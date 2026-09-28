<?php
/**
 * BookingRepository — v4.3 AUDIT FIX
 *
 *
 * @package RiadaKids\Booking
 */

namespace RiadaKids\Booking;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class BookingRepository {

    // -----------------------------------------------------------------------
    // Création
    // -----------------------------------------------------------------------

    /**
     * Phase 2 : $business_status permet de créer un booking du nouveau
     * workflow (BookingStatus::PENDING_SCHEDULE). Par défaut : comportement
     * historique strictement inchangé ('pending' / post_status 'pending').
     * Aucune ligne wp_rk_bookings n'est créée ici, quel que soit l'état.
     */
    public function create( BookingContext $ctx, string $business_status = BookingStatus::LEGACY_PENDING ): int {
        if ( ! in_array( $business_status, [ BookingStatus::LEGACY_PENDING, BookingStatus::PENDING_SCHEDULE ], true ) ) {
            rk_log( 'REPO', "create() statut initial refusé: {$business_status}", 'error' );
            return 0;
        }
        $uuid = wp_generate_uuid4();

        $post_id = wp_insert_post( [
            'post_type'   => 'rk_booking',
            'post_status' => BookingStatus::wp_post_status( $business_status ),
            'post_title'  => sprintf(
                'Réservation #%d — %s',
                $ctx->user_id,
                current_time( 'Y-m-d H:i:s' )
            ),
            'post_author' => $ctx->user_id,
            'meta_input'  => [
                '_rk_user_id'           => $ctx->user_id,
                '_rk_status'            => $business_status,
                '_rk_booking_uuid'      => $uuid,
                '_rk_credits_deducted'  => '0',
                '_rk_credits_refunded'  => '0',
                '_rk_created_at'        => current_time( 'mysql' ),
            ],
        ], true );

        if ( is_wp_error( $post_id ) ) {
            rk_log( 'REPO', 'create() wp_insert_post error: ' . $post_id->get_error_message(), 'error' );
            return 0;
        }

        return $post_id;
    }

    // -----------------------------------------------------------------------
    // Lecture
    // -----------------------------------------------------------------------

    public function load_context( int $booking_id ): ?BookingContext {
        $post = get_post( $booking_id );
        if ( ! $post || $post->post_type !== 'rk_booking' ) {
            return null;
        }

        $child_ids = get_post_meta( $booking_id, '_rk_child_ids', true );
        if ( ! is_array( $child_ids ) ) {
            $single    = (int) get_post_meta( $booking_id, '_rk_child_id', true );
            $child_ids = $single > 0 ? [ $single ] : [];
        }

        return BookingContext::from_array( [
            'user_id'              => (int)    $post->post_author,
            'program_id'           => (int)    get_post_meta( $booking_id, '_rk_program_id',           true ),
            'adventure_id'         => (int)    get_post_meta( $booking_id, '_rk_adventure_id',         true ),
            'session_id'           => (int)    get_post_meta( $booking_id, '_rk_session_id',           true ),
            'session_name'         => (string) get_post_meta( $booking_id, '_rk_session_name',         true ),
            'child_ids'            => $child_ids,
            'event_id'             => (int)    get_post_meta( $booking_id, '_rk_event_id',             true ),
            'event_name'           => (string) get_post_meta( $booking_id, '_rk_event_name',           true ),
            'appointment_id'       => (int)    get_post_meta( $booking_id, '_rk_appointment_id',       true ),
            'appointment_datetime' => (string) get_post_meta( $booking_id, '_rk_appointment_datetime', true ),
            'credits_used'         => (int)    get_post_meta( $booking_id, '_rk_credits_used',         true ),
        ] );
    }

    // -----------------------------------------------------------------------
    // Recherche par appointment_id SSA
    // -----------------------------------------------------------------------

    /**
     * BUG-8 FIX : filtre post_status pour exclure les bookings supprimés ('trash').
     *
     * Logique de priorité :
     *   1. Booking publié (confirmé, actif)         → post_status = 'publish'
     *   2. Booking en attente                        → post_status = 'pending'
     *   3. Booking annulé (conservé pour l'historique) → post_status = 'cancelled'
     *
     * Un booking en 'trash' (supprimé manuellement via WP) est ignoré.
     * Si un slot SSA est réutilisé après annulation, le nouveau booking 'pending'
     * sera trouvé en priorité sur l'ancien 'cancelled'.
     *
     * ORDER BY FIELD garantit : publish > pending > cancelled.
     */
    public function find_by_appointment_id( int $appointment_id ): int {
        global $wpdb;
        $result = $wpdb->get_var( $wpdb->prepare(
            "SELECT p.ID
               FROM {$wpdb->posts} p
         INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
                                        AND m.meta_key = '_rk_appointment_id'
                                        AND m.meta_value = %d
              WHERE p.post_type   = 'rk_booking'
                AND p.post_status IN ('pending', 'publish', 'cancelled')
              ORDER BY
                FIELD(p.post_status, 'publish', 'pending', 'cancelled') ASC,
                p.post_date DESC
              LIMIT 1",
            $appointment_id
        ) );
        return (int) ( $result ?? 0 );
    }

    // -----------------------------------------------------------------------
    // Recherche par booking_uuid
    // -----------------------------------------------------------------------

    public function find_by_uuid( string $uuid ): int {
        if ( ! $uuid ) return 0;
        global $wpdb;
        $result = $wpdb->get_var( $wpdb->prepare(
            "SELECT p.ID
               FROM {$wpdb->posts} p
         INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
                                        AND m.meta_key = '_rk_booking_uuid'
                                        AND m.meta_value = %s
              WHERE p.post_type = 'rk_booking'
              ORDER BY p.post_date DESC
              LIMIT 1",
            $uuid
        ) );
        return (int) ( $result ?? 0 );
    }

    // -----------------------------------------------------------------------
    // Recherche de doublons
    // -----------------------------------------------------------------------

    public function find_duplicate( int $child_id, int $event_id, string $datetime ): int {
        global $wpdb;

        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT p.ID
               FROM {$wpdb->posts} p
         INNER JOIN {$wpdb->postmeta} m1 ON m1.post_id = p.ID
                                         AND m1.meta_key = '_rk_event_id'
                                         AND m1.meta_value = %d
         INNER JOIN {$wpdb->postmeta} m2 ON m2.post_id = p.ID
                                         AND m2.meta_key = '_rk_appointment_datetime'
                                         AND m2.meta_value = %s
              WHERE p.post_type   = 'rk_booking'
                AND p.post_status IN ('pending','publish')",
            $event_id,
            $datetime
        ) );

        if ( empty( $ids ) ) return 0;

        foreach ( $ids as $booking_id ) {
            $stored = get_post_meta( (int) $booking_id, '_rk_child_ids', true );
            if ( is_array( $stored ) && in_array( $child_id, array_map( 'intval', $stored ), true ) ) {
                return (int) $booking_id;
            }
            $single = (int) get_post_meta( (int) $booking_id, '_rk_child_id', true );
            if ( $single === $child_id ) {
                return (int) $booking_id;
            }
        }

        return 0;
    }

    // -----------------------------------------------------------------------
    // Recherche booking pending
    // -----------------------------------------------------------------------

    public function find_pending_booking_by_event_and_user( int $event_id, int $user_id ): int {
        global $wpdb;

        $result = $wpdb->get_var( $wpdb->prepare(
            "SELECT p.ID
               FROM {$wpdb->posts} p
          LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
                                       AND m.meta_key = '_rk_event_id'
              WHERE p.post_type   = 'rk_booking'
                AND p.post_status = 'pending'
                AND p.post_author = %d
                AND (
                        m.meta_value = %d
                     OR m.meta_value = '0'
                     OR m.meta_value IS NULL
                    )
              ORDER BY
                CASE
                    WHEN m.meta_value = %d THEN 0
                    ELSE 1
                END ASC,
                p.post_date DESC
              LIMIT 1",
            $user_id,
            $event_id,
            $event_id
        ) );

        return (int) ( $result ?? 0 );
    }

    public function find_pending_booking_by_event( int $event_id ): int {
        global $wpdb;

        $result = $wpdb->get_var( $wpdb->prepare(
            "SELECT p.ID
               FROM {$wpdb->posts} p
          LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
                                       AND m.meta_key = '_rk_event_id'
              WHERE p.post_type   = 'rk_booking'
                AND p.post_status = 'pending'
                AND (
                        m.meta_value = %d
                     OR m.meta_value = '0'
                     OR m.meta_value IS NULL
                    )
              ORDER BY
                CASE
                    WHEN m.meta_value = %d THEN 0
                    ELSE 1
                END ASC,
                p.post_date DESC
              LIMIT 1",
            $event_id,
            $event_id
        ) );

        return (int) ( $result ?? 0 );
    }

    /**
     * ÉTAPE 13 — détection de collision pour le fallback sans UUID ni user.
     * Compte les bookings pending candidats pour cet event_id (même
     * critère de correspondance que find_pending_booking_by_event : le
     * event_id exact, OU 0/NULL — cas normal du pre-create avant que SSA
     * n'ait fourni le vrai type). Si ce compte est > 1, plusieurs
     * utilisateurs ont potentiellement une réservation en cours
     * simultanément sur le même event : le fallback ne peut alors pas
     * garantir de retrouver le bon booking, et BookingService doit refuser
     * plutôt que de deviner au hasard lequel confirmer.
     */
    public function count_pending_bookings_by_event( int $event_id ): int {
        global $wpdb;

        $result = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*)
               FROM {$wpdb->posts} p
          LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
                                       AND m.meta_key = '_rk_event_id'
              WHERE p.post_type   = 'rk_booking'
                AND p.post_status = 'pending'
                AND (
                        m.meta_value = %d
                     OR m.meta_value = '0'
                     OR m.meta_value IS NULL
                    )",
            $event_id
        ) );

        return (int) ( $result ?? 0 );
    }

    // -----------------------------------------------------------------------
    // Liste pour Dashboard
    // -----------------------------------------------------------------------

    // ═══════════════════════════════════════════════════════════════════
    // Phase 2 — nouveau workflow (états pré-confirmation)
    // Ces requêtes ne ciblent QUE post_status = 'rk_awaiting'. Elles ne
    // modifient jamais le comportement des finders legacy ci-dessus.
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Booking pré-confirmation dont _rk_appointment_id = $appointment_id.
     * Sert à reconnaître un callback/cron SSA appartenant au nouveau workflow.
     */
    public function find_pre_confirmation_by_appointment_id( int $appointment_id ): int {
        if ( $appointment_id <= 0 ) return 0;
        global $wpdb;
        $result = $wpdb->get_var( $wpdb->prepare(
            "SELECT p.ID
               FROM {$wpdb->posts} p
         INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
                                        AND m.meta_key = '_rk_appointment_id'
                                        AND m.meta_value = %d
              WHERE p.post_type   = 'rk_booking'
                AND p.post_status = %s
              ORDER BY p.post_date DESC
              LIMIT 1",
            $appointment_id,
            BookingStatus::WP_STATUS_AWAITING
        ) );
        return (int) ( $result ?? 0 );
    }

    /**
     * Demande ouverte (pré-confirmation) pour user + session + ENFANT.
     * Critère enfant obligatoire : deux enfants d'un même parent sur la
     * même session ne doivent jamais partager une demande.
     */
    public function find_open_workflow_booking( int $user_id, int $session_id, int $child_id ): int {
        if ( $user_id <= 0 || $session_id <= 0 || $child_id <= 0 ) return 0;
        global $wpdb;
        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT p.ID
               FROM {$wpdb->posts} p
         INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
                                        AND m.meta_key = '_rk_session_id'
                                        AND m.meta_value = %d
              WHERE p.post_type   = 'rk_booking'
                AND p.post_status = %s
                AND p.post_author = %d
              ORDER BY p.post_date DESC",
            $session_id,
            BookingStatus::WP_STATUS_AWAITING,
            $user_id
        ) );
        foreach ( (array) $ids as $id ) {
            if ( in_array( $child_id, $this->get_child_ids( (int) $id ), true ) ) {
                return (int) $id;
            }
        }
        return 0;
    }

    /**
     * Phase 3 — IDs des bookings du NOUVEAU workflow pour un état métier donné
     * (liste du dashboard Coordinateur). Lecture seule.
     *
     * Ne retourne que des états pré-confirmation : un état legacy ('pending',
     * 'confirmed'...) ou inconnu renvoie [] — le dashboard Coordinateur ne peut
     * donc jamais lister un ancien booking. Filtre à la fois sur _rk_status
     * (état métier) et post_status = rk_awaiting (invariant Phase 2).
     *
     * @return int[] du plus récent au plus ancien
     */
    public function find_ids_by_business_status( string $status, int $limit = 100 ): array {
        if ( ! BookingStatus::is_pre_confirmation( $status ) ) return [];
        $limit = max( 1, min( 500, $limit ) );
        global $wpdb;
        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT p.ID
               FROM {$wpdb->posts} p
         INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
                                        AND m.meta_key = '_rk_status'
                                        AND m.meta_value = %s
              WHERE p.post_type   = 'rk_booking'
                AND p.post_status = %s
              ORDER BY p.post_date DESC, p.ID DESC
              LIMIT %d",
            $status,
            BookingStatus::WP_STATUS_AWAITING,
            $limit
        ) );
        return array_map( 'intval', (array) $ids );
    }

    /** IDs enfants d'un booking (_rk_child_ids, repli sur l'ancien _rk_child_id). @return int[] */
    public function get_child_ids( int $booking_id ): array {
        $ids = get_post_meta( $booking_id, '_rk_child_ids', true );
        if ( is_array( $ids ) ) {
            return array_values( array_filter( array_map( 'intval', $ids ) ) );
        }
        $single = (int) get_post_meta( $booking_id, '_rk_child_id', true );
        return $single > 0 ? [ $single ] : [];
    }

    /**
     * BUG-7 FIX (cohérence) : inclut 'cancelled' dans post_status.
     * Les bookings annulés ont maintenant post_status = 'cancelled'
     * (et non 'trash') depuis cancel_from_ssa() v4.4.
     * 'trash' gardé pour rétrocompatibilité avec les anciens bookings annulés.
     */
    public function get_user_bookings( int $user_id ): array {
        $posts = get_posts( [
            'post_type'      => 'rk_booking',
            'author'         => $user_id,
            'post_status'    => [ 'pending', 'publish', 'cancelled', 'trash' ],
            'posts_per_page' => -1,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ] );

        $bookings = [];
        foreach ( $posts as $post ) {
            $child_ids = get_post_meta( $post->ID, '_rk_child_ids', true );
            if ( ! is_array( $child_ids ) ) {
                $single    = (int) get_post_meta( $post->ID, '_rk_child_id', true );
                $child_ids = $single > 0 ? [ $single ] : [];
            }

            // Source de vérité : _rk_status (méta CPT)
            $status = (string) get_post_meta( $post->ID, '_rk_status', true ) ?: $post->post_status;

            $bookings[] = [
                'booking_id'           => $post->ID,
                'booking_uuid'         => (string) get_post_meta( $post->ID, '_rk_booking_uuid',         true ),
                'status'               => $status,
                'child_ids'            => $child_ids,
                'event_id'             => (int)    get_post_meta( $post->ID, '_rk_event_id',             true ),
                'event_name'           => (string) get_post_meta( $post->ID, '_rk_event_name',           true ),
                'session_name'         => (string) get_post_meta( $post->ID, '_rk_session_name',         true ),
                'appointment_datetime' => (string) get_post_meta( $post->ID, '_rk_appointment_datetime', true ),
                'appointment_id'       => (int)    get_post_meta( $post->ID, '_rk_appointment_id',       true ),
                'program_id'           => (int)    get_post_meta( $post->ID, '_rk_program_id',           true ),
                'credits_used'         => (int)    get_post_meta( $post->ID, '_rk_credits_used',         true ),
                'created_at'           => $post->post_date,
            ];
        }

        return $bookings;
    }
}