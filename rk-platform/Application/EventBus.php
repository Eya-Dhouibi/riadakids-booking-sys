<?php
declare( strict_types=1 );
/**
 * Application — EventBus  (v2.1.0 — Audit P1-8/P1-13)
 *
 * Pub/sub des Domain Events.
 *
 * Nouveautés v2.1 :
 *   • Logging PERMANENT des échecs subscribers (plus seulement en WP_DEBUG)
 *     + hook `rkp_event_subscriber_failed` pour alerting (Sentry, mail admin…).
 *   • Idempotence optionnelle par subscriber (RKP_EventLog) — activée pour
 *     tout subscriber enregistré avec $idempotent = true. Indispensable pour
 *     l'attribution d'XP et de badges.
 *   • dispatch_async() : exécution différée via Action Scheduler (livré avec
 *     WooCommerce) — retry ×3 automatique, hors requête HTTP de l'enfant.
 *     Fallback synchrone transparent si AS est absent.
 *
 * Usage :
 *   RKP_EventBus::subscribe( 'quiz.passed', [ XpService::class, 'award' ], 10, true ); // idempotent
 *   RKP_EventBus::dispatch( new RKP_QuizPassed( $child_id, $quiz_id, $course_id ) );
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class RKP_EventBus {

	const ASYNC_HOOK = 'rkp_async_event';

	/** @var array<string, array<int, array<int, array{cb: callable, idem: bool}>>> */
	private static array $listeners = [];

	/**
	 * Abonner un handler à un événement.
	 *
	 * @param string   $event_name Ex. 'lesson.completed'
	 * @param callable $handler    Callable recevant le RKP_DomainEvent
	 * @param int      $priority   Plus bas = exécuté en premier
	 * @param bool     $idempotent true → ne sera jamais exécuté 2× pour le même event_id
	 */
	public static function subscribe( string $event_name, callable $handler, int $priority = 10, bool $idempotent = false ): void {
		self::$listeners[ $event_name ][ $priority ][] = [ 'cb' => $handler, 'idem' => $idempotent ];
	}

	/** Dispatch synchrone à tous les subscribers. */
	public static function dispatch( RKP_DomainEvent $event ): void {
		$name = $event->get_name();
		if ( empty( self::$listeners[ $name ] ) ) return;

		$event_id = self::event_id( $event );

		$sorted = self::$listeners[ $name ];
		ksort( $sorted );

		foreach ( $sorted as $handlers ) {
			foreach ( $handlers as $entry ) {
				$subscriber = self::describe( $entry['cb'] );

				// Idempotence : claim atomique — si déjà traité, on saute.
				if ( $entry['idem'] && class_exists( 'RKP_EventLog' )
					&& ! RKP_EventLog::claim( $event_id, $subscriber, $name ) ) {
					continue;
				}

				try {
					( $entry['cb'] )( $event );
				} catch ( \Throwable $e ) {
					// Un subscriber ne fait jamais planter les autres —
					// mais son échec n'est plus JAMAIS silencieux.
					rkp_log( sprintf( '[RKP_EventBus] %s → %s : %s', $name, $subscriber, $e->getMessage() ) );
					do_action( 'rkp_event_subscriber_failed', $name, $subscriber, $e, $event );

					if ( $entry['idem'] && class_exists( 'RKP_EventLog' ) ) {
						RKP_EventLog::release_failed( $event_id, $subscriber, $e->getMessage() );
					}
				}
			}
		}
	}

	/**
	 * Dispatch différé via Action Scheduler (retry ×3 intégré à AS).
	 * Fallback : dispatch synchrone immédiat si AS indisponible.
	 */
	public static function dispatch_async( RKP_DomainEvent $event ): void {
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::ASYNC_HOOK, [
				'class'   => get_class( $event ),
				'payload' => $event->to_array(),
			], 'rk-platform' );
			return;
		}
		self::dispatch( $event );
	}

	/** Worker Action Scheduler — reconstruit l'événement et le dispatch. */
	public static function handle_async( string $class, array $payload ): void {
		if ( ! class_exists( $class )
			|| ! in_array( RKP_DomainEvent::class, class_implements( $class ) ?: [], true )
			|| ! method_exists( $class, 'from_array' ) ) {
			rkp_log( "[RKP_EventBus] handle_async : {$class} ne supporte pas from_array() — événement ignoré." );
			return;
		}
		$event = $class::from_array( $payload );
		if ( $event instanceof RKP_DomainEvent ) {
			self::dispatch( $event );
		}
	}

	public static function boot(): void {
		add_action( self::ASYNC_HOOK, [ self::class, 'handle_async' ], 10, 2 );
	}

	/** Réinitialise tous les listeners (tests uniquement). */
	public static function reset(): void {
		self::$listeners = [];
	}

	/* ── Helpers ─────────────────────────────────────────────────────── */

	/** UUID stable de l'événement : fourni par l'événement, sinon dérivé du contenu. */
	private static function event_id( RKP_DomainEvent $event ): string {
		if ( method_exists( $event, 'event_id' ) ) {
			$id = (string) $event->event_id();
			if ( $id !== '' ) return $id;
		}
		// Déterministe : même fait métier ⇒ même id ⇒ déduplication naturelle.
		return substr( hash( 'sha256', $event->get_name() . '|' . wp_json_encode( $event->to_array() ) ), 0, 36 );
	}

	private static function describe( callable $cb ): string {
		if ( is_array( $cb ) ) {
			return ( is_object( $cb[0] ) ? get_class( $cb[0] ) : (string) $cb[0] ) . '::' . $cb[1];
		}
		if ( is_string( $cb ) ) return $cb;
		return 'closure@' . spl_object_hash( (object) $cb );
	}
}
