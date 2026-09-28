<?php
declare( strict_types=1 );
/**
 * Infrastructure — AnalyticsRepository  (Tutor LMS + custom)
 *
 * Agrégations statistiques pédagogiques.
 * Seule couche autorisée à appeler $wpdb et tutor_utils() pour les stats.
 *
 * Phase 6 : toutes les délégations vers RK_Coach_Course_Service sont supprimées.
 *           Les méthodes sont implémentées directement via les Repositories RKP.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class RKP_AnalyticsRepository {

    /** Nombre d'élèves inscrits à un cours. */
    public static function count_enrolled( int $course_id ): int {
        return RKP_EnrollmentRepository::count_by_course( $course_id );
    }

    /** IDs WP des élèves inscrits à un cours. */
    public static function get_enrolled_ids( int $course_id ): array {
        $enrollments = RKP_EnrollmentRepository::find_by_course( $course_id );
        return array_map( fn( $e ) => $e->child_id, $enrollments );
    }

    /**
     * Nombre de tentatives de quiz en attente de correction manuelle pour un coach.
     */
    public static function get_pending_quiz_count( int $coach_wp_uid ): int {
        return RKP_QuizAttemptRepository::count_pending_review_for_coach( $coach_wp_uid );
    }

    /**
     * Progression moyenne (%) de tous les élèves sur un cours.
     */
    public static function get_avg_progress( int $course_id ): int {
        $enrolled_ids = self::get_enrolled_ids( $course_id );
        if ( empty( $enrolled_ids ) ) return 0;
        $total = array_sum( array_map(
            fn( $uid ) => RKP_ProgressRepository::get_percent( $course_id, $uid ),
            $enrolled_ids
        ) );
        return (int) round( $total / count( $enrolled_ids ) );
    }

    /**
     * KPIs globaux pour la home Coach Dashboard.
     *
     * @return array{ active_courses: int, total_students: int, avg_progress: int, pending_quizzes: int }
     */
    public static function get_instructor_global_stats( int $coach_wp_uid ): array {
        global $wpdb;
        $course_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts}
              WHERE post_type = 'courses' AND post_status = 'publish' AND post_author = %d",
            $coach_wp_uid
        ) ) ?: [];

        $active_courses = count( $course_ids );
        $total_students = 0;
        $sum_progress   = 0;
        $count_prog     = 0;

        foreach ( $course_ids as $cid ) {
            $enrolled = self::count_enrolled( (int) $cid );
            $total_students += $enrolled;
            if ( $enrolled > 0 ) {
                $sum_progress += self::get_avg_progress( (int) $cid );
                $count_prog++;
            }
        }

        return [
            'active_courses'  => $active_courses,
            'total_students'  => $total_students,
            'avg_progress'    => $count_prog > 0 ? (int) round( $sum_progress / $count_prog ) : 0,
            'pending_quizzes' => self::get_pending_quiz_count( $coach_wp_uid ),
        ];
    }

    /**
     * Complétion par module/topic pour un cours donné.
     *
     * @param  int[]  $enrolled_ids  WP user IDs des élèves inscrits.
     * @return array  [ ['topic_id','topic_title','lessons','students_done','students_total'] ]
     */
    public static function get_module_completion( int $course_id, array $enrolled_ids ): array {
        if ( $course_id <= 0 || empty( $enrolled_ids ) ) return [];
        global $wpdb;

        $topics = $wpdb->get_results( $wpdb->prepare(
            "SELECT ID, post_title, menu_order
               FROM {$wpdb->posts}
              WHERE post_type   = 'topics'
                AND post_parent = %d
                AND post_status = 'publish'
           ORDER BY menu_order ASC",
            $course_id
        ) ) ?: [];

        if ( empty( $topics ) ) return [];

        $ct_table = $wpdb->prefix . 'tutor_completed_lesson';
        $has_ct   = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $ct_table ) ) === $ct_table;

        $out = [];
        foreach ( $topics as $topic ) {
            $tid = (int) $topic->ID;

            $lesson_ids = $wpdb->get_col( $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts}
                  WHERE post_type   = 'lesson'
                    AND post_parent = %d
                    AND post_status = 'publish'",
                $tid
            ) ) ?: [];

            $lessons_count = count( $lesson_ids );
            $students_done = 0;

            if ( $has_ct && $lessons_count > 0 ) {
                $ph_l = implode( ',', array_map( 'intval', $lesson_ids ) );
                foreach ( $enrolled_ids as $uid ) {
                    $done = (int) $wpdb->get_var( $wpdb->prepare(
                        "SELECT COUNT(*) FROM {$ct_table}
                          WHERE user_id   = %d
                            AND lesson_id IN ({$ph_l})",
                        (int) $uid
                    ) );
                    if ( $done >= $lessons_count ) $students_done++;
                }
            }

            $out[] = [
                'topic_id'       => $tid,
                'topic_title'    => (string) $topic->post_title,
                'lessons'        => $lessons_count,
                'students_done'  => $students_done,
                'students_total' => count( $enrolled_ids ),
            ];
        }
        return $out;
    }
}
