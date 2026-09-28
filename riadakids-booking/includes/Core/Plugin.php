<?php
/**
 * RiadaKids\Core\Plugin — Bootstrap central v3.0
 * Phase 3.1 : Suppression du chargement de SsaWidgetAjax.
 */

namespace RiadaKids\Core;

if ( ! defined( 'ABSPATH' ) ) exit;

class Plugin {

    private static ?Plugin $instance = null;

    public static function get_instance(): self {
        if ( null === self::$instance ) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {}

    /** Enregistre l'endpoint WC (appelé activation + init) */
    public static function register_endpoint(): void {
        add_rewrite_endpoint( 'book-session', EP_ROOT | EP_PAGES );
    }

    public function boot(): void {
        $this->maybe_migrate();

        add_action( 'init', [ self::class, 'register_endpoint' ], 1 );
        add_action( 'init', [ $this, 'maybe_flush_rewrite' ], 2 );

        add_filter( 'cron_schedules',     [ $this, 'register_cron_schedules' ] );
        add_action( 'rk_ssa_sync_cron',   [ $this, 'run_cron_sync' ] );
        add_action( 'rk_cleanup_cron',    [ $this, 'run_cron_cleanup' ] );
        add_action( 'rk_job_worker_cron', [ $this, 'run_job_worker' ] );
        $this->schedule_crons();

        $this->load_modules();

        add_action( 'admin_notices', [ $this, 'notice_guest_checkout' ] );
    }

    private function maybe_migrate(): void {
        if ( get_option( 'rk_plugin_version', '0' ) === RK_VERSION ) return;
        try {
            ob_start();
            \RiadaKids\Database\Install::run();
            \RiadaKids\Database\Migrations::run();
            ob_end_clean();
            update_option( 'rk_plugin_version', RK_VERSION );
            rk_log( 'SYSTEM', 'Migration DB v' . RK_VERSION );
        } catch ( \Throwable $e ) {
            if ( ob_get_level() ) ob_end_clean();
            rk_log( 'SYSTEM', 'Migration échouée : ' . $e->getMessage(), 'error' );
        }
    }

    public function maybe_flush_rewrite(): void {
        if ( get_option( 'rk_rewrite_version' ) === RK_VERSION ) return;
        flush_rewrite_rules( false );
        update_option( 'rk_rewrite_version', RK_VERSION );
        rk_log( 'SYSTEM', 'Rewrite rules flushées v' . RK_VERSION );
    }

    private function load_modules(): void {
        // Couche booking (injection de dépendances)
        $notifier = new \RiadaKids\Booking\BookingNotifier();
        $repo     = new \RiadaKids\Booking\BookingRepository();
        $service  = new \RiadaKids\Booking\BookingService( $repo, $notifier );

        // Hooks SSA (webhooks REST + actions SSA natives) — inchangé
        ( new \RiadaKids\Booking\BookingHooks( $service ) )->register();

        // AJAX
        ( new \RiadaKids\Ajax\BookingAjax( $service ) )->register();
        // SsaWidgetAjax supprimé (Phase 3.1) — tous ses hooks étaient liés au système custom
        new \RiadaKids\Ajax\ChildAjax();
        new \RiadaKids\Ajax\DashboardAjax();
        ( new \RiadaKids\Ajax\BookingEditAjax( $service ) )->register();
        // AJOUT — écran « تعديل الموعد » (reprogrammation interne coach +
        // date/heure), appelle l'API PHP interne de SSA Pro directement
        // (voir doc en tête de RescheduleAjax.php).
        ( new \RiadaKids\Ajax\RescheduleAjax( $service ) )->register();

        // Phase 3 — rôle + dashboard Coordinateur (LECTURE SEULE : aucune
        // planification, aucun SSA, aucune écriture crédit/SQL/hook final).
        \RiadaKids\Coordinator\CoordinatorRole::register();
        ( new \RiadaKids\Coordinator\CoordinatorAjax(
            new \RiadaKids\Coordinator\CoordinatorService( $repo )
        ) )->register();
        ( new \RiadaKids\Coordinator\CoordinatorDashboard() )->register();

        // Credits & Rewards — inchangé
        new \RiadaKids\Credits\CreditService();
        new \RiadaKids\Rewards\RewardService();

        // Frontend — endpoint WC dashboard — inchangé
        new \RiadaKids\Frontend\Dashboard( $service );

        // Assets (CSS/JS) — inchangé
        new Assets();

        // Admin — inchangé
        if ( is_admin() ) {
            new \RiadaKids\Admin\AdminPages();
            // EventsAdminPage désactivé — menu SSA الأحداث supprimé
            new \RiadaKids\Admin\DebugPage();
            // AJOUT — écran d'association Cours ↔ Type de rendez-vous SSA
            // (remplit wp_rk_ssa_map, lue par DB::get_ssa_type_for_course()
            // et désormais consommée par DashboardAjax::load_sessions()).
            new \RiadaKids\Admin\SSAMapAdminPage();
        }
    }

    /* ── Crons ────────────────────────────────────────────────────────────── */

    public function register_cron_schedules( array $s ): array {
        $s['rk_every_minute'] = [ 'interval' => 60,  'display' => 'Every 1 min (RK)' ];
        $s['rk_every_5min']   = [ 'interval' => 300, 'display' => 'Every 5 min (RK)' ];
        return $s;
    }

    private function schedule_crons(): void {
        foreach ( [
            'rk_ssa_sync_cron'   => 'rk_every_minute',
            'rk_cleanup_cron'    => 'daily',
            'rk_job_worker_cron' => 'rk_every_5min',
        ] as $hook => $rec ) {
            if ( ! wp_next_scheduled( $hook ) ) wp_schedule_event( time(), $rec, $hook );
        }
    }

    public function run_cron_sync(): void {
        try {
            if ( ! \RiadaKids\Database\DB::ssa_table_exists() ) return;
            $appts = \RiadaKids\Database\DB::get_recent_ssa_appointments( 50 );
            foreach ( $appts as $appt ) {
                $id = (int) ( $appt->id ?? 0 );
                if ( $id && get_transient( 'rk_cron_skip_' . $id ) ) continue;
                \RiadaKids\Database\DB::sync_ssa_appointment( (array) $appt );
            }
        } catch ( \Throwable $e ) {
            rk_log( 'CRON', $e->getMessage(), 'error' );
        }
    }

    public function run_cron_cleanup(): void {
        try {
            \RiadaKids\Database\DB::delete_old_credit_logs( 365 );
            \RiadaKids\Database\DB::delete_old_notifications( 90 );
        } catch ( \Throwable $e ) {
            rk_log( 'CLEANUP', $e->getMessage(), 'error' );
        }
    }

    public function run_job_worker(): void {
        try {
            foreach ( \RiadaKids\Database\DB::get_pending_jobs( 20 ) as $job ) {
                \RiadaKids\Database\DB::update_job_status( $job->id, 'processing' );
                $payload = json_decode( $job->payload, true ) ?: [];
                try {
                    $ok = ( $job->job_type === 'email' )
                        ? (bool) wp_mail( $payload['to'] ?? '', $payload['subject'] ?? '', $payload['body'] ?? '' )
                        : (bool) apply_filters( 'rk_process_job', false, $job->job_type, $payload );
                    \RiadaKids\Database\DB::update_job_status( $job->id, $ok ? 'done' : 'failed' );
                } catch ( \Throwable $e ) {
                    \RiadaKids\Database\DB::update_job_status( $job->id, 'failed', $e->getMessage() );
                }
            }
        } catch ( \Throwable $e ) {
            rk_log( 'JOBS', $e->getMessage(), 'error' );
        }
    }

    public function notice_guest_checkout(): void {
        if ( ! current_user_can( 'manage_options' ) ) return;
        if ( get_option( 'woocommerce_enable_guest_checkout' ) !== 'yes' ) return;
        echo '<div class="notice notice-warning is-dismissible"><p><strong>RiadaKids ⚠️</strong> : Guest Checkout WooCommerce activé — peut casser le mapping utilisateur. <a href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=account' ) ) . '">Désactiver</a></p></div>';
    }
}