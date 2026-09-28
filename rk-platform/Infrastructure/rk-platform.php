<?php
declare( strict_types=1 );
/**
 * Plugin Name:  RiadaKids Platform
 * Plugin URI:   https://riadakids.com
 * Description:  Plateforme unifiée — enfants · coachs · gamification · parcours
 * Version:      4.18.17
 * Requires PHP: 8.1
 * Author:       Eya
 * Text Domain:  rk-platform
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * DEUX MODES :
 *
 *   TRANSITION  — rk-my-children et/ou rk-coach-hub encore actifs.
 *                 Ce plugin se contente d'être l'infrastructure DDD (actuel).
 *
 *   UNIFIÉ      — les deux anciens plugins sont désactivés.
 *                 Ce plugin charge tout : Children + Coach + Platform.
 *
 * → Pour finaliser la fusion :
 *     1. Désactiver rk-my-children dans WP Admin > Extensions
 *     2. Désactiver rk-coach-hub dans WP Admin > Extensions
 *     Le plugin passe automatiquement en mode UNIFIÉ.
 * ─────────────────────────────────────────────────────────────────────────────
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/* ════════════════════════════════════════════════════════════════
 * CONSTANTES PLATEFORME (toujours)
 * ════════════════════════════════════════════════════════════════ */

if ( ! defined( 'RKP_VERSION' ) ) {
define( 'RKP_VERSION', '4.18.17' );   // fix : tour de bienvenue Tutor LMS (isCloseable:false) neutralise sur les surfaces enfant -- CSS immediat + garde JS Alpine, sans dependre d'une meta interne Tutor

/* Version du SCHÉMA de base de données — à incrémenter à CHAQUE fois qu'une
 * migration est ajoutée/modifiée dans rkp_run_schema_migrations(). Tant que
 * l'option 'rkp_db_version' correspond, aucune requête DDL n'est exécutée. */
define( 'RKP_DB_SCHEMA_VERSION', '4.19.1' );   // 4.19.1 : + wp_rk_refresh_tokens (mobile, enfin migrée)
define( 'RKP_DIR',     plugin_dir_path( __FILE__ ) );
define( 'RKP_URL',     plugin_dir_url( __FILE__ ) );

/* ════════════════════════════════════════════════════════════════
 * LOGGING CONDITIONNEL (audit 19/08/2026)
 *
 * Les error_log() du plugin étaient inconditionnels — y compris des
 * logs de SUCCÈS ('✅ Inscrit wp_user#...') émis à chaque inscription.
 * Sur un site à trafic, debug.log grossissait en continu.
 *
 * rkp_log() n'écrit que si WP_DEBUG ET WP_DEBUG_LOG sont actifs.
 * Les erreurs fatales (shutdown handler) gardent volontairement un
 * error_log() direct : elles doivent être tracées en production.
 * ════════════════════════════════════════════════════════════════ */

if ( ! function_exists( 'rkp_log' ) ) {
    function rkp_log( string $message ): void {
        if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) return;
        if ( ! defined( 'WP_DEBUG_LOG' ) || ! WP_DEBUG_LOG ) return;
        error_log( $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
    }
}

/* ════════════════════════════════════════════════════════════════
 * AUTOLOADER RKP_ (toujours — même en mode transition)
 * ════════════════════════════════════════════════════════════════ */

spl_autoload_register( static function ( string $class ): void {
    if ( strpos( $class, 'RKP_' ) !== 0 ) return;
    static $map = [
        // Domain
        'RKP_Course'                     => 'Domain/Course.php',
        'RKP_Lesson'                     => 'Domain/Lesson.php',
        'RKP_Topic'                      => 'Domain/Topic.php',
        'RKP_Quiz'                       => 'Domain/Quiz.php',
        'RKP_Progress'                   => 'Domain/Progress.php',
        'RKP_Enrollment'                 => 'Domain/Enrollment.php',
        'RKP_Booking'                    => 'Domain/Booking.php',
        'RKP_JourneySnapshot'            => 'Domain/JourneySnapshot.php',
        // Domain Events
        'RKP_DomainEvent'                => 'Domain/Events/DomainEvent.php',
        'RKP_LessonCompleted'            => 'Domain/Events/LessonCompleted.php',
        'RKP_QuizPassed'                 => 'Domain/Events/QuizPassed.php',
        'RKP_QuizFailed'                 => 'Domain/Events/QuizFailed.php',
        'RKP_BookingConfirmed'           => 'Domain/Events/BookingConfirmed.php',
        'RKP_CourseStarted'              => 'Domain/Events/CourseStarted.php',
        'RKP_CourseCompleted'            => 'Domain/Events/CourseCompleted.php',
        'RKP_ReportPublished'            => 'Domain/Events/ReportPublished.php',
        'RKP_BadgeEarned'                => 'Domain/Events/BadgeEarned.php',
        'RKP_LevelUp'                    => 'Domain/Events/LevelUp.php',
        'RKP_CoachMessageReceived'       => 'Domain/Events/CoachMessageReceived.php',
        'RKP_EventExperienceService'     => 'Application/EventExperienceService.php',
        'RKP_EventExperienceLog'         => 'Infrastructure/Events/EventExperienceLog.php',
        'RKP_EntryStoryService'          => 'Application/EntryStoryService.php',
        // Application
        'RKP_EventBus'                   => 'Application/EventBus.php',
        'RKP_LearningQueryService'       => 'Application/Query/LearningQueryService.php',
        'RKP_AdventureQueryService'      => 'Application/Query/AdventureQueryService.php',
        'RKP_JourneyQueryService'        => 'Application/Query/JourneyQueryService.php',
        'RKP_BookingQueryService'        => 'Application/Query/BookingQueryService.php',
        'RKP_AnalyticsQueryService'      => 'Application/Query/AnalyticsQueryService.php',
        'RKP_CoachQueryService'          => 'Application/Query/CoachQueryService.php',
        'RKP_GamificationQueryService'   => 'Application/Query/GamificationQueryService.php',
        'RKP_BadgeQueryService'          => 'Application/Query/BadgeQueryService.php',
        'RKP_MissionQueryService'        => 'Application/Query/MissionQueryService.php',
        'RKP_AssessmentQueryService'     => 'Application/Query/AssessmentQueryService.php',
        'RKP_CoachDataQueryService'      => 'Application/Query/CoachDataQueryService.php',
        'RKP_CoachMissionQueryService'   => 'Application/Query/CoachMissionQueryService.php',
        'RKP_ChildCommandService'        => 'Application/Command/ChildCommandService.php',
        'RKP_CoachCommandService'        => 'Application/Command/CoachCommandService.php',
        'RKP_ParentCommandService'       => 'Application/Command/ParentCommandService.php',
        'RKP_AdminCommandService'        => 'Application/Command/AdminCommandService.php',
        'RKP_GamificationCommandService' => 'Application/Command/GamificationCommandService.php',
        'RKP_BadgeCommandService'        => 'Application/Command/BadgeCommandService.php',
        'RKP_MissionCommandService'      => 'Application/Command/MissionCommandService.php',
        'RKP_AssessmentCommandService'   => 'Application/Command/AssessmentCommandService.php',
        'RKP_CoachMissionCommandService' => 'Application/Command/CoachMissionCommandService.php',
        'RKP_AvailabilityCommandService' => 'Application/Command/AvailabilityCommandService.php',
        'RKP_CacheInvalidationListener'  => 'Application/Listeners/CacheInvalidationListener.php',
        // Infrastructure — Tutor LMS
        'RKP_CourseRepository'           => 'Infrastructure/Tutor/CourseRepository.php',
        'RKP_LessonRepository'           => 'Infrastructure/Tutor/LessonRepository.php',
        'RKP_TopicRepository'            => 'Infrastructure/Tutor/TopicRepository.php',
        'RKP_QuizRepository'             => 'Infrastructure/Tutor/QuizRepository.php',
        'RKP_EnrollmentRepository'       => 'Infrastructure/Tutor/EnrollmentRepository.php',
        'RKP_ProgressRepository'         => 'Infrastructure/Tutor/ProgressRepository.php',
        'RKP_AnalyticsRepository'        => 'Infrastructure/Tutor/AnalyticsRepository.php',
        'RKP_QuizAttemptRepository'      => 'Infrastructure/Tutor/QuizAttemptRepository.php',
        // Infrastructure — SSA
        'RKP_BookingRepository'          => 'Infrastructure/SSA/BookingRepository.php',
        'RKP_AvailabilityRepository'     => 'Infrastructure/SSA/AvailabilityRepository.php',
        // Infrastructure — WordPress
        'RKP_ChildRepository'            => 'Infrastructure/WordPress/ChildRepository.php',
        'RKP_SkillRepository'            => 'Infrastructure/WordPress/SkillRepository.php',
        'RKP_UserRepository'             => 'Infrastructure/WordPress/UserRepository.php',
        'RKP_CategoryRepository'         => 'Infrastructure/WordPress/CategoryRepository.php',
        'RKP_CoachStudentRepository'     => 'Infrastructure/WordPress/CoachStudentRepository.php',
        'RKP_GamificationRepository'     => 'Infrastructure/WordPress/GamificationRepository.php',
        'RKP_BadgeRepository'            => 'Infrastructure/WordPress/BadgeRepository.php',
        'RKP_MissionRepository'          => 'Infrastructure/WordPress/MissionRepository.php',
        'RKP_AssessmentRepository'       => 'Infrastructure/WordPress/AssessmentRepository.php',
        'RKP_CoachSessionRepository'     => 'Infrastructure/WordPress/CoachSessionRepository.php',
        'RKP_CoachMissionRepository'     => 'Infrastructure/WordPress/CoachMissionRepository.php',
        'RKP_CertificateRepository'      => 'Infrastructure/WordPress/CertificateRepository.php',
        'RKP_DB'                         => 'Infrastructure/WordPress/RKP_DB.php',
        // Application — v2.2 (audit) : Ports, Wallet
        'RKP_LearningPort'               => 'Application/Contracts/Ports.php',
        'RKP_BookingPort'                => 'Application/Contracts/Ports.php',
        'RKP_MessagingPort'              => 'Application/Contracts/Ports.php',
        'RKP_WalletPort'                 => 'Application/Contracts/Ports.php',
        'RKP_WalletQueryService'         => 'Application/Query/WalletQueryService.php',
        'RKP_WalletCommandService'       => 'Application/Command/WalletCommandService.php',
        // Notifications — v2.8
        'RKP_NotificationRouter'         => 'Application/Listeners/NotificationRouter.php',
        // Quiz Flow — v2.6
        'RKP_QuizAssigned'               => 'Domain/Events/QuizAssigned.php',
        'RKP_AysQuizRepository'          => 'Infrastructure/Ays/AysQuizRepository.php',
        'RKP_QuizFlowCommandService'     => 'Application/Command/QuizFlowCommandService.php',
        // Infrastructure — v2.5 (audit)
        'RKP_TutorEnvironment'           => 'Infrastructure/Tutor/TutorEnvironment.php',
        // Infrastructure — v2.2 (audit) : Container, adapters, wallet, archive
        'RKP_Container'                  => 'Infrastructure/Container/Container.php',
        'RKP_TutorLearningAdapter'       => 'Infrastructure/Container/DefaultAdapters.php',
        'RKP_SsaBookingAdapter'          => 'Infrastructure/Container/DefaultAdapters.php',
        'RKP_BetterMessagesAdapter'      => 'Infrastructure/Container/DefaultAdapters.php',
        'RKP_WalletLedgerRepository'     => 'Infrastructure/WordPress/WalletLedgerRepository.php',
        'RKP_ChildArchiveRepository'     => 'Infrastructure/WordPress/ChildArchiveRepository.php',
        // Infrastructure — v2.1 (audit)
        'RKP_CustomBadgeRepository'      => 'Infrastructure/WordPress/CustomBadgeRepository.php',
        'RKP_MessageRepository'          => 'Infrastructure/BetterMessages/MessageRepository.php',
        'RKP_EventLog'                   => 'Infrastructure/Events/EventLog.php',
        'RKP_RateLimiter'                => 'Infrastructure/Security/RateLimiter.php',
        // Infrastructure — Bridges
        // v3.0/3.1 — Progress sync, Family link
        'RKP_ProgressSnapshotRepository' => 'Infrastructure/WordPress/ProgressSnapshotRepository.php',
        'RKP_ProgressSyncListener'       => 'Application/Listeners/ProgressSyncListener.php',
        'RKP_FamilyLinkQueryService'     => 'Application/Query/FamilyLinkQueryService.php',
        'RKP_FamilyLinkCommandService'   => 'Application/Command/FamilyLinkCommandService.php',
        'RKP_TutorBridge'                => 'Infrastructure/Bridge/TutorBridge.php',
        'RKP_RKBridge'                   => 'Infrastructure/Bridge/RKBridge.php',
        // Mobile (auth enfant + self-access) — 4.19.0/4.19.1
        // ABSENTES du mapping jusqu'ici : cf. audit 16/09/2026 §2.2 — le
        // module Mobile "existait" en fichiers mais class_exists() sur
        // TOUTES ces classes retournait systématiquement false.
        'RKP_JwtService'                 => 'Infrastructure/Security/JwtService.php',
        'RKP_RefreshTokenRepository'     => 'Infrastructure/Security/RefreshTokenRepository.php',
        'RKP_MobileChildAccess'          => 'Infrastructure/Security/MobileChildAccess.php',
        'RKP_AuthTokenCommandService'    => 'Application/Command/AuthTokenCommandService.php',
    ];
    if ( isset( $map[ $class ] ) ) {
        require_once RKP_DIR . $map[ $class ];
    }
} );

/* ════════════════════════════════════════════════════════════════
 * ASSETS MINIFIÉS — v2.2.0 (Audit P2-15)
 * `npm run build` génère un frère .min.js/.min.css pour chaque asset.
 * Ce filtre central sert automatiquement la version minifiée quand
 * elle existe — AUCUN enqueue à modifier, débrayable via SCRIPT_DEBUG.
 * ════════════════════════════════════════════════════════════════ */

function rkp_min_url( string $url ): string {
    if ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) return $url;
    if ( strpos( $url, RKP_URL ) !== 0 ) return $url;
    if ( ! preg_match( '/\.(js|css)(\?|$)/', $url ) || strpos( $url, '.min.' ) !== false ) return $url;

    $min_url  = preg_replace( '/\.(js|css)(\?|$)/', '.min.$1$2', $url );
    $rel      = parse_url( preg_replace( '/\?.*/', '', $min_url ), PHP_URL_PATH );
    $rel      = substr( (string) $rel, strlen( (string) parse_url( RKP_URL, PHP_URL_PATH ) ) );
    return file_exists( RKP_DIR . $rel ) ? $min_url : $url;
}
add_filter( 'style_loader_src',  'rkp_min_url', 20 );
add_filter( 'script_loader_src', 'rkp_min_url', 20 );

/* ════════════════════════════════════════════════════════════════
 * NO-CACHE CENTRALISÉ — v4.18.18
 * Un seul point d'appel pour toutes les pages standalone du plugin
 * (login enfant, login coach, espace coach, dashboards) qui NE DOIVENT
 * JAMAIS être servies depuis un cache figé.
 *
 * Pourquoi centraliser :
 * header('Cache-Control: no-store') seul NE SUFFIT PAS avec un cache
 * de page serveur (LiteSpeed/LSCache, WP Rocket page cache, NitroPack,
 * Cloudflare "Cache Everything") : ces couches agissent avant/à côté
 * de PHP et doivent recevoir un signal explicite (DONOTCACHEPAGE,
 * l'action LiteSpeed dédiée) — sinon elles peuvent quand même mettre
 * la réponse en cache et resservir une version périmée au refresh
 * suivant. Avant cette fonction, ce garde était dupliqué page par
 * page et certains templates (coach login, espace coach) ne l'avaient
 * pas → c'était la source du "vieille version au refresh".
 *
 * @param string $context Étiquette libre pour le tag LiteSpeed (debug).
 */
function rkp_no_cache_page( string $context = 'rk_page' ): void {
    if ( headers_sent() ) {
        return;
    }

    // 1. Headers HTTP standards — couvre navigateur + proxys respectueux.
    nocache_headers();
    header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private' );
    header( 'Pragma: no-cache' );
    header( 'Expires: 0' );

    // 2. Signal WordPress générique — lu par de nombreux plugins de cache
    //    de page (WP Rocket, W3TC, WP Super Cache…) en plus de LiteSpeed.
    if ( ! defined( 'DONOTCACHEPAGE' ) ) {
        define( 'DONOTCACHEPAGE', true );
    }

    // 3. Signal LiteSpeed explicite — nécessaire même avec DONOTCACHEPAGE
    //    défini tardivement dans certaines versions de LSCWS.
    do_action( 'litespeed_control_set_nocache', $context );

    // 4. Cloudflare / reverse-proxy générique : certains respectent ce
    //    header non-standard mais largement reconnu en pratique.
    header( 'CDN-Cache-Control: no-store' );
}

/* ════════════════════════════════════════════════════════════════
 * BUNDLING JS AU RUNTIME — v4.18.19
 * Concatène une liste de modules JS en un seul fichier, mis en cache
 * sur disque (wp-content/uploads/rk-bundles/), régénéré seulement si
 * un fichier source a changé (comparaison filemtime).
 *
 * Pourquoi : certaines pages du plugin (ex. l'espace coach) chargent
 * une quinzaine de fichiers <script> distincts — chacun une requête
 * HTTP séparée, même en `defer`. Sans étape de build (npm) disponible
 * dans ce dossier plugin, ce bundler runtime est le moyen le plus sûr
 * de réduire ces requêtes à une seule, sans dépendre d'un outillage
 * externe ni changer le comportement du code des modules eux-mêmes.
 *
 * L'ORDRE des fichiers dans $modules est préservé strictement dans le
 * bundle final — indispensable ici car les modules dépendent les uns
 * des autres dans cet ordre précis (ex. rk-auth-manager avant le reste).
 *
 * @param string   $bundle_name  Identifiant unique du bundle (nom de fichier).
 * @param string   $src_dir      Dossier absolu contenant les fichiers sources.
 * @param string[] $modules      Noms de fichiers SANS extension, dans l'ordre voulu.
 * @param string   $src_url      URL absolue correspondant à $src_dir (pour rkp_min_url).
 * @return string|null  URL du bundle prêt à l'emploi, ou null si échec (→ fallback appelant).
 */
function rkp_get_or_build_js_bundle( string $bundle_name, string $src_dir, array $modules, string $src_url ): ?string {
    $upload    = wp_upload_dir();
    if ( ! empty( $upload['error'] ) ) {
        return null;
    }
    $cache_dir = trailingslashit( $upload['basedir'] ) . 'rk-bundles';
    $cache_url = trailingslashit( $upload['baseurl'] ) . 'rk-bundles';

    // Empreinte de version = hash des filemtime de chaque module source.
    // Change dès qu'un seul fichier est modifié → régénération automatique,
    // sans jamais servir un bundle périmé après un déploiement.
    $stamps = [];
    $files  = [];
    foreach ( $modules as $mod ) {
        $path = $src_dir . $mod . '.js';
        if ( ! file_exists( $path ) ) {
            continue; // même tolérance que le fallback historique (module optionnel absent).
        }
        $files[]  = $path;
        $stamps[] = $mod . ':' . filemtime( $path );
    }
    if ( empty( $files ) ) {
        return null;
    }
    $hash = substr( md5( implode( '|', $stamps ) ), 0, 12 );

    $filename  = sanitize_file_name( $bundle_name . '-' . $hash . '.js' );
    $filepath  = $cache_dir . '/' . $filename;
    $fileurl   = $cache_url . '/' . $filename;

    if ( file_exists( $filepath ) ) {
        return $fileurl; // Déjà généré pour cette empreinte exacte — rien à refaire.
    }

    if ( ! wp_mkdir_p( $cache_dir ) ) {
        return null;
    }

    $parts = [];
    foreach ( $files as $i => $path ) {
        // Séparateur + commentaire d'origine : facilite le debug en
        // dev tools (sourcemap absent, mais au moins on sait quel
        // module a produit quelle ligne).
        $parts[] = "/* === {$modules[ $i ]}.js === */\n" . file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
    }
    $bundle = implode( "\n;\n", $parts ); // `;` de sécurité entre modules (évite les soucis d'ASI si un fichier source oublie son point-virgule final).

    $written = file_put_contents( $filepath, $bundle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents
    if ( false === $written ) {
        return null;
    }

    // Nettoyage : supprime les anciennes générations de CE bundle
    // (empreintes précédentes) pour ne pas accumuler indéfiniment des
    // fichiers orphelins à chaque déploiement.
    foreach ( glob( $cache_dir . '/' . sanitize_file_name( $bundle_name ) . '-*.js' ) ?: [] as $old ) {
        if ( $old !== $filepath ) {
            @unlink( $old ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        }
    }

    return $fileurl;
}

/* ════════════════════════════════════════════════════════════════
 * DÉTECTION DU MODE
 * ════════════════════════════════════════════════════════════════ */

$_rkp_active  = (array) get_option( 'active_plugins', [] );
$_rkp_mc_on   = in_array( 'rk-my-children/rk-my-children.php', $_rkp_active, true );
$_rkp_ch_on   = in_array( 'rk-coach-hub/rk-coach-hub.php',    $_rkp_active, true );
$_rkp_unified = ! $_rkp_mc_on && ! $_rkp_ch_on;
unset( $_rkp_active );

/* ════════════════════════════════════════════════════════════════
 * HOOKS ACTIVATION / DÉSACTIVATION (valables dans les deux modes)
 * ════════════════════════════════════════════════════════════════ */

register_activation_hook( __FILE__,   'rkp_platform_activate'   );
register_deactivation_hook( __FILE__, 'rkp_platform_deactivate' );

if ( $_rkp_unified ) {

    /* ════════════════════════════════════════════════════════════
     * MODE UNIFIÉ
     * ════════════════════════════════════════════════════════════ */

    /* ── Constantes modules (backward compat) ─────────────────── */

    /* v8.3.1 — Design v4 (maquettes Homepage) + neutralisation du chrome
     * natif Tutor LMS 4.x sur la home enfant. Bump obligatoire : sert de
     * cache-buster ?ver= pour rk-dashboard-v4.css (LiteSpeed). */
    /* Bump 6b-4-final (P0-2) : sert de $ver aux enqueue Children
     * (class-rk-mc-assets.php:72-105). Les .min.css/.min.js ont ete
     * regeneres au Sprint 1 ; sans bump, LiteSpeed/QUIC.cloud et les
     * navigateurs continueraient de servir les anciens fichiers. */
    define( 'RK_MC_VERSION',  '9.6.0' );   // Bump cache-busting : voir historique — cette constante alimente ?ver=X sur tous les assets enqueue de ce module (child-card.css, child-list.js, etc.). À incrémenter à chaque changement CSS/JS pour forcer le navigateur/LiteSpeed à recharger les fichiers.
    define( 'RK_MC_FILE',     __FILE__ );
    define( 'RK_MC_DIR',      RKP_DIR . 'Modules/Children/' );
    define( 'RK_MC_URL',      RKP_URL . 'Modules/Children/' );
    define( 'RK_MC_BASENAME', plugin_basename( __FILE__ ) );
    if ( ! defined( 'RK_TUTOR_DASHBOARD_URL' ) ) {
        define( 'RK_TUTOR_DASHBOARD_URL', '/dashboard/' );
    }

    /* Bump 6b-4-final (P0-2) : rk-auth-manager.js et
     * rk-coach-page-students.js ont ete modifies (retrait des console.log
     * tracant les tokens JWT) et leurs .min regeneres. */
    define( 'RK_COACH_HUB_VERSION',  '1.4.3' );   // fix : bouton menh/sahab pouvait revenir a l'ancien etat apres un GET de resync perime
    define( 'RK_COACH_HUB_FILE',     __FILE__ );
    define( 'RK_COACH_HUB_DIR',      RKP_DIR . 'Modules/Coach/' );
    define( 'RK_COACH_HUB_URL',      RKP_URL . 'Modules/Coach/' );
    define( 'RK_PLATFORM_MOBILE_DIR', RKP_DIR . 'Modules/Mobile/' );
    define( 'RK_COACH_HUB_BASENAME', plugin_basename( __FILE__ ) );

    /* ── Module Children — chargement immédiat ────────────────── */

    require_once RK_MC_DIR . 'includes/rk-mc-db-helpers.php';
    require_once RK_MC_DIR . 'includes/class-rk-mc-child-repository.php';
    require_once RK_MC_DIR . 'includes/rk-mc-svg-icons.php';
    // Phase 6b-1 — identité. SurfaceResolver d'abord (RK_Identity_Context
    // en dépend), puis le contexte. Chargés AVANT le session manager :
    // resolve_user_from_tab() les consulte.
    require_once RKP_DIR . 'Infrastructure/Security/ChildSessionRepository.php';
    require_once RKP_DIR . 'Application/Identity/SurfaceResolver.php';
    require_once RKP_DIR . 'Application/Identity/RK_Identity_Context.php';
    require_once RK_MC_DIR . 'includes/class-rk-mc-child-context.php';
    require_once RK_MC_DIR . 'includes/class-rk-mc-endpoint.php';
    require_once RK_MC_DIR . 'includes/class-rk-session-manager.php';
    require_once RK_MC_DIR . 'includes/class-rk-session-guard.php';
    require_once RK_MC_DIR . 'includes/class-rk-mc-pin-security.php';   // v2.1 — P0-1 : PIN chiffré + anti brute-force
    require_once RK_MC_DIR . 'includes/class-rk-mc-child-user.php';
    require_once RK_MC_DIR . 'includes/class-rk-mc-child-restrictions.php';
    require_once RK_MC_DIR . 'includes/class-rk-mc-tutor-child.php';
    require_once RK_MC_DIR . 'includes/class-rk-mc-rest.php';
    require_once RK_MC_DIR . 'includes/class-rk-mc-assets.php';
    require_once RK_MC_DIR . 'includes/class-rk-mc-child-card-renderer.php';
    require_once RK_MC_DIR . 'includes/class-rk-tutor-course-service.php';
    require_once RK_MC_DIR . 'includes/class-rk-booking-calendar-service.php';
    require_once RK_MC_DIR . 'includes/class-rk-mc-child-dashboard-service.php';
    require_once RK_MC_DIR . 'includes/rk-mc-utils.php';
    require_once RK_MC_DIR . 'includes/class-rk-mc-child-dashboard-data.php';
    require_once RK_MC_DIR . 'includes/class-rk-mc-child-dashboard.php';
    require_once RK_MC_DIR . 'includes/class-rk-mc-course-enrollment.php';
    require_once RK_MC_DIR . 'includes/class-rk-mc-gamification-service.php';
    require_once RK_MC_DIR . 'includes/class-rk-mc-badge-service.php';
    require_once RK_MC_DIR . 'includes/class-rk-mc-mission-service.php';
    require_once RK_MC_DIR . 'includes/class-rk-mc-skill-service.php';
    require_once RK_MC_DIR . 'includes/class-rk-mc-assessment-service.php';
    require_once RK_MC_DIR . 'includes/class-rk-mc-rapport-ajax.php';
    require_once RK_MC_DIR . 'includes/class-rk-mc-message-service.php';
    require_once RK_MC_DIR . 'includes/class-rk-mc-notification-service.php';
    require_once RK_MC_DIR . 'includes/class-rk-mc-experience-layer-ajax.php';
    require_once RK_MC_DIR . 'includes/class-rk-mc-guidance-ajax.php';
    require_once RK_MC_DIR . 'includes/class-rk-mc-badges-ajax.php';
    require_once RK_MC_DIR . 'includes/class-rk-mc-adventures-filter-service.php';
    require_once RK_MC_DIR . 'includes/class-rk-mc-quiz-play-ajax.php';        // v9.56 — Moteur de quiz natif
    require_once RK_MC_DIR . 'includes/class-rk-mc-tutor-dashboard.php';
    require_once RK_MC_DIR . 'includes/class-rk-mc-booking-bridge.php';
    require_once RK_MC_DIR . 'includes/class-rk-event-bus.php';
    require_once RK_MC_DIR . 'includes/class-rk-pdf-service.php';

    /* ── Module Mobile — app enfant (RiadaKids Mobile) ─────────────
     * Jusqu'ici jamais chargé : ni require_once, ni rest_api_init,
     * ni déterminaton d'identité par Bearer — cf. audit 16/09/2026 §2.2.
     * Chargé ICI, après le module Children dont il dépend entièrement
     * (RK_MC_Child_Repository, RK_MC_Pin_Security, RKP_MobileChildAccess).
     * @since 4.19.1 */
    require_once RK_PLATFORM_MOBILE_DIR . 'includes/class-rk-auth-rest.php';
    require_once RK_PLATFORM_MOBILE_DIR . 'includes/class-rk-dashboard-rest.php';
    require_once RK_PLATFORM_MOBILE_DIR . 'includes/class-rk-bookings-rest.php';
    require_once RK_PLATFORM_MOBILE_DIR . 'includes/class-rk-assessments-rest.php';
    require_once RK_PLATFORM_MOBILE_DIR . 'includes/class-rk-missions-rest.php';
    require_once RK_PLATFORM_MOBILE_DIR . 'includes/class-rk-children-content-rest.php';
    // @since 4.20.4 — écran mobile "مغامراتي". Dépend de
    // RK_MC_Adventures_Filter_Service (Modules/Children), déjà require_once
    // plus haut (ligne ~438) — donc chargée avant ce point.
    require_once RK_PLATFORM_MOBILE_DIR . 'includes/class-rk-adventures-rest.php';
    // @since 4.20.5 — écran mobile "الحساب" (remplace l'ouverture de
    // rk-mon-parcours/ dans un navigateur externe, qui exigeait une session
    // WordPress absente du contexte mobile JWT). Dépend de
    // RKP_JourneyQueryService/RKP_LearningQueryService (Application/Query),
    // déjà chargées par l'autoloader RKP_ en tête de fichier.
    require_once RK_PLATFORM_MOBILE_DIR . 'includes/class-rk-journey-rest.php';
    // @since 4.21.0 — cloche de notifications de l'app mobile, même source
    // de données que la cloche web (RK_MC_Notification_Service). Dépend de
    // ce service (Modules/Children), déjà require_once plus haut.
    require_once RK_PLATFORM_MOBILE_DIR . 'includes/class-rk-notifications-rest.php';
    // @since 4.22.0 — écran mobile natif "الرسائل" (remplace le FAB qui
    // ouvrait rk-messages.php dans un navigateur externe, sans session
    // WordPress valide côté JWT mobile). Dépend de RK_MC_Message_Service
    // (Modules/Children), déjà require_once plus haut.
    require_once RK_PLATFORM_MOBILE_DIR . 'includes/class-rk-messages-rest.php';
    // @since 4.20.2 — corrige "Failed to fetch" côté navigateur sur les
    // routes /rk/v1/* : le header custom X-RK-Auth déclenche un préflight
    // CORS que WordPress ne satisfait pas par défaut (voir la classe
    // pour le détail). N'affecte QUE les routes /rk/v1/*.
    require_once RK_PLATFORM_MOBILE_DIR . 'includes/class-rk-mobile-cors.php';
    RK_Auth_REST::init();
    RK_Dashboard_REST::init();
    RK_Bookings_REST::init();
    RK_Assessments_REST::init();
    RK_Missions_REST::init();
    RK_Journey_REST::init();
    RK_Notifications_REST::init();
    RK_Messages_REST::init();
    RK_Children_Content_REST::init();
    RK_Adventures_REST::init();
    RK_Mobile_CORS::init();

    if ( is_admin() ) {
        require_once RK_MC_DIR . 'includes/class-rk-mc-admin.php';
        require_once RK_MC_DIR . 'includes/class-rk-admin-students.php';
    }

    /* ── Auth coach : restauration du header Authorization ─────
     * Chargée ICI, hors de rkp_unified_boot(), qui fait un return
     * anticipé si WooCommerce ou Tutor LMS ne sont pas disponibles.
     * Dans ce cas le header n'était jamais restauré → /coach/me
     * répondait 401 et le login coach affichait à tort « compte sans
     * rôle مدرب ». La restauration doit aussi avoir lieu avant tout
     * determine_current_user, ce que garantit le chargement direct. */

    require_once RK_COACH_HUB_DIR . 'includes/class-rk-coach-auth-hardening.php';
    RK_Coach_Auth_Hardening::init();

    /* ── Hook session précoce (avant plugins_loaded) ──────────── */

    // phpcs:disable WordPress.Security.NonceVerification
    add_filter( 'determine_current_user', [ 'RK_Session_Manager', 'resolve_user_from_tab' ], 20 );
    if ( isset( $_GET['rk_tab'] ) && preg_match( '/^[a-f0-9]{40}$/', $_GET['rk_tab'] ) ) {
        if ( ! defined( 'DONOTCACHEPAGE' ) ) define( 'DONOTCACHEPAGE', true );
    }
    // phpcs:enable

    /* ── Boot unifié ──────────────────────────────────────────── */

    add_action( 'plugins_loaded', 'rkp_unified_boot', 10 );

    function rkp_unified_boot(): void {

        if ( ! class_exists( 'WooCommerce' ) ) {
            add_action( 'admin_notices', 'rk_mc_wc_missing_notice' );
            return;
        }
        if ( ! function_exists( 'tutor_utils' ) ) {
            add_action( 'admin_notices', 'rk_coach_hub_missing_tutor_notice' );
            return;
        }

        /* ── Children ─────────────────────────────────────────── */

        /* P1 (audit 19/08/2026) — Les migrations de schéma NE sont plus
         * exécutées ici. Elles tournaient à chaque requête front, soit des
         * dizaines de dbDelta()/SHOW COLUMNS par page vue. Elles sont
         * désormais centralisées dans rkp_run_schema_migrations(), appelée
         * une seule fois par version de schéma, en admin uniquement.
         * Voir rkp_maybe_run_schema_migrations() plus bas. */
        add_action( 'admin_init', 'rkp_maybe_run_schema_migrations', 1 );

        RK_Session_Manager::init();
        RK_Session_Guard::init();
        RK_MC_Child_User::init();
        RK_MC_Child_Restrictions::init();
        RK_MC_Tutor_Child::init();
        RK_MC_Endpoint::init();
        RK_MC_Rest::init();
        RK_MC_Quiz_Play_Ajax::init();       // v9.56 — Moteur de quiz natif
        RK_MC_Rapport_Ajax::init();          // v10.2 — filtre/pagination AJAX sur /rk-rapport/
        RK_MC_Assets::init();
        RK_MC_Child_Context::init();
        RK_MC_Course_Enrollment::init();
        RK_MC_Gamification_Service::init();

        $bust = static function( int $child_id ): void {
            RK_MC_Child_Dashboard_Data::clear_cache( $child_id );
            delete_transient( 'rk_dash_' . $child_id );
        };
        add_action( 'rkp_child_cache_invalidate', static function( int $child_wp_uid ) use ( $bust ): void {
            global $wpdb;
            $child_rk_id = (int) $wpdb->get_var( $wpdb->prepare(
                'SELECT id FROM ' . rk_mc_children_table() . ' WHERE wp_user_id = %d LIMIT 1',
                $child_wp_uid
            ) );
            if ( $child_rk_id > 0 ) $bust( $child_rk_id );
        } );
        add_action( 'rk_mc_child_points_added', fn( $cid ) => $bust( (int) $cid ) );
        add_action( 'rk_mc_badge_awarded',      fn( $cid ) => $bust( (int) $cid ) );
        add_action( 'rk_mc_child_level_up',     fn( $cid ) => $bust( (int) $cid ) );
        add_action( 'rk_mc_skill_updated',      fn( $cid ) => $bust( (int) $cid ) );
        add_action( 'rk_mc_mission_completed',  fn( $cid ) => $bust( (int) $cid ) );
        add_action( 'rk_mc_assessment_created', fn( $id, $cid ) => $bust( (int) $cid ), 10, 2 );
        add_action( 'rk_mc_bust_child_caches',  fn( $cid ) => $bust( (int) $cid ) );

        RK_MC_Message_Service::init();
        RK_MC_Notification_Service::init();
        RK_MC_Experience_Layer_Ajax::init(); // v9.9 — Child Experience Layer
        RK_MC_Guidance_Ajax::init(); // v9.9 — RKGuidance
        RK_MC_Badges_Ajax::init(); // v9.12 — mark_as_seen (شاراتي)
        RK_MC_Notification_Service::schedule_purge(); // idempotent — ne replanifie pas si déjà actif
        RK_MC_Adventures_Filter_Service::init();
        RK_MC_Tutor_Dashboard::init();

        /* v8.3.1 — Tutor LMS 4.x rend sa propre sidebar, son header et trois
         * cartes d'onboarding autour du contenu du dashboard. Sur la home
         * enfant, #rkd4 fournit déjà ce chrome : on retire les blocs Tutor
         * en amont plutôt que de les masquer uniquement en CSS.
         * Priorité 20 → après rk_coach_hub_redirect_coach_from_dashboard (5),
         * les coachs sont donc déjà redirigés vers /espace-coach/. */
        add_action( 'template_redirect', [ 'RK_MC_Tutor_Dashboard', 'strip_tutor4_chrome' ], 20 );

        RK_MC_Booking_Bridge::init();
        RK_Event_Bus::init();
        RK_PDF_Service::init();
        add_action( 'init', 'rk_mc_ensure_child_login_page', 1 );
        if ( is_admin() && class_exists( 'RK_MC_Admin' ) )      { RK_MC_Admin::init(); }
        if ( is_admin() && class_exists( 'RK_Admin_Students' ) ) { RK_Admin_Students::init(); }
        add_action( 'wp_enqueue_scripts', 'rk_mc_enqueue_dashboard_assets' );
        load_plugin_textdomain( 'rk-my-children', false, dirname( RK_MC_BASENAME ) . '/Modules/Children/languages' );

        /* ── Coach — chargement + boot ────────────────────────── */

        require_once RK_COACH_HUB_DIR . 'includes/class-rk-audit-log.php';
        require_once RK_COACH_HUB_DIR . 'includes/class-rk-availability-history.php';
        require_once RK_COACH_HUB_DIR . 'includes/class-rk-coach-course-service.php';
        require_once RK_COACH_HUB_DIR . 'includes/class-rk-coach-data.php';
        require_once RK_COACH_HUB_DIR . 'includes/class-rk-coach-students.php';
        require_once RK_COACH_HUB_DIR . 'includes/class-rk-coach-sessions.php';
        require_once RK_COACH_HUB_DIR . 'includes/class-rk-coach-evaluations.php';
        require_once RK_COACH_HUB_DIR . 'includes/class-rk-coach-messaging.php';
        require_once RK_COACH_HUB_DIR . 'includes/class-rk-coach-child-detail.php';
        require_once RK_COACH_HUB_DIR . 'includes/class-rk-coach-stats.php';
        require_once RK_COACH_HUB_DIR . 'includes/class-rk-coach-badges.php';
        require_once RK_COACH_HUB_DIR . 'includes/class-rk-coach-notifications.php';
        require_once RK_COACH_HUB_DIR . 'includes/class-rk-coach-missions.php';
        require_once RK_COACH_HUB_DIR . 'includes/class-rk-coach-auth.php';
        // v3.1.3 — class-rk-coach-auth-hardening.php est désormais chargée
        // et initialisée plus haut, hors de ce boot conditionnel.
        require_once RK_COACH_HUB_DIR . 'includes/class-rk-coach-dashboard.php';
        // v2.4 (Audit P3-14) — API coach découpée : helpers + 7 contrôleurs par domaine.
        require_once RK_COACH_HUB_DIR . 'includes/class-rk-coach-api-helpers.php';
        // v9.45 (13/08/2026) — 'qna' retiré de cette liste : le module
        // سؤال وجواب a été complètement supprimé (contrôleur, routes,
        // JS SPA — voir historique). Sans ce retrait, cette boucle
        // provoque une erreur fatale require_once (fichier introuvable)
        // au chargement du plugin, puisque class-rk-coach-qna-
        // controller.php n'existe plus sur le disque.
        foreach ( [ 'students', 'sessions', 'messages', 'quizzes', 'missions', 'profile', 'availability', 'badges' ] as $rkp_ctrl ) {
            require_once RK_COACH_HUB_DIR . "includes/controllers/class-rk-coach-{$rkp_ctrl}-controller.php";
        }
        unset( $rkp_ctrl );
        require_once RK_COACH_HUB_DIR . 'includes/class-rk-coach-api.php';

        /* Audit 19/08/2026 — 'rk_coach_mark_attendance' et 'rk_coach_save_notes'
         * étaient enregistrés ICI *et* dans RK_Coach_Dashboard::init().
         * WordPress dédoublonne les callbacks identiques (pas de double
         * exécution), mais deux points d'enregistrement pour un même hook
         * rendent le code trompeur à relire. Source unique retenue :
         * RK_Coach_Dashboard::init(), au plus près du module concerné. */
        add_action( 'admin_post_rk_coach_save_zoom_url',    [ 'RK_Coach_Sessions',     'handle_save_zoom_url' ] );
        add_action( 'admin_post_rk_coach_save_eval_inline',  [ 'RK_Coach_Child_Detail', 'handle_save_eval_inline' ] );
        add_action( 'admin_post_rk_coach_award_badge',       [ 'RK_Coach_Child_Detail', 'handle_award_badge' ] );
        add_action( 'admin_post_rk_coach_set_skills',        [ 'RK_Coach_Child_Detail', 'handle_set_skills' ] );
        add_action( 'admin_post_rk_coach_save_certificate',  [ 'RK_Coach_Child_Detail', 'handle_save_certificate' ] );
        add_action( 'admin_post_rk_coach_create_quiz',       [ 'RK_Coach_Child_Detail', 'handle_create_quiz' ] );
        add_action( 'admin_post_rk_coach_create_badge',      [ 'RK_Coach_Badges', 'handle_create_badge' ] );
        add_action( 'admin_post_rk_coach_update_badge',      [ 'RK_Coach_Badges', 'handle_update_badge' ] );
        add_action( 'admin_post_rk_coach_delete_badge',      [ 'RK_Coach_Badges', 'handle_delete_badge' ] );
        add_action( 'admin_post_rk_coach_revoke_badge',      [ 'RK_Coach_Badges', 'handle_revoke_badge' ] );

        add_action( 'init', 'rk_coach_hub_ensure_dashboard_page',    1 );
        add_action( 'init', 'rk_coach_hub_ensure_login_page',        1 );
        add_action( 'init', 'rk_coach_hub_ensure_espace_coach_page', 1 );
        RK_Coach_Data::init();
        RK_Coach_Evaluations::init();
        RK_Coach_Dashboard::init();
        RK_Coach_API::init();
        RK_Coach_Notifications::init();
        add_filter( 'template_include', 'rk_coach_hub_espace_coach_template', 99 );
        add_filter( 'template_include', 'rk_coach_hub_quiz_maker_template', 99 );
        add_action( 'template_redirect', 'rk_coach_hub_redirect_coach_from_dashboard', 5 );
        add_filter( 'tutor_dashboard/permalinks', [ 'RK_Coach_Dashboard', 'register_rk_permalinks' ] );
        add_action( 'init', 'rk_coach_hub_maybe_flush_rewrites', 99 );
        load_plugin_textdomain( 'rk-coach-hub', false, dirname( RK_COACH_HUB_BASENAME ) . '/Modules/Coach/languages' );

        /* ── Platform (bridges + cache listener) ──────────────── */

        RKP_TutorBridge::init();
        RKP_RKBridge::init();
        RKP_CacheInvalidationListener::subscribe();
        RKP_ProgressSyncListener::subscribe(); // v3.0 — Tutor → snapshots → 3 vues
        RKP_EventExperienceService::init(); // v9.9 — Child Experience Layer
        RKP_EventBus::boot();               // v2.1 — worker Action Scheduler (dispatch_async)
        RKP_JourneyQueryService::boot();    // v2.1 — invalidation du cache snapshot
        RKP_Container::boot();              // v2.2 — bindings des Ports (DI)
        RKP_ChildArchiveRepository::boot(); // v2.2 — purge quotidienne des archives
        RKP_NotificationRouter::boot();     // v2.8 — actions → notifications
        do_action( 'rkp_loaded' );
    }

    /**
     * Révoque le lien d'accès ?rk_tab= d'un enfant et retourne le nouveau.
     *
     * À utiliser si un lien a fuité (partagé par erreur, appareil perdu).
     * L'ancien token devient définitivement invalide ; le parent doit
     * rouvrir l'espace enfant depuis son tableau de bord pour obtenir la
     * nouvelle URL.
     *
     *   wp eval 'echo rkp_revoke_child_link( 324 );'
     *
     * @param  int    $child_wp_uid  WP user ID de l'enfant (pas l'ID rk_children).
     * @return string Nouveau token, ou '' si le manager est indisponible.
     */
    function rkp_revoke_child_link( int $child_wp_uid ): string {
        if ( ! class_exists( 'RK_Session_Manager' ) ) return '';
        return RK_Session_Manager::revoke_child_token( $child_wp_uid );
    }

    /* ════════════════════════════════════════════════════════════
     * MIGRATIONS DE SCHÉMA — exécution unique par version (P1)
     *
     * Avant : ~10 dbDelta() + 11 SHOW TABLES + 7 SHOW COLUMNS à CHAQUE
     * requête, front compris (hook plugins_loaded).
     * Après : le bloc ne tourne que si RKP_DB_SCHEMA_VERSION a changé,
     * et uniquement en contexte admin/CLI — un visiteur front ne
     * déclenche plus jamais de DDL.
     *
     * ⚠️  Toute nouvelle migration ajoutée ici DOIT s'accompagner d'un
     *     bump de RKP_DB_SCHEMA_VERSION en haut du fichier, sinon elle
     *     ne sera jamais jouée sur les sites déjà à jour.
     * ════════════════════════════════════════════════════════════ */

    function rkp_maybe_run_schema_migrations(): void {
        if ( get_option( 'rkp_db_version' ) === RKP_DB_SCHEMA_VERSION ) return;

        /* Verrou court : évite que deux requêtes admin simultanées lancent
         * les dbDelta() en parallèle (ALTER TABLE concurrents). */
        if ( get_transient( 'rkp_schema_migrating' ) ) return;
        set_transient( 'rkp_schema_migrating', 1, 5 * MINUTE_IN_SECONDS );

        try {
            rkp_run_schema_migrations();
            update_option( 'rkp_db_version', RKP_DB_SCHEMA_VERSION, false );
        } finally {
            delete_transient( 'rkp_schema_migrating' );
        }
    }

    /**
     * Toutes les migrations, dans l'ordre historique d'origine.
     * Chaque fonction reste individuellement idempotente (dbDelta, SHOW
     * COLUMNS…), donc un rejeu forcé est sans danger.
     */
    function rkp_run_schema_migrations(): void {
        /* ── Children ── */
        rk_mc_maybe_upgrade_table();
        rk_mc_upgrade_communication_tables();
        rk_mc_upgrade_v2_tables();
        rk_mc_backfill_appointment_dates();
        rk_mc_upgrade_bookings_coach_id();
        rk_mc_upgrade_bookings_meeting_url();

        /* ── Sessions enfant (6b-4a) ── */
        if ( class_exists( 'RKP_ChildSessionRepository' ) ) {
            RKP_ChildSessionRepository::maybe_create_table();
        }

        /* ── Mobile — refresh tokens (4.19.1) ──────────────────────
         * Absente jusqu'ici : la table wp_rk_refresh_tokens n'était
         * créée par aucune migration (audit 16/09/2026 §3 — bootstrap
         * item 3). Sans elle, RKP_RefreshTokenRepository::is_available()
         * retourne false et tout login/refresh mobile échoue proprement
         * (fail-closed), mais aucun token ne peut jamais être émis. */
        if ( class_exists( 'RKP_RefreshTokenRepository' ) ) {
            RKP_RefreshTokenRepository::maybe_create_table();
        }

        /* ── Coach ── (classes chargées par rkp_unified_boot) */
        if ( class_exists( 'RK_Audit_Log' ) )           RK_Audit_Log::maybe_create_table();
        if ( class_exists( 'RK_Availability_History' ) ) RK_Availability_History::maybe_create_table();
        if ( class_exists( 'RK_Coach_Missions' ) )      RK_Coach_Missions::maybe_create_table();
        if ( function_exists( 'rk_coach_hub_upgrade_db' ) )             rk_coach_hub_upgrade_db();
        if ( function_exists( 'rk_coach_hub_fix_quiz_maker_options' ) ) rk_coach_hub_fix_quiz_maker_options();
    }

    /**
     * Relance forcée des migrations — utile après une restauration de dump
     * ou un rollback de version.  Usage : wp eval 'rkp_force_schema_migrations();'
     */
    function rkp_force_schema_migrations(): void {
        delete_option( 'rkp_db_version' );
        delete_transient( 'rkp_schema_migrating' );
        rkp_maybe_run_schema_migrations();
    }

    /* ── Fonctions helpers (module Children) ──────────────────── */

    function rk_mc_ensure_child_login_page(): void {
        $cached = (int) get_option( 'rk_mc_child_login_page_id' );
        if ( $cached && get_post_status( $cached ) === 'publish' ) return;
        $existing = get_page_by_path( 'connexion-child' );
        if ( $existing && $existing->post_status === 'publish' ) {
            update_option( 'rk_mc_child_login_page_id', $existing->ID );
            return;
        }
        $page_id = wp_insert_post( [
            'post_title'     => 'فضاء الأطفال',
            'post_name'      => 'connexion-child',
            'post_status'    => 'publish',
            'post_type'      => 'page',
            'post_content'   => '',
            'comment_status' => 'closed',
            'ping_status'    => 'closed',
        ] );
        if ( ! is_wp_error( $page_id ) && $page_id ) {
            update_option( 'rk_mc_child_login_page_id', (int) $page_id );
        }
    }

    function rk_mc_activate(): void {
        rk_mc_maybe_upgrade_table();
        rk_mc_upgrade_gamification_tables();
        rk_mc_upgrade_communication_tables();
        RK_MC_Child_User::maybe_register_role();
        if ( class_exists( 'RK_MC_Endpoint' ) ) RK_MC_Endpoint::add_endpoint();
        rk_mc_ensure_child_login_page();
        flush_rewrite_rules();
    }

    function rk_mc_enqueue_dashboard_assets(): void {
        if ( ! is_account_page() ) return;
        if ( is_wc_endpoint_url( RK_MC_Endpoint::SLUG ) ) return;
        if ( is_wc_endpoint_url( RK_MC_Endpoint::CHILD_DASHBOARD_SLUG ) ) return;
        if ( is_wc_endpoint_url() ) return;
        RK_MC_Child_Card_Renderer::enqueue_assets();
    }

    function rk_mc_wc_missing_notice(): void {
        echo '<div class="notice notice-error"><p>' . esc_html__( 'RiadaKids Platform requires WooCommerce to be active.', 'rk-platform' ) . '</p></div>';
    }

    /* ── Fonctions helpers (module Coach) ─────────────────────── */

    function rk_coach_hub_ensure_dashboard_page(): void {
        $cached = (int) get_option( 'rk_coach_hub_dashboard_page_id' );
        if ( $cached && get_post_status( $cached ) === 'publish' ) {
            rk_coach_hub_sync_tutor_option( $cached );
            return;
        }
        $existing = get_page_by_path( 'dashboard' );
        if ( $existing && $existing->post_status === 'publish' ) {
            $page_id = $existing->ID;
        } else {
            $page_id = wp_insert_post( [
                'post_title'     => 'Dashboard',
                'post_name'      => 'dashboard',
                'post_status'    => 'publish',
                'post_type'      => 'page',
                'post_content'   => '[tutor_dashboard]',
                'comment_status' => 'closed',
                'ping_status'    => 'closed',
            ] );
            if ( is_wp_error( $page_id ) || ! $page_id ) return;
            $page_id = (int) $page_id;
        }
        update_option( 'rk_coach_hub_dashboard_page_id', $page_id );
        rk_coach_hub_sync_tutor_option( $page_id );
    }

    function rk_coach_hub_sync_tutor_option( int $page_id ): void {
        $opts = get_option( 'tutor_option', [] );
        if ( ! is_array( $opts ) ) $opts = [];
        if ( (int) ( $opts['tutor_dashboard_page_id'] ?? 0 ) !== $page_id ) {
            $opts['tutor_dashboard_page_id'] = $page_id;
            update_option( 'tutor_option', $opts );
        }
    }

    function rk_coach_hub_ensure_login_page(): void {
        $cached = (int) get_option( 'rk_coach_hub_login_page_id' );
        if ( $cached && get_post_status( $cached ) === 'publish' ) return;
        $existing = get_page_by_path( 'connexion-coach' );
        if ( $existing && $existing->post_status === 'publish' ) {
            update_option( 'rk_coach_hub_login_page_id', $existing->ID );
            return;
        }
        $page_id = wp_insert_post( [
            'post_title'     => 'Connexion Coach',
            'post_name'      => 'connexion-coach',
            'post_status'    => 'publish',
            'post_type'      => 'page',
            'post_content'   => '',
            'comment_status' => 'closed',
            'ping_status'    => 'closed',
        ] );
        if ( ! is_wp_error( $page_id ) && $page_id ) {
            update_option( 'rk_coach_hub_login_page_id', (int) $page_id );
        }
    }

    function rk_coach_hub_ensure_espace_coach_page(): void {
        $cached = (int) get_option( 'rk_coach_hub_espace_coach_page_id' );
        if ( $cached && get_post_status( $cached ) === 'publish' ) return;
        $existing = get_page_by_path( 'espace-coach' );
        if ( $existing && $existing->post_status === 'publish' ) {
            update_option( 'rk_coach_hub_espace_coach_page_id', $existing->ID );
            return;
        }
        $page_id = wp_insert_post( [
            'post_title'     => 'Espace Coach',
            'post_name'      => 'espace-coach',
            'post_status'    => 'publish',
            'post_type'      => 'page',
            'post_content'   => '',
            'comment_status' => 'closed',
            'ping_status'    => 'closed',
        ] );
        if ( ! is_wp_error( $page_id ) && $page_id ) {
            update_option( 'rk_coach_hub_espace_coach_page_id', (int) $page_id );
        }
    }

    function rk_coach_hub_activate(): void {
        rk_coach_hub_ensure_dashboard_page();
        rk_coach_hub_ensure_login_page();
        rk_coach_hub_ensure_espace_coach_page();
        flush_rewrite_rules();
    }

    function rk_coach_hub_upgrade_db(): void {
        global $wpdb;
        $bt   = $wpdb->prefix . 'rk_bookings';
        $cols = $wpdb->get_col( "SHOW COLUMNS FROM `{$bt}`" );
        if ( is_array( $cols ) && ! in_array( 'attendance', $cols, true ) ) {
            $wpdb->query( "ALTER TABLE `{$bt}` ADD COLUMN attendance VARCHAR(20) NOT NULL DEFAULT 'none'" );
        }
        if ( is_array( $cols ) && ! in_array( 'meeting_url', $cols, true ) ) {
            $wpdb->query( "ALTER TABLE `{$bt}` ADD COLUMN meeting_url VARCHAR(2048) NOT NULL DEFAULT ''" );
        }
        $at = $wpdb->prefix . 'rk_child_assessments';
        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$at}'" ) === $at ) {
            $acols = $wpdb->get_col( "SHOW COLUMNS FROM `{$at}`" );
            if ( is_array( $acols ) && ! in_array( 'overall_rating', $acols, true ) ) {
                $wpdb->query( "ALTER TABLE `{$at}` ADD COLUMN overall_rating DECIMAL(3,1) NULL DEFAULT NULL" );
            }
        }
        $cbt = $wpdb->prefix . 'rk_custom_badges';
        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$cbt}'" ) !== $cbt ) {
            $charset_collate = $wpdb->get_charset_collate();
            $wpdb->query(
                "CREATE TABLE `{$cbt}` (
                    id         BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                    badge_key  VARCHAR(80)  NOT NULL,
                    name       VARCHAR(255) NOT NULL,
                    icon_key   VARCHAR(64)  NOT NULL DEFAULT 'star',
                    cat        VARCHAR(32)  NOT NULL DEFAULT 'skill',
                    `desc`     TEXT         NOT NULL,
                    coach_id   BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
                    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    UNIQUE KEY badge_key (badge_key)
                ) {$charset_collate}"
            );
        }

        // v9.12 — Module "شاراتي" (page Child rk-badges) : migration 1/2.
        // Ajoute seen_at à wp_rk_child_badges (source de vérité unique des
        // badges obtenus) — permet de distinguer "جديدة" (earned mais pas
        // encore vu par l'enfant) de "مكتسبة" (déjà vu). Idempotente :
        // SHOW COLUMNS avant ALTER, comme le reste de cette fonction.
        // Backfill demandé : seen_at = earned_at pour les lignes EXISTANTES
        // uniquement (WHERE seen_at IS NULL, donc sans risque de réécraser
        // un futur seen_at déjà posé par un rechargement ultérieur de ce
        // même hook) — évite qu'un badge déjà obtenu avant cette migration
        // apparaisse artificiellement "جديدة" au premier chargement.
        $cbadges_t = $wpdb->prefix . 'rk_child_badges';
        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$cbadges_t}'" ) === $cbadges_t ) {
            $cbadges_cols = $wpdb->get_col( "SHOW COLUMNS FROM `{$cbadges_t}`" );
            if ( is_array( $cbadges_cols ) && ! in_array( 'seen_at', $cbadges_cols, true ) ) {
                $wpdb->query( "ALTER TABLE `{$cbadges_t}` ADD COLUMN seen_at DATETIME NULL DEFAULT NULL" );
                // phpcs:ignore WordPress.DB.PreparedSQL -- table identifier only, littéral fixe.
                $wpdb->query( "UPDATE `{$cbadges_t}` SET seen_at = earned_at WHERE seen_at IS NULL" );
            }
        }

        // v9.12 — Module "شاراتي" : migration 2/2. Ajoute badge_type à
        // wp_rk_custom_badges — dimension INDÉPENDANTE de `cat` (décision
        // explicite de l'utilisateur : pas de déduction automatique depuis
        // cat). Seules deux valeurs autorisées : 'achievement' | 'adventure'.
        // PAS de backfill automatique ici — voir mapping explicite fourni
        // séparément dans le rapport ci-dessous, en attente de validation
        // avant toute UPDATE sur les lignes existantes.
        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$cbt}'" ) === $cbt ) {
            $cbt_cols = $wpdb->get_col( "SHOW COLUMNS FROM `{$cbt}`" );
            if ( is_array( $cbt_cols ) && ! in_array( 'badge_type', $cbt_cols, true ) ) {
                $wpdb->query( "ALTER TABLE `{$cbt}` ADD COLUMN badge_type VARCHAR(20) NULL DEFAULT NULL" );
            }
        }

        // v9.20 — Migration des 22 badges SYSTÈME (auparavant codés en
        // dur dans RKP_BadgeQueryService::catalogue()) vers
        // wp_rk_custom_badges, pour les rendre modifiables/supprimables
        // depuis la page coach — décision explicite de l'utilisateur.
        //
        // is_system=1 les distingue des vrais badges "custom" créés par
        // un coach : coach_id=0 (aucun coach propriétaire unique), et
        // TOUT coach authentifié peut les éditer (contrairement aux
        // badges custom classiques, réservés à leur créateur — voir
        // RK_Coach_Badges_Controller::coach_owns_badge()).
        //
        // Idempotente et à sens unique : n'insère que les badge_key
        // ABSENTS de la table (WHERE NOT EXISTS via la contrainte
        // UNIQUE(badge_key) + INSERT IGNORE) — n'écrase jamais un badge
        // déjà migré ou déjà édité par un coach. Les badge_key sont
        // préservés À L'IDENTIQUE (first_session, level_2, etc.) pour
        // que les attributions déjà existantes dans wp_rk_child_badges
        // continuent de pointer vers le même catalogue sans rupture.
        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$cbt}'" ) === $cbt ) {
            $cbt_cols = $wpdb->get_col( "SHOW COLUMNS FROM `{$cbt}`" );
            if ( is_array( $cbt_cols ) && ! in_array( 'is_system', $cbt_cols, true ) ) {
                $wpdb->query( "ALTER TABLE `{$cbt}` ADD COLUMN is_system TINYINT(1) NOT NULL DEFAULT 0" );
            }

            if ( ! get_option( 'rkp_system_badges_migrated_v1' ) ) {
                $system_badges = [
                    'first_session'   => [ 'icon_key'=>'star',            'name'=>'أول خطوة',        'cat'=>'start',   'badge_type'=>'achievement', 'desc'=>'حضرت أول لقاء لك!'                  ],
                    'first_lesson'    => [ 'icon_key'=>'graduation-cap',  'name'=>'أول مغامرة',       'cat'=>'start',   'badge_type'=>'achievement', 'desc'=>'سجل في أول مغامرة'                  ],
                    'first_quiz'      => [ 'icon_key'=>'nav-target',      'name'=>'أول اختبار',       'cat'=>'start',   'badge_type'=>'achievement', 'desc'=>'أكمل أول اختبار لك'                 ],
                    'first_meeting'   => [ 'icon_key'=>'users',           'name'=>'أول لقاء',         'cat'=>'start',   'badge_type'=>'achievement', 'desc'=>'احضر أول لقاء مباشر'                ],
                    'streak_week'     => [ 'icon_key'=>'fire',            'name'=>'أسبوع متواصل',     'cat'=>'streak',  'badge_type'=>'achievement', 'desc'=>'احضر جلساتك أسبوعا كاملا'           ],
                    'perfect_month'   => [ 'icon_key'=>'diamond',         'name'=>'شهر مثالي',        'cat'=>'streak',  'badge_type'=>'achievement', 'desc'=>'احضر كل جلسات الشهر'                ],
                    'unstoppable'     => [ 'icon_key'=>'lightning',       'name'=>'لا يتوقف',         'cat'=>'streak',  'badge_type'=>'achievement', 'desc'=>'لا تفوت أي جلسة لفترة طويلة'        ],
                    'moon_regular'    => [ 'icon_key'=>'moon',            'name'=>'منتظم كالقمر',     'cat'=>'streak',  'badge_type'=>'achievement', 'desc'=>'احضر جلساتك بانتظام'                ],
                    'course_complete' => [ 'icon_key'=>'graduation-cap',  'name'=>'مغامرة مكتملة',    'cat'=>'mastery', 'badge_type'=>'achievement', 'desc'=>'أكمل مغامرة كاملة'                  ],
                    'quiz_perfect'    => [ 'icon_key'=>'trophy',          'name'=>'اختبار مثالي',     'cat'=>'mastery', 'badge_type'=>'achievement', 'desc'=>'احصل على العلامة الكاملة في اختبار' ],
                    'genius'          => [ 'icon_key'=>'lightning',       'name'=>'عبقري',            'cat'=>'mastery', 'badge_type'=>'achievement', 'desc'=>'أظهر تميزا في أحد اختباراتك'        ],
                    'precise'         => [ 'icon_key'=>'nav-target',      'name'=>'دقيق',             'cat'=>'mastery', 'badge_type'=>'achievement', 'desc'=>'أكملت مهامك دون أي أخطاء'           ],
                    'leader'          => [ 'icon_key'=>'rocket',          'name'=>'القائد الجريء',    'cat'=>'skill',   'badge_type'=>'achievement', 'desc'=>'شارة خاصة من المدرب'                ],
                    'speaker'         => [ 'icon_key'=>'skill-speech',    'name'=>'الصوت المسموع',    'cat'=>'skill',   'badge_type'=>'achievement', 'desc'=>'شارة خاصة من المدرب'                ],
                    'teamwork'        => [ 'icon_key'=>'users',           'name'=>'روح الفريق',       'cat'=>'skill',   'badge_type'=>'achievement', 'desc'=>'شارة خاصة تنتظرك'                   ],
                    'creative'        => [ 'icon_key'=>'skill-creativity','name'=>'المبدع الحقيقي',   'cat'=>'skill',   'badge_type'=>'achievement', 'desc'=>'شارة خاصة تنتظرك'                   ],
                    'anniversary'     => [ 'icon_key'=>'gift',            'name'=>'عيد ميلادنا معاً', 'cat'=>'special', 'badge_type'=>'achievement', 'desc'=>'شارة خاصة تنتظرك'                   ],
                    'star_of_month'   => [ 'icon_key'=>'star',            'name'=>'نجم الشهر',        'cat'=>'special', 'badge_type'=>'achievement', 'desc'=>'شارة خاصة تنتظرك'                   ],
                    'first_hero'      => [ 'icon_key'=>'award',           'name'=>'البطل الأول',      'cat'=>'special', 'badge_type'=>'achievement', 'desc'=>'شارة خاصة ونادرة'                   ],
                    'level_2'         => [ 'icon_key'=>'nav-compass',     'name'=>'مستكشف',           'cat'=>'level',   'badge_type'=>'achievement', 'desc'=>'ابدأ رحلتك الأولى'                  ],
                    'level_3'         => [ 'icon_key'=>'book-open',       'name'=>'مكتشف',            'cat'=>'level',   'badge_type'=>'achievement', 'desc'=>'تقدم في مغامراتك'                   ],
                    'level_4'         => [ 'icon_key'=>'lightning',       'name'=>'مبتكر',            'cat'=>'level',   'badge_type'=>'achievement', 'desc'=>'أكمل عدة مغامرات'                   ],
                    'level_5'         => [ 'icon_key'=>'star',            'name'=>'بانٍ',             'cat'=>'level',   'badge_type'=>'achievement', 'desc'=>'واصل التقدم بثبات'                  ],
                    'level_6'         => [ 'icon_key'=>'rocket',          'name'=>'رائد',             'cat'=>'level',   'badge_type'=>'achievement', 'desc'=>'كنت قدوة في مغامراتك'               ],
                    'level_7'         => [ 'icon_key'=>'trophy',          'name'=>'بطل',              'cat'=>'level',   'badge_type'=>'achievement', 'desc'=>'أظهر التزاماً استثنائياً'           ],
                    'level_8'         => [ 'icon_key'=>'diamond',         'name'=>'أسطورة',           'cat'=>'level',   'badge_type'=>'achievement', 'desc'=>'أصل إلى أعلى المستويات'             ],
                ];

                foreach ( $system_badges as $badge_key => $def ) {
                    // phpcs:ignore WordPress.DB.PreparedSQL -- table identifier fixe, valeurs via placeholders.
                    $wpdb->query( $wpdb->prepare(
                        "INSERT IGNORE INTO `{$cbt}` (badge_key, name, icon_key, cat, `desc`, badge_type, coach_id, is_system, created_at)
                         VALUES (%s, %s, %s, %s, %s, %s, 0, 1, %s)",
                        $badge_key, $def['name'], $def['icon_key'], $def['cat'], $def['desc'], $def['badge_type'], current_time( 'mysql' )
                    ) );
                }

                update_option( 'rkp_system_badges_migrated_v1', 1, false );
                RKP_CustomBadgeRepository::refresh_table_cache();
            }
        }
    }

    function rk_coach_hub_fix_quiz_maker_options(): void {
        $flag = 'rk_qm_options_fixed_v1';
        if ( get_option( $flag ) ) return;
        global $wpdb;
        $table = $wpdb->prefix . 'aysquiz_quizes';
        if ( ! $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) ) return;
        $required_defaults = [ 'form_name' => 'off', 'form_email' => 'off', 'form_phone' => 'off', 'custom_css' => '' ];
        $rows = $wpdb->get_results( "SELECT id, options FROM {$table} WHERE options IS NOT NULL AND options != ''" );
        foreach ( (array) $rows as $row ) {
            $opts    = json_decode( (string) $row->options, true );
            if ( ! is_array( $opts ) ) $opts = [];
            $changed = false;
            foreach ( $required_defaults as $key => $default ) {
                if ( ! array_key_exists( $key, $opts ) ) { $opts[ $key ] = $default; $changed = true; }
            }
            if ( $changed ) {
                $wpdb->update( $table, [ 'options' => wp_json_encode( $opts ) ], [ 'id' => (int) $row->id ], [ '%s' ], [ '%d' ] );
            }
        }
        update_option( $flag, true );
    }

    function rk_coach_hub_maybe_flush_rewrites(): void {
        if ( get_option( 'rk_coach_hub_rewrite_flushed' ) === RK_COACH_HUB_VERSION ) return;
        flush_rewrite_rules( false );
        update_option( 'rk_coach_hub_rewrite_flushed', RK_COACH_HUB_VERSION );
    }

    function rk_coach_hub_espace_coach_template( string $template ): string {
        if ( ! is_page( 'espace-coach' ) ) return $template;
        $tpl = RK_COACH_HUB_DIR . 'templates/page-espace-coach.php';
        return file_exists( $tpl ) ? $tpl : $template;
    }

    function rk_coach_hub_quiz_maker_template( string $template ): string {
        if ( ! is_singular( 'ays-quiz-maker' ) ) return $template;
        $tpl = RK_COACH_HUB_DIR . 'templates/single-ays-quiz-maker.php';
        return file_exists( $tpl ) ? $tpl : $template;
    }

    function rk_coach_hub_redirect_coach_from_dashboard(): void {
        if ( ! is_user_logged_in() ) return;
        if ( ! class_exists( 'RK_Coach_Dashboard' ) ) return;
        if ( ! RK_Coach_Dashboard::is_coach_user( wp_get_current_user() ) ) return;
        $uri = trailingslashit( (string) parse_url( $_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH ) );
        if ( strpos( $uri, '/dashboard/' ) === 0 ) { wp_safe_redirect( home_url( '/espace-coach/' ) ); exit; }
        if ( strpos( $uri, '/my-account/' ) === 0 ) { wp_safe_redirect( home_url( '/espace-coach/' ) ); exit; }
    }

    function rk_coach_hub_missing_dep_notice(): void {}
    function rk_coach_hub_missing_tutor_notice(): void {
        echo '<div class="notice notice-error"><p><strong>RiadaKids Platform</strong> nécessite que <strong>Tutor LMS</strong> soit installé et activé.</p></div>';
    }

    /* ── Activation platform (mode unifié) ────────────────────── */

    function rkp_platform_activate(): void {
        rk_mc_activate();
        rk_coach_hub_activate();
        // Le schéma est (re)construit intégralement à l'activation, quelle que
        // soit la valeur de 'rkp_db_version' déjà en base.
        rkp_force_schema_migrations();
        if ( class_exists( 'RK_MC_Notification_Service' ) ) {
            RK_MC_Notification_Service::schedule_purge();
        }
        rkp_platform_activate_core();
    }

    function rkp_platform_deactivate(): void {
        if ( class_exists( 'RK_Coach_Notifications' ) ) RK_Coach_Notifications::deactivate();
        if ( class_exists( 'RK_MC_Notification_Service' ) ) {
            RK_MC_Notification_Service::unschedule_purge();
        }
        flush_rewrite_rules();
    }

} else {

    /* ════════════════════════════════════════════════════════════
     * MODE TRANSITION — anciens plugins encore actifs.
     * rk-platform se contente d'être l'infrastructure DDD.
     * ════════════════════════════════════════════════════════════ */

    add_action( 'plugins_loaded', 'rkp_boot', 30 );

    function rkp_boot(): void {
        RKP_TutorBridge::init();
        RKP_RKBridge::init();
        RKP_CacheInvalidationListener::subscribe();
        RKP_ProgressSyncListener::subscribe(); // v3.0 — Tutor → snapshots → 3 vues
        RKP_EventExperienceService::init(); // v9.9 — Child Experience Layer
        RKP_EventBus::boot();               // v2.1
        RKP_JourneyQueryService::boot();    // v2.1
        RKP_Container::boot();              // v2.2
        RKP_ChildArchiveRepository::boot(); // v2.2
        RKP_NotificationRouter::boot();     // v2.8
        do_action( 'rkp_loaded' );
    }

    function rkp_platform_activate(): void { rkp_platform_activate_core(); }
    function rkp_platform_deactivate(): void { flush_rewrite_rules(); }
}

/* ════════════════════════════════════════════════════════════════
 * ACTIVATION CORE v2.1 (commune aux deux modes)
 * ════════════════════════════════════════════════════════════════ */

function rkp_platform_activate_core(): void {
    // Idempotence des Domain Events (anti double-XP / double-badge).
    RKP_EventLog::install();

    // v9.9 — Child Experience Layer : suivi "vu" des popups/toasts événementiels.
    if ( class_exists( 'RKP_EventExperienceLog' ) ) {
        RKP_EventExperienceLog::install();
    }

    // Re-détection des tables tierces (mémorisée en option, plus de SHOW TABLES à chaud).
    RKP_CustomBadgeRepository::refresh_table_cache();
    RKP_MessageRepository::refresh_table_cache();
    RKP_AysQuizRepository::refresh_table_cache(); // v2.6

    // P0-1 : chiffre immédiatement tout PIN enfant encore stocké en clair.
    if ( class_exists( 'RK_MC_Pin_Security' ) ) {
        RK_MC_Pin_Security::migrate_all();
    }

    // v2.8 — store de notifications du plugin (cloches enfant + coach).
    if ( class_exists( 'RK_MC_Notification_Service' ) ) {
        RK_MC_Notification_Service::install();
    }

    // v2.2 — Wallet ledger + archive enfants (P2-17 / P3-19).
    RKP_WalletLedgerRepository::install();
    RKP_WalletLedgerRepository::import_legacy_balances(); // idempotent (clé stable)
    RKP_ChildArchiveRepository::install();

    // v3.0 — snapshots de progression + réconciliation coach↔enfant (une fois)
    RKP_ProgressSnapshotRepository::install();
    if ( get_option( 'rkp_family_backfill_done' ) !== '1' ) {
        RKP_FamilyLinkCommandService::backfill_from_bookings();
        update_option( 'rkp_family_backfill_done', '1', false );
    }
}

} // fin de la garde anti-double-chargement (if ( ! defined( 'RKP_VERSION' ) ))