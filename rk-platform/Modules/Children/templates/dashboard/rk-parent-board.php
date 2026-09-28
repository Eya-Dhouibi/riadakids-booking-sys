<?php
declare( strict_types=1 );
/**
 * RiadaKids — Tutor Dashboard: لوحة ولي الأمر (Sprint 6)
 *
 * Blocks:
 *   1. Hero conditionnel (enfant sélectionné)
 *   2. Sélecteur enfant (si plusieurs enfants)
 *   3. 3 KPIs : Crédits · Prochaine séance · Niveau
 *   4. Carte dernier feedback (résumé + note)
 *   5. Barre progression cours actuel
 *   6. Badges récents (3 derniers)
 *   7. Actions rapides : Réserver · Contacter coach · Télécharger rapport
 */
if ( ! defined( 'ABSPATH' ) ) exit;

// Defense-in-depth: rk_child must never access parent board (shows credits, billing data)
if ( class_exists( 'RK_MC_Child_Restrictions' ) && RK_MC_Child_Restrictions::is_child_user() ) {
    wp_safe_redirect( home_url( RK_TUTOR_DASHBOARD_URL ) );
    exit;
}

$parent_id = get_current_user_id();
$children  = function_exists( 'rk_mc_get_children' ) ? rk_mc_get_children( $parent_id ) : [];

// Sélecteur enfant
// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$child_id = absint( $_GET['child_id'] ?? 0 );
if ( ! $child_id && ! empty( $children ) ) {
    $child_id = (int) $children[0]->id;
}
$child = null;
foreach ( $children as $c ) {
    if ( (int) $c->id === $child_id ) { $child = $c; break; }
}

global $wpdb;
$base = home_url( RK_TUTOR_DASHBOARD_URL );

/* ── Données enfant sélectionné ─────────────────────────────── */
$child_wp_id  = $child ? (int) ( $child->wp_user_id ?? 0 ) : 0;
$child_name   = $child ? ( $child->child_name ?? $child->display_name ?? '' ) : '';
$avatar_url   = $child ? rk_mc_get_avatar_url( $child ) : '';

// Niveau
$level_data = [];
if ( $child_id && class_exists( 'RK_MC_Gamification_Service' ) ) {
    $total      = RK_MC_Gamification_Service::get_total_points( $child_id );
    $level_data = RK_MC_Gamification_Service::compute_level( $total );
}

// Crédits
$credits = function_exists( 'rk_mc_get_session_credits' )
    ? (int) rk_mc_get_session_credits( $parent_id )
    : 0;

// Prochaine séance + fenêtre annulation
$next_session = null;
if ( $child_id ) {
    $bt = $wpdb->prefix . 'rk_bookings';
    if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $bt ) ) === $bt ) {
        $next_session = $wpdb->get_row( $wpdb->prepare(
            "SELECT appointment, booking_id, session_name, booking_date
               FROM {$bt}
              WHERE child_id = %d AND status = 'confirmed' AND appointment > NOW()
              ORDER BY appointment ASC LIMIT 1",
            $child_id
        ) );
    }
}
// Fenêtre annulation (24h depuis booking_date)
$next_booking_ts  = ! empty( $next_session->booking_date ) ? strtotime( $next_session->booking_date ) : 0;
$next_can_cancel  = $next_booking_ts > 0
    && ( time() - $next_booking_ts ) < DAY_IN_SECONDS;
$next_hours_left  = $next_booking_ts > 0
    ? max( 0, (int) ceil( ( $next_booking_ts + DAY_IN_SECONDS - time() ) / 3600 ) )
    : 0;

// Dernier feedback
$last_bilan = ( $child_id && class_exists( 'RK_MC_Assessment_Service' ) )
    ? RK_MC_Assessment_Service::get_latest( $child_id ) : null;

// Progression cours
$lms_enrolled = [];
$lms_avg      = 0;
if ( $child_wp_id && class_exists( 'RKP_LearningQueryService' ) ) {
    $lms_enrolled = RKP_LearningQueryService::get_enrolled_course_ids( $child_wp_id ) ?: [];
    if ( $lms_enrolled ) {
        $s = 0;
        foreach ( $lms_enrolled as $cid ) {
            $s += (int) RKP_LearningQueryService::get_completed_percent( $cid, $child_wp_id );
        }
        $lms_avg = (int) round( $s / count( $lms_enrolled ) );
    }
}

// Badges récents (3 derniers) — colonne earned_at (schéma DB)
$recent_badges = [];
if ( $child_id ) {
    $bt = $wpdb->prefix . 'rk_child_badges';
    if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $bt ) ) === $bt ) {
        $recent_badges = $wpdb->get_results( $wpdb->prepare(
            "SELECT badge_key, earned_at FROM {$bt} WHERE child_id = %d ORDER BY earned_at DESC LIMIT 3",
            $child_id
        ) ) ?: [];
    }
}
$badge_catalog = class_exists( 'RK_MC_Badge_Service' ) ? RK_MC_Badge_Service::catalogue() : [];

// URL coach pour message
$msg_url     = add_query_arg( [ 'child_id' => $child_id ], $base . 'rk-messages/' );
$book_url    = function_exists( 'get_permalink' )
    ? get_permalink( get_option( 'rk_booking_page_id', 0 ) ) ?: '#'
    : '#';

// Compteur messages non-lus (BM)
$msg_unread = 0;
if ( function_exists( 'bm_get_table' ) ) {
    $msg_unread = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COALESCE(SUM(unread_count),0) FROM " . bm_get_table( 'recipients' ) . "
          WHERE user_id = %d",
        $parent_id
    ) );
}

// Dernier rapport PDF généré
$last_pdf = $child_id ? get_user_meta( $parent_id, 'rk_pdf_last_' . $child_id, true ) : [];
$last_pdf_period = is_array( $last_pdf ) ? ( $last_pdf['period'] ?? '' ) : '';
$last_pdf_url    = is_array( $last_pdf ) ? ( $last_pdf['url']    ?? '' ) : '';

// Historique annulations (Sprint 7)
$cancelled_bookings = [];
if ( $child_id ) {
    $bt = $wpdb->prefix . 'rk_bookings';
    if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $bt ) ) === $bt ) {
        $cancelled_bookings = $wpdb->get_results( $wpdb->prepare(
            "SELECT session_name, appointment, booking_id, coach, updated_at
               FROM {$bt}
              WHERE child_id = %d AND status = 'cancelled'
              ORDER BY updated_at DESC LIMIT 5",
            $child_id
        ) ) ?: [];
    }
}
?>
<div class="rk-td-page-parent-board" dir="rtl" style="padding-bottom:40px;">

    <!-- ══ Sélecteur enfant (multiple) ════════════════════════════════ -->
    <?php if ( count( $children ) > 1 ) : ?>
    <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:24px;">
        <?php foreach ( $children as $c ) :
            $cid    = (int) $c->id;
            $cname  = $c->child_name ?? $c->display_name ?? 'طفل';
            $cav    = rk_mc_get_avatar_url( $c );
            $is_sel = $cid === $child_id;
        ?>
        <a href="<?php echo esc_url( add_query_arg( 'child_id', $cid, $base . 'rk-parent-board/' ) ); ?>"
           style="display:flex;align-items:center;gap:8px;padding:8px 14px;border-radius:30px;
                  background:<?php echo $is_sel ? 'var(--e-global-color-primary,#E8500A)' : '#f1f5f9'; ?>;
                  color:<?php echo $is_sel ? '#fff' : '#374151'; ?>;
                  font-size:.85rem;font-weight:600;text-decoration:none;transition:all .2s;">
            <img src="<?php echo esc_url( $cav ); ?>" width="28" height="28" style="border-radius:50%;"
                 alt="<?php echo esc_attr( $cname ); ?>">
            <?php echo esc_html( $cname ); ?>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if ( $child ) : ?>

    <!-- ══ Hero ═══════════════════════════════════════════════════════ -->
    <div style="background:linear-gradient(135deg,var(--e-global-color-secondary,#1B4F8C) 0%,#2563eb 100%);
                border-radius:20px;padding:28px 28px 24px;margin-bottom:24px;
                display:flex;align-items:center;gap:20px;flex-wrap:wrap;color:#fff;">
        <img src="<?php echo esc_url( $avatar_url ); ?>"
             alt="<?php echo esc_attr( $child_name ); ?>"
             width="72" height="72"
             style="border-radius:50%;border:3px solid rgba(255,255,255,.35);flex-shrink:0;">
        <div style="flex:1;min-width:160px;">
            <h2 style="margin:0 0 4px;font-size:1.3rem;font-weight:800;"><?php echo esc_html( $child_name ); ?></h2>
            <?php if ( ! empty( $level_data['label'] ) ) : ?>
            <p style="margin:0;font-size:.88rem;opacity:.9;">
                <?php echo esc_html( ( $level_data['icon'] ?? '⭐' ) . ' ' . $level_data['label'] ); ?>
            </p>
            <?php endif; ?>
        </div>
    </div>

    <!-- ══ 3 KPIs ══════════════════════════════════════════════════════ -->
    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:24px;">

        <!-- Crédits -->
        <div style="background:#fff;border-radius:16px;padding:20px;box-shadow:0 2px 8px rgba(0,0,0,.06);text-align:center;
                    border-top:4px solid <?php echo $credits <= 2 ? '#b91c1c' : 'var(--e-global-color-primary,#E8500A)'; ?>;">
            <p style="font-size:2rem;font-weight:900;margin:0;
                      color:<?php echo $credits <= 2 ? '#b91c1c' : 'var(--e-global-color-primary,#E8500A)'; ?>;">
                <?php echo $credits; ?>
            </p>
            <p style="font-size:.82rem;color:#64748b;margin:4px 0 0;"><?php esc_html_e( 'أرصدة لقاءات', 'rk-my-children' ); ?></p>
            <?php if ( $credits <= 2 ) : ?>
            <a href="<?php echo esc_url( $book_url ); ?>"
               style="display:inline-block;margin-top:8px;font-size:.75rem;color:#b91c1c;font-weight:700;">
                ⚠️ <?php esc_html_e( 'احجز الآن', 'rk-my-children' ); ?>
            </a>
            <?php endif; ?>
        </div>

        <!-- Prochaine séance -->
        <div style="background:#fff;border-radius:16px;padding:20px;box-shadow:0 2px 8px rgba(0,0,0,.06);text-align:center;
                    border-top:4px solid var(--e-global-color-secondary,#1B4F8C);">
            <?php if ( $next_session ) :
                $ns_ts  = rk_mc_appt_timestamp( $next_session->appointment );
                $ns_aid = (int) ( $next_session->booking_id ?? 0 );
            ?>
            <p style="font-size:1.15rem;font-weight:800;margin:0;color:var(--e-global-color-secondary,#1B4F8C);">
                <?php echo esc_html( rk_mc_appt_format( $next_session->appointment, 'j M', $ns_aid ) ); ?>
            </p>
            <p style="font-size:.85rem;color:#475569;margin:3px 0 0;">
                <?php echo esc_html( rk_mc_appt_format( $next_session->appointment, 'H:i', $ns_aid ) ); ?>
                <?php if ( ! empty( $next_session->session_name ) ) : ?>
                &nbsp;·&nbsp; <?php echo esc_html( $next_session->session_name ); ?>
                <?php endif; ?>
            </p>
            <?php if ( $next_can_cancel ) : ?>
            <span style="display:inline-flex;align-items:center;gap:3px;margin-top:6px;
                         background:#dcfce7;color:#166534;border-radius:6px;
                         padding:3px 8px;font-size:.72rem;font-weight:700;">
                🔓 <?php printf( esc_html__( 'إلغاء مفتوح — %d س', 'rk-my-children' ), $next_hours_left ); ?>
            </span>
            <?php elseif ( $next_booking_ts > 0 ) : ?>
            <span style="display:inline-flex;align-items:center;gap:3px;margin-top:6px;
                         background:#fee2e2;color:#991b1b;border-radius:6px;
                         padding:3px 8px;font-size:.72rem;font-weight:700;"
                  title="<?php esc_attr_e( 'انتهت مهلة الإلغاء — الحجز نهائي', 'rk-my-children' ); ?>">
                🔒 <?php esc_html_e( 'الإلغاء مُعلَّق', 'rk-my-children' ); ?>
            </span>
            <?php endif; ?>
            <?php else : ?>
            <p style="font-size:1.15rem;font-weight:800;margin:0;color:#94a3b8;">—</p>
            <p style="font-size:.82rem;color:#94a3b8;margin:4px 0 0;"><?php esc_html_e( 'لا جلسة محجوزة', 'rk-my-children' ); ?></p>
            <?php endif; ?>
            <p style="font-size:.78rem;color:#94a3b8;margin:6px 0 0;"><?php esc_html_e( 'الجلسة القادمة', 'rk-my-children' ); ?></p>
        </div>

        <!-- Niveau -->
        <div style="background:#fff;border-radius:16px;padding:20px;box-shadow:0 2px 8px rgba(0,0,0,.06);text-align:center;
                    border-top:4px solid #7c3aed;">
            <?php if ( ! empty( $level_data ) ) : ?>
            <p style="font-size:2rem;margin:0;"><?php echo esc_html( $level_data['icon'] ?? '⭐' ); ?></p>
            <p style="font-size:.85rem;font-weight:700;color:#7c3aed;margin:4px 0 0;"><?php echo esc_html( $level_data['label'] ?? '' ); ?></p>
            <?php else : ?>
            <p style="font-size:1.5rem;margin:0;">⭐</p>
            <?php endif; ?>
            <p style="font-size:.78rem;color:#94a3b8;margin:6px 0 0;"><?php esc_html_e( 'المستوى', 'rk-my-children' ); ?></p>
        </div>

    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:24px;">

        <!-- ══ Dernier feedback ══════════════════════════════════════ -->
        <div style="background:#fff;border-radius:16px;padding:20px;box-shadow:0 2px 8px rgba(0,0,0,.06);">
            <h3 style="font-size:.9rem;font-weight:800;color:#374151;margin:0 0 14px;display:flex;align-items:center;gap:6px;">
                ⭐ <?php esc_html_e( 'آخر تقييم من المدرب', 'rk-my-children' ); ?>
            </h3>
            <?php if ( $last_bilan ) : ?>
            <div style="display:flex;gap:3px;margin-bottom:10px;">
                <?php for ( $i = 1; $i <= 5; $i++ ) : ?>
                <svg width="18" height="18" viewBox="0 0 24 24"
                     fill="<?php echo $i <= (int) $last_bilan['rating'] ? '#f59e0b' : '#e2e8f0'; ?>"
                     aria-hidden="true">
                    <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>
                </svg>
                <?php endfor; ?>
                <span style="font-size:.8rem;color:#94a3b8;margin-right:6px;align-self:center;">
                    <?php echo esc_html( date_i18n( 'j M Y', strtotime( $last_bilan['assessed_at'] ?? '' ) ) ); ?>
                </span>
            </div>
            <?php if ( ! empty( $last_bilan['summary'] ) ) : ?>
            <p style="font-size:.87rem;color:#475569;line-height:1.6;margin:0 0 10px;
                      border-right:3px solid var(--e-global-color-primary,#E8500A);padding-right:10px;">
                <?php echo esc_html( wp_trim_words( $last_bilan['summary'], 20 ) ); ?>
            </p>
            <?php endif; ?>
            <a href="<?php echo esc_url( add_query_arg( 'child_id', $child_id, $base . 'reviews/' ) ); ?>"
               style="font-size:.8rem;font-weight:700;color:var(--e-global-color-secondary,#1B4F8C);text-decoration:none;">
                <?php esc_html_e( 'كل التقييمات ←', 'rk-my-children' ); ?>
            </a>
            <?php else : ?>
            <p style="font-size:.87rem;color:#94a3b8;margin:0;"><?php esc_html_e( 'لم يتم إجراء أي تقييم بعد.', 'rk-my-children' ); ?></p>
            <?php endif; ?>
        </div>

        <!-- ══ Progression cours ══════════════════════════════════════ -->
        <div style="background:#fff;border-radius:16px;padding:20px;box-shadow:0 2px 8px rgba(0,0,0,.06);">
            <h3 style="font-size:.9rem;font-weight:800;color:#374151;margin:0 0 14px;display:flex;align-items:center;gap:6px;">
                📚 <?php esc_html_e( 'التقدم في البرامج', 'rk-my-children' ); ?>
            </h3>
            <?php if ( $lms_enrolled ) : ?>
            <?php foreach ( array_slice( $lms_enrolled, 0, 3 ) as $cid ) :
                $pct   = $child_wp_id ? (int) RKP_LearningQueryService::get_completed_percent( $cid, $child_wp_id ) : 0;
                $title = get_the_title( $cid );
                $color = $pct >= 80 ? '#166534' : ( $pct >= 40 ? '#92400e' : '#1b4f8c' );
            ?>
            <div style="margin-bottom:12px;">
                <div style="display:flex;justify-content:space-between;font-size:.83rem;margin-bottom:4px;">
                    <span style="font-weight:600;color:#374151;"><?php echo esc_html( wp_trim_words( $title, 5 ) ); ?></span>
                    <span style="font-weight:700;color:<?php echo esc_attr( $color ); ?>;"><?php echo $pct; ?>%</span>
                </div>
                <div style="height:6px;background:#f1f5f9;border-radius:3px;overflow:hidden;">
                    <div style="height:100%;width:<?php echo $pct; ?>%;background:<?php echo esc_attr( $color ); ?>;border-radius:3px;transition:width .6s;"></div>
                </div>
            </div>
            <?php endforeach; ?>
            <?php else : ?>
            <p style="font-size:.87rem;color:#94a3b8;margin:0;"><?php esc_html_e( 'لم يسجّل في أي برنامج بعد.', 'rk-my-children' ); ?></p>
            <?php endif; ?>
        </div>

    </div>

    <!-- ══ Badges récents ══════════════════════════════════════════ -->
    <?php if ( $recent_badges ) : ?>
    <div style="background:#fff;border-radius:16px;padding:20px;box-shadow:0 2px 8px rgba(0,0,0,.06);margin-bottom:20px;">
        <h3 style="font-size:.9rem;font-weight:800;color:#374151;margin:0 0 14px;display:flex;align-items:center;justify-content:space-between;">
            <span>🏅 <?php esc_html_e( 'الشارات الأخيرة', 'rk-my-children' ); ?></span>
            <a href="<?php echo esc_url( add_query_arg( 'child_id', $child_id, $base . 'rk-badges/' ) ); ?>"
               style="font-size:.78rem;font-weight:600;color:var(--e-global-color-secondary,#1B4F8C);text-decoration:none;">
                <?php esc_html_e( 'كل الشارات ←', 'rk-my-children' ); ?>
            </a>
        </h3>
        <div style="display:flex;gap:14px;flex-wrap:wrap;">
            <?php foreach ( $recent_badges as $b ) :
                $def  = $badge_catalog[ $b->badge_key ] ?? [];
                $icon = $def['icon'] ?? '🏅';
                $name = $def['name'] ?? $b->badge_key;
            ?>
            <div style="display:flex;flex-direction:column;align-items:center;gap:4px;background:#fff7ed;
                        border-radius:12px;padding:12px 16px;min-width:70px;text-align:center;">
                <span style="font-size:1.8rem;"><?php echo esc_html( $icon ); ?></span>
                <span style="font-size:.72rem;font-weight:700;color:#92400e;"><?php echo esc_html( $name ); ?></span>
                <span style="font-size:.67rem;color:#d97706;"><?php echo ! empty( $b->awarded_at ) ? esc_html( date_i18n( 'j/m', strtotime( $b->awarded_at ) ) ) : ''; ?></span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- ══ Actions rapides ═════════════════════════════════════════ -->
    <div style="display:flex;gap:12px;flex-wrap:wrap;margin-top:4px;">
        <a href="<?php echo esc_url( $book_url ); ?>"
           style="flex:1;min-width:160px;display:flex;align-items:center;justify-content:center;gap:6px;
                  padding:12px 20px;font-size:.9rem;border-radius:12px;font-weight:700;text-decoration:none;
                  background:var(--e-global-color-primary,#E8500A);color:#fff;">
            📅 <?php esc_html_e( 'احجز جلسة', 'rk-my-children' ); ?>
        </a>
        <a href="<?php echo esc_url( $msg_url ); ?>"
           style="flex:1;min-width:160px;display:flex;align-items:center;justify-content:center;gap:6px;
                  padding:12px 20px;font-size:.9rem;border-radius:12px;font-weight:700;text-decoration:none;
                  background:var(--e-global-color-secondary,#1B4F8C);color:#fff;position:relative;">
            💬 <?php esc_html_e( 'الرسائل', 'rk-my-children' ); ?>
            <?php if ( $msg_unread > 0 ) : ?>
            <span style="background:#e8500a;color:#fff;border-radius:10px;font-size:.7rem;font-weight:800;
                         min-width:18px;height:18px;line-height:18px;text-align:center;padding:0 4px;">
                <?php echo $msg_unread; ?>
            </span>
            <?php endif; ?>
        </a>
        <?php if ( $last_pdf_url ) :
            $pdf_label = $last_pdf_period
                ? sprintf( '📊 %s', esc_html( $last_pdf_period ) )
                : '📊 ' . esc_html__( 'التقرير الشهري', 'rk-my-children' );
        ?>
        <a href="<?php echo esc_url( $last_pdf_url ); ?>"
           target="_blank" rel="noopener"
           style="flex:1;min-width:160px;display:flex;align-items:center;justify-content:center;gap:6px;
                  padding:12px 20px;font-size:.9rem;border-radius:12px;font-weight:700;text-decoration:none;
                  border:2px solid var(--e-global-color-secondary,#1B4F8C);color:var(--e-global-color-secondary,#1B4F8C);">
            <?php echo $pdf_label; ?>
        </a>
        <?php endif; ?>
    </div>

    <!-- ══ Historique annulations (Sprint 7) ════════════════════ -->
    <?php if ( $cancelled_bookings ) : ?>
    <div style="background:#fff;border-radius:16px;padding:20px;box-shadow:0 2px 8px rgba(0,0,0,.06);margin-top:20px;
                border-top:3px solid #b91c1c;">
        <h3 style="font-size:.9rem;font-weight:800;color:#374151;margin:0 0 14px;display:flex;align-items:center;gap:6px;">
            ❌ <?php esc_html_e( 'لقاءات الملغاة', 'rk-my-children' ); ?>
        </h3>
        <div style="display:flex;flex-direction:column;gap:8px;">
            <?php foreach ( $cancelled_bookings as $cb ) :
                $appt_ts   = $cb->appointment ? rk_mc_appt_timestamp( $cb->appointment ) : 0;
                $appt_aid  = (int) ( $cb->booking_id ?? 0 );
                $cancel_ts = $cb->updated_at  ? strtotime( $cb->updated_at  ) : 0;
            ?>
            <div style="display:flex;align-items:center;justify-content:space-between;
                        background:#fef2f2;border-radius:10px;padding:10px 14px;gap:10px;flex-wrap:wrap;">
                <div style="flex:1;min-width:120px;">
                    <p style="margin:0;font-size:.88rem;font-weight:700;color:#374151;">
                        <?php echo esc_html( $cb->session_name ?: __( 'جلسة', 'rk-my-children' ) ); ?>
                    </p>
                    <?php if ( $cb->coach ) : ?>
                    <p style="margin:2px 0 0;font-size:.76rem;color:#64748b;">
                        <?php echo esc_html( $cb->coach ); ?>
                    </p>
                    <?php endif; ?>
                </div>
                <div style="text-align:left;flex-shrink:0;">
                    <?php if ( $appt_ts ) : ?>
                    <p style="margin:0;font-size:.82rem;font-weight:700;color:#b91c1c;">
                        <?php echo esc_html( date_i18n( 'j M Y — H:i', $appt_ts ) ); ?>
                    </p>
                    <?php endif; ?>
                    <?php if ( $cancel_ts ) : ?>
                    <p style="margin:2px 0 0;font-size:.72rem;color:#94a3b8;">
                        <?php
                        /* translators: %s = date d'annulation */
                        printf( esc_html__( 'ألغي في: %s', 'rk-my-children' ), esc_html( date_i18n( 'j/m/Y', $cancel_ts ) ) );
                        ?>
                    </p>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php else : ?>
    <!-- Aucun enfant -->
    <div style="text-align:center;padding:60px 24px;background:#fff;border-radius:16px;box-shadow:0 2px 8px rgba(0,0,0,.06);">
        <p style="font-size:3rem;margin:0 0 12px;">👶</p>
        <p style="color:#64748b;font-size:.95rem;"><?php esc_html_e( 'لم يتم تسجيل أي طفل بعد.', 'rk-my-children' ); ?></p>
    </div>
    <?php endif; ?>

</div>
