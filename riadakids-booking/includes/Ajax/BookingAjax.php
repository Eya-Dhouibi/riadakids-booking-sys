<?php
/**
 * BookingAjax — Gestionnaire AJAX principal du wizard de réservation.
 * Phase 3.1 : Suppression définitive de rk_get_ssa_event_types et handle_get_ssa_event_types().
 *
 * @package RiadaKids\Ajax
 */

namespace RiadaKids\Ajax;

use RiadaKids\Booking\BookingContext;
use RiadaKids\Booking\BookingService;
use RiadaKids\Booking\SSAIntegration;
use RiadaKids\Credits\CreditRepository;
use RiadaKids\Core\Helpers;
use RiadaKids\Core\Security;

if ( ! defined( 'ABSPATH' ) ) exit;

class BookingAjax {

    public function __construct( private readonly BookingService $service ) {}

    public function register(): void {
        $actions = [
            'rk_get_events'         => 'handle_get_events',
            // rk_get_ssa_event_types supprimé (Phase 3.1) — SSA gère nativement via l'iframe
            'rk_get_courses'        => 'handle_get_courses',
            'rk_get_sessions'       => 'handle_get_sessions',
            'rk_credit_preview'     => 'handle_credit_preview',
            'rk_pre_create_booking' => 'handle_pre_create_booking',
            // rk_create_booking supprimé — flux legacy, remplacé par le
            // clic natif SSA (submitNativeBooking) + confirm_from_ssa().
            // Empêchait une seconde réservation potentielle côté RiadaKids.
            'rk_poll_booking'       => 'handle_poll_booking',
            // rk_save_temp, rk_get_pending, rk_clear_pending supprimés (pas de reprise)
            'rk_set_booking_event_name' => 'handle_set_booking_event_name',
        ];

        foreach ( $actions as $action => $method ) {
            add_action( 'wp_ajax_' . $action, [ $this, $method ] );
        }
        add_action( 'wp_ajax_nopriv_rk_get_events', [ $this, 'handle_get_events' ] );
        // wp_ajax_nopriv_rk_get_ssa_event_types supprimé (Phase 3.1)
    }

    // ── Événements SSA disponibles ───────────────────────────────────────────

    public function handle_get_events(): void {
        Security::verify_booking_request();
        $events = array_map( fn( $e ) => $e->to_array(), SSAIntegration::get_events() );
        wp_send_json_success( [ 'events' => $events ] );
    }

    // ── Cours/Aventures par programme (Étape 2) ──────────────────────────────

public function handle_get_courses(): void {
    Security::verify_booking_request();
    $this->require_login();

    $program_id = (int) ( $_POST['program_id'] ?? 0 );
    if ( $program_id <= 0 ) {
        wp_send_json_error( [ 'message' => 'program_id manquant' ] );
    }

    $query = new \WP_Query( [
        'post_type'      => 'courses',
        'post_status'    => 'publish',
        'posts_per_page' => 50,
        'orderby'        => 'menu_order title',
        'order'          => 'ASC',
        'tax_query'      => [ [
            'taxonomy' => 'course-category',
            'field'    => 'term_id',
            'terms'    => $program_id,
        ] ],
    ] );

    $default_img = 'https://riadakids.com/wp-content/uploads/2026/04/Banner-1.webp';

    $courses = [];
    foreach ( $query->posts as $post ) {
        $terms    = get_the_terms( $post->ID, 'course-category' );
        $cat_name = ( ! empty( $terms ) && ! is_wp_error( $terms ) ) ? $terms[0]->name : '';

        $courses[] = [
            'id'       => $post->ID,
            'title'    => $post->post_title,
            'excerpt'  => wp_strip_all_tags( get_the_excerpt( $post ) ),
            'category' => $cat_name,
            'image'    => get_the_post_thumbnail_url( $post->ID, 'medium' ) ?: $default_img,
        ];
    }

    if ( empty( $courses ) ) {
        wp_send_json_error( [ 'message' => 'لا توجد مغامرات متاحة لهذا البرنامج حالياً.' ] );
    }

    wp_send_json_success( [ 'courses' => $courses ] );
}

    public function handle_get_sessions(): void {
        Security::verify_booking_request();
        $this->require_login();

        $course_id = (int) ( $_POST['course_id'] ?? 0 );
        if ( $course_id <= 0 ) { wp_send_json_error( [ 'message' => 'course_id manquant' ] ); }

        $lessons = Helpers::get_tutor_lessons( $course_id );

        if ( empty( $lessons ) ) {
            wp_send_json_error( [ 'message' => 'لا توجد حصص متاحة لهذه الدورة.' ] );
        }

        $sessions = [];
        foreach ( $lessons as $lesson ) {
            $plain      = (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( $lesson->post_content ) );
            $desc       = mb_strlen( $plain ) > 100 ? mb_substr( $plain, 0, 100 ) . '…' : $plain;
            $sessions[] = [
                'id'          => $lesson->ID,
                'title'       => $lesson->post_title,
                'description' => $desc,
            ];
        }

        wp_send_json_success( [ 'sessions' => $sessions ] );
    }

    // ── Prévisualisation crédits ─────────────────────────────────────────────

    public function handle_credit_preview(): void {
        Security::verify_booking_request();
        $this->require_login();

        $child_ids = $this->child_ids();
        $user_id   = get_current_user_id();
        $avail     = CreditRepository::get_balance( $user_id );
        // 1 réservation = 1 enfant = 1 crédit fixe (mode single-child v8.0)
        $needed    = 1;

        wp_send_json_success( [
            'credits_available' => $avail,
            'credits_needed'    => $needed,
            'credits_after'     => max( 0, $avail - $needed ),
            'sufficient'        => $avail >= $needed,
        ] );
    }

    // ── Pré-création booking (étape 5 — avant confirmation dans l'iframe SSA) ─

    public function handle_pre_create_booking(): void {
        Security::verify_booking_request();
        $this->require_login();

        // ── LOG 1 : Données brutes reçues du POST ──────────────────────────
        $raw = [
            'program_id'   => $_POST['program_id']   ?? 'ABSENT',
            'adventure_id' => $_POST['adventure_id'] ?? 'ABSENT',
            'session_id'   => $_POST['session_id']   ?? 'ABSENT',
            'session_name' => $_POST['session_name'] ?? 'ABSENT',
            'child_ids'    => $_POST['child_ids']    ?? 'ABSENT',
            'event_id'     => $_POST['event_id']     ?? 'ABSENT',
        ];
        rk_log( 'PRE-CREATE', 'POST reçu : ' . wp_json_encode( $raw ) );

        // Fuseau horaire du visiteur (envoyé par booking-ssa.js) — repli si
        // ssa_appointments.customer_timezone reste vide côté SSA.
        $rk_tz = sanitize_text_field( wp_unslash( $_POST['customer_timezone'] ?? '' ) );
        if ( $rk_tz && in_array( $rk_tz, timezone_identifiers_list(), true ) ) {
            update_user_meta( get_current_user_id(), 'rk_customer_timezone', $rk_tz );
        }

        $child_ids = $this->child_ids();

        if ( empty( $child_ids ) ) {
            rk_log( 'PRE-CREATE', 'ERREUR : child_ids vide', 'error' );
            wp_send_json_error( [ 'message' => 'الرجاء اختيار طفل واحد على الأقل' ] );
        }

        // event_id peut être 0 ici — l'utilisateur choisit le conseiller dans l'iframe SSA.
        // BookingHooks::on_ssa_booked() résoudra le booking par UUID après le webhook.
        $event_id = (int) ( $_POST['event_id'] ?? 0 );

        $ctx = BookingContext::from_array( [
            'program_id'   => (int) ( $_POST['program_id']   ?? 0 ),
            'adventure_id' => (int) ( $_POST['adventure_id'] ?? 0 ),
            'session_id'   => (int) ( $_POST['session_id']   ?? 0 ),
            'session_name' => sanitize_text_field( $_POST['session_name'] ?? '' ),
            'child_ids'    => $child_ids,
            'event_id'     => $event_id,
        ] );

        // ── LOG 2 : Contexte généré ────────────────────────────────────────
        rk_log( 'PRE-CREATE', sprintf(
            'BookingContext généré — user_id=%d program_id=%d session_id=%d child_ids=[%s] event_id=%d',
            $ctx->user_id,
            $ctx->program_id,
            $ctx->session_id,
            implode( ',', $ctx->child_ids ),
            $ctx->event_id
        ) );

        // ── LOG 3 : Résultat de la validation ─────────────────────────────
        $valid = $ctx->is_valid_for_pre_create();
        rk_log( 'PRE-CREATE', sprintf(
            'is_valid_for_pre_create() = %s | user_id>0:%s program_id>0:%s session_id>0:%s child_ids_count:%d',
            $valid ? 'TRUE' : 'FALSE',
            $ctx->user_id > 0 ? 'OK' : 'FAIL',
            $ctx->program_id > 0 ? 'OK' : 'FAIL',
            $ctx->session_id > 0 ? 'OK' : 'FAIL',
            count( $ctx->child_ids )
        ), $valid ? 'info' : 'error' );

        $result = $this->service->pre_create_booking( $ctx );
        if ( $result['success'] ) {
            rk_log( 'PRE-CREATE', "Succès — booking_id={$result['booking_id']} booking_uuid={$result['booking_uuid']}" );
            wp_send_json_success( [
                'booking_id'   => $result['booking_id'],
                'booking_uuid' => $result['booking_uuid'],
            ] );
        } else {
            rk_log( 'PRE-CREATE', "ÉCHEC — message={$result['message']}", 'error' );
            wp_send_json_error( [ 'message' => $result['message'] ] );
        }
    }

    // Ancien handle_create_booking() (étape 6, flux legacy) supprimé.
    // La seule voie de création réelle du rendez-vous est désormais :
    //   1. rk_pre_create_booking (CPT pending, avant l'iframe SSA)
    //   2. clic natif SSA (RKSSAOverlay.submitNativeBooking, déclenché
    //      par l'utilisateur via #rk-confirm-booking)
    //   3. BookingService::confirm_from_ssa(), via le hook/webhook SSA
    // Ceci élimine tout chemin permettant une seconde réservation.

    // ── Polling statut ───────────────────────────────────────────────────────

    public function handle_poll_booking(): void {
        Security::verify_booking_request();
        $this->require_login();

        $id   = (int) ( $_POST['booking_id'] ?? 0 );
        $post = $id ? get_post( $id ) : null;
        if ( ! $post || (int) $post->post_author !== get_current_user_id() ) {
            wp_send_json_error( [ 'message' => 'Accès refusé' ] );
        }

        $status = (string) get_post_meta( $id, '_rk_status', true );

        // AJOUT (bug signalé — bouton تعديل الحجز absent sur la carte tant
        // qu'aucun rechargement de page n'a eu lieu) — row_id de
        // wp_rk_bookings (PAS le post_id du CPT ni l'appointment_id SSA),
        // le seul identifiant que .rk-open-edit-modal/.rk-open-cancel-modal
        // savent utiliser (voir Dashboard::render_booking_card(), même
        // data-row). Résolu via _rk_appointment_id (déjà écrit sur ce CPT
        // par BookingService — voir confirm_from_ssa_locked()), qui
        // correspond à la colonne booking_id de wp_rk_bookings.
        $appointment_id = (int) get_post_meta( $id, '_rk_appointment_id', true );
        $row_id = 0;
        if ( $appointment_id > 0 ) {
            global $wpdb;
            $row_id = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}rk_bookings WHERE booking_id = %d AND user_id = %d LIMIT 1",
                $appointment_id,
                get_current_user_id()
            ) );
        }

        wp_send_json_success( [
            'booking_id'       => $id,
            'row_id'           => $row_id,
            'appointment_id'   => $appointment_id,
            'status'           => $status ?: $post->post_status,
            'confirmed'        => $status === 'confirmed',
            'session_name'     => (string) get_post_meta( $id, '_rk_session_name', true ),
            'event_name'       => (string) get_post_meta( $id, '_rk_event_name',   true ),
            'credits_deducted' => get_post_meta( $id, '_rk_credits_deducted', true ) === '1',
        ] );
    }

    // ── Nom du coach (event_name capturé côté SSA) ─────────────────────────────

    /**
     * AJOUT (demande utilisateur — nom du coach sur la carte "لقاءاتي
     * القادمة") — enregistre event_name sur le CPT pending déjà créé par
     * rk_pre_create_booking. Appelée par booking-ssa.js::_syncEventName()
     * à plusieurs points du parcours (voir sa doc), dès que le titre
     * affiché par SSA (.mdc-card-header .md-title, "لقاء مع [Nom]") est
     * rendu et capturé côté client (voir RKSSAOverlay._captureEventName()).
     *
     * '_rk_event_name' est la MÊME meta déjà lue par BookingRepository::
     * load_context() (voir déjà utilisée dans handle_poll_booking()
     * ci-dessus) et déjà écrite par BookingService::save_meta() au moment
     * du pre-create initial — cet appel se contente de la mettre à jour
     * avec la valeur réelle capturée depuis SSA, plus précise que celle
     * connue au moment du pre-create (où l'iframe SSA n'a pas encore
     * affiché son écran). insert_to_rk_bookings() (déclenché plus tard par
     * confirm_from_ssa()) lira cette valeur mise à jour via
     * $ctx->event_name et la persistera dans wp_rk_bookings.coach.
     *
     * CORRECTION (bug signalé — nom du coach jamais affiché malgré une
     * chaîne de synchronisation par ailleurs correcte) — cause racine
     * réelle : SSA déclenche souvent plusieurs hooks natifs en succession
     * rapide pour le même rendez-vous (voir doc de
     * BookingHooks::on_ssa_updated()), donc confirm_from_ssa() —  et donc
     * insert_to_rk_bookings() — peut s'exécuter AVANT que cet appel-ci
     * n'ait eu le temps d'écrire _rk_event_name. Sans écriture directe ici
     * sur la ligne SQL, une confirmation déjà passée resterait figée avec
     * coach='' pour toujours (voir correctif jumeau dans
     * BookingService::insert_to_rk_bookings(), qui gère le sens inverse —
     * confirm_from_ssa() rejouée APRÈS que ce champ soit connu). On met
     * donc à jour directement wp_rk_bookings.coach ici aussi, pour la ligne
     * la plus récente déjà confirmée sous ce booking_id, si elle existe.
     * Écriture idempotente (UPDATE simple, jamais un second appointment
     * créé), sans effet si aucune ligne SQL n'existe encore (cas normal :
     * la confirmation n'a pas encore eu lieu, insert_to_rk_bookings()
     * écrira alors coach directement au bon moment).
     *
     * Même barrière d'appartenance que handle_poll_booking() ci-dessus :
     * post_author doit correspondre à l'utilisateur connecté.
     */
    public function handle_set_booking_event_name(): void {
        Security::verify_booking_request();
        $this->require_login();

        $id   = (int) ( $_POST['booking_id'] ?? 0 );
        $post = $id ? get_post( $id ) : null;
        if ( ! $post || (int) $post->post_author !== get_current_user_id() ) {
            wp_send_json_error( [ 'message' => 'Accès refusé' ] );
        }

        $event_name = sanitize_text_field( wp_unslash( (string) ( $_POST['event_name'] ?? '' ) ) );
        if ( '' === $event_name ) {
            wp_send_json_error( [ 'message' => 'event_name manquant' ] );
        }

        update_post_meta( $id, '_rk_event_name', $event_name );

        // Filet de sécurité — voir doc ci-dessus : met aussi à jour la
        // ligne SQL wp_rk_bookings si confirm_from_ssa() a déjà tourné
        // pour ce booking avant que cet appel n'arrive.
        $appointment_id = (int) get_post_meta( $id, '_rk_appointment_id', true );
        if ( $appointment_id > 0 ) {
            global $wpdb;
            $wpdb->update(
                $wpdb->prefix . 'rk_bookings',
                [ 'coach' => $event_name, 'updated_at' => current_time( 'mysql' ) ],
                [ 'booking_id' => $appointment_id ],
                [ '%s', '%s' ],
                [ '%d' ]
            );
        }

        wp_send_json_success();
    }


    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Retourne l'ID du seul enfant sélectionné (mode single-child v8.0).
     * Pour rétrocompatibilité, si child_ids[] est envoyé, on prend le premier.
     * Jamais plus d'un enfant par réservation.
     */
    private function child_ids(): array {
        if ( isset( $_POST['child_ids'] ) && is_array( $_POST['child_ids'] ) ) {
            $ids = array_values( array_filter( array_map( 'intval', $_POST['child_ids'] ) ) );
            // Mode single-child : on ne retient que le premier
            return ! empty( $ids ) ? [ $ids[0] ] : [];
        }
        $single = (int) ( $_POST['child_id'] ?? 0 );
        return $single > 0 ? [ $single ] : [];
    }

    private function require_login(): void {
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => 'يجب تسجيل الدخول' ], 401 );
        }
    }
}