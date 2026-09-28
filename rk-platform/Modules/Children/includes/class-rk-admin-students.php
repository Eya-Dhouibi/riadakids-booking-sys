<?php
declare( strict_types=1 );
/**
 * RK_Admin_Students — Remplace le callback de la page tutor-students.
 *
 * Garde exactement le style UX de Tutor LMS (mêmes templates, mêmes classes CSS)
 * mais affiche les données de wp_rk_children à la place de tutor_enrolled.
 *
 * @package RK_My_Children
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class RK_Admin_Students {

    const PER_PAGE = 20;

    /* ── Boot ──────────────────────────────────────────────────── */

    public static function init(): void {
        add_filter( 'tutor_admin_menu',      [ __CLASS__, 'override_tutor_callback' ] );
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_styles' ] );
    }

    /* ── Override Tutor LMS callback ───────────────────────────── */

    public static function override_tutor_callback( array $menus ): array {
        if ( isset( $menus['group_two']['students'] ) ) {
            $menus['group_two']['students']['callback'] = [ __CLASS__, 'render' ];
        }
        return $menus;
    }

    /* ── Enqueue ───────────────────────────────────────────────── */

    public static function enqueue_styles( string $hook ): void {
        if ( strpos( $hook, 'tutor-students' ) === false ) return;
        wp_enqueue_style(
            'rk-admin-students',
            plugin_dir_url( dirname( __FILE__ ) ) . 'assets/css/rk-admin-students.css',
            [],
            RK_MC_VERSION
        );
    }

    /* ── Query helpers ─────────────────────────────────────────── */

    private static function get_children( int $offset, int $limit, string $search ): array {
        return RK_MC_Child_Repository::search_paginated( $search, $limit, $offset );
    }

    private static function get_total( string $search ): int {
        return RK_MC_Child_Repository::count_search( $search );
    }

    private static function get_course_count( ?int $wp_user_id ): int {
        if ( ! $wp_user_id ) return 0;
        $ids = tutor_utils()->get_enrolled_courses_ids_by_user( $wp_user_id );
        return is_array( $ids ) ? count( $ids ) : 0;
    }

    /**
     * Pour tous les child_ids de la page courante, récupère en une seule query
     * le prochain booking confirmé (appointment > NOW()) avec le nom du coach.
     *
     * @return array<int, object{appointment:string, session_name:string, coach_name:string}>
     */
    private static function get_bookings_batch( array $child_ids ): array {
        if ( ! class_exists( 'RKP_BookingRepository' ) ) return [];
        return RKP_BookingRepository::get_next_confirmed_batch( $child_ids );
    }

    /* ── Render ────────────────────────────────────────────────── */

    public static function render(): void {
        if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'manage_tutor' ) ) {
            wp_die( __( 'Permission refusée.', 'rk-my-children' ) );
        }

        $search   = sanitize_text_field( $_GET['search'] ?? '' );
        $paged    = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
        $per_page = self::PER_PAGE;
        $offset   = ( $paged - 1 ) * $per_page;

        $rows     = self::get_children( $offset, $per_page, $search );
        $total    = self::get_total( $search );
        $child_ids = array_map( static fn( $r ) => (int) $r->child_id, $rows );
        $bookings  = self::get_bookings_batch( $child_ids );

        $navbar_data = [ 'page_title' => __( 'Students', 'tutor' ), 'hide_action_buttons' => true ];
        $filters     = [
            'bulk_action'  => false,
            'bulk_actions' => [],
            'ajax_action'  => '',
            'filters'      => [],
        ];

        $base_url = add_query_arg( [
            'page'   => 'tutor-students',
            'search' => $search ?: null,
        ], admin_url( 'admin.php' ) );

        $pagination_data = [
            'total_items' => $total,
            'per_page'    => $per_page,
            'paged'       => $paged,
            'base'        => add_query_arg( [
                'page'   => 'tutor-students',
                'search' => $search ?: null,
                'paged'  => '%#%',
            ], admin_url( 'admin.php' ) ),
        ];
        ?>
        <div class="tutor-admin-wrap" dir="rtl">

            <?php
            tutor_load_template_from_custom_path(
                tutor()->path . 'views/elements/list-navbar.php',
                $navbar_data
            );
            ?>

            <!-- Search bar (simplifié, sans filtres avancés) -->
            <div class="tutor-admin-container tutor-admin-container-lg">
                <div class="tutor-wp-dashboard-course-filter tutor-justify-end">
                    <div class="tutor-wp-dashboard-filter-right tutor-d-flex tutor-gap-1">
                        <form action="" method="get" id="tutor-rk-search-form">
                            <input type="hidden" name="page" value="tutor-students">
                            <div class="tutor-form-wrap">
                                <span class="tutor-form-icon">
                                    <span class="tutor-icon-search" aria-hidden="true"></span>
                                </span>
                                <input type="search" class="tutor-form-control"
                                       name="search"
                                       placeholder="<?php esc_attr_e( 'Search...', 'tutor' ); ?>"
                                       value="<?php echo esc_attr( $search ); ?>">
                            </div>
                        </form>
                        <?php if ( $search ) : ?>
                            <a class="tutor-color-subdued tutor-px-8 tutor-py-4"
                               href="<?php echo esc_url( admin_url( 'admin.php?page=tutor-students' ) ); ?>">
                                <?php esc_html_e( 'Clear All', 'tutor' ); ?>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="tutor-admin-container tutor-admin-container-lg tutor-mt-16">
                <?php if ( ! empty( $rows ) ) : ?>
                    <div class="tutor-table-responsive tutor-dashboard-list-table">
                        <table class="tutor-table tutor-table-middle tutor-table-with-checkbox">
                            <thead>
                                <tr>
                                    <th class="tutor-table-rows-sorting">الطفل</th>
                                    <th class="tutor-table-rows-sorting">البريد الإلكتروني</th>
                                    <th class="tutor-table-rows-sorting">العمر</th>
                                    <th class="tutor-table-rows-sorting">الدورات</th>
                                    <th class="tutor-table-rows-sorting">المدرّب</th>
                                    <th class="tutor-table-rows-sorting">الموعد القادم</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ( $rows as $row ) :
                                    $child_id    = (int) $row->child_id;
                                    $child_wp_id = $row->child_wp_id ? (int) $row->child_wp_id : null;
                                    $fiche_url   = add_query_arg( 'child_id', $child_id, home_url( RK_TUTOR_DASHBOARD_URL ) );
                                    $courses     = self::get_course_count( $child_wp_id );
                                    $avatar      = $child_wp_id
                                        ? tutor_utils()->get_tutor_avatar( $child_wp_id )
                                        : self::svg_avatar( (string) $row->child_name );
                                    $booking     = $bookings[ $child_id ] ?? null;
                                ?>
                                <tr>
                                    <!-- Name -->
                                    <td>
                                        <div class="tutor-d-flex tutor-align-center tutor-gap-1">
                                            <?php echo wp_kses( $avatar, tutor_utils()->allowed_avatar_tags() ); ?>
                                            <span><?php echo esc_html( $row->child_name ); ?></span>
                                            <?php if ( $child_wp_id ) : ?>
                                                <a href="<?php echo esc_url( tutor_utils()->profile_url( $child_wp_id, false ) ); ?>"
                                                   class="tutor-iconic-btn" target="_blank">
                                                    <span class="tutor-icon-external-link" aria-hidden="true"></span>
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    </td>

                                    <!-- Email parent -->
                                    <td>
                                        <span class="tutor-fs-7">
                                            <?php echo esc_html( $row->parent_email ?? '—' ); ?>
                                        </span>
                                    </td>

                                    <!-- Age -->
                                    <td>
                                        <span class="tutor-fs-7">
                                            <?php echo $row->child_age
                                                ? esc_html( $row->child_age ) . ' ' . __( 'ans', 'rk-my-children' )
                                                : '—'; ?>
                                        </span>
                                    </td>

                                    <!-- Courses -->
                                    <td>
                                        <span class="tutor-fs-7"><?php echo (int) $courses; ?></span>
                                    </td>

                                    <!-- Coach -->
                                    <td>
                                        <?php if ( $booking && $booking->coach_name ) : ?>
                                            <div class="tutor-d-flex tutor-align-center tutor-gap-1">
                                                <span class="rk-coach-dot"></span>
                                                <span class="tutor-fs-7"><?php echo esc_html( $booking->coach_name ); ?></span>
                                            </div>
                                        <?php else : ?>
                                            <span class="tutor-color-subdued tutor-fs-7">—</span>
                                        <?php endif; ?>
                                    </td>

                                    <!-- Prochain RDV -->
                                    <td>
                                        <?php if ( $booking && $booking->appointment ) :
                                            $_aid = (int) ( $booking->booking_id ?? 0 );
                                            $ts   = rk_mc_appt_timestamp( $booking->appointment );
                                            $day  = rk_mc_appt_format( $booking->appointment, 'd M', $_aid );
                                            $hr   = rk_mc_appt_format( $booking->appointment, 'H:i', $_aid );
                                        ?>
                                            <div class="rk-booking-cell">
                                                <span class="rk-booking-day"><?php echo esc_html( $day ); ?></span>
                                                <span class="rk-booking-hour"><?php echo esc_html( $hr ); ?></span>
                                            </div>
                                        <?php else : ?>
                                            <span class="tutor-color-subdued tutor-fs-7">—</span>
                                        <?php endif; ?>
                                    </td>

                                    <!-- Actions -->
                                    <td>
                                        <div class="tutor-d-flex tutor-align-center tutor-gap-1">
                                            <a href="<?php echo esc_url( $fiche_url ); ?>"
                                               target="_blank"
                                               class="tutor-btn tutor-btn-outline-primary tutor-btn-sm">
                                                <?php esc_html_e( 'الملف', 'rk-my-children' ); ?>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else : ?>
                    <?php tutils()->render_list_empty_state(); ?>
                <?php endif; ?>

                <div class="tutor-admin-page-pagination-wrapper tutor-mt-32">
                    <?php if ( $total > $per_page ) : ?>
                        <?php tutor_load_template_from_custom_path(
                            tutor()->path . 'views/elements/pagination.php',
                            $pagination_data
                        ); ?>
                    <?php endif; ?>
                </div>
            </div>

        </div>
        <?php
    }

    /* ── SVG avatar fallback (sans WP user) ───────────────────── */

    private static function svg_avatar( string $name ): string {
        $colors  = [ '#1B4F8C', '#2563eb', '#7c3aed', '#db2777', '#059669', '#d97706' ];
        $initials = mb_strtoupper( mb_substr( $name, 0, 1, 'UTF-8' ), 'UTF-8' );
        $color    = $colors[ abs( crc32( $name ) ) % count( $colors ) ];

        return sprintf(
            '<img src="data:image/svg+xml,' . rawurlencode(
                '<svg xmlns="http://www.w3.org/2000/svg" width="40" height="40">'
                . '<rect width="40" height="40" rx="50" fill="' . $color . '"/>'
                . '<text x="50%%" y="50%%" dominant-baseline="central" text-anchor="middle" '
                . 'fill="#fff" font-size="18" font-family="Tajawal,Tahoma,sans-serif">' . $initials . '</text>'
                . '</svg>'
            ) . '" width="40" height="40" alt="%s" style="border-radius:50%%">',
            esc_attr( $name )
        );
    }
}
