<?php
/**
 * RiadaKids\Ajax\BookingEditAjax — v3.5
 *
 * Édition d'une réservation depuis « لقاءاتي القادمة ».
 *
 * Cette classe est l'UNIQUE propriétaire du flux d'édition côté serveur.
 * Les handlers correspondants ont été retirés de DashboardAjax pour éviter
 * deux implémentations divergentes de la même règle métier.
 *
 * Périmètre EXACT de ce qui est modifiable :
 *
 *   MODIFIABLE  : program_id, course_id, session_name, child_id
 *                 → données 100 % RiadaKids, aucune contrepartie externe.
 *
 *   LECTURE SEULE : appointment, end_at, coach, timezone, appointment_type_id
 *                 → propriété de Simply Schedule Appointments. Les modifier
 *                   ici laisserait le créneau du coach inchangé côté SSA
 *                   (désynchronisation interdite). Le déplacement passe
 *                   obligatoirement par l'écran SSA (bouton « تغيير الموعد »).
 *
 *   INTOUCHABLE : credits_used, is_refunded, status
 *                 → une édition n'est PAS une nouvelle réservation : aucun
 *                   crédit n'est re-débité. Voir note « CRÉDITS » plus bas.
 *
 * @package RiadaKids\Ajax
 */

namespace RiadaKids\Ajax;

use RiadaKids\Booking\BookingService;
use RiadaKids\Children\ChildRepository;
use RiadaKids\Core\Helpers;
use RiadaKids\Core\TimeZone;

if ( ! defined( 'ABSPATH' ) ) exit;

class BookingEditAjax {

	/** Nonce dédié — indépendant du wizard de réservation. */
	public const NONCE_ACTION = 'rk_booking_edit';

	/** Statuts pour lesquels une édition est autorisée. */
	private const EDITABLE_STATUSES = [ 'confirmed', 'rescheduled' ];

	/**
	 * AJOUT (demande utilisateur — bouton "إلغاء" sur la carte) —
	 * dépendance vers BookingService, utilisée exclusivement par
	 * ajax_cancel() ci-dessous pour réutiliser BookingService::
	 * cancel_from_ssa(), qui gère déjà tout le cycle d'annulation
	 * (statut, remboursement conditionnel à la fenêtre 24h, notification)
	 * — le même code exact que celui déclenché par le webhook SSA
	 * d'annulation, pour ne jamais dupliquer cette règle métier.
	 */
	public function __construct( private readonly ?BookingService $service = null ) {}

	public function register(): void {
		// Actions canoniques
		add_action( 'wp_ajax_rk_booking_edit_get',      [ $this, 'ajax_get' ] );
		add_action( 'wp_ajax_rk_booking_edit_save',     [ $this, 'ajax_save' ] );
		add_action( 'wp_ajax_rk_booking_edit_courses',  [ $this, 'ajax_courses' ] );
		add_action( 'wp_ajax_rk_booking_edit_sessions', [ $this, 'ajax_sessions' ] );
		add_action( 'wp_ajax_rk_booking_cancel',        [ $this, 'ajax_cancel' ] );

		// Alias historiques — conservés pour ne casser aucun appelant existant
		// (anciennes pages en cache navigateur, intégrations tierces).
		add_action( 'wp_ajax_rk_get_booking_edit', [ $this, 'ajax_get' ] );
		add_action( 'wp_ajax_rk_update_booking',   [ $this, 'ajax_save' ] );
	}

	/* ══════════════════════════════════════════════════════════════
	 * Sécurité
	 * ══════════════════════════════════════════════════════════════ */

	/**
	 * Nonce + authentification.
	 *
	 * Accepte le nonce dédié ET l'ancien nonce booking : le dashboard peut
	 * rester ouvert dans un onglet servi par la version précédente du plugin.
	 */
	private function guard(): int {
		$ok = check_ajax_referer( self::NONCE_ACTION, 'nonce', false )
			|| check_ajax_referer( 'rk_booking_nonce', 'nonce', false );

		if ( ! $ok ) {
			$this->fail( 'انتهت صلاحية الصفحة، يرجى إعادة تحميلها', 403 );
		}

		$user_id = get_current_user_id();
		if ( ! $user_id || ! is_user_logged_in() ) {
			$this->fail( 'يجب تسجيل الدخول أولاً', 401 );
		}

		return $user_id;
	}

	/**
	 * Charge une réservation EN VÉRIFIANT L'APPARTENANCE dans la requête SQL.
	 *
	 * Le filtre `user_id = %d` est la barrière d'autorisation : un booking_id
	 * forgé dans DevTools ne renvoie simplement aucune ligne → 404, jamais
	 * les données d'un autre parent.
	 */
	private function load_owned_booking( int $row_id, int $user_id ): object {
		global $wpdb;

		if ( $row_id <= 0 ) {
			$this->fail( 'معرّف الحجز غير صالح', 400 );
		}

		$bk = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}rk_bookings WHERE id = %d AND user_id = %d",
			$row_id,
			$user_id
		) );

		if ( ! $bk ) {
			// Message volontairement identique pour « inexistant » et
			// « appartient à quelqu'un d'autre » : ne pas révéler l'existence
			// d'une ligne à un utilisateur qui n'y a pas droit.
			$this->fail( 'الحجز غير موجود', 404 );
		}

		return $bk;
	}

	/* ══════════════════════════════════════════════════════════════
	 * Handlers
	 * ══════════════════════════════════════════════════════════════ */

	/** GET — charge la réservation réelle + toutes les listes déroulantes. */
	public function ajax_get(): void {
		$user_id = $this->guard();
		$row_id  = absint( $_POST['booking_id'] ?? $_POST['row_id'] ?? 0 );
		$bk      = $this->load_owned_booking( $row_id, $user_id );

		if ( ! $this->is_editable( $bk ) ) {
			$this->fail( $this->lock_reason( $bk ), 403 );
		}

		$programs = [];
		$terms    = get_terms( [ 'taxonomy' => 'course-category', 'hide_empty' => false ] );
		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $t ) {
				$programs[] = [ 'id' => (int) $t->term_id, 'label' => $t->name ];
			}
		}

		$children = [];
		foreach ( ChildRepository::get_by_user( $user_id ) as $c ) {
			$children[] = [
				'id'    => (int) $c->id,
				'label' => trim( $c->child_name . ' ' . (string) ( $c->child_family_name ?? '' ) ),
			];
		}

		wp_send_json_success( [
			'booking'  => $this->present( $bk, $user_id ),
			'programs' => $programs,
			'children' => $children,
			'courses'  => $this->courses_for_program( (int) $bk->program_id ),
			'sessions' => $this->sessions_for_course( (int) $bk->course_id ),
		] );
	}

	/** Liste chaînée : programme → cours. */
	public function ajax_courses(): void {
		$this->guard();
		wp_send_json_success( [
			'courses' => $this->courses_for_program( absint( $_POST['program_id'] ?? 0 ) ),
		] );
	}

	/** Liste chaînée : cours → séances. */
	public function ajax_sessions(): void {
		$this->guard();
		wp_send_json_success( [
			'sessions' => $this->sessions_for_course( absint( $_POST['course_id'] ?? 0 ) ),
		] );
	}

	/**
	 * AJOUT (demande utilisateur — bouton "إلغاء" sur la carte) — annule
	 * une réservation à la demande du client, depuis "لقاءاتي القادمة".
	 *
	 * Même barrière d'appartenance que les autres handlers de cette classe
	 * (load_owned_booking() filtre déjà sur user_id = utilisateur connecté)
	 * et même fenêtre d'édition (is_editable() : statut confirmed/
	 * rescheduled ET moins de 24h depuis la création) — un client ne peut
	 * pas annuler un rendez-vous déjà passé la fenêtre, exactement comme
	 * il ne peut pas non plus le modifier après ce délai.
	 *
	 * La logique métier réelle (statut, remboursement conditionnel,
	 * notification) est entièrement déléguée à BookingService::
	 * cancel_from_ssa() — le même code que celui déclenché par le webhook
	 * SSA d'annulation — jamais dupliquée ici.
	 */
	public function ajax_cancel(): void {
		$user_id = $this->guard();
		$row_id  = absint( $_POST['booking_id'] ?? $_POST['row_id'] ?? 0 );
		$bk      = $this->load_owned_booking( $row_id, $user_id );

		if ( ! $this->is_editable( $bk ) ) {
			$this->fail( $this->lock_reason( $bk ), 403 );
		}

		if ( ! $this->service ) {
			// Ne devrait jamais arriver en production (voir Plugin.php,
			// qui injecte toujours BookingService) — filet de sécurité
			// pour ne jamais planter avec une erreur PHP brute côté client.
			$this->fail( 'خدمة الإلغاء غير متاحة حاليًا', 500 );
		}

		$appointment_id = (int) ( $bk->booking_id ?? 0 );
		if ( $appointment_id <= 0 ) {
			$this->fail( 'معرّف الموعد غير صالح', 400 );
		}

		// FIX (bug signalé — une réservation annulée revient "confirmée"
		// après un certain temps) — cancel_on_ssa() appelle réellement
		// SSA (status='canceled') AVANT de déléguer à cancel_from_ssa()
		// pour le reste du travail local — voir sa doc complète dans
		// BookingService.php pour la cause exacte du bug (le cron de
		// synchro écrasait le statut local avec le vrai statut SSA,
		// jamais modifié jusqu'ici pour ce chemin client).
		//
		// AJOUT — $row_id transmis comme booking_id CONNU (voir doc de
		// cancel_from_ssa() pour le bug exact que ça corrige — double
		// remboursement sur un mauvais booking si appointment_id est
		// partagé par plusieurs lignes locales, ex. appointment de test
		// réutilisé) : ce chemin client connaît le VRAI booking cliqué
		// sans aucune ambiguïté, jamais besoin de le redéduire.
		//
		// FIX (bug signalé — "تعذر إلغاء الحجز (HTTP 200)" alors que
		// l'annulation avait réellement réussi côté serveur, voir logs
		// fournis) — cause trouvée : cancel_on_ssa() déclenche en cascade
		// l'action 'rk_booking_cancelled', consommée par des intégrations
		// tierces (ex. RK_MC_Booking_Bridge::unenroll_children(), hors de
		// ce plugin) qui peuvent produire une sortie parasite (erreur SQL
		// wpdb affichée directement, warning PHP, etc.) — cette sortie
		// s'intercale AVANT wp_send_json_success() et corrompt le JSON de
		// la réponse ; jQuery échoue alors à le parser et déclenche
		// .fail() côté client (d'où le message générique "(HTTP 200)"),
		// alors que l'annulation a réellement réussi en base.
		//
		// Un tampon de sortie dédié isole tout ce que cancel_on_ssa() (et
		// les hooks tiers qu'il déclenche en cascade) pourrait imprimer :
		// on la capture et on la journalise pour ne rien perdre côté
		// debug, mais on ne la laisse JAMAIS atteindre la réponse HTTP.
		ob_start();
		$ok = $this->service->cancel_on_ssa( $appointment_id, $row_id );
		$stray_output = ob_get_clean();

		if ( $stray_output !== '' && function_exists( 'rk_log' ) ) {
			rk_log(
				'BOOKING',
				"ajax_cancel() booking#{$row_id} appt#{$appointment_id} — sortie parasite interceptée pendant cancel_on_ssa() (probablement une intégration tierce sur 'rk_booking_cancelled') : " . substr( $stray_output, 0, 2000 ),
				'warning'
			);
		}

		if ( ! $ok ) {
			$this->fail( 'تعذّر إلغاء الحجز، يرجى المحاولة لاحقًا', 500 );
		}

		global $wpdb;
		$fresh = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}rk_bookings WHERE id = %d AND user_id = %d",
			$row_id,
			$user_id
		) );

		wp_send_json_success( [
			'message'  => 'تم إلغاء الحجز بنجاح',
			'msg'      => 'تم إلغاء الحجز بنجاح',
			'booking'  => $fresh ? $this->present( $fresh, $user_id ) : null,
			// AJOUT — écran de succès Figma (« تم استرداد الرصيد إلى حسابك
			// تلقائيًا ») : reflète la vraie règle 24h appliquée par
			// BookingService::cancel_from_ssa(), jamais supposée côté client.
			'refunded' => $fresh ? ( (int) ( $fresh->is_refunded ?? 0 ) === 1 ) : false,
		] );
	}

	/**
	 * SAVE — revalide intégralement côté serveur, puis écrit de façon atomique.
	 *
	 * Aucune valeur envoyée par le navigateur n'est écrite sans avoir été
	 * re-vérifiée contre la base : existence, appartenance, et cohérence
	 * programme → cours → séance.
	 */
	public function ajax_save(): void {
		global $wpdb;

		$user_id = $this->guard();
		$table   = $wpdb->prefix . 'rk_bookings';
		$row_id  = absint( $_POST['booking_id'] ?? $_POST['row_id'] ?? 0 );
		$bk      = $this->load_owned_booking( $row_id, $user_id );

		if ( ! $this->is_editable( $bk ) ) {
			$this->fail( $this->lock_reason( $bk ), 403 );
		}

		$program_id   = absint( $_POST['program_id'] ?? 0 );
		$course_id    = absint( $_POST['course_id'] ?? 0 );
		$child_id     = absint( $_POST['child_id'] ?? 0 );
		$session_name = sanitize_text_field( wp_unslash( (string) ( $_POST['session_name'] ?? '' ) ) );

		/* ── Validation : champs obligatoires ─────────────────────── */

		if ( ! $program_id || ! $course_id || ! $child_id || '' === $session_name ) {
			$this->fail( 'يرجى تعبئة جميع الحقول المطلوبة', 400 );
		}

		/* ── Validation : programme ───────────────────────────────── */

		$term = get_term( $program_id, 'course-category' );
		if ( ! $term || is_wp_error( $term ) ) {
			$this->fail( 'البرنامج غير صحيح', 400 );
		}

		/* ── Validation : cours existe ET appartient au programme ─── */

		if ( get_post_type( $course_id ) !== 'courses' || get_post_status( $course_id ) !== 'publish' ) {
			$this->fail( 'الدورة غير صحيحة', 400 );
		}
		if ( ! has_term( $program_id, 'course-category', $course_id ) ) {
			$this->fail( 'الدورة لا تنتمي لهذا البرنامج', 400 );
		}

		/* ── Validation : séance appartient au cours ──────────────── */

		$session_ok = false;
		foreach ( $this->sessions_for_course( $course_id ) as $s ) {
			if ( $s['label'] === $session_name ) { $session_ok = true; break; }
		}
		if ( ! $session_ok ) {
			$this->fail( 'اللقاء لا ينتمي لهذه الدورة', 400 );
		}

		/* ── Validation : l'enfant appartient au parent connecté ──── */

		$child_label = '';
		foreach ( ChildRepository::get_by_user( $user_id ) as $c ) {
			if ( (int) $c->id === $child_id ) {
				$child_label = trim( $c->child_name . ' ' . (string) ( $c->child_family_name ?? '' ) );
				break;
			}
		}
		if ( '' === $child_label ) {
			// $_POST['child_id'] n'est JAMAIS écrit sans être passé par cette
			// boucle : un id d'enfant d'un autre parent est rejeté ici.
			$this->fail( 'الطفل المحدد غير مرتبط بحسابك', 403 );
		}

		/* ── Validation : pas deux fois le même enfant sur le RDV ─── */

		if ( $child_id !== (int) $bk->child_id ) {
			$clash = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$table}
				  WHERE booking_id = %d AND child_id = %d AND id != %d AND status != 'cancelled'",
				(int) $bk->booking_id,
				$child_id,
				$row_id
			) );
			if ( $clash ) {
				$this->fail( 'هذا الطفل مسجّل بالفعل في نفس الموعد', 409 );
			}
		}

		/* ── No-op : rien à écrire ────────────────────────────────── */

		$unchanged = $program_id === (int) $bk->program_id
			&& $course_id === (int) $bk->course_id
			&& $child_id === (int) $bk->child_id
			&& $session_name === (string) $bk->session_name;

		if ( $unchanged ) {
			// Protège aussi du double-clic : la 2e requête ne réécrit rien.
			wp_send_json_success( [
				'message' => 'لا توجد تغييرات لحفظها',
				'msg'     => 'لا توجد تغييرات لحفظها',
				'booking' => $this->present( $bk, $user_id ),
				'labels'  => $this->labels( $program_id, $course_id, $session_name, $child_label ),
				// AJOUT (bug signalé — filtre "الطفل" pas mis à jour
				// instantanément après changement d'enfant) — child_id
				// renvoyé pour que le client puisse mettre à jour
				// data-rk-child-id sur la carte, l'attribut réellement lu
				// par booking-history-filters.js::applyFilters() (le
				// texte affiché via 'labels' ci-dessus ne suffit pas).
				'child_id' => $child_id,
			] );
		}

		/* ══════════════════════════════════════════════════════════
		 * ÉCRITURE ATOMIQUE
		 *
		 * Trois sources doivent rester cohérentes :
		 *   1. wp_rk_bookings          (source de vérité)
		 *   2. CPT rk_booking + metas  (projection : emails, espace coach)
		 *   3. wp_rk_booking_quota     (occupation d'une place)
		 *
		 * InnoDB → toutes participent à la même transaction, y compris
		 * wp_postmeta. Un échec sur l'une annule les autres : pas d'état
		 * partiellement sauvegardé.
		 *
		 * CRÉDITS — VOLONTAIREMENT ABSENTS DE CETTE TRANSACTION.
		 * `credits_used` n'est ni lu ni écrit ici. Une édition conserve le
		 * crédit déjà consommé à la création : changer de séance ou d'enfant
		 * ne re-débite rien (pas de double débit), et ne rembourse rien.
		 * Seule l'annulation (flux existant, hors de cette classe) restitue
		 * le crédit.
		 * ══════════════════════════════════════════════════════════ */

		$wpdb->query( 'START TRANSACTION' );

		try {
			$updated = $wpdb->update(
				$table,
				[
					'program_id'   => $program_id,
					'course_id'    => $course_id,
					'session_name' => $session_name,
					'child_id'     => $child_id,
					'updated_at'   => current_time( 'mysql' ),
				],
				[ 'id' => $row_id, 'user_id' => $user_id ],
				[ '%d', '%d', '%s', '%d', '%s' ],
				[ '%d', '%d' ]
			);

			if ( false === $updated ) {
				throw new \RuntimeException( 'update_bookings_failed' );
			}

			// Projection CPT — laissée périmée, elle ferait diverger les
			// emails et l'espace coach de ce que voit le parent.
			$post_id = $this->find_booking_post( (int) $bk->booking_id );
			if ( $post_id ) {
				update_post_meta( $post_id, '_rk_program_id',   $program_id );
				update_post_meta( $post_id, '_rk_course_id',    $course_id );
				update_post_meta( $post_id, '_rk_session_name', $session_name );
				update_post_meta( $post_id, '_rk_child_id',     $child_id );

				// _rk_child_ids : liste des enfants du même RDV, recalculée
				// depuis la source de vérité plutôt que patchée à l'aveugle.
				$ids = $wpdb->get_col( $wpdb->prepare(
					"SELECT child_id FROM {$table}
					  WHERE booking_id = %d AND status != 'cancelled'",
					(int) $bk->booking_id
				) );
				update_post_meta( $post_id, '_rk_child_ids', array_map( 'intval', $ids ?: [] ) );
			}

			// Quota : libérer l'ancienne place, occuper la nouvelle.
			$this->move_quota( $bk, $child_id, $session_name );

			$wpdb->query( 'COMMIT' );

		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			error_log( '[RK][booking-edit] rollback row#' . $row_id . ' : ' . $e->getMessage() );
			$this->fail( 'تعذّر حفظ التعديلات، لم يتم تغيير أي شيء', 500 );
		}

		/**
		 * SSA — aucune action ici, et c'est délibéré.
		 *
		 * Aucun champ appartenant à SSA (appointment, end_at, coach,
		 * appointment_type_id, timezone) n'est lu depuis $_POST ni écrit en
		 * base par cette méthode. Le rendez-vous SSA reste donc, par
		 * construction, identique à ce qu'affiche le dashboard.
		 *
		 * LIMITATION DOCUMENTÉE : l'API SSA embarquée dans ce plugin
		 * (DB::get_ssa_edit_url) n'expose qu'un lien public de replanification
		 * ; il n'existe aucun point d'entrée serveur permettant de déplacer un
		 * rendez-vous. Le déplacement reste donc un parcours SSA natif
		 * (bouton « تغيير الموعد »), suivi de la resynchronisation par le cron
		 * `rk_ssa_sync_cron` déjà en place. Aucune API n'a été inventée.
		 */

		do_action( 'rk_booking_edited_by_customer', $row_id, $user_id );

		$fresh = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE id = %d AND user_id = %d",
			$row_id,
			$user_id
		) );

		wp_send_json_success( [
			'message' => 'تم تحديث الحجز بنجاح',
			'msg'     => 'تم تحديث الحجز بنجاح', // compat client < 3.5
			'booking' => $fresh ? $this->present( $fresh, $user_id ) : null,
			'labels'  => $this->labels( $program_id, $course_id, $session_name, $child_label ),
			// AJOUT (bug signalé — filtre "الطفل" pas mis à jour
			// instantanément après changement d'enfant) — child_id
			// renvoyé pour que le client puisse mettre à jour
			// data-rk-child-id sur la carte, l'attribut réellement lu par
			// booking-history-filters.js::applyFilters() (le texte
			// affiché via 'labels' ci-dessus ne suffit pas).
			'child_id' => $child_id,
		] );
	}

	/* ══════════════════════════════════════════════════════════════
	 * Quota
	 * ══════════════════════════════════════════════════════════════ */

	/**
	 * Déplace l'occupation de place : ancienne séance libérée, nouvelle
	 * occupée. L'horaire (`appointment`) ne change pas — il appartient à SSA.
	 *
	 * Écrit directement sur les colonnes réelles de wp_rk_booking_quota
	 * (child_id, session_name, appointment, booking_id). DB::quota_register()
	 * n'est volontairement pas réutilisée : elle cible une colonne
	 * `session_key` absente du schéma installé — bug préexistant signalé dans
	 * le rapport, non corrigé ici pour ne pas élargir le périmètre.
	 */
	private function move_quota( object $bk, int $new_child_id, string $new_session ): void {
		global $wpdb;

		$quota = $wpdb->prefix . 'rk_booking_quota';

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $quota ) ) !== $quota ) {
			return; // table absente : rien à synchroniser.
		}
		if ( empty( $bk->appointment ) ) {
			return; // sans horaire, la clé de quota n'a pas de sens.
		}

		$old_child   = (int) $bk->child_id;
		$old_session = (string) $bk->session_name;

		if ( $old_child === $new_child_id && $old_session === $new_session ) {
			return;
		}

		// Libère l'ancienne place.
		$wpdb->delete(
			$quota,
			[
				'child_id'     => $old_child,
				'session_name' => $old_session,
				'appointment'  => $bk->appointment,
			],
			[ '%d', '%s', '%s' ]
		);

		// Occupe la nouvelle. REPLACE : la clé UNIQUE
		// (child_id, session_name, appointment) rend l'opération idempotente,
		// donc sûre en cas de double soumission concurrente.
		$wpdb->replace(
			$quota,
			[
				'child_id'     => $new_child_id,
				'session_name' => $new_session,
				'appointment'  => $bk->appointment,
				'booking_id'   => (int) $bk->booking_id,
				'created_at'   => current_time( 'mysql' ),
			],
			[ '%d', '%s', '%s', '%d', '%s' ]
		);
	}

	/* ══════════════════════════════════════════════════════════════
	 * Helpers
	 * ══════════════════════════════════════════════════════════════ */

	/** Représentation complète d'une réservation pour la modale. */
	private function present( object $bk, int $user_id ): array {
		$prog = '';
		if ( $bk->program_id ) {
			$prog = get_term_field( 'name', (int) $bk->program_id, 'course-category' );
			if ( is_wp_error( $prog ) || ! $prog ) {
				$prog = get_term_field( 'name', (int) $bk->program_id, 'product_cat' );
			}
			if ( is_wp_error( $prog ) ) $prog = '';
		}

		return [
			'row_id'       => (int) $bk->id,
			'booking_id'   => (int) $bk->booking_id,
			'program_id'   => (int) $bk->program_id,
			'program_name' => (string) $prog,
			'course_id'    => (int) $bk->course_id,
			'course_name'  => $bk->course_id ? (string) get_the_title( (int) $bk->course_id ) : '',
			'session_name' => (string) $bk->session_name,
			'child_id'     => (int) $bk->child_id,
			'coach'        => (string) ( $bk->coach ?? '' ),
			'credits_used' => (int) ( $bk->credits_used ?? 1 ),
			'status'       => (string) $bk->status,
			'status_label' => $this->status_label( (string) $bk->status ),
			'appointment'  => ! empty( $bk->appointment )
				? TimeZone::format(
					(string) $bk->appointment, 'j M Y، H:i',
					(int) $bk->booking_id, $user_id
				)
				: '',
			'hours_left'   => $this->hours_left( $bk ),
		];
	}

	private function labels( int $program_id, int $course_id, string $session, string $child ): array {
		$prog = $program_id ? get_term_field( 'name', $program_id, 'course-category' ) : '';
		if ( is_wp_error( $prog ) ) $prog = '';

		return [
			'program' => $prog ?: '—',
			'course'  => $course_id ? get_the_title( $course_id ) : '—',
			'session' => $session ?: '—',
			'child'   => $child ?: '—',
		];
	}

	private function status_label( string $status ): string {
		return [
			'confirmed'      => 'مؤكد',
			'pending'        => 'انتظار',
			'pending_credit' => 'انتظار رصيد',
			'cancelled'      => 'ملغى',
			'rescheduled'    => 'معاد جدولة',
		][ $status ] ?? $status;
	}

	/** Fenêtre de 24 h ouverte ET statut modifiable. */
	private function is_editable( object $bk ): bool {
		if ( ! in_array( $bk->status, self::EDITABLE_STATUSES, true ) ) {
			return false;
		}
		$ts = ! empty( $bk->booking_date ) ? strtotime( (string) $bk->booking_date ) : 0;
		return $ts > 0 && ( time() - $ts ) < DAY_IN_SECONDS;
	}

	private function lock_reason( object $bk ): string {
		if ( ! in_array( $bk->status, self::EDITABLE_STATUSES, true ) ) {
			return 'لا يمكن تعديل حجز بحالة « ' . $this->status_label( (string) $bk->status ) . ' »';
		}
		return 'انتهت مهلة التعديل (24 ساعة من إتمام الحجز)';
	}

	private function hours_left( object $bk ): int {
		$ts = ! empty( $bk->booking_date ) ? strtotime( (string) $bk->booking_date ) : 0;
		return $ts > 0 ? max( 0, (int) ceil( ( $ts + DAY_IN_SECONDS - time() ) / 3600 ) ) : 0;
	}

	/** @return array<int, array{id:int,label:string}> */
	private function courses_for_program( int $program_id ): array {
		if ( $program_id <= 0 ) return [];

		$posts = get_posts( [
			'post_type'      => 'courses',
			'posts_per_page' => -1,
			'post_status'    => 'publish',
			'orderby'        => 'title',
			'order'          => 'ASC',
			'tax_query'      => [ [
				'taxonomy' => 'course-category',
				'field'    => 'term_id',
				'terms'    => $program_id,
			] ],
		] );

		return array_map(
			static fn( $p ) => [ 'id' => (int) $p->ID, 'label' => (string) $p->post_title ],
			$posts
		);
	}

	/** @return array<int, array{id:int,label:string}> */
	private function sessions_for_course( int $course_id ): array {
		if ( $course_id <= 0 ) return [];

		return array_map(
			static fn( $l ) => [ 'id' => (int) $l->ID, 'label' => (string) $l->post_title ],
			Helpers::get_tutor_lessons( $course_id )
		);
	}

	private function find_booking_post( int $appointment_id ): int {
		if ( $appointment_id <= 0 ) return 0;

		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT p.ID FROM {$wpdb->posts} p
			   INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
			          AND m.meta_key = '_rk_appointment_id'
			          AND m.meta_value = %d
			  WHERE p.post_type = 'rk_booking'
			  ORDER BY p.post_date DESC LIMIT 1",
			$appointment_id
		) );
	}

	/**
	 * Réponse d'erreur structurée. Jamais d'exception PHP brute renvoyée au
	 * navigateur : uniquement un message destiné à l'utilisateur.
	 */
	private function fail( string $message, int $code ): void {
		wp_send_json_error( [
			'message' => $message,
			'msg'     => $message, // compat client < 3.5
		], $code );
	}
}