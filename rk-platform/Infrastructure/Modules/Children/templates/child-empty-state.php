<?php
/**
 * Template : État vide — aucun enfant enregistré  (v5.0.0)
 *
 * Affiché quand $children est vide dans my-children.php.
 * Aussi généré dynamiquement côté JS (child-delete.js) après suppression
 * du dernier enfant. Le balisage doit être identique dans les deux cas.
 *
 * @since 5.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<div class="rk-empty-msg" id="rk-empty-msg">
    <span class="rk-empty-icon">
        <?php echo rk_mc_svg( 'empty' ); ?>
    </span>
    <p><?php esc_html_e( 'لا يوجد أطفال مسجلون حالياً.', 'rk-my-children' ); ?></p>
    <p class="rk-empty-sub"><?php esc_html_e( 'أضف طفلك الأول للبدء في حجز الحصص.', 'rk-my-children' ); ?></p>
</div>
