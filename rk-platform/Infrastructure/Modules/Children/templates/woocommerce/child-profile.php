<?php
declare( strict_types=1 );
/**
 * RiadaKids — WooCommerce My Account : الملف الكامل (fiche complète, parent)
 * Accessible à /my-account/child-profile/?child_id=X
 *
 * Vue LECTURE SEULE pour le parent : hérite des mêmes données/services que
 * le Child Dashboard (RK_MC_Child_Dashboard_Data → adventures, badges, XP,
 * sessions, dernier rapport coach) mais sans le chrome ni les interactions
 * propres à l'espace enfant (pas de FAB messages, pas de notif bell, etc.).
 *
 * @package RK_My_Children
 * @since   10.0.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$parent_id = get_current_user_id();
if ( ! $parent_id ) return;

// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$child_id = absint( $_GET['child_id'] ?? 0 );

$child = $child_id && class_exists( 'RK_MC_Child_Repository' )
    ? RK_MC_Child_Repository::get_child( $child_id, $parent_id )
    : null;

if ( ! $child ) {
    echo '<p dir="rtl" style="padding:24px;color:#64748b;">'
        . esc_html__( 'الطفل غير موجود أو لا يمكن الوصول إليه.', 'rk-my-children' )
        . '</p>';
    return;
}

$child_id   = (int) $child->id;
$child_name = rk_mc_child_full_name( $child );
$avatar_url = rk_mc_get_avatar_url( $child );
$child_age  = (int) ( $child->child_age ?? 0 );
$wp_uid     = (int) ( $child->wp_user_id ?? 0 );

/* ── Mêmes données que le Child Dashboard ─────────────────────── */
// AJOUT (demande utilisateur) — cohérence garantie avec le Child
// Dashboard : synchronise d'abord les inscriptions Tutor LMS depuis les
// bookings confirmés de CET enfant (idempotent, voir sa doc), pour que
// tout cours réservé via booking apparaisse bien dans "المغامرات" même
// si l'inscription automatique à l'achat n'a pas eu lieu.
if ( class_exists( 'RK_MC_Course_Enrollment' ) ) {
    RK_MC_Course_Enrollment::sync_for_child( $child_id );
}

$view_data  = class_exists( 'RK_MC_Child_Dashboard_Data' )
    ? RK_MC_Child_Dashboard_Data::get_dashboard_data( $child_id, $child )
    : array();

$journey    = $view_data['journey']  ?? array();
$adventures = $view_data['courses']  ?? ( $view_data['adventures'] ?? array() );
$badges     = $view_data['badges']   ?? array();
$calendar   = $view_data['calendar'] ?? array();
$assessment = $view_data['assessment'] ?? array();
$latest_eval = $assessment['latest'] ?? null;

$xp_current   = (int) ( $journey['current_xp']   ?? 0 );
$xp_to_next   = (int) ( $journey['xp_to_next']   ?? 0 );
$xp_target    = $xp_to_next > 0 ? $xp_current + $xp_to_next : max( $xp_current, 1 );
$ring_pct     = (int) ( $journey['progress_pct'] ?? 0 );
$badge_count  = (int) ( $journey['badge_collected'] ?? count( array_filter( $badges, static fn( $b ) => ! empty( $b['earned'] ) ) ) );

/* ── PIN de connexion enfant ───────────────────────────────────── */
$child_pin = ( $wp_uid && class_exists( 'RK_MC_Child_User' ) )
    ? RK_MC_Child_User::get_child_pin( $wp_uid )
    : '';

/* ── Stats globales (présence + total séances) ────────────────── */
global $wpdb;
$attn_rate  = 0;
$total_past = 0;
$total_all  = 0;
if ( $child_id ) {
    $attendance_rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT attendance FROM {$wpdb->prefix}rk_bookings
          WHERE child_id = %d AND appointment < %s AND attendance IN ('present','absent')",
        $child_id, current_time( 'mysql' )
    ) );
    $total_past = count( $attendance_rows );
    if ( $total_past > 0 ) {
        $present_count = count( array_filter( $attendance_rows, static fn( $r ) => $r->attendance === 'present' ) );
        $attn_rate     = (int) round( ( $present_count / $total_past ) * 100 );
    }
    $total_all = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}rk_bookings WHERE child_id = %d AND status != 'cancelled'",
        $child_id
    ) );
}

/* ── Progression moyenne d'achèvement des مغامرات ─────────────── */
$adv_avg = 0;
if ( ! empty( $adventures ) ) {
    $sum = 0;
    foreach ( $adventures as $a ) { $sum += (int) ( $a['progress'] ?? 0 ); }
    $adv_avg = (int) round( $sum / count( $adventures ) );
}

/* ── Séances récentes (historique, pas seulement à venir) ──────── */
$recent_sessions = $wpdb->get_results( $wpdb->prepare(
    "SELECT appointment, session_name, coach, status
       FROM {$wpdb->prefix}rk_bookings
      WHERE child_id = %d
   ORDER BY appointment DESC
      LIMIT 5",
    $child_id
) ) ?: array();

/* ── Dernier rapport (lien vers rk-rapport pré-filtré) ─────────── */
$rapport_url  = add_query_arg( 'child_id', $child_id, wc_get_account_endpoint_url( RK_MC_Endpoint::RAPPORT_SLUG ) );
$children_url = wc_get_account_endpoint_url( RK_MC_Endpoint::SLUG );
$meetings_url = wc_get_account_endpoint_url( 'book-session' ); // même endpoint que la sidebar (navigation.php)

/* ── Lien vers l'espace de connexion enfant (page standalone) ─── */
$child_login_url = home_url( '/connexion-child/' );

/* ── Avatar du coach pour "آخر تقرير" (donnée réelle : coach_id) ─ */
$coach_avatar_url = '';
if ( $latest_eval && ! empty( $latest_eval['coach_id'] ) ) {
    $coach_avatar_url = get_avatar_url( (int) $latest_eval['coach_id'], array( 'size' => 40 ) );
}

/*
 * Rotation d'icônes décoratives partagée (المغامرات + سجل اللقاءات) :
 * ni rk_bookings ni build_courses() n'exposent de champ icon_key/catégorie
 * exploitable, donc on ne fabrique pas de mapping hypothétique — on
 * applique une rotation stable sur le jeu d'icônes déjà utilisé ailleurs
 * dans le plugin (rkd4-icons.php), pour un rendu proche de Figma sans
 * inventer de donnée.
 */
$rk_icon_cycle = array( 'brain', 'code', 'book', 'rocket' );

/*
 * Icônes exactes extraites de la maquette Figma (Copy as SVG) pour la
 * section stats du hero — le set générique rkd4-icons.php ne matchait
 * pas visuellement (formes différentes), donc on utilise ici les tracés
 * réels plutôt qu'une approximation.
 */
$rk_stat_sparkle_svg = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" aria-hidden="true">'
    . '<path d="M12 22C17.5228 22 22 17.5228 22 12C22 6.47715 17.5228 2 12 2C6.47715 2 2 6.47715 2 12C2 17.5228 6.47715 22 12 22Z" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/>'
    . '<path d="M11.051 7.61608C11.1171 7.41342 11.2462 7.23717 11.4194 7.11305C11.5927 6.98892 11.8012 6.92342 12.0143 6.9261C12.2275 6.92878 12.4342 6.9995 12.6043 7.12794C12.7744 7.25638 12.899 7.43582 12.96 7.64008L13.697 9.09208C13.7687 9.23323 13.8729 9.35534 14.001 9.44835C14.1292 9.54136 14.2776 9.60262 14.434 9.62708L16.068 9.88308C16.2784 9.8839 16.4832 9.95107 16.6532 10.075C16.8232 10.199 16.9498 10.3734 17.015 10.5735C17.0801 10.7736 17.0805 10.9891 17.016 11.1894C16.9516 11.3897 16.8256 11.5645 16.656 11.6891L15.484 12.8571C15.3718 12.9688 15.2877 13.1056 15.2387 13.2561C15.1896 13.4067 15.1771 13.5667 15.202 13.7231L15.461 15.3361C15.5322 15.5382 15.5366 15.758 15.4735 15.9628C15.4104 16.1677 15.2832 16.3469 15.1105 16.4739C14.9379 16.601 14.729 16.6692 14.5146 16.6685C14.3003 16.6678 14.0918 16.5982 13.92 16.4701L12.455 15.7201C12.3139 15.6478 12.1576 15.6101 11.999 15.6101C11.8404 15.6101 11.6841 15.6478 11.543 15.7201L10.078 16.4701C9.90617 16.5972 9.69815 16.6659 9.48443 16.6661C9.27072 16.6664 9.06255 16.5981 8.89044 16.4714C8.71833 16.3447 8.59135 16.1662 8.5281 15.9621C8.46485 15.7579 8.46866 15.5389 8.53898 15.3371L8.79698 13.7241C8.82206 13.5676 8.80957 13.4073 8.76054 13.2566C8.71151 13.1058 8.62734 12.9689 8.51498 12.8571L7.35898 11.7051C7.18364 11.5836 7.05174 11.4092 6.98254 11.2075C6.91335 11.0057 6.91049 10.7871 6.97438 10.5835C7.03827 10.38 7.16557 10.2023 7.33767 10.0763C7.50978 9.95023 7.71767 9.88254 7.93098 9.88308L9.56398 9.62708C9.72041 9.60262 9.86879 9.54136 9.99692 9.44835C10.1251 9.35534 10.2293 9.23323 10.301 9.09208L11.051 7.61608Z" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/>'
    . '</svg>';

$rk_stat_ring_svg = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" aria-hidden="true">'
    . '<path d="M21 12C20.9999 13.9005 20.3981 15.7523 19.2809 17.2899C18.1637 18.8274 16.5885 19.9719 14.7809 20.5591C12.9733 21.1464 11.0262 21.1463 9.21864 20.559C7.41109 19.9716 5.83588 18.8271 4.71876 17.2895C3.60165 15.7518 2.99999 13.9 3 11.9994C3.00001 10.0989 3.60171 8.24706 4.71884 6.70945C5.83598 5.17184 7.4112 4.02736 9.21877 3.44003C11.0263 2.8527 12.9734 2.85267 14.781 3.43995" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>'
    . '</svg>';

include RK_MC_DIR . 'templates/dashboard/partials/rkd4-icons.php';
?>
<div class="rk-cprofile" dir="rtl">

    <!-- ══ Breadcrumb : الأطفال / [اسم الطفل] ══ -->
    <nav class="rk-cprofile__breadcrumb" aria-label="<?php esc_attr_e( 'مسار التصفح', 'rk-my-children' ); ?>">
        <a href="<?php echo esc_url( $children_url ); ?>"><?php esc_html_e( 'الأطفال', 'rk-my-children' ); ?></a>
        <span class="rk-cprofile__breadcrumb-sep" aria-hidden="true">/</span>
        <span class="rk-cprofile__breadcrumb-current" aria-current="page"><?php echo esc_html( $child_name ); ?></span>
    </nav>

    <div class="rk-cprofile__back">
        <a href="<?php echo esc_url( $children_url ); ?>">
            <?php echo $rkd4_icon( 'arrow', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
            <?php esc_html_e( 'العودة إلى الأطفال', 'rk-my-children' ); ?>
        </a>
    </div>

    <!-- ══ En-tête : accès enfant + identité + XP ══
         Structure fidèle à Figma : PIN block et identité+avatar sur LA
         MÊME LIGNE en desktop (pas empilés) ; repli en colonne sur mobile
         (voir CSS). Avatar 125x125 (valeur exacte Figma, pas 88px). -->
    <div class="rk-cprofile__hero">
        <div class="rk-cprofile__hero-row">

            <div class="rk-cprofile__identity-row">
                <div class="rk-cprofile__avatar">
                    <img src="<?php echo esc_url( $avatar_url ); ?>" alt="<?php echo esc_attr( $child_name ); ?>" width="125" height="125" loading="lazy">
                    <?php if ( $badge_count > 0 ) : ?>
                    <span class="rk-cprofile__avatar-badge"><?php echo (int) $badge_count; ?></span>
                    <?php endif; ?>
                </div>

                <div class="rk-cprofile__identity">
                <h1><?php echo esc_html( $child_name ); ?></h1>
                <?php if ( $child_age > 0 ) : ?>
                <span class="rk-cprofile__age"><?php echo esc_html( $child_age ); ?> <?php esc_html_e( 'سنوات', 'rk-my-children' ); ?></span>
                <?php endif; ?>
                <div class="rk-cprofile__xp">
                    <span class="rk-cprofile__xp-label"><?php esc_html_e( 'نقاط XP', 'rk-my-children' ); ?></span>
                    <span class="rk-cprofile__xp-val"><?php echo esc_html( $xp_current ); ?> / <?php echo esc_html( $xp_target ); ?></span>
                </div>
                <div class="rk-cprofile__xp-bar"><span style="width:<?php echo (int) $ring_pct; ?>%;"></span></div>
                </div>
            </div>

            <?php if ( $child_pin ) : ?>
            <div class="rk-cprofile__pin">
                <a href="<?php echo esc_url( $child_login_url ); ?>" class="rk-cprofile__pin-btn">
                    <svg width="20" height="20" viewBox="0 0 20 20" fill="none" aria-hidden="true">
                        <path d="M9.99996 15.8334L4.16663 10.0001L9.99996 4.16675" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        <path d="M15.8333 10H4.16663" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    <?php esc_html_e( 'دخول حساب الطفل', 'rk-my-children' ); ?>
                </a>
                <span class="rk-cprofile__pin-info">
                    <span class="rk-cprofile__pin-icon" aria-hidden="true">
                        <svg width="16" height="16" viewBox="0 0 27 27" fill="none">
                            <path d="M2.25 10.125C3.14511 10.125 4.00355 10.4806 4.63649 11.1135C5.26942 11.7464 5.625 12.6049 5.625 13.5C5.625 14.3951 5.26942 15.2536 4.63649 15.8865C4.00355 16.5194 3.14511 16.875 2.25 16.875V19.125C2.25 19.7217 2.48705 20.294 2.90901 20.716C3.33097 21.1379 3.90326 21.375 4.5 21.375H22.5C23.0967 21.375 23.669 21.1379 24.091 20.716C24.5129 20.294 24.75 19.7217 24.75 19.125V16.875C23.8549 16.875 22.9964 16.5194 22.3635 15.8865C21.7306 15.2536 21.375 14.3951 21.375 13.5C21.375 12.6049 21.7306 11.7464 22.3635 11.1135C22.9964 10.4806 23.8549 10.125 24.75 10.125V7.875C24.75 7.27826 24.5129 6.70597 24.091 6.28401C23.669 5.86205 23.0967 5.625 22.5 5.625H4.5C3.90326 5.625 3.33097 5.86205 2.90901 6.28401C2.48705 6.70597 2.25 7.27826 2.25 7.875V10.125Z" stroke="#295177" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                            <path d="M14.625 5.625V7.875" stroke="#295177" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                            <path d="M14.625 19.125V21.375" stroke="#295177" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                            <path d="M14.625 12.375V14.625" stroke="#295177" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </span>
                    <span class="rk-cprofile__pin-text">
                        <strong><?php echo esc_html( $child_pin ); ?></strong>
                        <span class="rk-cprofile__pin-label"><?php esc_html_e( 'رمز الدخول', 'rk-my-children' ); ?></span>
                    </span>
                </span>
            </div>
            <?php endif; ?>

        </div>
    </div>

    <!-- ══ Stats rapides ══ -->
    <div class="rk-cprofile__stats">
        <div class="rk-cprofile__stat">
            <span class="rk-cprofile__stat-icon"><?php echo $rk_stat_sparkle_svg; // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
            <span class="rk-cprofile__stat-text">
                <strong><?php echo (int) $adv_avg; ?>%</strong>
                <span><?php esc_html_e( 'نسبة إتمام المغامرات', 'rk-my-children' ); ?></span>
            </span>
        </div>
        <div class="rk-cprofile__stat">
            <span class="rk-cprofile__stat-icon"><?php echo $rk_stat_ring_svg; // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
            <span class="rk-cprofile__stat-text">
                <strong><?php echo $total_past > 0 ? (int) $attn_rate . '%' : '—'; ?></strong>
                <span><?php esc_html_e( 'نسبة الحضور', 'rk-my-children' ); ?></span>
            </span>
        </div>
        <div class="rk-cprofile__stat">
            <span class="rk-cprofile__stat-icon"><?php echo $rk_stat_sparkle_svg; // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
            <span class="rk-cprofile__stat-text">
                <strong><?php echo (int) $badge_count; ?></strong>
                <span><?php esc_html_e( 'الشارات', 'rk-my-children' ); ?></span>
            </span>
        </div>
        <div class="rk-cprofile__stat">
            <span class="rk-cprofile__stat-icon"><?php echo $rk_stat_sparkle_svg; // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
            <span class="rk-cprofile__stat-text">
                <strong><?php echo (int) $total_all; ?></strong>
                <span><?php esc_html_e( 'إجمالي اللقاءات', 'rk-my-children' ); ?></span>
            </span>
        </div>
    </div>

    <!-- ══ المغامرات ══ -->
    <div class="rk-cprofile__section">
        <h2><?php esc_html_e( 'المغامرات', 'rk-my-children' ); ?></h2>
        <?php if ( ! empty( $adventures ) ) : ?>
        <!-- AJUSTEMENT (demande utilisateur) — slider horizontal (3
             items/vue desktop, 1/vue mobile) au lieu d'une grille fluide,
             avec flèches de pagination. Structure JS-driven, voir
             assets/js/child-profile-slider.js — le CSS (rk-cprofile__adv-
             track/rk-cprofile__adv-nav) définit le viewport/scroll, le JS
             ne fait que déplacer .rk-cprofile__adv-track et activer/
             désactiver les flèches en bord de liste. -->
        <div class="rk-cprofile__adv-slider" data-rk-slider>
            <button type="button" class="rk-cprofile__adv-nav rk-cprofile__adv-nav--prev" data-dir="prev"
                    aria-label="<?php esc_attr_e( 'السابق', 'rk-my-children' ); ?>">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <polyline points="15 18 9 12 15 6"/>
                </svg>
            </button>
            <div class="rk-cprofile__adv-viewport">
                <div class="rk-cprofile__adv-track">
                    <?php foreach ( $adventures as $rk_adv_i => $adv ) :
                        $a_prog  = (int) ( $adv['progress'] ?? 0 );
                        $a_title = (string) ( $adv['title'] ?? '' );
                        $a_thumb = (string) ( $adv['thumbnail'] ?? '' );
                        $a_icon  = $rk_icon_cycle[ $rk_adv_i % count( $rk_icon_cycle ) ];
                    ?>
                    <div class="rk-cprofile__adv">
                        <div class="rk-cprofile__adv-media">
                            <?php if ( $a_thumb ) : ?>
                            <img src="<?php echo esc_url( $a_thumb ); ?>" alt="" loading="lazy">
                            <?php endif; ?>
                            <span class="rk-cprofile__adv-icon" aria-hidden="true">
                                <?php echo $rkd4_icon( $a_icon, 16 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                            </span>
                        </div>
                        <div class="rk-cprofile__adv-body">
                            <h3><?php echo esc_html( $a_title ); ?></h3>
                            <div class="rk-cprofile__adv-bar-wrap">
                                <div class="rk-cprofile__adv-bar" style="width:<?php echo $a_prog; ?>%;"></div>
                            </div>
                            <span><?php echo $a_prog; ?>% <?php esc_html_e( 'نسبة الإتمام', 'rk-my-children' ); ?></span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <button type="button" class="rk-cprofile__adv-nav rk-cprofile__adv-nav--next" data-dir="next"
                    aria-label="<?php esc_attr_e( 'التالي', 'rk-my-children' ); ?>">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <polyline points="9 18 15 12 9 6"/>
                </svg>
            </button>
        </div>
        <?php else : ?>
        <p class="rk-cprofile__empty"><?php esc_html_e( 'لم تبدأ أي مغامرة بعد.', 'rk-my-children' ); ?></p>
        <?php endif; ?>
    </div>

    <!-- ══ سجل الجلسات + آخر تقرير ══ -->
    <div class="rk-cprofile__cols">

        <div class="rk-cprofile__section">
            <div class="rk-cprofile__section-head">
                <h2><?php esc_html_e( 'سجل اللقاءات', 'rk-my-children' ); ?></h2>
                <a href="<?php echo esc_url( add_query_arg( array( 'child_id' => $child_id, 'tab' => 'booked' ), $meetings_url ) ); ?>" class="rk-cprofile__link rk-cprofile__link--pill">
                    <?php esc_html_e( 'كل اللقاءات', 'rk-my-children' ); ?>
                    <?php echo $rkd4_icon( 'arrow', 13 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                </a>
            </div>
            <?php if ( ! empty( $recent_sessions ) ) : ?>
            <ul class="rk-cprofile__sessions">
                <?php foreach ( $recent_sessions as $rk_sess_i => $s ) :
                    $ts = strtotime( (string) $s->appointment );
                    $session_report_url = $rapport_url; // même rapport enfant, pré-filtré
                    // Même logique de rotation que pour les cartes المغامرات
                    // (aucun champ icon_key sur rk_bookings) — cohérence visuelle
                    // sans inventer de mapping catégorie→icône.
                    $session_icon = $rk_icon_cycle[ $rk_sess_i % count( $rk_icon_cycle ) ];
                ?>
                <li>
                    <span class="rk-cprofile__session-cta">
                        <a href="<?php echo esc_url( add_query_arg( array( 'child_id' => $child_id, 'tab' => 'booked' ), $meetings_url ) ); ?>" class="rk-cprofile__link rk-cprofile__link--pill">
                            <?php esc_html_e( 'عرض التفاصيل', 'rk-my-children' ); ?>
                            <?php echo $rkd4_icon( 'arrow', 13 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                        </a>
                    </span>
                    <span class="rk-cprofile__session-info">
                        <span class="rk-cprofile__session-name"><?php echo esc_html( $s->session_name ?: __( 'لقاء', 'rk-my-children' ) ); ?></span>
                        <span class="rk-cprofile__session-date">
                            <?php echo $rkd4_icon( 'calendar', 12 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                            <?php echo $ts ? esc_html( date_i18n( 'j/m/Y H:i', $ts ) ) : '—'; ?>
                        </span>
                    </span>
                    <span class="rk-cprofile__session-icon" aria-hidden="true">
                        <?php echo $rkd4_icon( $session_icon, 16 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                    </span>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php else : ?>
            <p class="rk-cprofile__empty"><?php esc_html_e( 'لا توجد لقاءات مسجلة بعد.', 'rk-my-children' ); ?></p>
            <?php endif; ?>
        </div>

        <div class="rk-cprofile__section">
            <div class="rk-cprofile__section-head">
                <h2><?php esc_html_e( 'آخر تقرير', 'rk-my-children' ); ?></h2>
                <a href="<?php echo esc_url( $rapport_url ); ?>" class="rk-cprofile__link">
                    <?php esc_html_e( 'كل التقارير', 'rk-my-children' ); ?>
                    <?php echo $rkd4_icon( 'arrow', 13 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                </a>
            </div>
            <!-- AJOUT (demande utilisateur) — conteneur dédié avec le
                 style de carte demandé (fond blanc, double ombre, radius
                 16px), enveloppe le contenu du dernier rapport comme
                 l'état vide. -->
            <div class="rk-cprofile__report-content">
            <?php if ( $latest_eval ) : ?>
            <div class="rk-cprofile__eval">
                <?php if ( ! empty( $latest_eval['coach_name'] ) ) : ?>
                <div class="rk-cprofile__coach">
                    <span class="rk-cprofile__coach-name">
                        <?php
                        /* translators: %s: nom du coach/de la coach */
                        echo esc_html( sprintf( __( 'المدرب(ة) %s', 'rk-my-children' ), $latest_eval['coach_name'] ) );
                        ?>
                    </span>
                    <?php if ( $coach_avatar_url ) : ?>
                    <img src="<?php echo esc_url( $coach_avatar_url ); ?>" alt="" class="rk-cprofile__coach-avatar" width="32" height="32" loading="lazy">
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                <?php if ( ! empty( $latest_eval['summary'] ) ) : ?>
                <p><?php echo esc_html( $latest_eval['summary'] ); ?></p>
                <?php endif; ?>
                <a href="<?php echo esc_url( $rapport_url ); ?>" class="rk-cprofile__link">
                    <?php esc_html_e( 'عرض التقرير', 'rk-my-children' ); ?>
                </a>
            </div>
            <?php else : ?>
            <p class="rk-cprofile__empty"><?php esc_html_e( 'لا توجد تقارير بعد.', 'rk-my-children' ); ?></p>
            <?php endif; ?>
            </div><!-- /.rk-cprofile__report-content -->
        </div>

    </div>

</div>
