<?php
declare( strict_types=1 );
/**
 * Domain object — Course
 * Représente un cours Tutor LMS sans aucune dépendance WordPress.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class RKP_Course {

    public function __construct(
        public readonly int    $id,
        public readonly string $title,
        public readonly string $category,
        public readonly int    $instructor_id,
        public readonly string $instructor_name,
        public readonly int    $topics_count,
        public readonly int    $lessons_total,
        public readonly int    $quizzes_count,
        public readonly string $status,        // 'publish' | 'draft' | 'private'
        public readonly string $permalink,
        public readonly string $thumbnail,
    ) {}
}
