<?php
declare( strict_types=1 );
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Extrait de class-rk-mc-booking-bridge.php — factorisation par fonctionnalité.
 * Code déplacé verbatim, aucune modification de logique.
 */
trait RK_MC_Booking_Bridge_Admin {
    /* ═══════════════════════════════════════════════════════════════════
       TUTOR LMS — Completion d'une leçon → badge course_complete
       ═══════════════════════════════════════════════════════════════════ */

    public static function on_lesson_completed( $lesson_id ): void {
        if ( ! class_exists( 'RK_MC_Badge_Service' ) ) return;

        $student_uid = get_current_user_id();
        if ( ! $student_uid ) return;

        global $wpdb;
        $child_id = (int) $wpdb->get_var( $wpdb->prepare(
            'SELECT id FROM ' . rk_mc_children_table() . ' WHERE wp_user_id = %d LIMIT 1',
            $student_uid
        ) );
      if ( ! $child_id ) return;
        RK_MC_Badge_Service::award( $child_id, 'first_lesson' );

        // Si le cours est entièrement complété après cette leçon → badge مغامرة مكتملة
        $course_id = (int) get_post_field( 'post_parent', (int) $lesson_id );
        if ( $course_id > 0 && function_exists( 'tutor_utils' ) ) {
            $pct = (int) tutor_utils()->get_course_completed_percent( $course_id, $student_uid );
            if ( $pct >= 100 ) {
                RK_MC_Badge_Service::maybe_award( $child_id, 'course_complete' );
            }
        }

        // Invalider le cache dashboard de l'enfant
        if ( class_exists( 'RK_MC_Child_Dashboard_Data' ) ) {
            RK_MC_Child_Dashboard_Data::clear_cache( $child_id );
        }
    }

    /* ═══════════════════════════════════════════════════════════════════
       ADMIN — PAGE CONFIGURATION SSA → COACH
       ═══════════════════════════════════════════════════════════════════ */

    public static function register_admin_page(): void {
        add_submenu_page(
            'rk-children',
            __( 'Coach → SSA', 'rk-my-children' ),
            __( 'Coach → SSA', 'rk-my-children' ),
            'manage_options',
            'rk-coach-ssa-map',
            [ __CLASS__, 'render_admin_page' ]
        );
    }

    public static function render_admin_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Accès refusé.' );

        $map       = get_option( self::COACH_MAP_OPTION, [] );
        $saved     = ! empty( $_GET['saved'] );
        $ssa_types = self::fetch_ssa_types();
        $coaches   = self::fetch_coaches();
        ?>
        <div class="wrap" dir="ltr">
            <h1>🎓 Association Coach ↔ Type de rendez-vous SSA</h1>
            <p class="description">
                Associez chaque type de rendez-vous Simply Schedule Appointments
                au compte WordPress de son coach (rôle <code>tutor_instructor</code>).
            </p>

            <?php if ( $saved ) : ?>
            <div class="notice notice-success is-dismissible">
                <p>✅ Configuration sauvegardée avec succès.</p>
            </div>
            <?php endif; ?>

            <?php if ( empty( $ssa_types ) ) : ?>
            <div class="notice notice-warning">
                <p>⚠️ Aucun type de rendez-vous SSA trouvé.
                   Vérifiez que Simply Schedule Appointments est activé et configuré.</p>
            </div>
            <?php else : ?>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'rk_save_coach_map', 'rk_coach_map_nonce' ); ?>
                <input type="hidden" name="action" value="rk_save_coach_map">

                <table class="wp-list-table widefat fixed striped" style="max-width:800px;">
                    <thead>
                        <tr>
                            <th style="width:60px;">ID SSA</th>
                            <th>Type de rendez-vous</th>
                            <th>Coach assigné</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $ssa_types as $t ) :
                            $ssa_id      = (int) $t['id'];
                            $current_uid = (int) ( $map[ $ssa_id ] ?? 0 );
                        ?>
                        <tr>
                            <td><code><?php echo $ssa_id; ?></code></td>
                            <td><?php echo esc_html( $t['title'] ); ?></td>
                            <td>
                                <select name="rk_coach_map[<?php echo $ssa_id; ?>]" style="min-width:250px;">
                                    <option value="">— غير مرتبط / Non assigné —</option>
                                    <?php foreach ( $coaches as $coach ) : ?>
                                    <option value="<?php echo (int) $coach->ID; ?>"
                                            <?php selected( $current_uid, (int) $coach->ID ); ?>>
                                        <?php echo esc_html( $coach->display_name . ' (#' . $coach->ID . ')' ); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <?php if ( empty( $coaches ) ) : ?>
                <div class="notice notice-warning inline" style="margin:16px 0;">
                    <p>⚠️ Aucun utilisateur avec le rôle <code>tutor_instructor</code> trouvé.
                       Créez d'abord les comptes coachs dans Tutor LMS.</p>
                </div>
                <?php endif; ?>

                <?php submit_button( 'Enregistrer la configuration', 'primary', 'submit', true ); ?>
            </form>

            <?php endif; ?>

            <hr>
            <h3>Configuration actuelle</h3>
            <?php if ( empty( $map ) ) : ?>
                <p><em>Aucune association configurée.</em></p>
            <?php else : ?>
                <ul>
                <?php foreach ( $map as $ssa_id => $coach_uid ) :
                    $coach = get_user_by( 'id', $coach_uid );
                    $coach_name = $coach ? $coach->display_name : "User #{$coach_uid} introuvable";
                ?>
                    <li>SSA type <code>#<?php echo (int) $ssa_id; ?></code>
                        → Coach <strong><?php echo esc_html( $coach_name ); ?></strong>
                        (WP user #<?php echo (int) $coach_uid; ?>)</li>
                <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
        <?php
    }

    public static function handle_save(): void {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Accès refusé.' );
        check_admin_referer( 'rk_save_coach_map', 'rk_coach_map_nonce' );

        $raw = (array) ( $_POST['rk_coach_map'] ?? [] );
        $map = [];

        foreach ( $raw as $ssa_id => $coach_uid ) {
            $ssa_id   = (int) $ssa_id;
            $coach_uid = (int) $coach_uid;
            if ( $ssa_id > 0 && $coach_uid > 0 ) {
                $map[ $ssa_id ] = $coach_uid;
            }
        }

        update_option( self::COACH_MAP_OPTION, $map );

        wp_redirect( add_query_arg( 'saved', '1', admin_url( 'admin.php?page=rk-coach-ssa-map' ) ) );
        exit;
    }

    /* ═══════════════════════════════════════════════════════════════════
       PROFIL UTILISATEUR — Champ SSA events
       ═══════════════════════════════════════════════════════════════════ */

    public static function render_profile_ssa_field( WP_User $user ): void {
        if ( ! in_array( 'tutor_instructor', (array) $user->roles, true ) ) return;
        if ( ! current_user_can( 'manage_options' ) ) return;

        $ssa_types = self::fetch_ssa_types();
        if ( empty( $ssa_types ) ) return;

        $map     = get_option( self::COACH_MAP_OPTION, [] );
        $my_ids  = [];
        foreach ( $map as $ssa_id => $uid ) {
            if ( (int) $uid === (int) $user->ID ) {
                $my_ids[] = (int) $ssa_id;
            }
        }
        ?>
        <h3>🗓️ <?php esc_html_e( 'Events SSA associés', 'rk-my-children' ); ?></h3>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e( 'Types de rendez-vous SSA', 'rk-my-children' ); ?></th>
                <td>
                    <?php wp_nonce_field( 'rk_profile_ssa_' . $user->ID, 'rk_profile_ssa_nonce' ); ?>
                    <fieldset>
                        <legend class="screen-reader-text">Events SSA</legend>
                        <?php foreach ( $ssa_types as $t ) :
                            $tid = (int) $t['id'];
                        ?>
                        <label style="display:block;margin-bottom:6px;">
                            <input type="checkbox"
                                   name="rk_ssa_events[]"
                                   value="<?php echo $tid; ?>"
                                   <?php checked( in_array( $tid, $my_ids, true ) ); ?>>
                            <strong>#<?php echo $tid; ?></strong> — <?php echo esc_html( $t['title'] ); ?>
                        </label>
                        <?php endforeach; ?>
                    </fieldset>
                    <p class="description">
                        <?php esc_html_e( 'Cochez les events SSA dont ce coach est responsable. Les séances liées à ces events lui seront attribuées automatiquement.', 'rk-my-children' ); ?>
                    </p>
                </td>
            </tr>
        </table>
        <?php
    }

    public static function save_profile_ssa_field( int $user_id ): void {
        if ( ! current_user_can( 'manage_options' ) ) return;
        if ( ! isset( $_POST['rk_profile_ssa_nonce'] )
            || ! wp_verify_nonce( $_POST['rk_profile_ssa_nonce'], 'rk_profile_ssa_' . $user_id )
        ) return;

        $user = get_userdata( $user_id );
        if ( ! $user || ! in_array( 'tutor_instructor', (array) $user->roles, true ) ) return;

        // Charger la map actuelle et retirer toutes les entrées de ce coach
        $map = get_option( self::COACH_MAP_OPTION, [] );
        foreach ( $map as $ssa_id => $uid ) {
            if ( (int) $uid === $user_id ) {
                unset( $map[ $ssa_id ] );
            }
        }

        // Ajouter les cases cochées
        $selected = array_map( 'intval', (array) ( $_POST['rk_ssa_events'] ?? [] ) );
        foreach ( $selected as $ssa_id ) {
            if ( $ssa_id > 0 ) {
                $map[ $ssa_id ] = $user_id;
            }
        }

        update_option( self::COACH_MAP_OPTION, $map );
    }

    private static function fetch_ssa_types(): array {
        global $wpdb;
        $table = $wpdb->prefix . 'ssa_appointment_types';

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return [];
        }

        return (array) $wpdb->get_results(
            "SELECT id, title FROM {$table} WHERE status = 'publish' ORDER BY id ASC",
            ARRAY_A
        );
    }

    private static function fetch_coaches(): array {
        return get_users( [
            'role'    => 'tutor_instructor',
            'orderby' => 'display_name',
            'order'   => 'ASC',
            'fields'  => [ 'ID', 'display_name' ],
        ] ) ?: [];
    }
}
