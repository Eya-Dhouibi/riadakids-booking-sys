<?php
declare( strict_types=1 );
/**
 * Infrastructure — BadgeRepository
 *
 * Seule couche autorisée à lire/écrire dans wp_rk_child_badges.
 *
 * Note : child_id ici = rk_children.id (legacy ID), PAS wp_users.ID.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class RKP_BadgeRepository {

    private static function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'rk_child_badges';
    }

    private static function table_exists(): bool {
        global $wpdb;
        static $exists = null;
        if ( $exists === null ) {
            $t      = self::table();
            $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) === $t;
        }
        return $exists;
    }

    /** Vérifie si un enfant possède déjà un badge (idempotence). */
    public static function has_badge( int $child_rk_id, string $badge_key ): bool {
        if ( ! self::table_exists() ) return false;
        global $wpdb;
        return (bool) $wpdb->get_var( $wpdb->prepare(
            'SELECT id FROM ' . self::table() . ' WHERE child_id = %d AND badge_key = %s LIMIT 1',
            $child_rk_id, $badge_key
        ) );
    }

    /** Insère un badge. Retourne true si succès. */
    public static function insert( int $child_rk_id, string $badge_key, string $note = '' ): bool {
        if ( ! self::table_exists() ) return false;
        global $wpdb;
        return (bool) $wpdb->insert( self::table(), [
            'child_id'  => $child_rk_id,
            'badge_key' => $badge_key,
            'earned_at' => current_time( 'mysql' ),
            'note'      => $note,
        ] );
    }

    /** Supprime un badge (usage admin). */
    public static function delete( int $child_rk_id, string $badge_key ): bool {
        if ( ! self::table_exists() ) return false;
        global $wpdb;
        return (bool) $wpdb->delete( self::table(), [
            'child_id'  => $child_rk_id,
            'badge_key' => $badge_key,
        ] );
    }

    /** Nombre de badges gagnés ce mois-ci. */
    /**
     * Nombre d'enfants ayant actuellement ce badge (v9.59).
     *
     * Manquant jusqu'ici : delete_custom() supprimait les attributions
     * (rk_child_badges) mais ne pouvait pas informer le frontend de leur
     * nombre AVANT suppression, ce qui empêchait l'avertissement demandé
     * ("Ce badge est attribué à X enfant(s)").
     */
    public static function count_children_with_badge( string $badge_key ): int {
        if ( ! self::table_exists() ) return 0;
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            'SELECT COUNT(DISTINCT child_id) FROM ' . self::table() . ' WHERE badge_key = %s',
            $badge_key
        ) );
    }

    public static function count_this_month( int $child_rk_id ): int {
        if ( ! self::table_exists() ) return 0;
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            'SELECT COUNT(*) FROM ' . self::table() .
            ' WHERE child_id = %d AND YEAR(earned_at)=YEAR(NOW()) AND MONTH(earned_at)=MONTH(NOW())',
            $child_rk_id
        ) );
    }

    /** Tous les badges d'un enfant, du plus récent au plus ancien. */
    public static function find_all( int $child_rk_id ): array {
        if ( ! self::table_exists() ) return [];
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            'SELECT * FROM ' . self::table() . ' WHERE child_id = %d ORDER BY earned_at DESC',
            $child_rk_id
        ) ) ?: [];
    }

    /**
     * Marque un badge comme vu par l'enfant. Idempotente : WHERE
     * seen_at IS NULL garantit qu'un second appel ne modifie plus rien.
     * Strictement scopée à (child_id, badge_key).
     */
    public static function mark_as_seen( int $child_rk_id, string $badge_key ): bool {
        if ( ! self::table_exists() ) return false;
        global $wpdb;
        $wpdb->query( $wpdb->prepare(
            'UPDATE ' . self::table() . ' SET seen_at = %s WHERE child_id = %d AND badge_key = %s AND seen_at IS NULL',
            current_time( 'mysql' ), $child_rk_id, $badge_key
        ) );
        return (int) $wpdb->rows_affected > 0;
    }
}
