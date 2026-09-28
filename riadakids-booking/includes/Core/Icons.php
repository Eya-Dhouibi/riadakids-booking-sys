<?php
/**
 * RiadaKids\Core\Icons — bibliothèque d'icônes SVG inline.
 *
 * Remplace les emojis de l'interface. Les emojis posaient trois problèmes :
 *   - WordPress les convertit en <img> distants (s.w.org) : requêtes externes
 *     et rendu incohérent d'un appareil à l'autre ;
 *   - leur graphisme varie selon l'OS (Apple, Google, Windows) ;
 *   - impossible de les aligner sur la charte couleur du site.
 *
 * Les SVG héritent de `currentColor` : ils prennent automatiquement la couleur
 * du texte parent, y compris en survol ou en état actif.
 *
 * NOTE : les emojis sont CONSERVÉS dans les emails (RewardNotifier) — la
 * plupart des clients de messagerie bloquent ou dégradent le SVG inline.
 *
 * @package RiadaKids\Core
 */

namespace RiadaKids\Core;

if ( ! defined( 'ABSPATH' ) ) exit;

class Icons {

    /**
     * Tracés SVG (contenu interne de la balise <svg>), viewBox 24×24.
     * Style : trait uniquement, arrondi — cohérent avec l'existant.
     */
    private const PATHS = [

        /* ── Temps & agenda ─────────────────────────────────────── */
        'calendar'  => '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>',
        'clock'     => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'hourglass' => '<path d="M6 2h12M6 22h12M8 2v4a4 4 0 0 0 4 4 4 4 0 0 0 4-4V2M8 22v-4a4 4 0 0 1 4-4 4 4 0 0 1 4 4v4"/>',
        'refresh'   => '<path d="M21 12a9 9 0 1 1-2.6-6.4"/><path d="M21 3v6h-6"/>',

        /* ── États ──────────────────────────────────────────────── */
        'check'        => '<path d="M20 6 9 17l-5-5"/>',
        'check-circle' => '<circle cx="12" cy="12" r="9"/><path d="M8.5 12.5l2.5 2.5 4.5-5"/>',
        'x'            => '<path d="M18 6 6 18M6 6l12 12"/>',
        'x-circle'     => '<circle cx="12" cy="12" r="9"/><path d="M15 9l-6 6M9 9l6 6"/>',
        'alert'        => '<path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/><path d="M12 9v4M12 17h.01"/>',
        'info'         => '<circle cx="12" cy="12" r="9"/><path d="M12 16v-4M12 8h.01"/>',

        /* ── Verrouillage ───────────────────────────────────────── */
        'lock'        => '<rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
        'unlock'      => '<rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 9.9-1"/>',

        /* ── Actions ────────────────────────────────────────────── */
        'edit'    => '<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/>',
        'plus'    => '<path d="M12 5v14M5 12h14"/>',
        'trash'   => '<path d="M3 6h18M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>',
        'search'  => '<circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/>',
        'camera'  => '<path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/>',
        'cart'    => '<circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.7 13.4a2 2 0 0 0 2 1.6h9.7a2 2 0 0 0 2-1.6L23 6H6"/>',
        'settings' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.9-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1A1.7 1.7 0 0 0 9 19.4a1.7 1.7 0 0 0-1.9.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.9 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1A1.7 1.7 0 0 0 4.6 9a1.7 1.7 0 0 0-.3-1.9l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.9.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.9-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.9V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>',

        /* ── Contenu ────────────────────────────────────────────── */
        'target'   => '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="5"/><circle cx="12" cy="12" r="1.5"/>',
        'list'     => '<path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/>',
        'pin'      => '<path d="M12 21s7-5.7 7-11a7 7 0 1 0-14 0c0 5.3 7 11 7 11z"/><circle cx="12" cy="10" r="2.5"/>',
        'inbox'    => '<path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.5 5.5 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.5-6.5A2 2 0 0 0 16.7 4H7.3a2 2 0 0 0-1.8 1.5z"/>',
        'database' => '<ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5v14c0 1.7 4 3 9 3s9-1.3 9-3V5"/><path d="M3 12c0 1.7 4 3 9 3s9-1.3 9-3"/>',
        'user'     => '<circle cx="12" cy="7" r="4"/><path d="M6 21v-2a4 4 0 0 1 4-4h4a4 4 0 0 1 4 4v2"/>',
        'video'    => '<rect x="1" y="5" width="15" height="14" rx="2"/><path d="m23 7-6 5 6 5V7z"/>',
        'graduation-cap' => '<path d="M22 10 12 5 2 10l10 5 10-5z"/><path d="M6 12v5c0 1.1 2.7 3 6 3s6-1.9 6-3v-5"/><path d="M22 10v6"/>',

        /* ── Badges de programme (repris à l'identique de BookingForm.php,
           où ils étaient dupliqués en dur par slug — centralisés ici pour
           être réutilisés aussi sur les cartes de l'historique, voir
           Icons::program_icon_name()) ── */
        'program-languages' => '<path d="M4 19.5V4.5C4 3.83696 4.26339 3.20107 4.73223 2.73223C5.20107 2.26339 5.83696 2 6.5 2H19C19.2652 2 19.5196 2.10536 19.7071 2.29289C19.8946 2.48043 20 2.73478 20 3V21C20 21.2652 19.8946 21.5196 19.7071 21.7071C19.5196 21.8946 19.2652 22 19 22H6.5C5.83696 22 5.20107 21.7366 4.73223 21.2678C4.26339 20.7989 4 20.163 4 19.5ZM4 19.5C4 18.837 4.26339 18.2011 4.73223 17.7322C5.20107 17.2634 5.83696 17 6.5 17H20"/><path d="M8 13L12 6L16 13"/><path d="M9.09998 11H14.8"/>',
        'program-software'  => '<path d="M18 16L22 12L18 8"/><path d="M6 8L2 12L6 16"/><path d="M14.5 4L9.50003 20"/>',
        'program-ai'        => '<path d="M12 4.99999C12.0012 4.60002 11.9224 4.20385 11.7682 3.83479C11.614 3.46572 11.3876 3.13122 11.1023 2.85093C10.8169 2.57065 10.4784 2.35026 10.1067 2.20272C9.73491 2.05518 9.33739 1.98347 8.93751 1.9918C8.53762 2.00014 8.14344 2.08835 7.77815 2.25126C7.41286 2.41416 7.08383 2.64847 6.81041 2.94039C6.537 3.23232 6.32472 3.57597 6.18606 3.95114C6.04741 4.32631 5.98517 4.72542 6.00301 5.12499C5.41521 5.27613 4.86952 5.55904 4.40724 5.9523C3.94497 6.34556 3.57825 6.83886 3.33486 7.39484C3.09146 7.95081 2.97777 8.55488 3.0024 9.1613C3.02703 9.76772 3.18933 10.3606 3.47701 10.895C2.97119 11.3059 2.57344 11.8342 2.31835 12.4339C2.06327 13.0336 1.95857 13.6866 2.01338 14.336C2.06818 14.9854 2.28083 15.6115 2.63282 16.16C2.98481 16.7085 3.46548 17.1626 4.03301 17.483C3.96293 18.0252 4.00475 18.5761 4.1559 19.1015C4.30705 19.627 4.56431 20.1158 4.9118 20.5379C5.25929 20.9601 5.68962 21.3065 6.17623 21.5557C6.66284 21.805 7.19539 21.9519 7.74099 21.9873C8.28659 22.0227 8.83365 21.9459 9.3484 21.7616C9.86315 21.5773 10.3346 21.2894 10.7338 20.9157C11.1329 20.5421 11.4512 20.0906 11.669 19.5891C11.8868 19.0876 11.9994 18.5467 12 18V4.99999Z"/><path d="M9 13C9.83956 12.7047 10.5727 12.167 11.1067 11.455C11.6407 10.743 11.9515 9.88867 12 9"/><path d="M6.00299 5.125C6.02277 5.60873 6.15932 6.0805 6.40099 6.5"/><path d="M3.47699 10.896C3.65993 10.747 3.85569 10.6145 4.06199 10.5"/><path d="M5.99999 18C5.31082 18.0003 4.63326 17.8226 4.03299 17.484"/><path d="M12 13H16"/><path d="M12 18H18C18.5304 18 19.0391 18.2107 19.4142 18.5858C19.7893 18.9609 20 19.4696 20 20V21"/><path d="M12 8H20"/><path d="M16 8V5C16 4.46957 16.2107 3.96086 16.5858 3.58579C16.9609 3.21071 17.4696 3 18 3"/><path d="M16 13.5C16.2761 13.5 16.5 13.2761 16.5 13C16.5 12.7239 16.2761 12.5 16 12.5C15.7239 12.5 15.5 12.7239 15.5 13C15.5 13.2761 15.7239 13.5 16 13.5Z"/><path d="M18 3.5C18.2761 3.5 18.5 3.27614 18.5 3C18.5 2.72386 18.2761 2.5 18 2.5C17.7239 2.5 17.5 2.72386 17.5 3C17.5 3.27614 17.7239 3.5 18 3.5Z"/><path d="M20 21.5C20.2761 21.5 20.5 21.2761 20.5 21C20.5 20.7239 20.2761 20.5 20 20.5C19.7239 20.5 19.5 20.7239 19.5 21C19.5 21.2761 19.7239 21.5 20 21.5Z"/><path d="M20 8.5C20.2761 8.5 20.5 8.27614 20.5 8C20.5 7.72386 20.2761 7.5 20 7.5C19.7239 7.5 19.5 7.72386 19.5 8C19.5 8.27614 19.7239 8.5 20 8.5Z"/>',

        /* ── Récompenses ────────────────────────────────────────── */
        'trophy' => '<path d="M8 21h8M12 17v4M7 4h10v5a5 5 0 0 1-10 0z"/><path d="M17 5h3a3 3 0 0 1-3 3M7 5H4a3 3 0 0 0 3 3"/>',
        'gift'   => '<rect x="3" y="8" width="18" height="4" rx="1"/><path d="M5 12v9a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-9M12 8v14"/><path d="M12 8S10.5 4 8.5 4a2.5 2.5 0 0 0 0 5M12 8s1.5-4 3.5-4a2.5 2.5 0 0 1 0 5"/>',
        'medal'  => '<circle cx="12" cy="15" r="6"/><path d="M9 9 7 2h10l-2 7"/><path d="m12 12.5 1 2 2 .3-1.5 1.4.4 2.1-1.9-1-1.9 1 .4-2.1L9 14.8l2-.3z"/>',
        'party'  => '<path d="M3 21 8 8l8 8z"/><path d="M15 5a2 2 0 0 1 2-2M19 9a2 2 0 0 1 2-2M14 10l1-1M18 14l1-1"/>',
    ];

    /**
     * Retourne une icône SVG inline.
     *
     * @param string $name  Clé dans self::PATHS.
     * @param int    $size  Taille en pixels (carré).
     * @param array  $args  'class', 'stroke_width', 'style'.
     * @return string SVG, ou '' si l'icône n'existe pas.
     */
    public static function get( string $name, int $size = 16, array $args = [] ): string {
        if ( ! isset( self::PATHS[ $name ] ) ) {
            return '';
        }

        $class  = isset( $args['class'] ) ? ' ' . sanitize_html_class( $args['class'] ) : '';
        $width  = isset( $args['stroke_width'] ) ? (float) $args['stroke_width'] : 2;
        $style  = isset( $args['style'] ) ? ' style="' . esc_attr( $args['style'] ) . '"' : '';

        // Tous les spécificateurs sont positionnels : mélanger %s et %1$d
        // fait repartir la numérotation à zéro et produit width="0".
        return sprintf(
            '<svg class="rk-icon%1$s" width="%2$d" height="%2$d" viewBox="0 0 24 24" fill="none"'
            . ' stroke="currentColor" stroke-width="%3$s" stroke-linecap="round" stroke-linejoin="round"'
            . ' aria-hidden="true" focusable="false"%4$s>%5$s</svg>',
            $class,
            $size,
            $width,
            $style,
            self::PATHS[ $name ]
        );
    }

    /** Écho direct — raccourci pour les templates. */
    public static function render( string $name, int $size = 16, array $args = [] ): void {
        echo self::get( $name, $size, $args ); // phpcs:ignore WordPress.Security.EscapeOutput -- SVG interne
    }

    /**
     * Mappe le slug d'un terme de programme (course-category) vers le nom
     * d'icône PATHS correspondant. Même mapping que celui dupliqué en dur
     * dans BookingForm.php ($program_visuals) — centralisé ici pour être
     * réutilisé côté historique des réservations (voir Dashboard::
     * render_booking_card()). '' si le slug n'a pas d'icône dédiée : le
     * caller doit alors omettre le badge plutôt qu'afficher un repli
     * inventé.
     */
    public static function program_icon_name( string $slug ): string {
        return [
            'tech-makers'        => 'program-software',
            'future-innovators'  => 'program-ai',
            'kids-languages'     => 'program-languages',
        ][ $slug ] ?? '';
    }

    /**
     * Jeu d'icônes exposé au JavaScript.
     * Évite de dupliquer les tracés SVG dans les fichiers .js.
     *
     * @return array<string,string>
     */
    public static function for_js(): array {
        $keys = [
            'check', 'check-circle', 'x', 'x-circle', 'alert', 'info',
            'calendar', 'clock', 'hourglass', 'refresh', 'target', 'pin',
            'user', 'video', 'graduation-cap',
        ];

        $out = [];
        foreach ( $keys as $k ) {
            $out[ $k ] = self::get( $k, 16 );
        }
        return $out;
    }
}