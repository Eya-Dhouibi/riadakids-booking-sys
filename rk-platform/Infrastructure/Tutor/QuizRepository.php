<?php
declare( strict_types=1 );
/**
 * Infrastructure — QuizRepository  (Tutor LMS)
 *
 * SEULE couche autorisée à requêter le CPT 'tutor_quiz'.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class RKP_QuizRepository {

    /** @return RKP_Quiz[] — quiz d'un cours (tous topics confondus) */
    public static function find_by_course( int $course_id ): array {
        global $wpdb;
        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT q.ID
               FROM {$wpdb->posts} q
               JOIN {$wpdb->posts} t ON t.ID = q.post_parent
              WHERE q.post_type   = 'tutor_quiz'
                AND q.post_status = 'publish'
                AND t.post_type   = 'topics'
                AND t.post_parent = %d",
            $course_id
        ) );
        return array_filter( array_map( fn( $id ) => self::find( (int) $id, $course_id ), $ids ) );
    }

    /**
     * COUNT direct — évite de charger les objets quand on veut juste le nombre.
     */
    public static function count_by_course( int $course_id ): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT( q.ID )
               FROM {$wpdb->posts} q
               JOIN {$wpdb->posts} t ON t.ID = q.post_parent
              WHERE q.post_type   = 'tutor_quiz'
                AND q.post_status = 'publish'
                AND t.post_type   = 'topics'
                AND t.post_parent = %d",
            $course_id
        ) );
    }

    public static function find( int $quiz_id, int $course_id = 0 ): ?RKP_Quiz {
        $post = get_post( $quiz_id );
        if ( ! $post || 'tutor_quiz' !== $post->post_type ) return null;
        $topic_id = (int) $post->post_parent;
        if ( ! $course_id ) {
            $course_id = (int) get_post_field( 'post_parent', $topic_id );
        }
        return new RKP_Quiz(
            id:              $post->ID,
            course_id:       $course_id,
            topic_id:        $topic_id,
            title:           $post->post_title,
            questions_count: function_exists( 'tutor_utils' )
                ? (int) tutor_utils()->get_quiz_question_count( $post->ID ) : 0,
            pass_percent:    (int) get_post_meta( $post->ID, '_tutor_quiz_passing_grade', true ),
            permalink:       (string) get_permalink( $post->ID ),
        );
    }
}
