<?php
declare( strict_types=1 );
/**
 * RK_Assessments_REST — /rk/v1/children/{child_id}/assessments et
 * /rk/v1/bookings/{booking_id}/assessment (mobile, lecture seule).
 *
 * Auth : self-access enfant (cf. RKP_MobileChildAccess) — le JWT
 * authentifie l'enfant lui-même, pas un parent. `notes` (privé coach)
 * est exclu par RKP_AssessmentQueryService::format_for_child() /
 * get_for_booking_safe(), jamais renvoyé au mobile.
 *
 * @package RK_Platform
 * @since   4.19.0
 * @since   4.19.1 Pivot self-access enfant + fix 500 sur handle_detail
 *          (cf. audit 16/09/2026 §2.1/§2.3).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class RK_Assessments_REST {

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes(): void {
		register_rest_route( 'rk/v1', '/children/(?P<child_id>\d+)/assessments', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'handle_list' ),
			'permission_callback' => 'is_user_logged_in',
		) );

		register_rest_route( 'rk/v1', '/bookings/(?P<booking_id>\d+)/assessment', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'handle_detail' ),
			'permission_callback' => 'is_user_logged_in',
		) );
	}

	public static function handle_list( WP_REST_Request $request ) {
		$child_id = (int) $request->get_param( 'child_id' );
		$user_id  = get_current_user_id();

		if ( ! class_exists( 'RKP_MobileChildAccess' ) || ! class_exists( 'RKP_AssessmentQueryService' ) ) {
			return new WP_REST_Response( array( 'error' => 'assessments_unavailable' ), 503 );
		}

		if ( ! RKP_MobileChildAccess::check_self_access( $child_id, $user_id ) ) {
			return new WP_REST_Response( array( 'error' => 'forbidden' ), 403 );
		}

		return new WP_REST_Response(
			array( 'assessments' => RKP_AssessmentQueryService::get_all_for_child_safe( $child_id ) ),
			200
		);
	}

	/**
	 * Détail rattaché à une réservation. On résout d'abord l'identité self
	 * de l'enfant authentifié, PUIS on demande le bilan borné à ce
	 * child_id précis — get_for_booking_safe() ne retourne jamais un
	 * bilan d'un autre enfant, et ne lève jamais d'erreur fatale si le
	 * bilan n'existe pas (contrairement à l'ancienne
	 * get_for_booking_for_parent() qui n'a jamais existé — cf. audit §2.3).
	 */
	public static function handle_detail( WP_REST_Request $request ) {
		$booking_id = (int) $request->get_param( 'booking_id' );
		$user_id    = get_current_user_id();

		if ( ! class_exists( 'RKP_MobileChildAccess' ) || ! class_exists( 'RKP_AssessmentQueryService' ) ) {
			return new WP_REST_Response( array( 'error' => 'assessments_unavailable' ), 503 );
		}

		$self = RKP_MobileChildAccess::resolve_self( $user_id );
		if ( ! $self ) {
			return new WP_REST_Response( array( 'error' => 'forbidden' ), 403 );
		}

		$assessment = RKP_AssessmentQueryService::get_for_booking_safe( $booking_id, (int) $self->id );
		if ( null === $assessment ) {
			return new WP_REST_Response( array( 'error' => 'forbidden' ), 403 );
		}

		return new WP_REST_Response( array( 'assessment' => $assessment ), 200 );
	}
}
