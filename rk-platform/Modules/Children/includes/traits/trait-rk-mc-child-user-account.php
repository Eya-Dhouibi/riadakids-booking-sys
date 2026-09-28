<?php
declare( strict_types=1 );
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Extrait de class-rk-mc-child-user.php — factorisation par fonctionnalité.
 * Code déplacé verbatim, aucune modification de logique.
 */
trait RK_MC_Child_User_Account {
    /* ─────────────────────────────────────────
     * Rôle rk_child
     * ───────────────────────────────────────── */

    public static function maybe_register_role() {
        if ( get_role( 'rk_child' ) ) {
            return;
        }
        add_role(
            'rk_child',
            __( 'Enfant RiadaKids', 'rk-my-children' ),
            array(
                'read'              => true,
                'rk_child_dashboard' => true,
                // Capacités WooCommerce intentionnellement absentes
            )
        );
    }

    /* ─────────────────────────────────────────
     * Création wp_user
     * ───────────────────────────────────────── */

    /**
     * Crée un wp_user dédié pour un enfant et met à jour wp_user_id dans la table.
     *
     * @param  int         $child_id
     * @param  int         $parent_user_id
     * @return int|false   wp_user_id créé, ou false si erreur.
     */
    public static function create_for_child( int $child_id, int $parent_user_id ) {
        $child = RK_MC_Child_Repository::get_child( $child_id, $parent_user_id );
        if ( ! $child ) {
            return false;
        }

        // Ne pas recréer si déjà existant
        if ( ! empty( $child->wp_user_id ) && get_user_by( 'id', $child->wp_user_id ) ) {
            return (int) $child->wp_user_id;
        }

        // Construire un username unique
        $base     = 'rk_child_' . $child_id . '_' . sanitize_title( $child->child_name );
        $username = self::unique_username( $base );

        // Email synthétique — jamais envoyé
        $email = 'child_' . $child_id . '_' . $parent_user_id
               . '@internal.riadakids.com';

        $family = trim( (string) ( $child->child_family_name ?? '' ) );

        $display = ! empty( $child->display_name )
            ? $child->display_name
            : trim( $child->child_name . ' ' . $family );

        $user_data = array(
            'user_login'    => $username,
            'user_email'    => $email,
            'user_pass'     => wp_generate_password( 32, true, true ),
            'display_name'  => $display,
            'first_name'    => $child->child_name,
            'last_name'     => $family,
            'role'          => 'rk_child',
            // Désactiver les emails de bienvenue WP
            'send_user_notification' => false,
        );

        // Supprimer le filtre d'email WP qui enverrait un email
        remove_action( 'register_new_user', 'wp_send_new_user_notifications' );

        $wp_user_id = wp_insert_user( $user_data );

        if ( is_wp_error( $wp_user_id ) ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                rkp_log( '[RK_MC_Child_User] wp_insert_user error: '
                    . $wp_user_id->get_error_message() );
            }
            return false;
        }

        // Métadonnées de filiation
        update_user_meta( $wp_user_id, self::META_PARENT_ID, $parent_user_id );
        update_user_meta( $wp_user_id, self::META_CHILD_ROW,  $child_id );

        // Générer le PIN d'accès au dashboard enfant
        self::generate_pin( (int) $wp_user_id );

        // Mettre à jour la table enfants
        RK_MC_Child_Repository::update(
            $child_id,
            $parent_user_id,
            array( 'wp_user_id' => $wp_user_id )
        );

        // Permettre la mise à jour directe (contourne la whitelist update_fields)
        global $wpdb;
        $wpdb->update(
            rk_mc_children_table(),
            array( 'wp_user_id' => $wp_user_id ),
            array( 'id' => $child_id, 'user_id' => $parent_user_id ),
            array( '%d' ),
            array( '%d', '%d' )
        );

        return (int) $wp_user_id;
    }

    /**
     * Génère un username unique en incrémentant si nécessaire.
     */
    private static function unique_username( string $base ): string {
        $username = $base;
        $i        = 1;
        while ( username_exists( $username ) ) {
            $username = $base . '_' . $i++;
        }
        return $username;
    }

    /* ─────────────────────────────────────────
     * Hooks cycle de vie
     * ───────────────────────────────────────── */

    public static function on_child_created( int $child_id, int $parent_user_id ) {
        self::create_for_child( $child_id, $parent_user_id );
    }

    /**
     * Supprime le wp_user de l'enfant quand celui-ci est effacé.
     * NE supprime PAS si le wp_user a d'autres rôles (sécurité).
     */
    public static function on_child_deleted( int $child_id ) {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT wp_user_id, user_id FROM ' . rk_mc_children_table()
                . ' WHERE id = %d LIMIT 1',
                $child_id
            )
        );
        if ( ! $row || ! $row->wp_user_id ) {
            return;
        }
        $user = get_user_by( 'id', (int) $row->wp_user_id );
        if ( ! $user ) {
            return;
        }
        // Vérifier que c'est bien un rk_child (pas un vrai compte admin récupéré)
        if ( in_array( 'rk_child', (array) $user->roles, true ) ) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
            wp_delete_user( (int) $row->wp_user_id );
        }
    }

    /* ─────────────────────────────────────────
     * PIN d'accès au dashboard enfant
     * ───────────────────────────────────────── */

    /**
     * Génère et stocke un PIN 4 chiffres pour l'enfant.
     * Idempotent : ne régénère pas si un PIN valide existe déjà.
     *
     * @param  int    $wp_user_id  ID WordPress de l'enfant.
     * @return string PIN généré.
     */
    public static function generate_pin( int $wp_user_id ): string {
        // v2.1.0 (Audit P0-1) : lecture via Pin_Security — migre au passage
        // tout PIN encore stocké en clair (lazy migration).
        $existing = RK_MC_Pin_Security::read( $wp_user_id );
        if ( $existing && ctype_digit( $existing ) && strlen( $existing ) === 4 ) {
            return $existing;
        }
        $pin = str_pad( (string) wp_rand( 0, 9999 ), 4, '0', STR_PAD_LEFT );
        RK_MC_Pin_Security::store( $wp_user_id, $pin ); // chiffré au repos
        return $pin;
    }

    /**
     * Retourne le PIN de l'enfant (le génère s'il n'existe pas).
     *
     * @param  int    $wp_user_id
     * @return string
     */
    public static function get_child_pin( int $wp_user_id ): string {
        // v2.1.0 : déchiffrement à la volée (affichage parent/coach conservé).
        $pin = RK_MC_Pin_Security::read( $wp_user_id );
        if ( ! $pin ) {
            $pin = self::generate_pin( $wp_user_id );
        }
        return $pin;
    }

    /* ─────────────────────────────────────────
     * Booking confirmed → ensure WP user + PIN
     * ───────────────────────────────────────── */

    /**
     * Déclenché par rk_booking_confirmed (priorité 5, avant le bridge).
     * S'assure que chaque enfant du booking a un wp_user + un PIN.
     *
     * @param int   $booking_id
     * @param mixed $ctx  BookingContext (user_id, child_ids[])
     */
    public static function on_booking_confirmed( int $booking_id, $ctx ): void {
        if ( empty( $ctx->child_ids ) || ! is_array( $ctx->child_ids ) ) return;

        $parent_id = (int) ( $ctx->user_id ?? 0 );
        if ( ! $parent_id ) return;

        global $wpdb;
        $table = rk_mc_children_table();

        foreach ( $ctx->child_ids as $raw_child_id ) {
            $child_id = (int) $raw_child_id;
            if ( $child_id <= 0 ) continue;

            $row = $wpdb->get_row( $wpdb->prepare(
                "SELECT id, wp_user_id FROM {$table}
                  WHERE id = %d AND user_id = %d LIMIT 1",
                $child_id,
                $parent_id
            ) );

            if ( ! $row ) continue;

            // Créer le WP user si absent
            if ( empty( $row->wp_user_id ) || ! get_user_by( 'id', (int) $row->wp_user_id ) ) {
                $wp_user_id = self::create_for_child( $child_id, $parent_id );
            } else {
                $wp_user_id = (int) $row->wp_user_id;
            }

            // Générer PIN si absent
            if ( $wp_user_id ) {
                self::generate_pin( $wp_user_id );
            }
        }
    }

    /**
     * Resynchronise first_name / last_name / display_name du compte WP enfant
     * depuis wp_rk_children.
     *
     * Indispensable : le login enfant compare le prénom saisi avec first_name
     * et display_name. Sans cette synchro, renommer un enfant depuis l'espace
     * parent le laisse connecté sous son ANCIEN nom — et le nouveau ne marche pas.
     *
     * @param int $child_id       Ligne wp_rk_children.
     * @param int $parent_user_id Parent propriétaire.
     * @return bool
     */
    public static function sync_names( int $child_id, int $parent_user_id ): bool {
        $child = RK_MC_Child_Repository::get_child( $child_id, $parent_user_id );
        if ( ! $child || empty( $child->wp_user_id ) ) {
            return false;
        }

        $wp_user_id = (int) $child->wp_user_id;
        if ( ! get_user_by( 'id', $wp_user_id ) ) {
            return false;
        }

        $first  = (string) $child->child_name;
        $family = trim( (string) ( $child->child_family_name ?? '' ) );

        $display = ! empty( $child->display_name )
            ? (string) $child->display_name
            : trim( $first . ' ' . $family );

        $result = wp_update_user( array(
            'ID'           => $wp_user_id,
            'first_name'   => $first,
            'last_name'    => $family,
            'display_name' => $display,
        ) );

        return ! is_wp_error( $result );
    }

}
