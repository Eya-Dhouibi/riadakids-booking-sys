/**
 * RK – My Children | assets/js/child-notifications.js  (v5.0.0)
 *
 * Responsabilités :
 *   - Affichage des toasts de notification (succès, erreur, info)
 *   - Gestion des messages d'erreur dans le formulaire de la modale
 *
 * Expose : window.RKChildrenNotifications
 *
 * Dépend de : jQuery, window.rkMC
 */
( function ( $ ) {
    'use strict';

    /**
     * RKChildrenNotifications
     * Module de notification découplé du reste de l'interface.
     */
    window.RKChildrenNotifications = ( function () {

        /**
         * Affiche un toast pendant 30 secondes, puis le masque automatiquement.
         * Un seul toast visible à la fois — les autres sont fermés immédiatement.
         *
         * @param {string} id  Sélecteur CSS du toast (#rk-success-toast, #rk-delete-toast…)
         */
        function showToast( id ) {
            // Fermer tous les toasts ouverts (annule les timers en cours)
            $( '.rk-toast' ).each( function () {
                var $el = $( this );
                clearTimeout( $el.data( 'rk-toast-timer' ) );
                $el.removeClass( 'rk-toast--visible' );
            } );

            var $t = $( id );
            $t.addClass( 'rk-toast--visible' );

            var timer = setTimeout( function () {
                $t.removeClass( 'rk-toast--visible' );
            }, 30000 );

            $t.data( 'rk-toast-timer', timer );
        }

        /**
         * Affiche un message d'erreur global dans le formulaire de la modale.
         *
         * @param {string} msg
         */
        function showFormError( msg ) {
            $( '#rk-form-error' ).removeAttr( 'hidden' ).text( msg );
        }

        /**
         * Efface le message d'erreur global du formulaire.
         */
        function clearFormError() {
            $( '#rk-form-error' ).attr( 'hidden', '' ).text( '' );
        }

        /**
         * Affiche une erreur sur un champ spécifique.
         *
         * @param {string} fieldId   ID du champ (sans #)
         * @param {string} errorId   ID de l'élément d'erreur (sans #)
         * @param {string} msg
         */
        function showFieldError( fieldId, errorId, msg ) {
            $( '#' + fieldId ).addClass( 'rk-input-error' );
            $( '#' + errorId ).removeAttr( 'hidden' ).text( msg );
        }

        /**
         * Efface les erreurs de champ.
         *
         * @param {string} fieldId
         * @param {string} errorId
         */
        function clearFieldError( fieldId, errorId ) {
            $( '#' + fieldId ).removeClass( 'rk-input-error' );
            $( '#' + errorId ).attr( 'hidden', '' ).text( '' );
        }

        return {
            showToast:       showToast,
            showFormError:   showFormError,
            clearFormError:  clearFormError,
            showFieldError:  showFieldError,
            clearFieldError: clearFieldError
        };

    } )();

} )( jQuery );
