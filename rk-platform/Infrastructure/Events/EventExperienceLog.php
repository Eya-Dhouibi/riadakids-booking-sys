<?php
declare( strict_types=1 );
/**
 * Infrastructure/Events — RKP_EventExperienceLog
 *
 * Persistance de l'état "vu" (§21 du cahier des charges Child
 * Experience Layer) — DISTINCTE de RKP_EventLog : celle-ci suit
 * l'idempotence de TRAITEMENT serveur (éviter double XP), celle-ci
 * suit si l'ENFANT A VU le popup correspondant. Un événement peut être
 * "traité" (XP attribué) sans encore avoir été "vu" (popup pas encore
 * affiché — ex. enfant hors ligne au moment du dispatch).
 *
 * Table wp_rkp_event_experience_seen : (event_id, child_id) UNIQUE.
 *
 * @since 9.9.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;

final class RKP_EventExperienceLog {

    private static function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'rkp_event_experience_seen';
    }

    public static function install(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $cc = $wpdb->get_charset_collate();
        dbDelta( 'CREATE TABLE ' . self::table() . " (
            id          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            event_id    CHAR(36)            NOT NULL,
            child_id    BIGINT(20) UNSIGNED NOT NULL,
            event_name  VARCHAR(80)         NOT NULL DEFAULT '',
            queued_at   DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
            seen_at     DATETIME            NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_event_child (event_id, child_id),
            KEY idx_child_unseen (child_id, seen_at),
            KEY idx_created (queued_at)
        ) {$cc};" );
    }

    /**
     * Enregistre qu'un événement doit être présenté à un enfant —
     * appelé au moment du dispatch (avant que l'enfant ne le voie
     * réellement). INSERT IGNORE : un même (event_id, child_id) ne
     * peut être mis en file qu'une seule fois, même si le Domain Event
     * est redispatché (retry Action Scheduler, etc.).
     */
    public static function enqueue( string $event_id, int $child_id, string $event_name ): void {
        global $wpdb;
        $wpdb->query( $wpdb->prepare(
            'INSERT IGNORE INTO `' . self::table() . '` (event_id, child_id, event_name) VALUES (%s, %d, %s)', // phpcs:ignore WordPress.DB.PreparedSQL -- table from $wpdb->prefix
            $event_id, $child_id, $event_name
        ) );
    }

    /**
     * Tous les événements en attente (non vus) pour un enfant, du plus
     * ancien au plus récent — ordre chronologique naturel pour la
     * RKEventQueue côté client (§20).
     *
     * @return array<int, array{event_id:string, event_name:string, queued_at:string}>
     */
    public static function get_unseen( int $child_id, int $limit = 10 ): array {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            'SELECT event_id, event_name, queued_at FROM `' . self::table() . '`
              WHERE child_id = %d AND seen_at IS NULL
              ORDER BY queued_at ASC
              LIMIT %d', // phpcs:ignore WordPress.DB.PreparedSQL
            $child_id, $limit
        ), ARRAY_A );
        return $rows ?: [];
    }

    /** Marque un ou plusieurs événements comme vus (§21 — jamais réaffichés après refresh). */
    public static function mark_seen( array $event_ids, int $child_id ): void {
        if ( empty( $event_ids ) ) return;
        global $wpdb;
        $placeholders = implode( ',', array_fill( 0, count( $event_ids ), '%s' ) );
        $wpdb->query( $wpdb->prepare(
            'UPDATE `' . self::table() . "` SET seen_at = NOW()
              WHERE child_id = %d AND event_id IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL
            array_merge( [ $child_id ], $event_ids )
        ) );
    }

    /** Purge des entrées vues > 30 jours (cron quotidien, comme RKP_EventLog). */
    public static function purge_old( int $days = 30 ): void {
        global $wpdb;
        $wpdb->query( $wpdb->prepare(
            'DELETE FROM `' . self::table() . '` WHERE seen_at IS NOT NULL AND seen_at < DATE_SUB(NOW(), INTERVAL %d DAY)', // phpcs:ignore WordPress.DB.PreparedSQL
            $days
        ) );
    }
}
