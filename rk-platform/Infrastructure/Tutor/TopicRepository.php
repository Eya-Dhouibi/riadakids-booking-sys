<?php
declare( strict_types=1 );
/**
 * Infrastructure — TopicRepository  (Tutor LMS)
 *
 * SEULE couche autorisée à requêter le CPT 'topics'.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class RKP_TopicRepository {

    /** @return RKP_Topic[] — topics d'un cours, triés par menu_order */
    public static function find_by_course( int $course_id ): array {
        $posts = get_posts( [
            'post_type'      => 'topics',
            'post_status'    => 'publish',
            'post_parent'    => $course_id,
            'posts_per_page' => -1,
            'orderby'        => 'menu_order',
            'order'          => 'ASC',
            'no_found_rows'  => true,
        ] );
        return array_map( fn( $p ) => self::hydrate( $p, $course_id ), $posts );
    }

    /** COUNT direct sans charger les objets. */
    public static function count_by_course( int $course_id ): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts}
              WHERE post_type   = 'topics'
                AND post_status = 'publish'
                AND post_parent = %d",
            $course_id
        ) );
    }

    /**
     * Version BATCH de find_by_course() — tous les topics de N cours en
     * UNE seule requête WP_Query (post__in via post_parent IN), au lieu
     * de N appels get_posts(). Nécessaire pour la vue شارات المغامرات à
     * plat (toutes les séances de tous les cours de la plateforme) —
     * même discipline anti-N+1 déjà appliquée à
     * RKP_ProgressRepository::get_percent_batch().
     *
     * @param int[] $course_ids
     * @return array<int, RKP_Topic[]> [course_id => topics[]]
     */
    public static function find_by_courses_batch( array $course_ids ): array {
        $course_ids = array_values( array_unique( array_map( 'intval', $course_ids ) ) );
        $out        = array_fill_keys( $course_ids, [] );
        if ( empty( $course_ids ) ) return $out;

        $posts = get_posts( [
            'post_type'      => 'topics',
            'post_status'    => 'publish',
            'post_parent__in'=> $course_ids,
            'posts_per_page' => -1,
            'orderby'        => 'menu_order',
            'order'          => 'ASC',
            'no_found_rows'  => true,
        ] );

        // Comptage des leçons par topic — nécessaire à l'hydratation de
        // RKP_Topic (lessons_count), lui aussi en une seule requête
        // groupée plutôt que tutor_utils()->get_lesson_count_by_topic()
        // appelée par topic (voir hydrate() single, méthode inchangée).
        $topic_ids = array_map( static fn( \WP_Post $p ) => $p->ID, $posts );
        global $wpdb;
        $lesson_counts = [];
        if ( ! empty( $topic_ids ) ) {
            $ph = implode( ',', array_fill( 0, count( $topic_ids ), '%d' ) );
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT post_parent AS topic_id, COUNT(*) AS cnt
                   FROM {$wpdb->posts}
                  WHERE post_type = 'lesson' AND post_status = 'publish'
                    AND post_parent IN ({$ph})
               GROUP BY post_parent", // phpcs:ignore WordPress.DB.PreparedSQL -- $ph = placeholders %d uniquement
                $topic_ids
            ), ARRAY_A ) ?: [];
            foreach ( $rows as $row ) {
                $lesson_counts[ (int) $row['topic_id'] ] = (int) $row['cnt'];
            }
        }

        foreach ( $posts as $post ) {
            $course_id = (int) $post->post_parent;
            $out[ $course_id ][] = new RKP_Topic(
                id:            $post->ID,
                course_id:     $course_id,
                title:         $post->post_title,
                order:         (int) $post->menu_order,
                lessons_count: $lesson_counts[ $post->ID ] ?? 0,
            );
        }

        return $out;
    }

    public static function find( int $topic_id ): ?RKP_Topic {
        $post = get_post( $topic_id );
        if ( ! $post || 'topics' !== $post->post_type ) return null;
        return self::hydrate( $post, (int) $post->post_parent );
    }

    private static function hydrate( \WP_Post $post, int $course_id ): RKP_Topic {
        $lessons_count = function_exists( 'tutor_utils' )
            ? (int) tutor_utils()->get_lesson_count_by_topic( $post->ID )
            : 0;
        return new RKP_Topic(
            id:            $post->ID,
            course_id:     $course_id,
            title:         $post->post_title,
            order:         (int) $post->menu_order,
            lessons_count: $lessons_count,
        );
    }
}
