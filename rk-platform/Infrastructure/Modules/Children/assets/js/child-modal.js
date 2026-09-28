/**
 * RK – My Children | assets/js/child-modal.js  (v5.1.0)
 *
 * Responsabilités :
 *   - Ouverture / fermeture de la modale (ajout + édition)
 *   - Mise en mode "add" ou "edit"
 *   - Reset du formulaire (incl. champ avatar)
 *   - Prévisualisation live de l'avatar (URL → img, sinon initiales)
 *   - Gestion du spinner de sauvegarde
 *   - Liaisons clavier (Escape) et clic backdrop
 *
 * Expose : window.RKChildrenModal
 *
 * Dépend de : jQuery, window.rkMC, window.RKChildrenNotifications
 */
jQuery( function ( $ ) {
    'use strict';

    var i18n     = ( window.rkMC || {} ).i18n || {};
    var $overlay = $( '#rk-modal-overlay' );
    var isEdit   = false;

    /* ════════════════════════════════════════════════════════
     * Avatar preview helpers
     * ════════════════════════════════════════════════════════ */

    function _getInitial() {
        var name = $.trim( $( '#rk-field-name' ).val() );
        return name ? name.charAt( 0 ).toUpperCase() : '؟';
    }

    function _updateAvatarPreview( url ) {
        var $img   = $( '#rk-avatar-img' );
        var $inits = $( '#rk-avatar-initials' );
        var clean  = $.trim( url );

        if ( clean ) {
            $img.attr( 'src', clean ).removeAttr( 'hidden' );
            $inits.attr( 'hidden', '' );
        } else {
            $img.attr( 'hidden', '' ).attr( 'src', '' );
            $inits.removeAttr( 'hidden' ).text( _getInitial() );
        }
    }

    /* Live update: name → initials (when no avatar URL) */
    $( document ).on( 'input', '#rk-field-name', function () {
        if ( ! $.trim( $( '#rk-field-avatar' ).val() ) ) {
            $( '#rk-avatar-initials' ).text( _getInitial() );
        }
    } );

    /* Live update: avatar URL → image preview */
    $( document ).on( 'input', '#rk-field-avatar', function () {
        _updateAvatarPreview( $( this ).val() );
    } );

    /* ════════════════════════════════════════════════════════
     * RKChildrenModal — API publique
     * ════════════════════════════════════════════════════════ */
    window.RKChildrenModal = ( function () {

        function open( mode, child ) {
            isEdit = ( mode === 'edit' );
            _resetForm();

            if ( isEdit && child ) {
                $( '#rk-edit-child-id' ).val( child.id );
                $( '#rk-field-name' ).val( child.name );
                $( '#rk-field-family' ).val( child.family || '' );
                $( '#rk-field-username' ).val( child.username || '' );
                $( '#rk-field-age' ).val( child.age );
                $( '#rk-field-avatar' ).val( child.avatar || '' );
                $( '#rk-modal-title' ).text( i18n.editTitle || 'تعديل بيانات الطفل' );
                $( '#rk-modal-subtitle' ).hide();
                _updateAvatarPreview( child.avatar || '' );
            } else {
                $( '#rk-edit-child-id' ).val( '' );
                $( '#rk-modal-title' ).text( i18n.addTitle || 'إضافة طفل جديد' );
                $( '#rk-modal-subtitle' ).show();
                _updateAvatarPreview( '' );
            }

            $overlay.removeAttr( 'hidden' ).addClass( 'is-open' );
            $( '#rk-field-name' ).trigger( 'focus' );
        }

        function close() {
            $overlay.removeClass( 'is-open' );
            setTimeout( function () { $overlay.attr( 'hidden', '' ); }, 250 );
        }

        function isEditMode() {
            return isEdit;
        }

        function setSaving( saving ) {
            var $btn     = $( '#rk-save-child' );
            var $label   = $btn.find( '.rk-btn-label' );
            var $spinner = $btn.find( '.rk-btn-spinner' );

            /* <a> n'a pas d'attribut disabled : on utilise aria-disabled +
               une classe CSS (pointer-events:none) pour bloquer le clic. */
            if ( saving ) {
                $btn.addClass( 'is-disabled' ).attr( 'aria-disabled', 'true' );
                $label.hide();
                $spinner.removeAttr( 'hidden' ).show();
            } else {
                $btn.removeClass( 'is-disabled' ).removeAttr( 'aria-disabled' );
                $spinner.attr( 'hidden', '' ).hide();
                $label.show();
            }
        }

        function _resetForm() {
            $( '#rk-field-name' ).val( '' ).removeClass( 'rk-input-error' );
            $( '#rk-field-family' ).val( '' ).removeClass( 'rk-input-error' );
            $( '#rk-field-username' ).val( '' ).removeClass( 'rk-input-error' );
            $( '#rk-field-age' ).val( '' );
            $( '#rk-field-avatar' ).val( '' );
            _updateAvatarPreview( '' );
            if ( window.RKChildrenNotifications ) {
                window.RKChildrenNotifications.clearFormError();
                window.RKChildrenNotifications.clearFieldError( 'rk-field-name', 'rk-error-name' );
            }
            setSaving( false );
        }

        return { open: open, close: close, isEditMode: isEditMode, setSaving: setSaving };

    } )();

    /* ════════════════════════════════════════════════════════
     * Liaisons événements
     * ════════════════════════════════════════════════════════ */

    /* v11.0 — délégation + sélecteur multiple : le bouton d'en-tête
       (#rk-open-modal) ET la nouvelle carte "طفل جديد" en tête de
       grille (#rk-open-modal-card, ajoutée par le Renderer) ouvrent
       toutes deux le même modal en mode "add". */
    $( document ).on( 'click', '#rk-open-modal, #rk-open-modal-card', function ( e ) {
        e.preventDefault();
        window.RKChildrenModal.open( 'add' );
    } );

    $( document ).on( 'click', '#rk-modal-close, #rk-cancel-modal', function ( e ) {
        e.preventDefault();
        window.RKChildrenModal.close();
    } );

    /* <a role="button"> : Espace déclenche le clic comme un vrai bouton */
    $( document ).on( 'keydown', '.rk-modal-box a[role="button"]', function ( e ) {
        if ( e.key === ' ' || e.key === 'Spacebar' ) {
            e.preventDefault();
            $( this ).trigger( 'click' );
        }
    } );

    $overlay.on( 'click', function ( e ) {
        if ( ! $( e.target ).closest( '#rk-modal-box' ).length ) {
            window.RKChildrenModal.close();
        }
    } );

    $( document ).on( 'keydown', function ( e ) {
        if ( e.key === 'Escape' && $overlay.hasClass( 'is-open' ) ) {
            window.RKChildrenModal.close();
        }
    } );

    /* Ouvrir en mode édition — lit aussi data-avatar */
    $( document ).on( 'click', '.rk-edit-btn', function () {
        var $card = $( this ).closest( '.rk-child-card-final' );
        window.RKChildrenModal.open( 'edit', {
            id:     $card.data( 'id' ),
            name:   $card.data( 'name' ),
            family: $card.data( 'family' ) || '',
            age:    $card.data( 'age' ),
            avatar: $card.data( 'avatar' ) || ''
        } );
    } );

} );
