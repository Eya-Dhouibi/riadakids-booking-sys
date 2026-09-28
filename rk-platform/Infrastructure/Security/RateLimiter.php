<?php
declare( strict_types=1 );
/**
 * Infrastructure/Security — RKP_RateLimiter
 *
 * Limitation de débit générique (transients — compatible Redis object cache).
 *
 * Usage :
 *   if ( ! RKP_RateLimiter::allow( 'pin_login', $ip, 5, 15 * MINUTE_IN_SECONDS ) ) { ... 429 ... }
 *   RKP_RateLimiter::hit( 'pin_login', $ip, 15 * MINUTE_IN_SECONDS );   // sur échec
 *   RKP_RateLimiter::clear( 'pin_login', $ip );                          // sur succès
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class RKP_RateLimiter {

	private static function key( string $scope, string $subject ): string {
		return 'rkp_rl_' . $scope . '_' . substr( hash( 'sha256', $subject ), 0, 20 );
	}

	/** true si la limite n'est PAS atteinte. */
	public static function allow( string $scope, string $subject, int $max, int $window ): bool {
		return (int) get_transient( self::key( $scope, $subject ) ) < $max;
	}

	/** Incrémente le compteur (fenêtre glissante approximative). */
	public static function hit( string $scope, string $subject, int $window ): int {
		$k = self::key( $scope, $subject );
		$n = (int) get_transient( $k ) + 1;
		set_transient( $k, $n, $window );
		return $n;
	}

	public static function clear( string $scope, string $subject ): void {
		delete_transient( self::key( $scope, $subject ) );
	}

	/** Attempts restants avant blocage. */
	public static function remaining( string $scope, string $subject, int $max ): int {
		return max( 0, $max - (int) get_transient( self::key( $scope, $subject ) ) );
	}

	/** Helper : IP client fiable derrière proxy configuré. */
	public static function client_ip(): string {
		$ip = $_SERVER['REMOTE_ADDR'] ?? '';
		return (string) apply_filters( 'rkp_client_ip', $ip );
	}
}
