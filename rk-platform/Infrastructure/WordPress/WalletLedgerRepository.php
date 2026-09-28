<?php
declare( strict_types=1 );
/**
 * Infrastructure/WordPress — RKP_WalletLedgerRepository  v2.2.0 — Audit P2-17
 *
 * Ledger APPEND-ONLY des crédits de session.
 *
 * Principe : le solde n'est jamais stocké — il est la SOMME des mouvements.
 * Chaque crédit/débit est une ligne immuable avec raison + clé d'idempotence :
 *   • un solde contesté par un parent s'explique ligne par ligne ;
 *   • un même fait métier (booking #42 remboursé) ne crédite JAMAIS deux fois ;
 *   • aucun UPDATE → aucune course concurrente sur un champ balance.
 *
 * Compatibilité : à l'activation, le solde existant (wp_rk_user_credits)
 * est importé comme mouvement d'ouverture `legacy_opening_balance` avec une
 * clé stable — import rejouable sans doublon. Les systèmes historiques
 * restent intacts.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class RKP_WalletLedgerRepository implements RKP_WalletPort {

	private static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'rkp_wallet_ledger';
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$cc = $wpdb->get_charset_collate();
		dbDelta( 'CREATE TABLE ' . self::table() . " (
			id              BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id         BIGINT(20) UNSIGNED NOT NULL,
			amount          INT                 NOT NULL,
			reason          VARCHAR(80)         NOT NULL DEFAULT '',
			idempotency_key VARCHAR(120)        NOT NULL,
			meta            TEXT                NULL,
			actor_id        BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			created_at      DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY uniq_idem (idempotency_key),
			KEY idx_user (user_id),
			KEY idx_created (created_at)
		) {$cc};" );
	}

	/* ── RKP_WalletPort ─────────────────────────────────────────────── */

	public function balance( int $user_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare(
			'SELECT COALESCE(SUM(amount),0) FROM `' . self::table() . '` WHERE user_id = %d', // phpcs:ignore WordPress.DB.PreparedSQL -- table from $wpdb->prefix
			$user_id
		) );
	}

	public function credit( int $user_id, int $amount, string $reason, string $idempotency_key, array $meta = [] ): bool {
		return $this->append( $user_id, abs( $amount ), $reason, $idempotency_key, $meta );
	}

	public function debit( int $user_id, int $amount, string $reason, string $idempotency_key, array $meta = [] ): bool {
		$amount = abs( $amount );
		// Refus de découvert. Pas de course : deux débits du même fait
		// partagent la même clé d'idempotence (unicité atomique en base).
		if ( $this->balance( $user_id ) < $amount ) {
			return false;
		}
		return $this->append( $user_id, -$amount, $reason, $idempotency_key, $meta );
	}

	public function history( int $user_id, int $limit = 20 ): array {
		global $wpdb;
		return (array) $wpdb->get_results( $wpdb->prepare(
			'SELECT id, amount, reason, meta, actor_id, created_at FROM `' . self::table() . '`
			  WHERE user_id = %d ORDER BY id DESC LIMIT %d', // phpcs:ignore WordPress.DB.PreparedSQL
			$user_id, $limit
		) );
	}

	/* ── Interne ────────────────────────────────────────────────────── */

	private function append( int $user_id, int $amount, string $reason, string $idempotency_key, array $meta ): bool {
		if ( $user_id <= 0 || 0 === $amount || '' === $idempotency_key ) return false;

		global $wpdb;
		// INSERT IGNORE + clé unique = idempotence atomique.
		$wpdb->query( $wpdb->prepare(
			'INSERT IGNORE INTO `' . self::table() . '`
				(user_id, amount, reason, idempotency_key, meta, actor_id) VALUES (%d,%d,%s,%s,%s,%d)', // phpcs:ignore WordPress.DB.PreparedSQL
			$user_id,
			$amount,
			sanitize_key( $reason ),
			$idempotency_key,
			wp_json_encode( $meta ),
			get_current_user_id()
		) );
		return $wpdb->rows_affected === 1;
	}

	/* ── Import du solde historique (activation, idempotent) ─────────── */

	public static function import_legacy_balances(): int {
		global $wpdb;
		$imported = 0;

		$legacy = $wpdb->prefix . 'rk_user_credits';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $legacy ) ) === $legacy ) {
			$rows = (array) $wpdb->get_results( "SELECT user_id, balance FROM `{$legacy}` WHERE balance > 0" ); // phpcs:ignore WordPress.DB.PreparedSQL
			$repo = new self();
			foreach ( $rows as $r ) {
				if ( $repo->credit(
					(int) $r->user_id,
					(int) $r->balance,
					'legacy_opening_balance',
					'legacy_open_' . (int) $r->user_id, // clé stable → rejouable sans doublon
					[ 'source' => 'rk_user_credits' ]
				) ) {
					$imported++;
				}
			}
		}
		return $imported;
	}
}
