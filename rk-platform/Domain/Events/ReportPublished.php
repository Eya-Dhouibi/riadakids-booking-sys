<?php
declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) exit;

final class RKP_ReportPublished implements RKP_DomainEvent {

    public function __construct(
        public readonly int                $report_id,
        public readonly int                $child_id,
        public readonly int                $course_id,
        public readonly int                $published_by,   // WP user ID du rédacteur
        public readonly \DateTimeImmutable $occurred_at = new \DateTimeImmutable(),
    ) {}

    public function get_name(): string { return 'report.published'; }

    public function occurred_at(): \DateTimeImmutable { return $this->occurred_at; }

    public function to_array(): array {
        return [
            'report_id'    => $this->report_id,
            'child_id'     => $this->child_id,
            'course_id'    => $this->course_id,
            'published_by' => $this->published_by,
        ];
    }
}
