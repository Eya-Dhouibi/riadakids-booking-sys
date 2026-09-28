<?php
/**
 * RiadaKids\Database\Migrations — v4.0 CORRIGÉ
 *
 */

namespace RiadaKids\Database;

if ( ! defined( 'ABSPATH' ) ) exit;

class Migrations {

    private static array $migrations = [
        'M001_rk_bookings_v4_columns',
        'M002_rk_pending_bookings_table',
        'M003_rk_bookings_indexes',
        'M004_drop_child_level_column',
        'M005_rk_children_profile_columns',
    ];

    public static function run(): void {
        global $wpdb;
        $ran = (array) get_option( 'rk_migrations_ran', [] );

        foreach ( self::$migrations as $key ) {
            if ( in_array( $key, $ran, true ) ) continue;

            try {
                $method = 'run_' . strtolower( $key );
                if ( method_exists( static::class, $method ) ) {
                    static::$method( $wpdb );
                    $ran[] = $key;
                    update_option( 'rk_migrations_ran', $ran );
                    rk_log( 'MIGRATION', "Migration {$key} appliquée" );
                }
            } catch ( \Throwable $e ) {
                rk_log( 'MIGRATION', "Migration {$key} échouée: " . $e->getMessage(), 'error' );
            }
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // M001 — Colonnes manquantes dans rk_bookings
    // ─────────────────────────────────────────────────────────────────────────

    private static function run_m001_rk_bookings_v4_columns( \wpdb $wpdb ): void {
        $table = $wpdb->prefix . 'rk_bookings';

        // Colonnes à ajouter si absentes
        $columns_to_add = [
            'booking_uuid'        => "VARCHAR(36) NOT NULL DEFAULT '' AFTER id",
            'course_id'           => "BIGINT(20) UNSIGNED NOT NULL DEFAULT 0 AFTER program_id",
            'session_id'          => "BIGINT(20) UNSIGNED NOT NULL DEFAULT 0 AFTER course_id",
            'children_ids'        => "TEXT NULL DEFAULT NULL AFTER child_id",
            'appointment_id'      => "BIGINT(20) UNSIGNED NOT NULL DEFAULT 0 AFTER booking_id",
            'booking_time'        => "TIME NULL DEFAULT NULL AFTER booking_date",
        ];

        $existing_columns = $wpdb->get_col( "DESCRIBE {$table}", 0 );

        foreach ( $columns_to_add as $col => $definition ) {
            if ( in_array( $col, $existing_columns, true ) ) continue;
            $wpdb->query( "ALTER TABLE {$table} ADD COLUMN {$col} {$definition}" );
            rk_log( 'MIGRATION', "M001: colonne {$col} ajoutée à {$table}" );
        }

        // Renommer appointment_type_id si besoin (colonne existante sous autre nom)
        if ( ! in_array( 'appointment_type_id', $existing_columns, true ) &&
             in_array( 'event_id', $existing_columns, true ) ) {
            $wpdb->query( "ALTER TABLE {$table} CHANGE COLUMN event_id appointment_type_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0" );
            rk_log( 'MIGRATION', "M001: event_id renommé en appointment_type_id" );
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // M002 — Table rk_pending_bookings (sauvegarde SQL en complément usermeta)
    // ─────────────────────────────────────────────────────────────────────────

    private static function run_m002_rk_pending_bookings_table( \wpdb $wpdb ): void {
        $table = $wpdb->prefix . 'rk_pending_bookings';
        $cc    = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta( "CREATE TABLE {$table} (
            id              BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id         BIGINT(20) UNSIGNED NOT NULL,
            booking_uuid    VARCHAR(36) NOT NULL DEFAULT '',
            programme_id    BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            course_id       BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            session_id      BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            children_ids    TEXT NULL DEFAULT NULL,
            credits_used    TINYINT(3) UNSIGNED NOT NULL DEFAULT 0,
            current_step    TINYINT(1) NOT NULL DEFAULT 1,
            status          VARCHAR(20) NOT NULL DEFAULT 'pending',
            booking_post_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            expires_at      DATETIME NOT NULL,
            created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uk_user_id (user_id),
            KEY idx_status (status),
            KEY idx_expires_at (expires_at)
        ) {$cc};" );

        rk_log( 'MIGRATION', "M002: table {$table} créée" );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // M003 — Index sur rk_bookings.booking_id et appointment_id
    // ─────────────────────────────────────────────────────────────────────────

    private static function run_m003_rk_bookings_indexes( \wpdb $wpdb ): void {
        $table = $wpdb->prefix . 'rk_bookings';

        // Vérifie indexes existants
        $indexes = $wpdb->get_results( "SHOW INDEX FROM {$table}", ARRAY_A );
        $keys    = array_column( $indexes, 'Key_name' );

        if ( ! in_array( 'idx_booking_id', $keys, true ) ) {
            $wpdb->query( "ALTER TABLE {$table} ADD INDEX idx_booking_id (booking_id)" );
            rk_log( 'MIGRATION', "M003: index idx_booking_id ajouté" );
        }

        if ( ! in_array( 'idx_appointment_id', $keys, true ) &&
             in_array( 'appointment_id', array_column(
                 $wpdb->get_results( "DESCRIBE {$table}", ARRAY_A ), 'Field'
             ), true ) ) {
            $wpdb->query( "ALTER TABLE {$table} ADD INDEX idx_appointment_id (appointment_id)" );
            rk_log( 'MIGRATION', "M003: index idx_appointment_id ajouté" );
        }

        if ( ! in_array( 'idx_booking_uuid', $keys, true ) ) {
            $wpdb->query( "ALTER TABLE {$table} ADD INDEX idx_booking_uuid (booking_uuid)" );
            rk_log( 'MIGRATION', "M003: index idx_booking_uuid ajouté" );
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // M004 — Suppression de la colonne child_level de rk_children
    // ─────────────────────────────────────────────────────────────────────────

    private static function run_m004_drop_child_level_column( \wpdb $wpdb ): void {
        $table   = $wpdb->prefix . 'rk_children';
        $columns = $wpdb->get_col( "DESCRIBE {$table}", 0 );

        if ( in_array( 'child_level', $columns, true ) ) {
            $wpdb->query( "ALTER TABLE {$table} DROP COLUMN child_level" );
            rk_log( 'MIGRATION', 'M004: colonne child_level supprimée de ' . $table );
        } else {
            rk_log( 'MIGRATION', 'M004: child_level absente (déjà supprimée ou install fraîche)' );
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // M005 — Colonnes de profil manquantes dans rk_children
    //        (child_family_name, child_username, avatar_url utilisées par le
    //        code PHP/JS depuis plusieurs versions mais absentes du schéma
    //        initial sur les installations créées avant leur ajout)
    // ─────────────────────────────────────────────────────────────────────────

    private static function run_m005_rk_children_profile_columns( \wpdb $wpdb ): void {
        $table = $wpdb->prefix . 'rk_children';

        $columns_to_add = [
            'child_family_name' => "VARCHAR(255) NOT NULL DEFAULT '' AFTER child_name",
            'child_username'    => "VARCHAR(60)  NOT NULL DEFAULT '' AFTER child_family_name",
            'avatar_url'        => "VARCHAR(500) NOT NULL DEFAULT '' AFTER child_age",
        ];

        $existing_columns = $wpdb->get_col( "DESCRIBE {$table}", 0 );

        foreach ( $columns_to_add as $col => $definition ) {
            if ( in_array( $col, $existing_columns, true ) ) continue;
            $wpdb->query( "ALTER TABLE {$table} ADD COLUMN {$col} {$definition}" );
            rk_log( 'MIGRATION', "M005: colonne {$col} ajoutée à {$table}" );
        }
    }
}