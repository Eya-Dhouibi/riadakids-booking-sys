<?php
declare( strict_types=1 );
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Extrait de class-rk-mc-child-user.php — factorisation par fonctionnalité.
 *
 * @since 2.2.0 Correctifs login enfant :
 *   - validation du formulaire AVANT le verrou brute-force (un champ vide
 *     ne consomme plus d'essai et n'affiche plus « trop de tentatives ») ;
 *   - compte à rebours transmis au template via ?retry_in= ;
 *   - PIN indéchiffrable (salts WP modifiés) distingué d'un mauvais code :
 *     erreur dédiée `pin_unreadable`, sans incrémenter les compteurs ;
 *   - prénom introuvable distingué d'un mauvais PIN dans le journal d'audit ;
 *   - token de restauration parent posé AVANT d'écraser le cookie WP,
 *     pour ne plus perdre la session WooCommerce du parent.
 * @since 2.3.0 Homonymes : la boucle ne s'arrête plus au premier PIN valide.
 *   Si plusieurs enfants portent le même prénom ET le même code, la connexion
 *   est refusée (`ambiguous_child`) plutôt que d'ouvrir une session arbitraire.
 */
trait RK_MC_Child_User_Auth {
    /* ─────────────────────────────────────────
     * Page login enfant standalone
     * ───────────────────────────────────────── */

    /**
     * Force l'exclusion du cache de page pour /connexion-child/, le plus
     * tôt possible (hook 'init'). Voir l'explication complète dans
     * class-rk-mc-child-user.php, à l'enregistrement de ce hook.
     *
     * is_page() n'est PAS fiable sur 'init' ($wp_query n'est pas encore
     * peuplé) : on identifie donc la page directement par son slug dans
     * l'URL demandée, avant que le routage WordPress ne soit terminé.
     */
    public static function force_nocache_on_child_login_page(): void {
        $path = isset( $_SERVER['REQUEST_URI'] )
            ? trim( (string) wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ), '/' )
            : '';
        if ( 'connexion-child' !== $path ) return;

        if ( ! defined( 'DONOTCACHEPAGE' ) ) {
            define( 'DONOTCACHEPAGE', true );
        }
        do_action( 'litespeed_control_set_nocache', 'rk_child_login_form' );
        nocache_headers();
    }

    /**
     * Sert le template standalone quand on est sur /connexion-child/.
     */
    public static function child_login_template( string $template ): string {
        if ( ! is_page( 'connexion-child' ) ) return $template;

        // Le garde no-cache est posé beaucoup plus tôt, sur 'init' — voir
        // force_nocache_on_child_login_page(). On ne le répète pas ici.

        $custom = RK_MC_DIR . 'templates/page-child-login.php';
        return file_exists( $custom ) ? $custom : $template;
    }

    /**
     * Normalisation « enfant-friendly » d'un nom.
     *
     * Le nom n'est PAS un secret (seul le PIN protège le compte) : on
     * maximise la tolérance de saisie pour un enfant de 6-10 ans.
     *
     * Gère : casse, espaces multiples/insécables, marques RTL invisibles,
     * harakat, tatweel, variantes arabes (أ/إ/آ/ا, ى/ي, ة/ه, ؤ/و),
     * accents latins, chiffres arabes-indiens, ponctuation décorative.
     */
    public static function soft_name( string $raw ): string {
        $s = trim( $raw );

        $s = str_replace(
            array( "\xC2\xA0", "\xE2\x80\x8E", "\xE2\x80\x8F", "\xE2\x80\x8B", "\xD9\x80" ),
            ' ',
            $s
        );

        $s = mb_strtolower( $s );
        $s = (string) preg_replace( '/[\x{064B}-\x{0652}\x{0640}]/u', '', $s );

        $s = strtr( $s, array(
            'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا',
            'ى' => 'ي', 'ئ' => 'ي',
            'ة' => 'ه',
            'ؤ' => 'و',
            'گ' => 'ك', 'ک' => 'ك',
        ) );

        $s = strtr( $s, array(
            '٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4',
            '٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9',
        ) );

        if ( function_exists( 'remove_accents' ) ) {
            $s = remove_accents( $s );
        }

        $s = (string) preg_replace( '/[^\p{L}\p{N}\s]/u', '', $s );

        return trim( (string) preg_replace( '/\s+/u', ' ', $s ) );
    }

    /**
     * Le nom saisi correspond-il à ce compte ?
     *
     * Accepte, dans l'ordre de tolérance :
     *   - prénom seul                     → « eya »
     *   - prénom + nom de famille         → « eya dhouibi »
     *   - nom complet sans espaces        → « eyadhouibi »
     *   - display_name tel quel
     *
     * Si un nom de famille est saisi séparément, il doit correspondre —
     * c'est ce qui distingue deux enfants homonymes.
     *
     * @param string $typed  Prénom saisi.
     * @param string $family Nom de famille saisi (peut être vide).
     */
    private static function name_matches( string $typed, \WP_User $u, string $family = '' ): bool {
        $t = self::soft_name( $typed );
        if ( '' === $t ) return false;

        $u_first  = self::soft_name( (string) $u->first_name );
        $u_last   = self::soft_name( (string) $u->last_name );
        $u_disp   = self::soft_name( (string) $u->display_name );
        $f        = self::soft_name( $family );

        /*
         * Nom de famille fourni : il doit concorder. C'est le discriminant
         * entre deux « eya » de familles différentes.
         */
        if ( '' !== $f && '' !== $u_last && $f !== $u_last ) {
            return false;
        }

        $variants = array();
        foreach ( array( $u_first, $u_disp ) as $n ) {
            if ( '' === $n ) continue;
            $variants[] = $n;
            $variants[] = str_replace( ' ', '', $n );
            $parts = explode( ' ', $n );
            if ( count( $parts ) > 1 ) {
                $variants[] = $parts[0];
            }
        }

        // Prénom + nom de famille recomposés
        if ( '' !== $u_first && '' !== $u_last ) {
            $full = $u_first . ' ' . $u_last;
            $variants[] = $full;
            $variants[] = str_replace( ' ', '', $full );
        }

        $variants = array_unique( array_filter( $variants ) );

        // Le champ prénom peut contenir le nom complet ; on teste les deux formes.
        $typed_full = trim( $t . ' ' . $f );

        foreach ( array( $t, str_replace( ' ', '', $t ), $typed_full, str_replace( ' ', '', $typed_full ) ) as $cand ) {
            if ( '' !== $cand && in_array( $cand, $variants, true ) ) return true;
        }

        return false;
    }

    /**
     * Redirige vers la page de login avec un code d'erreur.
     * Centralisé pour garantir un comportement homogène (et testable).
     *
     * @param string               $code Code d'erreur lu par le template.
     * @param array<string, mixed> $args Paramètres additionnels (ex. retry_in).
     */
    /**
     * AJAX — GET /wp-admin/admin-ajax.php?action=rk_child_login_nonce
     *
     * Fournit un nonce WordPress FRAIS pour le formulaire de login enfant,
     * indépendamment de tout cache de la page /connexion-child/ elle-même.
     *
     * Pourquoi cet endpoint résout le bug "1er essai échoue, 2e réussit" :
     * le nonce imprimé par wp_nonce_field() dans le HTML au chargement de
     * la page peut être servi par un cache de page serveur (LiteSpeed/
     * QUIC.cloud/NitroPack/Cloudflare) — dans ce cas il correspond à un
     * rendu antérieur, potentiellement pour un autre visiteur ou un autre
     * instant, et wp_verify_nonce() le rejette systématiquement au POST
     * suivant. Le rechargement après l'échec (wp_safe_redirect) obtient
     * souvent un HTML frais — d'où le "2e essai" qui fonctionne.
     *
     * Cet endpoint n'est JAMAIS mis en cache (Cache-Control: no-store +
     * DONOTCACHEPAGE), donc le nonce qu'il retourne correspond TOUJOURS
     * à l'état réel du serveur au moment de l'appel — que le HTML de la
     * page ait été servi depuis un cache ou non.
     *
     * Sécurité : wp_create_nonce() reste l'unique générateur de nonce —
     * aucun token maison. wp_verify_nonce() au POST final reste l'unique
     * validateur, inchangé. Cet endpoint ne fait qu'assurer que le nonce
     * transmis au navigateur est frais ; il ne relâche aucun contrôle.
     * Réponse minimale : uniquement le nonce, rien d'autre.
     */
    public static function ajax_child_login_nonce(): void {
        if ( ! defined( 'DONOTCACHEPAGE' ) ) {
            define( 'DONOTCACHEPAGE', true );
        }
        do_action( 'litespeed_control_set_nocache', 'rk_child_login_nonce_endpoint' );
        nocache_headers();
        header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );

        rkp_log( sprintf(
            '[CHILD LOGIN] nonce request START | current_user_id=%d | session_token_hash=%s',
            get_current_user_id(),
            substr( md5( (string) wp_get_session_token() ), 0, 8 )
        ) );

        $nonce = wp_create_nonce( 'rk_child_username_pin_login' );

        rkp_log( sprintf(
            '[CHILD LOGIN] nonce generated | nonce_hash=%s',
            substr( md5( $nonce ), 0, 8 )
        ) );

        wp_send_json_success( array( 'nonce' => $nonce ) );
    }

    private static function child_login_fail( string $code, array $args = array() ): void {
        $args['login_error'] = $code;
        wp_safe_redirect( add_query_arg( $args, home_url( '/connexion-child/' ) ) );
        exit;
    }

    /**
     * Handler POST : admin-post.php?action=rk_child_pin_login
     *
     * Flux :
     *   1. Vérifier nonce
     *   2. Valider les champs (nom + PIN 4 chiffres)
     *   3. Vérifier le verrou brute-force
     *   4. Trouver l'enfant par first_name/display_name (case-insensitive)
     *   5. Vérifier le PIN + lever l'ambiguïté éventuelle
     *   6. Créer la session (onglet ou cookie) → rediriger vers /dashboard/
     */
    public static function handle_child_login(): void {
        $nonce = isset( $_POST['rk_child_login_nonce'] )
            ? sanitize_text_field( wp_unslash( $_POST['rk_child_login_nonce'] ) )
            : '';

        if ( ! wp_verify_nonce( $nonce, 'rk_child_pin_login' ) ) {
            self::child_login_fail( 'invalid_nonce' );
        }

        $child_name = sanitize_text_field( wp_unslash( $_POST['child_name'] ?? '' ) );
        $child_family = sanitize_text_field( wp_unslash( $_POST['child_family_name'] ?? '' ) );

        // Tolérance de saisie : espaces, tirets, chiffres arabes-indiens.
        $pin_raw     = self::soft_name( (string) wp_unslash( $_POST['child_pin'] ?? '' ) );
        $pin         = (string) preg_replace( '/\D/', '', $pin_raw );
        $name_norm   = self::soft_name( trim( $child_name . ' ' . $child_family ) );

        /*
         * ÉTAPE 1 — Validation du formulaire.
         *
         * Doit précéder le contrôle de verrouillage : un champ vide ou un PIN
         * incomplet n'est pas une tentative d'authentification. L'ordre inverse
         * affichait « trop de tentatives » sur un simple formulaire vide.
         */
        if ( '' === $name_norm || strlen( (string) $pin ) !== 4 ) {
            self::child_login_fail( 'empty_fields' );
        }

        /*
         * ÉTAPE 2 — Verrou brute-force à deux niveaux (IP+identité / identité).
         * Le délai restant est transmis au template pour afficher un compte
         * à rebours plutôt qu'un blocage opaque.
         */
        if ( RK_MC_Pin_Security::is_locked( $name_norm ) ) {
            $wait = (int) ceil( RK_MC_Pin_Security::lock_remaining( $name_norm ) / MINUTE_IN_SECONDS );
            self::child_login_fail( 'too_many_attempts', array( 'retry_in' => max( 1, $wait ) ) );
        }

        /*
         * ÉTAPE 3 — Recherche de l'enfant.
         * Base volontairement petite (un seul rôle rk_child) : le parcours
         * complet reste négligeable et évite une requête meta non indexée.
         */
        $users = get_users( array(
            'role'   => 'rk_child',
            'number' => -1,
            'fields' => 'all',
        ) );

        $candidates      = array();  // tous les enfants dont nom ET PIN concordent
        $name_found      = false;    // le prénom existe (mais PIN faux)
        $unreadable_user = 0;        // PIN présent en base mais indéchiffrable

        foreach ( $users as $u ) {
            // Comparaison tolérante : variantes arabes, accents, espaces,
            // prénom seul ou prénom + nom de famille. Le PIN reste le secret.
            if ( ! self::name_matches( $child_name, $u, $child_family ) ) continue;

            $name_found = true;

            /*
             * PIN chiffré avec une clé perdue (salts WP régénérés lors d'une
             * migration serveur). Le bon code ne fonctionnera jamais : inutile
             * — et contre-productif — de compter cela comme un échec.
             */
            if ( method_exists( 'RK_MC_Pin_Security', 'is_unreadable' )
                && RK_MC_Pin_Security::is_unreadable( (int) $u->ID ) ) {
                $unreadable_user = (int) $u->ID;
                continue;
            }

            // v2.1.0 : vérification via stockage chiffré, temps constant.
            // v2.3.0 : on ne sort PAS de la boucle — il faut détecter les homonymes.
            if ( RK_MC_Pin_Security::verify( (int) $u->ID, (string) $pin ) ) {
                $candidates[] = $u;
            }
        }

        /*
         * Homonymes avec le même PIN : impossible de savoir quel enfant se
         * connecte. On refuse plutôt que d'ouvrir la session du mauvais compte
         * (accès aux cours, évaluations et messages d'un autre enfant).
         */
        if ( count( $candidates ) > 1 ) {
            do_action( 'rk_child_pin_security_event', 'pin_ambiguous_match', array(
                'name'     => $name_norm,
                'user_ids' => wp_list_pluck( $candidates, 'ID' ),
            ) );
            self::child_login_fail( 'ambiguous_child' );
        }

        $matched = $candidates[0] ?? null;

        // PIN irrécupérable : erreur dédiée, aucun compteur incrémenté.
        if ( ! $matched && $unreadable_user ) {
            do_action( 'rk_child_pin_security_event', 'pin_unreadable_login_blocked', array(
                'user_id' => $unreadable_user,
                'name'    => $name_norm,
            ) );
            self::child_login_fail( 'pin_unreadable' );
        }

        if ( ! $matched ) {
            /*
             * Un nom introuvable est une faute de frappe, pas une attaque :
             * il ne consomme aucun essai. Seul un mauvais PIN sur un compte
             * EXISTANT incrémente les compteurs.
             */
            if ( $name_found ) {
                RK_MC_Pin_Security::register_failure( $name_norm );
            }

            // Distinction journalisée (le message utilisateur reste générique
            // pour ne pas révéler quels prénoms existent).
            do_action(
                'rk_child_pin_security_event',
                $name_found ? 'pin_wrong_code' : 'pin_unknown_name',
                array( 'name' => $name_norm )
            );

            $left = ( $name_found && method_exists( 'RK_MC_Pin_Security', 'attempts_left' ) )
                ? RK_MC_Pin_Security::attempts_left( $name_norm )
                : 0;

            // Compteur affiché seulement en fin de parcours (moins anxiogène).
            self::child_login_fail(
                'invalid_credentials',
                ( $left > 0 && $left <= 3 ) ? array( 'attempts_left' => $left ) : array()
            );
        }

        RK_MC_Pin_Security::clear_failures( $name_norm );

        /*
         * ÉTAPE 4 — Isolation de session :
         * - Parent connecté → sub-session onglet via rk_tab (cookie WP parent préservé)
         * - Aucune session parent → login WP direct pour l'enfant (cookie enfant)
         */
        $parent_wp_uid   = 0;
        $tutor_dashboard = home_url( rtrim( RK_TUTOR_DASHBOARD_URL, '/' ) . '/' );

        if ( is_user_logged_in() ) {
            $current = wp_get_current_user();
            if ( (int) $current->ID !== (int) $matched->ID
                 && ! in_array( 'rk_child', (array) $current->roles, true ) ) {
                $parent_wp_uid = (int) $current->ID;
            }
        }

        /*
         * ── PHASE 6a (19/08/2026) — SUPPRESSION DU MODE B ─────────────────
         * Ce bloc appelait wp_set_auth_cookie( $child_id ), ce qui écrasait
         * le cookie WordPress du navigateur. L'enfant devenait alors une
         * SESSION PERSISTANTE : /my-account/, panier et checkout le voyaient
         * comme l'utilisateur connecté. C'était la cause racine du bug
         * « l'enfant apparaît dans l'espace parent ».
         *
         * Désormais : un seul mécanisme d'identité enfant, la session onglet
         * request-scoped. Elle fonctionne aussi sans parent connecté
         * (parent_wp_uid = 0) : l'enfant est résolu sur les surfaces enfant
         * et nulle part ailleurs. Aucun cookie WordPress n'est jamais écrit
         * pour un enfant.
         */
        if ( ! class_exists( 'RK_Session_Manager' ) ) {
            // Sans le session manager, aucune identité enfant ne peut être
            // portée sans écrire de cookie — on refuse plutôt que de dégrader.
            self::child_login_fail( 'session_unavailable' );
        }

        $tab_id   = RK_Session_Manager::create_tab_session( (int) $matched->ID, $parent_wp_uid );
        $redirect = add_query_arg( RK_Session_Manager::TAB_PARAM, $tab_id, $tutor_dashboard );

        /** This action is documented in wp-includes/user.php */
        do_action( 'wp_login', $matched->user_login, $matched );

        wp_safe_redirect( $redirect );
        exit;
    }
    /**
     * Authentification enfant par USERNAME + PIN uniquement (v2.0).
     * 
     * Nouvelle méthode simplifiée : recherche directe par child_username
     * (champ unique dans rk_children), puis vérification du PIN.
     * Évite la logique de « name_matches » et les homonymes.
     */
    public static function handle_child_username_pin_login(): void {
        $nonce = isset( $_POST['rk_child_login_nonce'] )
            ? sanitize_text_field( wp_unslash( $_POST['rk_child_login_nonce'] ) )
            : '';

        /*
         * DIAGNOSTIC — "انتهت صلاحية الطلب" au premier essai (v10.7).
         *
         * v10.6 utilisait error_log() directement, sans passer par rkp_log().
         * rkp_log() exige WP_DEBUG *et* WP_DEBUG_LOG avant d'écrire —
         * error_log() seul peut partir vers le log PHP du serveur plutôt
         * que wp-content/debug.log, ou être invisible sans configuration
         * explicite. Corrigé : rkp_log() est le seul canal déjà utilisé et
         * vérifié dans tout le reste de ce plugin.
         *
         * Ajout par rapport à v10.6 : wp_get_session_token() et l'inventaire
         * des cookies WordPress présents. wp_create_nonce()/wp_verify_nonce()
         * dépendent tous les deux de get_current_user_id() ET du token de
         * session courant — si l'un des deux diffère entre le rendu GET et
         * la soumission POST, le nonce ne peut structurellement PAS
         * correspondre, indépendamment de tout cache de page. C'est
         * l'angle qui n'avait encore jamais été vérifié.
         */
        rkp_log( sprintf(
            '[CHILD LOGIN] submit START | nonce_present=%s | nonce_hash=%s | code_present=%s | current_user_id=%d | session_token_hash=%s | wp_cookies=%s | request_uri=%s',
            $nonce !== '' ? 'true' : 'false',
            $nonce !== '' ? substr( md5( $nonce ), 0, 8 ) : 'n/a',
            ! empty( $_POST['child_pin'] ) ? 'true' : 'false',
            get_current_user_id(),
            substr( md5( (string) wp_get_session_token() ), 0, 8 ),
            implode( ',', array_map(
                static function ( $k ) { return substr( $k, 0, 12 ); },
                array_keys( $_COOKIE )
            ) ),
            isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : ''
        ) );

        $nonce_valid = (bool) wp_verify_nonce( $nonce, 'rk_child_username_pin_login' );

        rkp_log( sprintf(
            '[CHILD LOGIN] nonce_valid=%s',
            $nonce_valid ? 'true' : 'false'
        ) );

        if ( ! $nonce_valid ) {
            rkp_log( '[CHILD LOGIN] error_branch=invalid_nonce' );
            self::child_login_fail( 'invalid_nonce' );
        }

        // Récupérer username (brut) et PIN
        $child_username = sanitize_user( wp_unslash( $_POST['child_username'] ?? '' ) );
        $pin_raw        = self::soft_name( (string) wp_unslash( $_POST['child_pin'] ?? '' ) );
        $pin            = (string) preg_replace( '/\D/', '', $pin_raw );

        // Validation : username et PIN requis
        if ( '' === trim( $child_username ) || strlen( $pin ) !== 4 ) {
            self::child_login_fail( 'empty_fields' );
        }

        // Verrou brute-force (sur username uniquement, plus simple)
        if ( RK_MC_Pin_Security::is_locked( $child_username ) ) {
            $wait = (int) ceil( RK_MC_Pin_Security::lock_remaining( $child_username ) / MINUTE_IN_SECONDS );
            self::child_login_fail( 'too_many_attempts', array( 'retry_in' => max( 1, $wait ) ) );
        }

        /**
         * Recherche directe par child_username (unique, indexée).
         * child_username est stocké dans wp_rk_children, pas dans wp_users,
         * donc on doit le chercher via le repository.
         */
        if ( ! class_exists( 'RK_MC_Child_Repository' ) ) {
            self::child_login_fail( 'invalid_credentials' );
        }

        // Récupérer TOUS les enfants (pas d'index sur child_username)
        $all_children = RK_MC_Child_Repository::get_children_for_username( $child_username );
        
        if ( empty( $all_children ) ) {
            // Username introuvable : pas de tentative brute-force
            self::child_login_fail( 'invalid_credentials' );
        }

        $matched = null;
        $unreadable_user = 0;

        foreach ( $all_children as $child_row ) {
            $wp_user_id = (int) ( $child_row->wp_user_id ?? 0 );
            if ( $wp_user_id <= 0 ) continue;

            // Vérifier si le PIN est irrécupérable
            if ( method_exists( 'RK_MC_Pin_Security', 'is_unreadable' )
                && RK_MC_Pin_Security::is_unreadable( $wp_user_id ) ) {
                $unreadable_user = $wp_user_id;
                continue;
            }

            // Vérifier le PIN
            if ( RK_MC_Pin_Security::verify( $wp_user_id, $pin ) ) {
                $matched = get_user_by( 'id', $wp_user_id );
                break;
            }
        }

        // PIN irrécupérable
        if ( ! $matched && $unreadable_user ) {
            do_action( 'rk_child_pin_security_event', 'pin_unreadable_login_blocked', array(
                'user_id' => $unreadable_user,
                'username' => $child_username,
            ) );
            self::child_login_fail( 'pin_unreadable' );
        }

        // Mauvais PIN
        if ( ! $matched ) {
            RK_MC_Pin_Security::register_failure( $child_username );
            do_action( 'rk_child_pin_security_event', 'pin_login_failed', array(
                'username' => $child_username,
            ) );
            self::child_login_fail( 'invalid_credentials' );
        }

        /**
         * Authentification réussie — même logique que handle_child_login() :
         * si parent connecté → sub-session, sinon login direct enfant.
         */
        $parent_wp_uid   = 0;
        $tutor_dashboard = home_url( rtrim( RK_TUTOR_DASHBOARD_URL, '/' ) . '/' );

        if ( is_user_logged_in() ) {
            $current = wp_get_current_user();
            if ( (int) $current->ID !== (int) $matched->ID
                 && ! in_array( 'rk_child', (array) $current->roles, true ) ) {
                $parent_wp_uid = (int) $current->ID;
            }
        }

        /*
         * ── PHASE 6a (19/08/2026) — SUPPRESSION DU MODE B ─────────────────
         * Ce bloc appelait wp_set_auth_cookie( $child_id ), ce qui écrasait
         * le cookie WordPress du navigateur. L'enfant devenait alors une
         * SESSION PERSISTANTE : /my-account/, panier et checkout le voyaient
         * comme l'utilisateur connecté. C'était la cause racine du bug
         * « l'enfant apparaît dans l'espace parent ».
         *
         * Désormais : un seul mécanisme d'identité enfant, la session onglet
         * request-scoped. Elle fonctionne aussi sans parent connecté
         * (parent_wp_uid = 0) : l'enfant est résolu sur les surfaces enfant
         * et nulle part ailleurs. Aucun cookie WordPress n'est jamais écrit
         * pour un enfant.
         */
        if ( ! class_exists( 'RK_Session_Manager' ) ) {
            // Sans le session manager, aucune identité enfant ne peut être
            // portée sans écrire de cookie — on refuse plutôt que de dégrader.
            self::child_login_fail( 'session_unavailable' );
        }

        $tab_id   = RK_Session_Manager::create_tab_session( (int) $matched->ID, $parent_wp_uid );
        $redirect = add_query_arg( RK_Session_Manager::TAB_PARAM, $tab_id, $tutor_dashboard );

        rkp_log( sprintf(
            '[CHILD LOGIN] code_valid=true | login SUCCESS | child_id=%d',
            (int) $matched->ID
        ) );

        do_action( 'wp_login', $matched->user_login, $matched );
        wp_safe_redirect( $redirect );
        exit;
    }

    /* ─────────────────────────────────────────
     * Logout enfant
     * ───────────────────────────────────────── */

    /**
     * URL de déconnexion sécurisée pour l'enfant.
     */
    public static function get_child_logout_url(): string {
        $args = array( 'rk_child_logout' => '1' );

        // phpcs:disable WordPress.Security.NonceVerification
        $raw_tab = isset( $_GET['rk_tab'] ) ? sanitize_text_field( wp_unslash( $_GET['rk_tab'] ) ) : '';
        // phpcs:enable
        if ( preg_match( '/^[a-f0-9]{40}$/', $raw_tab ) ) {
            $args['rk_tab'] = $raw_tab;
        }

        return wp_nonce_url(
            add_query_arg( $args, home_url( '/' ) ),
            'rk_child_logout'
        );
    }

    /* ── Session stacking helpers ─────────────────────────────────── */

    /**
     * Sauvegarde l'ID du parent dans un transient + cookie httpOnly.
     * Appelé juste AVANT d'ouvrir la session enfant dans le même navigateur.
     *
     * @param int $parent_id  WP user ID du parent connecté.
     */
    public static function save_parent_restore_token( int $parent_id ): void {
        $token = wp_generate_password( 32, false );
        set_transient( self::RESTORE_TRANSIENT . $token, $parent_id, self::RESTORE_TTL );
        setcookie(
            self::RESTORE_COOKIE,
            $token,
            0,               // session cookie (expire à la fermeture du navigateur)
            COOKIEPATH,
            (string) COOKIE_DOMAIN,
            is_ssl(),
            true             // httpOnly : inaccessible depuis JS
        );
    }

    /**
     * Lit le cookie de restore et retourne l'ID du parent s'il est encore valide.
     * Consomme le transient + efface le cookie.
     *
     * @return int  Parent user ID, ou 0 si absent / expiré.
     */
    public static function pop_parent_restore_token(): int {
        $token = isset( $_COOKIE[ self::RESTORE_COOKIE ] )
            ? sanitize_text_field( wp_unslash( $_COOKIE[ self::RESTORE_COOKIE ] ) )
            : '';

        if ( ! $token ) return 0;

        $parent_id = (int) get_transient( self::RESTORE_TRANSIENT . $token );

        // Consommer (one-time)
        delete_transient( self::RESTORE_TRANSIENT . $token );
        setcookie( self::RESTORE_COOKIE, '', time() - 3600, COOKIEPATH, (string) COOKIE_DOMAIN );

        return $parent_id > 0 ? $parent_id : 0;
    }

    /**
     * Vérifie (sans consommer) si un token de restore parent est présent.
     */
    public static function has_parent_restore(): bool {
        $token = isset( $_COOKIE[ self::RESTORE_COOKIE ] )
            ? sanitize_text_field( wp_unslash( $_COOKIE[ self::RESTORE_COOKIE ] ) )
            : '';
        if ( ! $token ) return false;
        return (bool) get_transient( self::RESTORE_TRANSIENT . $token );
    }

    /* ── Logout enfant ─────────────────────────────────────────────── */

    /**
     * Intercepte ?rk_child_logout=1.
     *
     * Si le parent avait ouvert la session enfant depuis son compte (même navigateur),
     * on restaure sa session silencieusement et on le redirige vers /my-account/.
     * Sinon, déconnexion normale → /connexion-child/.
     */
    public static function handle_child_logout(): void {
        if ( empty( $_GET['rk_child_logout'] ) ) return;

        $nonce = isset( $_GET['_wpnonce'] )
            ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) )
            : '';

        if ( ! wp_verify_nonce( $nonce, 'rk_child_logout' ) ) {
            wp_safe_redirect( home_url( '/connexion-child/' ) );
            exit;
        }

        /*
         * Cas A — Sub-session onglet (?rk_tab= dans l'URL de déconnexion).
         * Le cookie WP du parent n'a jamais été modifié.
         * Supprimer uniquement le transient : le parent reste connecté.
         */
        // phpcs:disable WordPress.Security.NonceVerification
        $raw_tab = isset( $_GET['rk_tab'] ) ? sanitize_text_field( wp_unslash( $_GET['rk_tab'] ) ) : '';
        // phpcs:enable
        if ( preg_match( '/^[a-f0-9]{40}$/', $raw_tab ) ) {
            delete_transient( 'rk_tab_' . $raw_tab );
            wp_safe_redirect( add_query_arg( 'logged_out', '1', home_url( '/connexion-child/' ) ) );
            exit;
        }

        // Compatibilité RK_Session_Manager si présent (fallback)
        if ( class_exists( 'RK_Session_Manager' ) ) {
            $tab_id = RK_Session_Manager::get_current_tab_id();
            if ( $tab_id ) {
                RK_Session_Manager::destroy_tab_session( $tab_id );
                wp_safe_redirect( add_query_arg( 'logged_out', '1', home_url( '/connexion-child/' ) ) );
                exit;
            }
        }

        /*
         * Cas B — Login direct enfant (PIN, cookie WP enfant actif).
         * Détruire la session WP de l'enfant et restaurer le parent si présent.
         *
         * Le token de restore est lu AVANT wp_logout() : la déconnexion peut
         * déclencher des hooks tiers qui purgent les cookies personnalisés.
         */
        $parent_id = self::pop_parent_restore_token();

        if ( is_user_logged_in() ) {
            $manager = WP_Session_Tokens::get_instance( get_current_user_id() );
            $manager->destroy_all();
            wp_logout();
        }

        // Restaurer la session parent si elle existait (même navigateur, même onglet)
        if ( $parent_id && get_user_by( 'id', $parent_id ) ) {
            wp_set_current_user( $parent_id );
            wp_set_auth_cookie( $parent_id, false, is_ssl() );
            wp_safe_redirect( home_url( '/my-account/' ) );
            exit;
        }

        // Pas de parent à restaurer : retour à la page login enfant
        wp_safe_redirect( add_query_arg( 'logged_out', '1', home_url( '/connexion-child/' ) ) );
        exit;
    }


    /* ─────────────────────────────────────────
     * Switch URL (token one-time)
     * ───────────────────────────────────────── */

    /**
     * Génère l'URL d'accès à l'espace enfant (un seul usage, 5 min).
     *
     * @param  int          $child_id
     * @param  int          $parent_user_id
     * @return string|false URL complète ou false si inaccessible.
     */
    public static function generate_switch_url( int $child_id, int $parent_user_id ) {
        if ( ! RK_MC_Child_Repository::ownership_check( $child_id, $parent_user_id ) ) {
            return false;
        }

        $child = RK_MC_Child_Repository::get_child( $child_id, $parent_user_id );
        if ( ! $child || empty( $child->wp_user_id ) ) {
            return false;
        }

        $child_wp_user = get_user_by( 'id', (int) $child->wp_user_id );
        if ( ! $child_wp_user
            || ! in_array( 'rk_child', (array) $child_wp_user->roles, true )
        ) {
            return false;
        }

        // Token aléatoire 48 caractères
        $token = bin2hex( random_bytes( 24 ) );

        set_transient(
            self::SWITCH_TRANSIENT . $token,
            array(
                'child_id'     => $child_id,
                'parent_id'    => $parent_user_id,
                'child_wp_uid' => (int) $child->wp_user_id,
            ),
            self::SWITCH_TTL
        );

        return add_query_arg(
            array(
                self::SWITCH_PARAM => $token,
                'child_id'         => $child_id,
            ),
            home_url( '/' )
        );
    }

    /* ─────────────────────────────────────────
     * Traitement du token (hook init)
     * ───────────────────────────────────────── */

    /**
     * Appelé sur 'init' (priorité 1).
     * Valide le token, authentifie l'enfant, redirige vers /dashboard/.
     */
    public static function maybe_process_switch() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( empty( $_GET[ self::SWITCH_PARAM ] ) ) {
            return;
        }

        // sanitize_hex_color() attend #RRGGBB — détruit un token hexadécimal 48 chars.
        // On sanitise manuellement : on ne garde que les caractères hex valides.
        $token = preg_replace(
            '/[^a-f0-9]/',
            '',
            strtolower( sanitize_text_field( wp_unslash( $_GET[ self::SWITCH_PARAM ] ) ) )
        );
        $child_id = absint( $_GET['child_id'] ?? 0 );

        if ( strlen( (string) $token ) !== 48 || $child_id <= 0 ) {
            wp_safe_redirect( home_url( '/' ) );
            exit;
        }

        $data = get_transient( self::SWITCH_TRANSIENT . $token );

        if ( ! $data
            || (int) $data['child_id'] !== $child_id
            || empty( $data['child_wp_uid'] )
        ) {
            // Token invalide ou expiré
            wp_safe_redirect( home_url( '/' ) );
            exit;
        }

        // Consommer le token (one-time)
        delete_transient( self::SWITCH_TRANSIENT . $token );

        $child_wp_uid  = (int) $data['child_wp_uid'];
        $parent_wp_uid = (int) ( $data['parent_id'] ?? 0 );
        $child_user    = get_user_by( 'id', $child_wp_uid );

        if ( ! $child_user
            || ! in_array( 'rk_child', (array) $child_user->roles, true )
        ) {
            wp_safe_redirect( home_url( '/' ) );
            exit;
        }

        /*
         * Isolation de session : on N'appelle PAS wp_set_auth_cookie().
         * Le cookie WP du parent dans ce navigateur reste intact.
         * On crée à la place une session onglet via transient WordPress.
         * Le token ?rk_tab= dans l'URL authentifie l'enfant pour cet onglet
         * uniquement, sans interférer avec les autres onglets.
         */
        $tab_id = class_exists( 'RK_Session_Manager' )
            ? RK_Session_Manager::create_tab_session( $child_wp_uid, $parent_wp_uid )
            : '';

        $tutor_dashboard = home_url( rtrim( RK_TUTOR_DASHBOARD_URL, '/' ) . '/' );

        if ( ! $tab_id ) {
            // Phase 6a — le fallback écrivait wp_set_auth_cookie( child ).
            // Supprimé : on refuse le switch plutôt que de créer une session
            // WordPress enfant persistante.
            wp_safe_redirect( home_url( '/' ) );
            exit;
        }

        $redirect = add_query_arg( RK_Session_Manager::TAB_PARAM, $tab_id, $tutor_dashboard );

        wp_safe_redirect( $redirect );
        exit;
    }

}