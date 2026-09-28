<?php
declare( strict_types=1 );
/**
 * RK_Journey_REST — GET /rk/v1/children/{child_id}/journey.
 *
 * Alimente l'écran mobile "الحساب" avec EXACTEMENT les mêmes données que
 * le template web `rk-mon-parcours.php` : hero card (niveau/XP/série/coach),
 * action principale (CTA selon next_action), étapes du parcours (rail des
 * topics du cours actif). Source unique : RKP_JourneyQueryService::getJourney(
 * $child_id ) — même service que le web, DÉCOUPLÉ du contexte $wp_query du
 * dashboard (contrairement à RK_MC_Tutor_Dashboard::get_snapshot()), donc
 * appelable tel quel depuis une requête REST sans état.
 *
 * Les libellés de niveau et le mapping CTA-par-next_action sont de la pure
 * présentation recopiée telle quelle depuis rk-mon-parcours.php (comme
 * achievement_category_labels() dans class-rk-children-content-rest.php) —
 * aucun calcul métier, aucune requête réécrite ici.
 *
 * `cta_target`/`stages` ne renvoient JAMAIS d'URL web brute : le mobile
 * navigue vers ses propres écrans natifs (adventures/bookings/missions),
 * jamais vers une page web externe — supprime une surface de lien sortant
 * par rapport au web, dans le droit fil des contraintes Politique Familles
 * déjà actées pour cette app (voir dashboard_screen.dart).
 *
 * @package RK_Platform
 * @since   4.20.5
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class RK_Journey_REST {

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes(): void {
		register_rest_route( 'rk/v1', '/children/(?P<child_id>\d+)/journey', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'handle_journey' ),
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

	/**
	 * MÊMES 8 niveaux, MÊMES libellés/icônes/couleurs que le tableau
	 * $level_labels codé en dur dans rk-mon-parcours.php (web) — copiés ici
	 * tels quels pour que mobile et web affichent des niveaux identiques.
	 */
	private static function level_labels(): array {
		return array(
			1 => array( 'label' => 'شرارة',    'icon' => 'spark',    'color' => '#94a3b8' ),
			2 => array( 'label' => 'مبتدئ',    'icon' => 'seedling', 'color' => '#22c55e' ),
			3 => array( 'label' => 'مجتهد',    'icon' => 'rocket',   'color' => '#3b82f6' ),
			4 => array( 'label' => 'متقدم',    'icon' => 'fire',     'color' => '#f59e0b' ),
			5 => array( 'label' => 'نجم',      'icon' => 'star',     'color' => '#f97316' ),
			6 => array( 'label' => 'بطل',      'icon' => 'trophy',   'color' => '#e11d48' ),
			7 => array( 'label' => 'أسطورة',   'icon' => 'diamond',  'color' => '#7c3aed' ),
			8 => array( 'label' => 'لا يُهزم', 'icon' => 'crown',    'color' => '#fbbf24' ),
		);
	}

	public static function handle_journey( WP_REST_Request $request ) {
		$child = self::resolve_owned_child( $request );
		if ( $child instanceof WP_REST_Response ) return $child;

		if ( ! class_exists( 'RKP_JourneyQueryService' ) ) {
			return new WP_REST_Response( array( 'error' => 'journey_unavailable' ), 503 );
		}

		$snapshot = RKP_JourneyQueryService::getJourney( (int) $child->id );
		$has_snap = $snapshot instanceof RKP_JourneySnapshot;

		$xp        = $has_snap ? $snapshot->xp : 0;
		$level_num = $has_snap ? $snapshot->level : 1;
		$streak    = $has_snap ? $snapshot->streak : 0;
		$pct       = $has_snap ? $snapshot->progress_percent() : 0;

		$levels   = self::level_labels();
		$lvl_info = $levels[ $level_num ] ?? $levels[1];

		return new WP_REST_Response( array(
			'xp'               => $xp,
			'level'            => $level_num,
			'level_label'      => $lvl_info['label'],
			'level_icon_key'   => $lvl_info['icon'],
			'level_color'      => $lvl_info['color'],
			'streak'           => $streak,
			'progress_percent' => $pct,
			'coach_name'       => $has_snap ? $snapshot->coach_name : '',
			'course_title'     => ( $has_snap && $snapshot->course ) ? $snapshot->course->title : '',
			'has_active_course' => $has_snap && $snapshot->has_active_course(),
			'cta'              => self::format_cta( $has_snap ? $snapshot->next_action : 'idle' ),
			'stages'           => self::format_stages( $has_snap ? $snapshot : null, $pct ),
		), 200 );
	}

	/**
	 * Même mapping next_action → libellé/icône que le switch() de
	 * rk-mon-parcours.php, mais `target` remplace `cta_url` : un couple
	 * {screen, param} que l'app résout vers un écran natif, jamais une URL
	 * web (voir note de classe ci-dessus).
	 */
	private static function format_cta( string $next_action ): array {
		switch ( $next_action ) {
			case 'continue_lesson':
				return array( 'label' => 'تابع درسك', 'icon_key' => 'book', 'target' => array( 'screen' => 'adventures' ) );
			case 'take_quiz':
				return array( 'label' => 'اجتز الاختبار', 'icon_key' => 'quiz', 'target' => array( 'screen' => 'adventures' ) );
			case 'attend_session':
				return array( 'label' => 'جلستك القادمة', 'icon_key' => 'target', 'target' => array( 'screen' => 'bookings' ) );
			case 'book_session':
				return array( 'label' => 'احجز جلسة', 'icon_key' => 'calendar', 'target' => array( 'screen' => 'bookings' ) );
			default:
				return array( 'label' => 'اكتشف دروسك', 'icon_key' => 'sparkles', 'target' => array( 'screen' => 'adventures' ) );
		}
	}

	/**
	 * Même règle de secours que le web : topics du cours actif si
	 * disponibles, sinon 3 étapes génériques ('done'/'active'/'pending')
	 * dérivées du pourcentage — cf. bloc "Si pas de topics disponibles"
	 * dans rk-mon-parcours.php.
	 */
	private static function format_stages( ?RKP_JourneySnapshot $snapshot, int $pct ): array {
		if ( ! $snapshot ) return array();

		$stages = array();
		if ( $snapshot->course && class_exists( 'RKP_LearningQueryService' ) ) {
			foreach ( RKP_LearningQueryService::get_topics( $snapshot->course->id ) as $t ) {
				$is_current = $snapshot->topic && (int) $snapshot->topic->id === (int) $t->id;
				$is_done    = ! $is_current && $pct > 0;
				$stages[] = array(
					'title'  => (string) $t->title,
					'status' => $is_current ? 'active' : ( $is_done ? 'done' : 'pending' ),
				);
			}
		}

		if ( empty( $stages ) ) {
			$topic_title = $snapshot->topic ? (string) $snapshot->topic->title : '';
			$stages = array(
				array( 'title' => 'البداية', 'status' => $pct > 0 ? 'done' : 'active' ),
				array( 'title' => $topic_title ?: 'الوحدة الحالية', 'status' => 'active' ),
				array( 'title' => 'الامتحان النهائي', 'status' => 'pending' ),
			);
		}
		return $stages;
	}
}
