<?php
declare( strict_types=1 );
/**
 * Domain object — Progress
 * Progression d'un enfant dans un cours.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class RKP_Progress {

    public function __construct(
        public readonly int $child_id,
        public readonly int $course_id,
        public readonly int $percent,         // 0-100
        public readonly int $lessons_done,
        public readonly int $lessons_total,
        public readonly int $quizzes_done,
        public readonly int $quizzes_total,
    ) {
        if ( $this->percent < 0 || $this->percent > 100 ) {
            throw new \InvalidArgumentException( "percent must be 0-100, got {$this->percent}" );
        }
    }

    public function is_complete(): bool {
        return $this->percent >= 100;
    }
}
