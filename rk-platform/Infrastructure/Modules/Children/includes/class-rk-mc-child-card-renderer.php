<?php
/**
 * RK_MC_Child_Card_Renderer  (v5.2.0 - Fixed)
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * RÔLE
 * ─────────────────────────────────────────────────────────────────────────────
 * Point d'entrée unique pour rendre une carte enfant HTML.
 * Toutes les pages qui affichent une carte enfant passent par cette classe.
 *
 * @package RK_My_Children
 * @since   5.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class RK_MC_Child_Card_Renderer {

    /**
     * Chemin absolu vers le template composant.
     */
    const TEMPLATE = 'templates/components/child-card.php';

    /* ─────────────────────────────────────────
     * API Publique
     * ───────────────────────────────────────── */

    /**
     * Rend une seule carte enfant.
     *
     * @param  object $child  Objet enfant (stdClass depuis rk_children).
     * @return void
     */
    public static function render( $child ) {

        if ( ! self::is_valid_child( $child ) ) {
            return;
        }

        $template = RK_MC_DIR . self::TEMPLATE;

        if ( ! file_exists( $template ) ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                rkp_log( '[RK_MC] Composant manquant : ' . $template );
            }
            return;
        }

        require $template;
    }

    /**
     * Rend une grille de cartes enfants.
     *
     * @param  array  $children       Tableau d'objets enfants.
     * @param  string $grid_id        Attribut id du conteneur (optionnel).
     * @param  bool   $show_add_card  v11.0 — Affiche en tête de grille la carte
     *                                pointillée "طفل جديد" (style riadakids-booking,
     *                                étape 4). false par défaut pour ne pas affecter
     *                                le slider compact du dashboard (render_recent).
     * @return void
     */
    public static function render_grid( array $children, $grid_id = '', bool $show_add_card = false ) {

        $id_attr = $grid_id ? ' id="' . esc_attr( $grid_id ) . '"' : '';
        echo '<div class="rk-card-grid"' . $id_attr . '>' . "\n";

        if ( $show_add_card ) {
            $add_tpl = RK_MC_DIR . 'templates/components/child-card-add.php';
            if ( file_exists( $add_tpl ) ) {
                require $add_tpl;
            }
        }

        if ( ! empty( $children ) ) {
            foreach ( $children as $child ) {
                self::render( $child );
            }
        } elseif ( ! $show_add_card ) {
            // L'état vide dédié n'a de sens que quand la carte "ajouter"
            // n'est pas déjà affichée (sinon elle en tient lieu).
            $empty_tpl = RK_MC_DIR . 'templates/child-empty-state.php';
            if ( file_exists( $empty_tpl ) ) {
                require $empty_tpl;
            }
        }

        echo '</div>' . "\n"; 
    }

    /**
     * Récupère et rend les N derniers enfants d'un utilisateur.
     * Utilisé par le dashboard WooCommerce (affichage compact).
     *
     * @param  int  $user_id  ID de l'utilisateur WP.
     * @param  int  $limit    Nombre maximum de cartes à afficher. Défaut : 3.
     * @return void
     */
    public static function render_recent( $user_id, $limit = 3 ) {
        $children = array();
        $limit    = absint( $limit );

        // التحقق من وجود الدالة الأساسية أولاً لتجنب الخطأ الفادح
        if ( function_exists( 'rk_mc_get_children_limited' ) ) {
            $children = rk_mc_get_children_limited( $user_id, $limit );
        } 
        // حل بديل (Fallback) في حال كانت الدالة غير معرفة ولكن دالة جلب الكل موجودة
        elseif ( function_exists( 'rk_mc_get_children' ) ) {
            $all_children = rk_mc_get_children( $user_id );
            if ( is_array( $all_children ) ) {
                $children = array_slice( $all_children, 0, $limit );
            }
        } 
        // حل احتياطي أخير عبر قاعدة البيانات مباشرة لجلب البيانات وحماية الموقع من الانهيار
        else {
            global $wpdb;
            $table_name = $wpdb->prefix . 'rk_children'; // تأكد من اسم الجدول الخاص بالإضافة
            if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table_name ) ) === $table_name ) {
                $children = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT * FROM {$table_name} WHERE user_id = %d ORDER BY id DESC LIMIT %d",
                        $user_id,
                        $limit
                    )
                );
            }
        }

        self::render_grid( $children );
    }

    /**
     * Enqueue les assets nécessaires aux cartes enfants (CSS + JS interactif).
     */
    public static function enqueue_assets() {

        $css_files = array(
            'rk-mc-card'    => 'assets/css/child-card.css',
            'rk-mc-actions' => 'assets/css/child-actions.css',
        );

        $prev = array();
        foreach ( $css_files as $handle => $path ) {
            if ( ! wp_style_is( $handle, 'enqueued' ) ) {
                wp_enqueue_style( $handle, RK_MC_URL . $path, $prev, RK_MC_VERSION );
            }
            $prev = array( $handle );
        }

        $js_files = array(
            'rk-mc-notifications' => array(
                'src'  => 'assets/js/child-notifications.js',
                'deps' => array( 'jquery' ),
            ),
            'rk-mc-list' => array(
                'src'  => 'assets/js/child-list.js',
                'deps' => array( 'jquery', 'rk-mc-notifications' ),
            ),
            'rk-mc-edit' => array(
                'src'  => 'assets/js/child-edit.js',
                'deps' => array( 'jquery', 'rk-mc-list' ),
            ),
            'rk-mc-delete' => array(
                'src'  => 'assets/js/child-delete.js',
                'deps' => array( 'jquery', 'rk-mc-list', 'rk-mc-notifications' ),
            ),
        );

        foreach ( $js_files as $handle => $config ) {
            if ( ! wp_script_is( $handle, 'enqueued' ) ) {
                wp_enqueue_script(
                    $handle,
                    RK_MC_URL . $config['src'],
                    $config['deps'],
                    RK_MC_VERSION,
                    true
                );
            }
        }

        if ( ! wp_script_is( 'rk-mc-notifications', 'enqueued' ) ) {
            return; 
        }

        global $rk_mc_localized;
        if ( ! empty( $rk_mc_localized ) ) {
            return;
        }
        $rk_mc_localized = true;

        $user_id = get_current_user_id();

        // دوال الإحصائيات احتياطاً لتجنب أخطاء مشابهة إذا لم تكن معرفة
        $children_count = function_exists('rk_mc_count_children') ? rk_mc_count_children( $user_id ) : 0;
        $bookings_count = function_exists('rk_mc_count_bookings') ? rk_mc_count_bookings( $user_id ) : 0;
        $sessions_count = function_exists('rk_mc_count_sessions') ? rk_mc_count_sessions( $user_id ) : 0;
        $credits_count  = function_exists('rk_mc_get_session_credits') ? rk_mc_get_session_credits( $user_id ) : 0;

        wp_localize_script( 'rk-mc-notifications', 'rkMC', array(
            'restUrl'       => esc_url_raw( rest_url( 'rk-mc/v1/children' ) ),
            'restNonce'     => wp_create_nonce( 'wp_rest' ),
            'dashboardBase' => esc_url( home_url( RK_TUTOR_DASHBOARD_URL ) ),
            'childSpaceUrl' => esc_url( home_url( '/connexion-child/' ) ),
            'stats' => array(
                'children' => $children_count,
                'bookings' => $bookings_count,
                'sessions' => $sessions_count,
                'credits'  => $credits_count,
            ),
            'i18n' => array(
                'confirmDelete' => __( 'هل أنت متأكد من حذف هذا الطفل؟', 'rk-my-children' ),
                'errorDelete'   => __( 'حدث خطأ أثناء الحذف، يرجى المحاولة مجدداً.', 'rk-my-children' ),
                'errorAdd'      => __( 'حدث خطأ أثناء الإضافة، يرجى المحاولة مجدداً.', 'rk-my-children' ),
                'errorNetwork'  => __( 'تعذر الاتصال بالخادم.', 'rk-my-children' ),
                'emptyMsg'      => __( 'لا يوجد أطفال مسجلون حالياً.', 'rk-my-children' ),
                'years'         => __( 'سنة', 'rk-my-children' ),
                'required'      => __( 'هذا الحقل مطلوب', 'rk-my-children' ),
                'btnDashboard'  => __( 'الدخول للوحة التحكم', 'rk-my-children' ),
                'btnReserve'    => __( 'حجز حصة', 'rk-my-children' ),
                'btnEdit'       => __( 'تعديل', 'rk-my-children' ),
                'btnDelete'     => __( 'حذف', 'rk-my-children' ),
                // AJOUT (bug signalé — bouton rapport rétabli sur la
                // carte, voir templates/components/child-card.php et
                // buildCard() dans assets/js/child-list.js) — clés i18n
                // manquantes pour le repli JS, jusqu'ici couvertes
                // seulement par leur valeur par défaut en dur dans le JS.
                'btnReports'    => __( 'التقارير', 'rk-my-children' ),
                'btnView'       => __( 'عرض', 'rk-my-children' ),
                'btnMore'       => __( 'المزيد من الإجراءات', 'rk-my-children' ),
                'savedOk'       => __( 'تم حفظ التغييرات بنجاح!', 'rk-my-children' ),
                'editTitle'     => __( 'تعديل بيانات الطفل', 'rk-my-children' ),
                'addTitle'      => __( 'إضافة طفل جديد', 'rk-my-children' ),
            ),
        ) );
    }

    /* ─────────────────────────────────────────
     * Helpers internes
     * ───────────────────────────────────────── */

    private static function is_valid_child( $child ) {
        return (
            ! empty( $child )
            && is_object( $child )
            && ! empty( $child->id )
            && ! empty( $child->child_name )
        );
    }
}