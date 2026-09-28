<?php
declare( strict_types=1 );
/**
 * RK_PDF_Service — Sprint 5: Rapport mensuel automatique.
 *
 * Comportement :
 *   • Cron déclenché le 2 de chaque mois (rk_monthly_pdf_cron).
 *   • Génère un rapport HTML/PDF pour chaque enfant actif.
 *   • Si Mpdf\Mpdf est disponible (via Composer) → vrai PDF, stocké comme WP attachment.
 *   • Sinon → lien vers la page rapport imprimable (mode print=1).
 *   • Notification BM au parent avec le lien.
 *
 * Pour activer mPDF : composer require mpdf/mpdf dans le dossier du plugin.
 *
 * @package RK_My_Children
 * @since   9.0.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class RK_PDF_Service {

    const CRON_HOOK = 'rk_monthly_pdf_cron';

    /* ─── Init ──────────────────────────────────────────────────── */

    public static function init(): void {
        add_action( self::CRON_HOOK, [ __CLASS__, 'run_monthly_reports' ] );
        add_action( 'init',          [ __CLASS__, 'maybe_schedule' ] );
    }

    /* ─── Planification cron ─────────────────────────────────────── */

    public static function maybe_schedule(): void {
        if ( wp_next_scheduled( self::CRON_HOOK ) ) return;

        // Prochaine occurrence : 2ème du mois suivant à 06:00
        $now   = time();
        $year  = (int) date( 'Y', $now );
        $month = (int) date( 'n', $now );
        if ( (int) date( 'j', $now ) >= 2 ) {
            $month++;
            if ( $month > 12 ) { $month = 1; $year++; }
        }
        $next = mktime( 6, 0, 0, $month, 2, $year );
        wp_schedule_single_event( $next, self::CRON_HOOK );
    }

    /* ─── Génération des rapports (appelé par le cron) ─────────── */

    public static function run_monthly_reports(): void {
        global $wpdb;
        $children_table = $wpdb->prefix . 'rk_children';
        $children = $wpdb->get_results(
            "SELECT id, user_id, wp_user_id, child_name, display_name FROM {$children_table} LIMIT 500"
        ) ?: [];

        $prev_month = (int) date( 'n' ) - 1;
        $prev_year  = (int) date( 'Y' );
        if ( $prev_month < 1 ) { $prev_month = 12; $prev_year--; }

        foreach ( $children as $child ) {
            self::generate_for_child( (int) $child->id, (int) $child->user_id, $child, $prev_year, $prev_month );
        }

        // Re-planifier pour le mois prochain
        $next_month = (int) date( 'n' ) + 1;
        $next_year  = (int) date( 'Y' );
        if ( $next_month > 12 ) { $next_month = 1; $next_year++; }
        $next_ts = mktime( 6, 0, 0, $next_month, 2, $next_year );
        wp_schedule_single_event( $next_ts, self::CRON_HOOK );
    }

    /* ─── Rapport pour un enfant ─────────────────────────────────── */

    public static function generate_for_child(
        int $child_id,
        int $parent_id,
        object $child,
        int $year,
        int $month
    ): void {
        if ( ! $parent_id ) return;

        $month_names_ar = [ 1=>'يناير',2=>'فبراير',3=>'مارس',4=>'أبريل',5=>'مايو',6=>'يونيو',
                            7=>'يوليو',8=>'أغسطس',9=>'سبتمبر',10=>'أكتوبر',11=>'نوفمبر',12=>'ديسمبر' ];
        $period = ( $month_names_ar[ $month ] ?? '' ) . ' ' . $year;

        // URL vers la page rapport imprimable
        $report_url = add_query_arg( [
            'child_id' => $child_id,
            'year'     => $year,
            'month'    => $month,
            'print'    => '1',
        ], home_url( RK_TUTOR_DASHBOARD_URL . 'rk-rapport/' ) );

        // Tenter la génération PDF si mPDF disponible
        $attachment_id = 0;
        if ( class_exists( 'Mpdf\Mpdf' ) ) {
            $attachment_id = self::generate_pdf_attachment( $child_id, $child, $year, $month, $period );
        }

        // Stocker la référence du dernier rapport (url + période) pour affichage dashboard
        $final_url = $attachment_id ? (string) wp_get_attachment_url( $attachment_id ) : $report_url;
        update_user_meta( $parent_id, 'rk_pdf_last_' . $child_id, [
            'url'    => $final_url,
            'period' => $period,
            'date'   => current_time( 'mysql' ),
        ] );

        // Notification BM au parent
        if ( class_exists( 'Better_Messages' ) && class_exists( 'RK_MC_Message_Service' ) ) {
            $admin    = RK_MC_Message_Service::get_admin_user();
            $admin_id = (int) $admin->ID;

            $content = $attachment_id
                ? sprintf( '📊 تقرير شهر %s لـ %s جاهز للتحميل: %s', $period,
                    $child->child_name ?? $child->display_name ?? 'طفلك', $final_url )
                : sprintf( '📊 تقرير شهر %s لـ %s متاح الآن: %s', $period,
                    $child->child_name ?? $child->display_name ?? 'طفلك', $report_url );

            if ( $admin_id && $admin_id !== $parent_id ) {
                Better_Messages()->functions->new_message( [
                    'sender_id'    => $admin_id,
                    'recipients'   => [ $parent_id ],
                    'content'      => $content,
                    'send_push'    => true,
                    'count_unread' => true,
                    'show_on_site' => true,
                ] );
            }
        }
    }

    /* ─── Génération PDF via mPDF ────────────────────────────────── */

    private static function generate_pdf_attachment(
        int $child_id,
        object $child,
        int $year,
        int $month,
        string $period
    ): int {
        global $wpdb;

        $month_start = sprintf( '%04d-%02d-01 00:00:00', $year, $month );
        $month_end   = sprintf( '%04d-%02d-%02d 23:59:59', $year, $month,
            (int) date( 't', mktime( 0, 0, 0, $month, 1, $year ) ) );

        $sessions = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}rk_bookings
              WHERE child_id = %d AND appointment BETWEEN %s AND %s ORDER BY appointment ASC",
            $child_id, $month_start, $month_end
        ) ) ?: [];

        $last_eval = null;
        if ( class_exists( 'RK_MC_Assessment_Service' ) ) {
            foreach ( RK_MC_Assessment_Service::get_all( $child_id ) as $ev ) {
                if ( ! empty( $ev['assessed_at'] ) && $ev['assessed_at'] >= substr( $month_start, 0, 10 )
                     && $ev['assessed_at'] <= substr( $month_end, 0, 10 ) ) {
                    $last_eval = $ev;
                    break;
                }
            }
        }

        $child_name = $child->child_name ?? $child->display_name ?? 'الطفل';

        // HTML du rapport (RTL simplifié pour mPDF)
        $html  = '<!DOCTYPE html><html dir="rtl" lang="ar"><head>';
        $html .= '<meta charset="UTF-8">';
        $html .= '<style>body{font-family:"notonaskharabic","DejaVu Sans",sans-serif;direction:rtl;font-size:13pt;line-height:1.9;}';
        $html .= 'h1{color:#1B4F8C;font-size:17pt;}h2{color:#E8500A;font-size:14pt;border-bottom:1px solid #e2e8f0;padding-bottom:6px;}';
        $html .= 'table{width:100%;border-collapse:collapse;}th,td{border:1px solid #e2e8f0;padding:8px 10px;text-align:right;font-size:12pt;}';
        $html .= 'th{background:#f8fafc;font-weight:bold;}.kpi-box{display:inline-block;width:22%;margin:1%;padding:10px;text-align:center;background:#f8fafc;border-radius:6px;}';
        $html .= '</style></head><body>';
        $html .= '<h1>RiadaKids — تقرير متابعة شهري</h1>';
        $html .= '<p>' . esc_html( $child_name ) . ' · ' . esc_html( $period ) . '</p>';
        $html .= '<p>' . date_i18n( 'j/m/Y' ) . '</p>';
        $html .= '<hr/>';

        // Séances
        $html .= '<h2>سجل لقاءات</h2>';
        if ( $sessions ) {
            $html .= '<table><thead><tr><th>التاريخ</th><th>الوقت</th><th>الجلسة</th><th>الحضور</th></tr></thead><tbody>';
            $att_labels = [ 'present' => 'حضر', 'absent' => 'غاب', 'late' => 'تأخر' ];
            foreach ( $sessions as $s ) {
                // date() ignore le fuseau WP : passer par le helper dédié.
                $_aid = (int) ( $s->booking_id ?? 0 );
                $_d   = rk_mc_appt_format( $s->appointment ?? '', 'j/m/Y', $_aid );
                $_h   = rk_mc_appt_format( $s->appointment ?? '', 'H:i',   $_aid );
                $att  = $att_labels[ $s->attendance ?? '' ] ?? 'غير مسجل';
                $html .= '<tr>';
                $html .= '<td>' . ( $_d ?: '—' ) . '</td>';
                $html .= '<td>' . ( $_h ?: '—' ) . '</td>';
                $html .= '<td>' . esc_html( $s->session_name ?? 'جلسة' ) . '</td>';
                $html .= '<td>' . esc_html( $att ) . '</td>';
                $html .= '</tr>';
            }
            $html .= '</tbody></table>';
        } else {
            $html .= '<p>لا توجد جلسات هذا الشهر.</p>';
        }

        // Évaluation
        if ( $last_eval ) {
            $html .= '<h2>ملاحظة المدرب</h2>';
            $html .= '<p>' . esc_html( $last_eval['summary'] ?? '' ) . '</p>';
            if ( ! empty( $last_eval['strengths'] ) ) {
                $html .= '<p><strong>نقاط القوة:</strong> ' . esc_html( implode( '، ', $last_eval['strengths'] ) ) . '</p>';
            }
        }

        $html .= '<p style="text-align:center;color:#94a3b8;font-size:9pt;margin-top:40px;">RiadaKids © ' . date( 'Y' ) . '</p>';
        $html .= '</body></html>';

        // Générer PDF avec mPDF
        try {
            // Police arabe embarquée explicitement (Noto Naskh Arabic).
            // Ne PAS compter sur une police "core" mPDF par son nom (ex. 'xbriyaz') :
            // si elle n'est pas correctement enregistrée dans l'installation, mPDF
            // retombe silencieusement sur une police sans formes arabes liées, ce
            // qui produit des lettres isolées/déconnectées dans le PDF final.
            $font_dir = RK_MC_DIR . 'assets/fonts/';

            $default_font_config = ( new \Mpdf\Config\ConfigVariables() )->getDefaults();
            $font_dirs           = $default_font_config['fontDir'];

            $default_font_vars = ( new \Mpdf\Config\FontVariables() )->getDefaults();
            $font_data         = $default_font_vars['fontdata'];

            $mpdf = new \Mpdf\Mpdf( [
                'mode'            => 'utf-8',
                'format'          => 'A4',
                'directionality'  => 'rtl',
                'tempDir'         => sys_get_temp_dir(),
                // Active le moteur de reshaping arabe (lettres liées) + bidi.
                'autoScriptToLang' => true,
                'autoLangToFont'   => true,
                'fontDir'         => array_merge( $font_dirs, [ $font_dir ] ),
                'fontdata'        => $font_data + [
                    'notonaskharabic' => [
                        'R'  => 'NotoNaskhArabic-Regular.ttf',
                        'B'  => 'NotoNaskhArabic-Bold.ttf',
                        'I'  => 'NotoNaskhArabic-Regular.ttf', // pas d'italique dédiée : fallback regular
                        'BI' => 'NotoNaskhArabic-Bold.ttf',
                        'useOTL'  => 0xFF, // active les substitutions OpenType (formes liées ar.)
                        'useKashida' => 75,
                    ],
                ],
                'default_font'    => 'notonaskharabic',
            ] );
            $mpdf->SetFont( 'notonaskharabic' );
            $mpdf->SetTitle( 'تقرير ' . $child_name . ' — ' . $period );
            $mpdf->WriteHTML( $html );

            // Stocker dans /wp-content/uploads/rk-rapports/
            $upload = wp_upload_dir();
            $dir    = trailingslashit( $upload['basedir'] ) . 'rk-rapports';
            wp_mkdir_p( $dir );
            $filename = sanitize_file_name( 'rapport-' . $child_id . '-' . $year . '-' . $month . '.pdf' );
            $filepath = $dir . '/' . $filename;
            $mpdf->Output( $filepath, 'F' );

            // Créer WP attachment
            $attachment = [
                'post_mime_type' => 'application/pdf',
                'post_title'     => 'تقرير ' . $child_name . ' — ' . $period,
                'post_status'    => 'private',
            ];
            $att_id = wp_insert_attachment( $attachment, $filepath );
            if ( $att_id ) {
                require_once ABSPATH . 'wp-admin/includes/image.php';
                wp_update_attachment_metadata( $att_id, wp_generate_attachment_metadata( $att_id, $filepath ) );
            }
            return (int) $att_id;

        } catch ( \Exception $e ) {
            rkp_log( '[RK PDFService] mPDF error: ' . $e->getMessage() );
            return 0;
        }
    }
}