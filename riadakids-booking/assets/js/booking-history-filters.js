/**
 * RiadaKids Booking — assets/js/booking-history-filters.js
 *
 * AJOUT (demande utilisateur) — filtres + pagination de la liste
 * "اللقاءات المحجوزة" :
 *   1. Sélecteur "الطفل" (menu déroulant custom, "الكل" par défaut).
 *   2. Bascule "القادمة" / "السابقة".
 *   3. Pagination — 6 cartes par page, appliquée sur le sous-ensemble déjà
 *      filtré par (1) et (2), pas sur la liste brute.
 *
 * Entièrement côté client — aucun appel réseau. Chaque carte porte déjà
 * data-rk-child-id et data-rk-upcoming (voir Dashboard::render_booking_card()
 * et Dashboard::render_bookings_filter_bar()), donc l'application d'un
 * filtre est un simple show/hide sur les cartes déjà présentes dans le DOM
 * — cohérent avec le choix déjà fait pour l'injection instantanée de carte
 * après confirmation (voir booking-confirmation.js).
 *
 * PILE INDÉPENDANTE (voir Assets.php) : aucune dépendance sur le wizard de
 * réservation, aucun module du wizard n'en dépend.
 */
(function ($) {
    'use strict';

    var PAGE_SIZE = 6;

    var state = {
        childId: 0,             // 0 = tous les enfants
        // AJOUT (demande utilisateur) — filtre par statut, même patron
        // que childId ci-dessus.
        status: 'all',           // 'all' | 'confirmed' | 'cancelled'
        timeFilter: 'upcoming', // 'upcoming' | 'past'
        page: 1                 // page courante, 1-indexée
    };

    /**
     * Applique le filtre enfant + القادمة/السابقة, PUIS la pagination sur
     * le résultat filtré. Toujours exécutées ensemble (jamais l'une sans
     * l'autre) car changer un filtre doit systématiquement revenir à la
     * page 1 — sinon une page 3 pourrait rester affichée alors que le
     * nouveau filtre n'a plus que 2 cartes au total.
     */
    function applyFilters(resetPage) {
        if (resetPage) state.page = 1;

        var $allCards = $('#rk-bookings-cards').children('.rk-booking-card');

        // ── Étape 1 : filtre enfant + statut + القادمة/السابقة ──────────
        var $matched = $allCards.filter(function () {
            var $card = $(this);
            var cardChild    = parseInt($card.attr('data-rk-child-id'), 10) || 0;
            var cardUpcoming = $card.attr('data-rk-upcoming') === '1';
            var cardStatus   = $card.attr('data-rk-status') || '';

            var matchChild = (state.childId === 0) || (cardChild === state.childId);
            var matchTime  = (state.timeFilter === 'upcoming') ? cardUpcoming : !cardUpcoming;

            // AJOUT (demande utilisateur) — 'rescheduled' fait partie du
            // statut "مؤكد" : une réservation reprogrammée reste active,
            // ce n'est pas une annulation (voir BookingService::
            // insert_to_rk_bookings(), status='rescheduled' posé après
            // une reprogrammation réussie — voir aussi RescheduleAjax::
            // ajax_confirm()).
            var matchStatus = (state.status === 'all')
                || (state.status === 'confirmed' && (cardStatus === 'confirmed' || cardStatus === 'rescheduled'))
                || (state.status === 'cancelled' && cardStatus === 'cancelled');

            return matchChild && matchStatus && matchTime;
        });

        // Masque d'abord tout, puis ne réaffiche que la page courante du
        // sous-ensemble filtré — un seul passage de show/hide par carte.
        $allCards.hide();

        var totalMatched = $matched.length;
        var totalPages   = Math.max(1, Math.ceil(totalMatched / PAGE_SIZE));
        if (state.page > totalPages) state.page = totalPages;
        if (state.page < 1) state.page = 1;

        var start = (state.page - 1) * PAGE_SIZE;
        $matched.slice(start, start + PAGE_SIZE).show();

        // Message "aucun résultat" uniquement quand des cartes existent
        // réellement mais qu'aucune ne correspond au filtre courant — pas
        // à confondre avec #rk-empty-bookings (aucune réservation du tout,
        // géré côté serveur, voir Dashboard::render_history()).
        var $emptyFiltered = $('#rk-bookings-empty-filtered');
        if ($emptyFiltered.length) {
            $emptyFiltered.prop('hidden', totalMatched !== 0 || $allCards.length === 0);
        }

        renderPagination(totalPages);
    }

    /**
     * Reconstruit la barre de pagination. Masquée entièrement quand tout
     * tient sur une seule page — pas de contrôle inutile pour 6 réservations
     * ou moins (le cas le plus courant).
     */
    function renderPagination(totalPages) {
        var $nav = $('#rk-bookings-pagination');
        if (!$nav.length) return;

        if (totalPages <= 1) {
            $nav.prop('hidden', true).empty();
            return;
        }

        var html = '';

        html += '<button type="button" class="rk-bookings-pagination-btn" data-page="' +
            (state.page - 1) + '" ' + (state.page === 1 ? 'disabled' : '') +
            ' aria-label="السابق">‹</button>';

        for (var p = 1; p <= totalPages; p++) {
            html += '<button type="button" class="rk-bookings-pagination-btn' +
                (p === state.page ? ' is-active' : '') + '" data-page="' + p + '"' +
                (p === state.page ? ' aria-current="page"' : '') + '>' + p + '</button>';
        }

        html += '<button type="button" class="rk-bookings-pagination-btn" data-page="' +
            (state.page + 1) + '" ' + (state.page === totalPages ? 'disabled' : '') +
            ' aria-label="التالي">›</button>';

        $nav.html(html).prop('hidden', false);
    }

    function closeChildMenu() {
        $('#rk-child-filter-menu').prop('hidden', true);
        $('#rk-child-filter-toggle').attr('aria-expanded', 'false');
    }

    // AJOUT (demande utilisateur) — même patron que closeChildMenu()
    // ci-dessus, pour le nouveau menu de filtre par statut.
    function closeStatusMenu() {
        $('#rk-status-filter-menu').prop('hidden', true);
        $('#rk-status-filter-toggle').attr('aria-expanded', 'false');
    }

    // AJOUT (bug signalé — filtre "الطفل" pas réappliqué instantanément
    // après modification d'une carte) — expose applyFilters()
    // publiquement, même patron que window.RKBookingEdit/
    // RKBookingReschedule, pour que booking-edit.js puisse redéclencher
    // le filtrage après avoir mis à jour data-rk-child-id sur une carte.
    // Placé AVANT le garde $bar.length ci-dessous pour rester accessible
    // même sans réservation existante au chargement (cas où la 1ère
    // réservation vient d'être confirmée et où filterBar n'existe pas
    // encore côté serveur au chargement initial de la page).
    window.RKBookingFilters = {
        refresh: function () { applyFilters(false); }
    };

    $(function () {
        var $bar = $('#rk-bookings-filter-bar');
        if (!$bar.length) return; // aucune réservation → pas de barre de filtres à câbler

        // ── Sélecteur enfant (menu déroulant custom) ──────────────────
        $('#rk-child-filter-toggle').on('click', function (e) {
            e.stopPropagation();
            var $menu = $('#rk-child-filter-menu');
            var expanded = $menu.prop('hidden') === false;
            $menu.prop('hidden', expanded);
            $(this).attr('aria-expanded', expanded ? 'false' : 'true');
        });

        // AJUSTEMENT — sélecteur [data-child-id] (au lieu de la classe
        // générique .rk-child-filter-option seule) puisque cette classe
        // est désormais partagée avec le nouveau menu de filtre par
        // statut (voir Dashboard::render_bookings_filter_bar()) — chaque
        // menu réagit uniquement à ses propres options.
        $(document).on('click', '.rk-child-filter-option[data-child-id]', function () {
            var childId = parseInt($(this).data('child-id'), 10) || 0;
            var label   = $(this).text().trim();

            $('#rk-child-filter-menu .rk-child-filter-option').removeClass('is-active');
            $(this).addClass('is-active');
            $('#rk-child-filter-current').text(label);

            state.childId = childId;
            closeChildMenu();
            applyFilters(true);
        });

        // ── Sélecteur statut (menu déroulant custom) — AJOUT (demande
        // utilisateur), même patron que le sélecteur enfant ci-dessus.
        $('#rk-status-filter-toggle').on('click', function (e) {
            e.stopPropagation();
            var $menu = $('#rk-status-filter-menu');
            var expanded = $menu.prop('hidden') === false;
            $menu.prop('hidden', expanded);
            $(this).attr('aria-expanded', expanded ? 'false' : 'true');
        });

        $(document).on('click', '.rk-child-filter-option[data-status]', function () {
            var status = $(this).data('status') || 'all';
            var label  = $(this).text().trim();

            $('#rk-status-filter-menu .rk-child-filter-option').removeClass('is-active');
            $(this).addClass('is-active');
            $('#rk-status-filter-current').text(label);

            state.status = status;
            closeStatusMenu();
            applyFilters(true);
        });

        // Clic en dehors du menu → fermeture
        $(document).on('click', function (e) {
            if (!$(e.target).closest('#rk-child-filter').length) {
                closeChildMenu();
            }
            if (!$(e.target).closest('#rk-status-filter').length) {
                closeStatusMenu();
            }
        });

        $(document).on('keydown', function (e) {
            if (e.key === 'Escape') {
                closeChildMenu();
                closeStatusMenu();
            }
        });

        // ── Bascule القادمة / السابقة ──────────────────────────────────
        $('#rk-time-filter').on('click', '.rk-time-filter-tab', function () {
            var $tab = $(this);
            if ($tab.hasClass('is-active')) return;

            $('.rk-time-filter-tab').removeClass('is-active').attr('aria-selected', 'false');
            $tab.addClass('is-active').attr('aria-selected', 'true');

            state.timeFilter = $tab.data('time-filter') === 'past' ? 'past' : 'upcoming';
            applyFilters(true);
        });

        // ── Pagination ──────────────────────────────────────────────
        $(document).on('click', '.rk-bookings-pagination-btn', function () {
            if ($(this).prop('disabled')) return;
            var page = parseInt($(this).data('page'), 10) || 1;
            if (page === state.page) return;

            state.page = page;
            applyFilters(false);

            // Reramène la liste en haut de la carte à chaque changement de
            // page — sans ce scroll, l'utilisateur reste visuellement au
            // même endroit alors que le contenu au-dessus a changé.
            var $history = $('.rk-bookings-history');
            if ($history.length) {
                $('html, body').animate({ scrollTop: $history.offset().top - 20 }, 250);
            }
        });

        // Application initiale — "القادمة" est l'onglet actif par défaut
        // (voir Dashboard::render_bookings_filter_bar()), donc les
        // réservations passées sont masquées dès le premier rendu.
        applyFilters(true);
    });

})(jQuery);