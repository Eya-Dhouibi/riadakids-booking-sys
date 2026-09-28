<?php
declare( strict_types=1 );
/**
 * RK_Session_Manager — Isolation complète des sessions par acteur.
 *
 * ┌─────────────────────────────────────────────────────────────────────────┐
 * │  ARCHITECTURE SESSIONS                                                   │
 * │                                                                          │
 * │  Coach   → Cookie WP standard (tutor_instructor)                        │
 * │            Naturellement isolé : utilisateur WP distinct,               │
 * │            cookie lié à l'ID utilisateur, appareils séparés.            │
 * │                                                                          │
 * │  Parent  → Cookie WP standard (subscriber)                              │
 * │            Jamais touché lors de l'ouverture d'un espace enfant.        │
 * │                                                                          │
 * │  Enfant  → DEUX modes indépendants :                                    │
 * │                                                                          │
 * │    A) Login direct (PIN) sur /connexion-child/                          │
 * │       → Cookie WP enfant via wp_set_auth_cookie()                       │
 * │       → RESTORE_COOKIE sauvegarde l'ID parent pour restauration         │
 * │       → handle_child_logout() restaure la session parent                │
 * │                                                                          │
 * │    B) Sub-session via switch (onglet ouvert par le parent)              │
 * │       → Token ?rk_tab={40hex} dans l'URL                                │
 * │       → Session stockée en transient WordPress (serveur uniquement)     │
 * │       → determine_current_user lit ?rk_tab → retourne l'ID enfant       │
 * │       → Cookie WP parent JAMAIS modifié                                 │
 * │       → Logout enfant : supprime le transient, parent reste connecté    │
 * │                                                                          │
 * │  Isolation garantie :                                                    │
 * │  • Onglet parent : pas de rk_tab → WP cookie parent actif              │
 * │  • Onglet enfant : rk_tab dans URL → transient authentifie l'enfant    │
 * │  • Coach logout : détruit uniquement les tokens WP du coach             │
 * │  • Child sub-session logout : supprime transient, WP cookie intact      │
 * └─────────────────────────────────────────────────────────────────────────┘
 *
 * @package RK_My_Children
 * @since   8.1.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class RK_Session_Manager {

    /* ── Constantes ──────────────────────────────────────────────────── */

    const TAB_PARAM      = 'rk_tab';    // Paramètre GET/POST identifiant l'onglet enfant
    const TAB_TRANSIENT  = 'rk_tab_';   // Préfixe transient WordPress
    const TAB_TTL        = 604800;       // Durée session onglet : 7 jours (sliding window)
    const EPOCH_META     = 'rk_child_tab_epoch'; // compteur de révocation (défaut 1)

    /**
     * ── FENÊTRE DE COMPATIBILITÉ LEGACY — RELATIVE (P0-1, 6b-4-final) ────
     *
     * Avant : une constante en dur, `1771459200`, commentée « 2026-11-17 ».
     * La valeur réelle était le 19/02/2026 — déjà expirée. Résultat : le
     * chemin legacy retournait null d'emblée, et TOUTES les sessions enfant
     * en cours ainsi que tous les liens de quiz déjà envoyés auraient cessé
     * de fonctionner dès le déploiement.
     *
     * Maintenant : la fenêtre est ANCRÉE sur la première migration, pas sur
     * une date d'écriture du code. Un report de déploiement de six mois ne
     * la fait plus dériver : elle démarre le jour où le site migre.
     *
     *     option rkp_legacy_window_start   (posée UNE SEULE FOIS)
     *              ↓
     *     + LEGACY_WINDOW  (90 jours)
     *              ↓
     *     deadline effective
     *
     * 90 jours = TTL de session (7 j) × 12 — de quoi couvrir un lien de quiz
     * dormant dans une boîte mail.
     *
     * ⚠️ La deadline ne concerne QUE la reconnaissance d'un token legacy
     * jamais utilisé depuis la migration. Un token legacy utilisé au moins
     * une fois a été ADOPTÉ par wp_rk_child_sessions : il est alors résolu
     * par la table et n'est plus jamais soumis à cette fenêtre.
     */
    const LEGACY_WINDOW      = 7776000;                    // 90 jours
    const LEGACY_START_OPTION = 'rkp_legacy_window_start'; // timestamp d'ancrage

    /**
     * Timestamp de fin de la fenêtre legacy, ancré sur la première migration.
     *
     * L'option n'est initialisée qu'une seule fois : si elle existe déjà,
     * elle n'est JAMAIS réécrite — sinon chaque déploiement rouvrirait la
     * fenêtre indéfiniment, ce qui reviendrait à un repli permanent.
     */
    public static function legacy_deadline(): int {
        $start = (int) get_option( self::LEGACY_START_OPTION, 0 );

        if ( $start <= 0 ) {
            // Première rencontre : on ancre la fenêtre maintenant.
            $start = time();
            add_option( self::LEGACY_START_OPTION, $start, '', false );

            // add_option() échoue si l'option existe déjà (course entre deux
            // requêtes) : on relit pour ne jamais écraser un ancrage existant.
            $start = (int) get_option( self::LEGACY_START_OPTION, $start );
        }

        return $start + self::LEGACY_WINDOW;
    }

    /* ── Bootstrap ───────────────────────────────────────────────────── */

    public static function init(): void {
        // determine_current_user est enregistré directement dans rk-my-children.php
        // (avant plugins_loaded) pour éviter tout problème de timing avec d'autres plugins.

        // Nettoyage du transient si wp_logout est appelé dans un contexte d'onglet
        add_action( 'wp_logout', array( __CLASS__, 'on_wp_logout' ), 1, 1 );

        // Phase 6a — conversion des cookies enfant hérités (voir méthode).
        add_action( 'template_redirect', array( __CLASS__, 'migrate_legacy_child_cookie' ), 1 );


        // Correctif REST 403 : les plugins tiers (BP Better Messages, Tutor LMS…) font
        // des requêtes XHR vers /wp-json/ sans inclure rk_tab → WordPress authentifie
        // via le cookie parent → nonce mismatch → 403. On injecte un intercepteur JS
        // très tôt dans le <head> pour ajouter rk_tab à toutes les requêtes wp-json/.
        if ( self::get_current_tab_id() && ! is_admin() ) {
            add_action( 'wp_head', array( __CLASS__, 'output_rest_tab_interceptor' ), 1 );

            // Durcissement (audit 19/08/2026) — le token vit dans l'URL, ce qui
            // est structurel : c'est ce qui permet l'isolation PAR ONGLET (un
            // cookie serait partagé entre tous les onglets et écraserait la
            // session parent). On limite donc les fuites plutôt que le support.
            add_action( 'send_headers', array( __CLASS__, 'send_tab_privacy_headers' ) );
            add_action( 'wp_head', array( __CLASS__, 'output_tab_referrer_meta' ), 0 );
        }
    }

    /**
     * En-têtes de confidentialité sur toute page portant un rk_tab.
     *
     *  • Referrer-Policy: no-referrer  → empêche la fuite du token vers TOUT
     *    domaine tiers (CDN, polices, analytics, iframe SSA/Zoom, images
     *    externes…). C'était le principal vecteur d'exposition.
     *  • X-Robots-Tag: noindex          → aucune URL porteuse de token ne doit
     *    finir dans un index de moteur de recherche.
     */
    public static function send_tab_privacy_headers(): void {
        if ( headers_sent() ) return;
        header( 'Referrer-Policy: no-referrer' );
        header( 'X-Robots-Tag: noindex, nofollow, noarchive', true );
    }

    /**
     * Filet côté HTML : si un cache de page (LiteSpeed, Cloudflare) sert la
     * réponse sans repasser par PHP, l'en-tête peut manquer. La balise meta
     * couvre ce cas pour le referrer.
     */
    public static function output_tab_referrer_meta(): void {
        echo '<meta name="referrer" content="no-referrer">' . "\n";
        echo '<meta name="robots" content="noindex,nofollow">' . "\n";
    }

    /**
     * Intercepteur JS minimal injecté en tête de page quand l'enfant est en session onglet.
     * Patch XMLHttpRequest.open et window.fetch pour ajouter ?rk_tab=TOKEN aux URLs :
     *   • de la racine REST (wp-json) — depuis toujours ;
     *   • d'admin-ajax.php — v2.6.2 : les plugins qui soumettent via admin-ajax
     *     (AYS Quiz Maker…) généraient un MISMATCH DE NONCE : la page était
     *     rendue en tant qu'ENFANT (token dans l'URL) mais l'ajax repartait
     *     sous le cookie PARENT → « Sorry, we are unable to store your data ».
     *     Avec le token sur admin-ajax, l'identité enfant est cohérente de
     *     bout en bout : nonce valide, tentative attribuée au bon utilisateur.
     */
    public static function output_rest_tab_interceptor(): void {
        $tab_id = self::get_current_tab_id();
        if ( ! $tab_id ) return;
        // Racine du SEUL namespace REST de ce plugin — ex. https://riadakids.com/wp-json/rk/v1/
        $rk_rest_base = rtrim( (string) rest_url( 'rk/v1' ), '/' ) . '/';
        // WP_DEBUG pilote le logging optionnel — jamais de bruit en production.
        $debug = defined( 'WP_DEBUG' ) && WP_DEBUG;
        ?>
        <script id="rk-rest-tab-interceptor">
        (function(T, B, DEBUG){
            /*
             * ── ISOLATION RK / EXTERNE (v10.6) ──────────────────────────────
             *
             * CAUSE RACINE de l'audit demandé :
             *
             * inj() elle-même ne modifiait JAMAIS l'URL d'une requête externe
             * (Better Messages, riada_get_count) — vérifié ligne à ligne :
             * isOurRest/isOurAjaxCall valent bien false, et le "if (!should
             * Inject) return u;" renvoyait l'URL intacte. Ce n'était donc PAS
             * un cas de rk_tab injecté à tort.
             *
             * Le vrai défaut architectural, exactement celui que cet audit
             * demandait de corriger : XMLHttpRequest.prototype.open/send et
             * window.fetch étaient réécrits pour TOUTE requête du site, sans
             * jamais retourner immédiatement au comportement natif pour les
             * requêtes externes. Le pipeline continuait toujours dans le
             * code RK (construction d'arguments, assignation de propriétés
             * custom sur l'objet xhr comme this.__rk_url, logs à chaque
             * appel) avant de déléguer à l'original — au lieu de déléguer
             * en premier et de ne RIEN faire d'autre pour ces requêtes.
             *
             * Cette version applique la règle demandée :
             *
             *   if (!isRKRequest(...)) return original.apply(this, arguments);
             *
             * — un vrai early return, sans aucune construction d'arguments,
             * sans propriété ajoutée sur l'objet natif, sans log par défaut.
             * Les requêtes externes (Better Messages, WooCommerce, Tutor,
             * Elementor, WPForms, SSA, ou tout autre plugin REST/AJAX)
             * traversent le navigateur exactement comme si ce script
             * n'existait pas.
             *
             * riada_get_count : confirmé absent de ce plugin (grep exhaustif
             * sur tout le code source) — c'est un handler externe (snippet
             * du thème). Son 400 n'est pas modifiable depuis ce fichier ; à
             * diagnostiquer côté handler (nonce manquant, méthode HTTP
             * attendue différente de GET, etc.), indépendamment de RK.
             */

            // ── Détection centralisée, seule et unique source de vérité ──
            function isRKRestUrl(url) {
                return typeof url === 'string' && url.indexOf(B) === 0;
            }

            function extractAjaxAction(url, body) {
                var fromUrl = /[?&]action=([^&]+)/.exec(String(url || ''));
                if (fromUrl) return decodeURIComponent(fromUrl[1]);
                if (body) {
                    var str = null;
                    if (typeof body === 'string') {
                        str = body;
                    } else if (typeof URLSearchParams !== 'undefined' && body instanceof URLSearchParams) {
                        str = body.toString();
                    } else if (typeof FormData !== 'undefined' && body instanceof FormData && typeof body.get === 'function') {
                        var v = body.get('action');
                        if (v) return String(v);
                    }
                    if (str) {
                        var fromBody = /(?:^|&)action=([^&]+)/.exec(str);
                        if (fromBody) return decodeURIComponent(fromBody[1]);
                    }
                }
                return null;
            }

            function isRKAjaxUrl(url, body) {
                if (typeof url !== 'string' || url.indexOf('admin-ajax.php') < 0) return false;
                var action = extractAjaxAction(url, body);
                return !!action && action.indexOf('rk_') === 0;
            }

            /** Point d'entrée unique — la seule fonction qui décide "RK ou pas". */
            function isRKRequest(url, body) {
                return isRKRestUrl(url) || isRKAjaxUrl(url, body);
            }

            function withTab(url) {
                if (url.indexOf('rk_tab=') >= 0) return url;
                return url + (url.indexOf('?') >= 0 ? '&' : '?') + 'rk_tab=' + T;
            }

            function log(msg, url) {
                if (DEBUG) console.log('[RK] ' + msg + (url ? ': ' + url : ''));
            }

            // ── XMLHttpRequest ────────────────────────────────────────────
            var origOpen = XMLHttpRequest.prototype.open;
            var origSend = XMLHttpRequest.prototype.send;

            XMLHttpRequest.prototype.open = function (method, url) {
                if (typeof url !== 'string' || !isRKRequest(url, null)) {
                    // Requête externe : passthrough total, aucune trace laissée
                    // sur l'objet xhr, aucun argument reconstruit.
                    return origOpen.apply(this, arguments);
                }
                log('REST/AJAX RK intercepté (open)', url);
                this.__rkPendingUrl = url; // uniquement pour les requêtes RK elles-mêmes
                var args = Array.prototype.slice.call(arguments);
                args[1] = withTab(url);
                return origOpen.apply(this, args);
            };

            XMLHttpRequest.prototype.send = function (body) {
                /*
                 * Ne s'active QUE si open() a déjà identifié cette requête
                 * comme RK (marqueur posé uniquement dans ce cas ci-dessus).
                 * Pour toute autre requête, __rkPendingUrl est absent :
                 * passthrough immédiat, send() natif inchangé.
                 */
                if (this.__rkPendingUrl === undefined) {
                    return origSend.apply(this, arguments);
                }
                // Cas jQuery.ajax : l'URL ne portait pas "action=", vérifié
                // maintenant via le body — pertinent seulement si l'URL
                // pointait déjà vers admin-ajax.php et a été retenue ci-dessus.
                log('body RK envoyé', this.__rkPendingUrl);
                return origSend.apply(this, arguments);
            };

            // ── fetch() ───────────────────────────────────────────────────
            if (window.fetch) {
                var origFetch = window.fetch;
                window.fetch = function (input, init) {
                    // Ne jamais toucher aux objets Request : on lit l'URL en
                    // lecture seule pour la décision, on ne reconstruit rien
                    // pour les requêtes externes.
                    var url = typeof input === 'string' ? input
                        : (input && typeof input.url === 'string' ? input.url : null);
                    var body = init && init.body ? init.body : null;

                    if (!url || !isRKRequest(url, body)) {
                        return origFetch.apply(this, arguments);
                    }

                    log('REST/AJAX RK intercepté (fetch)', url);
                    if (typeof input === 'string') {
                        return origFetch.call(this, withTab(input), init);
                    }
                    // input est un objet Request : on le reconstruit UNIQUEMENT
                    // ici, jamais pour une requête externe (retournée ci-dessus).
                    return origFetch.call(this, new Request(withTab(url), input), init);
                };
            }

            // ── Formulaires classiques postés vers admin-ajax.php ──────────
            document.addEventListener('submit', function (e) {
                var f = e.target;
                if (!f || !f.action || f.action.indexOf('admin-ajax.php') < 0) return;
                var actionInput = f.querySelector('input[name="action"]');
                var action = actionInput ? actionInput.value : '';
                if (!action || action.indexOf('rk_') !== 0) return; // externe : ne rien faire
                if (!f.querySelector('input[name="rk_tab"]')) {
                    var i = document.createElement('input');
                    i.type = 'hidden'; i.name = 'rk_tab'; i.value = T;
                    f.appendChild(i);
                }
            }, true);
        })(<?php echo wp_json_encode( $tab_id ); ?>, <?php echo wp_json_encode( $rk_rest_base ); ?>, <?php echo $debug ? 'true' : 'false'; ?>);
        </script>
        <?php
    }

    /**
     * Conversion des sessions enfant héritées (phase 6a — 19/08/2026).
     *
     * Avant ce correctif, un login enfant sans parent connecté écrivait un
     * VRAI cookie WordPress pour l'enfant. Ces cookies survivent au déploiement
     * et restent valides jusqu'à leur expiration : sans traitement, les enfants
     * déjà connectés continueraient d'apparaître dans /my-account/ pendant des
     * jours.
     *
     * Ici : on détecte un enfant authentifié par COOKIE (et non par rk_tab),
     * on détruit ce cookie, et on le bascule vers une session onglet — sans
     * jamais lui redemander son PIN.
     */
    public static function migrate_legacy_child_cookie(): void {
        if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) return;

        // Déjà en session onglet : rien à convertir.
        if ( self::get_current_tab_id() ) return;

        // L'enfant est-il authentifié par un vrai cookie WordPress ?
        $cookie_uid = (int) wp_validate_auth_cookie( '', 'logged_in' );
        if ( $cookie_uid <= 0 ) return;

        $user = get_user_by( 'id', $cookie_uid );
        if ( ! $user || ! in_array( 'rk_child', (array) $user->roles, true ) ) return;

        // Cookie enfant hérité confirmé → conversion.
        $tab_id = self::create_tab_session( $cookie_uid, 0 );

        $tokens = WP_Session_Tokens::get_instance( $cookie_uid );
        $tokens->destroy_all();
        wp_clear_auth_cookie();

        $dash = defined( 'RK_TUTOR_DASHBOARD_URL' ) ? RK_TUTOR_DASHBOARD_URL : '/dashboard/';
        wp_safe_redirect(
            add_query_arg( self::TAB_PARAM, $tab_id, home_url( rtrim( (string) $dash, '/' ) . '/' ) )
        );
        exit;
    }

    /* ── Session onglet enfant (sub-session sans toucher au WP cookie) ── */

    /**
     * Token stable pour un enfant — déterministe, jamais régénéré.
     * Dérivé de AUTH_KEY (secret wp-config) + child_wp_uid.
     * Non-devinable de l'extérieur, mais toujours identique pour le même enfant
     * → l'URL /dashboard/?rk_tab=TOKEN reste valide entre les sessions du parent.
     *
     * @param  int    $child_wp_uid
     * @return string 40 caractères hex
     */
    public static function child_token( int $child_wp_uid ): string {
        $epoch = (int) get_user_meta( $child_wp_uid, self::EPOCH_META, true );
        if ( $epoch < 1 ) $epoch = 1;

        // HMAC-SHA256 tronqué à 40 hex : le format historique est validé par
        // preg_match('/^[a-f0-9]{40}$/') dans ~24 endroits du plugin, on le
        // conserve donc strictement. sha1() nu est remplacé par un HMAC —
        // même longueur, primitive plus solide.
        return substr(
            hash_hmac( 'sha256', 'rk_tab|' . $child_wp_uid . '|' . $epoch, wp_salt( 'auth' ) ),
            0,
            40
        );
    }

    /**
     * Ancien format (sha1 nu, sans epoch). Toujours accepté pour ne PAS
     * casser les URLs déjà distribuées aux familles — sauf si le token de
     * cet enfant a été explicitement révoqué.
     */
    private static function legacy_child_token( int $child_wp_uid ): string {
        return hash( 'sha1', AUTH_KEY . '|rk_tab|' . $child_wp_uid );
    }

    /**
     * Vrai si $tab_id est un token valide pour cet enfant (nouveau OU ancien
     * format). Comparaison en temps constant.
     */
    private static function token_matches( int $child_wp_uid, string $tab_id ): bool {
        if ( hash_equals( self::child_token( $child_wp_uid ), $tab_id ) ) {
            return true;
        }
        // Repli legacy — désactivé dès qu'une révocation a eu lieu.
        if ( (int) get_user_meta( $child_wp_uid, self::EPOCH_META, true ) > 1 ) {
            return false;
        }
        return hash_equals( self::legacy_child_token( $child_wp_uid ), $tab_id );
    }

    /**
     * RÉVOCATION — invalide immédiatement et définitivement le lien d'un
     * enfant (PC perdu, lien partagé par erreur, départ d'un coach…).
     * Incrémente l'epoch : le token change, l'ancien ne sera plus jamais
     * accepté, et le format legacy est refusé pour cet enfant.
     *
     * C'était LE manque du design précédent : sans epoch, seule une rotation
     * de AUTH_KEY (= déconnexion de tout le site) pouvait invalider un token.
     *
     * @return string Le nouveau token, à redistribuer au parent.
     */
    public static function revoke_child_token( int $child_wp_uid ): string {
        $epoch = (int) get_user_meta( $child_wp_uid, self::EPOCH_META, true );
        $epoch = $epoch < 1 ? 2 : $epoch + 1;
        update_user_meta( $child_wp_uid, self::EPOCH_META, $epoch );

        /*
         * 6b-4a — Revoque TOUTES les sessions en table de cet enfant (tous
         * appareils, tous liens de quiz). Le bump d'epoch ci-dessus ferme en
         * plus la porte aux tokens HMAC herites.
         *
         * CHANGEMENT DE CONTRAT : avec des tokens aleatoires, il n'existe plus
         * de "prochain token" calculable — la valeur de retour est desormais
         * une chaine vide. L'enfant obtient un nouveau token a sa prochaine
         * connexion. Seul appelant : rkp_revoke_child_link() (helper WP-CLI).
         */
        if ( class_exists( 'RKP_ChildSessionRepository' ) ) {
            RKP_ChildSessionRepository::revoke_all_for_child( $child_wp_uid );
        }

        // Purge des traces de l'ancien token.
        $old = (string) get_user_meta( $child_wp_uid, 'rk_child_tab_token', true );
        if ( $old ) delete_transient( self::TAB_TRANSIENT . $old );
        delete_user_meta( $child_wp_uid, 'rk_child_tab_token' );
        delete_user_meta( $child_wp_uid, 'rk_child_tab_parent' );

        return '';   // voir le changement de contrat ci-dessus
    }

    /**
     * Crée (ou rafraîchit) la session onglet pour l'enfant.
     * Stocke les données côté serveur (transient). Aucun cookie modifié.
     * Utilise child_token() : même token à chaque appel → URL stable.
     *
     * @param  int    $child_wp_uid   WP user ID de l'enfant
     * @param  int    $parent_wp_uid  WP user ID du parent
     * @return string Token d'onglet (40 caractères hex)
     */
    public static function create_tab_session( int $child_wp_uid, int $parent_wp_uid ): string {
        /*
         * ── PHASE 6b-4a ──────────────────────────────────────────────────
         * Avant : token HMAC deterministe -> un enfant n'avait qu'UN token
         * possible, non revocable, avec une expiration illusoire (le repli
         * user_meta reconstruisait le transient indefiniment).
         *
         * Maintenant : token aleatoire random_bytes(20) -> 40 hex (format
         * strictement inchange, les 25 sites qui valident /^[a-f0-9]{40}$/
         * n'ont pas ete touches), stocke en table sous forme de SHA-256.
         *
         * Sessions concurrentes autorisees : cet appel ne revoque AUCUNE
         * session existante. Un enfant peut avoir plusieurs tokens actifs
         * (ordinateur, telephone, lien de quiz recu par e-mail).
         */
        if ( class_exists( 'RKP_ChildSessionRepository' ) ) {
            $token = RKP_ChildSessionRepository::open( $child_wp_uid, $parent_wp_uid );
            if ( '' !== $token ) {
                self::prime_session_cache( $token, $child_wp_uid, $parent_wp_uid );
                return $token;
            }
        }

        /*
         * P1-1 (6b-4-final) — FAIL CLOSED.
         *
         * Une version precedente retombait ici sur l'ancien token HMAC
         * deterministe quand la table etait indisponible. C'etait un repli
         * dangereux : il creait une session validable par le transient et
         * user_meta, donc il accordait une identite enfant EN CONTOURNANT
         * la table — exactement ce que le modele de securite interdit.
         *
         * Desormais : pas de table, pas de session. L'echec est silencieux
         * cote visiteur et journalise cote serveur. La migration s'execute
         * a la premiere visite admin et le service revient de lui-meme.
         */
        if ( function_exists( 'rkp_log' ) ) {
            rkp_log( '[RK Session] create_tab_session refuse : stockage de sessions indisponible.' );
        }
        return '';
    }

    /**
     * Ecrit le cache transient + le miroir user_meta d'une session.
     *
     * Le transient reste une optimisation de lecture (evite un SELECT par
     * requete) ; la table est la source de verite.
     *
     * Le miroir user_meta est CONSERVE volontairement : Coach
     * single-ays-quiz-maker.php:73 lit directement
     * get_user_meta( $child, 'rk_child_tab_parent' ) pour retrouver le parent.
     * Le supprimer casserait ce flux, hors perimetre 6b-4 (§7). Avec les
     * sessions concurrentes, ce miroir ne reflete que la DERNIERE session
     * ouverte — c'est suffisant pour l'usage qu'en fait le Coach (retrouver
     * le parent de l'enfant), qui ne depend pas du token lui-meme.
     */
    private static function prime_session_cache( string $tab_id, int $child_wp_uid, int $parent_wp_uid ): void {
        set_transient(
            self::TAB_TRANSIENT . $tab_id,
            array(
                'child_wp_uid'  => $child_wp_uid,
                'parent_wp_uid' => $parent_wp_uid,
                'created_at'    => time(),
            ),
            self::TAB_TTL
        );

        update_user_meta( $child_wp_uid, 'rk_child_tab_token', $tab_id );
        update_user_meta( $child_wp_uid, 'rk_child_tab_parent', $parent_wp_uid );
    }

    /**
     * Valide un tab_id et retourne les données de session ou null.
     *
     * @param  string     $tab_id
     * @return array|null
     */
    public static function validate_tab_session( string $tab_id ): ?array {
        if ( ! preg_match( '/^[a-f0-9]{40}$/', $tab_id ) ) return null;

        /* ── 1. Source de verite : la table (phase 6b-4a) ───────────────── */
        if ( class_exists( 'RKP_ChildSessionRepository' ) ) {
            $row = RKP_ChildSessionRepository::find( $tab_id );
            if ( $row ) {
                RKP_ChildSessionRepository::touch( $tab_id );   // last_seen_at + sliding window
                return array(
                    'child_wp_uid'  => (int) $row->child_id,
                    'parent_wp_uid' => (int) $row->parent_id,
                    'created_at'    => strtotime( (string) $row->created_at ),
                );
            }
            /*
             * ── FAIL-CLOSED (4.18.14) ────────────────────────────────────
             *
             * find() a renvoyé null : le token est soit inconnu, soit
             * RÉVOQUÉ / EXPIRÉ. Dans ce second cas il ne doit exister AUCUN
             * repli — or le cache transient ci-dessous survivait à une
             * révocation en masse (on ne connaît que les hash des tokens,
             * pas les tokens : les transients ne peuvent pas être supprimés
             * un par un). Résultat : une session « morte en base, vivante en
             * cache » pendant 7 jours.
             *
             * La table est la source de vérité : si elle connaît le token,
             * son verdict est final.
             */
            if ( RKP_ChildSessionRepository::is_known( $tab_id ) ) {
                delete_transient( self::TAB_TRANSIENT . $tab_id );
                return null;
            }
        }

        /* ── 2. Cache transient ─────────────────────────────────────────── */
        $data = get_transient( self::TAB_TRANSIENT . $tab_id );
        if ( is_array( $data ) ) return $data;

        /*
         * ── 3. CHEMIN LEGACY, BORNE DANS LE TEMPS ───────────────────────
         *
         * Reconnaissance : un token legacy est un token HMAC deterministe,
         * absent de la table, mais retrouvable via le miroir user_meta
         * rk_child_tab_token — puis CONFIRME par recalcul (token_matches()).
         * C'est ce recalcul qui distingue un vrai token legacy d'une valeur
         * arbitraire de 40 hex : un attaquant ne peut pas le fabriquer sans
         * wp_salt('auth').
         *
         * Migration : une fois valide, le token est ADOPTE par la table
         * (meme valeur, hash stocke). Les requetes suivantes passent par
         * l'etape 1 — le lien deja distribue continue de fonctionner, mais
         * devient revocable et reellement expirable.
         *
         * Fenetre : bornee par RK_LEGACY_TOKEN_DEADLINE. Passe cette date, un
         * token legacy non encore migre est refuse. Les sessions migrees, en
         * revanche, ne sont pas affectees — elles vivent en table.
         * Ce n'est donc pas un repli permanent.
         */
        if ( time() > self::legacy_deadline() ) return null;

        $users = get_users( array(
            'role'       => 'rk_child',
            'meta_key'   => 'rk_child_tab_token',
            'meta_value' => $tab_id,
            'fields'     => 'ID',
            'number'     => 1,
        ) );

        if ( empty( $users ) ) return null;

        $child_wp_uid  = (int) $users[0];
        $parent_wp_uid = (int) get_user_meta( $child_wp_uid, 'rk_child_tab_parent', true );

        // Confirmation cryptographique (anti-collision, anti-fabrication).
        if ( ! self::token_matches( $child_wp_uid, $tab_id ) ) return null;

        // Adoption : le token legacy devient une session en table.
        if ( class_exists( 'RKP_ChildSessionRepository' ) ) {
            RKP_ChildSessionRepository::adopt( $tab_id, $child_wp_uid, $parent_wp_uid );
        }

        $data = array(
            'child_wp_uid'  => $child_wp_uid,
            'parent_wp_uid' => $parent_wp_uid,
            'created_at'    => time(),
        );

        set_transient( self::TAB_TRANSIENT . $tab_id, $data, self::TAB_TTL );

        return $data;
    }

    /**
     * Détruit une session onglet (logout enfant).
     *
     * @param string $tab_id
     */
    public static function destroy_tab_session( string $tab_id ): void {
        if ( ! preg_match( '/^[a-f0-9]{40}$/', $tab_id ) ) return;

        /*
         * 6b-4a — Revoque UNIQUEMENT la session de ce token. Les autres
         * sessions de l'enfant restent actives : se deconnecter sur le
         * telephone ne ferme pas la session de la tablette, et n'invalide
         * pas un lien de quiz recu par e-mail.
         */
        if ( class_exists( 'RKP_ChildSessionRepository' ) ) {
            RKP_ChildSessionRepository::revoke( $tab_id );
        }

        // Récupérer le child_wp_uid avant suppression du transient
        $session = get_transient( self::TAB_TRANSIENT . $tab_id );
        delete_transient( self::TAB_TRANSIENT . $tab_id );

        // Supprimer aussi le backup user_meta
        if ( is_array( $session ) && ! empty( $session['child_wp_uid'] ) ) {
            delete_user_meta( (int) $session['child_wp_uid'], 'rk_child_tab_token' );
            delete_user_meta( (int) $session['child_wp_uid'], 'rk_child_tab_parent' );
        } else {
            // Transient déjà absent — retrouver via user_meta
            $users = get_users( array(
                'role'       => 'rk_child',
                'meta_key'   => 'rk_child_tab_token',
                'meta_value' => $tab_id,
                'fields'     => 'ID',
                'number'     => 1,
            ) );
            if ( ! empty( $users ) ) {
                delete_user_meta( (int) $users[0], 'rk_child_tab_token' );
                delete_user_meta( (int) $users[0], 'rk_child_tab_parent' );
            }
        }
    }

    /**
     * Lit le tab_id depuis la requête courante (GET ou POST).
     *
     * @return string Tab ID ou chaîne vide.
     */
    public static function get_current_tab_id(): string {
        // phpcs:disable WordPress.Security.NonceVerification
        $raw = $_GET[ self::TAB_PARAM ] ?? $_POST[ self::TAB_PARAM ] ?? '';
        // phpcs:enable
        $raw = sanitize_text_field( wp_unslash( (string) $raw ) );
        return preg_match( '/^[a-f0-9]{40}$/', $raw ) ? $raw : '';
    }

    /* ── Filter: determine_current_user ─────────────────────────────── */

    /**
     * Si ?rk_tab= est présent dans la requête et le transient est valide,
     * retourne l'ID WP de l'enfant.
     *
     * Priorité 20 (après le check standard WP à priorité 10).
     * Le cookie WP du parent n'est pas affecté.
     *
     * @param  int|false $user_id
     * @return int|false
     */
    public static function resolve_user_from_tab( $user_id ) {
        $tab_id = self::get_current_tab_id();
        if ( ! $tab_id ) return $user_id;

        /*
         * ── GARDE NO-CACHE (fix — toast "فشل التفويض" côté Tutor) ───────────
         *
         * Ce garde était auparavant placé APRÈS la décision de bornage
         * ci-dessous, donc atteint seulement quand is_tutor_child_context()
         * était déjà vrai. Sur child_app, avant l'ajout du cas "parent
         * absent", ce chemin sortait systématiquement à la ligne "return
         * $user_id" — le garde n'était donc JAMAIS posé pour ces pages.
         *
         * Conséquence concrète : LiteSpeed/QUIC.cloud pouvait mettre en
         * cache une page /dashboard/?rk_tab=... dont le HTML contenait un
         * nonce wp_rest (wp_create_nonce, voir class-rk-mc-assets.php)
         * généré pour l'utilisateur du moment — potentiellement "personne"
         * si le parent n'était pas connecté à cet instant précis. Toute
         * requête AJAX/REST ultérieure de Tutor LMS avec ce nonce périmé
         * échoue en 401/403, d'où le toast natif Tutor "فشل التفويض. يرجى
         * التحقق مما إذا كنت لا تزال مسجلاً مقوّضاً في هذا الموقع" — un
         * message Tutor, pas un texte de ce plugin (introuvable dans son
         * code source), confirmant que la cause est bien un nonce imprimé
         * dans du HTML mis en cache.
         *
         * Le garde est désormais posé ICI, avant toute décision de
         * substitution : toute requête portant un rk_tab au format valide
         * est marquée non-cacheable, que la substitution d'identité
         * s'applique ou non sur cette surface précise. C'est le seul
         * endroit qui garantit qu'aucune version figée du HTML — donc
         * aucun nonce périmé — ne peut être servie à un enfant.
         */
        if ( ! defined( 'DONOTCACHEPAGE' ) ) {
            define( 'DONOTCACHEPAGE', true );
        }
        do_action( 'litespeed_control_set_nocache', 'rk_tab session' );

        /*
         * ── BORNAGE (phase 6a, 19/08/2026) ────────────────────────────────
         * Avant : le contexte enfant s'appliquait PARTOUT où un rk_tab
         * traînait — /my-account/, panier, checkout, routes REST parent.
         * D'où la nécessité des rustines de RK_MC_Child_Restrictions et le
         * bug « l'enfant apparaît dans l'espace parent ».
         *
         * Phase 6b-1 : trois surfaces au lieu de deux. La substitution ne
         * s'applique plus QUE sur child_tutor — les pages Tutor natives où
         * Tutor exige réellement l'identité de l'enfant.
         *
         * Sur child_app (le dashboard Riada Kids), current_user reste le
         * PARENT : notre propre application n'a aucune raison de dépendre de
         * l'identité WordPress de l'enfant, elle lit
         * RK_Identity_Context::child_id().
         *
         * Sur parent (/my-account/, panier, checkout, onglets parent du
         * dashboard), le token est purement ignoré — pas de redirection,
         * pas de rustine.
         */
        if ( class_exists( 'RK_Identity_Context' )
             && ! RK_Identity_Context::is_tutor_child_context() ) {
            return $user_id;
        }

        $session = self::validate_tab_session( $tab_id );
        if ( ! $session ) return $user_id;

        // Sliding window : rafraîchir le TTL à chaque accès → session active 7 j sans re-login
        set_transient( self::TAB_TRANSIENT . $tab_id, $session, self::TAB_TTL );

        // Migration silencieuse : peupler le backup user_meta si absent (sessions créées avant ce fix)
        $child_uid_for_meta = (int) ( $session['child_wp_uid'] ?? 0 );
        if ( $child_uid_for_meta && ! get_user_meta( $child_uid_for_meta, 'rk_child_tab_token', true ) ) {
            update_user_meta( $child_uid_for_meta, 'rk_child_tab_token', $tab_id );
            if ( ! empty( $session['parent_wp_uid'] ) ) {
                update_user_meta( $child_uid_for_meta, 'rk_child_tab_parent', (int) $session['parent_wp_uid'] );
            }
        }

        $child_uid = (int) ( $session['child_wp_uid'] ?? 0 );
        if ( ! $child_uid ) return $user_id;

        // Vérifier que l'utilisateur existe toujours avec le rôle rk_child
        $user = get_user_by( 'id', $child_uid );
        if ( ! $user || ! in_array( 'rk_child', (array) $user->roles, true ) ) {
            self::destroy_tab_session( $tab_id );
            return $user_id;
        }

        return $child_uid;
    }

    /* ── Hook: wp_logout ─────────────────────────────────────────────── */

    /**
     * Si wp_logout() est appelé dans un contexte d'onglet enfant,
     * nettoyer le transient correspondant.
     *
     * @param int $user_id
     */
    public static function on_wp_logout( int $user_id ): void {
        $tab_id = self::get_current_tab_id();

        if ( $tab_id ) {
            $session = self::validate_tab_session( $tab_id );
            if ( $session && (int) ( $session['child_wp_uid'] ?? 0 ) === $user_id ) {
                self::destroy_tab_session( $tab_id );
            }
        }

        /*
         * ── FIN DE DÉLÉGATION (4.18.14) ──────────────────────────────────
         *
         * Une session onglet ouverte depuis /my-account/ est une DÉLÉGATION
         * de la session du parent : c'est lui qui l'a créée, après vérification
         * d'appartenance, et elle n'a jamais eu d'authentification propre.
         * Elle ne peut donc pas lui survivre — sinon l'onglet enfant continue
         * d'émettre des requêtes protégées portant des nonces émis pour un
         * utilisateur qui n'est plus connecté (cause racine du blocage).
         *
         * Les sessions à parent_id = 0 — login enfant direct par PIN, lien de
         * quiz reçu par e-mail — ne sont déléguées par personne : intactes.
         */
        if ( ! apply_filters( 'rkp_revoke_child_tabs_on_parent_logout', true, $user_id ) ) return;

        if ( $user_id > 0 && class_exists( 'RKP_ChildSessionRepository' ) ) {
            RKP_ChildSessionRepository::revoke_all_for_parent( $user_id );
        }
    }

    /* ── Coach : logout isolé ────────────────────────────────────────── */

    /**
     * Déconnecte le coach en détruisant uniquement son token de session
     * courant côté serveur. Les sessions des autres utilisateurs (parent,
     * enfant) sur leurs propres appareils sont inaffectées.
     *
     * Note : le cookie WP du navigateur du coach est effacé par le redirect
     * standard WordPress après l'appel à cette méthode.
     *
     * @param int $coach_wp_uid
     */
    public static function logout_coach( int $coach_wp_uid ): void {
        $manager = WP_Session_Tokens::get_instance( $coach_wp_uid );
        $token   = wp_get_session_token();
        if ( $token ) {
            $manager->destroy( $token ); // Détruit UNIQUEMENT la session courante
        }
    }

    /* ── Détection du type de session ───────────────────────────────── */

    /**
     * Retourne le type de session actif.
     *
     * @return string 'anonymous'|'child_tab'|'child_stacked'|'child_direct'|'coach'|'parent'
     */
    public static function get_session_type(): string {
        if ( ! is_user_logged_in() ) {
            // Vérifier aussi le tab session (pas de WP cookie mais rk_tab présent)
            $tab_id = self::get_current_tab_id();
            if ( $tab_id && self::validate_tab_session( $tab_id ) ) return 'child_tab';
            return 'anonymous';
        }

        $tab_id = self::get_current_tab_id();
        if ( $tab_id && self::validate_tab_session( $tab_id ) ) return 'child_tab';

        $user  = wp_get_current_user();
        $roles = (array) $user->roles;

        if ( in_array( 'rk_child', $roles, true ) ) {
            return class_exists( 'RK_MC_Child_User' ) && RK_MC_Child_User::has_parent_restore()
                ? 'child_stacked'
                : 'child_direct';
        }

        if ( in_array( 'tutor_instructor', $roles, true ) ) return 'coach';

        return 'parent';
    }

    public static function is_child_tab_session(): bool {
        return self::get_session_type() === 'child_tab';
    }

    public static function is_child_session(): bool {
        $type = self::get_session_type();
        return in_array( $type, array( 'child_tab', 'child_stacked', 'child_direct' ), true );
    }

    /**
     * Construit les paramètres URL à propager dans les liens de navigation.
     * Inclut child_id et rk_tab si présents.
     *
     * @param  int    $child_id
     * @return string '?child_id=X&rk_tab=Y' ou vide
     */
    public static function build_child_params( int $child_id ): string {
        $params = array( 'child_id' => $child_id );
        $tab_id = self::get_current_tab_id();
        if ( $tab_id ) {
            $params[ self::TAB_PARAM ] = $tab_id;
        }
        return '?' . http_build_query( $params );
    }
}
