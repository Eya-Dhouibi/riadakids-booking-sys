<?php
/**
 * Plugin Name:  RiadaKids Booking
 * Plugin URI:   https://riadakids.com
 * Description:  Wizard de réservation multi-événements SSA — WooCommerce endpoint /my-account/book-session/
 * Version:      4.18.0
 * Author:       RiadaKids
 * Text Domain:  riadakids
 * Domain Path:  /languages
 * Requires PHP: 8.0
 * Requires at least: 6.0
 *
 * @package RiadaKids
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'RK_VERSION',    '4.18.0' );  // FIX MAJEUR (bug signalé, logs fournis) — double remboursement de crédit sur DEUX bookings DIFFÉRENTS pour un même appt_id (quelques secondes d'écart, confirmé par les logs : balance=21 puis balance=22). Cause exacte trouvée : SSA déclenche à la fois les hooks 'ssa/appointment/canceled' ET 'ssa/appointment/cancelled' pour une seule transition de statut (BookingHooks::register(), les deux branchés sur le même callback) — l'appel update() de cancel_on_ssa() (v4.17.0) déclenche cette même chaîne de hooks, donc cancel_from_ssa() était appelée deux fois. Entre les deux appels, BookingRepository::find_by_appointment_id() (ORDER BY FIELD(post_status,'publish',...) ASC) retrouvait une ligne DIFFÉRENTE : le 1er appel annule le vrai booking (passe à 'cancelled', descend dans le tri), le 2e en retrouve un AUTRE encore en 'publish' si appointment_id est un appointment DE TEST réutilisé (confirmé dans les sessions précédentes) et le rembourse à tort. Double correction : (1) cancel_on_ssa()/cancel_from_ssa() acceptent désormais un $known_booking_id optionnel — le chemin client (BookingEditAjax::ajax_cancel()) transmet le VRAI booking_id sans ambiguïté, éliminant la recherche approximative pour ce chemin ; (2) transient de déduplication (30s) ajouté dans BookingHooks::on_ssa_canceled() ET handle_rest_webhook(), empêchant qu'un doublon de hook/webhook (canceled+cancelled) ne déclenche cancel_from_ssa() deux fois pour le même appt_id, peu importe quel booking il retrouverait.
define( 'RK_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'RK_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

spl_autoload_register( static function ( string $class ): void {
    if ( strpos( $class, 'RiadaKids\\' ) !== 0 ) return;
    $rel  = substr( $class, strlen( 'RiadaKids\\' ) );
    $file = RK_PLUGIN_DIR . 'includes/' . str_replace( '\\', '/', $rel ) . '.php';
    if ( file_exists( $file ) ) require_once $file;
} );

if ( ! function_exists( 'rk_log' ) ) {
    function rk_log( string $ctx, string $msg, string $level = 'info' ): void {
        if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
            error_log( "[RK/{$ctx}][{$level}] {$msg}" );
        }
    }
}

add_action( 'plugins_loaded', static function (): void {
    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', static function (): void {
            echo '<div class="notice notice-error"><p><strong>RiadaKids Booking :</strong> WooCommerce est requis.</p></div>';
        } );
        return;
    }
    \RiadaKids\Core\Plugin::get_instance()->boot();
} );

register_activation_hook( __FILE__, static function (): void {
    \RiadaKids\Core\Plugin::register_endpoint();
    flush_rewrite_rules();
    \RiadaKids\Database\Install::run();
    \RiadaKids\Database\Migrations::run();
    if ( defined( 'RK_SSA_APPT_TYPE_ID' ) && ! get_option( 'rk_ssa_events' ) ) {
        update_option( 'rk_ssa_events', [ [
            'ssa_id' => (int) RK_SSA_APPT_TYPE_ID, 'title' => 'Événement importé',
            'mentor' => '', 'active' => true, 'sort_order' => 0,
        ] ] );
    }
    update_option( 'rk_plugin_version', RK_VERSION );
    delete_option( 'rk_rewrite_version' ); // Force flush au prochain boot
} );

register_deactivation_hook( __FILE__, static function (): void {
    flush_rewrite_rules();
} );