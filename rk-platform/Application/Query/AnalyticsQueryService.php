<?php
declare( strict_types=1 );
/**
 * Application — AnalyticsQueryService  (Read Model)
 *
 * Agrégations statistiques sur les cours, élèves, progression.
 * Répond à : "Quelles sont les statistiques pédagogiques ?"
 *
 * RÈGLE : aucun appel direct à WP functions ou $wpdb.
 *         Tout passe par RKP_AnalyticsRepository.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class RKP_AnalyticsQueryService {

    /** Progression moyenne des élèves sur un cours (0-100). */
    public static function get_avg_progress( int $course_id ): int {
        return RKP_AnalyticsRepository::get_avg_progress( $course_id );
    }

    /** Nombre d'élèves inscrits à un cours. */
    public static function get_enrolled_count( int $course_id ): int {
        return RKP_AnalyticsRepository::count_enrolled( $course_id );
    }

    /** IDs WP des élèves inscrits à un cours. */
    public static function get_enrolled_ids( int $course_id ): array {
        return RKP_AnalyticsRepository::get_enrolled_ids( $course_id );
    }

    /** Nombre de quiz en attente de correction manuelle pour un coach. */
    public static function get_pending_quiz_count( int $coach_wp_uid ): int {
        return RKP_AnalyticsRepository::get_pending_quiz_count( $coach_wp_uid );
    }

    /**
     * KPIs globaux pour la home du Coach Dashboard.
     * @return array{ active_courses: int, avg_progress: int, pending_quizzes: int }
     */
    public static function get_instructor_global_stats( int $coach_wp_uid ): array {
        return RKP_AnalyticsRepository::get_instructor_global_stats( $coach_wp_uid );
    }

    /** Completion par module/topic pour un cours. */
    public static function get_module_completion( int $course_id, array $enrolled_ids ): array {
        return RKP_AnalyticsRepository::get_module_completion( $course_id, $enrolled_ids );
    }
}
