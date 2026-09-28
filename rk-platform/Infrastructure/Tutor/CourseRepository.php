<?php
declare( strict_types=1 );
/**
 * Infrastructure — CourseRepository  (Tutor LMS)
 *
 * SEULE couche autorisée à appeler WP_Query / get_post_meta()
 * pour le CPT 'courses' de Tutor LMS.
 *
 * Phase 1 : implémentation réelle via WP_Query.
 * Phase 2 : ajouter cache / invalidation sur Domain Events.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class RKP_CourseRepository {

    /** @return RKP_Course[] */
    public static function find_all( int $limit = 100 ): array {
        $posts = get_posts( [
            'post_type'      => 'courses',
            'post_status'    => 'publish',
            'posts_per_page' => $limit,
            'no_found_rows'  => true,
        ] );
        return array_map( [ self::class, 'hydrate' ], $posts );
    }

    public static function find( int $course_id ): ?RKP_Course {
        $post = get_post( $course_id );
        if ( ! $post || 'courses' !== $post->post_type ) return null;
        return self::hydrate( $post );
    }

    /** @return RKP_Course[] — cours d'un instructeur spécifique */
    public static function find_by_instructor( int $instructor_id ): array {
        global $wpdb;
        // Tutor LMS stocke l'instructeur principal via 'post_author'
        // et les co-instructeurs via la table tutor_courseinfo.
        $posts = get_posts( [
            'post_type'      => 'courses',
            'post_status'    => 'publish',
            'author'         => $instructor_id,
            'posts_per_page' => -1,
            'no_found_rows'  => true,
        ] );
        return array_map( [ self::class, 'hydrate' ], $posts );
    }

    /**
     * Récupère plusieurs cours en une seule requête.
     *
     * @param int[] $ids
     * @return array<int, RKP_Course>  indexé par course_id
     */
    public static function find_batch( array $ids ): array {
        if ( empty( $ids ) ) return [];
        $posts = get_posts( [
            'post_type'      => 'courses',
            'post_status'    => 'publish',
            'post__in'       => array_map( 'intval', $ids ),
            'posts_per_page' => -1,
            'no_found_rows'  => true,
            'orderby'        => 'post__in',
        ] );
        $map = [];
        foreach ( $posts as $post ) {
            $map[ $post->ID ] = self::hydrate( $post );
        }
        return $map;
    }

    /** @return RKP_Course[] — cours auxquels un enfant est inscrit */
    public static function find_enrolled_by_child( int $child_id ): array {
        global $wpdb;
        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT post_parent FROM {$wpdb->posts}
              WHERE post_type = 'tutor_enrolled'
                AND post_author = %d
                AND post_status = 'completed'",
            $child_id
        ) );
        if ( empty( $ids ) ) return [];
        $posts = get_posts( [
            'post_type'      => 'courses',
            'post_status'    => 'publish',
            'post__in'       => array_map( 'intval', $ids ),
            'posts_per_page' => -1,
            'no_found_rows'  => true,
        ] );
        return array_map( [ self::class, 'hydrate' ], $posts );
    }

    /**
     * Instructeur principal d'un cours (WP_User ou null).
     * Tutor LMS gère l'instructeur via post_author + méta co-instructeurs.
     */
    public static function find_instructor( int $course_id ): ?\WP_User {
        if ( function_exists( 'tutor_utils' ) ) {
            $user = tutor_utils()->get_tutor_instructor_of_this_course( $course_id );
            if ( $user instanceof \WP_User ) return $user;
        }
        $post = get_post( $course_id );
        if ( ! $post ) return null;
        return get_userdata( (int) $post->post_author ) ?: null;
    }

    /**
     * Image d'un cours, tolérante à l'absence de featured image WP.
     *
     * Priorité :
     *   1. tutor_utils()->get_thumbnail_url() — API officielle Tutor LMS,
     *      qui retombe elle-même sur le placeholder Tutor si le cours n'a
     *      pas d'image "à la une" définie (jamais de chaîne vide).
     *   2. get_the_post_thumbnail_url() — repli si Tutor LMS est absent
     *      (ex. environnement de test sans le plugin actif).
     *
     * @since 4.21.1 — avant ce correctif, un cours sans featured image WP
     * renvoyait `thumb_url`/`thumbnail` vide, laissant la carte mobile et
     * la couverture de la fiche cours sans image (dégradé de repli).
     */
    public static function resolve_thumbnail( int $post_id ): string {
        if ( function_exists( 'tutor_utils' ) ) {
            $url = (string) tutor_utils()->get_thumbnail_url( $post_id );
            if ( '' !== $url ) return $url;
        }
        return (string) get_the_post_thumbnail_url( $post_id, 'medium' );
    }

    private static function hydrate( \WP_Post $post ): RKP_Course {
        $topics_count  = function_exists( 'tutor_utils' ) ? (int) tutor_utils()->get_topic_count_by_course( $post->ID ) : 0;
        $lessons_total = function_exists( 'tutor_utils' ) ? (int) tutor_utils()->get_lesson_count_by_course( $post->ID ) : 0;
        $instructor    = function_exists( 'tutor_utils' ) ? tutor_utils()->get_tutor_instructor_of_this_course( $post->ID ) : null;
        return new RKP_Course(
            id:              $post->ID,
            title:           $post->post_title,
            category:        '',
            instructor_id:   $instructor ? (int) $instructor->ID : (int) $post->post_author,
            instructor_name: $instructor ? $instructor->display_name : '',
            topics_count:    $topics_count,
            lessons_total:   $lessons_total,
            quizzes_count:   0,
            status:          $post->post_status,
            permalink:       (string) get_permalink( $post->ID ),
            thumbnail:       self::resolve_thumbnail( $post->ID ),
        );
    }
}