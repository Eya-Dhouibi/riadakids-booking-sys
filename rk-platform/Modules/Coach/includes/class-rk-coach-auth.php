<?php
declare( strict_types=1 );
/**
 * RK_Coach_Auth — Authentification, login, logout et redirections du coach.
 *
 * Extrait de RK_Coach_Dashboard pour respecter la limite ~1300 lignes par fichier.
 * RK_Coach_Dashboard::init() appelle RK_Coach_Auth::init() en premier.
 *
 * @package RK_Coach_Hub
 * @since   1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class RK_Coach_Auth {

    /* ─── Init ──────────────────────────────────────────────────────── */

    public static function init(): void {
        add_filter( 'login_redirect',             [ __CLASS__, 'coach_login_redirect'    ], 999, 3 );
        add_filter( 'woocommerce_login_redirect', [ __CLASS__, 'coach_wc_login_redirect' ], 999, 2 );
        add_action( 'template_redirect',          [ __CLASS__, 'coach_dashboard_gate'   ], 1 );
        add_shortcode( 'rk_coach_login',          [ __CLASS__, 'render_coach_login'     ] );
        add_filter( 'wp_authenticate_user',       [ __CLASS__, 'gate_coach_role'        ], 999, 2 );
        add_filter( 'woocommerce_process_registration_errors', [ __CLASS__, 'block_coach_page_register' ], 10, 3 );

        // ── Login page standalone ────────────────────────────────────
        add_action( 'init', [ __CLASS__, 'force_nocache_on_coach_login_page' ], 1 );
        add_action( 'admin_post_nopriv_rk_coach_login', [ __CLASS__, 'handle_coach_login' ] );
        add_action( 'admin_post_rk_coach_login',        [ __CLASS__, 'handle_coach_login' ] );
        add_filter( 'template_include',                 [ __CLASS__, 'coach_login_template' ], 99 );

        // ── Session isolation : logout coach → page login ────────────
        add_action( 'init', [ __CLASS__, 'handle_rk_coach_logout' ] );

        // ── /my-account/ réservé aux parents uniquement ──────────────
        add_filter( 'wp_authenticate_user', [ __CLASS__, 'block_coach_on_parent_login' ], 998, 2 );
        add_action( 'template_redirect',    [ __CLASS__, 'redirect_coach_from_account' ], 2 );

        // ── Logout redirects ─────────────────────────────────────────
        add_filter( 'logout_redirect',             [ __CLASS__, 'session_logout_redirect'     ], PHP_INT_MAX, 3 );
        add_filter( 'woocommerce_logout_redirect', [ __CLASS__, 'customer_wc_logout_redirect' ] );
        add_action( 'wp_logout', [ __CLASS__, 'stamp_child_logout_cookie' ], 1 );
        add_filter( 'tutor_dashboard_logout_redirect_url', [ __CLASS__, 'tutor_logout_redirect' ] );
    }

    /* ─── Login redirect ────────────────────────────────────────────── */

    public static function coach_login_redirect( string $redirect_to, string $request, $user ): string {
        if ( is_wp_error( $user ) || ! ( $user instanceof WP_User ) ) return $redirect_to;
        if ( RK_Coach_Dashboard::is_coach_user( $user ) ) return home_url( '/espace-coach/' );
        return $redirect_to;
    }

    public static function coach_wc_login_redirect( string $redirect, WP_User $user ): string {
        if ( RK_Coach_Dashboard::is_coach_user( $user ) ) return home_url( '/espace-coach/' );
        return $redirect;
    }

    public static function coach_dashboard_gate(): void {
        if ( is_user_logged_in() ) return;
        if ( ! function_exists( 'tutor_utils' ) ) return;
        $dashboard_id = (int) tutor_utils()->get_option( 'tutor_dashboard_page_id' );
        if ( ! $dashboard_id || (int) get_queried_object_id() !== $dashboard_id ) return;

        // Session rk_tab encore valide (transient intact) → le cookie WP parent peut être absent
        // mais l'enfant a toujours sa session. Ne pas rediriger.
        // phpcs:disable WordPress.Security.NonceVerification
        $raw_tab = isset( $_GET['rk_tab'] ) ? sanitize_text_field( wp_unslash( $_GET['rk_tab'] ) ) : '';
        // phpcs:enable
        /*
         * 6b-4b — Meme correction que dans le resolveur Children : la lecture
         * directe du transient ne voyait plus les sessions stockees en table,
         * ce qui aurait redirige vers le login un enfant pourtant muni d'une
         * session valide (notamment via un lien de quiz recu par e-mail, sans
         * cookie WP prealable). Comportement fonctionnel inchange.
         */
        if ( preg_match( '/^[a-f0-9]{40}$/', $raw_tab ) ) {
            $rk_session = class_exists( 'RK_Session_Manager' )
                ? RK_Session_Manager::validate_tab_session( $raw_tab )
                : get_transient( 'rk_tab_' . $raw_tab );
            if ( $rk_session ) {
                return;
            }
        }

        if ( ! empty( $_COOKIE['rk_child_logout'] ) ) {
            setcookie( 'rk_child_logout', '', time() - 3600, COOKIEPATH, (string) COOKIE_DOMAIN );
            wp_safe_redirect( home_url( '/connexion-child/' ) );
            exit;
        }

        // Rediriger vers la page de login standard WP (pas /connexion-coach/).
        // Après login, coach_login_redirect() enverra les coachs vers /espace-coach/,
        // et les parents retourneront au dashboard normalement.
        wp_safe_redirect( wp_login_url( home_url( '/dashboard/' ) ) );
        exit;
    }

    public static function render_coach_login( array $atts = [] ): string {
        if ( is_user_logged_in() && RK_Coach_Dashboard::is_coach_user( wp_get_current_user() ) ) {
            wp_safe_redirect( home_url( '/espace-coach/' ) );
            exit;
        }

        add_filter( 'woocommerce_registration_enabled', '__return_false' );
        add_action( 'woocommerce_login_form', static function() {
            echo '<input type="hidden" name="rk_coach_gate" value="1">';
        }, 5 );

        ob_start(); ?>
        <div class="riada-auth-container rk-coach-login-wrap">
            <div class="riada-card">

                <div class="riada-logo">
                    <img decoding="async"
                         src="https://riadakids.com/wp-content/uploads/2026/01/logo-1.webp"
                         alt="Riada Kids" width="140" height="54" loading="eager">
                </div>

                <div class="riada-header-text">
                    <h2 class="riada-title">بوابة المدربين</h2>
                    <p class="riada-subtitle">الوصول مخصص للمدربين المعتمدين فقط</p>
                </div>

                <div class="riada-separator"><span>تسجيل الدخول</span></div>

                <?php if ( function_exists( 'wc_print_notices' ) ) wc_print_notices(); ?>

                <?php
                if ( function_exists( 'woocommerce_login_form' ) ) {
                    woocommerce_login_form( [ 'redirect' => home_url( '/espace-coach/' ), 'hidden' => false ] );
                }
                ?>

            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /* ─── Page login standalone ─────────────────────────────────────── */

    /**
     * Force l'exclusion du cache de page pour /connexion-coach/, posé sur
     * 'init' (le plus tôt possible), même raison que côté enfant : un
     * garde posé sur template_include peut arriver trop tard face à un
     * cache de page serveur. Voir RK_MC_Child_User_Auth::
     * force_nocache_on_child_login_page() pour l'explication complète.
     *
     * NOTE : handle_coach_login() (nonce 'rk_coach_login') n'est en
     * pratique jamais atteint par le formulaire réel de /connexion-coach/,
     * qui authentifie par JWT via fetch() sans nonce ni admin-post.php
     * (voir page-coach-login.php). Ce garde est donc un correctif de
     * cohérence architecturale, pas la correction d'un bug utilisateur
     * actif constaté côté coach.
     */
    public static function force_nocache_on_coach_login_page(): void {
        $path = isset( $_SERVER['REQUEST_URI'] )
            ? trim( (string) wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ), '/' )
            : '';
        if ( 'connexion-coach' !== $path ) return;

        if ( ! defined( 'DONOTCACHEPAGE' ) ) {
            define( 'DONOTCACHEPAGE', true );
        }
        do_action( 'litespeed_control_set_nocache', 'rk_coach_login_form' );
        nocache_headers();
    }

    public static function coach_login_template( string $template ): string {
        if ( ! is_page( 'connexion-coach' ) ) return $template;

        // Le garde no-cache est posé plus tôt, sur 'init' — voir
        // force_nocache_on_coach_login_page().

        $custom = RK_COACH_HUB_DIR . 'templates/page-coach-login.php';
        return file_exists( $custom ) ? $custom : $template;
    }

    public static function handle_coach_login(): void {
        $nonce = isset( $_POST['rk_coach_login_nonce'] )
            ? sanitize_text_field( wp_unslash( $_POST['rk_coach_login_nonce'] ) )
            : '';

        if ( ! wp_verify_nonce( $nonce, 'rk_coach_login' ) ) {
            wp_safe_redirect( add_query_arg( 'login_error', 'invalid_nonce', home_url( '/connexion-coach/' ) ) );
            exit;
        }

        $username = sanitize_user( wp_unslash( $_POST['coach_username'] ?? '' ) );
        $password = wp_unslash( $_POST['coach_password'] ?? '' );
        $remember = ! empty( $_POST['rememberme'] );

        if ( ! $username || ! $password ) {
            wp_safe_redirect( add_query_arg( 'login_error', 'empty_fields', home_url( '/connexion-coach/' ) ) );
            exit;
        }

        $user = wp_authenticate( $username, $password );
        if ( is_wp_error( $user ) ) {
            wp_safe_redirect( add_query_arg( 'login_error', 'bad_credentials', home_url( '/connexion-coach/' ) ) );
            exit;
        }

        if ( ! RK_Coach_Dashboard::is_coach_user( $user ) ) {
            wp_safe_redirect( add_query_arg( 'login_error', 'not_coach', home_url( '/connexion-coach/' ) ) );
            exit;
        }

        if ( is_user_logged_in() ) {
            $current = wp_get_current_user();
            if ( (int) $current->ID !== (int) $user->ID ) wp_logout();
        }

        wp_set_auth_cookie( $user->ID, $remember, is_ssl() );
        wp_set_current_user( $user->ID, $user->user_login );
        do_action( 'wp_login', $user->user_login, $user );

        $redirect = esc_url_raw( wp_unslash( $_POST['redirect_to'] ?? home_url( '/espace-coach/' ) ) );
        wp_safe_redirect( $redirect );
        exit;
    }

    /* ─── Session isolation : logout coach ──────────────────────────── */

    public static function handle_rk_coach_logout(): void {
        if ( empty( $_GET['rk_coach_logout'] ) ) return;

        $nonce = isset( $_GET['_wpnonce'] )
            ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) )
            : '';

        if ( ! wp_verify_nonce( $nonce, 'rk_coach_logout' ) ) {
            wp_safe_redirect( home_url( '/connexion-coach/' ) );
            exit;
        }

        if ( is_user_logged_in() ) {
            $manager = WP_Session_Tokens::get_instance( get_current_user_id() );

            // Détruire UNIQUEMENT le token de session courant — pas les autres appareils.
            $token = wp_get_session_token();
            if ( $token ) {
                $manager->destroy( $token );
            }

            // Effacer le cookie WP de ce navigateur uniquement (pas wp_logout() qui
            // déclenche des hooks WooCommerce et détruit toutes les sessions).
            wp_clear_auth_cookie();
        }

        wp_safe_redirect( add_query_arg( 'logged_out', '1', home_url( '/connexion-coach/' ) ) );
        exit;
    }

    public static function get_coach_logout_url(): string {
        return wp_nonce_url( home_url( '/?rk_coach_logout=1' ), 'rk_coach_logout' );
    }

    public static function session_logout_redirect( string $redirect_to, string $requested, $user ): string {
        if ( ! ( $user instanceof WP_User ) ) return home_url( '/' );
        $roles = (array) $user->roles;
        if ( in_array( 'rk_child', $roles, true ) )        return home_url( '/connexion-child/' );
        if ( in_array( 'tutor_instructor', $roles, true ) ) return home_url( '/connexion-coach/' );
        if ( in_array( 'rk_coach', $roles, true ) )        return home_url( '/connexion-coach/' );
        return home_url( '/' );
    }

    public static function customer_wc_logout_redirect( string $redirect ): string {
        return home_url( '/' );
    }

    public static function stamp_child_logout_cookie( int $user_id ): void {
        $user = get_user_by( 'id', $user_id );
        if ( ! $user ) return;
        if ( ! in_array( 'rk_child', (array) $user->roles, true ) ) return;
        setcookie( 'rk_child_logout', '1', time() + 60, COOKIEPATH, (string) COOKIE_DOMAIN, is_ssl() );
    }

    public static function tutor_logout_redirect( string $url ): string {
        if ( ! is_user_logged_in() ) return home_url( '/' );
        $roles = (array) wp_get_current_user()->roles;
        if ( in_array( 'rk_child', $roles, true ) )        return home_url( '/connexion-child/' );
        if ( in_array( 'tutor_instructor', $roles, true ) ) return home_url( '/connexion-coach/' );
        if ( in_array( 'rk_coach', $roles, true ) )        return home_url( '/connexion-coach/' );
        return home_url( '/' );
    }

    /* ─── /my-account/ : espace parent uniquement ───────────────────── */

    public static function block_coach_on_parent_login( $user, $password ) {
        if ( is_wp_error( $user ) ) return $user;
        if ( empty( $_POST['woocommerce-login-nonce'] ) ) return $user;
        if ( ! ( $user instanceof \WP_User ) ) return $user;
        if ( ! $user->has_cap( 'tutor_instructor' ) ) return $user;

        return new \WP_Error(
            'coach_on_parent_page',
            sprintf(
                __( 'الحساب غير موجود. إذا كنت مدرباً، يرجى الدخول من <a href="%s">بوابة المدربين</a>.', 'rk-coach-hub' ),
                esc_url( home_url( '/connexion-coach/' ) )
            )
        );
    }

    public static function redirect_coach_from_account(): void {
        if ( ! function_exists( 'is_account_page' ) || ! is_account_page() ) return;
        if ( ! is_user_logged_in() ) return;

        $user  = wp_get_current_user();
        $roles = (array) $user->roles;

        if ( RK_Coach_Dashboard::is_coach_user( $user ) ) {
            wp_safe_redirect( home_url( '/espace-coach/' ) );
            exit;
        }
        if ( in_array( 'rk_child', $roles, true ) ) {
            wp_safe_redirect( home_url( '/connexion-child/' ) );
            exit;
        }
    }

    /* ─── Gate rôle (shortcode login) ───────────────────────────────── */

    public static function gate_coach_role( $user, $password ) {
        if ( is_wp_error( $user ) ) return $user;
        if ( empty( $_POST['rk_coach_gate'] ) ) return $user;
        if ( ! RK_Coach_Dashboard::is_coach_user( $user ) ) {
            return new \WP_Error(
                'coach_access_denied',
                __( 'هذه الصفحة مخصصة للمدربين المعتمدين فقط. يرجى استخدام صفحة تسجيل الدخول الرئيسية.', 'rk-coach-hub' )
            );
        }
        return $user;
    }

    public static function block_coach_page_register( $errors, $username, $email ) {
        if ( ! empty( $_POST['rk_coach_gate'] ) ) {
            $errors->add(
                'coach_register_blocked',
                __( 'التسجيل غير مسموح في هذه الصفحة. يرجى التواصل مع الإدارة لإنشاء حساب مدرب.', 'rk-coach-hub' )
            );
        }
        return $errors;
    }
}
