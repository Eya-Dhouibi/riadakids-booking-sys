<?php
declare( strict_types=1 );
/**
 * RK_Coach_Evaluations — تقييم جلسة (3 sections AJAX indépendantes).
 *
 * @package RK_Coach_Hub
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class RK_Coach_Evaluations {

    /* ─── Init ─────────────────────────────────────────────────────── */

    public static function init(): void {
        add_action( 'wp_ajax_rk_save_skills_section',   [ __CLASS__, 'ajax_save_skills' ] );
        add_action( 'wp_ajax_rk_save_general_section',  [ __CLASS__, 'ajax_save_general' ] );
        add_action( 'wp_ajax_rk_save_notes_section',    [ __CLASS__, 'ajax_save_notes' ] );
        add_action( 'wp_ajax_rk_coach_save_draft',      [ __CLASS__, 'ajax_save_draft' ] );
        add_action( 'wp_ajax_rk_get_child_bookings',    [ __CLASS__, 'ajax_get_child_bookings' ] );
    }

    /* ─── Séances d'un enfant pour une date (sélecteur du formulaire) ── */

    /**
     * Bookings du coach connecté, pour cet enfant, à cette date — permet
     * au formulaire de lier l'évaluation à UNE séance précise (booking_id)
     * plutôt qu'à la seule date, qui ne distingue pas 2 séances le même
     * jour avec le même enfant (voir doc de migration dans
     * RKP_AssessmentRepository::ensure_schema()).
     *
     * @return array<int,object> Lignes wp_rk_bookings (booking_id, appointment, session_name, program_id).
     */
    private static function get_child_bookings_for_date( int $coach_id, int $child_id, string $date ): array {
        global $wpdb;
        $bt = $wpdb->prefix . 'rk_bookings';
        $has_coach_id = (bool) $wpdb->get_var( "SHOW COLUMNS FROM `{$bt}` LIKE 'coach_id'" );

        if ( $has_coach_id ) {
            return $wpdb->get_results( $wpdb->prepare(
                "SELECT booking_id, appointment, session_name
                   FROM {$bt}
                  WHERE child_id = %d AND coach_id = %d AND DATE(appointment) = %s
                    AND status != 'cancelled'
               ORDER BY appointment ASC",
                $child_id, $coach_id, $date
            ) ) ?: [];
        }
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT booking_id, appointment, session_name
               FROM {$bt}
              WHERE child_id = %d AND DATE(appointment) = %s
                AND status != 'cancelled'
           ORDER BY appointment ASC",
            $child_id, $date
        ) ) ?: [];
    }

    /* ─── Rendu : accès refusé / séance non éligible ────────────────── */

    /**
     * Affiché à la place du formulaire quand RKP_AssessmentEligibilityService
     * refuse l'accès à un booking_id explicitement demandé dans l'URL —
     * jamais un formulaire vide ou une erreur SQL/PHP brute (demande §7/§8).
     */
    private static function render_eligibility_denied( array $eligibility, string $base_url ): void {
        $messages = [
            'booking_not_found'        => __( 'هذه الجلسة غير موجودة.', 'rk-coach-hub' ),
            'not_authorized'           => __( 'هذه الجلسة لا تخص حسابك.', 'rk-coach-hub' ),
            'attendance_absent'        => __( 'لا يمكن تقييم جلسة كان الطالب غائباً عنها.', 'rk-coach-hub' ),
            'attendance_not_confirmed' => __( 'يجب تأكيد الحضور أولاً قبل إتاحة التقييم.', 'rk-coach-hub' ),
            'service_unavailable'      => __( 'تعذر التحقق من هذه الجلسة حالياً.', 'rk-coach-hub' ),
        ];
        $code = (string) ( $eligibility['code'] ?? 'service_unavailable' );
        $msg  = $messages[ $code ] ?? __( 'لا يمكن الوصول إلى هذه الجلسة.', 'rk-coach-hub' );
        ?>
        <div class="rk-coach-page" dir="rtl">
            <div class="rk-ch-topbar">
                <h2 class="rk-ch-topbar__title">
                    <?php esc_html_e( 'تقييم جلسة', 'rk-coach-hub' ); ?>
                </h2>
            </div>
            <div class="rk-ch-section">
                <div class="rk-ch-section-body">
                    <div class="rk-ch-empty">
                        <p class="rk-ch-empty__title"><?php echo esc_html( $msg ); ?></p>
                        <a href="<?php echo esc_url( tutor_utils()->tutor_dashboard_url( 'rk-seances' ) ); ?>" class="rk-ch-btn rk-ch-btn--outline rk-ch-btn--sm" style="margin-top:12px;">
                            <?php esc_html_e( 'العودة إلى اللقاءات', 'rk-coach-hub' ); ?>
                        </a>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /* ─── Rendu page ────────────────────────────────────────────────── */

    public static function render(): void {
        $coach_id     = get_current_user_id();
        $child_id     = absint( $_GET['child_id'] ?? 0 );
        $prefill_date = ( function_exists( 'rk_validate_date' ) && rk_validate_date( $_GET['session_date'] ?? '' ) )
                        ? sanitize_text_field( $_GET['session_date'] )
                        : date( 'Y-m-d' );
        // CORRECTIF — lien évaluation ↔ séance précise (booking_id) : voir
        // doc RKP_AssessmentRepository::ensure_schema(). booking_id=0 signifie
        // "aucune séance précise sélectionnée" → comportement legacy (date+coach).
        $booking_id           = absint( $_GET['booking_id'] ?? 0 );
        // v10.4.0 (Phase 3 — flow Coach) : on distingue un booking_id
        // explicitement fourni dans l'URL (venant du bouton "تقييم الجلسة"
        // / "تعديل التقييم" de la liste des séances — voir class-rk-coach-
        // sessions.php) d'un booking auto-présélectionné plus bas quand un
        // seul lقاء existe ce jour-là (flux legacy enfant+date). Seul le
        // premier verrouille le formulaire sur CETTE séance : le coach ne
        // doit pas pouvoir en changer manuellement (demande §3).
        $rk_booking_from_url  = $booking_id > 0;
        $students     = RK_Coach_Data::get_coach_students( $coach_id );
        $skills       = self::get_skill_definitions();
        $base_url     = tutor_utils()->tutor_dashboard_url( 'rk-evaluer' );
        $ajax_url     = esc_url( admin_url( 'admin-ajax.php' ) );
        $section_nonce = wp_create_nonce( 'rk_eval_section' );

        // v10.4.0 — vérification centralisée (RKP_AssessmentEligibilityService,
        // Phase 1) AVANT tout rendu du formulaire quand une séance précise est
        // demandée. RAPPEL : ceci est une amélioration d'UX (message clair,
        // formulaire non affiché) — la SEULE source de vérité pour la
        // sécurité reste cette même vérification côté AJAX
        // (create_or_update_for_booking_authorized()), jamais ce rendu.
        $rk_eligibility = null;
        if ( $rk_booking_from_url && class_exists( 'RKP_AssessmentEligibilityService' ) ) {
            $rk_eligibility = RKP_AssessmentEligibilityService::check_coach_eligibility( $booking_id, $coach_id );
            if ( ! $rk_eligibility['allowed'] ) {
                self::render_eligibility_denied( $rk_eligibility, $base_url );
                return;
            }
            // Le backend reconstruit child_id/date depuis le booking — on
            // les utilise pour tout le reste du rendu plutôt que les
            // paramètres GET (défense en profondeur, même si le rendu seul
            // n'est pas la barrière de sécurité).
            $child_id     = $rk_eligibility['child_id'];
            $prefill_date = $rk_eligibility['assessed_at'] ?: $prefill_date;
        } elseif ( $rk_booking_from_url ) {
            // Service indisponible : on refuse d'afficher un formulaire non
            // vérifié plutôt que de faire confiance aux paramètres GET.
            self::render_eligibility_denied( [ 'code' => 'service_unavailable' ], $base_url );
            return;
        }

        // Séances du jour pour cet élève (peuple le sélecteur "اللقاء") —
        // uniquement utile en mode legacy (pas de booking_id imposé par URL).
        $day_bookings = ( ! $rk_booking_from_url && $child_id && $prefill_date )
            ? self::get_child_bookings_for_date( $coach_id, $child_id, $prefill_date )
            : [];
        // Si un seul booking existe ce jour-là et qu'aucun n'est explicitement
        // choisi dans l'URL, on le présélectionne — cas le plus courant
        // (1 enfant = 1 séance/jour), pour ne pas ajouter de clic superflu.
        if ( ! $booking_id && 1 === count( $day_bookings ) ) {
            $booking_id = (int) $day_bookings[0]->booking_id;
        }

        // Infos de la séance verrouillée (affichage lecture seule) —
        // dérivées du booking réel, jamais des paramètres GET.
        $rk_locked_booking = null;
        if ( $rk_booking_from_url && class_exists( 'RKP_CoachSessionRepository' ) ) {
            $rk_locked_booking = RKP_CoachSessionRepository::find_booking_basic( $booking_id );
        }
        $rk_locked_child_name = '';
        foreach ( $students as $st ) {
            if ( (int) $st->child_id === (int) $child_id ) { $rk_locked_child_name = $st->child_name; break; }
        }
        $rk_locked_coach_name = wp_get_current_user()->display_name ?? '';

        // CORRECTIF (revue Phase 3) — quand booking_id > 0, l'évaluation
        // est STRICTEMENT liée à ce booking : aucun repli vers
        // child_id+date+coach, qui chargerait par erreur l'évaluation
        // d'UNE AUTRE séance du même enfant le même jour. Chaque booking
        // reste une séance indépendante (voir demande de revue Phase 3,
        // point 3). Le repli date+coach ne reste utilisé QUE lorsque
        // booking_id = 0 (flux legacy sans séance précise sélectionnée).
        $existing = null;
        if ( $booking_id && class_exists( 'RK_MC_Assessment_Service' ) ) {
            $existing = RK_MC_Assessment_Service::get_for_booking( $booking_id );
            // Trouvée ou non : on s'arrête là. Pas de repli — un booking
            // sans évaluation doit afficher un formulaire vide, jamais
            // l'évaluation d'une autre séance.
        } elseif ( $child_id && class_exists( 'RK_MC_Assessment_Service' ) ) {
            $existing = RK_MC_Assessment_Service::get_for_child_date( $child_id, $prefill_date, $coach_id );
        }
        ?>
        <div class="rk-coach-page" dir="rtl">

            <!-- Top bar -->
            <div class="rk-ch-topbar">
                <h2 class="rk-ch-topbar__title">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                    <?php esc_html_e( 'تقييم جلسة', 'rk-coach-hub' ); ?>
                </h2>
                <?php if ( $existing ) : ?>
                <span class="rk-ch-pill rk-ch-pill--blue">✓ <?php esc_html_e( 'تم التقييم — يمكنك تعديله', 'rk-coach-hub' ); ?></span>
                <?php endif; ?>
            </div>

            <?php if ( $rk_booking_from_url ) : ?>
            <!-- v10.4.0 — Contexte de séance VERROUILLÉ : le coach arrive ici
                 depuis le bouton "تقييم الجلسة"/"تعديل التقييم" de la liste,
                 la séance est donc fixée par booking_id et n'est plus
                 sélectionnable manuellement (demande §3/§4). -->
            <div class="rk-ch-section" id="rk-eval-context" style="margin-bottom:20px;">
                <div class="rk-ch-eval-form__row" style="display:flex;gap:24px;flex-wrap:wrap;">
                    <div>
                        <span class="rk-ch-eval-form__label" style="display:block;"><?php esc_html_e( 'الطفل', 'rk-coach-hub' ); ?></span>
                        <strong><?php echo esc_html( $rk_locked_child_name ?: '—' ); ?></strong>
                    </div>
                    <div>
                        <span class="rk-ch-eval-form__label" style="display:block;"><?php esc_html_e( 'المدرب', 'rk-coach-hub' ); ?></span>
                        <strong><?php echo esc_html( $rk_locked_coach_name ?: '—' ); ?></strong>
                    </div>
                    <div>
                        <span class="rk-ch-eval-form__label" style="display:block;"><?php esc_html_e( 'تاريخ الجلسة', 'rk-coach-hub' ); ?></span>
                        <strong><?php echo esc_html( $rk_locked_booking ? date_i18n( 'j/m/Y', strtotime( (string) $rk_locked_booking->appointment ) ) : $prefill_date ); ?></strong>
                    </div>
                    <?php if ( $rk_locked_booking && ! empty( $rk_locked_booking->appointment ) ) : ?>
                    <div>
                        <span class="rk-ch-eval-form__label" style="display:block;"><?php esc_html_e( 'وقت الجلسة', 'rk-coach-hub' ); ?></span>
                        <strong><?php echo esc_html( date_i18n( 'H:i', strtotime( (string) $rk_locked_booking->appointment ) ) ); ?></strong>
                    </div>
                    <?php endif; ?>
                </div>
                <!-- Champs verrouillés — nécessaires au JS (postSection()) mais
                     non modifiables par le coach : la séance vient du bouton
                     cliqué, pas d'une sélection libre. -->
                <input type="hidden" id="rk-sel-child"   value="<?php echo (int) $child_id; ?>">
                <input type="hidden" id="rk-sel-date"    value="<?php echo esc_attr( $prefill_date ); ?>">
                <input type="hidden" id="rk-sel-booking" value="<?php echo (int) $booking_id; ?>" data-nonce="<?php echo esc_attr( $section_nonce ); ?>">
            </div>
            <?php else : ?>
            <!-- Sélecteur élève + date + séance (partagé par les 3 sections) -->
            <div class="rk-ch-section" id="rk-eval-selector" style="margin-bottom:20px;">
                <div class="rk-ch-eval-form__row" style="display:flex;gap:16px;flex-wrap:wrap;align-items:flex-end;">
                    <div class="rk-ch-eval-form__field" style="flex:1;min-width:180px;">
                        <label class="rk-ch-eval-form__label" for="rk-sel-child">
                            <?php esc_html_e( 'الطالب', 'rk-coach-hub' ); ?> <span style="color:#e11d48;">*</span>
                        </label>
                        <select id="rk-sel-child" style="width:100%;padding:9px 12px;border:1.5px solid var(--ch-border,#e2e8f0);border-radius:8px;font-size:.9rem;background:#fff;">
                            <option value=""><?php esc_html_e( '— اختر الطالب —', 'rk-coach-hub' ); ?></option>
                            <?php foreach ( $students as $s ) : ?>
                            <option value="<?php echo (int) $s->child_id; ?>" <?php selected( $child_id, (int) $s->child_id ); ?>>
                                <?php echo esc_html( $s->child_name ); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="rk-ch-eval-form__field" style="flex:1;min-width:160px;">
                        <label class="rk-ch-eval-form__label" for="rk-sel-date">
                            <?php esc_html_e( 'تاريخ الجلسة', 'rk-coach-hub' ); ?> <span style="color:#e11d48;">*</span>
                        </label>
                        <input type="date" id="rk-sel-date" value="<?php echo esc_attr( $prefill_date ); ?>"
                               style="width:100%;padding:9px 12px;border:1.5px solid var(--ch-border,#e2e8f0);border-radius:8px;font-size:.9rem;">
                    </div>
                    <div class="rk-ch-eval-form__field" style="flex:1;min-width:200px;">
                        <label class="rk-ch-eval-form__label" for="rk-sel-booking">
                            <?php esc_html_e( 'اللقاء', 'rk-coach-hub' ); ?>
                            <span class="rk-ch-eval-form__hint"><?php esc_html_e( '(اختياري — لتقييم لقاء محدد)', 'rk-coach-hub' ); ?></span>
                        </label>
                        <select id="rk-sel-booking" data-nonce="<?php echo esc_attr( $section_nonce ); ?>"
                                style="width:100%;padding:9px 12px;border:1.5px solid var(--ch-border,#e2e8f0);border-radius:8px;font-size:.9rem;background:#fff;">
                            <option value="0"><?php esc_html_e( '— بدون لقاء محدد —', 'rk-coach-hub' ); ?></option>
                            <?php foreach ( $day_bookings as $b ) :
                                $b_time = date_i18n( 'H:i', strtotime( (string) $b->appointment ) );
                                $b_label = trim( ( $b->session_name ?: __( 'لقاء', 'rk-coach-hub' ) ) . ' — ' . $b_time );
                            ?>
                            <option value="<?php echo (int) $b->booking_id; ?>" <?php selected( $booking_id, (int) $b->booking_id ); ?>>
                                <?php echo esc_html( $b_label ); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <a id="rk-load-existing" href="#" class="rk-ch-btn rk-ch-btn--outline rk-ch-btn--sm" style="white-space:nowrap;">
                            <?php esc_html_e( 'تحميل تقييم موجود', 'rk-coach-hub' ); ?>
                        </a>
                    </div>
                </div>
                <?php if ( $booking_id && count( $day_bookings ) > 1 ) : ?>
                <div style="margin-top:10px;padding:8px 14px;background:#fffbeb;border:1.5px solid #fde68a;border-radius:8px;font-size:.83rem;color:#92400e;">
                    <?php esc_html_e( 'هذا الطالب لديه أكثر من لقاء في هذا اليوم — تأكد من اختيار اللقاء الصحيح قبل الحفظ.', 'rk-coach-hub' ); ?>
                </div>
                <?php endif; ?>
                <div id="rk-existing-notice" style="display:none;margin-top:10px;padding:8px 14px;background:#eff6ff;border:1.5px solid #bfdbfe;border-radius:8px;font-size:.83rem;color:#1d4ed8;">
                    <?php esc_html_e( 'يوجد تقييم محفوظ لهذا الطالب في هذا التاريخ — سيتم تحديثه عند الحفظ.', 'rk-coach-hub' ); ?>
                </div>
            </div>
            <?php endif; // $rk_booking_from_url ?>

            <!-- ══ Section 1 : تقييم المهارات ════════════════════════════ -->

            <div class="rk-ch-section" style="margin-bottom:20px;">
                <div class="rk-ch-section-head">
                    <div class="rk-ch-section-head__left">
                        <h3 class="rk-ch-section-title">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                            <?php esc_html_e( 'تقييم المهارات', 'rk-coach-hub' ); ?>
                        </h3>
                    </div>
                    <span id="rk-skills-saved-badge" style="display:none;" class="rk-ch-pill rk-ch-pill--blue">
                        <?php esc_html_e( 'محفوظ ✓', 'rk-coach-hub' ); ?>
                    </span>
                </div>
                <div class="rk-ch-section-body" style="padding:8px 0;">
                    <div id="rk-skills-grid" style="display:grid;gap:12px;">
                        <?php
                        $saved_skills = $existing['skill_scores'] ?? [];
                        foreach ( $skills as $key => $label ) :
                            $val = (int) ( $saved_skills[ $key ] ?? 3 );
                        ?>
                        <div class="rk-skill-row" data-key="<?php echo esc_attr( $key ); ?>"
                             style="display:flex;align-items:center;justify-content:space-between;gap:12px;border:1.5px solid var(--ch-border,#e2e8f0);border-radius:10px;padding:12px 16px;">
                            <span style="font-weight:600;font-size:.88rem;flex:1;"><?php echo esc_html( $label ); ?></span>
                            <div style="display:flex;gap:3px;align-items:center;">
                                <?php for ( $v = 1; $v <= 5; $v++ ) : ?>
                                <button type="button"
                                        class="rk-skill-star-btn<?php echo $v <= $val ? ' active' : ''; ?>"
                                        data-key="<?php echo esc_attr( $key ); ?>"
                                        data-val="<?php echo $v; ?>"
                                        style="background:none;border:none;cursor:pointer;padding:2px;line-height:0;">
                                    <svg width="22" height="22" viewBox="0 0 24 24"
                                         fill="<?php echo $v <= $val ? '#f59e0b' : '#e2e8f0'; ?>"
                                         style="transition:fill .15s;" aria-hidden="true">
                                        <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>
                                    </svg>
                                </button>
                                <?php endfor; ?>
                                <span class="rk-skill-val-display" style="min-width:28px;text-align:center;font-size:.8rem;font-weight:700;color:#f59e0b;">
                                    <?php echo $val; ?>/5
                                </span>
                            </div>
                            <input type="hidden" class="rk-skill-input" name="skills[<?php echo esc_attr( $key ); ?>]" value="<?php echo $val; ?>">
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <div style="margin-top:16px;display:flex;align-items:center;gap:12px;">
                        <button type="button" id="rk-save-skills" class="rk-ch-btn rk-ch-btn--primary">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/></svg>
                            <?php esc_html_e( 'حفظ تقييم المهارات', 'rk-coach-hub' ); ?>
                        </button>
                        <span id="rk-skills-status" style="font-size:.82rem;color:#64748b;"></span>
                    </div>
                </div>
            </div>

<span id="rk-skills-status" style="font-size:.82rem;color:#64748b;"></span>
                </div>

                <?php
                /* ── Aperçu synchronisé rk-td-page-skills (niveau actuel de l'enfant) ── */
                $current_skills = class_exists( 'RK_MC_Skill_Service' ) && $child_id
                    ? RK_MC_Skill_Service::get_skills( $child_id ) : [];
                if ( $current_skills ) :
                ?>
                <div id="rk-td-page-skills" class="rk-td-page-skills rk-ch-preview" style="margin-top:18px;padding-top:16px;border-top:1.5px dashed var(--ch-border,#e2e8f0);">
                    <h4 style="font-size:.85rem;font-weight:700;color:#64748b;margin:0 0 12px;">
                        <?php esc_html_e( 'المستوى الحالي (متزامن مع لوحة الولي)', 'rk-coach-hub' ); ?>
                    </h4>
                    <div class="rk-skills-list">
                        <?php foreach ( $current_skills as $s ) :
                            $level = (int) ( $s['level'] ?? 0 );
                            $max   = (int) ( $s['max']   ?? 10 );
                            $pct   = $max ? (int) round( $level / $max * 100 ) : 0;
                        ?>
                        <div class="rk-skill" style="display:flex;align-items:center;gap:10px;margin-bottom:8px;">
                            <span style="min-width:110px;font-size:.8rem;font-weight:600;"><?php echo esc_html( $s['name'] ?? $s['key'] ); ?></span>
                            <div style="flex:1;height:6px;background:#f1f5f9;border-radius:4px;overflow:hidden;">
                                <div style="height:100%;width:<?php echo $pct; ?>%;background:#1b4f8c;border-radius:4px;"></div>
                            </div>
                            <span style="min-width:36px;font-size:.78rem;font-weight:700;color:#1b4f8c;text-align:left;"><?php echo $level; ?>/<?php echo $max; ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- ══ Section 2 : التقييم العام ══════════════════════════════ -->
            <div class="rk-ch-section" style="margin-bottom:20px;">
                <div class="rk-ch-section-head">
                    <div class="rk-ch-section-head__left">
                        <h3 class="rk-ch-section-title">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                            <?php esc_html_e( 'التقييم العام', 'rk-coach-hub' ); ?>
                        </h3>
                    </div>
                    <span id="rk-general-saved-badge" style="display:none;" class="rk-ch-pill rk-ch-pill--blue">
                        <?php esc_html_e( 'محفوظ ✓', 'rk-coach-hub' ); ?>
                    </span>
                </div>
                <div class="rk-ch-section-body" style="padding:8px 0;">

                    <!-- Étoiles globales -->
                    <div style="margin-bottom:20px;">
                        <label class="rk-ch-eval-form__label" style="margin-bottom:8px;display:block;">
                            <?php esc_html_e( 'النجوم الإجمالية', 'rk-coach-hub' ); ?>
                        </label>
                        <div id="rk-overall-stars" style="display:flex;gap:6px;align-items:center;">
                            <?php
                            $saved_rating = (int) ( $existing['rating'] ?? 3 );
                            for ( $i = 1; $i <= 5; $i++ ) : ?>
                            <button type="button" class="rk-overall-star-btn<?php echo $i <= $saved_rating ? ' active' : ''; ?>"
                                    data-val="<?php echo $i; ?>"
                                    style="background:none;border:none;cursor:pointer;padding:2px;line-height:0;">
                                <svg width="32" height="32" viewBox="0 0 24 24"
                                     fill="<?php echo $i <= $saved_rating ? '#f59e0b' : '#e2e8f0'; ?>"
                                     style="transition:fill .15s;" aria-hidden="true">
                                    <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>
                                </svg>
                            </button>
                            <?php endfor; ?>
                            <input type="hidden" id="rk-overall-rating" value="<?php echo $saved_rating; ?>">
                            <span id="rk-overall-rating-label"
                                  style="font-size:1rem;font-weight:800;color:#f59e0b;margin-right:8px;">
                                <?php echo $saved_rating; ?>/5
                            </span>
                        </div>
                    </div>

                    <!-- Résumé -->
                    <div style="margin-bottom:16px;">
                        <label class="rk-ch-eval-form__label" for="rk-gen-summary">
                            <?php esc_html_e( 'ملخص الجلسة', 'rk-coach-hub' ); ?>
                            <span class="rk-ch-eval-form__hint"><?php esc_html_e( '(مرئي للطالب وولي الأمر)', 'rk-coach-hub' ); ?></span>
                        </label>
                        <textarea id="rk-gen-summary" rows="3"
                                  placeholder="<?php esc_attr_e( 'ملخص موجز لما تم في الجلسة...', 'rk-coach-hub' ); ?>"
                                  style="width:100%;box-sizing:border-box;"><?php echo esc_textarea( $existing['summary'] ?? '' ); ?></textarea>
                    </div>

                    <!-- Nqats القوة -->
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;" class="rk-eval-cols">
                        <div>
                            <label class="rk-ch-eval-form__label" for="rk-gen-strengths">
                                <?php esc_html_e( 'نقاط القوة', 'rk-coach-hub' ); ?>
                                <span class="rk-ch-eval-form__hint"><?php esc_html_e( '(نقطة في كل سطر)', 'rk-coach-hub' ); ?></span>
                            </label>
                            <textarea id="rk-gen-strengths" rows="4"
                                      placeholder="<?php esc_attr_e( "يشارك بفاعلية\nيطرح أسئلة ذكية", 'rk-coach-hub' ); ?>"
                                      style="width:100%;box-sizing:border-box;"><?php echo esc_textarea( implode( "\n", (array) ( $existing['strengths'] ?? [] ) ) ); ?></textarea>
                        </div>
                        <div>
                            <label class="rk-ch-eval-form__label" for="rk-gen-areas">
                                <?php esc_html_e( 'محاور التحسين', 'rk-coach-hub' ); ?>
                                <span class="rk-ch-eval-form__hint"><?php esc_html_e( '(نقطة في كل سطر)', 'rk-coach-hub' ); ?></span>
                            </label>
                            <textarea id="rk-gen-areas" rows="4"
                                      placeholder="<?php esc_attr_e( "التركيز في نهاية الجلسة\nالنطق الصحيح للكلمات", 'rk-coach-hub' ); ?>"
                                      style="width:100%;box-sizing:border-box;"><?php echo esc_textarea( implode( "\n", (array) ( $existing['developments'] ?? [] ) ) ); ?></textarea>
                        </div>
                    </div>

                    <!-- Message pour l'enfant/parent -->
                    <div style="margin-bottom:16px;background:#fffbeb;border:1.5px solid #fde68a;border-radius:10px;padding:14px 16px;">
                        <label class="rk-ch-eval-form__label" for="rk-gen-parent-msg" style="color:#92400e;">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="vertical-align:-2px" aria-hidden="true"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                            <?php esc_html_e( 'رسالة للطالب', 'rk-coach-hub' ); ?>
                            <span class="rk-ch-eval-form__hint" style="color:#a16207;"><?php esc_html_e( '(تظهر في لوحة الطالب مع التقييم)', 'rk-coach-hub' ); ?></span>
                        </label>
                        <textarea id="rk-gen-parent-msg" rows="3"
                                  placeholder="<?php esc_attr_e( 'أحسنت! لاحظت تحسناً كبيراً في...', 'rk-coach-hub' ); ?>"
                                  style="width:100%;box-sizing:border-box;border:1.5px solid #fde68a;border-radius:8px;padding:8px 12px;background:#fffef5;font-size:.88rem;"><?php echo esc_textarea( $existing['parent_message'] ?? '' ); ?></textarea>
                    </div>

                    <div style="display:flex;align-items:center;gap:12px;">
                        <button type="button" id="rk-save-general" class="rk-ch-btn rk-ch-btn--primary">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/></svg>
                            <?php esc_html_e( 'حفظ التقييم العام', 'rk-coach-hub' ); ?>
                        </button>
                        <span id="rk-general-status" style="font-size:.82rem;color:#64748b;"></span>
                    </div>

                </div>
            </div>

            <!-- ══ Section 3 : ملاحظات خاصة ══════════════════════════════ -->
            <div class="rk-ch-section">
                <div class="rk-ch-section-head">
                    <div class="rk-ch-section-head__left">
                        <h3 class="rk-ch-section-title">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
                            <?php esc_html_e( 'ملاحظات خاصة', 'rk-coach-hub' ); ?>
                        </h3>
                    </div>
                    <span class="rk-ch-eval-form__hint"><?php esc_html_e( 'غير مرئية للطالب', 'rk-coach-hub' ); ?></span>
                </div>
                <div class="rk-ch-section-body" style="padding:8px 0;">
                    <textarea id="rk-notes" rows="3"
                              placeholder="<?php esc_attr_e( 'ملاحظات للمدرب فقط...', 'rk-coach-hub' ); ?>"
                              style="width:100%;box-sizing:border-box;margin-bottom:12px;"><?php echo esc_textarea( $existing['notes'] ?? '' ); ?></textarea>
                    <div style="display:flex;align-items:center;gap:12px;">
                        <button type="button" id="rk-save-notes" class="rk-ch-btn rk-ch-btn--ghost">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/></svg>
                            <?php esc_html_e( 'حفظ الملاحظات', 'rk-coach-hub' ); ?>
                        </button>
                        <span id="rk-notes-status" style="font-size:.82rem;color:#64748b;"></span>
                    </div>
                </div>
            </div>

        </div>

        <script>
        (function(){
            var ajaxUrl = '<?php echo $ajax_url; ?>';
            var nonce   = '<?php echo esc_js( $section_nonce ); ?>';

            /* ── Utilitaires ── */
            function getChildId()  { return document.getElementById('rk-sel-child').value; }
            function getDate()     { return document.getElementById('rk-sel-date').value; }
            function getBookingId(){ return document.getElementById('rk-sel-booking').value || '0'; }

            function showStatus(id, msg, ok) {
                var el = document.getElementById(id);
                if (!el) return;
                el.textContent = msg;
                el.style.color = ok ? '#166534' : '#b91c1c';
                if (ok) setTimeout(function(){ el.textContent = ''; }, 4000);
            }

            function postSection(action, payload, statusId, badgeId, btn) {
                if (!getChildId()) { showStatus(statusId, '<?php echo esc_js( __('يرجى اختيار طالب أولاً', 'rk-coach-hub') ); ?>', false); return; }
                if (!getDate())    { showStatus(statusId, '<?php echo esc_js( __('يرجى تحديد تاريخ الجلسة', 'rk-coach-hub') ); ?>', false); return; }
                btn.disabled = true;
                btn.textContent = '<?php echo esc_js( __('جاري الحفظ...', 'rk-coach-hub') ); ?>';
                payload.action     = action;
                payload.nonce      = nonce;
                payload.child_id   = getChildId();
                payload.session_date = getDate();
                payload.booking_id = getBookingId();
                var fd = new FormData();
                Object.keys(payload).forEach(function(k){
                    var v = payload[k];
                    if (typeof v === 'object' && !Array.isArray(v)) {
                        Object.keys(v).forEach(function(sk){ fd.append(k+'['+sk+']', v[sk]); });
                    } else {
                        fd.append(k, v);
                    }
                });
                fetch(ajaxUrl, { method:'POST', body:fd })
                    .then(function(r){ return r.json(); })
                    .then(function(res){
                        btn.disabled = false;
                        btn.innerHTML = res.success
                            ? '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg> ' + btn.getAttribute('data-label')
                            : btn.getAttribute('data-label');
                        if (res.success) {
                            showStatus(statusId, '<?php echo esc_js( __('تم الحفظ بنجاح ✓', 'rk-coach-hub') ); ?>', true);
                            var badge = document.getElementById(badgeId);
                            if (badge) badge.style.display = 'inline-flex';
                            var notice = document.getElementById('rk-existing-notice');
                            if (notice) notice.style.display = 'block';
                        } else {
                            showStatus(statusId, res.data || '<?php echo esc_js( __('حدث خطأ', 'rk-coach-hub') ); ?>', false);
                        }
                    })
                    .catch(function(){
                        btn.disabled = false;
                        showStatus(statusId, '<?php echo esc_js( __('خطأ في الشبكة', 'rk-coach-hub') ); ?>', false);
                    });
            }

            /* ── Étoiles compétences ── */
            document.querySelectorAll('.rk-skill-star-btn').forEach(function(btn){
                btn.addEventListener('click', function(){
                    var key = btn.getAttribute('data-key');
                    var val = parseInt(btn.getAttribute('data-val'), 10);
                    var row = btn.closest('.rk-skill-row');
                    row.querySelectorAll('.rk-skill-star-btn').forEach(function(b){
                        var bv = parseInt(b.getAttribute('data-val'), 10);
                        var filled = bv <= val;
                        b.classList.toggle('active', filled);
                        b.querySelector('svg').setAttribute('fill', filled ? '#f59e0b' : '#e2e8f0');
                    });
                    row.querySelector('.rk-skill-val-display').textContent = val + '/5';
                    row.querySelector('.rk-skill-input').value = val;
                });
            });

            /* ── Étoiles générales ── */
            document.querySelectorAll('.rk-overall-star-btn').forEach(function(btn){
                btn.addEventListener('click', function(){
                    var val = parseInt(btn.getAttribute('data-val'), 10);
                    document.querySelectorAll('.rk-overall-star-btn').forEach(function(b){
                        var bv = parseInt(b.getAttribute('data-val'), 10);
                        var filled = bv <= val;
                        b.classList.toggle('active', filled);
                        b.querySelector('svg').setAttribute('fill', filled ? '#f59e0b' : '#e2e8f0');
                    });
                    document.getElementById('rk-overall-rating').value = val;
                    document.getElementById('rk-overall-rating-label').textContent = val + '/5';
                });
            });

            /* ── Hover preview étoiles compétences ── */
            document.querySelectorAll('.rk-skill-star-btn').forEach(function(btn){
                btn.addEventListener('mouseenter', function(){
                    var key = btn.getAttribute('data-key');
                    var val = parseInt(btn.getAttribute('data-val'), 10);
                    btn.closest('.rk-skill-row').querySelectorAll('.rk-skill-star-btn').forEach(function(b){
                        b.querySelector('svg').setAttribute('fill', parseInt(b.getAttribute('data-val'),10) <= val ? '#fbbf24' : '#e2e8f0');
                    });
                });
                btn.addEventListener('mouseleave', function(){
                    var row = btn.closest('.rk-skill-row');
                    var current = parseInt(row.querySelector('.rk-skill-input').value, 10);
                    row.querySelectorAll('.rk-skill-star-btn').forEach(function(b){
                        b.querySelector('svg').setAttribute('fill', parseInt(b.getAttribute('data-val'),10) <= current ? '#f59e0b' : '#e2e8f0');
                    });
                });
            });

            /* ── Hover preview étoiles générales ── */
            document.querySelectorAll('.rk-overall-star-btn').forEach(function(btn){
                btn.addEventListener('mouseenter', function(){
                    var val = parseInt(btn.getAttribute('data-val'), 10);
                    document.querySelectorAll('.rk-overall-star-btn').forEach(function(b){
                        b.querySelector('svg').setAttribute('fill', parseInt(b.getAttribute('data-val'),10) <= val ? '#fbbf24' : '#e2e8f0');
                    });
                });
                btn.addEventListener('mouseleave', function(){
                    var current = parseInt(document.getElementById('rk-overall-rating').value, 10);
                    document.querySelectorAll('.rk-overall-star-btn').forEach(function(b){
                        b.querySelector('svg').setAttribute('fill', parseInt(b.getAttribute('data-val'),10) <= current ? '#f59e0b' : '#e2e8f0');
                    });
                });
            });

            /* ── Bouton : حفظ المهارات ── */
            var saveSkillsBtn = document.getElementById('rk-save-skills');
            saveSkillsBtn.setAttribute('data-label', saveSkillsBtn.textContent.trim());
            saveSkillsBtn.addEventListener('click', function(){
                var scores = {};
                document.querySelectorAll('.rk-skill-input').forEach(function(inp){
                    scores[inp.name.replace('skills[','').replace(']','')] = inp.value;
                });
                postSection('rk_save_skills_section', { skill_scores: scores }, 'rk-skills-status', 'rk-skills-saved-badge', saveSkillsBtn);
            });

            /* ── Bouton : حفظ التقييم العام ── */
            var saveGenBtn = document.getElementById('rk-save-general');
            saveGenBtn.setAttribute('data-label', saveGenBtn.textContent.trim());
            saveGenBtn.addEventListener('click', function(){
                postSection('rk_save_general_section', {
                    overall_rating: document.getElementById('rk-overall-rating').value,
                    summary:        document.getElementById('rk-gen-summary').value,
                    strengths:      document.getElementById('rk-gen-strengths').value,
                    areas:          document.getElementById('rk-gen-areas').value,
                    parent_message: document.getElementById('rk-gen-parent-msg').value,
                }, 'rk-general-status', 'rk-general-saved-badge', saveGenBtn);
            });

            /* ── Bouton : حفظ الملاحظات ── */
            var saveNotesBtn = document.getElementById('rk-save-notes');
            saveNotesBtn.setAttribute('data-label', saveNotesBtn.textContent.trim());
            saveNotesBtn.addEventListener('click', function(){
                postSection('rk_save_notes_section', {
                    notes: document.getElementById('rk-notes').value,
                }, 'rk-notes-status', '', saveNotesBtn);
            });

            /* ── تحميل تقييم موجود ── (absent en mode verrouillé — pas de
               resélection manuelle possible, voir §3 de la demande) ── */
            var loadExistingBtn = document.getElementById('rk-load-existing');
            if (loadExistingBtn) loadExistingBtn.addEventListener('click', function(e){
                e.preventDefault();
                var cid = getChildId(), dt = getDate(), bid = getBookingId();
                if (!cid || !dt) return;
                var url = '<?php echo esc_url( $base_url ); ?>' + '?child_id=' + cid + '&session_date=' + dt;
                if (bid && bid !== '0') url += '&booking_id=' + bid;
                window.location.href = url;
            });

            /* ── Rechargement du sélecteur اللقاء quand élève/date changent ──
               Nécessaire pour que booking_id reste cohérent avec la séance
               réellement affichée avant de sauvegarder une section. Absent
               en mode verrouillé (le <select> n'existe pas). ── */
            function reloadBookingOptions() {
                var sel = document.getElementById('rk-sel-booking');
                if (!sel || sel.tagName !== 'SELECT') return;
                var cid = getChildId(), dt = getDate();
                sel.innerHTML = '<option value="0"><?php echo esc_js( __('— بدون لقاء محدد —', 'rk-coach-hub') ); ?></option>';
                if (!cid || !dt) return;
                var fd = new FormData();
                fd.append('action', 'rk_get_child_bookings');
                fd.append('nonce', nonce);
                fd.append('child_id', cid);
                fd.append('session_date', dt);
                fetch(ajaxUrl, { method:'POST', body:fd })
                    .then(function(r){ return r.json(); })
                    .then(function(res){
                        if (!res.success) return;
                        (res.data.bookings || []).forEach(function(b){
                            var opt = document.createElement('option');
                            opt.value = b.booking_id;
                            opt.textContent = b.label;
                            sel.appendChild(opt);
                        });
                        if ((res.data.bookings || []).length === 1) {
                            sel.value = res.data.bookings[0].booking_id;
                        }
                    });
            }
            var selChildEl = document.getElementById('rk-sel-child');
            var selDateEl  = document.getElementById('rk-sel-date');
            if (selChildEl && selChildEl.tagName === 'SELECT') selChildEl.addEventListener('change', reloadBookingOptions);
            if (selDateEl  && selDateEl.tagName  === 'INPUT'  && !selDateEl.readOnly) selDateEl.addEventListener('change', reloadBookingOptions);

        })();
        </script>

        <?php
    }

    /* ─── AJAX : Séances de l'enfant pour une date (sélecteur اللقاء) ─── */

    public static function ajax_get_child_bookings(): void {
        check_ajax_referer( 'rk_eval_section', 'nonce' );
        if ( ! current_user_can( 'tutor_instructor' ) ) wp_send_json_error( 'access' );

        $coach_id = get_current_user_id();
        $child_id = absint( $_POST['child_id'] ?? 0 );
        $date     = sanitize_text_field( $_POST['session_date'] ?? '' );

        if ( ! $child_id || ! $date || ! RK_Coach_Data::coach_owns_child( $coach_id, $child_id ) ) {
            wp_send_json_error( 'access' );
        }

        $rows = self::get_child_bookings_for_date( $coach_id, $child_id, $date );
        $out  = array_map( static function ( $b ) {
            return [
                'booking_id' => (int) $b->booking_id,
                'label'      => trim( ( $b->session_name ?: __( 'لقاء', 'rk-coach-hub' ) )
                    . ' — ' . date_i18n( 'H:i', strtotime( (string) $b->appointment ) ) ),
            ];
        }, $rows );

        wp_send_json_success( [ 'bookings' => $out ] );
    }

    /* ─── AJAX : Sauvegarder les compétences ────────────────────────── */

    public static function ajax_save_skills(): void {
        check_ajax_referer( 'rk_eval_section', 'nonce' );
        if ( ! current_user_can( 'tutor_instructor' ) ) wp_send_json_error( 'access' );

        $coach_id   = get_current_user_id();
        $child_id   = absint( $_POST['child_id'] ?? 0 );
        $date       = sanitize_text_field( $_POST['session_date'] ?? date( 'Y-m-d' ) );
        $booking_id = absint( $_POST['booking_id'] ?? 0 );

        if ( ! $child_id || ! RK_Coach_Data::coach_owns_child( $coach_id, $child_id ) ) {
            wp_send_json_error( 'access' );
        }

        $scores_raw = (array) ( $_POST['skill_scores'] ?? [] );
        $scores     = [];
        foreach ( $scores_raw as $k => $v ) {
            $key = sanitize_key( $k );
            if ( $key ) $scores[ $key ] = min( 5, max( 0, (int) $v ) );
        }

        if ( ! class_exists( 'RK_MC_Assessment_Service' ) ) wp_send_json_error( 'service_missing' );

        // v10.3.0 — booking_id présent : flow autorisé (child_id/coach_id/date
        // dérivés du booking + attendance vérifiée), pas de confiance en
        // $child_id/$date postés. Sans booking_id : repli legacy inchangé.
        if ( $booking_id ) {
            $res = RK_MC_Assessment_Service::create_or_update_for_booking_authorized( $booking_id, $coach_id, [
                'skill_scores' => $scores,
            ] );
            if ( ! $res['success'] ) wp_send_json_error( $res['code'] );
            $id       = $res['assessment_id'];
            $child_id = $res['child_id']; // dérivé du booking — remplace la valeur postée pour tous les side-effects ci-dessous
        } else {
            $id = RK_MC_Assessment_Service::create_or_update( $child_id, $date, $coach_id, [
                'skill_scores' => $scores,
            ] );
        }

        if ( $id && class_exists( 'RK_MC_Skill_Service' ) ) {
            foreach ( $scores as $key => $score ) {
                RK_MC_Skill_Service::set_skill( $child_id, $key, $score * 2 );
            }
        }

        $id ? wp_send_json_success( [ 'assessment_id' => $id ] ) : wp_send_json_error( 'save_failed' );
    }

    /* ─── AJAX : Sauvegarder التقييم العام ─────────────────────────── */

    public static function ajax_save_general(): void {
        check_ajax_referer( 'rk_eval_section', 'nonce' );
        if ( ! current_user_can( 'tutor_instructor' ) ) wp_send_json_error( 'access' );

        $coach_id      = get_current_user_id();
        $child_id      = absint( $_POST['child_id'] ?? 0 );
        $date          = sanitize_text_field( $_POST['session_date'] ?? date( 'Y-m-d' ) );
        $booking_id    = absint( $_POST['booking_id'] ?? 0 );
        $rating        = min( 5, max( 1, absint( $_POST['overall_rating'] ?? 3 ) ) );
        $summary       = sanitize_textarea_field( $_POST['summary']        ?? '' );
        $strengths_txt = sanitize_textarea_field( $_POST['strengths']      ?? '' );
        $areas_txt     = sanitize_textarea_field( $_POST['areas']          ?? '' );
        $parent_msg    = sanitize_textarea_field( $_POST['parent_message'] ?? '' );

        if ( ! $child_id || ! RK_Coach_Data::coach_owns_child( $coach_id, $child_id ) ) {
            wp_send_json_error( 'access' );
        }

        if ( ! class_exists( 'RK_MC_Assessment_Service' ) ) wp_send_json_error( 'service_missing' );

        $strengths = array_values( array_filter( array_map( 'trim', explode( "\n", $strengths_txt ) ) ) );
        $develops  = array_values( array_filter( array_map( 'trim', explode( "\n", $areas_txt ) ) ) );

        $eval_data = [
            'rating'         => $rating,
            'summary'        => $summary,
            'strengths'      => $strengths,
            'developments'   => $develops,
            'parent_message' => $parent_msg,
        ];
        if ( $booking_id ) {
            $res = RK_MC_Assessment_Service::create_or_update_for_booking_authorized( $booking_id, $coach_id, $eval_data );
            if ( ! $res['success'] ) wp_send_json_error( $res['code'] );
            $id       = $res['assessment_id'];
            $child_id = $res['child_id']; // dérivé du booking — remplace la valeur postée pour les side-effects ci-dessous
        } else {
            $id = RK_MC_Assessment_Service::create_or_update( $child_id, $date, $coach_id, $eval_data );
        }

        if ( $id ) {
            if ( class_exists( 'RK_MC_Gamification_Service' ) ) {
                RK_MC_Gamification_Service::add_points( $child_id, 5, 'evaluation', $id );
            }
            if ( class_exists( 'RK_Audit_Log' ) ) {
                RK_Audit_Log::record( 'evaluation', $child_id, [ 'assessment_id' => $id, 'rating' => $rating ] );
            }
        }

        $id ? wp_send_json_success( [ 'assessment_id' => $id ] ) : wp_send_json_error( 'save_failed' );
    }

    /* ─── AJAX : Sauvegarder الملاحظات الخاصة ───────────────────────── */

    public static function ajax_save_notes(): void {
        check_ajax_referer( 'rk_eval_section', 'nonce' );
        if ( ! current_user_can( 'tutor_instructor' ) ) wp_send_json_error( 'access' );

        $coach_id   = get_current_user_id();
        $child_id   = absint( $_POST['child_id'] ?? 0 );
        $date       = sanitize_text_field( $_POST['session_date'] ?? date( 'Y-m-d' ) );
        $booking_id = absint( $_POST['booking_id'] ?? 0 );
        $notes      = sanitize_textarea_field( $_POST['notes'] ?? '' );

        if ( ! $child_id || ! RK_Coach_Data::coach_owns_child( $coach_id, $child_id ) ) {
            wp_send_json_error( 'access' );
        }

        if ( ! class_exists( 'RK_MC_Assessment_Service' ) ) wp_send_json_error( 'service_missing' );

        if ( $booking_id ) {
            $res = RK_MC_Assessment_Service::create_or_update_for_booking_authorized( $booking_id, $coach_id, [
                'notes' => $notes,
            ] );
            if ( ! $res['success'] ) wp_send_json_error( $res['code'] );
            $id = $res['assessment_id'];
        } else {
            $id = RK_MC_Assessment_Service::create_or_update( $child_id, $date, $coach_id, [
                'notes' => $notes,
            ] );
        }

        $id ? wp_send_json_success( [ 'assessment_id' => $id ] ) : wp_send_json_error( 'save_failed' );
    }

    /* ─── Brouillons (compatibilité) ────────────────────────────────── */

    public static function ajax_save_draft(): void {
        check_ajax_referer( 'rk_eval_draft', 'nonce' );
        if ( ! current_user_can( 'tutor_instructor' ) ) wp_send_json_error( 'access' );
        wp_send_json_success( [ 'saved_at' => current_time( 'H:i' ) ] );
    }

    /* ─── Handler legacy (gardé pour compatibilité monthly) ─────────── */

    public static function handle_submit(): void {
        if ( ! check_admin_referer( 'rk_coach_eval', 'rk_eval_nonce' ) ) wp_die( 'Nonce invalide.' );
        if ( ! current_user_can( 'tutor_instructor' ) ) wp_die( 'Accès refusé.' );

        $coach_id     = get_current_user_id();
        $child_id     = absint( $_POST['child_id'] ?? 0 );
        $rating       = min( 5, max( 1, absint( $_POST['overall_rating'] ?? 3 ) ) );
        $summary      = sanitize_textarea_field( $_POST['summary']               ?? '' );
        $strengths    = sanitize_textarea_field( $_POST['strengths']             ?? '' );
        $areas        = sanitize_textarea_field( $_POST['areas_for_improvement'] ?? '' );
        $notes        = sanitize_textarea_field( $_POST['private_notes']         ?? '' );
        $parent_msg   = sanitize_textarea_field( $_POST['parent_message']        ?? '' );
        $sess_date    = sanitize_text_field(     $_POST['session_date']          ?? date( 'Y-m-d' ) );
        $skills_raw   = (array) ( $_POST['skills'] ?? [] );
        $redirect     = tutor_utils()->tutor_dashboard_url( 'rk-evaluer' );

        if ( ! $child_id ) {
            wp_redirect( add_query_arg( 'eval_error', rawurlencode( 'يرجى اختيار طالب.' ), $redirect ) );
            exit;
        }
        if ( ! RK_Coach_Data::coach_owns_child( $coach_id, $child_id ) ) wp_die( 'هذا الطالب غير مرتبط بك.' );

        $strengths_arr = array_values( array_filter( array_map( 'trim', explode( "\n", $strengths ) ) ) );
        $develop_arr   = array_values( array_filter( array_map( 'trim', explode( "\n", $areas ) ) ) );
        $skill_scores  = [];
        foreach ( $skills_raw as $key => $score ) {
            $k = sanitize_key( $key );
            if ( $k ) $skill_scores[ $k ] = min( 5, max( 1, (int) $score ) );
        }

        if ( ! class_exists( 'RK_MC_Assessment_Service' ) ) {
            wp_redirect( add_query_arg( 'eval_error', rawurlencode( 'خدمة التقييم غير متاحة.' ), $redirect ) );
            exit;
        }

        $id = RK_MC_Assessment_Service::create_or_update( $child_id, $sess_date, $coach_id, [
            'rating'         => $rating,
            'summary'        => $summary,
            'strengths'      => $strengths_arr,
            'developments'   => $develop_arr,
            'notes'          => $notes,
            'parent_message' => $parent_msg,
            'skill_scores'   => $skill_scores,
        ] );

        if ( ! $id ) {
            wp_redirect( add_query_arg( 'eval_error', rawurlencode( 'حدث خطأ أثناء الحفظ.' ), $redirect ) );
            exit;
        }

        if ( class_exists( 'RK_MC_Skill_Service' ) ) {
            foreach ( $skill_scores as $key => $score ) {
                RK_MC_Skill_Service::set_skill( $child_id, $key, $score * 2 );
            }
        }
        if ( class_exists( 'RK_MC_Gamification_Service' ) ) {
            RK_MC_Gamification_Service::add_points( $child_id, 5, 'evaluation', $id );
        }
        if ( class_exists( 'RK_Audit_Log' ) ) {
            RK_Audit_Log::record( 'evaluation', $child_id, [ 'assessment_id' => $id, 'rating' => $rating ] );
        }

        wp_redirect( add_query_arg( [ 'eval_done' => '1', 'child_id' => $child_id ], $redirect ) );
        exit;
    }

    public static function handle_monthly(): void {
        self::handle_submit();
    }

    /* ─── Définitions compétences ───────────────────────────────────── */

    public static function get_skill_definitions(): array {
        if ( class_exists( 'RK_MC_Skill_Service' ) ) {
            return array_map( fn( $d ) => $d['name'], RK_MC_Skill_Service::definitions() );
        }
        return [
            'speech'     => 'قوة الكلام',
            'teamwork'   => 'قوة التعاون',
            'creativity' => 'قوة الأفكار',
            'courage'    => 'قوة الشجاعة',
            'leadership' => 'قوة القيادة',
            'focus'      => 'قوة التركيز',
        ];
    }
}
