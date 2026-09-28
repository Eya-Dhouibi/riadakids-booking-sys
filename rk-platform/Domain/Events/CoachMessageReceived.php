<?php
declare( strict_types=1 );
/**
 * Domain Event — RKP_CoachMessageReceived
 *
 * Promu depuis le hook WordPress natif `rk_mc_message_sent`, mais
 * UNIQUEMENT dans le sens coach → enfant (le hook se déclenche aussi
 * pour les messages envoyés PAR l'enfant — le filtrage de direction
 * est fait au niveau du pont qui dispatch cet événement, pas ici :
 * cette classe reste un simple porteur de données, aucune logique).
 *
 * @since 9.9.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;

final class RKP_CoachMessageReceived implements RKP_DomainEvent {

    public function __construct(
        public readonly int                $message_id,
        public readonly int                $child_id,
        public readonly int                $coach_wp_uid,
        public readonly string             $excerpt,
        public readonly \DateTimeImmutable $occurred_at = new \DateTimeImmutable(),
    ) {}

    public function get_name(): string { return 'coach.message_received'; }

    public function occurred_at(): \DateTimeImmutable { return $this->occurred_at; }

    public function to_array(): array {
        return [
            'message_id'   => $this->message_id,
            'child_id'     => $this->child_id,
            'coach_wp_uid' => $this->coach_wp_uid,
            'excerpt'      => $this->excerpt,
        ];
    }
}
