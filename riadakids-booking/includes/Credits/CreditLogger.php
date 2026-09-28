<?php
/**
 * RiadaKids\Credits\CreditLogger — v4.1 BUGFIX SCHÉMA
 *
 *
 * @package RiadaKids\Credits
 */

namespace RiadaKids\Credits;

if ( ! defined( 'ABSPATH' ) ) exit;

class CreditLogger {

    /**
     * Enregistre un mouvement de crédits dans wp_rk_credit_logs.
     *
     * @param int    $user_id   Utilisateur concerné
     * @param string $type      Type : purchase | booking_deduction | booking_refund |
     *                                 refund_reversal | cancellation_reversal | admin
     * @param int    $amount    Montant (positif = crédit, négatif = débit)
     * @param int    $balance   Nouveau solde après l'opération
     * @param string $note      Note lisible (bilingue FR/AR)
     */
    public static function log(
        int    $user_id,
        string $type,
        int    $amount,
        int    $balance,
        string $note = ''
    ): void {
        global $wpdb;

        $table = $wpdb->prefix . 'rk_credit_logs';

        // Vérifie que la table existe (évite erreur fatale si migration non jouée)
        if ( ! $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) ) {
            rk_log( 'CREDIT_LOG', "Table {$table} introuvable — log ignoré", 'error' );
            return;
        }

        // ─────────────────────────────────────────────────────────────────────
        // BUGFIX : noms de colonnes corrigés pour correspondre au schéma DB.
        //
        // Schéma Install.php (wp_rk_credit_logs) :
        //   action_type   VARCHAR(100)   ← anciennement 'log_type'   (FAUX)
        //   balance_after INT(11)        ← anciennement 'balance'    (FAUX)
        //   details       LONGTEXT       ← anciennement 'note'       (FAUX)
        // ─────────────────────────────────────────────────────────────────────
        $wpdb->insert(
            $table,
            [
                'user_id'      => $user_id,
                'action_type'  => sanitize_text_field( $type ),   // FIX: était 'log_type'
                'amount'       => $amount,
                'balance_after'=> max( 0, $balance ),             // FIX: était 'balance'
                'details'      => sanitize_text_field( $note ),   // FIX: était 'note'
                'created_at'   => current_time( 'mysql' ),
            ],
            [ '%d', '%s', '%d', '%d', '%s', '%s' ]
        );

        if ( $wpdb->last_error ) {
            rk_log( 'CREDIT_LOG', "Erreur insert log: {$wpdb->last_error}", 'error' );
        } else {
            rk_log( 'CREDIT_LOG', "user#{$user_id} type={$type} amount={$amount} balance={$balance}" );
        }
    }

    /**
     * Retourne l'historique des crédits d'un utilisateur.
     *
     * NOTE : La requête SELECT utilise les vrais noms de colonnes du schéma.
     *
     * @param int $user_id
     * @param int $limit
     * @return array
     */
    public static function get_history( int $user_id, int $limit = 50 ): array {
        global $wpdb;
        return (array) $wpdb->get_results( $wpdb->prepare(
            // FIX : SELECT * → sélection explicite pour éviter les confusions futures
            "SELECT id, user_id, action_type, amount, balance_after, details, created_at
               FROM {$wpdb->prefix}rk_credit_logs
              WHERE user_id = %d
              ORDER BY created_at DESC
              LIMIT %d",
            $user_id,
            $limit
        ) );
    }
}