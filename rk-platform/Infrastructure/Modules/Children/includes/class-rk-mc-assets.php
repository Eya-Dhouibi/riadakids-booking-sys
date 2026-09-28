<?php
declare( strict_types=1 );
/**
 * RK_MC_Assets  (v5.3.0 — Sprint 1)
 *
 * Chargement CSS/JS.
 * Ajout v5.3.0 : child-dashboard.css sur l'endpoint child-dashboard.
 *
 * @package RK_My_Children
 * @since   5.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class RK_MC_Assets {

    public static function init() {
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
    }

    public static function enqueue() {

        // Tutor LMS /dashboard/ pour un enfant rk_child
        if ( self::is_tutor_dashboard_for_child() ) {
            self::enqueue_child_dashboard_styles();
            // v9.48 (13/08/2026) — Popups/toasts du Child Experience
            // Layer retirés du DASHBOARD uniquement (décision explicite
            // de l'utilisateur) : les célébrations XP/badges, l'Entry
            // Story de bienvenue et la guidance restent actives sur les
            // vraies pages de cours Tutor (leçon/quiz), voir l'appel
            // conservé ci-dessous pour is_tutor_frontend_page_for_child().
            return;
        }

        // v9.9 — Child Experience Layer : popups temps réel même sur une
        // vraie page Tutor native (leçon/quiz/cours), pas seulement le
        // Dashboard — décision explicite : l'enfant qui termine une
        // leçon doit voir le popup immédiatement, sans revenir d'abord
        // au Dashboard. Détection par CPT réel (courses/lesson/tutor_quiz),
        // pas une fonction API tierce non documentée de façon fiable.
        if ( self::is_tutor_frontend_page_for_child() ) {
            self::enqueue_experience_layer();
            return;
        }

        if ( ! is_account_page() ) {
            return;
        }

        // Endpoint my-children — assets complets
        if ( is_wc_endpoint_url( RK_MC_Endpoint::SLUG ) ) {
            self::enqueue_styles();
            self::enqueue_scripts();
            return;
        }

        // Endpoint child-dashboard — CSS dashboard uniquement (pas de JS interactif)
        if ( is_wc_endpoint_url( RK_MC_Endpoint::CHILD_DASHBOARD_SLUG ) ) {
            self::enqueue_child_dashboard_styles();
            return;
        }

        // Endpoint child-profile — fiche complète parent (CSS carte, lecture seule)
        if ( is_wc_endpoint_url( RK_MC_Endpoint::PROFILE_SLUG ) ) {
            self::enqueue_child_profile_styles();
            return;
        }
    }

    /* ─────────────────────────────────────────
     * CSS — My Children
     * ───────────────────────────────────────── */

    private static function enqueue_styles() {
        // v2.3 (Audit P4-18) — fondation accessibilité, chargée en premier.
        wp_enqueue_style( 'rk-a11y', RK_MC_URL . 'assets/css/rk-a11y.css', array(), RK_MC_VERSION );

        $css_files = array(
            'rk-mc-card'    => 'assets/css/child-card.css',
            'rk-mc-actions' => 'assets/css/child-actions.css',
            'rk-mc-modal'   => 'assets/css/child-modal.css',
            'rk-mc-modal-booking-style' => 'assets/css/child-modal-booking-style.css',
        );

        $prev = array();
        foreach ( $css_files as $handle => $path ) {
            wp_enqueue_style( $handle, RK_MC_URL . $path, $prev, RK_MC_VERSION );
            $prev = array( $handle );
        }
    }

    /* ─────────────────────────────────────────
     * CSS — Child Dashboard (Sprint 1)
     * ───────────────────────────────────────── */

    private static function enqueue_child_dashboard_styles() {
        wp_enqueue_style( 'rk-a11y', RK_MC_URL . 'assets/css/rk-a11y.css', array(), RK_MC_VERSION ); // v2.3 — P4-18

        wp_enqueue_style(
            'rk-dashboard',
            RK_MC_URL . 'assets/css/rk-dashboard.css',
            array(),
            RK_MC_VERSION
        );

        wp_enqueue_script(
            'rk-mc-child-dashboard',
            RK_MC_URL . 'assets/js/child-dashboard-endpoint.js',
            array(),
            RK_MC_VERSION,
            true
        );
    }

    /* ─────────────────────────────────────────
     * CSS — Fiche complète (child-profile, parent, lecture seule)
     * ───────────────────────────────────────── */

    private static function enqueue_child_profile_styles() {
        wp_enqueue_style( 'rk-a11y', RK_MC_URL . 'assets/css/rk-a11y.css', array(), RK_MC_VERSION );
        // child-card.css contient aussi les blocs .rk-cprofile__* (v10.0)
        wp_enqueue_style( 'rk-mc-card', RK_MC_URL . 'assets/css/child-card.css', array(), RK_MC_VERSION );

        // AJOUT (demande utilisateur) — slider المغامرات (3 items/vue
        // desktop, 1/vue mobile), voir sa doc complète en tête de fichier.
        wp_enqueue_script(
            'rk-mc-profile-slider',
            RK_MC_URL . 'assets/js/child-profile-slider.js',
            array( 'jquery' ),
            RK_MC_VERSION,
            true
        );
    }

    /* ─────────────────────────────────────────
     * JS — My Children
     * ───────────────────────────────────────── */

    private static function enqueue_scripts() {
        $js_files = array(
            'rk-mc-notifications' => array(
                'src'  => 'assets/js/child-notifications.js',
                'deps' => array( 'jquery' ),
            ),
            'rk-mc-modal' => array(
                'src'  => 'assets/js/child-modal.js',
                'deps' => array( 'jquery', 'rk-mc-notifications' ),
            ),
            'rk-mc-list' => array(
                'src'  => 'assets/js/child-list.js',
                'deps' => array( 'jquery', 'rk-mc-modal', 'rk-mc-notifications' ),
            ),
            'rk-mc-add' => array(
                'src'  => 'assets/js/child-add.js',
                'deps' => array( 'jquery', 'rk-mc-list' ),
            ),
            'rk-mc-edit' => array(
                'src'  => 'assets/js/child-edit.js',
                'deps' => array( 'jquery', 'rk-mc-list' ),
            ),
            'rk-mc-delete' => array(
                'src'  => 'assets/js/child-delete.js',
                'deps' => array( 'jquery', 'rk-mc-list', 'rk-mc-notifications' ),
            ),
            // v2.9 — upload de la photo enfant vers la bibliothèque WP
            'rk-mc-avatar-upload' => array(
                'src'  => 'assets/js/child-avatar-upload.js',
                'deps' => array( 'rk-mc-list' ), // rkMC (restUrl/restNonce) est localisé sur list
            ),
        );

        foreach ( $js_files as $handle => $config ) {
            wp_enqueue_script(
                $handle,
                RK_MC_URL . $config['src'],
                $config['deps'],
                RK_MC_VERSION,
                true
            );
        }

        self::localize( 'rk-mc-notifications' );
    }

    /* ─────────────────────────────────────────
     * Localisation
     * ───────────────────────────────────────── */

    private static function localize( $handle ) {
        /*
         * 6b-3 (site D 37) — rk_mc_count_children() / count_bookings()
         * comptent les enfants et reservations DU PARENT. Sur une surface
         * Tutor enfant, get_current_user_id() vaut le wp_user de l'enfant :
         * la cle de transient rk_mc_stats_{uid} creait alors un cache
         * parasite sous l'ID de l'enfant, avec des compteurs parent.
         */
        $user_id = class_exists( 'RK_Identity_Context' )
            ? RK_Identity_Context::authenticated_parent_id()
            : get_current_user_id();

        $stats_key = 'rk_mc_stats_' . $user_id;
        $stats = get_transient( $stats_key );
        if ( false === $stats ) {
            $stats = array(
                'children' => rk_mc_count_children( $user_id ),
                'bookings' => rk_mc_count_bookings( $user_id ),
                'sessions' => rk_mc_count_sessions( $user_id ),
                'credits'  => rk_mc_get_session_credits( $user_id ),
            );
            set_transient( $stats_key, $stats, 5 * MINUTE_IN_SECONDS );
        }

        wp_localize_script( $handle, 'rkMC', array(
            'restUrl'            => esc_url_raw( rest_url( 'rk-mc/v1/children' ) ),
            'restNonce'          => wp_create_nonce( 'wp_rest' ),
            'dashboardBase'      => esc_url( home_url( RK_TUTOR_DASHBOARD_URL ) ),
            'childDashboardBase' => esc_url( wc_get_account_endpoint_url( RK_MC_Endpoint::CHILD_DASHBOARD_SLUG ) ),
            'fullProfileBase'    => esc_url( wc_get_account_endpoint_url( RK_MC_Endpoint::PROFILE_SLUG ) ),
            'rapportBase'        => esc_url( wc_get_account_endpoint_url( RK_MC_Endpoint::RAPPORT_SLUG ) ),
            'childSpaceUrl'      => esc_url( home_url( '/connexion-child/' ) ),
            'stats' => $stats,
            'i18n' => array(
                'confirmDelete' => __( 'هل أنت متأكد من حذف هذا الطفل؟', 'rk-my-children' ),
                'errorDelete'   => __( 'حدث خطأ أثناء الحذف، يرجى المحاولة مجدداً.', 'rk-my-children' ),
                'errorAdd'      => __( 'حدث خطأ أثناء الإضافة، يرجى المحاولة مجدداً.', 'rk-my-children' ),
                'errorNetwork'  => __( 'تعذر الاتصال بالخادم.', 'rk-my-children' ),
                'emptyMsg'      => __( 'لا يوجد أطفال مسجلون حالياً.', 'rk-my-children' ),
                'years'         => __( 'سنة', 'rk-my-children' ),
                'required'      => __( 'هذا الحقل مطلوب', 'rk-my-children' ),
                'btnDashboard'  => __( 'الدخول كـ', 'rk-my-children' ),
                'btnReserve'    => __( 'حجز حصة', 'rk-my-children' ),
                'btnHistory'    => __( 'السجل', 'rk-my-children' ),
                'btnFullProfile'=> __( 'الملف الكامل', 'rk-my-children' ),
                'btnReports'    => __( 'التقارير', 'rk-my-children' ),
                'btnEdit'       => __( 'تعديل', 'rk-my-children' ),
                'btnDelete'     => __( 'حذف', 'rk-my-children' ),
                'savedOk'       => __( 'تم حفظ التغييرات بنجاح!', 'rk-my-children' ),
                'editTitle'     => __( 'تعديل بيانات الطفل', 'rk-my-children' ),
                'addTitle'      => __( 'إضافة طفل جديد', 'rk-my-children' ),
            ),
        ) );
    }

    /* ─────────────────────────────────────────
     * Détection Tutor LMS dashboard pour rk_child
     * ───────────────────────────────────────── */

    private static function is_tutor_dashboard_for_child(): bool {
        if ( ! is_user_logged_in() ) {
            return false;
        }
        $user = wp_get_current_user();
        if ( ! $user || ! in_array( 'rk_child', (array) $user->roles, true ) ) {
            return false;
        }
        global $wp;
        $path = trim( $wp->request ?? '', '/' );
        $slug = trim( RK_TUTOR_DASHBOARD_URL, '/' );
        return ( 0 === strpos( $path, $slug ) );
    }

    /**
     * Détecte une VRAIE page Tutor LMS native (cours/leçon/quiz), par
     * opposition au dashboard RiadaKids lui-même. Détection par CPT
     * réel via is_singular() — plus fiable qu'une fonction API tierce
     * non confirmée dans la documentation Tutor LMS.
     */
    private static function is_tutor_frontend_page_for_child(): bool {
        if ( ! is_user_logged_in() ) return false;
        $user = wp_get_current_user();
        if ( ! $user || ! in_array( 'rk_child', (array) $user->roles, true ) ) return false;

        return is_singular( [ 'courses', 'lesson', 'tutor_quiz' ] );
    }

    /**
     * true UNIQUEMENT si c'est réellement la première visite de CET
     * enfant précis sur son dashboard. Toute ambiguïté ou incohérence
     * (identité non résolue, user_meta absente pour une raison
     * anormale) retourne false — jamais true par défaut. Voir le
     * commentaire d'appel dans enqueue_experience_layer() pour le
     * mécanisme exact du bug que cette fonction corrige.
     *
     * @param int $child_id ID métier (rk_children.id), déjà résolu par l'appelant.
     */
    private static function child_is_first_visit( int $child_id ): bool {
        if ( $child_id <= 0 ) return false;
        if ( ! class_exists( 'RK_Identity_Context' ) ) return false;

        $child_wp_uid = RK_Identity_Context::child_wp_uid();
        if ( $child_wp_uid <= 0 ) {
            // Identité enfant non résolue côté WordPress : état inconnu,
            // jamais interprété comme "première visite" (§1 du correctif).
            if ( function_exists( 'rkp_log' ) ) {
                rkp_log( sprintf(
                    '[ONBOARDING] child_wp_uid=0 malgre child_id=%d — isFirstVisit force a false (identite non resolue)',
                    $child_id
                ) );
            }
            return false;
        }

        $seen_raw = get_user_meta( $child_wp_uid, 'rk_onboarding_seen', true );
        // Normalisation stricte : toute valeur "vraie" au sens PHP large
        // (1, '1', true) compte comme "déjà vu". Seule l'absence réelle
        // de meta (chaîne vide, valeur par défaut de get_user_meta) est
        // considérée comme "jamais vu".
        $already_seen = ! empty( $seen_raw );
        $is_first     = ! $already_seen;

        if ( function_exists( 'rkp_log' ) ) {
            rkp_log( sprintf(
                '[ONBOARDING] onboarding_seen_raw=%s | already_seen=%s | isFirstVisit=%s | child_wp_uid_hash=%s',
                var_export( $seen_raw, true ),
                $already_seen ? 'true' : 'false',
                $is_first ? 'true' : 'false',
                substr( md5( (string) $child_wp_uid ), 0, 8 )
            ) );
        }

        return $is_first;
    }

    /**
     * Enqueue le Child Experience Layer (popups/toasts événementiels) —
     * factorisé ici car deux contextes différents doivent le charger :
     * le Dashboard RiadaKids ET les vraies pages Tutor natives.
     */
    private static function enqueue_experience_layer(): void {
        wp_enqueue_style(
            'rk-experience-layer',
            RK_MC_URL . 'assets/css/rk-experience-layer.css',
            [],
            RK_MC_VERSION
        );
        wp_enqueue_script(
            'rk-experience-layer',
            RK_MC_URL . 'assets/js/rk-experience-layer.js',
            [],
            RK_MC_VERSION,
            true
        );

        if ( class_exists( 'RKP_EventExperienceService' ) ) {
            /* 6b-2 — meme raison que resolve_child_id() : sur child_app le
             * current user est le parent. On passe par le contexte. */
            $child_id = class_exists( 'RK_Identity_Context' )
                ? RK_Identity_Context::child_id()
                : 0;

            wp_localize_script( 'rk-experience-layer', 'rkExperience', [
                'ajaxUrl'           => admin_url( 'admin-ajax.php' ),
                'nonce'             => wp_create_nonce( 'rk_experience_layer' ),
                'childId'           => $child_id,
                'dashboardBase'     => esc_url( home_url( RK_TUTOR_DASHBOARD_URL ) ),
                /*
                 * CORRECTIF — normalisation stricte de isFirstVisit.
                 *
                 * Avant : `$child_id > 0 && ! get_user_meta( child_wp_uid(), ... )`.
                 * $child_id (id metier, rk_children.id) et child_wp_uid()
                 * (WP user id) sont DEUX identites distinctes dans ce
                 * plugin — child_wp_uid() peut retourner 0 alors meme que
                 * $child_id > 0 (compte WP supprime/desynchronise, ou
                 * is_parent_surface() different entre les deux appels).
                 *
                 * Dans ce cas, get_user_meta( 0, 'rk_onboarding_seen', true )
                 * retourne TOUJOURS '' (aucune meta pour l'utilisateur 0) —
                 * donc ! '' = true, et isFirstVisit devenait true a CHAQUE
                 * visite, quel que soit l'historique reel de l'enfant.
                 * C'est le mecanisme exact qui pouvait rouvrir l'onboarding
                 * en boucle.
                 *
                 * Corrige : $child_wp_uid est calcule UNE fois et verifie
                 * explicitement > 0 avant toute lecture de meta. Etat
                 * inconnu/incoherent = false, jamais true (§1, §3 du
                 * correctif) — la seule valeur envoyee au JS est TOUJOURS
                 * un booleen strict PHP, jamais une chaine ambigue.
                 */
                'isFirstVisit'      => self::child_is_first_visit( $child_id ),
            ] );
        }
    }

}