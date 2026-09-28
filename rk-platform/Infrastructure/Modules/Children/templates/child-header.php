<?php
/**
 * Template : Hero Banner + Stats  (v6.0 — + KPI Progression)
 *
 * Variables attendues depuis my-children.php :
 *   $parent_name     string  Prénom du parent
 *   $children_count  int     Nombre d'enfants
 *   $bookings_count  int     Nombre de réservations confirmées
 *   $sessions_count  int     Nombre de séances terminées
 *   $credits         int     Crédits de session restants
 *   $children        array   Liste des enfants (pour calcul progression)
 *
 * @since 6.0.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/* ── Calcul progression moyenne LMS + alerte crédits ────────────── */
$overall_progress = 0;
$alert_credits    = (int) $credits < 2;

if ( ! empty( $children ) && class_exists( 'RKP_LearningQueryService' ) ) {
    $prog_sum   = 0;
    $prog_count = 0;
    foreach ( $children as $ch ) {
        $wp_uid = (int) ( $ch->wp_user_id ?? 0 );
        if ( ! $wp_uid ) continue;
        $enrolled = RKP_LearningQueryService::get_enrolled_course_ids( $wp_uid );
        if ( empty( $enrolled ) ) continue;
        $total = 0; $sum = 0;
        foreach ( $enrolled as $cid ) {
            $sum += (int) RKP_LearningQueryService::get_completed_percent( $cid, $wp_uid );
            $total++;
        }
        if ( $total ) { $prog_sum += round( $sum / $total ); $prog_count++; }
    }
    $overall_progress = $prog_count ? (int) round( $prog_sum / $prog_count ) : 0;
}

/* ── Prochaine séance (plus proche de tous les enfants) ─────────── */
$next_any = null;
if ( ! empty( $children ) ) {
    global $wpdb;
    $ids = implode( ',', array_map( fn( $c ) => (int) $c->id, $children ) );
    $next_any = $ids ? $wpdb->get_var(
        "SELECT CONCAT( appointment, '|', COALESCE( booking_id, 0 ) ) FROM {$wpdb->prefix}rk_bookings
          WHERE child_id IN ($ids) AND appointment > '" . current_time( 'mysql' ) . "'
            AND status != 'cancelled'
         ORDER BY appointment ASC LIMIT 1"
    ) : null;
}
// Format « datetime|booking_id » (get_var ne renvoie qu'une colonne).
$next_appt    = '';
$next_appt_id = 0;
if ( $next_any ) {
    $parts        = explode( '|', (string) $next_any );
    $next_appt    = $parts[0] ?? '';
    $next_appt_id = (int) ( $parts[1] ?? 0 );
}
$next_ts = $next_appt ? rk_mc_appt_timestamp( $next_appt ) : 0;
?>
<!-- ══ HERO BANNER ══ -->
<div class="rk-hero-banner<?php echo $alert_credits ? ' rk-hero-banner--alert' : ''; ?>">
    <div class="rk-hero-bg-shapes" aria-hidden="true">
        <span class="rk-hero-shape rk-hero-shape--1"></span>
        <span class="rk-hero-shape rk-hero-shape--2"></span>
        <span class="rk-hero-shape rk-hero-shape--3"></span>
    </div>
    <div class="rk-hero-content">

        <div class="rk-hero-title-group">
            <span class="rk-hero-icon" aria-hidden="true">
                <?php echo rk_mc_svg( 'hero-users' ); ?>
            </span>
            <div>
                <h1 class="rk-hero-title"><?php esc_html_e( 'أطفالي', 'rk-my-children' ); ?></h1>
                <p class="rk-hero-greeting">
                    <?php printf( esc_html__( 'مرحباً %s، إليك لوحة متابعة أطفالك', 'rk-my-children' ), esc_html( $parent_name ) ); ?>
                </p>
                <?php if ( $next_ts ) : ?>
                <p class="rk-hero-next-session" style="margin-top:6px;font-size:.82rem;color:rgba(255,255,255,.82);display:flex;align-items:center;gap:6px;">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                    <?php printf(
                        esc_html__( 'الجلسة القادمة: %s', 'rk-my-children' ),
                        esc_html( rk_mc_appt_format( $next_appt, 'j M — H:i', $next_appt_id ) )
                    ); ?>
                </p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Alerte crédits -->
        <?php if ( $alert_credits ) : ?>
        <div class="rk-hero-alert" role="alert">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
            <?php printf(
                wp_kses( __( 'رصيدك منخفض (%d جلسة متبقية). <a href="/shop/" style="color:#fff;font-weight:700;text-decoration:underline;">اشحن الآن</a>', 'rk-my-children' ), [ 'a' => [ 'href' => [], 'style' => [] ] ] ),
                (int) $credits
            ); ?>
        </div>
        <?php endif; ?>

    </div>
</div>
