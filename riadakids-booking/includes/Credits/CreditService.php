<?php
/**
 * RiadaKids\Credits\CreditService — v25 PRODUCTION
 *
 * ══════════════════════════════════════════════════════════════
 * FIXES APPLIQUÉS
 * ══════════════════════════════════════════════════════════════
 *
 * FIX09 — Guard guest checkout : si user_id=0 sur une commande,
 *          on log une alerte admin et on ignore silencieusement
 *          (évite les crédits fantômes non attribuables).
 *
 * FIX15 — Hooks de remboursement/annulation commande WooCommerce :
 *          woocommerce_order_refunded     → restitution crédits + annulation points
 *          woocommerce_order_status_cancelled → idem
 *
 *          Règle : si la commande avait des crédits alloués ET n'a pas
 *          encore été remboursée (_rk_credits_refunded absent), on :
 *            1. Retire les crédits rendus du solde
 *            2. Retire les points reward correspondants
 *            3. Marque la commande (_rk_credits_refunded=1)
 *            4. Log le mouvement
 *
 * FIX14 — try/catch global sur on_order_complete / on_order_refunded.
 */

namespace RiadaKids\Credits;

use RiadaKids\Rewards\RewardService;

if ( ! defined( 'ABSPATH' ) ) exit;

class CreditService {

    const POINTS_PER_SESSION = 10;

    public function __construct() {

        // Achat pack
        add_action( 'woocommerce_order_status_processing', [ $this, 'on_order_complete' ] );
        add_action( 'woocommerce_order_status_completed',  [ $this, 'on_order_complete' ] );

        // FIX15 — Remboursement / annulation
        add_action( 'woocommerce_order_refunded',          [ $this, 'on_order_refunded' ], 10, 2 );
        add_action( 'woocommerce_order_status_cancelled',  [ $this, 'on_order_cancelled' ] );

        // Champ produit
        add_action( 'woocommerce_product_options_general_product_data', [ $this, 'render_product_field' ] );
        add_action( 'woocommerce_process_product_meta',                 [ $this, 'save_product_field' ] );
    }

    /* ── Achat ─────────────────────────────────────────────────── */

    public function on_order_complete( int $order_id ): void {
        try {
            $order = wc_get_order( $order_id );
            if ( ! $order ) return;

            $user_id = (int) $order->get_user_id();

            // FIX09 — Guard guest checkout
            if ( ! $user_id ) {
                \rk_log( 'CREDIT', "commande #{$order_id} sans user_id — guest checkout? crédits non alloués (FIX09)" );
                $this->notify_admin_guest_order( $order_id );
                return;
            }

            // Guard anti-doublon
            if ( $order->get_meta( '_rk_credits_processed' ) ) return;

            $total_credits = $this->count_order_credits( $order );
            if ( $total_credits <= 0 ) {
                \rk_log( 'CREDIT', "commande #{$order_id} — aucun _rk_session_credits sur les produits" );
                return;
            }

            // Migrer usermeta → table si nécessaire (FIX02)
            CreditRepository::migrate_from_meta( $user_id );

            // Créditer
            $new_balance = CreditRepository::increase( $user_id, $total_credits );
            CreditLogger::log( $user_id, 'purchase', $total_credits, $new_balance,
                "شراء باقة — طلب #{$order_id} ({$total_credits} حصص)" );

            // Points reward
            $pts = $total_credits * self::POINTS_PER_SESSION;
            if ( $pts > 0 ) {
                RewardService::add_points( $user_id, $pts, 'pack_purchase',
                    "شراء {$total_credits} حصص → {$pts} نقطة (طلب #{$order_id})" );
            }

            // Stocker nb crédits alloués pour remboursement futur
            $order->update_meta_data( '_rk_credits_processed',   '1' );
            $order->update_meta_data( '_rk_credits_allocated',   $total_credits );
            $order->update_meta_data( '_rk_points_allocated',    $pts );
            $order->update_meta_data( '_rk_credits_refunded',    '0' );
            $order->save();

            \rk_log( 'CREDIT', "+{$total_credits} crédits user#{$user_id} | solde: {$new_balance} | commande #{$order_id}" );
            do_action( 'rk_credits_purchased', $user_id, $order_id, $total_credits );

        } catch ( \Throwable $e ) {
            \rk_log( 'CREDIT', "on_order_complete exception #{$order_id}: " . $e->getMessage() );
        }
    }

    /* ── FIX15 — Remboursement partiel ────────────────────────── */

    /**
     * FIX15 — woocommerce_order_refunded( $order_id, $refund_id )
     * Appelé lors d'un remboursement partiel ou total.
     */
    public function on_order_refunded( int $order_id, int $refund_id ): void {
        try {
            $this->process_order_reversal( $order_id, 'refund' );
        } catch ( \Throwable $e ) {
            \rk_log( 'CREDIT', "on_order_refunded exception #{$order_id}: " . $e->getMessage() );
        }
    }

    /**
     * FIX15 — woocommerce_order_status_cancelled
     * Appelé lors d'une annulation commande.
     */
    public function on_order_cancelled( int $order_id ): void {
        try {
            $this->process_order_reversal( $order_id, 'cancellation' );
        } catch ( \Throwable $e ) {
            \rk_log( 'CREDIT', "on_order_cancelled exception #{$order_id}: " . $e->getMessage() );
        }
    }

    /**
     * FIX15 — Logique centrale de réversion :
     *   1. Vérifie que la commande avait des crédits alloués
     *   2. Vérifie qu'elle n'a pas déjà été remboursée
     *   3. Retire les crédits + points du solde utilisateur
     *   4. Log tout
     */
    private function process_order_reversal( int $order_id, string $reason ): void {
        $order = wc_get_order( $order_id );
        if ( ! $order ) return;

        $user_id = (int) $order->get_user_id();
        if ( ! $user_id ) return;

        // Guard : déjà remboursé
        if ( $order->get_meta( '_rk_credits_refunded' ) === '1' ) return;

        // Guard : pas encore traité → rien à rembourser
        if ( ! $order->get_meta( '_rk_credits_processed' ) ) return;

        $credits_to_remove = (int) $order->get_meta( '_rk_credits_allocated' );
        $points_to_remove  = (int) $order->get_meta( '_rk_points_allocated' );

        if ( $credits_to_remove <= 0 ) return;

        // Retirer les crédits — pas en dessous de 0
        $current  = CreditRepository::get_balance( $user_id );
        $deducted = min( $credits_to_remove, $current );
        $new_bal  = CreditRepository::decrease( $user_id, $deducted );

        CreditLogger::log(
            $user_id,
            $reason . '_reversal',
            -$deducted,
            $new_bal,
            "إلغاء/رد طلب #{$order_id} — خصم {$deducted} حصص"
        );

        // FIX15 — Retirer les points reward
        if ( $points_to_remove > 0 ) {
            $curr_pts     = RewardService::get_points( $user_id );
            $pts_deducted = min( $points_to_remove, $curr_pts );
            RewardService::set_points( $user_id, $curr_pts - $pts_deducted );
            \RiadaKids\Database\DB::add_points_log(
                $user_id,
                -$pts_deducted,
                $reason . '_reversal',
                "إلغاء طلب #{$order_id} — خصم {$pts_deducted} نقطة"
            );
            \rk_log( 'REWARDS', "FIX15 -{$pts_deducted} pts user#{$user_id} (order #{$order_id} {$reason})" );
        }

        // Marquer comme remboursé
        $order->update_meta_data( '_rk_credits_refunded', '1' );
        $order->save();

        \rk_log( 'CREDIT', "FIX15 -{$deducted} crédits user#{$user_id} ({$reason} order #{$order_id}) | nouveau solde: {$new_bal}" );
        do_action( 'rk_credits_reversed', $user_id, $order_id, $deducted, $reason );
    }

    /* ── Helpers ───────────────────────────────────────────────── */

    private function count_order_credits( \WC_Order $order ): int {
        $total = 0;
        foreach ( $order->get_items() as $item ) {
            $product = $item->get_product();
            if ( ! $product ) continue;
            $credits = (int) $product->get_meta( '_rk_session_credits' );
            if ( $credits <= 0 ) continue;
            $total += $credits * max( 1, (int) $item->get_quantity() );
        }
        return $total;
    }

    /**
     * FIX09 — Alerte admin si guest checkout détecté.
     */
    private function notify_admin_guest_order( int $order_id ): void {
        $key = '_rk_guest_alert_sent_' . $order_id;
        if ( get_option( $key ) ) return;

        $admin_email = get_option( 'admin_email' );
        $site        = get_bloginfo( 'name' );

        wp_mail(
            $admin_email,
            "[{$site}] ⚠️ Commande guest sans attribution crédits #{$order_id}",
            "La commande #{$order_id} n'a pas de user_id (guest checkout?).\n"
            . "Les crédits Riadakids n'ont PAS été alloués.\n"
            . "Vérifiez : " . admin_url( "post.php?post={$order_id}&action=edit" )
        );

        update_option( $key, '1', false );
    }

    /* ── Champ produit WC ──────────────────────────────────────── */

    public function render_product_field(): void {
        woocommerce_wp_text_input( [
            'id'          => '_rk_session_credits',
            'label'       => 'عدد الحصص (Riadakids)',
            'desc_tip'    => true,
            'description' => 'عدد الحصص الممنوحة عند شراء هذا المنتج. مثال: 1 أو 3 أو 12',
            'type'        => 'number',
            'custom_attributes' => [ 'step' => '1', 'min' => '0' ],
        ] );
    }

    public function save_product_field( int $post_id ): void {
        update_post_meta( $post_id, '_rk_session_credits', absint( $_POST['_rk_session_credits'] ?? 0 ) );
    }
}
