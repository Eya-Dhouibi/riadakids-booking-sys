<?php
declare( strict_types=1 );
/**
 * RK_Identity_Context — les TROIS identités d'une requête.
 *
 * ┌──────────────────────────────────────────────────────────────────────────┐
 * │                        WORDPRESS AUTH                                     │
 * │                              │                                            │
 * │                              ▼                                            │
 * │                        Parent #42                                         │
 * │                              │                                            │
 * │                              ▼                                            │
 * │                    RK Identity Context                                    │
 * │                       ┌──────┴──────┐                                     │
 * │                       ▼             ▼                                     │
 * │                  child_id      authenticated_parent_id                    │
 * │                  Child #108         Parent #42                            │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * ── 1. AUTHENTICATED_USER ───────────────────────────────────────────────────
 *   authenticated_parent_id() — le parent réellement authentifié par le cookie
 *   WordPress. C'est LA source de vérité pour toute décision d'autorisation.
 *
 *   ⚠️ RÈGLE ABSOLUE : cette méthode ne dépend NI de get_current_user_id(),
 *   NI du filtre determine_current_user. Elle lit et valide le cookie
 *   d'origine. Son résultat est donc identique que la substitution Tutor soit
 *   active ou non — sans quoi on obtiendrait :
 *
 *       current_user = Child ──► authenticated_parent_id() ──► Child   ✗
 *
 *   ce qui détruirait précisément la séparation recherchée.
 *
 *   Distinction à garder en tête :
 *
 *       authenticated_parent_id()  →  cookie WordPress d'origine  →  Parent
 *       get_current_user_id()      →  identité de LA REQUÊTE      →  peut
 *                                     exceptionnellement être l'enfant, et
 *                                     uniquement sur une surface Tutor.
 *
 * ── 2. ACTING_CHILD ─────────────────────────────────────────────────────────
 *   child_id() / child_wp_uid() — l'enfant sélectionné par le parent.
 *   Sémantique : « Parent 42 agit actuellement comme Enfant 108 »,
 *   jamais « ce navigateur est connecté en tant qu'Enfant 108 ».
 *
 *   OWNERSHIP-SAFE : un child_id n'est jamais retourné sur la seule foi de ce
 *   que le navigateur a envoyé. La chaîne est toujours :
 *
 *       authenticated_parent_id()
 *              ↓
 *       résolution du token/session
 *              ↓
 *       child_id candidat
 *              ↓
 *       vérification child.user_id === parent   ← non contournable
 *              ↓
 *       child_id
 *
 *   Échec de n'importe quel maillon ⇒ 0. Jamais de repli sur un autre enfant.
 *
 * ── 3. TUTOR_CURRENT_USER ───────────────────────────────────────────────────
 *   is_tutor_child_context() — vrai uniquement là où Tutor LMS exige
 *   réellement l'identité de l'enfant. Seul cas où get_current_user_id() peut
 *   différer de authenticated_parent_id(). Request-scoped et surface-scoped ;
 *   jamais cookie-scoped, jamais persistant.
 *
 * @package RK_Platform
 * @since   4.17.0 (phase 6b-1)
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class RK_Identity_Context {

	/** @var int|null Mémo — parent authentifié par cookie. */
	private static ?int $parent_id = null;

	/** @var object|null|false Mémo — enfant validé (false = pas encore résolu). */
	private static $child = false;

	/* ══════════════════════════════════════════════════════════════════
	 * 1. AUTHENTICATED_USER
	 * ══════════════════════════════════════════════════════════════════ */

	/**
	 * Parent réellement authentifié par le cookie WordPress.
	 *
	 * N'appelle JAMAIS get_current_user_id() — voir l'en-tête de classe.
	 *
	 * @return int 0 si non authentifié par cookie (JWT, CLI, visiteur anonyme).
	 */
	public static function authenticated_parent_id(): int {
		if ( null !== self::$parent_id ) return self::$parent_id;

		// wp_validate_auth_cookie() lit $_COOKIE directement : ni récursion
		// via notre propre filtre, ni influence de la substitution en cours.
		$uid = (int) wp_validate_auth_cookie( '', 'logged_in' );
		self::$parent_id = $uid > 0 ? $uid : 0;

		return self::$parent_id;
	}

	/**
	 * L'authentification vient-elle d'un cookie WordPress ?
	 *
	 * Faux en REST authentifié par JWT (module coach), en WP-CLI et en cron.
	 * Dans ces cas authenticated_parent_id() retourne 0 : c'est « pas de
	 * parent par cookie », et non « pas de parent du tout ». Les appelants
	 * doivent distinguer les deux plutôt que de conclure à un visiteur anonyme.
	 */
	public static function has_cookie_authentication(): bool {
		return self::authenticated_parent_id() > 0;
	}

	/* ══════════════════════════════════════════════════════════════════
	 * 2. ACTING_CHILD
	 * ══════════════════════════════════════════════════════════════════ */

	/**
	 * ID rk_children de l'enfant sélectionné — appartenance déjà vérifiée.
	 *
	 * ⚠️ Retourne TOUJOURS 0 sur une surface parent (§19 TEST C et D), même
	 * si le navigateur présente un token valide. Le token n'est pas rejeté,
	 * il est simplement ignoré : pas de redirection, pas d'erreur, la page
	 * parent s'affiche normalement avec l'identité du parent.
	 *
	 * C'est ce qui garantit qu'aucun code appelé depuis /my-account/ ou
	 * depuis /dashboard/rk-parent-board/ ne puisse utiliser l'enfant à la
	 * place du parent — y compris par erreur de programmation future.
	 *
	 * @return int 0 si surface parent, aucun enfant, token invalide/expiré,
	 *             ou enfant n'appartenant pas au parent authentifié.
	 */
	public static function child_id(): int {
		if ( self::is_parent_surface() ) return 0;

		$child = self::resolve_child();
		return $child ? (int) $child->id : 0;
	}

	/**
	 * wp_user_id de ce même enfant.
	 *
	 * DÉRIVÉ du child validé — jamais une seconde identité indépendante qui
	 * pourrait diverger de child_id(). Si l'enfant existe mais que son compte
	 * WP est absent ou invalide, retourne 0 : aucun repli vers le parent.
	 */
	public static function child_wp_uid(): int {
		if ( self::is_parent_surface() ) return 0;   // cohérent avec child_id()

		$child = self::resolve_child();
		if ( ! $child ) return 0;

		$wp_uid = (int) ( $child->wp_user_id ?? 0 );
		if ( $wp_uid <= 0 ) return 0;

		// L'enregistrement peut pointer vers un compte supprimé.
		return get_user_by( 'id', $wp_uid ) ? $wp_uid : 0;
	}

	/** L'enregistrement enfant validé, ou null. */
	public static function child(): ?object {
		if ( self::is_parent_surface() ) return null;

		$child = self::resolve_child();
		return $child ?: null;
	}

	/**
	 * Un enfant est-il sélectionné et utilisable sur cette surface ?
	 * Faux sur toute surface parent, par construction.
	 */
	public static function is_child_context(): bool {
		return self::child_id() > 0;
	}

	/* ══════════════════════════════════════════════════════════════════
	 * 2 bis. SELECTED_CHILD — filtre de consultation PARENT
	 *
	 * ⚠️ CE N'EST PAS UNE IDENTITÉ. C'est un filtre d'affichage.
	 *
	 *     « Le parent 42 REGARDE actuellement les données de l'enfant 108 »
	 *
	 * à ne jamais confondre avec ACTING_CHILD :
	 *
	 *     « Le parent 42 AGIT actuellement en tant qu'enfant 108 »
	 *
	 * Sur une surface parent, les deux coexistent sans se contredire :
	 *
	 *     selected_child_id() = 108      ← le parent consulte cet enfant
	 *     child_id()          = 0        ← aucune identité enfant en jeu
	 *     child_wp_uid()      = 0
	 *     current_user        = 42
	 *
	 * Origine du concept : ?child_id= a TOUJOURS signifié une consultation
	 * parent — son docblock d'origine (RK_MC_Child_Context v5.3.0) vérifie
	 * l'ownership « child.user_id === parent connecté », et l'exemple donné
	 * est une URL sous /my-account/. rk-parent-board.php l'utilise d'ailleurs
	 * comme un filtre avec valeur par défaut (le premier enfant) : une
	 * identité n'a pas de valeur par défaut, un filtre si.
	 * ══════════════════════════════════════════════════════════════════ */

	/** @var int|null Mémo. */
	private static ?int $selected_child_id = null;

	/**
	 * Enfant que le parent consulte via ?child_id=, ownership vérifié.
	 *
	 * Chaîne :
	 *     authenticated_parent_id()
	 *            ↓
	 *     ?child_id= (paramètre client, jamais cru sur parole)
	 *            ↓
	 *     rk_children.user_id === authenticated_parent_id()
	 *            ↓
	 *     selected_child_id
	 *
	 * @return int 0 si absent, invalide, ou n'appartenant pas au parent
	 *             authentifié (invariant 4 : cross-parent impossible).
	 */
	public static function selected_child_id(): int {
		if ( null !== self::$selected_child_id ) return self::$selected_child_id;
		self::$selected_child_id = 0;

		$parent_id = self::authenticated_parent_id();
		if ( $parent_id <= 0 ) return 0;

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- lecture seule d'un filtre d'affichage, ownership vérifié ci-dessous
		$raw = isset( $_GET['child_id'] ) ? absint( wp_unslash( $_GET['child_id'] ) ) : 0;
		if ( $raw <= 0 ) return 0;

		if ( ! function_exists( 'rk_mc_children_table' ) ) return 0;

		global $wpdb;
		$child = $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM ' . rk_mc_children_table() . ' WHERE id = %d LIMIT 1',
			$raw
		) );
		if ( ! $child || ! self::belongs_to_parent( $child, $parent_id ) ) return 0;

		self::$selected_child_id = (int) $child->id;
		return self::$selected_child_id;
	}

	/* ══════════════════════════════════════════════════════════════════
	 * 3. TUTOR_CURRENT_USER
	 * ══════════════════════════════════════════════════════════════════ */

	/**
	 * Tutor exige-t-il l'identité enfant sur cette requête ?
	 *
	 * Seul cas autorisant get_current_user_id() !== authenticated_parent_id().
	 *
	 * RÈGLE PRINCIPALE (inchangée) : sur child_tutor, la substitution
	 * s'applique toujours — Tutor y exige réellement l'identité enfant
	 * pour ses propres vérifications d'accès (inscriptions, progression).
	 *
	 * RÈGLE AJOUTÉE (isolation parent/enfant — logout du parent) :
	 *
	 *   Sur child_app (le dashboard Riada Kids), current_user reste
	 *   normalement le PARENT — notre application n'a aucune raison de
	 *   dépendre de l'identité WordPress de l'enfant, elle lit
	 *   child_id() directement.
	 *
	 *   MAIS une partie du chrome de /dashboard/ est rendue par Tutor LMS
	 *   lui-même, qui appelle is_user_logged_in() sans rien savoir de
	 *   rk_tab. Si le parent se déconnecte pendant que l'enfant a son
	 *   propre onglet ouvert avec une session valide, il n'existe plus
	 *   AUCUN current_user WordPress : Tutor affiche alors son propre
	 *   écran de login natif, alors que la session de l'enfant, elle,
	 *   n'a jamais expiré.
	 *
	 *   Correctif : si aucun parent n'est authentifié ET qu'une session
	 *   enfant valide existe sur child_app, on autorise la substitution
	 *   ici aussi — juste ce qu'il faut pour que Tutor voie un
	 *   current_user et continue de rendre la page normalement. Le
	 *   dashboard Riada Kids lui-même n'est pas affecté : il lit déjà
	 *   child_id(), jamais get_current_user_id().
	 *
	 *   Dès que le parent est présent, cette règle ne s'applique plus :
	 *   current_user redevient le parent sur child_app, comme prévu par
	 *   l'architecture d'origine (phase 6b-1).
	 */
	public static function is_tutor_child_context(): bool {
		$surface = self::surface();

		if ( RK_Surface_Resolver::CHILD_TUTOR === $surface ) {
			return self::child_wp_uid() > 0;
		}

		if ( RK_Surface_Resolver::CHILD_APP === $surface
			&& self::authenticated_parent_id() <= 0 ) {
			return self::child_wp_uid() > 0;
		}

		return false;
	}

	/* ══════════════════════════════════════════════════════════════════
	 * Surface
	 * ══════════════════════════════════════════════════════════════════ */

	/** @return string parent | child_app | child_tutor */
	public static function surface(): string {
		return RK_Surface_Resolver::surface();
	}

	/** Surface parent : l'identité ne doit jamais y basculer. */
	public static function is_parent_surface(): bool {
		return RK_Surface_Resolver::PARENT === self::surface();
	}

	/* ══════════════════════════════════════════════════════════════════
	 * Invariant architectural
	 * ══════════════════════════════════════════════════════════════════ */

	/**
	 * INVARIANT — sur toute surface parent :
	 *
	 *     get_current_user_id() === authenticated_parent_id()
	 *
	 * Violé signifie qu'un chemin a substitué l'identité hors des surfaces
	 * Tutor : c'est la régression exacte que 6a a corrigée. On journalise
	 * plutôt que de lever, pour ne pas casser une page en production.
	 */
	public static function assert_invariant(): bool {
		if ( ! self::is_parent_surface() ) return true;

		$current = (int) get_current_user_id();
		$parent  = self::authenticated_parent_id();
		if ( $current === $parent ) return true;

		if ( function_exists( 'rkp_log' ) ) {
			rkp_log( sprintf(
				'[RK Identity] INVARIANT VIOLÉ — surface=parent current_user=%d authenticated_parent=%d uri=%s',
				$current,
				$parent,
				isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) ) : '?'
			) );
		}
		return false;
	}

	/** Usage tests uniquement. */
	public static function reset(): void {
		self::$parent_id         = null;
		self::$child             = false;
		self::$selected_child_id = null;
		RK_Surface_Resolver::reset();
	}

	/* ══════════════════════════════════════════════════════════════════
	 * Résolution de l'enfant — le seul endroit qui décide
	 * ══════════════════════════════════════════════════════════════════ */

	/**
	 * Résout l'enfant actif, appartenance vérifiée. Une seule fois par requête.
	 *
	 * Deux sources, dans cet ordre :
	 *   1. ?child_id= via RK_MC_Child_Context (ownership déjà vérifié par
	 *      RK_MC_Child_Repository::get_child( $child_id, $parent ))
	 *   2. session ?rk_tab= (transient serveur)
	 *
	 * Dans les deux cas, l'appartenance est revérifiée ici contre
	 * authenticated_parent_id(). Aucune confiance n'est accordée à ce que le
	 * navigateur envoie.
	 *
	 * @return object|null
	 */
	private static function resolve_child(): ?object {
		if ( false !== self::$child ) return self::$child;
		self::$child = null;

		$parent_id = self::authenticated_parent_id();

		/* ── Source 1 : contexte ?child_id= ── */
		if ( class_exists( 'RK_MC_Child_Context' ) ) {
			$child = RK_MC_Child_Context::get_active_child();
			if ( $child && self::belongs_to_parent( $child, $parent_id ) ) {
				self::$child = $child;
				return self::$child;
			}
		}

		/* ── Source 2 : session ?rk_tab= ── */
		$child = self::resolve_child_from_tab( $parent_id );
		if ( $child ) self::$child = $child;

		return self::$child;
	}

	/**
	 * Enfant porté par le token rk_tab, appartenance vérifiée.
	 *
	 * Note transitoire : rk_tab reste la source de session en 6b-1. Le
	 * remplacement par wp_rk_child_sessions est prévu en 6b-4 — cette méthode
	 * est le SEUL point à réécrire à ce moment-là.
	 */
	private static function resolve_child_from_tab( int $parent_id ): ?object {
		if ( ! class_exists( 'RK_Session_Manager' ) ) return null;

		$tab_id = RK_Session_Manager::get_current_tab_id();
		if ( ! $tab_id ) return null;

		$session = RK_Session_Manager::validate_tab_session( $tab_id );
		if ( ! is_array( $session ) || empty( $session['child_wp_uid'] ) ) return null;

		/*
		 * Le token porte le parent qui l'a créé. Si le visiteur n'est pas ce
		 * parent, on refuse — c'est le TEST G : Parent B présentant un token
		 * appartenant à Parent A obtient child_id = 0.
		 *
		 * Cas parent_wp_uid = 0 : session créée sans parent connecté (login
		 * enfant direct). L'appartenance ne peut alors pas être établie ; on
		 * accepte l'enfant mais sans le rattacher à un parent, conformément à
		 * la consigne « ne pas deviner ». La vérification finale ci-dessous
		 * reste appliquée dès qu'un parent est authentifié.
		 */
		$token_parent = (int) ( $session['parent_wp_uid'] ?? 0 );
		if ( $token_parent > 0 && $parent_id > 0 && $token_parent !== $parent_id ) {
			return null;
		}

		global $wpdb;
		if ( ! function_exists( 'rk_mc_children_table' ) ) return null;

		$child = $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM ' . rk_mc_children_table() . ' WHERE wp_user_id = %d LIMIT 1',
			(int) $session['child_wp_uid']
		) );
		if ( ! $child ) return null;

		// Vérification finale d'appartenance dès qu'un parent est authentifié.
		if ( $parent_id > 0 && ! self::belongs_to_parent( $child, $parent_id ) ) {
			return null;
		}

		return $child;
	}

	/** child.user_id === parent authentifié. */
	private static function belongs_to_parent( object $child, int $parent_id ): bool {
		if ( $parent_id <= 0 ) return false;
		return (int) ( $child->user_id ?? 0 ) === $parent_id;
	}
}
