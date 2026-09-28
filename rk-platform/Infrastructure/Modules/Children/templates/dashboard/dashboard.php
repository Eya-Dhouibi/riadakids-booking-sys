<?php
/**
 * Child Dashboard v4 — RiadaKids
 * Refonte UI conforme aux maquettes « Homepage (Desktop) » et « Homepage (Mobile) ».
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * PRINCIPES
 * ─────────────────────────────────────────────────────────────────────────────
 *  - UI uniquement : toutes les données proviennent des services existants
 *    (RK_MC_Child_Dashboard_Data → $view_data) ; aucune requête nouvelle hormis
 *    la prochaine réservation (déjà présente en v3, conservée à l'identique).
 *  - Widgets réutilisés : notif_bell_trigger / notif_bell_widget /
 *    child_param() / get_child_for_template() / rk_mc_appt_format().
 *  - Aucun nouvel endpoint : toute la navigation reste sur /dashboard/
 *    avec les slugs Tutor existants + paramètre child_id / rk_tab.
 *
 * @package RK_My_Children
 * @since   8.0.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;

include __DIR__ . '/partials/rkd4-icons.php'; // fournit $rkd4_icon + rkd4_youtube_card()

/* ══════════════════════════════════════════════════════════════════
   1. DONNÉES  (identiques à la v3 — aucune clé modifiée)
   ══════════════════════════════════════════════════════════════════ */
$view_data  = $view_data ?? [];
$child      = rk_mc_array_get( $view_data, 'child',      [] );
$journey    = rk_mc_array_get( $view_data, 'journey',    [] );
$adventures = rk_mc_array_get( $view_data, 'adventures', [] );
$badges     = rk_mc_array_get( $view_data, 'badges',     [] );
$messages   = rk_mc_array_get( $view_data, 'messages',   [] );
$calendar   = rk_mc_array_get( $view_data, 'calendar',   [] );

$child_name  = (string) ( $child['name'] ?? '' );
$child_xp    = (int) ( $child['points'] ?? $journey['current_xp'] ?? 0 );
$child_level = (int) ( $child['level']  ?? 1 );
$ring_pct    = (int) ( $journey['progress_pct'] ?? $child['level_progress'] ?? 0 );
$xp_to_next  = (int) ( $journey['xp_to_next'] ?? 0 );
$xp_target   = $xp_to_next > 0 ? $child_xp + $xp_to_next : max( $child_xp, 1 );
$child_avatar = (string) ( $child['avatar'] ?? '' );

$coach_id     = (int) ( $messages['coach_id']     ?? 0 );
$coach_name   = (string) ( $messages['coach_name'] ?? '' );
$coach_avatar = (string) ( $messages['coach_avatar'] ?? '' );
$coach_unread = (int) ( $messages['coach_unread'] ?? 0 );
$admin_unread = (int) ( $messages['admin_unread'] ?? 0 );
$total_unread = $coach_unread + $admin_unread;
$msg_nonce    = wp_create_nonce( 'rk_mc_messages' );

$img_ilu = RK_MC_URL . 'assets/img/illustrations/';

/* ── Contexte enfant : mêmes règles de propagation que la v3 ─────── */
$child_id_for_msg = (int) ( $_GET['child_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification
$_raw_tab = isset( $_GET['rk_tab'] ) ? sanitize_text_field( wp_unslash( $_GET['rk_tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
$_is_tab  = (bool) preg_match( '/^[a-f0-9]{40}$/', $_raw_tab );

$_nb  = home_url( RK_TUTOR_DASHBOARD_URL );
$_cid = $child_id_for_msg ?: (int) ( $child['id'] ?? 0 );
$_cp  = $_is_tab ? '?rk_tab=' . $_raw_tab : ( $_cid ? '?child_id=' . $_cid : '' );

$msg_page_url = $_nb . 'rk-messages/' . $_cp;

/* 6b-2 — Les notifications sont poussées séparément au parent ET à
 * l'enfant (voir trait-rk-mc-booking-bridge-hooks.php:295-301). Sur la
 * racine du dashboard (surface child_app), le lecteur est l'enfant :
 * le badge doit donc compter SES notifications, pas celles du parent.
 * Sans contexte enfant → 0, jamais de repli sur le parent. */
$_rk_notif_uid = class_exists( 'RK_Identity_Context' ) ? RK_Identity_Context::child_wp_uid() : 0;
$notif_unread  = ( $_rk_notif_uid > 0 && class_exists( 'RK_MC_Notification_Service' ) )
    ? (int) RK_MC_Notification_Service::get_unread_count( $_rk_notif_uid )
    : 0;
$notif_nonce = wp_create_nonce( 'rk_mc_notifs' );

/* ── Résolution de l'enfant (fallback session onglet) ────────────── */
if ( ! $_cid && class_exists( 'RK_MC_Tutor_Dashboard' ) ) {
    $_resolved = RK_MC_Tutor_Dashboard::get_child_for_template();
    $_cid      = $_resolved ? (int) $_resolved->id : 0;
}

/* ══════════════════════════════════════════════════════════════════
   2. PROCHAINE SÉANCE  (logique v3 conservée verbatim)
   ══════════════════════════════════════════════════════════════════ */
$_bk = null;
if ( $_cid ) {
    global $wpdb;
    $_bk_table = $wpdb->prefix . 'rk_bookings';
    $_bk = $wpdb->get_row( $wpdb->prepare(
        "SELECT id, appointment, booking_id, session_name, coach_id, coach, meeting_url
           FROM {$_bk_table}
          WHERE child_id = %d
            AND status IN ('confirmed','rescheduled')
            AND appointment > NOW()
          ORDER BY appointment ASC
          LIMIT 1",
        $_cid
    ) );
}

$_bk_live = false;
$_bk_url  = '';
$_bk_when = '';
if ( $_bk ) {
    $_bk_ts   = rk_mc_appt_timestamp( (string) $_bk->appointment );
    $_bk_aid  = (int) ( $_bk->booking_id ?? 0 );
    $_bk_url  = (string) ( $_bk->meeting_url ?? '' );
    $_bk_now  = current_time( 'timestamp' );
    $_bk_live = $_bk_ts
        && $_bk_now >= ( $_bk_ts - 15 * MINUTE_IN_SECONDS )
        && $_bk_now <= ( $_bk_ts + 2 * HOUR_IN_SECONDS );

    if ( $_bk_ts ) {
        $_diff = $_bk_ts - $_bk_now;
        if ( $_diff > 0 && $_diff <= HOUR_IN_SECONDS ) {
            /* translators: %d = minutes restantes */
            $_bk_when = sprintf( 'جلستك تبدأ بعد %d دقيقة', max( 1, (int) round( $_diff / 60 ) ) );
        } else {
            $_bk_when = rk_mc_appt_format( (string) $_bk->appointment, 'l، j F — H:i', $_bk_aid );
        }
    }
}

$sessions_count = is_array( $calendar ) ? count( $calendar ) : 0;

/* ══════════════════════════════════════════════════════════════════
   3. CHROME (sidebar / topbar / bottom nav) — partial dédié
   ══════════════════════════════════════════════════════════════════ */
include __DIR__ . '/partials/rkd4-nav.php';

/* ══════════════════════════════════════════════════════════════════
   4. MAPPINGS UI
   ══════════════════════════════════════════════════════════════════ */

/** Icône de carte « مغامرة » déduite de la catégorie / du titre. */
$rkd4_course_icon = static function ( string $label ): string {
    $map = [
        'ذكاء'      => 'brain',   'اصطناع'  => 'brain',   'ai'      => 'brain',
        'برمج'      => 'code',    'منطق'    => 'code',    'code'    => 'code',
        'إنجليز'    => 'book',    'انجليز'  => 'book',    'لغة'     => 'book',
        'ريادة'     => 'rocket',  'أعمال'   => 'rocket',  'تفكير'   => 'rocket',
    ];
    $needle = mb_strtolower( $label );
    foreach ( $map as $kw => $ico ) {
        if ( '' !== $kw && mb_strpos( $needle, $kw ) !== false ) return $ico;
    }
    return 'star';
};

/**
 * Liste des mentors — dérivée des instructeurs Tutor des cours suivis,
 * complétée par le coach de messagerie. Aucune donnée dupliquée :
 * la source reste $adventures (Tutor LMS) et $messages (Better Messages).
 */
$rkd4_coaches = [];
foreach ( (array) $adventures as $_adv ) {
    $iid = (int) ( $_adv['instructor_id'] ?? 0 );
    if ( ! $iid || isset( $rkd4_coaches[ $iid ] ) ) continue;
    $rkd4_coaches[ $iid ] = [
        'id'     => $iid,
        'name'   => (string) ( $_adv['instructor_name'] ?? '' ),
        'role'   => (string) ( $_adv['category'] ?? '' ),
        'avatar' => get_avatar_url( $iid, [ 'size' => 128 ] ),
    ];
}
if ( $coach_id && ! isset( $rkd4_coaches[ $coach_id ] ) ) {
    $rkd4_coaches[ $coach_id ] = [
        'id'     => $coach_id,
        'name'   => $coach_name,
        'role'   => 'المدرب المرافق',
        'avatar' => $coach_avatar ?: get_avatar_url( $coach_id, [ 'size' => 128 ] ),
    ];
}
$rkd4_coaches = array_slice( array_values( array_filter(
    $rkd4_coaches,
    static fn( $c ) => '' !== trim( (string) $c['name'] )
) ), 0, 3 );

/** Présence : une session WP active vaut « متصل الآن ». */
$rkd4_is_online = static function ( int $uid ): bool {
    if ( ! $uid || ! class_exists( 'WP_Session_Tokens' ) ) return false;
    $last = (int) get_user_meta( $uid, 'rk_last_seen', true );
    if ( $last && ( time() - $last ) < 10 * MINUTE_IN_SECONDS ) return true;
    return (bool) WP_Session_Tokens::get_instance( $uid )->get_all();
};

/** Prochaines شارات : les 3 premières non débloquées. */
$rkd4_next_badges = array_slice( array_values( array_filter(
    (array) $badges,
    static fn( $b ) => empty( $b['earned'] )
) ), 0, 3 );

$rkd4_badge_icon = [
    'star' => 'star', 'trophy' => 'trophy', 'rocket' => 'rocket', 'fire' => 'fire',
    'nav-target' => 'target', 'graduation-cap' => 'book', 'book-open' => 'book',
    'calendar' => 'calendar', 'award' => 'trophy', 'flag' => 'flag',
];

/* URL du mini-jeu « ميلدو » — filtrable, pas de route en dur. */
$rkd4_meldo_url = (string) apply_filters(
    'rk_mc_meldo_url',
    (string) get_option( 'rk_mc_meldo_url', '' )
);
?>
<script>document.body.classList.add('rk-child-game-page','rkd4-chrome','rkd4-home');</script>

<div id="rkd4" dir="rtl">

    <?php echo $rkd4_mobtop(); // phpcs:ignore WordPress.Security.EscapeOutput ?>

    <script>
    var rkMsgCfg = {
        ajaxUrl: "<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>",
        nonce:   "<?php echo esc_js( $msg_nonce ); ?>",
        childId: <?php echo (int) $_cid; ?>,
        coachId: <?php echo (int) $coach_id; ?>
    };
    var rkNotifCfg = {
        ajaxUrl: "<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>",
        nonce:   "<?php echo esc_js( $notif_nonce ); ?>"
    };
    </script>

    <div id="rkd4-shell">

        <!-- ══════════ COLONNE PRINCIPALE ══════════ -->
        <main id="rkd4-main">

            <!-- Actions flottantes (desktop) -->
            <?php echo $rkd4_topbar(); // phpcs:ignore WordPress.Security.EscapeOutput ?>

            <!-- ══════════ HERO ══════════ -->
            <section class="rkd4-hero">
                 <div class="rkd4-hero__text">
                    <h1 class="rkd4-hero__title">مرحبًا <?php echo esc_html( $child_name ); ?>،</h1>
                    <p class="rkd4-hero__sub">مغامرتك القادمة تنتظرك، فلنبدأ!</p>
                    <p class="rkd4-hero__lead">تقدم، تعلم، واكسب مكافآت في كل خطوة.</p>
                </div>
                <div class="rkd4-pcard">
                    <div class="rkd4-pcard__avatar">
                        <img src="<?php echo esc_url( $child_avatar ?: $img_ilu . 'character-banana.png' ); ?>"
                             alt="<?php echo esc_attr( $child_name ); ?>" width="104" height="104">
                        <span class="rkd4-pcard__lvl"><?php echo (int) $child_level; ?></span>
                    </div>
                    <p class="rkd4-pcard__name"><?php echo esc_html( $child_name ); ?></p>
                    <div class="rkd4-pcard__meta">
                        <span>نقاط XP</span>
                        <span>مستوى <?php echo (int) $child_level; ?></span>
                    </div>
                    <div class="rkd4-pcard__bar">
                        <div class="rkd4-pcard__fill" style="width:<?php echo (int) min( 100, max( 0, $ring_pct ) ); ?>%"></div>
                    </div>
                    <p class="rkd4-pcard__xp">
                        <?php echo esc_html( number_format_i18n( $child_xp ) ); ?> / <?php echo esc_html( number_format_i18n( $xp_target ) ); ?> نقطة
                    </p>
                </div>
            </section>

            <!-- v9.9 — Ancrage RKGuidance (§16-18) : conteneur vide, rempli
                 dynamiquement par rk-experience-layer.js SI une guidance
                 pertinente existe (next_action du JourneySnapshot). Aucun
                 contenu forcé ici — respecte "ne jamais afficher des
                 popups inutiles". -->
            <div data-rk-guidance-anchor></div>

            <!-- ══════════ CARTES D'ACTION ══════════ -->
            <div class="rkd4-actions">

                <!-- 1. Prochain لقاء -->
                <article class="rkd4-act rkd4-act--blue">
                    <div class="rkd4-act__wrap">
                        <div class="rkd4-act__head">
                            <?php echo $rkd4_icon( 'video', 20 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                            <h2 class="rkd4-act__title">انضم إلى لقائك</h2>
                        </div>
                        <p class="rkd4-act__sub">
                            <?php echo esc_html( $_bk_when ?: 'مغامرة جديدة بنتظارك!' ); ?>
                        </p>
                    </div>
                    <div class="rkd4-act__art">
                        <img src="<?php echo esc_url( $img_ilu . 'character-join.webp' ); ?>" alt="" loading="lazy">
                    </div>
                    <?php if ( $_bk_url && $_bk_live ) : ?>
                    <a href="<?php echo esc_url( $_bk_url ); ?>" class="rkd4-act__cta" target="_blank" rel="noopener">
                        انضم الآن <?php echo $rkd4_icon( 'arrow', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                    </a>
                    <?php else : ?>
                    <a href="<?php echo esc_url( $_nb . 'rk-sessions/' . $_cp ); ?>" class="rkd4-act__cta">
                        <?php echo $_bk ? 'عرض لقائي' : 'انضم الآن'; ?>
                        <?php echo $rkd4_icon( 'arrow', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                    </a>
                    <?php endif; ?>
                </article>

                <!-- 2. Jeu ميلدو -->
                <article class="rkd4-act rkd4-act--yellow">
                    <div class="rkd4-act__wrap">
                        <div class="rkd4-act__head">
                            <?php echo $rkd4_icon( 'gamepad', 20 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                            <h2 class="rkd4-act__title">العب مع ميلدو!</h2>
                        </div>
                        <p class="rkd4-act__sub">ألعاب رائعة بانتظارك!</p>
                    </div>
                    <div class="rkd4-act__art">
                        <img src="<?php echo esc_url( $img_ilu . 'character-meldo.webp' ); ?>" alt="" loading="lazy">
                    </div>
                    <?php if ( $rkd4_meldo_url ) : ?>
                    <a href="<?php echo esc_url( $rkd4_meldo_url ); ?>" class="rkd4-act__cta" target="_blank" rel="noopener">
                        هيا نلعب <?php echo $rkd4_icon( 'arrow', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                    </a>
                    <?php else : ?>
                    <span class="rkd4-act__cta rkd4-act__cta--muted">قريباً</span>
                    <?php endif; ?>
                </article>

                <!-- 3. Défis -->
                <article class="rkd4-act rkd4-act--green">
                    <div class="rkd4-act__wrap">
                        <div class="rkd4-act__head">
                            <?php echo $rkd4_icon( 'target', 20 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                            <h2 class="rkd4-act__title">تحدياتي</h2>
                        </div>
                        <p class="rkd4-act__sub">لديك تحدٍ جديد بانتظارك!</p>
                    </div>
                    <div class="rkd4-act__art">
                        <img src="<?php echo esc_url( $img_ilu . 'character-challenge.webp' ); ?>" alt="" loading="lazy">
                    </div>
                    <a href="<?php echo esc_url( $_nb . 'rk-challenges/' . $_cp ); ?>" class="rkd4-act__cta">
                        ابدأ التحدي <?php echo $rkd4_icon( 'arrow', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                    </a>
                </article>

            </div>

            <!-- ══════════ مغامراتي ══════════ -->
            <section>
                <div class="rkd4-sec__head">
                    <h2 class="rkd4-sec__title">مغامراتي</h2>
                    <a href="<?php echo esc_url( $_nb . 'enrolled-courses/' . $_cp ); ?>" class="rkd4-btn-pill">
                        كل المغامرات <?php echo $rkd4_icon( 'arrow', 14 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                    </a>
                </div>

                <?php if ( ! empty( $adventures ) ) : ?>
                <div class="rkd4-advs">
                    <?php foreach ( array_slice( (array) $adventures, 0, 4 ) as $adv ) :
                        $a_prog  = (int) ( $adv['progress'] ?? 0 );
                        $a_done  = $a_prog >= 100;
                        $a_lbl   = (string) ( $adv['category'] ?? '' ) ?: (string) ( $adv['title'] ?? '' );
                        $a_dn    = (int) ( $adv['lessons_done']  ?? 0 );
                        $a_tt    = (int) ( $adv['lessons_total'] ?? 0 );
                        $a_link  = ! empty( $adv['permalink'] ) ? $adv['permalink'] : $_nb . 'enrolled-courses/' . $_cp;
                        $a_thumb = (string) ( $adv['thumbnail'] ?? '' );
                    ?>
                    <article class="rkd4-adv<?php echo $a_done ? ' is-done' : ''; ?>">
                        <a href="<?php echo esc_url( $a_link ); ?>" class="rkd4-adv__media">
                            <?php if ( $a_thumb ) : ?>
                            <img src="<?php echo esc_url( $a_thumb ); ?>" alt="" loading="lazy">
                            <?php endif; ?>
                            <span class="rkd4-adv__chip"><?php echo $rkd4_icon( $rkd4_course_icon( $a_lbl ), 17 ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
                            <?php if ( $a_done ) : ?>
                            <span class="rkd4-adv__done"><?php echo $rkd4_icon( 'check', 12 ); // phpcs:ignore WordPress.Security.EscapeOutput ?> مكتملة</span>
                            <?php endif; ?>
                        </a>
                        <div class="rkd4-adv__body">
                            <div class="rkd4-adv__txt">
                                <h3 class="rkd4-adv__title"><?php echo esc_html( (string) ( $adv['title'] ?? '' ) ); ?></h3>
                                <?php if ( $a_tt > 0 ) : ?>
                                <p class="rkd4-adv__meta"><?php echo (int) $a_dn; ?> من <?php echo (int) $a_tt; ?> مغامرات</p>
                                <?php endif; ?>
                            </div>
                            <a href="<?php echo esc_url( $a_link ); ?>" class="rkd4-adv__go"
                               aria-label="<?php echo esc_attr( (string) ( $adv['title'] ?? '' ) ); ?>">
                                <?php echo $rkd4_icon( 'arrow', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                            </a>
                        </div>
                        <div class="rkd4-adv__bar"><span style="width:<?php echo (int) min( 100, max( 0, $a_prog ) ); ?>%"></span></div>
                    </article>
                    <?php endforeach; ?>
                </div>
                <?php else : ?>
                <div class="rk4-empty">
                    <div class="rk4-deco-layer" aria-hidden="true">
                        <img src="<?php echo esc_url( RK_MC_URL . 'assets/img/illustrations/deco-star.png' ); ?>" alt="" style="width:28px;top:14px;inset-inline-start:8%;" loading="lazy">
                        <img src="<?php echo esc_url( RK_MC_URL . 'assets/img/illustrations/deco-cloud.png' ); ?>" alt="" style="width:64px;top:60%;inset-inline-end:6%;" loading="lazy">
                    </div>
                    <img src="<?php echo esc_url( RK_MC_URL . 'assets/img/illustrations/empty-course.webp' ); ?>"
                         alt="" class="rk4-empty__illustration" data-rk-float loading="lazy" width="180" height="180">
                    <h3 class="rk4-empty__title"><?php esc_html_e( 'لم تبدأ أي مغامرة بعد!', 'rk-my-children' ); ?></h3>
                    <p class="rk4-empty__text"><?php esc_html_e( 'اختر مغامرتك الأولى وابدأ رحلة التعلم مع مدربك.', 'rk-my-children' ); ?></p>
                    <a href="<?php echo esc_url( $_nb . 'enrolled-courses/' . $_cp ); ?>" class="rk4-empty__cta" data-rk-btn-pop>
                        <?php esc_html_e( 'استكشف مغامراتك', 'rk-my-children' ); ?>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" aria-hidden="true"><polyline points="15 18 9 12 15 6"/></svg>
                    </a>
                </div>
                <?php endif; ?>
            </section>

            <!-- ══════════ MENTORS + PROCHAINES ÉCUSSONS ══════════ -->
            <div class="rkd4-bottom">

                <section>
                    <div class="rkd4-sec__head">
                        <h2 class="rkd4-sec__title">تواصل مع مدربك</h2>
                        <a href="<?php echo esc_url( $msg_page_url ); ?>" class="rkd4-btn-pill">
                            كل الرسائل <?php echo $rkd4_icon( 'arrow', 14 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                        </a>
                    </div>

                    <?php if ( ! empty( $rkd4_coaches ) ) : ?>
                    <div class="rkd4-coaches">
                        <?php foreach ( $rkd4_coaches as $c ) :
                            $online = $rkd4_is_online( (int) $c['id'] );
                        ?>
                        <article class="rkd4-coach">
                            <img class="rkd4-coach__avatar" src="<?php echo esc_url( $c['avatar'] ); ?>"
                                 alt="" width="64" height="64" loading="lazy">
                            <h3 class="rkd4-coach__name"><?php echo esc_html( $c['name'] ); ?></h3>
                            <p class="rkd4-coach__role"><?php echo esc_html( $c['role'] ); ?></p>
                            <span class="rkd4-coach__status rkd4-coach__status--<?php echo $online ? 'on' : 'off'; ?>">
                                <span class="rkd4-coach__dot"></span>
                                <?php echo $online ? 'متصل الآن' : 'غير متصل'; ?>
                            </span>
                            <a href="<?php echo esc_url( add_query_arg( 'coach', (int) $c['id'], $msg_page_url ) ); ?>"
                               class="rkd4-coach__btn">
                                <?php echo $rkd4_icon( 'send', 14 ); // phpcs:ignore WordPress.Security.EscapeOutput ?> أرسل رسالة
                            </a>
                        </article>
                        <?php endforeach; ?>
                    </div>
                    <?php else : ?>
                    <div class="rk4-empty rk4-empty--compact">
                        <?php echo wp_kses_post( rk_mc_svg( 'award', [ 'class' => 'rk4-empty__icon' ] ) ); ?>
                        <p class="rk4-empty__text"><?php esc_html_e( 'سيظهر مدربك هنا بمجرد بدء أول مغامرة.', 'rk-my-children' ); ?></p>
                    </div>
                    <?php endif; ?>
                </section>

                <section>
                    <div class="rkd4-sec__head">
                        <h2 class="rkd4-sec__title">شاراتك القادمة</h2>
                        <a href="<?php echo esc_url( $_nb . 'rk-badges/' . $_cp ); ?>" class="rkd4-btn-pill">
                            عرض كل الشارات <?php echo $rkd4_icon( 'arrow', 14 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                        </a>
                    </div>

                    <?php if ( ! empty( $rkd4_next_badges ) ) : ?>
                    <div class="rkd4-badges">
                        <?php foreach ( $rkd4_next_badges as $b ) :
                            $b_ico = $rkd4_badge_icon[ $b['icon_key'] ?? '' ] ?? 'trophy';
                        ?>
                        <article class="rkd4-badge">
                            <span class="rkd4-badge__ico">
                                <?php echo $rkd4_icon( $b_ico, 28 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                                <span class="rkd4-badge__lock"><?php echo $rkd4_icon( 'lock', 11 ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
                            </span>
                            <h3 class="rkd4-badge__name"><?php echo esc_html( (string) ( $b['name'] ?? '' ) ); ?></h3>
                            <p class="rkd4-badge__hint"><?php echo esc_html( (string) ( $b['description'] ?? ( $b['hint'] ?? '' ) ) ); ?></p>
                        </article>
                        <?php endforeach; ?>
                    </div>
                    <?php else : ?>
                    <div class="rk4-empty rk4-empty--compact">
                        <?php echo wp_kses_post( rk_mc_svg( 'trophy', [ 'class' => 'rk4-empty__icon' ] ) ); ?>
                        <p class="rk4-empty__text"><?php esc_html_e( 'أحسنت! لقد جمعت كل الشارات المتاحة.', 'rk-my-children' ); ?></p>
                    </div>
                    <?php endif; ?>
                </section>

            </div>

            <!-- Bandeau YouTube (mobile uniquement — desktop : sidebar) -->
            <?php echo RK_MC_Dashboard_Chrome_V4::youtube_card( true ); // phpcs:ignore WordPress.Security.EscapeOutput ?>

        </main>

        <!-- ══════════ SIDEBAR (desktop) ══════════ -->
        <?php echo $rkd4_sidebar(); // phpcs:ignore WordPress.Security.EscapeOutput ?>

    </div><!-- /#rkd4-shell -->

    <?php echo $rkd4_botnav(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
    <?php echo $rkd4_msg_fab(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
    <?php echo RK_MC_Tutor_Dashboard::notif_bell_widget( $notif_nonce ); // phpcs:ignore WordPress.Security.EscapeOutput ?>

</div><!-- /#rkd4 -->