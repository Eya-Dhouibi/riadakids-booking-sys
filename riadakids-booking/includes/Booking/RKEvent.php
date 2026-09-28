<?php
/**
 * RKEvent — Modèle représentant un événement SSA sélectionnable.
 *
 * @package RiadaKids\Booking
 */

namespace RiadaKids\Booking;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class RKEvent {

    /** @var int  ID du type de rendez-vous SSA */
    public int $ssa_id;

    /** @var string  Titre affiché à l'utilisateur */
    public string $title;

    /** @var string  Nom du mentor (extrait du titre ou explicite) */
    public string $mentor;

    /** @var bool  L'événement est-il actif ? */
    public bool $active;

    /** @var int  Ordre d'affichage */
    public int $sort_order;

    public function __construct( array $data ) {
        $this->ssa_id     = (int)    ( $data['ssa_id']     ?? $data['appointment_type_id'] ?? 0 );
        $this->title      = (string) ( $data['title']      ?? '' );
        $this->mentor     = (string) ( $data['mentor']     ?? $this->title );
        $this->active     = (bool)   ( $data['active']     ?? true );
        $this->sort_order = (int)    ( $data['sort_order'] ?? 0 );
    }

    /** Sérialisation pour JSON / JS */
    public function to_array(): array {
        return [
            'ssa_id'     => $this->ssa_id,
            'title'      => $this->title,
            'mentor'     => $this->mentor,
            'active'     => $this->active,
            'sort_order' => $this->sort_order,
        ];
    }

    /** Construit une collection depuis le tableau stocké en option WP */
    public static function from_option(): array {
        $raw = get_option( 'rk_ssa_events', [] );
        if ( ! is_array( $raw ) ) {
            return [];
        }
        $events = [];
        foreach ( $raw as $item ) {
            $event = new self( $item );
            if ( $event->ssa_id > 0 ) {
                $events[] = $event;
            }
        }
        // Tri par sort_order
        usort( $events, fn( $a, $b ) => $a->sort_order <=> $b->sort_order );
        return $events;
    }
}