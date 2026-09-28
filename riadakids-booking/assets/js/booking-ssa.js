/**
 * RiadaKids Booking Wizard — assets/js/booking-ssa.js — Module 8 : SSA
 * v3.1 — Sections réorganisées par phase du cycle de vie (étape 22)
 *
 *
 * Dépendances : jQuery, booking-state.js, booking-utils.js, booking-navigation.js
 */
(function ($) {
    'use strict';

    window.RKSSA = {
        // Constantes d'état (voir doc d'en-tête ci-dessus)
        STATE: {
            DATE:       'date',
            TIME:       'time',
            CUSTOMER:   'customer',
            SSA_BOOKED: 'ssa_booked',
            SUCCESS:    'success'
        },

        _state:               null,
        _pollingTimer:        null,
        _pollingAttempts:     0,
        _pollingMaxAttempts:  30, // 60s max (poll toutes les 2s)
        _tz:                  null,

        // ═══════════════════════════════════════════════════════════════
        // 1. INIT
        // ═══════════════════════════════════════════════════════════════

        init: function () {
            this._tz = this._detectTimezone();
            this._persistTimezone();
            this.bindSsaAppointmentEvent();
            this.bindSlotChosenEvent();
            this.bindReturnedToDateEvent();
            this.bindReturnedToTimeEvent();

            // Disponible dès l'init (avant tout choix de créneau) pour que
            // le récap RiadaKids (étape 6, booking-summary.js) puisse
            // afficher la timezone du client sans attendre la confirmation
            // SSA — même valeur que celle déjà injectée dans l'URL iframe
            // (_injectIframeParams) et utilisée pour formater l'affichage.
            // ÉTAPE 17 — libellé humain ("Riyadh (GMT+3)"), pas l'identifiant
            // IANA brut ("Asia/Riyadh") — voir _timezoneLabel().
            if (window.RKBookingState) {
                window.RKBookingState.customer_timezone = this._timezoneLabel();
            }

            window.RiadaKidsWizard.Utils.log('initialized');
        },

        // ═══════════════════════════════════════════════════════════════
        // 2. MACHINE À ÉTATS
        // ═══════════════════════════════════════════════════════════════

        /**
         * ÉTAPE 16 — retour TIME → DATE. Écoute 'rk_ssa_returned_to_date'
         * émis par booking-ssa-overlay.js quand l'utilisateur clique sur le
         * vrai bouton retour natif SSA et que SSA réaffiche réellement
         * l'écran calendrier. Fait redescendre la machine à états d'un cran
         * (TIME → DATE) pour que la prochaine sélection de créneau
         * (rk_ssa_slot_chosen, qui transite DATE→TIME→CUSTOMER) ne soit pas
         * rejetée par le garde-fou de _transitionTo — sans ce retour d'état,
         * le wizard resterait silencieusement bloqué après un aller-retour
         * DATE↔TIME. Le contexte RiadaKids (enfant, programme, séance,
         * booking_uuid déjà pré-créé) n'est volontairement PAS réinitialisé
         * ici : seul l'état de sélection SSA (jour/heure) est concerné.
         */
        bindReturnedToDateEvent: function () {
            const self = this;
            $(document).on('rk_ssa_returned_to_date', function () {
                if (self._state === self.STATE.TIME || self._state === self.STATE.CUSTOMER) {
                    self._state = self.STATE.DATE;
                }
            });
        },

        /**
         * CORRECTION (bug signalé) — retour CUSTOMER → TIME. Écoute
         * 'rk_ssa_returned_to_time' émis par
         * RKSSAOverlay.returnToTimeSelect() quand l'utilisateur navigue de
         * l'étape 6 RiadaKids (récap) vers l'étape 5 : SSA revient d'un
         * cran (Customer Information → .time-select), le jour choisi reste
         * intact — contrairement à rk_ssa_returned_to_date (retour complet
         * jusqu'au calendrier), qui redescend à DATE. Sans cette
         * redescente d'état, le garde-fou de _transitionTo rejetterait la
         * prochaine transition TIME→CUSTOMER quand l'utilisateur
         * choisirait à nouveau un créneau (même le même), laissant le
         * wizard bloqué.
         */
        bindReturnedToTimeEvent: function () {
            const self = this;
            $(document).on('rk_ssa_returned_to_time', function () {
                if (self._state === self.STATE.CUSTOMER) {
                    self._state = self.STATE.TIME;
                }
            });
        },

        /**
         * Transition d'état contrôlée. Rejette toute transition qui ne
         * suit pas l'ordre attendu (voir doc d'en-tête) — un event reçu
         * hors séquence (ex. un second ssa_appointment_booked après
         * SUCCESS) est ignoré plutôt que de redéclencher une action.
         * C'est ce garde-fou qui empêche structurellement un second
         * "booking" ou un second succès, plutôt qu'une simple convention.
         *
         * CORRECTION (bug signalé — re-sélection perdue après "رجوع" étape
         * 6→5) — cas réel manquant : returnToTimeSelect() (voir
         * booking-ssa-overlay.js) ramène l'état à TIME (pas DATE), le jour
         * déjà choisi restant affiché. Si l'utilisateur choisit alors un
         * NOUVEAU créneau sur ce même écran .time-select — sans jamais
         * repasser par le calendrier, donc sans que rk_ssa_returned_to_date
         * ne redescende l'état à DATE — rk_ssa_slot_chosen se déclenche
         * avec _state déjà égal à TIME. L'ancien garde-fou (avancée stricte
         * d'un cran) rejetait alors ce TIME → TIME comme "hors séquence" et
         * bindSlotChosenEvent() s'arrêtait net au tout premier appel,
         * silencieusement : appointment_datetime n'était jamais mis à jour,
         * goToStep(6) jamais appelé — d'où l'étape 6 affichant encore
         * l'ancien créneau (ou le wizard semblant bloqué sur "suivant").
         * Un ré-choix sur le même écran (TIME → TIME) est un cas normal du
         * flux de retour arrière documenté ci-dessus, pas une séquence
         * invalide : autorisé ici au même titre que l'avancée stricte.
         * SUCCESS reste protégé (aucune transition arrière n'existe vers ou
         * depuis SUCCESS, voir doc d'en-tête) — seule la ré-entrée sur le
         * même état est permise, jamais un saut en arrière vers un état
         * antérieur.
         */
        _transitionTo: function (next) {
            const order = [
                this.STATE.DATE, this.STATE.TIME, this.STATE.CUSTOMER,
                this.STATE.SSA_BOOKED, this.STATE.SUCCESS
            ];
            const currentIndex = order.indexOf(this._state);
            const nextIndex    = order.indexOf(next);

            // Première transition (état encore null), avancée stricte d'un
            // cran vers l'avant, OU ré-entrée sur le même état (re-sélection
            // après retour arrière — voir correction ci-dessus).
            if (this._state !== null && nextIndex !== currentIndex + 1 && nextIndex !== currentIndex) {
                return false;
            }

            this._state = next;
            return true;
        },

        // ═══════════════════════════════════════════════════════════════
        // 3. FUSEAU HORAIRE — utilisé par plusieurs phases (init, DATE,
        //    SSA_BOOKED) : détection, persistance, et deux formats de
        //    sortie (formatage d'une date SSA, libellé d'affichage).
        // ═══════════════════════════════════════════════════════════════

        /**
         * Fuseau IANA du navigateur (ex. "Asia/Jerusalem").
         * Repli sur le fuseau du site exposé par PHP si l'API Intl est indisponible.
         */
        _detectTimezone: function () {
            try {
                var tz = Intl.DateTimeFormat().resolvedOptions().timeZone;
                if (tz) return tz;
            } catch (e) {}
            return (window.rkConfig && rkConfig.siteTimezone) ? rkConfig.siteTimezone : '';
        },

        /**
         * Enregistre le fuseau en user_meta (rk_customer_timezone).
         * Sert de repli côté dashboard quand ssa_appointments.customer_timezone
         * est vide (RDV créés hors widget, imports, etc.).
         */
        _persistTimezone: function () {
            var url = window.RiadaKidsWizard.Utils.ajaxUrl();
            if (!url || !this._tz) return;

            try {
                if (window.localStorage && localStorage.getItem('rk_tz_sent') === this._tz) return;
            } catch (e) {}

            var tz = this._tz;
            $.post(url, {
                action: 'rk_set_timezone',
                nonce:  rkConfig.nonce,
                tz:     tz
            }).done(function () {
                try { localStorage.setItem('rk_tz_sent', tz); } catch (e) {}
            });
        },

        /**
         * Formate une datetime ISO/UTC renvoyée par SSA dans le fuseau du client.
         * Retourne '' si la valeur est inexploitable (le caller garde son fallback).
         */
        _formatInClientTz: function (raw) {
            if (!raw) return '';

            var d = new Date(raw);
            if (isNaN(d.getTime())) return '';

            var opts = {
                weekday: 'long', day: 'numeric', month: 'long', year: 'numeric',
                hour: '2-digit', minute: '2-digit', hour12: false
            };
            if (this._tz) opts.timeZone = this._tz;

            var locale = (window.rkConfig && rkConfig.locale) ? rkConfig.locale : 'ar';

            try {
                return new Intl.DateTimeFormat(locale, opts).format(d);
            } catch (e) {
                return d.toLocaleString();
            }
        },

        /**
         * ÉTAPE 17 — libellé timezone affiché à l'utilisateur (récap étape 6,
         * card "التوقيت" — voir booking-summary.js). Entièrement dérivé du
         * fuseau IANA réel détecté (this._tz), jamais codé en dur : ni le
         * nom de ville, ni l'offset GMT. L'offset est calculé dynamiquement
         * via Intl.DateTimeFormat({ timeZoneName: 'shortOffset' }) — gère
         * automatiquement l'heure d'été le cas échéant, pour n'importe quel
         * fuseau, pas seulement Asia/Riyadh.
         *
         * Le nom de ville est dérivé du dernier segment de l'identifiant
         * IANA lui-même (ex. "Asia/Riyadh" → "Riyadh") plutôt que d'une
         * table de correspondance codée en dur — aucune API standard ne
         * traduit ces noms en arabe côté navigateur, donc le nom reste en
         * anglais par choix explicite plutôt que d'inventer une traduction.
         * Résultat pour Asia/Riyadh : "Riyadh (GMT+3)". Pour un autre
         * fuseau (ex. Africa/Tunis), le résultat change en conséquence :
         * "Tunis (GMT+1)" — jamais "الرياض"/"GMT+3" imposés à tort.
         */
        _timezoneLabel: function () {
            if (!this._tz) return '';

            var city = this._tz.split('/').pop().replace(/_/g, ' ');
            var offset = '';

            try {
                var parts = new Intl.DateTimeFormat('en-US', {
                    timeZone: this._tz, timeZoneName: 'shortOffset'
                }).formatToParts(new Date());
                var part = parts.find(function (p) { return p.type === 'timeZoneName'; });
                if (part) offset = part.value;
            } catch (e) {}

            return offset ? (city + ' (' + offset + ')') : city;
        },

        // ═══════════════════════════════════════════════════════════════
        // 4. PHASE DATE — entrée sur l'écran SSA (étape 5 du wizard),
        //    pre-create du booking RiadaKids avant que l'utilisateur ne
        //    choisisse quoi que ce soit dans l'iframe.
        // ═══════════════════════════════════════════════════════════════

        /**
         * ENTRÉE ÉTAPE 5 — Appelée par le contrôleur de navigation globale.
         *
         * L'iframe SSA est déjà dans le DOM (rendue par BookingForm.php).
         * On se contente de :
         *   1. Mettre à jour le compteur de crédits affiché.
         *   2. Bloquer le bouton "suivant" (déblocage après polling réussi).
         *   3. Lancer le pre-create si aucun UUID n'existe encore.
         */
        onEnterStep5: function () {
            const s = window.RKBookingState;
            const u = window.RiadaKidsWizard.Utils;

            // ÉTAPE 16 — ne PAS écraser l'état à chaque entrée dans l'étape 5.
            // Cas réels :
            //   - Première entrée (état encore null) → DATE, l'iframe SSA
            //     n'a encore rien affiché.
            //   - Retour depuis l'étape 6 (CUSTOMER) vers l'étape 5 : l'iframe
            //     SSA n'est PAS rechargée (même iframe, même document) — elle
            //     affiche toujours son dernier écran réel, typiquement TIME
            //     (créneaux du jour déjà choisi). Forcer _state à DATE ici
            //     contredirait ce que l'utilisateur voit réellement et
            //     bloquerait ensuite la machine à états (voir _transitionTo)
            //     dès qu'il choisirait un créneau. On laisse donc l'état tel
            //     qu'il était — TIME reste TIME, CUSTOMER redescend à TIME
            //     via bindReturnedToDateEvent uniquement si SSA affiche
            //     réellement à nouveau le calendrier.
            //   - Retour avec un booking_uuid perdu (session expirée, etc.,
            //     voir plus bas "Réinitialisation si retour sans UUID") →
            //     là seulement, on repart proprement de DATE.
            if (this._state === null) {
                this._state = this.STATE.DATE;
            }

            // Re-synchronisation du compteur de crédits depuis l'état global
            const selectedCount = (s.child_ids && s.child_ids.length) ? s.child_ids.length : 0;
            $('#rk-step5-credit-count').text(selectedCount);

            if (selectedCount > 0) {
                $('#rk-step5-hint').show();
            } else {
                $('#rk-step5-hint').hide();
            }

            // Réinitialisation si retour sans UUID
            //
            // CORRECTION — ce garde s'exécute à CHAQUE entrée dans l'étape 5,
            // y compris la toute première fois. À ce moment-là,
            // s.booking_uuid est légitimement encore vide (il n'est créé
            // qu'un peu plus bas par _preCreate()), mais s.event_id peut
            // déjà avoir été fixé correctement par booking-sessions.js
            // (data-ssa-event-id, voir wp_rk_ssa_map côté PHP) — le remettre
            // à 0 ici effaçait systématiquement cette valeur avant même que
            // _injectIframeParams() ne puisse la transmettre à l'iframe
            // (paramètre "types"), forçant SSA à toujours afficher son
            // propre écran de sélection de type/coach. event_id ne doit
            // être réinitialisé que dans le cas que ce garde vise
            // réellement : un retour en arrière après perte de session SSA
            // (appointment_id/appointment_datetime obsolètes) — pas la
            // première entrée dans l'étape.
            if (!s.booking_uuid) {
                s.appointment_id       = 0;
                s.appointment_datetime = '';
                this._state             = this.STATE.DATE; // aucun contexte SSA valide à conserver
            }

            // Le bouton "suivant" reste bloqué jusqu'à confirmation SSA
            u.updateNextButton(5, false);

            if (s.session_name) {
                u.updateSummaryField('sum-event', s.session_name);
                $('#sum-event-wrapper').show();
            }

            // Si l'utilisateur revient en arrière avec un UUID déjà valide,
            // ne pas relancer le pre-create — l'iframe est déjà là.
            if (s.booking_uuid && s.booking_id) {
                this._injectIframeParams(s.booking_uuid);
                return;
            }

            // Lancer le pre-create pour obtenir l'ID CPT côté serveur
            // avant que l'utilisateur choisisse son créneau dans l'iframe.
            this._preCreate();
        },

        /**
         * Injecte l'UUID du booking ET le fuseau du client dans l'URL de l'iframe SSA.
         *
         * - rk_booking_uuid : renvoyé par SSA dans son webhook → confirm_from_ssa()
         *   retrouve le booking par UUID (chemin le plus fiable).
         * - timezone : force SSA à afficher les créneaux et à enregistrer
         *   customer_timezone dans le fuseau réel du visiteur, au lieu de retomber
         *   sur le fuseau du business (Asia/Riyadh).
         *
         * RETOUR ARRIÈRE (demande utilisateur) — un essai précédent forçait
         * ici le paramètre "types" pour sauter l'écran SSA de sélection de
         * type/coach (.booking-cards). Effet de bord observé en test réel :
         * avec "types" forcé sur un seul type, SSA confirme parfois la
         * réservation automatiquement dès le créneau choisi, sans jamais
         * afficher le bouton "حجز هذا الموعد" que _clickBookingSubmit()
         * attend — l'écran RiadaKids restait figé sur "جار الحفظ" alors que
         * la réservation avait réellement réussi côté SSA. On revient donc
         * au comportement SSA par défaut : l'écran de sélection de
         * type/coach (visible dans le design à 5 étapes d'origine, capture
         * "من سيرافق طفلك؟") reste affiché normalement, et
         * RKSSAOverlay/_pollForAppRoot() attend cet écran comme les autres.
         */
        _injectIframeParams: function (uuid) {
            var iframe = document.getElementById('rk-ssa-iframe');
            if (!iframe || !iframe.src) return;

            try {
                var src     = new URL(iframe.src);
                var changed = false;

                if (uuid && src.searchParams.get('rk_booking_uuid') !== uuid) {
                    src.searchParams.set('rk_booking_uuid', uuid);
                    changed = true;
                }
                if (this._tz && src.searchParams.get('timezone') !== this._tz) {
                    src.searchParams.set('timezone', this._tz);
                    changed = true;
                }

                if (changed) iframe.src = src.toString();
            } catch (e) {
                // URL malformée : on laisse l'iframe en l'état (fallback usermeta suffira)
            }
        },

        /**
         * Pre-create : crée le CPT rk_booking (status=pending) et stocke
         * booking_id + booking_uuid dans RKBookingState.
         *
         * IMPORTANT : event_id = 0 est accepté ici. SSA fournira l'appointment_type_id
         * via le webhook une fois que l'utilisateur aura choisi dans l'iframe.
         * BookingHooks::on_ssa_booked() résoudra le booking par UUID.
         */
        _preCreate: function () {
            const self = this;
            const url  = window.RiadaKidsWizard.Utils.ajaxUrl();
            const s    = window.RKBookingState;

            if (!url) return;

            $.post(url, {
                action:            'rk_pre_create_booking',
                nonce:             rkConfig.nonce,
                program_id:        s.program_id,
                adventure_id:      s.adventure_id,
                session_id:        s.session_id,
                session_name:      s.session_name,
                child_ids:         s.child_ids,
                event_id:          s.event_id || 0,
                customer_timezone: self._tz || ''
            }, function (resp) {
                if (resp.success) {
                    s.booking_id   = resp.data.booking_id;
                    s.booking_uuid = resp.data.booking_uuid;

                    self._injectIframeParams(resp.data.booking_uuid);

                    // CORRECTION (bug signalé — nom du coach jamais
                    // affiché) — voir doc de _syncEventName() plus bas.
                    // s.booking_id n'existe qu'À PARTIR d'ICI (réponse
                    // async de rk_pre_create_booking) : si l'utilisateur
                    // choisit son créneau dans l'iframe SSA plus vite que
                    // cet appel ne répond, rk_ssa_slot_chosen (voir
                    // bindSlotChosenEvent) se déclenchait avec
                    // s.booking_id encore à 0 — la synchronisation du nom
                    // du coach était alors silencieusement ignorée, sans
                    // aucun retry ultérieur. On retente donc ICI, dès que
                    // booking_id devient disponible : si event_name est
                    // déjà connu à ce moment (cas où l'iframe a rendu son
                    // en-tête plus vite que cette réponse ne revient), on
                    // synchronise immédiatement — sinon rk_ssa_slot_chosen
                    // prendra le relais normalement (event_name pas encore
                    // connu à cet instant précis). Écriture idempotente
                    // côté serveur (simple update_post_meta), donc aucun
                    // risque de doublon si les deux chemins s'exécutent.
                    if (s.event_name) {
                        self._syncEventName(s.booking_id, s.event_name);
                    }
                } else {
                    // Affiche l'erreur sans bloquer l'iframe — l'utilisateur peut retenter
                    $('#rk-ssa-widget').prepend(
                        '<div class="rk-error-msg" id="rk-precreate-error">' +
                        (resp.data && resp.data.message ? resp.data.message : 'خطأ أثناء إعداد الحجز.') +
                        '</div>'
                    );
                }
            });
        },

        /**
         * AJOUT (demande utilisateur — nom du coach sur la carte
         * "لقاءاتي القادمة") — enregistre event_name (le titre affiché par
         * SSA, "لقاء مع [Nom]" — voir doc de bindSlotChosenEvent ci-dessous)
         * sur le CPT pending déjà créé par _preCreate().
         *
         * CORRECTION (bug signalé — nom du coach encore non affiché malgré
         * un premier correctif) — appelée maintenant à DEUX points distincts
         * (voir _preCreate() ci-dessus ET bindSlotChosenEvent() plus bas),
         * pas un seul : s.booking_id (résolu de façon async par
         * rk_pre_create_booking) et s.event_name (capturé de façon async
         * par RKSSAOverlay._captureEventName() dès que l'iframe SSA rend
         * son en-tête) deviennent disponibles à deux moments totalement
         * indépendants, dans un ordre non garanti selon la vitesse relative
         * du réseau et de l'utilisateur. L'ancienne version n'appelait cette
         * fonction qu'au second de ces deux événements (rk_ssa_slot_chosen)
         * et abandonnait silencieusement si booking_id n'était pas encore
         * connu à cet instant précis — un cas fréquent en pratique dès que
         * l'utilisateur choisissait son créneau rapidement. Appeler ce
         * synchronisme aux deux points (dès que CHACUNE des deux valeurs
         * devient disponible, en vérifiant que l'autre l'est déjà) couvre
         * les deux ordres d'arrivée possibles.
         *
         * Échec réseau : ignoré silencieusement — ce n'est qu'un
         * enrichissement d'affichage (nom du coach), jamais une donnée
         * bloquante pour la suite du parcours de réservation. Si l'appel
         * échoue, la carte affichera simplement "—" à la place du nom du
         * coach, sans aucun impact sur la réservation elle-même.
         */
        _syncEventName: function (bookingId, eventName) {
            const url = window.RiadaKidsWizard.Utils.ajaxUrl();
            if (!url || !bookingId || !eventName) return;

            $.post(url, {
                action:     'rk_set_booking_event_name',
                nonce:      rkConfig.nonce,
                booking_id: bookingId,
                event_name: eventName
            });
            // .fail() volontairement absent — voir doc ci-dessus.
        },

        // ═══════════════════════════════════════════════════════════════
        // 5. PHASE TIME/CUSTOMER — jour + heure choisis dans l'iframe SSA,
        //    bascule vers le récap RiadaKids (étape 6), avant toute
        //    confirmation SSA réelle.
        // ═══════════════════════════════════════════════════════════════

        /**
         * ENTRÉE ÉTAPE 6 (PRÉ-confirmation) — Écoute le signal custom
         * 'rk_ssa_slot_chosen' émis par booking-ssa-overlay.js dès que
         * l'utilisateur a choisi jour + heure dans l'iframe SSA et que le
         * formulaire "Customer Information" natif a été auto-rempli
         * (mais PAS encore soumis — voir booking-ssa-overlay.js).
         *
         * Transition DATE → TIME → CUSTOMER (SSA gère les écrans DATE/TIME
         * en interne dans l'iframe ; ce signal arrive une fois les deux
         * déjà choisis côté SSA, d'où la transition en un seul saut ici —
         * voir doc d'en-tête). À ce stade, aucun rendez-vous SSA n'existe
         * encore. On bascule simplement l'affichage vers l'étape 6
         * RiadaKids (récap, Capture 3), qui utilise appointment_datetime
         * déjà capturé côté DOM par RKSSAOverlay._captureChosenSlot().
         *
         * CORRECTION (demande utilisateur — nom du coach affiché sur la
         * carte "لقاءاتي القادمة") — le nom du coach EST déjà connu du
         * frontend : c'est exactement s.event_name, capturé depuis le vrai
         * titre affiché par SSA (h1.ssa-type-header, "لقاء مع [Nom]" — voir
         * RKSSAOverlay._captureEventName()) dès l'entrée à l'étape 5, une
         * fois l'enfant sélectionné. Jusqu'ici cette valeur ne quittait
         * jamais le navigateur (utilisée uniquement par booking-summary.js
         * pour l'affichage "المدرب" du récap étape 6) — jamais transmise au
         * serveur, donc jamais persistée dans wp_rk_bookings.coach.
         *
         * On l'envoie ici — au moment précis où jour+heure viennent d'être
         * choisis, donc où le header SSA est certainement déjà rendu et
         * capturé (contrairement à _preCreate(), appelée dès l'entrée à
         * l'étape 5, avant même que l'iframe SSA n'ait eu le temps
         * d'afficher son écran) — via rk_set_booking_event_name, qui
         * l'enregistre sur le CPT pending déjà créé (s.booking_id). Cette
         * meta est ensuite relue par BookingContext::from_array() au
         * moment de la confirmation réelle (confirm_from_ssa), exactement
         * comme les autres champs du contexte — voir BookingRepository::
         * load_context().
         */
        bindSlotChosenEvent: function () {
            const self = this;
            $(document).on('rk_ssa_slot_chosen', function () {
                if (!self._transitionTo(self.STATE.TIME)) return;
                if (!self._transitionTo(self.STATE.CUSTOMER)) return;

                const s0 = window.RKBookingState;
                self._syncEventName(s0.booking_id, s0.event_name);

                const s = window.RKBookingState;
                if (s.appointment_datetime) {
                    window.RiadaKidsWizard.Utils.updateSummaryField('sum-date', s.appointment_datetime);
                    $('#sum-date-wrapper').show();
                }

                // Préremplissage des champs VISIBLES nom/email (étape 6) à
                // partir des données client connues — uniquement si ces
                // champs sont encore vides, pour ne jamais écraser une
                // modification déjà saisie par l'utilisateur (ex. retour
                // arrière puis re-sélection d'un créneau).
                if (window.rkConfig) {
                    const $name  = $('#rk-customer-name');
                    const $email = $('#rk-customer-email');
                    if ($name.length && !$name.val())  $name.val(window.rkConfig.customerName  || '');
                    if ($email.length && !$email.val()) $email.val(window.rkConfig.customerEmail || '');
                }

                window.RiadaKidsWizard.Navigation.goToStep(6);
            });
        },

        // ═══════════════════════════════════════════════════════════════
        // 6. PHASE SSA_BOOKED — l'utilisateur a cliqué "تأكيد الحجز" côté
        //    RiadaKids, ce qui a déclenché le vrai clic natif SSA
        //    (RKSSAOverlay.submitNativeBooking) ; SSA a réellement créé
        //    le rendez-vous et émet son événement natif.
        // ═══════════════════════════════════════════════════════════════

        /**
         * ═══════════════════════════════════════════════════════════════
         * CORRECTION (audit) — SIGNAL RÉEL DE RÉSERVATION SSA
         * ═══════════════════════════════════════════════════════════════
         * Vérification faite sur le code source réel du plugin SSA
         * (simply-schedule-appointments) : l'event jQuery
         * 'ssa_appointment_booked' N'EXISTE NULLE PART dans SSA — ni dans
         * son bundle iframe (booking-app-new/dist), ni dans son embed
         * parent-page (assets/js/ssa-form-embed.js), ni côté PHP. Ce nom
         * d'event ne peut donc jamais être déclenché : le code précédent
         * qui l'écoutait ($(document).on('ssa_appointment_booked', ...))
         * ne se déclenchait jamais, et le polling ne démarrait donc
         * jamais non plus après un vrai clic de réservation.
         *
         * Le VRAI signal que SSA émet à la page parente au moment où le
         * rendez-vous est réellement enregistré (confirmé dans
         * booking-app-new/dist/static/js/app.js::handleSaveAppointment) est :
         *
         *   window.parent.postMessage(
         *     { ssaType: 'appointment', id: <appointment_id>, start_date },
         *     this.api.home_url
         *   )
         *
         * C'est exactement le même mécanisme que celui déjà utilisé par
         * SSA pour son propre pont Gravity Forms (voir
         * assets/js/ssa-form-embed.js dans le plugin SSA — même event,
         * même filtrage sur e.data.ssaType === 'appointment'). On adopte
         * ici le même pont, natif et documenté par SSA lui-même, plutôt
         * qu'un event jQuery qui n'a jamais existé.
         *
         * SSA ne notifie PAS la page parente en cas d'ÉCHEC (créneau
         * devenu indisponible entre-temps, etc.) — l'erreur reste interne
         * à l'iframe (this.error=true, this.errorMessage=...). Ce cas est
         * couvert côté booking-ssa-overlay.js::_bindErrorObserver, qui
         * observe directement ce même état d'erreur dans le DOM iframe
         * (classe .md-empty-state) et relaie rk_ssa_booking_failed —
         * aucun timeout local n'est plus utilisé pour ce cas.
         */
        bindSsaAppointmentEvent: function () {
            const self = this;

            window.addEventListener('message', function (event) {
                if (!event || !event.data || typeof event.data !== 'object') return;
                if (event.data.ssaType !== 'appointment') return;
                if (!event.data.id) return; // id vide = SSA a réinitialisé/annulé, pas une réservation réelle

                if (!self._transitionTo(self.STATE.SSA_BOOKED)) return;

                // Log au niveau technique (ID SSA) uniquement — jamais le
                // payload complet de l'appointment (peut contenir nom/email
                // du client, voir consigne étape 23).
                window.RiadaKidsWizard.Utils.log('appointment booked', { appointment_id: event.data.id });

                const s = window.RKBookingState;

                s.appointment_id = event.data.id;

                // start_date est déjà fourni dans le message postMessage
                // (voir handleSaveAppointment côté SSA) — repli sur la
                // valeur déjà capturée avant clic (_captureChosenSlot) si
                // absente.
                var raw = event.data.start_date || '';
                var localized = self._formatInClientTz(raw);

                s.appointment_datetime     = localized || s.appointment_datetime;
                s.appointment_datetime_raw = raw || s.appointment_datetime_raw;
                s.customer_timezone        = self._timezoneLabel();

                // CORRECTION (bug signalé — nom du coach encore non
                // affiché) — FILET DE SÉCURITÉ FINAL. Les deux points de
                // synchronisation précédents (_preCreate() et
                // bindSlotChosenEvent(), voir leurs doc respectives)
                // couvrent la quasi-totalité des cas, mais restent
                // dépendants d'un ordre d'arrivée entre deux valeurs
                // asynchrones (booking_id, event_name). Ce point-ci est
                // différent : il se déclenche sur le VRAI événement de
                // réservation SSA (postMessage), qui ne peut structurellement
                // survenir qu'après que l'utilisateur ait vu (et donc que
                // RKSSAOverlay ait capturé) l'en-tête SSA depuis le tout
                // début du parcours (étape 5) — les deux valeurs sont donc
                // garanties disponibles ici, quel que soit l'ordre des deux
                // points précédents. Appelée juste avant _startPolling()
                // (qui déclenchera confirm_from_ssa() côté serveur, lequel
                // relit cette même meta) — donc à temps.
                self._syncEventName(s.booking_id, s.event_name);

                window.RiadaKidsWizard.Utils.updateSummaryField('sum-date', s.appointment_datetime);
                $('#sum-date-wrapper').show();

                // Démarrage du polling pour détecter la confirmation côté serveur.
                // C'est SEULEMENT ici (après le vrai postMessage SSA) que le
                // polling doit tourner — plus depuis onEnterStep5.
                self._startPolling(s.booking_id);

                // Informe booking-confirmation.js que le vrai signal SSA de
                // succès est arrivé (utilisé pour du logging/état interne —
                // c'est rk_booking_confirmed_server, émis plus tard une fois
                // le serveur RiadaKids confirmé, qui déclenche réellement
                // l'écran de succès).
                $(document).trigger('rk_ssa_appointment_booked', [event.data]);
            });
        },

        // ═══════════════════════════════════════════════════════════════
        // 7. PHASE SUCCESS — attente de la confirmation backend
        //    (BookingService::confirm_from_ssa()) via polling, jusqu'à
        //    déclencher l'écran de succès RiadaKids.
        // ═══════════════════════════════════════════════════════════════

        /**
         * Polling toutes les 2s — attend que BookingService::confirm_from_ssa()
         * ait traité le webhook SSA et passé le booking en status=confirmed.
         *
         * Transition SSA_BOOKED → SUCCESS. C'est l'UNIQUE point de tout le
         * frontend qui déclenche rk_booking_confirmed_server — c.-à-d.
         * l'unique chemin vers l'état SUCCESS. Aucun bouton frontend ne
         * peut déclencher SUCCESS directement : SUCCESS ne dépend que de
         * la confirmation serveur réelle (confirm_from_ssa()), jamais d'un
         * second bouton ou d'une seconde action utilisateur.
         */
        _startPolling: function (bookingId) {
            const self = this;
            this._pollingAttempts = 0;
            this._stopPolling();

            // CORRECTION (bug signalé — écran figé indéfiniment, dernier
            // maillon) — _startPolling() peut désormais être appelée par
            // deux chemins : le vrai postMessage SSA (bindSsaAppointmentEvent,
            // qui pose _transitionTo(STATE.SSA_BOOKED) juste avant, voir
            // plus haut), OU l'observateur de secours de l'overlay
            // (RKSSAOverlay::_bindLateSuccessObserver, pour le cas où SSA
            // confirme sans jamais émettre ce postMessage — voir sa doc).
            // Ce second chemin appelle _startPolling() directement, en
            // contournant bindSsaAppointmentEvent et donc SANS jamais poser
            // l'état SSA_BOOKED. Résultat observé en test réel : le polling
            // tournait bien et recevait confirmed:true du serveur (vérifié
            // en base — booking réellement confirmé), mais
            // _transitionTo(STATE.SUCCESS) refusait silencieusement la
            // transition (elle n'autorise qu'un avancement strict d'un cran :
            // ici this._state valait encore "customer", pas "ssa_booked") —
            // l'écran de succès ne s'affichait donc jamais malgré un
            // succès serveur réel et un polling qui fonctionnait
            // correctement. On pose donc l'état SSA_BOOKED ici aussi,
            // uniquement s'il n'y est pas déjà (idempotent, sans effet sur
            // le chemin normal où bindSsaAppointmentEvent l'a déjà posé).
            if (this._state !== this.STATE.SSA_BOOKED && this._state !== this.STATE.SUCCESS) {
                this._transitionTo(this.STATE.SSA_BOOKED);
            }

            this._pollingTimer = setInterval(function () {
                self._pollingAttempts++;
                if (self._pollingAttempts > self._pollingMaxAttempts) {
                    self._stopPolling();
                    return;
                }

                const url = window.RiadaKidsWizard.Utils.ajaxUrl();
                if (!url) return;

                $.post(url, {
                    action:     'rk_poll_booking',
                    nonce:      rkConfig.nonce,
                    booking_id: bookingId,
                }, function (resp) {
                    if (!resp.success) { self._stopPolling(); return; }
                    if (resp.data.confirmed) {
                        self._stopPolling();
                        if (!self._transitionTo(self.STATE.SUCCESS)) return;

                        window.RKBookingState.booking_uuid = resp.data.booking_uuid || window.RKBookingState.booking_uuid;

                        // Log au niveau technique (booking_id, drapeau
                        // crédits) uniquement — jamais le booking_uuid ni
                        // aucune donnée personnelle du client (voir
                        // consigne étape 23).
                        window.RiadaKidsWizard.Utils.log('RiadaKids booking confirmed', { booking_id: bookingId });
                        if (resp.data.credits_deducted) {
                            window.RiadaKidsWizard.Utils.log('credits processed', { booking_id: bookingId });
                        }

                        // On est déjà à l'étape 6 (voir bindSlotChosenEvent) :
                        // ce signal ne fait plus naviguer, il déclenche
                        // l'affichage du succès (Capture 4) — géré par
                        // booking-confirmation.js.
                        $(document).trigger('rk_booking_confirmed_server', [resp.data]);
                    }
                });
            }, 2000);
        },

        _stopPolling: function () {
            if (this._pollingTimer) {
                clearInterval(this._pollingTimer);
                this._pollingTimer = null;
            }
        }
    };

})(jQuery);