<?php
/**
 * Child Dashboard v3 — RiadaKids Brand Design
 * Structure: sidebar (desktop) + drawer (mobile) + main grid
 * Colors: var(--e-global-color-*) Elementor vars, no alias declarations.
 *
 * v3.1 — Structure réparée (grid/col-main/col-side correctement imbriqués)
 *        + emojis remplacés par des icônes animées (Noto Animated Emoji)
 *          via le helper $rkd3_icon(), fallback texte automatique.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

/* ── Icônes cartoon SVG animées (sprite inline + SMIL, zéro dépendance) ──
 * $rkd3_icon('🔥', 26) → <svg><use href="#rk-i-fire"/></svg>
 * Emoji non mappé → fallback texte (rien ne casse jamais). */
$rkd3_icon = static function ( string $emoji, int $size = 26, string $class = '' ): string {
    static $map = [
        '⭐' => 'star',     '✨' => 'sparkle',  '🌟' => 'glowstar', '🔥' => 'fire',
        '🎖' => 'medal',    '🏅' => 'medal',    '🥇' => 'gold1',    '👋' => 'wave',
        '✉' => 'envelope', '💌' => 'envelope', '🎯' => 'target',   '⏱' => 'timer',
        '🕓' => 'clock',    '🕕' => 'clock',    '⏳' => 'hourglass','🗺' => 'map',
        '☁' => 'cloud',    '🏠' => 'house',    '🌳' => 'tree',     '🏙' => 'city',
        '🚀' => 'rocket',   '👑' => 'crown',    '⚔' => 'swords',   '🔮' => 'crystal',
        '🔒' => 'lock',     '🔓' => 'unlock',   '📊' => 'chart',    '📚' => 'books',
        '✅' => 'check',    '👩‍🏫' => 'teacher', '🎓' => 'gradcap',  '📅' => 'calendar',
        '💪' => 'muscle',   '🎤' => 'mic',      '🎨' => 'palette',  '💎' => 'diamond',
        '⚡' => 'bolt',     '🌙' => 'moon',     '🏆' => 'trophy',   '🎁' => 'gift',
        '🧭' => 'compass',  '📖' => 'bookopen', '👥' => 'users',    '🗣' => 'speak',
        '🤖' => 'robot',    '💡' => 'bulb',     '🔗' => 'link',     '▶' => 'play',
        '📍' => 'pin',      '❓' => 'question', '🗓' => 'calendar', '💬' => 'envelope',
    ];
    $key = str_replace( "\u{FE0F}", '', $emoji ); // variation selector
    if ( ! isset( $map[ $key ] ) ) {
        return '<span class="rkd3-anim" style="font-size:' . (int) $size . 'px;line-height:1;">' . esc_html( $emoji ) . '</span>';
    }
    return '<svg class="rkd3-svg ' . esc_attr( $class ) . '" width="' . (int) $size . '" height="' . (int) $size . '"'
         . ' role="img" aria-label="' . esc_attr( $emoji ) . '" focusable="false">'
         . '<use href="#rk-i-' . $map[ $key ] . '"></use></svg>';
};

/* ── Data ─────────────────────────────────────────────────────────── */
$view_data    = $view_data ?? [];
$child        = rk_mc_array_get( $view_data, 'child',       [] );
$journey      = rk_mc_array_get( $view_data, 'journey',     [] );
$session      = rk_mc_array_get( $view_data, 'session',     [] );
$adventures   = rk_mc_array_get( $view_data, 'adventures',  [] );
$missions     = rk_mc_array_get( $view_data, 'missions',    [] );
$powers       = rk_mc_array_get( $view_data, 'powers',      [] );
$badges       = rk_mc_array_get( $view_data, 'badges',      [] );
$welcome      = rk_mc_array_get( $view_data, 'welcome',     [] );
$assessment   = rk_mc_array_get( $view_data, 'assessment',  [] );
$messages     = rk_mc_array_get( $view_data, 'messages',    [] );
$quizzes      = rk_mc_array_get( $view_data, 'quizzes',     [] );
$certificates = rk_mc_array_get( $view_data, 'certificates', [] );
$calendar     = rk_mc_array_get( $view_data, 'calendar',    [] );
$stats        = rk_mc_array_get( $view_data, 'stats',       [] );

$has_session    = (int)( $session['timestamp'] ?? 0 ) > 0;
$has_adventures = ! empty( $adventures );
$has_missions   = ! empty( $missions );
$has_badges     = ! empty( $badges );
$bilan_latest   = $assessment['latest']    ?? null;
$coach_msgs     = $messages['coach_msgs']  ?? [];
$coach_id       = (int)( $messages['coach_id']    ?? 0 );
$coach_name     = $messages['coach_name']  ?? '';
$coach_unread   = (int)( $messages['coach_unread'] ?? 0 );
$admin_unread   = (int)( $messages['admin_unread'] ?? 0 );
$total_unread   = $coach_unread + $admin_unread;
$msg_nonce      = wp_create_nonce( 'rk_mc_messages' );

$img_ilu = RK_MC_URL . 'assets/img/illustrations/';

$child_xp    = intval( $child['points']   ?? $journey['current_xp']   ?? 0 );
$child_level = intval( $child['level']    ?? 1 );
$child_name  = $child['name'] ?? '';
$child_tier  = $child['title'] ?? $journey['current_tier'] ?? 'مستكشف صغير';
$ring_pct    = (int)( $journey['progress_pct'] ?? $child['level_progress'] ?? 0 );
$streak_val  = intval( $child['streak'] ?? 0 );
$earned_count  = $has_badges ? count( array_filter( $badges, static fn($b) => ! empty($b['earned']) ) ) : 0;
$badge_total   = (int)( $journey['badge_total'] ?? ( $has_badges ? count( $badges ) : 0 ) );

$rk_next_url   = $child['next_course_url']   ?? '';
$rk_next_title = $child['next_course_title'] ?? '';
$current_lesson = $child['current_lesson'] ?? null;
$mission_title  = $current_lesson['title'] ?? ( $rk_next_title ?: __( 'واصل رحلتك اليوم', 'rk-my-children' ) );
$mission_url    = $current_lesson['url']   ?? $rk_next_url;
$goal           = $child['goal'] ?? [];

$child_id_for_msg = (int)( $_GET['child_id'] ?? 0 ); // phpcs:ignore
$_raw_tab     = isset( $_GET['rk_tab'] ) ? sanitize_text_field( wp_unslash( $_GET['rk_tab'] ) ) : ''; // phpcs:ignore
$_msg_param   = preg_match( '/^[a-f0-9]{40}$/', $_raw_tab )
    ? '?rk_tab=' . $_raw_tab
    : ( $child_id_for_msg ? '?child_id=' . $child_id_for_msg : '' );
$msg_page_url = home_url( '/dashboard/rk-messages/' . $_msg_param );
$notif_unread = class_exists( 'RK_MC_Notification_Service' )
    ? RK_MC_Notification_Service::get_unread_count( get_current_user_id() )
    : 0;
$notif_nonce = wp_create_nonce( 'rk_mc_notifs' );

$_nb  = home_url( '/dashboard/' );
$_cid = $child_id_for_msg ?: (int)( $child['id'] ?? 0 );
$_cp  = preg_match( '/^[a-f0-9]{40}$/', $_raw_tab )
    ? '?rk_tab=' . $_raw_tab
    : ( $_cid ? '?child_id=' . $_cid : '' );

$_nav = [
    [ 'key' => 'index',    'label' => 'الرئيسية',        'emoji' => '🏠', 'url' => $_nb . $_cp ],
    [ 'key' => 'enrolled', 'label' => 'مغامراتي',          'emoji' => '🗺️', 'url' => $_nb . 'enrolled-courses/' . $_cp ],
    [ 'key' => 'badges',   'label' => 'التحديات',         'emoji' => '🎯', 'url' => $_nb . 'rk-badges/' . $_cp ],
    [ 'key' => 'calendar', 'label' => 'لقاءات',          'emoji' => '📅', 'url' => $_nb . 'rk-sessions/' . $_cp ],
    [ 'key' => 'messages', 'label' => 'الرسائل',          'emoji' => '💬', 'url' => $msg_page_url ],
    [ 'key' => 'rewards',  'label' => 'الجوائز',          'emoji' => '🏆', 'url' => $_nb . 'rk-badges/' . $_cp ],
    [ 'key' => 'qa',       'label' => 'سؤال وجواب',      'emoji' => '❓', 'url' => $_nb . 'question-answer/' . $_cp ],
    [ 'key' => 'profile',  'label' => 'الملف الشخصي',    'emoji' => '👤', 'url' => $_nb . 'settings/' . $_cp ],
];

$_mobnav = [
    [ 'key' => 'index',    'emoji' => '🏠', 'url' => $_nb . $_cp ],
    [ 'key' => 'enrolled', 'emoji' => '🗺️', 'url' => $_nb . 'enrolled-courses/' . $_cp ],
    [ 'key' => 'badges',   'emoji' => '🎯', 'url' => $_nb . 'rk-badges/' . $_cp ],
    [ 'key' => 'calendar', 'emoji' => '📅', 'url' => $_nb . 'rk-sessions/' . $_cp ],
    [ 'key' => 'profile',  'emoji' => '👤', 'url' => $_nb . 'settings/' . $_cp ],
];
$_current_key = 'index';

$island_icons    = [ '🏠','🌳','🏙️','🚀','👑','⚔️','🌟','🔮' ];
$challenge_items = array_slice( $missions, 0, 6 );
$creation_colors = [
    'var(--e-global-color-secondary,#4C95D7)',
    '#7C3AED',
    'var(--e-global-color-primary,#FF4411)',
    'var(--e-global-color-6e8f9c9,#79D8A0)',
    '#EF4444',
];
$creation_icons  = [ '🚀','🎨','🎬','🖼️','🏪','🏆' ];
?>
<script>document.body.classList.add('rk-child-game-page','rkd3-home-page');</script>

<style>
/* Icônes SVG animées — alignement inline propre */
.rkd3-svg,.rkd3-anim{display:inline-block;vertical-align:-0.22em;}
.rkd3-card__head-emoji .rkd3-svg{vertical-align:middle;}
.rkd3-hero__star .rkd3-svg{width:22px;height:22px;}
@media (prefers-reduced-motion:reduce){.rkd3-svg *{animation:none!important}}
</style>

<div id="rkd3" dir="rtl">

<!-- ═══ Sprite SVG cartoon animé (SMIL) — référencé par $rkd3_icon() ═══ -->
<svg xmlns="http://www.w3.org/2000/svg" style="position:absolute;width:0;height:0;overflow:hidden" aria-hidden="true">
<symbol id="rk-i-star" viewBox="0 0 64 64"><g><path d="M32 8l6.5 14.2 15.5 1.8-11.5 10.6 3.1 15.3L32 42.4 18.4 49.9l3.1-15.3L10 24l15.5-1.8z" fill="#FFC93C" stroke="#F5A623" stroke-width="2.5" stroke-linejoin="round"/><animateTransform attributeName="transform" type="rotate" values="-8 32 32;8 32 32;-8 32 32" dur="2s" repeatCount="indefinite"/></g></symbol>
<symbol id="rk-i-sparkle" viewBox="0 0 64 64"><g><path d="M32 10l4 14 14 4-14 4-4 14-4-14-14-4 14-4z" fill="#FFC93C"/><animate attributeName="opacity" values="1;0.35;1" dur="1.4s" repeatCount="indefinite"/></g><g><path d="M50 12l2 6 6 2-6 2-2 6-2-6-6-2 6-2z" fill="#4C95D7"/><animate attributeName="opacity" values="1;0.2;1" dur="1.9s" repeatCount="indefinite"/></g><g><path d="M14 44l1.6 5 5 1.6-5 1.6-1.6 5-1.6-5-5-1.6 5-1.6z" fill="#FF7A9E"/><animate attributeName="opacity" values="1;0.25;1" dur="1.1s" repeatCount="indefinite"/></g></symbol>
<symbol id="rk-i-glowstar" viewBox="0 0 64 64"><g><animateTransform attributeName="transform" type="rotate" from="0 32 32" to="360 32 32" dur="9s" repeatCount="indefinite"/><g stroke="#F5A623" stroke-width="3" stroke-linecap="round"><line x1="32" y1="4" x2="32" y2="12" transform="rotate(0 32 32)"/><line x1="32" y1="4" x2="32" y2="12" transform="rotate(45 32 32)"/><line x1="32" y1="4" x2="32" y2="12" transform="rotate(90 32 32)"/><line x1="32" y1="4" x2="32" y2="12" transform="rotate(135 32 32)"/><line x1="32" y1="4" x2="32" y2="12" transform="rotate(180 32 32)"/><line x1="32" y1="4" x2="32" y2="12" transform="rotate(225 32 32)"/><line x1="32" y1="4" x2="32" y2="12" transform="rotate(270 32 32)"/><line x1="32" y1="4" x2="32" y2="12" transform="rotate(315 32 32)"/></g></g><g><path d="M32 8l6.5 14.2 15.5 1.8-11.5 10.6 3.1 15.3L32 42.4 18.4 49.9l3.1-15.3L10 24l15.5-1.8z" fill="#FFC93C" stroke="#F5A623" stroke-width="2"/><animateTransform attributeName="transform" type="rotate" values="-5 32 32;5 32 32;-5 32 32" dur="2.2s" repeatCount="indefinite"/></g></symbol>
<symbol id="rk-i-fire" viewBox="0 0 64 64"><g><path d="M32 6c2 10 12 13 12 26a12 14 0 0 1-24 0c0-7 3-10 6-14 0 5 2 7 4 8-1-8 0-14 2-20z" fill="#FF4411"/><path d="M32 30c4 4 6 6 6 11a6 7 0 0 1-12 0c0-5 3-7 6-11z" fill="#FFC93C"><animate attributeName="d" values="M32 30c4 4 6 6 6 11a6 7 0 0 1-12 0c0-5 3-7 6-11z;M32 26c5 5 7 8 7 15a7 8 0 0 1-14 0c0-7 3-10 7-15z;M32 30c4 4 6 6 6 11a6 7 0 0 1-12 0c0-5 3-7 6-11z" dur="0.9s" repeatCount="indefinite"/></path><animateTransform attributeName="transform" type="rotate" values="-3 32 32;3 32 32;-3 32 32" dur="1.3s" repeatCount="indefinite"/></g></symbol>
<symbol id="rk-i-medal" viewBox="0 0 64 64"><g><path d="M24 6h6l4 14h-8z" fill="#4C95D7"/><path d="M40 6h-6l-4 14h8z" fill="#EF4444"/><circle cx="32" cy="38" r="16" fill="#FFC93C" stroke="#F5A623" stroke-width="3"/><path d="M32 28l3 6.5 7 .8-5.2 4.8 1.4 7L32 43.6 25.8 47l1.4-7L22 35.3l7-.8z" fill="#F5A623"/><animateTransform attributeName="transform" type="rotate" values="-6 32 32;6 32 32;-6 32 32" dur="2.6s" repeatCount="indefinite"/></g></symbol>
<symbol id="rk-i-wave" viewBox="0 0 64 64"><g><path d="M22 32c-2-8-3-14-1-15s4 1 5 6l2 8-1-16c0-3 5-3 5 0l1 14 2-14c.5-3 5-2.5 5 0l-1 15 4-11c1-2.7 5-1.5 4 1l-5 16c-2 7-6 11-12 11-7 0-9-6-8-15z" fill="#FFD8B4" stroke="#B4746B" stroke-width="2" stroke-linejoin="round"/><animateTransform attributeName="transform" type="rotate" values="-14 32 52;14 32 52;-14 32 52" dur="1.1s" repeatCount="indefinite"/></g></symbol>
<symbol id="rk-i-envelope" viewBox="0 0 64 64"><g><rect x="8" y="16" width="48" height="34" rx="6" fill="#4C95D7"/><path d="M10 20l22 17 22-17" fill="none" stroke="#fff" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round"/><g><circle cx="50" cy="18" r="7" fill="#FF4411"/><path d="M47 18l2.5 2.5L54 15" stroke="#fff" stroke-width="2.4" fill="none" stroke-linecap="round"/><animate attributeName="opacity" values="1;0.6;1" dur="2s" repeatCount="indefinite"/></g><animateTransform attributeName="transform" type="translate" values="0 0;0 -2.5;0 0" dur="2.4s" repeatCount="indefinite"/></g></symbol>
<symbol id="rk-i-target" viewBox="0 0 64 64"><g><circle cx="32" cy="32" r="22" fill="#EF4444"/><circle cx="32" cy="32" r="15" fill="#fff"/><circle cx="32" cy="32" r="8" fill="#EF4444"/><circle cx="32" cy="32" r="3" fill="#fff"/><g><path d="M48 16l6-6m-6 6l1 6m-1-6l-6-1" stroke="#0D1F35" stroke-width="3" stroke-linecap="round"/><animate attributeName="opacity" values="1;0.5;1" dur="1.8s" repeatCount="indefinite"/></g><animateTransform attributeName="transform" type="rotate" values="-4 32 32;4 32 32;-4 32 32" dur="2.8s" repeatCount="indefinite"/></g></symbol>
<symbol id="rk-i-timer" viewBox="0 0 64 64"><path d="M26 6h12v5H26z" fill="#4C95D7"/><line x1="46" y1="14" x2="50" y2="18" stroke="#4C95D7" stroke-width="4" stroke-linecap="round"/><circle cx="32" cy="34" r="20" fill="#fff" stroke="#4C95D7" stroke-width="4"/><g stroke="#CBD5E1" stroke-width="2" stroke-linecap="round"><line x1="32" y1="17" x2="32" y2="21"/><line x1="32" y1="47" x2="32" y2="51"/><line x1="15" y1="34" x2="19" y2="34"/><line x1="45" y1="34" x2="49" y2="34"/></g><g><line x1="32" y1="34" x2="32" y2="22" stroke="#0D1F35" stroke-width="3" stroke-linecap="round"><animateTransform attributeName="transform" type="rotate" from="0 32 34" to="360 32 34" dur="3s" repeatCount="indefinite"/></line></g><line x1="32" y1="34" x2="41" y2="34" stroke="#FF4411" stroke-width="3" stroke-linecap="round"/><circle cx="32" cy="34" r="2.5" fill="#0D1F35"/></symbol>
<symbol id="rk-i-clock" viewBox="0 0 64 64"><circle cx="32" cy="34" r="20" fill="#fff" stroke="#4C95D7" stroke-width="4"/><g stroke="#CBD5E1" stroke-width="2" stroke-linecap="round"><line x1="32" y1="17" x2="32" y2="21"/><line x1="32" y1="47" x2="32" y2="51"/><line x1="15" y1="34" x2="19" y2="34"/><line x1="45" y1="34" x2="49" y2="34"/></g><g><line x1="32" y1="34" x2="32" y2="22" stroke="#0D1F35" stroke-width="3" stroke-linecap="round"><animateTransform attributeName="transform" type="rotate" from="0 32 34" to="360 32 34" dur="5s" repeatCount="indefinite"/></line></g><line x1="32" y1="34" x2="41" y2="34" stroke="#FF4411" stroke-width="3" stroke-linecap="round"/><circle cx="32" cy="34" r="2.5" fill="#0D1F35"/></symbol>
<symbol id="rk-i-hourglass" viewBox="0 0 64 64"><g><path d="M20 8h24v6c0 8-8 10-8 18s8 10 8 18v6H20v-6c0-8 8-10 8-18s-8-10-8-18z" fill="none" stroke="#4C95D7" stroke-width="3.5" stroke-linejoin="round"/><path d="M25 12h14c0 7-7 9-7 14-0-5-7-7-7-14z" fill="#FFC93C"/><path d="M32 40c2 3 7 5 7 12H25c0-7 5-9 7-12z" fill="#FFC93C"/><animateTransform attributeName="transform" type="rotate" values="0 32 32;0 32 32;180 32 32;180 32 32;360 32 32" keyTimes="0;0.4;0.5;0.9;1" dur="4s" repeatCount="indefinite"/></g></symbol>
<symbol id="rk-i-map" viewBox="0 0 64 64"><g><path d="M8 16l16-6 16 6 16-6v38l-16 6-16-6-16 6z" fill="#79D8A0" stroke="#4CAF77" stroke-width="2.5" stroke-linejoin="round"/><path d="M24 10v38M40 16v38" stroke="#4CAF77" stroke-width="2.5"/><path d="M14 34c6-6 12 4 18-2s10 2 16-4" fill="none" stroke="#FF4411" stroke-width="3" stroke-dasharray="4 4" stroke-linecap="round"><animate attributeName="stroke-dashoffset" from="16" to="0" dur="1.2s" repeatCount="indefinite"/></path><circle cx="48" cy="24" r="4" fill="#EF4444"/><animateTransform attributeName="transform" type="rotate" values="-3 32 32;3 32 32;-3 32 32" dur="3s" repeatCount="indefinite"/></g></symbol>
<symbol id="rk-i-cloud" viewBox="0 0 64 64"><g><path d="M20 44a10 10 0 0 1 2-19.8A14 14 0 0 1 49 27a9 9 0 0 1-2 17z" fill="#EAF3FC" stroke="#4C95D7" stroke-width="3"/><animateTransform attributeName="transform" type="translate" values="-3 0;3 0;-3 0" dur="4s" repeatCount="indefinite"/></g></symbol>
<symbol id="rk-i-house" viewBox="0 0 64 64"><g><path d="M10 32L32 12l22 20" fill="none" stroke="#FF4411" stroke-width="5" stroke-linecap="round" stroke-linejoin="round"/><path d="M16 30v22h32V30" fill="#FFC93C" stroke="#F5A623" stroke-width="3" stroke-linejoin="round"/><rect x="27" y="38" width="10" height="14" rx="2" fill="#4C95D7"/><animateTransform attributeName="transform" type="translate" values="0 0;0 -2;0 0" dur="2.4s" repeatCount="indefinite"/></g></symbol>
<symbol id="rk-i-tree" viewBox="0 0 64 64"><g><rect x="29" y="40" width="6" height="14" rx="2" fill="#B4746B"/><circle cx="32" cy="26" r="15" fill="#79D8A0"/><circle cx="21" cy="33" r="9" fill="#5EC98B"/><circle cx="43" cy="33" r="9" fill="#5EC98B"/><animateTransform attributeName="transform" type="rotate" values="-4 32 32;4 32 32;-4 32 32" dur="3.2s" repeatCount="indefinite"/></g></symbol>
<symbol id="rk-i-city" viewBox="0 0 64 64"><g><rect x="10" y="26" width="14" height="28" rx="2" fill="#4C95D7"/><rect x="27" y="14" width="15" height="40" rx="2" fill="#8B5CF6"/><rect x="45" y="32" width="11" height="22" rx="2" fill="#FF4411"/><g fill="#FFC93C"><rect x="13" y="30" width="3" height="3"/><rect x="19" y="30" width="3" height="3"/><rect x="31" y="19" width="3" height="3"/><rect x="37" y="19" width="3" height="3"/><rect x="31" y="27" width="3" height="3"/><rect x="48" y="36" width="3" height="3"/><animate attributeName="opacity" values="1;0.3;1" dur="2.2s" repeatCount="indefinite"/></g></g></symbol>
<symbol id="rk-i-rocket" viewBox="0 0 64 64"><g><path d="M32 6c8 6 10 16 10 24l-4 8H26l-4-8c0-8 2-18 10-24z" fill="#fff" stroke="#4C95D7" stroke-width="3" stroke-linejoin="round"/><circle cx="32" cy="24" r="5" fill="#4C95D7"/><path d="M22 30l-8 10 10-2zM42 30l8 10-10-2z" fill="#EF4444"/><g><path d="M28 40c0 6 1 9 4 13 3-4 4-7 4-13z" fill="#FF4411"><animate attributeName="d" values="M28 40c0 6 1 9 4 13 3-4 4-7 4-13z;M28 40c0 8 1 12 4 17 3-5 4-9 4-17z;M28 40c0 6 1 9 4 13 3-4 4-7 4-13z" dur="0.5s" repeatCount="indefinite"/></path></g><animateTransform attributeName="transform" type="translate" values="0 0;0 -4;0 0" dur="2s" repeatCount="indefinite"/></g></symbol>
<symbol id="rk-i-crown" viewBox="0 0 64 64"><g><path d="M12 44l-3-24 13 10 10-16 10 16 13-10-3 24z" fill="#FFC93C" stroke="#F5A623" stroke-width="3" stroke-linejoin="round"/><rect x="12" y="44" width="40" height="8" rx="3" fill="#F5A623"/><g><circle cx="22" cy="38" r="3" fill="#EF4444"/><circle cx="32" cy="36" r="3" fill="#4C95D7"/><circle cx="42" cy="38" r="3" fill="#79D8A0"/><animate attributeName="opacity" values="1;0.45;1" dur="1.8s" repeatCount="indefinite"/></g><animateTransform attributeName="transform" type="rotate" values="-4 32 32;4 32 32;-4 32 32" dur="2.6s" repeatCount="indefinite"/></g></symbol>
<symbol id="rk-i-swords" viewBox="0 0 64 64"><g><g transform="rotate(45 32 32)"><rect x="29.5" y="8" width="5" height="34" rx="2.5" fill="#CBD5E1"/><rect x="24" y="42" width="16" height="5" rx="2.5" fill="#F5A623"/><rect x="29.5" y="47" width="5" height="9" rx="2.5" fill="#B4746B"/></g><g transform="rotate(-45 32 32)"><rect x="29.5" y="8" width="5" height="34" rx="2.5" fill="#4C95D7"/><rect x="24" y="42" width="16" height="5" rx="2.5" fill="#F5A623"/><rect x="29.5" y="47" width="5" height="9" rx="2.5" fill="#B4746B"/></g><animateTransform attributeName="transform" type="rotate" values="-5 32 32;5 32 32;-5 32 32" dur="2.4s" repeatCount="indefinite"/></g></symbol>
<symbol id="rk-i-crystal" viewBox="0 0 64 64"><g><circle cx="32" cy="30" r="18" fill="#8B5CF6" opacity="0.85"/><ellipse cx="26" cy="24" rx="6" ry="4" fill="#C4B5FD" transform="rotate(-25 26 24)"/><path d="M20 50h24l3 7H17z" fill="#F5A623"/><g><path d="M32 22l2.5 6 6 2.5-6 2.5-2.5 6-2.5-6-6-2.5 6-2.5z" fill="#fff"/><animate attributeName="opacity" values="1;0.15;1" dur="1.5s" repeatCount="indefinite"/></g><animateTransform attributeName="transform" type="translate" values="0 0;0 -2.5;0 0" dur="2.4s" repeatCount="indefinite"/></g></symbol>
<symbol id="rk-i-lock" viewBox="0 0 64 64"><g><rect x="16" y="28" width="32" height="26" rx="6" fill="#F5A623"/><path d="M22 28v-6a10 10 0 0 1 20 0v6" fill="none" stroke="#0D1F35" stroke-width="4.5" stroke-linecap="round"/><circle cx="32" cy="40" r="4" fill="#0D1F35"/><rect x="30" y="42" width="4" height="7" rx="2" fill="#0D1F35"/><animateTransform attributeName="transform" type="rotate" values="-3 32 32;3 32 32;-3 32 32" dur="2.5s" repeatCount="indefinite"/></g></symbol>
<symbol id="rk-i-unlock" viewBox="0 0 64 64"><g><rect x="16" y="28" width="32" height="26" rx="6" fill="#79D8A0"/><path d="M22 28v-8a10 10 0 0 1 19.5-3" fill="none" stroke="#0D1F35" stroke-width="4.5" stroke-linecap="round"/><circle cx="32" cy="40" r="4" fill="#0D1F35"/><rect x="30" y="42" width="4" height="7" rx="2" fill="#0D1F35"/><animateTransform attributeName="transform" type="rotate" values="-4 32 32;4 32 32;-4 32 32" dur="2.2s" repeatCount="indefinite"/></g></symbol>
<symbol id="rk-i-chart" viewBox="0 0 64 64"><g><rect x="8" y="8" width="48" height="46" rx="8" fill="#EAF3FC"/><g><rect x="16" y="34" width="8" height="14" rx="2" fill="#4C95D7"><animate attributeName="height" values="8;14;8" dur="2s" repeatCount="indefinite"/><animate attributeName="y" values="40;34;40" dur="2s" repeatCount="indefinite"/></rect><rect x="28" y="26" width="8" height="22" rx="2" fill="#FF4411"><animate attributeName="height" values="16;22;16" dur="2s" begin="0.3s" repeatCount="indefinite"/><animate attributeName="y" values="32;26;32" dur="2s" begin="0.3s" repeatCount="indefinite"/></rect><rect x="40" y="18" width="8" height="30" rx="2" fill="#79D8A0"><animate attributeName="height" values="22;30;22" dur="2s" begin="0.6s" repeatCount="indefinite"/><animate attributeName="y" values="26;18;26" dur="2s" begin="0.6s" repeatCount="indefinite"/></rect></g></g></symbol>
<symbol id="rk-i-books" viewBox="0 0 64 64"><g><g transform="rotate(-6 20 44)"><rect x="10" y="20" width="13" height="34" rx="2" fill="#EF4444"/></g><rect x="26" y="14" width="13" height="40" rx="2" fill="#4C95D7"/><g transform="rotate(6 46 44)"><rect x="42" y="22" width="13" height="32" rx="2" fill="#79D8A0"/></g><g fill="#fff" opacity="0.7"><rect x="15" y="26" width="4" height="8" rx="1" transform="rotate(-6 20 44)"/><rect x="30" y="20" width="4" height="8" rx="1"/></g><animateTransform attributeName="transform" type="translate" values="0 0;0 -2;0 0" dur="2.4s" repeatCount="indefinite"/></g></symbol>
<symbol id="rk-i-check" viewBox="0 0 64 64"><g><circle cx="32" cy="32" r="24" fill="#79D8A0"/><path d="M20 33l8 8 16-17" fill="none" stroke="#fff" stroke-width="6" stroke-linecap="round" stroke-linejoin="round" stroke-dasharray="40" stroke-dashoffset="40"><animate attributeName="stroke-dashoffset" values="40;0;0;40" keyTimes="0;0.3;0.85;1" dur="3s" repeatCount="indefinite"/></path><animateTransform attributeName="transform" type="rotate" values="-3 32 32;3 32 32;-3 32 32" dur="3s" repeatCount="indefinite"/></g></symbol>
<symbol id="rk-i-teacher" viewBox="0 0 64 64"><g><circle cx="32" cy="24" r="12" fill="#FFD8B4"/><path d="M20 22c0-9 6-13 12-13s12 4 12 13c-3-4-7-5-12-5s-9 1-12 5z" fill="#B4746B"/><circle cx="27" cy="24" r="1.8" fill="#0D1F35"/><circle cx="37" cy="24" r="1.8" fill="#0D1F35"/><path d="M28 30q4 3 8 0" stroke="#0D1F35" stroke-width="2" fill="none" stroke-linecap="round"/><path d="M14 54c2-10 9-14 18-14s16 4 18 14z" fill="#4C95D7"/><animateTransform attributeName="transform" type="translate" values="0 0;0 -2;0 0" dur="2.4s" repeatCount="indefinite"/></g></symbol>
<symbol id="rk-i-gradcap" viewBox="0 0 64 64"><g><path d="M6 24L32 12l26 12-26 12z" fill="#0D1F35"/><path d="M18 30v10c0 4 6 8 14 8s14-4 14-8V30l-14 7z" fill="#4C95D7"/><g><line x1="54" y1="26" x2="54" y2="40" stroke="#F5A623" stroke-width="3" stroke-linecap="round"/><circle cx="54" cy="43" r="3.5" fill="#F5A623"/><animateTransform attributeName="transform" type="rotate" values="-10 54 26;10 54 26;-10 54 26" dur="2s" repeatCount="indefinite"/></g><animateTransform attributeName="transform" type="rotate" values="-3 32 32;3 32 32;-3 32 32" dur="3s" repeatCount="indefinite"/></g></symbol>
<symbol id="rk-i-calendar" viewBox="0 0 64 64"><g><rect x="10" y="14" width="44" height="40" rx="8" fill="#fff" stroke="#4C95D7" stroke-width="3.5"/><path d="M10 26h44v-4a8 8 0 0 0-8-8H18a8 8 0 0 0-8 8z" fill="#EF4444"/><g><line x1="22" y1="10" x2="22" y2="18" stroke="#0D1F35" stroke-width="4" stroke-linecap="round"/><line x1="42" y1="10" x2="42" y2="18" stroke="#0D1F35" stroke-width="4" stroke-linecap="round"/></g><g fill="#CBD5E1"><rect x="18" y="32" width="7" height="6" rx="1.5"/><rect x="28.5" y="32" width="7" height="6" rx="1.5"/><rect x="39" y="32" width="7" height="6" rx="1.5"/><rect x="18" y="42" width="7" height="6" rx="1.5"/><rect x="28.5" y="42" width="7" height="6" rx="1.5"/></g><g><rect x="39" y="42" width="7" height="6" rx="1.5" fill="#FF4411"/><animate attributeName="opacity" values="1;0.4;1" dur="1.6s" repeatCount="indefinite"/></g><animateTransform attributeName="transform" type="translate" values="0 0;0 -2;0 0" dur="2.4s" repeatCount="indefinite"/></g></symbol>
<symbol id="rk-i-muscle" viewBox="0 0 64 64"><g><path d="M18 46c-4-10-4-22 0-30 3-6 11-6 13 0l3 10c8-2 16 0 19 6 4 8-1 16-9 18-10 3-22 2-26-4z" fill="#FFD8B4" stroke="#B4746B" stroke-width="2.5" stroke-linejoin="round"/><path d="M34 30c6-2 12 0 14 4" fill="none" stroke="#B4746B" stroke-width="2.5" stroke-linecap="round"/><animateTransform attributeName="transform" type="rotate" values="0 20 40;-8 20 40;0 20 40" dur="1.4s" repeatCount="indefinite"/></g></symbol>
<symbol id="rk-i-mic" viewBox="0 0 64 64"><g><rect x="25" y="8" width="14" height="26" rx="7" fill="#8B5CF6"/><path d="M18 28a14 14 0 0 0 28 0" fill="none" stroke="#0D1F35" stroke-width="3.5" stroke-linecap="round"/><line x1="32" y1="42" x2="32" y2="52" stroke="#0D1F35" stroke-width="3.5" stroke-linecap="round"/><line x1="24" y1="52" x2="40" y2="52" stroke="#0D1F35" stroke-width="3.5" stroke-linecap="round"/><g stroke="#FF4411" stroke-width="2.5" stroke-linecap="round" fill="none"><path d="M14 18q-3 4 0 8M50 18q3 4 0 8"/><animate attributeName="opacity" values="1;0.2;1" dur="1.3s" repeatCount="indefinite"/></g><animateTransform attributeName="transform" type="rotate" values="-4 32 32;4 32 32;-4 32 32" dur="2.4s" repeatCount="indefinite"/></g></symbol>
<symbol id="rk-i-palette" viewBox="0 0 64 64"><g><path d="M32 8C18 8 8 18 8 30s10 22 22 22c4 0 5-3 4-6-1-4 1-7 5-7h7c6 0 10-4 10-9C56 17 45 8 32 8z" fill="#FDE68A" stroke="#F5A623" stroke-width="3"/><g><circle cx="20" cy="24" r="4" fill="#EF4444"/><circle cx="32" cy="18" r="4" fill="#4C95D7"/><circle cx="44" cy="24" r="4" fill="#79D8A0"/><circle cx="18" cy="36" r="4" fill="#8B5CF6"/><animate attributeName="opacity" values="1;0.55;1" dur="2s" repeatCount="indefinite"/></g><animateTransform attributeName="transform" type="rotate" values="-4 32 32;4 32 32;-4 32 32" dur="3s" repeatCount="indefinite"/></g></symbol>
<symbol id="rk-i-diamond" viewBox="0 0 64 64"><g><path d="M18 12h28l10 14-24 28L8 26z" fill="#7DD3FC" stroke="#4C95D7" stroke-width="3" stroke-linejoin="round"/><path d="M8 26h48M18 12l6 14 8-14 8 14 6-14M24 26l8 28 8-28" fill="none" stroke="#4C95D7" stroke-width="2"/><g><path d="M24 18l1.5 4 4 1.5-4 1.5-1.5 4-1.5-4-4-1.5 4-1.5z" fill="#fff"/><animate attributeName="opacity" values="1;0.1;1" dur="1.4s" repeatCount="indefinite"/></g><animateTransform attributeName="transform" type="translate" values="0 0;0 -2.5;0 0" dur="2.4s" repeatCount="indefinite"/></g></symbol>
<symbol id="rk-i-bolt" viewBox="0 0 64 64"><g><path d="M36 6L14 36h13l-3 22 22-30H33z" fill="#FFC93C" stroke="#F5A623" stroke-width="3" stroke-linejoin="round"/><animate attributeName="opacity" values="1;0.5;1" dur="1s" repeatCount="indefinite"/><animateTransform attributeName="transform" type="rotate" values="-3 32 32;3 32 32;-3 32 32" dur="1.8s" repeatCount="indefinite"/></g></symbol>
<symbol id="rk-i-moon" viewBox="0 0 64 64"><g><path d="M42 10a22 22 0 1 0 12 28A18 18 0 0 1 42 10z" fill="#FFC93C" stroke="#F5A623" stroke-width="3"/><circle cx="26" cy="26" r="1.8" fill="#0D1F35"/><circle cx="34" cy="30" r="1.8" fill="#0D1F35"/><path d="M27 36q3 2.5 6 0" stroke="#0D1F35" stroke-width="2" fill="none" stroke-linecap="round"/><animateTransform attributeName="transform" type="rotate" values="-6 32 32;6 32 32;-6 32 32" dur="3.4s" repeatCount="indefinite"/></g></symbol>
<symbol id="rk-i-trophy" viewBox="0 0 64 64"><g><path d="M20 10h24v14a12 12 0 0 1-24 0z" fill="#FFC93C" stroke="#F5A623" stroke-width="3"/><path d="M20 14h-8a8 8 0 0 0 9 10M44 14h8a8 8 0 0 1-9 10" fill="none" stroke="#F5A623" stroke-width="3.5"/><rect x="28" y="36" width="8" height="8" fill="#F5A623"/><rect x="20" y="44" width="24" height="8" rx="3" fill="#B4746B"/><g><path d="M28 16l1.5 3.5 3.5 1.5-3.5 1.5-1.5 3.5-1.5-3.5-3.5-1.5 3.5-1.5z" fill="#fff"/><animate attributeName="opacity" values="1;0.15;1" dur="1.6s" repeatCount="indefinite"/></g><animateTransform attributeName="transform" type="rotate" values="-4 32 32;4 32 32;-4 32 32" dur="2.8s" repeatCount="indefinite"/></g></symbol>
<symbol id="rk-i-gift" viewBox="0 0 64 64"><g><rect x="12" y="26" width="40" height="28" rx="4" fill="#EF4444"/><rect x="10" y="18" width="44" height="10" rx="3" fill="#FF7A9E"/><rect x="28" y="18" width="8" height="36" fill="#FFC93C"/><path d="M32 18c-8 0-12-4-10-8s9-2 10 6c1-8 8-10 10-6s-2 8-10 8z" fill="none" stroke="#FFC93C" stroke-width="3.5"/><animateTransform attributeName="transform" type="translate" values="0 0;0 -3;0 0;0 -1.5;0 0" dur="2.2s" repeatCount="indefinite"/></g></symbol>
<symbol id="rk-i-gold1" viewBox="0 0 64 64"><g><path d="M26 6h5l3 12h-7z" fill="#4C95D7"/><path d="M38 6h-5l-3 12h7z" fill="#EF4444"/><circle cx="32" cy="36" r="17" fill="#FFC93C" stroke="#F5A623" stroke-width="3.5"/><text x="32" y="43" font-family="Arial" font-weight="bold" font-size="20" fill="#F5A623" text-anchor="middle">1</text><animateTransform attributeName="transform" type="rotate" values="-6 32 32;6 32 32;-6 32 32" dur="2.4s" repeatCount="indefinite"/></g></symbol>
<symbol id="rk-i-compass" viewBox="0 0 64 64"><g><circle cx="32" cy="32" r="23" fill="#fff" stroke="#0D1F35" stroke-width="4"/><g><path d="M32 14l6 18-6 18-6-18z" fill="#EF4444"/><path d="M32 14l6 18h-12z" fill="#EF4444"/><path d="M32 50l-6-18h12z" fill="#CBD5E1"/><animateTransform attributeName="transform" type="rotate" values="0 32 32;25 32 32;-15 32 32;0 32 32" dur="3.4s" repeatCount="indefinite"/></g><circle cx="32" cy="32" r="3" fill="#0D1F35"/></g></symbol>
<symbol id="rk-i-bookopen" viewBox="0 0 64 64"><g><path d="M32 16C26 11 16 10 8 12v36c8-2 18-1 24 4 6-5 16-6 24-4V12c-8-2-18-1-24 4z" fill="#fff" stroke="#4C95D7" stroke-width="3.5" stroke-linejoin="round"/><line x1="32" y1="16" x2="32" y2="52" stroke="#4C95D7" stroke-width="3"/><g stroke="#CBD5E1" stroke-width="2.5" stroke-linecap="round"><line x1="14" y1="22" x2="26" y2="20"/><line x1="14" y1="29" x2="26" y2="27"/><line x1="38" y1="20" x2="50" y2="22"/><line x1="38" y1="27" x2="50" y2="29"/></g><animateTransform attributeName="transform" type="translate" values="0 0;0 -2;0 0" dur="2.4s" repeatCount="indefinite"/></g></symbol>
<symbol id="rk-i-users" viewBox="0 0 64 64"><g><circle cx="24" cy="24" r="9" fill="#FFD8B4"/><path d="M10 50c1-9 7-13 14-13s13 4 14 13z" fill="#4C95D7"/><g><circle cx="43" cy="26" r="8" fill="#FFD8B4"/><path d="M32 50c1-8 5-11 11-11s10 3 11 11z" fill="#79D8A0"/><animateTransform attributeName="transform" type="translate" values="0 0;0 -2.5;0 0" dur="2.8s" repeatCount="indefinite"/></g><animateTransform attributeName="transform" type="translate" values="0 0;0 -2;0 0" dur="2.4s" repeatCount="indefinite"/></g></symbol>
<symbol id="rk-i-speak" viewBox="0 0 64 64"><g><circle cx="26" cy="22" r="11" fill="#FFD8B4"/><circle cx="22" cy="21" r="1.7" fill="#0D1F35"/><ellipse cx="30" cy="27" rx="3" ry="4" fill="#0D1F35"><animate attributeName="ry" values="4;1.5;4" dur="0.8s" repeatCount="indefinite"/></ellipse><path d="M10 54c2-10 8-14 16-14s14 4 16 14z" fill="#8B5CF6"/><g stroke="#FF4411" stroke-width="3" stroke-linecap="round" fill="none"><path d="M44 18q4 6 0 12"/><path d="M50 14q6 9 0 20"/><animate attributeName="opacity" values="1;0.25;1" dur="0.9s" repeatCount="indefinite"/></g></g></symbol>
<symbol id="rk-i-robot" viewBox="0 0 64 64"><g><rect x="14" y="20" width="36" height="28" rx="8" fill="#CBD5E1" stroke="#0D1F35" stroke-width="3"/><line x1="32" y1="12" x2="32" y2="20" stroke="#0D1F35" stroke-width="3"/><g><circle cx="32" cy="10" r="3.5" fill="#EF4444"/><animate attributeName="opacity" values="1;0.25;1" dur="1s" repeatCount="indefinite"/></g><g><circle cx="24" cy="32" r="4" fill="#4C95D7"><animate attributeName="r" values="4;4;1;4" keyTimes="0;0.85;0.92;1" dur="3.2s" repeatCount="indefinite"/></circle><circle cx="40" cy="32" r="4" fill="#4C95D7"><animate attributeName="r" values="4;4;1;4" keyTimes="0;0.85;0.92;1" dur="3.2s" repeatCount="indefinite"/></circle></g><rect x="25" y="40" width="14" height="3.5" rx="1.75" fill="#0D1F35"/><animateTransform attributeName="transform" type="translate" values="0 0;0 -2;0 0" dur="2.4s" repeatCount="indefinite"/></g></symbol>
<symbol id="rk-i-bulb" viewBox="0 0 64 64"><g><g><circle cx="32" cy="26" r="16" fill="#FFC93C" stroke="#F5A623" stroke-width="3"/><animate attributeName="opacity" values="1;0.55;1" dur="1.6s" repeatCount="indefinite"/></g><path d="M26 42h12v4a4 4 0 0 1-4 4h-4a4 4 0 0 1-4-4z" fill="#CBD5E1" stroke="#0D1F35" stroke-width="2.5"/><g stroke="#F5A623" stroke-width="3" stroke-linecap="round"><line x1="32" y1="2" x2="32" y2="7"/><line x1="12" y1="10" x2="16" y2="14"/><line x1="52" y1="10" x2="48" y2="14"/><animate attributeName="opacity" values="1;0.2;1" dur="1.6s" repeatCount="indefinite"/></g></g></symbol>
<symbol id="rk-i-link" viewBox="0 0 64 64"><g><g transform="rotate(-45 32 32)"><rect x="10" y="26" width="24" height="12" rx="6" fill="none" stroke="#4C95D7" stroke-width="4.5"/><rect x="30" y="26" width="24" height="12" rx="6" fill="none" stroke="#FF4411" stroke-width="4.5"/></g><animateTransform attributeName="transform" type="rotate" values="-5 32 32;5 32 32;-5 32 32" dur="2.4s" repeatCount="indefinite"/></g></symbol>
<symbol id="rk-i-play" viewBox="0 0 64 64"><g><circle cx="32" cy="32" r="24" fill="#FF4411"><animate attributeName="r" values="24;26;24" dur="1.4s" repeatCount="indefinite"/></circle><path d="M26 20l18 12-18 12z" fill="#fff"/></g></symbol>
<symbol id="rk-i-pin" viewBox="0 0 64 64"><g><path d="M32 6a17 17 0 0 1 17 17c0 12-17 33-17 33S15 35 15 23A17 17 0 0 1 32 6z" fill="#EF4444"/><circle cx="32" cy="23" r="7" fill="#fff"/><animateTransform attributeName="transform" type="translate" values="0 0;0 -3;0 0" dur="1.8s" repeatCount="indefinite"/></g></symbol>
<symbol id="rk-i-question" viewBox="0 0 64 64"><g><circle cx="32" cy="32" r="24" fill="#8B5CF6"/><text x="32" y="42" font-family="Arial" font-weight="bold" font-size="28" fill="#fff" text-anchor="middle">?</text><animateTransform attributeName="transform" type="rotate" values="-6 32 32;6 32 32;-6 32 32" dur="2.2s" repeatCount="indefinite"/></g></symbol>
</svg>

<div id="rkd3-shell">

<!-- ═══════ SIDEBAR (desktop) ═══════ -->
<?php echo RK_MC_Tutor_Dashboard::build_rk_nav_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

<!-- overlay for mobile sidebar -->
<div id="rkd3-drawer-overlay" hidden aria-hidden="true"></div>

<script>
var rkMsgCfg = {
    ajaxUrl: "<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>",
    nonce:   "<?php echo esc_js( $msg_nonce ); ?>",
    childId: <?php echo (int) $child_id_for_msg; ?>,
    coachId: <?php echo (int) $coach_id; ?>
};
var rkNotifCfg = {
    ajaxUrl: "<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>",
    nonce:   "<?php echo esc_js( $notif_nonce ); ?>"
};
</script>

<!-- ═══════ MAIN ═══════ -->
<main id="rkd3-main">

    <div id="rkd3-mobtop">
        <div class="rkd3-mobtop__brand"><?php echo $rkd3_icon( '⭐', 20 ); // phpcs:ignore WordPress.Security.EscapeOutput ?> ريادة كيدز</div>
        <div class="rkd3-mobtop__actions">

            <?php echo RK_MC_Tutor_Dashboard::notif_bell_trigger( $notif_unread, 'rkd3-mobtop__bell' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>

            <button id="rkd3-burger" class="rkd3-burger" aria-label="القائمة" aria-expanded="false">☰</button>

        </div>
    </div>

    <!-- HEADER -->
    <header class="rkd3-header rkd3-header--fixed">
        <div class="rkd3-header__greet">
            <h1 class="rkd3-header__title">مرحبا بك <?php echo esc_html( $child_name ); ?> <span class="rkd3-wave"><?php echo $rkd3_icon( '👋', 30 ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span></h1>
            <p class="rkd3-header__sub">جاهز لمواصلة رحلتك اليوم؟</p>
        </div>

        <div class="rkd3-header__stats">
            <?php if ( $streak_val > 0 ) : ?>
            <div class="rkd3-stat-pill">
                <span class="rkd3-stat-pill__icon"><?php echo $rkd3_icon( '🔥', 26 ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
                <div>
                    <div class="rkd3-stat-pill__val"><span class="rk-countup" data-count-up="<?php echo esc_attr( $streak_val ); ?>">0</span> يوماً</div>
                    <div class="rkd3-stat-pill__label">سلسلة الإنجاز</div>
                </div>
            </div>
            <?php endif; ?>
            <div class="rkd3-stat-pill">
                <span class="rkd3-stat-pill__icon"><?php echo $rkd3_icon( '⭐', 26 ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
                <div>
                    <div class="rkd3-stat-pill__val"><span class="rk-countup" data-count-up="<?php echo esc_attr( $child_xp ); ?>">0</span></div>
                    <div class="rkd3-stat-pill__label">نقاط XP</div>
                </div>
            </div>
            <div class="rkd3-stat-pill rkd3-stat-pill--level">
                <span class="rkd3-stat-pill__icon"><?php echo $rkd3_icon( '🎖️', 26 ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
                <div style="flex:1">
                    <div class="rkd3-stat-pill__val"><?php echo (int) $child_level; ?></div>
                    <div class="rkd3-stat-pill__label">المستوى</div>
                    <div class="rkd3-mini-bar">
                        <div class="rkd3-mini-bar__fill" style="width:<?php echo (int) $ring_pct; ?>%"></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="rkd3-header__right">
            <?php
            // v2.8.2 — vraie cloche avec dropdown (même widget que les sous-pages),
            // à la place de l'ancien lien 🔔 qui pointait vers la messagerie.
            echo RK_MC_Tutor_Dashboard::notif_bell_trigger( $notif_unread ); // phpcs:ignore WordPress.Security.EscapeOutput
            ?>
            <a href="<?php echo esc_url( $msg_page_url ); ?>" class="rkd3-icon-btn" title="الرسائل" aria-label="الرسائل">
                <?php echo $rkd3_icon( '✉️', 22 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                <?php if ( $total_unread > 0 ) : ?>
                <span class="rkd3-icon-btn__badge"><?php echo min( $total_unread, 9 ); ?></span>
                <?php endif; ?>
            </a>
            <div class="rkd3-header__avatar">
                <?php if ( ! empty( $child['avatar'] ) ) : ?>
                <img src="<?php echo esc_url( $child['avatar'] ); ?>" alt="" width="44" height="44">
                <?php else : ?>
                <img src="<?php echo esc_url( $img_ilu . 'character-banana.png' ); ?>" alt="" width="44" height="44">
                <?php endif; ?>
            </div>
        </div>
    </header>
    <?php echo RK_MC_Tutor_Dashboard::notif_bell_widget( $notif_nonce ); // phpcs:ignore WordPress.Security.EscapeOutput ?>

    <!-- GRID -->
    <div class="rkd3-grid">

    <!-- ══════════ COLONNE PRINCIPALE ══════════ -->
    <div class="rkd3-col rkd3-col--main">

        <!-- HERO — mission of the day -->
        <section class="rkd3-hero">
            <div class="rkd3-hero__deco">
                <span class="rkd3-hero__star" style="top:12%;left:8%;"><?php echo $rkd3_icon( '⭐', 22 ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
                <span class="rkd3-hero__star" style="top:65%;left:14%;animation-delay:.6s;"><?php echo $rkd3_icon( '✨', 22 ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
                <span class="rkd3-hero__star" style="top:20%;right:12%;animation-delay:1.1s;"><?php echo $rkd3_icon( '⭐', 22 ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
            </div>
            <div class="rkd3-hero__text">
                <span class="rkd3-hero__eyebrow"><?php echo $rkd3_icon( '🎯', 20 ); // phpcs:ignore WordPress.Security.EscapeOutput ?> مهمتي اليوم</span>
                <h2 class="rkd3-hero__title"><?php echo esc_html( $mission_title ); ?></h2>
                <div class="rkd3-hero__meta">
                    <span class="rkd3-hero__chip"><?php echo $rkd3_icon( '⏱', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput ?> 20 دقيقة</span>
                    <span class="rkd3-hero__chip"><?php echo $rkd3_icon( '⭐', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput ?> +120 XP</span>
                </div>
                <?php if ( $mission_url ) : ?>
                <a href="<?php echo esc_url( $mission_url ); ?>" class="rkd3-hero__cta">ابدأ المهمة ▶</a>
                <?php else : ?>
                <a href="<?php echo esc_url( home_url( RK_TUTOR_DASHBOARD_URL . 'enrolled-courses/' ) . RK_MC_Tutor_Dashboard::child_param() ); ?>"
                   class="rkd3-hero__cta">تابع رحلتك ▶</a>
                <?php endif; ?>
                <?php if ( ! empty( $goal['reward'] ) ) : ?>
                <div class="rkd3-hero__reward"><?php echo $rkd3_icon( '🏅', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput ?> المكافأة: <?php echo esc_html( $goal['reward'] ); ?></div>
                <?php endif; ?>
            </div>
            <div class="rkd3-hero__art">
                <img src="<?php echo esc_url( $img_ilu . 'empty-course.webp' ); ?>" alt="" loading="lazy">
            </div>
        </section>

        <!-- PARCOURS (islands map) -->
        <?php if ( $has_adventures ) : ?>
        <div class="rkd3-card">
            <div class="rkd3-card__head">
                <span class="rkd3-card__head-emoji"><?php echo $rkd3_icon( '🗺️', 26 ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
                <h2>مغامراتي</h2>
                <a href="<?php echo esc_url( $_nb . 'enrolled-courses/' . $_cp ); ?>" class="rkd3-card__see-all">عرض الكل</a>
            </div>
            <div class="rkd3-map">
                <span class="rkd3-map__cloud" style="top:6%;right:8%;"><?php echo $rkd3_icon( '☁️', 26 ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
                <span class="rkd3-map__cloud" style="top:50%;left:4%;animation-delay:2s;"><?php echo $rkd3_icon( '☁️', 26 ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
                <div class="rkd3-islands">
                    <?php
                    foreach ( $adventures as $ai => $adventure ) :
                        $adv_prog  = intval( $adventure['progress'] ?? 0 );
                        $adv_state = $adv_prog >= 100 ? 'done' : ( $adv_prog > 0 ? 'current' : 'locked' );
                        if ( 0 === $ai && 'locked' === $adv_state ) { $adv_state = 'current'; }
                        $adv_icon  = $island_icons[ $ai % count( $island_icons ) ];
                        $adv_link  = ! empty( $adventure['permalink'] ) ? $adventure['permalink'] : '#';
                    ?>
                    <div class="rkd3-island rkd3-island--<?php echo esc_attr( $adv_state ); ?>">
                        <?php if ( $ai > 0 ) : ?>
                        <span class="rkd3-island__path<?php echo 'locked' !== $adv_state ? ' is-done' : ''; ?>"></span>
                        <?php endif; ?>
                        <a href="<?php echo esc_url( $adv_link ); ?>" class="rkd3-island__badge" aria-label="<?php echo esc_attr( $adventure['title'] ?? '' ); ?>">
                            <?php echo $rkd3_icon( $adv_icon, 30 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                            <?php if ( 'done' === $adv_state ) : ?><span class="rkd3-island__check">✓</span><?php endif; ?>
                            <?php if ( 'locked' === $adv_state ) : ?><span class="rkd3-island__lock"><?php echo $rkd3_icon( '🔒', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span><?php endif; ?>
                        </a>
                        <span class="rkd3-island__label"><?php echo esc_html( $adventure['title'] ?? '' ); ?></span>
                        <?php if ( 'locked' !== $adv_state ) : ?>
                        <span class="rkd3-island__pct"><?php echo $adv_prog; ?>%</span>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div class="rkd3-map-progress">
                    <div class="rkd3-map-progress__bar">
                        <div class="rkd3-map-progress__fill" style="width:<?php echo (int) $ring_pct; ?>%"></div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- STATS + TIMELINE (flex wrap) -->
        <div class="rkd3-row-2col">

            <!-- Statistiques -->
            <div class="rkd3-card">
                <div class="rkd3-card__head">
                    <span class="rkd3-card__head-emoji"><?php echo $rkd3_icon( '📊', 26 ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
                    <h2>تطوري هذا الأسبوع</h2>
                </div>
                <div class="rkd3-stats-grid">
                    <div class="rkd3-stat-box">
                        <div class="rkd3-stat-box__icon"><?php echo $rkd3_icon( '📚', 28 ); // phpcs:ignore WordPress.Security.EscapeOutput ?></div>
                        <div class="rkd3-stat-box__val"><?php echo (int)( $stats['lessons_done'] ?? 0 ); ?></div>
                        <div class="rkd3-stat-box__label">الدروس المكتملة</div>
                    </div>
                    <div class="rkd3-stat-box">
                        <div class="rkd3-stat-box__icon"><?php echo $rkd3_icon( '✅', 28 ); // phpcs:ignore WordPress.Security.EscapeOutput ?></div>
                        <?php
                        /* الاختبارات — synchronisé avec /dashboard/my-quiz-attempts/ :
                         * même source (RKP_AysQuizRepository → wp_aysquiz_reports),
                         * même règle (tentative = end_date non vide). */
                        $_wk_ays_done = 0;
                        $_wk_cid      = (int) ( $_cid ?? 0 );

                        if ( ! $_wk_cid && class_exists( 'RK_MC_Tutor_Dashboard' ) ) {
                            $_wk_child  = RK_MC_Tutor_Dashboard::get_child_for_template();
                            $_wk_cid    = $_wk_child ? (int) $_wk_child->id : 0;
                        }
                        if ( $_wk_cid && class_exists( 'RKP_AysQuizRepository' ) ) {
                            global $wpdb;
                            $_wk_uid = isset( $_wk_child->wp_user_id )
                                ? (int) $_wk_child->wp_user_id
                                : (int) $wpdb->get_var( $wpdb->prepare(
                                    "SELECT wp_user_id FROM {$wpdb->prefix}rk_children WHERE id = %d LIMIT 1",
                                    $_wk_cid
                                ) );
                            foreach ( RKP_AysQuizRepository::find_assigned_for_child( $_wk_cid, $_wk_uid ) as $_wk_r ) {
                                if ( ! empty( $_wk_r->end_date ) ) {
                                    $_wk_ays_done++;
                                }
                            }
                        }
                        // Quiz coach (AYS) + quiz de cours (Tutor)
                        $_stat_quizzes = $_wk_ays_done + (int) ( $stats['quizzes_done'] ?? 0 );
                        ?>
                        <div class="rkd3-stat-box__val"><?php echo (int) $_stat_quizzes; ?></div>
                        <div class="rkd3-stat-box__label">الاختبارات</div>
                    </div>
                    <div class="rkd3-stat-box">
                        <div class="rkd3-stat-box__icon"><?php echo $rkd3_icon( '⏱', 28 ); // phpcs:ignore WordPress.Security.EscapeOutput ?></div>
                        <div class="rkd3-stat-box__val"><?php echo (int)( $stats['avg_progress'] ?? 0 ); ?>%</div>
                        <div class="rkd3-stat-box__label">متوسط التقدم</div>
                    </div>
                    <div class="rkd3-stat-box">
                        <div class="rkd3-stat-box__icon"><?php echo $rkd3_icon( '⭐', 28 ); // phpcs:ignore WordPress.Security.EscapeOutput ?></div>
                        <div class="rkd3-stat-box__val">+<span class="rk-countup" data-count-up="<?php echo esc_attr( $child_xp ); ?>">0</span></div>
                        <div class="rkd3-stat-box__label">النقاط المكتسبة</div>
                    </div>
                </div>
                <?php
                $spark = [ 0.15, 0.3, 0.55, 0.62, 0.8, 1 ];
                $pts = [];
                foreach ( $spark as $si => $sv ) {
                    $x = 10 + $si * ( 300 / ( count( $spark ) - 1 ) );
                    $y = 120 - ( $sv * 100 );
                    $pts[] = "$x,$y";
                }
                $poly = implode( ' ', $pts );
                ?>
                <svg class="rkd3-chart" viewBox="0 0 320 140" preserveAspectRatio="none" role="img" aria-label="مخطط النقاط">
                    <polyline points="<?php echo esc_attr( $poly ); ?>"
                        fill="none"
                        style="stroke:var(--e-global-color-secondary,#4C95D7)"
                        stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
                    <?php foreach ( $pts as $p ) : list( $px, $py ) = explode( ',', $p ); ?>
                    <circle cx="<?php echo esc_attr( $px ); ?>" cy="<?php echo esc_attr( $py ); ?>"
                        r="4" style="fill:var(--e-global-color-secondary,#4C95D7)"/>
                    <?php endforeach; ?>
                </svg>
            </div>

            <!-- النشاط الأخير (timeline) — beside stats -->
            <div class="rkd3-card">
                <div class="rkd3-card__head">
                    <span class="rkd3-card__head-emoji"><?php echo $rkd3_icon( '🕓', 26 ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
                    <h2>النشاط الأخير</h2>
                </div>
                <div class="rkd3-timeline">
                    <p class="rkd3-tl-group__label">اليوم</p>
                    <?php if ( $has_missions ) :
                        $recent_done = array_filter( $missions, static fn($m) => ( $m['state'] ?? '' ) === 'completed' );
                    ?>
                        <?php foreach ( array_slice( $recent_done, 0, 3 ) as $rm ) : ?>
                        <div class="rkd3-tl-item">
                            <div class="rkd3-tl-icon"><?php echo $rkd3_icon( '✅', 20 ); // phpcs:ignore WordPress.Security.EscapeOutput ?></div>
                            <div class="rkd3-tl-body">
                                <span class="rkd3-tl-text">أكملت «<?php echo esc_html( $rm['title'] ?? '' ); ?>»</span>
                                <?php if ( ! empty( $rm['reward'] ) ) : ?>
                                <span class="rkd3-tl-xp"><?php echo $rkd3_icon( '⭐', 14 ); // phpcs:ignore WordPress.Security.EscapeOutput ?> <?php echo esc_html( $rm['reward'] ); ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php else : ?>
                    <p class="rkd3-empty">لا يوجد نشاط بعد — ابدأ مهمتك الأولى! <?php echo $rkd3_icon( '🚀', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput ?></p>
                    <?php endif; ?>
                </div>
            </div>

        </div><!-- /row-2col -->

        <!-- Prochaine réservation (remplace le message du coach) -->
        <?php
        /* Prochaine session confirmée + lien de démarrage (meeting_url
         * renseigné par le coach via POST /coach/session/zoom). */
        $_bk = null;
        /* Résolution de l'enfant — fallback session enfant (?rk_tab) :
         * réutilise $_wk_cid déjà résolu par le bloc stats quiz plus haut,
         * sinon résout via get_child_for_template() (comme le bloc quiz). */
        $_bk_cid = (int) ( $_cid ?: ( $_wk_cid ?? 0 ) );
        if ( ! $_bk_cid && class_exists( 'RK_MC_Tutor_Dashboard' ) ) {
            $_bk_child = RK_MC_Tutor_Dashboard::get_child_for_template();
            $_bk_cid   = $_bk_child ? (int) $_bk_child->id : 0;
        }
        if ( $_bk_cid ) {
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
                $_bk_cid
            ) );
        }
        $_bk_book_url = $_nb . 'rk-sessions/' . $_cp; // page sessions/réservation
        if ( $_bk ) {
            // Fuseau du client (cf. rk_mc_appt_format) et non valeur brute.
            $_bk_ts     = rk_mc_appt_timestamp( (string) $_bk->appointment );
            $_bk_aid    = (int) ( $_bk->booking_id ?? 0 );
            $_bk_appt   = (string) $_bk->appointment;
            $_bk_url   = (string) ( $_bk->meeting_url ?? '' );
            $_bk_coach = '';
            if ( ! empty( $_bk->coach_id ) && ( $_bk_u = get_userdata( (int) $_bk->coach_id ) ) ) {
                $_bk_coach = $_bk_u->display_name;
            } elseif ( ! empty( $_bk->coach ) ) {
                $_bk_coach = (string) $_bk->coach;
            }
            // Bouton actif de 15 min avant le début jusqu'à 2 h après
            $_bk_now  = current_time( 'timestamp' );
            $_bk_live = $_bk_ts && $_bk_now >= ( $_bk_ts - 15 * MINUTE_IN_SECONDS )
                                && $_bk_now <= ( $_bk_ts + 2 * HOUR_IN_SECONDS );
        }
        ?>
        <div class="rkd3-card">
            <div class="rkd3-card__head">
                <span class="rkd3-card__head-emoji"><?php echo $rkd3_icon( '📅', 26 ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
                <h2>جلستي القادمة</h2>
            </div>
            <?php if ( $_bk ) : ?>
            <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap;">
                <div style="flex-shrink:0;width:64px;height:64px;border-radius:16px;background:var(--e-global-color-11f6b7e,#EDF4FB);display:flex;flex-direction:column;align-items:center;justify-content:center;line-height:1.1;">
                    <span style="font-size:1.4rem;font-weight:800;color:var(--e-global-color-secondary,#4C95D7);"><?php echo esc_html( $_bk_ts ? rk_mc_appt_format( $_bk_appt, 'j', $_bk_aid ) : '—' ); ?></span>
                    <span style="font-size:.72rem;font-weight:700;color:#64748b;"><?php echo esc_html( $_bk_ts ? rk_mc_appt_format( $_bk_appt, 'M', $_bk_aid ) : '' ); ?></span>
                </div>
                <div style="flex:1;min-width:150px;">
                    <strong style="display:block;font-size:.95rem;color:#0D1F35;">
                        <?php echo esc_html( $_bk->session_name ?: 'لقاء مع المدرب' ); ?>
                    </strong>
                    <?php if ( $_bk_coach ) : ?>
                    <span style="font-size:.8rem;color:#64748b;"><?php echo $rkd3_icon( '👩‍🏫', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput ?> <?php echo esc_html( $_bk_coach ); ?></span>
                    <?php endif; ?>
                    <div style="font-size:.8rem;color:#64748b;margin-top:2px;">
                        <?php echo $rkd3_icon( '🕕', 14 ); // phpcs:ignore WordPress.Security.EscapeOutput ?> <?php echo esc_html( $_bk_ts ? rk_mc_appt_format( $_bk_appt, 'l، j F — H:i', $_bk_aid ) : '' ); ?>
                    </div>
                </div>
                <?php if ( $_bk_url && $_bk_live ) : ?>
                <a href="<?php echo esc_url( $_bk_url ); ?>" target="_blank" rel="noopener"
                   style="display:inline-flex;align-items:center;gap:8px;padding:12px 26px;border-radius:14px;background:var(--e-global-color-primary,#FF4411);color:#fff;font-weight:800;font-size:.95rem;text-decoration:none;box-shadow:0 4px 12px rgba(255,68,17,.3);">
                    <?php echo $rkd3_icon( '▶️', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput ?> ابدأ الجلسة
                </a>
                <?php elseif ( $_bk_url ) : ?>
                <span style="display:inline-flex;align-items:center;gap:8px;padding:12px 26px;border-radius:14px;background:#e2e8f0;color:#64748b;font-weight:700;font-size:.9rem;cursor:not-allowed;" title="يتفعّل الزر قبل موعد الجلسة بـ15 دقيقة">
                    <?php echo $rkd3_icon( '⏳', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput ?> ابدأ الجلسة
                </span>
                <?php else : ?>
                <span style="display:inline-flex;align-items:center;gap:8px;padding:12px 26px;border-radius:14px;background:#e2e8f0;color:#64748b;font-weight:700;font-size:.9rem;">
                    <?php echo $rkd3_icon( '🔗', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput ?> الرابط سيتوفر قريباً
                </span>
                <?php endif; ?>
            </div>

            <?php else : /* ── État vide : aucune réservation à venir ── */ ?>
            <div style="display:flex;flex-direction:column;align-items:center;gap:10px;padding:18px 12px;text-align:center;">
                <?php echo $rkd3_icon( '🗓️', 46 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                <p style="margin:0;font-size:.92rem;font-weight:700;color:#0D1F35;">لا توجد جلسة قادمة</p>
                <p style="margin:0;font-size:.8rem;color:#64748b;">اطلب من والديك حجز جلستك القادمة مع مدربك</p>
                <a href="<?php echo esc_url( $_bk_book_url ); ?>"
                   style="display:inline-flex;align-items:center;gap:8px;margin-top:4px;padding:11px 24px;border-radius:14px;background:var(--e-global-color-secondary,#4C95D7);color:#fff;font-weight:800;font-size:.9rem;text-decoration:none;box-shadow:0 4px 12px rgba(76,149,215,.3);">
                    <?php echo $rkd3_icon( '📅', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput ?> عرض جلساتي
                </a>
            </div>
            <?php endif; ?>
        </div>

        <?php // Carte اختباراتي — factorisée dans partials/home-quiz-card.php (portée partagée via include)
        include __DIR__ . '/partials/home-quiz-card.php';
        ?>

    </div><!-- /col-main -->

    <!-- ══════════ SIDEBAR DROITE ══════════ -->
    <div class="rkd3-col rkd3-col--side">

        <!-- Coach -->
        <?php if ( $coach_name ) : ?>
        <div class="rkd3-card rkd3-coach">
            <div class="rkd3-card__head" style="justify-content:center;">
                <span class="rkd3-card__head-emoji"><?php echo $rkd3_icon( '🎓', 26 ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
                <h2 style="flex:0;">مدربي</h2>
            </div>
            <?php if ( ! empty( $messages['coach_avatar'] ) ) : ?>
            <img src="<?php echo esc_url( $messages['coach_avatar'] ); ?>" alt="" class="rkd3-coach__avatar">
            <?php else : ?>
            <div class="rkd3-coach__avatar" style="display:flex;align-items:center;justify-content:center;background:var(--e-global-color-11f6b7e,#EDF4FB);"><?php echo $rkd3_icon( '👩‍🏫', 40 ); // phpcs:ignore WordPress.Security.EscapeOutput ?></div>
            <?php endif; ?>
            <p class="rkd3-coach__name"><?php echo esc_html( $coach_name ); ?></p>
            <p class="rkd3-coach__role">مدربة ريادة الأعمال</p>
            <span class="rkd3-coach__online"><span class="rkd3-coach__dot"></span> متصلة الآن</span>
            <div class="rkd3-coach__actions">
                <a href="<?php echo esc_url( $msg_page_url ); ?>" class="rkd3-btn rkd3-btn--primary rkd3-btn--block">أرسل رسالة</a>
            </div>
        </div>
        <?php endif; ?>

        <!-- Prochaine séance (source : wp_rk_bookings — même $_bk que la carte principale) -->
        <?php if ( ! empty( $_bk ) ) : ?>
        <div class="rkd3-card">
            <div class="rkd3-card__head">
                <span class="rkd3-card__head-emoji"><?php echo $rkd3_icon( '📅', 26 ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
                <h2>جلستي القادمة</h2>
            </div>
            <div class="rkd3-session__row">
                <div class="rkd3-session__date">
                    <span class="rkd3-session__day"><?php echo esc_html( $_bk_ts ? rk_mc_appt_format( $_bk_appt, 'j', $_bk_aid ) : '—' ); ?></span>
                    <span class="rkd3-session__month"><?php echo esc_html( $_bk_ts ? rk_mc_appt_format( $_bk_appt, 'M', $_bk_aid ) : '' ); ?></span>
                </div>
                <div class="rkd3-session__info">
                    <strong><?php echo esc_html( $_bk->session_name ?: 'لقاء مع المدرب' ); ?></strong>
                    <span><?php echo esc_html( $_bk_coach ?: $coach_name ); ?></span>
                </div>
            </div>
            <?php if ( $_bk_ts ) : ?>
            <div class="rkd3-session__time"><?php echo $rkd3_icon( '🕕', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput ?> <?php echo esc_html( rk_mc_appt_format( $_bk_appt, 'H:i', $_bk_aid ) ); ?></div>
            <?php endif; ?>

            <?php if ( $_bk_url && $_bk_live ) : ?>
            <a href="<?php echo esc_url( $_bk_url ); ?>" target="_blank" rel="noopener"
               class="rkd3-btn rkd3-btn--green rkd3-btn--block">
                <?php echo $rkd3_icon( '▶️', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput ?> الانضمام إلى الجلسة
            </a>
            <?php elseif ( $_bk_url ) : ?>
            <span class="rkd3-btn rkd3-btn--block" style="background:#e2e8f0;color:#64748b;cursor:not-allowed;"
                  title="يتفعّل الزر قبل موعد الجلسة بـ15 دقيقة">
                <?php echo $rkd3_icon( '⏳', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput ?> الانضمام إلى الجلسة
            </span>
            <?php else : ?>
            <a href="<?php echo esc_url( $_nb . 'rk-sessions/' . $_cp ); ?>" class="rkd3-btn rkd3-btn--green rkd3-btn--block">
                عرض جلساتي
            </a>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Badges showcase -->
        <?php
        $_emoji_bdg = [
            'star'=>'⭐','graduation-cap'=>'🎓','nav-target'=>'🎯','users'=>'👥',
            'fire'=>'🔥','diamond'=>'💎','lightning'=>'⚡','moon'=>'🌙','trophy'=>'🏆',
            'rocket'=>'🚀','skill-speech'=>'🎤','skill-creativity'=>'🎨','gift'=>'🎁',
            'award'=>'🥇','nav-compass'=>'🧭','book-open'=>'📖',
        ];
        $_earned_set = array_filter( $badges, static fn( $b ) => ! empty( $b['earned'] ) );
        $_recent_6   = array_slice( array_values( $_earned_set ), 0, 6 );
        $_locked_n   = max( 0, $badge_total - $earned_count );
        $_bdg_pct    = $badge_total > 0 ? (int) round( $earned_count / $badge_total * 100 ) : 0;
        $_next_badge = $journey['next_badge'] ?? '';
        ?>
        <div class="rkd3-card rkd3-bdg-card">
            <div class="rkd3-card__head">
                <span class="rkd3-card__head-emoji"><?php echo $rkd3_icon( '🏅', 26 ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
                <h2><?php esc_html_e( 'شاراتي', 'rk-my-children' ); ?></h2>
                <a href="<?php echo esc_url( $_nb . 'rk-badges/' . $_cp ); ?>" class="rkd3-card__see-all">
                    <?php esc_html_e( 'عرض الكل', 'rk-my-children' ); ?>
                </a>
            </div>

            <!-- Progress -->
            <div class="rkd3-bdg-progress">
                <div class="rkd3-bdg-progress__bar">
                    <div class="rkd3-bdg-progress__fill" style="width:<?php echo $_bdg_pct; ?>%"></div>
                </div>
                <div class="rkd3-bdg-progress__meta">
                    <span>
                        <strong><?php echo $earned_count; ?></strong> / <?php echo $badge_total; ?>
                        <?php esc_html_e( 'شارة', 'rk-my-children' ); ?>
                    </span>
                    <span><?php echo $_bdg_pct; ?>%</span>
                </div>
            </div>

            <!-- Earned badge chips -->
            <?php if ( ! empty( $_recent_6 ) ) : ?>
            <div class="rkd3-bdg-chips">
                <?php foreach ( $_recent_6 as $_rb ) : ?>
                <div class="rkd3-bdg-chip" title="<?php echo esc_attr( $_rb['name'] ?? '' ); ?>">
                    <?php echo $rkd3_icon( $_emoji_bdg[ $_rb['icon_key'] ?? '' ] ?? '🏅', 24 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                </div>
                <?php endforeach; ?>
                <?php if ( $_locked_n > 0 ) : ?>
                <div class="rkd3-bdg-chip rkd3-bdg-chip--more" aria-hidden="true">
                    +<?php echo $_locked_n; ?>
                </div>
                <?php endif; ?>
            </div>
            <?php else : ?>
            <div class="rkd3-bdg-empty">
                <span aria-hidden="true"><?php echo $rkd3_icon( '💪', 22 ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
                <span><?php esc_html_e( 'واصل مسيرتك لتكسب شاراتك!', 'rk-my-children' ); ?></span>
            </div>
            <?php endif; ?>

            <!-- Next badge hint -->
            <?php if ( $_next_badge ) : ?>
            <div class="rkd3-bdg-hint">
                <span aria-hidden="true"><?php echo $rkd3_icon( '🔓', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
                <span><?php echo esc_html( $_next_badge ); ?></span>
            </div>
            <?php endif; ?>
        </div>

        <!-- DOMAINES (categories — item 7) -->
        <div class="rkd3-card">
            <div class="rkd3-card__head">
                <span class="rkd3-card__head-emoji"><?php echo $rkd3_icon( '🌟', 26 ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
                <h2>مجالاتي</h2>
            </div>
            <div class="rkd3-cats">
                <a href="<?php echo esc_url( $_nb . 'enrolled-courses/' . $_cp ); ?>" class="rkd3-cat-tile">
                    <span class="rkd3-cat-tile__icon"><?php echo $rkd3_icon( '🗣️', 28 ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
                    <span class="rkd3-cat-tile__name">التواصل باللغة الإنجليزية</span>
                </a>
                <a href="<?php echo esc_url( $_nb . 'enrolled-courses/' . $_cp ); ?>" class="rkd3-cat-tile">
                    <span class="rkd3-cat-tile__icon"><?php echo $rkd3_icon( '🤖', 28 ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
                    <span class="rkd3-cat-tile__name">الذكاء الاصطناعي</span>
                </a>
                <a href="<?php echo esc_url( $_nb . 'enrolled-courses/' . $_cp ); ?>" class="rkd3-cat-tile">
                    <span class="rkd3-cat-tile__icon"><?php echo $rkd3_icon( '💡', 28 ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
                    <span class="rkd3-cat-tile__name">المنطق والبرمجة</span>
                </a>
                <a href="<?php echo esc_url( $_nb . 'enrolled-courses/' . $_cp ); ?>" class="rkd3-cat-tile">
                    <span class="rkd3-cat-tile__icon"><?php echo $rkd3_icon( '🚀', 28 ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
                    <span class="rkd3-cat-tile__name">ريادة الأعمال والتفكير</span>
                </a>
            </div>
        </div>

    </div><!-- /col-side -->

    </div><!-- /grid -->

</main>
</div><!-- /shell -->

<?php // JS factorisé — voir Modules/Children/assets/js/rkd3-home.js
$_rk_js_rel = 'Modules/Children/assets/js/rkd3-home.js';
printf( '<script src="%s"></script>', esc_url( RKP_URL . $_rk_js_rel . '?ver=' . (string) filemtime( RKP_DIR . $_rk_js_rel ) ) );
?>

</div><!-- /#rkd3 -->