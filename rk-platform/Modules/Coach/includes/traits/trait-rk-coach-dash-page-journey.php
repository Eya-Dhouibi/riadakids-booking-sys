<?php
declare( strict_types=1 );
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Extrait de class-rk-coach-dashboard.php — factorisation par fonctionnalité.
 * Code déplacé verbatim, aucune modification de logique.
 */
trait RK_Coach_Dash_Page_Journey {
    /* ─── JOURNEY VIEW ─────────────────────────────────────────────── */

    private static function render_journey(): void {
        $coach_id = get_current_user_id();
        $child_id = absint( $_GET['child_id'] ?? 0 );

        if ( ! $child_id ) {
            wp_safe_redirect( tutor_utils()->tutor_dashboard_url( 'rk-mes-eleves' ) );
            exit;
        }

        // Vérification d'autorisation
        $students  = RK_Coach_Data::get_coach_students( $coach_id );
        $child_ids = array_map( fn( $s ) => (int) $s->child_id, $students );
        if ( ! in_array( $child_id, $child_ids, true ) ) {
            echo '<div class="rk-ch-notice rk-ch-notice--error">' . esc_html__( 'غير مصرح.', 'rk-coach-hub' ) . '</div>';
            return;
        }

        $child      = get_userdata( $child_id );
        $child_name = $child ? $child->display_name : __( 'طالب', 'rk-coach-hub' );
        $avatar_url = get_avatar_url( $child_id, [ 'size' => 80 ] );

        // XP + niveau (Journey snapshot ou Gamification)
        $xp             = 0;
        $level_num      = 1;
        $level_label    = '';
        $level_progress = 0;
        if ( class_exists( 'RKP_JourneyQueryService' ) ) {
            $snap      = RKP_JourneyQueryService::getJourney( $child_id );
            $xp        = $snap ? $snap->xp    : 0;
            $level_num = $snap ? $snap->level : 1;
        }
        if ( class_exists( 'RKP_GamificationQueryService' ) ) {
            $lvl            = RKP_GamificationQueryService::compute_level( $xp );
            $level_label    = (string) ( $lvl['label']    ?? '' );
            $level_progress = (int)    ( $lvl['progress'] ?? 0 );
        }

        // Cours LMS + modules
        $courses_data = [];
        $overall_pct  = 0;
        if ( function_exists( 'tutor_utils' ) ) {
            $enrolled_ids = (array) ( tutor_utils()->get_enrolled_courses_ids_by_user( $child_id ) ?: [] );
            $pct_sum      = 0;
            foreach ( $enrolled_ids as $cid ) {
                $cid  = (int) $cid;
                $pct  = (int) tutor_utils()->get_course_completed_percent( $cid, $child_id );
                $mods = ( class_exists( 'RK_Coach_Course_Service' ) && ! empty( [$child_id] ) )
                    ? RK_Coach_Course_Service::get_module_completion( $cid, [ $child_id ] )
                    : [];
                $courses_data[] = [
                    'id'      => $cid,
                    'title'   => (string) get_the_title( $cid ),
                    'pct'     => $pct,
                    'modules' => $mods,
                ];
                $pct_sum += $pct;
            }
            if ( count( $enrolled_ids ) > 0 ) {
                $overall_pct = (int) round( $pct_sum / count( $enrolled_ids ) );
            }
        }

        // Sessions
        $sessions     = RK_Coach_Data::get_child_sessions_for_coach( $child_id, $coach_id, 20 );
        $now          = time();
        $next_session = null;
        foreach ( $sessions as $s ) {
            if ( strtotime( $s['start'] ?? '' ) > $now ) { $next_session = $s; break; }
        }

        // Quiz
        $quiz_results = RK_Coach_Data::get_child_quiz_results( $child_id ) ?: [];
        $last_quiz    = $quiz_results[0] ?? null;
        $quiz_pending = array_filter( $quiz_results, fn( $q ) => ! empty( $q['needs_review'] ) );
        $quiz_avg     = count( $quiz_results ) > 0
            ? (int) round( array_sum( array_column( $quiz_results, 'score_pct' ) ) / count( $quiz_results ) )
            : 0;

        // Présence
        $attn_rate = RK_Coach_Data::get_child_attendance_rate( $child_id, $coach_id );
        $consec    = RK_Coach_Data::get_consecutive_absences( $child_id );

        // Badges
        $badge_count  = count( (array) ( get_user_meta( $child_id, 'rk_badges', true ) ?: [] ) );

        // Missions actives pour cet enfant
        $child_missions  = class_exists( 'RK_Coach_Missions' )
            ? RK_Coach_Missions::get_missions_for_child( $child_id )
            : [];
        $pending_missions = array_filter( $child_missions, fn( $m ) => in_array( $m['status'] ?? '', [ 'assigned', 'in_progress' ], true ) );

        // URLs actions
        $back_url    = tutor_utils()->tutor_dashboard_url( 'rk-mes-eleves' );
        $fiche_url   = add_query_arg( 'child_id', $child_id, tutor_utils()->tutor_dashboard_url( 'rk-fiche-eleve' ) );
        $eval_url    = add_query_arg( 'child_id', $child_id, tutor_utils()->tutor_dashboard_url( 'rk-evaluer' ) );
        $msg_url     = add_query_arg( 'child_id', $child_id, tutor_utils()->tutor_dashboard_url( 'rk-messagerie' ) );
        $mission_url = add_query_arg( 'child_id', $child_id, tutor_utils()->tutor_dashboard_url( 'rk-assigner-mission' ) );
        $badge_url   = add_query_arg( [ 'child_id' => $child_id, 'tab' => 'badges' ], tutor_utils()->tutor_dashboard_url( 'rk-fiche-eleve' ) );
        $quiz_url    = add_query_arg( [ 'child_id' => $child_id, 'tab' => 'quiz' ],   tutor_utils()->tutor_dashboard_url( 'rk-fiche-eleve' ) );

        // Statut global
        if ( $overall_pct >= 70 ) {
            $status_lbl = __( 'تقدم ممتاز', 'rk-coach-hub' );
            $status_cls = 'green';
        } elseif ( $overall_pct >= 40 ) {
            $status_lbl = __( 'تقدم متوسط', 'rk-coach-hub' );
            $status_cls = 'amber';
        } else {
            $status_lbl = __( 'يحتاج دعم', 'rk-coach-hub' );
            $status_cls = 'red';
        }
        ?>
        <div class="rk-coach-page rk-journey-page" dir="rtl">

            <!-- Retour -->
            <div class="rk-journey-back">
                <a href="<?php echo esc_url( $back_url ); ?>" class="rk-ch-btn rk-ch-btn--ghost rk-ch-btn--sm">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
                    <?php esc_html_e( 'العودة للطلاب', 'rk-coach-hub' ); ?>
                </a>
                <h2 class="rk-journey-page-title">
                    <?php esc_html_e( 'مسار الطالب', 'rk-coach-hub' ); ?>
                </h2>
            </div>

            <!-- Hero enfant -->
            <div class="rk-journey-hero">
                <div class="rk-journey-hero__left">
                    <div class="rk-journey-hero__avatar-wrap">
                        <img src="<?php echo esc_url( $avatar_url ); ?>"
                             alt="<?php echo esc_attr( $child_name ); ?>"
                             class="rk-journey-hero__avatar"
                             width="64" height="64">
                        <span class="rk-journey-hero__level-badge"><?php echo (int) $level_num; ?></span>
                    </div>
                    <div class="rk-journey-hero__info">
                        <h2 class="rk-journey-hero__name"><?php echo esc_html( $child_name ); ?></h2>
                        <?php if ( $level_label ) : ?>
                        <p class="rk-journey-hero__rank"><?php echo esc_html( $level_label ); ?></p>
                        <?php endif; ?>
                        <div class="rk-journey-hero__xp-row">
                            <div class="rk-journey-hero__xp-bar">
                                <div class="rk-journey-hero__xp-fill" style="width:<?php echo (int) $level_progress; ?>%"></div>
                            </div>
                            <span class="rk-journey-hero__xp-val"><?php echo number_format( $xp ); ?> XP</span>
                        </div>
                        <div class="rk-journey-hero__chips">
                            <span class="rk-ch-tag rk-ch-tag--<?php echo esc_attr( $status_cls ); ?>"><?php echo esc_html( $status_lbl ); ?></span>
                            <?php if ( $consec >= 3 ) : ?>
                            <span class="rk-ch-tag rk-ch-tag--amber"><?php printf( esc_html__( '%d غياب متتالي', 'rk-coach-hub' ), (int) $consec ); ?></span>
                            <?php endif; ?>
                            <?php if ( count( $quiz_pending ) > 0 ) : ?>
                            <span class="rk-ch-tag rk-ch-tag--amber"><?php printf( esc_html__( '%d اختبار للتصحيح', 'rk-coach-hub' ), count( $quiz_pending ) ); ?></span>
                            <?php endif; ?>
                            <?php if ( count( $pending_missions ) > 0 ) : ?>
                            <span class="rk-ch-tag rk-ch-tag--amber"><?php printf( esc_html__( '%d مهمة نشطة', 'rk-coach-hub' ), count( $pending_missions ) ); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="rk-journey-hero__stats">
                    <div class="rk-journey-hero__stat">
                        <span class="rk-journey-hero__stat-val"><?php echo (int) $overall_pct; ?>%</span>
                        <span class="rk-journey-hero__stat-lbl"><?php esc_html_e( 'تقدم كلي', 'rk-coach-hub' ); ?></span>
                    </div>
                    <div class="rk-journey-hero__stat">
                        <span class="rk-journey-hero__stat-val"><?php echo (int) $attn_rate; ?>%</span>
                        <span class="rk-journey-hero__stat-lbl"><?php esc_html_e( 'الحضور', 'rk-coach-hub' ); ?></span>
                    </div>
                    <div class="rk-journey-hero__stat">
                        <span class="rk-journey-hero__stat-val"><?php echo (int) $quiz_avg; ?>%</span>
                        <span class="rk-journey-hero__stat-lbl"><?php esc_html_e( 'متوسط الاختبارات', 'rk-coach-hub' ); ?></span>
                    </div>
                    <div class="rk-journey-hero__stat">
                        <span class="rk-journey-hero__stat-val"><?php echo (int) $badge_count; ?></span>
                        <span class="rk-journey-hero__stat-lbl"><?php esc_html_e( 'شارات', 'rk-coach-hub' ); ?></span>
                    </div>
                </div>
            </div>

            <!-- Layout 2 colonnes : rail gauche + détails droite -->
            <div class="rk-journey-layout">

                <!-- RAIL — progression par module -->
                <div class="rk-journey-rail-col">
                    <?php if ( empty( $courses_data ) ) : ?>
                    <div class="rk-ch-empty">
                        <p class="rk-ch-empty__title"><?php esc_html_e( 'لا توجد دورات مسجلة', 'rk-coach-hub' ); ?></p>
                    </div>
                    <?php else : ?>
                    <?php foreach ( $courses_data as $course ) : ?>
                    <div class="rk-ch-section">
                        <div class="rk-ch-section-head">
                            <div class="rk-ch-section-head__left">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--e-global-color-primary,#FF4411)" stroke-width="2.5" aria-hidden="true"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>
                                <h3 class="rk-ch-section-title"><?php echo esc_html( $course['title'] ); ?></h3>
                            </div>
                            <span class="rk-ch-badge"><?php echo (int) $course['pct']; ?>%</span>
                        </div>
                        <div class="rk-ch-section-body">
                            <?php if ( ! empty( $course['modules'] ) ) : ?>
                            <div class="rk-journey-rail">
                                <?php foreach ( $course['modules'] as $mod ) :
                                    $m_total = (int) $mod['students_total'];
                                    $m_done  = (int) $mod['students_done'];
                                    $m_pct   = $m_total > 0 ? (int) round( $m_done / $m_total * 100 ) : 0;
                                    $stage   = $m_pct >= 100 ? 'done' : ( $m_pct > 0 ? 'active' : 'pending' );
                                ?>
                                <div class="rk-journey-rail__stage rk-journey-rail__stage--<?php echo esc_attr( $stage ); ?>">
                                    <div class="rk-journey-rail__dot" aria-hidden="true">
                                        <?php if ( $stage === 'done' ) : ?>
                                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
                                        <?php elseif ( $stage === 'active' ) : ?>
                                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polygon points="5 3 19 12 5 21 5 3"/></svg>
                                        <?php else : ?>
                                        <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="5"/></svg>
                                        <?php endif; ?>
                                    </div>
                                    <div class="rk-journey-rail__body">
                                        <p class="rk-journey-rail__title"><?php echo esc_html( $mod['topic_title'] ); ?></p>
                                        <p class="rk-journey-rail__meta"><?php echo (int) $mod['lessons']; ?> <?php esc_html_e( 'درس', 'rk-coach-hub' ); ?></p>
                                    </div>
                                    <div class="rk-journey-rail__pct"><?php echo (int) $m_pct; ?>%</div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php else : ?>
                            <div class="rk-ch-progress-bar" style="margin-top:8px;" role="progressbar" aria-valuenow="<?php echo (int) $course['pct']; ?>" aria-valuemin="0" aria-valuemax="100">
                                <div class="rk-ch-progress-bar__fill" style="width:<?php echo (int) $course['pct']; ?>%"></div>
                            </div>
                            <p style="font-size:.8rem;color:var(--ch-text-2);margin-top:6px;"><?php echo (int) $course['pct']; ?>% <?php esc_html_e( 'مكتمل', 'rk-coach-hub' ); ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <!-- DÉTAILS — quiz / sessions / présence / missions -->
                <div class="rk-journey-detail-col">

                    <!-- Quiz -->
                    <div class="rk-ch-section">
                        <div class="rk-ch-section-head">
                            <div class="rk-ch-section-head__left">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="var(--e-global-color-primary,#FF4411)" stroke-width="2.5" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                                <h3 class="rk-ch-section-title"><?php esc_html_e( 'الاختبارات', 'rk-coach-hub' ); ?></h3>
                            </div>
                            <a href="<?php echo esc_url( $quiz_url ); ?>" class="rk-ch-btn rk-ch-btn--ghost rk-ch-btn--xs"><?php esc_html_e( 'عرض الكل', 'rk-coach-hub' ); ?></a>
                        </div>
                        <div class="rk-ch-section-body">
                            <?php if ( $last_quiz ) :
                                $score = (int) $last_quiz['score_pct'];
                                $score_color = $score >= 80 ? 'var(--ch-green)' : ( $score >= 50 ? '#d97706' : '#dc2626' );
                            ?>
                            <div class="rk-journey-detail-row">
                                <div class="rk-journey-detail-row__icon" style="background:rgba(255,68,17,.08);">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--e-global-color-primary,#FF4411)" stroke-width="2" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                                </div>
                                <div class="rk-journey-detail-row__info">
                                    <p class="rk-journey-detail-row__title"><?php echo esc_html( $last_quiz['quiz_title'] ?? __( 'آخر اختبار', 'rk-coach-hub' ) ); ?></p>
                                    <p class="rk-journey-detail-row__sub"><?php echo count( $quiz_results ); ?> <?php esc_html_e( 'اختبار — متوسط', 'rk-coach-hub' ); ?> <?php echo (int) $quiz_avg; ?>%</p>
                                </div>
                                <span style="font-size:1.3rem;font-weight:900;color:<?php echo esc_attr( $score_color ); ?>;font-variant-numeric:tabular-nums;"><?php echo (int) $score; ?>%</span>
                            </div>
                            <?php if ( count( $quiz_pending ) > 0 ) : ?>
                            <a href="<?php echo esc_url( $quiz_url ); ?>" class="rk-ch-notice rk-ch-notice--warn" style="display:block;margin-top:10px;font-size:.82rem;text-decoration:none;cursor:pointer;">
                                ⚠ <?php printf( esc_html__( '%d اختبار بانتظار التصحيح', 'rk-coach-hub' ), count( $quiz_pending ) ); ?>
                            </a>
                            <?php endif; ?>
                            <?php else : ?>
                            <p style="color:var(--ch-text-2);font-size:.85rem;"><?php esc_html_e( 'لا توجد اختبارات بعد', 'rk-coach-hub' ); ?></p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Prochaine séance -->
                    <div class="rk-ch-section">
                        <div class="rk-ch-section-head">
                            <div class="rk-ch-section-head__left">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="var(--e-global-color-secondary,#1B4F8C)" stroke-width="2.5" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                                <h3 class="rk-ch-section-title"><?php esc_html_e( 'لقاءات', 'rk-coach-hub' ); ?></h3>
                            </div>
                            <span class="rk-ch-badge"><?php echo count( $sessions ); ?></span>
                        </div>
                        <div class="rk-ch-section-body">
                            <?php if ( $next_session ) :
                                $ts = strtotime( $next_session['start'] ?? '' );
                            ?>
                            <div class="rk-journey-detail-row">
                                <div class="rk-journey-detail-row__icon" style="background:rgba(27,79,140,.08);">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--e-global-color-secondary,#1B4F8C)" stroke-width="2" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="3" y1="10" x2="21" y2="10"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/></svg>
                                </div>
                                <div class="rk-journey-detail-row__info">
                                    <p class="rk-journey-detail-row__title"><?php esc_html_e( 'الجلسة القادمة', 'rk-coach-hub' ); ?></p>
                                    <p class="rk-journey-detail-row__sub"><?php echo esc_html( date_i18n( 'l، j M — H:i', $ts ) ); ?></p>
                                </div>
                                <span class="rk-ch-tag rk-ch-tag--blue"><?php esc_html_e( 'قادمة', 'rk-coach-hub' ); ?></span>
                            </div>
                            <?php else : ?>
                            <p style="color:var(--ch-text-2);font-size:.85rem;"><?php esc_html_e( 'لا جلسات قادمة', 'rk-coach-hub' ); ?></p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Présence -->
                    <div class="rk-ch-section">
                        <div class="rk-ch-section-head">
                            <div class="rk-ch-section-head__left">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="var(--e-global-color-secondary,#1B4F8C)" stroke-width="2.5" aria-hidden="true"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
                                <h3 class="rk-ch-section-title"><?php esc_html_e( 'الحضور', 'rk-coach-hub' ); ?></h3>
                            </div>
                            <span class="rk-ch-badge rk-ch-badge--<?php echo $attn_rate >= 80 ? 'green' : ( $attn_rate >= 60 ? 'amber' : 'red' ); ?>"><?php echo (int) $attn_rate; ?>%</span>
                        </div>
                        <div class="rk-ch-section-body">
                            <div class="rk-ch-progress-bar" role="progressbar" aria-valuenow="<?php echo (int) $attn_rate; ?>" aria-valuemin="0" aria-valuemax="100">
                                <div class="rk-ch-progress-bar__fill" style="width:<?php echo (int) $attn_rate; ?>%;background:<?php echo $attn_rate >= 80 ? 'var(--ch-green)' : ( $attn_rate >= 60 ? '#d97706' : '#dc2626' ); ?>;"></div>
                            </div>
                            <?php if ( $consec >= 3 ) : ?>
                            <p class="rk-ch-notice rk-ch-notice--warn" style="margin-top:10px;font-size:.82rem;">
                                ⚠ <?php printf( esc_html__( '%d غياب متتالي — يحتاج تواصل فوري', 'rk-coach-hub' ), (int) $consec ); ?>
                            </p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Missions actives -->
                    <?php if ( ! empty( $pending_missions ) ) : ?>
                    <div class="rk-ch-section">
                        <div class="rk-ch-section-head">
                            <div class="rk-ch-section-head__left">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="var(--e-global-color-primary,#FF4411)" stroke-width="2.5" aria-hidden="true"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/></svg>
                                <h3 class="rk-ch-section-title"><?php esc_html_e( 'المهام النشطة', 'rk-coach-hub' ); ?></h3>
                            </div>
                            <span class="rk-ch-badge rk-ch-badge--amber"><?php echo count( $pending_missions ); ?></span>
                        </div>
                        <div class="rk-ch-section-body">
                            <?php foreach ( array_slice( $pending_missions, 0, 3 ) as $m ) : ?>
                            <div class="rk-journey-detail-row" style="padding:8px 0;border-bottom:1px solid var(--ch-border);">
                                <div class="rk-journey-detail-row__info">
                                    <p class="rk-journey-detail-row__title" style="font-size:.85rem;"><?php echo esc_html( $m['title'] ?? __( 'مهمة', 'rk-coach-hub' ) ); ?></p>
                                </div>
                                <span class="rk-ch-tag rk-ch-tag--amber" style="font-size:.75rem;"><?php echo esc_html( $m['status'] ?? '' ); ?></span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                </div>
            </div>

            <!-- Barre d'actions -->
            <div class="rk-journey-actions">
                <a href="<?php echo esc_url( $eval_url ); ?>" class="rk-ch-btn rk-ch-btn--primary rk-ch-btn--sm">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                    <?php esc_html_e( 'كتابة تقرير', 'rk-coach-hub' ); ?>
                </a>
                <a href="<?php echo esc_url( $mission_url ); ?>" class="rk-ch-btn rk-ch-btn--ghost rk-ch-btn--sm">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/></svg>
                    <?php esc_html_e( 'إسناد مهمة', 'rk-coach-hub' ); ?>
                </a>
                <a href="<?php echo esc_url( $msg_url ); ?>" class="rk-ch-btn rk-ch-btn--ghost rk-ch-btn--sm">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                    <?php esc_html_e( 'إرسال رسالة', 'rk-coach-hub' ); ?>
                </a>
                <a href="<?php echo esc_url( $badge_url ); ?>" class="rk-ch-btn rk-ch-btn--ghost rk-ch-btn--sm">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><circle cx="12" cy="8" r="6"/><path d="M15.477 12.89L17 22l-5-3-5 3 1.523-9.11"/></svg>
                    <?php esc_html_e( 'منح شارة', 'rk-coach-hub' ); ?>
                </a>
                <a href="<?php echo esc_url( $fiche_url ); ?>" class="rk-ch-btn rk-ch-btn--ghost rk-ch-btn--sm">
                    <?php esc_html_e( 'الملف الكامل', 'rk-coach-hub' ); ?>
                </a>
            </div>

        </div>
        <?php
    }

}
