<?php
declare( strict_types=1 );
/**
 * RK Child Dashboard — enrolled-courses / courses (مغامراتي)
 *
 * v9.3 — catalogue complet + refonte grille conforme aux maquettes
 * "My Adventures" (desktop + mobile) :
 *   - Barre de filtres (catégorie + toggle "masquer complétées") sur une
 *     seule ligne, toggle aligné à droite, toujours visible (désactivé
 *     si aucun cours complété).
 *   - Sur mobile, les pastilles de catégorie deviennent un bouton
 *     dropdown "الكل ⌄" (même DOM, bascule CSS/JS — voir rk-adventures-grid.js).
 *   - Grille de cartes (4 col. desktop / 2 col. mobile) avec image, titre,
 *     catégorie, pourcentage + barre de progression, nombre de sessions,
 *     et bouton d'action (Continuer / Voir le certificat).
 *   - Filtrage catégorie + toggle 100% client (JS), aucun rechargement.
 *   - Icônes par catégorie : SVG écrits en dur dans ce template (v9.3),
 *     indépendants de rk_mc_svg_library() pour éliminer tout risque de
 *     désynchronisation entre ce fichier et includes/rk-mc-svg-icons.php.
 *
 * Données : uniquement via RKP_LearningQueryService (aucun accès direct
 * à tutor_utils()/$wpdb pour la progression ou les leçons — Domain/Infra
 * layer déjà en place, cf. Infrastructure/Tutor/ProgressRepository).
 *
 * Catalogue complet : affiche TOUS les cours Tutor LMS publiés,
 * indépendamment d'un booking confirmé ou d'une inscription. Le filtre
 * métier booking (wp_rk_bookings) reste disponible plus bas uniquement
 * pour l'affichage informatif "prochaine session".
 *
 * @since 9.3.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$child = RK_MC_Tutor_Dashboard::get_child_for_template();
if ( ! $child ) {
    echo '<p style="padding:24px;color:#64748b;" dir="rtl">' . esc_html__( 'لم يتم تحديد طفل.', 'rk-my-children' ) . '</p>';
    return;
}

$child_id   = (int) $child->id;
$wp_uid     = (int) ( $child->wp_user_id ?? 0 );
$child_name = esc_html( $child->first_name ?? $child->child_name ?? __( 'الطفل', 'rk-my-children' ) );

global $wpdb;

/* ── 1. TOUS les cours Tutor LMS publiés (catalogue complet) ────────
 * plus de filtre booking : le dashboard affiche le catalogue
 * complet. $bt reste défini : utilisé plus bas pour l'affichage
 * informatif "prochaine session" par cours réservé. */
$bt = $wpdb->prefix . 'rk_bookings';

$enrolled_ids = $wpdb->get_col(
    "SELECT ID FROM {$wpdb->posts}
      WHERE post_type = 'courses' AND post_status = 'publish'
      ORDER BY post_title ASC"
);
$enrolled_ids = array_map( 'intval', (array) $enrolled_ids );

/* ── 2. Construire les données de chaque cours + progress ───────────
 * Toute la progression/leçons passe par RKP_LearningQueryService (Domain
 * layer) — aucun calcul manuel ni accès direct à tutor_utils()/$wpdb ici. */
$courses = [];

foreach ( $enrolled_ids as $cid ) {
    $course = get_post( $cid );
    if ( ! $course || 'publish' !== $course->post_status ) continue;

    $progress = ( $wp_uid && class_exists( 'RKP_LearningQueryService' ) )
        ? RKP_LearningQueryService::get_progress( $cid, $wp_uid )
        : null;

    $pct           = $progress ? max( 0, min( 100, (int) $progress->percent ) ) : 0;
    $lessons_total = $progress ? (int) $progress->lessons_total : 0;
    $lessons_done  = $progress ? (int) $progress->lessons_done  : 0;

    $category = class_exists( 'RKP_LearningQueryService' )
        ? RKP_LearningQueryService::get_primary_category( $cid )
        : '';

    $thumb_id  = get_post_thumbnail_id( $cid );
    $thumb_url = $thumb_id ? (string) wp_get_attachment_image_url( $thumb_id, 'medium_large' ) : '';

    // Prochaine session à venir pour ce cours (données RK, pas Tutor — inchangé).
    $next_name = '';
    $next_date = '';
    if ( $bt ) {
        $nxt = $wpdb->get_row( $wpdb->prepare(
            "SELECT session_name, appointment, booking_id FROM {$bt}
              WHERE child_id = %d AND course_id = %d
                AND status IN ('confirmed','rescheduled') AND appointment > NOW()
              ORDER BY appointment ASC LIMIT 1",
            $child_id, $cid
        ) );
        if ( $nxt ) {
            $next_name = (string) ( $nxt->session_name ?? '' );
            $ts_nxt    = rk_mc_appt_timestamp( (string) ( $nxt->appointment ?? '' ) );
            $next_date = $ts_nxt
                ? rk_mc_appt_format( (string) $nxt->appointment, 'j M Y — H:i', (int) ( $nxt->booking_id ?? 0 ) )
                : '';
        }
    }

    $courses[] = [
        'id'            => $cid,
        'title'         => $course->post_title,
        'permalink'     => (string) get_permalink( $cid ),
        'category'      => $category,
        'cat_slug'      => '' !== $category ? sanitize_title( $category ) : '',
        'pct'           => $pct,
        'thumb_url'     => $thumb_url,
        'lessons_done'  => $lessons_done,
        'lessons_total' => $lessons_total,
        'is_completed'  => $pct >= 100,
        'next_name'     => $next_name,
        'next_date'     => $next_date,
    ];
}

/* ── 3. Liste des catégories présentes (pour les pastilles / dropdown) ── */
$categories = [];
foreach ( $courses as $c ) {
    if ( '' !== $c['category'] && ! isset( $categories[ $c['category'] ] ) ) {
        $categories[ $c['category'] ] = 0;
    }
}
foreach ( $courses as $c ) {
    if ( '' !== $c['category'] ) {
        $categories[ $c['category'] ]++;
    }
}

/* $total_count et $completed_count ont été retirés : ils ne servaient
 * plus qu'à afficher rk-adv2__pill-count (supprimé) et le toggle
 * "إخفاء المكتملة" (supprimé également — le filtre de statut
 * rk-adv2__status-dd couvre déjà ce cas via son option "مكتملة"). */

/* $param (RK_MC_Tutor_Dashboard::child_param()) n'est plus nécessaire
 * ici : RK_MC_Adventures_Filter_Service::render_cards() l'appelle
 * lui-même pour construire les liens "افتح المغامرة" de chaque carte,
 * que ce soit au premier chargement ou en réponse AJAX. */

/* ── Icônes par catégorie (v9.3 — SVG en dur dans ce fichier) ───────
 * Association par mot-clé plutôt que par index : robuste si l'ordre
 * des catégories change ou si une nouvelle catégorie est ajoutée
 * (retombe alors sur 'filter-business' plutôt que planter).
 * Les <path> sont stockés séparément du <svg> englobant pour pouvoir
 * réutiliser les mêmes attributs de balise (width/height/stroke) sur
 * toutes les pastilles catégorie sans dupliquer le wrapper. */
$rk_adv_category_icon = static function ( string $cat_name ): string {
    $map = [
        'الذكاء الاصطناعي'          => 'filter-ai',
        'ريادة الأعمال'             => 'filter-business',
        'المنطق والبرمجة'           => 'filter-logic',
        'التواصل باللغة الإنجليزية' => 'filter-english',
        'التواصل باللغة الانجليزية' => 'filter-english', // variante orthographique observée en prod
    ];
    foreach ( $map as $needle => $icon_key ) {
        if ( false !== mb_strpos( $cat_name, $needle ) ) {
            return $icon_key;
        }
    }
    return 'filter-business';
};

/* SVG des pastilles de filtre — en dur, indépendants de rk_mc_svg_library(). */
$rk_adv_filter_svgs = [
    'filter-business' =>
        '<path d="M10 12.4999V16.6666C10 16.6666 12.525 16.2083 13.3333 14.9999C14.2333 13.6499 13.3333 10.8333 13.3333 10.8333"/>'
        . '<path d="M2.08334 17.9167C2.08334 17.9167 2.50001 14.8 3.75001 13.75C4.09241 13.4615 4.52931 13.3095 4.97686 13.3234C5.42442 13.3372 5.8511 13.5159 6.17501 13.825C6.83334 14.475 6.84168 15.55 6.25001 16.25C5.20001 17.5 2.08334 17.9167 2.08334 17.9167Z"/>'
        . '<path d="M7.5 10C7.94345 8.84957 8.50184 7.74676 9.16667 6.70838C10.1377 5.15587 11.4897 3.87758 13.0942 2.99512C14.6986 2.11266 16.5022 1.65535 18.3333 1.66671C18.3333 3.93338 17.6833 7.91671 13.3333 10.8334C12.2806 11.4987 11.1639 12.0571 10 12.5L7.5 10Z"/>'
        . '<path d="M7.50001 9.99991H3.33334C3.33334 9.99991 3.79168 7.47491 5.00001 6.66658C6.35001 5.76658 9.16668 6.70824 9.16668 6.70824"/>',
    'filter-english' =>
        '<path d="M3.33331 16.2501V3.75008C3.33331 3.19755 3.55281 2.66764 3.94351 2.27694C4.33421 1.88624 4.86411 1.66675 5.41665 1.66675H15.8333C16.0543 1.66675 16.2663 1.75455 16.4226 1.91083C16.5788 2.06711 16.6666 2.27907 16.6666 2.50008V17.5001C16.6666 17.7211 16.5788 17.9331 16.4226 18.0893C16.2663 18.2456 16.0543 18.3334 15.8333 18.3334H5.41665C4.86411 18.3334 4.33421 18.1139 3.94351 17.7232C3.55281 17.3325 3.33331 16.8026 3.33331 16.2501ZM3.33331 16.2501C3.33331 15.6975 3.55281 15.1676 3.94351 14.7769C4.33421 14.3862 4.86411 14.1667 5.41665 14.1667H16.6666"/>'
        . '<path d="M6.66669 10.8333L10 5L13.3334 10.8333"/>'
        . '<path d="M7.58331 9.16675H12.3333"/>',
    'filter-logic' =>
        '<path d="M15 13.3334L18.3333 10.0001L15 6.66675"/>'
        . '<path d="M5.00002 6.66675L1.66669 10.0001L5.00002 13.3334"/>'
        . '<path d="M12.0834 3.33325L7.91669 16.6666"/>',
    'filter-ai' =>
        '<path d="M10 4.16655C10.001 3.83324 9.93532 3.5031 9.80685 3.19555C9.67837 2.88799 9.4897 2.60923 9.25191 2.37567C9.01413 2.1421 8.73204 1.95844 8.42224 1.83549C8.11243 1.71254 7.78117 1.65278 7.44793 1.65972C7.1147 1.66667 6.78621 1.74018 6.4818 1.87593C6.17739 2.01169 5.9032 2.20694 5.67536 2.45022C5.44751 2.69349 5.27061 2.97987 5.15506 3.29251C5.03952 3.60515 4.98765 3.93774 5.00252 4.27071C4.51269 4.39666 4.05794 4.63242 3.67271 4.96014C3.28749 5.28786 2.98189 5.69894 2.77906 6.16225C2.57623 6.62556 2.48149 7.12896 2.50201 7.63431C2.52254 8.13965 2.65779 8.63371 2.89752 9.07905C2.476 9.42149 2.14454 9.86174 1.93197 10.3615C1.7194 10.8612 1.63215 11.4054 1.67782 11.9465C1.7235 12.4877 1.9007 13.0095 2.19403 13.4666C2.48735 13.9236 2.88791 14.3021 3.36085 14.569C3.30245 15.0209 3.3373 15.4799 3.46326 15.9178C3.58922 16.3557 3.8036 16.7631 4.09318 17.1148C4.38275 17.4666 4.74136 17.7553 5.14687 17.963C5.55238 18.1708 5.99617 18.2932 6.45083 18.3227C6.9055 18.3522 7.36139 18.2881 7.79034 18.1346C8.2193 17.981 8.61221 17.7411 8.94482 17.4297C9.27743 17.1183 9.54267 16.742 9.72416 16.3241C9.90565 15.9062 9.99953 15.4555 10 14.9999V4.16655Z"/>'
        . '<path d="M7.5 10.8333C8.19963 10.5872 8.81057 10.1392 9.25556 9.54584C9.70056 8.95251 9.95962 8.24056 10 7.5"/>'
        . '<path d="M5.00244 4.27075C5.01892 4.67386 5.13272 5.067 5.33411 5.41659"/>'
        . '<path d="M2.89746 9.08C3.04991 8.95584 3.21305 8.84541 3.38496 8.75"/>'
        . '<path d="M5.00001 15.0001C4.4257 15.0003 3.86106 14.8522 3.36084 14.5701"/>'
        . '<path d="M10 10.8333H13.3333"/>'
        . '<path d="M10 15H15C15.442 15 15.866 15.1756 16.1785 15.4882C16.4911 15.8007 16.6667 16.2246 16.6667 16.6667V17.5"/>'
        . '<path d="M10 6.66675H16.6667"/>'
        . '<path d="M13.3334 6.66667V4.16667C13.3334 3.72464 13.509 3.30072 13.8215 2.98816C14.1341 2.67559 14.558 2.5 15 2.5"/>'
        . '<path d="M13.3333 11.2501C13.5634 11.2501 13.75 11.0635 13.75 10.8334C13.75 10.6033 13.5634 10.4167 13.3333 10.4167C13.1032 10.4167 12.9166 10.6033 12.9166 10.8334C12.9166 11.0635 13.1032 11.2501 13.3333 11.2501Z"/>'
        . '<path d="M15 2.91659C15.2302 2.91659 15.4167 2.73004 15.4167 2.49992C15.4167 2.2698 15.2302 2.08325 15 2.08325C14.7699 2.08325 14.5834 2.2698 14.5834 2.49992C14.5834 2.73004 14.7699 2.91659 15 2.91659Z"/>'
        . '<path d="M16.6667 17.9166C16.8968 17.9166 17.0833 17.73 17.0833 17.4999C17.0833 17.2698 16.8968 17.0833 16.6667 17.0833C16.4365 17.0833 16.25 17.2698 16.25 17.4999C16.25 17.73 16.4365 17.9166 16.6667 17.9166Z"/>'
        . '<path d="M16.6667 7.08333C16.8968 7.08333 17.0833 6.89679 17.0833 6.66667C17.0833 6.43655 16.8968 6.25 16.6667 6.25C16.4365 6.25 16.25 6.43655 16.25 6.66667C16.25 6.89679 16.4365 7.08333 16.6667 7.08333Z"/>',
];

/* Attributs autorisés pour wp_kses() sur les fragments <path> ci-dessus. */
$rk_adv_svg_kses = [
    'path' => [ 'd' => true ],
];

?>

<div class="rk-adv2" dir="rtl" data-rk-adv-grid>

    <!-- En-tête -->
    <div class="rk-adv2__head">
        <h2 class="rk-adv2__title">
            <?php echo wp_kses_post( rk_mc_svg( 'rocket', [ 'class' => 'rk-adv2__title-ico' ] ) ); ?>
            <?php esc_html_e( 'مغامراتي', 'rk-my-children' ); ?>
        </h2>
    </div>

    <?php if ( ! empty( $courses ) ) : ?>

    <!-- Barre de filtres : pastilles catégorie (→ dropdown en mobile) + toggle masquer complétées (à droite) -->
    <div class="rk-adv2__filterbar">

        <?php if ( count( $categories ) > 1 ) : ?>
        <div class="rk-adv2__filters-wrap" data-rk-adv-filters-wrap>

            <!-- Bouton déclencheur — visible uniquement en mobile (CSS), ouvre le dropdown -->
            <button type="button"
                    class="rk-adv2__filter-trigger"
                    data-rk-adv-filter-trigger
                    aria-haspopup="listbox"
                    aria-expanded="false">
                <span data-rk-adv-filter-trigger-label><?php esc_html_e( 'الكل', 'rk-my-children' ); ?></span>
                <svg class="rk-adv2__filter-trigger-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" aria-hidden="true">
                    <polyline points="6 9 12 15 18 9"/>
                </svg>
            </button>

            <!-- Pastilles — barre horizontale en desktop, panneau dropdown en mobile -->
            <div class="rk-adv2__filters"
                 role="listbox"
                 aria-label="<?php esc_attr_e( 'تصفية حسب البرنامج', 'rk-my-children' ); ?>"
                 data-rk-adv-filters>
                <button type="button"
                        class="rk-adv2__pill is-active"
                        role="option"
                        aria-selected="true"
                        data-rk-adv-filter="all"
                        data-rk-adv-filter-label="<?php esc_attr_e( 'الكل', 'rk-my-children' ); ?>">
                    <svg class="rk-adv2__pill-ico" width="20" height="20" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M5.83334 1.66675H14.1667"/>
                        <path d="M4.16666 5H15.8333"/>
                        <path d="M15.8333 8.33325H4.16667C3.24619 8.33325 2.5 9.07944 2.5 9.99992V16.6666C2.5 17.5871 3.24619 18.3333 4.16667 18.3333H15.8333C16.7538 18.3333 17.5 17.5871 17.5 16.6666V9.99992C17.5 9.07944 16.7538 8.33325 15.8333 8.33325Z"/>
                    </svg>
                    <?php esc_html_e( 'الكل', 'rk-my-children' ); ?>
                </button>
                <?php foreach ( $categories as $cat_name => $cat_count ) :
                    unset( $cat_count ); // plus affiché depuis la suppression de rk-adv2__pill-count
                    $cat_icon_key  = $rk_adv_category_icon( $cat_name );
                    $cat_icon_path = $rk_adv_filter_svgs[ $cat_icon_key ] ?? $rk_adv_filter_svgs['filter-business'];
                ?>
                <button type="button"
                        class="rk-adv2__pill"
                        role="option"
                        aria-selected="false"
                        data-rk-adv-filter="<?php echo esc_attr( sanitize_title( $cat_name ) ); ?>"
                        data-rk-adv-filter-label="<?php echo esc_attr( $cat_name ); ?>">
                    <svg class="rk-adv2__pill-ico" width="20" height="20" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <?php echo wp_kses( $cat_icon_path, $rk_adv_svg_kses ); ?>
                    </svg>
                    <?php echo esc_html( $cat_name ); ?>
                </button>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Filtre par statut — isolé du système de pastilles de catégorie
             (data-rk-adv-status-dd dédié, JS séparé de data-rk-adv-filter).
             Dropdown ENTIÈREMENT personnalisé (bouton + listbox ARIA) :
             un <select> natif ne peut pas être stylé de façon fiable
             sur tous les navigateurs (son chevron/apparence natifs
             restaient visibles malgré appearance:none), donc on
             construit ici notre propre composant, cohérent avec le
             reste du Design System du Dashboard. -->
        <div class="rk-adv2__status-dd" data-rk-adv-status-dd data-value="">
            <span id="rk-adv2-status-label" class="rk-adv2__status-dd-label">
                <?php esc_html_e( 'الحالة', 'rk-my-children' ); ?>
            </span>

            <button type="button"
                    class="rk-adv2__status-dd-trigger"
                    id="rk-adv2-status-trigger"
                    data-rk-adv-status-trigger
                    aria-haspopup="listbox"
                    aria-expanded="false"
                    aria-labelledby="rk-adv2-status-label rk-adv2-status-trigger">
                <span class="rk-adv2__status-dd-trigger-text is-placeholder" data-rk-adv-status-trigger-text>
                    <?php esc_html_e( 'اختر الحالة', 'rk-my-children' ); ?>
                </span>
                <svg class="rk-adv2__status-dd-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" aria-hidden="true">
                    <polyline points="6 9 12 15 18 9"/>
                </svg>
            </button>

            <ul class="rk-adv2__status-dd-list"
                role="listbox"
                aria-labelledby="rk-adv2-status-label"
                data-rk-adv-status-list
                hidden>
                <li role="option"
                    class="rk-adv2__status-dd-option"
                    data-value="in-progress"
                    aria-selected="false">
                    <?php esc_html_e( 'قيد التقدم', 'rk-my-children' ); ?>
                </li>
                <li role="option"
                    class="rk-adv2__status-dd-option"
                    data-value="completed"
                    aria-selected="false">
                    <?php esc_html_e( 'مكتملة', 'rk-my-children' ); ?>
                </li>
            </ul>
        </div>

    </div>

    <!-- Grille de cartes — le rendu de chaque carte vit désormais dans
         RK_MC_Adventures_Filter_Service::render_cards(), partagé avec la
         réponse AJAX du filtre (voir class-rk-mc-adventures-filter-service.php).
         Ainsi le HTML du premier chargement et celui renvoyé après un
         clic sur une pastille/le filtre de statut ne peuvent jamais
         diverger — un seul endroit à maintenir. -->
    <div class="rk-adv2__grid" data-rk-adv-grid-list>
        <?php
        if ( class_exists( 'RK_MC_Adventures_Filter_Service' ) ) {
            RK_MC_Adventures_Filter_Service::render_cards( $courses, $child_id );
        }
        ?>
    </div>

    <?php else : ?>

    <!-- État vide — aucun cours publié. Réutilise l'illustration
         empty-course.webp (conservée lors de la fusion des dossiers
         Children/Children-1) conformément à la maquette fournie, plutôt
         que l'icône SVG générique utilisée auparavant. -->
    <div class="rk-adv2__empty">
        <img src="<?php echo esc_url( RK_MC_URL . 'assets/img/illustrations/empty-course.webp' ); ?>"
             alt=""
             class="rk-adv2__empty-illustration"
             loading="lazy"
             width="220" height="220">
        <h3 class="rk-adv2__empty-title"><?php esc_html_e( 'مغامراتك الأولى بانتظارك!', 'rk-my-children' ); ?></h3>
        <p class="rk-adv2__empty-text">
            <?php esc_html_e( 'اختر مغامرتك من بين مجالات متنوعة، وابدأ رحلة التعلم مع مدربك.', 'rk-my-children' ); ?>
        </p>
        <a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="rk-adv2__cta rk-adv2__cta--go rk-adv2__empty-cta">
            <?php esc_html_e( 'اكتشف المغامرات', 'rk-my-children' ); ?>
            <svg class="rk-adv2__cta-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" aria-hidden="true">
                <polyline points="15 18 9 12 15 6"/>
            </svg>
        </a>
    </div>

    <?php endif; ?>

</div>