<?php
declare( strict_types=1 );
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Extrait de class-rk-coach-child-detail.php — factorisation par fonctionnalité.
 * Code déplacé verbatim, aucune modification de logique.
 */
trait RK_Coach_Child_Tabs_Learning {
    /* ─── Onglet 1 : Profil + parent ───────────────────────────────── */

    private static function tab_profile( int $child_id, $child, array $parent, int $coach_id ): void {
        $msg_url  = add_query_arg( 'child_id', $child_id, tutor_utils()->tutor_dashboard_url( 'rk-messages' ) );
        $eval_url = add_query_arg( 'child_id', $child_id, tutor_utils()->tutor_dashboard_url( 'rk-evaluer' ) );
        ?>
        <div class="rk-ch-profile-grid">

            <!-- Infos enfant -->
            <div class="rk-ch-section">
                <div class="rk-ch-section-head">
                    <h3 class="rk-ch-section-title"><?php esc_html_e( 'معلومات الطالب', 'rk-coach-hub' ); ?></h3>
                </div>
                <div class="rk-ch-section-body rk-ch-info-list">
                    <div class="rk-ch-info-row">
                        <span class="rk-ch-info-label"><?php esc_html_e( 'الاسم الكامل', 'rk-coach-hub' ); ?></span>
                        <span class="rk-ch-info-val"><?php echo esc_html( $child ? $child->display_name : '—' ); ?></span>
                    </div>
                    <div class="rk-ch-info-row">
                        <span class="rk-ch-info-label"><?php esc_html_e( 'البريد الإلكتروني', 'rk-coach-hub' ); ?></span>
                        <span class="rk-ch-info-val"><?php echo $child ? esc_html( $child->user_email ) : '—'; ?></span>
                    </div>
                    <div class="rk-ch-info-row">
                        <span class="rk-ch-info-label"><?php esc_html_e( 'تاريخ الانضمام', 'rk-coach-hub' ); ?></span>
                        <span class="rk-ch-info-val"><?php echo $child ? esc_html( date_i18n( 'j F Y', strtotime( $child->user_registered ) ) ) : '—'; ?></span>
                    </div>
                </div>
            </div>

            <!-- Infos parent -->
            <div class="rk-ch-section">
                <div class="rk-ch-section-head">
                    <h3 class="rk-ch-section-title"><?php esc_html_e( 'ولي الأمر', 'rk-coach-hub' ); ?></h3>
                    <?php if ( ! empty( $parent['user_id'] ) ) : ?>
                    <a href="<?php echo esc_url( $msg_url ); ?>" class="rk-ch-btn rk-ch-btn--primary rk-ch-btn--sm">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                        <?php esc_html_e( 'مراسلة', 'rk-coach-hub' ); ?>
                    </a>
                    <?php endif; ?>
                </div>
                <div class="rk-ch-section-body rk-ch-info-list">
                    <?php if ( empty( $parent['user_id'] ) ) : ?>
                    <p style="color:#94a3b8;font-size:.85rem;"><?php esc_html_e( 'لا يوجد ولي أمر مرتبط.', 'rk-coach-hub' ); ?></p>
                    <?php else : ?>
                    <div class="rk-ch-info-row">
                        <span class="rk-ch-info-label"><?php esc_html_e( 'الاسم', 'rk-coach-hub' ); ?></span>
                        <span class="rk-ch-info-val"><?php echo esc_html( $parent['name'] ?? '—' ); ?></span>
                    </div>
                    <div class="rk-ch-info-row">
                        <span class="rk-ch-info-label"><?php esc_html_e( 'البريد الإلكتروني', 'rk-coach-hub' ); ?></span>
                        <span class="rk-ch-info-val"><?php echo esc_html( $parent['email'] ?? '—' ); ?></span>
                    </div>
                    <?php if ( ! empty( $parent['phone'] ) ) : ?>
                    <div class="rk-ch-info-row">
                        <span class="rk-ch-info-label"><?php esc_html_e( 'الهاتف', 'rk-coach-hub' ); ?></span>
                        <span class="rk-ch-info-val"><?php echo esc_html( $parent['phone'] ); ?></span>
                    </div>
                    <?php endif; ?>
                    <div class="rk-ch-info-row">
                        <span class="rk-ch-info-label"><?php esc_html_e( 'انضم في', 'rk-coach-hub' ); ?></span>
                        <span class="rk-ch-info-val"><?php echo esc_html( $parent['joined'] ?? '—' ); ?></span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

        </div>

        <!-- Actions rapides -->
        <div class="rk-ch-section" style="margin-top:20px;">
            <div class="rk-ch-section-head">
                <h3 class="rk-ch-section-title"><?php esc_html_e( 'إجراءات سريعة', 'rk-coach-hub' ); ?></h3>
            </div>
            <div class="rk-ch-section-body" style="display:flex;gap:12px;flex-wrap:wrap;">
                <a href="<?php echo esc_url( $eval_url ); ?>" class="rk-ch-btn rk-ch-btn--primary">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                    <?php esc_html_e( 'كتابة تقييم', 'rk-coach-hub' ); ?>
                </a>
                <a href="<?php echo esc_url( $msg_url ); ?>" class="rk-ch-btn rk-ch-btn--ghost">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                    <?php esc_html_e( 'مراسلة الوالدين', 'rk-coach-hub' ); ?>
                </a>
            </div>
        </div>
        <?php
    }

    /* ─── Onglet 2 : Historique séances ────────────────────────────── */

    private static function tab_sessions( array $sessions, int $child_id, int $coach_id ): void {
        $status_map = [
            'confirmed'   => [ 'css' => 'confirmed',   'label' => 'مؤكدة' ],
            'completed'   => [ 'css' => 'completed',   'label' => 'مكتملة' ],
            'cancelled'   => [ 'css' => 'cancelled',   'label' => 'ملغاة' ],
            'rescheduled' => [ 'css' => 'rescheduled', 'label' => 'معاد جدولتها' ],
            'pending'     => [ 'css' => 'pending',     'label' => 'بانتظار التأكيد' ],
        ];
        ?>
        <div class="rk-ch-section">
            <div class="rk-ch-section-head">
                <h3 class="rk-ch-section-title"><?php esc_html_e( 'سجل لقاءات', 'rk-coach-hub' ); ?></h3>
                <span class="rk-ch-pill rk-ch-pill--blue"><?php echo count( $sessions ); ?> <?php esc_html_e( 'جلسة', 'rk-coach-hub' ); ?></span>
            </div>
            <?php if ( empty( $sessions ) ) : ?>
            <div class="rk-ch-section-body">
                <div class="rk-ch-empty">
                    <div class="rk-ch-empty__icon"><svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="3" y1="10" x2="21" y2="10"/></svg></div>
                    <p class="rk-ch-empty__title"><?php esc_html_e( 'لا توجد جلسات بعد', 'rk-coach-hub' ); ?></p>
                </div>
            </div>
            <?php else : ?>
            <div class="rk-sessions-history-list" style="padding:4px 0;">
                <?php foreach ( $sessions as $s ) :
                    $ts        = strtotime( (string) ( $s['start'] ?? '' ) );
                    $is_past   = $ts && $ts < time();
                    $status    = (string) ( $s['status'] ?? '' );
                    $st        = $status_map[ $status ] ?? [ 'css' => 'pending', 'label' => $status ?: 'غير محدد' ];
                    $sess_date = $ts ? date( 'Y-m-d', $ts ) : '';
                    $eval_url  = add_query_arg( [
                        'child_id'     => $child_id,
                        'session_date' => $sess_date,
                    ], tutor_utils()->tutor_dashboard_url( 'rk-evaluer' ) );
                ?>
                <div class="rk-session-history-row <?php echo $is_past ? 'rk-session-history--past' : 'rk-session-history--future'; ?>">
                    <div class="rk-session-history-date">
                        <span class="rk-session-history-day"><?php echo $ts ? esc_html( date_i18n( 'j', $ts ) ) : '—'; ?></span>
                        <span class="rk-session-history-month"><?php echo $ts ? esc_html( date_i18n( 'M', $ts ) ) : ''; ?></span>
                    </div>
                    <div class="rk-session-history-body">
                        <strong><?php echo esc_html( $s['program_name'] ?: __( 'جلسة', 'rk-coach-hub' ) ); ?></strong>
                        <span class="rk-session-history-time"><?php echo $ts ? esc_html( date_i18n( 'H:i', $ts ) ) : ''; ?></span>
                    </div>
                    <div class="rk-session-history-status">
                        <span class="rk-session-status rk-session-status--<?php echo esc_attr( $st['css'] ); ?>">
                            <?php echo esc_html( $st['label'] ); ?>
                        </span>
                    </div>
                    <div style="flex-shrink:0;">
                        <a href="<?php echo esc_url( $eval_url ); ?>" class="rk-ch-btn rk-ch-btn--ghost rk-ch-btn--sm">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                            <?php esc_html_e( 'تقييم', 'rk-coach-hub' ); ?>
                        </a>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /* ─── Onglet 3 : Dورات (séparé du quiz) ────────────────────────── */

    private static function tab_courses( int $child_id ): void {
        $enrolled = function_exists( 'tutor_utils' ) ? tutor_utils()->get_enrolled_courses_ids_by_user( $child_id ) : [];
        ?>
        <div class="rk-ch-section">
            <div class="rk-ch-section-head">
                <h3 class="rk-ch-section-title"><?php esc_html_e( 'الدورات المسجّلة', 'rk-coach-hub' ); ?></h3>
                <span class="rk-ch-pill rk-ch-pill--blue"><?php echo count( $enrolled ); ?></span>
            </div>
            <?php if ( empty( $enrolled ) ) : ?>
            <div class="rk-ch-section-body">
                <p style="color:#94a3b8;font-size:.85rem;"><?php esc_html_e( 'لم يسجّل في أي دورة بعد.', 'rk-coach-hub' ); ?></p>
            </div>
            <?php else : ?>
            <div class="rk-ch-section-body" style="display:grid;gap:12px;">
                <?php foreach ( $enrolled as $course_id ) :
                    $pct   = function_exists( 'tutor_utils' ) ? (int) tutor_utils()->get_course_completed_percent( $course_id, $child_id ) : 0;
                    $title = get_the_title( $course_id );
                    $color = $pct >= 80 ? '#166534' : ( $pct >= 40 ? '#92400e' : '#1e40af' );
                ?>
                <div style="display:flex;align-items:center;gap:12px;">
                    <div style="flex:1;">
                        <p style="font-size:.88rem;font-weight:600;margin:0 0 4px;"><?php echo esc_html( $title ); ?></p>
                        <div style="height:6px;background:#f1f5f9;border-radius:3px;overflow:hidden;">
                            <div style="height:100%;width:<?php echo $pct; ?>%;background:<?php echo esc_attr( $color ); ?>;border-radius:3px;transition:width .6s;"></div>
                        </div>
                    </div>
                    <span style="font-size:.85rem;font-weight:700;color:<?php echo esc_attr( $color ); ?>;min-width:38px;text-align:left;"><?php echo $pct; ?>%</span>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /* ─── Helper : cours WC liés aux bookings de l'enfant ─────────── */

    private static function get_child_booked_courses( int $child_id ): array {
        return RKP_CoachSessionRepository::find_booked_courses_for_child( $child_id );
    }

    public static function get_coach_quizzes_for_child( int $child_id, int $coach_id ): array {
        return RKP_CoachSessionRepository::find_quizzes_for_child_by_coach( $child_id, $coach_id );
    }

    /* ─── Onglet 4 : Aختبارات (dédié) + création ──────────────────── */

    private static function tab_quiz( int $child_id, array $quiz_res ): void {
        $booked_courses = self::get_child_booked_courses( $child_id );

        $action   = admin_url( 'admin-post.php' );
        $done     = isset( $_GET['quiz_done'] );
        $quiz_err = isset( $_GET['quiz_error'] );
        ?>
        <div style="display:grid;gap:20px;">

        <?php if ( $done ) : ?>
        <div class="rk-ch-notice rk-ch-notice--success">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg>
            <?php esc_html_e( 'تم إنشاء الاختبار بنجاح وأصبح متاحاً للطالب.', 'rk-coach-hub' ); ?>
        </div>
        <?php endif; ?>

        <?php if ( $quiz_err ) : ?>
        <div class="rk-ch-notice rk-ch-notice--error">
            <?php esc_html_e( 'حدث خطأ أثناء إنشاء الاختبار. يرجى المحاولة مرة أخرى.', 'rk-coach-hub' ); ?>
        </div>
        <?php endif; ?>

        <!-- ── إنشاء اختبار جديد ──────────────────────────────────── -->
        <?php if ( ! empty( $booked_courses ) ) : ?>
        <details class="rk-inline-panel">
            <summary class="rk-ch-btn rk-ch-btn--primary rk-ch-btn--sm"
                     style="display:inline-flex;list-style:none;cursor:pointer;margin-bottom:0;">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                <?php esc_html_e( 'إنشاء اختبار جديد', 'rk-coach-hub' ); ?>
            </summary>
            <div class="rk-inline-panel__body" style="background:#f8fafc;border-radius:0 0 12px 12px;padding:20px;border:1px solid #e2e8f0;border-top:none;">
                <form method="post" action="<?php echo esc_url( $action ); ?>">
                    <?php wp_nonce_field( 'rk_coach_create_quiz', '_nonce' ); ?>
                    <input type="hidden" name="action"   value="rk_coach_create_quiz">
                    <input type="hidden" name="child_id" value="<?php echo $child_id; ?>">

                    <!-- Infos de base -->
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px;">
                        <div>
                            <label style="font-size:.82rem;font-weight:700;color:#374151;display:block;margin-bottom:4px;">
                                <?php esc_html_e( 'البرنامج *', 'rk-coach-hub' ); ?>
                            </label>
                            <select name="course_id" required
                                    style="width:100%;padding:9px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:.87rem;">
                                <?php foreach ( $booked_courses as $course ) : ?>
                                <option value="<?php echo (int) $course->id; ?>">
                                    <?php echo esc_html( $course->title ); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label style="font-size:.82rem;font-weight:700;color:#374151;display:block;margin-bottom:4px;">
                                <?php esc_html_e( 'نسبة النجاح (%)', 'rk-coach-hub' ); ?>
                            </label>
                            <input type="number" name="passing_grade" min="0" max="100" value="80"
                                   style="width:100%;padding:9px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:.87rem;">
                        </div>
                    </div>

                    <div style="margin-bottom:18px;">
                        <label style="font-size:.82rem;font-weight:700;color:#374151;display:block;margin-bottom:4px;">
                            <?php esc_html_e( 'عنوان الاختبار *', 'rk-coach-hub' ); ?>
                        </label>
                        <input type="text" name="quiz_title" required dir="rtl"
                               style="width:100%;padding:9px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:.88rem;"
                               placeholder="<?php esc_attr_e( 'مثال: اختبار مهارات القيادة', 'rk-coach-hub' ); ?>">
                    </div>

                    <!-- Bloc Aسئلة (5 max, les 4 premiers masqués) -->
                    <p style="font-size:.82rem;font-weight:700;color:#374151;margin:0 0 10px;">
                        <?php esc_html_e( 'الأسئلة (حتى 5)', 'rk-coach-hub' ); ?>
                        <span style="font-weight:400;color:#94a3b8;font-size:.78rem;">
                            — <?php esc_html_e( 'الخانات الفارغة تُتجاهل', 'rk-coach-hub' ); ?>
                        </span>
                    </p>

                    <div id="rk-qz-qs-<?php echo $child_id; ?>">
                    <?php for ( $qi = 0; $qi < 5; $qi++ ) : ?>
                    <div class="rk-qz-q-block" style="<?php echo $qi > 0 ? 'display:none;' : ''; ?>border:1.5px solid #e2e8f0;border-radius:10px;padding:14px;margin-bottom:10px;background:#fff;">
                        <p style="font-weight:700;font-size:.82rem;color:var(--e-global-color-secondary,#1B4F8C);margin:0 0 10px;">
                            <?php echo esc_html( sprintf( __( 'السؤال %d', 'rk-coach-hub' ), $qi + 1 ) ); ?>
                        </p>
                        <div style="margin-bottom:10px;">
                            <input type="text" name="questions[<?php echo $qi; ?>][title]" dir="rtl"
                                   style="width:100%;padding:8px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:.86rem;"
                                   placeholder="<?php esc_attr_e( 'نص السؤال...', 'rk-coach-hub' ); ?>">
                        </div>
                        <div style="display:grid;gap:7px;">
                            <?php for ( $ai = 0; $ai < 4; $ai++ ) : ?>
                            <div style="display:flex;align-items:center;gap:8px;">
                                <input type="radio"
                                       name="questions[<?php echo $qi; ?>][correct]"
                                       value="<?php echo $ai; ?>"
                                       <?php echo $ai === 0 ? 'checked' : ''; ?>
                                       title="<?php esc_attr_e( 'الإجابة الصحيحة', 'rk-coach-hub' ); ?>"
                                       style="accent-color:var(--e-global-color-primary,#E8500A);width:16px;height:16px;flex-shrink:0;">
                                <input type="text" name="questions[<?php echo $qi; ?>][options][<?php echo $ai; ?>]"
                                       dir="rtl"
                                       style="flex:1;padding:7px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:.84rem;"
                                       placeholder="<?php echo esc_attr( sprintf( __( 'الخيار %d', 'rk-coach-hub' ), $ai + 1 ) ); ?>">
                            </div>
                            <?php endfor; ?>
                        </div>
                        <p style="font-size:.72rem;color:#94a3b8;margin:6px 0 0;">
                            <?php esc_html_e( '○ أمام الخيار الصحيح', 'rk-coach-hub' ); ?>
                        </p>
                    </div>
                    <?php endfor; ?>
                    </div>

                    <button type="button"
                            onclick="rkQzAddQ('rk-qz-qs-<?php echo $child_id; ?>')"
                            class="rk-ch-btn rk-ch-btn--ghost rk-ch-btn--sm"
                            style="margin-bottom:16px;"
                            id="rk-qz-add-<?php echo $child_id; ?>">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                        <?php esc_html_e( 'إضافة سؤال', 'rk-coach-hub' ); ?>
                    </button>

                    <div>
                        <button type="submit" class="rk-ch-btn rk-ch-btn--primary">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                            <?php esc_html_e( 'إنشاء الاختبار', 'rk-coach-hub' ); ?>
                        </button>
                    </div>
                </form>
            </div>
        </details>
        <script>
        function rkQzAddQ(cid){
            var wrap=document.getElementById(cid);
            if(!wrap)return;
            var blocks=wrap.querySelectorAll('.rk-qz-q-block');
            var shown=0;
            for(var i=0;i<blocks.length;i++){
                if(blocks[i].style.display!=='none') shown++;
            }
            if(shown>=blocks.length){
                document.getElementById('rk-qz-add-<?php echo $child_id; ?>').style.display='none';
                return;
            }
            var next=blocks[shown];
            if(next) next.style.display='block';
            if(shown+1>=blocks.length){
                document.getElementById('rk-qz-add-<?php echo $child_id; ?>').style.display='none';
            }
        }
        </script>
        <?php endif; ?>

        <!-- ── الاختبارات المنشأة + روابط ──────────────────────────── -->
        <?php
        $created_quizzes = self::get_coach_quizzes_for_child( $child_id, get_current_user_id() );
        if ( ! empty( $created_quizzes ) ) :
        ?>
        <div class="rk-ch-section">
            <div class="rk-ch-section-head">
                <h3 class="rk-ch-section-title"><?php esc_html_e( 'الاختبارات المنشأة', 'rk-coach-hub' ); ?></h3>
                <span class="rk-ch-pill rk-ch-pill--blue"><?php echo count( $created_quizzes ); ?></span>
            </div>
            <div class="rk-ch-table-wrap">
                <table class="rk-ch-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'الاختبار', 'rk-coach-hub' ); ?></th>
                            <th><?php esc_html_e( 'البرنامج', 'rk-coach-hub' ); ?></th>
                            <th><?php esc_html_e( 'الرابط', 'rk-coach-hub' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $created_quizzes as $qz ) :
                            $quiz_url = get_permalink( (int) $qz->quiz_id );
                        ?>
                        <tr>
                            <td style="font-weight:600;"><?php echo esc_html( $qz->quiz_title ); ?></td>
                            <td style="color:#64748b;font-size:.85rem;"><?php echo esc_html( $qz->course_title ); ?></td>
                            <td>
                                <div style="display:flex;gap:6px;flex-wrap:wrap;">
                                    <a href="<?php echo esc_url( $quiz_url ); ?>" target="_blank"
                                       class="rk-ch-btn rk-ch-btn--ghost rk-ch-btn--sm">
                                        <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
                                        <?php esc_html_e( 'فتح', 'rk-coach-hub' ); ?>
                                    </a>
                                    <button type="button"
                                            class="rk-ch-btn rk-ch-btn--ghost rk-ch-btn--sm"
                                            data-copy-url="<?php echo esc_attr( $quiz_url ); ?>"
                                            onclick="var b=this;navigator.clipboard.writeText(b.dataset.copyUrl).then(function(){var t=b.textContent;b.textContent='✓';setTimeout(function(){b.textContent=t;},1500);});">
                                        <?php esc_html_e( 'نسخ الرابط', 'rk-coach-hub' ); ?>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- ── Résultats اختبارات ───────────────────────────────────── -->
        <div class="rk-ch-section">
            <div class="rk-ch-section-head">
                <h3 class="rk-ch-section-title"><?php esc_html_e( 'نتائج الاختبارات', 'rk-coach-hub' ); ?></h3>
                <span class="rk-ch-pill rk-ch-pill--blue"><?php echo count( $quiz_res ); ?></span>
            </div>
            <?php if ( empty( $quiz_res ) ) : ?>
            <div class="rk-ch-section-body">
                <p style="color:#94a3b8;font-size:.85rem;"><?php esc_html_e( 'لم يُنجز أي اختبار بعد.', 'rk-coach-hub' ); ?></p>
            </div>
            <?php else : ?>
            <div class="rk-ch-table-wrap">
                <table class="rk-ch-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'الاختبار', 'rk-coach-hub' ); ?></th>
                            <th><?php esc_html_e( 'التاريخ', 'rk-coach-hub' ); ?></th>
                            <th><?php esc_html_e( 'النتيجة', 'rk-coach-hub' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $quiz_res as $q ) :
                            $pct   = (int) ( $q['score_pct'] ?? 0 );
                            $color = $pct >= 80 ? '#166534' : ( $pct >= 50 ? '#92400e' : '#b91c1c' );
                            $bg    = $pct >= 80 ? '#dcfce7' : ( $pct >= 50 ? '#fef3c7' : '#fee2e2' );
                        ?>
                        <tr>
                            <td><?php echo esc_html( $q['quiz_title'] ?? '—' ); ?></td>
                            <td><?php echo ! empty( $q['attempt_date'] ) ? esc_html( date_i18n( 'j/m/Y', strtotime( $q['attempt_date'] ) ) ) : '—'; ?></td>
                            <td>
                                <span style="padding:3px 10px;border-radius:20px;font-size:.8rem;font-weight:700;color:<?php echo esc_attr( $color ); ?>;background:<?php echo esc_attr( $bg ); ?>;">
                                    <?php echo $pct; ?>%
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

        </div>
        <?php
    }

}
