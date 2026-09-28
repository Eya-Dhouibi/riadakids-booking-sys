/**
 * RiadaKids Booking — assets/js/booking-edit.js
 *
 * Modale d'édition d'une réservation depuis « لقاءاتي القادمة ».
 *
 * Listes chaînées : programme → cours → séance. Chaque changement de niveau
 * supérieur invalide les niveaux inférieurs (un cours ne survit pas au
 * changement de programme).
 *
 * La date n'est PAS éditable : elle appartient à SSA. La modifier en base
 * laisserait le créneau réservé chez le coach inchangé.
 */
(function ($) {
    'use strict';

    /*
     * rkConfig est injecté par wp_localize_script() SUR le handle 'rk-booking',
     * donc APRÈS ce fichier (qui en est une dépendance). Le lire au chargement
     * donnait un objet vide → nonce absent → 403 → « خطأ في الاتصال بالخادم ».
     * On le résout donc à chaque appel, pas une fois pour toutes.
     */
    function cfg() {
        return window.rkConfig || {};
    }

    var $modal, $msg, $program, $course, $session, $child, $save, $ssa, $appt;
    var $apptText, $hoursLeftBadge, $hoursLeftText;
    var currentRow = 0;
    var loading    = false;

    // AJOUT (demande utilisateur) — vues édition/reprogrammation, mises en
    // cache ICI (portée séparée de booking-reschedule.js, qui cache les
    // mêmes éléments DOM pour ses propres besoins) pour que
    // openCancelModal()/closeCancelModal() ci-dessous puissent basculer
    // depuis N'IMPORTE QUELLE vue vers celle d'annulation, sans dépendre
    // de variables privées d'un autre fichier.
    var $editView, $editActions, $editTitle, $editTopbar;
    var $rescheduleView, $rescheduleActions, $rescheduleTitle;

    function cacheDom() {
        $modal   = $('#rk-edit-booking-modal');
        $msg     = $('#rk-edit-msg');
        $program = $('#rk-edit-program');
        $course  = $('#rk-edit-course');
        $session = $('#rk-edit-session');
        $child   = $('#rk-edit-child');
        $save    = $('#rk-edit-save');
        $ssa     = $('#rk-edit-ssa');
        $appt    = $('#rk-edit-appointment');

        // AJOUT (rapport design — modale 1, section 1.1/1.3) — bandeau
        // date/heures restantes désormais scindé en deux éléments séparés
        // (voir Dashboard::render_edit_modal()), au lieu d'un texte
        // concaténé unique dans $appt.
        $apptText       = $('#rk-edit-appointment-text');
        $hoursLeftBadge = $('#rk-edit-hours-left');
        $hoursLeftText  = $('#rk-edit-hours-left-text');

        $editView          = $('#rk-edit-fields-view');
        $editActions       = $('#rk-edit-actions-view');
        $editTitle         = $('#rk-edit-modal-title');
        $editTopbar        = $('#rk-edit-topbar-view');
        $rescheduleView    = $('#rk-reschedule-fields-view');
        $rescheduleActions = $('#rk-reschedule-actions-view');
        $rescheduleTitle   = $('#rk-reschedule-modal-title');
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

    /** Remplit un <select>. `placeholder` s'affiche quand la liste est vide. */
    function fill($sel, items, selectedValue, placeholder, valueKey) {
        var key = valueKey || 'id';
        $sel.empty();

        if (!items || !items.length) {
            $sel.append($('<option>', { value: '', text: placeholder }));
            $sel.prop('disabled', true);
            return;
        }

        $sel.prop('disabled', false);
        $sel.append($('<option>', { value: '', text: placeholder }));

        $.each(items, function (i, it) {
            $sel.append($('<option>', {
                value: it[key],
                text:  it.label
            }));
        });

        if (selectedValue !== undefined && selectedValue !== null && selectedValue !== '') {
            $sel.val(String(selectedValue));
            // Valeur absente de la liste (élément supprimé depuis la réservation)
            if ($sel.val() === null) {
                $sel.prepend($('<option>', {
                    value: String(selectedValue),
                    text:  '— (غير متاح حالياً)'
                })).val(String(selectedValue));
            }
        }
    }

    function post(action, data) {
        var c = cfg();
        return $.post(
            c.ajaxUrl || '/wp-admin/admin-ajax.php',
            $.extend({ action: action, nonce: c.nonce || '' }, data)
        );
    }

    /** Message d'erreur exploitable : le générique masquait la vraie cause. */
    function ajaxError(xhr, fallback) {
        try {
            var r = JSON.parse(xhr.responseText);
            if (r && r.data && r.data.msg) return r.data.msg;
        } catch (e) {}

        if (xhr.status === 403) return 'انتهت صلاحية الصفحة — أعد تحميلها';
        if (xhr.status === 401) return 'يجب تسجيل الدخول';
        if (xhr.status === 0)   return 'تعذر الاتصال بالخادم';
        return fallback + ' (HTTP ' + xhr.status + ')';
    }

    /* ── Ouverture ─────────────────────────────────────────────── */

    function openModal(rowId, ssaUrl) {
        if (loading) return;
        loading   = true;
        currentRow = rowId;

        clearMsg();
        $modal.prop('hidden', false);
        $save.prop('disabled', true).text('جاري التحميل...');

        // FIX (bug signalé — "modifier date → revenir à la liste (bouton
        // ou fermeture) → recliquer تعديل SANS refresh de page" affiche
        // encore l'ancien contenu + le succès empilés) — ce chemin ne
        // passe PAS toujours par closeModal() (ex: "العودة إلى اللقاءات"
        // peut simplement rappeler showEditView() sans jamais repasser
        // $modal.hidden à true, donc le MutationObserver qui gère le
        // reset ne se déclenche jamais). openModal() est le SEUL point
        // de passage garanti à chaque réouverture : on y force donc
        // explicitement et INCONDITIONNELLEMENT le retour à l'état
        // initial de TOUTES les vues secondaires (reprogrammation,
        // succès reprogrammation, annulation, succès annulation) avant
        // même de commencer à charger quoi que ce soit — sans attendre
        // aucun événement de fermeture.
        if (window.RKBookingReschedule && typeof window.RKBookingReschedule.reset === 'function') {
            window.RKBookingReschedule.reset();
        }
        cancelRow = 0;
        if ($cancelTitle) $cancelTitle.prop('hidden', true);
        if ($cancelFieldsView) $cancelFieldsView.prop('hidden', true);
        if ($cancelActionsView) $cancelActionsView.prop('hidden', true);
        if ($cancelSuccessTitle) $cancelSuccessTitle.prop('hidden', true);
        if ($cancelSuccessView) $cancelSuccessView.prop('hidden', true);
        if ($cancelSuccessActions) $cancelSuccessActions.prop('hidden', true);
        if ($cancelAppt) $cancelAppt.text('');
        if ($editView) $editView.prop('hidden', false);
        if ($editActions) $editActions.prop('hidden', false);
        if ($editTitle) $editTitle.prop('hidden', false);
        if ($editTopbar) $editTopbar.prop('hidden', false);

        /*
         * Affichage immédiat des valeurs ACTUELLES lues dans la ligne du
         * tableau : l'utilisateur voit son choix courant sans attendre le
         * serveur, et garde un repère si la requête échoue.
         */
        var $row = $('[data-rk-row="' + rowId + '"]');
        if ($row.length) {
            fill($program, [{ id: '', label: $row.find('[data-rk-field="program"]').text().trim() }], '', '...');
            fill($course,  [{ id: '', label: $row.find('[data-rk-field="course"]').text().trim() }],  '', '...');
            fill($session, [{ id: '', label: $row.find('[data-rk-field="session"]').text().trim() }], '', '...');
            fill($child,   [{ id: '', label: $row.find('[data-rk-field="child"]').text().trim() }],   '', '...');
        }

        /*
         * Bouton « تغيير الموعد » : écran SSA de modification de date/heure
         *   /?appointment_action=edit&appointment_token=…
         * Masqué si le jeton est introuvable — un lien mort serait pire
         * qu'une absence de bouton.
         */
        if (ssaUrl) {
            $ssa.attr('href', ssaUrl).prop('hidden', false);
        } else {
            $ssa.prop('hidden', true);
        }

        post('rk_get_booking_edit', { row_id: rowId })
            .done(function (resp) {
                if (!resp || !resp.success) {
                    showMsg((resp && resp.data && resp.data.msg) || 'تعذر تحميل بيانات الحجز', true);
                    return;
                }

                var d = resp.data;
                var b = d.booking;

                // AJUSTEMENT (rapport design — modale 1, section 1.1/1.3) —
                // date et compte à rebours désormais dans deux éléments
                // séparés (icône déjà posée en dur côté PHP, voir
                // Dashboard::render_edit_modal()), au lieu d'un texte
                // concaténé unique.
                $apptText.text(b.appointment || '');

                if (b.hours_left) {
                    $hoursLeftText.text('متبقي ' + b.hours_left + ' ساعة للتعديل');
                    $hoursLeftBadge.prop('hidden', false);
                } else {
                    $hoursLeftBadge.prop('hidden', true);
                }

                fill($program, d.programs, b.program_id, 'اختر البرنامج');
                fill($course,  d.courses,  b.course_id,  d.courses.length ? 'اختر الدورة' : 'لا توجد دورات');
                // La séance est identifiée par son LIBELLÉ (colonne session_name),
                // pas par un ID : la table ne stocke pas session_id.
                fill($session, d.sessions, b.session_name, d.sessions.length ? 'اختر اللقاء' : 'لا توجد لقاءات', 'label');
                fill($child,   d.children, b.child_id,   'اختر الطفل');

                $save.prop('disabled', false).text('حفظ التعديلات');
            })
            .fail(function (xhr) {
                showMsg(ajaxError(xhr, 'تعذر تحميل بيانات الحجز'), true);
            })
            .always(function () {
                loading = false;
                if ($save.text() === 'جاري التحميل...') {
                    $save.prop('disabled', false).text('حفظ التعديلات');
                }
            });
    }

    function closeModal() {
        $modal.prop('hidden', true);
        currentRow = 0;
        clearMsg();

        // AJUSTEMENT (demande utilisateur — "après la fermeture de popup
        // tout les données et champs de date doit disparu, reste que la
        // partie 1 par défaut visible") — vide les 4 select de la vue
        // édition (البرنامج/الدورة/اللقاء/الطفل) et le bandeau date/
        // compte à rebours, pour ne jamais laisser les valeurs de la
        // réservation précédente visibles à la prochaine ouverture avant
        // que le rechargement AJAX ne les remplace.
        $program.empty();
        $course.empty();
        $session.empty();
        $child.empty();
        if ($apptText) $apptText.text('');
        if ($hoursLeftBadge) $hoursLeftBadge.prop('hidden', true);
        if ($hoursLeftText) $hoursLeftText.text('');

        // Même réinitialisation complète côté vue reprogrammation (type
        // choisi, calendrier, créneaux, champ التاريخ) — voir
        // booking-reschedule.js::resetRescheduleState().
        if (window.RKBookingReschedule && typeof window.RKBookingReschedule.reset === 'function') {
            window.RKBookingReschedule.reset();
        }

        // FIX (bug signalé — capture fournie montrant l'état attendu à
        // l'ouverture) — si la modale se ferme pendant que la vue
        // annulation OU succès annulation était active (Échap, clic sur
        // le fond), la remettre à zéro pour que la prochaine ouverture
        // retombe TOUJOURS sur la vue édition par défaut — même logique
        // que le MutationObserver de booking-reschedule.js pour sa
        // propre vue. AJUSTEMENT — appelle désormais les vraies
        // fonctions dédiées (closeCancelModal()/closeCancelSuccessModal())
        // au lieu de dupliquer partiellement leur logique : le code
        // précédent masquait bien la vue succès annulation mais
        // oubliait de RÉAFFICHER la vue édition dans ce cas précis (seul
        // closeCancelModal() le faisait), laissant la modale sur un état
        // où AUCUNE vue n'était visible à la réouverture suivante.
        // AJUSTEMENT (demande utilisateur — "pour chaque fermeture de
        // popup le contenu doit revenir vers l'état initial et les
        // champs de success message doivent être cachés") — masquage
        // FORCÉ et INCONDITIONNEL des 2 vues annulation/succès-annulation
        // à chaque fermeture, quel que soit leur état courant. Avant, le
        // masquage dépendait d'un test sur .prop('hidden') qui pouvait
        // rater un cas limite ; on force maintenant systématiquement le
        // même état final, sans condition.
        cancelRow = 0;
        if ($cancelTitle) $cancelTitle.prop('hidden', true);
        if ($cancelFieldsView) $cancelFieldsView.prop('hidden', true);
        if ($cancelActionsView) $cancelActionsView.prop('hidden', true);
        if ($cancelSuccessTitle) $cancelSuccessTitle.prop('hidden', true);
        if ($cancelSuccessView) $cancelSuccessView.prop('hidden', true);
        if ($cancelSuccessActions) $cancelSuccessActions.prop('hidden', true);

        // Vue édition systématiquement réaffichée en dessous — état
        // initial garanti, peu importe la vue active au moment de fermer.
        if ($editView) $editView.prop('hidden', false);
        if ($editActions) $editActions.prop('hidden', false);
        if ($editTitle) $editTitle.prop('hidden', false);
        if ($editTopbar) $editTopbar.prop('hidden', false);

        // AJUSTEMENT — texte narratif de la confirmation d'annulation
        // (déjà rempli par openCancelModal()) vidé aussi, même
        // principe que les select ci-dessus.
        if ($cancelAppt) $cancelAppt.text('');
    }

    /* ── Chaînage des listes ───────────────────────────────────── */

    function loadCoursesFor(programId) {
        post('rk_booking_edit_courses', { program_id: programId })
            .done(function (resp) {
                if (resp && resp.success) {
                    fill($course, resp.data.courses, '',
                         resp.data.courses.length ? 'اختر الدورة' : 'لا توجد دورات');
                } else {
                    fill($course, [], '', 'لا توجد دورات');
                }
            })
            .fail(function () {
                fill($course, [], '', 'تعذر التحميل');
            });
    }

    function reloadSessions() {
        var cid = parseInt($course.val(), 10) || 0;

        fill($session, [], '', 'جاري التحميل...');

        if (!cid) {
            fill($session, [], '', 'اختر الدورة أولاً');
            return;
        }

        post('rk_booking_edit_sessions', { course_id: cid })
            .done(function (resp) {
                if (resp && resp.success) {
                    fill($session, resp.data.sessions, '',
                         resp.data.sessions.length ? 'اختر اللقاء' : 'لا توجد لقاءات', 'label');
                } else {
                    fill($session, [], '', 'لا توجد لقاءات');
                }
            })
            .fail(function () {
                fill($session, [], '', 'تعذر التحميل');
            });
    }

    /* ── Enregistrement ────────────────────────────────────────── */

    function save() {
        if (!currentRow) return;

        clearMsg();
        $save.prop('disabled', true).text('جاري الحفظ...');

        post('rk_update_booking', {
            row_id:       currentRow,
            program_id:   parseInt($program.val(), 10) || 0,
            course_id:    parseInt($course.val(), 10) || 0,
            session_name: $session.val() || '',
            child_id:     parseInt($child.val(), 10) || 0
        })
            .done(function (resp) {
                if (!resp || !resp.success) {
                    showMsg((resp && resp.data && resp.data.msg) || 'تعذر حفظ التعديلات', true);
                    return;
                }

                showMsg(resp.data.msg || 'تم الحفظ', false);

                // Mise à jour de la carte sans recharger la page
                var $row = $('[data-rk-row="' + currentRow + '"]');
                if ($row.length && resp.data.labels) {
                    var L = resp.data.labels;
                    $row.find('[data-rk-field="program"]').text(L.program);
                    $row.find('[data-rk-field="course"]').text(L.course);
                    // L'élément [data-rk-field="session"] est déjà une balise <strong> dans le
                    // markup de la carte : pas besoin d'en réinjecter une (évite <strong><strong>).
                    $row.find('[data-rk-field="session"]').text(L.session);
                    $row.find('[data-rk-field="child"]').text(L.child);
                }

                // FIX (bug signalé — filtre "الطفل" pas mis à jour
                // instantanément après changement d'enfant) — le texte
                // affiché ci-dessus (data-rk-field="child") ne suffit
                // pas : booking-history-filters.js::applyFilters() lit
                // l'attribut data-rk-child-id sur la carte elle-même
                // (voir Dashboard::render_booking_card()), jamais mis à
                // jour jusqu'ici. Réapplique ensuite les filtres pour que
                // la carte apparaisse/disparaisse immédiatement si un
                // filtre enfant est actif sur l'ancien ou le nouveau
                // enfant.
                if ($row.length && resp.data.child_id) {
                    $row.attr('data-rk-child-id', resp.data.child_id);
                }
                if (window.RKBookingFilters && typeof window.RKBookingFilters.refresh === 'function') {
                    window.RKBookingFilters.refresh();
                }

                setTimeout(closeModal, 1200);
            })
            .fail(function (xhr) {
                showMsg(ajaxError(xhr, 'تعذر حفظ التعديلات'), true);
            })
            .always(function () {
                $save.prop('disabled', false).text('حفظ التعديلات');
            });
    }

    /* ── Init ──────────────────────────────────────────────────── */

    /**
     * AJOUT — API publique minimale pour booking-reschedule.js (pile
     * indépendante, voir sa doc). Expose uniquement ce qui est
     * nécessaire : le rowId de la modale d'édition actuellement ouverte
     * (pour que la modale de reprogrammation sache quelle réservation
     * traiter) et un moyen de la recharger après une reprogrammation
     * réussie — jamais un accès direct aux variables privées ci-dessus.
     */
    window.RKBookingEdit = {
        currentRow: function () { return currentRow; },
        reload: function (rowId) {
            var ssaUrlAttr = $('.rk-open-edit-modal[data-row="' + rowId + '"]').data('ssa-url') || '';
            openModal(rowId, ssaUrlAttr);
        }
    };

    /**
     * AJOUT (demande utilisateur — bouton "إلغاء" sur la carte) — modale
     * de confirmation dédiée. AJUSTEMENT (demande utilisateur) — fusionnée
     * comme 3e vue de la modale unifiée #rk-edit-booking-modal (plus une
     * modale séparée #rk-cancel-booking-modal, qui s'affichait mal
     * positionnée en fin de page) — même architecture que la vue
     * reprogrammation de booking-reschedule.js : titre/contenu/footer
     * basculés, jamais une deuxième fenêtre modale ouverte par-dessus.
     */
    var $cancelFieldsView, $cancelActionsView, $cancelTitle, $cancelAppt, $cancelConfirmBtn;
    var cancelRow = 0;

    function cacheCancelDom() {
        $cancelFieldsView  = $('#rk-cancel-fields-view');
        $cancelActionsView = $('#rk-cancel-actions-view');
        $cancelTitle       = $('#rk-cancel-modal-title');
        $cancelAppt        = $('#rk-cancel-appointment');
        $cancelConfirmBtn  = $('#rk-cancel-confirm');
    }

    // AJUSTEMENT — #rk-cancel-msg n'existe plus séparément (voir Dashboard::
    // render_edit_modal()) : les messages d'erreur de l'annulation
    // s'affichent désormais dans le même #rk-edit-msg que les autres vues
    // (showMsg/clearMsg déjà définis plus haut dans ce fichier).

    function openCancelModal(rowId) {
        if (!$cancelFieldsView.length) {
            if (window.console) {
                console.error('[rk-booking-edit] #rk-cancel-fields-view introuvable dans le DOM — la vue d\'annulation ne peut pas s\'ouvrir.');
            }
            return;
        }

        cancelRow = rowId;
        clearMsg();
        $cancelConfirmBtn.prop('disabled', false).text('تأكيد الإلغاء');

        // AJUSTEMENT (demande utilisateur, conforme à la maquette Figma) —
        // texte narratif complet ("سيتم إلغاء لقاء <séance> المقرر يوم
        // <date>.") avec la séance et la date en gras, au lieu du résumé
        // brut "cours — séance" précédent. Toujours lu directement depuis
        // la carte (pas d'appel réseau, voir doc ci-dessus).
        var $row = $('[data-rk-row="' + rowId + '"]');
        if ($row.length) {
            var session = $row.find('[data-rk-field="session"]').text().trim();
            var date    = $row.find('[data-rk-field="date"]').text().trim();

            var html = 'سيتم إلغاء لقاء ';
            if (session) html += '<strong>' + $('<span>').text(session).html() + '</strong>';
            html += ' المقرر ';
            if (date) html += '<strong>' + $('<span>').text(date).html() + '</strong>';
            html += '.';

            $cancelAppt.html(html);
        } else {
            $cancelAppt.text('');
        }

        // Bascule de vue — masque édition/reprogrammation, affiche
        // annulation. Réutilise les variables exposées par
        // booking-reschedule.js (chargé avant ce fichier, voir Assets.php)
        // pour ne pas dupliquer les caches DOM des vues déjà existantes.
        if ($editView) $editView.prop('hidden', true);
        if ($editActions) $editActions.prop('hidden', true);
        if ($editTitle) $editTitle.prop('hidden', true);
        if ($editTopbar) $editTopbar.prop('hidden', true);
        if ($rescheduleView) $rescheduleView.prop('hidden', true);
        if ($rescheduleActions) $rescheduleActions.prop('hidden', true);
        if ($rescheduleTitle) $rescheduleTitle.prop('hidden', true);

        $cancelTitle.prop('hidden', false);
        $cancelFieldsView.prop('hidden', false);
        $cancelActionsView.prop('hidden', false);
    }

    function closeCancelModal() {
        cancelRow = 0;
        clearMsg();

        $cancelTitle.prop('hidden', true);
        $cancelFieldsView.prop('hidden', true);
        $cancelActionsView.prop('hidden', true);

        // Retour à la vue édition par défaut (comme showEditView() dans
        // booking-reschedule.js) — cohérent que le déclencheur d'origine
        // soit la carte (rk-open-cancel-modal) ou le bouton "إلغاء" du
        // footer d'édition (rk-edit-cancel).
        if ($editView) $editView.prop('hidden', false);
        if ($editActions) $editActions.prop('hidden', false);
        if ($editTitle) $editTitle.prop('hidden', false);
        if ($editTopbar) $editTopbar.prop('hidden', false);
    }

    /**
     * AJOUT — écran de succès « تم إلغاء اللقاء » (Figma), affiché après une
     * annulation réussie. `refunded` reflète la vraie règle métier
     * appliquée côté serveur (voir BookingEditAjax::ajax_cancel(), champ
     * resp.data.refunded) — jamais supposée ici.
     */
    var $cancelSuccessView, $cancelSuccessTitle, $cancelSuccessActions, $cancelSuccessSubtitle, $cancelSuccessRebook;

    function cacheCancelSuccessDom() {
        $cancelSuccessView     = $('#rk-cancel-success-view');
        $cancelSuccessTitle    = $('#rk-cancel-success-title');
        $cancelSuccessActions  = $('#rk-cancel-success-actions-view');
        $cancelSuccessSubtitle = $('#rk-cancel-success-subtitle');
        $cancelSuccessRebook   = $('#rk-cancel-success-rebook');
    }

    function showCancelSuccessModal(refunded) {
        if (!$cancelSuccessView.length) return;

        $cancelSuccessSubtitle.text(
            refunded
                ? 'تم استرداد الرصيد إلى حسابك تلقائيًا'
                : 'تم إلغاء الحجز'
        );

        $cancelTitle.prop('hidden', true);
        $cancelFieldsView.prop('hidden', true);
        $cancelActionsView.prop('hidden', true);

        $cancelSuccessTitle.prop('hidden', false);
        $cancelSuccessView.prop('hidden', false);
        $cancelSuccessActions.prop('hidden', false);
    }

    function closeCancelSuccessModal() {
        cancelRow = 0;

        $cancelSuccessTitle.prop('hidden', true);
        $cancelSuccessView.prop('hidden', true);
        $cancelSuccessActions.prop('hidden', true);

        if ($editView) $editView.prop('hidden', false);
        if ($editActions) $editActions.prop('hidden', false);
        if ($editTitle) $editTitle.prop('hidden', false);
        if ($editTopbar) $editTopbar.prop('hidden', false);
    }

    function confirmCancel() {
        if (!cancelRow) return;

        clearMsg();
        $cancelConfirmBtn.prop('disabled', true).text('جاري الإلغاء...');

        post('rk_booking_cancel', { row_id: cancelRow })
            .done(function (resp) {
                if (!resp || !resp.success) {
                    showMsg((resp && resp.data && resp.data.msg) || 'تعذر إلغاء الحجز', true);
                    $cancelConfirmBtn.prop('disabled', false).text('تأكيد الإلغاء');
                    return;
                }

                // AJUSTEMENT (demande utilisateur) — reflète l'annulation
                // COMPLETEMENT sur la carte sans recharger la page : badge
                // de statut (couleur/texte/icone) ET bouton de pied de
                // carte mis a jour, pas seulement l'opacite - meme rendu
                // exact que le serveur produirait pour un booking dont le
                // statut est 'cancelled' (voir Dashboard::
                // render_booking_card(), meme structure HTML reproduite
                // ici pour rester synchronisee si ce gabarit change).
                var $row = $('[data-rk-row="' + cancelRow + '"]');
                if ($row.length) {
                    var icons = (window.rkConfig && rkConfig.icons) || {};

                    $row.addClass('rk-booking-card--muted');
                    // AJOUT (demande utilisateur — filtre par statut) —
                    // reflète l'annulation sur l'attribut utilisé par
                    // booking-history-filters.js (matchStatus), pas
                    // seulement le badge visuel ci-dessous.
                    $row.attr('data-rk-status', 'cancelled');

                    var $badge = $row.find('.rk-course-status-badge');
                    if ($badge.length) {
                        // AJUSTEMENT - icons[...] est toujours genere en
                        // 16px cote serveur (Icons::for_js()), alors que le
                        // badge attend 13px (voir Dashboard::
                        // render_booking_card()) : la taille est corrigee
                        // ici pour rester visuellement identique a une
                        // carte rendue directement par le serveur.
                        var $badgeIcon = $('<span>').html(icons['x-circle'] || '').find('svg')
                            .attr('width', 13).attr('height', 13);
                        $badge
                            .removeClass('is-confirmed')
                            .addClass('is-cancelled')
                            .empty()
                            .append($badgeIcon)
                            .append(document.createTextNode(' ملغى'));
                    }

                    $row.find('.rk-btn-edit-booking, .rk-btn-cancel-booking, .rk-bc-locked-note').remove();
                    if (!$row.find('.rk-btn-rebook').length) {
                        var $rebookIcon = $('<span>').html(icons['check'] || '').find('svg')
                            .attr('width', 12).attr('height', 12);
                        var $rebookBtn = $('<button>', {
                            type: 'button',
                            'class': 'rk-btn-rebook rk-open-rebook-form'
                        }).append($rebookIcon).append(document.createTextNode(' حجز مجدد'));
                        $row.find('.rk-bc-footer').empty().append($rebookBtn);
                    }
                }

                showCancelSuccessModal(resp.data.refunded);
            })
            .fail(function (xhr) {
                showMsg(ajaxError(xhr, 'تعذر إلغاء الحجز'), true);
                $cancelConfirmBtn.prop('disabled', false).text('تأكيد الإلغاء');
            });
    }

    $(function () {
        cacheDom();
        cacheCancelDom();
        cacheCancelSuccessDom();

        /*
         * FIX — protection contre un script tiers qui aurait retiré
         * l'attribut `hidden` avant le chargement de ce fichier (ex : un
         * autre plugin ciblant une classe générique similaire, ou tout
         * autre effet de bord externe au wizard de réservation).
         *
         * Sans ce garde-fou, la modale pouvait rester affichée en plein
         * écran (position:fixed; inset:0; z-index:99999) au chargement de
         * la page, invisible à l'œil mais absorbant TOUS les clics du
         * DOM — y compris ceux sur les boutons "تعديل" des cartes situées
         * en dessous. On force donc systématiquement l'état fermé au
         * DOM-ready, indépendamment de ce que le PHP ou un tiers a produit.
         */
        if ($modal.length) {
            $modal.prop('hidden', true);
        }

        /*
         * FIX — bouton "تعديل" totalement inerte, sans erreur console.
         *
         * Avant : `if (!$modal.length) return;` coupait TOUT le reste de
         * cette fonction — y compris le binding du clic sur
         * .rk-open-edit-modal — si #rk-edit-booking-modal n'était pas
         * encore présent dans le DOM à cet instant précis. Le binding du
         * clic est maintenant TOUJOURS actif ; seule openModal() vérifie
         * la présence de la modale, avec un message clair en console au
         * lieu d'un échec totalement muet.
         */
        $(document).on('click', '.rk-open-edit-modal', function (e) {
            e.preventDefault();
            if (!$modal.length) {
                if (window.console) {
                    console.error('[rk-booking-edit] #rk-edit-booking-modal introuvable dans le DOM — la modale ne peut pas s\'ouvrir.');
                }
                return;
            }
            openModal(parseInt($(this).data('row'), 10) || 0, $(this).data('ssa-url') || '');
        });

        // FIX (bug signalé) — "إلغاء" dans le footer de la modale d'édition
        // fermait toute la popup (comme "annuler l'action en cours"), au
        // lieu de basculer vers la VRAIE vue d'annulation SSA (voir
        // openCancelModal() ci-dessus, désormais une bascule de vue —
        // AJUSTEMENT demande utilisateur — plus l'ouverture d'une modale
        // séparée qui s'affichait mal positionnée en fin de page).
        $('#rk-edit-modal-close').on('click', closeModal);
        $('#rk-edit-cancel').on('click', function (e) {
            e.preventDefault();
            openCancelModal(currentRow);
        });

        // Clic sur le fond (pas sur la carte) → fermeture
        $modal.on('click', function (e) {
            if (e.target === this) closeModal();
        });

        // AJUSTEMENT (demande utilisateur) — déclenché depuis la carte
        // (bouton إلغاء direct, hors modale) : ouvre désormais la MÊME
        // modale unifiée que l'édition/reprogrammation, directement sur
        // sa vue annulation — plus une modale #rk-cancel-booking-modal
        // séparée.
        $(document).on('click', '.rk-open-cancel-modal', function (e) {
            e.preventDefault();
            if (!$modal.length) {
                if (window.console) {
                    console.error('[rk-booking-edit] #rk-edit-booking-modal introuvable dans le DOM — la vue d\'annulation ne peut pas s\'ouvrir.');
                }
                return;
            }
            var rowId = parseInt($(this).data('row'), 10) || 0;
            currentRow = rowId;
            $modal.prop('hidden', false);
            openCancelModal(rowId);
        });

        $('#rk-cancel-dismiss').on('click', closeCancelModal);
        $cancelConfirmBtn.on('click', confirmCancel);

        // ── Vue succès d'annulation (تم إلغاء اللقاء) ──────────────
        // AJUSTEMENT (demande utilisateur) — "العودة إلى اللقاءات" ferme
        // désormais toute la modale (fin du flow).
        $('#rk-cancel-success-close').on('click', function () {
            closeCancelSuccessModal();
            closeModal();
        });

        $cancelSuccessRebook.on('click', function (e) {
            e.preventDefault();
            closeCancelSuccessModal();
            closeModal();
            $('.rk-booking-view-tab[data-rk-view="form"]').trigger('click');
        });

        $(document).on('keydown', function (e) {
            if (e.key !== 'Escape') return;
            if (!$modal.prop('hidden')) closeModal();
        });

        $program.on('change', function () { loadCoursesFor(parseInt($(this).val(), 10) || 0); reloadSessions(); });
        $course.on('change', reloadSessions);

        $save.on('click', save);
    });

})(jQuery);