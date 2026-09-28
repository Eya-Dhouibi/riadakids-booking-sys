<?php
declare( strict_types=1 );
/**
 * RiadaKids — Dashboard enfant : أسئلة المدرب
 * Affiche les questions Q&A assignées par le coach à cet enfant.
 *
 * @package RK_My_Children
 * @since   9.1.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$child = RK_MC_Tutor_Dashboard::get_child_for_template();
if ( ! $child ) {
    echo '<p dir="rtl">' . esc_html__( 'يرجى تحديد الطفل أولاً.', 'rk-my-children' ) . '</p>';
    return;
}
$child_id = (int) $child->id;

$questions = class_exists( 'RKP_CoachMissionRepository' )
    ? RKP_CoachMissionRepository::find_qna_for_child( $child_id )
    : [];
?>
<style>
/* ── أسئلة المدرب ─────────────────────────────────────────────────── */
.rk-qa-page { font-family:Cairo,Tajawal,sans-serif; }
.rk-qa-page .rk-td-page-title {
    display:flex; align-items:center; gap:10px;
    font-size:1.2rem; font-weight:800; color:#0D1F35;
    margin:0 0 22px;
}
.rk-qa-empty {
    display:flex; flex-direction:column; align-items:center;
    padding:48px 20px; color:#94a3b8; text-align:center; gap:12px;
}
.rk-qa-empty__icon { font-size:3rem; }
.rk-qa-empty__text { font-size:.95rem; }
.rk-qa-list { display:flex; flex-direction:column; gap:16px; }
.rk-qa-card {
    background:#fff; border:1.5px solid #e8edf4; border-radius:14px;
    padding:18px 20px; box-shadow:0 2px 10px rgba(0,0,0,.05);
    transition:border-color .2s, box-shadow .2s;
}
.rk-qa-card:hover {
    border-color:var(--e-global-color-secondary,#1B4F8C);
    box-shadow:0 4px 16px rgba(27,79,140,.1);
}
.rk-qa-card__header {
    display:flex; align-items:flex-start; justify-content:space-between;
    gap:10px; margin-bottom:10px;
}
.rk-qa-card__meta { display:flex; flex-direction:column; gap:3px; }
.rk-qa-card__date {
    font-size:.76rem; color:#64748b;
}
.rk-qa-card__coach {
    font-size:.82rem; font-weight:700;
    color:var(--e-global-color-secondary,#1B4F8C);
}
.rk-qa-card__badge {
    font-size:.7rem; font-weight:700; padding:3px 10px; border-radius:20px;
    white-space:nowrap; flex-shrink:0;
}
.rk-qa-card__badge--new {
    background:#fef9c3; color:#92400e; border:1px solid #fde68a;
}
.rk-qa-card__badge--seen {
    background:#f0fdf4; color:#166534; border:1px solid #bbf7d0;
}
.rk-qa-card__question {
    font-size:.97rem; font-weight:700; color:#0D1F35;
    line-height:1.5; margin:0 0 8px;
}
.rk-qa-card__body {
    font-size:.88rem; color:#374151; line-height:1.7;
    margin:0; white-space:pre-wrap; word-break:break-word;
}
.rk-qa-card__pts {
    display:inline-flex; align-items:center; gap:4px;
    margin-top:10px; font-size:.78rem; font-weight:700;
    color:#f59e0b; background:#fffbeb; border:1px solid #fde68a;
    border-radius:20px; padding:2px 10px;
}
</style>

<div class="rk-qa-page rk-td-page-qa" dir="rtl">

    <h2 class="rk-td-page-title">
        <span style="font-size:1.3rem;">❓</span>
        <?php esc_html_e( 'أسئلة المدرب', 'rk-my-children' ); ?>
    </h2>

    <?php if ( empty( $questions ) ) : ?>
    <div class="rk-qa-empty">
        <span class="rk-qa-empty__icon">💬</span>
        <p class="rk-qa-empty__text">
            <?php esc_html_e( 'لا توجد أسئلة بعد — ستظهر هنا الأسئلة التي يرسلها لك مدربك', 'rk-my-children' ); ?>
        </p>
    </div>
    <?php else : ?>
    <div class="rk-qa-list">
        <?php foreach ( $questions as $q ) :
            $date_fmt  = ! empty( $q['assigned_at'] )
                         ? date_i18n( 'j F Y', strtotime( $q['assigned_at'] ) )
                         : '';
            $title     = trim( $q['title'] ?? '' );
            $body      = trim( $q['description'] ?? '' );
            $pts       = (int) ( $q['points'] ?? 0 );
            // coach_id → display name
            $coach_id  = (int) ( $q['coach_id'] ?? 0 );
            $coach_name = $coach_id ? get_the_author_meta( 'display_name', $coach_id ) : '';
            if ( ! $coach_name && $coach_id ) {
                $u = get_userdata( $coach_id );
                $coach_name = $u ? $u->display_name : '';
            }
            // "New" badge si moins de 48h
            $is_new = ! empty( $q['assigned_at'] )
                      && ( time() - strtotime( $q['assigned_at'] ) ) < 172800;
        ?>
        <div class="rk-qa-card">
            <div class="rk-qa-card__header">
                <div class="rk-qa-card__meta">
                    <?php if ( $date_fmt ) : ?>
                    <span class="rk-qa-card__date"><?php echo esc_html( $date_fmt ); ?></span>
                    <?php endif; ?>
                    <?php if ( $coach_name ) : ?>
                    <span class="rk-qa-card__coach"><?php echo esc_html( $coach_name ); ?></span>
                    <?php endif; ?>
                </div>
                <span class="rk-qa-card__badge <?php echo $is_new ? 'rk-qa-card__badge--new' : 'rk-qa-card__badge--seen'; ?>">
                    <?php echo $is_new ? esc_html__( 'جديد ✨', 'rk-my-children' ) : esc_html__( 'مقروء ✓', 'rk-my-children' ); ?>
                </span>
            </div>
            <?php if ( $title ) : ?>
            <p class="rk-qa-card__question"><?php echo esc_html( $title ); ?></p>
            <?php endif; ?>
            <?php if ( $body && $body !== $title ) : ?>
            <p class="rk-qa-card__body"><?php echo esc_html( $body ); ?></p>
            <?php endif; ?>
            <?php if ( $pts > 0 ) : ?>
            <span class="rk-qa-card__pts">⭐ <?php echo $pts; ?> <?php esc_html_e( 'نقاط', 'rk-my-children' ); ?></span>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

</div>