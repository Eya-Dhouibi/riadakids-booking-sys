<?php
declare( strict_types=1 );
/**
 * Application — RKP_EntryStoryService
 *
 * Sélectionne UNE SEULE "histoire" à présenter à l'entrée du Dashboard
 * (§4-9 de la spec Mobile Motion & UX), selon la priorité §7 :
 *   1. nouveau badge (obtenu récemment, non encore vu comme story)
 *   2. mission à terminer (proche de l'objectif, non complétée)
 *   3. session aujourd'hui
 *   4. session à venir (plus tard)
 *   5. leçon à continuer
 *   6. progression importante (seuil franchi)
 *   7. retour après absence
 *   8. nouveau contenu — non implémenté (aucune source de donnée
 *      "nouveau cours publié" identifiée dans le code existant)
 *   9. encouragement général (repli toujours disponible)
 *
 * RÈGLE ABSOLUE (§30) : ne consomme QUE des services déjà existants
 * (RKP_BadgeQueryService, RKP_MissionQueryService, RKP_BookingQueryService,
 * JourneySnapshot via RK_MC_Tutor_Dashboard::get_snapshot()) — aucun
 * calcul métier recréé ici, uniquement de la sélection/priorisation
 * et de la mise en forme pour l'UI.
 *
 * Fusionne et remplace RKP_EventExperienceService pour l'affichage à
 * l'entrée (décision explicite de l'utilisateur) — RKP_EventExperienceService
 * continue d'exister pour les popups en cours de session (badge gagné
 * PENDANT que l'enfant est déjà sur le Dashboard), un besoin distinct.
 *
 * @since 9.10.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;

final class RKP_EntryStoryService {

    /**
     * @return array{type:string, title:string, message:string, icon:string, cta_label:string, cta_action:string, extra:array}|null
     *         null si aucune histoire pertinente (repli "encouragement
     *         général" garantit normalement qu'un résultat existe
     *         toujours, sauf enfant introuvable).
     */
    public static function select_for_child( int $child_rk_id, int $child_wp_uid, string $child_name ): ?array {
        if ( ! $child_rk_id || ! $child_wp_uid ) return null;

        $story =
            self::try_new_badge( $child_rk_id, $child_wp_uid )
            ?? self::try_mission_near_completion( $child_rk_id )
            ?? self::try_session_today( $child_rk_id, $child_wp_uid )
            ?? self::try_session_upcoming( $child_rk_id, $child_wp_uid )
            ?? self::try_continue_lesson( $child_wp_uid )
            ?? self::try_significant_progress( $child_wp_uid )
            ?? self::try_returning_after_absence( $child_wp_uid, $child_name )
            ?? self::fallback_encouragement( $child_name );

        return $story;
    }

    /* ── 1. Nouveau badge ────────────────────────────────────────── */
    private static function try_new_badge( int $child_rk_id, int $child_wp_uid ): ?array {
        if ( ! class_exists( 'RKP_BadgeQueryService' ) ) return null;

        $full = RKP_BadgeQueryService::get_catalogue_for_child( $child_rk_id );

        $recent = null;
        $cutoff = time() - 3 * DAY_IN_SECONDS; // "récent" = obtenu dans les 3 derniers jours

        foreach ( $full as $badge ) {
            if ( empty( $badge['earned'] ) || empty( $badge['earned_at'] ) ) continue;
            $ts = strtotime( (string) $badge['earned_at'] );
            if ( $ts && $ts >= $cutoff && ( null === $recent || $ts > $recent['ts'] ) ) {
                $recent = [ 'badge' => $badge, 'ts' => $ts ];
            }
        }

        if ( ! $recent ) return null;

        return [
            'type'       => 'new_badge',
            'title'      => __( 'شارة جديدة', 'rk-my-children' ),
            'message'    => sprintf(
                /* translators: %s: badge name */
                __( 'لقد حققت إنجازاً جديداً: %s', 'rk-my-children' ),
                $recent['badge']['name'] ?? ''
            ),
            'icon'       => 'award',
            'cta_label'  => __( 'شاهد الشارة', 'rk-my-children' ),
            'cta_action' => 'navigate:rk-badges',
            'extra'      => [],
        ];
    }

    /* ── 2. Mission proche de complétion ─────────────────────────── */
    private static function try_mission_near_completion( int $child_rk_id ): ?array {
        if ( ! class_exists( 'RKP_MissionQueryService' ) ) return null;

        $missions = RKP_MissionQueryService::get_weekly_missions( $child_rk_id );
        $best     = null;

        foreach ( $missions as $m ) {
            if ( ! empty( $m['completed'] ) ) continue;
            // "à terminer" : au moins un progrès réel effectué (pct > 0),
            // pas une mission jamais commencée — sinon ce serait
            // indiscernable du repli générique "encouragement".
            if ( $m['pct'] <= 0 ) continue;
            if ( null === $best || $m['pct'] > $best['pct'] ) $best = $m;
        }

        if ( ! $best ) return null;

        return [
            'type'       => 'mission_near',
            'title'      => __( 'مهمتك بانتظارك', 'rk-my-children' ),
            'message'    => sprintf(
                /* translators: %s: mission name */
                __( 'أنجز هذه المهمة لتتقدم في رحلتك: %s', 'rk-my-children' ),
                $best['name']
            ),
            'icon'       => 'nav-target',
            'cta_label'  => __( 'ابدأ المهمة', 'rk-my-children' ),
            'cta_action' => 'navigate:home',
            'extra'      => [ 'progress_pct' => $best['pct'] ],
        ];
    }

    /* ── 3-4. Session aujourd'hui / à venir ──────────────────────── */
    private static function try_session_today( int $child_rk_id, int $child_wp_uid ): ?array {
        return self::session_story( $child_rk_id, $child_wp_uid, true );
    }
    private static function try_session_upcoming( int $child_rk_id, int $child_wp_uid ): ?array {
        return self::session_story( $child_rk_id, $child_wp_uid, false );
    }

    private static function session_story( int $child_rk_id, int $child_wp_uid, bool $today_only ): ?array {
        if ( ! class_exists( 'RKP_BookingQueryService' ) ) return null;

        $bookings = RKP_BookingQueryService::get_all_for_child( $child_rk_id, 'upcoming' );
        if ( empty( $bookings ) ) return null;

        $next = $bookings[0]; // déjà trié ASC par appointment (voir find_all_by_child)
        $is_today = $next->start_at->format( 'Y-m-d' ) === ( new \DateTimeImmutable( 'now', wp_timezone() ) )->format( 'Y-m-d' );

        if ( $today_only && ! $is_today ) return null;
        if ( ! $today_only && $is_today ) return null; // déjà couvert par try_session_today

        $coach = ( $next->coach_id && class_exists( 'RKP_UserRepository' ) ) ? RKP_UserRepository::find_coach( $next->coach_id ) : null;

        return [
            'type'       => $today_only ? 'session_today' : 'session_upcoming',
            'title'      => $today_only ? __( 'لديك حصة اليوم', 'rk-my-children' ) : __( 'حصتك القادمة', 'rk-my-children' ),
            'message'    => $next->session_name ?: __( 'موعدك محجوز.', 'rk-my-children' ),
            'icon'       => 'session',
            'cta_label'  => __( 'عرض الحصة', 'rk-my-children' ),
            'cta_action' => 'navigate:rk-sessions',
            'extra'      => [
                'time'       => wp_date( 'g:i a', $next->start_at->getTimestamp(), wp_timezone() ),
                'coach_name' => $coach ? $coach->display_name : '',
            ],
        ];
    }

    /* ── 5. Leçon à continuer ─────────────────────────────────────── */
    private static function try_continue_lesson( int $child_wp_uid ): ?array {
        if ( ! class_exists( 'RK_MC_Tutor_Dashboard' ) ) return null;
        $snapshot = RK_MC_Tutor_Dashboard::get_snapshot();
        if ( ! $snapshot || ! $snapshot->next_lesson ) return null;
        if ( 'continue_lesson' !== $snapshot->next_action ) return null;

        return [
            'type'       => 'continue_lesson',
            'title'      => __( 'رحلتك التعليمية مستمرة', 'rk-my-children' ),
            'message'    => $snapshot->next_lesson->title,
            'icon'       => 'book-open',
            'cta_label'  => __( 'متابعة الدرس', 'rk-my-children' ),
            'cta_action' => 'navigate:enrolled-courses',
            'extra'      => [],
        ];
    }

    /* ── 6. Progression importante ────────────────────────────────── */
    private static function try_significant_progress( int $child_wp_uid ): ?array {
        if ( ! class_exists( 'RK_MC_Tutor_Dashboard' ) ) return null;
        $snapshot = RK_MC_Tutor_Dashboard::get_snapshot();
        if ( ! $snapshot || ! $snapshot->progress ) return null;

        $pct = (int) $snapshot->progress->percent;
        // Seuils "notables" — pas une donnée inventée, juste un
        // découpage produit raisonnable sur la vraie valeur de progression.
        if ( ! in_array( $pct, [ 25, 50, 75, 90 ], true ) ) return null;

        return [
            'type'       => 'progress',
            'title'      => __( 'تقدم رائع', 'rk-my-children' ),
            'message'    => sprintf(
                /* translators: %d: percent */
                __( 'أكملت %d%% من مغامرتك الحالية!', 'rk-my-children' ),
                $pct
            ),
            'icon'       => 'progress',
            'cta_label'  => __( 'متابعة الرحلة', 'rk-my-children' ),
            'cta_action' => 'navigate:enrolled-courses',
            'extra'      => [ 'progress_pct' => $pct ],
        ];
    }

    /* ── 7. Retour après absence ──────────────────────────────────── */
    private static function try_returning_after_absence( int $child_wp_uid, string $child_name ): ?array {
        $last = (int) get_user_meta( $child_wp_uid, 'rk_last_login_tracked', true );
        if ( ! $last ) return null; // pas d'historique fiable — pas de story plutôt qu'une donnée inventée

        $days_absent = (int) floor( ( time() - $last ) / DAY_IN_SECONDS );
        if ( $days_absent < 3 ) return null; // "absence" = au moins 3 jours, seuil produit raisonnable

        return [
            'type'       => 'returning',
            'title'      => sprintf(
                /* translators: %s: child first name */
                __( 'أهلاً بعودتك يا %s', 'rk-my-children' ),
                $child_name
            ),
            'message'    => __( 'رحلتك التعليمية ما زالت مستمرة.', 'rk-my-children' ),
            'icon'       => 'journey',
            'cta_label'  => __( 'متابعة الرحلة', 'rk-my-children' ),
            'cta_action' => 'navigate:enrolled-courses',
            'extra'      => [],
        ];
    }

    /* ── 9. Repli — encouragement général (toujours disponible) ────── */
    private static function fallback_encouragement( string $child_name ): array {
        return [
            'type'       => 'encouragement',
            'title'      => sprintf(
                /* translators: %s: child first name */
                __( 'أهلاً بك يا %s', 'rk-my-children' ),
                $child_name
            ),
            'message'    => __( 'مغامرتك القادمة تنتظرك، فلنبدأ!', 'rk-my-children' ),
            'icon'       => 'rocket',
            'cta_label'  => __( 'استكشف مغامراتك', 'rk-my-children' ),
            'cta_action' => 'navigate:enrolled-courses',
            'extra'      => [],
        ];
    }
}
