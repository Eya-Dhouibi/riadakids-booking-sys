<?php
declare( strict_types=1 );
/**
 * RK_Auth_REST — pont Bearer JWT → identité WordPress + routes /rk/v1/auth/*.
 *
 * PRINCIPE CLÉ : ce fichier n'ajoute qu'un chemin d'identification EN PLUS
 * de l'existant. Il se branche sur le filtre WordPress standard
 * `determine_current_user`, qui est déjà le mécanisme que WP utilise pour
 * résoudre l'utilisateur courant sur chaque requête REST (cookie, application
 * passwords…). Si aucun header Authorization: Bearer n'est présent, ce filtre
 * ne fait RIEN et le comportement actuel (cookie/nonce, admin-ajax.php) est
 * 100% inchangé — aucune route existante n'est modifiée dans son fonctionnement.
 *
 * Une fois l'utilisateur résolu via ce filtre, TOUT le code existant qui
 * appelle get_current_user_id() / is_user_logged_in() / current_user_can()
 * fonctionne sans modification — y compris les permission_callback déjà en
 * place dans class-rk-mc-rest.php (ex. 'user_is_logged_in').
 *
 * @package RK_Platform
 * @since   4.19.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class RK_Auth_REST {

	public static function init(): void {
		// Résolution d'identité par Bearer token — priorité basse (10) pour
		// ne jamais court-circuiter un identifiant cookie déjà valide.
		add_filter( 'determine_current_user', array( __CLASS__, 'resolve_user_from_bearer' ), 20 );

		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * @param int|false $user_id Résultat des filtres précédents (ex. cookie WP).
	 * @return int|false
	 */
	public static function resolve_user_from_bearer( $user_id ) {
		// Un identifiant valide a déjà été trouvé (session cookie web) — ne pas l'écraser.
		if ( ! empty( $user_id ) ) return $user_id;

		$auth_header = self::get_authorization_header();
		if ( '' === $auth_header || stripos( $auth_header, 'Bearer ' ) !== 0 ) {
			return $user_id;   // pas de Bearer -> comportement WP inchangé
		}

		$token  = trim( substr( $auth_header, 7 ) );
		$claims = RKP_JwtService::verify( $token );
		if ( ! $claims ) return false;   // Bearer présent mais invalide -> refus explicite, pas de repli anonyme

		// Le user_id vient du JWT signé, mais AUCUNE autorisation n'est jamais
		// déduite des claims eux-mêmes : chaque endpoint reconstruit ses droits
		// depuis la DB (RKP_FamilyLinkQueryService, RKP_AssessmentEligibilityService…),
		// exactement comme pour une session web classique.
		if ( ! get_userdata( $claims['sub'] ) ) return false;

		return $claims['sub'];
	}

	/**
	 * Header custom volontairement DIFFÉRENT de "Authorization" : le plugin
	 * tiers "JWT Auth" (JWT_AUTH_SECRET_KEY, cf. wp-config.php) intercepte et
	 * rejette systématiquement tout header "Authorization: Bearer ..." sur
	 * TOUTES les routes REST — y compris les nôtres — en tentant de vérifier
	 * la signature avec SA propre clé, différente de RK_JWT_SECRET. Résultat :
	 * un access token rk-platform parfaitement valide se faisait rejeter par
	 * ce plugin avant même d'atteindre resolve_user_from_bearer() ci-dessus
	 * (erreur "jwt_auth_invalid_token" / "Signature verification failed").
	 * En utilisant un nom de header que ce plugin ne surveille pas, on évite
	 * le conflit sans toucher à sa configuration ni le désactiver — il reste
	 * libre de gérer ses propres routes /jwt-auth/v1/*.
	 */
	private const AUTH_HEADER_NAME = 'HTTP_X_RK_AUTH';

	private static function get_authorization_header(): string {
		if ( isset( $_SERVER[ self::AUTH_HEADER_NAME ] ) ) {
			return sanitize_text_field( wp_unslash( $_SERVER[ self::AUTH_HEADER_NAME ] ) );
		}
		// Certaines configs Apache/FastCGI ne transmettent pas HTTP_X_RK_AUTH
		// dans $_SERVER sous ce nom exact ; on retombe sur getallheaders().
		if ( function_exists( 'getallheaders' ) ) {
			foreach ( getallheaders() as $name => $value ) {
				if ( strcasecmp( $name, 'X-RK-Auth' ) === 0 ) {
					return sanitize_text_field( $value );
				}
			}
		}
		return '';
	}

	/* ══════════════════════════════════════════════════════════════════
	 * Routes /rk/v1/auth/*
	 * ══════════════════════════════════════════════════════════════════ */

	public static function register_routes(): void {
		register_rest_route( 'rk/v1', '/auth/login', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'handle_login' ),
			'permission_callback' => '__return_true',   // pas encore authentifié, c'est le but de la route
			'args'                => array(
				// Pivot 4.19.1 : login ENFANT (username + PIN 4 chiffres),
				// plus de mot de passe WordPress parent — cf. AuthTokenCommandService.
				'child_username' => array( 'required' => true, 'type' => 'string' ),
				'pin'            => array( 'required' => true, 'type' => 'string' ),
				'device_id'      => array( 'required' => false, 'type' => 'string', 'default' => '' ),
			),
		) );

		register_rest_route( 'rk/v1', '/auth/refresh', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'handle_refresh' ),
			'permission_callback' => '__return_true',   // authentifié par le refresh_token lui-même
			'args'                => array(
				'refresh_token' => array( 'required' => true, 'type' => 'string' ),
				'device_id'     => array( 'required' => false, 'type' => 'string', 'default' => '' ),
			),
		) );

		register_rest_route( 'rk/v1', '/auth/logout', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'handle_logout' ),
			'permission_callback' => '__return_true',
			'args'                => array(
				'refresh_token' => array( 'required' => true, 'type' => 'string' ),
			),
		) );

		register_rest_route( 'rk/v1', '/auth/me', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'handle_me' ),
			'permission_callback' => 'is_user_logged_in',   // couvre Bearer ET cookie, cf. resolve_user_from_bearer()
		) );
	}

	public static function handle_login( WP_REST_Request $request ) {
		$result = RKP_AuthTokenCommandService::login(
			(string) $request->get_param( 'child_username' ),
			(string) $request->get_param( 'pin' ),
			(string) $request->get_param( 'device_id' )
		);

		if ( empty( $result['ok'] ) ) {
			$error  = $result['error'] ?? 'login_failed';
			$status = ( 'rate_limited' === $error ) ? 429 : ( 'login_unavailable' === $error ? 503 : 401 );
			return new WP_REST_Response( array( 'error' => $error ), $status );
		}

		return new WP_REST_Response( self::format_token_response( $result ), 200 );
	}

	public static function handle_refresh( WP_REST_Request $request ) {
		$result = RKP_AuthTokenCommandService::refresh(
			(string) $request->get_param( 'refresh_token' ),
			(string) $request->get_param( 'device_id' )
		);

		if ( empty( $result['ok'] ) ) {
			return new WP_REST_Response( array( 'error' => $result['error'] ?? 'refresh_failed' ), 401 );
		}

		return new WP_REST_Response( self::format_token_response( $result ), 200 );
	}

	public static function handle_logout( WP_REST_Request $request ) {
		RKP_AuthTokenCommandService::logout( (string) $request->get_param( 'refresh_token' ) );
		return new WP_REST_Response( null, 204 );
	}

	/**
	 * Identité du titulaire du JWT — désormais l'enfant lui-même (pivot
	 * 4.19.1), pas un parent. On ne renvoie donc plus une liste de
	 * "children" : le sujet EST l'enfant. RKP_MobileChildAccess garantit
	 * que la ligne rk_children retournée est bien celle du compte
	 * wp_user_id authentifié, jamais celle d'un autre enfant.
	 */
	public static function handle_me( WP_REST_Request $request ) {
		$user_id = get_current_user_id();

		if ( ! class_exists( 'RKP_MobileChildAccess' ) ) {
			return new WP_REST_Response( array( 'error' => 'unavailable' ), 503 );
		}

		$self = RKP_MobileChildAccess::resolve_self( $user_id );
		if ( ! $self ) {
			return new WP_REST_Response( array( 'error' => 'not_found' ), 404 );
		}

		return new WP_REST_Response( self::format_child_summary( $self ), 200 );
	}

	/**
	 * Sous-ensemble volontairement restreint des colonnes de wp_rk_children
	 * exposées au mobile — jamais un SELECT * brut vers un client externe.
	 */
	private static function format_child_summary( object $row ): array {
		return array(
			'id'           => (int) $row->id,
			'wp_user_id'   => isset( $row->wp_user_id ) ? (int) $row->wp_user_id : null,
			'name'         => (string) ( $row->display_name ?: trim( $row->child_name . ' ' . $row->child_family_name ) ),
			'avatar_url'   => (string) ( $row->avatar_url ?? '' ),
		);
	}

	private static function format_token_response( array $result ): array {
		return array(
			'access_token'  => $result['access_token'],
			'refresh_token' => $result['refresh_token'],
			'expires_in'    => $result['expires_in'],
			'token_type'    => 'Bearer',
		);
	}
}