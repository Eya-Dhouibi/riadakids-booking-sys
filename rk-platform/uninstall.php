<?php
declare( strict_types=1 );
/**
 * Désinstallation de RiadaKids Platform.  (audit 19/08/2026)
 *
 * ┌──────────────────────────────────────────────────────────────────────────┐
 * │  ⚠️  DESTRUCTIF — LIRE AVANT DE DÉSINSTALLER                             │
 * │                                                                          │
 * │  Par défaut, ce script est PRUDENT : il ne supprime QUE les données      │
 * │  jetables (options, transients, tâches cron, user_meta techniques).      │
 * │  Les 21 tables custom — enfants, réservations, badges, évaluations,      │
 * │  bilans, messages — sont CONSERVÉES.                                     │
 * │                                                                          │
 * │  Pour tout effacer (tables + comptes enfants + rôle), définir dans       │
 * │  wp-config.php AVANT de désinstaller :                                   │
 * │                                                                          │
 * │      define( 'RKP_UNINSTALL_REMOVE_ALL_DATA', true );                    │
 * │                                                                          │
 * │  Cette opération est IRRÉVERSIBLE. Faire un dump SQL avant.              │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * @package RK_Platform
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

/* ════════════════════════════════════════════════════════════════
 * 1. Tâches planifiées
 * ════════════════════════════════════════════════════════════════ */

foreach ( array(
	'rkp_purge_child_archive',
	'rk_coach_hub_weekly_report',
	'rk_mc_monthly_notif_purge',
	'rk_monthly_pdf_cron',
) as $rkp_hook ) {
	wp_clear_scheduled_hook( $rkp_hook );
}

/* ════════════════════════════════════════════════════════════════
 * 2. Options
 * ════════════════════════════════════════════════════════════════ */

foreach ( array(
	// Versions de schéma
	'rkp_db_version',
	'rk_mc_children_table_ver',
	'rk_mc_communication_table_ver',
	'rk_mc_gamification_table_ver',
	'rk_mc_v2_table_ver',
	// Flags de migration
	'rk_appointment_dates_backfilled',
	'rk_bookings_coach_id_backfilled',
	'rk_child_coaches_backfilled',
	'rk_notifications_installed',
	'rk_qm_options_fixed_v1',
	'rkp_family_backfill_done',
	'rkp_system_badges_migrated_v1',
	// Pages auto-créées (les posts eux-mêmes sont conservés)
	'rk_booking_page_id',
	'rk_coach_hub_dashboard_page_id',
	'rk_coach_hub_espace_coach_page_id',
	'rk_coach_hub_login_page_id',
	'rk_mc_child_login_page_id',
	// Réglages
	'rk_coach_hub_rewrite_flushed',
	'rk_credits_product_url',
	'rk_mc_meldo_url',
	'rk_mc_youtube_url',
	'rk_platform_admin_user_id',
	'rk_ssa_coach_map',
	// Caches de détection de tables tierces
	'rkp_custom_badge_table_exists',
	'rkp_message_table_exists',
	'rkp_ays_table_exists',
) as $rkp_option ) {
	delete_option( $rkp_option );
	delete_site_option( $rkp_option );
}

/* ════════════════════════════════════════════════════════════════
 * 3. Transients (caches dashboard, throttling PIN, verrous)
 * ════════════════════════════════════════════════════════════════ */

$rkp_transient_prefixes = array(
	'rk_ch_',        // caches coach hub
	'rk_child_dash_',
	'rk_dash_',
	'rk_tab_',       // sessions onglet enfant
	'rk_pin_fail_',  // compteurs anti brute-force
	'rkp_',
);

foreach ( $rkp_transient_prefixes as $rkp_prefix ) {
	$rkp_like = $wpdb->esc_like( '_transient_' . $rkp_prefix ) . '%';
	$rkp_keys = $wpdb->get_col(
		$wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $rkp_like )
	);
	foreach ( (array) $rkp_keys as $rkp_key ) {
		delete_transient( substr( (string) $rkp_key, strlen( '_transient_' ) ) );
	}
}

/* ════════════════════════════════════════════════════════════════
 * 4. User meta techniques
 *
 * Toujours purgées : ce sont des jetons de session et des caches, sans
 * valeur métier, et laisser traîner rk_child_tab_token est un risque.
 * ════════════════════════════════════════════════════════════════ */

foreach ( array(
	'rk_child_tab_token',
	'rk_child_tab_parent',
	'rk_entry_story_last_shown',
	'rk_last_login_tracked',
	'rk_last_seen',
	'rk_onboarding_seen',
	'rk_children_ids',
) as $rkp_meta ) {
	$wpdb->delete( $wpdb->usermeta, array( 'meta_key' => $rkp_meta ) );
}

// Métas à clé préfixée (une par enfant / par cours)
foreach ( array( 'rk_coach_notes_child_', 'rk_pdf_last_', 'rk_prog_snap_' ) as $rkp_meta_prefix ) {
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s",
			$wpdb->esc_like( $rkp_meta_prefix ) . '%'
		)
	);
}

/* ════════════════════════════════════════════════════════════════
 * 5. Suppression totale — opt-in explicite uniquement
 * ════════════════════════════════════════════════════════════════ */

if ( ! defined( 'RKP_UNINSTALL_REMOVE_ALL_DATA' ) || ! RKP_UNINSTALL_REMOVE_ALL_DATA ) {
	return;
}

/* 5a. Comptes enfants + PIN + rôle */
require_once ABSPATH . 'wp-admin/includes/user.php';

$rkp_children = get_users( array( 'role' => 'rk_child', 'fields' => 'ID', 'number' => -1 ) );
foreach ( (array) $rkp_children as $rkp_child_uid ) {
	wp_delete_user( (int) $rkp_child_uid );
}

$wpdb->delete( $wpdb->usermeta, array( 'meta_key' => 'rk_child_pin' ) );
$wpdb->delete( $wpdb->usermeta, array( 'meta_key' => 'rk_badges' ) );
$wpdb->delete( $wpdb->usermeta, array( 'meta_key' => 'rk_session_credits' ) );

remove_role( 'rk_child' );

/* 5b. Tables custom — ordre indifférent, aucune contrainte FK déclarée */
foreach ( array(
	'rk_children',
	'rk_children_archive',
	'rk_assessment_drafts',
	'rk_child_assessments',
	'rk_child_badges',
	'rk_child_certificates',
	'rk_child_sessions',
	'rk_child_coaches',
	'rk_child_messages',
	'rk_child_missions',
	'rk_child_points',
	'rk_child_skills',
	'rk_bookings',
	'rk_coach_missions',
	'rk_custom_badges',
	'rk_notifications',
	'rk_progress_snapshots',
	'rk_user_credits',
	'rk_audit_log',
	'rk_availability_history',
	'rkp_event_log',
	'rkp_event_experience_seen',
	'rkp_wallet_ledger',
) as $rkp_table ) {
	$wpdb->query( 'DROP TABLE IF EXISTS `' . $wpdb->prefix . $rkp_table . '`' );
}
