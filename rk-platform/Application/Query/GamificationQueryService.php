<?php
declare( strict_types=1 );
/**
 * Application — GamificationQueryService  (Read Model)
 *
 * Répond à : "Combien de points ? Quel niveau ? Quand la dernière activité ?"
 *
 * RÈGLE : aucun appel direct à $wpdb — tout passe par GamificationRepository.
 *
 * Note : child_rk_id = rk_children.id (convention legacy des tables custom).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class RKP_GamificationQueryService {

    // ── Configuration ───────────────────────────────────────────────

    public static function levels(): array {
        return [
            1 => [ 'label' => 'شرارة',   'icon' => '⭐',  'min' => 0    ],
            2 => [ 'label' => 'مستكشف',  'icon' => '🔍', 'min' => 100  ],
            3 => [ 'label' => 'مكتشف',   'icon' => '🌱', 'min' => 300  ],
            4 => [ 'label' => 'مبتكر',   'icon' => '💡', 'min' => 600  ],
            5 => [ 'label' => 'بانٍ',    'icon' => '🏗️', 'min' => 1000 ],
            6 => [ 'label' => 'رائد',    'icon' => '🚀', 'min' => 1500 ],
            7 => [ 'label' => 'بطل',     'icon' => '🏆', 'min' => 2200 ],
            8 => [ 'label' => 'أسطورة',  'icon' => '✨', 'min' => 3000 ],
        ];
    }

    public static function point_values(): array {
        return [
            'session'         => 20,
            'lesson'          => 10,
            'quiz_pass'       => 15,
            'quiz_excellent'  => 25,
            'course_complete' => 60,
            'streak_3'        => 30,
            'badge_first'     => 20,
            'mission_weekly'  => 30,
        ];
    }

    // ── Reads ───────────────────────────────────────────────────────

    public static function get_total_points( int $child_rk_id ): int {
        return RKP_GamificationRepository::get_total_points( $child_rk_id );
    }

    public static function get_points_log( int $child_rk_id, int $limit = 10 ): array {
        return RKP_GamificationRepository::get_points_log( $child_rk_id, $limit );
    }

    /**
     * Streak = nombre de jours consécutifs d'activité, en remontant depuis
     * aujourd'hui (ou hier, pour ne pas casser le streak avant la fin de journée).
     * v2.1 — Audit P1-7 : remplace le `0` codé en dur du JourneySnapshot.
     */
    public static function get_streak( int $child_rk_id ): int {
        $dates = RKP_GamificationRepository::get_activity_dates( $child_rk_id, 60 );
        if ( empty( $dates ) ) return 0;

        $today     = current_time( 'Y-m-d' );
        $yesterday = date( 'Y-m-d', strtotime( $today . ' -1 day' ) );

        // Le streak est vivant si la dernière activité date d'aujourd'hui ou d'hier.
        if ( $dates[0] !== $today && $dates[0] !== $yesterday ) return 0;

        $streak = 1;
        for ( $i = 1, $n = count( $dates ); $i < $n; $i++ ) {
            $expected = date( 'Y-m-d', strtotime( $dates[ $i - 1 ] . ' -1 day' ) );
            if ( $dates[ $i ] !== $expected ) break;
            $streak++;
        }
        return $streak;
    }

    public static function get_last_activity_date( int $child_rk_id ): ?string {
        return RKP_GamificationRepository::get_last_activity_date( $child_rk_id );
    }

    // ── Level computation (pure, no DB) ─────────────────────────────

    public static function compute_level( int $total_points ): array {
        $levels  = self::levels();
        $current = $levels[1];
        $num     = 1;
        foreach ( $levels as $n => $l ) {
            if ( $total_points >= $l['min'] ) { $current = $l; $num = $n; }
        }
        $next_min = isset( $levels[ $num + 1 ] ) ? $levels[ $num + 1 ]['min'] : null;
        $progress = 0;
        if ( $next_min ) {
            $range    = $next_min - $current['min'];
            $progress = $range > 0
                ? min( 100, (int) round( ( $total_points - $current['min'] ) / $range * 100 ) )
                : 100;
        }
        return array_merge( $current, [
            'num'         => $num,
            'total'       => $total_points,
            'next_min'    => $next_min,
            'progress'    => $progress,
            'pts_to_next' => $next_min ? max( 0, $next_min - $total_points ) : 0,
        ] );
    }

    public static function get_level( int $child_rk_id ): array {
        return self::compute_level( self::get_total_points( $child_rk_id ) );
    }

    // ── ID conversion helper ─────────────────────────────────────────

    /** Convertit un wp_users.ID en rk_children.id. Retourne 0 si introuvable. */
    public static function get_child_rk_id_for_wp_user( int $wp_user_id ): int {
        return RKP_ChildRepository::get_id_by_wp_user( $wp_user_id );
    }
}
