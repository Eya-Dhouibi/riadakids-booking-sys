/**
 * rk-coach-page-evals.js — Page التقييمات (globale) + onglet fiche enfant
 * Inclut désormais la grille des compétences (skills), synchronisée
 * avec RK_MC_Skill_Service côté PHP → rapport parent.
 * Namespace: RKCoach.Pages.Evals
 */
(function () {
  'use strict';
  var RK    = window.RKCoach;
  var Core  = RK.Core;
  var Utils = RK.Utils;
  var Icons = RK.Icons;
  var S     = RK.state;

  var SKILL_DEFS = [
    { key: 'speech',     label: 'قوة الكلام'   },
    { key: 'teamwork',   label: 'قوة التعاون'  },
    { key: 'creativity', label: 'قوة الأفكار'  },
    { key: 'courage',    label: 'قوة الشجاعة'  },
    { key: 'leadership', label: 'قوة القيادة'  },
    { key: 'focus',      label: 'قوة التركيز'  }
  ];

  function dedupe(a) { return RK.Pages.Students.dedupe(a); }
  function fmtDate(d) { return RK.Pages.Students.fmtDate(d); }

  // Tolère plusieurs noms de clé possibles pour le nom de famille selon
  // la version de l'API (family_name, child_family_name, last_name, familyName).
  function getFamilyName(st) {
    if (!st) return '';
    return st.family_name || st.child_family_name || st.last_name || st.familyName || '';
  }

  /* ══════════════════════════════════════════════════════════
     PAGE GLOBALE
     ══════════════════════════════════════════════════════════ */
  function render() {
    if (!S.evalsData) Utils.setMain('<div class="rk-section-loading">جاري تحميل التقييمات…</div>');
    var studentsP = S.students
      ? Promise.resolve(S.students)
      : Core.apiGet('/coach/students').then(function (d) { S.students = dedupe(d); return S.students; });

    studentsP
      .then(function () { return S.evalsData ? Promise.resolve(S.evalsData) : Core.apiGet('/coach/evals', false); })
      .then(function (data) {
        S.evalsData   = Array.isArray(data) ? data : [];
        S.evalsFilter = S.evalsFilter || { childId: 0, courseId: 0, rating: 0 };
        draw();
      })
      .catch(function () {
        Utils.setMain('<div class="rk-empty" style="padding:48px;text-align:center;"><p style="color:#6b7280;">تعذّر تحميل التقييمات.</p></div>');
      });
  }

  function draw() {
    var students = S.students  || [];
    var all      = S.evalsData || [];
    var filter   = S.evalsFilter;

    var filtered = all.filter(function (ev) {
      if (filter.childId && ev.child_id !== filter.childId) return false;
      if (filter.courseId && Number(ev.course_id || 0) !== filter.courseId) return false;
      if (filter.rating  && Number(ev.rating) !== filter.rating) return false;
      return true;
    });

   var childOpts = '<option value="0">جميع الأطفال</option>' + students.map(function (st) {

  var cid = Number(st.id || st.child_id || 0);
  var firstName = Utils.e(st.name || "");
  var familyName = Utils.e(getFamilyName(st));

  return '<option value="' + cid + '"'
    + (Number(filter.childId) === cid ? ' selected' : '') + '>'
    + firstName
    + (familyName ? ' ' + familyName : '')
    + '</option>';

}).join('');

    /* ── Filtre الدورة — AJOUT (demande utilisateur) : n'affiche le
       sélecteur que si l'enfant sélectionné a réellement plus d'un
       cours parmi ses évaluations, pour ne pas encombrer l'UI quand ce
       n'est pas nécessaire. Construit côté client à partir des données
       déjà chargées (course_id/course_name exposés par /coach/evals),
       pas d'appel réseau supplémentaire. ── */
    var courseScope = filter.childId
      ? all.filter(function (ev) { return ev.child_id === filter.childId; })
      : all;
    var coursesSeen = {};
    courseScope.forEach(function (ev) {
      var cid = Number(ev.course_id || 0);
      if (cid && !coursesSeen[cid]) coursesSeen[cid] = ev.course_name || 'دورة';
    });
    var courseIds = Object.keys(coursesSeen);
    var courseOpts = '';
    if (courseIds.length > 1) {
      courseOpts = '<select id="rk-ev-filter-course" style="padding:7px 10px;border:1px solid #cbd5e1;border-radius:8px;font-size:.82rem;background:#fff;">'
        + '<option value="0">كل الدورات</option>'
        + courseIds.map(function (cid) {
            return '<option value="' + cid + '"' + (Number(filter.courseId) === Number(cid) ? ' selected' : '') + '>' + Utils.e(coursesSeen[cid]) + '</option>';
          }).join('')
        + '</select>';
    } else {
      filter.courseId = 0; // repli — un seul cours ou aucun : le filtre n'a pas lieu d'être
    }

    var ratingOpts = '<option value="0">كل التقييمات</option>' + [5,4,3,2,1].map(function (r) {
      return '<option value="' + r + '"' + (filter.rating === r ? ' selected' : '') + '>' + '★'.repeat(r) + '☆'.repeat(5 - r) + '</option>';
    }).join('');

    var cards = filtered.length ? filtered.map(card).join('')
      : '<div class="rk-empty" style="padding:40px;text-align:center;"><p>لا توجد تقييمات لهذا الفلتر.</p></div>';

    var html = '<div style="padding:20px 24px;">'
      + '<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:14px;">'
      + '<p class="rk-page-title" style="margin:0;">التقييمات</p>'
      + '<button id="rk-ev-toggle-form" type="button" style="display:inline-flex;align-items:center;gap:6px;'
      + 'padding:8px 16px;border-radius:8px;border:none;background:var(--e-global-color-primary,#FF4411);color:#fff;'
      + 'font-size:.83rem;font-weight:700;cursor:pointer;">' + Icons.iPlus + ' تقييم جديد</button>'
      + '</div>'
      + '<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:14px;">'
      + '<select id="rk-ev-filter-child" style="padding:7px 10px;border:1px solid #cbd5e1;border-radius:8px;font-size:.82rem;background:#fff;">' + childOpts + '</select>'
      + courseOpts
      + '<select id="rk-ev-filter-rating" style="padding:7px 10px;border:1px solid #cbd5e1;border-radius:8px;font-size:.82rem;background:#fff;">' + ratingOpts + '</select>'
      + '<span id="rk-ev-count" style="padding:5px 12px;background:#f1f5f9;border-radius:8px;font-size:.78rem;color:#64748b;">'
      + filtered.length + ' تقييم' + (all.length !== filtered.length ? ' / ' + all.length : '') + '</span>'
      + '</div>'
      + '<div id="rk-ev-add-form" style="display:none;margin-bottom:16px;">' + buildGlobalForm(students) + '</div>'
      + '<div id="rk-ev-cards">' + cards + '</div>'
      + '</div>';

    Utils.setMain(html);
    bind(students);
  }

  function card(ev) {
    var rating = Number(ev.rating) || 0;
    var stars  = '';
    for (var i = 1; i <= 5; i++) {
      stars += '<svg width="13" height="13" viewBox="0 0 24 24" fill="' + (i <= rating ? '#f59e0b' : 'none')
        + '" stroke="#f59e0b" stroke-width="2"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>';
    }
    var rBg  = rating >= 4 ? '#dcfce7' : rating >= 3 ? '#fef3c7' : '#fee2e2';
    var rClr = rating >= 4 ? '#166534' : rating >= 3 ? '#92400e' : '#b91c1c';
    var strs = (ev.strengths    || '').split('\n').filter(Boolean).slice(0, 2).join(' · ');
    var devs = (ev.developments || '').split('\n').filter(Boolean).slice(0, 2).join(' · ');
    var courseName = ev.course_name ? Utils.e(ev.course_name) : '';

    return '<div style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:14px 16px;margin-bottom:10px;">'
      + '<div style="display:flex;align-items:flex-start;gap:12px;">'
      + '<img src="' + Utils.av(ev.child_name, ev.child_avatar, 40) + '" width="40" height="40" loading="lazy" style="border-radius:50%;flex-shrink:0;">'
      + '<div style="flex:1;min-width:0;">'
      + '<div style="display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap;margin-bottom:4px;">'
      + '<span style="font-weight:700;font-size:.88rem;">' + Utils.e(ev.child_name) + '</span>'
      + '<div style="display:flex;align-items:center;gap:6px;">'
      + '<div style="display:flex;">' + stars + '</div>'
      + '<span style="padding:2px 8px;border-radius:20px;font-size:.72rem;font-weight:700;background:' + rBg + ';color:' + rClr + ';">' + rating + '/5</span>'
      + '<span style="font-size:.74rem;color:#94a3b8;">' + Utils.e(fmtDate(ev.assessed_at)) + '</span>'
      + '</div></div>'
      + (courseName ? '<span style="display:inline-block;font-size:.72rem;color:#1d4ed8;background:#eff6ff;padding:2px 9px;border-radius:20px;margin-bottom:6px;">' + courseName + '</span>' : '')
      + (ev.summary ? '<p style="margin:0;font-size:.83rem;color:#374151;line-height:1.5;">' + Utils.e(ev.summary) + '</p>' : '')
      + ((strs || devs) ? '<div style="display:flex;gap:8px;margin-top:7px;flex-wrap:wrap;">'
          + (strs ? '<span style="font-size:.72rem;color:#166534;background:#dcfce7;padding:2px 9px;border-radius:20px;">' + Utils.e(strs) + '</span>' : '')
          + (devs ? '<span style="font-size:.72rem;color:#92400e;background:#fef3c7;padding:2px 9px;border-radius:20px;">' + Utils.e(devs) + '</span>' : '')
          + '</div>' : '')
      + '</div></div></div>';
  }

  function buildGlobalForm(students) {
    var today    = new Date().toISOString().slice(0, 10);
    var childOpts = '<option value="">— اختر الطفل —</option>' + students.map(function (st) {
      var cid = Number(st.id || st.child_id || 0);
      var firstName = Utils.e(st.name || "");
      var familyName = Utils.e(getFamilyName(st));
      var fullName = familyName ? (firstName + ' ' + familyName) : firstName;
      return '<option value="' + cid + '">' + fullName + '</option>';
    }).join('');

    var skillsGrid = '<div id="rk-gev-skills" style="display:grid;gap:8px;margin-bottom:10px;">'
      + SKILL_DEFS.map(function (sk) {
          return '<div class="rk-gev-skill-row" data-key="' + sk.key + '" style="display:flex;align-items:center;justify-content:space-between;gap:10px;border:1px solid #e2e8f0;border-radius:8px;padding:8px 12px;background:#fff;">'
            + '<span style="font-size:.8rem;font-weight:600;flex:1;">' + Utils.e(sk.label) + '</span>'
            + '<div class="rk-gev-star-group" style="display:flex;gap:2px;">'
            + [1,2,3,4,5].map(function (v) {
                return '<button type="button" class="rk-gev-star-btn' + (v <= 3 ? ' active' : '') + '" data-key="' + sk.key + '" data-val="' + v + '" style="background:none;border:none;cursor:pointer;padding:1px;line-height:0;">'
                  + '<svg width="18" height="18" viewBox="0 0 24 24" fill="' + (v <= 3 ? '#f59e0b' : '#e2e8f0') + '" aria-hidden="true"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>'
                  + '</button>';
              }).join('')
            + '</div>'
            + '<input type="hidden" class="rk-gev-skill-input" data-key="' + sk.key + '" value="3">'
            + '</div>';
        }).join('')
      + '</div>';

    return '<div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:16px;">'
      + '<p style="font-weight:700;font-size:.85rem;margin:0 0 12px;color:#374151;">' + Icons.iClip + ' تقييم جديد</p>'
      + '<div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:10px;">'
      + '<div style="flex:2;min-width:160px;">'
      + '<label style="display:block;font-size:.75rem;font-weight:600;margin-bottom:4px;color:#374151;">الطفل</label>'
      + '<select id="rk-gev-child" style="width:100%;box-sizing:border-box;padding:8px 10px;border:1px solid #cbd5e1;border-radius:6px;font-size:.83rem;background:#fff;">' + childOpts + '</select>'
      + '</div>'
      + '<div style="flex:1;min-width:150px;">'
      + '<label style="display:block;font-size:.75rem;font-weight:600;margin-bottom:4px;color:#374151;">الدورة</label>'
      + '<select id="rk-gev-course" style="width:100%;box-sizing:border-box;padding:8px 10px;border:1px solid #cbd5e1;border-radius:6px;font-size:.83rem;background:#fff;"><option value="0">— اختر الطفل أولاً —</option></select>'
      + '</div>'
      + '<div style="flex:2;min-width:180px;">'
      + '<label style="display:block;font-size:.75rem;font-weight:600;margin-bottom:4px;color:#374151;">اللقاء <span style="font-weight:400;color:#94a3b8;">(اختياري — لتقييم لقاء محدد)</span></label>'
      + '<select id="rk-gev-booking" style="width:100%;box-sizing:border-box;padding:8px 10px;border:1px solid #cbd5e1;border-radius:6px;font-size:.83rem;background:#fff;"><option value="0">— بدون لقاء محدد —</option></select>'
      + '</div>'
      + '<div style="flex:1;min-width:130px;">'
      + '<label style="display:block;font-size:.75rem;font-weight:600;margin-bottom:4px;color:#374151;">التقييم</label>'
      + '<select id="rk-gev-rating" style="width:100%;box-sizing:border-box;padding:8px 10px;border:1px solid #cbd5e1;border-radius:6px;font-size:.83rem;background:#fff;">'
      + '<option value="5">⭐⭐⭐⭐⭐ ممتاز</option><option value="4">⭐⭐⭐⭐ جيد جداً</option>'
      + '<option value="3" selected>⭐⭐⭐ جيد</option><option value="2">⭐⭐ مقبول</option><option value="1">⭐ يحتاج تحسين</option>'
      + '</select></div>'
      + '</div>'
      + skillsGrid
      + '<textarea id="rk-gev-summary" rows="2" dir="rtl" placeholder="ملخص التقييم…" style="width:100%;box-sizing:border-box;padding:8px;border:1px solid #cbd5e1;border-radius:6px;font-size:.83rem;margin-bottom:8px;resize:vertical;"></textarea>'
      + '<div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:10px;">'
      + '<textarea id="rk-gev-strengths" rows="2" dir="rtl" placeholder="نقاط القوة (سطر لكل نقطة)…" style="flex:1;min-width:140px;box-sizing:border-box;padding:8px;border:1px solid #cbd5e1;border-radius:6px;font-size:.78rem;resize:vertical;"></textarea>'
      + '<textarea id="rk-gev-develop"   rows="2" dir="rtl" placeholder="محاور التطوير (سطر لكل نقطة)…" style="flex:1;min-width:140px;box-sizing:border-box;padding:8px;border:1px solid #cbd5e1;border-radius:6px;font-size:.78rem;resize:vertical;"></textarea>'
      + '</div>'
      + '<div style="display:flex;align-items:center;gap:10px;">'
      + '<button id="rk-gev-submit" type="button" style="padding:8px 18px;border-radius:8px;border:none;background:var(--e-global-color-primary,#FF4411);color:#fff;font-size:.83rem;font-weight:700;cursor:pointer;">' + Icons.iClip + ' حفظ التقييم</button>'
      + '<span id="rk-gev-msg" style="font-size:.8rem;"></span>'
      + '</div></div>';
  }

  function bind(students) {
    var childSel  = document.getElementById('rk-ev-filter-child');
    var courseSel = document.getElementById('rk-ev-filter-course');
    var ratingSel = document.getElementById('rk-ev-filter-rating');
    var countEl   = document.getElementById('rk-ev-count');
    var cardsEl   = document.getElementById('rk-ev-cards');

    function applyFilter() {
      S.evalsFilter.courseId = parseInt((courseSel || {}).value || '0', 10) || 0;
      S.evalsFilter.rating   = parseInt((ratingSel  || {}).value || '0', 10) || 0;
      var all = S.evalsData || [];
      var f   = S.evalsFilter;
      var filtered = all.filter(function (ev) {
        if (f.childId  && ev.child_id !== f.childId) return false;
        if (f.courseId && Number(ev.course_id || 0) !== f.courseId) return false;
        if (f.rating   && Number(ev.rating) !== f.rating) return false;
        return true;
      });
      if (cardsEl) cardsEl.innerHTML = filtered.length ? filtered.map(card).join('')
        : '<div class="rk-empty" style="padding:40px;text-align:center;"><p>لا توجد تقييمات لهذا الفلتر.</p></div>';
      if (countEl) countEl.textContent = filtered.length + ' تقييم' + (all.length !== filtered.length ? ' / ' + all.length : '');
    }

    // Changer d'enfant modifie la liste des cours disponibles (courseOpts
    // dépend de filter.childId) — on redessine entièrement plutôt que de
    // filtrer en place, pour reconstruire correctement le select "الدورة".
    if (childSel) childSel.addEventListener('change', function () {
      S.evalsFilter.childId  = parseInt(childSel.value || '0', 10) || 0;
      S.evalsFilter.courseId = 0;
      draw();
    });
    if (courseSel) courseSel.addEventListener('change', applyFilter);
    if (ratingSel) ratingSel.addEventListener('change', applyFilter);

    var toggleBtn = document.getElementById('rk-ev-toggle-form');
    var addForm   = document.getElementById('rk-ev-add-form');
    if (toggleBtn && addForm) {
      toggleBtn.addEventListener('click', function () {
        var isOpen = addForm.style.display !== 'none';
        addForm.style.display = isOpen ? 'none' : 'block';
        toggleBtn.textContent = '';
        toggleBtn.insertAdjacentHTML('afterbegin', isOpen ? (Icons.iPlus + ' تقييم جديد') : '✕ إغلاق');
      });
    }

    /* ── Rechargement des sélecteurs الدورة + اللقاء quand l'élève ou
       la دورة changent — le champ اللقاء liste TOUTES les séances
       réservées du cours choisi (peu importe leur date — chaque
       option affiche déjà sa propre date/heure dans son libellé).
       AJOUT (demande utilisateur) : le champ التاريخ a été retiré du
       formulaire — la date de l'évaluation est désormais dérivée
       automatiquement de la séance choisie (gevSelectedDate), ou de
       la date du jour si aucune séance n'est sélectionnée. ── */
    var gevChild  = document.getElementById('rk-gev-child');
    var gevCourse = document.getElementById('rk-gev-course');
    var gevBooking = document.getElementById('rk-gev-booking');
    var gevSelectedDate = ''; // date de la séance choisie (rk-gev-booking), sinon vide → repli sur aujourd'hui au submit

    /**
     * v9.35 — AJOUT (demande utilisateur) : le champ "الدورة" est
     * désormais toujours affiché et rempli avec la liste des cours
     * réservés (bookés) de l'enfant sélectionné — même s'il n'en a
     * qu'un seul — pour permettre de choisir explicitement un cours,
     * puis la séance qui lui est associée (rk-gev-booking, filtrée par
     * course_id). Avant cette version, le select restait caché tant
     * que l'enfant n'avait pas au moins 2 cours réservés.
     */
    function reloadGevCourses() {
      if (!gevCourse) return Promise.resolve();
      var cid = (gevChild || {}).value;
      gevCourse.innerHTML = '<option value="0">— اختر الطفل أولاً —</option>';
      if (!cid) { gevCourse.style.display = ''; return Promise.resolve(); }
      gevCourse.innerHTML = '<option value="0">جاري التحميل…</option>';
      return Core.apiGet('/coach/child/courses?child_id=' + cid, false)
        .then(function (courses) {
          courses = courses || [];
          if (!courses.length) {
            gevCourse.innerHTML = '<option value="0">لا توجد دورات محجوزة</option>';
            return;
          }
          gevCourse.innerHTML = courses.length > 1 ? '<option value="0">كل الدورات</option>' : '';
          courses.forEach(function (c) {
            var opt = document.createElement('option');
            opt.value = c.course_id;
            opt.textContent = c.course_name;
            gevCourse.appendChild(opt);
          });
          // Un seul cours : le sélectionner automatiquement pour que
          // اللقاء se filtre directement dessus sans action du coach.
          if (courses.length === 1) gevCourse.value = courses[0].course_id;
          gevCourse.style.display = '';
        })
        .catch(function () {
          gevCourse.innerHTML = '<option value="0">تعذّر تحميل الدورات</option>';
        });
    }

    /**
     * v9.36 — Liste TOUTES les séances réservées de l'enfant pour la
     * دورة choisie (peu importe leur date — chaque option affiche déjà
     * sa propre date/heure). Sélectionner une séance mémorise sa date
     * dans gevSelectedDate (voir listener plus bas), envoyée comme
     * assessed_at au submit.
     */
    function reloadGevBookings() {
      gevSelectedDate = '';
      if (!gevBooking) return;
      var cid = (gevChild || {}).value;
      var courseId = (gevCourse || {}).value || '0';
      gevBooking.innerHTML = '<option value="0">— بدون لقاء محدد —</option>';
      if (!cid) return;
      var url = '/coach/child/bookings-for-date?child_id=' + cid;
      if (courseId && courseId !== '0') url += '&course_id=' + courseId;
      Core.apiGet(url, false)
        .then(function (bookings) {
          (bookings || []).forEach(function (b) {
            var opt = document.createElement('option');
            opt.value = b.booking_id;
            opt.textContent = b.label;
            opt.setAttribute('data-date', b.date || '');
            gevBooking.appendChild(opt);
          });
          if ((bookings || []).length === 1) {
            gevBooking.value = bookings[0].booking_id;
            gevSelectedDate = bookings[0].date || '';
          }
        })
        .catch(function () { /* garde l'option par défaut */ });
    }

    // Choisir une séance mémorise sa date pour l'évaluation, pour que
    // la date enregistrée corresponde bien à celle du لقاء évalué
    // (déjà connue via booking_id → pas besoin de la ressaisir).
    if (gevBooking) gevBooking.addEventListener('change', function () {
      var opt = gevBooking.options[gevBooking.selectedIndex];
      gevSelectedDate = opt ? (opt.getAttribute('data-date') || '') : '';
    });

    if (gevChild) gevChild.addEventListener('change', function () { reloadGevCourses().then(reloadGevBookings); });
    if (gevCourse) gevCourse.addEventListener('change', reloadGevBookings);

    document.querySelectorAll('.rk-gev-star-btn').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var val   = parseInt(btn.getAttribute('data-val'), 10);
        var group = btn.closest('.rk-gev-skill-row');
        group.querySelectorAll('.rk-gev-star-btn').forEach(function (b) {
          var v = parseInt(b.getAttribute('data-val'), 10);
          b.classList.toggle('active', v <= val);
          b.querySelector('svg').setAttribute('fill', v <= val ? '#f59e0b' : '#e2e8f0');
        });
        group.querySelector('.rk-gev-skill-input').value = val;
      });
    });

    var submitBtn = document.getElementById('rk-gev-submit');
    if (!submitBtn) return;
    submitBtn.addEventListener('click', function () {
      var childId = parseInt(((document.getElementById('rk-gev-child')    || {}).value || '0'), 10);
      var date    = gevSelectedDate || today; // date de la séance choisie, sinon aujourd'hui
      var bookingId = parseInt(((document.getElementById('rk-gev-booking') || {}).value || '0'), 10);
      var rating  = parseInt(((document.getElementById('rk-gev-rating')   || {}).value || '3'), 10);
      var summary = ((document.getElementById('rk-gev-summary')  || {}).value || '').trim();
      var str     = ((document.getElementById('rk-gev-strengths')|| {}).value || '').trim();
      var dev     = ((document.getElementById('rk-gev-develop')  || {}).value || '').trim();
      var msgEl   = document.getElementById('rk-gev-msg');

      var skillScores = {};
      document.querySelectorAll('.rk-gev-skill-input').forEach(function (inp) {
        skillScores[inp.getAttribute('data-key')] = parseInt(inp.value, 10) || 0;
      });

      if (!childId) { if (msgEl) msgEl.innerHTML = '<span style="color:#b91c1c;">يرجى اختيار الطفل.</span>'; return; }

      submitBtn.disabled    = true;
      submitBtn.textContent = 'جاري الحفظ…';
      if (msgEl) msgEl.innerHTML = '';

      Core.apiPost('/coach/child/eval', {
        child_id: childId, assessed_at: date, booking_id: bookingId, rating: rating, summary: summary,
        strengths: str, developments: dev, skill_scores: skillScores
      }).then(function (r) {
        submitBtn.disabled    = false;
        submitBtn.textContent = 'حفظ التقييم';
        if (r && r.success) {
          Utils.showToast('تم حفظ التقييم بنجاح!', 'success');
          S.evalsData = null;
          Core.apiGet('/coach/evals', false).then(function (data) {
            S.evalsData = Array.isArray(data) ? data : [];
            draw();
          });
        } else if (msgEl) {
          msgEl.innerHTML = '<span style="color:#b91c1c;">فشل الحفظ. حاول مجدداً.</span>';
        }
      }).catch(function () {
        submitBtn.disabled    = false;
        submitBtn.textContent = 'حفظ التقييم';
        if (msgEl) msgEl.innerHTML = '<span style="color:#b91c1c;">خطأ في الاتصال.</span>';
      });
    });
  }

  /* ══════════════════════════════════════════════════════════
     ONGLET FICHE ENFANT
     ══════════════════════════════════════════════════════════ */
  /**
   * v9.35 — Retrait du formulaire d'ajout inline (rk-eval-form) de
   * l'onglet التقييمات dans la fiche enfant (#students) : sur demande,
   * cet onglet n'affiche désormais QUE la liste des évaluations déjà
   * enregistrées. L'ajout d'une nouvelle évaluation se fait exclusivement
   * depuis la page globale التقييمات (#evals), qui contient déjà le
   * formulaire complet (buildGlobalForm) avec sélection de l'enfant.
   */
  function loadChildEvals(childId) {
    Core.apiGet('/coach/child/evals?child_id=' + childId, false).then(function (evals) {
      var panel = document.getElementById('rk-panel-evals');
      if (!panel) return;
      var list = Array.isArray(evals) && evals.length ? evals.map(rowCompact).join('')
        : '<div class="rk-empty" style="padding:24px"><p>لا توجد تقييمات بعد.</p></div>';
      panel.innerHTML = '<div class="rk-eval-list">' + list + '</div>';
    }).catch(function () {
      var panel = document.getElementById('rk-panel-evals');
      if (panel) {
        panel.innerHTML = '<div class="rk-empty" style="padding:24px"><p>تعذّر تحميل التقييمات.</p></div>';
      }
    });
  }

  function rowCompact(ev) {
    var rating = Number(ev.rating) || 0;
    var stars  = '';
    for (var i = 1; i <= 5; i++) {
      stars += '<svg width="14" height="14" viewBox="0 0 24 24" fill="' + (i <= rating ? '#f59e0b' : 'none') + '" stroke="#f59e0b" stroke-width="2"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>';
    }
    return '<div class="rk-eval-row">'
      + '<div class="rk-eval-row__meta"><span class="rk-eval-date">' + Utils.e(ev.assessed_at || '') + '</span><div class="rk-eval-stars">' + stars + '</div></div>'
      + (ev.summary ? '<p class="rk-eval-summary">' + Utils.e(ev.summary) + '</p>' : '')
      + '</div>';
  }

  RK.Pages = RK.Pages || {};
  RK.Pages.Evals = {
    render: render,
    loadChildEvals: loadChildEvals,
    SKILL_DEFS: SKILL_DEFS
  };
})();