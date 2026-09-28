<?php
declare( strict_types=1 );
/**
 * Application — BookingQueryService  (Read Model)
 *
 * Répond à : "Quand et avec qui a lieu la prochaine séance ?"
 *
 * RÈGLE : aucun appel direct à WP functions ou $wpdb.
 *         Tout passe par RKP_BookingRepository.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class RKP_BookingQueryService {

    /** @return RKP_Booking[] — prochaines séances d'un enfant pour un cours */
    public static function get_for_course( int $child_id, int $course_id, int $limit = 5 ): array {
        return RKP_BookingRepository::find_upcoming_by_child( $child_id, $course_id, $limit );
    }

    /**
     * Toutes les séances d'un enfant, tous cours confondus — page لقاءاتي.
     *
     * @param string $when 'upcoming' | 'past' | 'all'
     * @return RKP_Booking[]
     */
    public static function get_all_for_child( int $child_id, string $when = 'all' ): array {
        return RKP_BookingRepository::find_all_by_child( $child_id, $when );
    }

    /** @return RKP_Booking[] — séances d'aujourd'hui pour un coach */
    public static function get_today_for_coach( int $coach_id ): array {
        return RKP_BookingRepository::find_today_for_coach( $coach_id );
    }

    /** @return RKP_Booking[] — séances de la semaine pour un coach */
    public static function get_week_for_coach( int $coach_id ): array {
        return RKP_BookingRepository::find_week_for_coach( $coach_id );
    }
}
