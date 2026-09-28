/**
 * rk-coach-utils.js — Utilitaires transverses : escape XSS, avatars,
 * dates, KPI card, toast, modal générique.
 * Namespace: RKCoach.Utils
 */
(function () {
  'use strict';
  var RK = window.RKCoach || (window.RKCoach = {});

  function setMain(html) {
    var el = document.getElementById('rk-main');
    if (el) el.innerHTML = html;
  }

  /** Escaper XSS — toutes les données API passent par ici avant innerHTML */
  function e(s) {
    if (s === null || s === undefined) return '';
    return String(s)
      .replace(/&/g,  '&amp;')
      .replace(/</g,  '&lt;')
      .replace(/>/g,  '&gt;')
      .replace(/"/g,  '&quot;')
      .replace(/'/g,  '&#39;');
  }

  /** SVG avatar avec initiale colorée (fallback quand pas de photo) */
  function svgAv(name, size) {
    var letter  = (name || '؟').charAt(0).toUpperCase();
    var palette = ['#1B4F8C','#0891b2','#7c3aed','#059669','#E8500A','#d97706','#be185d'];
    var color   = palette[(name || '').charCodeAt(0) % palette.length] || '#1B4F8C';
    var fs      = Math.round(size * 0.38);
    return 'data:image/svg+xml,' + encodeURIComponent(
      '<svg xmlns="http://www.w3.org/2000/svg" width="' + size + '" height="' + size + '">'
      + '<rect width="' + size + '" height="' + size + '" fill="' + color + '" rx="' + Math.round(size / 2) + '"/>'
      + '<text x="50%" y="50%" dominant-baseline="central" text-anchor="middle" '
      + 'font-family="Tajawal,sans-serif" font-size="' + fs + '" fill="#fff" font-weight="700">'
      + letter + '</text></svg>'
    );
  }

  /** Retourne l'URL d'avatar : photo réelle si dispo, sinon SVG initiale */
  function av(name, url, size) {
    return url && url.indexOf('gravatar') === -1 ? e(url) : e(svgAv(name, size));
  }

  function fmtDate(d) {
    if (!d) return '';
    var p = String(d).split('-');
    return p.length === 3 ? p[2] + '/' + p[1] + '/' + p[0] : d;
  }

  function kpi(color, icon, val, label) {
    return '<div class="rk-ch-kpi" style="--kpi-color:' + color + '">'
      + '<div class="rk-ch-kpi__icon" style="background:' + color + '20;color:' + color + ';">' + icon + '</div>'
      + '<div class="rk-ch-kpi__val">'   + Number(val) + '</div>'
      + '<div class="rk-ch-kpi__label">' + e(label)    + '</div></div>';
  }

  /* ── Toast (v9.57 — refonte UX) ───────────────────────────────
   * Ajouts par rapport a la version precedente :
   *   - icone + titre par type (lecture plus rapide qu'une ligne nue)
   *   - anti-doublon : un meme message relance la barre au lieu
   *     d'empiler trois toasts identiques (double-clic, retry)
   *   - pile plafonnee a 3 : au-dela, le plus ancien est retire
   *   - pause au survol : l'utilisateur lit sans course contre la montre
   *   - duree adaptee : les erreurs restent plus longtemps (6s) que les
   *     confirmations (3.5s) ; 7s pour tout etait trop long
   *   - role/aria-live corrects pour les lecteurs d'ecran
   * ───────────────────────────────────────────────────────────── */
  function showToast(msg, type) {
    var container = document.getElementById('rk-toast-container');
    if (!container) return;

    type = type || 'info';
    var key = encodeURIComponent(msg || '');

    // Anti-doublon : relance la barre de progression du toast existant.
    var existing = container.querySelector('.rk-toast[data-msg="' + key + '"]');
    if (existing) {
      existing.classList.remove('rk-toast--out');
      var bar = existing.querySelector('.rk-toast__bar');
      if (bar) { bar.style.animation = 'none'; void bar.offsetWidth; bar.style.animation = ''; }
      return;
    }

    // Une pile qui deborde masque le contenu de la page.
    while (container.children.length >= 3) container.removeChild(container.firstChild);

    var ICONS = {
      success: '<svg viewBox="0 0 20 20" width="18" height="18" aria-hidden="true"><circle cx="10" cy="10" r="8" fill="none" stroke="currentColor" stroke-width="1.6"/><path d="M6.2 10.3l2.6 2.6 5-5.2" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
      error:   '<svg viewBox="0 0 20 20" width="18" height="18" aria-hidden="true"><circle cx="10" cy="10" r="8" fill="none" stroke="currentColor" stroke-width="1.6"/><path d="M10 5.8v5m0 3v.3" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>',
      info:    '<svg viewBox="0 0 20 20" width="18" height="18" aria-hidden="true"><circle cx="10" cy="10" r="8" fill="none" stroke="currentColor" stroke-width="1.6"/><path d="M10 9.2v5m0-8.4v.3" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>'
    };
    var TITLES = { success: 'تم بنجاح', error: 'حدث خطأ', info: 'معلومة' };

    var toast = document.createElement('div');
    toast.className = 'rk-toast rk-toast--' + type;
    toast.setAttribute('role', type === 'error' ? 'alert' : 'status');
    toast.setAttribute('aria-live', type === 'error' ? 'assertive' : 'polite');
    toast.dataset.msg = key;

    toast.innerHTML =
      '<span class="rk-toast__icon">' + (ICONS[type] || ICONS.info) + '</span>' +
      '<div class="rk-toast__body">' +
        '<p class="rk-toast__title">' + (TITLES[type] || TITLES.info) + '</p>' +
        '<p class="rk-toast__msg">' + (msg || '').replace(/</g, '&lt;').replace(/>/g, '&gt;') + '</p>' +
      '</div>' +
      '<button class="rk-toast__close" type="button" aria-label="إغلاق">&times;</button>' +
      '<div class="rk-toast__bar"></div>';

    container.appendChild(toast);

    var timer;
    function dismiss() {
      clearTimeout(timer);
      toast.classList.add('rk-toast--out');
      setTimeout(function () { if (toast.parentNode) toast.parentNode.removeChild(toast); }, 260);
    }

    toast.querySelector('.rk-toast__close').addEventListener('click', dismiss);

    toast.addEventListener('mouseenter', function () {
      clearTimeout(timer);
      toast.classList.add('rk-toast--paused');
    });
    toast.addEventListener('mouseleave', function () {
      toast.classList.remove('rk-toast--paused');
      timer = setTimeout(dismiss, 2500);
    });

    // La barre de progression doit durer exactement le temps d'affichage :
    // le CSS avait 7s figés, desynchronises de la fermeture reelle.
    var duration = (type === 'error') ? 6000 : 3500;
    toast.style.setProperty('--rk-toast-dur', duration + 'ms');

    timer = setTimeout(dismiss, duration);
  }

  /* ── Modal générique ─────────────────────────────────────────── */
  function showModal(title, bodyHtml) {
    var old = document.getElementById('rk-modal-overlay');
    if (old) old.remove();

    var overlay = document.createElement('div');
    overlay.id  = 'rk-modal-overlay';
    overlay.setAttribute('style',
      'position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;'
      + 'display:flex;align-items:center;justify-content:center;padding:20px;box-sizing:border-box;');

    overlay.innerHTML =
      '<div id="rk-modal-box" style="background:#fff;border-radius:14px;max-width:580px;width:100%;'
      + 'max-height:85vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.3);direction:rtl;">'
      + '<div style="display:flex;align-items:center;justify-content:space-between;padding:14px 18px;'
      + 'border-bottom:1px solid #e2e8f0;position:sticky;top:0;background:#fff;z-index:1;">'
      + '<p id="rk-modal-title" style="margin:0;font-weight:700;font-size:.92rem;">' + e(title) + '</p>'
      + '<button id="rk-modal-close" type="button" style="background:none;border:none;font-size:1.1rem;'
      + 'cursor:pointer;color:#6b7280;line-height:1;">✕</button>'
      + '</div>'
      + '<div id="rk-modal-body" style="padding:18px;">' + bodyHtml + '</div>'
      + '</div>';

    document.body.appendChild(overlay);
    document.getElementById('rk-modal-close').addEventListener('click', function () { overlay.remove(); });
    overlay.addEventListener('click', function (ev) { if (ev.target === overlay) overlay.remove(); });
  }

  function copyToClipboard(text) {
    if (!text) return;
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(function () {
        showToast('تم نسخ الرابط!', 'success');
      }).catch(function () { fallbackCopy(text); });
    } else {
      fallbackCopy(text);
    }
  }

  function fallbackCopy(text) {
    var inp = document.createElement('input');
    inp.value = text;
    inp.style.position = 'fixed';
    inp.style.opacity  = '0';
    document.body.appendChild(inp);
    inp.focus();
    inp.select();
    try { document.execCommand('copy'); showToast('تم نسخ الرابط!', 'success'); }
    catch (err) { showToast('يرجى النسخ يدوياً.', 'error'); }
    document.body.removeChild(inp);
  }

  RK.Utils = {
    setMain: setMain,
    e: e,
    svgAv: svgAv,
    av: av,
    fmtDate: fmtDate,
    kpi: kpi,
    showToast: showToast,
    showModal: showModal,
    copyToClipboard: copyToClipboard
  };
})();