<?php
/**
 * RiadaKids\Core\Security
 *
 * Centralise les vérifications de sécurité (nonce, capabilities).
 *
 * BUG8 — Chaque endpoint AJAX dispose de :
 *   1. check_ajax_referer() via verify_*_request()
 *   2. is_user_logged_in() ou current_user_can() selon le contexte
 */

namespace RiadaKids\Core;

if ( ! defined( 'ABSPATH' ) ) exit;

class Security {

    /**
     * BUG8 — Vérifie nonce booking + connexion.
     */
    public static function verify_booking_request(): void {
        if ( ! check_ajax_referer( 'rk_booking_nonce', 'nonce', false ) ) {
            wp_send_json_error( [ 'msg' => 'invalid_nonce' ], 403 );
        }
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'msg' => 'not_logged_in' ], 401 );
        }
    }

    /**
     * BUG8 — Vérifie nonce reward + connexion.
     */
    public static function verify_reward_request(): void {
        if ( ! check_ajax_referer( 'rk_reward_nonce', 'nonce', false ) ) {
            wp_send_json_error( [ 'msg' => 'invalid_nonce' ], 403 );
        }
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'msg' => 'not_logged_in' ], 401 );
        }
    }

    /**
     * BUG8 — Vérifie nonce admin + capability.
     */
    public static function verify_admin_request(): void {
        if ( ! check_ajax_referer( 'rk_admin_nonce', 'nonce', false ) ) {
            wp_send_json_error( [ 'msg' => 'invalid_nonce' ], 403 );
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'msg' => 'forbidden' ], 403 );
        }
    }

    /**
     * Pour les pages admin (non AJAX).
     */
    public static function require_admin(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'غير مصرح', 403 );
        }
    }

    /* ── Nonces ─────────────────────────────────────────────────── */

    public static function booking_nonce(): string {
        return wp_create_nonce( 'rk_booking_nonce' );
    }

    public static function reward_nonce(): string {
        return wp_create_nonce( 'rk_reward_nonce' );
    }

    public static function admin_nonce(): string {
        return wp_create_nonce( 'rk_admin_nonce' );
    }
}