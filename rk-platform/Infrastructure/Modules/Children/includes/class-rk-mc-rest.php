<?php
declare( strict_types=1 );
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class RK_MC_Rest  (v4.1.0)
 *
 * Routes :
 *   GET    /wp-json/rk-mc/v1/children          → list_children()
 *   POST   /wp-json/rk-mc/v1/children          → add_child()
 *   PUT    /wp-json/rk-mc/v1/children/{id}     → update_child()
 *   DELETE /wp-json/rk-mc/v1/children/{id}     → delete_child()
 *   GET    /wp-json/rk-mc/v1/children/stats    → get_stats()   (Hero Banner)
 *
 * @since 4.1.0 Messages d'erreur distincts : un doublon de nom renvoyait
 *        « échec base de données », message trompeur pour le parent.
 */
class RK_MC_Rest {

    const NAMESPACE    = 'rk-mc/v1';
    const ROUTE_LIST   = '/children';
    const ROUTE_ITEM   = '/children/(?P<id>[\d]+)';
    const ROUTE_STATS  = '/children/stats';

    public static function init() {
        add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
    }

    public static function register_routes() {

        // GET /wp-json/rk-mc/v1/children
        register_rest_route( self::NAMESPACE, self::ROUTE_LIST, array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array( __CLASS__, 'list_children' ),
            'permission_callback' => array( __CLASS__, 'user_is_logged_in' ),
        ) );

        // POST /wp-json/rk-mc/v1/children
        register_rest_route( self::NAMESPACE, self::ROUTE_LIST, array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array( __CLASS__, 'add_child' ),
            'permission_callback' => array( __CLASS__, 'user_is_logged_in' ),
            'args'                => self::child_args(),
        ) );

        // PUT /wp-json/rk-mc/v1/children/{id}
        register_rest_route( self::NAMESPACE, self::ROUTE_ITEM, array(
            'methods'             => WP_REST_Server::EDITABLE,
            'callback'            => array( __CLASS__, 'update_child' ),
            'permission_callback' => array( __CLASS__, 'user_is_logged_in' ),
            'args'                => array_merge(
                array(
                    'id' => array(
                        'required'          => true,
                        'type'              => 'integer',
                        'sanitize_callback' => 'absint',
                    ),
                ),
                self::child_args( false )
            ),
        ) );

        // DELETE /wp-json/rk-mc/v1/children/{id}
        register_rest_route( self::NAMESPACE, self::ROUTE_ITEM, array(
            'methods'             => WP_REST_Server::DELETABLE,
            'callback'            => array( __CLASS__, 'delete_child' ),
            'permission_callback' => array( __CLASS__, 'user_is_logged_in' ),
            'args'                => array(
                'id' => array(
                    'required'          => true,
                    'type'              => 'integer',
                    'sanitize_callback' => 'absint',
                    'validate_callback' => function( $v ) {
                        return is_numeric( $v ) && (int) $v > 0;
                    },
                ),
            ),
        ) );

        // GET /wp-json/rk-mc/v1/children/stats  (Hero Banner)
        // v2.9 — Upload de la photo de l'enfant vers la BIBLIOTHÈQUE WordPress.
        register_rest_route( self::NAMESPACE, '/children/avatar', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'upload_avatar' ),
            'permission_callback' => array( __CLASS__, 'user_is_logged_in' ),
        ) );

        register_rest_route( self::NAMESPACE, self::ROUTE_STATS, array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array( __CLASS__, 'get_stats' ),
            'permission_callback' => array( __CLASS__, 'user_is_logged_in' ),
        ) );

        // POST /wp-json/rk-mc/v1/missions/{id}/progress  (child self-report)
        register_rest_route( self::NAMESPACE, '/missions/(?P<id>[\d]+)/progress', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array( __CLASS__, 'record_mission_progress' ),
            'permission_callback' => array( __CLASS__, 'user_is_logged_in' ),
            'args'                => array(
                'id' => array(
                    'required'          => true,
                    'type'              => 'integer',
                    'sanitize_callback' => 'absint',
                ),
            ),
        ) );
    }

    /* ─────────────────────────────────────────
     * Args definition
     * ───────────────────────────────────────── */

    private static function child_args( $name_required = true ) {
        return array(
            'child_name' => array(
                'required'          => $name_required,
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'validate_callback' => function( $v ) {
                    return is_string( $v ) && mb_strlen( trim( $v ) ) > 0 && mb_strlen( $v ) <= 255;
                },
            ),
            'child_family_name' => array(
                'required'          => false,
                'type'              => 'string',
                'default'           => '',
                'sanitize_callback' => 'sanitize_text_field',
                'validate_callback' => function( $v ) {
                    return ! is_string( $v ) || mb_strlen( $v ) <= 255;
                },
            ),
            'child_username' => array(
                'required'          => false,
                'type'              => 'string',
                'default'           => '',
                'sanitize_callback' => function( $v ) {
                    // sanitize_user() attend (string $username, bool $strict = false).
                    // Le sanitize_callback REST est invoqué avec 3 arguments
                    // (valeur, WP_REST_Request, nom du param) : passer cet objet
                    // directement à sanitize_user() provoquait une TypeError
                    // fatale (HTTP 500) sous PHP strict_types.
                    return sanitize_user( (string) $v, true );
                },
                'validate_callback' => function( $v ) {
                    return '' === $v || ( preg_match( '/^[a-zA-Z0-9_]{3,60}$/', $v ) === 1 );
                },
            ),
            'child_age' => array(
                'required'          => false,
                'type'              => 'string',
                'default'           => '',
                'sanitize_callback' => 'sanitize_text_field',
                'validate_callback' => function( $v ) {
                    return mb_strlen( $v ) <= 20;
                },
            ),
            'avatar_url' => array(
                'required'          => false,
                'type'              => 'string',
                'default'           => '',
                'sanitize_callback' => 'esc_url_raw',
                'validate_callback' => function( $v ) {
                    return empty( $v ) || mb_strlen( $v ) <= 500;
                },
            ),
        );
    }

    /* ─────────────────────────────────────────
     * Traduction des erreurs métier du Repository
     * ───────────────────────────────────────── */

    /**
     * Convertit le code d'erreur du Repository en WP_Error explicite.
     * Retourne null si la cause n'est pas identifiable (le caller garde
     * alors son message par défaut).
     */
    private static function repo_error( string $fallback_code, string $fallback_msg, int $fallback_status ) {
        $err = class_exists( 'RK_MC_Child_Repository' )
            ? RK_MC_Child_Repository::last_error()
            : '';

        switch ( $err ) {
            case 'name_taken':
                return new WP_Error(
                    'rk_mc_name_taken',
                    __( 'لديك طفل آخر بنفس الاسم. أضف اسم العائلة للتفريق بينهما.', 'rk-my-children' ),
                    array( 'status' => 409 )
                );

            case 'not_owner':
                return new WP_Error(
                    'rk_mc_not_found',
                    __( 'السجل غير موجود أو ليس لديك صلاحية تعديله.', 'rk-my-children' ),
                    array( 'status' => 404 )
                );
                
                case 'username_taken':
                return new WP_Error(
                    'rk_mc_username_taken',
                    __( 'اسم المستخدم هذا مستخدم بالفعل. جرّب اسماً آخر.', 'rk-my-children' ),
                    array( 'status' => 409 )
                );

            case 'no_fields':
                return new WP_Error(
                    'rk_mc_no_fields',
                    __( 'لا توجد بيانات لتحديثها.', 'rk-my-children' ),
                    array( 'status' => 400 )
                );
        }

        return new WP_Error( $fallback_code, $fallback_msg, array( 'status' => $fallback_status ) );
    }

    /* ─────────────────────────────────────────
     * Permission
     * ───────────────────────────────────────── */

    /**
     * v2.9 — Photo de l'enfant : upload vers la bibliothèque de médias.
     *
     * Reçoit `avatar_file` (multipart), valide type/poids, enregistre via
     * media_handle_upload() (⇒ visible dans Médias, miniatures générées),
     * puis renvoie l'URL taille 'medium' à stocker dans wp_rk_children.avatar_url.
     */
    public static function upload_avatar( WP_REST_Request $request ) {
        if ( empty( $_FILES['avatar_file'] ) ) {
            return new WP_Error( 'rk_no_file', __( 'لم يتم اختيار أي صورة.', 'rk-my-children' ), array( 'status' => 400 ) );
        }

        $file = $_FILES['avatar_file']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- géré par media_handle_upload

        // Garde-fous AVANT de toucher au disque.
        $allowed = array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' );
        $type    = (string) ( $file['type'] ?? '' );
        $size    = (int) ( $file['size'] ?? 0 );
        if ( ! in_array( $type, $allowed, true ) ) {
            return new WP_Error( 'rk_bad_type', __( 'الملف يجب أن يكون صورة (JPG, PNG, GIF, WebP).', 'rk-my-children' ), array( 'status' => 415 ) );
        }
        if ( $size <= 0 || $size > 2 * 1024 * 1024 ) {
            return new WP_Error( 'rk_too_big', __( 'حجم الصورة يجب ألا يتجاوز 2 ميغابايت.', 'rk-my-children' ), array( 'status' => 413 ) );
        }

        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        $attachment_id = media_handle_upload( 'avatar_file', 0, array(
            'post_title' => sanitize_text_field( (string) $request->get_param( 'child_name' ) ) ?: __( 'صورة طفل', 'rk-my-children' ),
        ) );
        if ( is_wp_error( $attachment_id ) ) {
            return new WP_Error( 'rk_upload_failed', $attachment_id->get_error_message(), array( 'status' => 500 ) );
        }

        // Traçabilité dans la bibliothèque : qui a uploadé, pour quel usage.
        update_post_meta( $attachment_id, '_rk_child_avatar', 1 );
        update_post_meta( $attachment_id, '_rk_parent_user',  get_current_user_id() );

        // Taille 'medium' si dispo (assez pour un avatar 120px), sinon l'original.
        $url = wp_get_attachment_image_url( $attachment_id, 'medium' ) ?: wp_get_attachment_url( $attachment_id );

        return new WP_REST_Response( array(
            'success'       => true,
            'url'           => esc_url_raw( (string) $url ),
            'attachment_id' => (int) $attachment_id,
        ), 201 );
    }

    public static function user_is_logged_in() {
        if ( ! is_user_logged_in() ) {
            return new WP_Error(
                'rk_mc_not_logged_in',
                __( 'يجب تسجيل الدخول أولاً.', 'rk-my-children' ),
                array( 'status' => 401 )
            );
        }
        return true;
    }

    /* ─────────────────────────────────────────
     * List Children
     * ───────────────────────────────────────── */

    public static function list_children() {
        $user_id  = get_current_user_id();
        $children = rk_mc_get_children( $user_id );
        $data     = array();

        foreach ( $children as $child ) {
            $data[] = self::format_child( $child );
        }

        return rest_ensure_response( array( 'success' => true, 'children' => $data ) );
    }

    /* ─────────────────────────────────────────
     * Add Child
     * ───────────────────────────────────────── */

    public static function add_child( WP_REST_Request $request ) {

        $user_id      = get_current_user_id();
        $child_name   = (string) $request->get_param( 'child_name' );
        $child_family = (string) $request->get_param( 'child_family_name' );
        $child_username = (string) $request->get_param( 'child_username' );
        $child_age    = $request->get_param( 'child_age' );
        $avatar_url   = $request->get_param( 'avatar_url' );

        if ( '' === trim( $child_name ) ) {
            return new WP_Error(
                'rk_mc_name_required',
                __( 'اسم الطفل مطلوب.', 'rk-my-children' ),
                array( 'status' => 422 )
            );
        }

        $insert_id = rk_mc_insert_child( array(
            'user_id'           => $user_id,
            'child_name'        => $child_name,
            'child_family_name' => $child_family,
            'child_username'    => $child_username,
            'child_age'         => $child_age,
            'avatar_url'        => $avatar_url,
            'created_at'        => current_time( 'mysql' ),
        ) );

        if ( false === $insert_id ) {
            return self::repo_error(
                'rk_mc_db_error',
                __( 'فشل حفظ البيانات في قاعدة البيانات.', 'rk-my-children' ),
                500
            );
        }

        // BUGFIX (13/08/2026) — create_for_child() n'était jamais appelée
        // à la création : le compte WP dédié + PIN d'accès dashboard
        // restaient absents jusqu'à ce qu'un mécanisme de réparation
        // paresseuse (ailleurs dans le code) les génère plus tard. La
        // carte retournée immédiatement après création affichait donc
        // ni PIN ni lien fonctionnel vers le dashboard enfant — corrigé
        // en créant le compte WP explicitement ici, avant format_child().
        if ( class_exists( 'RK_MC_Child_User' ) ) {
            RK_MC_Child_User::create_for_child( $insert_id, $user_id );
        }

        $child      = rk_mc_get_child( $insert_id, $user_id );
        $child_data = self::format_child( $child );
        $child_data['card_html'] = self::render_card_html( $child );

        return rest_ensure_response( array(
            'success' => true,
            'message' => __( 'تم حفظ الطفل بنجاح', 'rk-my-children' ),
            'child'   => $child_data,
        ) );
    }

    /* ─────────────────────────────────────────
     * Update Child
     * ───────────────────────────────────────── */

    public static function update_child( WP_REST_Request $request ) {

        $user_id  = get_current_user_id();
        $child_id = (int) $request->get_param( 'id' );

        $update = array_filter( array(
            'child_name'        => $request->get_param( 'child_name' ),
            'child_family_name' => $request->get_param( 'child_family_name' ),
            'child_username'    => $request->get_param( 'child_username' ),
            'child_age'         => $request->get_param( 'child_age' ),
            'avatar_url'        => $request->get_param( 'avatar_url' ),
        ), function( $v ) { return $v !== null; } );

        $result = rk_mc_update_child( $child_id, $user_id, $update );

        if ( false === $result ) {
            return self::repo_error(
                'rk_mc_not_found',
                __( 'السجل غير موجود أو ليس لديك صلاحية تعديله.', 'rk-my-children' ),
                404
            );
        }

        // Le prénom/nom sert d'identifiant au login enfant : resynchroniser
        // le compte WP, sinon l'ancien nom reste seul valide pour se connecter.
        if ( class_exists( 'RK_MC_Child_User' )
            && method_exists( 'RK_MC_Child_User', 'sync_names' ) ) {
            RK_MC_Child_User::sync_names( $child_id, $user_id );
        }

        $child      = rk_mc_get_child( $child_id, $user_id );
        $child_data = self::format_child( $child );
        $child_data['card_html'] = self::render_card_html( $child );

        return rest_ensure_response( array(
            'success' => true,
            'message' => __( 'تم تعديل البيانات بنجاح', 'rk-my-children' ),
            'child'   => $child_data,
        ) );
    }

    /* ─────────────────────────────────────────
     * Delete Child
     * ───────────────────────────────────────── */

    public static function delete_child( WP_REST_Request $request ) {

        $user_id  = get_current_user_id();
        $child_id = (int) $request->get_param( 'id' );

        $result = rk_mc_delete_child( $child_id, $user_id );

        if ( false === $result ) {
            return new WP_Error(
                'rk_mc_not_found',
                __( 'السجل غير موجود أو ليس لديك صلاحية حذفه.', 'rk-my-children' ),
                array( 'status' => 404 )
            );
        }

        return rest_ensure_response( array(
            'success' => true,
            'message' => __( 'تم حذف الطفل بنجاح', 'rk-my-children' ),
        ) );
    }

    /* ─────────────────────────────────────────
     * Stats (Hero Banner)
     * ───────────────────────────────────────── */

    public static function get_stats() {
        $user_id = get_current_user_id();
        return rest_ensure_response( array(
            'success'       => true,
            'children_count'=> rk_mc_count_children( $user_id ),
            'bookings_count'=> rk_mc_count_bookings( $user_id ),
            'sessions_count'=> rk_mc_count_sessions( $user_id ),
            'credits'       => rk_mc_get_session_credits( $user_id ),
        ) );
    }

    /* ─────────────────────────────────────────
     * POST /missions/{id}/progress — child self-report
     * ───────────────────────────────────────── */

    /**
     * Chaîne d'autorisation complète (§18) — phase 6b-1.
     *
     * Avant : find_by_wp_user_id( get_current_user_id() ), qui supposait que
     * le current user ETAIT l'enfant. Cela ne fonctionnait que grace a la
     * substitution d'identite, et l'appartenance parent->enfant n'etait jamais
     * verifiee : la route se contentait de is_user_logged_in().
     *
     * Maintenant, chaine explicite et sans substitution :
     *
     *     authenticated_parent_id()          parent authentifie par cookie
     *              v
     *     child_id()                         enfant du contexte, ownership
     *                                        parent->enfant deja verifie
     *              v
     *     mission.child_id === child_id      la mission appartient a l'enfant
     *              v
     *     record_progress()
     *
     * Aucun maillon ne fait confiance a ce que le navigateur envoie : le
     * mission_id de l'URL est le seul parametre client, et il est valide
     * contre l'enfant du contexte.
     */
    public static function record_mission_progress( WP_REST_Request $request ) {
        $mission_id = (int) $request->get_param( 'id' );

        if ( ! class_exists( 'RKP_CoachMissionRepository' ) ) {
            return new WP_REST_Response( [ 'code' => 'service_unavailable' ], 503 );
        }
        if ( ! class_exists( 'RK_Identity_Context' ) ) {
            return new WP_REST_Response( [ 'code' => 'identity_unavailable' ], 503 );
        }

        // 1. Parent reellement authentifie (jamais get_current_user_id()).
        if ( RK_Identity_Context::authenticated_parent_id() <= 0 ) {
            return new WP_REST_Response( [ 'code' => 'not_authenticated' ], 401 );
        }

        // 2. Enfant du contexte - appartenance au parent deja verifiee.
        $child_rk_id = RK_Identity_Context::child_id();
        if ( $child_rk_id <= 0 ) {
            return new WP_REST_Response( [ 'code' => 'no_child_context' ], 403 );
        }

        // 3. La mission appartient bien a CET enfant.
        $m = RKP_CoachMissionRepository::find_by_id( $mission_id );
        if ( ! $m || (int) $m->child_id !== $child_rk_id ) {
            return new WP_REST_Response( [ 'code' => 'not_found' ], 404 );
        }

        $ok = RKP_CoachMissionCommandService::record_progress( $mission_id, (int) $m->coach_id );
        return new WP_REST_Response( [ 'success' => $ok ], $ok ? 200 : 409 );
    }

    /* ─────────────────────────────────────────
     * Render card HTML (server-side, for add/update responses)
     * ───────────────────────────────────────── */

    private static function render_card_html( $child ): string {
        if ( ! $child ) {
            return '';
        }
        $template = RK_MC_DIR . 'templates/components/child-card.php';
        if ( ! file_exists( $template ) ) {
            return '';
        }
        ob_start();
        require $template;
        return (string) ob_get_clean();
    }

    /* ─────────────────────────────────────────
     * Format child for REST response
     * ───────────────────────────────────────── */

    private static function format_child( $child ) {
        if ( ! $child ) {
            return null;
        }
        $child_id   = (int) $child->id;
        $wp_user_id = (int) ( $child->wp_user_id ?? 0 );
        $child_pin  = ( $wp_user_id && class_exists( 'RK_MC_Child_User' ) )
                        ? RK_MC_Child_User::get_child_pin( $wp_user_id )
                        : '';
        $family = (string) ( $child->child_family_name ?? '' );

        return array(
            'id'                => $child_id,
            'child_name'        => $child->child_name,
            'child_family_name' => $family,
            'child_full_name'   => trim( $child->child_name . ' ' . $family ),
            'child_username'    => (string) ( $child->child_username ?? '' ),
            'display_name'      => (string) ( $child->display_name ?? '' ),
            'child_age'         => $child->child_age,
            'avatar_url'        => rk_mc_get_avatar_url( $child ),
            'created_at'        => $child->created_at,
            'wp_user_id'        => $wp_user_id,
            'child_pin'         => $child_pin,
            'dashboard_url'     => RK_MC_Child_Context::get_dashboard_url( $child_id ),
            'booking_url'       => RK_MC_Child_Context::get_booking_url( $child_id ),
            'history_url'       => RK_MC_Child_Context::get_history_url( $child_id ),
        );
    }
}