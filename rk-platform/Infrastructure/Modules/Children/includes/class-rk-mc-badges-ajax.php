<?php
declare( strict_types=1 );
/**
 * RK_MC_Badges_Ajax
 *
 * SEEN STATE -> is_new -> mark_as_seen (page شاراتي).
 *
 * Un seul endpoint : marquer un badge comme vu par l'enfant authentifié
 * courant. AUCUN child_id accepté depuis le client — le contexte enfant
 * est résolu exclusivement côté serveur, exactement comme
 * RK_MC_Guidance_Ajax::ajax_entry_story_get() déjà en production.
 *
 * @since 9.12.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;

final class RK_MC_Badges_Ajax {

    public static function init(): void {
        add_action( 'wp_ajax_rk_badge_mark_seen', [ __CLASS__, 'ajax_mark_seen' ] );
    }

    /**
     * POST rk_badge_mark_seen
     * Paramètres reçus du client : uniquement badge_key (+ nonce).
     * Le child_id n'est JAMAIS accepté depuis le client — résolu
     * exclusivement via RK_MC_Tutor_Dashboard::get_child_for_template().
     */
    public static function ajax_mark_seen(): void {
        check_ajax_referer( 'rk_experience_layer', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'code' => 'unauthenticated' ], 401 );
        }

        if ( ! class_exists( 'RK_MC_Tutor_Dashboard' ) || ! class_exists( 'RK_MC_Badge_Service' ) ) {
            wp_send_json_error( [ 'code' => 'unavailable' ], 500 );
        }

        $child = RK_MC_Tutor_Dashboard::get_child_for_template();
        if ( ! $child ) {
            wp_send_json_error( [ 'code' => 'no_child_context' ], 403 );
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce déjà vérifié ci-dessus.
        $badge_key = sanitize_key( $_POST['badge_key'] ?? '' );
        if ( '' === $badge_key ) {
            wp_send_json_error( [ 'code' => 'missing_badge_key' ], 400 );
        }

        $child_rk_id = (int) $child->id;

        $catalogue = RK_MC_Badge_Service::catalogue();
        if ( ! isset( $catalogue[ $badge_key ] ) ) {
            wp_send_json_error( [ 'code' => 'invalid_badge_key' ], 404 );
        }

        if ( ! RK_MC_Badge_Service::has_badge( $child_rk_id, $badge_key ) ) {
            wp_send_json_error( [ 'code' => 'badge_not_owned' ], 403 );
        }

        RK_MC_Badge_Service::mark_as_seen( $child_rk_id, $badge_key );

        // v9.12 — is_new lu depuis get_badges() (RKP_BadgeQueryService::
        // compute_is_new(), source de vérité UNIQUE), jamais recalculé
        // ici — une seconde formule locale aurait été exactement la
        // duplication de logique explicitement interdite.
        $badges  = RK_MC_Badge_Service::get_badges( $child_rk_id );
        $seen_at = null;
        $is_new  = false;
        foreach ( $badges as $b ) {
            if ( $b['key'] === $badge_key ) {
                $seen_at = $b['seen_at'];
                $is_new  = $b['is_new'];
                break;
            }
        }

        wp_send_json_success( [
            'badge_key' => $badge_key,
            'seen_at'   => $seen_at,
            'is_new'    => $is_new,
        ] );
    }
}
