<?php
declare( strict_types=1 );
/**
 * RiadaKids — Dashboard enfant : اختباراتي
 * Affiche les quiz AYS Quiz Maker assignés par le(s) coach(es) à cet enfant.
 * Source : wp_aysquiz_quizes WHERE quiz_url = 'rk:child:{rk_children.id}'
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

$child_rk_id   = (int) $child->id;
$child_wp_uid  = (int) ( $child->wp_user_id ?? 0 );

/* ── Données via le Learning Engine (v2.6 — Quiz Flow) ─────────────
 * Plus de SQL dans le template : RKP_AysQuizRepository encapsule AYS.
 * Deux groupes façon grande plateforme : « À faire » / « Terminés ».
 * ─────────────────────────────────────────────────────────────────── */
/* v2.6.1 — Token de session enfant pour les liens quiz.
 * Sans lui, la page quiz s'ouvre sous le cookie PARENT et la tentative
 * est mal attribuée. Le token est déterministe par enfant : on peut le
 * (re)générer ici quel que soit le contexte (session enfant ou parent). */
$rk_tab_token = ( isset( $_GET['rk_tab'] ) && preg_match( '/^[a-f0-9]{40}$/', (string) $_GET['rk_tab'] ) ) // phpcs:ignore WordPress.Security.NonceVerification
    ? sanitize_text_field( wp_unslash( $_GET['rk_tab'] ) ) // phpcs:ignore WordPress.Security.NonceVerification
    : '';
if ( ! $rk_tab_token && $child_wp_uid && class_exists( 'RK_Session_Manager' ) ) {
    $rk_tab_token = RK_Session_Manager::create_tab_session( $child_wp_uid, (int) ( $child->user_id ?? 0 ) );
}

$rows = class_exists( 'RKP_AysQuizRepository' )
    ? RKP_AysQuizRepository::find_assigned_for_child( $child_rk_id, $child_wp_uid )
    : [];

/* v2.6.1 — Auto-réparation : si un quiz assigné paraît « non tenté » alors
 * que l'enfant l'a passé sous le mauvais user_id (cookie parent), on
 * réattribue et on recharge. Idempotent, sans effet quand tout est déjà lié. */
if ( $child_wp_uid && class_exists( 'RKP_QuizFlowCommandService' ) ) {
    $rk_repaired = false;
    foreach ( $rows as $rk_row ) {
        if ( empty( $rk_row->end_date )
            && RKP_QuizFlowCommandService::repair_for_child( $child_wp_uid, (int) $rk_row->quiz_id ) ) {
            $rk_repaired = true;
        }
    }
    if ( $rk_repaired ) {
        $rows = RKP_AysQuizRepository::find_assigned_for_child( $child_rk_id, $child_wp_uid );
        if ( class_exists( 'RK_MC_Child_Dashboard_Data' ) ) {
            RK_MC_Child_Dashboard_Data::clear_cache( $child_rk_id );
        }
    }
    unset( $rk_row, $rk_repaired );
}

$today   = current_time( 'Y-m-d' );
$pending = [];
$done    = [];

foreach ( $rows as $row ) {
    $opts          = json_decode( (string) ( $row->options ?? '{}' ), true ) ?: [];
    $passing_grade = (int) ( $opts['passing_grade'] ?? 80 );
    $due_date      = (string) ( $opts['rk_due_date'] ?? '' );
    $max_attempts  = max( 1, (int) ( $opts['rk_max_attempts'] ?? 1 ) );
    $attempts      = (int) ( $row->attempts_count ?? 0 );
    $attempted     = ! empty( $row->end_date );
    $score         = $attempted ? max( 0, min( 100, (int) $row->score ) ) : null;
    $passed        = ( null !== $score ) ? ( $score >= $passing_grade ) : null;
    $permalink     = $row->custom_post_id
                     ? (string) ( get_permalink( (int) $row->custom_post_id ) ?: '' )
                     : '';
    if ( $permalink && $rk_tab_token ) { // v2.6.1 — la tentative sera attribuée à l'ENFANT
        $permalink = add_query_arg( 'rk_tab', $rk_tab_token, $permalink );
    }

    $quiz = [
        'quiz_id'       => (int) $row->quiz_id,
        'quiz_title'    => (string) $row->quiz_title,
        'passing_grade' => $passing_grade,
        'attempted'     => $attempted,
        'score'         => $score,
        'corrects'      => $attempted ? (int) $row->corrects_count : null,
        'total'         => $attempted ? (int) $row->questions_count : null,
        'passed'        => $passed,
        'date'          => (string) ( $row->end_date ?? '' ),
        'permalink'     => $permalink,
        'due_date'      => $due_date,
        'is_overdue'    => ( $due_date && $due_date < $today && ! $attempted ),
        'attempts'      => $attempts,
        'max_attempts'  => $max_attempts,
        'can_retake'    => ( $attempted && false === $passed && $attempts < $max_attempts && $permalink ),
    ];

    if ( $attempted ) { $done[] = $quiz; } else { $pending[] = $quiz; }
}

// À faire : en retard d'abord, puis échéance la plus proche.
usort( $pending, static function ( $a, $b ) {
    if ( $a['is_overdue'] !== $b['is_overdue'] ) return $a['is_overdue'] ? -1 : 1;
    return strcmp( $a['due_date'] ?: '9999', $b['due_date'] ?: '9999' );
} );

$quizzes = array_merge( $pending, $done ); // compat : compte total existant
?>
<style>
/* ── اختباراتي — Quiz Maker child page ──────────────────────────── */
.rk-quiz-page { font-family:Cairo,Tajawal,sans-serif; }
.rk-quiz-page .rk-td-page-title {
    display:flex; align-items:center; gap:10px;
    font-size:1.2rem; font-weight:800; color:#0D1F35; margin:0 0 22px;
}
.rk-quiz-empty {
    display:flex; flex-direction:column; align-items:center;
    padding:48px 20px; color:#94a3b8; text-align:center; gap:12px;
}
.rk-quiz-empty__icon { font-size:3rem; }
.rk-quiz-empty__text { font-size:.95rem; }

/* v2.7.1 — grille responsive : 4 colonnes desktop → 3 → 2 → 1 mobile */
.rk-quiz-list {
    display:grid;
    grid-template-columns:repeat(4, minmax(0, 1fr));
    gap:16px;
    align-items:stretch;
}
@media (max-width:1280px) { .rk-quiz-list { grid-template-columns:repeat(3, minmax(0, 1fr)); } }
@media (max-width:900px)  { .rk-quiz-list { grid-template-columns:repeat(2, minmax(0, 1fr)); } }
@media (max-width:560px)  { .rk-quiz-list { grid-template-columns:1fr; } }

.rk-quiz-card {
    background:#fff; border:1.5px solid #e8edf4; border-radius:14px;
    padding:18px 20px; box-shadow:0 2px 10px rgba(0,0,0,.05);
    transition:border-color .2s, box-shadow .2s;
    /* v2.7.1 — cartes de même hauteur dans la grille, footer plaqué en bas */
    display:flex; flex-direction:column; gap:12px;
    min-width:0; /* les titres longs tronquent au lieu de casser la grille */
}
.rk-quiz-card__title { min-width:0; overflow:hidden; text-overflow:ellipsis; }
.rk-quiz-card__header { flex-wrap:wrap; }
.rk-quiz-card__footer { margin-top:auto; }
.rk-quiz-card:hover {
    border-color:var(--e-global-color-secondary,#1B4F8C);
    box-shadow:0 4px 16px rgba(27,79,140,.1);
}
.rk-quiz-card__header {
    display:flex; align-items:flex-start; justify-content:space-between;
    gap:12px; margin-bottom:14px;
}
.rk-quiz-card__title {
    font-size:1rem; font-weight:800; color:#0D1F35; line-height:1.4; flex:1;
}
.rk-quiz-card__status {
    font-size:.72rem; font-weight:700; padding:4px 12px;
    border-radius:20px; white-space:nowrap; flex-shrink:0;
}
.rk-quiz-card__status--pending {
    background:#fef9c3; color:#92400e; border:1px solid #fde68a;
}
.rk-quiz-card__status--passed {
    background:#f0fdf4; color:#166534; border:1px solid #bbf7d0;
}
.rk-quiz-card__status--failed {
    background:#fff1f2; color:#9f1239; border:1px solid #fecdd3;
}

/* Score donut + stats */
.rk-quiz-card__result {
    display:flex; align-items:center; gap:16px; margin-bottom:14px;
}
.rk-quiz-card__donut {
    position:relative; width:68px; height:68px; flex-shrink:0;
}
.rk-quiz-card__donut svg { transform:rotate(-90deg); }
.rk-quiz-card__donut-text {
    position:absolute; inset:0; display:flex; flex-direction:column;
    align-items:center; justify-content:center; line-height:1.1;
}
.rk-quiz-card__donut-score { font-size:1.05rem; font-weight:800; color:#0D1F35; }
.rk-quiz-card__donut-label { font-size:.6rem; color:#94a3b8; }
.rk-quiz-card__stats { display:flex; flex-direction:column; gap:4px; }
.rk-quiz-card__stat {
    font-size:.82rem; color:#374151; display:flex; align-items:center; gap:5px;
}
.rk-quiz-card__stat-icon { font-size:.9rem; }

/* Progress bar pass threshold */
.rk-quiz-card__bar-wrap {
    position:relative; height:8px; background:#f1f5f9;
    border-radius:10px; overflow:visible; margin-bottom:14px;
}
.rk-quiz-card__bar-fill {
    height:100%; border-radius:10px; transition:width .5s ease;
}
.rk-quiz-card__bar-fill--passed { background:linear-gradient(90deg,#22c55e,#4ade80); }
.rk-quiz-card__bar-fill--failed { background:linear-gradient(90deg,var(--e-global-color-primary,#FF4411),#fb923c); }
.rk-quiz-card__bar-fill--pending { background:#e2e8f0; }
.rk-quiz-card__threshold {
    position:absolute; top:-3px; bottom:-3px; width:2px;
    background:#64748b; border-radius:2px;
}
.rk-quiz-card__threshold::after {
    content:attr(data-label); position:absolute; top:-18px; left:50%;
    transform:translateX(-50%); font-size:.6rem; color:#64748b;
    white-space:nowrap; font-family:Cairo,Tajawal,sans-serif;
}

/* CTA button */
.rk-quiz-card__footer { display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap; }
.rk-quiz-card__date { font-size:.76rem; color:#94a3b8; }
.rk-quiz-card__btn {
    display:inline-flex; align-items:center; gap:6px; font-size:.84rem;
    font-weight:700; padding:8px 18px; border-radius:10px; text-decoration:none;
    transition:opacity .2s; font-family:Cairo,Tajawal,sans-serif;
}
.rk-quiz-card__btn--start {
    background:var(--e-global-color-secondary,#1B4F8C); color:#fff;
}
.rk-quiz-card__btn--retry {
    background:#f1f5f9; color:#334155; border:1px solid #e2e8f0;
}
.rk-quiz-card__btn:hover { opacity:.85; }
.rk-quiz-card__btn--disabled {
    background:#f1f5f9; color:#94a3b8; cursor:default; pointer-events:none;
}

/* ── v2.6 Quiz Flow — sections & échéances ─────────────────────── */
.rk-quiz-section { margin:0 0 26px; }
.rk-quiz-section__title {
    display:flex; align-items:center; gap:8px;
    font-size:1rem; font-weight:800; color:#0D1F35; margin:0 0 12px;
}
.rk-quiz-section__count {
    background:var(--e-global-color-primary,#FF4411); color:#fff;
    border-radius:20px; padding:1px 10px; font-size:.75rem; font-weight:800;
}
.rk-quiz-section--done .rk-quiz-section__count { background:#22c55e; }
.rk-quiz-card--pending { border-color:#fbd38d; background:#fffdf9; }
.rk-quiz-card--overdue { border-color:#fca5a5; background:#fff7f7; }
.rk-quiz-chip {
    display:inline-flex; align-items:center; gap:5px;
    border-radius:20px; padding:3px 11px; font-size:.72rem; font-weight:700;
}
.rk-quiz-chip--due     { background:#fef3c7; color:#92400e; }
.rk-quiz-chip--overdue { background:#fee2e2; color:#991b1b; }
.rk-quiz-chip--tries   { background:#e0f2fe; color:#075985; }
.rk-quiz-card__cta {
    display:inline-flex; align-items:center; gap:7px;
    background:linear-gradient(120deg,var(--e-global-color-primary,#FF4411),#fb923c);
    color:#fff !important; border-radius:25px; padding:10px 22px;
    font-weight:800; font-size:.9rem; text-decoration:none;
    box-shadow:0 4px 12px rgba(255,68,17,.28); transition:transform .15s;
}
.rk-quiz-card__cta:hover { transform:translateY(-2px); }
.rk-quiz-card__retake {
    display:inline-flex; align-items:center; gap:6px;
    border:1.5px solid var(--e-global-color-primary,#FF4411);
    color:var(--e-global-color-primary,#FF4411) !important;
    border-radius:25px; padding:7px 18px; font-weight:700; font-size:.82rem;
    text-decoration:none; margin-top:10px;
}
</style>

<div class="rk-quiz-page rk-td-page-quiz" dir="rtl">

    <h2 class="rk-td-page-title">
        <span style="font-size:1.3rem;">📝</span>
        <?php esc_html_e( 'اختباراتي', 'rk-my-children' ); ?>
    </h2>

    <?php if ( empty( $quizzes ) ) : ?>
    <div class="rk-quiz-empty">
        <span class="rk-quiz-empty__icon">🧩</span>
        <p class="rk-quiz-empty__text">
            <?php esc_html_e( 'لا توجد اختبارات بعد — ستظهر هنا الاختبارات التي يرسلها لك مدربك', 'rk-my-children' ); ?>
        </p>
    </div>
    <?php else : ?>
    <?php foreach ( array(
        'pending' => array( '⏳', esc_html__( 'اختبارات بانتظارك', 'rk-my-children' ), $pending ),
        'done'    => array( '🏁', esc_html__( 'اختبارات منجزة',   'rk-my-children' ), $done ),
    ) as $sec_key => $sec ) :
        list( $sec_icon, $sec_label, $sec_items ) = $sec;
        if ( empty( $sec_items ) ) continue; ?>
    <div class="rk-quiz-section rk-quiz-section--<?php echo esc_attr( $sec_key ); ?>">
        <h3 class="rk-quiz-section__title">
            <?php echo $sec_icon; ?> <?php echo $sec_label; ?>
            <span class="rk-quiz-section__count"><?php echo count( $sec_items ); ?></span>
        </h3>
        <div class="rk-quiz-list">
        <?php foreach ( $sec_items as $quiz ) :
            $attempted     = $quiz['attempted'];
            $score         = $quiz['score'];
            $passed        = $quiz['passed'];
            $total         = $quiz['total'];
            $corrects      = $quiz['corrects'];
            $passing_grade = $quiz['passing_grade'];
            $permalink     = $quiz['permalink'];

            /* v2.6 Quiz Flow */
            $due_date     = $quiz['due_date'];
            $is_overdue   = $quiz['is_overdue'];
            $can_retake   = $quiz['can_retake'];
            $attempts     = $quiz['attempts'];
            $max_attempts = $quiz['max_attempts'];
            $card_mod     = ! $attempted ? ( $is_overdue ? ' rk-quiz-card--overdue' : ' rk-quiz-card--pending' ) : '';
            $due_fmt      = $due_date ? date_i18n( 'j F', strtotime( $due_date ) ) : '';

            /* Badge status */
            if ( ! $attempted ) {
                $status_class = 'rk-quiz-card__status--pending';
                $status_label = esc_html__( 'لم يُحاول بعد ⏳', 'rk-my-children' );
            } elseif ( $passed ) {
                $status_class = 'rk-quiz-card__status--passed';
                $status_label = esc_html__( 'ناجح ✅', 'rk-my-children' );
            } else {
                $status_class = 'rk-quiz-card__status--failed';
                $status_label = esc_html__( 'لم ينجح بعد 💪', 'rk-my-children' );
            }

            /* Bar fill */
            $bar_pct   = $attempted ? min( 100, (int) $score ) : 0;
            $bar_class = ! $attempted ? 'rk-quiz-card__bar-fill--pending'
                       : ( $passed   ? 'rk-quiz-card__bar-fill--passed'
                                     : 'rk-quiz-card__bar-fill--failed' );

            /* Date */
            $date_fmt = $attempted && ! empty( $quiz['date'] )
                        ? date_i18n( 'j F Y', strtotime( $quiz['date'] ) )
                        : '';

            /* Donut SVG */
            $radius     = 28;
            $circ       = round( 2 * M_PI * $radius, 2 );
            $dash_fill  = $attempted ? round( $bar_pct / 100 * $circ, 2 ) : 0;
            $donut_color = ! $attempted ? '#e2e8f0' : ( $passed ? '#22c55e' : '#ef4444' );
        ?>
        <div class="rk-quiz-card<?php echo esc_attr( $card_mod ); ?>">

            <!-- Header: title + status badge -->
            <div class="rk-quiz-card__header">
                <h3 class="rk-quiz-card__title"><?php echo esc_html( $quiz['quiz_title'] ); ?></h3>
                <span class="rk-quiz-card__status <?php echo esc_attr( $status_class ); ?>">
                    <?php echo $status_label; ?>
                </span>
                <?php if ( ! $attempted && $due_fmt ) : ?>
                <span class="rk-quiz-chip <?php echo $is_overdue ? 'rk-quiz-chip--overdue' : 'rk-quiz-chip--due'; ?>">
                    <?php echo $is_overdue
                        ? '⏰ ' . esc_html__( 'متأخر!', 'rk-my-children' ) . ' ' . esc_html( $due_fmt )
                        : '📅 ' . esc_html__( 'آخر أجل:', 'rk-my-children' ) . ' ' . esc_html( $due_fmt ); ?>
                </span>
                <?php endif; ?>
                <?php if ( $max_attempts > 1 ) : ?>
                <span class="rk-quiz-chip rk-quiz-chip--tries">
                    🔁 <?php printf( esc_html__( 'المحاولات: %1$d/%2$d', 'rk-my-children' ), (int) $attempts, (int) $max_attempts ); ?>
                </span>
                <?php endif; ?>
            </div>

            <!-- Result (if attempted) -->
            <?php if ( $attempted ) : ?>
            <div class="rk-quiz-card__result">
                <!-- Donut -->
                <div class="rk-quiz-card__donut">
                    <svg width="68" height="68" viewBox="0 0 68 68">
                        <circle cx="34" cy="34" r="<?php echo $radius; ?>"
                                fill="none" stroke="#f1f5f9" stroke-width="8"/>
                        <circle cx="34" cy="34" r="<?php echo $radius; ?>"
                                fill="none"
                                stroke="<?php echo esc_attr( $donut_color ); ?>"
                                stroke-width="8"
                                stroke-dasharray="<?php echo $dash_fill . ' ' . $circ; ?>"
                                stroke-linecap="round"/>
                    </svg>
                    <div class="rk-quiz-card__donut-text">
                        <span class="rk-quiz-card__donut-score"><?php echo $score; ?>%</span>
                        <span class="rk-quiz-card__donut-label"><?php esc_html_e( 'النتيجة', 'rk-my-children' ); ?></span>
                    </div>
                </div>
                <!-- Stats -->
                <div class="rk-quiz-card__stats">
                    <?php if ( $total ) : ?>
                    <span class="rk-quiz-card__stat">
                        <span class="rk-quiz-card__stat-icon">✅</span>
                        <?php printf(
                            esc_html__( '%1$d من %2$d إجابة صحيحة', 'rk-my-children' ),
                            (int) $corrects,
                            (int) $total
                        ); ?>
                    </span>
                    <?php endif; ?>
                    <span class="rk-quiz-card__stat">
                        <span class="rk-quiz-card__stat-icon">🎯</span>
                        <?php printf(
                            esc_html__( 'نسبة النجاح المطلوبة: %d%%', 'rk-my-children' ),
                            $passing_grade
                        ); ?>
                    </span>
                </div>
            </div>

            <!-- Progress bar with threshold marker -->
            <div class="rk-quiz-card__bar-wrap">
                <div class="rk-quiz-card__bar-fill <?php echo esc_attr( $bar_class ); ?>"
                     style="width:<?php echo $bar_pct; ?>%;"></div>
                <div class="rk-quiz-card__threshold"
                     style="left:<?php echo $passing_grade; ?>%;"
                     data-label="<?php echo esc_attr( $passing_grade . '%' ); ?>"></div>
            </div>
            <?php endif; ?>

            <!-- Footer: date + CTA -->
            <div class="rk-quiz-card__footer">
                <?php if ( $date_fmt ) : ?>
                <span class="rk-quiz-card__date">
                    📅 <?php echo esc_html( $date_fmt ); ?>
                </span>
                <?php else : ?>
                <span></span>
                <?php endif; ?>

                <?php if ( $permalink ) : ?>
                    <?php if ( ! $attempted ) : ?>
                    <a href="<?php echo esc_url( $permalink ); ?>"
                       class="rk-quiz-card__cta"
                       target="_blank" rel="noopener">
                        🚀 <?php esc_html_e( 'ابدأ الاختبار', 'rk-my-children' ); ?>
                    </a>
                    <?php elseif ( $can_retake ) : // v2.6 — uniquement si la politique du coach l'autorise ?>
                    <a href="<?php echo esc_url( $permalink ); ?>"
                       class="rk-quiz-card__retake"
                       target="_blank" rel="noopener">
                        🔄 <?php printf( esc_html__( 'إعادة المحاولة (%d متبقية)', 'rk-my-children' ), (int) ( $max_attempts - $attempts ) ); ?>
                    </a>
                    <?php else : ?>
                    <span></span>
                    <?php endif; ?>
                <?php else : ?>
                <span class="rk-quiz-card__btn rk-quiz-card__btn--disabled">
                    🔒 <?php esc_html_e( 'الرابط غير متاح', 'rk-my-children' ); ?>
                </span>
                <?php endif; ?>
            </div>

        </div>
        <?php endforeach; ?>
        </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>

</div>
