<?php
declare( strict_types=1 );
/**
 * RiadaKids — Partial : grille des séances + pagination (/my-account/rk-rapport/)
 *
 * AJOUT (demande utilisateur) — extrait de rk-rapport.php pour être
 * réutilisé tel quel par :
 *   1. le rendu initial de la page (inclusion directe) ;
 *   2. l'endpoint AJAX rk_rapport_filter_sessions (RK_MC_Rapport_Ajax),
 *      pour que le changement de filtre "برنامج" et de pagination se fasse
 *      sans rechargement de page — même HTML produit dans les deux cas.
 *
 * Variables attendues en scope (toutes déjà calculées par l'appelant) :
 *   $rk_sessions_page   array   séances de la page courante (après filtre + pagination)
 *   $rk_total_pages     int
 *   $rk_paged           int
 *   $rk_session_evals   array   [booking_id => bilan|null]
 *   $rk_icon_cycle      array
 *   $rkd4_icon          callable (défini par templates/dashboard/partials/rkd4-icons.php)
 *   $base_url           string  URL de base /my-account/rk-rapport/ (avec child_id)
 *   $child_id           int
 *
 * @package RK_My_Children
 * @since   10.2.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;
?>
<?php if ( $rk_sessions_page ) : ?>
<div class="rp-session-grid">
    <?php foreach ( $rk_sessions_page as $rk_sess_i => $s ) :
        $course     = trim( (string) ( $s->course_name   ?? '' ) );
        $coach      = trim( (string) ( $s->coach_resolved ?? '' ) );
        $s_coach_id = (int) ( $s->coach_id ?? 0 );
        $s_booking_id_key = (int) ( $s->booking_id ?? 0 );
        $s_eval     = $rk_session_evals[ $s_booking_id_key ] ?? null;
        $coach_avatar = $s_coach_id ? get_avatar_url( $s_coach_id, [ 'size' => 40 ] ) : '';
        $card_icon    = $rk_icon_cycle[ $rk_sess_i % count( $rk_icon_cycle ) ];
    ?>
    <div class="rp-session-card">
        <div class="rp-session-card__body">
            <div class="rp-session-card__top">
                <div class="rp-session-card__titles">
                    <h4 class="rp-session-card__title"><?php echo esc_html( $s->session_name ?: __( 'لقاء', 'rk-my-children' ) ); ?></h4>
                    <?php if ( $course ) : ?>
                    <p class="rp-session-card__subtitle"><?php echo esc_html( $course ); ?></p>
                    <?php endif; ?>
                </div>
                <span class="rp-session-card__icon" aria-hidden="true">
                    <?php echo $rkd4_icon( $card_icon, 22 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                </span>
            </div>
            <?php if ( $coach ) : ?>
            <div class="rp-session-card__coach">
                <?php if ( $coach_avatar ) : ?>
                <img src="<?php echo esc_url( $coach_avatar ); ?>" alt="" width="40" height="40" loading="lazy">
                <?php endif; ?>
                <span><?php echo esc_html( $coach ); ?></span>
            </div>
            <?php endif; ?>
        </div>
        <a href="<?php echo esc_url( add_query_arg( [
            'child_id'   => $child_id,
            'booking_id' => (int) ( $s->booking_id ?? 0 ),
        ], $base_url ) ); ?>" class="rp-session-card__btn">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><polyline points="9 18 15 12 9 6"/></svg>
            <?php esc_html_e( 'عرض التقرير', 'rk-my-children' ); ?>
        </a>
    </div>
    <?php endforeach; ?>
</div>

<?php if ( $rk_total_pages > 1 ) : ?>
<nav class="rp-pagination" aria-label="<?php esc_attr_e( 'تصفح صفحات التقارير', 'rk-my-children' ); ?>">
    <a href="<?php echo esc_url( add_query_arg( 'rp_page', max( 1, $rk_paged - 1 ) ) ); ?>"
       data-rp-page="<?php echo (int) max( 1, $rk_paged - 1 ); ?>"
       class="rp-pagination__nav<?php echo 1 === $rk_paged ? ' is-disabled' : ''; ?>"
       aria-label="<?php esc_attr_e( 'السابق', 'rk-my-children' ); ?>">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" aria-hidden="true"><polyline points="15 18 9 12 15 6"/></svg>
    </a>
    <?php for ( $rk_p = 1; $rk_p <= $rk_total_pages; $rk_p++ ) : ?>
    <a href="<?php echo esc_url( add_query_arg( 'rp_page', $rk_p ) ); ?>"
       data-rp-page="<?php echo (int) $rk_p; ?>"
       class="rp-pagination__page<?php echo $rk_p === $rk_paged ? ' active' : ''; ?>">
        <?php echo (int) $rk_p; ?>
    </a>
    <?php endfor; ?>
    <a href="<?php echo esc_url( add_query_arg( 'rp_page', min( $rk_total_pages, $rk_paged + 1 ) ) ); ?>"
       data-rp-page="<?php echo (int) min( $rk_total_pages, $rk_paged + 1 ); ?>"
       class="rp-pagination__nav<?php echo $rk_paged === $rk_total_pages ? ' is-disabled' : ''; ?>"
       aria-label="<?php esc_attr_e( 'التالي', 'rk-my-children' ); ?>">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" aria-hidden="true"><polyline points="9 18 15 12 9 6"/></svg>
    </a>
</nav>
<?php endif; ?>

<?php else : ?>
<p class="rp-empty-text"><?php esc_html_e( 'لا توجد لقاءات لهذا البرنامج هذا الشهر', 'rk-my-children' ); ?></p>
<?php endif; ?>
