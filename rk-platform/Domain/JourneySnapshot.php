<?php
declare( strict_types=1 );
/**
 * Domain object — JourneySnapshot  ⭐ Contrat central de la plateforme
 *
 * Photographie immutable du parcours pédagogique d'un enfant à un instant donné.
 *
 * Le Dashboard LIT cet objet. Il ne le modifie jamais.
 * Child Dashboard, Coach Dashboard, Parent Dashboard consomment tous
 * le même objet construit par JourneyQueryService::buildSnapshot().
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class RKP_JourneySnapshot {

    public function __construct(
        public readonly int                 $child_id,
        public readonly ?RKP_Course         $course,
        public readonly ?RKP_Topic          $topic,
        public readonly ?RKP_Lesson         $current_lesson,
        public readonly ?RKP_Lesson         $next_lesson,
        public readonly ?RKP_Quiz           $next_quiz,
        public readonly ?RKP_Progress       $progress,
        public readonly ?RKP_Booking        $upcoming_booking,
        public readonly ?int                $coach_id,
        public readonly string              $coach_name,
        public readonly int                 $xp,
        public readonly int                 $level,
        public readonly int                 $streak,
        public readonly array               $achievements,     // RKP_Achievement[] — Phase 2
        public readonly string              $next_action,      // 'attend_session'|'continue_lesson'|'take_quiz'|'book_session'|'idle'
        public readonly \DateTimeImmutable  $updated_at,
        public readonly ?RKP_Course         $next_journey = null, // v2.4 (Audit P5-20) — cours recommandé après complétion
    ) {}

    /** Retourne true si l'enfant est actif dans un cours. */
    public function has_active_course(): bool {
        return $this->course !== null && $this->progress !== null && ! $this->progress->is_complete();
    }

    /** Progression 0-100 ou 0 si aucun cours. */
    public function progress_percent(): int {
        return $this->progress?->percent ?? 0;
    }
}
