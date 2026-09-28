<?php
/**
 * Composant : Carte enfant simplifiée  (v11.0 — Username/PIN + Dashboard + Dropdown)
 *
 * AJUSTEMENT (demande utilisateur) — restructuration complète :
 *   - Username + code PIN (4 chiffres) affichés sous le nom, même source
 *     que la page /my-account/child-profile/ (RK_MC_Child_User::
 *     get_child_pin(), voir sa doc — génère le PIN au besoin, jamais
 *     stocké en clair).
 *   - Bouton unique "الدخول للوحة التحكم" (URL du VRAI dashboard enfant,
 *     RK_MC_Child_Context::get_dashboard_url() — distinct de
 *     get_full_profile_url(), qui menait à la page lecture-seule parent
 *     /child-profile/, retirée d'ici).
 *   - تعديل/حذف/عرض regroupés dans un menu déroulant (au lieu des 2
 *     icônes visibles + lien texte séparé) — mêmes handlers JS
 *     (.rk-edit-btn/.rk-delete-btn déjà câblés par child-edit.js/
 *     child-delete.js, data-id inchangé), "عرض" pointe vers l'ancienne
 *     URL "الملف الكامل" (fiche complète lecture seule, toujours utile
 *     depuis ce menu secondaire).
 *
 * Variables héritées : $child (stdClass)
 *
 * @package RK_My_Children
 * @since   11.0.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( empty( $child ) || ! is_object( $child ) || empty( $child->id ) ) return;

$avatar_url   = rk_mc_get_avatar_url( $child );
$child_id     = (int) $child->id;
$child_wp_uid = (int) ( $child->wp_user_id ?? 0 );

/* ── URL « الملف الكامل » : nouvelle page parent en lecture seule ── */
$full_profile_url = class_exists( 'RK_MC_Child_Context' )
    ? RK_MC_Child_Context::get_full_profile_url( $child_id )
    : add_query_arg( 'child_id', $child_id, wc_get_account_endpoint_url( 'child-profile' ) );

/* ── URL « الدخول للوحة التحكم » : vrai espace enfant ── */
$dashboard_url = class_exists( 'RK_MC_Child_Context' )
    ? RK_MC_Child_Context::get_dashboard_url( $child_id )
    : '';

/* ── URL « التقارير » : /my-account/rk-rapport/?child_id=X ──
 * FIX (bug signalé — "pourquoi éliminer button rapport de card") — ce
 * bouton n'avait jamais été demandé à retirer (seul "الملف الكامل"
 * l'était) ; il avait disparu par erreur en même temps lors de la
 * réduction de .rk-scard-simple-actions à un seul bouton. Rétabli ici,
 * à côté du bouton dashboard (voir .rk-scard-simple-actions plus bas). */
$rapport_url = class_exists( 'RK_MC_Endpoint' )
    ? add_query_arg( 'child_id', $child_id, wc_get_account_endpoint_url( RK_MC_Endpoint::RAPPORT_SLUG ) )
    : '';

/* ── Username + PIN (identifiants de connexion enfant) ── */
$child_username = (string) ( $child->child_username ?? '' );
$child_pin       = ( $child_wp_uid && class_exists( 'RK_MC_Child_User' ) )
    ? RK_MC_Child_User::get_child_pin( $child_wp_uid )
    : '';

/* ── Badge : notifications non lues de l'enfant ── */
$unread_count = ( $child_wp_uid && class_exists( 'RK_MC_Notification_Service' ) )
    ? (int) RK_MC_Notification_Service::get_unread_count( $child_wp_uid )
    : 0;
?>
<div class="rk-child-card-final rk-child-card-simple"
     data-id="<?php echo esc_attr( $child->id ); ?>"
     data-name="<?php echo esc_attr( $child->child_name ); ?>"
     data-family="<?php echo esc_attr( $child->child_family_name ?? '' ); ?>"
     data-age="<?php echo esc_attr( $child->child_age ); ?>"
     data-avatar="<?php echo esc_attr( $child->avatar_url ?? '' ); ?>">

    <!-- ── Menu déroulant تعديل/حذف/عرض ─────────────────────────── -->
    <div class="rk-scard-more">
        <button type="button" class="rk-scard-more-toggle"
                aria-haspopup="true" aria-expanded="false"
                aria-label="<?php esc_attr_e( 'المزيد من الإجراءات', 'rk-my-children' ); ?>">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <circle cx="12" cy="5" r="1"/><circle cx="12" cy="12" r="1"/><circle cx="12" cy="19" r="1"/>
            </svg>
        </button>
        <div class="rk-scard-more-menu" role="menu" hidden>
            <a href="<?php echo esc_url( $full_profile_url ); ?>" class="rk-scard-more-item" role="menuitem">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8Z"/><circle cx="12" cy="12" r="3"/>
                </svg>
                <span><?php esc_html_e( 'عرض', 'rk-my-children' ); ?></span>
            </a>
            <button type="button" class="rk-scard-more-item rk-edit-btn" data-id="<?php echo esc_attr( $child->id ); ?>" role="menuitem">
                <?php echo rk_mc_svg( 'edit' ); ?>
                <span><?php esc_html_e( 'تعديل', 'rk-my-children' ); ?></span>
            </button>
            <button type="button" class="rk-scard-more-item rk-scard-more-item--danger rk-delete-btn" data-id="<?php echo esc_attr( $child->id ); ?>" role="menuitem">
                <?php echo rk_mc_svg( 'delete' ); ?>
                <span><?php esc_html_e( 'حذف', 'rk-my-children' ); ?></span>
            </button>
        </div>
    </div>

    <!-- ── En-tête : Avatar + Nom + Âge (en flex horizontal) ───────── -->
    <div class="rk-scard-simple-header">
        <a href="<?php echo esc_url( $full_profile_url ); ?>" class="rk-scard-simple-avatar" aria-label="<?php echo esc_attr( sprintf(
            /* translators: %s: nom de l'enfant */
            __( 'عرض الملف الكامل لـ %s', 'rk-my-children' ),
            rk_mc_child_full_name( $child )
        ) ); ?>">
            <img src="<?php echo esc_url( $avatar_url ); ?>"
                 alt="<?php echo esc_attr( rk_mc_child_full_name( $child ) ); ?>"
                 width="72" height="72" loading="lazy" decoding="async">
            <?php if ( $unread_count > 0 ) : ?>
            <span class="rk-scard-simple-badge"><?php echo (int) min( 99, $unread_count ); ?></span>
            <?php endif; ?>
        </a>

        <!-- ── Nom + Âge ─────────────────────────────────────────── -->
        <div class="rk-scard-simple-identity">
            <a href="<?php echo esc_url( $full_profile_url ); ?>" class="rk-scard-simple-name-link">
                <h3 class="rk-scard-simple-name"><?php echo esc_html( rk_mc_child_full_name( $child ) ); ?></h3>
            </a>
            <?php if ( ! empty( $child->child_age ) ) : ?>
            <span class="rk-cmc-age"><?php echo (int) $child->child_age; ?> <?php esc_html_e( 'سنوات', 'rk-my-children' ); ?></span>
            <?php endif; ?>
        </div>
    </div>

    <!-- ── Username + PIN ────────────────────────────────────────── -->
    <?php if ( $child_username || $child_pin ) : ?>
    <div class="rk-scard-simple-login">
        <?php if ( $child_username ) : ?>
        <span class="rk-scard-simple-login-item">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>
            </svg>
            <?php echo esc_html( $child_username ); ?>
        </span>
        <?php endif; ?>
        <?php if ( $child_pin ) : ?>
        <span class="rk-scard-simple-login-item">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>
            </svg>
            <?php echo esc_html( $child_pin ); ?>
        </span>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- ── Actions principales : dashboard + rapports ──────────────── -->
    <div class="rk-scard-simple-actions">
        <?php if ( $dashboard_url ) : ?>
        <a href="<?php echo esc_url( $dashboard_url ); ?>" class="rk-action-btn rk-action-btn--dashboard">
            <?php esc_html_e( 'الدخول للوحة التحكم', 'rk-my-children' ); ?>
        </a>
        <?php endif; ?>
        <?php if ( $rapport_url ) : ?>
        <a href="<?php echo esc_url( $rapport_url ); ?>" class="rk-action-btn rk-action-btn--reports">
            <?php esc_html_e( 'التقارير', 'rk-my-children' ); ?>
        </a>
        <?php endif; ?>
    </div>

</div>
