<?php
declare( strict_types=1 );
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Extrait de CoachSessionRepository.php — factorisation par fonctionnalité.
 * Code déplacé verbatim, aucune modification de logique.
 */
trait RKP_Coach_Session_Repo_Courses {
    /**
     * Insère un booking manuel. Retourne l'ID inséré (0 = échec).
     *
     * @param  array $row  { child_id, appointment, session_name, status, attendance }
     */
    public static function create_booking( array $row ): int {
        $bt = self::bookings_table();
        if ( ! self::table_exists( $bt ) ) return 0;
        global $wpdb;
        $ok = $wpdb->insert( $bt, $row );
        return $ok ? (int) $wpdb->insert_id : 0;
    }

    /**
     * Cours (products) réservés pour une liste d'enfants, groupés par child_id.
     * Retourne [child_id => [{ id, title }]].
     *
     * @param  int[]  $child_ids   rk_children.id list
     * @return array<int, array>
     */
    public static function get_courses_for_children_batch( array $child_ids, int $coach_id ): array {
        if ( empty( $child_ids ) ) return [];
        $bt = self::bookings_table();
        if ( ! self::table_exists( $bt ) ) return [];
        global $wpdb;
        $ph   = implode( ',', array_fill( 0, count( $child_ids ), '%d' ) );

        $has_coach_col = self::column_exists( 'coach_id' );
        if ( $has_coach_col ) {
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT DISTINCT b.child_id, b.course_id AS id, p.post_title AS title
                   FROM {$bt} b
                   JOIN {$wpdb->posts} p ON p.ID = b.course_id
                  WHERE b.child_id IN ({$ph}) AND b.coach_id = %d
                    AND b.status IN ('confirmed','rescheduled') AND b.course_id > 0",
                ...array_merge( $child_ids, [ $coach_id ] )
            ) ) ?: [];
        } else {
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT DISTINCT b.child_id, b.course_id AS id, p.post_title AS title
                   FROM {$bt} b
                   JOIN {$wpdb->posts} p ON p.ID = b.course_id
                  WHERE b.child_id IN ({$ph})
                    AND b.status IN ('confirmed','rescheduled') AND b.course_id > 0",
                ...$child_ids
            ) ) ?: [];
        }

        $map = [];
        foreach ( $rows as $r ) {
            $map[ (int) $r->child_id ][] = [ 'id' => (int) $r->id, 'title' => (string) $r->title ];
        }
        return $map;
    }

    /**
     * Nombre de séances confirmées par enfant dans un intervalle (pour rapports hebdo).
     *
     * @param  int[]   $child_rk_ids
     * @param  string  $start  'Y-m-d H:i:s'
     * @param  string  $end    'Y-m-d H:i:s'
     * @return array<int, int>  [child_rk_id => session_count]
     */
    public static function get_session_counts_for_children( array $child_rk_ids, string $start, string $end ): array {
        if ( empty( $child_rk_ids ) ) return [];
        $bt = self::bookings_table();
        if ( ! self::table_exists( $bt ) ) return [];
        global $wpdb;
        $ph   = implode( ',', array_fill( 0, count( $child_rk_ids ), '%d' ) );
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT child_id, COUNT(*) AS cnt
               FROM {$bt}
              WHERE child_id IN ({$ph})
                AND appointment BETWEEN %s AND %s
                AND status IN ('confirmed','rescheduled')
              GROUP BY child_id",
            ...array_merge( $child_rk_ids, [ $start, $end ] )
        ) ) ?: [];
        $map = [];
        foreach ( $rows as $r ) {
            $map[ (int) $r->child_id ] = (int) $r->cnt;
        }
        return $map;
    }

    /**
     * Cours distincts réservés par un enfant avec stats de présence.
     *
     * @return array[]  { course_id, course_title, progress (%) }
     */
    public static function find_enrolled_courses_for_child( int $child_id ): array {
        if ( $child_id <= 0 ) return [];
        $bt = self::bookings_table();
        if ( ! self::table_exists( $bt ) ) return [];
        global $wpdb;

        // attendance column may not exist in all environments — guard before using it in SQL
        $att_expr = self::column_exists( 'attendance' )
            ? "SUM(CASE WHEN b.attendance = 'present' THEN 1 ELSE 0 END)"
            : '0';

        $rows = $wpdb->get_results( $wpdb->prepare(
            "(SELECT b.course_id,
                     COALESCE(NULLIF(p.post_title,''), NULLIF(b.session_name,''), 'مغامرة') AS course_title,
                     {$att_expr} AS sessions_done,
                     COUNT(*) AS sessions_total
                FROM {$bt} b
           LEFT JOIN {$wpdb->posts} p ON p.ID = b.course_id
               WHERE b.child_id = %d
                 AND b.status NOT IN ('cancelled')
                 AND b.course_id > 0
               GROUP BY b.course_id,
                        COALESCE(NULLIF(p.post_title,''), NULLIF(b.session_name,''), 'مغامرة'))
             UNION
             (SELECT 0 AS course_id,
                     b.session_name AS course_title,
                     {$att_expr} AS sessions_done,
                     COUNT(*) AS sessions_total
                FROM {$bt} b
               WHERE b.child_id = %d
                 AND b.status NOT IN ('cancelled')
                 AND (b.course_id IS NULL OR b.course_id = 0)
                 AND b.session_name IS NOT NULL AND b.session_name != ''
               GROUP BY b.session_name)
             ORDER BY sessions_total DESC
             LIMIT 10",
            $child_id, $child_id
        ) ) ?: [];
        return array_map( static function ( $row ) {
            $done  = (int) $row->sessions_done;
            $total = (int) $row->sessions_total;
            return [
                'course_id'    => (int) $row->course_id,
                'course_title' => (string) $row->course_title,
                'progress'     => $total > 0 ? (int) round( $done / $total * 100 ) : 0,
            ];
        }, $rows );
    }

    /**
     * Quizzes créés par le coach pour les cours réservés par cet enfant.
     *
     * @return object[]  { quiz_id, quiz_title, course_title }
     */
    public static function find_quizzes_for_child_by_coach( int $child_id, int $coach_id ): array {
        if ( $child_id <= 0 || $coach_id <= 0 ) return [];
        $bt = self::bookings_table();
        if ( ! self::table_exists( $bt ) ) return [];
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT q.ID AS quiz_id, q.post_title AS quiz_title, c.post_title AS course_title
               FROM {$wpdb->posts} q
               JOIN {$wpdb->posts} t   ON t.ID  = q.post_parent    AND t.post_type = 'topics'
               JOIN {$wpdb->posts} c   ON c.ID  = t.post_parent    AND c.post_type = 'courses'
               JOIN {$wpdb->postmeta} pm ON pm.post_id = c.ID      AND pm.meta_key = '_rk_linked_product'
               JOIN {$bt} b            ON b.course_id = CAST(pm.meta_value AS UNSIGNED)
              WHERE q.post_type   = 'tutor_quiz'
                AND q.post_status = 'publish'
                AND q.post_author = %d
                AND b.child_id   = %d
                AND b.status IN ('confirmed','rescheduled')
              GROUP BY q.ID, q.post_title, c.post_title
              ORDER BY q.post_date DESC",
            $coach_id, $child_id
        ) ) ?: [];
    }

    /**
     * Retourne le WP user_id du coach (colonne `coach`) pour un enfant, ou 0.
     * Utile pour les notifications mail : retrouver le coach depuis un booking.
     */
    public static function find_coach_wp_user_for_child( int $child_id ): int {
        if ( $child_id <= 0 ) return 0;
        $bt = self::bookings_table();
        if ( ! self::table_exists( $bt ) ) return 0;
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT DISTINCT coach FROM {$bt}
              WHERE child_id = %d AND coach > 0
              ORDER BY appointment DESC LIMIT 1",
            $child_id
        ) );
    }

    /**
     * Dernier course_id WC réservé entre un enfant et un coach.
     * Retourne 0 si aucun.
     */
    public static function find_last_course_id_for_child_coach( int $child_id, int $coach_id ): int {
        if ( $child_id <= 0 || $coach_id <= 0 ) return 0;
        $bt = self::bookings_table();
        if ( ! self::table_exists( $bt ) ) return 0;
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT DISTINCT course_id FROM {$bt}
              WHERE child_id = %d AND coach_id = %d AND course_id > 0
              ORDER BY appointment DESC LIMIT 1",
            $child_id, $coach_id
        ) );
    }

    /**
     * Cours distincts réservés par un enfant (pour sélecteur de cours dans child-detail).
     *
     * @return object[]  { id, title }
     */
    /**
     * Retourne les valeurs YEARWEEK(appointment,1) des semaines avec au moins 1 présence.
     * Triées DESC (semaine la plus récente en premier).
     *
     * @return int[]
     */
    public static function get_attendance_week_numbers( int $child_id, int $limit = 12 ): array {
        if ( $child_id <= 0 ) return [];
        $bt = self::bookings_table();
        if ( ! self::table_exists( $bt ) ) return [];
        global $wpdb;
        return array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
            "SELECT YEARWEEK(appointment, 1) AS yw
               FROM {$bt}
              WHERE child_id = %d AND attendance = 'present' AND appointment < NOW()
           GROUP BY yw
           ORDER BY yw DESC
              LIMIT %d",
            $child_id, $limit
        ) ) ?: [] );
    }

    /** Nombre de séances confirmées d'un enfant dans une plage. */
    public static function count_confirmed_in_range( int $child_id, string $start, string $end ): int {
        if ( $child_id <= 0 ) return 0;
        $bt = self::bookings_table();
        if ( ! self::table_exists( $bt ) ) return 0;
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$bt}
              WHERE child_id = %d AND appointment BETWEEN %s AND %s AND status = 'confirmed'",
            $child_id, $start, $end
        ) );
    }

    /** Nombre de séances avec attendance='present' d'un enfant dans une plage. */
    public static function count_present_in_range( int $child_id, string $start, string $end ): int {
        if ( $child_id <= 0 ) return 0;
        $bt = self::bookings_table();
        if ( ! self::table_exists( $bt ) ) return 0;
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$bt}
              WHERE child_id = %d AND appointment BETWEEN %s AND %s
                AND status = 'confirmed' AND attendance = 'present'",
            $child_id, $start, $end
        ) );
    }

    /** Retourne le session_name d'un booking (chaîne vide si introuvable). */
    public static function find_session_name( int $booking_id ): string {
        if ( $booking_id <= 0 ) return '';
        $bt = self::bookings_table();
        if ( ! self::table_exists( $bt ) ) return '';
        global $wpdb;
        return sanitize_text_field( $wpdb->get_var( $wpdb->prepare(
            "SELECT session_name FROM {$bt} WHERE id = %d LIMIT 1",
            $booking_id
        ) ) ?: '' );
    }

    public static function find_booked_courses_for_child( int $child_id ): array {
        if ( $child_id <= 0 ) return [];
        $bt = self::bookings_table();
        if ( ! self::table_exists( $bt ) ) return [];
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT DISTINCT b.course_id AS id, p.post_title AS title
               FROM {$bt} b
               JOIN {$wpdb->posts} p ON p.ID = b.course_id AND p.post_type = 'product'
              WHERE b.child_id = %d
                AND b.status IN ('confirmed','rescheduled')
                AND b.course_id > 0
              ORDER BY p.post_title ASC",
            $child_id
        ) ) ?: [];
    }
}
