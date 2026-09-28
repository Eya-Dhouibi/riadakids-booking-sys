<?php
declare( strict_types=1 );
/**
 * Application — WalletQueryService  v2.2.0 — Audit P2-17
 * Lecture du portefeuille de crédits. Dépend du PORT, pas de l'implémentation.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class RKP_WalletQueryService {

	public static function get_balance( int $user_id ): int {
		return self::port()->balance( $user_id );
	}

	/** @return object[] Mouvements (amount, reason, meta, actor_id, created_at). */
	public static function get_history( int $user_id, int $limit = 20 ): array {
		return self::port()->history( $user_id, $limit );
	}

	private static function port(): RKP_WalletPort {
		/** @var RKP_WalletPort */
		return RKP_Container::get( RKP_WalletPort::class );
	}
}
