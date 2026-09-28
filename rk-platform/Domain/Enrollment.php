<?php
declare( strict_types=1 );
/**
 * Domain object — Enrollment
 * Inscription d'un enfant à un cours Tutor LMS.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class RKP_Enrollment {

    public function __construct(
        public readonly int                $child_id,
        public readonly int                $course_id,
        public readonly \DateTimeImmutable $enrolled_at,
        public readonly string             $status,   // 'completed' | 'cancelled'
    ) {}

    public function is_active(): bool {
        return $this->status === 'completed';
    }
}
