<?php
declare( strict_types=1 );
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Extrait de class-rk-mc-admin.php — factorisation par fonctionnalité.
 * Code déplacé verbatim, aucune modification de logique.
 */
trait RK_MC_Admin_Gamif_Tabs {
    /* ══════════════════════════════════════════════════════════
       PAGE RENDER
       ══════════════════════════════════════════════════════════ */

    public static function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Permissions insuffisantes.', 'rk-my-children' ) );
        }

        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        $child_id = absint( $_GET['child_id'] ?? 0 );
        $tab      = sanitize_key( $_GET['tab']      ?? 'skills' );
        $saved    = ! empty( $_GET['saved'] );
        // phpcs:enable

        $children = self::get_all_children();
        $child    = $child_id ? self::get_child_by_id( $child_id ) : null;

        echo '<div class="wrap rk-admin-wrap">';
        printf( '<h1>%s</h1>', esc_html__( 'Gamification Enfants — RiadaKids', 'rk-my-children' ) );

        /* ── Child selector ────── */
        echo '<form method="get" action="" class="rk-admin-selector">';
        echo '<input type="hidden" name="page"  value="' . esc_attr( self::PAGE_SLUG ) . '">';
        echo '<input type="hidden" name="tab"   value="' . esc_attr( $tab ) . '">';
        echo '<select name="child_id" onchange="this.form.submit()">';
        printf( '<option value="">%s</option>', esc_html__( '— Sélectionner un enfant —', 'rk-my-children' ) );
        foreach ( $children as $c ) {
            $label = $c->child_name;
            if ( ! empty( $c->parent_name ) ) {
                $label .= ' (' . $c->parent_name . ')';
            }
            printf(
                '<option value="%s"%s>%s</option>',
                esc_attr( $c->id ),
                selected( $child_id, $c->id, false ),
                esc_html( $label )
            );
        }
        echo '</select>';
        submit_button( __( 'Voir', 'rk-my-children' ), 'secondary', 'submit', false );
        echo '</form>';

        if ( ! $child ) {
            echo '<div class="rk-admin-empty"><p>';
            esc_html_e( 'Sélectionnez un enfant pour gérer sa gamification.', 'rk-my-children' );
            echo '</p></div></div>';
            return;
        }

        /* ── Notice ────── */
        if ( $saved ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Modifications enregistrées avec succès.', 'rk-my-children' ) . '</p></div>';
        }

        /* ── Child stat bar ────── */
        echo '<div class="rk-admin-child-bar">';
        printf( '<strong>%s</strong>', esc_html( $child->child_name ) );
        if ( class_exists( 'RK_MC_Gamification_Service' ) ) {
            $pts   = RK_MC_Gamification_Service::get_total_points( $child_id );
            $level = RK_MC_Gamification_Service::compute_level( $pts );
            printf(
                ' &mdash; %s',
                esc_html( sprintf(
                    /* translators: 1=level num 2=level label 3=points */
                    __( 'Niveau %1$d (%2$s) · %3$d pts', 'rk-my-children' ),
                    $level['num'],
                    $level['label'],
                    $pts
                ) )
            );
            echo '<span class="rk-admin-progress-pill">';
            printf( '<span style="width:%d%%"></span>', intval( $level['progress'] ) );
            echo '</span>';
            printf( ' <small>%d%%</small>', intval( $level['progress'] ) );
        }
        echo '</div>';

        /* ── Tabs ────── */
        $base_url = add_query_arg(
            array( 'page' => self::PAGE_SLUG, 'child_id' => $child_id ),
            admin_url( 'admin.php' )
        );
        $tabs = array(
            'skills'   => __( 'Compétences', 'rk-my-children' ),
            'badges'   => __( 'Shawaret (Badges)', 'rk-my-children' ),
            'points'   => __( 'Niqat (Points)', 'rk-my-children' ),
            'bilans'   => __( 'Bilans (تقييمات)', 'rk-my-children' ),
            'messages' => __( 'Messages (رسائل)', 'rk-my-children' ),
        );
        echo '<nav class="nav-tab-wrapper rk-admin-tabs">';
        foreach ( $tabs as $t => $label ) {
            printf(
                '<a href="%s" class="nav-tab%s">%s</a>',
                esc_url( add_query_arg( 'tab', $t, $base_url ) ),
                ( $tab === $t ) ? ' nav-tab-active' : '',
                esc_html( $label )
            );
        }
        echo '</nav>';

        echo '<div class="rk-admin-tab-content">';
        switch ( $tab ) {
            case 'skills':
                self::render_skills_tab( $child_id );
                break;
            case 'badges':
                self::render_badges_tab( $child_id );
                break;
            case 'points':
                self::render_points_tab( $child_id );
                break;
            case 'bilans':
                self::render_bilans_tab( $child_id );
                break;
            case 'messages':
                self::render_messages_tab( $child_id );
                break;
        }
        echo '</div>';
        echo '</div>'; // .wrap
    }

    /* ══════════════════════════════════════════════════════════
       TAB: COMPÉTENCES (Skills)
       ══════════════════════════════════════════════════════════ */

    private static function render_skills_tab( int $child_id ): void {
        if ( ! class_exists( 'RK_MC_Skill_Service' ) ) {
            echo '<p>' . esc_html__( 'Service compétences non disponible.', 'rk-my-children' ) . '</p>';
            return;
        }

        $skills = RK_MC_Skill_Service::get_skills( $child_id );

        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="rk-admin-form">';
        echo '<input type="hidden" name="action"   value="rk_mc_save_skills">';
        echo '<input type="hidden" name="child_id" value="' . esc_attr( $child_id ) . '">';
        wp_nonce_field( 'rk_mc_save_skills' );

        echo '<div class="rk-skill-grid">';
        foreach ( $skills as $skill ) {
            $lvl = intval( $skill['level'] );
            $pct = intval( $skill['pct'] );
            $key = esc_attr( $skill['key'] );

            echo '<div class="rk-skill-card">';
            printf( '<label for="skill_%s"><strong>%s</strong></label>', $key, esc_html( $skill['name'] ) );

            echo '<div class="rk-skill-input-row">';
            printf(
                '<input type="range" id="skill_%1$s" name="skill_%1$s" min="0" max="10" value="%2$d"
                    oninput="document.getElementById(\'sv_%1$s\').textContent=this.value;document.getElementById(\'sp_%1$s\').style.width=(this.value*10)+\'%%\'">',
                $key, $lvl
            );
            printf( '<span class="rk-skill-val" id="sv_%s">%d</span>', $key, $lvl );
            echo '</div>';

            printf(
                '<div class="rk-skill-bar"><span id="sp_%s" style="width:%d%%"></span></div>',
                $key, $pct
            );
            echo '</div>';
        }
        echo '</div>';

        submit_button( __( 'Enregistrer les compétences', 'rk-my-children' ), 'primary', 'submit' );
        echo '</form>';
    }

    /* ══════════════════════════════════════════════════════════
       TAB: SHAWARET (Badges)
       ══════════════════════════════════════════════════════════ */

    private static function render_badges_tab( int $child_id ): void {
        if ( ! class_exists( 'RK_MC_Badge_Service' ) ) {
            echo '<p>' . esc_html__( 'Service badges non disponible.', 'rk-my-children' ) . '</p>';
            return;
        }

        $catalogue = RK_MC_Badge_Service::get_catalogue_for_child( $child_id );
        $post_url  = esc_url( admin_url( 'admin-post.php' ) );

        echo '<div class="rk-badge-admin-grid">';
        foreach ( $catalogue as $badge ) {
            $earned    = ! empty( $badge['earned'] );
            $css_class = $earned ? 'rk-badge-admin-item rk-badge-admin-item--earned' : 'rk-badge-admin-item';

            echo '<div class="' . esc_attr( $css_class ) . '">';
            echo '<div class="rk-badge-admin-item__head">';
            printf( '<strong>%s</strong>', esc_html( $badge['name'] ) );
            printf(
                ' <span class="rk-badge-cat rk-badge-cat--%s">%s</span>',
                esc_attr( $badge['cat'] ?? 'start' ),
                esc_html( $badge['cat'] ?? '' )
            );
            echo '</div>';

            if ( ! empty( $badge['desc'] ) ) {
                printf( '<p>%s</p>', esc_html( $badge['desc'] ) );
            }

            if ( $earned ) {
                $date = ! empty( $badge['earned_at'] )
                    ? date_i18n( get_option( 'date_format' ), strtotime( $badge['earned_at'] ) )
                    : __( 'Obtenu', 'rk-my-children' );
                printf( '<p class="rk-badge-admin-date">&#10003; %s</p>', esc_html( $date ) );

                echo '<form method="post" action="' . $post_url . '">';
                echo '<input type="hidden" name="action"    value="rk_mc_revoke_badge">';
                echo '<input type="hidden" name="child_id"  value="' . esc_attr( $child_id ) . '">';
                echo '<input type="hidden" name="badge_key" value="' . esc_attr( $badge['key'] ) . '">';
                wp_nonce_field( 'rk_mc_revoke_badge' );
                echo '<input type="submit" class="button button-small button-link-delete"
                    value="' . esc_attr__( 'Révoquer', 'rk-my-children' ) . '"
                    onclick="return confirm(\'' . esc_js( __( 'Révoquer cette badge ?', 'rk-my-children' ) ) . '\')">';
                echo '</form>';
            } else {
                echo '<form method="post" action="' . $post_url . '">';
                echo '<input type="hidden" name="action"    value="rk_mc_award_badge">';
                echo '<input type="hidden" name="child_id"  value="' . esc_attr( $child_id ) . '">';
                echo '<input type="hidden" name="badge_key" value="' . esc_attr( $badge['key'] ) . '">';
                wp_nonce_field( 'rk_mc_award_badge' );
                echo '<input type="submit" class="button button-primary button-small"
                    value="' . esc_attr__( 'Attribuer', 'rk-my-children' ) . '">';
                echo '</form>';
            }

            echo '</div>'; // .rk-badge-admin-item
        }
        echo '</div>';
    }

    /* ══════════════════════════════════════════════════════════
       TAB: NIQAT (Points)
       ══════════════════════════════════════════════════════════ */

    private static function render_points_tab( int $child_id ): void {
        echo '<div class="rk-points-layout">';

        /* ── Add points form ── */
        echo '<div class="rk-points-form-wrap">';
        echo '<h3>' . esc_html__( 'Ajouter des points', 'rk-my-children' ) . '</h3>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="rk-admin-form">';
        echo '<input type="hidden" name="action"   value="rk_mc_add_points">';
        echo '<input type="hidden" name="child_id" value="' . esc_attr( $child_id ) . '">';
        wp_nonce_field( 'rk_mc_add_points' );

        $sources = class_exists( 'RK_MC_Gamification_Service' )
            ? RK_MC_Gamification_Service::point_values()
            : array();

        echo '<table class="form-table"><tbody>';

        /* Source */
        echo '<tr><th scope="row"><label for="rk-pt-source">' . esc_html__( 'Source', 'rk-my-children' ) . '</label></th>';
        echo '<td><select id="rk-pt-source" name="source">';
        echo '<option value="manual">' . esc_html__( 'Manuel', 'rk-my-children' ) . '</option>';
        foreach ( $sources as $src => $val ) {
            printf(
                '<option value="%s">%s (+%d pts)</option>',
                esc_attr( $src ),
                esc_html( $src ),
                intval( $val )
            );
        }
        echo '</select></td></tr>';

        /* Points */
        echo '<tr><th scope="row"><label for="rk-pt-points">' . esc_html__( 'Points', 'rk-my-children' ) . '</label></th>';
        echo '<td><input type="number" id="rk-pt-points" name="points" value="10" min="-9999" max="9999" style="width:90px;">
              <span class="description">' . esc_html__( 'Négatif pour déduire.', 'rk-my-children' ) . '</span></td></tr>';

        /* Note */
        echo '<tr><th scope="row"><label for="rk-pt-note">' . esc_html__( 'Note', 'rk-my-children' ) . '</label></th>';
        echo '<td><input type="text" id="rk-pt-note" name="note" class="regular-text"
              placeholder="' . esc_attr__( 'Raison ou commentaire...', 'rk-my-children' ) . '"></td></tr>';

        echo '</tbody></table>';
        submit_button( __( 'Ajouter les points', 'rk-my-children' ), 'primary', 'submit' );
        echo '</form>';
        echo '</div>'; // .rk-points-form-wrap

        /* ── Points history ── */
        echo '<div class="rk-points-history-wrap">';
        echo '<h3>' . esc_html__( 'Historique (20 derniers)', 'rk-my-children' ) . '</h3>';

        $log = class_exists( 'RK_MC_Gamification_Service' )
            ? RK_MC_Gamification_Service::get_points_log( $child_id, 20 )
            : array();

        if ( empty( $log ) ) {
            echo '<p>' . esc_html__( 'Aucun point enregistré pour cet enfant.', 'rk-my-children' ) . '</p>';
        } else {
            echo '<table class="wp-list-table widefat striped">';
            echo '<thead><tr>';
            echo '<th>' . esc_html__( 'Date', 'rk-my-children' ) . '</th>';
            echo '<th>' . esc_html__( 'Points', 'rk-my-children' ) . '</th>';
            echo '<th>' . esc_html__( 'Source', 'rk-my-children' ) . '</th>';
            echo '<th>' . esc_html__( 'Note', 'rk-my-children' ) . '</th>';
            echo '</tr></thead><tbody>';

            foreach ( $log as $entry ) {
                $pts   = intval( $entry->points );
                $color = $pts >= 0 ? '#16a34a' : '#dc2626';
                echo '<tr>';
                printf( '<td>%s</td>', esc_html( date_i18n( get_option( 'date_format' ) . ' H:i', strtotime( $entry->earned_at ) ) ) );
                printf( '<td style="color:%s;font-weight:700;">%s</td>', esc_attr( $color ), esc_html( ( $pts >= 0 ? '+' : '' ) . $pts ) );
                printf( '<td><code>%s</code></td>', esc_html( $entry->source ) );
                printf( '<td>%s</td>', esc_html( $entry->note ) );
                echo '</tr>';
            }
            echo '</tbody></table>';
        }
        echo '</div>'; // .rk-points-history-wrap
        echo '</div>'; // .rk-points-layout
    }

}
