<?php
declare( strict_types=1 );
/**
 * Application — AssessmentCommandService  (Write Model)
 *
 * Création, mise à jour et suppression des bilans pédagogiques.
 *
 * RÈGLE : aucun appel direct à $wpdb — tout passe par AssessmentRepository.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class RKP_AssessmentCommandService {

    /** Invalide les caches objet pour un enfant. */
    private static function bust_caches( int $child_rk_id ): void {
        $group = 'rk_coach';
        wp_cache_delete( 'rk_mc_assessment_latest_' . $child_rk_id,       $group );
        wp_cache_delete( 'rk_mc_assessments_' . $child_rk_id,             $group );
        wp_cache_delete( 'rk_mc_assessments_' . $child_rk_id . '_skip',   $group );
    }

    /**
     * Crée un bilan. Retourne l'ID inséré (0 = échec).
     * Déclenche le hook rk_mc_assessment_created($id, $child_rk_id, $coach_id).
     */
    public static function create( array $data ): int {
        $child_rk_id = absint( $data['child_id'] ?? 0 );
        $coach_id    = absint( $data['coach_id'] ?? get_current_user_id() );
        $coach_name  = sanitize_text_field( $data['coach_name'] ?? '' );

        if ( ! $coach_name && $coach_id ) {
            $u = get_userdata( $coach_id );
            if ( $u ) $coach_name = $u->display_name;
        }

        $row = [
            'child_id'       => $child_rk_id,
            'booking_id'     => absint( $data['booking_id'] ?? 0 ),
            'coach_id'       => $coach_id,
            'coach_name'     => $coach_name,
            'assessed_at'    => sanitize_text_field( $data['assessed_at'] ?? current_time( 'Y-m-d' ) ),
            'rating'         => min( 5, max( 1, absint( $data['rating'] ?? 3 ) ) ),
            'summary'        => sanitize_textarea_field( $data['summary'] ?? '' ),
            'strengths'      => wp_json_encode(
                array_slice( array_filter( array_map( 'sanitize_text_field', (array) ( $data['strengths'] ?? [] ) ) ), 0, 3 )
            ),
            'developments'   => wp_json_encode(
                array_slice( array_filter( array_map( 'sanitize_text_field', (array) ( $data['developments'] ?? [] ) ) ), 0, 2 )
            ),
            'notes'          => sanitize_textarea_field( $data['notes'] ?? '' ),
            'parent_message' => sanitize_textarea_field( $data['parent_message'] ?? '' ),
            'skill_scores'   => wp_json_encode(
                array_map( static fn( $v ) => min( 5, max( 0, (int) $v ) ), (array) ( $data['skill_scores'] ?? [] ) )
            ),
        ];

        $id = RKP_AssessmentRepository::insert( $row );
        if ( $id ) {
            self::bust_caches( $child_rk_id );
            do_action( 'rk_mc_assessment_created', $id, $child_rk_id, $coach_id );
        }
        return $id;
    }

    /**
     * Met à jour les champs fournis d'un bilan existant.
     */
    public static function update( int $id, array $data ): bool {
        $existing = RKP_AssessmentQueryService::get_by_id( $id );
        $set      = [];

        if ( isset( $data['assessed_at'] ) )    $set['assessed_at']    = sanitize_text_field( $data['assessed_at'] );
        if ( isset( $data['booking_id'] ) )      $set['booking_id']     = absint( $data['booking_id'] );
        if ( isset( $data['rating'] ) )          $set['rating']         = min( 5, max( 1, absint( $data['rating'] ) ) );
        if ( isset( $data['summary'] ) )         $set['summary']        = sanitize_textarea_field( $data['summary'] );
        if ( isset( $data['notes'] ) )           $set['notes']          = sanitize_textarea_field( $data['notes'] );
        if ( isset( $data['parent_message'] ) )  $set['parent_message'] = sanitize_textarea_field( $data['parent_message'] );
        if ( isset( $data['strengths'] ) ) {
            $set['strengths']    = wp_json_encode(
                array_slice( array_filter( array_map( 'sanitize_text_field', (array) $data['strengths'] ) ), 0, 3 )
            );
        }
        if ( isset( $data['developments'] ) ) {
            $set['developments'] = wp_json_encode(
                array_slice( array_filter( array_map( 'sanitize_text_field', (array) $data['developments'] ) ), 0, 2 )
            );
        }
        if ( isset( $data['skill_scores'] ) ) {
            $set['skill_scores'] = wp_json_encode(
                array_map( static fn( $v ) => min( 5, max( 0, (int) $v ) ), (array) $data['skill_scores'] )
            );
        }

        $ok = RKP_AssessmentRepository::update( $id, $set );
        if ( $ok && $existing ) {
            $child_rk_id = (int) $existing['child_id'];
            self::bust_caches( $child_rk_id );
            do_action( 'rk_mc_bust_child_caches', $child_rk_id );
        }
        return $ok;
    }

    /** Supprime un bilan. */
    public static function delete( int $id ): bool {
        $existing = RKP_AssessmentQueryService::get_by_id( $id );
        $ok       = RKP_AssessmentRepository::delete( $id );
        if ( $ok && $existing ) {
            $child_rk_id = (int) $existing['child_id'];
            self::bust_caches( $child_rk_id );
            do_action( 'rk_mc_bust_child_caches', $child_rk_id );
        }
        return $ok;
    }

    /**
     * Crée ou met à jour le bilan (child, date, coach).
     * Retourne l'assessment_id.
     */
    public static function create_or_update( int $child_rk_id, string $date, int $coach_id, array $data ): int {
        $existing = RKP_AssessmentQueryService::get_for_child_date( $child_rk_id, $date, $coach_id );
        if ( $existing ) {
            self::update( $existing['id'], $data );
            return $existing['id'];
        }
        $data['child_id']    = $child_rk_id;
        $data['assessed_at'] = $date;
        $data['coach_id']    = $coach_id;
        $data += [
            'rating'         => 3,
            'summary'        => '',
            'strengths'      => [],
            'developments'   => [],
            'skill_scores'   => [],
            'notes'          => '',
            'parent_message' => '',
        ];
        return self::create( $data );
    }

    /**
     * Crée ou met à jour le bilan d'une séance précise (booking_id) — clé
     * fiable, contrairement à (date, coach) qui peut correspondre à
     * plusieurs séances du même enfant le même jour. $date/$child_rk_id/
     * $coach_id restent nécessaires pour la création initiale (colonnes
     * assessed_at/child_id/coach_id) et pour le fallback de lecture côté
     * anciens rapports (rk-rapport.php) qui n'ont pas encore de booking_id.
     * Retourne l'assessment_id.
     */
    public static function create_or_update_for_booking(
        int $booking_id, int $child_rk_id, string $date, int $coach_id, array $data
    ): int {
        $existing = RKP_AssessmentQueryService::get_for_booking( $booking_id );
        if ( $existing ) {
            self::update( $existing['id'], $data );
            return $existing['id'];
        }
        $data['child_id']    = $child_rk_id;
        $data['booking_id']  = $booking_id;
        $data['assessed_at'] = $date;
        $data['coach_id']    = $coach_id;
        $data += [
            'rating'         => 3,
            'summary'        => '',
            'strengths'      => [],
            'developments'   => [],
            'skill_scores'   => [],
            'notes'          => '',
            'parent_message' => '',
        ];
        return self::create( $data );
    }
}
