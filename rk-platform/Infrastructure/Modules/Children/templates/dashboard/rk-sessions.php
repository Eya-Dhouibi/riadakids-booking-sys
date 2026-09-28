<?php
declare( strict_types=1 );
/**
 * RiadaKids — Tutor Dashboard: صفحة "لقاءاتي" (My Sessions)
 *
 * v2.0 — Refonte complète : passage d'une simple liste plate à un
 * layout "maître-détail" (liste des séances + panneau détail de la
 * séance sélectionnée), conforme à la maquette fournie. L'ancienne
 * version utilisait des classes CSS (.rk-session-history-row,
 * .rk-kpi, .rk-filters-bar...) qui n'étaient stylées nulle part dans
 * rk-dashboard-v4.css (le seul fichier CSS réellement chargé sur
 * cette page) — uniquement dans un SCSS non compilé/non chargé.
 *
 * Données via RKP_BookingQueryService (Application) →
 * RKP_BookingRepository (Infrastructure) uniquement — aucun SQL brut
 * ici, cohérent avec la discipline en couches du reste du module.
 *
 * @package RK_My_Children
 * @since   9.6.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$child = RK_MC_Tutor_Dashboard::get_child_for_template();
if ( ! $child ) {
    echo '<p>' . esc_html__( 'يرجى تحديد الطفل أولاً.', 'rk-my-children' ) . '</p>';
    return;
}
$child_id = (int) $child->id;
$param    = RK_MC_Tutor_Dashboard::child_param();

// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- lecture simple d'un filtre d'affichage, pas d'action sensible.
$filter = sanitize_key( $_GET['when'] ?? 'upcoming' );
if ( ! in_array( $filter, [ 'upcoming', 'past' ], true ) ) {
    $filter = 'upcoming';
}

$bookings = class_exists( 'RKP_BookingQueryService' )
    ? RKP_BookingQueryService::get_all_for_child( $child_id, $filter )
    : [];

$base_url = home_url( RK_TUTOR_DASHBOARD_URL . 'rk-sessions/' ) . $param;

/**
 * Jour affiché sur la pastille de la liste — "اليوم" si aujourd'hui,
 * sinon le nom du jour de la semaine (conforme à la maquette : "اليوم",
 * "الخميس", "الاثنين"...).
 */
$day_label = static function ( \DateTimeImmutable $dt ): string {
    $today = new \DateTimeImmutable( 'today', wp_timezone() );
    if ( $dt->format( 'Y-m-d' ) === $today->format( 'Y-m-d' ) ) {
        return __( 'اليوم', 'rk-my-children' );
    }
    $days = [
        'Sunday'    => 'الأحد',
        'Monday'    => 'الاثنين',
        'Tuesday'   => 'الثلاثاء',
        'Wednesday' => 'الأربعاء',
        'Thursday'  => 'الخميس',
        'Friday'    => 'الجمعة',
        'Saturday'  => 'السبت',
    ];
    return $days[ $dt->format( 'l' ) ] ?? $dt->format( 'l' );
};

/**
 * Rend le panneau détail d'une séance donnée. Fonction locale (pas une
 * méthode de classe) : ce template n'est inclus qu'une fois par
 * requête normalement, mais on protège quand même par function_exists()
 * par précaution (même discipline défensive qu'ailleurs dans ce module).
 */
if ( ! function_exists( 'rk_sess_render_detail' ) ) :
function rk_sess_render_detail( RKP_Booking $booking, string $param, callable $day_label ): void {
    $course  = class_exists( 'RKP_LearningQueryService' ) ? RKP_LearningQueryService::get_course( $booking->course_id ) : null;
    $coach   = ( $booking->coach_id && class_exists( 'RKP_UserRepository' ) ) ? RKP_UserRepository::find_coach( $booking->coach_id ) : null;
    $is_past = $booking->start_at < new \DateTimeImmutable( 'now', wp_timezone() );
    ?>
    <?php if ( $course && $course->thumbnail ) : ?>
    <img class="rk-sess__detail-img" src="<?php echo esc_url( $course->thumbnail ); ?>" alt="" loading="lazy">
    <?php endif; ?>

    <?php if ( $course && '' !== $course->category ) : ?>
    <div class="rk-sess__detail-cat">
        <?php echo wp_kses_post( rk_mc_svg( 'book', [] ) ); ?>
        <?php echo esc_html( $course->category ); ?>
    </div>
    <?php endif; ?>

    <h2 class="rk-sess__detail-title"><?php echo esc_html( $booking->session_name ?: ( $course->title ?? '' ) ); ?></h2>

    <?php if ( $coach ) : ?>
    <div class="rk-sess__detail-coach">
        <img class="rk-sess__detail-coach-avatar" src="<?php echo esc_url( get_avatar_url( $coach->ID, [ 'size' => 64 ] ) ); ?>" alt="" width="32" height="32" loading="lazy">
        <span class="rk-sess__detail-coach-name">
            <?php
            printf(
                /* translators: %s: coach display name */
                esc_html__( 'المدربة %s', 'rk-my-children' ),
                esc_html( $coach->display_name )
            );
            ?>
        </span>
    </div>
    <?php endif; ?>

    <div class="rk-sess__detail-datetime">
        <?php echo wp_kses_post( rk_mc_svg( 'calendar', [] ) ); ?>
        <span>
            <?php echo esc_html( $day_label( $booking->start_at ) ); ?>،
            <?php esc_html_e( 'الساعة', 'rk-my-children' ); ?>
            <?php echo esc_html( wp_date( 'g:i a', $booking->start_at->getTimestamp(), wp_timezone() ) ); ?>
        </span>
    </div>

    <?php if ( $is_past ) : ?>
    <div class="rk-sess__detail-cta rk-sess__detail-cta--done">
        <?php echo wp_kses_post( rk_mc_svg( 'check', [] ) ); ?>
        <?php esc_html_e( 'انتهت', 'rk-my-children' ); ?>
    </div>
    <?php elseif ( $booking->meeting_url ) : ?>
    <a href="<?php echo esc_url( $booking->meeting_url ); ?>" target="_blank" rel="noopener noreferrer" class="rk-adv2__cta rk-adv2__cta--go rk-sess__detail-cta">
        <?php esc_html_e( 'انضم الآن', 'rk-my-children' ); ?>
        <svg class="rk-adv2__cta-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" aria-hidden="true">
            <polyline points="15 18 9 12 15 6"/>
        </svg>
    </a>
    <?php endif; ?>
    <?php
}
endif;
?>
<div class="rk-sess" dir="rtl" data-rk-sessions>

    <div class="rk-sess__header">
        <h1 class="rk-sess__title">
            <?php echo wp_kses_post( rk_mc_svg( 'calendar', [ 'class' => 'rk-sess__title-icon' ] ) ); ?>
            <?php esc_html_e( 'لقاءاتي', 'rk-my-children' ); ?>
        </h1>
        <div class="rk-sess__filters">
            <a href="<?php echo esc_url( add_query_arg( 'when', 'past', $base_url ) ); ?>"
               class="rk-sess__filter-btn <?php echo 'past' === $filter ? 'is-active' : ''; ?>">
                <?php esc_html_e( 'السابقة', 'rk-my-children' ); ?>
            </a>
            <a href="<?php echo esc_url( add_query_arg( 'when', 'upcoming', $base_url ) ); ?>"
               class="rk-sess__filter-btn rk-sess__filter-btn--primary <?php echo 'upcoming' === $filter ? 'is-active' : ''; ?>">
                <?php esc_html_e( 'القادمة', 'rk-my-children' ); ?>
            </a>
        </div>
    </div>

    <?php if ( empty( $bookings ) ) : ?>

    <div class="rk4-empty">
        <div class="rk4-deco-layer" aria-hidden="true">
            <img src="<?php echo esc_url( RK_MC_URL . 'assets/img/illustrations/deco-cloud.png' ); ?>" alt="" style="width:60px;top:10%;inset-inline-start:10%;" loading="lazy">
            <img src="<?php echo esc_url( RK_MC_URL . 'assets/img/illustrations/deco-star.png' ); ?>" alt="" style="width:24px;top:65%;inset-inline-end:12%;" loading="lazy">
        </div>
        <img src="<?php echo esc_url( RK_MC_URL . 'assets/img/illustrations/empty-course.webp' ); ?>"
             alt="" class="rk4-empty__illustration" data-rk-float loading="lazy" width="180" height="180">
        <h2 class="rk4-empty__title">
            <?php echo esc_html(
                'upcoming' === $filter
                    ? __( 'لا توجد لقاءات قادمة حاليًا', 'rk-my-children' )
                    : __( 'لا توجد لقاءات سابقة بعد', 'rk-my-children' )
            ); ?>
        </h2>
        <p class="rk4-empty__text">
            <?php echo esc_html(
                'upcoming' === $filter
                    ? __( 'انضم إلى مغامرة جديدة لحجز أول لقاء مع مدربك!', 'rk-my-children' )
                    : __( 'لقاءاتك المكتملة ستظهر هنا بعد أول جلسة.', 'rk-my-children' )
            ); ?>
        </p>
        <?php if ( class_exists( 'RKP_LearningQueryService' ) ) : ?>
        <a href="<?php echo esc_url( RKP_LearningQueryService::get_dashboard_url( 'enrolled-courses' ) . $param ); ?>" class="rk4-empty__cta" data-rk-btn-pop>
            <?php esc_html_e( 'استكشف مغامراتك', 'rk-my-children' ); ?>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" aria-hidden="true"><polyline points="15 18 9 12 15 6"/></svg>
        </a>
        <?php endif; ?>
    </div>

    <?php else : ?>

    <div class="rk-sess__body">

        <!-- ═══════════════ Liste des séances (colonne droite dans la
             maquette) ═══════════════ -->
        <ul class="rk-sess__list" data-rk-sess-list data-rk-stagger>
            <?php foreach ( $bookings as $i => $booking ) :
                $is_past = $booking->start_at < new \DateTimeImmutable( 'now', wp_timezone() );
            ?>
            <li class="rk-sess__item <?php echo 0 === $i ? 'is-selected' : ''; ?>"
                data-rk-sess-item
                data-index="<?php echo (int) $i; ?>"
                tabindex="0"
                role="button">
                <span class="rk-sess__item-title"><?php echo esc_html( $booking->session_name ); ?></span>
                <?php if ( $is_past ) : ?>
                <span class="rk-sess__item-badge rk-sess__item-badge--done">
                    <?php echo wp_kses_post( rk_mc_svg( 'check', [] ) ); ?>
                    <?php esc_html_e( 'انتهت', 'rk-my-children' ); ?>
                </span>
                <?php else : ?>
                <span class="rk-sess__item-badge rk-sess__item-badge--upcoming">
                    <?php echo esc_html( $day_label( $booking->start_at ) ); ?><br>
                    <?php echo esc_html( wp_date( 'g A', $booking->start_at->getTimestamp(), wp_timezone() ) ); ?>
                </span>
                <?php endif; ?>
            </li>
            <?php endforeach; ?>
        </ul>
        
                <!-- ═══════════════ Panneau détail — rempli en JS au clic sur un
             item de la liste (data-rk-sess-detail), pré-rempli ici pour
             la première séance afin de fonctionner sans JS au premier
             rendu (progressive enhancement). ═══════════════ -->
        <div class="rk-sess__detail" data-rk-sess-detail>
            <?php rk_sess_render_detail( $bookings[0], $param, $day_label ); ?>
        </div>

        <!-- Données JSON des séances, pour la sélection JS côté client
             sans rechargement de page (voir assets/js/rk-sessions.js). -->
        <script type="application/json" id="rk-sess-data">
            <?php
            $sessions_json = array_map( static function ( RKP_Booking $b ) use ( $day_label ) {
                $course  = class_exists( 'RKP_LearningQueryService' ) ? RKP_LearningQueryService::get_course( $b->course_id ) : null;
                $coach   = ( $b->coach_id && class_exists( 'RKP_UserRepository' ) ) ? RKP_UserRepository::find_coach( $b->coach_id ) : null;
                $is_past = $b->start_at < new \DateTimeImmutable( 'now', wp_timezone() );
                return [
                    'title'       => $b->session_name ?: ( $course->title ?? '' ),
                    'thumbnail'   => $course->thumbnail ?? '',
                    'category'    => $course->category ?? '',
                    'coachName'   => $coach ? $coach->display_name : '',
                    'coachAvatar' => $coach ? get_avatar_url( $coach->ID, [ 'size' => 64 ] ) : '',
                    'dayLabel'    => $day_label( $b->start_at ),
                    'time'        => wp_date( 'g:i a', $b->start_at->getTimestamp(), wp_timezone() ),
                    'isPast'      => $is_past,
                    'meetingUrl'  => $is_past ? '' : $b->meeting_url,
                ];
            }, $bookings );
            echo wp_json_encode( $sessions_json );
            ?>
        </script>

    </div>

    <?php endif; ?>

</div>
