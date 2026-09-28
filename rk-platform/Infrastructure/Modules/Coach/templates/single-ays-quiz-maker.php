<?php
/**
 * Template standalone — page quiz enfants (ays-quiz-maker).
 * Thème : couleurs florales vives pour enfants. Texte quiz #fff.
 * Palette Elementor : var(--e-global-color-primary) #FF4411 + var(--e-global-color-secondary) #4C95D7
 *
 * @package RK_Coach_Hub
 */
defined( 'ABSPATH' ) || exit;

global $post;
$quiz_title = get_the_title( $post );
$logo_url   = 'https://riadakids.com/wp-content/uploads/2026/01/logo-1.webp';

// Extract Quiz Maker quiz_id from the shortcode in post content.
preg_match( '/\[ays_quiz\s+id=["\']?(\d+)["\']?\]/', (string) ( $post->post_content ?? '' ), $_rk_m );
$rk_quiz_id = (int) ( $_rk_m[1] ?? 0 );

// ── Vérifier si l'enfant a déjà complété ce quiz ─────────────────
$rk_already_done   = false;
$rk_prev_score     = 0;
$rk_prev_corrects  = 0;
$rk_prev_total     = 0;
$rk_prev_passed    = false;
$rk_passing_grade  = 80;

$rk_max_attempts = 1;
$rk_attempts     = 0;
if ( $rk_quiz_id && is_user_logged_in() && class_exists( 'RKP_AysQuizRepository' ) ) {
    $rk_uid  = get_current_user_id();
    $rk_quiz = RKP_AysQuizRepository::find_rk_quiz( $rk_quiz_id );

    if ( $rk_quiz ) {
        $rk_passing_grade = (int) ( $rk_quiz->opts['passing_grade'] ?? 80 );
        $rk_max_attempts  = max( 1, (int) ( $rk_quiz->opts['rk_max_attempts'] ?? 1 ) );

        $prev = RKP_AysQuizRepository::latest_report( $rk_quiz_id, $rk_uid );

        /* v2.6.1 — AUTO-RÉPARATION : l'enfant a peut-être passé ce quiz sous
         * le cookie parent (soumission AYS via admin-ajax sans token).
         * On réattribue la tentative orpheline, on enregistre le résultat
         * (XP + événements, idempotent) puis on relit. */
        if ( ! $prev && class_exists( 'RKP_QuizFlowCommandService' )
            && RKP_QuizFlowCommandService::repair_for_child( $rk_uid, $rk_quiz_id ) ) {
            $prev = RKP_AysQuizRepository::latest_report( $rk_quiz_id, $rk_uid );
        }

        if ( $prev ) {
            $rk_already_done  = true;
            $rk_prev_score    = (int) $prev->score;
            $rk_prev_corrects = (int) $prev->corrects_count;
            $rk_prev_total    = max( 1, (int) $prev->questions_count );
            $rk_prev_passed   = $rk_prev_score >= $rk_passing_grade;
        }
        // Nombre de tentatives déjà consommées (pour la politique du coach).
        global $wpdb;
        $rk_attempts = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}aysquiz_reports
              WHERE quiz_id = %d AND user_id = %d AND end_date IS NOT NULL",
            $rk_quiz_id, $rk_uid
        ) );
    }
}

// ── URL de retour dashboard (avec rk_tab si disponible) ──────────────
// phpcs:disable WordPress.Security.NonceVerification
$_rk_tab_val = ( isset( $_GET['rk_tab'] ) && preg_match( '/^[a-f0-9]{40}$/', $_GET['rk_tab'] ) )
    ? sanitize_text_field( wp_unslash( $_GET['rk_tab'] ) )
    : '';
// phpcs:enable
if ( ! $_rk_tab_val && is_user_logged_in() && class_exists( 'RK_Session_Manager' ) ) {
    $_rk_child_uid  = get_current_user_id();
    $_rk_parent_uid = (int) get_user_meta( $_rk_child_uid, 'rk_child_tab_parent', true );
    if ( $_rk_child_uid && $_rk_parent_uid ) {
        $_rk_tab_val = RK_Session_Manager::create_tab_session( $_rk_child_uid, $_rk_parent_uid );
    }
}
$_rk_back_url = add_query_arg(
    $_rk_tab_val ? [ 'rk_tab' => $_rk_tab_val ] : [],
    home_url( '/dashboard/my-quiz-attempts/' )
);
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?> dir="rtl">
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo esc_html( $quiz_title ); ?> — RiadaKids</title>
<?php wp_head(); ?>
<?php // CSS factorisé — voir Modules/Coach/assets/css/single-quiz.css (versionné par filemtime pour contourner le cache LiteSpeed)
$_rk_css_rel = 'Modules/Coach/assets/css/single-quiz.css';
printf( '<link rel="stylesheet" href="%s">', esc_url( RKP_URL . $_rk_css_rel . '?ver=' . (string) filemtime( RKP_DIR . $_rk_css_rel ) ) );
?>
</head>
<body class="single-ays-quiz-maker rk-quiz-page">

<div class="rk-qp-bg" aria-hidden="true"></div>

<!-- Stickers floraux -->
<span class="rk-sticker rk-s1" aria-hidden="true">🌸</span>
<span class="rk-sticker rk-s2" aria-hidden="true">🌻</span>
<span class="rk-sticker rk-s3" aria-hidden="true">🦋</span>
<span class="rk-sticker rk-s4" aria-hidden="true">🌺</span>
<span class="rk-sticker rk-s5" aria-hidden="true">🌈</span>
<span class="rk-sticker rk-s6" aria-hidden="true">🌼</span>
<span class="rk-sticker rk-s7" aria-hidden="true">⭐</span>

<!-- Navbar -->
<nav class="rk-qp-nav">
  <a href="<?php echo esc_url( home_url( '/' ) ); ?>">
    <img class="rk-qp-nav-logo" src="<?php echo esc_url( $logo_url ); ?>" alt="RiadaKids" loading="lazy">
  </a>
  <span class="rk-qp-nav-label"><?php echo esc_html( $quiz_title ); ?></span>
</nav>

<!-- Contenu -->
<main class="rk-qp-wrap">

  <div class="rk-qp-badge">🎓&nbsp; اختبار تفاعلي</div>

  <h1 class="rk-qp-h1"><?php echo esc_html( $quiz_title ); ?></h1>

  <?php if ( $rk_already_done ) : ?>
  <!-- ── Résultat (quiz déjà complété — lecture seule) ── -->
  <div class="rk-qp-card" style="text-align:center;padding:0;">
    <div class="rk-qp-accent"></div>
    <div style="padding:36px 28px 40px;">

      <?php if ( $rk_prev_passed ) : ?>
      <div style="font-size:3.8rem;animation:rk-score-pop .7s cubic-bezier(.34,1.56,.64,1) both;">🏆</div>
      <p style="font-size:1.05rem;font-weight:800;color:#ffe000;margin:10px 0 4px;text-shadow:0 0 20px rgba(255,224,0,.6);">
        أحسنت! لقد نجحت
      </p>
      <?php else : ?>
      <div style="font-size:3.2rem;animation:rk-score-pop .7s cubic-bezier(.34,1.56,.64,1) both;">📖</div>
      <p style="font-size:1rem;font-weight:700;color:rgba(255,255,255,.85);margin:10px 0 4px;">
        واصل التدريب، ستنجح قريباً!
      </p>
      <?php endif; ?>

      <!-- Score principal -->
      <div style="font-size:4.2rem;font-weight:900;color:#ffe000;
                  text-shadow:0 0 30px rgba(255,224,0,.7),0 0 60px rgba(255,61,154,.4);
                  animation:rk-score-pop .7s cubic-bezier(.34,1.56,.64,1) .1s both;
                  margin:16px 0 8px;">
        <?php echo $rk_prev_score; ?>%
      </div>

      <!-- Badge statut -->
      <div style="display:inline-block;padding:6px 28px;border-radius:50px;font-size:1rem;font-weight:800;
                  background:<?php echo $rk_prev_passed ? 'rgba(74,222,128,.22)' : 'rgba(248,113,113,.18)'; ?>;
                  color:<?php echo $rk_prev_passed ? '#bbf7d0' : '#fecaca'; ?>;
                  border:2px solid <?php echo $rk_prev_passed ? '#4ade80' : '#f87171'; ?>;
                  margin-bottom:20px;">
        <?php echo $rk_prev_passed ? '✅ نجح' : '❌ لم ينجح'; ?>
      </div>

      <!-- Détail réponses -->
      <div style="display:flex;justify-content:center;gap:20px;flex-wrap:wrap;margin-bottom:24px;">
        <div style="background:rgba(255,255,255,.10);border-radius:12px;padding:12px 24px;min-width:110px;">
          <div style="font-size:1.6rem;font-weight:800;color:#ffffff;"><?php echo $rk_prev_corrects; ?>/<?php echo $rk_prev_total; ?></div>
          <div style="font-size:.75rem;color:rgba(255,255,255,.6);margin-top:2px;">إجابات صحيحة</div>
        </div>
        <div style="background:rgba(255,255,255,.10);border-radius:12px;padding:12px 24px;min-width:110px;">
          <div style="font-size:1.6rem;font-weight:800;color:#ffffff;"><?php echo $rk_passing_grade; ?>%</div>
          <div style="font-size:.75rem;color:rgba(255,255,255,.6);margin-top:2px;">درجة النجاح</div>
        </div>
      </div>

      <!-- Lien retour dashboard -->
      <a href="<?php echo esc_url( $_rk_back_url ); ?>"
         style="display:inline-block;padding:12px 32px;border-radius:50px;
                background:linear-gradient(135deg,#FF4411,#4C95D7);
                color:#fff;font-weight:800;font-size:.95rem;text-decoration:none;
                box-shadow:0 4px 24px rgba(76,149,215,.45);">
        ← العودة إلى اختباراتي
      </a>

      <p style="margin-top:18px;font-size:.78rem;color:rgba(255,255,255,.40);">
        هذا الاختبار مرة واحدة فقط
      </p>
    </div>
  </div>

  <?php else : ?>
  <!-- ── Quiz (première tentative) ── -->
  <div class="rk-qp-card">
    <div class="rk-qp-accent"></div>
    <?php
    if ( $post ) {
        echo apply_filters( 'the_content', $post->post_content );
    }
    ?>
  </div>
  <?php endif; ?>

</main>

<footer class="rk-qp-footer">
  <a href="<?php echo esc_url( home_url( '/' ) ); ?>">RiadaKids</a>
  &mdash; بالتوفيق! 🌟
</footer>

<?php wp_footer(); ?>

<script>
window.RK_QUIZ_DATA = {
  quizId  : <?php echo $rk_quiz_id; ?>,
  nonce   : '<?php echo esc_js( wp_create_nonce( 'wp_rest' ) ); ?>',
  api     : '<?php echo esc_url( rest_url( 'rk/v1' ) ); ?>',
  loggedIn: <?php echo is_user_logged_in() ? 'true' : 'false'; ?>,
  backUrl : '<?php echo esc_js( $_rk_back_url ); ?>',
  maxAttempts: <?php echo (int) $rk_max_attempts; ?>,
  attempts   : <?php echo (int) $rk_attempts; ?>
};
</script>
<?php // JS factorisé — voir Modules/Coach/assets/js/single-quiz.js
$_rk_js_rel = 'Modules/Coach/assets/js/single-quiz.js';
printf( '<script src="%s"></script>', esc_url( RKP_URL . $_rk_js_rel . '?ver=' . (string) filemtime( RKP_DIR . $_rk_js_rel ) ) );
?>
</body>
</html>
