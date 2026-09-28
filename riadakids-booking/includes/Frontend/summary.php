<?php
/**
 * Summary — Récapitulatif complet d'une réservation.
 *
 *
 * @package RiadaKids\Frontend
 */

use RiadaKids\Booking\BookingContext;
use RiadaKids\Booking\BookingRepository;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Résolution du contexte
if ( ! isset( $ctx ) && isset( $booking_id ) ) {
    $repo = new BookingRepository();
    $ctx  = $repo->load_context( (int) $booking_id );
}

if ( ! $ctx instanceof BookingContext ) {
    echo '<p>' . esc_html__( 'Réservation introuvable.', 'riadakids' ) . '</p>';
    return;
}

// Formatage date/heure
$datetime_fmt = '';
if ( $ctx->appointment_datetime ) {
    $datetime_fmt = wp_date(
        get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
        strtotime( $ctx->appointment_datetime )
    );
}

// Noms des enfants
$children_names = [];
foreach ( $ctx->child_ids as $child_id ) {
    $post = get_post( (int) $child_id );
    $children_names[] = $post ? $post->post_title : sprintf( __( 'Enfant #%d', 'riadakids' ), $child_id );
}

// Programme / aventure / session
$program_title   = get_the_title( $ctx->program_id )   ?: '—';
$adventure_title = get_the_title( $ctx->adventure_id ) ?: '—';
$session_title   = get_the_title( $ctx->session_id )   ?: '—';
?>
<div class="rk-booking-summary">
    <h3><?php esc_html_e( 'Récapitulatif de réservation', 'riadakids' ); ?></h3>

    <dl class="rk-summary-list">

        <!-- Programme -->
        <div class="rk-summary-row">
            <dt><?php esc_html_e( 'Programme', 'riadakids' ); ?></dt>
            <dd><?php echo esc_html( $program_title ); ?></dd>
        </div>

        <!-- Aventure -->
        <div class="rk-summary-row">
            <dt><?php esc_html_e( 'Aventure', 'riadakids' ); ?></dt>
            <dd><?php echo esc_html( $adventure_title ); ?></dd>
        </div>

        <!-- Niveau / Session -->
        <div class="rk-summary-row">
            <dt><?php esc_html_e( 'Niveau / Session', 'riadakids' ); ?></dt>
            <dd><?php echo esc_html( $session_title ); ?></dd>
        </div>

        <!-- Enfant(s) -->
        <div class="rk-summary-row">
            <dt><?php esc_html_e( 'Enfant(s)', 'riadakids' ); ?></dt>
            <dd>
                <?php if ( count( $children_names ) > 1 ) : ?>
                    <ul class="rk-children-list">
                        <?php foreach ( $children_names as $name ) : ?>
                            <li><?php echo esc_html( $name ); ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php else : ?>
                    <?php echo esc_html( $children_names[0] ?? '—' ); ?>
                <?php endif; ?>
            </dd>
        </div>

        <!-- ✅ Événement SSA — اللقاء -->
        <div class="rk-summary-row rk-summary-event">
            <dt><?php esc_html_e( 'اللقاء', 'riadakids' ); ?></dt>
            <dd>
                <strong class="rk-event-highlight">
                    <?php echo esc_html( $ctx->event_name ?: '—' ); ?>
                </strong>
            </dd>
        </div>

        <!-- Date & Heure -->
        <div class="rk-summary-row">
            <dt><?php esc_html_e( 'Date & Heure', 'riadakids' ); ?></dt>
            <dd>
                <?php echo $datetime_fmt
                    ? esc_html( $datetime_fmt )
                    : '<em>' . esc_html__( 'À confirmer', 'riadakids' ) . '</em>'; ?>
            </dd>
        </div>

        <!-- Crédits consommés -->
        <div class="rk-summary-row">
            <dt><?php esc_html_e( 'Crédits consommés', 'riadakids' ); ?></dt>
            <dd>
                <?php printf(
                    _n( '%d crédit', '%d crédits', $ctx->credits_used, 'riadakids' ),
                    $ctx->credits_used
                ); ?>
            </dd>
        </div>

    </dl>
</div>