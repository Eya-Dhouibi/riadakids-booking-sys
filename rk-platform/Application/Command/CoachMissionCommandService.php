<?php
declare( strict_types=1 );
/**
 * Application — CoachMissionCommandService  (Write Model)
 *
 * Logique métier pour les missions assignées par un coach à un enfant.
 * RÈGLE : aucun appel direct à $wpdb — tout passe par CoachMissionRepository.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class RKP_CoachMissionCommandService {

    /**
     * Assigne une nouvelle mission à un enfant.
     *
     * @param array $data {
     *   child_id    int     (rk_children.id)
     *   coach_id    int
     *   title       string
     *   description string
     *   target      int     (1-30, nombre de répétitions)
     *   points      int     (5-200)
     *   due_date    string  (YYYY-MM-DD, optionnel)
     *   file_url    string  (optionnel)
     *   file_name   string  (optionnel)
     * }
     * @return int  ID inséré (0 = échec)
     */
    public static function assign( array $data ): int {
        RKP_CoachMissionRepository::ensure_file_columns();

        $row = [
            'child_id'    => (int)    ( $data['child_id']    ?? 0 ),
            'coach_id'    => (int)    ( $data['coach_id']    ?? 0 ),
            'title'       => (string) ( $data['title']       ?? '' ),
            'description' => (string) ( $data['description'] ?? '' ),
            'target'      => max( 1, min( 30,  (int) ( $data['target'] ?? 1 ) ) ),
            'points'      => max( 5, min( 200, (int) ( $data['points'] ?? 10 ) ) ),
            'due_date'    => ! empty( $data['due_date'] ) ? (string) $data['due_date'] : null,
            'file_url'    => (string) ( $data['file_url']  ?? '' ),
            'file_name'   => (string) ( $data['file_name'] ?? '' ),
        ];

        if ( ! $row['child_id'] || ! $row['coach_id'] || ! $row['title'] ) return 0;

        return RKP_CoachMissionRepository::insert( $row );
    }

    /**
     * Enregistre +1 progrès pour une mission assignée par le coach.
     * Si complétée, attribue les points via GamificationCommandService et
     * fire le hook rk_mc_mission_completed.
     *
     * @return bool  true si mise à jour réussie (ou déjà récompensée)
     */
    public static function record_progress( int $mission_id, int $coach_id ): bool {
        $m = RKP_CoachMissionRepository::find_by_id( $mission_id );
        if ( ! $m || (int) $m->coach_id !== $coach_id ) return false;
        if ( $m->completed ) return true;

        $new_progress     = min( (int) $m->target, (int) $m->progress + 1 );
        $completed        = $new_progress >= (int) $m->target;
        $already_rewarded = ! empty( $m->rewarded_at );

        $set = [
            'progress'  => $new_progress,
            'completed' => $completed ? 1 : 0,
        ];
        if ( $completed && ! $already_rewarded ) {
            $set['rewarded_at'] = current_time( 'mysql' );
        }

        RKP_DB::begin();

        $ok = RKP_CoachMissionRepository::update( $mission_id, $set );
        if ( ! $ok ) {
            RKP_DB::rollback();
            return false;
        }

        if ( $completed && ! $already_rewarded && class_exists( 'RKP_GamificationCommandService' ) ) {
            $pts_ok = RKP_GamificationCommandService::add_points(
                (int) $m->child_id,
                (int) $m->points,
                'coach_mission',
                $mission_id,
                (string) $m->title
            );
            if ( ! $pts_ok ) {
                RKP_DB::rollback();
                return false;
            }
        }

        RKP_DB::commit();

        if ( $completed && ! $already_rewarded ) {
            do_action( 'rk_mc_mission_completed', (int) $m->child_id, 'coach_' . $mission_id );
        }

        return true;
    }
}
