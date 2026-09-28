/**
 * RiadaKids — Children Dashboard : fondations UX/UI (JS)
 *
 * Compagnon de rk-dashboard-enhance.css. Volontairement minimal —
 * Vanilla JS, aucune librairie externe (conforme à la demande de
 * rester léger / éviter les dépendances JS lourdes).
 *
 * @since 9.9.0
 */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', init );

	function init() {
		initAnimatedCounters();
		initFab();
	}

	/**
	 * Anime tout élément [data-rk-count-to="N"] de 0 (ou de sa valeur
	 * textuelle actuelle si numérique) jusqu'à N, au moment où il entre
	 * dans le viewport (IntersectionObserver) — pas au chargement de la
	 * page, pour ne jamais animer un compteur que l'utilisateur ne voit
	 * pas encore (mobile, pages longues).
	 */
	function initAnimatedCounters() {
		var counters = document.querySelectorAll( '[data-rk-count-to]' );
		if ( ! counters.length ) return;

		var prefersReducedMotion = window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;

		if ( ! window.IntersectionObserver || prefersReducedMotion ) {
			// Repli : affiche directement la valeur finale, sans animation.
			counters.forEach( function ( el ) {
				el.textContent = el.getAttribute( 'data-rk-count-to' );
			} );
			return;
		}

		var observer = new IntersectionObserver( function ( entries ) {
			entries.forEach( function ( entry ) {
				if ( ! entry.isIntersecting ) return;
				animateCounter( entry.target );
				observer.unobserve( entry.target );
			} );
		}, { threshold: 0.4 } );

		counters.forEach( function ( el ) { observer.observe( el ); } );
	}

	function animateCounter( el ) {
		var target = parseInt( el.getAttribute( 'data-rk-count-to' ) || '0', 10 );
		if ( ! target || isNaN( target ) ) {
			el.textContent = el.getAttribute( 'data-rk-count-to' ) || '';
			return;
		}

		var duration = 900; // ms — assez rapide pour ne jamais sembler lent, assez long pour être perceptible
		var start = null;

		function step( timestamp ) {
			if ( start === null ) start = timestamp;
			var progress = Math.min( ( timestamp - start ) / duration, 1 );
			// Easing "ease-out" simple, cohérent avec --rk4-ease-out en CSS.
			var eased = 1 - Math.pow( 1 - progress, 3 );
			el.textContent = Math.floor( eased * target ).toString();
			if ( progress < 1 ) {
				window.requestAnimationFrame( step );
			} else {
				el.textContent = target.toString();
			}
		}
		window.requestAnimationFrame( step );
	}

	/**
	 * Le bouton d'action flottant (.rk4-fab) n'est actif que sur les
	 * pages où une action principale claire existe — activé en ajoutant
	 * l'attribut data-rk-fab-active côté PHP (voir rk-dashboard-chrome),
	 * jamais construit dynamiquement ici : le contenu (icône + texte +
	 * lien) est toujours rendu côté serveur pour rester indexable et
	 * fonctionnel même si ce script échoue à charger.
	 *
	 * Ce script se contente ici d'une seule chose : masquer le FAB
	 * quand l'utilisateur scrolle vers le bas (pour ne pas gêner la
	 * lecture) et le montrer à nouveau en scrollant vers le haut —
	 * comportement standard d'app mobile.
	 */
	function initFab() {
		var fab = document.querySelector( '.rk4-fab[data-rk-fab-active]' );
		if ( ! fab ) return;

		var lastScrollY = window.scrollY;
		var ticking = false;

		window.addEventListener( 'scroll', function () {
			if ( ticking ) return;
			ticking = true;
			window.requestAnimationFrame( function () {
				var currentY = window.scrollY;
				var scrollingDown = currentY > lastScrollY && currentY > 80;
				fab.style.transform = scrollingDown ? 'translateY(120%)' : '';
				lastScrollY = currentY;
				ticking = false;
			} );
		}, { passive: true } );
	}
} )();
