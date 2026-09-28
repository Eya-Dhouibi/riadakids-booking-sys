<?php
declare( strict_types=1 );
/**
 * RK_MC_Badge_Service  (v7.0.0 — Adaptateur)
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * ADAPTATEUR vers RKP_BadgeCommandService / RKP_BadgeQueryService.
 *
 * Interface publique préservée à l'identique.
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * @package RK_My_Children
 * @since   7.0.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class RK_MC_Badge_Service {

    /* ─── Catalogue (délégué à RKP) ────────────────────────────── */

    public static function catalogue(): array {
        if ( class_exists( 'RKP_BadgeQueryService' ) ) {
            return RKP_BadgeQueryService::catalogue();
        }
        return [];
    }

    /* ─── Award ─────────────────────────────────────────────────── */

    public static function award( int $child_id, string $badge_key, string $note = '' ): bool {
        if ( ! class_exists( 'RKP_BadgeCommandService' ) ) return false;
        return RKP_BadgeCommandService::award( $child_id, $badge_key, $note );
    }

    /** v9.30 — variante avec code d'échec explicite, voir RKP_BadgeCommandService::award_with_reason(). */
    public static function award_with_reason( int $child_id, string $badge_key, string $note = '' ): string {
        if ( ! class_exists( 'RKP_BadgeCommandService' ) ) return 'service_unavailable';
        return RKP_BadgeCommandService::award_with_reason( $child_id, $badge_key, $note );
    }

    public static function maybe_award( int $child_id, string $trigger ): void {
        if ( ! class_exists( 'RKP_BadgeCommandService' ) ) return;
        RKP_BadgeCommandService::maybe_award( $child_id, $trigger );
    }

    public static function award_level_badge( int $child_id, array $level ): void {
        if ( ! class_exists( 'RKP_BadgeCommandService' ) ) return;
        RKP_BadgeCommandService::award_level_badge( $child_id, $level );
    }

    public static function revoke( int $child_id, string $badge_key ): bool {
        if ( ! class_exists( 'RKP_BadgeCommandService' ) ) return false;
        return RKP_BadgeCommandService::revoke( $child_id, $badge_key );
    }

    /** Marque un badge comme vu (§ SEEN STATE). */
    public static function mark_as_seen( int $child_id, string $badge_key ): bool {
        if ( ! class_exists( 'RKP_BadgeCommandService' ) ) return false;
        return RKP_BadgeCommandService::mark_as_seen( $child_id, $badge_key );
    }

    /* ─── Read ──────────────────────────────────────────────────── */

    public static function has_badge( int $child_id, string $key ): bool {
        if ( ! class_exists( 'RKP_BadgeQueryService' ) ) return false;
        return RKP_BadgeQueryService::has_badge( $child_id, $key );
    }

    public static function count_badges_this_month( int $child_id ): int {
        if ( ! class_exists( 'RKP_BadgeQueryService' ) ) return 0;
        return RKP_BadgeQueryService::count_this_month( $child_id );
    }

    public static function get_badges( int $child_id ): array {
        if ( ! class_exists( 'RKP_BadgeQueryService' ) ) return [];
        return RKP_BadgeQueryService::get_badges( $child_id );
    }

    public static function get_catalogue_for_child( int $child_id ): array {
        if ( ! class_exists( 'RKP_BadgeQueryService' ) ) return [];
        return RKP_BadgeQueryService::get_catalogue_for_child( $child_id );
    }
}