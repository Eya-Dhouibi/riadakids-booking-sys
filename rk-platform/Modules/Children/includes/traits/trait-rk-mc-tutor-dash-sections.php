<?php
declare( strict_types=1 );
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Extrait de class-rk-mc-tutor-dashboard.php — factorisation par fonctionnalité.
 * Code déplacé verbatim, aucune modification de logique.
 */
trait RK_MC_Tutor_Dash_Sections {
    /* ═══════════════════════════════════════════════════════════════════
       MAIN CONTENT  (injected inside Tutor content column, home only)
       ═══════════════════════════════════════════════════════════════════ */

    public static function render_rk_content(): void {
        if ( ! self::is_home() ) return;

        $child = self::resolve_child();

        // FT3 — Page multi-enfants quand aucun enfant sélectionné (parent sans contexte)
        if ( ! $child ) {
            if ( ! RK_MC_Child_Restrictions::is_child_user() && is_user_logged_in() ) {
                self::render_children_landing();
            }
            return;
        }

        $child_id  = (int) $child->id;
        $view_data = class_exists( 'RK_MC_Child_Dashboard_Data' )
            ? RK_MC_Child_Dashboard_Data::get_dashboard_data( $child_id, $child )
            : array();

        // FT1 — Quiz en attente (Tutor LMS)
        $d            = self::load_data();
        $quiz_pending = (int) ( $d['quiz_pending'] ?? 0 );
        if ( $quiz_pending > 0 ) : ?>
        <div dir="rtl"
             style="background:#eff6ff;border:1.5px solid #bfdbfe;border-radius:12px;
                    padding:14px 18px;margin-bottom:18px;display:flex;align-items:center;gap:12px;">
            <span style="font-size:1.4rem;">📝</span>
            <div style="flex:1;color:#1e40af;">
                <strong>
                    <?php printf( esc_html__( 'لديك %d اختبار في الانتظار', 'rk-my-children' ), $quiz_pending ); ?>
                </strong>
            </div>
            <?php if ( function_exists( 'tutor_utils' ) ) : ?>
            <a href="<?php echo esc_url( home_url( RK_TUTOR_DASHBOARD_URL . 'my-quiz-attempts/' . self::child_param() ) ); ?>"
               style="background:#2563eb;color:#fff;padding:8px 16px;border-radius:8px;
                      font-size:.85rem;font-weight:700;text-decoration:none;white-space:nowrap;">
                <?php esc_html_e( 'ابدأ الآن', 'rk-my-children' ); ?>
            </a>
            <?php endif; ?>
        </div>
        <?php endif;

        // Render the same complete design used at /my-account/child-dashboard/
        $tmpl = RK_MC_DIR . 'templates/dashboard/dashboard.php';
        if ( file_exists( $tmpl ) ) {
            include $tmpl;
        }
    }

    /* ── Children Landing (parent sans contexte enfant sélectionné) ─── */

    private static function render_children_landing(): void {
        $user_id  = get_current_user_id();
        $children = function_exists( 'rk_mc_get_children' ) ? rk_mc_get_children( $user_id ) : array();
        $credits  = function_exists( 'rk_mc_get_session_credits' ) ? rk_mc_get_session_credits( $user_id ) : 99;
        ?>
        <div class="rk-children-landing" dir="rtl" style="padding:8px 0 24px;">
            <h2 style="font-size:1.35rem;font-weight:700;color:#1e293b;margin-bottom:18px;">
                <?php esc_html_e( 'اختر طفلاً لعرض لوحته', 'rk-my-children' ); ?>
            </h2>

            <?php if ( $credits <= 2 ) : ?>
            <div style="background:#fff7ed;border:1.5px solid #fed7aa;border-radius:12px;
                        padding:14px 18px;margin-bottom:22px;display:flex;align-items:center;gap:12px;">
                <span style="font-size:1.4rem;">⚠️</span>
                <div style="flex:1;color:#c2410c;font-weight:600;">
                    <?php printf( esc_html__( 'رصيد لقاءات منخفض — %d جلسة متبقية', 'rk-my-children' ), max( 0, $credits ) ); ?>
                </div>
                <a href="<?php echo esc_url( home_url( '/booking/' ) ); ?>"
                   style="background:#ea580c;color:#fff;padding:8px 16px;border-radius:8px;
                          font-size:.85rem;font-weight:700;text-decoration:none;white-space:nowrap;">
                    <?php esc_html_e( 'احجز الآن', 'rk-my-children' ); ?>
                </a>
            </div>
            <?php endif; ?>

            <?php if ( $children ) : ?>
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:16px;">
                <?php foreach ( $children as $c ) :
                    $cid       = (int) $c->id;
                    $cname     = $c->child_name ?? '';
                    $age       = function_exists( 'rk_mc_get_child_age' ) ? rk_mc_get_child_age( $c ) : null;
                    $avatar    = function_exists( 'rk_mc_get_avatar_url' )  ? rk_mc_get_avatar_url( $c )  : '';
                    $dash_url  = home_url( RK_TUTOR_DASHBOARD_URL . '?child_id=' . $cid );
                    $pts       = class_exists( 'RK_MC_Gamification_Service' ) ? RK_MC_Gamification_Service::get_total_points( $cid ) : 0;
                    $lvl       = class_exists( 'RK_MC_Gamification_Service' ) ? RK_MC_Gamification_Service::compute_level( $pts ) : array();
                    $lvl_num   = (int) ( $lvl['num']   ?? 1 );
                    $lvl_label = $lvl['label'] ?? '';
                    $session   = class_exists( 'RK_MC_Child_Dashboard_Service' ) ? RK_MC_Child_Dashboard_Service::get_upcoming_session( $cid ) : null;
                ?>
                <a href="<?php echo esc_url( $dash_url ); ?>" style="text-decoration:none;">
                    <div style="background:#fff;border:1.5px solid #e2e8f0;border-radius:16px;
                                padding:22px 18px;text-align:center;
                                box-shadow:0 2px 12px rgba(0,0,0,.06);
                                transition:box-shadow .18s,transform .18s;cursor:pointer;"
                         onmouseenter="this.style.boxShadow='0 6px 24px rgba(0,0,0,.12)';this.style.transform='translateY(-2px)'"
                         onmouseleave="this.style.boxShadow='0 2px 12px rgba(0,0,0,.06)';this.style.transform='none'">
                        <img src="<?php echo esc_url( $avatar ); ?>" alt=""
                             style="width:68px;height:68px;border-radius:50%;object-fit:cover;
                                    margin-bottom:12px;border:3px solid #e0e7ff;">
                        <h3 style="font-size:1rem;font-weight:700;color:#1e293b;margin:0 0 4px;">
                            <?php echo esc_html( $cname ); ?>
                        </h3>
                        <?php if ( $age ) : ?>
                        <p style="color:#94a3b8;font-size:.82rem;margin:0 0 12px;">
                            <?php printf( esc_html__( '%d سنة', 'rk-my-children' ), $age ); ?>
                        </p>
                        <?php endif; ?>
                        <div style="display:inline-flex;align-items:center;gap:6px;
                                    background:#ede9fe;padding:4px 12px;border-radius:20px;margin-bottom:10px;">
                            <span style="color:#7c3aed;font-weight:700;font-size:.88rem;">
                                ⭐ <?php printf( esc_html__( 'مستوى %d', 'rk-my-children' ), $lvl_num ); ?>
                            </span>
                            <?php if ( $lvl_label ) : ?>
                            <span style="color:#a78bfa;font-size:.8rem;">— <?php echo esc_html( $lvl_label ); ?></span>
                            <?php endif; ?>
                        </div>
                        <?php if ( $session ) : ?>
                        <div style="font-size:.8rem;color:#64748b;">
                            📅 <?php echo esc_html( rk_mc_appt_format( $session->appointment ?? '', 'j M', (int) ( $session->booking_id ?? 0 ) ) ); ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </a>
                <?php endforeach; ?>
            </div>
            <?php else : ?>
            <div class="rk-empty" style="text-align:center;padding:40px 20px;">
                <?php echo wp_kses_post( rk_mc_svg( 'users', array( 'class' => 'rk-empty__icon' ) ) ); ?>
                <p><?php esc_html_e( 'لم تقم بإضافة أي طفل بعد', 'rk-my-children' ); ?></p>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /* ── KPI Strip ───────────────────────────────────────────────────── */

    private static function render_kpi_strip( array $d ): void {
        $session = $d['session'];
        $session_date = $session
            ? rk_mc_appt_format( $session->appointment ?? '', 'j M', (int) ( $session->booking_id ?? 0 ) )
            : '—';

        $kpis = array(
            array( 'icon' => 'graduation-cap', 'val' => $d['completed_count'],  'lbl' => 'مكتملة',   'mod' => 'primary'   ),
            array( 'icon' => 'lightning',       'val' => number_format( $d['total_points'] ), 'lbl' => 'نقطة', 'mod' => 'gold' ),
            array( 'icon' => 'award',           'val' => count( $d['badges'] ),  'lbl' => 'شارة',     'mod' => 'accent'    ),
            array( 'icon' => 'chart',           'val' => count( $d['skills'] ),  'lbl' => 'مهارة',    'mod' => 'secondary' ),
            array( 'icon' => 'calendar',        'val' => $session_date,          'lbl' => 'جلسة',     'mod' => 'green'     ),
            array( 'icon' => 'nav-target',      'val' => $d['done_missions'] . '/' . count( $d['missions'] ), 'lbl' => 'مهمة', 'mod' => 'orange' ),
        );
        ?>
        <div class="rk-kpi-strip">
            <?php foreach ( $kpis as $k ) : ?>
                <div class="rk-kpi rk-kpi--<?php echo esc_attr( $k['mod'] ); ?>">
                    <div class="rk-kpi__icon">
                        <?php echo wp_kses_post( rk_mc_svg( $k['icon'] ) ); ?>
                    </div>
                    <div class="rk-kpi__val"><?php echo esc_html( $k['val'] ); ?></div>
                    <div class="rk-kpi__lbl"><?php echo esc_html( $k['lbl'] ); ?></div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php
    }

    /* ── Session Card ────────────────────────────────────────────────── */

    private static function render_session_card( array $d ): void {
        $session = $d['session'];
        if ( ! $session ) return;

        $_aid     = (int) ( $session->booking_id ?? 0 );
        $ts       = rk_mc_appt_timestamp( $session->appointment ?? '' );
        $date_str = rk_mc_appt_format( $session->appointment ?? '', 'l، j F Y', $_aid );
        $time_str = rk_mc_appt_format( $session->appointment ?? '', 'g:i a',    $_aid );
        $join_url = ! empty( $session->meeting_url ) ? $session->meeting_url : '';
        $coach    = ! empty( $session->coach ) ? $session->coach : '';
        ?>
        <div class="rk-section rk-section--session" id="rk-dash-sessions">
            <div class="rk-session-card">
                <div class="rk-session-card__pulse"></div>
                <div class="rk-session-card__left">
                    <div class="rk-session-card__label">
                        <?php echo wp_kses_post( rk_mc_svg( 'calendar', array( 'class' => 'rk-session-card__label-icon' ) ) ); ?>
                        جلستك القادمة
                    </div>
                    <div class="rk-session-card__date"><?php echo esc_html( $date_str ); ?></div>
                    <div class="rk-session-card__time"><?php echo esc_html( $time_str ); ?></div>
                    <?php if ( $coach ) : ?>
                        <div class="rk-session-card__coach">
                            <?php echo wp_kses_post( rk_mc_svg( 'users', array( 'class' => 'rk-session-card__coach-icon' ) ) ); ?>
                            <?php echo esc_html( $coach ); ?>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="rk-session-card__right">
                    <div class="rk-session-countdown" id="rk-countdown" data-ts="<?php echo esc_attr( $ts ); ?>">—</div>
                    <?php if ( $join_url ) : ?>
                        <a href="<?php echo esc_url( $join_url ); ?>" class="rk-btn rk-btn--join" target="_blank" rel="noopener">
                            <?php echo wp_kses_post( rk_mc_svg( 'enter' ) ); ?>
                            انضم للجلسة
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
    }

    /* ── Courses Section ─────────────────────────────────────────────── */

    private static function render_courses_section( array $d ): void {
        $active_courses = $d['active_courses'];
        $wp_uid         = (int) ( $d['wp_uid'] ?? 0 );
        if ( ! $active_courses || ! $active_courses->have_posts() ) return;
        ?>
        <div class="rk-section rk-section--courses" id="rk-dash-courses">
            <div class="rk-section__header">
                <h2 class="rk-section__title">
                    <?php echo wp_kses_post( rk_mc_svg( 'graduation-cap', array( 'class' => 'rk-section__title-icon' ) ) ); ?>
                    دوراتي
                </h2>
                <a href="<?php echo esc_url( home_url( RK_TUTOR_DASHBOARD_URL . 'enrolled-courses/' . self::child_param() ) ); ?>"
                   class="rk-link-more"><?php esc_html_e( 'عرض الكل', 'rk-my-children' ); ?></a>
            </div>
            <div class="rk-courses-list">
                <?php
                while ( $active_courses->have_posts() ) :
                    $active_courses->the_post();
                    $cid      = get_the_ID();
                    $thumb    = get_tutor_course_thumbnail_src();
                    $progress = function_exists( 'tutor_utils' )
                        ? tutor_utils()->get_course_completed_percent( $cid, $wp_uid, true )
                        : array( 'completed_percent' => 0, 'completed_count' => 0, 'total_count' => 0 );
                    $pct      = (int) ( $progress['completed_percent'] ?? 0 );
                    ?>
                    <a href="<?php the_permalink(); ?>" class="rk-course-card">
                        <div class="rk-course-card__thumb">
                            <?php if ( $thumb ) : ?>
                                <img src="<?php echo esc_url( $thumb ); ?>" alt="<?php the_title_attribute(); ?>" loading="lazy" />
                            <?php else : ?>
                                <div class="rk-course-card__thumb-placeholder">
                                    <?php echo wp_kses_post( rk_mc_svg( 'graduation-cap' ) ); ?>
                                </div>
                            <?php endif; ?>
                            <div class="rk-course-card__pct-badge"><?php echo $pct; ?>%</div>
                        </div>
                        <div class="rk-course-card__body">
                            <div class="rk-course-card__title"><?php the_title(); ?></div>
                            <div class="rk-course-card__progress">
                                <div class="rk-progress-bar">
                                    <div class="rk-progress-bar__fill" style="width:<?php echo $pct; ?>%"></div>
                                </div>
                                <span class="rk-course-card__count">
                                    <?php echo esc_html( $progress['completed_count'] ?? 0 ); ?>/<?php echo esc_html( $progress['total_count'] ?? 0 ); ?>
                                    <?php esc_html_e( 'درس', 'rk-my-children' ); ?>
                                </span>
                            </div>
                        </div>
                        <div class="rk-course-card__arrow">
                            <?php echo wp_kses_post( rk_mc_svg( 'enter', array( 'class' => 'rk-course-card__arrow-icon' ) ) ); ?>
                        </div>
                    </a>
                <?php endwhile; wp_reset_postdata(); ?>
            </div>
        </div>
        <?php
    }

    /* ── Missions Section ────────────────────────────────────────────── */

    private static function render_missions_section( array $d ): void {
        $missions = $d['missions'] ?? array();
        // Show only active (not completed) for the homepage preview
        $active = array_filter( $missions, fn( $m ) => empty( $m['completed'] ) );
        ?>
        <div class="rk-section rk-section--missions" id="rk-dash-missions">
            <div class="rk-section__header">
                <h2 class="rk-section__title">
                    <?php echo wp_kses_post( rk_mc_svg( 'nav-target', array( 'class' => 'rk-section__title-icon' ) ) ); ?>
                    مهام المدرب
                </h2>
                <a href="<?php echo esc_url( home_url( RK_TUTOR_DASHBOARD_URL . 'question-answer/' . self::child_param() ) ); ?>"
                   class="rk-link-more">تفاصيل</a>
            </div>
            <?php if ( $active ) : ?>
                <div class="rk-missions-list">
                    <?php foreach ( array_slice( $active, 0, 3 ) as $m ) :
                        $target   = max( 1, (int) ( $m['target'] ?? 1 ) );
                        $progress = (int) ( $m['progress'] ?? 0 );
                        $pct      = min( 100, (int) round( $progress / $target * 100 ) );
                        ?>
                        <div class="rk-mission">
                            <div class="rk-mission__icon-wrap">
                                <?php echo wp_kses_post( rk_mc_svg( 'nav-target' ) ); ?>
                            </div>
                            <div class="rk-mission__body">
                                <div class="rk-mission__name"><?php echo esc_html( $m['title'] ?? '' ); ?></div>
                                <div class="rk-mission__bar">
                                    <div class="rk-mission__bar-fill" style="width:<?php echo $pct; ?>%"></div>
                                </div>
                                <div class="rk-mission__footer">
                                    <span class="rk-mission__count"><?php echo $progress; ?>/<?php echo $target; ?></span>
                                    <?php if ( (int) ( $m['points'] ?? 0 ) > 0 ) : ?>
                                        <span class="rk-mission__xp">
                                            <?php echo wp_kses_post( rk_mc_svg( 'lightning' ) ); ?>
                                            +<?php echo (int) $m['points']; ?> XP
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else : ?>
                <div class="rk-empty">
                    <?php echo wp_kses_post( rk_mc_svg( 'nav-target', array( 'class' => 'rk-empty__icon' ) ) ); ?>
                    <p>لا توجد مهام مُعيَّنة من المدرب</p>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /* ── Badges Section ──────────────────────────────────────────────── */

    private static function render_badges_section( array $d ): void {
        $badges = $d['badges'] ?? array();
        ?>
        <div class="rk-section rk-section--badges" id="rk-dash-badges">
            <div class="rk-section__header">
                <h2 class="rk-section__title">
                    <?php echo wp_kses_post( rk_mc_svg( 'award', array( 'class' => 'rk-section__title-icon' ) ) ); ?>
                    الشارات والجوائز
                </h2>
                <?php if ( $badges ) : ?>
                    <a href="<?php echo esc_url( home_url( RK_TUTOR_DASHBOARD_URL . 'rk-badges/' . self::child_param() ) ); ?>"
                       class="rk-link-more">عرض الكل</a>
                <?php endif; ?>
            </div>
            <?php if ( $badges ) :
                $preview = array_slice( $badges, 0, 6 );
                ?>
                <div class="rk-badges-grid">
                    <?php foreach ( $preview as $b ) :
                        $key  = $b->badge_key ?? 'star';
                        $bsvg = function_exists( 'rk_mc_badge_svg' ) ? rk_mc_badge_svg( $key ) : rk_mc_svg( 'award' );
                        $date = ! empty( $b->earned_at ) ? date_i18n( 'j M Y', strtotime( $b->earned_at ) ) : '';
                        ?>
                        <div class="rk-badge-tile">
                            <div class="rk-badge-tile__svg">
                                <?php echo wp_kses_post( $bsvg ); ?>
                            </div>
                            <div class="rk-badge-tile__name"><?php echo esc_html( $key ); ?></div>
                            <?php if ( $date ) : ?>
                                <div class="rk-badge-tile__date"><?php echo esc_html( $date ); ?></div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else : ?>
                <div class="rk-empty rk-empty--badges">
                    <?php echo wp_kses_post( rk_mc_svg( 'award', array( 'class' => 'rk-empty__icon' ) ) ); ?>
                    <p><?php esc_html_e( 'أكمل مهامك لتربح أولى شاراتك!', 'rk-my-children' ); ?></p>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /* ── Skills Section ──────────────────────────────────────────────── */

    private static function render_skills_section( array $d ): void {
        $skills = $d['skills'] ?? array();
        if ( ! $skills ) return;

        $labels = array(
            'speech'     => 'الكلام',     'teamwork'   => 'التعاون',
            'creativity' => 'الإبداع',    'courage'    => 'الشجاعة',
            'leadership' => 'القيادة',    'focus'      => 'التركيز',
        );
        $icons = array(
            'speech'     => 'skill-speech', 'teamwork'   => 'users',
            'creativity' => 'skill-creativity', 'courage' => 'trophy',
            'leadership' => 'rocket',       'focus'      => 'nav-target',
        );
        ?>
        <div class="rk-section rk-section--skills" id="rk-dash-skills">
            <div class="rk-section__header">
                <h2 class="rk-section__title">
                    <?php echo wp_kses_post( rk_mc_svg( 'chart', array( 'class' => 'rk-section__title-icon' ) ) ); ?>
                    المهارات
                </h2>
            </div>
            <div class="rk-skills-list">
                <?php foreach ( $skills as $s ) :
                    $key   = $s->skill_key ?? '';
                    $label = $labels[ $key ] ?? $key;
                    $icon  = $icons[ $key ] ?? 'chart';
                    $val   = min( 5, max( 0, (int) ( $s->current_score ?? 0 ) ) );
                    $pct   = (int) round( $val / 5 * 100 );
                    ?>
                    <div class="rk-skill">
                        <div class="rk-skill__icon"><?php echo wp_kses_post( rk_mc_svg( $icon ) ); ?></div>
                        <div class="rk-skill__name"><?php echo esc_html( $label ); ?></div>
                        <div class="rk-skill__bar">
                            <div class="rk-skill__fill" style="width:<?php echo $pct; ?>%"></div>
                        </div>
                        <div class="rk-skill__score"><?php echo $val; ?>/5</div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }

    /* ── Assessment Section ──────────────────────────────────────────── */

    private static function render_assessment_section( array $d ): void {
        $bilan = $d['assessment'];
        if ( ! $bilan ) return;

        $strengths = ! empty( $bilan['strengths'] ) ? json_decode( $bilan['strengths'], true ) : array();
        $develops  = ! empty( $bilan['developments'] ) ? json_decode( $bilan['developments'], true ) : array();
        ?>
        <div class="rk-section rk-section--assessment" id="rk-dash-assessment">
            <div class="rk-section__header">
                <h2 class="rk-section__title">
                    <?php echo wp_kses_post( rk_mc_svg( 'star', array( 'class' => 'rk-section__title-icon' ) ) ); ?>
                    آخر تقييم من المدرب
                </h2>
                <a href="<?php echo esc_url( home_url( RK_TUTOR_DASHBOARD_URL . 'reviews/' . self::child_param() ) ); ?>"
                   class="rk-link-more">كل التقييمات</a>
            </div>
            <div class="rk-bilan">
                <div class="rk-bilan__head">
                    <div class="rk-bilan__coach">
                        <?php echo wp_kses_post( rk_mc_svg( 'users', array( 'class' => 'rk-bilan__coach-icon' ) ) ); ?>
                        <div>
                            <strong><?php echo esc_html( $bilan['coach_name'] ); ?></strong>
                            <span><?php echo esc_html( date_i18n( 'j F Y', strtotime( $bilan['assessed_at'] ) ) ); ?></span>
                        </div>
                    </div>
                    <div class="rk-stars">
                        <?php for ( $i = 1; $i <= 5; $i++ ) : ?>
                            <span class="rk-star<?php echo $i <= (int) $bilan['rating'] ? ' rk-star--on' : ''; ?>">
                                <?php echo wp_kses_post( rk_mc_svg( 'star' ) ); ?>
                            </span>
                        <?php endfor; ?>
                    </div>
                </div>
                <?php if ( ! empty( $bilan['summary'] ) ) : ?>
                    <blockquote class="rk-bilan__summary"><?php echo esc_html( $bilan['summary'] ); ?></blockquote>
                <?php endif; ?>
                <?php if ( $strengths || $develops ) : ?>
                    <div class="rk-bilan__cols">
                        <?php if ( $strengths ) : ?>
                            <div class="rk-bilan__col rk-bilan__col--green">
                                <div class="rk-bilan__col-title">
                                    <?php echo wp_kses_post( rk_mc_svg( 'check-circle' ) ); ?>
                                    نقاط القوة
                                </div>
                                <ul><?php foreach ( array_slice( $strengths, 0, 3 ) as $s ) : ?><li><?php echo esc_html( $s ); ?></li><?php endforeach; ?></ul>
                            </div>
                        <?php endif; ?>
                        <?php if ( $develops ) : ?>
                            <div class="rk-bilan__col rk-bilan__col--blue">
                                <div class="rk-bilan__col-title">
                                    <?php echo wp_kses_post( rk_mc_svg( 'rocket' ) ); ?>
                                    للتطوير
                                </div>
                                <ul><?php foreach ( array_slice( $develops, 0, 2 ) as $s ) : ?><li><?php echo esc_html( $s ); ?></li><?php endforeach; ?></ul>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                <?php
                if ( class_exists( 'RK_MC_Assessment_Service' ) && ! empty( $bilan['skill_scores'] ) ) {
                    $scores = json_decode( $bilan['skill_scores'], true ) ?? array();
                    echo wp_kses_post( RK_MC_Assessment_Service::radar_svg( $scores, array(), 200 ) );
                }
                ?>
            </div>
        </div>
        <?php
    }

    /* ── Messages Section — D2: BP Better Messages ──────────────────── */

    private static function render_messages_section( array $d ): void {
        $child_id  = (int) ( $d['child_id'] ?? 0 );
        $coach_id  = (int) ( $d['coach_id_for_msg'] ?? 0 );
        $bm_unread = (int) ( $d['bm_unread'] ?? 0 );
        $msg_url   = home_url( RK_TUTOR_DASHBOARD_URL . 'rk-messages/' . self::child_param() );
        $bm_active = class_exists( 'Better_Messages' );
        ?>
        <div class="rk-section rk-section--messages" id="rk-dash-messages">
            <div class="rk-section__header">
                <h2 class="rk-section__title">
                    <?php echo wp_kses_post( rk_mc_svg( 'enter', array( 'class' => 'rk-section__title-icon' ) ) ); ?>
                    الرسائل
                    <?php if ( $bm_unread ) : ?>
                        <span class="rk-unread-dot"><?php echo $bm_unread; ?></span>
                    <?php endif; ?>
                </h2>
                <a href="<?php echo esc_url( $msg_url ); ?>" class="rk-link-more">فتح المراسلة</a>
            </div>

            <?php if ( $bm_active && $coach_id ) :
                $btn = do_shortcode( '[better_messages_pm_button user_id="' . (int) $coach_id . '" text="مراسلة المدرب"]' );
            ?>
            <div class="rk-msgs-bm">
                <p class="rk-msgs-bm__intro">تواصل مباشرة مع مدربك:</p>
                <?php echo $btn; ?>
                <?php if ( $bm_unread ) : ?>
                <span class="rk-msgs-bm__unread">
                    لديك <strong><?php echo $bm_unread; ?></strong> رسالة غير مقروءة
                </span>
                <?php endif; ?>
            </div>
            <?php elseif ( ! $bm_active ) : ?>
            <div class="rk-empty">
                <p>نظام الرسائل غير متاح حاليًا.</p>
            </div>
            <?php else : ?>
            <div class="rk-empty">
                <p>لم يتم تعيين مدرب بعد — ستظهر الرسائل بعد أول حجز مؤكد.</p>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }

}
