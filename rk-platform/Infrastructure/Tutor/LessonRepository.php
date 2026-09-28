<?php
declare( strict_types=1 );
/**
 * Infrastructure — LessonRepository  (Tutor LMS)
 *
 * SEULE couche autorisée à requêter le CPT 'lesson'.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class RKP_LessonRepository {

    /** @return RKP_Lesson[] — toutes les leçons d'un topic */
    public static function find_by_topic( int $topic_id ): array {
        $posts = get_posts( [
            'post_type'      => 'lesson',
            'post_status'    => 'publish',
            'post_parent'    => $topic_id,
            'posts_per_page' => -1,
            'orderby'        => 'menu_order',
            'order'          => 'ASC',
            'no_found_rows'  => true,
        ] );
        $course_id = (int) get_post_field( 'post_parent', $topic_id );
        return array_map( fn( $p ) => self::hydrate( $p, $topic_id, $course_id ), $posts );
    }

    public static function find( int $lesson_id ): ?RKP_Lesson {
        $post = get_post( $lesson_id );
        if ( ! $post || 'lesson' !== $post->post_type ) return null;
        $topic_id  = (int) $post->post_parent;
        $course_id = (int) get_post_field( 'post_parent', $topic_id );
        return self::hydrate( $post, $topic_id, $course_id );
    }

    /**
     * Version BATCH de find_by_topic() — toutes les leçons de N topics
     * en une seule requête, au lieu de N appels get_posts(). Voir
     * RKP_TopicRepository::find_by_courses_batch() pour le même principe.
     *
     * @param int[]              $topic_ids
     * @param array<int,int>     $course_id_by_topic [topic_id => course_id],
     *        fourni explicitement par l'appelant (qui le connaît déjà via
     *        find_by_courses_batch()) — évite tout appel get_post_field()
     *        en boucle, dont le comportement de cache ne doit jamais être
     *        supposé plutôt que garanti.
     * @return array<int, RKP_Lesson[]> [topic_id => lessons[]]
     */
    public static function find_by_topics_batch( array $topic_ids, array $course_id_by_topic ): array {
        $topic_ids = array_values( array_unique( array_map( 'intval', $topic_ids ) ) );
        $out       = array_fill_keys( $topic_ids, [] );
        if ( empty( $topic_ids ) ) return $out;

        $posts = get_posts( [
            'post_type'       => 'lesson',
            'post_status'     => 'publish',
            'post_parent__in' => $topic_ids,
            'posts_per_page'  => -1,
            'orderby'         => 'menu_order',
            'order'           => 'ASC',
            'no_found_rows'   => true,
        ] );

        // v9.15 — Préchargement manuel des attachments "featured image"
        // (get_the_post_thumbnail_url() appelée ensuite dans hydrate()).
        // get_posts() seul précharge déjà les POSTMETA (via
        // update_post_caches(), $update_meta_cache=true par défaut —
        // confirmé par la doc officielle WP), donc _thumbnail_id est
        // déjà en cache ; MAIS il ne précharge PAS les attachments
        // eux-mêmes (ça, c'est le rôle de update_post_thumbnail_cache(),
        // qui n'est câblée que dans une vraie boucle WP_Query/have_posts(),
        // jamais déclenchée par un simple get_posts() — confirmé par la
        // doc officielle de cette fonction). Sans ce préchargement manuel,
        // chaque get_the_post_thumbnail_url() dans hydrate() résoudrait
        // silencieusement un get_post() séparé par leçon — un vrai N+1
        // caché, le même type de piège déjà rencontré ailleurs dans ce
        // plugin, corrigé ici avant qu'il n'existe.
        $thumbnail_ids = [];
        foreach ( $posts as $post ) {
            $tid = get_post_meta( $post->ID, '_thumbnail_id', true );
            if ( $tid ) $thumbnail_ids[] = (int) $tid;
        }
        if ( ! empty( $thumbnail_ids ) ) {
            get_posts( [
                'post_type'      => 'attachment',
                'post_status'    => 'inherit',
                'post__in'       => array_unique( $thumbnail_ids ),
                'posts_per_page' => -1,
                'no_found_rows'  => true,
            ] ); // résultat ignoré volontairement : cet appel sert uniquement à chauffer le cache objet des attachments
        }

        foreach ( $posts as $post ) {
            $topic_id  = (int) $post->post_parent;
            $course_id = $course_id_by_topic[ $topic_id ] ?? 0;
            $out[ $topic_id ][] = self::hydrate( $post, $topic_id, $course_id );
        }

        return $out;
    }

    private static function hydrate( \WP_Post $post, int $topic_id, int $course_id ): RKP_Lesson {
        return new RKP_Lesson(
            id:        $post->ID,
            topic_id:  $topic_id,
            course_id: $course_id,
            title:     $post->post_title,
            type:      get_post_meta( $post->ID, '_tutor_lesson_media_type', true ) ?: 'text',
            order:     (int) $post->menu_order,
            permalink: (string) get_permalink( $post->ID ),
            thumbnail: (string) get_the_post_thumbnail_url( $post->ID, 'medium' ),
        );
    }
}
