<?php
declare( strict_types=1 );
/**
 * RKP_RefreshTokenRepository — stockage des refresh tokens de l'app mobile.
 *
 * ┌──────────────────────────────────────────────────────────────────────────┐
 * │  PHASE 1 — Fondations API & Auth mobile                                  │
 * │                                                                          │
 * │  Distinct de RKP_ChildSessionRepository (sessions web enfant / cookie). │
 * │  Ce repository porte les refresh tokens émis à un CLIENT MOBILE          │
 * │  (parent ou enfant) après /rk/v1/auth/login. L'access token (JWT, courte│
 * │  durée) n'est JAMAIS stocké côté serveur — seul le refresh token l'est,  │
 * │  ce qui permet la révocation réelle (déconnexion, appareil perdu).       │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * ⚠️ Le refresh token BRUT n'est jamais écrit en base. Seul son SHA-256 y figure.
 *
 * @package RK_Platform
 * @since   4.19.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class RKP_RefreshTokenRepository {

	/** Durée de vie d'un refresh token : 30 jours. */
	public const TTL = 30 * DAY_IN_SECONDS;

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'rk_refresh_tokens';
	}

	/** SHA-256 du token brut — la seule forme qui touche la base. */
	public static function hash( string $token ): string {
		return hash( 'sha256', $token );
	}

	/* ══════════════════════════════════════════════════════════════════
	 * Disponibilité de la table — FAIL CLOSED (même règle que P1-1 sessions)
	 * ══════════════════════════════════════════════════════════════════ */

	/** @var bool|null Mémo par requête. */
	private static ?bool $table_exists = null;

	public static function is_available(): bool {
		if ( null !== self::$table_exists ) return self::$table_exists;

		global $wpdb;
		$table = self::table();

		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		self::$table_exists = ( $found === $table );

		if ( ! self::$table_exists && function_exists( 'rkp_log' ) ) {
			rkp_log( '[RK Mobile Auth] Table ' . $table . ' absente : refresh tokens indisponibles (migration non encore exécutée ?)' );
		}

		return self::$table_exists;
	}

	/** Usage tests uniquement. */
	public static function reset_availability(): void {
		self::$table_exists = null;
	}

	private static function log_db_error( string $context ): void {
		global $wpdb;
		if ( ! empty( $wpdb->last_error ) && function_exists( 'rkp_log' ) ) {
			rkp_log( '[RK Mobile Auth] ' . $context . ' : ' . $wpdb->last_error );
		}
	}

	/* ══════════════════════════════════════════════════════════════════
	 * Schéma
	 * ══════════════════════════════════════════════════════════════════ */

	/**
	 * Création idempotente (dbDelta). Appelée depuis rkp_run_schema_migrations().
	 */
	public static function maybe_create_table(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$collate = $wpdb->get_charset_collate();

		dbDelta( "CREATE TABLE {$table} (
			id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			token_hash    CHAR(64)        NOT NULL,
			user_id       BIGINT UNSIGNED NOT NULL,
			device_id     VARCHAR(191)    NOT NULL DEFAULT '',
			created_at    DATETIME        NOT NULL,
			expires_at    DATETIME        NOT NULL,
			last_used_at  DATETIME        DEFAULT NULL,
			revoked_at    DATETIME        DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY token_hash (token_hash),
			KEY user_revoked (user_id, revoked_at),
			KEY expires_at (expires_at)
		) {$collate};" );

		self::$table_exists = null;
	}

	/* ══════════════════════════════════════════════════════════════════
	 * Écriture
	 * ══════════════════════════════════════════════════════════════════ */

	/**
	 * Émet un nouveau refresh token pour un utilisateur/appareil.
	 * Les sessions multi-appareils sont autorisées (même politique que le web enfant).
	 *
	 * @return string Token BRUT (64 hex), ou '' en cas d'échec.
	 */
	public static function issue( int $user_id, string $device_id = '' ): string {
		global $wpdb;
		if ( $user_id <= 0 ) return '';
		if ( ! self::is_available() ) return '';   // fail closed

		try {
			$token = bin2hex( random_bytes( 32 ) );   // 32 octets → 64 hex
		} catch ( \Exception $e ) {
			return '';
		}

		$now = time();
		$ok  = $wpdb->insert(
			self::table(),
			array(
				'token_hash'   => self::hash( $token ),
				'user_id'      => $user_id,
				'device_id'    => sanitize_text_field( $device_id ),
				'created_at'   => gmdate( 'Y-m-d H:i:s', $now ),
				'expires_at'   => gmdate( 'Y-m-d H:i:s', $now + self::TTL ),
				'last_used_at' => gmdate( 'Y-m-d H:i:s', $now ),
			),
			array( '%s', '%d', '%s', '%s', '%s', '%s' )
		);

		if ( ! $ok ) {
			self::log_db_error( 'issue() INSERT échoué' );
			return '';
		}
		return $token;
	}

	/* ══════════════════════════════════════════════════════════════════
	 * Lecture
	 * ══════════════════════════════════════════════════════════════════ */

	/**
	 * Session active correspondant au refresh token, ou null.
	 * Aucun repli — un token invalide/révoqué/expiré ne donne jamais accès.
	 */
	public static function find( string $token ): ?object {
		global $wpdb;
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $token ) ) return null;
		if ( ! self::is_available() ) return null;

		$row = $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM ' . self::table() . '
			  WHERE token_hash = %s
			    AND revoked_at IS NULL
			    AND expires_at > %s
			  LIMIT 1',
			self::hash( $token ),
			gmdate( 'Y-m-d H:i:s' )
		) );

		return $row ?: null;
	}

	/**
	 * Fait tourner le refresh token : révoque l'ancien, en émet un nouveau.
	 * Limite la fenêtre de rejeu si un refresh token est intercepté.
	 *
	 * @return string Nouveau token BRUT, ou '' en cas d'échec.
	 */
	public static function rotate( string $old_token, int $user_id, string $device_id = '' ): string {
		$new_token = self::issue( $user_id, $device_id );
		if ( '' === $new_token ) return '';
		self::revoke( $old_token );
		return $new_token;
	}

	/* ══════════════════════════════════════════════════════════════════
	 * Révocation
	 * ══════════════════════════════════════════════════════════════════ */

	public static function revoke( string $token ): void {
		global $wpdb;
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $token ) ) return;
		if ( ! self::is_available() ) return;

		$wpdb->update(
			self::table(),
			array( 'revoked_at' => gmdate( 'Y-m-d H:i:s' ) ),
			array( 'token_hash' => self::hash( $token ), 'revoked_at' => null ),
			array( '%s' ),
			array( '%s', '%s' )
		);
	}

	/** Déconnexion "tous les appareils" (perte de téléphone, changement de mot de passe…). */
	public static function revoke_all_for_user( int $user_id ): int {
		global $wpdb;
		if ( $user_id <= 0 || ! self::is_available() ) return 0;

		return (int) $wpdb->query( $wpdb->prepare(
			'UPDATE ' . self::table() . '
			    SET revoked_at = %s
			  WHERE user_id = %d AND revoked_at IS NULL',
			gmdate( 'Y-m-d H:i:s' ),
			$user_id
		) );
	}

	/** Purge des tokens expirés/révoqués depuis plus de 30 jours. */
	public static function purge_stale(): int {
		global $wpdb;
		if ( ! self::is_available() ) return 0;
		return (int) $wpdb->query( $wpdb->prepare(
			'DELETE FROM ' . self::table() . '
			  WHERE (expires_at < %s) OR (revoked_at IS NOT NULL AND revoked_at < %s)',
			gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ),
			gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS )
		) );
	}
}
