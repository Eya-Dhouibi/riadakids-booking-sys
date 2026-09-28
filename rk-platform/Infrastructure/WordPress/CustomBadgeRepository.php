<?php
declare( strict_types=1 );
/**
 * Infrastructure — CustomBadgeRepository  (v2.1.0 — Audit P1-4)
 *
 * Encapsule la table wp_rk_custom_badges.
 * AVANT : BadgeQueryService (couche Application) faisait `global $wpdb`
 *         + SHOW TABLES + SELECT * non préparé — violation de couche.
 * APRÈS : tout accès passe ici ; l'existence de la table est détectée
 *         UNE FOIS puis mémorisée en option (plus de SHOW TABLES à chaud).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class RKP_CustomBadgeRepository {

	private const EXISTS_OPT = 'rkp_tbl_custom_badges_exists';

	private static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'rk_custom_badges';
	}

	public static function table_exists(): bool {
		$cached = get_option( self::EXISTS_OPT, null );
		if ( null !== $cached ) return (bool) $cached;

		global $wpdb;
		$t      = self::table();
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) === $t;
		update_option( self::EXISTS_OPT, $exists ? 1 : 0, false );
		return $exists;
	}

	/** Force une re-détection (à appeler après création de table). */
	public static function refresh_table_cache(): void {
		delete_option( self::EXISTS_OPT );
	}

	/** @return object[] Tous les badges custom, ordonnés par id. */
	public static function find_all(): array {
		if ( ! self::table_exists() ) return [];
		global $wpdb;
		// Identifiant de table non paramétrable par l'utilisateur (préfixe WP),
		// backticks + provenance $wpdb->prefix : sûr. Colonnes explicites > SELECT *.
		$sql = 'SELECT * FROM `' . self::table() . '` ORDER BY id ASC';
		return (array) $wpdb->get_results( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL -- table identifier only
	}
}
