<?php
declare( strict_types=1 );
/**
 * Template minimal coach dashboard — sans header ni footer du thème.
 *
 * On inclut dashboard.php de Tutor LMS avec $is_shortcode = true pour
 * court-circuiter tutor_custom_header() / tutor_custom_footer() tout en
 * gardant toutes les actions du dashboard (before/wrap, dispatch, etc.).
 *
 * @package RK_Coach_Hub
 */
defined( 'ABSPATH' ) || exit;

// ── Empêcher tout cache de cette page ──────────────────────────────────────
// Couvre service worker (WP Rocket/Workbox) ET cache de page serveur
// (LiteSpeed/CDN) — sans ça, une page "offline" ou une version figée d'un
// crash précédent peut resservir même après correction du bug.
rkp_no_cache_page( 'rk_coach_no_chrome' );

// ── Filet de sécurité : erreurs fatales non-attrapables ──────────────────
// Si une erreur fatale (E_ERROR, E_PARSE…) survient n'importe où dans
// cette page (y compris wp_head / wp_footer), on garantit qu'une réponse
// HTML non-vide est envoyée au lieu d'une connexion coupée silencieuse
// qui déclencherait la page offline du service worker.
$_rk_ob_init = ob_get_level();
register_shutdown_function( function () use ( $_rk_ob_init ) {
    $err = error_get_last();
    if ( ! $err ) return;
    if ( ! in_array( $err['type'], [ E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR ], true ) ) return;
    error_log( '[RK Coach] shutdown fatal: ' . $err['message'] . ' at ' . $err['file'] . ':' . $err['line'] );
    // Vider les buffers ouverts depuis le début du template
    while ( ob_get_level() > $_rk_ob_init ) { ob_end_clean(); }
    if ( ob_get_level() > 0 ) { ob_end_clean(); }
    // Émettre une réponse HTML minimale pour éviter la page offline
    echo '<!DOCTYPE html><html dir="rtl"><head><meta charset="UTF-8">'
       . '<title>خطأ</title></head>'
       . '<body style="font-family:sans-serif;text-align:center;padding:40px;">'
       . '<p style="color:#c00;">حدث خطأ. الرجاء المحاولة مرة أخرى.</p>'
       . '</body></html>';
} );

// $is_shortcode = true empêche tutor_custom_header() / tutor_custom_footer()
// dans dashboard.php (lignes 12-14 et 287-289).
$is_shortcode = true;
$_tutor_tpl   = function_exists( 'tutor_get_template' ) ? tutor_get_template( 'dashboard' ) : '';
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php wp_head(); ?>
</head>
<body <?php body_class( 'rk-coach-chromeless' ); ?>>
<?php
if ( function_exists( 'wp_body_open' ) ) {
    wp_body_open();
}
if ( $_tutor_tpl && file_exists( $_tutor_tpl ) ) {
    $ob_level_before = ob_get_level();
    try {
        include $_tutor_tpl; // $is_shortcode visible dans le scope inclus
    } catch ( \Throwable $e ) {
        error_log( '[RK Coach] dashboard template error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() );
        // Vider les buffers ouverts par le template (ex: sidebar_buffer_start → ob_start)
        // sans toucher aux buffers WordPress préexistants.
        while ( ob_get_level() > $ob_level_before ) { ob_end_clean(); }
        echo '<div style="padding:40px;text-align:center;direction:rtl;font-family:sans-serif;">';
        echo '<p style="color:#c00;">حدث خطأ أثناء تحميل لوحة التحكم. الرجاء المحاولة مرة أخرى.</p>';
        echo '</div>';
    }
}
wp_footer();
?>
</body>
</html>
