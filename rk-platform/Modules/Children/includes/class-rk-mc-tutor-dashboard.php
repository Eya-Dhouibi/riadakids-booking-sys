<?php
declare( strict_types=1 );
/**
 * RK_MC_Tutor_Dashboard  (v8.4.0 — Design v4 unifie + Tutor LMS 4.x)
 *
 * Fusionne Tutor LMS + Gamification RiadaKids en un dashboard enfant premium.
 * N'a pas besoin de modifier les fichiers core Tutor LMS.
 *
 * Stratégie :
 *  1. body_class → ajoute rk-child-active (+ rk-td-home sur la page d'accueil)
 *  2. CSS cache TOUT le contenu Tutor par défaut quand rk-child-active
 *  3. tutor_dashboard/before/wrap → Hero full-width premium
 *  4. tutor_before_dashboard_content → Dashboard RK complet
 *  5. tutor_dashboard/after/wrap → Bottom nav mobile Duolingo-style
 *  6. tutor_dashboard/nav_ui_items → Nav enfant simplifiée (7 items max)
 *  7. load_dashboard_template_part_from_other_location → Pages RK custom
 *  8. block_restricted_pages → Blocage URL pour rk_child
 *  9. strip_tutor4_chrome → retire le chrome natif Tutor 4.x (sidebar,
 *     header, cartes d'onboarding) sur la home enfant
 * 10. RK_MC_Dashboard_Chrome_V4 → chrome v4 partage par la home ET les
 *     sous-pages (#rkd4-mobtop · #rkd4-side · #rkd4-botnav)
 *
 * @package RK_My_Children
 * @since   7.1.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/* Traits — factorisation par fonctionnalité (logique inchangée) */
require_once __DIR__ . '/traits/trait-rk-mc-tutor-dash-bootstrap.php';
require_once __DIR__ . '/traits/trait-rk-mc-tutor-dash-chrome.php';
require_once __DIR__ . '/traits/trait-rk-mc-tutor-dash-sections.php';
require_once __DIR__ . '/traits/trait-rk-mc-tutor-dash-nav.php';

/* v8.4 — Chrome v4 partage par la home ET les sous-pages */
require_once __DIR__ . '/class-rk-mc-dashboard-chrome-v4.php';

class RK_MC_Tutor_Dashboard {
    use RK_MC_Tutor_Dash_Bootstrap;
    use RK_MC_Tutor_Dash_Chrome;
    use RK_MC_Tutor_Dash_Sections;
    use RK_MC_Tutor_Dash_Nav;


    /* ── Sous-pages custom RiadaKids ─────────────────────────────────── */
    private static $rk_slugs = array(
        // Pages custom RK (Tutor n'a pas d'équivalent)
        'rk-messages', 'rk-badges', 'rk-skills', 'rk-sessions',
        'rk-certificats', 'rk-parent-board', 'rk-credits',
        'rk-mon-parcours', 'rk-challenges', 'rk-quiz-play',
        // Pages Tutor interceptées — template RK avec logique supplémentaire
        'enrolled-courses',   // Alias legacy Tutor 3.x — conservé pour anciens liens/favoris
        'courses',            // v9.0 — slug natif Tutor LMS 4.x pour l'onglet "mes cours"
                               // (tutor_core-js-extra → "course_slug":"courses"). Même template
                               // que 'enrolled-courses' : la page utilise un filtre catégorie
                               // 100% client (JS), donc aucune distinction active_tab nécessaire
                               // entre les deux slugs. Enfant : filtre par rk_bookings /
                               // Parent : natif Tutor.
        'certificates',       // Intercepté → contexte enfant (slug natif Tutor LMS)
        'question-answer',    // Q&A coach → enfant (questions assignées par le coach)
        'my-quiz-attempts',   // Quiz AYS assignés par le coach (aysquiz_quizes quiz_url='rk:child:N')
        'rk-adventure',       // Page détail d'un cours ("Adventure") — remplace le lien natif
                               // "افتح المغامرة" vers le permalink Tutor, voir
                               // class-rk-mc-adventures-filter-service.php. Paramètre 'course_id'
                               // en query string (?rk_tab=xxx&course_id=NN), lu directement dans
                               // le template — même enfant/session que les autres pages du dashboard.
        // Pages parent exclusives (pas dans Tutor natif)
        'bookings', 'reports',
    );

    /* ── Pages Tutor bloquées pour rk_child ─────────────────────────── */
    private static $blocked_slugs = array(
        'settings', 'purchase_history', 'wishlist', 'my-profile',
        'rk-credits', 'rk-parent-board', 'bookings', 'reports',  // financial/parent data
    );

    /* ── Couleurs par niveau ─────────────────────────────────────────── */
    private static $level_colors = array(
        1 => '#94a3b8', 2 => '#22c55e', 3 => '#3b82f6',
        4 => '#f59e0b', 5 => '#f97316', 6 => '#e11d48',
        7 => '#7c3aed', 8 => '#fbbf24',
    );

    /* ── Cache requête ───────────────────────────────────────────────── */
    private static $child_cache  = null;
    private static $data_cache   = null;
    private static $data_loaded  = false;

    /* ═══════════════════════════════════════════════════════════════════
       ASSETS
       ═══════════════════════════════════════════════════════════════════ */

    public static function enqueue_assets(): void {
        if ( ! function_exists( 'tutor_utils' ) ) return;
        if ( ! tutor_utils()->is_tutor_frontend_dashboard() ) return;

        wp_enqueue_style(
            'rk-dashboard',
            RK_MC_URL . 'assets/css/rk-dashboard.css',
            array(),
            RK_MC_VERSION
        );

        wp_enqueue_style(
            'rk-kids-enhanced',
            RK_MC_URL . 'assets/css/rk-kids-enhanced.css',
            array( 'rk-dashboard' ),
            RK_MC_VERSION
        );

        /* v8.0 — Design v4 (maquettes Homepage desktop + mobile).
         * Chargé en dernier : neutralise l'ancien chrome rkd3 ET le chrome
         * natif Tutor 4.x via body.rkd4-chrome, sur la home comme sur les
         * sous-pages. */
        wp_enqueue_style(
            'rk-dashboard-v4',
            RK_MC_URL . 'assets/css/rk-dashboard-v4.css',
            array( 'rk-kids-enhanced' ),
            RK_MC_VERSION
        );

        // v9.9 — Fondations UX/UI premium (animations, décorations,
        // Empty State générique, FAB) : chargées sur TOUTES les pages du
        // Dashboard, contrairement aux enqueues conditionnelles
        // ci-dessous qui ne servent qu'une page précise.
        wp_enqueue_style(
            'rk-dashboard-enhance',
            RK_MC_URL . 'assets/css/rk-dashboard-enhance.css',
            array( 'rk-dashboard-v4' ),
            RK_MC_VERSION
        );
        wp_enqueue_script(
            'rk-mc-dashboard-enhance',
            RK_MC_URL . 'assets/js/rk-dashboard-enhance.js',
            array(),
            RK_MC_VERSION,
            true
        );

        wp_enqueue_script(
            'rk-mc-child-dashboard',
            RK_MC_URL . 'assets/js/child-dashboard-endpoint.js',
            array(),
            RK_MC_VERSION,
            true
        );

        wp_enqueue_script(
            'rk-mc-tutor-dashboard',
            RK_MC_URL . 'assets/js/tutor-dashboard-rk.js',
            array( 'rk-mc-child-dashboard' ),
            RK_MC_VERSION,
            true
        );

        /* v9.0 — مغامراتي : filtre catégorie + toggle "masquer les
         * complétées". Chargé uniquement sur enrolled-courses/* et courses/*
         * (slug natif Tutor LMS 4.x) pour ne pas alourdir les autres pages
         * du dashboard. */
        global $wp_query;
        $current_slug = (string) ( $wp_query->query_vars['tutor_dashboard_page'] ?? '' );
        if ( str_starts_with( $current_slug, 'enrolled-courses' ) || str_starts_with( $current_slug, 'courses' ) ) {
            wp_enqueue_script(
                'rk-mc-adventures-grid',
                RK_MC_URL . 'assets/js/rk-adventures-grid.js',
                array(),
                RK_MC_VERSION,
                true
            );

            // v9.4 — filtrage AJAX (catégorie + statut) : la grille appelle
            // désormais admin-ajax.php plutôt que de masquer des cartes déjà
            // rendues en client-side. Voir RK_MC_Adventures_Filter_Service.
            wp_localize_script( 'rk-mc-adventures-grid', 'rkAdvFilter', array(
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'nonce'   => wp_create_nonce( RK_MC_Adventures_Filter_Service::NONCE_ACTION ),
                'action'  => 'rk_mc_filter_adventures',
                'i18n'    => array(
                    'error' => __( 'تعذّر تحميل المغامرات، حاول مرة أخرى.', 'rk-my-children' ),
                ),
            ) );
        }

        // v9.6 — لقاءاتي : sélection interactive du panneau détail, sans
        // rechargement de page. Aucun AJAX/nonce requis : les données de
        // toutes les séances affichées sont déjà présentes dans le JSON
        // inline généré par rk-sessions.php (#rk-sess-data).
        if ( str_starts_with( $current_slug, 'rk-sessions' ) ) {
            wp_enqueue_script(
                'rk-mc-sessions',
                RK_MC_URL . 'assets/js/rk-sessions.js',
                array(),
                RK_MC_VERSION,
                true
            );
        }

        // v9.7 — إنجازاتي : filtres client-side (statut + catégorie),
        // aucun AJAX nécessaire (catalogue déjà entièrement rendu).
        if ( str_starts_with( $current_slug, 'rk-badges' ) ) {
            wp_enqueue_script(
                'rk-mc-badges',
                RK_MC_URL . 'assets/js/rk-badges.js',
                array(),
                RK_MC_VERSION,
                true
            );
        }

        // v9.8 — شهاداتي : aperçu (modale), export PDF client (CDN
        // html2canvas + jsPDF, chargés à la demande uniquement) et
        // partage (Web Share API + repli presse-papiers).
        if ( str_starts_with( $current_slug, 'rk-certificats' ) ) {
            wp_enqueue_script(
                'rk-mc-certificates',
                RK_MC_URL . 'assets/js/rk-certificates.js',
                array(),
                RK_MC_VERSION,
                true
            );
        }
    }

    /* ═══════════════════════════════════════════════════════════════════
       CHROME NATIF TUTOR LMS 4.x
       ═══════════════════════════════════════════════════════════════════ */

    /**
     * v8.3.1 — Tutor LMS 4.x rend sa propre sidebar, son header et trois
     * cartes d'onboarding (profile completion / welcome / quick tips) autour
     * du contenu du dashboard. Sur la home enfant, #rkd4 fournit déjà ce
     * chrome : on retire les blocs Tutor en amont plutôt que de les masquer
     * uniquement en CSS (évite le FOUC et le JS Alpine inutile).
     *
     * Le CSS body.rkd4-home reste le filet de sécurité si les noms de
     * filtres changent d'une version Tutor à l'autre.
     *
     * Aucune modification du core Tutor — uniquement des filtres publics.
     * Hook : template_redirect (priorité 20).
     */
    public static function strip_tutor4_chrome(): void {
        if ( ! function_exists( 'tutor_utils' ) ) return;
        if ( ! tutor_utils()->is_tutor_frontend_dashboard() ) return;
        if ( ! self::is_home() ) return;
        if ( ! self::resolve_child() ) return;

        add_filter( 'tutor_dashboard_show_profile_completion', '__return_false' );
        add_filter( 'tutor_dashboard_show_welcome_card',       '__return_false' );
        add_filter( 'tutor_dashboard_show_quick_tips',         '__return_false' );
        
                add_filter( 'tutor_dashboard_show_profile_completion', '__return_false' );
        add_filter( 'tutor_dashboard_show_welcome_card',       '__return_false' );
        add_filter( 'tutor_dashboard_show_quick_tips',         '__return_false' );

        /*
         * Masque les blocs natifs Tutor 4.x du dashboard d'accueil qui n'ont
         * aucun sens côté enfant :
         *   - data-section-id="current_stats" → cartes "مجموع الأرباح",
         *     "مجموع الدورات", "مجموع الطلاب", "Avg. Rating" (vue instructeur)
         *   - .tutor-dashboard-home-chart   → "Course Completion Rate"
         *
         * Ces sections n'exposent aucun filtre Tutor : le masquage se fait en
         * CSS, injecté uniquement sur l'accueil du dashboard enfant.
         */
        add_action( 'wp_head', static function (): void {
            echo '<style id="rk-hide-tutor-home">'
               . '[data-section-id="current_stats"],'
               . '.tutor-dashboard-home-chart{display:none!important}'
               . '</style>';
        }, 99 );
    }
}