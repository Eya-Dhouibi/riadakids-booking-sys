<?php
declare( strict_types=1 );
/**
 * RK_Coach_Messaging — الرسائل avec sidebar élèves + templates rapides.
 * v3.0 — Sidebar student list · mini-card header · quick templates panel.
 *
 * @package RK_Coach_Hub
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class RK_Coach_Messaging {

    public static function render(): void {
        $coach_id  = get_current_user_id();
        $students  = RK_Coach_Data::get_coach_students( $coach_id );
        $active_id = absint( $_GET['child_id'] ?? 0 );
        $base_url  = tutor_utils()->tutor_dashboard_url( 'rk-messages' );

        // Trouver le parent et le WP user de l'élève actif
        $active_parent_id = 0;
        $active_child_wp  = 0;
        $active_child     = null;
        $raw_msg_to       = sanitize_key( $_GET['msg_to'] ?? 'parent' );
        $msg_to           = in_array( $raw_msg_to, [ 'parent', 'child' ], true ) ? $raw_msg_to : 'parent';

        if ( $active_id && class_exists( 'RK_Coach_Data' ) ) {
            $active_parent_id = RK_Coach_Data::get_parent_of_child( $active_id );
            $active_child_wp  = RK_Coach_Data::get_child_wp_user_id( $active_id );
            $active_child     = get_userdata( $active_id );
        }

        // Destinataire BM effectif
        $bm_recipient_id = ( $msg_to === 'child' && $active_child_wp > 0 )
                           ? $active_child_wp
                           : $active_parent_id;

        $has_bm = class_exists( 'Better_Messages' );

        // Templates rapides
        $templates = [
            __( 'أحسنت! أداء الطالب اليوم كان ممتازًا.', 'rk-coach-hub' ),
            __( 'الجلسة القادمة ستكون يوم {DATE}. هل يمكنكم التأكيد؟', 'rk-coach-hub' ),
            __( 'أرجو التأكد من مراجعة الطالب للمحتوى قبل الجلسة القادمة.', 'rk-coach-hub' ),
            __( 'لاحظت تحسنًا ملحوظًا في أداء الطالب. استمروا هكذا!', 'rk-coach-hub' ),
            __( 'غاب الطالب عن الجلسة. هل كل شيء على ما يرام؟', 'rk-coach-hub' ),
        ];
        ?>
        <div class="rk-coach-page" dir="rtl">

            <!-- Top bar -->
            <div class="rk-ch-topbar">
                <h2 class="rk-ch-topbar__title">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                    <?php esc_html_e( 'الرسائل', 'rk-coach-hub' ); ?>
                </h2>
            </div>

            <?php if ( ! $has_bm ) : ?>
            <div class="rk-ch-notice rk-ch-notice--warn">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                <?php esc_html_e( 'نظام الرسائل غير متاح. تأكد من تفعيل إضافة Better Messages.', 'rk-coach-hub' ); ?>
            </div>
            <?php else : ?>

            <div class="rk-ch-msg-layout<?php echo $active_id ? ' rk-ch-msg-layout--active' : ''; ?>">

                <!-- Sidebar : liste des élèves -->
                <div class="rk-ch-msg-sidebar">
                    <div class="rk-ch-msg-sidebar__head">
                        <span class="rk-ch-msg-sidebar__title"><?php esc_html_e( 'المحادثات', 'rk-coach-hub' ); ?></span>
                    </div>
                    <ul class="rk-ch-msg-sidebar__list">
                        <?php foreach ( $students as $st ) :
                            $sid        = (int) $st->child_id;
                            $parent_id  = RK_Coach_Data::get_parent_of_child( $sid );
                        $unread     = 0;
                            if ( $parent_id > 0 && function_exists( 'bm_get_table' ) ) {
                                global $wpdb;
                                $bm_tbl_r  = bm_get_table( 'recipients' );
                                $bm_tid    = (int) $wpdb->get_var( $wpdb->prepare(
                                    "SELECT r1.thread_id
                                       FROM {$bm_tbl_r} r1
                                 INNER JOIN {$bm_tbl_r} r2 ON r1.thread_id = r2.thread_id
                                      WHERE r1.user_id = %d AND r2.user_id = %d
                                      LIMIT 1",
                                    $coach_id, $parent_id
                                ) );
                                if ( $bm_tid > 0 ) {
                                    $unread = (int) $wpdb->get_var( $wpdb->prepare(
                                        "SELECT COALESCE(unread_count,0) FROM {$bm_tbl_r}
                                          WHERE thread_id = %d AND user_id = %d",
                                        $bm_tid, $coach_id
                                    ) );
                                }
                            }
                            $item_url   = add_query_arg( 'child_id', $sid, $base_url );
                            $is_active  = $sid === $active_id;
                        ?>
                        <li class="rk-ch-msg-sidebar__item<?php echo $is_active ? ' rk-ch-msg-sidebar__item--active' : ''; ?>">
                            <a href="<?php echo esc_url( $item_url ); ?>" class="rk-ch-msg-sidebar__link">
                                <img src="<?php echo esc_url( get_avatar_url( $sid, [ 'size' => 40 ] ) ); ?>"
                                     alt="<?php echo esc_attr( $st->child_name ); ?>"
                                     class="rk-ch-msg-sidebar__avatar"
                                     width="36" height="36">
                                <div class="rk-ch-msg-sidebar__info">
                                    <span class="rk-ch-msg-sidebar__name"><?php echo esc_html( $st->child_name ); ?></span>
                                    <?php if ( ! empty( $st->program_name ) ) : ?>
                                    <span class="rk-ch-msg-sidebar__program"><?php echo esc_html( $st->program_name ); ?></span>
                                    <?php endif; ?>
                                </div>
                                <?php if ( $unread && ! $is_active ) : ?>
                                <span class="rk-ch-msg-sidebar__badge"><?php echo $unread; ?></span>
                                <?php endif; ?>
                            </a>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>

                <!-- Zone principale -->
                <div class="rk-ch-msg-main">

                    <!-- Bouton retour (mobile uniquement) -->
                    <a href="<?php echo esc_url( $base_url ); ?>" class="rk-ch-msg-back" aria-label="<?php esc_attr_e( 'العودة للقائمة', 'rk-coach-hub' ); ?>">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><polyline points="9 18 15 12 9 6"/></svg>
                        <?php esc_html_e( 'المحادثات', 'rk-coach-hub' ); ?>
                    </a>

                    <?php if ( $active_child && $active_parent_id ) : ?>
                    <?php
                    // Sprint 4 — données enrichies en-tête
                    $parent_data = get_userdata( $active_parent_id );

                    // Prochaine séance
                    $next_booking = class_exists( 'RKP_BookingRepository' )
                        ? RKP_BookingRepository::get_next_confirmed( $active_id )
                        : null;
                    $next_session_label = $next_booking
                        ? date_i18n( 'j M · H:i', strtotime( $next_booking->appointment ) )
                        : __( '—', 'rk-coach-hub' );

                    // Dernier feedback
                    $last_bilan = class_exists( 'RK_MC_Assessment_Service' )
                        ? RK_MC_Assessment_Service::get_latest( $active_id ) : null;
                    $last_rating = $last_bilan ? (int) $last_bilan['rating'] : 0;
                    $last_date   = $last_bilan ? date_i18n( 'j M', strtotime( $last_bilan['assessed_at'] ?? '' ) ) : __( '—', 'rk-coach-hub' );

                    // Crédits parent
                    $credits = function_exists( 'rk_mc_get_session_credits' )
                        ? (int) rk_mc_get_session_credits( $active_parent_id ) : '—';
                    ?>
                    <!-- Mini-card header élève actif (Sprint 4 enrichi) -->
                    <div class="rk-ch-msg-child-header" style="flex-wrap:wrap;gap:12px;">
                        <img src="<?php echo esc_url( get_avatar_url( $active_id, [ 'size' => 48 ] ) ); ?>"
                             alt="<?php echo esc_attr( $active_child->display_name ); ?>"
                             class="rk-ch-msg-child-header__avatar"
                             width="40" height="40">
                        <div class="rk-ch-msg-child-header__info" style="flex:1;min-width:120px;">
                            <span class="rk-ch-msg-child-header__name"><?php echo esc_html( $active_child->display_name ); ?></span>
                            <?php if ( $parent_data ) : ?>
                            <span class="rk-ch-msg-child-header__parent">
                                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                                <?php echo esc_html( $parent_data->display_name ); ?>
                            </span>
                            <?php endif; ?>
                        </div>
                        <!-- KPIs conversation : prochaine séance · dernier feedback · crédits -->
                        <div class="rk-ch-msg-child-kpis" style="display:flex;gap:16px;align-items:center;font-size:.78rem;">
                            <div style="display:flex;flex-direction:column;align-items:center;gap:2px;">
                                <span style="font-weight:700;color:#1e293b;"><?php echo esc_html( $next_session_label ); ?></span>
                                <span style="color:#64748b;"><?php esc_html_e( 'الجلسة القادمة', 'rk-coach-hub' ); ?></span>
                            </div>
                            <div style="display:flex;flex-direction:column;align-items:center;gap:2px;">
                                <span style="font-weight:700;color:#1e293b;">
                                    <?php if ( $last_rating ) :
                                        echo str_repeat( '★', $last_rating ) . str_repeat( '☆', 5 - $last_rating );
                                    else : echo '—';
                                    endif; ?>
                                </span>
                                <span style="color:#64748b;"><?php echo esc_html( $last_date ); ?></span>
                            </div>
                            <div style="display:flex;flex-direction:column;align-items:center;gap:2px;">
                                <span style="font-weight:700;color:<?php echo is_numeric( $credits ) && (int) $credits <= 2 ? '#b91c1c' : '#1e293b'; ?>;">
                                    <?php echo esc_html( (string) $credits ); ?>
                                </span>
                                <span style="color:#64748b;"><?php esc_html_e( 'الأرصدة', 'rk-coach-hub' ); ?></span>
                            </div>
                        </div>
                        <a href="<?php echo esc_url( add_query_arg( 'child_id', $active_id, tutor_utils()->tutor_dashboard_url( 'rk-fiche-eleve' ) ) ); ?>"
                           class="rk-ch-btn rk-ch-btn--ghost rk-ch-btn--sm">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                            <?php esc_html_e( 'الملف', 'rk-coach-hub' ); ?>
                        </a>
                    </div>

                    <!-- Toggle parent / enfant -->
                    <?php if ( $active_child_wp > 0 ) : ?>
                    <div class="rk-ch-msg-toggle" style="display:flex;gap:8px;margin-bottom:12px;border-bottom:1px solid #e2e8f0;padding-bottom:10px;">
                        <a href="<?php echo esc_url( add_query_arg( [ 'child_id' => $active_id, 'msg_to' => 'parent' ], $base_url ) ); ?>"
                           class="rk-ch-btn rk-ch-btn--sm<?php echo $msg_to === 'parent' ? ' rk-ch-btn--primary' : ' rk-ch-btn--ghost'; ?>"
                           style="display:inline-flex;align-items:center;gap:5px;">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                            <?php esc_html_e( 'ولي الأمر', 'rk-coach-hub' ); ?>
                        </a>
                        <a href="<?php echo esc_url( add_query_arg( [ 'child_id' => $active_id, 'msg_to' => 'child' ], $base_url ) ); ?>"
                           class="rk-ch-btn rk-ch-btn--sm<?php echo $msg_to === 'child' ? ' rk-ch-btn--primary' : ' rk-ch-btn--ghost'; ?>"
                           style="display:inline-flex;align-items:center;gap:5px;">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                            <?php esc_html_e( 'الطالب مباشرة', 'rk-coach-hub' ); ?>
                        </a>
                    </div>
                    <?php endif; ?>

                    <!-- Templates rapides -->
                    <div class="rk-ch-templates">
                        <span class="rk-ch-templates__label"><?php esc_html_e( 'رسائل جاهزة:', 'rk-coach-hub' ); ?></span>
                        <?php foreach ( $templates as $tpl ) : ?>
                        <button type="button" class="rk-ch-tpl-btn" data-tpl="<?php echo esc_attr( $tpl ); ?>">
                            <?php echo esc_html( mb_substr( $tpl, 0, 30 ) ) . ( mb_strlen( $tpl ) > 30 ? '…' : '' ); ?>
                        </button>
                        <?php endforeach; ?>
                    </div>

                    <!-- BP BM (destinataire : parent ou enfant selon toggle) -->
                    <div class="rk-ch-bm-wrapper" id="rk-bm-recipient-<?php echo $bm_recipient_id; ?>">
                        <?php
                        add_filter( 'bp_better_messages_initial_user', function() use ( $bm_recipient_id ) {
                            return $bm_recipient_id;
                        } );
                        echo do_shortcode( '[better_messages]' );
                        ?>
                    </div>

                    <?php elseif ( ! $active_id ) : ?>
                    <!-- État vide : choisir un élève -->
                    <div class="rk-ch-empty" style="padding:60px 20px;">
                        <div class="rk-ch-empty__icon">
                            <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                        </div>
                        <p class="rk-ch-empty__title"><?php esc_html_e( 'اختر طالبًا لبدء المحادثة', 'rk-coach-hub' ); ?></p>
                        <p class="rk-ch-empty__text"><?php esc_html_e( 'يمكنك مراسلة ولي الأمر أو الطالب مباشرة.', 'rk-coach-hub' ); ?></p>
                    </div>

                    <?php else : ?>
                    <!-- Élève sans parent lié -->
                    <div class="rk-ch-notice rk-ch-notice--warn">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                        <?php esc_html_e( 'لا يوجد ولي أمر مرتبط بهذا الطالب.', 'rk-coach-hub' ); ?>
                    </div>
                    <?php endif; ?>

                </div><!-- /.rk-ch-msg-main -->

            </div><!-- /.rk-ch-msg-layout -->

            <!-- JS templates -->
            <script>
            (function(){
                document.querySelectorAll('.rk-ch-tpl-btn').forEach(function(btn){
                    btn.addEventListener('click', function(){
                        var tpl = this.dataset.tpl || '';
                        var area = document.querySelector('#rk-bm-recipient-<?php echo $bm_recipient_id; ?> textarea, .better-messages-form textarea');
                        if ( area ) {
                            area.value = tpl;
                            area.focus();
                            area.dispatchEvent(new Event('input', {bubbles:true}));
                        }
                    });
                });
            })();
            </script>

            <?php endif; ?>

        </div>
        <?php
    }
}
