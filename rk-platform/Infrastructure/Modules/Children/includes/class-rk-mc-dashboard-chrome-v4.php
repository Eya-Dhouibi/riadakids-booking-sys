<?php
declare( strict_types=1 );
/**
 * RK_MC_Dashboard_Chrome_V4 — Chrome de navigation du design v4.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * RÔLE
 * ─────────────────────────────────────────────────────────────────────────────
 * Fournit le MÊME chrome (sidebar desktop · topbar mobile · bottom nav mobile)
 * pour TOUTES les vues du dashboard enfant : la home (#rkd4) comme les
 * sous-pages Tutor (مغامراتي, لقاءاتي, الرسائل, الشارات, …).
 *
 * Avant v8.4 : la home utilisait des closures locales au template, les
 * sous-pages l'ancien chrome v3 (#rkd3-sidebar + .rkv2-bottom-nav) → deux
 * navigations différentes. Cette classe est désormais la source unique.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * RÈGLES
 * ─────────────────────────────────────────────────────────────────────────────
 *   - Aucune donnée nouvelle : réutilise RK_MC_Tutor_Dashboard::child_param(),
 *     get_child_for_template(), notif_bell_trigger() et les compteurs existants.
 *   - Aucun nouvel endpoint : tous les liens pointent sur /dashboard/ + slugs
 *     Tutor existants, avec propagation de child_id / rk_tab.
 *   - RTL natif (dir="rtl"), libellés en arabe.
 *
 * @package RK_My_Children
 * @since   8.4.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class RK_MC_Dashboard_Chrome_V4 {

    /** Cache par requête du contexte de navigation. */
    private static ?array $ctx = null;

    /* ═══════════════════════════════════════════════════════════════════
       ICÔNES SVG (contour, 24×24, currentColor)
       ═══════════════════════════════════════════════════════════════════ */

    private const PATHS = array(
        /* v8.7 — Tracés fournis pour la bottom nav (fichiers Frame_1..5.svg),
         * stroke recolorié en currentColor pour suivre l'état actif/inactif
         * en CSS (couleur d'origine des fichiers : #295177). viewBox recadré
         * de 0 0 24 24 à l'identique de la source, sauf 'home' qui vient
         * d'un SVG 32×32 (Frame_5) : viewBox adapté en conséquence via
         * un paramètre dédié dans icon(). */
        'home'    => '<path d="M20 28V17.3333C20 16.9797 19.8595 16.6406 19.6095 16.3905C19.3594 16.1405 19.0203 16 18.6667 16H13.3333C12.9797 16 12.6406 16.1405 12.3905 16.3905C12.1405 16.6406 12 16.9797 12 17.3333V28"/><path d="M4 13.3333C3.99991 12.9454 4.08445 12.5622 4.24772 12.2103C4.41099 11.8584 4.64906 11.5464 4.94533 11.296L14.2787 3.29599C14.76 2.8892 15.3698 2.66602 16 2.66602C16.6302 2.66602 17.24 2.8892 17.7213 3.29599L27.0547 11.296C27.3509 11.5464 27.589 11.8584 27.7523 12.2103C27.9156 12.5622 28.0001 12.9454 28 13.3333V25.3333C28 26.0406 27.719 26.7188 27.219 27.2189C26.7189 27.719 26.0406 28 25.3333 28H6.66667C5.95942 28 5.28115 27.719 4.78105 27.2189C4.28095 26.7188 4 26.0406 4 25.3333V13.3333Z"/>',
        'map'     => '<path d="M12 14C13.1046 14 14 13.1046 14 12C14 10.8954 13.1046 10 12 10C10.8954 10 10 10.8954 10 12C10 13.1046 10.8954 14 12 14Z"/><path d="M12 2V6"/><path d="M6.79999 15L3.29999 17"/><path d="M20.7 7L17.2 9"/><path d="M6.79999 9L3.29999 7"/><path d="M20.7 17L17.2 15"/><path d="M9 22L12 14L15 22"/><path d="M8 22H16"/><path d="M18 18.6999C19.3586 17.4848 20.3162 15.8857 20.7461 14.1144C21.176 12.3431 21.0579 10.483 20.4076 8.7803C19.7572 7.07756 18.6051 5.61243 17.1038 4.57879C15.6025 3.54514 13.8227 2.9917 12 2.9917C10.1773 2.9917 8.39751 3.54514 6.89621 4.57879C5.39491 5.61243 4.24284 7.07756 3.59245 8.7803C2.94206 10.483 2.82401 12.3431 3.25392 14.1144C3.68382 15.8857 4.64142 17.4848 6 18.6999"/>',
        'video'   => '<path d="M16 13L21.223 16.482C21.2983 16.5321 21.3858 16.5609 21.4761 16.5652C21.5664 16.5695 21.6563 16.5493 21.736 16.5066C21.8157 16.4639 21.8824 16.4004 21.9289 16.3228C21.9754 16.2452 22 16.1565 22 16.066V7.87002C22 7.78204 21.9768 7.69562 21.9328 7.61947C21.8887 7.54332 21.8253 7.48014 21.7491 7.43632C21.6728 7.3925 21.5863 7.36958 21.4983 7.36988C21.4103 7.37017 21.324 7.39368 21.248 7.43802L16 10.5"/><path d="M14 6H4C2.89543 6 2 6.89543 2 8V16C2 17.1046 2.89543 18 4 18H14C15.1046 18 16 17.1046 16 16V8C16 6.89543 15.1046 6 14 6Z"/>',
        'trophy'  => '<path d="M10 14.6599V16.2859C9.99622 16.6285 9.90448 16.9644 9.73358 17.2614C9.56268 17.5584 9.31834 17.8065 9.024 17.9819C8.39914 18.4447 7.89084 19.0469 7.53948 19.7406C7.18813 20.4343 7.00341 21.2003 7 21.9779"/><path d="M14 14.6599V16.2859C14.0038 16.6285 14.0955 16.9644 14.2664 17.2614C14.4373 17.5584 14.6817 17.8065 14.976 17.9819C15.6009 18.4447 16.1092 19.0469 16.4605 19.7406C16.8119 20.4343 16.9966 21.2003 17 21.9779"/><path d="M18 9H19.5C20.163 9 20.7989 8.73661 21.2678 8.26777C21.7366 7.79893 22 7.16304 22 6.5C22 5.83696 21.7366 5.20107 21.2678 4.73223C20.7989 4.26339 20.163 4 19.5 4H18"/><path d="M4 22H20"/><path d="M6 9C6 10.5913 6.63214 12.1174 7.75736 13.2426C8.88258 14.3679 10.4087 15 12 15C13.5913 15 15.1174 14.3679 16.2426 13.2426C17.3679 12.1174 18 10.5913 18 9V3C18 2.73478 17.8946 2.48043 17.7071 2.29289C17.5196 2.10536 17.2652 2 17 2H7C6.73478 2 6.48043 2.10536 6.29289 2.29289C6.10536 2.48043 6 2.73478 6 3V9Z"/><path d="M6 9H4.5C3.83696 9 3.20107 8.73661 2.73223 8.26777C2.26339 7.79893 2 7.16304 2 6.5C2 5.83696 2.26339 5.20107 2.73223 4.73223C3.20107 4.26339 3.83696 4 4.5 4H6"/>',
        'user'    => '<path d="M12 13C14.7614 13 17 10.7614 17 8C17 5.23858 14.7614 3 12 3C9.23858 3 7 5.23858 7 8C7 10.7614 9.23858 13 12 13Z"/><path d="M20 21C20 18.8783 19.1571 16.8434 17.6569 15.3431C16.1566 13.8429 14.1217 13 12 13C9.87827 13 7.84344 13.8429 6.34315 15.3431C4.84285 16.8434 4 18.8783 4 21"/>',
        'logout'  => '<path d="M10 4H6a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h4"/><path d="m15 8 4 4-4 4"/><path d="M19 12H9"/>',
        'chat'    => '<path d="M21 11.5a8.5 8.5 0 0 1-8.5 8.5 8.4 8.4 0 0 1-3.8-.9L3 21l1.9-5.7A8.4 8.4 0 0 1 4 11.5a8.5 8.5 0 0 1 17 0z"/>',
        'youtube' => '<rect x="2" y="5" width="20" height="14" rx="4"/><path d="m10 9 5 3-5 3z"/>',
        /* v9.52 — Cercles concentriques (cible), même tracé que celui déjà
         * utilisé pour la carte "تحدياتي" sur la page d'accueil du
         * dashboard (dashboard.php) — cohérence visuelle entre la carte
         * d'accès rapide et l'entrée de menu qui pointe vers la même page. */
        'target'  => '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="5"/><circle cx="12" cy="12" r="1.4"/>',
    );

    /** viewBox spécifique par icône — la plupart sont en 24×24, 'home' en 32×32. */
    private const VIEWBOX = array(
        'home' => '0 0 32 32',
    );

    public static function icon( string $key, int $size = 20 ): string {
        $d        = self::PATHS[ $key ] ?? self::PATHS['home'];
        $view_box = self::VIEWBOX[ $key ] ?? '0 0 24 24';
        return sprintf(
            '<svg width="%1$d" height="%1$d" viewBox="%3$s" aria-hidden="true" focusable="false"'
            . ' fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"'
            . ' stroke-linejoin="round">%2$s</svg>',
            $size,
            $d,
            $view_box
        );
    }

    /* ═══════════════════════════════════════════════════════════════════
       CONTEXTE
       ═══════════════════════════════════════════════════════════════════ */

    /**
     * Résout une fois par requête : base d'URL, paramètre enfant, slug actif,
     * compteurs non lus, URL de déconnexion et items de navigation.
     */
    private static function ctx(): array {
        if ( null !== self::$ctx ) return self::$ctx;

        global $wp_query;
        $slug = (string) ( $wp_query->query_vars['tutor_dashboard_page'] ?? '' );

        $nb = home_url( RK_TUTOR_DASHBOARD_URL );
        $cp = class_exists( 'RK_MC_Tutor_Dashboard' ) ? RK_MC_Tutor_Dashboard::child_param() : '';

        /* Compteurs — mêmes sources que le chrome v3. */
        /* 6b-2 — Chrome du dashboard ENFANT : sa navigation ne contient que
         * des slugs enfant (enrolled-courses, rk-badges, rk-sessions,
         * rk-challenges…). Le badge compte donc les notifications de
         * l'enfant. Sans contexte enfant → 0, jamais le parent. */
        $child_uid    = class_exists( 'RK_Identity_Context' ) ? RK_Identity_Context::child_wp_uid() : 0;
        $notif_unread = ( $child_uid > 0 && class_exists( 'RK_MC_Notification_Service' ) )
            ? (int) RK_MC_Notification_Service::get_unread_count( $child_uid )
            : 0;

        $msg_unread = 0;
        $sessions   = 0;
        $child      = class_exists( 'RK_MC_Tutor_Dashboard' )
            ? RK_MC_Tutor_Dashboard::get_child_for_template()
            : null;

        if ( $child && class_exists( 'RK_Booking_Calendar_Service' ) ) {
            $sessions = count( (array) RK_Booking_Calendar_Service::get_upcoming( (int) $child->id, 5 ) );
        }
        if ( $child && class_exists( 'RK_MC_Message_Service' ) ) {
            /* Signature réelle : ( $to_id, $child_id, $channel ).
             * Le badge messagerie additionne les deux canaux, comme le v3. */
            /* 6b-2 — $to_id est le DESTINATAIRE des messages non lus. Sur le
             * dashboard enfant, c'est l'enfant. Sans son compte WP → 0. */
            $msg_unread = $child_uid > 0
                ? (int) RK_MC_Message_Service::get_unread_count( $child_uid, (int) $child->id, 'coach' )
                + (int) RK_MC_Message_Service::get_unread_count( $child_uid, (int) $child->id, 'admin' )
                : 0;
        }

        /* Déconnexion — même règle que trait-rk-mc-tutor-dash-nav. */
        // phpcs:disable WordPress.Security.NonceVerification
        $raw_tab = isset( $_GET['rk_tab'] ) ? sanitize_text_field( wp_unslash( $_GET['rk_tab'] ) ) : '';
        // phpcs:enable
        $is_tab   = (bool) preg_match( '/^[a-f0-9]{40}$/', $raw_tab );
        $is_child = class_exists( 'RK_MC_Child_Restrictions' ) && RK_MC_Child_Restrictions::is_child_user();

        if ( ( $is_tab || $is_child ) && class_exists( 'RK_MC_Child_User' ) ) {
            $logout = RK_MC_Child_User::get_child_logout_url();
        } elseif ( function_exists( 'tutor_utils' ) ) {
            $logout = (string) tutor_utils()->tutor_dashboard_url( 'logout' );
        } else {
            $logout = wp_logout_url( home_url() );
        }

        /* « حسابي » : réglages pour le parent, parcours pour l'enfant. */
        $account = $is_child
            ? $nb . 'rk-mon-parcours/' . $cp
            : ( function_exists( 'tutor_utils' ) ? (string) tutor_utils()->tutor_dashboard_url( 'settings' ) : $nb . $cp );

        /* Items de navigation — source unique desktop + mobile.
         * 'slugs' liste les sous-pages qui allument l'item (état actif). */
        $items = array(
            array(
                'key' => 'index', 'icon' => 'home', 'label' => 'الرئيسية',
                'url' => $nb . $cp, 'badge' => 0, 'slugs' => array( '' ),
            ),
            array(
                'key' => 'enrolled', 'icon' => 'map', 'label' => 'مغامراتي',
                'url' => $nb . 'enrolled-courses/' . $cp, 'badge' => 0,
                'slugs' => array( 'enrolled-courses', 'question-answer', 'my-quiz-attempts', 'rk-mon-parcours' ),
            ),
            array(
                'key' => 'sessions', 'icon' => 'video', 'label' => 'لقاءاتي',
                'url' => $nb . 'rk-sessions/' . $cp, 'badge' => $sessions,
                'slugs' => array( 'rk-sessions' ),
            ),
            array(
                'key' => 'badges', 'icon' => 'trophy', 'label' => 'إنجازاتي',
                'url' => $nb . 'rk-badges/' . $cp, 'badge' => 0,
                'slugs' => array( 'rk-badges', 'certificates', 'rk-certificats', 'rk-skills' ),
            ),
            array(
                'key' => 'challenges', 'icon' => 'target', 'label' => 'تحدياتي',
                'url' => $nb . 'rk-challenges/' . $cp, 'badge' => 0,
                'slugs' => array( 'rk-challenges' ),
            ),
        );

        self::$ctx = array(
            'slug'         => $slug,
            'nb'           => $nb,
            'cp'           => $cp,
            'items'        => $items,
            'logout'       => $logout,
            'account'      => $account,
            'msg_url'      => $nb . 'rk-messages/' . $cp,
            'msg_unread'   => $msg_unread,
            'notif_unread' => $notif_unread,
            'logo'         => RK_MC_URL . 'assets/img/logo-riadakids.webp',
            'is_child'     => $is_child,
        );
        return self::$ctx;
    }

    /** Un item est actif si le slug courant figure dans sa liste. */
    private static function is_active( array $item, string $slug ): bool {
        return in_array( $slug, (array) ( $item['slugs'] ?? array() ), true );
    }

    /* ═══════════════════════════════════════════════════════════════════
       COMPOSANTS
       ═══════════════════════════════════════════════════════════════════ */

    /** Bloc de marque, partagé sidebar + topbar mobile. */
    private static function brand_html( string $logo ): string {
        ob_start();
        ?>
        <div class="rkd4-brand">
            <img src="<?php echo esc_url( $logo ); ?>" alt="Riada kids" loading="lazy">
        </div>
        <?php
        return (string) ob_get_clean();
    }

    /** Carte promotionnelle YouTube (sidebar desktop / bandeau mobile). */
    public static function youtube_card( bool $mobile = false ): string {
        $url = (string) apply_filters(
            'rk_mc_youtube_url',
            (string) get_option( 'rk_mc_youtube_url', 'https://www.youtube.com/@riadakids' )
        );
        $art = RK_MC_URL . 'assets/img/illustrations/character-youtube.png';
        ob_start();
        ?>
        <section class="rkd4-yt<?php echo $mobile ? ' rkd4-yt--mobile' : ''; ?>">
            <div class="rkd4-yt__inner">
                <div class="rkd4-yt__txt">
                    <h3>قناتنا على يوتيوب</h3>
                    <p>فيديوهات ممتعة بانتظارك!</p>
                </div>
                <div class="rkd4-yt__art">
                    <img src="<?php echo esc_url( $art ); ?>" alt="" loading="lazy">
                </div>
                <a href="<?php echo esc_url( $url ); ?>" class="rkd4-yt__cta" target="_blank" rel="noopener">
                    <?php echo self::icon( 'youtube', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                    شاهد الآن
                </a>
            </div>
        </section>
        <?php
        return (string) ob_get_clean();
    }

    /* ── SIDEBAR DESKTOP ─────────────────────────────────────────────── */

    public static function sidebar(): string {
        $c = self::ctx();
        ob_start();
        ?>
        <aside id="rkd4-side" dir="rtl">

            <div class="rkd4-sidecard">
                <?php echo self::brand_html( $c['logo'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?>

                <nav class="rkd4-nav" aria-label="القائمة الرئيسية">
                    <?php foreach ( $c['items'] as $it ) :
                        $active = self::is_active( $it, $c['slug'] );
                    ?>
                    <a href="<?php echo esc_url( $it['url'] ); ?>"
                       class="rkd4-nav__link<?php echo $active ? ' is-active' : ''; ?>"
                       <?php echo $active ? 'aria-current="page"' : ''; ?>>
                        <span class="rkd4-nav__ico"><?php echo self::icon( $it['icon'], 19 ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
                        <span class="rkd4-nav__label"><?php echo esc_html( $it['label'] ); ?></span>
                        <?php if ( (int) $it['badge'] > 0 ) : ?>
                        <span class="rkd4-nav__badge"><?php echo esc_html( (string) min( (int) $it['badge'], 9 ) ); ?></span>
                        <?php endif; ?>
                    </a>
                    <?php endforeach; ?>

                    <span class="rkd4-nav__sep" aria-hidden="true"></span>

                    <a href="<?php echo esc_url( $c['logout'] ); ?>"
                       class="rkd4-nav__link rkd4-nav__link--logout" data-no-instant="">
                        <span class="rkd4-nav__ico"><?php echo self::icon( 'logout', 19 ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
                        <span class="rkd4-nav__label">تسجيل الخروج</span>
                    </a>
                </nav>

                <?php echo self::youtube_card( false ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
            </div>

        </aside>
        <?php
        return (string) ob_get_clean();
    }

    /* ── TOPBAR MOBILE ───────────────────────────────────────────────── */

    /**
     * v8.6 — Rendu strictement identique sur TOUTES les pages du dashboard
     * (home ET sous-pages). Avant cette version, un titre de page
     * conditionnel (`page_title`) remplaçait le logo sur les sous-pages,
     * ce qui rendait le header visuellement différent d'une page à
     * l'autre — non conforme à la maquette, qui garde le même bandeau
     * partout (logo + cloche + messages).
     */
    public static function mobtop(): string {
        $c = self::ctx();
        ob_start();
        ?>
        <div id="rkd4-mobtop" dir="rtl">
            <?php echo self::brand_html( $c['logo'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
            <div class="rkd4-mobtop__actions">
                <?php echo RK_MC_Tutor_Dashboard::notif_bell_trigger( (int) $c['notif_unread'], 'rkd4-iconbtn' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                <?php
                // v9.51 (13/08/2026) — Icône de déconnexion déplacée ici
                // (topbar mobile, à côté de la cloche notifications),
                // retirée de #rkd4-botnav — décision explicite de
                // l'utilisateur. Icône seule, sans texte "خروج".
                ?>
                <a href="<?php echo esc_url( $c['logout'] ); ?>" class="rkd4-iconbtn rkd4-iconbtn--logout" data-no-instant aria-label="خروج">
                    <?php echo self::icon( 'logout', 20 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                </a>
                <?php
                // v9.54 (13/08/2026) — حسابي déplacé ici depuis le trailing
                // du bottom-nav (retiré de #rkd4-botnav) — décision
                // explicite de l'utilisateur. Même style rkd4-iconbtn que
                // les icônes voisines (خروج/الإشعارات), icône seule sans
                // texte, cohérent avec le reste de cette barre.
                ?>
                <a href="<?php echo esc_url( $c['account'] ); ?>" class="rkd4-iconbtn" aria-label="الحساب">
                    <?php echo self::icon( 'user', 20 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                </a>
            </div>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    /* ── ACTIONS FLOTTANTES DESKTOP ──────────────────────────────────── */

    /**
     * v8.6 — Idem : plus de titre de page conditionnel. La topbar desktop
     * (actions flottantes uniquement) est identique sur home et sous-pages.
     */
    public static function topbar(): string {
        $c = self::ctx();
        ob_start();
        ?>
        <div class="rkd4-topbar">
            <?php echo RK_MC_Tutor_Dashboard::notif_bell_trigger( (int) $c['notif_unread'], 'rkd4-iconbtn' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
            <a href="<?php echo esc_url( $c['msg_url'] ); ?>" class="rkd4-iconbtn" aria-label="الرسائل">
                <?php echo self::icon( 'chat', 20 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                <?php if ( (int) $c['msg_unread'] > 0 ) : ?>
                <span class="rkd4-iconbtn__badge"><?php echo esc_html( (string) min( (int) $c['msg_unread'], 9 ) ); ?></span>
                <?php endif; ?>
            </a>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    /* ── BOTTOM NAV MOBILE ───────────────────────────────────────────── */

    public static function botnav(): string {
        $c     = self::ctx();
        $items = $c['items'];

        $home     = $items[0];
        $leading  = array( $items[1], $items[2] );   // مغامراتي · لقاءاتي
        // v9.54 (13/08/2026) — حسابي retiré du trailing (déplacé vers
        // rkd4-mobtop__actions, voir mobtop()) — décision explicite de
        // l'utilisateur. Bottom-nav mobile ne garde plus que : مغامراتي،
        // لقاءاتي، (accueil), إنجازاتي، تحدياتي.
        $trailing = array(
            $items[3],                              // إنجازاتي
            $items[4],                              // تحدياتي
        );
        $home_active = self::is_active( $home, $c['slug'] );
        ob_start();
        ?>
        <nav id="rkd4-botnav" dir="rtl" aria-label="التنقل">
            <?php foreach ( $leading as $it ) :
                $active = self::is_active( $it, $c['slug'] );
            ?>
            <a href="<?php echo esc_url( $it['url'] ); ?>"
               class="rkd4-botnav__item<?php echo $active ? ' is-active' : ''; ?>"
               <?php echo $active ? 'aria-current="page"' : ''; ?>>
                <?php echo self::icon( $it['icon'], 22 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                <span><?php echo esc_html( $it['label'] ); ?></span>
                <?php if ( (int) $it['badge'] > 0 ) : ?>
                <span class="rkd4-botnav__badge"><?php echo esc_html( (string) min( (int) $it['badge'], 9 ) ); ?></span>
                <?php endif; ?>
            </a>
            <?php endforeach; ?>

            <a href="<?php echo esc_url( $home['url'] ); ?>"
               class="rkd4-botnav__item rkd4-botnav__item--fab<?php echo $home_active ? ' is-active' : ''; ?>"
               <?php echo $home_active ? 'aria-current="page"' : ''; ?>
               aria-label="الرئيسية">
                <span class="rkd4-botnav__fab"><?php echo self::icon( 'home', 26 ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
            </a>

            <?php foreach ( $trailing as $it ) :
                $active = self::is_active( $it, $c['slug'] );
            ?>
            <a href="<?php echo esc_url( $it['url'] ); ?>"
               class="rkd4-botnav__item<?php echo $active ? ' is-active' : ''; ?>"
               <?php echo $active ? 'aria-current="page"' : ''; ?>>
                <?php echo self::icon( $it['icon'], 22 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                <span><?php echo esc_html( $it['label'] ); ?></span>
            </a>
            <?php endforeach; ?>
        </nav>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * v9.54 (13/08/2026) — Bouton flottant fixe "الرسائل", retiré de
     * rkd4-mobtop__actions (remplacé là-bas par حسابي) — décision
     * explicite de l'utilisateur. Rendu séparément de #rkd4-botnav (pas
     * un item de la barre elle-même) pour rester flottant indépendamment
     * du reste de la navigation, positionné en CSS (voir
     * rk-dashboard-v4.css, .rkd4-msg-fab).
     */
    public static function messages_fab(): string {
        $c = self::ctx();
        ob_start();
        ?>
        <a href="<?php echo esc_url( $c['msg_url'] ); ?>" class="rkd4-msg-fab" aria-label="الرسائل">
            <?php echo self::icon( 'chat', 22 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
            <?php if ( (int) $c['msg_unread'] > 0 ) : ?>
            <span class="rkd4-msg-fab__badge"><?php echo esc_html( (string) min( (int) $c['msg_unread'], 9 ) ); ?></span>
            <?php endif; ?>
        </a>
        <?php
        return (string) ob_get_clean();
    }

    /* ═══════════════════════════════════════════════════════════════════
       RENDU COMPLET POUR LES SOUS-PAGES
       La home (#rkd4) assemble elle-même ses blocs dans dashboard.php ;
       les sous-pages reçoivent ici le chrome complet autour du contenu
       natif Tutor.
       ═══════════════════════════════════════════════════════════════════ */

    /**
     * v8.5.1 — Ouverture du chrome sur les sous-pages.
     *
     * BUG CORRIGE : l'ancienne render_subpage_chrome() imprimait tout le
     * chrome (topbar + mobtop + sidebar + botnav) d'un bloc, AVANT que le
     * contenu Tutor natif ne soit lui-meme imprime par le hook suivant.
     * Resultat : contenu et sidebar se retrouvaient l'un apres l'autre dans
     * le DOM, sans grille commune -> sidebar/topbar affiches, mais le
     * contenu poussé en dehors, sans colonnes (visible sur /rk-badges/).
     *
     * Nouvelle sequence, alignee sur celle de la home (#rkd4 dans
     * dashboard.php) :
     *   1. render_subpage_chrome_open()  → topbar + mobtop, PUIS ouvre
     *      #rkd4-shell > #rkd4-main (la colonne de contenu)
     *   2. [Tutor imprime son contenu natif ICI, à l'intérieur de #rkd4-main]
     *   3. render_subpage_chrome_close() → ferme #rkd4-main, imprime
     *      #rkd4-side (sidebar), ferme #rkd4-shell, puis #rkd4-botnav
     *
     * Hooks (voir trait-rk-mc-tutor-dash-bootstrap.php) :
     *   tutor_dashboard/before/wrap (prio 0) → render_subpage_chrome_open()
     *   tutor_dashboard/after/wrap  (prio 999) → render_subpage_chrome_close()
     */
    public static function render_subpage_chrome_open(): void {
        ?>
        <script>document.body.classList.add('rk-child-game-page','rkd4-chrome','rkd4-subpage');</script>
        <?php
        echo self::mobtop(); // phpcs:ignore WordPress.Security.EscapeOutput
        ?>
        <div id="rkd4-shell">
            <main id="rkd4-main">
                <?php echo self::topbar(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
        <?php
        /* Le contenu Tutor natif de la sous-page (liste de cours, badges,
         * messages, …) s'imprime juste après ce point, toujours à
         * l'intérieur de <main id="rkd4-main">. */
    }

    public static function render_subpage_chrome_close(): void {
        /* v8.5.2 — BUG : cette méthode est hookée sur tutor_dashboard/after/wrap
         * dès que self::resolve_child() est vrai (voir trait-rk-mc-tutor-dash-
         * -bootstrap.php), donc AUSSI sur la home (/dashboard/) — pas seulement
         * sur les sous-pages. Or dashboard.php imprime déjà son propre sidecard
         * via $rkd4_sidebar(). Sans cette garde, la home affichait deux
         * .rkd4-sidecard : celui de dashboard.php + celui imprimé ici en double.
         * render_subpage_chrome_open() n'a pas ce problème car render_topbar()
         * fait déjà un `return;` sur la home avant de l'appeler — mais
         * render_subpage_chrome_close() est un hook indépendant, donc doit
         * porter sa propre vérification. */
        if ( class_exists( 'RK_MC_Tutor_Dashboard' ) && RK_MC_Tutor_Dashboard::is_home_public() ) {
            return;
        }

        $notif_nonce = wp_create_nonce( 'rk_mc_notifs' );
        ?>
            </main>
            <?php echo self::sidebar(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
        </div><!-- /#rkd4-shell -->
        <?php
        echo self::botnav(); // phpcs:ignore WordPress.Security.EscapeOutput
        echo self::messages_fab(); // phpcs:ignore WordPress.Security.EscapeOutput
        echo RK_MC_Tutor_Dashboard::notif_bell_widget( $notif_nonce ); // phpcs:ignore WordPress.Security.EscapeOutput
    }

    /**
     * @deprecated 8.5.1 Conservee pour compatibilite si un autre hook y fait
     * encore reference ; redirige simplement vers la nouvelle sequence.
     */
    public static function render_subpage_chrome(): void {
        self::render_subpage_chrome_open();
        self::render_subpage_chrome_close();
    }
}