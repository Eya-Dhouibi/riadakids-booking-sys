<?php
declare( strict_types=1 );
/**
 * Application/Contracts — Ports (interfaces)  v2.2.0 — Audit P2-12
 *
 * Fondation de l'inversion de dépendances. Les services Application
 * dépendent de CES interfaces, jamais des implémentations concrètes
 * (Tutor, SSA, Better Messages…). Résolution via RKP_Container.
 * Les façades statiques historiques restent fonctionnelles — les adapters
 * par défaut délèguent vers elles (zéro breaking change).
 * Remplacer Tutor LMS demain = enregistrer d'autres implémentations.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/** Port Learning — le SEUL contrat que connaissent Journey & dashboards. */
interface RKP_LearningPort {
	/** @return RKP_Course[] */
	public function courses_for_child( int $child_wp_uid ): array;
	public function progress( int $course_id, int $child_wp_uid ): ?RKP_Progress;
	/** @return RKP_Topic[] */
	public function topics( int $course_id ): array;
	public function last_completed_lesson( int $course_id, int $child_wp_uid ): ?RKP_Lesson;
	public function next_lesson( int $course_id, int $child_wp_uid ): ?RKP_Lesson;
}

/** Port Booking (SSA aujourd'hui, n'importe quoi demain). */
interface RKP_BookingPort {
	/** @return RKP_Booking[] */
	public function upcoming_for_child( int $child_wp_uid, int $course_id, int $limit = 1 ): array;
}

/** Port Messaging (Better Messages aujourd'hui). */
interface RKP_MessagingPort {
	public function count_sent_between( int $sender_id, string $start, string $end ): int;
	public function is_available(): bool;
}

/** Port Wallet — ledger de crédits (v2.2, Audit P2-17). */
interface RKP_WalletPort {
	public function balance( int $user_id ): int;
	/** @param string $idempotency_key clé unique métier — un même fait ne crédite jamais 2×. */
	public function credit( int $user_id, int $amount, string $reason, string $idempotency_key, array $meta = [] ): bool;
	public function debit( int $user_id, int $amount, string $reason, string $idempotency_key, array $meta = [] ): bool;
	/** @return object[] mouvements, plus récents d'abord. */
	public function history( int $user_id, int $limit = 20 ): array;
}
