<?php
/**
 * RK – My Children | includes/rk-mc-svg-icons.php
 *
 * Bibliothèque centralisée des icônes SVG inline.
 * Utilisée à la fois en PHP (templates) et reflétée en JS (RKChildrenSVG).
 *
 * Avantages de la centralisation :
 *  - Un seul endroit pour modifier une icône (PHP + JS synchronisés).
 *  - Pas de dépendance à des polices d'icônes ou fichiers externes.
 *  - Compatible RTL, accessible (aria-hidden="true").
 *
 * @since 5.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Retourne une icône SVG inline par clé.
 *
 * @param  string $key   Clé de l'icône (cake, calendar, enter, chart, book, edit, delete, empty, check, spin, plus, close).
 * @param  array  $attrs Attributs HTML supplémentaires (class, style, etc.).
 * @return string        Balise SVG prête à l'emploi.
 */
function rk_mc_svg( $key, $attrs = array() ) {
    $icons = rk_mc_svg_library();

    if ( ! isset( $icons[ $key ] ) ) {
        return '';
    }

    $svg = $icons[ $key ];

    // Injection d'attributs supplémentaires sur la balise <svg>
    if ( ! empty( $attrs ) ) {
        $attr_str = '';
        foreach ( $attrs as $attr_key => $attr_val ) {
            $attr_str .= ' ' . esc_attr( $attr_key ) . '="' . esc_attr( $attr_val ) . '"';
        }
        $svg = str_replace( '<svg', '<svg' . $attr_str, $svg );
    }

    return $svg;
}

/**
 * Bibliothèque de toutes les icônes SVG du plugin.
 * Taille par défaut 14×14 pour les icônes inline, 16×16 pour les boutons,
 * 24×24+ pour les icônes de section/hero.
 *
 * @return array<string, string>
 */
function rk_mc_svg_library() {
    return array(

        /* ── Métadonnées enfant ── */
        'cake' =>
            '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            . '<path d="M20 21v-8a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v8"/>'
            . '<path d="M4 16s.5-1 2-1 2.5 2 4 2 2.5-2 4-2 2.5 2 4 2 2-1 2-1"/>'
            . '<path d="M2 21h20"/>'
            . '<path d="M7 8v2"/><path d="M12 8v2"/><path d="M17 8v2"/>'
            . '<circle cx="7" cy="5" r="1.5" fill="currentColor" stroke="none"/>'
            . '<circle cx="12" cy="5" r="1.5" fill="currentColor" stroke="none"/>'
            . '<circle cx="17" cy="5" r="1.5" fill="currentColor" stroke="none"/>'
            . '</svg>',

        'calendar' =>
            '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            . '<rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>'
            . '<line x1="16" y1="2" x2="16" y2="6"/>'
            . '<line x1="8" y1="2" x2="8" y2="6"/>'
            . '<line x1="3" y1="10" x2="21" y2="10"/>'
            . '</svg>',

        /* ── Actions carte ── */
        'enter' =>
            '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            . '<path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/>'
            . '<polyline points="10 17 15 12 10 7"/>'
            . '<line x1="15" y1="12" x2="3" y2="12"/>'
            . '</svg>',

        'chart' =>
            '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            . '<line x1="18" y1="20" x2="18" y2="10"/>'
            . '<line x1="12" y1="20" x2="12" y2="4"/>'
            . '<line x1="6" y1="20" x2="6" y2="14"/>'
            . '<line x1="2" y1="20" x2="22" y2="20"/>'
            . '</svg>',

        'book' =>
            '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            . '<rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>'
            . '<line x1="16" y1="2" x2="16" y2="6"/>'
            . '<line x1="8" y1="2" x2="8" y2="6"/>'
            . '<line x1="3" y1="10" x2="21" y2="10"/>'
            . '<polyline points="9 16 11 18 15 14"/>'
            . '</svg>',

        /* ── CRUD ── */
        'edit' =>
            '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            . '<path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>'
            . '<path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>'
            . '</svg>',

        'delete' =>
            '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            . '<polyline points="3 6 5 6 21 6"/>'
            . '<path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>'
            . '<path d="M10 11v6"/><path d="M14 11v6"/>'
            . '<path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/>'
            . '</svg>',

        /* ── États ── */
        'empty' =>
            '<svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            . '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>'
            . '<circle cx="9" cy="7" r="4"/>'
            . '<line x1="17" y1="11" x2="23" y2="11"/>'
            . '<line x1="20" y1="8" x2="20" y2="14"/>'
            . '</svg>',

        'check' =>
            '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            . '<polyline points="20 6 9 17 4 12"/>'
            . '</svg>',

        /* ── Page "المغامرة" (rk-adventure.php) — icône devant chaque
           leçon active, fournie par le design (Button.svg). Coordonnées
           conservées telles quelles (viewBox 0 0 24 24, origine décalée
           de -8/-6 par rapport au badge 64×64 fourni) pour rester
           fidèle au tracé d'origine plutôt que de le reconstruire à la
           main dans un autre repère, risquant d'en altérer la forme. */
        'lesson-active' =>
            '<svg width="16" height="16" viewBox="14 15 28 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            . '<path d="M32 23V39"/>'
            . '<path d="M36 31H38"/>'
            . '<path d="M36 27H38"/>'
            . '<path d="M40.001 37C40.5313 36.9997 41.0397 36.7889 41.4146 36.4139C41.7894 36.0388 42 35.5303 42 35V23C42 22.4697 41.7894 21.9612 41.4146 21.5861C41.0397 21.2111 40.5313 21.0003 40.001 21L36 21.002C35.2239 21.0018 34.4585 21.1822 33.7642 21.529C33.07 21.8758 32.4659 22.3794 32 23C31.5343 22.379 30.9303 21.875 30.2361 21.5279C29.5418 21.1807 28.7762 21 28 21H24C23.4696 21 22.9609 21.2107 22.5858 21.5858C22.2107 21.9609 22 22.4696 22 23V35C22 35.5303 22.2106 36.0388 22.5854 36.4139C22.9603 36.7889 23.4687 36.9997 23.999 37H28C28.7762 37 29.5418 37.1807 30.2361 37.5279C30.9303 37.875 31.5343 38.379 32 39C32.4657 38.379 33.0697 37.875 33.7639 37.5279C34.4582 37.1807 35.2238 37 36 37H40.001Z"/>'
            . '<path d="M26 31H28"/>'
            . '<path d="M26 27H28"/>'
            . '</svg>',

        /* ── UI ── */
        'spin' =>
            '<svg class="rk-spin" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true">'
            . '<path d="M21 12a9 9 0 1 1-6.219-8.56"/>'
            . '</svg>',

        'plus' =>
            '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            . '<line x1="12" y1="5" x2="12" y2="19"/>'
            . '<line x1="5" y1="12" x2="19" y2="12"/>'
            . '</svg>',

        'close' =>
            '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true">'
            . '<line x1="18" y1="6" x2="6" y2="18"/>'
            . '<line x1="6" y1="6" x2="18" y2="18"/>'
            . '</svg>',

        /* ── Hero ── */
        'hero-users' =>
            '<svg width="42" height="42" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">'
            . '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>'
            . '<circle cx="9" cy="7" r="4"/>'
            . '<path d="M23 21v-2a4 4 0 0 0-3-3.87"/>'
            . '<path d="M16 3.13a4 4 0 0 1 0 7.75"/>'
            . '</svg>',

        'stat-user' =>
            '<svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">'
            . '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>'
            . '<circle cx="12" cy="7" r="4"/>'
            . '</svg>',

        'stat-calendar' =>
            '<svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">'
            . '<rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>'
            . '<line x1="16" y1="2" x2="16" y2="6"/>'
            . '<line x1="8" y1="2" x2="8" y2="6"/>'
            . '<line x1="3" y1="10" x2="21" y2="10"/>'
            . '</svg>',

        'stat-school' =>
            '<svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">'
            . '<path d="M22 10v6M2 10l10-5 10 5-10 5z"/>'
            . '<path d="M6 12v5c3 3 9 3 12 0v-5"/>'
            . '</svg>',

        'stat-card' =>
            '<svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">'
            . '<rect x="1" y="4" width="22" height="16" rx="2" ry="2"/>'
            . '<line x1="1" y1="10" x2="23" y2="10"/>'
            . '</svg>',

        /* ── Dashboard navigation ───────────────────────────────── */
        'nav-home' =>
            '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            . '<path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>'
            . '<polyline points="9 22 9 12 15 12 15 22"/>'
            . '</svg>',

        'nav-compass' =>
            '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            . '<circle cx="12" cy="12" r="10"/>'
            . '<polygon points="16.24 7.76 14.12 14.12 7.76 16.24 9.88 9.88 16.24 7.76"/>'
            . '</svg>',

        'nav-target' =>
            '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            . '<circle cx="12" cy="12" r="10"/>'
            . '<circle cx="12" cy="12" r="6"/>'
            . '<circle cx="12" cy="12" r="2"/>'
            . '</svg>',

        'nav-medal' =>
            '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            . '<circle cx="12" cy="8" r="6"/>'
            . '<path d="M15.477 12.89 17 22l-5-3-5 3 1.523-9.11"/>'
            . '</svg>',

        /* ── Gamification ────────────────────────────────────────── */
        'lightning' =>
            '<svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor" stroke="none" aria-hidden="true">'
            . '<path d="M13 2 3 14h9l-1 8 10-12h-9l1-8z"/>'
            . '</svg>',

        'star' =>
            '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            . '<polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>'
            . '</svg>',

        'trophy' =>
            '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            . '<path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6"/>'
            . '<path d="M18 9h1.5a2.5 2.5 0 0 0 0-5H18"/>'
            . '<path d="M4 22h16"/>'
            . '<path d="M10 14.66V17c0 .55-.47.98-.97 1.21C7.85 18.75 7 20.24 7 22"/>'
            . '<path d="M14 14.66V17c0 .55.47.98.97 1.21C16.15 18.75 17 20.24 17 22"/>'
            . '<path d="M18 2H6v7a6 6 0 0 0 12 0V2Z"/>'
            . '</svg>',

        /* ── Ajouts v9.9 — famille SVG demandée (Journey/Session/XP/
           Progress/Certificate/Unlock/Coach), mêmes conventions de style
           que le reste du registre (stroke 2px, viewBox 24×24, coins
           arrondis) : pas de nouveau design system, extension du même. */

        'journey' =>
            '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            . '<path d="M3 20c3-6 6-2 9-8s6-2 9-8"/>'
            . '<circle cx="3" cy="20" r="1.5"/>'
            . '<circle cx="21" cy="4" r="1.5"/>'
            . '</svg>',

        'session' =>
            '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            . '<rect x="3" y="5" width="18" height="16" rx="2"/>'
            . '<path d="M16 3v4M8 3v4M3 10h18"/>'
            . '<path d="M12 14v3l2 1.5"/>'
            . '</svg>',

        'xp' =>
            '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            . '<path d="M13 2 4 14h6l-1 8 9-12h-6l1-8z"/>'
            . '</svg>',

        'progress' =>
            '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            . '<circle cx="12" cy="12" r="9"/>'
            . '<path d="M12 3a9 9 0 0 1 9 9"/>'
            . '</svg>',

        'certificate' =>
            '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            . '<rect x="3" y="3" width="18" height="13" rx="2"/>'
            . '<path d="M8 8h8M8 12h5"/>'
            . '<path d="M9 20.5 12 18l3 2.5v-4.5H9z"/>'
            . '</svg>',

        'unlock' =>
            '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            . '<rect x="4" y="11" width="14" height="10" rx="2"/>'
            . '<path d="M8 11V7a4 4 0 0 1 7.87-1"/>'
            . '</svg>',

        'coach' =>
            '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            . '<circle cx="12" cy="7" r="4"/>'
            . '<path d="M4 21v-1a8 8 0 0 1 16 0v1"/>'
            . '<path d="M9 21v-3M15 21v-3"/>'
            . '</svg>',

        'lock' =>
            '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            . '<rect width="18" height="11" x="3" y="11" rx="2" ry="2"/>'
            . '<path d="M7 11V7a5 5 0 0 1 10 0v4"/>'
            . '</svg>',

        'graduation-cap' =>
            '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            . '<path d="M22 10v6M2 10l10-5 10 5-10 5z"/>'
            . '<path d="M6 12v5c3 3 9 3 12 0v-5"/>'
            . '</svg>',

        'clock' =>
            '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            . '<circle cx="12" cy="12" r="10"/>'
            . '<polyline points="12 6 12 12 16 14"/>'
            . '</svg>',

        'sun' =>
            '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            . '<circle cx="12" cy="12" r="4"/>'
            . '<path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41"/>'
            . '</svg>',

        'moon' =>
            '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            . '<path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>'
            . '</svg>',

        'rocket' =>
            '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            . '<path d="M4.5 16.5c-1.5 1.26-2 5-2 5s3.74-.5 5-2c.71-.84.7-2.13-.09-2.91a2.18 2.18 0 0 0-2.91-.09z"/>'
            . '<path d="M12 15l-3-3a22 22 0 0 1 2-3.95A12.88 12.88 0 0 1 22 2c0 2.72-.78 7.5-6 11a22.35 22.35 0 0 1-4 2z"/>'
            . '<path d="M9 12H4s.55-3.03 2-4c1.62-1.08 5 0 5 0"/>'
            . '<path d="M12 15v5s3.03-.55 4-2c1.08-1.62 0-5 0-5"/>'
            . '</svg>',

        'gift' =>
            '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            . '<polyline points="20 12 20 22 4 22 4 12"/>'
            . '<rect width="22" height="5" x="1" y="7"/>'
            . '<line x1="12" x2="12" y1="22" y2="7"/>'
            . '<path d="M12 7H7.5a2.5 2.5 0 0 1 0-5C11 2 12 7 12 7z"/>'
            . '<path d="M12 7h4.5a2.5 2.5 0 0 0 0-5C13 2 12 7 12 7z"/>'
            . '</svg>',

        'users' =>
            '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            . '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/>'
            . '<circle cx="9" cy="7" r="4"/>'
            . '<path d="M22 21v-2a4 4 0 0 0-3-3.87"/>'
            . '<path d="M16 3.13a4 4 0 0 1 0 7.75"/>'
            . '</svg>',

        'fire' =>
            '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            . '<path d="M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.072-2.143-.224-4.054 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.153.433-2.294 1-3a2.5 2.5 0 0 0 2.5 2.5z"/>'
            . '</svg>',

        'diamond' =>
            '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            . '<path d="M2.7 10.3a2.41 2.41 0 0 0 0 3.41l7.59 7.59a2.41 2.41 0 0 0 3.41 0l7.59-7.59a2.41 2.41 0 0 0 0-3.41l-7.59-7.59a2.41 2.41 0 0 0-3.41 0Z"/>'
            . '</svg>',

        'book-open' =>
            '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            . '<path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/>'
            . '<path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/>'
            . '</svg>',

        'skill-speech' =>
            '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            . '<path d="M12 2a3 3 0 0 0-3 3v7a3 3 0 0 0 6 0V5a3 3 0 0 0-3-3z"/>'
            . '<path d="M19 10v2a7 7 0 0 1-14 0v-2"/>'
            . '<line x1="12" x2="12" y1="19" y2="22"/>'
            . '</svg>',

        'skill-creativity' =>
            '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            . '<path d="M15 14c.2-1 .7-1.7 1.5-2.5 1-.9 1.5-2.2 1.5-3.5A6 6 0 0 0 6 8c0 1 .2 2.2 1.5 3.5.7.7 1.3 1.5 1.5 2.5"/>'
            . '<path d="M9 18h6"/>'
            . '<path d="M10 22h4"/>'
            . '</svg>',

        'award' =>
            '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            . '<circle cx="12" cy="8" r="6"/>'
            . '<path d="M15.477 12.89 17 22l-5-3-5 3 1.523-9.11"/>'
            . '</svg>',

        'check-circle' =>
            '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            . '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>'
            . '<polyline points="22 4 12 14.01 9 11.01"/>'
            . '</svg>',

        'nav-bell' =>
            '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            . '<path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>'
            . '<path d="M13.73 21a2 2 0 0 1-3.46 0"/>'
            . '</svg>',

        'nav-quiz' =>
            '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            . '<path d="M9 11l3 3L22 4"/>'
            . '<path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>'
            . '</svg>',

        /* ── Filtres مغامراتي (v9.2) — icônes par catégorie ── */
        'filter-all' =>
            '<svg width="20" height="20" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            . '<path d="M5.83334 1.66675H14.1667"/>'
            . '<path d="M4.16666 5H15.8333"/>'
            . '<path d="M15.8333 8.33325H4.16667C3.24619 8.33325 2.5 9.07944 2.5 9.99992V16.6666C2.5 17.5871 3.24619 18.3333 4.16667 18.3333H15.8333C16.7538 18.3333 17.5 17.5871 17.5 16.6666V9.99992C17.5 9.07944 16.7538 8.33325 15.8333 8.33325Z"/>'
            . '</svg>',

        'filter-business' =>
            '<svg width="20" height="20" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            . '<path d="M10 12.4999V16.6666C10 16.6666 12.525 16.2083 13.3333 14.9999C14.2333 13.6499 13.3333 10.8333 13.3333 10.8333"/>'
            . '<path d="M2.08334 17.9167C2.08334 17.9167 2.50001 14.8 3.75001 13.75C4.09241 13.4615 4.52931 13.3095 4.97686 13.3234C5.42442 13.3372 5.8511 13.5159 6.17501 13.825C6.83334 14.475 6.84168 15.55 6.25001 16.25C5.20001 17.5 2.08334 17.9167 2.08334 17.9167Z"/>'
            . '<path d="M7.5 10C7.94345 8.84957 8.50184 7.74676 9.16667 6.70838C10.1377 5.15587 11.4897 3.87758 13.0942 2.99512C14.6986 2.11266 16.5022 1.65535 18.3333 1.66671C18.3333 3.93338 17.6833 7.91671 13.3333 10.8334C12.2806 11.4987 11.1639 12.0571 10 12.5L7.5 10Z"/>'
            . '<path d="M7.50001 9.99991H3.33334C3.33334 9.99991 3.79168 7.47491 5.00001 6.66658C6.35001 5.76658 9.16668 6.70824 9.16668 6.70824"/>'
            . '</svg>',

        'filter-logic' =>
            '<svg width="20" height="20" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            . '<path d="M3.33331 16.2501V3.75008C3.33331 3.19755 3.55281 2.66764 3.94351 2.27694C4.33421 1.88624 4.86411 1.66675 5.41665 1.66675H15.8333C16.0543 1.66675 16.2663 1.75455 16.4226 1.91083C16.5788 2.06711 16.6666 2.27907 16.6666 2.50008V17.5001C16.6666 17.7211 16.5788 17.9331 16.4226 18.0893C16.2663 18.2456 16.0543 18.3334 15.8333 18.3334H5.41665C4.86411 18.3334 4.33421 18.1139 3.94351 17.7232C3.55281 17.3325 3.33331 16.8026 3.33331 16.2501ZM3.33331 16.2501C3.33331 15.6975 3.55281 15.1676 3.94351 14.7769C4.33421 14.3862 4.86411 14.1667 5.41665 14.1667H16.6666"/>'
            . '<path d="M6.66669 10.8333L10 5L13.3334 10.8333"/>'
            . '<path d="M7.58331 9.16675H12.3333"/>'
            . '</svg>',

        'filter-english' =>
            '<svg width="20" height="20" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            . '<path d="M15 13.3334L18.3333 10.0001L15 6.66675"/>'
            . '<path d="M5.00002 6.66675L1.66669 10.0001L5.00002 13.3334"/>'
            . '<path d="M12.0834 3.33325L7.91669 16.6666"/>'
            . '</svg>',

        'filter-ai' =>
            '<svg width="20" height="20" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            . '<path d="M10 4.16655C10.001 3.83324 9.93532 3.5031 9.80685 3.19555C9.67837 2.88799 9.4897 2.60923 9.25191 2.37567C9.01413 2.1421 8.73204 1.95844 8.42224 1.83549C8.11243 1.71254 7.78117 1.65278 7.44793 1.65972C7.1147 1.66667 6.78621 1.74018 6.4818 1.87593C6.17739 2.01169 5.9032 2.20694 5.67536 2.45022C5.44751 2.69349 5.27061 2.97987 5.15506 3.29251C5.03952 3.60515 4.98765 3.93774 5.00252 4.27071C4.51269 4.39666 4.05794 4.63242 3.67271 4.96014C3.28749 5.28786 2.98189 5.69894 2.77906 6.16225C2.57623 6.62556 2.48149 7.12896 2.50201 7.63431C2.52254 8.13965 2.65779 8.63371 2.89752 9.07905C2.476 9.42149 2.14454 9.86174 1.93197 10.3615C1.7194 10.8612 1.63215 11.4054 1.67782 11.9465C1.7235 12.4877 1.9007 13.0095 2.19403 13.4666C2.48735 13.9236 2.88791 14.3021 3.36085 14.569C3.30245 15.0209 3.3373 15.4799 3.46326 15.9178C3.58922 16.3557 3.8036 16.7631 4.09318 17.1148C4.38275 17.4666 4.74136 17.7553 5.14687 17.963C5.55238 18.1708 5.99617 18.2932 6.45083 18.3227C6.9055 18.3522 7.36139 18.2881 7.79034 18.1346C8.2193 17.981 8.61221 17.7411 8.94482 17.4297C9.27743 17.1183 9.54267 16.742 9.72416 16.3241C9.90565 15.9062 9.99953 15.4555 10 14.9999V4.16655Z"/>'
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
            . '<path d="M16.6667 7.08333C16.8968 7.08333 17.0833 6.89679 17.0833 6.66667C17.0833 6.43655 16.8968 6.25 16.6667 6.25C16.4365 6.25 16.25 6.43655 16.25 6.66667C16.25 6.89679 16.4365 7.08333 16.6667 7.08333Z"/>'
            . '</svg>',
    );
}

/**
 * Retourne l'icône SVG appropriée pour une clé de badge.
 *
 * @param  string $badge_key  Clé du badge (ex: 'first_session', 'trophy', 'level_6').
 * @param  array  $attrs      Attributs HTML supplémentaires.
 * @return string             Balise SVG.
 */
function rk_mc_badge_svg( $badge_key, $attrs = array() ) {
    $map = array(
        'first_session'   => 'star',
        'first_lesson'    => 'graduation-cap',
        'first_quiz'      => 'nav-target',
        'first_meeting'   => 'users',
        'streak_week'     => 'fire',
        'perfect_month'   => 'diamond',
        'unstoppable'     => 'lightning',
        'moon_regular'    => 'moon',
        'course_complete' => 'graduation-cap',
        'quiz_perfect'    => 'trophy',
        'genius'          => 'lightning',
        'precise'         => 'nav-target',
        'leader'          => 'trophy',
        'speaker'         => 'skill-speech',
        'teamwork'        => 'users',
        'creative'        => 'skill-creativity',
        'anniversary'     => 'gift',
        'star_of_month'   => 'star',
        'first_hero'      => 'award',
        'level_2'         => 'nav-compass',
        'level_3'         => 'graduation-cap',
        'level_4'         => 'lightning',
        'level_5'         => 'rocket',
        'level_6'         => 'rocket',
        'level_7'         => 'trophy',
        'level_8'         => 'star',
    );
    $defaults  = array( 'width' => '28', 'height' => '28' );
    $icon_key  = isset( $map[ $badge_key ] ) ? $map[ $badge_key ] : 'trophy';
    return rk_mc_svg( $icon_key, array_merge( $defaults, $attrs ) );
}

/**
 * Retourne l'icône SVG pour une compétence enfant (قوى).
 *
 * @param  string $skill_key  Clé de la compétence (speech, teamwork, creativity, courage, leadership, focus).
 * @param  array  $attrs      Attributs HTML supplémentaires.
 * @return string             Balise SVG.
 */
function rk_mc_skill_svg( $skill_key, $attrs = array() ) {
    $map = array(
        'speech'     => 'skill-speech',
        'teamwork'   => 'users',
        'creativity' => 'skill-creativity',
        'courage'    => 'trophy',
        'leadership' => 'rocket',
        'focus'      => 'nav-target',
    );
    $defaults  = array( 'width' => '24', 'height' => '24' );
    $icon_key  = isset( $map[ $skill_key ] ) ? $map[ $skill_key ] : 'lightning';
    return rk_mc_svg( $icon_key, array_merge( $defaults, $attrs ) );
}
