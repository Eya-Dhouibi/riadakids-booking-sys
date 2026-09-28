/**
 * RiadaKids Booking Wizard — assets/js/booking-confirmation.js — Module 10 : Confirmation
 *
 * Responsabilité : confirmation finale (étape 6), affichage succès/erreur.
 *
 * v7.0 — Flux 4-états SSA (voir booking-ssa-overlay.js / booking-ssa.js) :
 * le bouton #rk-confirm-booking NE crée PLUS de booking RiadaKids. SSA reste
 * l'unique source de vérité pour la création du rendez-vous : ce bouton ne
 * fait que déclencher le clic natif SSA (déjà préparé/autofillé en amont)
 * puis attendre la confirmation serveur (webhook/hook SSA → confirm_from_ssa
 * → statut RiadaKids "confirmed" + déduction crédits), avant d'afficher
 * l'écran de succès (Capture 4). Aucun appel rk_create_booking ici — ce
 * endpoint AJAX legacy a été retiré (voir BookingAjax.php).
 *
 * Note v6.0 : RKPending (reprise de réservation incomplète) supprimé.
 * Le formulaire démarre toujours comme une nouvelle réservation.
 *
 * Dépendances : jQuery, booking-state.js, booking-utils.js, booking-ssa.js,
 * booking-ssa-overlay.js
 */
(function ($) {
    'use strict';

    window.RKConfirmation = {

        _confirming: false, // anti-double-clic (voir bindConfirmButton)
        // Message affiché uniquement si SSA a réellement signalé un échec
        // (rk_ssa_booking_failed) sans fournir son propre texte d'erreur.
        $defaultBookingError: 'تعذر إتمام الحجز، يرجى إعادة اختيار الموعد.',
        // CORRECTION (bug signalé, v2) — le timeout fixe (15s puis 30s)
        // affichait "ce créneau n'est plus disponible" alors que SSA
        // finissait par confirmer juste après : SSA n'impose lui-même
        // AUCUNE limite de temps sur handleSaveAppointment() (voir
        // booking-app-new/dist/static/js/app.js) — il attend simplement
        // son propre résultat (succès → postMessage ssaType:'appointment',
        // ou échec → this.error/this.errorMessage affichés dans l'iframe).
        // Un timeout arbitraire côté RiadaKids ne peut donc jamais être
        // "juste assez long" : il produit un faux négatif dès que le
        // réseau ou le serveur est un peu plus lent que prévu ce jour-là.
        // On adopte le même comportement que SSA : plus de délai fixe qui
        // invente un échec. Le seul chemin d'échec restant est le vrai
        // signal d'erreur SSA relayé via rk_ssa_booking_failed (voir
        // bindServerConfirmed ci-dessous et booking-ssa-overlay.js::
        // _bindErrorObserver).

        init: function () {
            this.bindConfirmButton();
            this.bindServerConfirmed();
            this.bindSuccessActions();
        },

        /* ── Bouton "تأكيد الحجز" (étape 6) ──────────────────────────────
         * Ne crée rien côté RiadaKids. Déclenche le clic natif SSA (une
         * seule fois — protégé à la fois ici et dans
         * RKSSAOverlay.submitNativeBooking) puis attend l'event natif SSA
         * ssa_appointment_booked (voir booking-ssa.js) qui lance lui-même
         * le polling déjà existant. C'est bindServerConfirmed() ci-dessous
         * qui affiche le succès une fois le serveur RiadaKids confirmé.
         */
        bindConfirmButton: function () {
            const self = this;

            $(document).on('click', '#rk-confirm-booking', function () {
                if (self._confirming) return; // anti-double-clic

                const $name    = $('#rk-customer-name');
                const $email   = $('#rk-customer-email');
                const name     = ($name.val()  || '').trim();
                const email    = ($email.val() || '').trim();
                const $error   = $('#rk-customer-fields-error');

                // Validation des champs VISIBLES avant toute soumission SSA —
                // aucun submit ne part si nom/email sont invalides.
                const emailValid = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
                if (!name || !emailValid) {
                    $name.toggleClass('rk-field-invalid', !name);
                    $email.toggleClass('rk-field-invalid', !emailValid);
                    $error.text('يرجى إدخال اسم صحيح وبريد إلكتروني صالح.').show();
                    return;
                }
                $name.removeClass('rk-field-invalid');
                $email.removeClass('rk-field-invalid');
                $error.hide();

                self._confirming = true;

                const $btn = $(this);
                $btn.prop('disabled', true).text('جاري تأكيد الحجز…');

                // Répercute les valeurs (éventuellement modifiées par
                // l'utilisateur) dans le formulaire SSA caché AVANT de
                // déclencher le vrai clic natif — jamais avant ce point.
                const synced = window.RKSSAOverlay &&
                    typeof window.RKSSAOverlay.applyCustomerFields === 'function' &&
                    window.RKSSAOverlay.applyCustomerFields(name, email);

                const triggered = synced &&
                    typeof window.RKSSAOverlay.submitNativeBooking === 'function' &&
                    window.RKSSAOverlay.submitNativeBooking();

                if (!triggered) {
                    self._confirming = false;
                    self.showError('تعذر إتمام الحجز، يرجى إعادة اختيار الموعد.');
                    $btn.prop('disabled', false).text('تأكيد الحجز');
                    return;
                }
                // Si déclenché : on attend ssa_appointment_booked → polling
                // → rk_booking_confirmed_server (voir bindServerConfirmed).
                // CORRECTION (v2) — aucun timeout fixe n'affiche plus
                // d'erreur ici : on attend le vrai résultat SSA, comme SSA
                // lui-même le fait. bindSsaAppointmentEvent() (booking-ssa.js)
                // écoute déjà l'échec réel de SSA — voir handleBookingError()
                // dans le bundle SSA, qui set this.error/this.errorMessage
                // dans l'iframe sans jamais notifier la page parente d'un
                // échec par postMessage. Faute d'un tel signal d'échec côté
                // SSA, RKSSAOverlay observe cet état d'erreur directement
                // dans le DOM iframe (voir booking-ssa-overlay.js::
                // _bindErrorObserver) et déclenche rk_ssa_booking_failed —
                // c'est le seul événement qui peut désormais faire échouer
                // cet écran, plus jamais une horloge locale.
            });
        },

        /**
         * Déclenché par booking-ssa.js une fois que le backend RiadaKids a
         * détecté (via webhook/hook SSA) que le rendez-vous est confirmé et
         * les crédits déduits. C'est le SEUL déclencheur de l'écran succès.
         */
        bindServerConfirmed: function () {
            const self = this;
            $(document).on('rk_booking_confirmed_server', function (event, data) {
                const s = window.RKBookingState;
                if (data && data.booking_uuid) s.booking_uuid = data.booking_uuid;

                // AJOUT (demande utilisateur) — insère la carte de la
                // réservation qui vient d'être confirmée dans l'onglet
                // "اللقاءات المحجوزة" INSTANTANÉMENT, AVANT même d'afficher
                // le popup succès (voir showSuccess plus bas) : la carte
                // est donc déjà présente le tout premier instant où
                // l'utilisateur clique "عرض لقاءاتي" (bindSuccessActions),
                // sans le moindre refresh de page.
                //
                // CORRECTION (bug signalé — carte visible seulement après
                // refresh) — la première version de cette fonctionnalité
                // faisait un aller-retour AJAX vers un endpoint dédié
                // (rk_get_booking_card) pour obtenir le HTML de la carte
                // depuis le serveur. Ce round-trip introduisait plusieurs
                // points de défaillance silencieux — nonce, cache d'objet
                // WordPress, réplication DB pas encore committée juste
                // après confirm_from_ssa(), échec réseau — dont chacun
                // laissait la carte invisible jusqu'au prochain vrai
                // rechargement, sans jamais remonter d'erreur visible à
                // l'utilisateur (voir _prependBookingCard v1, supprimée).
                //
                // Solution — TOUTES les données nécessaires à la carte
                // (programme, cours + son image, séance, enfant, date/heure)
                // sont déjà connues côté client à cet instant précis :
                // RKBookingState (rempli à chaque étape du wizard) et le
                // DOM de la carte cours sélectionnée à l'étape 2
                // (.rk-selected-card, injectée par RKPrograms.buildCourseCard()
                // — voir booking-programs.js, qui y pose déjà l'image et la
                // catégorie reçues du serveur). On construit donc le HTML
                // de la carte ENTIÈREMENT EN JS, sans aucun appel réseau :
                // zéro race condition possible, la carte apparaît au même
                // tick que la confirmation elle-même.
                self._prependBookingCard(data);

                self.showSuccess(s.booking_id);
            });
            // CORRECTION (v2) — le vrai échec SSA (créneau réellement pris
            // entre-temps, erreur serveur SSA) reste interne à l'iframe :
            // SSA ne poste jamais ce cas à la page parente (voir
            // handleBookingError() dans le bundle SSA). RKSSAOverlay
            // observe donc directement l'état d'erreur affiché par SSA
            // dans le DOM de l'iframe et déclenche rk_ssa_booking_failed
            // avec le message SSA d'origine — c'est désormais le seul
            // chemin d'échec de cet écran, aucune horloge locale.
            $(document).on('rk_ssa_booking_failed', function (event, data) {
                self._confirming = false;
                const message = (data && data.message) || self.$defaultBookingError;
                self.showError(message);
                $('#rk-confirm-booking').prop('disabled', false).text('تأكيد الحجز');
            });
            $(document).on('rk_ssa_appointment_booked', function () {
            });
        },

        /* ── Boutons de l'écran succès (Capture 4) ────────────────────────
         * "عرض مواعيدي" → bascule vers l'onglet "اللقاءات المحجوزة" déjà
         * existant sur la même page (RKViewSwitcher.showView('list'),
         * voir booking-view-switcher.js / Dashboard.php data-rk-view="list").
         * Pas de navigation ni de nouvelle URL — l'utilisateur choisit
         * cette action explicitement, aucun appel réseau créateur ici.
         *
         * "العودة" → réinitialise simplement le wizard sur l'étape 1 pour
         * permettre une nouvelle réservation, sans recharger la page.
         */
        bindSuccessActions: function () {
            $(document).on('click', '#rk-success-view-bookings', function () {
                self_removeSuccessModal();
                if (window.RKViewSwitcher && typeof window.RKViewSwitcher.showView === 'function') {
                    window.RKViewSwitcher.showView('list');
                }
            });

            $(document).on('click', '#rk-success-back', function () {
                self_removeSuccessModal();
                if (window.RiadaKidsWizard && window.RiadaKidsWizard.Navigation) {
                    window.RiadaKidsWizard.Navigation.goToStep(1);
                }
            });

            function self_removeSuccessModal() {
                $('#rk-success-modal').remove();
                // Le widget SSA (masqué dans showSuccess) redevient
                // pertinent pour une éventuelle nouvelle réservation :
                // sa visibilité normale est régie par onEnterStep5, qui
                // le réaffiche déjà via son propre flux quand l'étape 5
                // est ré-atteinte (aucune action supplémentaire requise
                // ici — on retire simplement le style inline posé plus
                // haut pour ne pas laisser une trace figée).
                $('#rk-ssa-widget').removeAttr('style');
            }
        },

        /**
         * Redimensionne une icône SVG (chaîne HTML issue de rkConfig.icons,
         * toujours générée à 16px côté PHP — voir Icons::for_js()) à la
         * taille exacte utilisée par Dashboard::render_booking_card() pour
         * la même icône (14px pour "enfant"/"heure", 13px pour le badge
         * statut "مؤكد"). Simple remplacement des attributs width/height —
         * le viewBox="0 0 24 24" et les tracés internes restent identiques,
         * donc le rendu visuel est pixel-identique à la carte serveur.
         */
        _resizeIcon: function (svgHtml, size) {
            if (!svgHtml) return '';
            return svgHtml
                .replace(/width="\d+"/, 'width="' + size + '"')
                .replace(/height="\d+"/, 'height="' + size + '"');
        },

        /**
         * AJOUT (demande utilisateur) — construit et insère la carte de la
         * réservation qui vient d'être confirmée, EN JS PUR à partir de
         * RKBookingState + du DOM de l'étape 2 (image/catégorie du cours
         * sélectionné) — aucun appel réseau (voir doc de bindServerConfirmed
         * ci-dessus pour le contexte de ce choix). Insérée dans
         * #rk-bookings-cards, avant toute autre carte (la plus récente en
         * premier — même ordre que Dashboard::render(), qui trie par
         * created_at DESC).
         *
         * Markup RIGOUREUSEMENT identique à Dashboard::render_booking_card()
         * (mêmes classes CSS, mêmes data-attributes, même structure DOM) —
         * toute divergence future entre les deux doit être répercutée des
         * deux côtés à la fois pour ne pas casser le CSS partagé.
         *
         * Statut affiché : toujours "مؤكد" (confirmed) — c'est le seul état
         * possible à ce stade précis (rk_booking_confirmed_server ne se
         * déclenche qu'après un statut serveur "confirmed" réel, voir
         * booking-ssa.js::_startPolling). Fenêtre d'annulation 24h : toujours
         * "ouverte" puisque la réservation vient d'être créée à l'instant.
         */
        _prependBookingCard: function (pollData) {
            const s = window.RKBookingState;
            const u = window.RiadaKidsWizard.Utils;
            const icons = (window.rkConfig && rkConfig.icons) || {};

            // AJOUT (bug signalé — bouton تعديل الحجز absent tant qu'aucun
            // rechargement de page n'a eu lieu) — row_id de wp_rk_bookings,
            // désormais renvoyé par rk_poll_booking (voir BookingAjax::
            // handle_poll_booking()) et propagé jusqu'ici via l'événement
            // rk_booking_confirmed_server (voir bindServerConfirmed).
            const rowId = (pollData && pollData.row_id) ? parseInt(pollData.row_id, 10) : 0;

            let $cards = $('#rk-bookings-cards');
            if (!$cards.length) {
                // La liste était vide au chargement de la page
                // (#rk-empty-bookings affiché à la place, voir
                // Dashboard::render_history()) : on recrée le conteneur
                // pour que cette première carte ait un endroit où
                // s'afficher, avec la classe exacte du markup serveur.
                $cards = $('<div class="rk-bookings-cards" id="rk-bookings-cards"></div>');
                const $empty = $('#rk-empty-bookings');
                if ($empty.length) {
                    $empty.replaceWith($cards);
                } else {
                    // Filet de sécurité — ne devrait normalement jamais
                    // arriver (l'un des deux conteneurs est toujours
                    // présent dans le markup initial de Dashboard.php).
                    $('.rk-bookings-history .rk-alert-info').after($cards);
                }
            }

            // Image + catégorie du cours : lues depuis la carte réellement
            // sélectionnée par l'utilisateur à l'étape 2 (voir
            // RKPrograms.buildCourseCard() dans booking-programs.js), qui
            // contient déjà exactement les mêmes valeurs que le serveur
            // aurait utilisées (même appel rk_get_courses en amont) —
            // aucune donnée recalculée, uniquement relue.
            const $selectedCourse = $('input[name="rk_course"]:checked, input[name="rk_adventure"]:checked')
                .closest('.rk-option-card');
            const courseImg = $selectedCourse.find('.rk-course-photo').attr('src') || '';
            const progName  = $selectedCourse.find('.rk-course-badge').text().trim()
                || s.program_name || '—';

            const childName = (s.children_names && s.children_names.length)
                ? s.children_names.join(', ')
                : '—';

            // AJUSTEMENT (demande utilisateur) — format compact carte
            // (voir u.formatCardDate(), cohérent avec Dashboard::
            // render_booking_card() côté PHP), au lieu du format détaillé
            // de l'étape 6 (avec minutes).
            const dtFormatted = u.formatCardDate(s.appointment_datetime);

            const html =
                '<article class="rk-booking-card rk-course-card" data-rk-row="' + rowId + '" data-rk-status="confirmed">' +
                    '<div class="rk-course-media">' +
                        (courseImg ? '<img src="' + u.escHtml(courseImg) + '" alt="" class="rk-course-photo" loading="lazy" decoding="async">' : '') +
                        '<span class="rk-course-status-badge is-confirmed">' +
                            this._resizeIcon(icons['check'], 13) + ' مؤكد' +
                        '</span>' +
                    '</div>' +
                    '<div class="rk-course-content rk-bc-content">' +
                        '<div class="rk-course-text">' +
                            '<strong data-rk-field="course">' + u.escHtml(s.adventure_name || '—') + '</strong>' +
                            '<p class="rk-bc-sub" data-rk-field="session">' + u.escHtml(s.session_name || '—') + '</p>' +
                            '<div class="rk-bc-meta">' +
                                // AJUSTEMENT (demande utilisateur) — coach
                                // AVANT la date, icône calendar (au lieu de
                                // clock) + data-rk-field="date" ajouté (même
                                // ordre et structure que Dashboard::
                                // render_booking_card()).
                                '<span class="rk-bc-meta-item rk-bc-coach">' + this._resizeIcon(icons['graduation-cap'], 14) +
                                    '<span data-rk-field="coach">' + u.escHtml(u.cleanCoachName(s.event_name) || '—') + '</span></span>' +
                                '<span class="rk-bc-meta-item">' + this._resizeIcon(icons['calendar'], 14) +
                                    '<span data-rk-field="date">' + u.escHtml(dtFormatted) + '</span></span>' +
                                '<span class="rk-visually-hidden" data-rk-field="child">' + u.escHtml(childName) + '</span>' +
                                '<span class="rk-visually-hidden" data-rk-field="program">' + u.escHtml(progName) + '</span>' +
                            '</div>' +
                        '</div>' +
                        // AJUSTEMENT (bug signalé) — bouton تعديل الحجز affiché
                        // dès l'injection quand rowId est disponible (voir
                        // doc de _prependBookingCard() plus haut) : la
                        // réservation vient d'être créée à l'instant, donc
                        // toujours dans la fenêtre 24h — même condition que
                        // Dashboard::render_booking_card() ($can_cancel true
                        // par construction ici. Pas de data-ssa-url : le lien
                        // de replanification SSA n'est pas connu côté client
                        // à cet instant — le bouton "تغيير الموعد" de la
                        // modale d'édition reste alors masqué jusqu'au
                        // prochain rechargement, sans bloquer le reste.
                        '<div class="rk-bc-footer">' +
                            (rowId
                                ? '<button type="button" class="rk-btn-edit-booking rk-open-edit-modal" data-row="' + rowId + '" data-ssa-url="">' +
                                      this._resizeIcon(icons['calendar'], 13) + ' تعديل الحجز' +
                                  '</button>'
                                : '') +
                        '</div>' +
                    '</div>' +
                '</article>';

            $cards.prepend(html);

            // Met à jour le badge "N لقاء" (voir Dashboard::render_history) —
            // simple relecture du nombre de cartes réellement présentes,
            // pas un calcul recalculé côté JS.
            const $badge = $('.rk-bookings-history .rk-badge-count');
            const count  = $cards.children('.rk-booking-card').length;
            if ($badge.length) {
                $badge.text(count + ' لقاء');
            } else {
                $('.rk-bookings-history .rk-section-header').append(
                    '<span class="rk-badge-count">' + count + ' لقاء</span>'
                );
            }
        },

        /* ── Affichage résultat ──────────────────────────────────────────── */
        showSuccess: function (bookingId) {
            const u = window.RiadaKidsWizard.Utils;
            const s = window.RKBookingState;

            // Log au niveau technique (booking_id) uniquement (voir consigne
            // étape 23).
            u.log('success displayed', { booking_id: bookingId });

            // Nettoie tout message d'erreur qui aurait pu être affiché par
            // un rk_ssa_booking_failed antérieur (ex. l'utilisateur avait
            // réessayé après un vrai échec, puis cette tentative-ci a
            // réussi) — les deux messages ne doivent jamais coexister.
            $('#rk-booking-result').removeClass('rk-error rk-success').hide().empty();
            this._confirming = false;

            const dtFormatted = (window.RKSummary && typeof window.RKSummary._formatAppointmentDate === 'function')
                ? window.RKSummary._formatAppointmentDate(s.appointment_datetime)
                : u.escHtml(s.appointment_datetime || '—');

            // CORRECTION (bug signalé) — remplace l'ancien écran inline
            // (carte dans la page) par un VRAI popup modal centré, calqué
            // sur la maquette fournie (mascotte + carte blanche + fond
            // assombri). L'image robot déjà utilisée en étape 1
            // (rk-hero-robot.png, voir BookingForm.php) est réutilisée ici
            // pour rester cohérent avec le reste du plugin.
            const robotUrl = (window.rkConfig && rkConfig.robotUrl) || '';

            const modalHtml =
                '<div class="rk-success-modal-backdrop" id="rk-success-modal">' +
                    '<div class="rk-success-modal-card" role="dialog" aria-modal="true">' +
                        (robotUrl ? '<img class="rk-success-modal-robot" src="' + u.escHtml(robotUrl) + '" alt="">' : '') +
                        '<h3 class="rk-success-modal-title">تم تأكيد الحجز!</h3>' +
                        '<p class="rk-success-modal-subtitle">موعدك جاهز، سيصلكم تأكيد عبر البريد</p>' +
                        '<div class="rk-success-modal-actions">' +
                            '<button type="button" class="rk-btn rk-btn-primary" id="rk-success-view-bookings">عرض لقاءاتي</button>' +
                            '<button type="button" class="rk-btn rk-btn-secondary" id="rk-success-back">احجز لقاء آخر</button>' +
                        '</div>' +
                    '</div>' +
                '</div>';

            // CORRECTION (bug signalé) — l'iframe SSA restait affichée
            // derrière l'écran de succès RiadaKids une fois le rendez-vous
            // réellement créé : SSA continue d'afficher son propre écran
            // de confirmation natif ("شكرًا لك! تم حجز موعدك...", avec ses
            // boutons "تحرير المعلومات"/"إعادة جدولة"/"إلغاء الموعد") dans
            // l'iframe, que rien ne masquait à ce stade. On masque
            // désormais complètement le widget SSA dès que le succès
            // RiadaKids est réellement confirmé — le popup RiadaKids
            // devient la SEULE confirmation visible pour l'utilisateur.
            $('#rk-ssa-widget').hide();

            $('body').append(modalHtml);

            // ÉTAPE 16 — une fois SUCCESS atteint, aucun moyen de revenir en
            // arrière dans le wizard ne doit rester visible sur cet écran :
            // ni #rk-confirm-booking (déjà masqué), ni le bouton natif
            // "‹ رجوع" (data-goto="5") du récap, qui ramènerait vers l'écran
            // SSA alors que le rendez-vous est déjà créé et confirmé. Les
            // deux seules actions possibles depuis SUCCESS sont désormais
            // celles du popup lui-même.
            $('#rk-confirm-booking').prop('disabled', false).text('تأكيد الحجز').hide();
            $('.rk-step6-nav [data-goto="5"]').hide();
        },

        showError: function (message) {
            this._confirming = false;
            $('#rk-booking-result')
                .removeClass('rk-success').addClass('rk-error')
                .html('<p>' + RKIcon('x-circle') + ' ' + window.RiadaKidsWizard.Utils.escHtml(message) + '</p>')
                .show();
        }
    };

})(jQuery);