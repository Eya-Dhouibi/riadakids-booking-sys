<?php
declare( strict_types=1 );
/**
 * Application — GamificationCommandService  (Write Model)
 *
 * Toutes les opérations d'écriture liées aux points et niveaux.
 * Déclenche les WP hooks historiques pour la rétrocompatibilité.
 *
 * RÈGLE : aucun appel direct à $wpdb — tout passe par GamificationRepository.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class RKP_GamificationCommandService {

    /**
     * Ajoute des points à un enfant.
     *
     * - Vérifie l'idempotence pour les tentatives de quiz (source_id > 0).
     * - Compare le niveau avant/après et déclenche rk_mc_child_level_up si besoin.
     * - Déclenche toujours rk_mc_child_points_added après insertion.
     *
     * @param int    $child_rk_id  rk_children.id (legacy ID).
     * @param int    $points       Négatif = déduction ; 0 = aucun effet.
     * @param string $source       Clé source (lesson, quiz_pass, …).
     * @param int    $source_id    ID de la ressource (attempt_id, lesson_id…). 0 = non applicable.
     * @param string $note         Texte libre.
     */
    public static function add_points(
        int $child_rk_id, int $points, string $source,
        int $source_id = 0, string $note = ''
    ): bool {
        if ( $child_rk_id <= 0 || $points === 0 ) return false;

        // Idempotence quiz + évaluation coach : ne pas accorder deux fois
        // les mêmes points pour la même tentative de quiz, ni pour la même
        // évaluation coach (source_id = assessment_id, STABLE entre la
        // création et toute modification ultérieure de la même évaluation
        // — voir create_or_update_for_booking_authorized(), Phase 1 —
        // donc une 2e sauvegarde de la même évaluation ne doit pas
        // redonner +5 points (revue Phase 3, point 4).
        $rk_idempotent_sources = [ 'quiz_excellent', 'quiz_pass', 'quiz_good', 'quiz_attempted', 'evaluation' ];
        if ( $source_id > 0 && in_array( $source, $rk_idempotent_sources, true ) ) {
            if ( RKP_GamificationRepository::has_points_for_source_id(
                $child_rk_id, $source_id, $rk_idempotent_sources
            ) ) {
                return false;
            }
        }

        $old_level = RKP_GamificationQueryService::get_level( $child_rk_id );
        $inserted  = RKP_GamificationRepository::insert_points( $child_rk_id, $points, $source, $source_id, $note );

        if ( $inserted ) {
            $new_total = RKP_GamificationQueryService::get_total_points( $child_rk_id );
            $new_level = RKP_GamificationQueryService::compute_level( $new_total );

            if ( $new_level['num'] > $old_level['num'] ) {
                do_action( 'rk_mc_child_level_up', $child_rk_id, $new_level, $old_level );
            }
            do_action( 'rk_mc_child_points_added', $child_rk_id, $points, $source );
        }
        return $inserted;
    }
}
