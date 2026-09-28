/**
 * RiadaKids Booking Wizard — assets/js/booking-ssa-overlay.js — Module 8b : SSA Overlay
 * v2.0 — Couche de présentation (étape 10)
 *
 * Widget custom basé sur le widget SSA (Simply Schedule Appointments) natif.
 *
 * PROBLÈME RÉSOLU :
 * Le champ « CSS personnalisé » du dashboard SSA applique des règles CSS
 * statiques, écrites à l'aveugle sans jamais voir le DOM réel. Le calendrier
 * (.monthly) et la colonne créneaux (.time-select) n'apparaissent pas en même
 * temps : .time-select n'existe dans le DOM qu'après le clic sur un jour. Un
 * CSS statique doit deviner tous les états possibles sans jamais les observer,
 * ce qui casse dès que SSA change un nom de classe ou une structure interne.
 *
 * SOLUTION :
 * Ce module s'exécute dans la page PARENTE (rk-booking.js), pas dans l'iframe.
 * L'iframe SSA est chargée depuis rest_url('ssa/v1/embed-inner') — donc le
 * MÊME ORIGIN que riadakids.com (voir BookingForm.php). Ceci autorise l'accès
 * direct à iframe.contentDocument depuis ce script (pas de restriction
 * cross-origin). On peut donc :
 *   1. Injecter une feuille <style> DANS le document de l'iframe.
 *   2. Observer ses mutations réelles (MutationObserver) pour savoir quand
 *      .time-select apparaît/disparaît, et ajuster la mise en page en direct
 *      au lieu de deviner tous les cas via des media queries statiques.
 *
 * PÉRIMÈTRE DE CE FICHIER (étape 10) — il ne doit PAS devenir un second
 * moteur de booking. Il est limité à :
 *   - ajouter/supprimer des classes CSS sur le DOM SSA natif
 *   - afficher/masquer, styliser, adapter le responsive et le RTL
 *   - traduire/injecter des libellés (titre, sous-titre) au-dessus des
 *     titres natifs SSA masqués
 *   - OBSERVER (lecture seule) le DOM SSA pour synchroniser l'UI RiadaKids
 *     (ex. lire le jour sélectionné pour l'afficher en sous-titre)
 * Il ne calcule, ne génère et ne reconstruit AUCUNE disponibilité, créneau
 * ou calendrier — SSA reste l'unique source de vérité pour tout cela.
 *
 * EXCEPTION EXPLICITE ET ASSUMÉE — submitNativeBooking() :
 * Ce module contient UN SEUL point qui va au-delà de la présentation pure :
 * un clic JS synthétique sur le bouton natif SSA "حجز هذا الموعد", via
 * submitNativeBooking(). Ce n'est pas un second moteur de booking : SSA reste
 * celui qui crée réellement le rendez-vous, ce clic ne fait que déclencher SA
 * propre logique interne (aucune donnée n'est envoyée directement au serveur
 * par ce fichier). C'est un pont volontaire, décidé explicitement : le flux
 * cible affiche le récap RiadaKids natif (نom/date/heure/enfant/coach) dès
 * que le créneau est choisi, avec un unique bouton "تأكيد الحجز" — l'écran
 * SSA nom/email natif reste invisible (préremplissage silencieux via
 * _autofillCustomerInfo, SANS clic à ce stade). C'est UNIQUEMENT le clic
 * explicite de l'utilisateur sur ce bouton RiadaKids (voir
 * booking-confirmation.js::bindConfirmButton) qui appelle
 * submitNativeBooking() — jamais un minuteur, jamais un MutationObserver,
 * jamais un chargement de page. Aucun autre endroit de ce fichier ne clique
 * sur un bouton SSA.
 *
 * Dépendances : jQuery, booking-utils.js
 * Chargé juste après booking-ssa.js (voir Assets.php).
 */
(function ($) {
    'use strict';

    window.RKSSAOverlay = {

        _styleTagId: 'rk-ssa-overlay-style',
        _observer: null,
        _iframeEl: null,
        _selectedDayLabel: '', // libellé du jour choisi, lu depuis le DOM SSA (voir _captureSelectedDayLabel)
        _clickedDayLabel: '', // libellé du jour capturé au clic (voir _bindCalendarDayClickCapture)
        _clickedTimeLabel: '', // libellé du créneau capturé au clic (voir _bindTimeSlotClickCapture)
        _calendarSnapshotHtml: '', // clone HTML figé du calendrier (voir _captureCalendarSnapshot/_injectCalendarSnapshot)
        _wasOnTimeScreen: false, // ÉTAPE 16 — mémorise le passage par l'écran créneaux, pour détecter un retour réel vers DATE
        _pollTimer: null,
        _pollAttempts: 0,
        _pollMaxAttempts: 25, // ~10s max (poll toutes les 400ms) — l'iframe SSA est async
        _postAbandonObserver: null, // relance le poll après abandon (voir _armPostAbandonWatch)
        _pendingDoc: null,       // document iframe au moment de l'autofill (voir _autofillCustomerInfo)
        _pendingContainer: null, // .customer-information-container correspondant (voir submitNativeBooking)
        _submitted: false,       // AJOUT — remplace l'ancienne marque rk-submitted (classList sur _pendingContainer, peu fiable si ce nœud DOM devient détaché avant lecture ultérieure — voir submitNativeBooking)
        _attempted: false,       // AJOUT — vrai dès qu'un clic natif est tenté (réussi ou non) ; arme _bindLateSuccessObserver, contrairement à _submitted qui exige un succès immédiat (voir submitNativeBooking)

        // ═══════════════════════════════════════════════════════════════
        // BOOTSTRAP — attache l'observer au document iframe, quel que soit
        // le moment où le script s'exécute par rapport au chargement.
        // ═══════════════════════════════════════════════════════════════

        init: function () {
            this._iframeEl = document.getElementById('rk-ssa-iframe');
            if (!this._iframeEl) return; // pas d'étape 5 sur cette vue (pas de crédits, etc.)

            // L'iframe peut recharger sa src (voir _injectIframeParams dans
            // booking-ssa.js) : on réattache l'observer à chaque (re)chargement.
            this._iframeEl.addEventListener('load', this._onIframeLoad.bind(this));

            // Cas où l'iframe est déjà chargée avant que ce script tourne
            // (retour en arrière dans le wizard, par ex.).
            this._onIframeLoad();
        },

        /**
         * Appelée à chaque chargement/rechargement du document iframe.
         * Le contentDocument n'est pas toujours immédiatement accessible
         * (race condition avec le propre bootstrap JS de SSA à l'intérieur),
         * donc on poll jusqu'à trouver .appt-select avant de brancher
         * le style + l'observer.
         */
        _onIframeLoad: function () {
            this._stopPolling();
            this._pollAttempts   = 0;
            this._selectedDayLabel = ''; // nouveau document iframe : la sélection précédente n'a plus cours
            this._clickedDayLabel  = ''; // idem — sera recapturé au prochain clic sur un jour
            this._clickedTimeLabel = ''; // idem — sera recapturé au prochain clic sur un créneau
            this._wasOnTimeScreen  = false; // idem — on repart de zéro sur le nouveau document
            this._calendarSnapshotHtml = ''; // idem — sera recapturé dès que .appt-select réapparaît

            // AJOUT (demande utilisateur — styliser aussi l'écran natif SSA
            // de sélection type/coach, .booking-cards) — injecté ici, dès
            // que le document est accessible, plutôt que d'attendre
            // _pollForAppRoot() (qui ne branche qu'une fois .appt-select ou
            // .time-select détecté, c'est-à-dire APRÈS l'écran de sélection
            // type/coach). _injectStyles() a son propre garde anti-doublon
            // (id du <style> déjà présent), donc cet appel précoce est sûr
            // même si _pollForAppRoot() l'appelle de nouveau plus tard.
            const earlyDoc = this._getIframeDoc();
            if (earlyDoc && earlyDoc.head) {
                this._injectStyles(earlyDoc);
            }

            this._pollForAppRoot();
        },

        _pollForAppRoot: function () {
            const self = this;
            const doc = this._getIframeDoc();

            // .appt-select (écran calendrier) OU .time-select (écran
            // créneaux, si l'utilisateur revient dans le wizard avec un
            // jour déjà choisi) — l'un ou l'autre suffit pour brancher.
            if (doc && (doc.querySelector('.appt-select') || doc.querySelector('.time-select'))) {
                this._injectStyles(doc);
                this._observe(doc);
                return;
            }

            this._pollAttempts++;
            if (this._pollAttempts > this._pollMaxAttempts) {
                // CORRECTION (instabilité "défaut/custom" signalée) — le poll
                // abandonnait définitivement après ~10s, ce qui suffit
                // largement pour le calendrier lui-même, mais pas pour tout
                // le temps que l'utilisateur peut passer sur l'écran natif
                // SSA de sélection type/coach (.booking-cards) qui précède
                // le calendrier quand plusieurs types SSA existent (retour
                // au comportement par défaut, voir _injectIframeParams).
                // Résultat observé en test réel : le poll expirait pendant
                // que l'utilisateur choisissait encore son coach, et
                // n'était jamais relancé une fois le calendrier réellement
                // affiché — l'overlay restait alors inactif (pas de style,
                // pas d'autofill, pas de clic natif) pour le reste du
                // parcours, de façon imprévisible selon la vitesse de
                // l'utilisateur. On relance donc une observation légère
                // et peu coûteuse (MutationObserver, pas de polling actif)
                // qui réarme le poll normal dès qu'un changement DOM
                // survient dans l'iframe — typique d'un clic sur une carte
                // .booking-cards qui fait avancer SSA vers le calendrier.
                this._armPostAbandonWatch();
                return;
            }

            this._pollTimer = setTimeout(function () {
                self._pollForAppRoot();
            }, 400);
        },

        _stopPolling: function () {
            if (this._pollTimer) {
                clearTimeout(this._pollTimer);
                this._pollTimer = null;
            }
            if (this._postAbandonObserver) {
                this._postAbandonObserver.disconnect();
                this._postAbandonObserver = null;
            }
        },

        /**
         * AJOUT (correction de l'instabilité "défaut/custom" signalée) —
         * observateur léger, branché uniquement après l'abandon du poll
         * initial (_pollForAppRoot). Ne fait aucun travail lui-même : il se
         * contente de détecter qu'un changement notable s'est produit dans
         * le document iframe (typiquement, SSA qui bascule de l'écran
         * .booking-cards vers le calendrier après un clic sur une carte
         * type/coach) et de réarmer un poll classique à ce moment-là.
         * Se déconnecte automatiquement une fois .appt-select/.time-select
         * trouvé, ou lors du prochain _onIframeLoad() (voir _stopPolling,
         * appelé en tout début de _onIframeLoad).
         */
        _armPostAbandonWatch: function () {
            var self = this;
            var doc  = this._getIframeDoc();
            if (!doc || !doc.body || this._postAbandonObserver) return;

            this._postAbandonObserver = new MutationObserver(function () {
                if (doc.querySelector('.appt-select') || doc.querySelector('.time-select')) {
                    self._postAbandonObserver.disconnect();
                    self._postAbandonObserver = null;
                    self._pollAttempts = 0; // repart avec un budget de tentatives frais
                    self._pollForAppRoot();
                }
            });
            this._postAbandonObserver.observe(doc.body, { childList: true, subtree: true });
        },

        // ═══════════════════════════════════════════════════════════════
        // ACCÈS & OBSERVATION DU DOCUMENT IFRAME — lecture seule, aucune
        // donnée de disponibilité n'est calculée ici (voir étape 19).
        // ═══════════════════════════════════════════════════════════════

        /**
         * Accès au document interne. Retourne null si cross-origin
         * (protection : ne jamais laisser une exception remonter et
         * casser le reste du wizard).
         */
        _getIframeDoc: function () {
            try {
                return this._iframeEl.contentDocument || this._iframeEl.contentWindow.document;
            } catch (e) {
                // Cross-origin de façon inattendue (ex. domaine SSA externe
                // dans une config différente) : on abandonne proprement,
                // le CSS du dashboard SSA reste le filet de secours.
                return null;
            }
        },

        /**
         * Injecte (une seule fois par document) la feuille de style qui
         * reproduit visuellement la maquette RiadaKids par-dessus le
         * balisage SSA natif.
         */
        _injectStyles: function (doc) {
            if (doc.getElementById(this._styleTagId)) return; // déjà injecté

            const style = doc.createElement('style');
            style.id = this._styleTagId;
            style.textContent = this._css();
            doc.head.appendChild(style);
        },

        /**
         * Observe le <body> du document iframe (pas seulement .appt-select,
         * qui n'existe plus une fois que l'écran bascule sur .time-select —
         * SSA remplace entièrement l'écran, il ne l'augmente pas). C'est ce
         * changement de structure qui nous oblige à figer une image du
         * calendrier avant sa disparition plutôt que de compter sur sa
         * présence continue dans le DOM.
         */
        _observe: function (doc) {
            const body = doc.body;
            if (!body) return;

            if (this._observer) this._observer.disconnect();

            const self = this;
            this._observer = new MutationObserver(function () {
                self._syncState(doc);
            });

            this._observer.observe(body, { childList: true, subtree: true });

            // Premier passage immédiat (retour en arrière dans le wizard :
            // l'un ou l'autre écran peut déjà être présent).
            this._syncState(doc);
        },

        // ═══════════════════════════════════════════════════════════════
        // ÉCRANS DATE / TIME — routage + présentation. _syncState() est le
        // point d'entrée unique (appelé à chaque mutation), qui délègue
        // ensuite à des méthodes dédiées par écran (_injectHeaderBlock pour
        // le titre/sous-titre de DATE et TIME, _injectCalendarSnapshot
        // pour le retour sur TIME, _captureSelectedDayLabel/_captureChosenSlot
        // pour lire — jamais calculer — ce que SSA affiche déjà).
        // ═══════════════════════════════════════════════════════════════

        /**
         * Point d'entrée unique appelé à chaque mutation du document iframe.
         * Route vers le bon traitement selon l'écran actuellement affiché :
         *   - .appt-select présent  → écran calendrier (étape "اختر تاريخا")
         *   - .time-select présent  → écran créneaux (étape "تحديد وقت")
         * Les deux ne coexistent JAMAIS dans le DOM SSA réel (confirmé) :
         * .time-select remplace .appt-select, il ne s'y ajoute pas.
         */
        _syncState: function (doc) {
            const apptSelect = doc.querySelector('.appt-select');
            const timeSelect = doc.querySelector('.time-select');

            // ÉTAPE 16 — retour TIME → DATE. Si l'utilisateur clique sur le
            // vrai bouton natif SSA "الرجوع" (voir _injectCalendarSnapshot),
            // SSA détruit .time-select et réaffiche .appt-select — on l'a
            // déjà observé (_wasOnTimeScreen mémorise qu'on était bien passé
            // par l'écran créneaux, pas juste le premier chargement de la
            // page). On notifie alors le reste du wizard (rk_ssa_returned_to_date,
            // consommé par booking-ssa.js) pour que sa machine à états
            // redescende proprement TIME → DATE plutôt que de rester bloquée
            // en avant, ce qui empêcherait toute nouvelle transition quand
            // l'utilisateur choisira un nouveau créneau.
            if (apptSelect && this._wasOnTimeScreen) {
                this._wasOnTimeScreen  = false;
                this._selectedDayLabel = ''; // le jour précédemment choisi n'a plus cours
                this._clickedDayLabel  = ''; // idem — sera recapturé au prochain clic sur un jour
                this._clickedTimeLabel = ''; // idem — sera recapturé au prochain clic sur un créneau
                $(document).trigger('rk_ssa_returned_to_date');
            }
            if (timeSelect && !apptSelect) {
                this._wasOnTimeScreen = true;
            }

            // Le titre "لقاء مع [Nom]" (.mdc-card-header .md-title — voir
            // _captureEventName() pour le détail du markup réel) est
            // présent en haut de tous les écrans SSA, quel que soit l'écran
            // affiché en dessous. On le capture dès qu'il apparaît pour
            // alimenter RKBookingState.event_name — utilisé par
            // booking-summary.js sur l'écran résumé final (étape 6, natif
            // RiadaKids, pas SSA) et synchronisé au serveur par
            // booking-ssa.js::_syncEventName() pour affichage du nom du
            // coach sur la carte "لقاءاتي القادمة".
            this._captureEventName(doc);

            if (apptSelect) {
                this._injectHeaderBlock(
                    doc, apptSelect, 'h1.date-select-headline.focus-target',
                    'اختر يوماً لعرض الأوقات المتاحة',
                    'جميع الأوقات بتوقيتك المحلي'
                );
            }

            if (timeSelect && !apptSelect) {
                // CORRECTION (demande utilisateur) — le titre/sous-titre
                // injecté ici ("اختر وقتاً للحجز") s'affichait cassé/tronqué
                // à l'écran (voir capture fournie). Sur demande explicite,
                // ce bloc est retiré sur l'écran créneaux : on n'appelle
                // plus _injectHeaderBlock ici. Le titre natif SSA
                // (h2.md-headline.focus-target) reste également masqué
                // (voir _css(), sélecteur inchangé) pour ne rien laisser
                // apparaître à sa place.
                //
                // CORRECTION (bug signalé — retour رجوع affichait un
                // "deuxième calendrier") — cet appel injectait une PHOTO
                // FIGÉE du calendrier (snapshot HTML, pointer-events:none)
                // par-dessus le vrai bouton natif SSA "الرجوع", avec un
                // proxy cliquable recouvrant toute la photo. Résultat : un
                // clic n'importe où sur cette photo ne sélectionnait jamais
                // un jour (la photo n'est pas interactive) — il ne pouvait
                // que redéclencher le bouton retour, renvoyant l'utilisateur
                // au VRAI calendrier interactif SSA, perçu comme un "second"
                // calendrier apparu après un clic qui semblait porter sur le
                // premier. C'est exactement le double-calendrier que ce
                // module doit éviter (voir doc d'en-tête : aucune
                // reconstruction de calendrier, aucune photo faisant office
                // de calendrier). Suppression de l'injection : le vrai
                // bouton natif SSA "الرجوع" reste simplement visible et
                // fonctionnel tel quel sur cet écran (voir règle CSS
                // correspondante, désactivée plus bas) — comportement natif
                // SSA, privilégié ici conformément à la consigne de
                // stabilité.
                // this._injectCalendarSnapshot(doc, timeSelect); // désactivé — voir commentaire ci-dessus

                // CORRECTION (bug signalé, "الموعد" toujours vide) —
                // analyse du bundle SSA réel : un clic sur un créneau
                // (méthode selectTime()) pose start_date dans le store SSA
                // ET déclenche IMMÉDIATEMENT $emit('nextStep') — il n'existe
                // AUCUNE fenêtre où le bouton reste affiché "sélectionné"
                // (aria-pressed / outlined) pendant que .time-select est
                // encore dans le DOM. L'ancienne approche (relire le DOM
                // 50ms après la mutation, dans _captureChosenSlot) arrivait
                // donc systématiquement trop tard : .time-select avait déjà
                // disparu, remplacé par l'écran Customer Information.
                // Solution : capturer le libellé du bouton cliqué AU
                // MOMENT MÊME du clic (phase de capture, avant que Vue ne
                // détruise l'écran), voir _bindTimeSlotClickCapture.
                this._bindTimeSlotClickCapture(timeSelect);
            }

            if (apptSelect) {
                // CORRECTION (bug signalé, jour manquant dans "الموعد") —
                // même cause racine que pour l'heure (voir plus haut,
                // _bindTimeSlotClickCapture) : analyse du bundle SSA réel
                // (méthode selectDate()) confirme qu'un clic sur un jour ne
                // fait qu'émettre selectDate — le composant parent réagit
                // aussitôt en rechargeant les créneaux du jour, ce qui
                // recrée le DOM du calendrier et détruit toute marque
                // "sélectionné" avant qu'un scan différé (MutationObserver)
                // ne puisse la lire de façon fiable. _captureSelectedDayLabel
                // (scan après coup) arrivait donc parfois trop tard — même
                // symptôme observé sur "١٥:٤٥" affiché sans le jour.
                // Solution identique : écouter le clic EN PHASE DE CAPTURE,
                // avant le handler Vue interne de SSA (voir
                // _bindCalendarDayClickCapture) — le vrai aria-label du
                // bouton (format natif SSA "dddd MMMM Do YYYY", ex.
                // "Sunday August 19th 2026") est déjà présent statiquement
                // sur chaque bouton jour, disponible dès avant le clic.
                this._bindCalendarDayClickCapture(apptSelect);

                // Capturé en dernier sur l'écran calendrier : le jour que
                // l'utilisateur vient de cliquer est déjà marqué "selected"
                // par SSA dans son propre DOM au moment où la mutation qui
                // déclenche ce passage est traitée. Conservé en repli
                // (voir _captureSelectedDayLabel) pour le cas où le clic
                // n'aurait pas encore été observé à cet instant précis.
                this._captureSelectedDayLabel(doc, apptSelect);

                // CORRECTION (demande utilisateur) — snapshot du calendrier
                // AVANT que SSA ne le détruise en passant à .time-select
                // (voir _injectCalendarSnapshot plus bas). Capturé ici, sur
                // le tout dernier écran où .appt-select existe encore,
                // juste avant que le clic sur un jour ne fasse basculer SSA
                // vers .time-select.
                this._captureCalendarSnapshot(apptSelect);
            }

            // Écran "Customer Information" (nom + email) — apparaît après
            // le choix d'un créneau, sur sa propre carte .customer-information-container.
            // Ni .appt-select ni .time-select ne sont présents à cet instant :
            // c'est un écran à part entière, structurellement indépendant
            // des deux précédents.
            this._autofillCustomerInfo(doc);

            // AJOUT (demande utilisateur — suppression du timeout fixe) —
            // détecte le vrai état d'erreur affiché par SSA lui-même après
            // handleSaveAppointment() (voir doc de _bindErrorObserver),
            // pour remplacer l'ancien timeout arbitraire par le signal réel.
            this._bindErrorObserver(doc);
        },

        // ═══════════════════════════════════════════════════════════════
        // ÉCRAN CUSTOMER — préremplissage silencieux (SANS soumission) du
        // formulaire nom/email SSA, lecture du créneau choisi avant toute
        // confirmation, et unique point d'entrée pour le vrai clic natif
        // SSA (submitNativeBooking, déclenché exclusivement par le clic
        // utilisateur sur تأكيد الحجز — voir étape 8/10 pour la justification
        // de cette seule exception à la règle "présentation uniquement").
        // ═══════════════════════════════════════════════════════════════

        /**
         * Remplit automatiquement (SANS soumettre) le formulaire SSA natif
         * "Customer Information" (نom/email) avec les données WooCommerce
         * de l'utilisateur connecté (rkConfig.customerName/customerEmail —
         * voir Assets.php), pour que l'utilisateur ne voie jamais cet écran.
         *
         * IMPORTANT (nouveau flux 4-états) : ce module ne clique plus tout
         * seul sur le bouton natif SSA "حجز هذا الموعد". Dès que les champs
         * sont valides et prêts, on considère que l'utilisateur a "choisi son
         * créneau" et on bascule l'affichage vers l'écran récap RiadaKids
         * natif (Capture 3 — "كل شيء جاهز لنؤكد الحجز"). C'est SEULEMENT le
         * clic de l'utilisateur sur #rk-confirm-booking (booking-confirmation.js)
         * qui déclenchera le vrai clic SSA via RKSSAOverlay.submitNativeBooking().
         *
         * Vue.js ne réagit PAS à une simple affectation `input.value = x` :
         * son v-model interne écoute l'événement natif 'input'. On doit
         * donc déclencher cet événement manuellement après avoir posé la
         * valeur, sinon SSA continue de voir les champs comme vides côté
         * validation et refuse la soumission le moment venu.
         *
         * Protection anti-boucle : une fois rempli pour cette instance de
         * formulaire, on marque le conteneur (.rk-autofilled) pour ne pas
         * ré-remplir à chaque mutation suivante déclenchée par notre propre
         * saisie, et on garde une référence au conteneur/document pour le
         * clic différé (voir submitNativeBooking).
         */
        _autofillCustomerInfo: function (doc) {
            if (!window.rkConfig) return;

            const container = doc.querySelector('.customer-information-container');
            if (!container || container.classList.contains('rk-autofilled')) return;

            const nameInput  = container.querySelector('input[name="name"]');
            const emailInput = container.querySelector('input[name="email"]');
            if (!nameInput || !emailInput) return;

            container.classList.add('rk-autofilled'); // avant tout, pour éviter un re-traitement

            // CORRECTION (audit) — le préremplissage est OPTIONNEL et ne doit
            // JAMAIS conditionner le passage à l'écran CUSTOMER RiadaKids.
            // Avant : `if (!name || !email) return;` empêchait purement et
            // simplement rk_ssa_slot_chosen de partir quand rkConfig ne
            // fournissait pas encore nom/email (utilisateur invité, profil
            // incomplet, etc.) — l'utilisateur restait bloqué sur l'écran
            // SSA masqué sans que rien ne s'affiche. Le préremplissage, s'il
            // a des données, reste une pure amélioration UX ; son absence
            // ne bloque plus rien. Les vraies valeurs finales viennent de
            // toute façon des champs VISIBLES (#rk-customer-name/-email,
            // étape 6) via applyCustomerFields() juste avant submission.
            const name  = (window.rkConfig && window.rkConfig.customerName)  || '';
            const email = (window.rkConfig && window.rkConfig.customerEmail) || '';
            if (name && email) {
                this._setVueInputValue(nameInput, name);
                this._setVueInputValue(emailInput, email);
            }

            // CORRECTION — masquage direct en JS (filet de sécurité, en plus
            // de la règle CSS :has() qui peut ne pas être supportée par tous
            // les navigateurs). Masque la vraie carte .mdc-card entière —
            // formulaire ET bouton natif "حجز هذا الموعد" — pour que
            // l'utilisateur ne voie jamais cet écran, le récap RiadaKids
            // prenant le relais visuellement (rk_ssa_slot_chosen, plus bas).
            const card = container.closest('.mdc-card');
            if (card) card.style.display = 'none';

            // Log au niveau technique uniquement — jamais le nom ni l'email
            // réellement injectés (voir consigne étape 23 : pas de données
            // personnelles/sensibles dans les logs, même en DEBUG).
            window.RiadaKidsWizard.Utils.log('customer form displayed');

            // Référence conservée pour le clic différé déclenché par
            // l'utilisateur (voir submitNativeBooking, appelé depuis
            // booking-confirmation.js) ET pour applyCustomerFields() qui
            // écrira les valeurs finales (issues des champs visibles
            // RiadaKids) dans ce même formulaire juste avant le submit.
            this._pendingDoc       = doc;
            this._pendingContainer = container;

            // Capture la date/heure choisie par l'utilisateur AVANT toute
            // confirmation SSA (le vrai signal de succès SSA — postMessage
            // ssaType:"appointment", voir bindSsaAppointmentEvent — ne
            // partira qu'après le clic réel, plus tard) et prévient le
            // reste du wizard que le récap peut s'afficher (Capture 3).
            // Ce déclenchement est INCONDITIONNEL : rk_ssa_slot_chosen ne
            // signifie que "date + heure choisies", jamais "formulaire
            // rempli" (voir doc d'en-tête de booking-ssa.js).
            const self = this;
            setTimeout(function () {
                self._captureChosenSlot(doc);
                $(document).trigger('rk_ssa_slot_chosen');
            }, 50);
        },

        /**
         * CORRECTION (bug signalé) — écoute les clics sur les boutons de
         * jour EN PHASE DE CAPTURE, avant que le handler Vue interne de
         * SSA (@click="selectDate") ne recharge les créneaux du jour et
         * ne détruise toute marque "sélectionné" du calendrier (même
         * cause racine que _bindTimeSlotClickCapture ci-dessous — voir sa
         * doc). Le vrai aria-label natif SSA (format "dddd MMMM Do YYYY")
         * est déjà présent sur chaque bouton jour AVANT le clic — capturé
         * ici pour rester fiable même si SSA reconstruit le DOM
         * immédiatement après.
         *
         * Un seul listener par instance de .appt-select (marquage
         * .rk-day-click-bound), pour éviter l'accumulation à chaque
         * mutation observée par le MutationObserver (_observe).
         */
        _bindCalendarDayClickCapture: function (apptSelect) {
            if (apptSelect.dataset.rkDayClickBound) return;
            apptSelect.dataset.rkDayClickBound = '1';

            const self = this;
            apptSelect.addEventListener('click', function (e) {
                const btn = e.target.closest('.book-day button');
                if (!btn) return;

                const label = btn.getAttribute('aria-label') || btn.textContent.trim();
                if (label) {
                    self._clickedDayLabel = label;
                }
            }, true); // phase de capture — s'exécute avant le handler Vue interne de SSA
        },

        /**
         * CORRECTION (bug signalé) — écoute les clics sur les boutons de
         * créneau EN PHASE DE CAPTURE (troisième argument `true` de
         * addEventListener), c'est-à-dire AVANT que le propre handler Vue
         * de SSA (@click="selectTime") ne s'exécute et ne déclenche la
         * destruction immédiate de .time-select. Le libellé du bouton
         * cliqué (ex. "15:45") est stocké dans this._clickedTimeLabel,
         * lu ensuite par _captureChosenSlot() — plus fiable qu'un nouveau
         * scan du DOM après coup, puisque l'écran n'existe déjà plus à ce
         * moment (voir doc plus haut).
         *
         * Un seul listener par instance de .time-select (marquage
         * .rk-time-click-bound), pour éviter l'accumulation à chaque
         * mutation observée par le MutationObserver (_observe).
         */
        _bindTimeSlotClickCapture: function (timeSelect) {
            if (timeSelect.dataset.rkTimeClickBound) return;
            timeSelect.dataset.rkTimeClickBound = '1';

            const self = this;
            timeSelect.addEventListener('click', function (e) {
                const btn = e.target.closest('ul.time-listing li .mdc-button');
                if (!btn) return;

                const label = btn.querySelector('.mdc-button__label');
                const text  = label ? label.textContent.trim() : btn.textContent.trim();
                if (text) {
                    self._clickedTimeLabel = text;
                }
            }, true); // phase de capture — s'exécute avant le handler Vue interne de SSA
        },

        /**
         * Extrait la date/heure choisie par l'utilisateur. Utilise en
         * priorité this._clickedTimeLabel — capturé au moment exact du
         * clic par _bindTimeSlotClickCapture (voir sa doc : .time-select
         * disparaît immédiatement après le clic, donc relire le DOM après
         * coup échoue systématiquement). Repli sur l'ancien scan DOM
         * (aria-pressed / outlined) au cas où un futur changement de SSA
         * réintroduirait un état "sélectionné" visible. Alimente
         * RKBookingState.appointment_datetime pour que booking-summary.js
         * (étape 6, déjà existant) affiche la bonne valeur sans attendre
         * l'event ssa_appointment_booked.
         *
         * CORRECTION (bug signalé, jour manquant) — même priorité pour le
         * jour : this._clickedDayLabel (capturé au clic par
         * _bindCalendarDayClickCapture) passe avant this._selectedDayLabel
         * (ancien scan différé, voir _captureSelectedDayLabel), pour la
         * même raison que pour l'heure — le DOM du calendrier peut déjà
         * avoir été reconstruit par SSA au moment où ce scan différé
         * s'exécute. Aucune donnée recalculée : uniquement relue.
         */
        _captureChosenSlot: function (doc) {
            if (!window.RKBookingState) return;

            const selectedTimeBtn =
                doc.querySelector('.time-select ul.time-listing li .mdc-button[aria-pressed="true"]') ||
                doc.querySelector('.time-select ul.time-listing li .mdc-button--outlined');

            const dayLabel  = this._clickedDayLabel || this._selectedDayLabel || '';
            const timeLabel = this._clickedTimeLabel
                || (selectedTimeBtn ? selectedTimeBtn.textContent.trim() : '');

            if (timeLabel) {
                // Log au niveau technique uniquement — jamais l'heure/date
                // en clair (voir consigne étape 23).
                window.RiadaKidsWizard.Utils.log('time selected');
                window.RKBookingState.appointment_datetime = [dayLabel, timeLabel].filter(Boolean).join(' — ');
            }
        },

        /**
         * Met à jour les champs nom/email cachés du formulaire SSA natif
         * avec les valeurs éventuellement modifiées par l'utilisateur dans
         * les champs VISIBLES de l'étape 6 RiadaKids (#rk-customer-name /
         * #rk-customer-email). Appelée juste avant submitNativeBooking()
         * (voir booking-confirmation.js) — jamais avant, pour ne jamais
         * committer une valeur que l'utilisateur pourrait encore modifier.
         * Retourne false si le formulaire caché n'est plus disponible
         * (ex. iframe rechargée entre-temps) : le caller doit alors
         * afficher une erreur plutôt que de soumettre des données obsolètes.
         */
        applyCustomerFields: function (name, email) {
            if (!this._pendingDoc || !this._pendingContainer) return false;

            const nameInput  = this._pendingContainer.querySelector('input[name="name"]');
            const emailInput = this._pendingContainer.querySelector('input[name="email"]');
            if (!nameInput || !emailInput) return false;

            this._setVueInputValue(nameInput, name);
            this._setVueInputValue(emailInput, email);
            return true;
        },

        /**
         * CORRECTION (bug signalé) — retour RiadaKids étape 6 → étape 5.
         *
         * Symptôme : au clic sur "‹ رجوع" (étape 6), l'iframe SSA restait
         * affichée sur son écran "Customer Information" — celui-là même
         * que RKSSAOverlay masque en permanence via CSS
         * (.customer-information-container.rk-autofilled, voir _css())
         * pour que l'utilisateur ne le voie jamais pendant le flux normal.
         * booking-navigation.js::goToStep(5) ne fait que réafficher
         * l'iframe RiadaKids telle quelle — il ne fait JAMAIS naviguer
         * l'iframe SSA elle-même en arrière. Résultat : l'utilisateur se
         * retrouvait face à un écran vide (le vrai contenu SSA affiché
         * dessous était justement celui masqué par notre CSS).
         *
         * Correction : avant de réafficher l'étape 5, on déclenche le VRAI
         * bouton retour natif SSA de l'écran Customer Information (même
         * mécanisme que _findBackButton pour l'écran créneaux — repéré par
         * son label textuel natif "الرجوع", pas une classe MDC générique).
         * SSA gère alors lui-même la navigation interne vers .time-select
         * (l'écran créneaux du jour déjà choisi), qui redevient visible
         * puisque notre masquage ne cible que
         * .customer-information-container — jamais .time-select.
         *
         * Appelée UNIQUEMENT par booking-navigation.js::goToStep(), au
         * moment précis où l'on quitte l'étape 6 vers l'étape 5 — jamais
         * automatiquement, jamais par un minuteur.
         */
        returnToTimeSelect: function () {
            if (!this._pendingDoc) return; // rien à faire : écran Customer Information jamais atteint

            const backBtn = this._findBackButtonInDoc(this._pendingDoc);
            if (backBtn) {
                window.RiadaKidsWizard.Utils.log('returning to time select');
                backBtn.click();
                // CORRECTION — informe la machine à états (booking-ssa.js)
                // que le retour ramène à TIME (créneaux du jour déjà
                // choisi), PAS à DATE (calendrier) : ce n'est pas le même
                // event que rk_ssa_returned_to_date (bouton "الرجوع" de
                // l'écran créneaux, qui ramène bien au calendrier). Ici,
                // SSA revient d'un cran seulement — de Customer Information
                // à .time-select — le jour reste choisi.
                $(document).trigger('rk_ssa_returned_to_time');
            }

            // La référence au formulaire Customer Information n'est plus
            // valable après ce retour (SSA va le re-render depuis zéro à
            // la prochaine sélection de créneau, avec un nouveau conteneur
            // DOM) — _autofillCustomerInfo() le recapturera normalement à
            // ce moment-là (voir _syncState).
            this._pendingDoc       = null;
            this._pendingContainer = null;
            this._submitted        = false; // nouveau cycle : un futur clic doit pouvoir retenter
            this._attempted        = false;
        },

        /**
         * CORRECTION (bug persistant après premier correctif) — repère le
         * vrai bouton natif SSA "الرجوع" n'importe où dans le document
         * iframe, plutôt que seulement à l'intérieur de la carte
         * .mdc-card contenant le formulaire Customer Information.
         *
         * Analyse du bundle SSA réel (composant CustomerInformation,
         * booking-app-new/dist/static/js/app.js) : ce bouton retour est
         * rendu par un composant carte partagé (foxy-card-header /
         * foxy-card-actions), dont la structure DOM exacte — quelle carte
         * l'englobe, si elle correspond à .mdc-card — n'est pas garantie
         * identique à celle de l'écran créneaux. Chercher uniquement dans
         * closest('.mdc-card') pouvait donc échouer à trouver le bouton
         * (retournant null, donc returnToTimeSelect() ne cliquait rien —
         * exactement le symptôme "page vide" persistant signalé).
         *
         * Recherche élargie à tout le document : le libellé natif "الرجوع"
         * reste le seul repère fiable, commun à tous les écrans SSA — pas
         * une classe MDC générique réutilisée partout ailleurs dans SSA.
         */
        _findBackButtonInDoc: function (doc) {
            const buttons = doc.querySelectorAll('button');
            for (let i = 0; i < buttons.length; i++) {
                const btn = buttons[i];

                // Filet de sécurité : ignore un bouton déjà traité/masqué
                // par _injectCalendarSnapshot lors d'un passage précédent
                // sur l'écran créneaux (marqué .rk-back-to-calendar-instance,
                // masqué en style inline — voir _injectCalendarSnapshot).
                // À ce stade (écran Customer Information), ce cas ne
                // devrait normalement jamais se produire — gardé par
                // prudence.
                if (btn.classList.contains('rk-back-to-calendar-instance')) continue;

                const label = btn.querySelector('.mdc-button__label');
                const text  = label ? label.textContent.trim() : btn.textContent.trim();
                if (text === 'الرجوع') {
                    return btn;
                }
            }
            return null;
        },

        /**
         * Déclenche le VRAI clic sur le bouton natif SSA "حجز هذا الموعد".
         * Appelé UNE SEULE FOIS, exclusivement depuis booking-confirmation.js
         * quand l'utilisateur clique lui-même sur #rk-confirm-booking (étape 6
         * RiadaKids, Capture 3 → 4). C'est ce clic — et lui seul — qui fait
         * réellement exister le rendez-vous côté SSA.
         *
         * Retourne true si le clic a pu être déclenché, false sinon (le
         * caller doit alors afficher une erreur plutôt que de laisser
         * l'utilisateur croire que la réservation est en cours).
         *
         * CORRECTION (bug signalé — clic "تأكيد الحجز" → erreur immédiate,
         * alors que SSA finissait par confirmer le RDV après un retour) —
         * cette méthode retournait TOUJOURS true, même quand
         * _clickBookingSubmit() ne trouvait aucun bouton à cliquer (carte
         * SSA pas encore montée, libellé du bouton différent, DOM pas
         * encore prêt) : elle ne faisait alors RIEN, mais
         * booking-confirmation.js croyait le clic déclenché avec succès et
         * attendait indéfiniment (jusqu'au filet de sécurité de 30s) le
         * signal SSA qui ne pouvait jamais arriver puisque rien n'avait
         * réellement été cliqué. _clickBookingSubmit() renvoie désormais
         * si elle a réellement trouvé et cliqué le bouton — propagé ici
         * tel quel, sans changer le contrat existant (true/false) ni la
         * logique d'anti-double-clic.
         */
        submitNativeBooking: function () {
            if (!this._pendingDoc || !this._pendingContainer) return false;
            if (this._submitted) return true; // déjà cliqué, anti-double-clic

            // CORRECTION (bug signalé — écran figé indéfiniment malgré un
            // RDV réellement confirmé côté serveur, log à l'appui) —
            // _bindLateSuccessObserver dépendait de self._submitted, qui ne
            // devient true QUE si _clickBookingSubmit réussit du premier
            // coup (bouton trouvé et cliqué, OU écran post-réservation déjà
            // visible à cet instant précis). Or dans le cas observé, SSA
            // était encore en train de traiter la réservation au moment du
            // clic — ni le bouton "حجز هذا الموعد" ni l'écran post-
            // réservation n'existaient encore — donc _clickBookingSubmit
            // retournait false, _submitted restait à false pour toujours,
            // et l'observateur permanent (qui exige _submitted=true pour
            // s'activer) ne se déclenchait jamais, même une fois SSA
            // effectivement confirmé quelques secondes plus tard. On pose
            // donc désormais _attempted dès qu'un clic est TENTÉ — que la
            // tentative réussisse ou non — c'est ce flag, pas _submitted,
            // qui arme _bindLateSuccessObserver ci-dessous : le vrai signal
            // fiable est "l'utilisateur a cliqué تأكيد الحجز", pas "le
            // premier essai de clic natif a immédiatement abouti".
            this._attempted = true;

            const clicked = this._clickBookingSubmit(this._pendingDoc, this._pendingContainer);
            if (!clicked) return false; // rien cliqué : ne pas marquer submitted, l'utilisateur doit pouvoir réessayer

            // CORRECTION (bug signalé — écran figé sur "جار الحفظ" malgré un
            // RDV réellement confirmé) — la marque était posée en
            // classList.add('rk-submitted') sur _pendingContainer, un nœud
            // DOM concret. Or dans le cas où SSA affiche puis retire
            // immédiatement l'écran Customer Information (basculement très
            // rapide vers la confirmation, observé avec certains types SSA),
            // ce nœud devient isConnected=false avant même que la marque ne
            // soit utile : tout code relisant _pendingContainer.classList
            // après ce point (dont _bindLateSuccessObserver) le fait sur un
            // élément fantôme, sans aucun rapport avec le nouveau DOM que
            // SSA a effectivement rendu — la marque devenait invisible à
            // elle-même. On utilise désormais un simple flag booléen sur
            // l'overlay, indépendant du cycle de vie de n'importe quel nœud
            // DOM particulier.
            this._submitted = true;
            window.RiadaKidsWizard.Utils.log('booking submitted');
            return true;
        },

        /**
         * AJOUT (demande utilisateur — suppression du timeout fixe de
         * booking-confirmation.js) — observe le vrai état d'erreur que SSA
         * affiche lui-même dans l'iframe après un échec de sauvegarde
         * (créneau réellement pris entre-temps, erreur serveur SSA, etc.).
         *
         * Analyse du bundle SSA (booking-app-new/dist/static/js/app.js) :
         * quand handleSaveAppointment() échoue, SSA bascule son composant
         * racine sur un rendu "foxy-empty" — classe DOM stable ".md-empty-state"
         * — et écrit le texte affiché (label + description, soit exactement
         * errorHeading + errorMessage) dans #ariaLiveFoxyEmpty, un nœud
         * d'accessibilité que SSA maintient lui-même pour les lecteurs
         * d'écran. SSA ne notifie JAMAIS la page parente de cet échec par
         * postMessage (seul le succès l'est, voir bindSsaAppointmentEvent
         * dans booking-ssa.js) — c'est pourquoi ce module doit observer le
         * DOM directement, plutôt que d'attendre un signal qui n'arrivera
         * jamais.
         *
         * Un seul listener par document iframe (marquage .rk-error-bound
         * sur <body>), pour éviter les doublons à chaque mutation observée
         * par le MutationObserver déjà en place (_observe).
         */
        _bindErrorObserver: function (doc) {
            if (!doc.body || doc.body.dataset.rkErrorBound) return;
            doc.body.dataset.rkErrorBound = '1';

            const self = this;
            let confirmTimer = null;

            const check = function () {
                // Ne concerne que le flux réel de confirmation : après une
                // tentative de clic natif (self._attempted, posé dès la
                // tentative — voir submitNativeBooking — pas seulement en
                // cas de succès immédiat, pour les mêmes raisons que
                // _bindLateSuccessObserver ci-dessus) et tant qu'aucun
                // succès n'est déjà arrivé. En dehors de cette fenêtre, un
                // ".md-empty-state" peut apparaître pour d'autres raisons
                // (état vide normal d'un autre écran SSA) — non pertinent
                // ici.
                if (!self._attempted) {
                    if (confirmTimer) { clearTimeout(confirmTimer); confirmTimer = null; }
                    return;
                }

                const emptyState = doc.querySelector('.md-empty-state');

                // CORRECTION (bug signalé — RDV réellement confirmé côté
                // serveur, mais bouton réactivé côté client comme après un
                // échec) — SSA peut afficher .md-empty-state de façon
                // purement transitoire pendant son propre traitement interne
                // (un court re-render Vue entre deux écrans, vérifié en
                // conditions réelles : le message ariaLiveFoxyEmpty était
                // vide au moment de la détection, signe d'un état encore en
                // construction plutôt qu'une vraie erreur stabilisée). Réagir
                // à la toute première mutation qui matche produisait un faux
                // "rk_ssa_booking_failed" alors que SSA continuait son
                // travail en coulisses et confirmait quand même le RDV
                // quelques instants plus tard. On attend désormais que
                // .md-empty-state reste présent pendant un court délai
                // stable avant de le traiter comme une vraie erreur — s'il
                // disparaît entre-temps (SSA a changé d'écran normalement),
                // on annule silencieusement.
                if (!emptyState) {
                    if (confirmTimer) { clearTimeout(confirmTimer); confirmTimer = null; }
                    return;
                }
                if (confirmTimer) return; // déjà en attente de confirmation

                confirmTimer = setTimeout(function () {
                    confirmTimer = null;
                    // Revérifie l'état réel du DOM à l'issue du délai — pas
                    // seulement la variable capturée au moment du premier
                    // passage, qui peut être un nœud désormais détaché.
                    if (!self._attempted || !doc.querySelector('.md-empty-state')) return;

                    const live = doc.getElementById('ariaLiveFoxyEmpty');
                    const message = live && live.textContent ? live.textContent.trim() : '';

                    window.RiadaKidsWizard.Utils.log('ssa booking error observed');
                    $(document).trigger('rk_ssa_booking_failed', [{ message: message }]);

                    // Cet échec est définitif pour la tentative en cours :
                    // l'utilisateur doit pouvoir cliquer à nouveau "تأكيد الحجز"
                    // (booking-confirmation.js réactive déjà le bouton sur
                    // rk_ssa_booking_failed) — on retire donc le marquage
                    // pour autoriser un nouveau clic natif.
                    self._submitted = false;
                    self._attempted = false;
                }, 3000); // CORRECTION (re-observé en test réel) — le re-render transitoire de SSA peut prendre jusqu'à ~2s (mesuré : 2s exactement dans un cas réel), pas <1s comme initialement estimé. Marge portée à 3s pour rester au-dessus avec sécurité.
            };

            // Un MutationObserver dédié à .md-empty-state (le body entier
            // est déjà observé par _observe, mais celui-ci ne route que
            // vers _syncState — on branche ici un second observer léger,
            // scopé uniquement à cette détection, pour ne rien mélanger
            // avec le routage DATE/TIME/CUSTOMER existant).
            const observer = new MutationObserver(check);
            observer.observe(doc.body, { childList: true, subtree: true });
            check(); // passage immédiat, au cas où l'erreur soit déjà affichée

            this._bindLateSuccessObserver(doc);
        },

        /**
         * AJOUT (bug signalé — écran figé sur "جار الحفظ" alors que le RDV
         * est bien confirmé côté SSA) — symétrique de _bindErrorObserver,
         * mais pour le succès. Couvre le cas où SSA bascule sur son écran
         * post-réservation APRÈS que _clickWhenEnabled ait déjà abandonné
         * sa propre fenêtre de 3s (le bouton "حجز هذا الموعد" était encore
         * affiché-mais-désactivé à ce moment-là, puis SSA l'a retiré du DOM
         * en confirmant directement, sans jamais le réactiver) : ce cas
         * précis ne peut pas être capté par une fenêtre de temps fixe, quelle
         * qu'elle soit — seul un observateur permanent, actif tant que
         * self._submitted reste vrai, couvre tous les délais possibles.
         * Permanent par nécessité : rien ne borne le temps que SSA peut
         * prendre pour confirmer en interne après le clic.
         */
        _bindLateSuccessObserver: function (doc) {
            if (!doc.body || doc.body.dataset.rkLateSuccessBound) return;
            doc.body.dataset.rkLateSuccessBound = '1';

            const self = this;
            const check = function () {
                if (!self._attempted) return;
                if (window.RKBookingState && window.RKBookingState.appointment_id) return; // succès déjà reçu via postMessage — rien à faire

                if (!self._hasPostBookingActions(doc)) return;

                var bookingId = window.RKBookingState && window.RKBookingState.booking_id;
                if (!bookingId || !window.RKSSA || typeof window.RKSSA._startPolling !== 'function') return;

                window.RiadaKidsWizard.Utils.log('booking already confirmed by SSA (late observer) — resuming server poll');
                window.RKSSA._startPolling(bookingId);
            };

            const observer = new MutationObserver(check);
            observer.observe(doc.body, { childList: true, subtree: true });
            check();
        },

        /**
         * Pose la valeur d'un input contrôlé par Vue et déclenche les
         * événements nécessaires pour que le v-model interne de SSA
         * capte le changement (voir _autofillCustomerInfo pour le pourquoi).
         */
        _setVueInputValue: function (input, value) {
            const proto = Object.getPrototypeOf(input);
            const nativeSetter = Object.getOwnPropertyDescriptor(proto, 'value') &&
                Object.getOwnPropertyDescriptor(proto, 'value').set;

            // Passe par le setter natif de HTMLInputElement plutôt que
            // input.value= directement : certains frameworks (Vue 2/3
            // selon la version) patchent la propriété 'value' sur
            // l'instance, ce qui ferait que le setter "patché" ignore
            // silencieusement notre affectation. Le setter natif du
            // prototype contourne ce patch.
            if (nativeSetter) {
                nativeSetter.call(input, value);
            } else {
                input.value = value;
            }

            input.dispatchEvent(new Event('input', { bubbles: true }));
            input.dispatchEvent(new Event('change', { bubbles: true }));
            input.dispatchEvent(new Event('blur', { bubbles: true }));
        },

        /**
         * Simule un clic sur le VRAI bouton natif SSA "حجز هذا الموعد" (pas
         * un submit de formulaire — le bouton est type="button", géré en
         * interne par le handler Vue v() de CustomerInformation, qui fait
         * lui-même la validation SSA (q()) puis appelle l'action Vuex
         * saveNewAppointment()/saveAppointment() déjà existante de SSA.
         * Ce clic reste donc l'unique action de réservation — on ne
         * contourne ni ne duplique rien de ce cheminement natif.
         *
         * CORRECTION (bug signalé, persistant) — recherche désormais le
         * bouton DANS LE DOCUMENT au moment même du clic
         * (doc.querySelector, portée large comme _findBackButtonInDoc),
         * au lieu de partir de `container` — la référence capturée par
         * _autofillCustomerInfo() au moment de l'autofill. Entre
         * l'autofill et le clic utilisateur, SSA peut avoir re-rendu ce
         * bloc (ex. watcher sur `information`, re-render Vue suite à la
         * saisie) : `container` reste alors une référence à un noeud déjà
         * détaché, closest('.mdc-card') échoue silencieusement, et rien
         * n'est cliqué — symptôme observé : "تأكيد الحجز" échoue
         * immédiatement, puis SSA confirme quand même après "رجوع" (le
         * clic natif de rattrapage sur l'écran normalement affiché,
         * lui, aboutit). Chercher dans `doc` directement, à l'instant du
         * clic, élimine cette fenêtre de référence périmée sans changer
         * ni le sélecteur ni le libellé recherché.
         *
         * Retourne explicitement si un bouton a réellement été trouvé ET
         * cliqué (true) ou non (false), pour que submitNativeBooking() ne
         * prétende jamais avoir déclenché la réservation quand ce n'est
         * pas le cas.
         */
        _clickBookingSubmit: function (doc, container) {
            const buttons = doc.querySelectorAll('.mdc-card__actions button');
            for (let i = 0; i < buttons.length; i++) {
                const label = buttons[i].querySelector('.mdc-button__label');
                if (!label || label.textContent.trim() !== 'حجز هذا الموعد') continue;

                // CORRECTION (bug signalé — "ce créneau n'est plus disponible"
                // alors qu'il est libre) : le bouton natif SSA est trouvé par
                // son libellé, mais peut encore être désactivé (disabled) un
                // court instant après l'auto-remplissage des champs — le temps
                // que la validation Vue interne de SSA s'exécute et active le
                // bouton. Un .click() JS sur un bouton HTML disabled ne
                // déclenche RIEN (aucun event, aucune erreur) : SSA ne
                // soumet jamais rien, aucun postMessage n'arrive, et le
                // filet de sécurité de booking-confirmation.js finit par
                // afficher le message d'indisponibilité 30s plus tard — un
                // faux négatif, sans lien avec la disponibilité réelle du
                // créneau. On attend ici que le bouton soit réellement
                // cliquable avant de le cliquer, sans jamais dupliquer la
                // logique de validation de SSA lui-même.
                return this._clickWhenEnabled(buttons[i]);
            }

            // CORRECTION (bug signalé — écran figé sur "جار الحفظ" alors que
            // le RDV est bien confirmé côté SSA) — SSA bascule parfois
            // directement sur son écran de confirmation SANS jamais avoir
            // affiché "حجز هذا الموعد" (comportement interne à SSA, hors de
            // notre contrôle : la boucle ci-dessus ne trouve alors jamais
            // rien à cliquer). Reconnaissable à ses boutons post-réservation
            // ("تحرير المعلومات", "إعادة جدولة", "إلغاء الموعد") — absents
            // de l'écran "حجز هذا الموعد", qui n'a qu'un seul bouton. Dans ce
            // cas, aucun postMessage ssaType:'appointment' n'arrivera jamais
            // (SSA ne l'émet qu'au moment du clic sur "حجز هذا الموعد" lui-
            // même — voir booking-ssa.js) : on relance donc directement le
            // polling serveur RiadaKids (RKSSA._startPolling), qui vérifie
            // le webhook déjà reçu côté serveur — indépendant du postMessage
            // manquant. Vérifié en conditions réelles sur riadakids.com :
            // le RDV existe bien en base à cet instant, seul l'affichage
            // restait bloqué faute de ce relais.
            if (this._hasPostBookingActions(doc)) {
                var bookingId = window.RKBookingState && window.RKBookingState.booking_id;
                if (bookingId && window.RKSSA && typeof window.RKSSA._startPolling === 'function') {
                    window.RiadaKidsWizard.Utils.log('booking already confirmed by SSA — resuming server poll');
                    window.RKSSA._startPolling(bookingId);
                    return true;
                }
            }

            return false;
        },

        /**
         * AJOUT — détecte l'écran natif SSA "post-réservation" (RDV déjà
         * confirmé) à ses boutons d'action caractéristiques, distincts de
         * l'écran "حجز هذا الموعد" qui n'a qu'un seul bouton de ce type.
         */
        _hasPostBookingActions: function (doc) {
            const labels = ['تحرير المعلومات', 'إعادة جدولة', 'إلغاء الموعد'];
            const buttons = doc.querySelectorAll('.mdc-card__actions button .mdc-button__label');
            let found = 0;
            for (let i = 0; i < buttons.length; i++) {
                if (labels.indexOf(buttons[i].textContent.trim()) !== -1) found++;
            }
            return found >= 2; // au moins deux libellés distincts = écran de succès, pas une coïncidence isolée
        },

        /**
         * Attend qu'un bouton natif SSA sorte de l'état disabled avant de le
         * cliquer réellement — sans jamais forcer l'état ni contourner la
         * validation SSA. Se contente d'observer disabled/aria-disabled sur
         * un intervalle court et borné ; si le bouton reste désactivé au-delà
         * de ce délai, ne clique rien et laisse le caller (submitNativeBooking)
         * retourner false, pour que booking-confirmation.js affiche l'erreur
         * immédiatement plutôt que d'attendre le timeout de 30s en pure perte.
         */
        _clickWhenEnabled: function (btn) {
            const isEnabled = function () {
                return !btn.disabled && btn.getAttribute('aria-disabled') !== 'true';
            };

            if (isEnabled()) {
                btn.click();
                return true;
            }

            const self = this;
            let attempts = 0;
            const maxAttempts = 15; // ~3s max (200ms x 15) — largement au-dessus des délais de validation Vue observés
            const timer = setInterval(function () {
                attempts++;
                if (isEnabled()) {
                    clearInterval(timer);
                    btn.click();
                    return;
                }
                if (attempts >= maxAttempts) {
                    clearInterval(timer);
                    // CORRECTION (bug signalé — écran figé sur "جار الحفظ"
                    // malgré un RDV réellement confirmé côté SSA) — le
                    // bouton "حجز هذا الموعد" est resté désactivé tout ce
                    // délai non pas parce qu'il allait s'activer, mais parce
                    // que SSA a directement basculé sur son écran de
                    // confirmation entre-temps (le bouton a disparu du DOM,
                    // remplacé par تحرير المعلومات/إعادة جدولة/إلغاء الموعد
                    // — isEnabled() sur un bouton retiré du DOM reste
                    // simplement toujours faux, sans jamais lever d'erreur).
                    // On vérifie donc cet état avant d'abandonner pour de
                    // bon, avec le même relais que _clickBookingSubmit.
                    var doc = self._pendingDoc || self._getIframeDoc();
                    if (doc && self._hasPostBookingActions(doc)) {
                        var bookingId = window.RKBookingState && window.RKBookingState.booking_id;
                        if (bookingId && window.RKSSA && typeof window.RKSSA._startPolling === 'function') {
                            window.RiadaKidsWizard.Utils.log('booking already confirmed by SSA (post-timeout) — resuming server poll');
                            window.RKSSA._startPolling(bookingId);
                        }
                    }
                }
            }, 200);

            // NOTE — submitNativeBooking() est synchrone par contrat existant
            // (voir sa doc et booking-confirmation.js qui lit son retour
            // immédiatement). Le cas normal (bouton déjà activé) reste
            // synchrone ci-dessus ; le cas différé (bouton pas encore activé)
            // clique dès que possible en arrière-plan sans bloquer l'appelant.
            // On retourne true ici : le clic est en file d'attente et se
            // produira dans les ~3s, donc booking-confirmation.js doit
            // continuer d'attendre le vrai signal SSA (postMessage) plutôt que
            // de considérer immédiatement l'échec — cohérent avec le filet de
            // sécurité existant de 30s, qui reste largement suffisant.
            return true;
        },

        /**
         * Capture le texte du titre "لقاء مع [Nom]" affiché par SSA dans
         * window.RKBookingState.event_name, si présent et disponible.
         * RKBookingState est défini dans la page PARENTE (booking-state.js),
         * pas dans le document iframe — ce script s'exécute côté parent
         * (voir _getIframeDoc), donc window ici référence bien le bon scope.
         *
         * CORRECTION (bug signalé — nom du coach jamais affiché malgré les
         * correctifs précédents sur le timing de synchronisation) — le vrai
         * markup SSA (vérifié en conditions réelles sur riadakids.com) est :
         *
         *   <div class="mdc-card-header md-card-header">
         *     <h2 class="md-title">لقاء مع نور الهدى عثمان</h2>
         *     ...
         *   </div>
         *
         * PAS h1.ssa-type-header — cette classe n'existe nulle part dans le
         * DOM SSA réel (vérification faite a posteriori sur le bundle SSA
         * en production) ; l'ancien sélecteur ne correspondait donc à AUCUN
         * élément, ce qui explique que event_name restait TOUJOURS vide,
         * quel que soit le point de synchronisation ajusté côté
         * booking-ssa.js (le vrai problème n'était jamais le TIMING de la
         * capture, mais le SÉLECTEUR lui-même qui ne capturait jamais rien).
         *
         * Repli sur h1.ssa-type-header conservé en second choix — coûte
         * rien et protège si une future version de SSA restaure cette
         * classe sur certains écrans.
         */
        _captureEventName: function (doc) {
            if (!window.RKBookingState) return;

            const header =
                doc.querySelector('.mdc-card-header .md-title, .md-card-header .md-title') ||
                doc.querySelector('h1.ssa-type-header');
            if (!header) return;

            const text = header.textContent.trim();
            if (text) {
                window.RKBookingState.event_name = text;
            }
        },

        /**
         * Lit (sans copier ni modifier) le libellé du jour actuellement
         * sélectionné dans le vrai calendrier SSA, pendant qu'il est encore
         * affiché (écran .appt-select). Alimente uniquement le TEXTE du
         * sous-titre de l'écran suivant (_injectHeaderBlock côté .time-select)
         * — aucune donnée de disponibilité n'est déduite ou recalculée ici,
         * on relit simplement ce que SSA affiche déjà lui-même.
         *
         * SSA marque le jour choisi comme "selected" au moment du clic,
         * juste avant de détruire .appt-select pour le remplacer par
         * .time-select — ce passage capture donc la bonne valeur.
         */
        _captureSelectedDayLabel: function (doc, apptSelect) {
            const selectedDay =
                apptSelect.querySelector('.book-day .selected') ||
                apptSelect.querySelector('.book-day button[aria-pressed="true"]') ||
                apptSelect.querySelector('.book-day button.selected');
            if (!selectedDay) return;

            const label = selectedDay.getAttribute('aria-label') || selectedDay.textContent.trim();
            if (label) {
                // Log au niveau technique uniquement : présence d'une
                // sélection, jamais la date elle-même en clair — le libellé
                // capturé sert uniquement à l'affichage RiadaKids, pas au log.
                window.RiadaKidsWizard.Utils.log('date selected');
                this._selectedDayLabel = label;
            }
        },

        /**
         * Capture (clonage HTML pur, lecture seule) le calendrier SSA réel
         * — `.monthly` — pendant qu'il existe encore dans le DOM, juste
         * avant que SSA ne le détruise en basculant vers `.time-select`.
         * Stocké en mémoire (this._calendarSnapshotHtml) pour être rejoué
         * visuellement sur l'écran créneaux (voir _injectCalendarSnapshot).
         * Pure lecture : aucune donnée de disponibilité n'est recalculée,
         * seul le balisage déjà rendu par SSA est recopié tel quel.
         */
        _captureCalendarSnapshot: function (apptSelect) {
            const monthly = apptSelect.querySelector('.monthly');
            if (monthly) {
                this._calendarSnapshotHtml = monthly.outerHTML;
            }
        },

        /**
         * CORRECTION (demande utilisateur, capture fournie) — affiche le
         * VRAI calendrier (capturé en snapshot sur l'écran précédent) à la
         * place de l'ancien bouton "الرجوع" mis en avant, sur l'écran
         * créneaux (.time-select).
         *
         * Le snapshot est un clone HTML figé (non interactif en lui-même :
         * les jours n'y sont pas cliquables individuellement, puisque SSA a
         * déjà détruit son propre calendrier et ses gestionnaires internes
         * à cet instant). Pour rester honnête envers l'utilisateur — un
         * calendrier affiché doit rester cliquable — on superpose par-dessus
         * le VRAI bouton natif SSA "الرجوع" (100% fonctionnel, gestionnaires
         * Vue intacts), rendu invisible mais cliquable sur toute la zone du
         * snapshot. Un clic n'importe où sur le calendrier affiché déclenche
         * donc ce vrai bouton natif, qui ramène SSA à son propre écran
         * calendrier interactif (où l'utilisateur peut alors vraiment
         * choisir un autre jour) — aucune reconstruction de calendrier
         * interactif côté RiadaKids, uniquement une photo + un vrai clic SSA.
         */
        _injectCalendarSnapshot: function (doc, timeSelect) {
            if (timeSelect.querySelector('.rk-calendar-snapshot')) return; // déjà injecté

            // CORRECTION (bug signalé, page vide) — si aucun snapshot n'a
            // été capturé (ex. .appt-select n'a jamais été observé avant
            // ce passage à .time-select — cas rare mais possible sur un
            // retour où SSA reconstruit son DOM différemment), ne PAS
            // injecter un wrap vide par-dessus le bouton retour natif :
            // cela masquerait ce bouton sans rien montrer à la place,
            // laissant l'écran visuellement vide. On abandonne proprement
            // et le vrai bouton natif SSA reste visible/fonctionnel tel quel.
            if (!this._calendarSnapshotHtml) return;

            const backButton = this._findBackButton(timeSelect);
            if (!backButton) return; // pas de vrai bouton natif disponible → rien à superposer, on abandonne proprement

            // CORRECTION STRUCTURELLE (bug persistant "page vide au
            // retour") — l'ancienne approche marquait ce bouton avec la
            // classe CSS .rk-back-to-calendar, masquée GLOBALEMENT et
            // DÉFINITIVEMENT par une règle CSS statique (voir _css()).
            // Problème : ce bouton "الرجوع" peut être rendu par un
            // composant PARTAGÉ entre plusieurs écrans SSA (confirmé par
            // analyse du bundle — même composant carte/header que l'écran
            // Customer Information). Une fois marqué invisible via une
            // classe globale, si SSA réutilise CE MÊME élément DOM sur un
            // écran où le bouton doit rester visible et fonctionnel
            // normalement (ex. après un retour, avant que notre snapshot
            // ne soit réinjecté), il restait invisible sans que rien ne
            // le remplace — écran vide.
            //
            // Fix : masquage par STYLE INLINE, appliqué UNIQUEMENT à cette
            // instance précise du bouton, au moment précis où on l'enrobe
            // d'un proxy cliquable. Si SSA recrée un nouvel élément bouton
            // à la prochaine mutation (ce qu'il fait normalement), ce
            // nouvel élément n'hérite d'aucun style inline — il reste
            // visible par défaut tant que ce code ne l'a pas explicitement
            // traité à son tour.
            backButton.style.position = 'absolute';
            backButton.style.width    = '1px';
            backButton.style.height   = '1px';
            backButton.style.padding  = '0';
            backButton.style.margin   = '-1px';
            backButton.style.overflow = 'hidden';
            backButton.style.clip     = 'rect(0, 0, 0, 0)';
            backButton.style.whiteSpace = 'nowrap';
            backButton.style.border   = '0';
            backButton.classList.add('rk-back-to-calendar-instance'); // marqueur non stylé, pour retrouver/nettoyer cette instance si besoin

            const wrap = doc.createElement('div');
            wrap.className = 'rk-calendar-snapshot';
            wrap.innerHTML = this._calendarSnapshotHtml;
            wrap.setAttribute('aria-hidden', 'true'); // purement visuel, le vrai contrôle accessible reste backButton

            const proxy = doc.createElement('button');
            proxy.type = 'button';
            proxy.className = 'rk-calendar-snapshot__proxy';
            proxy.setAttribute('aria-label', 'الرجوع');
            proxy.addEventListener('click', function () {
                backButton.click();
            });

            wrap.appendChild(proxy);

            // Inséré au même endroit que l'ancien bouton retour, pour
            // conserver l'ordre visuel (order:2, voir _css()).
            backButton.parentNode.insertBefore(wrap, backButton);
        },

        /**
         * Repère le vrai bouton natif SSA "الرجوع" par son label textuel
         * (pas une classe MDC générique, réutilisée ailleurs dans SSA —
         * même approche que _clickBookingSubmit).
         */
        /**
         * CORRECTION (bug persistant, page vide au retour) — recherchait
         * auparavant "الرجوع" UNIQUEMENT à l'intérieur de timeSelect
         * (.time-select). Analyse du bundle SSA (déjà confirmée pour
         * l'écran Customer Information, voir _findBackButtonInDoc) : ce
         * bouton peut être rendu par un composant carte/header PARTAGÉ,
         * potentiellement en dehors du conteneur .time-select lui-même —
         * en particulier lors d'un retour programmatique (R(), qui
         * recharge les créneaux de façon async avant d'émettre prevStep,
         * voir doc de returnToTimeSelect), où la structure DOM intermédiaire
         * diffère du premier affichage normal. Quand ce bouton n'était pas
         * trouvé, _injectCalendarSnapshot abandonnait silencieusement
         * (backButton === null), laissant l'écran incohérent — exactement
         * le symptôme de page vide signalé après un retour + nouvelle
         * sélection d'heure. Recherche élargie au document entier, comme
         * pour _findBackButtonInDoc.
         */
        _findBackButton: function (timeSelect) {
            const doc = timeSelect.ownerDocument || document;
            const buttons = doc.querySelectorAll('button');
            for (let i = 0; i < buttons.length; i++) {
                const btn = buttons[i];
                if (btn.classList.contains('rk-back-to-calendar-instance')) continue; // déjà traité par un passage précédent
                const label = btn.querySelector('.mdc-button__label');
                const text  = label ? label.textContent.trim() : btn.textContent.trim();
                if (text === 'الرجوع') {
                    return btn;
                }
            }
            return null;
        },

        /**
         * Injecte un vrai bloc HTML (icône <img> + titre + sous-titre) juste
         * avant le <h1>/<h2> natif SSA, au lieu d'un ::before/::after CSS.
         * Le titre natif est masqué (display:none) plutôt que vidé de son
         * texte — il reste dans le DOM pour l'accessibilité/le focus SSA
         * (tabindex="-1", focus-target), mais n'est plus affiché.
         *
         * title/subtitle sont fournis par l'appelant (_syncState) : texte
         * fixe pour l'écran calendrier, texte dynamique (date choisie, lue
         * depuis le DOM SSA — voir _captureSelectedDayLabel) pour l'écran
         * créneaux. Ce module reste une couche de présentation : il n'invente
         * ni ne recalcule aucune donnée de disponibilité.
         *
         * Idempotent PAR VALEUR : si le bloc existe déjà avec le MÊME texte,
         * on ne le recrée pas (évite un flicker à chaque mutation). S'il
         * existe avec un texte différent (ex. la date choisie vient d'être
         * capturée après un premier passage sans elle), on met à jour le
         * texte en place plutôt que de réinjecter tout le bloc.
         */
        _injectHeaderBlock: function (doc, root, titleSelector, title, subtitle) {
            const h1 = root.querySelector(titleSelector);
            if (!h1) return;

            const prev = h1.previousElementSibling;
            if (prev && prev.classList && prev.classList.contains('rk-date-header')) {
                const titleEl    = prev.querySelector('.rk-date-header__title');
                const subtitleEl = prev.querySelector('.rk-date-header__subtitle');
                if (titleEl && titleEl.textContent !== title) titleEl.textContent = title;
                if (subtitleEl && subtitleEl.textContent !== subtitle) subtitleEl.textContent = subtitle;
                return;
            }

            h1.style.display = 'none';

            const header = doc.createElement('div');
            header.className = 'rk-date-header';
            header.innerHTML =
                '<img class="rk-date-header__icon" ' +
                    'src="https://riadakids.com/wp-content/plugins/riadakids-booking/assets/images/rk-calendar-clock-icon.svg" ' +
                    'alt="" />' +
                '<h2 class="rk-date-header__title"></h2>' +
                '<p class="rk-date-header__subtitle"></p>';
            header.querySelector('.rk-date-header__title').textContent    = title;
            header.querySelector('.rk-date-header__subtitle').textContent = subtitle;

            h1.parentNode.insertBefore(header, h1);
        },

        // ═══════════════════════════════════════════════════════════════
        // PRÉSENTATION — feuille de style injectée dans le document
        // iframe (couche CSS pure, voir étape 10/18 pour son périmètre).
        // ═══════════════════════════════════════════════════════════════

        /**
         * Feuille de style injectée dans le document iframe.
         * Sélecteurs scopés sur la structure réelle confirmée :
         *   - .appt-select > .monthly (écran calendrier)
         *   - .time-select (écran créneaux — remplace .appt-select,
         *     ne coexiste jamais avec lui ; voir _syncState)
         * .rk-calendar-snapshot affiche le vrai calendrier SSA (capturé
         * avant sa destruction) à la place du bouton retour, sur l'écran
         * créneaux (voir _injectCalendarSnapshot).
         */
        _css: function () {
            return `
/* ── Base RTL ─────────────────────────────────────────────────────── */
html, body {
    text-align: right;
    font-family: inherit !important;
    padding: 0 !important;
    margin: 0 !important;
    background: #F0F4FF !important;
    min-height: 100% !important;
}

/* CORRECTION — le fond de page (derrière la carte calendrier blanche)
   doit être le bleu très clair de la maquette (Image 1), pas blanc.
   .app-wrapper / .booking-app sont les conteneurs racine SSA visibles
   dans le DOM réel (confirmé par inspection) — jusqu'ici aucun n'était
   stylisé, donc leur fond blanc par défaut restait visible tout autour
   de la carte calendrier (bug signalé). */
.app-wrapper,
.booking-app {
    background: #F0F4FF !important;
    min-height: 100% !important;
    box-sizing: border-box !important;
}

/* .booking-header (titre natif SSA "لقاء مع...", durée, fuseau horaire)
   reste VISIBLE — conservé sur demande explicite, en plus du bloc
   .rk-date-header (notre titre/sous-titre custom) qui s'affiche juste
   en dessous sur l'écran calendrier. Le bouton retour natif SSA
   (.back-button-wrapper, hors .booking-header) reste également visible
   nativement — aucun masquage appliqué dessus. */

/* ── Conteneur racine de l'étape date/heure ──────────────────────── */
.appt-select {
    display: flex !important;
    flex-wrap: wrap !important;
    flex-direction: column !important;
    justify-content: center !important;
    align-items: center !important;
    width: 100% !important;
    box-sizing: border-box !important;
    padding: 24px 16px !important;
}

/* CORRECTION — centrage vertical réel dans la hauteur de l'iframe.
   .appt-select avait déjà justify-content:center, mais ça ne centre que
   dans SA propre hauteur — sans hauteur pleine sur les conteneurs
   parents natifs SSA (.booking, .booking.current), le calendrier
   restait collé en haut/bas selon le contenu réel (bug signalé, voir
   Image 2 : carte poussée en bas à droite). */
.booking,
.booking.current {
    display: flex !important;
    flex-direction: column !important;
    min-height: 100% !important;
}

/* ── Titre : <h1>/<h2> natifs SSA masqués (voir _injectHeaderBlock),
       remplacés par le vrai bloc HTML .rk-date-header inséré juste
       avant eux. h1 = écran calendrier, h2.md-headline = écran créneaux. */
h1.date-select-headline.focus-target,
.time-select > h2.md-headline.focus-target {
    display: none !important;
}

.rk-date-header {
    display: flex !important;
    flex-direction: column !important;
    align-items: center !important;
    width: 100% !important;
    max-width: 320px !important;
    margin: 0 auto 20px auto !important;
    text-align: center !important;
    order: 1 !important;
}

.rk-date-header__icon {
    display: block !important;
    width: 60px !important;
    height: 60px !important;
    margin: 0 auto 12px auto !important;
}

.rk-date-header__title {
    box-sizing: border-box !important;
    width: 216px !important;
    height: 22px !important;
    max-width: 100% !important;
    font-family: 'Alexandria', inherit !important;
    font-weight: 700 !important;
    font-size: 14px !important;
    line-height: 160% !important;
    text-align: center !important;
    color: #5A7997 !important;
    margin: 0 auto !important;
    flex: none !important;
    order: 0 !important;
    flex-grow: 0 !important;
}

.rk-date-header__subtitle {
    box-sizing: border-box !important;
    width: 167px !important;
    height: 19px !important;
    max-width: 100% !important;
    font-family: 'Alexandria', inherit !important;
    font-weight: 500 !important;
    font-size: 12px !important;
    line-height: 160% !important;
    text-align: center !important;
    color: #5A7997 !important;
    margin: 12px auto 0 auto !important;
    flex: none !important;
    order: 1 !important;
    flex-grow: 0 !important;
}

/* ── Calendrier : cible UNIQUEMENT le conteneur racine ──────────────
   Card calendrier — spec Figma ──────────────────────────────────── */
   #ssa-booking-app .app-wrapper {
    background: transparent !important;
}
.appt-select > .monthly {
    box-sizing: border-box !important;
    display: flex !important;
    flex-direction: column !important;
    align-items: flex-start !important;
    padding: 16px !important;
    gap: 10px !important;
    width: 100% !important;
    min-height: 100% !important;
    max-width: 330px !important;
    margin: 0 !important;
    background: #FFFFFF !important;
    box-shadow: 0px 1px 2px -1px rgba(23, 47, 70, 0.2), 0px 1px 3px rgba(23, 47, 70, 0.05) !important;
    border-radius: 12px !important;
    flex: none !important;
    flex-grow: 0 !important;
    align-self: stretch !important;
}
.appt-select > .monthly > .title.monthly {
    display: flex !important;
    align-items: center !important;
    justify-content: space-between !important;
    width: 100% !important;
    padding: 8px 6px 0 !important;
}
.appt-select > .monthly .monthly-title {
    font-family: 'Alexandria', inherit !important;
    font-weight: 500 !important;
    font-size: 14px !important;
    line-height: 20px !important;
    color: #295177 !important;
    text-align: center !important;
    margin: 0 !important;
}
/* Boutons prev/next — spec Figma (identique sur les deux, y compris le
   miroir horizontal) */
.appt-select > .monthly .prev .mdc-icon-button,
.appt-select > .monthly .next .mdc-icon-button {
    box-sizing: border-box !important;
    display: flex !important;
    flex-direction: column !important;
    align-items: center !important;
    justify-content: center !important;
    padding: 8.72727px !important;
    gap: 5.82px !important;
    width: 31.94px !important;
    height: 31.94px !important;
    background: linear-gradient(318.96deg, #FF987B -1.9%, rgba(255, 194, 176, 0) 17.34%, #FFEBE5 87.36%), #FF4411 !important;
    background-blend-mode: soft-light, normal !important;
    box-shadow: 0px 0.727273px 1.45455px rgba(0, 0, 0, 0.3), 0px 1.45455px 4.36364px 1.45455px rgba(0, 0, 0, 0.15) !important;
    border-radius: 6px !important;
    transform: matrix(-1, 0, 0, 1, 0, 0) !important;
    flex: none !important;
    order: 2 !important;
    flex-grow: 0 !important;
}
.appt-select > .monthly .prev .mdc-icon-button .md-icon,
.appt-select > .monthly .next .mdc-icon-button .md-icon {
    color: #FFFFFF !important;
    font-size: 16px !important;
    /* Contre-miroir : l'icône flèche reste lisible malgré le transform
       du bouton parent (sinon la flèche elle-même serait inversée). */
    transform: matrix(-1, 0, 0, 1, 0, 0) !important;
}
.appt-select > .monthly .prev .mdc-icon-button[disabled],
.appt-select > .monthly .next .mdc-icon-button[disabled] {
    background: #FFD3C4 !important;
}
.appt-select > .monthly .week {
    display: flex !important;
    width: 100% !important;
    padding: 0 4px !important;
    margin: 0 !important;
    list-style: none !important;
}
.appt-select > .monthly .week li {
    width: 26px !important;
    height: 20px !important;
    flex: 1 !important;
    font-family: 'Alexandria', inherit !important;
    font-weight: 400 !important;
    font-size: 9px !important;
    line-height: 20px !important;
    display: flex !important;
    align-items: center !important;
    justify-content: flex-start !important;
    text-align: center !important;
    color: #8BA1B7 !important;
}
.appt-select > .monthly .calendar-days.monthly {
    display: grid !important;
    grid-template-columns: repeat(7, 1fr) !important;
    width: 100% !important;
    gap: 2px !important;
    padding: 0 4px 4px !important;
}
.appt-select > .monthly .book-day button {
    width: 100% !important;
    min-height: 32px !important;
    background: transparent !important;
    box-shadow: none !important;
    border-radius: 8px !important;
}
/* AJUSTEMENT (demande utilisateur v2) — cellules calendrier resserrées à
   24px (au lieu de 44px), pour un calendrier plus compact. */
.appt-select > .monthly .book-day button.md-whiteframe.md-whiteframe {
    min-width: 24px !important;
    width: 24px !important;
    margin: 0 !important;
}
.appt-select > .monthly .calendar-days.monthly {
    max-width: 290px;
}
.calendar-wrap.monthly {
    min-width: auto !important;
    flex: 1;
    width: 290px;
}
.calendar-days.monthly > div {
    width: 24px !important;
    min-width: 24px !important;
}
.appt-select > .monthly .week li {
    min-width: 24px !important;
}
.appt-select > .monthly .book-day button.disabled .md-body-1 span,
.appt-select > .monthly .book-day button[disabled] .md-body-1 span {
    box-sizing: border-box !important;
    width: 8px !important;
    height: 20px !important;
    font-family: 'Alexandria', inherit !important;
    font-weight: 400 !important;
    font-size: 12px !important;
    line-height: 20px !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    text-align: center !important;
    color: #8BA1B7 !important;
    flex: none !important;
    order: 0 !important;
    flex-grow: 0 !important;
}
.appt-select > .monthly .book-day button.selectable .md-body-1 span,
.appt-select > .monthly .book-day button:not([disabled]) .md-body-1 span {
    box-sizing: border-box !important;
    width: 15px !important;
    height: 20px !important;
    font-family: 'Alexandria', inherit !important;
    font-weight: 400 !important;
    font-size: 12px !important;
    line-height: 20px !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    text-align: center !important;
    color: #295177 !important;
    flex: none !important;
    order: 0 !important;
    flex-grow: 0 !important;
}
.appt-select > .monthly .book-day button.selectable:hover {
    background-color: #EAF3FB !important;
}
.appt-select > .monthly .book-day button.today .md-body-1 span {
    font-weight: 700 !important;
    color: #FF4411 !important;
}

/* ── Colonne créneaux (.time-select) — vraie structure SSA confirmée :
   .time-select-cols > .time-selection-col > (icône SVG, header, ul).
   CORRECTION : le conteneur racine s'appelle "time-select-cols" (sans
   "ion"), confirmé par inspection DevTools réelle — la sous-colonne
   individuelle, elle, s'appelle bien "time-selection-col" (avec "ion").
   Les deux classes coexistent avec des noms différents dans le vrai DOM
   SSA ; la confusion initiale entre les deux a empêché cette mise en
   page de s'appliquer (le sélecteur ne matchait jamais). */
.time-select .time-select-cols {
    display: flex !important;
    flex-direction: column !important;
    width: 288px !important;
    max-width: 100% !important;
    margin: 0 !important;
    gap: 16px !important;
}
.time-select .time-selection-col {
    width: 100% !important;
    max-width: 100% !important;
    padding: 0 !important;
}
/* CORRECTION (demande utilisateur) — style ajouté pour les colonnes
   créneaux multiples (matin/après-midi/soir) affichées côte à côte
   quand SSA en rend plusieurs pour un même jour. */
.md-layout .time-selection-col.md-layout {
    align-items: center !important;
    flex-direction: column !important;
    min-width: 25% !important;
}
.time-select .time-section-col-icon {
    display: flex !important;
    justify-content: center !important;
    margin: 0 0 8px 0 !important;
}
/* AJUSTEMENT (demande utilisateur v2) — badge icône dégradé bleu clair
   (au lieu d'une icône orange plate 32px), cohérent avec le design
   system des badges circulaires (.rk-summary-card__icon). */
.time-select .time-listing-icon {
    background: linear-gradient(134.42deg, rgba(215, 236, 250, 0.2) 0.5%, rgba(76, 149, 215, 0.2) 99.5%), #D7ECFA !important;
    box-shadow: 0px 0.857143px 1.71429px rgba(23, 47, 70, 0.25), 0px 1.71429px 5.14286px 1.71429px rgba(23, 47, 70, 0.05) !important;
    padding: 12px !important;
    border-radius: 12px !important;
}
.time-select .time-listing-icon .icon-path path {
    fill: #295177 !important;
}

.booking, .booking.current {
    width: 100%;
}
.time-select .time-selection-col-header {
    font-family: 'Alexandria', inherit !important;
    font-size: 15px !important;
    font-weight: 700 !important;
    color: #1d2129 !important;
    text-align: center !important;
    margin: 0 0 14px 0 !important;
    direction: rtl !important;
}
.time-select ul.time-listing {
    display: flex !important;
    flex-direction: column !important;
    gap: 10px !important;
    list-style: none !important;
    padding: 0 !important;
    margin: 0 !important;
}
.time-select ul.time-listing li .mdc-button {
    width: 100% !important;
    min-height: 48px !important;
    border: 1px solid #E3EEF7 !important;
    background-color: #FFFFFF !important;
    border-radius: 12px !important;
    box-shadow: 0px 1px 2px rgba(23, 47, 70, 0.06) !important;
}
.time-select ul.time-listing li .mdc-button .mdc-button__label {
    font-family: 'Alexandria', inherit !important;
    font-size: 15px !important;
    font-weight: 600 !important;
    color: #295177 !important;
    direction: ltr !important;
    display: inline-block !important;
    width: 100% !important;
    text-align: center !important;
}
.time-select ul.time-listing li .mdc-button--outlined,
.time-select ul.time-listing li .mdc-button[aria-pressed="true"] {
    border: 2px solid #FF4411 !important;
}

/* ── CORRECTION — Écran "Customer Information" (nom + email) masqué.
   BUG confirmé par capture réelle : cet écran natif SSA (sa carte
   .mdc-card entière, avec le formulaire déjà auto-rempli et le bouton
   natif "حجز هذا الموعد") s'affichait à l'utilisateur au lieu de rester
   invisible pendant que le récap RiadaKids (Capture 3, étape CUSTOMER)
   prend le relais. _autofillCustomerInfo() (JS) remplit bien les champs
   et déclenche rk_ssa_slot_chosen → bascule vers l'étape 6 RiadaKids,
   mais rien ne masquait visuellement cette carte SSA sous-jacente.
   La marque .rk-autofilled est déjà posée par _autofillCustomerInfo()
   dès que ce conteneur est traité — on l'utilise ici comme sélecteur
   pour masquer la carte entière (pas seulement le conteneur du form),
   afin que rien ne reste visible à cet écran. */
.customer-information-container.rk-autofilled {
    display: none !important;
}
.mdc-card:has(.customer-information-container.rk-autofilled) {
    display: none !important;
}

/* ── Écran créneaux (.time-select) — CORRECTION (bug signalé, "deuxième
   calendrier" au retour) — l'injection du snapshot figé
   (_injectCalendarSnapshot) est désactivée (voir booking-ssa-overlay.js,
   dans _syncState) : elle masquait le vrai bouton natif SSA "الرجوع" en
   style inline et le remplaçait visuellement par une photo non
   interactive du calendrier, dont le clic ne faisait que redéclencher ce
   même bouton retour — jamais une sélection de jour. Le vrai bouton natif
   SSA reste désormais simplement visible et cliquable tel quel : aucune
   règle ne le masque plus ici. Les règles ci-dessous ne stylent plus
   qu'un conteneur qui n'est plus injecté (JS) ; conservées telles quelles
   pour ne rien changer d'autre. ──────────────────── */

.rk-calendar-snapshot {
    position: relative !important;
    order: 2 !important;
    width: 100% !important;
    max-width: 330px !important;
    box-sizing: border-box !important;
}
/* Le snapshot est un clone de .monthly : mêmes règles visuelles que
   l'écran calendrier (voir bloc "Card calendrier" plus haut), simplement
   scopées sous .rk-calendar-snapshot au lieu de .appt-select. */
.rk-calendar-snapshot > .monthly {
    box-sizing: border-box !important;
    display: flex !important;
    flex-direction: column !important;
    align-items: flex-start !important;
    padding: 16px !important;
    gap: 10px !important;
    width: 100% !important;
    max-width: 330px !important;
    margin: 0 !important;
    background: #FFFFFF !important;
    box-shadow: 0px 1px 2px -1px rgba(23, 47, 70, 0.2), 0px 1px 3px rgba(23, 47, 70, 0.05) !important;
    border-radius: 12px !important;
    pointer-events: none !important; /* photo figée : aucun jour individuel n'est cliquable */
}
/* Le proxy cliquable recouvre tout le snapshot : un clic n'importe où
   sur le calendrier affiché déclenche le vrai bouton natif SSA "الرجوع"
   (voir _injectCalendarSnapshot), qui ramène à l'écran calendrier
   interactif réel. */
.rk-calendar-snapshot__proxy {
    position: absolute !important;
    inset: 0 !important;
    width: 100% !important;
    height: 100% !important;
    background: transparent !important;
    border: 0 !important;
    padding: 0 !important;
    margin: 0 !important;
    cursor: pointer !important;
    z-index: 1 !important;
}

/* ── Desktop : côte à côte ────────────────────────────────────────
       Colonne DROITE (RTL → premier bloc visuel) : icône + titre + sous-titre.
       Colonne GAUCHE : soit .monthly (écran calendrier, vrai DOM SSA),
                          soit .rk-calendar-snapshot (écran créneaux — le
                          snapshot du calendrier occupe la position du
                          calendrier réel). */
@media (min-width: 768px) {
    .appt-select,
    .time-select {
        display: flex !important;
        flex-direction: row !important;
        flex-wrap: nowrap !important;
        align-items: stretch !important;
        justify-content: center !important;
        gap: 32px !important;
        max-width: 720px !important;
        margin: 0 auto !important;
        width: 100%;
    }
    .rk-date-header {
        width: 240px !important;
        max-width: 240px !important;
        flex: 0 0 240px !important;
        margin: 0 !important;
        order: 1 !important;
        align-self: center !important;
        padding-left: 40px !important;
        border-left: 1px solid #8BA1B7 !important;
    }
    .appt-select > .monthly {
        order: 2 !important;
    }
    /* .time-select est LUI-MÊME le conteneur flex en desktop (voir plus
       haut) — le sélecteur générique .time-select > * pour son propre
       contenu interne est déjà géré dans le bloc "Colonne créneaux"
       ci-dessus (h2, ul.time-listing…), qui garde order:3 implicite
       (ordre naturel : .rk-calendar-snapshot est le seul frère avec
       order:2 explicite, tout le reste de .time-select suit). */
}

/* ── Mobile : empilé, icône+titre en haut puis calendrier/bouton retour ──
   Séparateur : sous le sous-titre, entre le bloc titre et le contenu. */
@media (max-width: 767px) {
    .appt-select,
    .time-select {
        display: flex !important;
        flex-direction: column !important;
        align-items: center !important;
        min-width: 320px;
    }
    .rk-date-header {
        order: 1 !important;
        width: 100% !important;
        max-width: 320px !important;
        padding-left: 0 !important;
        padding-bottom: 40px !important;
        margin-top: 40px !important;
        border-left: none !important;
        border-bottom: 1px solid #8BA1B7 !important;
    }
    .appt-select > .monthly,
    .rk-calendar-snapshot {
        order: 2 !important;
        width: 100% !important;
        max-width: 320px !important;
    }

    /* AJOUT (demande utilisateur v2) — ajustements mobile complémentaires. */
    .md-layout .time-selection-col.md-layout {
        margin-top: 2rem;
    }
    .time-select ul.time-listing {
        display: flex !important;
        flex-direction: row-reverse !important;
        flex-wrap: wrap;
        justify-content: center;
    }
    .appt-select {
        min-width: 330px !important;
    }
    .appt-select > .monthly {
        padding: 8px !important;
    }
}

/* ── AJOUT (demande utilisateur) — bandeau fuseau horaire dans l'en-tête
   du wizard SSA (booking-header .timezone), et bouton retour natif
   repositionné (float:left, cohérent avec le sens RTL). ──────────────── */
#ssa-booking-app main .app-wrapper.rtl_support .booking-header .timezone {
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    flex-direction: row-reverse !important;
    background: #fff !important;
    border-radius: 12px !important;
    width: fit-content !important;
    padding: 4px 12px !important;
}
.back-button-wrapper {
    margin-bottom: 1rem !important;
    float: left !important;
}

/* ── AJOUT (demande utilisateur) — titre natif SSA masqué s'il apparaît
   (h1.ssa-type-header). NOTE : une exploration précédente du DOM réel n'a
   trouvé cette classe nulle part (voir plus bas, repli déjà en place sur
   un sélecteur alternatif) — cette règle est ajoutée en repli sans risque
   (si la classe n'existe pas dans ce DOM, la règle reste simplement sans
   effet), pas comme source de vérité du masquage. ─────────────────────── */
h1.ssa-type-header.focus-target {
    display: none !important;
}

/* ── AJOUT (demande utilisateur) — écran natif SSA de sélection
   type/coach (.booking-cards, visible dans le design d'origine à 5
   étapes, capture "من سيرافق طفلك؟"). Styling CSS pur, aucune
   logique JS : SSA reste seul responsable du clic et de la
   navigation, on ne fait qu'habiller ses cartes existantes pour
   matcher visuellement le reste du parcours.
   AJUSTEMENT (demande utilisateur, v2) — remplace la version précédente
   (radius 16px, fond plat, sans ombre) par le style complet fourni :
   dimensions fixes, dégradé, ombre, badge icône décoratif ::before,
   durée en pastille. ─────────────────────────────────────────────────── */
.booking-cards {
    display: flex !important;
    flex-wrap: wrap !important;
    gap: 16px !important;
    justify-content: center !important;
    direction: rtl !important;
}
.booking-cards .book-type-single {
    width: 255px !important;
    max-width: 100% !important;
}
.booking-cards .mdc-card {
    box-sizing: border-box !important;
    width: 100% !important;
    height: 194px !important;
    display: flex !important;
    flex-direction: column !important;
    align-items: center !important;
    justify-content: center !important;
    text-align: center !important;
    padding: 24px 16px !important;
    background: radial-gradient(326.1% 105.11% at 50.2% 0%, rgba(255, 255, 255, 0.2) 0%, rgba(186, 217, 243, 0.2) 79.42%, rgba(76, 149, 215, 0.2) 100%), #FFFFFF !important;
    border: none !important;
    border-radius: 15px !important;
    box-shadow: 0px 1.25px 2.5px rgba(23, 47, 70, 0.2), 0px 2.5px 7.5px 2.5px rgba(23, 47, 70, 0.05) !important;
    transition: box-shadow .15s ease, transform .15s ease !important;
    cursor: pointer !important;
}
.booking-cards .mdc-card:hover,
.booking-cards .mdc-card:focus {
    box-shadow: 0px 2px 4px rgba(23, 47, 70, 0.25), 0px 4px 12px 4px rgba(23, 47, 70, 0.1) !important;
    transform: translateY(-2px) !important;
}
.booking-cards .mdc-card-header {
    box-sizing: border-box !important;
    position: relative !important;
    display: flex !important;
    flex-direction: column !important;
    align-items: center !important;
    justify-content: center !important;
    gap: 12px !important;
    width: 100% !important;
    padding: 76px 0 0 !important;
}
.booking-cards .mdc-card-header::before {
    content: "" !important;
    display: block !important;
    position: absolute !important;
    top: 0 !important;
    left: 50% !important;
    transform: translateX(-50%) !important;
    width: 64px !important;
    height: 64px !important;
    border-radius: 50% !important;
    background-color: #D7ECFA !important;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='26' height='26' viewBox='0 0 24 24' fill='none'%3E%3Ccircle cx='12' cy='7' r='3' stroke='%234C95D7' stroke-width='1.8'/%3E%3Cpath d='M6.5 19C6.5 15.96 8.96 13.5 12 13.5C15.04 13.5 17.5 15.96 17.5 19' stroke='%234C95D7' stroke-width='1.8' stroke-linecap='round'/%3E%3C/svg%3E") !important;
    background-repeat: no-repeat !important;
    background-position: center !important;
    background-size: 26px 26px !important;
}
.booking-cards .md-title {
    width: auto !important;
    max-width: 100% !important;
    height: 24px !important;
    font-family: 'Alexandria', inherit !important;
    font-style: normal !important;
    font-weight: 600 !important;
    font-size: 20px !important;
    line-height: 24px !important;
    text-align: center !important;
    color: #295177 !important;
    margin: 0 !important;
    white-space: nowrap !important;
    overflow: hidden !important;
    text-overflow: ellipsis !important;
}
.booking-cards .md-subhead {
    margin: 0 !important;
}
.booking-cards .appointment-duration {
    box-sizing: border-box !important;
    display: flex !important;
    flex-direction: row !important;
    justify-content: center !important;
    align-items: center !important;
    padding: 4px 8px !important;
    gap: 4px !important;
    min-width: 71px !important;
    height: 24px !important;
    background: #FFEBE5 !important;
    border-radius: 1234px !important;
    color: #FF4411 !important;
    font-family: 'Alexandria', inherit !important;
    font-size: 12px !important;
    font-weight: 600 !important;
    white-space: nowrap !important;
}

/* ── AJOUT (demande utilisateur) — responsive complémentaire pour
   .booking-cards en colonne sur mobile (600px), en plus du empilement
   déjà géré à 767px ci-dessus pour .rk-date-header/.monthly. ─────────── */
@media (max-width: 600px) {
    .booking-cards {
        flex-direction: column !important;
        align-items: stretch !important;
    }
    .booking-cards .book-type-single {
        width: 100% !important;
    }
}

/* ── AJOUT (demande utilisateur) — écran natif SSA de confirmation
   finale ("شكراً لك! تم حجز موعدك", boutons تحرير المعلومات /
   إعادة جدولة / إلغاء الموعد). Même principe : uniquement du CSS sur
   les classes génériques déjà utilisées ailleurs dans ce fichier
   (.mdc-card, .mdc-card__actions, .mdc-button), sans cibler de
   classe spécifique à cet écran — la structure exacte de cet écran
   n'a pas pu être confirmée dans le bundle SSA minifié, donc ce
   style reste volontairement générique et sans risque : si la
   classe attendue change de nom chez SSA, ces règles n'ont
   simplement aucun effet, elles ne cassent rien. ────────────────── */
.mdc-card__actions .mdc-button--outlined {
    border-color: #FF4411 !important;
    color: #FF4411 !important;
}
.mdc-card__actions .mdc-button--outlined:hover {
    background: #FFEBE5 !important;
}
`;
        },

        destroy: function () {
            this._stopPolling();
            if (this._observer) {
                this._observer.disconnect();
                this._observer = null;
            }
            this._pendingDoc       = null;
            this._pendingContainer = null;
            this._submitted        = false;
            this._attempted        = false;
        }
    };

})(jQuery);