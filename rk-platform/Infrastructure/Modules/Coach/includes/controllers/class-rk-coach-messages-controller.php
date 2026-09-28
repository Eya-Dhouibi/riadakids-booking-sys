<?php
declare( strict_types=1 );
/**
 * RK_Coach_Messages_Controller  (v2.4.0 — Audit P3-14)
 *
 * Messagerie coach ↔ parents/enfants (via l'adapter Better Messages).
 * Extrait du God Service RK_Coach_API (2 527 lignes) — corps des méthodes
 * strictement inchangé ; seuls les helpers partagés pointent désormais
 * vers RK_Coach_Api_Helpers. Le routing et l'auth restent dans RK_Coach_API.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class RK_Coach_Messages_Controller {

    public static function send_message( WP_REST_Request $request ): WP_REST_Response {
        $coach_id = get_current_user_id();
        $child_id = (int) $request->get_param( 'child_id' );
        $content  = sanitize_textarea_field( (string) $request->get_param( 'content' ) );

        if ( ! $child_id || ! $content ) {
            return new WP_REST_Response( [ 'code' => 'invalid_params' ], 400 );
        }
        $students = RK_Coach_Data::get_coach_students( $coach_id );
        $allowed  = array_map( fn( $s ) => (int) $s->child_id, (array) $students );
        if ( ! in_array( $child_id, $allowed, true ) ) {
            return new WP_REST_Response( [ 'code' => 'not_authorized' ], 403 );
        }

        $parent_id = RK_Coach_Data::get_parent_of_child( $child_id );
        if ( ! $parent_id ) {
            return new WP_REST_Response( [ 'code' => 'no_parent' ], 404 );
        }
        if ( ! class_exists( 'Better_Messages' ) || ! method_exists( Better_Messages()->functions, 'new_message' ) ) {
            return new WP_REST_Response( [ 'code' => 'bp_unavailable' ], 503 );
        }
        $result = Better_Messages()->functions->new_message( [
            'sender_id'  => $coach_id,
            'recipients' => [ $parent_id ],
            'subject'    => __( 'رسالة من المدرب', 'rk-coach-hub' ),
            'content'    => $content,
            'error_type' => 'wp_error',
        ] );

        if ( is_wp_error( $result ) || ! $result ) {
            return new WP_REST_Response( [ 'code' => 'send_failed' ], 500 );
        }
        return new WP_REST_Response( [ 'success' => true ], 200 );
    }

    /* ─── GET /coach/messages ───────────────────────────────────── */

    public static function get_messages(): WP_REST_Response {
        if ( ! function_exists( 'bm_get_table' ) ) {
            return new WP_REST_Response( [], 200 );
        }

        $coach_id = get_current_user_id();
        $students = RK_Coach_Data::get_coach_students( $coach_id );
        if ( empty( $students ) ) {
            return new WP_REST_Response( [], 200 );
        }

        global $wpdb;
        $tbl_r = bm_get_table( 'recipients' );
        $tbl_m = bm_get_table( 'messages' );

        // Build partner list : parent WP user + child WP user per student
        $partners = [];
        foreach ( (array) $students as $st ) {
            $child_id    = (int) $st->child_id;
            $parent_id   = RK_Coach_Data::get_parent_of_child( $child_id );
            $child_wp_id = RK_Coach_Api_Helpers::get_child_wp_user_id( $child_id );
            $full_name   = trim( (string) ( $st->child_name ?? '' ) . ' ' . (string) ( $st->child_family_name ?? '' ) );

            if ( $parent_id && $parent_id !== $coach_id ) {
                $partners[] = [
                    'child_id'     => $child_id,
                    'child_name'   => $full_name,
                    'partner_id'   => $parent_id,
                    'partner_type' => 'parent',
                ];
            }
            if ( $child_wp_id && $child_wp_id !== $coach_id && $child_wp_id !== $parent_id ) {
                $partners[] = [
                    'child_id'     => $child_id,
                    'child_name'   => $full_name,
                    'partner_id'   => $child_wp_id,
                    'partner_type' => 'child',
                ];
            }
        }

        $result = [];
        foreach ( $partners as $p ) {
            $partner_id = $p['partner_id'];

            $thread_id = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT r1.thread_id
                   FROM {$tbl_r} r1
                   JOIN {$tbl_r} r2 ON r1.thread_id = r2.thread_id
                  WHERE r1.user_id = %d AND r2.user_id = %d
                  LIMIT 1",
                $coach_id, $partner_id
            ) );

            $unread    = 0;
            $last_msg  = '';
            $last_date = '';

            if ( $thread_id ) {
                $unread = (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT COALESCE(unread_count,0) FROM {$tbl_r}
                      WHERE thread_id = %d AND user_id = %d",
                    $thread_id, $coach_id
                ) );
                $last = $wpdb->get_row( $wpdb->prepare(
                    "SELECT message, date_sent FROM {$tbl_m}
                      WHERE thread_id = %d ORDER BY date_sent DESC LIMIT 1",
                    $thread_id
                ) );
                if ( $last ) {
                    $last_msg  = mb_substr( wp_strip_all_tags( (string) $last->message ), 0, 80 );
                    $last_date = (string) $last->date_sent;
                }
            }

            $partner_user = get_userdata( $partner_id );
            $result[]     = [
                'thread_id'      => $thread_id,
                'partner_id'     => $partner_id,
                'partner_name'   => $partner_user ? $partner_user->display_name : '',
                'partner_avatar' => get_avatar_url( $partner_id, [ 'size' => 40 ] ),
                'partner_type'   => $p['partner_type'],
                'child_id'       => $p['child_id'],
                'child_name'     => $p['child_name'],
                'unread'         => $unread,
                'last_message'   => $last_msg,
                'last_date'      => $last_date,
            ];
        }

        usort( $result, static function ( $a, $b ) {
            if ( ! $a['last_date'] && ! $b['last_date'] ) return 0;
            if ( ! $a['last_date'] ) return 1;
            if ( ! $b['last_date'] ) return -1;
            return strcmp( $b['last_date'], $a['last_date'] );
        } );

        return new WP_REST_Response( $result, 200 );
    }

    /* ─── GET /coach/messages/thread?partner_id=X&child_id=Y ─────── */

    public static function get_thread( WP_REST_Request $request ): WP_REST_Response {
        if ( ! function_exists( 'bm_get_table' ) ) {
            return new WP_REST_Response( [ 'thread_id' => 0, 'messages' => [] ], 200 );
        }

        $coach_id   = get_current_user_id();
        $partner_id = (int) $request->get_param( 'partner_id' );
        $child_id   = (int) $request->get_param( 'child_id' );

        if ( ! $partner_id ) {
            return new WP_REST_Response( [ 'code' => 'missing_partner_id' ], 400 );
        }
        if ( ! RK_Coach_Api_Helpers::partner_is_allowed( $coach_id, $partner_id, $child_id ) ) {
            return new WP_REST_Response( [ 'code' => 'not_authorized' ], 403 );
        }

        global $wpdb;
        $tbl_r = bm_get_table( 'recipients' );
        $tbl_m = bm_get_table( 'messages' );

        $thread_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT r1.thread_id
               FROM {$tbl_r} r1
               JOIN {$tbl_r} r2 ON r1.thread_id = r2.thread_id
              WHERE r1.user_id = %d AND r2.user_id = %d
              LIMIT 1",
            $coach_id, $partner_id
        ) );

        if ( ! $thread_id ) {
            return new WP_REST_Response( [ 'thread_id' => 0, 'messages' => [] ], 200 );
        }

        // Mark as read for coach
        $wpdb->update( $tbl_r, [ 'unread_count' => 0 ], [ 'thread_id' => $thread_id, 'user_id' => $coach_id ], [ '%d' ], [ '%d', '%d' ] );

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, sender_id, message, date_sent
               FROM {$tbl_m}
              WHERE thread_id = %d
              ORDER BY date_sent ASC
              LIMIT 200",
            $thread_id
        ) ) ?: [];

        $messages = array_map( static function ( $r ) use ( $coach_id ) {
            $sender = get_userdata( (int) $r->sender_id );
            return [
                'id'          => (int) $r->id,
                'sender_id'   => (int) $r->sender_id,
                'sender_name' => $sender ? $sender->display_name : '',
                'content'     => wp_strip_all_tags( (string) $r->message ),
                'date_sent'   => substr( (string) $r->date_sent, 0, 16 ),
                'is_mine'     => (int) $r->sender_id === $coach_id,
            ];
        }, $rows );

        return new WP_REST_Response( [ 'thread_id' => $thread_id, 'messages' => $messages ], 200 );
    }

    /* ─── POST /coach/messages/send ────────────────────────────────── */

    public static function send_bm_message( WP_REST_Request $request ): WP_REST_Response {
        $coach_id   = get_current_user_id();
        $partner_id = (int) $request->get_param( 'partner_id' );
        $child_id   = (int) $request->get_param( 'child_id' );
        $content    = sanitize_textarea_field( (string) $request->get_param( 'content' ) );

        if ( ! $partner_id || ! $content ) {
            return new WP_REST_Response( [ 'code' => 'invalid_params' ], 400 );
        }
        if ( ! RK_Coach_Api_Helpers::partner_is_allowed( $coach_id, $partner_id, $child_id ) ) {
            return new WP_REST_Response( [ 'code' => 'not_authorized' ], 403 );
        }
        if ( ! class_exists( 'Better_Messages' ) || ! method_exists( Better_Messages()->functions, 'new_message' ) ) {
            return new WP_REST_Response( [ 'code' => 'messaging_unavailable' ], 503 );
        }

        // Find existing thread to continue it instead of creating a new one
        $thread_id = false;
        if ( function_exists( 'bm_get_table' ) ) {
            global $wpdb;
            $tbl_r     = bm_get_table( 'recipients' );
            $thread_id = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT r1.thread_id
                   FROM {$tbl_r} r1
                   JOIN {$tbl_r} r2 ON r1.thread_id = r2.thread_id
                  WHERE r1.user_id = %d AND r2.user_id = %d
                  LIMIT 1",
                $coach_id, $partner_id
            ) ) ?: false;
        }

        $args = [
            'sender_id'  => $coach_id,
            'recipients' => [ $partner_id ],
            'subject'    => __( 'رسالة من المدرب', 'rk-coach-hub' ),
            'content'    => $content,
            'error_type' => 'wp_error',
        ];
        if ( $thread_id ) {
            $args['thread_id'] = $thread_id;
        }

        $result = Better_Messages()->functions->new_message( $args );

        if ( is_wp_error( $result ) || ! $result ) {
            return new WP_REST_Response( [ 'code' => 'send_failed' ], 500 );
        }

        $coach = get_userdata( $coach_id );

        // Notify recipient (child or parent) of the new message from coach
     // Notify recipient (child or parent) of the new message from coach
        $coach_name = $coach ? $coach->display_name : __( 'المدرب', 'rk-coach-hub' );
        $notif_body = sprintf( 'رسالة من %s: %s', $coach_name, wp_trim_words( $content, 8 ) );

        if ( function_exists( 'riada_notify_user' ) ) {
            $notif_link = home_url( '/dashboard/' );
            riada_notify_user( $partner_id, '💬 رسالة جديدة', $notif_body, $notif_link, 'new_message' );
        }

        if ( class_exists( 'RK_MC_Notification_Service' ) ) {
            RK_MC_Notification_Service::push(
                $partner_id,
                'new_message',
                $notif_body,
                [ 'child_id' => $child_id ]
            );
        }
        return new WP_REST_Response( [
            'success'     => true,
            'thread_id'   => is_int( $result ) ? $result : (int) ( $thread_id ?: 0 ),
            'date_sent'   => substr( current_time( 'mysql' ), 0, 16 ),
            'sender_id'   => $coach_id,
            'sender_name' => $coach ? $coach->display_name : '',
            'content'     => $content,
        ], 200 );
    }

}
