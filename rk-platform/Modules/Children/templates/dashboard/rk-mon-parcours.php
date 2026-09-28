<?php
declare( strict_types=1 );
/**
 * RiadaKids — Tutor Dashboard Sub-Page: مساري (child journey view)
 *
 * RÈGLE ARCHITECTURE : ce template ne connaît que $snapshot.
 * Aucun appel à un service, aucun accès direct à la base de données.
 * load_data() dans RK_MC_Tutor_Dashboard a déjà appelé getJourney() une seule fois.
 *
 * v2.5 (Audit P4) : tous les emojis remplacés par des icônes SVG professionnelles
 *                    via le helper rk_mp_icon(). Lien "اختباراتي" renommé
 *                    "تحدياتي" et pointé vers /dashboard/rk-challenges/.
 *
 * @package RK_My_Children
 * @since   9.0.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Petite bibliothèque d'icônes SVG inline (stroke-based, style outline cohérent).
 * Remplace l'ancien usage d'emojis dans tout le template.
 *
 * @param string $name  Clé de l'icône.
 * @param string $class Classes CSS additionnelles.
 * @return string SVG markup (déjà sûr, aucune donnée utilisateur injectée).
 */
function rk_mp_icon( string $name, string $class = '' ): string {
    $common = 'class="rk-mp-icon ' . esc_attr( $class ) . '" width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"';

    $paths = [
        // Niveaux
        'spark'      => '<path d="M13 2 4 14h6l-1 8 9-12h-6l1-8Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" stroke-linecap="round"/>',
        'seedling'   => '<path d="M12 21V12M12 12C12 8 9 5 4 5c0 5 3 8 8 8Zm0 0c0-4.5 3-8 8-8 0 5.5-3.5 9-8 8Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" stroke-linecap="round"/>',
        'rocket'     => '<path d="M12 2.5c2.8 1.2 4.8 4 5.3 8.2.3 2.6-.5 5.3-2.3 7.3l-1.4-1.4c1.3-1.6 1.9-3.6 1.7-5.6-.4-3.3-2-5.5-3.9-6.6-1.9 1.1-3.5 3.3-3.9 6.6-.2 2 .4 4 1.7 5.6l-1.4 1.4c-1.8-2-2.6-4.7-2.3-7.3.5-4.2 2.5-7 5.3-8.2Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><circle cx="12" cy="9.5" r="1.6" stroke="currentColor" stroke-width="1.6"/><path d="M9 17.5c-1.5.5-2.3 1.8-2.5 4 2.2-.2 3.5-1 4-2.5M15 17.5c1.5.5 2.3 1.8 2.5 4-2.2-.2-3.5-1-4-2.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>',
        'fire'       => '<path d="M12 22c4.4 0 7-2.8 7-6.8 0-3.2-1.9-5-3.1-6.9-.3.9-1 2.2-2 2.9.2-2.7-.6-6-3.2-8.2C11 6 8 8 8 12c-1.1-.6-1.7-1.8-1.8-3.1C4.8 10.6 5 13.5 5 15.2 5 19.2 7.6 22 12 22Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/>',
        'star'       => '<path d="m12 3 2.6 5.6 6.1.6-4.6 4.1 1.3 6-5.4-3.1-5.4 3.1 1.3-6-4.6-4.1 6.1-.6L12 3Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/>',
        'trophy'     => '<path d="M8 4h8v4a4 4 0 0 1-8 0V4Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><path d="M8 5H5a1 1 0 0 0-1 1c0 2.5 1.6 4 4 4.3M16 5h3a1 1 0 0 1 1 1c0 2.5-1.6 4-4 4.3" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><path d="M12 12v3m-3 5h6m-3 0v-2m-3 2c0-1.5.6-2 3-2s3 .5 3 2" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>',
        'diamond'    => '<path d="M4 9 8 3h8l4 6-9.5 12L4 9Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><path d="M4 9h16M9.5 3 8 9l4.5 12M14.5 3 16 9l-4.5 12" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/>',
        'crown'      => '<path d="m3 8 4 3 5-6 5 6 4-3-2 10H5L3 8Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><path d="M5 21h14" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>',
        // CTA / cartes
        'book'       => '<path d="M4 5.5A2.5 2.5 0 0 1 6.5 3H12v18H6.5A2.5 2.5 0 0 1 4 18.5v-13Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><path d="M20 5.5A2.5 2.5 0 0 0 17.5 3H12v18h5.5a2.5 2.5 0 0 0 2.5-2.5v-13Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/>',
        'quiz'       => '<circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.8"/><path d="M9.5 9.3a2.5 2.5 0 0 1 4.9.7c0 1.7-2.4 1.7-2.4 3.3" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/><circle cx="12" cy="16.7" r="0.9" fill="currentColor"/>',
        'calendar'   => '<rect x="4" y="5" width="16" height="15" rx="2" stroke="currentColor" stroke-width="1.8"/><path d="M8 3v4M16 3v4M4 9.5h16" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>',
        'target'     => '<circle cx="12" cy="12" r="8.5" stroke="currentColor" stroke-width="1.8"/><circle cx="12" cy="12" r="5" stroke="currentColor" stroke-width="1.8"/><circle cx="12" cy="12" r="1.5" fill="currentColor"/>',
        'sparkles'   => '<path d="M12 3v4M12 17v4M4.5 12h4M15.5 12h4M6.5 6.5l2.8 2.8M14.7 14.7l2.8 2.8M17.5 6.5l-2.8 2.8M9.3 14.7l-2.8 2.8" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>',
        // Sections
        'map'        => '<path d="M9 4 3 6v14l6-2 6 2 6-2V4l-6 2-6-2Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><path d="M9 4v14M15 6v14" stroke="currentColor" stroke-width="1.8"/>',
        'books'      => '<path d="M5 3.5h3v17H5a1 1 0 0 1-1-1v-15a1 1 0 0 1 1-1Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><path d="M11 3.5h3v17h-3a1 1 0 0 1-1-1v-15a1 1 0 0 1 1-1Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><path d="m17.3 4.4 2.8.7a1 1 0 0 1 .7 1.2l-3.4 14.6a1 1 0 0 1-1.2.7l-2.8-.7" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/>',
        'teacher'    => '<circle cx="9" cy="8" r="3.2" stroke="currentColor" stroke-width="1.8"/><path d="M3.5 20c.7-3.4 2.9-5.2 5.5-5.2S13.8 16.6 14.5 20" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/><path d="M15 9.5c1-.3 1.7-1.2 1.7-2.5A2.6 2.6 0 0 0 14.5 4.4M17.5 20c-.4-2.1-1.3-3.6-2.6-4.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>',
        'message'    => '<path d="M4 6.5A2.5 2.5 0 0 1 6.5 4h11A2.5 2.5 0 0 1 20 6.5v8A2.5 2.5 0 0 1 17.5 17H10l-4.5 3.5V17h-1A2.5 2.5 0 0 1 4 14.5v-8Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/>',
        // Divers
        'wave'       => '<path d="M4 14c1-2 3-2 4 0s3 2 4 0 3-2 4 0 3 2 4 0" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><path d="M4 19c1-2 3-2 4 0s3 2 4 0 3-2 4 0 3 2 4 0" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>',
        'flag'       => '<path d="M6 21V4m0 1.5 12-2v10l-12 2" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/>',
    ];

    if ( ! isset( $paths[ $name ] ) ) return '';
    return '<svg ' . $common . ' aria-hidden="true" focusable="false">' . $paths[ $name ] . '</svg>';
}

$child    = RK_MC_Tutor_Dashboard::get_child_for_template();
$snapshot = RK_MC_Tutor_Dashboard::get_snapshot();
$cp       = RK_MC_Tutor_Dashboard::child_param();
$base     = home_url( RK_TUTOR_DASHBOARD_URL );
$back_url = esc_url( $base . $cp );

if ( ! $child ) {
    echo '<p dir="rtl">' . esc_html__( 'يرجى تحديد الطفل أولاً.', 'rk-my-children' ) . '</p>';
    return;
}

// ── Valeurs depuis le snapshot ─────────────────────────────────────────────
$has_snap    = $snapshot instanceof RKP_JourneySnapshot;
$xp          = $has_snap ? $snapshot->xp    : 0;
$level_num   = $has_snap ? $snapshot->level : 1;
$streak      = $has_snap ? $snapshot->streak : 0;
$pct         = $has_snap ? $snapshot->progress_percent() : 0;
$next_action = $has_snap ? $snapshot->next_action : 'idle';

$course_title   = $has_snap && $snapshot->course  ? esc_html( $snapshot->course->title ?? '' )  : '';
$topic_title    = $has_snap && $snapshot->topic   ? esc_html( $snapshot->topic->title ?? '' )   : '';
$current_lesson = $has_snap ? $snapshot->current_lesson : null;
$next_lesson    = $has_snap ? $snapshot->next_lesson    : null;
$booking        = $has_snap ? $snapshot->upcoming_booking : null;
$coach_name     = $has_snap ? esc_html( $snapshot->coach_name ) : '';

// ── Labels niveau (emoji → clé d'icône SVG) ────────────────────────────────
$level_labels = [
    1 => [ 'label' => 'شرارة',      'icon' => 'spark',    'color' => '#94a3b8' ],
    2 => [ 'label' => 'مبتدئ',      'icon' => 'seedling', 'color' => '#22c55e' ],
    3 => [ 'label' => 'مجتهد',      'icon' => 'rocket',   'color' => '#3b82f6' ],
    4 => [ 'label' => 'متقدم',      'icon' => 'fire',     'color' => '#f59e0b' ],
    5 => [ 'label' => 'نجم',        'icon' => 'star',     'color' => '#f97316' ],
    6 => [ 'label' => 'بطل',        'icon' => 'trophy',   'color' => '#e11d48' ],
    7 => [ 'label' => 'أسطورة',     'icon' => 'diamond',  'color' => '#7c3aed' ],
    8 => [ 'label' => 'لا يُهزم',   'icon' => 'crown',    'color' => '#fbbf24' ],
];
$lvl_info    = $level_labels[ $level_num ] ?? $level_labels[1];
$lvl_icon    = $lvl_info['icon'];
$lvl_label   = $lvl_info['label'];
$lvl_color   = $lvl_info['color'];

// ── Action principale ──────────────────────────────────────────────────────
$cta_label = '';
$cta_url   = '#';
$cta_icon  = '';
switch ( $next_action ) {
    case 'continue_lesson':
        $cta_label = 'تابع درسك';
        $cta_icon  = 'book';
        // v2.4 (Audit P3-10) : plus de tutor_utils() — le Domain object porte son permalink.
        $cta_url   = $next_lesson ? esc_url( $next_lesson->permalink ) : '#';
        break;
    case 'take_quiz':
        $cta_label = 'اجتز الاختبار';
        $cta_icon  = 'quiz';
        $cta_url   = $base . 'quiz-attempts/' . $cp;
        break;
    case 'attend_session': // v2.1 — une session est déjà réservée : on y va !
        $cta_label = 'جلستك القادمة';
        $cta_icon  = 'target';
        $cta_url   = $base . 'rk-sessions/' . $cp;
        break;
    case 'book_session': // v2.1 — sémantique corrigée : AUCUNE session réservée → réserver
        $cta_label = 'احجز جلسة';
        $cta_icon  = 'calendar';
        $cta_url   = $base . 'rk-sessions/' . $cp;
        break;
    default:
        $cta_label = 'اكتشف دروسك';
        $cta_icon  = 'sparkles';
        $cta_url   = $base . 'enrolled-courses/' . $cp;
}

// ── Étapes du rail (issues du cours actif uniquement) ─────────────────────
$stages = [];
if ( $has_snap && $snapshot->course ) {
    // v2.4 (Audit P3-10) : topics via le Learning Engine — plus de tutor_utils().
    foreach ( RKP_LearningQueryService::get_topics( $snapshot->course->id ) as $t ) {
        $topic_id   = (int) $t->id;
        $is_current = $snapshot->topic && $snapshot->topic->id === $topic_id;
        $is_done    = ! $is_current && $pct > 0; // simplified: topics before current are done
        $stages[]   = [
            'title'  => esc_html( $t->title ),
            'status' => $is_current ? 'active' : ( $is_done ? 'done' : 'pending' ),
        ];
    }
}
// Si pas de topics disponibles, créer des étapes génériques
if ( empty( $stages ) && $has_snap ) {
    $stages = [
        [ 'title' => 'البداية',        'status' => $pct > 0  ? 'done'   : 'active'  ],
        [ 'title' => $topic_title ?: 'الوحدة الحالية', 'status' => 'active' ],
        [ 'title' => 'الامتحان النهائي', 'status' => 'pending' ],
    ];
}
?>
<div class="rk-mp-wrap" dir="rtl">

    <!-- Bouton retour -->
    <a href="<?php echo $back_url; ?>" class="rk-mp-back">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>
        العودة للرئيسية
    </a>

    <!-- Hero card -->
    <div class="rk-mp-hero" style="--lvl-color:<?php echo esc_attr( $lvl_color ); ?>">
        <div class="rk-mp-hero__avatar">
            <span class="rk-mp-hero__avatar-lvl" style="background:<?php echo esc_attr( $lvl_color ); ?>"><?php echo esc_html( $level_num ); ?></span>
            <?php echo rk_mp_icon( $lvl_icon, 'rk-mp-icon--xl' ); ?>
        </div>
        <div class="rk-mp-hero__info">
            <div class="rk-mp-hero__name">
                <?php echo esc_html( $child->first_name ?? $child->child_name ?? '' ); ?>
                <?php echo rk_mp_icon( 'wave', 'rk-mp-icon--inline' ); ?>
            </div>
            <div class="rk-mp-hero__level">
                <?php echo rk_mp_icon( $lvl_icon, 'rk-mp-icon--inline' ); ?>
                <?php echo esc_html( $lvl_label ); ?> — المستوى <?php echo (int) $level_num; ?>
            </div>
            <div class="rk-mp-xp-bar">
                <div class="rk-mp-xp-bar__fill" style="width:<?php echo min( 100, $pct ); ?>%"></div>
            </div>
            <div class="rk-mp-hero__xp">
                <?php echo number_format( $xp ); ?> XP
                <?php echo rk_mp_icon( 'spark', 'rk-mp-icon--inline' ); ?>
            </div>
        </div>
        <div class="rk-mp-hero__chips">
            <?php if ( $streak > 0 ): ?>
            <span class="rk-mp-chip rk-mp-chip--fire"><?php echo rk_mp_icon( 'fire', 'rk-mp-icon--chip' ); ?> <?php echo (int) $streak; ?> يوم</span>
            <?php endif; ?>
            <span class="rk-mp-chip rk-mp-chip--pct"><?php echo $pct; ?>% مكتمل</span>
            <?php if ( $coach_name ): ?>
            <span class="rk-mp-chip rk-mp-chip--coach"><?php echo rk_mp_icon( 'teacher', 'rk-mp-icon--chip' ); ?> <?php echo $coach_name; ?></span>
            <?php endif; ?>
        </div>
    </div>

    <!-- CTA principal -->
    <a href="<?php echo esc_url( $cta_url ); ?>" class="rk-mp-cta">
        <?php echo rk_mp_icon( $cta_icon, 'rk-mp-icon--cta' ); ?>
        <?php echo esc_html( $cta_label ); ?>
    </a>

    <!-- Layout deux colonnes -->
    <div class="rk-mp-layout">

        <!-- Colonne gauche : rail de progression -->
        <div class="rk-mp-col rk-mp-col--rail">
            <h3 class="rk-mp-section-title">
                <?php echo rk_mp_icon( 'map', 'rk-mp-icon--inline' ); ?>
                خريطة مساري
            </h3>
            <?php if ( $course_title ): ?>
            <p class="rk-mp-course-name">
                <?php echo rk_mp_icon( 'books', 'rk-mp-icon--inline' ); ?>
                <?php echo $course_title; ?>
            </p>
            <?php endif; ?>

            <?php if ( ! $has_snap ): ?>
            <div class="rk-mp-empty">
                <span class="rk-mp-empty__icon"><?php echo rk_mp_icon( 'rocket', 'rk-mp-icon--empty' ); ?></span>
                <p>لم تبدأ مغامرتك بعد!</p>
                <a href="<?php echo esc_url( $base . 'enrolled-courses/' . $cp ); ?>" class="rk-mp-link">
                    <?php echo rk_mp_icon( 'sparkles', 'rk-mp-icon--inline' ); ?>
                    ابدأ الآن
                </a>
            </div>
            <?php else: ?>
            <div class="rk-mp-rail">
                <?php foreach ( $stages as $i => $stage ):
                    $cls = 'rk-mp-rail__stage rk-mp-rail__stage--' . esc_attr( $stage['status'] );
                    $dot_inner = match( $stage['status'] ) {
                        'done'   => '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg>',
                        'active' => '<svg width="10" height="10" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><polygon points="6 4 20 12 6 20 6 4"/></svg>',
                        default  => (string) ( $i + 1 ),
                    };
                ?>
                <div class="<?php echo $cls; ?>">
                    <div class="rk-mp-rail__dot"><?php echo $dot_inner; ?></div>
                    <div class="rk-mp-rail__label"><?php echo $stage['title']; ?></div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- Colonne droite : détails -->
        <div class="rk-mp-col rk-mp-col--detail">

            <?php if ( $current_lesson ): ?>
            <div class="rk-mp-card">
                <div class="rk-mp-card__icon"><?php echo rk_mp_icon( 'book', 'rk-mp-icon--card' ); ?></div>
                <div>
                    <div class="rk-mp-card__label">آخر درس أكملته</div>
                    <div class="rk-mp-card__val"><?php echo esc_html( $current_lesson->title ?? '' ); ?></div>
                </div>
            </div>
            <?php endif; ?>

            <?php if ( $next_lesson ): ?>
            <div class="rk-mp-card rk-mp-card--highlight">
                <div class="rk-mp-card__icon"><?php echo rk_mp_icon( 'rocket', 'rk-mp-icon--card' ); ?></div>
                <div>
                    <div class="rk-mp-card__label">درسك القادم</div>
                    <div class="rk-mp-card__val"><?php echo esc_html( $next_lesson->title ?? '' ); ?></div>
                </div>
                <?php if ( $next_lesson && ! empty( $next_lesson->permalink ) ): // v2.4 — permalink du Domain object ?>
                <a href="<?php echo esc_url( $next_lesson->permalink ); ?>" class="rk-mp-card__btn">
                    <?php echo rk_mp_icon( 'rocket', 'rk-mp-icon--btn' ); ?>
                    ابدأ
                </a>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php if ( $booking ): ?>
            <div class="rk-mp-card">
                <div class="rk-mp-card__icon"><?php echo rk_mp_icon( 'calendar', 'rk-mp-icon--card' ); ?></div>
                <div>
<div class="rk-mp-card__label">اللقاء القادمة</div>
                    <div class="rk-mp-card__val">
                        <?php
                        $start = $booking->start_at ?? $booking->date ?? null;
                        echo $start ? esc_html( date_i18n( 'l j F — H:i', strtotime( (string) $start ) ) ) : '';
                        ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Liens rapides -->
            <div class="rk-mp-quicklinks">
                <a href="<?php echo esc_url( $base . 'rk-badges/' . $cp ); ?>" class="rk-mp-qlink">
                    <?php echo rk_mp_icon( 'trophy', 'rk-mp-icon--qlink' ); ?>
                    <span>شاراتي</span>
                </a>
                <a href="<?php echo esc_url( 'https://riadakids.com/dashboard/rk-challenges/' . $cp ); ?>" class="rk-mp-qlink">
                    <?php echo rk_mp_icon( 'flag', 'rk-mp-icon--qlink' ); ?>
                    <span>تحدياتي</span>
                </a>
                <a href="<?php echo esc_url( $base . 'rk-sessions/' . $cp ); ?>" class="rk-mp-qlink">
                    <?php echo rk_mp_icon( 'calendar', 'rk-mp-icon--qlink' ); ?>
  <span> لقاءاتي</span>
                </a>
                <a href="<?php echo esc_url( $base . 'rk-messages/' . $cp ); ?>" class="rk-mp-qlink">
                    <?php echo rk_mp_icon( 'message', 'rk-mp-icon--qlink' ); ?>
                    <span>رسائلي</span>
                </a>
            </div>
        </div>
    </div>
</div>