<?php
declare( strict_types=1 );
/**
 * Infrastructure — UserRepository  (WordPress Users)
 *
 * Lecture des WP users dans le contexte RK.
 * Cette couche SEULE appelle get_users() / get_userdata().
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class RKP_UserRepository {

    /** Retourne le WP_User d'un enfant, ou null si inexistant. */
    public static function find_child( int $child_id ): ?\WP_User {
        $user = get_userdata( $child_id );
        return ( $user && in_array( 'rk_child', (array) $user->roles, true ) ) ? $user : null;
    }

    /** Retourne le WP_User d'un coach/instructeur, ou null si inexistant. */
    public static function find_coach( int $coach_id ): ?\WP_User {
        $user = get_userdata( $coach_id );
        return ( $user && in_array( 'tutor_instructor', (array) $user->roles, true ) ) ? $user : null;
    }

    /**
     * Retourne les IDs des enfants liés à un parent.
     * Lit le user meta 'rk_children_ids' défini par rk-my-children.
     *
     * @return int[]
     */
    public static function find_children_of_parent( int $parent_id ): array {
        $raw = get_user_meta( $parent_id, 'rk_children_ids', true );
        if ( empty( $raw ) ) return [];
        return array_map( 'intval', (array) $raw );
    }

    /**
     * Retourne les IDs WP de tous les enfants inscrits à un cours.
     * Délègue à EnrollmentRepository pour rester dans les contraintes de couche.
     *
     * @return int[]
     */
    public static function find_students_of_course( int $course_id ): array {
        $enrollments = RKP_EnrollmentRepository::find_by_course( $course_id );
        return array_map( fn( $e ) => $e->child_id, $enrollments );
    }
}
