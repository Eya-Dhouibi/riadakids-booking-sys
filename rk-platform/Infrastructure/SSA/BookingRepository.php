<?php
declare( strict_types=1 );
/**
 * Infrastructure — BookingRepository  (Simply Schedule Appointments / wp_rk_bookings)
 *
 * SEULE couche autorisée à requêter la table custom wp_rk_bookings.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class RKP_BookingRepository {

    private static function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'rk_bookings';
    }

    /** @return RKP_Booking[] — séances d'un enfant pour un cours, dans le futur */
    public static function find_upcoming_by_child( int $child_id, int $course_id, int $limit = 5 ): array {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM " . self::table() . "
              WHERE child_id  = %d
                AND course_id = %d
                AND appointment  > NOW()
              ORDER BY appointment ASC
              LIMIT %d",
            $child_id, $course_id, $limit
        ) );
        return array_filter( array_map( [ self::class, 'hydrate' ], $rows ) );
    }

    /** @return RKP_Booking[] — séances d'un coach pour une plage de dates */
    public static function find_by_coach_and_range( int $coach_id, string $from, string $to ): array {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM " . self::table() . "
              WHERE coach_id = %d
                AND appointment BETWEEN %s AND %s
              ORDER BY appointment ASC",
            $coach_id, $from, $to
        ) );
        return array_filter( array_map( [ self::class, 'hydrate' ], $rows ) );
    }

    /** @return RKP_Booking[] — séances d'aujourd'hui pour un coach */
    public static function find_today_for_coach( int $coach_id ): array {
        return self::find_by_coach_and_range(
            $coach_id,
            gmdate( 'Y-m-d 00:00:00' ),
            gmdate( 'Y-m-d 23:59:59' )
        );
    }

    /** @return RKP_Booking[] — séances de la semaine courante pour un coach */
    public static function find_week_for_coach( int $coach_id ): array {
        return self::find_by_coach_and_range(
            $coach_id,
            gmdate( 'Y-m-d 00:00:00', strtotime( 'monday this week' ) ),
            gmdate( 'Y-m-d 23:59:59', strtotime( 'sunday this week' ) )
        );
    }

    public static function find( int $booking_id ): ?RKP_Booking {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM " . self::table() . " WHERE id = %d",
            $booking_id
        ) );
        if ( ! $row ) return null;
        return self::hydrate( $row );
    }

    /**
     * Toutes les séances d'un enfant (tous cours confondus), filtrables
     * à venir/passées. Utilisée par la page "لقاءاتي" (rk-sessions.php).
     *
     * @param string $when 'upcoming' | 'past' | 'all'
     * @return RKP_Booking[]
     */
    public static function find_all_by_child( int $child_id, string $when = 'all' ): array {
        if ( $child_id <= 0 ) return [];
        global $wpdb;

        $where = 'child_id = %d';
        $args  = [ $child_id ];
        $order = 'ASC';

        if ( 'upcoming' === $when ) {
            $where .= " AND appointment > NOW() AND status NOT IN ('cancelled')";
        } elseif ( 'past' === $when ) {
            $where .= " AND appointment <= NOW()";
            $order  = 'DESC'; // la séance passée la plus récente en premier, cohérent avec le mockup
        }

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM " . self::table() . " WHERE {$where} ORDER BY appointment {$order}", // phpcs:ignore WordPress.DB.PreparedSQL -- $order est un littéral fixe ('ASC'/'DESC'), jamais une entrée utilisateur.
            ...$args
        ) );
        return array_filter( array_map( [ self::class, 'hydrate' ], $rows ) );
    }

    /** Prochaine séance confirmée d'un enfant (ou null). */
    public static function get_next_confirmed( int $child_id ): ?object {
        if ( $child_id <= 0 ) return null;
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM " . self::table() . "
              WHERE child_id = %d AND status = 'confirmed' AND appointment > NOW()
             ORDER BY appointment ASC LIMIT 1",
            $child_id
        ) ) ?: null;
    }

    /** Séance du jour d'un enfant (statut confirmed ou pending). */
    public static function get_today_session( int $child_id ): ?object {
        if ( $child_id <= 0 ) return null;
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM " . self::table() . "
              WHERE child_id = %d AND DATE(appointment) = CURDATE()
                AND status IN ('confirmed','pending') LIMIT 1",
            $child_id
        ) ) ?: null;
    }

    /**
     * appointment_type_id du dernier booking d'un enfant.
     * Utilisé pour résoudre le coach via COACH_MAP_OPTION.
     *
     * @param string $status  Filtre statut ; '' = tous les statuts.
     */
    public static function get_last_appointment_type_id( int $child_id, string $status = '' ): int {
        if ( $child_id <= 0 ) return 0;
        global $wpdb;
        $sql  = "SELECT appointment_type_id FROM " . self::table() . " WHERE child_id = %d AND appointment_type_id > 0";
        $args = [ $child_id ];
        if ( $status !== '' ) { $sql .= " AND status = %s"; $args[] = $status; }
        $sql .= " ORDER BY appointment DESC LIMIT 1";
        return (int) $wpdb->get_var( $wpdb->prepare( $sql, ...$args ) );
    }

    /** appointment_type_id d'un booking SSA (par SSA booking_id). */
    public static function get_appointment_type_for_ssa_booking( int $ssa_booking_id ): int {
        if ( $ssa_booking_id <= 0 ) return 0;
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            'SELECT appointment_type_id FROM ' . self::table() . ' WHERE booking_id = %d LIMIT 1',
            $ssa_booking_id
        ) );
    }

    /** Stampe le coach_id sur un booking SSA si la colonne existe et si coach_id = 0. */
    public static function set_coach_id_for_ssa_booking( int $ssa_booking_id, int $coach_id ): void {
        if ( $ssa_booking_id <= 0 || $coach_id <= 0 ) return;
        global $wpdb;
        $wpdb->query( $wpdb->prepare(
            'UPDATE ' . self::table() . ' SET coach_id = %d WHERE booking_id = %d AND coach_id = 0',
            $coach_id, $ssa_booking_id
        ) );
    }

    /** Nombre de bookings non-annulés d'un enfant. */
    public static function count_non_cancelled( int $child_id ): int {
        if ( $child_id <= 0 ) return 0;
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM " . self::table() . " WHERE child_id = %d AND status NOT IN ('cancelled')",
            $child_id
        ) );
    }

    /**
     * Bookings confirmés/complétés avec course_id valide.
     * Utilisé par le sync rétroactif d'inscription Tutor LMS.
     *
     * @return object[]  { course_id, child_id }
     */
    public static function get_confirmed_with_course(): array {
        global $wpdb;
        return $wpdb->get_results(
            "SELECT DISTINCT course_id, child_id FROM " . self::table() . "
              WHERE status IN ('confirmed','completed') AND course_id > 0 AND child_id > 0"
        ) ?: [];
    }

    /**
     * Bookings confirmés/complétés avec course_id valide, pour UN enfant.
     * Utilisé pour la sync ciblée (affichage fiche enfant) — évite de
     * parcourir toute la table comme get_confirmed_with_course().
     *
     * @param  int $child_id
     * @return object[]  { course_id, child_id }
     */
    public static function get_confirmed_with_course_for_child( int $child_id ): array {
        if ( $child_id <= 0 ) return [];
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT DISTINCT course_id, child_id FROM " . self::table() . "
              WHERE status IN ('confirmed','completed') AND course_id > 0 AND child_id = %d",
            $child_id
        ) ) ?: [];
    }

    /**
     * Prochain booking confirmé par lot d'enfants, avec nom du coach via JOIN wp_users.
     *
     * @param  int[]  $child_ids  rk_children.id
     * @return array<int, object>  [child_id => {child_id, appointment, session_name, coach_name}]
     */
    public static function get_next_confirmed_batch( array $child_ids ): array {
        if ( empty( $child_ids ) ) return [];
        global $wpdb;
        $ph   = implode( ',', array_fill( 0, count( $child_ids ), '%d' ) );
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT b.child_id, b.appointment, b.session_name, u.display_name AS coach_name
               FROM " . self::table() . " b
               LEFT JOIN {$wpdb->users} u ON u.ID = b.coach_id
              WHERE b.child_id IN ({$ph}) AND b.status = 'confirmed' AND b.appointment > NOW()
             ORDER BY b.child_id ASC, b.appointment ASC",
            ...$child_ids
        ) ) ?: [];
        $map = [];
        foreach ( $rows as $r ) {
            $cid = (int) $r->child_id;
            if ( ! isset( $map[ $cid ] ) ) $map[ $cid ] = $r;
        }
        return $map;
    }

    /**
     * Compte les bookings d'un user_id (parent WP) filtrés par statuts.
     *
     * @param string[] $statuses
     */
    public static function count_by_user_id( int $user_id, array $statuses ): int {
        if ( $user_id <= 0 || empty( $statuses ) ) return 0;
        global $wpdb;
        $ph = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM " . self::table() . " WHERE user_id = %d AND status IN ({$ph})",
            array_merge( [ $user_id ], $statuses )
        ) );
    }

    /** Vérifie si une colonne existe dans rk_bookings (cache statique). */
    public static function has_column( string $col ): bool {
        static $cache = [];
        if ( ! isset( $cache[ $col ] ) ) {
            global $wpdb;
            $cache[ $col ] = (bool) $wpdb->get_var(
                $wpdb->prepare( 'SHOW COLUMNS FROM ' . self::table() . ' LIKE %s', $col )
            );
        }
        return $cache[ $col ];
    }

    private static function hydrate( object $r ): ?RKP_Booking {
        // v2.7 — corrigé : la colonne réelle de wp_rk_bookings est
        // 'appointment' (confirmée par rk-mc-db-helpers.php et toutes les
        // autres méthodes de ce fichier), pas 'start_at'. Cette dernière
        // n'a jamais existé : hydrate() retournait donc systématiquement
        // null pour chaque ligne (booking_id ignoré, résultat toujours
        // vide) — bug préexistant, découvert en construisant rk-sessions.php.
        $ts = ! empty( $r->appointment ) ? strtotime( $r->appointment ) : 0;
        if ( ! $ts ) return null;
        return new RKP_Booking(
            id:           (int) $r->id,
            booking_id:   (int) ( $r->booking_id ?? 0 ),
            child_id:     (int) $r->child_id,
            coach_id:     (int) $r->coach_id,
            course_id:    (int) $r->course_id,
            session_name: (string) ( $r->session_name ?? '' ),
            start_at:     new \DateTimeImmutable( '@' . $ts ),
            meeting_url:  (string) ( $r->meeting_url  ?? '' ),
            status:       (string) ( $r->status        ?? 'booked' ),
        );
    }
}
