<?php
/**
 * RK_MC_Child_Dashboard
 *
 * Sprint 1 visual dashboard skeleton for Child Dashboard.
 * Uses PHP mock data and renders the final dashboard endpoint.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class RK_MC_Child_Dashboard {

    public static function render_dashboard() {
        if ( ! is_user_logged_in() ) {
            wp_safe_redirect( wc_get_page_permalink( 'myaccount' ) );
            exit;
        }

        // Validation ownership via service.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $child_id = absint( $_GET['child_id'] ?? 0 );
        if ( $child_id <= 0 ) {
            wp_safe_redirect( wc_get_account_endpoint_url( RK_MC_Endpoint::SLUG ) );
            exit;
        }

        $child = RK_MC_Child_Dashboard_Service::get_child_for_dashboard( $child_id, get_current_user_id() );
        if ( ! $child ) {
            wp_safe_redirect( wc_get_account_endpoint_url( RK_MC_Endpoint::SLUG ) );
            exit;
        }

        $view_data = RK_MC_Child_Dashboard_Data::get_dashboard_data( $child_id, $child );

        // Extrait du payload pour être accessible directement dans le template.
        // Le template existant n'est pas modifié — $snapshot s'ajoute à son scope.
        $snapshot = $view_data['snapshot'] ?? null;

        include RK_MC_DIR . 'templates/dashboard/dashboard.php';
    }
}
