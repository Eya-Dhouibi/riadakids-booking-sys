<?php
declare( strict_types=1 );
/**
 * Application — LearningQueryService  (Read Model)
 *
 * Source de vérité unique pour les données pédagogiques.
 * Répond à : "Qu'est-ce que Tutor LMS contient pour cet utilisateur ?"
 *
 * RÈGLE : aucun appel direct à WP_Query, get_post_meta, get_posts,
 *         tutor_utils() ou $wpdb — tout passe par les Repositories.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class RKP_LearningQueryService {

    // ── Cours ──────────────────────────────────────────────────────────────

    /** @return RKP_Course[] — cours actifs auxquels un enfant est inscrit */
    public static function get_courses_for_child( int $child_id ): array {
        $enrollments = RKP_EnrollmentRepository::find_by_child( $child_id );
        $active_ids  = [];
        foreach ( $enrollments as $enrollment ) {
            if ( $enrollment->is_active() ) {
                $active_ids[] = $enrollment->course_id;
            }
        }
        if ( empty( $active_ids ) ) return [];
        $map = RKP_CourseRepository::find_batch( $active_ids );
        return array_values( array_filter(
            array_map( fn( $id ) => $map[ $id ] ?? null, $active_ids )
        ) );
    }

    /** @return RKP_Course[] — cours d'un instructeur */
    public static function get_courses_for_coach( int $coach_wp_uid ): array {
        return RKP_CourseRepository::find_by_instructor( $coach_wp_uid );
    }

    public static function get_course( int $course_id ): ?RKP_Course {
        return RKP_CourseRepository::find( $course_id );
    }

    // ── Structure ──────────────────────────────────────────────────────────

    /** @return RKP_Topic[] */
    public static function get_topics( int $course_id ): array {
        return RKP_TopicRepository::find_by_course( $course_id );
    }

    /**
     * Version BATCH de get_topics() — pour N cours en une requête.
     * @param int[] $course_ids
     * @return array<int, RKP_Topic[]> [course_id => topics[]]
     */
    public static function get_topics_batch( array $course_ids ): array {
        return RKP_TopicRepository::find_by_courses_batch( $course_ids );
    }

    /** @return RKP_Lesson[] */
    public static function get_lessons( int $topic_id ): array {
        return RKP_LessonRepository::find_by_topic( $topic_id );
    }

    /**
     * Version BATCH de get_lessons() — pour N topics en une requête.
     * @param int[]          $topic_ids
     * @param array<int,int> $course_id_by_topic [topic_id => course_id]
     * @return array<int, RKP_Lesson[]> [topic_id => lessons[]]
     */
    public static function get_lessons_batch( array $topic_ids, array $course_id_by_topic ): array {
        return RKP_LessonRepository::find_by_topics_batch( $topic_ids, $course_id_by_topic );
    }

    /* ── Environnement Tutor (v2.5 — Audit P3-10) ──────────────────────────
       Capacités utilisées par les templates, encapsulées via TutorEnvironment.
       AUCUN template ne parle plus a Tutor directement. */

    /** URL d'une page du dashboard (ex. 'rk-fiche-eleve'). */
    public static function get_dashboard_url( string $page = '' ): string {
        return RKP_TutorEnvironment::dashboard_url( $page );
    }

    /** @return int[] IDs des cours où l'enfant est inscrit. */
    public static function get_enrolled_course_ids( int $child_id ): array {
        return RKP_TutorEnvironment::enrolled_course_ids( $child_id );
    }

    /** @return int[] IDs des cours complétés. */
    public static function get_completed_course_ids( int $child_id ): array {
        return RKP_TutorEnvironment::completed_course_ids( $child_id );
    }

    /**
     * v9.34 — Leçons NON complétées des cours où l'enfant est inscrit,
     * groupées par cours — alimente le menu déroulant "leçon" du
     * formulaire coach "marquer présence" (aucune correspondance fixe
     * séance→cours, le coach choisit à chaque fois — décision du
     * 12/08/2026). Les leçons déjà complétées sont exclues : rien à
     * valider une seconde fois pour cet enfant.
     *
     * @return array<int, array{course_title:string, lessons: RKP_Lesson[]}>
     *         indexé par course_id, dans l'ordre d'inscription.
     */
    /**
     * v9.35 — Simplifié : le cours est déjà fixé au moment du booking
     * (colonne course_id sur wp_rk_bookings, choisie par le parent lors
     * de la réservation) — décision explicite de l'utilisateur
     * (12/08/2026) de ne plus proposer un choix de cours au coach, DONC
     * plus besoin de parcourir tous les cours où l'enfant est inscrit :
     * uniquement les leçons NON complétées du cours de CETTE séance.
     *
     * @return RKP_Lesson[]
     */
    public static function get_pending_lessons_for_course( int $course_id, int $wp_user_id ): array {
        if ( $course_id <= 0 ) return [];

        $topics = self::get_topics( $course_id );
        if ( empty( $topics ) ) return [];

        $topic_ids          = array_map( static fn( RKP_Topic $t ) => $t->id, $topics );
        $course_id_by_topic = array_fill_keys( $topic_ids, $course_id );
        $lessons_by_topic   = self::get_lessons_batch( $topic_ids, $course_id_by_topic );

        $completed_ids = $wp_user_id > 0
            ? array_flip( RKP_ProgressRepository::get_completed_lesson_ids( $wp_user_id ) )
            : [];

        $pending = [];
        foreach ( $topics as $topic ) {
            foreach ( $lessons_by_topic[ $topic->id ] ?? [] as $lesson ) {
                if ( ! isset( $completed_ids[ $lesson->id ] ) ) {
                    $pending[] = $lesson;
                }
            }
        }

        return $pending;
    }

    public static function is_course_completed( int $course_id, int $child_id ): bool {
        return RKP_TutorEnvironment::is_course_completed( $course_id, $child_id );
    }

    /** Enregistrement de complétion (objet avec date), ou null. */
    public static function get_course_completion( int $course_id, int $child_id ): ?object {
        return RKP_TutorEnvironment::course_completion( $course_id, $child_id );
    }

    /** Pourcentage de complétion 0-100. */
    public static function get_completed_percent( int $course_id, int $child_id ): int {
        return RKP_TutorEnvironment::completed_percent( $course_id, $child_id );
    }

    /** @return RKP_Quiz[] */
    public static function get_quizzes( int $course_id ): array {
        return RKP_QuizRepository::find_by_course( $course_id );
    }

    /** Nombre de topics publiés dans un cours. */
    public static function count_topics( int $course_id ): int {
        return RKP_TopicRepository::count_by_course( $course_id );
    }

    /** Nombre de quiz publiés dans un cours. */
    public static function count_quizzes( int $course_id ): int {
        return RKP_QuizRepository::count_by_course( $course_id );
    }

    /** Catégorie principale d'un cours. */
    public static function get_primary_category( int $course_id ): string {
        return RKP_CategoryRepository::get_primary_for_course( $course_id );
    }

    // ── Progress ───────────────────────────────────────────────────────────

    public static function get_progress( int $course_id, int $child_id ): ?RKP_Progress {
        $percent       = RKP_ProgressRepository::get_percent( $course_id, $child_id );
        $lessons_total = RKP_ProgressRepository::get_lesson_count( $course_id );
        $lessons_done  = RKP_ProgressRepository::get_completed_lesson_count( $course_id, $child_id );

        return new RKP_Progress(
            child_id:      $child_id,
            course_id:     $course_id,
            percent:       min( 100, max( 0, $percent ) ),
            lessons_done:  $lessons_done,
            lessons_total: $lessons_total,
            quizzes_done:  0, // Phase 4
            quizzes_total: 0, // Phase 4
        );
    }

    /**
     * Version BATCH — pourcentage de complétion de N cours en 2 requêtes
     * SQL groupées (voir RKP_ProgressRepository::get_percent_batch()),
     * au lieu d'appeler get_progress() en boucle (N×3 requêtes/appels
     * tutor_utils()). Ajoutée pour la vue شارات المغامرات (aventures =
     * tous les cours de la plateforme) — get_progress() reste inchangée
     * pour tous ses appelants existants (enrolled-courses.php, etc.).
     *
     * @param int[] $course_ids
     * @return array<int,int> [course_id => percent 0-100]
     */
    public static function get_percent_batch( array $course_ids, int $wp_user_id ): array {
        return RKP_ProgressRepository::get_percent_batch( $course_ids, $wp_user_id );
    }

    /** Nombre de quiz complétés par un enfant dans un cours. */
    public static function count_completed_quizzes( int $course_id, int $child_id ): int {
        return RKP_QuizAttemptRepository::count_completed_in_course( $course_id, $child_id );
    }

    /** Une leçon donnée est-elle complétée par cet enfant ? */
    public static function is_lesson_completed( int $lesson_id, int $child_id ): bool {
        return RKP_ProgressRepository::is_lesson_completed( $lesson_id, $child_id );
    }

    /**
     * Version BATCH — tous les IDs de leçons complétées par un enfant,
     * en une requête. À utiliser avec in_array() plutôt que
     * is_lesson_completed() dans une boucle sur de nombreuses leçons.
     *
     * @return int[]
     */
    public static function get_completed_lesson_ids( int $wp_user_id ): array {
        return RKP_ProgressRepository::get_completed_lesson_ids( $wp_user_id );
    }

    // ── Quiz avec tentatives (view model) ──────────────────────────────────

    /**
     * Quiz d'un cours enrichis des données de tentative.
     * View model utilisé par les dashboards — non un Domain object.
     *
     * v2.7 — combine DEUX sources distinctes plutôt qu'une seule :
     * - Quiz Tutor LMS natifs (CPT tutor_quiz), via RKP_QuizRepository —
     *   fonctionnait déjà, conservé pour compatibilité si de tels quiz
     *   existent un jour dans un cours.
     * - Quiz AYS Quiz Maker créés par le coach et liés au cours via
     *   'rk_course_id' (voir class-rk-coach-quizzes-controller.php →
     *   create_quiz(), v2.7) — c'est la SOURCE RÉELLEMENT UTILISÉE
     *   aujourd'hui par les coachs, absente de cette méthode jusqu'ici.
     *
     * @return array [ ['id','title','url','best_pct','attempted','course_id','course_name','source'] ]
     */
    public static function get_quizzes_with_attempts( int $course_id, int $child_id ): array {
        $course_title = RKP_CourseRepository::find( $course_id )?->title ?? '';

        // ── Source 1 : quiz Tutor LMS natifs (CPT tutor_quiz) ──
        $native_quizzes = RKP_QuizRepository::find_by_course( $course_id );
        $native_view = array_map( function ( RKP_Quiz $quiz ) use ( $child_id, $course_id, $course_title ) {
            $best_pct = RKP_QuizAttemptRepository::get_best_score( $quiz->id, $child_id );
            return [
                'id'          => $quiz->id,
                'title'       => $quiz->title,
                'url'         => $quiz->permalink,
                'best_pct'    => $best_pct,
                'attempted'   => null !== $best_pct,
                'course_id'   => $course_id,
                'course_name' => $course_title,
                'source'      => 'tutor',
            ];
        }, $native_quizzes );

        // ── Source 2 : quiz AYS Quiz Maker liés au cours ──
        $ays_view = [];
        if ( class_exists( 'RKP_AysQuizRepository' ) ) {
            // find_assigned_for_child_and_course() attend l'ID rk_children
            // (table rk_children), pas le wp_user_id reçu en paramètre ici
            // — résolution via RKP_ChildRepository, seule couche autorisée
            // à faire cette conversion.
            $child_rk_id = class_exists( 'RKP_ChildRepository' )
                ? RKP_ChildRepository::get_id_by_wp_user( $child_id )
                : 0;

            if ( $child_rk_id ) {
                $ays_rows = RKP_AysQuizRepository::find_assigned_for_child_and_course( $child_rk_id, $child_id, $course_id );
                foreach ( $ays_rows as $row ) {
                    $attempted = ! empty( $row->end_date );
                    $score     = $attempted ? max( 0, min( 100, (int) $row->score ) ) : null;
                    $ays_view[] = [
                        'id'          => (int) $row->quiz_id,
                        'title'       => (string) $row->quiz_title,
                        'url'         => $row->custom_post_id ? (string) ( get_permalink( (int) $row->custom_post_id ) ?: '' ) : '',
                        'best_pct'    => $score,
                        'attempted'   => $attempted,
                        'course_id'   => $course_id,
                        'course_name' => $course_title,
                        'source'      => 'ays',
                    ];
                }
            }
        }

        return array_merge( $native_view, $ays_view );
    }

    /**
     * TOUS les quiz d'un enfant (à travers ses cours inscrits), 30 max.
     *
     * @return array  Tableau de view models — même format que get_quizzes_with_attempts().
     */
    public static function get_all_quizzes_for_child( int $child_id ): array {
        $courses = self::get_courses_for_child( $child_id );
        $out     = [];
        foreach ( $courses as $course ) {
            $out = array_merge( $out, self::get_quizzes_with_attempts( $course->id, $child_id ) );
        }
        return array_slice( array_reverse( $out ), 0, 30 );
    }

    // ── Stats globales ─────────────────────────────────────────────────────

    /**
     * Résumé statistique complet d'un enfant.
     *
     * @return array{
     *   courses_total: int, courses_completed: int, courses_active: int,
     *   lessons_total: int, lessons_done: int,
     *   quizzes_total: int, quizzes_done: int,
     *   avg_progress: int
     * }
     */
    public static function get_stats_for_child( int $child_id ): array {
        $courses = self::get_courses_for_child( $child_id );
        $stats   = [
            'courses_total'     => count( $courses ),
            'courses_completed' => 0,
            'courses_active'    => 0,
            'lessons_total'     => 0,
            'lessons_done'      => 0,
            'quizzes_total'     => 0,
            'quizzes_done'      => 0,
            'avg_progress'      => 0,
        ];
        if ( empty( $courses ) ) return $stats;

        $progress_sum = 0;
        foreach ( $courses as $course ) {
            $progress = self::get_progress( $course->id, $child_id );
            $pct      = $progress ? $progress->percent : 0;

            if ( $pct >= 100 )    $stats['courses_completed']++;
            elseif ( $pct > 0 )   $stats['courses_active']++;

            $stats['lessons_total'] += $progress ? $progress->lessons_total : 0;
            $stats['lessons_done']  += $progress ? $progress->lessons_done  : 0;
            $stats['quizzes_total'] += RKP_QuizRepository::count_by_course( $course->id );
            $stats['quizzes_done']  += RKP_QuizAttemptRepository::count_completed_in_course( $course->id, $child_id );
            $progress_sum           += $pct;
        }

        $stats['avg_progress'] = (int) round( $progress_sum / count( $courses ) );
        return $stats;
    }

    /**
     * Premier cours non terminé — pour le bouton "متابعة البرنامج" du hero.
     */
    public static function get_next_course_to_continue( int $child_id ): ?RKP_Course {
        foreach ( self::get_courses_for_child( $child_id ) as $course ) {
            $progress = self::get_progress( $course->id, $child_id );
            if ( $progress && ! $progress->is_complete() ) return $course;
        }
        return null;
    }

    // ── Enrollment ─────────────────────────────────────────────────────────

    /** @return RKP_Enrollment[] */
    public static function get_enrollment( int $course_id ): array {
        return RKP_EnrollmentRepository::find_by_course( $course_id );
    }

    public static function is_enrolled( int $course_id, int $child_id ): bool {
        return RKP_EnrollmentRepository::is_enrolled( $child_id, $course_id );
    }

    // ── Instructor ─────────────────────────────────────────────────────────

    public static function get_instructor( int $course_id ): ?\WP_User {
        return RKP_CourseRepository::find_instructor( $course_id );
    }
}