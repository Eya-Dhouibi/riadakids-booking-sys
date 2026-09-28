<?php
declare( strict_types=1 );
/**
 * RK_MC_Adventures_Filter_Service
 *
 * Filtrage AJAX de la grille "مغامراتي" (My Adventures) — remplace le
 * filtrage 100% client-side (JS masquant des cartes déjà rendues) par
 * un aller-retour serveur : au clic sur une catégorie, seuls les cours
 * de cette catégorie sont interrogés puis renvoyés déjà rendus en HTML.
 *
 * Réutilise RKP_LearningQueryService (Domain layer) pour la progression
 * et la catégorie — aucun accès direct à tutor_utils()/$wpdb ici, comme
 * dans enrolled-courses.php dont cette classe reprend la logique de
 * construction des données.
 *
 * @package RK_My_Children
 * @since   9.4.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class RK_MC_Adventures_Filter_Service {

    const NONCE_ACTION = 'rk_mc_adv_filter';

    public static function init(): void {
        add_action( 'wp_ajax_rk_mc_filter_adventures', [ __CLASS__, 'ajax_filter' ] );
        // Connecté uniquement — aucun accès invité au dashboard enfant,
        // donc pas de add_action( 'wp_ajax_nopriv_...' ) ici (même choix
        // que RK_MC_Notification_Service pour les endpoints du dashboard).
    }

    /**
     * Point d'entrée AJAX : renvoie le HTML de la grille filtrée +
     * le compte total (utilisé par le JS pour mettre à jour le label
     * "الكل" sans avoir à recompter côté client).
     */
    public static function ajax_filter(): void {
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );

        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            wp_send_json_error( 'unauthenticated', 403 );
        }

        if ( ! class_exists( 'RK_MC_Tutor_Dashboard' ) || ! class_exists( 'RKP_LearningQueryService' ) ) {
            wp_send_json_error( 'dependencies_missing', 500 );
        }

        $child = RK_MC_Tutor_Dashboard::get_child_for_template();
        if ( ! $child ) {
            wp_send_json_error( 'child_not_resolved', 403 );
        }

        // phpcs:disable WordPress.Security.NonceVerification -- nonce déjà vérifié ci-dessus via check_ajax_referer().
        $category_slug = isset( $_POST['category'] ) ? sanitize_title( wp_unslash( $_POST['category'] ) ) : 'all';
        $status_filter = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : '';
        // phpcs:enable

        // Le dropdown de statut n'expose plus que "قيد التقدم"/"مكتملة"
        // (voir enrolled-courses.php) — "الكل" et "لم تبدأ بعد" ont été
        // retirés. Le JS envoie une chaîne vide pour représenter l'état
        // neutre (aucun filtre choisi, ex. au premier chargement ou après
        // clic sur "اكتشف المغامرات") : on la traduit ici en 'all' en
        // interne pour réutiliser get_filtered_courses() sans le modifier.
        // Toute autre valeur inattendue (requête forgée) replie aussi sur
        // 'all' plutôt que d'accepter n'importe quoi.
        $allowed_statuses = [ 'in-progress', 'completed' ];
        if ( ! in_array( $status_filter, $allowed_statuses, true ) ) {
            $status_filter = 'all';
        }

        $courses = self::get_filtered_courses( $child, $category_slug, $status_filter );

        ob_start();
        self::render_cards( $courses, (int) $child->id, $status_filter );
        $html = ob_get_clean();

        wp_send_json_success( [
            'html'  => $html,
            'count' => count( $courses ),
        ] );
    }

    /**
     * Reprend la même construction de données que enrolled-courses.php
     * (catalogue complet Tutor LMS + progression via RKP_LearningQueryService),
     * en ajoutant les filtres catégorie/statut demandés côté serveur.
     *
     * @param object $child          Enfant résolu (RK_MC_Tutor_Dashboard::get_child_for_template()).
     * @param string $category_slug  Slug de catégorie ('all' = pas de filtre).
     * @param string $status_filter  'all' | 'not-started' | 'in-progress' | 'completed'
     *                               — filtre ISOLÉ (rk-adv2__status-dd), indépendant du
     *                               filtre catégorie ci-dessus ; combiné ici uniquement
     *                               au niveau de la requête, jamais dans le DOM/JS.
     *                               Le toggle "إخفاء المكتملة" a été retiré : le cas
     *                               "masquer les complétées" équivaut désormais à choisir
     *                               n'importe quelle valeur autre que "completed" ici.
     * @return array<int, array<string, mixed>>
     */
    /**
     * @since 4.20.4 — wrapper PUBLIC pour l'endpoint mobile
     * GET /rk/v1/children/{id}/adventures (class-rk-adventures-rest.php).
     * Appelle la MÊME méthode privée que ajax_filter() (web) : aucune
     * requête ni calcul de progression réécrit pour le mobile.
     */
    public static function get_courses_for_child( object $child, string $category_slug = 'all', string $status_filter = 'all' ): array {
        return self::get_filtered_courses( $child, $category_slug, $status_filter );
    }

    /**
     * @since 4.20.4 — expose publiquement le MÊME mapping nom-catégorie →
     * clé d'icône que enrolled-courses.php (variable locale
     * $rk_adv_category_icon dans ce fichier-là) : une seule table de
     * vérité, le web garde sa closure locale inchangée, le mobile
     * consomme celle-ci via l'API plutôt que de la redupliquer côté
     * Flutter.
     */
    public static function category_icon_key( string $cat_name ): string {
        $map = [
            'الذكاء الاصطناعي'          => 'filter-ai',
            'ريادة الأعمال'             => 'filter-business',
            'المنطق والبرمجة'           => 'filter-logic',
            'التواصل باللغة الإنجليزية' => 'filter-english',
            'التواصل باللغة الانجليزية' => 'filter-english', // variante orthographique observée en prod
        ];
        foreach ( $map as $needle => $icon_key ) {
            if ( false !== mb_strpos( $cat_name, $needle ) ) {
                return $icon_key;
            }
        }
        return 'filter-business';
    }

    private static function get_filtered_courses( object $child, string $category_slug, string $status_filter ): array {
        global $wpdb;

        $child_id = (int) $child->id;
        $wp_uid   = (int) ( $child->wp_user_id ?? 0 );
        $bt       = $wpdb->prefix . 'rk_bookings';

        $enrolled_ids = $wpdb->get_col(
            "SELECT ID FROM {$wpdb->posts}
              WHERE post_type = 'courses' AND post_status = 'publish'
              ORDER BY post_title ASC"
        );
        $enrolled_ids = array_map( 'intval', (array) $enrolled_ids );

        $courses = [];

        foreach ( $enrolled_ids as $cid ) {
            $course = get_post( $cid );
            if ( ! $course || 'publish' !== $course->post_status ) continue;

            $progress = ( $wp_uid && class_exists( 'RKP_LearningQueryService' ) )
                ? RKP_LearningQueryService::get_progress( $cid, $wp_uid )
                : null;

            $pct           = $progress ? max( 0, min( 100, (int) $progress->percent ) ) : 0;
            $lessons_total = $progress ? (int) $progress->lessons_total : 0;
            $lessons_done  = $progress ? (int) $progress->lessons_done  : 0;

            $category = class_exists( 'RKP_LearningQueryService' )
                ? RKP_LearningQueryService::get_primary_category( $cid )
                : '';

            $cat_slug = '' !== $category ? sanitize_title( $category ) : '';

            // ── Filtre catégorie : la comparaison porte sur le MÊME slug
            //    (sanitize_title) que celui posé en data-category sur
            //    chaque carte côté template — garantit une correspondance
            //    exacte quel que soit l'alphabet (arabe compris). ──
            if ( 'all' !== $category_slug && $cat_slug !== $category_slug ) {
                continue;
            }

            $is_completed = $pct >= 100;

            // ── Filtre de statut — isolé (rk-adv2__status-dd) : calcule
            //    le même statut à 3 valeurs que celui affiché sur chaque
            //    carte (badge "مكتملة"/"قيد التقدم"/"لم تبدأ بعد" dans
            //    render_cards() ci-dessous), pour une correspondance
            //    exacte entre ce qui est filtré et ce qui est affiché. ──
            if ( 'all' !== $status_filter ) {
                if ( $is_completed )   { $course_status = 'completed'; }
                elseif ( $pct > 0 )    { $course_status = 'in-progress'; }
                else                   { $course_status = 'not-started'; }

                if ( $course_status !== $status_filter ) {
                    continue;
                }
            }

            $thumb_id  = get_post_thumbnail_id( $cid );
            $thumb_url = $thumb_id ? (string) wp_get_attachment_image_url( $thumb_id, 'medium_large' ) : '';

            $next_name = '';
            $next_date = '';
            if ( $bt ) {
                $nxt = $wpdb->get_row( $wpdb->prepare(
                    "SELECT session_name, appointment, booking_id FROM {$bt}
                      WHERE child_id = %d AND course_id = %d
                        AND status IN ('confirmed','rescheduled') AND appointment > NOW()
                      ORDER BY appointment ASC LIMIT 1",
                    $child_id, $cid
                ) );
                if ( $nxt ) {
                    $next_name = (string) ( $nxt->session_name ?? '' );
                    $ts_nxt    = function_exists( 'rk_mc_appt_timestamp' ) ? rk_mc_appt_timestamp( (string) ( $nxt->appointment ?? '' ) ) : 0;
                    $next_date = $ts_nxt && function_exists( 'rk_mc_appt_format' )
                        ? rk_mc_appt_format( (string) $nxt->appointment, 'j M Y — H:i', (int) ( $nxt->booking_id ?? 0 ) )
                        : '';
                }
            }

            $courses[] = [
                'id'            => $cid,
                'title'         => $course->post_title,
                'permalink'     => (string) get_permalink( $cid ),
                'category'      => $category,
                'cat_slug'      => $cat_slug,
                'pct'           => $pct,
                'thumb_url'     => $thumb_url,
                'lessons_done'  => $lessons_done,
                'lessons_total' => $lessons_total,
                'is_completed'  => $is_completed,
                'next_name'     => $next_name,
                'next_date'     => $next_date,
            ];
        }

        return $courses;
    }

    /**
     * État vide RÉUTILISANT le même composant visuel que l'état vide
     * global du Dashboard (voir enrolled-courses.php, bloc "aucun cours
     * publié" : même icône, mêmes classes rk-adv2__empty*) — pour que
     * l'apparence soit identique, comme demandé, plutôt qu'un simple
     * message texte.
     *
     * Le bouton "اكتشف المغامرات" réinitialise le filtre de statut
     * (data-rk-adv-reset-filter) plutôt que de renvoyer vers l'accueil :
     * l'utilisateur est déjà sur le Dashboard, un vide dû au filtre doit
     * lui permettre d'un clic de revenir à la vue complète.
     *
     * @param string $status_filter 'in-progress' | 'completed'
     */
    private static function render_empty_state( string $status_filter ): void {
        $messages = [
            'completed'   => __( 'لا توجد مغامرات مكتملة بعد. أكمل مغامراتك الحالية لتظهر هنا!', 'rk-my-children' ),
            'in-progress' => __( 'لا توجد مغامرات قيد التقدم حاليًا.', 'rk-my-children' ),
        ];
        $message = $messages[ $status_filter ] ?? __( 'لا توجد مغامرات تطابق هذا الفلتر حاليًا.', 'rk-my-children' );

        $titles = [
            'completed'   => __( 'لا مغامرات مكتملة بعد!', 'rk-my-children' ),
            'in-progress' => __( 'لا مغامرات قيد التقدم!', 'rk-my-children' ),
        ];
        $title = $titles[ $status_filter ] ?? __( 'لا توجد نتائج', 'rk-my-children' );
        ?>
        <div class="rk-adv2__empty rk-adv2__empty-filtered">
            <img src="<?php echo esc_url( RK_MC_URL . 'assets/img/illustrations/empty-course.webp' ); ?>"
                 alt=""
                 class="rk-adv2__empty-illustration"
                 loading="lazy"
                 width="220" height="220">
            <h3 class="rk-adv2__empty-title"><?php echo esc_html( $title ); ?></h3>
            <p class="rk-adv2__empty-text"><?php echo esc_html( $message ); ?></p>
            <button type="button"
                    class="rk-adv2__cta rk-adv2__cta--go rk-adv2__empty-cta"
                    data-rk-adv-reset-filter>
                <?php esc_html_e( 'اكتشف المغامرات', 'rk-my-children' ); ?>
                <svg class="rk-adv2__cta-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" aria-hidden="true">
                    <polyline points="15 18 9 12 15 6"/>
                </svg>
            </button>
        </div>
        <?php
    }

    /**
     * Rend le HTML des cartes (identique au balisage de
     * enrolled-courses.php, extrait ici pour être réutilisable au
     * chargement initial de la page ET en réponse AJAX — voir
     * enrolled-courses.php qui appelle désormais cette même méthode).
     *
     * @param array<int, array<string, mixed>> $courses
     * @param int    $child_id
     * @param string $status_filter Statut actuellement sélectionné dans
     *                               rk-adv2__status-dd ('in-progress' ou
     *                               'completed') — utilisé pour adapter
     *                               le message de l'état vide si $courses
     *                               est vide après filtrage.
     */
    public static function render_cards( array $courses, int $child_id, string $status_filter = 'all' ): void {
        if ( empty( $courses ) ) {
            self::render_empty_state( $status_filter );
            return;
        }

        $param = class_exists( 'RK_MC_Tutor_Dashboard' ) ? RK_MC_Tutor_Dashboard::child_param() : '';

        foreach ( $courses as $c ) :
            $pct          = (int) $c['pct'];
            $cat_slug     = (string) $c['cat_slug'];
            $is_completed = (bool) $c['is_completed'];

            if ( $is_completed )  { $status_key = 'completed';   $status_label = __( 'مكتملة',      'rk-my-children' ); }
            elseif ( $pct > 0 )   { $status_key = 'in-progress'; $status_label = __( 'قيد التقدم',  'rk-my-children' ); }
            else                  { $status_key = 'not-started'; $status_label = __( 'لم تبدأ بعد', 'rk-my-children' ); }

            $cert_url = class_exists( 'RKP_LearningQueryService' )
                ? add_query_arg( 'child_id', $child_id, RKP_LearningQueryService::get_dashboard_url( 'rk-certificats' ) )
                : '#';

            // La carte "افتح المغامرة" pointe désormais vers la page custom
            // "Adventure" du dashboard plutôt que le permalink Tutor natif du
            // cours — voir templates/dashboard/rk-adventure.php.
            //
            // $param (child_param()) commence toujours par '?' (ex.
            // '?rk_tab=xxx' ou '?child_id=NN') ou est vide : le concaténer
            // directement après un premier add_query_arg('course_id', ...)
            // produirait une URL invalide à deux '?' (ex. '...?course_id=42?rk_tab=xxx').
            // On parse donc $param en tableau et on fusionne les deux jeux de
            // paramètres en un seul appel à add_query_arg().
            $adventure_extra_args = [ 'course_id' => (int) $c['id'] ];
            if ( '' !== $param ) {
                wp_parse_str( ltrim( $param, '?' ), $adventure_parsed );
                $adventure_extra_args = array_merge( $adventure_parsed, $adventure_extra_args );
            }
            $adventure_url = class_exists( 'RKP_LearningQueryService' )
                ? add_query_arg( $adventure_extra_args, RKP_LearningQueryService::get_dashboard_url( 'rk-adventure' ) )
                : ( ! empty( $c['permalink'] ) ? $c['permalink'] . $param : '#' ); // repli sûr si le service n'est pas chargé
        ?>
        <article class="rk-adv2__card rk-adv2__card--<?php echo esc_attr( $status_key ); ?>"
                 data-rk-adv-card
                 data-category="<?php echo esc_attr( $cat_slug ); ?>"
                 data-completed="<?php echo $is_completed ? '1' : '0'; ?>">

            <div class="rk-adv2__media">
                <?php if ( ! empty( $c['thumb_url'] ) ) : ?>
                <img src="<?php echo esc_url( $c['thumb_url'] ); ?>" alt="" loading="lazy">
                <?php else : ?>
                <div class="rk-adv2__media-placeholder">
                    <?php echo wp_kses_post( rk_mc_svg( 'book-open', [ 'class' => 'rk-adv2__media-placeholder-ico' ] ) ); ?>
                </div>
                <?php endif; ?>

                <span class="rk-adv2__pct-chip"><?php echo (int) $pct; ?>%</span>

                <?php if ( $is_completed ) : ?>
                <span class="rk-adv2__done-badge">
                    <?php echo wp_kses_post( rk_mc_svg( 'check', [] ) ); ?>
                    <?php esc_html_e( 'مكتملة', 'rk-my-children' ); ?>
                </span>
                <?php endif; ?>
            </div>

            <div class="rk-adv2__body">
                <?php if ( '' !== $c['category'] ) : ?>
                <div class="rk-adv2__cat">
                    <?php echo wp_kses_post( rk_mc_svg( 'book', [] ) ); ?>
                    <?php echo esc_html( $c['category'] ); ?>
                </div>
                <?php endif; ?>

                <h3 class="rk-adv2__card-title"><?php echo esc_html( $c['title'] ); ?></h3>

                <div class="rk-adv2__status rk-adv2__status--<?php echo esc_attr( $status_key ); ?>">
                    <?php echo esc_html( $status_label ); ?>
                </div>

                <div class="rk-adv2__progress-row">
                    <span class="rk-adv2__progress-pct"><?php echo (int) $pct; ?>%</span>
                    <?php if ( $c['lessons_total'] > 0 ) : ?>
                    <span class="rk-adv2__progress-lessons">
                        <?php printf(
                            /* translators: 1: lessons done, 2: lessons total */
                            esc_html__( 'الحصة: %1$d/%2$d', 'rk-my-children' ),
                            $c['lessons_done'], $c['lessons_total']
                        ); ?>
                    </span>
                    <?php endif; ?>
                </div>
                <div class="rk-adv2__bar">
                    <span style="width:<?php echo (int) $pct; ?>%"></span>
                </div>

                <?php if ( ! $is_completed && ( ! empty( $c['next_name'] ) || ! empty( $c['next_date'] ) ) ) : ?>
                <div class="rk-adv2__next">
                    <?php echo wp_kses_post( rk_mc_svg( 'calendar', [] ) ); ?>
                    <?php if ( ! empty( $c['next_name'] ) ) : ?>
                    <span class="rk-adv2__next-name"><?php echo esc_html( $c['next_name'] ); ?></span>
                    <?php endif; ?>
                    <?php if ( ! empty( $c['next_date'] ) ) : ?>
                    <span class="rk-adv2__next-date">— <?php echo esc_html( $c['next_date'] ); ?></span>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php if ( $is_completed ) : ?>
                <a href="<?php echo esc_url( $cert_url ); ?>" class="rk-adv2__cta rk-adv2__cta--cert">
                    <?php echo wp_kses_post( rk_mc_svg( 'award', [] ) ); ?>
                    <?php esc_html_e( 'شاهد الشهادة', 'rk-my-children' ); ?>
                </a>
                <?php else : ?>
                <a href="<?php echo esc_url( $adventure_url ); ?>" class="rk-adv2__cta rk-adv2__cta--go">
                    <?php esc_html_e( 'افتح المغامرة', 'rk-my-children' ); ?>
                    <svg class="rk-adv2__cta-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" aria-hidden="true">
                        <polyline points="15 18 9 12 15 6"/>
                    </svg>
                </a>
                <?php endif; ?>
            </div>
        </article>
        <?php
        endforeach;
    }
}