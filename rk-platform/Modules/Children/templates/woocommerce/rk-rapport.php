<?php
declare( strict_types=1 );
/**
 * RiadaKids — WooCommerce My Account : التقارير الشهرية
 * Accessible à /my-account/rk-rapport/
 *
 * @package RK_My_Children
 * @since   9.2.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$parent_id = get_current_user_id();
if ( ! $parent_id ) return;

global $wpdb;

/* ── Enfants du parent ───────────────────────────────────────── */
$children = $wpdb->get_results( $wpdb->prepare(
    "SELECT id, child_name, wp_user_id, avatar_url
       FROM {$wpdb->prefix}rk_children
      WHERE user_id = %d
   ORDER BY child_name ASC",
    $parent_id
), ARRAY_A ) ?: [];

if ( empty( $children ) ) {
    echo '<p dir="rtl" style="padding:24px;color:#64748b;">'
        . esc_html__( 'لا يوجد أطفال مسجلون في حسابك بعد.', 'rk-my-children' )
        . '</p>';
    return;
}

/* ── Enfant sélectionné ─────────────────────────────────────── */
// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$selected_id = absint( $_GET['child_id'] ?? 0 );
$child_map   = [];
foreach ( $children as $c ) {
    $child_map[ (int) $c['id'] ] = $c;
}
if ( ! $selected_id || ! isset( $child_map[ $selected_id ] ) ) {
    $selected_id = (int) $children[0]['id'];
}
$child       = (object) $child_map[ $selected_id ];
$child_id    = $selected_id;
$child_wp_id = (int) ( $child->wp_user_id ?? 0 );
$child_name  = rk_mc_child_full_name( $child );
$avatar_url  = function_exists( 'rk_mc_get_avatar_url' ) ? rk_mc_get_avatar_url( $child ) : get_avatar_url( $child_wp_id ?: 0 );

/* ── Période ────────────────────────────────────────────────── */
// phpcs:disable WordPress.Security.NonceVerification.Recommended
$year  = (int) ( $_GET['year']  ?? date( 'Y' ) );
$month = (int) ( $_GET['month'] ?? (int) date( 'm' ) );
// phpcs:enable
$year  = max( 2023, min( (int) date( 'Y' ), $year ) );
$month = max( 1, min( 12, $month ) );

$month_start = sprintf( '%04d-%02d-01 00:00:00', $year, $month );
$month_end   = sprintf( '%04d-%02d-%02d 23:59:59', $year, $month, (int) date( 't', mktime( 0, 0, 0, $month, 1, $year ) ) );
$base_url    = wc_get_account_endpoint_url( RK_MC_Endpoint::RAPPORT_SLUG );

/* ── Pagination (demande utilisateur) — 6 cartes par page, grille
   3 colonnes desktop / 1 mobile (voir rp-session-grid en CSS). ── */
// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$rk_paged      = max( 1, absint( $_GET['rp_page'] ?? 1 ) );
$rk_per_page   = 6;

/* ── Fil d'Ariane (demande utilisateur) — الأطفال / <enfant> / التقارير,
   affiché en flex justify-content:space-between avec le titre. ── */
$children_index_url = wc_get_account_endpoint_url( RK_MC_Endpoint::SLUG );

$month_names_ar = [ 1=>'يناير',2=>'فبراير',3=>'مارس',4=>'أبريل',5=>'مايو',6=>'يونيو',7=>'يوليو',8=>'أغسطس',9=>'سبتمبر',10=>'أكتوبر',11=>'نوفمبر',12=>'ديسمبر' ];
$period_label   = ( $month_names_ar[ $month ] ?? '' ) . ' ' . $year;

/* ── Toutes les séances de l'enfant (plus de filtre mensuel :
   cette page est maintenant une liste complète filtrable par programme,
   pas un rapport du mois) ────────────────────────────────────── */

// Vérifie si la colonne coach_id existe (ajoutée par migration rk-platform)
$_bt              = $wpdb->prefix . 'rk_bookings';
$_has_coach_id    = (bool) $wpdb->get_var( "SHOW COLUMNS FROM `{$_bt}` LIKE 'coach_id'" );
// b.* inclut déjà toute colonne "event_name" si elle existe sur cette
// installation (non confirmée sur tous les sites) — on vérifie sa présence
// avant de s'y fier pour l'affichage, plutôt que de supposer son existence.
$_has_event_name  = (bool) $wpdb->get_var( "SHOW COLUMNS FROM `{$_bt}` LIKE 'event_name'" );

if ( $_has_coach_id ) {
    // coach_id → JOIN wp_users pour display_name fiable
    // COALESCE : préfère le nom WP, sinon le VARCHAR coach (ancien format)
    $sessions = $wpdb->get_results( $wpdb->prepare(
        "SELECT b.*,
                t.name        AS program_name,
                p.post_title  AS course_name,
                COALESCE( NULLIF( u.display_name, '' ), NULLIF( b.coach, '' ) ) AS coach_resolved
           FROM {$_bt} b
      LEFT JOIN {$wpdb->terms} t  ON t.term_id = b.program_id
      LEFT JOIN {$wpdb->posts} p  ON p.ID      = b.course_id
      LEFT JOIN {$wpdb->users} u  ON u.ID      = b.coach_id AND b.coach_id > 0
          WHERE b.child_id = %d
       ORDER BY b.appointment DESC",
        $child_id
    ) ) ?: [];
} else {
    $sessions = $wpdb->get_results( $wpdb->prepare(
        "SELECT b.*,
                t.name        AS program_name,
                p.post_title  AS course_name,
                NULLIF( b.coach, '' ) AS coach_resolved
           FROM {$_bt} b
      LEFT JOIN {$wpdb->terms} t ON t.term_id = b.program_id
      LEFT JOIN {$wpdb->posts} p ON p.ID      = b.course_id
          WHERE b.child_id = %d
       ORDER BY b.appointment DESC",
        $child_id
    ) ) ?: [];
}

$total_sessions = count( $sessions );

/* ── Évaluation par séance (pour cartes) ─────────────────────────
   CORRECTIF — indexé par booking_id (clé fiable, une évaluation par
   séance) au lieu de date+coach, qui confondait 2 séances du même
   enfant le même jour. Fallback date+coach conservé pour les
   évaluations créées avant l'ajout de la colonne booking_id (voir
   RKP_AssessmentRepository::ensure_schema()). */
$rk_session_evals = [];
if ( class_exists( 'RK_MC_Assessment_Service' ) ) {
    foreach ( $sessions as $s ) {
        $s_booking_id = (int) ( $s->booking_id ?? 0 );
        if ( ! $s_booking_id || isset( $rk_session_evals[ $s_booking_id ] ) ) continue;

        $s_eval = RK_MC_Assessment_Service::get_for_booking( $s_booking_id );
        if ( ! $s_eval ) {
            $s_date  = substr( (string) ( $s->appointment ?? '' ), 0, 10 );
            $s_coach = (int) ( $s->coach_id ?? 0 );
            if ( $s_date && $s_coach ) {
                $s_eval = RK_MC_Assessment_Service::get_for_child_date( $child_id, $s_date, $s_coach );
            }
        }
        $rk_session_evals[ $s_booking_id ] = $s_eval;
    }
}

/* ── Liste des programmes (filtre) : TOUTES les catégories de cours
   Tutor LMS (taxonomie 'course-category'), pas seulement celles ayant
   déjà une séance réservée pour cet enfant — un programme disponible
   doit apparaître dans le filtre même si l'enfant n'y a pas encore
   participé. Même taxonomie que celle référencée par b.program_id
   (voir RKP_CategoryRepository, seule couche du plugin autorisée à
   appeler get_the_terms() pour les cours). */
$rk_program_terms = get_terms( [
    'taxonomy'   => 'course-category',
    'hide_empty' => false,
    'orderby'    => 'name',
    'order'      => 'ASC',
] );
$rk_program_list = ( ! is_wp_error( $rk_program_terms ) && $rk_program_terms )
    ? array_map( static function ( $t ) { return trim( (string) $t->name ); }, $rk_program_terms )
    : [];

// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$rk_active_program = isset( $_GET['program'] ) ? sanitize_text_field( wp_unslash( $_GET['program'] ) ) : '';

/*
 * Rotation d'icônes décoratives pour les cartes de séance : aucun champ
 * icon_key/catégorie exploitable n'existe sur rk_bookings, donc on
 * applique une rotation stable sur le jeu d'icônes déjà utilisé ailleurs
 * dans le plugin (rkd4-icons.php), cohérente avec child-profile.php.
 */
$rk_icon_cycle = array( 'brain', 'code', 'book', 'rocket' );
include RK_MC_DIR . 'templates/dashboard/partials/rkd4-icons.php';

/* ── Vue détail d'une séance : ?booking_id=X (clic "عرض التقرير"
   depuis une carte) ─────────────────────────────────────────── */
// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$rk_requested_booking_id = absint( $_GET['booking_id'] ?? 0 );
$rk_session_view         = null; // séance ciblée (objet $wpdb->get_row)
$rk_session_eval         = null; // évaluation coach correspondante, si elle existe

if ( $rk_requested_booking_id ) {
    if ( $_has_coach_id ) {
        $rk_session_view = $wpdb->get_row( $wpdb->prepare(
            "SELECT b.*,
                    t.name        AS program_name,
                    p.post_title  AS course_name,
                    COALESCE( NULLIF( u.display_name, '' ), NULLIF( b.coach, '' ) ) AS coach_resolved
               FROM {$_bt} b
          LEFT JOIN {$wpdb->terms} t  ON t.term_id = b.program_id
          LEFT JOIN {$wpdb->posts} p  ON p.ID      = b.course_id
          LEFT JOIN {$wpdb->users} u  ON u.ID      = b.coach_id AND b.coach_id > 0
              WHERE b.booking_id = %d AND b.child_id = %d",
            $rk_requested_booking_id, $child_id
        ) );
    } else {
        $rk_session_view = $wpdb->get_row( $wpdb->prepare(
            "SELECT b.*,
                    t.name        AS program_name,
                    p.post_title  AS course_name,
                    NULLIF( b.coach, '' ) AS coach_resolved
               FROM {$_bt} b
          LEFT JOIN {$wpdb->terms} t ON t.term_id = b.program_id
          LEFT JOIN {$wpdb->posts} p ON p.ID      = b.course_id
              WHERE b.booking_id = %d AND b.child_id = %d",
            $rk_requested_booking_id, $child_id
        ) );
    }

    // Sécurité : la séance doit bien appartenir à l'enfant sélectionné
    // (déjà filtré par child_id ci-dessus — $rk_session_view reste null sinon).
    $rk_session_quizzes = [];
    if ( $rk_session_view && class_exists( 'RK_MC_Assessment_Service' ) ) {
        $rk_s_date  = substr( (string) ( $rk_session_view->appointment ?? '' ), 0, 10 );
        $rk_s_coach = (int) ( $rk_session_view->coach_id ?? 0 );

        // CORRECTIF — priorité au lien direct booking_id (fiable, une
        // évaluation par séance). Fallback date+coach uniquement pour les
        // évaluations créées avant cette migration (booking_id absent côté
        // écriture à l'époque). Voir RKP_AssessmentRepository::ensure_schema().
        $rk_session_eval = RK_MC_Assessment_Service::get_for_booking( $rk_requested_booking_id );
        if ( ! $rk_session_eval && $rk_s_date && $rk_s_coach ) {
            $rk_session_eval = RK_MC_Assessment_Service::get_for_child_date( $child_id, $rk_s_date, $rk_s_coach );
        }

        /* ── Quiz AYS Maker liés à cette séance ────────────────────
           CORRECTIF — chaque quiz porte désormais un lien fiable vers
           son cours (options.rk_course_id, voir RK_Coach_Quizzes_
           Controller::create_quiz(), obligatoire depuis v2.7), et la
           séance (b.course_id) aussi. On filtre par CE cours plutôt que
           par une fenêtre de dates approximative : sans ce filtre, un
           enfant suivant plusieurs cours voyait les quiz d'un AUTRE
           cours apparaître sur le rapport d'une séance qui n'avait
           aucun rapport avec eux, simplement parce qu'ils tombaient
           dans les ±3 jours. La fenêtre de dates reste un second filtre
           (élargi à ±7 jours) pour les quiz anciens sans rk_course_id
           (rétrocompatibilité pré-v2.7) — filtrés uniquement par date
           dans ce cas, comme avant. */
        $rk_sv_course_id = (int) ( $rk_session_view->course_id ?? 0 );
        if ( $child_wp_id && $rk_s_date ) {
            $qz    = $wpdb->prefix . 'aysquiz_quizes';
            $rp_tb = $wpdb->prefix . 'aysquiz_reports';
            if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $qz ) ) === $qz ) {
                $rk_window_start = date( 'Y-m-d 00:00:00', strtotime( $rk_s_date . ' -7 days' ) );
                $rk_window_end   = date( 'Y-m-d 23:59:59', strtotime( $rk_s_date . ' +7 days' ) );
                $rk_quiz_candidates = $wpdb->get_results( $wpdb->prepare(
                    "SELECT q.id AS quiz_id, q.title AS quiz_title, q.options,
                            r.score, r.corrects_count, r.questions_count, r.end_date AS attempted_at
                       FROM {$qz} q
                 INNER JOIN {$rp_tb} r ON r.quiz_id = q.id AND r.user_id = %d
                      WHERE q.quiz_url = %s
                        AND r.end_date BETWEEN %s AND %s
                   ORDER BY r.end_date DESC",
                    $child_wp_id, 'rk:child:' . $child_id, $rk_window_start, $rk_window_end
                ) ) ?: [];

                $rk_session_quizzes = array_values( array_filter( $rk_quiz_candidates, static function ( $q ) use ( $rk_sv_course_id ) {
                    $opts             = json_decode( (string) ( $q->options ?? '{}' ), true ) ?: [];
                    $quiz_course_id   = (int) ( $opts['rk_course_id'] ?? 0 );
                    // Quiz lié à un cours connu : ne garder que ceux du MÊME
                    // cours que la séance. Quiz pré-v2.7 sans rk_course_id :
                    // conservé (repli sur la seule fenêtre de dates, comme
                    // avant ce correctif).
                    return ! $quiz_course_id || ( $rk_sv_course_id && $quiz_course_id === $rk_sv_course_id );
                } ) );
            }
        }
    }

    /* ── Compétences de l'enfant (portée globale, pas par séance —
       aucune notion de "compétences travaillées lors de cette séance
       précise" n'existe en base ; ce sont les compétences générales
       de l'enfant, cohérent avec la maquette). ──────────────────── */
    $rk_session_skills = ( $rk_session_view && class_exists( 'RK_MC_Skill_Service' ) )
        ? RK_MC_Skill_Service::get_skills( $child_id )
        : [];
}

/* ── Évaluation affichée dans "ملاحظة المدرب" : celle de la séance
   ciblée si on est en vue détail, sinon la dernière du mois ────── */
$last_eval = $rk_session_view ? $rk_session_eval : null;
if ( null === $last_eval && ! $rk_session_view && class_exists( 'RK_MC_Assessment_Service' ) ) {
    $all_evals = RK_MC_Assessment_Service::get_all( $child_id );
    foreach ( $all_evals as $ev ) {
        if ( ! empty( $ev['assessed_at'] ) && $ev['assessed_at'] >= $month_start && $ev['assessed_at'] <= $month_end ) {
            $last_eval = $ev;
            break;
        }
    }
}

?>
<?php // CSS factorisé — voir Modules/Children/assets/css/rk-rapport.css (versionné par filemtime pour contourner le cache LiteSpeed)
$_rk_css_rel = 'Modules/Children/assets/css/rk-rapport.css';
printf( '<link rel="stylesheet" href="%s">', esc_url( RKP_URL . $_rk_css_rel . '?ver=' . (string) filemtime( RKP_DIR . $_rk_css_rel ) ) );
?>

<div class="rp-wrap" dir="rtl">

<?php
/* ── Fil d'Ariane (demande utilisateur) — الأطفال / <enfant> / التقارير,
   avec un 4e niveau (nom de la séance) en vue détail — flex
   justify-content:space-between avec le titre principal. Le titre
   lui-même devient "تقرير" en vue détail, "التقارير" sur la liste. */
$rp_title = $rk_session_view
    ? __( 'تقرير', 'rk-my-children' )
    : __( 'التقارير', 'rk-my-children' );
?>
<div class="rp-top-title">
    <h1><?php echo esc_html( $rp_title ); ?></h1>
    <nav class="rp-breadcrumb" aria-label="<?php esc_attr_e( 'مسار التصفح', 'rk-my-children' ); ?>">
        <a href="<?php echo esc_url( $children_index_url ); ?>"><?php esc_html_e( 'الأطفال', 'rk-my-children' ); ?></a>
        <span class="rp-breadcrumb__sep" aria-hidden="true">/</span>
        <?php if ( $rk_session_view ) : ?>
        <a href="<?php echo esc_url( add_query_arg( [ 'child_id' => $child_id ], remove_query_arg( 'booking_id' ) ) ); ?>">
            <?php echo esc_html( $child_name ); ?>
        </a>
        <span class="rp-breadcrumb__sep" aria-hidden="true">/</span>
        <a href="<?php echo esc_url( add_query_arg( [ 'child_id' => $child_id ], remove_query_arg( 'booking_id' ) ) ); ?>">
            <?php esc_html_e( 'التقارير', 'rk-my-children' ); ?>
        </a>
        <span class="rp-breadcrumb__sep" aria-hidden="true">/</span>
        <span class="rp-breadcrumb__current">
            <?php echo esc_html( $rk_session_view->session_name ?: __( 'لقاء', 'rk-my-children' ) ); ?>
        </span>
        <?php else : ?>
        <span><?php echo esc_html( $child_name ); ?></span>
        <span class="rp-breadcrumb__sep" aria-hidden="true">/</span>
        <span class="rp-breadcrumb__current"><?php esc_html_e( 'التقارير', 'rk-my-children' ); ?></span>
        <?php endif; ?>
    </nav>
</div>

<?php /* ── Filtres : enfant (dropdown) + برنامج, alignés en flex ──── */ ?>
<?php if ( $rk_session_view ) : ?>
<a href="<?php echo esc_url( add_query_arg( [ 'child_id' => $child_id ], remove_query_arg( 'booking_id' ) ) ); ?>"
   class="rp-back-link">
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><polyline points="9 18 15 12 9 6"/></svg>
    <?php esc_html_e( 'العودة إلى كل اللقاءات', 'rk-my-children' ); ?>
</a>
<?php else : ?>

<div class="rp-filters-row">
    <?php /* ── AJUSTEMENT (demande utilisateur) — rp-child-filter passe
       d'onglets à une liste déroulante (<select>), alignée en flex avec
       rp-program-filter. La navigation (changement de page) est gérée
       par un petit script inline plutôt qu'un fichier JS séparé, pour
       rester cohérent avec le fonctionnement en lien <a> de tout le
       reste de cette page (pas de dépendance JS supplémentaire). ── */ ?>
    <?php if ( count( $children ) > 1 ) : ?>
    <div class="rp-child-filter">
        <label class="rp-visually-hidden" for="rp-child-select"><?php esc_html_e( 'اختر الطفل', 'rk-my-children' ); ?></label>
        <select id="rp-child-select" class="rp-child-select"
                onchange="location.href=this.value;">
            <?php foreach ( $children as $c ) :
                $c_id    = (int) $c['id'];
                $tab_url = add_query_arg( [ 'child_id' => $c_id, 'year' => $year, 'month' => $month ], $base_url );
            ?>
            <option value="<?php echo esc_url( $tab_url ); ?>"<?php selected( $c_id, $selected_id ); ?>>
                <?php echo esc_html( $c['child_name'] ?? '' ); ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php endif; ?>

    <?php if ( $rk_program_list ) : ?>
    <div class="rp-program-filter" role="tablist" aria-label="<?php esc_attr_e( 'تصفية حسب البرنامج', 'rk-my-children' ); ?>">
        <a href="<?php echo esc_url( remove_query_arg( 'program' ) ); ?>"
           class="rp-program-pill<?php echo '' === $rk_active_program ? ' active' : ''; ?>">
            <?php esc_html_e( 'الكل', 'rk-my-children' ); ?>
        </a>
        <?php foreach ( $rk_program_list as $rk_prog ) :
            $rk_prog_url = add_query_arg( 'program', rawurlencode( $rk_prog ) );
        ?>
        <a href="<?php echo esc_url( $rk_prog_url ); ?>"
           class="rp-program-pill<?php echo $rk_active_program === $rk_prog ? ' active' : ''; ?>">
            <?php echo esc_html( $rk_prog ); ?>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<?php
$rk_sessions_filtered = $rk_active_program
    ? array_filter( $sessions, static function ( $s ) use ( $rk_active_program ) {
        return trim( (string) ( $s->program_name ?? '' ) ) === $rk_active_program;
    } )
    : $sessions;
$rk_sessions_filtered = array_values( $rk_sessions_filtered );

/* ── Pagination (demande utilisateur) — 6 cartes/page ──────────── */
$rk_total_sessions = count( $rk_sessions_filtered );
$rk_total_pages    = max( 1, (int) ceil( $rk_total_sessions / $rk_per_page ) );
$rk_paged          = min( $rk_paged, $rk_total_pages );
$rk_page_offset    = ( $rk_paged - 1 ) * $rk_per_page;
$rk_sessions_page  = array_slice( $rk_sessions_filtered, $rk_page_offset, $rk_per_page );
?>

<div id="rp-sessions-results"
     data-ajax-url="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>"
     data-nonce="<?php echo esc_attr( wp_create_nonce( 'rk_rapport_filter' ) ); ?>"
     data-child-id="<?php echo esc_attr( $child_id ); ?>">
<?php include RK_MC_DIR . 'templates/woocommerce/partials/rapport-sessions-grid.php'; ?>
</div>

<?php endif; // ! $rk_session_view — fin du bloc "vue liste" (filtres + grille + pagination) ?>



<?php /* ── Rapport de séance : structure alignée sur Figma (node
   361:1092, frame " الخوارزميات البسيطة") — hero illustré, deux
   colonnes (نتائج الاختبارات + ملاحظة المدرب), puis مستوى المهارات. */ ?>
<?php if ( $rk_session_view ) :
    $rk_sv_date  = rk_mc_appt_format( $rk_session_view->appointment ?? '', 'j/m/Y', $rk_requested_booking_id );
    $rk_sv_time  = rk_mc_appt_format( $rk_session_view->appointment ?? '', 'H:i',   $rk_requested_booking_id );
    $rk_sv_prog  = trim( (string) ( $rk_session_view->program_name   ?? '' ) );
    $rk_sv_coach = trim( (string) ( $rk_session_view->coach_resolved ?? '' ) );
    $rk_sv_coach_avatar = ! empty( $rk_session_view->coach_id )
        ? get_avatar_url( (int) $rk_session_view->coach_id, [ 'size' => 50 ] )
        : '';
?>
<div class="rp-detail">

    <?php /* ── Hero ─────────────────────────────────────────── */ ?>
    <div class="rp-detail__hero">
        <div class="rp-detail__hero-main">
            <span class="rp-detail__hero-icon" aria-hidden="true">
                <?php echo $rkd4_icon( 'rocket', 32 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
            </span>
            <div class="rp-detail__hero-titles">
                <h1><?php echo esc_html( $rk_session_view->session_name ?: __( 'لقاء', 'rk-my-children' ) ); ?></h1>
                <?php if ( $rk_sv_prog ) : ?>
                <p><?php echo esc_html( $rk_sv_prog ); ?></p>
                <?php endif; ?>
            </div>
        </div>
        <?php if ( $rk_sv_coach ) : ?>
        <div class="rp-detail__hero-coach">
            <span><?php echo esc_html( sprintf(
                /* translators: %s: nom du coach/de la coach */
                __( 'المدرب %s', 'rk-my-children' ),
                $rk_sv_coach
            ) ); ?></span>
            <?php if ( $rk_sv_coach_avatar ) : ?>
            <img src="<?php echo esc_url( $rk_sv_coach_avatar ); ?>" alt="" width="50" height="50" loading="lazy">
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <?php /* ── نتائج الاختبارات + ملاحظة المدرب ────────────────── */ ?>
    <div class="rp-detail__cols">

        <div class="rp-detail__col">
            <h2 class="rp-detail__col-title"><?php esc_html_e( 'نتائج الاختبارات', 'rk-my-children' ); ?></h2>
            <?php if ( $rk_session_quizzes ) : ?>
            <div class="rp-quiz-list">
                <?php foreach ( $rk_session_quizzes as $q ) :
                    $score = (int) ( $q->score ?? 0 );
                    $opts  = json_decode( (string) ( $q->options ?? '{}' ), true ) ?: [];
                    $pg    = (int) ( $opts['passing_grade'] ?? 80 );
                    $passed = $score >= $pg;
                ?>
                <div class="rp-quiz-item">
                    <span class="rp-quiz-item__icon" aria-hidden="true">
                        <?php echo $rkd4_icon( 'book', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                    </span>
                    <div class="rp-quiz-item__body">
                        <strong><?php echo esc_html( $q->quiz_title ?: __( 'اختبار', 'rk-my-children' ) ); ?></strong>
                        <span class="rp-quiz-item__date">
                            <?php
                            $qts = strtotime( (string) ( $q->attempted_at ?? '' ) );
                            echo $qts ? esc_html( date_i18n( 'j/m/Y H:i', $qts ) ) : '—';
                            ?>
                        </span>
                    </div>
                    <span class="rp-quiz-item__score<?php echo $passed ? '' : ' rp-quiz-item__score--pending'; ?>">
                        <?php echo $passed ? esc_html( $score . '%' ) : esc_html__( 'قيد المراجعة', 'rk-my-children' ); ?>
                    </span>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else : ?>
            <p class="rp-empty-text"><?php esc_html_e( 'لا توجد نتائج اختبارات لهذا اللقاء بعد.', 'rk-my-children' ); ?></p>
            <?php endif; ?>
        </div>

        <div class="rp-detail__col">
            <h2 class="rp-detail__col-title"><?php esc_html_e( 'ملاحظة المدرب', 'rk-my-children' ); ?></h2>
            <?php if ( $rk_session_eval ) : ?>
            <div class="rp-coach-note">
                <?php if ( $rk_sv_coach ) : ?>
                <div class="rp-coach-note__head">
                    <span><?php echo esc_html( sprintf(
                        /* translators: %s: nom du coach/de la coach */
                        __( 'المدربة %s', 'rk-my-children' ),
                        $rk_sv_coach
                    ) ); ?></span>
                    <?php if ( $rk_sv_coach_avatar ) : ?>
                    <img src="<?php echo esc_url( $rk_sv_coach_avatar ); ?>" alt="" width="36" height="36" loading="lazy">
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                <?php if ( ! empty( $rk_session_eval['summary'] ) ) : ?>
                <p class="rp-coach-note__text"><?php echo esc_html( $rk_session_eval['summary'] ); ?></p>
                <?php endif; ?>

                <?php if ( ! empty( $rk_session_eval['strengths'] ) && is_array( $rk_session_eval['strengths'] ) ) : ?>
                <div class="rp-coach-note__block rp-coach-note__block--strengths">
                    <h3 class="rp-coach-note__label"><?php esc_html_e( 'نقاط القوة', 'rk-my-children' ); ?></h3>
                    <ul class="rp-coach-note__list">
                        <?php foreach ( $rk_session_eval['strengths'] as $rk_point ) : ?>
                        <li><?php echo esc_html( $rk_point ); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>

                <?php if ( ! empty( $rk_session_eval['developments'] ) && is_array( $rk_session_eval['developments'] ) ) : ?>
                <div class="rp-coach-note__block rp-coach-note__block--develop">
                    <h3 class="rp-coach-note__label"><?php esc_html_e( 'محاور التطوير', 'rk-my-children' ); ?></h3>
                    <ul class="rp-coach-note__list">
                        <?php foreach ( $rk_session_eval['developments'] as $rk_point ) : ?>
                        <li><?php echo esc_html( $rk_point ); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>
            </div>
            <?php else : ?>
            <p class="rp-empty-text"><?php esc_html_e( 'لا تتوفر ملاحظات من المدرب لهذا اللقاء بعد.', 'rk-my-children' ); ?></p>
            <?php endif; ?>
        </div>

    </div>

    <?php /* ── مستوى المهارات ───────────────────────────────── */ ?>
    <?php if ( $rk_session_skills ) : ?>
    <div class="rp-detail__section">
        <h2 class="rp-detail__col-title"><?php esc_html_e( 'مستوى المهارات', 'rk-my-children' ); ?></h2>
        <div class="rp-skills-grid">
            <?php foreach ( $rk_session_skills as $sk ) :
                $rk_sk_max   = max( 1, (int) ( $sk['max'] ?? 10 ) );
                $rk_sk_stars = (int) round( ( (int) ( $sk['level'] ?? 0 ) / $rk_sk_max ) * 5 );
            ?>
            <div class="rp-skill-card">
                <span class="rp-skill-card__icon" aria-hidden="true">
                    <?php echo $rkd4_icon( $sk['icon_key'] ?? 'star', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                </span>
                <div class="rp-skill-card__body">
                    <?php /* AJUSTEMENT (demande utilisateur) — rp-skill-card__name
                       placé au-dessus de rp-skill-card__track (au lieu d'à côté). */ ?>
                    <span class="rp-skill-card__name">
                        <?php echo esc_html( $sk['name'] ); ?>
                        <span class="rp-skill-card__stars" aria-hidden="true">
                            <?php for ( $rk_s = 1; $rk_s <= 5; $rk_s++ ) : ?>
                            <span class="rp-skill-card__star<?php echo $rk_s <= $rk_sk_stars ? ' is-active' : ''; ?>">
                                <?php echo $rkd4_icon( 'star', 11, true ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                            </span>
                            <?php endfor; ?>
                        </span>
                    </span>
                    <div class="rp-skill-card__track" role="progressbar" aria-valuenow="<?php echo (int) $sk['pct']; ?>" aria-valuemin="0" aria-valuemax="100">
                        <div class="rp-skill-card__fill" style="width:<?php echo (int) $sk['pct']; ?>%;"></div>
                    </div>
                </div>
                <span class="rp-skill-card__pct"><?php echo (int) $sk['pct']; ?>%</span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

</div>
<?php endif; // $rk_session_view ?>

</div><?php /* /.rp-wrap */ ?>
<?php // JS factorisé — voir Modules/Children/assets/js/rk-rapport.js
$_rk_js_rel = 'Modules/Children/assets/js/rk-rapport.js';
printf( '<script src="%s"></script>', esc_url( RKP_URL . $_rk_js_rel . '?ver=' . (string) filemtime( RKP_DIR . $_rk_js_rel ) ) );

// AJOUT (demande utilisateur) — filtre "برنامج" + pagination sans
// rechargement de page. Chargé seulement en vue liste (#rp-sessions-results
// n'existe pas en vue détail), mais le fichier gère aussi l'absence de
// l'élément (return anticipé), donc l'inclusion inconditionnelle est sûre.
$_rk_filters_js_rel = 'Modules/Children/assets/js/rk-rapport-filters.js';
printf( '<script src="%s"></script>', esc_url( RKP_URL . $_rk_filters_js_rel . '?ver=' . (string) filemtime( RKP_DIR . $_rk_filters_js_rel ) ) );
?>
