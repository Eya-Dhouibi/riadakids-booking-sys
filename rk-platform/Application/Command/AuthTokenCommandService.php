<?php
declare( strict_types=1 );
/**
 * Application — RKP_AuthTokenCommandService  (Write Model — auth mobile)
 *
 * Orchestration login/refresh/logout pour l'app mobile.
 *
 * PIVOT (4.19.1) : login() n'authentifie plus un parent via
 * wp_authenticate()/mot de passe WordPress. L'app mobile est le tableau
 * de bord de l'ENFANT (cf. pubspec.yaml : "tableau de bord enfant") — le
 * login se fait donc par username enfant + code PIN à 4 chiffres, EXACTEMENT
 * comme le web (/connexion-child/), en réutilisant sans les dupliquer :
 *   - RK_MC_Child_Repository::get_children_for_username() (recherche)
 *   - RK_MC_Pin_Security::verify()/is_unreadable() (vérification du PIN)
 *   - RK_MC_Pin_Security::is_locked()/register_failure()/clear_failures()
 *     (même verrou brute-force que le web — pas de logique dupliquée)
 * Le JWT émis a pour subject le wp_user_id de l'ENFANT (rôle rk_child),
 * jamais celui d'un parent — cf. RKP_MobileChildAccess.
 *
 * RÈGLE : aucun appel direct à $wpdb — tout passe par RKP_RefreshTokenRepository
 * et RK_MC_Child_Repository.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class RKP_AuthTokenCommandService {

	/**
	 * Login enfant par username + PIN (remplace l'ancien login parent).
	 *
	 * @return array{ok:bool,error?:string,access_token?:string,refresh_token?:string,expires_in?:int,user_id?:int}
	 */
	public static function login( string $child_username, string $pin, string $device_id = '' ): array {
		$child_username = sanitize_user( $child_username );
		$pin            = (string) preg_replace( '/\D/', '', $pin );

		if ( '' === trim( $child_username ) || 4 !== strlen( $pin ) ) {
			return array( 'ok' => false, 'error' => 'invalid_credentials' );
		}

		if ( ! class_exists( 'RK_MC_Pin_Security' ) || ! class_exists( 'RK_MC_Child_Repository' ) ) {
			return array( 'ok' => false, 'error' => 'login_unavailable' );
		}

		// Même verrou brute-force que /connexion-child/ côté web — un
		// enfant bloqué sur l'app mobile est aussi bloqué sur le web,
		// et réciproquement (une seule source de vérité).
		if ( RK_MC_Pin_Security::is_locked( $child_username ) ) {
			return array( 'ok' => false, 'error' => 'rate_limited' );
		}

		$all_children = RK_MC_Child_Repository::get_children_for_username( $child_username );
		if ( empty( $all_children ) ) {
			// Username introuvable : pas de tentative brute-force (cf. web).
			return array( 'ok' => false, 'error' => 'invalid_credentials' );
		}

		$matched          = null;
		$unreadable_found = false;

		foreach ( $all_children as $child_row ) {
			$wp_user_id = (int) ( $child_row->wp_user_id ?? 0 );
			if ( $wp_user_id <= 0 ) continue;

			if ( method_exists( 'RK_MC_Pin_Security', 'is_unreadable' )
				&& RK_MC_Pin_Security::is_unreadable( $wp_user_id ) ) {
				$unreadable_found = true;
				continue;
			}

			if ( RK_MC_Pin_Security::verify( $wp_user_id, $pin ) ) {
				$matched = $wp_user_id;
				break;
			}
		}

		if ( ! $matched && $unreadable_found ) {
			return array( 'ok' => false, 'error' => 'pin_unreadable' );
		}

		if ( ! $matched ) {
			RK_MC_Pin_Security::register_failure( $child_username );
			return array( 'ok' => false, 'error' => 'invalid_credentials' );
		}

		RK_MC_Pin_Security::clear_failures( $child_username );

		return self::issue_pair_for_user( $matched, $device_id );
	}

	/**
	 * Échange un refresh token valide contre un nouveau couple de tokens.
	 * Rotation systématique : l'ancien refresh token est révoqué.
	 */
	public static function refresh( string $refresh_token, string $device_id = '' ): array {
		$session = RKP_RefreshTokenRepository::find( $refresh_token );
		if ( ! $session ) {
			return array( 'ok' => false, 'error' => 'invalid_refresh_token' );
		}

		$user_id = (int) $session->user_id;
		if ( ! get_userdata( $user_id ) ) {
			// Compte supprimé depuis l'émission du token — révoquer et refuser.
			RKP_RefreshTokenRepository::revoke( $refresh_token );
			return array( 'ok' => false, 'error' => 'user_not_found' );
		}

		$new_refresh = RKP_RefreshTokenRepository::rotate( $refresh_token, $user_id, $device_id );
		if ( '' === $new_refresh ) {
			return array( 'ok' => false, 'error' => 'token_issue_failed' );
		}

		$access_token = RKP_JwtService::issue( $user_id );
		if ( '' === $access_token ) {
			RKP_RefreshTokenRepository::revoke( $new_refresh );
			return array( 'ok' => false, 'error' => 'token_issue_failed' );
		}

		return array(
			'ok'            => true,
			'access_token'  => $access_token,
			'refresh_token' => $new_refresh,
			'expires_in'    => RKP_JwtService::ACCESS_TOKEN_TTL,
			'user_id'       => $user_id,
		);
	}

	public static function logout( string $refresh_token ): void {
		RKP_RefreshTokenRepository::revoke( $refresh_token );
	}

	/** Déconnexion de tous les appareils (perte de téléphone, etc.). */
	public static function logout_all_devices( int $user_id ): void {
		RKP_RefreshTokenRepository::revoke_all_for_user( $user_id );
	}

	private static function issue_pair_for_user( int $user_id, string $device_id ): array {
		$refresh_token = RKP_RefreshTokenRepository::issue( $user_id, $device_id );
		if ( '' === $refresh_token ) {
			return array( 'ok' => false, 'error' => 'token_issue_failed' );
		}

		$access_token = RKP_JwtService::issue( $user_id );
		if ( '' === $access_token ) {
			RKP_RefreshTokenRepository::revoke( $refresh_token );
			return array( 'ok' => false, 'error' => 'token_issue_failed' );
		}

		return array(
			'ok'            => true,
			'access_token'  => $access_token,
			'refresh_token' => $refresh_token,
			'expires_in'    => RKP_JwtService::ACCESS_TOKEN_TTL,
			'user_id'       => $user_id,
		);
	}

	private static function client_ip(): string {
		return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '0.0.0.0';
	}
}
