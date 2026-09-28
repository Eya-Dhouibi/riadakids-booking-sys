<?php
/**
 * RKD4 — Icônes SVG inline (design v4).
 *
 * Expose la closure $rkd4_icon( string $key, int $size = 20 ): string
 * Aucune dépendance externe, aucune police d'icônes, RTL-safe.
 *
 * @package RK_My_Children
 * @since   8.0.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$rkd4_paths = array(
    'home'      => '<path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V20a1 1 0 0 0 1 1h4v-6h4v6h4a1 1 0 0 0 1-1V9.5"/>',
    'map'       => '<path d="m9 4-6 2.5V21l6-2.5"/><path d="m9 4 6 2.5V21L9 18.5"/><path d="m15 6.5 6-2.5v14.5L15 21"/>',
    'video'     => '<rect x="2" y="6" width="13" height="12" rx="2.5"/><path d="m15 11 6-3.5v9L15 13"/>',
    'trophy'    => '<path d="M7 4h10v5a5 5 0 0 1-10 0z"/><path d="M17 5h3v2a3 3 0 0 1-3 3"/><path d="M7 5H4v2a3 3 0 0 0 3 3"/><path d="M12 14v3"/><path d="M8.5 21h7l-1-4h-5z"/>',
    'user'      => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
    'logout'    => '<path d="M10 4H6a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h4"/><path d="m15 8 4 4-4 4"/><path d="M19 12H9"/>',
    'bell'      => '<path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/>',
    'chat'      => '<path d="M21 11.5a8.5 8.5 0 0 1-8.5 8.5 8.4 8.4 0 0 1-3.8-.9L3 21l1.9-5.7A8.4 8.4 0 0 1 4 11.5a8.5 8.5 0 0 1 17 0z"/>',
    'send'      => '<path d="M21 3 3 10.5l7 3 3 7z"/><path d="m10 13.5 11-10.5"/>',
    'arrow'     => '<path d="M19 12H5"/><path d="m11 6-6 6 6 6"/>',
    'play'      => '<path d="M7 4.5v15l13-7.5z"/>',
    'target'    => '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="5"/><circle cx="12" cy="12" r="1.4"/>',
    'gamepad'   => '<path d="M7 12h4"/><path d="M9 10v4"/><circle cx="16" cy="11.5" r="1"/><circle cx="18" cy="13.5" r="1"/><rect x="2" y="7" width="20" height="11" rx="5.5"/>',
    'check'     => '<path d="m4 12.5 5 5L20 6.5"/>',
    'lock'      => '<rect x="4" y="10" width="16" height="11" rx="2.5"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/>',
    'rocket'    => '<path d="M12 2c3.5 2.5 5.5 6.5 5.5 11L12 18l-5.5-5C6.5 8.5 8.5 4.5 12 2z"/><circle cx="12" cy="9" r="2"/><path d="M8 17c-1.5 1-2 2.5-2 5 2.5 0 4-.5 5-2"/>',
    'brain'     => '<path d="M9 4a3 3 0 0 0-3 3 3 3 0 0 0-1 5.8V16a4 4 0 0 0 4 4h1V4z"/><path d="M15 4a3 3 0 0 1 3 3 3 3 0 0 1 1 5.8V16a4 4 0 0 1-4 4h-1V4z"/>',
    'code'      => '<path d="m8 8-4 4 4 4"/><path d="m16 8 4 4-4 4"/><path d="m13.5 6-3 12"/>',
    'book'      => '<path d="M4 5.5A2.5 2.5 0 0 1 6.5 3H20v15H6.5A2.5 2.5 0 0 0 4 20.5z"/><path d="M4 20.5A2.5 2.5 0 0 1 6.5 18H20v3H6.5"/>',
    'star'      => '<path d="m12 3 2.7 5.7 6.3.9-4.5 4.4 1 6.3-5.5-3-5.5 3 1-6.3L3 9.6l6.3-.9z"/>',
    'flag'      => '<path d="M5 21V4"/><path d="M5 4h11l-2 3.5L16 11H5z"/>',
    'calendar'  => '<rect x="3" y="5" width="18" height="16" rx="2.5"/><path d="M3 10h18"/><path d="M8 3v4"/><path d="M16 3v4"/>',
    'fire'      => '<path d="M12 3c.5 3-2 4-2 7a4 4 0 0 0 8 0c0-4-3-6-6-7z"/><path d="M12 21a6 6 0 0 1-6-6c0-2 1-3.5 2.5-5C8 13 10 14 12 21z"/>',
    'sparkle'   => '<path d="M12 3v6"/><path d="M12 15v6"/><path d="M3 12h6"/><path d="M15 12h6"/><path d="m6 6 3 3"/><path d="m15 15 3 3"/><path d="m18 6-3 3"/><path d="m9 15-3 3"/>',
    'youtube'   => '<rect x="2" y="5" width="20" height="14" rx="4"/><path d="m10 9 5 3-5 3z"/>',
    /* AJOUT — icônes des compétences (رp-skill-card) manquantes ici,
       causaient un repli systématique sur 'star' (voir RK_MC_Skill_Service::definitions()). */
    'users'          => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
    'nav-target'     => '<circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/>',
    'skill-speech'   => '<path d="M12 2a3 3 0 0 0-3 3v7a3 3 0 0 0 6 0V5a3 3 0 0 0-3-3z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><line x1="12" x2="12" y1="19" y2="22"/>',
    'skill-creativity' => '<path d="M15 14c.2-1 .7-1.7 1.5-2.5 1-.9 1.5-2.2 1.5-3.5A6 6 0 0 0 6 8c0 1 .2 2.2 1.5 3.5.7.7 1.3 1.5 1.5 2.5"/><path d="M9 18h6"/><path d="M10 22h4"/>',
);

/**
 * Rend une icône SVG.
 *
 * @param string $key  Clé de $rkd4_paths.
 * @param int    $size Taille en px.
 * @param bool   $fill true = tracé rempli (star/play), false = contour.
 */
$rkd4_icon = static function ( string $key, int $size = 20, bool $fill = false ) use ( $rkd4_paths ): string {
    $d = $rkd4_paths[ $key ] ?? $rkd4_paths['star'];
    return sprintf(
        '<svg width="%1$d" height="%1$d" viewBox="0 0 24 24" aria-hidden="true" focusable="false"'
        . ' fill="%2$s" stroke="%3$s" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">%4$s</svg>',
        $size,
        $fill ? 'currentColor' : 'none',
        $fill ? 'none' : 'currentColor',
        $d
    );
};

/**
 * Carte promotionnelle YouTube (sidebar desktop / bandeau mobile).
 *
 * @param callable $icon    Closure $rkd4_icon.
 * @param bool     $mobile  true = variante bandeau horizontal mobile.
 */
if ( ! function_exists( 'rkd4_youtube_card' ) ) {
    function rkd4_youtube_card( callable $icon, bool $mobile = false ): string {
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
                    <?php echo $icon( 'youtube', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                    شاهد الآن
                </a>
            </div>
        </section>
        <?php
        return (string) ob_get_clean();
    }
}
