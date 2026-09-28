<?php
declare( strict_types=1 );
/**
 * RK_MC_Pin_Security — Sécurisation du PIN d'accès enfant.  (v2.2.0)
 *
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class RK_MC_Pin_Security {

	private const CIPHER        = 'aes-256-gcm';
	private const PREFIX        = '$rkpin1$';        // marqueur de format chiffré
	private const MAX_FAILS     = 10;                // seuil par identité
	private const MAX_FAILS_IP  = 40;                // seuil par IP+identité
	private const LOCK_TTL      = 10 * MINUTE_IN_SECONDS;

	/* ─────────────────────────────────────────
	 * Chiffrement au repos
	 * ───────────────────────────────────────── */

	private static function key(): string {
		// Clé 32 octets dérivée des salts WP — stable tant que les salts le sont.
		return hash( 'sha256', wp_salt( 'auth' ) . '|rk_child_pin', true );
	}

	public static function encrypt( string $pin ): string {
		$iv  = random_bytes( 12 );
		$tag = '';
		$ct  = openssl_encrypt( $pin, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag );
		if ( false === $ct ) {
			// openssl indisponible → on refuse de stocker en clair silencieusement.
			throw new \RuntimeException( 'RK_MC_Pin_Security: encryption unavailable.' );
		}
		return self::PREFIX . base64_encode( $iv . $tag . $ct );
	}

	/** @return string PIN en clair, ou '' si indéchiffrable. */
	public static function decrypt( string $stored ): string {
		if ( strpos( $stored, self::PREFIX ) !== 0 ) {
			// Ancien format clair (migration lazy gérée par caller).
			return ctype_digit( $stored ) ? $stored : '';
		}
		$raw = base64_decode( substr( $stored, strlen( self::PREFIX ) ), true );
		if ( false === $raw || strlen( $raw ) < 29 ) return '';
		$iv  = substr( $raw, 0, 12 );
		$tag = substr( $raw, 12, 16 );
		$ct  = substr( $raw, 28 );
		$pin = openssl_decrypt( $ct, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag );
		return is_string( $pin ) ? $pin : '';
	}

	public static function is_encrypted( string $stored ): bool {
		return strpos( $stored, self::PREFIX ) === 0;
	}

	/* ─────────────────────────────────────────
	 * Lecture / écriture usermeta (source unique)
	 * ───────────────────────────────────────── */

	/** Stocke un PIN (toujours chiffré). */
	public static function store( int $wp_user_id, string $pin ): void {
		update_user_meta( $wp_user_id, RK_MC_Child_User::META_CHILD_PIN, self::encrypt( $pin ) );
	}

	/**
	 * Lit le PIN en clair pour AFFICHAGE (parent / coach).
	 * Migration lazy : un PIN encore en clair est re-chiffré au passage.
	 */
	public static function read( int $wp_user_id ): string {
		$stored = (string) get_user_meta( $wp_user_id, RK_MC_Child_User::META_CHILD_PIN, true );
		if ( '' === $stored ) return '';
		if ( ! self::is_encrypted( $stored ) ) {
			$pin = ctype_digit( $stored ) && strlen( $stored ) === 4 ? $stored : '';
			if ( $pin ) self::store( $wp_user_id, $pin ); // migration lazy
			return $pin;
		}
		return self::decrypt( $stored );
	}

	/**
	 * true si un PIN est bien enregistré mais illisible.
	 * Cas typique : salts WP régénérés (migration serveur, wp-config.php refait).
	 * Le bon code ne fonctionnera JAMAIS tant que le PIN n'est pas réinitialisé.
	 */
	public static function is_unreadable( int $wp_user_id ): bool {
		$stored = (string) get_user_meta( $wp_user_id, RK_MC_Child_User::META_CHILD_PIN, true );
		return '' !== $stored && '' === self::read( $wp_user_id );
	}

	/** Vérifie un PIN candidat en temps constant. */
	public static function verify( int $wp_user_id, string $candidate ): bool {
		$stored = (string) get_user_meta( $wp_user_id, RK_MC_Child_User::META_CHILD_PIN, true );
		$real   = self::read( $wp_user_id );

		// PIN présent mais indéchiffrable → problème de clé, pas de l'utilisateur.
		if ( '' !== $stored && '' === $real ) {
			self::log( 'pin_decrypt_failed', [ 'user_id' => $wp_user_id ] );
			return false;
		}

		return '' !== $real && hash_equals( $real, $candidate );
	}

	/* ─────────────────────────────────────────
	 * Anti brute-force à deux clés (IP + identité)
	 * ───────────────────────────────────────── */

	private static function ip(): string {
		return isset( $_SERVER['REMOTE_ADDR'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	}

	/**
	 * La clé 'ip' inclut l'identité ciblée : sans cela, 5 échecs sur un enfant
	 * verrouillaient TOUS les enfants derrière la même IP (fratrie, NAT, 4G).
	 */
	private static function keys( string $name_norm ): array {
		return [
			'ip'   => 'rk_pin_fail_ip_'   . substr( md5( self::ip() . '|' . $name_norm ), 0, 16 ),
			'name' => 'rk_pin_fail_name_' . substr( md5( $name_norm ), 0, 16 ),
		];
	}

	private static function limit( string $scope ): int {
		return 'ip' === $scope ? self::MAX_FAILS_IP : self::MAX_FAILS;
	}

	/**
	 * Lit un compteur. Format v2.2 : ['n' => int, 'until' => timestamp].
	 * Rétrocompatible avec l'ancien format entier nu.
	 */
	private static function read_counter( string $key ): array {
		$raw = get_transient( $key );
		if ( is_array( $raw ) ) {
			return [ 'n' => (int) ( $raw['n'] ?? 0 ), 'until' => (int) ( $raw['until'] ?? 0 ) ];
		}
		if ( false === $raw ) return [ 'n' => 0, 'until' => 0 ];
		return [ 'n' => (int) $raw, 'until' => time() + self::LOCK_TTL ];
	}

	/** true si l'IP+identité OU l'identité seule est verrouillée. */
	public static function is_locked( string $name_norm ): bool {
		foreach ( self::keys( $name_norm ) as $scope => $k ) {
			if ( self::read_counter( $k )['n'] >= self::limit( $scope ) ) return true;
		}
		return false;
	}

	/** Secondes restantes avant déverrouillage (0 si non verrouillé). */
	public static function lock_remaining( string $name_norm ): int {
		$max = 0;
		foreach ( self::keys( $name_norm ) as $scope => $k ) {
			$c = self::read_counter( $k );
			if ( $c['n'] < self::limit( $scope ) ) continue;
			$max = max( $max, $c['until'] - time() );
		}
		return max( 0, $max );
	}

	/** Essais restants avant verrouillage sur l'identité (indicatif UI). */
	public static function attempts_left( string $name_norm ): int {
		$keys = self::keys( $name_norm );
		return max( 0, self::MAX_FAILS - self::read_counter( $keys['name'] )['n'] );
	}

	public static function register_failure( string $name_norm ): void {
		foreach ( self::keys( $name_norm ) as $scope => $k ) {
			$c = self::read_counter( $k );
			$n = $c['n'] + 1;

			// La fenêtre ne glisse pas : le verrou expire bien LOCK_TTL après
			// le PREMIER échec, sinon un attaquant lent le prolonge indéfiniment.
			$until = $c['until'] > time() ? $c['until'] : time() + self::LOCK_TTL;
			$ttl   = max( 60, $until - time() );

			set_transient( $k, [ 'n' => $n, 'until' => $until ], $ttl );

			if ( $n === self::limit( $scope ) ) {
				self::log( 'pin_lockout', [ 'scope' => $scope, 'name' => $name_norm ] );
			}
		}
		self::log( 'pin_login_failed', [ 'name' => $name_norm ] );
	}

	public static function clear_failures( string $name_norm ): void {
		foreach ( self::keys( $name_norm ) as $k ) delete_transient( $k );
	}

	/** Déverrouillage manuel (parent / coach / admin). */
	public static function unlock( string $name_norm ): void {
		self::clear_failures( $name_norm );
		self::log( 'pin_unlock_manual', [ 'name' => $name_norm ] );
	}

	/**
	 * Purge TOUS les verrous actifs (dépannage admin / WP-CLI).
	 * Nécessaire car les clés sont hachées : impossible de cibler sans le nom.
	 *
	 * @return int nombre de transients supprimés.
	 */
	public static function unlock_all(): int {
		global $wpdb;

		$rows = $wpdb->get_col(
			"SELECT option_name FROM {$wpdb->options}
			 WHERE option_name LIKE '\_transient\_rk\_pin\_fail\_%'"
		);

		$done = 0;
		foreach ( $rows as $option_name ) {
			$key = substr( (string) $option_name, strlen( '_transient_' ) );
			if ( delete_transient( $key ) ) $done++;
		}

		// Object cache externe (LiteSpeed, Redis) : les transients peuvent ne pas
		// être en base. On vide le groupe pour garantir la purge.
		if ( wp_using_ext_object_cache() ) {
			wp_cache_flush();
		}

		self::log( 'pin_unlock_all', [ 'count' => $done ] );
		return $done;
	}

	/* ─────────────────────────────────────────
	 * Journalisation
	 * ───────────────────────────────────────── */

	private static function log( string $action, array $meta ): void {
		$meta['ip'] = self::ip();
		if ( class_exists( 'RK_Audit_Log' ) && method_exists( 'RK_Audit_Log', 'record' ) ) {
			RK_Audit_Log::record( $action, 0, $meta );
		}
		/** Observabilité externe (SIEM, Slack, mail admin…). */
		do_action( 'rk_child_pin_security_event', $action, $meta );
	}

	/* ─────────────────────────────────────────
	 * Migration bulk optionnelle (WP-CLI / admin)
	 * ───────────────────────────────────────── */

	/** Chiffre tous les PIN encore en clair. @return int nombre migré. */
	public static function migrate_all(): int {
		$users = get_users( [ 'role' => 'rk_child', 'number' => -1, 'fields' => 'ID' ] );
		$done  = 0;
		foreach ( $users as $uid ) {
			$stored = (string) get_user_meta( (int) $uid, RK_MC_Child_User::META_CHILD_PIN, true );
			if ( $stored && ! self::is_encrypted( $stored ) && ctype_digit( $stored ) ) {
				self::store( (int) $uid, $stored );
				$done++;
			}
		}
		return $done;
	}

	/**
	 * Inventaire de l'état des PIN (diagnostic).
	 * @return array<int, array{name:string, state:string}>
	 */
	public static function audit_all(): array {
		$out = [];
		foreach ( get_users( [ 'role' => 'rk_child', 'number' => -1 ] ) as $u ) {
			$stored = (string) get_user_meta( $u->ID, RK_MC_Child_User::META_CHILD_PIN, true );

			if ( '' === $stored )                      $state = 'absent';
			elseif ( ! self::is_encrypted( $stored ) )  $state = 'clair (à migrer)';
			elseif ( '' === self::decrypt( $stored ) )  $state = 'ILLISIBLE — salts modifiés';
			else                                        $state = 'ok';

			$out[ (int) $u->ID ] = [
				'name'  => (string) ( $u->first_name ?: $u->display_name ),
				'state' => $state,
			];
		}
		return $out;
	}
}