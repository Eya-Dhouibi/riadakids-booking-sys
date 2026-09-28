/**
 * RiadaKids Booking Wizard — assets/js/rk-booking.js — v5.0 BOOTSTRAP ONLY
 *
 * Ce fichier est le POINT D'ENTRÉE UNIQUE du wizard.
 * Il ne contient AUCUNE logique métier.
 *
 * Responsabilité :
 *   1. Vérifier que tous les modules sont chargés
 *   2. Appeler .init() sur chaque module dans l'ordre
 *   3. Lancer la restauration du pending booking
 *
 * Ordre de chargement garanti par Assets.php (dépendances wp_register_script) :
 *   booking-state.js → booking-utils.js → booking-navigation.js →
 *   booking-programs.js → booking-sessions.js → booking-children.js →
 *   booking-credits.js → booking-ssa.js → booking-summary.js →
 *   booking-confirmation.js → rk-booking.js (ce fichier)
 */
(function ($) {
    'use strict';

    /* ── Guard : vérifier que le namespace est prêt ─────────────────────── */
    if (!window.RKBookingState || !window.RiadaKidsWizard || !window.RiadaKidsWizard.Utils) {
        console.error('[RK] Modules manquants — vérifier l\'ordre de chargement des scripts.');
        return;
    }

    console.log('RK Booking Modular v5.0 — bootstrap');

    /* ── DOM Ready : initialiser les modules dans l'ordre ───────────────── */
    $(function () {

        /* Module 3 — Navigation wizard */
        if (window.RKNavigation && typeof RKNavigation.init === 'function') {
            RKNavigation.init();
        }

        /* Module 4 — Programmes & Aventures */
        if (window.RKPrograms && typeof RKPrograms.init === 'function') {
            RKPrograms.init();
        }

        /* Module 5 — Séances */
        if (window.RKSessions && typeof RKSessions.init === 'function') {
            RKSessions.init();
        }

        /* Module 6 — Enfants */
        if (window.RKChildren && typeof RKChildren.init === 'function') {
            RKChildren.init();
        }

        /* Module 7 — Crédits */
        if (window.RKCredits && typeof RKCredits.init === 'function') {
            RKCredits.init();
        }

        /* Module 8 — SSA */
        if (window.RKSSA && typeof RKSSA.init === 'function') {
            RKSSA.init();
        }

        /* Module 8b — SSA Overlay (widget custom par-dessus l'iframe SSA) */
        if (window.RKSSAOverlay && typeof RKSSAOverlay.init === 'function') {
            RKSSAOverlay.init();
        }

        /* Module 10 — Confirmation + Pending */
        if (window.RKConfirmation && typeof RKConfirmation.init === 'function') {
            RKConfirmation.init();
        }

        /* RKPending.restore() supprimé — pas de reprise de réservation incomplète. */
    });

})(jQuery);