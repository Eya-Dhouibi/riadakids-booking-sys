/**
 * RiadaKids — Filtre "برنامج" + pagination AJAX sur /my-account/rk-rapport/
 *
 * AJOUT (demande utilisateur) — le résultat du filtre doit changer sans
 * rechargement de page. Intercepte les clics sur les pills .rp-program-pill
 * et les liens .rp-pagination__page/.rp-pagination__nav situés à
 * l'intérieur de #rp-sessions-results, appelle l'action AJAX
 * rk_rapport_filter_sessions (RK_MC_Rapport_Ajax), et remplace le
 * contenu de #rp-sessions-results par le HTML retourné — rendu par le
 * MÊME partial PHP que le chargement initial de la page, donc résultat
 * visuellement identique à un rechargement classique.
 *
 * Dégradation : les liens conservent leur href réel (?program=...,
 * ?rp_page=...), donc la page reste utilisable sans JS ou si le fetch
 * échoue (fallback sur navigation normale dans le catch()).
 *
 * @package RK_My_Children
 */
(function () {
    'use strict';

    function init() {
        var results = document.getElementById('rp-sessions-results');
        if (!results) return;

        var ajaxUrl = results.getAttribute('data-ajax-url');
        var nonce   = results.getAttribute('data-nonce');
        var childId = results.getAttribute('data-child-id');
        var pillsWrap = document.querySelector('.rp-program-filter');

        function currentProgram() {
            var active = pillsWrap ? pillsWrap.querySelector('.rp-program-pill.active') : null;
            // La pill "الكل" a un href sans ?program — on lit l'attribut
            // data-program s'il existe, sinon on déduit depuis l'URL du lien.
            if (!active) return '';
            try {
                var u = new URL(active.href, window.location.origin);
                return u.searchParams.get('program') || '';
            } catch (e) {
                return '';
            }
        }

        function setLoading(on) {
            results.style.opacity = on ? '.5' : '';
            results.style.pointerEvents = on ? 'none' : '';
        }

        function fetchAndRender(program, page, pushUrl) {
            setLoading(true);
            var fd = new FormData();
            fd.append('action', 'rk_rapport_filter_sessions');
            fd.append('nonce', nonce);
            fd.append('child_id', childId);
            fd.append('program', program || '');
            fd.append('rp_page', page || 1);

            fetch(ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    setLoading(false);
                    if (!res || !res.success) {
                        // Repli — navigation normale vers l'URL déjà calculée par PHP
                        if (pushUrl) window.location.href = pushUrl;
                        return;
                    }
                    results.innerHTML = res.data.html;
                    if (pushUrl) {
                        window.history.pushState({ rpProgram: program, rpPage: page }, '', pushUrl);
                    }
                    // Remonte doucement en haut de la grille — utile quand on
                    // change de page depuis le bas d'une longue liste.
                    results.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                })
                .catch(function () {
                    setLoading(false);
                    if (pushUrl) window.location.href = pushUrl;
                });
        }

        /* ── Pills برنامج ── */
        if (pillsWrap) {
            pillsWrap.addEventListener('click', function (e) {
                var link = e.target.closest('.rp-program-pill');
                if (!link) return;
                e.preventDefault();

                var href = link.getAttribute('href');
                var program = '';
                try {
                    program = new URL(link.href, window.location.origin).searchParams.get('program') || '';
                } catch (err) { /* garde program = '' */ }

                pillsWrap.querySelectorAll('.rp-program-pill').forEach(function (p) {
                    p.classList.toggle('active', p === link);
                });
                fetchAndRender(program, 1, href);
            });
        }

        /* ── Pagination — délégation sur #rp-sessions-results car la
           pagination est réinjectée à chaque fetch (contenu remplacé). ── */
        results.addEventListener('click', function (e) {
            var link = e.target.closest('.rp-pagination__page, .rp-pagination__nav');
            if (!link || link.classList.contains('is-disabled')) return;
            e.preventDefault();

            var page = parseInt(link.getAttribute('data-rp-page'), 10) || 1;
            fetchAndRender(currentProgram(), page, link.getAttribute('href'));
        });

        /* ── Bouton précédent/suivant navigateur ── */
        window.addEventListener('popstate', function (e) {
            var state = e.state;
            if (state && typeof state.rpPage !== 'undefined') {
                fetchAndRender(state.rpProgram || '', state.rpPage || 1, null);
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
