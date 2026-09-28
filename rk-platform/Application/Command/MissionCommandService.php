<?php
declare( strict_types=1 );
/**
 * Application — MissionCommandService  (Write Model)
 *
 * Missions hebdomadaires : initialisation et progression.
 *
 * RÈGLE : aucun appel direct à $wpdb — tout passe par MissionRepository.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class RKP_MissionCommandService {

    /**
     * Crée les missions de la semaine en cours si elles n'existent pas encore.
     * Idempotent : ne recrée pas ce qui existe déjà.
     */
    public static function ensure_weekly_missions( int $child_rk_id ): void {
        $defs    = RKP_MissionQueryService::definitions();
        $week    = RKP_MissionQueryService::current_week_start();
        $keys    = array_slice( array_keys( $defs ), 0, 3 );
        $missing = array_diff( $keys, RKP_MissionRepository::find_existing_keys( $child_rk_id, $week, $keys ) );
        if ( empty( $missing ) ) return;

        RKP_DB::begin();
        foreach ( $missing as $key ) {
            if ( ! RKP_MissionRepository::insert( $child_rk_id, $key, $week, $defs[ $key ]['target'] ) ) {
                RKP_DB::rollback();
                return;
            }
        }
        RKP_DB::commit();
    }

    /**
     * Avance la progression d'une mission.
     * Récompense automatiquement si la mission vient d'être complétée.
     */
    public static function record_progress( int $child_rk_id, string $mission_key, int $increment = 1 ): void {
        $week = RKP_MissionQueryService::current_week_start();
        $row  = RKP_MissionRepository::find_row( $child_rk_id, $mission_key, $week );
        if ( ! $row || $row->completed ) return;

        $new_progress = min( (int) $row->target, (int) $row->progress + $increment );
        $completed    = (int) ( $new_progress >= (int) $row->target );

        RKP_DB::begin();

        if ( ! RKP_MissionRepository::update_progress( (int) $row->id, $new_progress, $completed ) ) {
            RKP_DB::rollback();
            return;
        }

        if ( $completed && ! $row->rewarded ) {
            $defs   = RKP_MissionQueryService::definitions();
            $points = $defs[ $mission_key ]['points'] ?? 30;
            $ok     = RKP_GamificationCommandService::add_points(
                $child_rk_id, $points, 'mission_weekly', 0,
                $defs[ $mission_key ]['name'] ?? $mission_key
            );
            if ( ! $ok || ! RKP_MissionRepository::mark_rewarded( (int) $row->id ) ) {
                RKP_DB::rollback();
                return;
            }
        }

        RKP_DB::commit();
        if ( $completed && ! $row->rewarded ) {
            do_action( 'rk_mc_mission_completed', $child_rk_id, $mission_key );
        }
    }
}
