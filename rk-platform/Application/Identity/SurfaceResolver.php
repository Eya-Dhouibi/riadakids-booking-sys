<?php
declare( strict_types=1 );
/**
 * RK_Surface_Resolver — classification de la REQUÊTE en surface.
 *
 * ┌──────────────────────────────────────────────────────────────────────────┐
 * │  RESPONSABILITÉ UNIQUE                                                    │
 * │                                                                          │
 * │      REQUEST ─────────► surface                                          │
 * │                                                                          │
 * │  Cette classe ne décide JAMAIS d'une identité. Elle ne sait pas ce        │
 * │  qu'est un parent ou un enfant. Elle répond à une seule question :        │
 * │  « quelle est la nature de cette URL ? ».                                 │
 * │                                                                          │
 * │  C'est RK_Identity_Context qui décide ensuite comment l'identité          │
 * │  s'applique sur la surface retournée.                                     │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * ── TROIS SURFACES ──────────────────────────────────────────────────────────
 *
 *   PARENT       current_user = Parent · child_id = 0
 *                /my-account, panier, checkout, boutique, REST parent,
 *                et les onglets PARENT du dashboard (crédits, rapports…)
 *
 *   CHILD_APP    current_user = Parent · child_id = X        ← la cible
 *                Le dashboard Riada Kids. C'est NOTRE application : elle n'a
 *                aucune raison de dépendre de l'identité WordPress de l'enfant.
 *
 *   CHILD_TUTOR  current_user = Child  · child_id = X
 *                Uniquement là où Tutor LMS exige réellement l'identité de
 *                l'enfant pour autoriser l'accès (cours, leçon, quiz natifs).
 *                Substitution request-scoped, jamais de cookie.
 *
 * ── CONTRAINTE TECHNIQUE ────────────────────────────────────────────────────
 * Appelée depuis `determine_current_user`, donc AVANT l'existence de WP_Query.
 * Ni les conditional tags (is_account_page…) ni
 * $wp_query->query_vars['tutor_dashboard_page'] ne sont disponibles :
 * ils retourneraient toujours vide. La classification se fait exclusivement
 * sur REQUEST_URI, la route REST et l'action AJAX.
 *
 * ── SOURCES DES CHEMINS ─────────────────────────────────────────────────────
 * Aucun slug n'est inventé : ils sont soit dérivés des APIs WooCommerce/Tutor
 * quand elles sont disponibles à ce stade du boot, soit repris des listes déjà
 * déclarées dans le plugin (RK_MC_Tutor_Dashboard::$rk_slugs et
 * $blocked_slugs). Les valeurs sont mises en cache par requête.
 *
 * @package RK_Platform
 * @since   4.17.0 (phase 6b-1)
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class RK_Surface_Resolver {

	public const PARENT      = 'parent';
	public const CHILD_APP   = 'child_app';
	public const CHILD_TUTOR = 'child_tutor';

	/** @var string|null Mémo par requête. */
	private static ?string $surface = null;

	/** @var array<string,string>|null Chemins WooCommerce résolus. */
	private static ?array $wc_paths = null;

	/* ══════════════════════════════════════════════════════════════════
	 * Onglets du dashboard — surface MIXTE
	 *
	 * Découverte de l'audit 6a.1 : /dashboard/ héberge à la fois des pages
	 * enfant et des pages parent. Classer tout /dashboard/ en enfant faisait
	 * que rk-parent-board recevait l'identité de l'enfant comme $parent_id
	 * dès qu'un rk_tab traînait dans l'URL.
	 *
	 * Ces deux listes ne sont pas inventées : elles reprennent exactement
	 * RK_MC_Tutor_Dashboard::$rk_slugs et $blocked_slugs.
	 * ══════════════════════════════════════════════════════════════════ */

	/**
	 * Onglets du dashboard destinés au PARENT.
	 * Source : RK_MC_Tutor_Dashboard::$blocked_slugs — la liste des pages
	 * que le plugin interdit déjà à un rk_child, c'est-à-dire précisément
	 * les données financières et parentales.
	 */
	private const DASHBOARD_PARENT_TABS = array(
		'rk-parent-board',   // tableau de bord parent
		'rk-credits',        // crédits / achats
		'reports',           // rapports parent
		'bookings',          // réservations (vue parent)
		'settings',          // réglages du compte Tutor
		'purchase_history',
		'wishlist',
		'my-profile',
	);

	/**
	 * Onglets du dashboard destinés à l'ENFANT.
	 * Source : RK_MC_Tutor_Dashboard::$rk_slugs, moins les onglets parent.
	 */
	private const DASHBOARD_CHILD_TABS = array(
		'rk-messages', 'rk-badges', 'rk-skills', 'rk-sessions',
		'rk-certificats', 'rk-mon-parcours', 'rk-challenges',
		'rk-quiz-play', 'rk-adventure',
		'enrolled-courses', 'courses', 'certificates',
		'question-answer', 'my-quiz-attempts',
	);

	/**
	 * Actions admin-ajax.php s'exécutant en contexte enfant applicatif
	 * (données Riada Kids : notifications, badges, onboarding, chat).
	 */
	private const CHILD_APP_AJAX = array(
		'rk_experience_get_queue',
		'rk_experience_mark_seen',
		'rk_guidance_get',
		'rk_entry_story_get',
		'rk_onboarding_mark_seen',
		'rk_badge_mark_seen',
		'rk_mc_chat_load',
		'rk_mc_chat_send',
		'rk_mc_get_notifications',
		'rk_mc_mark_notifs_read',
		'rk_mc_filter_adventures',
	);

	/**
	 * Actions AJAX exigeant réellement current_user = Child.
	 * Classe C de l'audit 6a.1 — 1 seule action.
	 * Toute entrée ici doit être justifiée par une API Tutor/AYS qui lit
	 * current_user sans accepter de user_id explicite.
	 */
	private const CHILD_TUTOR_AJAX = array(
		'rk_quiz_play_submit',   // écrit dans wp_aysquiz_reports via AYS
	);

	/* ══════════════════════════════════════════════════════════════════
	 * Routes REST — cartographie exhaustive
	 *
	 * Deux namespaces coexistent dans le plugin, et ils ne se ressemblent
	 * pas :  rk-mc/v1  (module Children)  et  rk/v1  (module Coach).
	 * Une règle en 'rk/v1' seule ne matcherait JAMAIS les routes enfant —
	 * elles tomberaient toutes dans le défaut parent, et
	 * missions/{id}/progress renverrait 403.
	 * ══════════════════════════════════════════════════════════════════ */

	/** Routes REST toujours parent (gestion des enfants par le parent). */
	private const PARENT_REST = array(
		'/rk-mc/v1/children',    // GET/POST liste, PUT/DELETE item, stats, avatar
		'/rk/v1/coach',          // module Coach — hors périmètre parent/enfant
		'/wc/',
		'/wc-analytics/',
		'/wc-admin/',
	);

	/**
	 * Routes REST portant des données d'apprentissage de l'enfant.
	 * Surface child_app : current_user reste le PARENT, le child_id est
	 * résolu explicitement par RK_Identity_Context.
	 */
	private const CHILD_APP_REST = array(
		'/rk-mc/v1/missions',
	);

	/* ══════════════════════════════════════════════════════════════════
	 * API
	 * ══════════════════════════════════════════════════════════════════ */

	/** @return string PARENT | CHILD_APP | CHILD_TUTOR */
	public static function surface(): string {
		if ( null !== self::$surface ) return self::$surface;

		$surface = self::classify();

		/*
		 * Hotfix 4.18.1 — ne PAS mémoïser un résultat calculé avant que les
		 * permaliens soient disponibles.
		 *
		 * classify() peut être appelée très tôt (voir permalinks_ready()) et
		 * n'utilise alors que les chemins de repli. Figer ce résultat pour
		 * toute la requête classerait mal un site dont les pages WooCommerce
		 * ont des slugs personnalisés : /mon-espace-client/ ne serait pas
		 * reconnu comme surface parent, et le contexte enfant s'y
		 * appliquerait. On recalcule tant que le contexte n'est pas prêt.
		 */
		if ( self::permalinks_ready() ) {
			self::$surface = $surface;
		}

		return $surface;
	}

	/** Usage tests uniquement. */
	public static function reset(): void {
		self::$surface  = null;
		self::$wc_paths = null;
	}

	/* ══════════════════════════════════════════════════════════════════
	 * Classification
	 * ══════════════════════════════════════════════════════════════════ */

	private static function classify(): string {

		/* 1. Admin (hors ajax), CLI, cron → parent. */
		if ( is_admin() && ! wp_doing_ajax() )                return self::PARENT;
		if ( defined( 'WP_CLI' ) && WP_CLI )                  return self::PARENT;
		if ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) return self::PARENT;

		$path = self::request_path();

		/* 2. REST — la route prime sur le chemin. Parent d'abord. */
		if ( self::is_rest_request( $path ) ) {
			foreach ( self::PARENT_REST as $route ) {
				if ( false !== strpos( $path, $route ) ) return self::PARENT;
			}
			foreach ( self::CHILD_APP_REST as $route ) {
				if ( false !== strpos( $path, $route ) ) return self::CHILD_APP;
			}
			// Défaut : parent. Une route enfant non listée se voit
			// immédiatement (403), plutôt que d'hériter silencieusement
			// d'une identité enfant.
			return self::PARENT;
		}

		/* 3. admin-ajax.php — allow-list stricte. */
		if ( wp_doing_ajax() ) {
			// phpcs:ignore WordPress.Security.NonceVerification -- lecture seule, nonce vérifié par le handler
			$action = isset( $_REQUEST['action'] )
				? sanitize_key( wp_unslash( (string) $_REQUEST['action'] ) )
				: '';
			if ( in_array( $action, self::CHILD_TUTOR_AJAX, true ) ) return self::CHILD_TUTOR;
			if ( in_array( $action, self::CHILD_APP_AJAX,   true ) ) return self::CHILD_APP;
			return self::PARENT;
		}

		/* 4. Surfaces parent (WooCommerce + auth) — priorité absolue. */
		foreach ( self::parent_paths() as $prefix ) {
			if ( self::path_starts_with( $path, $prefix ) ) return self::PARENT;
		}

		/* 5. Dashboard — surface MIXTE, résolue par onglet. */
		$dash = self::dashboard_path();
		if ( self::path_starts_with( $path, $dash ) ) {
			$tab = self::segment_after( $path, $dash );

			// Racine du dashboard : contexte enfant si un enfant est
			// sélectionné, sinon page de choix côté parent. La surface reste
			// child_app — l'absence d'enfant est décidée par IdentityContext,
			// pas ici (le resolver ne connaît pas les identités).
			if ( '' === $tab ) return self::CHILD_APP;

			if ( in_array( $tab, self::DASHBOARD_PARENT_TABS, true ) ) return self::PARENT;
			if ( in_array( $tab, self::DASHBOARD_CHILD_TABS,  true ) ) return self::CHILD_APP;

			// Onglet Tutor natif non intercepté par le plugin → parent.
			return self::PARENT;
		}

		/* 6. Pages Tutor natives — seul endroit où Tutor exige l'enfant. */
		foreach ( self::tutor_paths() as $fragment ) {
			if ( false !== strpos( $path, $fragment ) ) return self::CHILD_TUTOR;
		}

		/* 7. Défaut : parent. */
		return self::PARENT;
	}

	/* ══════════════════════════════════════════════════════════════════
	 * Résolution des chemins — APIs d'abord, repli ensuite
	 * ══════════════════════════════════════════════════════════════════ */

	/**
	 * Chemins parent. Dérivés de WooCommerce quand ses fonctions sont déjà
	 * chargées ; sinon repli sur les slugs par défaut.
	 *
	 * Passer par wc_get_page_permalink() plutôt que par '/my-account' en dur
	 * couvre par construction les permaliens personnalisés ET traduits
	 * (Polylang renvoie l'URL de la page traduite) — sans que ce code ait à
	 * connaître la moindre langue.
	 *
	 * @return string[]
	 */
	/**
	 * Les APIs de permalien sont-elles utilisables MAINTENANT ?
	 *
	 * ⚠️ CORRECTIF CRITIQUE (hotfix 4.18.1) — cause d'une erreur fatale.
	 *
	 * classify() s'exécute depuis `determine_current_user`, qui peut être
	 * déclenché TRÈS tôt : n'importe quel plugin appelant is_user_logged_in()
	 * sur `plugins_loaded` (ici WPCode, puis Tutor) provoque
	 * _wp_get_current_user() avant que WordPress n'ait construit $wp_rewrite.
	 *
	 * À ce stade, wc_get_page_permalink() → get_permalink() → _get_page_link()
	 * appelle $wp_rewrite->get_page_permastruct() sur null :
	 *
	 *     Fatal error: Call to a member function get_page_permastruct() on null
	 *     wp-includes/link-template.php:435
	 *
	 * Une garde function_exists() ne suffit donc PAS : la fonction existe,
	 * mais son contexte n'est pas prêt. On vérifie l'objet global lui-même.
	 *
	 * Si ce n'est pas prêt, on utilise uniquement les chemins de repli — qui
	 * couvrent déjà les slugs par défaut et leurs variantes françaises.
	 */
	private static function permalinks_ready(): bool {
		return isset( $GLOBALS['wp_rewrite'] )
			&& $GLOBALS['wp_rewrite'] instanceof WP_Rewrite;
	}

	private static function parent_paths(): array {
		if ( null !== self::$wc_paths ) return self::$wc_paths;

		$paths = array();

		if ( self::permalinks_ready() && function_exists( 'wc_get_page_permalink' ) ) {
			foreach ( array( 'myaccount', 'cart', 'checkout', 'shop' ) as $page ) {
				$url = wc_get_page_permalink( $page );
				if ( is_string( $url ) && '' !== $url ) {
					$p = (string) wp_parse_url( $url, PHP_URL_PATH );
					if ( '' !== $p && '/' !== $p ) $paths[] = rtrim( $p, '/' );
				}
			}
		}

		// Repli + filet : conservés même quand l'API a répondu, car
		// wc_get_page_permalink() peut échouer si les pages ne sont pas
		// configurées, et d'anciens liens peuvent viser les slugs par défaut.
		$paths = array_merge( $paths, array(
			'/my-account', '/mon-compte',
			'/cart',       '/panier',
			'/checkout',   '/commande',
			'/shop',       '/boutique',
			'/product',    '/produit',
			'/wp-login.php',
		) );

		// Surfaces coach — hors périmètre parent/enfant, jamais de contexte enfant.
		$paths[] = '/espace-coach';
		$paths[] = '/connexion-coach';

		$paths = array_values( array_unique( $paths ) );

		// Mémoïser UNIQUEMENT si les APIs étaient disponibles : sinon on
		// figerait la liste de repli pour toute la requête, alors que les
		// permaliens personnalisés deviennent lisibles après `init`.
		if ( self::permalinks_ready() ) {
			self::$wc_paths = $paths;
		}

		return $paths;
	}

	/** Base du dashboard, depuis la constante du plugin. */
	private static function dashboard_path(): string {
		$dash = defined( 'RK_TUTOR_DASHBOARD_URL' ) ? RK_TUTOR_DASHBOARD_URL : '/dashboard/';
		return '/' . trim( (string) $dash, '/' );
	}

	/**
	 * Fragments d'URL des contenus Tutor natifs.
	 * Tutor expose sa base de permalien en option ; on la lit quand elle est
	 * disponible, sinon repli sur les slugs par défaut.
	 *
	 * @return string[]
	 */
	private static function tutor_paths(): array {
		$paths = array();

		// Même garde que parent_paths() : tutor_utils() peut aussi déclencher
		// des appels de permalien avant que $wp_rewrite n'existe.
		if ( self::permalinks_ready() && function_exists( 'tutor_utils' ) ) {
			$base = tutor_utils()->get_option( 'course_permalink_base' );
			if ( is_string( $base ) && '' !== $base ) {
				$paths[] = '/' . trim( $base, '/' ) . '/';
			}
		}

		return array_values( array_unique( array_merge( $paths, array(
			'/courses/', '/course/',
			'/lesson/', '/lessons/',
			'/quiz/', '/tutor_quiz/',
			'/assignments/', '/tutor_assignments/',

			/*
			 * P0-3 (6b-4-final) — CPT ays-quiz-maker.
			 *
			 * C'est l'URL que le coach envoie A L'ENFANT PAR E-MAIL :
			 * class-rk-coach-quizzes-controller.php:42 construit
			 * get_permalink( $quiz->custom_post_id ) -> /ays-quiz-maker/{slug}/
			 * puis y ajoute ?rk_tab=TOKEN (l. 623) avant wp_mail() (l. 648).
			 *
			 * Ce chemin manquait a l'allow-list depuis la phase 6b-1 : la
			 * surface etait donc 'parent', le token etait ignore, l'enfant
			 * n'etait jamais resolu et la tentative de quiz ne pouvait pas
			 * lui etre attribuee.
			 *
			 * Classe en CHILD_TUTOR et non child_app : le moteur AYS lit
			 * current_user pour attribuer la tentative dans
			 * wp_aysquiz_reports (cf. RK_MC_Quiz_Play_Ajax, classe C de
			 * l'audit 6a.1). L'identite enfant doit donc reellement etre
			 * exposee sur cette surface, le temps de la requete.
			 */
			'/ays-quiz-maker/',
		) ) ) );
	}

	/* ══════════════════════════════════════════════════════════════════
	 * Utilitaires
	 * ══════════════════════════════════════════════════════════════════ */

	private static function request_path(): string {
		$uri = isset( $_SERVER['REQUEST_URI'] )
			? esc_url_raw( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) )
			: '';
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		return '' === $path ? '/' : rtrim( $path, '/' );
	}

	/** Comparaison par segment : '/cart' ne matche pas '/cartographie'. */
	private static function path_starts_with( string $path, string $prefix ): bool {
		$prefix = rtrim( $prefix, '/' );
		if ( '' === $prefix ) return false;
		return $path === $prefix || 0 === strpos( $path, $prefix . '/' );
	}

	/** Premier segment après $base. '/dashboard/rk-badges/x' → 'rk-badges'. */
	private static function segment_after( string $path, string $base ): string {
		$base = rtrim( $base, '/' );
		if ( $path === $base ) return '';
		$rest = ltrim( substr( $path, strlen( $base ) ), '/' );
		if ( '' === $rest ) return '';
		$parts = explode( '/', $rest );
		return sanitize_key( $parts[0] );
	}

	private static function is_rest_request( string $path ): bool {
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) return true;
		$prefix = function_exists( 'rest_get_url_prefix' ) ? rest_get_url_prefix() : 'wp-json';
		return false !== strpos( $path, '/' . $prefix . '/' );
	}
}
