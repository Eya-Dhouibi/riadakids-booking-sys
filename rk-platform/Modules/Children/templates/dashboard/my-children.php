<?php
/**
 * Template principal : My Children  (v5.2.0)
 *
 * @since 5.0.0
 * @updated 5.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* ── Données partagées entre les sous-templates ── */
$user_id  = get_current_user_id();
$user     = wp_get_current_user();
$children = rk_mc_get_children( $user_id );

$children_count = count( $children );
$bookings_count = rk_mc_count_bookings( $user_id );
$sessions_count = rk_mc_count_sessions( $user_id );
$credits        = rk_mc_get_session_credits( $user_id );

$parent_name = ! empty( $user->first_name ) ? $user->first_name : $user->display_name;

$tpl = RK_MC_DIR . 'templates/';
$mod = RK_MC_DIR . 'modals/';

?>


<div class="rk-children" dir="rtl">

    <?php require $tpl . 'child-header.php'; ?>

    <!-- ══ HEADER ROW ══ -->
    <div class="rk-list-header rk-children-section">
        <h2><?php esc_html_e( 'الأطفال المسجلون', 'rk-my-children' ); ?></h2>
    </div>

    <!-- ══ CHILDREN GRID ══ -->
    <?php
    /*
     * v5.2.0 — On utilise le Renderer au lieu du require direct.
     * Résultat identique côté HTML, mais le composant est partagé
     * avec le dashboard WooCommerce (et toute page future).
     *
     * v11.0 — $show_add_card = true : carte "طفل جديد" en tête de grille.
     */
    RK_MC_Child_Card_Renderer::render_grid( $children, 'rk-child-grid', true );
    ?>

    <?php require $mod . 'add-child-modal.php'; ?>

</div><!-- /.rk-children -->