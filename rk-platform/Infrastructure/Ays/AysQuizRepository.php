<?php
declare( strict_types=1 );
/**
 * Infrastructure/Ays — RKP_AysQuizRepository  (v2.6.0 — Quiz Flow)
 *
 * SEUL point de contact avec le schéma AYS Quiz Maker
 * (wp_aysquiz_quizes / _reports). Encapsule les quiz assignés par les
 * coachs (convention quiz_url = 'rk:child:{rk_children.id}').
 *
 * Retire le SQL brut du template my-quiz-attempts et des contrôleurs,
 * et met en cache la détection de table (plus de SHOW TABLES à chaud).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class RKP_AysQuizRepository {

	private const EXISTS_OPT = 'rkp_tbl_aysquiz_exists';

	public static function is_available(): bool {
		$cached = get_option( self::EXISTS_OPT, null );
		if ( null !== $cached ) return (bool) $cached;

		global $wpdb;
		$t      = $wpdb->prefix . 'aysquiz_quizes';
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) === $t;
		update_option( self::EXISTS_OPT, $exists ? 1 : 0, false );
		return $exists;
	}

	public static function refresh_table_cache(): void {
		delete_option( self::EXISTS_OPT );
	}

	/**
	 * Quiz assignés à un enfant + dernière tentative + nombre de tentatives.
	 *
	 * @return object[] { quiz_id, quiz_title, options, custom_post_id, create_date,
	 *                    score, corrects_count, questions_count, end_date, attempts_count }
	 */
	public static function find_assigned_for_child( int $child_rk_id, int $child_wp_uid ): array {
		if ( ! self::is_available() ) return [];

		global $wpdb;
		$qz = $wpdb->prefix . 'aysquiz_quizes';
		$rp = $wpdb->prefix . 'aysquiz_reports';

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT q.id AS quiz_id, q.title AS quiz_title, q.options,
			        q.custom_post_id, q.create_date,
			        r.score, r.corrects_count, r.questions_count, r.end_date,
			        ( SELECT COUNT(*) FROM {$rp} r2
			           WHERE r2.quiz_id = q.id AND r2.user_id = %d
			             AND r2.end_date IS NOT NULL ) AS attempts_count
			   FROM {$qz} q
			   LEFT JOIN {$rp} r ON r.quiz_id = q.id AND r.user_id = %d
			  WHERE q.quiz_url = %s
			  ORDER BY q.create_date DESC, r.end_date DESC", // phpcs:ignore WordPress.DB.PreparedSQL -- tables from $wpdb->prefix
			$child_wp_uid,
			$child_wp_uid,
			'rk:child:' . $child_rk_id
		) ) ?: [];

		// Une ligne par quiz — tentative la plus récente.
		$seen = [];
		$out  = [];
		foreach ( $rows as $row ) {
			$qid = (int) $row->quiz_id;
			if ( isset( $seen[ $qid ] ) ) continue;
			$seen[ $qid ] = true;
			$out[]        = $row;
		}
		return $out;
	}

	/**
	 * Quiz AYS assignés à un enfant ET liés à un cours précis, via la clé
	 * 'rk_course_id' stockée dans les options JSON (voir
	 * class-rk-coach-quizzes-controller.php → create_quiz(), v2.7).
	 *
	 * Filtrage effectué en PHP après find_assigned_for_child() plutôt
	 * qu'en SQL sur le JSON : le volume par enfant reste faible (quelques
	 * quiz), et ça évite une dépendance à JSON_EXTRACT (support MySQL
	 * variable selon l'hébergement).
	 *
	 * @return object[] même forme que find_assigned_for_child().
	 */
	public static function find_assigned_for_child_and_course( int $child_rk_id, int $child_wp_uid, int $course_id ): array {
		if ( ! $course_id ) return [];

		return array_values( array_filter(
			self::find_assigned_for_child( $child_rk_id, $child_wp_uid ),
			static function ( $row ) use ( $course_id ) {
				$opts = json_decode( (string) ( $row->options ?? '{}' ), true ) ?: [];
				return isset( $opts['rk_course_id'] ) && (int) $opts['rk_course_id'] === $course_id;
			}
		) );
	}

	/** Dernière tentative TERMINÉE d'un utilisateur sur un quiz (ou null). */
	public static function latest_report( int $quiz_id, int $wp_user_id ): ?object {
		if ( ! self::is_available() ) return null;

		global $wpdb;
		$rp = $wpdb->prefix . 'aysquiz_reports';

		return $wpdb->get_row( $wpdb->prepare(
			"SELECT id, score, corrects_count, questions_count, end_date
			   FROM {$rp}
			  WHERE quiz_id = %d AND user_id = %d AND end_date IS NOT NULL
			  ORDER BY end_date DESC, id DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL
			$quiz_id, $wp_user_id
		) ) ?: null;
	}

	/** Métadonnées d'un quiz RK (titre, options décodées, child_rk_id, permalink). */
	public static function find_rk_quiz( int $quiz_id ): ?object {
		if ( ! self::is_available() ) return null;

		global $wpdb;
		$qz = $wpdb->prefix . 'aysquiz_quizes';

		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, title, options, quiz_url, custom_post_id FROM {$qz} WHERE id = %d LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL
			$quiz_id
		) );
		if ( ! $row || strpos( (string) $row->quiz_url, 'rk:child:' ) !== 0 ) return null;

		$row->child_rk_id = (int) substr( (string) $row->quiz_url, strlen( 'rk:child:' ) );
		$row->opts        = json_decode( (string) ( $row->options ?? '{}' ), true ) ?: [];
		$row->permalink   = $row->custom_post_id ? (string) ( get_permalink( (int) $row->custom_post_id ) ?: '' ) : '';
		return $row;
	}

	/**
	 * Réattribue à l'enfant les tentatives « orphelines » d'un quiz RK.
	 *
	 * CONTEXTE : l'enfant navigue via le token ?rk_tab (session onglet), mais
	 * AYS soumet le quiz via admin-ajax.php SANS ce token → WordPress
	 * authentifie via le cookie du navigateur → la tentative est enregistrée
	 * sous l'ID du PARENT (ou 0 en anonyme) au lieu de l'enfant.
	 *
	 * Cette méthode réassigne les tentatives terminées récentes du quiz dont
	 * user_id ∈ {0, parent} vers l'enfant. Fenêtre 12 h : couvre la session,
	 * sans risque de capturer le trafic d'un autre foyer (le quiz est déjà
	 * privé : quiz_url = rk:child:{id}, un seul enfant légitime).
	 *
	 * @return int nombre de tentatives réattribuées.
	 */
	public static function claim_orphan_attempts( int $quiz_id, int $child_wp_uid, int $parent_wp_uid, int $window_hours = 12 ): int {
		if ( ! self::is_available() || $quiz_id <= 0 || $child_wp_uid <= 0 ) return 0;

		global $wpdb;
		$rp = $wpdb->prefix . 'aysquiz_reports';

		$wpdb->query( $wpdb->prepare(
			"UPDATE {$rp}
			    SET user_id = %d
			  WHERE quiz_id = %d
			    AND user_id IN (0, %d)
			    AND end_date IS NOT NULL
			    AND end_date >= DATE_SUB(NOW(), INTERVAL %d HOUR)", // phpcs:ignore WordPress.DB.PreparedSQL -- table from $wpdb->prefix
			$child_wp_uid, $quiz_id, $parent_wp_uid, $window_hours
		) );
		return (int) $wpdb->rows_affected;
	}

	/** Fusionne des clés dans le JSON options d'un quiz. */
	public static function patch_options( int $quiz_id, array $patch ): bool {
		if ( ! self::is_available() ) return false;

		global $wpdb;
		$qz   = $wpdb->prefix . 'aysquiz_quizes';
		$opts = json_decode( (string) $wpdb->get_var( $wpdb->prepare(
			"SELECT options FROM {$qz} WHERE id = %d", $quiz_id // phpcs:ignore WordPress.DB.PreparedSQL
		) ), true ) ?: [];

		return false !== $wpdb->update(
			$qz,
			[ 'options' => wp_json_encode( array_merge( $opts, $patch ) ) ],
			[ 'id' => $quiz_id ],
			[ '%s' ], [ '%d' ]
		);
	}

	/**
	 * v9.56 (14/08/2026) — Questions + réponses d'un quiz, dans l'ordre
	 * de wp_aysquiz_quizes.question_ids (PAS une colonne quiz_id sur
	 * wp_aysquiz_questions — cette table n'a pas cette colonne, le lien
	 * se fait exclusivement via cette liste CSV côté quizes). Nécessaire
	 * pour le moteur de quiz natif intégré au dashboard (remplace le
	 * lien externe /ays-quiz-maker/, demande explicite de l'utilisateur
	 * — voir maquette Figma "My Challenges (Challenge)").
	 *
	 * @return array<int,object{id:int,question:string,type:string,answers:object[]}>
	 *         answers = [ { id, answer, correct } ] SANS jamais exposer
	 *         quelle réponse est correcte côté client (voir rk-quiz-play.php
	 *         qui retire ce champ avant tout rendu HTML).
	 */
	public static function find_questions_with_answers( int $quiz_id ): array {
		if ( ! self::is_available() ) return [];

		global $wpdb;
		$qz = $wpdb->prefix . 'aysquiz_quizes';
		$qs = $wpdb->prefix . 'aysquiz_questions';
		$as = $wpdb->prefix . 'aysquiz_answers';

		$question_ids_raw = (string) $wpdb->get_var( $wpdb->prepare(
			"SELECT question_ids FROM {$qz} WHERE id = %d", $quiz_id // phpcs:ignore WordPress.DB.PreparedSQL
		) );
		$question_ids = array_values( array_filter( array_map( 'intval', explode( ',', $question_ids_raw ) ) ) );
		if ( empty( $question_ids ) ) return [];

		$ph = implode( ',', array_fill( 0, count( $question_ids ), '%d' ) );

		$questions = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, question, question_title, type FROM {$qs} WHERE id IN ({$ph})", // phpcs:ignore WordPress.DB.PreparedSQL
			$question_ids
		) );
		// Réordonne selon question_ids (SQL IN() ne garantit pas l'ordre).
		$by_id = [];
		foreach ( $questions as $q ) { $by_id[ (int) $q->id ] = $q; }

		$answers = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, question_id, answer, correct FROM {$as} WHERE question_id IN ({$ph}) ORDER BY question_id, ordering", // phpcs:ignore WordPress.DB.PreparedSQL
			$question_ids
		) );
		$answers_by_question = [];
		foreach ( $answers as $a ) {
			$answers_by_question[ (int) $a->question_id ][] = $a;
		}

		$out = [];
		foreach ( $question_ids as $qid ) {
			if ( ! isset( $by_id[ $qid ] ) ) continue;
			$q = $by_id[ $qid ];
			$q->answers = $answers_by_question[ $qid ] ?? [];
			$out[] = $q;
		}
		return $out;
	}

	/**
	 * v9.56 (14/08/2026) — Écrit une tentative dans wp_aysquiz_reports,
	 * au même format exact que le moteur AYS natif (confirmé par
	 * inspection directe de la table le 13/08/2026 — voir
	 * options.correctness / options.user_answered / calc_method) : ce
	 * moteur de quiz natif ne remplace QUE l'affichage HTML, jamais le
	 * format de stockage, pour rester lisible par tout code existant
	 * (RKP_QuizFlowCommandService::record_ays_result(), rapports coach,
	 * page اختباراتي/تحدياتي).
	 *
	 * @param  array<int,array{question_id:int,answer_id:int,correct:bool}> $answers
	 * @return int  ID du rapport inséré, 0 en cas d'échec.
	 */
	public static function insert_report(
		int $quiz_id,
		int $wp_user_id,
		\DateTimeImmutable $start,
		\DateTimeImmutable $end,
		array $answers
	): int {
		if ( ! self::is_available() || $quiz_id <= 0 || $wp_user_id <= 0 ) return 0;

		global $wpdb;
		$rp = $wpdb->prefix . 'aysquiz_reports';

		$correctness    = [];
		$user_answered  = [];
		$corrects_count = 0;
		foreach ( $answers as $a ) {
			$key                    = 'question_id_' . (int) $a['question_id'];
			$correctness[ $key ]    = (bool) $a['correct'];
			$user_answered[ $key ]  = (string) $a['answer_id'];
			if ( $a['correct'] ) $corrects_count++;
		}
		$questions_count = count( $answers );
		$score           = $questions_count > 0 ? (int) round( ( $corrects_count / $questions_count ) * 100 ) : 0;
		$duration        = max( 0, $end->getTimestamp() - $start->getTimestamp() );

		$options = wp_json_encode( [
			'correctness'             => $correctness,
			'user_answered'           => $user_answered,
			'passed_time'             => human_time_diff( $start->getTimestamp(), $end->getTimestamp() ),
			'calc_method'             => 'by_correctness',
			'attributes_information'  => [],
		] );

		$ok = $wpdb->insert( $rp, [
			'quiz_id'         => $quiz_id,
			'user_id'         => $wp_user_id,
			'user_ip'         => (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			'user_name'       => '',
			'user_email'      => '',
			'user_phone'      => '',
			'start_date'      => $start->format( 'Y-m-d H:i:s' ),
			'end_date'        => $end->format( 'Y-m-d H:i:s' ),
			'duration'        => (string) $duration,
			'score'           => (string) $score,
			'corrects_count'  => (string) $corrects_count,
			'questions_count' => (string) $questions_count,
			'options'         => $options,
			'read'            => 0,
			'user_explanation'=> '',
		], [
			'%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s',
		] );

		return $ok ? (int) $wpdb->insert_id : 0;
	}
}