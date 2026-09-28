<?php
declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) exit;

final class RKP_QuizPassed implements RKP_DomainEvent {

    public function __construct(
        public readonly int                $child_id,
        public readonly int                $quiz_id,
        public readonly int                $course_id,
        public readonly int                $score_percent,
        public readonly \DateTimeImmutable $occurred_at = new \DateTimeImmutable(),
    ) {}

    public function get_name(): string { return 'quiz.passed'; }

    public function occurred_at(): \DateTimeImmutable { return $this->occurred_at; }

    public function to_array(): array {
        return [
            'child_id'      => $this->child_id,
            'quiz_id'       => $this->quiz_id,
            'course_id'     => $this->course_id,
            'score_percent' => $this->score_percent,
        ];
    }
}
