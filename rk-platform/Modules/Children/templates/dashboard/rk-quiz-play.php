<?php
declare( strict_types=1 );
/**
 * RiadaKids — Dashboard enfant : lecteur de test natif (تحدياتي → quiz)
 *
 * Remplace le lien externe vers /ays-quiz-maker/{slug}/ par une
 * interface intégrée dans le contenu du dashboard (sidebar/topbar
 * enfant visibles autour) — demande explicite de l'utilisateur, maquette
 * Figma fournie le 14/08/2026 ("My Challenges (Challenge)").
 *
 * Le format de STOCKAGE reste identique au vrai moteur AYS Quiz Maker
 * (wp_aysquiz_reports, même structure JSON) — voir
 * RKP_AysQuizRepository::insert_report(). Seul l'AFFICHAGE change.
 *
 * Slug : 'rk-quiz-play', paramètre 'quiz_id' en query string. Routé
 * automatiquement par nom de fichier (voir trait-rk-mc-tutor-dash-nav.php)
 * — ajouté à $rk_slugs dans class-rk-mc-tutor-dashboard.php.
 *
 * Flux : une question à l'écran à la fois, réponse envoyée en AJAX
 * (rk_quiz_answer, voir class-rk-mc-quiz-play-ajax.php), navigation
 * suivante/précédente géré côté client, soumission finale écrit le
 * rapport puis appelle RKP_QuizFlowCommandService::record_ays_result()
 * pour déclencher XP/badges/notifications (même pipeline que le moteur
 * natif AYS).
 *
 * @package RK_My_Children
 * @since   9.56.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$child = RK_MC_Tutor_Dashboard::get_child_for_template();
if ( ! $child ) {
    echo '<p dir="rtl">' . esc_html__( 'يرجى تحديد الطفل أولاً.', 'rk-my-children' ) . '</p>';
    return;
}

$child_rk_id  = (int) $child->id;
$child_wp_uid = (int) ( $child->wp_user_id ?? 0 );

// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- lecture simple d'un ID de test pour affichage.
$quiz_id = isset( $_GET['quiz_id'] ) ? absint( $_GET['quiz_id'] ) : 0;

if ( ! $quiz_id || ! class_exists( 'RKP_AysQuizRepository' ) ) {
    echo '<div dir="rtl" style="padding:40px 20px;text-align:center;color:#94a3b8;">'
        . esc_html__( 'التحدي غير موجود.', 'rk-my-children' ) . '</div>';
    return;
}

$quiz = RKP_AysQuizRepository::find_rk_quiz( $quiz_id );

// Vérifie que ce test appartient bien à CET enfant — un enfant ne doit
// jamais pouvoir jouer le test d'un autre en changeant quiz_id dans l'URL.
if ( ! $quiz || (int) $quiz->child_rk_id !== $child_rk_id ) {
    echo '<div dir="rtl" style="padding:40px 20px;text-align:center;color:#94a3b8;">'
        . esc_html__( 'هذا التحدي غير متاح لك.', 'rk-my-children' ) . '</div>';
    return;
}

// Déjà tenté ? Le moteur natif AYS respecte rk_max_attempts (souvent 1) —
// même règle ici : une tentative déjà enregistrée bloque un nouveau jeu,
// redirige vers le résultat au lieu de rejouer silencieusement.
$existing_report = RKP_AysQuizRepository::latest_report( $quiz_id, $child_wp_uid );
$max_attempts     = (int) ( $quiz->opts['rk_max_attempts'] ?? 1 );

$questions = RKP_AysQuizRepository::find_questions_with_answers( $quiz_id );
if ( empty( $questions ) ) {
    echo '<div dir="rtl" style="padding:40px 20px;text-align:center;color:#94a3b8;">'
        . esc_html__( 'لا توجد أسئلة في هذا التحدي بعد.', 'rk-my-children' ) . '</div>';
    return;
}

// Nom + avatar du coach (auteur du custom_post_id) — même logique que
// rk-challenges.php, dupliquée volontairement ici (fonction locale
// distincte, pages chargées indépendamment par le routeur par slug).
$coach_name   = '';
$coach_avatar = '';
if ( $quiz->custom_post_id ) {
    $author_id = (int) get_post_field( 'post_author', (int) $quiz->custom_post_id );
    if ( $author_id ) {
        $u = get_userdata( $author_id );
        if ( $u ) {
            $coach_name   = $u->display_name;
            $coach_avatar = get_avatar_url( $author_id, [ 'size' => 96 ] );
        }
    }
}

$primary_color = (string) ( $quiz->opts['color'] ?? '#E8500A' );

// Nonce dédié — voir class-rk-mc-quiz-play-ajax.php.
$nonce = wp_create_nonce( 'rk_quiz_play_' . $quiz_id );

// Sérialise les questions pour le JS, SANS jamais exposer quelle
// réponse est correcte (retiré ici, la correction se fait uniquement
// côté serveur dans le handler AJAX de soumission).
$questions_for_js = array_map( static function ( $q ) {
    return [
        'id'       => (int) $q->id,
        'question' => wp_strip_all_tags( (string) $q->question ),
        'type'     => (string) $q->type,
        'answers'  => array_map( static function ( $a ) {
            return [ 'id' => (int) $a->id, 'answer' => wp_strip_all_tags( (string) $a->answer ) ];
        }, $q->answers ),
    ];
}, $questions );
?>
<style>
.rk-qp-page { font-family:Cairo,Tajawal,sans-serif; direction:rtl; }
.rk-qp-head {
    display:flex; align-items:center; justify-content:space-between;
    gap:12px; margin-bottom:18px;
}
.rk-qp-title-block h1 {
    font-size:1.15rem; font-weight:800; color:#0D1F35; margin:0 0 4px;
}
.rk-qp-title-block .rk-qp-quiz-title {
    font-size:.95rem; font-weight:700; color:#334155; margin:0 0 6px;
}
.rk-qp-coach { display:flex; align-items:center; gap:8px; }
.rk-qp-coach img { width:28px; height:28px; border-radius:50%; object-fit:cover; }
.rk-qp-coach span { font-size:.82rem; color:#64748b; font-weight:600; }

.rk-qp-close {
    width:34px; height:34px; border-radius:50%; border:none; cursor:pointer;
    background:#f1f5f9; color:#64748b; display:flex; align-items:center; justify-content:center;
    flex-shrink:0; transition:background .15s;
}
.rk-qp-close:hover { background:#e2e8f0; }

.rk-qp-progress-row {
    display:flex; align-items:center; justify-content:space-between;
    margin-bottom:8px; font-size:.82rem; font-weight:700; color:#334155;
}
.rk-qp-progress-bar { height:6px; border-radius:20px; background:#e2e8f0; overflow:hidden; margin-bottom:26px; }
.rk-qp-progress-fill { height:100%; border-radius:20px; background:var(--rk-qp-color,#E8500A); transition:width .3s ease; }

.rk-qp-question { font-size:1.05rem; font-weight:800; color:#0D1F35; text-align:center; margin:0 0 22px; }

.rk-qp-answers { display:flex; flex-direction:column; gap:12px; margin-bottom:26px; }
.rk-qp-answer {
    display:flex; align-items:center; justify-content:space-between; gap:10px;
    background:#fff; border:1.5px solid #e2e8f0; border-radius:14px;
    padding:14px 16px; cursor:pointer; font-size:.92rem; font-weight:600; color:#334155;
    transition:border-color .15s, background .15s;
}
.rk-qp-answer:hover { border-color:var(--rk-qp-color,#E8500A); }
.rk-qp-answer.is-selected { border-color:var(--rk-qp-color,#E8500A); background:#fff7ed; }
.rk-qp-answer.is-correct  { border-color:#16a34a; background:#f0fdf4; }
.rk-qp-answer.is-incorrect{ border-color:#dc2626; background:#fef2f2; }
.rk-qp-answer.is-disabled { pointer-events:none; opacity:.85; }

.rk-qp-answer__badge {
    width:26px; height:26px; border-radius:50%; background:#eef2f7; color:#64748b;
    display:flex; align-items:center; justify-content:center; font-size:.78rem; font-weight:800;
    flex-shrink:0;
}
.rk-qp-answer.is-correct .rk-qp-answer__badge   { background:#16a34a; color:#fff; }
.rk-qp-answer.is-incorrect .rk-qp-answer__badge { background:#dc2626; color:#fff; }

.rk-qp-nav { display:flex; justify-content:space-between; gap:10px; }
.rk-qp-btn {
    padding:12px 26px; border-radius:12px; border:none; cursor:pointer;
    font-family:inherit; font-size:.9rem; font-weight:700; transition:opacity .15s;
}
.rk-qp-btn--primary { background:var(--rk-qp-color,#E8500A); color:#fff; }
.rk-qp-btn--primary:hover { opacity:.9; }
.rk-qp-btn--primary:disabled { opacity:.4; cursor:not-allowed; }
.rk-qp-btn--ghost { background:#f1f5f9; color:#334155; }

.rk-qp-result {
    display:none; flex-direction:column; align-items:center; gap:14px;
    padding:40px 20px; text-align:center;
}
.rk-qp-result.is-visible { display:flex; }
.rk-qp-result__icon { font-size:3.2rem; }
.rk-qp-result__score { font-size:2rem; font-weight:900; color:#0D1F35; }
.rk-qp-result__msg { font-size:.95rem; color:#64748b; max-width:340px; }

.rk-qp-locked {
    display:flex; flex-direction:column; align-items:center; gap:12px;
    padding:48px 20px; text-align:center; color:#94a3b8;
}
</style>

<div class="rk-qp-page" data-rk-quiz-play
     style="--rk-qp-color:<?php echo esc_attr( $primary_color ); ?>"
     data-quiz-id="<?php echo (int) $quiz_id; ?>"
     data-nonce="<?php echo esc_attr( $nonce ); ?>"
     data-questions="<?php echo esc_attr( wp_json_encode( $questions_for_js ) ); ?>">

    <div class="rk-qp-head">
        <div class="rk-qp-title-block">
            <h1><?php esc_html_e( 'تحدياتي', 'rk-my-children' ); ?></h1>
            <p class="rk-qp-quiz-title"><?php echo esc_html( wp_strip_all_tags( (string) $quiz->title ) ); ?></p>
            <?php if ( $coach_name ) : ?>
            <div class="rk-qp-coach">
                <?php if ( $coach_avatar ) : ?>
                <img src="<?php echo esc_url( $coach_avatar ); ?>" alt="" loading="lazy">
                <?php endif; ?>
                <span><?php printf( esc_html__( 'المدرب %s', 'rk-my-children' ), esc_html( $coach_name ) ); ?></span>
            </div>
            <?php endif; ?>
        </div>
        <a href="<?php echo esc_url( RKP_LearningQueryService::get_dashboard_url( 'rk-challenges' ) ); ?>"
           class="rk-qp-close" aria-label="<?php esc_attr_e( 'إغلاق', 'rk-my-children' ); ?>">✕</a>
    </div>

    <?php if ( $existing_report && $max_attempts <= 1 ) : ?>
    <div class="rk-qp-locked">
        <span style="font-size:2.4rem;">✅</span>
        <p><?php esc_html_e( 'لقد أكملت هذا التحدي بالفعل.', 'rk-my-children' ); ?></p>
        <a href="<?php echo esc_url( RKP_LearningQueryService::get_dashboard_url( 'rk-challenges' ) ); ?>" class="rk-qp-btn rk-qp-btn--primary">
            <?php esc_html_e( 'العودة إلى تحدياتي', 'rk-my-children' ); ?>
        </a>
    </div>
    <?php else : ?>

    <div class="rk-qp-progress-row">
        <span data-rk-qp-progress-text></span>
        <span data-rk-qp-percent>0%</span>
    </div>
    <div class="rk-qp-progress-bar"><div class="rk-qp-progress-fill" data-rk-qp-progress-fill style="width:0%"></div></div>

    <div data-rk-qp-question-host></div>

    <div class="rk-qp-nav">
        <button type="button" class="rk-qp-btn rk-qp-btn--ghost" data-rk-qp-prev hidden>
            <?php esc_html_e( 'السابق', 'rk-my-children' ); ?>
        </button>
        <span style="flex:1"></span>
        <button type="button" class="rk-qp-btn rk-qp-btn--primary" data-rk-qp-next disabled>
            <?php esc_html_e( 'التالي', 'rk-my-children' ); ?>
        </button>
    </div>

    <div class="rk-qp-result" data-rk-qp-result>
        <span class="rk-qp-result__icon" data-rk-qp-result-icon>🎉</span>
        <p class="rk-qp-result__score" data-rk-qp-result-score>0%</p>
        <p class="rk-qp-result__msg" data-rk-qp-result-msg></p>
        <a href="<?php echo esc_url( RKP_LearningQueryService::get_dashboard_url( 'rk-challenges' ) ); ?>" class="rk-qp-btn rk-qp-btn--primary">
            <?php esc_html_e( 'العودة إلى تحدياتي', 'rk-my-children' ); ?>
        </a>
    </div>

    <?php endif; ?>
</div>

<script>
(function () {
    var root = document.querySelector('[data-rk-quiz-play]');
    if (!root) return;

    var questions = JSON.parse(root.getAttribute('data-questions') || '[]');
    var quizId    = root.getAttribute('data-quiz-id');
    var nonce     = root.getAttribute('data-nonce');
    var ajaxUrl   = (window.rkExperience && window.rkExperience.ajaxUrl) || '/wp-admin/admin-ajax.php';

    var host        = root.querySelector('[data-rk-qp-question-host]');
    var btnNext      = root.querySelector('[data-rk-qp-next]');
    var btnPrev      = root.querySelector('[data-rk-qp-prev]');
    var progressText = root.querySelector('[data-rk-qp-progress-text]');
    var progressPct  = root.querySelector('[data-rk-qp-percent]');
    var progressFill = root.querySelector('[data-rk-qp-progress-fill]');
    var resultBox    = root.querySelector('[data-rk-qp-result]');
    var navRow       = root.querySelector('.rk-qp-nav');
    var progressRow  = root.querySelector('.rk-qp-progress-row');
    var progressBar  = root.querySelector('.rk-qp-progress-bar');

    if (!host || !questions.length) return;

    var current  = 0;
    var selected = {}; // { questionId: answerId }
    var startedAt = new Date();

    function esc(s) {
        var d = document.createElement('div');
        d.textContent = s == null ? '' : s;
        return d.innerHTML;
    }

    function renderQuestion() {
        var q = questions[current];
        var html = '<p class="rk-qp-question">' + esc(q.question) + '</p><div class="rk-qp-answers">';
        q.answers.forEach(function (a, i) {
            var isSel = selected[q.id] === a.id;
            html += '<button type="button" class="rk-qp-answer' + (isSel ? ' is-selected' : '') + '" data-answer-id="' + a.id + '">'
                + '<span>' + esc(a.answer) + '</span>'
                + '<span class="rk-qp-answer__badge">' + (i + 1) + '</span>'
                + '</button>';
        });
        html += '</div>';
        host.innerHTML = html;

        Array.prototype.slice.call(host.querySelectorAll('.rk-qp-answer')).forEach(function (btn) {
            btn.addEventListener('click', function () {
                selected[q.id] = parseInt(btn.getAttribute('data-answer-id'), 10);
                renderQuestion();
                updateNav();
            });
        });

        progressText.textContent = 'السؤال: ' + (current + 1) + '/' + questions.length;
        var pct = Math.round(((current) / questions.length) * 100);
        progressPct.textContent = pct + '%';
        progressFill.style.width = pct + '%';

        btnPrev.hidden = current === 0;
        btnNext.textContent = (current === questions.length - 1) ? 'إنهاء التحدي' : 'التالي';
    }

    function updateNav() {
        var q = questions[current];
        btnNext.disabled = selected[q.id] === undefined;
    }

    btnPrev.addEventListener('click', function () {
        if (current > 0) { current--; renderQuestion(); updateNav(); }
    });

    btnNext.addEventListener('click', function () {
        if (current < questions.length - 1) {
            current++;
            renderQuestion();
            updateNav();
            return;
        }
        submitQuiz();
    });

    function submitQuiz() {
        btnNext.disabled = true;
        btnNext.textContent = '...جاري الإرسال';

        var answersPayload = questions.map(function (q) {
            return { question_id: q.id, answer_id: selected[q.id] || 0 };
        });

        var fd = new FormData();
        fd.append('action', 'rk_quiz_play_submit');
        fd.append('nonce', nonce);
        fd.append('quiz_id', quizId);
        fd.append('started_at', Math.floor(startedAt.getTime() / 1000));
        fd.append('answers', JSON.stringify(answersPayload));

        fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (!res || !res.success) {
                    alert('حدث خطأ، حاول مرة أخرى.');
                    btnNext.disabled = false;
                    btnNext.textContent = 'إنهاء التحدي';
                    return;
                }
                showResult(res.data);
            })
            .catch(function () {
                alert('حدث خطأ في الاتصال.');
                btnNext.disabled = false;
                btnNext.textContent = 'إنهاء التحدي';
            });
    }

    function showResult(data) {
        host.hidden = true;
        navRow.hidden = true;
        progressRow.hidden = true;
        progressBar.hidden = true;

        var passed = !!data.passed;
        resultBox.querySelector('[data-rk-qp-result-icon]').textContent = passed ? '🎉' : '💪';
        resultBox.querySelector('[data-rk-qp-result-score]').textContent = data.score + '%';
        resultBox.querySelector('[data-rk-qp-result-msg]').textContent = passed
            ? 'أحسنت! لقد نجحت في هذا التحدي.'
            : 'حاول مرة أخرى في المرة القادمة، أنت تتقدم!';
        resultBox.classList.add('is-visible');
    }

    renderQuestion();
    updateNav();
})();
</script>