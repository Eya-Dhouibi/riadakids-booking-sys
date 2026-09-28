<?php
declare( strict_types=1 );
/**
 * Application — JourneyQueryService  (Read Model)  v2.1.0
 *
 * Assemble la photographie complète du parcours d'un enfant.
 * Retourne un RKP_JourneySnapshot immuable — même objet pour tous les dashboards.
 *
 * v2.1 — Corrections audit :
 *   P1-6  compute_next_action : matrice d'états explicite (le cas "booking à venir"
 *         retourne désormais 'attend_session' ; 'book_session' = pas de booking).
 *   P1-7  streak réel (GamificationQueryService::get_streak) — plus de 0 en dur.
 *   P2    cache transient 5 min du snapshot, invalidé par CacheInvalidationListener
 *         via le hook `rkp_child_cache_invalidate` (infrastructure déjà en place).
 *
 * RÈGLE : aucun appel direct à WP_Query, get_post_meta, tutor_utils() ou $wpdb.
 *         Tout passe par LearningQueryService ou les Repositories.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class RKP_JourneyQueryService {

	const CACHE_PREFIX = 'rkp_journey_';
	const CACHE_TTL    = 5 * MINUTE_IN_SECONDS;

	public static function boot(): void {
		add_action( 'rkp_child_cache_invalidate', [ self::class, 'clear_cache' ] );
	}

	public static function clear_cache( int $child_wp_uid ): void {
		delete_transient( self::CACHE_PREFIX . $child_wp_uid );
	}

	public static function getJourney( int $child_id, bool $bypass_cache = false ): ?RKP_JourneySnapshot {
		if ( ! $bypass_cache ) {
			$cached = get_transient( self::CACHE_PREFIX . $child_id );
			if ( $cached instanceof RKP_JourneySnapshot ) {
				return $cached;
			}
		}

		$snapshot = self::buildSnapshot( $child_id );

		if ( $snapshot !== null ) {
			set_transient( self::CACHE_PREFIX . $child_id, $snapshot, self::CACHE_TTL );
		}
		return $snapshot;
	}

	private static function buildSnapshot( int $child_wp_uid ): ?RKP_JourneySnapshot {
		$courses = RKP_LearningQueryService::get_courses_for_child( $child_wp_uid );
		if ( empty( $courses ) ) return null;

		$course   = self::find_active_course( $courses, $child_wp_uid ) ?? $courses[0];
		$progress = RKP_LearningQueryService::get_progress( $course->id, $child_wp_uid );

		$topics         = RKP_LearningQueryService::get_topics( $course->id );
		$current_lesson = RKP_ProgressRepository::get_last_completed_lesson( $course->id, $child_wp_uid );
		$next_lesson    = RKP_ProgressRepository::get_next_lesson( $course->id, $child_wp_uid );
		$current_topic  = self::find_topic_for_lesson( $current_lesson, $topics );

		$upcoming_booking = self::find_upcoming_booking( $child_wp_uid, $course->id );
		$next_quiz        = self::find_next_quiz( $course->id, $child_wp_uid );

		[ $xp, $level, $streak, $achievements ] = self::fetch_gamification( $child_wp_uid );

		return new RKP_JourneySnapshot(
			child_id:         $child_wp_uid,
			course:           $course,
			topic:            $current_topic,
			current_lesson:   $current_lesson,
			next_lesson:      $next_lesson,
			next_quiz:        $next_quiz, // v2.3 — premier quiz du cours non encore tenté
			progress:         $progress,
			upcoming_booking: $upcoming_booking,
			coach_id:         $course->instructor_id ?: null,
			coach_name:       $course->instructor_name,
			xp:               $xp,
			level:            $level,
			streak:           $streak,
			achievements:     $achievements, // v2.3 — badges obtenus (view models, plus récents d'abord)
			next_action:      self::compute_next_action( $progress, $next_lesson, $next_quiz, $upcoming_booking ),
			updated_at:       new \DateTimeImmutable(),
			next_journey:     self::recommend_next_journey( $courses, $course, $progress ),
		);
	}

	// ── Helpers privés ─────────────────────────────────────────────────────

	private static function find_active_course( array $courses, int $child_id ): ?RKP_Course {
		foreach ( $courses as $course ) {
			$p = RKP_LearningQueryService::get_progress( $course->id, $child_id );
			if ( $p && ! $p->is_complete() ) return $course;
		}
		return null;
	}

	/** @param RKP_Topic[] $topics */
	private static function find_topic_for_lesson( ?RKP_Lesson $lesson, array $topics ): ?RKP_Topic {
		if ( ! $lesson ) return null;
		foreach ( $topics as $topic ) {
			if ( $topic->id === $lesson->topic_id ) return $topic;
		}
		return null;
	}

	private static function find_upcoming_booking( int $child_id, int $course_id ): ?RKP_Booking {
		$bookings = RKP_BookingRepository::find_upcoming_by_child( $child_id, $course_id, 1 );
		return $bookings[0] ?? null;
	}

	/** @return array{int, int, int, array} [xp, level, streak, achievements] */
	private static function fetch_gamification( int $child_wp_uid ): array {
		$child_rk_id = RKP_GamificationQueryService::get_child_rk_id_for_wp_user( $child_wp_uid );
		if ( ! $child_rk_id ) return [ 0, 1, 0, [] ];
		$xp     = RKP_GamificationQueryService::get_total_points( $child_rk_id );
		$level  = RKP_GamificationQueryService::get_level( $child_rk_id )['num'] ?? 1;
		$streak = RKP_GamificationQueryService::get_streak( $child_rk_id );

		// v2.3 — badges obtenus, plus récents d'abord (view models du BadgeQueryService).
		$achievements = array_slice(
			array_reverse( RKP_BadgeQueryService::get_badges( $child_rk_id ) ),
			0,
			10
		);
		return [ $xp, $level, $streak, $achievements ];
	}

	/**
	 * v2.3 — Premier quiz du cours actif jamais tenté par l'enfant.
	 * null si tous les quiz ont au moins une tentative (ou aucun quiz).
	 */
	private static function find_next_quiz( int $course_id, int $child_wp_uid ): ?RKP_Quiz {
		foreach ( RKP_QuizRepository::find_by_course( $course_id ) as $quiz ) {
			if ( null === RKP_QuizAttemptRepository::get_best_score( $quiz->id, $child_wp_uid ) ) {
				return $quiz;
			}
		}
		return null;
	}

	/**
	 * v2.4 (Audit P5-20) — Next Journey : cours recommandé quand le cours
	 * actif est terminé (le cycle de rétention ne s'arrête plus au certificat).
	 *
	 * Heuristique volontairement simple et explicable au parent :
	 *   1. même catégorie principale que le cours terminé, non encore inscrit ;
	 *   2. à défaut, premier cours publié non encore inscrit.
	 * Filtre `rkp_next_journey` pour brancher une logique métier plus fine
	 * (niveaux, âge, prérequis) sans toucher au service.
	 *
	 * @param RKP_Course[] $enrolled
	 */
	private static function recommend_next_journey( array $enrolled, RKP_Course $current, ?RKP_Progress $progress ): ?RKP_Course {
		if ( ! $progress || ! $progress->is_complete() ) {
			return null; // on ne recommande la suite qu'une fois le parcours achevé
		}

		$enrolled_ids = array_map( static fn( RKP_Course $c ) => $c->id, $enrolled );
		$category     = RKP_CategoryRepository::get_primary_for_course( $current->id );

		$candidate = null;
		$fallback  = null;
		foreach ( RKP_CourseRepository::find_all( 50 ) as $course ) {
			if ( in_array( $course->id, $enrolled_ids, true ) ) continue;
			if ( null === $fallback ) $fallback = $course;
			if ( '' !== $category && RKP_CategoryRepository::get_primary_for_course( $course->id ) === $category ) {
				$candidate = $course;
				break;
			}
		}

		/** @var ?RKP_Course */
		return apply_filters( 'rkp_next_journey', $candidate ?? $fallback, $current, $enrolled );
	}

	/**
	 * Matrice d'états explicite (v2.1 sémantique corrigée, v2.3 + take_quiz) :
	 *
	 *   cours terminé                        → 'idle'            (rien à faire ici)
	 *   session réservée à venir             → 'attend_session'  (priorité : le rendez-vous)
	 *   leçon suivante disponible            → 'continue_lesson'
	 *   plus de leçon mais quiz jamais tenté → 'take_quiz'       (valider les acquis)
	 *   rien de tout cela                    → 'book_session'    (il faut réserver)
	 */
	private static function compute_next_action(
		?RKP_Progress $progress,
		?RKP_Lesson   $next_lesson,
		?RKP_Quiz     $next_quiz,
		?RKP_Booking  $booking
	): string {
		if ( $progress && $progress->is_complete() ) return 'idle';
		if ( $booking )     return 'attend_session';
		if ( $next_lesson ) return 'continue_lesson';
		if ( $next_quiz )   return 'take_quiz';
		return 'book_session';
	}
}
