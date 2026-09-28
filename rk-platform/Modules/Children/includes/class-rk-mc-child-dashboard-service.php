<?php
declare( strict_types=1 );
/**
 * RK_MC_Child_Dashboard_Service  (v5.3.0 — Sprint 1)
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * RÔLE
 * ─────────────────────────────────────────────────────────────────────────────
 * Logique métier du dashboard enfant. Aucun HTML ici.
 *
 * Principe : les templates ne font que afficher — toute la logique de données
 * passe par cette classe.
 *
 * RÈGLES SPRINT 1
 * ─────────────────────────────────────────────────────────────────────────────
 *   - Aucune modification du plugin RiadaKids Booking
 *   - Lecture Tutor LMS uniquement, aucun enrôlement, aucune modification
 *   - Toutes les méthodes sont défensives (retourne valeur neutre si table absente)
 *
 * @package RK_My_Children
 * @since   5.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class RK_MC_Child_Dashboard_Service {

    /* ─────────────────────────────────────────
     * Accès sécurisé au dashboard
     * ───────────────────────────────────────── */

    /**
     * Retourne l'objet enfant si child_id appartient au parent connecté.
     * Point d'entrée unique pour tous les templates du dashboard.
     *
     * @param  int         $child_id        ID de l'enfant demandé.
     * @param  int         $parent_user_id  ID du parent connecté (get_current_user_id()).
     * @return object|null Null = accès refusé.
     */
    public static function get_child_for_dashboard( int $child_id, int $parent_user_id ): ?object {
        if ( $child_id <= 0 || $parent_user_id <= 0 ) {
            return null;
        }
        return RK_MC_Child_Repository::get_child( $child_id, $parent_user_id );
    }

    /* ─────────────────────────────────────────
     * Données Hero
     * ───────────────────────────────────────── */

    /**
     * Nom affiché de l'enfant.
     * Priorité : display_name → child_name.
     *
     * @param  object $child
     * @return string
     */
    public static function get_display_name( object $child ): string {
        if ( ! empty( $child->display_name ) ) {
            return $child->display_name;
        }
        return $child->child_name;
    }

    /**
     * Âge (child_age — champ VARCHAR legacy, seule source depuis la
     * suppression de birth_date).
     *
     * @param  object   $child
     * @return int|null Null si aucune donnée d'âge disponible.
     */
    public static function get_age( object $child ): ?int {
        return rk_mc_get_child_age( $child );
    }

    /**
     * Niveau scolaire — colonne grade_level supprimée, retourne toujours ''.
     * Conservée pour compatibilité des appelants existants.
     *
     * @param  object $child
     * @return string
     */
    public static function get_grade( object $child ): string {
        return '';
    }

    /**
     * URL avatar de l'enfant.
     *
     * @param  object $child
     * @return string
     */
    public static function get_avatar_url( object $child ): string {
        return rk_mc_get_avatar_url( $child );
    }

    /* ─────────────────────────────────────────
     * Prochaine session (Section 3)
     * ───────────────────────────────────────── */

    /**
     * La prochaine session à venir pour un enfant.
     * Source : wp_rk_bookings (table relationnelle — nouvelle architecture).
     *
     * @param  int         $child_id   ID dans wp_rk_children (interne RK)
     * @return object|null stdClass avec propriétés : appointment, session_name, coach_name
     */
    public static function get_upcoming_session( int $child_id ): ?object {
        if ( $child_id <= 0 ) return null;
        return RK_Booking_Calendar_Service::get_next_as_object( $child_id );
    }

    /**
     * Formate une date de session pour l'affichage.
     *
     * @param  string $datetime  Valeur DATETIME de la colonne appointment.
     * @return string            Exemple : "الأربعاء، 18 يونيو 2025 — 15:00"
     */
    public static function format_session_date( string $datetime ): string {
        if ( empty( $datetime ) || '0000-00-00 00:00:00' === $datetime ) {
            return '';
        }
        return date_i18n( 'l، j F Y — H:i', strtotime( $datetime ) );
    }

    /* ─────────────────────────────────────────
     * Cours Tutor LMS (Section 2 — Aventures)
     * Sprint 1 : lecture seule, retourne [] si Tutor absent
     * ───────────────────────────────────────── */

    /**
     * Cours Tutor LMS associés à un enfant.
     * Délègue à RK_Tutor_Course_Service (source unique de vérité).
     *
     * @param  object $child
     * @return array
     */
    public static function get_child_courses( object $child ): array {
        $uid = ! empty( $child->wp_user_id ) ? (int) $child->wp_user_id : 0;
        if ( ! $uid || ! class_exists( 'RK_Tutor_Course_Service' ) ) {
            return [];
        }
        return RK_Tutor_Course_Service::get_enrolled_courses( $uid );
    }

    /* ─────────────────────────────────────────
     * Helpers URL dans le contexte dashboard
     * ───────────────────────────────────────── */

    /**
     * URL de réservation pour cet enfant (bouton dans le dashboard).
     *
     * @param  int    $child_id
     * @return string
     */
    public static function get_booking_url( int $child_id ): string {
        return RK_MC_Child_Context::get_booking_url( $child_id );
    }

    /**
     * URL retour vers la liste des enfants.
     *
     * @return string
     */
    public static function get_back_url(): string {
        return wc_get_account_endpoint_url( RK_MC_Endpoint::SLUG );
    }
}

