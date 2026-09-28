/**
 * child-avatar-upload.js  (v2.9)
 *
 * صورة الطفل — upload vers la bibliothèque WordPress.
 * Sélection fichier → validation client (type/2MB) → POST multipart
 * /rk-mc/v1/children/avatar → l'URL renvoyée remplit le champ caché
 * #rk-field-avatar (le flux create/update existant l'envoie tel quel)
 * et alimente la prévisualisation live du modal.
 */
( function () {
    'use strict';

    var cfg       = window.rkMC || {};
    var restBase  = ( cfg.restUrl || '' ).replace( /\/children\/?$/, '' );
    var restNonce = cfg.restNonce || '';

    var fileInput, hiddenUrl, btn, state, errBox, previewImg, initials;

    function $( id ) { return document.getElementById( id ); }

    function setState( txt, isError ) {
        if ( state ) {
            state.textContent = txt || '';
            state.style.color = isError ? '#dc2626' : '#059669';
        }
        if ( errBox ) {
            errBox.hidden = ! isError || ! txt;
            if ( isError && txt ) { errBox.textContent = txt; }
        }
    }

    function setPreview( url ) {
        if ( previewImg ) { previewImg.src = url; previewImg.hidden = false; }
        if ( initials )   { initials.hidden = true; }
    }

    function upload( file ) {
        // Validation client — le serveur revalide de toute façon.
        var okTypes = [ 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ];
        if ( okTypes.indexOf( file.type ) === -1 ) {
            setState( 'الملف يجب أن يكون صورة (JPG, PNG, GIF, WebP).', true );
            return;
        }
        if ( file.size > 2 * 1024 * 1024 ) {
            setState( 'حجم الصورة يجب ألا يتجاوز 2 ميغابايت.', true );
            return;
        }

        setState( '⏳ جارٍ رفع الصورة…', false );
        btn && ( btn.disabled = true );

        var fd = new FormData();
        fd.append( 'avatar_file', file );
        var nameField = $( 'rk-field-name' );
        if ( nameField && nameField.value ) { fd.append( 'child_name', nameField.value ); }

        fetch( restBase + '/children/avatar', {
            method:      'POST',
            headers:     { 'X-WP-Nonce': restNonce },
            credentials: 'same-origin',
            body:        fd
        } )
        .then( function ( r ) { return r.json().then( function ( j ) { return { ok: r.ok, j: j }; } ); } )
        .then( function ( res ) {
            btn && ( btn.disabled = false );
            if ( ! res.ok || ! res.j || ! res.j.success || ! res.j.url ) {
                setState( ( res.j && res.j.message ) || 'تعذر رفع الصورة، حاول مجدداً.', true );
                return;
            }
            hiddenUrl.value = res.j.url;
            setPreview( res.j.url );
            setState( '✅ تم رفع الصورة', false );
        } )
        .catch( function () {
            btn && ( btn.disabled = false );
            setState( 'تعذر الاتصال بالخادم.', true );
        } );
    }

    function init() {
        fileInput  = $( 'rk-field-avatar-file' );
        hiddenUrl  = $( 'rk-field-avatar' );
        btn        = $( 'rk-avatar-upload-btn' );
        state      = $( 'rk-avatar-upload-state' );
        errBox     = $( 'rk-error-avatar' );
        previewImg = $( 'rk-avatar-img' );
        initials   = $( 'rk-avatar-initials' );
        if ( ! fileInput || ! hiddenUrl || ! btn ) { return; }

        btn.addEventListener( 'click', function ( e ) {
            e.preventDefault();   // <a role="button">
            fileInput.click();
        } );
        fileInput.addEventListener( 'change', function () {
            if ( fileInput.files && fileInput.files[0] ) { upload( fileInput.files[0] ); }
        } );

        // Édition : si l'enfant a déjà une photo (champ pré-rempli par child-edit.js),
        // refléter dans la prévisualisation.
        if ( hiddenUrl.value ) { setPreview( hiddenUrl.value ); }
    }

    if ( document.readyState === 'loading' ) {
        document.addEventListener( 'DOMContentLoaded', init );
    } else {
        init();
    }
} )();
