<?php
/**
 * RiadaKids\Coordinator\CoordinatorService — Phase 3 (LECTURE SEULE)
 *
 * Fournit au dashboard Coordinateur les données des bookings du NOUVEAU
 * workflow (CPT rk_booking, états pré-confirmation uniquement).
 *
 * Garanties Phase 3 :
 *  - aucune écriture (ni CPT, ni wp_rk_bookings, ni crédits) ;
 *  - aucun appel SSA ;
 *  - aucun do_action('rk_booking_confirmed') ;
 *  - les bookings legacy ('pending', 'confirmed'…) ne sont jamais listés
 *    (BookingRepository::find_ids_by_business_status() les exclut).
 *
 * @package RiadaKids\Coordinator
 */

namespace RiadaKids\Coordinator;

use RiadaKids\Booking\BookingRepository;
use RiadaKids\Booking\BookingStatus;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CoordinatorService {

    /** Rôle WordPress des coachs (inchangé : aucun rk_coach n'est créé). */
    public const COACH_ROLE = 'tutor_instructor';

    /** @var array<int,object> cache enfants pour la durée de la requête */
    private array $children_cache = [];

    public function __construct( private readonly BookingRepository $repository ) {}

    /** @return array<int,array> Bookings d'un état pré-confirmation, prêts à afficher. */
    public function list_by_status( string $status, int $limit = 100 ): array {
        $ids = $this->repository->find_ids_by_business_status( $status, $limit );
        $this->warm_children_cache( $ids );
        $out = [];
        foreach ( $ids as $id ) {
            $row = $this->describe( $id );
            if ( $row ) $out[] = $row;
        }
        return $out;
    }

    /**
     * Détail d'UN booking, côté serveur uniquement (l'ID vient du client, les
     * données jamais).
     *
     * @return array{ok:bool,error?:string,booking?:array}
     *   error ∈ not_found | not_workflow
     */
    public function get_booking( int $booking_id ): array {
        $post = $booking_id > 0 ? get_post( $booking_id ) : null;
        if ( ! $post || $post->post_type !== 'rk_booking' ) {
            return [ 'ok' => false, 'error' => 'not_found' ];
        }
        $status = (string) get_post_meta( $booking_id, '_rk_status', true );
        // Le Coordinateur ne voit que le nouveau workflow, jamais un booking legacy.
        if ( ! BookingStatus::is_pre_confirmation( $status ) || $post->post_status !== BookingStatus::WP_STATUS_AWAITING ) {
            return [ 'ok' => false, 'error' => 'not_workflow' ];
        }
        $this->warm_children_cache( [ $booking_id ] );
        $row = $this->describe( $booking_id );
        return $row ? [ 'ok' => true, 'booking' => $row ] : [ 'ok' => false, 'error' => 'not_found' ];
    }

    /**
     * Coachs (utilisateurs tutor_instructor) + nombre de propositions en
     * attente de confirmation parent qui leur sont adressées.
     * La disponibilité SSA par coach n'est PAS lue ici (Phase 5).
     *
     * @return array<int,array{id:int,name:string,open_proposals:int}>
     */
    public function coaches(): array {
        $users = get_users( [
            'role'    => self::COACH_ROLE,
            'orderby' => 'display_name',
            'order'   => 'ASC',
            'fields'  => [ 'ID', 'display_name' ],
        ] );

        $open = [];
        foreach ( $this->repository->find_ids_by_business_status( BookingStatus::PENDING_PARENT_CONFIRMATION, 500 ) as $id ) {
            $cid = (int) get_post_meta( $id, BookingStatus::META_PROPOSED_COACH_ID, true );
            if ( $cid > 0 ) $open[ $cid ] = ( $open[ $cid ] ?? 0 ) + 1;
        }

        $out = [];
        foreach ( (array) $users as $u ) {
            $out[] = [
                'id'             => (int) $u->ID,
                'name'           => (string) $u->display_name,
                'open_proposals' => $open[ (int) $u->ID ] ?? 0,
            ];
        }
        return $out;
    }

    // ── Construction d'une ligne d'affichage ───────────────────────────

    private function describe( int $id ): ?array {
        $post = get_post( $id );
        if ( ! $post ) return null;

        $status    = (string) get_post_meta( $id, '_rk_status', true );
        $child_ids = $this->repository->get_child_ids( $id );

        $children = [];
        foreach ( $child_ids as $cid ) {
            $c = $this->children_cache[ $cid ] ?? null;
            $children[] = [
                'id'   => $cid,
                'name' => $c ? trim( ( (string) ( $c->child_name ?? '' ) ) . ' ' . ( (string) ( $c->child_family_name ?? '' ) ) ) : '',
                'age'  => $c ? (int) ( $c->child_age ?? 0 ) : 0,
            ];
        }

        $parent_id = (int) $post->post_author;
        $parent    = $parent_id > 0 ? get_userdata( $parent_id ) : false;

        $program_id = (int) get_post_meta( $id, '_rk_program_id', true );
        $term       = $program_id > 0 ? get_term( $program_id, 'course-category' ) : null;
        $course_id  = (int) get_post_meta( $id, '_rk_adventure_id', true );
        $session_id = (int) get_post_meta( $id, '_rk_session_id', true );
        $session    = (string) get_post_meta( $id, '_rk_session_name', true );
        if ( $session === '' && $session_id > 0 ) $session = (string) get_the_title( $session_id );

        $coach_id = (int) get_post_meta( $id, BookingStatus::META_PROPOSED_COACH_ID, true );
        $by_id    = (int) get_post_meta( $id, BookingStatus::META_SCHEDULED_BY, true );
        $by       = $by_id > 0 ? get_userdata( $by_id ) : false;
        $coach    = $coach_id > 0 ? get_userdata( $coach_id ) : false;
        $datetime = (string) get_post_meta( $id, BookingStatus::META_PROPOSED_DATETIME, true );

        return [
            'booking_id'   => $id,
            'status'       => $status,
            'children'     => $children,
            'parent'       => [ 'id' => $parent_id, 'name' => $parent ? (string) $parent->display_name : '' ],
            'program'      => ( $term && ! is_wp_error( $term ) ) ? (string) $term->name : '',
            'course'       => $course_id > 0 ? (string) get_the_title( $course_id ) : '',
            'session'      => $session,
            'requested_at' => (string) get_post_meta( $id, '_rk_created_at', true ) ?: (string) $post->post_date,
            'proposal'     => ( $coach_id > 0 || $datetime !== '' ) ? [
                'coach_id'          => $coach_id,
                'coach_name'        => $coach ? (string) $coach->display_name : '',
                'datetime'          => $datetime,
                'scheduled_by_name' => $by ? (string) $by->display_name : '',
                'scheduled_at'      => (string) get_post_meta( $id, BookingStatus::META_SCHEDULED_AT, true ),
            ] : null,
            'change'       => $status === BookingStatus::CHANGE_REQUESTED ? [
                'note'         => (string) get_post_meta( $id, BookingStatus::META_CHANGE_NOTE, true ),
                'requested_at' => (string) get_post_meta( $id, BookingStatus::META_CHANGE_REQUESTED_AT, true ),
            ] : null,
        ];
    }

    /** Une seule requête SQL pour tous les enfants des bookings listés. */
    private function warm_children_cache( array $booking_ids ): void {
        $wanted = [];
        foreach ( $booking_ids as $bid ) {
            foreach ( $this->repository->get_child_ids( (int) $bid ) as $cid ) {
                if ( ! isset( $this->children_cache[ $cid ] ) ) $wanted[ $cid ] = $cid;
            }
        }
        if ( ! $wanted ) return;

        global $wpdb;
        $wanted = array_values( $wanted );
        $ph     = implode( ',', array_fill( 0, count( $wanted ), '%d' ) );
        $rows   = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}rk_children WHERE id IN ($ph)",
            ...$wanted
        ) );
        foreach ( (array) $rows as $row ) {
            $this->children_cache[ (int) $row->id ] = $row;
        }
    }
}
