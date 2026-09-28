<?php
declare( strict_types=1 );
/**
 * Application — RKP_AvailabilityCommandService
 *
 * Validation + orchestration de l'écriture de disponibilité coach.
 * RÈGLE : aucun SQL ici — tout passe par RKP_AvailabilityRepository
 * (Infrastructure), qui lui-même n'écrit que via l'API SSA native.
 *
 * @package RK_Coach_Hub
 * @since   2.8.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;

final class RKP_AvailabilityCommandService {

    /**
     * @return array{success: bool, code?: string, data?: array}
     */
    public static function get_for_coach( int $coach_wp_uid ): array {
        $type_id = RKP_AvailabilityRepository::find_appointment_type_id_for_coach( $coach_wp_uid );
        if ( ! $type_id ) {
            return [ 'success' => false, 'code' => 'no_appointment_type' ];
        }

        $raw = RKP_AvailabilityRepository::get_raw( $type_id );
        if ( null === $raw ) {
            return [ 'success' => false, 'code' => 'ssa_unavailable' ];
        }

        return [ 'success' => true, 'data' => array_merge( $raw, [ 'appointment_type_id' => $type_id ] ) ];
    }

    /**
     * Récupère la disponibilité "pour cette semaine calendaire" telle
     * qu'affichée dans le dashboard coach : si un instantané a été
     * sauvegardé pour cette semaine précise, on le renvoie ; sinon on
     * retombe sur le planning récurrent SSA actuel (comportement par
     * défaut historique, jamais cassé). Le calendrier public de
     * réservation, lui, ne consulte jamais cet historique — il ne lit
     * que SSA directement, comme avant.
     *
     * @param string $week_start Date ISO 'YYYY-MM-DD' du lundi de la semaine consultée.
     * @return array{success: bool, code?: string, data?: array}
     */
    public static function get_for_coach_week( int $coach_wp_uid, string $week_start ): array {
        $type_id = RKP_AvailabilityRepository::find_appointment_type_id_for_coach( $coach_wp_uid );
        if ( ! $type_id ) {
            return [ 'success' => false, 'code' => 'no_appointment_type' ];
        }

        if ( class_exists( 'RK_Availability_History' ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $week_start ) ) {
            $snapshot = RK_Availability_History::get_for_week( $type_id, $week_start );
            if ( null !== $snapshot ) {
                return [ 'success' => true, 'data' => array_merge( $snapshot, [
                    'appointment_type_id' => $type_id,
                    'week_start'          => $week_start,
                    'from_history'        => true,
                ] ) ];
            }
        }

        // Aucun instantané pour cette semaine : on renvoie le planning récurrent
        // SSA actuel, avec un indicateur explicite pour que le front sache
        // qu'il affiche le "défaut" et non une donnée propre à cette semaine.
        $raw = RKP_AvailabilityRepository::get_raw( $type_id );
        if ( null === $raw ) {
            return [ 'success' => false, 'code' => 'ssa_unavailable' ];
        }

        return [ 'success' => true, 'data' => array_merge( $raw, [
            'appointment_type_id' => $type_id,
            'week_start'          => $week_start,
            'from_history'        => false,
        ] ) ];
    }

    /**
     * Valide puis sauvegarde une disponibilité hebdomadaire complète.
     *
     * Écrit TOUJOURS dans SSA (planning récurrent — source de vérité pour
     * le calendrier public de réservation, inchangé). Si $week_start est
     * fourni, enregistre EN PLUS un instantané daté dans
     * RK_Availability_History, pour que le dashboard coach puisse
     * naviguer/retrouver ce qui a été configuré semaine par semaine —
     * cela ne modifie jamais ce que les parents voient, qui reste
     * toujours le planning récurrent SSA le plus récent.
     *
     * @param array<string, array<int, array{time_start:string}>> $availability Format attendu : clés = jours anglais
     *        (RKP_AvailabilityRepository::WEEKDAYS), chaque jour = liste de { time_start: 'HH:MM' }.
     *        Le mode 'start_times' (celui utilisé par les vrais coachs, décision confirmée avec
     *        l'utilisateur) ne stocke qu'une heure de début — la durée du créneau est fixée par le champ
     *        `duration` de l'appointment type, pas par ce tableau.
     * @param string|null $week_start Date ISO 'YYYY-MM-DD' du lundi de la semaine en cours d'édition
     *        côté dashboard coach (optionnel — omis, le comportement est strictement identique à avant).
     */
    public static function save_for_coach( int $coach_wp_uid, array $availability, ?string $week_start = null ): array {
        $type_id = RKP_AvailabilityRepository::find_appointment_type_id_for_coach( $coach_wp_uid );
        if ( ! $type_id ) {
            return [ 'success' => false, 'code' => 'no_appointment_type' ];
        }

        $validated = self::validate_and_normalize( $availability );
        if ( null === $validated ) {
            return [ 'success' => false, 'code' => 'invalid_slots' ];
        }

        $saved = RKP_AvailabilityRepository::save_availability( $type_id, $validated );
        if ( ! $saved ) {
            return [ 'success' => false, 'code' => 'save_failed' ];
        }

        if ( $week_start && class_exists( 'RK_Availability_History' ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $week_start ) ) {
            $capacity_row = RKP_AvailabilityRepository::get_raw( $type_id );
            RK_Availability_History::record(
                $type_id,
                $coach_wp_uid,
                $week_start,
                $validated,
                $capacity_row['capacity_type'] ?? 'individual',
                $capacity_row['capacity'] ?? 1
            );
        }

        return [ 'success' => true, 'data' => [ 'appointment_type_id' => $type_id, 'availability' => $validated ] ];
    }

    public static function save_capacity_type_for_coach( int $coach_wp_uid, string $capacity_type, int $capacity = 1 ): array {
        $type_id = RKP_AvailabilityRepository::find_appointment_type_id_for_coach( $coach_wp_uid );
        if ( ! $type_id ) {
            return [ 'success' => false, 'code' => 'no_appointment_type' ];
        }

        if ( ! in_array( $capacity_type, [ 'individual', 'group' ], true ) ) {
            return [ 'success' => false, 'code' => 'invalid_capacity_type' ];
        }

        if ( 'group' === $capacity_type && ( $capacity < 2 || $capacity > 50 ) ) {
            return [ 'success' => false, 'code' => 'invalid_capacity' ];
        }

        $saved = RKP_AvailabilityRepository::save_capacity_type( $type_id, $capacity_type, $capacity );
        if ( ! $saved ) {
            return [ 'success' => false, 'code' => 'save_failed' ];
        }

        return [ 'success' => true, 'data' => [ 'appointment_type_id' => $type_id, 'capacity_type' => $capacity_type, 'capacity' => 'group' === $capacity_type ? $capacity : 1 ] ];
    }

    /**
     * Valide la structure envoyée par le client : jours autorisés
     * uniquement, format d'heure HH:MM strict, pas de doublons sur un
     * même jour. Retourne le tableau normalisé (7 jours garantis, triés
     * par heure croissante) ou null si la structure est invalide.
     *
     * @return array<string, array<int, array{time_start:string}>>|null
     */
    private static function validate_and_normalize( array $availability ): ?array {
        $normalized = array_fill_keys( RKP_AvailabilityRepository::WEEKDAYS, [] );

        foreach ( $availability as $day => $slots ) {
            if ( ! in_array( $day, RKP_AvailabilityRepository::WEEKDAYS, true ) ) {
                continue; // jour inconnu envoyé par le client : ignoré plutôt que de faire échouer toute la sauvegarde
            }
            if ( ! is_array( $slots ) ) return null;

            $day_times = [];
            foreach ( $slots as $slot ) {
                if ( ! is_array( $slot ) || empty( $slot['time_start'] ) ) return null;

                $time = (string) $slot['time_start'];
                if ( ! preg_match( '/^([01]\d|2[0-3]):[0-5]\d(:00)?$/', $time ) ) {
                    return null; // format invalide — on refuse plutôt que de deviner/corriger silencieusement
                }
                // Normalise en HH:MM:SS (format SSA natif observé sur les vraies données).
                $time = substr( $time, 0, 5 ) . ':00';
                $day_times[] = $time;
            }

            // Retire les doublons et trie chronologiquement — évite un
            // tableau incohérent que le coach n'a pas vraiment demandé
            // (deux clics rapides sur la même heure, par ex.).
            $day_times = array_values( array_unique( $day_times ) );
            sort( $day_times );

            $normalized[ $day ] = array_map( static fn( $t ) => [ 'time_start' => $t ], $day_times );
        }

        return $normalized;
    }
}
