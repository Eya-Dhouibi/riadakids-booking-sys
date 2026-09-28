<?php
declare( strict_types=1 );
/**
 * Infrastructure/Tutor — RKP_TutorEnvironment  (v2.5.0 — Audit P3-10)
 *
 * Fonctions d'environnement Tutor LMS (URLs du dashboard, inscriptions et
 * complétions par utilisateur) encapsulées derrière le Learning Engine.
 *
 * SEULE cette couche a le droit d'appeler tutor_utils() — les templates et
 * services passent par RKP_LearningQueryService. Chaque méthode a un
 * fallback sûr si Tutor est absent (plugin désactivé, tests).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class RKP_TutorEnvironment {

	private static function utils(): ?object {
		return function_exists( 'tutor_utils' ) ? tutor_utils() : null;
	}

	/** URL d'une page du dashboard Tutor (ex. 'rk-fiche-eleve'). */
	public static function dashboard_url( string $page = '' ): string {
		$u = self::utils();
		if ( $u && method_exists( $u, 'tutor_dashboard_url' ) ) {
			return (string) $u->tutor_dashboard_url( $page );
		}
		$base = defined( 'RK_TUTOR_DASHBOARD_URL' ) ? RK_TUTOR_DASHBOARD_URL : '/dashboard/';
		return home_url( trailingslashit( $base ) . ( $page ? trailingslashit( $page ) : '' ) );
	}

	/** @return int[] IDs des cours où l'utilisateur est inscrit. */
	public static function enrolled_course_ids( int $wp_user_id ): array {
		$u = self::utils();
		if ( $u && method_exists( $u, 'get_enrolled_courses_ids_by_user' ) ) {
			return array_map( 'intval', (array) $u->get_enrolled_courses_ids_by_user( $wp_user_id ) );
		}
		// Fallback : enrollments actifs via le repository.
		return array_map(
			static fn( $e ) => (int) $e->course_id,
			array_filter( RKP_EnrollmentRepository::find_by_child( $wp_user_id ), static fn( $e ) => $e->is_active() )
		);
	}

	/** @return int[] IDs des cours complétés par l'utilisateur. */
	public static function completed_course_ids( int $wp_user_id ): array {
		$u = self::utils();
		if ( $u && method_exists( $u, 'get_completed_courses_ids_by_user' ) ) {
			return array_map( 'intval', (array) $u->get_completed_courses_ids_by_user( $wp_user_id ) );
		}
		return [];
	}

	/**
	 * Enregistrement de complétion Tutor (objet avec comment_date), ou null.
	 * Fidèle au retour de tutor_utils()->is_completed_course() — utilisé par
	 * les templates certificats pour la date réelle de complétion.
	 */
	public static function course_completion( int $course_id, int $wp_user_id ): ?object {
		$u = self::utils();
		if ( $u && method_exists( $u, 'is_completed_course' ) ) {
			$c = $u->is_completed_course( $course_id, $wp_user_id );
			return is_object( $c ) ? $c : null;
		}
		return null;
	}

	/** Le cours est-il complété par l'utilisateur ? */
	public static function is_course_completed( int $course_id, int $wp_user_id ): bool {
		$u = self::utils();
		if ( $u && method_exists( $u, 'is_completed_course' ) ) {
			return (bool) $u->is_completed_course( $course_id, $wp_user_id );
		}
		return in_array( $course_id, self::completed_course_ids( $wp_user_id ), true );
	}

	/** Pourcentage de complétion 0-100. */
	public static function completed_percent( int $course_id, int $wp_user_id ): int {
		return RKP_ProgressRepository::get_percent( $course_id, $wp_user_id );
	}
}
