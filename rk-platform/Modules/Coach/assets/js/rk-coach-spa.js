/**
 * rk-coach-spa.js — Point d'entrée / orchestrateur du Dashboard Coach (RiadaKids)
 *
 * Ce fichier NE CONTIENT PLUS la logique métier : il déclenche uniquement
 * le boot et le dispatch de navigation. Toute la logique est répartie dans
 * /modules/ (voir en-tête de chaque fichier pour son contenu).
 *
 * Ordre de chargement requis (voir page-espace-coach.php) :
 *   1. rk-coach-core.js
 *   2. rk-coach-icons.js
 *   3. rk-coach-utils.js
 *   4. rk-coach-nav.js
 *   5. rk-coach-notifications.js
 *   6-16. rk-coach-page-*.js
 *   17. rk-coach-spa.js   (CE FICHIER, en dernier)
 *
 * Règle d'or du boot : on ne redirige vers ?expired=1 QUE pour une vraie
 * erreur d'authentification. Toute autre erreur (JS, réseau, module
 * manquant) est AFFICHÉE — jamais déguisée en expiration de session.
 */

/* global window, document */
(function () {
  'use strict';

  var RK = window.RKCoach || (window.RKCoach = {});

  /* ── Garde : modules chargés ? ────────────────────────────────────
   * Si un fichier /modules/ manque (déploiement partiel, cache LiteSpeed
   * périmé, 404), on l'affiche explicitement au lieu de crasher plus
   * loin et de rediriger vers ?expired=1 à tort. */
  function missingModules() {
    var need = ['Core', 'Icons', 'Utils', 'Nav', 'Notifications'];
    var pages = ['Home', 'Students', 'Sessions', 'Evals', 'Quiz',
                 'Badges', 'Messages', 'Stats', 'Settings'];
    var miss = [];
    need.forEach(function (n) { if (!RK[n]) miss.push(n); });
    var P = RK.Pages || {};
    pages.forEach(function (p) { if (!P[p]) miss.push('Pages.' + p); });
    return miss;
  }

  function showFatal(msg) {
    /* Panneau d'erreur lisible pour le coach + détail console pour toi */
    console.error('[RKCoach] ' + msg);
    var g = document.getElementById('rk-gate');
    if (g) {
      g.innerHTML =
        '<div style="text-align:center;padding:48px 20px;color:#64748b;font-family:inherit">' +
        '<div style="font-size:2rem">⚠️</div>' +
        '<p style="font-weight:700;color:#0D1F35;margin:10px 0 4px">حدث خطأ تقني</p>' +
        '<p style="font-size:.85rem;margin:0 0 14px">أعد تحميل الصفحة، وإن استمرّ الخطأ تواصل مع الإدارة</p>' +
        '<button onclick="location.reload()" style="padding:10px 26px;border:0;border-radius:12px;' +
        'background:#4C95D7;color:#fff;font-weight:700;cursor:pointer">إعادة التحميل</button>' +
        '<p style="font-size:.7rem;color:#94a3b8;margin-top:14px;direction:ltr">' + msg + '</p>' +
        '</div>';
    }
  }

  var miss = missingModules();
  if (miss.length) {
    showFatal('Modules non chargés : ' + miss.join(', ') +
      ' — vérifier le déploiement de assets/js/modules/ et purger le cache LiteSpeed.');
    return; // on n'essaie pas de booter sans les modules
  }

  RK.state = RK.state || {
    page: 'home', home: null, students: null,
    sessRange: 'week', childId: 0,
    evalsData: null, evalsFilter: { childId: 0, rating: 0 },
    pendingTab: null
  };
  var S = RK.state;

  var Core  = RK.Core;
  var Nav   = RK.Nav;
  var Utils = RK.Utils;
  var Pages = RK.Pages;

  /* Codes de réponse REST qui signifient réellement "session expirée" */
  var AUTH_CODES = [
    'jwt_auth_invalid_token', 'jwt_auth_expired_token',
    'rest_forbidden', 'rest_not_logged_in', 'rest_cookie_invalid_nonce'
  ];
  function isAuthCode(code) { return AUTH_CODES.indexOf(String(code || '')) !== -1; }

  function nav(page) {
    if (S.page === page) return;
    S.page = page;
    window.history.replaceState(null, '', '#' + page);
    renderContent();
    Nav.setActiveNav(page);
  }

  function renderContent() {
    switch (S.page) {
      case 'home':     Pages.Home.render();     break;
      case 'students': Pages.Students.render(); break;
      case 'sessions': Pages.Sessions.render(); break;
      case 'evals':    Pages.Evals.render();    break;
      case 'quiz':     Pages.Quiz.render();     break;
      case 'messages': Pages.Messages.render(); break;
      case 'stats':    Pages.Stats.render();    break;
      // v9.16 — 'badges' réintégrée : page complète avec CRUD (voir
      // rk-coach-page-badges.js), cohérente avec le reste de la SPA.
      case 'badges':   Pages.Badges.render();   break;
      case 'availability': Pages.Availability.render(); break;
      case 'settings': Pages.Settings.render(); break;
      default:         Pages.Home.render();
    }
  }

  function boot() {
    var token = Core.getToken();
    if (!token) { Core.redirect(RK.config.login); return; }

    Core.apiGet('/coach/home', false).then(function (data) {
      /* ?expired=1 UNIQUEMENT pour une vraie erreur d'auth */
      if (!data || isAuthCode(data.code)) {
        Core.redirect(RK.config.login + '?expired=1');
        return;
      }
      if (data.code) {
        /* Erreur API non-auth (500, données invalides…) : on l'affiche */
        showFatal('/coach/home → ' + data.code + ' : ' + (data.message || ''));
        return;
      }
      S.home = data;

      document.getElementById('rk-gate').style.display = 'none';
      document.getElementById('rk-app').classList.add('loaded');

      Nav.renderDesktopSidebar(data.coach);
      Nav.renderMobileUI(data.coach);
      RK.Notifications.init();

      var initPage = (window.location.hash || '#home').replace('#', '') || 'home';
      S.page = '';
      nav(initPage);
    }).catch(function (err) {
      /* Rejets d'auth émis par core (no_token / auth / 401) → expired.
       * TOUT LE RESTE (TypeError, module, réseau) → affiché, pas déguisé. */
      if (err === 'no_token' || err === 'auth' || (err && err.status === 401)) {
        Core.redirect(RK.config.login + '?expired=1');
        return;
      }
      showFatal('Boot échoué (PAS une expiration) : ' + String((err && err.message) || err));
    });
  }

  window.addEventListener('hashchange', function () {
    var p = (window.location.hash || '#home').replace('#', '') || 'home';
    if (S.page !== p) { S.page = ''; nav(p); }
  });

  /* ── Exposition globale (onclick="" inline conservés dans le HTML) ── */
  window.nav             = nav;
  // v9.25 — showChildQuiz() ouvre la fiche élève depuis l'onglet
  // الاختبارات (#quiz), mais le bouton "رجوع" de la fiche ramenait
  // toujours vers #students codé en dur, quelle que soit la page
  // d'origine. S.returnPage mémorise explicitement d'où on vient ;
  // Pages.Students lit cette valeur pour le bouton retour (repli sur
  // 'students' si absente — comportement historique inchangé pour
  // tout accès direct depuis la liste des élèves).
  window.showChildQuiz   = function (childId) { S.pendingTab = 'quiz'; S.returnPage = 'quiz'; Pages.Students.showChildDetail(childId); };
  window.previewQuiz     = function (quizId)  { Pages.Quiz.previewQuiz(quizId); };
  window.editQuiz        = function (quizId, childId) { Pages.Quiz.editQuiz(quizId, childId); };
  window.copyToClipboard = function (text) { Utils.copyToClipboard(text); };
  window.openMsgThread   = function (partnerId, childId) { Pages.Messages.openThread(partnerId, childId); };
  window.submitMsg       = function (partnerId, childId) { Pages.Messages.submit(partnerId, childId); };
  window._rkMarkNotifsRead = function () { RK.Notifications.markRead(); };

  RK.dispatch = { nav: nav, renderContent: renderContent };

  boot();

})();