/**
 * RiadaKids Booking Wizard — assets/js/booking-sessions.js — Module 5 : Séances
 *
 * Responsabilité UNIQUE : sélection séance ACF (étape 3) + chargement AJAX.
 *
 * Changements v5.0 :
 *   - FIX CRITIQUE : buildOptionCard() était appelé via window.RKPrograms →
 *     si RKPrograms n'était pas encore initialisé, html restait vide sans message
 *     d'erreur → liste silencieusement vide. Corrigé → utilise Utils.buildOptionCard()
 *   - Découplage complet de booking-programs.js
 *
 * Dépendances : jQuery, booking-state.js, booking-utils.js
 */
(function ($) {
    'use strict';

    window.RKSessions = {

        init: function () {
            this.bindSessionSelection();
        },

        /* ── Étape 3 — Sélection séance ACF ────────────────────────────── */
        bindSessionSelection: function () {
            $(document).on('change', 'input[name="rk_session"]', function () {
                $('input[name="rk_session"]').closest('.rk-option-card').removeClass('rk-selected-card');
                $(this).closest('.rk-option-card').addClass('rk-selected-card');

                const s = window.RKBookingState;
                s.session_id   = parseInt($(this).val(), 10);
                /* Récupère le nom de la séance ACF affiché dans la carte */
                s.session_name = $(this).data('label') ||
                                 $('strong', $(this).closest('.rk-option-content')).text() || '';
                /* Description courte (Tutor LMS lesson content, tronquée
                   côté PHP) — affichée en sous-texte sur l'écran résumé
                   final (étape 6, voir booking-summary.js). */
                s.session_description = $(this).data('description') || '';

                /* ssa_event_id = ID du type de rendez-vous SSA lié à cette
                   séance, via le mapping wp_rk_ssa_map (voir DashboardAjax::
                   render_session_option côté PHP). Conservé pour référence/
                   affichage éventuel, mais NE DOIT PLUS alimenter s.event_id
                   (voir CORRECTION ci-dessous). */
                s.ssa_event_id = parseInt($(this).data('ssa-event-id'), 10) || 0;

                // CORRECTION (bug signalé — booking jamais confirmé malgré
                // un vrai succès SSA, "confirm_from_ssa() ... pending expiré")
                // — s.event_id était systématiquement écrasé par le mapping
                // cours↔type (wp_rk_ssa_map), indépendamment du type SSA
                // RÉELLEMENT choisi par l'utilisateur à l'étape 5 (SSA
                // affiche son propre écran de sélection de type/coach —
                // voir booking-ssa.js::_injectIframeParams, où le forçage
                // "types=" a été retiré sur demande explicite). Résultat :
                // le pré-create envoyait au serveur un event_id qui ne
                // correspondait à RIEN de ce que l'utilisateur réservait
                // réellement dans l'iframe — confirm_from_ssa() cherchait
                // alors un booking "pending" pour ce mauvais event_id+user,
                // tombait sur un ancien pending expiré sans rapport, et
                // refusait la confirmation alors que SSA avait bel et bien
                // créé le rendez-vous demandé.
                //
                // On revient donc au design d'origine documenté dans
                // booking-ssa.js (_preCreate, commentaire "event_id = 0 est
                // accepté ici. SSA fournira l'appointment_type_id [...]") :
                // s.event_id reste à sa valeur par défaut (0, voir
                // booking-state.js) tant que SSA n'a pas lui-même confirmé
                // le vrai type choisi via son webhook/hook — c'est
                // confirm_from_ssa() qui reçoit alors le bon type_id
                // directement depuis les données SSA, pas une supposition
                // faite à l'étape 3 avant même que l'utilisateur choisisse
                // son coach à l'étape 5.
                /* event_name (nom affiché "لقاء مع [Nom]") est désormais
                   capturé directement depuis le DOM SSA à l'étape 5 —
                   voir booking-ssa-overlay.js::_captureEventName. */

                const u = window.RiadaKidsWizard.Utils;
                u.updateNextButton(3, true);
                u.updateSummaryField('sum-session', s.session_name);
                $('#sum-sep-2, #sum-session').show();
            });
        },

        /* ── Chargement AJAX des séances (étape 3) ──────────────────────── */
        /**
         * Appelé par booking-programs.js quand l'aventure change.
         * Correspond à DashboardAjax::load_sessions() côté PHP.
         *
         * CORRECTIONS v5.1 :
         *   1. action    : 'rk_load_sessions'  (était 'rk_get_sessions')
         *   2. paramètre : course              (était 'course_id')
         *   3. réponse   : HTML injecté direct (DashboardAjax::load_sessions()
         *                  fait echo + wp_die(), pas wp_send_json_success)
         */
        loadSessions: function (courseId) {
            const $container = $('#rk-sessions');
            const url        = window.RiadaKidsWizard.Utils.ajaxUrl();
            if (!url || !courseId) return;

            window.RiadaKidsWizard.Utils.updateNextButton(3, false);
            $container.html('<div class="rk-loading"><span class="rk-spinner"></span> جاري التحميل…</div>');

            $.post(url, {
                action: 'rk_load_sessions', // FIX 1 : était 'rk_get_sessions'
                nonce:  rkConfig.nonce,
                course: courseId,           // FIX 2 : était 'course_id'
            }, function (response) {
                // FIX 3 : injection HTML directe — load_sessions() retourne du HTML, pas du JSON
                $container.html(response);
            }, 'html'
            ).fail(function () {
                $container.html('<p class="rk-error">خطأ في التحميل. حاول مجدداً.</p>');
            });
        }
    };

})(jQuery);