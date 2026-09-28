<?php
declare( strict_types=1 );
/**
 * RK_Coach_Stats — Page Statistiques du coach.
 * v1.0 — Mensuel · Comparaison · Alertes qualité.
 *
 * @package RK_Coach_Hub
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class RK_Coach_Stats {

    public static function render(): void {
        $coach_id = get_current_user_id();

        // Mois sélectionné
        $year  = (int) ( $_GET['year']  ?? date( 'Y' ) );
        $month = (int) ( $_GET['month'] ?? (int) date( 'm' ) );
        $year  = max( 2023, min( (int) date( 'Y' ), $year ) );
        $month = max( 1, min( 12, $month ) );

        $prev_month = $month === 1 ? 12 : $month - 1;
        $prev_year  = $month === 1 ? $year - 1 : $year;

        $stats      = RK_Coach_Data::get_monthly_stats( $coach_id, $year, $month );
        $prev_stats = RK_Coach_Data::get_monthly_stats( $coach_id, $prev_year, $prev_month );
        $students   = RK_Coach_Data::get_coach_students( $coach_id );
        $base_url   = tutor_utils()->tutor_dashboard_url( 'rk-stats' );

        // Alertes qualité
        $quality_alerts = self::get_quality_alerts( $coach_id, $students );

        // Navigation mois
        $nav_prev = add_query_arg( [ 'year' => $prev_year, 'month' => $prev_month ], $base_url );
        $nav_next_month = $month === 12 ? 1 : $month + 1;
        $nav_next_year  = $month === 12 ? $year + 1 : $year;
        $nav_next       = add_query_arg( [ 'year' => $nav_next_year, 'month' => $nav_next_month ], $base_url );
        $is_current     = $year === (int) date( 'Y' ) && $month === (int) date( 'm' );

        $month_names_ar = [
            1 => 'يناير', 2 => 'فبراير', 3 => 'مارس', 4 => 'أبريل',
            5 => 'مايو', 6 => 'يونيو', 7 => 'يوليو', 8 => 'أغسطس',
            9 => 'سبتمبر', 10 => 'أكتوبر', 11 => 'نوفمبر', 12 => 'ديسمبر',
        ];

        // Données graphique barres (6 derniers mois)
        $chart_data = self::get_chart_data( $coach_id, $year, $month );
        ?>
        <div class="rk-coach-page" dir="rtl">

            <!-- Top bar -->
            <div class="rk-ch-topbar">
                <h2 class="rk-ch-topbar__title">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
                    <?php esc_html_e( 'الإحصائيات', 'rk-coach-hub' ); ?>
                </h2>
                <!-- Navigation mois -->
                <div class="rk-ch-topbar__actions">
                    <a href="<?php echo esc_url( $nav_prev ); ?>" class="rk-ch-btn rk-ch-btn--ghost rk-ch-btn--sm">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><polyline points="15 18 9 12 15 6"/></svg>
                    </a>
                    <span style="font-size:.88rem;font-weight:600;min-width:130px;text-align:center;">
                        <?php echo esc_html( ( $month_names_ar[ $month ] ?? '' ) . ' ' . $year ); ?>
                    </span>
                    <?php if ( ! $is_current ) : ?>
                    <a href="<?php echo esc_url( $nav_next ); ?>" class="rk-ch-btn rk-ch-btn--ghost rk-ch-btn--sm">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><polyline points="9 18 15 12 9 6"/></svg>
                    </a>
                    <?php else : ?>
                    <span class="rk-ch-btn rk-ch-btn--ghost rk-ch-btn--sm" style="opacity:.3;pointer-events:none;">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><polyline points="9 18 15 12 9 6"/></svg>
                    </span>
                    <?php endif; ?>
                </div>
            </div>

            <!-- KPIs du mois -->
            <div class="rk-ch-kpis rk-ch-kpis--4">
                <?php
                $kpis = [
                    [
                        'label' => __( 'لقاءات', 'rk-coach-hub' ),
                        'val'   => $stats['sessions'] ?? 0,
                        'prev'  => $prev_stats['sessions'] ?? 0,
                        'icon'  => '<rect x="3" y="4" width="18" height="18" rx="2"/><line x1="3" y1="10" x2="21" y2="10"/>',
                        'color' => 'var(--e-global-color-secondary,#1B4F8C)',
                        'bg'    => '#eff6ff',
                    ],
                    [
                        'label' => __( 'نسبة الحضور', 'rk-coach-hub' ),
                        'val'   => ( $stats['attendance_rate'] ?? 0 ) . '%',
                        'prev'  => ( $prev_stats['attendance_rate'] ?? 0 ) . '%',
                        'icon'  => '<polyline points="20 6 9 17 4 12"/>',
                        'color' => '#166534',
                        'bg'    => '#dcfce7',
                    ],
                    [
                        'label' => __( 'التقييمات', 'rk-coach-hub' ),
                        'val'   => $stats['evals_written'] ?? 0,
                        'prev'  => $prev_stats['evals_written'] ?? 0,
                        'icon'  => '<path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>',
                        'color' => 'var(--e-global-color-primary,#E8500A)',
                        'bg'    => '#fff7ed',
                    ],
                    [
                        'label' => __( 'رسائل الوالدين', 'rk-coach-hub' ),
                        'val'   => $stats['parent_messages'] ?? 0,
                        'prev'  => $prev_stats['parent_messages'] ?? 0,
                        'icon'  => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
                        'color' => '#7c3aed',
                        'bg'    => '#f5f3ff',
                    ],
                ];
                foreach ( $kpis as $kpi ) :
                    $val_num  = (int) filter_var( $kpi['val'], FILTER_SANITIZE_NUMBER_INT );
                    $prev_num = (int) filter_var( $kpi['prev'], FILTER_SANITIZE_NUMBER_INT );
                    $diff     = $val_num - $prev_num;
                    $trend    = $diff > 0 ? 'up' : ( $diff < 0 ? 'down' : 'same' );
                ?>
                <div class="rk-ch-kpi">
                    <div class="rk-ch-kpi__icon" style="background:<?php echo esc_attr( $kpi['bg'] ); ?>;color:<?php echo esc_attr( $kpi['color'] ); ?>;">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><?php echo $kpi['icon']; // phpcs:ignore ?></svg>
                    </div>
                    <div class="rk-ch-kpi__body">
                        <div class="rk-ch-kpi__val" style="color:<?php echo esc_attr( $kpi['color'] ); ?>;"><?php echo esc_html( $kpi['val'] ); ?></div>
                        <div class="rk-ch-kpi__label"><?php echo esc_html( $kpi['label'] ); ?></div>
                        <?php if ( $trend !== 'same' ) : ?>
                        <div class="rk-ch-kpi__trend rk-ch-kpi__trend--<?php echo $trend; ?>">
                            <?php if ( $trend === 'up' ) : ?>
                            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><polyline points="18 15 12 9 6 15"/></svg>
                            <?php else : ?>
                            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><polyline points="6 9 12 15 18 9"/></svg>
                            <?php endif; ?>
                            <?php echo abs( $diff ); ?> <?php esc_html_e( 'vs الشهر الماضي', 'rk-coach-hub' ); ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <div class="rk-ch-stats-cols">

                <!-- Graphique barres 6 mois -->
                <div class="rk-ch-section">
                    <div class="rk-ch-section-head">
                        <h3 class="rk-ch-section-title"><?php esc_html_e( 'لقاءات — آخر 6 أشهر', 'rk-coach-hub' ); ?></h3>
                    </div>
                    <div class="rk-ch-section-body">
                        <?php if ( empty( $chart_data ) ) : ?>
                        <p style="color:#94a3b8;font-size:.85rem;"><?php esc_html_e( 'لا بيانات بعد.', 'rk-coach-hub' ); ?></p>
                        <?php else :
                            $max_val = max( array_column( $chart_data, 'sessions' ) ) ?: 1;
                        ?>
                        <div class="rk-ch-bar-chart">
                            <?php foreach ( $chart_data as $cd ) :
                                $pct = round( ( $cd['sessions'] / $max_val ) * 100 );
                                $is_cur = $cd['month'] === $month && $cd['year'] === $year;
                            ?>
                            <div class="rk-ch-bar-chart__col">
                                <div class="rk-ch-bar-chart__bar-wrap">
                                    <div class="rk-ch-bar-chart__bar<?php echo $is_cur ? ' rk-ch-bar-chart__bar--current' : ''; ?>"
                                         style="height:<?php echo $pct; ?>%;" title="<?php echo $cd['sessions']; ?>"></div>
                                </div>
                                <span class="rk-ch-bar-chart__val"><?php echo $cd['sessions']; ?></span>
                                <span class="rk-ch-bar-chart__label"><?php echo esc_html( mb_substr( $month_names_ar[ $cd['month'] ] ?? '', 0, 3 ) ); ?></span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Tableau comparaison par élève -->
                <div class="rk-ch-section">
                    <div class="rk-ch-section-head">
                        <h3 class="rk-ch-section-title"><?php esc_html_e( 'الحضور حسب الطالب', 'rk-coach-hub' ); ?></h3>
                    </div>
                    <?php if ( empty( $students ) ) : ?>
                    <div class="rk-ch-section-body">
                        <p style="color:#94a3b8;font-size:.85rem;"><?php esc_html_e( 'لا يوجد طلاب.', 'rk-coach-hub' ); ?></p>
                    </div>
                    <?php else : ?>
                    <div class="rk-ch-table-wrap">
                        <table class="rk-ch-table">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e( 'الطالب', 'rk-coach-hub' ); ?></th>
                                    <th><?php esc_html_e( 'الحضور', 'rk-coach-hub' ); ?></th>
                                    <th><?php esc_html_e( 'الحالة', 'rk-coach-hub' ); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ( $students as $st ) :
                                    $sid   = (int) $st->child_id;
                                    $attn  = RK_Coach_Data::get_child_attendance_rate( $sid, $coach_id );
                                    $color = $attn >= 80 ? '#166534' : ( $attn >= 60 ? '#92400e' : '#b91c1c' );
                                    $bg    = $attn >= 80 ? '#dcfce7' : ( $attn >= 60 ? '#fef3c7' : '#fee2e2' );
                                    $fiche = add_query_arg( 'child_id', $sid, tutor_utils()->tutor_dashboard_url( 'rk-fiche-eleve' ) );
                                ?>
                                <tr>
                                    <td>
                                        <a href="<?php echo esc_url( $fiche ); ?>" style="font-weight:600;color:inherit;text-decoration:none;">
                                            <?php echo esc_html( $st->child_name ); ?>
                                        </a>
                                    </td>
                                    <td>
                                        <div style="display:flex;align-items:center;gap:8px;">
                                            <div style="flex:1;height:6px;background:#f1f5f9;border-radius:3px;overflow:hidden;min-width:60px;">
                                                <div style="height:100%;width:<?php echo $attn; ?>%;background:<?php echo esc_attr( $color ); ?>;border-radius:3px;"></div>
                                            </div>
                                            <span style="font-size:.82rem;font-weight:700;color:<?php echo esc_attr( $color ); ?>;"><?php echo $attn; ?>%</span>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if ( $attn >= 80 ) : ?>
                                        <span class="rk-ch-status rk-ch-status--present"><?php esc_html_e( 'ممتاز', 'rk-coach-hub' ); ?></span>
                                        <?php elseif ( $attn >= 60 ) : ?>
                                        <span class="rk-ch-status rk-ch-status--pending"><?php esc_html_e( 'متوسط', 'rk-coach-hub' ); ?></span>
                                        <?php else : ?>
                                        <span class="rk-ch-status rk-ch-status--absent"><?php esc_html_e( 'ضعيف', 'rk-coach-hub' ); ?></span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>

            </div><!-- /.rk-ch-stats-cols -->

            <!-- Alertes qualité -->
            <?php if ( ! empty( $quality_alerts ) ) : ?>
            <div class="rk-ch-section" style="margin-top:20px;">
                <div class="rk-ch-section-head">
                    <h3 class="rk-ch-section-title" style="color:#b91c1c;">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                        <?php esc_html_e( 'تنبيهات الجودة', 'rk-coach-hub' ); ?>
                    </h3>
                    <span class="rk-ch-pill rk-ch-pill--red"><?php echo count( $quality_alerts ); ?></span>
                </div>
                <div class="rk-ch-section-body" style="display:grid;gap:10px;">
                    <?php foreach ( $quality_alerts as $alert ) :
                        $fiche_url = add_query_arg( 'child_id', (int) $alert['child_id'], tutor_utils()->tutor_dashboard_url( 'rk-fiche-eleve' ) );
                    ?>
                    <div class="rk-ch-quality-alert">
                        <div class="rk-ch-quality-alert__icon">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                        </div>
                        <div class="rk-ch-quality-alert__body">
                            <a href="<?php echo esc_url( $fiche_url ); ?>" class="rk-ch-quality-alert__name">
                                <?php echo esc_html( $alert['child_name'] ); ?>
                            </a>
                            <span class="rk-ch-quality-alert__msg"><?php echo esc_html( $alert['message'] ); ?></span>
                        </div>
                        <a href="<?php echo esc_url( $alert['action_url'] ); ?>" class="rk-ch-btn rk-ch-btn--ghost rk-ch-btn--sm">
                            <?php echo esc_html( $alert['action_label'] ); ?>
                        </a>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

        </div>
        <?php
    }

    /* ─── Alertes qualité ───────────────────────────────────────────── */

    private static function get_quality_alerts( int $coach_id, array $students ): array {
        $alerts    = [];
        $today     = time();
        $three_weeks = 21 * DAY_IN_SECONDS;

        foreach ( $students as $st ) {
            $child_id   = (int) $st->child_id;
            $child_name = (string) $st->child_name;

            // Pas d'éval depuis 3 semaines
            $last_eval = RKP_AssessmentRepository::find_last_assessed_at_for_coach( $child_id, $coach_id );
            if ( ! $last_eval || ( $today - strtotime( $last_eval ) ) > $three_weeks ) {
                $alerts[] = [
                    'child_id'    => $child_id,
                    'child_name'  => $child_name,
                    'message'     => __( 'لم يُكتب أي تقييم منذ أكثر من ٣ أسابيع', 'rk-coach-hub' ),
                    'action_url'  => add_query_arg( 'child_id', $child_id, tutor_utils()->tutor_dashboard_url( 'rk-evaluer' ) ),
                    'action_label'=> __( 'تقييم', 'rk-coach-hub' ),
                ];
            }

            // Taux présence < 70%
            $attn = RK_Coach_Data::get_child_attendance_rate( $child_id, $coach_id );
            if ( $attn < 70 && $attn > 0 ) {
                $alerts[] = [
                    'child_id'    => $child_id,
                    'child_name'  => $child_name,
                    'message'     => sprintf( __( 'نسبة الحضور: %d%% (أقل من ٧٠%%)', 'rk-coach-hub' ), $attn ),
                    'action_url'  => add_query_arg( 'child_id', $child_id, tutor_utils()->tutor_dashboard_url( 'rk-messages' ) ),
                    'action_label'=> __( 'مراسلة', 'rk-coach-hub' ),
                ];
            }

            // 3+ absences consécutives
            $consec = RK_Coach_Data::get_consecutive_absences( $child_id );
            if ( $consec >= 3 ) {
                $alerts[] = [
                    'child_id'    => $child_id,
                    'child_name'  => $child_name,
                    'message'     => sprintf( __( '%d غيابات متتالية', 'rk-coach-hub' ), $consec ),
                    'action_url'  => add_query_arg( 'child_id', $child_id, tutor_utils()->tutor_dashboard_url( 'rk-messages' ) ),
                    'action_label'=> __( 'مراسلة', 'rk-coach-hub' ),
                ];
            }
        }

        return $alerts;
    }

    /* ─── Données graphique 6 mois ──────────────────────────────────── */

    private static function get_chart_data( int $coach_id, int $year, int $month ): array {
        $data = [];
        for ( $i = 5; $i >= 0; $i-- ) {
            $m = $month - $i;
            $y = $year;
            while ( $m < 1 ) { $m += 12; $y--; }
            $stats = RK_Coach_Data::get_monthly_stats( $coach_id, $y, $m );
            $data[] = [
                'month'    => $m,
                'year'     => $y,
                'sessions' => (int) ( $stats['sessions'] ?? 0 ),
            ];
        }
        return $data;
    }
}
