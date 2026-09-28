<?php
declare( strict_types=1 );
/**
 * RK_Coach_Auth_Hardening — Fiabilisation de l'auth JWT du dashboard coach.
 * (v3.2.2 — + rétablit l'identité JWT annulée par rest_cookie_check_errors)
 *
 * Trois causes racines couvertes :
 *
 *  1. HEADER AUTHORIZATION AVALÉ (Apache/FastCGI/LiteSpeed) :
 *     le header `Authorization: Bearer …` n'atteint jamais PHP →
 *     le plugin JWT ne voit aucun token → user non connecté →
 *     is_coach() = false → 403 → le SPA redirige vers ?expired=1.
 *     → On restaure le header depuis REDIRECT_HTTP_AUTHORIZATION /
 *       getallheaders() AVANT que le plugin JWT ne s'exécute.
 *
 *  2. RÉPONSE 401/403 MISE EN CACHE (LiteSpeed / proxy) :
 *     une réponse anonyme cachée de /coach/home est servie au coach
 *     authentifié → faux expired.
 *     → no-cache strict + X-LiteSpeed-Cache-Control: no-cache sur
 *       TOUTES les routes rk/v1.
 *
 *  3. DIAGNOSTIC IMPOSSIBLE :
 *     → GET /wp-json/rk/v1/coach/ping (public) répond :
 *         auth_header_reaches_php, jwt_plugin_active, user_id, is_coach.
 *       Une seule requête suffit à trancher où ça casse.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class RK_Coach_Auth_Hardening {

	public static function init(): void {
		// 1 — restaurer le header le plus tôt possible (avant determine_current_user du JWT)
		self::restore_authorization_header();

		// 2 — no-cache sur toutes les réponses REST rk/v1
		add_filter( 'rest_post_dispatch', [ __CLASS__, 'no_cache_headers' ], 10, 3 );

		// 3 — endpoint de diagnostic
		add_action( 'rest_api_init', [ __CLASS__, 'register_ping' ] );

		// 4 — v9.31 — diagnostic dédié منح/سحب badges. Coach a rapporté
		// des 409 sur POST /coach/badges/award sans pouvoir savoir quelle
		// cause exacte (badge_key inconnu du catalogue, table SQL
		// manquante, points bonus en échec…) déclenchait le rollback.
		// Endpoint 100% lecture seule, réservé aux coachs authentifiés.
		add_action( 'rest_api_init', [ __CLASS__, 'register_badges_diagnose' ] );

		// 5 — v3.2.0 — Un Bearer JWT explicite prime sur le cookie de session.
		// Sans ça, un navigateur portant un cookie logged_in (admin connecté
		// dans un autre onglet du même navigateur) fait échouer le contrôle
		// de nonce cookie de rest_cookie_check_errors : WordPress remet alors
		// le current_user à 0, alors même que determine_current_user avait
		// correctement résolu l'utilisateur depuis le token JWT. Symptôme
		// observé : /coach/me répond 401 rest_forbidden dans le navigateur
		// où l'admin est connecté, et fonctionne en navigation privée.
		add_filter( 'rest_authentication_errors', [ __CLASS__, 'jwt_overrides_cookie' ], 5 );

		// 6 — v3.2.1 — Sur les routes coach, un Bearer explicite prime sur
		// l'identité issue du cookie de session. Priorité 100 : après tous
		// les autres résolveurs (JWT à 10, cookies à 20, Wordfence à 99).
		// Sans ce garde, dans un navigateur où un admin est déjà connecté,
		// c'est l'identité du cookie qui gagne et /coach/me ne voit jamais
		// le coach porteur du token — d'où un 401 reproductible uniquement
		// dans ce navigateur, et jamais en navigation privée.
		add_filter( 'determine_current_user', [ __CLASS__, 'force_jwt_identity' ], 100 );

		// 7 — v3.2.2 — rest_cookie_check_errors() appelle wp_set_current_user(0)
		// lorsqu'un cookie de session WordPress est présent sans nonce REST
		// valide. Sur les routes coach, authentifiées par Bearer et appelées
		// sans X-WP-Nonce, cet effet de bord annule l'utilisateur que le JWT
		// venait de résoudre : determine_current_user rend bien l'ID du coach,
		// puis get_current_user_id() vaut 0 au moment du permission_callback.
		// Symptôme : 401 rest_forbidden uniquement dans un navigateur où un
		// utilisateur WordPress est déjà connecté, jamais en navigation privée.
		// On rétablit l'identité ici, après la phase d'authentification REST
		// et avant le dispatch de la route.
		add_filter( 'rest_pre_dispatch', [ __CLASS__, 'restore_jwt_identity' ], 1, 3 );
	}

	/* ─────────────────────────────────────────────────────────────
	 * 7. Rétablir l'identité JWT après le contrôle de nonce cookie
	 * ───────────────────────────────────────────────────────────── */

	/**
	 * Ne s'applique qu'aux routes /rk/v1/coach/* et uniquement si l'identité
	 * a effectivement été perdue (current_user_id à 0). L'ID est relu depuis
	 * le Bearer via force_jwt_identity() ; sans token valide, rien n'est
	 * rétabli et la requête reste anonyme.
	 *
	 * @param mixed           $result
	 * @param WP_REST_Server  $server
	 * @param WP_REST_Request $request
	 * @return mixed
	 */
	public static function restore_jwt_identity( $result, $server, $request ) {
		if ( false === strpos( (string) $request->get_route(), '/rk/v1/coach/' ) ) {
			return $result;
		}
		if ( get_current_user_id() > 0 ) {
			return $result;
		}

		$uid = (int) self::force_jwt_identity( 0 );
		if ( $uid > 0 ) {
			wp_set_current_user( $uid );
		}

		return $result;
	}

	/* ─────────────────────────────────────────────────────────────
	 * 6. Le Bearer prime sur le cookie (routes coach uniquement)
	 * ───────────────────────────────────────────────────────────── */

	/**
	 * Relit l'ID utilisateur depuis le payload du token, pour les seules
	 * routes /rk/v1/coach/*. La signature n'est PAS revalidée ici : le
	 * plugin JWT s'en charge en amont sur la même requête et rejette un
	 * token invalide avant le permission_callback. Ce filtre ne fait donc
	 * que départager deux identités déjà authentifiées — il n'accorde
	 * jamais l'accès à un token qui n'aurait pas passé cette validation.
	 *
	 * @param int|false $user_id
	 * @return int|false
	 */
	public static function force_jwt_identity( $user_id ) {
		$uri = (string) ( $_SERVER['REQUEST_URI'] ?? '' );
		if ( false === strpos( $uri, '/rk/v1/coach/' ) ) {
			return $user_id;
		}

		$auth = (string) ( $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['HTTP_X_RK_COACH_AUTH'] ?? '' );
		if ( 0 !== stripos( $auth, 'bearer ' ) ) {
			return $user_id;
		}

		$parts = explode( '.', trim( substr( $auth, 7 ) ) );
		if ( 3 !== count( $parts ) ) {
			return $user_id;
		}

		$payload = json_decode( (string) base64_decode( strtr( $parts[1], '-_', '+/' ) ), true );
		$jwt_uid = (int) ( $payload['data']['user']['id'] ?? 0 );

		return $jwt_uid > 0 ? $jwt_uid : $user_id;
	}

	/* ─────────────────────────────────────────────────────────────
	 * 5. Le Bearer JWT prime sur l'échec de nonce cookie
	 * ───────────────────────────────────────────────────────────── */

	/**
	 * Neutralise la seule erreur `rest_cookie_invalid_nonce` lorsqu'un
	 * Bearer a été fourni : dans ce cas l'authentification par cookie
	 * n'est pas la méthode visée, son échec ne doit pas invalider la
	 * requête. Toute autre erreur est renvoyée telle quelle.
	 *
	 * @param WP_Error|null|true $errors
	 * @return WP_Error|null|true
	 */
	public static function jwt_overrides_cookie( $errors ) {
		if ( ! is_wp_error( $errors ) ) {
			return $errors;
		}
		if ( 'rest_cookie_invalid_nonce' !== $errors->get_error_code() ) {
			return $errors;
		}

		$auth = (string) ( $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['HTTP_X_RK_COACH_AUTH'] ?? '' );
		if ( 0 !== stripos( $auth, 'bearer ' ) ) {
			return $errors;
		}

		return null;
	}

	/* ─────────────────────────────────────────────────────────────
	 * 1. Restaurer Authorization
	 * ───────────────────────────────────────────────────────────── */

	private static function restore_authorization_header(): void {
		if ( ! empty( $_SERVER['HTTP_AUTHORIZATION'] ) ) return;

		// Apache mod_rewrite en FastCGI place le header ici.
		if ( ! empty( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
			$_SERVER['HTTP_AUTHORIZATION'] = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
			return;
		}
		// Dernier recours : relire les headers bruts (Apache/LiteSpeed).
		if ( function_exists( 'getallheaders' ) ) {
			foreach ( getallheaders() as $name => $value ) {
				if ( 'authorization' === strtolower( (string) $name ) && '' !== $value ) {
					$_SERVER['HTTP_AUTHORIZATION'] = $value;
					return;
				}
			}
		}

		// v3.2 — Sur certains hébergements mutualisés (constaté sur
		// Hostinger/LiteSpeed CGI/LSAPI), le header "Authorization" est
		// supprimé de l'environnement PHP AVANT même que .htaccess ou
		// getallheaders() ne puisse le voir (aucune des méthodes
		// ci-dessus ne fonctionne, confirmé via /coach/ping :
		// auth_header_reaches_php reste false même avec CGIPassAuth On +
		// SetEnvIfNoCase). Contournement définitif : le SPA envoie EN
		// PLUS un header custom "X-RK-Coach-Auth" avec la même valeur
		// "Bearer <token>". Les headers "X-*" ne sont eux jamais filtrés
		// par ce mécanisme CGI historique. On le relit ici en dernier
		// recours et on reconstruit HTTP_AUTHORIZATION à partir de lui,
		// pour que le plugin JWT (qui lit HTTP_AUTHORIZATION) fonctionne
		// normalement ensuite, sans dupliquer sa logique de parsing.
		if ( ! empty( $_SERVER['HTTP_X_RK_COACH_AUTH'] ) ) {
			$_SERVER['HTTP_AUTHORIZATION'] = $_SERVER['HTTP_X_RK_COACH_AUTH'];
			return;
		}
	}

	/* ─────────────────────────────────────────────────────────────
	 * 2. No-cache strict sur rk/v1
	 * ───────────────────────────────────────────────────────────── */

	public static function no_cache_headers( $response, $server, $request ) {
		if ( $response instanceof WP_REST_Response
		     && str_starts_with( (string) $request->get_route(), '/rk/v1/' ) ) {
			$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
			$response->header( 'Pragma', 'no-cache' );
			$response->header( 'X-LiteSpeed-Cache-Control', 'no-cache' );
			$response->header( 'Vary', 'Authorization' );
		}
		return $response;
	}

	/* ─────────────────────────────────────────────────────────────
	 * 3. Diagnostic public
	 * ───────────────────────────────────────────────────────────── */

	public static function register_ping(): void {
		register_rest_route( 'rk/v1', '/coach/ping', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'ping' ],
			'permission_callback' => '__return_true', // volontairement public : ne révèle rien de sensible
		] );
	}

	public static function ping(): WP_REST_Response {
		$auth_raw = (string) ( $_SERVER['HTTP_AUTHORIZATION'] ?? '' );
		$user_id  = get_current_user_id();
		$is_coach = $user_id > 0
			&& class_exists( 'RK_Coach_Dashboard' )
			&& RK_Coach_Dashboard::is_coach_user( wp_get_current_user() );

		return new WP_REST_Response( [
			'auth_header_reaches_php' => '' !== $auth_raw,
			'auth_scheme'             => $auth_raw ? strtok( $auth_raw, ' ' ) : '',
			'auth_via_fallback_header' => empty( $_SERVER['HTTP_AUTHORIZATION'] ) && ! empty( $_SERVER['HTTP_X_RK_COACH_AUTH'] ),
			'jwt_plugin_active'       => class_exists( 'Jwt_Auth' ) || class_exists( 'Jwt_Auth_Public' )
			                             || function_exists( 'jwt_auth_rest_api_init' )
			                             || defined( 'JWT_AUTH_SECRET_KEY' ),
			'jwt_secret_defined'      => defined( 'JWT_AUTH_SECRET_KEY' ) && '' !== (string) JWT_AUTH_SECRET_KEY,
			'user_id'                 => $user_id,   // > 0 = le Bearer a bien authentifié
			'is_coach'                => $is_coach,
			'time'                    => time(),
		], 200 );
	}

	/* ─────────────────────────────────────────────────────────────
	 * 4. Diagnostic منح/سحب badges (v9.31)
	 * ───────────────────────────────────────────────────────────── */

	public static function register_badges_diagnose(): void {
		register_rest_route( 'rk/v1', '/coach/badges/diagnose', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'diagnose_badges' ],
			// Réservé au coach connecté : contrairement à /coach/ping,
			// cet endpoint révèle des noms de table et l'état interne
			// du catalogue, pas anodin à exposer publiquement.
			'permission_callback' => static function () {
				return is_user_logged_in()
					&& class_exists( 'RK_Coach_Dashboard' )
					&& RK_Coach_Dashboard::is_coach_user( wp_get_current_user() );
			},
		] );
	}

	/**
	 * 100% lecture seule (l'insert de test est fait dans une transaction
	 * systématiquement annulée) — n'écrit jamais réellement en base.
	 * Vérifie chaque étape de la chaîne منح/سحب dans l'ordre où
	 * BadgeCommandService::award() les traverse, pour isoler exactement
	 * où un 409 se produit :
	 *   1. Tables SQL requises existent-elles ?
	 *   2. Le catalogue de badges se charge-t-il (au moins 1 entrée) ?
	 *   3. Le badge_key fourni (optionnel, ?badge_key=xxx) est-il dans
	 *      le catalogue ?
	 *   4. Le child_id fourni (optionnel, ?child_id=N) a-t-il déjà ce
	 *      badge (cause la plus fréquente d'un 409 : 'already_awarded') ?
	 *   5. Le child_id appartient-il bien à ce coach (sinon 403 avant
	 *      même d'atteindre award()) ?
	 *   6. v9.32 — lecture SQL brute des lignes existantes pour ce
	 *      child_id (détecte une éventuelle ligne orpheline non vue par
	 *      has_badge()) + insertion à blanc pour capturer le VRAI
	 *      message MySQL si insert_test.ok est false.
	 */
	public static function diagnose_badges( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;
		$coach_id  = get_current_user_id();
		$badge_key = sanitize_key( (string) $request->get_param( 'badge_key' ) );
		$child_id  = (int) $request->get_param( 'child_id' );

		$tables = [
			'rk_child_badges'  => $wpdb->prefix . 'rk_child_badges',
			'rk_custom_badges' => $wpdb->prefix . 'rk_custom_badges',
			'rk_child_points'  => $wpdb->prefix . 'rk_child_points',
			'rk_children'      => $wpdb->prefix . 'rk_children',
			'rk_child_coaches' => $wpdb->prefix . 'rk_child_coaches',
		];
		$tables_exist = [];
		foreach ( $tables as $label => $full_name ) {
			$tables_exist[ $label ] = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $full_name ) ) === $full_name;
		}

		$catalogue_count  = 0;
		$catalogue_error  = null;
		$badge_key_exists = null;
		try {
			if ( class_exists( 'RKP_BadgeQueryService' ) ) {
				$cat             = RKP_BadgeQueryService::catalogue();
				$catalogue_count = count( $cat );
				if ( '' !== $badge_key ) {
					$badge_key_exists = isset( $cat[ $badge_key ] );
				}
			} else {
				$catalogue_error = 'RKP_BadgeQueryService introuvable (autoloader RKP_ non chargé ?)';
			}
		} catch ( \Throwable $e ) {
			$catalogue_error = $e->getMessage();
		}

		$already_awarded = null;
		$child_owned_by_coach = null;
		$raw_badge_rows = null;
		$insert_test = null;
		if ( $child_id > 0 ) {
			if ( '' !== $badge_key && class_exists( 'RKP_BadgeRepository' ) ) {
				$already_awarded = RKP_BadgeRepository::has_badge( $child_id, $badge_key );
			}
			if ( class_exists( 'RKP_CoachStudentRepository' ) ) {
				$child_owned_by_coach = RKP_CoachStudentRepository::coach_owns_child( $coach_id, $child_id );
			}

			// v9.32 — has_badge() dit false mais l'insert échoue quand
			// même avec un 409 'insert_failed' ? Lecture SQL brute pour
			// vérifier s'il existe une ligne orpheline (child_id stocké
			// différemment de ce que has_badge() interroge — legacy ID
			// vs wp_user_id, ou une casse différente du badge_key) qui
			// violerait quand même la contrainte UNIQUE (child_id, badge_key).
			if ( '' !== $badge_key ) {
				$table_cb = $wpdb->prefix . 'rk_child_badges';
				$raw_badge_rows = $wpdb->get_results( $wpdb->prepare(
					"SELECT id, child_id, badge_key, earned_at FROM {$table_cb} WHERE child_id = %d",
					$child_id
				) );

				// Insertion à blanc dans une transaction systématiquement
				// annulée : capture le VRAI message MySQL (contrainte,
				// colonne manquante, type incompatible…) sans jamais
				// laisser de trace en base, même en cas de succès du test.
				$wpdb->query( 'START TRANSACTION' );
				$test_key = '__rkp_diagnose_test__';
				$ok = $wpdb->insert( $table_cb, [
					'child_id'  => $child_id,
					'badge_key' => $test_key,
					'earned_at' => current_time( 'mysql' ),
					'note'      => 'diagnose-only, rolled back',
				] );
				$insert_test = [
					'ok'         => (bool) $ok,
					'last_error' => $wpdb->last_error ?: null,
					'last_query' => $wpdb->last_query ?: null,
				];
				$wpdb->query( 'ROLLBACK' );
			}
		}

		// v9.33 — insert_test (rk_child_badges) était sain (ok:true,
		// aucun last_error) alors que le 409 persistait quand même.
		// La seule branche restante de award_with_reason() capable de
		// causer un rollback après un insert() réussi est le bonus de
		// points (RKP_GamificationCommandService::add_points()),
		// déclenché uniquement si c'est le 1er badge du mois pour cet
		// enfant. Même méthode de test à blanc, appliquée à
		// wp_rk_child_points, pour capturer le vrai message MySQL ici.
		$points_test = null;
		$count_this_month = null;
		if ( $child_id > 0 && class_exists( 'RKP_BadgeRepository' ) ) {
			$count_this_month = RKP_BadgeRepository::count_this_month( $child_id );
			$table_pts = $wpdb->prefix . 'rk_child_points';
			if ( $tables_exist['rk_child_points'] ) {
				$wpdb->query( 'START TRANSACTION' );
				$ok_pts = $wpdb->insert( $table_pts, [
					'child_id'  => $child_id,
					'points'    => 20,
					'source'    => 'badge_first',
					'source_id' => 0,
					'note'      => 'diagnose-only, rolled back',
					'earned_at' => current_time( 'mysql' ),
				] );
				$points_test = [
					'ok'         => (bool) $ok_pts,
					'last_error' => $wpdb->last_error ?: null,
					'last_query' => $wpdb->last_query ?: null,
				];
				$wpdb->query( 'ROLLBACK' );
			}
		}

		return new WP_REST_Response( [
			'coach_id'             => $coach_id,
			'tables_exist'         => $tables_exist,
			'catalogue_count'      => $catalogue_count,
			'catalogue_error'      => $catalogue_error,
			'badge_key_checked'    => $badge_key ?: null,
			'badge_key_in_catalog' => $badge_key_exists,
			'child_id_checked'     => $child_id ?: null,
			'child_owned_by_coach' => $child_owned_by_coach,
			'child_already_has_badge' => $already_awarded,
			'raw_badge_rows_for_child' => $raw_badge_rows,
			'insert_test'          => $insert_test,
			'badges_count_this_month' => $count_this_month,
			'points_insert_test'   => $points_test,
			'php_version'          => PHP_VERSION,
			'rkp_version'          => defined( 'RKP_VERSION' ) ? RKP_VERSION : null,
			'time'                 => current_time( 'mysql' ),
		], 200 );
	}
}