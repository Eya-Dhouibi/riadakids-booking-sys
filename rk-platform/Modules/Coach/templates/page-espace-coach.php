<?php
/**
 * Template standalone — Espace coach JWT.
 *
 * Mobile : topbar fixe + drawer slide-in RTL + bottom nav.
 * Desktop : sidebar fixe 240px + content flex-1.
 * Auth    : JWT sessionStorage/localStorage — aucun cookie WordPress.
 *
 * JS/CSS : fichiers externes (rk-coach-spa.js + rk-coach-spa.css).
 * Config  : window.RK_COACH injectée en inline minimal avant le <script defer>.
 *
 * @package RK_Coach_Hub
 */
defined( 'ABSPATH' ) || exit;

// Empêcher tout cache (navigateur + LiteSpeed/WP Rocket/CDN) de cette page.
rkp_no_cache_page( 'rk_espace_coach' );

$_rk_api_base  = home_url( '/wp-json/rk/v1' );
$_rk_login_url = home_url( '/connexion-coach/' );
$_rk_home_url  = home_url( '/' );
$_rk_ver       = RK_COACH_HUB_VERSION;
$_rk_logo      = 'https://riadakids.com/wp-content/uploads/2026/01/logo-1.webp';
$_rk_a11y_css  = defined( 'RK_MC_URL' ) ? rkp_min_url( RK_MC_URL . 'assets/css/rk-a11y.css' ) : ''; // v2.3 — P4-18
$_rk_spa_css   = rkp_min_url( RK_COACH_HUB_URL . 'assets/css/rk-coach-spa.css' );
$_rk_hub_css   = rkp_min_url( RK_COACH_HUB_URL . 'assets/css/rk-coach-hub.css' );
$_rk_spa_js    = rkp_min_url( RK_COACH_HUB_URL . 'assets/js/rk-coach-spa.js' );
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>لوحة تحكم المدرب — RiadaKids</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@300;400;500;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?php echo esc_url( $_rk_hub_css ); ?>?v=<?php echo esc_attr( $_rk_ver ); ?>">
<?php if ( $_rk_a11y_css ) : ?><link rel="stylesheet" href="<?php echo esc_url( $_rk_a11y_css ); ?>?v=<?php echo esc_attr( $_rk_ver ); ?>"><?php endif; ?>
    <link rel="stylesheet" href="<?php echo esc_url( $_rk_spa_css ); ?>?v=<?php echo esc_attr( $_rk_ver ); ?>">

<!-- Config PHP → JS (inline minimal, pas de logique) -->
<script>
window.RK_COACH = {
    api:        '<?php echo esc_js( $_rk_api_base ); ?>',
    login:      '<?php echo esc_js( $_rk_login_url ); ?>',
    logo:       '<?php echo esc_js( $_rk_logo ); ?>',
    fiche_url:  '<?php echo esc_js( class_exists( 'RKP_LearningQueryService' ) ? RKP_LearningQueryService::get_dashboard_url( "rk-fiche-eleve" ) : "" ); ?>',
    badges_url: '<?php echo esc_js( class_exists( 'RKP_LearningQueryService' ) ? RKP_LearningQueryService::get_dashboard_url( "rk-badges" ) : "" ); ?>',
    // Requis pour que /coach/refresh-token (auth par cookie WP) passe
    // la vérification rest_cookie_check_errors — sans header
    // X-WP-Nonce, WordPress force l'utilisateur courant à 0 même si
    // le cookie de session est valide.
    restNonce:  '<?php echo esc_js( wp_create_nonce( 'wp_rest' ) ); ?>'
};
</script>
</head>
<body>

<!-- ══════════════════════════════════════════════════════════
     MOBILE — TOPBAR FIXE
     ══════════════════════════════════════════════════════════ -->
<header class="rk-mob-topbar" id="rk-mob-topbar" role="banner">

  <button class="rk-burger-btn" id="rk-burger" type="button"
          aria-label="فتح القائمة" aria-expanded="false" aria-controls="rk-mob-drawer">
    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true">
      <line x1="3" y1="6"  x2="21" y2="6"/>
      <line x1="3" y1="12" x2="21" y2="12"/>
      <line x1="3" y1="18" x2="21" y2="18"/>
    </svg>
  </button>

  <button class="rk-notif-bell" id="rk-notif-bell" type="button" aria-label="الإشعارات" aria-expanded="false">
    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
      <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>
      <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
    </svg>
    <span class="rk-notif-badge" id="rk-notif-badge" hidden></span>
  </button>

  <button class="rk-mob-avatar-btn" id="rk-mob-avatar-btn" type="button" aria-label="الملف الشخصي">
    <img id="rk-mob-topbar-avatar" src="<?php echo esc_url( $_rk_logo ); ?>"
         alt="" class="rk-mob-avatar-img">
  </button>

</header>
<!-- v2.8.1 — Cloche DESKTOP (le header mobile est masqué > 768px : le coach
     n'avait AUCUNE cloche sur ordinateur). Même panel, même badge synchronisé. -->
<button class="rk-notif-bell rk-notif-bell--desktop" type="button"
        aria-label="الإشعارات" aria-expanded="false">
  <svg width="21" height="21" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
    <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>
    <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
  </svg>
  <span class="rk-notif-badge" hidden></span>
</button>

<div class="rk-notif-panel" id="rk-notif-panel" hidden dir="rtl" role="dialog" aria-label="الإشعارات"></div>

<!-- ══════════════════════════════════════════════════════════
     MOBILE — OVERLAY
     ══════════════════════════════════════════════════════════ -->
<div class="rk-mob-overlay" id="rk-mob-overlay" aria-hidden="true"></div>

<!-- ══════════════════════════════════════════════════════════
     MOBILE — DRAWER (slide RTL, rempli par JS après auth)
     ══════════════════════════════════════════════════════════ -->
<aside class="rk-mob-drawer" id="rk-mob-drawer"
       role="navigation" aria-label="قائمة المدرب" aria-hidden="true">
  <div id="rk-mob-dwr-head" class="rk-mob-dwr-head">
    <div style="display:flex;align-items:center;gap:10px;padding-right:36px;">
      <div style="width:46px;height:46px;border-radius:50%;background:rgba(255,255,255,.1);flex-shrink:0;"></div>
      <div>
        <div style="width:80px;height:12px;background:rgba(255,255,255,.15);border-radius:4px;margin-bottom:6px;"></div>
        <div style="width:50px;height:10px;background:rgba(255,255,255,.1);border-radius:4px;"></div>
      </div>
    </div>
  </div>
  <div class="rk-mob-dwr-nav" id="rk-mob-dwr-nav"></div>
  <div class="rk-mob-dwr-footer" id="rk-mob-dwr-footer"></div>
</aside>

<!-- ══════════════════════════════════════════════════════════
     MOBILE — BOTTOM NAV (5 items)
     ══════════════════════════════════════════════════════════ -->
<nav class="rk-bottom-nav" id="rk-bottom-nav" aria-label="التنقل السريع">

  <button class="rk-bnav-item" data-page="home" type="button" aria-label="لوحة التحكم">
    <span class="rk-bnav-icon">
      <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/>
        <polyline points="9 22 9 12 15 12 15 22"/>
      </svg>
    </span>
    <span class="rk-bnav-lbl">التحكم</span>
  </button>

  <button class="rk-bnav-item" data-page="students" type="button" aria-label="الأطفال">
    <span class="rk-bnav-icon">
      <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/>
        <circle cx="9" cy="7" r="4"/>
        <path d="M23 21v-2a4 4 0 00-3-3.87"/>
        <path d="M16 3.13a4 4 0 010 7.75"/>
      </svg>
    </span>
    <span class="rk-bnav-lbl">الأطفال</span>
  </button>

  <button class="rk-bnav-item" data-page="sessions" type="button" aria-label="لقاءات">
    <span class="rk-bnav-icon">
      <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <rect x="3" y="4" width="18" height="18" rx="2"/>
        <line x1="16" y1="2" x2="16" y2="6"/>
        <line x1="8" y1="2" x2="8" y2="6"/>
        <line x1="3" y1="10" x2="21" y2="10"/>
      </svg>
    </span>
    <span class="rk-bnav-lbl">لقاءات</span>
  </button>

  <button class="rk-bnav-item" data-page="evals" type="button" aria-label="التقييمات" id="rk-bnav-evals">
    <span class="rk-bnav-icon">
      <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path d="M16 4h2a2 2 0 012 2v14a2 2 0 01-2 2H6a2 2 0 01-2-2V6a2 2 0 012-2h2"/>
        <rect x="8" y="2" width="8" height="4" rx="1"/>
        <line x1="9" y1="12" x2="15" y2="12"/>
        <line x1="9" y1="16" x2="13" y2="16"/>
      </svg>
    </span>
    <span class="rk-bnav-lbl">التقييمات</span>
  </button>

  <button class="rk-bnav-item" data-page="messages" type="button" aria-label="الرسائل" id="rk-bnav-messages">
    <span class="rk-bnav-icon">
      <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/>
      </svg>
    </span>
    <span class="rk-bnav-lbl">الرسائل</span>
  </button>

</nav>

<!-- ══════════════════════════════════════════════════════════
     AUTH GATE (visible pendant vérification JWT)
     ══════════════════════════════════════════════════════════ -->
<div id="rk-gate">
  <div class="rk-gate-spinner"></div>
  <p class="rk-gate-msg">جاري التحقق من هويتك…</p>
</div>

<!-- ══════════════════════════════════════════════════════════
     APP (desktop sidebar + contenu)
     ══════════════════════════════════════════════════════════ -->
<div id="rk-app">
  <div id="rk-nav-sidebar"></div>
  <main id="rk-main" role="main"></main>
</div>

<!-- ══════════════════════════════════════════════════════════
     TOAST CONTAINER (notifications منح/سحب شارات, etc.)
     ══════════════════════════════════════════════════════════ -->
<div id="rk-toast-container" aria-live="polite" aria-atomic="true"></div>

<!-- SPA JS — chargé en defer après le DOM -->
<?php
/*
 * ── SPA factorisé : modules concaténés en un seul bundle au runtime ──
 *
 * Perf : auparavant 16 <script> distincts = 16 requêtes HTTP séparées
 * (même en `defer`, chacune a son coût de connexion/TTFB, surtout
 * perceptible sur HTTP/1.1, mobile, ou latence élevée). Pas de build
 * step (npm) disponible dans ce dossier plugin pour bundler à la
 * compilation, donc le bundle est généré et mis en cache fichier au
 * premier accès ici — régénéré automatiquement seulement si un module
 * source change (comparaison filemtime), donc gratuit ensuite.
 *
 * L'ORDRE des modules est préservé strictement (identique à l'ancien
 * tableau de <script defer> — l'ordre d'exécution ne change pas).
 */
$_rk_spa_modules = [
    'rk-auth-manager', // v9.36 — chargé EN PREMIER pour auth unifiée
    'rk-coach-core', 'rk-coach-icons', 'rk-coach-utils', 'rk-coach-nav',
    'rk-coach-notifications',
    'rk-coach-page-home', 'rk-coach-page-badges', 'rk-coach-page-students', 'rk-coach-page-sessions',
    'rk-coach-page-evals',
    'rk-coach-page-quiz', 'rk-coach-page-messages',
    'rk-coach-page-stats', 'rk-coach-page-availability', 'rk-coach-page-settings',
];

$_rk_bundle_url = rkp_get_or_build_js_bundle(
    'rk-coach-spa-modules',
    RK_COACH_HUB_DIR . 'assets/js/modules/',
    $_rk_spa_modules,
    RK_COACH_HUB_URL . 'assets/js/modules/'
);

if ( $_rk_bundle_url ) {
    printf( '<script defer src="%s"></script>' . "\n", esc_url( $_rk_bundle_url ) );
} else {
    // Filet : si la génération du bundle échoue (dossier cache non
    // inscriptible, etc.), on retombe sur le chargement individuel
    // plutôt que de casser la page.
    foreach ( $_rk_spa_modules as $_rk_mod ) {
        $_rk_mod_rel  = 'assets/js/modules/' . $_rk_mod . '.js';
        $_rk_mod_path = RK_COACH_HUB_DIR . $_rk_mod_rel;
        if ( ! file_exists( $_rk_mod_path ) ) continue;
        printf(
            '<script defer src="%s"></script>' . "\n",
            esc_url( rkp_min_url( RK_COACH_HUB_URL . $_rk_mod_rel ) . '?v=' . (string) filemtime( $_rk_mod_path ) )
        );
    }
}
?>
<script defer src="<?php echo esc_url( $_rk_spa_js ); ?>?v=<?php echo esc_attr( (string) filemtime( RK_COACH_HUB_DIR . 'assets/js/rk-coach-spa.js' ) ); ?>"></script>
</body>
</html>