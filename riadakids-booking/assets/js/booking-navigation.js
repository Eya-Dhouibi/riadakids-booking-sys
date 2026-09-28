/**
 * RiadaKids Booking Wizard — Module 3: Wizard Navigation
 */
(function ($) {
    'use strict';

    window.RKNavigation = {
        init: function () {
            this.bindNavigation();
        },

        bindNavigation: function () {
            const self = this;
            
            $(document).on('click', '[id^="rk-next-"], .rk-btn-next', function () {
                const $btn = $(this);
                if ($btn.prop('disabled')) return;

                const currentStepId = $btn.attr('id');
                const currentStep = currentStepId
                    ? parseInt(currentStepId.replace('rk-next-', ''), 10)
                    : window.RKBookingState.currentStep;

                if (!self.validateCurrentStep(currentStep)) return;

                self.goToStep(currentStep + 1);
            });

            $(document).on('click', '.rk-btn-secondary[data-goto], .rk-btn-prev', function () {
                const prevStep = parseInt($(this).data('goto') || $(this).data('prev'), 10);
                if (prevStep > 0) self.goToStep(prevStep);
            });
        },

        goToStep: function (step) {
            // CORRECTION (bug signalé) — retour étape 6 → étape 5 affichait
            // une page vide : l'iframe SSA restait sur son écran "Customer
            // Information" masqué par notre propre CSS. On déclenche le
            // vrai retour SSA AVANT de réafficher l'étape 5, pour que
            // l'iframe montre réellement .time-select en dessous (voir
            // RKSSAOverlay.returnToTimeSelect pour le détail).
            if (step === 5 && this._previousStepWasStep6(window.RKBookingState.currentStep) &&
                window.RKSSAOverlay && typeof window.RKSSAOverlay.returnToTimeSelect === 'function') {
                window.RKSSAOverlay.returnToTimeSelect();
            }

            window.RKBookingState.currentStep = step;

            $('.rk-step-content').hide();
            $('#rk-step-' + step).show();

            $('.rk-steps .rk-step').removeClass('active completed');
            for (let i = 1; i < step; i++) {
                $('.rk-steps .rk-step[data-step="' + i + '"]').addClass('completed');
            }
            $('.rk-steps .rk-step[data-step="' + step + '"]').addClass('active');

            // Déclenchements d'interfaces inter-modules
            if (step === 4 && window.RKChildren && typeof window.RKChildren.refreshChildSelection === 'function') {
                window.RKChildren.refreshChildSelection();
            }
            if (step === 5 && window.RKSSA && typeof window.RKSSA.onEnterStep5 === 'function') {
                window.RKSSA.onEnterStep5();
            }
            if (step === 6 && window.RKSummary && typeof window.RKSummary.renderFinalSummary === 'function') {
                window.RKSummary.renderFinalSummary();
            }

            const $wizard = $('.rk-booking-page');
            if ($wizard.length) {
                $('html, body').animate({ scrollTop: $wizard.offset().top - 20 }, 300);
            }
        },

        /**
         * Vrai uniquement si l'étape courante (avant navigation) était la
         * 6 — utilisé exclusivement pour déclencher returnToTimeSelect()
         * au bon moment (voir goToStep). Isolé en méthode nommée plutôt
         * qu'une comparaison en ligne pour rendre l'intention explicite.
         */
        _previousStepWasStep6: function (currentStep) {
            return currentStep === 6;
        },

        validateCurrentStep: function (step) {
            const msgs = rkConfig.i18n || {};
            if (step === 1 && window.RKBookingState.program_id === 0) {
                alert(msgs.selectProgram || 'الرجاء اختيار رحلة');
                return false;
            }
            if (step === 2 && window.RKBookingState.adventure_id === 0) {
                alert(msgs.selectAdventure || 'الرجاء اختيار مغامرة');
                return false;
            }
            if (step === 3 && window.RKBookingState.session_id === 0) {
                alert(msgs.selectSession || 'الرجاء اختيار لقاء');
                return false;
            }
            if (step === 4 && window.RKBookingState.child_ids.length === 0) {
                alert(msgs.selectChild || 'الرجاء اختيار طفل واحد على الأقل');
                return false;
            }
            if (step === 5 && window.RKBookingState.appointment_id === 0) {
                alert(msgs.selectAppointment || 'الرجاء اختيار موعد من التقويم');
                return false;
            }
            return true;
        }
    };

    /**
     * CORRECTION — window.RiadaKidsWizard.Navigation n'était jamais assigné
     * nulle part dans le plugin (seul window.RKNavigation existait). Or
     * booking-ssa.js::bindSlotChosenEvent() et
     * booking-confirmation.js::bindSuccessActions() appellent tous deux
     * window.RiadaKidsWizard.Navigation.goToStep(...) — ce qui plantait
     * systématiquement avec "Cannot read properties of undefined (reading
     * 'goToStep')" dès la sélection d'un créneau SSA, laissant l'étape 6
     * complètement vide (la transition d'état avait bien lieu, mais
     * l'affichage DOM ne suivait jamais). Même pattern que
     * window.RiadaKidsWizard.Utils (voir booking-utils.js) : le namespace
     * partagé est complété ici, pas recréé.
     */
    window.RiadaKidsWizard = window.RiadaKidsWizard || {};
    window.RiadaKidsWizard.Navigation = window.RKNavigation;

})(jQuery);