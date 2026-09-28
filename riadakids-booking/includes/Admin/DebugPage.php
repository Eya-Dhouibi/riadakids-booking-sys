<?php
/**
 * RiadaKids\Admin\DebugPage
 *
 * Page d'administration dédiée au debug enterprise.
 *
 */

namespace RiadaKids\Admin;

use RiadaKids\Core\Security;
use RiadaKids\Database\DB;

if ( ! defined( 'ABSPATH' ) ) exit;

class DebugPage {

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'register_submenu' ] );
        add_action( 'admin_post_rk_manual_repair', [ $this, 'handle_manual_repair' ] );
    }

    /**
     * تحديد مسار ملف السجلات بشكل آمن لحماية النظام من غياب الثوابت
     */
    private function get_secure_log_file_path(): string {
        if ( defined('RK_LOG_FILE') && ! empty(RK_LOG_FILE) ) {
            return RK_LOG_FILE;
        }
        // مسار احتياطي آمن داخل مجلد الإضافة في حال عدم تعريف الثابت
        return WP_CONTENT_DIR . '/uploads/riadakids-booking-debug.log';
    }

    /**
     * جلب الحجوزات الفارغة عبر استعلام مباشر لحماية الموقع من الانهيار
     */
    private function get_local_null_bookings(): array {
        global $wpdb;
        $table_name = $wpdb->prefix . 'rk_bookings';

        // التحقق من وجود الجدول أولاً
        if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table_name ) ) !== $table_name ) {
            return [];
        }

        // جلب الحجوزات التي تحتوي على قيم 0 أو فارغة في الحقول الأساسية
        return $wpdb->get_results( "
            SELECT * FROM {$table_name} 
            WHERE program_id = 0 
               OR course_id = 0 
               OR child_id = 0 
            ORDER BY created_at DESC 
            LIMIT 100
        " );
    }

    public function register_submenu(): void {
        Security::require_admin();

        // حساب العدد باستخدام الاستعلام المباشر الآمن
        $null_bookings = $this->get_local_null_bookings();
        $null_count = count( $null_bookings );

        $badge = $null_count > 0 ? " <span class='awaiting-mod'>{$null_count}</span>" : '';

        add_submenu_page(
            'rk-bookings',
            'RK Debug',
            'Debug' . $badge,
            'manage_options',
            'rk-debug',
            [ $this, 'render' ]
        );
    }

    public function render(): void {
        Security::require_admin();

        $null_bookings = $this->get_local_null_bookings();
        $log_file      = $this->get_secure_log_file_path();
        
        $log_content   = ( file_exists( $log_file ) && is_readable( $log_file ) )
            ? implode( '', array_slice( file( $log_file ), -100 ) )
            : 'Aucun log pour le moment ou fichier inaccessible.';
        ?>
        <div class="wrap" dir="rtl">
            <h1 style="display:flex;align-items:center;gap:8px;"><?php echo \RiadaKids\Core\Icons::get( 'settings', 20 ); ?> Riadakids — Debug</h1>

            <?php if ( isset( $_GET['repaired'] ) ) : ?>
                <div class="notice notice-success is-dismissible">
                    <p>تم الانتهاء من الإصلاح اليدوي! الحجوزات التي تم إصلاحها: <strong><?php echo (int) $_GET['repaired']; ?></strong></p>
                </div>
            <?php endif; ?>

            <h2><?php echo \RiadaKids\Core\Icons::get( 'alert', 17 ); ?> Bookings avec champs null (<?php echo count( $null_bookings ); ?>)</h2>
            <?php if ( empty( $null_bookings ) ) : ?>
            <div style="color:#2b8a3e;font-weight:600;margin-bottom:20px;"><?php echo \RiadaKids\Core\Icons::get( 'check-circle', 16 ); ?> Aucun booking null — tout est propre !</div>
            <?php else : ?>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'rk_manual_repair', 'rk_repair_nonce' ); ?>
                <input type="hidden" name="action" value="rk_manual_repair">
                <input type="submit" class="button button-primary" value="Lancer le repair manuel">
            </form>
            <table class="wp-list-table widefat fixed striped" style="margin-top:12px;" dir="rtl">
                <thead>
                    <tr><th>RK ID</th><th>SSA ID</th><th>Utilisateur</th><th>program_id</th><th>course_id</th><th>child_id</th><th>Source</th><th>Date</th></tr>
                </thead>
                <tbody>
                <?php foreach ( $null_bookings as $b ) : ?>
                <tr>
                    <td><?php echo (int) $b->id; ?></td>
                    <td>
                        <?php if ( ! empty( $b->booking_id ) ) : ?>
                        #<?php echo (int) $b->booking_id; ?>
                        <?php else : ?>—<?php endif; ?>
                    </td>
                    <td><?php echo esc_html( $b->customer_name ?? '?' ); ?><br><small><?php echo esc_html( $b->customer_email ?? '' ); ?></small></td>
                    <td><?php echo $b->program_id ?: '<span style="color:red">0</span>'; ?></td>
                    <td><?php echo $b->course_id  ?: '<span style="color:red">0</span>'; ?></td>
                    <td><?php echo $b->child_id   ?: '<span style="color:red">0</span>'; ?></td>
                    <td><?php echo esc_html( $b->meta_source ?: '?' ); ?></td>
                    <td><?php echo ! empty( $b->created_at ) ? date_i18n( 'j M Y H:i', strtotime( $b->created_at ) ) : '—'; ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>

            <h2 style="margin-top:30px;"><?php echo \RiadaKids\Core\Icons::get( 'list', 17 ); ?> Derniers logs RK (100 lignes)</h2>
            <textarea readonly style="width:100%;height:300px;font-family:monospace;font-size:11px;background:#1a1a2e;color:#a9dc76;border:none;padding:12px;border-radius:6px;"><?php echo esc_textarea( $log_content ); ?></textarea>
            <p>
                <a href="<?php echo esc_url( add_query_arg( 'rk_clear_log', 1 ) ); ?>" class="button button-secondary"
                   onclick="return confirm('Vider le fichier log ?');"><?php echo \RiadaKids\Core\Icons::get( 'trash', 14 ); ?> Vider le log</a>
            </p>
            <?php $this->maybe_clear_log(); ?>

            <h2><?php echo \RiadaKids\Core\Icons::get( 'database', 17 ); ?> État des tables</h2>
            <?php $this->render_db_stats(); ?>

            <h2 style="margin-top:30px;"><?php echo \RiadaKids\Core\Icons::get( 'search', 17 ); ?> Diagnostic SSA — URL de modification</h2>
            <?php $this->render_ssa_diagnostic(); ?>
        </div>
        <?php
    }

    /**
     * Inspecte l'API SSA pour trouver d'où vient `public_edit_url`.
     *
     * SSA calcule cette URL au lieu de la stocker : sans connaître le point
     * d'entrée exact de SON API, impossible de reconstruire le lien
     * « تعديل أو إلغاء » envoyé dans ses emails.
     */
    private function render_ssa_diagnostic(): void {
        global $wpdb;

        // Dernier rendez-vous connu, pour tester sur un cas réel.
        $appt_id = (int) $wpdb->get_var(
            "SELECT id FROM {$wpdb->prefix}ssa_appointments ORDER BY id DESC LIMIT 1"
        );

        if ( ! $appt_id ) {
            echo '<p>Aucun rendez-vous SSA en base.</p>';
            return;
        }

        echo '<p><strong>Rendez-vous testé :</strong> #' . (int) $appt_id . '</p>';

        // ── Fonction ssa() disponible ? ──
        if ( ! function_exists( 'ssa' ) ) {
            echo '<p style="color:#b91c1c;">La fonction <code>ssa()</code> est introuvable — '
               . 'le plugin SSA est-il actif ?</p>';
        } else {
            $ssa   = ssa();
            $props = [];
            foreach ( get_object_vars( $ssa ) as $name => $val ) {
                if ( is_object( $val ) ) {
                    $props[ $name ] = get_class( $val );
                }
            }

            echo '<p><strong>Composants exposés par ssa() :</strong></p>';
            echo '<pre style="background:#f6f7f7;padding:10px;overflow:auto;max-height:220px;">'
               . esc_html( print_r( $props, true ) ) . '</pre>';

            // Méthodes des composants dont le nom évoque un rendez-vous.
            foreach ( $props as $name => $class ) {
                if ( stripos( $name, 'appoint' ) === false ) continue;

                $methods = get_class_methods( $ssa->{$name} );
                echo '<p><strong>Méthodes de ssa()-&gt;' . esc_html( $name ) . ' :</strong></p>';
                echo '<pre style="background:#f6f7f7;padding:10px;overflow:auto;max-height:220px;">'
                   . esc_html( implode( ', ', (array) $methods ) ) . '</pre>';
            }
        }

        // ── Ce que notre résolveur récupère réellement ──
        $appt = \RiadaKids\Database\DB::get_ssa_appointment_array( $appt_id );

        echo '<p><strong>Clés récupérées par RK :</strong></p>';
        echo '<pre style="background:#f6f7f7;padding:10px;overflow:auto;max-height:200px;">'
           . esc_html( $appt ? implode( ', ', array_keys( $appt ) ) : '(vide)' ) . '</pre>';

        $url = \RiadaKids\Database\DB::get_ssa_edit_url( $appt_id, 'edit' );

        echo '<p><strong>URL générée :</strong> ';
        if ( $url ) {
            echo '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">'
               . esc_html( $url ) . '</a>';
        } else {
            echo '<span style="color:#b91c1c;">aucune</span>';
        }
        echo '</p>';
    }

    public function handle_manual_repair(): void {
        Security::require_admin();
        check_admin_referer( 'rk_manual_repair', 'rk_repair_nonce' );

        $appointments = DB::get_recent_ssa_appointments( 50 );
        $repaired = 0;

        if ( ! empty( $appointments ) ) {
            foreach ( $appointments as $appt ) {
                if ( class_exists( '\RiadaKids\Booking\BookingService' ) && method_exists( '\RiadaKids\Booking\BookingService', 'repair_null_fields' ) ) {
                    $repaired += \RiadaKids\Booking\BookingService::repair_null_fields( (array) $appt );
                }
            }
        }

        wp_redirect( admin_url( 'admin.php?page=rk-debug&repaired=' . $repaired ) );
        exit;
    }

    private function maybe_clear_log(): void {
        if ( empty( $_GET['rk_clear_log'] ) ) return;
        Security::require_admin();
        
        $log_file = $this->get_secure_log_file_path();
        if ( file_exists( $log_file ) && is_writable( $log_file ) ) {
            file_put_contents( $log_file, '' );
            echo '<div class="notice notice-success"><p>Log vidé.</p></div>';
        }
    }

    private function render_db_stats(): void {
        global $wpdb;
        $tables = [
            'rk_bookings'    => 'Bookings',
            'rk_children'    => 'Enfants',
            'rk_credit_logs' => 'Logs crédits',
            'rk_points_logs' => 'Logs points',
        ];
        echo '<table class="widefat" style="max-width:400px;">';
        echo '<thead><tr><th>Table</th><th>Lignes</th></tr></thead><tbody>';
        foreach ( $tables as $suffix => $label ) {
            $table_name = $wpdb->prefix . $suffix;
            if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table_name ) ) === $table_name ) {
                $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name}" );
            } else {
                $count = 0;
            }
            echo "<tr><td>{$label}</td><td style='font-weight:700;'>{$count}</td></tr>";
        }
        echo '</tbody></table>';
    }
}