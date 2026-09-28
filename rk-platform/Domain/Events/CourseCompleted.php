<?php
declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) exit;

final class RKP_CourseCompleted implements RKP_DomainEvent {

    public function __construct(
        public readonly int                $child_id,
        public readonly int                $course_id,
        public readonly \DateTimeImmutable $occurred_at = new \DateTimeImmutable(),
    ) {}

    public function get_name(): string { return 'course.completed'; }

    public function occurred_at(): \DateTimeImmutable { return $this->occurred_at; }

    public function to_array(): array {
        return [
            'child_id'  => $this->child_id,
            'course_id' => $this->course_id,
        ];
    }
}
