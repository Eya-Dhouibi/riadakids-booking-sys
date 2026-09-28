<?php
/**
 * RiadaKids\Core\Assets — v5.0 Modular Script Loading
 *
 */

namespace RiadaKids\Core;

use RiadaKids\Credits\CreditRepository;

if ( ! defined( 'ABSPATH' ) ) exit;

class Assets {

    public function __construct() {
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue' ] );
        add_action( 'wp_enqueue_scripts', [ $this, 'force_ssa_assets' ], 99 );
    }

    /**
     * Suffixe '.min' en production, vide en debug (WP_DEBUG ou SCRIPT_DEBUG).
     * Permet de servir les fichiers minifiés sans jamais casser le
     * débogage : décommenter SCRIPT_DEBUG ou WP_DEBUG dans wp-config.php
     * fait automatiquement basculer sur les fichiers sources lisibles.
     */
    private function suffix(): string {
        $debug = ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG )
              || ( defined( 'WP_DEBUG' ) && WP_DEBUG );
        return $debug ? '' : '.min';
    }

    /**
     * Résout une URL d'asset vers sa version .min si le fichier existe sur
     * le disque, sinon repli silencieux sur le fichier source — aucune
     * 404 possible même si un .min.js n'a pas été livré pour tel fichier.
     */
    private function asset_url( string $rel_path, string $suffix ): string {
        if ( '' === $suffix ) {
            return RK_PLUGIN_URL . $rel_path;
        }
        $dot = strrpos( $rel_path, '.' );
        $min_rel = substr( $rel_path, 0, $dot ) . $suffix . substr( $rel_path, $dot );
        if ( file_exists( RK_PLUGIN_DIR . $min_rel ) ) {
            return RK_PLUGIN_URL . $min_rel;
        }
        return RK_PLUGIN_URL . $rel_path; // repli si le .min est absent
    }

    public function enqueue(): void {
        if ( ! $this->is_booking_page() ) return;

        $v      = RK_VERSION;
        $suffix = $this->suffix();

        // ── CSS ──────────────────────────────────────────────────────────────
        // Dépendance explicite sur le style du thème actif quand ce handle
        // existe : garantit que notre CSS charge APRÈS lui, donc gagne à
        // spécificité égale (voir le fix dans style.css — badges de statut
        // en fond blanc à cause d'un ordre de chargement non garanti).
        // Si le thème n'utilise pas le handle conventionnel, on ne déclare
        // aucune fausse dépendance — les !important ajoutés dans style.css
        // restent le vrai filet de sécurité indépendant de l'ordre.
        $theme_style_handle = get_template() . '-style';
        $css_deps = wp_style_is( $theme_style_handle, 'registered' ) ? [ $theme_style_handle ] : [];
        wp_enqueue_style( 'rk-style', $this->asset_url( 'assets/css/style.css', $suffix ), $css_deps, $v );

        // ── Modules JS — ordre de chargement STRICT ──────────────────────────
        //
        // Chaque handle déclare explicitement ses dépendances via le 3e paramètre
        // de wp_register_script. WordPress garantit le bon ordre de sortie dans le DOM.
        //
        // Ordre : booking-state → booking-utils → booking-navigation →
        //         booking-programs → booking-sessions → booking-children →
        //         booking-credits → booking-ssa → booking-summary →
        //         booking-confirmation → rk-booking (bootstrap)

        wp_register_script(
            'rk-state',
            $this->asset_url( 'assets/js/booking-state.js', $suffix ),
            [ 'jquery' ],
            $v,
            true
        );

        wp_register_script(
            'rk-utils',
            $this->asset_url( 'assets/js/booking-utils.js', $suffix ),
            [ 'jquery', 'rk-state' ],
            $v,
            true
        );

        wp_register_script(
            'rk-navigation',
            $this->asset_url( 'assets/js/booking-navigation.js', $suffix ),
            [ 'jquery', 'rk-utils' ],
            $v,
            true
        );

        wp_register_script(
            'rk-programs',
            $this->asset_url( 'assets/js/booking-programs.js', $suffix ),
            [ 'jquery', 'rk-utils', 'rk-navigation' ],
            $v,
            true
        );

        wp_register_script(
            'rk-sessions',
            $this->asset_url( 'assets/js/booking-sessions.js', $suffix ),
            [ 'jquery', 'rk-utils' ],   // découplé de rk-programs
            $v,
            true
        );

        wp_register_script(
            'rk-children',
            $this->asset_url( 'assets/js/booking-children.js', $suffix ),
            [ 'jquery', 'rk-utils' ],
            $v,
            true
        );

        wp_register_script(
            'rk-credits',
            $this->asset_url( 'assets/js/booking-credits.js', $suffix ),
            [ 'jquery', 'rk-utils' ],
            $v,
            true
        );

        wp_register_script(
            'rk-ssa',
            $this->asset_url( 'assets/js/booking-ssa.js', $suffix ),
            [ 'jquery', 'rk-utils', 'rk-navigation' ],
            $v,
            true
        );

        // Widget custom par-dessus l'iframe SSA (même origin : accès direct
        // à iframe.contentDocument). Doit charger après rk-ssa car il
        // s'appuie sur le même élément #rk-ssa-iframe rendu par BookingForm.php.
        wp_register_script(
            'rk-ssa-overlay',
            $this->asset_url( 'assets/js/booking-ssa-overlay.js', $suffix ),
            [ 'jquery', 'rk-utils', 'rk-ssa' ],
            $v,
            true
        );

        wp_register_script(
            'rk-summary',
            $this->asset_url( 'assets/js/booking-summary.js', $suffix ),
            [ 'jquery', 'rk-utils' ],
            $v,
            true
        );

        /*
         * ── Édition d'une réservation — PILE INDÉPENDANTE ────────────────
         *
         * Enqueue (pas seulement register) et AUCUNE dépendance : ni jQuery,
         * ni les modules du wizard, ni rk-booking.js.
         *
         * Avant : ce handle n'était qu'une DÉPENDANCE de 'rk-booking'. Toute
         * cause faisant sauter 'rk-booking' (dequeue par un plugin de cache,
         * garde « Modules manquants » du bootstrap, erreur dans un module
         * amont) emportait avec elle le script d'édition — bouton « تعديل »
         * inerte, sans erreur console exploitable.
         *
         * Désormais : booking-edit.js est chargé pour lui-même. Même si tout
         * le wizard échoue, le bouton « تعديل » continue de fonctionner.
         */
        wp_enqueue_script(
            'rk-booking-edit',
            $this->asset_url( 'assets/js/booking-edit.js', $suffix ),
            [],          // volontairement vide
            $v,
            true
        );

        // Config PROPRE au module d'édition : nonce dédié, imprimé juste
        // avant booking-edit.js. Ne dépend plus de rkConfig (handle rk-booking).
        wp_localize_script( 'rk-booking-edit', 'rkEditConfig', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( \RiadaKids\Ajax\BookingEditAjax::NONCE_ACTION ),
        ] );

        /*
         * ── Reprogrammation interne (écran « تعديل الموعد ») — PILE INDÉPENDANTE ──
         *
         * Même raisonnement que rk-booking-edit ci-dessus : chargé pour
         * lui-même, aucune dépendance sur le wizard de réservation. Reste
         * fonctionnel même si le wizard principal échoue.
         */
        wp_enqueue_script(
            'rk-booking-reschedule',
            $this->asset_url( 'assets/js/booking-reschedule.js', $suffix ),
            [],
            $v,
            true
        );

        wp_localize_script( 'rk-booking-reschedule', 'rkRescheduleConfig', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( \RiadaKids\Ajax\RescheduleAjax::NONCE_ACTION ),
        ] );

        wp_register_script(
            'rk-confirmation',
            $this->asset_url( 'assets/js/booking-confirmation.js', $suffix ),
            [ 'jquery', 'rk-utils', 'rk-navigation', 'rk-programs', 'rk-sessions' ],
            $v,
            true
        );

        /*
         * ── PHASE 1 : View switcher (Form / Liste) — PILE INDÉPENDANTE ────
         *
         * Bascule uniquement entre les deux vues "حجز لقاء" et "اللقاءات
         * المحجوزة". Ne dépend d'aucun module du wizard et n'est dépendance
         * d'aucun d'eux : casser ce script n'affecte jamais la réservation,
         * et casser le wizard n'affecte jamais ce switcher.
         */
        wp_enqueue_script(
            'rk-view-switcher',
            $this->asset_url( 'assets/js/booking-view-switcher.js', $suffix ),
            [ 'jquery' ],
            $v,
            true
        );

        /*
         * ── AJOUT (demande utilisateur) — filtres liste "اللقاءات المحجوزة" ──
         *
         * Filtre par enfant + bascule القادمة/السابقة, entièrement côté
         * client (chaque carte porte déjà data-rk-child-id et
         * data-rk-upcoming — voir Dashboard::render_booking_card()).
         * PILE INDÉPENDANTE, même raisonnement que rk-view-switcher
         * ci-dessus : un filtre cassé ne doit jamais affecter le wizard de
         * réservation, et inversement.
         */
        wp_enqueue_script(
            'rk-bookings-filters',
            $this->asset_url( 'assets/js/booking-history-filters.js', $suffix ),
            [ 'jquery' ],
            $v,
            true
        );

        // ── Bootstrap — dépend de tous les modules ───────────────────────────
        // rk-booking.js ne contient aucune logique métier.
        // Il orchestre uniquement l'appel à .init() de chaque module.
        wp_enqueue_script(
            'rk-booking',
            $this->asset_url( 'assets/js/rk-booking.js', $suffix ),
            [
                'jquery',
                'rk-state',
                'rk-utils',
                'rk-navigation',
                'rk-programs',
                'rk-sessions',
                'rk-children',
                'rk-credits',
                'rk-ssa',
                'rk-ssa-overlay',
                'rk-summary',
                'rk-confirmation',
            ],
            $v,
            true   // in_footer = true → s'exécute après le DOM
        );

        // ── Config PHP → JS ──────────────────────────────────────────────────
        //
        // Source unique de vérité pour rkConfig.
        // SUPPRIMÉ : 'events' => SSAIntegration::get_events()
        //   → Le menu SSA frontend n'existe plus. La liaison séance→SSA
        //     se fait côté serveur dans handle_get_sessions() via ACF.
        //   → Supprime aussi l'import de SSAIntegration dans ce fichier.
        //
        $user_id = get_current_user_id();

        // Nom/email WooCommerce de l'utilisateur connecté — exposés au JS
        // pour l'auto-remplissage du formulaire "Customer Information"
        // (nom + email) que le widget SSA affiche après la sélection d'un
        // créneau. Voir booking-ssa-overlay.js::_autofillCustomerInfo.
        $current_user  = wp_get_current_user();
        $customer_name = trim( $current_user->first_name . ' ' . $current_user->last_name );
        if ( '' === $customer_name ) {
            $customer_name = $current_user->display_name; // repli si prénom/nom absents
        }

        wp_localize_script( 'rk-booking', 'rkConfig', [
            'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
            'nonce'      => Security::booking_nonce(),
            // ÉTAPE 23 — active les logs [RK SSA] côté JS. Même source que
            // rk_log() côté PHP (constante WP_DEBUG native WordPress) : un
            // seul interrupteur pour tout le plugin, pas un flag séparé à
            // maintenir. Désactivé par défaut en production (WP_DEBUG=false).
            'debug'      => defined( 'WP_DEBUG' ) && WP_DEBUG,
            // Icônes SVG partagées (remplacent les emojis dans les modules JS)
            'icons'      => \RiadaKids\Core\Icons::for_js(),
            // Repli de formatage côté JS quand l'API Intl est indisponible
            'siteTimezone' => wp_timezone_string(),
            'locale'       => str_replace( '_', '-', get_locale() ),
            'credits'    => CreditRepository::get_balance( $user_id ),
            'bookingUrl' => function_exists( 'wc_get_account_endpoint_url' )
                                ? wc_get_account_endpoint_url( 'book-session' )
                                : '',
            'customerName'  => $customer_name,
            'customerEmail' => $current_user->user_email,
            // AJOUT (demande utilisateur) — image dédiée au popup de succès
            // (assets/images/rk-robot-popup.png), distincte de celle de
            // l'étape 1 (rk-hero-robot.png).
            'robotUrl'      => RK_PLUGIN_URL . 'assets/images/rk-robot-popup.png',
            'i18n'       => [
                'selectProgram'    => 'الرجاء اختيار رحلة أولاً',
                'selectAdventure'  => 'الرجاء اختيار مغامرة أولاً',
                'selectSession'    => 'الرجاء اختيار لقاء أولاً',
                'selectChild'      => 'الرجاء اختيار طفل واحد على الأقل',
                'selectAppointment'=> 'الرجاء اختيار موعد في التقويم',
                'noCredits'        => 'رصيد الجلسات غير كافٍ',
                'loadingCalendar'  => 'جاري تحميل التقويم...',
                'loadingCourses'   => 'جاري تحميل المغامرات...',
                'loadingSessions'  => 'جاري تحميل اللقاءات...',
                'errorGeneric'     => 'حدث خطأ، يرجى المحاولة مرة أخرى',
                'bookingConfirmed' => 'تم تأكيد الحجز بنجاح',
                'processing'       => 'جاري المعالجة...',
                'confirmBtn'       => 'تأكيد الحجز',
            ],
        ] );
    }

    /** Force le chargement des assets SSA sur la page de réservation */
    public function force_ssa_assets(): void {
        if ( ! $this->is_booking_page() ) return;
        do_action( 'ssa/enqueue_scripts' );
        global $wp_scripts, $wp_styles;
        foreach ( ( $wp_scripts->registered ?? [] ) as $h => $s ) {
            if ( preg_match( '/^ssa|simply.schedule/i', $h ) ) wp_enqueue_script( $h );
        }
        foreach ( ( $wp_styles->registered ?? [] ) as $h => $s ) {
            if ( preg_match( '/^ssa|simply.schedule/i', $h ) ) wp_enqueue_style( $h );
        }
    }

    private function is_booking_page(): bool {
        return function_exists( 'is_account_page' ) && is_account_page();
    }
}