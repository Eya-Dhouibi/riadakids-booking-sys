<?php
declare( strict_types=1 );
/**
 * RiadaKids — Tutor Dashboard: صفحة "المغامرة" (détail d'un cours)
 *
 * Remplace le lien natif Tutor LMS "افتح المغامرة" (permalink du cours)
 * par une page custom intégrée au Dashboard, alimentée intégralement
 * par les couches Domain/Application/Infrastructure déjà en place
 * (RKP_LearningQueryService) — aucun accès direct à tutor_utils()/$wpdb
 * ici, comme partout ailleurs dans ce module.
 *
 * Slug : 'rk-adventure', paramètre 'course_id' en query string, propagé
 * avec ?rk_tab=xxx (ou ?child_id=NN) exactement comme les autres pages
 * du dashboard — voir class-rk-mc-adventures-filter-service.php pour la
 * construction du lien depuis les cartes de مغامراتي.
 *
 * @package RK_My_Children
 * @since   9.5.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$child = RK_MC_Tutor_Dashboard::get_child_for_template();
if ( ! $child ) {
    echo '<p>' . esc_html__( 'يرجى تحديد الطفل أولاً.', 'rk-my-children' ) . '</p>';
    return;
}
$child_id = (int) $child->id;
$wp_uid   = (int) ( $child->wp_user_id ?? 0 );
$param    = RK_MC_Tutor_Dashboard::child_param();

// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- lecture simple d'un ID, pas d'action sensible.
$course_id = isset( $_GET['course_id'] ) ? absint( $_GET['course_id'] ) : 0;

if ( ! $course_id || ! class_exists( 'RKP_LearningQueryService' ) ) {
    ?>
    <div class="rk-adv__notfound" dir="rtl">
        <p><?php esc_html_e( 'المغامرة غير موجودة.', 'rk-my-children' ); ?></p>
        <a href="<?php echo esc_url( RKP_LearningQueryService::get_dashboard_url( 'enrolled-courses' ) . $param ); ?>" class="rk-adv2__cta rk-adv2__cta--go">
            <?php esc_html_e( 'العودة إلى مغامراتي', 'rk-my-children' ); ?>
        </a>
    </div>
    <?php
    return;
}

$course = RKP_LearningQueryService::get_course( $course_id );
if ( ! $course || 'publish' !== $course->status ) {
    ?>
    <div class="rk-adv__notfound" dir="rtl">
        <p><?php esc_html_e( 'هذه المغامرة غير متاحة حاليًا.', 'rk-my-children' ); ?></p>
        <a href="<?php echo esc_url( RKP_LearningQueryService::get_dashboard_url( 'enrolled-courses' ) . $param ); ?>" class="rk-adv2__cta rk-adv2__cta--go">
            <?php esc_html_e( 'العودة إلى مغامراتي', 'rk-my-children' ); ?>
        </a>
    </div>
    <?php
    return;
}

// ── "si course book" : l'enfant doit être inscrit pour voir le détail. ──
$is_booked = $wp_uid ? RKP_LearningQueryService::is_enrolled( $course_id, $wp_uid ) : false;

$progress = ( $wp_uid && $is_booked ) ? RKP_LearningQueryService::get_progress( $course_id, $wp_uid ) : null;
$pct      = $progress ? $progress->percent : 0;

$category = RKP_LearningQueryService::get_primary_category( $course_id );
$topics   = RKP_LearningQueryService::get_topics( $course_id );
$quizzes  = RKP_LearningQueryService::get_quizzes_with_attempts( $course_id, $wp_uid );
$coach    = RKP_LearningQueryService::get_instructor( $course_id );

// Nombre total de leçons du cours — calculé une seule fois ici (somme des
// lessons_count de chaque topic), réutilisé dans "عن هذه المغامرة" et
// "تفاصيل المغامرة" pour éviter toute divergence entre les deux affichages.
$lessons_total_count = array_sum( array_map( static fn( $t ) => $t->lessons_count, $topics ) );

// ── Séance prochaine : uniquement si le cours est réservé (is_booked). ──
$next_booking = null;
if ( $is_booked && class_exists( 'RKP_BookingRepository' ) ) {
    $upcoming = RKP_BookingRepository::find_upcoming_by_child( $child_id, $course_id, 1 );
    $next_booking = ! empty( $upcoming ) ? reset( $upcoming ) : null;
}

// ── Prochaine leçon non verrouillée (première leçon incomplète, dans l'ordre). ──
$next_lesson_id = 0;
if ( $is_booked ) {
    foreach ( $topics as $topic ) {
        foreach ( RKP_LearningQueryService::get_lessons( $topic->id ) as $lesson ) {
            $done = $wp_uid ? RKP_LearningQueryService::is_lesson_completed( $lesson->id, $wp_uid ) : false;
            if ( ! $done ) { $next_lesson_id = $lesson->id; break 2; }
        }
    }
}
?>
<div class="rk-adv" dir="rtl">

    <!-- ═══════════════ HERO ═══════════════
         Image de couverture affichée SEULEMENT si le cours est réservé
         (is_booked) — sinon un aperçu neutre sans détails de session,
         conformément à "si course book afficher dans hero". -->
    <div class="rk-adv__hero" style="<?php echo $course->thumbnail ? 'background-image:url(' . esc_url( $course->thumbnail ) . ')' : ''; ?>">
        <div class="rk-adv__hero-overlay"></div>
        <div class="rk-adv__hero-content">
            <h1 class="rk-adv__hero-title"><?php echo esc_html( $course->title ); ?></h1>
            <?php if ( '' !== $category ) : ?>
            <div class="rk-adv__hero-cat">
                <?php echo wp_kses_post( rk_mc_svg( 'book', [] ) ); ?>
                <?php echo esc_html( $category ); ?>
            </div>
            <?php endif; ?>

            <?php if ( $is_booked && $next_booking ) : ?>
            <!-- اللقاء القادم — uniquement si une séance future existe (RKP_Booking::is_upcoming()) -->
            <div class="rk-adv__hero-next-session">
                <span class="rk-adv__hero-next-badge">
                    <?php echo wp_kses_post( rk_mc_svg( 'clock', [] ) ); ?>
                    <?php esc_html_e( 'اليوم', 'rk-my-children' ); ?>
                </span>
                <span class="rk-adv__hero-next-label"><?php esc_html_e( 'اللقاء القادم:', 'rk-my-children' ); ?></span>
                <span class="rk-adv__hero-next-name"><?php echo esc_html( $next_booking->session_name ); ?></span>
            </div>
            <?php endif; ?>

            <?php if ( $is_booked ) : ?>
            <a href="<?php
                $start_url = $next_lesson_id
                    ? get_permalink( $next_lesson_id )
                    : $course->permalink;
                echo esc_url( ( $start_url ?: $course->permalink ) . $param );
            ?>" class="rk-adv2__cta rk-adv2__cta--go rk-adv__hero-cta">
                <?php esc_html_e( 'ابدأ الدرس', 'rk-my-children' ); ?>
                <svg class="rk-adv2__cta-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" aria-hidden="true">
                    <polyline points="15 18 9 12 15 6"/>
                </svg>
            </a>
            <?php endif; ?>
        </div>
    </div>

    <div class="rk-adv__body">
        <div class="rk-adv__main">

            <!-- ═══════════════ عن هذه المغامرة ═══════════════ -->
            <section class="rk-adv__section">
                <h2 class="rk-adv__section-title"><?php esc_html_e( 'عن هذه المغامرة', 'rk-my-children' ); ?></h2>
                <p class="rk-adv__about-text"><?php echo wp_kses_post( wpautop( get_post_field( 'post_excerpt', $course_id ) ?: get_post_field( 'post_content', $course_id ) ) ); ?></p>

                <div class="rk-adv__meta-grid">
                    <div class="rk-adv__meta-card">
                        <span class="rk-adv__meta-icon"><?php echo wp_kses_post( rk_mc_svg( 'book-open', [] ) ); ?></span>
                        <span class="rk-adv__meta-text">
                            <?php printf(
                                /* translators: %d: lessons count */
                                esc_html__( '%d حصة، 35 دقيقة لكل حصة', 'rk-my-children' ),
                                (int) $lessons_total_count
                            ); ?>
                        </span>
                    </div>
                    <div class="rk-adv__meta-card">
                        <span class="rk-adv__meta-icon"><?php echo wp_kses_post( rk_mc_svg( 'award', [] ) ); ?></span>
                        <span class="rk-adv__meta-text"><?php esc_html_e( 'شهادة عند الإتمام', 'rk-my-children' ); ?></span>
                    </div>
                </div>
            </section>

            <!-- ═══════════════ الجلسات التفاعلية (topics → lessons) ═══════════════ -->
            <section class="rk-adv__section">
                <h2 class="rk-adv__section-title"><?php esc_html_e( 'الجلسات التفاعلية', 'rk-my-children' ); ?></h2>
                <ul class="rk-adv__lessons">
                    <?php foreach ( $topics as $topic ) :
                        foreach ( RKP_LearningQueryService::get_lessons( $topic->id ) as $lesson ) :
                            $lesson_done   = ( $is_booked && $wp_uid ) ? RKP_LearningQueryService::is_lesson_completed( $lesson->id, $wp_uid ) : false;
                            $lesson_active = $is_booked && ! $lesson_done && $lesson->id === $next_lesson_id;
                            $lesson_locked = ! $is_booked || ( ! $lesson_done && ! $lesson_active );
                    ?>
                    <li class="rk-adv__lesson <?php echo $lesson_done ? 'is-done' : ( $lesson_active ? 'is-active' : 'is-locked' ); ?>">
                        <span class="rk-adv__lesson-icon">
                            <?php if ( $lesson_done ) : ?>
                                <?php echo wp_kses_post( rk_mc_svg( 'check', [] ) ); ?>
                            <?php elseif ( $lesson_active ) : ?>
                                <?php echo wp_kses_post( rk_mc_svg( 'lesson-active', [] ) ); ?>
                            <?php elseif ( $lesson_locked ) : ?>
                                <?php echo wp_kses_post( rk_mc_svg( 'lock', [] ) ); ?>
                            <?php endif; ?>
                        </span>
                        <span class="rk-adv__lesson-title"><?php echo esc_html( $lesson->title ); ?></span>
                        <span class="rk-adv__lesson-duration">35 <?php esc_html_e( 'دقيقة', 'rk-my-children' ); ?></span>
                        <?php if ( $lesson_active ) : ?>
                        <a href="<?php echo esc_url( $lesson->permalink . $param ); ?>" class="rk-adv2__cta rk-adv2__cta--go rk-adv__lesson-cta">
                            <?php esc_html_e( 'ابدأ الدرس', 'rk-my-children' ); ?>
                            <svg class="rk-adv2__cta-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" aria-hidden="true">
                                <polyline points="15 18 9 12 15 6"/>
                            </svg>
                        </a>
                        <?php elseif ( ! $lesson_locked ) : ?>
                        <a href="<?php echo esc_url( $lesson->permalink . $param ); ?>" class="rk-adv__lesson-link" aria-label="<?php esc_attr_e( 'مراجعة الدرس', 'rk-my-children' ); ?>">
                            <?php echo wp_kses_post( rk_mc_svg( 'book-open', [] ) ); ?>
                        </a>
                        <?php endif; ?>
                    </li>
                    <?php
                        endforeach;
                    endforeach;
                    ?>
                </ul>
            </section>

            <!-- ═══════════════ الاختبارات — quiz Tutor natifs + AYS Quiz Maker
                 liés au cours (voir LearningQueryService::get_quizzes_with_attempts()).
                 La section reste TOUJOURS visible (titre systématique) : un
                 état vide dédié remplace la liste si aucun quiz n'existe
                 encore, plutôt que de faire disparaître toute la section
                 (comportement précédent, corrigé). ═══════════════ -->
            <section class="rk-adv__section">
                <h2 class="rk-adv__section-title"><?php esc_html_e( 'الاختبارات', 'rk-my-children' ); ?></h2>
                <?php if ( ! empty( $quizzes ) ) : ?>
                <ul class="rk-adv__lessons">
                    <?php foreach ( $quizzes as $quiz ) :
                        $quiz_locked = ! $is_booked;
                    ?>
                    <li class="rk-adv__lesson <?php echo $quiz['attempted'] ? 'is-done' : ( $quiz_locked ? 'is-locked' : 'is-active' ); ?>">
                        <span class="rk-adv__lesson-icon">
                            <?php if ( $quiz['attempted'] ) : ?>
                                <?php echo wp_kses_post( rk_mc_svg( 'check', [] ) ); ?>
                            <?php elseif ( $quiz_locked ) : ?>
                                <?php echo wp_kses_post( rk_mc_svg( 'lock', [] ) ); ?>
                            <?php else : ?>
                                <?php echo wp_kses_post( rk_mc_svg( 'lesson-active', [] ) ); ?>
                            <?php endif; ?>
                        </span>
                        <span class="rk-adv__lesson-title"><?php echo esc_html( $quiz['title'] ); ?></span>
                        <?php if ( $quiz['attempted'] ) : ?>
                        <span class="rk-adv__lesson-duration"><?php echo (int) $quiz['best_pct']; ?>%</span>
                        <?php elseif ( ! $quiz_locked ) : ?>
                        <a href="<?php echo esc_url( $quiz['url'] . $param ); ?>" class="rk-adv2__cta rk-adv2__cta--go rk-adv__lesson-cta">
                            <?php esc_html_e( 'اختبر نفسك', 'rk-my-children' ); ?>
                            <svg class="rk-adv2__cta-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" aria-hidden="true">
                                <polyline points="15 18 9 12 15 6"/>
                            </svg>
                        </a>
                        <?php endif; ?>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php else : ?>
                <div class="rk-adv__quiz-empty">
                    <?php echo wp_kses_post( rk_mc_svg( 'book-open', [ 'class' => 'rk-adv__quiz-empty-ico' ] ) ); ?>
                    <p class="rk-adv__quiz-empty-text">
                        <?php echo esc_html(
                            $is_booked
                                ? __( 'لا توجد اختبارات بعد لهذه المغامرة. سيقوم مدربك بإضافتها قريبًا!', 'rk-my-children' )
                                : __( 'ستظهر الاختبارات هنا بعد الالتحاق بهذه المغامرة.', 'rk-my-children' )
                        ); ?>
                    </p>
                </div>
                <?php endif; ?>
            </section>

        </div>

        <!-- ═══════════════ SIDEBAR ═══════════════ -->
        <aside class="rk-adv__sidebar">

            <!-- تفاصيل المغامرة -->
            <div class="rk-adv__sidebar-card">
                <h3 class="rk-adv__sidebar-title"><?php esc_html_e( 'تفاصيل المغامرة', 'rk-my-children' ); ?></h3>
                <div class="rk-adv__detail-row">
                    <span class="rk-adv__detail-icon">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                            <circle cx="12" cy="7" r="4"/>
                        </svg>
                    </span>
                    <span><?php esc_html_e( 'جميع المستويات', 'rk-my-children' ); ?></span>
                </div>
                <div class="rk-adv__detail-row">
                    <?php echo wp_kses_post( rk_mc_svg( 'book-open', [] ) ); ?>
                    <span>
                        <?php printf(
                            /* translators: 1: lessons count */
                            esc_html__( '%1$d حصص، 35 دقيقة لكل حصة', 'rk-my-children' ),
                            (int) $lessons_total_count
                        ); ?>
                    </span>
                </div>
                <div class="rk-adv__detail-row">
                    <?php echo wp_kses_post( rk_mc_svg( 'award', [] ) ); ?>
                    <span><?php esc_html_e( 'شهادة عند الإتمام', 'rk-my-children' ); ?></span>
                </div>
            </div>

            <!-- المدربة — carte coach affichée SEULEMENT si le cours est réservé -->
            <?php if ( $is_booked && $coach ) : ?>
            <div class="rk-adv__sidebar-card">
                <h3 class="rk-adv__sidebar-title"><?php esc_html_e( 'المدربة', 'rk-my-children' ); ?></h3>
                <div class="rk-adv__coach">
                    <img class="rk-adv__coach-avatar"
                         src="<?php echo esc_url( get_avatar_url( $coach->ID, [ 'size' => 96 ] ) ); ?>"
                         alt="" width="48" height="48" loading="lazy">
                    <div class="rk-adv__coach-info">
                        <span class="rk-adv__coach-name"><?php echo esc_html( $coach->display_name ); ?></span>
                        <span class="rk-adv__coach-role"><?php echo esc_html( '' !== $category ? $category : __( 'مدرب/ة رفيق', 'rk-my-children' ) ); ?></span>
                    </div>
                </div>
                <?php
                // Même précaution que pour $adventure_url dans
                // class-rk-mc-adventures-filter-service.php : $param
                // commence déjà par '?', donc on fusionne proprement les
                // paramètres via add_query_arg() plutôt que de concaténer.
                $msg_extra_args = [ 'coach' => $coach->ID ];
                if ( '' !== $param ) {
                    wp_parse_str( ltrim( $param, '?' ), $msg_parsed );
                    $msg_extra_args = array_merge( $msg_parsed, $msg_extra_args );
                }
                $msg_url = class_exists( 'RKP_LearningQueryService' )
                    ? add_query_arg( $msg_extra_args, RKP_LearningQueryService::get_dashboard_url( 'rk-messages' ) )
                    : '#';
                ?>
                <a href="<?php echo esc_url( $msg_url ); ?>" class="rk-adv__coach-msg-btn">
                    <?php esc_html_e( 'أرسل رسالة', 'rk-my-children' ); ?>
                </a>
            </div>
            <?php endif; ?>

        </aside>
    </div>
</div>
