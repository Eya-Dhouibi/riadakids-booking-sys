<?php
declare( strict_types=1 );
/**
 * RK_MC_Mission_Service  (v7.0.0 — Adaptateur)
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * ADAPTATEUR vers RKP_MissionCommandService / RKP_MissionQueryService.
 *
 * Interface publique préservée à l'identique.
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * @package RK_My_Children
 * @since   7.0.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class RK_MC_Mission_Service {

    /* ─── Définitions (délégué à RKP) ──────────────────────────── */

    public static function definitions(): array {
        if ( class_exists( 'RKP_MissionQueryService' ) ) {
            return RKP_MissionQueryService::definitions();
        }
        return [];
    }

    /* ─── Initialiser les missions de la semaine ────────────────── */

    public static function ensure_weekly_missions( int $child_id ): void {
        if ( ! class_exists( 'RKP_MissionCommandService' ) ) return;
        RKP_MissionCommandService::ensure_weekly_missions( $child_id );
    }

    /* ─── Enregistrer progression ───────────────────────────────── */

    public static function record_progress( int $child_id, string $mission_key, int $increment = 1 ): void {
        if ( ! class_exists( 'RKP_MissionCommandService' ) ) return;
        RKP_MissionCommandService::record_progress( $child_id, $mission_key, $increment );
    }

    /* ─── Lecture ───────────────────────────────────────────────── */

    public static function get_weekly_missions( int $child_id ): array {
        if ( ! class_exists( 'RKP_MissionQueryService' ) ) return [];
        return RKP_MissionQueryService::get_weekly_missions( $child_id );
    }
}
