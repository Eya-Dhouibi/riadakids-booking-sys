<?php
declare( strict_types=1 );
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Extrait de class-rk-coach-dashboard.php — factorisation par fonctionnalité.
 * Code déplacé verbatim, aucune modification de logique.
 */
trait RK_Coach_Dash_Sidebar_Html {
    /* ─── Sidebar buffer (emoji nav) ───────────────────────────────── */

    public static function sidebar_buffer_start(): void {
        if ( class_exists( 'RK_MC_Child_Context' ) && RK_MC_Child_Context::get_active_child() ) return;
        self::$coach_sidebar_buffering = true;
        ob_start();
    }

    public static function sidebar_buffer_end(): void {
        if ( ! self::$coach_sidebar_buffering ) return;
        self::$coach_sidebar_buffering = false;
        $html = ob_get_clean();
        if ( ! is_string( $html ) || '' === $html ) return;

        try {
            // 1. Remplacer la nav sidebar Tutor LMS par la nav coach RK.
            $nav    = self::build_coach_nav_html();
            $result = preg_replace_callback(
                '~(<div[^>]+class="[^"]*\btutor-dashboard-left-menu\b[^"]*"[^>]*>)\s*<ul[^>]*>.*?</ul>\s*(</div>)~s',
                static function ( array $m ) use ( $nav ): string {
                    return $m[1] . $nav . $m[2];
                },
                $html,
                1
            );
            $result = is_string( $result ) ? $result : $html;

            // 2. Remplacer le header Tutor LMS par la bande enfants + bookings.
            $result = self::replace_header_div( $result );

            // 3. Supprimer le titre "لوحة التحكم" généré par Tutor LMS.
            //    Null-safety : preg_replace() peut retourner null sur erreur PCRE
            //    (backtracking limit). On conserve $result inchangé en cas d'échec.
            $step3 = preg_replace(
                '~<div[^>]+class="[^"]*\btutor-dashboard-title\b[^"]*"[^>]*>.*?</div>~s',
                '',
                $result
            );
            if ( is_string( $step3 ) ) $result = $step3;

            // 4. Supprimer entièrement tutor-dashboard-content-inner (wrapper + contenu).
            //    On utilise le div-counting pour gérer les divs imbriqués correctement.
            //    Le contenu coach est rendu AVANT ce div (via tutor_before_dashboard_content),
            //    donc on peut supprimer tout ce div sans perdre le rendu coach.
            $result = self::remove_div_with_class( $result, 'tutor-dashboard-content-inner' );

            echo $result;
        } catch ( \Throwable $e ) {
            // Fallback : afficher le HTML brut sans transformation plutôt qu'une page vide.
            rkp_log( '[RK_Coach_Dashboard] sidebar_buffer_end error: ' . $e->getMessage() );
            echo $html;
        }
    }

    /**
     * Supprime complètement un div (wrapper + tout son contenu) identifié par sa classe CSS.
     * Utilise le div-counting pour trouver le </div> fermant correct (gère les divs imbriqués).
     *
     * @param string $html   HTML à traiter.
     * @param string $class  Classe CSS cible (correspondance partielle, word-boundary).
     * @return string HTML sans le div ciblé.
     */
    private static function remove_div_with_class( string $html, string $class ): string {
        $pos = strpos( $html, $class );
        if ( $pos === false ) return $html;

        // Remonter jusqu'au <div contenant la classe
        $tag_start = strrpos( substr( $html, 0, $pos ), '<div' );
        if ( $tag_start === false ) return $html;

        // Trouver la fin du tag ouvrant
        $tag_close = strpos( $html, '>', $tag_start );
        if ( $tag_close === false ) return $html;

        // Div-counting : trouver le </div> correspondant
        $depth  = 1;
        $cursor = $tag_close + 1;
        $len    = strlen( $html );
        while ( $depth > 0 && $cursor < $len ) {
            $next_open  = strpos( $html, '<div',  $cursor );
            $next_close = strpos( $html, '</div>', $cursor );
            if ( $next_close === false ) break;
            if ( $next_open !== false && $next_open < $next_close ) {
                $depth++;
                $cursor = $next_open + 4;
            } else {
                $depth--;
                $cursor = $next_close + 6;
            }
        }

        // Supprimer du <div ouvrant jusqu'au </div> fermant inclus
        return substr( $html, 0, $tag_start ) . substr( $html, $cursor );
    }

    /**
     * Trouve le div.tutor-frontend-dashboard-header dans le HTML bufférisé
     * et le remplace par notre bande enfants (div-counting, pas de regex).
     */
    private static function replace_header_div( string $html ): string {
        $needle = 'tutor-frontend-dashboard-header';
        $pos    = strpos( $html, $needle );
        if ( $pos === false ) return $html;

        // Remonter jusqu'au <div qui contient la classe
        $tag_start = strrpos( substr( $html, 0, $pos ), '<div' );
        if ( $tag_start === false ) return $html;

        // Trouver la fin du tag ouvrant
        $tag_close = strpos( $html, '>', $tag_start );
        if ( $tag_close === false ) return $html;

        // Compter les divs imbriqués pour trouver le </div> correspondant
        $depth  = 1;
        $cursor = $tag_close + 1;
        $len    = strlen( $html );
        while ( $depth > 0 && $cursor < $len ) {
            $next_open  = strpos( $html, '<div',  $cursor );
            $next_close = strpos( $html, '</div>', $cursor );
            if ( $next_close === false ) break;
            if ( $next_open !== false && $next_open < $next_close ) {
                $depth++;
                $cursor = $next_open + 4;
            } else {
                $depth--;
                $cursor = $next_close + 6;
            }
        }

        return substr( $html, 0, $tag_start )
            . self::build_children_header_html()
            . substr( $html, $cursor );
    }

    /**
     * Construit le banner d'accueil coach (remplace .tutor-frontend-dashboard-header).
     * Affiche : salutation, nom, rôle, nombre d'élèves, date du jour.
     */
    private static function build_children_header_html(): string {
        $coach      = wp_get_current_user();
        $first_name = $coach->first_name ?: $coach->display_name;
        $avatar     = get_avatar_url( $coach->ID, [ 'size' => 64 ] );
        $greeting   = RK_Coach_Data::greeting_arabic();
        $students   = RK_Coach_Data::get_coach_students( $coach->ID );
        $count      = count( $students );
        $today_ar   = date_i18n( 'l، j F Y' );

        ob_start();
        ?>
        <div class="rk-ch-coach-banner" dir="rtl">
            <div class="rk-ch-coach-banner__avatar-wrap">
                <img src="<?php echo esc_url( $avatar ); ?>"
                     alt="<?php echo esc_attr( $first_name ); ?>"
                     class="rk-ch-coach-banner__avatar"
                     width="64" height="64">
                <span class="rk-ch-coach-banner__online" aria-hidden="true"></span>
            </div>
            <div class="rk-ch-coach-banner__body">
                <p class="rk-ch-coach-banner__greeting"><?php echo esc_html( $greeting ); ?> 👋</p>
                <h1 class="rk-ch-coach-banner__name"><?php echo esc_html( $first_name ); ?></h1>
                <p class="rk-ch-coach-banner__meta">
                    <span class="rk-ch-coach-banner__role">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                        <?php esc_html_e( 'مدرب RiadaKids', 'rk-coach-hub' ); ?>
                    </span>
                    <span class="rk-ch-coach-banner__sep" aria-hidden="true">·</span>
                    <span class="rk-ch-coach-banner__students">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                        <?php echo esc_html( sprintf( _n( '%d طالب', '%d طلاب', $count, 'rk-coach-hub' ), $count ) ); ?>
                    </span>
                    <span class="rk-ch-coach-banner__sep" aria-hidden="true">·</span>
                    <span class="rk-ch-coach-banner__date">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                        <?php echo esc_html( $today_ar ); ?>
                    </span>
                </p>
            </div>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    private static function build_coach_nav_html(): string {
        $coach   = wp_get_current_user();
        $name    = $coach->first_name ?: $coach->display_name;
        $page    = self::current_page();
        $avatar  = get_avatar_url( $coach->ID, [ 'size' => 64 ] );
        $pending = RK_Coach_Data::get_pending_evals_count( $coach->ID );
        $unread  = RK_Coach_Data::get_unread_messages_count( $coach->ID );

        // SVG Lucide-style — stroke icons 18×18
        $ico = [
            'home'       => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>',
            'book'       => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>',
            'users'      => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
            'progress'   => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>',
            'list'       => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>',
            'quiz'       => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>',
            'cal'        => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>',
            'clip'       => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><rect x="8" y="2" width="8" height="4" rx="1" ry="1"/><line x1="12" y1="11" x2="12" y2="17"/><line x1="9" y1="14" x2="15" y2="14"/></svg>',
            'target'     => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/></svg>',
            'award'      => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="6"/><path d="M15.477 12.89L17 22l-5-3-5 3 1.523-9.11"/></svg>',
            'chat'       => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>',
            'chart'      => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>',
            'logout'     => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>',
        ];

        $groups = [
            // ── Vue principale ─────────────────────────────────
            '' => [
                ''              => [ 'icon' => $ico['home'],  'label' => 'لوحة التحكم', 'color' => 'var(--e-global-color-primary,#FF4411)' ],
            ],
            // ── Parcours pédagogique (LMS) ─────────────────────
            'المنهج الدراسي' => [
                'rk-mes-cours'   => [ 'icon' => $ico['book'],     'label' => 'دوراتي',          'color' => '#1d4ed8', 'badge' => '' ],
                'rk-mes-eleves'  => [ 'icon' => $ico['users'],    'label' => 'الأطفال',          'color' => 'var(--e-global-color-secondary,#1B4F8C)', 'badge' => '' ],
                'rk-progression' => [ 'icon' => $ico['progress'], 'label' => 'تقدم الطلاب',     'color' => '#0891b2', 'badge' => '' ],
                'rk-lecons'      => [ 'icon' => $ico['list'],     'label' => 'متابعة الدروس',   'color' => '#059669', 'badge' => '' ],
                'rk-quiz'        => [ 'icon' => $ico['quiz'],     'label' => 'الاختبارات',      'color' => '#7c3aed', 'badge' => $pending > 0 ? (string) $pending : '' ],
                'rk-journey'     => [ 'icon' => $ico['chart'],    'label' => 'مسار الطالب',     'color' => '#0891b2', 'badge' => '' ],
            ],
            // ── Outils coach ──────────────────────────────────
            'أدوات المدرب' => [
                'rk-seances'          => [ 'icon' => $ico['cal'],    'label' => 'لقاءات',   'color' => '#0891b2', 'badge' => '' ],
                'rk-calendrier'       => [ 'icon' => $ico['cal'],    'label' => 'التقويم',   'color' => '#0f766e', 'badge' => '' ],
                'rk-evaluer'          => [ 'icon' => $ico['clip'],   'label' => 'التقييمات', 'color' => '#7c3aed', 'badge' => '' ],
                'rk-assigner-mission' => [ 'icon' => $ico['target'], 'label' => 'المهام',    'color' => '#dc2626', 'badge' => '' ],
                'rk-badges'           => [ 'icon' => $ico['award'],  'label' => 'الشارات',   'color' => '#d97706', 'badge' => '' ],
            ],
            // ── Communication & rapports ──────────────────────
            'تواصل ومتابعة' => [
                'rk-messagerie' => [ 'icon' => $ico['chat'],  'label' => 'الرسائل',  'color' => '#059669', 'badge' => $unread > 0 ? (string) $unread : '' ],
                'rk-stats'      => [ 'icon' => $ico['chart'], 'label' => 'التقارير', 'color' => '#6366f1', 'badge' => '' ],
            ],
        ];

        ob_start();
        ?>
        <nav class="rk-coach-nav-sidebar" dir="rtl" aria-label="<?php esc_attr_e( 'قائمة لوحة المدرب', 'rk-coach-hub' ); ?>">

            <!-- Hero coach -->
            <div class="rk-coach-nav__hero">
                <img src="<?php echo esc_url( $avatar ); ?>"
                     alt="<?php echo esc_attr( $name ); ?>"
                     class="rk-coach-nav__avatar"
                     width="44" height="44">
                <div class="rk-coach-nav__hero-info">
                    <p class="rk-coach-nav__name"><?php echo esc_html( $name ); ?></p>
                    <p class="rk-coach-nav__role"><?php esc_html_e( 'مدرب RiadaKids ⭐', 'rk-coach-hub' ); ?></p>
                </div>
            </div>

            <!-- Groupes nav -->
            <?php foreach ( $groups as $group_title => $items ) : ?>
            <div class="rk-coach-nav__group">
                <?php if ( $group_title ) : ?>
                <p class="rk-coach-nav__group-label"><?php echo esc_html( $group_title ); ?></p>
                <?php endif; ?>
                <?php foreach ( $items as $slug => $item ) :
                    $url       = function_exists( 'tutor_utils' ) ? tutor_utils()->tutor_dashboard_url( $slug ) : '#';
                    $is_active = ( $slug === $page );
                ?>
                <a href="<?php echo esc_url( $url ); ?>"
                   class="rk-coach-nav__item<?php echo $is_active ? ' rk-coach-nav__item--active' : ''; ?>"
                   <?php if ( $is_active ) : ?>style="--nav-item-color:<?php echo esc_attr( $item['color'] ); ?>"<?php endif; ?>>
                    <span class="rk-coach-nav__icon" aria-hidden="true"><?php echo $item['icon']; // phpcs:ignore — SVG littéral sûr ?></span>
                    <span class="rk-coach-nav__label"><?php echo esc_html( $item['label'] ); ?></span>
                    <?php if ( ! empty( $item['badge'] ) ) : ?>
                    <span class="rk-coach-nav__badge"><?php echo esc_html( $item['badge'] ); ?></span>
                    <?php endif; ?>
                </a>
                <?php endforeach; ?>
            </div>
            <?php endforeach; ?>

            <!-- Logout -->
            <div class="rk-coach-nav__footer">
                <a href="<?php echo esc_url( wp_logout_url( home_url( '/' ) ) ); ?>"
                   class="rk-coach-nav__logout">
                    <span aria-hidden="true"><?php echo $ico['logout']; // phpcs:ignore ?></span>
                    <?php esc_html_e( 'تسجيل الخروج', 'rk-coach-hub' ); ?>
                </a>
            </div>

        </nav>
        <?php
        return (string) ob_get_clean();
    }

}
