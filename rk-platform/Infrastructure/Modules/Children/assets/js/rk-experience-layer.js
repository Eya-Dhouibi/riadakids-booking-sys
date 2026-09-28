/**
 * RiadaKids — Child Experience Layer
 *
 * RKEventQueue : récupère la file d'événements non vus au chargement
 * de la page (Dashboard OU vraie page Tutor, voir class-rk-mc-assets.php),
 * les présente un par un (jamais 5 popups simultanés, §20), marque
 * chaque événement comme vu une fois affiché.
 *
 * Vanilla JS, aucune dépendance — conforme §24 (performance).
 *
 * @since 9.9.0
 */
( function () {
	'use strict';

	if ( typeof window.rkExperience === 'undefined' ) return;

	document.addEventListener( 'DOMContentLoaded', init );

	var prefersReducedMotion = window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;
	var root = null;
	var toastStack = null;
	var activeModal = null;
	var lastFocusedBeforeModal = null;

	function init() {
		ensureRoot();
		fetchEntryStory( function () {
			// La queue d'événements en cours de session n'apparaît
			// qu'APRÈS l'Entry Story (ou immédiatement si aucune Entry
			// Story pertinente) — jamais les deux simultanément (§7).
			fetchQueue();
		} );
		fetchGuidance();
		maybeShowOnboarding();
	}

	function ensureRoot() {
		if ( root ) return;
		root = document.createElement( 'div' );
		root.className = 'rk-exp-root';
		document.body.appendChild( root );

		toastStack = document.createElement( 'div' );
		toastStack.className = 'rk-exp-toast-stack';
		toastStack.setAttribute( 'aria-live', 'polite' );
		root.appendChild( toastStack );
	}

	/**
	 * RKGuidance (§16-18) — bannière discrète insérée dans le DOM
	 * (pas un popup flottant, cohérent avec "toujours comprendre où il
	 * est / quoi faire ensuite" sans interrompre). Ne s'affiche QUE si
	 * un point d'ancrage existe réellement sur la page (voir
	 * data-rk-guidance-anchor) — sur les pages qui n'en ont pas, la
	 * guidance est simplement absente plutôt que forcée ailleurs.
	 */
	function fetchGuidance() {
		var anchor = document.querySelector( '[data-rk-guidance-anchor]' );
		if ( ! anchor ) return; // pas de point d'ancrage sur cette page — pas de guidance forcée

		var body = new URLSearchParams();
		body.set( 'action', 'rk_guidance_get' );
		body.set( 'nonce', window.rkExperience.nonce );

		fetch( window.rkExperience.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString(),
		} )
			.then( function ( res ) { return res.json(); } )
			.then( function ( json ) {
				if ( ! json || ! json.success ) return;
				showGuidance( anchor, json.data );
			} )
			.catch( function () {} );
	}

	function showGuidance( anchor, data ) {
		// Une seule guidance affichée par visite — si déjà présente
		// (double init improbable mais défensif), ne pas dupliquer.
		if ( anchor.querySelector( '.rk-exp-guidance' ) ) return;

		var banner = document.createElement( 'div' );
		banner.className = 'rk-exp-guidance rk-exp-root';
		banner.setAttribute( 'role', 'status' );
		banner.innerHTML =
			'<p class="rk-exp-guidance__text">' + text( data.message ) + '</p>'
			+ ( data.cta_label
				? '<button type="button" class="rk-exp-guidance__cta" data-rk-exp-guidance-cta>' + text( data.cta_label ) + '</button>'
				: '' );

		var ctaBtn = banner.querySelector( '[data-rk-exp-guidance-cta]' );
		if ( ctaBtn ) {
			ctaBtn.addEventListener( 'click', function () { handleCta( data.cta_action ); } );
		}

		anchor.appendChild( banner );
	}

	/**
	 * RKFirstVisitGuide (§17) — 5 étapes courtes pointant vers des
	 * éléments RÉELS déjà présents dans la navigation (aucun élément
	 * fictif créé). Skippable à tout moment, jamais réaffiché une fois
	 * vu (persistance via user_meta côté serveur), rejouable si un
	 * appelant externe invoque window.RKFirstVisitGuide.replay().
	 */
	var ONBOARDING_STEPS = [
		{ selector: null, title: 'هذه مغامرتك', text: 'من هنا تتابع كل ما يخصك في ريادة كيدز.' },
		{ selector: 'a[href*="enrolled-courses"]', title: 'هنا تجد مغامراتك', text: 'اضغط هنا لرؤية جميع دروسك.' },
		{ selector: 'a[href*="rk-badges"]', title: 'وهنا يمكنك رؤية تقدمك', text: 'شاراتك وإنجازاتك تظهر هنا.' },
		{ selector: 'a[href*="rk-sessions"]', title: 'وهنا لقاءاتك', text: 'تابع جلساتك القادمة والسابقة من هنا.' },
		{ selector: null, title: 'هيا نبدأ!', text: 'مغامرتك تنتظرك — بالتوفيق!' },
	];

	function maybeShowOnboarding() {
		/*
		 * CORRECTIF — état inconnu = Dashboard accessible, jamais bloqué.
		 *
		 * Avant : `if ( ! window.rkExperience.isFirstVisit ) return;`
		 *
		 * Deux défauts réels dans cette seule ligne :
		 *
		 *  1. Si window.rkExperience lui-même est undefined (script pas
		 *     encore initialisé, échec d'enqueue, erreur PHP silencieuse
		 *     empêchant wp_localize_script), la lecture de .isFirstVisit
		 *     lève une TypeError. Cette exception, non catchée dans
		 *     init(), interrompt l'exécution — mais SANS jamais avoir
		 *     appelé runOnboarding(), donc ce cas précis ne créait pas
		 *     l'overlay. Corrigé quand même pour ne plus dépendre de ce
		 *     hasard : vérifié explicitement ci-dessous.
		 *
		 *  2. isFirstVisit est une valeur BOOLÉENNE côté PHP
		 *     ($child_id > 0 && ! get_user_meta(...)), donc jamais une
		 *     string ambiguë ("0", "false", "") dans ce cas précis — mais
		 *     rien dans le JS ne le GARANTISSAIT explicitement. Toute
		 *     évolution future du PHP qui renverrait accidentellement une
		 *     string aurait pu faire passer ce test par une comparaison
		 *     implicite. Normalisé : seule la valeur stricte `true`
		 *     autorise l'onboarding — tout le reste (false, undefined,
		 *     null, "", "0", "false", erreur) ferme la porte au modal,
		 *     jamais l'inverse.
		 */
		var isFirstVisit = ( typeof window.rkExperience === 'object'
			&& window.rkExperience !== null
			&& window.rkExperience.isFirstVisit === true );

		if ( window.console && typeof window.rkExperience !== 'undefined' ) {
			console.log( '[ONBOARDING] isFirstVisit=' + isFirstVisit
				+ ' | raw=' + JSON.stringify( window.rkExperience.isFirstVisit ) );
		}

		if ( ! isFirstVisit ) return;
		// L'onboarding n'a de sens que sur le vrai Dashboard (avec sa
		// navigation complète) — pas sur une page Tutor isolée.
		if ( ! document.querySelector( '.rkd4-nav' ) ) return;

		if ( window.console ) console.log( '[ONBOARDING] runOnboarding start' );
		runOnboarding( 0 );
	}

	/**
	 * Point de fermeture UNIQUE de l'onboarding (§8 du correctif).
	 *
	 * Garantit dans TOUS les cas, y compris un échec réseau de
	 * finishOnboarding() : l'overlay est retiré du DOM (pas juste caché
	 * visuellement — voir §10, jamais opacity:0 + pointer-events:auto),
	 * le highlight de l'étape en cours est nettoyé, et le Dashboard
	 * redevient immédiatement cliquable. Aucun état intermédiaire où
	 * l'overlay existerait encore dans le DOM après cet appel.
	 */
	function closeOnboarding( overlay, targetEl ) {
		if ( overlay && overlay.parentNode ) overlay.remove();
		if ( targetEl ) targetEl.classList.remove( 'rk-exp-onboard-highlight' );
	}

	function runOnboarding( stepIndex ) {
		if ( stepIndex >= ONBOARDING_STEPS.length ) {
			finishOnboarding();
			return;
		}

		var step = ONBOARDING_STEPS[ stepIndex ];
		var targetEl = step.selector ? document.querySelector( step.selector ) : null;

		var overlay = document.createElement( 'div' );
		overlay.className = 'rk-exp-onboard rk-exp-root';
		overlay.setAttribute( 'role', 'dialog' );
		overlay.setAttribute( 'aria-modal', 'true' );
		overlay.setAttribute( 'aria-label', step.title );

		var highlightRect = targetEl ? targetEl.getBoundingClientRect() : null;

		overlay.innerHTML = buildOnboardingCard( step, stepIndex, ONBOARDING_STEPS.length, highlightRect );
		document.body.appendChild( overlay );

		if ( targetEl ) targetEl.classList.add( 'rk-exp-onboard-highlight' );

		var nextBtn = overlay.querySelector( '[data-rk-onboard-next]' );
		var skipBtn = overlay.querySelector( '[data-rk-onboard-skip]' );

		/*
		 * §7 — Fermeture LOCALE immédiate, jamais conditionnée à l'AJAX.
		 * closeOnboarding() s'exécute en premier, de façon synchrone :
		 * le Dashboard est débloqué avant même que la requête réseau ne
		 * parte. finishOnboarding()/runOnboarding() qui suivent ne
		 * peuvent plus, par construction, laisser un overlay en place
		 * quel que soit leur résultat.
		 */
		nextBtn.addEventListener( 'click', function () {
			closeOnboarding( overlay, targetEl );
			runOnboarding( stepIndex + 1 );
		} );
		skipBtn.addEventListener( 'click', function () {
			closeOnboarding( overlay, targetEl );
			finishOnboarding();
		} );

		// §9 — Escape : sécurité supplémentaire, pas la correction
		// principale (qui est la condition d'ouverture ci-dessus).
		function onKeydown( e ) {
			if ( 'Escape' !== e.key ) return;
			document.removeEventListener( 'keydown', onKeydown );
			closeOnboarding( overlay, targetEl );
			finishOnboarding();
		}
		document.addEventListener( 'keydown', onKeydown );

		nextBtn.focus();
	}

	function buildOnboardingCard( step, index, total, rect ) {
		var cardStyle = '';
		if ( rect && rect.width ) {
			// Positionne la carte près de l'élément ciblé, avec repli
			// centré si l'élément n'est pas visible (ex. sidebar cachée
			// en mobile) — jamais une carte hors-écran.
			var top = rect.bottom + 12;
			if ( top > window.innerHeight - 160 ) top = Math.max( 12, rect.top - 140 );
			cardStyle = ' style="position:fixed;top:' + top + 'px;inset-inline-start:' + Math.max( 12, Math.min( rect.left, window.innerWidth - 300 ) ) + 'px;"';
		}

		return '<div class="rk-exp-onboard__backdrop"></div>'
			+ '<div class="rk-exp-onboard__card"' + cardStyle + '>'
			+   '<p class="rk-exp-onboard__step">' + ( index + 1 ) + ' / ' + total + '</p>'
			+   '<h3 class="rk-exp-onboard__title">' + text( step.title ) + '</h3>'
			+   '<p class="rk-exp-onboard__text">' + text( step.text ) + '</p>'
			+   '<div class="rk-exp-onboard__actions">'
			+     '<button type="button" class="rk-exp-onboard__skip" data-rk-onboard-skip>تخطي</button>'
			+     '<button type="button" class="rk-exp-onboard__next" data-rk-onboard-next>' + ( index + 1 === total ? 'ابدأ' : 'التالي' ) + '</button>'
			+   '</div>'
			+ '</div>';
	}

	function finishOnboarding() {
		/*
		 * §7 — Ce fetch() est un pur "fire and forget" de tracking. Le
		 * Dashboard est DÉJÀ débloqué à ce stade (closeOnboarding() a
		 * déjà retiré l'overlay avant que finishOnboarding() ne soit
		 * appelée — voir runOnboarding()). Que cet appel réussisse,
		 * échoue, ou timeout n'a plus aucune incidence sur
		 * l'interactivité de la page : seul le log ci-dessous en garde
		 * une trace, pour diagnostic.
		 */
		var body = new URLSearchParams();
		body.set( 'action', 'rk_onboarding_mark_seen' );
		body.set( 'nonce', window.rkExperience.nonce );
		fetch( window.rkExperience.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString(),
		} )
			.then( function ( r ) { return r.json(); } )
			.then( function ( res ) {
				if ( window.console ) {
					console.log( '[ONBOARDING] finishOnboarding ' + ( res && res.success ? 'success' : 'server_error' ) );
				}
			} )
			.catch( function () {
				if ( window.console ) console.log( '[ONBOARDING] finishOnboarding network_error (Dashboard non affecté)' );
			} );
	}

	// Rejouable à la demande (§17 "replayable") — ex. un futur bouton
	// "؟" dans les réglages pourrait appeler window.RKFirstVisitGuide.replay().
	window.RKFirstVisitGuide = {
		replay: function () { runOnboarding( 0 ); }
	};

	/**
	 * RKEntryStory (§4-10) — séquence d'accueil, UNE seule histoire
	 * prioritaire (§7, sélectionnée côté serveur par
	 * RKP_EntryStoryService — aucune logique de priorité ici, juste
	 * l'affichage). Bottom sheet sur mobile, respecte §6 (séquence
	 * d'animation exacte : overlay léger → sheet spring → mascotte →
	 * message → CTA).
	 */
	function fetchEntryStory( onDone ) {
		var body = new URLSearchParams();
		body.set( 'action', 'rk_entry_story_get' );
		body.set( 'nonce', window.rkExperience.nonce );

		fetch( window.rkExperience.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString(),
		} )
			.then( function ( res ) { return res.json(); } )
			.then( function ( json ) {
				if ( ! json || ! json.success || ! json.data ) { if ( onDone ) onDone(); return; }
				showEntryStory( json.data, onDone );
			} )
			.catch( function () { if ( onDone ) onDone(); } );
	}

	function showEntryStory( story, onDone ) {
		var backdrop = document.createElement( 'div' );
		backdrop.className = 'rk-exp-story-backdrop';

		var sheet = document.createElement( 'div' );
		sheet.className = 'rk-exp-story-sheet';
		sheet.setAttribute( 'role', 'dialog' );
		sheet.setAttribute( 'aria-modal', 'true' );
		sheet.setAttribute( 'aria-labelledby', 'rk-exp-story-title' );
		sheet.setAttribute( 'tabindex', '-1' );

		sheet.innerHTML = buildEntryStoryInner( story );
		backdrop.appendChild( sheet );
		root.appendChild( backdrop );

		var closeAll = function () {
			backdrop.classList.add( 'is-leaving' );
			setTimeout( function () {
				backdrop.remove();
				if ( onDone ) onDone();
			}, prefersReducedMotion ? 0 : 220 );
		};

		var laterBtn = sheet.querySelector( '[data-rk-story-later]' );
		var ctaBtn   = sheet.querySelector( '[data-rk-story-cta]' );
		laterBtn.addEventListener( 'click', closeAll );
		backdrop.addEventListener( 'click', function ( e ) { if ( e.target === backdrop ) closeAll(); } );
		if ( ctaBtn ) {
			ctaBtn.addEventListener( 'click', function () {
				handleCta( story.cta_action );
				closeAll();
			} );
		}

		// §10 — Swipe vertical, jamais obligatoire ("Plus tard" toujours
		// disponible en repli). Seuil de 60px pour éviter un dismiss
		// accidentel sur un simple tap tremblant.
		attachSwipeToDismiss( sheet, closeAll );

		document.addEventListener( 'keydown', function onKey( e ) {
			if ( 'Escape' === e.key ) { document.removeEventListener( 'keydown', onKey ); closeAll(); }
		} );

		( ctaBtn || laterBtn ).focus();

		var progressFill = sheet.querySelector( '[data-rk-story-progress]' );
		if ( progressFill ) {
			var pct = parseInt( progressFill.getAttribute( 'data-rk-story-progress' ), 10 ) || 0;
			// Requestanimationframe pour laisser le navigateur peindre la
			// largeur 0 initiale avant de déclencher la transition CSS —
			// sinon le passage à la vraie valeur ne s'anime pas.
			requestAnimationFrame( function () {
				requestAnimationFrame( function () { progressFill.style.width = pct + '%'; } );
			} );
		}
	}

	function buildEntryStoryInner( story ) {
		var iconSvg = ICONS[ story.icon ] || ICONS.rocket;
		var extra = story.extra || {};

		var extraHtml = '';
		if ( 'session_today' === story.type || 'session_upcoming' === story.type ) {
			extraHtml = '<div class="rk-exp-story__extra">'
				+ ( extra.time ? '<span>' + text( extra.time ) + '</span>' : '' )
				+ ( extra.coach_name ? '<span>' + text( extra.coach_name ) + '</span>' : '' )
				+ '</div>';
		} else if ( 'number' === typeof extra.progress_pct ) {
			extraHtml = '<div class="rk-exp-story__progress"><div class="rk-exp-story__progress-fill" data-rk-story-progress="' + extra.progress_pct + '"></div></div>';
		}

		return '<div class="rk-exp-story__handle" aria-hidden="true"></div>'
			+ '<div class="rk-exp-story__mascot-wrap">'
			+   '<div class="rk-exp-story__mascot" aria-hidden="true">' + iconSvg + '</div>'
			+ '</div>'
			+ '<div class="rk-exp-story__bubble">'
			+   '<h2 id="rk-exp-story-title" class="rk-exp-story__title">' + text( story.title ) + '</h2>'
			+   '<p class="rk-exp-story__message">' + text( story.message ) + '</p>'
			+   extraHtml
			+ '</div>'
			+ ( story.cta_label ? '<button type="button" class="rk-exp-story__cta" data-rk-story-cta>' + text( story.cta_label ) + '</button>' : '' )
			+ '<button type="button" class="rk-exp-story__later" data-rk-story-later>لاحقاً</button>';
	}

	function attachSwipeToDismiss( sheet, onDismiss ) {
		var startY = null;
		var currentY = 0;

		sheet.addEventListener( 'touchstart', function ( e ) {
			startY = e.touches[ 0 ].clientY;
		}, { passive: true } );

		sheet.addEventListener( 'touchmove', function ( e ) {
			if ( null === startY ) return;
			currentY = e.touches[ 0 ].clientY - startY;
			if ( currentY > 0 ) {
				sheet.style.transform = 'translateY(' + currentY + 'px)';
			}
		}, { passive: true } );

		sheet.addEventListener( 'touchend', function () {
			if ( currentY > 80 ) {
				onDismiss();
			} else {
				sheet.style.transform = '';
			}
			startY = null;
			currentY = 0;
		} );
	}

	/**
	 * Petit registre d'icônes inline nécessaire côté JS (le registre PHP
	 * rk_mc_svg() n'est pas accessible ici) — mêmes tracés que
	 * Modules/Children/includes/rk-mc-svg-icons.php pour rester
	 * cohérent, dupliqués volontairement pour les mêmes raisons déjà
	 * documentées ailleurs dans ce fichier (iconBook/iconCalendar).
	 */
	var ICONS = {
		award: '<svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="8" r="6"/><path d="M15.477 12.89L17 22l-5-3-5 3 1.523-9.11"/></svg>',
		'nav-target': '<svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="5"/><circle cx="12" cy="12" r="1.2" fill="currentColor" stroke="none"/></svg>',
		session: '<svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18"/><path d="M12 14v3l2 1.5"/></svg>',
		'book-open': '<svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg>',
		progress: '<svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 3a9 9 0 0 1 9 9"/></svg>',
		journey: '<svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 20c3-6 6-2 9-8s6-2 9-8"/><circle cx="3" cy="20" r="1.5"/><circle cx="21" cy="4" r="1.5"/></svg>',
		rocket: '<svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4.5 16.5c-1.5 1.26-2 5-2 5s3.74-.5 5-2c.71-.84.7-2.13-.09-2.91a2.18 2.18 0 0 0-2.91-.09z"/><path d="M12 15l-3-3a22 22 0 0 1 2-3.95A12.88 12.88 0 0 1 22 2c0 2.72-.78 7.5-6 11a22.35 22.35 0 0 1-4 2z"/></svg>',
	};

	function fetchQueue() {
		var body = new URLSearchParams();
		body.set( 'action', 'rk_experience_get_queue' );
		body.set( 'nonce', window.rkExperience.nonce );

		fetch( window.rkExperience.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString(),
		} )
			.then( function ( res ) { return res.json(); } )
			.then( function ( json ) {
				if ( ! json || ! json.success || ! Array.isArray( json.data.items ) ) return;
				presentQueue( json.data.items );
			} )
			.catch( function () {} );
	}

	function presentQueue( items ) {
		if ( ! items.length ) return;

		var modalCandidate = items.find( function ( it ) { return 'modal' === it.display; } );
		var rest = items.filter( function ( it ) { return it !== modalCandidate; } );

		if ( modalCandidate ) {
			showModal( modalCandidate, function () {
				markSeen( [ modalCandidate.event_id ] );
				queueToasts( rest );
			} );
		} else {
			queueToasts( items );
		}
	}

	function queueToasts( items ) {
		items.forEach( function ( item, index ) {
			setTimeout( function () {
				showToast( item );
				markSeen( [ item.event_id ] );
			}, index * 600 );
		} );
	}

	function showModal( item, onClose ) {
		var backdrop = document.createElement( 'div' );
		backdrop.className = 'rk-exp-modal-backdrop';

		var modal = document.createElement( 'div' );
		modal.className = 'rk-exp-modal';
		modal.setAttribute( 'role', 'dialog' );
		modal.setAttribute( 'aria-modal', 'true' );
		modal.setAttribute( 'aria-labelledby', 'rk-exp-modal-title' );
		modal.setAttribute( 'tabindex', '-1' );

		modal.innerHTML = buildModalInner( item );
		backdrop.appendChild( modal );
		root.appendChild( backdrop );

		if ( ! prefersReducedMotion && 'confetti-short' === item.animation ) {
			spawnConfetti( modal );
		}

		lastFocusedBeforeModal = document.activeElement;
		activeModal = { backdrop: backdrop, onClose: onClose };

		var closeBtn = modal.querySelector( '[data-rk-exp-close]' );
		var ctaBtn   = modal.querySelector( '[data-rk-exp-cta]' );

		closeBtn.addEventListener( 'click', closeModal );
		backdrop.addEventListener( 'click', function ( e ) {
			if ( e.target === backdrop ) closeModal();
		} );
		if ( ctaBtn ) {
			ctaBtn.addEventListener( 'click', function () {
				handleCta( item.cta_action );
				closeModal();
			} );
		}

		document.addEventListener( 'keydown', onModalKeydown );
		( ctaBtn || closeBtn ).focus();

		if ( item.reward ) {
			animateCounter( modal.querySelector( '[data-rk-exp-xp-value]' ), item.reward );
		}
	}

	function buildModalInner( item ) {
		var html = '<button type="button" class="rk-exp-modal__close" data-rk-exp-close aria-label="إغلاق">'
			+ '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>'
			+ '</button>';

		if ( item.illustration ) {
			html += '<div class="rk-exp-modal__illustration-wrap">'
				+ '<img src="' + attr( item.illustration ) + '" alt="" class="rk-exp-modal__illustration' + ( 'gentle-fade' !== item.animation ? ' is-animated' : '' ) + '" loading="lazy">'
				+ '</div>';
		}

		html += '<h2 id="rk-exp-modal-title" class="rk-exp-modal__title">' + text( item.title ) + '</h2>';
		html += '<p class="rk-exp-modal__message">' + text( item.message ) + '</p>';

		if ( 'number' === typeof item.score ) {
			html += '<div class="rk-exp-modal__score">' + item.score + '%</div>';
		}

		if ( item.reward ) {
			html += '<div class="rk-exp-modal__xp">+<span data-rk-exp-xp-value>0</span> XP</div>';
		}

		if ( item.cta_label ) {
			html += '<button type="button" class="rk-exp-modal__cta" data-rk-exp-cta>' + text( item.cta_label ) + '</button>';
		}

		return html;
	}

	function closeModal() {
		if ( ! activeModal ) return;
		var backdrop = activeModal.backdrop;
		var onClose  = activeModal.onClose;
		document.removeEventListener( 'keydown', onModalKeydown );

		backdrop.remove();
		activeModal = null;
		if ( lastFocusedBeforeModal && lastFocusedBeforeModal.focus ) lastFocusedBeforeModal.focus();
		if ( onClose ) onClose();
	}

	function onModalKeydown( e ) {
		if ( ! activeModal ) return;
		if ( 'Escape' === e.key ) {
			closeModal();
			return;
		}
		if ( 'Tab' !== e.key ) return;

		var focusables = activeModal.backdrop.querySelectorAll( 'button' );
		if ( ! focusables.length ) return;
		var first = focusables[ 0 ];
		var last  = focusables[ focusables.length - 1 ];

		if ( e.shiftKey && document.activeElement === first ) {
			e.preventDefault();
			last.focus();
		} else if ( ! e.shiftKey && document.activeElement === last ) {
			e.preventDefault();
			first.focus();
		}
	}

	function showToast( item ) {
		var toast = document.createElement( 'div' );
		toast.className = 'rk-exp-toast';
		toast.setAttribute( 'role', 'status' );

		var iconHtml = item.illustration
			? '<img src="' + attr( item.illustration ) + '" alt="" loading="lazy">'
			: '';

		toast.innerHTML =
			'<span class="rk-exp-toast__icon">' + iconHtml + '</span>'
			+ '<span class="rk-exp-toast__body">'
			+   '<p class="rk-exp-toast__title">' + text( item.title ) + '</p>'
			+   '<p class="rk-exp-toast__message">' + text( item.message ) + '</p>'
			+   ( item.cta_label ? '<button type="button" class="rk-exp-toast__cta" data-rk-exp-toast-cta>' + text( item.cta_label ) + '</button>' : '' )
			+ '</span>'
			+ '<button type="button" class="rk-exp-toast__close" data-rk-exp-toast-close aria-label="إغلاق">'
			+   '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>'
			+ '</button>';

		toastStack.appendChild( toast );

		var ctaBtn = toast.querySelector( '[data-rk-exp-toast-cta]' );
		if ( ctaBtn ) {
			ctaBtn.addEventListener( 'click', function () {
				handleCta( item.cta_action );
				dismissToast( toast );
			} );
		}
		toast.querySelector( '[data-rk-exp-toast-close]' ).addEventListener( 'click', function () {
			dismissToast( toast );
		} );

		var duration = item.duration || 4500;
		setTimeout( function () { dismissToast( toast ); }, prefersReducedMotion ? duration + 2000 : duration );
	}

	function dismissToast( toast ) {
		if ( ! toast || ! toast.parentNode ) return;
		toast.classList.add( 'is-leaving' );
		setTimeout( function () { toast.remove(); }, prefersReducedMotion ? 0 : 240 );
	}

	function markSeen( eventIds ) {
		var body = new URLSearchParams();
		body.set( 'action', 'rk_experience_mark_seen' );
		body.set( 'nonce', window.rkExperience.nonce );
		eventIds.forEach( function ( id ) { body.append( 'event_ids[]', id ); } );

		fetch( window.rkExperience.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString(),
		} ).catch( function () {} );
	}

	function handleCta( ctaAction ) {
		if ( ! ctaAction || 0 !== ctaAction.indexOf( 'navigate:' ) ) return;
		var target = ctaAction.slice( 'navigate:'.length );

		if ( 0 === target.indexOf( 'next_lesson:' ) ) {
			window.location.href = ( window.rkExperience.dashboardBase || '/dashboard/' ) + 'enrolled-courses/';
			return;
		}

		var base = window.rkExperience.dashboardBase || '/dashboard/';
		window.location.href = 'home' === target ? base : base + target + '/';
	}

	function animateCounter( el, target ) {
		if ( ! el ) return;
		if ( prefersReducedMotion ) { el.textContent = String( target ); return; }

		var duration = 700;
		var start = null;
		function step( ts ) {
			if ( null === start ) start = ts;
			var progress = Math.min( ( ts - start ) / duration, 1 );
			var eased = 1 - Math.pow( 1 - progress, 3 );
			el.textContent = Math.floor( eased * target );
			if ( progress < 1 ) window.requestAnimationFrame( step );
			else el.textContent = String( target );
		}
		window.requestAnimationFrame( step );
	}

	function spawnConfetti( modal ) {
		var colors = [ '#FF4411', '#4C95D7', '#34C77B', '#F5C243' ];
		var layer = document.createElement( 'div' );
		layer.className = 'rk-exp-confetti';
		for ( var i = 0; i < 16; i++ ) {
			var piece = document.createElement( 'span' );
			piece.style.left = ( Math.random() * 100 ) + '%';
			piece.style.background = colors[ i % colors.length ];
			piece.style.animationDelay = ( Math.random() * 0.3 ) + 's';
			layer.appendChild( piece );
		}
		modal.appendChild( layer );
		setTimeout( function () { layer.remove(); }, 1600 );
	}

	function text( s ) {
		return String( null == s ? '' : s ).replace( /[<>&]/g, function ( c ) {
			return { '<': '&lt;', '>': '&gt;', '&': '&amp;' }[ c ];
		} );
	}
	function attr( s ) { return text( s ).replace( /"/g, '&quot;' ); }
} )();
