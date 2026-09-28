<?php
declare( strict_types=1 );
/**
 * Infrastructure — RKBridge
 *
 * Écoute les hooks WP propres à RiadaKids (rk_*) et les retransmet
 * sous forme de Domain Events via RKP_EventBus.
 *
 * Priorité 50 sur tous les hooks : s'exécute APRÈS les handlers
 * existants. Aucun hook existant n'est remplacé — coexistence totale.
 *
 * Convention des IDs :
 *   $child_rk_id  = wp_rk_children.id   (ID dans la table custom)
 *   $child_wp_uid = wp_users.ID          (WP user ID, utilisé par rk-platform)
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class RKP_RKBridge {

    public static function init(): void {
        add_action( 'rk_booking_confirmed',     [ self::class, 'on_booking_confirmed'  ], 50, 2 );
        add_action( 'rk_mc_badge_awarded',      [ self::class, 'on_badge_awarded'      ], 50, 3 );
        add_action( 'rk_mc_assessment_created', [ self::class, 'on_assessment_created' ], 50, 3 );
        // v9.9 — Child Experience Layer : deux nouveaux relais hook → Domain Event,
        // promouvant des hooks déjà actifs en production sans dupliquer leur logique.
        add_action( 'rk_mc_child_level_up',     [ self::class, 'on_child_level_up'     ], 50, 3 );
        add_action( 'rk_mc_message_sent',       [ self::class, 'on_message_sent'       ], 50, 4 );
    }

    public static function on_booking_confirmed( int $booking_id, $ctx ): void {
        if ( ! $ctx ) return;

        $child_ids = (array) ( $ctx->child_ids ?? [] );
        $course_id = (int) ( $ctx->adventure_id ?? 0 );
        $appt_id   = (int) ( $ctx->appointment_id ?? 0 );
        $appt_dt   = (string) ( $ctx->appointment_datetime ?? '' );

        if ( ! $appt_dt ) return;

        try {
            $session_at = new \DateTimeImmutable( $appt_dt );
        } catch ( \Exception $e ) {
            return;
        }

        // coach_id déjà enregistré dans wp_rk_bookings par stamp_coach_id (priority 12)
        $coach_id = 0;
        if ( $appt_id > 0 ) {
            global $wpdb;
            $coach_id = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT coach_id FROM `{$wpdb->prefix}rk_bookings` WHERE booking_id = %d LIMIT 1",
                $appt_id
            ) );
        }

        foreach ( $child_ids as $child_rk_id ) {
            $wp_uid = self::resolve_wp_user_id( (int) $child_rk_id );
            if ( ! $wp_uid ) continue;
            RKP_EventBus::dispatch( new RKP_BookingConfirmed(
                $booking_id, $wp_uid, $coach_id, $course_id, $session_at
            ) );
        }
    }

    public static function on_badge_awarded( int $child_rk_id, string $badge_key, $badge_def ): void {
        $wp_uid = self::resolve_wp_user_id( $child_rk_id );
        if ( ! $wp_uid ) return;
        RKP_EventBus::dispatch( new RKP_BadgeEarned( $wp_uid, $badge_key ) );
    }

    public static function on_assessment_created( int $report_id, int $child_rk_id, int $coach_id ): void {
        $wp_uid = self::resolve_wp_user_id( $child_rk_id );
        if ( ! $wp_uid ) return;
        RKP_EventBus::dispatch( new RKP_ReportPublished( $report_id, $wp_uid, 0, $coach_id ) );
    }

    /**
     * Relais du hook natif `rk_mc_child_level_up`. Le calcul de niveau
     * lui-même n'est PAS dupliqué ici — il reste dans
     * RK_MC_Gamification_Service/GamificationCommandService, ce pont
     * ne fait que traduire le hook en Domain Event.
     *
     * @param array $new ['num' => int, 'label' => string, 'total' => int]
     * @param array $old même forme, niveau précédent
     */
    public static function on_child_level_up( int $child_rk_id, array $new, array $old ): void {
        $wp_uid = self::resolve_wp_user_id( $child_rk_id );
        if ( ! $wp_uid ) return;
        RKP_EventBus::dispatch( new RKP_LevelUp(
            $wp_uid,
            (int) ( $new['num'] ?? 0 ),
            (int) ( $old['num'] ?? 0 ),
            (string) ( $new['label'] ?? '' )
        ) );
    }

    /**
     * Relais du hook natif `rk_mc_message_sent`. IMPORTANT : ce hook se
     * déclenche pour TOUT message (enfant→coach ET coach→enfant) — on
     * ne dispatch le Domain Event que dans le sens coach→enfant
     * (l'enfant n'a pas besoin d'un popup "nouveau message" pour son
     * propre message envoyé). Le filtrage utilise user_can() natif
     * WordPress, pas une réécriture de la logique de rôle du plugin.
     */
    public static function on_message_sent( int $message_id, int $child_rk_id, int $from_id, int $to_id ): void {
        if ( ! user_can( $from_id, 'tutor_instructor' ) ) return; // pas un coach qui envoie → on ignore

        $wp_uid = self::resolve_wp_user_id( $child_rk_id );
        if ( ! $wp_uid ) return;

        global $wpdb;
        $body = (string) $wpdb->get_var( $wpdb->prepare(
            "SELECT body FROM `{$wpdb->prefix}rk_child_messages` WHERE id = %d LIMIT 1",
            $message_id
        ) );
        $excerpt = $body ? mb_substr( wp_strip_all_tags( $body ), 0, 80 ) : '';

        RKP_EventBus::dispatch( new RKP_CoachMessageReceived( $message_id, $wp_uid, $from_id, $excerpt ) );
    }

    private static function resolve_wp_user_id( int $child_rk_id ): int {
        if ( $child_rk_id <= 0 ) return 0;
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT wp_user_id FROM `{$wpdb->prefix}rk_children` WHERE id = %d LIMIT 1",
            $child_rk_id
        ) );
    }
}
