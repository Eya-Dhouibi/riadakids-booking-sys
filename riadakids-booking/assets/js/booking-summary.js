/**
 * RiadaKids Booking Wizard — Module 9: Final Summary View
 */
(function ($) {
    'use strict';

    window.RKSummary = {
        renderFinalSummary: function () {
            const s = window.RKBookingState;
            const u = window.RiadaKidsWizard.Utils;
            const icons = (window.rkConfig && rkConfig.icons) || {};

            const dtFormatted = this._formatAppointmentDate(s.appointment_datetime);

            const childrenList = s.children_names.length > 0
                ? s.children_names.map(u.escHtml).join(', ')
                : '—';

            // CORRECTION (demande utilisateur) — fusion de la carte
            // "التوقيت" (fuseau horaire) dans la carte "الموعد" (jour +
            // heure), en sous-texte — au lieu d'une carte séparée en fin
            // de liste. Calqué sur la maquette fournie : ligne principale
            // "الأحد 19 أغسطس..." en gras, puis juste en dessous, en plus
            // petit/gris, "توقيت الرياض (GMT+3)".
            const timezoneSub = s.customer_timezone
                ? 'توقيت ' + u.escHtml(s.customer_timezone)
                : '';

            const cards = [
                {
                    icon: icons['user'] || '',
                    label: 'الطفل',
                    value: childrenList
                },
                {
                    icon: icons['video'] || '',
                    label: 'اللقاء',
                    value: u.escHtml(s.session_name || '—')
                },
                {
                    icon: icons['graduation-cap'] || '',
                    label: 'المدرب',
                    value: u.escHtml(s.event_name || '—')
                },
                {
                    icon: icons['calendar'] || '',
                    label: 'الموعد',
                    value: u.escHtml(dtFormatted),
                    sub: timezoneSub
                }
            ];

            const html = cards.map(function (c) {
                return '<div class="rk-summary-card">' +
                    '<div class="rk-summary-card__icon">' + c.icon + '</div>' +
                    '<div class="rk-summary-card__text">' +
                        '<span class="rk-summary-card__label">' + c.label + '</span>' +
                        '<strong class="rk-summary-card__value">' + c.value + '</strong>' +
                        (c.sub ? '<span class="rk-summary-card__sub">' + c.sub + '</span>' : '') +
                    '</div>' +
                '</div>';
            }).join('');

            $('#rk-final-summary').html(html);
        },

        /**
         * Formate la date du rendez-vous au format de la maquette :
         * "السبت، 19 أغسطس الساعة 3:35 مساءً" — repli sur toLocaleString
         * si Intl échoue (voir booking-ssa.js::_formatInClientTz pour la
         * même logique appliquée à l'étape 5).
         */
        _formatAppointmentDate: function (raw) {
            if (!raw) return '—';

            const d = new Date(raw);

            // CORRECTION (bug signalé) — avant la confirmation SSA réelle,
            // appointment_datetime peut contenir une chaîne déjà formatée
            // en arabe, capturée directement depuis le DOM SSA (voir
            // RKSSAOverlay._captureChosenSlot, ex. "السبت، 19 أغسطس —
            // 15:45") plutôt qu'une date ISO/UTC. new Date() sur cette
            // chaîne échoue systématiquement (isNaN), ce qui faisait
            // afficher "—" sur la carte "الموعد" de l'étape 6 alors que la
            // vraie information était bien présente dans le state — juste
            // pas dans un format que Date() sait parser. On l'affiche
            // désormais telle quelle plutôt que de la jeter : c'est déjà
            // exactement ce que SSA affichait à l'utilisateur au moment du
            // choix, aucune donnée n'est recalculée.
            if (isNaN(d.getTime())) return raw;

            const locale = (window.rkConfig && rkConfig.locale) ? rkConfig.locale : 'ar';
            const opts = {
                weekday: 'long', day: 'numeric', month: 'long',
                hour: '2-digit', minute: '2-digit', hour12: true
            };

            try {
                return new Intl.DateTimeFormat(locale, opts).format(d);
            } catch (e) {
                return d.toLocaleString();
            }
        }
    };
})(jQuery);