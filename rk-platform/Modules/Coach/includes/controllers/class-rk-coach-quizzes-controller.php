<?php
declare( strict_types=1 );
/**
 * RK_Coach_Quizzes_Controller  (v2.4.0 — Audit P3-14)
 *
 * Quiz : création, édition, résultats, notifications de résultat.
 * Extrait du God Service RK_Coach_API (2 527 lignes) — corps des méthodes
 * strictement inchangé ; seuls les helpers partagés pointent désormais
 * vers RK_Coach_Api_Helpers. Le routing et l'auth restent dans RK_Coach_API.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class RK_Coach_Quizzes_Controller {

    public static function get_quiz_detail( WP_REST_Request $request ): WP_REST_Response {
        global $wpdb;
        $coach_id = get_current_user_id();
        $quiz_id  = (int) $request->get_param( 'id' );

        if ( ! $quiz_id ) {
            return new WP_REST_Response( [ 'code' => 'missing_id' ], 400 );
        }

        $qz  = $wpdb->prefix . 'aysquiz_quizes';
        $qt  = $wpdb->prefix . 'aysquiz_questions';
        $at  = $wpdb->prefix . 'aysquiz_answers';

        $quiz = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, title, question_ids, options, custom_post_id
               FROM {$qz}
              WHERE id = %d AND author_id = %d LIMIT 1",
            $quiz_id, $coach_id
        ) );

        if ( ! $quiz ) {
            return new WP_REST_Response( [ 'code' => 'not_found' ], 404 );
        }

        $opts          = json_decode( (string) ( $quiz->options ?? '{}' ), true ) ?: [];
        $passing_grade = (int) ( $opts['passing_grade'] ?? 80 );
        $quiz_url      = $quiz->custom_post_id
            ? (string) ( get_permalink( (int) $quiz->custom_post_id ) ?: '' )
            : '';

        $q_ids     = array_filter( array_map( 'intval', explode( ',', (string) ( $quiz->question_ids ?? '' ) ) ) );
        $questions = [];

        foreach ( $q_ids as $qid ) {
            $q = $wpdb->get_row( $wpdb->prepare(
                "SELECT id, question, type FROM {$qt} WHERE id = %d LIMIT 1",
                $qid
            ) );
            if ( ! $q ) continue;

            $answers_raw = $wpdb->get_results( $wpdb->prepare(
                "SELECT id, answer, correct FROM {$at}
                  WHERE question_id = %d ORDER BY ordering ASC",
                $qid
            ) ) ?: [];

            $questions[] = [
                'id'      => (int) $q->id,
                'title'   => (string) $q->question,
                'type'    => (string) $q->type,
                'mark'    => 1,
                'answers' => array_map( static function ( $a ) {
                    return [
                        'id'         => (int)  $a->id,
                        'title'      => (string) $a->answer,
                        'is_correct' => (bool) $a->correct,
                    ];
                }, $answers_raw ),
            ];
        }

        return new WP_REST_Response( [
            'quiz_id'       => $quiz_id,
            'quiz_title'    => (string) $quiz->title,
            'quiz_url'      => $quiz_url,
            'passing_grade' => $passing_grade,
            'attempts'      => 0,
            'questions'     => $questions,
        ], 200 );
    }

    /* ─── POST /coach/quiz/{id}/update — modifier un quiz ──────── */

    public static function update_quiz( WP_REST_Request $request ): WP_REST_Response {
        global $wpdb;
        $coach_id      = get_current_user_id();
        $quiz_id       = (int) $request->get_param( 'id' );
        $quiz_title    = sanitize_text_field( (string) $request->get_param( 'quiz_title' ) );
        $passing_grade = min( 100, max( 0, (int) ( $request->get_param( 'passing_grade' ) ?: 80 ) ) );
        $questions_raw = (array) ( $request->get_param( 'questions' ) ?: [] );

        // v2.6 (Quiz Flow) — politique d'échéance et de tentatives (optionnels).
        $due_raw      = sanitize_text_field( (string) $request->get_param( 'due_date' ) );
        $due_date     = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $due_raw ) ? $due_raw : '';
        $max_attempts = min( 10, max( 1, (int) ( $request->get_param( 'max_attempts' ) ?: 1 ) ) );

        if ( ! $quiz_id || ! $quiz_title ) {
            return new WP_REST_Response( [ 'code' => 'missing_params' ], 400 );
        }

        $qz = $wpdb->prefix . 'aysquiz_quizes';
        $qt = $wpdb->prefix . 'aysquiz_questions';
        $at = $wpdb->prefix . 'aysquiz_answers';

        $quiz = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, question_ids, options, custom_post_id FROM {$qz}
              WHERE id = %d AND author_id = %d LIMIT 1",
            $quiz_id, $coach_id
        ) );

        if ( ! $quiz ) {
            return new WP_REST_Response( [ 'code' => 'not_found' ], 404 );
        }

        // Supprimer anciennes questions + réponses
        $old_ids = array_filter( array_map( 'intval', explode( ',', (string) ( $quiz->question_ids ?? '' ) ) ) );
        foreach ( $old_ids as $old_qid ) {
            $wpdb->delete( $at, [ 'question_id' => $old_qid ], [ '%d' ] );
            $wpdb->delete( $qt, [ 'id' => $old_qid ], [ '%d' ] );
        }

        // Insérer nouvelles questions + réponses
        $new_ids = [];
        foreach ( $questions_raw as $q_data ) {
            $q_title = sanitize_text_field( (string) ( $q_data['title'] ?? '' ) );
            if ( ! $q_title ) continue;
            $wpdb->insert( $qt, [
                'author_id' => $coach_id, 'category_id' => 1,
                'question' => $q_title, 'question_title' => $q_title,
                'type' => 'radio', 'published' => 1,
                'create_date' => current_time( 'mysql' ),
                'not_influence_to_score' => 'off', 'options' => '{}',
            ], [ '%d', '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s' ] );
            $question_id = (int) $wpdb->insert_id;
            if ( ! $question_id ) continue;
            $new_ids[] = $question_id;
            $correct_idx = (int) ( $q_data['correct'] ?? 0 );
            foreach ( (array) ( $q_data['options'] ?? [] ) as $ai => $opt ) {
                $opt_text = sanitize_text_field( (string) $opt );
                if ( ! $opt_text ) continue;
                $wpdb->insert( $at, [
                    'question_id' => $question_id, 'answer' => $opt_text,
                    'image' => '', 'correct' => ( (int) $ai === $correct_idx ) ? 1 : 0,
                    'ordering' => $ai + 1, 'placeholder' => '',
                ], [ '%d', '%s', '%s', '%d', '%d', '%s' ] );
            }
        }

        if ( empty( $new_ids ) ) {
            return new WP_REST_Response( [ 'code' => 'no_valid_questions' ], 400 );
        }

        $opts = json_decode( (string) ( $quiz->options ?? '{}' ), true ) ?: [];
        $opts['passing_grade']        = $passing_grade;
        $opts['enable_correction']       = 'off';
        $opts['enable_previous_button']  = 'on';
        $opts['enable_restart_button']   = 'off';
        $opts['enable_questions_result'] = 'off';

        $wpdb->update( $qz, [
            'title'        => $quiz_title,
            'question_ids' => implode( ',', $new_ids ),
            'options'      => wp_json_encode( $opts ),
        ], [ 'id' => $quiz_id ], [ '%s', '%s', '%s' ], [ '%d' ] );

        if ( $quiz->custom_post_id ) {
            wp_update_post( [ 'ID' => (int) $quiz->custom_post_id, 'post_title' => $quiz_title ] );
        }

        // Invalidate child dashboard cache
        if ( class_exists( 'RK_MC_Child_Dashboard_Data' ) && strpos( (string) ( $quiz->quiz_url ?? '' ), 'rk:child:' ) === 0 ) {
            $cache_child_id = (int) str_replace( 'rk:child:', '', (string) $quiz->quiz_url );
            if ( $cache_child_id ) RK_MC_Child_Dashboard_Data::clear_cache( $cache_child_id );
        }

        return new WP_REST_Response( [ 'success' => true ], 200 );
    }

    /* ─── POST /quiz/result-notify — notif parent après quiz enfant ─ */

    public static function notify_quiz_result( WP_REST_Request $request ): WP_REST_Response {
        global $wpdb;
        $user_id = get_current_user_id();
        $quiz_id = (int) $request->get_param( 'quiz_id' );
        if ( ! $quiz_id ) {
            return new WP_REST_Response( [ 'ok' => true ], 200 ); // silent
        }

        $rp = $wpdb->prefix . 'aysquiz_reports';
        $qz = $wpdb->prefix . 'aysquiz_quizes';

        // Latest attempt for this user + quiz
        $report = $wpdb->get_row( $wpdb->prepare(
            "SELECT score, corrects_count, questions_count, end_date
               FROM {$rp}
              WHERE quiz_id = %d AND user_id = %d
              ORDER BY end_date DESC LIMIT 1",
            $quiz_id, $user_id
        ) );
        if ( ! $report ) {
            return new WP_REST_Response( [ 'ok' => true ], 200 );
        }

        $quiz = $wpdb->get_row( $wpdb->prepare(
            "SELECT title, options, quiz_url FROM {$qz} WHERE id = %d LIMIT 1",
            $quiz_id
        ) );
        // Only notify for RK-managed quizzes
        if ( ! $quiz || strpos( (string) ( $quiz->quiz_url ?? '' ), 'rk:child:' ) !== 0 ) {
            return new WP_REST_Response( [ 'ok' => true ], 200 );
        }

        /*
         * v2.6 (Quiz Flow) — PIPELINE SERVEUR (source de vérité) :
         * XP (barème Tutor), Domain Events quiz.passed/failed → notifications
         * in-app parent+coach, alertes échec, invalidation des caches.
         * IDEMPOTENT par tentative : recharger la page de résultat, doubler
         * l'appel ou rejouer ne compte jamais deux fois. Les emails HTML
         * ci-dessous ne partent que si la tentative vient d'être enregistrée.
         */
        $flow = class_exists( 'RKP_QuizFlowCommandService' )
            ? RKP_QuizFlowCommandService::record_ays_result( $user_id, $quiz_id )
            : [ 'recorded' => true ];
        if ( empty( $flow['recorded'] ) ) {
            return new WP_REST_Response( [ 'ok' => true, 'duplicate' => true ], 200 );
        }

        $child_id = (int) str_replace( 'rk:child:', '', (string) $quiz->quiz_url );
        $opts     = json_decode( (string) ( $quiz->options ?? '{}' ), true ) ?: [];
        $pg       = (int) ( $opts['passing_grade'] ?? 80 );
        $score    = (int) $report->score;
        $passed   = $score >= $pg;

        $parent_info = RK_Coach_Data::get_parent_info( $child_id );
        if ( empty( $parent_info['email'] ) ) {
            return new WP_REST_Response( [ 'ok' => true ], 200 );
        }

        $child_user  = get_userdata( $user_id );
        $child_name  = $child_user ? $child_user->display_name : 'الطالب';
        $status_text = $passed ? '✅ نجح' : '❌ لم ينجح';
        $sb          = $passed ? '#dcfce7' : '#fee2e2';
        $sc          = $passed ? '#166534' : '#991b1b';
        $total       = max( 1, (int) $report->questions_count );

        $subject = '📊 نتيجة اختبار ' . $child_name . ' — ' . $quiz->title;
        $body    = '<div dir="rtl" style="font-family:Tajawal,Arial,sans-serif;max-width:520px;margin:auto;padding:24px;">'
                 . '<h2 style="color:#FF4411;margin-bottom:8px;">نتيجة الاختبار 🎓</h2>'
                 . '<p>أنهى <strong>' . esc_html( $child_name ) . '</strong> الاختبار التالي:</p>'
                 . '<h3 style="color:#4C95D7;margin:10px 0;">' . esc_html( $quiz->title ) . '</h3>'
                 . '<table style="width:100%;border-collapse:collapse;margin:16px 0;font-size:.93rem;">'
                 . '<tr><td style="padding:9px 12px;background:#f8fafc;border:1px solid #e2e8f0;font-weight:600;">النتيجة</td>'
                 . '<td style="padding:9px 12px;border:1px solid #e2e8f0;font-size:1.5rem;font-weight:800;color:#FF4411;">' . $score . '%</td></tr>'
                 . '<tr><td style="padding:9px 12px;background:#f8fafc;border:1px solid #e2e8f0;font-weight:600;">الإجابات الصحيحة</td>'
                 . '<td style="padding:9px 12px;border:1px solid #e2e8f0;">' . (int) $report->corrects_count . ' / ' . $total . '</td></tr>'
                 . '<tr><td style="padding:9px 12px;background:#f8fafc;border:1px solid #e2e8f0;font-weight:600;">درجة النجاح</td>'
                 . '<td style="padding:9px 12px;border:1px solid #e2e8f0;">' . $pg . '%</td></tr>'
                 . '<tr><td style="padding:9px 12px;background:#f8fafc;border:1px solid #e2e8f0;font-weight:600;">الحالة</td>'
                 . '<td style="padding:9px 12px;border:1px solid #e2e8f0;">'
                 . '<span style="padding:3px 12px;border-radius:20px;font-weight:700;background:' . $sb . ';color:' . $sc . ';">' . $status_text . '</span>'
                 . '</td></tr>'
                 . '</table>'
                 . '<p style="color:#6b7280;font-size:12px;">تاريخ الإنجاز: ' . esc_html( (string) ( $report->end_date ?? '' ) ) . '</p>'
                 . '</div>';

        wp_mail( $parent_info['email'], $subject, $body, [ 'Content-Type: text/html; charset=UTF-8' ] );

        // ── Notification email → coach ────────────────────────────────
        $coach_wp_id = RKP_CoachSessionRepository::find_coach_wp_user_for_child( $child_id );
        $coach_user  = $coach_wp_id ? get_userdata( $coach_wp_id ) : null;
        if ( $coach_user && $coach_user->user_email ) {
            $coach_subject = '📊 نتيجة اختبار ' . $child_name . ' — ' . $quiz->title;
            $coach_fiche   = home_url( '/espace-coach/#students' );
            $coach_body    = '<div dir="rtl" style="font-family:Tajawal,Arial,sans-serif;max-width:520px;margin:auto;padding:24px;">'
                           . '<h2 style="color:#FF4411;margin-bottom:8px;">نتيجة اختبار الطالب 🎓</h2>'
                           . '<p>أكمل <strong>' . esc_html( $child_name ) . '</strong> الاختبار:</p>'
                           . '<h3 style="color:#4C95D7;margin:10px 0;">' . esc_html( $quiz->title ) . '</h3>'
                           . '<table style="width:100%;border-collapse:collapse;margin:16px 0;font-size:.93rem;">'
                           . '<tr><td style="padding:9px 12px;background:#f8fafc;border:1px solid #e2e8f0;font-weight:600;">النتيجة</td>'
                           . '<td style="padding:9px 12px;border:1px solid #e2e8f0;font-size:1.5rem;font-weight:800;color:#FF4411;">' . $score . '%</td></tr>'
                           . '<tr><td style="padding:9px 12px;background:#f8fafc;border:1px solid #e2e8f0;font-weight:600;">الإجابات الصحيحة</td>'
                           . '<td style="padding:9px 12px;border:1px solid #e2e8f0;">' . (int) $report->corrects_count . ' / ' . $total . '</td></tr>'
                           . '<tr><td style="padding:9px 12px;background:#f8fafc;border:1px solid #e2e8f0;font-weight:600;">درجة النجاح</td>'
                           . '<td style="padding:9px 12px;border:1px solid #e2e8f0;">' . $pg . '%</td></tr>'
                           . '<tr><td style="padding:9px 12px;background:#f8fafc;border:1px solid #e2e8f0;font-weight:600;">الحالة</td>'
                           . '<td style="padding:9px 12px;border:1px solid #e2e8f0;">'
                           . '<span style="padding:3px 12px;border-radius:20px;font-weight:700;background:' . $sb . ';color:' . $sc . ';">' . $status_text . '</span>'
                           . '</td></tr>'
                           . '</table>'
                           . '<p style="margin-top:16px;">'
                           . '<a href="' . esc_url( $coach_fiche ) . '" '
                           . 'style="display:inline-block;padding:10px 24px;background:#4C95D7;color:#fff;'
                           . 'border-radius:25px;text-decoration:none;font-weight:700;font-size:.9rem;">عرض ملف الطالب ←</a>'
                           . '</p>'
                           . '<p style="color:#6b7280;font-size:12px;margin-top:12px;">تاريخ الإنجاز: ' . esc_html( (string) ( $report->end_date ?? '' ) ) . '</p>'
                           . '</div>';
            wp_mail( $coach_user->user_email, $coach_subject, $coach_body, [ 'Content-Type: text/html; charset=UTF-8' ] );
        }

        // ── Notification WP interne → coach (dashboard bell) ─────────
        if ( $coach_user ) {
            $notif_table = $wpdb->prefix . 'rk_notifications';
            if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $notif_table ) ) === $notif_table ) {
                $wpdb->insert( $notif_table, [
                    'user_id'    => (int) $coach_user->ID,
                    'type'       => 'quiz_result',
                    'child_id'   => $child_id,
                    'title'      => 'نتيجة اختبار ' . $child_name,
                    'body'       => $quiz->title . ' — ' . $score . '%' . ( $passed ? ' ✅' : ' ❌' ),
                    'is_read'    => 0,
                    'created_at' => current_time( 'mysql' ),
                ], [ '%d', '%s', '%d', '%s', '%s', '%d', '%s' ] );
            }
        }

        // Invalidate child dashboard cache so the score appears on next load
        if ( class_exists( 'RK_MC_Child_Dashboard_Data' ) ) {
            RK_MC_Child_Dashboard_Data::clear_cache( $child_id );
        }

        return new WP_REST_Response( [
            'ok'         => true,
            'xp'         => (int) ( $flow['xp'] ?? 0 ), // v2.6.1 — affiché sur la bannière enfant
            'score_data' => [
                'score'        => $score,
                'passed'       => $passed,
                'corrects'     => (int) $report->corrects_count,
                'total'        => $total,
                'passing_grade' => $pg,
            ],
        ], 200 );
    }

    /* ─── GET /coach/child/quiz-results?child_id=N ──────────────── */

    public static function get_child_quiz_results( WP_REST_Request $request ): WP_REST_Response {
        global $wpdb;
        $coach_id = get_current_user_id();
        $child_id = (int) $request->get_param( 'child_id' );
        if ( ! $child_id ) {
            return new WP_REST_Response( [ 'code' => 'missing_child_id' ], 400 );
        }

        // v3.1.3 — fix faux 403 (redirection intempestive vers ?expired=1) :
        // l'ancienne vérification passait par get_coach_children_wp_user_ids(),
        // qui dérive la liste des enfants du coach depuis wp_rk_bookings
        // (réservations). C'est une source différente de la table
        // d'assignation officielle wp_rk_child_coaches utilisée par TOUS les
        // autres endpoints (students, quizzes, evals...). Un enfant assigné
        // au coach mais sans ligne de booking correspondante était donc
        // rejeté à tort (403), ce qui finissait par déconnecter le coach
        // côté SPA. On utilise désormais coach_owns_child(), qui interroge
        // directement wp_rk_child_coaches — la même source de vérité que le
        // reste de l'API.
        if ( ! class_exists( 'RKP_CoachStudentRepository' ) || ! RKP_CoachStudentRepository::coach_owns_child( $coach_id, $child_id ) ) {
            return new WP_REST_Response( [ 'code' => 'forbidden' ], 403 );
        }

        $child_wp_uid = RK_Coach_Api_Helpers::get_child_wp_user_id( $child_id );
        if ( ! $child_wp_uid ) {
            return new WP_REST_Response( [ 'code' => 'forbidden' ], 403 );
        }

        $qz = $wpdb->prefix . 'aysquiz_quizes';
        $rp = $wpdb->prefix . 'aysquiz_reports';

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT q.id AS quiz_id, q.title AS quiz_title, q.options,
                    r.score, r.corrects_count, r.questions_count, r.end_date
               FROM {$qz} q
               LEFT JOIN {$rp} r ON r.quiz_id = q.id AND r.user_id = %d
              WHERE q.author_id = %d AND q.quiz_url = %s
              ORDER BY q.create_date DESC, r.end_date DESC",
            $child_wp_uid, $coach_id, 'rk:child:' . $child_id
        ) ) ?: [];

        // One row per quiz (latest attempt if multiple)
        $seen   = [];
        $result = [];
        foreach ( $rows as $row ) {
            $qid = (int) $row->quiz_id;
            if ( isset( $seen[ $qid ] ) ) continue;
            $seen[ $qid ] = true;

            $opts          = json_decode( (string) ( $row->options ?? '{}' ), true ) ?: [];
            $passing_grade = (int) ( $opts['passing_grade'] ?? 80 );
            $attempted     = ! empty( $row->end_date );
            $score         = $attempted ? (int) $row->score : null;

            $result[] = [
                'quiz_id'       => $qid,
                'quiz_title'    => (string) $row->quiz_title,
                'score'         => $score,
                'corrects'      => $attempted ? (int) $row->corrects_count : null,
                'total'         => $attempted ? (int) $row->questions_count : null,
                'passing_grade' => $passing_grade,
                'passed'        => $score !== null ? ( $score >= $passing_grade ) : null,
                'date'          => (string) ( $row->end_date ?? '' ),
                'attempted'     => $attempted,
            ];
        }

        return new WP_REST_Response( array_values( $result ), 200 );
    }

    /* ─── GET /coach/child/quizzes?child_id=N ───────────────────── */

    public static function get_child_quizzes( WP_REST_Request $request ): WP_REST_Response {
        global $wpdb;
        $coach_id = get_current_user_id();
        $child_id = (int) $request->get_param( 'child_id' );
        if ( ! $child_id ) {
            return new WP_REST_Response( [ 'code' => 'missing_child_id' ], 400 );
        }

        $students = RK_Coach_Data::get_coach_students( $coach_id );
        $allowed  = array_map( fn( $s ) => (int) $s->child_id, (array) $students );
        if ( ! in_array( $child_id, $allowed, true ) ) {
            return new WP_REST_Response( [ 'code' => 'not_authorized' ], 403 );
        }

        $qz   = $wpdb->prefix . 'aysquiz_quizes';
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, title, custom_post_id
               FROM {$qz}
              WHERE author_id = %d AND quiz_url = %s
              ORDER BY create_date DESC LIMIT 50",
            $coach_id, 'rk:child:' . $child_id
        ) ) ?: [];

        $result = array_map( static function ( $row ) {
            $url = $row->custom_post_id
                ? (string) ( get_permalink( (int) $row->custom_post_id ) ?: '' )
                : '';
            return [
                'quiz_id'    => (int)    $row->id,
                'quiz_title' => (string) $row->title,
                'quiz_url'   => $url,
            ];
        }, $rows );

        return new WP_REST_Response( array_values( $result ), 200 );
    }

    /* ─── POST /coach/quiz/create — Quiz Maker ───────────────────── */

    public static function create_quiz( WP_REST_Request $request ): WP_REST_Response {
        global $wpdb;
        $coach_id      = get_current_user_id();
        $child_id      = (int) $request->get_param( 'child_id' );
        $course_id     = (int) $request->get_param( 'course_id' );
        $quiz_title    = sanitize_text_field( (string) $request->get_param( 'quiz_title' ) );
        $passing_grade = min( 100, max( 0, (int) ( $request->get_param( 'passing_grade' ) ?: 80 ) ) );
        $questions_raw = (array) ( $request->get_param( 'questions' ) ?: [] );

        // v2.6 (Quiz Flow) — politique d'échéance et de tentatives (optionnels).
        $due_raw      = sanitize_text_field( (string) $request->get_param( 'due_date' ) );
        $due_date     = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $due_raw ) ? $due_raw : '';
        $max_attempts = min( 10, max( 1, (int) ( $request->get_param( 'max_attempts' ) ?: 1 ) ) );

        // v2.7 — course_id devient obligatoire : sans lien réel vers un
        // cours, la page "المغامرة" (rk-adventure.php) ne peut jamais
        // afficher ce quiz dans sa section اختبارات (elle filtre par
        // course_id, voir RKP_LearningQueryService::get_quizzes_with_attempts()
        // → adaptée ci-dessous pour lire aussi les quiz AYS liés).
        if ( ! $child_id || ! $course_id || ! $quiz_title ) {
            return new WP_REST_Response( [ 'code' => 'missing_params' ], 400 );
        }

        $students = RK_Coach_Data::get_coach_students( $coach_id );
        $allowed  = array_map( fn( $s ) => (int) $s->child_id, (array) $students );
        if ( ! in_array( $child_id, $allowed, true ) ) {
            return new WP_REST_Response( [ 'code' => 'not_authorized' ], 403 );
        }

        // v2.7 — le cours choisi doit être un cours RÉEL auquel l'enfant est
        // inscrit (pas un ID arbitraire envoyé par le client) : même garde
        // que celle appliquée partout ailleurs dans le module pour lier
        // une donnée à un enfant/cours (voir RKP_LearningQueryService::is_enrolled()).
        $child_wp_uid = class_exists( 'RK_Coach_Api_Helpers' ) ? RK_Coach_Api_Helpers::get_child_wp_user_id( $child_id ) : 0;
        $is_enrolled  = $child_wp_uid && class_exists( 'RKP_LearningQueryService' )
            ? RKP_LearningQueryService::is_enrolled( $course_id, $child_wp_uid )
            : false;
        if ( ! $is_enrolled ) {
            return new WP_REST_Response( [ 'code' => 'course_not_enrolled' ], 400 );
        }

        $qt = $wpdb->prefix . 'aysquiz_questions';
        $at = $wpdb->prefix . 'aysquiz_answers';
        $qz = $wpdb->prefix . 'aysquiz_quizes';

        $question_ids = [];
        foreach ( $questions_raw as $q_data ) {
            $q_title = sanitize_text_field( (string) ( $q_data['title'] ?? '' ) );
            if ( ! $q_title ) continue;

            $wpdb->insert( $qt, [
                'author_id'              => $coach_id,
                'category_id'            => 1,
                'question'               => $q_title,
                'question_title'         => $q_title,
                'type'                   => 'radio',
                'published'              => 1,
                'create_date'            => current_time( 'mysql' ),
                'not_influence_to_score' => 'off',
                'options'                => '{}',
            ], [ '%d', '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s' ] );

            $question_id = (int) $wpdb->insert_id;
            if ( ! $question_id ) continue;
            $question_ids[] = $question_id;

            $correct_idx = (int) ( $q_data['correct'] ?? 0 );
            foreach ( (array) ( $q_data['options'] ?? [] ) as $ai => $opt ) {
                $opt_text = sanitize_text_field( (string) $opt );
                if ( ! $opt_text ) continue;
                $wpdb->insert( $at, [
                    'question_id' => $question_id,
                    'answer'      => $opt_text,
                    'image'       => '',
                    'correct'     => ( (int) $ai === $correct_idx ) ? 1 : 0,
                    'ordering'    => $ai + 1,
                    'placeholder' => '',
                ], [ '%d', '%s', '%s', '%d', '%d', '%s' ] );
            }
        }

        if ( empty( $question_ids ) ) {
            return new WP_REST_Response( [ 'code' => 'no_valid_questions' ], 400 );
        }

        $quiz_options = [
            // Required keys (accessed without isset in plugin code)
            'form_name'                   => 'off',
            'form_email'                  => 'off',
            'form_phone'                  => 'off',
            'custom_css'                  => '',
            // Functional
            'passing_grade'               => $passing_grade,
            'information_form'            => 'disable',
            'enable_logged_users'         => 'off',
            'enable_correction'           => 'off', // result only at the end
            'enable_progress_bar'         => 'on',
            'enable_next_button'          => 'on',
            'enable_previous_button'      => 'on',  // child can go back and change answer
            'enable_restart_button'       => ( $max_attempts > 1 ? 'on' : 'off' ), // v2.6 — politique de tentatives
            'rk_due_date'                 => $due_date,      // v2.6 — échéance affichée côté enfant
            'rk_max_attempts'             => $max_attempts,  // v2.6 — plafond appliqué côté enfant
            'rk_course_id'                => $course_id,     // v2.7 — lien vers le cours (page rk-adventure.php)
            'enable_questions_result'     => 'off', // no per-question breakdown
            'enable_questions_counter'    => 'on',
            'randomize_questions'         => 'off',
            'randomize_answers'           => 'off',
            'enable_early_finish'         => 'off',
            'hide_score'                  => 'off',
            'enable_result'               => 'off',
            'enable_timer'                => 'off',
            'disable_store_data'          => 'off',
            'enable_exit_button'          => 'off',
            'enable_social_buttons'       => 'off',
            'redirect_after_submit'       => 'off',
            // Visual — child-friendly palette (orange/purple RiadaKids brand)
            'quiz_theme'                  => 'classic_light',
            'color'                       => '#E8500A',
            'bg_color'                    => '#fffbf7',
            'text_color'                  => '#1e293b',
            'quiz_border_radius'          => '20',
            'enable_box_shadow'           => 'on',
            'box_shadow_color'            => '#fbd38d',
            'enable_border'               => 'off',
            'answers_font_size'           => '16',
            'quiz_title_font_size'        => 22,
            'quiz_content_max_width'      => 96,
            'quiz_content_mobile_max_width' => 98,
            'create_date'                 => current_time( 'mysql' ),
        ];

        $wpdb->insert( $qz, [
            'author_id'        => $coach_id,
            'title'            => $quiz_title,
            'description'      => '',
            'quiz_category_id' => 1,
            'question_ids'     => implode( ',', $question_ids ),
            'ordering'         => 0,
            'quiz_url'         => 'rk:child:' . $child_id,
            'published'        => 1,
            'create_date'      => current_time( 'mysql' ),
            'options'          => wp_json_encode( $quiz_options ),
        ], [ '%d', '%s', '%s', '%d', '%s', '%d', '%s', '%d', '%s', '%s' ] );

        $quiz_id = (int) $wpdb->insert_id;
        if ( ! $quiz_id ) {
            return new WP_REST_Response( [ 'code' => 'db_error' ], 500 );
        }

        $post_id = wp_insert_post( [
            'post_title'   => $quiz_title,
            'post_author'  => $coach_id,
            'post_type'    => 'ays-quiz-maker',
            'post_content' => '[ays_quiz id="' . $quiz_id . '"]',
            'post_status'  => 'publish',
            'post_date'    => current_time( 'mysql' ),
        ] );

        $quiz_url = '';
        if ( $post_id && ! is_wp_error( $post_id ) ) {
            $wpdb->update( $qz, [ 'custom_post_id' => (int) $post_id ], [ 'id' => $quiz_id ], [ '%d' ], [ '%d' ] );
            $quiz_url = (string) ( get_permalink( (int) $post_id ) ?: '' );

            /* v2.6.1 — le lien envoyé à l'enfant porte son token de session :
             * la page quiz l'authentifie comme ENFANT (pas le cookie parent)
             * et la tentative est correctement attribuée. */
            $child_wp_id = RK_Coach_Api_Helpers::get_child_wp_user_id( $child_id );
            if ( $quiz_url && $child_wp_id && class_exists( 'RK_Session_Manager' ) ) {
                $parent_uid = (int) RKP_ChildRepository::get_parent_user_id( $child_id );
                $rk_token   = RK_Session_Manager::create_tab_session( (int) $child_wp_id, $parent_uid );
                if ( $rk_token ) {
                    $quiz_url = add_query_arg( 'rk_tab', $rk_token, $quiz_url );
                }
            }
        }

        // Email child with quiz link
        if ( $quiz_url ) {
            $child_wp_id = $child_wp_id ?: RK_Coach_Api_Helpers::get_child_wp_user_id( $child_id );
            if ( $child_wp_id ) {
                $child_user = get_userdata( $child_wp_id );
                if ( $child_user && $child_user->user_email ) {
                    $coach_user = get_userdata( $coach_id );
                    $coach_name = $coach_user ? $coach_user->display_name : '';
                    $c_subject  = 'اختبار جديد بانتظارك 📝 — ' . $quiz_title;
                    $c_body     = '<div dir="rtl" style="font-family:Tajawal,Arial,sans-serif;max-width:520px;margin:auto;padding:24px;">'
                                . '<h2 style="color:#FF4411;margin-bottom:8px;">اختبار جديد! 🎉</h2>'
                                . '<p>مرحباً <strong>' . esc_html( $child_user->display_name ) . '</strong>،</p>'
                                . '<p>أرسل إليك مدربك <strong>' . esc_html( $coach_name ) . '</strong> اختباراً جديداً:</p>'
                                . '<h3 style="color:#4C95D7;margin:12px 0;">' . esc_html( $quiz_title ) . '</h3>'
                                . '<p><a href="' . esc_url( $quiz_url ) . '" '
                                . 'style="display:inline-block;padding:12px 28px;background:#FF4411;color:#fff;'
                                . 'border-radius:25px;text-decoration:none;font-weight:700;font-size:1rem;">ابدأ الاختبار ←</a></p>'
                                . '<p style="color:#6b7280;font-size:12px;margin-top:16px;">'
                                . 'سجّل الدخول إلى حسابك أولاً حتى تُحسب نتيجتك.</p>'
                                . '</div>';
                    wp_mail( $child_user->user_email, $c_subject, $c_body, [ 'Content-Type: text/html; charset=UTF-8' ] );
                }
            }
        }

        // v2.6 (Quiz Flow) — Domain Event `quiz.assigned` + notifications in-app
        // enfant + parent (l'email enfant ci-dessus est conservé).
        $child_wp_id = $child_wp_id ?? RK_Coach_Api_Helpers::get_child_wp_user_id( $child_id );
        if ( class_exists( 'RKP_QuizFlowCommandService' ) ) {
            RKP_QuizFlowCommandService::announce_assignment(
                (int) $child_wp_id, $child_id, $quiz_id, $coach_id, $quiz_title, $due_date
            );
        }

        // Invalidate child dashboard cache so the new quiz appears immediately
        if ( class_exists( 'RK_MC_Child_Dashboard_Data' ) ) {
            RK_MC_Child_Dashboard_Data::clear_cache( $child_id );
        }

        return new WP_REST_Response( [
            'success'      => true,
            'quiz_id'      => $quiz_id,
            'quiz_url'     => $quiz_url,
            'due_date'     => $due_date,
            'max_attempts' => $max_attempts,
        ], 201 );
    }

}