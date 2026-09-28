<?php
declare( strict_types=1 );
/**
 * RK_Missions_REST — /rk/v1/children/{child_id}/missions (GET) et
 * /rk/v1/children/{child_id}/missions/{mission_id}/progress (POST).
 *
 * §2.2 du plan : le POST progress réutilise la MÊME autorisation que la
 * route REST web équivalente (RKP_MissionCommandService) — aucune règle
 * d'écriture réinventée côté mobile.
 *
 * v4.20.1 — CORRECTIF : handle_list() appelait
 * `RKP_MissionQueryService::get_active_for_child()`, une méthode qui n'a
 * jamais existé dans ce service (seule `get_weekly_missions()` existe,
 * cf. Application/Query/MissionQueryService.php) — chaque appel provoquait
 * une fatal error PHP (500 côté mobile, reproduit sur
 * GET /children/{id}/missions). Remplacé par `get_weekly_missions()`,
 * mappé vers le format attendu par le modèle Dart `Mission`
 * (progress = fraction 0..1, pas le pourcentage 0..100 stocké côté PHP).
 *
 * handle_progress() a le MÊME problème mais plus profond : elle appelait
 * `RKP_MissionCommandService::update_progress()`, qui n'existe pas non
 * plus — le service n'expose que `record_progress( child_rk_id,
 * mission_key: string, increment: int = 1 )`, une méthode qui INCRÉMENTE
 * la progression d'une mission identifiée par sa clé (ex. 'pass_quiz'),
 * pas qui la POSE à une valeur absolue pour un ID numérique. Réutiliser
 * cette méthode ici demanderait de décider si "progress" envoyé par le
 * mobile est un incrément ou une valeur absolue, et de faire correspondre
 * mission_id (PK numérique) → mission_key (string) — une vraie décision
 * produit, pas un simple renommage. Aucun écran mobile actuel
 * (MissionsScreen) n'appelle cette route en écriture ; en attendant cette
 * décision, la route renvoie 503 plutôt que de planter en fatal error ou
 * d'inventer une sémantique d'écriture non validée.
 *
 * @package RK_Platform
 * @since   4.19.0
 * @since   4.20.1 Fix 500 sur handle_list() + garde-fou sur handle_progress().
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class RK_Missions_REST {

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes(): void {
		register_rest_route( 'rk/v1', '/children/(?P<child_id>\d+)/missions', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'handle_list' ),
			'permission_callback' => 'is_user_logged_in',
		) );

		register_rest_route( 'rk/v1', '/children/(?P<child_id>\d+)/missions/(?P<mission_id>\d+)/progress', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'handle_progress' ),
			'permission_callback' => 'is_user_logged_in',
			'args'                => array(
				'progress' => array( 'required' => true, 'type' => 'number' ),
			),
		) );
	}

	/**
	 * Self-access enfant (pivot 4.19.1) : le child_id de l'URL doit être
	 * celui de l'enfant authentifié — plus de notion "parent→enfant" côté
	 * mobile, cf. RKP_MobileChildAccess.
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

	public static function handle_list( WP_REST_Request $request ) {
		$child = self::resolve_owned_child( $request );
		if ( $child instanceof WP_REST_Response ) return $child;

		if ( ! class_exists( 'RKP_MissionQueryService' ) ) {
			return new WP_REST_Response( array( 'error' => 'missions_unavailable' ), 503 );
		}

		$missions = RKP_MissionQueryService::get_weekly_missions( (int) $child->id );

		return new WP_REST_Response(
			array( 'missions' => array_map( array( __CLASS__, 'format_mission' ), $missions ) ),
			200
		);
	}

	/**
	 * Mappe une mission hebdomadaire (format PHP interne, `pct` 0..100)
	 * vers le format attendu par le modèle Dart `Mission`
	 * (`progress` en fraction 0..1, cf. Mission.fromJson dans
	 * rk_models.dart). `id` = PK wp_rk_child_missions, `key` conservé pour
	 * un futur usage (ex. lier une progression à sa définition) sans être
	 * consommé par le client actuel.
	 */
	private static function format_mission( array $m ): array {
		return array(
			'id'        => (int) ( $m['id'] ?? 0 ),
			'key'       => (string) ( $m['key'] ?? '' ),
			'title'     => (string) ( $m['name'] ?? '' ),
			'progress'  => ( (int) ( $m['pct'] ?? 0 ) ) / 100,
			'completed' => (bool) ( $m['completed'] ?? false ),
		);
	}

	/**
	 * Écriture — VOLONTAIREMENT non branchée sur une vraie mise à jour
	 * pour l'instant. Voir le commentaire de classe en tête de fichier :
	 * `RKP_MissionCommandService::record_progress()` incrémente une
	 * mission par sa clé (string), ce endpoint reçoit une valeur absolue
	 * pour un ID numérique — les deux sémantiques ne correspondent pas
	 * sans décision produit. Renvoie 503 plutôt qu'une fatal error ou un
	 * comportement d'écriture inventé ici.
	 */
	public static function handle_progress( WP_REST_Request $request ) {
		$child = self::resolve_owned_child( $request );
		if ( $child instanceof WP_REST_Response ) return $child;

		return new WP_REST_Response( array( 'error' => 'missions_write_unavailable' ), 503 );
	}
}