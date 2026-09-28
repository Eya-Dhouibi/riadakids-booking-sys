/**
 * rk-coach-page-home.js — Page لوحة التحكم
 * Namespace: RKCoach.Pages.Home
 */
(function () {
  'use strict';
  var RK    = window.RKCoach;
  var Utils = RK.Utils;
  var Icons = RK.Icons;
  var S     = RK.state;

  function render() {
    var d      = S.home;
    var s      = d.stats || {};
    var c      = d.coach || {};
    var today  = d.today  || [];
    var alerts = d.alerts || [];

    var nextTxt = 'لا توجد جلسات مجدولة الآن';
    if (d.next && d.next.child_name) {
      nextTxt = 'جلستك القادمة: ' + Utils.e(d.next.child_name);
      if (d.next.start) nextTxt += ' — ' + Utils.e(d.next.start);
    }

    var chips = '';
    if (d.pending > 0) chips += '<button class="rk-ch-hero__chip rk-ch-hero__chip--alert" onclick="window.nav(\'evals\')" type="button">' + Icons.iAlert + ' ' + Number(d.pending) + ' تقييم معلق</button>';
    if (d.unread  > 0) chips += '<button class="rk-ch-hero__chip" onclick="window.nav(\'messages\')" type="button">' + Icons.iChat  + ' ' + Number(d.unread)  + ' رسالة</button>';
    if (!chips) chips = '<span class="rk-ch-hero__chip rk-ch-hero__chip--ok">' + Icons.iCheck + ' كل شيء بخير</span>';

    var kpis = Utils.kpi('#1B4F8C', Icons.iUsers,  s.total_students  || 0, 'إجمالي الطلاب')
             + Utils.kpi('#0891b2', Icons.iCal,    s.sessions_today  || 0, 'جلسات اليوم')
             + Utils.kpi('#7c3aed', Icons.iChart,  s.sessions_week   || 0, 'جلسات الأسبوع')
             + Utils.kpi(d.pending > 0 ? '#f59e0b' : '#22c55e', Icons.iClip, s.pending_evals || d.pending || 0, 'تقييمات معلقة')
             + Utils.kpi('#059669', Icons.iChat,   s.unread_messages || d.unread || 0, 'رسائل غير مقروءة')
             + Utils.kpi('#8b5cf6', Icons.iCheck,  s.sessions_month  || 0, 'جلسات الشهر');

    var tlHtml = '';
    if (today.length) {
      today.forEach(function (sess, i) {
        var att = sess.attendance || '';
        var isWarn = att === 'absent';
        tlHtml += '<div class="rk-tl-item' + (i === 0 ? ' rk-tl--active' : '') + (isWarn ? ' rk-tl--warn' : '') + '">'
          + '<div class="rk-tl-body">'
          + '<div class="rk-tl-child">' + Utils.e(sess.child_name || sess.name || '') + '</div>'
          + '<div class="rk-tl-label">' + Utils.e(sess.program || '') + '</div>'
          + '<span class="rk-tl-chip ' + (isWarn ? 'rk-tl-chip--eval' : 'rk-tl-chip--session') + '">'
          + (isWarn ? 'غياب' : 'جلسة') + '</span>'
          + '</div>'
          + '<div class="rk-tl-time">' + Utils.e(sess.start || '') + '</div>'
          + '<div class="rk-tl-dot"></div>'
          + '</div>';
      });
    } else {
      tlHtml = '<div class="rk-tl-empty">لا توجد جلسات اليوم</div>';
    }

    var alertsSideHtml = '';
    if (alerts.length) {
      alerts.slice(0, 3).forEach(function (a) {
        var isDanger = a.level === 'red';
        alertsSideHtml += '<div class="rk-home-alert' + (isDanger ? ' rk-home-alert--danger' : '') + '">'
          + '<div style="flex:1;min-width:0;">'
          + '<div class="rk-home-alert__name">' + Utils.e(a.title   || '') + '</div>'
          + '<div class="rk-home-alert__msg">'  + Utils.e(a.message || '') + '</div>'
          + '</div>'
          + '<button class="rk-home-alert__btn" type="button" onclick="window.nav(\'students\')">'
          + (isDanger ? 'تواصل' : 'تابع') + '</button>'
          + '</div>';
      });
    } else {
      alertsSideHtml = '<p style="text-align:center;color:#9ca3af;font-size:13px;padding:16px 0;">كل شيء بخير ✓</p>';
    }

    Utils.setMain(
      '<div class="rk-ch-hero"><div class="rk-ch-hero__body">'
      + '<h2 class="rk-ch-hero__title">' + Utils.e(c.greeting || 'مرحباً') + '، ' + Utils.e(c.name || '') + '</h2>'
      + '<p class="rk-ch-hero__sub">' + nextTxt + '</p></div>'
      + '<div class="rk-ch-hero__meta">' + chips + '</div></div>'
      + '<div class="rk-ch-kpis rk-ch-kpis--6" style="margin-top:24px;">' + kpis + '</div>'
      + '<div class="rk-home-cols">'
      + '<div class="rk-home-card">'
      + '<div class="rk-home-card__head"><span>جدول اليوم</span>'
      + '<span style="font-size:12px;color:#5A7499;font-weight:400;">' + today.length + ' جلسة</span></div>'
      + '<div class="rk-home-card__body"><div class="rk-tl">' + tlHtml + '</div></div>'
      + '</div>'
      + '<div style="display:flex;flex-direction:column;gap:16px;">'
      + '<div class="rk-home-card">'
      + '<div class="rk-home-card__head"><span>تنبيهات عاجلة</span>'
      + (alerts.length ? '<button type="button" onclick="window.nav(\'students\')" style="font-size:12px;color:#1B4F8C;background:none;border:none;cursor:pointer;font-weight:600;font-family:Tajawal,sans-serif;">عرض الكل ←</button>' : '')
      + '</div>'
      + '<div class="rk-home-card__body">' + alertsSideHtml + '</div>'
      + '</div>'
      + '</div>'
      + '</div>'
    );
  }

  RK.Pages = RK.Pages || {};
  RK.Pages.Home = { render: render };
})();