<?php
declare( strict_types=1 );
/**
 * RKP_MobileChildAccess — résolution d'identité « self » pour l'app mobile.
 *
 * PIVOT (4.19.1) : l'app mobile authentifie désormais l'ENFANT lui-même
 * (username enfant + PIN, cf. RKP_AuthTokenCommandService::login()), pas
 * son parent. Le sujet du JWT (claims['sub']) est donc le wp_user_id de
 * l'enfant — jamais celui d'un parent.
 *
 * Conséquence pour tous les contrôleurs REST /rk/v1/* : la question n'est
 * plus « ce child_id appartient-il au parent connecté ? » (RKP_..._for_parent,
 * cf. plan v1 et l'audit du 16/09/2026) mais « ce child_id est-il celui de
 * l'enfant connecté lui-même ? ». C'est une vérification strictement plus
 * simple et plus sûre : un enfant ne peut structurellement pas être
 * authentifié comme un autre enfant (contrairement à un parent qui peut
 * avoir plusieurs enfants et se tromper d'ID dans une requête).
 *
 * Remplace RKP_AssessmentEligibilityService::check_parent_access() prévu
 * par le plan v1 — jamais implémenté (cf. audit §2.1), et de toute façon
 * devenu obsolète avec ce pivot d'authentification.
 *
 * @package RK_Platform
 * @since   4.19.1
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class RKP_MobileChildAccess {

	/**
	 * La ligne rk_children de l'enfant authentifié — jamais celle d'un
	 * autre enfant, quel que soit le child_id demandé dans l'URL.
	 *
	 * @param int $authenticated_wp_user_id get_current_user_id() résolu
	 *                                      via le Bearer JWT (ou cookie).
	 * @return object|null
	 */
	public static function resolve_self( int $authenticated_wp_user_id ): ?object {
		if ( $authenticated_wp_user_id <= 0 ) return null;
		if ( ! class_exists( 'RK_MC_Child_Repository' ) ) return null;

		$user = get_userdata( $authenticated_wp_user_id );
		if ( ! $user || ! in_array( 'rk_child', (array) $user->roles, true ) ) {
			// Le mobile n'authentifie QUE des comptes enfant — un parent
			// (ou tout autre rôle) qui présenterait un JWT valide n'a
			// structurellement accès à aucune ressource /rk/v1/*.
			return null;
		}

		return RK_MC_Child_Repository::get_by_wp_user_id( $authenticated_wp_user_id );
	}

	/**
	 * Le child_id demandé (paramètre d'URL) correspond-il bien à l'enfant
	 * authentifié ? Refuse tout accès croisé enfant→enfant.
	 *
	 * @param int $requested_child_rk_id rk_children.id demandé dans l'URL.
	 * @param int $authenticated_wp_user_id get_current_user_id().
	 * @return object|null La ligne rk_children si autorisé, sinon null.
	 */
	public static function check_self_access( int $requested_child_rk_id, int $authenticated_wp_user_id ): ?object {
		$self = self::resolve_self( $authenticated_wp_user_id );
		if ( ! $self ) return null;
		if ( (int) $self->id !== $requested_child_rk_id ) return null;
		return $self;
	}
}
