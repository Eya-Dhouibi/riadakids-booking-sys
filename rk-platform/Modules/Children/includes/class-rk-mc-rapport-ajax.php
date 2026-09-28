<?php
declare( strict_types=1 );
/**
 * RK_MC_Rapport_Ajax  (v10.2.0 — nouveau)
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * AJOUT (demande utilisateur) — sur /my-account/rk-rapport/, le filtre
 * "برنامج" et la pagination des séances doivent changer de résultat SANS
 * recharger la page.
 *
 * Réplique EXACTEMENT la logique de sélection/filtre/pagination des
 * séances déjà présente dans templates/woocommerce/rk-rapport.php (même
 * requêtes SQL, même filtre par programme, même règle de pagination à
 * rk_per_page=6), puis rend le HTML via le MÊME partial que le rendu
 * initial de la page — templates/woocommerce/partials/rapport-sessions-grid.php
 * — pour garantir que le HTML retourné par AJAX est identique à celui
 * qu'un rechargement de page aurait produit.
 *
 * Sécurité : mêmes vérifications que la page elle-même — utilisateur
 * connecté, enfant appartenant bien au parent connecté (user_id match),
 * nonce dédié (rk_rapport_filter, généré dans rk-rapport.php).
 *
 * @package RK_My_Children
 * @since   10.2.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class RK_MC_Rapport_Ajax {

    public static function init(): void {
        add_action( 'wp_ajax_rk_rapport_filter_sessions', [ __CLASS__, 'handle_filter' ] );
    }

    public static function handle_filter(): void {
        check_ajax_referer( 'rk_rapport_filter', 'nonce' );

        $parent_id = get_current_user_id();
        if ( ! $parent_id ) {
            wp_send_json_error( 'not_logged_in', 401 );
        }

        global $wpdb;

        $child_id = absint( $_POST['child_id'] ?? 0 );
        if ( ! $child_id ) {
            wp_send_json_error( 'invalid_child', 400 );
        }

        // Sécurité — l'enfant doit appartenir au parent connecté (même
        // vérification que rk-rapport.php : SELECT ... WHERE user_id = %d).
        $child_row = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, child_name, wp_user_id, avatar_url
               FROM {$wpdb->prefix}rk_children
              WHERE id = %d AND user_id = %d",
            $child_id, $parent_id
        ), ARRAY_A );
        if ( ! $child_row ) {
            wp_send_json_error( 'access', 403 );
        }
        $child = (object) $child_row;

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce vérifié ci-dessus (check_ajax_referer)
        $rk_active_program = isset( $_POST['program'] ) ? sanitize_text_field( wp_unslash( $_POST['program'] ) ) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $rk_paged = max( 1, absint( $_POST['rp_page'] ?? 1 ) );
        $rk_per_page = 6;

        $base_url = wc_get_account_endpoint_url( RK_MC_Endpoint::RAPPORT_SLUG );
        $base_url = add_query_arg( 'child_id', $child_id, $base_url );
        if ( $rk_active_program ) {
            $base_url = add_query_arg( 'program', rawurlencode( $rk_active_program ), $base_url );
        }

        /* ── Séances de l'enfant — même requête que rk-rapport.php ──── */
        $_bt             = $wpdb->prefix . 'rk_bookings';
        $_has_coach_id   = (bool) $wpdb->get_var( "SHOW COLUMNS FROM `{$_bt}` LIKE 'coach_id'" );

        if ( $_has_coach_id ) {
            $sessions = $wpdb->get_results( $wpdb->prepare(
                "SELECT b.*,
                        t.name        AS program_name,
                        p.post_title  AS course_name,
                        COALESCE( NULLIF( u.display_name, '' ), NULLIF( b.coach, '' ) ) AS coach_resolved
                   FROM {$_bt} b
              LEFT JOIN {$wpdb->terms} t  ON t.term_id = b.program_id
              LEFT JOIN {$wpdb->posts} p  ON p.ID      = b.course_id
              LEFT JOIN {$wpdb->users} u  ON u.ID      = b.coach_id AND b.coach_id > 0
                  WHERE b.child_id = %d
               ORDER BY b.appointment DESC",
                $child_id
            ) ) ?: [];
        } else {
            $sessions = $wpdb->get_results( $wpdb->prepare(
                "SELECT b.*,
                        t.name        AS program_name,
                        p.post_title  AS course_name,
                        NULLIF( b.coach, '' ) AS coach_resolved
                   FROM {$_bt} b
              LEFT JOIN {$wpdb->terms} t ON t.term_id = b.program_id
              LEFT JOIN {$wpdb->posts} p ON p.ID      = b.course_id
                  WHERE b.child_id = %d
               ORDER BY b.appointment DESC",
                $child_id
            ) ) ?: [];
        }

        /* ── Évaluations par séance — même logique booking_id-first ─── */
        $rk_session_evals = [];
        if ( class_exists( 'RK_MC_Assessment_Service' ) ) {
            foreach ( $sessions as $s ) {
                $s_booking_id = (int) ( $s->booking_id ?? 0 );
                if ( ! $s_booking_id || isset( $rk_session_evals[ $s_booking_id ] ) ) continue;

                $s_eval = RK_MC_Assessment_Service::get_for_booking( $s_booking_id );
                if ( ! $s_eval ) {
                    $s_date  = substr( (string) ( $s->appointment ?? '' ), 0, 10 );
                    $s_coach = (int) ( $s->coach_id ?? 0 );
                    if ( $s_date && $s_coach ) {
                        $s_eval = RK_MC_Assessment_Service::get_for_child_date( $child_id, $s_date, $s_coach );
                    }
                }
                $rk_session_evals[ $s_booking_id ] = $s_eval;
            }
        }

        /* ── Filtre programme + pagination — même règle que rk-rapport.php ── */
        $rk_sessions_filtered = $rk_active_program
            ? array_filter( $sessions, static function ( $s ) use ( $rk_active_program ) {
                return trim( (string) ( $s->program_name ?? '' ) ) === $rk_active_program;
            } )
            : $sessions;
        $rk_sessions_filtered = array_values( $rk_sessions_filtered );

        $rk_total_sessions = count( $rk_sessions_filtered );
        $rk_total_pages    = max( 1, (int) ceil( $rk_total_sessions / $rk_per_page ) );
        $rk_paged          = min( $rk_paged, $rk_total_pages );
        $rk_page_offset    = ( $rk_paged - 1 ) * $rk_per_page;
        $rk_sessions_page  = array_slice( $rk_sessions_filtered, $rk_page_offset, $rk_per_page );

        $rk_icon_cycle = array( 'brain', 'code', 'book', 'rocket' );
        include RK_MC_DIR . 'templates/dashboard/partials/rkd4-icons.php';

        ob_start();
        include RK_MC_DIR . 'templates/woocommerce/partials/rapport-sessions-grid.php';
        $html = ob_get_clean();

        wp_send_json_success( [ 'html' => $html ] );
    }
}
