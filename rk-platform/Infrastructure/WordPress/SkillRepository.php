<?php
declare( strict_types=1 );
/**
 * Infrastructure — SkillRepository
 *
 * Seule couche autorisée à lire/écrire dans wp_rk_child_skills.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class RKP_SkillRepository {

    private static function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'rk_child_skills';
    }

    public static function maybe_create_table(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( "CREATE TABLE " . self::table() . " (
            id        BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            child_id  BIGINT(20) UNSIGNED NOT NULL,
            skill_key VARCHAR(50)         NOT NULL DEFAULT '',
            level     TINYINT(3) UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY child_skill (child_id, skill_key)
        ) " . $wpdb->get_charset_collate() . ";" );
    }

    /**
     * Retourne les niveaux bruts d'un enfant, indexés par skill_key.
     *
     * @return array<string, int>  ['speech' => 3, 'teamwork' => 7, …]
     */
    public static function get_skills( int $child_id ): array {
        if ( $child_id <= 0 ) return [];
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            'SELECT skill_key, level FROM ' . self::table() . ' WHERE child_id = %d',
            $child_id
        ) ) ?: [];
        return array_column( $rows, 'level', 'skill_key' );
    }

    /**
     * Insère ou met à jour le niveau d'une compétence.
     * Retourne true si la requête a réussi.
     */
    public static function set_skill( int $child_id, string $skill_key, int $level ): bool {
        if ( $child_id <= 0 || $skill_key === '' ) return false;
        global $wpdb;
        $ok = $wpdb->query( $wpdb->prepare(
            'INSERT INTO ' . self::table() . ' (child_id, skill_key, level)
             VALUES (%d, %s, %d)
             ON DUPLICATE KEY UPDATE level = VALUES(level)',
            $child_id, $skill_key, $level
        ) ) !== false;
        if ( $ok ) {
            do_action( 'rk_mc_skill_updated', $child_id );
        }
        return $ok;
    }
}
