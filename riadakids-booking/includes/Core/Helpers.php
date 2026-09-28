<?php
/**
 * RiadaKids\Core\Helpers — v25.2
 *
 * FIXES v25.2 :
 *   BUG1 — get_appt_id() : extrait l'id depuis n'importe quelle
 *           structure de réponse SSA (plate, imbriquée dans 'appointment',
 *           'data', 'id'/'ID'/'appointment_id').
 *           Root cause : SSA /ssa/v1/async retourne
 *           {"appointment":{"id":5,...}} → $arr['id']=0 → appt_id=0
 *           → ensure_from_ssa() retournait 0 silencieusement.
 *
 *   BUG1b — extract_appt_array() : normalise la réponse REST SSA
 *            en extrayant le sous-tableau appointment si présent.
 */

namespace RiadaKids\Core;

if ( ! defined( 'ABSPATH' ) ) exit;

class Helpers {

    /**
     * AJOUT (bug signalé) — retire le préfixe conversationnel que SSA
     * inclut parfois dans le TITRE de l'événement configuré côté admin
     * (ex. "أنت تحجز: نور الهدى عثمان" au lieu du simple nom du coach).
     * Ce préfixe n'est jamais généré par ce plugin — il vient tel quel du
     * titre SSA capturé/stocké (voir BookingService::
     * resolve_coach_name_from_staff_ids() en priorité désormais, ce nettoyage
     * ne s'applique qu'aux valeurs encore issues de l'ancien mécanisme de
     * capture DOM/webhook, event_name ou coach du payload).
     *
     * Ne modifie que la CHAÎNE AFFICHÉE, jamais ce qui est stocké en base
     * — appelée uniquement au moment du rendu (voir Dashboard::
     * render_booking_card()).
     *
     * @return string Nom nettoyé, ou la chaîne d'origine si aucun préfixe
     *                 connu n'a été trouvé — jamais de troncature aveugle.
     */
    public static function clean_coach_name( string $name ): string {
        $name = trim( $name );
        if ( '' === $name ) return $name;

        // Préfixes connus, un seul retiré au maximum (le premier qui
        // matche), suivis de ':' ou '،' avec espaces optionnels.
        $prefixes = [ 'أنت تحجز', 'انت تحجز' ];
        foreach ( $prefixes as $prefix ) {
            if ( 0 === mb_strpos( $name, $prefix ) ) {
                $rest = mb_substr( $name, mb_strlen( $prefix ) );
                $rest = preg_replace( '/^[\s:،,]+/u', '', $rest );
                if ( '' !== trim( (string) $rest ) ) {
                    return trim( (string) $rest );
                }
            }
        }

        return $name;
    }

    /**
     * BUG1 FIX — Extrait l'appt_id depuis TOUTE structure SSA possible :
     *
     *   Format plat    : {"id":5, "start_date":"..."}        → 5
     *   Format nested  : {"appointment":{"id":5,...}}         → 5
     *   Format data    : {"data":{"id":5,...}}                → 5
     *   Format appt_id : {"appointment_id":5,...}             → 5
     *   Format wpdb obj: stdClass {id:5}                     → 5
     */
    public static function get_appt_id( array $appt ): int {
        // 1. Clé directe (format habituel hook SSA + cron)
        if ( ! empty( $appt['id'] ) )   return (int) $appt['id'];
        if ( ! empty( $appt['ID'] ) )   return (int) $appt['ID'];

        // 2. appointment_id (variante SSA REST /ssa/v1/appointments)
        if ( ! empty( $appt['appointment_id'] ) ) return (int) $appt['appointment_id'];

        // 3. BUG1 FIX — sous-clé 'appointment' (réponse /ssa/v1/async)
        //    Format : {"appointment":{"id":5,"start_date":"..."}, "success":true}
        if ( ! empty( $appt['appointment'] ) && is_array( $appt['appointment'] ) ) {
            $sub = $appt['appointment'];
            if ( ! empty( $sub['id'] ) ) return (int) $sub['id'];
            if ( ! empty( $sub['ID'] ) ) return (int) $sub['ID'];
        }

        // 4. Sous-clé 'data'
        if ( ! empty( $appt['data'] ) && is_array( $appt['data'] ) ) {
            $sub = $appt['data'];
            if ( ! empty( $sub['id'] ) ) return (int) $sub['id'];
            if ( ! empty( $sub['ID'] ) ) return (int) $sub['ID'];
        }

        // 5. Recherche récursive 1 niveau (cas payload inattendu)
        foreach ( $appt as $val ) {
            if ( is_array( $val ) && ! empty( $val['id'] ) && is_numeric( $val['id'] ) ) {
                return (int) $val['id'];
            }
        }

        return 0;
    }

    /**
     * BUG1 FIX — Normalise un tableau de réponse SSA REST en extrayant
     * le sous-tableau appointment si présent.
     *
     * Utilisation : BookingHooks::intercept_ssa_rest() passe la réponse brute.
     * Cette méthode retourne le tableau d'appointment exploitable.
     *
     * Exemples :
     *   {"appointment":{"id":5,...},"success":true} → {"id":5,...}
     *   {"id":5,...}                                 → {"id":5,...}  (inchangé)
     */
    public static function extract_appt_array( array $response ): array {
        // Cas 1 — réponse /ssa/v1/async et variantes
        if ( ! empty( $response['appointment'] ) && is_array( $response['appointment'] ) ) {
            // Fusionner pour conserver customer_information etc. du niveau racine
            return array_merge( $response, $response['appointment'] );
        }

        // Cas 2 — sous-clé 'data'
        if ( ! empty( $response['data'] ) && is_array( $response['data'] ) ) {
            return array_merge( $response, $response['data'] );
        }

        // Cas 3 — déjà plat
        return $response;
    }

    /**
     * Résout l'ID WP à partir d'un payload SSA.
     * Ordre de priorité :
     *   1. wp_user_id direct dans le payload
     *   2. wp_user_id dans customer_information
     *   3. email → get_user_by('email')
     */
    public static function resolve_user_id_from_ssa( array $appt ): int {
        // 1. wp_user_id direct
        if ( ! empty( $appt['wp_user_id'] ) ) {
            $uid = (int) $appt['wp_user_id'];
            if ( $uid && get_userdata( $uid ) ) return $uid;
        }

        // 2. customer_information
        $ci = $appt['customer_information'] ?? null;
        if ( $ci ) {
            if ( is_string( $ci ) ) $ci = json_decode( $ci, true ) ?: [];
            if ( is_array( $ci ) ) {
                foreach ( $ci as $k => $v ) {
                    $key = strtolower( is_string( $k ) ? $k : (string) ( $v['id'] ?? '' ) );
                    $val = is_array( $v ) ? (string) ( $v['value'] ?? '' ) : (string) $v;
                    if ( $key === 'wp_user_id' && (int) $val > 0 ) {
                        $uid = (int) $val;
                        if ( get_userdata( $uid ) ) return $uid;
                    }
                }
            }
        }

        // 3. Email fallback
        $email = self::extract_email_from_ssa( $appt );
        if ( ! $email ) return 0;
        $user = get_user_by( 'email', $email );
        return $user ? (int) $user->ID : 0;
    }

    /**
     * Extrait l'email depuis un tableau SSA appointment.
     */
    public static function extract_email_from_ssa( array $appt ): string {
        foreach ( [ 'customer_email', 'email', 'client_email' ] as $key ) {
            if ( ! empty( $appt[ $key ] ) && is_email( $appt[ $key ] ) ) {
                return sanitize_email( $appt[ $key ] );
            }
        }
        $ci = $appt['customer_information'] ?? null;
        if ( ! $ci ) return '';
        if ( is_string( $ci ) ) $ci = json_decode( $ci, true ) ?: [];
        if ( ! is_array( $ci ) ) return '';
        foreach ( $ci as $k => $v ) {
            $field_key = strtolower( is_string( $k ) ? $k : (string) ( $v['id'] ?? '' ) );
            $val       = is_array( $v ) ? (string) ( $v['value'] ?? '' ) : (string) $v;
            if ( str_contains( $field_key, 'email' ) && is_email( $val ) ) {
                return sanitize_email( $val );
            }
        }
        return '';
    }

    public static function get_appt_date( array $appt ): string {
        foreach ( [ 'start_date_time', 'start_date', 'start_at', 'scheduled_at' ] as $col ) {
            if ( ! empty( $appt[ $col ] ) ) return $appt[ $col ];
        }
        return current_time( 'mysql' );
    }

    public static function ssa_admin_link( int $appt_id ): string {
        return admin_url(
            'admin.php?page=simply-schedule-appointments%2F#/ssa/appointment/' . $appt_id
        );
    }

    /**
     * Récupère les leçons Tutor LMS publiées pour un cours.
     *
     * Dans cette installation, le CPT des leçons est 'topics' (pas 'tutor_lesson').
     * La structure est à deux niveaux :
     *   Course → Container topic ("الجلسات التفاعلية – …") → Lesson topics
     *
     * Si un topic niveau-1 a des enfants → c'est un conteneur, on retourne ses enfants.
     * Si un topic niveau-1 n'a pas d'enfants → c'est une leçon directe.
     *
     * Fallback pour les installations utilisant 'tutor_lesson' standard.
     *
     * @param  int        $course_id  ID du cours (CPT 'courses')
     * @return \WP_Post[]             Leçons publiées triées par menu_order/titre
     */
    public static function get_tutor_lessons( int $course_id ): array {
        if ( $course_id <= 0 ) return [];

        // Structure de cette installation :
        //   Course (courses) → Container (topics) → Leçons (lesson)
        $containers = get_posts( [
            'post_type'      => 'topics',
            'post_parent'    => $course_id,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'orderby'        => 'menu_order title',
            'order'          => 'ASC',
        ] );

        if ( ! empty( $containers ) ) {
            $lessons = [];
            foreach ( $containers as $container ) {
                $lessons = array_merge( $lessons, get_posts( [
                    'post_type'      => 'lesson',
                    'post_parent'    => $container->ID,
                    'post_status'    => 'publish',
                    'posts_per_page' => -1,
                    'orderby'        => 'menu_order title',
                    'order'          => 'ASC',
                ] ) );
            }
            if ( ! empty( $lessons ) ) return $lessons;
        }

        // Fallback : leçons directes sous le cours (tutor_lesson standard)
        $direct = get_posts( [
            'post_type'      => 'tutor_lesson',
            'post_parent'    => $course_id,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'orderby'        => 'menu_order title',
            'order'          => 'ASC',
        ] );

        if ( ! empty( $direct ) ) return $direct;

        // Fallback niveau-2 : tutor_lesson_topic → tutor_lesson
        $std_containers = get_posts( [
            'post_type'      => 'tutor_lesson_topic',
            'post_parent'    => $course_id,
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'orderby'        => 'menu_order',
            'order'          => 'ASC',
        ] );

        $result = [];
        foreach ( $std_containers as $container ) {
            $result = array_merge( $result, get_posts( [
                'post_type'      => 'tutor_lesson',
                'post_parent'    => $container->ID,
                'post_status'    => 'publish',
                'posts_per_page' => -1,
                'orderby'        => 'menu_order title',
                'order'          => 'ASC',
            ] ) );
        }

        return $result;
    }
}