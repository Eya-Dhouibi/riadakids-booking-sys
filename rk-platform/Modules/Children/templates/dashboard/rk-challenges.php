<?php
declare( strict_types=1 );
/**
 * RiadaKids — Dashboard enfant : تحدياتي (Mes défis)
 *
 * Affiche les tests/quiz créés par le(s) coach(es) pour l'enfant — même
 * source de données que my-quiz-attempts.php (RKP_AysQuizRepository),
 * enrichie du nom/avatar du coach auteur de chaque test. Design aligné
 * sur la maquette Figma fournie par l'utilisateur (13/08/2026) : badge
 * XP, avatar coach, filtre pilule المكتملة/غير المكتملة.
 *
 * Slug : 'rk-challenges', routé automatiquement par nom de fichier
 * (voir trait-rk-mc-tutor-dash-nav.php) — ajouté à $rk_slugs dans
 * class-rk-mc-tutor-dashboard.php.
 *
 * @package RK_My_Children
 * @since   9.52.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$child = RK_MC_Tutor_Dashboard::get_child_for_template();
if ( ! $child ) {
    echo '<p dir="rtl">' . esc_html__( 'يرجى تحديد الطفل أولاً.', 'rk-my-children' ) . '</p>';
    return;
}

$child_rk_id  = (int) $child->id;
$child_wp_uid = (int) ( $child->wp_user_id ?? 0 );

/* ── Token de session enfant pour les liens de test (même logique que
 * my-quiz-attempts.php — sans lui, la tentative est mal attribuée). ── */
$rk_tab_token = ( isset( $_GET['rk_tab'] ) && preg_match( '/^[a-f0-9]{40}$/', (string) $_GET['rk_tab'] ) ) // phpcs:ignore WordPress.Security.NonceVerification
    ? sanitize_text_field( wp_unslash( $_GET['rk_tab'] ) ) // phpcs:ignore WordPress.Security.NonceVerification
    : '';
if ( ! $rk_tab_token && $child_wp_uid && class_exists( 'RK_Session_Manager' ) ) {
    $rk_tab_token = RK_Session_Manager::create_tab_session( $child_wp_uid, (int) ( $child->user_id ?? 0 ) );
}

$rows = class_exists( 'RKP_AysQuizRepository' )
    ? RKP_AysQuizRepository::find_assigned_for_child( $child_rk_id, $child_wp_uid )
    : [];

// Auto-réparation (même mécanisme que my-quiz-attempts.php).
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

/* ── Cache local des coachs déjà résolus (évite N requêtes get_userdata
 * pour N tests du même coach). ── */
$rk_coach_cache = [];
if ( ! function_exists( 'rk_challenges_resolve_coach' ) ) {
function rk_challenges_resolve_coach( int $custom_post_id, array &$cache ): array {
    if ( ! $custom_post_id ) {
        return [ 'name' => '', 'avatar' => '' ];
    }
    $author_id = (int) get_post_field( 'post_author', $custom_post_id );
    if ( ! $author_id ) {
        return [ 'name' => '', 'avatar' => '' ];
    }
    if ( isset( $cache[ $author_id ] ) ) {
        return $cache[ $author_id ];
    }
    $user = get_userdata( $author_id );
    $out  = $user
        ? [ 'name' => $user->display_name, 'avatar' => get_avatar_url( $author_id, [ 'size' => 64 ] ) ]
        : [ 'name' => '', 'avatar' => '' ];
    $cache[ $author_id ] = $out;
    return $out;
}
}

$challenges = [];
foreach ( $rows as $row ) {
    $opts          = json_decode( (string) ( $row->options ?? '{}' ), true ) ?: [];
    $passing_grade = (int) ( $opts['passing_grade'] ?? 80 );
    $attempted     = ! empty( $row->end_date );
    $score         = $attempted ? max( 0, min( 100, (int) $row->score ) ) : null;
    $custom_post_id = (int) ( $row->custom_post_id ?? 0 );
    // v9.56 (14/08/2026) — Remplace le lien externe /ays-quiz-maker/
    // par la nouvelle page interne rk-quiz-play.php (intégrée au
    // contenu du dashboard, maquette Figma fournie par l'utilisateur).
    $permalink = RKP_LearningQueryService::get_dashboard_url( 'rk-quiz-play' );
    $permalink = add_query_arg( 'quiz_id', (int) $row->quiz_id, $permalink );
    if ( $rk_tab_token ) {
        $permalink = add_query_arg( 'rk_tab', $rk_tab_token, $permalink );
    }
    $coach = rk_challenges_resolve_coach( $custom_post_id, $rk_coach_cache );

    // XP : pas de colonne dédiée côté AYS — dérivé du nombre de
    // questions (cohérent avec la maquette "20 XP" / "30 XP" pour des
    // tests de 4-10 questions), filtrable si un vrai champ est ajouté
    // plus tard côté coach.
    $questions_count = $attempted ? (int) $row->questions_count : (int) ( $opts['questions_count'] ?? 5 );
    $xp = (int) apply_filters( 'rk_challenge_xp', max( 10, $questions_count * 5 ), $row );

    $challenges[] = [
        'quiz_id'    => (int) $row->quiz_id,
        'title'      => (string) $row->quiz_title,
        'questions'  => $questions_count,
        'xp'         => $xp,
        'attempted'  => $attempted,
        'score'      => $score,
        'passed'     => ( null !== $score ) ? ( $score >= $passing_grade ) : null,
        'permalink'  => $permalink,
        'coach_name' => $coach['name'],
        'coach_avatar' => $coach['avatar'],
    ];
}

$done_count    = count( array_filter( $challenges, static fn( $c ) => $c['attempted'] ) );
$pending_count = count( $challenges ) - $done_count;
?>
<style>
.rk-chal-page { font-family:Cairo,Tajawal,sans-serif; direction:rtl; }
.rk-chal-page .rk-td-page-title {
    display:flex; align-items:center; gap:10px;
    font-size:1.2rem; font-weight:800; color:#0D1F35; margin:0 0 18px;
}

/* ── Filtre pilule المكتملة / غير المكتملة ── */
.rk-chal-tabs {
    display:inline-flex; background:#f1f5f9; border-radius:50px; padding:4px;
    gap:4px; margin-bottom:22px;
}
.rk-chal-tab {
    border:none; background:transparent; cursor:pointer;
    padding:8px 18px; border-radius:50px; font-size:.82rem; font-weight:700;
    color:#64748b; font-family:inherit; display:inline-flex; align-items:center; gap:6px;
    transition:background .15s, color .15s;
}
.rk-chal-tab.is-active {
    background:#fff; color:#0D1F35; box-shadow:0 1px 4px rgba(0,0,0,.08);
}
.rk-chal-tab.is-active[data-tab="done"] { color:#166534; }
.rk-chal-tab.is-active[data-tab="pending"] { color:#c2410c; }

/* ── Grille de cartes ── */
.rk-chal-grid {
    display:grid; grid-template-columns:repeat(3, minmax(0,1fr)); gap:16px;
}
@media (max-width:1100px) { .rk-chal-grid { grid-template-columns:repeat(2, minmax(0,1fr)); } }
@media (max-width:640px)  { .rk-chal-grid { grid-template-columns:1fr; } }

.rk-chal-card {
    background:#fff; border:1.5px solid #e8edf4; border-radius:16px;
    padding:16px; display:flex; flex-direction:column; gap:10px;
    box-shadow:0 2px 10px rgba(0,0,0,.04); transition:box-shadow .2s, border-color .2s;
}
.rk-chal-card:hover { border-color:#4C95D7; box-shadow:0 6px 18px rgba(27,79,140,.08); }
.rk-chal-card.is-done { opacity:.85; }

.rk-chal-card__head {
    display:flex; align-items:center; justify-content:space-between; gap:8px;
}
.rk-chal-card__xp {
    background:#fef9c3; color:#854d0e; font-size:.72rem; font-weight:800;
    padding:3px 10px; border-radius:20px; white-space:nowrap;
}
.rk-chal-card__type-icon {
    width:26px; height:26px; border-radius:8px; background:#eef3ff;
    display:flex; align-items:center; justify-content:center; font-size:.9rem; flex-shrink:0;
}

.rk-chal-card__title { font-size:.95rem; font-weight:800; color:#0D1F35; line-height:1.4; margin:0; }
.rk-chal-card__meta { font-size:.78rem; color:#94a3b8; margin:0; }

.rk-chal-card__coach {
    display:flex; align-items:center; gap:8px; margin-top:2px;
}
.rk-chal-card__coach img {
    width:26px; height:26px; border-radius:50%; object-fit:cover; flex-shrink:0;
}
.rk-chal-card__coach span { font-size:.78rem; color:#64748b; }

.rk-chal-card__score {
    display:flex; align-items:center; gap:6px; font-size:.8rem; font-weight:700;
}
.rk-chal-card__score.is-passed { color:#166534; }
.rk-chal-card__score.is-failed { color:#9f1239; }

.rk-chal-card__btn {
    display:flex; align-items:center; justify-content:center; gap:6px;
    margin-top:auto; padding:11px; border-radius:12px; text-decoration:none;
    font-size:.86rem; font-weight:700; font-family:inherit; transition:opacity .15s;
    border:none; cursor:pointer;
}
.rk-chal-card__btn--start {
    background:var(--e-global-color-primary,#FF4411); color:#fff !important;
}
.rk-chal-card__btn--start:hover { opacity:.9; }
.rk-chal-card__btn--done {
    background:#f0fdf4; color:#166534 !important; border:1px solid #bbf7d0;
}
.rk-chal-card__btn--disabled {
    background:#f1f5f9; color:#94a3b8 !important; pointer-events:none;
}

.rk-chal-empty {
    display:flex; flex-direction:column; align-items:center; gap:10px;
    padding:52px 20px; color:#94a3b8; text-align:center; grid-column:1/-1;
}
.rk-chal-empty__icon { font-size:2.6rem; }
</style>

<div class="rk-chal-page" data-rk-challenges>
    <h1 class="rk-td-page-title">
        <span aria-hidden="true">🏅</span>
        <?php esc_html_e( 'تحدياتي', 'rk-my-children' ); ?>
    </h1>

    <div class="rk-chal-tabs" role="tablist">
        <button type="button" class="rk-chal-tab is-active" data-tab="pending" role="tab">
            <?php esc_html_e( 'غير مكتملة', 'rk-my-children' ); ?>
            <?php if ( $pending_count > 0 ) : ?>(<?php echo (int) $pending_count; ?>)<?php endif; ?>
        </button>
        <button type="button" class="rk-chal-tab" data-tab="done" role="tab">
            <?php esc_html_e( 'المكتملة', 'rk-my-children' ); ?>
            <?php if ( $done_count > 0 ) : ?>(<?php echo (int) $done_count; ?>)<?php endif; ?>
        </button>
    </div>

    <?php if ( empty( $challenges ) ) : ?>
    <div class="rk-chal-grid">
        <div class="rk-chal-empty">
            <span class="rk-chal-empty__icon" aria-hidden="true">🎯</span>
            <p><?php esc_html_e( 'لا توجد تحديات بعد — سيرسل لك مدربك تحديات جديدة قريباً!', 'rk-my-children' ); ?></p>
        </div>
    </div>
    <?php else : ?>
    <div class="rk-chal-grid" data-rk-challenges-grid>
        <?php foreach ( $challenges as $c ) : ?>
        <div class="rk-chal-card<?php echo $c['attempted'] ? ' is-done' : ''; ?>"
             data-rk-challenge-card
             data-status="<?php echo $c['attempted'] ? 'done' : 'pending'; ?>">
            <div class="rk-chal-card__head">
                <span class="rk-chal-card__type-icon" aria-hidden="true">📝</span>
                <span class="rk-chal-card__xp">
                    <?php printf( esc_html__( '%d نقطة XP', 'rk-my-children' ), (int) $c['xp'] ); ?>
                </span>
            </div>

            <p class="rk-chal-card__title"><?php echo esc_html( $c['title'] ); ?></p>
            <p class="rk-chal-card__meta">
                <?php printf( esc_html__( '%d أسئلة', 'rk-my-children' ), (int) $c['questions'] ); ?>
            </p>

            <?php if ( $c['coach_name'] ) : ?>
            <div class="rk-chal-card__coach">
                <?php if ( $c['coach_avatar'] ) : ?>
                <img src="<?php echo esc_url( $c['coach_avatar'] ); ?>" alt="" loading="lazy">
                <?php endif; ?>
                <span><?php printf( esc_html__( 'المدرب %s', 'rk-my-children' ), esc_html( $c['coach_name'] ) ); ?></span>
            </div>
            <?php endif; ?>

            <?php if ( $c['attempted'] ) : ?>
            <span class="rk-chal-card__score<?php echo $c['passed'] ? ' is-passed' : ' is-failed'; ?>">
                <?php echo $c['passed'] ? '✅' : '⚠️'; ?>
                <?php printf( esc_html__( 'النتيجة: %d%%', 'rk-my-children' ), (int) $c['score'] ); ?>
            </span>
            <?php endif; ?>

            <?php if ( ! $c['attempted'] && $c['permalink'] ) : ?>
            <a href="<?php echo esc_url( $c['permalink'] ); ?>" class="rk-chal-card__btn rk-chal-card__btn--start">
                <?php esc_html_e( 'ابدأ التحدي', 'rk-my-children' ); ?>
            </a>
            <?php elseif ( $c['attempted'] ) : ?>
            <span class="rk-chal-card__btn rk-chal-card__btn--done">
                <?php esc_html_e( 'تم الإنجاز', 'rk-my-children' ); ?>
            </span>
            <?php else : ?>
            <span class="rk-chal-card__btn rk-chal-card__btn--disabled">
                <?php esc_html_e( 'الرابط غير متاح', 'rk-my-children' ); ?>
            </span>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<script>
(function () {
    var root = document.querySelector('[data-rk-challenges]');
    if (!root) return;
    var tabs  = Array.prototype.slice.call(root.querySelectorAll('.rk-chal-tab'));
    var cards = Array.prototype.slice.call(root.querySelectorAll('[data-rk-challenge-card]'));

    tabs.forEach(function (tab) {
        tab.addEventListener('click', function () {
            var status = tab.getAttribute('data-tab');
            tabs.forEach(function (t) { t.classList.toggle('is-active', t === tab); });
            cards.forEach(function (card) {
                card.hidden = card.getAttribute('data-status') !== status;
            });
        });
    });

    // État initial : "غير مكتملة" actif par défaut.
    cards.forEach(function (card) {
        card.hidden = card.getAttribute('data-status') !== 'pending';
    });
})();
</script>