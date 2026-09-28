/**
 * RK My Children — Slider المغامرات (/my-account/child-profile/)
 *
 * AJOUT (demande utilisateur) — 3 items/vue desktop, 1/vue mobile, avec
 * flèches de pagination. Déplace .rk-cprofile__adv-track via transform
 * translateX, largeur des items déjà fixée en CSS (voir
 * .rk-cprofile__adv-track .rk-cprofile__adv, assets/css/child-card.css).
 *
 * @package RK_My_Children
 */
( function ( $ ) {
    'use strict';

    function itemsPerView() {
        return window.innerWidth <= 768 ? 1 : 3;
    }

    $( function () {
        $( '[data-rk-slider]' ).each( function () {
            var $slider   = $( this );
            var $viewport = $slider.find( '.rk-cprofile__adv-viewport' );
            var $track    = $slider.find( '.rk-cprofile__adv-track' );
            var $items    = $track.children( '.rk-cprofile__adv' );
            var $prevBtn  = $slider.find( '[data-dir="prev"]' );
            var $nextBtn  = $slider.find( '[data-dir="next"]' );

            var total = $items.length;
            var index = 0;

            function maxIndex() {
                return Math.max( 0, total - itemsPerView() );
            }

            function update() {
                var max = maxIndex();
                if ( index > max ) index = max;
                if ( index < 0 ) index = 0;

                // AJUSTEMENT RTL — dir="rtl" sur le document (voir
                // child-profile.php, <div class="rk-cprofile" dir="rtl">) :
                // "next" doit visuellement déplacer le contenu vers la
                // GAUCHE de l'écran, donc translateX POSITIF en RTL
                // (l'axe X reste géométrique, pas inversé par le
                // navigateur pour cette propriété).
                var pct = index * ( 100 / itemsPerView() );
                $track.css( 'transform', 'translateX(' + pct + '%)' );

                $prevBtn.prop( 'disabled', index <= 0 );
                $nextBtn.prop( 'disabled', index >= max );

                if ( total <= itemsPerView() ) {
                    $prevBtn.add( $nextBtn ).hide();
                } else {
                    $prevBtn.add( $nextBtn ).show();
                }
            }

            $prevBtn.on( 'click', function () {
                index -= itemsPerView();
                update();
            } );

            $nextBtn.on( 'click', function () {
                index += itemsPerView();
                update();
            } );

            var resizeTimer = null;
            $( window ).on( 'resize', function () {
                clearTimeout( resizeTimer );
                resizeTimer = setTimeout( update, 150 );
            } );

            update();
        } );
    } );

} )( jQuery );
