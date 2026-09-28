<?php
declare( strict_types=1 );
/**
 * Domain Event — QuizAssigned  (v2.6.0 — Quiz Flow)
 * Un coach a assigné un quiz à un enfant.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class RKP_QuizAssigned implements RKP_DomainEvent {

	public function __construct(
		public readonly int                $child_id,      // wp_user_id de l'enfant
		public readonly int                $quiz_id,       // id AYS
		public readonly int                $coach_id,
		public readonly string             $quiz_title,
		public readonly string             $due_date,      // 'Y-m-d' ou ''
		public readonly \DateTimeImmutable $occurred_at = new \DateTimeImmutable(),
	) {}

	public function get_name(): string { return 'quiz.assigned'; }

	public function occurred_at(): \DateTimeImmutable { return $this->occurred_at; }

	public function to_array(): array {
		return [
			'child_id'    => $this->child_id,
			'quiz_id'     => $this->quiz_id,
			'coach_id'    => $this->coach_id,
			'quiz_title'  => $this->quiz_title,
			'due_date'    => $this->due_date,
			'occurred_at' => $this->occurred_at->format( DATE_ATOM ),
		];
	}

	public static function from_array( array $d ): self {
		return new self(
			(int) ( $d['child_id'] ?? 0 ),
			(int) ( $d['quiz_id'] ?? 0 ),
			(int) ( $d['coach_id'] ?? 0 ),
			(string) ( $d['quiz_title'] ?? '' ),
			(string) ( $d['due_date'] ?? '' ),
			new \DateTimeImmutable( (string) ( $d['occurred_at'] ?? 'now' ) )
		);
	}
}
