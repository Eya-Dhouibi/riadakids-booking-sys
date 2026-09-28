<?php
declare( strict_types=1 );
/**
 * RK_Adventures_REST — GET /rk/v1/children/{child_id}/adventures.
 *
 * Alimente l'écran mobile "مغامراتي" avec EXACTEMENT les mêmes données
 * que la grille web `enrolled-courses.php` / le filtrage AJAX
 * `RK_MC_Adventures_Filter_Service::ajax_filter()` : même construction de
 * cartes (id/titre/thumb/catégorie/pourcentage/leçons), même filtre
 * catégorie+statut, même mapping catégorie→icône (`category_icon_key()`,
 * ajouté publiquement à ce service pour ce endpoint — voir ce fichier).
 * Aucune requête SQL ni calcul de progression réécrit ici.
 *
 * Les catégories renvoyées dans `categories` proviennent TOUJOURS de la
 * liste complète non filtrée de l'enfant (comme les pastilles $categories
 * du web, construites une fois avant filtrage) — pour que les chips de
 * filtre restent stables quel que soit le filtre actuellement appliqué.
 *
 * @package RK_Platform
 * @since   4.20.4
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class RK_Adventures_REST {

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes(): void {
		register_rest_route( 'rk/v1', '/children/(?P<child_id>\d+)/adventures', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'handle_list' ),
			'permission_callback' => 'is_user_logged_in',
			'args'                => array(
				'category' => array( 'required' => false, 'type' => 'string' ),
				'status'   => array( 'required' => false, 'type' => 'string' ),
			),
		) );

		// GET /wp-json/rk/v1/children/{child_id}/adventures/{course_id}
		// @since 4.21.0 — page mobile "détail du cours" (clic sur une carte
		// مغامراتي) : cover + titre + description + infos. Les séances (LMS)
		// de ce cours ne sont PAS renvoyées ici — l'app les tire déjà de
		// /children/{id}/adventure-map (RKP_AdventureQueryService), filtrées
		// côté client par course_id, sans dupliquer cette requête.
		register_rest_route( 'rk/v1', '/children/(?P<child_id>\d+)/adventures/(?P<course_id>\d+)', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'handle_detail' ),
			'permission_callback' => 'is_user_logged_in',
		) );
	}

	/** Self-access enfant (pivot 4.19.1) — même garde que les autres routes Mobile. */
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

	public static function handle_list( WP_REST_Request $request ) {
		$child = self::resolve_owned_child( $request );
		if ( $child instanceof WP_REST_Response ) return $child;

		if ( ! class_exists( 'RK_MC_Adventures_Filter_Service' ) ) {
			return new WP_REST_Response( array( 'error' => 'adventures_unavailable' ), 503 );
		}

		$category_slug = sanitize_title( (string) $request->get_param( 'category' ) ?: 'all' );
		$status_filter = sanitize_key( (string) $request->get_param( 'status' ) ?: 'all' );
		if ( ! in_array( $status_filter, array( 'all', 'in-progress', 'completed', 'not-started' ), true ) ) {
			$status_filter = 'all';
		}

		$filtered = RK_MC_Adventures_Filter_Service::get_courses_for_child( $child, $category_slug ?: 'all', $status_filter );

		// Liste complète (non filtrée) UNIQUEMENT pour dériver les chips de
		// catégorie disponibles — même intention que $categories côté web
		// (construit une fois avant tout filtrage).
		$all_for_categories = ( 'all' === $category_slug && 'all' === $status_filter )
			? $filtered
			: RK_MC_Adventures_Filter_Service::get_courses_for_child( $child, 'all', 'all' );

		$categories = array();
		$seen       = array();
		foreach ( $all_for_categories as $c ) {
			$name = (string) $c['category'];
			if ( '' === $name || isset( $seen[ $name ] ) ) continue;
			$seen[ $name ] = true;
			$categories[]  = array(
				'name'      => $name,
				'slug'      => (string) $c['cat_slug'],
				'icon_key'  => RK_MC_Adventures_Filter_Service::category_icon_key( $name ),
			);
		}

		return new WP_REST_Response( array(
			'courses'    => array_map( array( __CLASS__, 'format_course' ), $filtered ),
			'categories' => $categories,
		), 200 );
	}

	/**
	 * Minimisation de données (Politique Familles) : on ne renvoie QUE ce
	 * que l'écran mobile affiche — pas `permalink` (URL web du cours, sans
	 * usage mobile natif), pas `next_name`/`next_date` (la prochaine
	 * séance liée à ce cours est déjà couverte par l'écran "لقاءاتي").
	 */
	private static function format_course( array $c ): array {
		return array(
			'id'                => (int) $c['id'],
			'title'             => (string) $c['title'],
			'thumb_url'         => (string) $c['thumb_url'],
			'category'          => (string) $c['category'],
			'category_slug'     => (string) $c['cat_slug'],
			'category_icon_key' => RK_MC_Adventures_Filter_Service::category_icon_key( (string) $c['category'] ),
			'percent'           => (int) $c['pct'],
			'lessons_done'      => (int) $c['lessons_done'],
			'lessons_total'     => (int) $c['lessons_total'],
			'is_completed'      => (bool) $c['is_completed'],
		);
	}

	/** @since 4.21.0 — GET /children/{child_id}/adventures/{course_id} (détail). */
	public static function handle_detail( WP_REST_Request $request ) {
		$child = self::resolve_owned_child( $request );
		if ( $child instanceof WP_REST_Response ) return $child;

		if ( ! class_exists( 'RK_MC_Adventures_Filter_Service' ) ) {
			return new WP_REST_Response( array( 'error' => 'adventures_unavailable' ), 503 );
		}

		$course_id = (int) $request->get_param( 'course_id' );
		$detail    = RK_MC_Adventures_Filter_Service::get_course_detail_for_child( $child, $course_id );
		if ( ! $detail ) {
			return new WP_REST_Response( array( 'error' => 'not_found' ), 404 );
		}

		$formatted                = self::format_course( $detail );
		$formatted['description'] = (string) ( $detail['description'] ?? '' );

		return new WP_REST_Response( $formatted, 200 );
	}
}