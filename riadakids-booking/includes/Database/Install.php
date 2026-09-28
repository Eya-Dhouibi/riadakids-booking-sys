<?php
/**
 * RiadaKids\Database\Install — v26.1 BUGFIX
 *
 * FIX-C1 : UNIQUE KEY uk_booking_id remplacé par uk_booking_child (booking_id, child_id)
 *           → Permet plusieurs lignes pour le même RDV SSA (1 par enfant)
 *           → Empêche le doublon sur le même couple (RDV, enfant)
 */

namespace RiadaKids\Database;

if ( ! defined( 'ABSPATH' ) ) exit;

class Install {

    public static function run(): void {
        global $wpdb;

        ob_start();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        ob_end_clean();

        $cc = $wpdb->get_charset_collate();

        foreach ( [ 'bookings', 'children', 'credit_logs', 'points_logs',
                    'notifications', 'jobs', 'ssa_map', 'booking_quota', 'user_credits' ] as $table ) {
            try {
                ob_start();
                self::$table( $wpdb, $cc );
                ob_end_clean();
            } catch ( \Throwable $e ) {
                ob_end_clean();
                if ( function_exists( 'rk_log' ) ) {
                    \rk_log( 'INSTALL', "Erreur table {$table}: " . $e->getMessage(), 'error' );
                }
            }
        }
    }

    /* ── wp_rk_bookings ─────────────────────────────────────────── */
    private static function bookings( \wpdb $wpdb, string $cc ): void {
        /*
         * FIX-C1 : UNIQUE KEY (booking_id, child_id) au lieu de (booking_id)
         *
         * Avant : UNIQUE KEY uk_booking_id (booking_id)
         *   → 1 seule ligne autorisée par RDV SSA
         *   → La 2ème insertion (child#2) échouait silencieusement
         *   → save_booking() retournait l'id de child#1 = log trompeur
         *
         * Après : UNIQUE KEY uk_booking_child (booking_id, child_id)
         *   → N lignes autorisées par RDV SSA (1 par enfant distinct)
         *   → Doublon (même RDV + même enfant) bloqué au niveau DB
         *   → Cohérence garantie même si le verrou applicatif est bypassé
         */
        dbDelta( "CREATE TABLE {$wpdb->prefix}rk_bookings (
            id                  BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            booking_id          BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            user_id             BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            program_id          BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            course_id           BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            session_name        VARCHAR(255) NOT NULL DEFAULT '',
            child_id            BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            appointment_type_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            appointment         DATETIME NULL DEFAULT NULL,
            end_at              DATETIME NULL DEFAULT NULL,
            timezone            VARCHAR(64) NOT NULL DEFAULT '',
            coach               VARCHAR(150) NOT NULL DEFAULT '',
            booking_date        DATETIME NULL DEFAULT NULL,
            status              VARCHAR(50) NOT NULL DEFAULT 'confirmed',
            attendance          VARCHAR(10) NOT NULL DEFAULT 'unknown',
            is_refunded         TINYINT(1) NOT NULL DEFAULT 0,
            credits_used        TINYINT(3) UNSIGNED NOT NULL DEFAULT 1,
            ssa_link            VARCHAR(512) NOT NULL DEFAULT '',
            meta_source         VARCHAR(20) NOT NULL DEFAULT '',
            created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uk_booking_child      (booking_id, child_id),
            KEY idx_user_id                  (user_id),
            KEY idx_child_id                 (child_id),
            KEY idx_status                   (status),
            KEY idx_appointment              (appointment),
            KEY idx_created_at               (created_at),
            KEY idx_child_appt_status        (child_id, appointment, status),
            KEY idx_appt_type_status         (appointment_type_id, appointment, status)
        ) {$cc};" );
    }

    /* ── wp_rk_children ─────────────────────────────────────────── */
    private static function children( \wpdb $wpdb, string $cc ): void {
        dbDelta( "CREATE TABLE {$wpdb->prefix}rk_children (
            id                 BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id            BIGINT(20) UNSIGNED NOT NULL,
            child_name         VARCHAR(150) NOT NULL DEFAULT '',
            child_family_name  VARCHAR(255) NOT NULL DEFAULT '',
            child_username     VARCHAR(60)  NOT NULL DEFAULT '',
            child_age          TINYINT(3) UNSIGNED NOT NULL DEFAULT 0,
            avatar_url         VARCHAR(500) NOT NULL DEFAULT '',
            created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_user_id (user_id)
        ) {$cc};" );
    }

    /* ── wp_rk_credit_logs ──────────────────────────────────────── */
    private static function credit_logs( \wpdb $wpdb, string $cc ): void {
        dbDelta( "CREATE TABLE {$wpdb->prefix}rk_credit_logs (
            id            BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id       BIGINT(20) UNSIGNED NOT NULL,
            action_type   VARCHAR(100) NOT NULL DEFAULT '',
            amount        INT(11) NOT NULL DEFAULT 0,
            balance_after INT(11) NOT NULL DEFAULT 0,
            details       LONGTEXT NULL,
            created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_user_id    (user_id),
            KEY idx_action_type (action_type),
            KEY idx_created_at (created_at)
        ) {$cc};" );
    }

    /* ── wp_rk_points_logs ──────────────────────────────────────── */
    private static function points_logs( \wpdb $wpdb, string $cc ): void {
        dbDelta( "CREATE TABLE {$wpdb->prefix}rk_points_logs (
            id          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id     BIGINT(20) UNSIGNED NOT NULL,
            points      INT(11) NOT NULL DEFAULT 0,
            action_type VARCHAR(100) NOT NULL DEFAULT '',
            description LONGTEXT NULL,
            created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_user_id    (user_id),
            KEY idx_action_type (action_type),
            KEY idx_created_at (created_at)
        ) {$cc};" );
    }

    /* ── wp_rk_notifications ────────────────────────────────────── */
    private static function notifications( \wpdb $wpdb, string $cc ): void {
        dbDelta( "CREATE TABLE {$wpdb->prefix}rk_notifications (
            id         BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id    BIGINT(20) UNSIGNED NOT NULL,
            type       VARCHAR(50) NOT NULL DEFAULT '',
            message    TEXT NOT NULL,
            is_read    TINYINT(1) NOT NULL DEFAULT 0,
            meta       LONGTEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_user_id (user_id),
            KEY idx_is_read (is_read)
        ) {$cc};" );
    }

    /* ── wp_rk_jobs ─────────────────────────────────────────────── */
    private static function jobs( \wpdb $wpdb, string $cc ): void {
        dbDelta( "CREATE TABLE {$wpdb->prefix}rk_jobs (
            id           BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            job_type     VARCHAR(50) NOT NULL DEFAULT 'email',
            payload      LONGTEXT NOT NULL,
            status       VARCHAR(20) NOT NULL DEFAULT 'pending',
            attempts     TINYINT(3) UNSIGNED NOT NULL DEFAULT 0,
            max_attempts TINYINT(3) UNSIGNED NOT NULL DEFAULT 3,
            scheduled_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            processed_at DATETIME NULL DEFAULT NULL,
            error_msg    LONGTEXT NULL,
            created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_status   (status, scheduled_at),
            KEY idx_job_type (job_type)
        ) {$cc};" );
    }

    /* ── wp_rk_ssa_map ──────────────────────────────────────────── */
    private static function ssa_map( \wpdb $wpdb, string $cc ): void {
        dbDelta( "CREATE TABLE {$wpdb->prefix}rk_ssa_map (
            id                  BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            course_id           BIGINT(20) UNSIGNED NOT NULL,
            program_id          BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            appointment_type_id BIGINT(20) UNSIGNED NOT NULL,
            label               VARCHAR(255) NOT NULL DEFAULT '',
            is_active           TINYINT(1) NOT NULL DEFAULT 1,
            created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uk_course_type (course_id, appointment_type_id),
            KEY idx_appointment_type (appointment_type_id)
        ) {$cc};" );
    }

    /* ── wp_rk_booking_quota ────────────────────────────────────── */
    private static function booking_quota( \wpdb $wpdb, string $cc ): void {
        dbDelta( "CREATE TABLE {$wpdb->prefix}rk_booking_quota (
            id           BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            child_id     BIGINT(20) UNSIGNED NOT NULL,
            session_name VARCHAR(255) NOT NULL DEFAULT '',
            appointment  DATETIME NOT NULL,
            booking_id   BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uk_child_session_appt (child_id, session_name(100), appointment)
        ) {$cc};" );
    }

    /* ── wp_rk_user_credits ─────────────────────────────────────── */
    private static function user_credits( \wpdb $wpdb, string $cc ): void {
        dbDelta( "CREATE TABLE {$wpdb->prefix}rk_user_credits (
            id         BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id    BIGINT(20) UNSIGNED NOT NULL,
            balance    INT(11) NOT NULL DEFAULT 0,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uk_user_id (user_id)
        ) {$cc};" );
    }
}