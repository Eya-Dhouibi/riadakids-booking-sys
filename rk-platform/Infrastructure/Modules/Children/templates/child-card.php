<?php
/**
 * Template : Carte enfant individuelle  (v5.1.0 — UX Refonte)
 *
 *
 * @since 5.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$avatar_url    = rk_mc_get_avatar_url( $child );
$dashboard_url = RK_MC_Child_Context::get_dashboard_url( $child->id );
$booking_url   = RK_MC_Child_Context::get_booking_url( $child->id );
$created_date  = date_i18n( get_option( 'date_format' ), strtotime( $child->created_at ) );
?>
<div class="rk-child-card-final"
     data-id="<?php echo esc_attr( $child->id ); ?>"
     data-name="<?php echo esc_attr( $child->child_name ); ?>"
     data-family="<?php echo esc_attr( $child->child_family_name ?? '' ); ?>"
     data-age="<?php echo esc_attr( $child->child_age ); ?>"
     data-avatar="<?php echo esc_attr( $child->avatar_url ); ?>"
     data-dashboard="<?php echo esc_url( $dashboard_url ); ?>">

    <!-- ═══ Bouton More Actions ⋮ (coin sup. droit) ═══ -->
    <div class="rk-more-actions">
        <a type="button"
                class="rk-more-btn"
                aria-label="<?php esc_attr_e( 'المزيد من الإجراءات', 'rk-my-children' ); ?>"
                aria-haspopup="true"
                aria-expanded="false">
            <span class="rk-more-dots" aria-hidden="true">⋮</span>
        </a>

        <div class="rk-more-dropdown" role="menu" aria-hidden="true">
            <!-- Modifier -->
            <a type="button"
                    class="rk-dropdown-item rk-edit-btn"
                    data-id="<?php echo esc_attr( $child->id ); ?>"
                    role="menuitem">
                <?php echo rk_mc_svg( 'edit' ); ?>
                <span><?php esc_html_e( 'تعديل', 'rk-my-children' ); ?></span>
            </a>

            <!-- Supprimer -->
            <a type="button"
                    class="rk-dropdown-item rk-dropdown-item--danger rk-delete-btn"
                    data-id="<?php echo esc_attr( $child->id ); ?>"
                    role="menuitem">
                <?php echo rk_mc_svg( 'delete' ); ?>
                <span><?php esc_html_e( 'حذف', 'rk-my-children' ); ?></span>
            </a>
        </div>
    </div><!-- /.rk-more-actions -->

    <!-- Avatar -->
    <div class="rk-avatar-circle">
        <img src="<?php echo esc_url( $avatar_url ); ?>"
             alt="<?php echo esc_attr( rk_mc_child_full_name( $child ) ); ?>"
             width="100" height="100"
             loading="lazy" decoding="async">
    </div>

    <!-- Nom -->
    <h3 class="rk-child-name"><?php echo esc_html( rk_mc_child_full_name( $child ) ); ?></h3>

    <!-- Meta (âge + date) -->
    <div class="rk-child-meta">
        <?php if ( ! empty( $child->child_age ) ) : ?>
        <span class="rk-meta-item">
            <?php echo rk_mc_svg( 'cake' ); ?>
            <?php echo esc_html( $child->child_age ); ?> <?php esc_html_e( 'سنة', 'rk-my-children' ); ?>
        </span>
        <?php endif; ?>
        <span class="rk-meta-item">
            <?php echo rk_mc_svg( 'calendar' ); ?>
            <?php echo esc_html( $created_date ); ?>
        </span>
    </div>

    <!-- ═══ CTA Principaux : toujours visibles ═══ -->
    <?php require __DIR__ . '/child-actions.php'; ?>

</div><!-- /.rk-child-card-final -->