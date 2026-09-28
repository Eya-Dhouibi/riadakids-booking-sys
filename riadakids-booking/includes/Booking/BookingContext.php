<?php
/**
 *
 * @package RiadaKids\Booking
 */

namespace RiadaKids\Booking;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class BookingContext {

    public int    $user_id      = 0;
    public int    $program_id   = 0;
    public int    $adventure_id = 0;
    public int    $session_id   = 0;

    /**
     * BUG-2 FIX : Nom de la séance tel que défini dans ACF (ex: "الاثنين 15h-17h").
     * C'est ce champ qui doit apparaître dans le Dashboard et les emails.
     */
    public string $session_name = '';

    /** @var int[]  IDs des enfants sélectionnés */
    public array  $child_ids    = [];

    /** ID du type de rendez-vous SSA sélectionné */
    public int    $event_id     = 0;

    /**
     * Titre du RKEvent SSA (ex: "Mentor Ahmed").
     * NE PAS utiliser pour afficher le nom de la séance dans le dashboard.
     * Utiliser $session_name à la place.
     */
    public string $event_name   = '';

    /** ID du rendez-vous SSA créé (rempli après confirmation SSA) */
    public int    $appointment_id = 0;

    /** Date/heure choisie dans le widget SSA (ISO 8601) */
    public string $appointment_datetime = '';

    /** Nombre de crédits consommés (= count($child_ids)) */
    public int    $credits_used = 0;

    // -----------------------------------------------------------------------

    public static function from_array( array $data ): self {
        $ctx = new self();

        $ctx->user_id      = (int)    ( $data['user_id']      ?? get_current_user_id() );
        $ctx->program_id   = (int)    ( $data['program_id']   ?? 0 );
        $ctx->adventure_id = (int)    ( $data['adventure_id'] ?? 0 );
        $ctx->session_id   = (int)    ( $data['session_id']   ?? 0 );
        $ctx->session_name = (string) ( $data['session_name'] ?? '' ); // BUG-2 FIX
        $ctx->event_id     = (int)    ( $data['event_id']     ?? 0 );
        $ctx->event_name   = (string) ( $data['event_name']   ?? '' );
        $ctx->appointment_id       = (int)    ( $data['appointment_id']       ?? 0 );
        $ctx->appointment_datetime = (string) ( $data['appointment_datetime'] ?? '' );

        // child_ids : accepte child_ids[] (form) ou child_ids (JSON array)
        if ( isset( $data['child_ids'] ) && is_array( $data['child_ids'] ) ) {
            $ctx->child_ids = array_map( 'intval', $data['child_ids'] );
        } elseif ( isset( $data['child_id'] ) ) {
            // Rétrocompatibilité ancien format single child
            $ctx->child_ids = [ (int) $data['child_id'] ];
        }

        // 1 réservation = 1 enfant = 1 crédit fixe (mode single-child)
        // On garde max(1,…) pour rétrocompatibilité avec les anciens bookings multi-enfants
        $ctx->credits_used = 1;

        // Résolution du nom SSA si non fourni (titre RKEvent)
        // Note : on ne doit PAS écraser session_name avec ce résultat.
        if ( $ctx->event_id > 0 && $ctx->event_name === '' ) {
            $event = SSAIntegration::find_event( $ctx->event_id );
            if ( $event ) {
                $ctx->event_name = $event->title;
            }
        }

        return $ctx;
    }

    /**
     * Validation complète — exige un event_id SSA valide.
     * Utilisée par create_booking() (flux legacy) uniquement.
     */
    public function is_valid(): bool {
        return $this->user_id > 0
            && $this->program_id > 0
            && $this->session_id > 0
            && count( $this->child_ids ) > 0
            && $this->event_id > 0
            && SSAIntegration::is_valid_event_id( $this->event_id );
    }

    /**
     * Validation allégée pour pre_create_booking() — Phase 3.1 iframe SSA.
     *
     * 1 réservation = 1 enfant exactement (mode single-child v8.0).
     * event_id intentionnellement NON requis :
     * l'utilisateur choisit le conseiller SSA DANS l'iframe, APRÈS le pre-create.
     * L'appointment_type_id sera fourni par le webhook SSA et résolu dans
     * BookingService::confirm_from_ssa().
     */
    public function is_valid_for_pre_create(): bool {
        return $this->user_id    > 0
            && $this->program_id > 0
            && $this->session_id > 0
            && count( $this->child_ids ) === 1;
    }

    public function to_array(): array {
        return [
            'user_id'              => $this->user_id,
            'program_id'           => $this->program_id,
            'adventure_id'         => $this->adventure_id,
            'session_id'           => $this->session_id,
            'session_name'         => $this->session_name, // BUG-2 FIX
            'child_ids'            => $this->child_ids,
            'event_id'             => $this->event_id,
            'event_name'           => $this->event_name,
            'appointment_id'       => $this->appointment_id,
            'appointment_datetime' => $this->appointment_datetime,
            'credits_used'         => $this->credits_used,
        ];
    }
}