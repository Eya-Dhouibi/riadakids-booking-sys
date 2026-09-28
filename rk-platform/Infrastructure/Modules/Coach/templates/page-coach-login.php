<?php
/**
 * Template standalone — Page login coach RiadaKids.
 *
 * Servi via template_include filter quand on est sur /connexion-coach/.
 * Bypasse complètement le thème (pas de get_header/get_footer).
 * Le formulaire envoie les credentials via fetch() au endpoint JWT,
 * stocke le token dans sessionStorage/localStorage, puis redirige
 * vers /espace-coach/.
 *
 * @package RK_Coach_Hub
 */
defined( 'ABSPATH' ) || exit;

// Empêcher tout cache (navigateur + LiteSpeed/WP Rocket/CDN) de cette page.
rkp_no_cache_page( 'rk_coach_login_page' );

/* ── Rediriger selon le RÔLE (pas current_user_can) ────────
 * current_user_can() est filtré par Tutor LMS et retourne
 * true pour les admins → on lit $user->roles directement. */
if ( is_user_logged_in() ) {
    $current_roles = (array) wp_get_current_user()->roles;
    if ( class_exists( 'RK_Coach_Dashboard' ) && RK_Coach_Dashboard::is_coach_user( wp_get_current_user() ) ) {
        wp_safe_redirect( home_url( '/espace-coach/' ) );
        exit;
    } elseif ( in_array( 'rk_child', $current_roles, true ) ) {
        // Ne rediriger que si c'est une session directe (cookie WP enfant).
        // En session rk_tab, l'enfant est current_user mais le parent navigue — ignorer.
        // phpcs:disable WordPress.Security.NonceVerification
        $rk_tab_coach = isset( $_GET['rk_tab'] ) ? sanitize_text_field( wp_unslash( $_GET['rk_tab'] ) ) : '';
        // phpcs:enable
        if ( ! preg_match( '/^[a-f0-9]{40}$/', $rk_tab_coach ) ) {
            wp_safe_redirect( home_url( '/connexion-child/' ) );
            exit;
        }
        // rk_tab : laisser la page s'afficher pour le vrai visiteur
    }
    // Parent connecté → afficher le formulaire (login coach via JWT, cookie parent inchangé)
}

/* ── Messages d'erreur / succès ────────────────────────── */
$logged_out = ! empty( $_GET['logged_out'] ); // phpcs:ignore WordPress.Security.NonceVerification
$expired    = ! empty( $_GET['expired'] );    // phpcs:ignore WordPress.Security.NonceVerification

$logo_url = esc_url( apply_filters( 'rk_coach_login_logo_url', home_url( '/' ) ) );
$logo_img = esc_url( apply_filters( 'rk_coach_login_logo_img', 'https://riadakids.com/wp-content/uploads/2026/01/logo-1.webp' ) );

// Base URLs for JavaScript (échappés pour JS, pas HTML)
$jwt_endpoint  = home_url( '/wp-json/jwt-auth/v1/token' );
$me_endpoint   = home_url( '/wp-json/rk/v1/coach/me' );
$dash_redirect = home_url( '/espace-coach/' );
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?> class="rk-cl-html">
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex,nofollow">
<title><?php esc_html_e( 'بوابة المدربين', 'rk-coach-hub' ); ?> — RiadaKids</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@300;400;500;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?php echo esc_url( RK_COACH_HUB_URL . 'assets/css/coach-login.css' ); ?>?v=<?php echo esc_attr( RK_COACH_HUB_VERSION ); ?>">
<?php wp_head(); ?>
</head>
<body class="rk-cl-body">

<div class="rk-cl-wrap">

  <!-- ══ Panneau gauche — branding ══ -->
  <div class="rk-cl-left" role="presentation">
    <div class="rk-cl-left-content">

      <!-- Logo -->
      <div class="rk-cl-logo">
        <a href="<?php echo $logo_url; ?>">
          <img src="<?php echo $logo_img; ?>"
               alt="RiadaKids" width="150" height="58"
               onerror="this.style.display='none';this.nextElementSibling.style.display='block';">
          <div class="rk-cl-logo-text" style="display:none;">Riada<span>Kids</span></div>
        </a>
      </div>

      <!-- Illustration SVG -->
      <div class="rk-cl-illustration" aria-hidden="true">
        <svg viewBox="0 0 280 220" fill="none" xmlns="http://www.w3.org/2000/svg">
          <rect x="30" y="20" width="220" height="130" rx="10" fill="rgba(255,255,255,.12)" stroke="rgba(255,255,255,.3)" stroke-width="2"/>
          <rect x="45" y="35" width="190" height="100" rx="6" fill="rgba(255,255,255,.08)"/>
          <line x1="60" y1="55" x2="200" y2="55" stroke="rgba(255,255,255,.35)" stroke-width="1.5" stroke-linecap="round"/>
          <line x1="60" y1="70" x2="170" y2="70" stroke="rgba(255,255,255,.25)" stroke-width="1.5" stroke-linecap="round"/>
          <line x1="60" y1="85" x2="185" y2="85" stroke="rgba(255,255,255,.25)" stroke-width="1.5" stroke-linecap="round"/>
          <line x1="60" y1="100" x2="155" y2="100" stroke="rgba(255,255,255,.25)" stroke-width="1.5" stroke-linecap="round"/>
          <rect x="175" y="75" width="8" height="30" rx="2" fill="rgba(251,146,60,.7)"/>
          <rect x="188" y="63" width="8" height="42" rx="2" fill="rgba(251,146,60,.9)"/>
          <rect x="201" y="70" width="8" height="35" rx="2" fill="rgba(251,146,60,.6)"/>
          <rect x="125" y="150" width="30" height="8" rx="2" fill="rgba(255,255,255,.2)"/>
          <ellipse cx="60" cy="185" rx="22" ry="28" fill="rgba(255,255,255,.15)" stroke="rgba(255,255,255,.3)" stroke-width="1.5"/>
          <circle cx="60" cy="162" r="14" fill="rgba(255,255,255,.2)" stroke="rgba(255,255,255,.35)" stroke-width="1.5"/>
          <path d="M75 175 Q100 160 130 150" stroke="rgba(255,255,255,.4)" stroke-width="2.5" stroke-linecap="round" fill="none"/>
          <line x1="130" y1="150" x2="145" y2="145" stroke="rgba(251,146,60,.8)" stroke-width="2" stroke-linecap="round"/>
          <circle cx="195" cy="185" r="10" fill="rgba(255,255,255,.15)" stroke="rgba(255,255,255,.25)" stroke-width="1.2"/>
          <circle cx="220" cy="185" r="10" fill="rgba(255,255,255,.15)" stroke="rgba(255,255,255,.25)" stroke-width="1.2"/>
          <circle cx="245" cy="185" r="10" fill="rgba(255,255,255,.15)" stroke="rgba(255,255,255,.25)" stroke-width="1.2"/>
          <path d="M140 30 L143 39 L153 39 L145 44 L148 53 L140 48 L132 53 L135 44 L127 39 L137 39 Z" fill="rgba(251,146,60,.8)"/>
        </svg>
      </div>

      <p class="rk-cl-tagline"><?php esc_html_e( 'منصة المدربين المحترفين', 'rk-coach-hub' ); ?></p>
      <p class="rk-cl-tagline-sub"><?php esc_html_e( 'تابع تقدّم تلاميذك، عيّن المهمات، وقيّم أداءهم من لوحة تحكم واحدة متكاملة.', 'rk-coach-hub' ); ?></p>

      <div class="rk-cl-dots" aria-hidden="true">
        <div class="rk-cl-dot active"></div>
        <div class="rk-cl-dot"></div>
        <div class="rk-cl-dot"></div>
      </div>

    </div>
  </div><!-- /.rk-cl-left -->

  <!-- ══ Panneau droit — formulaire ══ -->
  <div class="rk-cl-right">
    <div class="rk-cl-form-wrap">

      <!-- En-tête -->
      <div class="rk-cl-form-head">
        <div class="rk-cl-role-badge" aria-label="<?php esc_attr_e( 'مخصص للمدربين', 'rk-coach-hub' ); ?>">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true">
            <rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>
          </svg>
          <?php esc_html_e( 'دخول المدربين فقط', 'rk-coach-hub' ); ?>
        </div>
        <h1><?php esc_html_e( 'بوابة المدربين', 'rk-coach-hub' ); ?></h1>
        <p><?php esc_html_e( 'سجّل دخولك للوصول إلى لوحة التحكم الخاصة بك.', 'rk-coach-hub' ); ?></p>
      </div>

      <!-- Messages ────────────────────────────────────────── -->
      <?php if ( $logged_out ) : ?>
      <div class="rk-cl-alert rk-cl-alert--success" role="status">
        <span class="rk-cl-alert-icon">✅</span>
        <span><?php esc_html_e( 'تم تسجيل خروجك بنجاح. يمكنك تسجيل الدخول من جديد.', 'rk-coach-hub' ); ?></span>
      </div>
      <?php elseif ( $expired ) : ?>
      <div class="rk-cl-alert rk-cl-alert--error" role="alert">
        <span class="rk-cl-alert-icon">⚠️</span>
        <span><?php esc_html_e( 'انتهت جلستك. الرجاء تسجيل الدخول من جديد.', 'rk-coach-hub' ); ?></span>
      </div>
      <?php endif; ?>

      <!-- Alerte JavaScript (login JWT) -->
      <div id="rk-js-alert" class="rk-cl-alert rk-cl-alert--error" role="alert" style="display:none;">
        <span class="rk-cl-alert-icon">⚠️</span>
        <span id="rk-js-alert-msg"></span>
      </div>

      <!-- Formulaire — soumis via JavaScript (JWT) ────────── -->
      <form id="rk-coach-login-form" novalidate>

        <!-- Email / Username -->
        <div class="rk-cl-field first" id="rk-field-user">
          <label for="rk-coach-user"><?php esc_html_e( 'البريد الإلكتروني أو اسم المستخدم', 'rk-coach-hub' ); ?></label>
          <input type="text"
                 id="rk-coach-user"
                 autocomplete="username"
                 inputmode="email"
                 required>
        </div>

        <!-- Mot de passe -->
        <div class="rk-cl-field last" id="rk-field-pwd">
          <label for="rk-coach-pwd"><?php esc_html_e( 'كلمة المرور', 'rk-coach-hub' ); ?></label>
          <input type="password"
                 id="rk-coach-pwd"
                 autocomplete="current-password"
                 required>
          <button type="button" class="rk-cl-pwd-toggle" aria-label="<?php esc_attr_e( 'إظهار/إخفاء كلمة المرور', 'rk-coach-hub' ); ?>">
            <svg id="rk-eye-show" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
              <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>
            </svg>
            <svg id="rk-eye-hide" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true" style="display:none;">
              <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/>
              <line x1="1" y1="1" x2="23" y2="23"/>
            </svg>
          </button>
        </div>

        <!-- Remember + Forgot ───────────────────────────── -->
        <div class="rk-cl-row">
          <label class="rk-cl-checkbox">
            <input type="checkbox" id="rk-rememberme" value="1">
            <span class="rk-cl-check-box"></span>
            <?php esc_html_e( 'تذكّرني', 'rk-coach-hub' ); ?>
          </label>
          <a href="<?php echo esc_url( wp_lostpassword_url( home_url( '/connexion-coach/' ) ) ); ?>" class="rk-cl-forgot">
            <?php esc_html_e( 'نسيت كلمة المرور؟', 'rk-coach-hub' ); ?>
          </a>
        </div>

        <!-- Submit ──────────────────────────────────────── -->
        <button type="submit" class="rk-cl-submit" id="rk-cl-submit-btn">
          <span class="rk-cl-submit-text"><?php esc_html_e( 'دخول لوحة التحكم', 'rk-coach-hub' ); ?></span>
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true">
            <path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/>
            <polyline points="10 17 15 12 10 7"/>
            <line x1="15" y1="12" x2="3" y2="12"/>
          </svg>
        </button>

      </form>

      <!-- Lien retour site ────────────────────────────────── -->
      <div class="rk-cl-back">
        <a href="<?php echo esc_url( home_url( '/' ) ); ?>">
          ← <?php esc_html_e( 'العودة للموقع الرئيسي', 'rk-coach-hub' ); ?>
        </a>
      </div>

      <!-- Footer note ─────────────────────────────────────── -->
      <div class="rk-cl-footer-note" aria-hidden="true">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
          <rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>
        </svg>
        <?php esc_html_e( 'اتصال آمن — JWT Authentication', 'rk-coach-hub' ); ?>
      </div>

    </div>
  </div><!-- /.rk-cl-right -->

</div><!-- /.rk-cl-wrap -->

<script>
(function () {
  'use strict';

  /* ── Endpoints (injectés depuis PHP) ── */
  var API_JWT = '<?php echo esc_js( $jwt_endpoint ); ?>';
  var API_ME  = '<?php echo esc_js( $me_endpoint ); ?>';
  var DASH    = '<?php echo esc_js( $dash_redirect ); ?>';

  /* ── Labels flottants ── */
  document.querySelectorAll('.rk-cl-field input[type="text"],' +
    '.rk-cl-field input[type="password"]').forEach(function (inp) {
    function check() {
      inp.closest('.rk-cl-field').classList.toggle('has-value', inp.value.trim() !== '');
    }
    inp.addEventListener('input', check);
    inp.addEventListener('change', check);
    check();
  });

  /* ── Toggle mot de passe ── */
  var toggle   = document.querySelector('.rk-cl-pwd-toggle');
  var pwdInput = document.getElementById('rk-coach-pwd');
  var eyeShow  = document.getElementById('rk-eye-show');
  var eyeHide  = document.getElementById('rk-eye-hide');
  if (toggle && pwdInput) {
    toggle.addEventListener('click', function () {
      var isText = pwdInput.type === 'text';
      pwdInput.type = isText ? 'password' : 'text';
      eyeShow.style.display = isText ? '' : 'none';
      eyeHide.style.display = isText ? 'none' : '';
    });
  }

  /* ── Alerte JS ── */
  var jsAlert  = document.getElementById('rk-js-alert');
  var jsAlertM = document.getElementById('rk-js-alert-msg');
  function showErr(msg) {
    if (!jsAlert || !jsAlertM) return;
    jsAlertM.textContent = msg;
    jsAlert.style.display = 'flex';
    jsAlert.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }
  function hideErr() { if (jsAlert) jsAlert.style.display = 'none'; }

  /* ── Décoder expiry depuis token JWT (payload base64) ── */
  function tokenExpiry(token) {
    try {
      var b64 = token.split('.')[1].replace(/-/g, '+').replace(/_/g, '/');
      while (b64.length % 4) b64 += '=';
      var pay = JSON.parse(decodeURIComponent(escape(atob(b64))));
      return (pay.exp || 0) * 1000;
    } catch (_) { return Date.now() + 7 * 24 * 3600 * 1000; }
  }

  /* ── Login JWT ── */
  var form      = document.getElementById('rk-coach-login-form');
  var submitBtn = document.getElementById('rk-cl-submit-btn');
  var submitTxt = submitBtn ? submitBtn.innerHTML : '';

  if (form && submitBtn) {
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      hideErr();

      var username = document.getElementById('rk-coach-user').value.trim();
      var password = document.getElementById('rk-coach-pwd').value;
      var remember = document.getElementById('rk-rememberme');
      var store    = (remember && remember.checked) ? localStorage : sessionStorage;

      if (!username) { showErr('الرجاء إدخال البريد الإلكتروني أو اسم المستخدم.'); return; }
      if (!password) { showErr('الرجاء إدخال كلمة المرور.'); return; }

      submitBtn.disabled = true;
      submitBtn.innerHTML = '<div class="rk-cl-spinner"></div>';

      /* ─ Étape 1 : obtenir le token JWT ─ */
      fetch(API_JWT, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ username: username, password: password })
      })
      .then(function (res) {
        var ctype = res.headers.get('content-type') || '';
        if (ctype.indexOf('application/json') === -1) {
          // Le endpoint JWT ne renvoie pas du JSON (plugin JWT inactif,
          // secret manquant, ou permaliens WP non "Nom de la publication").
          throw new Error('خطأ في إعداد الخادم (JWT). يرجى التواصل مع الإدارة التقنية.');
        }
        return res.json().then(function (d) { return { ok: res.ok, data: d }; });
      })
      .then(function (r) {
        if (!r.ok || r.data.code) {
          var code = r.data.code || '';
          var msg  = 'البريد الإلكتروني أو كلمة المرور غير صحيحة.';
          if (code.indexOf('invalid_username') !== -1) msg = 'المستخدم غير موجود.';
          if (code.indexOf('incorrect_password') !== -1) msg = 'كلمة المرور غير صحيحة.';
          throw new Error(msg);
        }
        var token  = r.data.token;
        var expiry = tokenExpiry(token);

        /* ─ Étape 2 : vérifier le rôle coach ─ */
        /* Cache-buster + no-store : sans ça, une réponse 401 anonyme
           mise en cache (navigateur ou proxy) peut être resservie à un
           coach pourtant authentifié. */
        return fetch(API_ME + (API_ME.indexOf('?') === -1 ? '?' : '&') + '_=' + Date.now(), {
          cache: 'no-store',
          headers: {
            'Authorization': 'Bearer ' + token,
            /* Certains hébergements suppriment le header Authorization
               avant qu'il n'atteigne PHP ; header custom en secours,
               voir RK_Coach_Auth_Hardening côté serveur. */
            'X-RK-Coach-Auth': 'Bearer ' + token,
            'Content-Type': 'application/json'
          }
        })
        .then(function (res2) {
          var ctype2 = res2.headers.get('content-type') || '';
          if (ctype2.indexOf('application/json') === -1) {
            throw new Error('خطأ في إعداد الخادم (JWT). يرجى التواصل مع الإدارة التقنية.');
          }
          return res2.json().then(function (d2) {
            return { ok: res2.ok, status: res2.status, data: d2, token: token, expiry: expiry };
          });
        });
      })
      .then(function (r2) {
        if (!r2.ok) {
          /* 403 = utilisateur authentifié mais sans rôle coach.
             401 = le token n'a authentifié personne côté serveur
                   (header Authorization avalé, secret JWT absent/changé,
                    réponse anonyme mise en cache). Cas très différent :
                    ne pas accuser le rôle du compte. */
          if (r2.status === 403) {
            throw new Error('هذا الحساب ليس لديه صلاحيات الدخول كمدرب. تواصل مع الإدارة.');
          }
          throw new Error('تعذّر التحقق من الجلسة على الخادم (رمز الدخول لم يُقبل). تواصل مع الإدارة التقنية. [' + r2.status + ']');
        }
        /* ─ Étape 3 : stocker et rediriger ─ */
        store.setItem('rk_coach_token',  r2.token);
        store.setItem('rk_coach_expiry', String(r2.expiry));
        store.setItem('rk_coach_id',     String(r2.data.id    || ''));
        store.setItem('rk_coach_name',   String(r2.data.name  || ''));
        store.setItem('rk_coach_avatar', String(r2.data.avatar || ''));
        window.location.href = DASH;
      })
      .catch(function (err) {
        submitBtn.disabled = false;
        submitBtn.innerHTML = submitTxt;
        showErr(err.message || 'حدث خطأ في الاتصال. الرجاء المحاولة مرة أخرى.');
      });
    });
  }

  /* ── Enter → submit ── */
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && document.activeElement !== submitBtn) {
      var f = document.getElementById('rk-coach-login-form');
      if (f) { if (f.requestSubmit) f.requestSubmit(); else f.submit(); }
    }
  });

})();
</script>

<?php wp_footer(); ?>
</body>
</html>