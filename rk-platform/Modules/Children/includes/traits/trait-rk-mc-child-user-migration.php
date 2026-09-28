<?php
declare( strict_types=1 );
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Extrait de class-rk-mc-child-user.php — factorisation par fonctionnalité.
 * Code déplacé verbatim, aucune modification de logique.
 */
trait RK_MC_Child_User_Migration {
    /* ─────────────────────────────────────────
     * Migration des enfants existants
     * ───────────────────────────────────────── */

    /**
     * Crée les wp_users manquants pour tous les enfants existants en base.
     *
     * @return array [ 'created' => int, 'skipped' => int, 'errors' => int ]
     */
    public static function migrate_existing_children(): array {
        global $wpdb;
        $table = rk_mc_children_table();

        $rows = $wpdb->get_results(
            "SELECT id, user_id, wp_user_id FROM {$table} ORDER BY id ASC"
        );

        $result = array( 'created' => 0, 'skipped' => 0, 'errors' => 0 );

        foreach ( $rows as $row ) {
            // Déjà migré
            if ( ! empty( $row->wp_user_id )
                && get_user_by( 'id', (int) $row->wp_user_id )
            ) {
                $result['skipped']++;
                continue;
            }

            $uid = self::create_for_child( (int) $row->id, (int) $row->user_id );
            if ( $uid ) {
                $result['created']++;
            } else {
                $result['errors']++;
            }
        }

        return $result;
    }

    /**
     * Nombre d'enfants sans wp_user (pour la notice admin).
     */
    public static function count_unmigrated(): int {
        global $wpdb;
        $table = rk_mc_children_table();
        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$table}
             WHERE wp_user_id IS NULL OR wp_user_id = 0"
        );
    }

    /* ─────────────────────────────────────────
     * Page admin migration
     * ───────────────────────────────────────── */

    public static function register_migration_page() {
        add_submenu_page(
            'users.php',
            __( 'Migration Enfants RK', 'rk-my-children' ),
            __( 'Enfants RiadaKids', 'rk-my-children' ),
            'manage_options',
            'rk-child-migration',
            array( __CLASS__, 'render_migration_page' )
        );
    }

    public static function render_migration_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $result = null;
        if (
            isset( $_POST['rk_migrate_nonce'] )
            && wp_verify_nonce( $_POST['rk_migrate_nonce'], 'rk_migrate_children' )
        ) {
            $result = self::migrate_existing_children();
        }

        $unmigrated   = self::count_unmigrated();
        $child_users  = get_users( array(
            'role'    => 'rk_child',
            'orderby' => 'display_name',
            'order'   => 'ASC',
        ) );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Enfants RiadaKids — Migration comptes WordPress', 'rk-my-children' ); ?></h1>

            <?php if ( $result ) : ?>
            <div class="notice notice-success is-dismissible">
                <p>
                    <?php printf(
                        esc_html__( 'Migration terminée : %d créés, %d déjà migrés, %d erreurs.', 'rk-my-children' ),
                        $result['created'], $result['skipped'], $result['errors']
                    ); ?>
                </p>
            </div>
            <?php endif; ?>

            <!-- ── Statut migration ── -->
            <table class="form-table" style="max-width:500px;">
                <tr>
                    <th><?php esc_html_e( 'Enfants sans compte WordPress', 'rk-my-children' ); ?></th>
                    <td><strong><?php echo esc_html( $unmigrated ); ?></strong></td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Comptes rk_child créés', 'rk-my-children' ); ?></th>
                    <td><strong><?php echo esc_html( count( $child_users ) ); ?></strong></td>
                </tr>
            </table>

            <?php if ( $unmigrated > 0 ) : ?>
            <form method="post" style="margin-bottom:32px;">
                <?php wp_nonce_field( 'rk_migrate_children', 'rk_migrate_nonce' ); ?>
                <p class="description">
                    <?php esc_html_e(
                        'Cette action crée un compte WordPress avec le rôle rk_child pour chaque enfant sans compte.',
                        'rk-my-children'
                    ); ?>
                </p>
                <p>
                    <input type="submit"
                           class="button button-primary"
                           value="<?php esc_attr_e( 'Lancer la migration', 'rk-my-children' ); ?>">
                </p>
            </form>
            <?php else : ?>
            <p style="color:#1a7a4a;font-weight:600;margin-bottom:24px;">
                ✓ <?php esc_html_e( 'Tous les enfants ont un compte WordPress.', 'rk-my-children' ); ?>
            </p>
            <?php endif; ?>

            <!-- ── Liste des utilisateurs rk_child ── -->
            <h2 style="margin-top:16px;">
                <?php printf(
                    esc_html__( 'Utilisateurs enfants (%d)', 'rk-my-children' ),
                    count( $child_users )
                ); ?>
            </h2>

            <?php if ( empty( $child_users ) ) : ?>
            <p class="description">
                <?php esc_html_e( 'Aucun utilisateur rk_child pour le moment.', 'rk-my-children' ); ?>
            </p>
            <?php else : ?>
            <table class="wp-list-table widefat fixed striped" style="max-width:900px;">
                <thead>
                    <tr>
                        <th style="width:200px;"><?php esc_html_e( 'Nom affiché', 'rk-my-children' ); ?></th>
                        <th style="width:180px;"><?php esc_html_e( 'Identifiant', 'rk-my-children' ); ?></th>
                        <th style="width:80px;"><?php esc_html_e( 'Âge', 'rk-my-children' ); ?></th>
                        <th><?php esc_html_e( 'ولي الأمر (Parent)', 'rk-my-children' ); ?></th>
                        <th style="width:80px;"><?php esc_html_e( 'Actions', 'rk-my-children' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $child_users as $cu ) :
                    $parent_id  = (int) get_user_meta( $cu->ID, self::META_PARENT_ID, true );
                    $child_row_id = (int) get_user_meta( $cu->ID, self::META_CHILD_ROW, true );
                    $parent     = $parent_id ? get_user_by( 'id', $parent_id ) : null;

                    // Récupérer données enfant depuis wp_rk_children
                    $child_row = null;
                    if ( $child_row_id ) {
                        global $wpdb;
                        $child_row = $wpdb->get_row(
                            $wpdb->prepare(
                                'SELECT child_age FROM ' . rk_mc_children_table() . ' WHERE id = %d',
                                $child_row_id
                            )
                        );
                    }
                    $age = null;
                    if ( $child_row ) {
                        $age = rk_mc_get_child_age( $child_row );
                    }
                ?>
                <tr>
                    <td>
                        <strong><?php echo esc_html( $cu->display_name ); ?></strong>
                    </td>
                    <td>
                        <code style="font-size:.8em;"><?php echo esc_html( $cu->user_login ); ?></code>
                    </td>
                    <td>
                        <?php echo null !== $age
                            ? esc_html( $age ) . ' ' . esc_html__( 'سنة', 'rk-my-children' )
                            : '<span style="color:#aaa;">—</span>'; ?>
                    </td>
                    <td>
                        <?php if ( $parent ) : ?>
                            <a href="<?php echo esc_url( get_edit_user_link( $parent->ID ) ); ?>">
                                <?php echo esc_html( $parent->display_name ); ?>
                            </a>
                            <span style="color:#888;font-size:.8em;margin-right:6px;">
                                (<?php echo esc_html( $parent->user_email ); ?>)
                            </span>
                        <?php else : ?>
                            <span style="color:#e74c3c;">
                                ⚠ <?php esc_html_e( 'Parent introuvable', 'rk-my-children' ); ?>
                            </span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <a href="<?php echo esc_url( get_edit_user_link( $cu->ID ) ); ?>"
                           class="button button-small">
                            <?php esc_html_e( 'Modifier', 'rk-my-children' ); ?>
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>


            <!-- ── Tableau mapping WC Product ↔ Tutor LMS ── -->
            <h2 style="margin-top:36px;border-top:1px solid #ddd;padding-top:20px;">
                <?php esc_html_e( 'ربط المنتجات بمساقات Tutor LMS', 'rk-my-children' ); ?>
            </h2>

            <?php
            // Notice sync
            if ( ! empty( $_GET['rk_sync_done'] ) ) :
                $e_count = absint( $_GET['enrolled'] ?? 0 );
                $a_count = absint( $_GET['already']  ?? 0 );
                $m_count = absint( $_GET['missing']  ?? 0 );
            ?>
            <div class="notice notice-success is-dismissible">
                <p><?php printf(
                    esc_html__( 'Sync terminée : %d inscrits, %d déjà inscrits, %d liens manquants.', 'rk-my-children' ),
                    $e_count, $a_count, $m_count
                ); ?></p>
            </div>
            <?php endif; ?>

            <?php if ( class_exists( 'RK_MC_Course_Enrollment' ) ) :
                $mapped = RK_MC_Course_Enrollment::get_all_mapped_products();
            ?>

            <?php if ( ! empty( $mapped ) ) : ?>
            <table class="wp-list-table widefat fixed striped" style="max-width:860px;margin-bottom:16px;">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'منتج WooCommerce', 'rk-my-children' ); ?></th>
                        <th style="width:90px;"><?php esc_html_e( 'الحصص', 'rk-my-children' ); ?></th>
                        <th><?php esc_html_e( 'مساق Tutor LMS', 'rk-my-children' ); ?></th>
                        <th style="width:60px;"><?php esc_html_e( 'رابط', 'rk-my-children' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $mapped as $row ) : ?>
                <tr>
                    <td>
                        <a href="<?php echo esc_url( $row['product_url'] ); ?>" target="_blank">
                            <strong><?php echo esc_html( $row['product_title'] ); ?></strong>
                        </a>
                        <br><span style="color:#888;font-size:.8em;">ID <?php echo esc_html( $row['product_id'] ); ?></span>
                    </td>
                    <td>
                        <?php echo $row['credits']
                            ? '<strong>' . esc_html( $row['credits'] ) . '</strong>'
                            : '<span style="color:#aaa;">—</span>'; ?>
                    </td>
                    <td>
                        <a href="<?php echo esc_url( $row['course_url'] ); ?>" target="_blank">
                            <?php echo esc_html( $row['course_title'] ); ?>
                        </a>
                        <br><span style="color:#888;font-size:.8em;">ID <?php echo esc_html( $row['course_id'] ); ?></span>
                    </td>
                    <td>
                        <span style="color:#1a7a4a;font-size:1.2rem;">✓</span>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php else : ?>
            <p class="description" style="color:#e74c3c;">
                <?php esc_html_e( 'لم يتم ربط أي منتج بمساق Tutor LMS بعد. افتح أي منتج WooCommerce وحدد المساق المرتبط في قسم "بيانات المنتج".', 'rk-my-children' ); ?>
            </p>
            <?php endif; ?>

            <!-- Bouton sync rétroactif -->
            <form method="post"
                  action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
                  style="margin-top:12px;">
                <input type="hidden" name="action" value="rk_sync_enrollments">
                <?php wp_nonce_field( 'rk_sync_enrollments' ); ?>
                <input type="submit"
                       class="button button-secondary"
                       value="<?php esc_attr_e( '🔄  مزامنة التسجيلات من الحجوزات الحالية', 'rk-my-children' ); ?>">
                <p class="description" style="margin-top:6px;">
                    <?php esc_html_e(
                        'يفحص جميع الحجوزات المؤكدة ويُسجّل الأطفال في المساقات المرتبطة تلقائياً. العملية آمنة ولا تُكرر التسجيلات.',
                        'rk-my-children'
                    ); ?>
                </p>
            </form>

            <?php endif; ?>

        </div><!-- /.wrap -->
        <?php
    }

    public static function migration_notice() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $unmigrated = self::count_unmigrated();
        if ( $unmigrated <= 0 ) {
            return;
        }
        $url = admin_url( 'users.php?page=rk-child-migration' );
        ?>
        <div class="notice notice-warning">
            <p>
                <?php printf(
                    wp_kses(
                        __( '<strong>RiadaKids :</strong> %d enfant(s) n\'ont pas encore de compte WordPress. <a href="%s">Lancer la migration →</a>', 'rk-my-children' ),
                        array( 'strong' => array(), 'a' => array( 'href' => array() ) )
                    ),
                    $unmigrated,
                    esc_url( $url )
                ); ?>
            </p>
        </div>
        <?php
    }
}
