<?php
declare( strict_types=1 );
/**
 * RiadaKids — Tutor Dashboard Sub-Page: شهاداتي
 * Intercepte le slug natif Tutor LMS "certificates" pour l'afficher
 * dans le contexte enfant RiadaKids.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$child = RK_MC_Tutor_Dashboard::get_child_for_template();
if ( ! $child ) {
    echo '<p dir="rtl" style="padding:24px;color:#64748b;">'
         . esc_html__( 'يرجى تحديد الطفل أولاً.', 'rk-my-children' )
         . '</p>';
    return;
}

$child_id  = (int) $child->id;
$child_uid = (int) ( $child->wp_user_id ?? 0 );
$child_name = esc_html( $child->child_name ?? __( 'الطفل', 'rk-my-children' ) );

/* ── Certificats — logique native Tutor LMS ──────────────────────
 * Complétion stockée dans wp_comments (comment_type='course_completed',
 * comment_agent='TutorLMSPlugin'), PAS dans une user meta.
 * RKP_LearningQueryService::get_course_completion() lit cette table et renvoie
 * l'objet avec comment_date réel (completion_date).
 * ─────────────────────────────────────────────────────────────── */
$certificates = array();
if ( $child_uid && class_exists( 'RKP_LearningQueryService' ) ) {
    $completed_ids = (array) RKP_LearningQueryService::get_completed_course_ids( $child_uid );
    foreach ( $completed_ids as $cid ) {
        $cid        = (int) $cid;
        $completion = RKP_LearningQueryService::get_course_completion( $cid, $child_uid );

        $certificates[] = array(
            'course_id'    => $cid,
            'course_title' => get_the_title( $cid ),
            'thumb'        => get_the_post_thumbnail_url( $cid, 'thumbnail' ) ?: '',
            'course_url'   => (string) get_permalink( $cid ),
            'completed_at' => $completion->completion_date ?? '',
        );
    }
    // Plus récents en premier (cohérent avec l'ordre Tutor "completed-courses").
    usort( $certificates, static function ( $a, $b ) {
        return strtotime( (string) $b['completed_at'] ) <=> strtotime( (string) $a['completed_at'] );
    } );
}
?>
<div class="rk-td-page-certificates" dir="rtl" style="padding-bottom:32px;">

    <h2 style="display:flex;align-items:center;gap:8px;margin-bottom:20px;font-size:1.2rem;font-weight:700;color:#1e293b;">
        <?php echo wp_kses_post( rk_mc_svg( 'award', array( 'style' => 'width:22px;height:22px;color:#f59e0b;' ) ) ); ?>
        <?php esc_html_e( 'شهاداتي', 'rk-my-children' ); ?>
        <?php if ( $certificates ) : ?>
        <span style="background:#fef3c7;color:#b45309;font-size:.78rem;padding:2px 10px;border-radius:20px;font-weight:600;margin-right:auto;">
            <?php printf( esc_html__( '%d شهادة', 'rk-my-children' ), count( $certificates ) ); ?>
        </span>
        <?php endif; ?>
    </h2>

    <?php if ( $certificates ) : ?>
    <div style="display:flex;flex-direction:column;gap:14px;">
        <?php foreach ( $certificates as $cert ) : ?>
        <a href="<?php echo esc_url( $cert['course_url'] ); ?>"
           style="background:#fffbeb;border:1.5px solid #fde68a;border-radius:14px;padding:16px;display:flex;align-items:center;gap:14px;text-decoration:none;">

            <!-- Thumbnail ou icône -->
            <div style="width:60px;height:60px;border-radius:10px;overflow:hidden;flex-shrink:0;background:#fef3c7;display:flex;align-items:center;justify-content:center;">
                <?php if ( $cert['thumb'] ) : ?>
                    <img src="<?php echo esc_url( $cert['thumb'] ); ?>" alt="" loading="lazy"
                         style="width:100%;height:100%;object-fit:cover;">
                <?php else : ?>
                    <?php echo wp_kses_post( rk_mc_svg( 'award', array( 'style' => 'width:28px;height:28px;color:#d97706;' ) ) ); ?>
                <?php endif; ?>
            </div>

            <!-- Info -->
            <div style="flex:1;min-width:0;">
                <div style="font-weight:700;color:#1e293b;font-size:.95rem;margin-bottom:3px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                    <?php echo esc_html( $cert['course_title'] ); ?>
                </div>
                <div style="font-size:.78rem;color:#92400e;">
                    <?php esc_html_e( 'دورة مكتملة', 'rk-my-children' ); ?>
                    <?php if ( $cert['completed_at'] ) : ?>
                     · <?php echo esc_html( date_i18n( 'j M Y', strtotime( $cert['completed_at'] ) ) ); ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Badge réussite -->
            <div style="flex-shrink:0;background:#d97706;color:#fff;width:36px;height:36px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:1.1rem;">
                🏆
            </div>
        </a>
        <?php endforeach; ?>
    </div>

    <?php else : ?>
    <div style="text-align:center;padding:60px 24px;">
        <?php echo wp_kses_post( rk_mc_svg( 'award', array( 'style' => 'width:56px;height:56px;opacity:.2;margin:0 auto 16px;display:block;' ) ) ); ?>
        <p style="color:#64748b;font-size:.95rem;">
            <?php printf(
                esc_html__( 'لا توجد شهادات لـ %s بعد. أكمل دورة لتحصل على شهادتك!', 'rk-my-children' ),
                $child_name
            ); ?>
        </p>
    </div>
    <?php endif; ?>
</div>

