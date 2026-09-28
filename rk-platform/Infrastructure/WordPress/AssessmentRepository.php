<?php
declare( strict_types=1 );
/**
 * Infrastructure — AssessmentRepository
 *
 * Seule couche autorisée à lire/écrire dans wp_rk_child_assessments.
 *
 * Note : child_id ici = rk_children.id (legacy ID), PAS wp_users.ID.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class RKP_AssessmentRepository {

    private static function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'rk_child_assessments';
    }

    /** Garantit que la colonne parent_message existe (migration lazy, une fois par request). */
    private static function ensure_schema(): void {
        static $done = false;
        if ( $done ) return;
        $done = true;
        global $wpdb;
        if ( ! $wpdb->get_var( "SHOW COLUMNS FROM " . self::table() . " LIKE 'parent_message'" ) ) {
            $wpdb->query( "ALTER TABLE " . self::table() . " ADD COLUMN parent_message TEXT NOT NULL DEFAULT '' AFTER notes" );
        }
        // CORRECTIF — lien direct vers la séance évaluée (wp_rk_bookings.booking_id).
        // Sans cette colonne, l'évaluation était retrouvée par (child_id, date,
        // coach_id) : si un coach fait 2 séances le même jour avec le même enfant,
        // la 2e évaluation écrasait la 1ère silencieusement (une seule ligne
        // possible pour ce triplet). booking_id = 0 pour les anciennes lignes
        // (pré-migration) ou les évaluations sans séance liée : le fallback
        // date+coach reste utilisé dans ce cas (voir find_for_child_date_coach).
        if ( ! $wpdb->get_var( "SHOW COLUMNS FROM " . self::table() . " LIKE 'booking_id'" ) ) {
            $wpdb->query( "ALTER TABLE " . self::table() . " ADD COLUMN booking_id BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER child_id" );
            $wpdb->query( "ALTER TABLE " . self::table() . " ADD INDEX booking_id (booking_id)" );
        }
    }

    /** Insère un bilan. Retourne l'ID inséré (0 = échec). */
    public static function insert( array $row ): int {
        self::ensure_schema();
        global $wpdb;
        $ok = $wpdb->insert( self::table(), $row );
        return $ok ? (int) $wpdb->insert_id : 0;
    }

    /** Met à jour les colonnes fournies dans $set pour l'ID donné. */
    public static function update( int $id, array $set ): bool {
        self::ensure_schema();
        if ( empty( $set ) ) return false;
        global $wpdb;
        return (bool) $wpdb->update( self::table(), $set, [ 'id' => $id ] );
    }

    /** Supprime un bilan. */
    public static function delete( int $id ): bool {
        global $wpdb;
        return (bool) $wpdb->delete( self::table(), [ 'id' => $id ] );
    }

    /** Retourne un bilan par son ID, ou null. */
    public static function find_by_id( int $id ): ?object {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            'SELECT * FROM ' . self::table() . ' WHERE id = %d LIMIT 1',
            $id
        ) ) ?: null;
    }

    /** Dernier bilan d'un enfant (assessed_at DESC), ou null. */
    public static function find_latest_for_child( int $child_rk_id ): ?object {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            'SELECT * FROM ' . self::table() . ' WHERE child_id = %d ORDER BY assessed_at DESC LIMIT 1',
            $child_rk_id
        ) ) ?: null;
    }

    /** Bilans d'un enfant, du plus récent. */
    public static function find_all_for_child( int $child_rk_id, int $limit = 10, int $offset = 0 ): array {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            'SELECT * FROM ' . self::table() .
            ' WHERE child_id = %d ORDER BY assessed_at DESC LIMIT %d OFFSET %d',
            $child_rk_id, $limit, $offset
        ) ) ?: [];
    }

    /** Bilan existant pour (child, date, coach), ou null. */
    public static function find_for_child_date_coach( int $child_rk_id, string $date, int $coach_id ): ?object {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            'SELECT * FROM ' . self::table() .
            ' WHERE child_id = %d AND assessed_at = %s AND coach_id = %d LIMIT 1',
            $child_rk_id, $date, $coach_id
        ) ) ?: null;
    }

    /**
     * Bilan existant pour une séance précise (booking_id), ou null.
     * Source de vérité prioritaire depuis la migration booking_id — voir
     * ensure_schema(). Un booking_id = 0 ne matche jamais (séances
     * pré-migration ou évaluations sans séance liée).
     */
    public static function find_for_booking( int $booking_id ): ?object {
        if ( $booking_id <= 0 ) return null;
        self::ensure_schema();
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            'SELECT * FROM ' . self::table() . ' WHERE booking_id = %d LIMIT 1',
            $booking_id
        ) ) ?: null;
    }

    /**
     * Date du dernier bilan (MAX assessed_at) pour un couple (child, coach).
     * Retourne null si aucun bilan.
     */
    public static function find_last_assessed_at_for_coach( int $child_rk_id, int $coach_id ): ?string {
        global $wpdb;
        return $wpdb->get_var( $wpdb->prepare(
            'SELECT MAX(assessed_at) FROM ' . self::table() . ' WHERE child_id = %d AND coach_id = %d',
            $child_rk_id, $coach_id
        ) ) ?: null;
    }

    /**
     * Compte les bilans écrits par un coach pour un intervalle de dates donné.
     */
    public static function count_for_coach_in_range( int $coach_id, string $start, string $end ): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            'SELECT COUNT(*) FROM ' . self::table() . ' WHERE coach_id = %d AND assessed_at BETWEEN %s AND %s',
            $coach_id, $start, $end
        ) );
    }

    /**
     * Dernier assessed_at de chaque enfant pour un coach — une seule requête SQL.
     *
     * @param int[] $child_rk_ids
     * @return array<int, string|null>  [ child_rk_id => 'YYYY-MM-DD' | null ]
     */
    public static function find_last_assessed_at_batch_for_coach( array $child_rk_ids, int $coach_id ): array {
        if ( empty( $child_rk_ids ) ) return [];
        global $wpdb;
        $ph   = implode( ',', array_fill( 0, count( $child_rk_ids ), '%d' ) );
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT child_id, MAX(assessed_at) AS last_at FROM " . self::table() .
            " WHERE coach_id = %d AND child_id IN ({$ph}) GROUP BY child_id",
            array_merge( [ $coach_id ], $child_rk_ids )
        ) ) ?: [];
        $map = [];
        foreach ( $rows as $r ) {
            $map[ (int) $r->child_id ] = $r->last_at ?: null;
        }
        return $map;
    }

    /** Note moyenne des bilans pour un coach (colonne rating). */
    public static function get_avg_rating_for_coach( int $coach_id ): float {
        global $wpdb;
        return (float) $wpdb->get_var( $wpdb->prepare(
            'SELECT AVG(rating) FROM ' . self::table() . ' WHERE coach_id = %d',
            $coach_id
        ) );
    }
}
