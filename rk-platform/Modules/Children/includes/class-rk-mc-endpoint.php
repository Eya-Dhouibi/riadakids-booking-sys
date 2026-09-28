<?php
declare( strict_types=1 );
/**
 * RK_MC_Endpoint  (v5.3.0 — Sprint 1)
 *
 * Endpoints WooCommerce :
 *   /my-account/my-children/       — gestion des profils enfants (existant)
 *   /my-account/child-dashboard/   — dashboard enfant (nouveau Sprint 1)
 *
 * ARCHITECTURE child-dashboard :
 *   - Accessible uniquement via ?child_id=XX
 *   - Ownership validé dans le template (via RK_MC_Child_Dashboard_Service)
 *   - Non visible dans le menu WC du parent (accès par lien de la carte enfant)
 *
 * @package RK_My_Children
 * @since   5.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class RK_MC_Endpoint {

    const SLUG                  = 'my-children';
    const CHILD_DASHBOARD_SLUG  = 'child-dashboard';
    const MESSAGES_SLUG         = 'messages';
    const RAPPORT_SLUG          = 'rk-rapport';
    const PROFILE_SLUG          = 'child-profile';

    public static function init() {
        add_action( 'init',                           array( __CLASS__, 'add_endpoint' ) );
        add_filter( 'woocommerce_get_query_vars',     array( __CLASS__, 'query_vars' ) );
        add_filter( 'woocommerce_account_menu_items', array( __CLASS__, 'menu_item' ) );

        // Rendu endpoint my-children (existant)
        add_action(
            'woocommerce_account_' . self::SLUG . '_endpoint',
            array( __CLASS__, 'render' )
        );

        // Rendu endpoint child-dashboard (Sprint 1)
        add_action(
            'woocommerce_account_' . self::CHILD_DASHBOARD_SLUG . '_endpoint',
            array( __CLASS__, 'render_child_dashboard' )
        );

        // Rendu endpoint rk-rapport — tableau de bord parent
        add_action(
            'woocommerce_account_' . self::RAPPORT_SLUG . '_endpoint',
            array( __CLASS__, 'render_rapport' )
        );

        // Rendu endpoint child-profile — fiche complète (parent, lecture seule)
        add_action(
            'woocommerce_account_' . self::PROFILE_SLUG . '_endpoint',
            array( __CLASS__, 'render_child_profile' )
        );

        // Rendu endpoint messages — uniquement si BM ne le fait pas déjà
        if ( ! ( class_exists( 'Better_Messages' ) && ( Better_Messages()->settings['chatPage'] ?? '0' ) === 'woocommerce' ) ) {
            add_action(
                'woocommerce_account_' . self::MESSAGES_SLUG . '_endpoint',
                array( __CLASS__, 'render_messages' )
            );
        }
    }

    /* ─────────────────────────────────────────
     * Rewrite endpoints
     * ───────────────────────────────────────── */

    public static function add_endpoint() {
        add_rewrite_endpoint( self::SLUG,                 EP_ROOT | EP_PAGES );
        add_rewrite_endpoint( self::CHILD_DASHBOARD_SLUG, EP_ROOT | EP_PAGES );
        add_rewrite_endpoint( self::RAPPORT_SLUG,         EP_ROOT | EP_PAGES );
        add_rewrite_endpoint( self::PROFILE_SLUG,         EP_ROOT | EP_PAGES );

        // N'enregistrer messages que si BM ne le fait pas lui-même
        if ( ! ( class_exists( 'Better_Messages' ) && ( Better_Messages()->settings['chatPage'] ?? '0' ) === 'woocommerce' ) ) {
            add_rewrite_endpoint( self::MESSAGES_SLUG, EP_PAGES );
        }
    }

    /* ─────────────────────────────────────────
     * Query vars
     * ───────────────────────────────────────── */

    public static function query_vars( array $vars ): array {
        $vars[ self::SLUG ]                 = self::SLUG;
        $vars[ self::CHILD_DASHBOARD_SLUG ] = self::CHILD_DASHBOARD_SLUG;
        $vars[ self::RAPPORT_SLUG ]         = self::RAPPORT_SLUG;
        $vars[ self::PROFILE_SLUG ]         = self::PROFILE_SLUG;
        $vars[ self::MESSAGES_SLUG ]        = self::MESSAGES_SLUG;
        return $vars;
    }

    /* ─────────────────────────────────────────
     * Menu WooCommerce
     *
     * child-dashboard n'est PAS dans le menu parent.
     * messages est ajouté avant "déconnexion".
     * ───────────────────────────────────────── */

    public static function menu_item( array $items ): array {
        $new = array();
        foreach ( $items as $key => $label ) {
            // Insérer "messages" et "rk-rapport" avant "customer-logout"
            if ( 'customer-logout' === $key ) {
                if ( ! ( class_exists( 'Better_Messages' ) && ( Better_Messages()->settings['chatPage'] ?? '0' ) === 'woocommerce' )
                     && ! isset( $new[ self::MESSAGES_SLUG ] )
                ) {
                    $new[ self::MESSAGES_SLUG ] = __( 'الرسائل', 'rk-my-children' );
                }
                if ( ! isset( $new[ self::RAPPORT_SLUG ] ) ) {
                    $new[ self::RAPPORT_SLUG ] = __( 'التقارير', 'rk-my-children' );
                }
            }
            $new[ $key ] = $label;
            if ( 'dashboard' === $key ) {
                $new[ self::SLUG ] = __( 'أطفالي', 'rk-my-children' );
                // child-dashboard intentionnellement absent du menu
            }
        }
        return $new;
    }

    /* ─────────────────────────────────────────
     * Render : My Children (existant)
     * ───────────────────────────────────────── */

    public static function render() {
        if ( ! is_user_logged_in() ) {
            return;
        }

        // [v5.4.0] Présentation déplacée dans le thème (woocommerce/myaccount/my-children.php).
        // On priorise le template du thème ; si absent (thème changé/retiré),
        // on retombe sur l'ancien template du plugin — aucune régression possible.
        $theme_template = get_stylesheet_directory() . '/woocommerce/myaccount/my-children.php';
        $template       = file_exists( $theme_template )
            ? $theme_template
            : RK_MC_DIR . 'templates/my-children.php';

        if ( file_exists( $template ) ) {
            require $template;
        }
    }

    /* ─────────────────────────────────────────
     * Render : Child Dashboard (Sprint 1)
     * ───────────────────────────────────────── */

    public static function render_child_dashboard() {
        if ( ! is_user_logged_in() ) {
            wp_safe_redirect( wc_get_page_permalink( 'myaccount' ) );
            exit;
        }

        // Permanently redirect /my-account/child-dashboard/?child_id=X
        // to the unified Tutor LMS dashboard /dashboard/?child_id=X
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $child_id = absint( $_GET['child_id'] ?? 0 );
        $tutor_url = home_url( RK_TUTOR_DASHBOARD_URL );
        if ( $child_id > 0 ) {
            $tutor_url = add_query_arg( 'child_id', $child_id, $tutor_url );
        }
        wp_safe_redirect( $tutor_url, 301 );
        exit;
    }

    /* ─────────────────────────────────────────
     * Render : Rapports (/my-account/rk-rapport/)
     * ───────────────────────────────────────── */

    public static function render_rapport() {
        if ( ! is_user_logged_in() ) {
            wp_safe_redirect( wc_get_page_permalink( 'myaccount' ) );
            exit;
        }
        $template = RK_MC_DIR . 'templates/woocommerce/rk-rapport.php';
        if ( file_exists( $template ) ) {
            require $template;
        }
    }

    /* ─────────────────────────────────────────
     * Render : Fiche complète (/my-account/child-profile/)
     *
     * Vue parent, lecture seule : réutilise les mêmes données que le
     * Child Dashboard (RK_MC_Child_Dashboard_Data) mais sans le chrome
     * ni les interactions propres à l'espace enfant (pas dans le menu,
     * accessible uniquement via « الملف الكامل » sur la carte enfant).
     * ───────────────────────────────────────── */

    public static function render_child_profile() {
        if ( ! is_user_logged_in() ) {
            wp_safe_redirect( wc_get_page_permalink( 'myaccount' ) );
            exit;
        }
        $template = RK_MC_DIR . 'templates/woocommerce/child-profile.php';
        if ( file_exists( $template ) ) {
            require $template;
        }
    }

    /* ─────────────────────────────────────────
     * Render : Messages (/my-account/messages/)
     *
     * Délègue le rendu à Better Messages.
     * Actif uniquement quand BM n'enregistre pas
     * lui-même l'endpoint (chatPage !== 'woocommerce').
     * ───────────────────────────────────────── */

    public static function render_messages() {
        if ( ! is_user_logged_in() ) {
            wp_safe_redirect( wc_get_page_permalink( 'myaccount' ) );
            exit;
        }

        if ( class_exists( 'Better_Messages' ) && method_exists( Better_Messages()->functions, 'get_page' ) ) {
            echo Better_Messages()->functions->get_page(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        } else {
            echo '<p style="padding:24px;color:#718096;text-align:center;direction:rtl;">الرسائل غير متاحة حالياً.</p>';
        }
    }
}

