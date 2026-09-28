<?php
declare( strict_types=1 );
/**
 * RK_Coach_API — REST API endpoints pour le dashboard coach JWT.
 *
 * Namespace : rk/v1
 * Auth      : JWT via plugin "JWT Authentication for WP REST API"
 *             → Authorization: Bearer <token> sur chaque requête.
 *
 * @package RK_Coach_Hub
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class RK_Coach_API {

    public static function init(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
    }

    public static function register_routes(): void {
        $ns = 'rk/v1';

        // ── Données identité coach ───────────────────────────────────
        register_rest_route( $ns, '/coach/me', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'get_me' ],
            'permission_callback' => [ __CLASS__, 'is_coach' ],
        ] );

        // ── Profil coach (page الإعدادات) ────────────────────────────
        register_rest_route( $ns, '/coach/profile', [
            'methods'             => 'GET',
            'callback'            => [ 'RK_Coach_Profile_Controller', 'get_profile' ],
            'permission_callback' => [ __CLASS__, 'is_coach' ],
        ] );
        register_rest_route( $ns, '/coach/profile', [
            'methods'             => 'POST',
            'callback'            => [ 'RK_Coach_Profile_Controller', 'update_profile' ],
            'permission_callback' => [ __CLASS__, 'is_coach' ],
        ] );
        register_rest_route( $ns, '/coach/password', [
            'methods'             => 'POST',
            'callback'            => [ 'RK_Coach_Profile_Controller', 'change_password' ],
            'permission_callback' => [ __CLASS__, 'is_coach' ],
        ] );

        // ── Disponibilité coach (page الإعدادات → onglet التوفر) ─────
        // v2.8 — Écrit directement dans SSA (appointment_type_model), source
        // unique partagée avec le calendrier de réservation public.
        register_rest_route( $ns, '/coach/availability', [
            'methods'             => 'GET',
            'callback'            => [ 'RK_Coach_Availability_Controller', 'get_availability' ],
            'permission_callback' => [ __CLASS__, 'is_coach' ],
        ] );
        register_rest_route( $ns, '/coach/availability', [
            'methods'             => 'POST',
            'callback'            => [ 'RK_Coach_Availability_Controller', 'update_availability' ],
            'permission_callback' => [ __CLASS__, 'is_coach' ],
        ] );
        register_rest_route( $ns, '/coach/availability/capacity', [
            'methods'             => 'POST',
            'callback'            => [ 'RK_Coach_Availability_Controller', 'update_capacity_type' ],
            'permission_callback' => [ __CLASS__, 'is_coach' ],
        ] );

        // ── Données page d'accueil (tout en un call) ─────────────────
        register_rest_route( $ns, '/coach/home', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'get_home' ],
            'permission_callback' => [ __CLASS__, 'is_coach' ],
        ] );

        // ── Liste des élèves ─────────────────────────────────────────
        register_rest_route( $ns, '/coach/students', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'get_students' ],
            'permission_callback' => [ __CLASS__, 'is_coach' ],
        ] );

        // ── Rafraîchir le JWT silencieusement (basé sur le cookie WP) ─
        // Le cookie WP (session PHP) peut rester valide bien après
        // l'expiration du token JWT stocké côté client. Sans cette
        // route, le SPA envoie systématiquement le coach vers
        // /connexion-coach/?expired=1 dès que le JWT expire, même si
        // le coach est toujours authentifié sur WordPress.
        register_rest_route( $ns, '/coach/refresh-token', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'refresh_token' ],
            // Auth par cookie WP uniquement (pas de Bearer requis ici) :
            // c'est justement le but, le JWT est peut-être déjà mort.
            'permission_callback' => static function () {
                return is_user_logged_in()
                    && class_exists( 'RK_Coach_Dashboard' )
                    && RK_Coach_Dashboard::is_coach_user( wp_get_current_user() );
            },
        ] );

        // ── Séances (?range=today|week|month) ───────────────────────
        register_rest_route( $ns, '/coach/sessions', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'get_sessions' ],
            'permission_callback' => [ __CLASS__, 'is_coach' ],
        ] );

        // ── Fiche élève (?child_id=N) ────────────────────────────────
        register_rest_route( $ns, '/coach/student', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'get_student' ],
            'permission_callback' => [ __CLASS__, 'is_coach' ],
        ] );

        // ── Marquer présence ─────────────────────────────────────────
        register_rest_route( $ns, '/coach/attendance', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'mark_attendance' ],
            'permission_callback' => [ __CLASS__, 'is_coach' ],
        ] );

        // v9.34 — Leçons en attente pour peupler le menu déroulant du
        // formulaire de présence (شارات المغامرات à valider).
        register_rest_route( $ns, '/coach/session/pending-lessons', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'get_pending_lessons' ],
            'permission_callback' => [ __CLASS__, 'is_coach' ],
        ] );

        // ── Sauvegarder URL Zoom d'une séance ────────────────────────
        register_rest_route( $ns, '/coach/session/zoom', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'save_session_zoom' ],
            'permission_callback' => [ __CLASS__, 'is_coach' ],
        ] );

        // ── Évaluations d'un enfant ───────────────────────────────────
        register_rest_route( $ns, '/coach/child/evals', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'get_child_evals' ],
            'permission_callback' => [ __CLASS__, 'is_coach' ],
        ] );

        // ── Séances d'un enfant à une date donnée (sélecteur "اللقاء" du
        //    formulaire تقييم جديد — voir doc RKP_AssessmentRepository::
        //    ensure_schema() pour le lien booking_id) ─────────────────
        register_rest_route( $ns, '/coach/child/bookings-for-date', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'get_child_bookings_for_date' ],
            'permission_callback' => [ __CLASS__, 'is_coach' ],
        ] );

        // ── Cours distincts d'un enfant (filtre "الدورة" — enfant avec
        //    plusieurs cours) ──────────────────────────────────────────
        register_rest_route( $ns, '/coach/child/courses', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'get_child_courses' ],
            'permission_callback' => [ __CLASS__, 'is_coach' ],
        ] );

        // ── Sauvegarder une évaluation ───────────────────────────────
        register_rest_route( $ns, '/coach/child/eval', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'save_child_eval' ],
            'permission_callback' => [ __CLASS__, 'is_coach' ],
        ] );

        // ── Envoyer un message au parent ─────────────────────────────
        register_rest_route( $ns, '/coach/message', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'send_message' ],
            'permission_callback' => [ __CLASS__, 'is_coach' ],
        ] );

        // ── Messagerie BM : liste des conversations ───────────────────
        register_rest_route( $ns, '/coach/messages', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'get_messages' ],
            'permission_callback' => [ __CLASS__, 'is_coach' ],
        ] );

        // ── Messagerie BM : messages d'un thread ─────────────────────
        register_rest_route( $ns, '/coach/messages/thread', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'get_thread' ],
            'permission_callback' => [ __CLASS__, 'is_coach' ],
        ] );

        // ── Messagerie BM : envoyer un message ───────────────────────
        register_rest_route( $ns, '/coach/messages/send', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'send_bm_message' ],
            'permission_callback' => [ __CLASS__, 'is_coach' ],
        ] );

        // ── Notifications coach (bell SPA) ────────────────────────────
        register_rest_route( $ns, '/coach/notifications', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'get_coach_notifications' ],
            'permission_callback' => [ __CLASS__, 'is_coach' ],
        ] );
        register_rest_route( $ns, '/coach/notifications/read', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'mark_coach_notifications_read' ],
            'permission_callback' => [ __CLASS__, 'is_coach' ],
        ] );

        // ── Créer une réservation de séance ──────────────────────────
        register_rest_route( $ns, '/coach/booking', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'create_booking' ],
            'permission_callback' => [ __CLASS__, 'is_coach' ],
        ] );

        // ── Tasks globales (missions RK + quiz Tutor) ─────────────────
        register_rest_route( $ns, '/coach/tasks', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'get_tasks' ],
            'permission_callback' => [ __CLASS__, 'is_coach' ],
        ] );

        // ── Tasks d'un enfant (Tutor assignments + missions RK) ───────
        register_rest_route( $ns, '/coach/child/tasks', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'get_child_tasks' ],
            'permission_callback' => [ __CLASS__, 'is_coach' ],
        ] );

        // ── Créer une mission RK ──────────────────────────────────────
        register_rest_route( $ns, '/coach/mission', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'create_mission' ],
            'permission_callback' => [ __CLASS__, 'is_coach' ],
        ] );

        // ── Avancer la progression d'une mission ──────────────────────
        register_rest_route( $ns, '/coach/mission/(?P<id>\d+)/progress', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'progress_mission' ],
            'permission_callback' => [ __CLASS__, 'is_coach' ],
        ] );

        // ── Supprimer une mission ─────────────────────────────────────
        register_rest_route( $ns, '/coach/mission/(?P<id>\d+)', [
            'methods'             => 'DELETE',
            'callback'            => [ __CLASS__, 'delete_mission' ],
            'permission_callback' => [ __CLASS__, 'is_coach' ],
        ] );

        // ── Certificats Tutor LMS (?child_id=N optionnel) ────────────
        register_rest_route( $ns, '/coach/certificates', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'get_certificates' ],
            'permission_callback' => [ __CLASS__, 'is_coach' ],
        ] );

        // ── Toutes les évaluations (tous enfants du coach) ───────────
        register_rest_route( $ns, '/coach/evals', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'get_evals' ],
            'permission_callback' => [ __CLASS__, 'is_coach' ],
        ] );

        // ── Quiz d'un enfant (?child_id=N) ────────────────────────────
        register_rest_route( $ns, '/coach/child/quizzes', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'get_child_quizzes' ],
            'permission_callback' => [ __CLASS__, 'is_coach' ],
        ] );

        // ── Créer un quiz Tutor LMS via SPA (JWT) ─────────────────────
        register_rest_route( $ns, '/coach/quiz/create', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'create_quiz' ],
            'permission_callback' => [ __CLASS__, 'is_coach' ],
        ] );

        // ── Détail d'un quiz (preview SPA sans session WP) ────────────
        register_rest_route( $ns, '/coach/quiz/(?P<id>\d+)', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'get_quiz_detail' ],
            'permission_callback' => [ __CLASS__, 'is_coach' ],
        ] );

        // ── Modifier un quiz existant (titre, grade, questions) ────────
        register_rest_route( $ns, '/coach/quiz/(?P<id>\d+)/update', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'update_quiz' ],
            'permission_callback' => [ __CLASS__, 'is_coach' ],
        ] );

        // ── Notifier parent après qu'un enfant finit un quiz ──────────
        // Auth : cookie WP (enfant connecté), pas JWT coach.
        register_rest_route( $ns, '/quiz/result-notify', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'notify_quiz_result' ],
            'permission_callback' => function () { return is_user_logged_in(); },
        ] );

        // ── Résultats des quiz d'un enfant (dashboard coach) ──────────
        register_rest_route( $ns, '/coach/child/quiz-results', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'get_child_quiz_results' ],
            'permission_callback' => [ __CLASS__, 'is_coach' ],
        ] );

        // v9.10 — Routes /coach/badges, /coach/child/badge(s), /coach/badge/custom/*
        // retirées : servaient exclusivement la SPA #badges, éliminée au profit
        // de la seule page Tutor native (RK_Coach_Badges), décision explicite
        // de l'utilisateur pour une vue cohérente unique.

        // v9.11 — /coach/child/badges (GET, read-only) restaurée : sert
        // uniquement l'onglet "الشارات" de la fiche élève dans la SPA
        // students (rk-coach-page-students.js), qui est un écran distinct
        // de la SPA #badges éliminée en v9.10. Même source de vérité
        // (RKP_BadgeQueryService) que la page Tutor native — aucune
        // logique dupliquée, aucune écriture réintroduite.
        register_rest_route( $ns, '/coach/child/badges', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'get_child_badges' ],
            'permission_callback' => [ __CLASS__, 'is_coach' ],
        ] );

        // v9.16 — Page #badges de la SPA coach : CRUD complet, en plus
        // de la page Tutor native (RK_Coach_Badges) qui reste intacte.
        // Même source de vérité que le formulaire PHP historique
        // (RK_Coach_Badges) et que le catalogue enfant (rk-badges.php).
        register_rest_route( $ns, '/coach/badges', [
            'methods'             => 'GET',
            'callback'            => [ 'RK_Coach_Badges_Controller', 'get_overview' ],
            'permission_callback' => [ __CLASS__, 'is_coach' ],
        ] );
        register_rest_route( $ns, '/coach/badges/child', [
            'methods'             => 'GET',
            'callback'            => [ 'RK_Coach_Badges_Controller', 'get_child_view' ],
            'permission_callback' => [ __CLASS__, 'is_coach' ],
        ] );
        register_rest_route( $ns, '/coach/badges/award', [
            'methods'             => 'POST',
            'callback'            => [ 'RK_Coach_Badges_Controller', 'award' ],
            'permission_callback' => [ __CLASS__, 'is_coach' ],
        ] );
        register_rest_route( $ns, '/coach/badges/revoke', [
            'methods'             => 'POST',
            'callback'            => [ 'RK_Coach_Badges_Controller', 'revoke' ],
            'permission_callback' => [ __CLASS__, 'is_coach' ],
        ] );
        register_rest_route( $ns, '/coach/badges/custom', [
            'methods'             => 'POST',
            'callback'            => [ 'RK_Coach_Badges_Controller', 'create_custom' ],
            'permission_callback' => [ __CLASS__, 'is_coach' ],
        ] );
        register_rest_route( $ns, '/coach/badges/custom/(?P<badge_key>[a-zA-Z0-9_]+)', [
            'methods'             => 'POST',
            'callback'            => [ 'RK_Coach_Badges_Controller', 'update_custom' ],
            'permission_callback' => [ __CLASS__, 'is_coach' ],
        ] );
        register_rest_route( $ns, '/coach/badges/custom/(?P<badge_key>[a-zA-Z0-9_]+)', [
            'methods'             => 'DELETE',
            'callback'            => [ 'RK_Coach_Badges_Controller', 'delete_custom' ],
            'permission_callback' => [ __CLASS__, 'is_coach' ],
        ] );

        // v9.59 — MANQUAIT : upload_icon() existe dans le controller depuis
        // v9.20 (voir la note dans le controller) mais sa route n'a jamais
        // été enregistrée. openBadgeForm() l'appelle déjà côté SPA — tout
        // changement d'icône échouait en 404 silencieux.
        register_rest_route( $ns, '/coach/badges/custom/(?P<badge_key>[a-zA-Z0-9_]+)/upload-icon', [
            'methods'             => 'POST',
            'callback'            => [ 'RK_Coach_Badges_Controller', 'upload_icon' ],
            'permission_callback' => [ __CLASS__, 'is_coach' ],
        ] );

        // v9.59 — Pré-vérification avant suppression : nombre d'enfants
        // affectés, pour l'avertissement côté frontend AVANT confirmation
        // ("Ce badge est attribué à X enfant(s)...").
        register_rest_route( $ns, '/coach/badges/custom/(?P<badge_key>[a-zA-Z0-9_]+)/impact', [
            'methods'             => 'GET',
            'callback'            => [ 'RK_Coach_Badges_Controller', 'delete_impact' ],
            'permission_callback' => [ __CLASS__, 'is_coach' ],
        ] );

        // ── Mettre à jour les compétences d'un enfant ─────────────────
        register_rest_route( $ns, '/coach/child/skills', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'save_child_skills' ],
            'permission_callback' => [ __CLASS__, 'is_coach' ],
        ] );

        // ── Statistiques complètes (page #stats SPA) ──────────────────
        register_rest_route( $ns, '/coach/stats', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'get_stats' ],
            'permission_callback' => [ __CLASS__, 'is_coach' ],
        ] );
    }

    /* ─── Permission callback ────────────────────────────────────── */

    public static function is_coach(): bool {
        if ( ! is_user_logged_in() ) return false;
        return RK_Coach_Dashboard::is_coach_user( wp_get_current_user() );
    }

    /* ─── GET /coach/refresh-token ──────────────────────────────────
     * Émet un nouveau JWT pour le coach déjà authentifié via cookie WP.
     * Réutilise directement le plugin "JWT Authentication for WP REST
     * API" (mêmes filtres que /wp-json/jwt-auth/v1/token) plutôt que
     * de dupliquer sa logique de signature.
     */
    public static function refresh_token(): WP_REST_Response {
        $user = wp_get_current_user();

        if ( ! function_exists( 'jwt_auth_rest_api_init' ) && ! class_exists( 'Jwt_Auth_Public' )
             && ! defined( 'JWT_AUTH_SECRET_KEY' ) ) {
            return new WP_REST_Response( [ 'code' => 'jwt_plugin_missing' ], 500 );
        }

        $issued_at  = time();
        $not_before = apply_filters( 'jwt_auth_not_before', $issued_at, $issued_at );
        $expire     = apply_filters(
            'jwt_auth_expire',
            $issued_at + ( DAY_IN_SECONDS * 7 ),
            $issued_at
        );

        $token = [
            'iss'  => get_bloginfo( 'url' ),
            'iat'  => $issued_at,
            'nbf'  => $not_before,
            'exp'  => $expire,
            'data' => [ 'user' => [ 'id' => (string) $user->ID ] ],
        ];

        $secret = defined( 'JWT_AUTH_SECRET_KEY' ) ? JWT_AUTH_SECRET_KEY : '';
        if ( '' === $secret || ! class_exists( '\Firebase\JWT\JWT' ) ) {
            return new WP_REST_Response( [ 'code' => 'jwt_secret_missing' ], 500 );
        }

        $jwt = \Firebase\JWT\JWT::encode( $token, $secret, 'HS256' );

        return new WP_REST_Response( [
            'token'   => $jwt,
            'exp'     => $expire,
            'id'      => $user->ID,
            'name'    => $user->display_name,
            'avatar'  => get_avatar_url( $user->ID ),
        ], 200 );
    }

    /* ─── GET /coach/stats ──────────────────────────────────────── */

    public static function get_stats(): WP_REST_Response {
        $coach_id = get_current_user_id();
        $year     = (int) date( 'Y' );
        $month    = (int) date( 'n' );

        // 6 derniers mois (courbe + barres)
        $monthly = [];
        for ( $i = 5; $i >= 0; $i-- ) {
            $m = $month - $i;
            $y = $year;
            while ( $m < 1 ) { $m += 12; $y--; }
            $s         = RK_Coach_Data::get_monthly_stats( $coach_id, $y, $m );
            $monthly[] = [
                'year'       => $y,
                'month'      => $m,
                'sessions'   => (int) ( $s['sessions']        ?? 0 ),
                'attendance' => (int) ( $s['attendance_rate'] ?? 0 ),
                'evals'      => (int) ( $s['evals_written']   ?? 0 ),
                'messages'   => (int) ( $s['parent_messages'] ?? 0 ),
            ];
        }

        // Présence par élève (triés par taux croissant — les plus à risque en premier)
        $students_raw = RK_Coach_Data::get_coach_students( $coach_id );
        $students     = [];
        foreach ( $students_raw as $st ) {
            $cid       = (int) $st->child_id;
            $att       = RK_Coach_Data::get_child_attendance_rate( $cid, $coach_id );
            $consec    = RK_Coach_Data::get_consecutive_absences( $cid );
            $students[] = [
                'id'         => $cid,
                'name'       => trim( (string) $st->child_name . ' ' . (string) ( $st->child_family_name ?? '' ) ),
                'attendance' => $att,
                'absences'   => $consec,
            ];
        }
        usort( $students, static fn( $a, $b ) => $a['attendance'] <=> $b['attendance'] );

        $curr = end( $monthly );
        $prev = count( $monthly ) >= 2 ? $monthly[ count( $monthly ) - 2 ] : $curr;

        return new WP_REST_Response( [
            'monthly'  => $monthly,
            'students' => $students,
            'alerts'   => RK_Coach_Students::get_smart_alerts( $coach_id ),
            'current'  => $curr,
            'previous' => $prev,
        ], 200 );
    }

    /* ─── GET /coach/me ──────────────────────────────────────────── */

    public static function get_me(): WP_REST_Response {
        $user = wp_get_current_user();
        return new WP_REST_Response( [
            'id'     => $user->ID,
            'name'   => $user->first_name ?: $user->display_name,
            'email'  => $user->user_email,
            'avatar' => get_avatar_url( $user->ID, [ 'size' => 64 ] ),
        ], 200 );
    }

    /* ─── GET /coach/home ────────────────────────────────────────── */

    public static function get_home(): WP_REST_Response {
        $id   = get_current_user_id();
        $user = wp_get_current_user();

        $stats   = RK_Coach_Data::get_coach_stats( $id );
        $next    = RK_Coach_Data::get_next_session_soon( $id, 120 );
        $pending = RK_Coach_Data::get_pending_evals_count( $id );
        $unread  = RK_Coach_Data::get_unread_messages_count( $id );
        $alerts  = RK_Coach_Students::get_smart_alerts( $id );
        $today   = RK_Coach_Data::get_sessions_by_range( $id, 'today' );

        return new WP_REST_Response( [
            'coach'   => [
                'id'       => $id,
                'name'     => $user->first_name ?: $user->display_name,
                'avatar'   => get_avatar_url( $id, [ 'size' => 64 ] ),
                'greeting' => RK_Coach_Data::greeting_arabic(),
            ],
            'stats'   => array_map( 'intval', (array) $stats ),
            'next'    => $next,
            'pending' => $pending,
            'unread'  => $unread,
            'alerts'  => array_values( (array) $alerts ),
            'today'   => array_values( (array) $today ),
        ], 200 );
    }

    /* ─── GET /coach/students ────────────────────────────────────── */

    /** Délègue à RK_Coach_Students_Controller (v2.4 — découpage God Service). */
    public static function get_students(): WP_REST_Response {
        return RK_Coach_Students_Controller::get_students();
    }

    /** Délègue à RK_Coach_Sessions_Controller (v2.4 — découpage God Service). */
    public static function get_sessions( WP_REST_Request $request ): WP_REST_Response {
        return RK_Coach_Sessions_Controller::get_sessions( $request );
    }

    /** Délègue à RK_Coach_Students_Controller (v2.4 — découpage God Service). */
    public static function get_student( WP_REST_Request $request ): WP_REST_Response {
        return RK_Coach_Students_Controller::get_student( $request );
    }

    /** Délègue à RK_Coach_Sessions_Controller (v2.4 — découpage God Service). */
    public static function mark_attendance( WP_REST_Request $request ): WP_REST_Response {
        return RK_Coach_Sessions_Controller::mark_attendance( $request );
    }

    /** Délègue à RK_Coach_Sessions_Controller (v9.34). */
    public static function get_pending_lessons( WP_REST_Request $request ): WP_REST_Response {
        return RK_Coach_Sessions_Controller::get_pending_lessons( $request );
    }

    /** Délègue à RK_Coach_Sessions_Controller (v2.4 — découpage God Service). */
    public static function save_session_zoom( WP_REST_Request $request ): WP_REST_Response {
        return RK_Coach_Sessions_Controller::save_session_zoom( $request );
    }

    /** Délègue à RK_Coach_Students_Controller (v2.4 — découpage God Service). */
    public static function get_child_evals( WP_REST_Request $request ): WP_REST_Response {
        return RK_Coach_Students_Controller::get_child_evals( $request );
    }

    /** Délègue à RK_Coach_Students_Controller. Séances d'un enfant à une date
     * donnée — alimente le sélecteur "اللقاء" du formulaire تقييم جديد. */
    public static function get_child_bookings_for_date( WP_REST_Request $request ): WP_REST_Response {
        return RK_Coach_Students_Controller::get_child_bookings_for_date( $request );
    }

    /** Délègue à RK_Coach_Students_Controller. Cours distincts d'un enfant
     * — alimente le filtre "الدورة" quand l'enfant suit plusieurs cours. */
    public static function get_child_courses( WP_REST_Request $request ): WP_REST_Response {
        return RK_Coach_Students_Controller::get_child_courses( $request );
    }

    /** Délègue à RK_Coach_Students_Controller (v9.11 — restauration ciblée
     * de l'onglet Badges dans la fiche élève ; la SPA #badges autonome reste
     * éliminée, seule cette lecture read-only pour la fiche élève revient). */
    public static function get_child_badges( WP_REST_Request $request ): WP_REST_Response {
        return RK_Coach_Students_Controller::get_child_badges( $request );
    }

    /** Délègue à RK_Coach_Students_Controller (v2.4 — découpage God Service). */
    public static function save_child_eval( WP_REST_Request $request ): WP_REST_Response {
        return RK_Coach_Students_Controller::save_child_eval( $request );
    }

    /** Délègue à RK_Coach_Messages_Controller (v2.4 — découpage God Service). */
    public static function send_message( WP_REST_Request $request ): WP_REST_Response {
        return RK_Coach_Messages_Controller::send_message( $request );
    }

    /** Délègue à RK_Coach_Messages_Controller (v2.4 — découpage God Service). */
    public static function get_messages(): WP_REST_Response {
        return RK_Coach_Messages_Controller::get_messages();
    }

    /** Délègue à RK_Coach_Messages_Controller (v2.4 — découpage God Service). */
    public static function get_thread( WP_REST_Request $request ): WP_REST_Response {
        return RK_Coach_Messages_Controller::get_thread( $request );
    }

    /** Délègue à RK_Coach_Messages_Controller (v2.4 — découpage God Service). */
    public static function send_bm_message( WP_REST_Request $request ): WP_REST_Response {
        return RK_Coach_Messages_Controller::send_bm_message( $request );
    }

    public static function get_coach_notifications(): WP_REST_Response {
        global $wpdb;
        $coach_id = get_current_user_id();
        $table    = $wpdb->prefix . 'rk_notifications';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return new WP_REST_Response( [ 'items' => [], 'unread' => 0 ], 200 );
        }
        $items = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, type, title, body, is_read,
                    DATE_FORMAT(created_at, '%%d/%%m %%H:%%i') AS date_fmt
               FROM {$table}
              WHERE user_id = %d
              ORDER BY created_at DESC LIMIT 20",
            $coach_id
        ) ) ?: [];
        $unread = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND is_read = 0",
            $coach_id
        ) );
        return new WP_REST_Response( [ 'items' => $items, 'unread' => $unread ], 200 );
    }

    /* ─── POST /coach/notifications/read ───────────────────────── */

    public static function mark_coach_notifications_read(): WP_REST_Response {
        global $wpdb;
        $coach_id = get_current_user_id();

        // DEBUG TEMPORAIRE — à retirer une fois la cause trouvée.
        error_log( '[RK_DEBUG] mark_coach_notifications_read appelé pour user_id=' . $coach_id
            . ' | backtrace: ' . wp_debug_backtrace_summary( null, 0, false ) );

        $table    = $wpdb->prefix . 'rk_notifications';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) {
            $wpdb->update( $table, [ 'is_read' => 1 ], [ 'user_id' => $coach_id, 'is_read' => 0 ] );
        }
        return new WP_REST_Response( [ 'ok' => true ], 200 );
    }

    /* ─── POST /coach/booking ───────────────────────────────────── */

    /** Délègue à RK_Coach_Sessions_Controller (v2.4 — découpage God Service). */
    public static function create_booking( WP_REST_Request $request ): WP_REST_Response {
        return RK_Coach_Sessions_Controller::create_booking( $request );
    }

    /** Délègue à RK_Coach_Missions_Controller (v2.4 — découpage God Service). */
    public static function get_tasks( WP_REST_Request $request ): WP_REST_Response {
        return RK_Coach_Missions_Controller::get_tasks( $request );
    }

    /** Délègue à RK_Coach_Missions_Controller (v2.4 — découpage God Service). */
    public static function get_child_tasks( WP_REST_Request $request ): WP_REST_Response {
        return RK_Coach_Missions_Controller::get_child_tasks( $request );
    }

    /** Délègue à RK_Coach_Missions_Controller (v2.4 — découpage God Service). */
    public static function create_mission( WP_REST_Request $request ): WP_REST_Response {
        return RK_Coach_Missions_Controller::create_mission( $request );
    }

    /** Délègue à RK_Coach_Missions_Controller (v2.4 — découpage God Service). */
    public static function progress_mission( WP_REST_Request $request ): WP_REST_Response {
        return RK_Coach_Missions_Controller::progress_mission( $request );
    }

    /** Délègue à RK_Coach_Missions_Controller (v2.4 — découpage God Service). */
    public static function delete_mission( WP_REST_Request $request ): WP_REST_Response {
        return RK_Coach_Missions_Controller::delete_mission( $request );
    }

    /** Délègue à RK_Coach_Missions_Controller (v2.4 — découpage God Service). */
    public static function get_certificates( WP_REST_Request $request ): WP_REST_Response {
        return RK_Coach_Missions_Controller::get_certificates( $request );
    }

    /** Délègue à RK_Coach_Students_Controller (v2.4 — découpage God Service). */
    public static function get_evals(): WP_REST_Response {
        return RK_Coach_Students_Controller::get_evals();
    }

    /** Délègue à RK_Coach_Quizzes_Controller (v2.4 — découpage God Service). */
    public static function get_quiz_detail( WP_REST_Request $request ): WP_REST_Response {
        return RK_Coach_Quizzes_Controller::get_quiz_detail( $request );
    }

    /** Délègue à RK_Coach_Quizzes_Controller (v2.4 — découpage God Service). */
    public static function update_quiz( WP_REST_Request $request ): WP_REST_Response {
        return RK_Coach_Quizzes_Controller::update_quiz( $request );
    }

    /** Délègue à RK_Coach_Quizzes_Controller (v2.4 — découpage God Service). */
    public static function notify_quiz_result( WP_REST_Request $request ): WP_REST_Response {
        return RK_Coach_Quizzes_Controller::notify_quiz_result( $request );
    }

    /** Délègue à RK_Coach_Quizzes_Controller (v2.4 — découpage God Service). */
    public static function get_child_quiz_results( WP_REST_Request $request ): WP_REST_Response {
        return RK_Coach_Quizzes_Controller::get_child_quiz_results( $request );
    }

    /** Délègue à RK_Coach_Quizzes_Controller (v2.4 — découpage God Service). */
    public static function get_child_quizzes( WP_REST_Request $request ): WP_REST_Response {
        return RK_Coach_Quizzes_Controller::get_child_quizzes( $request );
    }

    /** Délègue à RK_Coach_Quizzes_Controller (v2.4 — découpage God Service). */
    public static function create_quiz( WP_REST_Request $request ): WP_REST_Response {
        return RK_Coach_Quizzes_Controller::create_quiz( $request );
    }

    // v9.45 — 3 méthodes de délégation vers RK_Coach_Qna_Controller
    // retirées avec les routes correspondantes (voir plus haut) —
    // module سؤال وجواب éliminé du dashboard coach, décision du
    // 13/08/2026.

    // v9.10 — 7 méthodes de délégation vers RK_Coach_Badges_Controller
    // retirées avec les routes correspondantes (voir plus haut).

    /** Délègue à RK_Coach_Students_Controller (v2.4 — découpage God Service). */
    public static function save_child_skills( WP_REST_Request $request ): WP_REST_Response {
        return RK_Coach_Students_Controller::save_child_skills( $request );
    }

}