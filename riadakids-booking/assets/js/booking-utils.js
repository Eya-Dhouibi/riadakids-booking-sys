/**
 * RiadaKids Booking Wizard — assets/js/booking-utils.js — Module 2 : Utilitaires
 *
 * ⚠️ FICHIER CRITIQUE MANQUANT — L'intégralité du wizard est non-fonctionnel sans lui.
 *
 * Ce module est chargé en PREMIER (après booking-state.js) et fournit à tous les
 * autres modules les fonctions partagées via window.RiadaKidsWizard.Utils.
 *
 * Fonctions exposées :
 *   - ajaxUrl()                 : URL admin-ajax.php (depuis rkConfig)
 *   - escHtml(str)              : Échappement HTML XSS-safe
 *   - updateNextButton(step, ok): Active/désactive le bouton "Suivant" d'une étape
 *   - updateSummaryField(id, v) : Met à jour un champ dans la barre de résumé
 *   - buildOptionCard(...)      : Génère le HTML d'une carte d'option (programme/aventure/séance)
 *
 * Dépendances : jQuery, booking-state.js (window.RiadaKidsWizard namespace)
 * Chargé par : Assets.php (handle: rk-utils, dépendance de tous les autres modules)
 */
(function ($) {
    'use strict';

    /**
     * Icône SVG partagée, fournie par PHP via rkConfig.icons.
     * Exposée globalement : tous les modules du wizard l'utilisent à la place
     * des emojis (rendu incohérent selon l'OS + conversion en <img> par WP).
     *
     * @param {string} name Clé de l'icône.
     * @return {string} SVG inline, ou '' si absente.
     */
    window.RKIcon = function (name) {
        var cfg = window.rkConfig || {};
        return (cfg.icons && cfg.icons[name]) ? cfg.icons[name] : '';
    };

    /* Initialise (ou complète) le namespace global */
    window.RiadaKidsWizard = window.RiadaKidsWizard || {};

    window.RiadaKidsWizard.Utils = {

        /* ── ÉTAPE 23 — Logging DEBUG désactivable ──────────────────────────
         * Même esprit que rk_log() côté PHP : préfixe [RK SSA], activable
         * uniquement si rkConfig.debug est vrai (dérivé de WP_DEBUG côté
         * serveur — voir Assets.php). En production (WP_DEBUG=false), ces
         * appels ne produisent RIEN — aucun coût, aucune fuite en console.
         *
         * NE JAMAIS passer en `data` : email complet, nonce, token, mot de
         * passe, ou toute donnée personnelle sensible. `data` doit rester
         * un identifiant technique court (booking_id, un état, un nom
         * d'event) — jamais une valeur qui identifie ou authentifie
         * quelqu'un. Voir les appels dans booking-ssa.js/booking-ssa-overlay.js
         * /booking-confirmation.js pour l'usage réel attendu.
         */
        log: function (message, data) {
            if (!window.rkConfig || !rkConfig.debug) return;
            if (data !== undefined) {
                console.log('[RK SSA] ' + message, data);
            } else {
                console.log('[RK SSA] ' + message);
            }
        },

        /* ── URL AJAX ─────────────────────────────────────────────────────── */

        /**
         * Retourne l'URL admin-ajax.php depuis rkConfig.
         * Retourne '' si rkConfig n'est pas disponible (évite les erreurs fatales JS).
         */
        ajaxUrl: function () {
            return (window.rkConfig && window.rkConfig.ajaxUrl) ? window.rkConfig.ajaxUrl : '';
        },

        /* ── Sécurité XSS ─────────────────────────────────────────────────── */

        /**
         * Échappe une chaîne pour insertion sécurisée dans le DOM HTML.
         * Remplace &, <, >, ", ' par leurs entités HTML.
         * Utilisation : u.escHtml(userInput) avant d'insérer dans .html()
         */
        escHtml: function (str) {
            if (str === null || str === undefined) return '';
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        },

        /**
         * AJOUT (bug signalé) — miroir JS de Helpers::clean_coach_name()
         * (voir includes/Core/Helpers.php pour la doc complète) : retire un
         * préfixe conversationnel connu du titre SSA (ex. "أنت تحجز: …"),
         * jamais généré par ce plugin. Utilisé côté client uniquement pour
         * la carte injectée juste après confirmation (voir
         * booking-confirmation.js::_prependBookingCard()), où event_name
         * vient directement de RKBookingState sans passer par le PHP.
         */
        cleanCoachName: function (name) {
            if (!name) return name;
            name = String(name).trim();
            var prefixes = ['أنت تحجز', 'انت تحجز'];
            for (var i = 0; i < prefixes.length; i++) {
                if (name.indexOf(prefixes[i]) === 0) {
                    var rest = name.slice(prefixes[i].length).replace(/^[\s:،,]+/, '');
                    if (rest.trim() !== '') return rest.trim();
                }
            }
            return name;
        },

        /**
         * AJOUT (demande utilisateur) — format compact pour la carte de
         * réservation (ex. "السبت، 19 أغسطس الساعة 3:35 مساءً" — même
         * format complet que le récapitulatif de reprogrammation partout,
         * demande utilisateur), distinct du format détaillé de
         * _formatAppointmentDate() (récap étape 6, structure différente)
         * — même style que TimeZone::format_arabic_full() côté PHP
         * (Dashboard::render_booking_card()), pour que la carte injectée
         * par booking-confirmation.js corresponde exactement à ce qu'un
         * rechargement de page afficherait.
         *
         * FIX (bug signalé — jour/mois parfois en anglais) — locale
         * forcée à 'ar' explicitement, JAMAIS rkConfig.locale (qui
         * reflète get_locale() côté WordPress — la locale DU SITE, pas
         * une préférence d'affichage arabe garantie, voir Assets.php).
         * Le reste de cette interface est en arabe indépendamment de
         * cette locale (chaînes codées en dur) — même principe ici.
         */
        formatCardDate: function (raw) {
            if (!raw) return '—';
            var d = new Date(raw);
            // Même repli que _formatAppointmentDate() — chaîne déjà
            // formatée en arabe capturée depuis le DOM SSA, non parsable
            // par Date().
            if (isNaN(d.getTime())) return raw;

            try {
                var weekday = new Intl.DateTimeFormat('ar', { weekday: 'long' }).format(d);
                var day     = new Intl.DateTimeFormat('ar-u-nu-latn', { day: 'numeric' }).format(d);
                var month   = new Intl.DateTimeFormat('ar', { month: 'long' }).format(d);
                var time    = new Intl.DateTimeFormat('ar-u-nu-latn', { hour: 'numeric', minute: '2-digit', hour12: true }).format(d);
                var hour24  = d.getHours();
                var meridiem = hour24 < 12 ? 'صباحاً' : 'مساءً';
                // Intl produit déjà un séparateur AM/PM arabe (ص/م) dans
                // `time` — on le retire pour ne garder que "H:MM" et
                // ajouter notre propre "الساعة .. مساءً/صباحاً" explicite,
                // cohérent avec le format demandé.
                var timeDigitsOnly = time.replace(/[^\d:]/g, '');
                return weekday + '، ' + day + ' ' + month + ' الساعة ' + timeDigitsOnly + ' ' + meridiem;
            } catch (e) {
                return raw;
            }
        },

        /* ── Boutons Navigation ───────────────────────────────────────────── */

        /**
         * Active ou désactive le bouton "Suivant" d'une étape du wizard.
         *
         * @param {number}  step   Numéro d'étape (1–6)
         * @param {boolean} enable true = activer, false = désactiver
         */
        updateNextButton: function (step, enable) {
            var $btn = $('#rk-next-' + step);
            if (!$btn.length) {
                /* Recherche alternative si l'ID ne suit pas la convention */
                $btn = $('.rk-step-content#rk-step-' + step + ' .rk-btn-primary');
            }
            $btn.prop('disabled', !enable);
            if (enable) {
                $btn.removeClass('rk-btn-disabled');
            } else {
                $btn.addClass('rk-btn-disabled');
            }
        },

        /* ── Barre de résumé ─────────────────────────────────────────────── */

        /**
         * Met à jour la valeur affichée d'un champ dans la barre de résumé.
         *
         * @param {string} fieldId   ID du conteneur résumé (ex: 'sum-program')
         * @param {string} value     Valeur à afficher
         */
        updateSummaryField: function (fieldId, value) {
            var $el = $('#' + fieldId);
            if (!$el.length) return;
            var $val = $el.find('.rk-sum-val');
            if ($val.length) {
                $val.text(value || '');
            } else {
                $el.text(value || '');
            }
        },

        /* ── Construction des cartes d'option ────────────────────────────── */

        /**
         * Génère le HTML d'une carte d'option radio (programme / aventure / séance).
         *
         * Ce helper centralise la construction des cartes pour éviter la duplication
         * entre booking-programs.js et booking-sessions.js.
         *
         * @param {string} inputName     Nom de l'input radio (ex: 'rk_program', 'rk_course', 'rk_session')
         * @param {number} id            Valeur de l'input (ID)
         * @param {string} title         Titre principal de la carte
         * @param {string} description   Description optionnelle
         * @param {string} icon          Emoji ou icône (ex: '🎯', '📅')
         * @param {number} ssaEventId    ID de l'événement SSA (0 si non applicable)
         * @returns {string}             HTML string de la carte label
         */
        buildOptionCard: function (inputName, id, title, description, icon, ssaEventId) {
            var safeTitle = this.escHtml(title || '');
            var safeDesc  = this.escHtml(description || '');
            var safeIcon  = icon || RKIcon( 'pin' );
            var ssaAttr   = ssaEventId > 0
                ? ' data-ssa-event-id="' + parseInt(ssaEventId, 10) + '"'
                : '';
            var descHtml  = safeDesc
                ? '<small class="rk-option-desc">' + safeDesc + '</small>'
                : '';

            return (
                '<label class="rk-option-card">' +
                    '<input type="radio" name="' + this.escHtml(inputName) + '"' +
                    ' value="' + parseInt(id, 10) + '"' +
                    ' data-label="' + safeTitle + '"' +
                    ssaAttr + '>' +
                    '<div class="rk-option-content">' +
                        '<div class="rk-option-icon">' + safeIcon + '</div>' +
                        '<div>' +
                            '<strong>' + safeTitle + '</strong>' +
                            descHtml +
                        '</div>' +
                    '</div>' +
                '</label>'
            );
        },

        /* ── Utilitaires date ─────────────────────────────────────────────── */

        /**
         * Formate une date ISO 8601 en format lisible (locale ar-EG).
         * Retourne '—' si la date est invalide.
         *
         * @param {string} isoString
         * @returns {string}
         */
        formatDateTime: function (isoString) {
            if (!isoString) return '—';
            try {
                return new Date(isoString).toLocaleString('ar-EG', {
                    year:   'numeric',
                    month:  'long',
                    day:    'numeric',
                    hour:   '2-digit',
                    minute: '2-digit',
                });
            } catch (e) {
                return isoString;
            }
        },

        /* ── Affichage messages ───────────────────────────────────────────── */

        /**
         * Affiche un message d'erreur dans un conteneur.
         *
         * @param {string|jQuery} container  Sélecteur CSS ou objet jQuery
         * @param {string}        message    Message d'erreur
         */
        showError: function (container, message) {
            $(container).html(
                '<p class="rk-error">' + RKIcon( 'x-circle' ) + ' ' + this.escHtml(message) + '</p>'
            ).show();
        },

        /**
         * Affiche un spinner de chargement dans un conteneur.
         *
         * @param {string|jQuery} container  Sélecteur CSS ou objet jQuery
         * @param {string}        message    Texte du chargement (optionnel)
         */
        showLoading: function (container, message) {
            var msg = message || (window.rkConfig && rkConfig.i18n && rkConfig.i18n.processing) || 'جاري التحميل…';
            $(container).html(
                '<div class="rk-loading">' +
                    '<span class="rk-spinner"></span> ' +
                    this.escHtml(msg) +
                '</div>'
            ).show();
        }
    };

})(jQuery);