<?php
declare( strict_types=1 );
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Extrait de class-rk-coach-dashboard.php — factorisation par fonctionnalité.
 * Code déplacé verbatim, aucune modification de logique.
 */
trait RK_Coach_Dash_Page_Home {
    /* ─── HOME ──────────────────────────────────────────────────────── */

    private static function render_home(): void {
        $coach      = wp_get_current_user();
        $coach_id   = $coach->ID;
        $first_name = $coach->first_name ?: $coach->display_name;
        $stats      = RK_Coach_Data::get_coach_stats( $coach_id );
        $next       = RK_Coach_Data::get_next_session_soon( $coach_id, 120 );
        $alerts     = RK_Coach_Students::get_smart_alerts( $coach_id );
        $today      = RK_Coach_Data::get_sessions_by_range( $coach_id, 'today' );
        $pending    = RK_Coach_Data::get_pending_evals_count( $coach_id );
        $unread     = RK_Coach_Data::get_unread_messages_count( $coach_id );

        // Cours LMS (top 3 pour la section home)
        $courses_lms = class_exists( 'RK_Coach_Course_Service' )
            ? array_slice( RK_Coach_Course_Service::get_instructor_courses( $coach_id, true ), 0, 3 )
            : [];

        $pending_quiz   = (int) ( $stats['pending_quizzes'] ?? 0 );
        $avg_progress   = (int) ( $stats['avg_progress']    ?? 0 );
        $active_courses = (int) ( $stats['active_courses']  ?? 0 );

        // Missions actives (toutes les missions non terminées du coach)
        $all_missions    = class_exists( 'RK_Coach_Missions' )
            ? RK_Coach_Missions::get_active_missions( $coach_id )
            : [];
        $pending_missions_count = count( $all_missions );
        ?>
        <div class="rk-coach-page" dir="rtl">

            <!-- Hero banner -->
            <div class="rk-ch-hero">
                <div class="rk-ch-hero__body">
                    <h2 class="rk-ch-hero__title">
                        <?php echo esc_html( RK_Coach_Data::greeting_arabic() ); ?>,
                        <?php echo esc_html( $first_name ); ?>
                    </h2>
                    <p class="rk-ch-hero__sub">
                        <?php if ( $next ) : ?>
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                            <?php esc_html_e( 'جلستك القادمة:', 'rk-coach-hub' ); ?>
                            <strong><?php echo esc_html( $next['child_name'] ?? '' ); ?></strong>
                            <?php echo esc_html( RK_Coach_Data::format_time( $next['start'] ?? '' ) ); ?>
                        <?php else : ?>
                            <?php esc_html_e( 'لا توجد جلسات قريبة اليوم', 'rk-coach-hub' ); ?>
                        <?php endif; ?>
                    </p>
                </div>
                <div class="rk-ch-hero__meta">
                    <?php if ( $pending > 0 ) : ?>
                    <a href="<?php echo esc_url( tutor_utils()->tutor_dashboard_url( 'rk-evaluer' ) ); ?>"
                       class="rk-ch-hero__chip rk-ch-hero__chip--alert">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/></svg>
                        <?php echo esc_html( $pending ); ?> <?php esc_html_e( 'تقييم معلق', 'rk-coach-hub' ); ?>
                    </a>
                    <?php endif; ?>
                    <?php if ( $unread > 0 ) : ?>
                    <a href="<?php echo esc_url( tutor_utils()->tutor_dashboard_url( 'rk-messagerie' ) ); ?>"
                       class="rk-ch-hero__chip">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                        <?php echo esc_html( $unread ); ?> <?php esc_html_e( 'رسائل', 'rk-coach-hub' ); ?>
                    </a>
                    <?php else : ?>
                    <span class="rk-ch-hero__chip rk-ch-hero__chip--ok">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg>
                        <?php esc_html_e( 'كل شيء بخير', 'rk-coach-hub' ); ?>
                    </span>
                    <?php endif; ?>
                    <a href="<?php echo esc_url( RK_Coach_Auth::get_coach_logout_url() ); ?>"
                       class="rk-ch-hero__chip rk-ch-hero__chip--logout"
                       title="<?php esc_attr_e( 'تسجيل الخروج', 'rk-coach-hub' ); ?>">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
                            <polyline points="16 17 21 12 16 7"/>
                            <line x1="21" y1="12" x2="9" y2="12"/>
                        </svg>
                        <?php esc_html_e( 'خروج', 'rk-coach-hub' ); ?>
                    </a>
                </div>
            </div>

            <!-- 7 KPIs — Learning Journey centric -->
            <div class="rk-ch-kpis rk-ch-kpis--7">

                <!-- KPI 1 — دوراتي النشطة -->
                <a href="<?php echo esc_url( tutor_utils()->tutor_dashboard_url( 'rk-mes-cours' ) ); ?>" class="rk-ch-kpi" style="text-decoration:none;">
                    <div class="rk-ch-kpi__icon rk-ch-kpi__icon--blue">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/>
                            <path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/>
                        </svg>
                    </div>
                    <div class="rk-ch-kpi__val"><?php echo (int) $active_courses; ?></div>
                    <div class="rk-ch-kpi__label"><?php esc_html_e( 'دورات نشطة', 'rk-coach-hub' ); ?></div>
                </a>

                <!-- KPI 2 — إجمالي الطلاب -->
                <a href="<?php echo esc_url( tutor_utils()->tutor_dashboard_url( 'rk-mes-eleves' ) ); ?>" class="rk-ch-kpi" style="text-decoration:none;">
                    <div class="rk-ch-kpi__icon rk-ch-kpi__icon--teal">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                            <circle cx="9" cy="7" r="4"/>
                            <path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
                            <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
                        </svg>
                    </div>
                    <div class="rk-ch-kpi__val"><?php echo (int) $stats['total_students']; ?></div>
                    <div class="rk-ch-kpi__label"><?php esc_html_e( 'إجمالي الطلاب', 'rk-coach-hub' ); ?></div>
                </a>

                <!-- KPI 3 — جلسات الشهر -->
                <a href="<?php echo esc_url( tutor_utils()->tutor_dashboard_url( 'rk-seances' ) ); ?>" class="rk-ch-kpi" style="text-decoration:none;">
                    <div class="rk-ch-kpi__icon rk-ch-kpi__icon--purple">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <rect x="3" y="4" width="18" height="18" rx="2"/>
                            <line x1="16" y1="2" x2="16" y2="6"/>
                            <line x1="8" y1="2" x2="8" y2="6"/>
                            <line x1="3" y1="10" x2="21" y2="10"/>
                        </svg>
                    </div>
                    <div class="rk-ch-kpi__val"><?php echo (int) ( $stats['sessions_month'] ?? 0 ); ?></div>
                    <div class="rk-ch-kpi__label"><?php esc_html_e( 'جلسات الشهر', 'rk-coach-hub' ); ?></div>
                </a>

                <!-- KPI 4 — اختبارات للتصحيح -->
                <a href="<?php echo esc_url( tutor_utils()->tutor_dashboard_url( 'rk-quiz' ) ); ?>" class="rk-ch-kpi" style="text-decoration:none;">
                    <div class="rk-ch-kpi__icon rk-ch-kpi__icon--<?php echo $pending_quiz > 0 ? 'orange' : 'green'; ?>">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <circle cx="12" cy="12" r="10"/>
                            <path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/>
                            <line x1="12" y1="17" x2="12.01" y2="17"/>
                        </svg>
                    </div>
                    <div class="rk-ch-kpi__val"><?php echo (int) $pending_quiz; ?></div>
                    <div class="rk-ch-kpi__label"><?php esc_html_e( 'اختبارات للتصحيح', 'rk-coach-hub' ); ?></div>
                    <?php if ( $pending_quiz > 0 ) : ?>
                    <span class="rk-ch-kpi__trend rk-ch-kpi__trend--warn">
                        <?php esc_html_e( 'يحتاج اهتمام', 'rk-coach-hub' ); ?>
                    </span>
                    <?php endif; ?>
                </a>

                <!-- KPI 5 — متوسط التقدم -->
                <a href="<?php echo esc_url( tutor_utils()->tutor_dashboard_url( 'rk-progression' ) ); ?>" class="rk-ch-kpi" style="text-decoration:none;">
                    <div class="rk-ch-kpi__icon rk-ch-kpi__icon--<?php echo $avg_progress >= 70 ? 'green' : ( $avg_progress >= 40 ? 'amber' : 'orange' ); ?>">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <line x1="18" y1="20" x2="18" y2="10"/>
                            <line x1="12" y1="20" x2="12" y2="4"/>
                            <line x1="6"  y1="20" x2="6"  y2="14"/>
                        </svg>
                    </div>
                    <div class="rk-ch-kpi__val"><?php echo (int) $avg_progress; ?>%</div>
                    <div class="rk-ch-kpi__label"><?php esc_html_e( 'متوسط التقدم', 'rk-coach-hub' ); ?></div>
                </a>

                <!-- KPI 6 — رسائل غير مقروءة -->
                <a href="<?php echo esc_url( tutor_utils()->tutor_dashboard_url( 'rk-messagerie' ) ); ?>" class="rk-ch-kpi" style="text-decoration:none;">
                    <div class="rk-ch-kpi__icon rk-ch-kpi__icon--<?php echo $unread > 0 ? 'orange' : 'blue'; ?>">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
                        </svg>
                    </div>
                    <div class="rk-ch-kpi__val"><?php echo (int) $unread; ?></div>
                    <div class="rk-ch-kpi__label"><?php esc_html_e( 'رسائل غير مقروءة', 'rk-coach-hub' ); ?></div>
                </a>

                <!-- KPI 7 — مهام نشطة -->
                <a href="<?php echo esc_url( tutor_utils()->tutor_dashboard_url( 'rk-assigner-mission' ) ); ?>" class="rk-ch-kpi" style="text-decoration:none;">
                    <div class="rk-ch-kpi__icon rk-ch-kpi__icon--<?php echo $pending_missions_count > 0 ? 'orange' : 'green'; ?>">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <circle cx="12" cy="12" r="10"/>
                            <circle cx="12" cy="12" r="6"/>
                            <circle cx="12" cy="12" r="2"/>
                        </svg>
                    </div>
                    <div class="rk-ch-kpi__val"><?php echo (int) $pending_missions_count; ?></div>
                    <div class="rk-ch-kpi__label"><?php esc_html_e( 'مهام نشطة', 'rk-coach-hub' ); ?></div>
                    <?php if ( $pending_missions_count > 0 ) : ?>
                    <span class="rk-ch-kpi__trend rk-ch-kpi__trend--warn"><?php esc_html_e( 'تحتاج متابعة', 'rk-coach-hub' ); ?></span>
                    <?php endif; ?>
                </a>

            </div>

            <!-- Actions rapides -->
            <div class="rk-ch-section rk-ch-mb-24">
                <div class="rk-ch-section-head">
                    <div class="rk-ch-section-head__left">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--e-global-color-secondary,#1B4F8C)" stroke-width="2.5" aria-hidden="true"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
                        <h3 class="rk-ch-section-title"><?php esc_html_e( 'الإجراءات السريعة', 'rk-coach-hub' ); ?></h3>
                    </div>
                </div>
                <div class="rk-ch-section-body rk-ch-quick-actions">
                    <a href="<?php echo esc_url( tutor_utils()->tutor_dashboard_url( 'rk-mes-cours' ) ); ?>" class="rk-ch-qa-btn rk-ch-qa-btn--blue">
                        <span class="rk-ch-qa-btn__icon" aria-hidden="true"><svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg></span>
                        <span class="rk-ch-qa-btn__label"><?php esc_html_e( 'دوراتي', 'rk-coach-hub' ); ?></span>
                    </a>
                    <a href="<?php echo esc_url( tutor_utils()->tutor_dashboard_url( 'rk-evaluer' ) ); ?>" class="rk-ch-qa-btn rk-ch-qa-btn--purple">
                        <span class="rk-ch-qa-btn__icon" aria-hidden="true"><svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/><line x1="9" y1="12" x2="15" y2="12"/></svg></span>
                        <span class="rk-ch-qa-btn__label"><?php esc_html_e( 'كتابة تقييم', 'rk-coach-hub' ); ?></span>
                    </a>
                    <a href="<?php echo esc_url( tutor_utils()->tutor_dashboard_url( 'rk-assigner-mission' ) ); ?>" class="rk-ch-qa-btn rk-ch-qa-btn--red">
                        <span class="rk-ch-qa-btn__icon" aria-hidden="true"><svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/></svg></span>
                        <span class="rk-ch-qa-btn__label"><?php esc_html_e( 'إسناد مهمة', 'rk-coach-hub' ); ?></span>
                    </a>
                    <a href="<?php echo esc_url( tutor_utils()->tutor_dashboard_url( 'rk-messagerie' ) ); ?>" class="rk-ch-qa-btn rk-ch-qa-btn--green">
                        <span class="rk-ch-qa-btn__icon" aria-hidden="true"><svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M22 2L11 13"/><path d="M22 2L15 22l-4-9-9-4 20-7z"/></svg></span>
                        <span class="rk-ch-qa-btn__label"><?php esc_html_e( 'إرسال رسالة', 'rk-coach-hub' ); ?></span>
                    </a>
                </div>
            </div>

            <!-- Mes cours (top 3) -->
            <?php if ( ! empty( $courses_lms ) ) : ?>
            <div class="rk-ch-section rk-ch-mb-24">
                <div class="rk-ch-section-head">
                    <div class="rk-ch-section-head__left">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--e-global-color-primary,#FF4411)" stroke-width="2.5" aria-hidden="true"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>
                        <h3 class="rk-ch-section-title"><?php esc_html_e( 'دوراتي', 'rk-coach-hub' ); ?></h3>
                    </div>
                    <a href="<?php echo esc_url( tutor_utils()->tutor_dashboard_url( 'rk-mes-cours' ) ); ?>"
                       class="rk-ch-btn rk-ch-btn--ghost rk-ch-btn--sm"><?php esc_html_e( 'عرض الكل', 'rk-coach-hub' ); ?></a>
                </div>
                <div class="rk-ch-course-cards">
                    <?php foreach ( $courses_lms as $c ) : ?>
                    <div class="rk-ch-course-card">
                        <?php if ( $c['thumbnail'] ) : ?>
                        <div class="rk-ch-course-card__thumb">
                            <img src="<?php echo esc_url( $c['thumbnail'] ); ?>"
                                 alt="<?php echo esc_attr( $c['title'] ); ?>"
                                 width="80" height="60" loading="lazy">
                        </div>
                        <?php endif; ?>
                        <div class="rk-ch-course-card__body">
                            <p class="rk-ch-course-card__cat"><?php echo esc_html( $c['category'] ?: __( 'دورة', 'rk-coach-hub' ) ); ?></p>
                            <h4 class="rk-ch-course-card__title"><?php echo esc_html( $c['title'] ); ?></h4>
                            <div class="rk-ch-course-card__meta">
                                <span><?php echo (int) $c['enrolled']; ?> <?php esc_html_e( 'طالب', 'rk-coach-hub' ); ?></span>
                                <span><?php echo (int) $c['lessons_total']; ?> <?php esc_html_e( 'درس', 'rk-coach-hub' ); ?></span>
                                <span><?php echo (int) $c['quizzes_count']; ?> <?php esc_html_e( 'اختبار', 'rk-coach-hub' ); ?></span>
                            </div>
                            <!-- Barre progression moyenne -->
                            <div class="rk-ch-progress-bar" role="progressbar" aria-valuenow="<?php echo (int) $c['avg_progress']; ?>" aria-valuemin="0" aria-valuemax="100">
                                <div class="rk-ch-progress-bar__fill" style="width:<?php echo (int) $c['avg_progress']; ?>%"></div>
                            </div>
                            <p class="rk-ch-course-card__pct"><?php echo (int) $c['avg_progress']; ?>% <?php esc_html_e( 'متوسط التقدم', 'rk-coach-hub' ); ?></p>
                        </div>
                        <div class="rk-ch-course-card__actions">
                            <a href="<?php echo esc_url( $c['permalink'] ); ?>"
                               class="rk-ch-btn rk-ch-btn--primary rk-ch-btn--sm"
                               target="_blank" rel="noopener">
                                <?php esc_html_e( 'عرض البرنامج', 'rk-coach-hub' ); ?>
                            </a>
                            <a href="<?php echo esc_url( add_query_arg( 'course_id', $c['id'], tutor_utils()->tutor_dashboard_url( 'rk-progression' ) ) ); ?>"
                               class="rk-ch-btn rk-ch-btn--ghost rk-ch-btn--sm">
                                <?php esc_html_e( 'تقدم الطلاب', 'rk-coach-hub' ); ?>
                            </a>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Alertes qualité -->
            <?php if ( $alerts ) : ?>
            <div class="rk-ch-section rk-ch-mb-24">
                <div class="rk-ch-section-head">
                    <div class="rk-ch-section-head__left">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#d97706" stroke-width="2.5" aria-hidden="true"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/></svg>
                        <h3 class="rk-ch-section-title"><?php esc_html_e( 'تنبيهات تحتاج اهتمامك', 'rk-coach-hub' ); ?></h3>
                    </div>
                </div>
                <div class="rk-ch-quality-alerts">
                    <?php foreach ( array_slice( $alerts, 0, 4 ) as $a ) : ?>
                    <div class="rk-ch-qa-row">
                        <div class="rk-ch-qa-icon rk-ch-qa-icon--<?php echo esc_attr( $a['level'] ); ?>">
                            <?php if ( $a['level'] === 'red' ) : ?>
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                            <?php else : ?>
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/></svg>
                            <?php endif; ?>
                        </div>
                        <div>
                            <p class="rk-ch-qa-title"><?php echo esc_html( $a['title'] ); ?></p>
                            <p class="rk-ch-qa-sub"><?php echo esc_html( $a['message'] ); ?></p>
                        </div>
                        <?php if ( ! empty( $a['url'] ) ) : ?>
                        <div class="rk-ch-qa-action">
                            <a href="<?php echo esc_url( $a['url'] ); ?>" class="rk-ch-btn rk-ch-btn--ghost rk-ch-btn--sm"><?php esc_html_e( 'عرض', 'rk-coach-hub' ); ?></a>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Séances d'aujourd'hui -->
            <?php if ( ! empty( $today ) ) : ?>
            <div class="rk-ch-section">
                <div class="rk-ch-section-head">
                    <div class="rk-ch-section-head__left">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--e-global-color-secondary,#1B4F8C)" stroke-width="2.5" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                        <h3 class="rk-ch-section-title"><?php esc_html_e( 'جلسات اليوم', 'rk-coach-hub' ); ?></h3>
                    </div>
                    <a href="<?php echo esc_url( tutor_utils()->tutor_dashboard_url( 'rk-seances' ) ); ?>"
                       class="rk-ch-btn rk-ch-btn--ghost rk-ch-btn--sm"><?php esc_html_e( 'الجدول الكامل', 'rk-coach-hub' ); ?></a>
                </div>
                <div class="rk-ch-session-list">
                    <?php foreach ( $today as $s ) RK_Coach_Sessions::render_row( $s ); ?>
                </div>
            </div>
            <?php else : ?>
            <div class="rk-ch-section">
                <div class="rk-ch-section-body">
                    <div class="rk-ch-empty">
                        <div class="rk-ch-empty__icon">
                            <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                        </div>
                        <p class="rk-ch-empty__title"><?php esc_html_e( 'لا توجد جلسات اليوم', 'rk-coach-hub' ); ?></p>
                        <p class="rk-ch-empty__text"><?php esc_html_e( 'استمتع بيومك! جلساتك القادمة ستظهر هنا.', 'rk-coach-hub' ); ?></p>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Objectifs de la semaine -->
            <?php
            $done_quiz    = $pending_quiz === 0;
            $done_evals   = $pending === 0;
            $has_sessions = ! empty( $today );
            $done_missions_review = $pending_missions_count <= 2;
            $goals = [
                [
                    'label' => $pending_quiz > 0
                        ? sprintf( __( 'تصحيح %d اختبار', 'rk-coach-hub' ), $pending_quiz )
                        : __( 'لا اختبارات معلقة', 'rk-coach-hub' ),
                    'done'  => $done_quiz,
                    'url'   => tutor_utils()->tutor_dashboard_url( 'rk-quiz' ),
                ],
                [
                    'label' => $pending > 0
                        ? sprintf( __( 'نشر %d تقييم', 'rk-coach-hub' ), $pending )
                        : __( 'كل التقييمات منشورة', 'rk-coach-hub' ),
                    'done'  => $done_evals,
                    'url'   => tutor_utils()->tutor_dashboard_url( 'rk-evaluer' ),
                ],
                [
                    'label' => $has_sessions
                        ? __( 'جلسات اليوم مجدولة', 'rk-coach-hub' )
                        : __( 'جدولة جلسات الأسبوع', 'rk-coach-hub' ),
                    'done'  => $has_sessions,
                    'url'   => tutor_utils()->tutor_dashboard_url( 'rk-seances' ),
                ],
                [
                    'label' => $pending_missions_count > 2
                        ? sprintf( __( 'متابعة %d مهمة نشطة', 'rk-coach-hub' ), $pending_missions_count )
                        : __( 'المهام تحت السيطرة', 'rk-coach-hub' ),
                    'done'  => $done_missions_review,
                    'url'   => tutor_utils()->tutor_dashboard_url( 'rk-assigner-mission' ),
                ],
            ];
            $goals_done = count( array_filter( $goals, fn( $g ) => $g['done'] ) );
            $goals_pct  = (int) round( $goals_done / count( $goals ) * 100 );
            ?>
            <div class="rk-ch-section rk-ch-mb-24">
                <div class="rk-ch-section-head">
                    <div class="rk-ch-section-head__left">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--ch-green,#16a34a)" stroke-width="2.5" aria-hidden="true"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
                        <h3 class="rk-ch-section-title"><?php esc_html_e( 'أهداف الأسبوع', 'rk-coach-hub' ); ?></h3>
                    </div>
                    <span class="rk-ch-badge" style="background:<?php echo $goals_pct >= 75 ? 'var(--ch-green-bg)' : '#fef9c3'; ?>;color:<?php echo $goals_pct >= 75 ? 'var(--ch-green)' : '#92400e'; ?>;">
                        <?php echo $goals_done; ?>/<?php echo count( $goals ); ?> <?php esc_html_e( 'مكتمل', 'rk-coach-hub' ); ?>
                    </span>
                </div>
                <div class="rk-ch-section-body">
                    <div class="rk-ch-progress-bar rk-ch-mb-16" style="margin-bottom:16px;" role="progressbar" aria-valuenow="<?php echo $goals_pct; ?>" aria-valuemin="0" aria-valuemax="100">
                        <div class="rk-ch-progress-bar__fill" style="width:<?php echo $goals_pct; ?>%;background:<?php echo $goals_pct >= 75 ? 'var(--ch-green)' : '#d97706'; ?>;"></div>
                    </div>
                    <div class="rk-ch-goals-list">
                        <?php foreach ( $goals as $goal ) : ?>
                        <a href="<?php echo esc_url( $goal['url'] ); ?>" class="rk-ch-goal-item<?php echo $goal['done'] ? ' rk-ch-goal-item--done' : ''; ?>">
                            <span class="rk-ch-goal-item__check" aria-hidden="true">
                                <?php if ( $goal['done'] ) : ?>
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="var(--ch-green)" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
                                <?php else : ?>
                                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="2"><circle cx="12" cy="12" r="9"/></svg>
                                <?php endif; ?>
                            </span>
                            <span class="rk-ch-goal-item__label"><?php echo esc_html( $goal['label'] ); ?></span>
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="2" class="rk-ch-goal-item__arrow" aria-hidden="true"><polyline points="9 18 15 12 9 6"/></svg>
                        </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

        </div>
        <?php
    }

}
