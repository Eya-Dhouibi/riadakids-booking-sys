<?php
/**
 * RiadaKids\Coordinator\CoordinatorDashboard — Phase 3
 *
 * Route frontend /coordinator-dashboard/ (règle de réécriture dédiée, sans
 * toucher à l'endpoint WooCommerce 'book-session' ni aux dashboards de la
 * plateforme A). Le shell HTML est vide : les données arrivent par AJAX
 * (CoordinatorAjax) et sont rendues en JS avec textContent (aucun innerHTML
 * sur des données).
 *
 * Accès : connecté + capability rk_view_booking_requests. Sinon :
 *   - non connecté → redirection vers wp-login (retour sur le dashboard)
 *   - connecté sans capability (parent, coach…) → 403.
 *
 * @package RiadaKids\Coordinator
 */

namespace RiadaKids\Coordinator;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CoordinatorDashboard {

    public const QUERY_VAR   = 'rk_coordinator';
    public const SLUG        = 'coordinator-dashboard';
    public const REWRITE_OPT = 'rk_coordinator_rewrite_v';
    public const REWRITE_VER = '1';

    public static function url(): string {
        return home_url( '/' . self::SLUG . '/' );
    }

    public function register(): void {
        add_action( 'init',              [ $this, 'add_rewrite' ], 3 );
        add_action( 'init',              [ $this, 'maybe_flush_rewrite' ], 20 );
        add_filter( 'query_vars',        [ $this, 'add_query_var' ] );
        add_action( 'template_redirect', [ $this, 'maybe_render' ], 1 );
    }

    public function add_rewrite(): void {
        add_rewrite_rule( '^' . self::SLUG . '/?$', 'index.php?' . self::QUERY_VAR . '=1', 'top' );
    }

    /** Un seul flush par version (option dédiée : RK_VERSION / migrations non touchés). */
    public function maybe_flush_rewrite(): void {
        if ( get_option( self::REWRITE_OPT ) === self::REWRITE_VER ) return;
        flush_rewrite_rules( false );
        update_option( self::REWRITE_OPT, self::REWRITE_VER );
    }

    public function add_query_var( array $vars ): array {
        $vars[] = self::QUERY_VAR;
        return $vars;
    }

    public function maybe_render(): void {
        if ( ! get_query_var( self::QUERY_VAR ) ) return;

        if ( ! is_user_logged_in() ) {
            wp_safe_redirect( wp_login_url( self::url() ) );
            exit;
        }
        if ( ! current_user_can( CoordinatorRole::CAP_VIEW_REQUESTS ) ) {
            status_header( 403 );
            nocache_headers();
            wp_die( esc_html( 'غير مصرح لك بالدخول إلى هذه الصفحة.' ), '', [ 'response' => 403 ] );
        }

        global $wp_query;
        if ( $wp_query ) $wp_query->is_404 = false;
        status_header( 200 );
        nocache_headers();

        $this->enqueue();
        get_header();
        $this->render_shell();
        get_footer();
        exit;
    }

    private function enqueue(): void {
        wp_enqueue_style( 'rk-coordinator', RK_PLUGIN_URL . 'assets/css/coordinator.css', [], RK_VERSION );
        wp_enqueue_script( 'rk-coordinator', RK_PLUGIN_URL . 'assets/js/coordinator.js', [], RK_VERSION, true );
        wp_localize_script( 'rk-coordinator', 'rkCoordinator', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( CoordinatorAjax::NONCE_ACTION ),
        ] );
    }

    private function render_shell(): void {
        ?>
        <main id="rk-coord" class="rk-coord" dir="rtl" lang="ar">
            <header class="rk-coord__head">
                <h1 class="rk-coord__title">لوحة منسق المواعيد</h1>
                <button type="button" class="rk-coord__btn rk-coord__btn--ghost" id="rk-coord-refresh">تحديث</button>
            </header>

            <div class="rk-coord__status" id="rk-coord-status" role="status" aria-live="polite">جارٍ التحميل…</div>

            <section class="rk-coord__section" data-section="new">
                <h2>طلبات حجز جديدة <span class="rk-coord__count" data-count="new">0</span></h2>
                <div class="rk-coord__list" data-list="new"></div>
            </section>

            <section class="rk-coord__section" data-section="pending">
                <h2>بانتظار تأكيد ولي الأمر <span class="rk-coord__count" data-count="pending">0</span></h2>
                <div class="rk-coord__list" data-list="pending"></div>
            </section>

            <section class="rk-coord__section" data-section="changes" hidden>
                <h2>طلبات تغيير الموعد <span class="rk-coord__count" data-count="changes">0</span></h2>
                <div class="rk-coord__list" data-list="changes"></div>
            </section>

            <section class="rk-coord__section" data-section="coaches" hidden>
                <h2>المدربين <span class="rk-coord__count" data-count="coaches">0</span></h2>
                <div class="rk-coord__list" data-list="coaches"></div>
            </section>

            <aside class="rk-coord__panel" id="rk-coord-panel" hidden aria-modal="true" role="dialog" aria-labelledby="rk-coord-panel-title">
                <div class="rk-coord__panel-box">
                    <button type="button" class="rk-coord__panel-close" id="rk-coord-panel-close" aria-label="إغلاق">×</button>
                    <h3 id="rk-coord-panel-title">تحديد الموعد</h3>
                    <div id="rk-coord-panel-body"></div>
                </div>
            </aside>
        </main>
        <?php
    }
}
