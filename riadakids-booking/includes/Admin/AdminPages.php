<?php
/**
 * RiadaKids\Admin\AdminPages
 *
 * Page principale d'administration des bookings.
 * Menu : RK Bookings (dashicons-calendar-alt)
 * Fonctionnalités : liste paginée, filtres, export CSV, stats.
 */

namespace RiadaKids\Admin;

use RiadaKids\Core\Security;
use RiadaKids\Credits\CreditRepository;

if ( ! defined( 'ABSPATH' ) ) exit;

class AdminPages {

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'register_menu' ] );
    }

    public function register_menu(): void {
        add_menu_page(
            'Riadakids — الحجوزات',
            'RK Bookings',
            'manage_options',
            'rk-bookings',
            [ $this, 'render' ],
            'dashicons-calendar-alt',
            56
        );
    }

    /**
     * Construction sécurisée des conditions SQL (WHERE)
     */
    private function build_secure_query_conditions( array $filters ): array {
        $where = [];
        $args  = [];

        if ( ! empty( $filters['search'] ) ) {
            $where[] = "(customer_name LIKE %s OR customer_email LIKE %s OR session_name LIKE %s)";
            $like    = '%' . $filters['search'] . '%';
            $args[]  = $like;
            $args[]  = $like;
            $args[]  = $like;
        }

        if ( ! empty( $filters['status'] ) ) {
            $where[] = "status = %s";
            $args[]  = $filters['status'];
        }

        if ( ! empty( $filters['program_id'] ) ) {
            $where[] = "program_id = %d";
            $args[]  = $filters['program_id'];
        }

        return [
            'where_sql' => ! empty( $where ) ? 'WHERE ' . implode( ' AND ', $where ) : '',
            'args'      => $args
        ];
    }

    public function render(): void {
        Security::require_admin();
        global $wpdb;

        $filters = [
            'search'     => sanitize_text_field( $_GET['s']       ?? '' ),
            'status'     => sanitize_text_field( $_GET['status']  ?? '' ),
            'program_id' => absint( $_GET['program'] ?? 0 ),
        ];

        $paged    = max( 1, intval( $_GET['paged'] ?? 1 ) );
        $per_page = 25;
        $offset   = ( $paged - 1 ) * $per_page;

        $query_data = $this->build_secure_query_conditions( $filters );
        $table_name = $wpdb->prefix . 'rk_bookings';

        // 1. Calcul du total des lignes pour la pagination
        if ( ! empty( $query_data['where_sql'] ) ) {
            $count_sql = $wpdb->prepare( "SELECT COUNT(*) FROM {$table_name} {$query_data['where_sql']}", ...$query_data['args'] );
        } else {
            $count_sql = "SELECT COUNT(*) FROM {$table_name}";
        }
        $total = (int) $wpdb->get_var( $count_sql );

        // 2. Récupération des résultats paginés
        if ( ! empty( $query_data['where_sql'] ) ) {
            $data_sql = "SELECT * FROM {$table_name} {$query_data['where_sql']} ORDER BY id DESC LIMIT %d OFFSET %d";
            $query_args = array_merge( $query_data['args'], [ $per_page, $offset ] );
            $rows = $wpdb->get_results( $wpdb->prepare( $data_sql, ...$query_args ) ) ?: [];
        } else {
            $rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table_name} ORDER BY id DESC LIMIT %d OFFSET %d", $per_page, $offset ) ) ?: [];
        }

        $total_pages = (int) ceil( $total / $per_page );

        // Statistiques globales
        $stats = $wpdb->get_row(
            "SELECT
                COUNT(*) AS total,
                SUM(status='confirmed') AS confirmed,
                SUM(status='pending_credit') AS pending_credit,
                SUM(status='cancelled') AS cancelled
             FROM {$table_name}"
        );

        $programs = get_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => false ] );

        $this->render_page( $rows, $total, $total_pages, $paged, $filters, $stats, $programs );
    }

    private function render_page(
        array  $rows,
        int    $total,
        int    $total_pages,
        int    $paged,
        array  $filters,
        object $stats,
        array  $programs
    ): void {
        global $wpdb;

        // Pré-chargement en lot de tous les enfants concernés pour optimiser les requêtes SQL
        $all_child_ids = [];
        foreach ( $rows as $r ) {
            $ids = json_decode( $r->children_ids ?? '[]', true );
            if ( is_array( $ids ) ) foreach ( $ids as $id ) { if ( $id > 0 ) $all_child_ids[$id] = true; }
            if ( ! empty( $r->child_id ) ) $all_child_ids[(int) $r->child_id] = true;
        }
        $children_map = [];
        if ( ! empty( $all_child_ids ) ) {
            $id_list      = array_keys( $all_child_ids );
            $placeholders = implode( ',', array_fill( 0, count( $id_list ), '%d' ) );
            $fetched      = $wpdb->get_results( $wpdb->prepare(
                "SELECT id, child_name, child_age FROM {$wpdb->prefix}rk_children WHERE id IN ({$placeholders})",
                ...$id_list
            ) ) ?: [];
            foreach ( $fetched as $c ) $children_map[(int) $c->id] = $c;
        }

        $base_url = admin_url( 'admin.php?page=rk-bookings' );
        ?>
        <style>
        .rk-admin-children-table { border-collapse:collapse; width:100%; margin-top:6px; font-size:12px; }
        .rk-admin-children-table th,
        .rk-admin-children-table td { padding:4px 8px; border:1px solid #ddd; text-align:right; }
        .rk-admin-children-table th { background:#f5f5f5; font-weight:600; color:#444; }
        </style>
        <div class="wrap" dir="rtl">
            <h1 class="wp-heading-inline"><?php echo \RiadaKids\Core\Icons::get( 'calendar', 18 ); ?> Riadakids — الحجوزات</h1>
            <hr class="wp-header-end">

            <div style="display:flex;gap:14px;margin:16px 0;flex-wrap:wrap;">
                <?php foreach ([
                    [ 'إجمالي الحجوزات', ($stats->total ?? 0),          '#3b5bdb' ],
                    [ 'مؤكدة',           ($stats->confirmed ?? 0),      '#2b8a3e' ],
                    [ 'في انتظار رصيد',  ($stats->pending_credit ?? 0),  '#e67700' ],
                    [ 'ملغاة',           ($stats->cancelled ?? 0),      '#c92a2a' ],
                ] as [$label, $val, $color] ) : ?>
                <div style="background:#fff;border:1px solid #e9ecef;border-radius:8px;padding:14px 20px;min-width:130px;text-align:center;box-shadow:0 1px 4px rgba(0,0,0,.06);">
                    <div style="font-size:24px;font-weight:800;color:<?php echo $color; ?>;"><?php echo (int) $val; ?></div>
                    <div style="font-size:12px;color:#666;margin-top:4px;"><?php echo esc_html( $label ); ?></div>
                </div>
                <?php endforeach; ?>
            </div>

            <form method="get" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:16px;">
                <input type="hidden" name="page" value="rk-bookings">
                <input type="search" name="s" value="<?php echo esc_attr( $filters['search'] ); ?>" placeholder="بحث..." style="width:220px;">
                <select name="status">
                    <option value="">— كل الحالات —</option>
                    <?php foreach ( [ 'confirmed' => 'مؤكد', 'pending_credit' => 'في انتظار رصيد', 'cancelled' => 'ملغي', 'rescheduled' => 'مُعاد جدولة' ] as $v => $l ) : ?>
                    <option value="<?php echo $v; ?>" <?php selected( $filters['status'], $v ); ?>><?php echo esc_html( $l ); ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="program">
                    <option value="">— كل البرامج —</option>
                    <?php if ( ! is_wp_error( $programs ) && ! empty( $programs ) ) : ?>
                        <?php foreach ( $programs as $term ) : ?>
                        <option value="<?php echo $term->term_id; ?>" <?php selected( $filters['program_id'], $term->term_id ); ?>><?php echo esc_html( $term->name ); ?></option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
                <input type="submit" class="button" value="فلترة">
                <a href="<?php echo esc_url( $base_url ); ?>" class="button button-link">↺ إعادة ضبط</a>
            </form>

            <p style="color:#666;font-size:13px;"><?php echo $total; ?> نتيجة</p>

            <table class="wp-list-table widefat fixed striped" dir="rtl">
                <thead>
                    <tr>
                        <th style="text-align:right;">العميل</th>
                        <th style="text-align:right;">البرنامج</th>
                        <th style="text-align:right;">الدورة</th>
                        <th style="text-align:right;">اللقاء</th>
                        <th style="text-align:right;">الطفل</th>
                        <th style="text-align:right;">العمر</th>
                        <th style="text-align:right;">الحالة</th>
                        <th style="text-align:right;">تاريخ اللقاء</th>
                        <th style="text-align:right;">رصيد العميل</th>
                        <th style="text-align:right;">SSA ID</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $rows as $row ) :
                    $balance = class_exists('\RiadaKids\Credits\CreditRepository') ? CreditRepository::get_balance( (int) $row->user_id ) : 0;
                    $status_colors = [
                        'confirmed'      => '#2b8a3e',
                        'pending_credit' => '#e67700',
                        'cancelled'      => '#c92a2a',
                        'rescheduled'    => '#1864ab',
                    ];
                    $status_labels = [
                        'confirmed'      => 'مؤكد',
                        'pending_credit' => 'في انتظار رصيد',
                        'cancelled'      => 'ملغي',
                        'rescheduled'    => 'مُعاد جدولة',
                    ];
                    $color = $status_colors[ $row->status ] ?? '#666';
                    $label = $status_labels[ $row->status ] ?? $row->status;

                    // ── 1. FIX DYNAMIQUE : NOM ET EMAIL DU CLIENT ──
                    $user = ! empty( $row->user_id ) ? get_user_by( 'id', (int) $row->user_id ) : false;
                    $customer_name  = $row->customer_name ?? '';
                    $customer_email = $row->customer_email ?? '';

                    if ( empty( $customer_name ) && $user ) {
                        $customer_name = $user->display_name;
                    }
                    if ( empty( $customer_email ) && $user ) {
                        $customer_email = $user->user_email;
                    }

                    // ── 2. FIX DYNAMIQUE : NOM DU PRODUIT WOOCOMMERCE ──
                    $session_name = $row->session_name ?? '';
                    if ( empty( $session_name ) && ! empty( $row->product_id ) && function_exists('wc_get_product') ) {
                        $product = wc_get_product( (int) $row->product_id );
                        if ( $product ) {
                            $session_name = $product->get_name();
                        }
                    }
                ?>
                    <tr>
                        <td>
                            <strong><?php echo esc_html( $customer_name ?: '—' ); ?></strong><br>
                            <small style="color:#999;"><?php echo esc_html( $customer_email ); ?></small>
                        </td>
                        <td><?php echo esc_html( ( ! empty( $row->program_id ) ? get_term_field('name', $row->program_id, 'product_cat') : '' ) ?: ( ! empty( $row->program_id ) ? $row->program_id : '—' ) ); ?></td>
                        <td><?php echo esc_html( ( ! empty( $row->course_id ) ? get_the_title( $row->course_id ) : '' ) ?: ( ! empty( $row->course_id ) ? $row->course_id : '—' ) ); ?></td>
                        
                        <td><?php echo esc_html( $session_name ?: '—' ); ?></td>
                        
                        <td>
                            <?php
                            // Mode single-child : 1 réservation = 1 enfant
                            $cids = json_decode( $row->children_ids ?? '[]', true );
                            if ( ! is_array( $cids ) || empty( $cids ) ) {
                                $cids = ! empty( $row->child_id ) ? [ (int) $row->child_id ] : [];
                            }
                            $first = (int) ( $cids[0] ?? 0 );
                            $obj   = $children_map[ $first ] ?? null;
                            $rk_c    = $obj ?: $row;
                            $rk_full = trim(
                                (string) ( $rk_c->child_name ?? '' ) . ' ' .
                                (string) ( $rk_c->child_family_name ?? '' )
                            );
                            echo esc_html( $rk_full ?: '—' );
                            ?>
                        </td>
                        
                        <td><?php
                            $cids_json = json_decode( $row->children_ids ?? '[]', true );
                            // Mode single-child : on affiche l'âge du premier (et unique) enfant
                            $first_id = ! empty( $cids_json[0] ) ? (int) $cids_json[0] : ( ! empty( $row->child_id ) ? (int) $row->child_id : 0 );
                            echo ! empty( $children_map[$first_id]->child_age ) ? esc_html( $children_map[$first_id]->child_age ) : '—';
                        ?></td>
                        <td><span style="color:<?php echo $color; ?>;font-weight:600;"><?php echo esc_html( $label ); ?></span></td>
                        <td><?php echo ! empty( $row->appointment ) ? date_i18n( 'j M Y H:i', strtotime( $row->appointment ) ) : '—'; ?></td>
                        <td style="font-weight:700;color:<?php echo $balance > 0 ? '#2b8a3e' : '#c92a2a'; ?>">
                            <?php echo (int) $balance; ?> حصة
                        </td>
                        <td>
                            <?php
                            $appt_id_for_link = (int) ( $row->booking_id ?? 0 );
                            if ( $appt_id_for_link > 0 ) :
                                $ssa_canonical = admin_url( 'admin.php?page=simply-schedule-appointments#/ssa/appointment/' . $appt_id_for_link );
                            ?>
                                <a href="<?php echo esc_url( $ssa_canonical ); ?>" target="_blank" title="Voir RDV #<?php echo $appt_id_for_link; ?> dans SSA">
                                    #<?php echo $appt_id_for_link; ?>
                                </a>
                            <?php else : ?>
                                —
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>

                <?php if ( empty( $rows ) ) : ?>
                    <tr><td colspan="11" style="text-align:center;color:#999;padding:30px;">لا توجد نتائج</td></tr>
                <?php endif; ?>
                </tbody>
            </table>

            <?php if ( $total_pages > 1 ) : ?>
            <div style="margin-top:16px;display:flex;gap:6px;justify-content:center;">
                <?php for ( $p = 1; $p <= $total_pages; $p++ ) : ?>
                <a href="<?php echo esc_url( add_query_arg( 'paged', $p, $base_url ) ); ?>"
                   style="padding:5px 10px;border:1px solid #ccc;border-radius:4px;<?php echo $p === $paged ? 'background:#3b5bdb;color:#fff;border-color:#3b5bdb;' : 'background:#fff;'; ?>">
                    <?php echo $p; ?>
                </a>
                <?php endfor; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }
}