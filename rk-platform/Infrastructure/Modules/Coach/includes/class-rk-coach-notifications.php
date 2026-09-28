<?php
declare( strict_types=1 );
/**
 * RK_Coach_Notifications — Notification automatique BM aux parents.
 *
 * Hooks actifs :
 *   rk_mc_badge_awarded     → badge obtenu
 *   rk_mc_child_level_up    → montée de niveau
 *   rk_mc_mission_completed → mission terminée
 *   rk_mc_assessment_created → évaluation créée (hook Assessment Service)
 *
 * Cron hebdomadaire (lundi 08h) :
 *   Envoie un bilan résumé à chaque parent actif.
 *
 * @package RK_Coach_Hub
 * @since   1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class RK_Coach_Notifications {

    const CRON_HOOK = 'rk_coach_hub_weekly_report';

    /* ─── Init ──────────────────────────────────────────────────────── */

    public static function init(): void {
        add_action( 'rk_mc_badge_awarded',      [ __CLASS__, 'on_badge_awarded'  ], 20, 3 );
        add_action( 'rk_mc_child_level_up',     [ __CLASS__, 'on_level_up'       ], 20, 3 );
        add_action( 'rk_mc_mission_completed',  [ __CLASS__, 'on_mission_completed' ], 20, 2 );
        add_action( 'rk_mc_assessment_created', [ __CLASS__, 'on_assessment_created' ], 20, 2 );
        add_action( 'tutor_quiz_attempt_ended', [ __CLASS__, 'on_quiz_attempt_ended' ], 20 );

        // Alertes coach dashboard sur les bookings
        add_action( 'rk_booking_confirmed',   [ __CLASS__, 'on_booking_for_coach'     ], 20, 2 );
        add_action( 'rk_booking_rescheduled', [ __CLASS__, 'on_rescheduled_for_coach' ], 20, 3 );
        add_action( 'rk_booking_cancelled',   [ __CLASS__, 'on_cancelled_for_coach'   ], 20, 2 );

        add_action( self::CRON_HOOK, [ __CLASS__, 'send_weekly_reports' ] );

        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            $next_monday = strtotime( 'next monday 08:00:00' );
            wp_schedule_event( $next_monday, 'weekly', self::CRON_HOOK );
        }
    }

    public static function deactivate(): void {
        $ts = wp_next_scheduled( self::CRON_HOOK );
        if ( $ts ) wp_unschedule_event( $ts, self::CRON_HOOK );
    }

    /* ─── Handlers hooks ────────────────────────────────────────────── */

    public static function on_badge_awarded( int $child_id, string $badge_key, array $badge_def ): void {
        $name      = $badge_def['name'] ?? $badge_key;
        $desc      = $badge_def['desc'] ?? '';
        $dash_url  = self::child_dashboard_url( $child_id, 'rk-badges' );
        self::notify_parent_bell(
            $child_id,
            'badge_awarded',
            sprintf( '🏅 حصل ابنك على شارة جديدة: %s%s', $name, $desc ? " — {$desc}" : '' ),
            [ 'link' => $dash_url, 'badge_key' => $badge_key ]
        );
    }

    public static function on_level_up( int $child_id, array $new_level, array $_old_level ): void {
        $icon     = $new_level['icon']  ?? '⭐';
        $label    = $new_level['label'] ?? '';
        $num      = $new_level['num']   ?? '';
        $dash_url = self::child_dashboard_url( $child_id );
        self::notify_parent_bell(
            $child_id,
            'level_up',
            sprintf( '%s ارتقى ابنك إلى المستوى %s — "%s"! استمر في التشجيع.', $icon, $num, $label ),
            [ 'link' => $dash_url ]
        );
    }

    public static function on_mission_completed( int $child_id, string $mission_key ): void {
        $defs     = class_exists( 'RK_MC_Mission_Service' ) ? RK_MC_Mission_Service::definitions() : [];
        $name     = $defs[ $mission_key ]['name'] ?? $mission_key;
        $dash_url = self::child_dashboard_url( $child_id, 'rk-missions' );
        self::notify_parent_bell(
            $child_id,
            'mission_completed',
            sprintf( '🎯 أنجز ابنك مهمة أسبوعية: "%s"! 🌟', $name ),
            [ 'link' => $dash_url ]
        );
    }

    public static function on_assessment_created( int $assessment_id, int $child_id ): void {
        $row = RKP_AssessmentRepository::find_by_id( $assessment_id );
        if ( ! $row ) return;

        $stars    = str_repeat( '⭐', (int) $row->rating );
        $dash_url = self::child_dashboard_url( $child_id, 'rk-evaluations' );

        if ( class_exists( 'RK_MC_Notification_Service' ) ) {
            $meta         = [ 'assessment_id' => $assessment_id, 'link' => $dash_url ];
            $child_record = RKP_CoachStudentRepository::find_child_by_id( $child_id );
            $child_wp_uid = $child_record ? (int) $child_record->wp_user_id : 0;
            if ( $child_wp_uid > 0 ) {
                RK_MC_Notification_Service::push(
                    $child_wp_uid,
                    'assessment_created',
                    sprintf( 'نشر مدربك %s تقييماً جديداً. التقييم: %s', $row->coach_name, $stars ),
                    $meta
                );
            }
            $parent_id = RK_Coach_Data::get_parent_of_child( $child_id );
            if ( $parent_id > 0 ) {
                RK_MC_Notification_Service::push(
                    $parent_id,
                    'assessment_created',
                    sprintf( 'المدرب %s نشر تقييماً لابنك. التقييم: %s', $row->coach_name, $stars ),
                    $meta + [ 'child_id' => $child_id ]
                );
            }
        }
    }

    /* ─── Notification coach : quiz terminé ────────────────────────────── */

    public static function on_quiz_attempt_ended( $attempt_id ): void {
        $attempt_id = (int) $attempt_id;
        if ( $attempt_id <= 0 ) return;

        $attempt = RKP_QuizAttemptRepository::find_attempt_by_id( $attempt_id );
        if ( ! $attempt ) return;

        $student_wp_id = (int) $attempt->user_id;
        if ( $student_wp_id <= 0 ) return;

        $child_record = RKP_CoachStudentRepository::find_by_wp_user_id( $student_wp_id );
        if ( ! $child_record ) return;
        $child_id = (int) $child_record->id;

        $coach_id = RKP_CoachStudentRepository::find_primary_coach_for_child( $child_id );
        if ( ! $coach_id ) return;

        $quiz_title = get_the_title( (int) $attempt->quiz_id ) ?: __( 'اختبار', 'rk-coach-hub' );
        $earned     = (float) $attempt->earned_marks;
        $total      = (float) $attempt->total_marks;
        $pct        = $total > 0 ? (int) round( $earned / $total * 100 ) : 0;

        $child_name = (string) $child_record->child_name;

        $dash_url = add_query_arg(
            [ 'child_id' => $child_id ],
            home_url( trailingslashit( defined( 'RK_TUTOR_DASHBOARD_URL' ) ? RK_TUTOR_DASHBOARD_URL : '/dashboard/' ) . 'rk-fiche-eleve/' )
        );

        $emoji = $pct >= 80 ? '🌟' : ( $pct >= 50 ? '📝' : '⚠️' );

        self::send_bm( $coach_id, sprintf(
            "%s أنهى %s الاختبار: \"%s\"\nالنتيجة: %d / %d نقطة (%d%%)\n\n🔗 %s",
            $emoji,
            $child_name,
            $quiz_title,
            (int) $earned,
            (int) $total,
            $pct,
            $dash_url
        ) );

        // Notification in-app pour le coach
        if ( class_exists( 'RK_MC_Notification_Service' ) ) {
            RK_MC_Notification_Service::push(
                $coach_id,
                'quiz_attempt_ended',
                sprintf( '%s أنهى اختبار "%s" — %d%%', $child_name, $quiz_title, $pct ),
                [ 'child_id' => $child_id, 'quiz_id' => (int) $attempt->quiz_id, 'attempt_id' => $attempt_id, 'score_pct' => $pct ]
            );
        }
    }

    /* ─── Notifications coach : booking confirmé / reschedule / annulé ─── */

public static function on_booking_for_coach( int $booking_id, $ctx ): void {
        if ( ! $ctx ) return;
        $coach_id = self::resolve_coach_id( $ctx );
        if ( ! $coach_id ) return;

        $session  = sanitize_text_field( $ctx->session_name ?: $ctx->event_name ?: '' );
        $dt_raw   = $ctx->appointment_datetime ?? '';
        $dt_str   = $dt_raw ? date_i18n( 'D j M Y - H:i', strtotime( $dt_raw ) ) : '';
        $children = self::get_children_names( (array) ( $ctx->child_ids ?? [] ) );

        self::send_bm( $coach_id, sprintf(
            "📅 حجز جديد مؤكد!\n👦 الطالب: %s\n📚 الجلسة: %s%s",
            $children,
            $session,
            $dt_str ? "\n🕐 الموعد: {$dt_str}" : ''
        ) );

        if ( class_exists( 'RK_MC_Notification_Service' ) ) {
            RK_MC_Notification_Service::push(
                $coach_id,
                'booking_new_coach',
                sprintf( '%s — %s%s', $children, $session, $dt_str ? " — {$dt_str}" : '' ),
                [ 'booking_id' => $booking_id ]
            );
        }
    }

    public static function on_rescheduled_for_coach( int $booking_id, string $new_datetime, $ctx ): void {
        if ( ! $ctx ) return;
        $coach_id = self::resolve_coach_id( $ctx );
        if ( ! $coach_id ) return;

        $session  = sanitize_text_field( $ctx->session_name ?: $ctx->event_name ?: '' );
        $dt_str   = $new_datetime ? date_i18n( 'D j M Y - H:i', strtotime( $new_datetime ) ) : '';
        $children = self::get_children_names( (array) ( $ctx->child_ids ?? [] ) );

        self::send_bm( $coach_id, sprintf(
            "🔄 إعادة جدولة حجز\n👦 الطالب: %s\n📚 الجلسة: %s%s",
            $children,
            $session,
            $dt_str ? "\n🕐 الموعد الجديد: {$dt_str}" : ''
        ) );
    }

    public static function on_cancelled_for_coach( int $booking_id, $ctx ): void {
        if ( ! $ctx ) return;
        $coach_id = self::resolve_coach_id( $ctx );
        if ( ! $coach_id ) return;

        $session  = sanitize_text_field( $ctx->session_name ?: $ctx->event_name ?: '' );
        $children = self::get_children_names( (array) ( $ctx->child_ids ?? [] ) );

        self::send_bm( $coach_id, sprintf(
            "❌ إلغاء حجز\n👦 الطالب: %s\n📚 الجلسة: %s",
            $children,
            $session
        ) );
    }

    /* ─── Rapport hebdomadaire (cron lundi) ─────────────────────────── */

    public static function send_weekly_reports(): void {
        $dow      = (int) date( 'N' );
        $last_mon = date( 'Y-m-d', strtotime( '-' . ( $dow + 6 ) . ' days' ) );
        $last_sun = date( 'Y-m-d', strtotime( '-' . $dow . ' days' ) );

        $children = RKP_CoachStudentRepository::find_all_with_wp_user();
        if ( empty( $children ) ) return;

        $child_ids    = array_map( fn( $c ) => (int) $c->id, $children );
        $start        = $last_mon . ' 00:00:00';
        $end          = $last_sun . ' 23:59:59';

        $sessions_map = RKP_CoachSessionRepository::get_session_counts_for_children( $child_ids, $start, $end );
        $points_map   = RKP_GamificationRepository::get_weekly_points_for_children( $child_ids, $start, $end );
        $missions_map = RKP_MissionRepository::count_completed_for_children_in_week( $child_ids, $last_mon );

        foreach ( $children as $child ) {
            $cid      = (int) $child->id;
            $sessions = (int) ( $sessions_map[ $cid ] ?? 0 );
            $points   = (int) ( $points_map[ $cid ]   ?? 0 );
            $missions = (int) ( $missions_map[ $cid ]  ?? 0 );

            if ( $sessions === 0 && $points === 0 && $missions === 0 ) continue;

            $dashboard_url = home_url( '/dashboard/' );
            $msg = sprintf(
                '📊 بيلان الأسبوع — %s: 📅 لقاءات: %d، ⚡ النقاط: %d، 🎯 المهام: %d',
                $child->child_name, $sessions, $points, $missions
            );
            self::notify_parent_bell( (int) $child->id, 'weekly_report', $msg, [ 'link' => $dashboard_url ] );
        }
    }

    /* ─── Helpers booking coach ─────────────────────────────────────── */

    private static function resolve_coach_id( $ctx ): int {
        if ( ! class_exists( 'RK_MC_Booking_Bridge' ) ) return 0;
        return RK_MC_Booking_Bridge::get_coach_user_id( (int) ( $ctx->event_id ?? 0 ) );
    }

    private static function get_children_names( array $child_ids ): string {
        if ( empty( $child_ids ) ) return '—';
        $map   = RKP_CoachStudentRepository::find_names_by_ids( array_map( 'intval', $child_ids ) );
        $names = array_values( $map );
        return $names ? implode( '، ', $names ) : '—';
    }

    /* ─── URL deep-link vers le dashboard enfant ────────────────────── */

    private static function child_dashboard_url( int $child_id, string $sub_page = '' ): string {
        $base = defined( 'RK_TUTOR_DASHBOARD_URL' )
            ? home_url( RK_TUTOR_DASHBOARD_URL )
            : home_url( '/dashboard/' );
        $url  = $sub_page ? trailingslashit( $base ) . $sub_page . '/' : $base;
        return add_query_arg( 'child_id', $child_id, $url );
    }

    /* ─── Core BM helper ────────────────────────────────────────────── */

    private static function notify_parent( int $child_id, string $message ): void {
        if ( ! class_exists( 'Better_Messages' ) ) return;

        $parent_id = RK_Coach_Data::get_parent_of_child( $child_id );
        if ( ! $parent_id ) return;

        self::send_bm( $parent_id, $message );
    }

    /**
     * Notifie le parent via la cloche (RK_MC_Notification_Service) au lieu
     * d'un message de discussion Better Messages.
     * Utilisé pour : badge obtenu, montée de niveau, mission terminée,
     * bilan hebdomadaire — ce sont des événements informatifs, pas une
     * conversation, donc ils ne doivent pas apparaître dans l'onglet Messages.
     */
    private static function notify_parent_bell( int $child_id, string $type, string $message, array $meta = [] ): void {
        if ( ! class_exists( 'RK_MC_Notification_Service' ) ) return;

        $parent_id = RK_Coach_Data::get_parent_of_child( $child_id );
        if ( ! $parent_id ) return;

        RK_MC_Notification_Service::push( $parent_id, $type, $message, $meta + [ 'child_id' => $child_id ] );
    }

    private static function send_bm( int $user_id, string $message ): void {
        if ( ! class_exists( 'Better_Messages' ) ) return;
        if ( $user_id <= 0 ) return;

        $admin_id = (int) get_option( 'rk_platform_admin_user_id', 0 );
        if ( ! $admin_id || ! get_user_by( 'id', $admin_id ) ) {
            $admins   = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
            $admin_id = ! empty( $admins ) ? (int) $admins[0] : 1;
        }

        Better_Messages()->functions->new_message( [
            'sender_id'  => $admin_id,
            'recipients' => [ $user_id ],
            'content'    => $message,
            'send_push'  => true,
        ] );
    }
}