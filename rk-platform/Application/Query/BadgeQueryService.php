<?php
declare( strict_types=1 );
/**
 * Application — BadgeQueryService  (Read Model)
 *
 * Source de vérité pour les shawaret (badges) des enfants.
 * Contient le catalogue complet (définitions) et les requêtes de lecture.
 *
 * RÈGLE : aucun appel direct à $wpdb — tout passe par BadgeRepository.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class RKP_BadgeQueryService {

    // ── Catalogue ───────────────────────────────────────────────────

    /**
     * v9.19 — Mapping legacy : noms de fichier image qui divergent
     * délibérément du badge_key (voir resolve_icon_url() ci-dessous).
     * Migré depuis rk-badges.php (Modules/Children), qui dupliquait
     * cette logique en local — désormais SOURCE UNIQUE, consommée par
     * le catalogue enfant (rk-badges.php) ET la page coach
     * (RK_Coach_Badges_Controller), qui affichait jusqu'ici une icône
     * générique faute d'accès à cette résolution.
     */
    private static function icon_filename_overrides(): array {
        return [
            'level_3'      => 'discoverer.svg',      // مكتشف — nom de fichier différent du badge_key
            'first_lesson' => 'first-adventure.svg', // أول مغامرة — idem, tiret au lieu d'underscore
        ];
    }

    /**
     * Résout l'URL de l'image RÉELLE d'un badge (icône illustrée, pas
     * l'icon_key générique SVG-inline) :
     *   1. {badge_key}.svg si présent sur disque (convention préférée)
     *   2. {badge_key}.png si présent (repli, en attendant conversion SVG)
     *   3. mapping legacy ci-dessus si présent (noms divergents)
     *   4. image générique rank-badge.png en dernier recours
     *
     * Nécessite RK_MC_DIR / RK_MC_URL (constantes globales définies
     * dans rk-platform.php, disponibles quel que soit le module
     * appelant — Children ou Coach).
     */
    /**
     * @since 4.20.4 — résolution du NOM DE FICHIER seul (sans domaine ni
     * chemin), extraite de resolve_icon_url() pour que l'app mobile
     * puisse mapper vers son propre asset local bundlé
     * (assets/badges/{filename}) sans avoir à re-décoder la table de
     * correspondance ci-dessous une seconde fois, ni charger l'image par
     * le réseau. UNE SEULE table de correspondance, consommée par les
     * deux résolutions (fichier seul / URL complète) ci-dessous.
     */
    public static function resolve_icon_filename( string $badge_key ): string {
        if ( '' === $badge_key || ! defined( 'RK_MC_DIR' ) ) {
            return 'rank-badge.png';
        }

        $badges_dir = RK_MC_DIR . 'assets/img/badges/';

        foreach ( [ '.svg', '.png' ] as $ext ) {
            $auto_filename = $badge_key . $ext;
            if ( file_exists( $badges_dir . $auto_filename ) ) {
                return $auto_filename;
            }
        }

        $overrides = self::icon_filename_overrides();
        if ( isset( $overrides[ $badge_key ] ) ) {
            return $overrides[ $badge_key ];
        }

        return 'rank-badge.png';
    }

    public static function resolve_icon_url( string $badge_key ): string {
        $fallback = defined( 'RK_MC_URL' ) ? RK_MC_URL . 'assets/img/badges/rank-badge.png' : '';
        if ( '' === $badge_key || ! defined( 'RK_MC_URL' ) ) {
            return $fallback;
        }
        return RK_MC_URL . 'assets/img/badges/' . rawurlencode( self::resolve_icon_filename( $badge_key ) );
    }

    /**
     * v9.20 — Catalogue lu EXCLUSIVEMENT depuis wp_rk_custom_badges.
     * Les 22 badges "système" (auparavant un tableau PHP en dur ici)
     * ont été migrés en base une fois pour toutes (voir
     * rk_coach_hub_upgrade_db() dans rk-platform.php, option
     * 'rkp_system_badges_migrated_v1') — is_system=1 les distingue des
     * vrais badges custom créés par un coach (is_system=0), mais les
     * deux vivent désormais dans la même table et sont également
     * modifiables/supprimables depuis la page coach (décision explicite
     * de l'utilisateur : plus de distinction lecture-seule/éditable).
     * badge_key préservés à l'identique lors de la migration — aucune
     * rupture pour les attributions déjà existantes dans
     * wp_rk_child_badges.
     */
    public static function catalogue(): array {
        static $full = null;
        if ( $full !== null ) return $full;

        $base = [];
        foreach ( RKP_CustomBadgeRepository::find_all() as $row ) {
            $base[ (string) $row->badge_key ] = [
                'icon_key'  => (string) $row->icon_key,
                'name'      => (string) $row->name,
                'cat'       => (string) $row->cat,
                'type'      => isset( $row->badge_type ) && '' !== (string) $row->badge_type ? (string) $row->badge_type : null,
                'desc'      => (string) $row->desc,
                'custom'    => true,
                'is_system' => (bool) ( $row->is_system ?? false ),
                'coach_id'  => (int) $row->coach_id,
            ];
        }

        // icon_url résolue UNE FOIS ici (mémoïsée avec le reste du
        // catalogue via `static $full`), pour que chaque consommateur
        // (catalogue enfant, page coach) obtienne directement l'URL de
        // la vraie image sans avoir à réimplémenter resolve_icon_url()
        // localement.
        foreach ( $base as $key => &$def ) {
            $def['icon_url']      = self::resolve_icon_url( (string) $key );
            $def['icon_filename'] = self::resolve_icon_filename( (string) $key );
        }
        unset( $def );

        $full = $base;
        return $full;
    }

    // ── Reads ───────────────────────────────────────────────────────

    public static function has_badge( int $child_rk_id, string $badge_key ): bool {
        return RKP_BadgeRepository::has_badge( $child_rk_id, $badge_key );
    }

    public static function count_this_month( int $child_rk_id ): int {
        return RKP_BadgeRepository::count_this_month( $child_rk_id );
    }

    /** Badges gagnés, enrichis avec les définitions du catalogue. */
    public static function get_badges( int $child_rk_id ): array {
        $rows = RKP_BadgeRepository::find_all( $child_rk_id );
        $cat  = self::catalogue();
        $out  = [];
        foreach ( $rows as $row ) {
            $def = $cat[ $row->badge_key ] ?? null;
            if ( $def ) {
                $earned_at = $row->earned_at ?? null;
                $seen_at   = $row->seen_at ?? null;
                $out[] = array_merge( $def, [
                    'key'       => $row->badge_key,
                    'earned_at' => $earned_at,
                    'seen_at'   => $seen_at,
                    // v9.12 — RÈGLE UNIQUE, appliquée explicitement en
                    // entier même ici où earned_at ne peut structurellement
                    // pas être null ($row vient de find_all(), donc une
                    // vraie ligne existante) — écrire la formule complète
                    // partout, jamais un raccourci "seen_at===null" seul,
                    // pour qu'aucune réutilisation future de ce code ne
                    // puisse silencieusement devenir incorrecte.
                    'is_new'    => self::compute_is_new( $earned_at, $seen_at ),
                    'note'      => $row->note,
                ] );
            }
        }
        return $out;
    }

    /** Catalogue complet avec flag earned/locked pour un enfant. */
    public static function get_catalogue_for_child( int $child_rk_id ): array {
        $badges = self::get_badges( $child_rk_id );

        /*
         * v9.58 — CORRECTIF DE DESYNCHRONISATION (409 award en boucle).
         *
         * $earned_keys etait derive de get_badges(), qui ECARTE toute
         * ligne dont la badge_key est absente du catalogue courant
         * (voir le `if ( $def )` dans get_badges()). Or award() teste
         * l'existence avec RKP_BadgeRepository::has_badge(), qui lit la
         * table BRUTE sans ce filtre. Les deux sources pouvaient donc
         * se contredire :
         *
         *   base      : la ligne existe        -> has_badge() = true
         *   vue enfant: ligne ecartee          -> earned      = false
         *
         * Resultat observe : le bouton restait bloque sur "منح", chaque
         * clic renvoyait 409 'already_awarded', et la resynchronisation
         * qui suit le 409 reaffichait... "منح". Boucle sans issue, sans
         * aucun moyen pour le coach de s'en sortir.
         *
         * On lit desormais les cles obtenues DIRECTEMENT depuis le
         * repository : meme source de verite que has_badge(). Un badge
         * present en base est marque earned, meme si sa definition a
         * disparu du catalogue (badge d'un autre coach, badge custom
         * supprime, cle legacy...).
         */
        $earned_keys = array_column( $badges, 'key' );

        if ( class_exists( 'RKP_BadgeRepository' ) ) {
            foreach ( RKP_BadgeRepository::find_all( $child_rk_id ) as $row ) {
                $raw_key = (string) ( $row->badge_key ?? '' );
                if ( '' !== $raw_key && ! in_array( $raw_key, $earned_keys, true ) ) {
                    $earned_keys[] = $raw_key;
                }
            }
        }

        $out = [];
        foreach ( self::catalogue() as $key => $def ) {
            $earned_at = null;
            $seen_at   = null;
            foreach ( $badges as $b ) {
                if ( $b['key'] === $key ) {
                    $earned_at = $b['earned_at'];
                    $seen_at   = $b['seen_at'];
                    break;
                }
            }
            // v9.12 — Badge non gagné (aucune ligne trouvée ci-dessus) :
            // $earned_at reste null → compute_is_new() retourne false via
            // la même formule unique, pas un flag codé en dur séparément
            // (§CAS C explicitement vérifié : catalogue non gagné → is_new
            // = false, dérivé de la règle, pas d'une initialisation à part).
            $out[] = array_merge( $def, [
                'key'       => $key,
                'earned'    => in_array( $key, $earned_keys, true ),
                'earned_at' => $earned_at,
                'seen_at'   => $seen_at,
                'is_new'    => self::compute_is_new( $earned_at, $seen_at ),
            ] );
        }
        return $out;
    }

    /**
     * RÈGLE MÉTIER UNIQUE pour is_new — validée explicitement avec
     * l'utilisateur, jamais dupliquée ou reformulée ailleurs :
     *   is_new = (earned_at !== null) ET (seen_at === null)
     * Aucune heuristique temporelle. §CAS D (données anormales,
     * earned_at=null ET seen_at=null) → false, couvert naturellement par
     * le ET logique (pas un cas spécial séparé).
     */
    private static function compute_is_new( ?string $earned_at, ?string $seen_at ): bool {
        return ( null !== $earned_at ) && ( null === $seen_at );
    }
}