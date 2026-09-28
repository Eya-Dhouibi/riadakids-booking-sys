<?php
declare( strict_types=1 );
/**
 * RK_Coach_Profile_Controller
 *
 * Paramètres du compte coach depuis la SPA /espace-coach/#settings :
 *   GET  /coach/profile          — infos actuelles (nom, email, téléphone, bio)
 *   POST /coach/profile          — mise à jour des infos
 *   POST /coach/password         — changement de mot de passe (vérifie l'actuel)
 *
 * Toute écriture passe par les API WordPress (wp_update_user / wp_set_password)
 * — jamais de SQL brut sur wp_users : le hash du mot de passe, les caches
 * utilisateur et les hooks (profile_update, password_reset…) restent cohérents.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class RK_Coach_Profile_Controller {

    /* ─── GET /coach/profile ────────────────────────────────────── */

    public static function get_profile(): WP_REST_Response {
        $user = wp_get_current_user();
        return new WP_REST_Response( [
            'id'         => $user->ID,
            'first_name' => (string) get_user_meta( $user->ID, 'first_name', true ),
            'last_name'  => (string) get_user_meta( $user->ID, 'last_name', true ),
            'display'    => (string) $user->display_name,
            'email'      => (string) $user->user_email,
            'phone'      => (string) get_user_meta( $user->ID, 'billing_phone', true ),
            'bio'        => (string) get_user_meta( $user->ID, 'description', true ),
            'avatar'     => get_avatar_url( $user->ID, [ 'size' => 96 ] ),
        ], 200 );
    }

    /* ─── POST /coach/profile ───────────────────────────────────── */

    public static function update_profile( WP_REST_Request $request ): WP_REST_Response {
        $user_id = get_current_user_id();

        $first = sanitize_text_field( (string) ( $request->get_param( 'first_name' ) ?? '' ) );
        $last  = sanitize_text_field( (string) ( $request->get_param( 'last_name' )  ?? '' ) );
        $email = sanitize_email(      (string) ( $request->get_param( 'email' )      ?? '' ) );
        $phone = sanitize_text_field( (string) ( $request->get_param( 'phone' )      ?? '' ) );
        $bio   = sanitize_textarea_field( (string) ( $request->get_param( 'bio' )    ?? '' ) );

        if ( $first === '' ) {
            return new WP_REST_Response( [ 'code' => 'first_name_required', 'message' => 'الاسم الأول مطلوب.' ], 400 );
        }

        // Email : format valide + non utilisé par un autre compte
        if ( $email !== '' ) {
            if ( ! is_email( $email ) ) {
                return new WP_REST_Response( [ 'code' => 'invalid_email', 'message' => 'البريد الإلكتروني غير صالح.' ], 400 );
            }
            $existing = email_exists( $email );
            if ( $existing && (int) $existing !== $user_id ) {
                return new WP_REST_Response( [ 'code' => 'email_taken', 'message' => 'هذا البريد مستخدم من حساب آخر.' ], 400 );
            }
        }

        $data = [
            'ID'           => $user_id,
            'first_name'   => $first,
            'last_name'    => $last,
            'display_name' => trim( $first . ' ' . $last ) ?: $first,
        ];
        if ( $email !== '' ) {
            $data['user_email'] = $email;
        }

        $result = wp_update_user( $data );
        if ( is_wp_error( $result ) ) {
            return new WP_REST_Response( [ 'code' => 'update_failed', 'message' => $result->get_error_message() ], 500 );
        }

        update_user_meta( $user_id, 'billing_phone', $phone );
        update_user_meta( $user_id, 'description',   $bio );

        // Journaliser si le module d'audit est présent
        if ( class_exists( 'RK_Audit_Log' ) && method_exists( 'RK_Audit_Log', 'log' ) ) {
            RK_Audit_Log::log( $user_id, 'profile_updated', 'coach_settings' );
        }

        return new WP_REST_Response( [
            'success' => true,
            'display' => $data['display_name'],
            'email'   => $email ?: wp_get_current_user()->user_email,
        ], 200 );
    }

    /* ─── POST /coach/password ──────────────────────────────────── */

    public static function change_password( WP_REST_Request $request ): WP_REST_Response {
        $user = wp_get_current_user();

        $current = (string) ( $request->get_param( 'current_password' ) ?? '' );
        $new     = (string) ( $request->get_param( 'new_password' )     ?? '' );
        $confirm = (string) ( $request->get_param( 'confirm_password' ) ?? '' );

        if ( $current === '' || $new === '' ) {
            return new WP_REST_Response( [ 'code' => 'missing_fields', 'message' => 'جميع الحقول مطلوبة.' ], 400 );
        }
        if ( $new !== $confirm ) {
            return new WP_REST_Response( [ 'code' => 'mismatch', 'message' => 'كلمتا المرور غير متطابقتين.' ], 400 );
        }
        if ( strlen( $new ) < 8 ) {
            return new WP_REST_Response( [ 'code' => 'too_short', 'message' => 'كلمة المرور يجب أن تكون 8 أحرف على الأقل.' ], 400 );
        }

        // Anti brute-force léger : 5 tentatives / 15 min par utilisateur
        $bf_key = 'rk_pw_attempts_' . $user->ID;
        $tries  = (int) get_transient( $bf_key );
        if ( $tries >= 5 ) {
            return new WP_REST_Response( [ 'code' => 'too_many_attempts', 'message' => 'محاولات كثيرة. حاول بعد 15 دقيقة.' ], 429 );
        }

        // Vérifier le mot de passe actuel
        if ( ! wp_check_password( $current, $user->user_pass, $user->ID ) ) {
            set_transient( $bf_key, $tries + 1, 15 * MINUTE_IN_SECONDS );
            return new WP_REST_Response( [ 'code' => 'wrong_password', 'message' => 'كلمة المرور الحالية غير صحيحة.' ], 403 );
        }
        delete_transient( $bf_key );

        // Mise à jour (hash + invalidation des sessions gérés par WP)
        wp_set_password( $new, $user->ID );

        // wp_set_password détruit les sessions — re-connecter l'utilisateur
        // courant pour ne pas le déconnecter brutalement de la SPA.
        wp_set_auth_cookie( $user->ID, true );

        if ( class_exists( 'RK_Audit_Log' ) && method_exists( 'RK_Audit_Log', 'log' ) ) {
            RK_Audit_Log::log( $user->ID, 'password_changed', 'coach_settings' );
        }

        return new WP_REST_Response( [ 'success' => true, 'message' => 'تم تغيير كلمة المرور بنجاح.' ], 200 );
    }
}
