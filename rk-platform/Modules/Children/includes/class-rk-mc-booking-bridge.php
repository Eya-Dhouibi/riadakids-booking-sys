<?php
declare( strict_types=1 );
/**
 * RK_MC_Booking_Bridge  (v8.0.0)
 *
 * Pont entre le plugin Booking (lecture seule) et :
 *   → Tutor LMS  (enrollment de l'enfant au cours lié)
 *   → BP Better Messages (création automatique des threads)
 *
 * AUCUNE modification du plugin Booking.
 *
 * DONNÉES DISPONIBLES SUR rk_booking_confirmed ($booking_id, $ctx) :
 *   $ctx->adventure_id   = Tutor LMS course_id  (déjà stocké dans le booking)
 *   $ctx->child_ids      = array [child_id]       (IDs dans wp_rk_children)
 *   $ctx->event_id       = SSA appointment type   (clé → coach wp_user)
 *   $ctx->user_id        = parent wp_user_id
 *
 * CONFIGURATION ADMIN :
 *   Option WordPress : rk_ssa_coach_map = [ ssa_type_id => coach_wp_user_id ]
 *   Page admin : RK My Children → Coach → SSA
 *
 * @package RK_My_Children
 * @since   8.0.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/* Traits — factorisation par fonctionnalité (logique inchangée) */
require_once __DIR__ . '/traits/trait-rk-mc-booking-bridge-hooks.php';
require_once __DIR__ . '/traits/trait-rk-mc-booking-bridge-admin.php';

class RK_MC_Booking_Bridge {
    use RK_MC_Booking_Bridge_Hooks;
    use RK_MC_Booking_Bridge_Admin;


    const COACH_MAP_OPTION = 'rk_ssa_coach_map';

    /* ═══════════════════════════════════════════════════════════════════
       INIT
       ═══════════════════════════════════════════════════════════════════ */

    public static function init(): void {
        add_action( 'rk_booking_confirmed',        [ __CLASS__, 'on_confirmed'        ], 10, 2 );
        add_action( 'rk_booking_confirmed',        [ __CLASS__, 'stamp_coach_id'      ], 12, 2 );
        add_action( 'rk_booking_rescheduled',      [ __CLASS__, 'on_rescheduled'      ], 10, 3 );
        add_action( 'rk_booking_cancelled',        [ __CLASS__, 'on_cancelled'        ], 10, 2 );
        add_action( 'rk_child_enrolled',           [ __CLASS__, 'on_child_enrolled_notify' ], 20, 4 );
        add_action( 'tutor_lesson_completed_after', [ __CLASS__, 'on_lesson_completed' ], 20 );

        if ( is_admin() ) {
            add_action( 'admin_menu',                   [ __CLASS__, 'register_admin_page' ] );
            add_action( 'admin_post_rk_save_coach_map', [ __CLASS__, 'handle_save'         ] );

            // Champ SSA sur le profil utilisateur (rôle tutor_instructor uniquement)
            add_action( 'show_extra_profile_fields',  [ __CLASS__, 'render_profile_ssa_field' ] );
            add_action( 'edit_user_profile',          [ __CLASS__, 'render_profile_ssa_field' ] );
            add_action( 'personal_options_update',    [ __CLASS__, 'save_profile_ssa_field'  ] );
            add_action( 'edit_user_profile_update',   [ __CLASS__, 'save_profile_ssa_field'  ] );
        }
    }

}

