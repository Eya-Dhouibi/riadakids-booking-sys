<?php
/**
 * RiadaKids\Coordinator\CoordinatorAjax — Phase 3
 *
 * Endpoints AJAX du dashboard Coordinateur. TOUS en lecture seule en Phase 3.
 * Chaque endpoint, dans cet ordre :
 *   1. utilisateur connecté          → sinon 401 not_logged_in
 *   2. nonce 'rk_coordinator_nonce'  → sinon 403 invalid_nonce
 *   3. capability(ies) rk_*          → sinon 403 forbidden
 *   4. booking_id validé côté serveur (post rk_booking, workflow uniquement)
 *
 * Aucun wp_ajax_nopriv_*. Les rôles parent (customer) et coach
 * (tutor_instructor) ne possèdent aucune capability rk_* : refus en 403.
 *
 * @package RiadaKids\Coordinator
 */

namespace RiadaKids\Coordinator;

use RiadaKids\Booking\BookingStatus;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CoordinatorAjax {

    public const NONCE_ACTION = 'rk_coordinator_nonce';

    public function __construct( private readonly CoordinatorService $service ) {}

    public function register(): void {
        add_action( 'wp_ajax_rk_coord_get_dashboard', [ $this, 'handle_get_dashboard' ] );
        add_action( 'wp_ajax_rk_coord_get_booking',   [ $this, 'handle_get_booking' ] );
        add_action( 'wp_ajax_rk_coord_get_coaches',   [ $this, 'handle_get_coaches' ] );
    }

    /** Flags d'interface : purement informatifs, la sécurité reste côté serveur. */
    public static function ui_caps(): array {
        return [
            'view_details'  => current_user_can( CoordinatorRole::CAP_VIEW_DETAILS ),
            'assign_coach'  => current_user_can( CoordinatorRole::CAP_ASSIGN_COACH ),
            'schedule'      => current_user_can( CoordinatorRole::CAP_SCHEDULE ),
            'reschedule'    => current_user_can( CoordinatorRole::CAP_RESCHEDULE ),
            'manage_change' => current_user_can( CoordinatorRole::CAP_MANAGE_CHANGES ),
            'view_coaches'  => current_user_can( CoordinatorRole::CAP_VIEW_COACHES ),
        ];
    }

    /** Liste des 3 sections + compteurs. */
    public function handle_get_dashboard(): void {
        $this->authorize( CoordinatorRole::CAP_VIEW_REQUESTS );

        $new     = $this->service->list_by_status( BookingStatus::PENDING_SCHEDULE );
        $pending = $this->service->list_by_status( BookingStatus::PENDING_PARENT_CONFIRMATION );
        // Les demandes de changement exigent en plus rk_manage_change_requests.
        $changes = current_user_can( CoordinatorRole::CAP_MANAGE_CHANGES )
            ? $this->service->list_by_status( BookingStatus::CHANGE_REQUESTED )
            : null;

        wp_send_json_success( [
            'new'     => $new,
            'pending' => $pending,
            'changes' => $changes,
            'caps'    => self::ui_caps(),
        ] );
    }

    /** Détail d'un booking (panneau de préparation — aucune planification en Phase 3). */
    public function handle_get_booking(): void {
        $this->authorize( CoordinatorRole::CAP_VIEW_DETAILS );

        $booking_id = isset( $_POST['booking_id'] ) ? absint( wp_unslash( $_POST['booking_id'] ) ) : 0;
        if ( $booking_id <= 0 ) {
            wp_send_json_error( [ 'msg' => 'invalid_booking_id' ], 400 );
        }

        $res = $this->service->get_booking( $booking_id );
        if ( ! $res['ok'] ) {
            // Même réponse pour « n'existe pas » et « pas du nouveau workflow » :
            // on ne révèle pas l'existence d'un booking legacy.
            wp_send_json_error( [ 'msg' => 'booking_not_found' ], 404 );
        }
        if ( $res['booking']['status'] === BookingStatus::CHANGE_REQUESTED
            && ! current_user_can( CoordinatorRole::CAP_MANAGE_CHANGES ) ) {
            wp_send_json_error( [ 'msg' => 'forbidden' ], 403 );
        }

        wp_send_json_success( [ 'booking' => $res['booking'], 'caps' => self::ui_caps() ] );
    }

    /** Liste des coachs (nom + propositions en attente). Pas de SSA en Phase 3. */
    public function handle_get_coaches(): void {
        $this->authorize( CoordinatorRole::CAP_VIEW_COACHES );
        wp_send_json_success( [ 'coaches' => $this->service->coaches() ] );
    }

    /**
     * @param string ...$caps Toutes requises.
     */
    private function authorize( string ...$caps ): void {
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'msg' => 'not_logged_in' ], 401 );
        }
        if ( ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
            wp_send_json_error( [ 'msg' => 'invalid_nonce' ], 403 );
        }
        foreach ( $caps as $cap ) {
            if ( ! current_user_can( $cap ) ) {
                wp_send_json_error( [ 'msg' => 'forbidden' ], 403 );
            }
        }
    }
}
