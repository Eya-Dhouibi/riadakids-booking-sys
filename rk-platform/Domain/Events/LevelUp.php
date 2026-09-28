<?php
declare( strict_types=1 );
/**
 * Domain Event — RKP_LevelUp
 *
 * Promu depuis le hook WordPress natif `rk_mc_child_level_up` (déjà
 * déclenché en production, voir class-rk-mc-gamification-service.php
 * et Application/Command/GamificationCommandService.php) — relayé ici
 * vers RKP_EventBus pour respecter l'architecture Domain Event
 * uniforme, sans dupliquer la logique de calcul de niveau elle-même.
 *
 * @since 9.9.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;

final class RKP_LevelUp implements RKP_DomainEvent {

    public function __construct(
        public readonly int                $child_id,
        public readonly int                $new_level,
        public readonly int                $old_level,
        public readonly string             $new_level_label,
        public readonly \DateTimeImmutable $occurred_at = new \DateTimeImmutable(),
    ) {}

    public function get_name(): string { return 'child.level_up'; }

    public function occurred_at(): \DateTimeImmutable { return $this->occurred_at; }

    public function to_array(): array {
        return [
            'child_id'        => $this->child_id,
            'new_level'       => $this->new_level,
            'old_level'       => $this->old_level,
            'new_level_label' => $this->new_level_label,
        ];
    }
}
