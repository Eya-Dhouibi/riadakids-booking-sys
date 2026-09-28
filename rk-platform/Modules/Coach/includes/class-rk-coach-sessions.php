<?php
declare( strict_types=1 );
/**
 * RK_Coach_Sessions — Page لقاءات du dashboard coach.
 * Requête directe sur wp_rk_bookings pour tous les enfants du coach.
 *
 * @package RK_Coach_Hub
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class RK_Coach_Sessions {

    /* ─── Rendu principal ───────────────────────────────────────────── */

    public static function render(): void {
        $coach_id = get_current_user_id();
        $students  = RK_Coach_Data::get_coach_students( $coach_id );
        $base_url  = function_exists( 'tutor_utils' ) ? tutor_utils()->tutor_dashboard_url( 'rk-seances' ) : '';

        ?>
        <div class="rk-coach-page" dir="rtl">

            <div class="rk-ch-topbar">
                <h2 class="rk-ch-topbar__title">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                    <?php esc_html_e( 'لقاءات', 'rk-coach-hub' ); ?>
                </h2>
            </div>

            <?php
            $all_child_ids = array_values( array_map( fn( $s ) => (int) $s->child_id, $students ) );
            if ( empty( $all_child_ids ) && class_exists( 'RKP_CoachSessionRepository' ) ) {
                $all_child_ids = RKP_CoachSessionRepository::find_child_ids_by_coach_id( $coach_id );
            }
            if ( empty( $all_child_ids ) ) : ?>
            <div class="rk-ch-section"><div class="rk-ch-section-body"><div class="rk-ch-empty">
                <div class="rk-ch-empty__icon"><svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="3" y1="10" x2="21" y2="10"/></svg></div>
                <p class="rk-ch-empty__title"><?php esc_html_e( 'لا يوجد طلاب مرتبطون بحسابك.', 'rk-coach-hub' ); ?></p>
            </div></div></div>
            </div>
            <?php return; endif; ?>

            <?php

            $filter   = sanitize_key( $_GET['status'] ?? 'upcoming' );
            if ( ! in_array( $filter, [ 'all', 'upcoming', 'past' ], true ) ) $filter = 'upcoming';
            $filter_s = absint( $_GET['filter_student'] ?? 0 );

            $child_ids = ( $filter_s && in_array( $filter_s, $all_child_ids, true ) )
                        ? [ $filter_s ]
                        : $all_child_ids;

            $per_page = 20;
            $page     = max( 1, absint( $_GET['page'] ?? 1 ) );
            $offset   = ( $page - 1 ) * $per_page;
            $now      = current_time( 'mysql' );

            $total    = RKP_CoachSessionRepository::count_for_coach( $coach_id, $child_ids, $filter, $now );
            $sessions = RKP_CoachSessionRepository::find_paginated_for_coach( $coach_id, $child_ids, $filter, $now, $per_page, $offset );
            // Si filtre upcoming retourne zéro, calculer le nombre de séances passées pour le hint
            $past_count = ( $filter === 'upcoming' && $total === 0 )
                ? RKP_CoachSessionRepository::count_for_coach( $coach_id, $child_ids, 'past', $now )
                : 0;

            $has_more      = ( $offset + count( $sessions ) ) < $total;
            $prev_page     = $page > 1 ? $page - 1 : null;
            $next_page     = $has_more ? $page + 1 : null;
            $filter_labels = [
                'upcoming' => __( 'القادمة',  'rk-coach-hub' ),
                'past'     => __( 'السابقة',  'rk-coach-hub' ),
                'all'      => __( 'الكل',     'rk-coach-hub' ),
            ];
            $status_map = [
                'confirmed'   => [ 'css' => 'confirmed',   'label' => 'مؤكدة' ],
                'completed'   => [ 'css' => 'completed',   'label' => 'مكتملة' ],
                'cancelled'   => [ 'css' => 'cancelled',   'label' => 'ملغاة' ],
                'rescheduled' => [ 'css' => 'rescheduled', 'label' => 'معاد جدولتها' ],
                'pending'     => [ 'css' => 'pending',     'label' => 'بانتظار التأكيد' ],
            ];
            ?>

            <!-- شريط الفلاتر -->
            <div class="rk-ch-section" style="margin-bottom:20px;">
                <div class="rk-ch-filterbar">

                    <div class="rk-ch-filter-chips">
                        <?php foreach ( $filter_labels as $key => $label ) :
                            $url = add_query_arg( array_filter( [
                                'status'         => $key === 'upcoming' ? null : $key,
                                'filter_student' => $filter_s ?: null,
                            ] ), $base_url );
                        ?>
                        <a href="<?php echo esc_url( $url ); ?>"
                           class="rk-ch-chip<?php echo $key === $filter ? ' rk-ch-chip--active' : ''; ?>">
                            <?php echo esc_html( $label ); ?>
                        </a>
                        <?php endforeach; ?>
                    </div>

                    <form method="get" style="display:flex;align-items:center;gap:8px;margin-right:auto;">
                        <?php foreach ( $_GET as $k => $v ) :
                            if ( in_array( $k, [ 'filter_student', 'tutor_dashboard_page' ], true ) ) continue;
                        ?>
                        <input type="hidden" name="<?php echo esc_attr( $k ); ?>" value="<?php echo esc_attr( (string) $v ); ?>">
                        <?php endforeach; ?>
                        <select name="filter_student" onchange="this.form.submit()"
                                style="padding:7px 12px;border:1.5px solid var(--ch-border,#e2e8f0);border-radius:8px;font-size:.85rem;background:#fff;cursor:pointer;">
                            <option value="0"><?php esc_html_e( 'كل الطلاب', 'rk-coach-hub' ); ?></option>
                            <?php foreach ( $students as $st ) : ?>
                            <option value="<?php echo (int) $st->child_id; ?>" <?php selected( $filter_s, (int) $st->child_id ); ?>>
                                <?php echo esc_html( $st->child_name ); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ( $filter_s ) :
                            $clear_url = add_query_arg( array_filter( [ 'status' => $filter === 'upcoming' ? null : $filter ] ), $base_url );
                        ?>
                        <a href="<?php echo esc_url( $clear_url ); ?>" class="rk-ch-btn rk-ch-btn--ghost rk-ch-btn--sm">
                            <?php esc_html_e( 'مسح', 'rk-coach-hub' ); ?>
                        </a>
                        <?php endif; ?>
                    </form>

                </div>
            </div>

            <!-- قائمة لقاءات -->
            <div class="rk-ch-section">
                <div class="rk-ch-section-head">
                    <div class="rk-ch-section-head__left">
                        <h3 class="rk-ch-section-title"><?php echo esc_html( $filter_labels[ $filter ] ); ?></h3>
                    </div>
                    <span class="rk-ch-pill rk-ch-pill--blue">
                        <?php echo $total; ?> <?php esc_html_e( 'جلسة', 'rk-coach-hub' ); ?>
                    </span>
                </div>

                <?php if ( empty( $sessions ) ) : ?>
                <div class="rk-ch-section-body">
                    <div class="rk-ch-empty">
                        <div class="rk-ch-empty__icon">
                            <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                        </div>
                        <p class="rk-ch-empty__title"><?php esc_html_e( 'لا توجد جلسات', 'rk-coach-hub' ); ?></p>
                        <p class="rk-ch-empty__text"><?php echo esc_html( $filter_labels[ $filter ] ); ?></p>
                        <?php if ( $past_count > 0 ) :
                            $past_url = add_query_arg( array_filter( [
                                'status'         => 'past',
                                'filter_student' => $filter_s ?: null,
                            ] ), $base_url );
                        ?>
                        <a href="<?php echo esc_url( $past_url ); ?>" class="rk-ch-btn rk-ch-btn--outline rk-ch-btn--sm" style="margin-top:12px;">
                            <?php printf( esc_html__( 'عرض %d جلسة سابقة', 'rk-coach-hub' ), $past_count ); ?>
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php else : ?>

                <div class="rk-sessions-history-list" style="padding:12px 0;">
                    <?php foreach ( $sessions as $s ) :
                        $ts          = strtotime( (string) ( $s->appointment ?? '' ) );
                        $is_past     = $ts && $ts < time();
                        $st          = $status_map[ $s->status ?? '' ] ?? [ 'css' => 'pending', 'label' => esc_html( (string) ( $s->status ?? '' ) ) ];
                        $s_booking_id = (int) ( $s->booking_id ?? 0 );
                        $attendance  = (string) ( $s->attendance ?? '' );

                        // v10.4.0 (Phase 3 — flow Coach) : état "تقييم الجلسة"
                        // basé sur booking_id (clé fiable), plus sur date+child
                        // (voir RKP_AssessmentEligibilityService, source unique
                        // pour l'éligibilité réelle côté backend — cette lecture
                        // ici est UNIQUEMENT pour l'affichage du bouton, jamais
                        // pour l'autorisation elle-même).
                        $has_assessment = $s_booking_id && class_exists( 'RK_MC_Assessment_Service' )
                            ? (bool) RK_MC_Assessment_Service::get_for_booking( $s_booking_id )
                            : false;
                        $eval_eligible  = in_array( $attendance, [ 'present', 'late' ], true );

                        $eval_url  = $s_booking_id ? add_query_arg( [
                            'child_id'   => (int) $s->child_id,
                            'booking_id' => $s_booking_id,
                        ], tutor_utils()->tutor_dashboard_url( 'rk-evaluer' ) ) : '';
                        $fiche_url = tutor_utils()->tutor_dashboard_url( 'rk-fiche-eleve' ) . '?child_id=' . (int) $s->child_id;
                        $zoom_url  = (string) ( $s->meeting_url ?? '' );
                    ?>
                    <div class="rk-session-group">
                    <div class="rk-session-history-row <?php echo $is_past ? 'rk-session-history--past' : 'rk-session-history--future'; ?>">

                        <div class="rk-session-history-date">
                            <span class="rk-session-history-day"><?php echo $ts ? esc_html( date_i18n( 'j', $ts ) ) : '—'; ?></span>
                            <span class="rk-session-history-month"><?php echo $ts ? esc_html( date_i18n( 'M', $ts ) ) : ''; ?></span>
                        </div>

                        <div class="rk-session-history-body">
                            <strong><?php echo esc_html( $s->child_name ?: '—' ); ?></strong>
                            <?php if ( ! empty( $s->session_name ) ) : ?>
                            <span style="display:block;font-size:.82rem;color:#64748b;"><?php echo esc_html( $s->session_name ); ?></span>
                            <?php endif; ?>
                            <span class="rk-session-history-time"><?php echo $ts ? esc_html( date_i18n( 'H:i', $ts ) ) : ''; ?></span>
                        </div>

                        <div class="rk-session-history-status">
                            <?php /* v10.4.0 — état حاضر/متأخر/غائب affiché en priorité
                               une fois l'attendance renseignée (post-séance) ; sinon
                               statut de réservation habituel (مؤكدة, etc.). */ ?>
                            <?php if ( 'present' === $attendance ) : ?>
                            <span class="rk-session-status rk-session-status--present"><?php esc_html_e( 'حاضر', 'rk-coach-hub' ); ?></span>
                            <?php elseif ( 'late' === $attendance ) : ?>
                            <span class="rk-session-status rk-session-status--late"><?php esc_html_e( 'متأخر', 'rk-coach-hub' ); ?></span>
                            <?php elseif ( 'absent' === $attendance ) : ?>
                            <span class="rk-session-status rk-session-status--absent"><?php esc_html_e( 'غائب', 'rk-coach-hub' ); ?></span>
                            <?php else : ?>
                            <span class="rk-session-status rk-session-status--<?php echo esc_attr( $st['css'] ); ?>">
                                <?php echo esc_html( $st['label'] ); ?>
                            </span>
                            <?php endif; ?>
                            <?php if ( $has_assessment ) : ?>
                            <span class="rk-session-status rk-session-status--evaluated">✓ <?php esc_html_e( 'تم التقييم', 'rk-coach-hub' ); ?></span>
                            <?php endif; ?>
                        </div>

                        <div style="display:flex;gap:6px;flex-shrink:0;">
                            <?php if ( 'absent' === $attendance ) : ?>
                                <span class="rk-ch-eval-form__hint" style="align-self:center;"><?php esc_html_e( 'لا يوجد تقييم', 'rk-coach-hub' ); ?></span>
                            <?php elseif ( $eval_eligible && $s_booking_id ) : ?>
                            <a href="<?php echo esc_url( $eval_url ); ?>" class="rk-ch-btn <?php echo $has_assessment ? 'rk-ch-btn--outline' : 'rk-ch-btn--primary'; ?> rk-ch-btn--sm" title="<?php echo esc_attr( $has_assessment ? __( 'تعديل التقييم', 'rk-coach-hub' ) : __( 'تقييم الجلسة', 'rk-coach-hub' ) ); ?>">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                <?php echo esc_html( $has_assessment ? __( 'تعديل التقييم', 'rk-coach-hub' ) : __( 'تقييم الجلسة', 'rk-coach-hub' ) ); ?>
                            </a>
                            <?php elseif ( ! $is_past ) : ?>
                                <span class="rk-ch-eval-form__hint" style="align-self:center;"><?php esc_html_e( 'بانتظار انتهاء الجلسة', 'rk-coach-hub' ); ?></span>
                            <?php endif; ?>
                            <a href="<?php echo esc_url( $fiche_url ); ?>" class="rk-ch-btn rk-ch-btn--ghost rk-ch-btn--sm" title="<?php esc_attr_e( 'ملف', 'rk-coach-hub' ); ?>">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                                <?php esc_html_e( 'ملف', 'rk-coach-hub' ); ?>
                            </a>
                        </div>

                    </div>

                    <?php if ( ! $is_past ) : ?>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
                          class="rk-zoom-form" style="display:flex;align-items:center;gap:8px;padding:6px 12px 10px;border-top:1px solid #f1f5f9;">
                        <input type="hidden" name="action" value="rk_coach_save_zoom_url">
                        <input type="hidden" name="booking_id" value="<?php echo (int) $s->booking_id; ?>">
                        <?php wp_nonce_field( 'rk_zoom_' . (int) $s->booking_id, 'rk_zoom_nonce' ); ?>
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#64748b" stroke-width="2" style="flex-shrink:0;" aria-hidden="true"><path d="M15 10l4.553-2.276A1 1 0 0121 8.723v6.554a1 1 0 01-1.447.894L15 14M3 8a2 2 0 012-2h10a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2V8z"/></svg>
                        <input type="url" name="meeting_url"
                               value="<?php echo esc_attr( $zoom_url ); ?>"
                               placeholder="<?php esc_attr_e( 'رابط Zoom أو Meet...', 'rk-coach-hub' ); ?>"
                               style="flex:1;min-width:0;padding:5px 10px;border:1.5px solid var(--ch-border,#e2e8f0);border-radius:8px;font-size:.82rem;background:#fff;">
                        <button type="submit" class="rk-ch-btn rk-ch-btn--ghost rk-ch-btn--sm">
                            <?php esc_html_e( 'حفظ', 'rk-coach-hub' ); ?>
                        </button>
                    </form>
                    <?php endif; ?>

                    </div><!-- .rk-session-group -->
                    <?php endforeach; ?>
                </div>

                <?php if ( $prev_page || $next_page ) : ?>
                <div class="rk-pagination-bar" style="display:flex;align-items:center;justify-content:center;gap:12px;padding:16px 0;" dir="rtl">
                    <?php if ( $prev_page ) :
                        $prev_url = add_query_arg( array_filter( [
                            'status'         => $filter === 'upcoming' ? null : $filter,
                            'filter_student' => $filter_s ?: null,
                            'page'           => $prev_page,
                        ] ), $base_url );
                    ?>
                    <a href="<?php echo esc_url( $prev_url ); ?>" class="rk-ch-btn rk-ch-btn--outline rk-ch-btn--sm">→ <?php esc_html_e( 'السابق', 'rk-coach-hub' ); ?></a>
                    <?php endif; ?>
                    <span style="font-size:.85rem;color:#64748b;">
                        <?php printf( esc_html__( 'صفحة %d من %d', 'rk-coach-hub' ), $page, max( 1, (int) ceil( $total / $per_page ) ) ); ?>
                    </span>
                    <?php if ( $next_page ) :
                        $next_url = add_query_arg( array_filter( [
                            'status'         => $filter === 'upcoming' ? null : $filter,
                            'filter_student' => $filter_s ?: null,
                            'page'           => $next_page,
                        ] ), $base_url );
                    ?>
                    <a href="<?php echo esc_url( $next_url ); ?>" class="rk-ch-btn rk-ch-btn--primary rk-ch-btn--sm">← <?php esc_html_e( 'المزيد', 'rk-coach-hub' ); ?></a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php endif; ?>
            </div>

        </div>
        <?php
    }

    /* ─── Handler présence (gardé pour rétrocompatibilité) ─────────── */

    public static function handle_mark_attendance(): void {
        $booking_id = absint( $_POST['booking_id'] ?? 0 );
        if ( ! check_admin_referer( 'rk_attendance_' . $booking_id, 'rk_attn_nonce' ) ) wp_die();
        if ( ! current_user_can( 'tutor_instructor' ) ) wp_die();

        $redirect = function_exists( 'tutor_utils' )
            ? add_query_arg( 'attn_done', '1', tutor_utils()->tutor_dashboard_url( 'rk-seances' ) )
            : admin_url();

        // Colonne attendance absente → redirection silencieuse
        if ( ! RK_Coach_Data::has_booking_column( 'attendance' ) ) {
            wp_redirect( $redirect );
            exit;
        }

        $attendance = sanitize_key( $_POST['attendance'] ?? '' );
        if ( ! in_array( $attendance, [ 'present', 'absent', 'late' ], true ) ) $attendance = 'present';

        $coach_id = get_current_user_id();
        $booking  = RKP_CoachSessionRepository::find_booking_basic( $booking_id );
        if ( ! $booking ) {
            wp_die( 'Access denied.' );
        }

        // CORRECTIF (revue Phase 3) — booking.coach_id est désormais la
        // SEULE source d'autorisation dès qu'elle est renseignée : un coach
        // ne doit jamais pouvoir modifier l'attendance d'un booking d'un
        // autre coach simplement parce qu'ils partagent le même enfant
        // (rk_child_coaches) ou le même type SSA. coach_owns_child() /
        // get_ssa_types_for_coach() ne restent un repli que pour les
        // bookings SANS coach_id (legacy / import).
        $booking_coach_id = (int) ( $booking->coach_id ?? 0 );
        if ( $booking_coach_id > 0 ) {
            if ( $booking_coach_id !== $coach_id ) {
                wp_die( 'Access denied.' );
            }
        } else {
            $ssa_type = (int) $booking->appointment_type_id;
            if ( $ssa_type && class_exists( 'RK_Coach_Data' ) ) {
                $coach_ssa_ids = RK_Coach_Data::get_ssa_types_for_coach( $coach_id );
                if ( ! in_array( $ssa_type, $coach_ssa_ids, true ) ) {
                    $child_id = (int) $booking->child_id;
                    if ( ! $child_id || ! RKP_CoachStudentRepository::coach_owns_child( $coach_id, $child_id ) ) {
                        wp_die( 'Access denied.' );
                    }
                }
            }
        }
        $child_id        = (int) $booking->child_id;
        $prev_attendance = (string) $booking->attendance;

        RKP_CoachSessionRepository::mark_attendance( $booking_id, $attendance );

        if ( $child_id > 0 && $attendance !== $prev_attendance ) {
            if ( $attendance === 'present' ) {
                do_action( 'rk_session_attended', $child_id, $booking_id, $prev_attendance );
            } elseif ( $attendance === 'absent' ) {
                do_action( 'rk_session_absent', $child_id, $booking_id, $prev_attendance );
            }
        }

        wp_redirect( $redirect );
        exit;
    }

    /* ─── Handler Zoom URL ──────────────────────────────────────────── */

    public static function handle_save_zoom_url(): void {
        $booking_id = absint( $_POST['booking_id'] ?? 0 );
        if ( ! check_admin_referer( 'rk_zoom_' . $booking_id, 'rk_zoom_nonce' ) ) wp_die();
        if ( ! current_user_can( 'tutor_instructor' ) ) wp_die();

        $redirect = function_exists( 'tutor_utils' )
            ? tutor_utils()->tutor_dashboard_url( 'rk-seances' )
            : admin_url();

        if ( ! RK_Coach_Data::has_booking_column( 'meeting_url' ) ) {
            wp_redirect( $redirect );
            exit;
        }

        $coach_id = get_current_user_id();
        $booking  = RKP_CoachSessionRepository::find_booking_basic( $booking_id );
        if ( $booking ) {
            $ssa_type = (int) $booking->appointment_type_id;
            if ( $ssa_type && class_exists( 'RK_Coach_Data' ) ) {
                $coach_ssa_ids = RK_Coach_Data::get_ssa_types_for_coach( $coach_id );
                if ( ! in_array( $ssa_type, $coach_ssa_ids, true ) ) {
                    // Fallback: accept if booking's child belongs to this coach
                    $child_id = (int) $booking->child_id;
                    if ( ! $child_id || ! RKP_CoachStudentRepository::coach_owns_child( $coach_id, $child_id ) ) {
                        wp_die( 'Access denied.' );
                    }
                }
            }
        }

        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'rk_bookings',
            [ 'meeting_url' => esc_url_raw( wp_unslash( (string) ( $_POST['meeting_url'] ?? '' ) ) ) ],
            [ 'id'          => $booking_id ],
            [ '%s' ],
            [ '%d' ]
        );

        wp_redirect( $redirect );
        exit;
    }
}
