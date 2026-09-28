/**
 * RiadaKids Booking — PHASE 1: View Switcher
 *
 * Bascule uniquement entre la vue "حجز لقاء" (Booking Form) et
 * "اللقاءات المحجوزة" (Booking List). Ce module ne gère AUCUNE logique
 * métier : pas de steps, pas d'AJAX, pas d'état de réservation.
 *
 * Ne pas appeler RKNavigation.goToStep() ici : le view switcher opère
 * à un niveau au-dessus du wizard (form vs list), pas entre les steps
 * du formulaire.
 */
(function ($) {
    'use strict';

    window.RKViewSwitcher = {
        init: function () {
            this.bindTabs();
        },

        bindTabs: function () {
            const self = this;
            $(document).on('click', '.rk-booking-view-tab', function () {
                const view = $(this).data('rk-view');
                if (!view) return;
                self.showView(view);
            });

            // AJOUT (demande utilisateur) — bouton "حجز مجدد" sur une carte de
            // réservation annulée (voir Dashboard::render_booking_card()) :
            // même bascule que les onglets حجز لقاء / اللقاءات المحجوزة
            // ci-dessus, réutilisée telle quelle plutôt que dupliquée.
            // Défilement vers le haut ajouté : contrairement aux onglets
            // (déjà visibles en haut de page), ce bouton est cliqué depuis
            // une carte potentiellement loin en bas de la liste.
            $(document).on('click', '.rk-open-rebook-form', function (e) {
                e.preventDefault();
                self.showView('form');
                $('html, body').animate({ scrollTop: 0 }, 250);
            });
        },

        showView: function (view) {
            $('.rk-booking-view-tab').removeClass('is-active');
            $('.rk-booking-view-tab[data-rk-view="' + view + '"]').addClass('is-active');

            $('.rk-booking-view').addClass('is-hidden');
            $('.rk-booking-view[data-view="' + view + '"]').removeClass('is-hidden');
        }
    };

    $(function () {
        window.RKViewSwitcher.init();
    });
})(jQuery);
