<?php
declare( strict_types=1 );
/**
 * Application — RKP_QuizFlowCommandService  (v2.6.0 — Quiz Flow)
 *
 * Pipeline serveur du cycle de vie d'un quiz coach → enfant → résultats,
 * aligné sur les grandes plateformes e-learning :
 *
 *   ASSIGNATION  announce_assignment()
 *     → Domain Event `quiz.assigned`
 *     → notification in-app ENFANT (« اختبار جديد ») + PARENT (information)
 *     (l'email enfant existant est conservé côté contrôleur)
 *
 *   RÉSULTAT     record_ays_result()  — SOURCE DE VÉRITÉ CÔTÉ SERVEUR
 *     → lit la dernière tentative AYS terminée (plus de dépendance au JS front)
 *     → IDEMPOTENT par tentative (RKP_EventLog) : un rechargement de page,
 *       un double appel ou un retry ne compte JAMAIS deux fois
 *     → XP attribué avec le MÊME barème que les quiz Tutor
 *       (100 % → 50 · réussite → 25 · ≥ 50 % → 10 · sinon → 5)
 *     → Domain Event `quiz.passed` / `quiz.failed`
 *       ⇒ notifications in-app parent + coach (subscribers existants)
 *       ⇒ invalidation des caches dashboard/journey (listener existant)
 *       ⇒ visible immédiatement : dashboard enfant, SPA coach, rapport parent
 *
 * RÈGLE : aucun SQL ici — tout passe par RKP_AysQuizRepository.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class RKP_QuizFlowCommandService {

	/* ── 1. Assignation ─────────────────────────────────────────────── */

	public static function announce_assignment(
		int $child_wp_uid,
		int $child_rk_id,
		int $quiz_id,
		int $coach_id,
		string $quiz_title,
		string $due_date = ''
	): void {
		// Domain Event (extensibilité : missions, analytics, webhooks…).
		RKP_EventBus::dispatch( new RKP_QuizAssigned( $child_wp_uid, $quiz_id, $coach_id, $quiz_title, $due_date ) );

		if ( ! class_exists( 'RK_MC_Notification_Service' ) ) return;

		$coach_user = get_userdata( $coach_id );
		$coach_name = $coach_user ? $coach_user->display_name : '';
		$due_txt    = $due_date ? ' — آخر أجل: ' . $due_date : '';

		// Notification in-app ENFANT.
		if ( $child_wp_uid > 0 ) {
			RK_MC_Notification_Service::push(
				$child_wp_uid,
				'quiz_assigned',
				'📝 اختبار جديد من مدربك ' . $coach_name . ' : « ' . $quiz_title . ' »' . $due_txt,
				[ 'quiz_id' => $quiz_id, 'due_date' => $due_date ]
			);
		}

		// Notification in-app PARENT (suivi).
		$parent_id = self::parent_wp_uid_for_child( $child_rk_id );
		if ( $parent_id > 0 ) {
			RK_MC_Notification_Service::push(
				$parent_id,
				'quiz_assigned_parent',
				'📝 اختبار جديد بانتظار طفلك : « ' . $quiz_title . ' »' . $due_txt,
				[ 'quiz_id' => $quiz_id, 'child_rk_id' => $child_rk_id ]
			);
		}
	}

	/* ── 2. Résultat (source de vérité serveur) ─────────────────────── */

	/**
	 * Enregistre la dernière tentative AYS terminée de l'enfant sur ce quiz.
	 *
	 * @return array{recorded:bool, passed:?bool, score:?int, xp:int, attempt_id:int}
	 *         recorded=false si : quiz non-RK, aucune tentative, ou tentative
	 *         déjà traitée (idempotence) — dans tous les cas sans effet de bord.
	 */
	public static function record_ays_result( int $child_wp_uid, int $quiz_id ): array {
		$none = [ 'recorded' => false, 'passed' => null, 'score' => null, 'xp' => 0, 'attempt_id' => 0 ];

		$quiz = RKP_AysQuizRepository::find_rk_quiz( $quiz_id );
		if ( ! $quiz ) return $none;

		/*
		 * v2.6.1 — RÉATTRIBUTION DES TENTATIVES ORPHELINES.
		 * AYS soumet via admin-ajax (cookie parent) → la tentative peut être
		 * enregistrée sous le parent au lieu de l'enfant. On la récupère ici
		 * AVANT toute lecture : le pipeline reste la source de vérité même
		 * quand le lien du quiz a été ouvert sans token rk_tab.
		 */
		$parent_uid = RKP_ChildRepository::get_parent_user_id( (int) $quiz->child_rk_id );
		RKP_AysQuizRepository::claim_orphan_attempts( $quiz_id, $child_wp_uid, $parent_uid );

		$report = RKP_AysQuizRepository::latest_report( $quiz_id, $child_wp_uid );
		if ( ! $report ) return $none;

		$attempt_id = (int) $report->id;

		// IDEMPOTENCE — une tentative n'est traitée qu'une seule fois, quel que
		// soit le nombre d'appels (rechargement, double clic, retry réseau).
		if ( class_exists( 'RKP_EventLog' )
			&& ! RKP_EventLog::claim( 'ays_attempt_' . $attempt_id, 'quiz_flow', 'quiz.result' ) ) {
			return $none;
		}

		$passing = (int) ( $quiz->opts['passing_grade'] ?? 80 );
		$score   = max( 0, min( 100, (int) $report->score ) );
		$passed  = $score >= $passing;

		// XP — même barème que les quiz Tutor (source_id = tentative ⇒ double filet d'idempotence).
		$xp = self::award_xp( $child_wp_uid, $score, $passed, $attempt_id );

		// Domain Events → notifications in-app parent+coach, alertes échec,
		// invalidation des caches dashboard & journey. course_id = 0 (quiz coach hors cours).
		$event = $passed
			? new RKP_QuizPassed( $child_wp_uid, $quiz_id, 0, $score )
			: new RKP_QuizFailed( $child_wp_uid, $quiz_id, 0, $score );
		RKP_EventBus::dispatch( $event );

		return [ 'recorded' => true, 'passed' => $passed, 'score' => $score, 'xp' => $xp, 'attempt_id' => $attempt_id ];
	}

	/**
	 * v2.6.1 — Réparation à la volée : réattribue les tentatives orphelines
	 * d'un quiz puis enregistre le résultat (idempotent). Appelé depuis les
	 * pages enfant quand un quiz assigné semble « non tenté » alors que
	 * l'enfant l'a passé. Sans effet si rien à réparer.
	 *
	 * @return bool true si une tentative a été (ré)attribuée à l'enfant.
	 */
	public static function repair_for_child( int $child_wp_uid, int $quiz_id ): bool {
		$result = self::record_ays_result( $child_wp_uid, $quiz_id );
		if ( $result['recorded'] ) return true;
		// record() a déjà tenté le claim ; « non enregistré » peut aussi
		// signifier « déjà traité » — l'enfant a bien une tentative liée.
		return null !== RKP_AysQuizRepository::latest_report( $quiz_id, $child_wp_uid );
	}

	/* ── Helpers privés ─────────────────────────────────────────────── */

	private static function award_xp( int $child_wp_uid, int $score, bool $passed, int $attempt_id ): int {
		if ( ! class_exists( 'RK_MC_Gamification_Service' ) ) return 0;

		$child_rk_id = RK_MC_Gamification_Service::get_child_id_for_wp_user( $child_wp_uid );
		if ( ! $child_rk_id ) return 0;

		if ( 100 === $score ) {
			[ $points, $source ] = [ 50, 'quiz_excellent' ];
		} elseif ( $passed ) {
			[ $points, $source ] = [ 25, 'quiz_pass' ];
		} elseif ( $score >= 50 ) {
			[ $points, $source ] = [ 10, 'quiz_good' ];
		} else {
			[ $points, $source ] = [ 5, 'quiz_attempted' ];
		}

		RK_MC_Gamification_Service::add_points( $child_rk_id, $points, $source, $attempt_id );

		if ( 100 === $score && class_exists( 'RK_MC_Badge_Service' ) ) {
			RK_MC_Badge_Service::award( $child_rk_id, 'quiz_perfect' );
		}
		if ( $passed && class_exists( 'RK_MC_Mission_Service' ) ) {
			RK_MC_Mission_Service::record_progress( $child_rk_id, 'pass_quiz', 1 );
		}
		return $points;
	}

	private static function parent_wp_uid_for_child( int $child_rk_id ): int {
		return RKP_ChildRepository::get_parent_user_id( $child_rk_id );
	}
}
