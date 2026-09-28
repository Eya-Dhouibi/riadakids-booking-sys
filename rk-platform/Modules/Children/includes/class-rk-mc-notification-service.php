<?php
declare( strict_types=1 );
/**
 * RK_MC_Notification_Service
 *
 * Délègue toutes les notifications à riada_notify_user() (snippet actif).
 * Conservée pour compatibilité : tous les appelants (booking_bridge,
 * assessment_service, gamification…) continuent à fonctionner sans modification.
 *
 * @package RK_My_Children
 * @since   8.3.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class RK_MC_Notification_Service {

    const CRON_HOOK = 'rk_mc_monthly_notif_purge';

    /* ─── AJAX + Cron hooks ───────────────────────────────────────── */

    /* ═══════════════════════════════════════════════════════════════
       v2.8 — STORE PROPRE wp_rk_notifications (indépendant du thème)
       AVANT : push() dépendait de riada_notify_user() (snippet du thème) —
       si la fonction manquait, TOUTES les notifications échouaient en
       silence, et la cloche coach lisait une table sans installeur.
       APRÈS : un seul store fiable, installé par le plugin ; le snippet du
       thème reste alimenté en miroir (compat descendante).
       ═══════════════════════════════════════════════════════════════ */

    private static function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'rk_notifications';
    }

    /** Crée/complète wp_rk_notifications (idempotent — dbDelta). */
    public static function install(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $cc = $wpdb->get_charset_collate();
        dbDelta( 'CREATE TABLE ' . self::table() . " (
            id         BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id    BIGINT(20) UNSIGNED NOT NULL,
            type       VARCHAR(40)         NOT NULL DEFAULT '',
            title      VARCHAR(120)        NOT NULL DEFAULT '',
            body       TEXT                NULL,
            link       VARCHAR(255)        NOT NULL DEFAULT '',
            is_read    TINYINT(1)          NOT NULL DEFAULT 0,
            created_at DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_user_read (user_id, is_read),
            KEY idx_created (created_at)
        ) {$cc};" );
        update_option( 'rk_notifications_installed', RK_MC_VERSION, false );
    }

    /** Auto-guérison : installe la table au premier besoin si absente. */
    private static function ensure_table(): void {
        if ( get_option( 'rk_notifications_installed' ) ) return;
        self::install();
    }

    public static function init(): void {
        add_action( 'wp_ajax_rk_mc_get_notifications', [ __CLASS__, 'ajax_get'       ] );
        add_action( 'wp_ajax_rk_mc_mark_notifs_read',  [ __CLASS__, 'ajax_mark_read' ] );
        add_action( self::CRON_HOOK,                   [ __CLASS__, 'purge_all'      ] );
        add_filter( 'cron_schedules',                  [ __CLASS__, 'add_monthly_schedule' ] );
    }

    /* ─── Cron schedule ───────────────────────────────────────────── */

    public static function add_monthly_schedule( array $schedules ): array {
        if ( ! isset( $schedules['monthly'] ) ) {
            $schedules['monthly'] = [
                'interval' => 30 * DAY_IN_SECONDS,
                'display'  => 'Once Monthly',
            ];
        }
        return $schedules;
    }

    /** Planifie la purge mensuelle (appeler à l'activation du plugin). */
    public static function schedule_purge(): void {
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            // Premier déclenchement : 1er jour du mois prochain à minuit
            $next = mktime( 0, 0, 0, (int) date( 'n' ) + 1, 1 );
            wp_schedule_event( $next, 'monthly', self::CRON_HOOK );
        }
    }

    /** Supprime le cron planifié (appeler à la désactivation du plugin). */
    public static function unschedule_purge(): void {
        $ts = wp_next_scheduled( self::CRON_HOOK );
        if ( $ts ) {
            wp_unschedule_event( $ts, self::CRON_HOOK );
        }
    }

    /**
     * Vide les deux tables de notifications.
     * Appelé automatiquement chaque mois par WP-Cron.
     * Peut aussi être déclenché manuellement : RK_MC_Notification_Service::purge_all()
     */
    public static function purge_all(): void {
        global $wpdb;

        // Table principale (snippet riada_notify_user)
        $main = $wpdb->prefix . 'user_notifications';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $main ) ) === $main ) {
            $wpdb->query( "TRUNCATE TABLE `{$main}`" );
        }

        // Table secondaire coach (optionnelle)
        $coach = $wpdb->prefix . 'rk_notifications';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $coach ) ) === $coach ) {
            $wpdb->query( "TRUNCATE TABLE `{$coach}`" );
        }

        do_action( 'rk_mc_notifications_purged' );
    }

    /**
     * Resout l'identite destinataire des notifications (phase 6b-3).
     *
     * Contrat AJAX :
     *     context = 'child'  + nonce rk_mc_notifs_child   -> child_wp_uid()
     *     context = 'parent' + nonce rk_mc_notifs_parent  -> authenticated_parent_id()
     *
     * Le nonce est DEDIE au contexte : un client qui reclamerait
     * context=child depuis une page parent presenterait le nonce parent et
     * echouerait ici avec un 403. Le contexte seul n'est jamais cru.
     *
     * Ni Referer, ni get_current_user_id() ne servent a deduire le contexte.
     *
     * @return int wp_user_id destinataire. Envoie une reponse d'erreur et
     *             termine la requete si la verification echoue.
     */
    private static function resolve_notif_recipient(): int {
        // phpcs:ignore WordPress.Security.NonceVerification -- le nonce est verifie juste apres, en fonction de ce contexte
        $context = isset( $_POST['context'] ) ? sanitize_key( wp_unslash( $_POST['context'] ) ) : '';

        if ( ! in_array( $context, array( 'child', 'parent' ), true ) ) {
            wp_send_json_error( 'invalid_context', 400 );
        }

        // Nonce dedie : c'est LUI qui prouve la surface d'emission.
        check_ajax_referer( 'rk_mc_notifs_' . $context, 'nonce' );

        if ( ! class_exists( 'RK_Identity_Context' ) ) {
            wp_send_json_error( 'identity_unavailable', 503 );
        }

        if ( 'parent' === $context ) {
            $uid = RK_Identity_Context::authenticated_parent_id();
            if ( $uid <= 0 ) wp_send_json_error( 'unauthenticated', 403 );
            return $uid;
        }

        /* context === 'child' — AUCUN repli sur le parent. Sans contexte
         * enfant valide, la reponse est vide, comme lorsque l'enfant n'a
         * aucune notification. */
        return RK_Identity_Context::child_wp_uid();
    }

    public static function ajax_get(): void {
        $user_id = self::resolve_notif_recipient();
        if ( $user_id <= 0 ) wp_send_json_success( array() );   // pas de repli parent

        wp_send_json_success( self::get_list_for_user( $user_id ) );
    }

    public static function ajax_mark_read(): void {
        $user_id = self::resolve_notif_recipient();
        if ( $user_id <= 0 ) wp_send_json_success();            // pas de repli parent

        self::mark_read_for_user( $user_id );
        wp_send_json_success();
    }

    /**
     * Liste des notifications d'un user_id, quel que soit l'appelant
     * (AJAX web via ajax_get(), REST mobile via RK_Notifications_REST).
     * Même requête, même repli sur le store legacy du snippet thème — un
     * seul chemin de lecture pour les deux surfaces.
     *
     * @since 4.21.0 Extrait de ajax_get() pour être réutilisable par
     *        RK_Notifications_REST (app mobile — même cloche que le web).
     */
    public static function get_list_for_user( int $user_id, int $limit = 15 ): array {
        if ( $user_id <= 0 ) return [];

        self::ensure_table();
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, title, body AS message, link, type, is_read,
                    DATE_FORMAT(created_at, '%%d/%%m %%H:%%i') AS date_fmt
               FROM " . self::table() . "
              WHERE user_id = %d
              ORDER BY created_at DESC LIMIT %d",
            $user_id,
            $limit
        ) ) ?: [];

        // Transition douce : historique du snippet thème si notre store est vide.
        if ( ! $rows ) {
            $legacy = $wpdb->prefix . 'user_notifications';
            if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $legacy ) ) === $legacy ) {
                $rows = $wpdb->get_results( $wpdb->prepare(
                    "SELECT id, title, message, link, type, is_read,
                            DATE_FORMAT(created_at, '%%d/%%m %%H:%%i') AS date_fmt
                       FROM {$legacy} WHERE user_id = %d
                      ORDER BY created_at DESC LIMIT %d",
                    $user_id,
                    $limit
                ) ) ?: [];
            }
        }

        return $rows;
    }

    /**
     * Marque tout comme lu pour un user_id — même effet que ajax_mark_read()
     * mais appelable directement par un contrôleur REST (identité déjà
     * résolue/vérifiée par l'appelant, pas de nonce ici).
     *
     * @since 4.21.0
     */
    public static function mark_read_for_user( int $user_id ): void {
        if ( $user_id <= 0 ) return;

        self::ensure_table();
        global $wpdb;
        $wpdb->update( self::table(), [ 'is_read' => 1 ], [ 'user_id' => $user_id, 'is_read' => 0 ] );

        $legacy = $wpdb->prefix . 'user_notifications';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $legacy ) ) === $legacy ) {
            $wpdb->update( $legacy, [ 'is_read' => 1 ], [ 'user_id' => $user_id, 'is_read' => 0 ] );
        }
    }

    /** Titres arabes par type — affichés dans la cloche header. */
    private static array $title_map = [
        'booking_confirmed'   => '✅ تأكيد اللقاء',
        'booking_rescheduled' => '📅 إعادة جدولة اللقاء',
        'booking_cancelled'   => '❌ إلغاء اللقاء',
        'course_enrolled'     => '🎓 تسجيل في مغامرة',
        'badge_awarded'       => '🏅 شارة جديدة',
        'badge_earned'        => '🏅 شارة جديدة',
        'assessment_created'  => '📊 تقييم جديد',
        'mission_completed'   => '🎯 مهمة مكتملة',
        'level_up'            => '🚀 ارتقاء مستوى',
        'quiz_attempt_ended'  => '📝 نتيجة اختبار',
        'new_message'         => '💬 رسالة جديدة',
        // v2.8 — Quiz Flow & routeur de notifications
        'quiz_assigned'        => '📝 اختبار جديد',
        'quiz_assigned_parent' => '📝 اختبار جديد لطفلك',
        'quiz_result_child'    => '📊 نتيجتك في الاختبار',
        'quiz_completed'       => '📝 نتيجة اختبار طفلك',
        'quiz_completed_coach' => '📝 نتيجة اختبار',
        'points_earned'        => '⭐ نقاط جديدة',
        'booking_new_coach'    => '📅 حجز جديد',
        'question_asked'       => '❓ سؤال جديد',
        // v3.1 — Q&A natif Tutor
        'qna_new_question'     => '❓ سؤال جديد من طالب',
        'qna_answered'         => '💬 رد المدرب على سؤالك',
        'progress_milestone'   => '🚀 تقدم رائع',
        // v2.8.4 — types historiques détectés par l'audit des liaisons
        'course_completed'     => '🎓 دورة مكتملة',
        'session_attended'     => '✅ حضور جلسة',
        'low_credits'          => '⚠️ الرصيد منخفض',
    ];

    /**
     * Envoie une notification vers la cloche header (wp_user_notifications via snippet).
     *
     * Délègue entièrement à riada_notify_user() — plus de table wp_rk_notifications.
     * La classe est conservée pour compatibilité : tous les appelants (booking_bridge,
     * assessment_service, gamification…) continuent à fonctionner sans modification.
     *
     * @param int    $user_id  WP user ID.
     * @param string $type     Clé de type : booking_confirmed, badge_earned, etc.
     * @param string $message  Texte de la notification.
     * @param array  $meta     [ 'link' => '...', 'child_id' => X, … ]
     */
    public static function push(
        int    $user_id,
        string $type,
        string $message,
        array  $meta = []
    ): bool {
        if ( $user_id <= 0 || '' === $type || '' === $message ) return false;

        self::ensure_table();

        $title = self::$title_map[ $type ] ?? 'إشعار';
        $link  = isset( $meta['link'] ) ? esc_url_raw( $meta['link'] ) : '';
        $body  = wp_strip_all_tags( $message );

        // 1) Store du plugin — la source de vérité (cloche enfant ET coach).
        global $wpdb;
        $ok = false !== $wpdb->insert( self::table(), [
            'user_id' => $user_id,
            'type'    => sanitize_key( $type ),
            'title'   => $title,
            'body'    => $body,
            'link'    => $link,
        ] );

        // 2) Miroir vers le snippet du thème s'il existe (compat descendante).
        if ( function_exists( 'riada_notify_user' ) ) {
            riada_notify_user( $user_id, $title, $body, $link, $type );
        }

        do_action( 'rk_mc_notification_pushed', $user_id, $type, $body, $meta );
        return $ok;
    }

    /**
     * Compte les notifications non lues pour un WP user.
     * Interroge wp_user_notifications (table du snippet riada_notify_user).
     * Retourne 0 si la table n'existe pas encore.
     */
    public static function get_unread_count( int $user_id ): int {
        if ( $user_id <= 0 ) return 0;
        self::ensure_table();
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            'SELECT COUNT(*) FROM ' . self::table() . ' WHERE user_id = %d AND is_read = 0',
            $user_id
        ) );
    }
}
