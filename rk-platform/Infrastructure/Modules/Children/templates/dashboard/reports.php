<?php
declare( strict_types=1 );
/**
 * Template : التقارير — page ta9arir (vue parent)
 *
 * Tableau de bord parent : liste des enfants avec KPIs du mois,
 * lien vers le rapport complet (rk-rapport) de chaque enfant.
 *
 * @package RK_My_Children
 * @since   9.1.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$parent_id = get_current_user_id();
if ( ! $parent_id ) return;

global $wpdb;

/* ── Période ─────────────────────────────────────────────────────── */
$year  = (int) ( $_GET['year']  ?? date( 'Y' ) );
$month = (int) ( $_GET['month'] ?? (int) date( 'm' ) );
$year  = max( 2023, min( (int) date( 'Y' ), $year ) );
$month = max( 1, min( 12, $month ) );

$month_start = sprintf( '%04d-%02d-01 00:00:00', $year, $month );
$month_end   = sprintf( '%04d-%02d-%02d 23:59:59', $year, $month, (int) date( 't', mktime( 0, 0, 0, $month, 1, $year ) ) );

$month_names_ar = [ 1=>'يناير',2=>'فبراير',3=>'مارس',4=>'أبريل',5=>'مايو',6=>'يونيو',
                    7=>'يوليو',8=>'أغسطس',9=>'سبتمبر',10=>'أكتوبر',11=>'نوفمبر',12=>'ديسمبر' ];
$period_label = ( $month_names_ar[ $month ] ?? '' ) . ' ' . $year;
$base_url = home_url( add_query_arg( [] ) );

/* ── Enfants du parent ───────────────────────────────────────────── */
$children = $wpdb->get_results( $wpdb->prepare(
    "SELECT * FROM " . rk_mc_children_table() . "
      WHERE user_id = %d ORDER BY child_name ASC",
    $parent_id
) ) ?: [];

/* ── KPIs du mois par enfant ─────────────────────────────────────── */
$child_stats = [];
foreach ( $children as $c ) {
    $cid = (int) $c->id;

    $sessions_count = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}rk_bookings
          WHERE child_id = %d AND appointment BETWEEN %s AND %s",
        $cid, $month_start, $month_end
    ) );

    $points = class_exists( 'RK_MC_Gamification_Service' )
        ? (int) RK_MC_Gamification_Service::get_total_points( $cid )
        : 0;
    $level = class_exists( 'RK_MC_Gamification_Service' )
        ? RK_MC_Gamification_Service::compute_level( $points )
        : [ 'num' => 1, 'label' => '' ];

    $lms_progress = 0;
    $wp_uid = (int) ( $c->wp_user_id ?? 0 );
    if ( $wp_uid && class_exists( 'RKP_LearningQueryService' ) ) {
        $enrolled = RKP_LearningQueryService::get_enrolled_course_ids( $wp_uid ) ?: [];
        if ( $enrolled ) {
            $sum = 0;
            foreach ( $enrolled as $course_id ) {
                $sum += (int) RKP_LearningQueryService::get_completed_percent( $course_id, $wp_uid );
            }
            $lms_progress = (int) round( $sum / count( $enrolled ) );
        }
    }

    $rapport_url = home_url( RK_TUTOR_DASHBOARD_URL . 'rk-rapport/' );
    if ( $wp_uid && class_exists( 'RK_Session_Manager' ) ) {
        $tab = RK_Session_Manager::create_tab_session( $wp_uid, $parent_id );
        $rapport_url = add_query_arg( [ 'rk_tab' => $tab, 'year' => $year, 'month' => $month ], $rapport_url );
    }

    $child_stats[ $cid ] = [
        'child'         => $c,
        'sessions'      => $sessions_count,
        'points'        => $points,
        'level_num'     => (int) ( $level['num'] ?? 1 ),
        'level_label'   => $level['label'] ?? '',
        'lms_progress'  => $lms_progress,
        'rapport_url'   => $rapport_url,
    ];
}

/* ── Navigation mois ─────────────────────────────────────────────── */
$prev_m = $month === 1 ? 12 : $month - 1;
$prev_y = $month === 1 ? $year - 1 : $year;
$next_m = $month === 12 ? 1 : $month + 1;
$next_y = $month === 12 ? $year + 1 : $year;
$is_cur = $year === (int) date( 'Y' ) && $month === (int) date( 'm' );
?>
<div class="rk-section rk-section--ta9arir" dir="rtl">

    <!-- Header -->
    <div class="rk-section__header">
        <h2 class="rk-section__title">
            <?php echo wp_kses_post( rk_mc_svg( 'chart', [ 'class' => 'rk-section__title-icon' ] ) ); ?>
            <?php esc_html_e( 'تقارير الأبناء', 'rk-my-children' ); ?>
        </h2>

        <!-- Navigation mois -->
        <div class="rk-filters-bar" style="gap:8px;">
            <a href="<?php echo esc_url( add_query_arg( [ 'year' => $prev_y, 'month' => $prev_m ] ) ); ?>"
               class="rk-btn rk-btn--outline rk-btn--sm">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><polyline points="15 18 9 12 15 6"/></svg>
            </a>
            <span class="rk-period-label"><?php echo esc_html( $period_label ); ?></span>
            <?php if ( ! $is_cur ) : ?>
            <a href="<?php echo esc_url( add_query_arg( [ 'year' => $next_y, 'month' => $next_m ] ) ); ?>"
               class="rk-btn rk-btn--outline rk-btn--sm">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><polyline points="9 18 15 12 9 6"/></svg>
            </a>
            <?php endif; ?>
        </div>
    </div>

    <?php if ( empty( $child_stats ) ) : ?>
    <div class="rk-empty">
        <p><?php esc_html_e( 'لا يوجد أبناء مسجلون.', 'rk-my-children' ); ?></p>
    </div>
    <?php else : ?>

    <!-- Grille cartes enfants -->
    <div class="rk-ta9arir-grid">
        <?php foreach ( $child_stats as $cid => $st ) :
            $c       = $st['child'];
            $name    = esc_html( rk_mc_child_full_name( $c ) );
            $initial = esc_html( mb_substr( $name, 0, 1 ) );
            $avatar  = function_exists( 'rk_mc_get_avatar_url' ) ? rk_mc_get_avatar_url( $c ) : '';
        ?>
        <div class="rk-ta9arir-card">

            <!-- En-tête enfant -->
            <div class="rk-ta9arir-card__header">
                <div class="rk-ta9arir-card__avatar">
                    <?php if ( $avatar ) : ?>
                        <img src="<?php echo esc_url( $avatar ); ?>" alt="<?php echo $name; ?>" width="48" height="48">
                    <?php else : ?>
                        <span><?php echo $initial; ?></span>
                    <?php endif; ?>
                    <div class="rk-ta9arir-card__level-badge">
                        <?php echo (int) $st['level_num']; ?>
                    </div>
                </div>
                <div class="rk-ta9arir-card__info">
                    <strong><?php echo $name; ?></strong>
                    <span><?php echo esc_html( $st['level_label'] ?: __( 'مستكشف', 'rk-my-children' ) ); ?></span>
                </div>
            </div>

            <!-- KPIs du mois -->
            <div class="rk-ta9arir-card__kpis">
                <div class="rk-ta9arir-kpi">
                    <strong><?php echo (int) $st['sessions']; ?></strong>
                    <span><?php esc_html_e( 'جلسة', 'rk-my-children' ); ?></span>
                </div>
                <div class="rk-ta9arir-kpi">
                    <strong><?php echo number_format( (int) $st['points'] ); ?></strong>
                    <span>XP</span>
                </div>
                <div class="rk-ta9arir-kpi">
                    <strong><?php echo (int) $st['lms_progress']; ?>%</strong>
                    <span><?php esc_html_e( 'تقدم', 'rk-my-children' ); ?></span>
                </div>
            </div>

            <!-- Barre de progression LMS -->
            <div class="rk-progress-bar" style="margin:0 16px 12px;" title="<?php echo $st['lms_progress']; ?>%">
                <div class="rk-progress-bar__fill" style="width:<?php echo min( 100, (int) $st['lms_progress'] ); ?>%"></div>
            </div>

            <!-- Lien rapport complet -->
            <div class="rk-ta9arir-card__footer">
                <a href="<?php echo esc_url( $st['rapport_url'] ); ?>"
                   class="rk-btn rk-btn--primary rk-btn--sm" style="width:100%;justify-content:center;">
                    <?php echo wp_kses_post( rk_mc_svg( 'chart', [ 'class' => 'rk-btn__icon' ] ) ); ?>
                    <?php esc_html_e( 'التقرير الكامل', 'rk-my-children' ); ?>
                </a>
            </div>

        </div>
        <?php endforeach; ?>
    </div>

    <?php endif; ?>

</div>

<style>
.rk-ta9arir-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
    gap: 20px;
    padding: 4px 0 24px;
}
.rk-ta9arir-card {
    background: #fff;
    border-radius: 16px;
    border: 1px solid #e2e8f0;
    box-shadow: 0 1px 4px rgba(0,0,0,.06);
    overflow: hidden;
    transition: box-shadow .2s, transform .2s;
}
.rk-ta9arir-card:hover { box-shadow: 0 6px 20px rgba(0,0,0,.1); transform: translateY(-2px); }
.rk-ta9arir-card__header {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 16px;
    background: linear-gradient(135deg, rgba(232,80,10,.06), rgba(76,149,215,.06));
    border-bottom: 1px solid #f1f5f9;
}
.rk-ta9arir-card__avatar {
    position: relative;
    width: 48px;
    height: 48px;
    border-radius: 50%;
    overflow: hidden;
    flex-shrink: 0;
    background: var(--e-global-color-primary, #FF4411);
    display: flex; align-items: center; justify-content: center;
    font-size: 1.3rem; font-weight: 800; color: #fff;
}
.rk-ta9arir-card__avatar img { width: 100%; height: 100%; object-fit: cover; }
.rk-ta9arir-card__level-badge {
    position: absolute;
    bottom: -2px; right: -2px;
    background: var(--e-global-color-secondary, #4C95D7);
    color: #fff;
    border-radius: 10px;
    font-size: .65rem;
    font-weight: 700;
    min-width: 18px;
    height: 18px;
    line-height: 18px;
    text-align: center;
    padding: 0 3px;
    border: 2px solid #fff;
}
.rk-ta9arir-card__info strong { display: block; font-size: .95rem; font-weight: 800; color: #0f172a; }
.rk-ta9arir-card__info span   { font-size: .78rem; color: #64748b; }
.rk-ta9arir-card__kpis {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    border-bottom: 1px solid #f1f5f9;
}
.rk-ta9arir-kpi {
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 12px 8px;
    border-left: 1px solid #f1f5f9;
}
.rk-ta9arir-kpi:last-child { border-left: none; }
.rk-ta9arir-kpi strong { font-size: 1.1rem; font-weight: 800; color: var(--e-global-color-primary, #FF4411); }
.rk-ta9arir-kpi span   { font-size: .72rem; color: #64748b; text-align: center; }
.rk-ta9arir-card__footer { padding: 12px 16px; }
.rk-period-label { font-size: .9rem; font-weight: 700; min-width: 110px; text-align: center; color: #0f172a; }
.rk-filters-bar--wrap { flex-wrap: wrap; gap: 8px; }
.rk-filters-bar__group { display: flex; flex-wrap: wrap; gap: 6px; }
@media (max-width: 640px) {
    .rk-ta9arir-grid { grid-template-columns: 1fr; }
}
</style>
