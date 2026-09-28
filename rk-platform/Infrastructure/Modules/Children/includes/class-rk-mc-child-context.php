<?php
/**
 * RK_MC_Child_Context  (v5.3.0 — Sprint 1)
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * RÔLE
 * ─────────────────────────────────────────────────────────────────────────────
 * Gère le contexte "enfant actif" pour la durée de la requête courante.
 *
 * CHANGEMENTS v5.3.0 (Sprint 1)
 * ─────────────────────────────────────────────────────────────────────────────
 * SUPPRIMÉ : session PHP ($_SESSION) — incompatible avec certains hébergeurs,
 *             cassé par les caches WooCommerce, instable en production.
 *
 * NOUVEAU : lecture directe de ?child_id=XX dans l'URL + vérification
 *            ownership strict (child.user_id === parent connecté).
 *
 * C'est la méthode la plus simple et la plus robuste :
 *   /my-account/child-dashboard/?child_id=12
 *   → validation → rendu dashboard de l'enfant 12
 *
 * SÉCURITÉ
 * ─────────────────────────────────────────────────────────────────────────────
 *   - Ownership vérifié à chaque requête via RK_MC_Child_Repository::get_child()
 *   - Un parent ne peut jamais accéder au dashboard d'un enfant qui ne lui appartient pas
 *   - Pas de donnée persistante côté client
 *
 * EXTENSIBILITÉ
 * ─────────────────────────────────────────────────────────────────────────────
 *   add_filter( 'rk_mc_active_child', function( $child ) { return $child; } );
 *   add_action( 'rk_mc_child_context_loaded', function( $child, $user_id ) {} );
 *
 * @package RK_My_Children
 * @since   5.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class RK_MC_Child_Context {

    /**
     * Enfant actif mis en cache pour la requête courante uniquement.
     *
     * @var object|null
     */
    private static $active_child = null;

    /**
     * Indique si la résolution a déjà été tentée (évite les requêtes répétées).
     *
     * @var bool
     */
    private static $resolved = false;

    /* ─────────────────────────────────────────
     * Init
     * ───────────────────────────────────────── */

    public static function init() {
        // Résoudre le contexte enfant dès que l'utilisateur est identifié
        add_action( 'wp', array( __CLASS__, 'maybe_set_active_child' ) );

        // Injecter les classes CSS + data-attribute
        add_filter( 'body_class', array( __CLASS__, 'body_class' ) );
    }

    /* ─────────────────────────────────────────
     * Résolution de l'enfant actif
     * ───────────────────────────────────────── */

    /**
     * Lire ?child_id= depuis l'URL, valider ownership, charger l'enfant.
     * Exécuté sur le hook 'wp' (utilisateur WordPress identifié).
     */
    public static function maybe_set_active_child() {

        if ( self::$resolved ) {
            return;
        }
        self::$resolved = true;

        if ( ! is_user_logged_in() ) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $child_id_raw = isset( $_GET['child_id'] ) ? $_GET['child_id'] : null;

        if ( null === $child_id_raw ) {
            return;
        }

        $child_id = absint( $child_id_raw );
        if ( $child_id <= 0 ) {
            return;
        }

        $user_id = get_current_user_id();

        // Vérification ownership via le Repository (source de vérité)
        $child = RK_MC_Child_Repository::get_child( $child_id, $user_id );

        if ( ! $child ) {
            // child_id invalide ou n'appartient pas au parent connecté — ignorer silencieusement
            return;
        }

        self::$active_child = $child;

        /**
         * Action déclenchée quand le contexte enfant est résolu.
         *
         * @param object $child   Enregistrement wp_rk_children
         * @param int    $user_id ID du parent connecté
         */
        do_action( 'rk_mc_child_context_loaded', $child, $user_id );
    }

    /* ─────────────────────────────────────────
     * API publique
     * ───────────────────────────────────────── */

    /**
     * Retourne l'enfant actif pour cette requête.
     *
     * @return object|null
     */
    public static function get_active_child(): ?object {
        /**
         * Filtre : permet aux modules tiers d'enrichir l'enfant actif.
         *
         * @param object|null $child
         */
        return apply_filters( 'rk_mc_active_child', self::$active_child );
    }

    /**
     * @return int  ID enfant actif, ou 0.
     */
    public static function get_active_child_id(): int {
        $child = self::get_active_child();
        return $child ? (int) $child->id : 0;
    }

    /**
     * @return bool
     */
    public static function has_active_child(): bool {
        return null !== self::get_active_child();
    }

    /**
     * Réinitialiser le contexte (utile pour les tests unitaires).
     */
    public static function clear_active_child(): void {
        self::$active_child = null;
        self::$resolved     = false;
    }

    /* ─────────────────────────────────────────
     * URL Builder
     * ───────────────────────────────────────── */

    /**
     * URL du dashboard Tutor LMS pour un enfant.
     * Conservée pour rétrocompatibilité (child-card.php, REST).
     *
     * @param  int    $child_id
     * @return string
     */
    public static function get_dashboard_url( int $child_id ): string {
        return add_query_arg(
            'child_id',
            absint( $child_id ),
            home_url( RK_TUTOR_DASHBOARD_URL )
        );
    }

    /**
     * URL du Child Dashboard (Sprint 1).
     *
     * @param  int    $child_id
     * @return string
     */
    public static function get_child_dashboard_url( int $child_id ): string {
        return add_query_arg(
            'child_id',
            absint( $child_id ),
            wc_get_account_endpoint_url( RK_MC_Endpoint::CHILD_DASHBOARD_SLUG )
        );
    }

    /**
     * URL de réservation avec child_id pré-sélectionné.
     *
     * @param  int    $child_id
     * @return string
     */
    public static function get_booking_url( int $child_id ): string {
        $base = wc_get_account_endpoint_url( 'book-session' );
        return add_query_arg( 'child_id', absint( $child_id ), $base );
    }

    /**
     * URL de la fiche complète (parent) — /my-account/child-profile/?child_id=X
     * Page en lecture seule pour le parent : reprend les données du
     * Child Dashboard (adventures, badges, XP, sessions, dernier rapport)
     * sans les interactions propres à l'espace enfant.
     *
     * @param  int    $child_id
     * @return string
     */
    public static function get_full_profile_url( int $child_id ): string {
        return add_query_arg(
            'child_id',
            absint( $child_id ),
            wc_get_account_endpoint_url( RK_MC_Endpoint::PROFILE_SLUG )
        );
    }

    /**
     * URL historique (conservé pour compatibilité REST).
     *
     * @param  int    $child_id
     * @return string
     */
    public static function get_history_url( int $child_id ): string {
        $base = wc_get_account_endpoint_url( 'my-children' );
        return add_query_arg(
            array( 'view' => 'history', 'child_id' => absint( $child_id ) ),
            $base
        );
    }

    /* ─────────────────────────────────────────
     * Body class
     * ───────────────────────────────────────── */

    public static function body_class( array $classes ): array {
        if ( self::has_active_child() ) {
            $classes[] = 'rk-child-context-active';
            $classes[] = 'rk-child-' . self::get_active_child_id();
        }
        return $classes;
    }
}
