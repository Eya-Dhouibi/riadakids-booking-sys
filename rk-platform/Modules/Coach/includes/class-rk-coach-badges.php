<?php
declare( strict_types=1 );
/**
 * RK_Coach_Badges — Gestion complète des شارات depuis le dashboard coach.
 * CRUD badges personnalisés + attribution + révocation.
 *
 * @package RK_Coach_Hub
 * @since   2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class RK_Coach_Badges {

    /* ── Helpers partagés ────────────────────────────────────────── */

    private static function page_url(): string {
        return function_exists( 'tutor_utils' )
            ? tutor_utils()->tutor_dashboard_url( 'rk-badges' )
            : home_url( '/espace-coach/' );
    }

    /**
     * v9.10 — Remplace l'ancienne emoji_map() (règle zéro-emoji, cohérence
     * avec le côté enfant). Mêmes CLÉS exactement conservées (validation
     * d'écriture inchangée, badges custom déjà créés restent valides) —
     * seul le mode d'affichage change : icônes SVG du registre partagé
     * rk_mc_svg() au lieu d'un caractère emoji.
     *
     * 'heart' et 'crown' n'ont pas d'icône dédiée dans le registre SVG
     * (déjà le cas avant cette révision — ces clés n'ont jamais eu de
     * représentation visuelle fiable) : conservées dans la liste valide
     * pour ne pas invalider un badge déjà créé avec cette clé, mais avec
     * un repli explicite vers 'star' au rendu (voir badge_icon_key()).
     */
    private static function icon_keys(): array {
        return [
            'star', 'graduation-cap', 'nav-target', 'users', 'fire',
            'diamond', 'lightning', 'moon', 'trophy', 'rocket',
            'skill-speech', 'skill-creativity', 'gift', 'award',
            'nav-compass', 'book-open', 'heart', 'crown',
        ];
    }

    /** Libellés arabes lisibles pour le sélecteur d'icône du formulaire (§ pas de nom de clé technique brut affiché au coach). */
    private static function icon_labels(): array {
        return [
            'star'             => __( 'نجمة', 'rk-coach-hub' ),
            'graduation-cap'   => __( 'تخرج', 'rk-coach-hub' ),
            'nav-target'       => __( 'هدف', 'rk-coach-hub' ),
            'users'            => __( 'فريق', 'rk-coach-hub' ),
            'fire'             => __( 'حماس', 'rk-coach-hub' ),
            'diamond'          => __( 'ماسة', 'rk-coach-hub' ),
            'lightning'        => __( 'سرعة', 'rk-coach-hub' ),
            'moon'             => __( 'مثابرة', 'rk-coach-hub' ),
            'trophy'           => __( 'كأس', 'rk-coach-hub' ),
            'rocket'           => __( 'انطلاقة', 'rk-coach-hub' ),
            'skill-speech'     => __( 'تواصل', 'rk-coach-hub' ),
            'skill-creativity' => __( 'إبداع', 'rk-coach-hub' ),
            'gift'             => __( 'هدية', 'rk-coach-hub' ),
            'award'            => __( 'وسام', 'rk-coach-hub' ),
            'nav-compass'      => __( 'استكشاف', 'rk-coach-hub' ),
            'book-open'        => __( 'تعلّم', 'rk-coach-hub' ),
            'heart'            => __( 'تميّز', 'rk-coach-hub' ),
            'crown'            => __( 'تفوّق', 'rk-coach-hub' ),
        ];
    }

    /** Résout une clé d'icône vers une clé réellement présente dans le registre SVG (repli 'star'). */
    private static function badge_icon_key( string $key ): string {
        return in_array( $key, [ 'heart', 'crown' ], true ) ? 'star' : $key;
    }

    private static function valid_cats(): array {
        return [
            'skill'   => __( 'مهارات', 'rk-coach-hub' ),
            'special' => __( 'خاصة', 'rk-coach-hub' ),
            'mastery' => __( 'إتقان', 'rk-coach-hub' ),
            'streak'  => __( 'مداومة', 'rk-coach-hub' ),
            'start'   => __( 'بداية', 'rk-coach-hub' ),
            'level'   => __( 'مستويات', 'rk-coach-hub' ),
        ];
    }

    private static function get_coach_custom_badges( int $coach_id ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'rk_custom_badges';
        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) !== $table ) return [];
        return (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM `{$table}` WHERE coach_id = %d ORDER BY id ASC",
            $coach_id
        ) );
    }

    /* ── Render principal ────────────────────────────────────────── */

    public static function render(): void {
        $coach_id     = get_current_user_id();
        $child_id     = absint( $_GET['child_id'] ?? 0 );
        $badge_action = sanitize_key( $_GET['rk_badge_action'] ?? '' ); // 'new' | 'edit'
        $edit_key     = sanitize_key( $_GET['rk_badge_key'] ?? '' );
        $students     = RK_Coach_Data::get_coach_students( $coach_id );
        $base_url     = self::page_url();

        if ( ! class_exists( 'RK_MC_Badge_Service' ) ) {
            echo '<div class="rk-ch-notice rk-ch-notice--error">'
               . esc_html__( 'خدمة الشارات غير متاحة.', 'rk-coach-hub' )
               . '</div>';
            return;
        }

        $catalogue = RK_MC_Badge_Service::catalogue();
        ?>
        <div class="rk-coach-page" dir="rtl">

            <!-- En-tête -->
            <div class="rk-ch-topbar">
                <h2 class="rk-ch-topbar__title">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="8" r="6"/><path d="M15.477 12.89L17 22l-5-3-5 3 1.523-9.11"/></svg>
                    <?php esc_html_e( 'إدارة الشارات', 'rk-coach-hub' ); ?>
                </h2>
                <?php if ( $child_id ) : ?>
                <a href="<?php echo esc_url( $base_url ); ?>" class="rk-ch-btn rk-ch-btn--ghost rk-ch-btn--sm">
                    <?php esc_html_e( '← جميع الطلاب', 'rk-coach-hub' ); ?>
                </a>
                <?php endif; ?>
            </div>

            <?php self::render_notices(); ?>

            <!-- Panel: badges personnalisés du coach (toujours visible) -->
            <?php self::render_custom_panel( $coach_id, $badge_action, $edit_key ); ?>

            <?php if ( empty( $students ) ) : ?>
            <div class="rk-ch-empty" style="padding:40px 0;">
                <div class="rk-ch-empty__icon">
                    <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
                </div>
                <p class="rk-ch-empty__title"><?php esc_html_e( 'لا يوجد طلاب بعد', 'rk-coach-hub' ); ?></p>
            </div>

            <?php elseif ( ! $child_id ) : ?>
            <!-- ── GRILLE DES ÉLÈVES ──────────────────────────────────── -->
            <div class="rk-ch-section">
                <div class="rk-ch-section-head">
                    <div class="rk-ch-section-head__left">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--e-global-color-secondary,#1B4F8C)" stroke-width="2.5" aria-hidden="true"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
                        <h3 class="rk-ch-section-title"><?php esc_html_e( 'منح أو سحب شارة', 'rk-coach-hub' ); ?></h3>
                    </div>
                </div>
                <div class="rk-ch-section-body">
                    <div class="rk-ch-badge-students-grid">
                        <?php foreach ( $students as $s ) :
                            $cid        = (int) $s->child_id;
                            $name       = trim( (string) $s->child_name . ' ' . (string) ( $s->child_family_name ?? '' ) );
                            $earned     = count( RK_MC_Badge_Service::get_badges( $cid ) );
                            $total      = count( $catalogue );
                            $avatar_url = ! empty( $s->avatar_url ) ? $s->avatar_url
                                          : get_avatar_url( 0, [ 'size' => 56, 'default' => 'mystery' ] );
                            $link       = add_query_arg( 'child_id', $cid, $base_url );
                        ?>
                        <a href="<?php echo esc_url( $link ); ?>" class="rk-ch-badge-student-card">
                            <img src="<?php echo esc_url( $avatar_url ); ?>"
                                 alt="<?php echo esc_attr( $name ); ?>"
                                 class="rk-ch-badge-student-card__avatar"
                                 width="56" height="56" loading="lazy">
                            <p class="rk-ch-badge-student-card__name"><?php echo esc_html( $name ); ?></p>
                            <p class="rk-ch-badge-student-card__count">
                                <?php echo $earned; ?> / <?php echo $total; ?>
                            </p>
                        </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <?php else :
            // ── ATTRIBUTION / RÉVOCATION POUR UN ÉLÈVE ──────────────────

            $child_ids = array_map( fn( $s ) => (int) $s->child_id, $students );
            if ( ! in_array( $child_id, $child_ids, true ) ) {
                echo '<div class="rk-ch-notice rk-ch-notice--error">' . esc_html__( 'غير مصرح.', 'rk-coach-hub' ) . '</div>';
                echo '</div>';
                return;
            }

            $earned_badges = RK_MC_Badge_Service::get_badges( $child_id );
            $earned_keys   = array_column( $earned_badges, 'key' );

            $sel_student = null;
            foreach ( $students as $s ) {
                if ( (int) $s->child_id === $child_id ) { $sel_student = $s; break; }
            }
            $sel_name   = $sel_student ? trim( (string) $sel_student->child_name . ' ' . (string) ( $sel_student->child_family_name ?? '' ) ) : '#' . $child_id;
            $sel_avatar = ( $sel_student && ! empty( $sel_student->avatar_url ) )
                          ? $sel_student->avatar_url
                          : get_avatar_url( 0, [ 'size' => 48, 'default' => 'mystery' ] );

            $categories = [
                'skill'   => [ 'label' => __( 'مهارات (يمنحها المدرب)', 'rk-coach-hub' ), 'color' => 'var(--e-global-color-secondary,#1B4F8C)' ],
                'special' => [ 'label' => __( 'خاصة',                   'rk-coach-hub' ), 'color' => 'var(--e-global-color-primary,#E8500A)' ],
                'mastery' => [ 'label' => __( 'إتقان',                  'rk-coach-hub' ), 'color' => '#7c3aed' ],
                'streak'  => [ 'label' => __( 'مداومة',                 'rk-coach-hub' ), 'color' => '#d97706' ],
                'start'   => [ 'label' => __( 'بداية',                  'rk-coach-hub' ), 'color' => '#059669' ],
                'level'   => [ 'label' => __( 'مستويات',                'rk-coach-hub' ), 'color' => '#0891b2' ],
            ];
            $grouped = [];
            foreach ( $catalogue as $key => $def ) {
                $grouped[ $def['cat'] ][ $key ] = $def;
            }
            ?>
            <div class="rk-ch-section">
                <div class="rk-ch-section-body">

                    <!-- Entête élève sélectionné -->
                    <div class="rk-ch-badge-child-header">
                        <img src="<?php echo esc_url( $sel_avatar ); ?>"
                             class="rk-ch-badge-child-avatar" width="48" height="48"
                             alt="<?php echo esc_attr( $sel_name ); ?>">
                        <div>
                            <p class="rk-ch-badge-child-name"><?php echo esc_html( $sel_name ); ?></p>
                            <p class="rk-ch-badge-child-count">
                                <?php echo count( $earned_keys ); ?> / <?php echo count( $catalogue ); ?>
                                <?php esc_html_e( 'شارة مكتسبة', 'rk-coach-hub' ); ?>
                            </p>
                        </div>
                    </div>

                    <!-- Catalogue par catégorie -->
                    <?php foreach ( $categories as $cat_key => $cat_def ) :
                        if ( empty( $grouped[ $cat_key ] ) ) continue; ?>
                    <div class="rk-ch-badge-cat-section">
                        <p class="rk-ch-badge-cat-title" style="--cat-color:<?php echo esc_attr( $cat_def['color'] ); ?>">
                            <?php echo esc_html( $cat_def['label'] ); ?>
                        </p>
                        <div class="rk-ch-badge-grid">
                            <?php foreach ( $grouped[ $cat_key ] as $badge_key => $badge_def ) :
                                $has       = in_array( $badge_key, $earned_keys, true );
                                $is_custom = ! empty( $badge_def['custom'] );
                            ?>
                            <div class="rk-ch-badge-card<?php echo $has ? ' rk-ch-badge-card--earned' : ' rk-ch-badge-card--locked'; ?>">
                                <div class="rk-ch-badge-card__icon-wrap">
                                    <?php if ( defined( 'RK_MC_URL' ) ) : ?>
                                    <img src="<?php echo esc_url( RK_MC_URL . 'assets/img/badges/rank-badge.png' ); ?>"
                                         alt="" class="rk-ch-badge-card__icon" loading="lazy" width="56" height="59">
                                    <?php endif; ?>
                                </div>
                                <p class="rk-ch-badge-card__name">
                                    <?php echo esc_html( $badge_def['name'] ); ?>
                                    <?php if ( $is_custom ) : ?>
                                    <span class="rk-ch-badge-card__custom-tag"><?php esc_html_e( 'مخصصة', 'rk-coach-hub' ); ?></span>
                                    <?php endif; ?>
                                </p>
                                <p class="rk-ch-badge-card__desc"><?php echo esc_html( $badge_def['desc'] ?? '' ); ?></p>

                                <?php if ( $has ) : ?>
                                <span class="rk-ch-badge-card__earned">
                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg>
                                    <?php esc_html_e( 'تم المنح', 'rk-coach-hub' ); ?>
                                </span>
                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
                                      style="margin-top:6px"
                                      onsubmit="return confirm('<?php echo esc_js( sprintf( __( 'سحب شارة "%s" من %s؟', 'rk-coach-hub' ), $badge_def['name'], $sel_name ) ); ?>')">
                                    <?php wp_nonce_field( 'rk_revoke_' . $child_id . '_' . $badge_key, 'rk_revoke_nonce' ); ?>
                                    <input type="hidden" name="action"    value="rk_coach_revoke_badge">
                                    <input type="hidden" name="child_id"  value="<?php echo $child_id; ?>">
                                    <input type="hidden" name="badge_key" value="<?php echo esc_attr( $badge_key ); ?>">
                                    <button type="submit"
                                            style="width:100%;padding:4px 8px;border:1px solid #fca5a5;background:#fff5f5;color:#dc2626;border-radius:6px;cursor:pointer;font-size:.72rem;font-family:inherit;margin-top:2px">
                                        <?php esc_html_e( 'سحب الشارة', 'rk-coach-hub' ); ?>
                                    </button>
                                </form>

                                <?php else : ?>
                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                                    <?php wp_nonce_field( 'rk_coach_award_badge', '_nonce' ); ?>
                                    <input type="hidden" name="action"      value="rk_coach_award_badge">
                                    <input type="hidden" name="child_id"    value="<?php echo $child_id; ?>">
                                    <input type="hidden" name="badge_key"   value="<?php echo esc_attr( $badge_key ); ?>">
                                    <input type="hidden" name="return_page" value="rk-badges">
                                    <button type="submit" class="rk-ch-badge-card__btn">
                                        <?php esc_html_e( 'منح', 'rk-coach-hub' ); ?>
                                    </button>
                                </form>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>

                </div>
            </div>
            <?php endif; ?>

        </div>
        <?php
    }

    /* ── Notices ─────────────────────────────────────────────────── */

    private static function render_notices(): void {
        $success_map = [
            'badge_created' => 'تم إنشاء الشارة بنجاح!',
            'badge_updated' => 'تم تحديث الشارة.',
            'badge_deleted' => 'تم حذف الشارة وسحبها من جميع الطلاب.',
            'badge_done'    => 'تم منح الشارة بنجاح!',
            'badge_revoked' => 'تم سحب الشارة من الطالب.',
        ];
        foreach ( $success_map as $param => $msg ) {
            if ( ! empty( $_GET[ $param ] ) ) {
                printf(
                    '<div class="rk-ch-notice rk-ch-notice--success"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg> %s</div>',
                    esc_html__( $msg, 'rk-coach-hub' )
                );
            }
        }
        $error_map = [
            'empty_name'   => 'اسم الشارة مطلوب.',
            'not_found'    => 'الشارة غير موجودة أو لا تملك صلاحية تعديلها.',
            'invalid_key'  => 'مفتاح الشارة غير صالح.',
            'already'      => 'الطالب يملك هذه الشارة بالفعل.',
            'missing_type' => 'يرجى اختيار نوع الشارة (إنجاز أو مغامرة).',
            'award_failed' => 'تعذّر منح الشارة. قد تكون الشارة غير صالحة أو ممنوحة مسبقاً.',
            'revoke_failed' => 'تعذّر سحب الشارة. قد تكون غير موجودة أصلاً.',
        ];
        $err = sanitize_key( $_GET['badge_error'] ?? '' );
        if ( $err && isset( $error_map[ $err ] ) ) {
            printf(
                '<div class="rk-ch-notice rk-ch-notice--error">%s</div>',
                esc_html__( $error_map[ $err ], 'rk-coach-hub' )
            );
        }
    }

    /* ── Panel de gestion des badges personnalisés ───────────────── */

    private static function render_custom_panel( int $coach_id, string $action, string $edit_key ): void {
        $custom_badges = self::get_coach_custom_badges( $coach_id );
        $base_url      = self::page_url();
        $create_url    = add_query_arg( 'rk_badge_action', 'new', $base_url );
        $cat_labels    = self::valid_cats();
        ?>
        <div class="rk-ch-section" style="margin-bottom:20px">
            <div class="rk-ch-section-head">
                <div class="rk-ch-section-head__left">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="8" r="6"/><path d="M15.477 12.89L17 22l-5-3-5 3 1.523-9.11"/></svg>
                    <h3 class="rk-ch-section-title"><?php esc_html_e( 'شاراتي المخصصة', 'rk-coach-hub' ); ?></h3>
                    <span style="background:#e0f2fe;color:#0369a1;border-radius:20px;padding:2px 10px;font-size:.72rem;font-weight:600">
                        <?php echo count( $custom_badges ); ?>
                    </span>
                </div>
                <?php if ( $action !== 'new' && $action !== 'edit' ) : ?>
                <a href="<?php echo esc_url( $create_url ); ?>" class="rk-ch-btn rk-ch-btn--primary rk-ch-btn--sm">
                    + <?php esc_html_e( 'شارة جديدة', 'rk-coach-hub' ); ?>
                </a>
                <?php endif; ?>
            </div>
            <div class="rk-ch-section-body">

                <?php if ( $action === 'new' ) : ?>
                <?php self::render_badge_form( null, $base_url ); ?>

                <?php elseif ( $action === 'edit' && $edit_key ) : ?>
                <?php
                global $wpdb;
                $table = $wpdb->prefix . 'rk_custom_badges';
                $row   = $wpdb->get_row( $wpdb->prepare(
                    "SELECT * FROM `{$table}` WHERE badge_key = %s AND coach_id = %d",
                    $edit_key, $coach_id
                ) );
                if ( $row ) {
                    self::render_badge_form( $row, $base_url );
                } else {
                    echo '<p style="color:#94a3b8;font-size:.85rem">' . esc_html__( 'الشارة غير موجودة.', 'rk-coach-hub' ) . '</p>';
                }
                ?>

                <?php elseif ( empty( $custom_badges ) ) : ?>
                <p style="color:#94a3b8;font-size:.85rem;padding:8px 0;line-height:1.6">
                    <?php esc_html_e( 'لم تنشئ أي شارة مخصصة بعد. أنشئ شاراتك الخاصة لتمنحها لطلابك!', 'rk-coach-hub' ); ?>
                </p>

                <?php else : ?>
                <div style="display:flex;flex-direction:column;gap:8px">
                    <?php foreach ( $custom_badges as $cb ) :
                        $edit_url  = add_query_arg( [ 'rk_badge_action' => 'edit', 'rk_badge_key' => $cb->badge_key ], $base_url );
                        $cat_label = $cat_labels[ $cb->cat ] ?? $cb->cat;
                    ?>
                    <div style="display:flex;align-items:center;gap:12px;padding:10px 14px;background:#f8fafc;border-radius:10px;border:1px solid #e2e8f0">
                        <span style="width:22px;height:22px;flex-shrink:0;color:var(--e-global-color-secondary,#1B4F8C)" aria-hidden="true">
                            <?php echo function_exists( 'rk_mc_svg' ) ? wp_kses_post( rk_mc_svg( self::badge_icon_key( $cb->icon_key ), [] ) ) : ''; ?>
                        </span>
                        <div style="flex:1;min-width:0">
                            <p style="font-weight:700;font-size:.9rem;margin:0 0 2px;color:#1e293b"><?php echo esc_html( $cb->name ); ?></p>
                            <p style="font-size:.72rem;color:#64748b;margin:0">
                                <?php echo esc_html( $cat_label ); ?>
                                <?php if ( $cb->desc ) : ?>
                                · <?php echo esc_html( mb_strimwidth( $cb->desc, 0, 55, '…' ) ); ?>
                                <?php endif; ?>
                            </p>
                        </div>
                        <div style="display:flex;gap:6px;flex-shrink:0">
                            <a href="<?php echo esc_url( $edit_url ); ?>"
                               class="rk-ch-btn rk-ch-btn--ghost rk-ch-btn--sm"
                               title="<?php esc_attr_e( 'تعديل', 'rk-coach-hub' ); ?>">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5z"/></svg>
                            </a>
                            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
                                  onsubmit="return confirm('<?php echo esc_js( sprintf( __( 'حذف شارة "%s" نهائياً وسحبها من جميع الطلاب؟', 'rk-coach-hub' ), $cb->name ) ); ?>')">
                                <?php wp_nonce_field( 'rk_delete_badge_' . $cb->badge_key, 'rk_badge_nonce' ); ?>
                                <input type="hidden" name="action"    value="rk_coach_delete_badge">
                                <input type="hidden" name="badge_key" value="<?php echo esc_attr( $cb->badge_key ); ?>">
                                <button type="submit"
                                        class="rk-ch-btn rk-ch-btn--ghost rk-ch-btn--sm"
                                        style="color:#dc2626;border-color:#fca5a5"
                                        title="<?php esc_attr_e( 'حذف', 'rk-coach-hub' ); ?>">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                                </button>
                            </form>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

            </div>
        </div>
        <?php
    }

    /* ── Formulaire création / édition ───────────────────────────── */

    private static function render_badge_form( ?object $row, string $cancel_url ): void {
        $is_edit = ( $row !== null );
        $action  = $is_edit ? 'rk_coach_update_badge' : 'rk_coach_create_badge';
        $nonce   = $is_edit ? 'rk_update_badge_' . $row->badge_key : 'rk_create_badge';
        $cats    = self::valid_cats();
        $icons   = self::icon_keys();
        ?>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
              style="background:#f1f5f9;border-radius:12px;padding:20px;display:flex;flex-direction:column;gap:14px">
            <?php wp_nonce_field( $nonce, 'rk_badge_nonce' ); ?>
            <input type="hidden" name="action" value="<?php echo esc_attr( $action ); ?>">
            <?php if ( $is_edit ) : ?>
            <input type="hidden" name="badge_key" value="<?php echo esc_attr( $row->badge_key ); ?>">
            <?php endif; ?>

            <h4 style="margin:0;font-size:.95rem;font-weight:700;color:#0f172a">
                <?php echo $is_edit
                    ? esc_html__( 'تعديل الشارة', 'rk-coach-hub' )
                    : esc_html__( 'إنشاء شارة جديدة', 'rk-coach-hub' ); ?>
            </h4>

            <div>
                <label style="display:block;font-size:.8rem;font-weight:600;color:#475569;margin-bottom:4px">
                    <?php esc_html_e( 'اسم الشارة *', 'rk-coach-hub' ); ?>
                </label>
                <input type="text" name="badge_name" required
                       value="<?php echo esc_attr( $row->name ?? '' ); ?>"
                       placeholder="<?php esc_attr_e( 'مثال: المبدع الصغير', 'rk-coach-hub' ); ?>"
                       style="width:100%;padding:9px 12px;border:1px solid #cbd5e1;border-radius:8px;font-size:.875rem;font-family:inherit;box-sizing:border-box;background:#fff">
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                <div>
                    <label style="display:block;font-size:.8rem;font-weight:600;color:#475569;margin-bottom:4px">
                        <?php esc_html_e( 'الرمز', 'rk-coach-hub' ); ?>
                    </label>
                    <select name="icon_key"
                            style="width:100%;padding:9px 12px;border:1px solid #cbd5e1;border-radius:8px;font-size:.875rem;font-family:inherit;background:#fff">
                        <?php foreach ( $icons as $key ) :
                            $label = self::icon_labels()[ $key ] ?? $key;
                        ?>
                        <option value="<?php echo esc_attr( $key ); ?>"
                                <?php selected( $row->icon_key ?? 'star', $key ); ?>>
                            <?php echo esc_html( $label ); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label style="display:block;font-size:.8rem;font-weight:600;color:#475569;margin-bottom:4px">
                        <?php esc_html_e( 'الفئة', 'rk-coach-hub' ); ?>
                    </label>
                    <select name="badge_cat"
                            style="width:100%;padding:9px 12px;border:1px solid #cbd5e1;border-radius:8px;font-size:.875rem;font-family:inherit;background:#fff">
                        <?php foreach ( $cats as $key => $label ) : ?>
                        <option value="<?php echo esc_attr( $key ); ?>"
                                <?php selected( $row->cat ?? 'skill', $key ); ?>>
                            <?php echo esc_html( $label ); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div>
                <label style="display:block;font-size:.8rem;font-weight:600;color:#475569;margin-bottom:4px">
                    <?php esc_html_e( 'الوصف', 'rk-coach-hub' ); ?>
                </label>
                <textarea name="badge_desc" rows="2"
                          placeholder="<?php esc_attr_e( 'وصف قصير يظهر للطالب عند حصوله على الشارة', 'rk-coach-hub' ); ?>"
                          style="width:100%;padding:9px 12px;border:1px solid #cbd5e1;border-radius:8px;font-size:.875rem;font-family:inherit;resize:vertical;box-sizing:border-box;background:#fff"><?php echo esc_textarea( $row->desc ?? '' ); ?></textarea>
            </div>

            <?php
            // v9.12 — Champ badge_type OBLIGATOIRE (décision validée) :
            // jamais déduit automatiquement, toujours choisi explicitement
            // par le coach. Aucune option pré-sélectionnée par défaut à
            // la création.
            $current_type = $row->badge_type ?? '';
            ?>
            <div>
                <label style="display:block;font-size:.8rem;font-weight:600;color:#475569;margin-bottom:6px">
                    <?php esc_html_e( 'نوع الشارة *', 'rk-coach-hub' ); ?>
                </label>
                <div style="display:flex;gap:16px">
                    <label style="display:flex;align-items:center;gap:6px;font-size:.85rem;color:#334155;cursor:pointer">
                        <input type="radio" name="badge_type" value="achievement" required
                               <?php checked( $current_type, 'achievement' ); ?>>
                        <?php esc_html_e( 'شارات الإنجاز', 'rk-coach-hub' ); ?>
                    </label>
                    <label style="display:flex;align-items:center;gap:6px;font-size:.85rem;color:#334155;cursor:pointer">
                        <input type="radio" name="badge_type" value="adventure" required
                               <?php checked( $current_type, 'adventure' ); ?>>
                        <?php esc_html_e( 'شارات المغامرات', 'rk-coach-hub' ); ?>
                    </label>
                </div>
                <?php if ( $is_edit && '' === $current_type ) : ?>
                <p style="margin:6px 0 0;font-size:.75rem;color:#b45309">
                    <?php esc_html_e( 'هذه شارة قديمة لم يُحدَّد نوعها بعد — يرجى اختيار النوع المناسب.', 'rk-coach-hub' ); ?>
                </p>
                <?php endif; ?>
            </div>

            <div style="display:flex;gap:10px;justify-content:flex-end">
                <a href="<?php echo esc_url( $cancel_url ); ?>" class="rk-ch-btn rk-ch-btn--ghost rk-ch-btn--sm">
                    <?php esc_html_e( 'إلغاء', 'rk-coach-hub' ); ?>
                </a>
                <button type="submit" class="rk-ch-btn rk-ch-btn--primary rk-ch-btn--sm">
                    <?php echo $is_edit
                        ? esc_html__( 'حفظ التعديلات', 'rk-coach-hub' )
                        : esc_html__( 'إنشاء الشارة', 'rk-coach-hub' ); ?>
                </button>
            </div>
        </form>
        <?php
    }

    /* ── Handler: créer une شارة personnalisée ───────────────────── */

    public static function handle_create_badge(): void {
        if ( ! check_admin_referer( 'rk_create_badge', 'rk_badge_nonce' ) ) wp_die();
        if ( ! current_user_can( 'tutor_instructor' ) ) wp_die( 'غير مصرح', 403 );

        $coach_id   = get_current_user_id();
        $name       = sanitize_text_field( wp_unslash( $_POST['badge_name'] ?? '' ) );
        $icon_key   = sanitize_key( $_POST['icon_key'] ?? 'star' );
        $cat        = sanitize_key( $_POST['badge_cat'] ?? 'skill' );
        $desc       = sanitize_textarea_field( wp_unslash( $_POST['badge_desc'] ?? '' ) );
        $badge_type = sanitize_key( $_POST['badge_type'] ?? '' );

        if ( empty( $name ) ) {
            wp_safe_redirect( add_query_arg( [ 'rk_badge_action' => 'new', 'badge_error' => 'empty_name' ], self::page_url() ) );
            exit;
        }

        // v9.12 — badge_type OBLIGATOIRE (décision validée) : aucune
        // création possible sans choix explicite achievement/adventure,
        // aucun défaut silencieux.
        if ( ! in_array( $badge_type, [ 'achievement', 'adventure' ], true ) ) {
            wp_safe_redirect( add_query_arg( [ 'rk_badge_action' => 'new', 'badge_error' => 'missing_type' ], self::page_url() ) );
            exit;
        }

        $allowed_cats = array_keys( self::valid_cats() );
        $cat          = in_array( $cat, $allowed_cats, true ) ? $cat : 'skill';
        $allowed_keys = self::icon_keys();
        $icon_key     = in_array( $icon_key, $allowed_keys, true ) ? $icon_key : 'star';

        global $wpdb;
        $badge_key = 'custom_' . substr( md5( uniqid( (string) $coach_id . $name, true ) ), 0, 10 );
        $wpdb->insert(
            $wpdb->prefix . 'rk_custom_badges',
            [
                'badge_key'  => $badge_key,
                'name'       => $name,
                'icon_key'   => $icon_key,
                'cat'        => $cat,
                'badge_type' => $badge_type,
                'desc'       => $desc,
                'coach_id'   => $coach_id,
                'created_at' => current_time( 'mysql' ),
            ],
            [ '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s' ]
        );

        wp_safe_redirect( add_query_arg( 'badge_created', '1', self::page_url() ) );
        exit;
    }

    /* ── Handler: modifier une شارة personnalisée ────────────────── */

    public static function handle_update_badge(): void {
        $badge_key = sanitize_key( $_POST['badge_key'] ?? '' );
        if ( ! $badge_key ) wp_die();
        if ( ! check_admin_referer( 'rk_update_badge_' . $badge_key, 'rk_badge_nonce' ) ) wp_die();
        if ( ! current_user_can( 'tutor_instructor' ) ) wp_die( 'غير مصرح', 403 );

        $coach_id   = get_current_user_id();
        $name       = sanitize_text_field( wp_unslash( $_POST['badge_name'] ?? '' ) );
        $icon_key   = sanitize_key( $_POST['icon_key'] ?? 'star' );
        $cat        = sanitize_key( $_POST['badge_cat'] ?? 'skill' );
        $desc       = sanitize_textarea_field( wp_unslash( $_POST['badge_desc'] ?? '' ) );
        $badge_type = sanitize_key( $_POST['badge_type'] ?? '' );

        if ( empty( $name ) ) {
            wp_safe_redirect( add_query_arg( [ 'rk_badge_action' => 'edit', 'rk_badge_key' => $badge_key, 'badge_error' => 'empty_name' ], self::page_url() ) );
            exit;
        }

        // v9.12 — même règle qu'à la création : badge_type OBLIGATOIRE.
        // C'est aussi l'occasion naturelle de classifier un ancien badge
        // custom (badge_type NULL) — le coach ne peut plus sauvegarder
        // l'édition sans faire ce choix.
        if ( ! in_array( $badge_type, [ 'achievement', 'adventure' ], true ) ) {
            wp_safe_redirect( add_query_arg( [ 'rk_badge_action' => 'edit', 'rk_badge_key' => $badge_key, 'badge_error' => 'missing_type' ], self::page_url() ) );
            exit;
        }

        $allowed_cats = array_keys( self::valid_cats() );
        $cat          = in_array( $cat, $allowed_cats, true ) ? $cat : 'skill';
        $allowed_keys = self::icon_keys();
        $icon_key     = in_array( $icon_key, $allowed_keys, true ) ? $icon_key : 'star';

        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'rk_custom_badges',
            [ 'name' => $name, 'icon_key' => $icon_key, 'cat' => $cat, 'badge_type' => $badge_type, 'desc' => $desc ],
            [ 'badge_key' => $badge_key, 'coach_id' => $coach_id ],
            [ '%s', '%s', '%s', '%s', '%s' ],
            [ '%s', '%d' ]
        );

        wp_safe_redirect( add_query_arg( 'badge_updated', '1', self::page_url() ) );
        exit;
    }

    /* ── Handler: supprimer une شارة personnalisée ───────────────── */

    public static function handle_delete_badge(): void {
        $badge_key = sanitize_key( $_POST['badge_key'] ?? '' );
        if ( ! $badge_key ) wp_die();
        if ( ! check_admin_referer( 'rk_delete_badge_' . $badge_key, 'rk_badge_nonce' ) ) wp_die();
        if ( ! current_user_can( 'tutor_instructor' ) ) wp_die( 'غير مصرح', 403 );

        $coach_id = get_current_user_id();
        global $wpdb;

        $exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM `{$wpdb->prefix}rk_custom_badges` WHERE badge_key = %s AND coach_id = %d",
            $badge_key, $coach_id
        ) );
        if ( ! $exists ) {
            wp_safe_redirect( add_query_arg( 'badge_error', 'not_found', self::page_url() ) );
            exit;
        }

        $wpdb->delete( $wpdb->prefix . 'rk_custom_badges', [ 'badge_key' => $badge_key, 'coach_id' => $coach_id ], [ '%s', '%d' ] );
        // Cleanup: retire ce badge de tous les enfants qui l'avaient
        $wpdb->delete( $wpdb->prefix . 'rk_child_badges', [ 'badge_key' => $badge_key ], [ '%s' ] );

        wp_safe_redirect( add_query_arg( 'badge_deleted', '1', self::page_url() ) );
        exit;
    }

    /* ── Handler: révoquer une شارة d'un élève ──────────────────── */

    public static function handle_revoke_badge(): void {
        $child_id  = absint( $_POST['child_id'] ?? 0 );
        $badge_key = sanitize_key( $_POST['badge_key'] ?? '' );
        if ( ! $child_id || ! $badge_key ) wp_die();
        if ( ! check_admin_referer( 'rk_revoke_' . $child_id . '_' . $badge_key, 'rk_revoke_nonce' ) ) wp_die();
        if ( ! current_user_can( 'tutor_instructor' ) ) wp_die( 'غير مصرح', 403 );

        $coach_id  = get_current_user_id();
        $students  = RK_Coach_Data::get_coach_students( $coach_id );
        $child_ids = array_map( fn( $s ) => (int) $s->child_id, $students );
        if ( ! in_array( $child_id, $child_ids, true ) ) {
            wp_die( 'غير مصرح', 403 );
        }

        // v9.14 — Même correctif que handle_award_badge : on vérifie le
        // retour de revoke() au lieu de l'ignorer. Si le badge n'existait
        // déjà plus (ou table absente), on l'indique au coach plutôt que
        // d'afficher "تم سحب الشارة" alors qu'aucune ligne n'a été supprimée.
        $revoked = class_exists( 'RKP_BadgeCommandService' )
            && RKP_BadgeCommandService::revoke( $child_id, $badge_key );

        $redirect_args = $revoked
            ? [ 'child_id' => $child_id, 'badge_revoked' => '1' ]
            : [ 'child_id' => $child_id, 'badge_error' => 'revoke_failed' ];
        wp_safe_redirect( add_query_arg( $redirect_args, self::page_url() ) );
        exit;
    }
}