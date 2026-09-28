<?php
declare( strict_types=1 );
/**
 * Application — CoachDataQueryService  (Read Model)
 *
 * Données opérationnelles coach : élèves, séances, statistiques globales.
 *
 * RÈGLE : aucun appel direct à $wpdb — tout passe par les Repositories.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class RKP_CoachDataQueryService {

    // ── Élèves ──────────────────────────────────────────────────────

    /**
     * Liste des élèves d'un coach.
     * Délègue à CoachStudentRepository (table wp_rk_child_coaches).
     *
     * @return object[]  Objets {child_id, child_name, wp_user_id, …}
     */
    public static function get_students( int $coach_id ): array {
        return RKP_CoachStudentRepository::find_children_for_coach( $coach_id );
    }

    /**
     * Vérifie qu'un enfant (child_rk_id) appartient aux élèves d'un coach.
     */
    public static function coach_owns_child( int $coach_id, int $child_rk_id ): bool {
        return RKP_CoachStudentRepository::coach_owns_child( $coach_id, $child_rk_id );
    }

    // ── Séances ──────────────────────────────────────────────────────

    /**
     * Séances dans une plage nommée : 'today' | 'tomorrow' | 'week' | 'month'.
     *
     * @return array[]  Tableaux associatifs (booking_id, child_id, start, program_name, …)
     */
    public static function get_sessions_by_range( int $coach_id, string $range ): array {
        [$start, $end] = self::range_to_dates( $range );
        return RKP_CoachSessionRepository::find_by_range( $coach_id, $start, $end );
    }

    /** Prochaine séance dans les $minutes minutes. */
    public static function get_next_session_soon( int $coach_id, int $minutes ): ?array {
        $limit    = time() + $minutes * 60;
        foreach ( self::get_sessions_by_range( $coach_id, 'today' ) as $s ) {
            $ts = strtotime( $s['start'] ?? '' );
            if ( $ts && $ts > time() && $ts <= $limit ) return $s;
        }
        return null;
    }

    // ── Statistiques ─────────────────────────────────────────────────

    /**
     * KPIs opérationnels du coach :
     *   total_students, sessions_today, sessions_week, avg_rating,
     *   active_courses, avg_progress, pending_quizzes.
     */
    public static function get_coach_stats( int $coach_id ): array {
        $today_dates = self::range_to_dates( 'today' );
        $week_dates  = self::range_to_dates( 'week' );
        $month_start = date( 'Y-m-01 00:00:00' );
        $month_end   = date( 'Y-m-t 23:59:59' );

        $total_students  = RKP_CoachSessionRepository::count_distinct_students( $coach_id );
        $sessions_today  = RKP_CoachSessionRepository::count_in_range( $coach_id, ...$today_dates );
        $sessions_week   = RKP_CoachSessionRepository::count_in_range( $coach_id, ...$week_dates );
        $sessions_month  = RKP_CoachSessionRepository::count_in_range( $coach_id, $month_start, $month_end );
        $avg_rating      = RKP_AssessmentRepository::get_avg_rating_for_coach( $coach_id );

        // Données LMS via CoachQueryService (déjà migré en Phase 6)
        $lms = class_exists( 'RKP_CoachQueryService' )
            ? RKP_CoachQueryService::get_global_stats( $coach_id )
            : [ 'active_courses' => 0, 'avg_progress' => 0, 'pending_quizzes' => 0, 'total_students' => 0 ];

        return [
            'total_students'  => $total_students,
            'sessions_today'  => $sessions_today,
            'sessions_week'   => $sessions_week,
            'sessions_month'  => $sessions_month,
            'avg_rating'      => round( (float) $avg_rating, 1 ),
            'active_courses'  => $lms['active_courses'],
            'avg_progress'    => $lms['avg_progress'],
            'pending_quizzes' => $lms['pending_quizzes'],
        ];
    }

    // ── Relations enfant ─────────────────────────────────────────────

    /** Retourne le WP user_id du parent d'un enfant (child_rk_id). */
    public static function get_parent_of_child( int $child_rk_id ): int {
        return RKP_ChildRepository::get_parent_user_id( $child_rk_id );
    }

    /** Retourne le WP user_id du compte enfant (distinct du parent). */
    public static function get_child_wp_user_id( int $child_rk_id ): int {
        return RKP_ChildRepository::get_wp_user_id( $child_rk_id );
    }

    // ── Données par enfant ───────────────────────────────────────────

    /** Données brutes rk_children pour un enfant. */
    public static function get_child_detail( int $child_rk_id ): ?object {
        return RKP_CoachStudentRepository::find_child_by_id( $child_rk_id );
    }

    /** Séances passées et futures d'un enfant (sans filtre coach). */
    public static function get_child_sessions( int $child_rk_id, int $limit = 10 ): array {
        return RKP_CoachSessionRepository::find_for_child( $child_rk_id, $limit );
    }

    /** Séances d'un enfant vérifiées comme appartenant au coach. */
    public static function get_child_sessions_for_coach( int $child_rk_id, int $coach_id, int $limit = 20 ): array {
        return RKP_CoachSessionRepository::find_for_child_owned_by_coach( $child_rk_id, $coach_id, $limit );
    }

    /** Résultats de quiz d'un enfant (via wp_user_id). */
    public static function get_child_quiz_results( int $child_rk_id, int $limit = 10 ): array {
        $wp_uid = self::get_child_wp_user_id( $child_rk_id );
        if ( ! $wp_uid ) return [];
        return RKP_QuizAttemptRepository::get_for_child( $wp_uid, $limit );
    }

    /**
     * Streak (jours consécutifs d'activité) d'un enfant basé sur rk_child_points.
     */
    public static function get_child_streak( int $child_rk_id ): int {
        $dates = RKP_GamificationRepository::get_activity_dates( $child_rk_id, 30 );
        if ( empty( $dates ) ) return 0;
        $streak = 0;
        $prev   = null;
        $today  = date( 'Y-m-d' );
        $yest   = date( 'Y-m-d', strtotime( '-1 day' ) );
        foreach ( $dates as $date ) {
            if ( $prev === null ) {
                if ( $date === $today || $date === $yest ) { $streak++; $prev = $date; }
                else break;
            } else {
                if ( $date === date( 'Y-m-d', strtotime( $prev . ' -1 day' ) ) ) { $streak++; $prev = $date; }
                else break;
            }
        }
        return $streak;
    }

    /**
     * Gamification d'un enfant : points, niveau, badges, streak.
     */
    public static function get_child_gamification( int $child_rk_id ): array {
        $total  = RKP_GamificationQueryService::get_total_points( $child_rk_id );
        $level  = RKP_GamificationQueryService::compute_level( $total );
        $badges = RKP_BadgeQueryService::get_badges( $child_rk_id );
        return [
            'total_points' => $total,
            'level_num'    => $level['num']   ?? 1,
            'level_label'  => $level['label'] ?? '',
            'badges_count' => count( $badges ),
            'streak'       => self::get_child_streak( $child_rk_id ),
        ];
    }

    /**
     * Bilans pédagogiques d'un enfant (pour le coach — version complète).
     */
    public static function get_child_assessments( int $child_rk_id, int $limit = 5 ): array {
        return RKP_AssessmentRepository::find_all_for_child( $child_rk_id, $limit, 0 );
    }

    // ── Stats avancées ───────────────────────────────────────────────

    /**
     * Nombre d'enfants sans évaluation récente parmi ceux ayant eu une séance
     * dans les $days_back derniers jours.
     */
    public static function get_pending_evals_count( int $coach_id, int $days_back = 30 ): int {
        $child_rk_ids = RKP_CoachSessionRepository::find_children_with_past_sessions( $coach_id, $days_back );
        if ( empty( $child_rk_ids ) ) return 0;
        $unique_ids = array_values( array_unique( $child_rk_ids ) );
        $last_evals = RKP_AssessmentRepository::find_last_assessed_at_batch_for_coach( $unique_ids, $coach_id );
        $cutoff     = strtotime( '-14 days' );
        $count      = 0;
        foreach ( $unique_ids as $child_rk_id ) {
            $last = $last_evals[ (int) $child_rk_id ] ?? null;
            if ( ! $last || strtotime( $last ) < $cutoff ) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Taux de présence d'un enfant pour ce coach.
     */
    public static function get_child_attendance_rate( int $child_rk_id, int $coach_id ): int {
        return RKP_CoachSessionRepository::get_child_attendance_rate( $child_rk_id, $coach_id );
    }

    /**
     * Nombre d'absences consécutives d'un enfant.
     */
    public static function get_consecutive_absences( int $child_rk_id ): int {
        return RKP_CoachSessionRepository::get_consecutive_absences( $child_rk_id );
    }

    /**
     * Enfants du coach enrichis du prochain booking (batch — 2 requêtes).
     *
     * @return array[]  [child_id, child_name, …, next_appointment, next_session_name, …]
     */
    public static function get_children_with_next_booking( int $coach_id ): array {
        $students = self::get_students( $coach_id );
        if ( empty( $students ) ) return [];

        $child_rk_ids = array_map( fn( $s ) => (int) $s->child_id, $students );
        $rows         = RKP_CoachStudentRepository::find_children_basic_by_ids( $child_rk_ids );
        $bk_map       = RKP_CoachSessionRepository::find_children_next_booking( $child_rk_ids );

        $result = [];
        foreach ( $rows as $row ) {
            $cid  = (int) $row->child_id;
            $bk   = $bk_map[ $cid ] ?? null;
            $result[] = [
                'child_id'          => $cid,
                'child_name'        => (string) ( $row->child_name  ?? '' ),
                'child_age'         => (string) ( $row->child_age   ?? '' ),
                'avatar_url'        => (string) ( $row->avatar_url  ?? '' ),
                'next_session_name' => $bk ? (string) $bk->session_name : '',
                'next_appointment'  => $bk ? (string) $bk->appointment  : '',
                'next_end_at'       => $bk ? (string) $bk->end_at       : '',
                'next_status'       => $bk ? (string) $bk->status       : '',
                'next_coach'        => $bk ? (string) $bk->coach        : '',
                'next_course_id'    => $bk ? (int)    $bk->course_id    : 0,
                'next_program_id'   => $bk ? (int)    $bk->program_id   : 0,
            ];
        }
        return $result;
    }

    /**
     * Statistiques mensuelles d'un coach :
     *   sessions, attendance_rate, evals_written, parent_messages.
     */
    public static function get_monthly_stats( int $coach_id, int $year, int $month ): array {
        $start = sprintf( '%04d-%02d-01 00:00:00', $year, $month );
        $end   = date( 'Y-m-d 23:59:59', mktime( 0, 0, 0, $month + 1, 0, $year ) );

        $raw   = RKP_CoachSessionRepository::get_monthly_raw( $coach_id, $start, $end );
        $evals = RKP_AssessmentRepository::count_for_coach_in_range( $coach_id, $start, $end );

        // v2.1 : Better Messages est encapsulé — plus de SQL direct dans Application.
        $msgs = RKP_MessageRepository::count_sent_between( $coach_id, $start, $end );

        // Taux basé sur les sessions POINTÉES uniquement (present/absent/late) —
        // les sessions futures non pointées ne diluent plus le pourcentage.
        $recorded = (int) ( $raw['recorded'] ?? 0 );

        return [
            'sessions'        => $raw['sessions'],
            'attendance_rate' => $recorded > 0
                ? (int) round( $raw['present'] / $recorded * 100 )
                : 0,
            'evals_written'   => $evals,
            'parent_messages' => $msgs,
        ];
    }

    // ── Helper privé ─────────────────────────────────────────────────

    /** @return array{0: string, 1: string}  [start_datetime, end_datetime] */
    private static function range_to_dates( string $range ): array {
        $now = current_time( 'timestamp' );
        switch ( $range ) {
            case 'tomorrow':
                return [
                    date( 'Y-m-d 00:00:00', strtotime( '+1 day', $now ) ),
                    date( 'Y-m-d 23:59:59', strtotime( '+1 day', $now ) ),
                ];
            case 'week':
                // Fenêtre glissante 7 jours — démarre au début du jour courant
                // pour inclure les sessions d'aujourd'hui déjà passées.
                return [
                    date( 'Y-m-d 00:00:00', $now ),
                    date( 'Y-m-d 23:59:59', strtotime( '+7 days', $now ) ),
                ];
            case 'month':
                // Fenêtre glissante 30 jours — démarre au début du jour courant.
                return [
                    date( 'Y-m-d 00:00:00', $now ),
                    date( 'Y-m-d 23:59:59', strtotime( '+30 days', $now ) ),
                ];
            default: // today
                return [
                    date( 'Y-m-d 00:00:00', $now ),
                    date( 'Y-m-d 23:59:59', $now ),
                ];
        }
    }
}