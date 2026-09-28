<?php
/**
 * RK My Children helpers.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Récupère une valeur dans un array en gardant un fallback.
 *
 * @param array        $array   Array source.
 * @param string|int   $key     Clé à lire.
 * @param mixed        $default Valeur de repli.
 * @return mixed
 */
function rk_mc_array_get( array $array, $key, $default = null ) {
    return array_key_exists( $key, $array ) ? $array[ $key ] : $default;
}
