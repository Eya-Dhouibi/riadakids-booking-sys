<?php
/**
 * RiadaKids\Ajax\DashboardAjax — v25.1 AUDIT FIX
 *
 *
 * @package RiadaKids\Ajax
 */

namespace RiadaKids\Ajax;

use RiadaKids\Core\Security;
use RiadaKids\Frontend\Dashboard;

if ( ! defined( 'ABSPATH' ) ) exit;

class DashboardAjax {

    /**
     * AJOUT — dépendance optionnelle vers Frontend\Dashboard, utilisée
     * exclusivement par handle_get_booking_card() pour réutiliser
     * Dashboard::render_booking_card() (même markup exact que la liste
     * rendue au chargement de page — voir sa doc). Nullable pour ne rien
     * casser si jamais DashboardAjax était instanciée ailleurs sans cette
     * dépendance (tests, etc.) : le endpoint échouerait proprement plutôt
     * que fatal.
     */
    public function __construct( private readonly ?Dashboard $dashboard = null ) {
        // NOTE — les actions d'édition d'une réservation ont été déplacées
        // dans RiadaKids\Ajax\BookingEditAjax, propriétaire unique de ce
        // flux. Les garder ici aurait produit deux implémentations
        // divergentes de la même règle métier.
        add_action( 'wp_ajax_rk_load_courses',  [ $this, 'load_courses' ] );
        add_action( 'wp_ajax_rk_load_sessions', [ $this, 'load_sessions' ] );
        add_action( 'wp_ajax_rk_set_timezone', [ $this, 'set_timezone' ] );
        add_action( 'wp_ajax_rk_get_booking_card', [ $this, 'handle_get_booking_card' ] );
    }

    /* ── Cours d'un programme ──────────────────────────────────── */

    public function load_courses(): void {
        Security::verify_booking_request();

        $program = absint( $_POST['program'] ?? 0 );
        if ( ! $program ) {
            echo '<div class="rk-empty">برنامج غير صحيح</div>';
            wp_die();
        }

        $products = get_posts( [
            'post_type'      => 'courses',
            'posts_per_page' => -1,
            'post_status'    => 'publish',
            'tax_query'      => [ [
                'taxonomy' => 'course-category',
                'field'    => 'term_id',
                'terms'    => $program,
            ] ],
            'orderby' => 'title',
            'order'   => 'ASC',
        ] );

        if ( empty( $products ) ) {
            echo '<div class="rk-empty">لا توجد دورات متاحة لهذا البرنامج</div>';
            wp_die();
        }

        foreach ( $products as $p ) {
            $short_desc = wp_strip_all_tags( get_the_excerpt( $p->ID ) );
            if ( empty( $short_desc ) ) {
                $short_desc = __( 'دورة تعليمية', 'riada-kids' );
            }
            printf(
                '<label class="rk-option-card">
                    <input type="radio" name="rk_course" value="%1$s" data-label="%2$s">
                    <div class="rk-option-content" style="display:flex;align-items:center;gap:12px;">
                        <div class="rk-option-icon">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#0065BF" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"></path>
                                <path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"></path>
                            </svg>
                        </div>
                        <div style="text-align:right;">
                            <strong style="display:block;font-size:14px;color:#1a1a1a;margin-bottom:2px;">%3$s</strong>
                            <small>%4$s</small>
                        </div>
                    </div>
                </label>',
                esc_attr( $p->ID ),
                esc_attr( $p->post_title ),
                esc_html( $p->post_title ),
                esc_html( $short_desc )
            );
        }

        wp_die();
    }

    /* ── Séances d'un cours ────────────────────────────────────── */

    /**
     * BUG-3 FIX : toutes les instructions error_log() et commentaires <!-- RK-DEBUG -->
     * ont été supprimés. Ce code tournait en production et :
     *   - Écrivait les données POST en clair dans debug.log (données personnelles).
     *   - Injectait des commentaires HTML visibles dans les réponses AJAX
     *     (révélait la structure interne aux utilisateurs malveillants).
     */
    public function load_sessions(): void {
        Security::verify_booking_request();

        if ( ! is_user_logged_in() ) {
            echo '<div class="rk-empty">يجب تسجيل الدخول</div>';
            wp_die();
        }

        $course = absint( $_POST['course'] ?? 0 );
        if ( ! $course || get_post_type( $course ) !== 'courses' ) {
            echo '<div class="rk-empty">دورة غير صحيحة</div>';
            wp_die();
        }

        $lessons = \RiadaKids\Core\Helpers::get_tutor_lessons( $course );

        if ( empty( $lessons ) ) {
            echo '<div class="rk-empty">لا توجد حصص متاحة لهذه الدورة</div>';
            wp_die();
        }

        // AJOUT — résout le type de rendez-vous SSA associé à ce cours
        // (table wp_rk_ssa_map). Une seule requête pour toutes les
        // sessions du cours : le mapping est au niveau du cours, pas de
        // la session individuelle.
        //
        // Sans cette valeur, data-ssa-event-id restait absent de chaque
        // carte, donc booking-sessions.js gardait s.event_id = 0, donc
        // l'iframe SSA (booking-ssa.js::_injectIframeParams) ne recevait
        // jamais de paramètre "types" — SSA affichait alors son propre
        // écran de sélection de type/coach (.booking-cards), un écran
        // que RKSSAOverlay ne surveille pas (il n'attend que .appt-select
        // et .time-select). Le poll de _pollForAppRoot() expirait donc
        // sans jamais brancher l'observer, cassant tout le pont JS en
        // aval (autofill du formulaire client, clic natif, détection du
        // succès/échec) — quel que soit le correctif apporté à ces
        // étapes ultérieures. Diagnostic complet effectué en conditions
        // réelles sur riadakids.com le 31/08.
        //
        // get_ssa_type_for_course() retourne 0 si aucun mapping n'existe
        // encore pour ce cours dans wp_rk_ssa_map : dans ce cas
        // data-ssa-event-id="0" est rendu, exactement le comportement
        // observé avant ce correctif — aucune régression pour les cours
        // pas encore mappés, ils continuent de dépendre de l'écran natif
        // SSA comme avant.
        $ssa_event_id = \RiadaKids\Database\DB::get_ssa_type_for_course( $course );

        foreach ( $lessons as $lesson ) {
            $plain = (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( $lesson->post_content ) );
            $desc  = mb_strlen( $plain ) > 100 ? mb_substr( $plain, 0, 100 ) . '…' : $plain;

            // Icône dynamique : image mise en avant de la leçon (Tutor LMS),
            // avec repli sur une icône générique si la leçon n'en a pas.
            $thumb = get_the_post_thumbnail_url( $lesson->ID, 'thumbnail' );
            $icon  = $thumb ?: 'https://riadakids.com/wp-content/uploads/2026/08/Group-153.svg';

            // course_id=0 → session_id = $lesson->ID (Tutor lesson ID used directly)
            $this->render_session_option( $lesson->post_title, $desc, $icon, 0, $lesson->ID, $ssa_event_id );
        }

        wp_die();
    }

    /* ── Rendu HTML d'une séance ────────────────────────────────── */

    private function render_session_option(
        string $title,
        string $goal,
        string $icon_url,
        int    $course_id     = 0,
        int    $session_index = 1,
        int    $ssa_event_id  = 0
    ): void {
        $session_id = $course_id > 0
            ? ( $course_id * 1000 + $session_index )
            : $session_index;

        printf(
            '<label class="rk-option-card rk-session-card">
                <input type="radio" name="rk_session"
                       value="%1$s"
                       data-label="%2$s"
                       data-session-name="%2$s"
                       data-description="%6$s"
                       data-ssa-event-id="%7$s">
                <div class="rk-option-content rk-session-content">
                    <div class="rk-session-icon">
                        <img src="%5$s" alt="" loading="lazy" decoding="async">
                    </div>
                    <div class="rk-session-text">
                        <strong>%3$s</strong>
                        <small>%4$s</small>
                    </div>
                </div>
            </label>',
            esc_attr( (string) $session_id ),
            esc_attr( $title ),
            esc_html( $title ),
            esc_html( $goal ),
            esc_url( $icon_url ),
            esc_attr( $goal ),
            esc_attr( (string) $ssa_event_id )
        );
    }
    
    /**
     * AJOUT (demande utilisateur) — carte HTML d'UNE SEULE réservation,
     * pour injection instantanée dans "اللقاءات المحجوزة" juste après une
     * confirmation réussie (rk_booking_confirmed_server), sans recharger
     * la page. Voir booking-confirmation.js::bindServerConfirmed(), qui
     * appelle cet endpoint (via _prependBookingCard) juste avant
     * d'afficher le popup succès.
     *
     * Requête la même ligne wp_rk_bookings (+ jointure enfant) que
     * Dashboard::render() pour l'historique complet — un seul WHERE en
     * plus sur rb.id — puis délègue à Dashboard::render_booking_card()
     * pour produire EXACTEMENT le même markup qu'au chargement de page :
     * aucune divergence possible entre les deux chemins de rendu.
     *
     * $_POST['appointment_id'] est l'ID de rendez-vous SSA — c'est LUI qui
     * remplit la colonne booking_id de wp_rk_bookings (voir
     * BookingService::insert_to_rk_bookings(), $wpdb->insert(['booking_id'
     * => $appointment_id, ...])), PAS le CPT rk_booking (post_id). Le
     * frontend connaît déjà cette valeur : RKBookingState.appointment_id,
     * posée par bindSsaAppointmentEvent() dès le vrai postMessage SSA
     * (voir booking-ssa.js) — disponible avant même que le polling ne
     * confirme, donc certainement présente au moment de cet appel
     * (déclenché par rk_booking_confirmed_server, qui arrive après).
     */
    public function handle_get_booking_card(): void {
        Security::verify_booking_request();

        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            wp_send_json_error( [ 'message' => 'يجب تسجيل الدخول' ], 401 );
        }

        $appointment_id = (int) ( $_POST['appointment_id'] ?? 0 );
        if ( $appointment_id <= 0 || ! $this->dashboard ) {
            wp_send_json_error( [ 'message' => 'appointment_id manquant' ] );
        }

        global $wpdb;
        $bk = $wpdb->get_row( $wpdb->prepare(
            "SELECT rb.*, rc.child_name, rc.child_family_name, rc.child_age
               FROM {$wpdb->prefix}rk_bookings rb
               LEFT JOIN {$wpdb->prefix}rk_children rc ON rc.id = rb.child_id
              WHERE rb.booking_id = %d AND rb.user_id = %d
              ORDER BY rb.created_at DESC
              LIMIT 1",
            $appointment_id, $user_id
        ) );

        if ( ! $bk ) {
            // Cas normal juste après confirm_from_ssa() si le webhook SQL
            // n'a pas encore committé au moment exact de cet appel (rare,
            // course bénigne) — le frontend garde alors simplement
            // l'ancienne liste jusqu'au prochain rechargement naturel de
            // la page, aucune donnée n'est perdue (voir booking-confirmation.js).
            wp_send_json_error( [ 'message' => 'الحجز غير موجود بعد' ] );
        }

        ob_start();
        $this->dashboard->render_booking_card( $bk );
        $html = ob_get_clean();

        wp_send_json_success( [ 'html' => $html ] );
    }

    public function set_timezone(): void {
        // Accepte les deux nonces : wizard de réservation (rkConfig) et dashboard (rkDash)
        $ok = check_ajax_referer( 'rk_booking_nonce', 'nonce', false )
           || check_ajax_referer( 'rk_dashboard_nonce', 'nonce', false );
        if ( ! $ok ) wp_send_json_error( [ 'message' => 'bad_nonce' ], 403 );

        $uid = get_current_user_id();
        if ( ! $uid ) wp_send_json_error( [ 'message' => 'not_logged_in' ], 403 );

        $tz = sanitize_text_field( $_POST['tz'] ?? '' );
        if ( ! in_array( $tz, timezone_identifiers_list(), true ) ) {
            wp_send_json_error( [ 'message' => 'invalid_tz' ], 400 );
        }
        update_user_meta( $uid, 'rk_customer_timezone', $tz );
        wp_send_json_success();
    }
}