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

    <!-- ══ HEADER ROW ══ -->
    <!-- v13.0 — Titre restylé avec rk-page-title (icône alignée sur
         riadakids-booking : /wp-content/plugins/riadakids-booking/assets/images/rk-page-title-icon.svg) -->
    <div class="rk-list-header rk-children-section">
        <div class="rk-page-title-group">
            <h2 class="rk-page-title"><?php esc_html_e( 'الأطفال', 'rk-my-children' ); ?></h2>
            <img decoding="async" class="rk-page-title-icon"
                 src="<?php echo esc_url( home_url( '/wp-content/plugins/riadakids-booking/assets/images/rk-page-title-icon.svg' ) ); ?>"
                 alt="" aria-hidden="true" width="40" height="40">
        </div>
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