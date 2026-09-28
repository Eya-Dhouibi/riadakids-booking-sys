<?php
declare( strict_types=1 );
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Extrait de class-rk-mc-booking-bridge.php — factorisation par fonctionnalité.
 * Code déplacé verbatim, aucune modification de logique.
 */
trait RK_MC_Booking_Bridge_Hooks {
    /* ═══════════════════════════════════════════════════════════════════
       HOOKS BOOKING
       ═══════════════════════════════════════════════════════════════════ */

    public static function on_confirmed( int $booking_id, $ctx ): void {
        if ( ! $ctx ) return;

        self::enroll_children( $ctx );
        self::create_bm_threads( $ctx );
        self::clear_child_dashboard_cache( $ctx );
        self::push_booking_notification( $ctx, 'booking_confirmed' );
    }

    /**
     * Priorité 12 — grave coach_id directement dans wp_rk_bookings.
     *
     * $ctx->event_id est toujours 0 au moment du hook (envoyé à 0 au pre-create,
     * SSA ne le connaît qu'à la confirmation). On lit donc appointment_type_id
     * directement depuis la ligne déjà insérée par insert_to_rk_bookings().
     */
    public static function stamp_coach_id( int $booking_id, $ctx ): void {
        if ( ! $ctx ) return;

        $appt_id = (int) ( $ctx->appointment_id ?? 0 );
        if ( $appt_id <= 0 ) return;

        // Résoudre le coach via : map → SSA Staff API → DB → appointment staff fallback
        // $ctx->event_id est l'appointment_type_id SSA, déjà disponible dans le contexte.
        $event_id = (int) ( $ctx->event_id ?? 0 );
        if ( $event_id <= 0 && class_exists( 'RKP_BookingRepository' ) ) {
            $event_id = RKP_BookingRepository::get_appointment_type_for_ssa_booking( $appt_id );
        }

        $coach_id = $event_id > 0 ? self::get_coach_user_id( $event_id ) : 0;

        if ( $coach_id <= 0 ) {
            $coach_id = self::resolve_coach_from_ssa_appointment( $appt_id );
        }

        if ( $coach_id <= 0 ) return;

        // Stamp coach_id dans wp_rk_bookings (si la colonne existe)
        global $wpdb;
        $bt = $wpdb->prefix . 'rk_bookings';
        static $col_exists = null;
        if ( $col_exists === null ) {
            $col_exists = ! empty( $wpdb->get_results( "SHOW COLUMNS FROM `{$bt}` LIKE 'coach_id'" ) );
        }
        if ( $col_exists && class_exists( 'RKP_BookingRepository' ) ) {
            RKP_BookingRepository::set_coach_id_for_ssa_booking( $appt_id, $coach_id );
        }

        // Ajouter les enfants dans rk_child_coaches → garantit que find_by_range() Path 2 fonctionne
        // même si SSA Staff n'est pas configuré et que coach_id est 0 dans la table.
        if ( class_exists( 'RKP_CoachStudentRepository' ) ) {
            foreach ( (array) ( $ctx->child_ids ?? [] ) as $child_id ) {
                $child_id = (int) $child_id;
                if ( $child_id > 0 ) {
                    RKP_CoachStudentRepository::ensure_assignment( $child_id, $coach_id );
                }
            }
        }
    }

    public static function on_rescheduled( int $booking_id, string $new_datetime, $ctx ): void {
        if ( ! $ctx ) return;
        self::push_booking_notification( $ctx, 'booking_rescheduled' );
    }

    public static function on_cancelled( int $booking_id, $ctx ): void {
        if ( ! $ctx ) return;
        self::unenroll_children( $ctx );
    }

    public static function on_child_enrolled_notify( int $child_id, int $child_wp_uid, int $course_id, int $parent_id ): void {
        if ( ! class_exists( 'RK_MC_Notification_Service' ) ) return;

        $course_title = get_the_title( $course_id ) ?: 'دورة جديدة';

        RK_MC_Notification_Service::push(
            $child_wp_uid,
            'course_enrolled',
            sprintf( 'تم تسجيلك في البرنامج "%s". يمكنك البدء الآن!', $course_title ),
            [ 'course_id' => $course_id ]
        );

        if ( $parent_id > 0 ) {
            $child_name = self::get_child_name( $child_id );
            RK_MC_Notification_Service::push(
                $parent_id,
                'course_enrolled',
                sprintf( 'تم تسجيل %s في البرنامج "%s"', $child_name, $course_title ),
                [ 'course_id' => $course_id, 'child_id' => $child_id ]
            );
        }
    }

    /* ═══════════════════════════════════════════════════════════════════
       ENROLLMENT TUTOR LMS
       ═══════════════════════════════════════════════════════════════════ */

    private static function enroll_children( $ctx ): void {
        $course_id = (int) ( $ctx->adventure_id ?? 0 );
        if ( $course_id <= 0 ) return;
        if ( ! function_exists( 'tutor_do_enroll' ) ) return;

        foreach ( (array) ( $ctx->child_ids ?? [] ) as $child_id ) {
            $child_id     = (int) $child_id;
            if ( $child_id <= 0 ) continue;

            $child_wp_uid = self::get_child_wp_user_id( $child_id );
            if ( ! $child_wp_uid ) {
                rkp_log( "[RK Bridge] Enfant #{$child_id} sans wp_user_id — enrollment ignoré" );
                continue;
            }

            // Idempotent : vérifier avant d'inscrire
            if ( function_exists( 'tutor_utils' ) && tutor_utils()->is_enrolled( $course_id, $child_wp_uid ) ) {
                continue;
            }

            $enrollment_id = tutor_do_enroll( $course_id, 0, $child_wp_uid );

            if ( $enrollment_id ) {
                rkp_log( "[RK Bridge] ✅ Inscrit wp_user#{$child_wp_uid} → cours#{$course_id}" );
                do_action( 'rk_child_enrolled', $child_id, $child_wp_uid, $course_id, (int) ( $ctx->user_id ?? 0 ) );
            } else {
                rkp_log( "[RK Bridge] ❌ Échec enrollment wp_user#{$child_wp_uid} → cours#{$course_id}" );
            }
        }
    }

    private static function unenroll_children( $ctx ): void {
        $course_id = (int) ( $ctx->adventure_id ?? 0 );
        if ( $course_id <= 0 || ! function_exists( 'tutor_utils' ) ) return;

        foreach ( (array) ( $ctx->child_ids ?? [] ) as $child_id ) {
            $child_id     = (int) $child_id;
            $child_wp_uid = self::get_child_wp_user_id( $child_id );
            if ( ! $child_wp_uid ) continue;

            if ( ! tutor_utils()->is_enrolled( $course_id, $child_wp_uid ) ) continue;

            // Annuler le statut dans la table Tutor
            global $wpdb;
            $wpdb->update(
                $wpdb->prefix . 'tutor_enrolled',
                [ 'status' => 'cancelled' ],
                [ 'course_id' => $course_id, 'user_id' => $child_wp_uid ],
                [ '%s' ],
                [ '%d', '%d' ]
            );

            rkp_log( "[RK Bridge] Enrollment annulé wp_user#{$child_wp_uid} cours#{$course_id}" );
        }
    }

    /* ═══════════════════════════════════════════════════════════════════
       BP BETTER MESSAGES — CRÉATION AUTOMATIQUE DES THREADS
       ═══════════════════════════════════════════════════════════════════ */

    private static function create_bm_threads( $ctx ): void {
        if ( ! class_exists( 'Better_Messages' ) ) return;

        $parent_id = (int) ( $ctx->user_id  ?? 0 );
        $coach_id  = self::get_coach_user_id( (int) ( $ctx->event_id ?? 0 ) );

        // Thread parent ↔ coach
        if ( $parent_id > 0 && $coach_id > 0 && $parent_id !== $coach_id ) {
            self::ensure_bm_thread(
                $coach_id,
                $parent_id,
                __( 'مرحباً! تم ربط هذه المحادثة تلقائياً عند تأكيد الحجز.', 'rk-my-children' )
            );
        }

        // Thread coach ↔ enfant
        foreach ( (array) ( $ctx->child_ids ?? [] ) as $child_id ) {
            $child_id     = (int) $child_id;
            $child_wp_uid = self::get_child_wp_user_id( $child_id );
            if ( ! $child_wp_uid || ! $coach_id || $child_wp_uid === $coach_id ) continue;

            self::ensure_bm_thread(
                $coach_id,
                $child_wp_uid,
                __( 'مرحباً! يمكنك التواصل مع مدربك هنا.', 'rk-my-children' ),
                $child_id
            );
        }
    }

    private static function ensure_bm_thread(
        int $sender_id,
        int $recipient_id,
        string $welcome,
        int $child_id = 0
    ): void {
        if ( ! function_exists( 'bm_get_table' ) ) return;

        global $wpdb;
        $table_r = bm_get_table( 'recipients' );

        // Vérifier si un thread existe déjà entre ces deux utilisateurs
        $existing_thread = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT r1.thread_id
               FROM {$table_r} r1
         INNER JOIN {$table_r} r2 ON r1.thread_id = r2.thread_id
              WHERE r1.user_id = %d
                AND r2.user_id = %d
              LIMIT 1",
            $sender_id,
            $recipient_id
        ) );

        if ( $existing_thread > 0 ) {
            // Thread already exists — store child_id if not yet set
            if ( $child_id > 0 && function_exists( 'bp_messages_update_meta' ) ) {
                bp_messages_update_meta( $existing_thread, 'rk_child_id', $child_id );
            }
            return;
        }

        // Créer le thread via l'API Better Messages
        $new_thread_id = Better_Messages()->functions->new_message( [
            'sender_id'    => $sender_id,
            'recipients'   => [ $recipient_id ],
            'content'      => $welcome,
            'send_push'    => false,
            'count_unread' => false,
            'show_on_site' => false,
        ] );

        // Stocker child_id comme méta du thread (Sprint 4)
        if ( $child_id > 0 && is_int( $new_thread_id ) && $new_thread_id > 0 ) {
            if ( function_exists( 'bp_messages_update_meta' ) ) {
                bp_messages_update_meta( $new_thread_id, 'rk_child_id', $child_id );
            }
        }
    }

    /* ═══════════════════════════════════════════════════════════════════
       GAMIFICATION — POINTS DE SESSION
       ═══════════════════════════════════════════════════════════════════ */

    private static function award_session_points( $ctx ): void {
        if ( ! class_exists( 'RK_MC_Gamification_Service' ) ) return;

        foreach ( (array) ( $ctx->child_ids ?? [] ) as $child_id ) {
            $child_id = (int) $child_id;
            if ( $child_id <= 0 ) continue;

            RK_MC_Gamification_Service::add_points( $child_id, 20, 'session', 0 );
        }
    }

    /* ═══════════════════════════════════════════════════════════════════
       NOTIFICATIONS  (via RK_MC_Notification_Service → riada_notify_user)
       ═══════════════════════════════════════════════════════════════════ */

    private static function push_booking_notification( $ctx, string $type ): void {
        if ( ! class_exists( 'RK_MC_Notification_Service' ) ) return;

        $session = sanitize_text_field( $ctx->session_name ?: $ctx->event_name ?: 'جلسة' );
        $dt_raw  = $ctx->appointment_datetime ?? '';
        $dt_str  = $dt_raw ? date_i18n( 'D j M Y - H:i', strtotime( $dt_raw ) ) : '';

        switch ( $type ) {
            case 'booking_confirmed':
                $msg = $dt_str
                    ? sprintf( 'تم تأكيد جلستك "%s" بتاريخ %s', $session, $dt_str )
                    : sprintf( 'تم تأكيد جلستك "%s"', $session );
                break;
            case 'booking_rescheduled':
                $msg = $dt_str
                    ? sprintf( 'تم إعادة جدولة جلستك "%s" إلى %s', $session, $dt_str )
                    : sprintf( 'تم إعادة جدولة جلستك "%s"', $session );
                break;
            default:
                return;
        }

        $meta      = [ 'session' => $session, 'datetime' => $dt_raw ];
        $parent_id = (int) ( $ctx->user_id ?? 0 );

        if ( $parent_id > 0 ) {
            RK_MC_Notification_Service::push( $parent_id, $type, $msg, $meta );
        }

        foreach ( (array) ( $ctx->child_ids ?? [] ) as $child_id ) {
            $wp_uid = self::get_child_wp_user_id( (int) $child_id );
            if ( $wp_uid > 0 ) {
                RK_MC_Notification_Service::push( $wp_uid, $type, $msg, $meta );
            }
        }
    }

    private static function get_child_name( int $child_id ): string {
        global $wpdb;
        return (string) ( $wpdb->get_var( $wpdb->prepare(
            'SELECT child_name FROM ' . rk_mc_children_table() . ' WHERE id = %d LIMIT 1',
            $child_id
        ) ) ?: '' );
    }

    /* ═══════════════════════════════════════════════════════════════════
       HELPERS
       ═══════════════════════════════════════════════════════════════════ */

    public static function get_child_wp_user_id( int $child_id ): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            'SELECT wp_user_id FROM ' . rk_mc_children_table() . ' WHERE id = %d LIMIT 1',
            $child_id
        ) );
    }

    /**
     * Retourne l'ID WordPress du coach lié à un type de rendez-vous SSA.
     *
     * Ordre de résolution :
     *  1. Option rk_ssa_coach_map (config admin ou cache auto)
     *  2. SSA Staff API (si SSA staff feature activée)
     *  3. Requête directe sur wp_ssa_staff_appointment_types + wp_ssa_staff
     *
     * Quand trouvé via 2 ou 3, écrit dans rk_ssa_coach_map pour les prochains appels.
     */
    public static function get_coach_user_id( int $ssa_event_id ): int {
        if ( $ssa_event_id <= 0 ) return 0;

        $map = get_option( self::COACH_MAP_OPTION, [] );
        if ( isset( $map[ $ssa_event_id ] ) && (int) $map[ $ssa_event_id ] > 0 ) {
            return (int) $map[ $ssa_event_id ];
        }

        $coach_id = self::resolve_coach_from_ssa_type( $ssa_event_id );
        if ( $coach_id > 0 ) {
            // Auto-cache in the map so subsequent calls skip SSA queries
            $map[ $ssa_event_id ] = $coach_id;
            update_option( self::COACH_MAP_OPTION, $map, false );
        }
        return $coach_id;
    }

    /**
     * Résout le coach WP user ID depuis SSA directement (sans rk_ssa_coach_map).
     * Essaie d'abord l'API SSA, puis un fallback DB direct.
     */
    private static function resolve_coach_from_ssa_type( int $ssa_event_id ): int {
        // — Tentative via API SSA Staff —
        if ( function_exists( 'ssa' ) ) {
            $plugin = ssa();
            if ( ! empty( $plugin->staff_appointment_type_model ) && ! empty( $plugin->staff_model ) ) {
                try {
                    $staff_ids = $plugin->staff_appointment_type_model->get_staff_ids_for_appointment_type_id( $ssa_event_id );
                    foreach ( (array) $staff_ids as $sid ) {
                        $staff  = $plugin->staff_model->get( (int) $sid );
                        $wp_uid = isset( $staff['user_id'] ) ? (int) $staff['user_id'] : 0;
                        if ( $wp_uid > 0 && self::is_instructor( $wp_uid ) ) return $wp_uid;
                    }
                } catch ( \Throwable $e ) {
                    // SSA API unavailable — fall through to DB
                }
            }
        }

        // — Fallback : requête directe sur les tables SSA staff —
        global $wpdb;
        $sat   = $wpdb->prefix . 'ssa_staff_appointment_types';
        $staff = $wpdb->prefix . 'ssa_staff';

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $sat ) ) !== $sat ) return 0;
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $staff ) ) !== $staff ) return 0;

        $user_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT s.user_id
               FROM {$sat} sat
               JOIN {$staff} s ON s.id = sat.staff_id
              WHERE sat.appointment_type_id = %d AND s.user_id > 0",
            $ssa_event_id
        ) );

        foreach ( (array) $user_ids as $uid ) {
            if ( (int) $uid > 0 && self::is_instructor( (int) $uid ) ) return (int) $uid;
        }
        return 0;
    }

    /**
     * Résout le coach depuis un SSA appointment spécifique (via wp_ssa_staff_appointments).
     * Fallback de dernier recours pour stamp_coach_id().
     */
    public static function resolve_coach_from_ssa_appointment( int $ssa_appt_id ): int {
        if ( $ssa_appt_id <= 0 ) return 0;

        // Essai via SSA API
        if ( function_exists( 'ssa' ) ) {
            $plugin = ssa();
            if ( ! empty( $plugin->staff_appointment_model ) && ! empty( $plugin->staff_model ) ) {
                try {
                    $staff_ids = $plugin->staff_appointment_model->get_staff_ids( $ssa_appt_id );
                    foreach ( (array) $staff_ids as $sid ) {
                        $staff  = $plugin->staff_model->get( (int) $sid );
                        $wp_uid = isset( $staff['user_id'] ) ? (int) $staff['user_id'] : 0;
                        if ( $wp_uid > 0 && self::is_instructor( $wp_uid ) ) return $wp_uid;
                    }
                } catch ( \Throwable $e ) {}
            }
        }

        // Fallback DB direct : wp_ssa_staff_appointments → wp_ssa_staff
        global $wpdb;
        $sa    = $wpdb->prefix . 'ssa_staff_appointments';
        $staff = $wpdb->prefix . 'ssa_staff';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $sa ) ) !== $sa ) return 0;
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $staff ) ) !== $staff ) return 0;

        $user_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT s.user_id
               FROM {$sa} sa
               JOIN {$staff} s ON s.id = sa.staff_id
              WHERE sa.appointment_id = %d AND s.user_id > 0",
            $ssa_appt_id
        ) );

        foreach ( (array) $user_ids as $uid ) {
            if ( (int) $uid > 0 && self::is_instructor( (int) $uid ) ) return (int) $uid;
        }
        return 0;
    }

    /** Vérifie que le WP user est un tutor_instructor. */
    private static function is_instructor( int $user_id ): bool {
        $user = get_userdata( $user_id );
        return $user && in_array( 'tutor_instructor', (array) $user->roles, true );
    }

    /**
     * Retourne l'ID WordPress du coach lié à un enfant
     * (via son dernier booking confirmé).
     */
    public static function get_coach_for_child( int $child_id ): int {
        $ssa_type_id = class_exists( 'RKP_BookingRepository' )
            ? RKP_BookingRepository::get_last_appointment_type_id( $child_id, 'confirmed' )
            : 0;
        return $ssa_type_id ? self::get_coach_user_id( $ssa_type_id ) : 0;
    }


    /* ═══════════════════════════════════════════════════════════════════
       CACHE — Invalidation dashboard enfant après booking
       ═══════════════════════════════════════════════════════════════════ */

    private static function clear_child_dashboard_cache( $ctx ): void {
        foreach ( (array) ( $ctx->child_ids ?? [] ) as $child_id ) {
            // Vide les deux caches : endpoint /child-dashboard/ + transient rk_dash_{cid}_{pid}
            do_action( 'rk_mc_bust_child_caches', (int) $child_id );
        }
    }

}
