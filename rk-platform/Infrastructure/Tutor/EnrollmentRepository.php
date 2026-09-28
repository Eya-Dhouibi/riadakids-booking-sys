<?php
declare( strict_types=1 );
/**
 * Infrastructure — EnrollmentRepository  (Tutor LMS)
 *
 * SEULE couche autorisée à requêter le CPT 'tutor_enrolled'.
 * Tutor LMS modélise chaque inscription comme un post de type 'tutor_enrolled'
 * dont post_parent = course_id et post_author = student_id.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class RKP_EnrollmentRepository {

    /** @return RKP_Enrollment[] — toutes les inscriptions actives à un cours */
    public static function find_by_course( int $course_id ): array {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT post_author AS child_id, post_date AS enrolled_at, post_status AS status
               FROM {$wpdb->posts}
              WHERE post_type   = 'tutor_enrolled'
                AND post_parent = %d",
            $course_id
        ) );
        return array_map( fn( $r ) => new RKP_Enrollment(
            child_id:    (int) $r->child_id,
            course_id:   $course_id,
            enrolled_at: new \DateTimeImmutable( $r->enrolled_at ),
            status:      $r->status,
        ), $rows );
    }

    /** @return RKP_Enrollment[] — tous les cours d'un enfant */
    public static function find_by_child( int $child_id ): array {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT post_parent AS course_id, post_date AS enrolled_at, post_status AS status
               FROM {$wpdb->posts}
              WHERE post_type   = 'tutor_enrolled'
                AND post_author = %d",
            $child_id
        ) );
        return array_map( fn( $r ) => new RKP_Enrollment(
            child_id:    $child_id,
            course_id:   (int) $r->course_id,
            enrolled_at: new \DateTimeImmutable( $r->enrolled_at ),
            status:      $r->status,
        ), $rows );
    }

    /** Nombre d'inscriptions à un cours (COUNT sans charger les objets). */
    public static function count_by_course( int $course_id ): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts}
              WHERE post_type = 'tutor_enrolled' AND post_parent = %d",
            $course_id
        ) );
    }

    /**
     * Nombre d'inscriptions pour chaque cours — une seule requête SQL.
     *
     * @param int[] $course_ids
     * @return array<int, int>  [ course_id => count ]
     */
    public static function count_batch_by_courses( array $course_ids ): array {
        if ( empty( $course_ids ) ) return [];
        global $wpdb;
        $ph   = implode( ',', array_fill( 0, count( $course_ids ), '%d' ) );
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT post_parent AS course_id, COUNT(*) AS cnt
               FROM {$wpdb->posts}
              WHERE post_type = 'tutor_enrolled' AND post_parent IN ({$ph})
              GROUP BY post_parent",
            $course_ids
        ) ) ?: [];
        $map = array_fill_keys( $course_ids, 0 );
        foreach ( $rows as $r ) {
            $map[ (int) $r->course_id ] = (int) $r->cnt;
        }
        return $map;
    }

    public static function is_enrolled( int $child_id, int $course_id ): bool {
        global $wpdb;
        $count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts}
              WHERE post_type   = 'tutor_enrolled'
                AND post_author = %d
                AND post_parent = %d",
            $child_id,
            $course_id
        ) );
        return $count > 0;
    }
}
