<?php
declare( strict_types=1 );
/**
 * RK_Coach_Students_Controller  (v2.4.0 — Audit P3-14)
 *
 * Élèves du coach : liste, fiche détaillée, évaluations, compétences.
 * Extrait du God Service RK_Coach_API (2 527 lignes) — corps des méthodes
 * strictement inchangé ; seuls les helpers partagés pointent désormais
 * vers RK_Coach_Api_Helpers. Le routing et l'auth restent dans RK_Coach_API.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class RK_Coach_Students_Controller {

    public static function get_students(): WP_REST_Response {
        $coach_id = get_current_user_id();
        $students = RK_Coach_Data::get_coach_students( $coach_id );
        $result   = [];
        foreach ( (array) $students as $st ) {
            $child_id    = (int) $st->child_id;
            $child_wp_id = RK_Coach_Api_Helpers::get_child_wp_user_id( $child_id );
            $age         = (int) ( $st->child_age ?? 0 );
            $result[] = [
                'id'               => $child_id,
                'name'             => (string) ( $st->child_name ?? '' ),
                // v9.24 — nom de famille affiché à côté du prénom sur
                // la carte élève (#students).
                'family_name'      => (string) ( $st->child_family_name ?? '' ),
                'age'              => $age,
                'avatar'           => (string) ( $st->avatar_url ?? '' ),
                'last_booking'     => (string) ( $st->last_booking ?? '' ),
                'is_primary'       => (bool)   ( $st->is_primary ?? false ),
                'attendance'       => RK_Coach_Data::get_child_attendance_rate( $child_id, $coach_id ),
                'absences'         => RK_Coach_Data::get_consecutive_absences( $child_id ),
                'next_booking'     => RK_Coach_Api_Helpers::get_next_child_booking( $child_id ),
                'enrolled_courses' => RK_Coach_Api_Helpers::get_child_enrolled_courses( $child_id ),
            ];
        }
        return new WP_REST_Response( $result, 200 );
    }

    /** Prochaine séance confirmée d'un enfant (à partir de maintenant). */
    public static function get_student( WP_REST_Request $request ): WP_REST_Response {
        $coach_id = get_current_user_id();
        $child_id = (int) $request->get_param( 'child_id' );
        if ( ! $child_id ) {
            return new WP_REST_Response( [ 'code' => 'missing_child_id' ], 400 );
        }
        $students = RK_Coach_Data::get_coach_students( $coach_id );
        $found    = null;
        foreach ( (array) $students as $st ) {
            if ( (int) $st->child_id === $child_id ) { $found = $st; break; }
        }
        if ( ! $found ) {
            return new WP_REST_Response( [ 'code' => 'not_authorized' ], 403 );
        }
        $child_wp_id = RK_Coach_Api_Helpers::get_child_wp_user_id( $child_id );
        $sessions    = RK_Coach_Api_Helpers::get_child_sessions_detail( $child_id );
        $upcoming    = array_values( array_filter( $sessions, fn( $s ) => (int) $s['is_upcoming'] === 1 ) );
        $age         = (int) ( $found->child_age ?? 0 );
        return new WP_REST_Response( [
            'id'               => $child_id,
            'name'             => (string) $found->child_name,
            'family_name'      => (string) ( $found->child_family_name ?? '' ),
            'age'              => $age,
            'avatar'           => (string) ( $found->avatar_url ?? '' ),
            'attendance'       => RK_Coach_Data::get_child_attendance_rate( $child_id, $coach_id ),
            'absences'         => RK_Coach_Data::get_consecutive_absences( $child_id ),
            'upcoming_count'   => count( $upcoming ),
            'next_booking'     => RK_Coach_Api_Helpers::get_next_child_booking( $child_id ),
            'enrolled_courses' => RK_Coach_Api_Helpers::get_child_enrolled_courses( $child_id ),
            'sessions'         => $sessions,
        ], 200 );
    }

    /** Retourne les bookings d'un enfant pour ce coach : upcoming (ASC) puis past (DESC). */
    public static function get_child_evals( WP_REST_Request $request ): WP_REST_Response {
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
        $evals  = RK_Coach_Data::get_child_assessments( $child_id, 20 );
        $result = array_map( static function ( $ev ) {
            return [
                'id'          => (int) ( $ev->id ?? 0 ),
                'assessed_at' => (string) ( $ev->assessed_at ?? '' ),
                'rating'      => (int) ( $ev->overall_rating ?? $ev->rating ?? 0 ),
                'summary'     => (string) ( $ev->summary ?? $ev->notes ?? '' ),
                'strengths'   => (string) ( is_array( $ev->strengths ?? null )
                                    ? implode( "\n", $ev->strengths )
                                    : ( $ev->strengths ?? '' ) ),
                'developments'=> (string) ( is_array( $ev->developments ?? null )
                                    ? implode( "\n", $ev->developments )
                                    : ( $ev->developments ?? '' ) ),
            ];
        }, $evals );

        return new WP_REST_Response( array_values( $result ), 200 );
    }

    /* ─── GET /coach/child/badges ───────────────────────────────── */

    /**
     * Catalogue complet des شارات pour un enfant, avec statut earned/locked.
     * Réutilise RKP_BadgeQueryService (même source de vérité que la page
     * Tutor native RK_Coach_Badges) — aucune duplication de logique.
     */
    public static function get_child_badges( WP_REST_Request $request ): WP_REST_Response {
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

        $catalogue = RKP_BadgeQueryService::get_catalogue_for_child( $child_id );
        $result    = array_map( static function ( $b ) {
            return [
                'key'        => (string) $b['key'],
                'name'       => (string) $b['name'],
                'desc'       => (string) $b['desc'],
                'cat'        => (string) $b['cat'],
                'icon_key'   => (string) $b['icon_key'],
                'earned'     => (bool) $b['earned'],
                'earned_at'  => (string) ( $b['earned_at'] ?? '' ),
                'custom'     => (bool) ( $b['custom'] ?? false ),
            ];
        }, $catalogue );

        return new WP_REST_Response( array_values( $result ), 200 );
    }

    /* ─── GET /coach/child/courses ───────────────────────────────── */

    /**
     * Cours distincts (course_id/course_name) auxquels cet enfant est
     * inscrit avec CE coach, déduits de ses séances (wp_rk_bookings).
     * Alimente le filtre "الدورة" sur la page التقييمات et le sélecteur
     * de cours du formulaire تقييم جديد, pour qu'un enfant suivant
     * plusieurs cours avec le même coach ne mélange pas ses évaluations
     * ni ses séances entre cours différents.
     */
    public static function get_child_courses( WP_REST_Request $request ): WP_REST_Response {
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

        global $wpdb;
        $bt = $wpdb->prefix . 'rk_bookings';
        $has_coach_id = (bool) $wpdb->get_var( "SHOW COLUMNS FROM `{$bt}` LIKE 'coach_id'" );

        $rows = $has_coach_id
            ? $wpdb->get_results( $wpdb->prepare(
                "SELECT DISTINCT b.course_id, p.post_title AS course_name
                   FROM {$bt} b
              LEFT JOIN {$wpdb->posts} p ON p.ID = b.course_id
                  WHERE b.child_id = %d AND b.coach_id = %d
                    AND b.course_id > 0 AND b.status != 'cancelled'
               ORDER BY p.post_title ASC",
                $child_id, $coach_id
            ) )
            : $wpdb->get_results( $wpdb->prepare(
                "SELECT DISTINCT b.course_id, p.post_title AS course_name
                   FROM {$bt} b
              LEFT JOIN {$wpdb->posts} p ON p.ID = b.course_id
                  WHERE b.child_id = %d
                    AND b.course_id > 0 AND b.status != 'cancelled'
               ORDER BY p.post_title ASC",
                $child_id
            ) );
        $rows = $rows ?: [];

        $result = array_map( static function ( $r ) {
            return [
                'course_id'   => (int) $r->course_id,
                'course_name' => (string) ( $r->course_name ?: __( 'دورة', 'rk-coach-hub' ) ),
            ];
        }, $rows );

        return new WP_REST_Response( array_values( $result ), 200 );
    }

    /* ─── GET /coach/child/bookings-for-date ────────────────────── */

    /**
     * v9.36 — Séances (wp_rk_bookings) de cet enfant pour ce coach,
     * alimente le sélecteur "اللقاء" du formulaire تقييم جديد pour que
     * l'évaluation se lie à une séance précise (booking_id) plutôt qu'à
     * une seule date (voir doc de migration dans
     * RKP_AssessmentRepository::ensure_schema()).
     *
     * AJOUT (demande utilisateur) : le paramètre 'date' devient
     * optionnel. Le coach ne choisit plus une date à l'avance pour
     * filtrer les séances (UX confuse — nécessitait de deviner le bon
     * jour) ; il choisit directement un cours (course_id), et cette
     * route retourne TOUTES les séances réservées de l'enfant pour ce
     * cours (peu importe la date), chacune affichant déjà sa propre
     * date/heure dans son libellé ('label'). Si 'date' est fourni
     * (rétro-compatibilité), le filtre par date exacte s'applique comme
     * avant.
     */
    public static function get_child_bookings_for_date( WP_REST_Request $request ): WP_REST_Response {
        $coach_id  = get_current_user_id();
        $child_id  = (int) $request->get_param( 'child_id' );
        $date      = sanitize_text_field( (string) $request->get_param( 'date' ) );
        $course_id = (int) $request->get_param( 'course_id' );

        if ( ! $child_id ) {
            return new WP_REST_Response( [ 'code' => 'invalid_params' ], 400 );
        }
        $students = RK_Coach_Data::get_coach_students( $coach_id );
        $allowed  = array_map( fn( $s ) => (int) $s->child_id, (array) $students );
        if ( ! in_array( $child_id, $allowed, true ) ) {
            return new WP_REST_Response( [ 'code' => 'not_authorized' ], 403 );
        }

        global $wpdb;
        $bt = $wpdb->prefix . 'rk_bookings';
        $has_coach_id = (bool) $wpdb->get_var( "SHOW COLUMNS FROM `{$bt}` LIKE 'coach_id'" );

        $where_date   = $date ? ' AND DATE(b.appointment) = %s' : '';
        $where_course = $course_id ? ' AND b.course_id = %d' : '';
        $params_base  = $has_coach_id ? [ $child_id, $coach_id ] : [ $child_id ];
        if ( $date )      $params_base[] = $date;
        if ( $course_id ) $params_base[] = $course_id;
        $params = $params_base;

        // Sans filtre date : limite raisonnable + tri des plus récentes
        // en premier (les séances passées récentes sont plus probables
        // à évaluer que les très anciennes).
        $limit_clause = $date ? '' : ' LIMIT 50';
        $order        = $date ? 'ASC' : 'DESC';

        $sql = $has_coach_id
            ? "SELECT booking_id, appointment, session_name, course_id
                 FROM {$bt} b
                WHERE b.child_id = %d AND b.coach_id = %d
                  AND b.status != 'cancelled'{$where_date}{$where_course}
             ORDER BY b.appointment {$order}{$limit_clause}"
            : "SELECT booking_id, appointment, session_name, course_id
                 FROM {$bt} b
                WHERE b.child_id = %d
                  AND b.status != 'cancelled'{$where_date}{$where_course}
             ORDER BY b.appointment {$order}{$limit_clause}";

        $rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ) ) ?: [];

        $result = array_map( static function ( $b ) {
            return [
                'booking_id' => (int) $b->booking_id,
                'course_id'  => (int) ( $b->course_id ?? 0 ),
                'date'       => $b->appointment ? date_i18n( 'Y-m-d', strtotime( (string) $b->appointment ) ) : '',
                'label'      => trim( ( $b->session_name ?: __( 'لقاء', 'rk-coach-hub' ) )
                    . ' — ' . date_i18n( 'd/m/Y H:i', strtotime( (string) $b->appointment ) ) ),
            ];
        }, $rows );

        return new WP_REST_Response( array_values( $result ), 200 );
    }

    /* ─── POST /coach/child/eval ────────────────────────────────── */

    public static function save_child_eval( WP_REST_Request $request ): WP_REST_Response {
        $coach_id  = get_current_user_id();
        $child_id  = (int) $request->get_param( 'child_id' );
        $booking_id     = (int) $request->get_param( 'booking_id' );
        $rating         = min( 5, max( 1, (int) $request->get_param( 'rating' ) ) );
        $summary        = sanitize_textarea_field( (string) $request->get_param( 'summary' ) );
        $strengths      = sanitize_textarea_field( (string) $request->get_param( 'strengths' ) );
        $develop        = sanitize_textarea_field( (string) $request->get_param( 'developments' ) );
        $parent_message = sanitize_textarea_field( (string) $request->get_param( 'parent_message' ) );
        $date           = sanitize_text_field( (string) ( $request->get_param( 'assessed_at' ) ?: current_time( 'Y-m-d' ) ) );

        if ( ! $child_id || $rating < 1 ) {
            return new WP_REST_Response( [ 'code' => 'invalid_params' ], 400 );
        }
        $students = RK_Coach_Data::get_coach_students( $coach_id );
        $allowed  = array_map( fn( $s ) => (int) $s->child_id, (array) $students );
        if ( ! in_array( $child_id, $allowed, true ) ) {
            return new WP_REST_Response( [ 'code' => 'not_authorized' ], 403 );
        }

        // v3.1.3 — le SPA (rk-coach-page-evals.js) envoie déjà un
        // skill_scores { key: 1..5 } par compétence (étoiles), mais il
        // était ignoré ici : 'skill_scores' => [] codé en dur, et
        // RK_MC_Skill_Service::set_skill() n'était jamais appelée. Les
        // barres de compétences de /my-account/rk-rapport/ (alimentées
        // par RKP_SkillRepository) restaient donc figées à 0% quoi que
        // note le coach. On valide, on persiste dans l'évaluation, et on
        // pousse chaque note vers RK_MC_Skill_Service (échelle 1-5 → 0-10,
        // même conversion que l'ancien flux admin ajax_save_skills()).
        $skill_scores_raw   = (array) ( $request->get_param( 'skill_scores' ) ?: [] );
        $valid_skill_keys   = class_exists( 'RK_MC_Skill_Service' ) ? array_keys( RK_MC_Skill_Service::definitions() ) : [];
        $skill_scores       = [];
        foreach ( $skill_scores_raw as $key => $score ) {
            $key = sanitize_key( (string) $key );
            if ( ! in_array( $key, $valid_skill_keys, true ) ) continue;
            $skill_scores[ $key ] = min( 5, max( 1, (int) $score ) );
        }

        // CORRECTIF — lien direct vers la séance évaluée (booking_id).
        // Sans ce lien, le rapport parent (/my-account/rk-rapport/,
        // vue détail par séance) ne retrouvait l'évaluation que par un
        // repli (child_id, date, coach_id) : si aucune séance réservée
        // n'existait exactement à cette date pour ce coach — cas fréquent
        // quand le coach ne fait que taper une date sans séance liée, ou
        // si 2 séances existent le même jour — "ملاحظة المدرب" et les
        // "نتائج الاختبارات" restaient vides côté parent alors que le
        // coach avait bien publié un تقييم. Voir doc de migration dans
        // RKP_AssessmentRepository::ensure_schema(). create_or_update_for_booking()
        // retombe sur create() simple quand booking_id = 0 (comportement
        // inchangé pour les évaluations non liées à une séance précise).
        $eval_data = [
            'coach_id'       => $coach_id,
            'rating'         => $rating,
            'summary'        => $summary,
            'strengths'      => array_values( array_filter( array_map( 'trim', explode( "\n", $strengths ) ) ) ),
            'developments'   => array_values( array_filter( array_map( 'trim', explode( "\n", $develop ) ) ) ),
            'notes'          => '',
            'skill_scores'   => $skill_scores,
            'parent_message' => $parent_message,
        ];
        if ( $booking_id && class_exists( 'RK_MC_Assessment_Service' ) ) {
            RK_MC_Assessment_Service::create_or_update_for_booking( $booking_id, $child_id, $date, $coach_id, $eval_data );
        } else {
            RKP_AssessmentCommandService::create( array_merge( $eval_data, [
                'child_id'    => $child_id,
                'assessed_at' => $date,
            ] ) );
        }

        if ( $skill_scores && class_exists( 'RK_MC_Skill_Service' ) ) {
            foreach ( $skill_scores as $key => $score ) {
                RK_MC_Skill_Service::set_skill( $child_id, $key, $score * 2 );
            }
        }

        delete_transient( 'rk_ch_pending_evals_' . $coach_id );
        if ( class_exists( 'RK_MC_Child_Dashboard_Data' ) ) {
            RK_MC_Child_Dashboard_Data::clear_cache( $child_id );
        }
        return new WP_REST_Response( [ 'success' => true ], 201 );
    }

    /* ─── POST /coach/message ───────────────────────────────────── */

    /**
     * Toutes les évaluations des élèves du coach — alimente la page
     * التقييمات (liste globale, rk-coach-page-evals.js).
     *
     * AJOUT (demande utilisateur) — expose désormais booking_id, course_id
     * et course_name par évaluation (résolus via JOIN sur wp_rk_bookings
     * quand booking_id > 0), pour permettre au coach de filtrer par
     * enfant ET par cours quand un enfant suit plusieurs cours — sans
     * ce filtre, un enfant multi-cours mélangeait toutes ses évaluations
     * dans une seule liste indifférenciée.
     */
    public static function get_evals(): WP_REST_Response {
        $coach_id = get_current_user_id();
        $students = RK_Coach_Data::get_coach_students( $coach_id );
        if ( empty( $students ) ) {
            return new WP_REST_Response( [], 200 );
        }

        $avatar_map = [];
        $name_map   = [];
        foreach ( (array) $students as $st ) {
            $cid              = (int) $st->child_id;
            $avatar_map[$cid] = (string) ( $st->avatar_url ?? '' );
            $name_map[$cid]   = trim( (string) ( $st->child_name ?? '' ) . ' ' . (string) ( $st->child_family_name ?? '' ) );
        }

        global $wpdb;
        $bt = $wpdb->prefix . 'rk_bookings';

        $all = [];
        foreach ( array_keys( $name_map ) as $child_id ) {
            $evals = RK_Coach_Data::get_child_assessments( $child_id, 50 );
            foreach ( (array) $evals as $ev ) {
                $str = $ev->strengths    ?? null;
                $dev = $ev->developments ?? null;

                $booking_id  = (int) ( $ev->booking_id ?? 0 );
                $course_id   = 0;
                $course_name = '';
                if ( $booking_id ) {
                    $b = $wpdb->get_row( $wpdb->prepare(
                        "SELECT b.course_id, p.post_title AS course_name
                           FROM {$bt} b
                      LEFT JOIN {$wpdb->posts} p ON p.ID = b.course_id
                          WHERE b.booking_id = %d",
                        $booking_id
                    ) );
                    if ( $b ) {
                        $course_id   = (int) $b->course_id;
                        $course_name = (string) ( $b->course_name ?? '' );
                    }
                }

                $all[] = [
                    'id'           => (int) ( $ev->id ?? 0 ),
                    'child_id'     => (int) $child_id,
                    'child_name'   => $name_map[ $child_id ],
                    'child_avatar' => $avatar_map[ $child_id ],
                    'booking_id'   => $booking_id,
                    'course_id'    => $course_id,
                    'course_name'  => $course_name,
                    'assessed_at'  => substr( (string) ( $ev->assessed_at ?? '' ), 0, 10 ),
                    'rating'       => (int) ( $ev->overall_rating ?? $ev->rating ?? 0 ),
                    'summary'      => (string) ( $ev->summary ?? $ev->notes ?? '' ),
                    'strengths'    => is_array( $str ) ? implode( "\n", $str ) : (string) ( $str ?: '' ),
                    'developments' => is_array( $dev ) ? implode( "\n", $dev ) : (string) ( $dev ?: '' ),
                ];
            }
        }

        usort( $all, static fn( $a, $b ) => strcmp( $b['assessed_at'], $a['assessed_at'] ) );
        return new WP_REST_Response( $all, 200 );
    }

    /* ─── GET /coach/quiz/{id} — preview quiz (Quiz Maker) ─────── */

    public static function save_child_skills( WP_REST_Request $request ): WP_REST_Response {
        $coach_id = get_current_user_id();
        $child_id = (int) $request->get_param( 'child_id' );
        $skills   = (array) ( $request->get_param( 'skills' ) ?: [] );

        if ( ! $child_id || empty( $skills ) ) {
            return new WP_REST_Response( [ 'code' => 'invalid_params' ], 400 );
        }
        $students = RK_Coach_Data::get_coach_students( $coach_id );
        $allowed  = array_map( fn( $s ) => (int) $s->child_id, (array) $students );
        if ( ! in_array( $child_id, $allowed, true ) ) {
            return new WP_REST_Response( [ 'code' => 'not_authorized' ], 403 );
        }

        foreach ( $skills as $skill_key => $level ) {
            $key = sanitize_key( (string) $skill_key );
            $lvl = min( 10, max( 0, (int) $level ) );
            if ( $key ) {
                RKP_SkillRepository::set_skill( $child_id, $key, $lvl );
            }
        }
        if ( class_exists( 'RK_MC_Child_Dashboard_Data' ) ) {
            RK_MC_Child_Dashboard_Data::clear_cache( $child_id );
        }
        return new WP_REST_Response( [ 'success' => true ], 200 );
    }
}