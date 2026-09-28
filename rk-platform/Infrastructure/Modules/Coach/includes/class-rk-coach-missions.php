<?php
declare( strict_types=1 );
/**
 * RK_Coach_Missions — Coach assigne une mission personnalisée à un enfant.
 *
 * Table : wp_rk_coach_missions (créée au boot)
 * URL   : /dashboard/rk-assigner-mission/
 * POST  : admin-post → action rk_coach_assign_mission
 *
 * @package RK_Coach_Hub
 * @since   1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class RK_Coach_Missions {

    /* ─── Init table ────────────────────────────────────────────────── */

    public static function maybe_create_table(): void {
        RKP_CoachMissionRepository::maybe_create_table();
    }

    private static function ensure_file_columns(): void {
        RKP_CoachMissionRepository::ensure_file_columns();
    }

    /* ─── Rendu page ────────────────────────────────────────────────── */

    public static function render(): void {
        self::ensure_file_columns();
        $coach_id  = get_current_user_id();
        $child_id  = absint( $_GET['child_id'] ?? 0 );
        $submitted = ! empty( $_GET['mission_done'] );
        $error     = sanitize_text_field( $_GET['mission_error'] ?? '' );
        $students  = RK_Coach_Data::get_coach_students( $coach_id );
        $missions  = self::get_active_missions( $coach_id );
        ?>
        <div class="rk-coach-page" dir="rtl">

            <!-- Top bar -->
            <div class="rk-ch-topbar">
                <h2 class="rk-ch-topbar__title">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="3"/></svg>
                    <?php esc_html_e( 'المهام', 'rk-coach-hub' ); ?>
                </h2>
                <?php if ( ! empty( $missions ) ) : ?>
                <span class="rk-ch-pill rk-ch-pill--blue">
                    <?php echo count( $missions ); ?> <?php esc_html_e( 'مهمة نشطة', 'rk-coach-hub' ); ?>
                </span>
                <?php endif; ?>
            </div>

            <!-- Notices -->
            <?php if ( $submitted ) : ?>
            <div class="rk-ch-notice rk-ch-notice--success">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg>
                <?php esc_html_e( 'تم إسناد المهمة بنجاح.', 'rk-coach-hub' ); ?>
            </div>
            <?php endif; ?>
            <?php if ( $error ) : ?>
            <div class="rk-ch-notice rk-ch-notice--error">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                <?php echo esc_html( urldecode( $error ) ); ?>
            </div>
            <?php endif; ?>

            <div class="rk-missions-layout">

                <!-- ═══ Formulaire ═══════════════════════════════════════ -->
                <div class="rk-ch-section rk-missions-form-section">
                    <div class="rk-ch-section-head">
                        <div class="rk-ch-section-head__left">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--e-global-color-secondary,#1B4F8C)" stroke-width="2.5" aria-hidden="true"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                            <h3 class="rk-ch-section-title"><?php esc_html_e( 'إسناد مهمة جديدة', 'rk-coach-hub' ); ?></h3>
                        </div>
                    </div>
                    <div class="rk-ch-section-body">
                        <form class="rk-ch-eval-form rk-mission-form"
                              method="post"
                              enctype="multipart/form-data"
                              action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                            <?php wp_nonce_field( 'rk_coach_assign_mission', 'rk_mission_nonce' ); ?>
                            <input type="hidden" name="action" value="rk_coach_assign_mission">

                            <!-- Élève -->
                            <div class="rk-ch-eval-form__field rk-ch-eval-form__field--full">
                                <label class="rk-ch-eval-form__label" for="rk-mission-child">
                                    <?php esc_html_e( 'الطالب', 'rk-coach-hub' ); ?>
                                    <span style="color:#e11d48;">*</span>
                                </label>
                                <select id="rk-mission-child" name="child_id" required>
                                    <option value=""><?php esc_html_e( '— اختر الطالب —', 'rk-coach-hub' ); ?></option>
                                    <?php foreach ( $students as $s ) : ?>
                                    <option value="<?php echo (int) $s->child_id; ?>"
                                            <?php selected( $child_id, (int) $s->child_id ); ?>>
                                        <?php echo esc_html( $s->child_name ); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- Titre -->
                            <div class="rk-ch-eval-form__field rk-ch-eval-form__field--full">
                                <label class="rk-ch-eval-form__label" for="rk-mission-title">
                                    <?php esc_html_e( 'عنوان المهمة', 'rk-coach-hub' ); ?>
                                    <span style="color:#e11d48;">*</span>
                                </label>
                                <input type="text" id="rk-mission-title" name="title" required
                                       placeholder="<?php esc_attr_e( 'مثال: تدرّب على المحادثة لمدة 10 دقائق يوميًا', 'rk-coach-hub' ); ?>">
                            </div>

                            <!-- Description -->
                            <div class="rk-ch-eval-form__field rk-ch-eval-form__field--full">
                                <label class="rk-ch-eval-form__label" for="rk-mission-desc">
                                    <?php esc_html_e( 'الوصف', 'rk-coach-hub' ); ?>
                                    <span class="rk-ch-eval-form__hint"><?php esc_html_e( '(اختياري)', 'rk-coach-hub' ); ?></span>
                                </label>
                                <textarea id="rk-mission-desc" name="description" rows="3"
                                          placeholder="<?php esc_attr_e( 'تفاصيل المهمة وكيفية تنفيذها...', 'rk-coach-hub' ); ?>"></textarea>
                            </div>

                            <!-- Répétitions + Points + Date -->
                            <div class="rk-ch-eval-form__row rk-mission-form__row3">
                                <div class="rk-ch-eval-form__field">
                                    <label class="rk-ch-eval-form__label" for="rk-mission-target">
                                        <?php esc_html_e( 'مرات التكرار', 'rk-coach-hub' ); ?>
                                    </label>
                                    <input type="number" id="rk-mission-target" name="target"
                                           min="1" max="30" value="1" required>
                                </div>
                                <div class="rk-ch-eval-form__field">
                                    <label class="rk-ch-eval-form__label" for="rk-mission-points">
                                        <?php esc_html_e( 'النقاط', 'rk-coach-hub' ); ?>
                                    </label>
                                    <input type="number" id="rk-mission-points" name="points"
                                           min="5" max="100" value="20" required>
                                </div>
                                <div class="rk-ch-eval-form__field">
                                    <label class="rk-ch-eval-form__label" for="rk-mission-due">
                                        <?php esc_html_e( 'تاريخ الانتهاء', 'rk-coach-hub' ); ?>
                                        <span class="rk-ch-eval-form__hint"><?php esc_html_e( '(اختياري)', 'rk-coach-hub' ); ?></span>
                                    </label>
                                    <input type="date" id="rk-mission-due" name="due_date"
                                           min="<?php echo esc_attr( date( 'Y-m-d' ) ); ?>">
                                </div>
                            </div>

                            <!-- Upload fichier -->
                            <div class="rk-ch-eval-form__field rk-ch-eval-form__field--full">
                                <label class="rk-ch-eval-form__label" for="rk-mission-file">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="vertical-align:-2px" aria-hidden="true"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>
                                    <?php esc_html_e( 'ملف مرفق', 'rk-coach-hub' ); ?>
                                    <span class="rk-ch-eval-form__hint"><?php esc_html_e( '(PDF, صورة، Word — اختياري)', 'rk-coach-hub' ); ?></span>
                                </label>
                                <div class="rk-mission-file-drop" id="rk-mission-file-drop">
                                    <input type="file" id="rk-mission-file" name="mission_file"
                                           accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.gif,.webp,.mp4,.mp3"
                                           class="rk-mission-file-input">
                                    <div class="rk-mission-file-drop__ui" aria-hidden="true">
                                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="var(--e-global-color-secondary,#1B4F8C)" stroke-width="1.5"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                                        <p class="rk-mission-file-drop__text">
                                            <?php esc_html_e( 'اسحب الملف هنا أو', 'rk-coach-hub' ); ?>
                                            <span class="rk-mission-file-drop__link"><?php esc_html_e( 'اختر من جهازك', 'rk-coach-hub' ); ?></span>
                                        </p>
                                        <p class="rk-mission-file-drop__hint"><?php esc_html_e( 'الحد الأقصى: 10 ميغابايت', 'rk-coach-hub' ); ?></p>
                                    </div>
                                    <div class="rk-mission-file-preview" id="rk-mission-file-preview" style="display:none;">
                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                                        <span id="rk-mission-file-name"></span>
                                        <button type="button" class="rk-mission-file-clear" id="rk-mission-file-clear" aria-label="<?php esc_attr_e( 'حذف الملف', 'rk-coach-hub' ); ?>">×</button>
                                    </div>
                                </div>
                            </div>

                            <div class="rk-ch-eval-form__submit">
                                <button type="submit" class="rk-ch-btn rk-ch-btn--primary rk-ch-btn--lg" id="rk-mission-submit">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="3"/></svg>
                                    <?php esc_html_e( 'إسناد المهمة', 'rk-coach-hub' ); ?>
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- ═══ Missions actives ══════════════════════════════════ -->
                <div class="rk-ch-section rk-missions-active-section">
                    <div class="rk-ch-section-head">
                        <div class="rk-ch-section-head__left">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--e-global-color-secondary,#1B4F8C)" stroke-width="2.5" aria-hidden="true"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
                            <h3 class="rk-ch-section-title"><?php esc_html_e( 'المهام النشطة', 'rk-coach-hub' ); ?></h3>
                        </div>
                        <?php if ( ! empty( $missions ) ) : ?>
                        <span class="rk-ch-pill rk-ch-pill--blue"><?php echo count( $missions ); ?></span>
                        <?php endif; ?>
                    </div>
                    <?php if ( empty( $missions ) ) : ?>
                    <div class="rk-ch-section-body">
                        <div class="rk-ch-empty">
                            <div class="rk-ch-empty__icon">
                                <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="3"/></svg>
                            </div>
                            <p class="rk-ch-empty__title"><?php esc_html_e( 'لا توجد مهام نشطة', 'rk-coach-hub' ); ?></p>
                            <p class="rk-ch-empty__text"><?php esc_html_e( 'أسند مهمة للطلاب من النموذج المجاور.', 'rk-coach-hub' ); ?></p>
                        </div>
                    </div>
                    <?php else : ?>
                    <div class="rk-ch-section-body" style="padding:0;">
                        <div class="rk-missions-active-list">
                        <?php foreach ( $missions as $m ) :
                            $pct     = (int) $m->target > 0 ? (int) round( (int) $m->progress / (int) $m->target * 100 ) : 0;
                            $bar_col = $pct >= 80 ? '#16a34a' : ( $pct >= 40 ? '#d97706' : 'var(--e-global-color-secondary,#1B4F8C)' );
                            $has_file = ! empty( $m->file_url );
                        ?>
                        <div class="rk-mission-active-card">
                            <div class="rk-mission-active-card__header">
                                <div class="rk-mission-active-card__student">
                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                                    <?php echo esc_html( $m->child_name ?: '—' ); ?>
                                </div>
                                <?php if ( $m->due_date ) : ?>
                                <span class="rk-mission-active-card__due">
                                    <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                                    <?php echo esc_html( date_i18n( 'j/m', strtotime( $m->due_date ) ) ); ?>
                                </span>
                                <?php endif; ?>
                            </div>
                            <p class="rk-mission-active-card__title"><?php echo esc_html( $m->title ); ?></p>
                            <div class="rk-mission-active-card__progress">
                                <div class="rk-mission-active-card__bar-wrap">
                                    <div class="rk-mission-active-card__bar"
                                         style="width:<?php echo $pct; ?>%;background:<?php echo esc_attr( $bar_col ); ?>"></div>
                                </div>
                                <span class="rk-mission-active-card__pct"><?php echo $pct; ?>%</span>
                            </div>
                            <div class="rk-mission-active-card__footer">
                                <span class="rk-mission-active-card__count"><?php echo (int) $m->progress; ?>/<?php echo (int) $m->target; ?></span>
                                <span class="rk-mission-active-card__pts">⚡ <?php echo (int) $m->points; ?> <?php esc_html_e( 'نقطة', 'rk-coach-hub' ); ?></span>
                                <div class="rk-mission-active-card__actions">
                                    <?php if ( $has_file ) : ?>
                                    <a href="<?php echo esc_url( $m->file_url ); ?>" target="_blank" rel="noopener"
                                       class="rk-ch-btn rk-ch-btn--ghost rk-ch-btn--sm" title="<?php echo esc_attr( $m->file_name ?: 'الملف' ); ?>">
                                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                                    </a>
                                    <?php endif; ?>
                                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
                                        <?php wp_nonce_field( 'rk_mission_progress_' . $m->id, 'rk_mp_nonce' ); ?>
                                        <input type="hidden" name="action"     value="rk_coach_mission_progress">
                                        <input type="hidden" name="mission_id" value="<?php echo (int) $m->id; ?>">
                                        <button type="submit" class="rk-ch-btn rk-ch-btn--ghost rk-ch-btn--sm"
                                                title="<?php esc_attr_e( 'تسجيل تقدم +1', 'rk-coach-hub' ); ?>">+1</button>
                                    </form>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

            </div><!-- /.rk-missions-layout -->

        </div>

        <style>
        .rk-missions-layout {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
            align-items: start;
        }
        @media (max-width: 768px) {
            .rk-missions-layout { grid-template-columns: 1fr; }
            .rk-missions-active-section { order: -1; }
        }

        /* File drop zone */
        .rk-mission-file-drop {
            position: relative;
            border: 2px dashed #cbd5e1;
            border-radius: 10px;
            padding: 20px 16px;
            text-align: center;
            cursor: pointer;
            transition: border-color .2s, background .2s;
            background: #f8fafc;
        }
        .rk-mission-file-drop:hover,
        .rk-mission-file-drop.drag-over {
            border-color: var(--e-global-color-secondary,#1B4F8C);
            background: #eff6ff;
        }
        .rk-mission-file-input {
            position: absolute;
            inset: 0;
            width: 100%;
            height: 100%;
            opacity: 0;
            cursor: pointer;
        }
        .rk-mission-file-drop__ui { pointer-events: none; }
        .rk-mission-file-drop__text {
            margin: 8px 0 4px;
            font-size: .9rem;
            color: #475569;
        }
        .rk-mission-file-drop__link {
            color: var(--e-global-color-secondary,#1B4F8C);
            font-weight: 600;
            text-decoration: underline;
        }
        .rk-mission-file-drop__hint { font-size: .78rem; color: #94a3b8; margin: 0; }
        .rk-mission-file-preview {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: .88rem;
            color: #166534;
            font-weight: 500;
            justify-content: center;
            pointer-events: auto;
        }
        .rk-mission-file-clear {
            background: none;
            border: none;
            color: #94a3b8;
            cursor: pointer;
            font-size: 1.2rem;
            line-height: 1;
            padding: 0 2px;
        }
        .rk-mission-file-clear:hover { color: #e11d48; }

        /* Active mission cards */
        .rk-missions-active-list {
            display: flex;
            flex-direction: column;
            gap: 0;
        }
        .rk-mission-active-card {
            padding: 14px 18px;
            border-bottom: 1px solid #f1f5f9;
        }
        .rk-mission-active-card:last-child { border-bottom: none; }
        .rk-mission-active-card__header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 4px;
        }
        .rk-mission-active-card__student {
            font-size: .78rem;
            color: var(--e-global-color-secondary,#1B4F8C);
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 4px;
        }
        .rk-mission-active-card__due {
            font-size: .73rem;
            color: #94a3b8;
            display: flex;
            align-items: center;
            gap: 3px;
        }
        .rk-mission-active-card__title {
            font-size: .9rem;
            font-weight: 700;
            color: #1e293b;
            margin: 2px 0 8px;
        }
        .rk-mission-active-card__progress {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 8px;
        }
        .rk-mission-active-card__bar-wrap {
            flex: 1;
            height: 5px;
            background: #e2e8f0;
            border-radius: 99px;
            overflow: hidden;
        }
        .rk-mission-active-card__bar {
            height: 100%;
            border-radius: 99px;
            transition: width .4s;
        }
        .rk-mission-active-card__pct {
            font-size: .72rem;
            color: #64748b;
            font-weight: 600;
            min-width: 30px;
        }
        .rk-mission-active-card__footer {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: .78rem;
        }
        .rk-mission-active-card__count { color: #64748b; }
        .rk-mission-active-card__pts { color: #f59e0b; font-weight: 600; flex: 1; }
        .rk-mission-active-card__actions { display: flex; align-items: center; gap: 4px; }

        /* Row 3-col */
        .rk-mission-form__row3 {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 12px;
        }
        @media (max-width: 500px) {
            .rk-mission-form__row3 { grid-template-columns: 1fr 1fr; }
            .rk-mission-form__row3 > :last-child { grid-column: 1 / -1; }
        }
        </style>

        <script>
        (function(){
            var drop = document.getElementById('rk-mission-file-drop');
            var input = document.getElementById('rk-mission-file');
            var preview = document.getElementById('rk-mission-file-preview');
            var nameEl = document.getElementById('rk-mission-file-name');
            var clearBtn = document.getElementById('rk-mission-file-clear');
            var submitBtn = document.getElementById('rk-mission-submit');

            if (!drop || !input) return;

            function showFile(name) {
                drop.querySelector('.rk-mission-file-drop__ui').style.display = 'none';
                nameEl.textContent = name;
                preview.style.display = 'flex';
            }
            function clearFile() {
                input.value = '';
                drop.querySelector('.rk-mission-file-drop__ui').style.display = '';
                preview.style.display = 'none';
            }

            input.addEventListener('change', function() {
                if (this.files && this.files[0]) showFile(this.files[0].name);
            });
            clearBtn.addEventListener('click', function(e) {
                e.stopPropagation();
                clearFile();
            });

            // Drag & drop
            ['dragenter','dragover'].forEach(function(ev) {
                drop.addEventListener(ev, function(e) {
                    e.preventDefault();
                    drop.classList.add('drag-over');
                });
            });
            ['dragleave','drop'].forEach(function(ev) {
                drop.addEventListener(ev, function(e) {
                    e.preventDefault();
                    drop.classList.remove('drag-over');
                });
            });
            drop.addEventListener('drop', function(e) {
                var files = e.dataTransfer && e.dataTransfer.files;
                if (files && files[0]) {
                    var dt = new DataTransfer();
                    dt.items.add(files[0]);
                    input.files = dt.files;
                    showFile(files[0].name);
                }
            });

            // Loading state on submit
            var form = drop.closest('form');
            if (form && submitBtn) {
                form.addEventListener('submit', function() {
                    submitBtn.disabled = true;
                    submitBtn.textContent = '...';
                });
            }
        })();
        </script>
        <?php
    }

    /* ─── Handler : assigner mission ───────────────────────────────── */

    public static function handle_assign(): void {
        if ( ! check_admin_referer( 'rk_coach_assign_mission', 'rk_mission_nonce' ) ) wp_die( 'Nonce invalide.' );
        if ( ! current_user_can( 'tutor_instructor' ) ) wp_die( 'Accès refusé.' );

        $redirect = tutor_utils()->tutor_dashboard_url( 'rk-assigner-mission' );
        $child_id = absint( $_POST['child_id'] ?? 0 );
        $title    = sanitize_text_field( $_POST['title'] ?? '' );
        $desc     = sanitize_textarea_field( $_POST['description'] ?? '' );
        $target   = max( 1, min( 30, absint( $_POST['target'] ?? 1 ) ) );
        $points   = max( 5, min( 100, absint( $_POST['points'] ?? 20 ) ) );
        $due_raw  = sanitize_text_field( $_POST['due_date'] ?? '' );
        $due      = ( $due_raw && function_exists( 'rk_validate_date' ) && rk_validate_date( $due_raw ) ) ? $due_raw : '';
        $coach_id = get_current_user_id();

        if ( ! $child_id || ! $title ) {
            wp_redirect( add_query_arg( 'mission_error', rawurlencode( 'يرجى ملء الحقول المطلوبة.' ), $redirect ) );
            exit;
        }

        if ( ! RK_Coach_Data::coach_owns_child( $coach_id, $child_id ) ) {
            wp_die( 'هذا الطالب غير مرتبط بك.' );
        }

        // File upload
        $file_url  = '';
        $file_name = '';
        if ( ! empty( $_FILES['mission_file']['name'] ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            $allowed = [ 'pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'mp4', 'mp3' ];
            $ext     = strtolower( pathinfo( $_FILES['mission_file']['name'], PATHINFO_EXTENSION ) );
            if ( in_array( $ext, $allowed, true ) && (int) $_FILES['mission_file']['size'] <= 10 * 1024 * 1024 ) {
                $overrides = [ 'test_form' => false, 'test_type' => false ];
                $uploaded  = wp_handle_upload( $_FILES['mission_file'], $overrides );
                if ( isset( $uploaded['url'] ) && ! isset( $uploaded['error'] ) ) {
                    $file_url  = $uploaded['url'];
                    $file_name = sanitize_file_name( $_FILES['mission_file']['name'] );
                }
            }
        }

        RKP_CoachMissionCommandService::assign( [
            'child_id'    => $child_id,
            'coach_id'    => $coach_id,
            'title'       => $title,
            'description' => $desc,
            'target'      => $target,
            'points'      => $points,
            'due_date'    => $due ?: null,
            'file_url'    => $file_url,
            'file_name'   => $file_name,
        ] );

        if ( class_exists( 'RK_MC_Child_Dashboard_Data' ) ) {
            RK_MC_Child_Dashboard_Data::clear_cache( $child_id );
        }

        wp_redirect( add_query_arg( [ 'mission_done' => '1', 'child_id' => $child_id ], $redirect ) );
        exit;
    }

    /* ─── Handler : enregistrer progrès ────────────────────────────── */

    public static function handle_progress(): void {
        $mission_id = absint( $_POST['mission_id'] ?? 0 );
        if ( ! check_admin_referer( 'rk_mission_progress_' . $mission_id, 'rk_mp_nonce' ) ) wp_die();
        if ( ! current_user_can( 'tutor_instructor' ) ) wp_die();

        $coach_id = get_current_user_id();
        $m        = RKP_CoachMissionRepository::find_by_id( $mission_id );
        if ( ! $m || $m->completed ) {
            wp_redirect( tutor_utils()->tutor_dashboard_url( 'rk-assigner-mission' ) );
            exit;
        }
        if ( (int) $m->coach_id !== $coach_id ) {
            wp_die( 'Accès refusé.' );
        }

        RKP_CoachMissionCommandService::record_progress( $mission_id, $coach_id );

        if ( class_exists( 'RK_MC_Child_Dashboard_Data' ) ) {
            RK_MC_Child_Dashboard_Data::clear_cache( (int) $m->child_id );
        }

        wp_redirect( tutor_utils()->tutor_dashboard_url( 'rk-assigner-mission' ) );
        exit;
    }

    /* ─── Getters ───────────────────────────────────────────────────── */

    public static function get_active_missions( int $coach_id ): array {
        return RKP_CoachMissionQueryService::get_active_missions( $coach_id );
    }

    public static function get_missions_for_child( int $child_id ): array {
        return RKP_CoachMissionQueryService::get_missions_for_child( $child_id );
    }
}
