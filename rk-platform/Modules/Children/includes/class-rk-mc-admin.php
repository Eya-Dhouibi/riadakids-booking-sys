<?php
/**
 * RK_MC_Admin — Panneau d'administration Gamification (v7.0.0)
 *
 * Allows admins/coaches to:
 *  - Set skill levels per child (0–10 per skill)
 *  - Award or revoke badges
 *  - Add manual points and view points history
 *
 * Registered as a WooCommerce submenu: WooCommerce → Gamification Enfants.
 *
 * @package RK_My_Children
 * @since   7.0.0
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* Traits — factorisation par fonctionnalité (logique inchangée) */
require_once __DIR__ . '/traits/trait-rk-mc-admin-gamif-tabs.php';
require_once __DIR__ . '/traits/trait-rk-mc-admin-content-tabs.php';

class RK_MC_Admin {
    use RK_MC_Admin_Gamif_Tabs;
    use RK_MC_Admin_Content_Tabs;


    const PAGE_SLUG = 'rk-mc-gamification';

    /* ── Security ─────────────────────────────────────────────── */

    private static function require_admin(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Permissions insuffisantes.', 'rk-my-children' ), 403 );
        }
    }

    /* ── Boot ─────────────────────────────────────────────────── */

    public static function init(): void {
        add_action( 'admin_menu',  array( __CLASS__, 'register_menu' ) );
        add_action( 'admin_post_rk_mc_save_skills',      array( __CLASS__, 'handle_save_skills' ) );
        add_action( 'admin_post_rk_mc_award_badge',      array( __CLASS__, 'handle_award_badge' ) );
        add_action( 'admin_post_rk_mc_revoke_badge',     array( __CLASS__, 'handle_revoke_badge' ) );
        add_action( 'admin_post_rk_mc_add_points',       array( __CLASS__, 'handle_add_points' ) );
        add_action( 'admin_post_rk_mc_save_assessment',  array( __CLASS__, 'handle_save_assessment' ) );
        add_action( 'admin_post_rk_mc_delete_assessment',array( __CLASS__, 'handle_delete_assessment' ) );
        add_action( 'admin_post_rk_mc_reply_message',    array( __CLASS__, 'handle_reply_message' ) );
        add_action( 'admin_enqueue_scripts',              array( __CLASS__, 'enqueue_admin_styles' ) );
    }

    /* ── Menu ─────────────────────────────────────────────────── */

    public static function register_menu(): void {
        add_submenu_page(
            'woocommerce',
            __( 'Gamification Enfants', 'rk-my-children' ),
            __( 'Gamification Enfants', 'rk-my-children' ),
            'manage_options',
            self::PAGE_SLUG,
            array( __CLASS__, 'render_page' )
        );
    }

    public static function enqueue_admin_styles( string $hook ): void {
        if ( false === strpos( $hook, self::PAGE_SLUG ) ) {
            return;
        }
        wp_add_inline_style( 'wp-admin', self::inline_css() );
    }

    /* ══════════════════════════════════════════════════════════
       FORM HANDLERS
       ══════════════════════════════════════════════════════════ */

    public static function handle_save_skills(): void {
        self::require_admin();
        self::verify_nonce( 'rk_mc_save_skills' );
        $child_id = absint( $_POST['child_id'] ?? 0 );
        if ( ! $child_id ) {
            wp_die( esc_html__( 'ID enfant invalide.', 'rk-my-children' ) );
        }

        if ( class_exists( 'RK_MC_Skill_Service' ) ) {
            foreach ( array_keys( RK_MC_Skill_Service::definitions() ) as $key ) {
                $level = absint( $_POST[ 'skill_' . $key ] ?? 0 );
                RK_MC_Skill_Service::set_skill( $child_id, $key, min( 10, $level ) );
            }
        }

        wp_safe_redirect( add_query_arg( array(
            'page'     => self::PAGE_SLUG,
            'child_id' => $child_id,
            'tab'      => 'skills',
            'saved'    => '1',
        ), admin_url( 'admin.php' ) ) );
        exit;
    }

    public static function handle_award_badge(): void {
        self::require_admin();
        self::verify_nonce( 'rk_mc_award_badge' );
        $child_id  = absint( $_POST['child_id'] ?? 0 );
        $badge_key = sanitize_key( $_POST['badge_key'] ?? '' );
        if ( $child_id && $badge_key && class_exists( 'RK_MC_Badge_Service' ) ) {
            RK_MC_Badge_Service::award( $child_id, $badge_key );
        }
        wp_safe_redirect( self::tab_url( $child_id, 'badges', '1' ) );
        exit;
    }

    public static function handle_revoke_badge(): void {
        self::require_admin();
        self::verify_nonce( 'rk_mc_revoke_badge' );
        $child_id  = absint( $_POST['child_id'] ?? 0 );
        $badge_key = sanitize_key( $_POST['badge_key'] ?? '' );
        if ( $child_id && $badge_key && class_exists( 'RK_MC_Badge_Service' ) ) {
            RK_MC_Badge_Service::revoke( $child_id, $badge_key );
        }
        wp_safe_redirect( self::tab_url( $child_id, 'badges' ) );
        exit;
    }

    public static function handle_add_points(): void {
        self::require_admin();
        self::verify_nonce( 'rk_mc_add_points' );
        $child_id = absint( $_POST['child_id'] ?? 0 );
        $points   = (int) ( $_POST['points'] ?? 0 );
        $source   = sanitize_key( $_POST['source'] ?? 'manual' );
        $note     = sanitize_text_field( $_POST['note'] ?? '' );

        if ( $child_id && $points !== 0 && class_exists( 'RK_MC_Gamification_Service' ) ) {
            RK_MC_Gamification_Service::add_points( $child_id, $points, $source, 0, $note );
        }
        wp_safe_redirect( self::tab_url( $child_id, 'points', '1' ) );
        exit;
    }

    /* ══════════════════════════════════════════════════════════
       HELPERS
       ══════════════════════════════════════════════════════════ */

    private static function verify_nonce( string $action ): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Permissions insuffisantes.', 'rk-my-children' ) );
        }
        check_admin_referer( $action );
    }

    private static function tab_url( int $child_id, string $tab, string $saved = '' ): string {
        $args = array(
            'page'     => self::PAGE_SLUG,
            'child_id' => $child_id,
            'tab'      => $tab,
        );
        if ( $saved ) {
            $args['saved'] = $saved;
        }
        return add_query_arg( $args, admin_url( 'admin.php' ) );
    }

    /** Returns all children across all parents, ordered by parent name then child name. */
    private static function get_all_children(): array {
        global $wpdb;
        $ct = rk_mc_children_table();
        $ut = $wpdb->users;
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return $wpdb->get_results(
            "SELECT c.id, c.child_name, c.user_id, u.display_name AS parent_name
             FROM {$ct} c
             LEFT JOIN {$ut} u ON u.ID = c.user_id
             ORDER BY u.display_name ASC, c.child_name ASC"
        ) ?: array();
    }

    /** Returns a single child row by id. */
    private static function get_child_by_id( int $id ): ?object {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            'SELECT * FROM ' . rk_mc_children_table() . ' WHERE id = %d LIMIT 1',
            $id
        ) );
    }

    /* ══════════════════════════════════════════════════════════
       INLINE CSS
       ══════════════════════════════════════════════════════════ */

    private static function inline_css(): string {
        return '
        /* RK Admin — Gamification panel */
        .rk-admin-wrap { max-width: 1200px; }
        .rk-admin-selector { display:flex; align-items:center; gap:10px; margin:16px 0 24px; }
        .rk-admin-selector select { min-width:260px; }
        .rk-admin-child-bar {
            padding:12px 18px;
            background:#fff;
            border:1px solid #e0e0e0;
            border-radius:6px;
            margin-bottom:20px;
            font-size:14px;
        }
        .rk-admin-progress-pill {
            display:inline-block;
            width:120px;
            height:8px;
            background:#e5e7eb;
            border-radius:999px;
            vertical-align:middle;
            margin:0 6px;
            position:relative;
            overflow:hidden;
        }
        .rk-admin-progress-pill span {
            position:absolute;
            inset:0 auto 0 0;
            background:#E8500A;
            border-radius:999px;
        }
        .rk-admin-tabs { margin-bottom:0; border-bottom:1px solid #c3c4c7; }
        .rk-admin-tab-content {
            background:#fff;
            border:1px solid #c3c4c7;
            border-top:none;
            padding:24px;
            border-radius:0 0 4px 4px;
        }
        .rk-admin-empty { background:#f9f9f9; padding:30px; text-align:center; border:1px dashed #ddd; border-radius:6px; margin-top:16px; }

        /* Skills grid */
        .rk-skill-grid {
            display:grid;
            grid-template-columns:repeat(auto-fill, minmax(280px, 1fr));
            gap:16px;
            margin-bottom:20px;
        }
        .rk-skill-card {
            padding:16px;
            background:#f9fafb;
            border:1px solid #e5e7eb;
            border-radius:8px;
        }
        .rk-skill-card label { display:block; margin-bottom:10px; }
        .rk-skill-input-row { display:flex; align-items:center; gap:10px; margin-bottom:8px; }
        .rk-skill-input-row input[type=range] { flex:1; accent-color:#E8500A; }
        .rk-skill-val { min-width:26px; text-align:center; font-weight:700; font-size:1.1rem; }
        .rk-skill-bar { height:8px; background:#e5e7eb; border-radius:999px; overflow:hidden; }
        .rk-skill-bar span { display:block; height:100%; background:linear-gradient(90deg,#E8500A,#1B4F8C); border-radius:999px; transition:width .3s; }

        /* Badges grid */
        .rk-badge-admin-grid {
            display:grid;
            grid-template-columns:repeat(auto-fill, minmax(240px, 1fr));
            gap:14px;
        }
        .rk-badge-admin-item {
            padding:14px;
            background:#f9fafb;
            border:1px solid #e5e7eb;
            border-radius:8px;
            font-size:13px;
        }
        .rk-badge-admin-item--earned {
            background:#f0fdf4;
            border-color:#86efac;
        }
        .rk-badge-admin-item__head { margin-bottom:6px; }
        .rk-badge-admin-item p { margin:4px 0; color:#555; }
        .rk-badge-admin-date { color:#16a34a !important; font-weight:600; }
        .rk-badge-cat {
            display:inline-block;
            padding:1px 7px;
            border-radius:999px;
            font-size:11px;
            font-weight:700;
            background:#e5e7eb;
            color:#374151;
            text-transform:uppercase;
        }
        .rk-badge-cat--start   { background:#dcfce7; color:#166534; }
        .rk-badge-cat--streak  { background:#ffedd5; color:#c2410c; }
        .rk-badge-cat--mastery { background:#dbeafe; color:#1d4ed8; }
        .rk-badge-cat--skill   { background:#ede9fe; color:#6d28d9; }
        .rk-badge-cat--special { background:#fce7f3; color:#9d174d; }
        .rk-badge-cat--level   { background:#fef9c3; color:#92400e; }

        /* Points layout */
        .rk-points-layout { display:grid; grid-template-columns:360px 1fr; gap:30px; align-items:start; }
        .rk-points-form-wrap h3,
        .rk-points-history-wrap h3 { margin-top:0; }
        @media (max-width:900px) { .rk-points-layout { grid-template-columns:1fr; } }

        /* Bilans tab */
        .rk-bilan-wrap { max-width:720px; }
        .rk-bilan-wrap h3 { margin-top:0; }

        /* Messages tab */
        .rk-msg-admin-channel {
            margin-bottom:28px;
            padding:18px;
            background:#f9fafb;
            border:1px solid #e5e7eb;
            border-radius:8px;
        }
        .rk-msg-admin-channel h3 { margin-top:0; }
        .rk-msg-admin-list {
            max-height:280px;
            overflow-y:auto;
            padding:10px;
            background:#fff;
            border:1px solid #e5e7eb;
            border-radius:6px;
            margin-bottom:12px;
            display:flex;
            flex-direction:column;
            gap:8px;
        }
        .rk-msg-admin-item {
            font-size:13px;
            padding:8px 10px;
            border-radius:6px;
            background:#f1f5f9;
            line-height:1.5;
        }
        .rk-msg-admin-dir {
            display:inline-block;
            font-size:11px;
            font-weight:700;
            color:#6b7280;
            margin-bottom:2px;
        }
        ';
    }
}
