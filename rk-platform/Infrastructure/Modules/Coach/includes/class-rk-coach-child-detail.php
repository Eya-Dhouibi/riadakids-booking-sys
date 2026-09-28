<?php
declare( strict_types=1 );
/**
 * RK_Coach_Child_Detail — Fiche complète de l'élève (5 onglets).
 * v3.0 — Profil · Séances · LMS · Évaluations · Notes privées.
 *
 * @package RK_Coach_Hub
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/* Traits — factorisation par fonctionnalité (logique inchangée) */
require_once __DIR__ . '/traits/trait-rk-coach-child-tabs-learning.php';
require_once __DIR__ . '/traits/trait-rk-coach-child-tabs-progress.php';
require_once __DIR__ . '/traits/trait-rk-coach-child-handlers.php';

class RK_Coach_Child_Detail {
    use RK_Coach_Child_Tabs_Learning;
    use RK_Coach_Child_Tabs_Progress;
    use RK_Coach_Child_Handlers;


    /* ─── Rendu principal ───────────────────────────────────────────── */

    public static function render(): void {
        $coach_id = get_current_user_id();
        $child_id = absint( $_GET['child_id'] ?? 0 );

        if ( ! $child_id ) {
            echo '<div class="rk-ch-notice rk-ch-notice--error">' . esc_html__( 'الطالب غير محدد.', 'rk-coach-hub' ) . '</div>';
            return;
        }

        // Vérifier que cet élève appartient à ce coach
        $students   = RK_Coach_Data::get_coach_students( $coach_id );
        $child_ids  = array_map( fn( $s ) => (int) $s->child_id, $students );
        if ( ! in_array( $child_id, $child_ids, true ) ) {
            echo '<div class="rk-ch-notice rk-ch-notice--error">' . esc_html__( 'غير مصرح.', 'rk-coach-hub' ) . '</div>';
            return;
        }

        $child      = get_userdata( $child_id );
        $child_name = $child ? $child->display_name : __( 'طالب', 'rk-coach-hub' );
        $avatar_url = get_avatar_url( $child_id, [ 'size' => 96 ] );
        $attn_rate  = RK_Coach_Data::get_child_attendance_rate( $child_id, $coach_id );
        $consec     = RK_Coach_Data::get_consecutive_absences( $child_id );
        $parent     = RK_Coach_Data::get_parent_info( $child_id );
        $sessions   = RK_Coach_Data::get_child_sessions_for_coach( $child_id, $coach_id, 50 );
        $quiz_res   = RK_Coach_Data::get_child_quiz_results( $child_id );
        $back_url   = tutor_utils()->tutor_dashboard_url( 'rk-eleves' );

        // Onglet actif (8 onglets Sprint 3)
        $valid_tabs = [ 'profile', 'sessions', 'courses', 'quiz', 'evals', 'missions', 'badges', 'skills', 'certs', 'notes' ];
        $active_tab = in_array( $_GET['tab'] ?? '', $valid_tabs, true )
                      ? sanitize_key( $_GET['tab'] )
                      : 'profile';
        $base_url   = add_query_arg( 'child_id', $child_id, tutor_utils()->tutor_dashboard_url( 'rk-fiche-eleve' ) );

        $tabs = [
            'profile'   => [ 'label' => __( 'الملف', 'rk-coach-hub' ),       'icon' => '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>' ],
            'sessions'  => [ 'label' => __( 'لقاءات', 'rk-coach-hub' ),     'icon' => '<rect x="3" y="4" width="18" height="18" rx="2"/><line x1="3" y1="10" x2="21" y2="10"/>' ],
            'courses'   => [ 'label' => __( 'الدورات', 'rk-coach-hub' ),     'icon' => '<polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>' ],
            'quiz'      => [ 'label' => __( 'الاختبارات', 'rk-coach-hub' ),  'icon' => '<circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/>' ],
            'evals'     => [ 'label' => __( 'التقييمات', 'rk-coach-hub' ),   'icon' => '<path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>' ],
            'missions'  => [ 'label' => __( 'المهام', 'rk-coach-hub' ),      'icon' => '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>' ],
            'badges'    => [ 'label' => __( 'الشارات', 'rk-coach-hub' ),     'icon' => '<circle cx="12" cy="8" r="7"/><polyline points="8.21 13.89 7 23 12 20 17 23 15.79 13.88"/>' ],
            'skills'    => [ 'label' => __( 'المهارات', 'rk-coach-hub' ),    'icon' => '<line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/>' ],
            'certs'     => [ 'label' => __( 'الشهادات', 'rk-coach-hub' ),    'icon' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="9" y1="15" x2="15" y2="15"/>' ],
            'notes'     => [ 'label' => __( 'ملاحظاتي', 'rk-coach-hub' ),   'icon' => '<path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/>' ],
        ];
        ?>
        <div class="rk-coach-page" dir="rtl">

            <!-- Bouton retour -->
            <div style="margin-bottom:16px;">
                <a href="<?php echo esc_url( $back_url ); ?>" class="rk-ch-btn rk-ch-btn--ghost rk-ch-btn--sm">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
                    <?php esc_html_e( 'العودة للطلاب', 'rk-coach-hub' ); ?>
                </a>
            </div>

            <!-- Hero enfant -->
            <div class="rk-ch-child-header">
                <div class="rk-ch-child-header__decor"></div>
                <div class="rk-ch-child-header__inner">
                    <img src="<?php echo esc_url( $avatar_url ); ?>"
                         alt="<?php echo esc_attr( $child_name ); ?>"
                         class="rk-ch-child-header__avatar"
                         width="72" height="72">
                    <div class="rk-ch-child-header__info">
                        <h2 class="rk-ch-child-header__name"><?php echo esc_html( $child_name ); ?></h2>
                        <?php if ( ! empty( $sessions[0]['program_name'] ) ) : ?>
                        <p class="rk-ch-child-header__program"><?php echo esc_html( $sessions[0]['program_name'] ); ?></p>
                        <?php endif; ?>
                    </div>
                    <div class="rk-ch-child-header__kpis">
                        <div class="rk-ch-child-header__kpi">
                            <span class="rk-ch-child-header__kpi-val"><?php echo $attn_rate; ?>%</span>
                            <span class="rk-ch-child-header__kpi-lbl"><?php esc_html_e( 'الحضور', 'rk-coach-hub' ); ?></span>
                        </div>
                        <div class="rk-ch-child-header__kpi">
                            <span class="rk-ch-child-header__kpi-val"><?php echo count( $sessions ); ?></span>
                            <span class="rk-ch-child-header__kpi-lbl"><?php esc_html_e( 'جلسة', 'rk-coach-hub' ); ?></span>
                        </div>
                        <div class="rk-ch-child-header__kpi">
                            <span class="rk-ch-child-header__kpi-val"><?php echo count( $quiz_res ); ?></span>
                            <span class="rk-ch-child-header__kpi-lbl"><?php esc_html_e( 'اختبار', 'rk-coach-hub' ); ?></span>
                        </div>
                        <?php if ( $consec >= 2 ) : ?>
                        <div class="rk-ch-child-header__kpi" style="color:#fca5a5;">
                            <span class="rk-ch-child-header__kpi-val"><?php echo $consec; ?></span>
                            <span class="rk-ch-child-header__kpi-lbl"><?php esc_html_e( 'غياب متتالي', 'rk-coach-hub' ); ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Navigation onglets -->
            <div class="rk-ch-tabs" role="tablist">
                <?php foreach ( $tabs as $key => $tab ) :
                    $tab_url = add_query_arg( 'tab', $key, $base_url );
                    $is_act  = $key === $active_tab;
                ?>
                <a href="<?php echo esc_url( $tab_url ); ?>"
                   class="rk-ch-tab<?php echo $is_act ? ' rk-ch-tab--active' : ''; ?>"
                   role="tab" aria-selected="<?php echo $is_act ? 'true' : 'false'; ?>">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><?php echo $tab['icon']; // phpcs:ignore ?></svg>
                    <?php echo esc_html( $tab['label'] ); ?>
                </a>
                <?php endforeach; ?>
            </div>

            <!-- Contenu onglets -->
            <div class="rk-ch-tab-panel" role="tabpanel">
                <?php
                match ( $active_tab ) {
                    'profile'  => self::tab_profile( $child_id, $child, $parent, $coach_id ),
                    'sessions' => self::tab_sessions( $sessions, $child_id, $coach_id ),
                    'courses'  => self::tab_courses( $child_id ),
                    'quiz'     => self::tab_quiz( $child_id, $quiz_res ),
                    'evals'    => self::tab_evals( $child_id, $coach_id ),
                    'missions' => self::tab_missions( $child_id, $coach_id ),
                    'badges'   => self::tab_badges( $child_id, $coach_id ),
                    'skills'   => self::tab_skills( $child_id, $coach_id ),
                    'certs'    => self::tab_certs( $child_id, $coach_id ),
                    'notes'    => self::tab_notes( $child_id, $coach_id ),
                    default    => self::tab_profile( $child_id, $child, $parent, $coach_id ),
                };
                ?>
            </div>

        </div>
        <?php
    }

    /* ─── Onglet 8 : Notes privées ──────────────────────────────────── */

    private static function tab_notes( int $child_id, int $coach_id ): void {
        $meta_key = 'rk_coach_notes_child_' . $child_id;
        $notes    = (string) ( get_user_meta( $coach_id, $meta_key, true ) ?? '' );
        $action   = admin_url( 'admin-post.php' );
        $saved    = isset( $_GET['notes_done'] );
        ?>
        <div class="rk-ch-section">
            <div class="rk-ch-section-head">
                <h3 class="rk-ch-section-title">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
                    <?php esc_html_e( 'ملاحظاتي الخاصة', 'rk-coach-hub' ); ?>
                </h3>
                <span style="font-size:.78rem;color:#94a3b8;"><?php esc_html_e( 'مرئية فقط لك', 'rk-coach-hub' ); ?></span>
            </div>

            <?php if ( $saved ) : ?>
            <div class="rk-ch-notice rk-ch-notice--success" style="margin:0 20px 16px;">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg>
                <?php esc_html_e( 'تم حفظ الملاحظات بنجاح.', 'rk-coach-hub' ); ?>
            </div>
            <?php endif; ?>

            <div class="rk-ch-private-notes-wrap">
                <form method="post" action="<?php echo esc_url( $action ); ?>">
                    <?php wp_nonce_field( 'rk_coach_save_notes', 'rk_notes_nonce' ); ?>
                    <input type="hidden" name="action"   value="rk_coach_save_notes">
                    <input type="hidden" name="child_id" value="<?php echo $child_id; ?>">
                    <textarea name="notes" rows="10" dir="rtl"
                              placeholder="<?php esc_attr_e( 'اكتب ملاحظاتك الخاصة عن هذا الطالب هنا... (مرئية فقط لك)', 'rk-coach-hub' ); ?>"
                              style="width:100%;border:none;background:transparent;resize:vertical;font-size:.9rem;line-height:1.7;font-family:inherit;outline:none;color:#1e293b;"><?php echo esc_textarea( $notes ); ?></textarea>
                    <div style="display:flex;justify-content:flex-start;margin-top:12px;padding-top:12px;border-top:1px dashed #fbbf24;">
                        <button type="submit" class="rk-ch-btn rk-ch-btn--orange">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                            <?php esc_html_e( 'حفظ الملاحظات', 'rk-coach-hub' ); ?>
                        </button>
                        <?php if ( $notes ) : ?>
                        <span style="margin-right:12px;font-size:.8rem;color:#94a3b8;align-self:center;">
                            <?php echo esc_html( mb_strlen( $notes ) ); ?> <?php esc_html_e( 'حرف', 'rk-coach-hub' ); ?>
                        </span>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
        <?php
    }
}
