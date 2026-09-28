<?php
declare( strict_types=1 );
/**
 * Application — ChildCommandService  (Write Model)
 *
 * Actions déclenchées par l'enfant ou en son nom.
 * Phase 1 : stubs documentés — délégation ajoutée en Phase 2.
 * Phase 2 : émettra des Domain Events via RKP_EventBus.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class RKP_ChildCommandService {

    /**
     * Marquer une leçon comme terminée.
     * Phase 2 : dispatch( new RKP_LessonCompleted(...) )
     */
    public static function complete_lesson( int $child_id, int $lesson_id, int $course_id ): bool {
        // Phase 1 — aucun comportement propre, on laisse Tutor LMS gérer via ses propres hooks.
        return true;
    }

    /**
     * Soumettre une réponse à un quiz.
     * Phase 2 : dispatch( RKP_QuizPassed | RKP_QuizFailed )
     */
    public static function submit_quiz_attempt(
        int $child_id,
        int $quiz_id,
        int $course_id,
        int $score_percent
    ): bool {
        // Phase 1 — stub.
        return true;
    }

    /**
     * Démarrer un cours (première inscription).
     * Phase 2 : dispatch( new RKP_CourseStarted(...) )
     */
    public static function start_course( int $child_id, int $course_id ): bool {
        // Phase 1 — stub.
        return true;
    }
}
