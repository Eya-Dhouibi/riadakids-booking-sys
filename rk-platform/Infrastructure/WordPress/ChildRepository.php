<?php
declare( strict_types=1 );
/**
 * Infrastructure — ChildRepository
 *
 * Seule couche autorisée à lire wp_rk_children pour des lookups d'ID.
 * Les CRUD complets sur rk_children restent dans rk-my-children (RK_MC_Child_Repository).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class RKP_ChildRepository {

    private static function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'rk_children';
    }

    /** Retourne rk_children.id pour un wp_users.ID donné (0 si introuvable). */
    public static function get_id_by_wp_user( int $wp_user_id ): int {
        if ( $wp_user_id <= 0 ) return 0;
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            'SELECT id FROM ' . self::table() . ' WHERE wp_user_id = %d LIMIT 1',
            $wp_user_id
        ) );
    }

    /** Retourne wp_users.ID du compte enfant pour un rk_children.id (0 si introuvable). */
    public static function get_wp_user_id( int $child_rk_id ): int {
        if ( $child_rk_id <= 0 ) return 0;
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            'SELECT wp_user_id FROM ' . self::table() . ' WHERE id = %d LIMIT 1',
            $child_rk_id
        ) );
    }

    /** Retourne wp_users.ID du parent (user_id) pour un rk_children.id (0 si introuvable). */
    public static function get_parent_user_id( int $child_rk_id ): int {
        if ( $child_rk_id <= 0 ) return 0;
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            'SELECT user_id FROM ' . self::table() . ' WHERE id = %d LIMIT 1',
            $child_rk_id
        ) );
    }
}
