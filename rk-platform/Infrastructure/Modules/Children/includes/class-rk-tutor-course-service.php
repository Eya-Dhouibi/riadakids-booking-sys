<?php
declare( strict_types=1 );
/**
 * RK_Tutor_Course_Service  (v2.0.0 — Adaptateur)
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * ADAPTATEUR TEMPORAIRE vers RKP_LearningQueryService.
 *
 * Cette classe conserve son interface publique à l'identique (aucun appelant
 * existant n'a besoin d'être modifié). Son implémentation délègue entièrement
 * à la couche Application de rk-platform.
 *
 * Migration :
 *   v1.0.0 — implémentation directe (WP_Query / $wpdb / tutor_utils).
 *   v2.0.0 — adaptateur : délègue à RKP_LearningQueryService + Repositories.
 *
 * À supprimer quand tous les appelants utilisent directement
 * RKP_LearningQueryService.
 *
 * FORMAT DE RETOUR (inchangé depuis v1.0.0) :
 *   get_enrolled_courses() / get_course_detail() retournent un tableau :
 *   [
 *     'id', 'title', 'permalink', 'thumbnail', 'category',
 *     'instructor_id', 'instructor_name',
 *     'progress',
 *     'topics_count', 'lessons_total', 'lessons_done',
 *     'quizzes_count', 'quizzes_done',
 *     'next_lesson' => ['id','title','url'] | null,
 *     'state'       => 'new'|'in-progress'|'almost'|'completed',
 *   ]
 *
 * @package RK_My_Children
 * @since   2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class RK_Tutor_Course_Service {

    /* ═══════════════════════════════════════════════════════════════════
       COURSES
       ═══════════════════════════════════════════════════════════════════ */

    /**
     * Cours auxquels l'utilisateur est inscrit, enrichis des métadonnées.
     *
     * @param  int   $uid  wp_user_id de l'enfant
     * @return array       Tableau de course arrays.
     */
    public static function get_enrolled_courses( int $uid ): array {
        if ( $uid <= 0 || ! class_exists( 'RKP_LearningQueryService' ) ) {
            return [];
        }

        $courses = RKP_LearningQueryService::get_courses_for_child( $uid );
        $out     = [];
        foreach ( $courses as $course ) {
            $detail = self::get_course_detail( $course->id, $uid );
            if ( $detail ) $out[] = $detail;
        }
        return $out;
    }

    /**
     * Données complètes d'un cours pour un utilisateur donné.
     *
     * @param  int        $course_id
     * @param  int        $uid        wp_user_id
     * @return array|null
     */
    public static function get_course_detail( int $course_id, int $uid ): ?array {
        if ( $course_id <= 0 || ! class_exists( 'RKP_LearningQueryService' ) ) return null;

        $course = RKP_LearningQueryService::get_course( $course_id );
        if ( ! $course ) return null;

        $progress     = RKP_LearningQueryService::get_progress( $course_id, $uid );
        $pct          = $progress ? $progress->percent : 0;
        $next_rk      = RKP_ProgressRepository::get_next_lesson( $course_id, $uid );
        $instructor   = RKP_LearningQueryService::get_instructor( $course_id );
        $category     = RKP_LearningQueryService::get_primary_category( $course_id );
        $quizzes_done = RKP_LearningQueryService::count_completed_quizzes( $course_id, $uid );
        $quizzes_total = RKP_LearningQueryService::count_quizzes( $course_id );

        $state = 'new';
        if ( $pct >= 100 )     $state = 'completed';
        elseif ( $pct >= 70 )  $state = 'almost';
        elseif ( $pct > 0 )    $state = 'in-progress';

        return [
            'id'              => $course->id,
            'title'           => $course->title,
            'permalink'       => $course->permalink,
            'thumbnail'       => $course->thumbnail,
            'category'        => $category,
            'instructor_id'   => $instructor ? (int) $instructor->ID          : 0,
            'instructor_name' => $instructor ? (string) $instructor->display_name : '',
            'progress'        => $pct,
            'topics_count'    => RKP_LearningQueryService::count_topics( $course_id ),
            'lessons_total'   => $progress ? $progress->lessons_total : $course->lessons_total,
            'lessons_done'    => $progress ? $progress->lessons_done  : 0,
            'quizzes_count'   => $quizzes_total,
            'quizzes_done'    => $quizzes_done,
            'next_lesson'     => $next_rk ? [
                'id'    => $next_rk->id,
                'title' => $next_rk->title,
                'url'   => $next_rk->permalink,
            ] : null,
            'state'           => $state,
        ];
    }

    /* ═══════════════════════════════════════════════════════════════════
       PROGRESS
       ═══════════════════════════════════════════════════════════════════ */

    public static function get_progress( int $course_id, int $uid ): int {
        if ( ! class_exists( 'RKP_LearningQueryService' ) ) return 0;
        $p = RKP_LearningQueryService::get_progress( $course_id, $uid );
        return $p ? $p->percent : 0;
    }

    public static function is_enrolled( int $course_id, int $uid ): bool {
        if ( ! class_exists( 'RKP_LearningQueryService' ) ) return false;
        return RKP_LearningQueryService::is_enrolled( $course_id, $uid );
    }

    /* ═══════════════════════════════════════════════════════════════════
       STRUCTURE — Topics / Lessons / Quiz
       ═══════════════════════════════════════════════════════════════════ */

    /**
     * IDs des topics d'un cours (ordre menu_order ASC).
     *
     * @return int[]
     */
    public static function get_topic_ids( int $course_id ): array {
        if ( ! class_exists( 'RKP_TopicRepository' ) ) return [];
        return array_map(
            fn( RKP_Topic $t ) => $t->id,
            RKP_TopicRepository::find_by_course( $course_id )
        );
    }

    public static function count_topics( int $course_id ): int {
        if ( ! class_exists( 'RKP_LearningQueryService' ) ) return 0;
        return RKP_LearningQueryService::count_topics( $course_id );
    }

    public static function count_lessons( int $course_id ): int {
        if ( ! class_exists( 'RKP_ProgressRepository' ) ) return 0;
        return RKP_ProgressRepository::get_lesson_count( $course_id );
    }

    public static function count_quizzes( int $course_id ): int {
        if ( ! class_exists( 'RKP_LearningQueryService' ) ) return 0;
        return RKP_LearningQueryService::count_quizzes( $course_id );
    }

    public static function count_completed_quizzes( int $course_id, int $uid ): int {
        if ( ! class_exists( 'RKP_LearningQueryService' ) ) return 0;
        return RKP_LearningQueryService::count_completed_quizzes( $course_id, $uid );
    }

    /**
     * Prochaine leçon non complétée.
     *
     * @return array|null  ['id','title','url'] ou null.
     */
    public static function get_next_lesson( int $course_id, int $uid ): ?array {
        if ( ! class_exists( 'RKP_ProgressRepository' ) ) return null;
        $lesson = RKP_ProgressRepository::get_next_lesson( $course_id, $uid );
        if ( ! $lesson ) return null;
        return [ 'id' => $lesson->id, 'title' => $lesson->title, 'url' => $lesson->permalink ];
    }

    /* ═══════════════════════════════════════════════════════════════════
       QUIZ
       ═══════════════════════════════════════════════════════════════════ */

    /**
     * Quiz d'un cours avec l'état de tentative de l'utilisateur.
     *
     * @return array  [ ['id','title','url','best_pct','attempted','course_id','course_name'] ]
     */
    public static function get_quizzes_for_course( int $course_id, int $uid ): array {
        if ( ! class_exists( 'RKP_LearningQueryService' ) ) return [];
        return RKP_LearningQueryService::get_quizzes_with_attempts( $course_id, $uid );
    }

    /**
     * TOUS les quiz de l'utilisateur (tous cours confondus, 30 max).
     *
     * @return array
     */
    public static function get_all_quizzes_for_user( int $uid ): array {
        if ( $uid <= 0 || ! class_exists( 'RKP_LearningQueryService' ) ) return [];
        return RKP_LearningQueryService::get_all_quizzes_for_child( $uid );
    }

    /* ═══════════════════════════════════════════════════════════════════
       INSTRUCTEUR / COACH
       ═══════════════════════════════════════════════════════════════════ */

    /**
     * Données de l'instructeur du cours.
     *
     * @return array|null  ['id','name','avatar'] ou null.
     */
    public static function get_instructor( int $course_id ): ?array {
        if ( ! class_exists( 'RKP_LearningQueryService' ) ) return null;
        $user = RKP_LearningQueryService::get_instructor( $course_id );
        if ( ! $user ) return null;
        return [
            'id'     => (int) $user->ID,
            'name'   => (string) $user->display_name,
            'avatar' => (string) get_avatar_url( $user->ID, [ 'size' => 48 ] ),
        ];
    }

    /* ═══════════════════════════════════════════════════════════════════
       CATÉGORIE
       ═══════════════════════════════════════════════════════════════════ */

    public static function get_primary_category( int $course_id ): string {
        if ( ! class_exists( 'RKP_LearningQueryService' ) ) return '';
        return RKP_LearningQueryService::get_primary_category( $course_id );
    }

    /* ═══════════════════════════════════════════════════════════════════
       HERO
       ═══════════════════════════════════════════════════════════════════ */

    /**
     * Premier cours non terminé pour le bouton "متابعة البرنامج".
     *
     * @return array  ['id','title','url','progress'] ou tableau vide.
     */
    public static function get_next_course_to_continue( int $uid ): array {
        if ( ! class_exists( 'RKP_LearningQueryService' ) ) return [];
        $course = RKP_LearningQueryService::get_next_course_to_continue( $uid );
        if ( ! $course ) return [];
        $p = RKP_LearningQueryService::get_progress( $course->id, $uid );
        return [
            'id'       => $course->id,
            'title'    => $course->title,
            'url'      => $course->permalink,
            'progress' => $p ? $p->percent : 0,
        ];
    }

    /* ═══════════════════════════════════════════════════════════════════
       STATS GLOBALES
       ═══════════════════════════════════════════════════════════════════ */

    /**
     * Résumé statistique global d'un enfant.
     *
     * @return array  courses_total, courses_completed, courses_active,
     *                lessons_total, lessons_done,
     *                quizzes_total, quizzes_done,
     *                avg_progress
     */
    public static function get_stats( int $uid ): array {
        if ( ! class_exists( 'RKP_LearningQueryService' ) ) {
            return [
                'courses_total' => 0, 'courses_completed' => 0, 'courses_active' => 0,
                'lessons_total' => 0, 'lessons_done'      => 0,
                'quizzes_total' => 0, 'quizzes_done'      => 0,
                'avg_progress'  => 0,
            ];
        }
        return RKP_LearningQueryService::get_stats_for_child( $uid );
    }
}
