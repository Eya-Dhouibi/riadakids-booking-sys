<?php
declare( strict_types=1 );
/**
 * Application — AdminCommandService  (Write Model)
 *
 * Actions d'administration (back-office, CLI, imports).
 * Phase 1 : stubs documentés.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class RKP_AdminCommandService {

    /**
     * Importer un cours depuis une source externe.
     * Phase 2 : intégration Tutor LMS import API.
     */
    public static function import_course( array $data, int $admin_id ): ?int {
        // Phase 1 — stub. Retourne null (cours non créé).
        return null;
    }

    /**
     * Recalculer les stats de progression de tous les élèves d'un cours.
     * Phase 2 : batch job via WP-CLI ou Action Scheduler.
     */
    public static function recompute_progress( int $course_id, int $admin_id ): int {
        // Phase 1 — stub. Retourne 0 élèves retraités.
        return 0;
    }

    /**
     * Désactiver un compte enfant (RGPD / retrait parental).
     * Phase 2 : anonymisation des données personnelles.
     */
    public static function deactivate_child( int $child_id, int $admin_id, string $reason ): bool {
        // Phase 1 — stub.
        return true;
    }
}
