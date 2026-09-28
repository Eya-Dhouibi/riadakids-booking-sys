<?php
declare( strict_types=1 );
/**
 * Infrastructure — Adapters par défaut des Ports  v2.2.0 — Audit P2-12
 * Chaque adapter délègue aux repositories statiques historiques :
 * comportement STRICTEMENT identique, mais les consommateurs peuvent
 * désormais dépendre des interfaces (testables, substituables).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class RKP_TutorLearningAdapter implements RKP_LearningPort {
	public function courses_for_child( int $child_wp_uid ): array {
		return RKP_LearningQueryService::get_courses_for_child( $child_wp_uid );
	}
	public function progress( int $course_id, int $child_wp_uid ): ?RKP_Progress {
		return RKP_LearningQueryService::get_progress( $course_id, $child_wp_uid );
	}
	public function topics( int $course_id ): array {
		return RKP_LearningQueryService::get_topics( $course_id );
	}
	public function last_completed_lesson( int $course_id, int $child_wp_uid ): ?RKP_Lesson {
		return RKP_ProgressRepository::get_last_completed_lesson( $course_id, $child_wp_uid );
	}
	public function next_lesson( int $course_id, int $child_wp_uid ): ?RKP_Lesson {
		return RKP_ProgressRepository::get_next_lesson( $course_id, $child_wp_uid );
	}
}

final class RKP_SsaBookingAdapter implements RKP_BookingPort {
	public function upcoming_for_child( int $child_wp_uid, int $course_id, int $limit = 1 ): array {
		return RKP_BookingRepository::find_upcoming_by_child( $child_wp_uid, $course_id, $limit );
	}
}

final class RKP_BetterMessagesAdapter implements RKP_MessagingPort {
	public function count_sent_between( int $sender_id, string $start, string $end ): int {
		return RKP_MessageRepository::count_sent_between( $sender_id, $start, $end );
	}
	public function is_available(): bool {
		return RKP_MessageRepository::is_available();
	}
}
