<?php
declare( strict_types=1 );
/**
 * RiadaKids — شهاداتي (child view)
 *
 * v3.0 — Refonte complète, module Certificates.
 *
 * ANALYSE PRÉALABLE (voir résumé livré à l'utilisateur) :
 * - Tutor LMS Pro (add-on "Certificate Builder") n'est PAS actif sur ce
 *   site — la génération de PDF de certificat native n'est disponible
 *   que dans cette version payante, absente ici.
 * - La détection de complétion à 100% utilise l'API interne native de
 *   Tutor LMS (gratuite) : tutor_utils()->is_completed_course() et
 *   ->get_completed_courses_ids_by_user(), déjà encapsulées dans
 *   RKP_TutorEnvironment — AUCUNE requête SQL directe ici.
 * - Génération "automatique" : reste 100% dynamique (calculée à chaque
 *   affichage depuis les cours réellement complétés côté Tutor), sans
 *   nouvelle table de stockage — décision prise avec l'utilisateur
 *   pour éviter toute désynchronisation entre un enregistrement figé
 *   et l'état réel de progression.
 * - Un système SÉPARÉ existe déjà (RKP_CertificateRepository,
 *   wp_rk_child_certificates) pour des certificats uploadés
 *   MANUELLEMENT par le coach (diplômes externes, PDF tiers) — décision
 *   explicite de l'utilisateur : NE PAS les mélanger avec les
 *   certificats auto-générés de cette page.
 * - PDF téléchargeable : généré côté CLIENT (html2canvas + jsPDF, via
 *   CDN), décision explicite de l'utilisateur pour éviter d'ajouter une
 *   dépendance serveur (dompdf) risquant d'entrer en conflit avec
 *   d'autres plugins l'embarquant déjà.
 *
 * @package RK_My_Children
 * @since   9.0.0
 * @since   9.8.0 Refonte complète (module Certificates).
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$child = RK_MC_Tutor_Dashboard::get_child_for_template();
if ( ! $child ) {
    echo '<p>' . esc_html__( 'يرجى تحديد الطفل أولاً.', 'rk-my-children' ) . '</p>';
    return;
}
$child_id = (int) $child->id;
$wp_uid   = (int) ( $child->wp_user_id ?? 0 );
$name     = $child->child_name ?? __( 'الطفل', 'rk-my-children' );
$param    = RK_MC_Tutor_Dashboard::child_param();

/* ── Cours complétés — API native Tutor LMS uniquement ─────────────── */
$completed_courses = [];
if ( $wp_uid && class_exists( 'RKP_LearningQueryService' ) ) {
    $completed_ids = RKP_LearningQueryService::get_completed_course_ids( $wp_uid );
    if ( is_array( $completed_ids ) ) {

        $completed_ids = array_unique( array_map( 'intval', $completed_ids ) );
        foreach ( $completed_ids as $course_id ) {
            $course_id = (int) $course_id;
            $post      = get_post( $course_id );
            if ( ! $post ) continue;

            // Date réelle de complétion — enregistrement natif Tutor
            // (wp_comments, comment_date), plus fiable que post_modified
            // (repli utilisé seulement si Tutor ne renvoie rien).
            $completion = RKP_LearningQueryService::get_course_completion( $course_id, $wp_uid );
            $date_str   = $completion->comment_date ?? $post->post_modified;

            $instructor = class_exists( 'RKP_LearningQueryService' )
                ? RKP_LearningQueryService::get_instructor( $course_id )
                : null;

            // Code de vérification déterministe (même formule que la
            // version précédente) : reproductible sans stockage, unique
            // par enfant+cours, non devinable (salt WP).
            $cert_code = strtoupper( substr( hash( 'sha256', $child_id . '-' . $course_id . '-' . wp_salt() ), 0, 10 ) );

            $completed_courses[] = [
                'id'         => $course_id,
                'title'      => $post->post_title,
                'thumb'      => get_the_post_thumbnail_url( $course_id, 'medium' ) ?: '',
                'date'       => $date_str,
                'cert_code'  => $cert_code,
                'instructor' => $instructor ? $instructor->display_name : '',
            ];
        }
    }
}

/* ── Rendu du certificat imprimable / source du PDF client ──────────── */
if ( ! function_exists( 'rk_render_print_certificate' ) ) :
function rk_render_print_certificate( array $c, string $child_name ): void {
    $date_fmt = $c['date'] ? date_i18n( 'j F Y', strtotime( $c['date'] ) ) : date_i18n( 'j F Y' );
    ?>
    <!DOCTYPE html>
    <html dir="rtl" lang="ar">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width,initial-scale=1">
        <title><?php echo esc_html( sprintf( __( 'شهادة — %s', 'rk-my-children' ), $child_name ) ); ?></title>
        <style>
        @import url('https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;900&display=swap');
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Cairo', Tajawal, sans-serif; background: #f8fafc; display: flex; align-items: center; justify-content: center; min-height: 100vh; padding: 20px; }
        .cert { width: 780px; min-height: 560px; background: #fff; border: 3px solid #e2e8f0; border-radius: 20px; padding: 52px 60px; position: relative; overflow: hidden; text-align: center; }
        .cert::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 10px; background: linear-gradient(90deg, #E8500A, #1B4F8C); }
        .cert::after  { content: ''; position: absolute; bottom: 0; left: 0; right: 0; height: 10px; background: linear-gradient(90deg, #1B4F8C, #E8500A); }
        .cert-orb-1 { position: absolute; top: -80px; right: -80px; width: 240px; height: 240px; border-radius: 50%; background: radial-gradient(circle, rgba(27,79,140,.07) 0%, transparent 70%); }
        .cert-orb-2 { position: absolute; bottom: -80px; left: -80px; width: 240px; height: 240px; border-radius: 50%; background: radial-gradient(circle, rgba(232,80,10,.06) 0%, transparent 70%); }
        .cert-logo  { font-size: 1.1rem; font-weight: 900; color: #1B4F8C; letter-spacing: 2px; margin-bottom: 28px; }
        .cert-label { font-size: .85rem; color: #94a3b8; font-weight: 600; text-transform: uppercase; letter-spacing: 3px; margin-bottom: 14px; }
        .cert-name  { font-size: 2.6rem; font-weight: 900; color: #1e293b; margin-bottom: 10px; }
        .cert-sub   { font-size: 1rem; color: #64748b; margin-bottom: 30px; }
        .cert-program { font-size: 1.4rem; font-weight: 800; color: #E8500A; margin-bottom: 8px; padding: 16px 32px; background: linear-gradient(135deg, rgba(232,80,10,.07), rgba(27,79,140,.06)); border-radius: 12px; display: inline-block; }
        .cert-date  { font-size: .88rem; color: #64748b; margin-top: 28px; margin-bottom: 24px; }
        .cert-divider { width: 80px; height: 3px; background: linear-gradient(90deg, #E8500A, #1B4F8C); border-radius: 2px; margin: 20px auto; }
        .cert-footer { display: flex; justify-content: space-between; align-items: flex-end; margin-top: 32px; }
        .cert-sig   { text-align: center; }
        .cert-sig__line { width: 160px; height: 1px; background: #e2e8f0; margin-bottom: 6px; }
        .cert-sig__name { font-size: .78rem; color: #64748b; font-weight: 600; }
        .cert-sig__instructor { font-size: .82rem; color: #1e293b; font-weight: 700; margin-top: 2px; }
        .cert-verify { font-size: .72rem; color: #94a3b8; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 8px 14px; }
        .cert-verify code { font-family: monospace; font-size: .8rem; color: #1B4F8C; font-weight: 700; letter-spacing: 2px; }
        .cert-seal { width: 80px; height: 80px; border-radius: 50%; border: 3px solid #E8500A; display: flex; align-items: center; justify-content: center; color: #E8500A; margin: 0 auto 8px; }
        @media print { body { background: #fff; padding: 0; } .cert { border: none; border-radius: 0; } .no-print { display: none; } }
        .no-print-bar { background: #1e293b; color: #fff; padding: 12px 20px; text-align: center; font-size: .9rem; font-family: Cairo, sans-serif; }
        </style>
    </head>
    <body>
    <div>
        <div class="no-print-bar no-print">
            <button onclick="window.print()" style="background:#E8500A;color:#fff;border:none;padding:8px 24px;border-radius:8px;font-family:Cairo,sans-serif;font-size:.9rem;font-weight:700;cursor:pointer;margin-left:12px;">
                طباعة / حفظ PDF
            </button>
            <button onclick="window.close()" style="background:transparent;color:#fff;border:1px solid rgba(255,255,255,.3);padding:8px 20px;border-radius:8px;font-family:Cairo,sans-serif;font-size:.9rem;cursor:pointer;">
                إغلاق
            </button>
        </div>
        <div class="cert" id="rk-cert-surface">
            <div class="cert-orb-1"></div>
            <div class="cert-orb-2"></div>

            <div class="cert-logo">RiadaKids</div>

            <div class="cert-seal">
                <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><circle cx="12" cy="8" r="6"/><path d="M15.477 12.89L17 22l-5-3-5 3 1.523-9.11"/></svg>
            </div>

            <p class="cert-label"><?php esc_html_e( 'شهادة إتمام', 'rk-my-children' ); ?></p>
            <h1 class="cert-name"><?php echo esc_html( $child_name ); ?></h1>
            <p class="cert-sub"><?php esc_html_e( 'أكمل بنجاح برنامج', 'rk-my-children' ); ?></p>
            <div class="cert-divider"></div>
            <div class="cert-program"><?php echo esc_html( $c['title'] ); ?></div>
            <p class="cert-date"><?php echo esc_html( $date_fmt ); ?></p>

            <div class="cert-footer">
                <div class="cert-sig">
                    <div class="cert-sig__line"></div>
                    <div class="cert-sig__name"><?php esc_html_e( 'المدير التنفيذي', 'rk-my-children' ); ?></div>
                </div>
                <div class="cert-verify">
                    <?php esc_html_e( 'رمز التحقق:', 'rk-my-children' ); ?>
                    <code><?php echo esc_html( $c['cert_code'] ); ?></code>
                </div>
                <div class="cert-sig">
                    <div class="cert-sig__line"></div>
                    <div class="cert-sig__name"><?php esc_html_e( 'المدرب المشرف', 'rk-my-children' ); ?></div>
                    <?php if ( ! empty( $c['instructor'] ) ) : ?>
                    <div class="cert-sig__instructor"><?php echo esc_html( $c['instructor'] ); ?></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    </body>
    </html>
    <?php
    exit;
}
endif;

// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- lecture simple d'un code de vérification pour affichage, pas d'action sensible.
$print_id = sanitize_key( $_GET['print_cert'] ?? '' );

/* ── Mode impression/PDF d'un certificat unique ─────────────────────── */
if ( $print_id ) {
    $course_to_print = null;
    foreach ( $completed_courses as $c ) {
        if ( $c['cert_code'] === strtoupper( $print_id ) ) { $course_to_print = $c; break; }
    }
    if ( $course_to_print ) {
        rk_render_print_certificate( $course_to_print, $name );
        return;
    }
}

$browse_courses_url = class_exists( 'RKP_LearningQueryService' )
    ? RKP_LearningQueryService::get_dashboard_url( 'enrolled-courses' ) . $param
    : home_url( '/' );
?>
<div class="rkc2" dir="rtl" data-rk-certificates>

    <div class="rkc2__header">
        <h1 class="rkc2__title">
            <?php echo wp_kses_post( rk_mc_svg( 'award', [ 'class' => 'rkc2__title-icon' ] ) ); ?>
            <?php esc_html_e( 'إنجازاتي', 'rk-my-children' ); ?>
        </h1>
        <div class="rkc2__tabs">
            <span class="rkc2__tab rkc2__tab--active">
                <?php echo wp_kses_post( rk_mc_svg( 'graduation-cap', [] ) ); ?>
                <?php esc_html_e( 'شهادتي', 'rk-my-children' ); ?>
            </span>
            <?php if ( class_exists( 'RKP_LearningQueryService' ) ) : ?>
            <a href="<?php echo esc_url( RKP_LearningQueryService::get_dashboard_url( 'rk-badges' ) . $param ); ?>" class="rkc2__tab">
                <?php echo wp_kses_post( rk_mc_svg( 'award', [] ) ); ?>
                <?php esc_html_e( 'شاراتي', 'rk-my-children' ); ?>
            </a>
            <?php endif; ?>
        </div>
    </div>

    <?php if ( empty( $completed_courses ) ) : ?>

    <!-- ═══════════════ Empty State premium ═══════════════ -->
    <div class="rkc2__empty">
        <div class="rkc2__empty-banner">
            <?php echo wp_kses_post( rk_mc_svg( 'award', [ 'class' => 'rkc2__empty-banner-ico' ] ) ); ?>
            <div>
                <p class="rkc2__empty-banner-title"><?php esc_html_e( 'خزانة شهاداتي', 'rk-my-children' ); ?></p>
                <p class="rkc2__empty-banner-text"><?php esc_html_e( 'أكمل مغامرتك لتحصل على شهادات جديدة!', 'rk-my-children' ); ?></p>
            </div>
        </div>

        <img src="<?php echo esc_url( RK_MC_URL . 'assets/img/illustrations/empty-course.webp' ); ?>"
             alt="" class="rkc2__empty-illustration" loading="lazy" width="220" height="220">

        <h2 class="rkc2__empty-title"><?php esc_html_e( 'لم تحصل على أي شهادة بعد', 'rk-my-children' ); ?></h2>
        <p class="rkc2__empty-text"><?php esc_html_e( 'أكمل أول دورة تدريبية لتحصل على أول شهادة إنجاز.', 'rk-my-children' ); ?></p>

        <a href="<?php echo esc_url( $browse_courses_url ); ?>" class="rk-adv2__cta rk-adv2__cta--go rkc2__empty-cta">
            <?php esc_html_e( 'استكشف الدورات', 'rk-my-children' ); ?>
            <svg class="rk-adv2__cta-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" aria-hidden="true">
                <polyline points="15 18 9 12 15 6"/>
            </svg>
        </a>
    </div>

    <?php else : ?>

    <p class="rkc2__intro">
        <?php printf(
            /* translators: 1: child name, 2: certificates count */
            esc_html__( 'أحسنت %1$s! حصلت على %2$d شهادة إنجاز.', 'rk-my-children' ),
            esc_html( $name ), count( $completed_courses )
        ); ?>
    </p>

    <div class="rkc2__grid" data-rk-stagger>
        <?php
        // BUGFIX (12/08/2026) — home_url( add_query_arg( [] ) ) était
        // incorrect : add_query_arg( [] ) sans second paramètre retourne
        // REQUEST_URI (déjà une URL relative complète avec chemin), et
        // l'emboîter dans home_url() cassait le résultat (concaténation
        // erronée, l'iframe pointait vers une URL invalide → contenu
        // vide dans la modale, symptôme observé le 12/08/2026).
        //
        // Fix : construire l'URL de la page courante proprement, une
        // seule fois, EN PRÉSERVANT $param (rk_tab=<hash>) — le système
        // de contexte enfant (RK_MC_Tutor_Dashboard::child_param(),
        // déjà chargé plus haut) : sans lui, la page de destination de
        // l'iframe perd le contexte enfant sélectionné et affiche une
        // page vide/différente ("n'est pas au même endroit" — second
        // symptôme observé). $param inclut déjà le point d'interrogation
        // ("?rk_tab=..." ou "") donc on l'ajoute tel quel avant
        // add_query_arg(), qui gère correctement l'ajout d'un second
        // paramètre à une URL en ayant déjà un.
        $current_page_url = ( is_ssl() ? 'https://' : 'http://' ) . $_SERVER['HTTP_HOST'] . strtok( $_SERVER['REQUEST_URI'], '?' ) . $param;
        foreach ( $completed_courses as $c ) :
            $date_fmt  = $c['date'] ? date_i18n( 'j F Y', strtotime( $c['date'] ) ) : '';
            $print_url = add_query_arg( 'print_cert', strtolower( $c['cert_code'] ), $current_page_url );
        ?>
        <div class="rkc2__card" data-rk-cert-card
             data-title="<?php echo esc_attr( sprintf( __( 'شهادة إتمام دورة: %s', 'rk-my-children' ), $c['title'] ) ); ?>"
             data-print-url="<?php echo esc_url( $print_url ); ?>"
             data-code="<?php echo esc_attr( $c['cert_code'] ); ?>">

            <div class="rkc2__card-thumb">
                <?php if ( $c['thumb'] ) : ?>
                <img src="<?php echo esc_url( $c['thumb'] ); ?>" alt="" loading="lazy">
                <?php else : ?>
                <div class="rkc2__card-thumb-placeholder">
                    <?php echo wp_kses_post( rk_mc_svg( 'graduation-cap', [] ) ); ?>
                </div>
                <?php endif; ?>
                <span class="rkc2__card-badge">
                    <?php echo wp_kses_post( rk_mc_svg( 'check', [] ) ); ?>
                    <?php esc_html_e( 'مُعتمدة', 'rk-my-children' ); ?>
                </span>
            </div>

            <div class="rkc2__card-body">
                <h3 class="rkc2__card-course-title"><?php echo esc_html( $c['title'] ); ?></h3>

                <?php if ( $date_fmt ) : ?>
                <p class="rkc2__card-meta">
                    <?php echo wp_kses_post( rk_mc_svg( 'calendar', [] ) ); ?>
                    <?php echo esc_html( $date_fmt ); ?>
                </p>
                <?php endif; ?>

                <?php if ( $c['instructor'] ) : ?>
                <p class="rkc2__card-meta">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>
                    </svg>
                    <?php echo esc_html( $c['instructor'] ); ?>
                </p>
                <?php endif; ?>

                <div class="rkc2__card-actions">
                    <button type="button" class="rkc2__card-action" data-rk-cert-view title="<?php esc_attr_e( 'عرض', 'rk-my-children' ); ?>">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                    </button>
                    <button type="button" class="rkc2__card-action" data-rk-cert-download title="<?php esc_attr_e( 'تحميل PDF', 'rk-my-children' ); ?>">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                    </button>
                    <button type="button" class="rkc2__card-action" data-rk-cert-share title="<?php esc_attr_e( 'مشاركة', 'rk-my-children' ); ?>">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><line x1="8.6" y1="13.5" x2="15.4" y2="17.5"/><line x1="15.4" y1="6.5" x2="8.6" y2="10.5"/></svg>
                    </button>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <?php
    ?>

    <?php endif; ?>

</div>