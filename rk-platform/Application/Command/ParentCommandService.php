<?php
declare( strict_types=1 );
/**
 * Application — ParentCommandService  (Write Model)
 *
 * Actions réservées au parent (tuteur légal).
 * Phase 1 : stubs documentés.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class RKP_ParentCommandService {

    /**
     * Inscrire un enfant à un cours (achat WooCommerce).
     * Phase 2 : émettra RKP_CourseStarted après la transaction.
     */
    public static function enroll_child( int $parent_id, int $child_id, int $course_id ): bool {
        // Phase 1 — stub.
        return true;
    }

    /**
     * Lier un enfant à un parent dans la session RK.
     * Délègue à RK_Session_Manager existant.
     */
    public static function link_child( int $parent_id, int $child_id ): bool {
        // Phase 1 — stub (RK_Session_Manager gère déjà ce cas).
        return true;
    }
}
