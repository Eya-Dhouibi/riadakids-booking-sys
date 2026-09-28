<?php
/**
 * RiadaKids\Booking\BookingStatus — Phase 2 (workflow Coordinateur)
 *
 * SOURCE UNIQUE des statuts et de la machine d'états du CPT `rk_booking`.
 * Aucune logique d'accès aux données ici : uniquement des constantes et des
 * fonctions pures (testables sans WordPress, hors apply_filters()).
 *
 * ┌──────────────────────────────────────────────────────────────────────┐
 * │ DEUX NOTIONS DISTINCTES — ne jamais les confondre                     │
 * │                                                                       │
 * │  _rk_status  (post meta)  = ÉTAT MÉTIER. Valeurs de ce fichier.       │
 * │  post_status (WordPress)  = ÉTAT TECHNIQUE, uniquement pour contrôler │
 * │                             quelles requêtes existantes voient le CPT.│
 * │                                                                       │
 * │  Historique : pending→'pending', confirmed→'publish',                 │
 * │               cancelled→'cancelled'. Conservé tel quel.               │
 * │  Nouveau    : les 3 états pré-confirmation → 'rk_awaiting'.           │
 * │  Ce post_status est volontairement ABSENT de toutes les listes        │
 * │  ('pending','publish','cancelled','trash') utilisées par le code      │
 * │  existant (find_pending_booking_by_event*, find_by_appointment_id,    │
 * │  find_duplicate, get_user_bookings, find_recent_pending) : l'ancien   │
 * │  flux SSA ne peut donc jamais "voir" un booking du nouveau workflow.  │
 * └──────────────────────────────────────────────────────────────────────┘
 *
 * Ces statuts ne vont JAMAIS dans wp_rk_bookings (colonne status VARCHAR(20),
 * et 'pending_parent_confirmation' fait 27 caractères). La table SQL n'est
 * écrite qu'à la confirmation, par confirm_from_ssa_locked() (inchangé).
 *
 * @package RiadaKids\Booking
 */

namespace RiadaKids\Booking;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class BookingStatus {

    // ── Nouveaux états (workflow Coordinateur) ────────────────────────
    public const PENDING_SCHEDULE            = 'pending_schedule';
    public const PENDING_PARENT_CONFIRMATION = 'pending_parent_confirmation';
    public const CHANGE_REQUESTED            = 'change_requested';
    public const EXPIRED                     = 'expired';

    // ── États partagés avec l'ancien système (valeurs inchangées) ─────
    public const CONFIRMED   = 'confirmed';
    public const COMPLETED   = 'completed';
    public const CANCELLED   = 'cancelled';
    public const RESCHEDULED = 'rescheduled';

    /** Ancien état "pending" (flux SSA legacy). NE PAS utiliser pour le nouveau workflow. */
    public const LEGACY_PENDING = 'pending';

    /** post_status WordPress des états pré-confirmation (voir bloc d'en-tête). */
    public const WP_STATUS_AWAITING = 'rk_awaiting';
    public const WP_STATUS_EXPIRED  = 'rk_expired';

    // ── Clés de meta CPT du nouveau workflow ──────────────────────────
    // Champs déjà existants et RÉUTILISÉS (non dupliqués) :
    //   _rk_appointment_id, _rk_appointment_datetime, _rk_created_at,
    //   _rk_credits_deducted, _rk_credits_refunded, _rk_child_ids, _rk_session_id.
    public const META_SCHEDULED_BY         = '_rk_scheduled_by';          // user ID du Coordinateur
    public const META_SCHEDULED_AT         = '_rk_scheduled_at';          // current_time('mysql')
    public const META_PARENT_CONFIRMED_AT  = '_rk_parent_confirmed_at';   // posé en Phase 6 uniquement
    public const META_CHANGE_REQUESTED_AT  = '_rk_change_requested_at';
    public const META_CHANGE_NOTE          = '_rk_change_note';
    public const META_PROPOSED_COACH_ID    = '_rk_proposed_coach_id';
    public const META_PROPOSED_DATETIME    = '_rk_proposed_datetime';     // distinct de _rk_appointment_datetime (posé à la confirmation)

    public const CHANGE_NOTE_MAX_LENGTH = 1000;
    public const DEFAULT_EXPIRY_DAYS    = 7;

    /**
     * Machine d'états complète. Les clés absentes n'ont aucune transition
     * gérée par cette classe (états legacy 'pending', 'booked'…).
     *
     * 'confirmed' → 'completed'/'cancelled'/'rescheduled' existent déjà dans
     * le code legacy (cancel_from_ssa, reschedule_from_ssa) : la table les
     * DOCUMENTE, mais transition_status() ne les exécute pas.
     */
    private const TRANSITIONS = [
        self::PENDING_SCHEDULE            => [ self::PENDING_PARENT_CONFIRMATION, self::CANCELLED, self::EXPIRED ],
        self::PENDING_PARENT_CONFIRMATION => [ self::CONFIRMED, self::CHANGE_REQUESTED, self::CANCELLED, self::EXPIRED ],
        self::CHANGE_REQUESTED            => [ self::PENDING_PARENT_CONFIRMATION, self::CANCELLED, self::EXPIRED ],
        self::CONFIRMED                   => [ self::COMPLETED, self::CANCELLED, self::RESCHEDULED ],
    ];

    /** @return string[] */
    public static function pre_confirmation_statuses(): array {
        return [ self::PENDING_SCHEDULE, self::PENDING_PARENT_CONFIRMATION, self::CHANGE_REQUESTED ];
    }

    /** États porteurs du nouveau workflow (pré-confirmation + expired). */
    public static function is_workflow_status( string $status ): bool {
        return self::is_pre_confirmation( $status ) || $status === self::EXPIRED;
    }

    /** Vrai pour les 3 états où RIEN de métier ne doit s'exécuter (crédit, SQL, hook). */
    public static function is_pre_confirmation( string $status ): bool {
        return in_array( $status, self::pre_confirmation_statuses(), true );
    }

    /**
     * "Confirmé" au sens historique : 'confirmed' et 'rescheduled' (une
     * réservation reprogrammée reste une réservation confirmée).
     */
    public static function is_confirmed( string $status ): bool {
        return in_array( $status, [ self::CONFIRMED, self::RESCHEDULED ], true );
    }

    /**
     * Terminal = plus aucune évolution possible. Accepte l'orthographe SQL
     * historique 'canceled'. NB : 'confirmed' n'est PAS terminal (→ completed,
     * cancelled) ; voir is_final_for_workflow().
     */
    public static function is_terminal( string $status ): bool {
        return in_array( $status, [ self::COMPLETED, self::CANCELLED, 'canceled', self::EXPIRED ], true );
    }

    /** Fin du workflow de PLANIFICATION : terminal ou confirmé. */
    public static function is_final_for_workflow( string $status ): bool {
        return self::is_terminal( $status ) || $status === self::CONFIRMED;
    }

    public static function can_transition( string $from, string $to ): bool {
        return isset( self::TRANSITIONS[ $from ] ) && in_array( $to, self::TRANSITIONS[ $from ], true );
    }

    /** @return string[] */
    public static function allowed_transitions( string $from ): array {
        return self::TRANSITIONS[ $from ] ?? [];
    }

    /**
     * post_status WordPress associé à un état métier.
     * Les valeurs legacy gardent leur mapping historique.
     */
    public static function wp_post_status( string $status ): string {
        if ( self::is_pre_confirmation( $status ) ) return self::WP_STATUS_AWAITING;
        return match ( $status ) {
            self::EXPIRED                        => self::WP_STATUS_EXPIRED,
            self::CANCELLED, 'canceled'          => 'cancelled',
            self::CONFIRMED, self::COMPLETED,
            self::RESCHEDULED                    => 'publish',
            default                              => 'pending',
        };
    }

    /** Durée d'expiration des états pré-confirmation, en jours (filtrable, non codée en dur). */
    public static function expiry_days(): int {
        $days = (int) apply_filters( 'rk_booking_preconfirmation_expiry_days', self::DEFAULT_EXPIRY_DAYS );
        return max( 1, $days );
    }
}
