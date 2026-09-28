<?php
declare( strict_types=1 );
/**
 * Application — RKP_EventExperienceService
 *
 * Traduit chaque Domain Event en "expérience UX" (§2 du cahier des
 * charges) : priorité, type d'affichage (modal HIGH / toast LOW),
 * illustration réelle du plugin (§25 — pas de nouvel asset créé),
 * texte, CTA. Ne fait AUCUN calcul métier (XP, niveau, badge) — lit
 * uniquement les données déjà présentes dans le payload de l'event ou
 * via les Query Services existants (§27 : jamais de SQL direct ici).
 *
 * S'abonne à RKP_EventBus au boot — c'est la SEULE classe qui construit
 * le mapping Event → UX ; le reste de la couche (queue, popups, toasts)
 * ne fait que consommer ce qu'elle produit.
 *
 * @since 9.9.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;

final class RKP_EventExperienceService {

    private const PRIORITY_HIGH   = 'high';
    private const PRIORITY_MEDIUM = 'medium';
    private const PRIORITY_LOW    = 'low';

    public static function init(): void {
        RKP_EventBus::subscribe( 'badge.earned',           [ self::class, 'on_badge_earned'    ], 30 );
        RKP_EventBus::subscribe( 'child.level_up',          [ self::class, 'on_level_up'         ], 30 );
        RKP_EventBus::subscribe( 'quiz.passed',             [ self::class, 'on_quiz_passed'      ], 30 );
        RKP_EventBus::subscribe( 'quiz.failed',             [ self::class, 'on_quiz_failed'      ], 30 );
        RKP_EventBus::subscribe( 'lesson.completed',        [ self::class, 'on_lesson_completed' ], 30 );
        RKP_EventBus::subscribe( 'course.completed',        [ self::class, 'on_course_completed' ], 30 );
        RKP_EventBus::subscribe( 'booking.confirmed',       [ self::class, 'on_booking_confirmed'], 30 );
        RKP_EventBus::subscribe( 'report.published',        [ self::class, 'on_report_published' ], 30 );
        RKP_EventBus::subscribe( 'coach.message_received',  [ self::class, 'on_coach_message'    ], 30 );
        // course.started et quiz.assigned : volontairement AUCUNE expérience
        // visuelle immédiate (§20 anti-spam — "commencer" un cours n'a pas la
        // charge émotionnelle d'une réussite, un quiz assigné relève déjà de
        // la cloche de notifications existante RK_MC_Notification_Service).
    }

    public static function on_badge_earned( RKP_BadgeEarned $e ): void {
        $badge = self::get_badge_definition( $e->badge_key );
        self::enqueue( $e, self::PRIORITY_HIGH, 'modal', [
            'icon'         => 'badge',
            'illustration' => self::ico( 'badge-animated.svg' ),
            'title'        => __( 'شارة جديدة!', 'rk-my-children' ),
            'message'      => $badge['name'] ?? __( 'حصلت على شارة جديدة!', 'rk-my-children' ),
            'reward'       => null,
            'cta_label'    => __( 'عرض الشارة', 'rk-my-children' ),
            'cta_action'   => 'navigate:rk-badges',
            'animation'    => 'badge-reveal',
        ] );
    }

    public static function on_level_up( RKP_LevelUp $e ): void {
        self::enqueue( $e, self::PRIORITY_HIGH, 'modal', [
            'icon'         => 'rocket',
            'illustration' => self::ico( 'icon-rocket-animated.svg' ),
            'title'        => __( 'مستوى جديد!', 'rk-my-children' ),
            'message'      => sprintf(
                /* translators: %d: new level number */
                __( 'انتقلت إلى المستوى %d!', 'rk-my-children' ),
                $e->new_level
            ),
            'reward'       => null,
            'cta_label'    => __( 'اكتشف مستواك', 'rk-my-children' ),
            'cta_action'   => 'navigate:home',
            'animation'    => 'celebration-short',
        ] );
    }

    public static function on_quiz_passed( RKP_QuizPassed $e ): void {
        self::enqueue( $e, self::PRIORITY_HIGH, 'modal', [
            'icon'         => 'trophy',
            'illustration' => self::illustration( 'character-challenge.webp' ),
            'title'        => __( 'ممتاز!', 'rk-my-children' ),
            'message'      => __( 'نجحت في الاختبار!', 'rk-my-children' ),
            'score'        => $e->score_percent,
            'reward'       => null,
            'cta_label'    => __( 'تابع مغامرتك', 'rk-my-children' ),
            'cta_action'   => 'navigate:enrolled-courses',
            'animation'    => 'trophy-stars',
        ] );
    }

    public static function on_quiz_failed( RKP_QuizFailed $e ): void {
        self::enqueue( $e, self::PRIORITY_LOW, 'toast', [
            'icon'         => 'star',
            'illustration' => self::ico( 'icon-star-smiley.svg' ),
            'title'        => __( 'محاولة رائعة!', 'rk-my-children' ),
            'message'      => __( 'كل محاولة تجعلك أقوى.', 'rk-my-children' ),
            'score'        => $e->score_percent,
            'reward'       => null,
            'cta_label'    => __( 'حاول مرة أخرى', 'rk-my-children' ),
            'cta_action'   => 'navigate:enrolled-courses',
            'animation'    => 'gentle-fade',
        ] );
    }

    public static function on_lesson_completed( RKP_LessonCompleted $e ): void {
        self::enqueue( $e, self::PRIORITY_MEDIUM, 'toast', [
            'icon'         => 'checkmark',
            'illustration' => self::ico( 'icon-checkmark.svg' ),
            'title'        => __( 'أحسنت!', 'rk-my-children' ),
            'message'      => __( 'أنهيت الدرس.', 'rk-my-children' ),
            'reward'       => self::real_xp_for_lesson(),
            'cta_label'    => __( 'الدرس التالي', 'rk-my-children' ),
            'cta_action'   => 'navigate:next_lesson:' . $e->lesson_id,
            'animation'    => 'small-bounce',
        ] );
    }

    public static function on_course_completed( RKP_CourseCompleted $e ): void {
        self::enqueue( $e, self::PRIORITY_HIGH, 'modal', [
            'icon'         => 'flag',
            'illustration' => self::illustration( 'character-join.webp' ),
            'title'        => __( 'أحسنت!', 'rk-my-children' ),
            'message'      => __( 'أكملت المغامرة بنجاح!', 'rk-my-children' ),
            'reward'       => null,
            'cta_label'    => __( 'تابع مغامرتك', 'rk-my-children' ),
            'cta_action'   => 'navigate:enrolled-courses',
            'animation'    => 'confetti-short',
        ] );
    }

    public static function on_booking_confirmed( RKP_BookingConfirmed $e ): void {
        $coach = class_exists( 'RKP_UserRepository' ) ? RKP_UserRepository::find_coach( $e->coach_id ) : null;
        self::enqueue( $e, self::PRIORITY_MEDIUM, 'modal', [
            'icon'         => 'calendar',
            'illustration' => self::ico( 'icon-calendar.svg' ),
            'title'        => __( 'جلستك مؤكدة!', 'rk-my-children' ),
            'message'      => $coach
                ? sprintf( __( 'مع المدرب %s', 'rk-my-children' ), $coach->display_name )
                : __( 'موعدك محجوز.', 'rk-my-children' ),
            'session_at'   => $e->session_at->format( 'c' ),
            'reward'       => null,
            'cta_label'    => __( 'عرض الجلسة', 'rk-my-children' ),
            'cta_action'   => 'navigate:rk-sessions',
            'animation'    => 'small-bounce',
        ] );
    }

    public static function on_report_published( RKP_ReportPublished $e ): void {
        self::enqueue( $e, self::PRIORITY_MEDIUM, 'toast', [
            'icon'         => 'clipboard',
            'illustration' => null,
            'title'        => __( 'تقرير جديد من مدربك', 'rk-my-children' ),
            'message'      => __( 'مدربك أرسل لك ملاحظات جديدة.', 'rk-my-children' ),
            'reward'       => null,
            'cta_label'    => __( 'عرض التقرير', 'rk-my-children' ),
            'cta_action'   => 'navigate:rk-parent-board',
            'animation'    => 'gentle-fade',
        ] );
    }

    public static function on_coach_message( RKP_CoachMessageReceived $e ): void {
        $coach = class_exists( 'RKP_UserRepository' ) ? RKP_UserRepository::find_coach( $e->coach_wp_uid ) : null;
        self::enqueue( $e, self::PRIORITY_LOW, 'toast', [
            'icon'         => 'chat',
            'illustration' => null,
            'title'        => $coach
                ? sprintf( __( 'المدرب %s', 'rk-my-children' ), $coach->display_name )
                : __( 'رسالة جديدة', 'rk-my-children' ),
            'message'      => $e->excerpt,
            'reward'       => null,
            'cta_label'    => __( 'اقرأ الرسالة', 'rk-my-children' ),
            'cta_action'   => 'navigate:rk-messages',
            'animation'    => 'gentle-fade',
        ] );
    }

    /* ── Construction commune ────────────────────────────────────── */

    private static function enqueue( RKP_DomainEvent $event, string $priority, string $display, array $payload ): void {
        if ( empty( $event->child_id ) ) return;

        $event_id = self::event_id( $event );

        if ( class_exists( 'RKP_EventExperienceLog' ) ) {
            RKP_EventExperienceLog::enqueue( $event_id, (int) $event->child_id, $event->get_name() );
        }

        set_transient(
            'rkp_exp_' . $event_id,
            array_merge( $payload, [
                'event_id'   => $event_id,
                'event_type' => $event->get_name(),
                'priority'   => $priority,
                'display'    => $display,
                'duration'   => 'modal' === $display ? 0 : 4500,
            ] ),
            DAY_IN_SECONDS
        );
    }

    private static function event_id( RKP_DomainEvent $event ): string {
        if ( method_exists( $event, 'event_id' ) ) {
            $id = (string) $event->event_id();
            if ( '' !== $id ) return $id;
        }
        return substr( hash( 'sha256', $event->get_name() . '|' . wp_json_encode( $event->to_array() ) ), 0, 36 );
    }

    /* ── Données réelles uniquement (§26 — AUCUNE mock data) ────────── */

    private static function real_xp_for_lesson(): int {
        // Barème réel déjà utilisé par class-rk-mc-gamification-service.php
        // (on_lesson_completed → add_points(..., 10, ...)) — pas recalculé,
        // juste reflété pour ne jamais afficher un XP différent du vrai montant crédité.
        return 10;
    }

    private static function get_badge_definition( string $badge_key ): array {
        if ( ! class_exists( 'RKP_BadgeQueryService' ) || ! method_exists( 'RKP_BadgeQueryService', 'catalogue' ) ) {
            return [];
        }
        $catalogue = RKP_BadgeQueryService::catalogue();
        return is_array( $catalogue[ $badge_key ] ?? null ) ? $catalogue[ $badge_key ] : [];
    }

    private static function ico( string $filename ): string {
        return defined( 'RK_MC_URL' ) ? RK_MC_URL . 'assets/img/icons/' . $filename : '';
    }

    private static function illustration( string $filename ): string {
        return defined( 'RK_MC_URL' ) ? RK_MC_URL . 'assets/img/illustrations/' . $filename : '';
    }
}
