/**
 * rk-coach-notifications.js — Cloche, panel, toasts temps réel
 * Namespace: RKCoach.Notifications
 */
(function () {
  'use strict';
  var RK    = window.RKCoach;
  var Core  = RK.Core;
  var Utils = RK.Utils;

  var _notifPollTimer  = null;
  var _notifCache      = { items: [], unread: 0 };
  var _notifLastMaxId  = -1;

  function rkNotifBells() {
    return Array.prototype.slice.call(document.querySelectorAll('.rk-notif-bell'));
  }

  function init() {
    var bells = rkNotifBells();
    var panel = document.getElementById('rk-notif-panel');
    if (!bells.length || !panel) return;

    bells.forEach(function (bell) {
      bell.addEventListener('click', function (ev) {
        ev.stopPropagation();
        if (!panel.hidden) { closePanel(); } else { openPanel(); }
      });
    });

    document.addEventListener('click', function (ev) {
      if (!panel.hidden && !panel.contains(ev.target) && !ev.target.closest('.rk-notif-bell')) {
        closePanel();
      }
    });

    fetchNotifications();
    _notifPollTimer = setInterval(fetchNotifications, 60000);
  }

  function openPanel() {
    var panel = document.getElementById('rk-notif-panel');
    if (!panel) return;
    panel.hidden = false;
    rkNotifBells().forEach(function (b) { b.setAttribute('aria-expanded', 'true'); });
    renderPanel(panel, _notifCache);
    setTimeout(markRead, 1800);
  }

  function closePanel() {
    var panel = document.getElementById('rk-notif-panel');
    if (panel) panel.hidden = true;
    rkNotifBells().forEach(function (b) { b.setAttribute('aria-expanded', 'false'); });
  }

  /* ── TOASTS temps réel ─────────────────────────────────────── */
  function toastZone() {
    var z = document.getElementById('rk-toast-zone');
    if (!z) {
      z = document.createElement('div');
      z.id = 'rk-toast-zone';
      z.setAttribute('aria-live', 'polite');
      document.body.appendChild(z);
    }
    return z;
  }

  function showRealtimeToast(item) {
    var zone = toastZone();
    while (zone.children.length >= 3) zone.removeChild(zone.firstChild);
    var el = document.createElement('div');
    el.className = 'rk-toast';
    el.setAttribute('role', 'status');
    el.innerHTML = '<div class="rk-toast__body">'
      + '<p class="rk-toast__title">' + Utils.e(item.title || 'إشعار') + '</p>'
      + '<p class="rk-toast__msg">'  + Utils.e(item.body  || '')       + '</p>'
      + '</div>'
      + '<button class="rk-toast__close" aria-label="إغلاق">✕</button>'
      + '<span class="rk-toast__bar"></span>';
    function kill() { if (!el.parentNode) return; el.classList.add('rk-toast--out'); setTimeout(function () { el.remove(); }, 280); }
    el.querySelector('.rk-toast__close').addEventListener('click', function (ev) { ev.stopPropagation(); kill(); });
    el.addEventListener('click', function () { kill(); openPanel(); });
    zone.appendChild(el);
    setTimeout(kill, 7000);
  }

  function diffAndToast(items) {
    var maxId = _notifLastMaxId;
    (items || []).forEach(function (it) {
      var id = parseInt(it.id, 10) || 0;
      if (_notifLastMaxId >= 0 && id > _notifLastMaxId) showRealtimeToast(it);
      if (id > maxId) maxId = id;
    });
    _notifLastMaxId = maxId;
  }

  function fetchNotifications() {
    Core.apiGet('/coach/notifications', false).then(function (data) {
      if (!data) return;
      _notifCache = data;
      updateBadge(data.unread || 0);
      diffAndToast(data.items);
      var panel = document.getElementById('rk-notif-panel');
      if (panel && !panel.hidden) renderPanel(panel, data);
    }).catch(function () {});
  }

  function updateBadge(count) {
    var badges = document.querySelectorAll('.rk-notif-badge, #rk-notif-badge');
    Array.prototype.forEach.call(badges, function (badge) {
      if (count > 0) {
        badge.textContent = count > 9 ? '9+' : String(count);
        badge.hidden = false;
      } else {
        badge.hidden = true;
      }
    });
  }

  function renderPanel(panel, data) {
    var items  = (data && data.items) || [];
    var unread = (data && data.unread) || 0;

    var head = '<div class="rk-notif-panel__head">'
      + '<p class="rk-notif-panel__title">🔔 الإشعارات' + (unread > 0 ? ' <span style="color:#FF4411;font-size:12px;">(' + unread + ')</span>' : '') + '</p>'
      + (unread > 0 ? '<button class="rk-notif-panel__mark" onclick="window._rkMarkNotifsRead()">تعليم كمقروء</button>' : '')
      + '</div>';

    var body = '';
    if (!items.length) {
      body = '<p class="rk-notif-empty">لا توجد إشعارات</p>';
    } else {
      items.forEach(function (it) {
        var read = it.is_read == 1 || it.is_read === '1';
        var cls  = read ? 'rk-notif-item rk-notif-item--read' : 'rk-notif-item rk-notif-item--unread';
        body += '<div class="' + cls + '">'
          + '<span class="rk-notif-dot" aria-hidden="true"></span>'
          + '<div class="rk-notif-item__body">'
          + '<p class="rk-notif-item__title">' + Utils.e(it.title || 'إشعار') + '</p>'
          + '<p class="rk-notif-item__msg">'  + Utils.e(it.body  || '')       + '</p>'
          + '</div>'
          + '<span class="rk-notif-item__date">' + Utils.e(it.date_fmt || '') + '</span>'
          + '</div>';
      });
    }

    panel.innerHTML = head + body;
  }

  function markRead() {
    Core.apiPost('/coach/notifications/read', {}).then(function () {
      _notifCache.unread = 0;
      updateBadge(0);
      (_notifCache.items || []).forEach(function (it) { it.is_read = 1; });
      var panel = document.getElementById('rk-notif-panel');
      if (panel && !panel.hidden) renderPanel(panel, _notifCache);
    }).catch(function () {});
  }

  RK.Notifications = {
    init: init,
    openPanel: openPanel,
    closePanel: closePanel,
    fetchNotifications: fetchNotifications,
    markRead: markRead
  };
})();