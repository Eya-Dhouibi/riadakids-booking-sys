<?php
declare( strict_types=1 );
/**
 * RK_MC_Guidance_Ajax
 *
 * Expose next_action du JourneySnapshot déjà existant (§16-18 —
 * RKGuidance) via un simple endpoint AJAX en lecture seule. AUCUNE
 * nouvelle logique de décision : la matrice d'état complète vit déjà
 * dans RKP_JourneyQueryService::compute_next_action() (P1-6), ce
 * contrôleur ne fait que la lire et construire le texte/CTA associé
 * (le mapping visuel §18, pas le calcul métier).
 *
 * @since 9.9.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;

final class RK_MC_Guidance_Ajax {

    public static function init(): void {
        add_action( 'wp_ajax_rk_guidance_get', [ __CLASS__, 'ajax_get' ] );
        add_action( 'wp_ajax_rk_onboarding_mark_seen', [ __CLASS__, 'ajax_onboarding_mark_seen' ] );
        add_action( 'wp_ajax_rk_entry_story_get', [ __CLASS__, 'ajax_entry_story_get' ] );

        // v9.10 — RKEntryStory §7 point 7 "retour après absence" : aucun
        // tracking de dernière connexion enfant n'existait avant (le seul
        // meta trouvé, rk_last_seen, sert au statut "en ligne" du coach,
        // jamais écrit pour un enfant). Ajouté ici, honnêtement, plutôt
        // que d'inventer une donnée d'absence sans vraie source.
        add_action( 'wp_login', [ __CLASS__, 'track_login' ], 10, 2 );
    }

    public static function track_login( string $user_login, $user ): void {
        if ( $user instanceof \WP_User && in_array( 'rk_child', (array) $user->roles, true ) ) {
            update_user_meta( $user->ID, 'rk_last_login_tracked', time() );
        }
    }

    /**
     * §4-9 — RKEntryStory : une seule histoire prioritaire à l'ouverture
     * du Dashboard, ET une seule fois par 24h (tous types d'histoires
     * confondus, confirmé explicitement par l'utilisateur) — sinon elle
     * réapparaissait à chaque rechargement de page, y compris plusieurs
     * fois par jour. Délègue la SÉLECTION à RKP_EntryStoryService ; ce
     * contrôleur ne gère que la fréquence d'affichage (séparation des
     * responsabilités : le service choisit QUELLE histoire, ici on
     * décide SI on la montre).
     */
    public static function ajax_entry_story_get(): void {
        check_ajax_referer( 'rk_experience_layer', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'code' => 'unauthenticated' ], 401 );
        }

        if ( ! class_exists( 'RK_MC_Tutor_Dashboard' ) || ! class_exists( 'RKP_EntryStoryService' ) ) {
            wp_send_json_error( [ 'code' => 'unavailable' ], 500 );
        }

        $child = RK_MC_Tutor_Dashboard::get_child_for_template();
        if ( ! $child ) {
            wp_send_json_error( [ 'code' => 'no_child_context' ], 403 );
        }

        $child_wp_uid = (int) ( $child->wp_user_id ?? 0 );

        $last_shown = (int) get_user_meta( $child_wp_uid, 'rk_entry_story_last_shown', true );
        if ( $last_shown && ( time() - $last_shown ) < DAY_IN_SECONDS ) {
            wp_send_json_error( [ 'code' => 'already_shown_today' ], 200 ); // 200, pas une vraie erreur — juste "rien à montrer maintenant"
        }

        $child_rk_id = (int) $child->id;
        $child_name  = (string) ( $child->name ?? '' );

        $story = RKP_EntryStoryService::select_for_child( $child_rk_id, $child_wp_uid, $child_name );
        if ( ! $story ) {
            wp_send_json_error( [ 'code' => 'no_story' ], 404 );
        }

        update_user_meta( $child_wp_uid, 'rk_entry_story_last_shown', time() );

        wp_send_json_success( $story );
    }

    /**
     * §17 — RKFirstVisitGuide : marque l'onboarding comme vu, pour ne
     * plus jamais le réafficher (skippable et non répété par défaut —
     * "replayable" reste possible via un bouton dédié ailleurs dans
     * l'UI, qui appellerait simplement l'affichage sans repasser par
     * ce endpoint d'écriture).
     */
    public static function ajax_onboarding_mark_seen(): void {
        check_ajax_referer( 'rk_experience_layer', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'code' => 'unauthenticated' ], 401 );
        }

        /*
         * 6b-2 — L'onboarding est celui de l'ENFANT (couche experience du
         * dashboard enfant). Ecrire la meta sur le parent la partagerait
         * entre tous ses enfants : le 2e enfant ne verrait jamais son
         * onboarding. La lecture correspondante est dans
         * class-rk-mc-assets.php ('isFirstVisit') et a ete migree en meme
         * temps — les deux doivent rester sur la meme identite.
         *
         * Sans contexte enfant : on n'ecrit rien et on ne se rabat pas sur
         * le parent. Reponse succes malgre tout, l'appelant JS ne fait
         * qu'accuser reception.
         */
        $child_uid = class_exists( 'RK_Identity_Context' ) ? RK_Identity_Context::child_wp_uid() : 0;
        if ( $child_uid > 0 ) {
            update_user_meta( $child_uid, 'rk_onboarding_seen', 1 );
            if ( function_exists( 'rkp_log' ) ) {
                rkp_log( sprintf(
                    '[ONBOARDING] rk_onboarding_seen=1 ecrit | child_wp_uid_hash=%s',
                    substr( md5( (string) $child_uid ), 0, 8 )
                ) );
            }
        } elseif ( function_exists( 'rkp_log' ) ) {
            rkp_log( '[ONBOARDING] finishOnboarding recu mais child_wp_uid=0 — rien ecrit (identite non resolue)' );
        }
        wp_send_json_success();
    }

    public static function ajax_get(): void {
        check_ajax_referer( 'rk_experience_layer', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'code' => 'unauthenticated' ], 401 );
        }

        if ( ! class_exists( 'RK_MC_Tutor_Dashboard' ) ) {
            wp_send_json_error( [ 'code' => 'unavailable' ], 500 );
        }

        $snapshot = RK_MC_Tutor_Dashboard::get_snapshot();
        if ( ! $snapshot ) {
            wp_send_json_error( [ 'code' => 'no_snapshot' ], 404 );
        }

        wp_send_json_success( self::build_guidance_payload( $snapshot ) );
    }

    /**
     * Mapping next_action → texte/CTA (§18). Le CALCUL de next_action
     * lui-même n'est jamais refait ici — uniquement sa présentation.
     */
    private static function build_guidance_payload( RKP_JourneySnapshot $snapshot ): array {
        $map = [
            'continue_lesson' => [
                'message'    => __( 'تابع درسك من هنا', 'rk-my-children' ),
                'cta_label'  => __( 'متابعة الدرس', 'rk-my-children' ),
                'cta_action' => 'navigate:enrolled-courses',
            ],
            'take_quiz' => [
                'message'    => __( 'حان وقت التحدي!', 'rk-my-children' ),
                'cta_label'  => __( 'ابدأ الاختبار', 'rk-my-children' ),
                'cta_action' => 'navigate:enrolled-courses',
            ],
            'book_session' => [
                'message'    => __( 'احجز جلستك القادمة', 'rk-my-children' ),
                'cta_label'  => __( 'احجز الآن', 'rk-my-children' ),
                'cta_action' => 'navigate:rk-sessions',
            ],
            'attend_session' => [
                'message'    => __( 'جلستك القادمة بانتظارك', 'rk-my-children' ),
                'cta_label'  => __( 'عرض الجلسة', 'rk-my-children' ),
                'cta_action' => 'navigate:rk-sessions',
            ],
            'idle' => [
                'message'    => __( 'أحسنت! أكملت هذه المغامرة', 'rk-my-children' ),
                'cta_label'  => __( 'اكتشف مغامرة جديدة', 'rk-my-children' ),
                'cta_action' => 'navigate:enrolled-courses',
            ],
        ];

        $entry = $map[ $snapshot->next_action ] ?? $map['idle'];

        return array_merge( $entry, [
            'next_action' => $snapshot->next_action,
        ] );
    }
}
