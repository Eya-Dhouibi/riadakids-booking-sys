/**
 * RiadaKids — Tutor LMS Dashboard Integration JS  (v7.1.0 — Sprint 6)
 * Session countdown · Message tabs · AJAX send
 */
(function () {
    'use strict';

    function qs(sel) { return document.querySelector(sel); }
    function qsa(sel) { return Array.prototype.slice.call(document.querySelectorAll(sel)); }

    /* ── Session countdown ─────────────────────────────────────────── */

    function initCountdown() {
        var el = qs('#rk-countdown');
        if (!el) return;

        var ts = parseInt(el.getAttribute('data-ts'), 10);
        if (!ts || ts <= 0) return;

        function render() {
            var diff = ts - Math.floor(Date.now() / 1000);
            if (diff <= 0) { el.textContent = 'الجلسة الآن'; return; }
            var days  = Math.floor(diff / 86400);
            var hours = Math.floor((diff % 86400) / 3600);
            var mins  = Math.floor((diff % 3600) / 60);
            var parts = [];
            if (days)  parts.push(days  + ' يوم');
            if (hours) parts.push(hours + ' ساعة');
            if (mins || !parts.length) parts.push(mins + ' دقيقة');
            el.textContent = 'بعد ' + parts.join(' و ');
        }

        render();
        setInterval(render, 60000);
    }

    /* ── Message tab switching ─────────────────────────────────────── */

    function initMsgTabs() {
        var tabs   = qsa('.rk-msgs-tab');
        var panels = qsa('.rk-msgs-panel');
        if (!tabs.length) return;

        tabs.forEach(function (tab) {
            tab.addEventListener('click', function () {
                var ch = this.getAttribute('data-channel');

                tabs.forEach(function (t) {
                    t.classList.remove('rk-msgs-tab--active');
                    t.setAttribute('aria-selected', 'false');
                });
                this.classList.add('rk-msgs-tab--active');
                this.setAttribute('aria-selected', 'true');

                panels.forEach(function (p) { p.classList.add('rk-msgs-panel--hidden'); });

                var target = qs('#rk-msgs-panel-' + ch);
                if (target) {
                    target.classList.remove('rk-msgs-panel--hidden');
                    scrollList(target.querySelector('.rk-msgs-list'));
                }
            });
        });

        panels.forEach(function (p) {
            if (!p.classList.contains('rk-msgs-panel--hidden')) {
                scrollList(p.querySelector('.rk-msgs-list'));
            }
        });
    }

    function scrollList(list) {
        if (list) list.scrollTop = list.scrollHeight;
    }

    /* ── AJAX message send ─────────────────────────────────────────── */

    function initMsgForms() {
        var cfg = window.rkTdMsgCfg;
        if (!cfg) return;

        qsa('.rk-msgs-form').forEach(function (form) {
            form.addEventListener('submit', function (e) {
                e.preventDefault();

                var channel  = form.getAttribute('data-channel') || 'coach';
                var toId     = parseInt(form.getAttribute('data-to'), 10) || 0;
                var textarea = form.querySelector('.rk-msgs-input');
                var btn      = form.querySelector('.rk-btn--send');
                var body     = textarea ? textarea.value.trim() : '';

                if (!body || !toId) return;

                btn.disabled = true;

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
                        btn.disabled = false;
                        if (!res.success) {
                            showError(form, res.data ? res.data.msg : 'خطأ');
                            return;
                        }
                        textarea.value = '';
                        appendMsg(channel, res.data.body, res.data.time, true);
                    })
                    .catch(function () {
                        btn.disabled = false;
                        showError(form, 'خطأ في الاتصال');
                    });
            });
        });
    }

    function appendMsg(channel, text, time, outgoing) {
        var list = qs('#rk-msgs-list-' + channel);
        if (!list) return;

        var empty = list.querySelector('.rk-msgs-empty');
        if (empty) empty.remove();

        var div = document.createElement('div');
        div.className = 'rk-msg' + (outgoing ? ' rk-msg--out' : '');
        div.innerHTML = '<p>' + escHtml(text) + '</p><time>' + escHtml(time) + '</time>';
        list.appendChild(div);
        scrollList(list);
    }

    function showError(form, msg) {
        var old = form.parentNode.querySelector('.rk-msgs-error');
        if (old) old.remove();
        var err = document.createElement('p');
        err.className = 'rk-msgs-error';
        err.style.cssText = 'color:#e11d48;font-size:13px;padding:4px 0;';
        err.textContent = msg;
        form.parentNode.insertBefore(err, form);
        setTimeout(function () { if (err.parentNode) err.parentNode.removeChild(err); }, 4000);
    }

    function escHtml(s) {
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    /* ── Boot ──────────────────────────────────────────────────────── */

    document.addEventListener('DOMContentLoaded', function () {
        initCountdown();
        initMsgTabs();
        initMsgForms();
    });
})();
