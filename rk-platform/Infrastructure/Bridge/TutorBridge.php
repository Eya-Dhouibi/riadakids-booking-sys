<?php
declare( strict_types=1 );
/**
 * Infrastructure — TutorBridge  (v3.0.0 — Audit fonctionnel : fidélité Tutor LMS)
 *
 * Écoute les hooks WP de Tutor LMS et les retransmet sous forme
 * de Domain Events via RKP_EventBus.
 *
 * v3.0.0 :
 *   • NOUVEAU  tutor_after_enrolled → course.started
 *     (une inscription faite directement dans Tutor — admin, WooCommerce,
 *      import — est désormais visible par la plateforme, pas seulement
 *      celles créées par le booking bridge).
 *   • NOUVEAU  tutor_course_progress_reset / retake → progress.updated (percent recalculé)
 *     et invalidation des snapshots — plus de progression fantôme après un reset.
 *   • FIX      seuil de passage quiz : Tutor stocke les options dans la meta
 *     'tutor_quiz_option' (tableau), PAS dans '_tutor_quiz_passing_grade'.
 *     L'ancienne lecture retombait silencieusement sur 80 pour tous les quiz.
 *   • NOUVEAU  chaque événement pédagogique déclenche la mise à jour du
 *     snapshot de progression persistant (RKP_ProgressSyncListener).
 *
 * Priorité 50 sur tous les hooks : s'exécute APRÈS les handlers
 * existants (Gamification@10, RK_Event_Bus@20, BookingBridge@20).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class RKP_TutorBridge {

    public static function init(): void {
        add_action( 'tutor_lesson_completed_after', [ self::class, 'on_lesson_completed' ], 50, 2 );
        add_action( 'tutor_quiz_attempt_ended',     [ self::class, 'on_quiz_attempt_ended' ], 50, 1 );
        add_action( 'tutor_course_complete_after',  [ self::class, 'on_course_completed' ], 50, 2 );

        /* v3.0 — inscription faite côté Tutor (admin, Woo, import) */
        add_action( 'tutor_after_enrolled',         [ self::class, 'on_enrolled' ], 50, 3 );

        /* v3.0 — reset / reprise de cours : la progression Tutor redescend,
         * la plateforme doit suivre (snapshots, caches, vues parent/coach). */
        add_action( 'tutor_course_progress_reset_after', [ self::class, 'on_progress_reset' ], 50, 2 );
        add_action( 'tutor_course_retake_before_start',  [ self::class, 'on_progress_reset' ], 50, 2 );
    }

    /* ─────────────────────────────────────────────────────────────
     * Leçon complétée
     * ───────────────────────────────────────────────────────────── */

    public static function on_lesson_completed( $lesson_id, $user_id = 0 ): void {
        $lesson_id = (int) $lesson_id;
        // Certaines versions de Tutor n'envoient qu'un argument.
        $user_id   = (int) ( $user_id ?: get_current_user_id() );
        if ( ! $lesson_id || ! $user_id ) return;

        $course_id = self::resolve_course_id( $lesson_id );

        RKP_EventBus::dispatch( new RKP_LessonCompleted( $user_id, $lesson_id, $course_id ) );
    }

    /* ─────────────────────────────────────────────────────────────
     * Tentative de quiz terminée
     * ───────────────────────────────────────────────────────────── */

    public static function on_quiz_attempt_ended( $attempt ): void {
        // Tutor peut passer l'objet attempt OU l'attempt_id selon la version.
        if ( is_numeric( $attempt ) && function_exists( 'tutor_utils' ) ) {
            $attempt = tutor_utils()->get_attempt( (int) $attempt );
        }
        if ( ! is_object( $attempt ) ) return;

        $user_id = (int) ( $attempt->user_id ?? 0 );
        $quiz_id = (int) ( $attempt->quiz_id ?? 0 );
        if ( ! $user_id || ! $quiz_id ) return;

        $score = (float) ( $attempt->earned_marks ?? 0 );
        $total = max( 1.0, (float) ( $attempt->total_marks ?? 1 ) );
        $pct   = (int) round( $score / $total * 100 );

        $course_id = self::resolve_course_id( $quiz_id );
        $pass_pct  = self::quiz_passing_grade( $quiz_id );

        if ( $pct >= $pass_pct ) {
            RKP_EventBus::dispatch( new RKP_QuizPassed( $user_id, $quiz_id, $course_id, $pct ) );
        } else {
            RKP_EventBus::dispatch( new RKP_QuizFailed( $user_id, $quiz_id, $course_id, $pct ) );
        }
    }

    /* ─────────────────────────────────────────────────────────────
     * Cours complété / démarré / réinitialisé
     * ───────────────────────────────────────────────────────────── */

    public static function on_course_completed( $course_id, $user_id ): void {
        $course_id = (int) $course_id;
        $user_id   = (int) $user_id;
        if ( ! $course_id || ! $user_id ) return;
        RKP_EventBus::dispatch( new RKP_CourseCompleted( $user_id, $course_id ) );
    }

    /**
     * tutor_after_enrolled( $course_id, $user_id, $enrolled_id )
     * → course.started, quel que soit le canal d'inscription.
     * Idempotent en aval : l'EventLog (event_id, subscriber) empêche
     * tout double effet si le hook rejoue.
     */
    public static function on_enrolled( $course_id, $user_id, $enrolled_id = 0 ): void {
        $course_id = (int) $course_id;
        $user_id   = (int) $user_id;
        if ( ! $course_id || ! $user_id ) return;
        RKP_EventBus::dispatch( new RKP_CourseStarted( $user_id, $course_id ) );
    }

    /**
     * Reset / retake : recalcul immédiat du pourcentage réel Tutor
     * et propagation (snapshot, caches, vues).
     */
    public static function on_progress_reset( $course_id, $user_id ): void {
        $course_id = (int) $course_id;
        $user_id   = (int) $user_id;
        if ( ! $course_id || ! $user_id ) return;

        if ( class_exists( 'RKP_ProgressSyncListener' ) ) {
            RKP_ProgressSyncListener::sync( $user_id, $course_id, 'reset' );
        }
        do_action( 'rkp_course_progress_reset', $user_id, $course_id );
    }

    /* ─────────────────────────────────────────────────────────────
     * Helpers
     * ───────────────────────────────────────────────────────────── */

    /** lesson/quiz → topic → course. Tolère un contenu attaché directement au cours. */
    private static function resolve_course_id( int $content_id ): int {
        // Voie native Tutor si disponible (gère tous les cas de structure).
        if ( function_exists( 'tutor_utils' )
             && method_exists( tutor_utils(), 'get_course_id_by_content' ) ) {
            $cid = (int) tutor_utils()->get_course_id_by_content( $content_id );
            if ( $cid > 0 ) return $cid;
        }
        $parent = (int) get_post_field( 'post_parent', $content_id );
        if ( $parent <= 0 ) return 0;
        // parent = topic ? remonter d'un cran. parent = course ? le garder.
        return get_post_type( $parent ) === 'courses'
            ? $parent
            : (int) get_post_field( 'post_parent', $parent );
    }

    /**
     * Seuil de passage réel d'un quiz Tutor.
     * Tutor LMS stocke les réglages dans la meta 'tutor_quiz_option'
     * (tableau sérialisé, clé 'passing_grade'). Défaut Tutor : 80.
     */
    public static function quiz_passing_grade( int $quiz_id ): int {
        $opts = get_post_meta( $quiz_id, 'tutor_quiz_option', true );
        if ( is_array( $opts ) && isset( $opts['passing_grade'] ) ) {
            $g = (int) $opts['passing_grade'];
            if ( $g >= 0 && $g <= 100 ) return $g;
        }
        // Compat : anciennes installations / meta custom éventuelle.
        $legacy = (int) get_post_meta( $quiz_id, '_tutor_quiz_passing_grade', true );
        return ( $legacy > 0 && $legacy <= 100 ) ? $legacy : 80;
    }
}
