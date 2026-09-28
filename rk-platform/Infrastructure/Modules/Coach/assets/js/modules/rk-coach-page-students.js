/**
 * rk-coach-page-students.js — Grille des élèves + fiche détaillée
 * (onglets معلومات / الحجوزات / المغامرات ; les onglets tasks/evals/quiz/badges
 * délèguent aux modules dédiés)
 * Namespace: RKCoach.Pages.Students
 */
(function () {
  'use strict';
  var RK    = window.RKCoach;
  var Core  = RK.Core;
  var Utils = RK.Utils;
  var Icons = RK.Icons;
  var S     = RK.state;

  /* ── Icônes SVG locales (stroke currentColor) ─────────────────── */
  var SVG = {
    child: '<svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>',
    target: '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="vertical-align:-1px;"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/></svg>',
    medal: '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="vertical-align:-2px;"><circle cx="12" cy="8" r="6"/><path d="M15.477 12.89L17 22l-5-3-5 3 1.523-9.11"/></svg>',
    warn: '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="vertical-align:-1px;"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>',
    book: '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>'
  };

  /**
   * v9.24 — Combine prénom + nom de famille (st.family_name, alimenté
   * par c.child_family_name côté SQL — voir RKP_CoachStudentRepository
   * ::find_children_for_coach()). Utilisé partout où le nom de l'enfant
   * est affiché (carte liste, fiche détail, panneau infos) — avant
   * cette version seul le prénom (st.name) était utilisé, family_name
   * n'était même pas exposé par l'API.
   */
  function fullName(st) {
    var first  = (st && st.name) || '';
    var family = (st && st.family_name) || '';
    return family ? (first + ' ' + family) : first;
  }

  function dedupe(arr) {
    var seen = {};
    return (Array.isArray(arr) ? arr : []).filter(function (st) {
      var id = Number(st.id || st.child_id || 0);
      if (!id || seen[id]) return false;
      seen[id] = true;
      return true;
    });
  }

  function render() {
    if (S.students) { draw(); return; }
    Utils.setMain('<div class="rk-section-loading">جاري تحميل قائمة الأطفال…</div>');
    Core.apiGet('/coach/students').then(function (data) {
      S.students = dedupe(data);
      draw();
    }).catch(function () {
      Utils.setMain('<div class="rk-section-loading">تعذّر تحميل البيانات.</div>');
    });
  }

  function draw(filter) {
    if (!S.students.length) {
      Utils.setMain('<div class="rk-empty"><div class="rk-empty-icon">' + SVG.child + '</div><p>لا يوجد أطفال مسجّلون بعد.</p></div>');
      return;
    }

    var q    = (filter || '').trim().toLowerCase();
    var list = q
      ? S.students.filter(function (st) { return fullName(st).toLowerCase().indexOf(q) !== -1; })
      : S.students;

    var cards = list.map(function (st) {
      var name    = fullName(st);
      var avSrc   = Utils.av(name, st.avatar, 54);
      var att     = Number(st.attendance || 0);
      var attCls  = att >= 80 ? 'att-good' : att >= 50 ? 'att-warn' : 'att-low';
      var courses = Array.isArray(st.enrolled_courses) ? st.enrolled_courses : [];
      var bk      = st.next_booking;
      var bkTxt   = bk && bk.date
        ? Utils.e(fmtDate(bk.date)) + (bk.program ? ' — ' + Utils.e(bk.program) : '')
        : 'لا توجد جلسة قادمة';
      var bkEmpty = !(bk && bk.date);

      var coursesHtml = courses.length
        ? '<div class="rk-sc__courses">'
          + courses.map(function (c) {
              var pct = c.progress || 0;
              return '<div class="rk-sc__course">'
                + '<div class="rk-sc__course-hd">'
                + '<span class="rk-sc__course-name">' + SVG.target + ' ' + Utils.e(c.course_title) + '</span>'
                + '<span class="rk-sc__course-pct">' + pct + '%</span>'
                + '</div>'
                + '<div class="rk-sc__course-bar"><div class="rk-sc__course-fill" style="width:' + pct + '%"></div></div>'
                + '</div>';
            }).join('')
          + '</div>'
        : '';

      return '<div class="rk-sc" data-id="' + Number(st.id) + '">'
        + '<div class="rk-sc__head">'
        + '<div class="rk-sc__av-wrap">'
        + '<img class="rk-sc__av" src="' + avSrc + '" alt="' + Utils.e(name) + '" loading="lazy" onerror="this.src=\'' + Utils.e(Utils.svgAv(name, 54)) + '\'">'
        + (st.absences >= 3 ? '<span class="rk-sc__alert-dot"></span>' : '')
        + '</div>'
        + '<div class="rk-sc__identity">'
        + '<p class="rk-sc__name">' + Utils.e(name) + '</p>'
        + '<p class="rk-sc__age">' + Utils.e(st.age || '—') + ' سنة</p>'
        + '</div>'
        + '</div>'
        + '<div class="rk-sc__att-row">'
        + '<div class="rk-sc__att-bar"><div class="rk-sc__att-fill rk-sc__att-fill--' + attCls + '" style="width:' + att + '%"></div></div>'
        + '<span class="rk-sc__att-lbl ' + attCls + '">' + att + '%</span>'
        + '</div>'
        + '<div class="rk-sc__stats">'
        + scChip(String(courses.length), 'مغامرة', '#1B4F8C')
        + scChip(String(st.absences || 0), 'غياب', st.absences >= 3 ? '#dc2626' : '#6b7280')
        + '</div>'
        + coursesHtml
        + '<div class="rk-sc__next' + (bkEmpty ? ' rk-sc__next--empty' : '') + '">'
        + Icons.iCal + ' ' + bkTxt
        + '</div>'
        + '<button class="rk-sc__btn" type="button" data-id="' + Number(st.id) + '">عرض الملف ←</button>'
        + '</div>';
    }).join('');

    var noResult = !list.length
      ? '<div class="rk-empty" style="padding:32px"><p>لا توجد نتائج.</p></div>'
      : '';

    Utils.setMain(
      '<div class="rk-students-header">'
      + '<p class="rk-page-title">الأطفال (' + S.students.length + ')</p>'
      + '<div class="rk-search-wrap">'
      + '<input type="search" id="rk-student-search" class="rk-search-input" dir="rtl" placeholder="ابحث عن طفل…" value="' + Utils.e(filter || '') + '">'
      + '</div>'
      + '</div>'
      + '<div class="rk-student-grid" id="rk-student-grid">' + cards + noResult + '</div>'
    );

    var searchEl = document.getElementById('rk-student-search');
    if (searchEl) {
      searchEl.focus();
      searchEl.addEventListener('input', function () { draw(searchEl.value); });
    }

    document.querySelectorAll('.rk-sc').forEach(function (el) {
      el.addEventListener('click', function () { showChildDetail(parseInt(el.dataset.id, 10)); });
    });
    document.querySelectorAll('.rk-sc__btn').forEach(function (btn) {
      btn.addEventListener('click', function (ev) {
        ev.stopPropagation();
        showChildDetail(parseInt(btn.dataset.id, 10));
      });
    });
  }

  function scChip(val, lbl, color) {
    return '<div class="rk-sc__chip" style="border-color:' + color + '28;color:' + color + '">'
      + '<strong>' + Utils.e(val) + '</strong>&nbsp;' + Utils.e(lbl) + '</div>';
  }

  function fmtDate(d) {
    if (!d) return '';
    var p = String(d).split('-');
    return p.length === 3 ? p[2] + '/' + p[1] + '/' + p[0] : d;
  }

  /* ══════════════════════════════════════════════════════════
     FICHE ENFANT
     ══════════════════════════════════════════════════════════ */
  function showChildDetail(childId) {
    S.childId = childId;
    S.page    = 'student-detail';
    Utils.setMain('<div class="rk-section-loading">جاري تحميل بيانات الطفل…</div>');
    Core.apiGet('/coach/student?child_id=' + childId, false).then(function (st) {

      if (!st || st.code) {
        console.warn('[RK_FICHE] API error code:', st && st.code);
        Utils.setMain('<div class="rk-empty" style="padding:48px;text-align:center">'
          + '<p style="color:#6b7280;font-size:14px">تعذّر تحميل ملف الطفل — رمز: <code>' + Utils.e((st && st.code) || 'null') + '</code></p>'
          + '<button class="rk-btn-detail" style="margin-top:12px" onclick="nav(\'students\')" type="button">← رجوع للقائمة</button>'
          + '</div>');
        return;
      }

      try {
        var name   = fullName(st);
        var avUrl  = Utils.av(name, st.avatar, 80);
        var att    = Number(st.attendance || 0);
        var attCls = att >= 80 ? 'att-good' : att >= 50 ? 'att-warn' : 'att-low';
        var nextBk = st.next_booking;
        var nextBkHtml = nextBk && nextBk.date
          ? '<div class="rk-fiche-next-bk">' + Icons.iCal + ' '
              + Utils.e(fmtDate(nextBk.date))
              + (nextBk.time ? ' • ' + Utils.e(nextBk.time) : '')
              + (nextBk.program ? ' — ' + Utils.e(nextBk.program) : '')
              + '</div>'
          : '';

        var html = '<button class="rk-back-btn" id="rk-back-students" type="button">← رجوع</button>'
          + '<div class="rk-fiche">'
          + '<div class="rk-fiche__hero">'
          + '<div class="rk-fiche__av"><img src="' + avUrl + '" width="80" height="80" loading="lazy" onerror="this.src=\'' + Utils.e(Utils.svgAv(name, 80)) + '\'"></div>'
          + '<div class="rk-fiche__meta">'
          + '<h2 class="rk-fiche__name">' + Utils.e(name) + '</h2>'
          + '<div class="rk-fiche__tags">'
          + '<span class="rk-fiche-tag rk-fiche-tag--age">' + Utils.e(st.age || '—') + ' سنة</span>'
          + '<span class="rk-fiche-tag rk-fiche-tag--att ' + attCls + '">' + att + '% حضور</span>'
          + (Number(st.upcoming_count) > 0 ? '<span class="rk-fiche-tag rk-fiche-tag--blue">' + Number(st.upcoming_count) + ' لقاء قادم</span>' : '')
          + (st.absences >= 3 ? '<span class="rk-fiche-tag rk-fiche-tag--warn">' + SVG.warn + ' ' + Number(st.absences) + ' غيابات</span>' : '')
          + '</div>'
          + nextBkHtml
          + '</div>'
          + '</div>'
          + '<div class="rk-fiche__tabs" role="tablist">'
          + '<button class="rk-fiche__tab active" data-tab="info"     role="tab" type="button">معلومات</button>'
          + '<button class="rk-fiche__tab"         data-tab="bookings" role="tab" type="button">الحجوزات</button>'
          + '<button class="rk-fiche__tab"         data-tab="courses"  role="tab" type="button">المغامرات</button>'
          + '<button class="rk-fiche__tab"         data-tab="evals"    role="tab" type="button">التقييمات</button>'
          + '<button class="rk-fiche__tab"         data-tab="quiz"     role="tab" type="button">الاختبارات</button>'
          + '<button class="rk-fiche__tab"         data-tab="badges"   role="tab" type="button">' + SVG.medal + ' الشارات</button>'
          + '</div>'
          + '<div id="rk-panel-info"     class="rk-fiche__panel rk-fiche__panel--active">' + buildInfoPanel(st) + '</div>'
          + '<div id="rk-panel-bookings" class="rk-fiche__panel">' + drawBookingsList(st.sessions || []) + '</div>'
          + '<div id="rk-panel-courses"  class="rk-fiche__panel">' + buildCoursesPanel(st) + '</div>'
          + '<div id="rk-panel-evals"    class="rk-fiche__panel"><div class="rk-section-loading">جاري التحميل…</div></div>'
          + '<div id="rk-panel-quiz"     class="rk-fiche__panel">' + RK.Pages.Quiz.buildPanelSkeleton() + '</div>'
          + '<div id="rk-panel-badges"   class="rk-fiche__panel"><div class="rk-section-loading">جاري التحميل…</div></div>'
          + '</div>';

        Utils.setMain(html);

        var backBtn = document.getElementById('rk-back-students');
        if (backBtn) backBtn.addEventListener('click', function () { window.nav('students'); });

        document.querySelectorAll('.rk-fiche__tab').forEach(function (tab) {
          tab.addEventListener('click', function () {
            document.querySelectorAll('.rk-fiche__tab').forEach(function (t) { t.classList.remove('active'); });
            tab.classList.add('active');
            document.querySelectorAll('.rk-fiche__panel').forEach(function (p) { p.classList.remove('rk-fiche__panel--active'); });
            var panel = document.getElementById('rk-panel-' + tab.dataset.tab);
            if (panel) panel.classList.add('rk-fiche__panel--active');
          });
        });

        RK.Pages.Evals.loadChildEvals(childId);
        RK.Pages.Quiz.loadChildQuizzes(childId, st.enrolled_courses || []);
        RK.Pages.Badges.loadChildBadges(childId);

        if (S.pendingTab) {
          var ptEl = document.querySelector('.rk-fiche__tab[data-tab="' + S.pendingTab + '"]');
          if (ptEl) ptEl.click();
          S.pendingTab = null;
        }

      } catch (err) {
        console.error('[RK_FICHE] JS error building template:', err);
        Utils.setMain('<div class="rk-empty" style="padding:48px;text-align:center">'
          + '<p style="color:#991b1b;font-size:13px">خطأ JS: ' + Utils.e(String(err)) + '</p>'
          + '<button class="rk-btn-detail" style="margin-top:12px" onclick="nav(\'students\')" type="button">← رجوع</button>'
          + '</div>');
      }

    }).catch(function (err) {
      console.error('[RK_FICHE] fetch error:', err);
      Utils.setMain('<div class="rk-empty" style="padding:48px;text-align:center">'
        + '<p style="color:#6b7280;font-size:14px">تعذّر الاتصال بالخادم.</p>'
        + '<button class="rk-btn-detail" style="margin-top:12px" onclick="nav(\'students\')" type="button">← رجوع</button>'
        + '</div>');
    });
  }

  function buildInfoPanel(st) {
    var childCard = '<div class="rk-info-card">'
      + '<h4 class="rk-info-card__title">معلومات الطفل</h4>'
      + infoRow('الاسم الكامل',        fullName(st) || '—')
      + infoRow('العمر',               (st.age || '—') + ' سنة')
      + infoRow('نسبة الحضور',         Number(st.attendance || 0) + '%')
      + infoRow('الغيابات المتتالية',   String(st.absences || 0))
      + infoRow('الحجوزات القادمة',     String(st.upcoming_count || 0))
      + infoRow('المغامرات المسجّلة',    String((st.enrolled_courses || []).length))
      + '</div>';
    return '<div class="rk-info-grid">' + childCard + '</div>';
  }

  function infoRow(label, value) {
    return '<div class="rk-info-row">'
      + '<span class="rk-info-row__lbl">' + Utils.e(label) + '</span>'
      + '<span class="rk-info-row__val">' + Utils.e(value) + '</span>'
      + '</div>';
  }

  function buildCoursesPanel(st) {
    var courses = Array.isArray(st.enrolled_courses) ? st.enrolled_courses : [];
    if (!courses.length) {
      return '<div class="rk-empty" style="padding:32px"><p>لا توجد مغامرات مسجّلة لهذا الطفل.</p></div>';
    }
    return '<div class="rk-courses-grid">' + courses.map(function (c) {
      var pct = Math.min(100, Math.max(0, Number(c.progress || 0)));
      return '<div class="rk-course-card">'
        + '<div class="rk-course-card__icon" style="color:#1B4F8C;display:inline-flex;">' + SVG.book + '</div>'
        + '<div class="rk-course-card__body">'
        + '<p class="rk-course-card__title">' + Utils.e(c.course_title || '') + '</p>'
        + '<div class="rk-progress-bar"><div class="rk-progress-bar__fill" style="width:' + pct + '%"></div></div>'
        + '<p class="rk-course-card__pct">' + pct + '% مكتمل</p>'
        + '</div></div>';
    }).join('') + '</div>';
  }

  function drawBookingsList(sessions) {
    if (!sessions.length) {
      return '<div class="rk-empty" style="padding:32px"><p>لا توجد حجوزات مسجّلة.</p></div>';
    }
    var upcoming = sessions.filter(function (s) { return s.is_upcoming == 1; });
    var past     = sessions.filter(function (s) { return s.is_upcoming != 1; });
    var html     = '';

    if (upcoming.length) {
      html += '<div class="rk-bk-section-label rk-bk-section-label--upcoming">'
            + Icons.iCal + ' الحجوزات القادمة (' + upcoming.length + ')</div>'
            + upcoming.map(bookingRow).join('');
    }
    if (past.length) {
      html += '<div class="rk-bk-section-label">' + Icons.iChart + ' الحجوزات السابقة (' + past.length + ')</div>'
            + past.map(bookingRow).join('');
    }
    return html;
  }

  function bookingRow(b) {
    var statusMap = {
      confirmed:   { lbl: 'مؤكد',     cls: 'bk-confirmed'   },
      rescheduled: { lbl: 'معاد جدولة', cls: 'bk-rescheduled' },
      pending:     { lbl: 'انتظار',    cls: 'bk-pending'     },
      completed:   { lbl: 'مكتمل',    cls: 'bk-completed'   },
      cancelled:   { lbl: 'ملغى',     cls: 'bk-cancelled'   }
    };
    var st      = statusMap[b.status] || { lbl: Utils.e(b.status || '—'), cls: '' };
    var att     = b.attendance || 'none';
    var attLbl  = att === 'present' ? 'حاضر' : att === 'absent' ? 'غائب' : '';
    var attCls  = att === 'present' ? 'present' : att === 'absent' ? 'absent' : '';
    var timeStr = Utils.e(fmtDate(b.date || '')) + (b.start ? ' • ' + Utils.e(b.start) : '');

    return '<div class="rk-booking-row' + (b.is_upcoming == 1 ? ' rk-booking-row--upcoming' : '') + '">'
      + '<div class="rk-booking-row__bar"></div>'
      + '<div class="rk-booking-row__info">'
      + '<p class="rk-booking-row__prog">' + Utils.e(b.program || '—') + '</p>'
      + '<p class="rk-booking-row__time">' + timeStr + '</p>'
      + '</div>'
      + '<div class="rk-booking-row__badges">'
      + '<span class="rk-badge-bk ' + st.cls + '">' + st.lbl + '</span>'
      + (attLbl ? '<span class="rk-badge-att ' + attCls + '">' + attLbl + '</span>' : '')
      + '</div>'
      + '</div>';
  }

  RK.Pages = RK.Pages || {};
  RK.Pages.Students = {
    render: render,
    draw: draw,
    dedupe: dedupe,
    showChildDetail: showChildDetail,
    fmtDate: fmtDate
  };
})();