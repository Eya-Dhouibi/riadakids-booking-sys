<?php
declare( strict_types=1 );
/**
 * RK_MC_Child_Dashboard_Data  (v7.0.0 — Sprint 4)
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * RÔLE
 * ─────────────────────────────────────────────────────────────────────────────
 * Couche de fourniture de données pour le Child Dashboard.
 *
 * Architecture :
 *   Child Dashboard
 *   ↓
 *   get_dashboard_data( $child_id, $child )
 *   ↓
 *   Tutor LMS + WooCommerce Bookings + RK Children DB + Gamification DB
 *   ↓
 *   Fallback neutre uniquement si donnée absente
 *
 * RÈGLES
 * ─────────────────────────────────────────────────────────────────────────────
 *   - Toutes les appels de services sont défensifs (class_exists / function_exists).
 *   - Aucun mock data utilisé — uniquement des valeurs neutres comme fallback.
 *   - Le template consomme toujours le même format de sortie.
 *
 * @package RK_My_Children
 * @since   7.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class RK_MC_Child_Dashboard_Data {

    private const CACHE_PREFIX = 'rk_mc_dash_';
    private const CACHE_TTL    = 180; // 3 minutes

    /* ─── Cache helpers ─────────────────────────────────────────────── */

    public static function clear_cache( int $child_id ): void {
        delete_transient( self::CACHE_PREFIX . $child_id );
    }

    /**
     * Retourne le payload complet pour le dashboard enfant.
     * Toutes les sections sauf messages sont mises en cache 3 minutes.
     * Les messages sont toujours récupérés frais (mark-as-read requis).
     *
     * @param int         $child_id  ID de l'enfant.
     * @param object|null $child     Enregistrement wp_rk_children (optionnel).
     * @return array
     */
    public static function get_dashboard_data( int $child_id, ?object $child = null ): array {
        $cache_key = self::CACHE_PREFIX . $child_id;
        $cached    = get_transient( $cache_key );
        if ( false !== $cached ) {
            $cached['messages'] = self::fetch_and_build_messages( $child_id );
            return $cached;
        }

        /* ── 1. Profil enfant ──────────────────────────────────── */
        $display_name = '';
        $avatar_url   = '';
        if ( $child ) {
            $display_name = RK_MC_Child_Dashboard_Service::get_display_name( $child );
            $avatar_url   = RK_MC_Child_Dashboard_Service::get_avatar_url( $child );
        }

        /* ── 2. Gamification (points + niveau) ─────────────────── */
        $has_gami     = class_exists( 'RK_MC_Gamification_Service' );
        $total_points = $has_gami ? RK_MC_Gamification_Service::get_total_points( $child_id ) : 0;
        $level_data   = $has_gami
            ? RK_MC_Gamification_Service::compute_level( $total_points )
            : self::default_level();

        /* ── 3. Message de bienvenue contextuel ────────────────── */
        $welcome = ( $has_gami && $display_name )
            ? RK_MC_Gamification_Service::get_welcome_message( $child_id, $display_name )
            : array( 'text' => '', 'type' => 'default', 'icon_key' => 'sun' );

        /* ── 4. Badges (catalogue complet + état gagné/verrouillé) */
        $has_badges      = class_exists( 'RK_MC_Badge_Service' );
        $badge_catalogue = $has_badges
            ? RK_MC_Badge_Service::get_catalogue_for_child( $child_id )
            : array();
        $earned_count    = count( array_filter( $badge_catalogue, static fn( $b ) => $b['earned'] ) );
        $total_badges    = $has_badges ? count( RK_MC_Badge_Service::catalogue() ) : 0;

        /* ── 5. Missions hebdomadaires (réelles) ───────────────── */
        $has_missions = class_exists( 'RK_MC_Mission_Service' );
        $raw_missions = $has_missions
            ? RK_MC_Mission_Service::get_weekly_missions( $child_id )
            : array();

        /* ── 5b. Missions assignées par le coach ────────────────── */
        $coach_missions = class_exists( 'RK_Coach_Missions' )
            ? RK_Coach_Missions::get_missions_for_child( $child_id )
            : array();

        /* ── 6. Compétences / Pouvoirs (réels, gérés par coach) ── */
        $has_skills = class_exists( 'RK_MC_Skill_Service' );
        $raw_skills = $has_skills
            ? RK_MC_Skill_Service::get_skills( $child_id )
            : array();

        /* ── 7. Prochaine session (table wp_rk_bookings) ────────── */
        $upcoming = RK_MC_Child_Dashboard_Service::get_upcoming_session( $child_id );

        /* ── 8a. Cours Tutor LMS (source unique — learning journey) ── */
        $uid           = ! empty( $child->wp_user_id ) ? (int) $child->wp_user_id : 0;
        $tutor_courses = $uid ? RK_Tutor_Course_Service::get_enrolled_courses( $uid ) : [];
        $tutor_stats   = $uid ? RK_Tutor_Course_Service::get_stats( $uid ) : [];

        /* ── 8b. Hero CTA — premier cours non terminé ──────────────── */
        $hero_raw  = $uid ? RK_Tutor_Course_Service::get_next_course_to_continue( $uid ) : [];
        $hero_next = [
            'url'   => $hero_raw['url']   ?? '',
            'title' => $hero_raw['title'] ?? '',
        ];

        /* ── 8c. Prochaine leçon à continuer ───────────────────────── */
        $current_lesson = null;
        foreach ( $tutor_courses as $tc ) {
            if ( ! empty( $tc['next_lesson'] ) ) {
                $current_lesson = array_merge(
                    $tc['next_lesson'],
                    [ 'course_title' => $tc['title'] ]
                );
                break;
            }
        }

        /* ── 9. Calendrier — séances depuis wp_rk_bookings ─────────── */
        $calendar_sessions = RK_Booking_Calendar_Service::get_upcoming( $child_id, 5 );

        /* ── 8d. Quiz Tutor LMS ─────────────────────────────────────── */
        $raw_quizzes = $uid ? RK_Tutor_Course_Service::get_all_quizzes_for_user( $uid ) : [];

        /* ── 8e. Certificats coach ──────────────────────────────── */
        $raw_certificates = self::fetch_certificates( $child_id );

        /* ── 9. Assessment (bilan du coach) ─────────────────────── */
        $has_assessment  = class_exists( 'RK_MC_Assessment_Service' );
        $latest_bilan    = $has_assessment ? RK_MC_Assessment_Service::get_latest( $child_id ) : null;
        $bilan_history   = ( $has_assessment && $latest_bilan )
            ? RK_MC_Assessment_Service::get_all( $child_id, true )  /* skip first = latest */
            : [];
        $previous_bilan  = $has_assessment ? self::get_previous_bilan( $child_id ) : null;

        /* ── 10. Messages: récupérés séparément (non cachés) ─────── */
        // (traité après le cache pour garantir mark-as-read en temps réel)

        /* ════ COMPOSITION DES SECTIONS ════════════════════════════ */

        $all_levels       = $has_gami ? RK_MC_Gamification_Service::levels() : array();
        $next_level_num   = $level_data['num'] + 1;
        $next_level_label = isset( $all_levels[ $next_level_num ] ) ? $all_levels[ $next_level_num ]['label'] : '';
        $pts_to_next      = $level_data['pts_to_next'] ?? 0;

        /* Section : enfant */
        $child_section = array(
            'name'           => $display_name ?: __( 'الطالب', 'rk-my-children' ),
            'avatar'         => $avatar_url,
            'level'          => $level_data['num'],
            'level_progress' => $level_data['progress'],
            'points'         => $total_points,
            'title'          => $level_data['label'],
            'next_mission'   => self::get_next_mission_title( $raw_missions ),
            'streak'         => '',
            'goal'           => array(
                'title'       => __( 'هدف الأسبوع', 'rk-my-children' ),
                'description' => self::get_weekly_goal_text( $raw_missions ),
                'reward'      => sprintf( __( '+%d نقطة', 'rk-my-children' ), 30 ),
                'cta'         => __( 'ابدأ الآن', 'rk-my-children' ),
                'status'      => 'active',
            ),
            'avatar_stage'      => $level_data['label'],
            'next_stage'        => $next_level_label,
            'next_course_url'   => $hero_next['url'],
            'next_course_title' => $hero_next['title'],
            'current_lesson'    => $current_lesson,
            'stats'             => $tutor_stats,
        );

        /* Section : parcours */
        $journey_section = array(
            'title'           => __( 'مغامرتك RiadaKids', 'rk-my-children' ),
            'level'           => $level_data['num'],
            'current_xp'      => $total_points,
            'xp_to_next'      => $pts_to_next,
            'progress_pct'    => $level_data['progress'],
            'current_tier'    => $level_data['label'],
            'next_tier'       => $next_level_label,
            'badge_collected' => $earned_count,
            'badge_total'     => $total_badges,
            'next_badge'      => self::get_next_badge_hint( $badge_catalogue ),
            'xp_message'      => $pts_to_next > 0
                ? sprintf( __( 'فقط %d نقطة لتصبح %s', 'rk-my-children' ), $pts_to_next, $next_level_label )
                : __( 'وصلت للمستوى الأعلى!', 'rk-my-children' ),
        );

        $built_courses = self::build_courses( $tutor_courses, $child_id );

        $core = array(
            'child'        => $child_section,
            'journey'      => $journey_section,
            'courses'      => $built_courses,
            // Alias 'adventures' maintenu pour compatibilité ascendante des templates existants
            'adventures'   => $built_courses,
            'session'      => self::build_session( $upcoming ),
            'missions'     => self::build_missions( $raw_missions, $coach_missions ),
            'powers'       => self::build_powers( $raw_skills ),
            'badges'       => self::build_badges( $badge_catalogue ),
            'welcome'      => $welcome,
            'assessment'   => self::build_assessment( $latest_bilan, $previous_bilan, $bilan_history ),
            'quizzes'      => $raw_quizzes,
            'certificates' => $raw_certificates,
            'calendar'     => $calendar_sessions,
            'stats'        => $tutor_stats,
        );

        set_transient( $cache_key, $core, self::CACHE_TTL );

        $core['messages'] = self::fetch_and_build_messages( $child_id );
        return $core;
    }

    /* ════════════════════════════════════════════════════════════
       HELPERS PRIVÉS
       ════════════════════════════════════════════════════════════ */

    /** Niveau neutre si Gamification_Service est absent. */
    private static function default_level(): array {
        return array(
            'num'        => 1,
            'label'      => __( 'شرارة', 'rk-my-children' ),
            'progress'   => 0,
            'pts_to_next' => 100,
            'next_min'   => 100,
        );
    }

    /**
     * Construit la section "دوراتي" depuis les cours Tutor LMS.
     * Enrichit chaque cours avec la prochaine séance SSA associée.
     *
     * Format unifié : compatible avec les templates existants (clés 'adventures')
     * ET les nouveaux templates (clés enrichies).
     *
     * @param  array $tutor_courses  Sortie de RK_Tutor_Course_Service::get_enrolled_courses().
     * @param  int   $child_id       Pour récupérer les séances depuis wp_rk_bookings.
     * @return array
     */
    private static function build_courses( array $tutor_courses, int $child_id = 0 ): array {
        if ( empty( $tutor_courses ) ) return [];

        $out = [];
        foreach ( $tutor_courses as $course ) {
            $pct = (int) $course['progress'];

            // Prochaine séance SSA liée à ce cours
            $next_sessions  = $child_id > 0
                ? RK_Booking_Calendar_Service::get_for_course( $child_id, $course['id'], 1 )
                : [];
            $next_session   = $next_sessions[0] ?? null;
            $next_session_name = $next_session ? $next_session['session_name'] : '';
            $next_session_date = '';
            if ( $next_session && ! empty( $next_session['appointment'] ) ) {
                $ts = rk_mc_appt_timestamp( $next_session['appointment'] );
                $next_session_date = $ts
                    ? rk_mc_appt_format( $next_session['appointment'], 'j M Y — H:i', (int) ( $next_session['booking_id'] ?? 0 ) )
                    : '';
            }

            $out[] = [
                // Clés standard (rétrocompatibilité templates)
                'id'                => $course['id'],
                'title'             => $course['title'],
                'progress'          => $pct,
                'completed'         => $pct >= 100 ? 1 : 0,
                'total'             => 1,
                'lessons_done'      => $course['lessons_done'],
                'lessons_total'     => $course['lessons_total'],
                'state'             => $course['state'],
                'permalink'         => $course['permalink'],
                'thumbnail'         => $course['thumbnail'],
                'description'       => $course['category'],
                'reward'            => '',
                'rarity'            => 'rare',
                'product_title'     => '',
                'product_url'       => '',
                'session_credits'   => 0,
                // Clés enrichies (nouvelles)
                'category'          => $course['category'],
                'instructor_id'     => $course['instructor_id'],
                'instructor_name'   => $course['instructor_name'],
                'topics_count'      => $course['topics_count'],
                'quizzes_count'     => $course['quizzes_count'],
                'quizzes_done'      => $course['quizzes_done'],
                'next_lesson'       => $course['next_lesson'],
                'next_session_name' => $next_session_name,
                'next_session_date' => $next_session_date,
                'sessions_past'     => 0,
                'sessions_total'    => 0,
            ];
        }
        return $out;
    }

    /* ─── Certificates ──────────────────────────────────────────── */

    /**
     * Récupère les certificats assignés à l'enfant par un coach.
     */
    private static function fetch_certificates( int $child_id ): array {
        if ( ! class_exists( 'RKP_CertificateRepository' ) ) return [];
        $rows = RKP_CertificateRepository::find_for_child( $child_id );
        foreach ( $rows as &$row ) {
            $coach           = get_userdata( (int) ( $row->coach_id ?? 0 ) );
            $row->coach_name = $coach ? $coach->display_name : __( 'المدرب', 'rk-my-children' );
        }
        unset( $row );
        return $rows;
    }

    /**
     * Mappe les missions DB vers le format template.
     *
     * @param  array $raw_missions  Sortie de RK_MC_Mission_Service::get_weekly_missions().
     * @return array
     */
    private static function build_missions( array $raw_missions, array $coach_missions = [] ): array {
        $icon_map = array(
            'complete_lessons' => 'graduation-cap',
            'attend_session'   => 'calendar',
            'pass_quiz'        => 'nav-target',
            'complete_lesson'  => 'book-open',
        );

        $out = array();

        // Coach-assigned missions first (pinned at top)
        foreach ( $coach_missions as $m ) {
            $progress = (int) ( $m['progress'] ?? 0 );
            $target   = max( 1, (int) ( $m['target'] ?? 1 ) );
            $state    = 'new';
            if ( (int) ( $m['completed'] ?? 0 ) ) {
                $state = 'completed';
            } elseif ( $progress > 0 ) {
                $state = 'in-progress';
            }

            $out[] = array(
                'title'       => (string) ( $m['title'] ?? '' ),
                'description' => (string) ( $m['description'] ?? '' ),
                'progress'    => $progress,
                'target'      => $target,
                'reward'      => sprintf( '+%d XP', (int) ( $m['points'] ?? 0 ) ),
                'xp_reward'   => (int) ( $m['points'] ?? 0 ),
                'impact'      => '',
                'badge'       => '',
                'subtasks'    => array(),
                'state'       => $state,
                'hint'        => '',
                'pct'         => (int) round( $progress / $target * 100 ),
                'icon_key'    => 'nav-target',
                'source'      => 'coach',
                'file_url'    => (string) ( $m['file_url'] ?? '' ),
                'file_name'   => (string) ( $m['file_name'] ?? '' ),
                'due_date'    => (string) ( $m['due_date'] ?? '' ),
            );
        }

        // Weekly auto-missions
        foreach ( $raw_missions as $m ) {
            $state = 'new';
            if ( $m['completed'] ) {
                $state = 'completed';
            } elseif ( $m['progress'] > 0 ) {
                $state = 'in-progress';
            }

            $out[] = array(
                'title'       => $m['name'],
                'description' => '',
                'progress'    => $m['progress'],
                'target'      => $m['target'],
                'reward'      => sprintf( '+%d XP', $m['points'] ),
                'xp_reward'   => $m['points'],
                'impact'      => '',
                'badge'       => '',
                'subtasks'    => array(),
                'state'       => $state,
                'hint'        => '',
                'pct'         => $m['pct'],
                'icon_key'    => isset( $icon_map[ $m['key'] ] ) ? $icon_map[ $m['key'] ] : 'nav-target',
                'source'      => 'weekly',
                'file_url'    => '',
                'file_name'   => '',
                'due_date'    => '',
            );
        }
        return $out;
    }

    /**
     * Mappe les compétences DB vers le format template.
     *
     * @param  array $raw_skills  Sortie de RK_MC_Skill_Service::get_skills().
     * @return array
     */
    private static function build_powers( array $raw_skills ): array {
        $out = array();
        foreach ( $raw_skills as $skill ) {
            $level  = (int) $skill['level'];
            $pct    = (int) $skill['pct'];
            $max    = (int) ( $skill['max'] ?? 10 );

            $status = 'inactive';
            if ( $pct >= 90 ) {
                $status = 'almost';
            } elseif ( $level > 0 ) {
                $status = 'growing';
            }

            $next_label = $level < $max
                ? sprintf( __( 'مستوى %d', 'rk-my-children' ), $level + 1 )
                : __( 'المستوى الأقصى', 'rk-my-children' );

            $out[] = array(
                'name'       => $skill['name'],
                'level'      => $level,
                'progress'   => $pct,
                'tip'        => '',
                'status'     => $status,
                'next_label' => $next_label,
                'icon_key'   => $skill['key'],
            );
        }
        return $out;
    }

    /**
     * Construit la section badges à partir du catalogue complet de l'enfant.
     * Retourne les 18 premiers badges (earned + locked) pour affichage.
     *
     * @param  array $catalogue  Sortie de RK_MC_Badge_Service::get_catalogue_for_child().
     * @return array
     */
    private static function build_badges( array $catalogue ): array {
        $out     = array();
        $limit   = 18;
        $count   = 0;

        /* Afficher d'abord les badges obtenus, puis les verrouillés */
        usort( $catalogue, static fn( $a, $b ) => (int) $b['earned'] - (int) $a['earned'] );

        foreach ( $catalogue as $badge ) {
            if ( $count >= $limit ) {
                break;
            }
            $out[] = array(
                'name'      => $badge['name'],
                'badge_key' => $badge['key'],
                'icon_key'  => $badge['icon_key'] ?? 'star',
                'earned'    => (bool) $badge['earned'],
                'earned_at' => ! empty( $badge['earned_at'] )
                    ? date_i18n( 'j F Y', strtotime( $badge['earned_at'] ) )
                    : '',
                'hint'      => $badge['desc'] ?? '',
                'rarity'    => $badge['cat'] ?? 'start',
            );
            $count++;
        }
        return $out;
    }

    /**
     * Construit la section session à venir.
     *
     * @param  object|null $upcoming  stdClass depuis get_upcoming_session() ou null.
     *                                Propriétés attendues : appointment, session_name, coach_name.
     * @return array
     */
    private static function build_session( ?object $upcoming ): array {
        $base = array(
            'coach_avatar' => '',
            'coach_name'   => '',
            'title'        => __( 'الجلسة القادمة', 'rk-my-children' ),
            'date'         => '',
            'time'         => '',
            'question'     => __( 'ما هو أكبر حلم لديك؟', 'rk-my-children' ),
            'cta'          => __( 'أنا جاهز', 'rk-my-children' ),
            'timestamp'    => 0,
        );

        if ( ! $upcoming ) {
            return $base;
        }

        $ts                = rk_mc_appt_timestamp( $upcoming->appointment ?? '' );
        $_aid              = (int) ( $upcoming->booking_id ?? 0 );
        $base['date']      = $ts ? rk_mc_appt_format( $upcoming->appointment, 'l، j F Y', $_aid ) : '';
        $base['time']      = $ts ? rk_mc_appt_format( $upcoming->appointment, 'H:i',      $_aid ) : '';
        $base['timestamp'] = $ts ?: 0;

        // ACF session name (e.g. "الاثنين 15h-17h") becomes the card tag/title.
        if ( ! empty( $upcoming->session_name ) ) {
            $base['title'] = $upcoming->session_name;
        }

        if ( ! empty( $upcoming->coach_name ) ) {
            $base['coach_name'] = $upcoming->coach_name;
        }

        return $base;
    }

    /* ── Texte du prochain objectif hebdomadaire ───────────────── */
    private static function get_weekly_goal_text( array $missions ): string {
        foreach ( $missions as $m ) {
            if ( ! $m['completed'] ) {
                return $m['name'];
            }
        }
        return __( 'أحسنت! أكملت مهام الأسبوع', 'rk-my-children' );
    }

    private static function get_next_mission_title( array $missions ): string {
        foreach ( $missions as $m ) {
            if ( ! $m['completed'] ) {
                return $m['name'];
            }
        }
        return '';
    }

    private static function get_next_badge_hint( array $catalogue ): string {
        foreach ( $catalogue as $badge ) {
            if ( empty( $badge['earned'] ) && ! empty( $badge['desc'] ) ) {
                return $badge['desc'];
            }
        }
        return '';
    }

    /* ── Assessment builder ────────────────────────────────────── */

    private static function get_previous_bilan( int $child_id ): ?array {
        if ( ! class_exists( 'RK_MC_Assessment_Service' ) ) {
            return null;
        }
        $all = RK_MC_Assessment_Service::get_all( $child_id );
        return isset( $all[1] ) ? $all[1] : null;
    }

    private static function build_assessment( ?array $latest, ?array $previous, array $history ): array {
        $has_assessment = class_exists( 'RK_MC_Assessment_Service' );
        return array(
            'latest'       => $latest,
            'previous'     => $previous,
            'history'      => $history,
            'radar_svg'    => ( $has_assessment && $latest )
                ? RK_MC_Assessment_Service::radar_svg(
                    $latest['skill_scores'],
                    $previous ? $previous['skill_scores'] : []
                  )
                : '',
        );
    }

    /* ── Messages: fetch + build (non cachée) ─────────────────── */

    /** Récupère et construit la section messages en temps réel (marks-as-read inclus). */
    private static function fetch_and_build_messages( int $child_id ): array {
        $has_messages = class_exists( 'RK_MC_Message_Service' );
        $parent_id    = get_current_user_id();
        $coach_obj    = $has_messages ? RK_MC_Message_Service::get_coach( $child_id )    : null;
        $admin_obj    = $has_messages ? RK_MC_Message_Service::get_admin_user()          : null;
        $coach_msgs   = ( $has_messages && $coach_obj )
            ? RK_MC_Message_Service::get_conversation( $child_id, $parent_id, 'coach' ) : [];
        $admin_msgs   = ( $has_messages && $admin_obj )
            ? RK_MC_Message_Service::get_conversation( $child_id, $parent_id, 'admin' ) : [];
        $coach_unread = ( $has_messages && $coach_obj )
            ? RK_MC_Message_Service::get_unread_count( $parent_id, $child_id, 'coach' ) : 0;
        $admin_unread = ( $has_messages && $admin_obj )
            ? RK_MC_Message_Service::get_unread_count( $parent_id, $child_id, 'admin' ) : 0;

        return self::build_messages(
            $coach_msgs, $admin_msgs,
            $coach_obj,  $admin_obj,
            $parent_id,
            $coach_unread, $admin_unread
        );
    }

    /* ── Messages builder ──────────────────────────────────────── */

    private static function build_messages(
        array $coach_msgs, array $admin_msgs,
        ?object $coach_obj, ?object $admin_obj,
        int $parent_id,
        int $coach_unread, int $admin_unread
    ): array {
        return array(
            'coach_msgs'   => $coach_msgs,
            'admin_msgs'   => $admin_msgs,
            'coach_id'     => $coach_obj ? (int) $coach_obj->ID : 0,
            'coach_name'   => $coach_obj ? $coach_obj->display_name : '',
            'admin_id'     => $admin_obj ? (int) $admin_obj->ID : 0,
            'admin_name'   => $admin_obj ? $admin_obj->display_name : __( 'الإدارة', 'rk-my-children' ),
            'parent_id'    => $parent_id,
            'coach_unread' => $coach_unread,
            'admin_unread' => $admin_unread,
        );
    }
}

