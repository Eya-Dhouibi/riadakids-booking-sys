<?php
declare( strict_types=1 );
/**
 * RK_Coach_Api_Helpers — helpers partagés de l'API coach.  (v2.4.0 — Audit P3-14)
 *
 * Extraits de RK_Coach_API lors du découpage en contrôleurs :
 * résolution enfant ↔ wp_user, périmètre du coach, contexte parent, bookings.
 * Code inchangé — seule la visibilité passe de private à public.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class RK_Coach_Api_Helpers {

    public static function get_next_child_booking( int $child_id ): ?array {
        return RKP_CoachSessionRepository::find_next_booking_for_child( $child_id );
    }

    /* ─── GET /coach/sessions ────────────────────────────────────── */

    public static function get_child_sessions_detail( int $child_id ): array {
        return RKP_CoachSessionRepository::find_sessions_detail_for_child( $child_id, get_current_user_id() );
    }

    /* ─── POST /coach/attendance ─────────────────────────────────── */

    public static function partner_is_allowed( int $coach_id, int $partner_id, int $child_id = 0 ): bool {
        $students = RK_Coach_Data::get_coach_students( $coach_id );
        foreach ( (array) $students as $st ) {
            $cid = (int) $st->child_id;
            if ( $child_id && $cid !== $child_id ) continue;
            $pid = RK_Coach_Data::get_parent_of_child( $cid );
            $wid = RK_Coach_Api_Helpers::get_child_wp_user_id( $cid );
            if ( $partner_id === $pid || ( $wid && $partner_id === $wid ) ) return true;
        }
        return false;
    }

    /* ─── GET /coach/notifications ──────────────────────────────── */

    public static function get_child_tutor_assignments( int $child_wp_uid ): array {
        global $wpdb;

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT
                 p.ID              AS assignment_id,
                 p.post_title      AS title,
                 co.ID             AS course_id,
                 co.post_title     AS course_title,
                 cm.comment_ID     AS submission_id,
                 cm.comment_approved AS sub_status,
                 cm.comment_date   AS submitted_at,
                 cmt.meta_value    AS mark,
                 aom.meta_value    AS assignment_options_raw
             FROM {$wpdb->posts} e
             JOIN {$wpdb->posts} co
               ON co.ID = e.post_parent AND co.post_type = 'courses'
             JOIN {$wpdb->posts} p
               ON p.post_parent = co.ID
              AND p.post_type = 'tutor_assignments'
              AND p.post_status = 'publish'
             LEFT JOIN {$wpdb->comments} cm
               ON cm.comment_post_ID = p.ID
              AND cm.comment_type = 'tutor_assignment'
              AND cm.user_id = %d
             LEFT JOIN {$wpdb->commentmeta} cmt
               ON cmt.comment_id = cm.comment_ID
              AND cmt.meta_key = 'assignment_mark'
             LEFT JOIN {$wpdb->postmeta} aom
               ON aom.post_id = p.ID
              AND aom.meta_key = 'assignment_option'
             WHERE e.post_type = 'tutor_enrolled'
               AND e.post_status = 'completed'
               AND e.post_author = %d
             GROUP BY p.ID
             ORDER BY p.post_date DESC
             LIMIT 20",
            $child_wp_uid, $child_wp_uid
        ) ) ?: [];

        return array_map( static function ( $row ) {
            $opts       = maybe_unserialize( $row->assignment_options_raw );
            $total_mark = isset( $opts['total_mark'] ) ? (int) $opts['total_mark'] : 100;
            $pass_mark  = isset( $opts['pass_mark'] )  ? (int) $opts['pass_mark']  : 0;

            if ( $row->submission_id === null ) {
                $status = 'pending';
            } elseif ( $row->mark !== null ) {
                $status = 'graded';
            } elseif ( $row->sub_status === 'submitted' ) {
                $status = 'submitted';
            } else {
                $status = 'submitting';
            }

            return [
                'id'            => (int) $row->assignment_id,
                'title'         => (string) $row->title,
                'course_id'     => (int) $row->course_id,
                'course_title'  => (string) $row->course_title,
                'submission_id' => $row->submission_id ? (int) $row->submission_id : null,
                'status'        => $status,
                'submitted_at'  => $row->submitted_at ? substr( $row->submitted_at, 0, 10 ) : null,
                'mark'          => $row->mark !== null ? (int) $row->mark : null,
                'total_mark'    => $total_mark,
                'pass_mark'     => $pass_mark,
            ];
        }, $rows );
    }

    /* ─── POST /coach/mission ────────────────────────────────────── */

    public static function get_coach_children_wp_user_ids( int $coach_id ): array {
        static $cache = [];
        if ( isset( $cache[ $coach_id ] ) ) return $cache[ $coach_id ];
        return $cache[ $coach_id ] = class_exists( 'RKP_CoachStudentRepository' )
            ? RKP_CoachStudentRepository::get_children_wp_user_ids_for_coach( $coach_id )
            : [];
    }

    /**
     * Retourne le wp_user_id d'un enfant depuis son child_id (wp_rk_children.id).
     */
    public static function get_child_wp_user_id( int $child_id ): int {
        $row = RKP_CoachStudentRepository::find_child_by_id( $child_id );
        return $row ? (int) $row->wp_user_id : 0;
    }

    /**
     * Retourne les cours WC liés aux bookings d'un enfant (child_id RK).
     * Source : wp_rk_bookings.course_id → wp_posts (product).
     *
     * @return array[]  [ { course_id, course_title, progress } ]
     */
    public static function get_child_enrolled_courses( int $child_id ): array {
        return RKP_CoachSessionRepository::find_enrolled_courses_for_child( $child_id );
    }

    public static function get_parent_info_for_child( int $child_id ): array {
        $child_row = RKP_CoachStudentRepository::find_child_by_id( $child_id );
        $parent_id = $child_row ? (int) $child_row->user_id : 0;
        if ( ! $parent_id ) return [];
        $user = get_userdata( $parent_id );
        if ( ! $user ) return [];
        $phone = (string) ( get_user_meta( $parent_id, 'billing_phone', true )
                         ?: get_user_meta( $parent_id, 'phone_number',  true )
                         ?: '' );
        return [
            'id'     => $parent_id,
            'name'   => $user->display_name,
            'email'  => $user->user_email,
            'avatar' => get_avatar_url( $parent_id, [ 'size' => 48 ] ),
            'phone'  => $phone,
        ];
    }

    /* ─── POST /coach/child/badge ───────────────────────────────── */

}
