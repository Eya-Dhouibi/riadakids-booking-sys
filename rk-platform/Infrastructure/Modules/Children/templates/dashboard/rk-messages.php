<?php
declare( strict_types=1 );
/**
 * RiadaKids — Dashboard Enfant : الرسائل  (v4.0 — Kids Chat UI)
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$child = RK_MC_Tutor_Dashboard::get_child_for_template();
if ( ! $child ) {
    echo '<p style="padding:24px;text-align:center;color:#64748b;">'
        . esc_html__( 'يرجى تحديد الطفل أولاً.', 'rk-my-children' ) . '</p>';
    return;
}

$child_id     = (int) $child->id;
$child_wp_uid = (int) ( $child->wp_user_id ?? 0 );
$has_bm       = class_exists( 'Better_Messages' );
$has_msg      = class_exists( 'RK_MC_Message_Service' );

/* ── Resolve recipients ───────────────────────────────────────────── */
$coach_obj    = $has_msg ? RK_MC_Message_Service::get_coach( $child_id ) : null;
$admin_obj    = $has_msg ? RK_MC_Message_Service::get_admin_user()       : null;
$coach_wp_uid = $coach_obj ? (int) $coach_obj->ID : 0;
$admin_wp_uid = $admin_obj ? (int) $admin_obj->ID : 0;

/* ── Load initial messages ────────────────────────────────────────── */
$coach_msgs = [];
$admin_msgs = [];
$last_coach = 0;
$last_admin = 0;

if ( $child_wp_uid && $has_msg ) {
    if ( $coach_wp_uid ) {
        $coach_msgs = RK_MC_Message_Service::load_bm_messages( $child_wp_uid, $coach_wp_uid );
        $last_coach = $coach_msgs ? max( array_column( $coach_msgs, 'id' ) ) : 0;
    }
    if ( $admin_wp_uid && $admin_wp_uid !== $coach_wp_uid ) {
        $admin_msgs = RK_MC_Message_Service::load_bm_messages( $child_wp_uid, $admin_wp_uid );
        $last_admin = $admin_msgs ? max( array_column( $admin_msgs, 'id' ) ) : 0;
    }
}

/* ── Unread counts ────────────────────────────────────────────────── */
$unread_coach = $has_msg ? RK_MC_Message_Service::bm_thread_unread( $child_wp_uid, $coach_wp_uid ) : 0;
$unread_admin = $has_msg ? RK_MC_Message_Service::bm_thread_unread( $child_wp_uid, $admin_wp_uid ) : 0;

/* ── Nonce ────────────────────────────────────────────────────────── */
$nonce     = wp_create_nonce( 'rk_mc_chat' );
$show_tabs = ( $coach_obj && $admin_obj && $admin_wp_uid !== $coach_wp_uid );

/* ── Helper: single message bubble ───────────────────────────────── */
function rk_chat_msg( array $msg, int $viewer_id, string $partner_avatar, ?string $prev_day, ?int $prev_sender ): string {
    $is_out  = ( (int) $msg['sender_id'] === $viewer_id );
    $day     = $msg['date_day'] ?? substr( $msg['date_full'], 0, 10 );
    $html    = '';

    if ( $day !== $prev_day ) {
        $today     = date_i18n( 'Y-m-d' );
        $yesterday = date_i18n( 'Y-m-d', strtotime( '-1 day' ) );
        $label     = ( $day === $today ) ? 'اليوم' : ( ( $day === $yesterday ) ? 'أمس' : date_i18n( 'j F Y', strtotime( $day ) ) );
        $html .= '<div class="rk-chat-day" aria-hidden="true"><span>' . esc_html( $label ) . '</span></div>';
    }

    $grouped = ( $prev_sender !== null && $prev_sender === (int) $msg['sender_id'] && $day === $prev_day );
    $cls     = 'rk-chat-msg ' . ( $is_out ? 'rk-chat-msg--out' : 'rk-chat-msg--in' );
    if ( $grouped ) $cls .= ' rk-chat-msg--grouped';

    $ava_html = $is_out ? '' :
        '<img class="rk-chat-msg__ava" src="' . esc_url( $partner_avatar ) . '" alt="" width="34" height="34" aria-hidden="true">';

    $html .= '<div class="' . esc_attr( $cls ) . '" data-id="' . (int) $msg['id'] . '" data-sender="' . (int) $msg['sender_id'] . '">'
        . $ava_html
        . '<div class="rk-chat-msg__bubble">'
        .   '<p class="rk-chat-msg__text">' . esc_html( $msg['body'] ) . '</p>'
        .   '<time class="rk-chat-msg__time" datetime="' . esc_attr( $msg['date_full'] ) . '">'
        .       esc_html( $msg['time'] ) . ( $is_out ? ' ✓' : '' )
        .   '</time>'
        . '</div>'
        . '</div>';

    return $html;
}
?>
<?php // CSS factorisé — voir Modules/Children/assets/css/rk-messages.css (versionné par filemtime pour contourner le cache LiteSpeed)
$_rk_css_rel = 'Modules/Children/assets/css/rk-messages.css';
printf( '<link rel="stylesheet" href="%s">', esc_url( RKP_URL . $_rk_css_rel . '?ver=' . (string) filemtime( RKP_DIR . $_rk_css_rel ) ) );
?>

<div class="rk-chat-wrap" dir="rtl">

    <?php if ( $show_tabs ) : ?>
    <div class="rk-chat-tabs" role="tablist">
        <button class="rk-chat-tab rk-chat-tab--active"
                data-panel="coach" role="tab" aria-selected="true" aria-controls="rk-panel-coach">
            🎓
            المدرب
            <?php if ( $unread_coach > 0 ) : ?>
            <span class="rk-chat-tab__badge"><?php echo min( $unread_coach, 9 ); ?><?php echo $unread_coach > 9 ? '+' : ''; ?></span>
            <?php endif; ?>
        </button>
        <button class="rk-chat-tab"
                data-panel="admin" role="tab" aria-selected="false" aria-controls="rk-panel-admin">
            🤝
            الإدارة
            <?php if ( $unread_admin > 0 ) : ?>
            <span class="rk-chat-tab__badge"><?php echo min( $unread_admin, 9 ); ?><?php echo $unread_admin > 9 ? '+' : ''; ?></span>
            <?php endif; ?>
        </button>
    </div>
    <?php endif; ?>

    <?php
    $coach_avatar = $coach_obj ? get_avatar_url( $coach_wp_uid, [ 'size' => 64 ] ) : '';
    ?>

    <!-- ══ PANEL COACH ══ -->
    <div class="rk-chat-panel rk-chat-panel--active" id="rk-panel-coach" role="tabpanel">
        <?php if ( $coach_obj && $coach_wp_uid ) : ?>

        <div class="rk-chat-header">
            <img class="rk-chat-header__ava"
                 src="<?php echo esc_url( $coach_avatar ); ?>"
                 alt="<?php echo esc_attr( $coach_obj->display_name ); ?>"
                 width="46" height="46">
            <div class="rk-chat-header__info">
                <h2 class="rk-chat-header__name"><?php echo esc_html( $coach_obj->display_name ); ?></h2>
                <span class="rk-chat-header__role">
                    <span class="rk-chat-header__online" aria-hidden="true"></span>
                    <span class="rk-chat-header__badge">🎓 مدربك</span>
                </span>
            </div>
        </div>

        <div class="rk-chat-body" id="rk-body-coach">
            <?php if ( $coach_msgs ) :
                $prev_day    = null;
                $prev_sender = null;
                foreach ( $coach_msgs as $msg ) :
                    echo rk_chat_msg( $msg, $child_wp_uid, $coach_avatar, $prev_day, $prev_sender );
                    $prev_day    = $msg['date_day'];
                    $prev_sender = (int) $msg['sender_id'];
                endforeach;
            else : ?>
            <div class="rk-chat-empty">
                <span class="rk-chat-empty__icon">💬</span>
                <div class="rk-chat-empty__stars">⭐⭐⭐</div>
                <p><?php esc_html_e( 'قل مرحبًا لمدربك!', 'rk-my-children' ); ?></p>
                <small><?php esc_html_e( 'يمكنك إرسال أسئلة أو تعليقات على جلساتك', 'rk-my-children' ); ?></small>
            </div>
            <?php endif; ?>
        </div>

        <div class="rk-chat-composer">
            <textarea id="rk-input-coach"
                      class="rk-chat-input"
                      placeholder="<?php esc_attr_e( 'اكتب رسالتك للمدرب… 😊', 'rk-my-children' ); ?>"
                      rows="1"
                      maxlength="2000"
                      aria-label="<?php esc_attr_e( 'اكتب رسالة', 'rk-my-children' ); ?>"></textarea>
            <button class="rk-chat-send-btn"
                    data-panel="coach"
                    data-to="<?php echo esc_attr( $coach_wp_uid ); ?>"
                    aria-label="<?php esc_attr_e( 'إرسال', 'rk-my-children' ); ?>">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
            </button>
        </div>

        <?php else : ?>
        <div class="rk-chat-nocoach">
            <span class="rk-chat-nocoach__icon">📅</span>
            <p><?php esc_html_e( 'لم يتم تعيين مدرب بعد', 'rk-my-children' ); ?></p>
            <small><?php esc_html_e( 'ستظهر المحادثة بعد تأكيد أول جلسة 🌟', 'rk-my-children' ); ?></small>
        </div>
        <?php endif; ?>
    </div>

    <?php if ( $show_tabs && $admin_obj && $admin_wp_uid ) :
        $admin_avatar = get_avatar_url( $admin_wp_uid, [ 'size' => 64 ] );
    ?>
    <!-- ══ PANEL ADMIN ══ -->
    <div class="rk-chat-panel" id="rk-panel-admin" role="tabpanel">

        <div class="rk-chat-header" style="background: linear-gradient(135deg, #06D6A0 0%, #0491D1 100%);">
            <img class="rk-chat-header__ava"
                 src="<?php echo esc_url( $admin_avatar ); ?>"
                 alt="<?php echo esc_attr( $admin_obj->display_name ); ?>"
                 width="46" height="46">
            <div class="rk-chat-header__info">
                <h2 class="rk-chat-header__name"><?php esc_html_e( 'فريق ريادة كيدز', 'rk-my-children' ); ?></h2>
                <span class="rk-chat-header__role">
                    <span class="rk-chat-header__online" aria-hidden="true"></span>
                    <span class="rk-chat-header__badge">🤝 الدعم</span>
                </span>
            </div>
        </div>

        <div class="rk-chat-body" id="rk-body-admin">
            <?php if ( $admin_msgs ) :
                $prev_day    = null;
                $prev_sender = null;
                foreach ( $admin_msgs as $msg ) :
                    echo rk_chat_msg( $msg, $child_wp_uid, $admin_avatar, $prev_day, $prev_sender );
                    $prev_day    = $msg['date_day'];
                    $prev_sender = (int) $msg['sender_id'];
                endforeach;
            else : ?>
            <div class="rk-chat-empty">
                <span class="rk-chat-empty__icon">🤝</span>
                <div class="rk-chat-empty__stars">⭐⭐⭐</div>
                <p><?php esc_html_e( 'تواصل مع الفريق!', 'rk-my-children' ); ?></p>
                <small><?php esc_html_e( 'للأسئلة والدعم — نحن هنا دائمًا 💙', 'rk-my-children' ); ?></small>
            </div>
            <?php endif; ?>
        </div>

        <div class="rk-chat-composer">
            <textarea id="rk-input-admin"
                      class="rk-chat-input"
                      placeholder="<?php esc_attr_e( 'اكتب رسالتك… 💬', 'rk-my-children' ); ?>"
                      rows="1"
                      maxlength="2000"
                      aria-label="<?php esc_attr_e( 'اكتب رسالة', 'rk-my-children' ); ?>"></textarea>
            <button class="rk-chat-send-btn"
                    style="background: linear-gradient(135deg, #06D6A0 0%, #0491D1 100%); box-shadow: 0 4px 14px rgba(6,214,160,.35);"
                    data-panel="admin"
                    data-to="<?php echo esc_attr( $admin_wp_uid ); ?>"
                    aria-label="<?php esc_attr_e( 'إرسال', 'rk-my-children' ); ?>">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
            </button>
        </div>

    </div>
    <?php endif; ?>

</div><!-- /.rk-chat-wrap -->

<script>
/* Config dynamique (PHP) — le reste du JS est factorisé dans assets/js/rk-messages.js */
window.RK_CHAT_CFG = {
        ajaxUrl : <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>,
        nonce   : <?php echo wp_json_encode( $nonce ); ?>,
        childId : <?php echo (int) $child_id; ?>,
        viewerId: <?php echo (int) $child_wp_uid; ?>,
        coach   : {
            toId   : <?php echo (int) $coach_wp_uid; ?>,
            avatar : <?php echo wp_json_encode( $coach_avatar ); ?>,
            lastId : <?php echo (int) $last_coach; ?>
        },
        admin   : {
            toId   : <?php echo (int) $admin_wp_uid; ?>,
            avatar : <?php echo wp_json_encode( $admin_avatar ?? '' ); ?>,
            lastId : <?php echo (int) $last_admin; ?>
        }
};
</script>
<?php // JS factorisé — voir Modules/Children/assets/js/rk-messages.js
$_rk_js_rel = 'Modules/Children/assets/js/rk-messages.js';
printf( '<script src="%s"></script>', esc_url( RKP_URL . $_rk_js_rel . '?ver=' . (string) filemtime( RKP_DIR . $_rk_js_rel ) ) );
?>
