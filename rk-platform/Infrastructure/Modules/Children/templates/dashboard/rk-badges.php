<?php
declare( strict_types=1 );
/**
 * RiadaKids — إنجازاتي / شاراتي (child view)
 *
 * v2.0 — Refonte visuelle conforme à la maquette : bannière "خزانة
 * شاراتي", onglets شاراتي/شهادتي (le second renvoie vers la vraie
 * page rk-certificats.php, déjà existante et fonctionnelle — pas de
 * duplication de logique), filtres "الحالة"/"المجال", icône de badge
 * générique (rank-badge.png) au lieu de la mosaïque d'emojis.
 *
 * La LOGIQUE MÉTIER (catalogue complet, groupement par catégorie,
 * calcul earned/total, badge "من المدرب") est intégralement conservée
 * — voir RK_MC_Badge_Service::get_catalogue_for_child(), inchangée.
 *
 * @package RK_My_Children
 * @since   8.4.0
 * @since   9.7.0 Refonte visuelle (maquette + image de badge fournie).
 * @since   9.26  شارات المغامرات : le fil "Programme › Cours" en
 *                texte aligné à droite devient un titre + sous-titre
 *                CENTRÉS au-dessus de chaque groupe de séances (§voir
 *                .rkb2__adv-heading dans le CSS, remplace
 *                .rkb2__adv-breadcrumb).
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$child = RK_MC_Tutor_Dashboard::get_child_for_template();
if ( ! $child ) {
    echo '<p dir="rtl">' . esc_html__( 'يرجى تحديد الطفل أولاً.', 'rk-my-children' ) . '</p>';
    return;
}
$child_id = (int) $child->id;
$wp_uid   = (int) ( $child->wp_user_id ?? 0 );
$param    = RK_MC_Tutor_Dashboard::child_param();

// v9.13 — شارات المغامرات : système SÉPARÉ des badges standards
// (décision validée avec l'utilisateur) — une carte par cours réel de
// la plateforme, jamais dérivée du catalogue de badges. Voir
// RKP_AdventureQueryService pour l'architecture complète.
$adventures = class_exists( 'RKP_AdventureQueryService' )
    ? RKP_AdventureQueryService::get_adventures_for_child( $child_id, $wp_uid )
    : [];

// v9.18 — Liste des PROGRAMMES distincts réellement présents dans les
// aventures affichées (§validé avec l'utilisateur : uniquement ceux
// ayant au moins une séance visible — même règle que pour les
// catégories achievement ci-dessous). slug => libellé, dans l'ordre
// de première apparition — aucune requête supplémentaire, dérivé de
// $adventures déjà chargée.
$programs = [];
foreach ( $adventures as $adv ) {
    $name = $adv['program_name'] ?: __( 'عام', 'rk-my-children' );
    $slug = sanitize_title( $name );
    if ( ! isset( $programs[ $slug ] ) ) $programs[ $slug ] = $name;
}

// Full catalogue with earned/locked flags per badge — logique inchangée.
$catalogue_full = class_exists( 'RK_MC_Badge_Service' )
    ? RK_MC_Badge_Service::get_catalogue_for_child( $child_id )
    : [];

$categories = [
    'start'   => [ 'label' => 'البداية',        'color' => '#059669' ],
    'streak'  => [ 'label' => 'المداومة',        'color' => '#D97706' ],
    'mastery' => [ 'label' => 'الإتقان',         'color' => '#7C3AED' ],
    'skill'   => [ 'label' => 'مهارات المدرب',   'color' => 'var(--e-global-color-secondary,#4C95D7)' ],
    'special' => [ 'label' => 'خاصة ونادرة',     'color' => 'var(--e-global-color-primary,#FF4411)' ],
    'level'   => [ 'label' => 'المستويات',       'color' => '#0891B2' ],
];

// Group and count — logique inchangée.
$grouped  = [];
$total    = count( $catalogue_full );
$earned_n = 0;
foreach ( $catalogue_full as $b ) {
    $grouped[ $b['cat'] ][] = $b;
    if ( $b['earned'] ) $earned_n++;
}
$pct = $total > 0 ? (int) round( $earned_n / $total * 100 ) : 0;

// v9.19 — Résolution de l'image réelle CENTRALISÉE dans
// RKP_BadgeQueryService::resolve_icon_url() (Application/Query),
// consommée aussi par la page coach (RK_Coach_Badges_Controller) — une
// seule source de vérité au lieu de deux implémentations dupliquées.
// $catalogue_full (chargé plus haut via RK_MC_Badge_Service::get_catalogue_for_child())
// porte déjà 'icon_url' résolu pour chaque badge — plus besoin de
// $resolve_badge_icon local ici.
$badge_img_url = RK_MC_URL . 'assets/img/badges/rank-badge.png';

// URL de l'onglet شهادتي — pointe vers la vraie page rk-certificats.php,
// déjà fonctionnelle (catalogue de certificats, impression, code de
// vérification) : cet onglet n'est qu'un lien, aucune logique dupliquée.
$certificates_url = class_exists( 'RKP_LearningQueryService' )
    ? RKP_LearningQueryService::get_dashboard_url( 'rk-certificats' ) . $param
    : '#';
?>
<div class="rkb2" dir="rtl" data-rk-badges>

    <div class="rkb2__header">
        <h1 class="rkb2__title">
            <img src="<?php echo esc_url( RK_MC_URL . 'assets/img/icons/achievements-title-icon.svg' ); ?>"
                 alt="" class="rkb2__title-icon" loading="lazy" width="32" height="32">
            <?php esc_html_e( 'إنجازاتي', 'rk-my-children' ); ?>
        </h1>
        <div class="rkb2__tabs">
            <a href="<?php echo esc_url( $certificates_url ); ?>" class="rkb2__tab">
                <img src="<?php echo esc_url( RK_MC_URL . 'assets/img/icons/tab-certificates-icon.svg' ); ?>" alt="" class="rkb2__tab-icon" loading="lazy" width="16" height="16">
                <?php esc_html_e( 'شهادتي', 'rk-my-children' ); ?>
            </a>
            <span class="rkb2__tab rkb2__tab--active">
                <img src="<?php echo esc_url( RK_MC_URL . 'assets/img/icons/tab-badges-icon.svg' ); ?>" alt="" class="rkb2__tab-icon" loading="lazy" width="13" height="13">
                <?php esc_html_e( 'شاراتي', 'rk-my-children' ); ?>
            </span>
        </div>
    </div>

    <!-- ── Bannière "خزانة شاراتي" ── -->
    <div class="rkb2__banner">
        <div class="rkb2__banner-progress-card">
        <div class="rkb2__banner-progress-top">
            <span class="rkb2__banner-progress-pct"><?php echo (int) $pct; ?>%</span>
            <div class="rkb2__banner-progress-info">
                <p class="rkb2__banner-progress-label"><?php esc_html_e( 'تقدمك في جمع الشارات', 'rk-my-children' ); ?></p>
                <p class="rkb2__banner-progress-count">
                    <?php echo esc_html( sprintf(
                        /* translators: 1: earned, 2: total */
                        __( '%1$d / %2$d شارة مكتسبة', 'rk-my-children' ),
                        $earned_n, $total
                    ) ); ?>
                </p>
            </div>
            <!-- v9.35 — Groupe59 : médaille premium déplacée à l'intérieur
                 de .rkb2__banner-progress-card, en flex avec le reste du
                 contenu de la carte (remplace la superposition absolue sur
                 toute la bannière). Purement décorative (aria-hidden). -->
            <img src="<?php echo esc_url( RK_MC_URL . 'assets/img/icons/badge-medal-premium.svg' ); ?>"
                 alt="" class="rkb2__banner-medal" loading="lazy" width="66" height="76" aria-hidden="true">
        </div>
        <div class="rkb2__banner-progress-bar" role="img"
             aria-label="<?php echo esc_attr( sprintf( /* translators: %d: percentage */ __( 'اكتمال جمع الشارات %d%%', 'rk-my-children' ), $pct ) ); ?>">
            <div class="rkb2__banner-progress-bar-fill" style="width:<?php echo esc_attr( $pct ); ?>%"></div>
        </div>
        </div>
        <div class="rkb2__banner-body">
            <p class="rkb2__banner-title">
                <img src="<?php echo esc_url( RK_MC_URL . 'assets/img/icons/banner-title-icon.svg' ); ?>" alt="" class="rkb2__banner-title-icon" loading="lazy" width="18" height="18">
                <?php esc_html_e( 'خزانة شاراتي', 'rk-my-children' ); ?>
            </p>
            <p class="rkb2__banner-text">
                <?php
                if ( $earned_n === 0 ) {
                    esc_html_e( 'واصل جلساتك وأكمل دروسك لتفتح أول شارة من الشارات!', 'rk-my-children' );
                } else {
                    printf(
                        /* translators: 1: earned count, 2: total count */
                        esc_html__( 'واصل جلساتك وأكمل دروسك لتفتح المزيد من الشارات! أنت في %1$d / %2$d شارة مكتسبة', 'rk-my-children' ),
                        $earned_n, $total
                    );
                }
                ?>
            </p>
            <?php if ( class_exists( 'RKP_LearningQueryService' ) ) : ?>
            <a href="<?php echo esc_url( RKP_LearningQueryService::get_dashboard_url( 'enrolled-courses' ) . $param ); ?>" class="rk-adv2__cta rk-adv2__cta--go rkb2__banner-cta">
                <?php esc_html_e( 'تابع مغامراتك', 'rk-my-children' ); ?>
                <svg class="rk-adv2__cta-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" aria-hidden="true">
                    <polyline points="15 18 9 12 15 6"/>
                </svg>
            </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- ── Filtres ── -->
    <div class="rkb2__filters">
        <!-- v9.18 — شارات الإنجاز / شارات المغامرات deviennent des VRAIS
             boutons visibles (§confirmé avec l'utilisateur, style pill
             comme les onglets شاراتي/شهادتي), plus un dropdown type. الكل
             ajouté comme 3ème état, défaut. Le dropdown المجال ci-dessous
             devient CONTEXTUEL à ce choix (§JS : rebuild dynamique). -->
        <div class="rkb2__type-toggle" data-rkb-type-toggle role="group" aria-label="<?php esc_attr_e( 'المجال', 'rk-my-children' ); ?>">
            <button type="button" class="rkb2__type-btn is-active" data-value="achievement">
                <img src="<?php echo esc_url( RK_MC_URL . 'assets/img/icons/tab-badges-icon.svg' ); ?>" alt="" class="rkb2__tab-icon" loading="lazy" width="13" height="13">
                <?php esc_html_e( 'شارات الإنجاز', 'rk-my-children' ); ?>
            </button>
            <button type="button" class="rkb2__type-btn" data-value="adventure">
                <img src="<?php echo esc_url( RK_MC_URL . 'assets/img/icons/tab-certificates-icon.svg' ); ?>" alt="" class="rkb2__tab-icon" loading="lazy" width="16" height="16">
                <?php esc_html_e( 'شارات المغامرات', 'rk-my-children' ); ?>
            </button>
        </div>

        <div class="rkb2__filter-group">
        <div class="rkb2__filter" data-rkb-filter="status">
            <span class="rkb2__filter-label"><?php esc_html_e( 'الحالة:', 'rk-my-children' ); ?></span>
            <button type="button" class="rkb2__filter-trigger" data-rkb-filter-trigger aria-haspopup="listbox" aria-expanded="false">
                <span data-rkb-filter-text><?php esc_html_e( 'الكل', 'rk-my-children' ); ?></span>
                <svg class="rkb2__filter-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" aria-hidden="true"><polyline points="6 9 12 15 18 9"/></svg>
            </button>
            <ul class="rkb2__filter-list" role="listbox" hidden>
                <li role="option" class="rkb2__filter-option is-selected" data-value="all" aria-selected="true"><?php esc_html_e( 'الكل', 'rk-my-children' ); ?></li>
                <li role="option" class="rkb2__filter-option" data-value="earned" aria-selected="false"><?php esc_html_e( 'مكتسبة', 'rk-my-children' ); ?></li>
                <li role="option" class="rkb2__filter-option" data-value="locked" aria-selected="false"><?php esc_html_e( 'مقفلة', 'rk-my-children' ); ?></li>
            </ul>
        </div>

        <!-- v9.18 — dropdown المجال : rempli CÔTÉ SERVEUR avec les 3 jeux
             possibles simultanément (data-scope="achievement|adventure"
             sur chaque option), le JS bascule juste leur visibilité selon
             le type actif — pas de reconstruction DOM dynamique, plus
             robuste et sans nouvel appel réseau. "الكل" = les deux jeux
             combinés (§confirmé). -->
        <div class="rkb2__filter" data-rkb-filter="domain">
            <span class="rkb2__filter-label"><?php esc_html_e( 'المجال:', 'rk-my-children' ); ?></span>
            <button type="button" class="rkb2__filter-trigger" data-rkb-filter-trigger aria-haspopup="listbox" aria-expanded="false">
                <span data-rkb-filter-text><?php esc_html_e( 'الكل', 'rk-my-children' ); ?></span>
                <svg class="rkb2__filter-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" aria-hidden="true"><polyline points="6 9 12 15 18 9"/></svg>
            </button>
            <ul class="rkb2__filter-list" role="listbox" hidden>
                <li role="option" class="rkb2__filter-option is-selected" data-value="all" data-scope="achievement,adventure" aria-selected="true"><?php esc_html_e( 'الكل', 'rk-my-children' ); ?></li>
                <?php foreach ( $categories as $cat_key => $cat_meta ) :
                    if ( empty( $grouped[ $cat_key ] ) ) continue;
                ?>
                <li role="option" class="rkb2__filter-option" data-value="cat:<?php echo esc_attr( $cat_key ); ?>" data-scope="achievement" aria-selected="false"><?php echo esc_html( $cat_meta['label'] ); ?></li>
                <?php endforeach; ?>
                <?php foreach ( $programs as $prog_slug => $prog_label ) : ?>
                <li role="option" class="rkb2__filter-option" data-value="prog:<?php echo esc_attr( $prog_slug ); ?>" data-scope="adventure" aria-selected="false"><?php echo esc_html( $prog_label ); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        </div>
    </div>

    <!-- ── Sections par catégorie ── -->
    <div data-rkb-sections>
        <?php foreach ( $categories as $cat_key => $cat_meta ) :
            if ( empty( $grouped[ $cat_key ] ) ) continue;
            $cat_earned = count( array_filter( $grouped[ $cat_key ], static fn( $b ) => $b['earned'] ) );
            $cat_total  = count( $grouped[ $cat_key ] );
        ?>
        <div class="rkb2__section" data-rkb-section data-category="<?php echo esc_attr( $cat_key ); ?>">
            <div class="rkb2__section-head">
                <div class="rkb2__section-label">
                    <span class="rkb2__section-name">
                        <?php echo esc_html( $cat_meta['label'] ); ?>
                    </span>
                </div>
            </div>
            <div class="rkb2__grid" data-rk-stagger>
                <?php foreach ( $grouped[ $cat_key ] as $b ) :
                    $earned   = (bool) $b['earned'];
                    $date_str = ( $earned && ! empty( $b['earned_at'] ) )
                        ? date_i18n( 'j M Y', strtotime( $b['earned_at'] ) )
                        : '';
                    // v9.12 — is_new vient EXCLUSIVEMENT de RKP_BadgeQueryService::
                    // compute_is_new() (earned_at!==null && seen_at===null),
                    // jamais recalculé/deviné ici — remplace l'ancienne
                    // heuristique fausse ($is_coach basé sur le texte de `note`,
                    // qui affichait "جديدة" indéfiniment pour tout badge
                    // attribué par un coach, peu importe si l'enfant l'avait
                    // déjà vu).
                    $is_new     = (bool) ( $b['is_new'] ?? false );
                    $badge_type = $b['type'] ?? null; // null = custom non classifié (§6 : jamais deviné) — donnée métier, PAS le filtre type ci-dessous.
                ?>
                <div class="rkb2__card <?php echo $earned ? 'is-earned' : 'is-locked'; ?><?php echo $is_new ? ' is-new' : ''; ?>"
                     data-rkb-card
                     data-earned="<?php echo $earned ? '1' : '0'; ?>"
                     data-new="<?php echo $is_new ? '1' : '0'; ?>"
                     data-type="achievement"
                     data-badge-type="<?php echo esc_attr( $badge_type ?? '' ); ?>"
                     data-category="<?php echo esc_attr( $cat_key ); ?>"
                     data-badge-key="<?php echo esc_attr( $b['key'] ?? '' ); ?>"
                     style="--cat:<?php echo esc_attr( $cat_meta['color'] ); ?>">

                    <?php if ( $is_new ) : ?>
                    <span class="rkb2__card-flag"><?php esc_html_e( 'جديدة', 'rk-my-children' ); ?></span>
                    <?php endif; ?>

                    <div class="rkb2__card-icon-wrap rkb2__card-icon-wrap--badge">
                        <img src="<?php echo esc_url( $b['icon_url'] ?? $badge_img_url ); ?>" alt="" class="rkb2__card-icon" loading="lazy" width="140" height="140">
                    </div>

                    <p class="rkb2__card-name"><?php echo esc_html( $b['name'] ?? '' ); ?></p>

                    <?php if ( $earned && $date_str ) : ?>
                    <span class="rkb2__card-chip"><?php echo esc_html( $date_str ); ?></span>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>

        <?php if ( ! empty( $adventures ) ) :
            // v9.30 — Regroupement à DEUX niveaux : PROGRAMME (un seul
            // en-tête par programme, groupant tous ses cours) puis COURS
            // à l'intérieur (chacun avec son propre sous-titre + grille
            // de séances). Avant cette version, le regroupement se
            // faisait uniquement par course_id : chaque cours devenait
            // son propre .rkb2__adv-group avec son propre en-tête
            // programme, ce qui répétait le nom du programme à chaque
            // cours au lieu de l'afficher une seule fois au-dessus de
            // tous ses cours. Toujours une présentation pure (§30) —
            // aucun recalcul de logique métier, uniquement un
            // regroupement de la liste à plat déjà retournée par le
            // service.
            $adventures_by_program = [];
            foreach ( $adventures as $adv ) {
                $program_name = $adv['program_name'] ?: __( 'عام', 'rk-my-children' );
                $program_slug = sanitize_title( $program_name );

                if ( ! isset( $adventures_by_program[ $program_slug ] ) ) {
                    $adventures_by_program[ $program_slug ] = [
                        'program_name' => $adv['program_name'], // '' si "عام" — affichage conditionnel inchangé plus bas
                        'courses'      => [],
                    ];
                }

                $course_id = $adv['course_id'];
                if ( ! isset( $adventures_by_program[ $program_slug ]['courses'][ $course_id ] ) ) {
                    $adventures_by_program[ $program_slug ]['courses'][ $course_id ] = [
                        'course_title' => $adv['course_title'],
                        'sessions'     => [],
                    ];
                }

                $adventures_by_program[ $program_slug ]['courses'][ $course_id ]['sessions'][] = $adv;
            }
        ?>
        <!-- v9.14 — شارات المغامرات : REFONTE, une carte par SÉANCE réelle
             (leçon Tutor), plus par cours — décision validée. Chaque carte
             porte badge => nom de la séance, avec le fil Programme › Cours
             affiché en en-tête de son groupe. data-type="adventure" reste
             consommé par le même filtre générique déjà existant. -->
        <div class="rkb2__section" data-rkb-section data-adventures-section>
            <?php foreach ( $adventures_by_program as $program_slug => $program ) : ?>
            <!-- v9.30 — .rkb2__adv-group est maintenant le niveau
                 PROGRAMME (data-program posé ici, lu par le filtre JS
                 via card.closest('.rkb2__adv-group') — aucun changement
                 JS nécessaire, closest() retrouve ce même ancêtre même
                 avec les cours désormais imbriqués à l'intérieur). -->
            <div class="rkb2__adv-group" data-program="<?php echo esc_attr( $program_slug ); ?>">
                <?php if ( $program['program_name'] ) : ?>
                <!-- v9.30 — Titre de PROGRAMME centré, affiché UNE SEULE
                     FOIS pour tous ses cours (remplace l'ancien en-tête
                     répété à chaque cours). -->
                <p class="rkb2__adv-heading-program"><?php echo esc_html( $program['program_name'] ); ?></p>
                <?php endif; ?>

            <?php foreach ( $program['courses'] as $course_id => $course ) : ?>
                <div class="rkb2__adv-course">
                    <!-- v9.30 — Sous-titre de COURS centré, un par cours
                         à l'intérieur du programme (remplace
                         .rkb2__adv-heading, désormais scindé en
                         .rkb2__adv-heading-program niveau programme +
                         .rkb2__adv-heading-course niveau cours). -->
                    <p class="rkb2__adv-heading-course"><?php echo esc_html( $course['course_title'] ); ?></p>
                    <div class="rkb1__grid" data-rk-stagger>
                        <?php foreach ( $course['sessions'] as $adv ) :
                            $earned = $adv['completed'];
                        ?>
                        <div class="rkb2__card <?php echo $earned ? 'is-earned' : 'is-locked'; ?>"
                           data-rkb-card
                           data-earned="<?php echo $earned ? '1' : '0'; ?>"
                           data-new="0"
                           data-type="adventure"
                           style="--cat:#2563eb">
                            <div class="rkb2__card-icon-wrap">
                                <?php if ( $adv['thumbnail'] ) : ?>
                                <img src="<?php echo esc_url( $adv['thumbnail'] ); ?>" alt="" class="rkb2__card-icon" loading="lazy" width="120" height="120">
                                <?php else : ?>
                                <img src="<?php echo esc_url( $badge_img_url ); ?>" alt="" class="rkb2__card-icon" loading="lazy" width="120" height="120">
                                <?php endif; ?>
                            </div>
                            <p class="rkb2__card-name"><?php echo esc_html( $adv['session_title'] ); ?></p>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- État vide résultant d'un filtrage trop restrictif — géré en JS -->
        <div class="rkb2__no-results" data-rkb-no-results hidden>
            <?php esc_html_e( 'لا توجد شارات تطابق هذا الفلتر حاليًا.', 'rk-my-children' ); ?>
        </div>
    </div>

</div>