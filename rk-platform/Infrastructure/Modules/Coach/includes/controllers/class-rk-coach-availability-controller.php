<?php
declare( strict_types=1 );
/**
 * RK_Coach_Availability_Controller
 *
 * Disponibilité SSA du coach depuis la SPA /espace-coach/#settings
 * (nouvel onglet "التوفر") :
 *   GET  /coach/availability          — créneaux + capacity_type actuels
 *   POST /coach/availability          — sauvegarde des créneaux hebdomadaires
 *   POST /coach/availability/capacity — sauvegarde individual/group
 *
 * Toute écriture délègue à RKP_AvailabilityCommandService (Application) →
 * RKP_AvailabilityRepository (Infrastructure) → ssa()->appointment_type_model.
 * AUCUNE table/logique de disponibilité propre à ce plugin — une seule
 * source de vérité, celle de SSA, pour garantir que le calendrier de
 * réservation public reflète toujours exactement ce que le coach a
 * configuré ici.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class RK_Coach_Availability_Controller {

    /* ─── GET /coach/availability ───────────────────────────────── */

    public static function get_availability( WP_REST_Request $request ): WP_REST_Response {
        $coach_id   = get_current_user_id();
        $week_start = sanitize_text_field( (string) ( $request->get_param( 'week_start' ) ?? '' ) );

        if ( ! class_exists( 'RKP_AvailabilityCommandService' ) ) {
            return new WP_REST_Response( [ 'code' => 'service_unavailable', 'message' => 'الخدمة غير متاحة حاليًا.' ], 500 );
        }

        $result = $week_start && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $week_start )
            ? RKP_AvailabilityCommandService::get_for_coach_week( $coach_id, $week_start )
            : RKP_AvailabilityCommandService::get_for_coach( $coach_id );

        if ( ! $result['success'] ) {
            return self::error_response( $result['code'] ?? 'unknown_error' );
        }

        return new WP_REST_Response( $result['data'], 200 );
    }

    /* ─── POST /coach/availability ──────────────────────────────── */

    public static function update_availability( WP_REST_Request $request ): WP_REST_Response {
        $coach_id   = get_current_user_id();
        $week_start = sanitize_text_field( (string) ( $request->get_param( 'week_start' ) ?? '' ) );

        $availability = $request->get_param( 'availability' );
        if ( ! is_array( $availability ) ) {
            return new WP_REST_Response( [ 'code' => 'missing_availability', 'message' => 'بيانات التوفر مفقودة.' ], 400 );
        }

        if ( ! class_exists( 'RKP_AvailabilityCommandService' ) ) {
            return new WP_REST_Response( [ 'code' => 'service_unavailable', 'message' => 'الخدمة غير متاحة حاليًا.' ], 500 );
        }

        $result = RKP_AvailabilityCommandService::save_for_coach(
            $coach_id,
            $availability,
            $week_start && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $week_start ) ? $week_start : null
        );

        if ( ! $result['success'] ) {
            return self::error_response( $result['code'] ?? 'unknown_error' );
        }

        if ( class_exists( 'RK_Audit_Log' ) && method_exists( 'RK_Audit_Log', 'log' ) ) {
            RK_Audit_Log::log( $coach_id, 'availability_updated', 'coach_settings' );
        }

        return new WP_REST_Response( array_merge( [ 'success' => true ], $result['data'] ), 200 );
    }

    /* ─── POST /coach/availability/capacity ─────────────────────── */

    public static function update_capacity_type( WP_REST_Request $request ): WP_REST_Response {
        $coach_id      = get_current_user_id();
        $capacity_type = sanitize_key( (string) ( $request->get_param( 'capacity_type' ) ?? '' ) );
        $capacity      = (int) ( $request->get_param( 'capacity' ) ?? 1 );

        if ( ! class_exists( 'RKP_AvailabilityCommandService' ) ) {
            return new WP_REST_Response( [ 'code' => 'service_unavailable', 'message' => 'الخدمة غير متاحة حاليًا.' ], 500 );
        }

        $result = RKP_AvailabilityCommandService::save_capacity_type_for_coach( $coach_id, $capacity_type, $capacity );

        if ( ! $result['success'] ) {
            return self::error_response( $result['code'] ?? 'unknown_error' );
        }

        if ( class_exists( 'RK_Audit_Log' ) && method_exists( 'RK_Audit_Log', 'log' ) ) {
            RK_Audit_Log::log( $coach_id, 'capacity_type_updated', 'coach_settings' );
        }

        return new WP_REST_Response( array_merge( [ 'success' => true ], $result['data'] ), 200 );
    }

    /* ─── Messages d'erreur arabes, cohérents avec le reste du dashboard ── */

    private static function error_response( string $code ): WP_REST_Response {
        $messages = [
            'no_appointment_type'   => 'لم يتم العثور على برنامج مرتبط بحسابك. يرجى التواصل مع الإدارة.',
            'ssa_unavailable'       => 'تعذّر الاتصال بنظام الحجوزات. حاول لاحقًا.',
            'invalid_slots'         => 'صيغة الأوقات غير صحيحة.',
            'invalid_capacity_type' => 'نوع الجلسة غير صالح.',
            'invalid_capacity'      => 'عدد المشاركين يجب أن يكون بين 2 و50.',
            'save_failed'           => 'تعذّر حفظ التغييرات. حاول مرة أخرى.',
            'service_unavailable'   => 'الخدمة غير متاحة حاليًا.',
        ];
        $status = 'no_appointment_type' === $code ? 404 : ( in_array( $code, [ 'invalid_slots', 'invalid_capacity_type', 'invalid_capacity' ], true ) ? 400 : 500 );

        return new WP_REST_Response( [
            'code'    => $code,
            'message' => $messages[ $code ] ?? 'حدث خطأ غير متوقع.',
        ], $status );
    }
}
