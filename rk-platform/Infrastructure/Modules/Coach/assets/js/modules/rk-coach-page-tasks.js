/**
 * rk-coach-page-tasks.js — Missions RK personnelles (fiche enfant) + modal création
 * Note : le contenu de l'ancien onglet "المهام" (assignments Tutor + missions)
 * reste disponible ici pour compat ; l'onglet actif est "سؤال وجواب" (rk-coach-page-qna.js).
 * Namespace: RKCoach.Pages.Tasks
 */
(function () {
  'use strict';
  var RK    = window.RKCoach;
  var Core  = RK.Core;
  var Utils = RK.Utils;
  var S     = RK.state;

  function fmtDate(d) { return RK.Pages.Students.fmtDate(d); }

  function missionCard(m) {
    var pct       = m.target > 0 ? Math.round(m.progress / m.target * 100) : 0;
    var statusCls = m.completed ? 'task-done' : (m.due_date && new Date(m.due_date) < new Date() ? 'task-late' : 'task-active');
    var statusLbl = m.completed ? 'مكتملة' : (m.due_date && new Date(m.due_date) < new Date() ? 'متأخرة' : 'نشطة');
    var dueTxt    = m.due_date ? ' • ' + fmtDate(m.due_date) : '';

    return '<div class="rk-task-card rk-task-card--mission">'
      + '<div class="rk-task-card__header">'
      + '<div class="rk-task-card__left">'
      + '<span class="rk-task-src rk-task-src--rk">RK</span>'
      + '<div>'
      + '<p class="rk-task-card__title">' + Utils.e(m.title) + '</p>'
      + '<p class="rk-task-card__meta">' + Utils.e(m.child_name || '') + dueTxt + '</p>'
      + '</div>'
      + '</div>'
      + '<div class="rk-task-card__right">'
      + '<span class="rk-task-badge ' + statusCls + '">' + statusLbl + '</span>'
      + '<span class="rk-task-pts">' + m.points + ' نقطة</span>'
      + '</div>'
      + '</div>'
      + (m.description ? '<p class="rk-task-card__desc">' + Utils.e(m.description) + '</p>' : '')
      + (!m.completed
          ? '<div class="rk-task-prog">'
              + '<div class="rk-task-prog__bar"><div class="rk-task-prog__fill" style="width:' + pct + '%"></div></div>'
              + '<span class="rk-task-prog__txt">' + m.progress + ' / ' + m.target + '</span>'
              + '</div>'
          : '')
      + (!m.completed
          ? '<div class="rk-task-card__actions">'
              + '<button class="rk-task-btn-prog" data-id="' + m.id + '" type="button">+1 تقدم</button>'
              + '<button class="rk-task-btn-del"  data-id="' + m.id + '" type="button">حذف</button>'
              + '</div>'
          : '')
      + '</div>';
  }

  function assignmentFicheCard(a) {
    var statusMap = { pending: ['task-pending','لم يُسلَّم'], submitted: ['task-submitted','مُسلَّم'], graded: ['task-done','مُصحَّح'], submitting: ['task-active','جار التسليم'] };
    var s   = statusMap[a.status] || ['task-pending', a.status];
    var markTxt = a.mark !== null ? a.mark + '/' + a.total_mark : '';
    return '<div class="rk-fiche-task-card rk-fiche-task-card--assign">'
      + '<div class="rk-fiche-task-card__icon">📋</div>'
      + '<div class="rk-fiche-task-card__body">'
      + '<p class="rk-fiche-task-card__title">' + Utils.e(a.title) + '</p>'
      + '<p class="rk-fiche-task-card__course">' + Utils.e(a.course_title) + '</p>'
      + (a.submitted_at ? '<p class="rk-fiche-task-card__date">' + fmtDate(a.submitted_at) + '</p>' : '')
      + '</div>'
      + '<div class="rk-fiche-task-card__end">'
      + '<span class="rk-task-badge ' + s[0] + '">' + s[1] + '</span>'
      + (markTxt ? '<span class="rk-task-score">' + markTxt + '</span>' : '')
      + '</div>'
      + '</div>';
  }

  function missionFicheCard(m) {
    var pct = m.target > 0 ? Math.round(m.progress / m.target * 100) : 0;
    return '<div class="rk-fiche-task-card rk-fiche-task-card--mission" data-mid="' + m.id + '">'
      + '<div class="rk-fiche-task-card__icon" style="font-size:1rem;color:var(--e-global-color-secondary,#4C95D7);">◎</div>'
      + '<div class="rk-fiche-task-card__body">'
      + '<p class="rk-fiche-task-card__title">' + Utils.e(m.title) + '</p>'
      + (m.description ? '<p class="rk-fiche-task-card__desc">' + Utils.e(m.description) + '</p>' : '')
      + '<div class="rk-task-prog rk-task-prog--sm">'
      + '<div class="rk-task-prog__bar"><div class="rk-task-prog__fill" style="width:' + pct + '%"></div></div>'
      + '<span class="rk-task-prog__txt">' + m.progress + '/' + m.target + ' • ' + m.points + ' نقطة</span>'
      + '</div>'
      + '</div>'
      + '<div class="rk-fiche-task-card__end">'
      + '<button class="rk-task-btn-prog" data-id="' + m.id + '" type="button">+1</button>'
      + '<button class="rk-task-btn-del rk-task-btn-del--sm" data-id="' + m.id + '" type="button">🗑</button>'
      + '</div>'
      + '</div>';
  }

  function loadChildTasks(childId) {
    Core.apiGet('/coach/child/tasks?child_id=' + childId, false).then(function (d) {
      var panel = document.getElementById('rk-panel-tasks');
      if (!panel) return;

      var assignments = (d && Array.isArray(d.tutor_assignments)) ? d.tutor_assignments : [];
      var missions    = (d && Array.isArray(d.missions))          ? d.missions          : [];

      var newBtn =
        '<button class="rk-btn-primary rk-child-task-new" data-child="' + childId + '" type="button" style="margin-bottom:16px">'
        + '+ مهمة جديدة'
        + '</button>';

      var assignHtml = assignments.length
        ? assignments.map(assignmentFicheCard).join('')
        : '<div class="rk-tasks-empty-section"><p>لا توجد واجبات Tutor مرتبطة بهذا الطفل.</p></div>';

      var missionHtml = missions.length
        ? missions.map(missionFicheCard).join('')
        : '<div class="rk-tasks-empty-section"><p>لا توجد مهام RK بعد.</p></div>';

      panel.innerHTML =
        newBtn
        + '<div class="rk-fiche-tasks">'
        + '<div class="rk-ft-section">'
        + '<h4 class="rk-ft-section__title">واجبات Tutor LMS</h4>'
        + assignHtml
        + '</div>'
        + '<div class="rk-ft-section">'
        + '<h4 class="rk-ft-section__title">مهام RK الشخصية</h4>'
        + missionHtml
        + '</div>'
        + '</div>';

      var newBtnEl = panel.querySelector('.rk-child-task-new');
      if (newBtnEl) {
        newBtnEl.addEventListener('click', function () {
          showMissionModal(parseInt(newBtnEl.dataset.child, 10));
        });
      }

      panel.querySelectorAll('.rk-task-btn-prog').forEach(function (btn) {
        btn.addEventListener('click', function () { doMissionProgress(btn, childId); });
      });

      panel.querySelectorAll('.rk-task-btn-del').forEach(function (btn) {
        btn.addEventListener('click', function () { doMissionDelete(btn, childId); });
      });

    }).catch(function () {
      var panel = document.getElementById('rk-panel-tasks');
      if (panel) panel.innerHTML = '<div class="rk-empty" style="padding:24px"><p>تعذّر تحميل المهام.</p></div>';
    });
  }

  function doMissionProgress(btn, childId) {
    var id = parseInt(btn.dataset.id, 10);
    btn.disabled = true;
    Core.apiPost('/coach/mission/' + id + '/progress', {}).then(function (res) {
      if (res && res.success) {
        if (childId) loadChildTasks(childId);
      }
    }).catch(function () { btn.disabled = false; });
  }

  function doMissionDelete(btn, childId) {
    if (!confirm('حذف هذه المهمة؟')) return;
    var id = parseInt(btn.dataset.id, 10);
    Core.apiDelete('/coach/mission/' + id).then(function (res) {
      if (res && res.success) {
        if (childId) loadChildTasks(childId);
      }
    }).catch(function () {});
  }

  function showMissionModal(preChildId) {
    if (!S.students) {
      Core.apiGet('/coach/students', true).then(function (list) {
        S.students = RK.Pages.Students.dedupe(list);
        showMissionModal(preChildId);
      });
      return;
    }

    var studentsOpts = S.students.map(function (st) {
      var sel = preChildId && st.child_id === preChildId ? ' selected' : '';
      return '<option value="' + st.child_id + '"' + sel + '>' + Utils.e(st.name) + '</option>';
    }).join('');

    var overlay = document.createElement('div');
    overlay.className = 'rk-modal-overlay';
    overlay.innerHTML =
      '<div class="rk-modal" role="dialog" aria-modal="true">'
      + '<div class="rk-modal__header">'
      + '<h3 class="rk-modal__title">إنشاء مهمة جديدة</h3>'
      + '<button class="rk-modal__close" type="button" aria-label="إغلاق">✕</button>'
      + '</div>'
      + '<form class="rk-modal__form" id="rk-mission-form" novalidate>'
      + '<div class="rk-mf-field">'
      + '<label class="rk-mf-label">الطفل <span class="rk-req">*</span></label>'
      + '<select class="rk-mf-input" name="child_id" required>' + (studentsOpts || '<option value="">— اختر طفلاً —</option>') + '</select>'
      + '</div>'
      + '<div class="rk-mf-field">'
      + '<label class="rk-mf-label">عنوان المهمة <span class="rk-req">*</span></label>'
      + '<input class="rk-mf-input" name="title" type="text" placeholder="مثال: قراءة سورة البقرة" required maxlength="200">'
      + '</div>'
      + '<div class="rk-mf-field">'
      + '<label class="rk-mf-label">الوصف</label>'
      + '<textarea class="rk-mf-input" name="description" rows="3" placeholder="تفاصيل المهمة…"></textarea>'
      + '</div>'
      + '<div class="rk-mf-row">'
      + '<div class="rk-mf-field">'
      + '<label class="rk-mf-label">عدد التكرارات المطلوبة</label>'
      + '<input class="rk-mf-input" name="target" type="number" min="1" max="30" value="1">'
      + '</div>'
      + '<div class="rk-mf-field">'
      + '<label class="rk-mf-label">النقاط المكتسبة</label>'
      + '<input class="rk-mf-input" name="points" type="number" min="5" max="200" value="10">'
      + '</div>'
      + '<div class="rk-mf-field">'
      + '<label class="rk-mf-label">تاريخ الاستحقاق</label>'
      + '<input class="rk-mf-input" name="due_date" type="date">'
      + '</div>'
      + '</div>'
      + '<div class="rk-mf-actions">'
      + '<button class="rk-mf-btn rk-mf-btn--cancel" type="button">إلغاء</button>'
      + '<button class="rk-mf-btn rk-mf-btn--submit" type="submit">إنشاء المهمة</button>'
      + '</div>'
      + '</form>'
      + '</div>';

    document.body.appendChild(overlay);

    var close = function () { if (overlay.parentNode) overlay.parentNode.removeChild(overlay); };
    overlay.querySelector('.rk-modal__close').addEventListener('click', close);
    overlay.querySelector('.rk-mf-btn--cancel').addEventListener('click', close);
    overlay.addEventListener('click', function (ev) { if (ev.target === overlay) close(); });

    overlay.querySelector('#rk-mission-form').addEventListener('submit', function (ev) {
      ev.preventDefault();
      var fd      = new FormData(ev.target);
      var payload = {
        child_id:    parseInt(fd.get('child_id'), 10),
        title:       fd.get('title'),
        description: fd.get('description'),
        target:      parseInt(fd.get('target'), 10) || 1,
        points:      parseInt(fd.get('points'), 10) || 10,
        due_date:    fd.get('due_date') || ''
      };

      if (!payload.child_id || !payload.title) return;

      var submitBtn = overlay.querySelector('.rk-mf-btn--submit');
      submitBtn.disabled = true;
      submitBtn.textContent = 'جاري الحفظ…';

      Core.apiPost('/coach/mission', payload).then(function (res) {
        if (res && res.success) {
          close();
          if (preChildId) loadChildTasks(preChildId);
        } else {
          submitBtn.disabled = false;
          submitBtn.textContent = 'إنشاء المهمة';
        }
      }).catch(function () {
        submitBtn.disabled = false;
        submitBtn.textContent = 'إنشاء المهمة';
      });
    });
  }

  RK.Pages = RK.Pages || {};
  RK.Pages.Tasks = {
    missionCard: missionCard,
    assignmentFicheCard: assignmentFicheCard,
    missionFicheCard: missionFicheCard,
    loadChildTasks: loadChildTasks,
    showMissionModal: showMissionModal
  };
})();