<?php
/**
 * RiadaKids\Frontend\Dashboard — v3.3 CORRECTION BUG-4
 *
 * @package RiadaKids\Frontend
 */

namespace RiadaKids\Frontend;

use RiadaKids\Booking\BookingService;
use RiadaKids\Credits\CreditRepository;
use RiadaKids\Children\ChildRepository;
use RiadaKids\Core\Security;

if ( ! defined( 'ABSPATH' ) ) exit;

class Dashboard {

    public function __construct( private readonly BookingService $service ) {
        add_filter( 'woocommerce_get_query_vars',                [ $this, 'add_query_var' ] );
        add_filter( 'woocommerce_account_menu_items',            [ $this, 'add_menu_item' ] );
        add_action( 'woocommerce_account_book-session_endpoint', [ $this, 'render' ] );
    }

    public function add_query_var( array $vars ): array {
        $vars['book-session'] = 'book-session';
        return $vars;
    }

    public function add_menu_item( array $items ): array {
        $logout = $items['customer-logout'] ?? null;
        unset( $items['customer-logout'] );
        $items['book-session'] = 'احجز لقاءك';
        if ( $logout ) $items['customer-logout'] = $logout;
        return $items;
    }

    public function render(): void {
        if ( ! is_user_logged_in() ) {
            wc_add_notice( 'يجب تسجيل الدخول أولاً.', 'error' );
            wp_safe_redirect( wc_get_page_permalink( 'myaccount' ) );
            exit;
        }

        global $wpdb;
        $user_id  = get_current_user_id();
        $credits  = CreditRepository::get_balance( $user_id );
        $children = ChildRepository::get_by_user( $user_id );

        $programs = [];
        $all = get_terms( [ 'taxonomy' => 'course-category', 'hide_empty' => false, 'parent' => 0 ] );
        if ( ! is_wp_error( $all ) ) {
            foreach ( $all as $p ) {
                $programs[] = $p;
            }
        }

        // Historique des réservations depuis la table SQL
        $user_bookings = $wpdb->get_results( $wpdb->prepare(
            "SELECT rb.*, rc.child_name, rc.child_family_name, rc.child_age
               FROM {$wpdb->prefix}rk_bookings rb
               LEFT JOIN {$wpdb->prefix}rk_children rc ON rc.id = rb.child_id
              WHERE rb.user_id = %d
              ORDER BY rb.created_at DESC",
            $user_id
        ) ) ?: [];

        $this->render_view_switcher( $credits );

        echo '<div class="rk-booking-view rk-booking-view-form" data-view="form">';
        BookingForm::render_static(
            $user_id, $credits, $credits >= 1,
            $programs, $children,
            $user_bookings
        );
        echo '</div>';

        echo '<div class="rk-booking-view rk-booking-view-list is-hidden" data-view="list">';
        $this->render_history( $user_bookings, $user_id, $children );
        echo '</div>';
    }

    /**
     * PHASE 1 + 2 — Header commun (titre + switcher Form/Liste).
     *
     * Fusionne ce qui était deux blocs séparés (titre dans BookingForm.php,
     * switcher ici) pour permettre l'alignement sur une même ligne en
     * desktop tel que le Figma : titre à droite, boutons à gauche (RTL).
     * En mobile, le switcher passe en dessous, pleine largeur, boutons
     * côte à côte (voir .rk-page-toprow / .rk-booking-view-switcher en CSS).
     *
     * $credits n'est utilisé ici que pour garder #rk-credits-display
     * (cible réelle de booking-credits.js) disponible dès le chargement
     * de la page, avant même que BookingForm ne soit rendu — évite tout
     * flash sans cible JS si un script s'exécute très tôt.
     */
    private function render_view_switcher( int $credits ): void {
        $title_icon_url = RK_PLUGIN_URL . 'assets/images/rk-page-title-icon.svg';
        ?>
        <div class="rk-page-header" dir="rtl">
            <div class="rk-page-toprow">
                <div class="rk-page-title-group">
                    <h2 class="rk-page-title">جدول اللقاءات</h2>
                    <img class="rk-page-title-icon" src="<?php echo esc_url( $title_icon_url ); ?>" alt="" aria-hidden="true" width="40" height="40">
                </div>

                <div class="rk-booking-view-switcher">
                    <button type="button" class="rk-booking-view-tab is-active" data-rk-view="form">
                        <svg class="rk-icon" width="15" height="15" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                            <g clip-path="url(#rk-tab-icon-edit)">
                                <path d="M11.1668 1.66666H5.00016C4.55814 1.66666 4.13421 1.84226 3.82165 2.15482C3.50909 2.46738 3.3335 2.8913 3.3335 3.33333V16.6667C3.3335 17.1087 3.50909 17.5326 3.82165 17.8452C4.13421 18.1577 4.55814 18.3333 5.00016 18.3333H15.0002C15.4422 18.3333 15.8661 18.1577 16.1787 17.8452C16.4912 17.5326 16.6668 17.1087 16.6668 16.6667V10.5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                <path d="M1.6665 5H4.99984" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                <path d="M1.6665 8.33333H4.99984" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                <path d="M1.6665 11.6667H4.99984" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                <path d="M1.6665 15H4.99984" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                <path d="M17.8151 4.68833C18.1471 4.35637 18.3336 3.90613 18.3336 3.43667C18.3336 2.9672 18.1471 2.51696 17.8151 2.185C17.4832 1.85304 17.0329 1.66654 16.5635 1.66654C16.094 1.66654 15.6438 1.85304 15.3118 2.185L11.1368 6.36167C10.9387 6.55968 10.7937 6.80445 10.7151 7.07333L10.0176 9.465C9.99673 9.53671 9.99547 9.61272 10.014 9.68508C10.0326 9.75743 10.0702 9.82348 10.123 9.87629C10.1758 9.92911 10.2419 9.96676 10.3142 9.9853C10.3866 10.0038 10.4626 10.0026 10.5343 9.98167L12.926 9.28417C13.1949 9.20565 13.4396 9.06063 13.6376 8.8625L17.8151 4.68833Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            </g>
                            <defs><clipPath id="rk-tab-icon-edit"><rect width="20" height="20" fill="white"/></clipPath></defs>
                        </svg>
                        حجز لقاء
                    </button>
                    <button type="button" class="rk-booking-view-tab" data-rk-view="list">
                        <svg class="rk-icon" width="15" height="15" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                            <path d="M13.3333 10.8333L17.6858 13.735C17.7485 13.7768 17.8214 13.8007 17.8967 13.8043C17.972 13.8079 18.0468 13.791 18.1133 13.7554C18.1797 13.7199 18.2352 13.6669 18.274 13.6023C18.3127 13.5376 18.3332 13.4637 18.3333 13.3883V6.55833C18.3333 6.48502 18.314 6.41299 18.2772 6.34954C18.2405 6.28608 18.1877 6.23343 18.1241 6.19691C18.0606 6.16039 17.9885 6.14129 17.9152 6.14154C17.8419 6.14179 17.7699 6.16138 17.7066 6.19833L13.3333 8.75" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                            <path d="M11.6667 5H3.33341C2.41294 5 1.66675 5.74619 1.66675 6.66667V13.3333C1.66675 14.2538 2.41294 15 3.33341 15H11.6667C12.5872 15 13.3334 14.2538 13.3334 13.3333V6.66667C13.3334 5.74619 12.5872 5 11.6667 5Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                        اللقاءات المحجوزة
                    </button>
                </div>
            </div>

            <!-- rk-credits-display conservé (masqué) : cible réelle mise à jour par
                 booking-credits.js. Le crédit visible se trouve dans le Hero
                 via #rk-hero-credits-display. -->
            <span id="rk-credits-display" class="rk-visually-hidden"><?php echo esc_html( number_format( $credits, 0 ) ); ?></span>
        </div>
        <?php
    }

    private function render_history( array $bookings, int $user_id, array $children = [] ): void {
        ?>
        <div id="bookings-all" class="rk-card rk-bookings-history" style="margin-top:24px;" dir="rtl">
            <div class="rk-section-header" style="margin-bottom:16px;">
                <h3 style="margin:0;display:flex;align-items:center;gap:7px;"><?php echo \RiadaKids\Core\Icons::get( 'calendar', 18 ); ?> لقاءاتي القادمة</h3>
                <?php if ( ! empty( $bookings ) ) : ?>
                <span class="rk-badge-count"><?php echo count( $bookings ); ?> لقاء</span>
                <?php endif; ?>
            </div>

            <?php /* Un seul message : les deux encadrés répétaient la même règle.
                     Texte aligné sur la règle métier réelle appliquée par
                     BookingService::cancel_from_ssa() : fenêtre de 24h
                     démarrant à la RÉSERVATION (pas au rendez-vous), avec
                     remboursement intégral si annulé/modifié dans ce délai.
                     AJUSTEMENT (demande utilisateur, specs Figma exactes) —
                     style déplacé du CSS inline vers .rk-bookings-info-banner
                     (voir layout/_bookings-history.scss), texte reformulé
                     plus court. */ ?>
            <div class="rk-alert rk-bookings-info-banner">
                <span class="rk-bookings-info-banner__icon"><?php echo \RiadaKids\Core\Icons::get( 'clock', 18 ); ?></span>
                <span>
                    يمكن إلغاء الموعد أو تعديله قبل 24 ساعة من موعده، مع استرداد الرصيد تلقائيًا
                </span>
            </div>

            <?php if ( ! empty( $bookings ) ) : ?>
            <?php $this->render_bookings_filter_bar( $children ); ?>
            <?php endif; ?>

            <?php if ( empty( $bookings ) ) : ?>
            <div class="rk-empty-bookings" id="rk-empty-bookings">
                <div class="rk-empty-icon" style="color:#94a3b8;"><?php echo \RiadaKids\Core\Icons::get( 'inbox', 48, [ 'stroke_width' => 1.5 ] ); ?></div>
                <p class="rk-empty-title">لا توجد لقاءات حتى الآن</p>
                <p class="rk-empty-sub">ابدأ بحجز لقاءك الأول لطفلك الآن!</p>
            </div>
            <?php else : ?>
            <div class="rk-bookings-cards" id="rk-bookings-cards">
                        <?php foreach ( $bookings as $bk ) : ?>
                            <?php $this->render_booking_card( $bk ); ?>
                        <?php endforeach; ?>
            </div>
            <div class="rk-bookings-empty-filtered" id="rk-bookings-empty-filtered" hidden>
                <p class="rk-empty-sub">لا توجد لقاءات مطابقة لهذا الفلتر.</p>
            </div>
            <nav class="rk-bookings-pagination" id="rk-bookings-pagination"
                 aria-label="<?php esc_attr_e( 'تصفح صفحات اللقاءات', 'riada-kids' ); ?>" hidden></nav>
            <?php endif; ?>
        </div>

        <?php $this->render_edit_modal(); ?>
        <?php
    }

    /**
     * AJOUT (demande utilisateur) — barre de filtres au-dessus de la liste
     * des réservations : sélecteur "الطفل" (tous les enfants du parent
     * connecté, "الكل" par défaut) + bascule "القادمة" / "السابقة".
     *
     * Filtrage ENTIÈREMENT côté client (voir assets/js/booking-history-
     * filters.js) : chaque carte porte déjà data-rk-child-id et
     * data-rk-upcoming (voir render_booking_card ci-dessous), donc aucun
     * rechargement ni appel AJAX n'est nécessaire pour appliquer un filtre
     * — cohérent avec le choix déjà fait pour l'injection instantanée de
     * carte après confirmation (voir booking-confirmation.js).
     */
    private function render_bookings_filter_bar( array $children ): void {
        ?>
        <div class="rk-bookings-filter-bar" id="rk-bookings-filter-bar">
            <?php
            // AJUSTEMENT (demande utilisateur) — filtre enfant + filtre
            // statut regroupés ensemble (côte à côte, petit gap), pour
            // que le justify-content:space-between du parent
            // .rk-bookings-filter-bar sépare bien ce GROUPE de
            // .rk-time-filter, plutôt que de répartir l'espace également
            // entre les 3 blocs (qui collait visuellement enfant/statut
            // l'un à l'autre — voir capture fournie).
            ?>
            <div class="rk-filters-group">
                <div class="rk-child-filter" id="rk-child-filter">
                    <span class="rk-child-filter-label"><?php esc_html_e( 'الطفل:', 'riada-kids' ); ?></span>
                    <button type="button" class="rk-child-filter-toggle" id="rk-child-filter-toggle"
                            aria-haspopup="listbox" aria-expanded="false">
                        <span id="rk-child-filter-current"><?php esc_html_e( 'الكل', 'riada-kids' ); ?></span>
                        <svg width="12" height="12" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                            <path d="M5 7.5L10 12.5L15 7.5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </button>
                    <ul class="rk-child-filter-menu" id="rk-child-filter-menu" role="listbox" hidden>
                        <li role="option" data-child-id="0" class="rk-child-filter-option is-active"><?php esc_html_e( 'الكل', 'riada-kids' ); ?></li>
                        <?php foreach ( $children as $c ) :
                            $label = trim( (string) $c->child_name . ' ' . (string) ( $c->child_family_name ?? '' ) );
                            if ( '' === $label ) continue;
                        ?>
                        <li role="option" data-child-id="<?php echo (int) $c->id; ?>" class="rk-child-filter-option"><?php echo esc_html( $label ); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>

                <?php
                // AJOUT (demande utilisateur) — filtre par statut, même
                // patron que le filtre enfant ci-dessus (menu déroulant
                // avec option "الكل" + liste, ouverture/fermeture gérées
                // par booking-history-filters.js).
                ?>
                <div class="rk-status-filter" id="rk-status-filter">
                    <span class="rk-child-filter-label"><?php esc_html_e( 'الحالة:', 'riada-kids' ); ?></span>
                    <button type="button" class="rk-child-filter-toggle" id="rk-status-filter-toggle"
                            aria-haspopup="listbox" aria-expanded="false">
                        <span id="rk-status-filter-current"><?php esc_html_e( 'الكل', 'riada-kids' ); ?></span>
                        <svg width="12" height="12" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                            <path d="M5 7.5L10 12.5L15 7.5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </button>
                    <ul class="rk-child-filter-menu" id="rk-status-filter-menu" role="listbox" hidden>
                        <li role="option" data-status="all" class="rk-child-filter-option is-active"><?php esc_html_e( 'الكل', 'riada-kids' ); ?></li>
                        <li role="option" data-status="confirmed" class="rk-child-filter-option"><?php esc_html_e( 'مؤكد', 'riada-kids' ); ?></li>
                        <li role="option" data-status="cancelled" class="rk-child-filter-option"><?php esc_html_e( 'ملغى', 'riada-kids' ); ?></li>
                    </ul>
                </div>
            </div>

            <div class="rk-time-filter" id="rk-time-filter" role="tablist">
                <button type="button" class="rk-time-filter-tab is-active" data-time-filter="upcoming" role="tab" aria-selected="true">
                    <?php esc_html_e( 'القادمة', 'riada-kids' ); ?>
                </button>
                <button type="button" class="rk-time-filter-tab" data-time-filter="past" role="tab" aria-selected="false">
                    <?php esc_html_e( 'السابقة', 'riada-kids' ); ?>
                </button>
            </div>
        </div>
        <?php
    }

    /**
     * AJOUT — carte d'une seule réservation, extraite de render_history()
     * pour être réutilisée telle quelle si un futur appelant a besoin du
     * même markup exact (ex. une injection instantanée après confirmation
     * — voir booking-confirmation.js, qui construit son propre HTML
     * client-side plutôt que d'appeler cette méthode, pour éviter tout
     * aller-retour réseau — voir sa doc).
     *
     * @param object $bk Ligne wp_rk_bookings (+ jointure enfant), telle que
     *                   retournée par la requête de render() ci-dessus.
     */
    public function render_booking_card( object $bk ): void {
        // Try course-category first (new), fall back to product_cat (legacy bookings)
        if ( $bk->program_id ) {
            $prog = get_term_field( 'name', $bk->program_id, 'course-category' );
            if ( is_wp_error( $prog ) || empty( $prog ) ) {
                $prog = get_term_field( 'name', $bk->program_id, 'product_cat' );
            }
            $prog = ( ! is_wp_error( $prog ) && ! empty( $prog ) ) ? $prog : '—';
        } else {
            $prog = '—';
        }
        $course = $bk->course_id ? get_the_title( $bk->course_id ) : '—';

        // Image de secours si le cours n'a pas de vignette (même fallback que BookingAjax::get_courses).
        $rk_default_booking_img = 'https://riadakids.com/wp-content/uploads/2026/04/Banner-1.webp';

        // Image du cours (même logique que le wizard de réservation).
        $course_img = $bk->course_id
            ? ( get_the_post_thumbnail_url( $bk->course_id, 'medium' ) ?: $rk_default_booking_img )
            : $rk_default_booking_img;

        // Calcul fenêtre annulation 24h (depuis booking_date = date de création)
        $booking_ts   = ! empty( $bk->booking_date ) ? strtotime( $bk->booking_date ) : 0;
        $elapsed_secs = $booking_ts > 0 ? ( time() - $booking_ts ) : PHP_INT_MAX;
        $can_cancel   = $elapsed_secs < DAY_IN_SECONDS
            && in_array( $bk->status, [ 'confirmed', 'rescheduled' ], true );

        // AJOUT (demande utilisateur — filtre القادمة/السابقة) — un
        // rendez-vous est "à venir" si sa date/heure réelle (celle prise
        // chez le coach, pas la date de création du booking) est dans le
        // futur. Repli sur created_at si appointment est vide (booking
        // encore pending, jamais confirmé par SSA) : dans ce cas il n'y a
        // pas encore de vrai rendez-vous, donc "à venir" par défaut.
        $appointment_ts = ! empty( $bk->appointment ) ? strtotime( (string) $bk->appointment ) : 0;
        $is_upcoming    = $appointment_ts > 0 ? ( $appointment_ts >= time() ) : true;

        // ── BUG-4 FIX : Résolution du nom de séance ─────────────────
        //
        // Ordre de priorité :
        //
        // 1. session_name colonne SQL (depuis v4.2 : contient le nom ACF)
        //    → Pour les NOUVEAUX bookings créés après ce fix.
        //
        // 2. _rk_session_name CPT meta (stocké depuis v4.2 par BookingService)
        //    → Idem : uniquement présent après la mise à jour.
        //
        // 3. Fallback : booking_id → _rk_event_name CPT meta
        //    → Pour les ANCIENS bookings (avant v4.2) : affichera le
        //    titre SSA ("Mentor Ahmed") si session_name était vide.
        //    C'est le meilleur résultat disponible pour l'historique ancien.
        //
        // 4. '—' si aucune donnée.

        $session_display = '';
        $booking_post_id = 0;

        // Priorité 1 : colonne SQL session_name (nom ACF depuis v4.2)
        if ( ! empty( $bk->session_name ) ) {
            $session_display = trim( (string) $bk->session_name );
        }

        // Priorité 2 : CPT meta _rk_session_name (nom ACF depuis v4.2)
        if ( empty( $session_display ) && ! empty( $bk->booking_id ) ) {
            // booking_id dans la table SQL = appointment_id SSA
            // On doit retrouver le CPT via _rk_appointment_id
            $booking_post_id = $this->find_booking_post_by_appointment( (int) $bk->booking_id );
            if ( $booking_post_id ) {
                $session_display = (string) get_post_meta( $booking_post_id, '_rk_session_name', true );
            }
        }

        // Priorité 3 : _rk_event_name CPT (titre SSA — fallback anciens bookings)
        if ( empty( $session_display ) && ! empty( $bk->booking_id ) ) {
            if ( empty( $booking_post_id ) ) {
                $booking_post_id = $this->find_booking_post_by_appointment( (int) $bk->booking_id );
            }
            if ( $booking_post_id ) {
                $session_display = (string) get_post_meta( $booking_post_id, '_rk_event_name', true );
            }
        }

        // Fallback final
        if ( empty( $session_display ) ) {
            $session_display = '—';
        }

        // Nom complet : prénom + nom de famille
        $child_name = trim(
            (string) ( $bk->child_name ?? '' ) . ' ' .
            (string) ( $bk->child_family_name ?? '' )
        ) ?: '—';

        // AJUSTEMENT (bug signalé — nom du coach vide sur des cartes déjà
        // en base) — la colonne `coach` en base reste prioritaire (déjà
        // fiabilisée à l'écriture, voir BookingService::
        // resolve_coach_name_from_staff_ids()) ; si elle est vide (anciennes
        // réservations écrites avant cette correction, ou fenêtre où SSA
        // n'avait pas encore assigné staff_ids au moment du premier
        // webhook), on comble avec le même lookup staff_ids en lecture —
        // jamais une valeur inventée si les deux sources sont vides.
        $coach_from_db = trim( (string) ( $bk->coach ?? '' ) );
        $coach_resolved = $this->resolve_coach_from_staff_ids( (int) ( $bk->booking_id ?? 0 ) );
        $coach_name   = $coach_from_db ?: ( $coach_resolved['name'] ?: '—' );
        // AJUSTEMENT (bug signalé) — retire un éventuel préfixe
        // conversationnel du titre SSA (ex. "أنت تحجز: …") — voir
        // Helpers::clean_coach_name(). Sans effet quand le nom vient déjà
        // de staff_ids (jamais préfixé, c'est un display_name WordPress).
        $coach_name   = '—' === $coach_name ? $coach_name : \RiadaKids\Core\Helpers::clean_coach_name( $coach_name );
        $coach_avatar = $coach_resolved['avatar'];

        // Lien de replanification SSA — utile uniquement
        // tant que la fenêtre de 24 h est ouverte.
        $edit_url = $can_cancel
            ? \RiadaKids\Database\DB::get_ssa_edit_url(
                  (int) ( $bk->booking_id ?? 0 ), 'edit'
              )
            : '';

        // Heure convertie dans le fuseau du client (cf. Core\TimeZone)
        // AJUSTEMENT (demande utilisateur) — format complet jour de
        // AJUSTEMENT (bug signalé — "Tuesday، 22 September 2PM" en
        // anglais au lieu de l'arabe, wp_date() ne traduit jamais selon
        // le fuseau mais selon la locale WORDPRESS DU SITE, souvent pas
        // configurée en arabe) — utilise désormais TimeZone::
        // format_arabic_full() (tables jour/mois codées en dur,
        // indépendantes de toute config serveur), même format complet
        // partout (carte, récapitulatif — demande utilisateur) au lieu
        // du format compact précédent.
        $date = ! empty( $bk->appointment )
            ? \RiadaKids\Core\TimeZone::format_arabic_full(
                  $bk->appointment,
                  (int) ( $bk->booking_id ?? 0 ), get_current_user_id()
              )
            : wp_date( 'j F', strtotime( $bk->created_at ) );

        // AJOUT — icône de catégorie flottante sur l'image (design Figma) :
        // même icône que le badge de programme utilisé à l'écran 1 du
        // wizard (choix البرنامج, voir Icons::program_icon_name() pour le
        // mapping slug → icône, centralisé pour éviter la 4e duplication
        // du même SVG). '' si le programme n'a pas de slug connu — pas de
        // badge affiché plutôt qu'une icône inventée.
        $program_icon_name = '';
        if ( $bk->program_id ) {
            $term = get_term( (int) $bk->program_id, 'course-category' );
            if ( $term && ! is_wp_error( $term ) ) {
                $program_icon_name = \RiadaKids\Core\Icons::program_icon_name( $term->slug );
            }
        }
        ?>

        <article class="rk-booking-card rk-course-card <?php echo $bk->status === 'cancelled' ? 'rk-booking-card--muted' : ''; ?>"
                 data-rk-row="<?php echo (int) $bk->id; ?>"
                 data-rk-child-id="<?php echo (int) $bk->child_id; ?>"
                 data-rk-upcoming="<?php echo $is_upcoming ? '1' : '0'; ?>"
                 data-rk-status="<?php echo esc_attr( $bk->status ); ?>">

            <div class="rk-course-media">
                <img src="<?php echo esc_url( $course_img ); ?>" alt="" class="rk-course-photo" loading="lazy" decoding="async">

                <?php
                // AJOUT — badge de statut (design Figma) : مؤكد ✓ (vert) sur
                // fond de statut 'confirmed'/'rescheduled', ملغى ⊘ (rouge) sur
                // 'cancelled'. Aucun badge pour les autres statuts (pending…)
                // — pas de couleur/texte inventé pour un état non prévu par
                // le design.
                $status_badge = [
                    'confirmed'   => [ 'label' => 'مؤكد', 'icon' => 'check', 'class' => 'is-confirmed' ],
                    'rescheduled' => [ 'label' => 'مؤكد', 'icon' => 'check', 'class' => 'is-confirmed' ],
                    'cancelled'   => [ 'label' => 'ملغى', 'icon' => 'x-circle', 'class' => 'is-cancelled' ],
                ][ $bk->status ] ?? null;
                ?>
                <?php if ( $status_badge ) : ?>
                <span class="rk-course-status-badge <?php echo esc_attr( $status_badge['class'] ); ?>">
                    <?php echo \RiadaKids\Core\Icons::get( $status_badge['icon'], 13 ); ?>
                    <?php echo esc_html( $status_badge['label'] ); ?>
                </span>
                <?php endif; ?>

                <?php if ( $program_icon_name ) : ?>
                <span class="rk-course-category-icon" aria-hidden="true">
                    <?php echo \RiadaKids\Core\Icons::get( $program_icon_name, 16 ); ?>
                </span>
                <?php endif; ?>
            </div>

            <div class="rk-course-content rk-bc-content">
                <div class="rk-course-text">
                    <strong data-rk-field="course"><?php echo esc_html( $course ); ?></strong>
                    <p class="rk-bc-sub" data-rk-field="session"><?php echo esc_html( $session_display ); ?></p>

                    <div class="rk-bc-meta">
                        <?php
                        // AJUSTEMENT (demande utilisateur) — coach affiché
                        // AVANT la date (ordre inversé par rapport à
                        // avant), icône calendrier (au lieu de clock) pour
                        // la date.
                        ?>
                        <span class="rk-bc-meta-item rk-bc-coach">
                            <?php if ( $coach_avatar ) : ?>
                                <img src="<?php echo esc_url( $coach_avatar ); ?>" alt="" class="rk-bc-coach-avatar" loading="lazy">
                            <?php else : ?>
                                <?php echo \RiadaKids\Core\Icons::get( 'graduation-cap', 14 ); ?>
                            <?php endif; ?>
                            <span data-rk-field="coach"><?php echo esc_html( $coach_name ); ?></span>
                        </span>
                        <span class="rk-bc-meta-item">
                            <?php echo \RiadaKids\Core\Icons::get( 'calendar', 14 ); ?>
                            <span data-rk-field="date"><?php echo esc_html( $date ); ?></span>
                        </span>
                        <?php
                        // AJUSTEMENT (demande utilisateur) — l'item "enfant"
                        // visible sur la carte est retiré (remplacé par l'item
                        // coach ci-dessus), mais le champ data-rk-field="child"
                        // doit rester présent dans le DOM (masqué) : il est lu
                        // par booking-edit.js (préremplissage instantané de la
                        // modale d'édition, voir _("openModal") + mise à jour
                        // après sauvegarde) et par le programme lui-même déjà
                        // visible ailleurs sur la carte (البرنامج, data-rk-field
                        // "program" — conservé pour le même besoin JS, voir
                        // plus bas). Le retirer du DOM casserait silencieusement
                        // ce préremplissage.
                        ?>
                        <span class="rk-visually-hidden" data-rk-field="child"><?php echo esc_html( $child_name ); ?></span>
                        <span class="rk-visually-hidden" data-rk-field="program"><?php echo esc_html( is_wp_error( $prog ) ? '—' : $prog ); ?></span>
                    </div>
                </div>

                <div class="rk-bc-footer">
                    <?php
                    // AJOUT (demande utilisateur) — un seul bouton contextuel en
                    // pied de carte, conforme à la maquette Figma : "تعديل الحجز"
                    // (orange) si la réservation est modifiable (statut
                    // confirmed/rescheduled ET fenêtre 24h ouverte, voir
                    // $can_cancel plus haut dans cette méthode), "حجز مجدد" (pilule
                    // verte) si annulée. Aucun bouton pour les autres statuts
                    // (pending…) — pas d'action inventée pour un état non prévu.
                    //
                    // Le bouton "إلغاء" direct a été retiré de cette carte
                    // compacte (demande utilisateur) : l'annulation reste
                    // accessible depuis l'intérieur de la modale d'édition
                    // elle-même (bouton #rk-cancel-confirm, voir
                    // render_cancel_modal() — ouverte séparément, inchangée).
                    ?>
                    <?php if ( $can_cancel ) : ?>
                        <button type="button"
                                class="rk-btn-edit-booking rk-open-edit-modal"
                                data-row="<?php echo (int) $bk->id; ?>"
                                data-ssa-url="<?php echo esc_url( $edit_url ); ?>">
                            <?php echo \RiadaKids\Core\Icons::get( 'calendar', 13 ); ?> <?php esc_html_e( 'تعديل الحجز', 'riada-kids' ); ?>
                        </button>
                    <?php elseif ( 'cancelled' === $bk->status ) : ?>
                        <button type="button" class="rk-btn-rebook rk-open-rebook-form">
                            <?php echo \RiadaKids\Core\Icons::get( 'check', 12 ); ?> <?php esc_html_e( 'حجز مجدد', 'riada-kids' ); ?>
                        </button>
                    <?php elseif ( in_array( $bk->status, [ 'confirmed', 'rescheduled' ], true ) ) : ?>
                        <?php
                        // AJOUT (demande utilisateur) — 3e état découvert en
                        // test réel : réservation CONFIRMÉE mais dont la
                        // fenêtre de 24h ($can_cancel plus haut) est déjà
                        // expirée. Ni modifiable ni annulée : note visuelle
                        // (pas de bouton cliquable, signe "opposé" de تعديل
                        // الحجز — cadenas plutôt que crayon) au lieu d'un
                        // footer vide.
                        ?>
                        <span class="rk-bc-locked-note">
                            <?php echo \RiadaKids\Core\Icons::get( 'lock', 12 ); ?> <?php esc_html_e( 'انتهت مهلة التعديل', 'riada-kids' ); ?>
                        </span>
                    <?php endif; ?>
                </div>
            </div>
        </article>
        <?php
    }

    /**
     * Modale d'édition d'une réservation.
     *
     * Les listes déroulantes sont chaînées : programme → cours → séance.
     * La DATE n'est pas éditable ici — elle appartient à SSA, la modifier
     * en base laisserait le créneau réservé chez le coach inchangé.
     */
    private function render_edit_modal(): void {
        ?>
        <div id="rk-edit-booking-modal" class="rk-edit-modal-overlay" hidden dir="rtl"
             role="dialog" aria-modal="true" aria-labelledby="rk-edit-modal-title">
            <div class="rk-modal-card rk-reschedule-card">
                <button type="button" class="rk-modal-x" id="rk-edit-modal-close"
                        aria-label="<?php esc_attr_e( 'إغلاق', 'riada-kids' ); ?>">&times;</button>

                <?php
                // AJUSTEMENT (demande utilisateur) — une seule modale, deux
                // vues internes basculées en JS (booking-reschedule.js) :
                // #rk-edit-fields-view (édition programme/cours/séance/enfant,
                // vue par défaut) et #rk-reschedule-fields-view (coach +
                // calendrier + créneaux, cachée dès le départ — voir
                // "hidden" ci-dessous). Remplace l'ancienne architecture à
                // deux modales séparées (#rk-edit-booking-modal puis
                // #rk-reschedule-modal ouverte par-dessus).
                //
                // FIX (bug signalé) — les deux titres restaient visibles en
                // même temps malgré l'attribut hidden : un style="display:
                // flex" INLINE a une spécificité plus élevée que n'importe
                // quelle règle CSS [hidden]{display:none} du navigateur, il
                // gagnait donc systématiquement. Remplacé par une vraie
                // classe .rk-edit-view-title (voir layout/
                // _bookings-history.scss) qui porte sa propre garde
                // &[hidden].
                ?>
                <?php
                // AJUSTEMENT (rapport design — modale 1, section 1.1/1.3)
                // — bandeau supérieur avec badge compte à rebours (coin
                // gauche, pastille orange, ex. "⏱ متبقي 20 ساعة للتعديل")
                // et date/heure du cours (coin droit) — remplace l'ancien
                // texte concaténé unique dans #rk-edit-appointment.
                // AJUSTEMENT (demande utilisateur) — le titre h3 est
                // désormais DANS ce même conteneur flex (aligné avec le
                // badge/la date sur une seule ligne), plus au-dessus en
                // bloc séparé.
                // Uniquement pertinent pour la vue édition : masqué en vue
                // reprogrammation (voir booking-reschedule.js).
                ?>
                <div class="rk-edit-topbar" id="rk-edit-topbar-view">
                    <span class="rk-edit-countdown-badge" id="rk-edit-hours-left" hidden>
                        <?php echo \RiadaKids\Core\Icons::get( 'clock', 13 ); ?>
                        <span id="rk-edit-hours-left-text"></span>
                    </span>
                    <h3 id="rk-edit-modal-title" class="rk-edit-view-title rk-edit-view-title--edit"><?php esc_html_e( 'تعديل الحجز', 'riada-kids' ); ?></h3>
                    <span class="rk-edit-appointment-date" id="rk-edit-appointment">
                        <?php echo \RiadaKids\Core\Icons::get( 'calendar', 13 ); ?>
                        <span id="rk-edit-appointment-text"></span>
                    </span>
                </div>
                <h3 id="rk-reschedule-modal-title" class="rk-edit-view-title rk-edit-view-title--reschedule" hidden>
                    <?php esc_html_e( 'تعديل الموعد', 'riada-kids' ); ?>
                </h3>
                <?php
                // AJUSTEMENT (demande utilisateur) — modale d'annulation
                // fusionnée comme 3e vue de la modale unifiée (plus une
                // modale séparée, voir doc en tête de render_edit_modal()
                // — même architecture que la vue reprogrammation).
                ?>
                <h3 id="rk-cancel-modal-title" class="rk-edit-view-title rk-edit-view-title--cancel" hidden>
                    <?php esc_html_e( 'إلغاء اللقاء؟', 'riada-kids' ); ?>
                </h3>
                <?php
                // AJUSTEMENT (demande utilisateur) — écrans de succès
                // (reprogrammation ET annulation) fusionnés comme 5e/6e
                // vues de la même modale unifiée — plus des modales
                // séparées #rk-reschedule-success-modal/#rk-cancel-
                // success-modal ouvertes par-dessus.
                ?>
                <h3 id="rk-reschedule-success-title" class="rk-edit-view-title rk-edit-view-title--success" hidden>
                    <?php esc_html_e( 'تم تحديث الموعد', 'riada-kids' ); ?>
                </h3>
                <h3 id="rk-cancel-success-title" class="rk-edit-view-title rk-edit-view-title--success" hidden>
                    <?php esc_html_e( 'تم إلغاء اللقاء', 'riada-kids' ); ?>
                </h3>

                <div class="rk-edit-msg" id="rk-edit-msg" hidden></div>

                <div class="rk-edit-body" id="rk-edit-fields-view">

                    <div class="rk-edit-field">
                        <label for="rk-edit-program"><?php esc_html_e( 'البرنامج', 'riada-kids' ); ?></label>
                        <select id="rk-edit-program"></select>
                    </div>

                    <div class="rk-edit-field">
                        <label for="rk-edit-course"><?php esc_html_e( 'الدورة', 'riada-kids' ); ?></label>
                        <select id="rk-edit-course"></select>
                    </div>

                    <div class="rk-edit-field">
                        <label for="rk-edit-session"><?php esc_html_e( 'اللقاء', 'riada-kids' ); ?></label>
                        <select id="rk-edit-session"></select>
                    </div>

                    <div class="rk-edit-field">
                        <label for="rk-edit-child"><?php esc_html_e( 'الطفل', 'riada-kids' ); ?></label>
                        <select id="rk-edit-child"></select>
                    </div>

                    <p class="rk-edit-note" id="rk-edit-note">
                        <?php echo \RiadaKids\Core\Icons::get( 'clock', 14 ); ?> <?php esc_html_e( 'لتغيير التاريخ أو الوقت، استخدم زر «تغيير الموعد» أدناه.', 'riada-kids' ); ?>
                    </p>
                </div>

                <?php
                // AJUSTEMENT (demande utilisateur) — champs coach/calendrier/
                // créneaux déjà présents dans le DOM dès le chargement de la
                // page (hidden), plutôt que construits dynamiquement à
                // l'ouverture : basculés à la place de #rk-edit-note au clic
                // sur "تغيير الموعد" (voir booking-reschedule.js).
                ?>
                <div class="rk-reschedule-body" id="rk-reschedule-fields-view" hidden>
                    <?php
                    // AJUSTEMENT (demande utilisateur) — dropdown "المدرب"
                    // retiré (source de données incertaine, voir bug
                    // signalé — plusieurs types actifs mais un seul
                    // affiché) : remplacé par un simple champ en lecture
                    // seule, affichant le nom de l'événement déjà réservé,
                    // non modifiable. state.typeId reste fixé au type
                    // ACTUEL de la réservation (voir booking-reschedule.js
                    // ::showRescheduleView()) — seule la date/heure change
                    // désormais dans ce flow.
                    ?>
                    <div class="rk-edit-field rk-reschedule-staff-field">
                        <label for="rk-reschedule-staff-display"><?php esc_html_e( 'المدرب', 'riada-kids' ); ?></label>
                        <div class="rk-reschedule-staff-readonly" id="rk-reschedule-staff-display"></div>
                    </div>

                    <?php
                    // AJOUT (demande utilisateur, rapport design §2.4) —
                    // champ "التاريخ" cliquable (comme un input, placeholder
                    // "اختر التاريخ"), ouvre le calendrier en popover juste
                    // en dessous — remplace l'ancien calendrier affiché en
                    // permanence dans le flux normal de la modale.
                    ?>
                    <div class="rk-edit-field rk-reschedule-date-field">
                        <label for="rk-reschedule-date-toggle"><?php esc_html_e( 'التاريخ', 'riada-kids' ); ?></label>
                        <button type="button" class="rk-reschedule-date-input" id="rk-reschedule-date-toggle"
                                aria-haspopup="true" aria-expanded="false">
                            <?php echo \RiadaKids\Core\Icons::get( 'calendar', 16 ); ?>
                            <span id="rk-reschedule-date-toggle-text"><?php esc_html_e( 'اختر التاريخ', 'riada-kids' ); ?></span>
                        </button>

                        <div class="rk-reschedule-calendar-popover" id="rk-reschedule-calendar-popover" hidden>
                            <div class="rk-reschedule-calendar" id="rk-reschedule-calendar">
                                <div class="rk-reschedule-cal-header">
                                    <button type="button" class="rk-reschedule-cal-nav" id="rk-reschedule-cal-prev" aria-label="<?php esc_attr_e( 'الشهر السابق', 'riada-kids' ); ?>">‹</button>
                                    <span id="rk-reschedule-cal-label"></span>
                                    <button type="button" class="rk-reschedule-cal-nav" id="rk-reschedule-cal-next" aria-label="<?php esc_attr_e( 'الشهر التالي', 'riada-kids' ); ?>">›</button>
                                </div>
                                <?php
                                // Noms courts des jours, dimanche→samedi comme
                                // sur la maquette SSA de référence (الأحد en
                                // première colonne côté droit, RTL).
                                $weekdays = [ 'الأحد', 'الإثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت' ];
                                ?>
                                <div class="rk-reschedule-cal-weekdays">
                                    <?php foreach ( $weekdays as $day ) : ?>
                                        <span><?php echo esc_html( $day ); ?></span>
                                    <?php endforeach; ?>
                                </div>
                                <div class="rk-reschedule-cal-grid" id="rk-reschedule-cal-grid"></div>
                            </div>
                        </div>
                    </div>

                    <div class="rk-reschedule-slots" id="rk-reschedule-slots">
                        <p class="rk-reschedule-slots-empty" id="rk-reschedule-slots-empty">
                            <?php esc_html_e( 'اختر يوماً لعرض الأوقات المتاحة', 'riada-kids' ); ?>
                        </p>
                        <ul class="time-listing" id="rk-reschedule-slots-list" hidden></ul>
                    </div>

                    <p class="rk-timezone-row" id="rk-reschedule-timezone"></p>
                </div>

                <?php
                // AJUSTEMENT (demande utilisateur) — vue "إلغاء اللقاء؟"
                // fusionnée ici (illustration + texte narratif dynamique,
                // rempli par booking-edit.js::openCancelView() — voir sa
                // doc). Image avec repli sur icône SVG si le fichier
                // n'existe pas encore (voir Dashboard::image_url_or_empty()).
                $sad_robot_url = $this->image_url_or_empty( 'rk-robot-sad.png' );
                ?>
                <div class="rk-cancel-fields-view" id="rk-cancel-fields-view" hidden>
                    <?php if ( $sad_robot_url ) : ?>
                        <img class="rk-cancel-modal-illustration" src="<?php echo esc_url( $sad_robot_url ); ?>" alt="">
                    <?php else : ?>
                        <?php echo \RiadaKids\Core\Icons::get( 'x-circle', 48 ); ?>
                    <?php endif; ?>
                    <p class="rk-cancel-modal-subtitle" id="rk-cancel-appointment"></p>
                </div>

                <?php
                // AJOUT — écran de succès « تم تحديث الموعد » (Figma),
                // affiché après une reprogrammation réussie. Purement
                // informatif, aucune logique métier : le récapitulatif est
                // injecté depuis la réponse AJAX de RescheduleAjax::
                // ajax_confirm() (voir booking-reschedule.js).
                // AJOUT (demande utilisateur) — illustration horloge
                // orange, même repli propre que les autres illustrations
                // (voir image_url_or_empty()).
                $clock_url = $this->image_url_or_empty( 'rk-clock-success.png' );
                ?>
                <div class="rk-cancel-fields-view" id="rk-reschedule-success-view" hidden>
                    <?php if ( $clock_url ) : ?>
                        <img class="rk-cancel-modal-illustration" src="<?php echo esc_url( $clock_url ); ?>" alt="">
                    <?php else : ?>
                        <?php echo \RiadaKids\Core\Icons::get( 'check-circle', 48 ); ?>
                    <?php endif; ?>
                    <div class="rk-final-summary" id="rk-reschedule-success-summary"></div>
                </div>

                <?php
                // AJOUT — écran de succès « تم إلغاء اللقاء » (Figma),
                // affiché après une annulation réussie. Le message de
                // remboursement automatique reflète la règle métier
                // réellement appliquée par BookingService::
                // cancel_from_ssa() (remboursement si annulé dans les 24h
                // suivant booking_date) — jamais affiché si l'annulation
                // n'a pas donné lieu à un remboursement (voir
                // booking-edit.js, qui lit resp.data.refunded).
                $check_url = $this->image_url_or_empty( 'rk-success-check.svg' );
                ?>
                <div class="rk-cancel-fields-view" id="rk-cancel-success-view" hidden>
                    <?php if ( $check_url ) : ?>
                        <img class="rk-cancel-modal-illustration" src="<?php echo esc_url( $check_url ); ?>" alt="">
                    <?php else : ?>
                        <?php echo \RiadaKids\Core\Icons::get( 'check-circle', 48 ); ?>
                    <?php endif; ?>
                    <p class="rk-cancel-modal-subtitle" id="rk-cancel-success-subtitle"></p>
                </div>

                <div class="rk-edit-actions" id="rk-edit-actions-view">
                    <button type="button" class="rk-btn rk-btn-primary" id="rk-edit-save">
                        <?php esc_html_e( 'حفظ التعديلات', 'riada-kids' ); ?>
                    </button>
                    <button type="button" class="rk-btn rk-btn-outline rk-open-reschedule-modal" id="rk-edit-reschedule-open">
                        <?php echo \RiadaKids\Core\Icons::get( 'clock', 14 ); ?> <?php esc_html_e( 'تغيير الموعد', 'riada-kids' ); ?>
                    </button>
                    <button type="button" class="rk-btn" id="rk-edit-cancel">
                        <?php esc_html_e( 'إلغاء', 'riada-kids' ); ?>
                    </button>
                </div>

                <div class="rk-edit-actions" id="rk-reschedule-actions-view" hidden>
                    <button type="button" class="rk-btn rk-btn-primary" id="rk-reschedule-confirm" disabled>
                        <?php esc_html_e( 'تأكيد الموعد الجديد', 'riada-kids' ); ?>
                    </button>
                    <button type="button" class="rk-btn" id="rk-reschedule-back">
                        <?php esc_html_e( 'تراجع', 'riada-kids' ); ?>
                    </button>
                </div>

                <div class="rk-edit-actions" id="rk-cancel-actions-view" hidden>
                    <button type="button" class="rk-btn rk-btn-danger" id="rk-cancel-confirm">
                        <?php esc_html_e( 'تأكيد الإلغاء', 'riada-kids' ); ?>
                    </button>
                    <button type="button" class="rk-btn rk-btn-secondary" id="rk-cancel-dismiss">
                        <?php esc_html_e( 'تراجع', 'riada-kids' ); ?>
                    </button>
                </div>

                <div class="rk-edit-actions" id="rk-reschedule-success-actions-view" hidden>
                    <button type="button" class="rk-btn rk-btn-secondary" id="rk-reschedule-success-close">
                        <?php // AJUSTEMENT (demande utilisateur) — flèche déplacée avant le texte. ?>
                        ← <?php esc_html_e( 'العودة إلى اللقاءات', 'riada-kids' ); ?>
                    </button>
                </div>

                <div class="rk-edit-actions" id="rk-cancel-success-actions-view" hidden>
                    <a href="#" class="rk-btn rk-btn-primary" id="rk-cancel-success-rebook">
                        <?php esc_html_e( 'حجز لقاء جديد', 'riada-kids' ); ?>
                    </a>
                    <button type="button" class="rk-btn rk-btn-secondary" id="rk-cancel-success-close">
                        <?php esc_html_e( 'العودة إلى اللقاءات', 'riada-kids' ); ?>
                    </button>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Avatar photo du coach assigné à un appointment SSA (design Figma —
     * remplace l'ancienne icône silhouette générique). Résolu via
     * staff_ids de l'appointment (même mécanisme fiable que
     * RescheduleAjax::current_staff_ids() — jamais par correspondance de
     * texte sur le nom, fragile). Cache statique : une seule résolution
     * par appointment_id même si render_booking_card() est appelée
     * plusieurs fois dans la même requête.
     *
     * @return string URL de l'avatar, ou '' si indisponible (SSA inactif,
     *                 appointment sans staff assigné, coach sans compte
     *                 WordPress) — le caller affiche alors une icône de
     *                 repli, jamais une image cassée.
     */
    /**
     * Résout nom + avatar du coach depuis staff_ids de l'appointment SSA —
     * en un seul lookup (même mécanisme fiable que BookingService::
     * resolve_coach_name_from_staff_ids(), voir sa doc). Sert de repli
     * d'affichage pour le NOM quand la colonne `coach` en base est restée
     * vide (réservations créées avant cette correction, ou dont le
     * premier webhook SSA est arrivé avant que staff_ids ne soit assigné
     * — voir la doc de mise à jour idempotente dans
     * BookingService::insert_to_rk_bookings()) : le nom stocké en base
     * reste toujours prioritaire quand il est présent, ce lookup ne
     * comble que le vide.
     *
     * @return array{name: string, avatar: string} Chaînes vides si
     *                 indisponible (SSA inactif, appointment sans staff
     *                 assigné, coach sans compte WordPress) — jamais une
     *                 valeur inventée.
     */
    /**
     * Résout l'URL d'une image dans assets/images/ si le fichier existe,
     * '' sinon — pour un repli propre sur icône SVG (voir render_edit_modal()
     * et render_cancel_success_modal()) tant que l'image n'a pas été
     * ajoutée par la suite dans le dossier.
     */
    private function image_url_or_empty( string $filename ): string {
        $path = RK_PLUGIN_DIR . 'assets/images/' . $filename;
        return file_exists( $path ) ? RK_PLUGIN_URL . 'assets/images/' . $filename : '';
    }

    private function resolve_coach_from_staff_ids( int $appointment_id ): array {
        static $cache = [];

        if ( $appointment_id <= 0 ) return [ 'name' => '', 'avatar' => '' ];
        if ( isset( $cache[ $appointment_id ] ) ) return $cache[ $appointment_id ];

        $result = [ 'name' => '', 'avatar' => '' ];
        try {
            $appt      = \RiadaKids\Database\DB::get_ssa_appointment_array( $appointment_id );
            $staff_ids = $appt['staff_ids'] ?? [];
            $staff_ids = is_array( $staff_ids ) ? $staff_ids : [ $staff_ids ];

            if ( ! empty( $staff_ids ) ) {
                $uid  = (int) reset( $staff_ids );
                $user = $uid > 0 ? get_userdata( $uid ) : false;
                if ( $user ) {
                    $result['name']   = $user->display_name;
                    $result['avatar'] = get_avatar_url( $uid, [ 'size' => 96 ] );
                } elseif ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    rk_log( 'DASHBOARD', "resolve_coach_from_staff_ids: staff_ids=[{$uid}] trouvé pour appointment#{$appointment_id} mais get_userdata() a échoué (pas de compte WP pour cet ID)", 'warning' );
                }
            } elseif ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                rk_log( 'DASHBOARD', "resolve_coach_from_staff_ids: staff_ids vide/absent pour appointment#{$appointment_id} — clés reçues: " . implode( ',', array_keys( $appt ) ), 'warning' );
            }
        } catch ( \Throwable $e ) {
            rk_log( 'DASHBOARD', 'resolve_coach_from_staff_ids: ' . $e->getMessage(), 'warning' );
        }

        $cache[ $appointment_id ] = $result;
        return $result;
    }

    /**
     * Retrouve le CPT rk_booking (post_id) depuis l'appointment_id SSA.
     * Utilise un cache statique pour éviter les requêtes répétées en boucle.
     */
    private function find_booking_post_by_appointment( int $appointment_id ): int {
        static $cache = [];

        if ( $appointment_id <= 0 ) return 0;
        if ( isset( $cache[ $appointment_id ] ) ) return $cache[ $appointment_id ];

        global $wpdb;
        $result = $wpdb->get_var( $wpdb->prepare(
            "SELECT p.ID
               FROM {$wpdb->posts} p
         INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
                                        AND m.meta_key = '_rk_appointment_id'
                                        AND m.meta_value = %d
              WHERE p.post_type = 'rk_booking'
              ORDER BY p.post_date DESC
              LIMIT 1",
            $appointment_id
        ) );

        $cache[ $appointment_id ] = (int) ( $result ?? 0 );
        return $cache[ $appointment_id ];
    }
}