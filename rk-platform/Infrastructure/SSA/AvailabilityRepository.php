<?php
declare( strict_types=1 );
/**
 * Infrastructure — AvailabilityRepository
 *
 * Lecture/écriture de la disponibilité SSA (table wp_ssa_appointment_types,
 * colonne `availability`) — AUCUNE logique parallèle : on écrit directement
 * dans la même source que le calendrier de réservation public de SSA.
 *
 * Analyse préalable (confirmée via un diagnostic exécuté sur le site réel,
 * pas de la documentation générique) :
 * - $plugin->staff_model / staff_availability sont SSA_Missing sur cette
 *   licence ("Plus Edition") : la disponibilité par STAFF individuel n'est
 *   pas activée. Chaque coach a en réalité son propre APPOINTMENT TYPE
 *   SSA distinct (confirmé par l'utilisateur), et c'est le champ
 *   `availability` de CET appointment type qui sert de source de vérité.
 * - Format réel observé sur les données de production : deux
 *   availability_type coexistent —
 *     'start_times'     : chaque créneau n'a qu'un time_start (la durée
 *                          vient du champ `duration` de l'appointment type)
 *     'available_blocks' : vrais blocs time_start/time_end
 *   Les coachs réels utilisent 'start_times' — c'est ce format que l'UI
 *   cible, conformément à la décision prise avec l'utilisateur.
 * - Écriture confirmée via ssa()->appointment_type_model->update() (pas de
 *   save()/create() sur ce modèle — vérifié via get_class_methods() réel).
 *
 * @package RK_Coach_Hub
 * @since   2.8.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;

final class RKP_AvailabilityRepository {

    /** Jours de la semaine dans l'ordre attendu par SSA (clés anglaises, format natif). */
    public const WEEKDAYS = [ 'Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday' ];

    /**
     * Récupère l'appointment type SSA lié à un coach — réutilise la même
     * table de correspondance déjà en place (rk_ssa_coach_map) plutôt que
     * d'en créer une nouvelle, dans le sens inverse de
     * RK_MC_Booking_Bridge::get_coach_user_id().
     *
     * @return int 0 si aucun appointment type trouvé pour ce coach.
     */
    public static function find_appointment_type_id_for_coach( int $coach_wp_uid ): int {
        if ( $coach_wp_uid <= 0 ) return 0;

        if ( ! class_exists( 'RK_MC_Booking_Bridge' ) ) return 0;

        $map = get_option( RK_MC_Booking_Bridge::COACH_MAP_OPTION, [] );
        foreach ( (array) $map as $ssa_event_id => $mapped_coach_id ) {
            if ( (int) $mapped_coach_id === $coach_wp_uid ) {
                return (int) $ssa_event_id;
            }
        }

        // Repli : la map peut ne contenir que les types déjà résolus au
        // moins une fois (mise en cache paresseuse, voir
        // trait-rk-mc-booking-bridge-hooks.php). On interroge alors
        // directement SSA pour chaque appointment type existant, jusqu'à
        // trouver celui de ce coach — même logique de résolution que
        // RK_MC_Booking_Bridge, juste parcourue dans l'autre sens.
        if ( function_exists( 'ssa' ) ) {
            $plugin = ssa();
            if ( ! empty( $plugin->appointment_type_model ) && method_exists( $plugin->appointment_type_model, 'query' ) ) {
                try {
                    $types = $plugin->appointment_type_model->query( [ 'per_page' => 200 ] );
                    foreach ( (array) $types as $type ) {
                        $type_id = is_object( $type ) ? (int) ( $type->id ?? 0 ) : (int) ( $type['id'] ?? 0 );
                        if ( ! $type_id ) continue;
                        if ( class_exists( 'RK_MC_Booking_Bridge' ) && RK_MC_Booking_Bridge::get_coach_user_id( $type_id ) === $coach_wp_uid ) {
                            return $type_id;
                        }
                    }
                } catch ( \Throwable $e ) {
                    // SSA API indisponible — aucun repli SQL ici : la
                    // résolution coach↔type dépend déjà de la logique
                    // métier de RK_MC_Booking_Bridge, qu'on ne duplique pas.
                }
            }
        }

        return 0;
    }

    /**
     * Lit la disponibilité brute d'un appointment type — reflète EXACTEMENT
     * ce que SSA a en base (aucune transformation), pour ne jamais afficher
     * une donnée qui diffère silencieusement de la vraie source.
     *
     * @return array{
     *   availability: array<string, array<int, array{time_start:string, time_end?:string}>>,
     *   availability_type: string,
     *   capacity_type: string,
     *   duration: int,
     *   timezone_style: string,
     *   title: string
     * }|null
     */
    public static function get_raw( int $appointment_type_id ): ?array {
        if ( $appointment_type_id <= 0 ) return null;

        if ( function_exists( 'ssa' ) ) {
            $plugin = ssa();
            if ( ! empty( $plugin->appointment_type_model ) && method_exists( $plugin->appointment_type_model, 'get' ) ) {
                try {
                    $type = $plugin->appointment_type_model->get( $appointment_type_id );
                    if ( $type ) {
                        $data = is_object( $type ) ? get_object_vars( $type ) : $type;
                        return self::normalize_raw( $data );
                    }
                } catch ( \Throwable $e ) {
                    // repli SQL ci-dessous
                }
            }
        }

        // Repli SQL direct — lecture seule, jamais utilisé pour écrire.
        global $wpdb;
        $table = $wpdb->prefix . 'ssa_appointment_types';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) return null;

        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $appointment_type_id ), ARRAY_A );
        if ( ! $row ) return null;

        return self::normalize_raw( $row );
    }

    private static function normalize_raw( array $data ): array {
        $availability = $data['availability'] ?? [];
        if ( is_string( $availability ) ) {
            $decoded      = json_decode( $availability, true );
            $availability = is_array( $decoded ) ? $decoded : [];
        }
        // Garantit la présence des 7 jours, même vides — évite un
        // "undefined index" côté template si SSA n'a encore rien stocké
        // pour certains jours (comme observé : 'Tuesday' => [] réel).
        $full = [];
        foreach ( self::WEEKDAYS as $day ) {
            $full[ $day ] = is_array( $availability[ $day ] ?? null ) ? array_values( $availability[ $day ] ) : [];
        }

        return [
            'availability'      => $full,
            'availability_type' => (string) ( $data['availability_type'] ?? 'start_times' ),
            'capacity_type'     => (string) ( $data['capacity_type'] ?? 'individual' ),
            'capacity'          => (int) ( $data['capacity'] ?? 1 ),
            'duration'          => (int) ( $data['duration'] ?? 30 ),
            'timezone_style'    => (string) ( $data['timezone_style'] ?? 'localized' ),
            'timezone'          => self::get_real_timezone(),
            'title'             => (string) ( $data['title'] ?? '' ),
        ];
    }

    /**
     * Vrai fuseau horaire utilisé par SSA — RÉGLAGE GLOBAL du plugin
     * (Appointments → Settings → General), PAS le fuseau du navigateur
     * du coach. Confirmé via le code source SSA réel :
     * SSA_Appointment_Type_Object::get_timezone() → ssa()->utils->
     * get_datetimezone() → $plugin->settings_global->get_datetimezone().
     * Afficher le fuseau du navigateur (Intl côté client) affichait un
     * fuseau totalement différent de celui réellement appliqué par SSA
     * pour calculer les disponibilités — corrigé ici.
     */
    private static function get_real_timezone(): string {
        if ( function_exists( 'ssa' ) ) {
            $plugin = ssa();
            if ( ! empty( $plugin->settings_global ) && method_exists( $plugin->settings_global, 'get_datetimezone' ) ) {
                try {
                    $tz = $plugin->settings_global->get_datetimezone();
                    if ( $tz instanceof \DateTimeZone ) return $tz->getName();
                    if ( is_string( $tz ) && '' !== $tz ) return $tz;
                } catch ( \Throwable $e ) {
                    // repli ci-dessous
                }
            }
        }
        // Repli : fuseau WordPress lui-même (réglages généraux du site),
        // dont SSA hérite très probablement par défaut si son propre
        // réglage n'est pas explicitement défini.
        $wp_tz = wp_timezone_string();
        return $wp_tz ?: 'UTC';
    }

    /**
     * Écrit la nouvelle disponibilité — SEULE méthode d'écriture de ce
     * repository, appelée uniquement depuis RKP_AvailabilityCommandService
     * après validation. Passe par ssa()->appointment_type_model->update(),
     * jamais par SQL direct (pour ne jamais contourner les hooks/cache
     * internes de SSA — voir invalidation ci-dessous).
     *
     * @param array<string, array<int, array{time_start:string, time_end?:string}>> $availability
     */
    public static function save_availability( int $appointment_type_id, array $availability ): bool {
        if ( $appointment_type_id <= 0 ) return false;
        if ( ! function_exists( 'ssa' ) ) return false;

        $plugin = ssa();
        if ( empty( $plugin->appointment_type_model ) || ! method_exists( $plugin->appointment_type_model, 'update' ) ) {
            return false;
        }

        try {
            $result = $plugin->appointment_type_model->update( $appointment_type_id, [
                'availability' => $availability,
            ] );

            self::invalidate_cache( $appointment_type_id );

            return false !== $result;
        } catch ( \Throwable $e ) {
            return false;
        }
    }

    /**
     * Écrit capacity_type ('individual' | 'group') ET capacity (nombre
     * max de participants — pertinent uniquement en mode 'group', mais
     * toujours écrit pour rester cohérent avec le schéma SSA réel où les
     * deux colonnes existent indépendamment).
     */
    public static function save_capacity_type( int $appointment_type_id, string $capacity_type, int $capacity = 1 ): bool {
        if ( $appointment_type_id <= 0 ) return false;
        if ( ! in_array( $capacity_type, [ 'individual', 'group' ], true ) ) return false;
        if ( ! function_exists( 'ssa' ) ) return false;

        $plugin = ssa();
        if ( empty( $plugin->appointment_type_model ) || ! method_exists( $plugin->appointment_type_model, 'update' ) ) {
            return false;
        }

        // En mode individuel, la capacité SSA native est toujours 1 (une
        // seule place, un seul participant) — on force cette valeur côté
        // serveur plutôt que de faire confiance à ce que le client envoie,
        // pour ne jamais laisser une incohérence individual+capacity>1
        // s'écrire dans SSA. Le plafond de 50 en mode groupe est un choix
        // produit raisonnable pour un contexte de coaching pédagogique —
        // SSA_Constants::CAPACITY_MAX existe (100000) mais sert un tout
        // autre cas d'usage (capacité "illimitée" des ressources), pas
        // une vraie limite de groupe pertinente ici.
        $capacity = 'group' === $capacity_type ? max( 2, min( 50, $capacity ) ) : 1;

        try {
            $result = $plugin->appointment_type_model->update( $appointment_type_id, [
                'capacity_type' => $capacity_type,
                'capacity'      => $capacity,
            ] );

            self::invalidate_cache( $appointment_type_id );

            return false !== $result;
        } catch ( \Throwable $e ) {
            return false;
        }
    }

    /**
     * Invalide le cache de disponibilité SSA après une écriture — sans ça,
     * le calendrier de réservation public pourrait continuer à afficher
     * l'ANCIENNE disponibilité pendant la durée du cache (voir
     * SSA_Availability_Cache, confirmé présent dans le diagnostic).
     */
    private static function invalidate_cache( int $appointment_type_id ): void {
        if ( ! function_exists( 'ssa' ) ) return;
        $plugin = ssa();

        if ( ! empty( $plugin->availability_cache_invalidation )
             && method_exists( $plugin->availability_cache_invalidation, 'invalidate_appointment_type_cache' ) ) {
            try {
                $plugin->availability_cache_invalidation->invalidate_appointment_type_cache( $appointment_type_id );
                return;
            } catch ( \Throwable $e ) {
                // on tente le repli ci-dessous
            }
        }

        // Repli : le modèle lui-même expose invalidate_appointment_type_cache()
        // (confirmé présent dans get_class_methods() du diagnostic réel).
        if ( ! empty( $plugin->appointment_type_model )
             && method_exists( $plugin->appointment_type_model, 'invalidate_appointment_type_cache' ) ) {
            try {
                $plugin->appointment_type_model->invalidate_appointment_type_cache( $appointment_type_id );
            } catch ( \Throwable $e ) {
                // Cache non invalidé — pas idéal mais non bloquant : le
                // cache SSA a de toute façon une durée de vie limitée.
            }
        }
    }
}
