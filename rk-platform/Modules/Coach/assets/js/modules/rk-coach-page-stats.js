/**
 * rk-coach-page-stats.js — Rapports et statistiques
 * Namespace: RKCoach.Pages.Stats
 */
(function () {
  'use strict';
  var RK    = window.RKCoach;
  var Core  = RK.Core;
  var Utils = RK.Utils;
  var Icons = RK.Icons;

  /* ── Icônes SVG locales ───────────────────────────────────────── */
  var SVG = {
    up:    '<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="vertical-align:-1px;"><polyline points="18 15 12 9 6 15"/></svg>',
    down:  '<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="vertical-align:-1px;"><polyline points="6 9 12 15 18 9"/></svg>',
    flat:  '<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true" style="vertical-align:-1px;"><line x1="5" y1="12" x2="19" y2="12"/></svg>',
    warn:  '<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="vertical-align:-1px;"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>',
    chart: '<svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="12" y1="20" x2="12" y2="10"/><line x1="18" y1="20" x2="18" y2="4"/><line x1="6" y1="20" x2="6" y2="16"/></svg>'
  };

  function render() {
    Utils.setMain('<div class="rk-section-loading">جاري تحميل الإحصائيات…</div>');

    Core.apiGet('/coach/stats').then(function (data) {
      var monthly  = Array.isArray(data.monthly)  ? data.monthly  : [];
      var students = Array.isArray(data.students) ? data.students : [];
      var alerts   = Array.isArray(data.alerts)   ? data.alerts   : [];
      var curr     = data.current  || {};
      var prev     = data.previous || {};

      var MONTHS = ['','يناير','فبراير','مارس','أبريل','مايو','يونيو','يوليو','أغسطس','سبتمبر','أكتوبر','نوفمبر','ديسمبر'];

      function kpiStat(color, icon, val, label, prevVal, suffix) {
        suffix = suffix || '';
        var diff = Number(val) - Number(prevVal);
        var trendHtml = diff > 0
          ? '<div style="display:inline-flex;align-items:center;gap:3px;font-size:11px;font-weight:700;color:#059669;margin-top:6px;background:#dcfce7;padding:2px 8px;border-radius:10px;">' + SVG.up + ' +' + diff + suffix + '</div>'
          : diff < 0
          ? '<div style="display:inline-flex;align-items:center;gap:3px;font-size:11px;font-weight:700;color:#DC2626;margin-top:6px;background:#fee2e2;padding:2px 8px;border-radius:10px;">' + SVG.down + ' ' + diff + suffix + '</div>'
          : '<div style="display:inline-flex;align-items:center;gap:3px;font-size:11px;font-weight:600;color:#94a3b8;margin-top:6px;background:#f1f5f9;padding:2px 8px;border-radius:10px;">' + SVG.flat + ' مستقر</div>';
        return '<div class="rk-ch-kpi" style="--kpi-color:' + color + '">'
          + '<div class="rk-ch-kpi__icon" style="background:' + color + '20;color:' + color + ';">' + icon + '</div>'
          + '<div class="rk-ch-kpi__val">' + Number(val) + suffix + '</div>'
          + '<div class="rk-ch-kpi__label">' + Utils.e(label) + '</div>'
          + trendHtml
          + '</div>';
      }

      /* ── Graphique barres — sessions ── */
      var maxSess = 1;
      monthly.forEach(function (m) { if (m.sessions > maxSess) maxSess = m.sessions; });
      var sessBars = '';
      monthly.forEach(function (m, i) {
        var pct    = Math.round((m.sessions / maxSess) * 100);
        var isCurr = i === monthly.length - 1;
        sessBars += '<div class="rk-ch-bar-chart__col">'
          + '<div class="rk-ch-bar-chart__bar-wrap">'
          + '<div class="rk-ch-bar-chart__bar' + (isCurr ? ' rk-ch-bar-chart__bar--current' : '') + '" style="height:' + Math.max(pct, 3) + '%;border-radius:6px 6px 0 0;transition:height .4s ease;" title="' + m.sessions + ' جلسة"></div>'
          + '</div>'
          + '<span class="rk-ch-bar-chart__val">' + m.sessions + '</span>'
          + '<span class="rk-ch-bar-chart__label"' + (isCurr ? ' style="font-weight:700;color:#1B4F8C;"' : '') + '>' + Utils.e((MONTHS[m.month] || '').substr(0, 5)) + '</span>'
          + '</div>';
      });

      /* ── Graphique barres — présence ── */
      var attBars = '';
      monthly.forEach(function (m, i) {
        var pct      = m.attendance || 0;
        var isCurr   = i === monthly.length - 1;
        var barColor = pct >= 80 ? '#059669' : pct >= 60 ? '#d97706' : '#dc2626';
        attBars += '<div class="rk-ch-bar-chart__col">'
          + '<div class="rk-ch-bar-chart__bar-wrap">'
          + '<div class="rk-ch-bar-chart__bar" style="height:' + Math.max(pct, 3) + '%;background:' + (isCurr ? barColor : barColor + '55') + ';border-radius:6px 6px 0 0;transition:height .4s ease;" title="' + pct + '%"></div>'
          + '</div>'
          + '<span class="rk-ch-bar-chart__val" style="color:' + barColor + ';font-weight:700;">' + pct + '%</span>'
          + '<span class="rk-ch-bar-chart__label"' + (isCurr ? ' style="font-weight:700;color:#1B4F8C;"' : '') + '>' + Utils.e((MONTHS[m.month] || '').substr(0, 5)) + '</span>'
          + '</div>';
      });

      /* ── Tableau présence par élève ── */
      var studRows = '';
      if (students.length) {
        students.forEach(function (st) {
          var att   = Number(st.attendance || 0);
          var color = att >= 80 ? '#166534' : att >= 60 ? '#92400e' : '#991b1b';
          var bg    = att >= 80 ? '#dcfce7' : att >= 60 ? '#fef3c7' : '#fee2e2';
          var lbl   = att >= 80 ? 'ممتاز'  : att >= 60 ? 'متوسط'   : 'ضعيف';
          studRows += '<tr style="border-bottom:1px solid #f8fafc;">'
            + '<td style="font-weight:600;font-size:13px;padding:10px 16px;">' + Utils.e(st.name) + '</td>'
            + '<td style="padding:10px 16px;">'
            + '<div style="display:flex;align-items:center;gap:8px;">'
            + '<div style="flex:1;height:7px;background:#f1f5f9;border-radius:4px;overflow:hidden;min-width:80px;">'
            + '<div style="height:100%;width:' + att + '%;background:linear-gradient(90deg,' + color + ',' + color + 'cc);border-radius:4px;transition:width .4s ease;"></div>'
            + '</div>'
            + '<span style="font-size:12px;font-weight:700;color:' + color + ';white-space:nowrap;font-variant-numeric:tabular-nums;">' + att + '%</span>'
            + '</div></td>'
            + '<td style="padding:10px 16px;">'
            + '<span style="display:inline-block;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700;background:' + bg + ';color:' + color + ';">' + lbl + '</span>'
            + (Number(st.absences) >= 3 ? ' <span style="display:inline-flex;align-items:center;gap:3px;font-size:11px;color:#dc2626;font-weight:700;">' + SVG.warn + ' ' + st.absences + ' غياب</span>' : '')
            + '</td>'
            + '</tr>';
        });
      } else {
        studRows = '<tr><td colspan="3" style="text-align:center;color:#9ca3af;padding:24px;">لا توجد بيانات طلاب</td></tr>';
      }

      /* ── ملخص شهري ── */
      var summRows = '';
      monthly.slice().reverse().forEach(function (m, i) {
        var isCurr = i === 0;
        summRows += '<tr style="' + (isCurr ? 'background:#f8fafc;' : '') + 'border-bottom:1px solid #f8fafc;">'
          + '<td style="font-weight:600;padding:8px 14px;">' + Utils.e(MONTHS[m.month] || '') + ' ' + m.year
          + (isCurr ? ' <span style="font-size:10px;background:#1B4F8C;color:#fff;padding:2px 7px;border-radius:8px;font-weight:700;">الحالي</span>' : '')
          + '</td>'
          + '<td style="text-align:center;padding:8px 14px;font-variant-numeric:tabular-nums;">' + m.sessions + '</td>'
          + '<td style="text-align:center;padding:8px 14px;">'
          + '<span style="font-weight:700;font-variant-numeric:tabular-nums;color:' + (m.attendance >= 80 ? '#059669' : m.attendance >= 60 ? '#d97706' : '#dc2626') + ';">' + m.attendance + '%</span>'
          + '</td>'
          + '<td style="text-align:center;padding:8px 14px;font-variant-numeric:tabular-nums;">' + m.evals + '</td>'
          + '<td style="text-align:center;padding:8px 14px;font-variant-numeric:tabular-nums;">' + m.messages + '</td>'
          + '</tr>';
      });

      /* ── Alertes qualité ── */
      var alertsHtml = '';
      if (alerts.length) {
        var alertRows = '';
        alerts.forEach(function (a) {
          var clr = a.level === 'red' ? '#DC2626' : '#D97706';
          alertRows += '<div style="display:flex;align-items:center;gap:12px;padding:12px 18px;border-right:4px solid ' + clr + ';border-bottom:1px solid #f3f4f6;">'
            + '<div style="flex:1;min-width:0;">'
            + '<div style="font-weight:700;font-size:13px;">' + Utils.e(a.title || '') + '</div>'
            + '<div style="font-size:12px;color:#6b7280;margin-top:2px;">' + Utils.e(a.message || '') + '</div>'
            + '</div>'
            + '<button type="button" onclick="window.nav(\'students\')" style="padding:5px 12px;border-radius:8px;background:none;border:1.5px solid ' + clr + ';color:' + clr + ';font-size:12px;font-weight:700;cursor:pointer;font-family:Tajawal,sans-serif;white-space:nowrap;">عرض الطالب</button>'
            + '</div>';
        });
        alertsHtml = '<div class="rk-ch-section" style="margin-top:20px;">'
          + '<div class="rk-ch-section-head"><h3 class="rk-ch-section-title" style="color:#b91c1c;">' + Icons.iAlert + ' تنبيهات الجودة</h3>'
          + '<span style="background:#fee2e2;color:#991b1b;font-size:11px;font-weight:700;padding:3px 9px;border-radius:12px;">' + alerts.length + '</span>'
          + '</div>'
          + '<div>' + alertRows + '</div>'
          + '</div>';
      }

      Utils.setMain(
        '<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">'
        + '<p class="rk-page-title" style="margin:0;">التقارير والإحصائيات</p>'
        + '<button type="button" id="rk-stats-refresh" style="display:inline-flex;align-items:center;gap:6px;padding:6px 14px;border-radius:20px;background:#f3f4f6;border:none;color:#4b5563;font-size:12.5px;font-weight:600;cursor:pointer;font-family:Tajawal,sans-serif;">'
        + '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>'
        + ' تحديث</button>'
        + '</div>'
        + '<div class="rk-ch-kpis rk-ch-kpis--4" style="margin-top:14px;">'
        + kpiStat('#1B4F8C', Icons.iCal,   curr.sessions   || 0, 'لقاءات — هذا الشهر',   prev.sessions   || 0)
        + kpiStat('#059669', Icons.iCheck, curr.attendance || 0, 'نسبة الحضور',           prev.attendance || 0, '%')
        + kpiStat('#E8500A', Icons.iClip,  curr.evals      || 0, 'تقييمات كُتبت',           prev.evals      || 0)
        + kpiStat('#7c3aed', Icons.iChat,  curr.messages   || 0, 'رسائل الوالدين',          prev.messages   || 0)
        + '</div>'
        + '<div class="rk-ch-stats-cols">'
        + '<div class="rk-ch-section">'
        + '<div class="rk-ch-section-head"><h3 class="rk-ch-section-title">لقاءات — آخر 6 أشهر</h3></div>'
        + '<div class="rk-ch-bar-chart">' + sessBars + '</div>'
        + '</div>'
        + '<div class="rk-ch-section">'
        + '<div class="rk-ch-section-head"><h3 class="rk-ch-section-title">نسبة الحضور — آخر 6 أشهر</h3></div>'
        + '<div class="rk-ch-bar-chart">' + attBars + '</div>'
        + '</div>'
        + '</div>'
        + '<div class="rk-ch-section" style="margin-top:20px;">'
        + '<div class="rk-ch-section-head"><h3 class="rk-ch-section-title">الحضور حسب الطالب</h3>'
        + '<span style="font-size:12px;color:#6b7280;">' + students.length + ' طالب</span>'
        + '</div>'
        + '<div class="rk-ch-table-wrap" style="overflow-x:auto;">'
        + '<table class="rk-ch-table" style="min-width:420px;">'
        + '<thead><tr><th style="text-align:right;padding:10px 16px;">الطالب</th><th style="padding:10px 16px;">الحضور</th><th style="padding:10px 16px;">الحالة</th></tr></thead>'
        + '<tbody>' + studRows + '</tbody>'
        + '</table>'
        + '</div>'
        + '</div>'
        + '<div class="rk-ch-section" style="margin-top:20px;">'
        + '<div class="rk-ch-section-head"><h3 class="rk-ch-section-title">ملخص شهري</h3></div>'
        + '<div class="rk-ch-table-wrap" style="overflow-x:auto;">'
        + '<table class="rk-ch-table" style="min-width:480px;">'
        + '<thead><tr>'
        + '<th style="text-align:right;padding:10px 14px;">الشهر</th>'
        + '<th style="text-align:center;padding:10px 14px;">لقاءات</th>'
        + '<th style="text-align:center;padding:10px 14px;">الحضور</th>'
        + '<th style="text-align:center;padding:10px 14px;">التقييمات</th>'
        + '<th style="text-align:center;padding:10px 14px;">الرسائل</th>'
        + '</tr></thead>'
        + '<tbody>' + summRows + '</tbody>'
        + '</table>'
        + '</div>'
        + '</div>'
        + alertsHtml
      );

      /* Bouton تحديث — force un rechargement sans cache JS */
      var refreshBtn = document.getElementById('rk-stats-refresh');
      if (refreshBtn) {
        refreshBtn.addEventListener('click', function () {
          Core.cacheDelete('_coach_stats');
          render();
        });
      }
    }).catch(function () {
      Utils.setMain('<div class="rk-empty"><div class="rk-empty-icon">' + SVG.chart + '</div><p>تعذّر تحميل الإحصائيات.</p></div>');
    });
  }

  RK.Pages = RK.Pages || {};
  RK.Pages.Stats = { render: render };
})();