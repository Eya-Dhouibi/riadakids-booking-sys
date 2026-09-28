<?php
declare( strict_types=1 );
/**
 * RK_Audit_Log — journal des actions sensibles (points manuels, badges, évaluations).
 *
 * Table : wp_rk_audit_log
 *
 * @package RK_Coach_Hub
 * @since   1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class RK_Audit_Log {

    private static function t(): string {
        global $wpdb;
        return $wpdb->prefix . 'rk_audit_log';
    }

    public static function maybe_create_table(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $cc = $wpdb->get_charset_collate();
        dbDelta( "CREATE TABLE " . self::t() . " (
            id               BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            action           VARCHAR(80)         NOT NULL DEFAULT '',
            actor_id         BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            actor_role       VARCHAR(40)         NOT NULL DEFAULT '',
            target_child_id  BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            meta             TEXT                NOT NULL,
            ip               VARCHAR(45)         NOT NULL DEFAULT '',
            created_at       DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_actor      (actor_id),
            KEY idx_child      (target_child_id),
            KEY idx_action     (action),
            KEY idx_created_at (created_at)
        ) {$cc};" );
    }

    /**
     * Enregistre une action sensible.
     *
     * @param string $action     Identifiant de l'action (manual_points, manual_badge, evaluation, mission_assign…)
     * @param int    $child_id   Enfant concerné.
     * @param array  $meta       Données contextuelles (points, note, badge_key…).
     */
    public static function record( string $action, int $child_id, array $meta = [] ): void {
        global $wpdb;

        $user       = wp_get_current_user();
        $actor_role = implode( ',', (array) $user->roles );
        $ip         = self::get_client_ip();

        $wpdb->insert( self::t(), [
            'action'          => sanitize_key( $action ),
            'actor_id'        => (int) $user->ID,
            'actor_role'      => $actor_role,
            'target_child_id' => $child_id,
            'meta'            => wp_json_encode( $meta ),
            'ip'              => $ip,
        ], [ '%s', '%d', '%s', '%d', '%s', '%s' ] );
    }

    private static function get_client_ip(): string {
        $keys = [ 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' ];
        foreach ( $keys as $k ) {
            if ( ! empty( $_SERVER[ $k ] ) ) {
                return sanitize_text_field( explode( ',', $_SERVER[ $k ] )[0] );
            }
        }
        return '';
    }
}
