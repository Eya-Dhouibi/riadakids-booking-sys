<?php
declare( strict_types=1 );
/**
 * RK_Coach_Missions_Controller  (v2.4.0 — Audit P3-14)
 *
 * Missions & tâches assignées, progression, certificats.
 * Extrait du God Service RK_Coach_API (2 527 lignes) — corps des méthodes
 * strictement inchangé ; seuls les helpers partagés pointent désormais
 * vers RK_Coach_Api_Helpers. Le routing et l'auth restent dans RK_Coach_API.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class RK_Coach_Missions_Controller {

    public static function get_tasks( WP_REST_Request $request ): WP_REST_Response {
        $coach_id = get_current_user_id();

        // ── Missions RK ──────────────────────────────────────────────
        $missions_raw = RKP_CoachMissionQueryService::get_all_missions( $coach_id );
        $missions = array_map( static function ( $m ) {
            $target   = max( 1, (int) $m->target );
            $progress = min( $target, (int) $m->progress );
            return [
                'type'        => 'rk_mission',
                'id'          => (int) $m->id,
                'child_id'    => (int) $m->rk_child_id,
                'child_name'  => (string) $m->child_name,
                'title'       => (string) $m->title,
                'description' => (string) $m->description,
                'target'      => $target,
                'progress'    => $progress,
                'completed'   => (bool) $m->completed,
                'points'      => (int) $m->points,
                'due_date'    => (string) ( $m->due_date ?? '' ),
                'assigned_at' => (string) $m->assigned_at,
            ];
        }, $missions_raw );

        // ── Quiz attempts Tutor LMS ──────────────────────────────────
        $child_uids = RK_Coach_Api_Helpers::get_coach_children_wp_user_ids( $coach_id );
        $quizzes    = [];
        if ( ! empty( $child_uids ) ) {
            // Build wp_user_id → {child_id, child_name} lookup from students
            $students   = RK_Coach_Data::get_coach_students( $coach_id );
            $uid_lookup = [];
            foreach ( (array) $students as $st ) {
                if ( (int) $st->wp_user_id > 0 ) {
                    $uid_lookup[ (int) $st->wp_user_id ] = [ 'child_id' => (int) $st->child_id, 'child_name' => (string) $st->child_name ];
                }
            }
            $rows = RKP_QuizAttemptRepository::find_for_coach_children( $child_uids, 60 );
            $quizzes = array_map( static function ( $row ) use ( $uid_lookup ) {
                $info  = $uid_lookup[ (int) $row['user_id'] ] ?? [ 'child_id' => 0, 'child_name' => '' ];
                $total = (float) $row['total_marks'];
                return [
                    'type'       => 'tutor_quiz',
                    'id'         => (int) $row['attempt_id'],
                    'child_id'   => $info['child_id'],
                    'child_name' => $info['child_name'],
                    'title'      => (string) $row['quiz_title'],
                    'score'      => $total > 0 ? round( (float) $row['earned_marks'] / $total * 100 ) . '%' : null,
                    'completed'  => true,
                    'created_at' => (string) $row['attempt_ended_at'],
                ];
            }, $rows );
        }

        // ── Quiz Maker (coach-assigned) ──────────────────────────────
        $qz_table  = $wpdb->prefix . 'aysquiz_quizes';
        $rp_table  = $wpdb->prefix . 'aysquiz_reports';
        $qz_maker  = [];

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $qz_table ) ) === $qz_table ) {
            $qz_rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT q.id, q.title, q.quiz_url, q.create_date, q.custom_post_id,
                        c.child_name, c.wp_user_id, c.id AS child_rk_id
                   FROM {$qz_table} q
                   JOIN {$ct} c ON CONCAT('rk:child:', c.id) = q.quiz_url
                  WHERE q.author_id = %d AND q.published = 1
                  ORDER BY q.create_date DESC LIMIT 50",
                $coach_id
            ) ) ?: [];

            $rp_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $rp_table ) ) === $rp_table;

            foreach ( $qz_rows as $row ) {
                $best_score = null;
                $attempted  = false;
                if ( $rp_exists && (int) $row->wp_user_id ) {
                    $rep = $wpdb->get_row( $wpdb->prepare(
                        "SELECT MAX(score) AS best FROM {$rp_table}
                          WHERE quiz_id = %d AND user_id = %d",
                        (int) $row->id, (int) $row->wp_user_id
                    ) );
                    if ( $rep && $rep->best !== null ) {
                        $best_score = (int) $rep->best;
                        $attempted  = true;
                    }
                }
                $quiz_url = $row->custom_post_id
                    ? ( (string) ( get_permalink( (int) $row->custom_post_id ) ?: '' ) )
                    : '';
                $qz_maker[] = [
                    'type'        => 'quiz_maker',
                    'id'          => (int) $row->id,
                    'child_id'    => (int) $row->child_rk_id,
                    'child_name'  => (string) $row->child_name,
                    'title'       => (string) $row->title,
                    'quiz_url'    => $quiz_url,
                    'created_at'  => (string) $row->create_date,
                    'attempted'   => $attempted,
                    'best_score'  => $best_score,
                ];
            }
        }

        $active_m  = count( array_filter( $missions, static fn( $m ) => ! $m['completed'] ) );
        $done_m    = count( $missions ) - $active_m;

        return new WP_REST_Response( [
            'stats'      => [
                'missions_active'    => $active_m,
                'missions_completed' => $done_m,
                'quiz_attempts'      => count( $quizzes ),
                'quiz_maker_total'   => count( $qz_maker ),
            ],
            'missions'   => array_values( $missions ),
            'quizzes'    => array_values( $quizzes ),
            'quiz_maker' => array_values( $qz_maker ),
        ], 200 );
    }

    /* ─── GET /coach/child/tasks?child_id=N ─────────────────────── */

    public static function get_child_tasks( WP_REST_Request $request ): WP_REST_Response {
        $coach_id = get_current_user_id();
        $child_id = (int) $request->get_param( 'child_id' );
        if ( $child_id <= 0 ) {
            return new WP_REST_Response( [ 'code' => 'missing_child_id' ], 400 );
        }

        $child_uids   = RK_Coach_Api_Helpers::get_coach_children_wp_user_ids( $coach_id );
        $child_wp_uid = RK_Coach_Api_Helpers::get_child_wp_user_id( $child_id );
        if ( ! $child_wp_uid || ! in_array( $child_wp_uid, $child_uids, true ) ) {
            return new WP_REST_Response( [ 'code' => 'forbidden' ], 403 );
        }

        $assignments  = RK_Coach_Api_Helpers::get_child_tutor_assignments( $child_wp_uid );

        $missions_raw = class_exists( 'RK_Coach_Missions' )
            ? RK_Coach_Missions::get_missions_for_child( $child_id )
            : [];

        $missions = array_map( static function ( $m ) {
            $target   = max( 1, (int) ( $m['target'] ?? 1 ) );
            $progress = min( $target, (int) ( $m['progress'] ?? 0 ) );
            return [
                'id'          => (int) $m['id'],
                'title'       => (string) $m['title'],
                'description' => (string) ( $m['description'] ?? '' ),
                'target'      => $target,
                'progress'    => $progress,
                'completed'   => (bool) $m['completed'],
                'points'      => (int) $m['points'],
                'due_date'    => (string) ( $m['due_date'] ?? '' ),
                'file_url'    => (string) ( $m['file_url'] ?? '' ),
                'file_name'   => (string) ( $m['file_name'] ?? '' ),
                'assigned_at' => (string) $m['assigned_at'],
            ];
        }, $missions_raw );

        return new WP_REST_Response( [
            'tutor_assignments' => $assignments,
            'missions'          => $missions,
        ], 200 );
    }

    public static function create_mission( WP_REST_Request $request ): WP_REST_Response {
        $coach_id = get_current_user_id();
        $child_id = (int) $request->get_param( 'child_id' );
        $title    = sanitize_text_field( (string) $request->get_param( 'title' ) );
        $desc     = sanitize_textarea_field( (string) $request->get_param( 'description' ) );
        $due_date = sanitize_text_field( (string) $request->get_param( 'due_date' ) );
        $points   = max( 5, min( 200, (int) ( $request->get_param( 'points' ) ?: 10 ) ) );
        $target   = max( 1, min( 30,  (int) ( $request->get_param( 'target' ) ?: 1 ) ) );

        if ( ! $child_id || ! $title ) {
            return new WP_REST_Response( [ 'code' => 'missing_params' ], 400 );
        }

        $child_uids   = RK_Coach_Api_Helpers::get_coach_children_wp_user_ids( $coach_id );
        $child_wp_uid = RK_Coach_Api_Helpers::get_child_wp_user_id( $child_id );
        if ( ! $child_wp_uid || ! in_array( $child_wp_uid, $child_uids, true ) ) {
            return new WP_REST_Response( [ 'code' => 'forbidden' ], 403 );
        }

        $inserted_id = RKP_CoachMissionCommandService::assign( [
            'child_id'    => $child_id,
            'coach_id'    => $coach_id,
            'title'       => $title,
            'description' => $desc,
            'target'      => $target,
            'points'      => $points,
            'due_date'    => $due_date ?: null,
        ] );

        if ( ! $inserted_id ) {
            return new WP_REST_Response( [ 'code' => 'db_error' ], 500 );
        }

        if ( class_exists( 'RK_MC_Child_Dashboard_Data' ) ) {
            RK_MC_Child_Dashboard_Data::clear_cache( $child_id );
        }

        return new WP_REST_Response( [ 'id' => $inserted_id, 'success' => true ], 201 );
    }

    /* ─── POST /coach/mission/{id}/progress ─────────────────────── */

    public static function progress_mission( WP_REST_Request $request ): WP_REST_Response {
        $coach_id   = get_current_user_id();
        $mission_id = (int) $request->get_param( 'id' );

        $mission = RKP_CoachMissionRepository::find_by_id( $mission_id );
        if ( ! $mission || (int) $mission->coach_id !== $coach_id ) {
            return new WP_REST_Response( [ 'code' => 'not_found' ], 404 );
        }
        if ( $mission->completed ) {
            return new WP_REST_Response( [ 'code' => 'already_completed' ], 400 );
        }

        $new_progress  = min( (int) $mission->target, (int) $mission->progress + 1 );
        $now_completed = $new_progress >= (int) $mission->target;

        RKP_CoachMissionCommandService::record_progress( $mission_id, $coach_id );

        if ( class_exists( 'RK_MC_Child_Dashboard_Data' ) ) {
            RK_MC_Child_Dashboard_Data::clear_cache( (int) $mission->child_id );
        }

        return new WP_REST_Response( [
            'success'   => true,
            'progress'  => $new_progress,
            'target'    => (int) $mission->target,
            'completed' => $now_completed,
        ], 200 );
    }

    /* ─── DELETE /coach/mission/{id} ────────────────────────────── */

    public static function delete_mission( WP_REST_Request $request ): WP_REST_Response {
        $coach_id   = get_current_user_id();
        $mission_id = (int) $request->get_param( 'id' );

        $mission = RKP_CoachMissionRepository::find_by_id( $mission_id );
        if ( ! $mission || (int) $mission->coach_id !== $coach_id ) {
            return new WP_REST_Response( [ 'code' => 'not_found' ], 404 );
        }

        RKP_CoachMissionRepository::delete( $mission_id, $coach_id );

        if ( class_exists( 'RK_MC_Child_Dashboard_Data' ) ) {
            RK_MC_Child_Dashboard_Data::clear_cache( (int) $mission->child_id );
        }

        return new WP_REST_Response( [ 'success' => true ], 200 );
    }

    /* ─── GET /coach/certificates(?child_id=N) ── Tutor LMS ──────── */

    public static function get_certificates( WP_REST_Request $request ): WP_REST_Response {
        global $wpdb;
        $coach_id   = get_current_user_id();
        $child_id   = (int) $request->get_param( 'child_id' );

        $child_uids = RK_Coach_Api_Helpers::get_coach_children_wp_user_ids( $coach_id );
        if ( empty( $child_uids ) ) {
            return new WP_REST_Response( [], 200 );
        }

        if ( $child_id ) {
            $child_wp_uid = RK_Coach_Api_Helpers::get_child_wp_user_id( $child_id );
            if ( ! $child_wp_uid || ! in_array( $child_wp_uid, $child_uids, true ) ) {
                return new WP_REST_Response( [], 200 );
            }
            $child_uids = [ $child_wp_uid ];
        }

        // Tutor LMS stocke les complétions de cours dans wp_usermeta
        // clé : _tutor_completed_courses   valeur : JSON array de course IDs
        $uid_ph = implode( ',', array_fill( 0, count( $child_uids ), '%d' ) );
        $meta_rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT user_id, meta_value
               FROM {$wpdb->usermeta}
              WHERE meta_key = '_tutor_completed_courses'
                AND user_id IN ($uid_ph)",
            ...$child_uids
        ) ) ?: [];

        $result = [];
        foreach ( $meta_rows as $meta ) {
            $child = RKP_CoachStudentRepository::find_by_wp_user_id( (int) $meta->user_id );
            if ( ! $child ) continue;

            $course_ids = json_decode( $meta->meta_value, true );
            if ( ! is_array( $course_ids ) ) continue;

            foreach ( $course_ids as $cid ) {
                $course = get_post( (int) $cid );
                if ( ! $course || $course->post_type !== 'courses' ) continue;
                $result[] = [
                    'child_id'     => (int) $child->id,
                    'child_name'   => (string) $child->child_name,
                    'course_id'    => (int) $cid,
                    'course_title' => $course->post_title,
                    'cert_url'     => tutor_utils()->get_course_completed_percent( $cid, (int) $meta->user_id ) >= 100
                        ? add_query_arg( [ 'course_id' => $cid, 'user_id' => $meta->user_id ], home_url( '/tutor-certificate/' ) )
                        : null,
                ];
            }
        }

        return new WP_REST_Response( array_values( $result ), 200 );
    }

    /* ─── GET /coach/evals — toutes les évaluations du coach ───── */

}
