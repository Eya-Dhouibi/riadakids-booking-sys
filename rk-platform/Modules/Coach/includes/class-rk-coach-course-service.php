<?php
declare( strict_types=1 );
/**
 * RK_Coach_Course_Service  (v2.0.0 — Adaptateur)
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * ADAPTATEUR TEMPORAIRE vers RKP_CoachQueryService.
 *
 * Cette classe conserve son interface publique à l'identique (aucun appelant
 * existant n'a besoin d'être modifié). Son implémentation délègue entièrement
 * à la couche Application de rk-platform.
 *
 * Migration :
 *   v1.0.0 — implémentation directe (get_posts / $wpdb / tutor_utils).
 *   v2.0.0 — adaptateur : délègue à RKP_CoachQueryService + Repositories.
 *
 * Format de retour de get_instructor_courses() (inchangé depuis v1.0.0) :
 *   [ 'id', 'title', 'permalink', 'thumbnail', 'category',
 *     'enrolled', 'avg_progress', 'topics_count', 'lessons_total', 'quizzes_count' ]
 *
 * À supprimer quand tous les appelants utilisent directement
 * RKP_CoachQueryService.
 *
 * @package RK_Coach_Hub
 * @since   2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class RK_Coach_Course_Service {

    /* ═══════════════════════════════════════════════════════════════════
       COURS DE L'INSTRUCTEUR
       ═══════════════════════════════════════════════════════════════════ */

    /**
     * Cours publiés du coach avec stats agrégées.
     *
     * @param  int   $coach_wp_uid
     * @param  bool  $with_stats  true = calcule avg_progress (requêtes supplémentaires).
     * @return array[]
     */
    public static function get_instructor_courses( int $coach_wp_uid, bool $with_stats = true ): array {
        if ( ! class_exists( 'RKP_CoachQueryService' ) ) return [];

        $cache_key = 'rk_coach_courses_' . $coach_wp_uid . ( $with_stats ? '_s' : '' );
        $cached    = get_transient( $cache_key );
        if ( false !== $cached ) return $cached;

        $result = RKP_CoachQueryService::get_courses( $coach_wp_uid, $with_stats );
        set_transient( $cache_key, $result, 10 * MINUTE_IN_SECONDS );
        return $result;
    }

    /* ═══════════════════════════════════════════════════════════════════
       INSCRITS
       ═══════════════════════════════════════════════════════════════════ */

    /**
     * WP user IDs des élèves inscrits à un cours.
     *
     * @return int[]
     */
    public static function get_enrolled_user_ids( int $course_id ): array {
        if ( ! class_exists( 'RKP_CoachQueryService' ) ) return [];
        return RKP_CoachQueryService::get_enrolled_ids( $course_id );
    }

    /**
     * Nombre d'inscrits à un cours.
     */
    public static function get_enrolled_count( int $course_id ): int {
        if ( ! class_exists( 'RKP_CoachQueryService' ) ) return 0;
        return RKP_CoachQueryService::get_enrolled_count( $course_id );
    }

    /* ═══════════════════════════════════════════════════════════════════
       PROGRESSION
       ═══════════════════════════════════════════════════════════════════ */

    /**
     * Progression moyenne (0-100) de tous les inscrits dans un cours.
     *
     * @param  int    $course_id
     * @param  int[]  $user_ids  Ignoré — compat v1.0.0 signature (déprécié).
     */
    public static function get_course_avg_progress( int $course_id, array $user_ids = [] ): int {
        if ( ! class_exists( 'RKP_CoachQueryService' ) ) return 0;
        return RKP_CoachQueryService::get_course_avg_progress( $course_id );
    }

    /* ═══════════════════════════════════════════════════════════════════
       STATS DE CONTENU D'UN COURS
       ═══════════════════════════════════════════════════════════════════ */

    /**
     * Topics, leçons, quiz d'un cours.
     *
     * @return array{ topics: int, lessons: int, quizzes: int }
     */
    public static function get_course_lms_stats( int $course_id ): array {
        if ( ! class_exists( 'RKP_CoachQueryService' ) ) {
            return [ 'topics' => 0, 'lessons' => 0, 'quizzes' => 0 ];
        }
        return RKP_CoachQueryService::get_course_stats( $course_id );
    }

    /* ═══════════════════════════════════════════════════════════════════
       ÉLÈVES DANS UN COURS
       ═══════════════════════════════════════════════════════════════════ */

    /**
     * Progression de tous les enfants du coach inscrits à un cours.
     *
     * @return array[]
     */
    public static function get_students_in_course( int $course_id, int $coach_id ): array {
        if ( ! class_exists( 'RKP_CoachQueryService' ) ) return [];
        return RKP_CoachQueryService::get_students_in_course( $course_id, $coach_id );
    }

    /* ═══════════════════════════════════════════════════════════════════
       QUIZ
       ═══════════════════════════════════════════════════════════════════ */

    /**
     * Nombre de tentatives en attente de correction manuelle.
     */
    public static function get_pending_quiz_count( int $coach_wp_uid ): int {
        if ( ! class_exists( 'RKP_CoachQueryService' ) ) return 0;
        return RKP_CoachQueryService::get_pending_quiz_count( $coach_wp_uid );
    }

    /**
     * Tentatives récentes pour les cours du coach.
     *
     * @return array[]
     */
    public static function get_recent_quiz_attempts( int $coach_wp_uid, int $limit = 20 ): array {
        if ( ! class_exists( 'RKP_CoachQueryService' ) ) return [];
        return RKP_CoachQueryService::get_recent_quiz_attempts( $coach_wp_uid, $limit );
    }

    /* ═══════════════════════════════════════════════════════════════════
       COMPLÉTION PAR MODULE
       ═══════════════════════════════════════════════════════════════════ */

    /**
     * Avancement par topic pour des élèves donnés.
     *
     * @param  int[]  $enrolled_wp_ids
     * @return array[]
     */
    public static function get_module_completion( int $course_id, array $enrolled_wp_ids ): array {
        if ( ! class_exists( 'RKP_CoachQueryService' ) ) return [];
        return RKP_CoachQueryService::get_module_completion( $course_id, $enrolled_wp_ids );
    }

    /* ═══════════════════════════════════════════════════════════════════
       STATS GLOBALES
       ═══════════════════════════════════════════════════════════════════ */

    /**
     * KPIs globaux du coach : cours actifs, élèves, progression, quiz en attente.
     *
     * @return array{ active_courses: int, total_students: int, avg_progress: int, pending_quizzes: int }
     */
    public static function get_instructor_global_stats( int $coach_wp_uid ): array {
        if ( ! class_exists( 'RKP_CoachQueryService' ) ) {
            return [ 'active_courses' => 0, 'total_students' => 0, 'avg_progress' => 0, 'pending_quizzes' => 0 ];
        }

        $cache_key = 'rk_coach_global_lms_' . $coach_wp_uid;
        $cached    = get_transient( $cache_key );
        if ( false !== $cached ) return $cached;

        $result = RKP_CoachQueryService::get_global_stats( $coach_wp_uid );
        set_transient( $cache_key, $result, 10 * MINUTE_IN_SECONDS );
        return $result;
    }

    /* ═══════════════════════════════════════════════════════════════════
       CACHE
       ═══════════════════════════════════════════════════════════════════ */

    public static function bust_cache( int $coach_wp_uid ): void {
        delete_transient( 'rk_coach_courses_' . $coach_wp_uid );
        delete_transient( 'rk_coach_courses_' . $coach_wp_uid . '_s' );
        delete_transient( 'rk_coach_global_lms_' . $coach_wp_uid );
    }
}
