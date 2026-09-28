<?php
/**
 * RiadaKids\Database\DB — v25 PRODUCTION
 *
 */

namespace RiadaKids\Database;

if ( ! defined( 'ABSPATH' ) ) exit;

class DB {

    /* ════════════════════════════════════════════════════════════
     * FIX01 — SQL LOCKS
     * ════════════════════════════════════════════════════════════ */

    public static function acquire_sql_lock( string $name, int $timeout = 0 ): bool {
        global $wpdb;
        return (int) $wpdb->get_var(
            $wpdb->prepare( "SELECT GET_LOCK(%s, %d)", $name, $timeout )
        ) === 1;
    }

    public static function release_sql_lock( string $name ): void {
        global $wpdb;
        $wpdb->get_var( $wpdb->prepare( "SELECT RELEASE_LOCK(%s)", $name ) );
    }

    /* ════════════════════════════════════════════════════════════
     * FIX02 — USER CREDITS TABLE
     * ════════════════════════════════════════════════════════════ */

    public static function get_user_credit_row( int $user_id ): ?object {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}rk_user_credits WHERE user_id=%d LIMIT 1",
            $user_id
        ) ) ?: null;
    }

    public static function upsert_user_credits( int $user_id, int $balance ): void {
        global $wpdb;
        $table = $wpdb->prefix . 'rk_user_credits';
        $wpdb->query( $wpdb->prepare(
            "INSERT INTO {$table} (user_id, balance, updated_at)
             VALUES (%d, %d, NOW())
             ON DUPLICATE KEY UPDATE balance=VALUES(balance), updated_at=NOW()",
            $user_id,
            max( 0, $balance )
        ) );
    }

    /**
     * FIX02/FIX06 — Transaction SQL atomique pour décrémenter les crédits.
     * Lit depuis wp_rk_user_credits avec SELECT FOR UPDATE.
     * Si usermeta seul (ancien user), migre automatiquement.
     *
     * @return int Nouveau solde, ou -1 si solde insuffisant
     */
    public static function credits_decrease_safe( int $user_id, int $amount ): int {
        global $wpdb;

        try {
            $wpdb->query( 'START TRANSACTION' );

            $table = $wpdb->prefix . 'rk_user_credits';

            $row = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM {$table} WHERE user_id=%d LIMIT 1 FOR UPDATE",
                $user_id
            ) );

            // Fallback : migrer depuis usermeta si première fois
            if ( ! $row ) {
                $meta_val = max( 0, (int) get_user_meta( $user_id, 'rk_session_credits', true ) );
                $wpdb->insert( $table, [
                    'user_id'    => $user_id,
                    'balance'    => $meta_val,
                    'updated_at' => current_time( 'mysql' ),
                ] );
                $row = $wpdb->get_row( $wpdb->prepare(
                    "SELECT * FROM {$table} WHERE user_id=%d LIMIT 1 FOR UPDATE",
                    $user_id
                ) );
            }

            $current = max( 0, (int) ( $row->balance ?? 0 ) );

            if ( $current < $amount ) {
                $wpdb->query( 'ROLLBACK' );
                return -1;
            }

            $new = $current - $amount;

            $wpdb->update( $table,
                [ 'balance' => $new, 'updated_at' => current_time( 'mysql' ) ],
                [ 'user_id' => $user_id ],
                [ '%d', '%s' ], [ '%d' ]
            );

            $wpdb->query( 'COMMIT' );
            return $new;

        } catch ( \Throwable $e ) {
            $wpdb->query( 'ROLLBACK' );
            \rk_log( 'DB', 'credits_decrease_safe exception: ' . $e->getMessage() );
            return -1;
        }
    }

    /**
     * Transaction SQL atomique pour INCRÉMENTER les crédits (remboursement).
     *
     * Utilise SELECT FOR UPDATE sur wp_rk_user_credits pour éviter les
     * race conditions lors d'annulations concurrentes.
     *
     * @param int $user_id  ID WordPress de l'utilisateur.
     * @param int $amount   Nombre de crédits à ajouter (positif).
     * @return int          Nouveau solde après incrément.
     */
    public static function credits_increase_safe( int $user_id, int $amount ): int {
        global $wpdb;

        try {
            $wpdb->query( 'START TRANSACTION' );

            $table = $wpdb->prefix . 'rk_user_credits';

            $row = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM {$table} WHERE user_id=%d LIMIT 1 FOR UPDATE",
                $user_id
            ) );

            // Si pas encore de ligne (user sans crédits), créer avec 0
            if ( ! $row ) {
                $meta_val = max( 0, (int) get_user_meta( $user_id, 'rk_session_credits', true ) );
                $wpdb->insert( $table, [
                    'user_id'    => $user_id,
                    'balance'    => $meta_val,
                    'updated_at' => current_time( 'mysql' ),
                ] );
                $row = $wpdb->get_row( $wpdb->prepare(
                    "SELECT * FROM {$table} WHERE user_id=%d LIMIT 1 FOR UPDATE",
                    $user_id
                ) );
            }

            $current = max( 0, (int) ( $row->balance ?? 0 ) );
            $new     = $current + abs( $amount );

            $wpdb->update(
                $table,
                [ 'balance' => $new, 'updated_at' => current_time( 'mysql' ) ],
                [ 'user_id' => $user_id ],
                [ '%d', '%s' ],
                [ '%d' ]
            );

            $wpdb->query( 'COMMIT' );
            return $new;

        } catch ( \Throwable $e ) {
            $wpdb->query( 'ROLLBACK' );
            \rk_log( 'DB', 'credits_increase_safe exception: ' . $e->getMessage() );

            // Fallback non-atomique en cas d'erreur DB
            $fallback = max( 0, (int) get_user_meta( $user_id, 'rk_session_credits', true ) );
            return $fallback;
        }
    }

    /* ════════════════════════════════════════════════════════════
     * FIX04 — JOB PROCESSOR
     * ════════════════════════════════════════════════════════════ */

    public static function process_pending_jobs( int $limit = 20 ): int {
        $jobs      = self::get_pending_jobs( $limit );
        $processed = 0;

        foreach ( $jobs as $job ) {
            self::increment_job_attempts( $job->id );

            try {
                $payload = json_decode( $job->payload, true ) ?: [];
                $success = self::dispatch_job( $job->job_type, $payload );

                self::update_job_status( $job->id, $success ? 'done' : 'failed', $success ? '' : 'dispatch returned false' );

                if ( $success ) $processed++;

            } catch ( \Throwable $e ) {
                self::update_job_status( $job->id, 'failed', $e->getMessage() );
                \rk_log( 'JOBS', "job#{$job->id} type={$job->job_type} exception: " . $e->getMessage() );
            }
        }

        return $processed;
    }

    private static function dispatch_job( string $type, array $payload ): bool {
        switch ( $type ) {
            case 'reward_email':
                $user_id = (int) ( $payload['user_id'] ?? 0 );
                if ( ! $user_id ) return false;
                return \RiadaKids\Rewards\RewardNotifier::send_reward_email( $user_id );

            default:
                \rk_log( 'JOBS', "type inconnu: {$type}" );
                return false;
        }
    }

    /* ════════════════════════════════════════════════════════════
     * BOOKINGS
     * ════════════════════════════════════════════════════════════ */

    public static function save_booking( array $data ): int {
        global $wpdb;

        $table      = $wpdb->prefix . 'rk_bookings';
        $booking_id = (int) ( $data['booking_id'] ?? 0 );
        $child_id   = (int) ( $data['child_id']   ?? 0 );
        if ( ! $booking_id ) return 0;

        $exists = self::booking_exists( $booking_id, $child_id );
        if ( $exists ) return $exists;

        $row = [
            'booking_id'          => $booking_id,
            'user_id'             => (int) ( $data['user_id'] ?? 0 ),
            'program_id'          => (int) ( $data['program_id'] ?? 0 ),
            'course_id'           => (int) ( $data['course_id'] ?? 0 ),
            'session_name'        => sanitize_text_field( $data['session_name'] ?? '' ),
            'child_id'            => (int) ( $data['child_id'] ?? 0 ),
            'appointment_type_id' => (int) ( $data['appointment_type_id'] ?? 0 ),
            'appointment'         => $data['appointment'] ?? null,
            'end_at'              => $data['end_at'] ?? null,
            'timezone'            => sanitize_text_field( $data['timezone'] ?? '' ),
            'coach'               => sanitize_text_field( $data['coach'] ?? '' ),
            'booking_date'        => $data['booking_date'] ?? current_time( 'mysql' ),
            'status'              => sanitize_text_field( $data['status'] ?? 'confirmed' ),
            'is_refunded'         => 0,
            'credits_used'        => max( 0, (int) ( $data['credits_used'] ?? 1 ) ),
            'ssa_link'            => esc_url_raw( $data['ssa_link'] ?? '' ),
            'meta_source'         => sanitize_text_field( $data['meta_source'] ?? '' ),
            'created_at'          => current_time( 'mysql' ),
        ];

        $wpdb->insert( $table, $row );

        if ( $wpdb->last_error ) {
            \rk_log( 'DB', 'save_booking error: ' . $wpdb->last_error );
        }

        return (int) $wpdb->insert_id;
    }

    public static function booking_exists( int $booking_id, int $child_id = 0 ): int {
        global $wpdb;
        if ( $child_id > 0 ) {
            return (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}rk_bookings
                 WHERE booking_id=%d AND child_id=%d LIMIT 1",
                $booking_id, $child_id
            ) );
        }
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}rk_bookings WHERE booking_id=%d LIMIT 1",
            $booking_id
        ) );
    }

    public static function get_booking_by_ssa_id( int $booking_id ): ?object {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}rk_bookings WHERE booking_id=%d LIMIT 1",
            $booking_id
        ) ) ?: null;
    }

    public static function get_all_by_ssa_id( int $booking_id ): array {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}rk_bookings WHERE booking_id=%d",
            $booking_id
        ) ) ?: [];
    }

    public static function update_booking_status( int $ssa_id, string $status ): void {
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'rk_bookings',
            [ 'status' => $status ],
            [ 'booking_id' => $ssa_id ],
            [ '%s' ], [ '%d' ]
        );
    }

    public static function update_booking_fields( int $ssa_id, array $fields ): void {
        global $wpdb;
        if ( empty( $fields ) ) return;
        $wpdb->update( $wpdb->prefix . 'rk_bookings', $fields, [ 'booking_id' => $ssa_id ] );
    }

    public static function mark_refunded( int $ssa_id ): void {
        global $wpdb;
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$wpdb->prefix}rk_bookings
             SET is_refunded=1, status='cancelled'
             WHERE booking_id=%d",
            $ssa_id
        ) );
    }

    public static function get_user_bookings( int $user_id ): array {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT rb.*, rc.child_name, rc.child_family_name, rc.child_age
             FROM {$wpdb->prefix}rk_bookings rb
             LEFT JOIN {$wpdb->prefix}rk_children rc ON rc.id=rb.child_id
             WHERE rb.user_id=%d ORDER BY rb.created_at DESC",
            $user_id
        ) ) ?: [];
    }

    public static function get_all_bookings( array $filters = [], int $limit = 25, int $offset = 0 ): array {
        global $wpdb;
        [ $where, $params ] = self::build_booking_where( $filters );
        $q = "SELECT rb.*, rc.child_name, rc.child_family_name, rc.child_age,
                     u.display_name AS customer_name, u.user_email AS customer_email
              FROM {$wpdb->prefix}rk_bookings rb
              LEFT JOIN {$wpdb->prefix}rk_children rc ON rb.child_id=rc.id
              LEFT JOIN {$wpdb->users} u ON rb.user_id=u.ID
              WHERE {$where} ORDER BY rb.created_at DESC LIMIT %d OFFSET %d";
        $params[] = $limit;
        $params[] = $offset;
        return $wpdb->get_results( $wpdb->prepare( $q, ...$params ) ) ?: [];
    }

    public static function count_bookings( array $filters = [] ): int {
        global $wpdb;
        [ $where, $params ] = self::build_booking_where( $filters );
        $q = "SELECT COUNT(*) FROM {$wpdb->prefix}rk_bookings rb
              LEFT JOIN {$wpdb->prefix}rk_children rc ON rb.child_id=rc.id
              LEFT JOIN {$wpdb->users} u ON rb.user_id=u.ID
              WHERE {$where}";
        return $params
            ? (int) $wpdb->get_var( $wpdb->prepare( $q, ...$params ) )
            : (int) $wpdb->get_var( $q );
    }

    public static function get_null_bookings( int $limit = 50 ): array {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT rb.*, u.display_name AS customer_name, u.user_email AS customer_email
             FROM {$wpdb->prefix}rk_bookings rb
             LEFT JOIN {$wpdb->users} u ON rb.user_id=u.ID
             WHERE rb.program_id=0 OR rb.course_id=0 OR rb.child_id=0
             ORDER BY rb.created_at DESC LIMIT %d",
            $limit
        ) ) ?: [];
    }

    public static function count_null_bookings(): int {
        global $wpdb;
        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}rk_bookings WHERE program_id=0 OR course_id=0 OR child_id=0"
        );
    }

    public static function get_latest_user_booking( int $user_id ): ?object {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}rk_bookings WHERE user_id=%d ORDER BY id DESC LIMIT 1",
            $user_id
        ) ) ?: null;
    }

    private static function build_booking_where( array $filters ): array {
        $where = '1=1'; $params = [];

        if ( ! empty( $filters['search'] ) ) {
            $like    = '%' . $GLOBALS['wpdb']->esc_like( $filters['search'] ) . '%';
            $where  .= ' AND (u.display_name LIKE %s OR u.user_email LIKE %s OR rc.child_name LIKE %s OR rb.session_name LIKE %s)';
            $params  = array_merge( $params, [ $like, $like, $like, $like ] );
        }
        if ( ! empty( $filters['status'] ) ) {
            $where .= ' AND rb.status=%s'; $params[] = $filters['status'];
        }
        if ( ! empty( $filters['program_id'] ) ) {
            $where .= ' AND rb.program_id=%d'; $params[] = (int) $filters['program_id'];
        }

        return [ $where, $params ];
    }

    /* ════════════════════════════════════════════════════════════
     * CHILDREN
     * ════════════════════════════════════════════════════════════ */

    /**
     * Colonnes réellement présentes dans wp_rk_children.
     *
     * La table est gérée par rk-platform (module Children) : ce plugin en est
     * consommateur. Une colonne peut donc manquer si la migration n'a pas
     * encore tourné — on filtre plutôt que de laisser l'INSERT échouer.
     *
     * @return string[]
     */
    private static function children_columns(): array {
        static $cols = null;
        if ( null !== $cols ) return $cols;

        global $wpdb;
        $cols = $wpdb->get_col( "SHOW COLUMNS FROM {$wpdb->prefix}rk_children" ) ?: [];
        return $cols;
    }

    public static function insert_child( array $data ): int {
        global $wpdb;

        $row = [
            'user_id'    => (int) ( $data['user_id'] ?? 0 ),
            'child_name' => sanitize_text_field( $data['child_name'] ?? '' ),
            'child_age'  => (int) ( $data['child_age'] ?? 0 ),
            'created_at' => current_time( 'mysql' ),
        ];

        $optional = [
            'child_family_name' => sanitize_text_field( $data['child_family_name'] ?? '' ),
            'child_username'    => sanitize_user( $data['child_username'] ?? '', true ),
            'avatar_url'        => esc_url_raw( $data['avatar_url'] ?? '' ),
        ];

        $available = self::children_columns();
        foreach ( $optional as $col => $val ) {
            if ( in_array( $col, $available, true ) ) {
                $row[ $col ] = $val;
            } elseif ( '' !== $val ) {
                error_log( "[RK] insert_child : colonne {$col} absente, valeur ignoree." );
            }
        }

        $wpdb->insert( $wpdb->prefix . 'rk_children', $row );
        return (int) $wpdb->insert_id;
    }

    public static function get_user_children( int $user_id ): array {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT rc.*,
                    (SELECT COUNT(*) FROM {$wpdb->prefix}rk_bookings rb WHERE rb.child_id = rc.id) AS booking_count
             FROM {$wpdb->prefix}rk_children rc
             WHERE rc.user_id=%d ORDER BY rc.child_name ASC",
            $user_id
        ) ) ?: [];
    }

    /* ════════════════════════════════════════════════════════════
     * CREDIT LOGS
     * ════════════════════════════════════════════════════════════ */

    public static function add_credit_log( int $user_id, string $action, int $amount, int $balance_after, string $details = '' ): bool {
        global $wpdb;
        return (bool) $wpdb->insert(
            $wpdb->prefix . 'rk_credit_logs',
            [ 'user_id' => $user_id, 'action_type' => $action, 'amount' => $amount,
              'balance_after' => $balance_after, 'details' => $details, 'created_at' => current_time( 'mysql' ) ],
            [ '%d', '%s', '%d', '%d', '%s', '%s' ]
        );
    }

    public static function get_credit_logs( int $user_id, int $limit = 50 ): array {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}rk_credit_logs WHERE user_id=%d ORDER BY created_at DESC LIMIT %d",
            $user_id, $limit
        ) ) ?: [];
    }

    /* ════════════════════════════════════════════════════════════
     * POINTS LOGS
     * ════════════════════════════════════════════════════════════ */

    public static function add_points_log( int $user_id, int $points, string $action, string $description = '' ): bool {
        global $wpdb;
        return (bool) $wpdb->insert(
            $wpdb->prefix . 'rk_points_logs',
            [ 'user_id' => $user_id, 'points' => $points, 'action_type' => $action,
              'description' => $description, 'created_at' => current_time( 'mysql' ) ],
            [ '%d', '%d', '%s', '%s', '%s' ]
        );
    }

    public static function get_points_logs( int $user_id, int $limit = 50 ): array {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}rk_points_logs WHERE user_id=%d ORDER BY created_at DESC LIMIT %d",
            $user_id, $limit
        ) ) ?: [];
    }

    /* ════════════════════════════════════════════════════════════
     * NOTIFICATIONS
     * ════════════════════════════════════════════════════════════ */

    public static function add_notification( int $user_id, string $type, string $message, array $meta = [] ): int {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'rk_notifications', [
            'user_id' => $user_id, 'type' => $type, 'message' => $message,
            'is_read' => 0, 'meta' => ! empty( $meta ) ? wp_json_encode( $meta ) : null,
            'created_at' => current_time( 'mysql' ),
        ], [ '%d', '%s', '%s', '%d', '%s', '%s' ] );
        return (int) $wpdb->insert_id;
    }

    public static function get_unread_notifications( int $user_id ): array {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}rk_notifications WHERE user_id=%d AND is_read=0 ORDER BY created_at DESC",
            $user_id
        ) ) ?: [];
    }

    public static function mark_notification_read( int $notif_id, int $user_id ): void {
        global $wpdb;
        $wpdb->update( $wpdb->prefix . 'rk_notifications', [ 'is_read' => 1 ],
            [ 'id' => $notif_id, 'user_id' => $user_id ], [ '%d' ], [ '%d', '%d' ] );
    }

    /* ════════════════════════════════════════════════════════════
     * FIX04 — JOBS QUEUE
     * ════════════════════════════════════════════════════════════ */

    public static function enqueue_job( string $type, array $payload, int $delay = 0 ): int {
        global $wpdb;
        $scheduled = date( 'Y-m-d H:i:s', time() + $delay );
        $wpdb->insert( $wpdb->prefix . 'rk_jobs', [
            'job_type'     => sanitize_text_field( $type ),
            'payload'      => wp_json_encode( $payload ),
            'status'       => 'pending',
            'attempts'     => 0,
            'max_attempts' => defined( 'RK_JOB_MAX_RETRIES' ) ? RK_JOB_MAX_RETRIES : 3,
            'scheduled_at' => $scheduled,
            'created_at'   => current_time( 'mysql' ),
        ] );
        return (int) $wpdb->insert_id;
    }

    public static function get_pending_jobs( int $limit = 20 ): array {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}rk_jobs
             WHERE status IN ('pending','failed') AND attempts < max_attempts AND scheduled_at <= NOW()
             ORDER BY scheduled_at ASC LIMIT %d",
            $limit
        ) ) ?: [];
    }

    public static function update_job_status( int $job_id, string $status, string $error = '' ): void {
        global $wpdb;
        $wpdb->update( $wpdb->prefix . 'rk_jobs',
            [ 'status' => $status, 'error_msg' => $error ?: null, 'processed_at' => current_time( 'mysql' ) ],
            [ 'id' => $job_id ], [ '%s', '%s', '%s' ], [ '%d' ] );
    }

    public static function increment_job_attempts( int $job_id ): void {
        global $wpdb;
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$wpdb->prefix}rk_jobs SET attempts=attempts+1 WHERE id=%d",
            $job_id
        ) );
    }

    /* ════════════════════════════════════════════════════════════
     * FIX10 — QUOTA
     * ════════════════════════════════════════════════════════════ */

    public static function quota_exists( int $child_id, string $session_name, string $appointment_dt ): bool {
        global $wpdb;
        $key = md5( $child_id . '|' . $session_name . '|' . $appointment_dt );
        return (bool) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}rk_booking_quota WHERE child_id=%d AND session_key=%s LIMIT 1",
            $child_id, $key
        ) );
    }

    public static function quota_register( int $child_id, string $session_name, string $appointment_dt, int $booking_id ): void {
        global $wpdb;
        $key = md5( $child_id . '|' . $session_name . '|' . $appointment_dt );
        $wpdb->replace( $wpdb->prefix . 'rk_booking_quota', [
            'child_id'    => $child_id,
            'session_key' => $key,
            'booking_id'  => $booking_id,
            'created_at'  => current_time( 'mysql' ),
        ], [ '%d', '%s', '%d', '%s' ] );
    }

    /* ════════════════════════════════════════════════════════════
     * FIX11 — CLEANUP LOGS
     * ════════════════════════════════════════════════════════════ */

    public static function delete_old_credit_logs( int $days = 365 ): int {
        global $wpdb;
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}rk_credit_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
            $days
        ) );
        return (int) $wpdb->rows_affected;
    }

    public static function delete_old_points_logs( int $days = 365 ): int {
        global $wpdb;
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}rk_points_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
            $days
        ) );
        return (int) $wpdb->rows_affected;
    }

    public static function delete_old_notifications( int $days = 90 ): int {
        global $wpdb;
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}rk_notifications WHERE is_read=1 AND created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
            $days
        ) );
        return (int) $wpdb->rows_affected;
    }

    /* ════════════════════════════════════════════════════════════
     * FIX11 — SSA MAP
     * ════════════════════════════════════════════════════════════ */

    public static function get_ssa_type_for_course( int $course_id ): int {
        global $wpdb;
        $type_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT appointment_type_id FROM {$wpdb->prefix}rk_ssa_map WHERE course_id=%d AND is_active=1 LIMIT 1",
            $course_id
        ) );
        return $type_id ?: ( defined( 'RK_SSA_APPT_TYPE_ID' ) ? (int) RK_SSA_APPT_TYPE_ID : 0 );
    }

    public static function get_all_ssa_maps(): array {
        global $wpdb;
        return $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}rk_ssa_map ORDER BY course_id ASC" ) ?: [];
    }

    public static function upsert_ssa_map( int $course_id, int $program_id, int $type_id, string $label = '' ): void {
        global $wpdb;
        $wpdb->replace( $wpdb->prefix . 'rk_ssa_map', [
            'course_id' => $course_id, 'program_id' => $program_id,
            'appointment_type_id' => $type_id, 'label' => sanitize_text_field( $label ), 'is_active' => 1,
        ], [ '%d', '%d', '%d', '%s', '%d' ] );
    }

    /**
     * AJOUT — supprime tout mapping SSA existant pour un cours (utilisé
     * par l'écran d'admin « ربط SSA بالدورات » quand l'admin choisit
     * « — بدون ربط — »). get_ssa_type_for_course() retombe alors sur son
     * comportement d'origine (0, ou RK_SSA_APPT_TYPE_ID si défini).
     */
    public static function delete_ssa_map_for_course( int $course_id ): void {
        global $wpdb;
        $wpdb->delete( $wpdb->prefix . 'rk_ssa_map', [ 'course_id' => $course_id ], [ '%d' ] );
    }

    /* ════════════════════════════════════════════════════════════
     * FIX12 — EXPORT SNAPSHOT
     * ════════════════════════════════════════════════════════════ */

    public static function export_bookings_snapshot(): string|false {
        $rows = self::get_all_bookings( [], 99999 );
        if ( empty( $rows ) ) return false;
        if ( ! defined( 'RK_BACKUP_DIR' ) ) return false;

        if ( ! is_dir( RK_BACKUP_DIR ) ) {
            wp_mkdir_p( RK_BACKUP_DIR );
            file_put_contents( RK_BACKUP_DIR . '.htaccess', 'Deny from all' );
        }

        $file = RK_BACKUP_DIR . 'rk-bookings-' . date( 'Y-m-d_H-i' ) . '.csv';
        $fh   = fopen( $file, 'w' );
        if ( ! $fh ) return false;

        fputs( $fh, "\xEF\xBB\xBF" );
        fputcsv( $fh, [ 'id','booking_id','user_id','program_id','course_id','session_name',
            'child_id','appointment','status','credits_used','is_refunded','meta_source','created_at' ] );

        foreach ( $rows as $r ) {
            fputcsv( $fh, [ $r->id, $r->booking_id, $r->user_id, $r->program_id, $r->course_id,
                $r->session_name, $r->child_id, $r->appointment, $r->status,
                $r->credits_used, $r->is_refunded, $r->meta_source, $r->created_at ] );
        }

        fclose( $fh );
        return $file;
    }

    /* ════════════════════════════════════════════════════════════
     * SSA UTILS
     * ════════════════════════════════════════════════════════════ */

    public static function ssa_table_exists(): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'ssa_appointments';
        return $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) === $table;
    }

    /**
     * URL SSA de modification / annulation d'un rendez-vous par le client.
     *
     * SSA expose cette valeur dans ses modèles d'email sous la forme
     * `{{ Appointment.public_edit_url }}` — elle est donc CALCULÉE par SSA,
     * pas stockée brute en base. On interroge l'API du plugin plutôt que
     * de tenter de reconstruire le jeton.
     *
     * Format final :
     *   https://site.com/?appointment_action=edit&appointment_token={token}
     *
     * Surchargeable :
     *   add_filter( 'rk_booking_reschedule_url', fn( $u, $id, $a ) => '…', 10, 3 );
     *
     * @param int    $appointment_id ID SSA (rk_bookings.booking_id).
     * @param string $action         'edit' (replanifier) ou 'cancel'.
     * @return string URL absolue, ou '' si SSA ne la fournit pas.
     */
    public static function get_ssa_edit_url( int $appointment_id, string $action = 'edit' ): string {
        $action = in_array( $action, [ 'edit', 'cancel' ], true ) ? $action : 'edit';
        $url    = '';

        $appt = self::get_ssa_appointment_array( $appointment_id );

        if ( $appt ) {
            // 1. SSA a déjà calculé l'URL : on la prend telle quelle.
            if ( ! empty( $appt['public_edit_url'] ) && is_string( $appt['public_edit_url'] ) ) {
                $url = $appt['public_edit_url'];

                // L'URL native pointe sur 'edit' ; adapter si on veut annuler.
                if ( 'cancel' === $action ) {
                    $url = add_query_arg( 'appointment_action', 'cancel', $url );
                }
            }

            // 2. Sinon : le jeton seul suffit à reconstruire l'URL.
            if ( '' === $url ) {
                foreach ( [ 'public_edit_token', 'appointment_token', 'edit_token', 'token', 'edit_hash' ] as $k ) {
                    if ( ! empty( $appt[ $k ] ) && is_string( $appt[ $k ] ) ) {
                        $url = add_query_arg(
                            [ 'appointment_action' => $action, 'appointment_token' => $appt[ $k ] ],
                            home_url( '/' )
                        );
                        break;
                    }
                }
            }
        }

        if ( '' === $url ) {
            error_log(
                '[RK] URL SSA introuvable pour appointment#' . $appointment_id
                . ' — clés disponibles : '
                . ( $appt ? implode( ', ', array_keys( $appt ) ) : 'aucune (RDV introuvable)' )
            );
        }

        /**
         * Permet d'imposer l'URL si l'API SSA diffère.
         *
         * @param string $url            URL calculée ('' si indisponible).
         * @param int    $appointment_id ID du rendez-vous SSA.
         * @param string $action         'edit' | 'cancel'.
         */
        return (string) apply_filters( 'rk_booking_reschedule_url', $url, $appointment_id, $action );
    }

    /**
     * Rendez-vous SSA sous forme de tableau, ENRICHI par SSA lorsque c'est
     * possible (public_edit_url, jetons…).
     *
     * L'API de SSA a changé de forme au fil des versions : on essaie les
     * points d'entrée connus, du plus complet au plus brut, puis on retombe
     * sur un SELECT direct.
     *
     * @param int $appointment_id
     * @return array<string,mixed> Tableau vide si introuvable.
     */
    public static function get_ssa_appointment_array( int $appointment_id ): array {
        if ( $appointment_id <= 0 ) {
            return [];
        }

        // ── Voie 1 : API PHP de SSA (seule à fournir public_edit_url) ──
        if ( function_exists( 'ssa' ) ) {
            try {
                $ssa = ssa();

                foreach ( [ 'appointment_model', 'appointment' ] as $prop ) {
                    if ( ! isset( $ssa->{$prop} ) || ! is_object( $ssa->{$prop} ) ) {
                        continue;
                    }
                    $model = $ssa->{$prop};

                    foreach ( [ 'get', 'get_by_id', 'get_appointment', 'query_one' ] as $method ) {
                        if ( ! method_exists( $model, $method ) ) {
                            continue;
                        }

                        $result = $model->{$method}( $appointment_id );
                        $result = is_object( $result ) ? (array) $result : $result;

                        if ( ! is_array( $result ) || ! $result ) {
                            continue;
                        }

                        // Demander à SSA d'enrichir la ligne si un formateur existe.
                        foreach ( [ 'prepare_appointment_for_response', 'format_appointment', 'add_computed_fields' ] as $fmt ) {
                            if ( method_exists( $model, $fmt ) ) {
                                $enriched = $model->{$fmt}( $result );
                                if ( is_array( $enriched ) && $enriched ) {
                                    $result = $enriched;
                                }
                                break;
                            }
                        }

                        return $result;
                    }
                }
            } catch ( \Throwable $e ) {
                // API absente ou signature différente : on continue.
                rk_log( 'SSA', 'get_ssa_appointment_array: ' . $e->getMessage(), 'warning' );
            }
        }

        // ── Voie 2 : lecture directe de la table ──
        if ( ! self::ssa_table_exists() ) {
            return [];
        }

        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ssa_appointments WHERE id = %d",
            $appointment_id
        ), ARRAY_A );

        return is_array( $row ) ? $row : [];
    }

    public static function get_recent_ssa_appointments( int $limit = 30 ): array {
        global $wpdb;
        if ( ! self::ssa_table_exists() ) return [];
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ssa_appointments
             WHERE status NOT IN ('canceled','cancelled','abandoned','rejected')
             ORDER BY id DESC LIMIT %d",
            $limit
        ) ) ?: [];
    }

    /**
     * Synchronise un rendez-vous SSA vers wp_rk_bookings (appelé par le CRON).
     *
     * - Si le booking n'existe pas encore → création via BookingService::ensure_from_ssa().
     * - Si le booking existe → mise à jour du statut SSA → RK.
     * - Pose un transient 1 h pour éviter le re-traitement dans le prochain tick.
     *
     * @param array $appt  Ligne brute de wp_ssa_appointments castée en tableau.
     */
    public static function sync_ssa_appointment( array $appt ): void {
        $ssa_id = (int) ( $appt['id'] ?? 0 );
        if ( ! $ssa_id ) return;

        $ssa_status = sanitize_text_field( $appt['status'] ?? '' );
        $existing   = self::booking_exists( $ssa_id );

        if ( $existing ) {
            // Synchronise uniquement le statut si différent
            $rk_status = match ( $ssa_status ) {
                'booked', 'confirmed'                             => 'confirmed',
                'rescheduled'                                     => 'rescheduled',
                'canceled', 'cancelled', 'abandoned', 'rejected'  => 'cancelled',
                default                                           => $ssa_status,
            };
            if ( $rk_status ) {
                self::update_booking_status( $ssa_id, $rk_status );
            }
        } else {
            // Crée le booking si l'heure est dans le futur ou très récente
            $start = strtotime( $appt['start_date'] ?? '' );
            if ( $start && $start > strtotime( '-24 hours' ) ) {
                \RiadaKids\Booking\BookingService::ensure_from_ssa( $appt, 'cron_sync' );
            }
        }

        // Marque comme traité pour éviter un re-traitement dans le prochain tick CRON
        set_transient( 'rk_cron_skip_' . $ssa_id, 1, HOUR_IN_SECONDS );
    }
}