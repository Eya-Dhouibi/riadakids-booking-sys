<?php
declare( strict_types=1 );
/**
 * Application/Listeners — RKP_NotificationRouter  (v2.8.0)
 *
 * LE point central qui transforme les actions de la plateforme en
 * notifications in-app. Un événement = un endroit où chercher.
 *
 *   ENFANT                          COACH
 *   ─────────────────────────       ─────────────────────────────
 *   📝 quiz assigné (v2.6 ✔)        📅 réservation confirmée
 *   📊 sa propre note de quiz       📝 enfant termine un quiz (✔ existant)
 *   ⭐ points gagnés                 💬 message reçu (✔ via store v2.8)
 *   🚀 passage de niveau            ❓ question posée (Tutor Q&A)
 *   💬 message reçu (✔ via store)
 *
 * Règles :
 *   • jamais d'exception qui remonte (une notification ratée ne casse rien) ;
 *   • pas de doublon : les points issus d'un quiz (source quiz_*) ne sont pas
 *     re-notifiés — la notification de résultat inclut déjà l'XP ;
 *   • tout passe par RK_MC_Notification_Service::push (store unique v2.8,
 *     lu par la cloche enfant ET la cloche coach).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class RKP_NotificationRouter {

	public static function boot(): void {
		// ── ENFANT : sa propre note, dès que le quiz est corrigé ──
		if ( class_exists( 'RKP_EventBus' ) ) {
			RKP_EventBus::subscribe( 'quiz.passed', [ self::class, 'on_quiz_result' ], 20 );
			RKP_EventBus::subscribe( 'quiz.failed', [ self::class, 'on_quiz_result' ], 20 );
		}

		// ── ENFANT : points gagnés & passage de niveau ──
		add_action( 'rk_mc_child_points_added', [ self::class, 'on_points_added' ], 10, 3 );
		add_action( 'rk_mc_child_level_up',     [ self::class, 'on_level_up' ],     10, 2 );

		// ── COACH : réservation confirmée ──
		// CONTRAT EXTERNE : le hook `rk_booking_confirmed` est déclenché par le
		// plugin RiadaKids Booking (webhook SSA de confirmation). Trois autres
		// listeners du présent plugin en dépendent déjà (RK_Event_Bus,
		// RK_MC_Child_User, RKBridge) — si les notifications booking manquent,
		// vérifier que le plugin rk-booking est actif et à jour.
		add_action( 'rk_booking_confirmed',     [ self::class, 'on_booking_confirmed' ], 20, 2 );

		// ── COACH : question posée dans le Q&A Tutor ──
		// (comment_type 'tutor_q_and_a' — indépendant des hooks internes de Tutor)
		add_action( 'comment_post',             [ self::class, 'on_comment_posted' ], 10, 2 );
	}

	/* ── ENFANT ─────────────────────────────────────────────────────── */

	/** Sa propre note, avec encouragement adapté. */
	public static function on_quiz_result( RKP_DomainEvent $event ): void {
		try {
			$child_uid = (int) $event->child_id;
			$score     = (int) $event->score_percent;
			if ( $child_uid <= 0 || ! class_exists( 'RK_MC_Notification_Service' ) ) return;

			$title  = (int) $event->quiz_id > 0 ? ( get_the_title( (int) $event->quiz_id ) ?: 'الاختبار' ) : 'الاختبار';
			$passed = 'quiz.passed' === $event->get_name();

			$msg = $passed
				? sprintf( '🎉 أحسنت! نجحت في اختبار "%s" بنتيجة %d%%', $title, $score )
				: sprintf( '💪 حصلت على %d%% في اختبار "%s" — حاول مرة أخرى، أنت قادر!', $score, $title );

			RK_MC_Notification_Service::push( $child_uid, 'quiz_result_child', $msg, [
				'quiz_id' => (int) $event->quiz_id,
				'score'   => $score,
				'passed'  => $passed,
			] );
		} catch ( \Throwable $e ) {
			rkp_log( '[RKP_NotificationRouter] on_quiz_result: ' . $e->getMessage() );
		}
	}

	/** Points gagnés — sauf sources quiz_* (la notif de résultat inclut l'XP). */
	public static function on_points_added( int $child_rk_id, int $points, string $source ): void {
		try {
			if ( $points <= 0 || 0 === strpos( $source, 'quiz' ) ) return;
			if ( ! class_exists( 'RK_MC_Notification_Service' ) ) return;

			$child_uid = RKP_ChildRepository::get_wp_user_id( $child_rk_id );
			if ( $child_uid <= 0 ) return;

			RK_MC_Notification_Service::push(
				$child_uid,
				'points_earned',
				sprintf( '⭐ ربحت %d نقطة خبرة جديدة! واصل التقدم 🚀', $points ),
				[ 'points' => $points, 'source' => $source ]
			);
		} catch ( \Throwable $e ) {
			rkp_log( '[RKP_NotificationRouter] on_points_added: ' . $e->getMessage() );
		}
	}

	/** Passage de niveau 🚀 */
	public static function on_level_up( int $child_rk_id, int $new_level ): void {
		try {
			if ( ! class_exists( 'RK_MC_Notification_Service' ) ) return;
			$child_uid = RKP_ChildRepository::get_wp_user_id( $child_rk_id );
			if ( $child_uid <= 0 ) return;

			RK_MC_Notification_Service::push(
				$child_uid,
				'level_up',
				sprintf( '🚀 مبروك! وصلت إلى المستوى %d', $new_level ),
				[ 'level' => $new_level ]
			);
		} catch ( \Throwable $e ) {
			rkp_log( '[RKP_NotificationRouter] on_level_up: ' . $e->getMessage() );
		}
	}

	/* ── COACH ──────────────────────────────────────────────────────── */

	/** Réservation confirmée → le coach concerné est prévenu. */
	public static function on_booking_confirmed( int $booking_id, $ctx ): void {
		try {
			if ( ! class_exists( 'RK_MC_Notification_Service' ) ) return;

			$coach_id = class_exists( 'RK_MC_Booking_Bridge' )
				? (int) RK_MC_Booking_Bridge::resolve_coach_from_ssa_appointment( $booking_id )
				: 0;
			if ( $coach_id <= 0 ) return;

			$child_name = '';
			$child_id   = is_array( $ctx ) ? (int) ( $ctx['child_id'] ?? 0 ) : (int) ( $ctx->child_id ?? 0 );
			if ( $child_id > 0 && class_exists( 'RK_MC_Child_Repository' ) ) {
				$child      = RK_MC_Child_Repository::find( $child_id );
				$child_name = (string) ( $child->name ?? '' );
			}

			RK_MC_Notification_Service::push(
				$coach_id,
				'booking_new_coach',
				$child_name
					? sprintf( '📅 حجز جديد: جلسة مع %s', $child_name )
					: '📅 حجز جديد بانتظارك في جدولك',
				[ 'booking_id' => $booking_id, 'child_id' => $child_id ]
			);
		} catch ( \Throwable $e ) {
			rkp_log( '[RKP_NotificationRouter] on_booking_confirmed: ' . $e->getMessage() );
		}
	}

	/** Question posée dans le Q&A Tutor → l'instructeur (coach) du cours. */
	public static function on_comment_posted( int $comment_id, $approved ): void {
		try {
			$comment = get_comment( $comment_id );
			if ( ! $comment || 'tutor_q_and_a' !== (string) $comment->comment_type ) return;
			if ( (int) $comment->comment_parent > 0 ) return; // réponse, pas question
			if ( ! class_exists( 'RK_MC_Notification_Service' ) ) return;

			$course_id = (int) $comment->comment_post_ID;
			$coach_id  = (int) get_post_field( 'post_author', $course_id );
			$asker_id  = (int) $comment->user_id;
			if ( $coach_id <= 0 || $coach_id === $asker_id ) return;

			$asker      = get_userdata( $asker_id );
			$asker_name = $asker ? $asker->display_name : 'أحد الطلاب';

			RK_MC_Notification_Service::push(
				$coach_id,
				'question_asked',
				sprintf( '❓ سؤال جديد من %s في "%s": %s',
					$asker_name,
					get_the_title( $course_id ) ?: 'الدورة',
					wp_trim_words( (string) $comment->comment_content, 10 )
				),
				[ 'course_id' => $course_id, 'comment_id' => $comment_id, 'asker_id' => $asker_id ]
			);
		} catch ( \Throwable $e ) {
			rkp_log( '[RKP_NotificationRouter] on_comment_posted: ' . $e->getMessage() );
		}
	}
}
