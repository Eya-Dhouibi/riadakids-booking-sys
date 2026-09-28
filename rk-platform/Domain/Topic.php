<?php
declare( strict_types=1 );
/**
 * Domain object — Topic (module / chapitre d'un cours Tutor LMS)
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class RKP_Topic {

    public function __construct(
        public readonly int    $id,
        public readonly int    $course_id,
        public readonly string $title,
        public readonly int    $order,
        public readonly int    $lessons_count,
    ) {}
}
