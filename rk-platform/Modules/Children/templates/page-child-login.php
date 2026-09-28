<?php
/**
 * Template standalone — Page login enfant RiadaKids (/connexion-child/).
 *
 * Bypasse complètement le thème (pas de get_header/get_footer).
 * Le formulaire POST → admin-post.php → RK_MC_Child_User::handle_child_login().
 *
 * @package RK_My_Children
 */
defined( 'ABSPATH' ) || exit;

rkp_no_cache_page( 'rk_child_login_page' );

/* ── Rediriger selon le RÔLE (pas current_user_can) ────
 * current_user_can() est filtré par Tutor LMS et retourne
 * true pour les admins → on lit $user->roles directement. */
if ( is_user_logged_in() ) {
    $current_roles = (array) wp_get_current_user()->roles;
    if ( in_array( 'rk_child', $current_roles, true ) ) {
        /* Enfant déjà connecté → son dashboard */
        wp_safe_redirect( home_url( rtrim( RK_TUTOR_DASHBOARD_URL, '/' ) . '/' ) );
        exit;
    }
    if ( in_array( 'tutor_instructor', $current_roles, true )
         || in_array( 'rk_coach', $current_roles, true ) ) {
        /* Coach connecté → son espace */
        wp_safe_redirect( home_url( '/espace-coach/' ) );
        exit;
    }
    /* Admin, parent, customer → afficher le formulaire.
     * Le parent vient ici pour ouvrir la session de son enfant. */
}

/* ── Messages ─────────────────────────────────────── */
// phpcs:disable WordPress.Security.NonceVerification
$error_code    = isset( $_GET['login_error'] ) ? sanitize_key( $_GET['login_error'] ) : '';
$logged_out    = ! empty( $_GET['logged_out'] );
$retry_in      = isset( $_GET['retry_in'] )      ? absint( $_GET['retry_in'] )      : 0;
$attempts_left = isset( $_GET['attempts_left'] ) ? absint( $_GET['attempts_left'] ) : 0;
// phpcs:enable

$errors = [
    'invalid_credentials' => 'الاسم أو الرمز غير صحيح. اطلب مساعدة ولي أمرك.',
    'empty_fields'        => 'الرجاء إدخال اسمك والرمز السري.',
    'invalid_nonce'       => 'انتهت صلاحية الطلب. أعد المحاولة.',
    'too_many_attempts'   => sprintf(
        'محاولات كثيرة جداً. حاول مرة أخرى بعد %d دقيقة.',
        max( 1, $retry_in ?: 15 )
    ),
    'pin_unreadable'      => 'تعذّر التحقق من رمزك. يرجى التواصل مع ولي أمرك لإعادة تعيينه.',
    'ambiguous_child'     => 'يوجد أكثر من حساب بنفس الاسم والرمز. يرجى التواصل مع ولي أمرك.',
    'session_unavailable' => 'حدث خطأ تقني مؤقت. يرجى إعادة المحاولة خلال لحظات.',
];

/* Compteur d'essais restants (uniquement sur mauvais identifiants) */
$attempts_note = ( 'invalid_credentials' === $error_code && $attempts_left > 0 )
    ? sprintf( 'تبقّى لديك %d محاولات.', $attempts_left )
    : '';

/* ── Enfants du parent connecté (évite toute saisie du nom) ── */
$rk_children = array();
if ( is_user_logged_in() && class_exists( 'RK_MC_Child_Repository' ) ) {
    $rk_children = array_values( array_filter(
        /* 6b-3 (site D 2) — liste les enfants DU PARENT connecte. */
        RK_MC_Child_Repository::get_children(
            class_exists( 'RK_Identity_Context' )
                ? RK_Identity_Context::authenticated_parent_id()
                : get_current_user_id()
        ),
        static function ( $c ) { return ! empty( $c->child_name ); }
    ) );
}

/** Libellé complet « prénom + nom de famille » pour un enfant. */
$rk_full_name = static function ( $c ) {
    return trim( (string) $c->child_name . ' ' . (string) ( $c->child_family_name ?? '' ) );
};

$logo_img = esc_url( apply_filters(
    'rk_child_login_logo',
    'https://riadakids.com/wp-content/uploads/2026/01/logo-1.webp'
) );

/* Image d'illustration (colonne gauche) — filtrable */
$hero_img = esc_url( apply_filters(
    'rk_child_login_hero_image',
    RK_MC_URL . 'assets/img/child-login-hero.webp'
) );

/* Logos partenaires (colonne droite, bas) — filtrables */
$partner_logos = apply_filters( 'rk_child_login_partners', [
    [
        'img' => RK_MC_URL . 'assets/img/meldomind-logo.png',
        'alt' => 'MeldoMind Group',
    ],
    [
        'img' => RK_MC_URL . 'assets/img/logo-riadakids.webp',
        'alt' => 'Riada Kids',
    ],
] );
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?> class="rk-child-login-html">
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex,nofollow">
<title><?php esc_html_e( 'فضاء الأطفال', 'rk-my-children' ); ?> — RiadaKids</title>
<?php
$rk_clb_css_path = RK_MC_DIR . 'assets/css/child-login.css';
$rk_clb_css_ver  = file_exists( $rk_clb_css_path ) ? filemtime( $rk_clb_css_path ) : RK_MC_VERSION;

/*
 * CSS inliné directement dans le <head>, au lieu d'un <link> externe.
 *
 * Pourquoi : un <link rel="stylesheet"> déclenche une requête réseau
 * séparée. Entre le moment où le HTML arrive (carte à sa taille brute,
 * sans layout) et le moment où cette requête répond, le navigateur peut
 * peindre une frame intermédiaire non stylée — c'est le flash visible
 * sur mobile/connexion lente/cache froid (voir capture : carte minuscule
 * en haut à gauche avant application du split-screen centré).
 *
 * En inlinant le CSS dans le <head>, il n'existe plus de fenêtre entre
 * "HTML reçu" et "CSS appliqué" : le navigateur ne peut pas peindre
 * avant d'avoir tout le <head>, donc pas de flash possible. Le fichier
 * ~13 Ko reste largement sous le coût d'une requête bloquante évitée.
 */
if ( $rk_clb_css_path && file_exists( $rk_clb_css_path ) ) {
    echo '<style id="rk-clb-critical-css">';
    echo file_get_contents( $rk_clb_css_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
    echo '</style>';
} else {
    // Filet : si le fichier est introuvable (déploiement cassé), on retombe
    // sur le lien externe plutôt que d'afficher la page sans aucun style.
    echo '<link rel="stylesheet" href="' . esc_url( RK_MC_URL . 'assets/css/child-login.css' ) . '?v=' . esc_attr( $rk_clb_css_ver ) . '">';
}
?>
<?php
/* ── Isolation CSS : cette page est standalone, on ne veut pas
 * que le thème / Elementor / plugins tiers injectent des styles
 * globaux (input, h1, button, etc.) qui écrasent child-login.css
 * en s'affichant après elle via wp_head(). On dé-file tout ce qui
 * n'est pas indispensable avant de déclencher wp_head(). */
global $wp_styles, $wp_scripts;

if ( $wp_styles instanceof WP_Styles ) {
    foreach ( $wp_styles->queue as $handle ) {
        wp_dequeue_style( $handle );
        wp_deregister_style( $handle );
    }
}

if ( $wp_scripts instanceof WP_Scripts ) {
    foreach ( $wp_scripts->queue as $handle ) {
        // On garde jquery au cas où un script inline du thème en dépendrait encore.
        if ( 'jquery' === $handle || 'jquery-core' === $handle || 'jquery-migrate' === $handle ) {
            continue;
        }
        wp_dequeue_script( $handle );
        wp_deregister_script( $handle );
    }
}

wp_head();
?>
</head>
<body class="rk-child-login-body">

    <!-- Carte centrale split-screen -->
    <div class="rk-clb-card">

        <!-- Colonne image -->
        <div class="rk-clb-media">
            <img src="<?php echo $hero_img; ?>" alt="<?php esc_attr_e( 'فضاء الأطفال RiadaKids', 'rk-my-children' ); ?>" loading="eager">
        </div>

        <!-- Colonne formulaire -->
        <div class="rk-clb-form-col">
            <div class="rk-clb-form-inner">

                <!-- En-tête -->
                <div class="rk-clb-head">
                    <h1><?php esc_html_e( 'أهلاً يا بطل !', 'rk-my-children' ); ?></h1>
                    <p><?php esc_html_e( 'أدخل اسمك ورمزك السري للدخول إلى عالمك', 'rk-my-children' ); ?></p>
                </div>

                <!-- Alerte succès déconnexion -->
                <?php if ( $logged_out ) : ?>
                <div class="rk-clb-alert rk-clb-alert--success" role="status">
                    <span>✅</span>
                    <span><?php esc_html_e( 'تم تسجيل خروجك. إلى اللقاء يا بطل! 👋', 'rk-my-children' ); ?></span>
                </div>
                <?php elseif ( $error_code && isset( $errors[ $error_code ] ) ) : ?>
                <!-- Alerte erreur -->
                <div class="rk-clb-alert rk-clb-alert--error" role="alert">
                    <span>⚠️</span>
                    <span>
                        <?php echo esc_html( $errors[ $error_code ] ); ?>
                        <?php if ( $attempts_note ) : ?>
                            <br><small><?php echo esc_html( $attempts_note ); ?></small>
                        <?php endif; ?>
                    </span>
                </div>
                <?php endif; ?>

                <!-- Formulaire -->
                <?php
                rkp_log( sprintf(
                    '[CHILD LOGIN] page rendered | current_user_id=%d | session_token_hash=%s',
                    get_current_user_id(),
                    substr( md5( (string) wp_get_session_token() ), 0, 8 )
                ) );
                ?>
                <form method="post"
                      action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
                      data-nonce-endpoint="<?php echo esc_url( admin_url( 'admin-ajax.php?action=rk_child_login_nonce' ) ); ?>"
                      id="rk-child-login-form"
                      novalidate>

                    <input type="hidden" name="action" value="rk_child_username_pin_login">
                    <?php
                    /*
                     * v10.8 — Le nonce N'EST PLUS imprimé statiquement dans
                     * ce HTML. C'était la vraie cause du bug "1er essai
                     * échoue, 2e réussit" : si cette page est servie depuis
                     * un cache de page serveur (LiteSpeed/QUIC.cloud/
                     * NitroPack/Cloudflare), le nonce ci-dessous aurait
                     * correspondu à un rendu antérieur — jamais à l'état
                     * réel du serveur au moment du POST.
                     *
                     * Le champ est maintenant VIDE au rendu, et rempli
                     * dynamiquement en JS juste avant la première
                     * interaction (voir le script en bas de page), via
                     * l'endpoint dédié /wp-admin/admin-ajax.php?action=
                     * rk_child_login_nonce — jamais mis en cache, donc
                     * TOUJOURS synchronisé avec l'état réel du serveur.
                     *
                     * Le POST final reste un formulaire HTML natif inchangé
                     * vers admin-post.php ; seule la SOURCE du nonce change.
                     * wp_verify_nonce() au serveur reste l'unique validateur.
                     */
                    ?>
                    <input type="hidden" name="rk_child_login_nonce" id="rk_child_login_nonce" value="">

                    <!-- Username (اسم المستخدم) -->
                    <div class="rk-clb-field">
                        <label for="rk_clb_username"><?php esc_html_e( 'اسم المستخدم', 'rk-my-children' ); ?></label>

                        <?php if ( count( $rk_children ) > 1 ) : ?>
                            <?php /* Parent connecté, plusieurs enfants : dropdown usernames */ ?>
                            <select id="rk_clb_username" name="child_username" required>
                                <option value=""><?php esc_html_e( 'اختر اسم المستخدم', 'rk-my-children' ); ?></option>
                                <?php foreach ( $rk_children as $rk_c ) : ?>
                                    <option value="<?php echo esc_attr( (string) ( $rk_c->child_username ?? '' ) ); ?>">
                                        <?php echo esc_html( (string) ( $rk_c->child_username ?? $rk_full_name( $rk_c ) ) ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>

                        <?php elseif ( count( $rk_children ) === 1 ) : ?>
                            <?php /* Un seul enfant : username pré-rempli */ ?>
                            <input type="hidden" name="child_username"
                                   value="<?php echo esc_attr( (string) ( $rk_children[0]->child_username ?? '' ) ); ?>">
                            <p class="rk-clb-child-name">
                                <?php echo esc_html( (string) ( $rk_children[0]->child_username ?? $rk_full_name( $rk_children[0] ) ) ); ?> 👋
                            </p>

                        <?php else : ?>
                            <?php /* Aucune session parent : saisie libre du username */ ?>
                            <input type="text"
                                   id="rk_clb_username"
                                   name="child_username"
                                   placeholder="<?php esc_attr_e( 'أدخل اسم المستخدم', 'rk-my-children' ); ?>"
                                   autocomplete="off"
                                   dir="ltr"
                                   required>
                        <?php endif; ?>
                    </div>

                    <!-- PIN (champ unique, 4 chiffres) -->
                    <div class="rk-clb-field">
                        <label for="rk_clb_pin"><?php esc_html_e( 'رمزك السري', 'rk-my-children' ); ?></label>
                        <input type="password"
                               id="rk_clb_pin"
                               name="child_pin"
                               placeholder="<?php esc_attr_e( 'أدخل رمزك السري', 'rk-my-children' ); ?>"
                               inputmode="numeric"
                               pattern="[0-9]*"
                               maxlength="4"
                               autocomplete="off"
                               required>
                    </div>

                    <!-- Submit -->
                    <button type="submit" class="rk-clb-submit" id="rk-clb-submit-btn" disabled>
                        <span class="rk-clb-submit-label" id="rk-clb-submit-label-prep">
                            <?php esc_html_e( 'جارٍ التحضير...', 'rk-my-children' ); ?>
                        </span>
                        <span class="rk-clb-submit-label" id="rk-clb-submit-label-ready" style="display:none;">
                            <?php esc_html_e( 'أدخل إلى عالمك', 'rk-my-children' ); ?>
                        </span>
                        <span class="rk-clb-spinner" style="display:none;"></span>
                    </button>

                </form>

                <!-- Lien retour -->
                <div class="rk-clb-back">
                    <?php esc_html_e( 'أنت ولي أمر؟', 'rk-my-children' ); ?>
                    <a href="<?php echo esc_url( home_url( '/my-account/' ) ); ?>">
                        <?php esc_html_e( 'تسجيل دخول الوالدين', 'rk-my-children' ); ?>
                    </a>
                </div>

                <!-- Logos partenaires -->
                <?php if ( ! empty( $partner_logos ) ) : ?>
                <div class="rk-clb-partners">
                    <?php foreach ( $partner_logos as $partner ) : ?>
                        <img src="<?php echo esc_url( $partner['img'] ); ?>"
                             alt="<?php echo esc_attr( $partner['alt'] ); ?>"
                             loading="lazy">
                    <?php endforeach; ?>
                </div>
                <p class="rk-clb-partners-note">
                    <?php esc_html_e( 'ريادة كيدز بالتعاون مع ميلدو مايند', 'rk-my-children' ); ?>
                </p>
                <?php endif; ?>

            </div><!-- /.rk-clb-form-inner -->
        </div><!-- /.rk-clb-form-col -->

    </div><!-- /.rk-clb-card -->

<?php wp_footer(); ?>
<script>
(function() {
    'use strict';
    var form        = document.getElementById('rk-child-login-form');
    var btn         = document.getElementById('rk-clb-submit-btn');
    var labelPrep   = document.getElementById('rk-clb-submit-label-prep');
    var labelReady  = document.getElementById('rk-clb-submit-label-ready');
    var nonceField  = document.getElementById('rk_child_login_nonce');
    var pin         = document.getElementById('rk_clb_pin');

    /*
     * v10.8 — État réel nonceReady, PAS un délai arbitraire.
     *
     * Le bouton reste désactivé (attribut HTML "disabled", posé au rendu
     * PHP ci-dessus) jusqu'à la résolution effective de cette requête —
     * jamais avant, quel que soit le temps que ça prend. Aucun setTimeout.
     *
     * L'endpoint est appelé UNE SEULE FOIS au chargement de la page, avant
     * toute interaction utilisateur possible : le nonce est donc déjà prêt
     * bien avant que l'utilisateur n'ait fini de saisir son code, dans la
     * grande majorité des cas réels — sans latence perceptible.
     */
    var nonceReady = false;

    function ajaxUrl() {
        return form.getAttribute('data-nonce-endpoint');
    }

    function fetchFreshNonce() {
        return fetch(ajaxUrl(), {
            method:      'GET',
            credentials: 'same-origin',
            cache:       'no-store'
        })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            if (!res || !res.success || !res.data || !res.data.nonce) {
                throw new Error('nonce_fetch_failed');
            }
            nonceField.value = res.data.nonce;
            nonceReady = true;
            btn.disabled = false;
            if (labelPrep)  labelPrep.style.display  = 'none';
            if (labelReady) labelReady.style.display = '';
            // Log console uniquement — pas de requête réseau supplémentaire,
            // pour ne pas fausser le compte "nonce endpoint: 1" attendu.
            if (window.console) console.log('[CHILD LOGIN] nonce ready');
        })
        .catch(function () {
            /*
             * Repli : si l'endpoint échoue (réseau, JS bloqué par un
             * bloqueur de script, etc.), on ne bloque pas indéfiniment
             * l'utilisateur — le POST se fera avec un nonce potentiellement
             * absent et échouera proprement côté serveur avec le message
             * "انتهت صلاحية الطلب" existant, plutôt que de laisser un
             * formulaire à jamais désactivé. C'est une dégradation vers le
             * comportement précédent, pas une aggravation.
             */
            nonceReady = true;
            btn.disabled = false;
            if (labelPrep)  labelPrep.style.display  = 'none';
            if (labelReady) labelReady.style.display = '';
        });
    }

    // Restreindre la saisie PIN aux chiffres uniquement
    if (pin) {
        pin.addEventListener('input', function () {
            this.value = this.value.replace(/\D/g, '').slice(0, 4);
        });
    }

    if (form && btn && nonceField) {
        fetchFreshNonce();

        form.addEventListener('submit', function(e) {
            // Le bouton est physiquement désactivé tant que nonceReady est
            // faux ; ce garde protège aussi la soumission par touche Entrée.
            if (!nonceReady) {
                e.preventDefault();
                return;
            }

            // Récupérer le username (input, select ou hidden pré-rempli)
            var usernameEl = form.querySelector('[name="child_username"]');
            var username   = usernameEl ? usernameEl.value.trim() : '';
            var code = pin ? pin.value.trim() : '';

            // Valider: username non-vide ET PIN = 4 chiffres
            if (!username || code.length !== 4) {
                e.preventDefault();
                return;
            }

            // Anti double-submit : désactive AVANT l'envoi, pas de retry
            // automatique quel que soit le résultat serveur ensuite.
            btn.disabled = true;
            btn.classList.add('loading');
            if (labelReady) labelReady.style.display = 'none';
            var spinner = btn.querySelector('.rk-clb-spinner');
            if (spinner) spinner.style.display = 'inline-block';
        });
    }
})();
</script>
</body>
</html>