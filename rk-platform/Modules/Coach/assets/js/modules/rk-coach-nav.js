/**
 * rk-coach-nav.js — Sidebar desktop, drawer mobile, bottom-nav, burger
 * Namespace: RKCoach.Nav
 */
(function () {
  'use strict';
  var RK    = window.RKCoach;
  var Core  = RK.Core;
  var Utils = RK.Utils;
  var Icons = RK.Icons;

  function setActiveNav(page) {
    document.querySelectorAll('.rk-nav-item').forEach(function (el) {
      el.classList.toggle('active', el.dataset.page === page);
    });
    document.querySelectorAll('.rk-bnav-item').forEach(function (el) {
      el.classList.toggle('active', el.dataset.page === page);
    });
    document.querySelectorAll('.rk-mob-dwr-item').forEach(function (el) {
      el.classList.toggle('active', el.dataset.page === page);
    });
  }

  /* ══════════════════════════════════════════════════════════
     MOBILE — DRAWER
     ══════════════════════════════════════════════════════════ */
  function openDrawer() {
    var d = document.getElementById('rk-mob-drawer');
    var o = document.getElementById('rk-mob-overlay');
    var b = document.getElementById('rk-burger');
    if (d) { d.classList.add('open'); d.setAttribute('aria-hidden', 'false'); }
    if (o) o.classList.add('open');
    if (b) b.setAttribute('aria-expanded', 'true');
    document.body.style.overflow = 'hidden';
  }

  function closeDrawer() {
    var d = document.getElementById('rk-mob-drawer');
    var o = document.getElementById('rk-mob-overlay');
    var b = document.getElementById('rk-burger');
    if (d) { d.classList.remove('open'); d.setAttribute('aria-hidden', 'true'); }
    if (o) o.classList.remove('open');
    if (b) b.setAttribute('aria-expanded', 'false');
    document.body.style.overflow = '';
  }

  /* Bindings statiques (présents dans le HTML avant boot) */
  (function bindStatics() {
    var burger  = document.getElementById('rk-burger');
    var overlay = document.getElementById('rk-mob-overlay');
    if (burger)  burger.addEventListener('click', openDrawer);
    if (overlay) overlay.addEventListener('click', closeDrawer);
    document.addEventListener('keydown', function (ev) {
      if (ev.key === 'Escape') closeDrawer();
    });
    document.querySelectorAll('.rk-bnav-item').forEach(function (btn) {
      btn.addEventListener('click', function () { RK.dispatch.nav(btn.dataset.page); });
    });
  })();

  /* ══════════════════════════════════════════════════════════
     NAV ITEMS DEFINITION
     ══════════════════════════════════════════════════════════ */
  function navItems(pending, unread) {
    return [
      { page: 'home',     icon: Icons.iHome,  label: 'لوحة التحكم', group: '' },
      { page: 'students', icon: Icons.iUsers, label: 'الأطفال',      group: '' },
      { page: 'sessions', icon: Icons.iCal,   label: 'لقاءات',      group: 'عمل المدرب' },
      { page: 'evals',    icon: Icons.iClip,  label: 'التقييمات',    group: 'عمل المدرب', badge: pending },
      { page: 'quiz',     icon: Icons.iQuiz,  label: 'الاختبارات',   group: 'عمل المدرب' },
      // v9.16 — 'badges' devient une VRAIE page de la SPA (CRUD complet
      // en AJAX, voir rk-coach-page-badges.js) — même mécanisme que les
      // autres onglets, plus de lien externe vers /dashboard/rk-badges/.
      { page: 'badges',   icon: Icons.iAward, label: 'الشارات',      group: 'عمل المدرب' },
      { page: 'messages', icon: Icons.iChat,  label: 'الرسائل',      group: 'التواصل',    badge: unread  },
      { page: 'stats',    icon: Icons.iChart, label: 'التقارير',     group: 'التواصل' },
      { page: 'availability', icon: Icons.iClock, label: 'التوفر',    group: 'الحساب' },
      { page: 'settings', icon: Icons.iGear,  label: 'الإعدادات',    group: 'الحساب' }
    ];
  }

  function buildNavHtml(items, itemCls, groupCls, labelCls, iconCls, badgeCls) {
    var html      = '';
    var lastGroup = null;
    items.forEach(function (it) {
      if (it.group !== lastGroup) {
        if (lastGroup !== null) html += '</div>';
        html += '<div class="' + groupCls + '">';
        if (it.group) html += '<span class="' + labelCls + '">' + Utils.e(it.group) + '</span>';
        lastGroup = it.group;
      }
      var bdg = (it.badge > 0)
        ? '<span class="' + badgeCls + '">' + (it.badge > 9 ? '9+' : it.badge) + '</span>'
        : '';
      // Items marqués external:true deviennent de vrais liens <a href>,
      // qui sortent complètement de la SPA vers une page externe — au
      // lieu du <button data-page> utilisé pour les pages internes
      // routées par RK.dispatch.nav(), qui ne quitte jamais la SPA.
      // Aucun item n'utilise ce mécanisme actuellement (badges est
      // redevenue une vraie page interne en v9.16) — conservé pour un
      // futur lien externe éventuel.
      if (it.external && it.href) {
        html += '<a class="' + itemCls + '" href="' + Utils.e(it.href) + '" data-page="' + Utils.e(it.page) + '">'
              + '<span class="' + iconCls + '">' + it.icon + '</span>'
              + '<span>' + Utils.e(it.label) + '</span>' + bdg + '</a>';
      } else {
        html += '<button class="' + itemCls + '" data-page="' + Utils.e(it.page) + '" type="button">'
              + '<span class="' + iconCls + '">' + it.icon + '</span>'
              + '<span>' + Utils.e(it.label) + '</span>' + bdg + '</button>';
      }
    });
    if (lastGroup !== null) html += '</div>';
    return html;
  }

  /* ══════════════════════════════════════════════════════════
     DESKTOP SIDEBAR
     ══════════════════════════════════════════════════════════ */
  function renderDesktopSidebar(coach) {
    var S       = RK.state;
    var pending = S.home.pending || 0;
    var unread  = S.home.unread  || 0;
    var name    = Utils.e(coach.name || Core.storeGet(Core.KEYS.name) || 'المدرب');
    var avatar  = coach.avatar || Core.storeGet(Core.KEYS.avatar) || RK.config.logo;

    var html = '<div class="rk-nav-logo-area">'
      + '<img src="' + Utils.e(RK.config.logo) + '" alt="RiadaKids" loading="eager" onerror="this.style.display=\'none\'">'
      + '</div>'
      + '<div class="rk-nav-hero">'
      + '<img src="' + Utils.e(avatar) + '" alt="' + name + '" width="40" height="40" onerror="this.src=\'' + Utils.e(RK.config.logo) + '\'">'
      + '<div class="rk-nav-hero-info"><p class="rk-nav-name">' + name + '</p>'
      + '<p class="rk-nav-role">مدرب RiadaKids ⭐</p></div></div>';

    html += buildNavHtml(navItems(pending, unread), 'rk-nav-item', 'rk-nav-group', 'rk-nav-group-label', 'rk-nav-icon', 'rk-nav-badge');
    html += '<div class="rk-nav-footer"><button class="rk-nav-logout" id="rk-desk-logout" type="button">'
          + Icons.iLogout + ' تسجيل الخروج</button></div>';

    document.getElementById('rk-nav-sidebar').innerHTML = html;
    document.querySelectorAll('.rk-nav-item:not([href])').forEach(function (el) {
      el.addEventListener('click', function () { RK.dispatch.nav(el.dataset.page); });
    });
    var logoutBtn = document.getElementById('rk-desk-logout');
    if (logoutBtn) logoutBtn.addEventListener('click', function () {
      Core.storeClear(); Core.redirect(RK.config.login + '?logged_out=1');
    });
  }

  /* ══════════════════════════════════════════════════════════
     MOBILE UI (topbar + drawer)
     ══════════════════════════════════════════════════════════ */
  function renderMobileUI(coach) {
    var S       = RK.state;
    var pending = S.home.pending || 0;
    var unread  = S.home.unread  || 0;
    var name    = Utils.e(coach.name || Core.storeGet(Core.KEYS.name) || 'المدرب');
    var avatar  = coach.avatar || Core.storeGet(Core.KEYS.avatar) || RK.config.logo;

    var topAv = document.getElementById('rk-mob-topbar-avatar');
    if (topAv) { topAv.src = avatar; topAv.alt = name; }

    var dHead = document.getElementById('rk-mob-dwr-head');
    if (dHead) {
      dHead.innerHTML =
        '<button class="rk-mob-dwr-close" id="rk-mob-dwr-close" type="button" aria-label="إغلاق القائمة">' + Icons.iClose + '</button>'
        + '<img class="rk-mob-dwr-avatar" src="' + Utils.e(avatar) + '" alt="' + name + '" width="46" height="46" onerror="this.src=\'' + Utils.e(RK.config.logo) + '\'">'
        + '<div><p class="rk-mob-dwr-name">' + name + '</p><p class="rk-mob-dwr-role">مدرب RiadaKids ⭐</p></div>';
      var closeBtn = document.getElementById('rk-mob-dwr-close');
      if (closeBtn) closeBtn.addEventListener('click', closeDrawer);
    }

    var dNav = document.getElementById('rk-mob-dwr-nav');
    if (dNav) {
      dNav.innerHTML = buildNavHtml(navItems(pending, unread), 'rk-mob-dwr-item', 'rk-mob-dwr-group', 'rk-mob-dwr-group-label', 'rk-mob-dwr-icon', 'rk-mob-dwr-badge');
      dNav.querySelectorAll('.rk-mob-dwr-item:not([href])').forEach(function (el) {
        el.addEventListener('click', function () { RK.dispatch.nav(el.dataset.page); closeDrawer(); });
      });
    }

    var dFoot = document.getElementById('rk-mob-dwr-footer');
    if (dFoot) {
      dFoot.innerHTML = '<button class="rk-mob-dwr-logout" id="rk-mob-logout" type="button">' + Icons.iLogout + ' تسجيل الخروج</button>';
      var mobLogout = document.getElementById('rk-mob-logout');
      if (mobLogout) mobLogout.addEventListener('click', function () {
        Core.storeClear(); Core.redirect(RK.config.login + '?logged_out=1');
      });
    }

    setBottomNavBadges(pending, unread);
  }

  function setBottomNavBadges(pending, unread) {
    var map = { evals: pending, messages: unread };
    Object.keys(map).forEach(function (pg) {
      var count = map[pg];
      var btn   = document.querySelector('.rk-bnav-item[data-page="' + pg + '"]');
      if (!btn) return;
      var existing = btn.querySelector('.rk-bnav-badge');
      if (existing) existing.remove();
      if (count > 0) {
        var sp = document.createElement('span');
        sp.className   = 'rk-bnav-badge';
        sp.textContent = count > 9 ? '9+' : String(count);
        btn.appendChild(sp);
      }
    });
  }

  RK.Nav = {
    setActiveNav: setActiveNav,
    openDrawer: openDrawer,
    closeDrawer: closeDrawer,
    navItems: navItems,
    buildNavHtml: buildNavHtml,
    renderDesktopSidebar: renderDesktopSidebar,
    renderMobileUI: renderMobileUI,
    setBottomNavBadges: setBottomNavBadges
  };
})();