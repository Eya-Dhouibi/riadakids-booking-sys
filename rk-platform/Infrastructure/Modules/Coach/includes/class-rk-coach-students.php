<?php
declare( strict_types=1 );
/**
 * RK_Coach_Students — Liste des élèves du coach.
 * v3.0 — Cartes professionnelles + alertes + recherche + filtres.
 *
 * @package RK_Coach_Hub
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class RK_Coach_Students {

    /* ─── Batch : course WC lié au dernier booking par enfant ──────── */

    private static function get_courses_batch( array $child_ids, int $coach_id ): array {
        $all = RKP_CoachSessionRepository::get_courses_for_children_batch( $child_ids, $coach_id );
        $map = [];
        foreach ( $all as $cid => $courses ) {
            $map[ (int) $cid ] = (string) ( $courses[0]['title'] ?? '' );
        }
        return $map;
    }

    /* ─── Rendu page ────────────────────────────────────────────────── */

    public static function render(): void {
        $coach_id = get_current_user_id();
        $search   = sanitize_text_field( $_GET['s'] ?? '' );
        $sort     = in_array( $_GET['sort'] ?? '', [ 'name', 'attendance', 'progress', 'alerts' ], true )
                    ? sanitize_key( $_GET['sort'] )
                    : 'name';

        $students = RK_Coach_Data::get_coach_students( $coach_id );

        // Enrich avec stats
        $enriched = [];
        foreach ( $students as $st ) {
            $child_id = (int) $st->child_id;
            $attn     = RK_Coach_Data::get_child_attendance_rate( $child_id, $coach_id );
            $consec   = RK_Coach_Data::get_consecutive_absences( $child_id );
            $last_contact = RK_Coach_Data::get_last_parent_contact( $coach_id, $child_id );

            // Progression LMS
            $progress = 0;
            if ( function_exists( 'tutor_utils' ) ) {
                $enrolled = tutor_utils()->get_enrolled_courses_ids_by_user( $child_id );
                if ( ! empty( $enrolled ) ) {
                    $total = 0; $done = 0;
                    foreach ( $enrolled as $cid ) {
                        $stats    = tutor_utils()->get_course_completed_percent( $cid, $child_id );
                        $progress+= (int) $stats;
                        $total++;
                    }
                    $progress = $total ? (int) round( $progress / $total ) : 0;
                }
            }

            // Alertes
            $alerts = [];
            if ( $consec >= 3 )   $alerts[] = 'absent3';
            if ( $attn < 70 )     $alerts[] = 'low_attn';
            if ( $progress < 20 ) $alerts[] = 'low_progress';

            $enriched[] = [
                'child_id'     => $child_id,
                'name'         => (string) $st->child_name,
                'avatar'       => get_avatar_url( $child_id, [ 'size' => 80 ] ),
                'program'      => (string) ( $st->program_name ?? '' ),
                'attendance'   => $attn,
                'consecutive'  => $consec,
                'progress'     => $progress,
                'last_contact' => $last_contact,
                'alerts'       => $alerts,
                'course_title' => '',
            ];
        }

        // Batch : cours liés aux bookings (1 requête pour tous les enfants)
        $child_ids_list = array_column( $enriched, 'child_id' );
        $courses_map    = self::get_courses_batch( $child_ids_list, $coach_id );
        foreach ( $enriched as &$item ) {
            $item['course_title'] = $courses_map[ $item['child_id'] ] ?? '';
        }
        unset( $item );

        // Filtre recherche
        if ( $search !== '' ) {
            $enriched = array_filter( $enriched, function ( $st ) use ( $search ) {
                return mb_stripos( $st['name'], $search ) !== false
                    || mb_stripos( $st['program'], $search ) !== false
                    || mb_stripos( $st['course_title'], $search ) !== false;
            } );
            $enriched = array_values( $enriched );
        }

        // Tri
        usort( $enriched, function ( $a, $b ) use ( $sort ) {
            return match ( $sort ) {
                'attendance' => $a['attendance'] <=> $b['attendance'],
                'progress'   => $b['progress']   <=> $a['progress'],
                'alerts'     => count( $b['alerts'] ) <=> count( $a['alerts'] ),
                default      => strcmp( $a['name'], $b['name'] ),
            };
        } );

        $base_url    = tutor_utils()->tutor_dashboard_url( 'rk-eleves' );
        $alert_count = count( array_filter( $enriched, fn( $st ) => ! empty( $st['alerts'] ) ) );
        ?>
        <div class="rk-coach-page" dir="rtl">

            <!-- Top bar -->
            <div class="rk-ch-topbar">
                <h2 class="rk-ch-topbar__title">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                    <?php esc_html_e( 'طلابي', 'rk-coach-hub' ); ?>
                </h2>
                <div class="rk-ch-topbar__actions">
                    <span class="rk-ch-pill rk-ch-pill--blue"><?php echo count( $enriched ); ?> <?php esc_html_e( 'طالب', 'rk-coach-hub' ); ?></span>
                    <?php if ( $alert_count ) : ?>
                    <span class="rk-ch-pill rk-ch-pill--red">
                        <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" aria-hidden="true"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                        <?php echo $alert_count; ?> <?php esc_html_e( 'تنبيه', 'rk-coach-hub' ); ?>
                    </span>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Filtres + recherche -->
            <div class="rk-ch-section" style="margin-bottom:20px;">
                <div class="rk-ch-filterbar">
                    <!-- Recherche -->
                    <form method="get" style="display:flex;align-items:center;gap:8px;flex:1;max-width:320px;">
                        <?php foreach ( $_GET as $k => $v ) :
                            if ( in_array( $k, [ 's', 'tutor_dashboard_page' ], true ) ) continue;
                        ?>
                        <input type="hidden" name="<?php echo esc_attr( $k ); ?>" value="<?php echo esc_attr( $v ); ?>">
                        <?php endforeach; ?>
                        <div style="position:relative;flex:1;">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="2.5" style="position:absolute;right:10px;top:50%;transform:translateY(-50%);pointer-events:none;" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                            <input type="text" name="s" value="<?php echo esc_attr( $search ); ?>"
                                   placeholder="<?php esc_attr_e( 'بحث بالاسم أو البرنامج...', 'rk-coach-hub' ); ?>"
                                   style="width:100%;padding:8px 34px 8px 12px;border:1.5px solid var(--ch-border,#e2e8f0);border-radius:9px;font-size:.85rem;background:#fff;direction:rtl;">
                        </div>
                        <button type="submit" class="rk-ch-btn rk-ch-btn--primary rk-ch-btn--sm">
                            <?php esc_html_e( 'بحث', 'rk-coach-hub' ); ?>
                        </button>
                        <?php if ( $search ) : ?>
                        <a href="<?php echo esc_url( add_query_arg( 's', '', $base_url ) ); ?>"
                           class="rk-ch-btn rk-ch-btn--ghost rk-ch-btn--sm"><?php esc_html_e( 'مسح', 'rk-coach-hub' ); ?></a>
                        <?php endif; ?>
                    </form>

                    <!-- Tri -->
                    <div class="rk-ch-filter-chips" style="margin-right:auto;">
                        <?php
                        $sort_opts = [
                            'name'       => 'الاسم',
                            'attendance' => 'الحضور',
                            'progress'   => 'التقدم',
                            'alerts'     => 'التنبيهات',
                        ];
                        foreach ( $sort_opts as $key => $lbl ) :
                            $url = add_query_arg( [ 'sort' => $key, 's' => $search ], $base_url );
                        ?>
                        <a href="<?php echo esc_url( $url ); ?>"
                           class="rk-ch-chip<?php echo $key === $sort ? ' rk-ch-chip--active' : ''; ?>">
                            <?php echo esc_html( $lbl ); ?>
                        </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- Grille étudiants -->
            <?php if ( empty( $enriched ) ) : ?>
            <div class="rk-ch-section">
                <div class="rk-ch-section-body">
                    <div class="rk-ch-empty">
                        <div class="rk-ch-empty__icon">
                            <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
                        </div>
                        <p class="rk-ch-empty__title"><?php esc_html_e( 'لا يوجد طلاب', 'rk-coach-hub' ); ?></p>
                        <?php if ( $search ) : ?>
                        <p class="rk-ch-empty__text"><?php echo esc_html( sprintf( __( 'لا نتائج لـ "%s"', 'rk-coach-hub' ), $search ) ); ?></p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php else : ?>
            <div class="rk-ch-students-grid">
                <?php foreach ( $enriched as $st ) self::render_card( $st, $coach_id ); ?>
            </div>
            <?php endif; ?>

        </div>
        <?php
    }

    /* ─── Carte élève ───────────────────────────────────────────────── */

    private static function render_card( array $st, int $coach_id ): void {
        $child_id  = (int) $st['child_id'];
        $fiche_url = add_query_arg( 'child_id', $child_id, tutor_utils()->tutor_dashboard_url( 'rk-fiche-eleve' ) );
        $quiz_url  = add_query_arg( [ 'child_id' => $child_id, 'tab' => 'quiz' ], tutor_utils()->tutor_dashboard_url( 'rk-fiche-eleve' ) );
        $eval_url  = add_query_arg( 'child_id', $child_id, tutor_utils()->tutor_dashboard_url( 'rk-evaluer' ) );
        $msg_url   = add_query_arg( 'child_id', $child_id, tutor_utils()->tutor_dashboard_url( 'rk-messages' ) );
        $attn      = (int) $st['attendance'];
        $prog      = (int) $st['progress'];
        $alerts    = $st['alerts'];
        $has_alert = ! empty( $alerts );
        $attn_color = $attn >= 80 ? '#166534' : ( $attn >= 60 ? '#92400e' : '#b91c1c' );
        $attn_bg    = $attn >= 80 ? '#dcfce7' : ( $attn >= 60 ? '#fef3c7' : '#fee2e2' );
        ?>
        <div class="rk-ch-student-card<?php echo $has_alert ? ' rk-ch-student-card--alert' : ''; ?>">

            <!-- En-tête carte -->
            <div class="rk-ch-student-card__header">
                <a href="<?php echo esc_url( $fiche_url ); ?>" class="rk-ch-student-card__avatar-link">
                    <img src="<?php echo esc_url( $st['avatar'] ); ?>"
                         alt="<?php echo esc_attr( $st['name'] ); ?>"
                         class="rk-ch-student-card__avatar"
                         width="56" height="56">
                </a>
                <div class="rk-ch-student-card__meta">
                    <h3 class="rk-ch-student-card__name">
                        <a href="<?php echo esc_url( $fiche_url ); ?>"><?php echo esc_html( $st['name'] ); ?></a>
                    </h3>
                    <?php if ( $st['course_title'] ) : ?>
                    <p class="rk-ch-student-card__course">
                        <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg>
                        <?php echo esc_html( $st['course_title'] ); ?>
                    </p>
                    <?php elseif ( $st['program'] ) : ?>
                    <p class="rk-ch-student-card__program"><?php echo esc_html( $st['program'] ); ?></p>
                    <?php endif; ?>
                </div>

                <?php if ( $has_alert ) : ?>
                <div class="rk-ch-student-card__alert-dot" title="<?php echo esc_attr( implode( ' · ', self::alert_labels( $alerts ) ) ); ?>">
                    <svg width="10" height="10" viewBox="0 0 24 24" fill="#b91c1c" aria-hidden="true"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/></svg>
                </div>
                <?php endif; ?>
            </div>

            <!-- Stats barres -->
            <div class="rk-ch-student-card__stats">
                <div class="rk-ch-student-card__stat">
                    <span class="rk-ch-student-card__stat-label"><?php esc_html_e( 'الحضور', 'rk-coach-hub' ); ?></span>
                    <div class="rk-ch-student-card__stat-bar-wrap">
                        <div class="rk-ch-student-card__stat-bar" style="width:<?php echo $attn; ?>%;background:<?php echo esc_attr( $attn_color ); ?>;"></div>
                    </div>
                    <span class="rk-ch-student-card__stat-val" style="color:<?php echo esc_attr( $attn_color ); ?>;background:<?php echo esc_attr( $attn_bg ); ?>;"><?php echo $attn; ?>%</span>
                </div>
                <div class="rk-ch-student-card__stat">
                    <span class="rk-ch-student-card__stat-label"><?php esc_html_e( 'التقدم', 'rk-coach-hub' ); ?></span>
                    <div class="rk-ch-student-card__stat-bar-wrap">
                        <div class="rk-ch-student-card__stat-bar" style="width:<?php echo $prog; ?>%;background:var(--e-global-color-primary,#E8500A);"></div>
                    </div>
                    <span class="rk-ch-student-card__stat-val" style="color:var(--e-global-color-primary,#E8500A);"><?php echo $prog; ?>%</span>
                </div>
            </div>

            <!-- Alertes chips -->
            <?php if ( $has_alert ) : ?>
            <div class="rk-ch-student-card__alerts">
                <?php foreach ( self::alert_labels( $alerts ) as $label ) : ?>
                <span class="rk-ch-student-card__alert-chip">
                    <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" aria-hidden="true"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                    <?php echo esc_html( $label ); ?>
                </span>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <!-- Dernier contact parent -->
            <div class="rk-ch-student-card__contact">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="2" aria-hidden="true"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                <span>
                    <?php
                    if ( $st['last_contact'] && $st['last_contact'] !== '—' ) {
                        echo esc_html( sprintf( __( 'آخر تواصل: %s', 'rk-coach-hub' ), $st['last_contact'] ) );
                    } else {
                        esc_html_e( 'لا يوجد تواصل مع الوالدين', 'rk-coach-hub' );
                    }
                    ?>
                </span>
            </div>

            <!-- Actions -->
            <div class="rk-ch-student-card__actions">
                <?php $journey_url = add_query_arg( 'child_id', $child_id, tutor_utils()->tutor_dashboard_url( 'rk-journey' ) ); ?>
                <a href="<?php echo esc_url( $journey_url ); ?>" class="rk-ch-btn rk-ch-btn--primary rk-ch-btn--sm rk-ch-btn--block">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
                    <?php esc_html_e( 'مسار الطالب', 'rk-coach-hub' ); ?>
                </a>
                <a href="<?php echo esc_url( $fiche_url ); ?>" class="rk-ch-btn rk-ch-btn--ghost rk-ch-btn--sm rk-ch-btn--block">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                    <?php esc_html_e( 'الملف', 'rk-coach-hub' ); ?>
                </a>
                <a href="<?php echo esc_url( $eval_url ); ?>" class="rk-ch-btn rk-ch-btn--ghost rk-ch-btn--sm rk-ch-btn--block">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                    <?php esc_html_e( 'تقييم', 'rk-coach-hub' ); ?>
                </a>
                <a href="<?php echo esc_url( $quiz_url ); ?>" class="rk-ch-btn rk-ch-btn--ghost rk-ch-btn--sm rk-ch-btn--block">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                    <?php esc_html_e( 'اختبار', 'rk-coach-hub' ); ?>
                </a>
                <a href="<?php echo esc_url( $msg_url ); ?>" class="rk-ch-btn rk-ch-btn--ghost rk-ch-btn--sm rk-ch-btn--block">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                    <?php esc_html_e( 'رسالة', 'rk-coach-hub' ); ?>
                </a>
            </div>

        </div>
        <?php
    }

    /* ─── Alertes intelligentes pour le dashboard home ─────────────── */

    public static function get_smart_alerts( int $coach_id ): array {
        $cache_key = 'rk_ch_alerts_' . $coach_id;
        $cached    = get_transient( $cache_key );
        if ( false !== $cached ) return $cached;

        $students = RK_Coach_Data::get_coach_students( $coach_id );
        $alerts   = [];
        $base_url = function_exists( 'tutor_utils' ) ? tutor_utils()->tutor_dashboard_url( '' ) : admin_url();

        foreach ( $students as $st ) {
            $child_id   = (int) $st->child_id;
            $child_name = trim( (string) $st->child_name . ' ' . (string) ( $st->child_family_name ?? '' ) );
            $detail_url = add_query_arg( 'child_id', $child_id, $base_url . 'rk-fiche-eleve/' );

            $consec = RK_Coach_Data::get_consecutive_absences( $child_id );
            if ( $consec >= 3 ) {
                $alerts[] = [
                    'level'   => 'red',
                    'title'   => $child_name,
                    'message' => sprintf( __( '%d غيابات متتالية', 'rk-coach-hub' ), $consec ),
                    'url'     => $detail_url,
                ];
                continue;
            }

            $attn = RK_Coach_Data::get_child_attendance_rate( $child_id, $coach_id );
            if ( $attn < 70 ) {
                $alerts[] = [
                    'level'   => 'orange',
                    'title'   => $child_name,
                    'message' => sprintf( __( 'معدل حضور %d%%', 'rk-coach-hub' ), $attn ),
                    'url'     => $detail_url,
                ];
                continue;
            }

            $last_eval = RKP_AssessmentRepository::find_last_assessed_at_for_coach( $child_id, $coach_id );
            if ( ! $last_eval || strtotime( $last_eval ) < strtotime( '-21 days' ) ) {
                $alerts[] = [
                    'level'   => 'orange',
                    'title'   => $child_name,
                    'message' => __( 'لم يُكتب تقييم منذ أكثر من ٣ أسابيع', 'rk-coach-hub' ),
                    'url'     => add_query_arg( 'child_id', $child_id, $base_url . 'rk-evaluer/' ),
                ];
            }

            // Crédits faibles — avertir le coach pour qu'il prévienne le parent
            $parent_id = RK_Coach_Data::get_parent_of_child( $child_id );
            if ( $parent_id && function_exists( 'rk_mc_get_session_credits' ) ) {
                $credits = (int) rk_mc_get_session_credits( $parent_id );
                if ( $credits <= 2 ) {
                    $alerts[] = [
                        'level'   => 'orange',
                        'title'   => $child_name,
                        'message' => sprintf( __( 'رصيد الأسرة منخفض: %d جلسات فقط', 'rk-coach-hub' ), $credits ),
                        'url'     => $detail_url,
                    ];
                }
            }

            // Baisse progression LMS (comparaison mensuelle via snapshot)
            if ( function_exists( 'tutor_utils' ) ) {
                $enrolled = tutor_utils()->get_enrolled_courses_ids_by_user( $child_id );
                if ( ! empty( $enrolled ) ) {
                    $total = 0; $current_pct = 0;
                    foreach ( $enrolled as $cid ) {
                        $current_pct += (int) tutor_utils()->get_course_completed_percent( $cid, $child_id );
                        $total++;
                    }
                    $current_pct = $total ? (int) round( $current_pct / $total ) : 0;
                    $snap        = get_user_meta( $child_id, 'rk_prog_snap_' . $child_id, true );
                    $cur_month   = date( 'Y-m' );
                    if ( is_array( $snap ) && ! empty( $snap['month'] ) ) {
                        if ( $snap['month'] !== $cur_month ) {
                            // Nouveau mois : comparer et mettre à jour
                            $prev_pct = (int) $snap['pct'];
                            if ( $prev_pct > 0 && ( $prev_pct - $current_pct ) >= 10 ) {
                                $alerts[] = [
                                    'level'   => 'orange',
                                    'title'   => $child_name,
                                    'message' => sprintf(
                                        __( 'تراجع التقدم من %d%% إلى %d%%', 'rk-coach-hub' ),
                                        $prev_pct, $current_pct
                                    ),
                                    'url'     => $detail_url,
                                ];
                            }
                            update_user_meta( $child_id, 'rk_prog_snap_' . $child_id, [ 'pct' => $current_pct, 'month' => $cur_month ] );
                        }
                    } else {
                        // Premier enregistrement
                        update_user_meta( $child_id, 'rk_prog_snap_' . $child_id, [ 'pct' => $current_pct, 'month' => $cur_month ] );
                    }
                }
            }
        }

        set_transient( $cache_key, $alerts, 5 * MINUTE_IN_SECONDS );
        return $alerts;
    }

    /* ─── Libellés alertes ──────────────────────────────────────────── */

    private static function alert_labels( array $keys ): array {
        $map = [
            'absent3'      => __( '٣ غيابات متتالية', 'rk-coach-hub' ),
            'low_attn'     => __( 'حضور أقل من ٧٠٪', 'rk-coach-hub' ),
            'low_progress' => __( 'تقدم ضعيف', 'rk-coach-hub' ),
        ];
        return array_values( array_filter(
            array_map( fn( $k ) => $map[ $k ] ?? null, $keys )
        ) );
    }
}
