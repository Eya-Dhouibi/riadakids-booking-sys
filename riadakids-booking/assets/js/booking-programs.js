/**
 * RiadaKids Booking Wizard — assets/js/booking-programs.js — Module 4 : Programmes & Aventures
 *
 * Responsabilité UNIQUE : sélection programme (étape 1) + chargement aventures (étape 2).
 *
 * Changements v5.0 :
 *   - buildOptionCard() supprimé → délégué à window.RiadaKidsWizard.Utils.buildOptionCard()
 *   - Plus de couplage avec booking-sessions.js
 *
 * Dépendances : jQuery, booking-state.js, booking-utils.js, booking-navigation.js
 */
(function ($) {
    'use strict';

    window.RKPrograms = {

        init: function () {
            this.bindProgramSelection();
            this.bindAdventureSelection();
        },

        /* ── Étape 1 — Sélection programme ─────────────────────────────── */
        bindProgramSelection: function () {
            const self = this;

            $(document).on('change', 'input[name="rk_program"]', function () {
                $('input[name="rk_program"]').closest('.rk-option-card').removeClass('rk-selected-card');
                $(this).closest('.rk-option-card').addClass('rk-selected-card');

                const s = window.RKBookingState;
                s.program_id   = parseInt($(this).val(), 10);
                s.program_name = $(this).data('label') || $('strong', $(this).parent()).text() || '';

                /* Réinitialise les étapes suivantes */
                s.adventure_id   = 0;
                s.adventure_name = '';
                s.session_id     = 0;
                s.session_name   = '';
                s.ssa_event_id   = 0;

                const u = window.RiadaKidsWizard.Utils;
                u.updateNextButton(1, true);
                u.updateSummaryField('sum-program', s.program_name);
                $('#rk-summary-bar, #sum-program').show();

                self.loadCourses(s.program_id);
            });
        },

        /* ── Étape 2 — Sélection aventure ──────────────────────────────── */
        bindAdventureSelection: function () {
            $(document).on('change', 'input[name="rk_course"], input[name="rk_adventure"]', function () {
                $('input[name="rk_course"], input[name="rk_adventure"]')
                    .closest('.rk-option-card').removeClass('rk-selected-card');
                $(this).closest('.rk-option-card').addClass('rk-selected-card');

                const s = window.RKBookingState;
                s.adventure_id   = parseInt($(this).val(), 10);
                s.adventure_name = $(this).data('label') || $('strong', $(this).parent()).text() || '';
                s.session_id     = 0;
                s.session_name   = '';
                s.ssa_event_id   = 0;

                const u = window.RiadaKidsWizard.Utils;
                u.updateNextButton(2, true);
                u.updateSummaryField('sum-course', s.adventure_name);
                $('#sum-sep-1, #sum-course').show();

                /* Délègue le chargement des séances au module dédié */
                if (window.RKSessions && typeof RKSessions.loadSessions === 'function') {
                    RKSessions.loadSessions(s.adventure_id);
                }
            });
        },

        /* ── Chargement AJAX des aventures (étape 2) ────────────────────── */
        loadCourses: function (programId) {
            const $container = $('#rk-courses');
            const url        = window.RiadaKidsWizard.Utils.ajaxUrl();
            if (!url || !programId) return;

            window.RiadaKidsWizard.Utils.updateNextButton(2, false);
            $container.html('<div class="rk-loading"><span class="rk-spinner"></span> جاري التحميل…</div>');

            $.post(url, {
                action:     'rk_get_courses',
                nonce:      rkConfig.nonce,
                program_id: programId,
            }, function (resp) {
                if (!resp.success || !resp.data.courses || resp.data.courses.length === 0) {
                    $container.html('<p class="rk-empty">لا توجد مغامرات متاحة لهذا البرنامج.</p>');
                    return;
                }
                let html = '';
                resp.data.courses.forEach(function (c) {
                    html += RKPrograms.buildCourseCard(c);
                });
                $container.html(html);
            }).fail(function () {
                $container.html('<p class="rk-error">خطأ في التحميل. حاول مجدداً.</p>');
            });
        },

        /* ── Card de cours (étape 2) — badge catégorie au-dessus du titre ── */
        buildCourseCard: function (c) {
            const u         = window.RiadaKidsWizard.Utils;
            const safeTitle = u.escHtml(c.title || '');
            const safeDesc  = u.escHtml(c.excerpt || '');
            const safeCat   = u.escHtml(c.category || '');
            const safeImg   = u.escHtml(c.image || '');

            return (
                '<label class="rk-option-card rk-course-card">' +
                    '<input type="radio" name="rk_course"' +
                    ' value="' + parseInt(c.id, 10) + '"' +
                    ' data-label="' + safeTitle + '">' +
                    '<div class="rk-course-media">' +
                        '<img src="' + safeImg + '" alt="" class="rk-course-photo" loading="lazy" decoding="async">' +
                    '</div>' +
                    '<div class="rk-option-content rk-course-content">' +
                        '<div class="rk-course-text">' +
                            (safeCat ? '<span class="rk-course-badge">' + safeCat + '</span>' : '') +
                            '<strong>' + safeTitle + '</strong>' +
                            (safeDesc ? '<small>' + safeDesc + '</small>' : '') +
                        '</div>' +
                    '</div>' +
                '</label>'
            );
        }

    };

})(jQuery);