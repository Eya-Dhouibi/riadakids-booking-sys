<?php
declare( strict_types=1 );
/**
 * Application/Query — FamilyLinkQueryService  (v3.1.0 — DDD fix)
 *
 * SOURCE UNIQUE de la relation coach ↔ enfant ↔ parent pour TOUTES les vues.
 *
 * Problème résolu : chaque vue résolvait le trio par un chemin différent
 * (RK_Coach_Data::get_parent_of_child, booking-bridge::get_coach_for_child,
 * JourneyQueryService, requêtes ad hoc dans les templates…) avec des
 * résultats parfois divergents (coach via mapping vs coach via bookings).
 *
 * Ordre de résolution du coach (documenté, déterministe) :
 *   1. wp_rk_child_coaches WHERE is_primary = 1        (choix explicite)
 *   2. wp_rk_child_coaches — assignation la plus récente
 *   3. wp_rk_bookings.coach_id — dernier booking        (fallback historique)
 *
 * Toutes les lectures sont mises en cache statique par requête HTTP.
 *
 * RÈGLE (13/08/2026) : aucun accès $wpdb direct dans ce fichier — toutes
 * les requêtes SQL vivent dans RKP_CoachStudentRepository (Infrastructure).
 * Violation corrigée suite à audit : ce fichier contenait auparavant des
 * requêtes $wpdb brutes, seule exception parmi les Query/Command Services
 * du plugin.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class RKP_FamilyLinkQueryService {

	/** @var array<int,array> cache par child_rk_id */
	private static array $ctx_cache = [];

	/* ─────────────────────────────────────────────────────────────
	 * CONTEXTE FAMILLE — l'objet que toutes les vues consomment
	 * ───────────────────────────────────────────────────────────── */

	/**
	 * Contexte complet à partir de l'ID plateforme (rk_children.id).
	 *
	 * @return array{
	 *   child_rk_id:int, child_wp_uid:int, child_name:string, avatar_url:string,
	 *   parent_id:int, parent_name:string, parent_email:string,
	 *   coach_id:int, coach_name:string, coach_avatar:string,
	 *   is_primary_coach:bool
	 * }
	 */
	public static function context( int $child_rk_id ): array {
		if ( $child_rk_id <= 0 ) return self::empty_context();
		if ( isset( self::$ctx_cache[ $child_rk_id ] ) ) return self::$ctx_cache[ $child_rk_id ];

		$child = RKP_CoachStudentRepository::find_context_row( $child_rk_id );
		if ( ! $child ) return self::$ctx_cache[ $child_rk_id ] = self::empty_context();

		[ $coach_id, $is_primary ] = self::resolve_coach( $child_rk_id );

		$parent = $child->parent_id ? get_userdata( (int) $child->parent_id ) : null;
		$coach  = $coach_id ? get_userdata( $coach_id ) : null;

		return self::$ctx_cache[ $child_rk_id ] = [
			'child_rk_id'      => (int) $child->id,
			'child_wp_uid'     => (int) $child->wp_user_id,
			'child_name'       => (string) $child->child_name,
			'avatar_url'       => (string) ( $child->avatar_url ?? '' ),
			'parent_id'        => (int) $child->parent_id,
			'parent_name'      => $parent ? $parent->display_name : '',
			'parent_email'     => $parent ? $parent->user_email : '',
			'coach_id'         => $coach_id,
			'coach_name'       => $coach ? $coach->display_name : '',
			'coach_avatar'     => $coach ? get_avatar_url( $coach->ID, [ 'size' => 96 ] ) : '',
			'is_primary_coach' => $is_primary,
		];
	}

	/** Contexte à partir du WP user id de l'enfant (dashboards, REST). */
	public static function context_by_wp_uid( int $child_wp_uid ): array {
		if ( $child_wp_uid <= 0 ) return self::empty_context();
		$id = RKP_CoachStudentRepository::find_id_by_wp_user_id( $child_wp_uid );
		return self::context( $id );
	}

	/* ─────────────────────────────────────────────────────────────
	 * Résolutions unitaires (compat avec les appels existants)
	 * ───────────────────────────────────────────────────────────── */

	/** Coach effectif d'un enfant, 0 si aucun. */
	public static function coach_for_child( int $child_rk_id ): int {
		return self::context( $child_rk_id )['coach_id'];
	}

	/** Parent (WP user) d'un enfant, 0 si aucun. */
	public static function parent_for_child( int $child_rk_id ): int {
		return self::context( $child_rk_id )['parent_id'];
	}

	/**
	 * Tous les coachs d'un enfant (multi-matières), primaire en premier.
	 * @return array<int,array{coach_id:int,coach_name:string,is_primary:bool,assigned_at:string}>
	 */
	public static function coaches_for_child( int $child_rk_id ): array {
		$rows = RKP_CoachStudentRepository::find_coach_assignments( $child_rk_id );
		$out = [];
		foreach ( $rows as $r ) {
			$u = get_userdata( (int) $r->coach_id );
			$out[] = [
				'coach_id'    => (int) $r->coach_id,
				'coach_name'  => $u ? $u->display_name : '',
				'is_primary'  => (bool) $r->is_primary,
				'assigned_at' => (string) $r->assigned_at,
			];
		}
		return $out;
	}

	/**
	 * Contextes famille pour un LOT d'enfants — pour les rosters (1 vue = 1 appel).
	 * @param  int[] $child_rk_ids
	 * @return array<int,array> indexé par child_rk_id
	 */
	public static function contexts( array $child_rk_ids ): array {
		$out = [];
		foreach ( array_unique( array_map( 'intval', $child_rk_ids ) ) as $id ) {
			if ( $id > 0 ) $out[ $id ] = self::context( $id );
		}
		return $out;
	}

	/* ─────────────────────────────────────────────────────────────
	 * Interne
	 * ───────────────────────────────────────────────────────────── */

	/** @return array{0:int,1:bool} [coach_id, is_primary] */
	private static function resolve_coach( int $child_rk_id ): array {
		// 1. Coach principal explicite
		$primary = RKP_CoachStudentRepository::find_explicit_primary_coach( $child_rk_id );
		if ( $primary > 0 ) return [ $primary, true ];

		// 2. Assignation la plus récente
		$latest = RKP_CoachStudentRepository::find_latest_coach_assignment( $child_rk_id );
		if ( $latest > 0 ) return [ $latest, false ];

		// 3. Fallback : dernier booking porteur d'un coach_id
		if ( RKP_CoachStudentRepository::bookings_have_coach_column() ) {
			$from_bk = RKP_CoachStudentRepository::find_coach_from_latest_booking( $child_rk_id );
			if ( $from_bk > 0 ) return [ $from_bk, false ];
		}

		return [ 0, false ];
	}

	private static function empty_context(): array {
		return [
			'child_rk_id' => 0, 'child_wp_uid' => 0, 'child_name' => '', 'avatar_url' => '',
			'parent_id' => 0, 'parent_name' => '', 'parent_email' => '',
			'coach_id' => 0, 'coach_name' => '', 'coach_avatar' => '',
			'is_primary_coach' => false,
		];
	}
}