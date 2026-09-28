<?php
declare( strict_types=1 );
/**
 * RK_Session_Guard — cycle de vie de la session côté Child Dashboard.
 *
 * ┌─────────────────────────────────────────────────────────────────────────┐
 * │  PROBLÈME RÉSOLU (4.18.14)                                              │
 * │                                                                         │
 * │  Le Child Dashboard est rendu pendant que le PARENT est authentifié.    │
 * │  Tous les nonces imprimés dans ce HTML (wp_rest, _tutor_nonce,          │
 * │  rk_experience_layer, rk_mc_notifs_child…) sont liés à l'identité et au │
 * │  jeton de session du parent.                                            │
 * │                                                                         │
 * │  Quand le parent se déconnecte dans un autre onglet, l'onglet enfant    │
 * │  n'en sait RIEN : il continue son polling avec des nonces désormais     │
 * │  invalides. Chaque requête repart en 401/403 (rest_cookie_invalid_nonce)│
 * │  et le gestionnaire d'erreur global de Tutor LMS affiche son toast      │
 * │  rouge en bas de page, en boucle, à chaque cycle de polling.            │
 * │                                                                         │
 * │  Ce garde donne au navigateur les DEUX choses qui manquaient :          │
 * │    1. un signal cross-onglet, local, gratuit  → le cookie marqueur      │
 * │    2. une vérité serveur non protégée par nonce → /session/state        │
 * │  et une porte d'entrée propre au rechargement → guard_page_load()       │
 * └─────────────────────────────────────────────────────────────────────────┘
 *
 * @package RK_My_Children
 * @since   4.18.14
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class RK_Session_Guard {

	/**
	 * Cookie marqueur, LISIBLE PAR JS (volontairement non HttpOnly).
	 *
	 * Il ne contient AUCUN secret et n'authentifie rien : c'est une empreinte
	 * opaque de la session parent en cours. Le navigateur s'en sert pour
	 * répondre à une seule question, sans requête réseau :
	 * « la session parent qui a rendu cette page est-elle toujours la même ? »
	 */
	const MARKER_COOKIE = 'rk_parent_session';

	/** Namespace REST déjà utilisé par le plugin. */
	const REST_NS = 'rk/v1';

	public static function init(): void {
		add_action( 'init',             array( __CLASS__, 'sync_marker_cookie' ), 20 );
		add_action( 'wp_logout',        array( __CLASS__, 'clear_marker_cookie' ), 1 );
		add_action( 'rest_api_init',    array( __CLASS__, 'register_routes' ) );
		add_action( 'template_redirect', array( __CLASS__, 'no_cache_child_surfaces' ), 0 );
		add_action( 'template_redirect', array( __CLASS__, 'guard_page_load' ), 2 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_sentinel' ), 20 );
		add_action( 'wp_head',            array( __CLASS__, 'suppress_tutor_tour_for_children' ), 1 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'suppress_better_messages_for_children' ), 100 );
		add_filter( 'rk_notifications_should_enqueue', array( __CLASS__, 'suppress_riada_notifications_for_children' ) );
	}

	/* ══════════════════════════════════════════════════════════════════
	 * 1. Marqueur de session parent
	 * ══════════════════════════════════════════════════════════════════ */

	/**
	 * Empreinte opaque de la session parent courante.
	 *
	 * Dérivée de wp_hash() : ni le user ID ni le jeton de session ne sont
	 * dérivables de la valeur publiée, et la valeur change à chaque
	 * nouvelle connexion (jeton de session différent).
	 */
	public static function marker_value(): string {
		$parent = class_exists( 'RK_Identity_Context' )
			? RK_Identity_Context::authenticated_parent_id()
			: (int) get_current_user_id();

		if ( $parent <= 0 ) return '';

		$token = function_exists( 'wp_get_session_token' ) ? (string) wp_get_session_token() : '';

		return substr( wp_hash( 'rk_parent_marker|' . $parent . '|' . $token, 'auth' ), 0, 16 );
	}

	/**
	 * Pose ou retire le cookie marqueur pour qu'il reflète l'état réel.
	 *
	 * Aucun effet en admin, en REST, en AJAX ou en CLI : le cookie n'a de
	 * sens que sur une page front, et écrire un cookie sur une réponse REST
	 * provoquerait des warnings « headers already sent » côté serveurs qui
	 * bufferisent différemment.
	 */
	public static function sync_marker_cookie(): void {
		if ( is_admin() || wp_doing_ajax() || headers_sent() ) return;
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) return;
		if ( defined( 'WP_CLI' ) && WP_CLI ) return;

		$expected = self::marker_value();
		$current  = isset( $_COOKIE[ self::MARKER_COOKIE ] )
			? sanitize_text_field( wp_unslash( (string) $_COOKIE[ self::MARKER_COOKIE ] ) )
			: '';

		if ( $expected === $current ) return;   // rien à faire : cas le plus fréquent

		if ( '' === $expected ) {
			self::clear_marker_cookie();
			return;
		}

		setcookie(
			self::MARKER_COOKIE,
			$expected,
			array(
				'expires'  => 0,               // cookie de session navigateur
				'path'     => COOKIEPATH ? COOKIEPATH : '/',
				'domain'   => COOKIE_DOMAIN,
				'secure'   => is_ssl(),
				'httponly' => false,           // lu par rk-session-sentinel.js — par conception
				'samesite' => 'Lax',
			)
		);
		$_COOKIE[ self::MARKER_COOKIE ] = $expected;
	}

	/** Retire le marqueur : appelé sur wp_logout et quand plus aucun parent n'est authentifié. */
	public static function clear_marker_cookie(): void {
		if ( headers_sent() ) return;

		setcookie(
			self::MARKER_COOKIE,
			'',
			array(
				'expires'  => time() - YEAR_IN_SECONDS,
				'path'     => COOKIEPATH ? COOKIEPATH : '/',
				'domain'   => COOKIE_DOMAIN,
				'secure'   => is_ssl(),
				'httponly' => false,
				'samesite' => 'Lax',
			)
		);
		unset( $_COOKIE[ self::MARKER_COOKIE ] );
	}

	/* ══════════════════════════════════════════════════════════════════
	 * 2. Vérité serveur — GET /wp-json/rk/v1/session/state
	 * ══════════════════════════════════════════════════════════════════ */

	public static function register_routes(): void {
		register_rest_route( self::REST_NS, '/session/state', array(
			'methods'             => 'GET',
			/*
			 * PUBLIQUE ET SANS NONCE — c'est le point clé.
			 *
			 * Une route de diagnostic d'authentification ne peut pas exiger
			 * le nonce dont on cherche justement à savoir s'il est encore
			 * valide : elle échouerait exactement dans le cas qu'elle doit
			 * diagnostiquer. Elle ne divulgue rien : trois booléens et deux
			 * URL publiques, aucun identifiant, aucune donnée d'enfant.
			 */
			'permission_callback' => '__return_true',
			'callback'            => array( __CLASS__, 'rest_state' ),
		) );
	}

	public static function rest_state( WP_REST_Request $request ): WP_REST_Response {
		$parent    = class_exists( 'RK_Identity_Context' ) ? RK_Identity_Context::authenticated_parent_id() : 0;
		$tab_id    = class_exists( 'RK_Session_Manager' ) ? RK_Session_Manager::get_current_tab_id() : '';
		$tab_valid = false;

		if ( $tab_id && class_exists( 'RK_Session_Manager' ) ) {
			$tab_valid = is_array( RK_Session_Manager::validate_tab_session( $tab_id ) );
		}

		$usable = $tab_id ? $tab_valid : ( $parent > 0 );

		/*
		 * Le contexte de la session PERDUE est déterminé ICI, côté serveur,
		 * où parent_id est lisible. Le client n'a plus à le deviner à partir
		 * de « y a-t-il un parent connecté ? » — une question qui n'a jamais
		 * pu distinguer un logout parent d'une session enfant autonome.
		 */
		$rendered_under_parent = '1' === (string) $request->get_param( 'rup' );
		$lost = self::resolve_lost_context( $tab_id, $rendered_under_parent || $parent > 0 );

		$response = new WP_REST_Response( array(
			'valid'                => $usable,
			'reason'               => $usable ? '' : $lost['reason'],
			'redirect_url'         => $lost['redirect_url'],
			'parent_authenticated' => $parent > 0,
			'tab_session_valid'    => $tab_valid,
			/*
			 * « La session enfant peut-elle encore servir des requêtes
			 * protégées ? » — un onglet enfant reste utilisable soit parce
			 * que le parent est là, soit parce que la session onglet est
			 * autonome (login PIN direct, parent_id = 0).
			 */
			'child_session_usable' => $tab_valid,
			'marker'               => self::marker_value(),
			'account_url'          => self::account_url(),
			'login_url'            => self::login_url(),
		) );

		$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );

		return $response;
	}

	/* ══════════════════════════════════════════════════════════════════
	 * 2 bis. Exclusion de cache — TOUTE surface enfant
	 * ══════════════════════════════════════════════════════════════════ */

	/**
	 * Aucune page enfant ne doit jamais être servie depuis un cache.
	 *
	 * ── LE TROU COMBLÉ (4.18.16) ─────────────────────────────────────────
	 *
	 * Les deux gardes existants (RK_Session_Manager::resolve_user_from_tab()
	 * et rk-platform.php) sont conditionnés à la présence de ?rk_tab=. Ils
	 * couvrent donc l'enfant ouvert DEPUIS l'espace parent, et lui seul.
	 *
	 * Un enfant connecté DIRECTEMENT par PIN sur /connexion-child/ n'a pas
	 * de rk_tab : son cookie WordPress lui appartient en propre. Aucun des
	 * deux gardes ne se déclenchait, et /dashboard/ redevenait cacheable.
	 *
	 * Conséquence, identique au bug d'origine mais par un autre chemin :
	 * LiteSpeed peut servir à l'enfant B un HTML figé contenant le
	 * _tutor_nonce de l'enfant A (ou d'un visiteur anonyme). Le nonce ne
	 * correspond pas à l'utilisateur de la requête AJAX → Tutor LMS répond
	 * « autorisation refusée » → toast rouge, alors même que la session de
	 * l'enfant est parfaitement valide.
	 *
	 * Le garde est donc posé sur la SURFACE, pas sur la présence d'un token.
	 */
	public static function no_cache_child_surfaces(): void {
		if ( is_admin() || wp_doing_ajax() ) return;
		if ( ! class_exists( 'RK_Surface_Resolver' ) ) return;

		if ( RK_Surface_Resolver::PARENT === RK_Surface_Resolver::surface() ) return;

		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		do_action( 'litespeed_control_set_nocache', 'rk child surface' );
		nocache_headers();
	}

	/* ══════════════════════════════════════════════════════════════════
	 * 3. Garde au chargement de page (scénario D — refresh après logout)
	 * ══════════════════════════════════════════════════════════════════ */

	/**
	 * Une page enfant chargée avec un rk_tab mort ne doit jamais s'afficher
	 * à moitié : ni écran de login natif Tutor, ni dashboard vide qui va
	 * immédiatement produire des 403. Redirection immédiate, côté serveur,
	 * AVANT tout rendu.
	 */
	public static function guard_page_load(): void {
		if ( is_admin() || wp_doing_ajax() ) return;
		if ( ! class_exists( 'RK_Session_Manager' ) || ! class_exists( 'RK_Surface_Resolver' ) ) return;

		$tab_id = RK_Session_Manager::get_current_tab_id();
		if ( ! $tab_id ) return;   // pas d'onglet enfant : rien à garder ici

		$surface = RK_Surface_Resolver::surface();
		if ( RK_Surface_Resolver::PARENT === $surface ) return;   // le token y est déjà ignoré (6b-1)

		if ( is_array( RK_Session_Manager::validate_tab_session( $tab_id ) ) ) return;   // session vivante

		/*
		 * Session onglet morte (révoquée par le logout du parent, ou expirée).
		 * On sort du contexte enfant proprement, sans message technique.
		 */
		$lost   = self::resolve_lost_context(
			$tab_id,
			class_exists( 'RK_Identity_Context' ) && RK_Identity_Context::authenticated_parent_id() > 0
		);
		$target = $lost['redirect_url'];

		nocache_headers();
		wp_safe_redirect( $target, 302 );
		exit;
	}

	/* ══════════════════════════════════════════════════════════════════
	 * 4. Sentinelle JS
	 * ══════════════════════════════════════════════════════════════════ */

	/** Chargée uniquement sur les surfaces enfant — jamais sur /my-account/, le panier ou la boutique. */
	public static function enqueue_sentinel(): void {
		if ( ! class_exists( 'RK_Surface_Resolver' ) ) return;

		$surface = RK_Surface_Resolver::surface();
		if ( RK_Surface_Resolver::PARENT === $surface ) return;

		$suffix = ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ? '.js' : '.min.js';

		wp_enqueue_script(
			'rk-session-sentinel',
			RK_MC_URL . 'assets/js/rk-session-sentinel' . $suffix,
			array(),
			RK_MC_VERSION,
			false   // dans le <head> : doit observer les requêtes des autres scripts
		);

		wp_localize_script( 'rk-session-sentinel', 'rkSessionGuard', array(
			'markerCookie' => self::MARKER_COOKIE,
			'marker'       => self::marker_value(),
			'stateUrl'     => esc_url_raw( rest_url( self::REST_NS . '/session/state' ) ),
			'tabParam'     => class_exists( 'RK_Session_Manager' ) ? RK_Session_Manager::TAB_PARAM : 'rk_tab',
			'tabId'        => class_exists( 'RK_Session_Manager' ) ? RK_Session_Manager::get_current_tab_id() : '',
			'accountUrl'   => self::account_url(),
			'loginUrl'     => self::login_url(),
			/*
			 * La page a-t-elle été rendue alors qu'un parent était
			 * authentifié ? C'est le repli du client quand le serveur est
			 * injoignable : une session rendue sous un parent était une
			 * délégation, donc /my-account/.
			 */
			'renderedUnderParent' => ( class_exists( 'RK_Identity_Context' )
				&& RK_Identity_Context::authenticated_parent_id() > 0 ) ? 1 : 0,
			'i18n'         => array(
				'expired'  => __( 'انتهت الجلسة. جارٍ إعادة التوجيه…', 'rk-my-children' ),
			),
		) );
	}

	/* ══════════════════════════════════════════════════════════════════
	 * Résolution du CONTEXTE de la session perdue
	 * ══════════════════════════════════════════════════════════════════ */

	/**
	 * Vers où renvoyer quelqu'un dont la session enfant vient de mourir ?
	 *
	 * ── LA BONNE QUESTION ────────────────────────────────────────────────
	 *
	 * Ce n'est PAS « un parent est-il authentifié maintenant ? ». Juste après
	 * un logout parent, la réponse est toujours non — c'est le bug corrigé
	 * ici : les deux points de décision (guard_page_load et le sentinelle)
	 * en concluaient « donc c'est une session enfant autonome » et
	 * renvoyaient vers /connexion-child/, alors que la personne devant
	 * l'écran est le PARENT qui vient de se déconnecter.
	 *
	 * La bonne question est : « la session qui vient de mourir était-elle
	 * DÉLÉGUÉE ? ». Elle se lit dans la ligne de session elle-même, via
	 * parent_id — une donnée qui survit à la révocation (describe() lit les
	 * lignes révoquées et expirées, contrairement à find()).
	 *
	 *     parent_id > 0  → délégation d'un parent  → /my-account/
	 *     parent_id = 0  → session enfant autonome → /connexion-child/
	 *
	 * ── REPLI ────────────────────────────────────────────────────────────
	 *
	 * Si la ligne est introuvable (purge, session transient legacy jamais
	 * adoptée), on retombe sur l'indice le plus fiable restant : la page
	 * a-t-elle été rendue sous un parent authentifié ? Le paramètre
	 * $rendered_under_parent porte cette information depuis le client. En
	 * dernier recours, /my-account/ — parce que c'est la destination sûre :
	 * un parent y retrouve son espace, et un enfant y trouve un lien vers
	 * le sien, alors que l'inverse laisse un parent devant un clavier PIN.
	 *
	 * @param  string $tab_id
	 * @param  bool   $rendered_under_parent Page rendue avec un parent authentifié ?
	 * @return array{reason:string,redirect_url:string}
	 */
	public static function resolve_lost_context( string $tab_id, bool $rendered_under_parent = false ): array {
		$delegated = null;   // null = inconnu

		if ( $tab_id && class_exists( 'RKP_ChildSessionRepository' ) ) {
			$row = RKP_ChildSessionRepository::describe( $tab_id );
			if ( is_array( $row ) ) {
				$delegated = $row['parent_id'] > 0;
			}
		}

		if ( null === $delegated ) {
			$delegated = $rendered_under_parent;
		}

		if ( $delegated ) {
			return array(
				'reason'       => 'parent_session_ended',
				'redirect_url' => add_query_arg( 'rk_session', 'expired', self::account_url() ),
			);
		}

		return array(
			'reason'       => 'child_session_expired',
			'redirect_url' => add_query_arg( 'rk_session', 'expired', self::login_url() ),
		);
	}

	/* ══════════════════════════════════════════════════════════════════
	 * Tour de bienvenue Tutor LMS — désactivé sur les surfaces enfant
	 * ══════════════════════════════════════════════════════════════════ */

	/**
	 * Tutor LMS 4.x affiche un tour de bienvenue (5 diapositives, Alpine.js
	 * `tutorTour()` / `tutorModal()`) au premier login de chaque compte.
	 * Le modal est construit avec `isCloseable: false` : sans bouton actif,
	 * rien ne le referme (ni clic extérieur, ni Échap).
	 *
	 * ── POURQUOI IL BLOQUE SPÉCIFIQUEMENT LES COMPTES ENFANT ────────────
	 *
	 * Le compte `rk_child` est un compte WordPress à part entière (créé par
	 * RK_MC_Child_User_Account::create_for_child()), donc à ses yeux c'est
	 * un « nouvel utilisateur » Tutor comme un autre : le tour se déclenche.
	 * Mais l'interface RKD4 (Modules/Children) est une reconstruction
	 * complète du dashboard — ce tour décrit une UI que l'enfant ne voit
	 * jamais. Il n'a donc aucune valeur pour ce contexte et ne doit jamais
	 * s'afficher.
	 *
	 * ── POURQUOI CETTE APPROCHE, PLUTÔT QU'UNE META UTILISATEUR ──────────
	 *
	 * Tutor LMS ne documente pas publiquement la clé usermeta ni le nom
	 * d'option qui pilotent `is_tour_completed` pour la version 4.0.4 du
	 * plugin fourni. Écrire une clé devinée à la création du compte
	 * (`create_for_child()`) présenterait un risque silencieux : si la clé
	 * est fausse, rien ne le signale — le tour continuerait d'apparaître
	 * sans qu'aucune erreur ne le révèle. La neutralisation ci-dessous ne
	 * dépend d'aucun nom interne à Tutor : elle agit sur ce qui est
	 * observable et stable, le rendu du bloc lui-même.
	 *
	 * Deux couches, dans cet ordre :
	 *   1. CSS injecté dans <head>, avant tout rendu — masque le modal
	 *      immédiatement, sans le flash d'affichage qu'un correctif
	 *      purement JS (agissant après DOMContentLoaded) provoquerait.
	 *   2. JS, dans la même balise — empêche Alpine d'initialiser ce
	 *      composant précis, pour que rien ne reste focus-piégé derrière
	 *      un `display:none` (un lecteur d'écran ou Tab pourrait sinon
	 *      encore atteindre les éléments masqués).
	 *
	 * Aucune autre page (coach, parent, admin) n'est concernée : seules
	 * les surfaces enfant chargent ce garde.
	 */
	public static function suppress_tutor_tour_for_children(): void {
		if ( is_admin() || wp_doing_ajax() ) return;
		if ( ! class_exists( 'RK_Surface_Resolver' ) ) return;

		$surface = RK_Surface_Resolver::surface();
		if ( RK_Surface_Resolver::PARENT === $surface ) return;

		?>
<style id="rk-suppress-tutor-tour">
/* 4.18.17 — le tour de bienvenue Tutor décrit une UI que l'enfant ne voit
   jamais (dashboard RKD4 custom) ; masqué avant tout rendu pour éviter le
   flash, en plus du garde JS qui empêche Alpine de l'activer. */
/* :has() couvre les navigateurs récents ; #tutor-tour-modal (id fixe posé
   par Tutor) est le filet de sécurité pour les autres. */
.tutor-modal:has(.tutor-tour-images),
#tutor-tour-modal,
[aria-modal="true"]:has(#tutor-tour-modal) { display: none !important; }
</style>
<script id="rk-suppress-tutor-tour-js">
document.addEventListener( 'alpine:init', function () {
	if ( ! window.Alpine || typeof window.Alpine.data !== 'function' ) return;

	/*
	 * On laisse tutorTour()/tutorModal() se définir normalement (d'autres
	 * pages en ont besoin), mais on court-circuite CE composant précis :
	 * son propre x-data force initialOpen à false et open à rester false
	 * en permanence, quoi que le composant d'origine tente de faire.
	 */
	document.querySelectorAll( '[x-data*="tutorTour("]' ).forEach( function ( el ) {
		el.removeAttribute( 'x-data' );
		el.setAttribute( 'hidden', '' );
	} );
} );

/* Repli : si alpine:init a déjà été manqué (script chargé tard), on
   masque et on vide quand même le conteneur — jamais de display:none seul
   sur un élément qui resterait dans l'arbre d'accessibilité. */
document.addEventListener( 'DOMContentLoaded', function () {
	document.querySelectorAll( '[x-data*="tutorTour("]' ).forEach( function ( el ) {
		el.setAttribute( 'hidden', '' );
		var modal = el.querySelector( '.tutor-modal' );
		if ( modal ) modal.remove();
	} );
} );
</script>
		<?php
	}

	/* ══════════════════════════════════════════════════════════════════
	 * Better Messages — jamais chargé sur les surfaces enfant
	 * ══════════════════════════════════════════════════════════════════ */

	/**
	 * RiadaKids a sa propre messagerie custom pour l'enfant
	 * (Modules/Children/templates/dashboard/rk-messages.php, nonce
	 * `rk_mc_chat`, endpoint admin-ajax.php dédié). Cette UI lit les
	 * tables de Better Messages directement en PHP
	 * (RK_MC_Message_Service::load_bm_messages()) — elle n'appelle
	 * jamais l'API REST/AJAX native de Better Messages.
	 *
	 * Le plugin Better Messages, lui, enqueue son script front (handle
	 * `better-messages`, voir bp-better-messages.php::load_scripts(),
	 * accroché à wp_enqueue_scripts) pour TOUT utilisateur connecté,
	 * sans aucune vérification de rôle. Ce script poll
	 * /wp-json/better-messages/v1/checkNew toutes les ~12 secondes.
	 *
	 * Le rôle `rk_child` n'a que les capacités `read` et
	 * `rk_child_dashboard` (voir RK_MC_Child_User_Account::
	 * maybe_register_role()) — aucune capacité BuddyPress/Better
	 * Messages. Chaque appel `checkNew` échoue donc en 403, indéfiniment,
	 * sans jamais s'arrêter : rk-session-sentinel.js ne peut rien y
	 * faire, la session enfant restant par ailleurs parfaitement valide
	 * (ce n'est pas un problème de session, mais de permissions absentes
	 * par design pour ce rôle).
	 *
	 * On désenqueue donc ce script uniquement sur les surfaces enfant —
	 * jamais sur /my-account/, le coach, l'admin ou la boutique.
	 */
	public static function suppress_better_messages_for_children(): void {
		if ( is_admin() || wp_doing_ajax() ) return;
		if ( ! class_exists( 'RK_Surface_Resolver' ) ) return;

		$surface = RK_Surface_Resolver::surface();
		if ( RK_Surface_Resolver::PARENT === $surface ) return;

		wp_dequeue_script( 'better-messages' );
		wp_dequeue_style( 'better-messages' );
		wp_dequeue_script( 'better-messages-i18n' );
		wp_dequeue_script( 'better-messages-i18n-inline' );
		wp_deregister_script( 'better-messages' );
		wp_deregister_style( 'better-messages' );
	}

	/* ══════════════════════════════════════════════════════════════════
	 * Riada Notifications (plugin séparé) — jamais sur les surfaces enfant
	 * ══════════════════════════════════════════════════════════════════ */

	/**
	 * Le plugin "Riada Kids - Notifications" (riada-notifications.php)
	 * enqueue son script pour tout utilisateur connecté
	 * (is_user_logged_in() uniquement, voir
	 * rk_notifications_enqueue_assets()) — sans distinction de rôle. Son
	 * polling AJAX (action rk_get_count, toutes les 15 s par défaut) vérifie
	 * un nonce (RK_NOTIFICATIONS_NONCE_ACTION = 'rk_notifications') via
	 * check_ajax_referer(), qui répond en 400 dès que le nonce ne
	 * correspond plus à l'utilisateur/contexte résolu au moment de l'appel.
	 *
	 * RiadaKids Platform a son propre système de notifications enfant
	 * (RK_MC_Notification_Service) — Riada Notifications fait donc doublon
	 * sur les surfaces enfant, avec le même risque de nonce désynchronisé
	 * que Better Messages (page rendue via `rk_tab`/contexte délégué,
	 * identité résolue différemment entre le rendu et l'appel AJAX).
	 *
	 * Le plugin expose lui-même le filtre `rk_notifications_should_enqueue`
	 * pour ce cas précis (voir riada-notifications.php) : on s'en sert
	 * plutôt que de dequeue après coup.
	 *
	 * @param  bool $should_enqueue
	 * @return bool
	 */
	public static function suppress_riada_notifications_for_children( bool $should_enqueue ): bool {
		if ( is_admin() || wp_doing_ajax() ) return $should_enqueue;
		if ( ! class_exists( 'RK_Surface_Resolver' ) ) return $should_enqueue;

		$surface = RK_Surface_Resolver::surface();
		if ( RK_Surface_Resolver::PARENT === $surface ) return $should_enqueue;

		return false;
	}

	/* ══════════════════════════════════════════════════════════════════
	 * Helpers
	 * ══════════════════════════════════════════════════════════════════ */

	public static function account_url(): string {
		if ( function_exists( 'wc_get_page_permalink' ) ) {
			$url = wc_get_page_permalink( 'myaccount' );
			if ( $url ) return esc_url_raw( $url );
		}
		return esc_url_raw( home_url( '/my-account/' ) );
	}

	public static function login_url(): string {
		return esc_url_raw( home_url( '/connexion-child/' ) );
	}
}