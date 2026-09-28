<?php
declare( strict_types=1 );
/**
 * RK_MC_Child_User  (v5.4.0 — Sprint 2)
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * RÔLE
 * ─────────────────────────────────────────────────────────────────────────────
 * Gère le cycle de vie du wp_user dédié à chaque enfant :
 *   - Création automatique à l'ajout d'un enfant
 *   - Suppression à la suppression de l'enfant
 *   - Migration des enfants existants (outil admin)
 *   - Génération du lien de switch sécurisé parent → espace enfant
 *
 * SWITCH MÉCANISME (Option A — nouvel onglet)
 * ─────────────────────────────────────────────────────────────────────────────
 *   1. Le parent clique "فضاء [enfant]" → nouvel onglet s'ouvre
 *   2. L'URL contient un token one-time signé (durée 5 min)
 *   3. Sur init : validation token → session onglet rk_tab (phase 6a —
 *      plus aucun wp_set_auth_cookie() pour un enfant)
 *   4. Redirect vers /dashboard/ (Tutor LMS)
 *   5. L'onglet parent reste intact, session parent préservée.
 *
 * SÉCURITÉ
 * ─────────────────────────────────────────────────────────────────────────────
 *   - Token one-time (consommé dès la validation, supprimé du transient)
 *   - Lié à child_id + parent_id : impossible à rejouer par un autre parent
 *   - TTL 5 minutes
 *   - L'enfant créé possède le rôle rk_child (accès restreint)
 *
 * @package RK_My_Children
 * @since   5.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* Traits — factorisation par fonctionnalité (logique inchangée) */
require_once __DIR__ . '/traits/trait-rk-mc-child-user-account.php';
require_once __DIR__ . '/traits/trait-rk-mc-child-user-auth.php';
require_once __DIR__ . '/traits/trait-rk-mc-child-user-migration.php';

class RK_MC_Child_User {
    use RK_MC_Child_User_Account;
    use RK_MC_Child_User_Auth;
    use RK_MC_Child_User_Migration;


    /** Clé usermeta : ID du parent sur le compte de l'enfant */
    const META_PARENT_ID   = '_rk_parent_id';

    /** Clé usermeta : ID de la ligne wp_rk_children */
    const META_CHILD_ROW   = '_rk_child_row_id';

    /** Clé usermeta : PIN 4 chiffres pour la page login enfant */
    const META_CHILD_PIN   = '_rk_child_pin';

    /** Session stacking parent → enfant */
    const RESTORE_COOKIE    = 'rk_parent_restore';   // cookie : clé du transient
    const RESTORE_TRANSIENT = 'rk_pr_';              // préfixe transient : rk_pr_{token}
    const RESTORE_TTL       = 600;                   // 10 minutes (durée de la session enfant max)

    /** Préfixe des transients switch */
    const SWITCH_TRANSIENT = 'rk_cswitch_';

    /** Durée du token (secondes) */
    const SWITCH_TTL       = 300; // 5 minutes

    /** Paramètre GET du token */
    const SWITCH_PARAM     = 'rk_child_enter';

    /* ─────────────────────────────────────────
     * Init
     * ───────────────────────────────────────── */

    public static function init() {
        // Créer le rôle rk_child si absent
        self::maybe_register_role();

        // Hook : après insertion enfant → créer wp_user
        add_action( 'rk_mc_child_inserted',  array( __CLASS__, 'on_child_created'  ), 10, 2 );

        // Hook : après suppression enfant → supprimer wp_user
        add_action( 'rk_mc_child_deleted',   array( __CLASS__, 'on_child_deleted'  ), 10, 1 );

        // Hook : booking confirmé → s'assurer que le wp_user enfant existe + PIN généré
        add_action( 'rk_booking_confirmed',  array( __CLASS__, 'on_booking_confirmed' ), 5, 2 );

        // Traitement du token switch (avant headers)
        add_action( 'init', array( __CLASS__, 'maybe_process_switch' ), 1 );

        /*
         * FIX — "انتهت صلاحية الطلب" au premier essai, systématiquement.
         *
         * Le garde DONOTCACHEPAGE posé dans child_login_template() (hook
         * template_include, priorité 98) s'exécute TROP TARD dans le cycle
         * WordPress pour être fiable face à un cache de page serveur
         * (LiteSpeed/QUIC.cloud). Ces plugins décident souvent du cache
         * bien avant template_include — parfois dès que la requête est
         * reçue, avant même que WordPress n'ait fini de router.
         *
         * Symptôme exact que ce timing explique : le TOUT PREMIER
         * chargement d'une nouvelle session sert une page HTML déjà en
         * cache (un nonce imprimé lors d'un rendu antérieur, pour un
         * visiteur ou un instant différent) → wp_verify_nonce() échoue
         * inévitablement, quel que soit le code PIN saisi, car le nonce
         * lui-même ne correspond à aucun rendu réel de CETTE requête.
         * child_login_fail() redirige alors en 302 vers la même URL : ce
         * rechargement est un vrai NOUVEAU passage dans WordPress, cette
         * fois plus susceptible d'obtenir un cache MISS (ou déclenché
         * après la purge que provoque souvent une erreur serveur) → HTML
         * frais → nonce valide → succès. D'où "1er essai échoue tout le
         * temps, 2e réussit tout le temps, même code".
         *
         * Correctif : poser le garde sur 'init', priorité 1 — le plus tôt
         * possible dans le cycle WordPress public, avant que template_include
         * ne soit même atteint, et avant la plupart des points de décision
         * de cache des plugins de cache serveur.
         */
        add_action( 'init', array( __CLASS__, 'force_nocache_on_child_login_page' ), 1 );

        /*
         * Endpoint AJAX dynamique pour le nonce du login enfant (v10.8).
         *
         * Architecture demandée : la page /connexion-child/ peut rester
         * mise en cache par un plugin de cache serveur (LiteSpeed/QUIC.
         * cloud/etc.) sans que cela ne casse le login — le nonce imprimé
         * dans le HTML n'est plus la source de vérité. Le formulaire
         * récupère un nonce FRAIS via cet endpoint (jamais mis en cache,
         * confirmé nopriv car l'enfant n'est jamais connecté à cet
         * instant), l'injecte dans le champ caché juste avant la
         * soumission, et seulement alors active le bouton de connexion.
         *
         * Le POST final reste un formulaire HTML natif vers
         * admin-post.php, inchangé — wp_verify_nonce() continue de
         * protéger le endpoint réel de connexion, exactement comme avant.
         * Seule la SOURCE du nonce change : dynamique plutôt qu'imprimée
         * dans du HTML potentiellement caché.
         */
        add_action( 'wp_ajax_nopriv_rk_child_login_nonce', array( __CLASS__, 'ajax_child_login_nonce' ) );
        add_action( 'wp_ajax_rk_child_login_nonce',        array( __CLASS__, 'ajax_child_login_nonce' ) );

        // Page login enfant standalone (/connexion-child/)
        // Ancienne méthode (nom + PIN) — conservée pour compatibilité
        add_action( 'admin_post_nopriv_rk_child_pin_login', array( __CLASS__, 'handle_child_login' ) );
        add_action( 'admin_post_rk_child_pin_login',        array( __CLASS__, 'handle_child_login' ) );
        
        // Nouvelle méthode (username + PIN) — authentification par anom d'utilisateur
        add_action( 'admin_post_nopriv_rk_child_username_pin_login', array( __CLASS__, 'handle_child_username_pin_login' ) );
        add_action( 'admin_post_rk_child_username_pin_login',        array( __CLASS__, 'handle_child_username_pin_login' ) );
        
        add_filter( 'template_include',                     array( __CLASS__, 'child_login_template' ), 98 );

        // Logout enfant : ?rk_child_logout=1 → /connexion-child/?logged_out=1
        add_action( 'init', array( __CLASS__, 'handle_child_logout' ) );


        // Outil migration dans le menu admin
        add_action( 'admin_menu', array( __CLASS__, 'register_migration_page' ) );

        // Notice admin si des enfants n'ont pas de wp_user
        add_action( 'admin_notices', array( __CLASS__, 'migration_notice' ) );
    }

}

