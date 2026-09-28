<?php
declare( strict_types=1 );
/**
 * Infrastructure — ProgressSnapshotRepository  (v3.0.0 — nouveau)
 *
 * Persiste la progression Tutor LMS par (enfant, cours) dans une table
 * plateforme : wp_rk_progress_snapshots.
 *
 * POURQUOI :
 *   • Les vues parent / coach / enfant recalculaient la progression en LIVE
 *     via tutor_utils() → N+1 requêtes par roster, aucun historique,
 *     et une progression qui "disparaît" si Tutor est désactivé.
 *   • Un snapshot écrit à CHAQUE événement pédagogique (leçon, quiz, cours,
 *     reset) donne : lecture O(1) pour les rosters, source pour les rapports
 *     PDF, tri "progress" du roster coach fiable, et résilience si Tutor
 *     est momentanément indisponible.
 *
 * MODÈLE : upsert par (child_wp_uid, course_id). L'historique fin reste
 * dans wp_rkp_event_log ; cette table est l'état COURANT dénormalisé.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class RKP_ProgressSnapshotRepository {

	private static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'rk_progress_snapshots';
	}

	/** Création idempotente (dbDelta) — appeler à l'activation et en upgrade. */
	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( 'CREATE TABLE ' . self::table() . " (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			child_wp_uid BIGINT(20) UNSIGNED NOT NULL,
			course_id BIGINT(20) UNSIGNED NOT NULL,
			percent TINYINT UNSIGNED NOT NULL DEFAULT 0,
			lessons_done SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			lessons_total SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			quizzes_passed SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			last_lesson_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			last_activity DATETIME NULL DEFAULT NULL,
			completed_at DATETIME NULL DEFAULT NULL,
			source VARCHAR(32) NOT NULL DEFAULT 'tutor',
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY uniq_child_course (child_wp_uid, course_id),
			KEY idx_child (child_wp_uid),
			KEY idx_course (course_id),
			KEY idx_activity (last_activity)
		) " . $wpdb->get_charset_collate() . ';' );
	}

	/** Upsert de l'état courant d'un couple (enfant, cours). */
	public static function upsert( int $child_wp_uid, int $course_id, array $data ): bool {
		if ( $child_wp_uid <= 0 || $course_id <= 0 ) return false;
		global $wpdb;

		$now = current_time( 'mysql' );
		$row = [
			'child_wp_uid'   => $child_wp_uid,
			'course_id'      => $course_id,
			'percent'        => max( 0, min( 100, (int) ( $data['percent'] ?? 0 ) ) ),
			'lessons_done'   => max( 0, (int) ( $data['lessons_done'] ?? 0 ) ),
			'lessons_total'  => max( 0, (int) ( $data['lessons_total'] ?? 0 ) ),
			'quizzes_passed' => max( 0, (int) ( $data['quizzes_passed'] ?? 0 ) ),
			'last_lesson_id' => max( 0, (int) ( $data['last_lesson_id'] ?? 0 ) ),
			'last_activity'  => $data['last_activity'] ?? $now,
			'completed_at'   => $data['completed_at'] ?? null,
			'source'         => sanitize_key( (string) ( $data['source'] ?? 'tutor' ) ),
			'updated_at'     => $now,
		];

		// phpcs:ignore WordPress.DB.PreparedSQL -- table interne, valeurs préparées
		$sql = $wpdb->prepare(
			'INSERT INTO `' . self::table() . '`
				(child_wp_uid, course_id, percent, lessons_done, lessons_total,
				 quizzes_passed, last_lesson_id, last_activity, completed_at, source, updated_at)
			 VALUES (%d,%d,%d,%d,%d,%d,%d,%s,%s,%s,%s)
			 ON DUPLICATE KEY UPDATE
				percent = VALUES(percent),
				lessons_done = VALUES(lessons_done),
				lessons_total = VALUES(lessons_total),
				quizzes_passed = VALUES(quizzes_passed),
				last_lesson_id = IF(VALUES(last_lesson_id) > 0, VALUES(last_lesson_id), last_lesson_id),
				last_activity = VALUES(last_activity),
				completed_at = VALUES(completed_at),
				source = VALUES(source),
				updated_at = VALUES(updated_at)',
			$row['child_wp_uid'], $row['course_id'], $row['percent'],
			$row['lessons_done'], $row['lessons_total'], $row['quizzes_passed'],
			$row['last_lesson_id'], $row['last_activity'],
			$row['completed_at'] ?? '0000-00-00 00:00:00',
			$row['source'], $row['updated_at']
		);
		// NULL propre pour completed_at
		if ( null === $row['completed_at'] ) {
			$sql = str_replace( "'0000-00-00 00:00:00'", 'NULL', $sql );
		}
		return false !== $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/** État courant d'un couple (enfant, cours), ou null. */
	public static function find( int $child_wp_uid, int $course_id ): ?object {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM `' . self::table() . '` WHERE child_wp_uid = %d AND course_id = %d', // phpcs:ignore WordPress.DB.PreparedSQL
			$child_wp_uid, $course_id
		) );
		return $row ?: null;
	}

	/** Tous les snapshots d'un enfant (pour le board parent / fiche coach). */
	public static function find_by_child( int $child_wp_uid ): array {
		global $wpdb;
		return (array) $wpdb->get_results( $wpdb->prepare(
			'SELECT * FROM `' . self::table() . '` WHERE child_wp_uid = %d ORDER BY last_activity DESC', // phpcs:ignore WordPress.DB.PreparedSQL
			$child_wp_uid
		) );
	}

	/**
	 * Progression moyenne par enfant pour un LOT d'enfants — UNE requête.
	 * C'est ce qui remplace le N+1 du roster coach (tri "progress").
	 *
	 * @param  int[] $child_wp_uids
	 * @return array<int,array{avg_percent:int,active_courses:int,last_activity:?string}>
	 */
	public static function aggregate_for_children( array $child_wp_uids ): array {
		$ids = array_values( array_filter( array_map( 'intval', $child_wp_uids ) ) );
		if ( empty( $ids ) ) return [];
		global $wpdb;

		$ph   = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$rows = (array) $wpdb->get_results( $wpdb->prepare(
			'SELECT child_wp_uid,
			        ROUND(AVG(percent)) AS avg_percent,
			        SUM(completed_at IS NULL AND percent > 0) AS active_courses,
			        MAX(last_activity) AS last_activity
			   FROM `' . self::table() . "`
			  WHERE child_wp_uid IN ({$ph})
			  GROUP BY child_wp_uid", // phpcs:ignore WordPress.DB.PreparedSQL
			...$ids
		) );

		$out = [];
		foreach ( $rows as $r ) {
			$out[ (int) $r->child_wp_uid ] = [
				'avg_percent'    => (int) $r->avg_percent,
				'active_courses' => (int) $r->active_courses,
				'last_activity'  => $r->last_activity,
			];
		}
		return $out;
	}

	/** Purge des snapshots d'un enfant (désactivation / RGPD). */
	public static function delete_for_child( int $child_wp_uid ): void {
		global $wpdb;
		$wpdb->delete( self::table(), [ 'child_wp_uid' => $child_wp_uid ], [ '%d' ] );
	}
}
