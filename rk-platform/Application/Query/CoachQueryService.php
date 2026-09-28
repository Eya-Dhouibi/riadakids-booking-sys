<?php
declare( strict_types=1 );
/**
 * Application — CoachQueryService  (Read Model)
 *
 * Source de vérité unique pour les données pédagogiques côté instructeur.
 * Répond à : "Que voit le coach sur ses cours, ses élèves, leur progression ?"
 *
 * RÈGLE : aucun appel direct à WP_Query, get_post_meta, get_posts,
 *         tutor_utils() ou $wpdb — tout passe par les Repositories.
 *
 * Équivalent de LearningQueryService pour le périmètre coach.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class RKP_CoachQueryService {

    // ── Cours de l'instructeur ──────────────────────────────────────────

    /**
     * Cours publiés du coach avec stats agrégées.
     * Équivalent de RK_Coach_Course_Service::get_instructor_courses().
     *
     * @return array[]  [ ['id','title','permalink','thumbnail','category',
     *                    'enrolled','avg_progress','topics_count','lessons_total','quizzes_count'] ]
     */
    public static function get_courses( int $coach_wp_uid, bool $with_stats = true ): array {
        if ( $coach_wp_uid <= 0 ) return [];

        $courses    = RKP_LearningQueryService::get_courses_for_coach( $coach_wp_uid );
        $course_ids = array_map( fn( $c ) => $c->id, $courses );
        $enr_counts = $with_stats ? RKP_EnrollmentRepository::count_batch_by_courses( $course_ids ) : [];
        $out        = [];

        foreach ( $courses as $course ) {
            $enrolled = $enr_counts[ $course->id ] ?? 0;
            $avg_pct  = ( $with_stats && $enrolled > 0 )
                ? self::get_course_avg_progress( $course->id )
                : 0;

            $out[] = [
                'id'            => $course->id,
                'title'         => $course->title,
                'permalink'     => $course->permalink,
                'thumbnail'     => $course->thumbnail,
                'category'      => RKP_LearningQueryService::get_primary_category( $course->id ),
                'enrolled'      => $enrolled,
                'avg_progress'  => $avg_pct,
                'topics_count'  => RKP_TopicRepository::count_by_course( $course->id ),
                'lessons_total' => RKP_ProgressRepository::get_lesson_count( $course->id ),
                'quizzes_count' => RKP_QuizRepository::count_by_course( $course->id ),
            ];
        }

        return $out;
    }

    // ── Inscrits ────────────────────────────────────────────────────────

    /** @return int[]  WP user IDs des élèves inscrits à un cours. */
    public static function get_enrolled_ids( int $course_id ): array {
        $enrollments = RKP_EnrollmentRepository::find_by_course( $course_id );
        return array_map( fn( $e ) => $e->child_id, $enrollments );
    }

    public static function get_enrolled_count( int $course_id ): int {
        return RKP_EnrollmentRepository::count_by_course( $course_id );
    }

    // ── Progression ─────────────────────────────────────────────────────

    /**
     * Progression moyenne (%) de tous les élèves inscrits à un cours.
     */
    public static function get_course_avg_progress( int $course_id ): int {
        return RKP_AnalyticsRepository::get_avg_progress( $course_id );
    }

    // ── Stats de contenu d'un cours ──────────────────────────────────────

    /**
     * Nombre de topics, leçons et quiz d'un cours.
     *
     * @return array{ topics: int, lessons: int, quizzes: int }
     */
    public static function get_course_stats( int $course_id ): array {
        return [
            'topics'  => RKP_TopicRepository::count_by_course( $course_id ),
            'lessons' => RKP_ProgressRepository::get_lesson_count( $course_id ),
            'quizzes' => RKP_QuizRepository::count_by_course( $course_id ),
        ];
    }

    // ── Élèves dans un cours ─────────────────────────────────────────────

    /**
     * Progression de tous les enfants du coach inscrits à un cours.
     * Croise wp_rk_child_coaches (enfants du coach) avec les inscrits Tutor LMS.
     *
     * @return array[]  [ ['child_id','child_name','wp_user_id','progress',
     *                    'lessons_done','lessons_total','next_lesson','last_activity'] ]
     */
    public static function get_students_in_course( int $course_id, int $coach_id ): array {
        if ( $course_id <= 0 || $coach_id <= 0 ) return [];

        $children = RKP_CoachStudentRepository::find_children_for_coach( $coach_id );
        if ( empty( $children ) ) return [];

        $enrolled_wp_ids = self::get_enrolled_ids( $course_id );
        if ( empty( $enrolled_wp_ids ) ) return [];

        $lessons_total = RKP_ProgressRepository::get_lesson_count( $course_id );
        $out           = [];

        // Perf — batch la date de dernière leçon en 1 requête pour tous les
        // élèves enrôlés au lieu d'1 requête par élève dans la boucle
        // ci-dessous (N+1 direct pour une classe nombreuse).
        $enrolled_in_children = array_values( array_filter(
            array_map( static fn( $c ) => (int) $c->wp_user_id, $children ),
            static fn( $wp_uid ) => in_array( $wp_uid, $enrolled_wp_ids, true )
        ) );
        $last_activity_map = RKP_CoachStudentRepository::get_last_lesson_dates_batch( $enrolled_in_children );

        foreach ( $children as $child ) {
            $wp_uid = (int) $child->wp_user_id;
            if ( ! in_array( $wp_uid, $enrolled_wp_ids, true ) ) continue;

            $pct         = RKP_ProgressRepository::get_percent( $course_id, $wp_uid );
            $lessons_done = $lessons_total > 0 ? (int) round( $pct / 100 * $lessons_total ) : 0;
            $next         = RKP_ProgressRepository::get_next_lesson( $course_id, $wp_uid );

            $out[] = [
                'child_id'      => (int) $child->child_id,
                'child_name'    => (string) $child->child_name,
                'wp_user_id'    => $wp_uid,
                'progress'      => $pct,
                'lessons_done'  => $lessons_done,
                'lessons_total' => $lessons_total,
                'next_lesson'   => $next ? [
                    'id'    => $next->id,
                    'title' => $next->title,
                    'url'   => $next->permalink,
                ] : null,
                'last_activity' => $last_activity_map[ $wp_uid ] ?? '',
            ];
        }

        usort( $out, fn( $a, $b ) => $b['progress'] <=> $a['progress'] );
        return $out;
    }

    // ── Quiz ─────────────────────────────────────────────────────────────

    /**
     * Nombre de tentatives en attente de correction manuelle pour un coach.
     */
    public static function get_pending_quiz_count( int $coach_wp_uid ): int {
        return RKP_QuizAttemptRepository::count_pending_review_for_coach( $coach_wp_uid );
    }

    /**
     * Tentatives récentes pour tous les cours d'un coach.
     *
     * @return array[]
     */
    public static function get_recent_quiz_attempts( int $coach_wp_uid, int $limit = 20 ): array {
        return RKP_QuizAttemptRepository::get_recent_for_coach( $coach_wp_uid, $limit );
    }

    // ── Complétion par module ─────────────────────────────────────────────

    /**
     * Avancement par topic — combien d'élèves ont terminé toutes les leçons.
     *
     * @param  int[]  $enrolled_ids  WP user IDs des élèves inscrits.
     * @return array[]
     */
    public static function get_module_completion( int $course_id, array $enrolled_ids ): array {
        return RKP_AnalyticsRepository::get_module_completion( $course_id, $enrolled_ids );
    }

    // ── Stats globales ────────────────────────────────────────────────────

    /**
     * KPIs globaux du coach : cours actifs, élèves, progression moyenne, quiz en attente.
     *
     * @return array{ active_courses: int, total_students: int, avg_progress: int, pending_quizzes: int }
     */
    public static function get_global_stats( int $coach_wp_uid ): array {
        return RKP_AnalyticsRepository::get_instructor_global_stats( $coach_wp_uid );
    }
}
