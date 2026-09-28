<?php
declare( strict_types=1 );
/**
 * Application — CoachMissionQueryService  (Read Model)
 *
 * Données lecture pour les missions assignées par le coach (wp_rk_coach_missions).
 * Distinctes des missions hebdomadaires auto (wp_rk_child_missions).
 *
 * RÈGLE : aucun appel direct à $wpdb.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class RKP_CoachMissionQueryService {

    /** Missions actives (non terminées) du coach, avec nom enfant. */
    public static function get_active_missions( int $coach_id ): array {
        return RKP_CoachMissionRepository::find_active_for_coach( $coach_id );
    }

    /** Toutes les missions du coach (actives + terminées), avec nom enfant. */
    public static function get_all_missions( int $coach_id, int $limit = 50 ): array {
        return RKP_CoachMissionRepository::find_all_for_coach( $coach_id, $limit );
    }

    /** Missions actives pour un enfant (tous coaches). Format ARRAY_A. */
    public static function get_missions_for_child( int $child_id ): array {
        return RKP_CoachMissionRepository::find_for_child( $child_id );
    }

    /** Missions (actives + terminées) d'un enfant pour un coach précis. */
    public static function get_missions_for_child_by_coach( int $child_id, int $coach_id, int $limit = 30 ): array {
        return RKP_CoachMissionRepository::find_for_child_by_coach( $child_id, $coach_id, $limit );
    }
}
