<?php
declare( strict_types=1 );
/**
 * Infrastructure — CoachMissionRepository
 *
 * Seule couche autorisée à lire/écrire dans wp_rk_coach_missions.
 * Table distincte de wp_rk_child_missions (missions hebdo auto).
 *
 * child_id ici = rk_children.id (legacy ID), PAS wp_users.ID.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class RKP_CoachMissionRepository {

    private static function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'rk_coach_missions';
    }

    private static function children_table(): string {
        global $wpdb;
        return $wpdb->prefix . 'rk_children';
    }

    /** Crée la table si elle n'existe pas. */
    public static function maybe_create_table(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( "CREATE TABLE " . self::table() . " (
            id           BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            child_id     BIGINT(20) UNSIGNED NOT NULL,
            coach_id     BIGINT(20) UNSIGNED NOT NULL,
            quiz_id      BIGINT(20) UNSIGNED NULL DEFAULT NULL,
            title        VARCHAR(200)        NOT NULL DEFAULT '',
            description  TEXT                NOT NULL,
            target       TINYINT(3) UNSIGNED NOT NULL DEFAULT 1,
            progress     TINYINT(3) UNSIGNED NOT NULL DEFAULT 0,
            completed    TINYINT(1)          NOT NULL DEFAULT 0,
            points       SMALLINT(5)         NOT NULL DEFAULT 10,
            due_date     DATE                NULL DEFAULT NULL,
            file_url     VARCHAR(500)        NOT NULL DEFAULT '',
            file_name    VARCHAR(255)        NOT NULL DEFAULT '',
            rewarded_at  DATETIME            NULL DEFAULT NULL,
            assigned_at  DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY child_coach_key (child_id, coach_id)
        ) " . $wpdb->get_charset_collate() . ";" );
        self::ensure_file_columns();
    }

    /** Migration lazy des colonnes file_url, file_name, quiz_id. */
    public static function ensure_file_columns(): void {
        static $done = false;
        if ( $done ) return;
        $done = true;
        global $wpdb;
        $t    = self::table();
        $cols = $wpdb->get_col( "SHOW COLUMNS FROM {$t}" );
        if ( ! in_array( 'file_url',  $cols, true ) ) {
            $wpdb->query( "ALTER TABLE {$t} ADD COLUMN file_url VARCHAR(500) NOT NULL DEFAULT '' AFTER due_date" );
        }
        if ( ! in_array( 'file_name', $cols, true ) ) {
            $wpdb->query( "ALTER TABLE {$t} ADD COLUMN file_name VARCHAR(255) NOT NULL DEFAULT '' AFTER file_url" );
        }
        if ( ! in_array( 'quiz_id', $cols, true ) ) {
            $wpdb->query( "ALTER TABLE {$t} ADD COLUMN quiz_id BIGINT(20) UNSIGNED NULL DEFAULT NULL AFTER coach_id" );
        }
    }

    /** Migration lazy de la colonne type (mission | qna). */
    public static function ensure_type_column(): void {
        static $done = false;
        if ( $done ) return;
        $done = true;
        global $wpdb;
        $t    = self::table();
        $cols = $wpdb->get_col( "SHOW COLUMNS FROM {$t}" );
        if ( ! in_array( 'type', $cols, true ) ) {
            $wpdb->query( "ALTER TABLE {$t} ADD COLUMN type VARCHAR(20) NOT NULL DEFAULT 'mission' AFTER coach_id" );
        }
    }

    /** Insère une mission. Retourne l'ID inséré (0 = échec). */
    public static function insert( array $row ): int {
        self::ensure_file_columns();
        self::ensure_type_column();
        global $wpdb;
        $ok = $wpdb->insert( self::table(), $row );
        return $ok ? (int) $wpdb->insert_id : 0;
    }

    /** Retourne une mission par son ID, ou null. */
    public static function find_by_id( int $id ): ?object {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            'SELECT * FROM ' . self::table() . ' WHERE id = %d LIMIT 1',
            $id
        ) ) ?: null;
    }

    /** Met à jour les colonnes fournies dans $set pour l'ID donné. */
    public static function update( int $id, array $set ): bool {
        if ( empty( $set ) ) return false;
        global $wpdb;
        return $wpdb->update( self::table(), $set, [ 'id' => $id ] ) !== false;
    }

    /** Supprime une mission si elle appartient bien au coach. */
    public static function delete( int $id, int $coach_id ): bool {
        if ( $id <= 0 || $coach_id <= 0 ) return false;
        global $wpdb;
        return (bool) $wpdb->delete( self::table(), [ 'id' => $id, 'coach_id' => $coach_id ] );
    }

    /**
     * Missions actives d'un coach (completed = 0), avec child_name via JOIN.
     *
     * @return object[]
     */
    public static function find_active_for_coach( int $coach_id, int $limit = 20 ): array {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT m.*, c.child_name
               FROM " . self::table() . " m
          LEFT JOIN " . self::children_table() . " c ON c.id = m.child_id
              WHERE m.coach_id = %d AND m.completed = 0
           ORDER BY m.assigned_at DESC LIMIT %d",
            $coach_id, $limit
        ) ) ?: [];
    }

    /**
     * Toutes les missions pour un coach (actives + complétées), avec child_name.
     *
     * @return object[]
     */
    public static function find_all_for_coach( int $coach_id, int $limit = 50 ): array {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT m.*, c.child_name, c.id AS rk_child_id
               FROM " . self::table() . " m
               JOIN " . self::children_table() . " c ON c.id = m.child_id
              WHERE m.coach_id = %d
           ORDER BY m.assigned_at DESC LIMIT %d",
            $coach_id, $limit
        ) ) ?: [];
    }

    /**
     * Missions actives d'un enfant (completed = 0) pour tous les coaches.
     *
     * @return array[]  Format ARRAY_A
     */
    public static function find_for_child( int $child_id, int $limit = 10 ): array {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM " . self::table() . "
              WHERE child_id = %d AND completed = 0
           ORDER BY assigned_at DESC LIMIT %d",
            $child_id, $limit
        ), ARRAY_A ) ?: [];
    }

    /**
     * Toutes missions (actives + terminées) d'un enfant pour un coach donné.
     *
     * @return object[]
     */
    public static function find_for_child_by_coach( int $child_id, int $coach_id, int $limit = 30 ): array {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM " . self::table() . "
              WHERE child_id = %d AND coach_id = %d
           ORDER BY assigned_at DESC LIMIT %d",
            $child_id, $coach_id, $limit
        ) ) ?: [];
    }

    /**
     * Questions Q&A (type='qna') assignées à un enfant donné — vue enfant.
     *
     * @return array[]  Format ARRAY_A
     */
    public static function find_qna_for_child( int $child_id, int $limit = 30 ): array {
        if ( $child_id <= 0 ) return [];
        self::ensure_type_column();
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM " . self::table() . "
              WHERE child_id = %d AND type = 'qna'
           ORDER BY assigned_at DESC LIMIT %d",
            $child_id, $limit
        ), ARRAY_A ) ?: [];
    }

    /**
     * Questions Q&A (type='qna') pour un ensemble d'enfants, avec infos enfant.
     *
     * @param  int[] $child_ids  rk_children.id[]
     * @return object[]
     */
    public static function find_qna_for_coach_children( array $child_ids, int $limit = 60 ): array {
        if ( empty( $child_ids ) ) return [];
        self::ensure_type_column();
        global $wpdb;
        $t    = self::table();
        $ct   = self::children_table();
        $ph   = implode( ',', array_fill( 0, count( $child_ids ), '%d' ) );
        $args = array_merge( $child_ids, [ $limit ] );
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT m.*, c.child_name, c.wp_user_id AS child_wp_uid
               FROM {$t} m
               JOIN {$ct} c ON c.id = m.child_id
              WHERE m.child_id IN ({$ph}) AND m.type = 'qna'
           ORDER BY m.assigned_at DESC LIMIT %d",
            ...$args
        ) ) ?: [];
    }
}
