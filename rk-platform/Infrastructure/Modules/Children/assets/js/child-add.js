/**
 * RK – My Children | assets/js/child-add.js  (v5.1.0)
 *
 * Responsabilités :
 *   - Écoute le clic sur #rk-save-child en mode "ajout"
 *   - Valide les champs (nom obligatoire)
 *   - Appelle RKChildrenApi.create() avec child_name, child_age, avatar_url
 *   - Sur succès : prépend la carte (HTML serveur si dispo), met à jour le compteur,
 *                  ferme la modale, toast
 *
 * Dépend de :
 *   jQuery, window.rkMC,
 *   window.RKChildrenApi, window.RKChildrenList,
 *   window.RKChildrenModal, window.RKChildrenNotifications
 */
jQuery( function ( $ ) {
    'use strict';

    var i18n = ( window.rkMC || {} ).i18n || {};

    $( document ).on( 'click', '#rk-save-child', function ( e ) {

        e.preventDefault();   // <a role="button"> : empêcher la navigation

        if ( $( this ).hasClass( 'is-disabled' ) ) {
            return;
        }

        if ( window.RKChildrenModal && window.RKChildrenModal.isEditMode() ) {
            return;
        }

        var notif  = window.RKChildrenNotifications;
        var modal  = window.RKChildrenModal;
        var list   = window.RKChildrenList;
        var api    = window.RKChildrenApi;

        notif.clearFormError();
        notif.clearFieldError( 'rk-field-name', 'rk-error-name' );
        notif.clearFieldError( 'rk-field-family', 'rk-error-family' );
        notif.clearFieldError( 'rk-field-username', 'rk-error-username' );

        var childName     = $.trim( $( '#rk-field-name' ).val() );
        var childFamily   = $.trim( $( '#rk-field-family' ).val() );
        var childUsername = $.trim( $( '#rk-field-username' ).val() );
        var childAge      = $.trim( $( '#rk-field-age' ).val() );
        var avatarUrl      = $.trim( $( '#rk-field-avatar' ).val() );

        if ( ! childName ) {
            notif.showFieldError( 'rk-field-name', 'rk-error-name', i18n.required || 'هذا الحقل مطلوب' );
            $( '#rk-field-name' ).trigger( 'focus' );
            return;
        }

        if ( ! childFamily ) {
            notif.showFieldError( 'rk-field-family', 'rk-error-family', i18n.required || 'هذا الحقل مطلوب' );
            $( '#rk-field-family' ).trigger( 'focus' );
            return;
        }

        if ( ! childUsername ) {
            notif.showFieldError( 'rk-field-username', 'rk-error-username', i18n.required || 'هذا الحقل مطلوب' );
            $( '#rk-field-username' ).trigger( 'focus' );
            return;
        }

        modal.setSaving( true );

        api.create( {
            child_name:        childName,
            child_family_name: childFamily,
            child_username:    childUsername,
            child_age:         childAge,
            avatar_url:        avatarUrl
        } )
        
            .done( function ( child ) {
                list.prependCard( child );
                list.incrementStat( 'children', 1 );
                modal.close();
                notif.showToast( '#rk-success-toast' );
            } )
            .fail( function ( errorMsg ) {
                notif.showFormError( errorMsg );
                modal.setSaving( false );
            } );
    } );

} );
