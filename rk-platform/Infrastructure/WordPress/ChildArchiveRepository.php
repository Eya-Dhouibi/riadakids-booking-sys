<?php
declare( strict_types=1 );
/**
 * Infrastructure/WordPress — RKP_ChildArchiveRepository  v2.2.0 — Audit P3-19
 *
 * "Soft delete" par ARCHIVAGE (et non par colonne deleted_at).
 *
 * Pourquoi ce choix : des dizaines de requêtes à travers Modules/ lisent
 * wp_rk_children directement — une colonne deleted_at exigerait de modifier
 * CHACUNE (fort risque de fuite d'enfants "supprimés" dans les listes).
 * L'archivage garde toutes les lectures existantes intactes :
 *
 *   suppression → la ligne est COPIÉE dans wp_rk_children_archive
 *                 (avec son usermeta clé, qui l'a supprimée, quand)
 *               → puis supprimée de la table vivante (comportement inchangé)
 *   restauration possible pendant RETENTION_DAYS (30 j)
 *   purge définitive automatique ensuite (cron quotidien) — conforme RGPD
 *   (minimisation) tout en protégeant contre la suppression accidentelle.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class RKP_ChildArchiveRepository {

	const RETENTION_DAYS = 30;
	const CRON_HOOK      = 'rkp_purge_child_archive';

	private static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'rk_children_archive';
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$cc = $wpdb->get_charset_collate();
		dbDelta( 'CREATE TABLE ' . self::table() . " (
			id          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			child_id    BIGINT(20) UNSIGNED NOT NULL,
			user_id     BIGINT(20) UNSIGNED NOT NULL,
			row_json    LONGTEXT            NOT NULL,
			meta_json   LONGTEXT            NULL,
			deleted_by  BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			deleted_at  DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY idx_child (child_id),
			KEY idx_parent (user_id),
			KEY idx_deleted (deleted_at)
		) {$cc};" );

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	public static function boot(): void {
		add_action( self::CRON_HOOK, [ self::class, 'purge_expired' ] );
	}

	/**
	 * Photographie l'enfant (ligne + usermeta essentiel du wp_user lié)
	 * AVANT sa suppression. Appelé par RK_MC_Child_Repository::delete().
	 */
	public static function archive( int $child_id, int $parent_user_id ): bool {
		global $wpdb;
		$children = $wpdb->prefix . 'rk_children';

		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM `{$children}` WHERE id = %d AND user_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL -- table from $wpdb->prefix
			$child_id, $parent_user_id
		), ARRAY_A );

		if ( ! $row ) return false;

		$meta = [];
		if ( ! empty( $row['wp_user_id'] ) ) {
			$wp_uid = (int) $row['wp_user_id'];
			$meta   = [
				'wp_user_login' => ( get_userdata( $wp_uid )->user_login ?? '' ),
				'pin_stored'    => (string) get_user_meta( $wp_uid, '_rk_child_pin', true ), // déjà chiffré (v2.1)
			];
		}

		return false !== $wpdb->insert( self::table(), [
			'child_id'   => $child_id,
			'user_id'    => $parent_user_id,
			'row_json'   => wp_json_encode( $row ),
			'meta_json'  => wp_json_encode( $meta ),
			'deleted_by' => get_current_user_id(),
		] );
	}

	/** Restaure un enfant archivé dans la table vivante. */
	public static function restore( int $archive_id, int $parent_user_id ): bool {
		global $wpdb;

		$entry = $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM `' . self::table() . '` WHERE id = %d AND user_id = %d', // phpcs:ignore WordPress.DB.PreparedSQL
			$archive_id, $parent_user_id
		) );
		if ( ! $entry ) return false;

		$row = json_decode( (string) $entry->row_json, true );
		if ( ! is_array( $row ) ) return false;

		unset( $row['id'] ); // nouvel id auto — l'ancien wp_user a pu être supprimé
		$children = $wpdb->prefix . 'rk_children';
		$ok       = false !== $wpdb->insert( $children, $row );

		if ( $ok ) {
			$wpdb->delete( self::table(), [ 'id' => $archive_id ] );
			do_action( 'rkp_child_restored', (int) $wpdb->insert_id, $parent_user_id );
		}
		return $ok;
	}

	/** @return object[] Archives restaurables d'un parent. */
	public static function list_for_parent( int $parent_user_id ): array {
		global $wpdb;
		return (array) $wpdb->get_results( $wpdb->prepare(
			'SELECT id, child_id, row_json, deleted_at FROM `' . self::table() . '`
			  WHERE user_id = %d ORDER BY deleted_at DESC', // phpcs:ignore WordPress.DB.PreparedSQL
			$parent_user_id
		) );
	}

	/** Purge définitive des archives expirées (cron quotidien). */
	public static function purge_expired(): int {
		global $wpdb;
		$wpdb->query( $wpdb->prepare(
			'DELETE FROM `' . self::table() . '` WHERE deleted_at < DATE_SUB(NOW(), INTERVAL %d DAY)', // phpcs:ignore WordPress.DB.PreparedSQL
			self::RETENTION_DAYS
		) );
		return (int) $wpdb->rows_affected;
	}
}
