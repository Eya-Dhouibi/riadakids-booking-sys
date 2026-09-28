<?php
declare( strict_types=1 );
/**
 * Application/Command — FamilyLinkCommandService  (v3.1.0 — DDD fix)
 *
 * Écritures sur la relation coach ↔ enfant ↔ parent.
 *
 *   • set_primary_coach()   : désigne LE coach de référence d'un enfant
 *                             (celui affiché dans les vues parent/enfant,
 *                             destinataire des self-reports de mission).
 *   • backfill_from_bookings() : reconstruit wp_rk_child_coaches depuis
 *                             l'historique wp_rk_bookings — corrige tous
 *                             les enfants inscrits AVANT que le booking
 *                             bridge n'écrive la table (idempotent).
 *   • unlink()              : retire une assignation (fin de collaboration).
 *
 * Chaque écriture invalide les caches des vues concernées et journalise
 * dans wp_rk_audit_log si disponible.
 *
 * RÈGLE (13/08/2026) : aucun accès $wpdb direct dans ce fichier — toutes
 * les requêtes SQL (y compris la transaction START/COMMIT/ROLLBACK de
 * set_primary_coach) vivent dans RKP_CoachStudentRepository
 * (Infrastructure). Violation corrigée suite à audit : ce fichier
 * contenait auparavant des requêtes $wpdb brutes ET des transactions SQL
 * manuelles, seule exception parmi les Query/Command Services du plugin.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class RKP_FamilyLinkCommandService {

	/* ─────────────────────────────────────────────────────────────
	 * Coach principal
	 * ───────────────────────────────────────────────────────────── */

	/**
	 * Désigne le coach principal d'un enfant.
	 * Crée l'assignation si absente, dégrade les autres à is_primary = 0.
	 */
	public static function set_primary_coach( int $child_rk_id, int $coach_id, int $actor_id = 0 ): bool {
		if ( $child_rk_id <= 0 || $coach_id <= 0 ) return false;

		RKP_CoachStudentRepository::begin_transaction();

		// Assignation présente ? sinon la créer.
		RKP_CoachStudentRepository::ensure_assignment( $child_rk_id, $coach_id );

		$ok = RKP_CoachStudentRepository::set_primary_coach_atomic( $child_rk_id, $coach_id, $actor_id );

		if ( ! $ok ) {
			RKP_CoachStudentRepository::rollback();
			return false;
		}
		RKP_CoachStudentRepository::commit();

		self::audit( 'primary_coach_set', $child_rk_id, $coach_id, $actor_id );
		self::flush( $child_rk_id, $coach_id );
		do_action( 'rkp_primary_coach_changed', $child_rk_id, $coach_id, $actor_id );
		return true;
	}

	/** Retire une assignation coach↔enfant. Refuse de retirer le primaire sans remplaçant. */
	public static function unlink( int $child_rk_id, int $coach_id, int $actor_id = 0 ): bool {
		if ( $child_rk_id <= 0 || $coach_id <= 0 ) return false;

		$is_primary = RKP_CoachStudentRepository::get_is_primary( $child_rk_id, $coach_id );
		$others     = RKP_CoachStudentRepository::count_other_coaches( $child_rk_id, $coach_id );

		// Règle métier : un enfant actif garde toujours un coach de référence.
		if ( $is_primary && 0 === $others ) return false;

		$deleted = RKP_CoachStudentRepository::delete_assignment( $child_rk_id, $coach_id );
		if ( $deleted ) {
			// Si on vient de retirer le primaire, promouvoir le plus récent restant.
			if ( $is_primary ) {
				$next = RKP_CoachStudentRepository::find_latest_coach_assignment( $child_rk_id );
				if ( $next > 0 ) self::set_primary_coach( $child_rk_id, $next, $actor_id );
			}
			self::audit( 'coach_unlinked', $child_rk_id, $coach_id, $actor_id );
			self::flush( $child_rk_id, $coach_id );
		}
		return $deleted;
	}

	/* ─────────────────────────────────────────────────────────────
	 * Backfill — réconciliation depuis l'historique des bookings
	 * ───────────────────────────────────────────────────────────── */

	/**
	 * Reconstruit les assignations manquantes depuis wp_rk_bookings.
	 * Idempotent (INSERT IGNORE). Désigne comme primaire le coach du
	 * booking le plus récent pour les enfants qui n'en ont pas encore.
	 *
	 * À lancer : une fois à l'upgrade v3.0, puis disponible en admin
	 * ("Réconcilier les coachs") et en WP-CLI.
	 *
	 * @return array{assignments:int, primaries:int}
	 */
	public static function backfill_from_bookings(): array {
		$result     = RKP_CoachStudentRepository::backfill_assignments_from_bookings();
		$primaries  = 0;

		foreach ( $result['candidates'] as $r ) {
			if ( self::set_primary_coach( (int) $r->child_id, (int) $r->coach_id ) ) {
				$primaries++;
			}
		}

		self::audit( 'family_backfill', 0, 0, get_current_user_id(),
			sprintf( 'assignments=%d primaries=%d', $result['assignments'], $primaries ) );

		return [ 'assignments' => $result['assignments'], 'primaries' => $primaries ];
	}

	/* ─────────────────────────────────────────────────────────────
	 * Interne
	 * ───────────────────────────────────────────────────────────── */

	private static function flush( int $child_rk_id, int $coach_id ): void {
		if ( class_exists( 'RKP_FamilyLinkQueryService' ) ) {
			// Le cache statique vit par requête ; on invalide les transients de vues.
			$ctx = RKP_FamilyLinkQueryService::context( $child_rk_id );
			if ( $ctx['child_wp_uid'] > 0 ) delete_transient( 'rk_child_dash_' . $ctx['child_wp_uid'] );
			if ( $ctx['parent_id'] > 0 )    delete_transient( 'rk_parent_board_' . $ctx['parent_id'] );
		}
		delete_transient( 'rk_coach_stats_' . $coach_id );
		do_action( 'rk_coach_flush_stats', $coach_id );
	}

	private static function audit( string $event, int $child_id, int $coach_id, int $actor_id, string $note = '' ): void {
		if ( class_exists( 'RK_Audit_Log' ) && method_exists( 'RK_Audit_Log', 'log' ) ) {
			RK_Audit_Log::log( $event, [
				'child_id' => $child_id,
				'coach_id' => $coach_id,
				'actor_id' => $actor_id,
				'note'     => $note,
			] );
		}
	}
}