<?php
declare( strict_types=1 );
/**
 * RK_Messages_REST — /rk/v1/messages/* (mobile).
 *
 * Écran "الرسائل" natif (messages_screen.dart) : remplace le FAB qui
 * ouvrait auparavant rk-messages.php dans un navigateur externe (session
 * WordPress cookie absente du contexte mobile JWT — l'enfant y arrivait
 * déconnecté, cf. commentaire historique d'AppConfig::accountUrl). Ici,
 * même identité que le reste de l'API mobile : self-access enfant via
 * RKP_MobileChildAccess, Bearer JWT déjà vérifié en amont
 * (class-rk-auth-rest.php) — PAS de nonce WordPress, contrairement aux
 * actions AJAX web (rk_mc_chat_send/rk_mc_chat_load).
 *
 * AUCUNE logique de messagerie réinventée : tout passe par
 * RK_MC_Message_Service, le MÊME service que rk-messages.php et
 * class-rk-mc-message-service.php::ajax_chat_send()/ajax_chat_load() :
 *   - lecture   → RK_MC_Message_Service::load_bm_messages() (thread
 *                 Better Messages 1:1) avec repli sur get_conversation()
 *                 (table custom wp_rk_child_messages) si BM est absent —
 *                 même ordre de priorité que rk-messages.php.
 *   - écriture  → Better_Messages()->functions->new_message() si BM est
 *                 actif, sinon RK_MC_Message_Service::send() — même ordre
 *                 de priorité et même rate-limit que ajax_chat_send().
 *
 * Deux interlocuteurs possibles, comme le web (rk_show_tabs) :
 *   'coach' — RK_MC_Message_Service::get_coach()
 *   'admin' — RK_MC_Message_Service::get_admin_user()
 *
 * @package RK_Platform
 * @since   4.22.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class RK_Messages_REST {

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes(): void {
		// GET /wp-json/rk/v1/messages/threads — méta des 2 threads
		// (coach/admin) : identité + avatar + compteur non lus, SANS le
		// contenu des messages (liste des conversations de l'écran).
		register_rest_route( 'rk/v1', '/messages/threads', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'handle_threads' ),
			'permission_callback' => 'is_user_logged_in',
		) );

		// GET /wp-json/rk/v1/messages/{channel} — contenu d'un thread.
		register_rest_route( 'rk/v1', '/messages/(?P<channel>coach|admin)', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'handle_get_thread' ),
			'permission_callback' => 'is_user_logged_in',
			'args'                => array(
				'after_id' => array(
					'required' => false,
					'type'     => 'integer',
					'default'  => 0,
				),
			),
		) );

		// POST /wp-json/rk/v1/messages/{channel} — envoi d'un message.
		register_rest_route( 'rk/v1', '/messages/(?P<channel>coach|admin)', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'handle_send' ),
			'permission_callback' => 'is_user_logged_in',
			'args'                => array(
				'body' => array(
					'required' => true,
					'type'     => 'string',
				),
			),
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

		return $self;
	}

	/**
	 * Résout le wp_user_id destinataire pour un canal donné, exactement
	 * comme rk-messages.php ($coach_wp_uid / $admin_wp_uid).
	 */
	private static function resolve_partner( string $channel, int $child_rk_id ): int {
		if ( ! class_exists( 'RK_MC_Message_Service' ) ) return 0;

		if ( 'admin' === $channel ) {
			$admin = RK_MC_Message_Service::get_admin_user();
			return $admin ? (int) $admin->ID : 0;
		}

		$coach = RK_MC_Message_Service::get_coach( $child_rk_id );
		return $coach ? (int) $coach->ID : 0;
	}

	/**
	 * GET /messages/threads — même paire coach/admin que rk-messages.php
	 * ($show_tabs), sans le contenu des messages. Si coach et admin sont
	 * la même personne (fallback get_coach() → admin), un seul thread est
	 * renvoyé — même règle que le web ($admin_wp_uid !== $coach_wp_uid).
	 */
	public static function handle_threads( WP_REST_Request $request ) {
		$self = self::self_or_error();
		if ( $self instanceof WP_REST_Response ) return $self;

		if ( ! class_exists( 'RK_MC_Message_Service' ) ) {
			return new WP_REST_Response( array( 'error' => 'unavailable' ), 503 );
		}

		$child_rk_id  = (int) $self->id;
		$child_wp_uid = (int) ( $self->wp_user_id ?? 0 );

		$coach_obj    = RK_MC_Message_Service::get_coach( $child_rk_id );
		$admin_obj    = RK_MC_Message_Service::get_admin_user();
		$coach_wp_uid = $coach_obj ? (int) $coach_obj->ID : 0;
		$admin_wp_uid = $admin_obj ? (int) $admin_obj->ID : 0;

		$threads   = array();
		$threads[] = self::format_thread( 'coach', $coach_obj, $child_wp_uid, $coach_wp_uid );

		if ( $admin_obj && $admin_wp_uid > 0 && $admin_wp_uid !== $coach_wp_uid ) {
			$threads[] = self::format_thread( 'admin', $admin_obj, $child_wp_uid, $admin_wp_uid );
		}

		return new WP_REST_Response( array( 'threads' => $threads ), 200 );
	}

	private static function format_thread( string $channel, ?object $partner, int $child_wp_uid, int $partner_wp_uid ): array {
		$has_partner = $partner && $partner_wp_uid > 0;

		$unread = 0;
		if ( $has_partner && $child_wp_uid && class_exists( 'RK_MC_Message_Service' ) ) {
			$unread = RK_MC_Message_Service::bm_thread_unread( $child_wp_uid, $partner_wp_uid );
		}

		return array(
			'channel'      => $channel,
			// L'écran affiche "فريق ريادة كيدز" pour le canal admin plutôt
			// que le nom personnel de l'administrateur — même libellé que
			// rk-messages.php (بدلاً من $admin_obj->display_name brut).
			'name'         => 'admin' === $channel
				? __( 'فريق ريادة كيدز', 'rk-my-children' )
				: ( $has_partner ? (string) $partner->display_name : '' ),
			'avatar_url'   => $has_partner ? get_avatar_url( $partner_wp_uid, array( 'size' => 128 ) ) : '',
			'has_partner'  => $has_partner,
			'unread_count' => $unread,
		);
	}

	/**
	 * GET /messages/{channel} — contenu du thread, MÊME ordre de priorité
	 * que rk-messages.php : Better Messages d'abord, table custom en repli.
	 * `after_id` permet un polling incrémental (comme ajax_chat_load()) ;
	 * omis ou à 0, renvoie l'historique complet (plafonné, comme le web).
	 */
	public static function handle_get_thread( WP_REST_Request $request ) {
		$self = self::self_or_error();
		if ( $self instanceof WP_REST_Response ) return $self;

		if ( ! class_exists( 'RK_MC_Message_Service' ) ) {
			return new WP_REST_Response( array( 'error' => 'unavailable' ), 503 );
		}

		$channel      = (string) $request->get_param( 'channel' );
		$child_rk_id  = (int) $self->id;
		$child_wp_uid = (int) ( $self->wp_user_id ?? 0 );
		$after_id     = (int) $request->get_param( 'after_id' );

		$partner_wp_uid = self::resolve_partner( $channel, $child_rk_id );
		if ( ! $child_wp_uid || ! $partner_wp_uid ) {
			return new WP_REST_Response( array( 'messages' => array(), 'partner_wp_uid' => 0 ), 200 );
		}

		$messages = RK_MC_Message_Service::load_bm_messages( $child_wp_uid, $partner_wp_uid, $after_id );

		// Repli table custom si Better Messages est absent — même geste
		// que get_conversation() (celle-ci marque aussi les messages
		// entrants comme lus, ce que load_bm_messages() ne fait pas :
		// c'est voulu, l'écran appelle handle_get_thread() pour l'affichage
		// initial ET pour marquer la lecture côté custom table).
		if ( ! $messages && ! class_exists( 'Better_Messages' ) ) {
			$rows = RK_MC_Message_Service::get_conversation( $child_rk_id, $child_wp_uid, $channel );
			foreach ( $rows as $row ) {
				if ( $after_id > 0 && (int) $row->id <= $after_id ) continue;
				$messages[] = array(
					'id'        => (int) $row->id,
					'sender_id' => (int) $row->from_id,
					'body'      => wp_strip_all_tags( (string) $row->body ),
					'time'      => date_i18n( 'H:i', strtotime( (string) $row->created_at ) ),
					'date_full' => date_i18n( 'j M Y H:i', strtotime( (string) $row->created_at ) ),
					'date_day'  => date_i18n( 'Y-m-d', strtotime( (string) $row->created_at ) ),
				);
			}
		}

		return new WP_REST_Response( array(
			'messages'       => array_map(
				fn( array $m ) => self::format_message( $m, $child_wp_uid ),
				$messages
			),
			'partner_wp_uid' => $partner_wp_uid,
		), 200 );
	}

	private static function format_message( array $m, int $viewer_wp_uid ): array {
		return array(
			'id'         => $m['id'],
			'is_outgoing' => ( (int) $m['sender_id'] === $viewer_wp_uid ),
			'body'       => $m['body'],
			'time'       => $m['time'],
			'date_full'  => $m['date_full'],
			'date_day'   => $m['date_day'],
		);
	}

	/**
	 * POST /messages/{channel} — envoi. MÊME priorité, MÊME rate-limit et
	 * MÊME borne de longueur (2000 caractères) que
	 * RK_MC_Message_Service::ajax_chat_send() — aucune règle réinventée.
	 */
	public static function handle_send( WP_REST_Request $request ) {
		$self = self::self_or_error();
		if ( $self instanceof WP_REST_Response ) return $self;

		if ( ! class_exists( 'RK_MC_Message_Service' ) ) {
			return new WP_REST_Response( array( 'error' => 'unavailable' ), 503 );
		}

		$channel      = (string) $request->get_param( 'channel' );
		$child_rk_id  = (int) $self->id;
		$child_wp_uid = (int) ( $self->wp_user_id ?? 0 );
		$body         = sanitize_textarea_field( (string) $request->get_param( 'body' ) );

		if ( ! $child_wp_uid || '' === trim( $body ) ) {
			return new WP_REST_Response( array( 'error' => 'invalid_request' ), 400 );
		}
		if ( mb_strlen( $body ) > 2000 ) {
			return new WP_REST_Response( array( 'error' => 'message_too_long' ), 400 );
		}

		$partner_wp_uid = self::resolve_partner( $channel, $child_rk_id );
		if ( ! $partner_wp_uid ) {
			return new WP_REST_Response( array( 'error' => 'no_recipient' ), 422 );
		}

		if ( ! RK_MC_Message_Service::check_rate_limit_public( $child_wp_uid ) ) {
			return new WP_REST_Response( array( 'error' => 'rate_limited' ), 429 );
		}

		// Better Messages en priorité, comme ajax_chat_send() — sinon repli
		// table custom via RK_MC_Message_Service::send().
		if ( class_exists( 'Better_Messages' ) && method_exists( Better_Messages()->functions, 'new_message' ) ) {
			$result = Better_Messages()->functions->new_message( array(
				'sender_id'  => $child_wp_uid,
				'recipients' => array( $partner_wp_uid ),
				'content'    => wp_kses_post( $body ),
			) );

			if ( is_wp_error( $result ) ) {
				return new WP_REST_Response( array( 'error' => 'send_failed' ), 500 );
			}

			RK_MC_Message_Service::notify_recipient_public( $partner_wp_uid, $child_rk_id, $body );

			$msg_id = is_array( $result ) ? (int) ( $result['message_id'] ?? 0 ) : (int) $result;

			return new WP_REST_Response( array(
				'id'          => $msg_id,
				'is_outgoing' => true,
				'body'        => esc_html( $body ),
				'time'        => date_i18n( 'H:i' ),
			), 201 );
		}

		$id = RK_MC_Message_Service::send( $child_rk_id, $child_wp_uid, $partner_wp_uid, $body, $channel );
		if ( ! $id ) {
			return new WP_REST_Response( array( 'error' => 'send_failed' ), 500 );
		}

		return new WP_REST_Response( array(
			'id'          => $id,
			'is_outgoing' => true,
			'body'        => esc_html( $body ),
			'time'        => date_i18n( 'H:i' ),
		), 201 );
	}
}
