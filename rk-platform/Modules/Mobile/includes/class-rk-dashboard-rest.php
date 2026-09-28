<?php
declare( strict_types=1 );
/**
 * RK_Dashboard_REST — /rk/v1/dashboard/summary (mobile).
 *
 * PIVOT (4.19.1) : le sujet du JWT est l'enfant, pas le parent (cf.
 * RKP_AuthTokenCommandService::login()). RKP_GamificationQueryService
 * (points/niveau/streak) est déjà scopée par child_rk_id, donc directement
 * réutilisable telle quelle.
 *
 * ⚠️ NON RÉSOLU VOLONTAIREMENT : rk_mc_count_bookings()/rk_mc_count_sessions()/
 * rk_mc_get_session_credits() sont scopées par user_id PARENT (crédits
 * familiaux partagés entre enfants — table wp_rk_user_credits.user_id).
 * Un enfant authentifié seul n'a pas de user_id parent à disposition sans
 * remonter la table de liaison famille (RKP_FamilyLinkQueryService), et la
 * question produit — un enfant doit-il voir les crédits/séances DU PARENT
 * (potentiellement partagés avec une fratrie), ou faut-il une notion de
 * crédits/compteurs PAR ENFANT qui n'existe pas encore côté schéma ? —
 * n'est pas tranchée. Plutôt que d'inventer un mapping enfant→parent ad hoc
 * ici (et risquer d'exposer les crédits d'un autre enfant de la même
 * fratrie), ces trois champs sont retournés à `null` avec `available:false`
 * jusqu'à décision. Ne PAS combler avec rk_mc_count_bookings($self->id) —
 * ce n'est PAS un user_id, le résultat serait silencieusement faux.
 *
 * v4.20.0 — Sections additionnelles pour l'écran d'accueil mobile natif
 * (dashboard_screen.dart, 4 sections : accueil/actions, مغامراتي, تواصل مع
 * مدربك, شاراتك القادمة). Toutes les données passent par les MÊMES services
 * applicatifs que le web (RKP_BookingRepository, RK_MC_Message_Service,
 * RKP_MissionQueryService) — AUCUNE logique dupliquée, uniquement une mise
 * en forme JSON minimisée pour l'écran enfant (Politique Familles Google
 * Play : pas de champ non nécessaire à l'affichage).
 *
 * MODÉRATION — "تواصل مع مدربك" : la version mobile est VOLONTAIREMENT
 * LECTURE SEULE (identité + présence du coach uniquement, aucun contenu de
 * message, aucun champ de saisie libre). Le canal de messagerie libre
 * (Better Messages, cf. RK_MC_Message_Service::send()) existe côté web mais
 * n'est PAS exposé ici.
 *
 * @package RK_Platform
 * @since   4.19.0
 * @since   4.19.1 Pivot self-access enfant.
 * @since   4.20.0 Sections session/coach/meldo/challenges pour le dashboard mobile natif.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class RK_Dashboard_REST {

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes(): void {
		register_rest_route( 'rk/v1', '/dashboard/summary', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'handle_summary' ),
			'permission_callback' => 'is_user_logged_in',
		) );
	}

	public static function handle_summary( WP_REST_Request $request ) {
		$user_id = get_current_user_id();

		if ( ! class_exists( 'RKP_MobileChildAccess' ) ) {
			return new WP_REST_Response( array( 'error' => 'unavailable' ), 503 );
		}

		$self = RKP_MobileChildAccess::resolve_self( $user_id );
		if ( ! $self ) {
			return new WP_REST_Response( array( 'error' => 'forbidden' ), 403 );
		}

		$child_rk_id = (int) $self->id;

		return new WP_REST_Response( array(
			// Voir avertissement en tête de fichier : pas encore résolu.
			'bookings_count'     => null,
			'sessions_count'     => null,
			'credits'            => null,
			'counters_available' => false,
			'child'              => self::format_child_dashboard( $self ),
			'session'            => self::format_next_session( $child_rk_id ),
			'coach'              => self::format_coach_card( $child_rk_id ),
			'meldo'              => self::format_meldo(),
			'challenges'         => self::format_challenges( $child_rk_id ),
			// @since 4.20.3 — badge "لقاءاتي" de la bottom nav mobile.
			// MÊME service que RK_MC_Dashboard_Chrome_V4::ctx() (web) :
			// aucune logique de comptage réinventée ici.
			'upcoming_sessions_count' => self::format_upcoming_sessions_count( $child_rk_id ),
		), 200 );
	}

	/**
	 * Résumé gamification de l'enfant authentifié — les colonnes brutes de
	 * wp_rk_children (child_name, avatar_url…) plus le niveau/points/streak
	 * calculés par le service applicatif existant, jamais recalculés ici.
	 */
	private static function format_child_dashboard( object $row ): array {
		$child_rk_id = (int) $row->id;

		$gamification = null;
		if ( class_exists( 'RKP_GamificationQueryService' ) ) {
			$gamification = array(
				'total_points' => RKP_GamificationQueryService::get_total_points( $child_rk_id ),
				'streak'       => RKP_GamificationQueryService::get_streak( $child_rk_id ),
				'level'        => RKP_GamificationQueryService::get_level( $child_rk_id ),
			);
		}

		return array(
			'id'           => $child_rk_id,
			'name'         => (string) ( $row->display_name ?: trim( $row->child_name . ' ' . $row->child_family_name ) ),
			'avatar_url'   => (string) ( $row->avatar_url ?? '' ),
			'gamification' => $gamification,
		);
	}

	/**
	 * Section 1 (bloc d'accueil) — carte "انضم إلى لقائك".
	 *
	 * Réutilise RKP_BookingRepository::get_next_confirmed(), la MÊME requête
	 * que dashboard.php (bloc "PROCHAINE SÉANCE"), pas une nouvelle logique.
	 * `join_url` n'est renvoyé QUE si la séance est live (même fenêtre que le
	 * web : -15 min / +2h autour de l'heure de rendez-vous) — sinon un enfant
	 * pourrait rejoindre un appel vidéo hors créneau sans supervision. Hors
	 * fenêtre live, l'app affiche uniquement `label` et navigue vers l'écran
	 * de réservations existant (aucun lien externe).
	 */
	private static function format_next_session( int $child_rk_id ): array {
		$base = array(
			'has_session' => false,
			'is_live'     => false,
			'start_at'    => null,
			'label'       => '',
			'join_url'    => '',
		);

		if ( ! class_exists( 'RKP_BookingRepository' ) ) {
			return $base;
		}

		$booking = RKP_BookingRepository::get_next_confirmed( $child_rk_id );
		if ( ! $booking || empty( $booking->appointment ) ) {
			return $base;
		}

		$ts = function_exists( 'rk_mc_appt_timestamp' )
			? rk_mc_appt_timestamp( (string) $booking->appointment )
			: strtotime( (string) $booking->appointment );
		if ( ! $ts ) {
			return $base;
		}

		$now        = current_time( 'timestamp' );
		$is_live    = $now >= ( $ts - 15 * MINUTE_IN_SECONDS ) && $now <= ( $ts + 2 * HOUR_IN_SECONDS );
		$booking_pk = (int) ( $booking->booking_id ?? 0 );

		$label = '';
		$diff  = $ts - $now;
		if ( $diff > 0 && $diff <= HOUR_IN_SECONDS && function_exists( 'rk_mc_appt_format' ) ) {
			/* translators: %d = minutes restantes */
			$label = sprintf( __( 'جلستك تبدأ بعد %d دقيقة', 'rk-my-children' ), max( 1, (int) round( $diff / 60 ) ) );
		} elseif ( function_exists( 'rk_mc_appt_format' ) ) {
			$label = rk_mc_appt_format( (string) $booking->appointment, 'l، j F — H:i', $booking_pk );
		}

		return array(
			'has_session' => true,
			'is_live'     => $is_live,
			'start_at'    => gmdate( 'c', $ts ),
			'label'       => $label,
			'join_url'    => ( $is_live && ! empty( $booking->meeting_url ) ) ? (string) $booking->meeting_url : '',
		);
	}

	/**
	 * Section 3 ("تواصل مع مدربك") — carte coach en LECTURE SEULE.
	 *
	 * Réutilise RK_MC_Message_Service::get_coach() (même résolution que le
	 * web : coach assigné → coach déduit du dernier type de rendez-vous →
	 * admin en dernier recours). Volontairement PAS de compteur de messages
	 * non lus ni de contenu de conversation : le canal de messagerie libre
	 * n'est pas porté sur mobile v1.
	 */
	private static function format_coach_card( int $child_rk_id ): array {
		$base = array(
			'has_coach'  => false,
			'name'       => '',
			'avatar_url' => '',
			'online'     => false,
		);

		if ( ! class_exists( 'RK_MC_Message_Service' ) ) {
			return $base;
		}

		$coach = RK_MC_Message_Service::get_coach( $child_rk_id );
		if ( ! $coach || empty( $coach->ID ) ) {
			return $base;
		}

		$coach_wp_id = (int) $coach->ID;
		$online      = false;
		if ( class_exists( 'WP_Session_Tokens' ) ) {
			$last_seen = (int) get_user_meta( $coach_wp_id, 'rk_last_seen', true );
			$online    = ( $last_seen && ( time() - $last_seen ) < 10 * MINUTE_IN_SECONDS )
				|| (bool) WP_Session_Tokens::get_instance( $coach_wp_id )->get_all();
		}

		return array(
			'has_coach'  => true,
			'name'       => (string) $coach->display_name,
			'avatar_url' => get_avatar_url( $coach_wp_id, array( 'size' => 128 ) ),
			'online'     => $online,
		);
	}

	/**
	 * Section 1 — carte "العب مع ميلدو!". Même filtre/option que le web
	 * (`rk_mc_meldo_url`) : source unique, jamais de route en dur côté
	 * mobile non plus. `available:false` si l'option n'est pas configurée
	 * (le web affiche alors "قريباً" — même comportement attendu mobile).
	 */
	private static function format_meldo(): array {
		$url = (string) apply_filters( 'rk_mc_meldo_url', (string) get_option( 'rk_mc_meldo_url', '' ) );
		return array(
			'available' => '' !== $url,
			'url'       => $url,
		);
	}

	/**
	 * Badge "لقاءاتي" de la bottom nav — MÊME service et MÊME limite (5)
	 * que RK_MC_Dashboard_Chrome_V4::ctx() côté web (voir
	 * class-rk-mc-dashboard-chrome-v4.php). Le plafonnement à 9 pour
	 * l'affichage (comme le web : `min((int)$it['badge'], 9)`) est fait
	 * côté client (Flutter), pas ici — cette valeur reste le compte réel.
	 */
	private static function format_upcoming_sessions_count( int $child_rk_id ): int {
		if ( ! class_exists( 'RK_Booking_Calendar_Service' ) ) {
			return 0;
		}
		return count( (array) RK_Booking_Calendar_Service::get_upcoming( $child_rk_id, 5 ) );
	}

	/**
	 * Section 1 — carte "تحدياتي". Un seul entier (nombre de missions
	 * hebdomadaires actives non terminées) : le contenu détaillé des
	 * missions reste sur l'écran missions dédié (/children/{id}/missions,
	 * déjà exposé), la tuile d'accueil n'a besoin que du compteur.
	 */
	private static function format_challenges( int $child_rk_id ): array {
		if ( ! class_exists( 'RKP_MissionQueryService' ) ) {
			return array( 'active_count' => 0 );
		}
		$missions = RKP_MissionQueryService::get_weekly_missions( $child_rk_id );
		$active   = array_filter( $missions, static fn( $m ) => empty( $m['completed'] ) );
		return array( 'active_count' => count( $active ) );
	}
}