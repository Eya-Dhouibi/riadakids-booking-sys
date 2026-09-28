<?php
declare( strict_types=1 );
/**
 * RK_MC_Gamification_Service  (v7.0.0 — Adaptateur)
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * ADAPTATEUR vers RKP_GamificationCommandService / RKP_GamificationQueryService.
 *
 * Cette classe conserve son interface publique à l'identique.
 * Son implémentation délègue entièrement à la couche Application de rk-platform.
 *
 * Restent dans cette classe (logique présentation/UI) :
 *   - get_welcome_message()   : message contextuel horodaté
 *   - store_levelup_transient() : handler WP hook
 *   - init() + on_*()          : enregistrement des hooks Tutor LMS
 *
 * À supprimer quand tous les appelants utilisent directement les RKP services.
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * @package RK_My_Children
 * @since   7.0.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class RK_MC_Gamification_Service {

    /* ─── Seuils de niveaux ── délégués à RKP ────────────────────── */
    public static function levels(): array {
        if ( class_exists( 'RKP_GamificationQueryService' ) ) {
            return RKP_GamificationQueryService::levels();
        }
        return [];
    }

    public static function point_values(): array {
        if ( class_exists( 'RKP_GamificationQueryService' ) ) {
            return RKP_GamificationQueryService::point_values();
        }
        return [];
    }

    /* ═══ POINTS ════════════════════════════════════════════════════ */

    public static function add_points(
        int $child_id, int $points, string $source,
        int $source_id = 0, string $note = ''
    ): bool {
        if ( ! class_exists( 'RKP_GamificationCommandService' ) ) return false;
        return RKP_GamificationCommandService::add_points( $child_id, $points, $source, $source_id, $note );
    }

    public static function get_total_points( int $child_id ): int {
        if ( ! class_exists( 'RKP_GamificationQueryService' ) ) return 0;
        return RKP_GamificationQueryService::get_total_points( $child_id );
    }

    public static function get_points_log( int $child_id, int $limit = 10 ): array {
        if ( ! class_exists( 'RKP_GamificationQueryService' ) ) return [];
        return RKP_GamificationQueryService::get_points_log( $child_id, $limit );
    }

    /* ═══ NIVEAUX ═══════════════════════════════════════════════════ */

    public static function compute_level( int $total_points ): array {
        if ( ! class_exists( 'RKP_GamificationQueryService' ) ) return [ 'num' => 1, 'label' => '', 'total' => $total_points ];
        return RKP_GamificationQueryService::compute_level( $total_points );
    }

    public static function get_level( int $child_id ): array {
        if ( ! class_exists( 'RKP_GamificationQueryService' ) ) return [ 'num' => 1, 'label' => '', 'total' => 0 ];
        return RKP_GamificationQueryService::get_level( $child_id );
    }

    /* ═══ MESSAGE DE BIENVENUE (logique présentation — reste ici) ═══ */

    public static function get_welcome_message( int $child_id, string $child_name ): array {
        $hour = (int) current_time( 'G' );

        if ( get_transient( "rk_levelup_{$child_id}" ) ) {
            delete_transient( "rk_levelup_{$child_id}" );
            $level = self::get_level( $child_id );
            return [
                'text'     => sprintf( __( '%1$s! ارتقيت للمستوى الجديد — أنت الآن "%2$s"', 'rk-my-children' ), $child_name, $level['label'] ),
                'type'     => 'levelup',
                'icon_key' => 'trophy',
            ];
        }

        $upcoming = self::get_todays_session( $child_id );
        if ( $upcoming ) {
            $time_str = rk_mc_appt_format( $upcoming->appointment, 'g:i a', (int) ( $upcoming->booking_id ?? 0 ) );
            return [
                'text'     => sprintf( __( 'اليوم عندك جلسة الساعة %s! هل أنت مستعد؟', 'rk-my-children' ), $time_str ),
                'type'     => 'session_today',
                'icon_key' => 'clock',
            ];
        }

        $last_activity = class_exists( 'RKP_GamificationQueryService' )
            ? RKP_GamificationQueryService::get_last_activity_date( $child_id )
            : null;
        if ( $last_activity ) {
            $days_ago = (int) ( ( time() - strtotime( $last_activity ) ) / DAY_IN_SECONDS );
            if ( $days_ago >= 7 ) {
                return [
                    'text'     => sprintf( __( 'اشتقنا لك يا %s! يلا نرجع للمسار', 'rk-my-children' ), $child_name ),
                    'type'     => 'absence',
                    'icon_key' => 'rocket',
                ];
            }
        }

        if ( $hour >= 5 && $hour < 12 ) {
            return [
                'text'     => sprintf( __( 'صباح الطاقة يا %s — يوم جديد يعني فرصة جديدة لكسب النقاط!', 'rk-my-children' ), $child_name ),
                'type'     => 'morning',
                'icon_key' => 'sun',
            ];
        }
        if ( $hour >= 12 && $hour < 17 ) {
            return [
                'text'     => sprintf( __( 'مساء الإبداع يا %s — تحقق من مهامك اليوم!', 'rk-my-children' ), $child_name ),
                'type'     => 'afternoon',
                'icon_key' => 'sun',
            ];
        }
        return [
            'text'     => sprintf( __( 'أهلاً بك يا %s — كيف كان يومك؟', 'rk-my-children' ), $child_name ),
            'type'     => 'evening',
            'icon_key' => 'moon',
        ];
    }

    /* ═══ HOOKS AUTOMATIQUES (Tutor LMS) — restent dans ce plugin ══ */

    public static function init(): void {
        add_action( 'tutor_lesson_completed_after', [ __CLASS__, 'on_lesson_completed' ], 10, 2 );
        add_action( 'tutor_quiz_attempt_ended',     [ __CLASS__, 'on_quiz_completed'    ], 10, 1 );
        add_action( 'tutor_course_complete_after',  [ __CLASS__, 'on_course_completed'  ], 10, 2 );
        add_action( 'rk_mc_child_level_up',         [ __CLASS__, 'store_levelup_transient' ], 10, 3 );

        // CORRECTIF — cohérence dashboard enfant / fiche parent (child-profile.php) :
        // ni add_points() ni Badge_Service::award()/maybe_award() ne purgeaient le
        // cache RK_MC_Child_Dashboard_Data (TTL 3 min) lu par les deux vues. On
        // écoute ici les 3 hooks déjà émis en interne par la couche Application
        // (points, badge, niveau) — couvre aussi les attributions manuelles côté
        // coach (award_with_reason, badges non liés à Tutor) en plus des hooks
        // Tutor LMS déjà gérés par ProgressSyncListener::flush_all_views().
        add_action( 'rk_mc_child_points_added', [ __CLASS__, 'flush_dashboard_cache' ], 10, 1 );
        add_action( 'rk_mc_badge_awarded',      [ __CLASS__, 'flush_dashboard_cache' ], 10, 1 );
        add_action( 'rk_mc_child_level_up',     [ __CLASS__, 'flush_dashboard_cache' ], 10, 1 );
    }

    /** Purge le cache dashboard/child-profile après tout événement de gamification. */
    public static function flush_dashboard_cache( int $child_id ): void {
        if ( $child_id > 0 && class_exists( 'RK_MC_Child_Dashboard_Data' ) ) {
            RK_MC_Child_Dashboard_Data::clear_cache( $child_id );
        }
    }

    public static function on_lesson_completed( int $lesson_id, int $user_id ): void {
        $child_id = self::get_child_id_for_wp_user( $user_id );
        if ( ! $child_id ) return;
        self::add_points( $child_id, 10, 'lesson', $lesson_id, get_the_title( $lesson_id ) ?: '' );
        if ( class_exists( 'RK_MC_Mission_Service' ) ) {
            RK_MC_Mission_Service::record_progress( $child_id, 'complete_lesson', 1 );
        }
    }

    public static function on_quiz_completed( object $attempt ): void {
        $user_id    = (int) ( $attempt->user_id ?? 0 );
        $child_id   = self::get_child_id_for_wp_user( $user_id );
        if ( ! $child_id ) return;

        $attempt_id = (int) ( $attempt->attempt_id ?? $attempt->id ?? 0 );
        $score      = (float) ( $attempt->earned_marks ?? 0 );
        $total      = max( 1.0, (float) ( $attempt->total_marks ?? 1 ) );
        $pct        = $score / $total * 100;

        // Idempotence gérée dans GamificationCommandService (source_id = attempt_id)
        if ( $pct >= 90 ) {
            self::add_points( $child_id, 50, 'quiz_excellent', $attempt_id );
        } elseif ( $pct >= 70 ) {
            self::add_points( $child_id, 25, 'quiz_pass', $attempt_id );
        } elseif ( $pct >= 50 ) {
            self::add_points( $child_id, 10, 'quiz_good', $attempt_id );
        } else {
            self::add_points( $child_id, 5, 'quiz_attempted', $attempt_id );
        }

        if ( class_exists( 'RK_MC_Badge_Service' ) && $pct >= 100.0 ) {
            RK_MC_Badge_Service::award( $child_id, 'quiz_perfect' );
        }
        if ( $pct >= 70 && class_exists( 'RK_MC_Mission_Service' ) ) {
            RK_MC_Mission_Service::record_progress( $child_id, 'pass_quiz', 1 );
        }
    }

    public static function on_course_completed( int $course_id, int $user_id ): void {
        $child_id = self::get_child_id_for_wp_user( $user_id );
        if ( ! $child_id ) return;
        self::add_points( $child_id, 100, 'course_complete', $course_id, get_the_title( $course_id ) ?: '' );
        if ( class_exists( 'RK_MC_Badge_Service' ) ) {
            RK_MC_Badge_Service::maybe_award( $child_id, 'course_complete' );
        }
    }

    public static function store_levelup_transient( int $child_id, array $new, array $old ): void {
        $wp_uid = class_exists( 'RKP_ChildRepository' )
            ? RKP_ChildRepository::get_wp_user_id( $child_id )
            : (int) ( RK_MC_Child_Repository::find( $child_id )->wp_user_id ?? 0 );
        if ( $wp_uid ) {
            set_transient( "rk_levelup_{$child_id}", $new, 2 * DAY_IN_SECONDS );
        }
    }

    /* ── Helpers contexte présentation (restent ici — usage interne) */

    private static function get_todays_session( int $child_id ): ?object {
        return class_exists( 'RKP_BookingRepository' )
            ? RKP_BookingRepository::get_today_session( $child_id )
            : null;
    }

    /* ── Helper : child_id from wp_user_id ─────────────────────────── */

    public static function get_child_id_for_wp_user( int $wp_user_id ): int {
        if ( class_exists( 'RKP_ChildRepository' ) ) {
            return RKP_ChildRepository::get_id_by_wp_user( $wp_user_id );
        }
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            'SELECT id FROM ' . rk_mc_children_table() . ' WHERE wp_user_id = %d LIMIT 1',
            $wp_user_id
        ) );
    }
}
