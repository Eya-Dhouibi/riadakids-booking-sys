<?php
declare( strict_types=1 );
/**
 * Domain object — Quiz
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class RKP_Quiz {

    public function __construct(
        public readonly int    $id,
        public readonly int    $course_id,
        public readonly int    $topic_id,
        public readonly string $title,
        public readonly int    $questions_count,
        public readonly int    $pass_percent,   // 0-100
        public readonly string $permalink,
    ) {}
}
