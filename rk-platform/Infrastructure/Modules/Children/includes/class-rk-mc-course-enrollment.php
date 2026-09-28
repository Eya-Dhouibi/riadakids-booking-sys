<?php
declare( strict_types=1 );
/**
 * RK_MC_Course_Enrollment  (v5.5.0)
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * LIAISON  WooCommerce Product  ↔  Tutor LMS Course
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * ARCHITECTURE
 * ─────────────────────────────────────────────────────────────────────────────
 *   WC Product  ──(_rk_tutor_course_id)──▶  Tutor LMS Course
 *
 *   Chaque produit WC peut être lié à un cours Tutor LMS.
 *   À la complétion de la commande, tous les enfants (rk_child) du parent
 *   acheteur sont inscrits automatiquement dans ce cours.
 *
 * FLUX
 * ─────────────────────────────────────────────────────────────────────────────
 *   1. Admin ouvre un produit WC en édition
 *   2. Champ "مساق Tutor LMS" : sélectionner le cours correspondant
 *   3. Parent achète le produit → commande complétée
 *   4. Hook woocommerce_order_status_completed :
 *        pour chaque article → course_id → do_enroll(child_wp_user_id)
 *   5. Le cours apparaît dans le dashboard enfant (section Aventures)
 *
 * RETROACTIF
 * ─────────────────────────────────────────────────────────────────────────────
 *   sync_from_bookings() :
 *   Parcourt wp_rk_bookings (booking plugin), retrouve le produit WC,
 *   retrouve le cours Tutor LMS, inscrit le child. Idempotent.
 *   Déclenché depuis la page admin "Enfants RiadaKids".
 *
 * RÈGLE
 * ─────────────────────────────────────────────────────────────────────────────
 *   Plugin RiadaKids Booking : ZÉRO modification.
 *   Tous les hooks sont sur WooCommerce et WordPress natifs.
 *
 * @package RK_My_Children
 * @since   5.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class RK_MC_Course_Enrollment {

    /* ─────────────────────────────────────────
     * Clés méta
     * ───────────────────────────────────────── */

    /** Meta WC product → ID cours Tutor LMS */
    const PRODUCT_COURSE_META = '_rk_tutor_course_id';

    /** Meta Tutor LMS course → ID produit WC */
    const COURSE_PRODUCT_META = '_rk_wc_product_id';

    /** Meta WC order : log des inscriptions effectuées */
    const ORDER_ENROLLED_META = '_rk_enrolled_children_log';

    /** Meta WC order : flag "traitement déjà fait" */
    const ORDER_PROCESSED_META = '_rk_enrollment_processed';

    /* ─────────────────────────────────────────
     * Init
     * ───────────────────────────────────────── */

    public static function init() {

        /* ── Admin WC Product ──────────────────────────── */
        add_action(
            'woocommerce_product_options_general_product_data',
            array( __CLASS__, 'render_product_course_field' )
        );
        add_action(
            'woocommerce_process_product_meta',
            array( __CLASS__, 'save_product_course_field' )
        );

        /* ── Admin Tutor LMS Course ────────────────────── */
        add_action( 'add_meta_boxes', array( __CLASS__, 'register_course_metabox' ) );
        add_action( 'save_post_courses', array( __CLASS__, 'save_course_product_meta' ), 10, 2 );

        /* ── Auto-création cours Tutor LMS depuis produit WC ─ */
        // Quand un produit WC est publié/modifié sans cours lié → crée le cours Tutor LMS automatiquement.
        // Priorité 25 : après save_wc_product_meta de Tutor LMS (priorité 10) et notre save_product_course_field.
        add_action( 'save_post_product', array( __CLASS__, 'auto_create_course_for_product' ), 25, 2 );

        /* ── Students = enfants (hook woocommerce_order_status_changed, priorité 20) ──
         * Tutor LMS inscrit le parent à priorité 10.
         * Nous inscrivons les enfants à priorité 20 et annulons l'inscription du parent.
         */
        add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'on_order_status_changed' ), 20, 3 );

        /* ── Sync rétroactif (depuis la page admin) ────── */
        add_action( 'admin_post_rk_sync_enrollments', array( __CLASS__, 'handle_sync_request' ) );
    }

    /* ═══════════════════════════════════════════════════════════════
     * ADMIN — Produit WC
     * ═══════════════════════════════════════════════════════════════ */

    /**
     * Ajoute un champ de sélection de cours Tutor LMS dans l'onglet
     * "Général" du produit WooCommerce (à côté de _rk_session_credits).
     */
    public static function render_product_course_field(): void {
        global $post;

        $current = (int) get_post_meta( $post->ID, self::PRODUCT_COURSE_META, true );

        $courses = get_posts( array(
            'post_type'      => 'courses',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'orderby'        => 'title',
            'order'          => 'ASC',
            'fields'         => array( 'ID', 'post_title' ),
        ) );

        $options = array( 0 => __( '— غير مرتبط —', 'rk-my-children' ) );
        foreach ( $courses as $c ) {
            $options[ $c->ID ] = $c->post_title;
        }

        echo '<div class="options_group rk-enrollment-field">';
        woocommerce_wp_select( array(
            'id'          => self::PRODUCT_COURSE_META,
            'label'       => __( 'مساق Tutor LMS المرتبط', 'rk-my-children' ),
            'desc_tip'    => true,
            'description' => __( 'عند اكتمال الطلب، يُسجَّل أطفال ولي الأمر تلقائياً في هذا المساق.', 'rk-my-children' ),
            'options'     => $options,
            'value'       => $current ?: 0,
        ) );

        // Afficher le lien vers le cours si déjà lié
        if ( $current ) {
            $course_url = get_edit_post_link( $current );
            $title      = get_the_title( $current );
            printf(
                '<p class="description" style="margin-right:12px;color:#1b4f8c;">
                    ✓ <a href="%s" target="_blank">%s</a>
                 </p>',
                esc_url( $course_url ),
                esc_html( $title )
            );
        }

        echo '</div>';
    }

    public static function save_product_course_field( int $product_id ): void {
        $course_id = absint( $_POST[ self::PRODUCT_COURSE_META ] ?? 0 );

        if ( $course_id && get_post_type( $course_id ) === 'courses' ) {
            update_post_meta( $product_id, self::PRODUCT_COURSE_META, $course_id );
            // Écriture bidirectionnelle sur le cours
            update_post_meta( $course_id, self::COURSE_PRODUCT_META, $product_id );
        } else {
            delete_post_meta( $product_id, self::PRODUCT_COURSE_META );
        }
    }

    /* ═══════════════════════════════════════════════════════════════
     * ADMIN — Cours Tutor LMS
     * ═══════════════════════════════════════════════════════════════ */

    public static function register_course_metabox(): void {
        add_meta_box(
            'rk_course_product_link',
            __( 'RiadaKids — منتج WooCommerce المرتبط', 'rk-my-children' ),
            array( __CLASS__, 'render_course_metabox' ),
            'courses',
            'side',
            'default'
        );
    }

    public static function render_course_metabox( \WP_Post $post ): void {
        wp_nonce_field( 'rk_course_product_save', 'rk_course_product_nonce' );

        $current_product_id = (int) get_post_meta( $post->ID, self::COURSE_PRODUCT_META, true );

        // Tous les produits WC qui ont ce cours ou qui n'ont pas de cours lié
        $all_products = get_posts( array(
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'orderby'        => 'title',
            'order'          => 'ASC',
            'fields'         => array( 'ID', 'post_title' ),
        ) );

        ?>
        <p style="margin-bottom:8px;">
            <label for="<?php echo esc_attr( self::COURSE_PRODUCT_META ); ?>" style="font-weight:600;">
                <?php esc_html_e( 'المنتج المرتبط', 'rk-my-children' ); ?>
            </label>
        </p>
        <select name="<?php echo esc_attr( self::COURSE_PRODUCT_META ); ?>"
                id="<?php echo esc_attr( self::COURSE_PRODUCT_META ); ?>"
                style="width:100%;">
            <option value="0"><?php esc_html_e( '— غير مرتبط —', 'rk-my-children' ); ?></option>
            <?php foreach ( $all_products as $product ) : ?>
            <option value="<?php echo esc_attr( $product->ID ); ?>"
                <?php selected( $current_product_id, $product->ID ); ?>>
                <?php echo esc_html( $product->post_title ); ?>
                (#<?php echo esc_html( $product->ID ); ?>)
            </option>
            <?php endforeach; ?>
        </select>

        <?php if ( $current_product_id ) :
            $credits = (int) get_post_meta( $current_product_id, '_rk_session_credits', true ); ?>
        <p style="margin-top:8px;font-size:.8rem;color:#1b4f8c;">
            <?php printf(
                esc_html__( '✓ مرتبط بـ : %s', 'rk-my-children' ),
                '<strong>' . esc_html( get_the_title( $current_product_id ) ) . '</strong>'
            ); ?>
            <?php if ( $credits ) : ?>
            <br><span style="color:#6b7280;">
                <?php printf( esc_html__( '%d حصة / طلب', 'rk-my-children' ), $credits ); ?>
            </span>
            <?php endif; ?>
        </p>
        <?php endif; ?>
        <?php
    }

    public static function save_course_product_meta( int $post_id, \WP_Post $post ): void {
        if ( ! isset( $_POST['rk_course_product_nonce'] )
            || ! wp_verify_nonce( $_POST['rk_course_product_nonce'], 'rk_course_product_save' )
        ) {
            return;
        }
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }

        $product_id = absint( $_POST[ self::COURSE_PRODUCT_META ] ?? 0 );

        if ( $product_id && get_post_type( $product_id ) === 'product' ) {
            update_post_meta( $post_id, self::COURSE_PRODUCT_META, $product_id );
            // Bidirectionnel : écrire aussi sur le produit
            update_post_meta( $product_id, self::PRODUCT_COURSE_META, $post_id );
        } else {
            delete_post_meta( $post_id, self::COURSE_PRODUCT_META );
        }
    }

    /* ═══════════════════════════════════════════════════════════════
     * INSCRIPTION AUTOMATIQUE (WC order completed)
     * ═══════════════════════════════════════════════════════════════ */

    /**
     * Déclenché sur woocommerce_order_status_completed et _processing.
     * Pour chaque article de la commande lié à un cours Tutor LMS,
     * inscrit tous les enfants rk_child du parent.
     */
    public static function on_order_completed( int $order_id ): void {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        // Idempotence : ne pas traiter deux fois
        if ( $order->get_meta( self::ORDER_PROCESSED_META ) ) {
            return;
        }

        $parent_user_id = (int) $order->get_user_id();
        if ( ! $parent_user_id ) {
            return; // Guest checkout — pas d'enfants associables
        }

        // Enfants du parent avec un wp_user_id valide
        $children = array_filter(
            RK_MC_Child_Repository::get_children( $parent_user_id ),
            fn( $c ) => ! empty( $c->wp_user_id ) && (int) $c->wp_user_id > 0
        );

        if ( empty( $children ) ) {
            return;
        }

        $log = array();

        foreach ( $order->get_items() as $item ) {
            $product_id = (int) $item->get_product_id();
            $course_id  = self::get_linked_course_for_product( $product_id );

            if ( ! $course_id ) {
                continue;
            }

            foreach ( $children as $child ) {
                $child_uid = (int) $child->wp_user_id;
                $result    = self::enroll_child_in_course( $course_id, $child_uid, $order_id );

                $log[] = array(
                    'child_id'   => (int) $child->id,
                    'wp_user_id' => $child_uid,
                    'child_name' => $child->child_name,
                    'course_id'  => $course_id,
                    'product_id' => $product_id,
                    'enrolled'   => $result,
                    'at'         => current_time( 'mysql' ),
                );
            }

            // Annuler l'inscription du parent : students = enfants uniquement
            self::cancel_parent_enrollment( $course_id, $parent_user_id );
        }

        // Sauvegarder le log et marquer comme traité
        $order->update_meta_data( self::ORDER_ENROLLED_META,  $log );
        $order->update_meta_data( self::ORDER_PROCESSED_META, '1' );
        $order->save();

        /**
         * Action déclenchée après inscription.
         *
         * @param array $log             Détail des inscriptions.
         * @param int   $order_id
         * @param int   $parent_user_id
         */
        do_action( 'rk_mc_children_enrolled_from_order', $log, $order_id, $parent_user_id );
    }

    /**
     * Wrapper pour woocommerce_order_status_changed (priorité 20).
     * Tutor LMS inscrit le parent à priorité 10 → nous inscrivons les enfants
     * et annulons l'inscription du parent à priorité 20.
     */
    public static function on_order_status_changed( int $order_id, string $from, string $to ): void {
        if ( $to !== 'completed' ) {
            return;
        }
        self::on_order_completed( $order_id );
    }

    /* ═══════════════════════════════════════════════════════════════
     * AUTO-CRÉATION COURS TUTOR LMS DEPUIS PRODUIT WC
     * ═══════════════════════════════════════════════════════════════ */

    /**
     * Hook save_post_product (priorité 25).
     * Si le produit n'a pas encore de cours Tutor LMS lié → en crée un automatiquement.
     */
    public static function auto_create_course_for_product( int $product_id, \WP_Post $post ): void {
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }
        if ( wp_is_post_revision( $product_id ) ) {
            return;
        }
        if ( in_array( $post->post_status, array( 'trash', 'auto-draft' ), true ) ) {
            return;
        }

        // Ne pas recréer si ce produit a déjà un cours lié
        if ( self::get_linked_course_for_product( $product_id ) > 0 ) {
            return;
        }

        // Supprimer le hook temporairement pour éviter la récursion sur save_post_courses
        remove_action( 'save_post_product', array( __CLASS__, 'auto_create_course_for_product' ), 25 );

        $course_id = wp_insert_post( array(
            'post_type'    => 'courses',
            'post_title'   => $post->post_title,
            'post_content' => $post->post_excerpt ?: '',
            'post_status'  => $post->post_status === 'publish' ? 'publish' : 'draft',
            'post_author'  => $post->post_author,
        ), true );

        add_action( 'save_post_product', array( __CLASS__, 'auto_create_course_for_product' ), 25, 2 );

        if ( is_wp_error( $course_id ) || ! $course_id ) {
            return;
        }

        // Liens bidirectionnels (nos metas)
        update_post_meta( $product_id, self::PRODUCT_COURSE_META, $course_id );
        update_post_meta( $course_id, self::COURSE_PRODUCT_META, $product_id );
        // Meta native Tutor LMS → son intégration WC reconnaît le lien
        update_post_meta( $course_id, '_tutor_course_product_id', $product_id );
    }

    /* ═══════════════════════════════════════════════════════════════
     * SYNC RÉTROACTIF (depuis wp_rk_bookings)
     * ═══════════════════════════════════════════════════════════════ */

    /**
     * Parcourt wp_rk_bookings, retrouve le WC product (course_id),
     * retrouve le cours Tutor LMS lié, inscrit le child.
     * Idempotent : is_enrolled() vérifie avant d'inscrire.
     *
     * @return array [ 'enrolled' => int, 'already' => int, 'missing_link' => int ]
     */
    public static function sync_from_bookings(): array {
        if ( ! class_exists( 'RKP_BookingRepository' ) ) {
            return array( 'enrolled' => 0, 'already' => 0, 'missing_link' => 0, 'no_table' => true );
        }

        // Bookings confirmés avec child_id ET course_id (WC product ID)
        $rows = RKP_BookingRepository::get_confirmed_with_course();

        return self::sync_rows( $rows );
    }

    /**
     * Variante ciblée de sync_from_bookings() : synchronise uniquement les
     * bookings confirmés d'UN enfant. Appelée à l'affichage de la fiche
     * enfant (child-profile.php) pour garantir que tout cours réservé via
     * un booking apparaît bien dans "المغامرات" même si l'inscription
     * automatique (woocommerce_order_status_changed) n'a pas eu lieu ou
     * a été manquée — idempotent (is_enrolled() vérifié avant inscription).
     *
     * @param  int $child_id  rk_children.id
     * @return array [ 'enrolled' => int, 'already' => int, 'missing_link' => int ]
     */
    public static function sync_for_child( int $child_id ): array {
        if ( $child_id <= 0 || ! class_exists( 'RKP_BookingRepository' ) ) {
            return array( 'enrolled' => 0, 'already' => 0, 'missing_link' => 0 );
        }

        $rows = RKP_BookingRepository::get_confirmed_with_course_for_child( $child_id );

        return self::sync_rows( $rows );
    }

    /**
     * Logique commune de synchronisation booking → inscription Tutor LMS,
     * factorisée entre sync_from_bookings() (global) et sync_for_child()
     * (ciblé).
     *
     * @param  object[] $rows  { course_id, child_id }
     * @return array [ 'enrolled' => int, 'already' => int, 'missing_link' => int ]
     */
    private static function sync_rows( array $rows ): array {
        $result = array( 'enrolled' => 0, 'already' => 0, 'missing_link' => 0 );

        foreach ( $rows as $row ) {
            $product_id = (int) $row->course_id;   // Dans booking, course_id = WC product ID
            $child_id   = (int) $row->child_id;    // ID dans wp_rk_children

            // Cours Tutor LMS lié au produit WC
            $tutor_course_id = self::get_linked_course_for_product( $product_id );
            if ( ! $tutor_course_id ) {
                $result['missing_link']++;
                continue;
            }

            // wp_user_id de l'enfant
            $child = RK_MC_Child_Repository::get_by_id( $child_id );
            if ( ! $child || empty( $child->wp_user_id ) ) {
                $result['missing_link']++;
                continue;
            }

            $child_uid = (int) $child->wp_user_id;

            // Déjà inscrit ?
            if ( function_exists( 'tutor_utils' )
                && tutor_utils()->is_enrolled( $tutor_course_id, $child_uid )
            ) {
                $result['already']++;
                continue;
            }

            $enrolled = self::enroll_child_in_course( $tutor_course_id, $child_uid, 0 );
            if ( $enrolled ) {
                $result['enrolled']++;
            } else {
                $result['missing_link']++;
            }
        }

        return $result;
    }

    public static function handle_sync_request(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Accès refusé.', 'rk-my-children' ) );
        }
        check_admin_referer( 'rk_sync_enrollments' );

        $result = self::sync_from_bookings();

        $redirect = add_query_arg( array(
            'page'         => 'rk-child-migration',
            'rk_sync_done' => '1',
            'enrolled'     => $result['enrolled'],
            'already'      => $result['already'],
            'missing'      => $result['missing_link'],
        ), admin_url( 'users.php' ) );

        wp_safe_redirect( $redirect );
        exit;
    }

    /* ═══════════════════════════════════════════════════════════════
     * HELPERS
     * ═══════════════════════════════════════════════════════════════ */

    /**
     * Retourne le cours Tutor LMS lié à un produit WC.
     *
     * @param  int $product_id  WC product ID.
     * @return int              Tutor LMS course ID, ou 0.
     */
    public static function get_linked_course_for_product( int $product_id ): int {
        // 1. Notre meta (la plus rapide)
        $course_id = (int) get_post_meta( $product_id, self::PRODUCT_COURSE_META, true );
        if ( $course_id && get_post_type( $course_id ) === 'courses' ) {
            return $course_id;
        }
        // 2. Fallback : meta native Tutor LMS (_tutor_course_product_id sur le cours)
        global $wpdb;
        $course_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta}
             WHERE meta_key = '_tutor_course_product_id' AND meta_value = %d LIMIT 1",
            $product_id
        ) );
        if ( $course_id && get_post_type( $course_id ) === 'courses' ) {
            update_post_meta( $product_id, self::PRODUCT_COURSE_META, $course_id );
            return $course_id;
        }
        return 0;
    }

    /**
     * Retourne le produit WC lié à un cours Tutor LMS.
     *
     * @param  int $course_id  Tutor LMS course ID.
     * @return int             WC product ID, ou 0.
     */
    public static function get_linked_product_for_course( int $course_id ): int {
        return (int) get_post_meta( $course_id, self::COURSE_PRODUCT_META, true );
    }

    /**
     * Inscrit un enfant (wp_user) dans un cours Tutor LMS.
     * Vérifie l'inscription préalable pour être idempotent.
     *
     * @param  int  $course_id      Tutor LMS course ID.
     * @param  int  $child_wp_uid   wp_user_id de l'enfant (rôle rk_child).
     * @param  int  $order_id       WC order ID (0 si sync rétroactif).
     * @return bool True = nouvellement inscrit, False = déjà inscrit ou erreur.
     */
    public static function enroll_child_in_course(
        int $course_id,
        int $child_wp_uid,
        int $order_id = 0
    ): bool {
        if ( ! function_exists( 'tutor_utils' ) ) {
            return false;
        }
        if ( $course_id <= 0 || $child_wp_uid <= 0 ) {
            return false;
        }

        $utils = tutor_utils();

        // Vérifier que l'utilisateur existe et a le bon rôle
        $user = get_user_by( 'id', $child_wp_uid );
        if ( ! $user ) {
            return false;
        }

        // Déjà inscrit → idempotent
        if ( $utils->is_enrolled( $course_id, $child_wp_uid ) ) {
            return false;
        }

        // Inscription via l'API Tutor LMS
        $enrollment_id = $utils->do_enroll( $course_id, $order_id, $child_wp_uid );

        if ( $enrollment_id ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                rkp_log( sprintf(
                    '[RK_MC_Enrollment] Inscrit user #%d au cours #%d (order #%d) — enrollment #%d',
                    $child_wp_uid, $course_id, $order_id, $enrollment_id
                ) );
            }
            return true;
        }

        return false;
    }

    /**
     * Annule l'inscription du parent dans un cours Tutor LMS.
     * Tutor LMS l'inscrit par défaut à l'achat — nous la remplaçons par celle des enfants.
     */
    private static function cancel_parent_enrollment( int $course_id, int $parent_user_id ): void {
        global $wpdb;
        $enrollment_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_type = 'tutor_enrolled'
               AND post_parent = %d
               AND post_author = %d
             LIMIT 1",
            $course_id, $parent_user_id
        ) );
        if ( $enrollment_id ) {
            $wpdb->update(
                $wpdb->posts,
                array( 'post_status' => 'cancelled' ),
                array( 'ID' => $enrollment_id ),
                array( '%s' ),
                array( '%d' )
            );
            clean_post_cache( $enrollment_id );
        }
    }

    /* ═══════════════════════════════════════════════════════════════
     * MAPPING TABLE (page admin)
     * ═══════════════════════════════════════════════════════════════ */

    /**
     * Retourne tous les produits WC ayant un cours Tutor LMS lié.
     * Utilisé pour afficher le tableau de mapping sur la page admin.
     *
     * @return array[]
     */
    public static function get_all_mapped_products(): array {
        $products = get_posts( array(
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'meta_query'     => array( array(
                'key'     => self::PRODUCT_COURSE_META,
                'value'   => 0,
                'compare' => '>',
                'type'    => 'NUMERIC',
            ) ),
        ) );

        $result = array();
        foreach ( $products as $product ) {
            $course_id = self::get_linked_course_for_product( $product->ID );
            if ( ! $course_id ) {
                continue;
            }
            $result[] = array(
                'product_id'    => $product->ID,
                'product_title' => $product->post_title,
                'product_url'   => get_edit_post_link( $product->ID ),
                'credits'       => (int) get_post_meta( $product->ID, '_rk_session_credits', true ),
                'course_id'     => $course_id,
                'course_title'  => get_the_title( $course_id ),
                'course_url'    => get_edit_post_link( $course_id ),
            );
        }
        return $result;
    }
}

