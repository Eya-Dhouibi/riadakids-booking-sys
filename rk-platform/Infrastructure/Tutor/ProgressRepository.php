<?php
declare( strict_types=1 );
/**
 * Infrastructure — ProgressRepository  (Tutor LMS)
 *
 * SEULE couche autorisée à appeler tutor_utils() pour la progression.
 * Retourne uniquement des scalaires ou des objets Domain.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class RKP_ProgressRepository {

    /** Pourcentage de complétion d'un cours pour un enfant (0–100). */
    public static function get_percent( int $course_id, int $child_id ): int {
        if ( ! function_exists( 'tutor_utils' ) ) return 0;
        return (int) tutor_utils()->get_course_completed_percent( $course_id, $child_id );
    }

    /**
     * v9.34 — Marque une leçon comme complétée pour un enfant précis,
     * appelée par le coach depuis "marquer présence" (menu déroulant
     * leçon). AUCUNE correspondance fixe séance→cours dans ce système
     * (décision explicite de l'utilisateur, 12/08/2026) : c'est le
     * coach qui désigne manuellement quelle leçon valider à chaque
     * séance marquée présente.
     *
     * Passe par l'API OFFICIELLE Tutor (tutor_utils()->mark_lesson_
     * complete(), résolu dynamiquement vers Tutor\Models\LessonModel::
     * mark_lesson_complete() via le magic __call de Tutor\Utils) —
     * jamais un INSERT direct dans wp_tutor_completed_lesson : le
     * schéma exact de cette table (colonnes au-delà de user_id/
     * lesson_id déjà confirmées ailleurs) n'est pas garanti stable
     * entre versions de Tutor, alors que l'API publique l'est.
     *
     * mark_lesson_complete() natif agit sur l'utilisateur COURANT
     * (get_current_user_id() en interne) — objectif ici étant l'enfant
     * et non le coach connecté, le contexte utilisateur est basculé
     * temporairement le temps de l'appel puis restauré, quel que soit
     * le chemin de sortie (try/finally).
     *
     * Déclenche naturellement tutor_lesson_completed_before/after —
     * donc RK_MC_Gamification_Service::on_lesson_completed(),
     * RK_MC_Booking_Bridge::on_lesson_completed() et RKP_TutorBridge
     * (Domain Event lesson.completed) se déclenchent normalement, sans
     * duplication de logique ici : XP, badges liés à la leçon, cache —
     * tout continue de passer par les mêmes canaux qu'une complétion
     * faite par l'enfant lui-même.
     *
     * Idempotent : si déjà complétée, Tutor ne réinsère pas de doublon
     * (comportement natif) — safe à rappeler plusieurs fois.
     *
     * @return bool true si l'appel a pu être effectué (ne garantit pas
     *              nécessairement un changement d'état si déjà complétée).
     */
    public static function mark_lesson_complete_for_child( int $lesson_id, int $wp_user_id ): bool {
        if ( $lesson_id <= 0 || $wp_user_id <= 0 ) return false;
        if ( ! function_exists( 'tutor_utils' ) ) return false;

        // BUGFIX (12/08/2026) — Deux problèmes successifs corrigés ici :
        //
        // 1) method_exists( $utils, 'mark_lesson_complete' ) retournait
        //    TOUJOURS false : Tutor\Utils délègue cette méthode via son
        //    magic __call(), non détectable par method_exists(). Fixé en
        //    appelant directement $utils->mark_lesson_complete().
        //
        // 2) wp_set_current_user( $wp_user_id ) — utilisé initialement
        //    pour que Tutor marque la complétion au nom de l'ENFANT et
        //    non du coach connecté — s'est avéré risqué dans CE contexte
        //    précis : l'authentification de ce endpoint REST passe par
        //    JWT (voir refresh_token() dans class-rk-coach-api.php),
        //    pas par les cookies WP classiques. Changer $current_user en
        //    plein milieu d'une requête REST authentifiée par JWT peut
        //    interférer avec des vérifications de capacité internes à
        //    Tutor (is_enrolled, permissions) déclenchées par le hook
        //    tutor_lesson_completed_after, provoquant une erreur qui
        //    remontait comme "تعذّر تسجيل الحضور" côté coach — confirmé
        //    par le fait que la présence SEULE (sans lesson_id) s'enre-
        //    gistrait normalement, isolant le problème à ce bloc précis.
        //
        //    Fix : mark_lesson_complete() est appelée SANS changer
        //    $current_user. La plupart des versions récentes de Tutor
        //    (LessonModel::mark_lesson_complete) acceptent un second
        //    paramètre $user_id optionnel — on le passe explicitement
        //    si la signature le permet (détecté via Reflection, sans
        //    exécuter d'appel test). Si la version installée ne
        //    l'accepte pas, on renonce proprement (return false) plutôt
        //    que de risquer de marquer la leçon pour le COACH par
        //    erreur (pire que ne rien faire).
        $utils = tutor_utils();

        try {
            $accepts_user_id_param = false;
            try {
                $reflection = new \ReflectionMethod( '\Tutor\Models\LessonModel', 'mark_lesson_complete' );
                $accepts_user_id_param = $reflection->getNumberOfParameters() >= 2;
            } catch ( \ReflectionException $e ) {
                // Classe/méthode introuvable en reflection — on tentera
                // quand même l'appel via tutor_utils() ci-dessous, qui
                // gère la résolution différemment (magic __call).
            }

            if ( ! $accepts_user_id_param ) {
                // Impossible de garantir que la complétion sera
                // attribuée au bon utilisateur (l'enfant, pas le coach
                // connecté) sans changer le contexte global — on
                // renonce plutôt que de risquer une donnée incorrecte.
                rkp_log( sprintf(
                    '[RK ProgressRepository] mark_lesson_complete_for_child ignoré — la version de Tutor LMS installée n\'accepte pas de user_id explicite (lesson=%d user=%d).',
                    $lesson_id, $wp_user_id
                ) );
                return false;
            }

            // v9.41 — Auto-inscription au cours Tutor si nécessaire.
            // Les vrais enfants achètent des PACKS DE CRÉDITS (produits
            // WooCommerce génériques, sans lien _rk_tutor_course_id vers
            // un cours précis) — voir RK_MC_Course_Enrollment, conçu
            // pour un modèle "1 produit = 1 cours" qui ne correspond pas
            // au modèle réel (crédits réutilisables sur n'importe quel
            // cours suivi avec le coach). Résultat : un enfant peut
            // suivre 100% des leçons d'un cours sans jamais y être
            // formellement "enrolled" côté Tutor — is_completed_course()
            // et le certificat en dépendent tous les deux et restent
            // bloqués sans cet enrollment.
            //
            // Fix à la racine : puisque c'est désormais le COACH qui
            // valide les leçons (pas l'enfant lui-même en cliquant dans
            // le cours), on auto-inscrit l'enfant au cours dès sa
            // PREMIÈRE leçon validée dans ce cours — idempotent
            // (is_enrolled() vérifié avant), order_id=0 car cette
            // inscription ne provient pas d'une commande WooCommerce.
            //
            // BUGFIX (12/08/2026, confirmé par debug) — Tutor crée par
            // défaut un enrollment au statut 'pending' quand order_id=0
            // (voir Utils::do_enroll() : $enrolment_status = 'pending'
            // sauf si un order_id de commande WooCommerce complétée est
            // fourni). is_enrolled() ne reconnaît que le statut
            // 'completed' comme "vraiment" inscrit — donc chaque appel
            // recréait un NOUVEAU enrollment 'pending' (5 doublons
            // observés en test : IDs 9829-9833), jamais reconnu par
            // is_enrolled() ni par get_completed_courses_ids_by_user().
            // Fix : filtre tutor_enroll_data (pattern documenté par
            // Tutor lui-même) pour forcer post_status='completed' sur
            // CET enrollment précis uniquement, le temps de l'appel —
            // légitime ici puisque le coach vient de valider une leçon
            // réelle, pas une inscription spéculative.
            $force_enrollment_completed = function ( array $data ) {
                $data['post_status'] = 'completed';
                return $data;
            };

            $ancestors_for_enroll = get_post_ancestors( $lesson_id );
            $course_id_for_enroll = ! empty( $ancestors_for_enroll ) ? (int) array_pop( $ancestors_for_enroll ) : 0;

            // BUGFIX (12/08/2026, confirmé par test en production — 29
            // enrollments dupliqués créés) — $utils->is_enrolled() ne
            // détecte apparemment pas les enrollments forcés au statut
            // 'completed' (probablement conçu pour les statuts natifs
            // WooCommerce comme 'pending'/'processing'). Résultat :
            // chaque appel recréait un NOUVEAU post tutor_enrolled au
            // lieu de réutiliser celui déjà créé, produisant autant de
            // certificats dupliqués dans la liste que d'appels effectués.
            // Fix : vérification directe et fiable par requête sur les
            // posts tutor_enrolled existants (n'importe quel statut),
            // plutôt que de dépendre du comportement interne incertain
            // de is_enrolled().
            $existing_enrollment = $course_id_for_enroll > 0 ? get_posts( [
                'post_type'      => 'tutor_enrolled',
                'post_parent'    => $course_id_for_enroll,
                'author'         => $wp_user_id,
                'post_status'    => 'any',
                'posts_per_page' => 1,
                'fields'         => 'ids',
            ] ) : [];
            $already_enrolled = ! empty( $existing_enrollment );

            if ( function_exists( 'rk_debug_log' ) ) {
                rk_debug_log( 'ProgressRepository — état enrollment AVANT do_enroll', [
                    'course_id'         => $course_id_for_enroll,
                    'wp_user_id'        => $wp_user_id,
                    'already_enrolled'  => $already_enrolled ? 'true (post #' . reset( $existing_enrollment ) . ')' : 'false',
                ] );
            }

            if ( $course_id_for_enroll > 0 && ! $already_enrolled ) {
                add_filter( 'tutor_enroll_data', $force_enrollment_completed );
                $enrollment_id = $utils->do_enroll( $course_id_for_enroll, 0, $wp_user_id );
                remove_filter( 'tutor_enroll_data', $force_enrollment_completed );

                if ( function_exists( 'rk_debug_log' ) ) {
                    rk_debug_log( 'ProgressRepository — résultat do_enroll (post_status forcé completed)', [
                        'course_id'      => $course_id_for_enroll,
                        'wp_user_id'     => $wp_user_id,
                        'enrollment_id'  => $enrollment_id === false ? 'FALSE (échec)' : $enrollment_id,
                    ] );
                }
            }

            do_action( 'tutor_lesson_completed_before', $lesson_id );

            if ( function_exists( 'rk_debug_log' ) ) {
                rk_debug_log( 'ProgressRepository — juste AVANT mark_lesson_complete', compact( 'lesson_id', 'wp_user_id' ) );
            }

            $utils->mark_lesson_complete( $lesson_id, $wp_user_id );

            if ( function_exists( 'rk_debug_log' ) ) {
                rk_debug_log( 'ProgressRepository — juste APRÈS mark_lesson_complete (pas de fatal)', compact( 'lesson_id', 'wp_user_id' ) );
            }

            // BUGFIX (12/08/2026, confirmé par debug en production) — La
            // complétion elle-même RÉUSSIT (vérifié : la usermeta
            // _tutor_completed_lesson_id_{id} est bien écrite). Le crash
            // se produit APRÈS, dans do_action('tutor_lesson_completed_
            // after', ...) : un listener NATIF de Tutor LMS sur ce même
            // hook fait un wp_safe_redirect() suivi d'un die() —
            // comportement voulu pour un clic web normal ("passer à la
            // leçon suivante"), fatal dans notre contexte serveur-à-
            // serveur (appel REST, pas de page à rediriger).
            //
            // Fix : on NE déclenche PAS do_action('tutor_lesson_
            // completed_after', ...) — ce qui évite le die() natif —
            // et on appelle directement, à la main, les 3 SEULS
            // listeners de CE plugin connus sur ce hook (voir audit du
            // 12/08/2026 : Gamification, BookingBridge, TutorBridge/
            // EventBus — aucun autre listener trouvé dans rk-platform).
            // Ainsi XP, badges de niveau, invalidation de cache et
            // notifications continuent de fonctionner normalement ;
            // seul le comportement de redirection web propre à Tutor
            // est court-circuité — sans jamais toucher au hook global
            // ni risquer de retirer un mauvais callback.
            if ( class_exists( 'RK_MC_Gamification_Service' ) ) {
                RK_MC_Gamification_Service::on_lesson_completed( $lesson_id, $wp_user_id );
            }
            if ( class_exists( 'RK_MC_Booking_Bridge' ) ) {
                RK_MC_Booking_Bridge::on_lesson_completed( $lesson_id );
            }
            if ( class_exists( 'RKP_TutorBridge' ) ) {
                RKP_TutorBridge::on_lesson_completed( $lesson_id, $wp_user_id );
            }

            // v9.40 — Auto-complétion du COURS quand toutes ses leçons
            // sont validées. Tutor LMS n'a AUCUN mécanisme natif pour
            // qu'un cours passe "complété" automatiquement à 100% de
            // leçons faites — c'est normalement l'élève qui doit cliquer
            // explicitement "إنهاء الدورة" sur la page du cours (confirmé
            // par la documentation Tutor : aucune API "force completion"
            // n'existe côté admin/coach). Puisque ce plugin permet au
            // coach de valider des leçons à la place de l'enfant, il est
            // cohérent de déclencher la même complétion de cours
            // automatiquement une fois la dernière leçon validée —
            // sinon le certificat (/dashboard/rk-certificats/, basé sur
            // is_completed_course()) n'apparaîtrait jamais malgré 100%
            // de progression individuelle.
            $course_id = $course_id_for_enroll;
            if ( $course_id > 0 && function_exists( 'tutor_utils' ) ) {
                $percent = (int) tutor_utils()->get_course_completed_percent( $course_id, $wp_user_id );
                if ( $percent >= 100 && class_exists( '\Tutor\Models\CourseModel' )
                     && method_exists( '\Tutor\Models\CourseModel', 'mark_course_as_completed' )
                ) {
                    try {
                        \Tutor\Models\CourseModel::mark_course_as_completed( $course_id, $wp_user_id );
                        if ( function_exists( 'rk_debug_log' ) ) {
                            rk_debug_log( 'ProgressRepository — cours auto-complété (100% des leçons)', compact( 'course_id', 'wp_user_id' ) );
                        }
                    } catch ( \Throwable $e ) {
                        rkp_log( sprintf(
                            '[RK ProgressRepository] mark_course_as_completed a échoué (non bloquant) — course=%d user=%d : %s',
                            $course_id, $wp_user_id, $e->getMessage()
                        ) );
                    }
                }
            }

            if ( function_exists( 'rk_debug_log' ) ) {
                rk_debug_log( 'ProgressRepository — 3 listeners appelés directement, sans die() natif' );
            }

            return true;
        } catch ( \Throwable $e ) {
            rkp_log( sprintf(
                '[RK ProgressRepository] mark_lesson_complete_for_child a échoué — lesson=%d user=%d : %s',
                $lesson_id, $wp_user_id, $e->getMessage()
            ) );
            return false;
        }
    }

    /**
     * Version BATCH de get_percent() — calcule le pourcentage de N cours
     * en 2 requêtes SQL groupées, au lieu de N appels tutor_utils()
     * (chacun potentiellement plusieurs requêtes internes non batchables
     * — aucune API Tutor LMS bulk native n'a été trouvée, confirmé par
     * recherche externe avant d'écrire cette méthode).
     *
     * Construite sur wp_tutor_completed_lesson (user_id, lesson_id),
     * déjà utilisée en accès SQL direct ailleurs dans ce plugin
     * (AnalyticsRepository, CoachStudentRepository) — même patron
     * défensif (SHOW TABLES avant d'interroger), pas une nouvelle
     * dépendance inventée.
     *
     * @param int[] $course_ids
     * @return array<int,int> [course_id => percent]
     */
    public static function get_percent_batch( array $course_ids, int $wp_user_id ): array {
        $course_ids = array_values( array_unique( array_map( 'intval', $course_ids ) ) );
        if ( empty( $course_ids ) || $wp_user_id <= 0 ) return [];

        global $wpdb;
        $out = array_fill_keys( $course_ids, 0 );

        $ph_courses = implode( ',', array_fill( 0, count( $course_ids ), '%d' ) );

        // 1 requête : nombre total de leçons publiées, groupé par cours
        // (jointure lesson → topic → course, structure Tutor native).
        $totals = $wpdb->get_results( $wpdb->prepare(
            "SELECT t.post_parent AS course_id, COUNT(l.ID) AS total
               FROM {$wpdb->posts} l
               JOIN {$wpdb->posts} t ON t.ID = l.post_parent
              WHERE l.post_type   = 'lesson'
                AND l.post_status = 'publish'
                AND t.post_type   = 'topics'
                AND t.post_parent IN ({$ph_courses})
           GROUP BY t.post_parent", // phpcs:ignore WordPress.DB.PreparedSQL -- $ph_courses = placeholders %d uniquement
            $course_ids
        ), ARRAY_A ) ?: [];

        $lessons_total_by_course = [];
        foreach ( $totals as $row ) {
            $lessons_total_by_course[ (int) $row['course_id'] ] = (int) $row['total'];
        }

        $ct_table = $wpdb->prefix . 'tutor_completed_lesson';
        $has_ct   = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $ct_table ) ) === $ct_table;

        // BUGFIX (12/08/2026) — même cause que get_completed_lesson_ids()
        // : cette installation n'a pas wp_tutor_completed_lesson (usermeta
        // natif à la place). Avant ce fix, l'absence de table faisait
        // silencieusement retomber sur 0% pour tous les cours, sans
        // jamais consulter le usermeta — symptôme identique (progression
        // affichée comme nulle malgré des leçons réellement complétées).
        if ( ! $has_ct ) {
            $completed_ids = array_flip( self::get_completed_lesson_ids_from_usermeta( $wp_user_id ) );
            if ( empty( $completed_ids ) ) return $out;

            // Reconstruit lessons_done par cours à partir du usermeta —
            // nécessite de savoir à quel cours appartient chaque leçon
            // complétée : une requête groupée (pas de N+1) sur les IDs
            // de leçons trouvés en usermeta, filtrée aux cours demandés.
            $lesson_ids = array_keys( $completed_ids );
            if ( empty( $lesson_ids ) ) return $out;

            $ph_lessons = implode( ',', array_fill( 0, count( $lesson_ids ), '%d' ) );
            $done = $wpdb->get_results( $wpdb->prepare(
                "SELECT t.post_parent AS course_id, COUNT(*) AS done
                   FROM {$wpdb->posts} l
                   JOIN {$wpdb->posts} t ON t.ID = l.post_parent
                  WHERE l.ID IN ({$ph_lessons})
                    AND t.post_parent IN ({$ph_courses})
               GROUP BY t.post_parent", // phpcs:ignore WordPress.DB.PreparedSQL -- $ph_lessons/$ph_courses = placeholders %d uniquement
                array_merge( $lesson_ids, $course_ids )
            ), ARRAY_A ) ?: [];

            foreach ( $done as $row ) {
                $cid   = (int) $row['course_id'];
                $total = $lessons_total_by_course[ $cid ] ?? 0;
                if ( $total > 0 ) {
                    $out[ $cid ] = (int) min( 100, round( ( (int) $row['done'] / $total ) * 100 ) );
                }
            }
            return $out;
        }

        // 1 requête : leçons complétées par CET enfant, groupées par cours
        // (jointure completed_lesson → lesson → topic → course).
        $done = $wpdb->get_results( $wpdb->prepare(
            "SELECT t.post_parent AS course_id, COUNT(*) AS done
               FROM {$ct_table} c
               JOIN {$wpdb->posts} l ON l.ID = c.lesson_id
               JOIN {$wpdb->posts} t ON t.ID = l.post_parent
              WHERE c.user_id     = %d
                AND t.post_parent IN ({$ph_courses})
           GROUP BY t.post_parent", // phpcs:ignore WordPress.DB.PreparedSQL -- $ph_courses = placeholders %d uniquement
            array_merge( [ $wp_user_id ], $course_ids )
        ), ARRAY_A ) ?: [];

        foreach ( $done as $row ) {
            $cid   = (int) $row['course_id'];
            $total = $lessons_total_by_course[ $cid ] ?? 0;
            if ( $total > 0 ) {
                $out[ $cid ] = (int) min( 100, round( ( (int) $row['done'] / $total ) * 100 ) );
            }
        }

        return $out;
    }

    /** Nombre total de leçons publiées dans un cours. */
    public static function get_lesson_count( int $course_id ): int {
        if ( ! function_exists( 'tutor_utils' ) ) return 0;
        return (int) tutor_utils()->get_lesson_count_by_course( $course_id );
    }

    /**
     * Nombre de leçons complétées par un enfant dans un cours.
     *
     * BUGFIX (13/08/2026) — Réécrite pour utiliser get_completed_lesson_
     * ids() (déjà corrigée le 12/08/2026 avec fallback usermeta) au lieu
     * d'appeler directement des méthodes natives Tutor incertaines sur
     * cette installation (method_exists() sur des méthodes magic __call
     * retourne toujours false, ET is_completed_lesson() elle-même
     * s'appuyait sur un mécanisme non confirmé fonctionnel ici — symptôme
     * observé : un cours confirmé 100% complété via is_completed_course()
     * affichait quand même "2/5 leçons" sur /dashboard/enrolled-courses/,
     * qui utilise cette méthode). Même source de vérité que le reste du
     * plugin (شارات المغامرات, certificats) : cohérence garantie.
     */
    public static function get_completed_lesson_count( int $course_id, int $child_id ): int {
        // NOTE : $child_id ici est en réalité un wp_user_id — tous les
        // appelants de cette méthode (get_progress(), etc.) lui passent
        // déjà un wp_user_id, cohérent avec le nommage historique du
        // paramètre plutôt trompeur mais inchangé pour ne pas casser la
        // signature publique existante.
        $wp_user_id = $child_id;
        if ( $wp_user_id <= 0 ) return 0;

        $lesson_ids = self::get_course_lesson_ids( $course_id );
        if ( empty( $lesson_ids ) ) return 0;

        $completed_ids = array_flip( self::get_completed_lesson_ids( $wp_user_id ) );
        $count = 0;
        foreach ( $lesson_ids as $lid ) {
            if ( isset( $completed_ids[ $lid ] ) ) $count++;
        }
        return $count;
    }

    /** @return int[] IDs des leçons publiées d'un cours (toutes topics confondus). */
    private static function get_course_lesson_ids( int $course_id ): array {
        global $wpdb;
        return array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
            "SELECT l.ID
               FROM {$wpdb->posts} l
               JOIN {$wpdb->posts} t ON t.ID = l.post_parent
              WHERE l.post_type   = 'lesson'
                AND l.post_status = 'publish'
                AND t.post_type   = 'topics'
                AND t.post_parent = %d",
            $course_id
        ) ) ?: [] );
    }

    /**
     * Une leçon donnée est-elle complétée par cet enfant ?
     * Nécessaire pour l'état visuel (coche verte / cadenas) de chaque
     * leçon dans une liste — les méthodes ci-dessus ne donnent que des
     * agrégats (compte total), pas l'état d'une leçon précise.
     */
    public static function is_lesson_completed( int $lesson_id, int $child_id ): bool {
        if ( ! function_exists( 'tutor_utils' ) ) return false;
        return (bool) tutor_utils()->is_completed_lesson( $lesson_id, $child_id );
    }

    /**
     * Version BATCH — tous les IDs de leçons complétées par un enfant,
     * en UNE requête (au lieu d'appeler is_lesson_completed() par
     * leçon, un vrai N+1 dès qu'on affiche plus qu'une poignée de
     * séances — voir la vue شارات المغامرات à plat, potentiellement
     * des centaines de séances). Même table/patron déjà utilisé pour
     * get_percent_batch() (wp_tutor_completed_lesson, déjà en accès SQL
     * direct ailleurs dans ce plugin).
     *
     * @return int[] IDs de leçons complétées par cet enfant (wp_user_id)
     */
    public static function get_completed_lesson_ids( int $wp_user_id ): array {
        if ( $wp_user_id <= 0 ) return [];
        global $wpdb;
        $table = $wpdb->prefix . 'tutor_completed_lesson';

        // BUGFIX (12/08/2026, confirmé par debug en production) — Sur
        // CETTE installation, wp_tutor_completed_lesson N'EXISTE PAS :
        // Tutor stocke la complétion en usermeta natif
        // (_tutor_completed_lesson_id_{lesson_id} = timestamp), pas
        // dans une table SQL dédiée. L'ancien code retournait [] dès
        // que la table était absente — silencieusement, sans jamais
        // vérifier le format usermeta — ce qui faisait apparaître
        // TOUTES les leçons comme "locked" côté شارات المغامرات même
        // après une complétion réussie (le SEUL symptôme observable
        // était une carte jamais débloquée, sans aucune erreur).
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return self::get_completed_lesson_ids_from_usermeta( $wp_user_id );
        }

        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT lesson_id FROM {$table} WHERE user_id = %d",
            $wp_user_id
        ) );
        return array_map( 'intval', $ids ?: [] );
    }

    /**
     * Fallback usermeta natif (_tutor_completed_lesson_id_{id}) pour
     * les installations Tutor LMS qui n'utilisent pas la table SQL
     * wp_tutor_completed_lesson — voir get_completed_lesson_ids().
     * Une seule requête (get_user_meta sans clé précise ramène TOUT
     * le usermeta de l'utilisateur), filtrée en PHP — pas de N+1.
     *
     * @return int[]
     */
    private static function get_completed_lesson_ids_from_usermeta( int $wp_user_id ): array {
        $all_meta = get_user_meta( $wp_user_id );
        if ( ! is_array( $all_meta ) ) return [];

        $prefix = '_tutor_completed_lesson_id_';
        $ids    = [];
        foreach ( array_keys( $all_meta ) as $meta_key ) {
            if ( strpos( $meta_key, $prefix ) === 0 ) {
                $lesson_id = (int) substr( $meta_key, strlen( $prefix ) );
                if ( $lesson_id > 0 ) $ids[] = $lesson_id;
            }
        }
        return $ids;
    }

    /**
     * Dernière leçon complétée par un enfant dans un cours.
     * Retourne un RKP_Lesson (Domain object) ou null.
     */
    public static function get_last_completed_lesson( int $course_id, int $child_id ): ?RKP_Lesson {
        if ( ! function_exists( 'tutor_utils' ) ) return null;
        $utils = tutor_utils();

        if ( ! method_exists( $utils, 'get_last_completed_lesson_by_course' ) ) return null;

        $lesson = $utils->get_last_completed_lesson_by_course( $course_id, $child_id );
        if ( ! $lesson ) return null;

        return RKP_LessonRepository::find( (int) $lesson->ID );
    }

    /**
     * Prochaine leçon (ou devoir) à faire dans un cours.
     * Retourne un RKP_Lesson (Domain object) ou null.
     */
    public static function get_next_lesson( int $course_id, int $child_id ): ?RKP_Lesson {
        if ( ! function_exists( 'tutor_utils' ) ) return null;
        $utils = tutor_utils();

        if ( ! method_exists( $utils, 'get_next_assignment_or_lesson' ) ) return null;

        $lesson = $utils->get_next_assignment_or_lesson( $course_id, $child_id );
        if ( ! $lesson ) return null;

        return RKP_LessonRepository::find( (int) $lesson->ID );
    }
}