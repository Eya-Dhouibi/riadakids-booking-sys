<?php
declare( strict_types=1 );
/**
 * RK_Coach_Dashboard — orchestrateur du dashboard coach.
 * v3.0 — 6 KPIs, home enrichi, design professionnel.
 *
 * @package RK_Coach_Hub
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/* Traits — factorisation par fonctionnalité (logique inchangée) */
require_once __DIR__ . '/traits/trait-rk-coach-dash-page-home.php';
require_once __DIR__ . '/traits/trait-rk-coach-dash-pages.php';
require_once __DIR__ . '/traits/trait-rk-coach-dash-page-journey.php';
require_once __DIR__ . '/traits/trait-rk-coach-dash-sidebar-html.php';

class RK_Coach_Dashboard {
    use RK_Coach_Dash_Page_Home;
    use RK_Coach_Dash_Pages;
    use RK_Coach_Dash_Page_Journey;
    use RK_Coach_Dash_Sidebar_Html;


    private static $rk_slugs = [
        // Pages pédagogiques (Learning Management)
        'rk-mes-cours', 'rk-progression', 'rk-lecons', 'rk-quiz',
        // Pages existantes
        'rk-mes-eleves', 'rk-seances', 'rk-evaluer', 'rk-messagerie',
        'rk-fiche-eleve', 'rk-assigner-mission', 'rk-stats', 'rk-badges',
        'rk-calendrier',
        // Vue Journey — parcours complet d'un enfant
        'rk-journey',
    ];

    private static $coach_sidebar_buffering = false;

    private static $hidden_pages = [
        'withdraw', 'reviews', 'wishlist', 'purchase_history',
    ];

    /* ─── Init ──────────────────────────────────────────────────────── */

    public static function init(): void {
        RK_Coach_Auth::init();   // login / logout / redirections → class-rk-coach-auth.php

        add_action( 'template_redirect',                   [ __CLASS__, 'setup_hooks' ], 6 );
        add_action( 'admin_post_rk_coach_eval',            [ 'RK_Coach_Evaluations', 'handle_submit'   ] );
        add_action( 'admin_post_rk_coach_monthly_eval',    [ 'RK_Coach_Evaluations', 'handle_monthly'  ] );
        add_action( 'admin_post_rk_coach_assign_mission',  [ 'RK_Coach_Missions',    'handle_assign'   ] );
        add_action( 'admin_post_rk_coach_mission_progress',[ 'RK_Coach_Missions',    'handle_progress' ] );
        add_action( 'admin_post_rk_coach_add_points',      [ __CLASS__, 'handle_add_points'  ] );
        // v9.10 — retiré : doublon avec RK_Coach_Child_Detail::handle_award_badge
        // (via trait RK_Coach_Child_Handlers, déjà enregistré dans rk-platform.php,
        // version plus complète avec vérification coach_owns_child). Les deux
        // s'exécutaient à chaque soumission de formulaire, un vrai bug préexistant
        // découvert en travaillant sur la cohérence visuelle des badges.
        add_action( 'admin_post_rk_coach_mark_attendance', [ 'RK_Coach_Sessions',    'handle_mark_attendance' ] );
        add_action( 'admin_post_rk_coach_save_notes',      [ __CLASS__, 'handle_save_notes'  ] );
    }

    /* ─── Rôle ─────────────────────────────────────────────────────── */

    /* Login / logout / redirections → RK_Coach_Auth (class-rk-coach-auth.php) */


    public static function is_coach_user( WP_User $user ): bool {
        if ( ! $user->ID ) return false;
        if ( $user->has_cap( 'tutor_instructor' ) ) return true;
        foreach ( [ 'rk_coach', 'coach', 'instructor', 'tutor_instructor' ] as $role ) {
            if ( in_array( $role, (array) $user->roles, true ) ) return true;
        }
        return false;
    }

    /* ─── Hooks ─────────────────────────────────────────────────────── */

    public static function setup_hooks(): void {
        if ( ! function_exists( 'tutor_utils' ) ) return;
        if ( ! tutor_utils()->is_tutor_frontend_dashboard() ) return;
        if ( ! self::is_coach() ) return;

        add_filter( 'tutor_dashboard/instructor_nav_items',             [ __CLASS__, 'add_nav_items'      ] );
        add_filter( 'tutor_dashboard/nav_items',                        [ __CLASS__, 'hide_nav_pages'     ] );
        add_filter( 'load_dashboard_template_part_from_other_location', [ __CLASS__, 'intercept_template'   ] );
        add_action( 'tutor_before_dashboard_content',                   [ __CLASS__, 'dispatch'            ] ); // home
        add_action( 'tutor_load_dashboard_template_before',             [ __CLASS__, 'dispatch'            ] ); // sous-pages
        add_action( 'wp_enqueue_scripts',                               [ __CLASS__, 'enqueue_assets'      ] );
        add_filter( 'body_class',                                       [ __CLASS__, 'add_body_class'     ] );
        add_filter( 'template_include',                                 [ __CLASS__, 'no_chrome_template' ], 100 );
        add_action( 'tutor_dashboard/before/wrap', [ __CLASS__, 'sidebar_buffer_start' ], 2   );
        add_action( 'tutor_dashboard/after/wrap',  [ __CLASS__, 'sidebar_buffer_end'   ], 997 );
    }

    public static function is_coach(): bool {
        $user = wp_get_current_user();
        if ( ! $user->ID ) return false;
        // Vérifier les rôles DIRECTEMENT — current_user_can() est filtré par Tutor LMS
        // et peut retourner true pour les admins, ce qui provoquerait de faux positifs.
        $roles = (array) $user->roles;
        foreach ( [ 'tutor_instructor', 'rk_coach', 'coach', 'instructor' ] as $role ) {
            if ( in_array( $role, $roles, true ) ) return true;
        }
        return false;
    }

    private static function current_page(): string {
        global $wp_query;
        return (string) ( $wp_query->query_vars['tutor_dashboard_page'] ?? '' );
    }

    /* ─── Navigation ────────────────────────────────────────────────── */

    public static function add_nav_items( array $items ): array {
        $rk = [
            // ── Learning Management (primaire) ──────────────────────
            'rk-mes-cours'        => [ 'title' => 'دوراتي',         'icon' => 'tutor-icon-book',          'auth_cap' => 'tutor_instructor' ],
            'rk-mes-eleves'       => [ 'title' => 'الأطفال',        'icon' => 'tutor-icon-user-bold',     'auth_cap' => 'tutor_instructor' ],
            'rk-progression'      => [ 'title' => 'التقدم',         'icon' => 'tutor-icon-chart-bar',     'auth_cap' => 'tutor_instructor' ],
            'rk-lecons'           => [ 'title' => 'الدروس',         'icon' => 'tutor-icon-list',          'auth_cap' => 'tutor_instructor' ],
            'rk-quiz'             => [ 'title' => 'الاختبارات',     'icon' => 'tutor-icon-star-bold',     'auth_cap' => 'tutor_instructor' ],
            'rk-journey'          => [ 'title' => 'مسار الطالب',    'icon' => 'tutor-icon-chart-bar',     'auth_cap' => 'tutor_instructor' ],
            // ── Actions pédagogiques ──────────────────────────────
            'rk-evaluer'          => [ 'title' => 'التقييمات',      'icon' => 'tutor-icon-clipboard',     'auth_cap' => 'tutor_instructor' ],
            'rk-assigner-mission' => [ 'title' => 'المهام',         'icon' => 'tutor-icon-target',        'auth_cap' => 'tutor_instructor' ],
            'rk-badges'           => [ 'title' => 'الشارات',        'icon' => 'tutor-icon-award',         'auth_cap' => 'tutor_instructor' ],
            // ── Planning + Communication ──────────────────────────
            'rk-seances'          => [ 'title' => 'لقاءات',        'icon' => 'tutor-icon-calendar-line', 'auth_cap' => 'tutor_instructor' ],
            'rk-calendrier'       => [ 'title' => 'التقويم',        'icon' => 'tutor-icon-calendar',      'auth_cap' => 'tutor_instructor' ],
            'rk-messagerie'       => [ 'title' => 'الرسائل',        'icon' => 'tutor-icon-chat',          'auth_cap' => 'tutor_instructor' ],
            'rk-stats'            => [ 'title' => 'الإحصاءات',      'icon' => 'tutor-icon-chart-bar',     'auth_cap' => 'tutor_instructor' ],
        ];
        $result = []; $inserted = false;
        foreach ( $items as $key => $item ) {
            if ( $key === 'separator-2' && ! $inserted ) {
                foreach ( $rk as $k => $v ) $result[ $k ] = $v;
                $inserted = true;
            }
            $result[ $key ] = $item;
        }
        return $inserted ? $result : array_merge( $result, $rk );
    }

    public static function hide_nav_pages( array $items ): array {
        if ( ! self::is_coach() ) return $items;
        foreach ( self::$hidden_pages as $slug ) unset( $items[ $slug ] );
        return $items;
    }

    /* ─── Template + dispatch ────────────────────────────────────────── */

    public static function intercept_template( string $tpl ): string {
        $page = self::current_page();
        return in_array( $page, self::$rk_slugs, true ) ? RK_COACH_HUB_DIR . 'templates/rk-coach-noop.php' : $tpl;
    }

    public static function no_chrome_template( string $template ): string {
        return RK_COACH_HUB_DIR . 'templates/coach-no-chrome.php';
    }

    public static function register_rk_permalinks( array $items ): array {
        foreach ( self::$rk_slugs as $slug ) {
            if ( ! isset( $items[ $slug ] ) ) {
                $items[ $slug ] = [ 'title' => '' ];
            }
        }
        return $items;
    }

    public static function dispatch(): void {
        $page = self::current_page();
        switch ( $page ) {
            // ── Pages Learning Management (nouvelles) ──────────────
            case 'rk-mes-cours':       self::render_mes_cours();   break;
            case 'rk-progression':     self::render_progression(); break;
            case 'rk-lecons':          self::render_lecons();      break;
            case 'rk-quiz':            self::render_quiz();        break;
            case 'rk-calendrier':      self::render_calendrier();  break;
            case 'rk-journey':         self::render_journey();     break;
            // ── Pages existantes ───────────────────────────────────
            case 'rk-mes-eleves':      RK_Coach_Students::render();     break;
            case 'rk-seances':         RK_Coach_Sessions::render();     break;
            case 'rk-evaluer':         RK_Coach_Evaluations::render();  break;
            case 'rk-messagerie':      RK_Coach_Messaging::render();    break;
            case 'rk-fiche-eleve':     RK_Coach_Child_Detail::render(); break;
            case 'rk-assigner-mission':RK_Coach_Missions::render();     break;
            case 'rk-stats':           RK_Coach_Stats::render();        break;
            case 'rk-badges':          RK_Coach_Badges::render();       break;
            case '':
                if ( class_exists( 'RK_MC_Child_Context' ) && RK_MC_Child_Context::get_active_child() ) break;
                self::render_home();
                break;
        }
    }

    /* ─── Assets ────────────────────────────────────────────────────── */

    public static function enqueue_assets(): void {
        if ( ! self::is_coach() ) return;
        $deps = wp_style_is( 'tutor-frontend-dashboard', 'registered' ) ? [ 'tutor-frontend-dashboard' ] : [];
        wp_enqueue_style( 'rk-coach-hub', RK_COACH_HUB_URL . 'assets/css/rk-coach-hub.css', $deps, RK_COACH_HUB_VERSION );

        $toasts = [];
        if ( ! empty( $_GET['pts_done'] ) )     $toasts[] = [ 'type' => 'success', 'msg' => 'تم إضافة النقاط بنجاح' ];
        if ( ! empty( $_GET['badge_done'] ) )   $toasts[] = [ 'type' => 'success', 'msg' => 'تم منح الشارة بنجاح' ];
        if ( ! empty( $_GET['eval_done'] ) )    $toasts[] = [ 'type' => 'success', 'msg' => 'تم حفظ التقييم بنجاح' ];
        if ( ! empty( $_GET['notes_done'] ) )   $toasts[] = [ 'type' => 'success', 'msg' => 'تم حفظ الملاحظات' ];
        if ( ! empty( $_GET['mission_done'] ) ) $toasts[] = [ 'type' => 'success', 'msg' => 'تم إسناد المهمة بنجاح' ];
        if ( ! empty( $_GET['attn_done'] ) )    $toasts[] = [ 'type' => 'success', 'msg' => 'تم تسجيل الحضور' ];
        if ( ! empty( $_GET['badge_error'] ) )  $toasts[] = [ 'type' => 'error',   'msg' => 'مفتاح الشارة غير صالح' ];

        $confetti = ! empty( $_GET['badge_done'] );

        wp_enqueue_script(
            'rk-coach-dashboard',
            RK_COACH_HUB_URL . 'assets/js/rk-coach-dashboard.js',
            [ 'jquery' ],
            RK_COACH_HUB_VERSION,
            true
        );

        wp_localize_script( 'rk-coach-dashboard', 'rkCoachUI', [
            'toasts'   => $toasts,
            'confetti' => $confetti,
        ] );
    }

    public static function add_body_class( array $classes ): array {
        if ( self::is_coach() ) $classes[] = 'rk-coach-active';
        return $classes;
    }

    /* ─── Rate limiting ─────────────────────────────────────────────── */

    private static function rate_limit( string $action, int $user_id, int $max = 10, int $window = 60 ): bool {
        $key   = 'rk_rl_' . sanitize_key( $action ) . '_' . $user_id;
        $count = (int) get_transient( $key );
        if ( $count >= $max ) return false;
        set_transient( $key, $count + 1, $window );
        return true;
    }

    /* ─── Handlers ──────────────────────────────────────────────────── */

    public static function handle_add_points(): void {
        if ( ! check_admin_referer( 'rk_coach_add_points', 'rk_pts_nonce' ) ) wp_die();
        if ( ! current_user_can( 'tutor_instructor' ) ) wp_die();
        if ( ! self::rate_limit( 'add_points', get_current_user_id() ) ) wp_die( 'Rate limit exceeded.' );
        $coach_id = get_current_user_id();
        $child_id = absint( $_POST['child_id'] ?? 0 );
        if ( ! $child_id || ! RK_Coach_Data::coach_owns_child( $coach_id, $child_id ) ) wp_die( 'Accès refusé.' );
        $points   = min( 200, max( 1, absint( $_POST['points'] ?? 10 ) ) );
        $note     = sanitize_text_field( $_POST['note'] ?? '' );
        $redirect = tutor_utils()->tutor_dashboard_url( 'rk-fiche-eleve' ) . '?child_id=' . $child_id;
        if ( class_exists( 'RK_MC_Gamification_Service' ) ) {
            RK_MC_Gamification_Service::add_points( $child_id, $points, 'coach_manual', 0, $note );
            RK_Audit_Log::record( 'manual_points', $child_id, [ 'points' => $points, 'note' => $note ] );
        }
        wp_redirect( add_query_arg( 'pts_done', '1', $redirect ) );
        exit;
    }

    public static function handle_award_badge(): void {
        if ( ! check_admin_referer( 'rk_coach_award_badge', 'rk_badge_nonce' ) ) wp_die();
        if ( ! current_user_can( 'tutor_instructor' ) ) wp_die();
        if ( ! self::rate_limit( 'award_badge', get_current_user_id() ) ) wp_die( 'Rate limit exceeded.' );
        $coach_id  = get_current_user_id();
        $child_id  = absint( $_POST['child_id'] ?? 0 );
        $badge_key = sanitize_key( $_POST['badge_key'] ?? '' );
        if ( ! $child_id || ! $badge_key || ! RK_Coach_Data::coach_owns_child( $coach_id, $child_id ) ) wp_die( 'Accès refusé.' );
        $redirect  = tutor_utils()->tutor_dashboard_url( 'rk-fiche-eleve' ) . '?child_id=' . $child_id;
        if ( class_exists( 'RK_MC_Badge_Service' ) ) {
            $catalogue = RK_MC_Badge_Service::catalogue();
            if ( ! isset( $catalogue[ $badge_key ] ) ) {
                wp_redirect( add_query_arg( 'badge_error', 'invalid_key', $redirect ) );
                exit;
            }
            RK_MC_Badge_Service::award( $child_id, $badge_key, 'من المدرب' );
            RK_Audit_Log::record( 'manual_badge', $child_id, [ 'badge_key' => $badge_key ] );
        }
        wp_redirect( add_query_arg( 'badge_done', '1', $redirect ) );
        exit;
    }

    public static function handle_save_notes(): void {
        if ( ! check_admin_referer( 'rk_coach_save_notes', 'rk_notes_nonce' ) ) wp_die();
        if ( ! current_user_can( 'tutor_instructor' ) ) wp_die();
        $coach_id = get_current_user_id();
        $child_id = absint( $_POST['child_id'] ?? 0 );
        $notes    = sanitize_textarea_field( $_POST['private_notes'] ?? '' );
        if ( $child_id && RK_Coach_Data::coach_owns_child( $coach_id, $child_id ) ) {
            update_user_meta( $coach_id, 'rk_coach_notes_child_' . $child_id, $notes );
            RK_Audit_Log::record( 'save_notes', $child_id, [] );
        }
        $redirect = tutor_utils()->tutor_dashboard_url( 'rk-fiche-eleve' ) . '?child_id=' . $child_id . '&tab=notes';
        wp_redirect( add_query_arg( 'notes_done', '1', $redirect ) );
        exit;
    }
}
