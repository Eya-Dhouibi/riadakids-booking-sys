/**
 * RiadaKids — لقاءاتي (My Sessions)
 *
 * Sélection interactive côté client : cliquer un item de la liste met
 * à jour le panneau détail SANS rechargement de page, à partir des
 * données JSON déjà présentes dans le HTML (#rk-sess-data, généré par
 * rk-sessions.php — aucun appel AJAX nécessaire, tout est déjà chargé).
 *
 * Scope : uniquement sur la page لقاءاتي (guardé par [data-rk-sessions]).
 *
 * @since 9.6.0
 */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', init );

	function init() {
		var root = document.querySelector( '[data-rk-sessions]' );
		if ( ! root ) return;

		var dataEl = document.getElementById( 'rk-sess-data' );
		var detail = root.querySelector( '[data-rk-sess-detail]' );
		var items  = root.querySelectorAll( '[data-rk-sess-item]' );

		if ( ! dataEl || ! detail || ! items.length ) return;

		var sessions;
		try {
			sessions = JSON.parse( dataEl.textContent || '[]' );
		} catch ( e ) {
			return; // JSON invalide : on garde le rendu PHP initial (première séance), pas d'interactivité, mais rien de cassé.
		}

		items.forEach( function ( item ) {
			item.addEventListener( 'click', function () {
				selectSession( item );
			} );
			item.addEventListener( 'keydown', function ( e ) {
				if ( e.key === 'Enter' || e.key === ' ' ) {
					e.preventDefault();
					selectSession( item );
				}
			} );
		} );

		function selectSession( item ) {
			var index = parseInt( item.getAttribute( 'data-index' ) || '-1', 10 );
			var data  = sessions[ index ];
			if ( ! data ) return;

			items.forEach( function ( i ) { i.classList.remove( 'is-selected' ); } );
			item.classList.add( 'is-selected' );

			detail.innerHTML = buildDetailHtml( data );
		}

		function buildDetailHtml( d ) {
			var html = '';

			if ( d.thumbnail ) {
				html += '<img class="rk-sess__detail-img" src="' + attr( d.thumbnail ) + '" alt="" loading="lazy">';
			}
			if ( d.category ) {
				html += '<div class="rk-sess__detail-cat">' + iconBook() + text( d.category ) + '</div>';
			}
			html += '<h2 class="rk-sess__detail-title">' + text( d.title ) + '</h2>';

			if ( d.coachName ) {
				html += '<div class="rk-sess__detail-coach">'
					+ '<img class="rk-sess__detail-coach-avatar" src="' + attr( d.coachAvatar ) + '" alt="" width="32" height="32" loading="lazy">'
					+ '<span class="rk-sess__detail-coach-name">المدربة ' + text( d.coachName ) + '</span>'
					+ '</div>';
			}

			html += '<div class="rk-sess__detail-datetime">' + iconCalendar()
				+ '<span>' + text( d.dayLabel ) + '، الساعة ' + text( d.time ) + '</span></div>';

			if ( d.isPast ) {
				html += '<div class="rk-sess__detail-cta rk-sess__detail-cta--done">' + iconCheck() + 'انتهت</div>';
			} else if ( d.meetingUrl ) {
				html += '<a href="' + attr( d.meetingUrl ) + '" target="_blank" rel="noopener noreferrer" class="rk-adv2__cta rk-adv2__cta--go rk-sess__detail-cta">'
					+ 'انضم الآن'
					+ '<svg class="rk-adv2__cta-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" aria-hidden="true"><polyline points="15 18 9 12 15 6"/></svg>'
					+ '</a>';
			}

			return html;
		}

		/* ── Échappement HTML minimal (mêmes principes que les autres
		   modules JS du plugin, ex. rk-adventures-grid.js) ── */
		function text( s ) {
			return String( s == null ? '' : s ).replace( /[<>&]/g, function ( c ) {
				return { '<': '&lt;', '>': '&gt;', '&': '&amp;' }[ c ];
			} );
		}
		function attr( s ) {
			return text( s ).replace( /"/g, '&quot;' );
		}

		/* ── Icônes inline — copie EXACTE des tracés de rk_mc_svg()
		   ('book', 'calendar', 'check' — voir rk-mc-svg-icons.php),
		   dupliqués ici volontairement : ce JS n'a pas accès au
		   registre PHP d'icônes. Vérifiés trait pour trait contre la
		   source pour garantir un rendu identique. ── */
		function iconBook() {
			// Le registre nomme cette icône 'book' mais son tracé réel
			// est un calendrier avec coche (pas un livre) — copié tel
			// quel pour rester visuellement identique au reste du site.
			return '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
				+ '<rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>'
				+ '<line x1="16" y1="2" x2="16" y2="6"/>'
				+ '<line x1="8" y1="2" x2="8" y2="6"/>'
				+ '<line x1="3" y1="10" x2="21" y2="10"/>'
				+ '<polyline points="9 16 11 18 15 14"/>'
				+ '</svg>';
		}
		function iconCalendar() {
			return '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
				+ '<rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>'
				+ '<line x1="16" y1="2" x2="16" y2="6"/>'
				+ '<line x1="8" y1="2" x2="8" y2="6"/>'
				+ '<line x1="3" y1="10" x2="21" y2="10"/>'
				+ '</svg>';
		}
		function iconCheck() {
			return '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg>';
		}
	}
} )();
