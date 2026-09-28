<?php
declare( strict_types=1 );
/**
 * RK_Coach_Data  (v2.0.0 — Adaptateur)
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * ADAPTATEUR vers RKP_CoachDataQueryService et les services RKP associés.
 *
 * Cette classe conserve son interface publique à l'identique.
 * Toute la logique métier délègue à rk-platform.
 *
 * Restent dans cette classe (intégration WP / UI) :
 *   - init() + on_*()              : hooks WP (cache invalidation)
 *   - bust_for_child/coach/assess  : gestion cache transients
 *   - get_unread_messages_count()  : intégration BP Better Messages
 *   - get_last_parent_contact()    : intégration BP Better Messages
 *   - get_parent_info()            : lookup WP userdata
 *   - has_booking_column()         : utilitaire schéma DB
 *   - greeting_arabic()            : affichage contextuel
 *   - format_time()                : formatage heure
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * @package RK_Coach_Hub
 * @since   2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class RK_Coach_Data {

    private static array $students_cache = [];
    private static array $ssa_cache      = [];

    const CACHE_GROUP = 'rk_coach';

    /* ─── Init + invalidation hooks ───────────────────────────────────── */

    public static function init(): void {
        add_action( 'rk_session_attended',      [ __CLASS__, 'on_attendance_change' ], 10, 2 );
        add_action( 'rk_session_absent',        [ __CLASS__, 'on_attendance_change' ], 10, 2 );
        add_action( 'rk_booking_confirmed',     [ __CLASS__, 'on_booking_event' ] );
        add_action( 'rk_booking_cancelled',     [ __CLASS__, 'on_booking_event' ] );
        add_action( 'rk_mc_assessment_created', [ __CLASS__, 'on_assessment_created' ], 10, 2 );
    }

    public static function on_attendance_change( int $child_id, int $booking_id ): void {
        self::bust_for_child( $child_id );
    }

    public static function on_booking_event( $ctx ): void {
        foreach ( (array) ( $ctx->child_ids ?? [] ) as $child_id ) {
            self::bust_for_child( (int) $child_id );
        }
    }

    public static function on_assessment_created( int $assessment_id, int $child_id ): void {
        self::bust_assessment_cache( $child_id );
        if ( class_exists( 'RKP_CoachStudentRepository' ) ) {
            foreach ( RKP_CoachStudentRepository::get_coaches_for_child( $child_id ) as $cid ) {
                delete_transient( 'rk_ch_alerts_' . $cid );
                delete_transient( 'rk_ch_pending_evals_' . $cid );
            }
        }
    }

    public static function bust_for_child( int $child_id ): void {
        delete_transient( 'rk_ch_consec_' . $child_id );
        delete_transient( 'rk_ch_lms_' . $child_id );
        wp_cache_delete( 'rk_parent_of_' . $child_id,    self::CACHE_GROUP );
        wp_cache_delete( 'rk_child_wp_uid_' . $child_id, self::CACHE_GROUP );

        $coach_ids = class_exists( 'RKP_CoachStudentRepository' )
            ? RKP_CoachStudentRepository::get_coaches_for_child( $child_id )
            : [];
        foreach ( $coach_ids as $cid ) {
            delete_transient( 'rk_ch_attn_' . $child_id . '_' . $cid );
            delete_transient( 'rk_ch_alerts_' . $cid );
            delete_transient( 'rk_ch_students_' . $cid );
            unset( self::$students_cache[ $cid ] );
        }
    }

    public static function bust_for_coach( int $coach_id ): void {
        delete_transient( 'rk_ch_students_' . $coach_id );
        delete_transient( 'rk_ch_alerts_' . $coach_id );
        delete_transient( 'rk_ch_pending_evals_' . $coach_id );
        unset( self::$students_cache[ $coach_id ] );
        foreach ( [ 'today', 'tomorrow', 'week', 'month' ] as $range ) {
            delete_transient( 'rk_ch_sess_' . $coach_id . '_' . $range . '_' . date( 'Ymd' ) );
        }
        // Stats mensuelles (page #stats) — invalider les 6 mois affichés
        // pour que le pointage de présence se reflète immédiatement.
        $y = (int) date( 'Y' );
        $m = (int) date( 'n' );
        for ( $i = 0; $i < 6; $i++ ) {
            $mm = $m - $i;
            $yy = $y;
            while ( $mm < 1 ) { $mm += 12; $yy--; }
            delete_transient( 'rk_ch_mstats_' . $coach_id . '_' . $yy . '_' . $mm );
        }
        delete_transient( 'rk_ch_stats_' . $coach_id . '_' . date( 'Ymd' ) );
        if ( class_exists( 'RK_Coach_Course_Service' ) ) {
            RK_Coach_Course_Service::bust_cache( $coach_id );
        }
    }

    public static function bust_assessment_cache( int $child_id ): void {
        wp_cache_delete( 'rk_mc_assessment_latest_' . $child_id, self::CACHE_GROUP );
        wp_cache_delete( 'rk_mc_assessments_' . $child_id,       self::CACHE_GROUP );
        wp_cache_delete( 'rk_mc_assessments_skip_' . $child_id,  self::CACHE_GROUP );
    }

    /* ─── SSA (délégué) ────────────────────────────────────────────── */

    public static function get_ssa_types_for_coach( int $coach_id ): array {
        if ( isset( self::$ssa_cache[ $coach_id ] ) ) {
            return self::$ssa_cache[ $coach_id ];
        }
        $result = class_exists( 'RKP_CoachSessionRepository' )
            ? RKP_CoachSessionRepository::get_ssa_type_ids_for_coach( $coach_id )
            : [];
        self::$ssa_cache[ $coach_id ] = $result;
        return $result;
    }

    /* ─── Élèves (délégué) ─────────────────────────────────────────── */

    public static function get_coach_students( int $coach_id ): array {
        if ( isset( self::$students_cache[ $coach_id ] ) ) {
            return self::$students_cache[ $coach_id ];
        }
        $transient_key = 'rk_ch_students_' . $coach_id;
        $cached        = get_transient( $transient_key );
        if ( false !== $cached ) {
            self::$students_cache[ $coach_id ] = $cached;
            return $cached;
        }
        $result = class_exists( 'RKP_CoachDataQueryService' )
            ? RKP_CoachDataQueryService::get_students( $coach_id )
            : [];
        self::$students_cache[ $coach_id ] = $result;
        set_transient( $transient_key, $result, 10 * MINUTE_IN_SECONDS );
        return $result;
    }

    public static function coach_owns_child( int $coach_id, int $child_id ): bool {
        if ( ! class_exists( 'RKP_CoachDataQueryService' ) ) return false;
        return RKP_CoachDataQueryService::coach_owns_child( $coach_id, $child_id );
    }

    /* ─── Séances (délégué + cache) ────────────────────────────────── */

    public static function get_sessions_by_range( int $coach_id, string $range ): array {
        $cache_key = 'rk_ch_sess_' . $coach_id . '_' . $range . '_' . date( 'Ymd' );
        $cached    = get_transient( $cache_key );
        if ( false !== $cached ) return $cached;

        $result = class_exists( 'RKP_CoachDataQueryService' )
            ? RKP_CoachDataQueryService::get_sessions_by_range( $coach_id, $range )
            : [];
        set_transient( $cache_key, $result, 5 * MINUTE_IN_SECONDS );
        return $result;
    }

    public static function get_next_session_soon( int $coach_id, int $minutes ): ?array {
        if ( ! class_exists( 'RKP_CoachDataQueryService' ) ) return null;
        return RKP_CoachDataQueryService::get_next_session_soon( $coach_id, $minutes );
    }

    /* ─── Statistiques coach (délégué + cache) ─────────────────────── */

    public static function get_coach_stats( int $coach_id ): array {
        $cache_key = 'rk_ch_stats_' . $coach_id . '_' . date( 'Ymd' );
        $cached    = get_transient( $cache_key );
        if ( false !== $cached ) return $cached;

        $result = class_exists( 'RKP_CoachDataQueryService' )
            ? RKP_CoachDataQueryService::get_coach_stats( $coach_id )
            : [ 'total_students'=>0, 'sessions_today'=>0, 'sessions_week'=>0, 'avg_rating'=>0 ];
        set_transient( $cache_key, $result, 300 );
        return $result;
    }

    /* ─── Relations (délégué) ──────────────────────────────────────── */

    public static function get_parent_of_child( int $child_id ): int {
        $ck = 'rk_parent_of_' . $child_id;
        $v  = wp_cache_get( $ck, self::CACHE_GROUP );
        if ( false !== $v ) return (int) $v;
        $v = class_exists( 'RKP_CoachDataQueryService' )
            ? RKP_CoachDataQueryService::get_parent_of_child( $child_id )
            : 0;
        wp_cache_set( $ck, $v, self::CACHE_GROUP );
        return $v;
    }

    public static function get_child_wp_user_id( int $child_id ): int {
        $ck = 'rk_child_wp_uid_' . $child_id;
        $v  = wp_cache_get( $ck, self::CACHE_GROUP );
        if ( false !== $v ) return (int) $v;
        $v = class_exists( 'RKP_CoachDataQueryService' )
            ? RKP_CoachDataQueryService::get_child_wp_user_id( $child_id )
            : 0;
        wp_cache_set( $ck, $v, self::CACHE_GROUP );
        return $v;
    }

    /* ─── Données par enfant (délégué) ─────────────────────────────── */

    public static function get_child_detail( int $child_id ): ?object {
        if ( ! class_exists( 'RKP_CoachDataQueryService' ) ) return null;
        return RKP_CoachDataQueryService::get_child_detail( $child_id );
    }

    public static function get_child_lms_progress( int $child_id ): array {
        $cache_key = 'rk_ch_lms_' . $child_id;
        $cached    = get_transient( $cache_key );
        if ( false !== $cached ) return $cached;

        $wp_uid = self::get_child_wp_user_id( $child_id );
        if ( ! $wp_uid || ! function_exists( 'tutor_utils' ) ) {
            return [ 'enrolled' => 0, 'completed' => 0, 'courses' => [] ];
        }
        $enrolled_ids  = tutor_utils()->get_enrolled_courses_ids_by_user( $wp_uid );
        $completed_ids = tutor_utils()->get_completed_courses_ids_by_user( $wp_uid );
        $enrolled_ids  = is_array( $enrolled_ids )  ? $enrolled_ids  : [];
        $completed_ids = is_array( $completed_ids ) ? $completed_ids : [];

        $courses = [];
        foreach ( array_slice( $enrolled_ids, 0, 5 ) as $cid ) {
            $prog      = tutor_utils()->get_course_completed_percent( $cid, $wp_uid, true );
            $raw_pct   = $prog['completed_percent'] ?? 0;
            $courses[] = [ 'title' => get_the_title( $cid ), 'pct' => $raw_pct > 0 ? (int) $raw_pct : 0 ];
        }
        $ec     = count( $enrolled_ids );
        $result = [ 'enrolled' => $ec, 'completed' => min( count( $completed_ids ), $ec ), 'courses' => $courses ];
        set_transient( $cache_key, $result, 15 * MINUTE_IN_SECONDS );
        return $result;
    }

    public static function get_child_gamification( int $child_id ): array {
        if ( ! class_exists( 'RKP_CoachDataQueryService' ) ) {
            return [ 'total_points'=>0, 'level_num'=>1, 'level_label'=>'', 'badges_count'=>0, 'streak'=>0 ];
        }
        return RKP_CoachDataQueryService::get_child_gamification( $child_id );
    }

    public static function get_child_sessions( int $child_id, int $limit = 10 ): array {
        if ( ! class_exists( 'RKP_CoachDataQueryService' ) ) return [];
        return RKP_CoachDataQueryService::get_child_sessions( $child_id, $limit );
    }

    public static function get_child_assessments( int $child_id, int $limit = 5 ): array {
        if ( ! class_exists( 'RKP_CoachDataQueryService' ) ) return [];
        return RKP_CoachDataQueryService::get_child_assessments( $child_id, $limit );
    }

    public static function get_child_streak( int $child_id ): int {
        if ( ! class_exists( 'RKP_CoachDataQueryService' ) ) return 0;
        return RKP_CoachDataQueryService::get_child_streak( $child_id );
    }

    public static function get_child_sessions_for_coach( int $child_id, int $coach_id, int $limit = 20 ): array {
        if ( ! class_exists( 'RKP_CoachDataQueryService' ) ) return [];
        return RKP_CoachDataQueryService::get_child_sessions_for_coach( $child_id, $coach_id, $limit );
    }

    public static function get_child_quiz_results( int $child_id ): array {
        if ( ! class_exists( 'RKP_CoachDataQueryService' ) ) return [];
        return RKP_CoachDataQueryService::get_child_quiz_results( $child_id );
    }

    /* ─── Statistiques enrichies (délégué + cache) ─────────────────── */

    public static function get_pending_evals_count( int $coach_id ): int {
        $cache_key = 'rk_ch_pending_evals_' . $coach_id;
        $cached    = get_transient( $cache_key );
        if ( false !== $cached ) return (int) $cached;

        $result = class_exists( 'RKP_CoachDataQueryService' )
            ? RKP_CoachDataQueryService::get_pending_evals_count( $coach_id )
            : 0;
        set_transient( $cache_key, $result, 5 * MINUTE_IN_SECONDS );
        return $result;
    }

    public static function get_child_attendance_rate( int $child_id, int $coach_id ): int {
        $cache_key = 'rk_ch_attn_' . $child_id . '_' . $coach_id;
        $cached    = get_transient( $cache_key );
        if ( false !== $cached ) return (int) $cached;

        $result = class_exists( 'RKP_CoachDataQueryService' )
            ? RKP_CoachDataQueryService::get_child_attendance_rate( $child_id, $coach_id )
            : 0;
        set_transient( $cache_key, $result, 15 * MINUTE_IN_SECONDS );
        return $result;
    }

    public static function get_consecutive_absences( int $child_id ): int {
        $cache_key = 'rk_ch_consec_' . $child_id;
        $cached    = get_transient( $cache_key );
        if ( false !== $cached ) return (int) $cached;

        $result = class_exists( 'RKP_CoachDataQueryService' )
            ? RKP_CoachDataQueryService::get_consecutive_absences( $child_id )
            : 0;
        set_transient( $cache_key, $result, 15 * MINUTE_IN_SECONDS );
        return $result;
    }

    public static function get_children_with_next_booking( int $coach_id ): array {
        if ( ! class_exists( 'RKP_CoachDataQueryService' ) ) return [];
        return RKP_CoachDataQueryService::get_children_with_next_booking( $coach_id );
    }

    public static function get_monthly_stats( int $coach_id, int $year, int $month ): array {
        $cache_key  = 'rk_ch_mstats_' . $coach_id . '_' . $year . '_' . $month;
        $cached     = get_transient( $cache_key );
        if ( false !== $cached ) return $cached;

        $result = class_exists( 'RKP_CoachDataQueryService' )
            ? RKP_CoachDataQueryService::get_monthly_stats( $coach_id, $year, $month )
            : [ 'sessions'=>0, 'attendance_rate'=>0, 'evals_written'=>0, 'parent_messages'=>0 ];

        $is_current = ( $year === (int) date( 'Y' ) && $month === (int) date( 'n' ) );
        set_transient( $cache_key, $result, $is_current ? 5 * MINUTE_IN_SECONDS : HOUR_IN_SECONDS );
        return $result;
    }

    /* ─── Intégrations WP (restent ici — non business logic) ─────── */

    public static function get_unread_messages_count( int $coach_id ): int {
        if ( class_exists( 'BP_Messages_Thread' ) ) {
            return (int) BP_Messages_Thread::get_unread_count( $coach_id );
        }
        global $wpdb;
        $table = $wpdb->prefix . 'bp_messages_recipients';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) return 0;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT SUM(unread_count) FROM {$table}
              WHERE user_id = %d AND is_deleted = 0 AND sender_only = 0",
            $coach_id
        ) );
    }

    public static function get_last_parent_contact( int $coach_id, int $child_id ): string {
        if ( ! class_exists( 'Better_Messages' ) ) return '';
        $parent_id = self::get_parent_of_child( $child_id );
        if ( ! $parent_id ) return '';
        global $wpdb;
        $msg_table = $wpdb->prefix . 'bp_messages_messages';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $msg_table ) ) !== $msg_table ) return '';
        $date = $wpdb->get_var( $wpdb->prepare(
            "SELECT MAX(m.date_sent)
               FROM {$msg_table} m
               JOIN {$wpdb->prefix}bp_messages_recipients r ON r.thread_id = m.thread_id
              WHERE m.sender_id = %d AND r.user_id = %d",
            $coach_id, $parent_id
        ) );
        return $date ? date_i18n( 'd/m/Y', strtotime( $date ) ) : '';
    }

    public static function get_parent_info( int $child_id ): array {
        $parent_id = self::get_parent_of_child( $child_id );
        if ( ! $parent_id ) return [];
        $user = get_userdata( $parent_id );
        if ( ! $user ) return [];
        return [
            'user_id' => $parent_id,
            'name'    => $user->display_name,
            'email'   => $user->user_email,
            'phone'   => get_user_meta( $parent_id, 'billing_phone', true ) ?: '',
            'joined'  => date_i18n( 'd/m/Y', strtotime( $user->user_registered ) ),
        ];
    }

    /* ─── Utilitaires (restent ici) ────────────────────────────────── */

    public static function has_booking_column( string $col ): bool {
        if ( class_exists( 'RKP_BookingRepository' ) ) {
            return RKP_BookingRepository::has_column( $col );
        }
        static $cache = [];
        if ( ! isset( $cache[ $col ] ) ) {
            global $wpdb;
            $cache[ $col ] = (bool) $wpdb->get_var(
                $wpdb->prepare( "SHOW COLUMNS FROM {$wpdb->prefix}rk_bookings LIKE %s", $col )
            );
        }
        return $cache[ $col ];
    }

    public static function greeting_arabic(): string {
        $h = (int) date( 'G' );
        if ( $h >= 5  && $h < 12 ) return 'صباح الخير';
        if ( $h >= 12 && $h < 17 ) return 'مساء الخير';
        return 'أهلاً وسهلاً';
    }

    public static function format_time( string $datetime ): string {
        $ts = strtotime( $datetime );
        return $ts ? date_i18n( 'H:i', $ts ) : $datetime;
    }
}