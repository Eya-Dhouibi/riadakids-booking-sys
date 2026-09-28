<?php
declare( strict_types=1 );
/**
 * RK_MC_Assessment_Service  (v8.0.0 — Adaptateur)
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * ADAPTATEUR vers RKP_AssessmentCommandService / RKP_AssessmentQueryService.
 *
 * Interface publique préservée à l'identique.
 *
 * Restent dans cette classe (logique présentation/UI) :
 *   - radar_svg()  : générateur SVG pur (aucun appel DB)
 *   - for_child()  : transformation de données pour la vue enfant
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * @package RK_My_Children
 * @since   8.0.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class RK_MC_Assessment_Service {

    /* ─── Read ──────────────────────────────────────────────────── */

    public static function get_for_child_date( int $child_id, string $date, int $coach_id ): ?array {
        if ( ! class_exists( 'RKP_AssessmentQueryService' ) ) return null;
        return RKP_AssessmentQueryService::get_for_child_date( $child_id, $date, $coach_id );
    }

    /** Bilan d'une séance précise (booking_id) — voir RKP_AssessmentQueryService::get_for_booking(). */
    public static function get_for_booking( int $booking_id ): ?array {
        if ( ! class_exists( 'RKP_AssessmentQueryService' ) ) return null;
        return RKP_AssessmentQueryService::get_for_booking( $booking_id );
    }

    public static function get_latest( int $child_id ): ?array {
        if ( ! class_exists( 'RKP_AssessmentQueryService' ) ) return null;
        return RKP_AssessmentQueryService::get_latest( $child_id );
    }

    public static function get_all( int $child_id, bool $skip_first = false ): array {
        if ( ! class_exists( 'RKP_AssessmentQueryService' ) ) return [];
        return RKP_AssessmentQueryService::get_all( $child_id, $skip_first );
    }

    public static function get_by_id( int $id ): ?array {
        if ( ! class_exists( 'RKP_AssessmentQueryService' ) ) return null;
        return RKP_AssessmentQueryService::get_by_id( $id );
    }

    /* ─── Write ─────────────────────────────────────────────────── */

    public static function create( array $data ): int {
        if ( ! class_exists( 'RKP_AssessmentCommandService' ) ) return 0;
        return RKP_AssessmentCommandService::create( $data );
    }

    public static function update( int $id, array $data ): bool {
        if ( ! class_exists( 'RKP_AssessmentCommandService' ) ) return false;
        return RKP_AssessmentCommandService::update( $id, $data );
    }

    public static function delete( int $id ): bool {
        if ( ! class_exists( 'RKP_AssessmentCommandService' ) ) return false;
        return RKP_AssessmentCommandService::delete( $id );
    }

    public static function create_or_update( int $child_id, string $date, int $coach_id, array $data ): int {
        if ( ! class_exists( 'RKP_AssessmentCommandService' ) ) return 0;
        return RKP_AssessmentCommandService::create_or_update( $child_id, $date, $coach_id, $data );
    }

    /** Crée/met à jour le bilan d'une séance précise (booking_id). Voir RKP_AssessmentCommandService. */
    public static function create_or_update_for_booking(
        int $booking_id, int $child_id, string $date, int $coach_id, array $data
    ): int {
        if ( ! class_exists( 'RKP_AssessmentCommandService' ) ) return 0;
        return RKP_AssessmentCommandService::create_or_update_for_booking( $booking_id, $child_id, $date, $coach_id, $data );
    }

    /* ─── Format (délégué) ──────────────────────────────────────── */

    public static function format( object $row ): array {
        if ( class_exists( 'RKP_AssessmentQueryService' ) ) {
            return RKP_AssessmentQueryService::format( $row );
        }
        return [];
    }

    /* ─── Vue enfant (logique présentation — reste ici) ─────────── */

    public static function for_child( array $bilan ): array {
        $summary_sentences = explode( '.', $bilan['summary'] ?? '' );
        $short_summary     = trim( $summary_sentences[0] ?? '' );
        if ( $short_summary ) $short_summary .= '.';

        return [
            'id'             => (int) $bilan['id'],
            'assessed_at'    => $bilan['assessed_at'],
            'rating'         => (int) $bilan['rating'],
            'coach_name'     => $bilan['coach_name'],
            'summary'        => $short_summary,
            'strengths'      => array_slice( (array) ( $bilan['strengths'] ?? [] ), 0, 2 ),
            'goal'           => (string) ( ( $bilan['developments'] ?? [] )[0] ?? '' ),
            'radar_svg'      => self::radar_svg( (array) ( $bilan['skill_scores'] ?? [] ), [], 180 ),
            'parent_message' => $bilan['parent_message'] ?? '',
        ];
    }

    /* ─── Radar SVG (logique présentation pure — reste ici) ─────── */

    public static function radar_svg( array $current, array $previous = [], int $size = 220 ): string {
        $cache_key = 'rk_radar_' . md5( serialize( $current ) . serialize( $previous ) . $size );
        $cached    = get_transient( $cache_key );
        if ( $cached !== false ) return $cached;

        $skills = [ 'speech', 'teamwork', 'creativity', 'courage', 'leadership', 'focus' ];
        $labels = [ 'الكلام', 'التعاون', 'الأفكار', 'الشجاعة', 'القيادة', 'التركيز' ];
        $n      = count( $skills );
        $cx     = $size / 2;
        $cy     = $size / 2;
        $r      = $size * 0.32;
        $max    = 5;

        $sanitize_scores = static function( array $scores ) use ( $skills, $max ): array {
            $out = array_fill_keys( $skills, 0 );
            foreach ( $skills as $k ) {
                if ( isset( $scores[ $k ] ) ) {
                    $out[ $k ] = max( 0, min( $max, (float) $scores[ $k ] ) );
                }
            }
            return $out;
        };
        $current  = $sanitize_scores( $current );
        $previous = $sanitize_scores( $previous );

        $coord = function( int $i, float $s ) use ( $cx, $cy, $r, $n, $max ): array {
            $angle = -M_PI / 2 + ( $i * 2 * M_PI / $n );
            $dist  = max( 0.02, $s / $max ) * $r;
            return [
                round( $cx + $dist * cos( $angle ), 2 ),
                round( $cy + $dist * sin( $angle ), 2 ),
            ];
        };

        $poly_pts = function( array $scores ) use ( $skills, $coord ): string {
            $pts = [];
            foreach ( $skills as $i => $k ) {
                [ $x, $y ] = $coord( $i, (float) ( $scores[ $k ] ?? 0 ) );
                $pts[] = $x . ',' . $y;
            }
            return implode( ' ', $pts );
        };

        $o = '<svg viewBox="0 0 ' . $size . ' ' . $size . '" width="' . $size . '" height="' . $size . '" xmlns="http://www.w3.org/2000/svg">';

        for ( $lv = 1; $lv <= $max; $lv++ ) {
            $bg = [];
            for ( $i = 0; $i < $n; $i++ ) {
                [ $bx, $by ] = $coord( $i, $lv );
                $bg[] = $bx . ',' . $by;
            }
            $o .= '<polygon points="' . implode( ' ', $bg ) . '" fill="none" stroke="rgba(0,0,0,.07)" stroke-width=".6"/>';
        }

        for ( $i = 0; $i < $n; $i++ ) {
            [ $ax, $ay ] = $coord( $i, $max );
            $o .= '<line x1="' . $cx . '" y1="' . $cy . '" x2="' . $ax . '" y2="' . $ay . '" stroke="rgba(0,0,0,.08)" stroke-width=".6"/>';
        }

        if ( ! empty( $previous ) ) {
            $o .= '<polygon points="' . $poly_pts( $previous ) . '" fill="rgba(27,79,140,.12)" stroke="rgba(27,79,140,.55)" stroke-width="1.5" stroke-dasharray="3,2"/>';
        }

        $o .= '<polygon points="' . $poly_pts( $current ) . '" fill="rgba(232,80,10,.14)" stroke="rgba(232,80,10,.85)" stroke-width="2"/>';

        foreach ( $skills as $i => $k ) {
            [ $dx, $dy ] = $coord( $i, (float) ( $current[ $k ] ?? 0 ) );
            $o .= '<circle cx="' . $dx . '" cy="' . $dy . '" r="3.5" fill="rgba(232,80,10,.9)" stroke="#fff" stroke-width="1.2"/>';
        }

        for ( $i = 0; $i < $n; $i++ ) {
            $angle  = -M_PI / 2 + ( $i * 2 * M_PI / $n );
            $ld     = $r + 20;
            $lx     = round( $cx + $ld * cos( $angle ), 1 );
            $ly     = round( $cy + $ld * sin( $angle ), 1 );
            $anchor = $lx < $cx - 4 ? 'end' : ( $lx > $cx + 4 ? 'start' : 'middle' );
            $o .= '<text x="' . $lx . '" y="' . $ly . '" text-anchor="' . $anchor
                . '" dominant-baseline="middle" font-size="9.5" fill="#64748b">' . esc_html( $labels[ $i ] ) . '</text>';
        }

        $o .= '</svg>';
        set_transient( $cache_key, $o, DAY_IN_SECONDS );
        return $o;
    }
}
