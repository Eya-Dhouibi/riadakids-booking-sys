<?php
declare( strict_types=1 );
/**
 * Partial : carte اختباراتي (quiz card — item 8) du dashboard home.
 * Extrait de dashboard.php — include() partage la portée des variables :
 * comportement strictement identique.
 */
if ( ! defined( 'ABSPATH' ) ) exit;
?>
<!-- اختباراتي (quiz card — item 8) -->
<?php
// Stats + liste via RKP_AysQuizRepository — même source que my-quiz-attempts.php
$_ays_done = 0; $_ays_avg = 0; $_ays_best = 0; $_ays_pending = 0;
$_ays_list = [];

// Fallback : en session enfant (?rk_tab), $child['id'] n'existe pas → $_cid = 0.
if ( ! $_cid && class_exists( 'RK_MC_Tutor_Dashboard' ) ) {
    $_rk_child_obj = RK_MC_Tutor_Dashboard::get_child_for_template();
    $_cid = $_rk_child_obj ? (int) $_rk_child_obj->id : 0;
}

if ( $_cid && class_exists( 'RKP_AysQuizRepository' ) ) {
    global $wpdb;
    $_ays_uid = isset( $_rk_child_obj->wp_user_id )
        ? (int) $_rk_child_obj->wp_user_id
        : (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT wp_user_id FROM {$wpdb->prefix}rk_children WHERE id = %d LIMIT 1", $_cid
        ) );

    $_ays_rows = RKP_AysQuizRepository::find_assigned_for_child( $_cid, $_ays_uid );

    // v2.6.1 — auto-réparation : tentatives enregistrées sous le cookie PARENT.
    if ( $_ays_uid && class_exists( 'RKP_QuizFlowCommandService' ) ) {
        $_ays_fix = false;
        foreach ( $_ays_rows as $_ays_r ) {
            if ( empty( $_ays_r->end_date )
                && RKP_QuizFlowCommandService::repair_for_child( $_ays_uid, (int) $_ays_r->quiz_id ) ) {
                $_ays_fix = true;
            }
        }
        if ( $_ays_fix ) {
            $_ays_rows = RKP_AysQuizRepository::find_assigned_for_child( $_cid, $_ays_uid );
        }
    }

    $_ays_scores = [];
    foreach ( $_ays_rows as $_ays_r ) {
        $_attempted = ! empty( $_ays_r->end_date );
        $_score     = $_attempted ? max( 0, min( 100, (int) $_ays_r->score ) ) : null;

        if ( $_attempted ) {
            $_ays_done++;
            $_ays_scores[] = $_score;
        } else {
            $_ays_pending++;
        }

        $_ays_list[] = [
            'title'     => (string) $_ays_r->quiz_title,
            'attempted' => $_attempted,
            'score'     => $_score,
        ];
    }
    if ( $_ays_scores ) {
        $_ays_avg  = (int) round( array_sum( $_ays_scores ) / count( $_ays_scores ) );
        $_ays_best = max( $_ays_scores );
    }
    // Max 4 items dans la carte — le reste via « عرض الكل ».
    $_ays_list = array_slice( $_ays_list, 0, 4 );
    unset( $_ays_rows, $_ays_r, $_ays_scores, $_ays_fix, $_attempted, $_score );
}
?>
<div class="rkd3-card rkd3-quiz-card">
    <div class="rkd3-card__head">
        <span class="rkd3-card__head-emoji">📝</span>
        <h2>اختباراتي</h2>
        <a href="<?php echo esc_url( $_nb . 'my-quiz-attempts/' . $_cp ); ?>" class="rkd3-card__see-all">عرض الكل</a>
    </div>
    <div class="rkd3-quiz-stats">
        <div class="rkd3-quiz-stat">
            <span class="rkd3-quiz-stat__icon">✅</span>
            <div class="rkd3-quiz-stat__body">
                <div class="rkd3-quiz-stat__val"><?php echo (int) $_ays_done; ?></div>
                <div class="rkd3-quiz-stat__label">اختبار مكتمل</div>
            </div>
        </div>
        <div class="rkd3-quiz-stat">
            <span class="rkd3-quiz-stat__icon">🎯</span>
            <div class="rkd3-quiz-stat__body">
                <div class="rkd3-quiz-stat__val"><?php echo (int) $_ays_avg; ?>%</div>
                <div class="rkd3-quiz-stat__label">متوسط النتائج</div>
            </div>
        </div>
        <?php if ( $_ays_best > 0 ) : ?>
        <div class="rkd3-quiz-stat">
            <span class="rkd3-quiz-stat__icon">🏆</span>
            <div class="rkd3-quiz-stat__body">
                <div class="rkd3-quiz-stat__val"><?php echo (int) $_ays_best; ?>%</div>
                <div class="rkd3-quiz-stat__label">أفضل نتيجة</div>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <?php if ( ! empty( $_ays_list ) ) : ?>
    <ul class="rkd3-quiz-list">
        <?php foreach ( $_ays_list as $_qi ) : ?>
        <li class="rkd3-quiz-list__item <?php echo $_qi['attempted'] ? 'is-done' : 'is-pending'; ?>">
            <span class="rkd3-quiz-list__title"><?php echo esc_html( $_qi['title'] ); ?></span>
            <?php if ( $_qi['attempted'] ) : ?>
                <span class="rkd3-quiz-list__badge"><?php echo (int) $_qi['score']; ?>%</span>
            <?php else : ?>
                <span class="rkd3-quiz-list__badge rkd3-quiz-list__badge--pending">لم يُحل بعد</span>
            <?php endif; ?>
        </li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
    <a href="<?php echo esc_url( $_nb . 'my-quiz-attempts/' . $_cp ); ?>" class="rkd3-btn rkd3-btn--outline rkd3-btn--block" style="margin-top:14px;">
        <?php echo $_ays_pending > 0
            ? 'أكمل اختباراتك (' . (int) $_ays_pending . ')'
            : 'ابدأ اختباراً جديداً'; ?>
    </a>
</div>
