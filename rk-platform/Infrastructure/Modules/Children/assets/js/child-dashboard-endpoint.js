/**
 * RK – Child Dashboard endpoint script
 * Handles: countup · badge popup · session countdown · message tabs + AJAX · nav active state
 * Works with the illustrated 3-column design (rk-cdb-* CSS namespace).
 */
(function () {
    'use strict';

    function qs(sel)  { return document.querySelector(sel); }
    function qsa(sel) { return Array.prototype.slice.call(document.querySelectorAll(sel)); }

    function escHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    /* ── Count-up animation ─────────────────────────────────────── */

    function initCountUp() {
        qsa('.rk-countup[data-count-up]').forEach(function (el) {
            var target   = parseInt(el.getAttribute('data-count-up'), 10) || 0;
            var duration = 1200;
            var start    = null;
            function step(ts) {
                if (!start) start = ts;
                var pct = Math.min((ts - start) / duration, 1);
                el.textContent = Math.floor(pct * target).toLocaleString('ar-SA');
                if (pct < 1) window.requestAnimationFrame(step);
            }
            window.requestAnimationFrame(step);
        });
    }

    /* ── Badge popup ────────────────────────────────────────────── */

    function initBadgePopup() {
        var popup  = qs('#rk-badge-popup');
        var pImg   = qs('#rk-badge-popup-img');
        var pName  = qs('#rk-badge-popup-name');
        var pStory = qs('#rk-badge-popup-story');
        var pClose = qs('#rk-badge-popup-close');
        var pBack  = qs('#rk-badge-popup-backdrop');
        if (!popup) return;

        function openBadge(el) {
            if (!el.dataset.badgeEarned || el.dataset.badgeEarned === '0') return;
            if (pImg)   pImg.src          = el.dataset.badgeIcon  || '';
            if (pName)  pName.textContent  = el.dataset.badgeName  || '';
            if (pStory) pStory.textContent = el.dataset.badgeStory || '';
            popup.hidden = false;
            document.body.style.overflow = 'hidden';
            if (pClose) pClose.focus();
        }

        function closeBadge() {
            popup.hidden = true;
            document.body.style.overflow = '';
        }

        qsa('[data-badge-earned]').forEach(function (el) {
            el.addEventListener('click', function () { openBadge(el); });
            el.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); openBadge(el); }
            });
        });

        if (pClose) pClose.addEventListener('click', closeBadge);
        if (pBack)  pBack.addEventListener('click',  closeBadge);
        popup.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') closeBadge();
        });
    }

    /* ── Level-up close ─────────────────────────────────────────── */

    function initLevelUp() {
        var overlay = qs('#rk-levelup-overlay');
        var closeBtn = qs('#rk-levelup-close');
        if (!closeBtn || !overlay) return;
        closeBtn.addEventListener('click', function () { overlay.hidden = true; document.body.style.overflow = ''; });
    }

    /* ── Message tabs ───────────────────────────────────────────── */

    function initMsgTabs() {
        var tabs   = qsa('.rk-cdb-msg-tab');
        var panels = qsa('.rk-cdb-msg-panel');
        if (!tabs.length) return;

        tabs.forEach(function (tab) {
            tab.addEventListener('click', function () {
                var ch = this.getAttribute('data-channel');

                tabs.forEach(function (t) {
                    t.classList.remove('rk-cdb-msg-tab--active');
                    t.setAttribute('aria-selected', 'false');
                });
                this.classList.add('rk-cdb-msg-tab--active');
                this.setAttribute('aria-selected', 'true');

                panels.forEach(function (p) {
                    p.classList.remove('rk-cdb-msg-panel--active');
                    p.hidden = true;
                });
                var target = qs('#panel-' + ch);
                if (target) {
                    target.classList.add('rk-cdb-msg-panel--active');
                    target.hidden = false;
                    scrollMsgList(target.querySelector('.rk-cdb-msg-list'));
                }
            });
        });

        panels.forEach(function (p) {
            if (!p.hidden) scrollMsgList(p.querySelector('.rk-cdb-msg-list'));
        });
    }

    function scrollMsgList(list) {
        if (list) list.scrollTop = list.scrollHeight;
    }

    /* ── Message AJAX send ──────────────────────────────────────── */

    function initMsgForms() {
        var cfg = window.rkMsgCfg;
        if (!cfg) return;

        qsa('.rk-cdb-msg-form').forEach(function (form) {
            form.addEventListener('submit', function (e) {
                e.preventDefault();

                var channel  = form.getAttribute('data-channel') || 'coach';
                var toId     = parseInt(form.getAttribute('data-to'), 10) || 0;
                var textarea = form.querySelector('.rk-cdb-msg-input');
                var btn      = form.querySelector('.rk-cdb-msg-send');
                var body     = textarea ? textarea.value.trim() : '';

                if (!body || !toId) return;
                if (btn) btn.disabled = true;

                var fd = new FormData();
                fd.append('action',   'rk_mc_send_message');
                fd.append('nonce',    cfg.nonce);
                fd.append('child_id', cfg.childId);
                fd.append('to_id',    toId);
                fd.append('channel',  channel);
                fd.append('body',     body);

                fetch(cfg.ajaxUrl, { method: 'POST', body: fd })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        if (btn) btn.disabled = false;
                        if (!res.success) { showMsgError(form, res.data ? res.data.msg : 'خطأ'); return; }
                        if (textarea) textarea.value = '';
                        appendBubble(channel, res.data.body, res.data.time, true);
                    })
                    .catch(function () {
                        if (btn) btn.disabled = false;
                        showMsgError(form, 'خطأ في الاتصال');
                    });
            });
        });
    }

    function appendBubble(channel, text, time, outgoing) {
        var list = qs('#rk-msg-list-' + channel);
        if (!list) return;
        var placeholder = list.querySelector('.rk-cdb-msg-empty');
        if (placeholder) placeholder.remove();
        var div = document.createElement('div');
        div.className = 'rk-cdb-msg-bubble' + (outgoing ? ' rk-cdb-msg-bubble--out' : '');
        div.innerHTML = '<p>' + escHtml(text) + '</p><time>' + escHtml(time) + '</time>';
        list.appendChild(div);
        scrollMsgList(list);
    }

    function showMsgError(form, msg) {
        var existing = form.parentNode.querySelector('.rk-cdb-msg-error');
        if (existing) existing.remove();
        var err = document.createElement('p');
        err.className = 'rk-cdb-msg-error';
        err.style.cssText = 'font-size:.82rem;color:#dc2626;padding:6px 14px;margin:0';
        err.textContent = msg;
        form.parentNode.insertBefore(err, form);
        window.setTimeout(function () { if (err.parentNode) err.parentNode.removeChild(err); }, 4000);
    }

    /* ── Bottom nav active state via IntersectionObserver ────────── */

    function initBottomNav() {
        var navItems = qsa('.rk-cdb-nav-item');
        if (!navItems.length) return;

        var sectionIds = ['dashboard-map', 'dashboard-powers', 'dashboard-badges', 'section-messages'];
        var sections   = sectionIds.map(function (id) { return qs('#' + id); }).filter(Boolean);

        /* Smooth scroll */
        navItems.forEach(function (item) {
            item.addEventListener('click', function (e) {
                var href = this.getAttribute('href') || '';
                if (href.charAt(0) === '#') {
                    var target = qs(href);
                    if (target) { e.preventDefault(); target.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
                }
            });
        });

        /* Active highlight via IO */
        if (!window.IntersectionObserver) return;
        var observer = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    var id = entry.target.id;
                    navItems.forEach(function (item) {
                        var href = (item.getAttribute('href') || '').replace('#', '');
                        item.classList.toggle('active', href === id);
                    });
                }
            });
        }, { threshold: 0.3 });

        sections.forEach(function (s) { observer.observe(s); });
    }

    /* ── Session countdown ──────────────────────────────────────── */

    function initSessionCountdown() {
        var wraps = qsa('[data-session-ts], .rk-session-card__countdown-wrap[data-ts]');
        wraps.forEach(function (wrap) {
            var ts = parseInt(wrap.getAttribute('data-session-ts') || wrap.getAttribute('data-ts'), 10);
            if (!ts || ts <= 0) return;
            var dEl = wrap.querySelector('#rk-cd-days,   .rk-cd-block__num:nth-child(1)');
            var hEl = wrap.querySelector('#rk-cd-hours,  .rk-cd-block__num:nth-child(2)');
            var mEl = wrap.querySelector('#rk-cd-mins,   .rk-cd-block__num:nth-child(3)');
            var sEl = wrap.querySelector('#rk-cd-secs,   .rk-cd-block__num:nth-child(4)');

            function pad(n) { return n < 10 ? '0' + n : '' + n; }
            function render() {
                var diff = ts - Math.floor(Date.now() / 1000);
                if (diff <= 0) { if (dEl) dEl.textContent = '00'; if (hEl) hEl.textContent = '00'; if (mEl) mEl.textContent = '00'; if (sEl) sEl.textContent = '00'; return; }
                if (dEl) dEl.textContent = pad(Math.floor(diff / 86400));
                if (hEl) hEl.textContent = pad(Math.floor((diff % 86400) / 3600));
                if (mEl) mEl.textContent = pad(Math.floor((diff % 3600) / 60));
                if (sEl) sEl.textContent = pad(diff % 60);
            }
            render();
            window.setInterval(render, 1000);
        });
    }

    /* ── Boot ───────────────────────────────────────────────────── */

    document.addEventListener('DOMContentLoaded', function () {
        initCountUp();
        initBadgePopup();
        initLevelUp();
        initMsgTabs();
        initMsgForms();
        initBottomNav();
        initSessionCountdown();
    });

})();
