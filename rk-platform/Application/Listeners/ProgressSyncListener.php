<?php
declare( strict_types=1 );
/**
 * Application/Listeners — ProgressSyncListener  (v3.0.0 — nouveau)
 *
 * GARANTIT que toute progression Tutor LMS est transmise à la plateforme.
 *
 * S'abonne aux Domain Events pédagogiques (émis par RKP_TutorBridge) et,
 * pour chaque événement :
 *   1. Relit la progression RÉELLE dans Tutor (source de vérité) via
 *      RKP_ProgressRepository — jamais d'incrément aveugle côté plateforme.
 *   2. Persiste le snapshot (RKP_ProgressSnapshotRepository) → lecture O(1)
 *      pour les rosters coach, le board parent et les rapports.
 *   3. Invalide les caches des TROIS vues (enfant, parent, coach).
 *   4. Notifie parent + coach aux jalons (25/50/75/100 %) — pas à chaque leçon,
 *      pour ne pas noyer la cloche.
 *
 * Idempotence : déléguée à RKP_EventLog (event_id, subscriber) — un événement
 * rejoué ne renotifie pas ; le snapshot, lui, est un upsert donc sans danger.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class RKP_ProgressSyncListener {

	private const SUBSCRIBER = 'progress_sync';

	/** Jalons de notification (percent). */
	private const MILESTONES = [ 25, 50, 75, 100 ];

	public static function subscribe(): void {
		if ( ! class_exists( 'RKP_EventBus' ) ) return;
		RKP_EventBus::subscribe( 'lesson.completed', [ self::class, 'on_learning_event' ] );
		RKP_EventBus::subscribe( 'quiz.passed',      [ self::class, 'on_learning_event' ] );
		RKP_EventBus::subscribe( 'quiz.failed',      [ self::class, 'on_learning_event' ] );
		RKP_EventBus::subscribe( 'course.started',   [ self::class, 'on_learning_event' ] );
		RKP_EventBus::subscribe( 'course.completed', [ self::class, 'on_course_completed' ] );
	}

	/* ─────────────────────────────────────────────────────────────
	 * Handlers
	 * ───────────────────────────────────────────────────────────── */

	public static function on_learning_event( RKP_DomainEvent $e ): void {
		$data      = $e->to_array();
		$child_uid = (int) ( $data['child_id'] ?? 0 );
		$course_id = (int) ( $data['course_id'] ?? 0 );
		if ( ! $child_uid || ! $course_id ) return;

		self::sync( $child_uid, $course_id, $e->get_name() );
	}

	public static function on_course_completed( RKP_DomainEvent $e ): void {
		$data      = $e->to_array();
		$child_uid = (int) ( $data['child_id'] ?? 0 );
		$course_id = (int) ( $data['course_id'] ?? 0 );
		if ( ! $child_uid || ! $course_id ) return;

		self::sync( $child_uid, $course_id, 'course.completed', /* completed */ true );
	}

	/* ─────────────────────────────────────────────────────────────
	 * Cœur : relire Tutor, persister, propager
	 * ───────────────────────────────────────────────────────────── */

	/**
	 * Point d'entrée unique — appelable aussi hors événement
	 * (reset Tutor, backfill WP-CLI, cron de réconciliation).
	 */
	public static function sync( int $child_uid, int $course_id, string $reason = 'manual', bool $completed = false ): void {

		/* 1 ── Vérité Tutor */
		$percent       = RKP_ProgressRepository::get_percent( $course_id, $child_uid );
		$lessons_total = RKP_ProgressRepository::get_lesson_count( $course_id );
		$lessons_done  = RKP_ProgressRepository::get_completed_lesson_count( $course_id, $child_uid );
		$last_lesson   = RKP_ProgressRepository::get_last_completed_lesson( $course_id, $child_uid );

		if ( $completed ) $percent = 100;

		$prev = class_exists( 'RKP_ProgressSnapshotRepository' )
			? RKP_ProgressSnapshotRepository::find( $child_uid, $course_id )
			: null;
		$prev_percent = $prev ? (int) $prev->percent : 0;

		/* 2 ── Persistance snapshot */
		if ( class_exists( 'RKP_ProgressSnapshotRepository' ) ) {
			RKP_ProgressSnapshotRepository::upsert( $child_uid, $course_id, [
				'percent'        => $percent,
				'lessons_done'   => $lessons_done,
				'lessons_total'  => $lessons_total,
				'last_lesson_id' => $last_lesson->id ?? 0,
				'completed_at'   => ( 100 === $percent ) ? current_time( 'mysql' ) : null,
				'source'         => 'tutor:' . $reason,
			] );
		}

		/* 3 ── Invalidation des caches des trois vues */
		self::flush_all_views( $child_uid );

		/* 4 ── Notifications de jalon (franchissement uniquement, idempotent) */
		if ( $percent > $prev_percent ) {
			foreach ( self::MILESTONES as $m ) {
				if ( $prev_percent < $m && $percent >= $m ) {
					self::notify_milestone( $child_uid, $course_id, $m );
					break; // un seul jalon notifié par sync
				}
			}
		}

		/** Vue temps réel / intégrations tierces. */
		do_action( 'rkp_progress_synced', $child_uid, $course_id, $percent, $prev_percent, $reason );
	}

	/* ─────────────────────────────────────────────────────────────
	 * Propagation famille : enfant + parent + coach
	 * ───────────────────────────────────────────────────────────── */

	private static function flush_all_views( int $child_uid ): void {
		// Vue ENFANT — cache dashboard (clé historique du module Children)
		delete_transient( 'rk_child_dash_' . $child_uid );
		do_action( 'rk_mc_flush_child_dashboard', $child_uid );

		$fam = self::family( $child_uid );

		// CORRECTIF — le cache RÉELLEMENT lu par le Child Dashboard et par
		// child-profile.php (RK_MC_Child_Dashboard_Data, clé rk_mc_dash_{child_rk_id},
		// TTL 3 min) n'était jamais purgé ici : cette méthode ne vidait que la clé
		// legacy rk_child_dash_{child_wp_uid}, jamais consommée en lecture. Sans ce
		// correctif, badges/progress/statut "terminé" pouvaient rester périmés
		// jusqu'à 3 minutes après un lesson.completed / quiz.passed / course.completed.
		if ( $fam['child_rk_id'] > 0 && class_exists( 'RK_MC_Child_Dashboard_Data' ) ) {
			RK_MC_Child_Dashboard_Data::clear_cache( $fam['child_rk_id'] );
		}

		// Vue PARENT — board parent
		if ( $fam['parent_id'] > 0 ) {
			delete_transient( 'rk_parent_board_' . $fam['parent_id'] );
		}
		// Vue COACH — stats roster
		if ( $fam['coach_id'] > 0 ) {
			delete_transient( 'rk_coach_stats_' . $fam['coach_id'] );
			do_action( 'rk_coach_flush_stats', $fam['coach_id'] );
		}
	}

	private static function notify_milestone( int $child_uid, int $course_id, int $milestone ): void {
		if ( ! class_exists( 'RK_MC_Notification_Service' ) ) return;

		// Idempotence par événement de jalon : (child, course, jalon) unique.
		if ( class_exists( 'RKP_EventLog' ) ) {
			$event_id = sprintf( 'milestone:%d:%d:%d', $child_uid, $course_id, $milestone );
			if ( ! RKP_EventLog::claim( $event_id, self::SUBSCRIBER, 'progress.milestone' ) ) {
				return; // déjà notifié
			}
		}

		$course_title = get_the_title( $course_id ) ?: __( 'دورة', 'rk-platform' );
		$fam          = self::family( $child_uid );

		// Enfant : encouragement
		RK_MC_Notification_Service::push(
			$child_uid,
			'progress_milestone',
			( 100 === $milestone )
				? sprintf( 'مبروك! أكملت البرنامج "%s" 🎉', $course_title )
				: sprintf( 'رائع! وصلت إلى %d%% في "%s" 🚀', $milestone, $course_title ),
			[ 'course_id' => $course_id, 'milestone' => $milestone ]
		);

		// Parent : suivi
		if ( $fam['parent_id'] > 0 ) {
			RK_MC_Notification_Service::push(
				$fam['parent_id'],
				'progress_milestone',
				sprintf( 'أنجز %s %d%% من البرنامج "%s"', $fam['child_name'], $milestone, $course_title ),
				[ 'course_id' => $course_id, 'child_wp_uid' => $child_uid, 'milestone' => $milestone ]
			);
		}

		// Coach : seulement 100 % (éviter le bruit côté pro)
		if ( 100 === $milestone && $fam['coach_id'] > 0 ) {
			RK_MC_Notification_Service::push(
				$fam['coach_id'],
				'progress_milestone',
				sprintf( 'أكمل %s البرنامج "%s"', $fam['child_name'], $course_title ),
				[ 'course_id' => $course_id, 'child_wp_uid' => $child_uid ]
			);
		}
	}

	/** Contexte famille via le service central (fallback dégradé sinon). */
	private static function family( int $child_uid ): array {
		if ( class_exists( 'RKP_FamilyLinkQueryService' ) ) {
			return RKP_FamilyLinkQueryService::context_by_wp_uid( $child_uid );
		}
		return [ 'parent_id' => 0, 'coach_id' => 0, 'child_name' => '', 'child_rk_id' => 0 ];
	}
}
