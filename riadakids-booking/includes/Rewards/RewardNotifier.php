<?php
/**
 * RiadaKids\Rewards\RewardNotifier — v25 PRODUCTION
 *
 * ══════════════════════════════════════════════════════════════
 * FIX04 — Queue email avec retry WP-Cron
 * ══════════════════════════════════════════════════════════════
 *
 * Avant (❌ fragile) :
 *   RewardNotifier::send_reward_email() → wp_mail() direct
 *   → si SMTP échoue : email perdu définitivement
 *
 * Après (✓ robuste) :
 *   RewardService::redeem_one() enqueue un job dans wp_rk_jobs
 *   → Plugin::run_cron_jobs() tente l'envoi toutes les 60s
 *   → max 3 tentatives (configurable via RK_JOB_MAX_RETRIES)
 *   → status : pending → processing → done/failed
 *
 * Ce fichier gère l'envoi réel (appelé par le job processor).
 */

namespace RiadaKids\Rewards;

use RiadaKids\Credits\CreditRepository;

if ( ! defined( 'ABSPATH' ) ) exit;

class RewardNotifier {

    /**
     * FIX04 — Envoi de l'email de récompense (appelé par le job processor).
     * En cas d'échec wp_mail(), retourne false → le job sera retenté.
     */
    public static function send_reward_email( int $user_id ): bool {
        $user = get_userdata( $user_id );
        if ( ! $user ) return false;

        $balance   = CreditRepository::get_balance( $user_id );
        $pts_after = RewardService::get_points( $user_id );
        $site      = get_bloginfo( 'name' );
        $subject   = "[{$site}] 🎉 تهانينا! حصلت على لقاء مجاني";

        $msg  = '<div dir="rtl" style="font-family:Tahoma,sans-serif;max-width:580px;margin:0 auto;background:#fff;border-radius:12px;border:1px solid #e9ecef;overflow:hidden;">';
        $msg .= '<div style="background:linear-gradient(135deg,#3b5bdb,#845ef7);padding:28px;text-align:center;">';
        $msg .= '<div style="font-size:52px;margin-bottom:8px;">🏆</div>';
        $msg .= '<h1 style="color:#fff;margin:0;font-size:22px;">تهانينا ' . esc_html( $user->display_name ) . '!</h1>';
        $msg .= '</div>';
        $msg .= '<div style="padding:28px;">';
        $msg .= '<p style="font-size:16px;">لقد جمعت <strong style="color:#3b5bdb;">200 نقطة مكافأة</strong> وتم تحويلها تلقائياً إلى:</p>';
        $msg .= '<div style="background:#d3f9d8;border-radius:8px;padding:16px 20px;margin:16px 0;">';
        $msg .= '<span style="font-size:32px;">🎁</span> ';
        $msg .= '<strong style="font-size:18px;color:#2b8a3e;">لقاء مجاني</strong>';
        $msg .= '<br><span style="color:#555;">تمت الإضافة مباشرة إلى رصيدك</span>';
        $msg .= '</div>';
        $msg .= '<table style="width:100%;border-collapse:collapse;margin:16px 0;">';
        $msg .= '<tr><th style="text-align:right;padding:10px 14px;background:#f8f9fa;border:1px solid #e9ecef;color:#555;">رصيد الحصص الحالي</th>';
        $msg .= '<td style="padding:10px 14px;border:1px solid #e9ecef;font-weight:700;color:#2b8a3e;">' . intval( $balance ) . ' لقاء</td></tr>';
        $msg .= '<tr><th style="text-align:right;padding:10px 14px;background:#f8f9fa;border:1px solid #e9ecef;color:#555;">نقاط المكافآت المتبقية</th>';
        $msg .= '<td style="padding:10px 14px;border:1px solid #e9ecef;font-weight:700;">' . intval( $pts_after ) . ' نقطة</td></tr>';
        $msg .= '</table>';
        $msg .= '<p style="color:#888;font-size:13px;">واصل الحجز وتجميع النقاط للحصول على المزيد من الحصص المجانية!</p>';
        $msg .= '</div></div>';

        $sent = wp_mail(
            $user->user_email,
            $subject,
            $msg,
            [ 'Content-Type: text/html; charset=UTF-8' ]
        );

        if ( $sent ) {
            \rk_log( 'EMAIL', "Reward email envoyé → {$user->user_email}" );
        } else {
            \rk_log( 'EMAIL', "Échec envoi reward email → {$user->user_email} (sera retenté FIX04)" );
        }

        return $sent;
    }
}
