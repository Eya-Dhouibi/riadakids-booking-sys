<?php
declare( strict_types=1 );
/**
 * RK_MC_Child_Restrictions  (v5.4.0 — Sprint 2)
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * RÔLE
 * ─────────────────────────────────────────────────────────────────────────────
 * Applique toutes les restrictions pour le rôle rk_child.
 * Un enfant connecté ne doit jamais accéder à :
 *
 *   - wp-admin (tous les endpoints)
 *   - WooCommerce : commandes, abonnements, paiements, checkout, panier
 *   - WooCommerce account : edit-account (informations financières)
 *   - Shop / boutique
 *
 * Redirect systématique vers /dashboard/ (Tutor LMS).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * ARCHITECTURE
 * ─────────────────────────────────────────────────────────────────────────────
 * Ce fichier ne modifie AUCUN template Tutor LMS ni WooCommerce.
 * Il agit uniquement via des hooks WordPress standard.
 *
 * @package RK_My_Children
 * @since   5.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class RK_MC_Child_Restrictions {

    /**
     * Pages WooCommerce Account bloquées pour rk_child.
     * Clé : endpoint WC | Valeur : description pour débogage.
     */
    private static $blocked_wc_endpoints = array(
        'orders'          => 'Commandes',
        'view-order'      => 'Détail commande',
        'downloads'       => 'Téléchargements',
        'edit-account'    => 'Modifier compte',
        'payment-methods' => 'Moyens de paiement',
        'subscriptions'   => 'Abonnements',
        'view-subscription' => 'Détail abonnement',
        'my-account'      => 'Mon compte (racine WC)',
    );

    /**
     * Slugs de pages WP bloquées (par page slug ou chemin URL).
     */
    private static $blocked_slugs = array(
        'cart',
        'checkout',
        'shop',
    );

    /* ─────────────────────────────────────────
     * Init
     * ───────────────────────────────────────── */

    public static function init() {
        if ( ! is_user_logged_in() ) {
            return;
        }
        if ( ! self::is_child_user() ) {
            return;
        }

        // Bloquer wp-admin (sauf AJAX)
        add_action( 'admin_init',         array( __CLASS__, 'block_admin_access' ) );

        // Masquer la barre d'administration
        add_filter( 'show_admin_bar',     '__return_false' );

        // Bloquer les pages WC + boutique (template_redirect)
        add_action( 'template_redirect',  array( __CLASS__, 'block_restricted_pages' ) );

        // Désactiver les capacités WooCommerce sur les requêtes Ajax WC
        add_filter( 'woocommerce_is_account_page', array( __CLASS__, 'disable_wc_account_page' ) );

        // Retirer les éléments WC du menu nav (thème)
        add_filter( 'wp_nav_menu_objects', array( __CLASS__, 'filter_nav_menu' ), 10, 2 );
    }

    /* ─────────────────────────────────────────
     * Vérification rôle
     * ───────────────────────────────────────── */

    public static function is_child_user(): bool {
        if ( ! function_exists( 'wp_get_current_user' ) ) {
            return false;
        }
        $user = wp_get_current_user();
        return $user && in_array( 'rk_child', (array) $user->roles, true );
    }

    /* ─────────────────────────────────────────
     * Blocage wp-admin
     * ───────────────────────────────────────── */

    public static function block_admin_access() {
        // Autoriser les requêtes AJAX (nécessaires pour Tutor LMS)
        if ( wp_doing_ajax() ) {
            return;
        }
        // Autoriser les requêtes REST
        if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
            return;
        }
        self::redirect_to_dashboard();
    }

    /* ─────────────────────────────────────────
     * Blocage pages WC / boutique
     * ───────────────────────────────────────── */

    public static function block_restricted_pages() {
        if ( self::is_restricted_page() ) {
            self::redirect_to_dashboard();
        }
    }

    /**
     * Retourne true si la page courante est restreinte pour rk_child.
     */
    private static function is_restricted_page(): bool {

        // Pages WooCommerce Account (endpoints bloqués)
        if ( function_exists( 'is_account_page' ) && is_account_page() ) {
            foreach ( self::$blocked_wc_endpoints as $endpoint => $label ) {
                if ( is_wc_endpoint_url( $endpoint ) ) {
                    return true;
                }
            }
            // Racine /my-account/ sans endpoint = page compte parent
            if ( ! is_wc_endpoint_url() ) {
                return true;
            }
        }

        // Panier / Checkout / Boutique
        if ( function_exists( 'is_cart' ) && is_cart() )         return true;
        if ( function_exists( 'is_checkout' ) && is_checkout() ) return true;
        if ( function_exists( 'is_shop' ) && is_shop() )         return true;

        // Vérification par slug de page (fallback)
        if ( is_page( self::$blocked_slugs ) ) {
            return true;
        }

        return false;
    }

    /* ─────────────────────────────────────────
     * Désactiver is_account_page pour rk_child
     * ───────────────────────────────────────── */

    public static function disable_wc_account_page( $is_account ): bool {
        // Les endpoints autorisés pour rk_child (via notre propre endpoint)
        if ( function_exists( 'is_wc_endpoint_url' ) ) {
            $allowed = array(
                RK_MC_Endpoint::CHILD_DASHBOARD_SLUG,
            );
            foreach ( $allowed as $ep ) {
                if ( is_wc_endpoint_url( $ep ) ) {
                    return $is_account;
                }
            }
        }
        return false;
    }

    /* ─────────────────────────────────────────
     * Filtre menu de navigation
     * ───────────────────────────────────────── */

    /**
     * Retire les éléments de menu liés à WooCommerce pour rk_child.
     * Agit sur tous les menus WordPress — ne touche pas les menus Tutor LMS.
     */
    public static function filter_nav_menu( array $items, $args ): array {
        $blocked_classes = array(
            'woocommerce-MyAccount-navigation',
            'wc-item',
        );
        $blocked_url_fragments = array(
            '/my-account',
            '/cart',
            '/checkout',
            '/shop',
        );

        $home = home_url();

        $filtered = array();
        foreach ( $items as $item ) {
            $url  = $item->url ?? '';
            $path = str_replace( $home, '', $url );

            $blocked = false;
            foreach ( $blocked_url_fragments as $fragment ) {
                if ( 0 === strpos( $path, $fragment ) ) {
                    $blocked = true;
                    break;
                }
            }
            foreach ( $blocked_classes as $cls ) {
                if ( in_array( $cls, (array) ( $item->classes ?? array() ), true ) ) {
                    $blocked = true;
                    break;
                }
            }

            if ( ! $blocked ) {
                $filtered[] = $item;
            }
        }

        return $filtered;
    }

    /* ─────────────────────────────────────────
     * Redirect helper
     * ───────────────────────────────────────── */

    private static function redirect_to_dashboard() {
        $tutor_url = home_url( rtrim( RK_TUTOR_DASHBOARD_URL, '/' ) . '/' );
        wp_safe_redirect( $tutor_url );
        exit;
    }
}

