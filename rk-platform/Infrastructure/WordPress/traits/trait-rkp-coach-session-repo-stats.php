<?php
declare( strict_types=1 );
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Extrait de CoachSessionRepository.php — factorisation par fonctionnalité.
 * Code déplacé verbatim, aucune modification de logique.
 */
trait RKP_Coach_Session_Repo_Stats {
    /**
     * Agrégats mensuels pour le coach (sessions confirmées, présences).
     * Utilise les 3 chemins d'identification pour ne rien manquer.
     *
     * @return array{sessions: int, present: int}
     */
    public static function get_monthly_raw( int $coach_id, string $start, string $end ): array {
        global $wpdb;
        $bt = self::bookings_table();

        $or_conds  = [];
        $or_params = [];

        $ssa_ids = self::get_ssa_type_ids_for_coach( $coach_id );
        if ( ! empty( $ssa_ids ) ) {
            $ph = implode( ',', array_fill( 0, count( $ssa_ids ), '%d' ) );
            $or_conds[]  = "appointment_type_id IN ({$ph})";
            $or_params   = array_merge( $or_params, $ssa_ids );
        }

        $cc = self::coach_map_table();
        if ( self::table_exists( $cc ) ) {
            $or_conds[]  = "child_id IN (SELECT child_id FROM {$cc} WHERE coach_id = %d)";
            $or_params[] = $coach_id;
        }

        if ( self::column_exists( 'coach_id' ) ) {
            $or_conds[]  = '(coach_id = %d AND coach_id > 0)';
            $or_params[] = $coach_id;
        }

        if ( empty( $or_conds ) ) return [ 'sessions' => 0, 'present' => 0, 'recorded' => 0 ];

        $ownership = '(' . implode( ' OR ', $or_conds ) . ')';

        $sessions = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(DISTINCT id) FROM {$bt}
              WHERE {$ownership} AND appointment BETWEEN %s AND %s AND status = 'confirmed'",
            ...array_merge( $or_params, [ $start, $end ] )
        ) );

        $present  = 0;
        $recorded = 0;
        if ( self::column_exists( 'attendance' ) ) {
            $present = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(DISTINCT id) FROM {$bt}
                  WHERE {$ownership} AND appointment BETWEEN %s AND %s
                    AND status = 'confirmed' AND attendance = 'present'",
                ...array_merge( $or_params, [ $start, $end ] )
            ) );
            // Sessions dont la présence a été pointée — dénominateur correct
            // du taux de présence (exclut les sessions futures non pointées).
            $recorded = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(DISTINCT id) FROM {$bt}
                  WHERE {$ownership} AND appointment BETWEEN %s AND %s
                    AND status = 'confirmed' AND attendance IN ('present','absent','late')",
                ...array_merge( $or_params, [ $start, $end ] )
            ) );
        }

        return [ 'sessions' => $sessions, 'present' => $present, 'recorded' => $recorded ];
    }

    /** Vérifie l'existence d'une colonne dans wp_rk_bookings (cache mémoire). */
    private static function column_exists( string $col ): bool {
        global $wpdb;
        static $cache = [];
        if ( ! isset( $cache[ $col ] ) ) {
            $cache[ $col ] = (bool) $wpdb->get_var(
                $wpdb->prepare( 'SHOW COLUMNS FROM ' . self::bookings_table() . ' LIKE %s', $col )
            );
        }
        return $cache[ $col ];
    }

    /**
     * v9.23 — Même garde que column_exists() mais pour wp_rk_children,
     * requise pour child_family_name : colonne ajoutée en v6.1.0
     * (voir rk-mc-db-helpers.php), donc potentiellement absente sur une
     * installation ancienne jamais passée par la migration ADD COLUMN.
     */
    private static function children_column_exists( string $col ): bool {
        global $wpdb;
        static $cache = [];
        if ( ! isset( $cache[ $col ] ) ) {
            $cache[ $col ] = (bool) $wpdb->get_var(
                $wpdb->prepare( 'SHOW COLUMNS FROM ' . self::children_table() . ' LIKE %s', $col )
            );
        }
        return $cache[ $col ];
    }

    /**
     * Nombre de séances dans un intervalle de dates pour ce coach.
     */
    /**
     * Nombre de séances dans un intervalle pour ce coach.
     * Même logique 3-chemins que find_by_range() pour garantir la cohérence des compteurs.
     */
    public static function count_in_range( int $coach_id, string $start, string $end ): int {
        global $wpdb;
        $bt = self::bookings_table();
        if ( ! self::table_exists( $bt ) ) return 0;

        $or_conds  = [];
        $or_params = [];

        $ssa_ids = self::get_ssa_type_ids_for_coach( $coach_id );
        if ( ! empty( $ssa_ids ) ) {
            $ph = implode( ',', array_fill( 0, count( $ssa_ids ), '%d' ) );
            $or_conds[]  = "appointment_type_id IN ({$ph})";
            $or_params   = array_merge( $or_params, $ssa_ids );
        }

        $cc = self::coach_map_table();
        if ( self::table_exists( $cc ) ) {
            $or_conds[]  = "child_id IN (SELECT child_id FROM {$cc} WHERE coach_id = %d)";
            $or_params[] = $coach_id;
        }

        if ( self::column_exists( 'coach_id' ) ) {
            $or_conds[]  = '(coach_id = %d AND coach_id > 0)';
            $or_params[] = $coach_id;
        }

        if ( empty( $or_conds ) ) return 0;

        $ownership = '(' . implode( ' OR ', $or_conds ) . ')';
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(DISTINCT id) FROM {$bt}
              WHERE {$ownership} AND appointment BETWEEN %s AND %s
                AND status IN ('confirmed','rescheduled','booked')",
            ...array_merge( $or_params, [ $start, $end ] )
        ) );
    }

    /**
     * Liste paginée de séances pour une liste d'enfants, avec filtre.
     *
     * @param  int[]   $child_ids
     * @param  string  $filter   'upcoming'|'past'|'all'
     * @param  string  $now      current_time('mysql')
     * @return object[]
     */
    public static function find_paginated_for_children(
        array $child_ids, string $filter, string $now, int $per_page, int $offset
    ): array {
        if ( empty( $child_ids ) ) return [];
        $bt = self::bookings_table();
        $ct = self::children_table();
        if ( ! self::table_exists( $bt ) ) return [];
        global $wpdb;
        $ph     = implode( ',', array_fill( 0, count( $child_ids ), '%d' ) );
        $where  = "b.child_id IN ({$ph})";
        $params = $child_ids;
        $order  = 'ASC';
        if ( $filter === 'upcoming' ) {
            $where   .= ' AND b.appointment > %s';
            $params[] = $now;
        } elseif ( $filter === 'past' ) {
            $where   .= ' AND b.appointment <= %s';
            $params[] = $now;
            $order    = 'DESC';
        }
        $url_col = self::column_exists( 'meeting_url' ) ? ', b.meeting_url' : ", '' AS meeting_url";
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT b.id AS booking_id, b.child_id, b.appointment, b.session_name, b.status, c.child_name{$url_col}
               FROM {$bt} b
          LEFT JOIN {$ct} c ON c.id = b.child_id
              WHERE {$where}
           ORDER BY b.appointment {$order}
              LIMIT %d OFFSET %d",
            ...array_merge( $params, [ $per_page, $offset ] )
        ) ) ?: [];
    }

    /**
     * Compte les séances pour une liste d'enfants avec un filtre.
     *
     * @param  int[]   $child_ids
     * @param  string  $filter   'upcoming'|'past'|'all'
     * @param  string  $now      current_time('mysql')
     */
    public static function count_for_children( array $child_ids, string $filter, string $now ): int {
        if ( empty( $child_ids ) ) return 0;
        $bt = self::bookings_table();
        if ( ! self::table_exists( $bt ) ) return 0;
        global $wpdb;
        $ph     = implode( ',', array_fill( 0, count( $child_ids ), '%d' ) );
        $where  = "b.child_id IN ({$ph})";
        $params = $child_ids;
        if ( $filter === 'upcoming' ) {
            $where   .= ' AND b.appointment > %s';
            $params[] = $now;
        } elseif ( $filter === 'past' ) {
            $where   .= ' AND b.appointment <= %s';
            $params[] = $now;
        }
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$bt} b WHERE {$where}",
            ...$params
        ) );
    }

    /**
    /**
     * Compte les séances d'un coach.
     *
     * CORRECTIF (revue Phase 3) — quand la colonne coach_id existe, elle
     * est désormais le filtre PRINCIPAL ET OBLIGATOIRE : deux coaches
     * partageant le même enfant (rk_child_coaches) ne doivent voir QUE
     * leurs propres bookings, jamais ceux de l'autre coach simplement
     * parce qu'ils sont tous deux associés à cet enfant. Le chemin
     * child_ids ne sert plus de filtre principal dans ce cas — il ne
     * reste utilisé que comme repli pour les installations où coach_id
     * n'existe pas du tout (chemin 1 legacy).
     *
     *   1. colonne coach_id (si elle existe) — obligatoire, priorité 1
     *   2. child_ids (rk_child_coaches) — repli SI coach_id n'existe pas
     *   3. appointment_type_id via SSA Staff map — dernier repli
     */
    public static function count_for_coach(
        int $coach_id, array $child_ids, string $filter, string $now
    ): int {
        $bt = self::bookings_table();
        if ( ! self::table_exists( $bt ) ) return 0;
        global $wpdb;

        [ $date_cond, $date_params ] = self::build_date_filter( $filter, $now );

        // Chemin 1 : colonne coach_id — SOURCE DE VÉRITÉ dès qu'elle existe.
        // On ne tombe PAS sur child_ids dans ce cas, même si le résultat
        // est 0 (0 est une réponse valide : ce coach n'a simplement aucune
        // séance sur ce filtre — pas une raison de retomber sur "tous les
        // bookings des enfants qu'il partage avec d'autres coaches").
        if ( self::column_exists( 'coach_id' ) ) {
            return (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$bt} b WHERE b.coach_id = %d AND b.coach_id > 0{$date_cond}",
                ...array_merge( [ $coach_id ], $date_params )
            ) );
        }

        // Chemin 2 : child_ids — uniquement si coach_id n'existe pas du
        // tout sur cette installation (ancien schéma).
        if ( ! empty( $child_ids ) ) {
            $ph    = implode( ',', array_fill( 0, count( $child_ids ), '%d' ) );
            $count = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$bt} b WHERE b.child_id IN ({$ph}){$date_cond}",
                ...array_merge( $child_ids, $date_params )
            ) );
            if ( $count > 0 ) return $count;
        }

        // Chemin 3 : appointment_type_id via SSA Staff map
        $type_ids = self::get_ssa_type_ids_for_coach( $coach_id );
        if ( ! empty( $type_ids ) ) {
            $ph = implode( ',', array_fill( 0, count( $type_ids ), '%d' ) );
            return (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$bt} b WHERE b.appointment_type_id IN ({$ph}){$date_cond}",
                ...array_merge( $type_ids, $date_params )
            ) );
        }

        return 0;
    }

    /**
     * Liste paginée de séances pour un coach.
     * CORRECTIF (revue Phase 3) — même priorité que count_for_coach() :
     * coach_id est obligatoire et exclusif dès qu'il existe, pour rester
     * cohérent avec le total retourné par count_for_coach() (pagination).
     *
     * @return object[]
     */
    public static function find_paginated_for_coach(
        int $coach_id, array $child_ids, string $filter, string $now, int $per_page, int $offset
    ): array {
        $bt = self::bookings_table();
        $ct = self::children_table();
        if ( ! self::table_exists( $bt ) ) return [];
        global $wpdb;

        [ $date_cond, $date_params ] = self::build_date_filter( $filter, $now );
        $order   = $filter === 'past' ? 'DESC' : 'ASC';
        $url_col = self::column_exists( 'meeting_url' ) ? ', b.meeting_url' : ", '' AS meeting_url";
        // v10.4.0 (Phase 3 — flow Coach) : attendance nécessaire pour
        // déterminer l'état d'éligibilité au feedback (present/late/absent)
        // directement dans la liste des séances, sans requête séparée par
        // ligne. Additif — colonne déjà utilisée ailleurs (Phase 1), aucun
        // appelant existant de find_paginated_for_coach() n'est cassé par
        // cet ajout de colonne au SELECT.
        $attn_col = self::column_exists( 'attendance' ) ? ', b.attendance' : ", '' AS attendance";
        $sel     = "SELECT b.id AS booking_id, b.child_id, b.appointment, b.session_name, b.status, c.child_name{$url_col}{$attn_col}
                      FROM {$bt} b
                 LEFT JOIN {$ct} c ON c.id = b.child_id";

        // Chemin 1 : colonne coach_id — obligatoire et exclusif dès qu'elle
        // existe (voir doc count_for_coach() ci-dessus).
        if ( self::column_exists( 'coach_id' ) ) {
            return $wpdb->get_results( $wpdb->prepare(
                "{$sel} WHERE b.coach_id = %d AND b.coach_id > 0{$date_cond} ORDER BY b.appointment {$order} LIMIT %d OFFSET %d",
                ...array_merge( [ $coach_id ], $date_params, [ $per_page, $offset ] )
            ) ) ?: [];
        }

        // Chemin 2 : child_ids — uniquement si coach_id n'existe pas.
        if ( ! empty( $child_ids ) ) {
            $ph   = implode( ',', array_fill( 0, count( $child_ids ), '%d' ) );
            $rows = $wpdb->get_results( $wpdb->prepare(
                "{$sel} WHERE b.child_id IN ({$ph}){$date_cond} ORDER BY b.appointment {$order} LIMIT %d OFFSET %d",
                ...array_merge( $child_ids, $date_params, [ $per_page, $offset ] )
            ) ) ?: [];
            if ( ! empty( $rows ) ) return $rows;
        }

        // Chemin 3 : appointment_type_id via SSA Staff map
        $type_ids = self::get_ssa_type_ids_for_coach( $coach_id );
        if ( ! empty( $type_ids ) ) {
            $ph = implode( ',', array_fill( 0, count( $type_ids ), '%d' ) );
            return $wpdb->get_results( $wpdb->prepare(
                "{$sel} WHERE b.appointment_type_id IN ({$ph}){$date_cond} ORDER BY b.appointment {$order} LIMIT %d OFFSET %d",
                ...array_merge( $type_ids, $date_params, [ $per_page, $offset ] )
            ) ) ?: [];
        }

        return [];
    }

    /** Retourne [condition_sql, params] pour le filtre de date. */
    private static function build_date_filter( string $filter, string $now ): array {
        if ( $filter === 'upcoming' ) return [ ' AND b.appointment > %s', [ $now ] ];
        if ( $filter === 'past' )     return [ ' AND b.appointment <= %s', [ $now ] ];
        return [ '', [] ];
    }

    /**
     * Retourne les données essentielles d'un booking (child_id, appointment_type_id, attendance).
     */
    public static function find_booking_basic( int $booking_id ): ?object {
        if ( $booking_id <= 0 ) return null;
        $bt = self::bookings_table();
        if ( ! self::table_exists( $bt ) ) return null;
        global $wpdb;
        // v9.35 — course_id ajouté : nécessaire pour résoudre les leçons
        // شارات المغامرات du cours déjà choisi lors du booking (le coach
        // ne choisit plus de cours, uniquement la leçon — décision du
        // 12/08/2026), consommé par get_pending_lessons().
        //
        // v10.3.0 — coach_id + appointment ajoutés : nécessaires à
        // RKP_AssessmentEligibilityService pour authentifier le coach
        // réellement propriétaire de CETTE séance (colonne garantie
        // présente depuis rk_mc_upgrade_bookings_coach_id(), cf.
        // rk-mc-db-helpers.php) plutôt que de se fier uniquement à la
        // relation générique coach↔enfant (rk_child_coaches), qui ne
        // distingue pas quel coach précis a donné CETTE séance quand un
        // enfant a plusieurs coachs.
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT id, child_id, coach_id, appointment, appointment_type_id, attendance, course_id
               FROM {$bt} WHERE id = %d LIMIT 1",
            $booking_id
        ) ) ?: null;
    }

    /**
     * Met à jour la colonne attendance pour un booking.
     */
    public static function mark_attendance( int $booking_id, string $attendance ): bool {
        if ( $booking_id <= 0 ) return false;
        $bt = self::bookings_table();
        if ( ! self::table_exists( $bt ) ) return false;
        global $wpdb;
        return $wpdb->update( $bt, [ 'attendance' => $attendance ], [ 'id' => $booking_id ], [ '%s' ], [ '%d' ] ) !== false;
    }

    /**
     * Prochain booking confirmé pour un enfant.
     *
     * @return array{date:string, time:string, program:string}|null
     */
    public static function find_next_booking_for_child( int $child_id ): ?array {
        if ( $child_id <= 0 ) return null;
        $bt = self::bookings_table();
        if ( ! self::table_exists( $bt ) ) return null;
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT DATE(appointment)          AS date,
                    LEFT(TIME(appointment), 5) AS time,
                    session_name               AS program
               FROM {$bt}
              WHERE child_id = %d
                AND appointment >= NOW()
                AND status IN ('confirmed','rescheduled','pending')
              ORDER BY appointment ASC
              LIMIT 1",
            $child_id
        ) );
        if ( ! $row ) return null;
        return [
            'date'    => (string) $row->date,
            'time'    => (string) $row->time,
            'program' => (string) ( $row->program ?? '' ),
        ];
    }

    /**
     * Détail des séances d'un enfant pour ce coach : upcoming (ASC) + past (DESC).
     * Applique un filtre coach_id si la colonne existe.
     *
     * @return array[]  [booking_id, date, start, program, attendance, status, is_upcoming]
     */
    public static function find_sessions_detail_for_child( int $child_id, int $coach_id ): array {
        if ( $child_id <= 0 ) return [];
        $bt = self::bookings_table();
        if ( ! self::table_exists( $bt ) ) return [];
        global $wpdb;
        $now = current_time( 'mysql' );

        $has_coach_col = self::column_exists( 'coach_id' );
        $coach_filter  = $has_coach_col ? $wpdb->prepare( 'AND b.coach_id = %d', $coach_id ) : '';

        $upcoming = $wpdb->get_results( $wpdb->prepare(
            "SELECT b.id                           AS booking_id,
                    DATE(b.appointment)            AS date,
                    LEFT(TIME(b.appointment), 5)   AS start,
                    COALESCE(b.session_name, '')   AS program,
                    COALESCE(b.attendance,'none')  AS attendance,
                    b.status,
                    1                              AS is_upcoming
               FROM {$bt} b
              WHERE b.child_id = %d
                AND b.appointment >= %s
                AND b.status NOT IN ('cancelled')
                {$coach_filter}
              ORDER BY b.appointment ASC
              LIMIT 15",
            $child_id, $now
        ) ) ?: [];

        $past = $wpdb->get_results( $wpdb->prepare(
            "SELECT b.id                           AS booking_id,
                    DATE(b.appointment)            AS date,
                    LEFT(TIME(b.appointment), 5)   AS start,
                    COALESCE(b.session_name, '')   AS program,
                    COALESCE(b.attendance,'none')  AS attendance,
                    b.status,
                    0                              AS is_upcoming
               FROM {$bt} b
              WHERE b.child_id = %d
                AND b.appointment < %s
                {$coach_filter}
              ORDER BY b.appointment DESC
              LIMIT 35",
            $child_id, $now
        ) ) ?: [];

        return array_merge(
            array_map( 'get_object_vars', $upcoming ),
            array_map( 'get_object_vars', $past )
        );
    }

}