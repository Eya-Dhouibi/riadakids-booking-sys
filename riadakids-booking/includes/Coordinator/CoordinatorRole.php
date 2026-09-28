<?php
/**
 * RiadaKids\Coordinator\CoordinatorRole — Phase 3
 *
 * Rôle « منسق المواعيد » (rk_coordinator) + capabilities dédiées.
 *
 * - Les coachs restent `tutor_instructor` : aucun rôle rk_coach n'est créé.
 * - Le rôle ne reçoit AUCUNE capability WordPress d'édition/administration
 *   (pas de manage_options, edit_posts, delete_posts, manage_woocommerce) :
 *   uniquement `read` + les 7 capabilities rk_* ci-dessous.
 * - Le rôle Administrator reçoit les mêmes 7 capabilities (et rien d'autre)
 *   afin que « Administrator → PASS » repose sur une vraie capability et non
 *   sur un contournement manage_options dans chaque endpoint.
 * - La synchronisation est versionnée (option) : une seule écriture en base
 *   par version, aucune écriture à chaque requête.
 *
 * @package RiadaKids\Coordinator
 */

namespace RiadaKids\Coordinator;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class CoordinatorRole {

    public const ROLE         = 'rk_coordinator';
    public const ROLE_LABEL   = 'منسق المواعيد';
    public const SCHEMA_OPT   = 'rk_coordinator_role_v';
    public const SCHEMA_VER   = '1';

    public const CAP_VIEW_REQUESTS  = 'rk_view_booking_requests';
    public const CAP_VIEW_DETAILS   = 'rk_view_booking_details';
    public const CAP_ASSIGN_COACH   = 'rk_assign_booking_coach';
    public const CAP_SCHEDULE       = 'rk_schedule_booking';
    public const CAP_RESCHEDULE     = 'rk_reschedule_booking';
    public const CAP_MANAGE_CHANGES = 'rk_manage_change_requests';
    public const CAP_VIEW_COACHES   = 'rk_view_coach_availability';

    /** @return string[] Les 7 capabilities du workflow Coordinateur. */
    public static function capabilities(): array {
        return [
            self::CAP_VIEW_REQUESTS,
            self::CAP_VIEW_DETAILS,
            self::CAP_ASSIGN_COACH,
            self::CAP_SCHEDULE,
            self::CAP_RESCHEDULE,
            self::CAP_MANAGE_CHANGES,
            self::CAP_VIEW_COACHES,
        ];
    }

    /** Capabilities du rôle : `read` + les 7 rk_*. Rien d'autre. */
    public static function role_caps(): array {
        $caps = [ 'read' => true ];
        foreach ( self::capabilities() as $cap ) {
            $caps[ $cap ] = true;
        }
        return $caps;
    }

    public static function register(): void {
        add_action( 'init', [ self::class, 'maybe_sync' ], 5 );
    }

    /** Crée/met à jour le rôle une seule fois par version de schéma. */
    public static function maybe_sync(): void {
        if ( get_option( self::SCHEMA_OPT ) === self::SCHEMA_VER ) return;
        self::sync();
        update_option( self::SCHEMA_OPT, self::SCHEMA_VER );
    }

    public static function sync(): void {
        $role = get_role( self::ROLE );
        if ( ! $role ) {
            add_role( self::ROLE, self::ROLE_LABEL, self::role_caps() );
            $role = get_role( self::ROLE );
        }
        if ( $role ) {
            foreach ( self::role_caps() as $cap => $grant ) {
                if ( ! $role->has_cap( $cap ) ) $role->add_cap( $cap, $grant );
            }
        }
        $admin = get_role( 'administrator' );
        if ( $admin ) {
            foreach ( self::capabilities() as $cap ) {
                if ( ! $admin->has_cap( $cap ) ) $admin->add_cap( $cap );
            }
        }
    }
}
