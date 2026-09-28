<?php
declare( strict_types=1 );
/**
 * RK_MC_Tutor_QnA — Intégration du Q&A NATIF Tutor LMS dans le dashboard enfant.
 * (v3.1.0 — nouveau)
 *
 * Tutor LMS stocke son Q&A dans wp_comments avec comment_type = 'tutor_q_and_a' :
 *   • question : comment_parent = 0, comment_post_ID = course_id
 *   • réponse  : comment_parent = question_id
 *
 * Ce service :
 *   1. LIT les questions/réponses de l'enfant (lecture tolérante aux versions :
 *      tutor_utils() si dispo, sinon requête comments directe).
 *   2. PERMET à l'enfant de poser une question sur un de SES cours
 *      (AJAX + nonce + vérification d'inscription au cours).
 *   3. NOTIFIE via la cloche RK :
 *        - le COACH quand l'enfant pose une question
 *          (y compris si la question est posée via l'UI native Tutor),
 *        - l'ENFANT + le PARENT quand le coach répond.
 *
 * Routage coach : RKP_FamilyLinkQueryService (coach principal) si présent,
 * sinon fallback booking-bridge.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class RK_MC_Tutor_QnA {

	const COMMENT_TYPE = 'tutor_q_and_a';
	const AJAX_ASK     = 'rk_child_qna_ask';
	const NONCE        = 'rk_child_qna';

	public static function init(): void {
		// Enfant pose une question depuis NOTRE formulaire
		add_action( 'wp_ajax_' . self::AJAX_ASK, [ __CLASS__, 'handle_ask' ] );

		// Question posée via l'UI NATIVE Tutor (page de cours) → même notification coach
		add_action( 'tutor_after_asked_question', [ __CLASS__, 'on_native_question_asked' ], 10, 1 );

		// Réponse de l'instructeur (UI native Tutor) → notifier enfant + parent
		add_action( 'tutor_after_answer_to_question', [ __CLASS__, 'on_native_answer' ], 10, 1 );
	}

	/* ═════════════════════════════════════════════════════════════
	   LECTURE — questions de l'enfant (+ réponses)
	   ═════════════════════════════════════════════════════════════ */

	/**
	 * Questions posées par l'enfant, réponses imbriquées, plus récentes d'abord.
	 *
	 * @return array<int,object{id:int,course_id:int,course_title:string,
	 *               question:string,date:string,answers:array}>
	 */
	public static function get_questions_for_child( int $child_wp_uid, int $limit = 30 ): array {
		if ( $child_wp_uid <= 0 ) return [];

		$questions = get_comments( [
			'type'    => self::COMMENT_TYPE,
			'user_id' => $child_wp_uid,
			'parent'  => 0,
			'status'  => 'all',      // Tutor approuve par défaut ; 'all' couvre les variantes
			'number'  => $limit,
			'orderby' => 'comment_date_gmt',
			'order'   => 'DESC',
		] );
		if ( empty( $questions ) ) return [];

		// Toutes les réponses en UNE requête (pas de N+1)
		$q_ids   = array_map( static fn( $c ) => (int) $c->comment_ID, $questions );
		$answers = get_comments( [
			'type'       => self::COMMENT_TYPE,
			'parent__in' => $q_ids,
			'status'     => 'all',
			'orderby'    => 'comment_date_gmt',
			'order'      => 'ASC',
		] );
		$by_parent = [];
		foreach ( $answers as $a ) {
			$by_parent[ (int) $a->comment_parent ][] = (object) [
				'id'          => (int) $a->comment_ID,
				'author_id'   => (int) $a->user_id,
				'author_name' => $a->comment_author ?: get_the_author_meta( 'display_name', (int) $a->user_id ),
				'body'        => $a->comment_content,
				'date'        => $a->comment_date,
			];
		}

		$out = [];
		foreach ( $questions as $q ) {
			$course_id = (int) $q->comment_post_ID;
			$out[] = (object) [
				'id'           => (int) $q->comment_ID,
				'course_id'    => $course_id,
				'course_title' => get_the_title( $course_id ) ?: '—',
				'question'     => $q->comment_content,
				'date'         => $q->comment_date,
				'answers'      => $by_parent[ (int) $q->comment_ID ] ?? [],
			];
		}
		return $out;
	}

	/** Nombre de questions de l'enfant encore sans réponse (badge d'onglet). */
	public static function count_unanswered( int $child_wp_uid ): int {
		$qs = self::get_questions_for_child( $child_wp_uid, 100 );
		return count( array_filter( $qs, static fn( $q ) => empty( $q->answers ) ) );
	}

	/* ═════════════════════════════════════════════════════════════
	   ÉCRITURE — l'enfant pose une question (notre AJAX)
	   ═════════════════════════════════════════════════════════════ */

	public static function handle_ask(): void {
		check_ajax_referer( self::NONCE, 'nonce' );

		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			wp_send_json_error( [ 'message' => __( 'يجب تسجيل الدخول.', 'rk-platform' ) ], 401 );
		}
		// Réservé au rôle enfant OU au parent en sub-session enfant.
		if ( class_exists( 'RK_MC_Child_Restrictions' ) && ! RK_MC_Child_Restrictions::is_child_user() ) {
			// Parent en session onglet : le contexte enfant résolu par le dashboard prime.
			$ctx_child = class_exists( 'RK_MC_Tutor_Dashboard' )
				? RK_MC_Tutor_Dashboard::get_child_for_template() : null;
			if ( $ctx_child && (int) ( $ctx_child->wp_user_id ?? 0 ) > 0 ) {
				$user_id = (int) $ctx_child->wp_user_id;
			} else {
				wp_send_json_error( [ 'message' => __( 'غير مسموح.', 'rk-platform' ) ], 403 );
			}
		}

		$course_id = absint( $_POST['course_id'] ?? 0 );
		$question  = trim( wp_kses( wp_unslash( (string) ( $_POST['question'] ?? '' ) ), [] ) );

		if ( $course_id <= 0 || '' === $question ) {
			wp_send_json_error( [ 'message' => __( 'يرجى اختيار الدورة وكتابة سؤالك.', 'rk-platform' ) ], 422 );
		}
		if ( mb_strlen( $question ) > 2000 ) {
			wp_send_json_error( [ 'message' => __( 'السؤال طويل جداً (2000 حرف كحد أقصى).', 'rk-platform' ) ], 422 );
		}

		// L'enfant ne peut poser une question QUE sur un cours lié à lui :
		// inscription Tutor OU booking confirmé (mode transition) — v3.1.1.
		$enrolled = self::child_can_ask_on_course( $user_id, $course_id );
		if ( ! $enrolled ) {
			wp_send_json_error( [ 'message' => __( 'أنت غير مسجل في هذه الدورة.', 'rk-platform' ) ], 403 );
		}

		// Anti-spam doux : 1 question / 30 s / enfant.
		$rl_key = 'rk_qna_rl_' . $user_id;
		if ( get_transient( $rl_key ) ) {
			wp_send_json_error( [ 'message' => __( 'انتظر قليلاً قبل إرسال سؤال آخر.', 'rk-platform' ) ], 429 );
		}
		set_transient( $rl_key, 1, 30 );

		$user = get_userdata( $user_id );
		$qid  = wp_insert_comment( [
			'comment_post_ID'      => $course_id,
			'comment_author'       => $user ? $user->display_name : '',
			'comment_author_email' => $user ? $user->user_email : '',
			'comment_content'      => $question,
			'comment_type'         => self::COMMENT_TYPE,
			'comment_parent'       => 0,
			'user_id'              => $user_id,
			'comment_approved'     => 1,
		] );

		if ( ! $qid ) {
			wp_send_json_error( [ 'message' => __( 'تعذر حفظ السؤال، حاول مجدداً.', 'rk-platform' ) ], 500 );
		}

		self::notify_coach_new_question( $user_id, $course_id, $question );

		wp_send_json_success( [
			'message' => __( 'تم إرسال سؤالك إلى المدرب 🎉', 'rk-platform' ),
			'id'      => (int) $qid,
		] );
	}

	/* ═════════════════════════════════════════════════════════════
	   HOOKS NATIFS TUTOR — mêmes notifications, autre point d'entrée
	   ═════════════════════════════════════════════════════════════ */

	/** Tutor : tutor_after_asked_question( array $data ) — question via UI native. */
	public static function on_native_question_asked( $data ): void {
		$data      = (array) $data;
		$child_uid = (int) ( $data['user_id']  ?? 0 );
		$course_id = (int) ( $data['comment_post_ID'] ?? $data['course_id'] ?? 0 );
		$question  = (string) ( $data['comment_content'] ?? $data['question'] ?? '' );
		if ( ! $child_uid || ! $course_id ) return;

		// Ne router vers la cloche RK que si l'auteur est un enfant plateforme.
		if ( ! self::is_platform_child( $child_uid ) ) return;

		self::notify_coach_new_question( $child_uid, $course_id, $question );
	}

	/** Tutor : tutor_after_answer_to_question( int $answer_comment_id ). */
	public static function on_native_answer( $answer_id ): void {
		$answer = get_comment( (int) $answer_id );
		if ( ! $answer || self::COMMENT_TYPE !== $answer->comment_type || ! $answer->comment_parent ) return;

		$question = get_comment( (int) $answer->comment_parent );
		if ( ! $question ) return;

		$child_uid = (int) $question->user_id;
		if ( ! self::is_platform_child( $child_uid ) ) return;

		// Éviter de notifier l'enfant de sa propre relance.
		if ( (int) $answer->user_id === $child_uid ) return;

		$course_id    = (int) $question->comment_post_ID;
		$course_title = get_the_title( $course_id ) ?: '—';
		$link         = home_url( ( defined( 'RK_TUTOR_DASHBOARD_URL' ) ? RK_TUTOR_DASHBOARD_URL : '/dashboard/' ) . 'question-answer/' );

		if ( class_exists( 'RK_MC_Notification_Service' ) ) {
			// Enfant
			RK_MC_Notification_Service::push(
				$child_uid,
				'qna_answered',
				sprintf( 'أجاب مدربك على سؤالك في "%s" 💬', $course_title ),
				[ 'course_id' => $course_id, 'question_id' => (int) $question->comment_ID, 'link' => $link ]
			);
			// Parent (suivi)
			$fam = self::family( $child_uid );
			if ( $fam['parent_id'] > 0 ) {
				RK_MC_Notification_Service::push(
					$fam['parent_id'],
					'qna_answered',
					sprintf( 'أجاب المدرب على سؤال %s في "%s"', $fam['child_name'], $course_title ),
					[ 'course_id' => $course_id, 'child_wp_uid' => $child_uid ]
				);
			}
		}
	}

	/* ═════════════════════════════════════════════════════════════
	   Interne
	   ═════════════════════════════════════════════════════════════ */

	private static function notify_coach_new_question( int $child_uid, int $course_id, string $question ): void {
		if ( ! class_exists( 'RK_MC_Notification_Service' ) ) return;

		$fam      = self::family( $child_uid );
		$coach_id = $fam['coach_id'];

		// Fallback : instructeur Tutor du cours si aucun coach plateforme.
		if ( $coach_id <= 0 && function_exists( 'tutor_utils' ) ) {
			$instructors = (array) tutor_utils()->get_instructors_by_course( $course_id );
			$coach_id    = ! empty( $instructors ) ? (int) ( $instructors[0]->ID ?? 0 ) : 0;
		}
		if ( $coach_id <= 0 ) return;

		$course_title = get_the_title( $course_id ) ?: '—';
		$excerpt      = mb_strimwidth( wp_strip_all_tags( $question ), 0, 90, '…' );
		$qna_url      = admin_url( 'admin.php?page=question_answer' ); // page Q&A instructeur Tutor
		if ( function_exists( 'tutor_utils' ) ) {
			$qna_url = tutor_utils()->tutor_dashboard_url( 'question-answer' );
		}

		RK_MC_Notification_Service::push(
			$coach_id,
			'qna_new_question',
			sprintf( 'سؤال جديد من %s في "%s": %s', $fam['child_name'] ?: 'طالب', $course_title, $excerpt ),
			[ 'course_id' => $course_id, 'child_wp_uid' => $child_uid, 'link' => esc_url_raw( $qna_url ) ]
		);
	}

	/**
	 * L'enfant est-il légitime pour poser une question sur ce cours ?
	 * Vrai si : inscription Tutor, OU booking confirmé/reprogrammé sur ce
	 * cours, OU cours publié quand l'enfant n'a AUCUNE inscription résolue
	 * (filet aligné sur le sélecteur du formulaire).
	 */
	private static function child_can_ask_on_course( int $child_uid, int $course_id ): bool {
		// 1. Inscription Tutor
		if ( class_exists( 'RKP_TutorEnvironment' )
		     && in_array( $course_id, array_map( 'intval', (array) RKP_TutorEnvironment::enrolled_course_ids( $child_uid ) ), true ) ) {
			return true;
		}
		if ( function_exists( 'tutor_utils' ) && tutor_utils()->is_enrolled( $course_id, $child_uid ) ) {
			return true;
		}
		// 2. Booking confirmé sur ce cours
		global $wpdb;
		$bt = $wpdb->prefix . 'rk_bookings';
		$ct = $wpdb->prefix . 'rk_children';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $bt ) ) === $bt ) {
			$has = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$bt} b JOIN {$ct} c ON c.id = b.child_id
				  WHERE c.wp_user_id = %d AND b.course_id = %d
				    AND b.status IN ('confirmed','rescheduled')",
				$child_uid, $course_id
			) );
			if ( $has > 0 ) return true;
			// 3. Filet : l'enfant n'a AUCUN cours lié → autoriser tout cours publié
			$any = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$bt} b JOIN {$ct} c ON c.id = b.child_id
				  WHERE c.wp_user_id = %d AND b.course_id > 0
				    AND b.status IN ('confirmed','rescheduled')",
				$child_uid
			) );
			if ( 0 === $any && 'publish' === get_post_status( $course_id )
			     && 'courses' === get_post_type( $course_id ) ) {
				return true;
			}
		}
		return false;
	}

	private static function is_platform_child( int $wp_uid ): bool {
		$u = get_userdata( $wp_uid );
		return $u && in_array( 'rk_child', (array) $u->roles, true );
	}

	private static function family( int $child_uid ): array {
		if ( class_exists( 'RKP_FamilyLinkQueryService' ) ) {
			return RKP_FamilyLinkQueryService::context_by_wp_uid( $child_uid );
		}
		// Fallback legacy
		$name   = get_userdata( $child_uid )->first_name ?? '';
		$coach  = 0;
		$parent = 0;
		if ( class_exists( 'RK_MC_Booking_Bridge' )
		     && method_exists( 'RK_MC_Booking_Bridge', 'get_coach_for_child' ) ) {
			global $wpdb;
			$ct    = $wpdb->prefix . 'rk_children';
			$row   = $wpdb->get_row( $wpdb->prepare(
				"SELECT id, user_id, child_name FROM {$ct} WHERE wp_user_id = %d LIMIT 1", $child_uid // phpcs:ignore WordPress.DB.PreparedSQL
			) );
			if ( $row ) {
				$coach  = (int) RK_MC_Booking_Bridge::get_coach_for_child( (int) $row->id );
				$parent = (int) $row->user_id;
				$name   = (string) $row->child_name;
			}
		}
		return [ 'coach_id' => $coach, 'parent_id' => $parent, 'child_name' => $name, 'child_rk_id' => 0, 'child_wp_uid' => $child_uid ];
	}
}
