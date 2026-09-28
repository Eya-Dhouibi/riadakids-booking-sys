<?php
declare( strict_types=1 );
/**
 * RK_Coach_Badges_Controller — CRUD complet des شارات pour la SPA coach.
 *
 * v9.16 — Nouveau : expose en REST (JSON, sans rechargement de page) le
 * même CRUD que la page Tutor native RK_Coach_Badges (create/update/
 * delete/award/revoke), pour que l'onglet "الشارات" de la SPA
 * (espace-coach/#badges) fonctionne en AJAX pur, cohérent avec le reste
 * du dashboard coach (toutes les autres pages sont déjà des routes SPA).
 *
 * AUCUNE logique dupliquée pour l'attribution/révocation : délègue
 * directement à RK_MC_Badge_Service (award/revoke), exactement la même
 * source de vérité que la page PHP et que le catalogue enfant
 * (rk-badges.php) — un badge attribué ici apparaît "مكتسبة" côté enfant
 * instantanément, comme avec la page Tutor native.
 *
 * La page PHP classique (RK_Coach_Badges, /dashboard/rk-badges/) reste
 * intacte et fonctionnelle en parallèle — ce controller ne la remplace
 * pas, il donne juste un second point d'accès (SPA) aux mêmes données.
 *
 * @package RK_Coach_Hub
 * @since   9.16.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class RK_Coach_Badges_Controller {

    /* ── Constantes de validation (dupliquées à l'identique depuis
       RK_Coach_Badges — private là-bas, nécessaires ici aussi ; toute
       modification doit être répercutée dans les deux classes). ──── */

    private static function icon_keys(): array {
        return [
            'star', 'graduation-cap', 'nav-target', 'users', 'fire',
            'diamond', 'lightning', 'moon', 'trophy', 'rocket',
            'skill-speech', 'skill-creativity', 'gift', 'award',
            'nav-compass', 'book-open', 'heart', 'crown',
        ];
    }

    private static function icon_labels(): array {
        return [
            'star'             => __( 'نجمة', 'rk-coach-hub' ),
            'graduation-cap'   => __( 'تخرج', 'rk-coach-hub' ),
            'nav-target'       => __( 'هدف', 'rk-coach-hub' ),
            'users'            => __( 'فريق', 'rk-coach-hub' ),
            'fire'             => __( 'حماس', 'rk-coach-hub' ),
            'diamond'          => __( 'ماسة', 'rk-coach-hub' ),
            'lightning'        => __( 'سرعة', 'rk-coach-hub' ),
            'moon'             => __( 'مثابرة', 'rk-coach-hub' ),
            'trophy'           => __( 'كأس', 'rk-coach-hub' ),
            'rocket'           => __( 'انطلاقة', 'rk-coach-hub' ),
            'skill-speech'     => __( 'تواصل', 'rk-coach-hub' ),
            'skill-creativity' => __( 'إبداع', 'rk-coach-hub' ),
            'gift'             => __( 'هدية', 'rk-coach-hub' ),
            'award'            => __( 'وسام', 'rk-coach-hub' ),
            'nav-compass'      => __( 'استكشاف', 'rk-coach-hub' ),
            'book-open'        => __( 'تعلّم', 'rk-coach-hub' ),
            'heart'            => __( 'تميّز', 'rk-coach-hub' ),
            'crown'            => __( 'تفوّق', 'rk-coach-hub' ),
        ];
    }

    private static function valid_cats(): array {
        return [
            'skill'   => __( 'مهارات', 'rk-coach-hub' ),
            'special' => __( 'خاصة', 'rk-coach-hub' ),
            'mastery' => __( 'إتقان', 'rk-coach-hub' ),
            'streak'  => __( 'مداومة', 'rk-coach-hub' ),
            'start'   => __( 'بداية', 'rk-coach-hub' ),
            'level'   => __( 'مستويات', 'rk-coach-hub' ),
        ];
    }

    /**
     * v9.20 — Renvoie tous les badges ÉDITABLES par ce coach : les
     * siens (coach_id = $coach_id) + tous les badges SYSTÈME
     * (is_system=1, partagés entre tous les coachs) — auparavant
     * uniquement WHERE coach_id = %d, qui excluait les 22 badges
     * système migrés en base (coach_id=0).
     */
    private static function get_coach_custom_badges( int $coach_id ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'rk_custom_badges';
        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) !== $table ) return [];
        return (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM `{$table}` WHERE coach_id = %d OR is_system = 1 ORDER BY is_system DESC, id ASC",
            $coach_id
        ) );
    }

    /** Vérifie que $child_id appartient bien aux élèves de ce coach. */
    private static function coach_owns_child( int $coach_id, int $child_id ): bool {
        /*
         * v9.60 — BUGFIX : cette methode filtrait get_coach_students(),
         * qui est mis en CACHE 10 minutes (RK_Coach_Data::$students_cache
         * + transient rk_ch_students_{coach_id}), jamais invalide nulle
         * part dans le plugin. Un enfant fraichement assigne a ce coach
         * pouvait donc echouer ici jusqu'a 10 minutes, avec un 403
         * 'not_authorized' que le frontend n'affichait pas clairement
         * (voir apiPost() : tout 403 est d'abord traite comme un token
         * expire, et retente une 2e fois avant de propager l'echec reel).
         *
         * RK_Coach_Data::coach_owns_child() existe deja, execute une
         * requete SQL directe (RKP_CoachStudentRepository), sans passer
         * par ce cache. Reutilisee ici plutot que dupliquee.
         */
        return RK_Coach_Data::coach_owns_child( $coach_id, $child_id );
    }

    /**
     * v9.20 — Un coach peut éditer/supprimer un badge si :
     *   - c'est un badge SYSTÈME (coach_id=0, is_system=1) : TOUT coach
     *     authentifié peut l'éditer (pas de propriétaire unique — décision
     *     explicite de l'utilisateur, ces 22 badges sont partagés), OU
     *   - c'est SON PROPRE badge custom (coach_id = $coach_id).
     * Retourne la ligne trouvée (ou null si absente/non autorisée), pour
     * éviter une seconde requête SELECT dans les appelants.
     */
    private static function find_editable_badge( int $coach_id, string $badge_key ): ?object {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM `{$wpdb->prefix}rk_custom_badges` WHERE badge_key = %s",
            $badge_key
        ) );
        if ( ! $row ) return null;

        $is_system = (bool) ( $row->is_system ?? false );
        $owner_id  = (int) $row->coach_id;

        if ( $is_system || 0 === $owner_id || $owner_id === $coach_id ) {
            return $row;
        }
        return null;
    }

    /* ── GET /coach/badges ─────────────────────────────────────────
     * Vue d'ensemble : liste des élèves + compteur شارات + catalogue
     * complet + badges personnalisés du coach — tout ce qu'il faut
     * pour peindre la page #badges de la SPA en un seul appel.
     */
    public static function get_overview(): WP_REST_Response {
        $coach_id = get_current_user_id();

        if ( ! class_exists( 'RK_MC_Badge_Service' ) ) {
            return new WP_REST_Response( [ 'code' => 'badge_service_unavailable' ], 500 );
        }

        $catalogue = RK_MC_Badge_Service::catalogue();
        $students  = RK_Coach_Data::get_coach_students( $coach_id );

        $students_out = array_map( static function ( $s ) use ( $catalogue ) {
            $cid = (int) $s->child_id;
            return [
                'id'     => $cid,
                'name'   => trim( (string) $s->child_name . ' ' . (string) ( $s->child_family_name ?? '' ) ),
                'avatar' => (string) ( $s->avatar_url ?? '' ),
                'earned' => count( RK_MC_Badge_Service::get_badges( $cid ) ),
                'total'  => count( $catalogue ),
            ];
        }, (array) $students );

        $catalogue_out = [];
        foreach ( $catalogue as $key => $def ) {
            $catalogue_out[] = [
                'key'       => (string) $key,
                'name'      => (string) ( $def['name'] ?? '' ),
                'desc'      => (string) ( $def['desc'] ?? '' ),
                'cat'       => (string) ( $def['cat'] ?? 'skill' ),
                'icon_key'  => (string) ( $def['icon_key'] ?? 'star' ),
                'icon_url'  => (string) ( $def['icon_url'] ?? '' ),
                'custom'    => (bool) ( $def['custom'] ?? false ),
                // v9.20 — is_system : distingue les 22 badges partagés
                // entre tous les coachs des vrais badges custom d'un
                // coach précis. Les deux sont désormais éditables/
                // supprimables, mais le JS affiche un avertissement plus
                // marqué avant de supprimer un badge système (impacte
                // potentiellement tous les coachs, pas qu'un seul).
                'is_system' => (bool) ( $def['is_system'] ?? false ),
            ];
        }

        $custom_out = array_map( static function ( $cb ) {
            return [
                'badge_key'  => (string) $cb->badge_key,
                'name'       => (string) $cb->name,
                'icon_key'   => (string) $cb->icon_key,
                'icon_url'   => class_exists( 'RKP_BadgeQueryService' ) ? RKP_BadgeQueryService::resolve_icon_url( (string) $cb->badge_key ) : '',
                'cat'        => (string) $cb->cat,
                'badge_type' => (string) ( $cb->badge_type ?? '' ),
                'desc'       => (string) ( $cb->desc ?? '' ),
                'is_system'  => (bool) ( $cb->is_system ?? false ),
            ];
        }, self::get_coach_custom_badges( $coach_id ) );

        return new WP_REST_Response( [
            'students'       => array_values( $students_out ),
            'catalogue'      => $catalogue_out,
            'custom_badges'  => array_values( $custom_out ),
            'categories'     => self::valid_cats(),
            'icons'          => self::icon_labels(),
        ], 200 );
    }

    /* ── GET /coach/badges/child ────────────────────────────────────
     * Catalogue complet + statut earned/locked pour UN élève précis —
     * utilisé quand le coach ouvre la vue attribution d'un enfant.
     */
    public static function get_child_view( WP_REST_Request $request ): WP_REST_Response {
        $coach_id = get_current_user_id();
        $child_id = (int) $request->get_param( 'child_id' );
        if ( ! $child_id ) {
            return new WP_REST_Response( [ 'code' => 'missing_child_id' ], 400 );
        }
        if ( ! self::coach_owns_child( $coach_id, $child_id ) ) {
            return new WP_REST_Response( [ 'code' => 'not_authorized' ], 403 );
        }
        if ( ! class_exists( 'RKP_BadgeQueryService' ) ) {
            return new WP_REST_Response( [ 'code' => 'badge_service_unavailable' ], 500 );
        }

        $catalogue = RKP_BadgeQueryService::get_catalogue_for_child( $child_id );
        $result    = array_map( static function ( $b ) {
            return [
                'key'       => (string) $b['key'],
                'name'      => (string) $b['name'],
                'desc'      => (string) $b['desc'],
                'cat'       => (string) $b['cat'],
                'icon_url'  => (string) ( $b['icon_url'] ?? '' ),
                'earned'    => (bool) $b['earned'],
                'earned_at' => (string) ( $b['earned_at'] ?? '' ),
                'custom'    => (bool) ( $b['custom'] ?? false ),
            ];
        }, $catalogue );

        return new WP_REST_Response( [
            'child_id' => $child_id,
            'badges'   => array_values( $result ),
        ], 200 );
    }

    /* ── POST /coach/badges/award ───────────────────────────────────
     * Attribue un badge à un élève — même appel que le handler
     * admin-post historique (RK_Coach_Child_Handlers::handle_award_badge),
     * réutilise RK_MC_Badge_Service::award() comme source de vérité
     * unique : le badge devient "مكتسبة" côté enfant instantanément.
     */
    public static function award( WP_REST_Request $request ): WP_REST_Response {
        $coach_id  = get_current_user_id();
        $child_id  = (int) $request->get_param( 'child_id' );
        $badge_key = sanitize_key( (string) $request->get_param( 'badge_key' ) );

        if ( ! $child_id || ! $badge_key ) {
            return new WP_REST_Response( [ 'code' => 'missing_params' ], 400 );
        }
        if ( ! self::coach_owns_child( $coach_id, $child_id ) ) {
            return new WP_REST_Response( [ 'code' => 'not_authorized' ], 403 );
        }
        if ( ! class_exists( 'RK_MC_Badge_Service' ) ) {
            return new WP_REST_Response( [ 'code' => 'badge_service_unavailable' ], 500 );
        }

        // v9.21 — le retour d'award() était ignoré : la SPA affichait
        // toujours "تم منح الشارة!" même en cas d'échec (badge déjà
        // attribué, clé invalide, ou table SQL absente), alors que
        // RIEN n'avait été écrit en base et le dashboard enfant restait
        // inchangé. On propage désormais le vrai résultat au frontend.
        //
        // v9.30 — award_with_reason() remplace award() ici : le 409
        // renvoyait un code générique 'award_failed' sans jamais dire
        // laquelle des 3 causes possibles s'était produite (badge_key
        // inconnu, déjà attribué, échec DB réel) — impossible à
        // diagnostiquer depuis le frontend. Le vrai code (ex:
        // 'already_awarded' pour un badge déjà acquis, cas le plus
        // fréquent en usage réel — double-clic ou état frontend
        // désynchronisé) est maintenant renvoyé explicitement.
        $reason = RK_MC_Badge_Service::award_with_reason( $child_id, $badge_key );

        if ( '' !== $reason ) {
            return new WP_REST_Response( [ 'code' => $reason, 'success' => false ], 409 );
        }

        return new WP_REST_Response( [ 'success' => true ], 200 );
    }

    /* ── POST /coach/badges/revoke ──────────────────────────────────
     * Retire un badge précédemment attribué (redevient "مقفلة" côté
     * enfant), même source de vérité que la révocation PHP existante.
     */
    public static function revoke( WP_REST_Request $request ): WP_REST_Response {
        $coach_id  = get_current_user_id();
        $child_id  = (int) $request->get_param( 'child_id' );
        $badge_key = sanitize_key( (string) $request->get_param( 'badge_key' ) );

        if ( ! $child_id || ! $badge_key ) {
            return new WP_REST_Response( [ 'code' => 'missing_params' ], 400 );
        }
        if ( ! self::coach_owns_child( $coach_id, $child_id ) ) {
            return new WP_REST_Response( [ 'code' => 'not_authorized' ], 403 );
        }
        if ( ! class_exists( 'RKP_BadgeCommandService' ) ) {
            return new WP_REST_Response( [ 'code' => 'badge_service_unavailable' ], 500 );
        }

        /*
         * v9.58 — Correctif du 409 Conflict sur /coach/badges/revoke.
         *
         * Avant : revoke() renvoyait false des que $wpdb->delete()
         * supprimait 0 ligne, et le controller repondait 409. C'etait le
         * cas systematique des badges "achievement" (level_2...), calcules
         * dynamiquement et sans ligne en base — le coach voyait une erreur
         * alors que l'enfant n'avait tout simplement pas ce badge.
         *
         * Desormais, comme pour award() : un code precis, et 'not_awarded'
         * est traite comme un SUCCES idempotent — l'etat voulu (l'enfant
         * n'a pas le badge) est atteint. Le front resynchronise la carte.
         */
        $reason = method_exists( 'RKP_BadgeCommandService', 'revoke_with_reason' )
            ? RKP_BadgeCommandService::revoke_with_reason( $child_id, $badge_key )
            : ( RKP_BadgeCommandService::revoke( $child_id, $badge_key ) ? 'ok' : 'revoke_failed' );

        if ( 'unknown_badge' === $reason ) {
            return new WP_REST_Response( [ 'code' => 'unknown_badge', 'success' => false ], 404 );
        }

        if ( 'ok' === $reason || 'not_awarded' === $reason ) {
            return new WP_REST_Response( [ 'success' => true, 'code' => $reason ], 200 );
        }

        return new WP_REST_Response( [ 'code' => $reason, 'success' => false ], 409 );
    }

    /* ── POST /coach/badges/custom ───────────────────────────────────
     * Crée une شارة personnalisée. Mêmes règles de validation que le
     * formulaire PHP (RK_Coach_Badges::handle_create_badge) — champ
     * badge_type OBLIGATOIRE, aucun défaut silencieux.
     */
    public static function create_custom( WP_REST_Request $request ): WP_REST_Response {
        $coach_id   = get_current_user_id();
        $name       = sanitize_text_field( (string) $request->get_param( 'name' ) );
        $icon_key   = sanitize_key( (string) $request->get_param( 'icon_key' ) ?: 'star' );
        $cat        = sanitize_key( (string) $request->get_param( 'cat' ) ?: 'skill' );
        $desc       = sanitize_textarea_field( (string) $request->get_param( 'desc' ) );
        $badge_type = sanitize_key( (string) $request->get_param( 'badge_type' ) );

        if ( empty( $name ) ) {
            return new WP_REST_Response( [ 'code' => 'empty_name' ], 400 );
        }
        if ( ! in_array( $badge_type, [ 'achievement', 'adventure' ], true ) ) {
            return new WP_REST_Response( [ 'code' => 'missing_type' ], 400 );
        }

        $allowed_cats = array_keys( self::valid_cats() );
        $cat          = in_array( $cat, $allowed_cats, true ) ? $cat : 'skill';
        $allowed_keys = self::icon_keys();
        $icon_key     = in_array( $icon_key, $allowed_keys, true ) ? $icon_key : 'star';

        global $wpdb;
        $badge_key = 'custom_' . substr( md5( uniqid( (string) $coach_id . $name, true ) ), 0, 10 );
        $wpdb->insert(
            $wpdb->prefix . 'rk_custom_badges',
            [
                'badge_key'  => $badge_key,
                'name'       => $name,
                'icon_key'   => $icon_key,
                'cat'        => $cat,
                'badge_type' => $badge_type,
                'desc'       => $desc,
                'coach_id'   => $coach_id,
                'created_at' => current_time( 'mysql' ),
            ],
            [ '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s' ]
        );

        return new WP_REST_Response( [ 'success' => true, 'badge_key' => $badge_key ], 201 );
    }

    /* ── POST /coach/badges/custom/(?P<badge_key>[a-z0-9_]+) ────────
     * Modifie une شارة personnalisée existante (doit appartenir à ce
     * coach). Mêmes règles de validation qu'à la création.
     */
    public static function update_custom( WP_REST_Request $request ): WP_REST_Response {
        $coach_id  = get_current_user_id();
        $badge_key = sanitize_key( (string) $request->get_param( 'badge_key' ) );
        if ( ! $badge_key ) {
            return new WP_REST_Response( [ 'code' => 'missing_badge_key' ], 400 );
        }

        $existing = self::find_editable_badge( $coach_id, $badge_key );
        if ( ! $existing ) {
            return new WP_REST_Response( [ 'code' => 'not_found' ], 404 );
        }

        $name       = sanitize_text_field( (string) $request->get_param( 'name' ) );
        $icon_key   = sanitize_key( (string) $request->get_param( 'icon_key' ) ?: 'star' );
        $cat        = sanitize_key( (string) $request->get_param( 'cat' ) ?: 'skill' );
        $desc       = sanitize_textarea_field( (string) $request->get_param( 'desc' ) );
        $badge_type = sanitize_key( (string) $request->get_param( 'badge_type' ) );

        if ( empty( $name ) ) {
            return new WP_REST_Response( [ 'code' => 'empty_name' ], 400 );
        }
        if ( ! in_array( $badge_type, [ 'achievement', 'adventure' ], true ) ) {
            return new WP_REST_Response( [ 'code' => 'missing_type' ], 400 );
        }

        $allowed_cats = array_keys( self::valid_cats() );
        $cat          = in_array( $cat, $allowed_cats, true ) ? $cat : 'skill';
        $allowed_keys = self::icon_keys();
        $icon_key     = in_array( $icon_key, $allowed_keys, true ) ? $icon_key : 'star';

        global $wpdb;
        // v9.20 — WHERE badge_key seul (pas AND coach_id=%d) : un badge
        // système (coach_id=0) doit rester modifiable même si ce n'est
        // pas le coach qui l'a "créé" — l'autorisation a déjà été
        // vérifiée ci-dessus par find_editable_badge().
        $wpdb->update(
            $wpdb->prefix . 'rk_custom_badges',
            [ 'name' => $name, 'icon_key' => $icon_key, 'cat' => $cat, 'badge_type' => $badge_type, 'desc' => $desc ],
            [ 'badge_key' => $badge_key ],
            [ '%s', '%s', '%s', '%s', '%s' ],
            [ '%s' ]
        );

        return new WP_REST_Response( [ 'success' => true ], 200 );
    }

    /* ── POST /coach/badges/custom/(?P<badge_key>...)/upload-icon ───
     * v9.20 — Upload d'une VRAIE image pour un badge (système ou
     * custom), en remplacement du sélecteur d'icône SVG générique.
     * Le fichier est sauvegardé sous {badge_key}.{ext} dans
     * assets/img/badges/ — exactement le format que
     * RKP_BadgeQueryService::resolve_icon_url() cherche déjà en
     * priorité, donc AUCUN changement nécessaire côté résolution :
     * l'image uploadée est automatiquement servie partout (catalogue
     * coach ET enfant) dès qu'elle existe sur disque.
     *
     * Sécurité : type MIME + extension whitelist stricte (jpg/png/svg/
     * webp), taille max 2 Mo, nom de fichier dérivé de badge_key
     * (jamais du nom original envoyé par le client) — aucune exécution
     * de code possible, aucun chemin arbitraire.
     */
    public static function upload_icon( WP_REST_Request $request ): WP_REST_Response {
        $coach_id  = get_current_user_id();
        $badge_key = sanitize_key( (string) $request->get_param( 'badge_key' ) );
        if ( ! $badge_key ) {
            return new WP_REST_Response( [ 'code' => 'missing_badge_key' ], 400 );
        }

        $existing = self::find_editable_badge( $coach_id, $badge_key );
        if ( ! $existing ) {
            return new WP_REST_Response( [ 'code' => 'not_found' ], 404 );
        }

        $files = $request->get_file_params();
        if ( empty( $files['icon']['tmp_name'] ) || ! is_uploaded_file( $files['icon']['tmp_name'] ) ) {
            return new WP_REST_Response( [ 'code' => 'missing_file' ], 400 );
        }
        $file = $files['icon'];

        // 2 Mo max — largement suffisant pour une icône de badge.
        if ( (int) $file['size'] > 2 * 1024 * 1024 ) {
            return new WP_REST_Response( [ 'code' => 'file_too_large' ], 400 );
        }

        $allowed_mimes = [
            'image/svg+xml' => 'svg',
            'image/png'     => 'png',
            'image/jpeg'    => 'jpg',
            'image/webp'    => 'webp',
        ];
        // finfo (pas juste $file['type'], falsifiable côté client) pour
        // déterminer le VRAI type MIME du contenu envoyé.
        $real_mime = function_exists( 'finfo_open' )
            ? (string) finfo_file( finfo_open( FILEINFO_MIME_TYPE ), $file['tmp_name'] )
            : (string) ( $file['type'] ?? '' );

        if ( ! isset( $allowed_mimes[ $real_mime ] ) ) {
            return new WP_REST_Response( [ 'code' => 'invalid_file_type' ], 400 );
        }
        $ext = $allowed_mimes[ $real_mime ];

        if ( ! defined( 'RK_MC_DIR' ) || ! defined( 'RK_MC_URL' ) ) {
            return new WP_REST_Response( [ 'code' => 'upload_dir_unavailable' ], 500 );
        }
        $badges_dir = RK_MC_DIR . 'assets/img/badges/';
        if ( ! is_dir( $badges_dir ) || ! is_writable( $badges_dir ) ) {
            return new WP_REST_Response( [ 'code' => 'upload_dir_not_writable' ], 500 );
        }

        // Retire toute image préexistante sous une AUTRE extension pour
        // ce badge_key (ex: ancien .png remplacé par un nouveau .svg) —
        // sinon resolve_icon_url() (qui teste .svg avant .png) pourrait
        // continuer à servir l'ancien fichier au lieu du nouveau upload.
        foreach ( [ 'svg', 'png', 'jpg', 'webp' ] as $old_ext ) {
            $old_path = $badges_dir . $badge_key . '.' . $old_ext;
            if ( $old_ext !== $ext && file_exists( $old_path ) ) {
                @unlink( $old_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- best-effort cleanup, non bloquant si échoue
            }
        }

        $dest_path = $badges_dir . $badge_key . '.' . $ext;
        if ( ! move_uploaded_file( $file['tmp_name'], $dest_path ) ) {
            return new WP_REST_Response( [ 'code' => 'upload_failed' ], 500 );
        }

        return new WP_REST_Response( [
            'success'  => true,
            'icon_url' => RK_MC_URL . 'assets/img/badges/' . rawurlencode( $badge_key . '.' . $ext ) . '?v=' . time(),
        ], 200 );
    }

    /* ── DELETE /coach/badges/custom/(?P<badge_key>[a-z0-9_]+) ──────
     * Supprime définitivement une شارة personnalisée + la retire de
     * tous les élèves qui l'avaient (même comportement que le handler
     * PHP historique).
     */
    /**
     * GET /coach/badges/custom/{badge_key}/impact  (v9.59)
     *
     * Nombre d'enfants actuellement attribués à ce badge, pour que le
     * frontend affiche l'avertissement AVANT la confirmation de
     * suppression : "Ce badge est attribué à X enfant(s)...".
     * Même ownership que delete_custom() (find_editable_badge()).
     */
    public static function delete_impact( WP_REST_Request $request ): WP_REST_Response {
        $coach_id  = get_current_user_id();
        $badge_key = sanitize_key( (string) $request->get_param( 'badge_key' ) );
        if ( ! $badge_key ) {
            return new WP_REST_Response( [ 'code' => 'missing_badge_key' ], 400 );
        }

        $existing = self::find_editable_badge( $coach_id, $badge_key );
        if ( ! $existing ) {
            return new WP_REST_Response( [ 'code' => 'not_found' ], 404 );
        }

        if ( ! class_exists( 'RKP_BadgeRepository' ) ) {
            return new WP_REST_Response( [ 'code' => 'badge_service_unavailable' ], 500 );
        }

        return new WP_REST_Response( [
            'success'       => true,
            'children_count' => RKP_BadgeRepository::count_children_with_badge( $badge_key ),
        ], 200 );
    }

    public static function delete_custom( WP_REST_Request $request ): WP_REST_Response {
        $coach_id  = get_current_user_id();
        $badge_key = sanitize_key( (string) $request->get_param( 'badge_key' ) );
        if ( ! $badge_key ) {
            return new WP_REST_Response( [ 'code' => 'missing_badge_key' ], 400 );
        }

        $existing = self::find_editable_badge( $coach_id, $badge_key );
        if ( ! $existing ) {
            return new WP_REST_Response( [ 'code' => 'not_found' ], 404 );
        }

        // v9.59 — compté AVANT suppression pour le renvoyer dans la
        // réponse (confirmation finale côté frontend, ceinture + bretelles
        // avec l'avertissement déjà affiché via delete_impact()).
        $affected = class_exists( 'RKP_BadgeRepository' )
            ? RKP_BadgeRepository::count_children_with_badge( $badge_key )
            : 0;

        global $wpdb;
        // v9.20 — WHERE badge_key seul, mêmes raisons que update_custom().
        $wpdb->delete( $wpdb->prefix . 'rk_custom_badges', [ 'badge_key' => $badge_key ], [ '%s' ] );
        // Cleanup : retire ce badge de tous les enfants qui l'avaient — même comportement que le handler PHP historique.
        $wpdb->delete( $wpdb->prefix . 'rk_child_badges', [ 'badge_key' => $badge_key ], [ '%s' ] );

        return new WP_REST_Response( [ 'success' => true, 'children_affected' => $affected ], 200 );
    }
}