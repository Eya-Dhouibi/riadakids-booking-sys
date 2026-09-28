/**
 * RiadaKids — مغامراتي (My Adventures) grid
 *
 * Deux systèmes de filtre INDÉPENDANTS coexistent ici :
 *
 * 1. Pastilles de catégorie (.rk-adv2__filters / data-rk-adv-filter) —
 *    NON MODIFIÉES dans cette révision : comportement, markup et
 *    fonctions conservés tels quels, comme demandé explicitement.
 *
 * 2. Dropdown de statut (.rk-adv2__status-dd / data-rk-adv-status-dd) —
 *    entièrement personnalisé (bouton + listbox ARIA), remplace le
 *    <select> natif d'une révision précédente : un <select> ne peut
 *    pas être stylé de façon fiable sur tous les navigateurs (son
 *    chevron/apparence natifs restaient visibles malgré
 *    appearance:none), d'où ce composant custom cohérent avec le
 *    Design System du Dashboard.
 *
 * Les deux filtres sont combinés uniquement au moment de construire
 * la requête AJAX vers admin-ajax.php (voir
 * RK_MC_Adventures_Filter_Service côté PHP), qui renvoie le HTML déjà
 * filtré de la grille — jamais de masquage/affichage de cartes déjà
 * présentes dans le DOM.
 *
 * Scope: only runs on the enrolled-courses/courses page (guarded by [data-rk-adv-grid]).
 *
 * @since 9.1.0
 * @since 9.4.0 AJAX filtering + status dropdown (première version, <select> natif).
 * @since 9.5.0 Dropdown de statut entièrement personnalisé (bouton +
 *              listbox), limité à "قيد التقدم"/"مكتملة", état neutre
 *              par défaut (aucun filtre appliqué au chargement), état
 *              vide réutilisant le composant .rk-adv2__empty du
 *              Dashboard avec bouton de réinitialisation du filtre.
 */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', init );

	function init() {
		var root = document.querySelector( '[data-rk-adv-grid]' );
		if ( ! root ) return;

		var gridList = root.querySelector( '[data-rk-adv-grid-list]' );

		if ( ! gridList || typeof window.rkAdvFilter === 'undefined' ) {
			return; // pas de config AJAX localisée (rkAdvFilter) : rien à faire côté JS.
		}

		var activeCategory = 'all';
		var activeStatus   = ''; // '' = aucun filtre de statut appliqué (état neutre initial)
		var requestToken   = 0;  // annule les réponses obsolètes si l'utilisateur enchaîne plusieurs interactions rapidement

		initCategoryPills();
		initStatusDropdown();
		initEmptyStateResetDelegation();

		/* ══════════════════════════════════════════════════════════════
		 * 1. PASTILLES DE CATÉGORIE — logique conservée telle quelle
		 * ══════════════════════════════════════════════════════════════ */
		function initCategoryPills() {
			var pills       = root.querySelectorAll( '[data-rk-adv-filter]' );
			var filtersWrap = root.querySelector( '[data-rk-adv-filters-wrap]' );
			var catTrigger  = root.querySelector( '[data-rk-adv-filter-trigger]' );
			var triggerLbl  = catTrigger ? catTrigger.querySelector( '[data-rk-adv-filter-trigger-label]' ) : null;

			pills.forEach( function ( pill ) {
				pill.addEventListener( 'click', function () {
					activeCategory = pill.getAttribute( 'data-rk-adv-filter' ) || 'all';

					pills.forEach( function ( p ) {
						var isActive = p === pill;
						p.classList.toggle( 'is-active', isActive );
						p.setAttribute( 'aria-selected', isActive ? 'true' : 'false' );
					} );

					if ( triggerLbl ) {
						triggerLbl.textContent = pill.getAttribute( 'data-rk-adv-filter-label' ) || pill.getAttribute( 'data-rk-adv-filter' );
					}

					// Séparation complète des deux mécanismes de filtre :
					// choisir une catégorie réinitialise TOUJOURS le statut à
					// l'état neutre (aucun filtre), pour qu'un statut restant
					// actif en arrière-plan ne vide jamais silencieusement le
					// résultat d'un changement de catégorie — comportement
					// confirmé explicitement par l'utilisateur.
					var statusWrap = root.querySelector( '[data-rk-adv-status-dd]' );
					if ( statusWrap && typeof statusWrap.__acbpResetStatus === 'function' ) {
						statusWrap.__acbpResetStatus( /* skipFetch */ true );
					} else {
						activeStatus = '';
					}

					fetchFilteredGrid();
					closeCategoryDropdown();
				} );
			} );

			if ( catTrigger && filtersWrap ) {
				catTrigger.addEventListener( 'click', function ( e ) {
					e.stopPropagation();
					var isOpen = filtersWrap.getAttribute( 'data-open' ) === 'true';
					isOpen ? closeCategoryDropdown() : openCategoryDropdown();
				} );

				document.addEventListener( 'click', function ( e ) {
					if ( ! filtersWrap.contains( e.target ) ) closeCategoryDropdown();
				} );

				document.addEventListener( 'keydown', function ( e ) {
					if ( e.key === 'Escape' ) closeCategoryDropdown();
				} );
			}

			function openCategoryDropdown() {
				if ( ! filtersWrap || ! catTrigger ) return;
				filtersWrap.setAttribute( 'data-open', 'true' );
				catTrigger.setAttribute( 'aria-expanded', 'true' );
			}

			function closeCategoryDropdown() {
				if ( ! filtersWrap || ! catTrigger ) return;
				filtersWrap.setAttribute( 'data-open', 'false' );
				catTrigger.setAttribute( 'aria-expanded', 'false' );
			}
		}

		/* ══════════════════════════════════════════════════════════════
		 * 2. DROPDOWN DE STATUT — composant custom (bouton + listbox)
		 * ══════════════════════════════════════════════════════════════ */
		function initStatusDropdown() {
			var wrap       = root.querySelector( '[data-rk-adv-status-dd]' );
			var statusTrig = root.querySelector( '[data-rk-adv-status-trigger]' );
			var statusList = root.querySelector( '[data-rk-adv-status-list]' );
			var statusText = root.querySelector( '[data-rk-adv-status-trigger-text]' );

			if ( ! wrap || ! statusTrig || ! statusList || ! statusText ) {
				return; // composant absent de cette vue — rien à initialiser.
			}

			var options          = Array.prototype.slice.call( statusList.querySelectorAll( '[role="option"]' ) );
			var placeholderLabel = statusText.textContent; // "اختر الحالة" — conservé pour l'état neutre / le reset

			statusTrig.addEventListener( 'click', function ( e ) {
				e.stopPropagation();
				isOpen() ? closeStatusDropdown() : openStatusDropdown();
			} );

			options.forEach( function ( opt ) {
				opt.addEventListener( 'click', function () {
					selectStatus( opt.getAttribute( 'data-value' ) || '', opt.textContent.trim() );
					closeStatusDropdown();
					statusTrig.focus();
				} );
			} );

			// Navigation clavier — flèches pour parcourir, Entrée/Espace
			// pour choisir, Échap pour fermer : comportement standard
			// d'une listbox ARIA.
			statusList.addEventListener( 'keydown', function ( e ) {
				var currentIndex = options.indexOf( document.activeElement );

				if ( e.key === 'ArrowDown' ) {
					e.preventDefault();
					focusOption( currentIndex + 1 < options.length ? currentIndex + 1 : 0 );
				} else if ( e.key === 'ArrowUp' ) {
					e.preventDefault();
					focusOption( currentIndex > 0 ? currentIndex - 1 : options.length - 1 );
				} else if ( e.key === 'Enter' || e.key === ' ' ) {
					e.preventDefault();
					if ( document.activeElement && document.activeElement.getAttribute( 'role' ) === 'option' ) {
						document.activeElement.click();
					}
				} else if ( e.key === 'Escape' ) {
					closeStatusDropdown();
					statusTrig.focus();
				}
			} );

			document.addEventListener( 'click', function ( e ) {
				if ( ! wrap.contains( e.target ) ) closeStatusDropdown();
			} );

			// Expose une fonction de réinitialisation utilisée par le
			// bouton "اكتشف المغامرات" de l'état vide (voir
			// initEmptyStateResetDelegation ci-dessous), sans dépendre
			// d'une variable globale.
			wrap.__acbpResetStatus = function ( skipFetch ) {
				selectStatus( '', placeholderLabel, /* isPlaceholder */ true, /* skipFetch */ skipFetch );
			};

			function isOpen() {
				return wrap.getAttribute( 'data-open' ) === 'true';
			}

			function openStatusDropdown() {
				wrap.setAttribute( 'data-open', 'true' );
				statusTrig.setAttribute( 'aria-expanded', 'true' );
				statusList.hidden = false;
				var selected = statusList.querySelector( '.is-selected' ) || options[ 0 ];
				if ( selected ) focusOption( options.indexOf( selected ) );
			}

			function closeStatusDropdown() {
				wrap.setAttribute( 'data-open', 'false' );
				statusTrig.setAttribute( 'aria-expanded', 'false' );
				statusList.hidden = true;
			}

			function focusOption( index ) {
				if ( options[ index ] ) options[ index ].focus();
			}

			/**
			 * @param {string}  value          '' | 'in-progress' | 'completed'
			 * @param {string}  label          Texte à afficher sur le bouton déclencheur.
			 * @param {boolean} [isPlaceholder] true pour l'état neutre (aucun filtre).
			 * @param {boolean} [skipFetch]     true pour ne pas déclencher fetchFilteredGrid()
			 *                                  ici — utilisé quand l'appelant va lui-même
			 *                                  lancer une seule requête combinée juste après
			 *                                  (voir initEmptyStateResetDelegation), ou pour
			 *                                  un reset silencieux (ex: après un clic sur une
			 *                                  pastille catégorie — voir initCategoryPills()).
			 *                                  Dans ces deux cas, la catégorie est déjà gérée
			 *                                  par l'appelant : on ne la retouche pas ici pour
			 *                                  éviter tout appel redondant.
			 */
			function selectStatus( value, label, isPlaceholder, skipFetch ) {
				activeStatus = value;
				wrap.setAttribute( 'data-value', value );

				statusText.textContent = label;
				statusText.classList.toggle( 'is-placeholder', !! isPlaceholder );

				options.forEach( function ( o ) {
					var isSelected = ( o.getAttribute( 'data-value' ) || '' ) === value && ! isPlaceholder;
					o.classList.toggle( 'is-selected', isSelected );
					o.setAttribute( 'aria-selected', isSelected ? 'true' : 'false' );
				} );

				// Séparation complète des deux mécanismes de filtre : un vrai
				// choix utilisateur de statut (ni placeholder, ni reset
				// silencieux programmatique) réinitialise TOUJOURS la
				// catégorie vers "الكل" — pour ne jamais combiner
				// silencieusement les deux filtres. Une seule requête AJAX
				// est envoyée malgré les deux changements d'état.
				if ( ! isPlaceholder && ! skipFetch ) {
					resetCategoryToAll();
					fetchFilteredGrid();
				}
			}
		}

		/* ══════════════════════════════════════════════════════════════
		 * 3. Bouton "اكتشف المغامرات" de l'état vide filtré — délégation
		 *    d'événement car ce bouton est injecté dynamiquement dans le
		 *    HTML renvoyé par l'AJAX (il n'existe pas forcément au
		 *    chargement initial de la page).
		 * ══════════════════════════════════════════════════════════════ */
		function initEmptyStateResetDelegation() {
			gridList.addEventListener( 'click', function ( e ) {
				var resetBtn = e.target.closest( '[data-rk-adv-reset-filter]' );
				if ( ! resetBtn ) return;

				// Réinitialise LES DEUX filtres : le vide peut venir du
				// statut, de la catégorie, ou de leur combinaison — le
				// bouton "اكتشف المغامرات" doit ramener à la vue complète
				// dans tous les cas, pas seulement annuler le statut.
				var statusWrap = root.querySelector( '[data-rk-adv-status-dd]' );
				if ( statusWrap && typeof statusWrap.__acbpResetStatus === 'function' ) {
					statusWrap.__acbpResetStatus( /* skipFetch */ true );
				} else {
					activeStatus = '';
				}

				resetCategoryToAll();
				fetchFilteredGrid();
			} );
		}

		/**
		 * Remet la pastille "الكل" active et son libellé sur le
		 * déclencheur mobile — n'appelle PAS fetchFilteredGrid() lui-même
		 * (initEmptyStateResetDelegation s'en charge une seule fois après
		 * avoir remis les deux filtres à zéro, pour n'envoyer qu'une
		 * seule requête AJAX au lieu de deux).
		 */
		function resetCategoryToAll() {
			activeCategory = 'all';

			var pills      = root.querySelectorAll( '[data-rk-adv-filter]' );
			var catTrigger = root.querySelector( '[data-rk-adv-filter-trigger]' );
			var triggerLbl = catTrigger ? catTrigger.querySelector( '[data-rk-adv-filter-trigger-label]' ) : null;

			pills.forEach( function ( p ) {
				var isAll = ( p.getAttribute( 'data-rk-adv-filter' ) || '' ) === 'all';
				p.classList.toggle( 'is-active', isAll );
				p.setAttribute( 'aria-selected', isAll ? 'true' : 'false' );
				if ( isAll && triggerLbl ) {
					triggerLbl.textContent = p.getAttribute( 'data-rk-adv-filter-label' ) || 'الكل';
				}
			} );
		}

		/* ══════════════════════════════════════════════════════════════
		 * 4. Requête AJAX commune aux deux filtres
		 * ══════════════════════════════════════════════════════════════ */
		function fetchFilteredGrid() {
			var token = ++requestToken;
			var statusWrap = root.querySelector( '[data-rk-adv-status-dd]' );

			gridList.setAttribute( 'aria-busy', 'true' );
			gridList.classList.add( 'is-loading' );
			if ( statusWrap ) statusWrap.classList.add( 'is-loading' );

			// Skeleton : remplace temporairement le contenu par des
			// blocs "shimmer" imitant la forme des cartes (§4 —
			// "afficher un petit loader/skeleton pendant le
			// chargement"), plutôt qu'un simple fondu qui laisserait
			// les anciennes cartes visibles et potentiellement trompeuses
			// pendant l'attente.
			gridList.innerHTML = buildSkeletonHtml();

			var body = new URLSearchParams();
			body.set( 'action', window.rkAdvFilter.action );
			body.set( 'nonce', window.rkAdvFilter.nonce );
			body.set( 'category', activeCategory );
			body.set( 'status', activeStatus );

			fetch( window.rkAdvFilter.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: body.toString(),
			} )
				.then( function ( res ) { return res.json(); } )
				.then( function ( json ) {
					if ( token !== requestToken ) return; // une requête plus récente a déjà pris le dessus

					clearLoadingState( statusWrap );

					if ( json && json.success && json.data && typeof json.data.html === 'string' ) {
						gridList.innerHTML = json.data.html;
					} else {
						showError();
					}
				} )
				.catch( function () {
					if ( token !== requestToken ) return;
					clearLoadingState( statusWrap );
					showError();
				} );
		}

		/**
		 * 4 blocs "shimmer" imitant les proportions d'une carte
		 * .rk-adv2__card (voir CSS : .rk-adv2__skeleton-card), affichés
		 * pendant l'attente de la réponse AJAX.
		 */
		function buildSkeletonHtml() {
			var card = '' +
				'<div class="rk-adv2__skeleton-card" aria-hidden="true">' +
					'<div class="rk-adv2__skeleton-media"></div>' +
					'<div class="rk-adv2__skeleton-body">' +
						'<div class="rk-adv2__skeleton-line rk-adv2__skeleton-line--sm"></div>' +
						'<div class="rk-adv2__skeleton-line rk-adv2__skeleton-line--lg"></div>' +
						'<div class="rk-adv2__skeleton-line rk-adv2__skeleton-line--md"></div>' +
					'</div>' +
				'</div>';
			return card + card + card + card;
		}

		function clearLoadingState( statusWrap ) {
			gridList.classList.remove( 'is-loading' );
			gridList.removeAttribute( 'aria-busy' );
			if ( statusWrap ) statusWrap.classList.remove( 'is-loading' );
		}

		function showError() {
			var msg = ( window.rkAdvFilter && window.rkAdvFilter.i18n && window.rkAdvFilter.i18n.error )
				? window.rkAdvFilter.i18n.error
				: 'Error';
			gridList.innerHTML = '<p class="rk-adv2__no-results" dir="rtl">' + msg.replace( /[<>&]/g, function ( c ) {
				return { '<': '&lt;', '>': '&gt;', '&': '&amp;' }[ c ];
			} ) + '</p>';
		}
	}
} )();
