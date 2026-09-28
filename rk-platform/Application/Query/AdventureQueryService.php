<?php
declare( strict_types=1 );
/**
 * Application — AdventureQueryService
 *
 * "شارات المغامرات" — v9.14 : REFONTE DE GRANULARITÉ (décision validée
 * avec l'utilisateur, remplace la version précédente "une carte par
 * cours") : chaque SÉANCE réelle (leçon Tutor) d'un cours devient une
 * carte d'aventure, à plat, pour TOUS les cours de la plateforme.
 *
 * Chaque carte porte :
 *   - nom du programme  (RKP_CategoryRepository::get_primary_for_course())
 *   - nom du cours       (RKP_Course::$title)
 *   - nom de la séance    (RKP_Lesson::$title)
 *   - statut (locked/completed, dérivé de la vraie complétion Tutor)
 *
 * PERFORMANCE — potentiellement des centaines de séances (tous les
 * cours de la plateforme, décision validée) : construit EXCLUSIVEMENT
 * sur les versions BATCH ajoutées pour ce besoin précis
 * (get_topics_batch, get_lessons_batch, get_completed_lesson_ids) —
 * jamais un appel par séance/topic/cours dans une boucle.
 *
 * PAS de is_new pour les aventures (décision déjà validée précédemment,
 * inchangée).
 *
 * @since 9.14.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;

final class RKP_AdventureQueryService {

    public const STATUS_LOCKED    = 'locked';
    public const STATUS_COMPLETED = 'completed';

    /**
     * Construit la liste à plat de toutes les séances de tous les cours
     * publiés de la plateforme, chacune enrichie du vrai statut réel
     * pour cet enfant précis.
     *
     * @return array<int, array{
     *   lesson_id:int, session_title:string, thumbnail:string,
     *   course_id:int, course_title:string,
     *   program_name:string, program_icon_key:string,
     *   status:string, completed:bool, permalink:string
     * }>
     *   `thumbnail` = image du cours Tutor LMS (featured image du CPT
     *   'courses', identique pour toutes les séances d'un même cours),
     *   jamais celle de la leçon.
     */
    public static function get_adventures_for_child( int $child_rk_id, int $wp_user_id, int $course_limit = 100 ): array {
        if ( ! class_exists( 'RKP_CourseRepository' )
             || ! class_exists( 'RKP_LearningQueryService' )
             || ! class_exists( 'RKP_CategoryRepository' ) ) {
            return [];
        }

        $courses = RKP_CourseRepository::find_all( $course_limit );
        if ( empty( $courses ) ) return [];

        $course_ids = array_map( static fn( RKP_Course $c ) => $c->id, $courses );

        // ── 1 requête : tous les topics de tous les cours ──────────────
        $topics_by_course = RKP_LearningQueryService::get_topics_batch( $course_ids );

        $all_topic_ids      = [];
        $course_id_by_topic = [];
        foreach ( $topics_by_course as $course_id => $topics ) {
            foreach ( $topics as $topic ) {
                $all_topic_ids[] = $topic->id;
                $course_id_by_topic[ $topic->id ] = $course_id;
            }
        }

        // ── 1 requête : toutes les leçons de tous les topics ───────────
        $lessons_by_topic = RKP_LearningQueryService::get_lessons_batch( $all_topic_ids, $course_id_by_topic );

        // ── 1 requête : toutes les leçons complétées par cet enfant ────
        $completed_ids = $wp_user_id > 0
            ? RKP_LearningQueryService::get_completed_lesson_ids( $wp_user_id )
            : [];
        $completed_set = array_flip( $completed_ids ); // isset() O(1) plutôt qu'in_array() O(n) répété

        // Index cours (titre) + programme (catégorie) — déjà chargés
        // sans requête supplémentaire par carte (données déjà en
        // mémoire depuis find_all(), + 1 appel catégorie par cours
        // distinct, pas par séance).
        $course_by_id = [];
        foreach ( $courses as $c ) { $course_by_id[ $c->id ] = $c; }

        $program_by_course = [];
        $program_icon_by_course = [];
        foreach ( $course_ids as $cid ) {
            $program_name                     = RKP_CategoryRepository::get_primary_for_course( $cid );
            $program_by_course[ $cid ]        = $program_name;
            // Icône dynamique du cours Tutor LMS : MÊME mapping catégorie→
            // icône que celui déjà utilisé pour les puces de catégorie de
            // "مغامراتي" (RK_MC_Adventures_Filter_Service::category_icon_key(),
            // dérivé du nom de catégorie Tutor du cours) — pas une nouvelle
            // icône recalculée, pour rester visuellement cohérent entre
            // l'écran مغامراتي et les شارات المغامرات de "شاراتي".
            $program_icon_by_course[ $cid ]   = class_exists( 'RK_MC_Adventures_Filter_Service' )
                ? RK_MC_Adventures_Filter_Service::category_icon_key( $program_name )
                : 'filter-business';
        }

        $out = [];
        foreach ( $lessons_by_topic as $topic_id => $lessons ) {
            $course_id = $course_id_by_topic[ $topic_id ] ?? 0;
            $course    = $course_by_id[ $course_id ] ?? null;
            if ( ! $course ) continue;

            foreach ( $lessons as $lesson ) {
                $completed = isset( $completed_set[ $lesson->id ] );
                $out[] = [
                    'lesson_id'     => $lesson->id,
                    'session_title' => $lesson->title,
                    // Image du COURS Tutor LMS (RKP_Course::$thumbnail,
                    // featured image du CPT 'courses'), PAS celle de la
                    // leçon : les leçons Tutor n'ont quasiment jamais de
                    // featured image propre, ce qui laissait les cartes
                    // (dashboard + haut de la fiche cours mobile) sans
                    // image ou avec le dégradé de repli.
                    'thumbnail'     => $course->thumbnail,
                    'course_id'     => $course_id,
                    'course_title'  => $course->title,
                    'program_name'  => $program_by_course[ $course_id ] ?? '',
                    'program_icon_key' => $program_icon_by_course[ $course_id ] ?? 'filter-business',
                    'status'        => $completed ? self::STATUS_COMPLETED : self::STATUS_LOCKED,
                    'completed'     => $completed,
                    'permalink'     => $lesson->permalink,
                ];
            }
        }

        return $out;
    }
}