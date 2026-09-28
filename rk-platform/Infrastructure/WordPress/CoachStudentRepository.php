<?php
declare( strict_types=1 );
/**
 * Infrastructure — CoachStudentRepository  (WordPress custom tables)
 *
 * SEULE couche autorisée à requêter wp_rk_child_coaches et
 * wp_rk_children pour les relations coach ↔ enfant.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class RKP_CoachStudentRepository {

    private static function table_exists( string $table ): bool {
        global $wpdb;
        static $cache = [];
        if ( ! isset( $cache[ $table ] ) ) {
            $cache[ $table ] = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
        }
        return $cache[ $table ];
    }

    /**
     * v9.24 — Vérifie l'existence d'une colonne dans wp_rk_children.
     * Requise pour child_family_name : colonne ajoutée en v6.1.0
     * (voir rk-mc-db-helpers.php), potentiellement absente sur une
     * installation ancienne jamais passée par la migration ADD COLUMN.
     */
    private static function children_column_exists( string $col ): bool {
        global $wpdb;
        static $cache = [];
        if ( ! isset( $cache[ $col ] ) ) {
            $cache[ $col ] = (bool) $wpdb->get_var(
                $wpdb->prepare( 'SHOW COLUMNS FROM ' . $wpdb->prefix . 'rk_children LIKE %s', $col )
            );
        }
        return $cache[ $col ];
    }

    /**
     * Enfants assignés à un coach (table wp_rk_child_coaches).
     * Retourne des objets stdClass { child_id, child_name, wp_user_id }.
     *
     * @return object[]
     */
    public static function find_children_for_coach( int $coach_id ): array {
        if ( $coach_id <= 0 ) return [];
        global $wpdb;
        $cc_table = $wpdb->prefix . 'rk_child_coaches';
        $ct       = $wpdb->prefix . 'rk_children';
        // v9.24 — nom de famille affiché à côté du prénom dans la carte
        // élève (#students) et la carte séance (#sessions). Garde
        // défensive : colonne potentiellement absente (voir ci-dessus).
        $family_col = self::children_column_exists( 'child_family_name' )
            ? ', c.child_family_name'
            : ", '' AS child_family_name";

        if ( self::table_exists( $cc_table ) ) {
            $result = $wpdb->get_results( $wpdb->prepare(
                "SELECT DISTINCT c.id AS child_id, c.child_name{$family_col}, c.child_age, c.avatar_url, c.wp_user_id
                   FROM {$cc_table} cc
              LEFT JOIN {$ct} c ON c.id = cc.child_id
                  WHERE cc.coach_id = %d AND c.wp_user_id > 0",
                $coach_id
            ) ) ?: [];
            if ( ! empty( $result ) ) return $result;
        }

        // rk_child_coaches vide ou absent → fallback via wp_rk_bookings
        return self::find_children_via_bookings( $coach_id );
    }

    /**
     * Fallback : enfants trouvés via wp_rk_bookings quand rk_child_coaches est vide.
     * Chemin A : colonne coach_id directe.
     * Chemin B : appointment_type_id via tables SSA Staff premium.
     */
    private static function find_children_via_bookings( int $coach_id ): array {
        global $wpdb;
        $bt = $wpdb->prefix . 'rk_bookings';
        $ct = $wpdb->prefix . 'rk_children';
        if ( ! self::table_exists( $bt ) ) return [];
        $family_col = self::children_column_exists( 'child_family_name' )
            ? ', c.child_family_name'
            : ", '' AS child_family_name";

        // Chemin A : colonne coach_id
        if ( $wpdb->get_var( "SHOW COLUMNS FROM `{$bt}` LIKE 'coach_id'" ) ) {
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT DISTINCT c.id AS child_id, c.child_name{$family_col}, c.wp_user_id
                   FROM {$bt} b
                   JOIN {$ct} c ON c.id = b.child_id
                  WHERE b.coach_id = %d AND b.coach_id > 0",
                $coach_id
            ) ) ?: [];
            if ( ! empty( $rows ) ) return $rows;
        }

        // Chemin B : appointment_type_id via SSA Staff premium
        $type_ids = self::get_ssa_type_ids_via_staff_db( $coach_id );
        if ( ! empty( $type_ids ) ) {
            $ph   = implode( ',', array_fill( 0, count( $type_ids ), '%d' ) );
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT DISTINCT c.id AS child_id, c.child_name{$family_col}, c.wp_user_id
                   FROM {$bt} b
                   JOIN {$ct} c ON c.id = b.child_id
                  WHERE b.appointment_type_id IN ({$ph})",
                ...$type_ids
            ) ) ?: [];
            if ( ! empty( $rows ) ) return $rows;
        }

        return [];
    }

    /** Requête directe sur wp_ssa_staff / wp_ssa_staff_appointment_types. */
    private static function get_ssa_type_ids_via_staff_db( int $coach_id ): array {
        global $wpdb;
        $sat   = $wpdb->prefix . 'ssa_staff_appointment_types';
        $staff = $wpdb->prefix . 'ssa_staff';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $sat ) ) !== $sat ) return [];
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $staff ) ) !== $staff ) return [];
        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT sat.appointment_type_id
               FROM {$sat} sat
               JOIN {$staff} s ON s.id = sat.staff_id
              WHERE s.user_id = %d",
            $coach_id
        ) );
        return array_map( 'intval', $ids ?: [] );
    }

    /**
     * Date de la dernière leçon Tutor LMS complétée par un utilisateur.
     * Retourne '' si aucune activité ou si la table n'existe pas.
     */
    public static function get_last_lesson_date( int $wp_user_id ): string {
        if ( $wp_user_id <= 0 ) return '';
        global $wpdb;
        $table = $wpdb->prefix . 'tutor_completed_lesson';
        if ( ! self::table_exists( $table ) ) return '';
        return (string) ( $wpdb->get_var( $wpdb->prepare(
            "SELECT MAX(created_at) FROM {$table} WHERE user_id = %d",
            $wp_user_id
        ) ) ?? '' );
    }

    /**
     * Version batch de get_last_lesson_date() — UNE requête pour tout un
     * groupe d'élèves au lieu d'une par élève.
     *
     * Perf : utilisée dans les listes coach (get_students_in_course) qui
     * bouclaient sur get_last_lesson_date() par élève — un N+1 direct
     * (jusqu'à ~20 requêtes individuelles pour une classe de 20). Ce
     * batch remplace ça par 1 seule requête groupée par user_id.
     *
     * @param  int[]  $wp_user_ids
     * @return array<int,string>  [wp_user_id => date MAX(created_at) ou '']
     */
    public static function get_last_lesson_dates_batch( array $wp_user_ids ): array {
        $wp_user_ids = array_values( array_unique( array_filter( array_map( 'intval', $wp_user_ids ) ) ) );
        if ( empty( $wp_user_ids ) ) return [];

        global $wpdb;
        $table = $wpdb->prefix . 'tutor_completed_lesson';
        if ( ! self::table_exists( $table ) ) return [];

        $placeholders = implode( ',', array_fill( 0, count( $wp_user_ids ), '%d' ) );
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT user_id, MAX(created_at) AS last_date FROM {$table} WHERE user_id IN ({$placeholders}) GROUP BY user_id",
            $wp_user_ids
        ) );

        $out = [];
        foreach ( $rows as $r ) {
            $out[ (int) $r->user_id ] = (string) $r->last_date;
        }
        return $out;
    }

    /**
     * Retourne les données de base d'un enfant par son rk_children.id, ou null.
     */
    public static function find_child_by_id( int $child_rk_id ): ?object {
        if ( $child_rk_id <= 0 ) return null;
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            'SELECT * FROM ' . $wpdb->prefix . 'rk_children WHERE id = %d LIMIT 1',
            $child_rk_id
        ) ) ?: null;
    }

    /**
     * Retourne child_id, child_name, child_age, avatar_url
     * pour une liste d'IDs (batch — une seule requête).
     *
     * @param  int[]  $child_rk_ids  rk_children.id list.
     * @return object[]
     */
    public static function find_children_basic_by_ids( array $child_rk_ids ): array {
        if ( empty( $child_rk_ids ) ) return [];
        global $wpdb;
        $ph = implode( ',', array_fill( 0, count( $child_rk_ids ), '%d' ) );
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT id AS child_id, child_name, child_age, avatar_url
               FROM " . $wpdb->prefix . "rk_children
              WHERE id IN ({$ph}) ORDER BY child_name ASC",
            ...$child_rk_ids
        ) ) ?: [];
    }

    /**
     * Vérifie que $child_id appartient aux élèves de $coach_id.
     * Requête directe sur wp_rk_child_coaches — plus rapide que charger tous les élèves.
     */
    public static function coach_owns_child( int $coach_id, int $child_id ): bool {
        if ( $coach_id <= 0 || $child_id <= 0 ) return false;
        global $wpdb;
        $cc_table = $wpdb->prefix . 'rk_child_coaches';
        if ( ! self::table_exists( $cc_table ) ) {
            return false;
        }
        return (bool) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$cc_table} WHERE coach_id = %d AND child_id = %d LIMIT 1",
            $coach_id, $child_id
        ) );
    }

    /**
     * Retourne une map [child_rk_id => child_name] pour une liste d'IDs.
     *
     * @param  int[]  $child_rk_ids
     * @return array<int, string>
     */
    public static function find_names_by_ids( array $child_rk_ids ): array {
        if ( empty( $child_rk_ids ) ) return [];
        global $wpdb;
        $ph   = implode( ',', array_fill( 0, count( $child_rk_ids ), '%d' ) );
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, child_name FROM " . $wpdb->prefix . "rk_children WHERE id IN ({$ph})",
            ...$child_rk_ids
        ) ) ?: [];
        $map = [];
        foreach ( $rows as $r ) {
            $map[ (int) $r->id ] = (string) $r->child_name;
        }
        return $map;
    }

    /**
     * Retourne l'enfant (rk_children row) à partir de son wp_user_id, ou null.
     */
    public static function find_by_wp_user_id( int $wp_user_id ): ?object {
        if ( $wp_user_id <= 0 ) return null;
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            'SELECT * FROM ' . $wpdb->prefix . 'rk_children WHERE wp_user_id = %d LIMIT 1',
            $wp_user_id
        ) ) ?: null;
    }

    /**
     * Retourne le coach_id primaire d'un enfant (is_primary DESC, LIMIT 1).
     * Retourne 0 si aucun.
     */
    public static function find_primary_coach_for_child( int $child_rk_id ): int {
        if ( $child_rk_id <= 0 ) return 0;
        global $wpdb;
        $cc_table = $wpdb->prefix . 'rk_child_coaches';
        if ( ! self::table_exists( $cc_table ) ) {
            return 0;
        }
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT coach_id FROM {$cc_table} WHERE child_id = %d ORDER BY is_primary DESC LIMIT 1",
            $child_rk_id
        ) );
    }

    /**
     * Retourne tous les enfants ayant un user_id WP actif (pour rapports cron).
     *
     * @return object[]  { id, child_name, user_id }
     */
    public static function find_all_with_wp_user(): array {
        global $wpdb;
        return $wpdb->get_results(
            'SELECT id, child_name, user_id FROM ' . $wpdb->prefix . 'rk_children WHERE user_id > 0'
        ) ?: [];
    }

    /**
     * Retourne tous les coach_ids assignés à un enfant.
     *
     * @return int[]
     */
    public static function get_coaches_for_child( int $child_rk_id ): array {
        if ( $child_rk_id <= 0 ) return [];
        global $wpdb;
        $table = $wpdb->prefix . 'rk_child_coaches';
        if ( ! self::table_exists( $table ) ) return [];
        return array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
            'SELECT DISTINCT coach_id FROM ' . $table . ' WHERE child_id = %d',
            $child_rk_id
        ) ) ?: [] );
    }

    /** Retourne true si l'enfant a déjà un coach marqué is_primary = 1. */
    public static function has_primary_coach( int $child_rk_id ): bool {
        if ( $child_rk_id <= 0 ) return false;
        global $wpdb;
        return (bool) $wpdb->get_var( $wpdb->prepare(
            'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'rk_child_coaches WHERE child_id = %d AND is_primary = 1',
            $child_rk_id
        ) );
    }

    /**
     * Retourne les wp_user_id des enfants liés à un coach via ses bookings.
     * Encapsule le JOIN rk_bookings → rk_children.
     *
     * @return int[]
     */
    public static function get_children_wp_user_ids_for_coach( int $coach_id ): array {
        if ( $coach_id <= 0 ) return [];
        global $wpdb;
        $bt = $wpdb->prefix . 'rk_bookings';
        $ct = $wpdb->prefix . 'rk_children';

        $child_ids = array_map( 'intval', array_filter( (array) $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT child_id FROM {$bt} WHERE coach_id = %d AND child_id > 0",
            $coach_id
        ) ) ) );
        if ( empty( $child_ids ) ) return [];

        $ph = implode( ',', array_fill( 0, count( $child_ids ), '%d' ) );
        return array_map( 'intval', array_filter( (array) $wpdb->get_col( $wpdb->prepare(
            "SELECT wp_user_id FROM {$ct} WHERE id IN ({$ph}) AND wp_user_id IS NOT NULL AND wp_user_id > 0",
            ...$child_ids
        ) ) ) );
    }

    /** Insère une relation coach-enfant. Retourne true si réussi. */
    public static function insert_assignment( int $child_id, int $coach_id, int $is_primary = 0, int $assigned_by = 0 ): bool {
        if ( $child_id <= 0 || $coach_id <= 0 ) return false;
        global $wpdb;
        return (bool) $wpdb->insert( $wpdb->prefix . 'rk_child_coaches', [
            'child_id'    => $child_id,
            'coach_id'    => $coach_id,
            'is_primary'  => $is_primary,
            'assigned_by' => $assigned_by,
            'assigned_at' => current_time( 'mysql' ),
        ] );
    }

    /**
     * Crée la relation child↔coach si elle n'existe pas déjà (idempotent).
     * Utilise INSERT IGNORE pour éviter les erreurs de clé dupliquée.
     */
    public static function ensure_assignment( int $child_id, int $coach_id ): void {
        if ( $child_id <= 0 || $coach_id <= 0 ) return;
        global $wpdb;
        $table = $wpdb->prefix . 'rk_child_coaches';
        $wpdb->query( $wpdb->prepare(
            "INSERT IGNORE INTO `{$table}` (child_id, coach_id, is_primary, assigned_by, assigned_at)
             VALUES (%d, %d, 0, 0, %s)",
            $child_id,
            $coach_id,
            current_time( 'mysql' )
        ) );
    }

    /* ─────────────────────────────────────────────────────────────
     * v9.55 (13/08/2026) — Méthodes ajoutées pour porter les requêtes
     * $wpdb qui vivaient à tort dans RKP_FamilyLinkQueryService et
     * RKP_FamilyLinkCommandService (couche Application) — violation de
     * la règle DDD "aucun $wpdb hors Infrastructure", relevée en audit.
     * Ces deux services délèguent maintenant entièrement à ce
     * Repository, cohérent avec tous les autres Query/Command Services
     * du plugin.
     * ───────────────────────────────────────────────────────────── */

    /**
     * Ligne de base d'un enfant pour la construction du contexte famille
     * (id, nom, avatar, parent_id, wp_user_id).
     */
    public static function find_context_row( int $child_rk_id ): ?object {
        if ( $child_rk_id <= 0 ) return null;
        global $wpdb;
        $ct = $wpdb->prefix . 'rk_children';
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT id, child_name, avatar_url, user_id AS parent_id, wp_user_id
               FROM {$ct} WHERE id = %d",
            $child_rk_id
        ) ) ?: null;
    }

    /** rk_children.id à partir du wp_user_id de l'enfant, 0 si absent. */
    public static function find_id_by_wp_user_id( int $child_wp_uid ): int {
        if ( $child_wp_uid <= 0 ) return 0;
        global $wpdb;
        $ct = $wpdb->prefix . 'rk_children';
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$ct} WHERE wp_user_id = %d LIMIT 1",
            $child_wp_uid
        ) );
    }

    /**
     * Toutes les assignations coach d'un enfant, primaire en premier
     * puis plus récent d'abord — format brut pour coaches_for_child().
     *
     * @return object[]  { coach_id, is_primary, assigned_at }
     */
    public static function find_coach_assignments( int $child_rk_id ): array {
        if ( $child_rk_id <= 0 ) return [];
        global $wpdb;
        $cc = $wpdb->prefix . 'rk_child_coaches';
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT coach_id, is_primary, assigned_at
               FROM {$cc} WHERE child_id = %d
              ORDER BY is_primary DESC, assigned_at DESC",
            $child_rk_id
        ) ) ?: [];
    }

    /** coach_id du coach principal explicite (is_primary=1), 0 si aucun. */
    public static function find_explicit_primary_coach( int $child_rk_id ): int {
        if ( $child_rk_id <= 0 ) return 0;
        global $wpdb;
        $cc = $wpdb->prefix . 'rk_child_coaches';
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT coach_id FROM {$cc} WHERE child_id = %d AND is_primary = 1
              ORDER BY assigned_at DESC LIMIT 1",
            $child_rk_id
        ) );
    }

    /** coach_id de l'assignation la plus récente (primaire ou non), 0 si aucune. */
    public static function find_latest_coach_assignment( int $child_rk_id ): int {
        if ( $child_rk_id <= 0 ) return 0;
        global $wpdb;
        $cc = $wpdb->prefix . 'rk_child_coaches';
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT coach_id FROM {$cc} WHERE child_id = %d
              ORDER BY assigned_at DESC LIMIT 1",
            $child_rk_id
        ) );
    }

    /** true si la colonne coach_id existe sur wp_rk_bookings (fallback conditionnel). */
    public static function bookings_have_coach_column(): bool {
        global $wpdb;
        $bt = $wpdb->prefix . 'rk_bookings';
        return (bool) $wpdb->get_var( "SHOW COLUMNS FROM `{$bt}` LIKE 'coach_id'" );
    }

    /** coach_id du dernier booking (fallback historique quand aucune assignation n'existe). */
    public static function find_coach_from_latest_booking( int $child_rk_id ): int {
        if ( $child_rk_id <= 0 ) return 0;
        global $wpdb;
        $bt = $wpdb->prefix . 'rk_bookings';
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT coach_id FROM {$bt}
              WHERE child_id = %d AND coach_id > 0
              ORDER BY appointment DESC LIMIT 1",
            $child_rk_id
        ) );
    }

    /**
     * Désigne coach_id comme primaire pour child_id : démote tous les
     * autres, promeut celui-ci — dans une transaction SQL. Retourne
     * false si l'une des deux requêtes échoue (le CommandService gère
     * le ROLLBACK/COMMIT autour de cet appel).
     */
    public static function set_primary_coach_atomic( int $child_id, int $coach_id, int $actor_id ): bool {
        if ( $child_id <= 0 || $coach_id <= 0 ) return false;
        global $wpdb;
        $cc = $wpdb->prefix . 'rk_child_coaches';

        $demote = $wpdb->query( $wpdb->prepare(
            "UPDATE {$cc} SET is_primary = 0 WHERE child_id = %d",
            $child_id
        ) );
        $promote = $wpdb->query( $wpdb->prepare(
            "UPDATE {$cc} SET is_primary = 1, assigned_by = %d
              WHERE child_id = %d AND coach_id = %d",
            $actor_id, $child_id, $coach_id
        ) );

        return false !== $demote && false !== $promote;
    }

    /** Démarre une transaction SQL sur la connexion $wpdb partagée. */
    public static function begin_transaction(): void {
        global $wpdb;
        $wpdb->query( 'START TRANSACTION' );
    }

    /** Valide la transaction SQL en cours. */
    public static function commit(): void {
        global $wpdb;
        $wpdb->query( 'COMMIT' );
    }

    /** Annule la transaction SQL en cours. */
    public static function rollback(): void {
        global $wpdb;
        $wpdb->query( 'ROLLBACK' );
    }

    /** is_primary de la relation child↔coach, ou 0 si absente/inexistante. */
    public static function get_is_primary( int $child_rk_id, int $coach_id ): int {
        if ( $child_rk_id <= 0 || $coach_id <= 0 ) return 0;
        global $wpdb;
        $cc = $wpdb->prefix . 'rk_child_coaches';
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT is_primary FROM {$cc} WHERE child_id = %d AND coach_id = %d LIMIT 1",
            $child_rk_id, $coach_id
        ) );
    }

    /** Nombre d'autres coachs assignés à cet enfant (hors coach_id donné). */
    public static function count_other_coaches( int $child_rk_id, int $exclude_coach_id ): int {
        if ( $child_rk_id <= 0 ) return 0;
        global $wpdb;
        $cc = $wpdb->prefix . 'rk_child_coaches';
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$cc} WHERE child_id = %d AND coach_id <> %d",
            $child_rk_id, $exclude_coach_id
        ) );
    }

    /** Supprime une relation child↔coach précise. Retourne true si une ligne a été supprimée. */
    public static function delete_assignment( int $child_rk_id, int $coach_id ): bool {
        if ( $child_rk_id <= 0 || $coach_id <= 0 ) return false;
        global $wpdb;
        $cc = $wpdb->prefix . 'rk_child_coaches';
        return (bool) $wpdb->delete( $cc, [ 'child_id' => $child_rk_id, 'coach_id' => $coach_id ], [ '%d', '%d' ] );
    }

    /**
     * Réconciliation en masse depuis wp_rk_bookings — deux opérations :
     *  1) INSERT IGNORE de toutes les paires (child, coach) vues dans
     *     les bookings, absentes de wp_rk_child_coaches.
     *  2) Liste des enfants sans coach primaire, avec le coach_id de
     *     leur booking le plus récent (pour promotion côté appelant).
     *
     * @return array{assignments:int, candidates:object[]}
     */
    public static function backfill_assignments_from_bookings(): array {
        global $wpdb;
        $bt = $wpdb->prefix . 'rk_bookings';
        $cc = $wpdb->prefix . 'rk_child_coaches';

        if ( ! self::bookings_have_coach_column() ) {
            return [ 'assignments' => 0, 'candidates' => [] ];
        }

        $assignments = (int) $wpdb->query(
            "INSERT IGNORE INTO {$cc} (child_id, coach_id, is_primary, assigned_by, assigned_at)
             SELECT DISTINCT b.child_id, b.coach_id, 0, 0, NOW()
               FROM {$bt} b
              WHERE b.child_id > 0 AND b.coach_id > 0"
        );

        $candidates = $wpdb->get_results(
            "SELECT b.child_id, b.coach_id
               FROM {$bt} b
               JOIN ( SELECT child_id, MAX(appointment) AS last_appt
                        FROM {$bt} WHERE coach_id > 0 GROUP BY child_id ) lb
                 ON lb.child_id = b.child_id AND lb.last_appt = b.appointment
              WHERE b.coach_id > 0
                AND b.child_id NOT IN (
                    SELECT child_id FROM {$cc} WHERE is_primary = 1
                )"
        ) ?: [];

        return [ 'assignments' => max( 0, $assignments ), 'candidates' => $candidates ];
    }
}