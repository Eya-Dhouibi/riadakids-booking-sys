<?php
/**
 * RK – My Children | includes/rk-mc-db-helpers.php
 *
 * v5.3.0 — Sprint 1
 *
 * ARCHITECTURE :
 *   Les fonctions CRUD ici sont désormais des WRAPPERS vers RK_MC_Child_Repository.
 *   Cela garantit la rétrocompatibilité : tout code existant (Booking, REST, JS)
 *   continue de fonctionner sans modification.
 *
 *   Source de vérité unique : RK_MC_Child_Repository → wp_rk_children
 *
 * COLONNES AJOUTÉES v5.3.0 :
 *   wp_user_id   BIGINT NULL  — réservé Sprint 2 (création wp_user enfant)
 *   birth_date   DATE NULL    — date de naissance pour calcul âge dynamique
 *   grade_level  VARCHAR(50)  — niveau scolaire (CE2, 4ème…)
 *   display_name VARCHAR(150) — surnom affiché dans le dashboard enfant
 *
 *   CONSERVÉ : child_age VARCHAR(20) — utilisé par plugin Booking, ne pas supprimer.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* ─────────────────────────────────────────
 * Table name
 * ───────────────────────────────────────── */

function rk_mc_children_table()    { global $wpdb; return $wpdb->prefix . 'rk_children'; }
function rk_mc_points_table()      { global $wpdb; return $wpdb->prefix . 'rk_child_points'; }
function rk_mc_badges_table()      { global $wpdb; return $wpdb->prefix . 'rk_child_badges'; }
function rk_mc_skills_table()      { global $wpdb; return $wpdb->prefix . 'rk_child_skills'; }
function rk_mc_missions_table()    { global $wpdb; return $wpdb->prefix . 'rk_child_missions'; }
function rk_mc_assessments_table() { global $wpdb; return $wpdb->prefix . 'rk_child_assessments'; }
function rk_mc_messages_table()    { global $wpdb; return $wpdb->prefix . 'rk_child_messages'; }

/* ─────────────────────────────────────────
 * Create / Upgrade table
 *
 * v5.3.0 : Nouvelles colonnes Sprint 1
 *   wp_user_id   BIGINT(20) UNSIGNED NULL DEFAULT NULL
 *   birth_date   DATE NULL DEFAULT NULL
 *   grade_level  VARCHAR(50) NOT NULL DEFAULT ''
 *   display_name VARCHAR(150) NOT NULL DEFAULT ''
 *
 * CONSERVÉ : child_age VARCHAR(20) (compatibilité Booking)
 * CONSERVÉ : avatar_url VARCHAR(500)
 * ───────────────────────────────────────── */

function rk_mc_maybe_upgrade_table() {

    global $wpdb;

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $table_name      = rk_mc_children_table();
    $charset_collate = $wpdb->get_charset_collate();

    /*
     * dbDelta est idempotent :
     *   - Ajoute les colonnes manquantes sans toucher aux données existantes.
     *   - Ne supprime jamais de colonnes.
     *   - Compatibilité garantie avec le plugin RiadaKids Booking.
     */
    $sql = "CREATE TABLE {$table_name} (
        id           BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id      BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
        wp_user_id   BIGINT(20) UNSIGNED NULL DEFAULT NULL,
        child_name   VARCHAR(255)        NOT NULL DEFAULT '',
        child_family_name VARCHAR(255)   NOT NULL DEFAULT '',
        child_age    VARCHAR(20)         NOT NULL DEFAULT '',
        display_name VARCHAR(150)        NOT NULL DEFAULT '',
        avatar_url   VARCHAR(500)        NOT NULL DEFAULT '',
        created_at   DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY user_id_key    (user_id),
        KEY wp_user_id_key (wp_user_id)
    ) {$charset_collate};";

    dbDelta( $sql );

    /* Fallback si dbDelta échoue (permissions restreintes) */
    $exists = $wpdb->get_var(
        $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name )
    );

    if ( $exists !== $table_name ) {
        $wpdb->query(
            "CREATE TABLE IF NOT EXISTS {$table_name} (
                id           BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id      BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
                wp_user_id   BIGINT(20) UNSIGNED NULL DEFAULT NULL,
                child_name   VARCHAR(255)        NOT NULL DEFAULT '',
                child_family_name VARCHAR(255)   NOT NULL DEFAULT '',
                child_age    VARCHAR(20)         NOT NULL DEFAULT '',
                display_name VARCHAR(150)        NOT NULL DEFAULT '',
                avatar_url   VARCHAR(500)        NOT NULL DEFAULT '',
                created_at   DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY  (id),
                KEY user_id_key    (user_id),
                KEY wp_user_id_key (wp_user_id)
            ) {$charset_collate};"
        );
    }

    /*
     * Filet de sécurité v6.1.0 — child_family_name.
     *
     * dbDelta ne parvient pas toujours à ajouter une colonne (définition mal
     * alignée, table pré-existante avec une collation différente, cache
     * d'opcode). On vérifie explicitement et on ajoute la colonne au besoin :
     * sans elle, la valeur saisie par le parent est perdue silencieusement.
     */
    $has_family = $wpdb->get_var( $wpdb->prepare(
        "SHOW COLUMNS FROM {$table_name} LIKE %s",
        'child_family_name'
    ) );

    if ( ! $has_family ) {
        $wpdb->query(
            "ALTER TABLE {$table_name}
             ADD COLUMN child_family_name VARCHAR(255) NOT NULL DEFAULT '' AFTER child_name"
        );
    } else {
        // Normalise les lignes créées avant le correctif (NULL → '').
        $wpdb->query(
            "UPDATE {$table_name} SET child_family_name = ''
              WHERE child_family_name IS NULL"
        );
    }

    /*
     * Filet de sécurité — child_username.
     * Même logique que child_family_name ci-dessus : ajout explicite si
     * dbDelta n'a pas créé la colonne, avec contrainte d'unicité globale
     * (le username sert d'identifiant visible affiché sur la carte).
     */
    $has_username = $wpdb->get_var( $wpdb->prepare(
        "SHOW COLUMNS FROM {$table_name} LIKE %s",
        'child_username'
    ) );

    if ( ! $has_username ) {
        $wpdb->query(
            "ALTER TABLE {$table_name}
             ADD COLUMN child_username VARCHAR(60) NULL DEFAULT NULL AFTER child_family_name,
             ADD UNIQUE KEY child_username_unique (child_username)"
        );
    }

    /*
     * Suppression définitive — birth_date, grade_level.
     * Colonnes non utilisées, retirées à la demande. Chaque colonne est
     * vérifiée individuellement avant DROP (idempotent, sûr même si l'une
     * des deux a déjà été retirée manuellement).
     */
    foreach ( array( 'birth_date', 'grade_level' ) as $col_to_drop ) {
        $has_col = $wpdb->get_var( $wpdb->prepare(
            "SHOW COLUMNS FROM {$table_name} LIKE %s",
            $col_to_drop
        ) );
        if ( $has_col ) {
            $wpdb->query( "ALTER TABLE {$table_name} DROP COLUMN {$col_to_drop}" );
        }
    }

    // La détection de colonnes est mise en cache : la purger après migration.
    if ( class_exists( 'RK_MC_Child_Repository' )
        && method_exists( 'RK_MC_Child_Repository', 'flush_column_cache' ) ) {
        RK_MC_Child_Repository::flush_column_cache();
    }

    update_option( 'rk_mc_children_table_ver', '6.1.0' );

    /* ─── Tables Sprint 3 : Gamification ─────────────────────── */
    rk_mc_upgrade_gamification_tables();
}

/**
 * Crée / met à jour les 3 tables de gamification.
 * Séparée pour être appelée indépendamment si besoin.
 */
function rk_mc_upgrade_gamification_tables() {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $cc = $wpdb->get_charset_collate();

    /* wp_rk_child_points — journal des points par enfant
     * source : session | lesson | quiz | course | streak | badge | mission
     */
    dbDelta( "CREATE TABLE {$wpdb->prefix}rk_child_points (
        id          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        child_id    BIGINT(20) UNSIGNED NOT NULL,
        points      SMALLINT(5)         NOT NULL DEFAULT 0,
        source      VARCHAR(40)         NOT NULL DEFAULT '',
        source_id   BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
        note        VARCHAR(255)        NOT NULL DEFAULT '',
        earned_at   DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY child_id_key      (child_id),
        KEY source_key        (source),
        KEY idx_child_date    (child_id, earned_at)
    ) {$cc};" );

    /* wp_rk_child_badges — shawaret maktasaba
     */
    dbDelta( "CREATE TABLE {$wpdb->prefix}rk_child_badges (
        id          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        child_id    BIGINT(20) UNSIGNED NOT NULL,
        badge_key   VARCHAR(80)         NOT NULL DEFAULT '',
        earned_at   DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
        note        VARCHAR(255)        NOT NULL DEFAULT '',
        PRIMARY KEY (id),
        UNIQUE KEY unique_badge (child_id, badge_key),
        KEY child_id_key (child_id)
    ) {$cc};" );

    /* wp_rk_child_skills — qowa al-tifl (skills levels)
     * managed by teacher via admin
     */
    dbDelta( "CREATE TABLE {$wpdb->prefix}rk_child_skills (
        id          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        child_id    BIGINT(20) UNSIGNED NOT NULL,
        skill_key   VARCHAR(40)         NOT NULL DEFAULT '',
        level       TINYINT(3)  UNSIGNED NOT NULL DEFAULT 0,
        updated_at  DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY unique_skill (child_id, skill_key),
        KEY child_id_key (child_id)
    ) {$cc};" );

    /* wp_rk_child_missions — weekly missions
     */
    dbDelta( "CREATE TABLE {$wpdb->prefix}rk_child_missions (
        id          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        child_id    BIGINT(20) UNSIGNED NOT NULL,
        mission_key VARCHAR(60)         NOT NULL DEFAULT '',
        week_start  DATE                NOT NULL,
        target      TINYINT(3)  UNSIGNED NOT NULL DEFAULT 1,
        progress    TINYINT(3)  UNSIGNED NOT NULL DEFAULT 0,
        completed   TINYINT(1)  NOT NULL DEFAULT 0,
        rewarded    TINYINT(1)  NOT NULL DEFAULT 0,
        PRIMARY KEY (id),
        UNIQUE KEY unique_mission    (child_id, mission_key, week_start),
        KEY child_id_key             (child_id),
        KEY idx_child_week_completed (child_id, week_start, completed)
    ) {$cc};" );

    update_option( 'rk_mc_gamification_table_ver', '6.0.0' );

    /* ─── Tables Sprint 4 : Bilans + Messages ─────────────────── */
    rk_mc_upgrade_communication_tables();
}

/**
 * Crée / met à jour les tables bilan (assessment) et messages (v7.0.0).
 */
function rk_mc_upgrade_communication_tables() {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $cc = $wpdb->get_charset_collate();

    /* wp_rk_child_assessments — coach bilans per child */
    dbDelta( "CREATE TABLE {$wpdb->prefix}rk_child_assessments (
        id           BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        child_id     BIGINT(20) UNSIGNED NOT NULL,
        coach_id     BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
        coach_name   VARCHAR(150)        NOT NULL DEFAULT '',
        assessed_at  DATE                NOT NULL,
        rating       TINYINT(1) UNSIGNED NOT NULL DEFAULT 3,
        summary      TEXT                NOT NULL,
        strengths    TEXT                NOT NULL,
        developments TEXT                NOT NULL,
        notes        TEXT                NOT NULL,
        skill_scores TEXT                NOT NULL,
        created_at   DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY child_id_key    (child_id),
        KEY coach_id_key    (coach_id),
        KEY idx_child_date  (child_id, assessed_at)
    ) {$cc};" );

    /* wp_rk_child_messages — parent <-> coach/admin conversations */
    dbDelta( "CREATE TABLE {$wpdb->prefix}rk_child_messages (
        id          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        child_id    BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
        channel     VARCHAR(20)         NOT NULL DEFAULT 'coach',
        from_id     BIGINT(20) UNSIGNED NOT NULL,
        to_id       BIGINT(20) UNSIGNED NOT NULL,
        body        TEXT                NOT NULL,
        is_read     TINYINT(1)          NOT NULL DEFAULT 0,
        created_at  DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY child_channel_key (child_id, channel),
        KEY to_id_key (to_id)
    ) {$cc};" );

    /* wp_rk_notifications — coach notification bell (quiz results, messages, …) */
    dbDelta( "CREATE TABLE {$wpdb->prefix}rk_notifications (
        id          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id     BIGINT(20) UNSIGNED NOT NULL,
        type        VARCHAR(40)         NOT NULL DEFAULT '',
        child_id    BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
        title       VARCHAR(255)        NOT NULL DEFAULT '',
        body        TEXT                NOT NULL,
        is_read     TINYINT(1)          NOT NULL DEFAULT 0,
        created_at  DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY user_id_key (user_id),
        KEY idx_user_unread (user_id, is_read)
    ) {$cc};" );

    update_option( 'rk_mc_communication_table_ver', '7.1.0' );
}

/**
 * Corrige les lignes wp_rk_bookings où appointment IS NULL
 * en lisant start_date depuis wp_ssa_appointments (via booking_id = SSA appointment id).
 *
 * FIX (bug signalé — "459 characters of unexpected output during
 * activation", cause trouvée dans debug.log : "Unknown column
 * 'sa.start_date_time' in WHERE") — cette fonction supposait à tort que
 * SSA stocke la date sous 'start_date_time'. Confirmé à de multiples
 * reprises (voir riadakids-booking, TimeZone::storage_tz()/format(),
 * RescheduleAjax) : la vraie colonne SSA est 'start_date' (format
 * 'Y-m-d H:i:s'), jamais 'start_date_time'. Toute requête SQL référençant
 * une colonne inexistante produit une erreur affichée par WordPress AVANT
 * l'envoi des en-têtes HTTP pendant activate_plugin() — exactement la
 * cause de "unexpected output during activation".
 * Corrigé : nom de colonne réel, + vérification défensive de son
 * existence avant d'exécuter la requête (SHOW COLUMNS), pour que toute
 * évolution future du schéma SSA produise un skip silencieux plutôt
 * qu'une erreur SQL visible à l'activation.
 */
function rk_mc_backfill_appointment_dates(): void {
    if ( get_option( 'rk_appointment_dates_backfilled' ) === '1' ) return;

    global $wpdb;
    $bt  = $wpdb->prefix . 'rk_bookings';
    $sat = $wpdb->prefix . 'ssa_appointments';

    if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $bt ) ) !== $bt ) return;
    if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $sat ) ) !== $sat ) return;

    // Vérifie que la colonne existe réellement avant de l'utiliser dans
    // une requête — évite de reproduire ce même bug si le schéma SSA
    // change encore à l'avenir (skip silencieux au lieu d'une erreur SQL
    // visible à l'activation).
    $col_exists = $wpdb->get_results( "SHOW COLUMNS FROM `{$sat}` LIKE 'start_date'" );
    if ( empty( $col_exists ) ) return;

    // Mise à jour directe via JOIN SQL — une seule requête, pas de boucle PHP
    $wpdb->query(
        "UPDATE {$bt} b
         INNER JOIN {$sat} sa ON sa.id = b.booking_id
            SET b.appointment = sa.start_date
          WHERE b.appointment IS NULL
            AND sa.start_date IS NOT NULL
            AND sa.start_date != ''"
    );

    update_option( 'rk_appointment_dates_backfilled', '1' );
}

/**
 * Ajoute la colonne coach_id à wp_rk_bookings (si absente) et backfille
 * les enregistrements existants depuis rk_ssa_coach_map.
 * Idempotent — utilise une option one-shot pour le backfill.
 */
function rk_mc_upgrade_bookings_coach_id(): void {
    global $wpdb;
    $bt = $wpdb->prefix . 'rk_bookings';

    // Table absente (booking plugin pas encore activé) → rien à faire
    if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $bt ) ) !== $bt ) return;

    // Ajouter la colonne si elle n'existe pas encore
    $col = $wpdb->get_results( "SHOW COLUMNS FROM `{$bt}` LIKE 'coach_id'" );
    if ( empty( $col ) ) {
        $wpdb->query( "ALTER TABLE `{$bt}` ADD COLUMN `coach_id` BIGINT(20) UNSIGNED NOT NULL DEFAULT 0" );
        // Index séparé — on supprime l'erreur si l'index existe déjà
        $wpdb->suppress_errors( true );
        $wpdb->query( "ALTER TABLE `{$bt}` ADD INDEX `idx_coach_id` (`coach_id`)" );
        $wpdb->suppress_errors( false );
    }

    // Backfill : re-déclenché si la version change (permet de re-remplir si la map était vide)
    if ( get_option( 'rk_bookings_coach_id_backfilled' ) === '3' ) return;

    // Charger la map (manuelle + auto-découverte via SSA)
    $map = get_option( 'rk_ssa_coach_map', [] );

    $rows = $wpdb->get_results(
        "SELECT appointment_type_id, booking_id FROM `{$bt}`
          WHERE appointment_type_id > 0 AND coach_id = 0"
    ) ?: [];

    $resolved_type_cache = [];

    foreach ( $rows as $row ) {
        $type_id    = (int) $row->appointment_type_id;
        $booking_id = (int) $row->booking_id;
        $coach_id   = (int) ( $map[ $type_id ] ?? 0 );

        // Résolution par type (avec cache pour éviter N requêtes SSA identiques)
        if ( ! $coach_id ) {
            if ( array_key_exists( $type_id, $resolved_type_cache ) ) {
                $coach_id = $resolved_type_cache[ $type_id ];
            } elseif ( class_exists( 'RK_MC_Booking_Bridge' ) ) {
                $coach_id = RK_MC_Booking_Bridge::get_coach_user_id( $type_id );
                $resolved_type_cache[ $type_id ] = $coach_id;
                if ( $coach_id > 0 ) {
                    $map[ $type_id ] = $coach_id;
                }
            }
        }

        // Dernier recours : staff lié à l'appointment SSA spécifique
        if ( ! $coach_id && $booking_id > 0 && class_exists( 'RK_MC_Booking_Bridge' ) ) {
            $coach_id = RK_MC_Booking_Bridge::resolve_coach_from_ssa_appointment( $booking_id );
        }

        if ( ! $coach_id ) continue;
        $wpdb->update(
            $bt,
            [ 'coach_id' => $coach_id ],
            [ 'booking_id' => $booking_id ],
            [ '%d' ],
            [ '%d' ]
        );
    }

    // Persiste les mappings type→coach nouvellement découverts
    if ( ! empty( $resolved_type_cache ) ) {
        update_option( 'rk_ssa_coach_map', $map, false );
    }

    update_option( 'rk_bookings_coach_id_backfilled', '3' );
}

/**
 * Crée / met à jour les tables v2 (RiadaKids v2 — Sprint 1).
 *   wp_rk_child_coaches      → affectation explicite coach ↔ enfant
 *   wp_rk_assessment_drafts  → brouillons d'évaluation coach
 */
function rk_mc_upgrade_v2_tables() {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $cc = $wpdb->get_charset_collate();

    dbDelta( "CREATE TABLE {$wpdb->prefix}rk_child_coaches (
        id          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        child_id    BIGINT(20) UNSIGNED NOT NULL,
        coach_id    BIGINT(20) UNSIGNED NOT NULL,
        is_primary  TINYINT(1)          NOT NULL DEFAULT 0,
        assigned_by BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
        assigned_at DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY child_coach (child_id, coach_id),
        KEY coach_key (coach_id),
        KEY child_key (child_id)
    ) {$cc};" );

    dbDelta( "CREATE TABLE {$wpdb->prefix}rk_assessment_drafts (
        id          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        coach_id    BIGINT(20) UNSIGNED NOT NULL,
        child_id    BIGINT(20) UNSIGNED NOT NULL,
        draft_data  LONGTEXT            NOT NULL,
        updated_at  DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY coach_child (coach_id, child_id)
    ) {$cc};" );

    update_option( 'rk_mc_v2_table_ver', '8.0.0' );

    // Backfill : alimente wp_rk_child_coaches depuis les bookings existants.
    // Version '2' : utilise map + auto-découverte SSA Staff + colonne coach_id.
    if ( get_option( 'rk_child_coaches_backfilled' ) !== '3' ) {
        $bookings_table = $wpdb->prefix . 'rk_bookings';
        $coaches_table  = $wpdb->prefix . 'rk_child_coaches';

        $has_bookings = $wpdb->get_var(
            $wpdb->prepare( 'SHOW TABLES LIKE %s', $bookings_table )
        ) === $bookings_table;

        if ( $has_bookings ) {
            // — Construire la map type_id → coach_id (manuelle + SSA Staff DB) —
            $map = get_option( 'rk_ssa_coach_map', [] );

            // Compléter la map depuis les tables SSA Staff si disponibles
            $sat_table   = $wpdb->prefix . 'ssa_staff_appointment_types';
            $staff_table = $wpdb->prefix . 'ssa_staff';
            $sat_exists  = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $sat_table ) ) === $sat_table;
            $st_exists   = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $staff_table ) ) === $staff_table;
            if ( $sat_exists && $st_exists ) {
                $ssa_rows = $wpdb->get_results(
                    "SELECT sat.appointment_type_id, s.user_id AS coach_wp_id
                       FROM {$sat_table} sat
                       JOIN {$staff_table} s ON s.id = sat.staff_id
                      WHERE s.user_id > 0"
                ) ?: [];
                foreach ( $ssa_rows as $sr ) {
                    $tid = (int) $sr->appointment_type_id;
                    if ( $tid > 0 && ! isset( $map[ $tid ] ) ) {
                        $map[ $tid ] = (int) $sr->coach_wp_id;
                    }
                }
            }

            // — Chemin A : via appointment_type_id + map —
            $bookings = $wpdb->get_results(
                "SELECT DISTINCT child_id, appointment_type_id FROM {$bookings_table}
                  WHERE appointment_type_id > 0 AND child_id > 0"
            ) ?: [];
            foreach ( $bookings as $row ) {
                $child_id = (int) $row->child_id;
                $coach_id = (int) ( $map[ (int) $row->appointment_type_id ] ?? 0 );
                if ( ! $coach_id ) continue;
                $has_primary = (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$coaches_table} WHERE child_id = %d AND is_primary = 1",
                    $child_id
                ) );
                $wpdb->query( $wpdb->prepare(
                    "INSERT IGNORE INTO {$coaches_table} (child_id, coach_id, is_primary, assigned_by, assigned_at)
                     VALUES (%d, %d, %d, 0, NOW())",
                    $child_id, $coach_id, $has_primary === 0 ? 1 : 0
                ) );
            }

            // — Chemin B : via colonne coach_id dans wp_rk_bookings —
            if ( $wpdb->get_var( "SHOW COLUMNS FROM `{$bookings_table}` LIKE 'coach_id'" ) ) {
                $b2 = $wpdb->get_results(
                    "SELECT DISTINCT child_id, coach_id FROM {$bookings_table}
                      WHERE coach_id > 0 AND child_id > 0"
                ) ?: [];
                foreach ( $b2 as $row ) {
                    $child_id = (int) $row->child_id;
                    $coach_id = (int) $row->coach_id;
                    $has_primary = (int) $wpdb->get_var( $wpdb->prepare(
                        "SELECT COUNT(*) FROM {$coaches_table} WHERE child_id = %d AND is_primary = 1",
                        $child_id
                    ) );
                    $wpdb->query( $wpdb->prepare(
                        "INSERT IGNORE INTO {$coaches_table} (child_id, coach_id, is_primary, assigned_by, assigned_at)
                         VALUES (%d, %d, %d, 0, NOW())",
                        $child_id, $coach_id, $has_primary === 0 ? 1 : 0
                    ) );
                }
            }
        }
        update_option( 'rk_child_coaches_backfilled', '3' );
    }
}

/* ═══════════════════════════════════════════════════════════════════
 * WRAPPERS — API procédurale publique
 *
 * Ces fonctions délèguent à RK_MC_Child_Repository.
 * Conservées pour rétrocompatibilité avec :
 *   - class-rk-mc-rest.php
 *   - class-rk-mc-child-card-renderer.php
 *   - riadakids-booking (Children/ChildRepository.php)
 *   - Tout template existant
 *
 * NE PAS modifier les signatures. NE PAS supprimer.
 * ═══════════════════════════════════════════════════════════════════ */

function rk_mc_get_children( $user_id ) {
    return RK_MC_Child_Repository::get_children( (int) $user_id );
}

function rk_mc_get_children_limited( $user_id, $limit = 3 ) {
    return RK_MC_Child_Repository::get_limited( (int) $user_id, (int) $limit );
}

function rk_mc_get_children_for_username( $username ) {
    return RK_MC_Child_Repository::get_children_for_username( (string) $username );
}

function rk_mc_get_child( $child_id, $user_id ) {
    return RK_MC_Child_Repository::get_child( (int) $child_id, (int) $user_id );
}

function rk_mc_insert_child( $data ) {
    return RK_MC_Child_Repository::insert( $data );
}

function rk_mc_update_child( $child_id, $user_id, $data ) {
    return RK_MC_Child_Repository::update( (int) $child_id, (int) $user_id, $data );
}

function rk_mc_delete_child( $child_id, $user_id ) {
    return RK_MC_Child_Repository::delete( (int) $child_id, (int) $user_id );
}

function rk_mc_count_children( $user_id ) {
    return RK_MC_Child_Repository::count( (int) $user_id );
}

/* ─────────────────────────────────────────
 * Stats helpers (Hero Banner)
 * Conservés ici car ils requêtent d'autres tables.
 * ───────────────────────────────────────── */

function rk_mc_get_session_credits( $user_id ) {
    // Priorité : table wp_rk_user_credits (plugin Booking v3+)
    global $wpdb;
    $credits_table = $wpdb->prefix . 'rk_user_credits';
    $exists = $wpdb->get_var(
        $wpdb->prepare( 'SHOW TABLES LIKE %s', $credits_table )
    );
    if ( $exists === $credits_table ) {
        $row = $wpdb->get_row(
            $wpdb->prepare( "SELECT balance FROM {$credits_table} WHERE user_id = %d LIMIT 1", intval( $user_id ) )
        );
        if ( $row !== null ) {
            return max( 0, (int) $row->balance );
        }
    }
    // Fallback usermeta
    return (int) get_user_meta( intval( $user_id ), 'rk_session_credits', true );
}

function rk_mc_count_bookings( $user_id ) {
    if ( class_exists( 'RKP_BookingRepository' ) ) {
        return RKP_BookingRepository::count_by_user_id( (int) $user_id, [ 'confirmed', 'completed' ] );
    }
    global $wpdb;
    $bookings_table = $wpdb->prefix . 'rk_bookings';
    if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $bookings_table ) ) !== $bookings_table ) return 0;
    return (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$bookings_table} WHERE user_id = %d AND status IN ('confirmed','completed')",
        intval( $user_id )
    ) );
}

function rk_mc_count_sessions( $user_id ) {
    if ( class_exists( 'RKP_BookingRepository' ) ) {
        return RKP_BookingRepository::count_by_user_id( (int) $user_id, [ 'completed' ] );
    }
    global $wpdb;
    $bookings_table = $wpdb->prefix . 'rk_bookings';
    if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $bookings_table ) ) !== $bookings_table ) return 0;
    return (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$bookings_table} WHERE user_id = %d AND status = 'completed'",
        intval( $user_id )
    ) );
}

/* ─────────────────────────────────────────
 * Avatar URL helper
 * ───────────────────────────────────────── */

function rk_mc_get_avatar_url( $child ) {
    if ( ! empty( $child->avatar_url ) ) {
        return esc_url( $child->avatar_url );
    }
    /*
     * v2.8.2 — fallback = personnage RiadaKids (plus de gravatar : la tête
     * grise « mystery person » n'a rien à faire sur un dashboard enfant, et
     * on évite un appel externe). Aligné sur le header des sous-pages.
     */
    return esc_url( RK_MC_URL . 'assets/img/illustrations/character-banana.png' );
}

/* ─────────────────────────────────────────
 * Age helper (Sprint 1)
 *
 * birth_date supprimée de la table — child_age (VARCHAR legacy) est
 * désormais la seule source.
 * ───────────────────────────────────────── */

/**
 * Vérifie si $viewer_id peut voir les données de $child_id.
 * Point d'entrée unique — à appeler en premier dans tout handler, template ou endpoint.
 */
function rk_can_view_child( int $viewer_id, int $child_id ): bool {
    if ( user_can( $viewer_id, 'manage_options' ) ) return true;
    if ( user_can( $viewer_id, 'tutor_instructor' ) ) {
        return class_exists( 'RK_Coach_Data' )
            && (bool) RK_Coach_Data::coach_owns_child( $viewer_id, $child_id );
    }
    return RK_MC_Child_Repository::ownership_check( $child_id, $viewer_id );
}

function rk_mc_get_child_age( $child ) {
    if ( ! empty( $child->child_age ) ) {
        return (int) $child->child_age;
    }
    return null;
}


/**
 * Ajoute la colonne meeting_url (lien Zoom/Meet du coach) à wp_rk_bookings.
 * Idempotent — SHOW COLUMNS avant ALTER.
 */
function rk_mc_upgrade_bookings_meeting_url(): void {
    global $wpdb;
    $bt = $wpdb->prefix . 'rk_bookings';

    if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $bt ) ) !== $bt ) return;

    $col = $wpdb->get_results( "SHOW COLUMNS FROM `{$bt}` LIKE 'meeting_url'" );
    if ( empty( $col ) ) {
        $wpdb->query( "ALTER TABLE `{$bt}` ADD COLUMN `meeting_url` VARCHAR(500) NOT NULL DEFAULT ''" );
    }
}


/**
 * Nom complet d'un enfant : prénom + nom de famille.
 * Source unique — à utiliser partout où un nom d'enfant est affiché.
 *
 * @param object|null $child Ligne wp_rk_children.
 * @return string
 */
function rk_mc_child_full_name( $child ): string {
    if ( ! is_object( $child ) ) return '';

    return trim(
        (string) ( $child->child_name ?? '' ) . ' ' .
        (string) ( $child->child_family_name ?? '' )
    );
}
/* ─────────────────────────────────────────
 * Fuseau horaire des rendez-vous (v6.2.0)
 * ───────────────────────────────────────── */

/**
 * Formate une date de rendez-vous (`wp_rk_bookings.appointment`) dans le
 * fuseau horaire du CLIENT.
 *
 * Pourquoi ce helper : SSA écrit `appointment` dans le fuseau du « business »
 * (Asia/Riyadh) ou en UTC selon la configuration. Le code historique faisait
 * `date_i18n( $f, strtotime( $appt ) )` : strtotime interprète la chaîne dans
 * le fuseau du SITE puis date_i18n réapplique ce même décalage — les deux
 * conversions s'annulent et la valeur BRUTE est affichée, jamais l'heure
 * réelle du client (12:00 au lieu de 15:00 pour un client en GMT+3).
 *
 * La conversion est déléguée à \RiadaKids\Core\TimeZone (plugin Booking),
 * source unique de vérité. Repli sûr si ce plugin est désactivé.
 *
 * @param string|null $appointment Datetime MySQL telle que stockée.
 * @param string      $format      Format PHP/WP (ex. 'H:i', 'j/m/Y').
 * @param int         $appt_id     ID SSA (rk_bookings.booking_id) — permet de
 *                                 lire customer_timezone du rendez-vous.
 * @param int         $user_id     Repli : user_meta rk_customer_timezone.
 * @return string Chaîne formatée, ou '' si la date est vide/invalide.
 */
function rk_mc_appt_format( ?string $appointment, string $format, int $appt_id = 0, int $user_id = 0 ): string {
    $appointment = trim( (string) $appointment );
    if ( '' === $appointment || str_starts_with( $appointment, '0000-00-00' ) ) {
        return '';
    }

    if ( ! $user_id ) {
        $user_id = get_current_user_id();
    }

    // Chemin nominal : conversion complète via le plugin Booking.
    if ( class_exists( '\RiadaKids\Core\TimeZone' ) ) {
        $out = \RiadaKids\Core\TimeZone::format( $appointment, $format, $appt_id, $user_id );
        return ( '—' === $out ) ? '' : $out;
    }

    // Repli : au moins ne pas appliquer la double conversion historique.
    try {
        $dt = new DateTimeImmutable( $appointment, wp_timezone() );
    } catch ( Exception $e ) {
        return '';
    }
    return wp_date( $format, $dt->getTimestamp(), wp_timezone() );
}

/**
 * Timestamp UTC réel d'un rendez-vous — pour les COMPARAISONS
 * (passé/futur, « demain », fenêtre d'annulation), jamais pour l'affichage.
 *
 * strtotime() sur la valeur brute donne un timestamp faux dès que le fuseau
 * de stockage diffère de celui du site : les comparaisons avec time()
 * dérivent alors de plusieurs heures.
 */
function rk_mc_appt_timestamp( ?string $appointment ): int {
    $appointment = trim( (string) $appointment );
    if ( '' === $appointment || str_starts_with( $appointment, '0000-00-00' ) ) {
        return 0;
    }

    $tz = class_exists( '\RiadaKids\Core\TimeZone' )
        ? \RiadaKids\Core\TimeZone::storage_tz()
        : wp_timezone();

    try {
        return ( new DateTimeImmutable( $appointment, $tz ) )->getTimestamp();
    } catch ( Exception $e ) {
        return 0;
    }
}

/**
 * Libellé du décalage horaire client (ex. « GMT+3 »), à afficher à côté
 * de l'heure pour lever toute ambiguïté.
 */
function rk_mc_appt_tz_label( int $appt_id = 0, int $user_id = 0 ): string {
    if ( ! class_exists( '\RiadaKids\Core\TimeZone' ) ) {
        return '';
    }
    if ( ! $user_id ) {
        $user_id = get_current_user_id();
    }
    return \RiadaKids\Core\TimeZone::offset_label( $appt_id, $user_id );
}