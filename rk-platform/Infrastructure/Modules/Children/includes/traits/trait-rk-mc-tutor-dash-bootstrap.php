<?php
declare( strict_types=1 );
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Extrait de class-rk-mc-tutor-dashboard.php — factorisation par fonctionnalité.
 * Code déplacé verbatim, aucune modification de logique.
 */
trait RK_MC_Tutor_Dash_Bootstrap {
    /* ═══════════════════════════════════════════════════════════════════
       INIT
       ═══════════════════════════════════════════════════════════════════ */

    public static function init(): void {
        add_action( 'init',              array( __CLASS__, 'register_rk_rewrite_rules' ) );
        add_action( 'init',              array( __CLASS__, 'maybe_flush_rewrite_rules' ), 99 );
        add_action( 'template_redirect', array( __CLASS__, 'maybe_hide_theme_chrome' ), 1 );
        add_action( 'template_redirect', array( __CLASS__, 'maybe_convert_child_id_to_tab' ), 3 );
        add_action( 'template_redirect', array( __CLASS__, 'setup_hooks' ), 5 );
        add_action( 'template_redirect', array( __CLASS__, 'block_restricted_pages' ), 20 );
    }

    public static function maybe_hide_theme_chrome(): void {
        // phpcs:disable WordPress.Security.NonceVerification
        $raw_tab = isset( $_GET['rk_tab'] ) ? sanitize_text_field( wp_unslash( $_GET['rk_tab'] ) ) : '';
        // phpcs:enable
        if ( ! preg_match( '/^[a-f0-9]{40}$/', $raw_tab ) ) return;
        if ( function_exists( 'tutor_utils' ) && tutor_utils()->is_tutor_frontend_dashboard() ) return;
        add_action( 'wp_head', array( __CLASS__, 'output_hide_theme_chrome_css' ), 1 );
        add_filter( 'body_class', static function ( array $classes ): array {
            $classes[] = 'rk-no-chrome';
            return $classes;
        } );
    }

    public static function output_hide_theme_chrome_css(): void {
        echo '<style>
body.rk-no-chrome [data-elementor-type="header"],
body.rk-no-chrome [data-elementor-type="footer"],
body.rk-no-chrome .elementor-location-header,
body.rk-no-chrome .elementor-location-footer,
body.rk-no-chrome header.site-header,
body.rk-no-chrome footer.site-footer,
body.rk-no-chrome .header-section,
body.rk-no-chrome .footer-section,
body.rk-no-chrome #masthead,
body.rk-no-chrome #colophon,
body.rk-no-chrome #site-header,
body.rk-no-chrome #site-footer,
body.rk-no-chrome .site-header,
body.rk-no-chrome .site-footer,
body.rk-no-chrome .ast-above-header-wrap,
body.rk-no-chrome .ast-main-header-wrap,
body.rk-no-chrome .ast-below-header-wrap,
body.rk-no-chrome .ast-footer-above-wrap,
body.rk-no-chrome .ast-footer-main-wrap,
body.rk-no-chrome .ast-footer-below-wrap,
body.rk-no-chrome #wpadminbar{display:none!important;}
body.rk-no-chrome{padding-top:0!important;margin-top:0!important;}
</style>';
    }

    /* ═══════════════════════════════════════════════════════════════════
       REWRITE RULES — enregistre les slugs RK dans le routeur WordPress
       ═══════════════════════════════════════════════════════════════════ */

    public static function register_rk_rewrite_rules(): void {
        $slug = trim( RK_TUTOR_DASHBOARD_URL, '/' );
        if ( ! $slug ) return;

        $page_id = 0;
        if ( function_exists( 'tutor_utils' ) ) {
            $page_id = (int) tutor_utils()->get_option( 'tutor_dashboard_page_id' );
        }
        if ( ! $page_id ) {
            $page    = get_page_by_path( $slug );
            $page_id = $page ? (int) $page->ID : 0;
        }
        if ( ! $page_id ) return;

        foreach ( self::$rk_slugs as $rk_slug ) {
            add_rewrite_rule(
                '^' . preg_quote( $slug, '#' ) . '/' . preg_quote( $rk_slug, '#' ) . '/?$',
                'index.php?page_id=' . $page_id . '&tutor_dashboard_page=' . $rk_slug,
                'top'
            );
        }
    }

    // Flush rewrite rules une seule fois quand la liste de slugs change.
    public static function maybe_flush_rewrite_rules(): void {
        $opt  = 'rk_mc_rw_v';
        $hash = md5( RK_TUTOR_DASHBOARD_URL . ':' . implode( ',', self::$rk_slugs ) );
        if ( get_option( $opt ) !== $hash ) {
            flush_rewrite_rules( false );
            update_option( $opt, $hash, false );
        }
    }

    /* ═══════════════════════════════════════════════════════════════════
       PARENT → CHILD TAB REDIRECT
       Quand un parent visite ?child_id=X sans ?rk_tab, on crée la session
       onglet et on redirige vers ?rk_tab=<token_stable>.
       Le parent voit alors exactement l'espace de l'enfant (même WP user
       context via determine_current_user).
       ═══════════════════════════════════════════════════════════════════ */

    public static function maybe_convert_child_id_to_tab(): void {
        if ( ! function_exists( 'tutor_utils' ) ) return;
        if ( ! tutor_utils()->is_tutor_frontend_dashboard() ) return;

        // Réservé aux parents connectés (pas aux enfants, pas aux coachs)
        if ( ! is_user_logged_in() ) return;
        if ( class_exists( 'RK_MC_Child_Restrictions' ) && RK_MC_Child_Restrictions::is_child_user() ) return;
        if ( class_exists( 'RK_Coach_Dashboard' ) && RK_Coach_Dashboard::is_coach() ) return;

        // phpcs:disable WordPress.Security.NonceVerification
        $raw_cid = isset( $_GET['child_id'] ) ? (int) sanitize_text_field( wp_unslash( $_GET['child_id'] ) ) : 0;
        $raw_tab = isset( $_GET['rk_tab'] )   ? sanitize_text_field( wp_unslash( $_GET['rk_tab'] ) )        : '';
        // phpcs:enable

        // Rien à faire si pas de child_id ou si rk_tab déjà présent et valide
        if ( $raw_cid <= 0 || preg_match( '/^[a-f0-9]{40}$/', $raw_tab ) ) return;

        if ( ! class_exists( 'RK_Session_Manager' ) || ! class_exists( 'RK_MC_Child_Repository' ) ) return;

        $parent_uid = get_current_user_id();
        $child_obj  = RK_MC_Child_Repository::get_child( $raw_cid, $parent_uid );

        if ( ! $child_obj || empty( $child_obj->wp_user_id ) ) return;

        // Crée (ou rafraîchit) la session onglet — token stable et déterministe
        $tab_id = RK_Session_Manager::create_tab_session( (int) $child_obj->wp_user_id, $parent_uid );

        // Remplace child_id par rk_tab dans l'URL courante (garde les autres params)
        $url = remove_query_arg( 'child_id' );
        $url = add_query_arg( RK_Session_Manager::TAB_PARAM, $tab_id, $url );

        wp_safe_redirect( $url );
        exit;
    }

    public static function setup_hooks(): void {
        if ( ! function_exists( 'tutor_utils' ) ) return;
        if ( ! tutor_utils()->is_tutor_frontend_dashboard() ) return;

        // Le plugin rk-coach-hub gère entièrement le dashboard des coachs.
        // Exception : si rk_tab présent ET que la page courante est une
        // sous-page ENFANT légitime (le coach consulte le dashboard d'un
        // enfant précis), on laisse le module Children s'activer.
        //
        // v9.15 — Garde-fou renforcé : avant cette version, un rk_tab
        // valide résiduel dans l'URL (ex: après avoir consulté un enfant,
        // puis navigué vers une page EXCLUSIVEMENT coach comme rk-badges
        // sans que le paramètre soit nettoyé) suffisait à laisser ce
        // module s'activer EN PLUS du dispatcher coach — les deux
        // s'exécutaient alors sur le même hook tutor_before_dashboard_content,
        // et le rendu enfant (render_rk_content) écrasait visuellement le
        // rendu coach (RK_Coach_Badges::render()). On exclut désormais
        // explicitement les slugs réservés au coach, qu'un rk_tab soit
        // présent ou non — ces pages n'ont jamais de contenu enfant légitime.
        if ( class_exists( 'RK_Coach_Dashboard' ) && RK_Coach_Dashboard::is_coach() ) {
            $coach_only_slugs = [
                'rk-mes-cours', 'rk-mes-eleves', 'rk-progression', 'rk-lecons',
                'rk-quiz', 'rk-journey', 'rk-evaluer', 'rk-assigner-mission',
                'rk-badges', 'rk-seances', 'rk-calendrier', 'rk-messagerie',
                'rk-stats',
            ];
            global $wp_query;
            $current_slug = (string) ( $wp_query->query_vars['tutor_dashboard_page'] ?? '' );
            if ( in_array( $current_slug, $coach_only_slugs, true ) ) return;

            // phpcs:disable WordPress.Security.NonceVerification
            $raw_tab = isset( $_GET['rk_tab'] ) ? sanitize_text_field( wp_unslash( $_GET['rk_tab'] ) ) : '';
            // phpcs:enable
            if ( ! preg_match( '/^[a-f0-9]{40}$/', $raw_tab ) ) return;
        }

        // Session persistence: sauvegarde/restauration du token dans localStorage (toujours)
        add_action( 'wp_head', array( __CLASS__, 'output_session_persist_script' ), 0 );

        // Always: body class + nav + template loader + assets
        add_filter( 'body_class',                                     array( __CLASS__, 'add_body_class' ) );
        add_filter( 'tutor_dashboard/nav_ui_items',                   array( __CLASS__, 'filter_nav' ) );
        add_filter( 'load_dashboard_template_part_from_other_location', array( __CLASS__, 'load_rk_template' ) );
        add_action( 'wp_enqueue_scripts',                             array( __CLASS__, 'enqueue_assets' ) );

        // Sidebar gamifiée bureau : buffer + remplacement pour TOUS les utilisateurs (enfant ET parent)
        add_action( 'tutor_dashboard/before/wrap', array( __CLASS__, 'sidebar_buffer_start' ), 1 );
        add_action( 'tutor_dashboard/after/wrap',  array( __CLASS__, 'sidebar_buffer_end' ),   998 );

        if ( self::resolve_child() ) {
            /* v8.5.1 — Chrome v4 unifié (#rkd4-mobtop · #rkd4-side · #rkd4-botnav).
             * render_topbar() délègue à RK_MC_Dashboard_Chrome_V4::render_subpage_chrome_open()
             * qui imprime mobtop + topbar puis OUVRE #rkd4-shell > #rkd4-main : le contenu
             * Tutor natif de la sous-page s'imprime ensuite à l'intérieur de #rkd4-main,
             * dans le flux normal du hook tutor_dashboard/before/wrap → contenu → after/wrap.
             * render_subpage_chrome_close() ferme #rkd4-main, imprime la sidebar, ferme
             * #rkd4-shell, puis imprime #rkd4-botnav.
             *
             * Les anciennes render_subpage_main_open()/close() (#rkd3-main) sont
             * remplacées par cette paire — ne plus les hooker ici, sous peine de
             * dupliquer un conteneur autour du contenu. */
            add_action( 'tutor_dashboard/before/wrap', array( __CLASS__, 'render_topbar' ), 0 );
            // Contenu RK complet (hero, sections) uniquement en contexte enfant
            add_action( 'tutor_before_dashboard_content', array( __CLASS__, 'render_rk_content' ) );
            if ( class_exists( 'RK_MC_Dashboard_Chrome_V4' ) ) {
                // Ferme #rkd4-main + imprime sidebar/botnav (priorité 999, après le flush du buffer à 998)
                add_action( 'tutor_dashboard/after/wrap', array( 'RK_MC_Dashboard_Chrome_V4', 'render_subpage_chrome_close' ), 999 );
            } else {
                // Filet de sécurité si la classe v4 n'est pas chargée (ne devrait pas arriver)
                add_action( 'tutor_dashboard/before/wrap', array( __CLASS__, 'render_subpage_main_open' ), 0 );
                add_action( 'tutor_dashboard/after/wrap',  array( __CLASS__, 'render_mobile_nav' ) );
                add_action( 'tutor_dashboard/after/wrap',  array( __CLASS__, 'render_subpage_main_close' ), 999 );
            }
        } elseif ( is_user_logged_in() ) {
            // Nav mobile parent (sans contexte enfant)
            add_action( 'tutor_dashboard/after/wrap', array( __CLASS__, 'render_parent_mobile_nav' ) );
        }
    }

    /* ═══════════════════════════════════════════════════════════════════
       CONTEXT
       ═══════════════════════════════════════════════════════════════════ */

    private static function resolve_child(): ?object {
        if ( null !== self::$child_cache ) return self::$child_cache;

        /*
         * ── BORNAGE PAR SURFACE (phase 6b-3, 19/08/2026) ─────────────────
         *
         * Avant : resolve_child() etait la SECONDE source de verite du
         * plugin, concurrente de RK_Surface_Resolver. Elle resolvait un
         * enfant depuis ?child_id= sans jamais regarder la surface, ce qui
         * activait le Chrome ENFANT sur une page PARENT :
         *
         *     /dashboard/rk-parent-board/?child_id=108
         *         surface        = parent      -> child_id() = 0
         *         resolve_child()= Child 108   -> Chrome enfant rendu
         *         resultat       : badges a 0 sur une page parent
         *
         * Maintenant : sur une surface parent, aucun ACTING CHILD n'est
         * resolu, donc le Chrome enfant n'est pas attache (voir la garde
         * `if ( self::resolve_child() )` plus haut dans ce fichier).
         *
         * ?child_id= n'est PAS perdu pour autant : il reste disponible via
         * RK_Identity_Context::selected_child_id(), en tant que FILTRE de
         * consultation parent. rk-parent-board.php continue d'ailleurs de
         * le lire directement, avec son propre controle d'appartenance.
         *
         * NOTE COACH : le chemin coach (2b, plus bas) reste intact. Un coach
         * ouvre le dashboard d'un eleve via rk_tab, sur une surface
         * child_app -- il n'est donc jamais bloque par cette garde. Son
         * ownership passe par get_coach_students(), pas par le contexte
         * parent/enfant.
         */
        if ( class_exists( 'RK_Identity_Context' )
            && RK_Identity_Context::is_parent_surface() ) {
            self::$child_cache = null;
            return null;
        }

        $child = RK_MC_Child_Context::get_active_child();
        if ( $child ) { self::$child_cache = $child; return $child; }

        // Chemin 1 : l'enfant est le current WP user (cookie direct ou rk_tab via determine_current_user)
        if ( RK_MC_Child_Restrictions::is_child_user() ) {
            global $wpdb;
            $child = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM " . rk_mc_children_table() . " WHERE wp_user_id = %d LIMIT 1",
                get_current_user_id()
            ) );
            self::$child_cache = $child ?: null;
            return self::$child_cache;
        }

        // Chemin 2 : rk_tab présent — lecture directe du transient (fonctionne même sans RK_Session_Manager)
        // phpcs:disable WordPress.Security.NonceVerification
        $raw_tab = isset( $_GET['rk_tab'] ) ? sanitize_text_field( wp_unslash( $_GET['rk_tab'] ) ) : '';
        // phpcs:enable
        if ( preg_match( '/^[a-f0-9]{40}$/', $raw_tab ) ) {
            /*
             * 6b-4b — Passage par l'API. La lecture directe du transient
             * cessait de voir les sessions des que celles-ci vivaient en
             * table (6b-4a), et ignorait expiration et revocation.
             * validate_tab_session() consulte la table, puis le cache, puis
             * le chemin legacy borne — contrat de retour identique.
             */
            $session = class_exists( 'RK_Session_Manager' )
                ? RK_Session_Manager::validate_tab_session( $raw_tab )
                : get_transient( 'rk_tab_' . $raw_tab );
            if ( is_array( $session ) && ! empty( $session['child_wp_uid'] ) ) {

                // Chemin 2a : visiteur = le parent propriétaire du token
                if ( isset( $session['parent_wp_uid'] )
                    && (int) $session['parent_wp_uid'] === get_current_user_id()
                ) {
                    global $wpdb;
                    $child = $wpdb->get_row( $wpdb->prepare(
                        "SELECT * FROM " . rk_mc_children_table() . " WHERE wp_user_id = %d LIMIT 1",
                        (int) $session['child_wp_uid']
                    ) );
                    self::$child_cache = $child ?: null;
                    return self::$child_cache;
                }

                // Chemin 2b : visiteur = coach autorisé pour cet enfant
                if ( class_exists( 'RK_Coach_Dashboard' )
                    && RK_Coach_Dashboard::is_coach_user( wp_get_current_user() )
                ) {
                    global $wpdb;
                    $child = $wpdb->get_row( $wpdb->prepare(
                        "SELECT * FROM " . rk_mc_children_table() . " WHERE wp_user_id = %d LIMIT 1",
                        (int) $session['child_wp_uid']
                    ) );
                    if ( $child ) {
                        $coach_id      = get_current_user_id();
                        $coach_students = class_exists( 'RK_Coach_Data' )
                            ? RK_Coach_Data::get_coach_students( $coach_id )
                            : [];
                        $allowed = array_map( fn( $s ) => (int) $s->child_id, (array) $coach_students );
                        if ( in_array( (int) $child->id, $allowed, true ) ) {
                            self::$child_cache = $child;
                            return self::$child_cache;
                        }
                    }
                }
            }
        }

        self::$child_cache = null;
        return null;
    }

    /** Public accessor for sub-page templates. */
    public static function get_child_for_template(): ?object {
        return self::resolve_child();
    }

    /** Returns the JourneySnapshot built once by load_data(). Templates must use this, never call JourneyQueryService directly. */
    public static function get_snapshot(): ?RKP_JourneySnapshot {
        $d = self::load_data();
        return $d['snapshot'] ?? null;
    }

    public static function child_param(): string {
        // rk_tab doit être propagé dans tous les liens — lecture directe sans dépendre de RK_Session_Manager.
        // phpcs:disable WordPress.Security.NonceVerification
        $raw_tab = isset( $_GET['rk_tab'] ) ? sanitize_text_field( wp_unslash( $_GET['rk_tab'] ) ) : '';
        // phpcs:enable
        if ( preg_match( '/^[a-f0-9]{40}$/', $raw_tab ) ) {
            return '?rk_tab=' . $raw_tab;
        }

        // Enfant en session directe (cookie WP, sans rk_tab) : aucun param nécessaire.
        if ( RK_MC_Child_Restrictions::is_child_user() ) return '';

        $d      = self::load_data();
        $params = array();

        if ( ! empty( $d['child_id'] ) ) {
            $params['child_id'] = (int) $d['child_id'];
        }

        return $params ? '?' . http_build_query( $params ) : '';
    }

    private static function is_home(): bool {
        global $wp_query;
        $slug = $wp_query->query_vars['tutor_dashboard_page'] ?? null;
        return $slug === '' || $slug === null;
    }

    /**
     * v8.5.2 — Accesseur public à is_home(), pour les classes externes
     * (RK_MC_Dashboard_Chrome_V4::render_subpage_chrome_close()) qui ont
     * besoin de savoir si le hook after/wrap courant s'exécute sur la home
     * ou sur une sous-page, sans dupliquer la logique de détection du slug.
     */
    public static function is_home_public(): bool {
        return self::is_home();
    }

    /* ═══════════════════════════════════════════════════════════════════
       DATA LOADING
       ═══════════════════════════════════════════════════════════════════ */

    private static function load_data(): array {
        if ( self::$data_loaded ) return self::$data_cache ?? array();
        self::$data_loaded = true;

        $child = self::resolve_child();
        if ( ! $child ) { self::$data_cache = array(); return array(); }

        $child_id  = (int) $child->id;

        // Cache transient 5 minutes — invalidé par les hooks gamification
        $cache_key = 'rk_dash_' . $child_id;
        $cached    = get_transient( $cache_key );
        if ( $cached !== false ) {
            self::$data_cache = $cached;
            return $cached;
        }
        $parent_id = get_current_user_id();
        $wp_uid    = (int) ( $child->wp_user_id ?? 0 );

        // Gamification
        $total_points = class_exists( 'RK_MC_Gamification_Service' )
            ? RK_MC_Gamification_Service::get_total_points( $child_id ) : 0;
        $level = class_exists( 'RK_MC_Gamification_Service' )
            ? RK_MC_Gamification_Service::compute_level( $total_points ) : array();
        $welcome = class_exists( 'RK_MC_Gamification_Service' )
            ? RK_MC_Gamification_Service::get_welcome_message( $child_id, $child->first_name ?? '' )
            : array( 'text' => '', 'icon_key' => 'star' );

        // Badges
        $badges = class_exists( 'RK_MC_Badge_Service' )
            ? RK_MC_Badge_Service::get_badges( $child_id ) : array();

        // Missions coach (dynamiques)
        $missions = class_exists( 'RK_Coach_Missions' )
            ? RK_Coach_Missions::get_missions_for_child( $child_id )
            : array();

        // Skills
        $skills = class_exists( 'RK_MC_Skill_Service' )
            ? RK_MC_Skill_Service::get_skills( $child_id ) : array();

        // Upcoming session
        $session = class_exists( 'RK_MC_Child_Dashboard_Service' )
            ? RK_MC_Child_Dashboard_Service::get_upcoming_session( $child_id ) : null;

        // Assessment
        $assessment = class_exists( 'RK_MC_Assessment_Service' )
            ? RK_MC_Assessment_Service::get_latest( $child_id ) : null;

        // Messages — D2 : BP Better Messages (migration depuis wp_rk_child_messages)
        $coach_id_for_msg = class_exists( 'RK_MC_Booking_Bridge' )
            ? RK_MC_Booking_Bridge::get_coach_for_child( $child_id )
            : 0;

        // Unread count BP Better Messages
        $bm_unread = 0;
        if ( $wp_uid && function_exists( 'bm_get_table' ) ) {
            global $wpdb;
            $bm_unread = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM " . bm_get_table( 'recipients' ) . "
                  WHERE user_id = %d AND unread_count > 0",
                $wp_uid
            ) );
        }

        // Unread RK notifications (Sprint 2 — cloche topbar)
        $notif_unread = class_exists( 'RK_MC_Notification_Service' )
            ? RK_MC_Notification_Service::get_unread_count( $wp_uid ?: $parent_id )
            : 0;

        // Tutor LMS courses for child
        $active_courses  = null;
        $enrolled_count  = 0;
        $completed_count = 0;
        if ( $wp_uid && function_exists( 'tutor_utils' ) ) {
            $enrolled_ids    = tutor_utils()->get_enrolled_courses_ids_by_user( $wp_uid );
            $enrolled_count  = is_array( $enrolled_ids ) ? count( $enrolled_ids ) : 0;
            $completed_ids   = tutor_utils()->get_completed_courses_ids_by_user( $wp_uid );
            $completed_count = is_array( $completed_ids ) ? count( $completed_ids ) : 0;

            if ( class_exists( '\Tutor\Models\CourseModel' ) ) {
                $active_courses = \Tutor\Models\CourseModel::get_active_courses_by_user( $wp_uid );
            }
        }

        // Journey snapshot — source unique de vérité, partagée par tous les dashboards
        $snapshot = ( class_exists( 'RKP_JourneyQueryService' ) && $wp_uid )
            ? RKP_JourneyQueryService::getJourney( $wp_uid )
            : null;

        $done_missions = 0; // get_missions_for_child() returns only active (completed=0)

        // FT1 — Quizzes en attente Tutor LMS (attempt_started non soumis)
        $quiz_pending = 0;
        if ( $wp_uid && function_exists( 'tutor_utils' ) ) {
            global $wpdb;
            $quiz_pending = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}tutor_quiz_attempts
                  WHERE user_id = %d AND attempt_status = 'attempt_started'",
                $wp_uid
            ) );
        }

        self::$data_cache = compact(
            'child', 'child_id', 'parent_id', 'wp_uid',
            'total_points', 'level', 'welcome',
            'badges', 'missions', 'done_missions', 'skills',
            'session', 'assessment',
            'coach_id_for_msg', 'bm_unread', 'notif_unread',
            'active_courses', 'enrolled_count', 'completed_count',
            'quiz_pending',
            'snapshot'
        );

        set_transient( $cache_key, self::$data_cache, 5 * MINUTE_IN_SECONDS );
        return self::$data_cache;
    }

}