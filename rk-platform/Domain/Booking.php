<?php
declare( strict_types=1 );
/**
 * Domain object — Booking
 * Séance planifiée (table wp_rk_bookings).
 * Rôle : planning uniquement — aucune logique pédagogique.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class RKP_Booking {

    public function __construct(
        public readonly int                $id,
        public readonly int                $booking_id,   // ID du booking côté plugin SSA — requis pour rk_mc_appt_format() (conversion de fuseau horaire), distinct de $id (clé primaire wp_rk_bookings).
        public readonly int                $child_id,
        public readonly int                $coach_id,
        public readonly int                $course_id,
        public readonly string             $session_name,
        public readonly \DateTimeImmutable $start_at,
        public readonly string             $meeting_url,
        public readonly string             $status,      // 'pending' | 'confirmed' | 'completed' | 'cancelled' | 'rescheduled'
    ) {}

    public function is_upcoming(): bool {
        // v2.7 — corrigé : le vrai statut "à venir" est 'confirmed' (voir
        // toutes les requêtes de BookingRepository), 'booked' n'existe pas
        // dans wp_rk_bookings.status et ne matchait donc jamais.
        return $this->start_at > new \DateTimeImmutable() && $this->status === 'confirmed';
    }

    /**
     * @since 4.20.4 — MÊME fenêtre (-15 min / +2 h autour de l'heure de
     * séance) que celle utilisée dans
     * RK_Dashboard_REST::format_next_session() pour session.join_url —
     * extraite ici en méthode réutilisable pour que
     * RK_Bookings_REST::format_booking() (liste complète des séances)
     * puisse gater join_url de la même façon sans redéfinir la fenêtre
     * une troisième fois. Un enfant ne doit jamais pouvoir rejoindre un
     * appel vidéo hors de ce créneau, liste ou pas.
     */
    public function is_live_window(): bool {
        $now = new \DateTimeImmutable();
        return $now >= $this->start_at->modify( '-15 minutes' )
            && $now <= $this->start_at->modify( '+2 hours' );
    }
}