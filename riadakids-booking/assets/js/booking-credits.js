/**
 * RiadaKids Booking Wizard — Module 7: Credits Verification
 * v8.0 — Coût fixe 1 crédit par réservation (1 réservation = 1 enfant = 1 crédit)
 */
(function ($) {
    'use strict';

    window.RKCredits = {
        init: function () {
            // Aucun écouteur direct nécessaire, appelé par le module enfants
        },

        refreshCreditPreview: function () {
            const childIds = window.RKBookingState.child_ids;

            if (!childIds || childIds.length === 0) {
                $('#rk-credit-preview').hide();
                window.RiadaKidsWizard.Utils.updateNextButton(4, false);
                return;
            }

            // 1 réservation = 1 enfant = 1 crédit fixe
            const needed = 1;

            $('#rk-credit-need').text(needed);
            $('#rk-credit-preview').show();

            const url = window.RiadaKidsWizard.Utils.ajaxUrl();
            if (!url) return;

            $.post(url, {
                action:   'rk_credit_preview',
                nonce:    rkConfig.nonce,
                child_ids: [childIds[0]], // Un seul enfant
            }, function (resp) {
                if (!resp.success) return;
                const d = resp.data;
                $('#rk-credits-display, #rk-credit-cur, #rk-credit-balance, #rk-hero-credits-display').text(d.credits_available);
                $('#rk-credit-need').text(d.credits_needed);
                $('#rk-credit-after').text(d.credits_after);
                $('#rk-credit-insufficient').toggle(!d.sufficient);

                window.RiadaKidsWizard.Utils.updateNextButton(4, d.sufficient);
            }).fail(function () {
                window.RiadaKidsWizard.Utils.updateNextButton(4, true);
            });
        }
    };
})(jQuery);
