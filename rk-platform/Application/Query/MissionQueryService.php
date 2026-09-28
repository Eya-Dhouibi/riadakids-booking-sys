<?php
declare( strict_types=1 );
/**
 * Application — MissionQueryService  (Read Model)
 *
 * Missions hebdomadaires : définitions et lectures.
 *
 * RÈGLE : aucun appel direct à $wpdb — tout passe par MissionRepository.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class RKP_MissionQueryService {

    // ── Définitions (config pure) ────────────────────────────────────

    public static function definitions(): array {
        return [
            'complete_lessons' => [
                'name'     => 'أكمل درسين هذا الأسبوع',
                'icon_key' => 'graduation-cap',
                'target'   => 2,
                'points'   => 30,
            ],
            'attend_session' => [
                'name'     => 'احضر جلستك هذا الأسبوع',
                'icon_key' => 'calendar',
                'target'   => 1,
                'points'   => 20,
            ],
            'pass_quiz' => [
                'name'     => 'حقق 80% في اختبار',
                'icon_key' => 'nav-target',
                'target'   => 1,
                'points'   => 25,
            ],
            'complete_lesson' => [
                'name'     => 'أكمل درساً واحداً',
                'icon_key' => 'book-open',
                'target'   => 1,
                'points'   => 10,
            ],
        ];
    }

    public static function current_week_start(): string {
        $dow    = (int) date( 'N' );
        $offset = $dow === 7 ? -6 : 1 - $dow;
        return date( 'Y-m-d', strtotime( "{$offset} days" ) );
    }

    // ── Read ────────────────────────────────────────────────────────

    /**
     * Missions hebdomadaires d'un enfant, enrichies avec les définitions.
     * Crée les missions manquantes si nécessaire (idempotent via ensure_weekly).
     */
    public static function get_weekly_missions( int $child_rk_id ): array {
        RKP_MissionCommandService::ensure_weekly_missions( $child_rk_id );

        $week = self::current_week_start();
        $rows = RKP_MissionRepository::find_weekly( $child_rk_id, $week );
        $defs = self::definitions();
        $out  = [];
        foreach ( $rows as $r ) {
            $def  = $defs[ $r->mission_key ] ?? [];
            $pct  = $r->target > 0 ? min( 100, (int) round( $r->progress / $r->target * 100 ) ) : 0;
            $out[] = [
                'id'        => (int) $r->id, // PK wp_rk_child_missions — nécessaire pour l'API mobile (Mission.id)
                'key'       => $r->mission_key,
                'name'      => $def['name']     ?? $r->mission_key,
                'icon'      => $def['icon_key'] ?? 'nav-target',
                'points'    => $def['points']   ?? 0,
                'target'    => (int) $r->target,
                'progress'  => (int) $r->progress,
                'pct'       => $pct,
                'completed' => (bool) $r->completed,
                'rewarded'  => (bool) $r->rewarded,
            ];
        }
        return $out;
    }
}