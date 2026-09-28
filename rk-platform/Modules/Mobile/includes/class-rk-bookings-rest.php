<?php
declare( strict_types=1 );
/**
 * RK_Bookings_REST — /rk/v1/children/{child_id}/bookings et
 * /rk/v1/bookings/{booking_id} (mobile, LECTURE SEULE).
 *
 * Auth : self-access enfant (RKP_MobileChildAccess) — le child_id de l'URL
 * doit être celui de l'enfant authentifié, jamais un autre. Le détail
 * d'une réservation est borné au même child_id via RKP_BookingRepository::
 * find() + comparaison ->child_id, jamais via une méthode "for_parent"
 * qui n'a jamais existé (cf. audit 16/09/2026 §3.3 — 500 garanti).
 *
 * @package RK_Platform
 * @since   4.19.0
 * @since   4.19.1 Pivot self-access enfant + fix 500 sur handle_detail.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class RK_Bookings_REST {

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes(): void {
		register_rest_route( 'rk/v1', '/children/(?P<child_id>\d+)/bookings', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'handle_list' ),
			'permission_callback' => 'is_user_logged_in',
			'args'                => array(
				'status' => array(
					'required'          => false,
					'type'              => 'string',
					'enum'              => array( 'upcoming', 'past', 'all' ),
					'default'           => 'upcoming',
				),
			),
		) );

		register_rest_route( 'rk/v1', '/bookings/(?P<booking_id>\d+)', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'handle_detail' ),
			'permission_callback' => 'is_user_logged_in',
		) );
	}

	public static function handle_list( WP_REST_Request $request ) {
		$child_id = (int) $request->get_param( 'child_id' );
		$user_id  = get_current_user_id();

		if ( ! class_exists( 'RKP_MobileChildAccess' ) || ! class_exists( 'RKP_BookingQueryService' ) ) {
			return new WP_REST_Response( array( 'error' => 'bookings_unavailable' ), 503 );
		}

		if ( ! RKP_MobileChildAccess::check_self_access( $child_id, $user_id ) ) {
			return new WP_REST_Response( array( 'error' => 'forbidden' ), 403 );
		}

		$status   = (string) $request->get_param( 'status' );
		$bookings = RKP_BookingQueryService::get_all_for_child( $child_id, $status );

		return new WP_REST_Response(
			array( 'bookings' => array_map( array( __CLASS__, 'format_booking' ), $bookings ) ),
			200
		);
	}

	/**
	 * Détail d'une réservation, borné à l'enfant authentifié. Pas de
	 * service "_for_parent" — on lit la réservation puis on vérifie
	 * nous-mêmes child_id === enfant authentifié, exactement comme
	 * RKP_AssessmentQueryService::get_for_booking_safe().
	 */
	public static function handle_detail( WP_REST_Request $request ) {
		$booking_id = (int) $request->get_param( 'booking_id' );
		$user_id    = get_current_user_id();

		if ( ! class_exists( 'RKP_MobileChildAccess' ) || ! class_exists( 'RKP_BookingRepository' ) ) {
			return new WP_REST_Response( array( 'error' => 'bookings_unavailable' ), 503 );
		}

		$self = RKP_MobileChildAccess::resolve_self( $user_id );
		if ( ! $self ) {
			return new WP_REST_Response( array( 'error' => 'forbidden' ), 403 );
		}

		$booking = RKP_BookingRepository::find( $booking_id );
		if ( ! $booking || (int) $booking->child_id !== (int) $self->id ) {
			return new WP_REST_Response( array( 'error' => 'forbidden' ), 403 );
		}

		return new WP_REST_Response( array( 'booking' => self::format_booking( $booking ) ), 200 );
	}

	private static function format_booking( RKP_Booking $b ): array {
		$coach = get_userdata( $b->coach_id );

		return array(
			'id'                => $b->id,
			'child_id'          => $b->child_id,
			'coach_id'          => $b->coach_id,
			// @since 4.20.4 — écran mobile "لقاءاتي" : nom/avatar du coach,
			// même résolution WP core (get_userdata/get_avatar_url) que
			// RK_Dashboard_REST::format_coach_card(). Aucune donnée
			// supplémentaire sur le coach (jamais son e-mail, son rôle,
			// etc.) — minimisation Politique Familles.
			'coach_name'        => $coach ? (string) $coach->display_name : '',
			'coach_avatar_url'  => $coach ? get_avatar_url( $b->coach_id, array( 'size' => 96 ) ) : '',
			'course_id'         => $b->course_id,
			'session_name'      => $b->session_name,
			'start_at'          => $b->start_at->format( DATE_ATOM ),
			// @since 4.20.4 — même fenêtre live que session.join_url dans
			// /dashboard/summary (RKP_Booking::is_live_window(), extraite
			// pour être réutilisée ici) : jamais de lien de visio actif
			// hors de cette fenêtre, quel que soit l'élément de la liste.
			'meeting_url'       => $b->is_live_window() ? $b->meeting_url : '',
			'is_live'           => $b->is_live_window(),
			'status'            => $b->status,
			'is_upcoming'       => $b->is_upcoming(),
		);
	}
}