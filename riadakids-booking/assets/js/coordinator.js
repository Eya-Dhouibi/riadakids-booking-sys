/**
 * RiadaKids — Dashboard Coordinateur (Phase 3, lecture seule).
 * Toutes les données sont posées via textContent : aucun innerHTML sur des
 * données serveur. Aucune planification / aucun SSA à ce stade.
 */
(function () {
    'use strict';

    var cfg = window.rkCoordinator || {};
    var root = document.getElementById('rk-coord');
    if (!root || !cfg.ajaxUrl) return;

    var statusEl = document.getElementById('rk-coord-status');
    var panel = document.getElementById('rk-coord-panel');
    var panelBody = document.getElementById('rk-coord-panel-body');
    var panelTitle = document.getElementById('rk-coord-panel-title');
    var lastFocus = null;

    function el(tag, cls, text) {
        var n = document.createElement(tag);
        if (cls) n.className = cls;
        if (text !== undefined && text !== null) n.textContent = text;
        return n;
    }

    function post(action, data) {
        var fd = new FormData();
        fd.append('action', action);
        fd.append('nonce', cfg.nonce);
        Object.keys(data || {}).forEach(function (k) { fd.append(k, data[k]); });
        return fetch(cfg.ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json().catch(function () { return { success: false, data: { msg: 'bad_response' } }; }); })
            .then(function (j) {
                if (!j || !j.success) {
                    var msg = (j && j.data && j.data.msg) || 'error';
                    var err = new Error(msg); err.code = msg; throw err;
                }
                return j.data;
            });
    }

    function errText(e) {
        var c = e && e.code;
        if (c === 'not_logged_in') return 'انتهت الجلسة، يرجى تسجيل الدخول من جديد.';
        if (c === 'invalid_nonce') return 'انتهت صلاحية الصفحة، يرجى تحديثها.';
        if (c === 'forbidden') return 'غير مصرح لك بهذا الإجراء.';
        if (c === 'booking_not_found') return 'لم يعد هذا الطلب متاحاً.';
        return 'تعذّر التحميل، حاول مرة أخرى.';
    }

    // "2026-10-15 17:00:00" ou "2026-10-15T17:00:00" → "15/10/2026 — 17:00" (sans conversion de fuseau)
    function fmt(s) {
        var m = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/.exec(s || '');
        return m ? m[3] + '/' + m[2] + '/' + m[1] + ' — ' + m[4] + ':' + m[5] : (s || '—');
    }

    function childLabel(b) {
        var names = (b.children || []).map(function (c) { return c.name || ('#' + c.id); });
        return names.length ? names.join('، ') : '—';
    }

    function row(label, value) {
        var r = el('div', 'rk-coord__row');
        r.appendChild(el('span', 'rk-coord__label', label));
        r.appendChild(el('span', 'rk-coord__value', value || '—'));
        return r;
    }

    function card(b, variant) {
        var c = el('article', 'rk-coord__card rk-coord__card--' + variant);
        c.setAttribute('data-booking-id', b.booking_id);
        c.appendChild(el('h3', 'rk-coord__child', childLabel(b)));
        c.appendChild(row('ولي الأمر', b.parent && b.parent.name));
        c.appendChild(row('البرنامج', b.program));
        c.appendChild(row('الدورة', b.course));
        c.appendChild(row('الحصة', b.session));
        if (variant === 'pending' && b.proposal) {
            c.appendChild(row('المدرب', b.proposal.coach_name));
            c.appendChild(row('الموعد المقترح', fmt(b.proposal.datetime)));
            c.appendChild(row('المنسق', b.proposal.scheduled_by_name));
            c.appendChild(row('تاريخ البرمجة', fmt(b.proposal.scheduled_at)));
            c.appendChild(el('p', 'rk-coord__wait', 'بانتظار تأكيد ولي الأمر'));
        } else if (variant === 'changes') {
            if (b.proposal) c.appendChild(row('الموعد الحالي', fmt(b.proposal.datetime)));
            var note = el('blockquote', 'rk-coord__note', (b.change && b.change.note) || '—');
            c.appendChild(el('span', 'rk-coord__label', 'ملاحظة ولي الأمر'));
            c.appendChild(note);
            c.appendChild(row('تاريخ الطلب', fmt(b.change && b.change.requested_at)));
        } else {
            c.appendChild(row('تاريخ الطلب', fmt(b.requested_at)));
        }
        return c;
    }

    function fill(name, items, variant, caps) {
        var list = root.querySelector('[data-list="' + name + '"]');
        var count = root.querySelector('[data-count="' + name + '"]');
        list.textContent = '';
        count.textContent = String(items.length);
        if (!items.length) { list.appendChild(el('p', 'rk-coord__empty', 'لا توجد عناصر.')); return; }
        items.forEach(function (b) {
            var c = card(b, variant);
            var canAct = variant === 'new' ? caps.schedule : (variant === 'changes' ? caps.reschedule : false);
            if (canAct && caps.view_details) {
                var btn = el('button', 'rk-coord__btn', variant === 'changes' ? 'إعادة تحديد الموعد' : 'تحديد الموعد');
                btn.type = 'button';
                btn.addEventListener('click', function () { openPanel(b.booking_id, variant); });
                c.appendChild(btn);
            }
            list.appendChild(c);
        });
    }

    function loadDashboard() {
        statusEl.textContent = 'جارٍ التحميل…';
        statusEl.hidden = false;
        return post('rk_coord_get_dashboard').then(function (d) {
            var caps = d.caps || {};
            fill('new', d.new || [], 'new', caps);
            fill('pending', d.pending || [], 'pending', caps);
            var chSec = root.querySelector('[data-section="changes"]');
            if (d.changes === null) { chSec.hidden = true; }
            else { chSec.hidden = false; fill('changes', d.changes || [], 'changes', caps); }
            var coSec = root.querySelector('[data-section="coaches"]');
            if (caps.view_coaches) { coSec.hidden = false; loadCoaches(); } else { coSec.hidden = true; }
            statusEl.hidden = true;
        }).catch(function (e) { statusEl.textContent = errText(e); statusEl.hidden = false; });
    }

    function loadCoaches() {
        var list = root.querySelector('[data-list="coaches"]');
        return post('rk_coord_get_coaches').then(function (d) {
            var coaches = d.coaches || [];
            root.querySelector('[data-count="coaches"]').textContent = String(coaches.length);
            list.textContent = '';
            if (!coaches.length) { list.appendChild(el('p', 'rk-coord__empty', 'لا يوجد مدربون.')); return; }
            coaches.forEach(function (c) {
                var a = el('article', 'rk-coord__card rk-coord__card--coach');
                a.appendChild(el('h3', 'rk-coord__child', c.name));
                a.appendChild(row('مواعيد مقترحة بانتظار التأكيد', String(c.open_proposals)));
                list.appendChild(a);
            });
        }).catch(function (e) { list.textContent = ''; list.appendChild(el('p', 'rk-coord__empty', errText(e))); });
    }

    // ── Panneau de préparation (aucune planification en Phase 3) ───────
    function openPanel(id, variant) {
        lastFocus = document.activeElement;
        panelTitle.textContent = variant === 'changes' ? 'إعادة تحديد الموعد' : 'تحديد الموعد';
        panelBody.textContent = 'جارٍ التحميل…';
        panel.hidden = false;
        document.getElementById('rk-coord-panel-close').focus();

        post('rk_coord_get_booking', { booking_id: id }).then(function (d) {
            var b = d.booking, caps = d.caps || {};
            panelBody.textContent = '';
            panelBody.appendChild(card(b, variant === 'changes' ? 'changes' : 'new'));

            var coachField = el('div', 'rk-coord__field');
            coachField.appendChild(el('label', 'rk-coord__label', 'المدرب'));
            var sel = el('select', 'rk-coord__select');
            sel.id = 'rk-coord-coach';
            sel.disabled = !caps.assign_coach;
            sel.appendChild(new Option('اختر المدرب', ''));
            coachField.appendChild(sel);
            panelBody.appendChild(coachField);

            var soon = el('p', 'rk-coord__soon', 'اختيار التاريخ والوقت وإرسال الموعد سيُفعَّل في المرحلة القادمة.');
            panelBody.appendChild(soon);
            var send = el('button', 'rk-coord__btn', 'إرسال الموعد');
            send.type = 'button'; send.disabled = true;
            panelBody.appendChild(send);

            if (caps.assign_coach && caps.view_coaches) {
                post('rk_coord_get_coaches').then(function (r) {
                    (r.coaches || []).forEach(function (c) { sel.appendChild(new Option(c.name, String(c.id))); });
                }).catch(function () {});
            }
        }).catch(function (e) { panelBody.textContent = errText(e); });
    }

    function closePanel() {
        panel.hidden = true;
        panelBody.textContent = '';
        if (lastFocus && lastFocus.focus) lastFocus.focus();
    }

    document.getElementById('rk-coord-panel-close').addEventListener('click', closePanel);
    panel.addEventListener('click', function (ev) { if (ev.target === panel) closePanel(); });
    document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape' && !panel.hidden) closePanel(); });
    document.getElementById('rk-coord-refresh').addEventListener('click', loadDashboard);

    loadDashboard();
})();
