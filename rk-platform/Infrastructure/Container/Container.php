<?php
declare( strict_types=1 );
/**
 * Infrastructure — RKP_Container  v2.2.0 — Audit P2-12
 *
 * Micro-container d'injection de dépendances. Pas de framework :
 * bind() / singleton() / get(), c'est tout.
 * Substitution (tests, LMS alternatif) :
 *   RKP_Container::bind( RKP_LearningPort::class, fn() => new My_LearnDash_Adapter() );
 * Point d'extension : hook `rkp_container_ready`.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class RKP_Container {

	/** @var array<string, callable> */
	private static array $bindings = [];

	/** @var array<string, object> */
	private static array $instances = [];

	/** Enregistre une factory (nouvelle instance à chaque get). */
	public static function bind( string $id, callable $factory ): void {
		self::$bindings[ $id ] = $factory;
		unset( self::$instances[ $id ] );
	}

	/** Enregistre une factory singleton (instance mémoïsée). */
	public static function singleton( string $id, callable $factory ): void {
		self::bind( $id, static function () use ( $id, $factory ) {
			return self::$instances[ $id ] ??= $factory();
		} );
	}

	public static function has( string $id ): bool {
		return isset( self::$bindings[ $id ] );
	}

	/** @throws \RuntimeException si l'id n'est pas enregistré. */
	public static function get( string $id ): object {
		if ( ! isset( self::$bindings[ $id ] ) ) {
			throw new \RuntimeException( "RKP_Container : binding manquant pour {$id}" );
		}
		return ( self::$bindings[ $id ] )();
	}

	/** Réinitialisation (tests uniquement). */
	public static function reset(): void {
		self::$bindings  = [];
		self::$instances = [];
	}

	/* ── Bindings par défaut (adapters vers l'existant — zéro breaking) ── */

	public static function boot(): void {
		self::singleton( RKP_LearningPort::class,  static fn() => new RKP_TutorLearningAdapter() );
		self::singleton( RKP_BookingPort::class,   static fn() => new RKP_SsaBookingAdapter() );
		self::singleton( RKP_MessagingPort::class, static fn() => new RKP_BetterMessagesAdapter() );
		self::singleton( RKP_WalletPort::class,    static fn() => new RKP_WalletLedgerRepository() );

		/** Point d'extension : substituer/ajouter des bindings. */
		do_action( 'rkp_container_ready', self::class );
	}
}
