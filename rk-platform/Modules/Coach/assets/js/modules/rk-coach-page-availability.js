/**
 * rk-coach-page-availability.js — Page التوفر
 *
 * Éditeur de disponibilité hebdomadaire (créneaux à heure fixe, format
 * réel utilisé par les coachs — voir RKP_AvailabilityRepository côté
 * PHP) + sélecteur individual/group (capacity_type SSA) + navigation
 * par semaine calendaire réelle (prev/next).
 *
 * Chaque sauvegarde écrit TOUJOURS dans SSA (wp_ssa_appointment_types)
 * via /coach/availability — la même source que le calendrier de
 * réservation public, jamais une copie. SSA ne connaît qu'un planning
 * RÉCURRENT par jour de semaine (aucune notion de date) : le calendrier
 * public continue donc toujours de refléter le dernier planning
 * récurrent sauvegardé, quelle que soit la semaine affichée ici.
 *
 * En parallèle, chaque sauvegarde enregistre aussi un instantané daté
 * dans RK_Availability_History (wp_rk_availability_history) — un
 * journal propre au dashboard coach qui permet de RETROUVER ce qui a
 * été configuré pour chaque semaine passée/future, sans jamais changer
 * ce que les parents voient sur le calendrier public.
 *
 * Namespace: RKCoach.Pages.Availability
 */
(function () {
  'use strict';
  var RK    = window.RKCoach;
  var Core  = RK.Core;
  var Utils = RK.Utils;
  var Icons = RK.Icons;

  var DAYS = [
    { key: 'Sunday',    label: 'الأحد' },
    { key: 'Monday',    label: 'الإثنين' },
    { key: 'Tuesday',   label: 'الثلاثاء' },
    { key: 'Wednesday', label: 'الأربعاء' },
    { key: 'Thursday',  label: 'الخميس' },
    { key: 'Friday',    label: 'الجمعة' },
    { key: 'Saturday',  label: 'السبت' }
  ];

  var MONTHS_AR = ['يناير','فبراير','مارس','أبريل','مايو','يونيو','يوليو','أغسطس','سبتمبر','أكتوبر','نوفمبر','ديسمبر'];

  /**
   * Retourne le dimanche (premier jour de semaine, cohérent avec
   * RKP_AvailabilityRepository::WEEKDAYS qui commence à 'Sunday') de la
   * semaine contenant la date donnée, à minuit local.
   */
  function startOfWeek(date) {
    var d = new Date(date.getTime());
    d.setHours(0, 0, 0, 0);
    d.setDate(d.getDate() - d.getDay()); // getDay(): 0 = dimanche
    return d;
  }

  function addDays(date, n) {
    var d = new Date(date.getTime());
    d.setDate(d.getDate() + n);
    return d;
  }

  function toIsoDate(date) {
    var y = date.getFullYear();
    var m = ('0' + (date.getMonth() + 1)).slice(-2);
    var d = ('0' + date.getDate()).slice(-2);
    return y + '-' + m + '-' + d;
  }

  /**
   * Retourne { days: '9 – 15', monthYear: 'أغسطس 2026' } — séparé en deux
   * parties pour permettre une hiérarchie visuelle claire côté CSS
   * (les jours en gras, le mois/année en plus discret juste en dessous),
   * plutôt qu'une seule chaîne dense mélangeant chiffres LTR et texte
   * arabe RTL qui pouvait se lire dans le désordre selon le navigateur.
   */
  function formatWeekLabel(weekStartDate) {
    var end = addDays(weekStartDate, 6);
    var sameMonth = weekStartDate.getMonth() === end.getMonth() && weekStartDate.getFullYear() === end.getFullYear();

    var days = sameMonth
      ? weekStartDate.getDate() + ' – ' + end.getDate()
      : weekStartDate.getDate() + ' ' + MONTHS_AR[weekStartDate.getMonth()] + ' – ' + end.getDate() + ' ' + MONTHS_AR[end.getMonth()];

    var monthYear = MONTHS_AR[end.getMonth()] + ' ' + end.getFullYear();

    return { days: days, monthYear: monthYear };
  }

  function isCurrentWeek(weekStartDate) {
    return toIsoDate(weekStartDate) === toIsoDate(startOfWeek(new Date()));
  }

  var AV = {
    appointmentTypeId: 0,
    availability: {},
    capacityType: 'individual',
    capacity: 1,
    duration: 30,
    timezoneStyle: 'localized',
    timezone: '',
    dirty: false,
    currentWeekStart: startOfWeek(new Date()),
    fromHistory: false
  };

  var SVG = {
    plus:  '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>',
    x:     '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>',
    check: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg>',
    /* dir="rtl" : "prev" (précédent) pointe visuellement vers la droite, "next" vers la gauche */
    chevronPrev: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="15 18 9 12 15 6"/></svg>',
    chevronNext: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="9 18 15 12 9 6"/></svg>'
  };

  function render() {
    Utils.setMain('<div class="rk-section-loading">جاري تحميل التوفر…</div>');

    var weekIso = toIsoDate(AV.currentWeekStart);

    Core.apiGet('/coach/availability?week_start=' + weekIso, false).then(function (data) {
      data = data || {};
      AV.appointmentTypeId = data.appointment_type_id || 0;
      AV.availability      = data.availability || {};
      AV.capacityType      = data.capacity_type || 'individual';
      AV.capacity           = data.capacity || 1;
      AV.duration           = data.duration || 30;
      AV.timezoneStyle       = data.timezone_style || 'localized';
      AV.timezone            = data.timezone || '';
      AV.fromHistory         = !!data.from_history;
      AV.dirty              = false;

      Utils.setMain(buildHtml());
      bind();
    }).catch(function (err) {
      var code = (err && err.code) || '';
      var msg  = 'no_appointment_type' === code
        ? 'لم يتم العثور على برنامج مرتبط بحسابك. يرجى التواصل مع الإدارة.'
        : 'تعذّر تحميل بيانات التوفر. حاول مرة أخرى.';
      Utils.setMain(
        '<div class="rk-settings-card" style="text-align:center;padding:32px;">'
        + '<p style="color:#64748b;font-size:.9rem;">' + Utils.e(msg) + '</p>'
        + '</div>'
      );
    });
  }

  function buildHtml() {
    var html = '<div class="rk-avail">';

    html += '<div class="rk-settings-card rk-avail__header">'
      + '<div>'
      +   '<h3 class="rk-settings-section__title" style="margin:0 0 4px;">' + Icons.iClock + ' توفر جلساتك</h3>'
      +   (AV.timezone ? '<p class="rk-avail__tz">توقيتك: <span dir="ltr">' + Utils.e(AV.timezone) + '</span></p>' : '')
      + '</div>'
      + '<button type="button" id="rk-avail-add" class="rk-set-btn rk-avail__add-btn">' + SVG.plus + ' إضافة وقت</button>'
      + '</div>';

    html += '<div class="rk-settings-card rk-avail__capacity">'
      + '<h4 class="rk-avail__subtitle">نوع الجلسة</h4>'
      + '<div class="rk-avail__capacity-choices" role="radiogroup" aria-label="نوع الجلسة">'
      +   capacityOption('individual', 'فردية', 'جلسة واحد لواحد مع كل طالب')
      +   capacityOption('group', 'جماعية', 'عدة طلاب في نفس الجلسة')
      + '</div>'
      + '<div id="rk-avail-capacity-field" class="rk-avail__capacity-count"' + ('group' === AV.capacityType ? '' : ' hidden') + '>'
      +   '<label for="rk-avail-capacity-input" class="rk-set-label">الحد الأقصى للمشاركين</label>'
      +   '<input type="number" id="rk-avail-capacity-input" class="rk-set-input" min="2" max="50" value="' + Utils.e(String(AV.capacity || 2)) + '" dir="ltr" style="max-width:120px;">'
      + '</div>'
      + '</div>';

    var weekLabel = formatWeekLabel(AV.currentWeekStart);

    html += '<div class="rk-settings-card rk-avail__week-nav">'
      + '<button type="button" id="rk-avail-week-prev" class="rk-avail__week-btn" aria-label="الأسبوع السابق">' + SVG.chevronNext + '</button>'
      + '<div class="rk-avail__week-label">'
      +   '<div class="rk-avail__week-range" dir="ltr"><span>' + Utils.e(weekLabel.days) + '</span></div>'
      +   '<div class="rk-avail__week-month">' + Utils.e(weekLabel.monthYear) + '</div>'
      +   '<div class="rk-avail__week-badges">'
      +     (isCurrentWeek(AV.currentWeekStart) ? '<span class="rk-avail__week-current">الأسبوع الحالي</span>' : '')
      +     (AV.fromHistory ? '<span class="rk-avail__week-saved">' + SVG.check + ' تم الحفظ لهذا الأسبوع</span>' : '')
      +   '</div>'
      + '</div>'
      + '<button type="button" id="rk-avail-week-next" class="rk-avail__week-btn" aria-label="الأسبوع التالي">' + SVG.chevronPrev + '</button>'
      + '</div>';

    html += '<div class="rk-settings-card">'
      + '<h4 class="rk-avail__subtitle">أيام هذا الأسبوع</h4>'
      + '<div class="rk-avail__days" id="rk-avail-days">'
      + DAYS.map(buildDayRow).join('')
      + '</div>'
      + '</div>';

    html += '<div class="rk-avail__savebar">'
      + '<div id="rk-avail-notice"></div>'
      + '<button type="button" id="rk-avail-save" class="rk-set-btn">حفظ التغييرات</button>'
      + '</div>';

    html += '</div>';
    return html;
  }

  function capacityOption(value, label, hint) {
    var checked = AV.capacityType === value;
    return '<label class="rk-avail__capacity-opt' + (checked ? ' is-checked' : '') + '">'
      + '<input type="radio" name="rk-avail-capacity" value="' + value + '"' + (checked ? ' checked' : '') + '>'
      + '<span class="rk-avail__capacity-opt-label">' + Utils.e(label) + '</span>'
      + '<span class="rk-avail__capacity-opt-hint">' + Utils.e(hint) + '</span>'
      + '</label>';
  }

  function buildDayRow(day, index) {
    var slots    = AV.availability[day.key] || [];
    var dayDate  = addDays(AV.currentWeekStart, index);
    var dateStr  = dayDate.getDate() + ' ' + MONTHS_AR[dayDate.getMonth()];
    var isToday  = toIsoDate(dayDate) === toIsoDate(new Date());

    return '<div class="rk-avail__day' + (isToday ? ' rk-avail__day--today' : '') + '" data-day="' + day.key + '">'
      + '<div class="rk-avail__day-label">'
      +   '<span class="rk-avail__day-name">' + Utils.e(day.label) + '</span>'
      +   '<span class="rk-avail__day-date" dir="ltr">' + Utils.e(dateStr) + '</span>'
      + '</div>'
      + '<div class="rk-avail__day-slots" data-day-slots="' + day.key + '">'
      +   (slots.length ? slots.map(function (s, i) { return buildSlotChip(day.key, s, i); }).join('') : '<span class="rk-avail__day-empty">لا يوجد وقت محدد</span>')
      + '</div>'
      + '</div>';
  }

  function buildSlotChip(dayKey, slot, index) {
    var time = (slot.time_start || '').substring(0, 5);
    return '<span class="rk-avail__chip" data-day="' + dayKey + '" data-index="' + index + '">'
      + '<span class="rk-avail__chip-dot" aria-hidden="true"></span>'
      + '<span dir="ltr">' + Utils.e(time) + '</span>'
      + '<button type="button" class="rk-avail__chip-remove" data-remove-day="' + dayKey + '" data-remove-index="' + index + '" aria-label="إزالة">' + SVG.x + '</button>'
      + '</span>';
  }

  function bind() {
    var root = document.querySelector('.rk-avail');
    if (!root) return;

    root.querySelectorAll('input[name="rk-avail-capacity"]').forEach(function (input) {
      input.addEventListener('change', function () {
        AV.capacityType = input.value;
        root.querySelectorAll('.rk-avail__capacity-opt').forEach(function (opt) {
          opt.classList.toggle('is-checked', opt.querySelector('input').value === input.value);
        });
        var capField = document.getElementById('rk-avail-capacity-field');
        if (capField) capField.hidden = ('group' !== input.value);
        markDirty();
      });
    });

    var capInput = document.getElementById('rk-avail-capacity-input');
    if (capInput) {
      capInput.addEventListener('input', function () {
        AV.capacity = parseInt(capInput.value, 10) || 2;
        markDirty();
      });
    }

    root.addEventListener('click', function (e) {
      var removeBtn = e.target.closest('[data-remove-day]');
      if (removeBtn) {
        var day   = removeBtn.getAttribute('data-remove-day');
        var index = parseInt(removeBtn.getAttribute('data-remove-index'), 10);
        (AV.availability[day] || []).splice(index, 1);
        refreshDaySlots(day);
        markDirty();
        return;
      }
    });

    var addBtn = document.getElementById('rk-avail-add');
    if (addBtn) {
      addBtn.addEventListener('click', function () { openAddSlotForm(); });
    }

    var prevBtn = document.getElementById('rk-avail-week-prev');
    if (prevBtn) {
      prevBtn.addEventListener('click', function () { changeWeek(-1); });
    }

    var nextBtn = document.getElementById('rk-avail-week-next');
    if (nextBtn) {
      nextBtn.addEventListener('click', function () { changeWeek(1); });
    }

    var saveBtn = document.getElementById('rk-avail-save');
    if (saveBtn) {
      saveBtn.addEventListener('click', save);
    }

    window.addEventListener('beforeunload', function (e) {
      if (AV.dirty) { e.preventDefault(); e.returnValue = ''; }
    });
  }

  function changeWeek(direction) {
    if (AV.dirty) {
      var confirmed = window.confirm('لديك تغييرات غير محفوظة. هل تريد المتابعة بدون حفظ؟');
      if (!confirmed) return;
    }
    AV.currentWeekStart = addDays(AV.currentWeekStart, direction * 7);
    render();
  }

  function openAddSlotForm() {
    var existing = document.getElementById('rk-avail-add-form');
    if (existing) { existing.remove(); return; }

    var daysOptions = DAYS.map(function (d) { return '<option value="' + d.key + '">' + Utils.e(d.label) + '</option>'; }).join('');

    var hourOptions = [];
    for (var h = 1; h <= 12; h++) {
      var hh = (h < 10 ? '0' : '') + h;
      hourOptions.push('<option value="' + hh + '">' + hh + '</option>');
    }
    var minuteOptions = ['00', '15', '30', '45'].map(function (m) {
      return '<option value="' + m + '">' + m + '</option>';
    }).join('');
    var ampmOptions =
      '<option value="AM">صباحاً</option>'
      + '<option value="PM">مساءً</option>';

    var form = document.createElement('div');
    form.id = 'rk-avail-add-form';
    form.className = 'rk-avail__add-form';
    form.innerHTML =
      '<select id="rk-avail-add-day" class="rk-set-input">' + daysOptions + '</select>'
      + '<div class="rk-avail__time-picker" dir="ltr">'
      +   '<select id="rk-avail-add-hour" class="rk-set-input rk-avail__time-part">' + hourOptions.join('') + '</select>'
      +   '<span class="rk-avail__time-sep">:</span>'
      +   '<select id="rk-avail-add-minute" class="rk-set-input rk-avail__time-part">' + minuteOptions + '</select>'
      +   '<select id="rk-avail-add-ampm" class="rk-set-input rk-avail__time-part rk-avail__ampm">' + ampmOptions + '</select>'
      + '</div>'
      + '<button type="button" id="rk-avail-add-confirm" class="rk-set-btn">' + SVG.check + ' إضافة</button>';

    var header = document.querySelector('.rk-avail__header');
    header.insertAdjacentElement('afterend', form);

    document.getElementById('rk-avail-add-confirm').addEventListener('click', function () {
      var day    = document.getElementById('rk-avail-add-day').value;
      var hour12 = parseInt(document.getElementById('rk-avail-add-hour').value, 10);
      var minute = document.getElementById('rk-avail-add-minute').value;
      var ampm   = document.getElementById('rk-avail-add-ampm').value;

      if (!hour12 || !minute || !ampm) {
        notice('يرجى اختيار وقت.', false);
        return;
      }

      // Conversion 12h (AM/PM) → 24h, format attendu par time_start (HH:MM:00)
      var hour24 = hour12 % 12;
      if (ampm === 'PM') hour24 += 12;
      var hh24 = (hour24 < 10 ? '0' : '') + hour24;
      var time = hh24 + ':' + minute;

      AV.availability[day] = AV.availability[day] || [];
      AV.availability[day].push({ time_start: time + ':00' });
      AV.availability[day].sort(function (a, b) { return a.time_start.localeCompare(b.time_start); });

      refreshDaySlots(day);
      markDirty();
      form.remove();
    });
  }

  function refreshDaySlots(dayKey) {
    var container = document.querySelector('[data-day-slots="' + dayKey + '"]');
    if (!container) return;
    var slots = AV.availability[dayKey] || [];
    container.innerHTML = slots.length
      ? slots.map(function (s, i) { return buildSlotChip(dayKey, s, i); }).join('')
      : '<span class="rk-avail__day-empty">لا يوجد وقت محدد</span>';
  }

  function markDirty() {
    AV.dirty = true;
    var saveBtn = document.getElementById('rk-avail-save');
    if (saveBtn) saveBtn.classList.add('rk-avail__save--pending');
  }

  function save() {
    var saveBtn = document.getElementById('rk-avail-save');
    if (saveBtn) { saveBtn.disabled = true; saveBtn.textContent = 'جارٍ الحفظ…'; }

    Core.apiPost('/coach/availability', { availability: AV.availability, week_start: toIsoDate(AV.currentWeekStart) })
      .then(function () {
        return Core.apiPost('/coach/availability/capacity', {
          capacity_type: AV.capacityType,
          capacity: 'group' === AV.capacityType ? Math.max(2, Math.min(50, AV.capacity)) : 1
        });
      })
      .then(function () {
        AV.dirty = false;
        AV.fromHistory = true;
        if (saveBtn) saveBtn.classList.remove('rk-avail__save--pending');
        notice('تم حفظ التغييرات بنجاح.', true);
      })
      .catch(function (err) {
        var messages = {
          no_appointment_type: 'لم يتم العثور على برنامج مرتبط بحسابك.',
          invalid_slots: 'صيغة الأوقات غير صحيحة، حاول مرة أخرى.',
          invalid_capacity: 'عدد المشاركين يجب أن يكون بين 2 و50.',
          save_failed: 'تعذّر حفظ التغييرات. حاول مرة أخرى.'
        };
        notice(messages[(err && err.code) || ''] || 'حدث خطأ أثناء الحفظ.', false);
      })
      .finally(function () {
        if (saveBtn) { saveBtn.disabled = false; saveBtn.textContent = 'حفظ التغييرات'; }
      });
  }

  function notice(msg, ok) {
    var el = document.getElementById('rk-avail-notice');
    if (!el) return;
    el.innerHTML = '<div class="rk-set-notice ' + (ok ? 'rk-set-notice--ok' : 'rk-set-notice--err') + '">'
      + (ok ? SVG.check + ' ' : '') + Utils.e(msg) + '</div>';
    el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }

  RK.Pages = RK.Pages || {};
  RK.Pages.Availability = {
    render: render
  };
})();
