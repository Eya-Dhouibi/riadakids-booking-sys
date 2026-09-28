/**
 * RK – My Children | assets/js/child-delete.js  (v5.0.0)
 *
 * Responsabilités :
 *   - Écoute le clic sur .rk-delete-btn
 *   - Demande confirmation (window.confirm)
 *   - Appelle RKChildrenApi.remove()
 *   - Sur succès : retire la carte avec animation, met à jour le compteur,
 *                  affiche l'état vide si nécessaire, toast
 *
 * Dépend de :
 *   jQuery, window.rkMC,
 *   window.RKChildrenApi, window.RKChildrenList,
 *   window.RKChildrenNotifications
 */
jQuery( function ( $ ) {
    'use strict';

    var i18n = ( window.rkMC || {} ).i18n || {};

    $( document ).on( 'click', '.rk-delete-btn', function ( e ) {
        e.preventDefault();
        e.stopPropagation();

        var notif = window.RKChildrenNotifications;
        var list  = window.RKChildrenList;
        var api   = window.RKChildrenApi;

        var $card   = $( this ).closest( '.rk-child-card-final' );
        var childId = $card.data( 'id' );

        if ( ! window.confirm( i18n.confirmDelete || 'هل أنت متأكد؟' ) ) {
            return;
        }

        $card.addClass( 'is-loading' );

        api.remove( childId )
            .done( function () {
                list.removeCard( $card, function () {
                    list.incrementStat( 'children', -1 );
                } );
                notif.showToast( '#rk-delete-toast' );
            } )
            .fail( function ( errorMsg ) {
                $card.removeClass( 'is-loading' );
                window.alert( errorMsg );
            } );
    } );

} );
