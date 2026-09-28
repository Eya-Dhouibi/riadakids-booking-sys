<?php
declare( strict_types=1 );
/**
 * RK_MC_Experience_Layer_Ajax
 *
 * Deux endpoints minimalistes pour le Child Experience Layer :
 *   GET  rk_experience_get_queue  — événements non vus (§20 Event Queue)
 *   POST rk_experience_mark_seen  — persistance "vu" (§21)
 *
 * Aucune logique métier ici — délègue entièrement à
 * RKP_EventExperienceLog (Infrastructure). Le payload UX complet de
 * chaque événement est déjà construit et mis en cache par
 * RKP_EventExperienceService au moment du dispatch (transient
 * 'rkp_exp_{event_id}') — ce contrôleur se contente de le relire.
 *
 * @since 9.9.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;

final class RK_MC_Experience_Layer_Ajax {

    public static function init(): void {
        add_action( 'wp_ajax_rk_experience_get_queue', [ __CLASS__, 'ajax_get_queue' ] );
        add_action( 'wp_ajax_rk_experience_mark_seen', [ __CLASS__, 'ajax_mark_seen' ] );
    }

    public static function ajax_get_queue(): void {
        check_ajax_referer( 'rk_experience_layer', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'code' => 'unauthenticated' ], 401 );
        }

        $child_id = self::resolve_child_id();
        if ( ! $child_id ) {
            wp_send_json_error( [ 'code' => 'no_child_context' ], 403 );
        }

        if ( ! class_exists( 'RKP_EventExperienceLog' ) ) {
            wp_send_json_success( [ 'items' => [] ] );
        }

        $unseen = RKP_EventExperienceLog::get_unseen( $child_id, 10 );
        $items  = [];

        foreach ( $unseen as $row ) {
            $payload = get_transient( 'rkp_exp_' . $row['event_id'] );
            if ( false === $payload ) {
                // Le transient a expiré (24h, voir RKP_EventExperienceService)
                // avant que l'enfant ne se reconnecte — l'expérience visuelle
                // n'a plus de sens à ce stade (§21 : pas de re-création
                // artificielle de contenu périmé). On marque quand même
                // comme vu pour ne pas laisser une entrée fantôme en base.
                RKP_EventExperienceLog::mark_seen( [ $row['event_id'] ], $child_id );
                continue;
            }
            $items[] = $payload;
        }

        wp_send_json_success( [ 'items' => $items ] );
    }

    public static function ajax_mark_seen(): void {
        check_ajax_referer( 'rk_experience_layer', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'code' => 'unauthenticated' ], 401 );
        }

        $child_id = self::resolve_child_id();
        if ( ! $child_id ) {
            wp_send_json_error( [ 'code' => 'no_child_context' ], 403 );
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce déjà vérifié ci-dessus via check_ajax_referer().
        $event_ids = isset( $_POST['event_ids'] ) ? (array) $_POST['event_ids'] : [];
        $event_ids = array_map( 'sanitize_text_field', $event_ids );
        $event_ids = array_filter( $event_ids, static fn( $id ) => preg_match( '/^[a-f0-9]{1,36}$/', $id ) );

        if ( empty( $event_ids ) || ! class_exists( 'RKP_EventExperienceLog' ) ) {
            wp_send_json_success( [ 'marked' => 0 ] );
        }

        RKP_EventExperienceLog::mark_seen( $event_ids, $child_id );
        wp_send_json_success( [ 'marked' => count( $event_ids ) ] );
    }

    /**
     * Résout l'ID enfant (table rk_children) du contexte courant.
     * Priorité : session enfant directe (rk_child) → wp_user_id du
     * compte connecté. Ne recrée AUCUNE logique de résolution : réutilise
     * RKP_ChildRepository, déjà la seule couche autorisée pour ça.
     */
    private static function resolve_child_id(): int {
        /*
         * 6b-2 — Avant : get_id_by_wp_user( get_current_user_id() ), qui
         * supposait que le current user ETAIT l'enfant. Depuis 6b-1, ces
         * actions AJAX sont en surface child_app où current_user est le
         * PARENT : la resolution renvoyait donc 0 et la couche experience
         * ne remontait plus rien.
         *
         * RK_Identity_Context::child_id() retourne directement l'ID
         * rk_children de l'enfant actif, ownership parent->enfant deja
         * verifie. Aucun repli sur le parent : 0 signifie "pas de contexte
         * enfant", et les appelants (l. 34 et 70) renvoient deja une
         * reponse vide dans ce cas.
         */
        if ( ! class_exists( 'RK_Identity_Context' ) ) return 0;
        return RK_Identity_Context::child_id();
    }
}
