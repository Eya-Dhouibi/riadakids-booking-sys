<?php
declare( strict_types=1 );
/**
 * RiadaKids — Tutor Dashboard: الأرصدة (Sprint 7)
 *
 * Affiche le solde actuel des crédits, l'historique des transactions,
 * et un bouton de rechargement.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

// Defense-in-depth: rk_child must never access financial data
if ( class_exists( 'RK_MC_Child_Restrictions' ) && RK_MC_Child_Restrictions::is_child_user() ) {
    wp_safe_redirect( home_url( RK_TUTOR_DASHBOARD_URL ) );
    exit;
}

$parent_id = get_current_user_id();

global $wpdb;

// Solde actuel
$credits_table = $wpdb->prefix . 'rk_user_credits';
$has_credits_t = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $credits_table ) ) === $credits_table;

$balance = 0;
if ( $has_credits_t ) {
    $balance = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT balance FROM {$credits_table} WHERE user_id = %d LIMIT 1",
        $parent_id
    ) );
} else {
    $balance = (int) get_user_meta( $parent_id, 'rk_session_credits', true );
}

// Historique depuis wp_rk_user_credits_log (table optionnelle)
$history = [];
$log_table = $credits_table . '_log';
$has_log_t = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $log_table ) ) === $log_table;
if ( $has_log_t ) {
    $history = $wpdb->get_results( $wpdb->prepare(
        "SELECT amount, type, note, created_at
           FROM {$log_table}
          WHERE user_id = %d
          ORDER BY created_at DESC LIMIT 30",
        $parent_id
    ) ) ?: [];
}

// Fallback : sessions confirmées depuis wp_rk_bookings
if ( empty( $history ) ) {
    $bk_table = $wpdb->prefix . 'rk_bookings';
    $has_bk_t = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $bk_table ) ) === $bk_table;
    if ( $has_bk_t ) {
        // Utilise status = 'confirmed' (colonne toujours présente)
        // N'utilise pas attendance (colonne absente dans certaines installations)
        $history = $wpdb->get_results( $wpdb->prepare(
            "SELECT
                'session' AS type,
                session_name AS note,
                appointment AS created_at,
                -1 AS amount
             FROM {$bk_table}
             WHERE user_id = %d
               AND status = 'confirmed'
               AND appointment <= NOW()
             ORDER BY appointment DESC LIMIT 20",
            $parent_id
        ) ) ?: [];
    }
}

$book_url = get_permalink( get_option( 'rk_booking_page_id', 0 ) ) ?: '#';

// Vérifier si l'URL WooCommerce shop produit crédits existe
$credits_product_url = get_option( 'rk_credits_product_url', '' );
?>
<div class="rk-td-page-credits" dir="rtl" style="padding-bottom:40px;">

    <h2 class="rk-td-page-title" style="display:flex;align-items:center;gap:10px;font-size:1.2rem;font-weight:800;color:var(--e-global-color-secondary,#1B4F8C);margin-bottom:24px;">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 8v4l3 3"/></svg>
        <?php esc_html_e( 'أرصدة لقاءات', 'rk-my-children' ); ?>
    </h2>

    <!-- Solde principal -->
    <div style="background:linear-gradient(135deg,var(--e-global-color-secondary,#1B4F8C) 0%,#2563eb 100%);
                border-radius:20px;padding:32px;margin-bottom:24px;text-align:center;color:#fff;">
        <p style="font-size:4rem;font-weight:900;margin:0;line-height:1;">
            <?php echo $balance; ?>
        </p>
        <p style="font-size:1rem;opacity:.85;margin:8px 0 20px;"><?php esc_html_e( 'جلسات متبقية', 'rk-my-children' ); ?></p>

        <?php if ( $balance <= 2 ) : ?>
        <div style="background:rgba(255,255,255,.15);border-radius:12px;padding:10px 16px;margin-bottom:16px;font-size:.88rem;">
            ⚠️ <?php esc_html_e( 'رصيدك منخفض! احجز مسبقاً لضمان استمرارية التدريب.', 'rk-my-children' ); ?>
        </div>
        <?php endif; ?>

        <div style="display:flex;gap:12px;justify-content:center;flex-wrap:wrap;">
            <a href="<?php echo esc_url( $book_url ); ?>"
               style="background:#fff;color:var(--e-global-color-primary,#E8500A);padding:11px 24px;
                      border-radius:10px;font-weight:700;font-size:.9rem;text-decoration:none;">
                📅 <?php esc_html_e( 'احجز جلسة', 'rk-my-children' ); ?>
            </a>
            <?php if ( $credits_product_url ) : ?>
            <a href="<?php echo esc_url( $credits_product_url ); ?>"
               style="background:rgba(255,255,255,.2);color:#fff;padding:11px 24px;
                      border:1.5px solid rgba(255,255,255,.4);border-radius:10px;font-weight:700;font-size:.9rem;text-decoration:none;">
                ➕ <?php esc_html_e( 'اشتر أرصدة', 'rk-my-children' ); ?>
            </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Historique -->
    <div style="background:#fff;border-radius:16px;padding:24px;box-shadow:0 2px 8px rgba(0,0,0,.06);">
        <h3 style="font-size:.95rem;font-weight:800;color:#374151;margin:0 0 16px;"><?php esc_html_e( 'سجل لقاءات', 'rk-my-children' ); ?></h3>

        <?php if ( empty( $history ) ) : ?>
        <p style="color:#94a3b8;font-size:.87rem;"><?php esc_html_e( 'لا يوجد سجل متاح بعد.', 'rk-my-children' ); ?></p>
        <?php else : ?>
        <div style="overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;font-size:.85rem;">
                <thead>
                    <tr style="border-bottom:2px solid #f1f5f9;">
                        <th style="text-align:right;padding:8px 12px;color:#64748b;font-weight:600;"><?php esc_html_e( 'التاريخ', 'rk-my-children' ); ?></th>
                        <th style="text-align:right;padding:8px 12px;color:#64748b;font-weight:600;"><?php esc_html_e( 'الجلسة / الحدث', 'rk-my-children' ); ?></th>
                        <th style="text-align:center;padding:8px 12px;color:#64748b;font-weight:600;"><?php esc_html_e( 'التغيير', 'rk-my-children' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $history as $h ) :
                        $amount  = (int) ( $h->amount ?? -1 );
                        $is_pos  = $amount > 0;
                        $color   = $is_pos ? '#166534' : '#b91c1c';
                        $bg      = $is_pos ? '#dcfce7' : '#fee2e2';
                        $sign    = $is_pos ? '+' : '';
                        $ts      = strtotime( $h->created_at ?? '' );
                    ?>
                    <tr style="border-bottom:1px solid #f8fafc;">
                        <td style="padding:10px 12px;color:#64748b;">
                            <?php echo $ts ? esc_html( date_i18n( 'j/m/Y H:i', $ts ) ) : '—'; ?>
                        </td>
                        <td style="padding:10px 12px;color:#374151;font-weight:500;">
                            <?php echo esc_html( $h->note ?? '' ); ?>
                        </td>
                        <td style="padding:10px 12px;text-align:center;">
                            <span style="padding:3px 12px;border-radius:20px;font-weight:700;font-size:.8rem;color:<?php echo esc_attr( $color ); ?>;background:<?php echo esc_attr( $bg ); ?>;">
                                <?php echo esc_html( $sign . $amount ); ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

</div>
