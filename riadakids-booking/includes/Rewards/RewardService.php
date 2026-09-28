<?php
/**
 * RiadaKids\Rewards\RewardService — v25 PRODUCTION
 *
 * ══════════════════════════════════════════════════════════════
 * FIXES APPLIQUÉS
 * ══════════════════════════════════════════════════════════════
 *
 * FIX14 — try/catch global sur toutes les méthodes publiques.
 *
 * FIX15 — set_points() et subtract_points() publics pour permettre
 *          à CreditService::process_order_reversal() de retirer
 *          les points quand une commande est annulée/remboursée.
 *          Empêche la désynchronisation crédits / rewards.
 *
 * JS FIX — Le JS inline du widget (dismiss alert) contenait une
 *           syntax error : `var $btn=$this)` → corrigé en `var $btn=$(this)`.
 */

namespace RiadaKids\Rewards;

use RiadaKids\Credits\CreditRepository;
use RiadaKids\Credits\CreditLogger;
use RiadaKids\Database\DB;

if ( ! defined( 'ABSPATH' ) ) exit;

class RewardService {

    const THRESHOLD = 200;

    public function __construct() {
        add_action( 'woocommerce_account_dashboard', [ $this, 'display_widget' ], 5 );
        add_action( 'wp_ajax_rk_dismiss_reward_alert', [ $this, 'ajax_dismiss_alert' ] );
    }

    /* ════════════════════════════════════════════════════════════
     * API STATIQUE POINTS
     * ════════════════════════════════════════════════════════════ */

    public static function get_points( int $user_id ): int {
        return max( 0, (int) get_user_meta( $user_id, 'rk_reward_points', true ) );
    }

    public static function set_points( int $user_id, int $points ): void {
        update_user_meta( $user_id, 'rk_reward_points', max( 0, $points ) );
    }

    /**
     * FIX15 — Soustraction sécurisée de points (sans passer sous 0).
     * Utilisé par CreditService lors d'un remboursement.
     * Retourne le nouveau solde.
     */
    public static function subtract_points( int $user_id, int $amount ): int {
        $current  = self::get_points( $user_id );
        $deducted = min( $amount, $current );
        $new      = $current - $deducted;
        self::set_points( $user_id, $new );
        return $new;
    }

    /**
     * Ajoute des points et vérifie automatiquement le seuil.
     * FIX14 — Wrapped try/catch.
     */
    public static function add_points(
        int    $user_id,
        int    $points,
        string $action      = 'misc',
        string $description = ''
    ): int {
        try {
            if ( ! $user_id || $points <= 0 ) return self::get_points( $user_id );

            $old = self::get_points( $user_id );
            $new = $old + $points;
            self::set_points( $user_id, $new );

            $total = (int) get_user_meta( $user_id, 'rk_total_points_earned', true );
            update_user_meta( $user_id, 'rk_total_points_earned', $total + $points );

            DB::add_points_log( $user_id, $points, $action, $description );
            \rk_log( 'REWARDS', "+{$points} pts user#{$user_id} ({$action}) total:{$new}" );

            self::maybe_auto_redeem( $user_id );

            return $new;

        } catch ( \Throwable $e ) {
            \rk_log( 'REWARDS', "add_points exception user#{$user_id}: " . $e->getMessage() );
            return self::get_points( $user_id );
        }
    }

    /* ════════════════════════════════════════════════════════════
     * AUTO-REDEEM — seuil 200 pts
     * ════════════════════════════════════════════════════════════ */

    public static function maybe_auto_redeem( int $user_id ): int {
        $redeemed = 0;
        $safety   = 10;

        while ( self::get_points( $user_id ) >= self::THRESHOLD && $safety-- > 0 ) {
            if ( ! self::redeem_one( $user_id ) ) break;
            $redeemed++;
        }

        return $redeemed;
    }

    private static function redeem_one( int $user_id ): bool {
        try {
            $current = self::get_points( $user_id );
            if ( $current < self::THRESHOLD ) return false;

            $remaining = $current - self::THRESHOLD;
            self::set_points( $user_id, $remaining );

            DB::add_points_log( $user_id, -self::THRESHOLD, 'redeem',
                sprintf( 'استبدال %d نقطة بلقاء مجاني', self::THRESHOLD ) );

            $new_bal = CreditRepository::increase( $user_id, 1 );
            CreditLogger::log( $user_id, 'reward', 1, $new_bal, 'لقاء مجاني — نظام المكافآت (200 نقطة)' );

            // Alerte dashboard
            $pending   = array_values( array_filter(
                (array) get_user_meta( $user_id, 'rk_show_reward_alert', true ),
                'is_array'
            ) );
            $pending[] = [ 'time' => current_time( 'mysql' ), 'sessions' => 1 ];
            update_user_meta( $user_id, 'rk_show_reward_alert', array_values( $pending ) );

            // Notification DB + email via queue (FIX04)
            DB::add_notification( $user_id, 'reward', 'تهانينا! لقد حصلت على لقاء مجاني',
                [ 'threshold' => self::THRESHOLD, 'balance' => $new_bal ] );

            DB::enqueue_job( 'reward_email', [ 'user_id' => $user_id, 'balance' => $new_bal ], 0 );

            do_action( 'rk_reward_redeemed', $user_id );
            \rk_log( 'REWARDS', "REWARD user#{$user_id} pts_restants:{$remaining} +1 séance" );

            return true;

        } catch ( \Throwable $e ) {
            \rk_log( 'REWARDS', "redeem_one exception user#{$user_id}: " . $e->getMessage() );
            return false;
        }
    }

    /* ════════════════════════════════════════════════════════════
     * DASHBOARD WIDGET
     * ════════════════════════════════════════════════════════════ */

    public function display_widget(): void {
        try {
            $user_id  = get_current_user_id();
            if ( ! $user_id ) return;

            $points   = self::get_points( $user_id );
            $progress = min( 100, round( ( $points / self::THRESHOLD ) * 100, 1 ) );
            $pts_left = max( 0, self::THRESHOLD - $points );

            $pending = array_values( array_filter(
                (array) get_user_meta( $user_id, 'rk_show_reward_alert', true ),
                'is_array'
            ) );

            $logs  = DB::get_points_logs( $user_id, 5 );
            $nonce = \RiadaKids\Core\Security::reward_nonce();
            ?>
            <div class="rk-rewards-widget" dir="rtl">

                <?php foreach ( $pending as $idx => $alert ) : ?>
                <div class="rk-reward-alert-banner" style="background:linear-gradient(135deg,#d3f9d8,#b2f2bb);border:1px solid #51cf66;border-radius:10px;padding:14px 18px;margin-bottom:12px;display:flex;align-items:center;gap:12px;">
                    <span style="color:#f59e0b;display:inline-flex;"><?php echo \RiadaKids\Core\Icons::get( 'trophy', 28 ); ?></span>
                    <div style="flex:1;">
                        <strong style="color:#2b8a3e;">تهانينا! لقد حصلت على لقاء مجانية</strong><br>
                        <span style="font-size:13px;color:#444;">لقد جمعت 200 نقطة — تم تحويلها تلقائياً إلى لقاء مجانية في رصيدك.</span>
                    </div>
                    <button class="rk-reward-dismiss"
                            data-idx="<?php echo esc_attr( $idx ); ?>"
                            data-nonce="<?php echo esc_attr( $nonce ); ?>"
                            style="background:none;border:none;font-size:18px;cursor:pointer;color:#555;padding:4px 8px;"><?php echo \RiadaKids\Core\Icons::get( 'x', 16 ); ?></button>
                </div>
                <?php endforeach; ?>

                <div class="rk-points-card" style="background:#fff;border:1px solid #e9ecef;border-radius:12px;padding:20px;margin-bottom:20px;box-shadow:0 2px 8px rgba(0,0,0,.06);">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;">
                        <div style="display:flex;gap:10px;align-items:center;">
                            <span style="color:#f59e0b;display:inline-flex;"><?php echo \RiadaKids\Core\Icons::get( 'medal', 24 ); ?></span>
                            <div>
                                <strong style="font-size:15px;color:#1a1a2e;">نقاط المكافآت</strong><br>
                                <small style="color:#868e96;">كل <?php echo self::THRESHOLD; ?> نقطة = لقاء مجانية</small>
                            </div>
                        </div>
                        <div style="text-align:center;">
                            <span style="font-size:32px;font-weight:800;color:#3b5bdb;"><?php echo esc_html( $points ); ?></span><br>
                            <span style="font-size:12px;color:#868e96;">نقطة</span>
                        </div>
                    </div>

                    <div style="background:#f1f3f5;border-radius:50px;height:12px;overflow:hidden;margin-bottom:8px;">
                        <div style="width:<?php echo esc_attr( $progress ); ?>%;background:linear-gradient(90deg,#3b5bdb,#845ef7);height:100%;border-radius:50px;transition:width .4s ease;"></div>
                    </div>

                    <div style="display:flex;justify-content:space-between;font-size:12px;color:#868e96;margin-bottom:14px;">
                        <span><?php echo esc_html( $points ); ?> / <?php echo self::THRESHOLD; ?></span>
                        <?php if ( $pts_left > 0 ) : ?>
                        <span><?php echo esc_html( $pts_left ); ?> نقطة للقاء المجاني التالي</span>
                        <?php else : ?>
                        <span style="color:#2b8a3e;font-weight:700;"><?php echo \RiadaKids\Core\Icons::get( 'check-circle', 15 ); ?> يمكنك الحصول على لقاء مجانية!</span>
                        <?php endif; ?>
                    </div>

                    <?php if ( ! empty( $logs ) ) : ?>
                    <details style="margin-top:10px;">
                        <summary style="cursor:pointer;color:#3b5bdb;font-size:13px;font-weight:600;"><?php echo \RiadaKids\Core\Icons::get( 'list', 14 ); ?> آخر العمليات</summary>
                        <div style="margin-top:8px;">
                            <?php
                            $labels = [
                                'pack_purchase'     => 'شراء باقة',
                                'booking_completed' => 'استخدام لقاء',
                                'redeem'            => 'استبدال نقاط',
                                'admin_add'         => 'إضافة إدارية',
                                'refund_reversal'   => 'إلغاء طلب',
                                'cancellation_reversal' => 'إلغاء طلب',
                                'misc'              => 'متنوع',
                            ];
                            foreach ( $logs as $log ) :
                                $pos   = $log->points > 0;
                                $color = $pos ? '#2b8a3e' : '#c92a2a';
                                $icon  = $pos ? '↑' : '↓';
                                $label = $labels[ $log->action_type ] ?? $log->action_type;
                            ?>
                            <div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid #f5f5f5;font-size:13px;">
                                <div>
                                    <span style="color:<?php echo $color; ?>;font-weight:700;"><?php echo $icon; ?> <?php echo abs( $log->points ); ?> نقطة</span>
                                    <span style="color:#666;margin-right:6px;"><?php echo esc_html( $label ); ?></span>
                                </div>
                                <span style="color:#aaa;font-size:12px;"><?php echo date_i18n( 'j M Y', strtotime( $log->created_at ) ); ?></span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </details>
                    <?php endif; ?>
                </div>
            </div>

            <script>
            /* FIX JS — var $btn=$(this) était $btn=$this) — syntax error corrigée */
            jQuery(function($){
                $('.rk-reward-dismiss').on('click', function(){
                    var $btn = $(this);
                    var $row = $btn.closest('.rk-reward-alert-banner');
                    $row.slideUp(250, function(){ $(this).remove(); });
                    $.post('<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>', {
                        action : 'rk_dismiss_reward_alert',
                        idx    : $btn.data('idx'),
                        nonce  : $btn.data('nonce')
                    });
                });
            });
            </script>
            <?php
        } catch ( \Throwable $e ) {
            \rk_log( 'REWARDS', "display_widget exception: " . $e->getMessage() );
        }
    }

    /* ── AJAX dismiss ───────────────────────────────────────────── */

    public function ajax_dismiss_alert(): void {
        \RiadaKids\Core\Security::verify_reward_request();

        $user_id = get_current_user_id();
        $idx     = isset( $_POST['idx'] ) ? (int) $_POST['idx'] : null;

        $pending = array_values( array_filter(
            (array) get_user_meta( $user_id, 'rk_show_reward_alert', true ),
            'is_array'
        ) );

        if ( $idx !== null && isset( $pending[ $idx ] ) ) {
            array_splice( $pending, $idx, 1 );
        }

        if ( empty( $pending ) ) {
            delete_user_meta( $user_id, 'rk_show_reward_alert' );
        } else {
            update_user_meta( $user_id, 'rk_show_reward_alert', array_values( $pending ) );
        }

        wp_send_json_success();
    }
}
