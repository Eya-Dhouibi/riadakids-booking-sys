<?php
declare( strict_types=1 );
/**
 * Interface — DomainEvent
 * Contrat que tout événement métier doit respecter.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

interface RKP_DomainEvent {

    /** Nom lisible de l'événement — ex. 'lesson.completed' */
    public function get_name(): string;

    /** Moment où l'événement s'est produit. */
    public function occurred_at(): \DateTimeImmutable;

    /** Données de l'événement sous forme de tableau (pour logs, webhooks…). */
    public function to_array(): array;
}
