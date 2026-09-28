/**
 * RiadaKids Booking — assets/js/booking-reschedule.js
 *
 * Écran « تعديل الموعد » (Figma) — reprogrammation interne d'une
 * réservation : choix du coach, puis calendrier + créneaux, appelant
 * RescheduleAjax côté serveur (voir includes/Ajax/RescheduleAjax.php pour
 * le détail de l'intégration avec l'API PHP interne de SSA Pro).
 *
 * AJUSTEMENT (demande utilisateur) — n'ouvre plus une modale séparée
 * (#rk-reschedule-modal) : bascule le CONTENU de la modale d'édition
 * existante (#rk-edit-booking-modal, ouverte par booking-edit.js) entre
 * deux vues internes déjà présentes dans le DOM :
 *   #rk-edit-fields-view        (programme/cours/séance/enfant + note —
 *                                 vue par défaut)
 *   #rk-reschedule-fields-view  (coach + calendrier + créneaux — cachée
 *                                 dès le départ, affichée au clic sur
 *                                 "تغيير الموعد")
 * Même bascule pour le footer (#rk-edit-actions-view /
 * #rk-reschedule-actions-view) et le titre (.rk-edit-view-title--edit /
 * --reschedule). Un seul overlay/backdrop, jamais deux modales empilées.
 *
 * PILE INDÉPENDANTE (voir Assets.php) : chargé séparément de
 * booking-edit.js, mais les deux scripts opèrent désormais sur LE MÊME
 * DOM de modale — coordination via window.RKBookingEdit.currentRow()
 * (déjà exposé par booking-edit.js) pour le rowId, jamais de duplication
 * d'état.
 */
(function ($) {
    'use strict';

    function cfg() {
        return window.rkRescheduleConfig || {};
    }

    var $editView, $rescheduleView, $editActions, $rescheduleActions,
        $editTitle, $rescheduleTitle, $editNote, $editTopbar;
    var $msg, $staffDisplay, $calLabel, $calGrid, $slotsEmpty, $slotsList,
        $tzRow, $confirmBtn;
    // AJUSTEMENT (demande utilisateur) — écran de succès fusionné comme
    // vue interne de la modale unifiée (#rk-reschedule-success-view),
    // plus une modale séparée #rk-reschedule-success-modal.
    var $successView, $successTitle, $successActions, $successSummary;
    // AJOUT (demande utilisateur, rapport design §2.4) — champ "التاريخ"
    // cliquable + popover calendrier.
    var $dateToggle, $dateToggleText, $calPopover;

    var state = {
        rowId: 0,
        // AJUSTEMENT (demande utilisateur) — le champ "المدرب" liste
        // désormais des TYPES DE RENDEZ-VOUS SSA (wp_ssa_appointment_types),
        // pas des coachs directement. typeId remplace l'ancien staffId —
        // le vrai coach est résolu côté serveur à partir de ce type (voir
        // RescheduleAjax::resolve_staff_id_for_type()).
        typeId: 0,
        viewMonth: null,   // Date — 1er jour du mois affiché dans le calendrier
        selectedDate: null, // 'YYYY-MM-DD'
        selectedSlot: null, // ISO datetime complet
        tz: null,
        availability: {}    // { 'YYYY-MM-DD': [ '2026-08-19T15:45:00', ... ] }
    };

    function cacheDom() {
        $editView          = $('#rk-edit-fields-view');
        $rescheduleView     = $('#rk-reschedule-fields-view');
        $editActions        = $('#rk-edit-actions-view');
        $rescheduleActions  = $('#rk-reschedule-actions-view');
        $editTitle          = $('#rk-edit-modal-title');
        $rescheduleTitle    = $('#rk-reschedule-modal-title');
        $editNote           = $('#rk-edit-note');
        // AJOUT (rapport design — modale 1) — bandeau badge/date, propre à
        // la vue édition, masqué en vue reprogrammation (voir
        // showRescheduleView/showEditView plus bas).
        $editTopbar         = $('#rk-edit-topbar-view');

        $msg            = $('#rk-edit-msg');
        // AJUSTEMENT (demande utilisateur) — dropdown remplacé par un
        // simple affichage en lecture seule (voir Dashboard::
        // render_edit_modal()), id="rk-reschedule-staff-display".
        $staffDisplay   = $('#rk-reschedule-staff-display');
        $dateToggle     = $('#rk-reschedule-date-toggle');
        $dateToggleText = $('#rk-reschedule-date-toggle-text');
        $calPopover     = $('#rk-reschedule-calendar-popover');
        $calLabel       = $('#rk-reschedule-cal-label');
        $calGrid        = $('#rk-reschedule-cal-grid');
        $slotsEmpty     = $('#rk-reschedule-slots-empty');
        $slotsList      = $('#rk-reschedule-slots-list');
        $tzRow          = $('#rk-reschedule-timezone');
        $confirmBtn     = $('#rk-reschedule-confirm');

        $successView     = $('#rk-reschedule-success-view');
        $successTitle    = $('#rk-reschedule-success-title');
        $successActions  = $('#rk-reschedule-success-actions-view');
        $successSummary  = $('#rk-reschedule-success-summary');
    }

    function post(action, data) {
        var c = cfg();
        return $.post(
            c.ajaxUrl || '/wp-admin/admin-ajax.php',
            $.extend({ action: action, nonce: c.nonce || '' }, data)
        );
    }

    function ajaxError(xhr, fallback) {
        try {
            var r = JSON.parse(xhr.responseText);
            if (r && r.data && r.data.msg) return r.data.msg;
        } catch (e) {}

        if (xhr.status === 403) return 'انتهت صلاحية الصفحة — أعد تحميلها';
        if (xhr.status === 401) return 'يجب تسجيل الدخول';
        if (xhr.status === 409) return 'عذرًا، تم حجز هذا الوقت للتو';
        if (xhr.status === 0)   return 'تعذر الاتصال بالخادم';
        return fallback + ' (HTTP ' + xhr.status + ')';
    }

    function showMsg(text, isError) {
        $msg.text(text)
            .css({
                background: isError ? '#fee2e2' : '#dcfce7',
                color:      isError ? '#991b1b' : '#166534'
            })
            .prop('hidden', false);
    }

    function clearMsg() {
        $msg.prop('hidden', true).text('');
    }

    /* ── Fuseau client — même logique que booking-ssa.js::_timezoneLabel() ── */

    function detectTimezone() {
        try {
            return Intl.DateTimeFormat().resolvedOptions().timeZone || '';
        } catch (e) {
            return '';
        }
    }

    function timezoneLabel(tz) {
        if (!tz) return '';
        var city = tz.split('/').pop().replace(/_/g, ' ');
        var offset = '';
        try {
            var parts = new Intl.DateTimeFormat('en-US', {
                timeZone: tz, timeZoneName: 'shortOffset'
            }).formatToParts(new Date());
            var part = parts.find(function (p) { return p.type === 'timeZoneName'; });
            if (part) offset = part.value;
        } catch (e) {}
        return offset ? ('توقيت ' + city + ' (' + offset + ')') : ('توقيت ' + city);
    }

    /* ── Bascule de vue (dans la même modale) ─────────────────────── */

    function showRescheduleView(rowId) {
        state.rowId       = rowId;
        state.typeId      = 0;
        state.selectedDate = null;
        state.selectedSlot = null;
        state.availability = {};
        state.viewMonth    = new Date();
        state.tz           = detectTimezone();

        clearMsg();
        $confirmBtn.prop('disabled', true);
        $tzRow.text(timezoneLabel(state.tz));
        $slotsList.empty().prop('hidden', true);
        $slotsEmpty.prop('hidden', false);

        // AJUSTEMENT (demande utilisateur, rapport design §2.4) — champ
        // "التاريخ" réinitialisé au placeholder, popover calendrier fermé
        // par défaut (voir openCalendarPopover/closeCalendarPopover).
        $dateToggleText.text('اختر التاريخ');
        $dateToggle.attr('aria-expanded', 'false');
        $calPopover.prop('hidden', true);

        // La STRUCTURE du calendrier (mois + grille de jours) est dessinée
        // immédiatement, avant même l'ouverture du popover — cases toutes
        // désactivées tant que state.availability est vide (aucun type
        // choisi) — puis redessinée avec les vraies disponibilités dès que
        // renderCalendar() reçoit une réponse serveur.
        var monthStart = new Date(state.viewMonth.getFullYear(), state.viewMonth.getMonth(), 1);
        var monthEnd   = new Date(state.viewMonth.getFullYear(), state.viewMonth.getMonth() + 1, 0);
        $calLabel.text(monthLabel(monthStart));
        drawCalendarGrid(monthStart, monthEnd);

        // Bascule d'affichage — la modale elle-même (#rk-edit-booking-modal)
        // reste ouverte, seul son contenu change (voir doc en tête de
        // fichier).
        $editView.prop('hidden', true);
        $editActions.prop('hidden', true);
        $editTitle.prop('hidden', true);
        if ($editTopbar.length) $editTopbar.prop('hidden', true);
        $rescheduleView.prop('hidden', false);
        $rescheduleActions.prop('hidden', false);
        $rescheduleTitle.prop('hidden', false);

        // AJUSTEMENT v2 (demande utilisateur) — nom affiché lu directement
        // depuis resp.data.staff_name (colonne `coach` de wp_rk_bookings,
        // voir RescheduleAjax::ajax_get_appointment_types()), plus besoin
        // de chercher dans une liste de types.
        post('rk_reschedule_get_types', { booking_id: rowId })
            .done(function (resp) {
                if (!resp || !resp.success) {
                    showMsg((resp && resp.data && resp.data.msg) || 'تعذر تحميل بيانات اللقاء', true);
                    return;
                }

                var d = resp.data;
                var currentId = parseInt(d.current_type_id, 10) || 0;

                $staffDisplay.text(d.staff_name || '—');

                if (currentId) {
                    state.typeId = currentId;
                    renderCalendar();
                } else {
                    showMsg('تعذّر تحديد اللقاء المرتبط بهذا الموعد', true);
                }
            })
            .fail(function (xhr) {
                showMsg(ajaxError(xhr, 'تعذر تحميل بيانات اللقاء'), true);
            });
    }

    function showEditView() {
        clearMsg();
        $rescheduleView.prop('hidden', true);
        $rescheduleActions.prop('hidden', true);
        $rescheduleTitle.prop('hidden', true);
        // FIX (bug signalé, capture fournie — titre/champs/boutons de
        // succès ET édition tous visibles en même temps après "العودة إلى
        // اللقاءات" puis réouverture) — cette fonction ne masquait jamais
        // la vue SUCCÈS reprogrammation (successView/successActions/
        // successTitle), seulement la vue reprogrammation elle-même. Le
        // bouton "العودة إلى اللقاءات" (voir binding plus bas) appelle
        // cette fonction directement après avoir juste caché la modale —
        // sans ce correctif, la vue succès restait active "en dessous",
        // visible dès la réouverture suivante par-dessus la vue édition
        // fraîchement réaffichée.
        if ($successView && $successView.length) $successView.prop('hidden', true);
        if ($successActions && $successActions.length) $successActions.prop('hidden', true);
        if ($successTitle && $successTitle.length) $successTitle.prop('hidden', true);
        $editView.prop('hidden', false);
        $editActions.prop('hidden', false);
        $editTitle.prop('hidden', false);
        if ($editTopbar.length) $editTopbar.prop('hidden', false);
    }

    /**
     * AJOUT (demande utilisateur — "après la fermeture de popup tout les
     * données et champs de date doit disparu, reste que la partie 1 par
     * défaut visible") — remet à zéro TOUT l'état de la vue reprogrammation
     * (pas seulement son visibility) : type sélectionné, date/créneau
     * choisis, calendrier dessiné, contenu du select, champ التاريخ. Sans
     * cela, rouvrir la modale après une fermeture laissait les résidus de
     * la session précédente jusqu'au prochain rechargement AJAX. Exposée
     * publiquement (voir window.RKBookingReschedule ci-dessous) pour être
     * appelée depuis booking-edit.js::closeModal(), point de fermeture
     * central de la modale unifiée.
     */
    function resetRescheduleState() {
        state.rowId = 0;
        state.typeId = 0;
        state.viewMonth = null;
        state.selectedDate = null;
        state.selectedSlot = null;
        state.tz = null;
        state.availability = {};

        if ($staffDisplay && $staffDisplay.length) $staffDisplay.text('');
        if ($calGrid && $calGrid.length) $calGrid.empty();
        if ($calLabel && $calLabel.length) $calLabel.text('');
        if ($slotsList && $slotsList.length) $slotsList.empty().prop('hidden', true);
        if ($slotsEmpty && $slotsEmpty.length) $slotsEmpty.prop('hidden', false);
        if ($dateToggleText && $dateToggleText.length) $dateToggleText.text('اختر التاريخ');
        if ($dateToggle && $dateToggle.length) $dateToggle.attr('aria-expanded', 'false');
        if ($calPopover && $calPopover.length) $calPopover.prop('hidden', true);
        if ($confirmBtn && $confirmBtn.length) $confirmBtn.prop('disabled', true);

        // FIX (bug signalé, capture fournie — édition + succès
        // reprogrammation encore empilés malgré l'appel à cette fonction
        // depuis openModal()) — cette fonction ne remettait à zéro QUE
        // les DONNÉES (state.*, contenu des champs), jamais la
        // VISIBILITÉ des vues elle-même. Si la vue succès était affichée
        // au moment de fermer la modale, elle restait affichée après cet
        // appel, peu importe que ses données internes soient bien
        // vidées. Force désormais explicitement le même état final que
        // showEditView() : vue édition visible, TOUTES les autres vues
        // (reprogrammation ET succès reprogrammation) masquées —
        // inconditionnellement, sans dépendre d'un test sur l'état
        // courant.
        if ($rescheduleView && $rescheduleView.length) $rescheduleView.prop('hidden', true);
        if ($rescheduleActions && $rescheduleActions.length) $rescheduleActions.prop('hidden', true);
        if ($rescheduleTitle && $rescheduleTitle.length) $rescheduleTitle.prop('hidden', true);
        if ($successView && $successView.length) $successView.prop('hidden', true);
        if ($successActions && $successActions.length) $successActions.prop('hidden', true);
        if ($successTitle && $successTitle.length) $successTitle.prop('hidden', true);
        if ($editView && $editView.length) $editView.prop('hidden', false);
        if ($editActions && $editActions.length) $editActions.prop('hidden', false);
        if ($editTitle && $editTitle.length) $editTitle.prop('hidden', false);
        if ($editTopbar && $editTopbar.length) $editTopbar.prop('hidden', false);
    }

    /* ── Calendrier ────────────────────────────────────────────── */

    function pad2(n) { return n < 10 ? '0' + n : String(n); }

    function isoDate(d) {
        return d.getFullYear() + '-' + pad2(d.getMonth() + 1) + '-' + pad2(d.getDate());
    }

    function monthLabel(d) {
        var locale = (window.rkConfig && rkConfig.locale) ? rkConfig.locale : 'ar';
        try {
            return new Intl.DateTimeFormat(locale, { month: 'long', year: 'numeric' }).format(d);
        } catch (e) {
            return d.toLocaleDateString();
        }
    }

    function renderCalendar() {
        if (!state.typeId) return;

        var monthStart = new Date(state.viewMonth.getFullYear(), state.viewMonth.getMonth(), 1);
        var monthEnd   = new Date(state.viewMonth.getFullYear(), state.viewMonth.getMonth() + 1, 0);

        $calLabel.text(monthLabel(monthStart));

        post('rk_reschedule_get_availability', {
            booking_id:          state.rowId,
            appointment_type_id: state.typeId,
            start_date_min:      isoDate(monthStart) + ' 00:00:00',
            start_date_max:      isoDate(monthEnd)   + ' 23:59:59'
        })
            .done(function (resp) {
                state.availability = {};

                if (resp && resp.success && resp.data && resp.data.availability) {
                    // La forme exacte de la réponse SSA (liste de slots ISO,
                    // groupée ou non par jour) varie selon la version — on
                    // normalise ici en { 'YYYY-MM-DD': [{iso, label}, ...] }
                    // plutôt que de supposer une forme unique.
                    // AJUSTEMENT (bug signalé — heure affichée décalée) —
                    // `label` est désormais le texte déjà formaté côté
                    // serveur (voir RescheduleAjax::ajax_get_availability(),
                    // TimeZone::format() avec le bon fuseau) — le JS
                    // n'essaie plus jamais de reformater lui-même une heure
                    // depuis la chaîne brute SSA (voir doc historique
                    // retirée de ce fichier sur ce sujet).
                    var raw = resp.data.availability;
                    var slots = Array.isArray(raw) ? raw
                        : (raw.slots || raw.availability || raw.data || []);

                    $.each(slots, function (i, s) {
                        var iso = typeof s === 'string' ? s : (s.start_date || s.date || s.datetime);
                        if (!iso) return;
                        var label = (typeof s === 'object' && s.label) ? s.label : iso;
                        var day = iso.substring(0, 10);
                        if (!state.availability[day]) state.availability[day] = [];
                        state.availability[day].push({ iso: iso, label: label });
                    });
                }

                drawCalendarGrid(monthStart, monthEnd);
            })
            .fail(function (xhr) {
                showMsg(ajaxError(xhr, 'تعذر تحميل الأوقات المتاحة'), true);
                drawCalendarGrid(monthStart, monthEnd);
            });
    }

    function drawCalendarGrid(monthStart, monthEnd) {
        $calGrid.empty();

        var firstWeekday = monthStart.getDay(); // 0 = dimanche
        var todayIso     = isoDate(new Date());

        for (var i = 0; i < firstWeekday; i++) {
            $calGrid.append($('<span>', { class: 'rk-reschedule-cal-cell rk-reschedule-cal-cell--empty' }));
        }

        for (var day = 1; day <= monthEnd.getDate(); day++) {
            var d = new Date(monthStart.getFullYear(), monthStart.getMonth(), day);
            var iso = isoDate(d);
            var hasSlots = !!(state.availability[iso] && state.availability[iso].length);
            var isPast = iso < todayIso;

            var $cell = $('<button>', {
                type: 'button',
                class: 'rk-reschedule-cal-cell' +
                    (hasSlots ? ' is-available' : '') +
                    (iso === state.selectedDate ? ' is-selected' : ''),
                text: day,
                disabled: isPast || !hasSlots
            }).attr('data-date', iso);

            $calGrid.append($cell);
        }
    }

    /* ── Popover calendrier (champ "التاريخ") ─────────────────────── */
    // AJOUT (demande utilisateur, rapport design §2.4) — le calendrier
    // s'ouvre désormais en dropdown sous le champ "التاريخ" plutôt que
    // d'être affiché en permanence dans le flux de la modale.

    /** Navigation mois précédent/suivant — redessine la STRUCTURE même
     *  sans type choisi (voir showRescheduleView), puis recharge les
     *  vraies disponibilités si un type est sélectionné. */
    function navigateCalendarMonth() {
        if (state.typeId) {
            renderCalendar();
            return;
        }

        var monthStart = new Date(state.viewMonth.getFullYear(), state.viewMonth.getMonth(), 1);
        var monthEnd   = new Date(state.viewMonth.getFullYear(), state.viewMonth.getMonth() + 1, 0);
        $calLabel.text(monthLabel(monthStart));
        state.availability = {};
        drawCalendarGrid(monthStart, monthEnd);
    }

    function openCalendarPopover() {
        $calPopover.prop('hidden', false);
        $dateToggle.attr('aria-expanded', 'true');
    }

    function closeCalendarPopover() {
        $calPopover.prop('hidden', true);
        $dateToggle.attr('aria-expanded', 'false');
    }

    function toggleCalendarPopover() {
        if ($calPopover.prop('hidden')) {
            openCalendarPopover();
        } else {
            closeCalendarPopover();
        }
    }

    /** Formate une date ISO ('YYYY-MM-DD') pour affichage dans le champ,
     *  ex. "السبت، 19 أغسطس" — même locale que le reste du wizard. */
    function formatDateForField(iso) {
        var locale = (window.rkConfig && rkConfig.locale) ? rkConfig.locale : 'ar';
        try {
            var d = new Date(iso + 'T00:00:00');
            return new Intl.DateTimeFormat(locale, { weekday: 'long', day: 'numeric', month: 'long' }).format(d);
        } catch (e) {
            return iso;
        }
    }

    function selectDate(iso) {
        state.selectedDate = iso;
        state.selectedSlot = null;
        $confirmBtn.prop('disabled', true);

        $calGrid.find('.rk-reschedule-cal-cell').removeClass('is-selected');
        $calGrid.find('[data-date="' + iso + '"]').addClass('is-selected');

        // AJOUT (demande utilisateur, rapport design §2.4) — remplit le
        // champ "التاريخ" avec la date choisie et referme le popover
        // calendrier, avant même de savoir si des créneaux existent pour
        // ce jour (le champ doit refléter le choix de date fait, la liste
        // de créneaux en dessous gère séparément son propre état vide).
        closeCalendarPopover();
        $dateToggleText.text(formatDateForField(iso));

        var slots = state.availability[iso] || [];
        $slotsList.empty();

        if (!slots.length) {
            $slotsEmpty.prop('hidden', false);
            $slotsList.prop('hidden', true);
            return;
        }

        $slotsEmpty.prop('hidden', true);
        $slotsList.prop('hidden', false);

        // AJUSTEMENT (bug signalé — heure décalée) — timeLabel n'est plus
        // recalculé côté client (voir doc de renderCalendar() plus haut) :
        // chaque entrée de `slots` est désormais { iso, label }, `label`
        // déjà correctement formaté côté serveur.
        $.each(slots, function (i, slot) {
            $slotsList.append(
                $('<li>').append(
                    $('<button>', { type: 'button', class: 'rk-reschedule-slot', text: slot.label })
                        .attr('data-iso', slot.iso)
                )
            );
        });
    }

    function selectSlot(iso_dt, $btn) {
        state.selectedSlot = iso_dt;
        $slotsList.find('.rk-reschedule-slot').removeClass('is-selected');
        $btn.addClass('is-selected');
        $confirmBtn.prop('disabled', false);
    }

    /* ── Confirmation ──────────────────────────────────────────── */

    function confirm() {
        if (!state.selectedSlot || !state.typeId || !state.rowId) return;

        clearMsg();
        $confirmBtn.prop('disabled', true).text('جاري الحفظ...');

        // AJOUT (diagnostic — bug signalé, jour mis à jour mais pas
        // l'heure) — logging du format exact envoyé, pour confirmer que
        // state.selectedSlot contient bien l'heure (pas seulement la
        // date) au moment de l'envoi — voir aussi la normalisation
        // ajoutée côté serveur (RescheduleAjax::ajax_confirm()).
        if (window.console && window.rkConfig && rkConfig.debug) {
            console.log('[rk-reschedule] start_date envoyé:', state.selectedSlot);
        }

        post('rk_reschedule_confirm', {
            booking_id:          state.rowId,
            appointment_type_id: state.typeId,
            start_date:          state.selectedSlot
        })
            .done(function (resp) {
                if (!resp || !resp.success) {
                    showMsg((resp && resp.data && resp.data.msg) || 'تعذر تحديث الموعد', true);
                    $confirmBtn.prop('disabled', false).text('تأكيد الموعد الجديد');
                    return;
                }

                // AJOUT (demande utilisateur — "après changement les
                // informations sont bien mise à jour dans le backend base
                // de données") — SSA a bien été mis à jour à ce point
                // (sinon resp.success serait false, voir RescheduleAjax::
                // ajax_confirm()) ; db_updated distingue le cas rare où la
                // synchro locale (wp_rk_bookings) aurait échoué malgré
                // tout — avertit sans bloquer, le cron rk_ssa_sync_cron
                // rattrape l'écart.
                if (resp.data && resp.data.db_updated === false) {
                    if (window.console) {
                        console.warn('[rk-reschedule] SSA mis à jour avec succès, mais la synchronisation locale (wp_rk_bookings) a échoué — sera rattrapée par le cron de synchro.');
                    }
                }

                // AJUSTEMENT (demande utilisateur — "après changement de
                // date la date doit modifier instantanément dans la card
                // sans refresh") — reflète la nouvelle date/heure sur la
                // carte, même mécanisme déjà en place côté annulation
                // (voir booking-edit.js::confirmCancel()). Le résumé
                // (déjà formaté via TimeZone::format(), voir
                // RescheduleAjax::present_summary()) est réutilisé tel
                // quel — jamais reformaté côté client.
                if (resp.data.summary && resp.data.summary.appointment) {
                    var $row = $('[data-rk-row="' + state.rowId + '"]');
                    if ($row.length) {
                        $row.find('[data-rk-field="date"]').text(resp.data.summary.appointment_card || resp.data.summary.appointment);
                        // AJOUT (demande utilisateur — filtre par statut) —
                        // une reprogrammation passe le booking à
                        // status='rescheduled' côté serveur (voir
                        // RescheduleAjax::ajax_confirm()) — reflété ici
                        // pour que booking-history-filters.js (qui traite
                        // 'rescheduled' comme faisant partie de "مؤكد")
                        // voie la bonne valeur sans recharger la page.
                        $row.attr('data-rk-status', 'rescheduled');
                    }
                }

                // Bascule vers la vue succès (voir showSuccessModal ci-
                // dessus) — la modale (#rk-edit-booking-modal) reste
                // ouverte, plus de fermeture/réouverture d'une modale
                // séparée.
                showSuccessModal(resp.data.summary);
            })
            .fail(function (xhr) {
                showMsg(ajaxError(xhr, 'تعذر تحديث الموعد'), true);
            })
            .always(function () {
                $confirmBtn.prop('disabled', false).text('تأكيد الموعد الجديد');
            });
    }

    function showSuccessModal(summary) {
        $successSummary.empty();

        if (summary) {
            var icons = (window.rkConfig && rkConfig.icons) || {};
            // AJOUT (rapport design — modale 3 "تم تحديث الموعد", section
            // 3.5/3.6) — badge icône circulaire par carte, même structure
            // que .rk-summary-card__icon du récapitulatif de l'étape 6 du
            // wizard (voir booking-summary.js) — réutilisé ici plutôt que
            // dupliqué avec des valeurs différentes.
            var rows = [
                { label: 'الطفل',  value: summary.child,       icon: icons['user'] },
                { label: 'اللقاء', value: summary.session,     icon: icons['video'] },
                { label: 'المدرب', value: summary.coach,       icon: icons['graduation-cap'] },
                { label: 'الموعد', value: summary.appointment, icon: icons['calendar'] }
            ];

            $.each(rows, function (i, r) {
                if (!r.value) return;

                // AJUSTEMENT (demande utilisateur, spec Figma exacte
                // fournie) — ligne fuseau horaire ajoutée UNIQUEMENT sur la
                // carte "الموعد" (les autres — الطفل/اللقاء/المدرب — n'en
                // ont pas), même icône horloge que .rk-timezone-row du
                // calendrier de reprogrammation (voir components/
                // _timeslots.scss).
                var tzRow = '';
                if ('الموعد' === r.label) {
                    var tz = detectTimezone();
                    if (tz) {
                        tzRow = '<span class="rk-summary-card__tz">' +
                            (icons['clock'] || '') + ' ' + timezoneLabel(tz) +
                        '</span>';
                    }
                }

                $successSummary.append(
                    '<div class="rk-summary-card">' +
                        '<div class="rk-summary-card__icon">' + (r.icon || '') + '</div>' +
                        '<div class="rk-summary-card__text">' +
                            '<span class="rk-summary-card__label">' + r.label + '</span>' +
                            '<strong class="rk-summary-card__value">' + $('<span>').text(r.value).html() + '</strong>' +
                            tzRow +
                        '</div>' +
                    '</div>'
                );
            });
        }

        // AJUSTEMENT (demande utilisateur) — bascule de vue au lieu
        // d'ouvrir une modale séparée : cache toutes les autres vues,
        // affiche la vue succès reprogrammation.
        if ($editView) $editView.prop('hidden', true);
        if ($editActions) $editActions.prop('hidden', true);
        if ($editTitle) $editTitle.prop('hidden', true);
        if ($editTopbar) $editTopbar.prop('hidden', true);
        $rescheduleView.prop('hidden', true);
        $rescheduleActions.prop('hidden', true);
        $rescheduleTitle.prop('hidden', true);

        $successTitle.prop('hidden', false);
        $successView.prop('hidden', false);
        $successActions.prop('hidden', false);
    }

    /* ── Init ──────────────────────────────────────────────────── */

    $(function () {
        cacheDom();

        // AJOUT — API publique minimale pour booking-edit.js (voir doc de
        // resetRescheduleState() ci-dessus).
        window.RKBookingReschedule = {
            reset: resetRescheduleState
        };

        if ($rescheduleView.length) $rescheduleView.prop('hidden', true);
        if ($rescheduleActions.length) $rescheduleActions.prop('hidden', true);
        if ($rescheduleTitle.length) $rescheduleTitle.prop('hidden', true);
        if ($successView.length) $successView.prop('hidden', true);
        if ($successTitle.length) $successTitle.prop('hidden', true);
        if ($successActions.length) $successActions.prop('hidden', true);

        $(document).on('click', '.rk-open-reschedule-modal', function (e) {
            e.preventDefault();
            var rowId = window.RKBookingEdit && window.RKBookingEdit.currentRow
                ? window.RKBookingEdit.currentRow()
                : 0;
            if (rowId) showRescheduleView(rowId);
        });

        // "تراجع" — revient à la vue édition SANS fermer la modale (même
        // overlay, voir doc en tête de fichier). La fermeture complète
        // (croix, clic sur le fond, Échap) reste gérée par booking-edit.js
        // puisque c'est sa modale ; ce script ne fait que basculer la vue
        // interne pendant qu'elle est ouverte.
        $('#rk-reschedule-back').on('click', showEditView);

        // AJUSTEMENT (demande utilisateur) — plus de binding "change" ici :
        // state.typeId est désormais fixé une seule fois dans
        // showRescheduleView() (voir plus haut), au chargement des
        // données, et ne change plus jamais pendant que la vue est
        // ouverte (le champ المدرب est en lecture seule).

        $('#rk-reschedule-cal-prev').on('click', function () {
            state.viewMonth = new Date(state.viewMonth.getFullYear(), state.viewMonth.getMonth() - 1, 1);
            navigateCalendarMonth();
        });
        $('#rk-reschedule-cal-next').on('click', function () {
            state.viewMonth = new Date(state.viewMonth.getFullYear(), state.viewMonth.getMonth() + 1, 1);
            navigateCalendarMonth();
        });

        // AJOUT (demande utilisateur, rapport design §2.4) — champ
        // "التاريخ" : ouvre/ferme le popover calendrier au clic, ferme au
        // clic en dehors (fond de la modale, pas du popover lui-même).
        $dateToggle.on('click', function (e) {
            e.stopPropagation();
            toggleCalendarPopover();
        });
        $calPopover.on('click', function (e) {
            e.stopPropagation();
        });
        $(document).on('click', function () {
            if (!$calPopover.prop('hidden')) closeCalendarPopover();
        });

        $calGrid.on('click', '.rk-reschedule-cal-cell.is-available', function () {
            selectDate($(this).attr('data-date'));
        });

        $slotsList.on('click', '.rk-reschedule-slot', function () {
            selectSlot($(this).attr('data-iso'), $(this));
        });

        $confirmBtn.on('click', confirm);

        // AJUSTEMENT (demande utilisateur) — "العودة إلى اللقاءات" ferme
        // désormais toute la modale (fin du flow), au lieu de juste
        // masquer une modale de succès séparée. Remet aussi la vue
        // édition par défaut pour la prochaine ouverture.
        $('#rk-reschedule-success-close').on('click', function () {
            $('#rk-edit-booking-modal').prop('hidden', true);
            showEditView();
        });

        // FIX (bug signalé — remise à zéro de la vue à la fermeture) —
        // si l'utilisateur ferme la modale (croix/fond/Échap gérés par
        // booking-edit.js) alors qu'il était sur la vue reprogrammation OU
        // succès, la modale doit rouvrir sur "édition" la prochaine fois.
        // Observe l'attribut hidden de la modale plutôt que de dupliquer
        // les handlers de fermeture (déjà dans booking-edit.js).
        var $editModal = $('#rk-edit-booking-modal');
        if ($editModal.length && window.MutationObserver) {
            new MutationObserver(function () {
                if (!$editModal.prop('hidden')) return;
                if (($rescheduleView.length && !$rescheduleView.prop('hidden')) ||
                    ($successView.length && !$successView.prop('hidden'))) {
                    showEditView();
                }
            }).observe($editModal.get(0), { attributes: true, attributeFilter: ['hidden'] });
        }
    });

})(jQuery);
