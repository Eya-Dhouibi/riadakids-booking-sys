<?php
declare( strict_types=1 );
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Extrait de class-rk-mc-admin.php — factorisation par fonctionnalité.
 * Code déplacé verbatim, aucune modification de logique.
 */
trait RK_MC_Admin_Content_Tabs {
    /* ══════════════════════════════════════════════════════════
       HANDLER: BILANS
       ══════════════════════════════════════════════════════════ */

    public static function handle_save_assessment(): void {
        self::require_admin();
        self::verify_nonce( 'rk_mc_save_assessment' );
        $child_id = absint( $_POST['child_id'] ?? 0 );
        if ( ! $child_id || ! class_exists( 'RK_MC_Assessment_Service' ) ) {
            wp_die( esc_html__( 'Données invalides.', 'rk-my-children' ) );
        }

        $data = array(
            'child_id'     => $child_id,
            'assessed_at'  => sanitize_text_field( $_POST['assessed_at'] ?? current_time( 'Y-m-d' ) ),
            'rating'       => absint( $_POST['rating'] ?? 3 ),
            'summary'      => sanitize_textarea_field( $_POST['summary'] ?? '' ),
            'strengths'    => array_filter( array_map( 'sanitize_text_field', (array) ( $_POST['strengths'] ?? [] ) ) ),
            'developments' => array_filter( array_map( 'sanitize_text_field', (array) ( $_POST['developments'] ?? [] ) ) ),
            'notes'        => sanitize_textarea_field( $_POST['notes'] ?? '' ),
            'skill_scores' => array_map( 'absint', (array) ( $_POST['skill_scores'] ?? [] ) ),
        );

        $edit_id = absint( $_POST['edit_id'] ?? 0 );
        if ( $edit_id ) {
            RK_MC_Assessment_Service::update( $edit_id, $data );
        } else {
            RK_MC_Assessment_Service::create( $data );
        }

        wp_safe_redirect( self::tab_url( $child_id, 'bilans', '1' ) );
        exit;
    }

    public static function handle_delete_assessment(): void {
        self::require_admin();
        self::verify_nonce( 'rk_mc_delete_assessment' );
        $child_id = absint( $_POST['child_id'] ?? 0 );
        $bilan_id = absint( $_POST['bilan_id'] ?? 0 );
        if ( $bilan_id && class_exists( 'RK_MC_Assessment_Service' ) ) {
            RK_MC_Assessment_Service::delete( $bilan_id );
        }
        wp_safe_redirect( self::tab_url( $child_id, 'bilans' ) );
        exit;
    }

    /* ══════════════════════════════════════════════════════════
       HANDLER: MESSAGES
       ══════════════════════════════════════════════════════════ */

    public static function handle_reply_message(): void {
        self::require_admin();
        self::verify_nonce( 'rk_mc_reply_message' );
        $child_id  = absint( $_POST['child_id'] ?? 0 );
        $parent_id = absint( $_POST['parent_id'] ?? 0 );
        $channel   = sanitize_key( $_POST['channel'] ?? 'coach' );
        $body      = sanitize_textarea_field( wp_unslash( $_POST['body'] ?? '' ) );

        if ( $child_id && $parent_id && $body && class_exists( 'RK_MC_Message_Service' ) ) {
            RK_MC_Message_Service::send( $child_id, get_current_user_id(), $parent_id, $body, $channel );
        }
        wp_safe_redirect( self::tab_url( $child_id, 'messages', '1' ) );
        exit;
    }

    /* ══════════════════════════════════════════════════════════
       TAB: BILANS (Assessments)
       ══════════════════════════════════════════════════════════ */

    private static function render_bilans_tab( int $child_id ): void {
        if ( ! class_exists( 'RK_MC_Assessment_Service' ) ) {
            echo '<p>' . esc_html__( 'Service bilans non disponible.', 'rk-my-children' ) . '</p>';
            return;
        }

        $all       = RK_MC_Assessment_Service::get_all( $child_id );
        $post_url  = esc_url( admin_url( 'admin-post.php' ) );
        $skill_defs = class_exists( 'RK_MC_Skill_Service' ) ? RK_MC_Skill_Service::definitions() : [];

        /* ── Create form ── */
        echo '<div class="rk-bilan-wrap">';
        echo '<h3>' . esc_html__( 'Nouveau bilan', 'rk-my-children' ) . '</h3>';
        echo '<form method="post" action="' . $post_url . '" class="rk-admin-form">';
        echo '<input type="hidden" name="action"   value="rk_mc_save_assessment">';
        echo '<input type="hidden" name="child_id" value="' . esc_attr( $child_id ) . '">';
        echo '<input type="hidden" name="edit_id"  value="0">';
        wp_nonce_field( 'rk_mc_save_assessment' );

        echo '<table class="form-table"><tbody>';

        /* Date */
        echo '<tr><th>' . esc_html__( 'Date', 'rk-my-children' ) . '</th>';
        echo '<td><input type="date" name="assessed_at" value="' . esc_attr( current_time( 'Y-m-d' ) ) . '" required></td></tr>';

        /* Rating */
        echo '<tr><th>' . esc_html__( 'Note globale', 'rk-my-children' ) . '</th><td>';
        for ( $r = 1; $r <= 5; $r++ ) {
            printf(
                '<label style="margin-left:12px"><input type="radio" name="rating" value="%1$d"%2$s> %1$d ★</label>',
                $r,
                checked( $r, 3, false )
            );
        }
        echo '</td></tr>';

        /* Summary */
        echo '<tr><th>' . esc_html__( 'Résumé (1 phrase)', 'rk-my-children' ) . '</th>';
        echo '<td><textarea name="summary" rows="2" class="large-text" maxlength="300"></textarea></td></tr>';

        /* Strengths */
        echo '<tr><th>' . esc_html__( 'Points forts (max 3)', 'rk-my-children' ) . '</th><td>';
        for ( $i = 0; $i < 3; $i++ ) {
            echo '<input type="text" name="strengths[]" class="regular-text" style="display:block;margin-bottom:6px" maxlength="120">';
        }
        echo '</td></tr>';

        /* Developments */
        echo '<tr><th>' . esc_html__( 'Axes de développement (max 2)', 'rk-my-children' ) . '</th><td>';
        for ( $i = 0; $i < 2; $i++ ) {
            echo '<input type="text" name="developments[]" class="regular-text" style="display:block;margin-bottom:6px" maxlength="120">';
        }
        echo '</td></tr>';

        /* Skill scores */
        if ( ! empty( $skill_defs ) ) {
            echo '<tr><th>' . esc_html__( 'Scores par compétence (0-5)', 'rk-my-children' ) . '</th><td>';
            echo '<div class="rk-skill-grid" style="margin:0">';
            foreach ( $skill_defs as $key => $def ) {
                $ek = esc_attr( $key );
                echo '<div class="rk-skill-card">';
                printf( '<label for="bsk_%s"><strong>%s</strong></label>', $ek, esc_html( $def['name'] ) );
                echo '<div class="rk-skill-input-row">';
                printf(
                    '<input type="range" id="bsk_%1$s" name="skill_scores[%1$s]" min="0" max="5" value="3"
                        oninput="document.getElementById(\'bsv_%1$s\').textContent=this.value">',
                    $ek
                );
                printf( '<span class="rk-skill-val" id="bsv_%s">3</span>', $ek );
                echo '</div></div>';
            }
            echo '</div></td></tr>';
        }

        /* Notes */
        echo '<tr><th>' . esc_html__( 'Notes libres', 'rk-my-children' ) . '</th>';
        echo '<td><textarea name="notes" rows="4" class="large-text" maxlength="2000"></textarea></td></tr>';

        echo '</tbody></table>';
        submit_button( __( 'Enregistrer le bilan', 'rk-my-children' ), 'primary', 'submit' );
        echo '</form></div>';

        /* ── Existing bilans list ── */
        if ( empty( $all ) ) {
            echo '<p style="color:#888;margin-top:24px">' . esc_html__( 'Aucun bilan enregistré pour cet enfant.', 'rk-my-children' ) . '</p>';
            return;
        }

        echo '<h3 style="margin-top:32px">' . esc_html__( 'Historique des bilans', 'rk-my-children' ) . '</h3>';
        echo '<table class="wp-list-table widefat striped">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__( 'Date', 'rk-my-children' ) . '</th>';
        echo '<th>' . esc_html__( 'Note', 'rk-my-children' ) . '</th>';
        echo '<th>' . esc_html__( 'Résumé', 'rk-my-children' ) . '</th>';
        echo '<th>' . esc_html__( 'Actions', 'rk-my-children' ) . '</th>';
        echo '</tr></thead><tbody>';

        foreach ( $all as $b ) {
            echo '<tr>';
            printf( '<td>%s</td>', esc_html( date_i18n( get_option( 'date_format' ), strtotime( $b['assessed_at'] ) ) ) );
            printf( '<td>%s ★</td>', esc_html( $b['rating'] ) );
            printf( '<td>%s</td>', esc_html( wp_trim_words( $b['summary'], 12 ) ) );

            /* Delete button */
            echo '<td>';
            echo '<form method="post" action="' . $post_url . '" style="display:inline">';
            echo '<input type="hidden" name="action"   value="rk_mc_delete_assessment">';
            echo '<input type="hidden" name="child_id" value="' . esc_attr( $child_id ) . '">';
            echo '<input type="hidden" name="bilan_id" value="' . esc_attr( $b['id'] ) . '">';
            wp_nonce_field( 'rk_mc_delete_assessment' );
            printf(
                '<input type="submit" class="button button-link-delete button-small" value="%s"
                    onclick="return confirm(\'%s\')">',
                esc_attr__( 'Supprimer', 'rk-my-children' ),
                esc_js( __( 'Supprimer ce bilan ?', 'rk-my-children' ) )
            );
            echo '</form></td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    /* ══════════════════════════════════════════════════════════
       TAB: MESSAGES
       ══════════════════════════════════════════════════════════ */

    private static function render_messages_tab( int $child_id ): void {
        if ( ! class_exists( 'RK_MC_Message_Service' ) ) {
            echo '<p>' . esc_html__( 'Service messages non disponible.', 'rk-my-children' ) . '</p>';
            return;
        }

        $child    = self::get_child_by_id( $child_id );
        if ( ! $child ) {
            return;
        }
        $parent_id = (int) $child->user_id;
        $post_url  = esc_url( admin_url( 'admin-post.php' ) );
        $all_msgs  = RK_MC_Message_Service::get_all_for_child( $child_id );
        $channels  = [ 'coach' => __( 'Coach', 'rk-my-children' ), 'admin' => __( 'Admin', 'rk-my-children' ) ];

        foreach ( $channels as $ch => $ch_label ) {
            $filtered = array_filter( $all_msgs, static fn( $m ) => $m->channel === $ch );

            echo '<div class="rk-msg-admin-channel">';
            printf( '<h3>%s — %s</h3>', esc_html( $ch_label ), esc_html__( 'canal', 'rk-my-children' ) );

            if ( empty( $filtered ) ) {
                echo '<p style="color:#888">' . esc_html__( 'Aucun message.', 'rk-my-children' ) . '</p>';
            } else {
                echo '<div class="rk-msg-admin-list">';
                foreach ( $filtered as $msg ) {
                    $dir = ( (int) $msg->from_id === $parent_id ) ? 'parent →' : '← réponse';
                    $from_user = get_userdata( (int) $msg->from_id );
                    $from_name = $from_user ? $from_user->display_name : 'Inconnu';
                    printf(
                        '<div class="rk-msg-admin-item"><span class="rk-msg-admin-dir">%s</span> <strong>%s</strong>: %s <em>(%s)</em></div>',
                        esc_html( $dir ),
                        esc_html( $from_name ),
                        esc_html( $msg->body ),
                        esc_html( date_i18n( get_option( 'date_format' ) . ' H:i', strtotime( $msg->created_at ) ) )
                    );
                }
                echo '</div>';
            }

            /* Reply form */
            echo '<form method="post" action="' . $post_url . '" class="rk-admin-form" style="margin-top:12px">';
            echo '<input type="hidden" name="action"    value="rk_mc_reply_message">';
            echo '<input type="hidden" name="child_id"  value="' . esc_attr( $child_id ) . '">';
            echo '<input type="hidden" name="parent_id" value="' . esc_attr( $parent_id ) . '">';
            echo '<input type="hidden" name="channel"   value="' . esc_attr( $ch ) . '">';
            wp_nonce_field( 'rk_mc_reply_message' );
            echo '<textarea name="body" rows="2" class="large-text" placeholder="'
                . esc_attr__( 'Votre réponse…', 'rk-my-children' ) . '" required></textarea>';
            echo '<br><br>';
            submit_button( __( 'Envoyer la réponse', 'rk-my-children' ), 'primary', 'submit', false );
            echo '</form></div>';
        }
    }

}
