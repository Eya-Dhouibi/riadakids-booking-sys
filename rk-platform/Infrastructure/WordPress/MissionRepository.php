<?php
declare( strict_types=1 );
/**
 * Infrastructure — MissionRepository
 *
 * Seule couche autorisée à lire/écrire dans wp_rk_child_missions.
 *
 * Note : child_id ici = rk_children.id (legacy ID), PAS wp_users.ID.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class RKP_MissionRepository {

    private static function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'rk_child_missions';
    }

    /** Retourne la ligne de mission ou null si inexistante. */
    public static function find_row( int $child_rk_id, string $mission_key, string $week_start ): ?object {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            'SELECT * FROM ' . self::table() . ' WHERE child_id=%d AND mission_key=%s AND week_start=%s LIMIT 1',
            $child_rk_id, $mission_key, $week_start
        ) ) ?: null;
    }

    /** Clés des missions existantes pour un enfant + semaine (une seule requête). */
    public static function find_existing_keys( int $child_rk_id, string $week_start, array $keys ): array {
        if ( empty( $keys ) ) return [];
        global $wpdb;
        $ph = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
        return $wpdb->get_col( $wpdb->prepare(
            "SELECT mission_key FROM " . self::table() .
            " WHERE child_id=%d AND week_start=%s AND mission_key IN ({$ph})",
            array_merge( [ $child_rk_id, $week_start ], $keys )
        ) ) ?: [];
    }

    /** Insère une mission hebdomadaire. */
    public static function insert( int $child_rk_id, string $mission_key, string $week_start, int $target ): void {
        global $wpdb;
        $wpdb->insert( self::table(), [
            'child_id'    => $child_rk_id,
            'mission_key' => $mission_key,
            'week_start'  => $week_start,
            'target'      => $target,
            'progress'    => 0,
            'completed'   => 0,
            'rewarded'    => 0,
        ] );
    }

    /** Retourne toutes les missions d'un enfant pour une semaine. */
    public static function find_weekly( int $child_rk_id, string $week_start ): array {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            'SELECT * FROM ' . self::table() . ' WHERE child_id=%d AND week_start=%s',
            $child_rk_id, $week_start
        ) ) ?: [];
    }

    /** Met à jour la progression (et le flag completed). */
    public static function update_progress( int $id, int $progress, int $completed ): void {
        global $wpdb;
        $wpdb->update( self::table(), [ 'progress' => $progress, 'completed' => $completed ], [ 'id' => $id ] );
    }

    /** Marque une mission comme récompensée. */
    public static function mark_rewarded( int $id ): void {
        global $wpdb;
        $wpdb->update( self::table(), [ 'rewarded' => 1 ], [ 'id' => $id ] );
    }

    /**
     * Nombre de missions hebdo complétées par chaque enfant pour une semaine donnée.
     *
     * @param  int[]   $child_rk_ids
     * @param  string  $week_start   'Y-m-d'
     * @return array<int, int>       [child_rk_id => count]
     */
    /** Retourne une mission hebdo par son ID (ou null). */
    public static function find_by_id( int $id ): ?object {
        if ( $id <= 0 ) return null;
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            'SELECT * FROM ' . self::table() . ' WHERE id = %d LIMIT 1',
            $id
        ) ) ?: null;
    }

    public static function count_completed_for_children_in_week( array $child_rk_ids, string $week_start ): array {
        if ( empty( $child_rk_ids ) ) return [];
        global $wpdb;
        $ph   = implode( ',', array_fill( 0, count( $child_rk_ids ), '%d' ) );
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT child_id, COUNT(*) AS cnt
               FROM " . self::table() . "
              WHERE child_id IN ({$ph}) AND week_start = %s AND completed = 1
              GROUP BY child_id",
            ...array_merge( $child_rk_ids, [ $week_start ] )
        ) ) ?: [];
        $map = [];
        foreach ( $rows as $r ) {
            $map[ (int) $r->child_id ] = (int) $r->cnt;
        }
        return $map;
    }
}
