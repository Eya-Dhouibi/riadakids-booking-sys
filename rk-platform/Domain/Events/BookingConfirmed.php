<?php
declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) exit;

final class RKP_BookingConfirmed implements RKP_DomainEvent {

    public function __construct(
        public readonly int                $booking_id,
        public readonly int                $child_id,
        public readonly int                $coach_id,
        public readonly int                $course_id,
        public readonly \DateTimeImmutable $session_at,
        public readonly \DateTimeImmutable $occurred_at = new \DateTimeImmutable(),
    ) {}

    public function get_name(): string { return 'booking.confirmed'; }

    public function occurred_at(): \DateTimeImmutable { return $this->occurred_at; }

    public function to_array(): array {
        return [
            'booking_id' => $this->booking_id,
            'child_id'   => $this->child_id,
            'coach_id'   => $this->coach_id,
            'course_id'  => $this->course_id,
            'session_at' => $this->session_at->format( \DateTime::ATOM ),
        ];
    }
}
