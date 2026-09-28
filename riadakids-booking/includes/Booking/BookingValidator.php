<?php
/**
 * RiadaKids\Booking\BookingValidator
 *
 * Validation d'une demande de booking avant déduction de crédits.
 *
 * BUG7 — Vérifie appointment_type_id SSA (allowed_ssa_types)
 * Vérifie également : crédits disponibles, enfant valide, date future.
 */

namespace RiadaKids\Booking;

use RiadaKids\Credits\CreditRepository;
use RiadaKids\Database\DB;

if ( ! defined( 'ABSPATH' ) ) exit;

class BookingValidator {

    /** @var string[] */
    private array $errors = [];

    public function get_errors(): array  { return $this->errors; }
    public function get_first_error(): string { return $this->errors[0] ?? ''; }
    public function is_valid(): bool { return empty( $this->errors ); }

    /**
     * Valide un contexte de booking complet.
     *
     * FIX-C2 : $child_count transmis à check_credits() pour valider
     *   que le solde couvre TOUS les enfants sélectionnés.
     *
     * @param int   $user_id
     * @param int   $child_id    Premier enfant (validation ownership)
     * @param array $appt        Payload SSA appointment
     * @param int   $child_count Nombre total d'enfants (pour check crédit) — défaut 1
     * @return bool
     */
    public function validate( int $user_id, int $child_id, array $appt = [], int $child_count = 1 ): bool {
        $this->errors = [];

        $this->check_user( $user_id );
        $this->check_credits( $user_id, max( 1, $child_count ) );
        $this->check_child( $user_id, $child_id );
        if ( ! empty( $appt ) ) {
            $this->check_appointment_type( $appt );
            $this->check_future_date( $appt );
            $this->check_no_duplicate( $appt, $child_id );
        }

        return empty( $this->errors );
    }

    /* ── Règles ─────────────────────────────────────────────────── */

    private function check_user( int $user_id ): void {
        if ( ! $user_id || ! get_userdata( $user_id ) ) {
            $this->errors[] = 'المستخدم غير موجود';
        }
    }

    /**
     * FIX-C2 : Vérifie que le solde couvre les $required crédits (1 par enfant).
     *
     * Avant : toujours < 1 → passe avec balance=1 même pour 3 enfants
     * Après : < $required → bloque si solde insuffisant pour tous les enfants
     */
    private function check_credits( int $user_id, int $required = 1 ): void {
        $balance = CreditRepository::get_balance( $user_id );
        if ( $balance < $required ) {
            $this->errors[] = $required > 1
                ? "رصيد الحصص غير كافٍ ({$balance}/{$required})"
                : 'رصيد الحصص غير كافٍ';
        }
    }

    private function check_child( int $user_id, int $child_id ): void {
        if ( ! $child_id ) {
            $this->errors[] = 'يجب اختيار طفل';
            return;
        }
        // Vérifier que l'enfant appartient bien à l'utilisateur
        $children = DB::get_user_children( $user_id );
        $ids      = array_column( $children, 'id' );
        if ( ! in_array( (string) $child_id, array_map( 'strval', $ids ), true ) ) {
            $this->errors[] = 'الطفل المختار غير صحيح';
        }
    }

    /**
     * BUG7 — Vérifie que le type SSA est autorisé.
     */
    private function check_appointment_type( array $appt ): void {
        $type_id = (int) ( $appt['appointment_type_id'] ?? 0 );
        if ( ! $type_id ) return; // type absent = pas bloquant (hook ancien)

        $allowed = apply_filters( 'rk_allowed_ssa_types', [ (int) RK_SSA_APPT_TYPE_ID ] );
        if ( ! in_array( $type_id, (array) $allowed, true ) ) {
            $this->errors[] = 'نوع الموعد غير مسموح به';
        }
    }

    private function check_future_date( array $appt ): void {
        foreach ( [ 'start_date', 'start_at', 'scheduled_at' ] as $col ) {
            if ( ! empty( $appt[ $col ] ) ) {
                if ( strtotime( $appt[ $col ] ) < time() ) {
                    $this->errors[] = 'تاريخ الموعد في الماضي';
                }
                return;
            }
        }
    }

    private function check_no_duplicate( array $appt, int $child_id = 0 ): void {
        $appt_id = (int) ( $appt['id'] ?? 0 );
        if ( $appt_id && DB::booking_exists( $appt_id, $child_id ) ) {
            $this->errors[] = 'هذا الموعد محجوز بالفعل';
        }
    }
}