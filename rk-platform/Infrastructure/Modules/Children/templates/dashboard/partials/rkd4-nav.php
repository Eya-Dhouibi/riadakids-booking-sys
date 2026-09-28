<?php
/**
 * RKD4 — Pont vers le chrome de navigation partage.
 *
 * Depuis v8.4, sidebar / topbar mobile / bottom nav sont fournis par
 * RK_MC_Dashboard_Chrome_V4 afin que la home ET les sous-pages du dashboard
 * partagent exactement la meme navigation.
 *
 * Ce partial ne fait plus que reexposer les closures attendues par
 * dashboard.php, pour ne pas casser le template existant.
 *
 * @package RK_My_Children
 * @since   8.4.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$rkd4_sidebar  = static fn(): string => RK_MC_Dashboard_Chrome_V4::sidebar();
$rkd4_mobtop   = static fn(): string => RK_MC_Dashboard_Chrome_V4::mobtop();
$rkd4_botnav   = static fn(): string => RK_MC_Dashboard_Chrome_V4::botnav();
$rkd4_topbar   = static fn(): string => RK_MC_Dashboard_Chrome_V4::topbar();
// v9.54 (13/08/2026) — Bouton flottant "الرسائل", ajouté à la home au
// même titre que les autres closures ci-dessus (déjà appelé sur les
// sous-pages via render_subpage_chrome_close()).
$rkd4_msg_fab  = static fn(): string => RK_MC_Dashboard_Chrome_V4::messages_fab();