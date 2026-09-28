<?php
declare( strict_types=1 );
/**
 * Infrastructure — CertificateRepository
 *
 * Seule couche autorisée à lire/écrire dans wp_rk_child_certificates.
 * child_id ici = rk_children.id (legacy ID).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class RKP_CertificateRepository {

    private static function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'rk_child_certificates';
    }

    /** Garantit que la table rk_child_certificates existe. */
    public static function maybe_create_table(): void {
        global $wpdb;
        $t = self::table();
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) === $t ) return;

        if ( function_exists( 'rk_coach_ensure_certificates_table' ) ) {
            rk_coach_ensure_certificates_table();
        } else {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            dbDelta( "CREATE TABLE {$t} (
                id           BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                child_id     BIGINT(20) UNSIGNED NOT NULL,
                coach_id     BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
                title        VARCHAR(255)        NOT NULL DEFAULT '',
                description  TEXT                NOT NULL,
                file_url     VARCHAR(500)        NOT NULL DEFAULT '',
                file_name    VARCHAR(255)        NOT NULL DEFAULT '',
                issued_at    DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY  (id),
                KEY child_idx (child_id)
            ) " . $wpdb->get_charset_collate() . ";" );
        }
    }

    /** Insère un certificat. Retourne l'ID inséré (0 = échec). */
    public static function insert( array $row ): int {
        global $wpdb;
        $ok = $wpdb->insert( self::table(), $row );
        return $ok ? (int) $wpdb->insert_id : 0;
    }

    /**
     * Certificats d'un enfant, du plus récent.
     *
     * @return object[]
     */
    public static function find_for_child( int $child_rk_id, int $limit = 20 ): array {
        global $wpdb;
        $t = self::table();
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) !== $t ) return [];
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$t} WHERE child_id = %d ORDER BY issued_at DESC LIMIT %d",
            $child_rk_id, $limit
        ) ) ?: [];
    }
}
