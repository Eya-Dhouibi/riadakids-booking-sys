<?php
declare( strict_types=1 );
/**
 * RK_Children_Content_REST — /rk/v1/children/{child_id}/badges et
 * /rk/v1/children/{child_id}/adventure-map (mobile).
 *
 * Autorisation : rk_mc_get_child( $child_id, $user_id ) — même fonction déjà
 * utilisée par le web, filtre SQL `WHERE id = %d AND user_id = %d`. Si elle
 * retourne null, l'enfant n'appartient pas à l'utilisateur courant : 403.
 * Aucune règle d'autorisation réécrite ici.
 *
 * @package RK_Platform
 * @since   4.19.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class RK_Children_Content_REST {

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes(): void {
		register_rest_route( 'rk/v1', '/children/(?P<child_id>\d+)/badges', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'handle_badges' ),
			'permission_callback' => 'is_user_logged_in',
		) );

		register_rest_route( 'rk/v1', '/children/(?P<child_id>\d+)/adventure-map', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'handle_adventure_map' ),
			'permission_callback' => 'is_user_logged_in',
		) );
	}

	/**
	 * Self-access enfant (pivot 4.19.1), cf. RKP_MobileChildAccess.
	 */
	private static function resolve_owned_child( WP_REST_Request $request ) {
		$child_id = (int) $request->get_param( 'child_id' );
		$user_id  = get_current_user_id();

		$child = class_exists( 'RKP_MobileChildAccess' )
			? RKP_MobileChildAccess::check_self_access( $child_id, $user_id )
			: null;

		if ( ! $child ) {
			return new WP_REST_Response( array( 'error' => 'forbidden' ), 403 );
		}
		return $child;
	}

	public static function handle_badges( WP_REST_Request $request ) {
		$child = self::resolve_owned_child( $request );
		if ( $child instanceof WP_REST_Response ) return $child;

		if ( ! class_exists( 'RKP_BadgeQueryService' ) ) {
			return new WP_REST_Response( array( 'error' => 'badges_unavailable' ), 503 );
		}

		$badges = RKP_BadgeQueryService::get_catalogue_for_child( (int) $child->id );

		return new WP_REST_Response( array(
			'badges'  => $badges,
			// @since 4.20.4 — écran mobile "إنجازاتي" (شارات الإنجاز).
			// Même compte que la bannière "خزانة شاراتي" du web
			// (rk-badges.php : $total = count($catalogue_full), $earned_n
			// compté sur ce même tableau) — pas de requête supplémentaire.
			'banner'  => self::format_banner( $badges ),
			// Filtre "المجال:" (شارات الإنجاز uniquement — le domaine des
			// شارات المغامرات se dérive côté app depuis /adventure-map,
			// déjà consommé par l'écran مغامراتي, aucun doublon ici).
			'domains' => self::format_achievement_domains( $badges ),
		), 200 );
	}

	/**
	 * MÊME 6 catégories, MÊMES libellés et couleurs que le tableau
	 * $categories codé en dur dans rk-badges.php (web) — copiés ici tels
	 * quels pour que mobile et web affichent des libellés identiques.
	 * Ce n'est QUE de la présentation (libellé/couleur par clé `cat`,
	 * qui existe déjà sur chaque badge renvoyé par
	 * RKP_BadgeQueryService::get_catalogue_for_child()) : aucun calcul
	 * métier, aucune requête.
	 */
	private static function achievement_category_labels(): array {
		return array(
			'start'   => array( 'label' => 'البداية',      'color' => '#059669' ),
			'streak'  => array( 'label' => 'المداومة',      'color' => '#D97706' ),
			'mastery' => array( 'label' => 'الإتقان',       'color' => '#7C3AED' ),
			'skill'   => array( 'label' => 'مهارات المدرب', 'color' => '#4C95D7' ),
			'special' => array( 'label' => 'خاصة ونادرة',   'color' => '#FF4411' ),
			'level'   => array( 'label' => 'المستويات',     'color' => '#0891B2' ),
		);
	}

	/**
	 * Ne renvoie que les catégories ayant au moins un badge — même règle
	 * que le web (`if ( empty( $grouped[ $cat_key ] ) ) continue;`), donc
	 * le filtre "المجال" mobile ne propose jamais une catégorie vide.
	 */
	private static function format_achievement_domains( array $badges ): array {
		$present = array();
		foreach ( $badges as $b ) {
			$cat = (string) ( $b['cat'] ?? '' );
			if ( '' !== $cat ) $present[ $cat ] = true;
		}

		$labels = self::achievement_category_labels();
		$out    = array();
		foreach ( $labels as $key => $meta ) {
			if ( ! isset( $present[ $key ] ) ) continue;
			$out[] = array( 'key' => $key, 'label' => $meta['label'], 'color' => $meta['color'] );
		}
		return $out;
	}

	/** Bannière "خزانة شاراتي" — même compte que le web, dérivé du même tableau. */
	private static function format_banner( array $badges ): array {
		$total  = count( $badges );
		$earned = count( array_filter( $badges, static fn( $b ) => ! empty( $b['earned'] ) ) );
		return array(
			'earned'  => $earned,
			'total'   => $total,
			'percent' => $total > 0 ? (int) round( $earned / $total * 100 ) : 0,
		);
	}

	public static function handle_adventure_map( WP_REST_Request $request ) {
		$child = self::resolve_owned_child( $request );
		if ( $child instanceof WP_REST_Response ) return $child;

		if ( ! class_exists( 'RKP_AdventureQueryService' ) ) {
			return new WP_REST_Response( array( 'error' => 'adventure_map_unavailable' ), 503 );
		}

		$wp_user_id = isset( $child->wp_user_id ) ? (int) $child->wp_user_id : 0;

		return new WP_REST_Response(
			array( 'adventures' => RKP_AdventureQueryService::get_adventures_for_child( (int) $child->id, $wp_user_id ) ),
			200
		);
	}
}