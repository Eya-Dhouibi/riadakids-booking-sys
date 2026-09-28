<?php
declare( strict_types=1 );
/**
 * Application — CacheInvalidationListener
 *
 * S'abonne aux Domain Events qui rendent le cache dashboard obsolète
 * et publie le hook WP `rkp_child_cache_invalidate` (child_wp_uid).
 *
 * C'est rk-my-children qui s'abonne à ce hook et nettoie
 * ses propres transients — rk-platform ne connaît pas leur format.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class RKP_CacheInvalidationListener {

    public static function subscribe(): void {
        RKP_EventBus::subscribe( 'lesson.completed', [ self::class, 'on_child_event' ] );
        RKP_EventBus::subscribe( 'quiz.passed',      [ self::class, 'on_child_event' ] );
        RKP_EventBus::subscribe( 'quiz.failed',      [ self::class, 'on_child_event' ] );
        RKP_EventBus::subscribe( 'course.completed', [ self::class, 'on_child_event' ] );
    }

    public static function on_child_event( RKP_DomainEvent $event ): void {
        $data     = $event->to_array();
        $child_id = (int) ( $data['child_id'] ?? 0 );
        if ( $child_id > 0 ) {
            do_action( 'rkp_child_cache_invalidate', $child_id );
        }
    }
}
