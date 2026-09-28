<?php
declare( strict_types=1 );
/**
 * Application — BadgeCommandService  (Write Model)
 *
 * Attribution et révocation des shawaret (badges).
 *
 * RÈGLE : aucun appel direct à $wpdb — tout passe par BadgeRepository.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class RKP_BadgeCommandService {

    /**
     * Attribue un badge à un enfant (idempotent).
     * Accorde 20 pts bonus si c'est le premier badge du mois.
     * Déclenche le hook rk_mc_badge_awarded.
     *
     * v9.30 — retourne désormais un code d'échec explicite au lieu
     * d'un simple bool : le controller REST renvoyait un 409 générique
     * ("award_failed") sans dire LAQUELLE des 3 causes possibles
     * (badge_key inconnu du catalogue, déjà attribué, échec DB réel
     * lors de l'insert ou du bonus de points) s'était produite —
     * rendant le diagnostic impossible depuis le frontend.
     *
     * @param string $note  Note libre (affichée dans la timeline).
     * @return string  '' si succès, sinon un code d'échec :
     *                 'unknown_badge' | 'already_awarded' | 'insert_failed' | 'points_failed'
     */
    public static function award_with_reason( int $child_rk_id, string $badge_key, string $note = '' ): string {
        $cat = RKP_BadgeQueryService::catalogue();
        if ( ! isset( $cat[ $badge_key ] ) ) return 'unknown_badge';
        if ( RKP_BadgeRepository::has_badge( $child_rk_id, $badge_key ) ) return 'already_awarded';

        RKP_DB::begin();

        $inserted = RKP_BadgeRepository::insert( $child_rk_id, $badge_key, $note );
        if ( ! $inserted ) {
            RKP_DB::rollback();
            return 'insert_failed';
        }

        /*
         * v9.58 — Le bonus de points ne doit plus faire echouer l'octroi.
         *
         * Avant : si add_points() renvoyait false, tout etait annule et le
         * controller repondait 409 'points_failed'. Le coach ne pouvait
         * plus jamais attribuer ce badge, alors que l'echec portait sur un
         * BONUS accessoire (20 pts) et non sur l'action demandee.
         *
         * add_points() renvoie aussi false pour des raisons parfaitement
         * normales (garde d'idempotence, points === 0), et il declenche des
         * hooks tiers susceptibles d'echouer independamment du badge.
         *
         * Le badge est donc conserve ; seul le bonus est perdu, et
         * l'incident est journalise pour diagnostic.
         */
        $first_this_month = ( RKP_BadgeRepository::count_this_month( $child_rk_id ) === 1 );
        if ( $first_this_month ) {
            $ok = RKP_GamificationCommandService::add_points(
                $child_rk_id, 20, 'badge_first', 0,
                sprintf( 'أول شارة هذا الشهر: %s', $cat[ $badge_key ]['name'] )
            );
            if ( ! $ok && function_exists( 'rkp_log' ) ) {
                rkp_log( sprintf(
                    '[RK Badges] Bonus "premiere شارة du mois" non accorde (enfant %d, badge %s) — le badge reste attribue.',
                    $child_rk_id,
                    $badge_key
                ) );
            }
        }

        try {
            RKP_DB::commit();
        } catch ( \Throwable $e ) {
            // commit() leve si une operation imbriquee a condamne la
            // transaction (voir RKP_DB::rollback()). Rien n'a ete ecrit.
            if ( function_exists( 'rkp_log' ) ) {
                rkp_log( '[RK Badges] COMMIT refuse : ' . $e->getMessage() );
            }
            return 'insert_failed';
        }

        do_action( 'rk_mc_badge_awarded', $child_rk_id, $badge_key, $cat[ $badge_key ] );
        return '';
    }

    /**
     * Wrapper bool historique — conservé pour tous les appelants
     * existants (handlers admin-post, RK_MC_Badge_Service, etc.) qui
     * n'ont besoin que d'un succès/échec sans le détail de la cause.
     */
    public static function award( int $child_rk_id, string $badge_key, string $note = '' ): bool {
        return '' === self::award_with_reason( $child_rk_id, $badge_key, $note );
    }

    /**
     * Attribution conditionnelle selon un trigger connu.
     */
    public static function maybe_award( int $child_rk_id, string $trigger ): void {
        switch ( $trigger ) {
            case 'first_session':
            case 'first_lesson':
            case 'course_complete':
                self::award( $child_rk_id, $trigger );
                break;
        }
    }

    /**
     * Attribue le badge de niveau (level_2 … level_8) si défini dans le catalogue.
     */
    public static function award_level_badge( int $child_rk_id, array $level ): void {
        $key = 'level_' . $level['num'];
        if ( isset( RKP_BadgeQueryService::catalogue()[ $key ] ) ) {
            self::award( $child_rk_id, $key );
        }
    }

    /**
     * Révoque un badge (usage admin).
     */
    public static function revoke( int $child_rk_id, string $badge_key ): bool {
        return 'ok' === self::revoke_with_reason( $child_rk_id, $badge_key );
    }

    /**
     * Retire un badge et retourne un code precis (v9.58).
     *
     * Symetrique de award_with_reason(). Motif du correctif :
     *
     * $wpdb->delete() retourne le NOMBRE de lignes supprimees. Quand le
     * badge n'est pas present dans rk_child_badges, il retourne 0, donc
     * revoke() renvoyait false et le controller repondait 409 Conflict.
     *
     * Or ce cas est parfaitement normal :
     *   - badges "achievement" (level_2, first_lesson...) : calcules
     *     dynamiquement a partir des points/progression, ils n'ont jamais
     *     de ligne en base tant qu'ils n'ont pas ete octroyes manuellement ;
     *   - etat d'affichage desynchronise (double-clic, second onglet,
     *     autre coach agissant en parallele).
     *
     * Dans les deux cas l'etat VOULU est atteint : l'enfant n'a pas le
     * badge. Traiter cela comme un conflit bloquait l'interface sur une
     * erreur alors que rien n'avait echoue.
     *
     * @return string 'ok' | 'not_awarded' | 'unknown_badge'
     */
    public static function revoke_with_reason( int $child_rk_id, string $badge_key ): string {
        $cat = RKP_BadgeQueryService::catalogue();
        if ( ! isset( $cat[ $badge_key ] ) ) return 'unknown_badge';

        if ( ! RKP_BadgeRepository::has_badge( $child_rk_id, $badge_key ) ) {
            // Rien a retirer : l'etat cible est deja celui demande.
            return 'not_awarded';
        }

        $deleted = RKP_BadgeRepository::delete( $child_rk_id, $badge_key );
        if ( ! $deleted ) return 'not_awarded';

        do_action( 'rk_mc_badge_revoked', $child_rk_id, $badge_key );
        return 'ok';
    }

    /** Marque un badge comme vu par l'enfant. Pass-through idempotent vers le repository. */
    public static function mark_as_seen( int $child_rk_id, string $badge_key ): bool {
        return RKP_BadgeRepository::mark_as_seen( $child_rk_id, $badge_key );
    }
}