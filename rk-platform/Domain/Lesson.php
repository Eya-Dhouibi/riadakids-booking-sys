<?php
declare( strict_types=1 );
/**
 * Domain object — Lesson
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class RKP_Lesson {

    public function __construct(
        public readonly int    $id,
        public readonly int    $topic_id,
        public readonly int    $course_id,
        public readonly string $title,
        public readonly string $type,      // 'text' | 'video' | 'zoom'
        public readonly int    $order,
        public readonly string $permalink,
        public readonly string $thumbnail = '', // v9.15 — "الصورة البارزة" de la leçon (WP featured image natif, confirmé par doc Tutor LMS — même mécanisme que RKP_Course::$thumbnail)
    ) {}
}
