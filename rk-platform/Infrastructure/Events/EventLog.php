<?php
declare( strict_types=1 );
/**
 * Infrastructure/Events — RKP_EventLog  (v2.1.0 — Audit P1-8)
 *
 * Idempotence des Domain Events.
 *
 * Table wp_rkp_event_log : (event_id, subscriber) UNIQUE.
 * Un subscriber marqué "traité" pour un event_id donné n'est jamais rejoué :
 * plus de double XP / double badge en cas de double hook Tutor, retry
 * navigateur ou rejeu de l'outbox.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class RKP_EventLog {

	private static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'rkp_event_log';
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$cc = $wpdb->get_charset_collate();
		dbDelta( 'CREATE TABLE ' . self::table() . " (
			id          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			event_id    CHAR(36)            NOT NULL,
			event_name  VARCHAR(80)         NOT NULL DEFAULT '',
			subscriber  VARCHAR(160)        NOT NULL DEFAULT '',
			status      VARCHAR(12)         NOT NULL DEFAULT 'done',
			error       TEXT                NULL,
			created_at  DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY uniq_event_sub (event_id, subscriber),
			KEY idx_name (event_name),
			KEY idx_created (created_at)
		) {$cc};" );
	}

	/**
	 * Réserve l'exécution (event_id, subscriber).
	 * @return bool true si le subscriber peut s'exécuter (première fois),
	 *              false s'il a déjà traité cet événement (idempotence).
	 */
	public static function claim( string $event_id, string $subscriber, string $event_name ): bool {
		global $wpdb;
		// INSERT IGNORE : la clé unique fait office de verrou atomique.
		$wpdb->query( $wpdb->prepare(
			'INSERT IGNORE INTO `' . self::table() . '` (event_id, event_name, subscriber) VALUES (%s, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL -- table from $wpdb->prefix
			$event_id, $event_name, $subscriber
		) );
		return $wpdb->rows_affected === 1;
	}

	/** Marque un échec (le claim est libéré → retry possible). */
	public static function release_failed( string $event_id, string $subscriber, string $error ): void {
		global $wpdb;
		$wpdb->delete( self::table(), [ 'event_id' => $event_id, 'subscriber' => $subscriber ] );
		$wpdb->insert( self::table(), [
			'event_id'   => $event_id . '#fail#' . wp_generate_password( 6, false ),
			'event_name' => 'failure',
			'subscriber' => $subscriber,
			'status'     => 'failed',
			'error'      => mb_substr( $error, 0, 5000 ),
		] );
	}

	/** Purge des entrées > 90 jours (à brancher sur un cron quotidien). */
	public static function purge_old( int $days = 90 ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare(
			'DELETE FROM `' . self::table() . '` WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)', // phpcs:ignore WordPress.DB.PreparedSQL
			$days
		) );
	}
}
