/**
 * rk-coach-page-quiz.js — Liste, création, édition, preview des quiz
 * Namespace: RKCoach.Pages.Quiz
 */
(function () {
  'use strict';
  var RK    = window.RKCoach;
  var Core  = RK.Core;
  var Utils = RK.Utils;
  var Icons = RK.Icons;
  var S     = RK.state;

  var RK_QUIZ_URLS = {};

  function fmtDate(d) { return RK.Pages.Students.fmtDate(d); }
  function dedupe(a)  { return RK.Pages.Students.dedupe(a); }

  /* ══════════════════════════════════════════════════════════
     PAGE : LISTE (اختبارات par élève → renvoie vers la fiche)
     ══════════════════════════════════════════════════════════ */
  function render() {
    // v9.27 — S.students était chargé une seule fois puis réutilisé
    // indéfiniment (if (!S.students)) : si le coach marquait une
    // présence depuis #sessions puis revenait sur #quiz, le taux de
    // "الحضور" affiché restait l'ancienne valeur en mémoire, même si
    // le serveur avait déjà invalidé son propre cache (voir
    // RK_Coach_Data::on_attendance_change(), accroché aux hooks
    // rk_session_attended/rk_session_absent). On recharge maintenant
    // systématiquement à l'entrée sur #quiz, sans cache sessionStorage
    // (apiGet(..., false)) pour éviter aussi la staleness côté client.
    Utils.setMain('<div class="rk-section-loading">جاري تحميل قائمة الأطفال…</div>');
    Core.apiGet('/coach/students', false).then(function (data) {
      S.students = dedupe(data);
      renderList();
    }).catch(function () {
      Utils.setMain('<div class="rk-section-loading">تعذّر تحميل البيانات.</div>');
    });
  }

  function renderList() {
    var rows = S.students.map(function (st) {
      var cid    = Number(st.id || st.child_id || 0);
      var att    = Number(st.attendance || 0);
      var attClr = att >= 80 ? '#166534' : (att >= 60 ? '#92400e' : '#b91c1c');
      var attBg  = att >= 80 ? '#dcfce7' : (att >= 60 ? '#fef3c7' : '#fee2e2');
      // v9.26 — colonne "اللقاء" (ex-"البرنامج") : st.program_name
      // n'existe pas dans la réponse /coach/students (champ mort qui
      // retombait toujours sur '—'). La vraie source du nom de séance
      // est next_booking.program (alimenté par session_name côté SQL —
      // voir RKP_CoachSessionRepository::find_next_booking_for_child()),
      // déjà utilisée ailleurs dans la fiche élève (nextBkHtml).
      return '<tr style="border-bottom:1px solid #f1f5f9;">'
        + '<td style="padding:12px 16px;">'
        +   '<div style="display:flex;align-items:center;gap:10px;">'
        +   '<img src="' + Utils.av(st.name, st.avatar, 36) + '" width="36" height="36" style="border-radius:50%;flex-shrink:0;">'
        +   '<span style="font-weight:600;font-size:.88rem;">' + Utils.e(st.family_name ? (st.name + ' ' + st.family_name) : st.name) + '</span>'
        +   '</div>'
        + '</td>'
        + '<td style="padding:12px 16px;font-size:.82rem;color:#64748b;">' + Utils.e((st.next_booking && st.next_booking.program) || '—') + '</td>'
        + '<td style="padding:12px 16px;">'
        +   '<span style="padding:3px 10px;border-radius:20px;font-size:.78rem;font-weight:700;'
        +   'color:' + attClr + ';background:' + attBg + ';">' + att + '%</span>'
        + '</td>'
        + '<td style="padding:12px 16px;">'
        +   '<button onclick="showChildQuiz(' + cid + ')" type="button" '
        +   'style="display:inline-flex;align-items:center;gap:5px;cursor:pointer;'
        +   'padding:7px 12px;border-radius:8px;font-size:.8rem;font-weight:600;border:none;'
        +   'color:#fff;background:var(--e-global-color-primary,#FF4411);">'
        +   Icons.iQuiz + ' اختبارات</button>'
        + '</td>'
        + '</tr>';
    }).join('');

    var html = '<div style="padding:20px 24px;">'
      + '<p class="rk-page-title" style="margin-bottom:16px;">الاختبارات</p>'
      + '<div style="background:#fff;border-radius:12px;border:1px solid #e2e8f0;overflow:hidden;">'
      + '<table style="width:100%;border-collapse:collapse;direction:rtl;">'
      + '<thead>'
      + '<tr style="background:#f8fafc;border-bottom:2px solid #e2e8f0;">'
      + '<th style="text-align:right;padding:10px 16px;font-size:.78rem;color:#64748b;font-weight:600;">الطالب</th>'
      + '<th style="text-align:right;padding:10px 16px;font-size:.78rem;color:#64748b;font-weight:600;">اللقاء</th>'
      + '<th style="text-align:right;padding:10px 16px;font-size:.78rem;color:#64748b;font-weight:600;">الحضور</th>'
      + '<th style="padding:10px 16px;"></th>'
      + '</tr>'
      + '</thead>'
      + '<tbody>'
      + (rows || '<tr><td colspan="4" style="padding:24px;text-align:center;color:#94a3b8;">لا يوجد أطفال.</td></tr>')
      + '</tbody>'
      + '</table>'
      + '</div>'
      + '</div>';

    Utils.setMain(html);
  }

  /* ══════════════════════════════════════════════════════════
     ONGLET FICHE ENFANT
     ══════════════════════════════════════════════════════════ */
  function buildPanelSkeleton() {
    return '<div class="rk-section-loading">جاري تحميل الاختبارات…</div>';
  }

  function loadChildQuizzes(childId, enrolledCourses) {
    Core.apiGet('/coach/child/quizzes?child_id=' + childId, false).then(function (quizzes) {
      var panel = document.getElementById('rk-panel-quiz');
      if (!panel) return;
      panel.innerHTML = buildContent(childId, enrolledCourses, Array.isArray(quizzes) ? quizzes : []);
      bindQuizForm(childId, enrolledCourses);
      loadChildQuizResults(childId);
    }).catch(function () {
      var panel = document.getElementById('rk-panel-quiz');
      if (!panel) return;
      panel.innerHTML = buildContent(childId, enrolledCourses, []);
      bindQuizForm(childId, enrolledCourses);
      loadChildQuizResults(childId);
    });
  }

  function loadChildQuizResults(childId) {
    Core.apiGet('/coach/child/quiz-results?child_id=' + childId, false).then(function (results) {
      var box = document.getElementById('rk-qz-results');
      if (!box) return;
      if (!Array.isArray(results) || !results.length) {
        box.innerHTML = '<div style="padding:12px 0 20px;color:#94a3b8;font-size:.83rem;">لا توجد اختبارات بعد.</div>';
        return;
      }
      var btnBase = 'padding:5px 10px;border-radius:6px;font-size:.76rem;font-weight:600;cursor:pointer;border:1px solid';
      function actions(r) {
        return '<td style="text-align:center;">'
          + '<div style="display:flex;gap:6px;flex-shrink:0;justify-content:center;">'
          + '<button onclick="previewQuiz(' + r.quiz_id + ')" type="button" title="معاينة الاختبار" '
          + 'style="' + btnBase + ' #cbd5e1;background:#fff;color:#374151;">👁 معاينة</button>'
          + '<button onclick="editQuiz(' + r.quiz_id + ',' + childId + ')" type="button" title="تعديل الاختبار" '
          + 'style="' + btnBase + ' #c7d7f0;background:#eff6ff;color:#1B4F8C;">✏️ تعديل</button>'
          + '</div></td>';
      }
      var rows = results.map(function (r) {
        if (!r.attempted) {
          return '<tr>'
            + '<td class="rk-qzr-title">' + Utils.e(r.quiz_title) + '</td>'
            + '<td colspan="4" style="color:#9ca3af;font-size:.78rem;text-align:center;">لم يُنجز بعد</td>'
            + actions(r)
            + '</tr>';
        }
        var pct    = r.score + '%';
        var ok     = r.passed;
        var badge  = ok
          ? '<span class="rk-qzr-badge rk-qzr-badge--ok">نجح</span>'
          : '<span class="rk-qzr-badge rk-qzr-badge--fail">لم ينجح</span>';
        var dateStr = r.date ? r.date.slice(0, 10) : '';
        return '<tr>'
          + '<td class="rk-qzr-title">' + Utils.e(r.quiz_title) + '</td>'
          + '<td style="text-align:center;font-weight:700;color:' + (ok ? '#166534' : '#991b1b') + ';">' + pct + '</td>'
          + '<td style="text-align:center;font-size:.78rem;color:#64748b;">' + (r.corrects !== null ? r.corrects + '/' + r.total : '') + '</td>'
          + '<td style="text-align:center;">' + badge + '</td>'
          + '<td style="text-align:center;font-size:.75rem;color:#94a3b8;">' + Utils.e(dateStr) + '</td>'
          + actions(r)
          + '</tr>';
      }).join('');
      box.innerHTML = '<div class="rk-qzr-wrap">'
        + '<p class="rk-qzr-heading">📊 الاختبارات المنشأة ونتائجها (' + results.length + ')</p>'
        + '<table class="rk-qzr-table">'
        + '<thead><tr>'
        + '<th>الاختبار</th><th>النتيجة</th><th>الإجابات</th><th>الحالة</th><th>التاريخ</th><th>إجراءات</th>'
        + '</tr></thead>'
        + '<tbody>' + rows + '</tbody>'
        + '</table></div>';
    }).catch(function () {
      var box = document.getElementById('rk-qz-results');
      if (box) box.innerHTML = '';
    });
  }

  function buildContent(childId, courses, quizzes) {
    var quizList = '';
    if (quizzes.length) {
      quizzes.forEach(function (q) { if (q.quiz_url) RK_QUIZ_URLS[q.quiz_id] = q.quiz_url; });
    }

    // v2.7 — sélecteur de cours obligatoire : sans lien réel vers un cours
    // (course_id), le quiz n'apparaît jamais dans la section اختبارات de
    // rk-adventure.php côté enfant (voir class-rk-coach-quizzes-controller.php
    // → create_quiz(), qui exige désormais ce paramètre).
    // course_id peut valoir 0 pour des réservations sans cours réel lié
    // (voir find_enrolled_courses_for_child(), branche UNION) — on les
    // exclut du sélecteur, un quiz ne peut pas s'y rattacher.
    var validCourses = (courses || []).filter(function (c) {
      return Number(c.course_id || c.id || 0) > 0;
    });
    var courseOptions = validCourses.map(function (c) {
      return '<option value="' + Number(c.course_id || c.id || 0) + '">' + Utils.e(c.course_title || c.title || '') + '</option>';
    }).join('');
    var courseField = validCourses.length
      ? '<div style="margin-bottom:10px;">'
        + '<label style="display:block;font-size:.78rem;font-weight:600;margin-bottom:4px;color:#374151;">البرنامج *</label>'
        + '<select id="rk-qz-course" required'
        + ' style="width:100%;box-sizing:border-box;padding:8px 10px;border:1px solid #cbd5e1;border-radius:6px;font-size:.85rem;">'
        + '<option value="">اختر البرنامج…</option>' + courseOptions
        + '</select>'
        + '</div>'
      // Aucun cours réservé pour cet enfant : impossible de créer un quiz
      // lié — on informe le coach plutôt que de soumettre un quiz orphelin.
      : '<div style="margin-bottom:10px;padding:10px;background:#fef2f2;border:1px solid #fecaca;border-radius:6px;color:#b91c1c;font-size:.8rem;">'
        + 'لا توجد برامج مسجّلة لهذا الطالب — يجب تسجيله في برنامج أولاً لإنشاء اختبار.'
        + '</div>';

    var form = '<form id="rk-quiz-form" style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:16px;">'
      + '<p style="font-weight:700;font-size:.85rem;color:#374151;margin:0 0 12px;">+ إنشاء اختبار جديد</p>'
      + courseField
      + '<div style="margin-bottom:10px;display:flex;gap:10px;">'
      + '<div style="flex:3;">'
      + '<label style="display:block;font-size:.78rem;font-weight:600;margin-bottom:4px;color:#374151;">عنوان الاختبار</label>'
      + '<input id="rk-qz-title" type="text" placeholder="مثال: اختبار المهارات الأسبوعي" required'
      + ' style="width:100%;box-sizing:border-box;padding:8px 10px;border:1px solid #cbd5e1;border-radius:6px;font-size:.85rem;">'
      + '</div>'
      + '<div style="flex:1;">'
      + '<label style="display:block;font-size:.78rem;font-weight:600;margin-bottom:4px;color:#374151;">درجة النجاح %</label>'
      + '<input id="rk-qz-grade" type="number" value="80" min="0" max="100"'
      + ' style="width:100%;box-sizing:border-box;padding:8px 10px;border:1px solid #cbd5e1;border-radius:6px;font-size:.85rem;">'
      + '</div>'
      + '</div>'
      + '<div id="rk-qz-questions">' + buildQuizQuestion(0) + '</div>'
      + '<div style="margin-top:10px;display:flex;gap:8px;">'
      + '<button type="button" id="rk-qz-add-q" style="padding:7px 14px;border-radius:6px;border:1px solid #cbd5e1;background:#fff;font-size:.8rem;cursor:pointer;">+ سؤال</button>'
      + '<button type="submit" id="rk-qz-submit" style="padding:7px 18px;border-radius:6px;border:none;background:var(--e-global-color-primary,#FF4411);color:#fff;font-size:.83rem;font-weight:700;cursor:pointer;">إنشاء الاختبار</button>'
      + '</div>'
      + '<div id="rk-qz-msg" style="margin-top:10px;font-size:.82rem;"></div>'
      + '</form>';

    return '<div style="padding:20px;">'
      + '<div id="rk-qz-results"><div style="color:#9ca3af;font-size:.78rem;padding:4px 0 12px;">جاري تحميل النتائج…</div></div>'
      + quizList + form + '</div>';
  }

  function buildOptRow(idx, oi, isCorrect, optValue) {
    return '<label class="rk-opt-row" style="display:flex;align-items:center;gap:8px;margin-bottom:4px;'
        + 'padding:7px 10px;border-radius:7px;cursor:pointer;'
        + 'border:2px solid ' + (isCorrect ? '#22c55e' : '#e2e8f0') + ';'
        + 'background:' + (isCorrect ? '#f0fdf4' : '#fff') + ';">'
        + '<input class="rk-qz-correct" type="radio" name="rk-qz-correct-' + idx + '" value="' + oi + '"'
        + (isCorrect ? ' checked' : '')
        + ' style="accent-color:#22c55e;width:15px;height:15px;flex-shrink:0;cursor:pointer;">'
        + '<span class="rk-opt-badge" style="font-size:.69rem;font-weight:700;min-width:32px;flex-shrink:0;'
        + 'color:' + (isCorrect ? '#166534' : '#94a3b8') + ';">' + (isCorrect ? '✓ صح' : '○') + '</span>'
        + '<input class="rk-qz-opt" type="text" placeholder="الخيار ' + (oi + 1) + '…"'
        + (optValue != null ? ' value="' + Utils.e(optValue) + '"' : '')
        + ' style="flex:1;padding:4px 0;border:none;border-bottom:1px solid #e2e8f0;'
        + 'background:transparent;font-size:.83rem;outline:none;font-family:inherit;">'
        + '</label>';
  }

  function buildQuizQuestion(idx) {
    var optRows = [0,1,2,3].map(function (oi) { return buildOptRow(idx, oi, oi === 0, null); }).join('');
    return '<div class="rk-qz-q" data-idx="' + idx + '" style="border:1px solid #e2e8f0;border-radius:8px;padding:12px;margin-bottom:10px;background:#fff;">'
      + '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">'
      + '<span style="font-size:.78rem;font-weight:600;color:#374151;">السؤال ' + (idx + 1) + '</span>'
      + (idx > 0 ? '<button type="button" class="rk-qz-rm-q" style="background:none;border:none;color:#ef4444;font-size:.8rem;cursor:pointer;">✕ حذف</button>' : '')
      + '</div>'
      + '<input class="rk-qz-qtitle" type="text" placeholder="نص السؤال…" required'
      + ' style="width:100%;box-sizing:border-box;padding:7px 10px;border:1px solid #cbd5e1;border-radius:6px;font-size:.83rem;margin-bottom:10px;">'
      + '<p style="font-size:.72rem;color:#475569;font-weight:600;margin:0 0 6px;">📍 انقر على الصف لتحديد الإجابة الصحيحة — الصف الأخضر = الإجابة الصحيحة</p>'
      + optRows
      + '</div>';
  }

  function bindQuizForm(childId, enrolledCourses) {
    var addBtn  = document.getElementById('rk-qz-add-q');
    var form    = document.getElementById('rk-quiz-form');
    var qBox    = document.getElementById('rk-qz-questions');
    var msgBox  = document.getElementById('rk-qz-msg');
    if (!form || !addBtn || !qBox) return;

    var qCount = 1;

    addBtn.addEventListener('click', function () {
      if (qCount >= 10) return;
      qBox.insertAdjacentHTML('beforeend', buildQuizQuestion(qCount));
      qBox.lastElementChild.querySelector('.rk-qz-rm-q') &&
        qBox.lastElementChild.querySelector('.rk-qz-rm-q').addEventListener('click', function () {
          qBox.lastElementChild.remove();
          qCount--;
        });
      qCount++;
    });

    qBox.addEventListener('click', function (ev) {
      var rm = ev.target.closest('.rk-qz-rm-q');
      if (!rm) return;
      rm.closest('.rk-qz-q').remove();
      qCount--;
    });

    function updateOptRows(qEl) {
      qEl.querySelectorAll('.rk-opt-row').forEach(function (row) {
        var r  = row.querySelector('.rk-qz-correct');
        var ok = r && r.checked;
        row.style.borderColor = ok ? '#22c55e' : '#e2e8f0';
        row.style.background  = ok ? '#f0fdf4' : '#fff';
        var badge = row.querySelector('.rk-opt-badge');
        if (badge) { badge.textContent = ok ? '✓ صح' : '○'; badge.style.color = ok ? '#166534' : '#94a3b8'; }
      });
    }
    qBox.addEventListener('change', function (ev) {
      if (ev.target.classList.contains('rk-qz-correct')) updateOptRows(ev.target.closest('.rk-qz-q'));
    });

    form.addEventListener('submit', function (ev) {
      ev.preventDefault();
      var courseSelect = document.getElementById('rk-qz-course');
      var courseId     = courseSelect ? parseInt(courseSelect.value || '0', 10) : 0;
      var title     = (document.getElementById('rk-qz-title') || {}).value || '';
      var grade     = parseInt((document.getElementById('rk-qz-grade') || {}).value || '80', 10);
      var submitBtn = document.getElementById('rk-qz-submit');

      // v2.7 — البرنامج obligatoire, même garde côté client que côté
      // serveur (create_quiz() rejette désormais toute requête sans
      // course_id valide).
      if (!courseId) {
        msgBox.innerHTML = '<span style="color:#b91c1c;">يرجى اختيار البرنامج المرتبط بالاختبار.</span>';
        return;
      }

      if (!title.trim()) {
        msgBox.innerHTML = '<span style="color:#b91c1c;">يرجى ملء عنوان الاختبار.</span>';
        return;
      }

      var questions = [];
      qBox.querySelectorAll('.rk-qz-q').forEach(function (qEl) {
        var qTitle  = (qEl.querySelector('.rk-qz-qtitle') || {}).value || '';
        var correct = 0;
        var radios  = qEl.querySelectorAll('.rk-qz-correct');
        radios.forEach(function (r, ri) { if (r.checked) correct = ri; });
        var options = [];
        qEl.querySelectorAll('.rk-qz-opt').forEach(function (o) { options.push(o.value); });
        if (qTitle.trim()) questions.push({ title: qTitle.trim(), correct: correct, options: options });
      });

      if (!questions.length) {
        msgBox.innerHTML = '<span style="color:#b91c1c;">يرجى إضافة سؤال واحد على الأقل.</span>';
        return;
      }

      submitBtn.disabled = true;
      submitBtn.textContent = 'جاري الإنشاء…';
      msgBox.innerHTML = '';

      Core.apiPost('/coach/quiz/create', {
        child_id: childId, course_id: courseId, quiz_title: title.trim(), passing_grade: grade, questions: questions
      }).then(function (res) {
        submitBtn.disabled = false;
        submitBtn.textContent = 'إنشاء الاختبار';
        if (res && res.success) {
          var linkHtml = res.quiz_url
            ? ' — <a href="' + Utils.e(res.quiz_url) + '" target="_blank" rel="noopener" style="color:#E8500A;">فتح الاختبار ↗</a>'
            : '';
          msgBox.innerHTML = '<span style="color:#166534;">✅ تم إنشاء الاختبار بنجاح!' + linkHtml + '</span>';
          loadChildQuizzes(childId, enrolledCourses || []);
        } else {
          msgBox.innerHTML = '<span style="color:#b91c1c;">❌ فشل الإنشاء: ' + Utils.e((res && res.code) || 'unknown') + '</span>';
          console.error('[RK_QUIZ] error', res);
        }
      }).catch(function (err) {
        submitBtn.disabled = false;
        submitBtn.textContent = 'إنشاء الاختبار';
        msgBox.innerHTML = '<span style="color:#b91c1c;">❌ خطأ في الاتصال بالخادم.</span>';
        console.error('[RK_QUIZ] fetch error', err);
      });
    });
  }

  /* ══════════════════════════════════════════════════════════
     PREVIEW / EDIT (modal)
     ══════════════════════════════════════════════════════════ */
  function previewQuiz(quizId) {
    Utils.showModal('جاري التحميل…', '<div class="rk-section-loading" style="padding:24px 0;">جاري تحميل الاختبار…</div>');

    Core.apiGet('/coach/quiz/' + quizId, false).then(function (quiz) {
      var titleEl = document.getElementById('rk-modal-title');
      var bodyEl  = document.getElementById('rk-modal-body');
      if (!bodyEl) return;
      if (titleEl) titleEl.textContent = quiz.quiz_title;

      var meta = '<div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:14px;">'
        + '<span style="font-size:.78rem;padding:3px 10px;background:#f1f5f9;border-radius:20px;color:#475569;">درجة النجاح: ' + quiz.passing_grade + '%</span>'
        + '<span style="font-size:.78rem;padding:3px 10px;background:#f1f5f9;border-radius:20px;color:#475569;">المحاولات: ' + quiz.attempts + '</span>'
        + '<span style="font-size:.78rem;padding:3px 10px;background:#f1f5f9;border-radius:20px;color:#475569;">' + quiz.questions.length + ' سؤال</span>'
        + '</div>';

      var qs = quiz.questions.map(function (q, qi) {
        var answers = q.answers.map(function (a) {
          var correct = a.is_correct;
          return '<div style="display:flex;align-items:center;gap:8px;padding:6px 10px;border-radius:6px;margin-bottom:4px;'
            + (correct ? 'background:#dcfce7;border:1px solid #bbf7d0;' : 'background:#f8fafc;border:1px solid #e2e8f0;') + '">'
            + '<span style="font-size:.8rem;">' + (correct ? '✅' : '⬜') + '</span>'
            + '<span style="font-size:.82rem;' + (correct ? 'font-weight:600;color:#166534;' : 'color:#374151;') + '">' + Utils.e(a.title) + '</span>'
            + '</div>';
        }).join('');
        return '<div style="margin-bottom:14px;padding:12px;background:#fff;border:1px solid #e2e8f0;border-radius:8px;">'
          + '<p style="margin:0 0 8px;font-weight:700;font-size:.85rem;color:#1e293b;">'
          + (qi + 1) + '. ' + Utils.e(q.title)
          + (q.mark > 1 ? ' <span style="font-size:.72rem;font-weight:400;color:#94a3b8;">(' + q.mark + ' نقاط)</span>' : '')
          + '</p>'
          + answers + '</div>';
      }).join('') || '<p style="color:#94a3b8;font-size:.83rem;">لا توجد أسئلة.</p>';

      var shareSection = quiz.quiz_url
        ? '<div style="margin-top:16px;padding:12px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;">'
          + '<p style="margin:0 0 6px;font-size:.75rem;font-weight:700;color:#374151;">📤 رابط المشاركة مع الطالب</p>'
          + '<div style="display:flex;align-items:center;gap:8px;">'
          + '<span id="rk-modal-url" style="font-size:.72rem;color:#475569;word-break:break-all;flex:1;direction:ltr;">' + Utils.e(quiz.quiz_url) + '</span>'
          + '<button id="rk-modal-copy-btn" type="button" style="padding:5px 12px;border-radius:6px;border:1px solid #cbd5e1;background:#fff;font-size:.78rem;cursor:pointer;flex-shrink:0;">📋 نسخ</button>'
          + '</div>'
          + '</div>'
        : '';

      bodyEl.innerHTML = meta + qs + shareSection;

      var copyBtn = document.getElementById('rk-modal-copy-btn');
      if (copyBtn) {
        copyBtn.addEventListener('click', function () { Utils.copyToClipboard(quiz.quiz_url); });
      }

    }).catch(function () {
      var bodyEl = document.getElementById('rk-modal-body');
      if (bodyEl) bodyEl.innerHTML = '<p style="color:#b91c1c;text-align:center;padding:20px 0;">تعذّر تحميل بيانات الاختبار.</p>';
    });
  }

  function buildQuizQuestionFilled(q, idx) {
    var answers    = q.answers || [];
    var correctIdx = 0;
    answers.forEach(function (a, ai) { if (a.is_correct) correctIdx = ai; });
    var optRows = [0,1,2,3].map(function (oi) {
        return buildOptRow(idx, oi, oi === correctIdx, (answers[oi] || {}).title || '');
    }).join('');
    return '<div class="rk-qz-q" data-idx="' + idx + '" style="border:1px solid #e2e8f0;border-radius:8px;padding:12px;margin-bottom:10px;background:#fff;">'
      + '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">'
      + '<span style="font-size:.78rem;font-weight:600;color:#374151;">السؤال ' + (idx + 1) + '</span>'
      + (idx > 0 ? '<button type="button" class="rk-qz-rm-q" style="background:none;border:none;color:#ef4444;font-size:.8rem;cursor:pointer;">✕ حذف</button>' : '')
      + '</div>'
      + '<input class="rk-qz-qtitle" type="text" placeholder="نص السؤال…" required value="' + Utils.e(q.title) + '"'
      + ' style="width:100%;box-sizing:border-box;padding:7px 10px;border:1px solid #cbd5e1;border-radius:6px;font-size:.83rem;margin-bottom:10px;">'
      + '<p style="font-size:.72rem;color:#475569;font-weight:600;margin:0 0 6px;">📍 انقر على الصف لتحديد الإجابة الصحيحة — الصف الأخضر = الإجابة الصحيحة</p>'
      + optRows
      + '</div>';
  }

  function editQuiz(quizId, childId) {
    Utils.showModal('جاري التحميل…', '<div class="rk-section-loading" style="padding:24px 0;">جاري تحميل الاختبار…</div>');

    Core.apiGet('/coach/quiz/' + quizId, false).then(function (quiz) {
      var titleEl = document.getElementById('rk-modal-title');
      var bodyEl  = document.getElementById('rk-modal-body');
      if (!bodyEl) return;
      if (titleEl) titleEl.textContent = '✏️ تعديل الاختبار';

      var qHtml = quiz.questions.length
        ? quiz.questions.map(buildQuizQuestionFilled).join('')
        : buildQuizQuestion(0);

      bodyEl.innerHTML =
        '<form id="rk-edit-quiz-form">'
        + '<div style="display:flex;gap:10px;margin-bottom:12px;">'
        + '<div style="flex:3;">'
        + '<label style="display:block;font-size:.78rem;font-weight:600;margin-bottom:4px;color:#374151;">عنوان الاختبار</label>'
        + '<input id="rk-eq-title" type="text" value="' + Utils.e(quiz.quiz_title) + '" required'
        + ' style="width:100%;box-sizing:border-box;padding:8px 10px;border:1px solid #cbd5e1;border-radius:6px;font-size:.85rem;">'
        + '</div>'
        + '<div style="flex:1;">'
        + '<label style="display:block;font-size:.78rem;font-weight:600;margin-bottom:4px;color:#374151;">درجة النجاح %</label>'
        + '<input id="rk-eq-grade" type="number" value="' + Utils.e(String(quiz.passing_grade)) + '" min="0" max="100"'
        + ' style="width:100%;box-sizing:border-box;padding:8px 10px;border:1px solid #cbd5e1;border-radius:6px;font-size:.85rem;">'
        + '</div>'
        + '</div>'
        + '<div id="rk-eq-questions">' + qHtml + '</div>'
        + '<div style="margin-top:10px;display:flex;gap:8px;">'
        + '<button type="button" id="rk-eq-add-q" style="padding:7px 14px;border-radius:6px;border:1px solid #cbd5e1;background:#fff;font-size:.8rem;cursor:pointer;">+ سؤال</button>'
        + '<button type="submit" id="rk-eq-submit" style="padding:7px 18px;border-radius:6px;border:none;background:var(--e-global-color-primary,#FF4411);color:#fff;font-size:.83rem;font-weight:700;cursor:pointer;">💾 حفظ التعديلات</button>'
        + '</div>'
        + '<div id="rk-eq-msg" style="margin-top:10px;font-size:.82rem;"></div>'
        + '</form>';

      var qCount = Math.max(quiz.questions.length, 1);
      var addBtn  = document.getElementById('rk-eq-add-q');
      var form    = document.getElementById('rk-edit-quiz-form');
      var qBox    = document.getElementById('rk-eq-questions');
      var msgBox  = document.getElementById('rk-eq-msg');

      addBtn.addEventListener('click', function () {
        if (qCount >= 10) return;
        qBox.insertAdjacentHTML('beforeend', buildQuizQuestion(qCount));
        qCount++;
      });
      qBox.addEventListener('click', function (ev) {
        var rm = ev.target.closest('.rk-qz-rm-q');
        if (!rm) return;
        rm.closest('.rk-qz-q').remove();
        qCount--;
      });

      function updateOptRowsEq(qEl) {
        qEl.querySelectorAll('.rk-opt-row').forEach(function (row) {
          var r  = row.querySelector('.rk-qz-correct');
          var ok = r && r.checked;
          row.style.borderColor = ok ? '#22c55e' : '#e2e8f0';
          row.style.background  = ok ? '#f0fdf4' : '#fff';
          var badge = row.querySelector('.rk-opt-badge');
          if (badge) { badge.textContent = ok ? '✓ صح' : '○'; badge.style.color = ok ? '#166534' : '#94a3b8'; }
        });
      }
      qBox.addEventListener('change', function (ev) {
        if (ev.target.classList.contains('rk-qz-correct')) updateOptRowsEq(ev.target.closest('.rk-qz-q'));
      });

      form.addEventListener('submit', function (ev) {
        ev.preventDefault();
        var title     = (document.getElementById('rk-eq-title') || {}).value || '';
        var grade     = parseInt((document.getElementById('rk-eq-grade') || {}).value || '80', 10);
        var submitBtn = document.getElementById('rk-eq-submit');
        if (!title.trim()) {
          msgBox.innerHTML = '<span style="color:#b91c1c;">يرجى ملء عنوان الاختبار.</span>';
          return;
        }
        var questions = [];
        qBox.querySelectorAll('.rk-qz-q').forEach(function (qEl) {
          var qTitle  = (qEl.querySelector('.rk-qz-qtitle') || {}).value || '';
          var correct = 0;
          qEl.querySelectorAll('.rk-qz-correct').forEach(function (r, ri) { if (r.checked) correct = ri; });
          var options = [];
          qEl.querySelectorAll('.rk-qz-opt').forEach(function (o) { options.push(o.value); });
          if (qTitle.trim()) questions.push({ title: qTitle.trim(), correct: correct, options: options });
        });
        if (!questions.length) {
          msgBox.innerHTML = '<span style="color:#b91c1c;">أضف سؤالاً واحداً على الأقل.</span>';
          return;
        }
        submitBtn.disabled    = true;
        submitBtn.textContent = 'جاري الحفظ…';
        Core.apiPost('/coach/quiz/' + quizId + '/update', {
          quiz_title: title, passing_grade: grade, questions: questions
        }).then(function (res) {
          submitBtn.disabled    = false;
          submitBtn.textContent = '💾 حفظ التعديلات';
          if (res && res.success) {
            msgBox.innerHTML = '<span style="color:#166534;">✅ تم حفظ التعديلات بنجاح!</span>';
            setTimeout(function () {
              var ov = document.getElementById('rk-modal-overlay');
              if (ov) ov.remove();
              if (childId) loadChildQuizzes(childId, []);
            }, 1200);
          } else {
            msgBox.innerHTML = '<span style="color:#b91c1c;">حدث خطأ، حاول مجدداً.</span>';
          }
        }).catch(function () {
          submitBtn.disabled    = false;
          submitBtn.textContent = '💾 حفظ التعديلات';
          msgBox.innerHTML = '<span style="color:#b91c1c;">حدث خطأ في الاتصال.</span>';
        });
      });

    }).catch(function () {
      var bodyEl = document.getElementById('rk-modal-body');
      if (bodyEl) bodyEl.innerHTML = '<p style="color:#b91c1c;text-align:center;padding:20px 0;">تعذّر تحميل بيانات الاختبار.</p>';
    });
  }

  RK.Pages = RK.Pages || {};
  RK.Pages.Quiz = {
    render: render,
    buildPanelSkeleton: buildPanelSkeleton,
    loadChildQuizzes: loadChildQuizzes,
    loadChildQuizResults: loadChildQuizResults,
    previewQuiz: previewQuiz,
    editQuiz: editQuiz
  };
})();