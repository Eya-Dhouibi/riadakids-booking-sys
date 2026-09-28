<?php
/**
 * RiadaKids\Booking\BookingNotifier — v25.1 AUDIT FIX
 *
 *
 * @package RiadaKids\Booking
 */

namespace RiadaKids\Booking;

if ( ! defined( 'ABSPATH' ) ) exit;

class BookingNotifier {

    public function notify_booking_created( int $booking_id, BookingContext $ctx ): void {
        $this->send_user_email( $booking_id, $ctx, 'created' );
        $this->send_admin_email( $booking_id, $ctx, 'created' );
    }

    public function notify_booking_confirmed( int $booking_id, BookingContext $ctx ): void {
        $this->send_user_email( $booking_id, $ctx, 'confirmed' );
        $this->send_admin_email( $booking_id, $ctx, 'confirmed' );
    }

    /**
     * BUG-6 FIX : Notification de reprogrammation.
     * Appelée par BookingService::reschedule_from_ssa() après mise à jour
     * de _rk_appointment_datetime et _rk_status = 'rescheduled'.
     */
    public function notify_booking_rescheduled( int $booking_id, BookingContext $ctx ): void {
        $this->send_user_email( $booking_id, $ctx, 'rescheduled' );
        $this->send_admin_email( $booking_id, $ctx, 'rescheduled' );
    }

    /**
     * BUG-6 FIX : Notification d'annulation.
     * Appelée par BookingService::cancel_from_ssa() après mise à jour du statut.
     */
    public function notify_booking_cancelled( int $booking_id, BookingContext $ctx ): void {
        $this->send_user_email( $booking_id, $ctx, 'cancelled' );
        $this->send_admin_email( $booking_id, $ctx, 'cancelled' );
    }

    // ── Email utilisateur ─────────────────────────────────────────────────

    private function send_user_email( int $booking_id, BookingContext $ctx, string $type ): void {
        $user = get_user_by( 'id', $ctx->user_id );
        if ( ! $user ) return;

        $subjects = [
            'created'     => __( 'تأكيد استلام حجزك — Riadakids', 'riadakids' ),
            'confirmed'   => __( 'تم تأكيد حجزك — Riadakids', 'riadakids' ),
            'rescheduled' => __( 'تم تغيير موعد حجزك — Riadakids', 'riadakids' ),
            'cancelled'   => __( 'تم إلغاء حجزك — Riadakids', 'riadakids' ),
        ];

        $subject = $subjects[ $type ] ?? sprintf( __( 'تحديث على حجزك #%d', 'riadakids' ), $booking_id );
        $body    = $this->build_user_email_body( $booking_id, $ctx, $type );

        wp_mail( $user->user_email, $subject, $body, $this->html_headers() );
    }

    // ── Email admin ───────────────────────────────────────────────────────

    private function send_admin_email( int $booking_id, BookingContext $ctx, string $type ): void {
        $subjects = [
            'created'     => sprintf( '[RK] Nouvelle réservation #%d', $booking_id ),
            'confirmed'   => sprintf( '[RK] Réservation confirmée #%d', $booking_id ),
            'rescheduled' => sprintf( '[RK] Réservation reprogrammée #%d', $booking_id ),
            'cancelled'   => sprintf( '[RK] Réservation annulée #%d', $booking_id ),
        ];

        $subject = $subjects[ $type ] ?? sprintf( '[RK] Mise à jour réservation #%d', $booking_id );
        $body    = $this->build_admin_email_body( $booking_id, $ctx, $type );

        wp_mail( get_option( 'admin_email' ), $subject, $body, $this->html_headers() );
    }

    // ── Corps des emails ──────────────────────────────────────────────────

    private function build_user_email_body( int $booking_id, BookingContext $ctx, string $type ): string {
        $user         = get_user_by( 'id', $ctx->user_id );
        $display_name = $user ? $user->display_name : '';
        $datetime = $ctx->appointment_datetime
            ? \RiadaKids\Core\TimeZone::format(
                  $ctx->appointment_datetime, 'l j F Y، H:i',
                  (int) $ctx->appointment_id, (int) $ctx->user_id
              ) . ' (' . \RiadaKids\Core\TimeZone::offset_label( (int) $ctx->appointment_id, (int) $ctx->user_id ) . ')'
            : '—';

        $children_names = $this->get_children_names( $ctx->child_ids );
        $session_label  = $ctx->session_name ?: $ctx->event_name;

        $status_lines = [
            'created'     => 'تم استلام طلب حجزك بنجاح. سيتم تأكيده قريباً.',
            'confirmed'   => 'تم تأكيد حجزك بنجاح. نتطلع لرؤيتك!',
            'rescheduled' => 'تم تغيير موعد حجزك. يُرجى مراجعة الموعد الجديد أدناه.',
            'cancelled'   => 'نأسف لإعلامك أنه تم إلغاء حجزك.',
        ];

        $status_text = $status_lines[ $type ] ?? '';

        return sprintf(
            '<div dir="rtl" style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;">
                <h2 style="color:#0065BF;">مرحباً %s</h2>
                <p>%s</p>
                <table style="width:100%%;border-collapse:collapse;margin-top:16px;">
                    <tr><td style="padding:8px;border:1px solid #ddd;background:#f9f9f9;font-weight:bold;">رقم الحجز</td><td style="padding:8px;border:1px solid #ddd;">#%d</td></tr>
                    <tr><td style="padding:8px;border:1px solid #ddd;background:#f9f9f9;font-weight:bold;">اللقاء</td><td style="padding:8px;border:1px solid #ddd;">%s</td></tr>
                    <tr><td style="padding:8px;border:1px solid #ddd;background:#f9f9f9;font-weight:bold;">الأطفال</td><td style="padding:8px;border:1px solid #ddd;">%s</td></tr>
                    <tr><td style="padding:8px;border:1px solid #ddd;background:#f9f9f9;font-weight:bold;">الموعد</td><td style="padding:8px;border:1px solid #ddd;">%s</td></tr>
                </table>
                <p style="margin-top:24px;color:#666;font-size:12px;">Riadakids — %s</p>
            </div>',
            esc_html( $display_name ),
            esc_html( $status_text ),
            $booking_id,
            esc_html( $session_label ),
            esc_html( implode( ', ', $children_names ) ),
            esc_html( $datetime ),
            get_bloginfo( 'name' )
        );
    }

    private function build_admin_email_body( int $booking_id, BookingContext $ctx, string $type ): string {
        $user     = get_user_by( 'id', $ctx->user_id );
        $email    = $user ? $user->user_email    : '—';
        $name     = $user ? $user->display_name  : "user#{$ctx->user_id}";
        $tz_site  = wp_timezone();
        $datetime = $ctx->appointment_datetime
            ? wp_date( 'Y-m-d H:i', ( new \DateTimeImmutable( $ctx->appointment_datetime, \RiadaKids\Core\TimeZone::storage_tz() ) )->getTimestamp(), $tz_site )
              . ' — client : ' . \RiadaKids\Core\TimeZone::format( $ctx->appointment_datetime, 'Y-m-d H:i', (int) $ctx->appointment_id, (int) $ctx->user_id )
            : '—';

        // BUG-5 : lien SSA canonique depuis appointment_id
        $appt_id  = $ctx->appointment_id;
        $ssa_link = $appt_id > 0
            ? admin_url( 'admin.php?page=simply-schedule-appointments#/ssa/appointment/' . $appt_id )
            : '—';

        $children_names = $this->get_children_names( $ctx->child_ids );

        return sprintf(
            '<div style="font-family:Arial,sans-serif;">
                <h3>[RK] Booking #%d — %s</h3>
                <table style="border-collapse:collapse;">
                    <tr><td style="padding:4px 12px;border:1px solid #ccc;">Type</td><td style="padding:4px 12px;border:1px solid #ccc;"><strong>%s</strong></td></tr>
                    <tr><td style="padding:4px 12px;border:1px solid #ccc;">Utilisateur</td><td style="padding:4px 12px;border:1px solid #ccc;">%s (%s)</td></tr>
                    <tr><td style="padding:4px 12px;border:1px solid #ccc;">Enfants</td><td style="padding:4px 12px;border:1px solid #ccc;">%s</td></tr>
                    <tr><td style="padding:4px 12px;border:1px solid #ccc;">Séance</td><td style="padding:4px 12px;border:1px solid #ccc;">%s</td></tr>
                    <tr><td style="padding:4px 12px;border:1px solid #ccc;">Date</td><td style="padding:4px 12px;border:1px solid #ccc;">%s</td></tr>
                    <tr><td style="padding:4px 12px;border:1px solid #ccc;">SSA</td><td style="padding:4px 12px;border:1px solid #ccc;"><a href="%s">SSA #%d</a></td></tr>
                    <tr><td style="padding:4px 12px;border:1px solid #ccc;">Crédits</td><td style="padding:4px 12px;border:1px solid #ccc;">%d</td></tr>
                </table>
            </div>',
            $booking_id,
            strtoupper( $type ),
            esc_html( $type ),
            esc_html( $name ),
            esc_html( $email ),
            esc_html( implode( ', ', $children_names ) ),
            esc_html( $ctx->session_name ?: $ctx->event_name ),
            esc_html( $datetime ),
            esc_url( $ssa_link ),
            $appt_id,
            $ctx->credits_used
        );
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function get_children_names( array $child_ids ): array {
        $names = [];
        foreach ( $child_ids as $id ) {
            $name = get_post_meta( (int) $id, 'child_name', true );
            if ( ! $name ) {
                $name = "Enfant #{$id}";
            }
            $names[] = $name;
        }
        return $names;
    }

    private function html_headers(): array {
        return [
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . get_bloginfo( 'name' ) . ' <' . get_option( 'admin_email' ) . '>',
        ];
    }
}