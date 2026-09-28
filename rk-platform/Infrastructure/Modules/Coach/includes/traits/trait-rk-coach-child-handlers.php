<?php
declare( strict_types=1 );
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Extrait de class-rk-coach-child-detail.php — factorisation par fonctionnalité.
 * Code déplacé verbatim, aucune modification de logique.
 */
trait RK_Coach_Child_Handlers {
    /* ─── Handlers admin-post ──────────────────────────────────────── */

    public static function handle_save_eval_inline(): void {
        check_admin_referer( 'rk_coach_eval_inline', '_nonce' );
        if ( ! current_user_can( 'tutor_instructor' ) ) wp_die( 'غير مصرح', 403 );

        $coach_id = get_current_user_id();
        $child_id = absint( $_POST['child_id'] ?? 0 );
        if ( ! $child_id ) wp_die( 'child_id manquant', 400 );

        // Vérifier ownership
        $students  = RK_Coach_Data::get_coach_students( $coach_id );
        $child_ids = array_map( fn( $s ) => (int) $s->child_id, $students );
        if ( ! in_array( $child_id, $child_ids, true ) ) wp_die( 'غير مصرح', 403 );

        $strengths   = array_filter( array_map( 'sanitize_text_field', explode( "\n", wp_unslash( $_POST['strengths'] ?? '' ) ) ) );
        $developments = array_filter( array_map( 'sanitize_text_field', explode( "\n", wp_unslash( $_POST['developments'] ?? '' ) ) ) );
        $skill_scores = [];
        foreach ( (array) ( $_POST['skill_scores'] ?? [] ) as $k => $v ) {
            $skill_scores[ sanitize_key( $k ) ] = min( 5, max( 0, (int) $v ) );
        }

        if ( class_exists( 'RK_MC_Assessment_Service' ) ) {
            RK_MC_Assessment_Service::create( [
                'child_id'         => $child_id,
                'coach_id'         => $coach_id,
                'assessed_at'      => sanitize_text_field( $_POST['assessed_at'] ?? current_time( 'Y-m-d' ) ),
                'rating'           => absint( $_POST['rating'] ?? 3 ),
                'summary'          => sanitize_textarea_field( wp_unslash( $_POST['summary'] ?? '' ) ),
                'strengths'        => array_values( $strengths ),
                'developments'     => array_values( $developments ),
                'notes'            => sanitize_textarea_field( wp_unslash( $_POST['personal_message'] ?? '' ) ),
                'skill_scores'     => $skill_scores,
            ] );
        }

        // Synchroniser les compétences dans wp_rk_child_skills (score 0-5 → level 0-10)
        if ( class_exists( 'RK_MC_Skill_Service' ) && ! empty( $skill_scores ) ) {
            foreach ( $skill_scores as $skill_key => $score ) {
                RK_MC_Skill_Service::set_skill( $child_id, $skill_key, min( 10, max( 0, $score * 2 ) ) );
            }
            RK_MC_Child_Dashboard_Data::clear_cache( $child_id );
        }

        $redirect = add_query_arg( [ 'child_id' => $child_id, 'tab' => 'evals', 'eval_done' => '1' ],
            tutor_utils()->tutor_dashboard_url( 'rk-fiche-eleve' ) );
        wp_safe_redirect( $redirect );
        exit;
    }

    public static function handle_assign_mission(): void {
        check_admin_referer( 'rk_coach_assign_mission', '_nonce' );
        if ( ! current_user_can( 'tutor_instructor' ) ) wp_die( 'غير مصرح', 403 );

        $coach_id = get_current_user_id();
        $child_id = absint( $_POST['child_id'] ?? 0 );
        if ( ! $child_id ) wp_die( 'child_id manquant', 400 );

        $students  = RK_Coach_Data::get_coach_students( $coach_id );
        $child_ids = array_map( fn( $s ) => (int) $s->child_id, $students );
        if ( ! in_array( $child_id, $child_ids, true ) ) wp_die( 'غير مصرح', 403 );

        RKP_CoachMissionCommandService::assign( [
            'child_id'    => $child_id,
            'coach_id'    => $coach_id,
            'title'       => sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) ),
            'description' => sanitize_textarea_field( wp_unslash( $_POST['description'] ?? '' ) ),
            'due_date'    => sanitize_text_field( $_POST['due_date'] ?? '' ) ?: null,
            'points'      => min( 100, max( 0, absint( $_POST['xp_reward'] ?? 10 ) ) ),
        ] );

        $redirect = add_query_arg( [ 'child_id' => $child_id, 'tab' => 'missions', 'mission_done' => '1' ],
            tutor_utils()->tutor_dashboard_url( 'rk-fiche-eleve' ) );
        wp_safe_redirect( $redirect );
        exit;
    }

    public static function handle_award_badge(): void {
        check_admin_referer( 'rk_coach_award_badge', '_nonce' );
        if ( ! current_user_can( 'tutor_instructor' ) ) wp_die( 'غير مصرح', 403 );

        $coach_id  = get_current_user_id();
        $child_id  = absint( $_POST['child_id'] ?? 0 );
        $badge_key = sanitize_key( $_POST['badge_key'] ?? '' );
        if ( ! $child_id || ! $badge_key ) wp_die( 'Paramètres manquants', 400 );

        $students  = RK_Coach_Data::get_coach_students( $coach_id );
        $child_ids = array_map( fn( $s ) => (int) $s->child_id, $students );
        if ( ! in_array( $child_id, $child_ids, true ) ) wp_die( 'غير مصرح', 403 );

        // v9.14 — Le résultat de award() était ignoré : en cas d'échec
        // (badge déjà attribué, clé invalide, ou table SQL manquante),
        // le coach voyait quand même "تم منح الشارة بنجاح!" alors que
        // rien n'avait été écrit en base. On vérifie maintenant le
        // retour et on redirige vers un message d'erreur explicite.
        $awarded = class_exists( 'RK_MC_Badge_Service' )
            && RK_MC_Badge_Service::award( $child_id, $badge_key );

        // v9.13 — Redirection intelligente selon la page d'origine : ce
        // handler est partagé par deux formulaires (l'onglet "شارات" de
        // la fiche élève ET la page dédiée rk-badges du coach). Avant
        // cette version, le retour était toujours forcé vers
        // rk-fiche-eleve, ce qui téléportait le coach hors de rk-badges
        // après un clic sur "منح" — return_page (champ hidden posé par
        // chaque formulaire) restaure le bon comportement pour les deux.
        $return_page = sanitize_key( $_POST['return_page'] ?? 'rk-fiche-eleve' );
        $return_page = in_array( $return_page, [ 'rk-fiche-eleve', 'rk-badges' ], true ) ? $return_page : 'rk-fiche-eleve';

        $status_args = $awarded
            ? [ 'badge_done' => '1' ]
            : [ 'badge_error' => 'award_failed' ];
        $redirect_args = 'rk-badges' === $return_page
            ? array_merge( [ 'child_id' => $child_id ], $status_args )
            : array_merge( [ 'child_id' => $child_id, 'tab' => 'badges' ], $status_args );

        $redirect = add_query_arg( $redirect_args, tutor_utils()->tutor_dashboard_url( $return_page ) );
        wp_safe_redirect( $redirect );
        exit;
    }

    public static function handle_set_skills(): void {
        check_admin_referer( 'rk_coach_set_skills', '_nonce' );
        if ( ! current_user_can( 'tutor_instructor' ) ) wp_die( 'غير مصرح', 403 );

        $coach_id = get_current_user_id();
        $child_id = absint( $_POST['child_id'] ?? 0 );
        if ( ! $child_id ) wp_die( 'child_id manquant', 400 );

        $students  = RK_Coach_Data::get_coach_students( $coach_id );
        $child_ids = array_map( fn( $s ) => (int) $s->child_id, $students );
        if ( ! in_array( $child_id, $child_ids, true ) ) wp_die( 'غير مصرح', 403 );

        if ( class_exists( 'RK_MC_Skill_Service' ) ) {
            foreach ( (array) ( $_POST['skills'] ?? [] ) as $key => $val ) {
                RK_MC_Skill_Service::set_skill( $child_id, sanitize_key( $key ), absint( $val ) );
            }
        }

        if ( class_exists( 'RK_MC_Child_Dashboard_Data' ) ) {
            RK_MC_Child_Dashboard_Data::clear_cache( $child_id );
        }

        $redirect = add_query_arg(
            [ 'child_id' => $child_id, 'tab' => 'skills', 'skills_done' => '1' ],
            tutor_utils()->tutor_dashboard_url( 'rk-fiche-eleve' )
        );
        wp_safe_redirect( $redirect );
        exit;
    }

    public static function handle_save_certificate(): void {
        check_admin_referer( 'rk_coach_save_certificate', '_nonce' );
        if ( ! current_user_can( 'tutor_instructor' ) ) wp_die( 'غير مصرح', 403 );

        $coach_id = get_current_user_id();
        $child_id = absint( $_POST['child_id'] ?? 0 );
        if ( ! $child_id ) wp_die( 'child_id manquant', 400 );

        $students  = RK_Coach_Data::get_coach_students( $coach_id );
        $child_ids = array_map( fn( $s ) => (int) $s->child_id, $students );
        if ( ! in_array( $child_id, $child_ids, true ) ) wp_die( 'غير مصرح', 403 );

        // Gestion du fichier
        $file_url  = '';
        $file_name = '';

        // Priorité 1 : URL manuelle
        $manual_url = esc_url_raw( trim( wp_unslash( $_POST['file_url_manual'] ?? '' ) ) );
        if ( $manual_url ) {
            $file_url  = $manual_url;
            $file_name = basename( parse_url( $manual_url, PHP_URL_PATH ) );
        }

        // Priorité 2 : upload fichier
        if ( ! $file_url && ! empty( $_FILES['certificate_file']['name'] ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            $upload = wp_handle_upload(
                $_FILES['certificate_file'],
                [ 'test_form' => false, 'mimes' => [ 'pdf' => 'application/pdf', 'png' => 'image/png', 'jpg|jpeg' => 'image/jpeg' ] ]
            );
            if ( isset( $upload['url'] ) && ! isset( $upload['error'] ) ) {
                $file_url  = $upload['url'];
                $file_name = basename( $upload['file'] );
            }
        }

        RKP_CertificateRepository::maybe_create_table();
        RKP_CertificateRepository::insert( [
            'child_id'    => $child_id,
            'coach_id'    => $coach_id,
            'title'       => sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) ),
            'description' => sanitize_textarea_field( wp_unslash( $_POST['description'] ?? '' ) ),
            'file_url'    => $file_url,
            'file_name'   => sanitize_file_name( $file_name ),
            'issued_at'   => current_time( 'mysql' ),
        ] );

        if ( class_exists( 'RK_MC_Child_Dashboard_Data' ) ) {
            RK_MC_Child_Dashboard_Data::clear_cache( $child_id );
        }

        $redirect = add_query_arg(
            [ 'child_id' => $child_id, 'tab' => 'certs', 'cert_done' => '1' ],
            tutor_utils()->tutor_dashboard_url( 'rk-fiche-eleve' )
        );
        wp_safe_redirect( $redirect );
        exit;
    }

    /* ─── Helper : Tutor LMS shadow course pour un WC product ──────── */

    public static function get_or_create_shadow_course( int $product_id, int $coach_id ): int {
        global $wpdb;
        $existing = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p
             JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
             WHERE p.post_type = 'courses'
               AND p.post_status = 'publish'
               AND pm.meta_key = '_rk_linked_product'
               AND pm.meta_value = %d
             LIMIT 1",
            $product_id
        ) );
        if ( $existing ) return $existing;

        $title = get_the_title( $product_id );
        if ( ! $title ) return 0;

        $shadow_id = (int) wp_insert_post( [
            'post_title'  => $title,
            'post_type'   => 'courses',
            'post_status' => 'publish',
            'post_author' => $coach_id,
        ] );
        if ( ! $shadow_id ) return 0;

        update_post_meta( $shadow_id, '_rk_linked_product', $product_id );
        update_post_meta( $shadow_id, '_tutor_course_price_type', 'free' );
        update_post_meta( $shadow_id, '_tutor_instructor', $coach_id );

        return $shadow_id;
    }

    /* ─── Handler : إنشاء اختبار Tutor LMS ────────────────────────── */

    public static function handle_create_quiz(): void {
        check_admin_referer( 'rk_coach_create_quiz', '_nonce' );
        if ( ! current_user_can( 'tutor_instructor' ) ) wp_die( 'غير مصرح', 403 );

        $coach_id      = get_current_user_id();
        $child_id      = absint( $_POST['child_id'] ?? 0 );
        $course_id     = absint( $_POST['course_id'] ?? 0 );
        $quiz_title    = sanitize_text_field( wp_unslash( $_POST['quiz_title'] ?? '' ) );
        $passing_grade = min( 100, max( 0, absint( $_POST['passing_grade'] ?? 80 ) ) );

        if ( ! $child_id || ! $course_id || ! $quiz_title ) wp_die( 'Params manquants', 400 );

        // Vérifier ownership
        $students  = RK_Coach_Data::get_coach_students( $coach_id );
        $child_ids = array_map( fn( $s ) => (int) $s->child_id, $students );
        if ( ! in_array( $child_id, $child_ids, true ) ) wp_die( 'غير مصرح', 403 );

        $err_url = add_query_arg(
            [ 'child_id' => $child_id, 'tab' => 'quiz', 'quiz_error' => '1' ],
            function_exists( 'tutor_utils' ) ? tutor_utils()->tutor_dashboard_url( 'rk-fiche-eleve' ) : home_url()
        );

        global $wpdb;

        // Shadow Tutor course lié au WC product (hiérarchie LMS correcte)
        $shadow_course_id = self::get_or_create_shadow_course( $course_id, $coach_id );
        if ( ! $shadow_course_id ) {
            wp_safe_redirect( $err_url );
            exit;
        }

        // Inscrire l'enfant dans le shadow course (idempotent)
        if ( function_exists( 'tutor_utils' ) && ! tutor_utils()->is_enrolled( $shadow_course_id, $child_id ) ) {
            tutor_utils()->do_enroll( $shadow_course_id, 0, $child_id );
        }

        // Trouver ou créer un topic dans le shadow course
        $topic_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_type = 'topics' AND post_parent = %d AND post_status = 'publish'
             ORDER BY menu_order ASC LIMIT 1",
            $shadow_course_id
        ) );

        if ( ! $topic_id ) {
            $topic_id = (int) wp_insert_post( [
                'post_title'  => __( 'الاختبارات', 'rk-coach-hub' ),
                'post_type'   => 'topics',
                'post_status' => 'publish',
                'post_parent' => $shadow_course_id,
                'menu_order'  => 1,
                'post_author' => $coach_id,
            ] );
        }

        if ( ! $topic_id ) {
            wp_safe_redirect( $err_url );
            exit;
        }

        // Construire les questions au format QuizBuilder v3
        $questions_payload = [];
        foreach ( (array) ( $_POST['questions'] ?? [] ) as $q_data ) {
            $q_title = sanitize_text_field( wp_unslash( $q_data['title'] ?? '' ) );
            if ( empty( $q_title ) ) continue;

            $correct_idx = absint( $q_data['correct'] ?? 0 );
            $answers     = [];
            foreach ( (array) ( $q_data['options'] ?? [] ) as $ai => $opt ) {
                $opt_text = sanitize_text_field( wp_unslash( $opt ) );
                if ( empty( $opt_text ) ) continue;
                $answers[] = [
                    \TUTOR\QuizBuilder::TRACKING_KEY => \TUTOR\QuizBuilder::FLAG_NEW,
                    'answer_title'                   => $opt_text,
                    'is_correct'                     => ( (int) $ai === $correct_idx ) ? 1 : 0,
                    'answer_view_format'             => 'text',
                    'answer_two_gap_match'           => '',
                    'image_id'                       => 0,
                ];
            }

            $questions_payload[] = [
                \TUTOR\QuizBuilder::TRACKING_KEY => \TUTOR\QuizBuilder::FLAG_NEW,
                'question_title'                 => $q_title,
                'question_description'           => '',
                'question_type'                  => 'multiple_choice',
                'question_mark'                  => 1,
                'question_settings'              => [],
                'question_answers'               => $answers,
            ];
        }

        // Appel direct à QuizBuilder::save_quiz() (Tutor LMS 3.x)
        $builder = new \TUTOR\QuizBuilder( false );
        $result  = $builder->save_quiz( $topic_id, [
            'post_title'   => $quiz_title,
            'post_content' => '',
            'quiz_option'  => [
                'time_limit'                    => [ 'time_value' => 0, 'time_type' => 'minutes' ],
                'hide_quiz_time_display'        => 0,
                'feedback_mode'                 => 'retry',
                'attempts_allowed'              => 3,
                'passing_grade'                 => $passing_grade,
                'max_questions_for_answer'      => 0,
                'quiz_auto_start'               => 0,
                'short_answer_characters_limit' => 200,
            ],
            'questions' => $questions_payload,
        ] );

        if ( ! $result->success || ! $result->data ) {
            wp_safe_redirect( $err_url );
            exit;
        }

        $quiz_id = (int) $result->data;

        // Invalider le cache du dashboard enfant
        if ( class_exists( 'RK_MC_Child_Dashboard_Data' ) ) {
            RK_MC_Child_Dashboard_Data::clear_cache( $child_id );
        }

        wp_safe_redirect( add_query_arg(
            [ 'child_id' => $child_id, 'tab' => 'quiz', 'quiz_done' => '1' ],
            tutor_utils()->tutor_dashboard_url( 'rk-fiche-eleve' )
        ) );
        exit;
    }

}