<?php
declare( strict_types=1 );
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Extrait de class-rk-mc-tutor-dashboard.php — factorisation par fonctionnalité.
 * Code déplacé verbatim, aucune modification de logique.
 */
trait RK_MC_Tutor_Dash_Nav {
    /* ═══════════════════════════════════════════════════════════════════
       MOBILE BOTTOM NAV  (after Tutor wrap)
       ═══════════════════════════════════════════════════════════════════ */

    public static function render_mobile_nav(): void {
        $d     = self::load_data();
        $child = $d['child'] ?? null;
        if ( ! $child ) return;

        $base         = home_url( RK_TUTOR_DASHBOARD_URL );
        $cp           = self::child_param();
        $unread       = (int) ( $d['bm_unread']    ?? 0 );
        $notif_unread = (int) ( $d['notif_unread'] ?? 0 );
        $is_child     = RK_MC_Child_Restrictions::is_child_user();

        /* v8.4 — Le contexte enfant utilise #rkd4-botnav (chrome v4) sur
         * toutes les vues : on n'imprime plus l'ancienne .rkv2-bottom-nav. */
        if ( class_exists( 'RK_MC_Dashboard_Chrome_V4' ) ) return;

        global $wp_query;
        $current_slug = $wp_query->query_vars['tutor_dashboard_page'] ?? '';

        if ( $is_child ) {
            $nav_items = array(
                array( 'slug' => '',                'emoji' => '🏠', 'url' => $base . $cp,                        'label' => 'الرئيسية'    ),
                array( 'slug' => 'enrolled-courses','emoji' => '🗺️', 'url' => $base . 'enrolled-courses/' . $cp,  'label' => 'مغامراتي'   ),
                array( 'slug' => 'rk-sessions',     'emoji' => '📅', 'url' => $base . 'rk-sessions/' . $cp,       'label' => 'لقاءات'     ),
                array( 'slug' => 'rk-badges',       'emoji' => '🏆', 'url' => $base . 'rk-badges/' . $cp,         'label' => 'الشارات'    ),
                array( 'slug' => 'question-answer', 'emoji' => '❓', 'url' => $base . 'question-answer/' . $cp,   'label' => 'سؤال وجواب' ),
                array( 'slug' => 'my-quiz-attempts','emoji' => '📝', 'url' => $base . 'my-quiz-attempts/' . $cp, 'label' => 'الاختبارات' ),
            );
        } else {
            $nav_items = array(
                array( 'slug' => '',            'emoji' => '🏠', 'url' => $base . $cp,                   'label' => 'الرئيسية' ),
                array( 'slug' => 'rk-messages', 'emoji' => '💬', 'url' => $base . 'rk-messages/' . $cp, 'label' => 'الرسائل',
                       'badge' => $unread + $notif_unread ),
            );
        }
        ?>
        <?php if ( $is_child ) :
            $total_unread  = $unread + $notif_unread;
            $msg_url       = $base . 'rk-messages/' . $cp;
        ?>
        <a href="<?php echo esc_url( $msg_url ); ?>"
           class="rkv2-float-msg<?php echo $total_unread > 0 ? ' has-unread' : ''; ?>"
           aria-label="<?php esc_attr_e( 'الرسائل', 'rk-my-children' ); ?>"
           title="<?php esc_attr_e( 'الرسائل', 'rk-my-children' ); ?>">
            <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/>
            </svg>
            <?php if ( $total_unread > 0 ) : ?>
            <span class="rkv2-float-msg-badge"><?php echo min( $total_unread, 9 ); ?><?php echo $total_unread > 9 ? '+' : ''; ?></span>
            <?php endif; ?>
        </a>
        <?php endif; ?>

        <nav class="rkv2-bottom-nav" dir="rtl" aria-label="<?php esc_attr_e( 'التنقل', 'rk-my-children' ); ?>">
            <?php foreach ( $nav_items as $item ) :
                $active = $current_slug === $item['slug'];
            ?>
            <a href="<?php echo esc_url( $item['url'] ); ?>"
               class="rkv2-bottom-nav-item<?php echo $active ? ' active' : ''; ?>"
               aria-current="<?php echo $active ? 'page' : 'false'; ?>"
               aria-label="<?php echo esc_attr( $item['label'] ); ?>">
                <span class="rkv2-bottom-nav-emoji"><?php echo $item['emoji']; ?></span>
                <span><?php echo esc_html( $item['label'] ); ?></span>
                <?php if ( ! empty( $item['badge'] ) && (int) $item['badge'] > 0 ) : ?>
                <span class="rkv2-nav-badge"><?php echo (int) $item['badge']; ?></span>
                <?php endif; ?>
            </a>
            <?php endforeach; ?>
        </nav>
        <?php
    }

    /* ═══════════════════════════════════════════════════════════════════
       MOBILE NAV PARENT  (after Tutor wrap — vue parent sans enfant)
       ═══════════════════════════════════════════════════════════════════ */

    public static function render_parent_mobile_nav(): void {
        $base = home_url( RK_TUTOR_DASHBOARD_URL );
        global $wp_query;
        $current_slug = $wp_query->query_vars['tutor_dashboard_page'] ?? '';

        $nav_items = array(
            array( 'slug' => '',               'url' => $base,                      'emoji' => '🏠', 'label' => 'الرئيسية' ),
            array( 'slug' => 'enrolled-courses','url' => $base . 'enrolled-courses/','emoji' => '🗺️', 'label' => 'دوراتي'   ),
            array( 'slug' => 'bookings',        'url' => $base . 'bookings/',         'emoji' => '📅', 'label' => 'لقاءات'  ),
            array( 'slug' => 'reports',       'url' => $base . 'reports/',        'emoji' => '📊', 'label' => 'التقارير' ),
        );
        ?>
        <nav class="rk-mobile-nav" dir="rtl" aria-label="<?php esc_attr_e( 'التنقل', 'rk-my-children' ); ?>">
            <?php foreach ( $nav_items as $item ) :
                $active = $current_slug === $item['slug'];
            ?>
                <a href="<?php echo esc_url( $item['url'] ); ?>"
                   class="rk-mobile-nav__item <?php echo $active ? 'rk-mobile-nav__item--active' : ''; ?>"
                   aria-current="<?php echo $active ? 'page' : 'false'; ?>">
                    <div class="rk-mobile-nav__icon-wrap">
                        <span style="font-size:1.3rem;line-height:1;"><?php echo $item['emoji']; ?></span>
                    </div>
                    <span class="rk-mobile-nav__label"><?php echo esc_html( $item['label'] ); ?></span>
                </a>
            <?php endforeach; ?>
        </nav>
        <?php
    }

    /* ═══════════════════════════════════════════════════════════════════
       NAV FILTER  — Remplace TOUS les items quand child actif
       ═══════════════════════════════════════════════════════════════════ */

    public static function filter_nav( array $items ): array {
        if ( ! is_user_logged_in() ) return $items;
        if ( class_exists( 'RK_Coach_Dashboard' ) && RK_Coach_Dashboard::is_coach() ) return $items;

        $child = self::resolve_child();
        $base  = home_url( RK_TUTOR_DASHBOARD_URL );

        /* ── Nav ENFANT ─────────────────────────────────────────────────── */
        if ( $child ) {
            $cp       = self::child_param();
            $d        = self::load_data();
            $unread   = (int) ( $d['bm_unread'] ?? 0 );
            $is_child = RK_MC_Child_Restrictions::is_child_user();

            $new = array();
            $new['index']            = array( 'title' => 'الرئيسية',        'icon' => 'tutor-icon-dashboard',     'url' => $base . '?child_id=' . (int) $child->id );
            $new['sep-learn']        = array( 'title' => 'تعلّمي',          'type' => 'separator' );
            $new['enrolled-courses'] = array( 'title' => 'مغامراتي',        'icon' => 'tutor-icon-mortarboard-o', 'url' => $base . 'enrolled-courses/' . $cp );
            $new['question-answer']  = array( 'title' => 'سؤال وجواب',      'icon' => 'tutor-icon-question',      'url' => $base . 'question-answer/' . $cp );
            $new['rk-sessions']      = array( 'title' => 'لقاءات',          'icon' => 'tutor-icon-calendar-line', 'url' => $base . 'rk-sessions/' . $cp );
            $new['my-quiz-attempts'] = array( 'title' => 'الاختبارات',       'icon' => 'tutor-icon-quiz-attempt',  'url' => $base . 'my-quiz-attempts/' . $cp );
            $new['certificates']     = array( 'title' => 'شهاداتي',          'icon' => 'tutor-icon-award',          'url' => $base . 'certificates/' . $cp );
            $new['sep-comm']         = array( 'title' => 'تواصل',            'type' => 'separator' );
            $new['rk-messages']      = array( 'title' => $unread > 0 ? 'الرسائل (' . $unread . ')' : 'الرسائل',
                                              'icon' => 'tutor-icon-message', 'url' => $base . 'rk-messages/' . $cp );
            $new['reviews']          = array( 'title' => 'التقييمات',        'icon' => 'tutor-icon-star-o',        'url' => $base . 'reviews/' . $cp );
            $new['sep-bottom']       = array( 'title' => '',                 'type' => 'separator' );
            if ( ! $is_child ) { $new['settings'] = array( 'title' => 'الإعدادات', 'icon' => 'tutor-icon-gear' ); }
            $new['logout']           = array( 'title' => 'تسجيل الخروج',    'icon' => 'tutor-icon-signout' );
        
        /* Bloquer l'accès direct à l'onglet rk-skills (retiré du dashboard enfant) */
            if ( function_exists( 'tutor_utils' ) ) {
                $current_page = tutor_utils()->get_query_var( 'tutor_dashboard_page' ) ?? '';
                if ( 'rk-skills' === $current_page ) {
                    wp_safe_redirect( $base . '?child_id=' . (int) $child->id );
                    exit;
                }
            }

            return $new;
        }

        /* ── Nav PARENT (sans contexte enfant) ──────────────────────────── */
        $new = array();
        $new['index']            = array( 'title' => 'الرئيسية',     'icon' => 'tutor-icon-dashboard',     'url' => $base );
        $new['sep-courses']      = array( 'title' => 'دوراتي',       'type' => 'separator' );
        $new['enrolled-courses'] = array( 'title' => 'مغامراتي',     'icon' => 'tutor-icon-mortarboard-o', 'url' => $base . 'enrolled-courses/' );
        $new['my-quiz-attempts'] = array( 'title' => 'اختباراتي',    'icon' => 'tutor-icon-quiz-attempt',  'url' => $base . 'my-quiz-attempts/' );
        $new['reviews']          = array( 'title' => 'تقييماتي',     'icon' => 'tutor-icon-chart',         'url' => $base . 'reviews/' );
        $new['wishlist']         = array( 'title' => 'المفضلة',      'icon' => 'tutor-icon-wishlist-line',  'url' => $base . 'wishlist/' );
        $new['question-answer']  = array( 'title' => 'سؤال وجواب',  'icon' => 'tutor-icon-question',      'url' => $base . 'question-answer/' );
        $new['sep-children']     = array( 'title' => 'متابعة الأبناء', 'type' => 'separator' );
        $new['bookings']          = array( 'title' => 'لقاءات',      'icon' => 'tutor-icon-calendar-line', 'url' => $base . 'bookings/' );
        $new['reports']          = array( 'title' => 'التقارير',     'icon' => 'tutor-icon-chart-bar',     'url' => $base . 'reports/' );
        $new['sep-bottom']       = array( 'title' => '',             'type' => 'separator' );
        $new['settings']         = array( 'title' => 'الإعدادات',   'icon' => 'tutor-icon-gear' );
        $new['logout']           = array( 'title' => 'تسجيل الخروج','icon' => 'tutor-icon-signout' );
        return $new;
    }

    /* ═══════════════════════════════════════════════════════════════════
       TEMPLATE LOADER
       ═══════════════════════════════════════════════════════════════════ */

    public static function load_rk_template( string $location ): string {
        if ( $location ) return $location;

        global $wp_query;
        $slug = $wp_query->query_vars['tutor_dashboard_page'] ?? '';

        if ( ! in_array( $slug, self::$rk_slugs, true ) ) return $location;

        // enrolled-courses / courses (slug natif Tutor LMS 4.x, v9.0) : template RK
        // uniquement en contexte enfant (filtre booking). Pour les parents, Tutor LMS
        // affiche ses propres cours nativement.
        $courses_slugs = array( 'enrolled-courses', 'courses' );
        if ( in_array( $slug, $courses_slugs, true ) && ! self::resolve_child() ) {
            return $location;
        }

        // 'courses' (slug natif Tutor 4.x) partage le même template physique que
        // l'alias legacy 'enrolled-courses' — un seul fichier à maintenir.
        $tpl_slug = in_array( $slug, $courses_slugs, true ) ? 'enrolled-courses' : $slug;

        $tpl = RK_MC_DIR . 'templates/dashboard/' . sanitize_file_name( $tpl_slug ) . '.php';
        return file_exists( $tpl ) ? $tpl : $location;
    }

    /* ═══════════════════════════════════════════════════════════════════
       SESSION PERSIST — localStorage save/restore du rk_tab
       Injecté en priorité 0 dans wp_head, AVANT tout contenu.

       Logique :
         • Si rk_tab valide présent + enfant résolu → sauvegarde dans localStorage.
         • Si rk_tab absent + on est sur une sous-page /dashboard/xxx/ →
             restaure depuis localStorage et redirige (invisible).
         • La page racine /dashboard/ n'est jamais auto-restaurée :
             c'est là que le parent choisit l'enfant.
       ═══════════════════════════════════════════════════════════════════ */

    public static function output_session_persist_script(): void {
        if ( ! class_exists( 'RK_Session_Manager' ) ) return;

        $tab_id    = RK_Session_Manager::get_current_tab_id();
        $is_active = $tab_id && ( self::resolve_child() !== null );
        $ls_key    = 'rk_ct_' . substr( md5( home_url() ), 0, 8 );
        $dash_path = '/' . trim( (string) RK_TUTOR_DASHBOARD_URL, '/' ); // ex. /dashboard
        ?>
        <script id="rk-session-persist">
        (function(){
            var K    = <?php echo wp_json_encode( $ls_key ); ?>;
            var DASH = <?php echo wp_json_encode( $dash_path ); ?>;

            // Effacer le token sauvegardé lors d'un clic sur un lien de déconnexion
            document.addEventListener('click', function(e) {
                var el = e.target;
                while (el && el.tagName !== 'A') el = el.parentNode;
                if (!el || el.tagName !== 'A') return;
                var h = el.getAttribute('href') || '';
                if (h.indexOf('rk_child_logout') !== -1 || h.indexOf('action=logout') !== -1) {
                    localStorage.removeItem(K);
                }
            });

            <?php if ( $is_active ) : ?>
            // Page chargée avec rk_tab valide → sauvegarder dans localStorage
            localStorage.setItem(K, <?php echo wp_json_encode( $tab_id ); ?>);
            <?php else : ?>
            // Pas de rk_tab dans l'URL → tenter de restaurer depuis localStorage
            var stored = localStorage.getItem(K);
            if (stored && /^[a-f0-9]{40}$/.test(stored)) {
                var href = window.location.href;
                var path = window.location.pathname.replace(/\/$/, '');
                // Ne restaurer QUE sur les sous-pages (/dashboard/xxx), jamais sur la racine
                // La racine = page de sélection d'enfant pour le parent
                if (path !== DASH && path.indexOf(DASH + '/') === 0 && href.indexOf('rk_tab=') === -1) {
                    document.documentElement.style.visibility = 'hidden';
                    var sep = href.indexOf('?') >= 0 ? '&' : '?';
                    window.location.replace(href + sep + 'rk_tab=' + encodeURIComponent(stored));
                    return;
                }
            }
            <?php endif; ?>
        })();
        </script>
        <?php
    }

    /* ═══════════════════════════════════════════════════════════════════
       BLOCK RESTRICTED PAGES
       ═══════════════════════════════════════════════════════════════════ */

    public static function block_restricted_pages(): void {
        if ( ! RK_MC_Child_Restrictions::is_child_user() ) return;
        if ( ! function_exists( 'tutor_utils' ) ) return;
        if ( ! tutor_utils()->is_tutor_frontend_dashboard() ) return;

        global $wp_query;
        $slug = $wp_query->query_vars['tutor_dashboard_page'] ?? '';

        if ( in_array( $slug, self::$blocked_slugs, true ) ) {
            wp_safe_redirect( home_url( RK_TUTOR_DASHBOARD_URL ) );
            exit;
        }
    }

    /* ═══════════════════════════════════════════════════════════════════
       ASSETS
       ═══════════════════════════════════════════════════════════════════ */

    /* ═══════════════════════════════════════════════════════════════════
       GAMIFIED SIDEBAR — buffer + replace Tutor left-menu on desktop
       ═══════════════════════════════════════════════════════════════════ */

    public static function sidebar_buffer_start(): void {
        ob_start();
    }

    public static function sidebar_buffer_end(): void {
        $html = ob_get_clean();
        if ( ! is_string( $html ) || '' === $html ) return;
        // Strip the Tutor LMS default sidebar; our #rkd3-sidebar is already output by render_topbar().
        $html = (string) preg_replace(
            '/<div[^>]+\btutor-dashboard-left-menu\b[^>]*>[\s\S]*?<\/ul>\s*<\/div>/i',
            '',
            $html
        );
        echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    public static function build_rk_nav_html(): string {
        global $wp_query;
        $slug     = (string) ( $wp_query->query_vars['tutor_dashboard_page'] ?? '' );
        $child    = self::resolve_child();
        $base     = home_url( RK_TUTOR_DASHBOARD_URL );
        $cp       = self::child_param();
        $d             = $child ? self::load_data() : array();
        $unread        = (int) ( $d['bm_unread'] ?? 0 );
        $notif_unread  = (int) ( $d['notif_unread'] ?? 0 );
        $total_unread  = $unread + $notif_unread;
        $is_child      = RK_MC_Child_Restrictions::is_child_user();

        $name    = '';
        $initial = 'ط';
        $avatar  = '';
        $lvl_num = 1;
        $lvl_lbl = '';
        $xp_pct  = 0;
        $points  = 0;
        if ( $child ) {
            $name    = esc_html( $child->first_name ?? $child->child_name ?? '' );
            $initial = esc_html( mb_substr( $name, 0, 1 ) );
            $avatar  = function_exists( 'rk_mc_get_avatar_url' )
                ? esc_url( rk_mc_get_avatar_url( $child ) ) : '';
            $level   = $d['level'] ?? array();
            $lvl_num = (int) ( $level['num'] ?? 1 );
            $lvl_lbl = esc_html( $level['label'] ?? '' );
            $xp_pct  = (int) ( $level['progress'] ?? 0 );
            $points  = (int) ( $d['total_points'] ?? 0 );
        }

        if ( $child ) {
            /* ── Groupes nav ENFANT ─────────────────────────────────────── */
            $groups = array(
                array(
                    'label' => '',
                    'items' => array(
                        array( 'slug' => '', 'url' => $base . '?child_id=' . (int) $child->id,
                               'emoji' => '🏠', 'label' => 'الرئيسية', 'color' => 'orange' ),
                    ),
                ),
                array(
                    'label' => 'تعلّمي',
                    'items' => array(
                        array( 'slug' => 'rk-mon-parcours',  'url' => $base . 'rk-mon-parcours/' . $cp,
                               'emoji' => '🚀', 'label' => 'مساري',         'color' => 'blue'   ),
                        array( 'slug' => 'enrolled-courses', 'url' => $base . 'enrolled-courses/' . $cp,
                               'emoji' => '🗺️', 'label' => 'مغامراتي',    'color' => 'purple' ),
                        array( 'slug' => 'question-answer',  'url' => $base . 'question-answer/' . $cp,
                               'emoji' => '❓', 'label' => 'سؤال وجواب', 'color' => 'red'    ),
                        array( 'slug' => 'rk-sessions',      'url' => $base . 'rk-sessions/' . $cp,
                               'emoji' => '📅', 'label' => 'لقاءات',     'color' => 'blue'   ),
                        array( 'slug' => 'my-quiz-attempts', 'url' => $base . 'my-quiz-attempts/' . $cp,
                               'emoji' => '📝', 'label' => 'الاختبارات', 'color' => 'indigo' ),
                        array( 'slug' => 'certificates',     'url' => $base . 'certificates/' . $cp,
                               'emoji' => '🏆', 'label' => 'شهاداتي',    'color' => 'gold'   ),
                    ),
                ),
                array(
                    'label' => 'تواصل',
                    'items' => array(
                        array( 'slug' => 'rk-messages',    'url' => $base . 'rk-messages/' . $cp,
                               'emoji' => '💬', 'label' => 'الرسائل',        'color' => 'blue', 'badge' => $unread ),
                        array( 'slug' => 'reviews',         'url' => $base . 'reviews/' . $cp,
                               'emoji' => '⭐', 'label' => 'التقييمات',      'color' => 'gold'   ),
                    ),
                ),
            );
        } else {
            /* ── Groupes nav PARENT ─────────────────────────────────────── */
            $groups = array(
                array(
                    'label' => '',
                    'items' => array(
                        array( 'slug' => '', 'url' => $base,
                               'emoji' => '🏠', 'label' => 'الرئيسية', 'color' => 'orange' ),
                    ),
                ),
                array(
                    'label' => 'دوراتي',
                    'items' => array(
                        array( 'slug' => 'enrolled-courses', 'url' => $base . 'enrolled-courses/',
                               'emoji' => '🗺️', 'label' => 'مغامراتي',  'color' => 'purple' ),
                        array( 'slug' => 'my-quiz-attempts', 'url' => $base . 'my-quiz-attempts/',
                               'emoji' => '📝', 'label' => 'اختباراتي', 'color' => 'indigo' ),
                        array( 'slug' => 'reviews',          'url' => $base . 'reviews/',
                               'emoji' => '⭐', 'label' => 'تقييماتي',  'color' => 'gold'   ),
                        array( 'slug' => 'wishlist',         'url' => $base . 'wishlist/',
                               'emoji' => '❤️', 'label' => 'المفضلة',   'color' => 'red'    ),
                        array( 'slug' => 'question-answer',  'url' => $base . 'question-answer/',
                               'emoji' => '💬', 'label' => 'سؤال وجواب','color' => 'blue'   ),
                    ),
                ),
                array(
                    'label' => 'متابعة الأبناء',
                    'items' => array(
                        array( 'slug' => 'bookings',  'url' => $base . 'bookings/',
                               'emoji' => '📅', 'label' => 'لقاءات',  'color' => 'blue' ),
                        array( 'slug' => 'reports', 'url' => $base . 'reports/',
                               'emoji' => '📊', 'label' => 'التقارير', 'color' => 'teal' ),
                    ),
                ),
            );
        }

        // phpcs:disable WordPress.Security.NonceVerification
        $raw_tab_nav = isset( $_GET['rk_tab'] ) ? sanitize_text_field( wp_unslash( $_GET['rk_tab'] ) ) : '';
        // phpcs:enable
        $is_tab_nav  = (bool) preg_match( '/^[a-f0-9]{40}$/', $raw_tab_nav );

        if ( ( $is_tab_nav || $is_child ) && class_exists( 'RK_MC_Child_User' ) ) {
            // Sub-session onglet ou enfant direct : déconnexion custom (ne détruit pas le cookie parent)
            $logout_url = RK_MC_Child_User::get_child_logout_url();
        } elseif ( function_exists( 'tutor_utils' ) ) {
            $logout_url = (string) tutor_utils()->tutor_dashboard_url( 'logout' );
        } else {
            $logout_url = wp_logout_url( home_url() );
        }

        // Build nav items list.
        if ( $child ) {
            $nav_items = array(
                array( 'slug' => '',                 'emoji' => '🏠', 'label' => 'الرئيسية',       'url' => $base . '?child_id=' . (int) $child->id ),
                array( 'slug' => 'enrolled-courses', 'emoji' => '🗺️', 'label' => 'مغامراتي',       'url' => $base . 'enrolled-courses/' . $cp ),
                array( 'slug' => 'my-quiz-attempts', 'emoji' => '📝', 'label' => 'اختباراتي',      'url' => $base . 'my-quiz-attempts/' . $cp ),
                array( 'slug' => 'rk-sessions',      'emoji' => '📅', 'label' => 'لقاءات',         'url' => $base . 'rk-sessions/' . $cp ),
                array( 'slug' => 'rk-messages',      'emoji' => '💬', 'label' => 'الرسائل',         'url' => $base . 'rk-messages/' . $cp, 'badge' => $total_unread ),
                array( 'slug' => 'question-answer',  'emoji' => '❓', 'label' => 'أسئلة المدرب',   'url' => $base . 'question-answer/' . $cp ),
                array( 'slug' => 'certificates',     'emoji' => '🏆', 'label' => 'شهاداتي',        'url' => $base . 'certificates/' . $cp ),
            );
        } else {
            $nav_items = array(
                array( 'slug' => '',                 'emoji' => '🏠', 'label' => 'الرئيسية',   'url' => $base ),
                array( 'slug' => 'enrolled-courses', 'emoji' => '🗺️', 'label' => 'دوراتي',     'url' => $base . 'enrolled-courses/' ),
                array( 'slug' => 'my-quiz-attempts', 'emoji' => '📝', 'label' => 'اختباراتي', 'url' => $base . 'my-quiz-attempts/' ),
                array( 'slug' => 'bookings',         'emoji' => '📅', 'label' => 'لقاءات',    'url' => $base . 'bookings/' ),
                array( 'slug' => 'reports',          'emoji' => '📊', 'label' => 'التقارير',   'url' => $base . 'reports/' ),
            );
        }

        ob_start();
        ?>
        <aside id="rkd3-sidebar" dir="rtl">

            <div class="rkd3-brand">
                <span class="rkd3-brand__star">⭐</span>
                <div>ريادة كيدز<small>رحلتك نحو المستقبل</small></div>
            </div>

            <?php if ( $child ) : ?>
            <div class="rkd3-profile">
                <div class="rkd3-profile__avatar">
                    <?php if ( $avatar ) : ?>
                    <img src="<?php echo $avatar; ?>" alt="<?php echo $name; ?>" width="76" height="76">
                    <?php else : ?>
                    <img src="<?php echo esc_url( RK_MC_URL . 'assets/img/illustrations/character-banana.png' ); ?>" alt="" width="76" height="76">
                    <?php endif; ?>
                    <span class="rkd3-profile__level"><?php echo $lvl_num; ?></span>
                </div>
                <p class="rkd3-profile__name"><?php echo $name; ?></p>
                <p class="rkd3-profile__title">مستوى <?php echo $lvl_num; ?></p>
                <div class="rkd3-profile__xp">
                    <div class="rkd3-profile__xp-bar">
                        <div class="rkd3-profile__xp-fill" style="width:<?php echo $xp_pct; ?>%"></div>
                    </div>
                    <div class="rkd3-profile__xp-label">
                        <span><?php echo number_format( $points ); ?> XP ⚡</span>
                        <span><?php echo $xp_pct; ?>%</span>
                    </div>
                </div>
            </div>
            <?php else :
                $parent_user   = wp_get_current_user();
                $parent_name   = esc_html( $parent_user->display_name ?: $parent_user->user_login );
                $parent_avatar = get_avatar_url( $parent_user->ID, array( 'size' => 76 ) );
            ?>
            <div class="rkd3-profile">
                <div class="rkd3-profile__avatar">
                    <?php if ( $parent_avatar ) : ?>
                    <img src="<?php echo esc_url( $parent_avatar ); ?>" alt="" width="76" height="76">
                    <?php endif; ?>
                </div>
                <p class="rkd3-profile__name"><?php echo $parent_name; ?></p>
                <p class="rkd3-profile__title">لوحة الأولياء</p>
            </div>
            <?php endif; ?>

            <nav class="rkd3-nav" aria-label="القائمة الرئيسية">
                <?php foreach ( $nav_items as $ni ) :
                    $is_active = ( $slug === $ni['slug'] );
                    $badge     = (int) ( $ni['badge'] ?? 0 );
                ?>
                <a href="<?php echo esc_url( $ni['url'] ); ?>"
                   class="rkd3-nav__link<?php echo $is_active ? ' is-active' : ''; ?>">
                    <span class="rkd3-nav__icon"><?php echo $ni['emoji']; ?></span>
                    <span class="rkd3-nav__label"><?php echo esc_html( $ni['label'] ); ?></span>
                    <?php if ( $badge > 0 ) : ?>
                    <span class="rkd3-nav__badge"><?php echo min( $badge, 9 ); ?></span>
                    <?php endif; ?>
                </a>
                <?php endforeach; ?>

                <?php if ( ! $is_child && function_exists( 'tutor_utils' ) ) : ?>
                <a href="<?php echo esc_url( tutor_utils()->tutor_dashboard_url( 'settings' ) ); ?>"
                   class="rkd3-nav__link<?php echo 'settings' === $slug ? ' is-active' : ''; ?>">
                    <span class="rkd3-nav__icon">⚙️</span>
                    <span class="rkd3-nav__label">الإعدادات</span>
                </a>
                <?php endif; ?>

                <a href="<?php echo esc_url( $logout_url ); ?>"
                   class="rkd3-nav__link rkd3-nav__logout" data-no-instant="">
                    <span class="rkd3-nav__icon">🚪</span>
                    <span class="rkd3-nav__label">تسجيل الخروج</span>
                </a>
            </nav>

            <div class="rkd3-sidebar-deco">
                <img src="<?php echo esc_url( RK_MC_URL . 'assets/img/illustrations/character-astronaut.png' ); ?>" alt="" loading="lazy">
            </div>

        </aside>
        <?php
        return (string) ob_get_clean();
    }

}
