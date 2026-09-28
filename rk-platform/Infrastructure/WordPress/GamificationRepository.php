<?php
declare( strict_types=1 );
/**
 * Infrastructure — GamificationRepository
 *
 * Seule couche autorisée à lire/écrire dans wp_rk_child_points.
 *
 * Note : child_id ici = rk_children.id (legacy ID du tableau personnalisé),
 *        PAS le wp_users.ID. Correspond exactement à la colonne child_id
 *        de la table physique.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class RKP_GamificationRepository {

    private static function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'rk_child_points';
    }

    private static function table_exists(): bool {
        global $wpdb;
        static $exists = null;
        if ( $exists === null ) {
            $t      = self::table();
            $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) === $t;
        }
        return $exists;
    }

    /** Insère une ligne de points. Retourne true si succès. */
    public static function insert_points(
        int $child_rk_id, int $points, string $source,
        int $source_id = 0, string $note = ''
    ): bool {
        if ( ! self::table_exists() ) return false;
        global $wpdb;
        return (bool) $wpdb->insert( self::table(), [
            'child_id'  => $child_rk_id,
            'points'    => $points,
            'source'    => $source,
            'source_id' => $source_id,
            'note'      => $note,
            'earned_at' => current_time( 'mysql' ),
        ] );
    }

    /** Total des points cumulés pour un enfant. */
    public static function get_total_points( int $child_rk_id ): int {
        if ( ! self::table_exists() ) return 0;
        global $wpdb;
        return max( 0, (int) $wpdb->get_var( $wpdb->prepare(
            'SELECT COALESCE(SUM(points),0) FROM ' . self::table() . ' WHERE child_id = %d',
            $child_rk_id
        ) ) );
    }

    /** Dernières entrées de points (log). */
    public static function get_points_log( int $child_rk_id, int $limit = 10 ): array {
        if ( ! self::table_exists() ) return [];
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            'SELECT * FROM ' . self::table() . ' WHERE child_id = %d ORDER BY earned_at DESC LIMIT %d',
            $child_rk_id, $limit
        ) ) ?: [];
    }

    /** Date de la dernière activité (earned_at MAX), ou null. */
    public static function get_last_activity_date( int $child_rk_id ): ?string {
        if ( ! self::table_exists() ) return null;
        global $wpdb;
        return $wpdb->get_var( $wpdb->prepare(
            'SELECT MAX(earned_at) FROM ' . self::table() . ' WHERE child_id = %d',
            $child_rk_id
        ) ) ?: null;
    }

    /**
     * Dates distinctes d'activité (earned_at) pour le calcul du streak,
     * triées DESC (la plus récente en premier).
     *
     * @return string[]  Format 'Y-m-d'.
     */
    public static function get_activity_dates( int $child_rk_id, int $limit = 30 ): array {
        if ( ! self::table_exists() ) return [];
        global $wpdb;
        return $wpdb->get_col( $wpdb->prepare(
            'SELECT DISTINCT DATE(earned_at) FROM ' . self::table() .
            ' WHERE child_id = %d ORDER BY 1 DESC LIMIT %d',
            $child_rk_id, $limit
        ) ) ?: [];
    }

    /**
     * Vérifie si des points ont déjà été accordés pour un source_id donné
     * (idempotence pour les tentatives de quiz).
     *
     * @param  string[]  $sources  Ex: ['quiz_excellent','quiz_pass','quiz_good','quiz_attempted']
     */
    public static function has_points_for_source_id(
        int $child_rk_id, int $source_id, array $sources
    ): bool {
        if ( ! self::table_exists() || $source_id <= 0 || empty( $sources ) ) return false;
        global $wpdb;
        $ph = implode( ',', array_fill( 0, count( $sources ), '%s' ) );
        return (bool) $wpdb->get_var( $wpdb->prepare(
            'SELECT COUNT(*) FROM ' . self::table() .
            " WHERE child_id = %d AND source_id = %d AND source IN ({$ph})",
            array_merge( [ $child_rk_id, $source_id ], $sources )
        ) );
    }

    /**
     * Total des points gagnés par chaque enfant dans un intervalle de dates.
     *
     * @param  int[]   $child_rk_ids  rk_children.id list
     * @param  string  $start         'Y-m-d H:i:s' ou 'Y-m-d'
     * @param  string  $end           'Y-m-d H:i:s' ou 'Y-m-d'
     * @return array<int, int>        [child_rk_id => total_points]
     */
    public static function get_weekly_points_for_children( array $child_rk_ids, string $start, string $end ): array {
        if ( ! self::table_exists() || empty( $child_rk_ids ) ) return [];
        global $wpdb;
        $ph   = implode( ',', array_fill( 0, count( $child_rk_ids ), '%d' ) );
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT child_id, COALESCE(SUM(points),0) AS total
               FROM " . self::table() . "
              WHERE child_id IN ({$ph}) AND earned_at BETWEEN %s AND %s
              GROUP BY child_id",
            ...array_merge( $child_rk_ids, [ $start, $end ] )
        ) ) ?: [];
        $map = [];
        foreach ( $rows as $r ) {
            $map[ (int) $r->child_id ] = (int) $r->total;
        }
        return $map;
    }
}
