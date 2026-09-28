/**
 * rk-coach-dashboard.js — Interactions UI du dashboard Tutor LMS (espace coach)
 *
 * Config dynamique (toasts, confetti) injectée via wp_localize_script() :
 *   window.rkCoachUI = { toasts: [...], confetti: false }
 */

/* global window, document */
(function () {
  'use strict';

  document.addEventListener('DOMContentLoaded', function () {

    /* ── Tabs (fiche élève) ───────────────────────────────────── */
    var tabs = document.querySelectorAll('.rk-ch-tab[data-tab]');
    tabs.forEach(function (tab) {
      tab.addEventListener('click', function (e) {
        e.preventDefault();
        var target = this.dataset.tab;
        tabs.forEach(function (t) { t.classList.remove('rk-ch-tab--active'); });
        document.querySelectorAll('.rk-ch-tab-panel').forEach(function (p) {
          p.classList.remove('rk-ch-tab-panel--active');
        });
        this.classList.add('rk-ch-tab--active');
        var panel = document.getElementById('tab-' + target);
        if (panel) panel.classList.add('rk-ch-tab-panel--active');
      });
    });

    /* ── Stars rating ────────────────────────────────────────── */
    var stars  = document.querySelectorAll('.rk-ch-star-btn');
    var hidden = document.getElementById('rk-rating-input');
    if (stars.length && hidden) {
      var cur = parseInt(hidden.value, 10) || 3;
      function hl(n) { stars.forEach(function (b, i) { b.classList.toggle('active', i < n); }); }
      stars.forEach(function (btn) {
        btn.addEventListener('click', function () { cur = +this.dataset.v; hidden.value = cur; hl(cur); });
        btn.addEventListener('mouseenter', function () { hl(+this.dataset.v); });
        btn.addEventListener('mouseleave', function () { hl(cur); });
      });
      hl(cur);
    }

    /* ── Skill sliders ───────────────────────────────────────── */
    document.querySelectorAll('.rk-ch-skill-slider').forEach(function (sl) {
      function upd() {
        var pct = (sl.value - sl.min) / (sl.max - sl.min) * 100;
        sl.style.setProperty('--pct', pct + '%');
        var valEl = sl.parentNode.querySelector('.rk-ch-skill-val');
        if (valEl) valEl.textContent = sl.value + '/5';
      }
      sl.addEventListener('input', upd);
      upd();
    });

    /* ── Student search ──────────────────────────────────────── */
    var searchInput = document.getElementById('rk-ch-student-search');
    var grid        = document.getElementById('rk-ch-students-grid');
    if (searchInput && grid) {
      searchInput.addEventListener('input', function () {
        var q = this.value.trim().toLowerCase();
        grid.querySelectorAll('.rk-ch-student-card').forEach(function (card) {
          var name = (card.querySelector('.rk-ch-student-card__name') || {}).textContent || '';
          card.style.display = (!q || name.toLowerCase().includes(q)) ? '' : 'none';
        });
      });
    }

    /* ── Message templates ───────────────────────────────────── */
    document.querySelectorAll('.rk-ch-tpl-btn').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var textarea = document.getElementById('rk-ch-msg-compose');
        if (textarea) textarea.value = (textarea.value ? textarea.value + '\n' : '') + this.dataset.tpl;
      });
    });

    /* ── Toasts + confetti (data injectée via wp_localize_script) ── */
    var ui = window.rkCoachUI || {};

    if (ui.toasts && ui.toasts.length) {
      var container = document.createElement('div');
      container.id  = 'rk-toast-container';
      document.body.appendChild(container);

      ui.toasts.forEach(function (t, i) {
        setTimeout(function () {
          var d    = document.createElement('div');
          var icon = t.type === 'success'
            ? '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#166534" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>'
            : '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#991b1b" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>';
          d.className = 'rk-toast rk-toast--' + t.type;
          d.innerHTML = icon + ' ' + t.msg;
          container.appendChild(d);
          setTimeout(function () {
            d.classList.add('rk-toast--hiding');
            setTimeout(function () { d.remove(); }, 280);
          }, 3500);
        }, i * 380);
      });
    }

    if (ui.confetti) {
      var colors = ['#1b4f8c', '#e8500a', '#fbbf24', '#22c55e', '#a78bfa'];
      for (var i = 0; i < 60; i++) {
        (function (idx) {
          setTimeout(function () {
            var p = document.createElement('div');
            p.className = 'rk-confetti-piece';
            p.style.left              = Math.random() * 100 + 'vw';
            p.style.background        = colors[Math.floor(Math.random() * colors.length)];
            p.style.width             = (8 + Math.random() * 8) + 'px';
            p.style.height            = (8 + Math.random() * 8) + 'px';
            p.style.animationDuration = (2 + Math.random() * 2) + 's';
            document.body.appendChild(p);
            setTimeout(function () { p.remove(); }, 4500);
          }, idx * 40);
        })(i);
      }
    }

  });

})();
