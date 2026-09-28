<?php
/**
 * RK_MC_Tutor_Child  (v5.4.0)
 *
 * Administration WordPress — colonne Parent dans wp-admin/users.php,
 * onglet "أطفال ريادة كيدز", exclusion rk_child de la vue "All Users",
 * et liste des enfants sur le profil du parent.
 *
 * @package RK_My_Children
 * @since   5.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class RK_MC_Tutor_Child {


    /* ─────────────────────────────────────────
     * Init
     * ───────────────────────────────────────── */

    public static function init() {
        /*
         * Tutor LMS dashboard : AUCUNE modification.
         * Le dashboard LMS reste intact dans son état par défaut.
         */

        // Colonne "ولي الأمر" dans wp-admin/users.php
        add_filter( 'manage_users_columns',       array( __CLASS__, 'add_parent_column_header' ) );
        add_filter( 'manage_users_custom_column', array( __CLASS__, 'render_parent_column' ), 10, 3 );

        // Onglet dédié dans wp-admin/users.php
        add_filter( 'views_users',   array( __CLASS__, 'add_children_tab' ) );

        // Exclure rk_child de la vue "All Users"
        add_action( 'pre_get_users', array( __CLASS__, 'exclude_children_from_all_users' ) );

        // Liste des enfants sur le profil du parent
        add_action( 'edit_user_profile', array( __CLASS__, 'render_parent_children_list' ) );
        add_action( 'show_user_profile', array( __CLASS__, 'render_parent_children_list' ) );
    }

    /* ─────────────────────────────────────────
     * Colonne "Parent" dans wp-admin/users.php
     * ───────────────────────────────────────── */

    public static function add_parent_column_header( array $columns ): array {
        // Insérer après la colonne "role"
        $new = array();
        foreach ( $columns as $key => $label ) {
            $new[ $key ] = $label;
            if ( 'role' === $key ) {
                $new['rk_parent'] = __( 'ولي الأمر', 'rk-my-children' );
            }
        }
        return $new;
    }

    public static function render_parent_column( string $value, string $column, int $user_id ): string {
        if ( 'rk_parent' !== $column ) {
            return $value;
        }

        $user = get_user_by( 'id', $user_id );
        if ( ! $user || ! in_array( 'rk_child', (array) $user->roles, true ) ) {
            return '<span style="color:#aaa;">—</span>';
        }

        $parent_id = (int) get_user_meta( $user_id, RK_MC_Child_User::META_PARENT_ID, true );
        if ( ! $parent_id ) {
            return '<span style="color:#e74c3c;" title="'
                . esc_attr__( 'Parent non trouvé', 'rk-my-children' )
                . '">⚠ —</span>';
        }

        $parent = get_user_by( 'id', $parent_id );
        if ( ! $parent ) {
            return '<span style="color:#e74c3c;">⚠ ID ' . esc_html( $parent_id ) . '</span>';
        }

        $edit_url = esc_url( get_edit_user_link( $parent_id ) );
        return sprintf(
            '<a href="%s"><strong>%s</strong></a><br><span style="color:#888;font-size:.8em;">%s</span>',
            $edit_url,
            esc_html( $parent->display_name ),
            esc_html( $parent->user_email )
        );
    }

    /**
     * Ajoute l'onglet "أطفال ريادة كيدز" dans la barre de navigation
     * de wp-admin/users.php, avec le nombre d'enfants entre parenthèses.
     *
     * @param  array $views  Onglets existants.
     * @return array
     */
    public static function add_children_tab( array $views ): array {
        $count = count( get_users( array(
            'role'   => 'rk_child',
            'fields' => 'ID',
        ) ) );

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $is_current = isset( $_GET['role'] ) && 'rk_child' === $_GET['role'];
        $url        = esc_url( admin_url( 'users.php?role=rk_child' ) );
        $label      = __( 'أطفال ريادة كيدز', 'rk-my-children' );

        $views['rk_child'] = sprintf(
            '<a href="%s"%s>%s <span class="count">(%d)</span></a>',
            $url,
            $is_current ? ' class="current" aria-current="page"' : '',
            esc_html( $label ),
            $count
        );

        return $views;
    }

    /**
     * Exclut les utilisateurs rk_child de la vue "Tous les utilisateurs"
     * (et de son compteur). Ils n'apparaissent que dans l'onglet dédié.
     *
     * @param \WP_User_Query $query
     */
    public static function exclude_children_from_all_users( \WP_User_Query $query ): void {
        global $pagenow;

        // Uniquement sur la page de liste des utilisateurs
        if ( ! is_admin() || 'users.php' !== $pagenow ) {
            return;
        }

        // Si un rôle spécifique est demandé via ?role=, ne pas interférer
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( ! empty( $_GET['role'] ) ) {
            return;
        }

        // Exclure rk_child de la vue "Tous"
        $role_not_in   = (array) $query->get( 'role__not_in' );
        $role_not_in[] = 'rk_child';
        $query->set( 'role__not_in', array_unique( $role_not_in ) );
    }

    /* ─────────────────────────────────────────
     * Profil parent : liste des enfants liés
     * ───────────────────────────────────────── */

    public static function render_parent_children_list( \WP_User $profileuser ) {
        if ( ! current_user_can( 'edit_users' ) && get_current_user_id() !== $profileuser->ID ) {
            return;
        }

        $children = RK_MC_Child_Repository::get_children( $profileuser->ID );
        if ( empty( $children ) ) {
            return;
        }
        ?>
        <h2><?php esc_html_e( 'أطفال هذا المستخدم', 'rk-my-children' ); ?></h2>
        <table class="form-table" id="rk-parent-children-list">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'الاسم', 'rk-my-children' ); ?></th>
                    <th><?php esc_html_e( 'العمر', 'rk-my-children' ); ?></th>
                    <th><?php esc_html_e( 'حساب WordPress', 'rk-my-children' ); ?></th>
                    <th><?php esc_html_e( 'تاريخ الإضافة', 'rk-my-children' ); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ( $children as $child ) :
                $age = rk_mc_get_child_age( $child );
                $child_user = ! empty( $child->wp_user_id )
                    ? get_user_by( 'id', (int) $child->wp_user_id )
                    : null;
            ?>
            <tr>
                <td><strong><?php echo esc_html( $child->child_name ); ?></strong></td>
                <td>
                    <?php echo null !== $age
                        ? esc_html( $age ) . ' ' . esc_html__( 'سنة', 'rk-my-children' )
                        : '—'; ?>
                </td>
                <td>
                    <?php if ( $child_user ) : ?>
                        <a href="<?php echo esc_url( get_edit_user_link( $child_user->ID ) ); ?>">
                            <?php echo esc_html( $child_user->user_login ); ?>
                        </a>
                        <span style="color:green;margin-right:6px;">✓</span>
                    <?php else : ?>
                        <span style="color:#e74c3c;"><?php esc_html_e( 'لا يوجد حساب بعد', 'rk-my-children' ); ?></span>
                    <?php endif; ?>
                </td>
                <td><?php echo esc_html( date_i18n( 'j F Y', strtotime( $child->created_at ) ) ); ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    /* ─────────────────────────────────────────
     * Helper
     * ───────────────────────────────────────── */

    public static function is_child_user(): bool {
        if ( ! function_exists( 'wp_get_current_user' ) ) {
            return false;
        }
        $user = wp_get_current_user();
        return $user && in_array( 'rk_child', (array) $user->roles, true );
    }
}
