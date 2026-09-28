<?php
declare( strict_types=1 );
/**
 * RK_Event_Bus — Orchestrateur central des événements RiadaKids v2.
 *
 * Chaque hook déclenche une chaîne d'effets : XP · Badges · Crédits · Notifications · Cache.
 * Centraliser ici évite la duplication entre plugins et garantit l'ordre d'exécution.
 *
 * Hooks entrants :
 *   rk_session_attended($child_id, $booking_id)   — coach marque présence
 *   rk_session_absent($child_id, $booking_id)     — coach marque absence
 *   rk_booking_confirmed($booking_id, $ctx)       — priorité 15 (après Bridge@10)
 *   rk_mc_badge_awarded($child_id, $badge_key, $badge_def)
 *   rk_mc_child_level_up($child_id, $new_level, $old_level)
 *   tutor_quiz_attempt_ended($attempt)            — priorité 20 (après Gamification@10)
 *   tutor_course_complete_after($course_id, $user_id) — priorité 20
 *
 * @package RK_My_Children
 * @since   8.0.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class RK_Event_Bus {

    /* ─── Init ──────────────────────────────────────────────────── */

    public static function init(): void {
        add_action( 'rk_session_attended',          [ __CLASS__, 'on_session_attended'  ], 10, 3 );
        add_action( 'rk_session_absent',            [ __CLASS__, 'on_session_absent'    ], 10, 3 );
        add_action( 'rk_booking_confirmed',         [ __CLASS__, 'on_booking_confirmed' ], 15, 2 );
        add_action( 'rk_booking_cancelled',         [ __CLASS__, 'on_booking_cancelled' ], 10, 2 );
        add_action( 'rk_mc_badge_awarded',          [ __CLASS__, 'on_badge_awarded'     ], 10, 3 );
        add_action( 'rk_mc_child_level_up',         [ __CLASS__, 'on_level_up'          ], 10, 3 );
        /*
         * v2.3 (Audit P2-9) — UNIFICATION DU VOCABULAIRE D'ÉVÉNEMENTS.
         * Ce module ne consomme plus les hooks Tutor bruts : il s'abonne aux
         * Domain Events core (quiz.passed / quiz.failed / course.completed)
         * dispatchés par RKP_TutorBridge. Un seul point de contact avec
         * Tutor LMS (le bridge), un seul flux d'événements.
         */
        if ( class_exists( 'RKP_EventBus' ) ) {
            RKP_EventBus::subscribe( 'quiz.passed',      [ __CLASS__, 'on_quiz_core_event' ] );
            RKP_EventBus::subscribe( 'quiz.failed',      [ __CLASS__, 'on_quiz_core_event' ] );
            RKP_EventBus::subscribe( 'course.completed', [ __CLASS__, 'on_course_core_event' ] );
        } else {
            // Filet de sécurité (core absent) : ancien câblage direct.
            add_action( 'tutor_quiz_attempt_ended',    [ __CLASS__, 'on_quiz_ended' ], 20, 1 );
            add_action( 'tutor_course_complete_after', [ __CLASS__, 'on_course_complete' ], 20, 2 );
        }
        add_action( 'rk_mc_assessment_created',     [ __CLASS__, 'on_assessment_created'], 10, 3 );
        add_action( 'rk_mc_mission_completed',      [ __CLASS__, 'on_mission_completed' ], 10, 2 );
    }

    /* ═══════════════════════════════════════════════════════════════
       PRÉSENCE — effets sur la présence réelle (pas le booking)
       ═══════════════════════════════════════════════════════════════ */

    /**
     * Présence confirmée par le coach.
     *
     * Règle métier :
     *   Présent → XP + crédit déduit atomiquement (transaction MySQL).
     *   Absent  → rien (crédit conservé).
     *
     * Idempotence : source='session_attended' + source_id=booking_id dans wp_rk_child_points.
     * Transaction scope MINIMAL : uniquement INSERT points + UPDATE crédit.
     * Les badges et notifications s'exécutent APRÈS COMMIT (défaillance acceptable).
     */
    public static function on_session_attended( int $child_id, int $booking_id, string $prev_attendance = '' ): void {
        global $wpdb;

        // Lecture idempotence AVANT transaction (lecture seule, pas d'impact)
        $already_xp = class_exists( 'RKP_GamificationRepository' )
            && RKP_GamificationRepository::has_points_for_source_id( $child_id, $booking_id, [ 'session_attended' ] );

        if ( ! $already_xp ) {
            // Snapshot du niveau AVANT insert (pour détecter level-up après COMMIT)
            $old_total = class_exists( 'RK_MC_Gamification_Service' )
                ? RK_MC_Gamification_Service::get_total_points( $child_id )
                : 0;

            // ── Transaction atomique XP + Crédit ─────────────────────────────
            $wpdb->query( 'START TRANSACTION' );

            $xp_ok = class_exists( 'RKP_GamificationRepository' )
                && RKP_GamificationRepository::insert_points( $child_id, 20, 'session_attended', $booking_id );
            $credit_ok = self::deduct_session_credit( $child_id );

            $xp_credit_committed = ( $xp_ok && $credit_ok );
            if ( $xp_credit_committed ) {
                $wpdb->query( 'COMMIT' );
            } else {
                $wpdb->query( 'ROLLBACK' );
                rkp_log( sprintf(
                    '[RK EventBus] session_attended ROLLBACK — child=%d booking=%d xp=%d credit=%d sql=%s',
                    $child_id, $booking_id, (int) $xp_ok, (int) $credit_ok,
                    (string) $wpdb->last_error
                ) );
                self::flush_coach_stats_for_child( $child_id );
            }
            // ── Fin transaction ───────────────────────────────────────────────

            // BUGFIX (13/08/2026) — Le bloc post-COMMIT ci-dessous (cache
            // dashboard, level-up, notif parent, alerte crédit) dépend
            // réellement du succès de la transaction XP/crédit — il reste
            // donc conditionné à $xp_credit_committed. Mais l'attribution
            // des badges (أول خطوة / first_session, streak), elle, ne
            // dépendait pas fonctionnellement de ce succès — un `return;`
            // placé ici auparavant sortait pourtant de TOUTE la fonction
            // avant d'atteindre le code des badges plus bas, malgré le
            // commentaire de tête de fonction documentant explicitement
            // l'intention contraire : "Les badges et notifications
            // s'exécutent APRÈS COMMIT (défaillance acceptable)".
            // Concrètement : si le crédit de séances était insuffisant
            // (deduct_session_credit() échoue), la transaction s'annulait
            // correctement, mais le badge de présence n'était JAMAIS
            // attribué non plus — alors que l'enfant avait bel et bien
            // été marqué présent par le coach. Le bloc post-COMMIT est
            // maintenant scopé dans le if ci-dessous ; l'exécution
            // continue toujours après vers l'attribution des badges,
            // conformément à l'intention documentée.
            if ( $xp_credit_committed ) {
                do_action( 'rk_mc_child_points_added', $child_id, 20, 'session_attended' );

                if ( class_exists( 'RK_MC_Gamification_Service' ) ) {
                    $new_total = RK_MC_Gamification_Service::get_total_points( $child_id );
                    $new_level = RK_MC_Gamification_Service::compute_level( $new_total );
                    $old_level = RK_MC_Gamification_Service::compute_level( $old_total );
                    if ( $new_level['num'] > $old_level['num'] ) {
                        do_action( 'rk_mc_child_level_up', $child_id, $new_level, $old_level );
                    }
                }

                self::notify_parent_session_attended( $child_id, $booking_id );

                // Alerte crédit faible post-déduction
                $cred_parent = self::get_parent_id( $child_id );
                if ( $cred_parent > 0 ) {
                    $credits = function_exists( 'rk_mc_get_session_credits' )
                        ? rk_mc_get_session_credits( $cred_parent )
                        : 99;
                    if ( $credits <= 2 ) {
                        self::notify_low_credits( $cred_parent, $credits );
                    }
                }
            }
        }

        // Badges + cache : idempotents, safe à rejouer même après Absent→Présent
        // أول خطوة — décernée à la 1re séance réellement effectuée (idempotent)
        if ( class_exists( 'RK_MC_Badge_Service' ) ) {
            RK_MC_Badge_Service::award( $child_id, 'first_session' );
        }
        self::maybe_award_streak_badges( $child_id );
        self::flush_coach_stats_for_child( $child_id );
    }

    /**
     * Absence confirmée par le coach.
     *
     * Règle métier (confirmée) : absent = crédit conservé.
     * Seul le cache stats est invalidé.
     */
    public static function on_session_absent( int $child_id, int $booking_id, string $prev_attendance = '' ): void {
        self::flush_coach_stats_for_child( $child_id );
    }

    /* ═══════════════════════════════════════════════════════════════
       BOOKING — alimentation wp_rk_child_coaches
       ═══════════════════════════════════════════════════════════════ */

    public static function on_booking_confirmed( int $booking_id, $ctx ): void {
        if ( ! $ctx ) return;
        self::sync_child_coaches( $booking_id, $ctx );
    }

    /* ═══════════════════════════════════════════════════════════════
       QUIZ — notification parent (XP géré par Gamification Service)
       ═══════════════════════════════════════════════════════════════ */

    /** v2.3 — Subscriber core : RKP_QuizPassed | RKP_QuizFailed. */
    public static function on_quiz_core_event( RKP_DomainEvent $event ): void {
        self::notify_quiz( (int) $event->child_id, (int) $event->quiz_id, (int) $event->score_percent );
    }

    /** v2.3 — Subscriber core : RKP_CourseCompleted. */
    public static function on_course_core_event( RKP_DomainEvent $event ): void {
        self::on_course_complete( (int) $event->course_id, (int) $event->child_id );
    }

    /** Câblage legacy conservé en filet de sécurité (voir init). */
    public static function on_quiz_ended( object $attempt ): void {
        $score = (float) ( $attempt->earned_marks ?? 0 );
        $total = max( 1.0, (float) ( $attempt->total_marks ?? 1 ) );
        self::notify_quiz(
            (int) ( $attempt->user_id ?? 0 ),
            (int) ( $attempt->quiz_id ?? 0 ),
            (int) round( $score / $total * 100 )
        );
    }

    /** Notifications parent + coach après un quiz (logique unique, deux entrées). */
    private static function notify_quiz( int $user_id, int $quiz_id, int $pct ): void {
        if ( ! class_exists( 'RK_MC_Notification_Service' ) ) return;

        $child_id = class_exists( 'RK_MC_Gamification_Service' )
            ? RK_MC_Gamification_Service::get_child_id_for_wp_user( $user_id )
            : 0;
        if ( ! $child_id ) return;

        $title = $quiz_id > 0 ? ( get_the_title( $quiz_id ) ?: 'الاختبار' ) : 'الاختبار';

        // Notification parent
        $parent_id = self::get_parent_id( $child_id );
        if ( $parent_id > 0 ) {
            RK_MC_Notification_Service::push(
                $parent_id,
                'quiz_completed',
                sprintf( '📝 أنهى طفلك اختبار "%s" بنتيجة %d%%', $title, $pct ),
                [ 'child_id' => $child_id, 'quiz_id' => $quiz_id, 'score_pct' => $pct ]
            );
        }

        // Notification coach pour le suivi
        $primary_coach_id = class_exists( 'RKP_CoachStudentRepository' )
            ? RKP_CoachStudentRepository::find_primary_coach_for_child( $child_id )
            : 0;
        if ( $primary_coach_id > 0 ) {
            $child_name = '';
            $cu = self::get_child_wp_user_id( $child_id );
            if ( $cu ) {
                $ud = get_userdata( $cu );
                $child_name = $ud ? $ud->display_name : '';
            }
            RK_MC_Notification_Service::push(
                $primary_coach_id,
                'quiz_completed_coach',
                sprintf( '📝 أنهى الطالب %s اختبار "%s" بنتيجة %d%%', $child_name, $title, $pct ),
                [ 'child_id' => $child_id, 'quiz_id' => $quiz_id, 'score_pct' => $pct ]
            );
        }
    }

    /* ═══════════════════════════════════════════════════════════════
       COURS — notification parent (XP géré par Gamification Service)
       ═══════════════════════════════════════════════════════════════ */

    public static function on_course_complete( int $course_id, int $user_id ): void {
        if ( ! class_exists( 'RK_MC_Notification_Service' ) ) return;

        $child_id = class_exists( 'RK_MC_Gamification_Service' )
            ? RK_MC_Gamification_Service::get_child_id_for_wp_user( $user_id )
            : 0;
        if ( ! $child_id ) return;

        $title     = get_the_title( $course_id ) ?: 'البرنامج';
        $parent_id = self::get_parent_id( $child_id );

        if ( $parent_id > 0 ) {
            RK_MC_Notification_Service::push(
                $parent_id,
                'course_completed',
                sprintf( '🎉 أنهى طفلك البرنامج "%s" بالكامل! مبروك!', $title ),
                [ 'child_id' => $child_id, 'course_id' => $course_id ]
            );
        }

        // Notification enfant
        $child_wp_uid = self::get_child_wp_user_id( $child_id );
        if ( $child_wp_uid > 0 ) {
            RK_MC_Notification_Service::push(
                $child_wp_uid,
                'course_completed',
                sprintf( '🎉 أنهيت البرنامج "%s"! أنت بطل!', $title ),
                [ 'course_id' => $course_id ]
            );
        }
    }

    /* ═══════════════════════════════════════════════════════════════
       BADGES — notification sur obtention
       ═══════════════════════════════════════════════════════════════ */

    public static function on_badge_awarded( int $child_id, string $badge_key, array $badge_def ): void {
        if ( ! class_exists( 'RK_MC_Notification_Service' ) ) return;

        $badge_name = $badge_def['name'] ?? $badge_key;
        $parent_id  = self::get_parent_id( $child_id );

        if ( $parent_id > 0 ) {
            RK_MC_Notification_Service::push(
                $parent_id, 'badge_awarded',
                sprintf( '🏅 حصل طفلك على شارة "%s"!', $badge_name ),
                [ 'child_id' => $child_id, 'badge_key' => $badge_key ]
            );
        }

        $child_wp_uid = self::get_child_wp_user_id( $child_id );
        if ( $child_wp_uid > 0 ) {
            RK_MC_Notification_Service::push(
                $child_wp_uid, 'badge_awarded',
                sprintf( '🏅 حصلت على شارة "%s"! أنت رائع!', $badge_name ),
                [ 'badge_key' => $badge_key ]
            );
        }

        self::flush_coach_stats_for_child( $child_id );
    }

    /* ═══════════════════════════════════════════════════════════════
       LEVEL-UP — notification
       ═══════════════════════════════════════════════════════════════ */

    public static function on_level_up( int $child_id, array $new_level, array $old_level ): void {
        if ( ! class_exists( 'RK_MC_Notification_Service' ) ) return;

        $label     = $new_level['label'] ?? '';
        $icon      = $new_level['icon']  ?? '⭐';
        $parent_id = self::get_parent_id( $child_id );

        if ( $parent_id > 0 ) {
            RK_MC_Notification_Service::push(
                $parent_id, 'level_up',
                sprintf( '%s مبروك! وصل طفلك للمستوى الجديد: %s', $icon, $label ),
                [ 'child_id' => $child_id, 'level' => $new_level['num'] ?? 0 ]
            );
        }

        $child_wp_uid = self::get_child_wp_user_id( $child_id );
        if ( $child_wp_uid > 0 ) {
            RK_MC_Notification_Service::push(
                $child_wp_uid, 'level_up',
                sprintf( '%s مبروك! وصلت للمستوى "%s"! استمر!', $icon, $label ),
                [ 'level' => $new_level['num'] ?? 0 ]
            );
        }
    }

    /* ═══════════════════════════════════════════════════════════════
       STREAK BADGES
       ═══════════════════════════════════════════════════════════════ */

    private static function maybe_award_streak_badges( int $child_id ): void {
        if ( ! class_exists( 'RK_MC_Badge_Service' ) ) return;
        if ( ! class_exists( 'RKP_CoachSessionRepository' ) ) return;

        // streak_week : 4 semaines consécutives avec au moins 1 présence
        $weeks = RKP_CoachSessionRepository::get_attendance_week_numbers( $child_id, 12 );

        if ( count( $weeks ) >= 4 ) {
            $consecutive = 0;
            $expected    = null;
            foreach ( $weeks as $yw ) {
                if ( $expected === null ) {
                    $expected    = $yw;
                    $consecutive = 1;
                } elseif ( $yw === $expected - 1 ) {
                    $consecutive++;
                    $expected--;
                } else {
                    break;
                }
            }
            if ( $consecutive >= 4 ) {
                RK_MC_Badge_Service::award( $child_id, 'streak_week' );
            }
        }

        // perfect_month : toutes les séances du mois courant = 'present'
        $month_start   = date( 'Y-m-01 00:00:00' );
        $month_end     = date( 'Y-m-t 23:59:59' );
        $total_month   = RKP_CoachSessionRepository::count_confirmed_in_range( $child_id, $month_start, $month_end );
        $present_month = RKP_CoachSessionRepository::count_present_in_range( $child_id, $month_start, $month_end );

        if ( $total_month >= 2 && $present_month === $total_month ) {
            RK_MC_Badge_Service::award( $child_id, 'perfect_month' );
        }
    }

    /* ═══════════════════════════════════════════════════════════════
       CRÉDITS WOOCOMMERCE
       ═══════════════════════════════════════════════════════════════ */

    /**
     * Déduit 1 crédit du parent de l'enfant.
     *
     * Retourne true  = succès OU solde nul (non-bloquant : l'XP est quand même accordé,
     *                  l'admin devra recharger le compte du parent).
     * Retourne false = erreur SQL réelle → la transaction appellante fera ROLLBACK.
     */
    private static function deduct_session_credit( int $child_id ): bool {
        global $wpdb;
        $parent_id = self::get_parent_id( $child_id );
        if ( ! $parent_id ) return true; // pas de parent lié → non-bloquant

        $credits_table = $wpdb->prefix . 'rk_user_credits';
        $has_table     = $wpdb->get_var(
            $wpdb->prepare( 'SHOW TABLES LIKE %s', $credits_table )
        ) === $credits_table;

        if ( $has_table ) {
            $balance = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT balance FROM {$credits_table} WHERE user_id = %d LIMIT 1",
                $parent_id
            ) );
            if ( $balance <= 0 ) {
                // Solde épuisé → log admin, mais ne bloque pas l'XP de l'enfant
                rkp_log( "[RK EventBus] deduct_session_credit: solde nul pour parent#{$parent_id} (child#{$child_id})" );
                return true;
            }
            $result = $wpdb->query( $wpdb->prepare(
                "UPDATE {$credits_table} SET balance = balance - 1 WHERE user_id = %d AND balance > 0",
                $parent_id
            ) );
            return $result !== false; // false = SQL error → ROLLBACK
        }

        // Fallback user_meta : best-effort, non-bloquant
        $current = (int) get_user_meta( $parent_id, 'rk_session_credits', true );
        if ( $current > 0 ) {
            update_user_meta( $parent_id, 'rk_session_credits', $current - 1 );
        }
        return true;
    }

    /* ═══════════════════════════════════════════════════════════════
       ALIMENTATION wp_rk_child_coaches
       ═══════════════════════════════════════════════════════════════ */

    private static function sync_child_coaches( int $booking_id, $ctx ): void {
        if ( ! class_exists( 'RKP_CoachStudentRepository' ) ) return;

        $ssa_type = (int) ( $ctx->event_id ?? 0 );
        $map      = get_option( RK_MC_Booking_Bridge::COACH_MAP_OPTION, [] );
        $coach_id = (int) ( $map[ $ssa_type ] ?? 0 );
        if ( $coach_id <= 0 ) return;

        foreach ( (array) ( $ctx->child_ids ?? [] ) as $child_id ) {
            $child_id = (int) $child_id;
            if ( $child_id <= 0 ) continue;

            // Idempotent : ignorer si la relation existe déjà
            if ( RKP_CoachStudentRepository::coach_owns_child( $coach_id, $child_id ) ) continue;

            // is_primary = 1 si premier coach pour cet enfant
            $is_primary = RKP_CoachStudentRepository::has_primary_coach( $child_id ) ? 0 : 1;
            RKP_CoachStudentRepository::insert_assignment( $child_id, $coach_id, $is_primary );
        }

        // v9.49 (13/08/2026) — Invalide le cache transient des séances
        // du coach (RK_Coach_Data::bust_for_coach()), sinon la nouvelle
        // réservation reste invisible sur /espace-coach/#sessions jusqu'à
        // expiration naturelle du transient (rk_ch_sess_*) — ce cache
        // n'était invalidé qu'après un changement de présence, jamais à
        // la création d'un booking. class_exists() : RK_Coach_Data vit
        // dans Modules/Coach, chargé indépendamment de ce fichier
        // (Modules/Children) selon l'ordre d'activation des modules.
        if ( class_exists( 'RK_Coach_Data' ) && method_exists( 'RK_Coach_Data', 'bust_for_coach' ) ) {
            RK_Coach_Data::bust_for_coach( $coach_id );
        }
    }

    /* ═══════════════════════════════════════════════════════════════
       NOTIFICATIONS SESSIONS
       ═══════════════════════════════════════════════════════════════ */

    private static function notify_parent_session_attended( int $child_id, int $booking_id ): void {
        if ( ! class_exists( 'RK_MC_Notification_Service' ) ) return;

        $parent_id = self::get_parent_id( $child_id );
        if ( ! $parent_id ) return;

        $session_raw = class_exists( 'RKP_CoachSessionRepository' )
            ? RKP_CoachSessionRepository::find_session_name( $booking_id )
            : '';
        $session = $session_raw ?: 'الجلسة';

        RK_MC_Notification_Service::push(
            $parent_id,
            'session_attended',
            sprintf( '✅ تم تسجيل حضور طفلك في جلسة "%s"', $session ),
            [ 'child_id' => $child_id, 'booking_id' => $booking_id ]
        );
    }

    /* ═══════════════════════════════════════════════════════════════
       CACHE STATS COACH
       ═══════════════════════════════════════════════════════════════ */

    private static function flush_coach_stats_for_child( int $child_id ): void {
        $coach_ids = class_exists( 'RKP_CoachStudentRepository' )
            ? RKP_CoachStudentRepository::get_coaches_for_child( $child_id )
            : [];
        foreach ( $coach_ids as $coach_id ) {
            delete_transient( 'rk_ch_stats_' . $coach_id . '_' . date( 'Ymd' ) );
        }
        if ( class_exists( 'RK_MC_Child_Dashboard_Data' ) ) {
            RK_MC_Child_Dashboard_Data::clear_cache( $child_id );
        }
    }

    /* ═══════════════════════════════════════════════════════════════
       HELPERS
       ═══════════════════════════════════════════════════════════════ */

    private static function get_parent_id( int $child_id ): int {
        return class_exists( 'RKP_ChildRepository' )
            ? RKP_ChildRepository::get_parent_user_id( $child_id )
            : (int) ( RK_MC_Child_Repository::find( $child_id )->user_id ?? 0 );
    }

    private static function get_child_wp_user_id( int $child_id ): int {
        return class_exists( 'RKP_ChildRepository' )
            ? RKP_ChildRepository::get_wp_user_id( $child_id )
            : (int) ( RK_MC_Child_Repository::find( $child_id )->wp_user_id ?? 0 );
    }

    /* ═══════════════════════════════════════════════════════════════
       ASSESSMENT CREATED — XP enfant + notification parent + enfant
       ═══════════════════════════════════════════════════════════════ */

    public static function on_assessment_created( int $assessment_id, int $child_id, int $coach_id = 0 ): void {
        // +5 XP à l'enfant (idempotence gérée par has_points_for_source_id)
        if ( class_exists( 'RKP_GamificationRepository' )
            && ! RKP_GamificationRepository::has_points_for_source_id( $child_id, $assessment_id, [ 'assessment' ] )
        ) {
            RKP_GamificationRepository::insert_points( $child_id, 5, 'assessment', $assessment_id );
            do_action( 'rk_mc_child_points_added', $child_id, 5, 'assessment' );
        }

        if ( ! class_exists( 'RK_MC_Notification_Service' ) ) return;

        // Notification parent
        $parent_id = self::get_parent_id( $child_id );
        if ( $parent_id > 0 ) {
            RK_MC_Notification_Service::push(
                $parent_id,
                'assessment_created',
                '📊 تم نشر تقييم جديد لطفلك من المدرب',
                [ 'child_id' => $child_id, 'assessment_id' => $assessment_id ]
            );
        }

        // Notification enfant (version simplifiée)
        $child_wp_uid = self::get_child_wp_user_id( $child_id );
        if ( $child_wp_uid > 0 ) {
            RK_MC_Notification_Service::push(
                $child_wp_uid,
                'assessment_created',
                '📊 لديك تقييم جديد من مدربك!',
                [ 'assessment_id' => $assessment_id ]
            );
        }

        self::flush_coach_stats_for_child( $child_id );
    }

    /* ═══════════════════════════════════════════════════════════════
       MISSION COMPLETED
       ═══════════════════════════════════════════════════════════════ */

    public static function on_mission_completed( int $child_id, int $mission_id ): void {
        if ( ! class_exists( 'RK_MC_Notification_Service' ) ) return;

        $mission = class_exists( 'RKP_MissionRepository' )
            ? RKP_MissionRepository::find_by_id( $mission_id )
            : null;
        $title = $mission ? sanitize_text_field( $mission->title ) : 'المهمة';

        $parent_id = self::get_parent_id( $child_id );
        if ( $parent_id > 0 ) {
            RK_MC_Notification_Service::push(
                $parent_id,
                'mission_completed',
                sprintf( '🎯 أنجز طفلك مهمة "%s"!', $title ),
                [ 'child_id' => $child_id, 'mission_id' => $mission_id ]
            );
        }

        $child_wp_uid = self::get_child_wp_user_id( $child_id );
        if ( $child_wp_uid > 0 ) {
            RK_MC_Notification_Service::push(
                $child_wp_uid,
                'mission_completed',
                sprintf( '🎯 أحسنت! أنجزت مهمة "%s"!', $title ),
                [ 'mission_id' => $mission_id ]
            );
        }
    }

    /* ═══════════════════════════════════════════════════════════════
       BOOKING CANCELLED — Sprint 7
       Déduction crédit si annulation parent < 24h avant la séance.
       ═══════════════════════════════════════════════════════════════ */

    public static function on_booking_cancelled( int $booking_id, $ctx ): void {
        if ( ! $ctx ) return;

        $parent_id    = (int) ( $ctx->user_id ?? 0 );
        $booking_dt   = $ctx->appointment_datetime ?? '';
        $cancelled_by = sanitize_key( $ctx->cancelled_by ?? '' );
        $hours_ahead  = $booking_dt
            ? ( strtotime( $booking_dt ) - time() ) / 3600
            : 999;

        // Déduction crédit : uniquement annulation parent dans < 24h
        if ( 'coach' !== $cancelled_by && 'admin' !== $cancelled_by
             && $hours_ahead >= 0 && $hours_ahead < 24 ) {
            foreach ( (array) ( $ctx->child_ids ?? [] ) as $cid ) {
                $cid = (int) $cid;
                if ( $cid > 0 ) {
                    self::deduct_session_credit( $cid );
                }
            }
        }

        // Notification parent
        if ( class_exists( 'RK_MC_Notification_Service' ) && $parent_id > 0 ) {
            $session_name = sanitize_text_field( $ctx->session_name ?? 'الجلسة' );
            RK_MC_Notification_Service::push(
                $parent_id,
                'booking_cancelled',
                sprintf( '❌ تم إلغاء الجلسة "%s"', $session_name ),
                [ 'booking_id' => $booking_id ]
            );
        }

        // Alerte crédit faible (≤2)
        if ( $parent_id > 0 ) {
            $credits = function_exists( 'rk_mc_get_session_credits' )
                ? rk_mc_get_session_credits( $parent_id )
                : 99;
            if ( $credits <= 2 ) {
                self::notify_low_credits( $parent_id, $credits );
            }
        }

        // Flush cache coach + dashboard (endpoint + transient Tutor)
        foreach ( (array) ( $ctx->child_ids ?? [] ) as $cid ) {
            self::flush_coach_stats_for_child( (int) $cid );
            do_action( 'rk_mc_bust_child_caches', (int) $cid );
        }
    }

    /* ─── Alerte crédit faible (partagée session_attended + annulation) */

    public static function notify_low_credits( int $parent_id, int $credits ): void {
        // Eviter le spam : max 1 alerte par 24h
        $transient = 'rk_low_credits_notif_' . $parent_id;
        if ( get_transient( $transient ) ) return;
        set_transient( $transient, 1, DAY_IN_SECONDS );

        if ( class_exists( 'RK_MC_Notification_Service' ) ) {
            RK_MC_Notification_Service::push(
                $parent_id,
                'low_credits',
                sprintf( '💳 تنبيه: رصيدك منخفض — %d جلسة متبقية فقط', $credits ),
                [ 'credits' => $credits ]
            );
        }

        // Envoi BM depuis admin
        if ( class_exists( 'Better_Messages' ) && class_exists( 'RK_MC_Message_Service' ) ) {
            $admin = RK_MC_Message_Service::get_admin_user();
            $admin_id = (int) $admin->ID;
            if ( $admin_id && $admin_id !== $parent_id ) {
                Better_Messages()->functions->new_message( [
                    'sender_id'    => $admin_id,
                    'recipients'   => [ $parent_id ],
                    'content'      => sprintf(
                        '💳 تنبيه: رصيدك منخفض (%d جلسة متبقية). احجز الآن لضمان استمرارية التدريب.',
                        $credits
                    ),
                    'send_push'    => true,
                    'count_unread' => true,
                    'show_on_site' => true,
                ] );
            }
        }
    }
}