<?php
declare( strict_types=1 );
/**
 * RiadaKids — Tutor Dashboard: التقرير الشهري
 * v1.0 — Rapport PDF/Print complet : séances, présence, compétences, évaluations.
 *
 * @package RK_My_Children
 * @since   9.0.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$child = RK_MC_Tutor_Dashboard::get_child_for_template();
if ( ! $child ) {
    echo '<p dir="rtl">' . esc_html__( 'يرجى تحديد الطفل أولاً.', 'rk-my-children' ) . '</p>';
    return;
}
$child_id    = (int) $child->id;
$child_wp_id = (int) ( $child->wp_user_id ?? 0 );
$child_name  = $child->child_name ?? '';
$avatar_url  = rk_mc_get_avatar_url( $child );

global $wpdb;

/* ── Période (mois sélectionné) ──────────────────────────────────── */
$year  = (int) ( $_GET['year']  ?? date( 'Y' ) );
$month = (int) ( $_GET['month'] ?? (int) date( 'm' ) );
$year  = max( 2023, min( (int) date( 'Y' ), $year ) );
$month = max( 1, min( 12, $month ) );

$month_start = sprintf( '%04d-%02d-01 00:00:00', $year, $month );
$month_end   = sprintf( '%04d-%02d-%02d 23:59:59', $year, $month, (int) date( 't', mktime( 0, 0, 0, $month, 1, $year ) ) );
$base_url    = home_url( add_query_arg( [] ) );
$print_mode  = isset( $_GET['print'] );

$month_names_ar = [ 1=>'يناير',2=>'فبراير',3=>'مارس',4=>'أبريل',5=>'مايو',6=>'يونيو',7=>'يوليو',8=>'أغسطس',9=>'سبتمبر',10=>'أكتوبر',11=>'نوفمبر',12=>'ديسمبر' ];
$period_label = ( $month_names_ar[ $month ] ?? '' ) . ' ' . $year;

/* ── Données du mois ────────────────────────────────────────────── */
$sessions = $wpdb->get_results( $wpdb->prepare(
    "SELECT * FROM {$wpdb->prefix}rk_bookings
      WHERE child_id = %d AND appointment BETWEEN %s AND %s
   ORDER BY appointment ASC",
    $child_id, $month_start, $month_end
) ) ?: [];

$total_sessions  = count( $sessions );
$present_count   = 0;
$absent_count    = 0;
$late_count      = 0;
foreach ( $sessions as $s ) {
    $att = $s->attendance ?? '';
    if ( 'present' === $att )    { $present_count++; }
    elseif ( 'absent' === $att ) { $absent_count++; }
    elseif ( 'late' === $att )   { $late_count++; }
}
$recorded_count = $present_count + $absent_count + $late_count;
$attn_rate      = $recorded_count > 0 ? (int) round( $present_count / $recorded_count * 100 ) : 0;

/* ── Résultats des tests (Tutor LMS — wp_tutor_quiz_attempts) ──── */
$quiz_results = [];
if ( $child_wp_id ) {
    $quiz_results = $wpdb->get_results( $wpdb->prepare(
        "SELECT qa.quiz_id, p.post_title AS quiz_title,
                qa.total_marks, qa.earned_marks,
                ROUND( qa.earned_marks / GREATEST( qa.total_marks, 1 ) * 100 ) AS score_pct,
                qa.attempt_ended_at AS attempted_at
           FROM {$wpdb->prefix}tutor_quiz_attempts qa
      LEFT JOIN {$wpdb->posts} p ON p.ID = qa.quiz_id
          WHERE qa.user_id = %d AND qa.attempt_status = 'attempt_ended'
            AND qa.attempt_ended_at BETWEEN %s AND %s
       ORDER BY qa.attempt_ended_at DESC",
        $child_wp_id, $month_start, $month_end
    ) ) ?: [];
}

/* ── Compétences actuelles ─────────────────────────────────────── */
$skills = class_exists( 'RK_MC_Skill_Service' ) ? RK_MC_Skill_Service::get_skills( $child_id ) : [];

/* ── Dernière évaluation du mois ──────────────────────────────── */
$last_eval = null;
if ( class_exists( 'RK_MC_Assessment_Service' ) ) {
    $all_evals = RK_MC_Assessment_Service::get_all( $child_id );
    foreach ( $all_evals as $ev ) {
        if ( ! empty( $ev['assessed_at'] ) && $ev['assessed_at'] >= $month_start && $ev['assessed_at'] <= $month_end ) {
            $last_eval = $ev;
            break;
        }
    }
}

/* ── LMS progression ──────────────────────────────────────────── */
$lms_progress = 0;
$enrolled = [];
if ( $child_wp_id && class_exists( 'RKP_LearningQueryService' ) ) {
    $enrolled = RKP_LearningQueryService::get_enrolled_course_ids( $child_wp_id ) ?: [];
    if ( $enrolled ) {
        $sum = 0;
        foreach ( $enrolled as $cid ) {
            $sum += (int) RKP_LearningQueryService::get_completed_percent( $cid, $child_wp_id );
        }
        $lms_progress = (int) round( $sum / count( $enrolled ) );
    }
}
?>
<div class="rk-td-page-rapport" dir="rtl">

    <?php if ( ! $print_mode ) : ?>
    <!-- ── Header page normale ──────────────────────────────────── -->
    <div class="rk-rapport-topbar">
        <h2 class="rk-td-page-title">
            <?php echo wp_kses_post( rk_mc_svg( 'chart', [ 'class' => 'rk-td-page-title__icon' ] ) ); ?>
            <?php esc_html_e( 'التقرير الشهري', 'rk-my-children' ); ?>
        </h2>
        <div class="rk-rapport-topbar__actions">
            <!-- Navigation mois -->
            <?php
            $prev_m = $month === 1 ? 12 : $month - 1;
            $prev_y = $month === 1 ? $year - 1 : $year;
            $next_m = $month === 12 ? 1 : $month + 1;
            $next_y = $month === 12 ? $year + 1 : $year;
            $is_cur = $year === (int) date( 'Y' ) && $month === (int) date( 'm' );
            ?>
            <a href="<?php echo esc_url( add_query_arg( [ 'year' => $prev_y, 'month' => $prev_m ] ) ); ?>"
               class="rk-btn rk-btn--outline rk-btn--sm">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><polyline points="15 18 9 12 15 6"/></svg>
            </a>
            <span style="font-size:.9rem;font-weight:700;min-width:120px;text-align:center;"><?php echo esc_html( $period_label ); ?></span>
            <?php if ( ! $is_cur ) : ?>
            <a href="<?php echo esc_url( add_query_arg( [ 'year' => $next_y, 'month' => $next_m ] ) ); ?>"
               class="rk-btn rk-btn--outline rk-btn--sm">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><polyline points="9 18 15 12 9 6"/></svg>
            </a>
            <?php endif; ?>
            <!-- Bouton imprimer -->
            <a href="<?php echo esc_url( add_query_arg( [ 'year' => $year, 'month' => $month, 'print' => '1' ] ) ); ?>"
               target="_blank" class="rk-btn rk-btn--primary rk-btn--sm">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
                <?php esc_html_e( 'طباعة PDF', 'rk-my-children' ); ?>
            </a>
        </div>
    </div>
    <?php else : ?>
    <!-- ── Bandeau impression ─────────────────────────────────── -->
    <div class="rk-rapport-print-bar no-print">
        <button onclick="window.print()" style="background:#E8500A;color:#fff;border:none;padding:9px 24px;border-radius:8px;font-family:inherit;font-size:.9rem;font-weight:700;cursor:pointer;">
            <?php esc_html_e( 'طباعة / حفظ PDF', 'rk-my-children' ); ?>
        </button>
        <button onclick="window.close()" style="background:transparent;border:1.5px solid #e2e8f0;padding:9px 20px;border-radius:8px;font-family:inherit;font-size:.9rem;cursor:pointer;">
            <?php esc_html_e( 'إغلاق', 'rk-my-children' ); ?>
        </button>
    </div>
    <?php endif; ?>

    <!-- ══════════════════════════════════════════════════════════
         RAPPORT IMPRIMABLE
         ══════════════════════════════════════════════════════════ -->
    <div class="rk-rapport-doc">

        <!-- En-tête rapport -->
        <div class="rk-rapport-header">
            <div class="rk-rapport-header__brand">
                <span class="rk-rapport-header__logo">RiadaKids</span>
                <span class="rk-rapport-header__type"><?php esc_html_e( 'تقرير متابعة شهري', 'rk-my-children' ); ?></span>
            </div>
            <div class="rk-rapport-header__child">
                <img src="<?php echo esc_url( $avatar_url ); ?>" alt="<?php echo esc_attr( $child_name ); ?>"
                     class="rk-rapport-header__avatar" width="56" height="56">
                <div>
                    <h2 class="rk-rapport-header__name"><?php echo esc_html( $child_name ); ?></h2>
                    <p class="rk-rapport-header__period"><?php echo esc_html( $period_label ); ?></p>
                </div>
            </div>
        </div>

        <!-- KPIs résumé -->
        <div class="rk-rapport-kpis">
            <?php
            $kpis = [
                [ 'label' => __( 'لقاءات', 'rk-my-children' ), 'val' => $total_sessions, 'color' => '#1b4f8c', 'bg' => '#eff6ff' ],
                [ 'label' => __( 'الحضور',   'rk-my-children' ), 'val' => $attn_rate . '%', 'color' => $attn_rate >= 80 ? '#166534' : '#b91c1c', 'bg' => $attn_rate >= 80 ? '#dcfce7' : '#fee2e2' ],
                [ 'label' => __( 'الغياب',   'rk-my-children' ), 'val' => $absent_count, 'color' => '#b91c1c', 'bg' => '#fee2e2' ],
                [ 'label' => __( 'التقدم',   'rk-my-children' ), 'val' => $lms_progress . '%', 'color' => '#e8500a', 'bg' => '#fff7ed' ],
            ];
            foreach ( $kpis as $kpi ) :
            ?>
            <div class="rk-rapport-kpi" style="border-top:3px solid <?php echo esc_attr( $kpi['color'] ); ?>;">
                <span class="rk-rapport-kpi__val" style="color:<?php echo esc_attr( $kpi['color'] ); ?>;"><?php echo esc_html( $kpi['val'] ); ?></span>
                <span class="rk-rapport-kpi__label"><?php echo esc_html( $kpi['label'] ); ?></span>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Tableau des séances -->
        <div class="rk-rapport-section">
            <h3 class="rk-rapport-section__title"><?php esc_html_e( 'سجل لقاءات', 'rk-my-children' ); ?></h3>
            <table class="rk-rapport-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'التاريخ', 'rk-my-children' ); ?></th>
                        <th><?php esc_html_e( 'الوقت', 'rk-my-children' ); ?></th>
                        <th><?php esc_html_e( 'اسم الجلسة', 'rk-my-children' ); ?></th>
                        <th><?php esc_html_e( 'الحضور', 'rk-my-children' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( $sessions ) :
                        foreach ( $sessions as $s ) :
                            $appt_id = (int) ( $s->booking_id ?? 0 );
                            $ts      = rk_mc_appt_timestamp( $s->appointment ?? '' );
                            $attendance_labels = [
                                'present' => [ 'label' => __( 'حضر', 'rk-my-children' ),    'color' => '#166534', 'bg' => '#dcfce7' ],
                                'absent'  => [ 'label' => __( 'غاب', 'rk-my-children' ),    'color' => '#b91c1c', 'bg' => '#fee2e2' ],
                                'late'    => [ 'label' => __( 'تأخر', 'rk-my-children' ),   'color' => '#92400e', 'bg' => '#fef3c7' ],
                            ];
                            $att = $attendance_labels[ $s->attendance ?? '' ] ?? [ 'label' => __( 'غير مسجل', 'rk-my-children' ), 'color' => '#64748b', 'bg' => '#f1f5f9' ];
                    ?>
                    <tr>
                        <td><?php echo $ts ? esc_html( rk_mc_appt_format( $s->appointment, 'j/m/Y', $appt_id ) ) : '—'; ?></td>
                        <td><?php echo $ts ? esc_html( rk_mc_appt_format( $s->appointment, 'H:i',   $appt_id ) ) : '—'; ?></td>
                        <td><?php echo esc_html( $s->session_name ?: __( 'جلسة', 'rk-my-children' ) ); ?></td>
                        <td>
                            <span style="padding:2px 10px;border-radius:20px;font-size:.78rem;font-weight:700;
                                         color:<?php echo esc_attr( $att['color'] ); ?>;background:<?php echo esc_attr( $att['bg'] ); ?>;">
                                <?php echo esc_html( $att['label'] ); ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; else : ?>
                    <tr>
                        <td colspan="4" style="text-align:center;color:#94a3b8;padding:20px;">
                            <?php esc_html_e( 'لا توجد جلسات هذا الشهر', 'rk-my-children' ); ?>
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Résultats des tests (quiz) -->
        <div class="rk-rapport-section">
            <h3 class="rk-rapport-section__title"><?php esc_html_e( 'نتائج الاختبارات', 'rk-my-children' ); ?></h3>
            <table class="rk-rapport-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'التاريخ', 'rk-my-children' ); ?></th>
                        <th><?php esc_html_e( 'الاختبار', 'rk-my-children' ); ?></th>
                        <th><?php esc_html_e( 'النتيجة', 'rk-my-children' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( $quiz_results ) :
                        foreach ( $quiz_results as $q ) :
                            $qts         = strtotime( $q->attempted_at ?? '' );
                            $score       = (int) $q->score_pct;
                            $score_color = $score >= 80 ? '#166534' : ( $score >= 50 ? '#92400e' : '#b91c1c' );
                            $score_bg    = $score >= 80 ? '#dcfce7' : ( $score >= 50 ? '#fef3c7' : '#fee2e2' );
                    ?>
                    <tr>
                        <td><?php echo $qts ? esc_html( date_i18n( 'j/m/Y', $qts ) ) : '—'; ?></td>
                        <td><?php echo esc_html( $q->quiz_title ?: __( 'اختبار', 'rk-my-children' ) ); ?></td>
                        <td>
                            <span style="padding:2px 10px;border-radius:20px;font-size:.78rem;font-weight:700;
                                         color:<?php echo esc_attr( $score_color ); ?>;background:<?php echo esc_attr( $score_bg ); ?>;">
                                <?php echo (int) $q->earned_marks; ?>/<?php echo (int) $q->total_marks; ?> (<?php echo $score; ?>%)
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; else : ?>
                    <tr>
                        <td colspan="3" style="text-align:center;color:#94a3b8;padding:20px;">
                            <?php esc_html_e( 'لا توجد اختبارات هذا الشهر', 'rk-my-children' ); ?>
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Compétences -->
        <?php if ( $skills ) : ?>
        <div class="rk-rapport-section rk-rapport-section--2col">
            <h3 class="rk-rapport-section__title"><?php esc_html_e( 'مستوى المهارات', 'rk-my-children' ); ?></h3>
            <div class="rk-rapport-skills">
                <?php foreach ( $skills as $s ) :
                    $level = (int) ( $s['level'] ?? 0 );
                    $max   = (int) ( $s['max']   ?? 10 );
                    $pct   = $max ? (int) round( $level / $max * 100 ) : 0;
                    $color = $pct >= 80 ? '#166534' : ( $pct >= 50 ? '#92400e' : '#1b4f8c' );
                ?>
                <div class="rk-rapport-skill-row">
                    <span class="rk-rapport-skill-name"><?php echo esc_html( $s['name'] ?? $s['key'] ); ?></span>
                    <div style="flex:1;height:7px;background:#f1f5f9;border-radius:4px;overflow:hidden;">
                        <div style="height:100%;width:<?php echo $pct; ?>%;background:<?php echo esc_attr( $color ); ?>;border-radius:4px;"></div>
                    </div>
                    <span style="min-width:32px;font-size:.8rem;font-weight:700;color:<?php echo esc_attr( $color ); ?>;text-align:left;"><?php echo $level; ?>/<?php echo $max; ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Dernière évaluation du mois -->
        <div class="rk-rapport-section">
            <h3 class="rk-rapport-section__title"><?php esc_html_e( 'ملاحظة المدرب', 'rk-my-children' ); ?></h3>
            <?php if ( $last_eval ) : ?>
            <div class="rk-rapport-eval">
                <?php if ( ! empty( $last_eval['rating'] ) ) : ?>
                <div style="display:flex;gap:3px;margin-bottom:8px;">
                    <?php for ( $i = 1; $i <= 5; $i++ ) : ?>
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="<?php echo $i <= (int) $last_eval['rating'] ? '#f59e0b' : '#e2e8f0'; ?>" aria-hidden="true"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                    <?php endfor; ?>
                </div>
                <?php endif; ?>
                <?php if ( ! empty( $last_eval['summary'] ) ) : ?>
                <p class="rk-rapport-eval__text"><?php echo esc_html( $last_eval['summary'] ); ?></p>
                <?php endif; ?>
                <?php if ( ! empty( $last_eval['strengths'] ) && is_array( $last_eval['strengths'] ) ) : ?>
                <div class="rk-rapport-eval__strengths">
                    <strong><?php esc_html_e( 'نقاط القوة:', 'rk-my-children' ); ?></strong>
                    <ul>
                        <?php foreach ( $last_eval['strengths'] as $point ) : ?>
                        <li><?php echo esc_html( $point ); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>
                <?php if ( ! empty( $last_eval['developments'] ) && is_array( $last_eval['developments'] ) ) : ?>
                <div class="rk-rapport-eval__developments">
                    <strong><?php esc_html_e( 'نقاط للتطوير:', 'rk-my-children' ); ?></strong>
                    <ul>
                        <?php foreach ( $last_eval['developments'] as $point ) : ?>
                        <li><?php echo esc_html( $point ); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>
                <?php if ( ! empty( $last_eval['parent_message'] ) ) : ?>
                <div class="rk-rapport-eval__remark">
                    <strong><?php esc_html_e( 'رسالة لولي الأمر:', 'rk-my-children' ); ?></strong>
                    <p><?php echo esc_html( $last_eval['parent_message'] ); ?></p>
                </div>
                <?php elseif ( ! empty( $last_eval['notes'] ) ) : ?>
                <div class="rk-rapport-eval__remark">
                    <strong><?php esc_html_e( 'ملاحظات:', 'rk-my-children' ); ?></strong>
                    <p><?php echo esc_html( $last_eval['notes'] ); ?></p>
                </div>
                <?php endif; ?>
            </div>
            <?php else : ?>
            <p style="color:#94a3b8;padding:16px 0;font-size:.9rem;">
                <?php esc_html_e( 'لا تتوفر ملاحظات من المدرب هذا الشهر', 'rk-my-children' ); ?>
            </p>
            <?php endif; ?>
        </div>

        <!-- Pied de page -->
        <div class="rk-rapport-footer">
            <span>RiadaKids © <?php echo date( 'Y' ); ?></span>
            <span><?php echo esc_html( sprintf( __( 'تقرير %s', 'rk-my-children' ), $period_label ) ); ?></span>
            <span><?php echo esc_html( sprintf( __( 'تاريخ الإصدار: %s', 'rk-my-children' ), date_i18n( 'j/m/Y' ) ) ); ?></span>
        </div>

    </div><!-- /.rk-rapport-doc -->

</div>
<?php if ( $print_mode ) : ?>
<style>
@media print {
    .no-print, .rk-rapport-topbar, .rk-rapport-print-bar { display: none !important; }
    body { background: #fff !important; }
    .rk-rapport-doc { box-shadow: none !important; border: none !important; }
}
</style>
<script>
window.addEventListener('load', function(){ setTimeout(function(){ window.print(); }, 500); });
</script>
<?php endif; ?>
