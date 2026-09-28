<?php
declare( strict_types=1 );
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Extrait de CoachSessionRepository.php — factorisation par fonctionnalité.
 * Code déplacé verbatim, aucune modification de logique.
 */
trait RKP_Coach_Session_Repo_Queries {
    /**
     * IDs SSA appointment_type associés au coach.
     * Priorité 1 : option manuelle rk_ssa_coach_map.
     * Priorité 2 : tables SSA Staff (wp_ssa_staff / wp_ssa_staff_appointment_types).
     * Le résultat de P2 est mis en cache dans la map pour les appels suivants.
     */
    public static function get_ssa_type_ids_for_coach( int $coach_id ): array {
        $map    = get_option( 'rk_ssa_coach_map', [] );
        $result = [];
        foreach ( $map as $ssa_id => $uid ) {
            if ( (int) $uid === $coach_id ) $result[] = (int) $ssa_id;
        }
        if ( ! empty( $result ) ) return $result;

        $result = self::discover_ssa_type_ids_for_coach( $coach_id );
        if ( ! empty( $result ) ) {
            foreach ( $result as $ssa_id ) {
                $map[ $ssa_id ] = $coach_id;
            }
            update_option( 'rk_ssa_coach_map', $map, false );
        }
        return $result;
    }

    /**
     * Découverte des appointment_type_ids pour un coach via les tables SSA Staff premium.
     */
    private static function discover_ssa_type_ids_for_coach( int $coach_id ): array {
        global $wpdb;

        $sat   = $wpdb->prefix . 'ssa_staff_appointment_types';
        $staff = $wpdb->prefix . 'ssa_staff';
        if (
            $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $sat ) ) !== $sat ||
            $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $staff ) ) !== $staff
        ) {
            return [];
        }

        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT sat.appointment_type_id
               FROM {$sat} sat
               JOIN {$staff} s ON s.id = sat.staff_id
              WHERE s.user_id = %d",
            $coach_id
        ) );

        return array_map( 'intval', $ids ?: [] );
    }

    /**
     * Séances entre $start et $end pour un coach.
     * Combine les 3 chemins en une seule requête OR pour éviter les early-returns qui
     * masquent des bookings avec appointment_type_id manquant ou coach_id non rempli.
     *
     * @return array[]  Objets convertis en tableaux associatifs.
     */
    public static function find_by_range( int $coach_id, string $start, string $end ): array {
        $bt = self::bookings_table();
        $ct = self::children_table();
        if ( ! self::table_exists( $bt ) ) return [];
        global $wpdb;

        $or_conds  = [];
        $or_params = [];

        // Chemin 1 : appointment_type_id lié au coach via SSA Staff / map
        $ssa_ids = self::get_ssa_type_ids_for_coach( $coach_id );
        if ( ! empty( $ssa_ids ) ) {
            $ph          = implode( ',', array_fill( 0, count( $ssa_ids ), '%d' ) );
            $or_conds[]  = "b.appointment_type_id IN ({$ph})";
            $or_params   = array_merge( $or_params, $ssa_ids );
        }

        // Chemin 2 : enfants assignés dans wp_rk_child_coaches
        $cc = self::coach_map_table();
        if ( self::table_exists( $cc ) ) {
            $or_conds[]  = "b.child_id IN (SELECT child_id FROM {$cc} WHERE coach_id = %d)";
            $or_params[] = $coach_id;
        }

        // Chemin 3 : colonne coach_id dans wp_rk_bookings
        if ( self::column_exists( 'coach_id' ) ) {
            $or_conds[]  = '(b.coach_id = %d AND b.coach_id > 0)';
            $or_params[] = $coach_id;
        }

        if ( empty( $or_conds ) ) return [];

        $ownership = '(' . implode( ' OR ', $or_conds ) . ')';
        $url_col   = self::column_exists( 'meeting_url' ) ? ', b.meeting_url' : ", '' AS meeting_url";
        $att_col   = self::column_exists( 'attendance' )  ? ', b.attendance'  : ", '' AS attendance";
        // v9.23 — child_family_name (nom de famille) affiché à côté du
        // prénom côté SPA coach. Garde défensive comme meeting_url/
        // attendance ci-dessus : colonne absente sur une install jamais
        // migrée (voir rk-mc-db-helpers.php, ADD COLUMN v6.1.0).
        $family_col = self::children_column_exists( 'child_family_name' )
            ? ', c.child_family_name'
            : ", '' AS child_family_name";
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT b.id AS booking_id, b.child_id, b.appointment AS start,
                    b.session_name AS program_name, c.child_name{$family_col},
                    b.course_id, b.program_id, b.coach, b.end_at, b.status{$url_col}{$att_col}
               FROM {$bt} b
          LEFT JOIN {$ct} c ON c.id = b.child_id
              WHERE {$ownership}
                AND b.appointment BETWEEN %s AND %s
                AND b.status IN ('confirmed','rescheduled','booked')
           ORDER BY b.appointment ASC",
            ...array_merge( $or_params, [ $start, $end ] )
        ) ) ?: [];

        // Dédoublonner par booking_id (plusieurs chemins peuvent ramener le même booking)
        $seen   = [];
        $result = [];
        foreach ( $rows as $row ) {
            if ( ! isset( $seen[ $row->booking_id ] ) ) {
                $seen[ $row->booking_id ] = true;
                $result[] = get_object_vars( $row );
            }
        }
        return $result;
    }

    /**
     * Retourne les child_id distincts ayant au moins un booking pour ce coach (via colonne coach_id).
     *
     * @return int[]
     */
    public static function find_child_ids_by_coach_id( int $coach_id ): array {
        if ( $coach_id <= 0 ) return [];
        $bt = self::bookings_table();
        if ( ! self::table_exists( $bt ) || ! self::column_exists( 'coach_id' ) ) return [];
        global $wpdb;
        $rows = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT child_id FROM {$bt} WHERE coach_id = %d AND coach_id > 0",
            $coach_id
        ) );
        return array_map( 'intval', $rows ?: [] );
    }

    /**
     * Nombre de students distincts ayant au moins une séance confirmée avec ce coach.
     * Utilise les 3 chemins d'identification (SSA, coach_id, child_coaches fallback).
     */
    public static function count_distinct_students( int $coach_id ): int {
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

        if ( self::column_exists( 'coach_id' ) ) {
            $or_conds[]  = '(coach_id = %d AND coach_id > 0)';
            $or_params[] = $coach_id;
        }

        if ( ! empty( $or_conds ) ) {
            $ownership = '(' . implode( ' OR ', $or_conds ) . ')';
            return (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(DISTINCT child_id) FROM {$bt}
                  WHERE {$ownership} AND status = 'confirmed'",
                ...$or_params
            ) );
        }

        // Fallback : table des affectations enfant-coach
        $cc = self::coach_map_table();
        if ( self::table_exists( $cc ) ) {
            return (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$cc} WHERE coach_id = %d",
                $coach_id
            ) );
        }
        return 0;
    }

    /**
     * Séances d'un enfant (toutes, sans filtre coach), triées DESC.
     *
     * @return array[]  [booking_id, start, program_name, status]
     */
    public static function find_for_child( int $child_rk_id, int $limit = 10 ): array {
        $bt = self::bookings_table();
        if ( ! self::table_exists( $bt ) ) return [];
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id AS booking_id, appointment AS start, session_name AS program_name, status
               FROM {$bt}
              WHERE child_id = %d ORDER BY appointment DESC LIMIT %d",
            $child_rk_id, $limit
        ) );
        return array_map( 'get_object_vars', $rows ?: [] );
    }

    /**
     * Séances d'un enfant vérifiées comme appartenant au coach (via liste élèves).
     *
     * @return array[]  [booking_id, start, program_name, status]
     */
    public static function find_for_child_owned_by_coach( int $child_rk_id, int $coach_id, int $limit = 20 ): array {
        if ( ! RKP_CoachStudentRepository::coach_owns_child( $coach_id, $child_rk_id ) ) return [];
        $bt = self::bookings_table();
        if ( ! self::table_exists( $bt ) ) return [];
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id AS booking_id, appointment AS start, session_name AS program_name, status
               FROM {$bt}
              WHERE child_id = %d ORDER BY appointment DESC LIMIT %d",
            $child_rk_id, $limit
        ) );
        return array_map( 'get_object_vars', $rows ?: [] );
    }

    /**
     * Batch : prochain booking pour chaque enfant d'une liste.
     * Retourne une map child_rk_id → booking stdClass.
     *
     * @param  int[]  $child_rk_ids
     * @return array<int, object>
     */
    public static function find_children_next_booking( array $child_rk_ids ): array {
        if ( empty( $child_rk_ids ) ) return [];
        $bt = self::bookings_table();
        if ( ! self::table_exists( $bt ) ) return [];
        global $wpdb;
        $ph      = implode( ',', array_fill( 0, count( $child_rk_ids ), '%d' ) );
        $bookings = $wpdb->get_results( $wpdb->prepare(
            "SELECT b.child_id, b.session_name, b.appointment, b.end_at, b.coach, b.status, b.course_id, b.program_id
               FROM {$bt} b
         INNER JOIN (
             SELECT child_id, MIN(appointment) AS min_appt
               FROM {$bt}
              WHERE child_id IN ({$ph})
                AND appointment > NOW()
                AND status IN ('confirmed','rescheduled')
              GROUP BY child_id
         ) nxt ON nxt.child_id = b.child_id AND nxt.min_appt = b.appointment
          ORDER BY b.appointment ASC",
            ...$child_rk_ids
        ) ) ?: [];
        $map = [];
        foreach ( $bookings as $bk ) {
            $map[ (int) $bk->child_id ] = $bk;
        }
        return $map;
    }

    /**
     * v9.28 — Factorise la clause d'ownership 3-chemins (SSA / wp_rk_child_coaches
     * / colonne coach_id) utilisée par find_by_range() ET désormais par
     * get_child_attendance_rate() / get_consecutive_absences(). Avant
     * cette extraction, get_child_attendance_rate() n'utilisait QUE le
     * chemin SSA et retournait 0 sans même chercher si le coach n'avait
     * aucun appointment_type_id mappé — alors qu'une séance pouvait très
     * bien être rattachée via wp_rk_child_coaches ou coach_id direct
     * (les 2 autres chemins), et donc bel et bien marquée "présent".
     *
     * @return array{0: string, 1: array}  [clause SQL "(...)", params]
     */
    private static function ownership_clause( int $coach_id ): array {
        $or_conds  = [];
        $or_params = [];

        $ssa_ids = self::get_ssa_type_ids_for_coach( $coach_id );
        if ( ! empty( $ssa_ids ) ) {
            $ph         = implode( ',', array_fill( 0, count( $ssa_ids ), '%d' ) );
            $or_conds[] = "appointment_type_id IN ({$ph})";
            $or_params  = array_merge( $or_params, $ssa_ids );
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

        if ( empty( $or_conds ) ) return [ '', [] ];
        return [ '(' . implode( ' OR ', $or_conds ) . ')', $or_params ];
    }

    /**
     * Taux de présence d'un enfant pour ce coach, calculé sur les
     * séances DONT LA PRÉSENCE A ÉTÉ POINTÉE (attendance renseignée),
     * pas sur "toutes les séances passées".
     * Retourne 0 si la colonne `attendance` n'existe pas.
     *
     * v9.28 — status IN ('confirmed','rescheduled','booked') pour rester
     * cohérent avec find_by_range() (qui alimente #sessions, où le coach
     * marque la présence) : une séance 'booked' ou 'rescheduled' pointée
     * présente doit compter dans le taux, pas seulement 'confirmed'.
     *
     * v9.29 — retrait du filtre `appointment < NOW()` : un coach peut
     * pointer une séance "مكتملة" avant l'heure prévue (séance en
     * cours, anticipée, ou horloge serveur légèrement décalée) — ce
     * filtre excluait alors cette présence bien réelle du calcul,
     * affichant 0% malgré un pointage explicite. Le dénominateur
     * correct est "séances dont la présence a été enregistrée"
     * (attendance IN ('present','absent')), pas "séances passées".
     */
    public static function get_child_attendance_rate( int $child_rk_id, int $coach_id ): int {
        if ( ! self::column_exists( 'attendance' ) ) return 0;
        global $wpdb;
        [ $ownership, $or_params ] = self::ownership_clause( $coach_id );
        if ( '' === $ownership ) return 0;
        $bt = self::bookings_table();
        $total = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$bt}
              WHERE child_id = %d AND {$ownership}
                AND status IN ('confirmed','rescheduled','booked')
                AND attendance IN ('present','absent')",
            array_merge( [ $child_rk_id ], $or_params )
        ) );
        if ( $total === 0 ) return 0;
        $present = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$bt}
              WHERE child_id = %d AND {$ownership}
                AND status IN ('confirmed','rescheduled','booked') AND attendance = 'present'",
            array_merge( [ $child_rk_id ], $or_params )
        ) );
        return (int) round( $present / $total * 100 );
    }

    /**
     * Nombre d'absences consécutives d'un enfant (séances les plus récentes d'abord).
     */
    public static function get_consecutive_absences( int $child_rk_id ): int {
        if ( ! self::column_exists( 'attendance' ) ) return 0;
        global $wpdb;
        $bt   = self::bookings_table();
        $rows = $wpdb->get_col( $wpdb->prepare(
            "SELECT attendance FROM {$bt}
              WHERE child_id = %d AND appointment < NOW() AND status = 'confirmed'
                AND attendance IN ('present','absent')
           ORDER BY appointment DESC LIMIT 10",
            $child_rk_id
        ) ) ?: [];
        $streak = 0;
        foreach ( $rows as $att ) {
            if ( $att === 'absent' ) { $streak++; } else { break; }
        }
        return $streak;
    }

    /**
     * child_ids (rk_children.id) ayant eu des séances passées dans les $days derniers jours
     * pour ce coach.
     *
     * @return int[]
     */
    public static function find_children_with_past_sessions( int $coach_id, int $days = 30 ): array {
        global $wpdb;
        $bt      = self::bookings_table();
        $ssa_ids = self::get_ssa_type_ids_for_coach( $coach_id );

        if ( ! empty( $ssa_ids ) ) {
            $ph = implode( ',', array_fill( 0, count( $ssa_ids ), '%d' ) );
            return array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
                "SELECT b.child_id FROM {$bt} b
                  WHERE b.appointment_type_id IN ({$ph})
                    AND b.appointment < NOW()
                    AND b.appointment >= DATE_SUB(NOW(), INTERVAL %d DAY)
                    AND b.status = 'confirmed'",
                ...array_merge( $ssa_ids, [ $days ] )
            ) ) ?: [] );
        }

        // Fallback : enfants du coach via child_coaches
        $cc = self::coach_map_table();
        if ( ! self::table_exists( $cc ) || ! self::table_exists( $bt ) ) return [];
        $child_ids = array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
            "SELECT child_id FROM {$cc} WHERE coach_id = %d", $coach_id
        ) ) ?: [] );
        if ( empty( $child_ids ) ) return [];
        $ph2 = implode( ',', array_fill( 0, count( $child_ids ), '%d' ) );
        return array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT b.child_id FROM {$bt} b
              WHERE b.child_id IN ({$ph2})
                AND b.appointment < NOW()
                AND b.appointment >= DATE_SUB(NOW(), INTERVAL %d DAY)
                AND b.status IN ('confirmed','rescheduled')",
            ...array_merge( $child_ids, [ $days ] )
        ) ) ?: [] );
    }

}