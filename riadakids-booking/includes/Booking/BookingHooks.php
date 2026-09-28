<?php
/**
 * RiadaKids\Booking\BookingHooks — v4.1 AUDIT FIX
 *
 */

namespace RiadaKids\Booking;

if ( ! defined( 'ABSPATH' ) ) exit;

class BookingHooks {

    public function __construct( private readonly BookingService $service ) {}

    public function register(): void {
        // ── Webhook REST ──────────────────────────────────────────────────────
        add_action( 'rest_api_init', [ $this, 'register_rest_webhook' ] );

        // ── Actions natives SSA ───────────────────────────────────────────────
        // BUG-1 FIX : "created" SUPPRIMÉ — SSA déclenche toujours booked ou
        // confirmed juste après, ce qui déclenchait une triple exécution.
        // On conserve "booked" (principal) + "confirmed" (garde si booked absent).
        add_action( 'ssa/appointment/booked',    [ $this, 'on_ssa_booked' ], 10, 2 );
        add_action( 'ssa/appointment/confirmed', [ $this, 'on_ssa_booked' ], 10, 2 );

        // "updated" : uniquement si le statut change vers booked/confirmed
        add_action( 'ssa/appointment/updated',   [ $this, 'on_ssa_updated' ], 10, 2 );

        // Reprogrammation
        add_action( 'ssa/appointment/rescheduled', [ $this, 'on_ssa_rescheduled' ], 10, 2 );

        // Annulation (les deux orthographes)
        add_action( 'ssa/appointment/canceled',  [ $this, 'on_ssa_canceled' ], 10, 1 );
        add_action( 'ssa/appointment/cancelled', [ $this, 'on_ssa_canceled' ], 10, 1 );
    }

    // ── Webhook REST ─────────────────────────────────────────────────────────

    public function register_rest_webhook(): void {
        register_rest_route( 'riadakids/v1', '/ssa-webhook', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'handle_rest_webhook' ],
            'permission_callback' => [ $this, 'verify_webhook_signature' ],
        ] );
    }

    public function handle_rest_webhook( \WP_REST_Request $request ): \WP_REST_Response {
        $payload = $request->get_json_params() ?: [];
        $event   = sanitize_text_field( $payload['event'] ?? 'booked' );

        rk_log( 'WEBHOOK', "SSA webhook reçu: event={$event}" );

        $type_id      = SSAIntegration::extract_appointment_type_id( $payload );
        $appt_id      = SSAIntegration::extract_appointment_id( $payload );
        $datetime     = sanitize_text_field( $payload['start_date_time'] ?? $payload['start_date'] ?? $payload['datetime'] ?? '' );
        $booking_uuid = sanitize_text_field( SSAIntegration::extract_booking_uuid( $payload ) );
        $coach        = sanitize_text_field( SSAIntegration::extract_coach_name( $payload ) );
        $user_id      = $this->extract_user_id_from_payload( $payload );

        if ( in_array( $event, [ 'canceled', 'cancelled' ], true ) ) {
            // AJOUT (même protection que on_ssa_canceled() ci-dessous, voir
            // sa doc) — un vrai webhook SSA externe pourrait aussi envoyer
            // deux requêtes HTTP distinctes (une par variante d'event) pour
            // la même annulation.
            $dedup_key = 'rk_cancel_hook_dedup_' . $appt_id;
            if ( get_transient( $dedup_key ) ) {
                rk_log( 'WEBHOOK', "SSA cancel appt_id={$appt_id} — doublon de webhook ignoré" );
                return new \WP_REST_Response( [ 'status' => 'duplicate_ignored' ], 200 );
            }
            set_transient( $dedup_key, 1, 30 );

            $ok = $this->service->cancel_from_ssa( $appt_id );
            rk_log( 'WEBHOOK', "SSA cancel appt_id={$appt_id} → " . ( $ok ? 'OK' : 'not_found' ) );
            return new \WP_REST_Response( [ 'status' => $ok ? 'cancelled' : 'not_found' ], $ok ? 200 : 404 );
        }

        if ( $event === 'rescheduled' ) {
            $ssa_link = sanitize_url( $payload['reschedule_url'] ?? $payload['calendar_url'] ?? '' );
            $ok = $this->service->reschedule_from_ssa( $appt_id, $datetime, $ssa_link );
            rk_log( 'WEBHOOK', "SSA reschedule appt_id={$appt_id} → " . ( $ok ? 'OK' : 'not_found' ) );
            return new \WP_REST_Response( [ 'status' => $ok ? 'rescheduled' : 'not_found' ], $ok ? 200 : 404 );
        }

        // BUG-1 FIX : "created" traité comme "booked" côté webhook REST également
        if ( ! SSAIntegration::is_valid_event_id( $type_id ) ) {
            rk_log( 'WEBHOOK', "SSA type_id={$type_id} non géré par RK — ignoré" );
            return new \WP_REST_Response( [ 'status' => 'ignored' ], 200 );
        }

        $ok = $this->service->confirm_from_ssa( $appt_id, $type_id, $datetime, $user_id, $booking_uuid, $coach );
        rk_log( 'WEBHOOK', "SSA confirm appt_id={$appt_id} user={$user_id} → " . ( $ok ? 'OK' : 'not_found' ) );
        return new \WP_REST_Response( [ 'status' => $ok ? 'confirmed' : 'not_found' ], $ok ? 200 : 404 );
    }

    /**
     * ÉTAPE 13 — durcissement sécurité.
     *
     * AVANT : si RK_SSA_WEBHOOK_SECRET n'était pas définie, cette méthode
     * retournait TOUJOURS true — le webhook REST acceptait alors N'IMPORTE
     * QUELLE requête sans aucune vérification, ce qui permettait à un tiers
     * de POST un booking_uuid deviné/volé et de faire confirmer un booking
     * (avec déduction de crédits) sans réservation SSA réelle.
     *
     * APRÈS : le repli sans secret configuré est conservé (pour ne pas
     * casser un déploiement existant qui n'a jamais eu ce secret), MAIS :
     *   1. Un avertissement explicite est loggé à chaque requête acceptée
     *      sans secret, pour rendre ce manque de configuration visible.
     *   2. handle_rest_webhook() (voir plus bas) exige désormais que le
     *      booking_uuid corresponde à un booking pending EXISTANT ET NON
     *      EXPIRÉ (voir BookingService::confirm_from_ssa() / expiration
     *      ajoutée à cette étape) avant de confirmer quoi que ce soit —
     *      ce qui réduit drastiquement la fenêtre d'attaque même sans
     *      secret configuré : un UUID inventé ou expiré ne peut plus
     *      confirmer un booking.
     * La vraie correction reste de définir RK_SSA_WEBHOOK_SECRET dans
     * wp-config.php et de configurer la même valeur côté SSA (Webhooks
     * settings) — ce point n'est pas encore fait sur riadakids.com.
     */
    public function verify_webhook_signature( \WP_REST_Request $request ): bool {
        $secret = defined( 'RK_SSA_WEBHOOK_SECRET' ) ? RK_SSA_WEBHOOK_SECRET : '';
        if ( ! $secret ) {
            rk_log( 'WEBHOOK', 'RK_SSA_WEBHOOK_SECRET non définie — requête acceptée SANS vérification de signature. Définir cette constante dans wp-config.php pour sécuriser le webhook.', 'warning' );
            return true;
        }
        $sig      = $request->get_header( 'X-SSA-Signature' ) ?? '';
        $expected = 'sha256=' . hash_hmac( 'sha256', $request->get_body(), $secret );
        return hash_equals( $expected, $sig );
    }

    // ── Actions natives SSA ──────────────────────────────────────────────────

    /**
     * Appelé sur booked / confirmed.
     * BUG-1 FIX : "created" retiré → ce handler ne fire qu'une fois par RDV.
     */
    public function on_ssa_booked( int $appt_id, array $data ): void {
        $type_id = (int) ( $data['appointment_type_id'] ?? 0 );
        if ( ! SSAIntegration::is_valid_event_id( $type_id ) ) return;

        $datetime = (string) ( $data['start_date_time'] ?? $data['start_date'] ?? $data['start_at'] ?? $data['datetime'] ?? '' );
        $user_id  = $this->extract_user_id_from_data( $data );
        $uuid     = sanitize_text_field( SSAIntegration::extract_booking_uuid( $data ) );
        $coach    = sanitize_text_field( SSAIntegration::extract_coach_name( $data ) );

        rk_log( 'HOOK', "ssa_booked appt_id={$appt_id} type={$type_id} user={$user_id} uuid={$uuid}" );
        $this->service->confirm_from_ssa( $appt_id, $type_id, $datetime, $user_id, $uuid, $coach );
    }

    /**
     * BUG-1b FIX : on_ssa_updated() ne propage vers on_ssa_booked() que si le
     * booking RK n'est PAS encore confirmé, pour éviter une double exécution
     * quand SSA envoie updated juste après booked.
     */
    public function on_ssa_updated( int $appt_id, array $data ): void {
        $status = strtolower( $data['status'] ?? '' );

        if ( in_array( $status, [ 'booked', 'confirmed' ], true ) ) {
            // BUG-1b FIX : vérifier si déjà confirmé avant de propager
            $booking_id = \RiadaKids\Database\DB::booking_exists( $appt_id );
            if ( $booking_id ) {
                $rk_status = (string) get_post_meta( $booking_id, '_rk_status', true );
                if ( $rk_status === 'confirmed' ) {
                    rk_log( 'HOOK', "ssa_updated appt_id={$appt_id} → déjà confirmé, skip on_ssa_booked (BUG-1b)" );
                    return;
                }
            }
            $this->on_ssa_booked( $appt_id, $data );

        } elseif ( in_array( $status, [ 'canceled', 'cancelled' ], true ) ) {
            $this->on_ssa_canceled( $appt_id );

        } elseif ( $status === 'rescheduled' ) {
            $this->on_ssa_rescheduled( $appt_id, $data );
        }
    }

    /**
     * Reprogrammation → PAS de confirm, PAS de déduction crédits.
     */
    public function on_ssa_rescheduled( int $appt_id, array $data ): void {
        $new_datetime = (string) ( $data['start_date_time'] ?? $data['start_date'] ?? $data['start_at'] ?? $data['datetime'] ?? '' );
        $ssa_link     = sanitize_url( $data['reschedule_url'] ?? $data['calendar_url'] ?? '' );
        rk_log( 'HOOK', "ssa_rescheduled appt_id={$appt_id} → {$new_datetime}" );
        $this->service->reschedule_from_ssa( $appt_id, $new_datetime, $ssa_link );
    }

    /**
     * FIX (bug signalé, logs fournis — double remboursement sur deux
     * bookings différents pour un même appt_id, quelques secondes
     * d'écart) — SSA déclenche à la fois 'ssa/appointment/canceled' ET
     * 'ssa/appointment/cancelled' pour une seule transition de statut
     * (voir register() ci-dessus, les deux branchés sur ce même
     * callback), donc cette méthode était appelée deux fois pour le même
     * événement réel. Transient de déduplication très court (30s, largement
     * suffisant pour deux hooks du même événement WordPress qui se suivent
     * de quelques millisecondes à quelques secondes) : le premier appel
     * traite normalement, tout second appel avec le même appt_id dans
     * cette fenêtre est ignoré AVANT même d'atteindre cancel_from_ssa() —
     * complète la protection déjà en place côté BookingService (booking_id
     * connu transmis par cancel_on_ssa(), voir sa doc), qui ne couvre que
     * le chemin client et pas ce doublon de hook lui-même.
     */
    public function on_ssa_canceled( int $appt_id ): void {
        $dedup_key = 'rk_cancel_hook_dedup_' . $appt_id;
        if ( get_transient( $dedup_key ) ) {
            rk_log( 'HOOK', "ssa_canceled appt_id={$appt_id} — doublon de hook ignoré (canceled/cancelled déclenchés ensemble)" );
            return;
        }
        set_transient( $dedup_key, 1, 30 );

        rk_log( 'HOOK', "ssa_canceled appt_id={$appt_id}" );
        $this->service->cancel_from_ssa( $appt_id );
    }

    // ── Extraction user_id ───────────────────────────────────────────────────

    private function extract_user_id_from_payload( array $payload ): int {
        if ( ! empty( $payload['rk_uid'] ) )      return (int) $payload['rk_uid'];
        if ( ! empty( $payload['customer_id'] ) ) return (int) $payload['customer_id'];
        $email = sanitize_email(
            $payload['customer_email'] ?? $payload['email'] ?? $payload['customer']['email'] ?? ''
        );
        if ( $email ) {
            $user = get_user_by( 'email', $email );
            if ( $user ) return $user->ID;
        }
        return 0;
    }

    private function extract_user_id_from_data( array $data ): int {
        if ( ! empty( $data['rk_uid'] ) )       return (int) $data['rk_uid'];
        if ( ! empty( $data['customer_id'] ) )  return (int) $data['customer_id'];
        if ( ! empty( $data['user_id'] ) )      return (int) $data['user_id'];
        $email = sanitize_email(
            $data['customer_email'] ?? $data['email'] ?? $data['customer']['email'] ?? ''
        );
        if ( $email ) {
            $user = get_user_by( 'email', $email );
            if ( $user ) return $user->ID;
        }
        return 0;
    }
}