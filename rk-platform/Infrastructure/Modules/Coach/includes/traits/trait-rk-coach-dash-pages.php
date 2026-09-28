<?php
declare( strict_types=1 );
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Extrait de class-rk-coach-dashboard.php — factorisation par fonctionnalité.
 * Code déplacé verbatim, aucune modification de logique.
 */
trait RK_Coach_Dash_Pages {
    /* ─── PAGES LEARNING MANAGEMENT ────────────────────────────────── */

    private static function render_mes_cours(): void {
        $coach_id = get_current_user_id();
        $courses  = class_exists( 'RK_Coach_Course_Service' )
            ? RK_Coach_Course_Service::get_instructor_courses( $coach_id, true )
            : [];
        ?>
        <div class="rk-coach-page" dir="rtl">
            <div class="rk-ch-section">
                <div class="rk-ch-section-head">
                    <div class="rk-ch-section-head__left">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--e-global-color-primary,#FF4411)" stroke-width="2.5" aria-hidden="true"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>
                        <h3 class="rk-ch-section-title"><?php esc_html_e( 'دوراتي التدريبية', 'rk-coach-hub' ); ?></h3>
                    </div>
                    <span class="rk-ch-badge"><?php echo count( $courses ); ?> <?php esc_html_e( 'دورة', 'rk-coach-hub' ); ?></span>
                </div>
                <?php if ( empty( $courses ) ) : ?>
                <div class="rk-ch-empty">
                    <p class="rk-ch-empty__title"><?php esc_html_e( 'لا توجد دورات منشورة', 'rk-coach-hub' ); ?></p>
                    <p class="rk-ch-empty__text"><?php esc_html_e( 'أنشئ دوراتك الأولى في لوحة تحكم Tutor LMS.', 'rk-coach-hub' ); ?></p>
                </div>
                <?php else : ?>
                <div class="rk-ch-course-cards rk-ch-course-cards--full">
                    <?php foreach ( $courses as $c ) : ?>
                    <div class="rk-ch-course-card rk-ch-course-card--full">
                        <?php if ( $c['thumbnail'] ) : ?>
                        <div class="rk-ch-course-card__thumb">
                            <img src="<?php echo esc_url( $c['thumbnail'] ); ?>" alt="<?php echo esc_attr( $c['title'] ); ?>" width="120" height="80" loading="lazy">
                        </div>
                        <?php endif; ?>
                        <div class="rk-ch-course-card__body">
                            <?php if ( $c['category'] ) : ?>
                            <p class="rk-ch-course-card__cat"><?php echo esc_html( $c['category'] ); ?></p>
                            <?php endif; ?>
                            <h4 class="rk-ch-course-card__title"><?php echo esc_html( $c['title'] ); ?></h4>
                            <div class="rk-ch-course-card__stats-row">
                                <span class="rk-ch-stat-chip rk-ch-stat-chip--blue">
                                    <?php echo (int) $c['enrolled']; ?> <?php esc_html_e( 'طالب', 'rk-coach-hub' ); ?>
                                </span>
                                <span class="rk-ch-stat-chip">
                                    <?php echo (int) $c['topics_count']; ?> <?php esc_html_e( 'وحدة', 'rk-coach-hub' ); ?>
                                </span>
                                <span class="rk-ch-stat-chip">
                                    <?php echo (int) $c['lessons_total']; ?> <?php esc_html_e( 'درس', 'rk-coach-hub' ); ?>
                                </span>
                                <span class="rk-ch-stat-chip">
                                    <?php echo (int) $c['quizzes_count']; ?> <?php esc_html_e( 'اختبار', 'rk-coach-hub' ); ?>
                                </span>
                            </div>
                            <div class="rk-ch-progress-bar" role="progressbar" aria-valuenow="<?php echo (int) $c['avg_progress']; ?>" aria-valuemin="0" aria-valuemax="100">
                                <div class="rk-ch-progress-bar__fill" style="width:<?php echo (int) $c['avg_progress']; ?>%"></div>
                            </div>
                            <p class="rk-ch-course-card__pct"><?php echo (int) $c['avg_progress']; ?>% <?php esc_html_e( 'متوسط تقدم الطلاب', 'rk-coach-hub' ); ?></p>
                        </div>
                        <div class="rk-ch-course-card__actions">
                            <a href="<?php echo esc_url( add_query_arg( 'course_id', $c['id'], tutor_utils()->tutor_dashboard_url( 'rk-progression' ) ) ); ?>"
                               class="rk-ch-btn rk-ch-btn--primary rk-ch-btn--sm">
                                <?php esc_html_e( 'تقدم الطلاب', 'rk-coach-hub' ); ?>
                            </a>
                            <a href="<?php echo esc_url( add_query_arg( 'course_id', $c['id'], tutor_utils()->tutor_dashboard_url( 'rk-lecons' ) ) ); ?>"
                               class="rk-ch-btn rk-ch-btn--ghost rk-ch-btn--sm">
                                <?php esc_html_e( 'الدروس', 'rk-coach-hub' ); ?>
                            </a>
                            <a href="<?php echo esc_url( $c['permalink'] ); ?>"
                               class="rk-ch-btn rk-ch-btn--ghost rk-ch-btn--sm"
                               target="_blank" rel="noopener">
                                <?php esc_html_e( 'عرض', 'rk-coach-hub' ); ?>
                            </a>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    private static function render_progression(): void {
        $coach_id  = get_current_user_id();
        $course_id = absint( $_GET['course_id'] ?? 0 );

        $courses = class_exists( 'RK_Coach_Course_Service' )
            ? RK_Coach_Course_Service::get_instructor_courses( $coach_id, false )
            : [];

        // Si pas de course_id sélectionné, prendre le premier
        if ( ! $course_id && ! empty( $courses ) ) {
            $course_id = $courses[0]['id'];
        }

        $students = ( $course_id && class_exists( 'RK_Coach_Course_Service' ) )
            ? RK_Coach_Course_Service::get_students_in_course( $course_id, $coach_id )
            : [];

        $course_title = $course_id ? ( get_the_title( $course_id ) ?: '' ) : '';
        ?>
        <div class="rk-coach-page" dir="rtl">
            <!-- Sélecteur de cours -->
            <?php if ( count( $courses ) > 1 ) : ?>
            <div class="rk-ch-section rk-ch-mb-16">
                <form method="get" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                    <label for="rk-course-select" class="rk-ch-label"><?php esc_html_e( 'البرنامج:', 'rk-coach-hub' ); ?></label>
                    <select id="rk-course-select" name="course_id" class="rk-ch-select" onchange="this.form.submit()">
                        <?php foreach ( $courses as $c ) : ?>
                        <option value="<?php echo (int) $c['id']; ?>" <?php selected( $course_id, $c['id'] ); ?>>
                            <?php echo esc_html( $c['title'] ); ?> (<?php echo (int) $c['enrolled']; ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <?php // Conserver les autres query vars Tutor LMS ?>
                    <input type="hidden" name="tutor_dashboard_page" value="rk-progression">
                </form>
            </div>
            <?php endif; ?>

            <div class="rk-ch-section">
                <div class="rk-ch-section-head">
                    <div class="rk-ch-section-head__left">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--e-global-color-secondary,#1B4F8C)" stroke-width="2.5" aria-hidden="true"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
                        <h3 class="rk-ch-section-title">
                            <?php echo $course_title ? esc_html( $course_title ) : esc_html__( 'تقدم الطلاب', 'rk-coach-hub' ); ?>
                        </h3>
                    </div>
                    <span class="rk-ch-badge"><?php echo count( $students ); ?> <?php esc_html_e( 'طالب', 'rk-coach-hub' ); ?></span>
                </div>
                <?php if ( empty( $students ) ) : ?>
                <div class="rk-ch-empty">
                    <p class="rk-ch-empty__title"><?php esc_html_e( 'لا يوجد طلاب مسجلون', 'rk-coach-hub' ); ?></p>
                </div>
                <?php else : ?>
                <div class="rk-ch-student-progress-list">
                    <?php foreach ( $students as $s ) :
                        $pct         = (int) $s['progress'];
                        $pct_color   = $pct >= 80 ? '#16a34a' : ( $pct >= 50 ? '#d97706' : 'var(--e-global-color-primary,#FF4411)' );
                        $next_lbl    = $s['next_lesson']['title'] ?? '';
                        $last_raw    = $s['last_activity'];
                        $last_lbl    = $last_raw ? date_i18n( 'j M Y', strtotime( $last_raw ) ) : '';
                    ?>
                    <div class="rk-ch-sp-row">
                        <div class="rk-ch-sp-name"><?php echo esc_html( $s['child_name'] ); ?></div>
                        <div class="rk-ch-sp-progress">
                            <div class="rk-ch-progress-bar" role="progressbar" aria-valuenow="<?php echo $pct; ?>" aria-valuemin="0" aria-valuemax="100">
                                <div class="rk-ch-progress-bar__fill" style="width:<?php echo $pct; ?>%;background:<?php echo esc_attr( $pct_color ); ?>"></div>
                            </div>
                            <span class="rk-ch-sp-pct"><?php echo $pct; ?>%</span>
                        </div>
                        <div class="rk-ch-sp-meta">
                            <?php if ( $s['lessons_done'] > 0 || $s['lessons_total'] > 0 ) : ?>
                            <span><?php echo (int) $s['lessons_done']; ?>/<?php echo (int) $s['lessons_total']; ?> <?php esc_html_e( 'درس', 'rk-coach-hub' ); ?></span>
                            <?php endif; ?>
                            <?php if ( $next_lbl ) : ?>
                            <span class="rk-ch-sp-next"><?php echo esc_html( $next_lbl ); ?></span>
                            <?php endif; ?>
                            <?php if ( $last_lbl ) : ?>
                            <span class="rk-ch-sp-date"><?php echo esc_html( $last_lbl ); ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="rk-ch-sp-actions">
                            <?php
                            $fiche_url = add_query_arg(
                                [ 'tutor_dashboard_page' => 'rk-fiche-eleve', 'child_id' => $s['child_id'] ],
                                tutor_utils()->tutor_dashboard_url( '' )
                            );
                            ?>
                            <a href="<?php echo esc_url( $fiche_url ); ?>" class="rk-ch-btn rk-ch-btn--ghost rk-ch-btn--xs">
                                <?php esc_html_e( 'الملف', 'rk-coach-hub' ); ?>
                            </a>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    private static function render_lecons(): void {
        $coach_id  = get_current_user_id();
        $course_id = absint( $_GET['course_id'] ?? 0 );

        $courses = class_exists( 'RK_Coach_Course_Service' )
            ? RK_Coach_Course_Service::get_instructor_courses( $coach_id, false )
            : [];

        if ( ! $course_id && ! empty( $courses ) ) {
            $course_id = $courses[0]['id'];
        }

        $enrolled_ids = $course_id ? RK_Coach_Course_Service::get_enrolled_user_ids( $course_id ) : [];
        $modules      = ( $course_id && ! empty( $enrolled_ids ) )
            ? RK_Coach_Course_Service::get_module_completion( $course_id, $enrolled_ids )
            : [];

        $course_title = $course_id ? ( get_the_title( $course_id ) ?: '' ) : '';
        ?>
        <div class="rk-coach-page" dir="rtl">
            <?php if ( count( $courses ) > 1 ) : ?>
            <div class="rk-ch-section rk-ch-mb-16">
                <form method="get" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                    <select name="course_id" class="rk-ch-select" onchange="this.form.submit()">
                        <?php foreach ( $courses as $c ) : ?>
                        <option value="<?php echo (int) $c['id']; ?>" <?php selected( $course_id, $c['id'] ); ?>>
                            <?php echo esc_html( $c['title'] ); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <input type="hidden" name="tutor_dashboard_page" value="rk-lecons">
                </form>
            </div>
            <?php endif; ?>

            <div class="rk-ch-section">
                <div class="rk-ch-section-head">
                    <div class="rk-ch-section-head__left">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--e-global-color-secondary,#1B4F8C)" stroke-width="2.5" aria-hidden="true"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
                        <h3 class="rk-ch-section-title">
                            <?php echo $course_title ? esc_html( $course_title ) : esc_html__( 'متابعة الدروس', 'rk-coach-hub' ); ?>
                        </h3>
                    </div>
                    <span class="rk-ch-badge"><?php echo count( $enrolled_ids ); ?> <?php esc_html_e( 'طالب', 'rk-coach-hub' ); ?></span>
                </div>
                <?php if ( empty( $modules ) ) : ?>
                <div class="rk-ch-empty">
                    <p class="rk-ch-empty__title"><?php esc_html_e( 'لا توجد وحدات دراسية', 'rk-coach-hub' ); ?></p>
                </div>
                <?php else : ?>
                <div class="rk-ch-module-list">
                    <?php foreach ( $modules as $mod ) :
                        $total  = (int) $mod['students_total'];
                        $done   = (int) $mod['students_done'];
                        $pct    = $total > 0 ? (int) round( $done / $total * 100 ) : 0;
                        $status = $done === $total ? 'completed' : ( $done > 0 ? 'partial' : 'pending' );
                    ?>
                    <div class="rk-ch-module-row rk-ch-module-row--<?php echo esc_attr( $status ); ?>">
                        <div class="rk-ch-module-row__indicator">
                            <?php if ( $status === 'completed' ) : ?>
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="2.5" aria-label="<?php esc_attr_e( 'مكتمل', 'rk-coach-hub' ); ?>"><polyline points="20 6 9 17 4 12"/></svg>
                            <?php elseif ( $status === 'partial' ) : ?>
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#d97706" stroke-width="2.5" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="16"/></svg>
                            <?php else : ?>
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="2.5" aria-hidden="true"><circle cx="12" cy="12" r="10"/></svg>
                            <?php endif; ?>
                        </div>
                        <div class="rk-ch-module-row__body">
                            <p class="rk-ch-module-row__title"><?php echo esc_html( $mod['topic_title'] ); ?></p>
                            <p class="rk-ch-module-row__meta">
                                <?php echo (int) $mod['lessons']; ?> <?php esc_html_e( 'درس', 'rk-coach-hub' ); ?>
                                · <?php echo $done; ?>/<?php echo $total; ?> <?php esc_html_e( 'طالب أتموا الوحدة', 'rk-coach-hub' ); ?>
                            </p>
                            <div class="rk-ch-progress-bar rk-ch-progress-bar--sm">
                                <div class="rk-ch-progress-bar__fill" style="width:<?php echo $pct; ?>%"></div>
                            </div>
                        </div>
                        <div class="rk-ch-module-row__pct"><?php echo $pct; ?>%</div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    private static function render_quiz(): void {
        $coach_id = get_current_user_id();
        $attempts = class_exists( 'RK_Coach_Course_Service' )
            ? RK_Coach_Course_Service::get_recent_quiz_attempts( $coach_id, 30 )
            : [];

        $pending_count = array_reduce( $attempts, fn( $c, $a ) => $c + ( $a['needs_review'] ? 1 : 0 ), 0 );
        ?>
        <div class="rk-coach-page" dir="rtl">
            <div class="rk-ch-section">
                <div class="rk-ch-section-head">
                    <div class="rk-ch-section-head__left">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--e-global-color-primary,#FF4411)" stroke-width="2.5" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                        <h3 class="rk-ch-section-title"><?php esc_html_e( 'نتائج الاختبارات', 'rk-coach-hub' ); ?></h3>
                    </div>
                    <?php if ( $pending_count > 0 ) : ?>
                    <span class="rk-ch-badge rk-ch-badge--warn"><?php echo (int) $pending_count; ?> <?php esc_html_e( 'تحتاج مراجعة', 'rk-coach-hub' ); ?></span>
                    <?php endif; ?>
                </div>
                <?php if ( empty( $attempts ) ) : ?>
                <div class="rk-ch-empty">
                    <p class="rk-ch-empty__title"><?php esc_html_e( 'لا توجد اختبارات حتى الآن', 'rk-coach-hub' ); ?></p>
                </div>
                <?php else : ?>
                <div class="rk-ch-quiz-list">
                    <?php foreach ( $attempts as $a ) :
                        $score = (int) $a['score_pct'];
                        $score_color = $score >= 80 ? '#16a34a' : ( $score >= 50 ? '#d97706' : '#dc2626' );
                        $ts = strtotime( $a['attempted_at'] );
                        $date_lbl = $ts ? date_i18n( 'j M Y H:i', $ts ) : '';
                    ?>
                    <div class="rk-ch-quiz-row<?php echo $a['needs_review'] ? ' rk-ch-quiz-row--review' : ''; ?>">
                        <div class="rk-ch-quiz-row__info">
                            <p class="rk-ch-quiz-row__title"><?php echo esc_html( $a['quiz_title'] ); ?></p>
                            <p class="rk-ch-quiz-row__meta">
                                <?php if ( $a['child_name'] ) echo esc_html( $a['child_name'] ) . ' · '; ?>
                                <?php echo esc_html( $date_lbl ); ?>
                                <?php if ( $a['needs_review'] ) : ?>
                                <span class="rk-ch-tag rk-ch-tag--warn"><?php esc_html_e( 'تحتاج مراجعة', 'rk-coach-hub' ); ?></span>
                                <?php endif; ?>
                            </p>
                        </div>
                        <div class="rk-ch-quiz-row__score" style="color:<?php echo esc_attr( $score_color ); ?>">
                            <?php echo $score; ?>%
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    private static function render_calendrier(): void {
        $coach_id = get_current_user_id();
        $week     = RK_Coach_Data::get_sessions_by_range( $coach_id, 'week' );
        $month    = RK_Coach_Data::get_sessions_by_range( $coach_id, 'month' );
        $view     = sanitize_key( $_GET['view'] ?? 'week' );
        $sessions = $view === 'month' ? $month : $week;
        ?>
        <div class="rk-coach-page" dir="rtl">
            <div class="rk-ch-section">
                <div class="rk-ch-section-head">
                    <div class="rk-ch-section-head__left">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--e-global-color-secondary,#1B4F8C)" stroke-width="2.5" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                        <h3 class="rk-ch-section-title"><?php esc_html_e( 'التقويم', 'rk-coach-hub' ); ?></h3>
                    </div>
                    <div class="rk-ch-tab-group">
                        <a href="<?php echo esc_url( add_query_arg( 'view', 'week', tutor_utils()->tutor_dashboard_url( 'rk-calendrier' ) ) ); ?>"
                           class="rk-ch-tab<?php echo $view === 'week' ? ' rk-ch-tab--active' : ''; ?>">
                           <?php esc_html_e( 'الأسبوع', 'rk-coach-hub' ); ?>
                        </a>
                        <a href="<?php echo esc_url( add_query_arg( 'view', 'month', tutor_utils()->tutor_dashboard_url( 'rk-calendrier' ) ) ); ?>"
                           class="rk-ch-tab<?php echo $view === 'month' ? ' rk-ch-tab--active' : ''; ?>">
                           <?php esc_html_e( 'الشهر', 'rk-coach-hub' ); ?>
                        </a>
                    </div>
                </div>
                <?php if ( empty( $sessions ) ) : ?>
                <div class="rk-ch-empty">
                    <p class="rk-ch-empty__title">
                        <?php $view === 'week'
                            ? esc_html_e( 'لا توجد جلسات هذا الأسبوع', 'rk-coach-hub' )
                            : esc_html_e( 'لا توجد جلسات هذا الشهر', 'rk-coach-hub' ); ?>
                    </p>
                </div>
                <?php else : ?>
                <div class="rk-ch-session-list">
                    <?php foreach ( $sessions as $s ) RK_Coach_Sessions::render_row( $s ); ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

}
