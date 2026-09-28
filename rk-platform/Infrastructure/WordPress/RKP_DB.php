<?php
declare( strict_types=1 );
/**
 * Infrastructure — RKP_DB
 *
 * Wrapper léger pour les transactions MySQL.
 * Supporte les appels imbriqués via un compteur de profondeur :
 * seul le BEGIN/COMMIT le plus extérieur touche vraiment la base.
 * Un ROLLBACK à n'importe quel niveau annule tout.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class RKP_DB {

    private static int $depth = 0;

    public static function begin(): void {
        if ( self::$depth === 0 ) {
            global $wpdb;
            $wpdb->query( 'START TRANSACTION' );
        }
        self::$depth++;
    }

    public static function commit(): void {
        if ( self::$depth <= 0 ) return;
        self::$depth--;
        if ( self::$depth === 0 ) {
            global $wpdb;

            // Un echec imbrique a condamne la transaction : on annule au
            // lieu de valider un etat partiel.
            if ( self::$failed ) {
                self::$failed = false;
                $wpdb->query( 'ROLLBACK' );
                throw new \Exception( 'Transaction annulee : echec dans une operation imbriquee.' );
            }
            // v9.36 BUGFIX — CRITIQUE : $wpdb->query() retourne false
            // en cas d'erreur SQL, mais le code ci-dessus l'ignorait
            // silencieusement. Si le COMMIT échouait (ex: erreur
            // transactionnelle, mode autocommit activé, etc.), la
            // transaction n'était jamais validée mais le code PHP
            // continuait et retournait success=true — causant la perte
            // silencieuse de toutes les données (badges insérés mais
            // jamais commitées). Solution : tracker l'erreur et lever
            // une exception pour que le caller sache que ça a échoué.
            $result = $wpdb->query( 'COMMIT' );
            if ( false === $result ) {
                self::$depth  = 0; // Reset état
                self::$failed = false;
                $error = $wpdb->last_error ?: 'Unknown COMMIT error';
                throw new \Exception( "COMMIT failed: " . $error );
            }
        }
    }

    /**
     * v9.58 — BUGFIX CRITIQUE : rollback imbrique.
     *
     * Avant, rollback() remettait $depth a 0 et emettait un ROLLBACK
     * inconditionnel. Or les transactions SONT imbriquees en pratique :
     *
     *   award_with_reason()            begin()   depth 1
     *     add_points()                                        (dans la txn)
     *       do_action('rk_mc_child_level_up')
     *         award_level_badge()      begin()   depth 2
     *           insert echoue      ->  rollback()
     *
     * Le rollback interne annulait alors TOUTE la transaction externe,
     * puis mettait depth a 0. Le code appelant continuait sans le
     * savoir : son commit() sortait immediatement (depth <= 0), aucun
     * COMMIT n'etait emis, et award_with_reason() retournait '' —
     * c'est-a-dire SUCCES — alors que rien n'avait ete ecrit en base.
     * Symptome : reponse 200, badge absent, puis 409 au clic suivant.
     *
     * Desormais : seul le niveau racine emet un vrai ROLLBACK. Un echec
     * imbrique marque la transaction comme condamnee ; le ROLLBACK est
     * emis quand la pile se devide, et commit() refuse de valider une
     * transaction marquee.
     */
    private static bool $failed = false;

    public static function rollback(): void {
        global $wpdb;

        if ( self::$depth > 1 ) {
            // Echec imbrique : on marque, on ne touche pas a la txn parente.
            self::$failed = true;
            self::$depth--;
            return;
        }

        self::$depth  = 0;
        self::$failed = false;
        $wpdb->query( 'ROLLBACK' );
    }

    /** La transaction en cours a-t-elle ete condamnee par un echec imbrique ? */
    public static function has_failed(): bool {
        return self::$failed;
    }
}