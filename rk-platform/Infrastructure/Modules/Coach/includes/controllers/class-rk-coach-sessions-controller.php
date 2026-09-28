<?php
declare( strict_types=1 );
/**
 * RK_Coach_Sessions_Controller  (v2.4.0 — Audit P3-14)
 *
 * Sessions : planning, présence, lien Zoom, création de réservation.
 * Extrait du God Service RK_Coach_API (2 527 lignes) — corps des méthodes
 * strictement inchangé ; seuls les helpers partagés pointent désormais
 * vers RK_Coach_Api_Helpers. Le routing et l'auth restent dans RK_Coach_API.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class RK_Coach_Sessions_Controller {

    public static function get_sessions( WP_REST_Request $request ): WP_REST_Response {
        $coach_id = get_current_user_id();
        $range    = sanitize_text_field( $request->get_param( 'range' ) ?: 'week' );
        // Whitelist range values
        if ( ! in_array( $range, [ 'today', 'week', 'month' ], true ) ) $range = 'week';
        $sessions = RK_Coach_Data::get_sessions_by_range( $coach_id, $range );
        return new WP_REST_Response( array_values( (array) $sessions ), 200 );
    }

    /* ─── GET /coach/student?child_id=N ─────────────────────────── */

    public static function mark_attendance( WP_REST_Request $request ): WP_REST_Response {
        $coach_id   = get_current_user_id();
        $booking_id = (int) $request->get_param( 'booking_id' );
        $status     = sanitize_text_field( $request->get_param( 'status' ) ?: '' );
        // v9.34 — Champ optionnel : le coach peut désigner la leçon
        // شارات المغامرات à valider pour cette séance (aucune
        // correspondance fixe séance→cours — décision du 12/08/2026).
        $lesson_id  = (int) $request->get_param( 'lesson_id' );

        if ( ! $booking_id || ! in_array( $status, [ 'present', 'absent' ], true ) ) {
            return new WP_REST_Response( [ 'code' => 'invalid_params' ], 400 );
        }

        $booking = RKP_CoachSessionRepository::find_booking_basic( $booking_id );
        if ( ! $booking ) {
            return new WP_REST_Response( [ 'code' => 'not_found' ], 404 );
        }
        $students = RK_Coach_Data::get_coach_students( $coach_id );
        $allowed  = array_map( fn( $s ) => (int) $s->child_id, (array) $students );
        $child_id = (int) $booking->child_id;
        if ( ! in_array( $child_id, $allowed, true ) ) {
            return new WP_REST_Response( [ 'code' => 'not_authorized' ], 403 );
        }

        $prev_status = (string) ( $booking->attendance ?? '' );
        $updated     = RKP_CoachSessionRepository::mark_attendance( $booking_id, $status );
        if ( ! $updated ) {
            return new WP_REST_Response( [ 'code' => 'db_error' ], 500 );
        }

        // v9.34 — Parité avec l'ancien formulaire admin-post
        // (handle_mark_attendance) : déclenche rk_session_attended /
        // rk_session_absent, consommés par RK_Event_Bus pour XP,
        // crédit, badges de streak (streak_week, perfect_month) et
        // notifications. Absent de cet endpoint REST jusqu'ici — sans
        // ce hook, une présence marquée depuis la SPA ne donnait ni
        // XP ni badge, contrairement à /dashboard/rk-seances/.
        if ( $child_id > 0 && $status !== $prev_status ) {
            if ( $status === 'present' ) {
                do_action( 'rk_session_attended', $child_id, $booking_id, $prev_status );
            } elseif ( $status === 'absent' ) {
                do_action( 'rk_session_absent', $child_id, $booking_id, $prev_status );
            }
        }

        // v9.34 — Validation de la leçon شارات المغامرات choisie par
        // le coach, uniquement si présence effective. BLINDÉ (12/08/2026)
        // : ce bloc ne doit JAMAIS faire échouer mark_attendance dans son
        // ensemble — la présence elle-même (déjà enregistrée ci-dessus)
        // est la donnée critique, la validation de leçon est un bonus.
        // Un try/catch enveloppe tout l'appel, pas seulement l'intérieur
        // de la méthode du repository, au cas où l'erreur proviendrait
        // d'un hook tiers (tutor_lesson_completed_after) déclenché en
        // cascade plutôt que du code de ce plugin lui-même.
        if ( $lesson_id > 0 && $status === 'present' && $child_id > 0
             && class_exists( 'RKP_ChildRepository' ) && class_exists( 'RKP_ProgressRepository' ) ) {
            try {
                $wp_user_id = RKP_ChildRepository::get_wp_user_id( $child_id );
                if ( $wp_user_id > 0 ) {
                    RKP_ProgressRepository::mark_lesson_complete_for_child( $lesson_id, $wp_user_id );
                }
            } catch ( \Throwable $e ) {
                rkp_log( sprintf(
                    '[RK Coach Sessions] Validation de leçon échouée (présence déjà enregistrée) — booking=%d lesson=%d : %s',
                    $booking_id, $lesson_id, $e->getMessage()
                ) );
            }
        }

        if ( method_exists( 'RK_Coach_Data', 'bust_for_coach' ) ) {
            RK_Coach_Data::bust_for_coach( $coach_id );
        }
        do_action( 'rk_mc_bust_child_caches', $child_id );
        return new WP_REST_Response( [ 'success' => true, 'status' => $status ], 200 );
    }

    /* ─── GET /coach/session/pending-lessons?child_id=N ─────────────
     * v9.34 — Leçons non complétées de l'enfant, groupées par cours,
     * pour peupler le menu déroulant "leçon" du formulaire de présence
     * côté SPA (espace-coach/#séances). Utilisée juste avant l'appel à
     * mark_attendance avec lesson_id.
     */
    /* ─── GET /coach/session/pending-lessons?booking_id=N ───────────
     * v9.35 — Leçons non complétées du cours DÉJÀ CHOISI lors du
     * booking (course_id sur wp_rk_bookings, fixé par le parent à la
     * réservation) — décision explicite de l'utilisateur (12/08/2026)
     * de ne plus proposer de choix de cours au coach, uniquement la
     * leçon. Remplace l'ancienne version indexée par child_id (qui
     * parcourait TOUS les cours de l'enfant, inutilement complexe vu
     * que le cours de la séance est déjà connu).
     */
    public static function get_pending_lessons( WP_REST_Request $request ): WP_REST_Response {
        $coach_id   = get_current_user_id();
        $booking_id = (int) $request->get_param( 'booking_id' );
        if ( ! $booking_id ) {
            return new WP_REST_Response( [ 'code' => 'missing_booking_id' ], 400 );
        }

        $booking = RKP_CoachSessionRepository::find_booking_basic( $booking_id );
        if ( ! $booking ) {
            return new WP_REST_Response( [ 'code' => 'not_found' ], 404 );
        }

        $child_id = (int) $booking->child_id;
        $students = RK_Coach_Data::get_coach_students( $coach_id );
        $allowed  = array_map( fn( $s ) => (int) $s->child_id, (array) $students );
        if ( ! in_array( $child_id, $allowed, true ) ) {
            return new WP_REST_Response( [ 'code' => 'not_authorized' ], 403 );
        }

        $course_id = (int) ( $booking->course_id ?? 0 );
        if ( ! $course_id ) {
            // Séance sans cours associé (ex: type de rendez-vous non lié
            // à Tutor) — pas une erreur, juste aucune leçon à proposer.
            return new WP_REST_Response( [ 'lessons' => [] ], 200 );
        }

        if ( ! class_exists( 'RKP_ChildRepository' ) || ! class_exists( 'RKP_LearningQueryService' ) ) {
            return new WP_REST_Response( [ 'code' => 'service_unavailable' ], 500 );
        }

        $wp_user_id = RKP_ChildRepository::get_wp_user_id( $child_id );
        $lessons    = RKP_LearningQueryService::get_pending_lessons_for_course( $course_id, $wp_user_id );

        return new WP_REST_Response( [
            'lessons' => array_map( static fn( RKP_Lesson $l ) => [
                'id'    => $l->id,
                'title' => $l->title,
            ], $lessons ),
        ], 200 );
    }

    /* ─── POST /coach/session/zoom ──────────────────────────────── */

    public static function save_session_zoom( WP_REST_Request $request ): WP_REST_Response {
        $coach_id   = get_current_user_id();
        $booking_id = (int) $request->get_param( 'booking_id' );
        $url        = esc_url_raw( (string) ( $request->get_param( 'meeting_url' ) ?: '' ) );

        if ( ! $booking_id ) {
            return new WP_REST_Response( [ 'code' => 'invalid_params' ], 400 );
        }
        if ( ! RK_Coach_Data::has_booking_column( 'meeting_url' ) ) {
            return new WP_REST_Response( [ 'code' => 'column_missing' ], 500 );
        }

        $booking = RKP_CoachSessionRepository::find_booking_basic( $booking_id );
        if ( ! $booking ) {
            return new WP_REST_Response( [ 'code' => 'not_found' ], 404 );
        }

        // Vérifier l'ownership : enfant dans la liste du coach ou type SSA appartenant au coach
        $students = RK_Coach_Data::get_coach_students( $coach_id );
        $allowed  = array_map( fn( $s ) => (int) $s->child_id, (array) $students );
        if ( ! in_array( (int) $booking->child_id, $allowed, true ) ) {
            $ssa_ids = class_exists( 'RK_Coach_Data' ) ? RK_Coach_Data::get_ssa_types_for_coach( $coach_id ) : [];
            if ( empty( $ssa_ids ) || ! in_array( (int) $booking->appointment_type_id, $ssa_ids, true ) ) {
                return new WP_REST_Response( [ 'code' => 'not_authorized' ], 403 );
            }
        }

        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'rk_bookings',
            [ 'meeting_url' => $url ],
            [ 'id'          => $booking_id ],
            [ '%s' ],
            [ '%d' ]
        );

        return new WP_REST_Response( [ 'success' => true, 'meeting_url' => $url ], 200 );
    }

    /* ─── GET /coach/child/evals?child_id=N ─────────────────────── */

    public static function create_booking( WP_REST_Request $request ): WP_REST_Response {
        $coach_id     = get_current_user_id();
        $child_id     = (int) $request->get_param( 'child_id' );
        $appointment  = sanitize_text_field( (string) $request->get_param( 'appointment' ) );
        $session_name = sanitize_text_field( (string) ( $request->get_param( 'session_name' ) ?: 'جلسة تدريبية' ) );

        if ( ! $child_id || ! $appointment ) {
            return new WP_REST_Response( [ 'code' => 'invalid_params' ], 400 );
        }
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}/', $appointment ) ) {
            return new WP_REST_Response( [ 'code' => 'invalid_date_format' ], 400 );
        }
        $students = RK_Coach_Data::get_coach_students( $coach_id );
        $allowed  = array_map( fn( $s ) => (int) $s->child_id, (array) $students );
        if ( ! in_array( $child_id, $allowed, true ) ) {
            return new WP_REST_Response( [ 'code' => 'not_authorized' ], 403 );
        }

        $booking_id = RKP_CoachSessionRepository::create_booking( [
            'child_id'     => $child_id,
            'appointment'  => $appointment,
            'session_name' => $session_name,
            'status'       => 'confirmed',
            'attendance'   => 'none',
        ] );

        if ( ! $booking_id ) {
            return new WP_REST_Response( [ 'code' => 'db_error' ], 500 );
        }
        RK_Coach_Data::bust_for_coach( $coach_id );
        return new WP_REST_Response( [ 'success' => true, 'booking_id' => $booking_id ], 201 );
    }

    /* ─── GET /coach/tasks — missions RK + quiz attempts (page globale) */

}