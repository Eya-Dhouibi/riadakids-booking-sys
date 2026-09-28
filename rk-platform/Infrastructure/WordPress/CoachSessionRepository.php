<?php
declare( strict_types=1 );
/**
 * Infrastructure — CoachSessionRepository
 *
 * Requêtes sur wp_rk_bookings et wp_rk_child_coaches pour les séances coach.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/* Traits — factorisation par fonctionnalité (logique inchangée) */
require_once __DIR__ . '/traits/trait-rkp-coach-session-repo-queries.php';
require_once __DIR__ . '/traits/trait-rkp-coach-session-repo-stats.php';
require_once __DIR__ . '/traits/trait-rkp-coach-session-repo-courses.php';

final class RKP_CoachSessionRepository {
    use RKP_Coach_Session_Repo_Queries;
    use RKP_Coach_Session_Repo_Stats;
    use RKP_Coach_Session_Repo_Courses;


    private static function bookings_table(): string {
        global $wpdb;
        return $wpdb->prefix . 'rk_bookings';
    }

    private static function children_table(): string {
        global $wpdb;
        return $wpdb->prefix . 'rk_children';
    }

    private static function coach_map_table(): string {
        global $wpdb;
        return $wpdb->prefix . 'rk_child_coaches';
    }

    private static function table_exists( string $table ): bool {
        global $wpdb;
        static $cache = [];
        if ( ! isset( $cache[ $table ] ) ) {
            $cache[ $table ] = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
        }
        return $cache[ $table ];
    }

}
