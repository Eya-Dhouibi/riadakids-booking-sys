<?php
declare( strict_types=1 );
/**
 * RK_MC_Quiz_Play_Ajax — v9.56 (14/08/2026)
 *
 * Handler de soumission du moteur de quiz natif (rk-quiz-play.php).
 * La correction se fait ENTIÈREMENT côté serveur — le client n'envoie
 * que quiz_id + les IDs de réponses choisies, jamais un score ou un
 * verdict de correction (empêche un enfant techniquement outillé de
 * falsifier son résultat via les DevTools).
 *
 * Écrit dans wp_aysquiz_reports au même format que le moteur AYS natif
 * (voir RKP_AysQuizRepository::insert_report()), puis délègue à
 * RKP_QuizFlowCommandService::record_ays_result() pour déclencher
 * XP/badges/notifications — même pipeline que les quiz joués via
 * l'ancien lien externe /ays-quiz-maker/.
 *
 * @package RK_My_Children
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class RK_MC_Quiz_Play_Ajax {

	public static function init(): void {
		add_action( 'wp_ajax_rk_quiz_play_submit', [ __CLASS__, 'handle_submit' ] );
	}

	public static function handle_submit(): void {
		$quiz_id = isset( $_POST['quiz_id'] ) ? absint( $_POST['quiz_id'] ) : 0;

		if ( ! $quiz_id || ! check_ajax_referer( 'rk_quiz_play_' . $quiz_id, 'nonce', false ) ) {
			wp_send_json_error( [ 'message' => 'invalid_nonce' ], 403 );
		}

		$wp_user_id = get_current_user_id();
		if ( ! $wp_user_id ) {
			wp_send_json_error( [ 'message' => 'not_logged_in' ], 401 );
		}

		if ( ! class_exists( 'RKP_AysQuizRepository' ) ) {
			wp_send_json_error( [ 'message' => 'repository_unavailable' ], 500 );
		}

		$quiz = RKP_AysQuizRepository::find_rk_quiz( $quiz_id );
		if ( ! $quiz ) {
			wp_send_json_error( [ 'message' => 'quiz_not_found' ], 404 );
		}

		// Vérifie que l'enfant connecté est bien le propriétaire du quiz
		// (quiz_url = 'rk:child:{id}') — un enfant ne peut jamais
		// soumettre le test assigné à un autre, même en connaissant son
		// quiz_id (essai manuel via DevTools/Postman).
		if ( class_exists( 'RKP_ChildRepository' ) ) {
			$child_wp_uid_owner = RKP_ChildRepository::get_wp_user_id( (int) $quiz->child_rk_id );
			if ( $child_wp_uid_owner !== $wp_user_id ) {
				wp_send_json_error( [ 'message' => 'forbidden' ], 403 );
			}
		}

		// Déjà tenté et limite atteinte ? Refuse une deuxième écriture.
		$max_attempts = (int) ( $quiz->opts['rk_max_attempts'] ?? 1 );
		if ( $max_attempts > 0 ) {
			$already = RKP_AysQuizRepository::latest_report( $quiz_id, $wp_user_id );
			if ( $already ) {
				wp_send_json_error( [ 'message' => 'already_attempted' ], 409 );
			}
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce déjà vérifié plus haut (check_ajax_referer).
		$answers_raw = isset( $_POST['answers'] ) ? wp_unslash( $_POST['answers'] ) : '[]'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$answers_in  = json_decode( (string) $answers_raw, true );
		if ( ! is_array( $answers_in ) || empty( $answers_in ) ) {
			wp_send_json_error( [ 'message' => 'no_answers' ], 400 );
		}

		// Questions + bonnes réponses connues UNIQUEMENT côté serveur —
		// jamais transmises au client dans un format exploitable pour
		// deviner la correction avant soumission (voir rk-quiz-play.php,
		// $questions_for_js retire déjà le champ 'correct').
		$questions = RKP_AysQuizRepository::find_questions_with_answers( $quiz_id );
		if ( empty( $questions ) ) {
			wp_send_json_error( [ 'message' => 'no_questions' ], 404 );
		}

		// Map question_id → answer_id correct(s), pour comparaison O(1).
		$correct_by_question = [];
		foreach ( $questions as $q ) {
			foreach ( $q->answers as $a ) {
				if ( (int) $a->correct === 1 ) {
					$correct_by_question[ (int) $q->id ] = (int) $a->id;
				}
			}
		}

		// Construit le tableau final answer par answer, en ignorant tout
		// question_id envoyé par le client qui ne correspond pas à une
		// vraie question de CE quiz (empêche l'injection de faux points).
		$valid_question_ids = array_map( static fn( $q ) => (int) $q->id, $questions );
		$final_answers       = [];
		foreach ( $answers_in as $entry ) {
			$qid = isset( $entry['question_id'] ) ? (int) $entry['question_id'] : 0;
			$aid = isset( $entry['answer_id'] ) ? (int) $entry['answer_id'] : 0;
			if ( ! in_array( $qid, $valid_question_ids, true ) ) continue;

			$correct_answer_id = $correct_by_question[ $qid ] ?? 0;
			$final_answers[] = [
				'question_id' => $qid,
				'answer_id'   => $aid,
				'correct'     => ( $aid > 0 && $aid === $correct_answer_id ),
			];
		}

		if ( empty( $final_answers ) ) {
			wp_send_json_error( [ 'message' => 'no_valid_answers' ], 400 );
		}

		$started_at = isset( $_POST['started_at'] ) ? absint( $_POST['started_at'] ) : 0;
		$start      = $started_at > 0
			? new DateTimeImmutable( '@' . $started_at )
			: new DateTimeImmutable( '-1 minute' );
		$end        = new DateTimeImmutable();

		$report_id = RKP_AysQuizRepository::insert_report( $quiz_id, $wp_user_id, $start, $end, $final_answers );
		if ( ! $report_id ) {
			wp_send_json_error( [ 'message' => 'insert_failed' ], 500 );
		}

		// Délègue au pipeline existant : XP, badges, notifications
		// coach/parent, invalidation des caches dashboard — même chemin
		// que le moteur AYS natif (l'enfant venait auparavant du lien
		// externe /ays-quiz-maker/, ce handler est la seule chose qui
		// change, pas ce qui se passe après).
		$result = class_exists( 'RKP_QuizFlowCommandService' )
			? RKP_QuizFlowCommandService::record_ays_result( $wp_user_id, $quiz_id )
			: [ 'recorded' => false, 'passed' => null, 'score' => null ];

		$corrects_count = count( array_filter( $final_answers, static fn( $a ) => $a['correct'] ) );
		$questions_count = count( $final_answers );
		$score = $questions_count > 0 ? (int) round( ( $corrects_count / $questions_count ) * 100 ) : 0;
		$passing = (int) ( $quiz->opts['passing_grade'] ?? 80 );

		wp_send_json_success( [
			'score'      => $result['score'] ?? $score,
			'passed'     => $result['passed'] ?? ( $score >= $passing ),
			'xp'         => $result['xp'] ?? 0,
			'report_id'  => $report_id,
		] );
	}
}