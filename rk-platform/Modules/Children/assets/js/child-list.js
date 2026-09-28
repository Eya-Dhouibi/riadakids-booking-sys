/**
 * RK – My Children | assets/js/child-list.js  (v5.1.0 — UX Refonte)
 *
 */
( function ( $ ) {
    'use strict';

    var cfg           = window.rkMC || {};
    var restUrl       = cfg.restUrl       || '';
    var restNonce     = cfg.restNonce     || '';
    var dashboardBase = cfg.dashboardBase || '/dashboard/';
    var childSpaceUrl = cfg.childSpaceUrl || '/connexion-child/';
    var i18n          = cfg.i18n          || {};

    /* ════════════════════════════════════════════════════════
     * RKChildrenSVG — Icônes SVG synchronisées avec PHP
     * (rk-mc-svg-icons.php → rk_mc_svg_library())
     * ════════════════════════════════════════════════════════ */
    window.RKChildrenSVG = {
        cake:    '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 21v-8a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v8"/><path d="M4 16s.5-1 2-1 2.5 2 4 2 2.5-2 4-2 2.5 2 4 2 2-1 2-1"/><path d="M2 21h20"/><path d="M7 8v2"/><path d="M12 8v2"/><path d="M17 8v2"/><circle cx="7" cy="5" r="1.5" fill="currentColor" stroke="none"/><circle cx="12" cy="5" r="1.5" fill="currentColor" stroke="none"/><circle cx="17" cy="5" r="1.5" fill="currentColor" stroke="none"/></svg>',
        cal:     '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>',
        chart:   '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/><line x1="2" y1="20" x2="22" y2="20"/></svg>',
        book:    '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/><polyline points="9 16 11 18 15 14"/></svg>',
        edit:    '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>',
        trash:   '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>',
        empty:   '<svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="17" y1="11" x2="23" y2="11"/><line x1="20" y1="8" x2="20" y2="14"/></svg>',
        // AJOUT (demande utilisateur — carte enfant restructurée) — miroir
        // JS exact des nouvelles icônes ajoutées dans templates/components/
        // child-card.php (voir sa doc).
        dots:    '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="5" r="1"/><circle cx="12" cy="12" r="1"/><circle cx="12" cy="19" r="1"/></svg>',
        eye:     '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8Z"/><circle cx="12" cy="12" r="3"/></svg>',
        user:    '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>',
        key:     '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>'
    };

    /* ════════════════════════════════════════════════════════
     * RKChildrenApi — Couche AJAX centralisée
     *
     * Méthodes publiques :
     *   RKChildrenApi.create( data )     → Promise (child)
     *   RKChildrenApi.update( id, data ) → Promise (child)
     *   RKChildrenApi.remove( id )       → Promise (success)
     * ════════════════════════════════════════════════════════ */
    window.RKChildrenApi = ( function () {

        var REST_HEADERS = {
            'X-WP-Nonce':       restNonce,
            'X-Requested-With': 'XMLHttpRequest'
        };

        function extractError( xhr, fallback ) {
            try {
                var parsed = JSON.parse( xhr.responseText );
                if ( parsed && parsed.message ) { return parsed.message; }
            } catch ( e ) {}
            return fallback + ' (HTTP ' + xhr.status + ')';
        }

        function create( data ) {
            var dfd = $.Deferred();
            $.ajax( {
                url:         restUrl,
                type:        'POST',
                dataType:    'json',
                contentType: 'application/x-www-form-urlencoded; charset=UTF-8',
                headers:     REST_HEADERS,
                data:        data,
                success: function ( response ) {
                    if ( response && response.success && response.child ) {
                        dfd.resolve( response.child );
                    } else {
                        dfd.reject( i18n.errorAdd || 'حدث خطأ أثناء الإضافة.' );
                    }
                },
                error: function ( xhr ) {
                    dfd.reject( extractError( xhr, i18n.errorNetwork || 'تعذر الاتصال بالخادم.' ) );
                }
            } );
            return dfd.promise();
        }

        function update( id, data ) {
            var dfd = $.Deferred();
            $.ajax( {
                url:         restUrl + '/' + parseInt( id, 10 ),
                type:        'PUT',
                dataType:    'json',
                contentType: 'application/x-www-form-urlencoded; charset=UTF-8',
                headers:     REST_HEADERS,
                data:        data,
                success: function ( response ) {
                    if ( response && response.success && response.child ) {
                        dfd.resolve( response.child );
                    } else {
                        dfd.reject( i18n.errorAdd || 'حدث خطأ.' );
                    }
                },
                error: function ( xhr ) {
                    dfd.reject( extractError( xhr, i18n.errorNetwork || 'تعذر الاتصال بالخادم.' ) );
                }
            } );
            return dfd.promise();
        }

        function remove( id ) {
            var dfd = $.Deferred();
            $.ajax( {
                url:      restUrl + '/' + parseInt( id, 10 ),
                type:     'DELETE',
                dataType: 'json',
                headers:  REST_HEADERS,
                success: function ( response ) {
                    if ( response && response.success ) {
                        dfd.resolve();
                    } else {
                        dfd.reject( i18n.errorDelete || 'حدث خطأ أثناء الحذف.' );
                    }
                },
                error: function ( xhr ) {
                    dfd.reject( extractError( xhr, i18n.errorNetwork || 'تعذر الاتصال بالخادم.' ) );
                }
            } );
            return dfd.promise();
        }

        return { create: create, update: update, remove: remove };

    } )();

    /* ════════════════════════════════════════════════════════
     * RKChildrenList — Gestion de la grille + Hero stats
     * ════════════════════════════════════════════════════════ */
    window.RKChildrenList = ( function () {

        var SVG   = window.RKChildrenSVG;
        var stats = cfg.stats || { children: 0, bookings: 0, sessions: 0, credits: 0 };

        var STAT_MAP = {
            children: '#rk-stat-children',
            bookings: '#rk-stat-bookings',
            sessions: '#rk-stat-sessions',
            credits:  '#rk-stat-credits'
        };

        function updateStatDisplay( key, value ) {
            if ( STAT_MAP[ key ] ) { $( STAT_MAP[ key ] ).text( value ); }
        }

        function incrementStat( key, delta ) {
            stats[ key ] = ( stats[ key ] || 0 ) + delta;
            updateStatDisplay( key, stats[ key ] );
        }

        function escHtml( str ) {
            return $( '<div>' ).text( String( str || '' ) ).html();
        }

        function gravatarUrl( name ) {
            return 'https://www.gravatar.com/avatar/'
                + _md5( ( name || '' ).trim().toLowerCase() )
                + '?s=120&d=mp&r=g';
        }

        function _md5( s ) {
            var m = unescape( encodeURIComponent( s ) );
            var h = 0, i;
            for ( i = 0; i < m.length; i++ ) {
                h = ( Math.imul( 31, h ) + m.charCodeAt( i ) ) | 0;
            }
            var hex = ( h >>> 0 ).toString( 16 ).padStart( 8, '0' );
            return hex + hex + hex + hex;
        }

        function buildDashboardUrl( childId ) {
            var base = dashboardBase.replace( /\/$/, '' );
            return base + '/?child_id=' + parseInt( childId, 10 );
        }

        function hasCards() {
            return $( '#rk-child-grid .rk-child-card-final' ).length > 0;
        }

        /* ── Construction HTML d'une carte simplifiée (v10.0 — sync PHP template) ── */

        var fullProfileBase = cfg.fullProfileBase || '/my-account/child-profile/';
        var rapportBase     = cfg.rapportBase     || '/my-account/rk-rapport/';

        function buildUrlWithChildId( base, childId ) {
            var sep = base.indexOf( '?' ) === -1 ? '?' : '&';
            return base.replace( /\/$/, '/' ) + sep + 'child_id=' + parseInt( childId, 10 );
        }

        function buildCard( child ) {
            var fullName  = ( ( child.child_name || '' ) + ' ' + ( child.child_family_name || '' ) ).trim();
            var avatarSrc = child.avatar_url || gravatarUrl( fullName );
            var safeId    = escHtml( child.id );
            var safeName  = escHtml( fullName );
            // AJUSTEMENT (demande utilisateur — carte restructurée) —
            // full_profile_url conservée UNIQUEMENT pour le lien "عرض" du
            // menu déroulant (voir plus bas) ; dashboard_url (déjà présent
            // dans la réponse REST, voir class-rk-mc-rest.php::
            // format_child()) devient l'action principale de la carte.
            var profileUrl   = buildUrlWithChildId( fullProfileBase, child.id );
            var rapportUrl   = buildUrlWithChildId( rapportBase,     child.id );
            var dashboardUrl = child.dashboard_url || '#';
            var username     = child.child_username || '';
            var pin          = child.child_pin || '';

            var badgeHtml = '';
            if ( child.unread_count ) {
                var n = parseInt( child.unread_count, 10 ) || 0;
                if ( n > 0 ) {
                    badgeHtml = '<span class="rk-scard-simple-badge">' + escHtml( Math.min( 99, n ) ) + '</span>';
                }
            }

            var loginHtml = '';
            if ( username || pin ) {
                loginHtml = '<div class="rk-scard-simple-login">'
                    + ( username ? '<span class="rk-scard-simple-login-item">' + SVG.user + escHtml( username ) + '</span>' : '' )
                    + ( pin      ? '<span class="rk-scard-simple-login-item">' + SVG.key  + escHtml( pin )      + '</span>' : '' )
                    + '</div>';
            }

            return (
                '<div class="rk-child-card-final rk-child-card-simple"'
                + ' data-id="'     + safeId                      + '"'
                + ' data-name="'   + safeName                    + '"'
                + ' data-family="' + escHtml( child.child_family_name || '' ) + '"'
                + ' data-age="'    + escHtml( child.child_age )  + '"'
                + ' data-avatar="' + escHtml( child.avatar_url ) + '">'

                /* ── Menu déroulant تعديل/حذف/عرض ── */
                + '<div class="rk-scard-more">'
                +   '<button type="button" class="rk-scard-more-toggle" aria-haspopup="true" aria-expanded="false"'
                +     ' aria-label="' + escHtml( i18n.btnMore || 'المزيد من الإجراءات' ) + '">'
                +     SVG.dots
                +   '</button>'
                +   '<div class="rk-scard-more-menu" role="menu" hidden>'
                +     '<a href="' + escHtml( profileUrl ) + '" class="rk-scard-more-item" role="menuitem">'
                +       SVG.eye + '<span>' + escHtml( i18n.btnView || 'عرض' ) + '</span>'
                +     '</a>'
                +     '<button type="button" class="rk-scard-more-item rk-edit-btn" data-id="' + safeId + '" role="menuitem">'
                +       SVG.edit + '<span>' + escHtml( i18n.btnEdit || 'تعديل' ) + '</span>'
                +     '</button>'
                +     '<button type="button" class="rk-scard-more-item rk-scard-more-item--danger rk-delete-btn" data-id="' + safeId + '" role="menuitem">'
                +       SVG.trash + '<span>' + escHtml( i18n.btnDelete || 'حذف' ) + '</span>'
                +     '</button>'
                +   '</div>'
                + '</div>'

                /* ── Avatar + badge ── */
                + '<div class="rk-scard-simple-avatar">'
                +   '<img src="' + escHtml( avatarSrc ) + '" alt="' + safeName + '" width="72" height="72" loading="lazy" decoding="async">'
                +   badgeHtml
                + '</div>'

                /* ── Nom ── */
                + '<h3 class="rk-scard-simple-name">' + safeName + '</h3>'

                /* ── Username + PIN ── */
                + loginHtml

                /* ── Actions principales : dashboard + rapports ── */
                + '<div class="rk-scard-simple-actions">'
                +   '<a href="' + escHtml( dashboardUrl ) + '" class="rk-action-btn rk-action-btn--dashboard">'
                +     '<span>' + escHtml( i18n.btnDashboard || 'الدخول للوحة التحكم' ) + '</span>'
                +   '</a>'
                +   '<a href="' + escHtml( rapportUrl ) + '" class="rk-action-btn rk-action-btn--reports">'
                +     '<span>' + escHtml( i18n.btnReports || 'التقارير' ) + '</span>'
                +   '</a>'
                + '</div>'

                + '</div>'  /* /.rk-child-card-final.rk-child-card-simple */
            );
        }

        /* ── État vide ── */

        function showEmptyState() {
            $( '#rk-child-grid' ).html(
                '<div class="rk-empty-msg" id="rk-empty-msg">'
                + '<span class="rk-empty-icon">' + SVG.empty + '</span>'
                + '<p>' + escHtml( i18n.emptyMsg || 'لا يوجد أطفال مسجلون حالياً.' ) + '</p>'
                + '</div>'
            );
        }

        /* ── API publique ── */

        function prependCard( child ) {
            $( '#rk-empty-msg' ).remove();
            var html = child.card_html ? child.card_html : buildCard( child );
            $( '#rk-child-grid' ).prepend( html );
        }

        function replaceCard( id, child ) {
            var html = child.card_html ? child.card_html : buildCard( child );
            $( '.rk-child-card-final[data-id="' + id + '"]' ).replaceWith( $( html ) );
        }

        function removeCard( $card, onDone ) {
            $card.fadeOut( 380, function () {
                $( this ).remove();
                if ( ! hasCards() ) { showEmptyState(); }
                if ( onDone ) { onDone(); }
            } );
        }

        return {
            buildCard:      buildCard,
            prependCard:    prependCard,
            replaceCard:    replaceCard,
            removeCard:     removeCard,
            incrementStat:  incrementStat,
            showEmptyState: showEmptyState
        };

    } )();

    /* ════════════════════════════════════════════════════════
     * More Actions Dropdown — Initialisation (DOM ready)
     *
     * AJUSTEMENT (demande utilisateur — carte enfant restructurée) —
     * classes renommées (.rk-scard-more-toggle / .rk-scard-more-menu /
     * .rk-scard-more-item, voir templates/components/child-card.php et
     * buildCard() ci-dessus) : l'ancien menu ⋮ (.rk-more-btn/
     * .rk-more-dropdown) vivait dans templates/child-card.php, un
     * template devenu obsolète (plus jamais require par aucun
     * appelant réel) — cette logique migre donc vers la vraie carte
     * active, sans dupliquer le mécanisme déjà robuste ci-dessous.
     *
     * Comportement :
     *   - Clic sur .rk-scard-more-toggle → ouvre/ferme le menu de CETTE carte
     *   - Clic en dehors                  → ferme tous les menus ouverts
     *   - Touche Escape                   → ferme tous les menus ouverts
     *   - Un seul menu ouvert à la fois
     *
     * Délégation sur document → fonctionne avec les cartes ajoutées dynamiquement
     * (prependCard / replaceCard).
     * ════════════════════════════════════════════════════════ */
    $( function () {

        /**
         * Ferme tous les menus ouverts sauf celui passé en paramètre.
         *
         * @param {jQuery|null} $exceptMenu  Le menu à ne PAS fermer (null = fermer tous).
         */
        function closeAllDropdowns( $exceptMenu ) {
            $( '.rk-scard-more-menu' ).not( '[hidden]' ).each( function () {
                var $dd = $( this );
                if ( $exceptMenu && $dd.is( $exceptMenu ) ) { return; }
                $dd.prop( 'hidden', true );
                $dd.prev( '.rk-scard-more-toggle' ).attr( 'aria-expanded', 'false' );
            } );
        }

        /* Clic sur le bouton ⋮ */
        $( document ).on( 'click', '.rk-scard-more-toggle', function ( e ) {
            e.stopPropagation();

            var $btn  = $( this );
            var $menu = $btn.next( '.rk-scard-more-menu' );
            var isOpen = ! $menu.prop( 'hidden' );

            /* Fermer tous les autres d'abord */
            closeAllDropdowns( null );

            if ( ! isOpen ) {
                /* Ouvrir ce menu */
                $menu.prop( 'hidden', false );
                $btn.attr( 'aria-expanded', 'true' );

                /* Focus sur le premier item pour l'accessibilité clavier */
                $menu.find( '.rk-scard-more-item' ).first().trigger( 'focus' );
            }
            /* Si déjà ouvert : fermer (toggle géré par closeAllDropdowns ci-dessus) */
        } );

        /* Clic en dehors → fermer tout */
        $( document ).on( 'click', function () {
            closeAllDropdowns( null );
        } );

        /* Touche Escape → fermer tout */
        $( document ).on( 'keydown', function ( e ) {
            if ( e.key === 'Escape' ) {
                closeAllDropdowns( null );
            }
        } );

        /* Clic à l'intérieur du menu → ne pas propager (évite fermeture immédiate) */
        $( document ).on( 'click', '.rk-scard-more-menu', function ( e ) {
            e.stopPropagation();
        } );

        /* Après action Edit ou Delete → fermer le menu */
        $( document ).on( 'click', '.rk-scard-more-item', function () {
            closeAllDropdowns( null );
        } );

    } );

} )( jQuery );