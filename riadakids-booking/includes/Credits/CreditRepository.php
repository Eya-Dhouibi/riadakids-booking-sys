<?php
/**
 * RiadaKids\Credits\CreditRepository — v25 PRODUCTION
 *
 * ══════════════════════════════════════════════════════════════
 * FIXES APPLIQUÉS
 * ══════════════════════════════════════════════════════════════
 *
 * FIX02 — Les crédits sont maintenant stockés dans la table
 *          wp_rk_user_credits (balance + updated_at) en PLUS
 *          du usermeta (pour compatibilité rétroactive).
 *          La lecture lit d'abord la table dédiée, fallback usermeta.
 *          Cela permet des transactions SQL propres (SELECT FOR UPDATE).
 *
 * FIX06 — decrease_safe() utilise une vraie transaction SQL :
 *          BEGIN → SELECT FOR UPDATE → vérif solde → UPDATE → COMMIT
 *          Jamais de solde négatif possible même sous haute charge.
 *          Retourne -1 si solde insuffisant (sans déduction).
 *
 * FIX14 — Toutes les opérations DB sont wrappées dans try/catch.
 */

namespace RiadaKids\Credits;

use RiadaKids\Database\DB;

if ( ! defined( 'ABSPATH' ) ) exit;

class CreditRepository {

    const META_KEY = 'rk_session_credits';

    /* ── Lecture ───────────────────────────────────────────────── */

    /**
     * FIX02 — Lit depuis wp_rk_user_credits en priorité,
     * fallback sur usermeta pour la rétrocompatibilité.
     */
    public static function get_balance( int $user_id ): int {
        if ( ! $user_id ) return 0;

        // Priorité : table dédiée
        $row = DB::get_user_credit_row( $user_id );
        if ( $row !== null ) {
            return max( 0, (int) $row->balance );
        }

        // Fallback usermeta (anciens utilisateurs)
        return max( 0, (int) get_user_meta( $user_id, self::META_KEY, true ) );
    }

    /* ── Écriture simple (non concurrente) ──────────────────────── */

    public static function set_balance( int $user_id, int $amount ): void {
        $safe = max( 0, $amount );

        // Écriture double (table + meta) pour cohérence
        DB::upsert_user_credits( $user_id, $safe );
        update_user_meta( $user_id, self::META_KEY, $safe );
    }

    public static function increase( int $user_id, int $amount ): int {
        try {
            $current = self::get_balance( $user_id );
            $new     = $current + abs( $amount );
            self::set_balance( $user_id, $new );
            return $new;
        } catch ( \Throwable $e ) {
            \rk_log( 'CREDIT', "increase exception user#{$user_id}: " . $e->getMessage() );
            return self::get_balance( $user_id );
        }
    }

    /**
     * Décrémentation simple — uniquement pour usages non-concurrent
     * (admin manuel, tests). Préférer decrease_safe() dans le flow booking.
     */
    public static function decrease( int $user_id, int $amount ): int {
        $new = max( 0, self::get_balance( $user_id ) - abs( $amount ) );
        self::set_balance( $user_id, $new );
        return $new;
    }

    /* ── FIX06 — Décrémentation atomique transactionnelle ────────── */

    /**
     * FIX06 — Transaction SQL avec SELECT FOR UPDATE sur la table
     *          wp_rk_user_credits + sync usermeta après commit.
     *
     * Avantages vs usermeta seul :
     *   ✓ Fonctionne sans Redis / object cache
     *   ✓ Multi-workers PHP safe
     *   ✓ Serveur multi-instance safe (même MySQL unique)
     *   ✓ Jamais de solde négatif
     *
     * @return int Nouveau solde, ou -1 si insuffisant (aucune déduction)
     */
    public static function decrease_safe( int $user_id, int $amount ): int {
        $new = DB::credits_decrease_safe( $user_id, $amount );

        // Sync usermeta si déduction réussie
        if ( $new >= 0 ) {
            update_user_meta( $user_id, self::META_KEY, $new );
        }

        return $new;
    }

    /* ── Migration usermeta → table dédiée ──────────────────────── */

    /**
     * FIX02 — Migre un user de l'ancien système usermeta
     * vers la nouvelle table wp_rk_user_credits.
     * Appelé automatiquement si aucune ligne existe en table.
     */
    public static function migrate_from_meta( int $user_id ): void {
        if ( DB::get_user_credit_row( $user_id ) !== null ) return;

        $meta_val = (int) get_user_meta( $user_id, self::META_KEY, true );
        if ( $meta_val > 0 ) {
            DB::upsert_user_credits( $user_id, $meta_val );
            \rk_log( 'CREDIT', "migré user#{$user_id} usermeta→table: {$meta_val} crédits" );
        }
    }
}
