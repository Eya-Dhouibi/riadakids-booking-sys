<?php
declare( strict_types=1 );
/**
 * Application — AssessmentQueryService  (Read Model)
 *
 * Bilans pédagogiques des enfants (évaluations coach).
 *
 * RÈGLE : aucun appel direct à $wpdb — tout passe par AssessmentRepository.
 *
 * Note : child_rk_id = rk_children.id (legacy ID custom).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class RKP_AssessmentQueryService {

    // ── Format ──────────────────────────────────────────────────────

    public static function format( object $row ): array {
        return [
            'id'             => (int) $row->id,
            'child_id'       => (int) $row->child_id,
            'booking_id'     => (int) ( $row->booking_id ?? 0 ),
            'coach_id'       => (int) $row->coach_id,
            'coach_name'     => (string) ( $row->coach_name ?? '' ),
            'assessed_at'    => (string) ( $row->assessed_at ?? '' ),
            'rating'         => (int) ( $row->rating ?? 3 ),
            'summary'        => (string) ( $row->summary ?? '' ),
            'strengths'      => json_decode( $row->strengths    ?? '[]', true ) ?: [],
            'developments'   => json_decode( $row->developments ?? '[]', true ) ?: [],
            'notes'          => (string) ( $row->notes ?? '' ),
            'parent_message' => (string) ( $row->parent_message ?? '' ),
            'skill_scores'   => json_decode( $row->skill_scores ?? '{}', true ) ?: [],
        ];
    }

    // ── Reads ───────────────────────────────────────────────────────

    public static function get_latest( int $child_rk_id ): ?array {
        $cache_key = 'rk_mc_assessment_latest_' . $child_rk_id;
        $cached    = wp_cache_get( $cache_key, 'rk_coach' );
        if ( false !== $cached ) {
            return is_array( $cached ) ? $cached : null;
        }
        $row    = RKP_AssessmentRepository::find_latest_for_child( $child_rk_id );
        $result = $row ? self::format( $row ) : null;
        wp_cache_set( $cache_key, $result ?? '__null__', 'rk_coach' );
        return $result;
    }

    public static function get_all( int $child_rk_id, bool $skip_first = false ): array {
        $cache_key = 'rk_mc_assessments_' . $child_rk_id . ( $skip_first ? '_skip' : '' );
        $cached    = wp_cache_get( $cache_key, 'rk_coach' );
        if ( false !== $cached ) return $cached;

        $offset = $skip_first ? 1 : 0;
        $rows   = RKP_AssessmentRepository::find_all_for_child( $child_rk_id, 10, $offset );
        $result = array_map( [ self::class, 'format' ], $rows );
        wp_cache_set( $cache_key, $result, 'rk_coach' );
        return $result;
    }

    public static function get_by_id( int $id ): ?array {
        $row = RKP_AssessmentRepository::find_by_id( $id );
        return $row ? self::format( $row ) : null;
    }

    public static function get_for_child_date( int $child_rk_id, string $date, int $coach_id ): ?array {
        $row = RKP_AssessmentRepository::find_for_child_date_coach( $child_rk_id, $date, $coach_id );
        return $row ? self::format( $row ) : null;
    }

    /**
     * Bilan d'une séance précise (booking_id) — source de vérité prioritaire
     * quand elle existe. Voir RKP_AssessmentRepository::find_for_booking().
     */
    public static function get_for_booking( int $booking_id ): ?array {
        $row = RKP_AssessmentRepository::find_for_booking( $booking_id );
        return $row ? self::format( $row ) : null;
    }

    public static function get_avg_rating_for_coach( int $coach_id ): float {
        return RKP_AssessmentRepository::get_avg_rating_for_coach( $coach_id );
    }

    // ── Mobile (self-access enfant) — jamais 'notes' (privé coach) ────

    /**
     * Version mobile de format() : exclut délibérément 'notes' (réservé
     * au coach, cf. audit 16/09/2026 §2.1/§2.3 — la fuite identifiée par
     * le plan v1 mais jamais corrigée avant ce correctif).
     */
    public static function format_for_child( object $row ): array {
        $full = self::format( $row );
        unset( $full['notes'] );
        return $full;
    }

    /** @return array[] — tous les bilans d'un enfant, sans 'notes'. */
    public static function get_all_for_child_safe( int $child_rk_id ): array {
        $rows = RKP_AssessmentRepository::find_all_for_child( $child_rk_id, 50, 0 );
        return array_map( [ self::class, 'format_for_child' ], $rows );
    }

    /**
     * Bilan d'une réservation précise, borné à l'enfant authentifié.
     * Retourne null si le bilan n'existe pas OU s'il appartient à un
     * autre enfant que $child_rk_id — jamais d'exception, jamais de 500.
     */
    public static function get_for_booking_safe( int $booking_id, int $child_rk_id ): ?array {
        $row = RKP_AssessmentRepository::find_for_booking( $booking_id );
        if ( ! $row || (int) $row->child_id !== $child_rk_id ) return null;
        return self::format_for_child( $row );
    }
}
