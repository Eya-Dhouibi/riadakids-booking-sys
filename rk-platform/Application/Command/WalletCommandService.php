<?php
declare( strict_types=1 );
/**
 * Application — WalletCommandService  v2.2.0 — Audit P2-17
 *
 * Écritures du portefeuille. Toute mutation exige une RAISON et une
 * CLÉ D'IDEMPOTENCE dérivée du fait métier :
 *
 *   RKP_WalletCommandService::credit( $parent_id, 1, 'booking_refund', 'refund_booking_42' );
 *   RKP_WalletCommandService::debit(  $parent_id, 1, 'session_booked', 'debit_booking_43'  );
 *
 * Rejouer le même fait (webhook doublé, retry) est un no-op silencieux.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class RKP_WalletCommandService {

	public static function credit( int $user_id, int $amount, string $reason, string $idempotency_key, array $meta = [] ): bool {
		$ok = self::port()->credit( $user_id, $amount, $reason, $idempotency_key, $meta );
		if ( $ok ) {
			do_action( 'rkp_wallet_credited', $user_id, $amount, $reason );
		}
		return $ok;
	}

	/** @return bool false si solde insuffisant ou fait déjà traité. */
	public static function debit( int $user_id, int $amount, string $reason, string $idempotency_key, array $meta = [] ): bool {
		$ok = self::port()->debit( $user_id, $amount, $reason, $idempotency_key, $meta );
		if ( $ok ) {
			do_action( 'rkp_wallet_debited', $user_id, $amount, $reason );
		}
		return $ok;
	}

	private static function port(): RKP_WalletPort {
		/** @var RKP_WalletPort */
		return RKP_Container::get( RKP_WalletPort::class );
	}
}
