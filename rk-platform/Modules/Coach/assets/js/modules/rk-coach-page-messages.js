/**
 * rk-coach-page-messages.js — Messagerie temps réel (liste + thread)
 * Namespace: RKCoach.Pages.Messages
 */
(function () {
  'use strict';
  var RK    = window.RKCoach;
  var Core  = RK.Core;
  var Utils = RK.Utils;
  var S     = RK.state;

  var msgState = { partnerId: 0, childId: 0, threadId: 0, poll: null };

  function render() {
    if (msgState.poll) { clearInterval(msgState.poll); msgState.poll = null; }

    Utils.setMain('<div><p class="rk-page-title" style="margin-bottom:16px;">الرسائل</p>'
      + '<div class="rk-section-loading">جاري تحميل المحادثات…</div></div>');

    Core.apiGet('/coach/messages', false).then(function (convos) {
      drawLayout(Array.isArray(convos) ? convos : []);
    }).catch(function () {
      Utils.setMain('<div style="padding:24px;color:#b91c1c;">تعذّر تحميل المحادثات.</div>');
    });
  }

  function drawLayout(convos) {
    var convHtml = convos.length ? convos.map(function (c) {
      var isParent = c.partner_type === 'parent';
      var typeLabel = isParent ? 'ولي الأمر' : 'الطفل';
      var typeClr   = isParent ? '#4C95D7' : '#FF4411';
      var typeBg    = isParent ? '#eff6ff' : '#fff5f0';
      var badge     = c.unread > 0
        ? '<span style="min-width:18px;height:18px;border-radius:50%;background:#FF4411;color:#fff;font-size:.65rem;font-weight:700;display:inline-flex;align-items:center;justify-content:center;padding:0 4px;">' + c.unread + '</span>'
        : '';
      return '<div class="rk-msg-conv" data-pid="' + c.partner_id + '" data-cid="' + c.child_id + '"'
        + ' onclick="openMsgThread(' + c.partner_id + ',' + c.child_id + ')"'
        + ' style="display:flex;align-items:center;gap:10px;padding:12px 14px;cursor:pointer;border-bottom:1px solid #f1f5f9;transition:background .15s;">'
        + '<img src="' + Utils.e(c.partner_avatar || '') + '" width="36" height="36" style="border-radius:50%;flex-shrink:0;object-fit:cover;">'
        + '<div style="flex:1;min-width:0;">'
        +   '<div style="display:flex;align-items:center;gap:5px;margin-bottom:2px;">'
        +   '<span style="font-weight:700;font-size:.83rem;color:#1e293b;">' + Utils.e(c.child_name) + '</span>'
        +   '<span style="font-size:.65rem;font-weight:600;color:' + typeClr + ';background:' + typeBg + ';padding:1px 7px;border-radius:20px;flex-shrink:0;">' + typeLabel + '</span>'
        +   '</div>'
        +   '<div style="font-size:.73rem;color:#94a3b8;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">'
        +   Utils.e(c.last_message || (c.thread_id ? '' : 'ابدأ المحادثة'))
        +   '</div>'
        + '</div>'
        + '<div style="display:flex;flex-direction:column;align-items:flex-end;gap:4px;flex-shrink:0;">'
        + (c.last_date ? '<span style="font-size:.65rem;color:#94a3b8;">' + Utils.e(c.last_date.slice(0, 10)) + '</span>' : '')
        + badge
        + '</div>'
        + '</div>';
    }).join('')
    : '<div style="padding:32px 16px;text-align:center;color:#94a3b8;font-size:.85rem;">لا يوجد طلاب لمراسلتهم.</div>';

    var html = '<div>'
      + '<p class="rk-page-title" style="margin-bottom:16px;">الرسائل</p>'
      + '<div style="display:flex;height:calc(100vh - 200px);min-height:480px;border:1px solid #e2e8f0;border-radius:12px;overflow:hidden;">'
      + '<div id="rk-msg-list" style="width:270px;flex-shrink:0;border-left:1px solid #e2e8f0;overflow-y:auto;background:#fff;">'
      +   '<div style="padding:10px 14px;background:#f8fafc;border-bottom:1px solid #e2e8f0;">'
      +   '<span style="font-size:.72rem;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.03em;">المحادثات</span>'
      +   '</div>'
      +   convHtml
      + '</div>'
      + '<div id="rk-msg-thread" style="flex:1;display:flex;flex-direction:column;background:#f8fafc;overflow:hidden;">'
      +   '<div style="flex:1;display:flex;align-items:center;justify-content:center;flex-direction:column;gap:12px;color:#94a3b8;">'
      +   '<svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2" style="color:#cbd5e1;"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>'
      +   '<span style="font-size:.88rem;">اختر محادثة لعرض الرسائل</span>'
      +   '</div>'
      + '</div>'
      + '</div>'
      + '</div>';

    Utils.setMain(html);

    if (msgState.partnerId) openThread(msgState.partnerId, msgState.childId);
  }

  function openThread(partnerId, childId) {
    msgState.partnerId = partnerId;
    msgState.childId   = childId;

    document.querySelectorAll('.rk-msg-conv').forEach(function (el) {
      el.style.background = parseInt(el.dataset.pid, 10) === partnerId ? '#eff6ff' : '';
    });

    var threadEl = document.getElementById('rk-msg-thread');
    if (!threadEl) return;

    threadEl.style.display = 'flex';
    threadEl.style.flexDirection = 'column';
    threadEl.innerHTML =
      '<div id="rk-msg-messages" style="flex:1;overflow-y:auto;padding:16px 20px;direction:rtl;">'
      + '<div style="text-align:center;color:#94a3b8;padding:20px;font-size:.85rem;">جاري تحميل الرسائل…</div>'
      + '</div>'
      + '<div style="padding:10px 16px 12px;border-top:1px solid #e2e8f0;background:#fff;flex-shrink:0;">'
      +   '<div style="display:flex;gap:8px;align-items:flex-end;">'
      +   '<textarea id="rk-msg-input" dir="rtl" placeholder="اكتب رسالة…" rows="1"'
      +   ' style="flex:1;border:1px solid #e2e8f0;border-radius:10px;padding:10px 14px;font-family:inherit;font-size:.88rem;resize:none;min-height:44px;max-height:120px;outline:none;transition:border-color .15s;"></textarea>'
      +   '<button onclick="submitMsg(' + partnerId + ',' + childId + ')"'
      +   ' style="height:44px;padding:0 20px;border-radius:10px;background:var(--e-global-color-primary,#FF4411);color:#fff;font-weight:700;font-size:1rem;border:none;cursor:pointer;flex-shrink:0;transition:opacity .15s;" title="إرسال">→</button>'
      +   '</div>'
      + '</div>';

    loadThread(partnerId, childId, false);

    if (msgState.poll) clearInterval(msgState.poll);
    msgState.poll = setInterval(function () {
      if (S.page !== 'messages' || msgState.partnerId !== partnerId) {
        clearInterval(msgState.poll);
        msgState.poll = null;
        return;
      }
      loadThread(partnerId, childId, true);
    }, 10000);
  }

  function loadThread(partnerId, childId, silent) {
    Core.apiGet('/coach/messages/thread?partner_id=' + partnerId + '&child_id=' + childId, false)
      .then(function (data) {
        msgState.threadId = data.thread_id || 0;
        drawBubbles(data.messages || [], !!silent);
      })
      .catch(function () {
        if (!silent) {
          var el = document.getElementById('rk-msg-messages');
          if (el) el.innerHTML = '<p style="text-align:center;color:#b91c1c;padding:20px;">تعذّر تحميل الرسائل.</p>';
        }
      });
  }

  function drawBubbles(messages, silent) {
    var el = document.getElementById('rk-msg-messages');
    if (!el) return;

    var atBottom = el.scrollHeight - el.scrollTop - el.clientHeight < 80;

    var html = messages.length
      ? messages.map(function (m) {
          var mine = !!m.is_mine;
          return '<div style="display:flex;flex-direction:' + (mine ? 'row-reverse' : 'row') + ';margin-bottom:8px;">'
            + '<div style="max-width:72%;padding:9px 14px;'
            +   'border-radius:' + (mine ? '14px 4px 14px 14px' : '4px 14px 14px 14px') + ';'
            +   'background:' + (mine ? 'var(--e-global-color-primary,#FF4411)' : '#fff') + ';'
            +   'color:' + (mine ? '#fff' : '#1e293b') + ';'
            +   'font-size:.85rem;line-height:1.55;box-shadow:0 1px 3px rgba(0,0,0,.08);word-break:break-word;">'
            +   '<div>' + Utils.e(m.content) + '</div>'
            +   '<div style="font-size:.63rem;opacity:.65;margin-top:4px;">' + Utils.e(m.date_sent || '') + '</div>'
            + '</div></div>';
        }).join('')
      : '<div style="text-align:center;color:#94a3b8;padding:32px;font-size:.85rem;">لا توجد رسائل بعد — ابدأ المحادثة.</div>';

    el.innerHTML = html;
    if (atBottom || !silent) el.scrollTop = el.scrollHeight;
  }

  function submit(partnerId, childId) {
    var inputEl = document.getElementById('rk-msg-input');
    if (!inputEl) return;
    var content = inputEl.value.trim();
    if (!content) return;

    inputEl.disabled = true;
    Core.apiPost('/coach/messages/send', { partner_id: partnerId, child_id: childId, content: content })
      .then(function (res) {
        inputEl.disabled = false;
        if (!res || !res.success) { alert('فشل إرسال الرسالة.'); return; }

        inputEl.value    = '';
        msgState.threadId = res.thread_id || msgState.threadId;

        var msgsEl = document.getElementById('rk-msg-messages');
        if (msgsEl) {
          var node = document.createElement('div');
          node.style.cssText = 'display:flex;flex-direction:row-reverse;margin-bottom:8px;';
          node.innerHTML =
            '<div style="max-width:72%;padding:9px 14px;border-radius:14px 4px 14px 14px;'
            + 'background:var(--e-global-color-primary,#FF4411);color:#fff;font-size:.85rem;'
            + 'line-height:1.55;box-shadow:0 1px 3px rgba(0,0,0,.08);word-break:break-word;">'
            + '<div>' + Utils.e(content) + '</div>'
            + '<div style="font-size:.63rem;opacity:.65;margin-top:4px;">' + Utils.e(res.date_sent || '') + '</div>'
            + '</div>';
          msgsEl.appendChild(node);
          msgsEl.scrollTop = msgsEl.scrollHeight;
        }
      })
      .catch(function () {
        inputEl.disabled = false;
        alert('خطأ في الاتصال.');
      });
  }

  RK.Pages = RK.Pages || {};
  RK.Pages.Messages = {
    render: render,
    openThread: openThread,
    submit: submit
  };
})();