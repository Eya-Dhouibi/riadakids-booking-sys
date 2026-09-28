<?php
declare( strict_types=1 );
/**
 * Infrastructure — QuizAttemptRepository  (Tutor LMS)
 *
 * SEULE couche autorisée à requêter la table {prefix}tutor_quiz_attempts.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class RKP_QuizAttemptRepository {

    /**
     * Meilleur score (%) pour un quiz et un enfant.
     * Retourne null si aucune tentative terminée.
     */
    public static function get_best_score( int $quiz_id, int $child_id ): ?int {
        global $wpdb;
        $table = $wpdb->prefix . 'tutor_quiz_attempts';
        if ( ! self::table_exists( $table ) ) return null;

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT earned_marks, total_marks
               FROM {$table}
              WHERE user_id        = %d
                AND quiz_id        = %d
                AND attempt_status = 'attempt_ended'
              ORDER BY earned_marks DESC
              LIMIT 1",
            $child_id, $quiz_id
        ) );

        if ( ! $row || (float) $row->total_marks <= 0 ) return null;
        return (int) round( (float) $row->earned_marks / (float) $row->total_marks * 100 );
    }

    /**
     * Nombre de quiz distincts complétés par un enfant dans un cours.
     */
    public static function count_completed_in_course( int $course_id, int $child_id ): int {
        global $wpdb;
        $table = $wpdb->prefix . 'tutor_quiz_attempts';
        if ( ! self::table_exists( $table ) ) return 0;

        $quiz_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT q.ID
               FROM {$wpdb->posts} q
               JOIN {$wpdb->posts} t ON t.ID = q.post_parent
              WHERE q.post_type   = 'tutor_quiz'
                AND q.post_status = 'publish'
                AND t.post_type   = 'topics'
                AND t.post_parent = %d",
            $course_id
        ) );

        if ( empty( $quiz_ids ) ) return 0;

        $ph = implode( ',', array_fill( 0, count( $quiz_ids ), '%d' ) );
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT( DISTINCT quiz_id )
               FROM {$table}
              WHERE user_id        = %d
                AND attempt_status = 'attempt_ended'
                AND quiz_id        IN ({$ph})",
            array_merge( [ $child_id ], array_map( 'intval', $quiz_ids ) )
        ) );
    }

    /**
     * Nombre de tentatives quiz en attente de correction manuelle (review_required)
     * pour tous les cours d'un instructeur.
     */
    public static function count_pending_review_for_coach( int $coach_wp_uid ): int {
        if ( $coach_wp_uid <= 0 ) return 0;
        global $wpdb;
        $table = $wpdb->prefix . 'tutor_quiz_attempts';
        if ( ! self::table_exists( $table ) ) return 0;

        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*)
               FROM {$table} a
               JOIN {$wpdb->posts} q ON q.ID = a.quiz_id AND q.post_type = 'tutor_quiz'
               JOIN {$wpdb->posts} t ON t.ID = q.post_parent AND t.post_type = 'topics'
               JOIN {$wpdb->posts} c ON c.ID = t.post_parent AND c.post_type = 'courses'
              WHERE c.post_author      = %d
                AND a.attempt_status   = 'review_required'",
            $coach_wp_uid
        ) );
    }

    /**
     * Tentatives récentes pour les cours d'un instructeur.
     * Format : [ quiz_id, quiz_title, child_name, score_pct, attempted_at, needs_review ]
     *
     * @return array[]
     */
    public static function get_recent_for_coach( int $coach_wp_uid, int $limit = 20 ): array {
        if ( $coach_wp_uid <= 0 ) return [];
        global $wpdb;
        $table = $wpdb->prefix . 'tutor_quiz_attempts';
        if ( ! self::table_exists( $table ) ) return [];

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT qa.quiz_id, qa.user_id, qa.total_marks, qa.earned_marks,
                    qa.attempt_status, qa.attempt_ended_at,
                    qp.post_title AS quiz_title
               FROM {$table} qa
               JOIN {$wpdb->posts} qp ON qp.ID = qa.quiz_id
               JOIN {$wpdb->posts} tp ON tp.ID = qp.post_parent AND tp.post_type = 'topics'
               JOIN {$wpdb->posts} cp ON cp.ID = tp.post_parent AND cp.post_type = 'courses'
              WHERE cp.post_author     = %d
                AND qa.attempt_status IN ('attempt_ended','review_required')
           ORDER BY qa.attempt_ended_at DESC
              LIMIT %d",
            $coach_wp_uid, $limit
        ) ) ?: [];

        $ct  = $wpdb->prefix . 'rk_children';
        $out = [];
        foreach ( $rows as $row ) {
            $child_name = (string) ( $wpdb->get_var( $wpdb->prepare(
                "SELECT child_name FROM {$ct} WHERE wp_user_id = %d LIMIT 1",
                (int) $row->user_id
            ) ) ?? '' );
            $total = (float) $row->total_marks;
            $out[] = [
                'quiz_id'      => (int) $row->quiz_id,
                'quiz_title'   => (string) ( $row->quiz_title ?? '' ),
                'child_name'   => $child_name,
                'score_pct'    => $total > 0 ? (int) round( (float) $row->earned_marks / $total * 100 ) : 0,
                'attempted_at' => (string) ( $row->attempt_ended_at ?? '' ),
                'needs_review' => $row->attempt_status === 'review_required',
            ];
        }
        return $out;
    }

    /**
     * Résultats de quiz d'un utilisateur WP (10 derniers, attempt_ended).
     *
     * @return array[]  [quiz_id, quiz_title, total_marks, earned_marks, score_pct, attempted_at]
     */
    public static function get_for_child( int $wp_user_id, int $limit = 10 ): array {
        if ( $wp_user_id <= 0 ) return [];
        global $wpdb;
        $table = $wpdb->prefix . 'tutor_quiz_attempts';
        if ( ! self::table_exists( $table ) ) return [];
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT qa.quiz_id, p.post_title AS quiz_title,
                    qa.total_marks, qa.earned_marks,
                    ROUND(qa.earned_marks / GREATEST(qa.total_marks,1) * 100) AS score_pct,
                    qa.attempt_ended_at AS attempted_at
               FROM {$table} qa
          LEFT JOIN {$wpdb->posts} p ON p.ID = qa.quiz_id
              WHERE qa.user_id = %d AND qa.attempt_status = 'attempt_ended'
           ORDER BY qa.attempt_ended_at DESC LIMIT %d",
            $wp_user_id, $limit
        ) ) ?: [];
        return array_map( 'get_object_vars', $rows );
    }

    /**
     * Retourne une tentative par son ID (toutes colonnes), ou null.
     */
    public static function find_attempt_by_id( int $attempt_id ): ?object {
        if ( $attempt_id <= 0 ) return null;
        global $wpdb;
        $table = $wpdb->prefix . 'tutor_quiz_attempts';
        if ( ! self::table_exists( $table ) ) return null;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE attempt_id = %d LIMIT 1",
            $attempt_id
        ) ) ?: null;
    }

    /**
     * Tentatives récentes pour une liste d'enfants (par wp_user_id).
     * Utilisé par get_tasks() dans l'API coach pour agréger les résultats quiz.
     *
     * @param  int[]  $child_wp_user_ids  wp_users.ID list
     * @return array[]  [attempt_id, quiz_id, user_id, earned_marks, total_marks, score_pct, attempt_ended_at, quiz_title]
     */
    public static function find_for_coach_children( array $child_wp_user_ids, int $limit = 100 ): array {
        if ( empty( $child_wp_user_ids ) ) return [];
        global $wpdb;
        $table = $wpdb->prefix . 'tutor_quiz_attempts';
        if ( ! self::table_exists( $table ) ) return [];
        $ph   = implode( ',', array_fill( 0, count( $child_wp_user_ids ), '%d' ) );
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT qa.attempt_id, qa.quiz_id, qa.user_id,
                    qa.earned_marks, qa.total_marks,
                    ROUND(qa.earned_marks / GREATEST(qa.total_marks,1) * 100) AS score_pct,
                    qa.attempt_ended_at, qa.attempt_status,
                    p.post_title AS quiz_title
               FROM {$table} qa
          LEFT JOIN {$wpdb->posts} p ON p.ID = qa.quiz_id
              WHERE qa.user_id IN ({$ph})
                AND qa.attempt_status IN ('attempt_ended','review_required')
           ORDER BY qa.attempt_ended_at DESC
              LIMIT %d",
            ...array_merge( $child_wp_user_ids, [ $limit ] )
        ) ) ?: [];
        return array_map( 'get_object_vars', $rows );
    }

    /** Cache statique pour éviter de répéter SHOW TABLES. */
    private static function table_exists( string $table ): bool {
        global $wpdb;
        static $cache = [];
        if ( ! isset( $cache[ $table ] ) ) {
            $cache[ $table ] = $wpdb->get_var(
                $wpdb->prepare( 'SHOW TABLES LIKE %s', $table )
            ) === $table;
        }
        return $cache[ $table ];
    }
}
