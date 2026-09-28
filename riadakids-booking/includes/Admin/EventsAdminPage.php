<?php
/**
 * RiadaKids\Admin\EventsAdminPage — Gestion des événements SSA.
 *
 */

namespace RiadaKids\Admin;

use RiadaKids\Booking\SSAIntegration;

if ( ! defined( 'ABSPATH' ) ) exit;

class EventsAdminPage {

    public function register(): void {
        add_action( 'admin_menu',                        [ $this, 'add_submenu' ] );
        add_action( 'admin_post_rk_save_events',         [ $this, 'handle_save' ] );
        add_action( 'admin_enqueue_scripts',             [ $this, 'enqueue_admin_assets' ] );
    }

    // ── Sous-menu ─────────────────────────────────────────────────────────────
    public function add_submenu(): void {
        add_submenu_page(
            'rk-bookings',                    // ← parent slug (AdminPages.php)
            'Événements SSA — RiadaKids',
            'الأحداث SSA',
            'manage_options',
            'rk-ssa-events',
            [ $this, 'render_page' ]
        );
    }

    public function enqueue_admin_assets( string $hook ): void {
        if ( ! str_contains( $hook, 'rk-ssa-events' ) ) return;
        wp_enqueue_style( 'rk-admin', RK_PLUGIN_URL . 'assets/css/admin.css', [], RK_VERSION );
    }

    // ── Rendu ─────────────────────────────────────────────────────────────────
    public function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Accès refusé.' );

        $events    = get_option( 'rk_ssa_events', [] );
        $ssa_types = SSAIntegration::fetch_ssa_appointment_types();
        $saved     = (bool) ( $_GET['saved'] ?? false );
        ?>
        <div class="wrap" dir="ltr">
            <h1 style="display:flex;align-items:center;gap:8px;"><?php echo \RiadaKids\Core\Icons::get( 'calendar', 20 ); ?> Événements SSA — RiadaKids</h1>
            <p class="description">
                Configurez les événements affichés à l'étape 5 du wizard.
                <strong>Aucune modification de code nécessaire</strong> pour ajouter un 4e ou 5e événement.
            </p>

            <?php if ( $saved ) : ?>
            <div class="notice notice-success is-dismissible"><p><?php echo \RiadaKids\Core\Icons::get( 'check-circle', 15 ); ?> Événements sauvegardés.</p></div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'rk_save_events', 'rk_events_nonce' ); ?>
                <input type="hidden" name="action" value="rk_save_events">

                <table class="wp-list-table widefat fixed striped" id="rk-events-table">
                    <thead>
                        <tr>
                            <th width="50">Ordre</th>
                            <th>Titre affiché (arabe)</th>
                            <th>Mentor</th>
                            <th width="200">SSA Appointment Type ID</th>
                            <th width="70">Actif</th>
                            <th width="80">Supprimer</th>
                        </tr>
                    </thead>
                    <tbody id="rk-events-tbody">
                        <?php
                        if ( empty( $events ) ) {
                            echo '<tr id="rk-no-events"><td colspan="6"><em>Aucun événement — cliquez sur "Ajouter" ci-dessous.</em></td></tr>';
                        }
                        foreach ( $events as $i => $ev ) {
                            $this->row( $i, $ev, $ssa_types );
                        }
                        ?>
                    </tbody>
                </table>

                <p>
                    <button type="button" id="rk-add-event-row" class="button button-secondary">
                        + Ajouter un événement
                    </button>
                </p>

                <?php submit_button( 'Enregistrer les événements' ); ?>
            </form>

            <?php if ( ! empty( $ssa_types ) ) : ?>
            <div class="card" style="max-width:600px;margin-top:20px;padding:16px;">
                <h3>Types SSA disponibles (référence)</h3>
                <table class="widefat" style="max-width:480px;">
                    <thead><tr><th>ID</th><th>Titre SSA</th></tr></thead>
                    <tbody>
                        <?php foreach ( $ssa_types as $t ) : ?>
                        <tr>
                            <td><code><?php echo (int) $t['id']; ?></code></td>
                            <td><?php echo esc_html( $t['title'] ); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

        <!-- Template ligne (masqué, cloné par JS) -->
        <table style="display:none"><tbody id="rk-row-template">
            <?php $this->row( '__IDX__', [ 'ssa_id' => '', 'title' => '', 'mentor' => '', 'active' => true, 'sort_order' => 0 ], $ssa_types, true ); ?>
        </tbody></table>

        <script>
        (function($){
            var idx = <?php echo max( 0, count( $events ) ); ?>;
            var $tpl = $('#rk-row-template tr').first();

            $('#rk-add-event-row').on('click', function(){
                $('#rk-no-events').remove();
                var html = $tpl.clone().html().replace(/__IDX__/g, idx++);
                $('#rk-events-tbody').append($('<tr>').html(html));
            });

            $(document).on('click', '.rk-del-row', function(){
                $(this).closest('tr').remove();
                if(!$('#rk-events-tbody tr').length){
                    $('#rk-events-tbody').html('<tr id="rk-no-events"><td colspan="6"><em>Aucun événement.</em></td></tr>');
                }
            });
        })(jQuery);
        </script>
        <?php
    }

    private function row( $idx, array $ev, array $ssa_types, bool $tpl = false ): void {
        $n          = "rk_events[{$idx}]";
        $ssa_id     = isset( $ev['ssa_id'] )     ? (int) $ev['ssa_id']  : 0;
        $title      = $ev['title']      ?? '';
        $mentor     = $ev['mentor']     ?? '';
        $active     = (bool) ( $ev['active']     ?? true );
        $sort_order = (int)  ( $ev['sort_order'] ?? 0 );
        ?>
        <tr>
            <td>
                <input type="number" name="<?php echo esc_attr($n); ?>[sort_order]"
                       value="<?php echo esc_attr($sort_order); ?>" min="0" style="width:50px;">
            </td>
            <td>
                <input type="text" name="<?php echo esc_attr($n); ?>[title]"
                       value="<?php echo esc_attr($title); ?>"
                       placeholder="لقاء مع نور الهدى عثمان" style="width:100%;" required>
            </td>
            <td>
                <input type="text" name="<?php echo esc_attr($n); ?>[mentor]"
                       value="<?php echo esc_attr($mentor); ?>"
                       placeholder="اسم المرشد" style="width:100%;">
            </td>
            <td>
                <?php if ( ! empty( $ssa_types ) ) : ?>
                <select name="<?php echo esc_attr($n); ?>[ssa_id]" style="width:100%;">
                    <option value="">— Choisir —</option>
                    <?php foreach ( $ssa_types as $t ) : ?>
                    <option value="<?php echo (int)$t['id']; ?>"
                            <?php if ( ! $tpl ) selected( $ssa_id, (int)$t['id'] ); ?>>
                        #<?php echo (int)$t['id']; ?> — <?php echo esc_html($t['title']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <?php else : ?>
                <input type="number" name="<?php echo esc_attr($n); ?>[ssa_id]"
                       value="<?php echo esc_attr($ssa_id); ?>"
                       min="1" placeholder="ID SSA" style="width:80px;">
                <?php endif; ?>
            </td>
            <td style="text-align:center;">
                <input type="checkbox" name="<?php echo esc_attr($n); ?>[active]"
                       value="1" <?php if ( ! $tpl ) checked($active); ?>>
            </td>
            <td style="text-align:center;">
                <button type="button" class="button button-link-delete rk-del-row"><?php echo \RiadaKids\Core\Icons::get( 'x', 14 ); ?></button>
            </td>
        </tr>
        <?php
    }

    // ── Sauvegarde ────────────────────────────────────────────────────────────
    public function handle_save(): void {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Accès refusé.' );
        check_admin_referer( 'rk_save_events', 'rk_events_nonce' );

        $events = [];
        foreach ( (array) ( $_POST['rk_events'] ?? [] ) as $item ) {
            $id = (int) ( $item['ssa_id'] ?? 0 );
            if ( $id <= 0 ) continue;
            $events[] = [
                'ssa_id'     => $id,
                'title'      => sanitize_text_field( $item['title']      ?? '' ),
                'mentor'     => sanitize_text_field( $item['mentor']     ?? '' ),
                'active'     => ! empty( $item['active'] ),
                'sort_order' => (int) ( $item['sort_order'] ?? 0 ),
            ];
        }

        usort( $events, fn( $a, $b ) => $a['sort_order'] <=> $b['sort_order'] );
        update_option( 'rk_ssa_events', $events );

        wp_redirect( add_query_arg( 'saved', '1', admin_url( 'admin.php?page=rk-ssa-events' ) ) );
        exit;
    }
}