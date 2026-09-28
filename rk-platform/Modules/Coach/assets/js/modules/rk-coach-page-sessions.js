/**
 * RK Coach — Page "لقاءات" (Sessions) de la SPA coach.
 *
 * Liste des séances par plage (aujourd'hui/semaine/mois), marquage de
 * présence, gestion du lien Zoom.
 *
 * RECONSTITUÉ depuis rk-coach-page-sessions.min.js. Renommage de
 * variables + commentaires pour lisibilité.
 *
 * v9.34 — Quand le coach marque une présence, un sélecteur de leçon
 * شارات المغامرات apparaît (aucune correspondance fixe séance→cours,
 * décision explicite du 12/08/2026) : le coach choisit manuellement
 * quelle leçon valider pour cette séance, ou passe sans en choisir.
 * Valider une leçon la marque complétée côté Tutor LMS pour l'enfant
 * → elle apparaît immédiatement débloquée dans شارات المغامرات, et si
 * c'était la dernière leçon du cours, le certificat correspondant
 * apparaît automatiquement sur /dashboard/rk-certificats/ (système
 * déjà 100% dynamique, aucun changement nécessaire de ce côté).
 */
(function () {
  "use strict";

  var RK    = window.RKCoach;
  var Core  = RK.Core;
  var Utils = RK.Utils;
  var state = RK.state;

  var ICONS = {
    video: '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 10l4.553-2.276A1 1 0 0121 8.723v6.554a1 1 0 01-1.447.894L15 14M3 8a2 2 0 012-2h10a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2V8z"/></svg>',
    check: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg>',
    cross: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>',
    edit: '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>',
    calendar: '<svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>',
  };

  var TIMEZONE = "Asia/Riyadh";

  function parseUtcDate(value) {
    if (!value) return null;
    var d = new Date(String(value).replace(" ", "T") + "Z");
    return isNaN(d.getTime()) ? null : d;
  }

  function formatSessionDate(value) {
    var date = parseUtcDate(value);
    if (!date) return Utils.e(String(value || ""));
    try {
      var dateStr = new Intl.DateTimeFormat("en-GB", {
        timeZone: TIMEZONE, day: "2-digit", month: "2-digit", year: "numeric",
      }).format(date);
      var timeStr = new Intl.DateTimeFormat("en-GB", {
        timeZone: TIMEZONE, hour: "2-digit", minute: "2-digit", hour12: false,
      }).format(date);
      return dateStr + " • " + timeStr;
    } catch (e) {
      return Utils.e(String(value));
    }
  }

  function render() {
    load(state.sessRange);
  }

  function load(range) {
    state.sessRange = range;
    var cacheKey = "sessions_" + range;
    var cached = Core.cacheGet(cacheKey);
    if (cached) {
      renderList(cached);
      return;
    }
    Utils.setMain('<div class="rk-section-loading">جاري تحميل لقاءات…</div>');
    Core.apiGet("/coach/sessions?range=" + range, false)
      .then(function (res) {
        var sessions = Array.isArray(res) ? res : [];
        Core.cacheSet(cacheKey, sessions);
        renderList(sessions);
      })
      .catch(function () {
        Utils.setMain('<div class="rk-section-loading">تعذّر تحميل البيانات.</div>');
      });
  }

  function renderList(sessions) {
    var tabsHtml = [
      ["today", "اليوم"],
      ["week", "الأسبوع"],
      ["month", "الشهر"],
    ]
      .map(function (t) {
        var active = state.sessRange === t[0] ? " active" : "";
        return '<button class="rk-range-tab' + active + '" data-range="' + t[0] + '" type="button">' + t[1] + "</button>";
      })
      .join("");

    var bodyHtml = sessions.length
      ? '<div class="rk-sessions-grid">' +
          sessions.map(function (s) { return sessionRow(s, s.attendance || ""); }).join("") +
        "</div>"
      : '<div class="rk-empty"><div class="rk-empty-icon">' + ICONS.calendar + "</div><p>لا توجد جلسات في هذه الفترة.</p></div>";

    Utils.setMain('<p class="rk-page-title">لقاءات</p><div class="rk-range-tabs">' + tabsHtml + "</div>" + bodyHtml);

    document.querySelectorAll(".rk-range-tab").forEach(function (btn) {
      btn.addEventListener("click", function () {
        Core.cacheDelete("sessions_" + btn.dataset.range);
        load(btn.dataset.range);
      });
    });

    bindRowEvents();
  }

  /** Petit badge d'état (مكتملة / غير مكتملة / لم يُسجّل) affiché sur chaque ligne. */
  function attendanceBadge(status) {
    var normalized = status === "present" ? "present" : status === "absent" ? "absent" : "none";
    var label = status === "present" ? "مكتملة" : status === "absent" ? "غير مكتملة" : "لم يُسجّل";
    return '<span class="rk-badge-att ' + normalized + '">' + label + "</span>";
  }

  /** Boutons مكتملة/غير مكتملة affichés sous chaque séance. */
  function attendanceActions(bookingId, currentStatus) {
    var presentActive = currentStatus === "present";
    var absentActive = currentStatus === "absent";
    return (
      '<div class="rk-session-att-actions" style="display:flex;gap:6px;margin-top:8px;">' +
        '<button type="button" class="rk-att-btn" data-bid="' + bookingId + '" data-att="present" ' +
          'style="display:inline-flex;align-items:center;gap:5px;padding:4px 12px;border:1.5px solid ' +
          (presentActive ? "#166534" : "#e2e8f0") + ";border-radius:8px;font-size:.8rem;cursor:pointer;background:" +
          (presentActive ? "#dcfce7" : "#fff") + ';color:#166534;font-weight:600;">' + ICONS.check + " مكتملة</button>" +
        '<button type="button" class="rk-att-btn" data-bid="' + bookingId + '" data-att="absent" ' +
          'style="display:inline-flex;align-items:center;gap:5px;padding:4px 12px;border:1.5px solid ' +
          (absentActive ? "#991b1b" : "#e2e8f0") + ";border-radius:8px;font-size:.8rem;cursor:pointer;background:" +
          (absentActive ? "#fee2e2" : "#fff") + ';color:#991b1b;font-weight:600;">' + ICONS.cross + " غير مكتملة</button>" +
      "</div>"
    );
  }

  function sessionRow(session, currentStatus) {
    var timeLabel = session.start
      ? '<span dir="ltr" style="unicode-bidi:embed;">' + formatSessionDate(session.start) + "</span>" +
        (session.end_at || session.end
          ? '<span dir="ltr" style="unicode-bidi:embed;"> — ' + formatSessionDate(session.end_at || session.end).split(" • ")[1] + "</span>"
          : "")
      : Utils.e(session.date || "");

    var bookingId = Number(session.booking_id || 0);
    var meetingUrl = session.meeting_url || "";
    var childId = Number(session.child_id || 0);

    var zoomHtml = "";
    if (bookingId) {
      zoomHtml = meetingUrl
        ? '<div class="rk-session-zoom"><a href="' + Utils.e(meetingUrl) + '" target="_blank" rel="noopener noreferrer" class="rk-zoom-join-btn">' + ICONS.video + " انضم عبر Zoom</a>" +
          '<button type="button" class="rk-zoom-edit-btn" data-bid="' + bookingId + '" data-url="' + Utils.e(meetingUrl) + '" title="تعديل الرابط" aria-label="تعديل الرابط">' + ICONS.edit + "</button></div>"
        : '<div class="rk-session-zoom"><button type="button" class="rk-zoom-add-btn" data-bid="' + bookingId + '">' + ICONS.video + " إضافة رابط Zoom</button></div>";
    }

    return (
      '<div class="rk-session-row" data-bid="' + bookingId + '" data-child-id="' + childId + '">' +
        '<div class="rk-session-bar"></div>' +
        '<div class="rk-session-info">' +
          sessionHeadingHtml(session) +
          '<p class="rk-session-time">' + timeLabel + "</p>" +
          zoomHtml +
          (bookingId ? attendanceActions(bookingId, currentStatus) : "") +
          '<div class="rk-session-lesson-picker" data-lesson-picker="' + bookingId + '"></div>' +
        "</div>" +
        attendanceBadge(currentStatus) +
      "</div>"
    );
  }

  /**
   * v9.24 — Refonte hiérarchie : le titre de séance (program_name)
   * passe en <h2> (élément principal de la carte), le nom de l'enfant
   * (prénom + nom de famille) en <p> secondaire juste en dessous.
   * Avant cette version le nom seul portait le <p class="rk-session-name">
   * et le titre s'affichait en <span> inline à sa suite — inversé par
   * rapport à ce que demande le coach (titre = info principale d'une
   * carte séance, l'enfant est le sous-texte).
   * Si program_name est vide (booking sans titre renseigné), on replie
   * sur le nom de l'enfant seul en <h2> pour ne jamais laisser la carte
   * sans titre visible.
   */
  function sessionHeadingHtml(session) {
    var childName   = Utils.e(session.child_name || "");
    var familyName  = Utils.e(session.child_family_name || "");
    var programName = Utils.e(session.program_name || "");
    var fullName    = familyName ? (childName + " " + familyName) : childName;

    if (programName) {
      return (
        '<h2 class="rk-session-title">' + programName + "</h2>" +
        (fullName ? '<p class="rk-session-name">' + fullName + "</p>" : "")
      );
    }
    return '<h2 class="rk-session-title">' + (fullName || "") + "</h2>";
  }

  function bindRowEvents() {
    document.querySelectorAll(".rk-zoom-add-btn, .rk-zoom-edit-btn").forEach(function (btn) {
      btn.addEventListener("click", function () {
        toggleZoomEditor(Number(btn.dataset.bid), btn.dataset.url || "");
      });
    });

    document.querySelectorAll(".rk-att-btn").forEach(function (btn) {
      btn.addEventListener("click", function () {
        onAttendanceButtonClick(Number(btn.dataset.bid), btn.dataset.att);
      });
    });
  }

  /**
   * Clic sur مكتملة/غير مكتملة. Pour "absent", comportement inchangé (post
   * immédiat). Pour "present", ouvre d'abord le sélecteur de leçon
   * شارات المغامرات (optionnel) avant de poster — le coach peut aussi
   * ignorer le sélecteur et confirmer sans leçon.
   */
  function onAttendanceButtonClick(bookingId, status) {
    if (!bookingId || (status !== "present" && status !== "absent")) return;

    if (status === "absent") {
      submitAttendance(bookingId, status, 0);
      return;
    }

    openLessonPicker(bookingId);
  }

  /** Affiche le sélecteur de leçon شارات المغامرات pour cette séance, chargé via l'API. */
  function openLessonPicker(bookingId) {
    var row = document.querySelector('.rk-session-row[data-bid="' + bookingId + '"]');
    if (!row) return;

    var picker = row.querySelector('[data-lesson-picker="' + bookingId + '"]');
    if (!picker) return;

    // Toggle : si déjà ouvert, referme.
    if (picker.dataset.open === "1") {
      picker.innerHTML = "";
      picker.dataset.open = "0";
      return;
    }

    picker.dataset.open = "1";
    picker.innerHTML = '<p style="font-size:.78rem;color:#94a3b8;margin-top:6px;">جاري تحميل الدروس…</p>';

    // v9.35 — Le cours est déjà fixé par le booking (course_id résolu
    // côté serveur depuis booking_id) — plus besoin de child_id ici.
    Core.apiGet("/coach/session/pending-lessons?booking_id=" + bookingId, false)
      .then(function (res) {
        renderLessonPicker(picker, bookingId, (res && res.lessons) || []);
      })
      .catch(function () {
        // Échec du chargement des leçons : on ne bloque pas la présence,
        // on confirme sans leçon associée.
        submitAttendance(bookingId, "present", 0);
      });
  }

  function renderLessonPicker(picker, bookingId, lessons) {
    if (!lessons.length) {
      // Aucune leçon en attente (tout complété, ou séance sans cours
      // associé) — confirme directement la présence sans sélecteur.
      submitAttendance(bookingId, "present", 0);
      return;
    }

    var optionsHtml = '<option value="0">— بدون درس —</option>';
    lessons.forEach(function (lesson) {
      optionsHtml += '<option value="' + lesson.id + '">' + Utils.e(lesson.title) + "</option>";
    });

    picker.innerHTML =
      '<div style="margin-top:8px;padding:10px;background:#f8fafc;border-radius:10px;">' +
        '<label style="font-size:.78rem;color:#64748b;font-weight:600;display:block;margin-bottom:6px;">' +
          "الدرس المُنجز في هذه الجلسة (اختياري):" +
        "</label>" +
        '<select class="rk-mf-input rk-lesson-select" data-bid="' + bookingId + '" style="width:100%;margin-bottom:8px;">' +
          optionsHtml +
        "</select>" +
        '<button type="button" class="rk-btn-primary rk-btn-sm rk-lesson-confirm" data-bid="' + bookingId + '">تأكيد الحضور</button>' +
      "</div>";

    var confirmBtn = picker.querySelector(".rk-lesson-confirm");
    confirmBtn.addEventListener("click", function () {
      var select = picker.querySelector(".rk-lesson-select");
      var lessonId = select ? parseInt(select.value, 10) || 0 : 0;
      submitAttendance(bookingId, "present", lessonId);
    });
  }

  /** Envoie le marquage de présence (+ leçon optionnelle) et met à jour la ligne. */
  function submitAttendance(bookingId, status, lessonId) {
    var row = document.querySelector('.rk-session-row[data-bid="' + bookingId + '"]');
    if (row) row.style.opacity = ".5";

    var payload = { booking_id: bookingId, status: status };
    if (lessonId > 0) payload.lesson_id = lessonId;

    Core.apiPost("/coach/attendance", payload)
      .then(function (res) {
        if (!res || !res.success) throw new Error(res && res.code ? res.code : "error");

        if (row) {
          row.style.opacity = "";

          var badge = row.querySelector(".rk-badge-att");
          if (badge) badge.outerHTML = attendanceBadge(status);

          var actions = row.querySelector(".rk-session-att-actions");
          if (actions) {
            actions.outerHTML = attendanceActions(bookingId, status);
            row.querySelectorAll(".rk-att-btn").forEach(function (btn) {
              btn.addEventListener("click", function () {
                onAttendanceButtonClick(Number(btn.dataset.bid), btn.dataset.att);
              });
            });
          }

          var picker = row.querySelector('[data-lesson-picker="' + bookingId + '"]');
          if (picker) {
            picker.innerHTML = "";
            picker.dataset.open = "0";
          }
        }

        ["today", "week", "month"].forEach(function (r) {
          Core.cacheDelete("sessions_" + r);
        });
        Core.cacheDelete("_coach_stats");
        Core.cacheDelete("_coach_home");
      })
      .catch(function () {
        if (row) row.style.opacity = "";
        alert("تعذّر تسجيل الحضور. حاول مجدداً.");
      });
  }

  function toggleZoomEditor(bookingId, currentUrl) {
    var existing = document.getElementById("rk-zoom-inline-" + bookingId);
    if (existing) {
      existing.remove();
      return;
    }

    var row = document.querySelector('.rk-session-row[data-bid="' + bookingId + '"]');
    if (!row) return;

    var editor = document.createElement("div");
    editor.id = "rk-zoom-inline-" + bookingId;
    editor.style.cssText = "display:flex;align-items:center;gap:8px;padding:6px 12px 10px;border-top:1px solid #f1f5f9;direction:rtl;";
    editor.innerHTML =
      '<span style="flex-shrink:0;color:#64748b;display:inline-flex;">' + ICONS.video + "</span>" +
      '<input type="url" id="rk-zoom-input-' + bookingId + '" value="' + Utils.e(currentUrl) + '" placeholder="رابط Zoom أو Meet..." ' +
        'style="flex:1;padding:5px 10px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:.82rem;">' +
      '<button type="button" id="rk-zoom-save-' + bookingId + '" style="padding:5px 14px;background:var(--e-global-color-primary,#FF4411);color:#fff;border:none;border-radius:8px;font-size:.82rem;cursor:pointer;">حفظ</button>' +
      '<button type="button" id="rk-zoom-cancel-' + bookingId + '" style="padding:5px 10px;background:#f1f5f9;border:none;border-radius:8px;font-size:.82rem;cursor:pointer;">إلغاء</button>';

    row.insertAdjacentElement("afterend", editor);

    document.getElementById("rk-zoom-cancel-" + bookingId).addEventListener("click", function () {
      editor.remove();
    });
    document.getElementById("rk-zoom-save-" + bookingId).addEventListener("click", function () {
      var url = document.getElementById("rk-zoom-input-" + bookingId).value.trim();
      Core.apiPost("/coach/session/zoom", { booking_id: bookingId, meeting_url: url })
        .then(function () {
          Core.cacheDelete("sessions_" + (state.sessRange || "week"));
          load(state.sessRange || "week");
        })
        .catch(function () {
          alert("تعذّر الحفظ. حاول مجدداً.");
        });
    });
  }

  RK.Pages = RK.Pages || {};
  RK.Pages.Sessions = {
    render: render,
    load: load,
    sessionRow: sessionRow,
  };
})();