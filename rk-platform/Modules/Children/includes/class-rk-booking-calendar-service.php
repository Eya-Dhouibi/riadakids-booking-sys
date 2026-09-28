<?php
declare( strict_types=1 );
/**
 * RK_Booking_Calendar_Service  (v1.0.0)
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * SOURCE DE VÉRITÉ UNIQUE pour les données de planning (séances).
 *
 * Lit UNIQUEMENT la table wp_rk_bookings (nouveau système).
 * Supprime toute dépendance au CPT rk_booking et ses postmeta.
 *
 * LE BOOKING = uniquement : date + heure + coach + child + statut.
 * Le contenu pédagogique est toujours récupéré via RK_Tutor_Course_Service.
 *
 * REMPLACE
 *   - class-rk-mc-child-dashboard-service.php :: get_upcoming_session()
 *     (qui lisait le CPT rk_booking via postmeta LIKE — coûteux et obsolète)
 *   - class-rk-mc-child-dashboard-data.php :: fetch_upcoming_sessions()
 *     (idem CPT)
 *
 * FORMAT DE RETOUR
 *   get_upcoming() retourne un tableau de Session Objects :
 *   [
 *     'appointment'   => string   DATETIME 'Y-m-d H:i:s'
 *     'session_name'  => string   nom ACF de la séance (ex: "الاثنين 15h-17h")
 *     'coach_name'    => string   nom du coach (wp_display_name)
 *     'coach_id'      => int      wp_user_id du coach
 *     'course_id'     => int      Tutor LMS course post ID
 *     'course_title'  => string   titre du cours Tutor
 *     'course_url'    => string   permalink du cours
 *     'status'        => string   'confirmed'|'rescheduled'
 *     'ssa_link'      => string   lien SSA meeting
 *   ]
 *
 * @package RK_My_Children
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class RK_Booking_Calendar_Service {

    /* ═══════════════════════════════════════════════════════════════════
       PROCHAINES SÉANCES — usage courant (child dashboard)
       ═══════════════════════════════════════════════════════════════════ */

    /**
     * Retourne les $limit prochaines séances confirmées d'un enfant.
     *
     * @param  int  $child_id   ID dans wp_rk_children (interne RK)
     * @param  int  $limit
     * @return array
     */
    public static function get_upcoming( int $child_id, int $limit = 5 ): array {
        if ( $child_id <= 0 ) return [];

        $rows = self::query_bookings( $child_id, 'upcoming', $limit );
        return self::hydrate( $rows );
    }

    /**
     * Retourne uniquement la prochaine séance (pour le widget "Prochaine séance").
     *
     * @param  int        $child_id
     * @return array|null Session hydratée ou null.
     */
    public static function get_next( int $child_id ): ?array {
        $sessions = self::get_upcoming( $child_id, 1 );
        return $sessions[0] ?? null;
    }

    /**
     * Retourne les séances liées à un cours spécifique pour un enfant.
     * Utile pour enrichir la carte de cours avec la prochaine séance associée.
     *
     * @param  int  $child_id
     * @param  int  $course_id  Tutor LMS course post ID
     * @param  int  $limit
     * @return array
     */
    public static function get_for_course( int $child_id, int $course_id, int $limit = 3 ): array {
        if ( $child_id <= 0 || $course_id <= 0 ) return [];

        global $wpdb;
        $bt = $wpdb->prefix . 'rk_bookings';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $bt ) ) !== $bt ) return [];

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$bt}
              WHERE child_id  = %d
                AND course_id = %d
                AND status    IN ('confirmed','rescheduled')
                AND appointment > NOW()
              ORDER BY appointment ASC
              LIMIT %d",
            $child_id, $course_id, $limit
        ) ) ?: [];

        return self::hydrate( $rows );
    }

    /**
     * Retourne toutes les séances passées d'un enfant (pour l'historique).
     *
     * @param  int  $child_id
     * @param  int  $limit
     * @return array
     */
    public static function get_past( int $child_id, int $limit = 20 ): array {
        if ( $child_id <= 0 ) return [];

        $rows = self::query_bookings( $child_id, 'past', $limit );
        return self::hydrate( $rows );
    }

    /**
     * Alias compatible avec l'ancienne signature de get_upcoming_session()
     * (remplace le CPT-based query de class-rk-mc-child-dashboard-service.php).
     *
     * @param  int        $child_id
     * @return object|null  stdClass avec appointment, session_name, coach_name
     */
    public static function get_next_as_object( int $child_id ): ?object {
        $session = self::get_next( $child_id );
        if ( ! $session ) return null;

        return (object) [
            'appointment'  => $session['appointment'],
            'session_name' => $session['session_name'],
            'coach_name'   => $session['coach_name'],
        ];
    }

    /* ═══════════════════════════════════════════════════════════════════
       STATS GLOBALES planning pour un enfant
       ═══════════════════════════════════════════════════════════════════ */

    /**
     * Compte le nombre de séances passées et à venir pour un enfant.
     *
     * @return array ['total','past','upcoming']
     */
    public static function get_counts( int $child_id ): array {
        if ( $child_id <= 0 ) return [ 'total' => 0, 'past' => 0, 'upcoming' => 0 ];

        global $wpdb;
        $bt = $wpdb->prefix . 'rk_bookings';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $bt ) ) !== $bt ) {
            return [ 'total' => 0, 'past' => 0, 'upcoming' => 0 ];
        }

        $upcoming = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$bt}
              WHERE child_id = %d AND status IN ('confirmed','rescheduled') AND appointment > NOW()",
            $child_id
        ) );

        $past = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$bt}
              WHERE child_id = %d AND status IN ('confirmed','rescheduled') AND appointment <= NOW()",
            $child_id
        ) );

        return [
            'total'    => $upcoming + $past,
            'past'     => $past,
            'upcoming' => $upcoming,
        ];
    }

    /* ═══════════════════════════════════════════════════════════════════
       HELPERS PRIVÉS
       ═══════════════════════════════════════════════════════════════════ */

    /**
     * Query de base sur wp_rk_bookings.
     *
     * @param  string  $direction  'upcoming' | 'past'
     */
    private static function query_bookings( int $child_id, string $direction, int $limit ): array {
        global $wpdb;
        $bt = $wpdb->prefix . 'rk_bookings';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $bt ) ) !== $bt ) return [];

        $compare = $direction === 'past' ? '<= NOW()' : '> NOW()';
        $order   = $direction === 'past' ? 'DESC' : 'ASC';

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$bt}
              WHERE child_id = %d
                AND status   IN ('confirmed','rescheduled')
                AND appointment {$compare}
              ORDER BY appointment {$order}
              LIMIT %d",
            $child_id,
            $limit
        ) ) ?: [];
    }

    /**
     * Enrichit les lignes brutes wp_rk_bookings avec les données du cours Tutor LMS.
     *
     * @param  array  $rows  Résultats MySQL (stdClass[])
     * @return array
     */
    private static function hydrate( array $rows ): array {
        $out = [];
        foreach ( $rows as $row ) {
            $course_id    = (int) ( $row->course_id ?? 0 );
            $course_title = $course_id > 0 ? ( get_the_title( $course_id ) ?: '' ) : '';
            $course_url   = $course_id > 0 ? ( (string) get_permalink( $course_id ) ) : '';

            // Nom du coach : priorité coach_id (colonne), fallback coach (varchar)
            $coach_name = (string) ( $row->coach ?? '' );
            $coach_id   = (int) ( $row->coach_id ?? 0 );
            if ( $coach_id > 0 ) {
                $coach_user = get_userdata( $coach_id );
                if ( $coach_user ) {
                    $coach_name = $coach_user->display_name;
                }
            }

            $out[] = [
                'appointment'  => (string) ( $row->appointment ?? '' ),
                'session_name' => (string) ( $row->session_name ?? '' ),
                'coach_name'   => $coach_name,
                'coach_id'     => $coach_id,
                'course_id'    => $course_id,
                'course_title' => $course_title,
                'course_url'   => $course_url,
                'status'       => (string) ( $row->status ?? '' ),
                'ssa_link'     => (string) ( $row->ssa_link ?? '' ),
            ];
        }
        return $out;
    }
}
