<?php
declare( strict_types=1 );
/**
 * RK_Availability_History — historique de disponibilité par semaine
 * calendaire, côté dashboard coach uniquement.
 *
 * IMPORTANT : SSA (mode "Specific start times") ne stocke qu'un planning
 * RÉCURRENT par jour de semaine (Sunday→Saturday) — il n'a aucune notion
 * de date précise. Le calendrier public de réservation (parents) continue
 * donc à toujours suivre le DERNIER planning récurrent sauvegardé dans
 * wp_ssa_appointment_types.availability, exactement comme avant.
 *
 * Cette table sert uniquement à ce que le coach puisse, dans son
 * dashboard, naviguer entre les semaines passées/futures et RETROUVER
 * ce qui a été configuré/sauvegardé à chaque sauvegarde — un journal
 * daté, pas une seconde source de vérité pour les réservations.
 *
 * Table : wp_rk_availability_history
 *
 * @package RK_Coach_Hub
 * @since   2.9.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class RK_Availability_History {

    private static function t(): string {
        global $wpdb;
        return $wpdb->prefix . 'rk_availability_history';
    }

    public static function maybe_create_table(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $cc = $wpdb->get_charset_collate();
        dbDelta( "CREATE TABLE " . self::t() . " (
            id                   BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            appointment_type_id  BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            coach_id             BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            week_start           DATE                NOT NULL,
            availability         LONGTEXT            NOT NULL,
            capacity_type        VARCHAR(20)         NOT NULL DEFAULT 'individual',
            capacity             INT UNSIGNED        NOT NULL DEFAULT 1,
            created_at           DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_type_week (appointment_type_id, week_start),
            KEY idx_coach     (coach_id),
            KEY idx_week      (week_start)
        ) {$cc};" );
    }

    /**
     * Enregistre un instantané de la disponibilité au moment de la
     * sauvegarde, associé à la semaine calendaire en cours (lundi de
     * la semaine courante, cohérent avec RKP_AvailabilityRepository::WEEKDAYS
     * qui commence à 'Sunday' — on utilise ici le format ISO Monday-first
     * uniquement comme clé de regroupement interne, sans impact sur SSA).
     *
     * Une seule ligne par (appointment_type_id, week_start) : un nouvel
     * enregistrement pour la même semaine ÉCRASE le précédent, pour ne
     * garder que le dernier état réellement sauvegardé de cette semaine-là.
     */
    public static function record(
        int $appointment_type_id,
        int $coach_id,
        string $week_start,
        array $availability,
        string $capacity_type = 'individual',
        int $capacity = 1
    ): bool {
        global $wpdb;

        $existing_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM " . self::t() . " WHERE appointment_type_id = %d AND week_start = %s LIMIT 1",
            $appointment_type_id,
            $week_start
        ) );

        $data   = [
            'appointment_type_id' => $appointment_type_id,
            'coach_id'            => $coach_id,
            'week_start'          => $week_start,
            'availability'        => wp_json_encode( $availability ),
            'capacity_type'       => $capacity_type,
            'capacity'            => max( 1, $capacity ),
        ];
        $format = [ '%d', '%d', '%s', '%s', '%s', '%d' ];

        if ( $existing_id ) {
            return false !== $wpdb->update( self::t(), $data, [ 'id' => (int) $existing_id ], $format, [ '%d' ] );
        }

        return false !== $wpdb->insert( self::t(), $data, $format );
    }

    /**
     * Récupère l'instantané enregistré pour une semaine donnée, ou null
     * si le coach n'a jamais sauvegardé pendant cette semaine précise
     * (auquel cas l'appelant doit se rabattre sur le planning récurrent
     * SSA actuel — voir RKP_AvailabilityCommandService::get_for_coach()).
     *
     * @return array{availability: array, capacity_type: string, capacity: int, created_at: string}|null
     */
    public static function get_for_week( int $appointment_type_id, string $week_start ): ?array {
        global $wpdb;

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT availability, capacity_type, capacity, created_at FROM " . self::t() . "
             WHERE appointment_type_id = %d AND week_start = %s LIMIT 1",
            $appointment_type_id,
            $week_start
        ), ARRAY_A );

        if ( ! $row ) return null;

        $decoded = json_decode( (string) $row['availability'], true );

        return [
            'availability'  => is_array( $decoded ) ? $decoded : [],
            'capacity_type' => (string) $row['capacity_type'],
            'capacity'      => (int) $row['capacity'],
            'created_at'    => (string) $row['created_at'],
        ];
    }

    /**
     * Liste les semaines (dates de début) pour lesquelles un instantané
     * existe déjà — utile pour afficher un indicateur visuel côté UI
     * ("cette semaine a été modifiée") sans devoir charger tout le détail.
     *
     * @return string[] Liste de dates 'YYYY-MM-DD'
     */
    public static function list_recorded_weeks( int $appointment_type_id ): array {
        global $wpdb;

        $rows = $wpdb->get_col( $wpdb->prepare(
            "SELECT week_start FROM " . self::t() . " WHERE appointment_type_id = %d ORDER BY week_start ASC",
            $appointment_type_id
        ) );

        return array_map( 'strval', (array) $rows );
    }
}
