<?php
declare( strict_types=1 );
/**
 * Infrastructure — CategoryRepository  (WordPress Taxonomies)
 *
 * SEULE couche autorisée à appeler get_the_terms() pour les cours.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class RKP_CategoryRepository {

    /**
     * Nom de la catégorie principale d'un cours (taxonomie 'course-category').
     * Retourne '' si aucune catégorie assignée.
     */
    public static function get_primary_for_course( int $course_id ): string {
        $terms = get_the_terms( $course_id, 'course-category' );
        if ( empty( $terms ) || is_wp_error( $terms ) ) return '';
        return (string) reset( $terms )->name;
    }

    /**
     * Toutes les catégories d'un cours.
     * @return string[]
     */
    public static function get_all_for_course( int $course_id ): array {
        $terms = get_the_terms( $course_id, 'course-category' );
        if ( empty( $terms ) || is_wp_error( $terms ) ) return [];
        return array_map( fn( $t ) => $t->name, $terms );
    }
}
