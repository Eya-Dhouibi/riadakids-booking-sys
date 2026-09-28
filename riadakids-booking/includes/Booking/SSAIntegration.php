<?php
namespace RiadaKids\Booking;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Class SSAIntegration
 * Gère l'interaction et le requêtage de la base de données de Simply Schedule Appointments (SSA).
 *
 * Phase 3.1 — SUPPRIMÉ :
 *   - render_widget()  — l'iframe officielle SSA est rendue directement par BookingForm.php
 */
class SSAIntegration {

    /**
     * Récupère la liste des types d'événements SSA actifs.
     * Format : [ ['id' => int, 'name' => string], ... ]
     *
     * @return array
     */
    public static function get_event_types(): array {
        return self::_fetch_from_ssa_table();
    }

    /**
     * Retourne la liste des RKEvent configurés dans l'option rk_ssa_events.
     * Utilisée par BookingAjax::handle_get_events().
     *
     * @return RKEvent[]
     */
    public static function get_events(): array {
        return RKEvent::from_option();
    }

    /**
     * Cherche un RKEvent par son ssa_id.
     * Utilisée par BookingContext::from_array() et BookingService::ensure_from_ssa().
     *
     * @param int $ssa_id
     * @return RKEvent|null
     */
    public static function find_event( int $ssa_id ): ?RKEvent {
        if ( $ssa_id <= 0 ) {
            return null;
        }
        foreach ( RKEvent::from_option() as $event ) {
            if ( $event->ssa_id === $ssa_id ) {
                return $event;
            }
        }
        return null;
    }

    /**
     * Retourne les types de rendez-vous SSA depuis wp_ssa_appointment_types.
     * Utilisée par EventsAdminPage::render_page() pour le <select> de configuration.
     * Format : [ ['id' => int, 'title' => string], ... ]
     *
     * @return array
     */
    public static function fetch_ssa_appointment_types(): array {
        global $wpdb;
        $table = $wpdb->prefix . 'ssa_appointment_types';

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return [];
        }

        $rows = $wpdb->get_results(
            "
            SELECT
                id,
                title
            FROM {$table}
            WHERE status = 'publish'
            ORDER BY id ASC
            ",
            ARRAY_A
        );

        if ( empty( $rows ) || ! is_array( $rows ) ) {
            return [];
        }

        $types = [];
        foreach ( $rows as $row ) {
            $id    = (int) ( $row['id'] ?? 0 );
            $title = isset( $row['title'] ) ? trim( (string) $row['title'] ) : '';
            if ( $id > 0 && $title !== '' ) {
                $types[] = [
                    'id'    => $id,
                    'title' => $title,
                ];
            }
        }

        return $types;
    }

    /**
     * Valide si un ID de type d'événement existe et est bien publié.
     *
     * @param int $event_id
     * @return bool
     */
    public static function is_valid_event_id( int $event_id ): bool {
        if ( $event_id <= 0 ) {
            return false;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'ssa_appointment_types';

        $status = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT status FROM {$table} WHERE id = %d",
                $event_id
            )
        );

        return ( 'publish' === $status );
    }

    // ── Extraction payload webhook SSA ─────────────────────────────────────

    /**
     * Extrait l'appointment_id depuis un payload webhook SSA.
     *
     * Chemins acceptés (par ordre de priorité) :
     *   $payload['appointment_id']          — champ plat standard
     *   $payload['id']                      — champ plat alternatif
     *   $payload['appointment']['id']       — objet imbriqué SSA v3+
     *
     * Retourne toujours un int >= 0. Jamais de cast tableau → entier.
     *
     * @param  array $payload Corps JSON du webhook SSA.
     * @return int  ID du rendez-vous, ou 0 si non trouvé.
     */
    public static function extract_appointment_id( array $payload ): int {
        // 1. Champ plat appointment_id
        if ( isset( $payload['appointment_id'] ) && ! is_array( $payload['appointment_id'] ) ) {
            $v = (int) $payload['appointment_id'];
            if ( $v > 0 ) return $v;
        }

        // 2. Champ plat id
        if ( isset( $payload['id'] ) && ! is_array( $payload['id'] ) ) {
            $v = (int) $payload['id'];
            if ( $v > 0 ) return $v;
        }

        // 3. Objet imbriqué appointment.id
        if ( isset( $payload['appointment']['id'] ) && ! is_array( $payload['appointment']['id'] ) ) {
            $v = (int) $payload['appointment']['id'];
            if ( $v > 0 ) return $v;
        }

        return 0;
    }

    /**
     * Extrait l'appointment_type_id depuis un payload webhook SSA.
     *
     * Chemins acceptés (par ordre de priorité) :
     *   $payload['appointment_type_id']                   — champ plat standard
     *   $payload['type_id']                               — alias court
     *   $payload['appointment_type']['id']                — objet imbriqué
     *   $payload['appointment']['appointment_type_id']    — objet appointment imbriqué
     *
     * Jamais de cast (int) array(...) — chaque branche vérifie is_array() avant cast.
     *
     * @param  array $payload Corps JSON du webhook SSA.
     * @return int  ID du type de rendez-vous, ou 0 si non trouvé / tableau reçu.
     */
    public static function extract_appointment_type_id( array $payload ): int {
        // 1. Champ plat appointment_type_id
        if ( isset( $payload['appointment_type_id'] ) && ! is_array( $payload['appointment_type_id'] ) ) {
            $v = (int) $payload['appointment_type_id'];
            if ( $v > 0 ) return $v;
        }

        // 2. Alias court type_id
        if ( isset( $payload['type_id'] ) && ! is_array( $payload['type_id'] ) ) {
            $v = (int) $payload['type_id'];
            if ( $v > 0 ) return $v;
        }

        // 3. Objet imbriqué appointment_type.id  (SSA peut envoyer l'objet complet)
        if ( isset( $payload['appointment_type'] ) && is_array( $payload['appointment_type'] ) ) {
            if ( isset( $payload['appointment_type']['id'] ) && ! is_array( $payload['appointment_type']['id'] ) ) {
                $v = (int) $payload['appointment_type']['id'];
                if ( $v > 0 ) return $v;
            }
        }

        // 4. Objet imbriqué appointment.appointment_type_id
        if ( isset( $payload['appointment'] ) && is_array( $payload['appointment'] ) ) {
            if ( isset( $payload['appointment']['appointment_type_id'] ) && ! is_array( $payload['appointment']['appointment_type_id'] ) ) {
                $v = (int) $payload['appointment']['appointment_type_id'];
                if ( $v > 0 ) return $v;
            }
        }

        return 0;
    }

    /**
     * Extrait le booking_uuid (identifiant RiadaKids) depuis un payload
     * webhook SSA ou un tableau $data d'action native SSA.
     *
     * booking_uuid est injecté par RiadaKids comme paramètre de query
     * string dans l'URL de l'iframe SSA (voir booking-ssa.js
     * ::_injectIframeParams) — ce n'est pas un custom field configuré côté
     * dashboard SSA. SSA le retransmet généralement à plat dans son
     * payload, mais par cohérence avec extract_appointment_id() et
     * extract_appointment_type_id() ci-dessus (qui gèrent déjà des formes
     * imbriquées), cette méthode couvre aussi le cas où SSA l'enverrait
     * sous forme imbriquée selon le canal (event JS natif, webhook REST,
     * hook PHP natif) — amélioration de robustesse du mapping uniquement,
     * aucune donnée n'est devinée ou recalculée : on ne fait que chercher
     * la même valeur à des emplacements plus nombreux.
     *
     * Chemins acceptés (par ordre de priorité) :
     *   $payload['booking_uuid']                — champ plat standard
     *   $payload['rk_booking_uuid']              — alias historique
     *   $payload['appointment']['booking_uuid']  — objet imbriqué SSA v3+
     *   $payload['custom_fields']['booking_uuid'] — au cas où SSA
     *                                                l'exposerait un jour
     *                                                comme champ personnalisé
     *
     * @param  array $payload Payload webhook ou $data d'action native SSA.
     * @return string UUID du booking RiadaKids, ou '' si non trouvé.
     */
    public static function extract_booking_uuid( array $payload ): string {
        // 1. Champ plat booking_uuid
        if ( isset( $payload['booking_uuid'] ) && ! is_array( $payload['booking_uuid'] ) ) {
            $v = trim( (string) $payload['booking_uuid'] );
            if ( $v !== '' ) return $v;
        }

        // 2. Alias historique rk_booking_uuid
        if ( isset( $payload['rk_booking_uuid'] ) && ! is_array( $payload['rk_booking_uuid'] ) ) {
            $v = trim( (string) $payload['rk_booking_uuid'] );
            if ( $v !== '' ) return $v;
        }

        // 3. Objet imbriqué appointment.booking_uuid
        if ( isset( $payload['appointment'] ) && is_array( $payload['appointment'] ) ) {
            if ( isset( $payload['appointment']['booking_uuid'] ) && ! is_array( $payload['appointment']['booking_uuid'] ) ) {
                $v = trim( (string) $payload['appointment']['booking_uuid'] );
                if ( $v !== '' ) return $v;
            }
        }

        // 4. Éventuel custom field, si SSA l'expose un jour ainsi
        if ( isset( $payload['custom_fields'] ) && is_array( $payload['custom_fields'] ) ) {
            if ( isset( $payload['custom_fields']['booking_uuid'] ) && ! is_array( $payload['custom_fields']['booking_uuid'] ) ) {
                $v = trim( (string) $payload['custom_fields']['booking_uuid'] );
                if ( $v !== '' ) return $v;
            }
        }

        return '';
    }

    /**
     * AJOUT (demande utilisateur — afficher le nom du coach sur la carte
     * réservation) — extrait le nom du mentor/coach depuis un payload
     * webhook SSA ou un tableau $data d'action native SSA, sur le même
     * modèle qu'extract_booking_uuid() ci-dessus : plusieurs formes
     * possibles selon le canal (webhook REST vs hook PHP natif), aucune
     * donnée n'est devinée — uniquement recherchée à plusieurs
     * emplacements plausibles du payload SSA réel.
     *
     * Chemins acceptés (par ordre de priorité) :
     *   $payload['mentor']                    — champ plat le plus courant
     *                                            dans les payloads SSA observés
     *                                            (voir Dashboard.php, ancien
     *                                            commentaire "Mentor Ahmed")
     *   $payload['coach']                     — alias plat alternatif
     *   $payload['appointment']['mentor']     — objet imbriqué SSA v3+
     *   $payload['appointment_type']['title'] — repli : le titre du type de
     *                                            rendez-vous contient souvent
     *                                            le nom du mentor dans la
     *                                            config SSA de ce site
     *                                            (ex. "Mentor Ahmed")
     *
     * @param  array $payload Payload webhook ou $data d'action native SSA.
     * @return string Nom du coach, ou '' si non trouvé.
     */
    public static function extract_coach_name( array $payload ): string {
        // 1. Champ plat mentor
        if ( isset( $payload['mentor'] ) && ! is_array( $payload['mentor'] ) ) {
            $v = trim( (string) $payload['mentor'] );
            if ( $v !== '' ) return $v;
        }

        // 2. Alias plat coach
        if ( isset( $payload['coach'] ) && ! is_array( $payload['coach'] ) ) {
            $v = trim( (string) $payload['coach'] );
            if ( $v !== '' ) return $v;
        }

        // 3. Objet imbriqué appointment.mentor
        if ( isset( $payload['appointment'] ) && is_array( $payload['appointment'] ) ) {
            if ( isset( $payload['appointment']['mentor'] ) && ! is_array( $payload['appointment']['mentor'] ) ) {
                $v = trim( (string) $payload['appointment']['mentor'] );
                if ( $v !== '' ) return $v;
            }
        }

        // 4. Repli : titre du type de rendez-vous (contient souvent le nom
        // du mentor dans la config SSA de ce site — voir doc ci-dessus).
        if ( isset( $payload['appointment_type'] ) && is_array( $payload['appointment_type'] ) ) {
            if ( isset( $payload['appointment_type']['title'] ) && ! is_array( $payload['appointment_type']['title'] ) ) {
                $v = trim( (string) $payload['appointment_type']['title'] );
                if ( $v !== '' ) return $v;
            }
        }

        return '';
    }

    // ── Méthodes internes ───────────────────────────────────────────────────

    /**
     * Extraction depuis wp_ssa_appointment_types.
     * Retourne le format [ ['id' => int, 'name' => string] ] attendu par le JS.
     *
     * @return array
     */
    private static function _fetch_from_ssa_table(): array {
        global $wpdb;
        $table = $wpdb->prefix . 'ssa_appointment_types';

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return [];
        }

        $rows = $wpdb->get_results(
            "
            SELECT
                id,
                title
            FROM {$table}
            WHERE status = 'publish'
            ORDER BY id ASC
            ",
            ARRAY_A
        );

        if ( empty( $rows ) || ! is_array( $rows ) ) {
            return [];
        }

        $types = [];
        foreach ( $rows as $row ) {
            $id   = (int) ( $row['id'] ?? 0 );
            $name = isset( $row['title'] ) ? trim( (string) $row['title'] ) : '';
            if ( $id > 0 && $name !== '' ) {
                $types[] = [
                    'id'   => $id,
                    'name' => $name,
                ];
            }
        }

        return $types;
    }
}