<?php
declare( strict_types=1 );
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Extrait de class-rk-coach-child-detail.php — factorisation par fonctionnalité.
 * Code déplacé verbatim, aucune modification de logique.
 */
trait RK_Coach_Child_Tabs_Progress {
    /* ─── Onglet 5 : Évaluations (rk_child_assessments + formulaire inline) */

    private static function tab_evals( int $child_id, int $coach_id ): void {
        $evals  = class_exists( 'RK_MC_Assessment_Service' )
            ? RK_MC_Assessment_Service::get_all( $child_id )
            : [];
        $action = admin_url( 'admin-post.php' );
        $saved  = isset( $_GET['eval_done'] );
        $skills = [ 'speech' => 'الكلام', 'teamwork' => 'التعاون', 'creativity' => 'الأفكار', 'courage' => 'الشجاعة', 'leadership' => 'القيادة', 'focus' => 'التركيز' ];
        $nonce  = wp_create_nonce( 'rk_coach_eval_inline' );
        ?>
        <div style="display:grid;gap:20px;">

        <?php if ( $saved ) : ?>
        <div class="rk-ch-notice rk-ch-notice--success">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg>
            <?php esc_html_e( 'تم حفظ التقييم بنجاح.', 'rk-coach-hub' ); ?>
        </div>
        <?php endif; ?>

        <!-- Formulaire inline -->
        <details class="rk-inline-panel" <?php echo $saved ? 'open' : ''; ?>>
            <summary class="rk-ch-btn rk-ch-btn--primary rk-ch-btn--sm" style="display:inline-flex;list-style:none;cursor:pointer;margin-bottom:0;">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                <?php esc_html_e( 'تقييم جديد', 'rk-coach-hub' ); ?>
            </summary>
            <div class="rk-inline-panel__body" style="background:#f8fafc;border-radius:0 0 12px 12px;padding:20px;border:1px solid #e2e8f0;border-top:none;">
                <form method="post" action="<?php echo esc_url( $action ); ?>">
                    <input type="hidden" name="action"   value="rk_coach_save_eval_inline">
                    <input type="hidden" name="child_id" value="<?php echo $child_id; ?>">
                    <input type="hidden" name="_nonce"   value="<?php echo esc_attr( $nonce ); ?>">
                    <input type="hidden" name="_tab"     value="evals">

                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;">
                        <div>
                            <label style="font-size:.82rem;font-weight:700;color:#374151;display:block;margin-bottom:4px;"><?php esc_html_e( 'التقييم العام (1-5 نجوم)', 'rk-coach-hub' ); ?></label>
                            <select name="rating" required style="width:100%;padding:8px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:.88rem;">
                                <?php for ( $i = 5; $i >= 1; $i-- ) : ?>
                                <option value="<?php echo $i; ?>"><?php echo str_repeat( '⭐', $i ) . ' (' . $i . ')'; ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div>
                            <label style="font-size:.82rem;font-weight:700;color:#374151;display:block;margin-bottom:4px;"><?php esc_html_e( 'تاريخ الجلسة', 'rk-coach-hub' ); ?></label>
                            <input type="date" name="assessed_at" value="<?php echo esc_attr( current_time( 'Y-m-d' ) ); ?>"
                                   style="width:100%;padding:8px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:.88rem;">
                        </div>
                    </div>

                    <div style="margin-bottom:16px;">
                        <label style="font-size:.82rem;font-weight:700;color:#374151;display:block;margin-bottom:4px;"><?php esc_html_e( 'ملخص التقييم', 'rk-coach-hub' ); ?></label>
                        <textarea name="summary" rows="3" dir="rtl"
                                  style="width:100%;padding:8px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:.87rem;resize:vertical;"
                                  placeholder="<?php esc_attr_e( 'تقييم عام لأداء الطالب في هذه الجلسة...', 'rk-coach-hub' ); ?>"></textarea>
                    </div>

                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:16px;">
                        <div>
                            <label style="font-size:.82rem;font-weight:700;color:#374151;display:block;margin-bottom:4px;"><?php esc_html_e( 'نقاط القوة (سطر لكل نقطة)', 'rk-coach-hub' ); ?></label>
                            <textarea name="strengths" rows="3" dir="rtl"
                                      style="width:100%;padding:8px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:.87rem;resize:vertical;"
                                      placeholder="<?php esc_attr_e( 'التعاون\nالثقة بالنفس', 'rk-coach-hub' ); ?>"></textarea>
                        </div>
                        <div>
                            <label style="font-size:.82rem;font-weight:700;color:#374151;display:block;margin-bottom:4px;"><?php esc_html_e( 'محاور التطوير', 'rk-coach-hub' ); ?></label>
                            <textarea name="developments" rows="3" dir="rtl"
                                      style="width:100%;padding:8px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:.87rem;resize:vertical;"
                                      placeholder="<?php esc_attr_e( 'التركيز\nضبط الوقت', 'rk-coach-hub' ); ?>"></textarea>
                        </div>
                    </div>

                    <!-- Compétences (radares) -->
                    <div style="margin-bottom:16px;">
                        <label style="font-size:.82rem;font-weight:700;color:#374151;display:block;margin-bottom:8px;"><?php esc_html_e( 'تقييم المهارات (0-5)', 'rk-coach-hub' ); ?></label>
                        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;">
                            <?php foreach ( $skills as $key => $label ) : ?>
                            <label style="font-size:.82rem;color:#374151;">
                                <?php echo esc_html( $label ); ?>
                                <input type="number" name="skill_scores[<?php echo esc_attr( $key ); ?>]"
                                       min="0" max="5" value="3"
                                       style="width:60px;margin-right:6px;padding:4px;border:1.5px solid #e2e8f0;border-radius:6px;">
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Message personnel coach (Sprint 3) -->
                    <div style="margin-bottom:16px;background:#fff7ed;border-radius:10px;padding:14px;">
                        <label style="font-size:.82rem;font-weight:700;color:#92400e;display:block;margin-bottom:4px;">
                            💬 <?php esc_html_e( 'رسالة شخصية للطالب', 'rk-coach-hub' ); ?>
                        </label>
                        <textarea name="personal_message" rows="2" dir="rtl"
                                  style="width:100%;padding:8px;border:1.5px solid #fed7aa;border-radius:8px;background:#fff;font-size:.87rem;resize:vertical;"
                                  placeholder="<?php esc_attr_e( 'رسالة تشجيع أو توجيه مباشر للطالب (اختياري)...', 'rk-coach-hub' ); ?>"></textarea>
                    </div>

                    <button type="submit" class="rk-ch-btn rk-ch-btn--primary">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/></svg>
                        <?php esc_html_e( 'حفظ التقييم', 'rk-coach-hub' ); ?>
                    </button>
                </form>
            </div>
        </details>

        <!-- Liste évaluations existantes -->
        <div class="rk-ch-section">
            <div class="rk-ch-section-head">
                <h3 class="rk-ch-section-title"><?php esc_html_e( 'سجل التقييمات', 'rk-coach-hub' ); ?></h3>
                <span class="rk-ch-pill rk-ch-pill--blue"><?php echo count( $evals ); ?></span>
            </div>
            <?php if ( empty( $evals ) ) : ?>
            <div class="rk-ch-section-body">
                <div class="rk-ch-empty">
                    <p class="rk-ch-empty__title"><?php esc_html_e( 'لا توجد تقييمات بعد', 'rk-coach-hub' ); ?></p>
                </div>
            </div>
            <?php else : ?>
            <div style="display:grid;gap:14px;padding:20px;">
                <?php foreach ( $evals as $ev ) :
                    $rating    = (int) ( $ev['rating'] ?? 0 );
                    $strengths = (array) ( $ev['strengths'] ?? [] );
                    $devs      = (array) ( $ev['developments'] ?? [] );
                ?>
                <div style="border:1.5px solid #e2e8f0;border-radius:10px;padding:14px 16px;">
                    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
                        <span style="font-weight:600;font-size:.88rem;"><?php echo esc_html( date_i18n( 'j F Y', strtotime( $ev['assessed_at'] ?? '' ) ) ); ?></span>
                        <div class="rk-ch-stars" aria-label="<?php echo esc_attr( $rating . '/5' ); ?>">
                            <?php for ( $i = 1; $i <= 5; $i++ ) : ?>
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="<?php echo $i <= $rating ? '#f59e0b' : '#e2e8f0'; ?>" aria-hidden="true"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                            <?php endfor; ?>
                        </div>
                    </div>
                    <?php if ( ! empty( $ev['summary'] ) ) : ?>
                    <p style="font-size:.84rem;color:#475569;line-height:1.6;margin:0 0 8px;"><?php echo esc_html( $ev['summary'] ); ?></p>
                    <?php endif; ?>
                    <?php if ( $strengths || $devs ) : ?>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:8px;">
                        <?php if ( $strengths ) : ?>
                        <div style="background:#f0fdf4;border-radius:6px;padding:8px 10px;">
                            <p style="font-size:.75rem;font-weight:700;color:#166534;margin:0 0 4px;"><?php esc_html_e( 'نقاط القوة', 'rk-coach-hub' ); ?></p>
                            <ul style="margin:0;padding-right:16px;font-size:.82rem;color:#166534;">
                                <?php foreach ( $strengths as $s ) : ?>
                                <li><?php echo esc_html( $s ); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                        <?php endif; ?>
                        <?php if ( $devs ) : ?>
                        <div style="background:#fff7ed;border-radius:6px;padding:8px 10px;">
                            <p style="font-size:.75rem;font-weight:700;color:#92400e;margin:0 0 4px;"><?php esc_html_e( 'للتطوير', 'rk-coach-hub' ); ?></p>
                            <ul style="margin:0;padding-right:16px;font-size:.82rem;color:#92400e;">
                                <?php foreach ( $devs as $d ) : ?>
                                <li><?php echo esc_html( $d ); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        </div>
        <?php
    }

    /* ─── Onglet 6 : Missions ───────────────────────────────────────── */

    private static function tab_missions( int $child_id, int $coach_id ): void {
        $missions = RKP_CoachMissionQueryService::get_missions_for_child_by_coach( $child_id, $coach_id );
        $action    = admin_url( 'admin-post.php' );
        $done      = isset( $_GET['mission_done'] );
        ?>
        <div style="display:grid;gap:20px;">

        <?php if ( $done ) : ?>
        <div class="rk-ch-notice rk-ch-notice--success">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg>
            <?php esc_html_e( 'تم إسناد المهمة بنجاح.', 'rk-coach-hub' ); ?>
        </div>
        <?php endif; ?>

        <!-- Formulaire d'assignation de mission inline -->
        <details class="rk-inline-panel">
            <summary class="rk-ch-btn rk-ch-btn--primary rk-ch-btn--sm" style="display:inline-flex;list-style:none;cursor:pointer;margin-bottom:0;">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                <?php esc_html_e( 'إسناد مهمة جديدة', 'rk-coach-hub' ); ?>
            </summary>
            <div class="rk-inline-panel__body" style="background:#f8fafc;border-radius:0 0 12px 12px;padding:20px;border:1px solid #e2e8f0;border-top:none;">
                <form method="post" action="<?php echo esc_url( $action ); ?>">
                    <?php wp_nonce_field( 'rk_coach_assign_mission', '_nonce' ); ?>
                    <input type="hidden" name="action"   value="rk_coach_assign_mission">
                    <input type="hidden" name="child_id" value="<?php echo $child_id; ?>">

                    <div style="margin-bottom:14px;">
                        <label style="font-size:.82rem;font-weight:700;color:#374151;display:block;margin-bottom:4px;"><?php esc_html_e( 'عنوان المهمة', 'rk-coach-hub' ); ?></label>
                        <input type="text" name="title" required dir="rtl"
                               style="width:100%;padding:9px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:.88rem;"
                               placeholder="<?php esc_attr_e( 'مثال: التدرّب على الخطاب لمدة 5 دقائق', 'rk-coach-hub' ); ?>">
                    </div>

                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px;">
                        <div>
                            <label style="font-size:.82rem;font-weight:700;color:#374151;display:block;margin-bottom:4px;"><?php esc_html_e( 'الموعد النهائي', 'rk-coach-hub' ); ?></label>
                            <input type="date" name="due_date"
                                   value="<?php echo esc_attr( date( 'Y-m-d', strtotime( '+7 days' ) ) ); ?>"
                                   style="width:100%;padding:9px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:.88rem;">
                        </div>
                        <div>
                            <label style="font-size:.82rem;font-weight:700;color:#374151;display:block;margin-bottom:4px;"><?php esc_html_e( 'نقاط XP', 'rk-coach-hub' ); ?></label>
                            <input type="number" name="xp_reward" value="10" min="0" max="100"
                                   style="width:100%;padding:9px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:.88rem;">
                        </div>
                    </div>

                    <div style="margin-bottom:14px;">
                        <label style="font-size:.82rem;font-weight:700;color:#374151;display:block;margin-bottom:4px;"><?php esc_html_e( 'وصف المهمة', 'rk-coach-hub' ); ?></label>
                        <textarea name="description" rows="2" dir="rtl"
                                  style="width:100%;padding:9px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:.87rem;resize:vertical;"
                                  placeholder="<?php esc_attr_e( 'تعليمات تفصيلية للطالب...', 'rk-coach-hub' ); ?>"></textarea>
                    </div>

                    <button type="submit" class="rk-ch-btn rk-ch-btn--primary">
                        <?php esc_html_e( 'إسناد المهمة', 'rk-coach-hub' ); ?>
                    </button>
                </form>
            </div>
        </details>

        <!-- Liste missions -->
        <div class="rk-ch-section">
            <div class="rk-ch-section-head">
                <h3 class="rk-ch-section-title"><?php esc_html_e( 'مهام الطالب', 'rk-coach-hub' ); ?></h3>
                <span class="rk-ch-pill rk-ch-pill--blue"><?php echo count( $missions ); ?></span>
            </div>
            <?php if ( empty( $missions ) ) : ?>
            <div class="rk-ch-section-body">
                <p style="color:#94a3b8;font-size:.85rem;"><?php esc_html_e( 'لا توجد مهام مسندة بعد.', 'rk-coach-hub' ); ?></p>
            </div>
            <?php else : ?>
            <div class="rk-ch-table-wrap">
                <table class="rk-ch-table">
                    <thead><tr>
                        <th><?php esc_html_e( 'المهمة', 'rk-coach-hub' ); ?></th>
                        <th><?php esc_html_e( 'الموعد', 'rk-coach-hub' ); ?></th>
                        <th><?php esc_html_e( 'الحالة', 'rk-coach-hub' ); ?></th>
                        <th>XP</th>
                    </tr></thead>
                    <tbody>
                        <?php foreach ( $missions as $m ) :
                            $is_done = (bool) ( $m->completed ?? 0 );
                            $sl = $is_done ? 'منجزة' : 'جارية';
                            $sc = $is_done ? '#166534' : '#92400e';
                            $sb = $is_done ? '#dcfce7' : '#fef3c7';
                        ?>
                        <tr>
                            <td><?php echo esc_html( $m->title ?? '' ); ?></td>
                            <td><?php echo ! empty( $m->due_date ) ? esc_html( date_i18n( 'j/m/Y', strtotime( $m->due_date ) ) ) : '—'; ?></td>
                            <td><span style="padding:3px 10px;border-radius:20px;font-size:.78rem;font-weight:700;color:<?php echo esc_attr( $sc ); ?>;background:<?php echo esc_attr( $sb ); ?>;"><?php echo esc_html( $sl ); ?></span></td>
                            <td><?php echo (int) ( $m->points ?? 0 ); ?></td>
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

    /* ─── Onglet 7 : Badges ─────────────────────────────────────────── */

    private static function tab_badges( int $child_id, int $coach_id ): void {
        $earned = RKP_BadgeRepository::find_all( $child_id );
        $catalog      = class_exists( 'RK_MC_Badge_Service' ) ? RK_MC_Badge_Service::catalogue() : [];
        $earned_keys  = array_column( $earned, 'badge_key' );
        $action       = admin_url( 'admin-post.php' );
        $done         = isset( $_GET['badge_done'] );
        // v9.14 — affiche désormais aussi l'échec silencieux d'award()
        // (badge déjà attribué / clé invalide), auparavant non signalé
        // sur cet onglet alors que handle_award_badge peut y rediriger.
        $error        = sanitize_key( $_GET['badge_error'] ?? '' );
        ?>
        <div style="display:grid;gap:20px;">

        <?php if ( $done ) : ?>
        <div class="rk-ch-notice rk-ch-notice--success">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg>
            <?php esc_html_e( 'تم منح الشارة بنجاح.', 'rk-coach-hub' ); ?>
        </div>
        <?php elseif ( 'award_failed' === $error ) : ?>
        <div class="rk-ch-notice rk-ch-notice--error">
            <?php esc_html_e( 'تعذّر منح الشارة. قد تكون الشارة غير صالحة أو ممنوحة مسبقاً.', 'rk-coach-hub' ); ?>
        </div>
        <?php endif; ?>

        <!-- Formulaire d'attribution de badge inline -->
        <?php if ( ! empty( $catalog ) ) : ?>
        <details class="rk-inline-panel">
            <summary class="rk-ch-btn rk-ch-btn--primary rk-ch-btn--sm" style="display:inline-flex;list-style:none;cursor:pointer;margin-bottom:0;">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><circle cx="12" cy="8" r="7"/><polyline points="8.21 13.89 7 23 12 20 17 23 15.79 13.88"/></svg>
                <?php esc_html_e( 'منح شارة', 'rk-coach-hub' ); ?>
            </summary>
            <div class="rk-inline-panel__body" style="background:#f8fafc;border-radius:0 0 12px 12px;padding:20px;border:1px solid #e2e8f0;border-top:none;">
                <form method="post" action="<?php echo esc_url( $action ); ?>">
                    <?php wp_nonce_field( 'rk_coach_award_badge', '_nonce' ); ?>
                    <input type="hidden" name="action"   value="rk_coach_award_badge">
                    <input type="hidden" name="child_id" value="<?php echo $child_id; ?>">
                    <div style="display:grid;grid-template-columns:1fr auto;gap:12px;align-items:flex-end;">
                        <div>
                            <label style="font-size:.82rem;font-weight:700;color:#374151;display:block;margin-bottom:4px;"><?php esc_html_e( 'اختر الشارة', 'rk-coach-hub' ); ?></label>
                            <select name="badge_key" required style="width:100%;padding:9px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:.88rem;">
                                <?php foreach ( $catalog as $key => $def ) :
                                    $already = in_array( $key, $earned_keys, true );
                                ?>
                                <option value="<?php echo esc_attr( $key ); ?>" <?php disabled( $already ); ?>>
                                    <?php echo esc_html( ( $def['icon'] ?? '🏅' ) . ' ' . ( $def['name'] ?? $key ) . ( $already ? ' ✓' : '' ) ); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button type="submit" class="rk-ch-btn rk-ch-btn--primary"><?php esc_html_e( 'منح', 'rk-coach-hub' ); ?></button>
                    </div>
                </form>
            </div>
        </details>
        <?php endif; ?>

        <!-- Badges gagnés -->
        <div class="rk-ch-section">
            <div class="rk-ch-section-head">
                <h3 class="rk-ch-section-title"><?php esc_html_e( 'الشارات المكتسبة', 'rk-coach-hub' ); ?></h3>
                <span class="rk-ch-pill rk-ch-pill--blue"><?php echo count( $earned ); ?></span>
            </div>
            <?php if ( empty( $earned ) ) : ?>
            <div class="rk-ch-section-body">
                <p style="color:#94a3b8;font-size:.85rem;"><?php esc_html_e( 'لم يحصل على أي شارة بعد.', 'rk-coach-hub' ); ?></p>
            </div>
            <?php else : ?>
            <div style="display:flex;flex-wrap:wrap;gap:12px;padding:20px;">
                <?php foreach ( $earned as $b ) :
                    $def     = $catalog[ $b->badge_key ] ?? [];
                    $ico_key = $def['icon_key'] ?? '';
                    $icon    = self::badge_emoji( $ico_key );
                    $name    = $def['name'] ?? $b->badge_key;
                ?>
                <div style="display:flex;flex-direction:column;align-items:center;gap:4px;background:#fff;border:1.5px solid #e2e8f0;border-radius:12px;padding:14px 16px;min-width:80px;">
                    <span style="font-size:1.8rem;"><?php echo esc_html( $icon ); ?></span>
                    <span style="font-size:.75rem;font-weight:700;color:#374151;text-align:center;"><?php echo esc_html( $name ); ?></span>
                    <span style="font-size:.7rem;color:#94a3b8;"><?php echo ! empty( $b->earned_at ) ? esc_html( date_i18n( 'j/m', strtotime( $b->earned_at ) ) ) : ''; ?></span>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        </div>
        <?php
    }

    /* ─── Emoji helper (badge icon_key → emoji) ────────────────────── */

    private static function badge_emoji( string $key ): string {
        $map = [
            'star'            => '⭐',
            'graduation-cap'  => '🎓',
            'nav-target'      => '🎯',
            'users'           => '👥',
            'fire'            => '🔥',
            'diamond'         => '💎',
            'lightning'       => '⚡',
            'moon'            => '🌙',
            'trophy'          => '🏆',
            'rocket'          => '🚀',
            'skill-speech'    => '🎤',
            'skill-creativity'=> '🎨',
            'gift'            => '🎁',
            'award'           => '🥇',
            'nav-compass'     => '🧭',
            'book-open'       => '📖',
        ];
        return $map[ $key ] ?? '🏅';
    }

    /* ─── Onglet 9 : Compétences (مهارات) ──────────────────────────── */

    private static function tab_skills( int $child_id, int $coach_id ): void {
        if ( ! class_exists( 'RK_MC_Skill_Service' ) ) {
            echo '<div class="rk-ch-notice rk-ch-notice--error">' . esc_html__( 'خدمة المهارات غير متاحة.', 'rk-coach-hub' ) . '</div>';
            return;
        }

        $skills  = RK_MC_Skill_Service::get_skills( $child_id );
        $action  = admin_url( 'admin-post.php' );
        $done    = isset( $_GET['skills_done'] );
        ?>
        <div style="display:grid;gap:20px;">

        <?php if ( $done ) : ?>
        <div class="rk-ch-notice rk-ch-notice--success">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg>
            <?php esc_html_e( 'تم حفظ المهارات بنجاح.', 'rk-coach-hub' ); ?>
        </div>
        <?php endif; ?>

        <div class="rk-ch-section">
            <div class="rk-ch-section-head">
                <h3 class="rk-ch-section-title"><?php esc_html_e( 'قوى الطالب (0–10)', 'rk-coach-hub' ); ?></h3>
            </div>
            <div class="rk-ch-section-body">
                <form method="post" action="<?php echo esc_url( $action ); ?>">
                    <?php wp_nonce_field( 'rk_coach_set_skills', '_nonce' ); ?>
                    <input type="hidden" name="action"   value="rk_coach_set_skills">
                    <input type="hidden" name="child_id" value="<?php echo $child_id; ?>">

                    <div style="display:grid;gap:16px;margin-bottom:20px;">
                        <?php foreach ( $skills as $sk ) :
                            $ico_map = [
                                'speech'     => '🎤',
                                'teamwork'   => '👥',
                                'creativity' => '🎨',
                                'courage'    => '🏆',
                                'leadership' => '🚀',
                                'focus'      => '🎯',
                            ];
                            $ico = $ico_map[ $sk['key'] ] ?? '⭐';
                        ?>
                        <div style="display:grid;grid-template-columns:auto 1fr auto;align-items:center;gap:12px;">
                            <span style="font-size:1.3rem;width:32px;text-align:center;"><?php echo esc_html( $ico ); ?></span>
                            <div>
                                <label for="skill_<?php echo esc_attr( $sk['key'] ); ?>"
                                       style="display:block;font-size:.85rem;font-weight:700;color:#374151;margin-bottom:4px;">
                                    <?php echo esc_html( $sk['name'] ); ?>
                                </label>
                                <input type="range"
                                       id="skill_<?php echo esc_attr( $sk['key'] ); ?>"
                                       name="skills[<?php echo esc_attr( $sk['key'] ); ?>]"
                                       min="0" max="10"
                                       value="<?php echo (int) $sk['level']; ?>"
                                       style="width:100%;accent-color:var(--e-global-color-primary,#E8500A);"
                                       oninput="document.getElementById('sv_<?php echo esc_attr( $sk['key'] ); ?>').textContent=this.value">
                            </div>
                            <span id="sv_<?php echo esc_attr( $sk['key'] ); ?>"
                                  style="font-size:.9rem;font-weight:800;color:var(--e-global-color-primary,#E8500A);min-width:24px;text-align:center;">
                                <?php echo (int) $sk['level']; ?>
                            </span>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <button type="submit" class="rk-ch-btn rk-ch-btn--primary">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/></svg>
                        <?php esc_html_e( 'حفظ المهارات', 'rk-coach-hub' ); ?>
                    </button>
                </form>
            </div>
        </div>

        </div>
        <?php
    }

    /* ─── Onglet 10 : Certificats (شهاداتي) ────────────────────────── */

    private static function tab_certs( int $child_id, int $coach_id ): void {
        $certs = RKP_CertificateRepository::find_for_child( $child_id );
        $action   = admin_url( 'admin-post.php' );
        $done     = isset( $_GET['cert_done'] );
        ?>
        <div style="display:grid;gap:20px;">

        <?php if ( $done ) : ?>
        <div class="rk-ch-notice rk-ch-notice--success">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg>
            <?php esc_html_e( 'تم إصدار الشهادة بنجاح.', 'rk-coach-hub' ); ?>
        </div>
        <?php endif; ?>

        <!-- Formulaire d'émission de certificat -->
        <details class="rk-inline-panel">
            <summary class="rk-ch-btn rk-ch-btn--primary rk-ch-btn--sm"
                     style="display:inline-flex;list-style:none;cursor:pointer;margin-bottom:0;">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                <?php esc_html_e( 'إصدار شهادة جديدة', 'rk-coach-hub' ); ?>
            </summary>
            <div class="rk-inline-panel__body" style="background:#f8fafc;border-radius:0 0 12px 12px;padding:20px;border:1px solid #e2e8f0;border-top:none;">
                <form method="post" action="<?php echo esc_url( $action ); ?>" enctype="multipart/form-data">
                    <?php wp_nonce_field( 'rk_coach_save_certificate', '_nonce' ); ?>
                    <input type="hidden" name="action"   value="rk_coach_save_certificate">
                    <input type="hidden" name="child_id" value="<?php echo $child_id; ?>">

                    <div style="margin-bottom:14px;">
                        <label style="font-size:.82rem;font-weight:700;color:#374151;display:block;margin-bottom:4px;">
                            <?php esc_html_e( 'عنوان الشهادة *', 'rk-coach-hub' ); ?>
                        </label>
                        <input type="text" name="title" required dir="rtl"
                               style="width:100%;padding:9px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:.88rem;"
                               placeholder="<?php esc_attr_e( 'مثال: شهادة إتمام برنامج القيادة', 'rk-coach-hub' ); ?>">
                    </div>

                    <div style="margin-bottom:14px;">
                        <label style="font-size:.82rem;font-weight:700;color:#374151;display:block;margin-bottom:4px;">
                            <?php esc_html_e( 'وصف الإنجاز', 'rk-coach-hub' ); ?>
                        </label>
                        <textarea name="description" rows="2" dir="rtl"
                                  style="width:100%;padding:9px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:.87rem;resize:vertical;"
                                  placeholder="<?php esc_attr_e( 'وصف قصير للإنجاز المحقق...', 'rk-coach-hub' ); ?>"></textarea>
                    </div>

                    <div style="margin-bottom:18px;">
                        <label style="font-size:.82rem;font-weight:700;color:#374151;display:block;margin-bottom:4px;">
                            <?php esc_html_e( 'ملف الشهادة (PDF أو صورة)', 'rk-coach-hub' ); ?>
                        </label>
                        <input type="file" name="certificate_file"
                               accept=".pdf,.png,.jpg,.jpeg"
                               style="width:100%;padding:6px 0;font-size:.85rem;">
                        <p style="font-size:.75rem;color:#94a3b8;margin:4px 0 0;">
                            <?php esc_html_e( 'يمكنك أيضاً رفع الملف عبر مكتبة الوسائط ولصق الرابط أدناه:', 'rk-coach-hub' ); ?>
                        </p>
                        <input type="url" name="file_url_manual" dir="ltr"
                               style="width:100%;padding:7px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:.82rem;margin-top:4px;"
                               placeholder="https://...">
                    </div>

                    <button type="submit" class="rk-ch-btn rk-ch-btn--primary">
                        <?php esc_html_e( 'إصدار الشهادة', 'rk-coach-hub' ); ?>
                    </button>
                </form>
            </div>
        </details>

        <!-- Liste des certificats émis -->
        <div class="rk-ch-section">
            <div class="rk-ch-section-head">
                <h3 class="rk-ch-section-title"><?php esc_html_e( 'الشهادات المصدرة', 'rk-coach-hub' ); ?></h3>
                <span class="rk-ch-pill rk-ch-pill--blue"><?php echo count( $certs ); ?></span>
            </div>
            <?php if ( empty( $certs ) ) : ?>
            <div class="rk-ch-section-body">
                <p style="color:#94a3b8;font-size:.85rem;"><?php esc_html_e( 'لم يتم إصدار أي شهادة بعد.', 'rk-coach-hub' ); ?></p>
            </div>
            <?php else : ?>
            <div style="display:grid;gap:10px;padding:20px;">
                <?php foreach ( $certs as $c ) : ?>
                <div style="display:flex;align-items:center;gap:12px;background:#f8fafc;border-radius:10px;padding:12px;">
                    <span style="font-size:1.5rem;">🏅</span>
                    <div style="flex:1;">
                        <p style="font-size:.88rem;font-weight:700;margin:0 0 2px;"><?php echo esc_html( $c->title ); ?></p>
                        <p style="font-size:.75rem;color:#64748b;margin:0;">
                            <?php echo esc_html( date_i18n( 'j F Y', strtotime( $c->issued_at ) ) ); ?>
                        </p>
                    </div>
                    <?php if ( ! empty( $c->file_url ) ) : ?>
                    <a href="<?php echo esc_url( $c->file_url ); ?>" target="_blank"
                       class="rk-ch-btn rk-ch-btn--ghost rk-ch-btn--sm">
                        <?php esc_html_e( 'عرض', 'rk-coach-hub' ); ?>
                    </a>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        </div>
        <?php
    }

}