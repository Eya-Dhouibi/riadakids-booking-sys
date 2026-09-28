<?php
declare( strict_types=1 );
/**
 * RK_MC_Child_Repository  (v5.4.1)
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * SOURCE DE VÉRITÉ UNIQUE pour les profils enfants.
 *
 * ARCHITECTURE :
 *   Tous les accès à wp_rk_children passent par cette classe.
 *   Le plugin RiadaKids Booking reste CONSOMMATEUR : il lit la même table
 *   via son propre ChildRepository, sans en être propriétaire.
 *
 * RÉTROCOMPATIBILITÉ :
 *   Les fonctions procédurales rk_mc_get_children(), rk_mc_insert_child(),
 *   etc. dans rk-mc-db-helpers.php sont des wrappers vers cette classe.
 *   Aucune migration de code externe n'est nécessaire.
 *
 * @package RK_My_Children
 * @since   5.3.0
 * @since   5.4.0 Unicité du nom par parent : le login enfant s'appuie sur
 *          le couple (nom, PIN). Deux enfants homonymes chez le même parent
 *          rendent l'authentification ambiguë — on la refuse à la source.
 * @since   5.4.1 Correctifs :
 *          - name_taken() ne lisait pas child_family_name (colonne absente du
 *            SELECT) : le nom de famille ne désambiguïsait donc jamais rien ;
 *          - $formats codé en dur → recalculé depuis $row ;
 *          - garde has_column() si dbDelta n'a pas encore tourné ;
 *          - last_error() pour distinguer « doublon » d'une vraie erreur SQL.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class RK_MC_Child_Repository {

    /* ─────────────────────────────────────────
     * Champs autorisés à l'insertion / mise à jour
     * ───────────────────────────────────────── */

   private static $insert_fields = array(
        'user_id', 'child_name', 'child_family_name', 'child_age', 'child_username',
        'avatar_url', 'created_at',
    );

    private static $update_fields = array(
        'child_name', 'child_family_name', 'child_age', 'child_username',
        'avatar_url',
    );

    /** Dernière erreur métier rencontrée par insert()/update(). */
    private static $last_error = '';

    /**
     * Code de la dernière erreur métier : 'name_taken', 'db_error',
     * 'not_owner', 'no_fields', ou '' si tout s'est bien passé.
     */
    public static function last_error(): string {
        return self::$last_error;
    }

    /**
     * La colonne existe-t-elle ?
     * Protège d'un dbDelta non exécuté (upgrade partiel, cache d'opcode…).
     *
     * v5.4.2 — SEUL le résultat POSITIF est mis en cache.
     * Mémoriser une absence était un piège : avec un object cache persistant
     * (LiteSpeed, Redis), le « 0 » enregistré AVANT la migration survivait une
     * heure, insert() retirait alors la colonne et child_family_name restait
     * NULL en base alors que la colonne existait bel et bien.
     * Une colonne ne disparaît jamais : le cache positif, lui, est sûr.
     */
    public static function has_column( string $column ): bool {
        global $wpdb;
        static $memo = array();

        if ( isset( $memo[ $column ] ) ) {
            return $memo[ $column ];
        }

        $key = 'rk_mc_col_' . $column;
        if ( wp_cache_get( $key, 'rk_mc' ) ) {
            $memo[ $column ] = true;
            return true;
        }

        $table = rk_mc_children_table();
        $found = (bool) $wpdb->get_var( $wpdb->prepare(
            "SHOW COLUMNS FROM {$table} LIKE %s",
            $column
        ) );

        // Rien n'est mis en cache si la colonne est absente : la prochaine
        // requête reverra la base, et la migration sera prise en compte.
        if ( $found ) {
            wp_cache_set( $key, 1, 'rk_mc', DAY_IN_SECONDS );
        }

        $memo[ $column ] = $found;
        return $found;
    }

    /** Purge le cache de détection de colonnes (après une migration). */
    public static function flush_column_cache(): void {
        foreach ( array( 'child_family_name', 'child_username' ) as $col ) {
            wp_cache_delete( 'rk_mc_col_' . $col, 'rk_mc' );
        }
    }

    /* ─────────────────────────────────────────
     * READ
     * ───────────────────────────────────────── */

    /**
     * Tous les enfants d'un parent, du plus récent au plus ancien.
     *
     * @param  int      $user_id  ID parent (wp_users).
     * @return object[]
     */
    public static function get_children( int $user_id ): array {
        global $wpdb;
        $table = rk_mc_children_table();

        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE user_id = %d ORDER BY created_at DESC",
                $user_id
            )
        );

        return $results ? $results : array();
    }

    /**
     * Les N derniers enfants d'un parent (dashboard compact).
     *
     * @param  int $user_id
     * @param  int $limit    Défaut : 3.
     * @return object[]
     */
    public static function get_children_for_username( string $username ): array {
        global $wpdb;
        $table = rk_mc_children_table();
        
        $results = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE child_username = %s",
            $username
        ) );

        return $results ? $results : array();
    }

    /**
     * Les N derniers enfants d'un parent (dashboard compact).
     *
     * @param  int $user_id
     * @param  int $limit    Défaut : 3.
     * @return object[]
     */
    public static function get_limited( int $user_id, int $limit = 3 ): array {
        global $wpdb;
        $table = rk_mc_children_table();
        $limit = max( 1, $limit );

        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE user_id = %d ORDER BY created_at DESC LIMIT %d",
                $user_id,
                $limit
            )
        );

        return $results ? $results : array();
    }

    /**
     * Un enfant précis, avec vérification ownership.
     *
     * @param  int         $child_id
     * @param  int         $user_id   ID du parent connecté.
     * @return object|null Null si non trouvé ou n'appartient pas au parent.
     */
    public static function get_child( int $child_id, int $user_id ): ?object {
        global $wpdb;
        $table = rk_mc_children_table();

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE id = %d AND user_id = %d LIMIT 1",
                $child_id,
                $user_id
            )
        );

        return $row ?: null;
    }

    /**
     * Un enfant précis par son ID seul (sans vérification ownership).
     * Usage interne uniquement — ne pas exposer en REST public.
     *
     * @param  int         $child_id
     * @return object|null
     */
    public static function get_by_id( int $child_id ): ?object {
        global $wpdb;
        $table = rk_mc_children_table();

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE id = %d LIMIT 1",
                $child_id
            )
        );

        return $row ?: null;
    }

    /**
     * L'enfant propriétaire d'un compte WP (rôle rk_child) donné.
     * Usage : résolution d'identité self-service côté mobile — l'enfant
     * authentifié par son propre JWT (sub = wp_user_id enfant) ne doit
     * jamais pouvoir se faire passer pour un autre enfant.
     *
     * @since 4.19.1 — pivot auth mobile (username enfant + PIN).
     */
    public static function get_by_wp_user_id( int $wp_user_id ): ?object {
        global $wpdb;
        if ( $wp_user_id <= 0 ) return null;
        $table = rk_mc_children_table();

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE wp_user_id = %d LIMIT 1",
                $wp_user_id
            )
        );

        return $row ?: null;
    }

    /**
     * Vérification ownership : l'enfant appartient-il au parent ?
     *
     * @param  int  $child_id
     * @param  int  $user_id
     * @return bool
     */
    public static function ownership_check( int $child_id, int $user_id ): bool {
        global $wpdb;
        $table = rk_mc_children_table();

        $exists = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$table} WHERE id = %d AND user_id = %d LIMIT 1",
                $child_id,
                $user_id
            )
        );

        return (bool) $exists;
    }

    /**
     * Nombre d'enfants d'un parent.
     *
     * @param  int $user_id
     * @return int
     */
    public static function count( int $user_id ): int {
        global $wpdb;
        $table = rk_mc_children_table();

        return (int) $wpdb->get_var(
            $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE user_id = %d", $user_id )
        );
    }

    /**
     * Recherche paginée (admin) — JOIN wp_users pour parent_name/email.
     *
     * @return array[]  { child_id, child_name, child_age, child_wp_id, parent_name, parent_email, user_registered }
     */
    public static function search_paginated( string $search, int $limit, int $offset ): array {
        global $wpdb;
        $table  = rk_mc_children_table();
        $has_fn = self::has_column( 'child_family_name' );
        $fn_col = $has_fn ? 'c.child_family_name,' : "'' AS child_family_name,";
        $where  = '';
        $args   = [];
        if ( $search !== '' ) {
            $like  = '%' . $wpdb->esc_like( $search ) . '%';
            $where = $has_fn
                ? 'WHERE (c.child_name LIKE %s OR c.child_family_name LIKE %s OR u.display_name LIKE %s OR u.user_email LIKE %s)'
                : 'WHERE (c.child_name LIKE %s OR u.display_name LIKE %s OR u.user_email LIKE %s)';
            $args  = $has_fn ? [ $like, $like, $like, $like ] : [ $like, $like, $like ];
        }
        $args[] = $limit;
        $args[] = $offset;
        $sql = "SELECT c.id AS child_id, c.child_name, {$fn_col} c.child_age,
                       c.wp_user_id AS child_wp_id, u.display_name AS parent_name,
                       u.user_email AS parent_email, u.user_registered
                  FROM {$table} c
             LEFT JOIN {$wpdb->users} u ON u.ID = c.user_id
                {$where}
              ORDER BY c.id DESC
                 LIMIT %d OFFSET %d";
        return (array) $wpdb->get_results( $wpdb->prepare( $sql, $args ) );
    }

    /** Nombre total d'enfants correspondant à la recherche (admin). */
    public static function count_search( string $search ): int {
        global $wpdb;
        $table  = rk_mc_children_table();
        $has_fn = self::has_column( 'child_family_name' );
        $where  = '';
        $args   = [];
        if ( $search !== '' ) {
            $like  = '%' . $wpdb->esc_like( $search ) . '%';
            $where = $has_fn
                ? 'WHERE (c.child_name LIKE %s OR c.child_family_name LIKE %s OR u.display_name LIKE %s OR u.user_email LIKE %s)'
                : 'WHERE (c.child_name LIKE %s OR u.display_name LIKE %s OR u.user_email LIKE %s)';
            $args  = $has_fn ? [ $like, $like, $like, $like ] : [ $like, $like, $like ];
        }
        $sql = "SELECT COUNT(*) FROM {$table} c LEFT JOIN {$wpdb->users} u ON u.ID = c.user_id {$where}";
        return (int) ( $args ? $wpdb->get_var( $wpdb->prepare( $sql, $args ) ) : $wpdb->get_var( $sql ) );
    }

    /* ─────────────────────────────────────────
     * UNICITÉ DU NOM (v5.4.0)
     * ───────────────────────────────────────── */

    /**
     * Normalise un nom pour comparaison : casse, espaces multiples,
     * espaces insécables et marques directionnelles invisibles.
     */
    public static function normalize_name( string $raw ): string {
        $s = mb_strtolower( trim( $raw ) );
        $s = str_replace( array( "\xC2\xA0", "\xE2\x80\x8F", "\xE2\x80\x8E" ), ' ', $s );
        return trim( (string) preg_replace( '/\s+/u', ' ', $s ) );
    }

    /**
     * Le couple (prénom + nom de famille) est-il déjà utilisé par un AUTRE
     * enfant du même parent ?
     *
     * La comparaison se fait en PHP et non en SQL : la collation de la table
     * peut être sensible à la casse ou aux diacritiques selon l'installation.
     *
     * @param  string $name              Prénom candidat.
     * @param  int    $parent_user_id    ID du parent.
     * @param  int    $exclude_child_id  Enfant à ignorer (cas d'une mise à jour).
     * @param  string $family            Nom de famille candidat.
     * @return bool
     */
    public static function name_taken( string $name, int $parent_user_id, int $exclude_child_id = 0, string $family = '' ): bool {
        global $wpdb;
        $table = rk_mc_children_table();

        // Le nom de famille lève l'ambiguïté : deux « eya » de familles
        // différentes sont deux enfants distincts, pas un doublon.
        $norm = self::normalize_name( trim( $name . ' ' . $family ) );

        if ( '' === $norm || $parent_user_id <= 0 ) {
            return false;
        }

        // v5.4.1 — child_family_name DOIT figurer dans le SELECT, sinon la
        // comparaison retombe toujours sur le prénom seul et le nom de famille
        // ne sert à rien.
        $fn_col = self::has_column( 'child_family_name' )
            ? 'child_family_name'
            : "'' AS child_family_name";

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, child_name, {$fn_col} FROM {$table} WHERE user_id = %d AND id != %d",
                $parent_user_id,
                $exclude_child_id
            )
        );

        foreach ( (array) $rows as $r ) {
            $full = trim( (string) $r->child_name . ' ' . (string) ( $r->child_family_name ?? '' ) );
            if ( self::normalize_name( $full ) === $norm ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Liste les groupes d'enfants homonymes (diagnostic / nettoyage admin).
     *
     * @return array<string, array<int, object>> Clé = nom complet normalisé.
     */
    public static function find_duplicates(): array {
        global $wpdb;
        $table  = rk_mc_children_table();
        $fn_col = self::has_column( 'child_family_name' )
            ? 'child_family_name'
            : "'' AS child_family_name";

        $rows = $wpdb->get_results(
            "SELECT id, user_id, child_name, {$fn_col}, wp_user_id FROM {$table} ORDER BY id ASC"
        );

        $groups = array();
        foreach ( (array) $rows as $r ) {
            $full = trim( (string) $r->child_name . ' ' . (string) ( $r->child_family_name ?? '' ) );
            $groups[ self::normalize_name( $full ) ][] = $r;
        }

        return array_filter( $groups, static function ( $g ) {
            return count( $g ) > 1;
        } );
    }

    /* ─────────────────────────────────────────
     * WRITE
     * ───────────────────────────────────────── */

    /**
     * Insérer un nouvel enfant.
     *
     * @param  array     $data  Champs : user_id, child_name, child_family_name,
     *                          child_age,
     *                          avatar_url.
     * @return int|false ID inséré, ou false. Voir last_error() pour la cause.
     */
    public static function insert( array $data ) {
        global $wpdb;

        self::$last_error = '';

        $defaults = array(
            'user_id'           => 0,
            'child_name'        => '',
            'child_family_name' => '',
            'child_username'    => '',
            'child_age'         => '',
            'avatar_url'        => '',
            'created_at'        => current_time( 'mysql' ),
        );

        $row = array_intersect_key(
            array_merge( $defaults, $data ),
            array_flip( self::$insert_fields )
        );

        /*
         * Filet : si dbDelta n'a pas encore ajouté la colonne, on retire le
         * champ au lieu de faire échouer tout l'enregistrement avec une erreur
         * SQL incompréhensible côté utilisateur.
         */
        $family_supported = self::has_column( 'child_family_name' );
        if ( ! $family_supported ) {
            unset( $row['child_family_name'] );

            // Panne silencieuse évitée : on trace le cas, car la valeur saisie
            // par le parent est alors perdue sans aucun message d'erreur.
            rkp_log(
                '[RK_MC_Repository] child_family_name ignoré : colonne absente de '
                . rk_mc_children_table() . ' — lancer rk_mc_maybe_upgrade_table().'
            );
        }
        
        $username_supported = self::has_column( 'child_username' );
        if ( ! $username_supported ) {
            unset( $row['child_username'] );
            rkp_log(
                '[RK_MC_Repository] child_username ignoré : colonne absente de '
                . rk_mc_children_table() . ' — lancer rk_mc_maybe_upgrade_table().'
            );
        } elseif ( '' !== trim( (string) ( $row['child_username'] ?? '' ) ) ) {
            $taken = (bool) $GLOBALS['wpdb']->get_var( $GLOBALS['wpdb']->prepare(
                "SELECT id FROM " . rk_mc_children_table() . " WHERE child_username = %s LIMIT 1",
                $row['child_username']
            ) );
            if ( $taken ) {
                self::$last_error = 'username_taken';
                return false;
            }
        }

        /*
         * Unicité du nom chez ce parent.
         * Le login enfant identifie par (nom + PIN) : deux homonymes rendent
         * l'authentification ambiguë et peuvent ouvrir le mauvais compte.
         * Filtre d'échappement pour les imports / migrations en masse.
         */
        if ( apply_filters( 'rk_mc_enforce_unique_child_name', true ) ) {
            if ( self::name_taken(
                (string) $row['child_name'],
                (int) $row['user_id'],
                0,
                (string) ( $row['child_family_name'] ?? '' )
            ) ) {
                self::$last_error = 'name_taken';

                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    rkp_log( sprintf(
                        '[RK_MC_Repository] insert refusé : nom "%s %s" déjà utilisé par le parent %d',
                        (string) $row['child_name'],
                        (string) ( $row['child_family_name'] ?? '' ),
                        (int) $row['user_id']
                    ) );
                }

                do_action( 'rk_mc_child_name_conflict', (string) $row['child_name'], (int) $row['user_id'] );
                return false;
            }
        }

        // Valider child_age : entier positif 0–25
        if ( isset( $row['child_age'] ) && $row['child_age'] !== '' ) {
            $age = absint( $row['child_age'] );
            $row['child_age'] = ( $age >= 0 && $age <= 25 ) ? (string) $age : '';
        }

        /*
         * child_username porte une contrainte UNIQUE en base. MySQL traite
         * plusieurs NULL comme distincts (autorisé), mais plusieurs '' comme
         * IDENTIQUES (rejeté) — d'où « Duplicate entry '' for key
         * child_username_unique » dès le 2ᵉ enfant sans username renseigné.
         * On normalise donc '' → null avant l'INSERT.
         */
        if ( isset( $row['child_username'] ) && '' === trim( (string) $row['child_username'] ) ) {
            $row['child_username'] = null;
        }

        /*
         * v5.4.1 — Formats calculés depuis $row et non codés en dur :
         * une colonne retirée (ci-dessus) décalerait sinon tous les formats
         * et corromprait les valeurs insérées.
         */
        $formats = array();
        foreach ( array_keys( $row ) as $col ) {
            $formats[] = ( 'user_id' === $col ) ? '%d' : '%s';
        }

        $inserted = $wpdb->insert(
            rk_mc_children_table(),
            $row,
            $formats
        );

        if ( false === $inserted ) {
            self::$last_error = 'db_error';
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                rkp_log( '[RK_MC_Repository] insert error: ' . $wpdb->last_error );
            }
            return false;
        }

        $new_child_id = (int) $wpdb->insert_id;

        /**
         * Déclenché après l'insertion d'un enfant.
         * RK_MC_Child_User::on_child_created() écoute ce hook
         * pour créer le wp_user rk_child correspondant.
         *
         * @param int $new_child_id     ID de la ligne wp_rk_children créée.
         * @param int $parent_user_id   ID du parent (wp_users).
         */
        do_action( 'rk_mc_child_inserted', $new_child_id, (int) ( $row['user_id'] ?? 0 ) );

        return $new_child_id;
    }

    /**
     * Mettre à jour les champs d'un enfant (ownership vérifié).
     *
     * @param  int   $child_id
     * @param  int   $user_id
     * @param  array $data  Champs autorisés : child_name, child_family_name,
     *                      child_age,
     *                      avatar_url.
     * @return bool False si non trouvé / non autorisé / aucun champ / doublon.
     *              Voir last_error() pour la cause.
     */
    public static function update( int $child_id, int $user_id, array $data ): bool {
        global $wpdb;
        $table = rk_mc_children_table();

        self::$last_error = '';

        // Whitelist des champs modifiables
        $update = array_intersect_key( $data, array_flip( self::$update_fields ) );

        // Colonne absente (dbDelta non exécuté) : on ignore le champ.
        if ( isset( $update['child_family_name'] ) && ! self::has_column( 'child_family_name' ) ) {
            unset( $update['child_family_name'] );
        }

        if ( empty( $update ) ) {
            self::$last_error = 'no_fields';
            return false;
        }

        if ( ! self::ownership_check( $child_id, $user_id ) ) {
            self::$last_error = 'not_owner';
            return false;
        }

        /*
         * Renommage : le nouveau nom ne doit pas entrer en collision.
         *
         * On résout les valeurs EFFECTIVES depuis l'enregistrement existant :
         * une mise à jour partielle (ex. seul le nom de famille change) ne doit
         * pas comparer un prénom vide contre les autres enfants.
         */
        if ( ( isset( $update['child_name'] ) || isset( $update['child_family_name'] ) )
            && apply_filters( 'rk_mc_enforce_unique_child_name', true ) ) {

            $current = self::get_child( $child_id, $user_id );

            $eff_name = array_key_exists( 'child_name', $update )
                ? (string) $update['child_name']
                : (string) ( $current->child_name ?? '' );

            $eff_family = array_key_exists( 'child_family_name', $update )
                ? (string) $update['child_family_name']
                : (string) ( $current->child_family_name ?? '' );

            if ( self::name_taken( $eff_name, $user_id, $child_id, $eff_family ) ) {
                self::$last_error = 'name_taken';
                do_action( 'rk_mc_child_name_conflict', $eff_name, $user_id );
                return false;
            }
        }

        // Valider child_age : entier positif 0–25
        if ( isset( $update['child_age'] ) && $update['child_age'] !== '' ) {
            $age = absint( $update['child_age'] );
            $update['child_age'] = ( $age >= 0 && $age <= 25 ) ? (string) $age : '';
        }

        // child_username : '' → null (contrainte UNIQUE, voir insert() pour détail)
        if ( array_key_exists( 'child_username', $update )
            && '' === trim( (string) $update['child_username'] ) ) {
            $update['child_username'] = null;
        }

        $result = $wpdb->update(
            $table,
            $update,
            array( 'id' => $child_id, 'user_id' => $user_id )
        );

        if ( false === $result ) {
            self::$last_error = 'db_error';
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                rkp_log( '[RK_MC_Repository] update error: ' . $wpdb->last_error );
            }
            return false;
        }

        return true;
    }

    /**
     * Supprimer un enfant (ownership vérifié).
     *
     * @param  int  $child_id
     * @param  int  $user_id
     * @return bool
     */
    public static function delete( int $child_id, int $user_id ): bool {
        global $wpdb;
        $table = rk_mc_children_table();

        self::$last_error = '';

        if ( ! self::ownership_check( $child_id, $user_id ) ) {
            self::$last_error = 'not_owner';
            return false;
        }

        /**
         * Déclenché avant la suppression d'un enfant.
         * RK_MC_Child_User::on_child_deleted() écoute ce hook
         * pour supprimer le wp_user rk_child associé.
         *
         * @param int $child_id ID de l'enfant qui va être supprimé.
         */
        // v2.2 (Audit P3-19) : photographie AVANT tout — le hook ci-dessous
        // supprime le wp_user rk_child, l'archive doit donc passer en premier.
        if ( class_exists( 'RKP_ChildArchiveRepository' ) ) {
            RKP_ChildArchiveRepository::archive( $child_id, $user_id );
        }

        do_action( 'rk_mc_child_deleted', $child_id );

        $result = $wpdb->delete(
            $table,
            array( 'id' => $child_id, 'user_id' => $user_id ),
            array( '%d', '%d' )
        );

        return false !== $result;
    }
}