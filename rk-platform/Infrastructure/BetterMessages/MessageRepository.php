<?php
declare( strict_types=1 );
/**
 * Infrastructure/BetterMessages — RKP_MessageRepository  (v2.1.0 — Audit P1-5)
 *
 * Adapter Better Messages. SEUL point de contact avec le schéma interne
 * de Better Messages (wp_bp_messages_messages).
 *
 * AVANT : CoachDataQueryService (Application) requêtait la table directement
 *         — une MAJ de Better Messages pouvait casser silencieusement.
 * APRÈS : tout passe ici ; détection de table mémorisée en option ; si le
 *         schéma change, un seul fichier à adapter, et l'échec est loggé
 *         au lieu d'être silencieux.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class RKP_MessageRepository {

	private const EXISTS_OPT = 'rkp_tbl_bm_messages_exists';

	public static function is_available(): bool {
		if ( ! class_exists( 'Better_Messages' ) ) return false;

		$cached = get_option( self::EXISTS_OPT, null );
		if ( null !== $cached ) return (bool) $cached;

		global $wpdb;
		$t      = $wpdb->prefix . 'bp_messages_messages';
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) === $t;
		update_option( self::EXISTS_OPT, $exists ? 1 : 0, false );
		return $exists;
	}

	public static function refresh_table_cache(): void {
		delete_option( self::EXISTS_OPT );
	}

	/** Nombre de messages envoyés par un utilisateur sur une période. */
	public static function count_sent_between( int $sender_id, string $start, string $end ): int {
		if ( ! self::is_available() ) return 0;

		global $wpdb;
		$t = $wpdb->prefix . 'bp_messages_messages';

		$count = $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM `{$t}` WHERE sender_id = %d AND date_sent BETWEEN %s AND %s", // phpcs:ignore WordPress.DB.PreparedSQL -- table identifier from $wpdb->prefix
			$sender_id, $start, $end
		) );

		if ( null === $count && ! empty( $wpdb->last_error ) ) {
			// Le schéma BM a probablement changé — signal au lieu d'échec silencieux.
			rkp_log( '[RKP_MessageRepository] Schéma Better Messages inattendu : ' . $wpdb->last_error );
			update_option( self::EXISTS_OPT, 0, false );
			return 0;
		}
		return (int) $count;
	}
}
