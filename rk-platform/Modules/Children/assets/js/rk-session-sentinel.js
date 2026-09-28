/**
 * RiadaKids — Session Sentinel (Child surfaces)
 *
 * RESPONSABILITÉ UNIQUE : savoir, à tout instant, si la session qui a rendu
 * cette page est encore valide — et, si elle ne l'est plus, arrêter l'onglet
 * proprement AVANT que les gestionnaires d'erreur globaux des autres plugins
 * (Tutor LMS en particulier) n'aient l'occasion d'afficher leurs alertes
 * techniques en boucle.
 *
 * CE QUE CE FICHIER NE FAIT PAS :
 *   • il n'avale aucune erreur (pas de try/catch global, pas de display:none) ;
 *   • il n'ajoute AUCUN polling réseau : la détection locale lit un cookie ;
 *   • il ne retente jamais une requête échouée ;
 *   • il ne laisse AUCUNE promesse pendante : les requêtes en vol sont
 *     annulées via AbortController, jamais abandonnées sans résolution.
 *
 * TROIS DÉTECTEURS, un seul verdict :
 *   1. LOCAL     — le cookie marqueur de session parent a disparu ou changé.
 *                  Coût nul, fonctionne entre onglets, latence ≤ 4 s.
 *   2. RÉACTIF   — une requête same-origin revient en 401/403. Observation
 *                  passive : l'erreur suit son cours normal, on se contente
 *                  de demander UNE confirmation au serveur.
 *   3. REPRISE   — au retour de focus sur l'onglet (cas le plus fréquent :
 *                  le parent s'est déconnecté ailleurs pendant ce temps).
 *
 * @since 4.18.14
 */
( function () {
	'use strict';

	var cfg = window.rkSessionGuard;
	if ( ! cfg || ! cfg.stateUrl ) return;

	var LOCAL_CHECK_MS = 4000;   // lecture de cookie, aucune requête réseau
	var CONFIRM_MIN_MS = 5000;   // anti-rafale sur la confirmation serveur

	var terminated  = false;
	var confirming  = false;
	var lastConfirm = 0;
	var channel     = null;

	try {
		channel = ( 'BroadcastChannel' in window ) ? new window.BroadcastChannel( 'rk_session' ) : null;
	} catch ( e ) { channel = null; }

	/* ══════════════════════════════════════════════════════════════════
	 * Lecture du marqueur
	 * ══════════════════════════════════════════════════════════════════ */

	function readMarker() {
		var name  = cfg.markerCookie + '=';
		var parts = String( document.cookie || '' ).split( ';' );
		for ( var i = 0; i < parts.length; i++ ) {
			var c = parts[ i ].replace( /^\s+/, '' );
			if ( c.indexOf( name ) === 0 ) return c.substring( name.length );
		}
		return '';
	}

	/**
	 * La session a-t-elle changé depuis le rendu de la page ?
	 *
	 * Si la page a été rendue SANS parent authentifié (marqueur vide —
	 * cas d'un enfant connecté directement par PIN), il n'y a pas de
	 * session parent à surveiller : ce détecteur reste silencieux et seul
	 * le détecteur réactif s'applique.
	 */
	function markerChangedLocally() {
		if ( ! cfg.marker ) return false;
		return readMarker() !== cfg.marker;
	}

	/* ══════════════════════════════════════════════════════════════════
	 * Confirmation serveur — une seule requête, jamais en boucle
	 * ══════════════════════════════════════════════════════════════════ */

	function confirmWithServer() {
		if ( terminated || confirming ) return;

		var now = Date.now();
		if ( now - lastConfirm < CONFIRM_MIN_MS ) return;   // anti-rafale
		lastConfirm = now;
		confirming  = true;

		var url = cfg.stateUrl;
		var sep = function () { return url.indexOf( '?' ) === -1 ? '?' : '&'; };

		if ( cfg.tabId ) {
			url += sep() + encodeURIComponent( cfg.tabParam ) + '=' + encodeURIComponent( cfg.tabId );
		}
		// « Cette page a-t-elle été rendue sous un parent authentifié ? » —
		// le serveur en a besoin comme repli si la ligne de session a été
		// purgée. Aucun identifiant : un simple booléen.
		if ( cfg.renderedUnderParent ) {
			url += sep() + 'rup=1';
		}

		// Aucun nonce : cette route est publique par conception (voir
		// RK_Session_Guard::register_routes) — elle ne peut pas dépendre du
		// nonce dont elle doit justement diagnostiquer la validité.
		nativeFetch( url, { credentials: 'same-origin', cache: 'no-store' } )
			.then( function ( r ) { return r.ok ? r.json() : null; } )
			.then( function ( state ) {
				confirming = false;
				if ( ! state ) return;   // réseau hors service ≠ session perdue : on ne fait rien

				// Le marqueur renvoyé fait autorité : il resynchronise la
				// référence locale quand le parent s'est simplement reconnecté.
				if ( state.parent_authenticated && state.marker ) {
					cfg.marker = state.marker;
				}

				// Le serveur tranche via `valid`. Les champs détaillés ne
				// servent que de repli pour une réponse d'une version
				// antérieure du plugin (déploiement partiel, cache CDN).
				var usable = ( 'valid' in state )
					? !! state.valid
					: ( cfg.tabId ? !! state.child_session_usable : !! state.parent_authenticated );

				if ( ! usable ) terminate( state );
			} )
			.catch( function () { confirming = false; } );
	}

	/* ══════════════════════════════════════════════════════════════════
	 * Terminaison propre
	 * ══════════════════════════════════════════════════════════════════ */

	function terminate( state ) {
		if ( terminated ) return;
		terminated = true;

		window.__rkSessionLost = true;

		// 1) Prévenir le reste de l'application : les modules qui écoutent
		//    cet évènement arrêtent leurs timers (voir rk-messages.js,
		//    rk-coach-notifications.js…).
		try {
			window.dispatchEvent( new CustomEvent( 'rk:session-lost' ) );
		} catch ( e ) {}

		// 2) Prévenir les autres onglets enfant du même navigateur.
		if ( channel ) {
			try { channel.postMessage( { type: 'lost', url: destination( state ) } ); } catch ( e ) {}
		}

		// 3) Annuler le trafic en vol — AbortController, pas d'abandon.
		//
		//    Ce n'est pas masquer une erreur : c'est exactement ce que fait
		//    le navigateur lui-même quand on quitte une page. Les requêtes
		//    rejettent avec une AbortError, la classe d'erreur que les
		//    gestionnaires globaux traitent déjà comme « navigation en
		//    cours » — et surtout, aucune promesse ne reste pendante, aucune
		//    socket ne fuit.
		abortInFlight();

		// 4) Message humain, puis sortie IMMÉDIATE.
		//
		//    Le délai d'affichage a été supprimé : c'était la seule raison
		//    d'exister d'un « gel » du réseau. La navigation démarre tout de
		//    suite, le navigateur annule lui-même ce qui reste, et la fenêtre
		//    pendant laquelle un 401 pourrait encore atteindre le
		//    gestionnaire Tutor tombe de ~900 ms à quelques millisecondes.
		showOverlay();

		window.location.replace( destination( state ) );
	}

	/**
	 * Destination après perte de session.
	 *
	 * AUTORITÉ AU SERVEUR : lui seul lit parent_id sur la ligne de session
	 * défunte et sait donc si elle était DÉLÉGUÉE par un parent ou AUTONOME.
	 * Le client ne rejoue pas cette décision.
	 *
	 * Le repli local n'existe que pour le cas « serveur injoignable » ou
	 * « terminaison propagée par BroadcastChannel » (state === null) : on
	 * s'appuie alors sur le contexte de rendu de CETTE page — rendue sous un
	 * parent = délégation = /my-account/.
	 */
	function destination( state ) {
		if ( state && state.redirect_url ) return state.redirect_url;

		var base = cfg.renderedUnderParent
			? ( cfg.accountUrl || cfg.loginUrl )
			: ( cfg.loginUrl || cfg.accountUrl );

		if ( ! base ) return '/';

		return base + ( base.indexOf( '?' ) === -1 ? '?' : '&' ) + 'rk_session=expired';
	}

	function showOverlay() {
		if ( document.getElementById( 'rk-session-overlay' ) ) return;

		var el = document.createElement( 'div' );
		el.id  = 'rk-session-overlay';
		el.setAttribute( 'role', 'status' );
		el.style.cssText = [
			'position:fixed', 'inset:0', 'z-index:2147483000',
			'display:flex', 'align-items:center', 'justify-content:center',
			'background:rgba(255,255,255,.92)',
			'font:600 18px/1.6 system-ui,-apple-system,"Segoe UI",Tahoma,sans-serif',
			'color:#1f2937', 'text-align:center', 'padding:24px'
		].join( ';' );
		el.textContent = ( cfg.i18n && cfg.i18n.expired ) || '';

		( document.body || document.documentElement ).appendChild( el );
	}

	/* ══════════════════════════════════════════════════════════════════
	 * Observation passive du trafic + gel après terminaison
	 * ══════════════════════════════════════════════════════════════════ */

	var nativeFetch = window.fetch ? window.fetch.bind( window ) : null;
	var frozen      = false;

	/*
	 * Interrupteur unique partagé par toutes les requêtes que l'on proxifie.
	 * Un seul abort() annule tout ce qui est en vol, sans avoir à tenir un
	 * registre des promesses.
	 */
	var killSwitch = ( 'AbortController' in window ) ? new window.AbortController() : null;

	/** XHR en vol — XMLHttpRequest ignore AbortSignal, on garde les instances. */
	var liveXhr = [];

	function isSameOrigin( url ) {
		try {
			return new URL( String( url ), window.location.href ).origin === window.location.origin;
		} catch ( e ) { return false; }
	}

	function observeStatus( status, url ) {
		if ( terminated ) return;
		if ( status !== 401 && status !== 403 ) return;
		if ( ! isSameOrigin( url ) ) return;
		// Un 403 peut être légitime (droit manquant sur une ressource
		// précise). On ne conclut JAMAIS depuis le code seul : on demande
		// une confirmation, une seule fois, au serveur.
		confirmWithServer();
	}

	function abortInFlight() {
		frozen = true;

		if ( killSwitch ) { try { killSwitch.abort(); } catch ( e ) {} }

		for ( var i = 0; i < liveXhr.length; i++ ) {
			try { liveXhr[ i ].abort(); } catch ( e ) {}
		}
		liveXhr.length = 0;
	}

	/** Erreur d'annulation de la même classe que celle produite par le navigateur. */
	function abortError() {
		try {
			return new DOMException( 'RK session lost', 'AbortError' );
		} catch ( e ) {
			var err = new Error( 'RK session lost' );
			err.name = 'AbortError';
			return err;
		}
	}

	if ( nativeFetch ) {
		window.fetch = function ( input, init ) {
			// Après terminaison : rejet immédiat en AbortError. Jamais une
			// promesse pendante — un appelant qui attend indéfiniment est un
			// bug, pas une protection.
			if ( frozen ) return Promise.reject( abortError() );

			var url = ( input && input.url ) ? input.url : input;

			/*
			 * On rattache notre signal UNIQUEMENT si l'appelant n'en fournit
			 * pas déjà un : écraser le signal d'un appelant casserait sa
			 * propre logique d'annulation (recherche incrémentale, upload
			 * annulable…). Les rares requêtes qui ont leur propre signal ne
			 * sont donc pas annulées par nous — le navigateur s'en charge
			 * à la navigation, qui suit immédiatement.
			 */
			if ( killSwitch && init && typeof init === 'object' && ! init.signal ) {
				try { init = Object.assign( {}, init, { signal: killSwitch.signal } ); } catch ( e ) {}
			} else if ( killSwitch && ! init ) {
				init = { signal: killSwitch.signal };
			}

			return nativeFetch( input, init ).then( function ( res ) {
				observeStatus( res.status, url );
				return res;   // la réponse suit son chemin normal, intacte
			} );
		};
	}

	var XHR = window.XMLHttpRequest;
	if ( XHR && XHR.prototype ) {
		var origOpen = XHR.prototype.open;
		var origSend = XHR.prototype.send;

		XHR.prototype.open = function ( method, url ) {
			this.__rkGuardUrl = url;
			return origOpen.apply( this, arguments );
		};

		XHR.prototype.send = function () {
			var xhr = this;

			// Après terminaison : on annule via l'API native plutôt que de
			// ne rien faire. Un XHR jamais envoyé reste bloqué en readyState
			// 1 et son appelant n'est jamais notifié ; abort() déclenche
			// l'évènement 'abort' standard, que les gestionnaires savent
			// distinguer d'une erreur serveur.
			if ( frozen ) {
				try { xhr.abort(); } catch ( e ) {}
				return;
			}

			liveXhr.push( xhr );

			function forget() {
				var i = liveXhr.indexOf( xhr );
				if ( i !== -1 ) liveXhr.splice( i, 1 );
			}

			xhr.addEventListener( 'load', function () {
				forget();
				observeStatus( xhr.status, xhr.__rkGuardUrl );
			} );
			xhr.addEventListener( 'error', forget );
			xhr.addEventListener( 'abort', forget );

			return origSend.apply( this, arguments );
		};
	}

	/* ══════════════════════════════════════════════════════════════════
	 * Boucles de détection (aucune requête réseau)
	 * ══════════════════════════════════════════════════════════════════ */

	window.setInterval( function () {
		if ( terminated ) return;
		if ( markerChangedLocally() ) confirmWithServer();
	}, LOCAL_CHECK_MS );

	function onResume() {
		if ( terminated ) return;
		if ( document.visibilityState === 'hidden' ) return;
		if ( markerChangedLocally() ) confirmWithServer();
	}

	document.addEventListener( 'visibilitychange', onResume );
	window.addEventListener( 'focus', onResume );
	window.addEventListener( 'pageshow', function ( e ) {
		// Retour par le bouton « précédent » : la page vient du bfcache et
		// n'a donc PAS été re-rendue par le serveur. Vérification systématique.
		if ( e.persisted ) confirmWithServer();
		else onResume();
	} );

	if ( channel ) {
		channel.onmessage = function ( e ) {
			var d = e && e.data;
			if ( ! d ) return;

			// L'onglet qui a détecté la perte a déjà obtenu la destination
			// du serveur : on la réutilise plutôt que de refaire un appel.
			if ( d === 'lost' ) { terminate( null ); return; }
			if ( d.type === 'lost' ) {
				terminate( d.url ? { redirect_url: d.url } : null );
			}
		};
	}

	/* Vérification immédiate : la page peut avoir été servie depuis un cache. */
	if ( markerChangedLocally() ) confirmWithServer();

	window.RKSessionSentinel = {
		isLost: function () { return terminated; },
		check:  confirmWithServer
	};
} )();
