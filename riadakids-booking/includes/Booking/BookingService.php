<?php
/**
 * RiadaKids\Booking\BookingService — v4.4 AUDIT FIX
 *
 * @package RiadaKids\Booking
 */

namespace RiadaKids\Booking;

use RiadaKids\Credits\CreditRepository;
use RiadaKids\Credits\CreditLogger;
use RiadaKids\Database\DB;
use RiadaKids\Core\Helpers;

if ( ! defined( 'ABSPATH' ) ) exit;

class BookingService {

    public function __construct(
        private readonly BookingRepository $repository,
        private readonly BookingNotifier   $notifier
    ) {}

    // ═══════════════════════════════════════════════════════════════════
    // PRÉ-CRÉATION BOOKING (étape 5 — avant widget SSA)
    // ═══════════════════════════════════════════════════════════════════

    public function pre_create_booking( BookingContext $ctx ): array {
        rk_log( 'SERVICE', sprintf(
            'pre_create_booking() ENTRY — user=%d program=%d session=%d children=[%s] event=%d credits=%d',
            $ctx->user_id, $ctx->program_id, $ctx->session_id,
            implode( ',', $ctx->child_ids ), $ctx->event_id, $ctx->credits_used
        ) );

        if ( ! $ctx->is_valid_for_pre_create() ) {
            rk_log( 'SERVICE', sprintf(
                'pre_create_booking() VALIDATION FAIL — user_id=%d(%s) program_id=%d(%s) session_id=%d(%s) child_count=%d(%s)',
                $ctx->user_id,    $ctx->user_id    > 0 ? 'OK' : 'FAIL',
                $ctx->program_id, $ctx->program_id > 0 ? 'OK' : 'FAIL',
                $ctx->session_id, $ctx->session_id > 0 ? 'OK' : 'FAIL',
                count( $ctx->child_ids ), count( $ctx->child_ids ) > 0 ? 'OK' : 'FAIL'
            ), 'error' );
            return $this->err( __( 'Données de réservation invalides.', 'riadakids' ) );
        }

        $needed = $ctx->credits_used;
        $avail  = CreditRepository::get_balance( $ctx->user_id );
        rk_log( 'SERVICE', "pre_create_booking() crédits — needed={$needed} avail={$avail}" );

        if ( $avail < $needed ) {
            rk_log( 'SERVICE', "pre_create_booking() CRÉDITS INSUFFISANTS user#{$ctx->user_id}", 'error' );
            return $this->err( sprintf(
                __( 'Crédits insuffisants. Nécessaire : %d, disponible : %d.', 'riadakids' ),
                $needed, $avail
            ) );
        }

        $existing_pending = $this->find_recent_pending( $ctx );
        if ( $existing_pending ) {
            $uuid = (string) get_post_meta( $existing_pending, '_rk_booking_uuid', true );
            rk_log( 'SERVICE', "pre_create_booking() réutilise booking#{$existing_pending} (pending récent) user#{$ctx->user_id}" );
            return [ 'success' => true, 'booking_id' => $existing_pending, 'booking_uuid' => $uuid, 'message' => '' ];
        }

        rk_log( 'SERVICE', "pre_create_booking() création CPT rk_booking pour user#{$ctx->user_id}" );
        $booking_id = $this->repository->create( $ctx );
        if ( ! $booking_id ) {
            rk_log( 'SERVICE', 'pre_create_booking() ÉCHEC wp_insert_post', 'error' );
            return $this->err( __( 'Erreur lors de la création de la réservation.', 'riadakids' ) );
        }

        $this->save_meta( $booking_id, $ctx );
        $uuid = (string) get_post_meta( $booking_id, '_rk_booking_uuid', true );

        rk_log( 'SERVICE', "pre_create_booking() SUCCÈS — booking#{$booking_id} uuid={$uuid} user#{$ctx->user_id} — crédits NON déduits" );
        do_action( 'rk_booking_pending', $booking_id, $ctx );

        return [ 'success' => true, 'booking_id' => $booking_id, 'booking_uuid' => $uuid, 'message' => '' ];
    }

    // create_booking() (flux legacy, étape 6) supprimé — voir BookingAjax.php.
    // pre_create_booking() (ci-dessus) + confirm_from_ssa() (ci-dessous)
    // couvrent désormais tout le cycle de vie du booking.

    // ═══════════════════════════════════════════════════════════════════
    // Confirmation SSA → déduction crédits
    // ═══════════════════════════════════════════════════════════════════

    public function confirm_from_ssa(
        int    $appointment_id,
        int    $appointment_type_id,
        string $datetime,
        int    $user_id = 0,
        string $booking_uuid = '',
        string $coach = ''
    ): bool {
        rk_log( 'BOOKING', "confirm_from_ssa() appt_id={$appointment_id} type={$appointment_type_id} user={$user_id}" );

        // PHASE 2 — GARDE 1 : cet appointment SSA est déjà lié à un booking du
        // nouveau workflow (pré-confirmation) → callback SSA sans effet métier.
        // Placé AVANT toute recherche legacy pour qu'un booking 'pending'
        // legacy du même parent ne soit jamais confirmé à sa place.
        $linked_preconf = $this->repository->find_pre_confirmation_by_appointment_id( $appointment_id );
        if ( $linked_preconf ) {
            return $this->handle_preconfirmation_callback( $linked_preconf, $appointment_id );
        }

        $booking_id = 0;

        if ( $booking_uuid ) {
            $booking_id = $this->repository->find_by_uuid( $booking_uuid );
            rk_log( 'BOOKING', "confirm via uuid={$booking_uuid} → booking#{$booking_id}" );
        }

        if ( ! $booking_id && $user_id ) {
            $booking_id = $this->repository->find_pending_booking_by_event_and_user( $appointment_type_id, $user_id );
            rk_log( 'BOOKING', "confirm via CPT pending event+user → booking#{$booking_id}" );
        }

        if ( ! $booking_id ) {
            $booking_id = $this->repository->find_pending_booking_by_event( $appointment_type_id );
            if ( $booking_id ) {
                // ÉTAPE 13 — collision. Ce fallback n'a ni UUID ni user_id
                // pour désambiguïser : si plusieurs bookings pending
                // existent pour cet event_id (deux utilisateurs en cours
                // de réservation simultanée, par ex.), on ne peut pas
                // garantir que le booking retourné est le bon. Mieux vaut
                // refuser explicitement que de risquer de confirmer et
                // déduire des crédits sur le compte du mauvais utilisateur.
                $candidates = $this->repository->count_pending_bookings_by_event( $appointment_type_id );
                if ( $candidates > 1 ) {
                    rk_log( 'BOOKING', "confirm_from_ssa() fallback event_id seul AMBIGU ({$candidates} candidats pending) pour type={$appointment_type_id} — confirmation refusée", 'error' );
                    return false;
                }
                rk_log( 'BOOKING', "confirm via CPT pending event_id seul (fallback) → booking#{$booking_id}", 'warning' );
            }
        }

        if ( ! $booking_id ) {
            rk_log( 'BOOKING', "confirm_from_ssa() aucun booking pending trouvé pour type={$appointment_type_id}", 'error' );
            return false;
        }

        // PHASE 2 — GARDE 2 : booking résolu (ex. via UUID) mais en pré-confirmation.
        if ( BookingStatus::is_pre_confirmation( (string) get_post_meta( $booking_id, '_rk_status', true ) ) ) {
            return $this->handle_preconfirmation_callback( $booking_id, $appointment_id );
        }

        // ÉTAPE 14 — Idempotence. Le check de statut ci-dessous n'est pas
        // atomique par lui-même (lecture puis, plus loin, écriture) : deux
        // exécutions concurrentes de confirm_from_ssa() pour le MÊME
        // booking_id (ex. SSA envoie ssa/appointment/booked deux fois de
        // suite, ou webhook + hook natif arrivent en même temps) pourraient
        // toutes deux lire _rk_status='pending' avant que l'une des deux
        // n'ait eu le temps d'écrire 'confirmed', et donc toutes deux
        // continuer : double insert_to_rk_bookings (déjà protégé par un
        // check d'existence, mais lui-même non atomique), double
        // notification email, et une tentative de double déduction de
        // crédits (elle-même protégée par decrease_safe(), qui verrouille
        // la ligne crédits de l'utilisateur via SELECT FOR UPDATE — mais
        // ça ne protège pas le CPT rk_booking ni les notifications).
        //
        // Un verrou SQL nommé sur ce booking_id précis (GET_LOCK, déjà
        // utilisé ailleurs dans le plugin pour les crédits — voir DB.php)
        // sérialise les exécutions concurrentes : la seconde attend que la
        // première ait fini (et donc déjà écrit 'confirmed') avant de
        // relire le statut, et voit alors 'confirmed' → return true sans
        // rien réexécuter. Timeout court (5s) : en cas d'échec d'acquisition
        // (verrou bloqué anormalement longtemps), on refuse plutôt que de
        // risquer un double traitement.
        $lock_name = 'rk_confirm_booking_' . $booking_id;
        if ( ! DB::acquire_sql_lock( $lock_name, 5 ) ) {
            rk_log( 'BOOKING', "confirm_from_ssa() impossible d'acquérir le verrou pour booking#{$booking_id} — traitement concurrent en cours, requête refusée", 'error' );
            return false;
        }

        try {
            return $this->confirm_from_ssa_locked( $booking_id, $appointment_id, $appointment_type_id, $datetime, $user_id, $coach );
        } finally {
            DB::release_sql_lock( $lock_name );
        }
    }

    /**
     * ÉTAPE 14 — corps réel de confirm_from_ssa(), exécuté sous verrou
     * (voir confirm_from_ssa() ci-dessus). Logique métier strictement
     * identique à avant cette étape — uniquement extraite dans sa propre
     * méthode pour pouvoir être encadrée par try/finally proprement.
     */
    private function confirm_from_ssa_locked(
        int    $booking_id,
        int    $appointment_id,
        int    $appointment_type_id,
        string $datetime,
        int    $user_id,
        string $coach = ''
    ): bool {
        $current_status = (string) get_post_meta( $booking_id, '_rk_status', true );

        // PHASE 2 — GARDE 3 (sous verrou) : le statut a pu passer en
        // pré-confirmation entre la garde 2 et l'acquisition du verrou.
        if ( BookingStatus::is_pre_confirmation( $current_status ) ) {
            return $this->handle_preconfirmation_callback( $booking_id, $appointment_id );
        }

        if ( $current_status === 'confirmed' ) {
            rk_log( 'BOOKING', "confirm_from_ssa() booking#{$booking_id} déjà confirmé — skip", 'warning' );
            return true;
        }

        // ÉTAPE 13 — Expiration. Un booking pending trop ancien ne doit plus
        // pouvoir être confirmé : ni par un utilisateur légitime qui aurait
        // abandonné la réservation puis la reprendrait très tardivement dans
        // un état incohérent avec son crédit/programme actuel, ni par une
        // requête (webhook non signé, voir verify_webhook_signature) qui
        // tenterait de rejouer un ancien booking_uuid. Ce contrôle porte
        // uniquement sur les bookings encore 'pending' — un booking déjà
        // 'confirmed' est intercepté juste au-dessus et n'est jamais
        // concerné par l'expiration.
        if ( $current_status === 'pending' && self::is_pending_expired( $booking_id ) ) {
            rk_log( 'BOOKING', "confirm_from_ssa() booking#{$booking_id} pending expiré (>24h) — confirmation refusée", 'error' );
            return false;
        }

        $ctx = $this->repository->load_context( $booking_id );
        if ( ! $ctx ) {
            rk_log( 'BOOKING', "confirm_from_ssa() impossible de charger contexte booking#{$booking_id}", 'error' );
            return false;
        }

        $effective_user_id = $ctx->user_id ?: $user_id;
        $needed            = max( 1, $ctx->credits_used );

        $new_bal          = CreditRepository::decrease_safe( $effective_user_id, $needed );
        $credits_deducted = false;

        if ( $new_bal < 0 ) {
            rk_log( 'BOOKING', "confirm_from_ssa() crédits insuffisants user#{$effective_user_id} pour booking#{$booking_id} — booking confirmé SANS déduction", 'error' );
        } else {
            $credits_deducted = true;
            CreditLogger::log(
                $effective_user_id,
                'booking_deduction',
                -$needed,
                $new_bal,
                "خصم {$needed} حصة — حجز #{$booking_id} (SSA #{$appointment_id})"
            );
            rk_log( 'BOOKING', "-{$needed} crédits user#{$effective_user_id} après confirmation SSA | nouveau solde={$new_bal}" );
        }

        update_post_meta( $booking_id, '_rk_appointment_id',       $appointment_id );
        update_post_meta( $booking_id, '_rk_appointment_datetime', $datetime );
        update_post_meta( $booking_id, '_rk_status',               'confirmed' );
        update_post_meta( $booking_id, '_rk_credits_deducted',     $credits_deducted ? '1' : '0' );

        wp_update_post( [ 'ID' => $booking_id, 'post_status' => 'publish' ] );

        // AJUSTEMENT (bug signalé — nom du coach vide sur des réservations
        // fraîches malgré event_name/coach déjà en place) — nouvelle
        // priorité n°1 : staff_ids de l'appointment SSA, résolu vers un
        // vrai utilisateur WordPress (même mécanisme fiable que
        // RescheduleAjax::coach_name_for_staff_id() — les coachs sont de
        // simples users WP, get_userdata() ne dépend d'aucun markup HTML).
        // L'ancienne priorité (event_name capturé côté client depuis le
        // titre affiché par SSA, "لقاء مع [Nom]") est fragile : elle
        // dépend du markup HTML exact de SSA, qui a déjà changé au moins
        // une fois par le passé (voir _captureEventName() dans
        // booking-ssa-overlay.js) et peut recasser silencieusement à tout
        // moment. $coach (extrait du payload webhook SSA, lui aussi
        // basé sur des heuristiques de texte) reste le dernier repli.
        $coach_to_store = $this->resolve_coach_name_from_staff_ids( $appointment_id )
            ?: $ctx->event_name
            ?: $coach;

        $this->insert_to_rk_bookings( $booking_id, $ctx, $appointment_id, $appointment_type_id, $datetime, $coach_to_store );

        if ( $effective_user_id ) {
            $this->clear_pending( $effective_user_id );
        }

        $ctx->appointment_id       = $appointment_id;
        $ctx->appointment_datetime = $datetime;

        $this->notifier->notify_booking_confirmed( $booking_id, $ctx );
        do_action( 'rk_booking_confirmed', $booking_id, $ctx );

        rk_log( 'BOOKING', "booking#{$booking_id} CONFIRMÉ SSA #{$appointment_id} user#{$effective_user_id} | {$datetime}" );
        return true;
    }

    // ═══════════════════════════════════════════════════════════════════
    // Reprogrammation SSA
    // ═══════════════════════════════════════════════════════════════════

    /**
     * BUG-6 FIX :
     *   - _rk_status passe à 'rescheduled' (au lieu de 'confirmed')
     *     → le Dashboard affiche maintenant '🔄 معاد جدولة'
     *   - status SQL = 'rescheduled' (cohérence avec le CPT)
     *   - Notification email envoyée via BookingNotifier::notify_booking_rescheduled()
     */
    public function reschedule_from_ssa(
        int    $appointment_id,
        string $new_datetime,
        string $new_ssa_link = ''
    ): bool {
        rk_log( 'BOOKING', "reschedule_from_ssa() appt_id={$appointment_id} new_datetime={$new_datetime}" );

        $booking_id = $this->repository->find_by_appointment_id( $appointment_id );
        if ( ! $booking_id ) {
            rk_log( 'BOOKING', "reschedule_from_ssa() booking introuvable pour appt_id={$appointment_id}", 'warning' );
            return false;
        }

        // BUG-6 FIX : statut 'rescheduled', pas 'confirmed'
        update_post_meta( $booking_id, '_rk_appointment_datetime', $new_datetime );
        update_post_meta( $booking_id, '_rk_status', 'rescheduled' );
        if ( $new_ssa_link ) {
            update_post_meta( $booking_id, '_rk_ssa_link', $new_ssa_link );
        }

        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'rk_bookings',
            [
                'appointment' => $new_datetime,
                'status'      => 'rescheduled', // BUG-6 FIX
                'ssa_link'    => $new_ssa_link,
                'updated_at'  => current_time( 'mysql' ),
            ],
            [ 'booking_id' => $appointment_id ],
            [ '%s', '%s', '%s', '%s' ],
            [ '%d' ]
        );

        $ctx = $this->repository->load_context( $booking_id );

        // BUG-6 FIX : notification de reprogrammation
        if ( $ctx ) {
            $ctx->appointment_datetime = $new_datetime;
            $this->notifier->notify_booking_rescheduled( $booking_id, $ctx );
        }

        do_action( 'rk_booking_rescheduled', $booking_id, $new_datetime, $ctx );

        rk_log( 'BOOKING', "booking#{$booking_id} reprogrammé → {$new_datetime} (statut=rescheduled, crédits inchangés)" );
        return true;
    }

    // ═══════════════════════════════════════════════════════════════════
    // Annulation SSA → remboursement crédits ATOMIQUE
    // ═══════════════════════════════════════════════════════════════════

    /**
     * BUG-7 FIX : post_status = 'cancelled' (statut CPT sémantique)
     *   au lieu de 'trash' (état générique WordPress).
     *   Nécessite que 'cancelled' soit enregistré comme statut valide
     *   pour rk_booking dans Plugin.php (register_booking_statuses()).
     *
     * BUG-2 FIX : DB::credits_increase_safe() (atomique) remplace
     *   CreditRepository::increase() (read-modify-write non-atomique).
     *   → Protège contre le double-remboursement en cas de double webhook.
     *
     * BUG-7b FIX : notification d'annulation envoyée.
     */
    /**
     * AJOUT (bug signalé — une réservation annulée revient "confirmée"
     * après un certain temps) — cause trouvée : cancel_from_ssa()
     * (ci-dessous) est réutilisée à la fois pour le vrai webhook SSA
     * (SSA notifie qu'un appointment a été annulé CÔTÉ SSA) ET pour le
     * client qui clique "تأكيد الإلغاء" (voir BookingEditAjax::
     * ajax_cancel()) — mais dans ce 2e cas, aucun appel n'était jamais
     * fait vers SSA lui-même : seul le statut LOCAL (wp_rk_bookings)
     * passait à 'cancelled'. Le cron de synchro périodique
     * (Plugin::run_cron_sync() → DB::sync_ssa_appointment()) relit
     * ensuite le VRAI statut SSA (resté 'booked'/'confirmed', jamais
     * touché) et écrase le statut local avec cette valeur — d'où le
     * retour à "confirmé" après la prochaine exécution du cron.
     *
     * Cette méthode appelle réellement SSA_Appointment_Model::update()
     * avec status='canceled' (orthographe SSA — un seul 'l', confirmée en
     * lisant SSA_Appointment_Model::get_canceled_statuses() dans son code
     * source) AVANT de déléguer à cancel_from_ssa() pour le reste du
     * travail local (remboursement, statut CPT, etc.) — jamais l'inverse
     * : appeler SSA doit précéder la mise à jour locale, pour que
     * is_editable()/les gardes de fenêtre 24h restent basées sur l'état
     * réellement accepté par SSA (voir SSA_Appointment_Model::
     * customer_can_transition(), qui peut refuser la transition — ex.
     * appointment déjà annulé côté SSA par un autre canal entre-temps).
     *
     * @return bool false si SSA refuse la transition ou si l'appel
     *              échoue — cancel_from_ssa() n'est alors JAMAIS appelée,
     *              pour ne jamais désynchroniser l'état local d'un état
     *              SSA qui n'a pas réellement changé.
     */
    /**
     * @param int $known_booking_id CPT booking_id EXACT, déjà connu avec
     *            certitude côté appelant (voir BookingEditAjax::
     *            ajax_cancel(), qui connaît $row_id sans ambiguïté). 0 si
     *            inconnu (cas webhook SSA, voir doc de cancel_from_ssa()
     *            ci-dessous pour pourquoi cette distinction est
     *            nécessaire).
     */
    public function cancel_on_ssa( int $appointment_id, int $known_booking_id = 0 ): bool {
        if ( ! function_exists( 'ssa' ) ) {
            rk_log( 'BOOKING', 'cancel_on_ssa: fonction ssa() introuvable — SSA inactif ?', 'error' );
            return false;
        }

        try {
            $ssa_plugin = ssa();
        } catch ( \Throwable $e ) {
            rk_log( 'BOOKING', 'cancel_on_ssa: ssa() inaccessible — ' . $e->getMessage(), 'error' );
            return false;
        }

        try {
            $result = $ssa_plugin->appointment_model->update( $appointment_id, [
                'status' => 'canceled',
            ] );

            if ( is_array( $result ) && ! empty( $result['error'] ) ) {
                rk_log( 'BOOKING', "cancel_on_ssa: SSA a refusé l'annulation de l'appointment#{$appointment_id} — " . ( $result['error']['message'] ?? $result['error']['code'] ?? 'raison inconnue' ), 'warning' );
                return false;
            }
        } catch ( \Throwable $e ) {
            rk_log( 'BOOKING', 'cancel_on_ssa update(): ' . $e->getMessage(), 'error' );
            return false;
        }

        return $this->cancel_from_ssa( $appointment_id, $known_booking_id );
    }

    /**
     * FIX (bug signalé, logs fournis — DOUBLE remboursement de crédit sur
     * deux bookings DIFFÉRENTS pour un même appt_id, quelques secondes
     * d'écart) — cause exacte trouvée : SSA déclenche à la fois les hooks
     * 'ssa/appointment/canceled' ET 'ssa/appointment/cancelled' pour une
     * seule transition de statut (voir BookingHooks::register(), les deux
     * branchés sur le même callback) — mon propre appel update() dans
     * cancel_on_ssa() ci-dessus déclenche cette même chaîne de hooks (déjà
     * confirmée dans une session précédente : ssa/appointment/after_update
     * → ...), donc cancel_from_ssa() était appelée DEUX FOIS pour la même
     * annulation. Entre les deux appels, BookingRepository::
     * find_by_appointment_id() (ORDER BY FIELD(post_status,'publish',...)
     * ASC) retrouve une ligne DIFFÉRENTE : le 1er appel trouve et annule
     * le VRAI booking (passe alors à post_status='cancelled', donc
     * descend dans le tri) ; si appointment_id est un appointment DE TEST
     * réutilisé (confirmé dans les sessions précédentes — "event test
     * riadakids") partagé par plusieurs vieux bookings locaux encore en
     * 'publish', le 2e appel en retrouve un AUTRE et le rembourse à tort.
     *
     * $known_booking_id (passé par cancel_on_ssa() depuis le chemin
     * client, qui connaît le VRAI booking_id sans ambiguïté) élimine
     * cette recherche ambiguë : quand fourni, il est utilisé directement,
     * find_by_appointment_id() n'est appelée QUE pour le chemin webhook
     * (où le vrai booking reste de toute façon indéterminable autrement).
     */
    public function cancel_from_ssa( int $appointment_id, int $known_booking_id = 0 ): bool {
        rk_log( 'BOOKING', "cancel_from_ssa() appt_id={$appointment_id}" . ( $known_booking_id ? " (booking_id connu={$known_booking_id})" : '' ) );

        $booking_id = $known_booking_id > 0
            ? $known_booking_id
            : $this->repository->find_by_appointment_id( $appointment_id );
        if ( ! $booking_id ) {
            rk_log( 'BOOKING', "cancel_from_ssa() booking introuvable pour appt_id={$appointment_id}", 'warning' );
            return false;
        }

        $already_refunded = (string) get_post_meta( $booking_id, '_rk_credits_refunded', true );
        if ( $already_refunded === '1' ) {
            rk_log( 'BOOKING', "cancel_from_ssa() booking#{$booking_id} déjà remboursé — skip", 'warning' );
            return true;
        }

        $ctx = $this->repository->load_context( $booking_id );

        update_post_meta( $booking_id, '_rk_status', 'cancelled' );
        // BUG-7 FIX : 'cancelled' au lieu de 'trash'
        wp_update_post( [ 'ID' => $booking_id, 'post_status' => 'cancelled' ] );

        // AJOUT (défense en profondeur, même bug que cancel_on_ssa() —
        // voir sa doc) — protège aussi ce chemin webhook contre un
        // écrasement par le cron dans l'intervalle où SSA n'aurait pas
        // encore propagé le nouveau statut à toutes ses propres requêtes
        // internes (cas limite, cache SSA). Même durée que
        // DB::sync_ssa_appointment(), qui pose ce même transient à la fin
        // de chaque synchro normale.
        set_transient( 'rk_cron_skip_' . $appointment_id, 1, HOUR_IN_SECONDS );

        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'rk_bookings',
            [ 'status' => 'cancelled', 'is_refunded' => 0, 'updated_at' => current_time( 'mysql' ) ],
            [ 'booking_id' => $appointment_id ],
            [ '%s', '%d', '%s' ],
            [ '%d' ]
        );

        if ( $ctx ) {
            $credits_deducted = (string) get_post_meta( $booking_id, '_rk_credits_deducted', true );

            // Rule: credit refund only if cancellation happens within 24h of booking_date.
            // After 24h the booking is final — no refund regardless of when the session is.
            $booking_date_raw  = $wpdb->get_var( $wpdb->prepare(
                "SELECT MIN(booking_date) FROM {$wpdb->prefix}rk_bookings WHERE booking_id = %d",
                $appointment_id
            ) );
            $within_24h_window = $booking_date_raw
                ? ( ( time() - strtotime( $booking_date_raw ) ) < DAY_IN_SECONDS )
                : true; // unknown booking_date → allow refund to avoid penalising edge cases

            if ( $credits_deducted === '1' && $within_24h_window ) {
                $used = max( 1, $ctx->credits_used );

                // BUG-2 FIX : credits_increase_safe() — transaction SQL atomique
                // (SELECT FOR UPDATE sur wp_rk_user_credits) évite le double-remboursement
                // si deux webhooks cancel arrivent simultanément.
                $new_bal = DB::credits_increase_safe( $ctx->user_id, $used );

                // Sync usermeta
                update_user_meta( $ctx->user_id, 'rk_session_credits', $new_bal );

                CreditLogger::log(
                    $ctx->user_id,
                    'booking_refund',
                    $used,
                    $new_bal,
                    "استرداد {$used} حصة — إلغاء حجز #{$booking_id} (SSA #{$appointment_id})"
                );

                update_post_meta( $booking_id, '_rk_credits_refunded', '1' );

                $wpdb->update(
                    $wpdb->prefix . 'rk_bookings',
                    [ 'is_refunded' => 1, 'updated_at' => current_time( 'mysql' ) ],
                    [ 'booking_id' => $appointment_id ],
                    [ '%d', '%s' ],
                    [ '%d' ]
                );

                rk_log( 'BOOKING', "+{$used} crédits remboursés user#{$ctx->user_id} annulation booking#{$booking_id} | nouveau solde={$new_bal}" );

            } elseif ( $credits_deducted === '1' && ! $within_24h_window ) {
                // Cancellation after 24h window — credits are forfeited
                rk_log( 'BOOKING', "booking#{$booking_id} annulé APRÈS fenêtre 24h — crédits NON remboursés user#{$ctx->user_id} (booking_date={$booking_date_raw})", 'warning' );
                update_post_meta( $booking_id, '_rk_credits_refunded', '0' );

            } else {
                rk_log( 'BOOKING', "booking#{$booking_id} annulé mais crédits jamais déduits — pas de remboursement" );
            }

            // BUG-7b FIX : notification d'annulation
            $this->notifier->notify_booking_cancelled( $booking_id, $ctx );
        }

        do_action( 'rk_booking_cancelled', $booking_id, $ctx );
        return true;
    }

    // ═══════════════════════════════════════════════════════════════════

    public function clear_pending( int $user_id ): void {
        delete_user_meta( $user_id, '_rk_pending_booking' );
        rk_log( 'BOOKING', "pending_booking effacé user#{$user_id}" );
    }

    // ═══════════════════════════════════════════════════════════════════
    // Synchronisation depuis SSA (cron)
    // ═══════════════════════════════════════════════════════════════════

    public static function ensure_from_ssa( array $appt, string $source = 'cron' ): int {
        $appt_id = Helpers::get_appt_id( $appt );
        if ( ! $appt_id ) return 0;

        $existing = DB::booking_exists( $appt_id );
        if ( $existing ) return $existing;

        // PHASE 2 : le cron ne doit JAMAIS créer de ligne wp_rk_bookings pour un
        // appointment appartenant à un booking encore en pré-confirmation.
        if ( ( new BookingRepository() )->find_pre_confirmation_by_appointment_id( $appt_id ) ) {
            rk_log( 'BOOKING', "ensure_from_ssa() appt#{$appt_id} lié à un booking pré-confirmation — aucune ligne SQL créée", 'info' );
            return 0;
        }

        $type_id = (int) ( $appt['appointment_type_id'] ?? 0 );
        if ( ! SSAIntegration::is_valid_event_id( $type_id ) ) return 0;

        $user_id  = Helpers::resolve_user_id_from_ssa( $appt );
        $event    = SSAIntegration::find_event( $type_id );
        $datetime = Helpers::get_appt_date( $appt );

        return DB::save_booking( [
            'booking_id'          => $appt_id,
            'user_id'             => $user_id,
            'appointment_type_id' => $type_id,
            'appointment'         => $datetime,
            'status'              => sanitize_text_field( $appt['status'] ?? 'confirmed' ),
            'meta_source'         => $source,
        ] );
    }

    public static function repair_null_fields( array $appt ): int {
        $appt_id = Helpers::get_appt_id( $appt );
        if ( ! $appt_id ) return 0;
        return DB::repair_null_fields( $appt_id, $appt );
    }

    // ═══════════════════════════════════════════════════════════════════
    // Privés
    // ═══════════════════════════════════════════════════════════════════

    /**
     * ÉTAPE 13 — expiration des bookings pending.
     * Un booking est expiré si son statut est encore 'pending' ET que
     * _rk_created_at (posé par BookingRepository::create()) date de plus
     * de 24h. Durée choisie explicitement — voir échange avec le client.
     */
    private static function is_pending_expired( int $booking_id ): bool {
        $created_at = (string) get_post_meta( $booking_id, '_rk_created_at', true );
        if ( ! $created_at ) return false; // pas de date connue : ne pas bloquer sur une donnée absente

        $created_ts = strtotime( $created_at );
        if ( ! $created_ts ) return false;

        $max_age_seconds = 24 * HOUR_IN_SECONDS;
        return ( current_time( 'timestamp' ) - $created_ts ) > $max_age_seconds;
    }

    // ═══════════════════════════════════════════════════════════════════
    // PHASE 2 — Machine d'états du nouveau workflow (CPT uniquement)
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Callback SSA (booked/confirmed) reçu pour un booking en pré-confirmation.
     *
     * AUCUN effet métier : pas de déduction de crédit, pas de passage à
     * 'confirmed', pas d'INSERT wp_rk_bookings, pas de rk_booking_confirmed,
     * pas de notification. On vérifie seulement que l'appointment reçu est
     * bien celui lié au booking :
     *   - lié et identique  → true  (cas attendu : SSA notifie le RDV que le
     *                          Coordinateur vient de créer ; on attend le parent)
     *   - non lié / différent → false (le callback ne correspond pas à ce booking)
     */
    private function handle_preconfirmation_callback( int $booking_id, int $appointment_id ): bool {
        $status = (string) get_post_meta( $booking_id, '_rk_status', true );
        $linked = (int) get_post_meta( $booking_id, '_rk_appointment_id', true );

        if ( $appointment_id > 0 && $linked === $appointment_id ) {
            rk_log( 'BOOKING', "callback SSA appt#{$appointment_id} pour booking#{$booking_id} en '{$status}' — ignoré (attente confirmation parent, aucun crédit/SQL/hook)", 'info' );
            return true;
        }

        rk_log( 'BOOKING', "callback SSA appt#{$appointment_id} REFUSÉ pour booking#{$booking_id} en '{$status}' (appointment lié={$linked})", 'warning' );
        return false;
    }

    /**
     * Change l'état métier d'un booking du NOUVEAU workflow.
     *
     * Périmètre Phase 2 (volontairement restreint) :
     *  - source ∈ {pending_schedule, pending_parent_confirmation, change_requested}
     *  - cible  ∈ transitions autorisées SAUF 'confirmed'
     *
     * 'confirmed' est refusé ici : il doit passer par la porte unique
     * existante (crédit + SQL + rk_booking_confirmed), branchée en Phase 6
     * sous le verrou 'rk_confirm_booking_{id}'. Écrire _rk_status='confirmed'
     * à la main contournerait tout cela.
     *
     * N'écrit JAMAIS dans wp_rk_bookings, ne touche jamais aux crédits.
     *
     * @param array $data pending_parent_confirmation : scheduled_by, coach_id, datetime
     *                    change_requested            : note
     * @return array{success:bool,message:string,from?:string,to?:string}
     */
    public function transition_status( int $booking_id, string $to, array $data = [] ): array {
        $post = get_post( $booking_id );
        if ( ! $post || $post->post_type !== 'rk_booking' ) {
            return $this->err( __( 'Réservation introuvable.', 'riadakids' ) );
        }
        if ( $to === BookingStatus::CONFIRMED ) {
            return $this->err( 'confirmed_requires_confirmation_gateway' );
        }

        $lock = 'rk_booking_transition_' . $booking_id;
        if ( ! DB::acquire_sql_lock( $lock, 5 ) ) {
            rk_log( 'BOOKING', "transition_status() verrou indisponible booking#{$booking_id}", 'error' );
            return $this->err( 'lock_unavailable' );
        }

        try {
            $from = (string) get_post_meta( $booking_id, '_rk_status', true );
            if ( ! BookingStatus::is_pre_confirmation( $from ) ) {
                return $this->err( "source_not_in_new_workflow:{$from}" );
            }
            if ( ! BookingStatus::can_transition( $from, $to ) ) {
                rk_log( 'BOOKING', "transition_status() refusée booking#{$booking_id} {$from} → {$to}", 'warning' );
                return $this->err( "transition_not_allowed:{$from}->{$to}" );
            }

            $meta = [];
            if ( $to === BookingStatus::PENDING_PARENT_CONFIRMATION ) {
                $by    = (int) ( $data['scheduled_by'] ?? 0 );
                $coach = (int) ( $data['coach_id'] ?? 0 );
                $dt    = sanitize_text_field( (string) ( $data['datetime'] ?? '' ) );
                if ( $by <= 0 || $coach <= 0 || $dt === '' || ! strtotime( $dt ) ) {
                    return $this->err( 'invalid_schedule_data' );
                }
                $meta = [
                    BookingStatus::META_SCHEDULED_BY      => $by,
                    BookingStatus::META_SCHEDULED_AT      => current_time( 'mysql' ),
                    BookingStatus::META_PROPOSED_COACH_ID => $coach,
                    BookingStatus::META_PROPOSED_DATETIME => $dt,
                ];
            } elseif ( $to === BookingStatus::CHANGE_REQUESTED ) {
                $note = trim( sanitize_textarea_field( (string) ( $data['note'] ?? '' ) ) );
                if ( $note === '' ) {
                    return $this->err( 'change_note_required' );
                }
                $meta = [
                    BookingStatus::META_CHANGE_REQUESTED_AT => current_time( 'mysql' ),
                    BookingStatus::META_CHANGE_NOTE         => mb_substr( $note, 0, BookingStatus::CHANGE_NOTE_MAX_LENGTH ),
                ];
            }

            foreach ( $meta as $k => $v ) {
                update_post_meta( $booking_id, $k, $v );
            }
            update_post_meta( $booking_id, '_rk_status', $to );
            wp_update_post( [ 'ID' => $booking_id, 'post_status' => BookingStatus::wp_post_status( $to ) ] );

            rk_log( 'BOOKING', "booking#{$booking_id} {$from} → {$to} (CPT uniquement, aucun SQL/crédit/hook de confirmation)" );
            do_action( 'rk_booking_status_changed', $booking_id, $from, $to );

            return [ 'success' => true, 'message' => '', 'from' => $from, 'to' => $to ];
        } finally {
            DB::release_sql_lock( $lock );
        }
    }

    /**
     * Préparation de la future expiration (aucun cron ici). Durée :
     * BookingStatus::expiry_days() (7 j, filtrable). Date de référence selon l'état.
     * Ne concerne jamais l'ancien 'pending' (is_pending_expired(), 24 h, inchangé).
     */
    public static function is_preconfirmation_expired( int $booking_id ): bool {
        $status = (string) get_post_meta( $booking_id, '_rk_status', true );
        if ( ! BookingStatus::is_pre_confirmation( $status ) ) return false;

        $ref_key = match ( $status ) {
            BookingStatus::PENDING_PARENT_CONFIRMATION => BookingStatus::META_SCHEDULED_AT,
            BookingStatus::CHANGE_REQUESTED            => BookingStatus::META_CHANGE_REQUESTED_AT,
            default                                    => '_rk_created_at',
        };
        $ref = (string) get_post_meta( $booking_id, $ref_key, true )
            ?: (string) get_post_meta( $booking_id, '_rk_created_at', true );
        $ts  = $ref ? strtotime( $ref ) : 0;
        if ( ! $ts ) return false; // pas de date fiable : ne jamais expirer sur une donnée absente

        return ( current_time( 'timestamp' ) - $ts ) > ( BookingStatus::expiry_days() * DAY_IN_SECONDS );
    }

    private function check_duplicate( BookingContext $ctx ): bool {
        if ( empty( $ctx->appointment_datetime ) ) return false;
        foreach ( $ctx->child_ids as $child_id ) {
            if ( $this->repository->find_duplicate( $child_id, $ctx->event_id, $ctx->appointment_datetime ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Cherche un booking pending récent (< 10 min) pour le même user/session
     * ET LE MÊME ENFANT.
     *
     * PHASE 2 — correction : l'ancienne requête (user + session, LIMIT 1)
     * réutilisait le booking de l'enfant A pour une demande de l'enfant B
     * faite dans les 10 minutes. Le jeu d'enfants doit maintenant être
     * identique. Le reste (fenêtre 10 min, post_status 'pending', user, session)
     * est inchangé : l'ancien flux SSA fonctionne comme avant. Ne cible jamais
     * les bookings du nouveau workflow (post_status 'rk_awaiting').
     */
    private function find_recent_pending( BookingContext $ctx ): int {
        global $wpdb;
        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT p.ID
               FROM {$wpdb->posts} p
         INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
                                        AND m.meta_key = '_rk_session_id'
                                        AND m.meta_value = %d
              WHERE p.post_type   = 'rk_booking'
                AND p.post_status = 'pending'
                AND p.post_author = %d
                AND p.post_date  >= DATE_SUB(NOW(), INTERVAL 10 MINUTE)
              ORDER BY p.post_date DESC
              LIMIT 10",
            $ctx->session_id,
            $ctx->user_id
        ) );

        $wanted = array_values( array_unique( array_map( 'intval', $ctx->child_ids ) ) );
        sort( $wanted );

        foreach ( (array) $ids as $id ) {
            $have = array_values( array_unique( $this->repository->get_child_ids( (int) $id ) ) );
            sort( $have );
            if ( $have === $wanted ) {
                return (int) $id;
            }
        }
        return 0;
    }

    private function save_meta( int $id, BookingContext $ctx ): void {
        update_post_meta( $id, '_rk_child_ids',            $ctx->child_ids );
        update_post_meta( $id, '_rk_event_id',             $ctx->event_id );
        update_post_meta( $id, '_rk_event_name',           $ctx->event_name );
        update_post_meta( $id, '_rk_session_name',         $ctx->session_name );
        update_post_meta( $id, '_rk_appointment_id',       $ctx->appointment_id );
        update_post_meta( $id, '_rk_appointment_datetime', $ctx->appointment_datetime );
        update_post_meta( $id, '_rk_credits_used',         $ctx->credits_used );
        update_post_meta( $id, '_rk_program_id',           $ctx->program_id );
        update_post_meta( $id, '_rk_adventure_id',         $ctx->adventure_id );
        update_post_meta( $id, '_rk_session_id',           $ctx->session_id );
        update_post_meta( $id, '_rk_credits_deducted',     '0' );
        update_post_meta( $id, '_rk_credits_refunded',     '0' );
    }

    /**
     * BUG-4 FIX : credits_used = $ctx->credits_used (non plus hardcodé à 1).
     * Chaque ligne de la table rk_bookings correspond à un enfant ; le total
     * de crédits consommés par cet enfant = credits_used du contexte (en général 1,
     * sauf configuration future).
     */
    /**
     * Résout le nom du coach depuis staff_ids de l'appointment SSA — même
     * mécanisme fiable que RescheduleAjax::coach_name_for_staff_id() : les
     * coachs sont de simples utilisateurs WordPress, get_userdata() ne
     * dépend d'aucun markup HTML capturé côté client (contrairement à
     * l'ancienne priorité event_name/coach, voir doc dans
     * confirm_from_ssa_locked() ci-dessus).
     *
     * @return string Nom du coach, ou '' si indisponible (SSA inactif,
     *                 appointment sans staff assigné, coach sans compte
     *                 WordPress) — jamais un nom inventé.
     */
    private function resolve_coach_name_from_staff_ids( int $appointment_id ): string {
        if ( $appointment_id <= 0 ) return '';

        try {
            $appt      = DB::get_ssa_appointment_array( $appointment_id );
            $staff_ids = $appt['staff_ids'] ?? [];
            $staff_ids = is_array( $staff_ids ) ? $staff_ids : [ $staff_ids ];

            if ( empty( $staff_ids ) ) return '';

            $uid  = (int) reset( $staff_ids );
            $user = $uid > 0 ? get_userdata( $uid ) : false;
            return $user ? $user->display_name : '';
        } catch ( \Throwable $e ) {
            rk_log( 'BOOKING', 'resolve_coach_name_from_staff_ids: ' . $e->getMessage(), 'warning' );
            return '';
        }
    }

    private function insert_to_rk_bookings(
        int            $booking_post_id,
        BookingContext $ctx,
        int            $appointment_id,
        int            $appointment_type_id,
        string         $datetime,
        string         $coach = ''
    ): void {
        global $wpdb;
        $table = $wpdb->prefix . 'rk_bookings';

        $session_name_to_store = ! empty( $ctx->session_name )
            ? $ctx->session_name
            : $ctx->event_name;

        foreach ( $ctx->child_ids as $child_id ) {
            $existing_id = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$table} WHERE booking_id=%d AND child_id=%d LIMIT 1",
                $appointment_id, $child_id
            ) );

            // CORRECTION (bug signalé — nom du coach jamais affiché malgré
            // une chaîne de synchronisation correcte en amont) — cause
            // racine réelle : SSA déclenche souvent PLUSIEURS hooks natifs
            // en succession rapide pour le même rendez-vous ('booked' PUIS
            // 'updated', voir doc de BookingHooks::on_ssa_updated() —
            // "SSA envoie updated juste après booked"). confirm_from_ssa()
            // est donc appelée plusieurs fois pour le même appointment_id,
            // et l'ancien code SAUTAIT purement et simplement l'insertion
            // dès qu'une ligne existait déjà (if ($exists) continue;) —
            // y compris pour mettre à jour `coach`. Si le PREMIER de ces
            // appels arrivait avant que rk_set_booking_event_name (voir
            // booking-ssa.js::_syncEventName) n'ait eu le temps d'écrire
            // _rk_event_name, la ligne SQL se figeait alors définitivement
            // avec coach='' — même si event_name arrivait juste après et
            // même si un appel ultérieur de confirm_from_ssa() (le
            // 'updated' suivant 'booked') relisait bien la bonne valeur
            // depuis $ctx->event_name : rien n'était jamais réécrit.
            //
            // Une ligne déjà existante est désormais MISE À JOUR plutôt que
            // sautée : idempotent par construction (mêmes valeurs si rien
            // n'a changé), et permet à `coach` — ainsi qu'aux autres champs
            // pouvant légitimement se préciser entre deux appels successifs
            // (session_name, appointment) — de finir par refléter l'état le
            // plus à jour du contexte, peu importe lequel des hooks SSA a
            // gagné la course en premier.
            if ( $existing_id ) {
                $wpdb->update(
                    $table,
                    [
                        'session_name' => $session_name_to_store,
                        'appointment'  => $datetime ?: null,
                        'coach'        => $coach,
                        'updated_at'   => current_time( 'mysql' ),
                    ],
                    [ 'id' => $existing_id ],
                    [ '%s', '%s', '%s', '%s' ],
                    [ '%d' ]
                );
                continue;
            }

            $wpdb->insert( $table, [
                'booking_id'          => $appointment_id,
                'user_id'             => $ctx->user_id,
                'program_id'          => $ctx->program_id,
                'course_id'           => $ctx->adventure_id,
                'session_name'        => $session_name_to_store,
                'child_id'            => $child_id,
                'appointment_type_id' => $appointment_type_id,
                'appointment'         => $datetime ?: null,
                'booking_date'        => current_time( 'mysql' ),
                'status'              => 'confirmed',
                'credits_used'        => (int) $ctx->credits_used, // BUG-4 FIX : était hardcodé à 1
                // AJOUT (demande utilisateur — afficher le nom du coach) —
                // la colonne `coach` existait déjà dans le schéma (voir
                // Install.php) mais n'était jamais écrite ici. Alimentée
                // désormais depuis $ctx->event_name (synchronisé côté client
                // par booking-ssa.js::_syncEventName(), voir doc plus haut)
                // avec repli sur SSAIntegration::extract_coach_name() —
                // '' si aucune des deux sources n'a rien fourni, jamais une
                // valeur devinée.
                'coach'               => $coach,
                'meta_source'         => 'wizard',
                'created_at'          => current_time( 'mysql' ),
                'updated_at'          => current_time( 'mysql' ),
            ], [ '%d','%d','%d','%d','%s','%d','%d','%s','%s','%s','%d','%s','%s','%s','%s' ] );
        }
    }

    private function err( string $msg ): array {
        return [ 'success' => false, 'booking_id' => 0, 'message' => $msg ];
    }
}