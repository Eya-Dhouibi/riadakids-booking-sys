<?php
declare( strict_types=1 );
/**
 * RKP_JwtService — émission/validation des access tokens mobiles (JWT).
 *
 * Volontairement SANS dépendance Composer (firebase/php-jwt) : la plupart
 * des installs WordPress de production ne garantissent pas un vendor/ à jour,
 * et un HMAC-SHA256 fait maison sur un format aussi simple est trivial à
 * auditer entièrement dans ce seul fichier.
 *
 * Portée volontairement réduite : uniquement HS256, uniquement les claims
 * dont l'app mobile a besoin. Ne PAS étendre en JWKS/RS256 sans vraie raison.
 *
 * Le secret RK_JWT_SECRET doit être défini dans wp-config.php :
 *   define( 'RK_JWT_SECRET', '<64+ caractères aléatoires>' );
 * Ne JAMAIS committer cette valeur ni la stocker en base/option.
 *
 * @package RK_Platform
 * @since   4.19.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class RKP_JwtService {

	/** Durée de vie d'un access token : 20 minutes. Volontairement court —
	 *  c'est le refresh token (RKP_RefreshTokenRepository) qui porte la
	 *  révocation réelle ; l'access token doit expirer vite s'il fuite. */
	public const ACCESS_TOKEN_TTL = 20 * MINUTE_IN_SECONDS;

	private static function secret(): string {
		if ( ! defined( 'RK_JWT_SECRET' ) || strlen( (string) RK_JWT_SECRET ) < 32 ) {
			if ( function_exists( 'rkp_log' ) ) {
				rkp_log( '[RK Mobile Auth] RK_JWT_SECRET absent ou trop court — voir wp-config.php' );
			}
			return '';   // provoque un échec de signature -> rejet, jamais un secret par défaut
		}
		return (string) RK_JWT_SECRET;
	}

	private static function b64url_encode( string $data ): string {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
	}

	private static function b64url_decode( string $data ): string {
		$pad = strlen( $data ) % 4;
		if ( $pad ) $data .= str_repeat( '=', 4 - $pad );
		return (string) base64_decode( strtr( $data, '-_', '+/' ), true );
	}

	/**
	 * Émet un access token pour un utilisateur WP.
	 *
	 * @param int   $user_id
	 * @param array $extra_claims Claims additionnelles non sensibles (ex. 'role').
	 */
	public static function issue( int $user_id, array $extra_claims = array() ): string {
		$secret = self::secret();
		if ( '' === $secret || $user_id <= 0 ) return '';

		$now    = time();
		$header = array( 'alg' => 'HS256', 'typ' => 'JWT' );
		$claims = array_merge(
			array(
				'sub' => $user_id,
				'iat' => $now,
				'exp' => $now + self::ACCESS_TOKEN_TTL,
				'iss' => 'rk-platform',
			),
			$extra_claims
		);

		$segments = array(
			self::b64url_encode( (string) wp_json_encode( $header ) ),
			self::b64url_encode( (string) wp_json_encode( $claims ) ),
		);
		$signing_input = implode( '.', $segments );
		$signature     = hash_hmac( 'sha256', $signing_input, $secret, true );
		$segments[]    = self::b64url_encode( $signature );

		return implode( '.', $segments );
	}

	/**
	 * Valide un access token et retourne ses claims, ou null si invalide/expiré.
	 * Ne fait AUCUNE hypothèse de confiance : vérifie la signature, l'expiration,
	 * puis c'est à l'appelant de reconstruire les autorisations depuis la DB
	 * (jamais depuis les claims eux-mêmes, à l'exception du user_id).
	 *
	 * @return array{sub:int,iat:int,exp:int}|null
	 */
	public static function verify( string $token ): ?array {
		$secret = self::secret();
		if ( '' === $secret ) return null;

		$parts = explode( '.', $token );
		if ( count( $parts ) !== 3 ) return null;
		[ $header_b64, $claims_b64, $sig_b64 ] = $parts;

		$signing_input     = $header_b64 . '.' . $claims_b64;
		$expected_sig      = hash_hmac( 'sha256', $signing_input, $secret, true );
		$provided_sig      = self::b64url_decode( $sig_b64 );

		if ( ! hash_equals( $expected_sig, $provided_sig ) ) return null;

		$claims = json_decode( self::b64url_decode( $claims_b64 ), true );
		if ( ! is_array( $claims ) || empty( $claims['sub'] ) || empty( $claims['exp'] ) ) return null;
		if ( (int) $claims['exp'] < time() ) return null;   // expiré

		return array(
			'sub' => (int) $claims['sub'],
			'iat' => (int) ( $claims['iat'] ?? 0 ),
			'exp' => (int) $claims['exp'],
		);
	}
}
