<?php
/**
 * Template : Boutons d'action d'une carte enfant  (v5.4.1)
 *
 * Inclus par templates/components/child-card.php.
 *
 * Variables héritées :
 *   $child          object  Ligne wp_rk_children
 *   $dashboard_url  string  URL Tutor LMS dashboard (/dashboard/?child_id=X)
 *   $booking_url    string  URL réservation
 *
 * v5.4.1 : Suppression du bouton "فضاء child" redondant.
 *   Le bouton Dashboard existant (carte déjà créée) gère la navigation
 *   vers le dashboard Tutor LMS — pas besoin d'un second bouton.
 *
 * @since 5.4.1
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<div class="rk-child-actions">

    <!-- Dashboard Tutor LMS -->
    <a href="<?php echo esc_url( $dashboard_url ); ?>"
       class="rk-action-btn rk-action-btn--dashboard"
       title="<?php esc_attr_e( 'لوحة المتابعة', 'rk-my-children' ); ?>">
        <?php echo rk_mc_svg( 'chart' ); ?>
        <span><?php esc_html_e( 'Dashboard', 'rk-my-children' ); ?></span>
    </a>

    <!-- Réservation -->
    <a href="<?php echo esc_url( $booking_url ); ?>"
       class="rk-action-btn rk-action-btn--book"
       title="<?php esc_attr_e( 'حجز حصة', 'rk-my-children' ); ?>">
        <?php echo rk_mc_svg( 'book' ); ?>
        <span><?php esc_html_e( 'حجز', 'rk-my-children' ); ?></span>
    </a>

</div><!-- /.rk-child-actions -->
