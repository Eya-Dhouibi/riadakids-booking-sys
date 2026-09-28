<?php
declare( strict_types=1 );
/**
 * RK_Mobile_CORS — autorise le header custom `X-RK-Auth` en CORS pour les
 * routes /rk/v1/* uniquement.
 *
 * PROBLÈME OBSERVÉ : sur le web (Chrome, `flutter run -d chrome`), le POST
 * /rk/v1/auth/login réussissait (les tokens étaient bien enregistrés), mais
 * le GET /rk/v1/auth/me qui suit immédiatement échouait avec
 * `ClientException: Failed to fetch` — une erreur levée par le NAVIGATEUR
 * lui-même, avant même que la requête ne parte réellement.
 *
 * CAUSE : `ApiClient` envoie le token dans un header CUSTOM `X-RK-Auth`
 * (volontairement différent de `Authorization`, cf. la note dans
 * class-rk-auth-rest.php sur le conflit avec le plugin JWT Auth tiers).
 * Un header non-standard sur une requête cross-origin déclenche un
 * préflight `OPTIONS` du navigateur, qui exige que le serveur réponde avec
 * `Access-Control-Allow-Headers: X-RK-Auth` explicitement. Le filtre CORS
 * par défaut de WordPress (`rest_send_cors_headers`, sur
 * `rest_pre_serve_request`) pose bien `Access-Control-Allow-Origin`, mais
 * NE POSE JAMAIS `Access-Control-Allow-Headers` — donc le préflight
 * échouait côté navigateur et `fetch()` remontait directement
 * "Failed to fetch" sans même que la requête réelle n'atteigne PHP (elle
 * n'apparaît donc dans AUCUN log serveur).
 *
 * PORTÉE VOLONTAIREMENT LIMITÉE : on AJOUTE des en-têtes, on ne retire pas
 * le filtre CORS par défaut de WordPress, et on ne s'applique qu'aux
 * routes commençant par `/rk/v1/` — le comportement CORS du reste de
 * l'API REST WordPress (utilisée par le site web, d'autres plugins, etc.)
 * reste strictement inchangé.
 *
 * @package RK_Platform
 * @since   4.20.2
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class RK_Mobile_CORS {

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register' ) );
	}

	public static function register(): void {
		add_filter( 'rest_pre_serve_request', array( __CLASS__, 'add_headers' ), 10, 4 );
	}

	/**
	 * @param bool             $served
	 * @param WP_REST_Response $result
	 * @param WP_REST_Request  $request
	 * @param WP_REST_Server   $server
	 */
	public static function add_headers( $served, $result, $request, $server ) {
		$route = $request instanceof WP_REST_Request ? $request->get_route() : '';
		if ( 0 !== strpos( $route, '/rk/v1/' ) ) {
			return $served; // pas une route mobile — on ne touche à rien.
		}

		// TODO PROD : remplacer '*' par l'origine exacte de l'app une fois
		// figée (ex. capacitor://localhost, ou le domaine si publié en PWA).
		// '*' est acceptable ici car ces routes n'utilisent JAMAIS de cookies
		// (Access-Control-Allow-Credentials n'est pas envoyé) — l'auth passe
		// uniquement par le Bearer token dans X-RK-Auth, jamais par cookie.
		header( 'Access-Control-Allow-Origin: *' );
		header( 'Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS' );
		header( 'Access-Control-Allow-Headers: Content-Type, X-RK-Auth' );
		header( 'Access-Control-Max-Age: 600' );

		// Requête de préflight : WordPress n'a rien de plus à faire, on
		// répond 200 immédiatement avec les en-têtes déjà posés ci-dessus.
		if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'OPTIONS' === $_SERVER['REQUEST_METHOD'] ) {
			status_header( 200 );
			return true; // marque la requête comme déjà servie.
		}

		return $served;
	}
}
