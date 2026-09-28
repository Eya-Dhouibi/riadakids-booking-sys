<?php
declare( strict_types=1 );
/**
 * RK_Notifications_REST — /rk/v1/notifications (mobile).
 *
 * Porte la même cloche de notifications que le header web
 * (Modules/Children/includes/traits/trait-rk-mc-tutor-dash-chrome.php →
 * notif_bell_trigger()/notif_bell_widget(), actions AJAX
 * rk_mc_get_notifications / rk_mc_mark_notifs_read) — AUCUNE logique de
 * lecture/écriture réinventée ici, tout passe par
 * RK_MC_Notification_Service::get_list_for_user()/mark_read_for_user(),
 * les MÊMES méthodes que ajax_get()/ajax_mark_read() côté web.
 *
 * PIVOT identité (cf. RKP_MobileChildAccess) : le JWT mobile authentifie
 * l'enfant lui-même, donc get_current_user_id() EST déjà le wp_user_id
 * destinataire des notifications — pas de résolution "parent → enfant"
 * comme côté AJAX web (RK_Identity_Context::child_wp_uid()), et pas de
 * nonce : l'identité est déjà garantie par le Bearer JWT vérifié en amont
 * (class-rk-auth-rest.php), donc pas de contexte "parent" à distinguer ici.
 *
 * @package RK_Platform
 * @since   4.21.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class RK_Notifications_REST {

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes(): void {
		// GET /wp-json/rk/v1/notifications — liste + compteur non lus.
		register_rest_route( 'rk/v1', '/notifications', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'handle_list' ),
			'permission_callback' => 'is_user_logged_in',
		) );

		// POST /wp-json/rk/v1/notifications/read — tout marquer comme lu
		// (même geste que le bouton "تحديد كمقروء" du panneau web).
		register_rest_route( 'rk/v1', '/notifications/read', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'handle_mark_read' ),
			'permission_callback' => 'is_user_logged_in',
		) );
	}

	/** Résout et vérifie que l'appelant est bien un enfant authentifié. */
	private static function self_or_error() {
		$user_id = get_current_user_id();

		if ( ! class_exists( 'RKP_MobileChildAccess' ) ) {
			return new WP_REST_Response( array( 'error' => 'unavailable' ), 503 );
		}

		$self = RKP_MobileChildAccess::resolve_self( $user_id );
		if ( ! $self ) {
			return new WP_REST_Response( array( 'error' => 'forbidden' ), 403 );
		}

		return $user_id;
	}

	public static function handle_list( WP_REST_Request $request ) {
		$user_id = self::self_or_error();
		if ( $user_id instanceof WP_REST_Response ) return $user_id;

		if ( ! class_exists( 'RK_MC_Notification_Service' ) ) {
			return new WP_REST_Response( array( 'error' => 'unavailable' ), 503 );
		}

		$rows = RK_MC_Notification_Service::get_list_for_user( $user_id );

		return new WP_REST_Response( array(
			'notifications' => array_map( array( __CLASS__, 'format_row' ), $rows ),
			'unread_count'  => RK_MC_Notification_Service::get_unread_count( $user_id ),
		), 200 );
	}

	public static function handle_mark_read( WP_REST_Request $request ) {
		$user_id = self::self_or_error();
		if ( $user_id instanceof WP_REST_Response ) return $user_id;

		if ( ! class_exists( 'RK_MC_Notification_Service' ) ) {
			return new WP_REST_Response( array( 'error' => 'unavailable' ), 503 );
		}

		RK_MC_Notification_Service::mark_read_for_user( $user_id );

		return new WP_REST_Response( array( 'success' => true ), 200 );
	}

	/** Uniformise le format des lignes (objets stdClass issus de $wpdb) en JSON REST propre. */
	private static function format_row( object $row ): array {
		return array(
			'id'        => (int) $row->id,
			'title'     => (string) $row->title,
			'message'   => (string) $row->message,
			'link'      => (string) $row->link,
			'type'      => (string) $row->type,
			'is_read'   => (bool) ( (int) $row->is_read === 1 ),
			'date_fmt'  => (string) $row->date_fmt,
		);
	}
}
