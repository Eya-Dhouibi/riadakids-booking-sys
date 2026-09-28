<?php
declare( strict_types=1 );
/**
 * Application — CoachCommandService  (Write Model)
 *
 * Actions réservées au coach.
 * Phase 1 : stubs documentés.
 * Phase 2 : émettra Domain Events (ReportPublished, BadgeEarned…) via RKP_EventBus.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class RKP_CoachCommandService {

    /**
     * Publier un rapport pédagogique.
     * Phase 2 : dispatch( new RKP_ReportPublished(...) )
     */
    public static function publish_report(
        int $report_id,
        int $child_id,
        int $course_id,
        int $coach_id
    ): bool {
        // Phase 1 — stub.
        return true;
    }

    /**
     * Attribuer un badge à un enfant.
     * Phase 2 : dispatch( new RKP_BadgeEarned(...) )
     */
    public static function award_badge( int $child_id, string $badge_key, int $coach_id ): bool {
        // Phase 1 — stub.
        return true;
    }

    /**
     * Assigner une mission / devoir à un enfant.
     * Phase 2 : intégration Tutor LMS assignments.
     */
    public static function assign_mission(
        int $child_id,
        int $course_id,
        int $coach_id,
        string $title,
        string $description
    ): bool {
        // Phase 1 — stub.
        return true;
    }

    /**
     * Corriger manuellement un quiz (open-ended).
     * Phase 2 : dispatch( RKP_QuizPassed | RKP_QuizFailed ) selon score.
     */
    public static function grade_quiz_attempt(
        int $attempt_id,
        int $coach_id,
        int $score_percent
    ): bool {
        // Phase 1 — stub.
        return true;
    }
}
