<?php
declare( strict_types=1 );
/**
 * RK_MC_Message_Service (v7.0.0 — Sprint 4)
 * Parent ↔ Coach / Admin per-child messaging.
 *
 * Channels:
 *   'coach' — private thread between parent and the child's coach
 *   'admin' — support thread between parent and site admin
 *
 * @package RK_My_Children
 * @since   7.0.0
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class RK_MC_Message_Service {

    const CHANNEL_COACH = 'coach';
    const CHANNEL_ADMIN = 'admin';

    private static function t(): string {
        global $wpdb;
        return $wpdb->prefix . 'rk_child_messages';
    }

    /* ─── Hooks ─────────────────────────────────────────────────── */

    public static function init(): void {
        add_action( 'wp_ajax_rk_mc_send_message', [ __CLASS__, 'ajax_send' ] );
        add_action( 'wp_ajax_rk_mc_chat_send',    [ __CLASS__, 'ajax_chat_send' ] );
        add_action( 'wp_ajax_rk_mc_chat_load',    [ __CLASS__, 'ajax_chat_load' ] );
    }

    /* ─── Auth helper ───────────────────────────────────────────────────── */

    /**
     * Validates that the current user can send/read messages for this child.
     * Returns the child's WP user ID on success, 0 on failure.
     * Works for: direct child login, parent via rk_tab, parent via child_id.
     */
    private static function auth_child_chat( int $child_id ): int {
        $current_uid = get_current_user_id();
        if ( ! $current_uid || ! $child_id ) return 0;

        global $wpdb;
        $child_row = $wpdb->get_row( $wpdb->prepare(
            "SELECT wp_user_id, user_id FROM " . rk_mc_children_table() . " WHERE id = %d LIMIT 1",
            $child_id
        ) );
        if ( ! $child_row ) return 0;

        $child_wp_uid  = (int) $child_row->wp_user_id;
        $child_owner   = (int) $child_row->user_id;

        // Case 1: current user IS the child (direct login or via rk_tab substitution)
        if ( $child_wp_uid > 0 && $current_uid === $child_wp_uid ) {
            return $child_wp_uid;
        }

        // Case 2: current user is the parent who owns this child record
        if ( $current_uid === $child_owner ) {
            return $child_wp_uid > 0 ? $child_wp_uid : 0;
        }

        // Case 3: generic ownership check (handles edge cases)
        if ( function_exists( 'rk_mc_get_child' ) && rk_mc_get_child( $child_id, $current_uid ) ) {
            return $child_wp_uid > 0 ? $child_wp_uid : 0;
        }

        // Case 4: rk_tab POST/GET — fallback si determine_current_user n'a pas tourné
        // phpcs:disable WordPress.Security.NonceVerification
        $raw_tab = sanitize_text_field( wp_unslash( $_POST['rk_tab'] ?? $_GET['rk_tab'] ?? '' ) );
        // phpcs:enable
        if ( preg_match( '/^[a-f0-9]{40}$/', $raw_tab ) && class_exists( 'RK_Session_Manager' ) ) {
            $session = RK_Session_Manager::validate_tab_session( $raw_tab );
            if ( $session && (int) ( $session['child_wp_uid'] ?? 0 ) === $child_wp_uid ) {
                return $child_wp_uid;
            }
        }

        return 0;
    }

    /* ─── Core send ─────────────────────────────────────────────── */

    public static function send( int $child_id, int $from_id, int $to_id, string $body, string $channel = 'coach' ): int {
        global $wpdb;

        $wpdb->insert( self::t(), [
            'child_id'   => $child_id,
            'channel'    => sanitize_key( $channel ),
            'from_id'    => $from_id,
            'to_id'      => $to_id,
            'body'       => sanitize_textarea_field( $body ),
            'is_read'    => 0,
            'created_at' => current_time( 'mysql' ),
        ] );

      $id = (int) $wpdb->insert_id;
        if ( $id ) {
            do_action( 'rk_mc_message_sent', $id, $child_id, $from_id, $to_id );
            self::maybe_create_notification( $to_id, $child_id, $body );
            self::push_coach_rk_notification( $to_id, $child_id, $body );
        }
        return $id;
    }

    /* ─── Read conversation ─────────────────────────────────────── */

    /**
     * Fetches up to $limit messages for a child/channel where $user_id is sender or recipient.
     * Marks received messages as read.
     *
     * @return object[]
     */
    public static function get_conversation( int $child_id, int $user_id, string $channel = 'coach', int $limit = 60 ): array {
        global $wpdb;

        $rows = $wpdb->get_results( $wpdb->prepare(
            'SELECT * FROM ' . self::t() . '
             WHERE child_id = %d AND channel = %s
               AND (from_id = %d OR to_id = %d)
             ORDER BY created_at ASC LIMIT %d',
            $child_id, $channel, $user_id, $user_id, $limit
        ) ) ?: [];

        /* Mark incoming messages as read */
        $wpdb->query( $wpdb->prepare(
            'UPDATE ' . self::t() . ' SET is_read = 1
             WHERE child_id = %d AND channel = %s AND to_id = %d AND is_read = 0',
            $child_id, $channel, $user_id
        ) );

        return $rows;
    }

    public static function get_unread_count( int $to_id, int $child_id, string $channel ): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            'SELECT COUNT(*) FROM ' . self::t() . '
             WHERE to_id = %d AND child_id = %d AND channel = %s AND is_read = 0',
            $to_id, $child_id, $channel
        ) );
    }

    /** All conversations for $child_id (admin view). */
    public static function get_all_for_child( int $child_id, int $limit = 80 ): array {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            'SELECT * FROM ' . self::t() . ' WHERE child_id = %d ORDER BY created_at ASC LIMIT %d',
            $child_id, $limit
        ) ) ?: [];
    }

    /* ─── Coach / Admin user resolution ─────────────────────────── */

    /**
     * Returns the WP_User object of the coach for this child.
     * Resolves via the SSA appointment_type_id → coach WP user ID map
     * (integer lookup — no display_name collision risk).
     * Falls back to the site admin.
     */
    public static function get_coach( int $child_id ): object {
        // Source principale : table wp_rk_child_coaches (coach assigné à l'enfant)
        if ( class_exists( 'RKP_CoachStudentRepository' ) ) {
            $coach_wp_id = RKP_CoachStudentRepository::find_primary_coach_for_child( $child_id );
            if ( $coach_wp_id > 0 ) {
                $user = get_userdata( $coach_wp_id );
                if ( $user ) return $user;
            }
        }

        // Fallback : booking-type → coach via COACH_MAP_OPTION
        if ( class_exists( 'RKP_BookingRepository' ) ) {
            $ssa_type_id = RKP_BookingRepository::get_last_appointment_type_id( $child_id );
            if ( $ssa_type_id ) {
                $map      = get_option( RK_MC_Booking_Bridge::COACH_MAP_OPTION, [] );
                $coach_id = isset( $map[ $ssa_type_id ] ) ? (int) $map[ $ssa_type_id ] : 0;
                if ( $coach_id ) {
                    $user = get_userdata( $coach_id );
                    if ( $user ) return $user;
                }
            }
        }

        return self::get_admin_user();
    }

    public static function get_admin_user(): object {
        $admins = get_users( [
            'role'   => 'administrator',
            'number' => 1,
            'fields' => 'all',
        ] );
        if ( ! empty( $admins ) ) {
            return $admins[0];
        }
        return (object) [ 'ID' => 1, 'display_name' => 'Admin', 'user_email' => '' ];
    }

    /* ─── Rate limiting ─────────────────────────────────────────── */

    private const RL_MAX    = 10; // max messages per window
    private const RL_WINDOW = 60; // seconds

    private static function check_rate_limit( int $user_id ): bool {
        $key   = 'rk_msg_rl_' . $user_id;
        $count = (int) get_transient( $key );
        if ( $count >= self::RL_MAX ) {
            return false;
        }
        set_transient( $key, $count + 1, self::RL_WINDOW );
        return true;
    }

    /**
     * Wrapper public de check_rate_limit() — nécessaire depuis
     * RK_Messages_REST (Modules/Mobile), qui n'a pas accès aux méthodes
     * private de cette classe. Même fenêtre/limite, rien de dupliqué.
     *
     * @since 4.22.0
     */
    public static function check_rate_limit_public( int $user_id ): bool {
        return self::check_rate_limit( $user_id );
    }

    /**
     * Wrapper public regroupant maybe_create_notification() et
     * push_coach_rk_notification() — le même couple d'appels que
     * ajax_chat_send() effectue après un envoi réussi via Better
     * Messages. Nécessaire depuis RK_Messages_REST (Modules/Mobile).
     *
     * @since 4.22.0
     */
    public static function notify_recipient_public( int $to_id, int $child_id, string $body ): void {
        self::maybe_create_notification( $to_id, $child_id, $body );
        self::push_coach_rk_notification( $to_id, $child_id, $body );
    }

    /* ─── AJAX ──────────────────────────────────────────────────── */

    public static function ajax_send(): void {
        check_ajax_referer( 'rk_mc_messages', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'msg' => 'غير مصرح' ], 401 );
        }

        $from_id = get_current_user_id();

        if ( ! self::check_rate_limit( $from_id ) ) {
            wp_send_json_error( [ 'msg' => 'يرجى الانتظار قبل إرسال رسائل إضافية' ], 429 );
        }

        $child_id = absint( $_POST['child_id'] ?? 0 );
        $to_id    = absint( $_POST['to_id']    ?? 0 );
        $channel  = sanitize_key( $_POST['channel'] ?? 'coach' );
        $body     = sanitize_textarea_field( wp_unslash( $_POST['body'] ?? '' ) );

        if ( ! $child_id || ! $to_id || ! trim( $body ) ) {
            wp_send_json_error( [ 'msg' => 'بيانات ناقصة' ], 400 );
        }

        /* Ownership: parent must own child */
        $child = function_exists( 'rk_mc_get_child' ) ? rk_mc_get_child( $child_id, $from_id ) : null;
        if ( ! $child ) {
            wp_send_json_error( [ 'msg' => 'غير مصرح' ], 403 );
        }

        $id = self::send( $child_id, $from_id, $to_id, $body, $channel );
        if ( ! $id ) {
            wp_send_json_error( [ 'msg' => 'خطأ في الحفظ' ], 500 );
        }

        wp_send_json_success( [
            'id'   => $id,
            'body' => esc_html( $body ),
            'time' => current_time( 'H:i' ),
        ] );
    }

    /* ─── Chat AJAX v8 — enfant peut envoyer ───────────────────── */

    /**
     * Send via BM or custom table. Works for child login, parent via rk_tab, parent via child_id.
     * Always sends FROM child's WP user ID.
     */
    public static function ajax_chat_send(): void {
        check_ajax_referer( 'rk_mc_chat', 'nonce' );

        $child_id = absint( $_POST['child_id'] ?? 0 );
        $to_id    = absint( $_POST['to_id']    ?? 0 );
        $body     = sanitize_textarea_field( wp_unslash( $_POST['body'] ?? '' ) );

        if ( ! $child_id || ! $to_id || '' === trim( $body ) ) {
            wp_send_json_error( [ 'msg' => 'بيانات ناقصة' ], 400 );
        }
        if ( mb_strlen( $body ) > 2000 ) {
            wp_send_json_error( [ 'msg' => 'الرسالة طويلة جداً' ], 400 );
        }

        $sender_uid = self::auth_child_chat( $child_id );
        if ( ! $sender_uid ) {
            wp_send_json_error( [ 'msg' => 'غير مصرح' ], 403 );
        }
        if ( ! self::check_rate_limit( $sender_uid ) ) {
            wp_send_json_error( [ 'msg' => 'يرجى الانتظار قليلاً' ], 429 );
        }

        if ( class_exists( 'Better_Messages' ) && method_exists( Better_Messages()->functions, 'new_message' ) ) {
            $result = Better_Messages()->functions->new_message( [
                'sender_id'  => $sender_uid,
                'recipients' => [ $to_id ],
                'content'    => wp_kses_post( $body ),
            ] );
            if ( is_wp_error( $result ) ) {
                wp_send_json_error( [ 'msg' => $result->get_error_message() ], 500 );
            }
            // Notify coach via both tables (BM path skips maybe_create_notification)
            self::maybe_create_notification( $to_id, $child_id, $body );
            self::push_coach_rk_notification( $to_id, $child_id, $body );
            $msg_id = is_array( $result ) ? (int) ( $result['message_id'] ?? 0 ) : (int) $result;
            wp_send_json_success( [
                'id'        => $msg_id,
                'sender_id' => $sender_uid,
                'body'      => esc_html( $body ),
                'time'      => date_i18n( 'H:i' ),
                'engine'    => 'bm',
            ] );
        }

        $channel = sanitize_key( $_POST['channel'] ?? 'coach' );
        $id      = self::send( $child_id, $sender_uid, $to_id, $body, $channel );
        if ( ! $id ) {
            wp_send_json_error( [ 'msg' => 'خطأ في الحفظ' ], 500 );
        }
        self::push_coach_rk_notification( $to_id, $child_id, $body );
        wp_send_json_success( [
            'id'        => $id,
            'sender_id' => $sender_uid,
            'body'      => esc_html( $body ),
            'time'      => date_i18n( 'H:i' ),
            'engine'    => 'custom',
        ] );
    }

    /** Poll: returns new BM messages after last_id for a 1:1 thread. */
    public static function ajax_chat_load(): void {
        check_ajax_referer( 'rk_mc_chat', 'nonce' );

        $child_id   = absint( $_POST['child_id']   ?? 0 );
        $partner_id = absint( $_POST['partner_id'] ?? 0 );
        $last_id    = absint( $_POST['last_id']    ?? 0 );

        if ( ! $child_id || ! $partner_id ) {
            wp_send_json_error( [ 'msg' => 'بيانات ناقصة' ], 400 );
        }

        $viewer_uid = self::auth_child_chat( $child_id );
        if ( ! $viewer_uid ) {
            wp_send_json_error( [ 'msg' => 'غير مصرح' ], 403 );
        }

        $messages = self::load_bm_messages( $viewer_uid, $partner_id, $last_id );

        if ( function_exists( 'bm_get_table' ) && $messages ) {
            global $wpdb;
            $tbl       = bm_get_table( 'recipients' );
            $thread_id = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT r1.thread_id FROM {$tbl} r1
                 INNER JOIN {$tbl} r2 ON r1.thread_id = r2.thread_id
                 WHERE r1.user_id = %d AND r2.user_id = %d LIMIT 1",
                $viewer_uid, $partner_id
            ) );
            if ( $thread_id ) {
                $wpdb->update( $tbl, [ 'unread_count' => 0 ], [
                    'thread_id' => $thread_id,
                    'user_id'   => $viewer_uid,
                ] );
            }
        }

        wp_send_json_success( [ 'messages' => $messages, 'viewer_id' => $viewer_uid ] );
    }

    /**
     * Load messages from BM's messages table for a 1:1 thread.
     *
     * @return array[] Each item: id, sender_id, body, time, date_full.
     */
    public static function load_bm_messages( int $user_a, int $user_b, int $after_id = 0, int $limit = 60 ): array {
        if ( ! function_exists( 'bm_get_table' ) || ! $user_a || ! $user_b || $user_a === $user_b ) {
            return [];
        }

        global $wpdb;
        $tbl_r = bm_get_table( 'recipients' );
        $tbl_m = bm_get_table( 'messages' );

        $thread_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT r1.thread_id FROM {$tbl_r} r1
             INNER JOIN {$tbl_r} r2 ON r1.thread_id = r2.thread_id
             WHERE r1.user_id = %d AND r2.user_id = %d LIMIT 1",
            $user_a, $user_b
        ) );
        if ( ! $thread_id ) return [];

        // Check once whether the table has an is_deleted column (varies by BM version).
        static $has_deleted_col = null;
        if ( $has_deleted_col === null ) {
            $cols = $wpdb->get_col( "SHOW COLUMNS FROM {$tbl_m} LIKE 'is_deleted'" );
            $has_deleted_col = ! empty( $cols );
        }
        $del_clause = $has_deleted_col ? 'AND is_deleted = 0' : '';

        if ( $after_id > 0 ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT id, sender_id, `message` AS body, date_sent
                   FROM {$tbl_m}
                  WHERE thread_id = %d {$del_clause} AND id > %d
                  ORDER BY date_sent ASC LIMIT %d",
                $thread_id, $after_id, $limit
            ) ) ?: [];
        } else {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT id, sender_id, `message` AS body, date_sent
                   FROM {$tbl_m}
                  WHERE thread_id = %d {$del_clause}
                  ORDER BY date_sent ASC LIMIT %d",
                $thread_id, $limit
            ) ) ?: [];
        }

        $out = [];
        foreach ( $rows as $r ) {
            $out[] = [
                'id'        => (int) $r->id,
                'sender_id' => (int) $r->sender_id,
                'body'      => wp_strip_all_tags( $r->body ?? '' ),
                'time'      => date_i18n( 'H:i', strtotime( $r->date_sent ) ),
                'date_full' => date_i18n( 'j M Y H:i', strtotime( $r->date_sent ) ),
                'date_day'  => date_i18n( 'Y-m-d', strtotime( $r->date_sent ) ),
            ];
        }
        return $out;
    }

    /* ─── BP Better Messages thread helpers ────────────────────── */

    /**
     * Ensures a 1:1 BM thread exists between two users.
     * Returns thread_id (existing or new), 0 on failure.
     * Idempotent — safe to call multiple times.
     */
    public static function ensure_bm_thread( int $user_a, int $user_b, string $welcome = '' ): int {
        if ( ! class_exists( 'Better_Messages' ) || ! function_exists( 'bm_get_table' ) ) return 0;
        if ( $user_a <= 0 || $user_b <= 0 || $user_a === $user_b ) return 0;

        global $wpdb;
        $tbl = bm_get_table( 'recipients' );

        $thread_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT r1.thread_id
               FROM {$tbl} r1
         INNER JOIN {$tbl} r2 ON r1.thread_id = r2.thread_id
              WHERE r1.user_id = %d AND r2.user_id = %d
              LIMIT 1",
            $user_a, $user_b
        ) );

        if ( $thread_id > 0 ) return $thread_id;

        if ( ! $welcome ) {
            $welcome = __( 'مرحباً! كيف يمكنني مساعدتك؟', 'rk-my-children' );
        }

        $result = Better_Messages()->functions->new_message( [
            'sender_id'    => $user_a,
            'recipients'   => [ $user_b ],
            'content'      => wp_kses_post( $welcome ),
            'send_push'    => false,
            'count_unread' => false,
            'show_on_site' => false,
        ] );

        if ( is_wp_error( $result ) ) {
            rkp_log( '[RK MessageService] ensure_bm_thread failed: ' . $result->get_error_message() );
            return 0;
        }

        return (int) $result;
    }

    /**
     * Returns BM unread count for $reader in their thread with $partner.
     * Returns 0 when BM is absent or no thread exists.
     */
    public static function bm_thread_unread( int $reader, int $partner ): int {
        if ( ! function_exists( 'bm_get_table' ) || $reader <= 0 || $partner <= 0 ) return 0;
        global $wpdb;
        $tbl = bm_get_table( 'recipients' );
        $thread_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT r1.thread_id
               FROM {$tbl} r1
         INNER JOIN {$tbl} r2 ON r1.thread_id = r2.thread_id
              WHERE r1.user_id = %d AND r2.user_id = %d
              LIMIT 1",
            $reader, $partner
        ) );
        if ( ! $thread_id ) return 0;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(unread_count,0) FROM {$tbl} WHERE thread_id = %d AND user_id = %d",
            $thread_id, $reader
        ) );
    }

    /* ─── Notification bridge ───────────────────────────────────── */

    private static function maybe_create_notification( int $to_id, int $child_id, string $body ): void {
        if ( ! function_exists( 'riada_notify_user' ) ) return;

        riada_notify_user(
            $to_id,
            '💬 رسالة جديدة',
            sprintf( __( 'رسالة جديدة: %s', 'rk-my-children' ), wp_trim_words( $body, 8 ) ),
            '',
            'new_message'
        );
    }

    /**
     * Inserts a row in wp_rk_notifications for the coach SPA bell.
     * Only runs when $to_id is a coach user (i.e., not a child/parent).
     */
 private static function push_coach_rk_notification( int $recipient_uid, int $child_id, string $body ): void {
        if ( $recipient_uid <= 0 || ! class_exists( 'RK_MC_Notification_Service' ) ) return;
        RK_MC_Notification_Service::push(
            $recipient_uid,
            'new_message',
            wp_trim_words( $body, 12 ),
            [ 'child_id' => $child_id ]
        );
    }
}

