<?php
/**
 * RiadaKids\Frontend\Shortcodes
 *
 * Shortcodes frontend du plugin Riadakids.
 */

namespace RiadaKids\Frontend;

use RiadaKids\Credits\CreditRepository;

if ( ! defined( 'ABSPATH' ) ) exit;

class Shortcodes {

    public function __construct() {
        add_shortcode( 'rk_credits',        [ $this, 'shortcode_credits' ] );
        add_shortcode( 'rk_booking_button', [ $this, 'shortcode_booking_button' ] );
    }

    /**
     * [rk_credits] — Affiche le solde actuel de l'utilisateur
     */
    public function shortcode_credits( array $atts = [] ): string {
        if ( ! is_user_logged_in() ) {
            return '';
        }

        $user_id = get_current_user_id();
        $balance = (int) CreditRepository::get_balance( $user_id );

        $label = ( $balance === 1 ) ? 'لقاء' : 'لقاءات';

        return sprintf(
            '<span class="rk-shortcode-credits">رصيدك الحالي : <strong>%d</strong> %s</span>',
            $balance,
            esc_html( $label )
        );
    }

    /**
     * [rk_booking_button label="احجز الآن"] — Bouton vers l'endpoint de réservation
     */
    public function shortcode_booking_button( array $atts = [] ): string {
        $atts = shortcode_atts(
            [
                'label' => 'احجز لقاءك',
                'class' => 'rk-btn rk-btn-primary',
            ],
            $atts,
            'rk_booking_button'
        );

        if ( ! function_exists( 'wc_get_account_endpoint_url' ) ) {
            return '';
        }

        $url = wc_get_account_endpoint_url( 'book-session' );

        if ( empty( $url ) ) {
            return '';
        }

        return sprintf(
            '<a href="%s" class="%s">%s</a>',
            esc_url( $url ),
            esc_attr( $atts['class'] ),
            esc_html( $atts['label'] )
        );
    }

    /**
     * Rendu de l'étape Simply Schedule Appointments (SSA)
     * مأخوذة ومحدثة لتعمل بالشورتكود العربي مباشرة لضمان ظهور الـ Widget
     */
public static function render_ssa_step( bool $has_credits, int $credits ): void {
    if ( ! $has_credits ) {
        echo '<div class="rk-notice rk-notice-danger">ليس لديك رصيد كافٍ لحجز لقاء جديد. يرجى شحن حسابك.</div>';
        return;
    }
    
    echo '<div class="rk-ssa-container">';
    echo '<h3>الخطوة 5 — اختيار الموعد</h3>';
    
    $ssa_nonce = wp_create_nonce( 'wp_rest' ); 

    $iframe_url = add_query_arg( [
        'integration'      => '',
        'type'             => '',
        'label'            => '',
        'types'            => '',
        'edit'             => '',
        'view'             => '',
        'payment_provider' => '',
        'ssa_locale'       => 'ar', 
        'ssa_is_rtl'       => '1',  
        'booking_url'      => rawurlencode( wc_get_account_endpoint_url( 'book-session' ) ),
        '_wpnonce'         => $ssa_nonce,
    ], 'https://riadakids.com/wp-json/ssa/v1/embed-inner' );

    $iframe_url .= '#/';

    echo '<iframe src="' . esc_url( $iframe_url ) . '" height="600px" width="100%" name="ssa_booking" loading="eager" frameborder="0" data-skip-lazy="1" class="ssa_booking_iframe skip-lazy" title="حجز وقت" scrolling="no" style="overflow: hidden; min-height: 600px;"></iframe>';
    
    echo '</div>';
}
}