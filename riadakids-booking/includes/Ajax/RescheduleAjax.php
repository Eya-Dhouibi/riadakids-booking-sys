<?php
/**
 * RiadaKids\Ajax\RescheduleAjax — v2.0
 *
 * Reprogrammation interne d'une réservation (écran « تعديل الموعد » de la
 * maquette Figma) : changer le TYPE D'ÉVÉNEMENT SSA (et implicitement le
 * coach, chaque type ayant un coach dédié dans RiadaKids — demande
 * utilisateur) et/ou la date/heure d'un rendez-vous déjà confirmé, sans
 * quitter le site RiadaKids.
 *
 * ══════════════════════════════════════════════════════════════════════
 * AJUSTEMENT v2.0 (demande utilisateur) — le champ « المدرب » liste
 * désormais les TYPES DE RENDEZ-VOUS SSA (table wp_ssa_appointment_types,
 * status='publish'), pas les coachs directement. Dans RiadaKids, chaque
 * coach a son propre type d'événement dédié (relation 1-à-1) : changer de
 * type revient donc exactement à changer de coach, sans étape
 * supplémentaire. Le vrai staff_id à assigner est résolu à partir du type
 * choisi via SSA_Staff_Appointment_Type_Model::
 * get_staff_ids_for_appointment_type_id() — jamais envoyé par le client.
 * ══════════════════════════════════════════════════════════════════════
 *
 * Pourquoi appeler l'API PHP interne de SSA directement (pas de requête
 * HTTP vers son endpoint REST) :
 *
 *   SSA_Appointment_Model::update_item() — le handler de sa route REST
 *   PUT /appointments/{id} — se réduit lui-même à un seul appel :
 *
 *       $this->update( $item_id, $params );
 *
 *   (voir includes/class-appointment-model.php de SSA Pro, méthode
 *   update_item(), dernière ligne avant la construction de la réponse).
 *   Appeler directement SSA_Appointment_Model::update() reproduit donc
 *   EXACTEMENT le même comportement que sa route REST officielle :
 *     - vérification de disponibilité si start_date change
 *       (is_prospective_appointment_available, exclut l'appointment
 *       courant de la recherche de conflit)
 *     - calcul automatique de end_date depuis la durée du type de RDV
 *     - historique de reprogrammation (meta rescheduled_from_start_dates)
 *     - gestion des événements groupés (capacity_type = 'group')
 *     - déclenchement de la chaîne de hooks natifs SSA (do_action
 *       'ssa/appointment/after_update' → 'ssa/appointment/rescheduled' →
 *       notifications email, sync Google Calendar, cache — voir échange
 *       avec le client, confirmé en lisant class-hooks.php/
 *       class-notifications.php du plugin SSA)
 *   — sans reconstruire cette logique nous-mêmes, et sans les soucis de
 *   nonce/permission REST puisque l'appel se fait serveur-à-serveur.
 *
 *   Champs modifiables par un appelant non-privilégié côté SSA
 *   (SSA_Appointment_Model::get_unprivileged_update_fields()) :
 *   start_date, staff_ids — exactement ce dont cet écran a besoin.
 *
 *   La disponibilité par type/date vient de
 *   SSA_Appointment_Type_Model::get_availability(), la même requête
 *   utilisée par l'app de réservation SSA elle-même
 *   (SSA_Availability_Query), donc garantie cohérente avec ce que le
 *   coach voit réellement dans son propre calendrier. Structure de
 *   réponse confirmée en lisant la source SSA (class-availability-
 *   query.php::get_bookable_appointment_start_datetime_strings()) :
 *   ['data' => [['start_date' => 'Y-m-d H:i:s'], ...]].
 * ══════════════════════════════════════════════════════════════════════
 *
 * Périmètre EXACT de ce qui est modifiable ici (mêmes garde-fous que
 * BookingEditAjax : appartenance de la ligne + fenêtre 24h depuis
 * booking_date) :
 *
 *   MODIFIABLE  : type de rendez-vous / coach implicite (staff_ids),
 *                 date/heure (start_date)
 *   JAMAIS TOUCHÉ : credits_used, is_refunded, status — une
 *                   reprogrammation n'est pas une nouvelle réservation.
 *
 * @package RiadaKids\Ajax
 */

namespace RiadaKids\Ajax;

use RiadaKids\Booking\BookingService;
use RiadaKids\Database\DB;
use RiadaKids\Core\TimeZone;

if ( ! defined( 'ABSPATH' ) ) exit;

class RescheduleAjax {

	/** Même nonce que BookingEditAjax — un seul écran logique côté client. */
	public const NONCE_ACTION = 'rk_booking_edit';

	/** Statuts pour lesquels une reprogrammation est autorisée. */
	private const EDITABLE_STATUSES = [ 'confirmed', 'rescheduled' ];

	public function __construct( private readonly ?BookingService $service = null ) {}

	public function register(): void {
		add_action( 'wp_ajax_rk_reschedule_get_types',        [ $this, 'ajax_get_appointment_types' ] );
		add_action( 'wp_ajax_rk_reschedule_get_availability', [ $this, 'ajax_get_availability' ] );
		add_action( 'wp_ajax_rk_reschedule_confirm',          [ $this, 'ajax_confirm' ] );
	}

	/* ══════════════════════════════════════════════════════════════
	 * Sécurité — copié à l'identique de BookingEditAjax::guard()/
	 * load_owned_booking() pour rester cohérent avec l'écran d'édition
	 * dont cette classe est le prolongement (voir doc en tête de fichier).
	 * ══════════════════════════════════════════════════════════════ */

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
			$this->fail( 'الحجز غير موجود', 404 );
		}

		return $bk;
	}

	/** Fenêtre de 24h ouverte ET statut modifiable — même règle que BookingEditAjax::is_editable(). */
	private function is_editable( object $bk ): bool {
		if ( ! in_array( $bk->status, self::EDITABLE_STATUSES, true ) ) {
			return false;
		}
		$ts = ! empty( $bk->booking_date ) ? strtotime( (string) $bk->booking_date ) : 0;
		return $ts > 0 && ( time() - $ts ) < DAY_IN_SECONDS;
	}

	private function lock_reason(): string {
		return 'انتهت مهلة التعديل (24 ساعة من إتمام الحجز)';
	}

	/**
	 * Accès à l'instance du plugin SSA Pro (fonction globale ssa(), comme
	 * déjà utilisé dans DB::get_ssa_appointment_array()). Retourne null si
	 * SSA n'est pas actif — chaque appelant doit vérifier avant d'utiliser.
	 */
	private function ssa(): ?object {
		if ( ! function_exists( 'ssa' ) ) return null;
		try {
			return ssa();
		} catch ( \Throwable $e ) {
			rk_log( 'RESCHEDULE', 'ssa() inaccessible: ' . $e->getMessage(), 'error' );
			return null;
		}
	}

	/* ══════════════════════════════════════════════════════════════
	 * Handlers
	 * ══════════════════════════════════════════════════════════════ */

	/**
	 * Liste des types de rendez-vous SSA actifs (status='publish'), lue
	 * directement dans wp_ssa_appointment_types — pour peupler le menu
	 * déroulant « المدرب » de l'écran de reprogrammation (demande
	 * utilisateur : le champ liste des event types, pas des coachs
	 * directement — chaque type a son coach dédié dans RiadaKids).
	 */
	public function ajax_get_appointment_types(): void {
		$user_id = $this->guard();
		$row_id  = absint( $_POST['booking_id'] ?? $_POST['row_id'] ?? 0 );
		$bk      = $this->load_owned_booking( $row_id, $user_id );

		if ( ! $this->is_editable( $bk ) ) {
			$this->fail( $this->lock_reason(), 403 );
		}

		// AJUSTEMENT v2 (demande utilisateur — dropdown retiré, champ
		// affiché en lecture seule) — le nom affiché vient directement de
		// la colonne `coach` de wp_rk_bookings (déjà la source utilisée
		// pour le champ "المدرب" sur la carte principale, voir Dashboard::
		// render_booking_card()), pas d'une liste de types SSA — plus
		// besoin d'interroger SSAIntegration::get_events() ni
		// wp_ssa_appointment_types puisqu'il n'y a plus de choix à
		// présenter, juste un nom à afficher.
		$name = (string) ( $bk->coach ?? '' );
		$name = '' !== $name ? \RiadaKids\Core\Helpers::clean_coach_name( $name ) : '—';

		wp_send_json_success( [
			'staff_name'      => $name,
			'current_type_id' => (int) ( $bk->appointment_type_id ?? 0 ),
		] );
	}

	/**
	 * Créneaux disponibles pour un TYPE de rendez-vous donné, sur une
	 * plage de dates. Réutilise SSA_Appointment_Type_Model::
	 * get_availability() — même requête que l'app de réservation SSA
	 * elle-même (garantie cohérente avec le calendrier réel du coach), en
	 * excluant l'appointment courant de la recherche de conflit (il
	 * occupe déjà ce créneau).
	 *
	 * AJUSTEMENT v2.0 — $type_id vient désormais du CLIENT (le type choisi
	 * dans le dropdown), plus de l'ancienne réservation : reprogrammer
	 * vers un autre type doit interroger la disponibilité de CE type, pas
	 * de l'ancien.
	 */
	public function ajax_get_availability(): void {
		$user_id = $this->guard();
		$row_id  = absint( $_POST['booking_id'] ?? $_POST['row_id'] ?? 0 );
		$bk      = $this->load_owned_booking( $row_id, $user_id );

		if ( ! $this->is_editable( $bk ) ) {
			$this->fail( $this->lock_reason(), 403 );
		}

		$ssa = $this->ssa();
		if ( ! $ssa ) {
			$this->fail( 'خدمة الجدولة غير متاحة حاليًا', 500 );
		}

		$type_id  = absint( $_POST['appointment_type_id'] ?? 0 );
		$date_min = sanitize_text_field( (string) ( $_POST['start_date_min'] ?? '' ) );
		$date_max = sanitize_text_field( (string) ( $_POST['start_date_max'] ?? '' ) );

		if ( $type_id <= 0 || '' === $date_min ) {
			$this->fail( 'معطيات ناقصة', 400 );
		}

		try {
			$request = new \WP_REST_Request( 'GET' );
			$request->set_param( 'id', $type_id );
			$request->set_param( 'start_date_min', $date_min );
			$request->set_param( 'start_date_max', $date_max ?: $date_min );
			$request->set_param( 'excluded_appointment_ids', [ (int) $bk->booking_id ] );

			// get_availability() renvoie un tableau brut ['data' => [...]]
			// (confirmé en lisant la source SSA — voir doc en tête de
			// fichier), jamais un WP_REST_Response ici puisque appelée en
			// PHP direct plutôt que via le routeur REST — les deux formes
			// sont tout de même gérées, au cas où une future version de
			// SSA changerait ce détail d'implémentation.
			$response = $ssa->appointment_type_model->get_availability( $request );

			$data = ( $response instanceof \WP_REST_Response )
				? $response->get_data()
				: $response;

		} catch ( \Throwable $e ) {
			rk_log( 'RESCHEDULE', 'ajax_get_availability: ' . $e->getMessage(), 'error' );
			$this->fail( 'تعذّر تحميل الأوقات المتاحة', 500 );
		}

		// La vraie structure SSA est ['data' => [...], 'response_code' =>
		// 200, ...] — on extrait déjà ['data'] ici plutôt que de laisser
		// le client deviner parmi plusieurs formes possibles.
		$slots = is_array( $data ) ? ( $data['data'] ?? $data ) : [];
		$slots = $slots ?: [];

		// FIX (bug signalé — heure affichée décalée, "13:30" réellement
		// disponible mais affiché "2:30 PM"/"14:30" sur le bouton) — les
		// créneaux SSA (start_date) sont dans le FUSEAU DE STOCKAGE
		// BUSINESS SSA (ex. Asia/Riyadh GMT+3), jamais UTC ni le fuseau du
		// visiteur (ex. Tunis GMT+1, confirmé différent en pratique) —
		// voir TimeZone::storage_tz(). Un simple `new Date(...)` côté JS
		// interprète cette chaîne comme une heure LOCALE DU NAVIGATEUR
		// (format 'Y-m-d H:i:s', pas ISO strict), causant une double
		// conversion incorrecte lors du formatage avec Intl.DateTimeFormat
		// vers state.tz. Le label affiché est donc désormais calculé ICI,
		// côté serveur, avec TimeZone::format() (qui connaît storage_tz()
		// ET le fuseau client réel de cette réservation via customer_tz())
		// — le JS n'a plus jamais à deviner ou convertir un fuseau
		// lui-même, juste afficher label tel quel.
		$appt_id_for_tz = (int) ( $bk->booking_id ?? 0 );
		foreach ( $slots as &$slot ) {
			$raw = is_array( $slot ) ? ( $slot['start_date'] ?? '' ) : (string) $slot;
			if ( '' === $raw ) continue;

			// $raw est déjà en fuseau storage_tz() (voir doc ci-dessus) —
			// TimeZone::format() suppose que la chaîne reçue est en
			// storage_tz() (comportement de storage_tz()/DateTimeImmutable
			// déjà en place pour toutes les autres dates de ce fichier),
			// donc réutilisable tel quel sans traitement supplémentaire.
			$label = \RiadaKids\Core\TimeZone::format( $raw, 'H:i', $appt_id_for_tz, $user_id );
			if ( is_array( $slot ) ) {
				$slot['label'] = $label;
			} else {
				$slot = [ 'start_date' => $raw, 'label' => $label ];
			}
		}
		unset( $slot );

		wp_send_json_success( [
			'availability' => $slots,
		] );
	}

	/**
	 * Confirme la reprogrammation : résout le coach depuis le type choisi,
	 * appelle SSA_Appointment_Model::update() (voir doc en tête de
	 * fichier), puis synchronise wp_rk_bookings pour que la carte reflète
	 * immédiatement le changement, sans attendre le cron rk_ssa_sync_cron.
	 *
	 * AJUSTEMENT v3.0 (bug signalé — "تعذّر تحديد المدرب المرتبط بهذا
	 * الموعد" systématique) — l'hypothèse "chaque type = un coach dédié"
	 * s'est révélée fausse en conditions réelles : le vrai contenu de la
	 * colonne `staff` sur wp_ssa_appointment_types est
	 * {"required":"","assignment":"random"} — SSA assigne le staff
	 * ALÉATOIREMENT parmi ceux éligibles à un type, il n'existe aucun
	 * lien fixe type→coach ni table/classe exposant une telle liste
	 * (confirmé par les logs de diagnostic ajoutés en 4.0.1 : les 3 voies
	 * de repli échouaient toutes, la 3e révélant ce contenu JSON).
	 *
	 * Décision produit (confirmée avec l'utilisateur) : le simple
	 * changement de date/heure suffit — staff_ids n'est PLUS envoyé du
	 * tout à update(). Le coach déjà assigné à l'appointment reste
	 * automatiquement en place tant qu'il est disponible au nouveau
	 * créneau (is_prospective_appointment_available() le vérifie déjà en
	 * interne à SSA, voir doc en tête de fichier) — cohérent avec le fait
	 * que la disponibilité affichée dans le calendrier (ajax_get_availability())
	 * dépend déjà du STAFF DE CE TYPE, pas d'un choix explicite du client.
	 */
	public function ajax_confirm(): void {
		$user_id = $this->guard();
		$row_id  = absint( $_POST['booking_id'] ?? $_POST['row_id'] ?? 0 );
		$bk      = $this->load_owned_booking( $row_id, $user_id );

		if ( ! $this->is_editable( $bk ) ) {
			$this->fail( $this->lock_reason(), 403 );
		}

		$ssa = $this->ssa();
		if ( ! $ssa ) {
			$this->fail( 'خدمة الجدولة غير متاحة حاليًا', 500 );
		}

		$type_id    = absint( $_POST['appointment_type_id'] ?? 0 );
		$start_date = sanitize_text_field( (string) ( $_POST['start_date'] ?? '' ) );
		$appt_id    = (int) ( $bk->booking_id ?? 0 );

		if ( $type_id <= 0 || '' === $start_date || $appt_id <= 0 ) {
			$this->fail( 'معطيات ناقصة', 400 );
		}

		// FIX (bug signalé — après reprogrammation, la carte affiche la
		// bonne date mais garde l'ancienne heure) — start_date était
		// transmis à SSA tel quel, sans jamais valider ni normaliser son
		// format. state.selectedSlot (voir booking-reschedule.js::
		// selectSlot()) est censé être au format SSA natif
		// ('Y-m-d H:i:s', avec espace — voir renderCalendar(), qui lit
		// s.start_date brut de get_availability()), mais rien ne garantit
		// que le client envoie toujours exactement ce format (ex. si le
		// navigateur reformate via Date() quelque part, on obtiendrait un
		// 'T' ISO ou un décalage de fuseau). Normalisé ici via
		// DateTimeImmutable — accepte 'Y-m-d H:i:s' ET 'Y-m-d\TH:i:s',
		// rejette tout format non reconnu plutôt que de transmettre une
		// chaîne que SSA pourrait mal interpréter silencieusement (ex.
		// tronquer l'heure à minuit).
		$start_date = str_replace( 'T', ' ', $start_date );
		try {
			$parsed = new \DateTimeImmutable( $start_date );
		} catch ( \Exception $e ) {
			rk_log( 'RESCHEDULE', "ajax_confirm: start_date reçu du client non parsable — valeur brute: \"{$start_date}\"", 'error' );
			$this->fail( 'صيغة الموعد غير صالحة', 400 );
		}
		$start_date = $parsed->format( 'Y-m-d H:i:s' );

		try {
			// Même chemin exact que la route REST officielle de SSA (voir
			// doc en tête de fichier) : update_item() se réduit lui-même
			// à ce seul appel, avec le contrôle de disponibilité déjà
			// intégré si start_date change réellement. appointment_type_id
			// n'est volontairement PAS envoyé ici : voir doc de
			// get_unprivileged_update_fields() dans SSA — changer le type
			// sur un appointment existant peut désynchroniser l'état de
			// paiement (voir commentaire SSA, "intentionally NOT listed
			// on update"). staff_ids n'est PLUS envoyé non plus (voir doc
			// v3.0 ci-dessus) : le coach déjà assigné à l'appointment
			// reste automatiquement en place, SSA lui-même vérifie sa
			// disponibilité au nouveau créneau.
			$result = $ssa->appointment_model->update( $appt_id, [
				'start_date' => $start_date,
			] );

			if ( is_array( $result ) && ! empty( $result['error'] ) ) {
				$code = $result['error']['code'] ?? '';
				$msg  = ( 'appointment_unavailable' === $code )
					? 'عذرًا، تم حجز هذا الوقت للتو — يرجى اختيار وقت آخر'
					: ( $result['error']['message'] ?? 'تعذّر تحديث الموعد' );
				$this->fail( $msg, 409 );
			}
		} catch ( \Throwable $e ) {
			rk_log( 'RESCHEDULE', 'ajax_confirm update(): ' . $e->getMessage(), 'error' );
			$this->fail( 'تعذّر تحديث الموعد، يرجى المحاولة لاحقًا', 500 );
		}

		// ── Synchro immédiate de wp_rk_bookings ──────────────────────
		// Relit l'appointment SSA à jour (nouvelle date, coach inchangé)
		// plutôt que de reconstruire ces valeurs à la main, pour rester
		// la source de vérité unique déjà utilisée ailleurs (voir
		// DB::get_ssa_appointment_array()). appointment_type_id est mis à
		// jour explicitement ici (côté RiadaKids uniquement, voir note
		// ci-dessus sur pourquoi il n'est pas envoyé à SSA).
		global $wpdb;
		$db_update_ok = false;
		try {
			$fresh_appt = DB::get_ssa_appointment_array( $appt_id );
			$new_coach  = $this->resolve_current_coach_name( $ssa, $appt_id, $fresh_appt );

			$update = [
				'appointment_type_id' => $type_id,
				'status'              => 'rescheduled',
				'updated_at'          => current_time( 'mysql' ),
			];
			$fmt = [ '%d', '%s', '%s' ];

			if ( ! empty( $fresh_appt['start_date'] ) ) {
				$update['appointment'] = $fresh_appt['start_date'];
				$fmt[] = '%s';
			}
			if ( '' !== $new_coach ) {
				$update['coach'] = $new_coach;
				$fmt[] = '%s';
			}

			$db_result = $wpdb->update(
				$wpdb->prefix . 'rk_bookings',
				$update,
				[ 'id' => (int) $bk->id ],
				$fmt,
				[ '%d' ]
			);

			// $wpdb->update() renvoie le nombre de lignes affectées, ou
			// false en cas d'erreur SQL — 0 est un succès légitime
			// (valeurs déjà identiques, ex. même créneau reconfirmé).
			$db_update_ok = ( false !== $db_result );
			if ( ! $db_update_ok ) {
				rk_log( 'RESCHEDULE', "ajax_confirm: échec SQL wpdb->update sur rk_bookings#{$bk->id} — " . $wpdb->last_error, 'error' );
			}
		} catch ( \Throwable $e ) {
			// La reprogrammation SSA a réussi — ne jamais faire échouer la
			// réponse au client pour un problème de synchro locale, le cron
			// rk_ssa_sync_cron rattrapera l'écart à la prochaine passe.
			rk_log( 'RESCHEDULE', 'sync wp_rk_bookings après reschedule: ' . $e->getMessage(), 'warning' );
		}

		// Déplace l'occupation de quota (même logique que
		// BookingEditAjax::move_quota(), dupliquée ici : classe distincte,
		// pas d'accès direct à la méthode privée de l'autre classe).
		$this->move_quota_for_reschedule( $bk, $start_date );

		do_action( 'rk_booking_rescheduled_by_customer', $row_id, $user_id );

		$fresh = $wpdb->get_row( $wpdb->prepare(
			"SELECT rb.*, rc.child_name, rc.child_family_name
			   FROM {$wpdb->prefix}rk_bookings rb
			   LEFT JOIN {$wpdb->prefix}rk_children rc ON rc.id = rb.child_id
			  WHERE rb.id = %d AND rb.user_id = %d",
			$row_id,
			$user_id
		) );

		// AJOUT (demande utilisateur — "après changement les informations
		// sont bien mises à jour dans le backend base de données") —
		// confirmation explicite au client que la synchro locale a bien
		// réussi (db_updated: true/false), distincte du succès SSA
		// (toujours vrai à ce point, sinon on aurait déjà fail() plus
		// haut). Permet au JS d'avertir si SSA a été mis à jour mais que
		// la synchro locale a échoué (cas rare, rattrapé par le cron,
		// mais l'utilisateur doit pouvoir le savoir).
		wp_send_json_success( [
			'message'    => 'تم تحديث الموعد بنجاح',
			'summary'    => $fresh ? $this->present_summary( $fresh, $user_id ) : null,
			'db_updated' => $db_update_ok,
		] );
	}

	/* ══════════════════════════════════════════════════════════════
	 * Helpers
	 * ══════════════════════════════════════════════════════════════ */

	/**
	 * Résout le staff_id dédié à un type de rendez-vous — relation 1-à-1
	 * dans RiadaKids (demande utilisateur : "chaque coach à son event
	 * type donc changé event c'est le même changé coach"). Prend le
	 * premier staff_id retourné si plusieurs sont associés côté SSA (cas
	 * non attendu dans RiadaKids, mais géré sans planter).
	 */
	/**
	 * Résout le staff_id dédié à un type de rendez-vous — relation 1-à-1
	 * dans RiadaKids (demande utilisateur : "chaque coach à son event
	 * type donc changé event c'est le même changé coach").
	 *
	 * AJUSTEMENT (bug signalé — "تعذّر تحديد المدرب المرتبط بهذا الموعد"
	 * systématique) — $ssa->staff_appointment_type_model est bien une
	 * propriété réelle du plugin SSA Pro (confirmée en usage dans son
	 * propre export/import de sauvegarde, class-support-status.php), mais
	 * son fichier de classe n'a pas pu être vérifié directement (absent
	 * de l'archive fournie pour l'analyse). MÉTHODE ABANDONNÉE — voir doc
	 * v3.0 de ajax_confirm() : le contenu réel de la colonne `staff`
	 * ({"required":"","assignment":"random"}, confirmé par les logs de
	 * diagnostic) révèle que SSA assigne le staff ALÉATOIREMENT, il
	 * n'existe aucune liste fixe de coachs par type à résoudre. Conservée
	 * en commentaire pour mémoire — remplacée par
	 * resolve_current_coach_name() ci-dessous, qui lit simplement le
	 * coach DÉJÀ assigné à l'appointment (jamais recalculé).
	 */

	/**
	 * Nom du coach actuellement assigné à un appointment — pour affichage
	 * seulement (mise à jour de la colonne `coach` de wp_rk_bookings après
	 * reprogrammation, voir ajax_confirm()). Tente la vraie méthode PHP de
	 * SSA (staff_appointment_model->get_staff_ids(), découverte en lisant
	 * SSA_Appointment_Model::get_staff_ids() dans son code source — c'est
	 * ce que SSA utilise pour lui-même), avec repli sur le champ déjà
	 * présent dans $fresh_appt si cette classe est elle aussi absente de
	 * cette installation (comme staff_appointment_type_model).
	 *
	 * @return string Nom du coach, ou '' si indisponible des deux façons
	 *                 — jamais un nom inventé ; la carte affichera alors
	 *                 son repli existant (voir Dashboard::
	 *                 resolve_coach_from_staff_ids()).
	 */
	private function resolve_current_coach_name( object $ssa, int $appointment_id, array $fresh_appt ): string {
		$staff_ids = [];

		try {
			if ( isset( $ssa->staff_appointment_model )
				&& method_exists( $ssa->staff_appointment_model, 'get_staff_ids' ) ) {
				$ids = $ssa->staff_appointment_model->get_staff_ids( $appointment_id );
				if ( ! empty( $ids ) ) {
					$staff_ids = is_array( $ids ) ? $ids : [ $ids ];
				}
			}
		} catch ( \Throwable $e ) {
			rk_log( 'RESCHEDULE', 'resolve_current_coach_name: ' . $e->getMessage(), 'warning' );
		}

		if ( empty( $staff_ids ) && ! empty( $fresh_appt['staff_ids'] ) ) {
			$staff_ids = is_array( $fresh_appt['staff_ids'] ) ? $fresh_appt['staff_ids'] : [ $fresh_appt['staff_ids'] ];
		}

		if ( empty( $staff_ids ) ) return '';

		$user = get_userdata( (int) reset( $staff_ids ) );
		return $user ? $user->display_name : '';
	}

	/**
	 * Même logique que BookingEditAjax::move_quota() — dupliquée
	 * volontairement (classe distincte, méthode source privée) plutôt que
	 * de modifier la visibilité de l'existant pour un seul appelant
	 * externe. Ici, seule la date change (child_id/session_name restent
	 * identiques) : la clé de quota se déplace donc uniquement sur
	 * l'axe temporel.
	 */
	private function move_quota_for_reschedule( object $bk, string $new_start_date ): void {
		global $wpdb;

		$quota = $wpdb->prefix . 'rk_booking_quota';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $quota ) ) !== $quota ) {
			return;
		}
		if ( empty( $bk->appointment ) || $bk->appointment === $new_start_date ) {
			return;
		}

		$wpdb->delete(
			$quota,
			[
				'child_id'     => (int) $bk->child_id,
				'session_name' => (string) $bk->session_name,
				'appointment'  => $bk->appointment,
			],
			[ '%d', '%s', '%s' ]
		);

		$wpdb->replace(
			$quota,
			[
				'child_id'     => (int) $bk->child_id,
				'session_name' => (string) $bk->session_name,
				'appointment'  => $new_start_date,
				'booking_id'   => (int) $bk->booking_id,
				'created_at'   => current_time( 'mysql' ),
			],
			[ '%d', '%s', '%s', '%d', '%s' ]
		);
	}

	/** Récapitulatif pour l'écran de succès « تم تحديث الموعد ». */
	private function present_summary( object $bk, int $user_id ): array {
		// AJUSTEMENT (bug signalé — texte parasite "أنت تحجز: …" dans le
		// récapitulatif de reprogrammation) — même nettoyage déjà appliqué
		// côté carte de réservation (voir Dashboard::render_booking_card(),
		// Helpers::clean_coach_name()), jusqu'ici absent de ce récap
		// précis.
		$coach = (string) ( $bk->coach ?? '' );
		$coach = '' !== $coach ? \RiadaKids\Core\Helpers::clean_coach_name( $coach ) : '—';

		return [
			'child'   => trim( (string) ( $bk->child_name ?? '' ) . ' ' . (string) ( $bk->child_family_name ?? '' ) ) ?: '—',
			'session' => (string) ( $bk->session_name ?? '—' ),
			'coach'   => $coach,
			// AJUSTEMENT (demande utilisateur, format exact fourni) —
			// "السبت، 19 أغسطس الساعة 3:35 مساءً" au lieu du format
			// numérique compact précédent, via TimeZone::
			// format_arabic_full() (nouvelle méthode dédiée, voir sa doc).
			'appointment' => ! empty( $bk->appointment )
				? TimeZone::format_arabic_full( (string) $bk->appointment, (int) $bk->booking_id, $user_id )
				: '',
			// AJUSTEMENT (demande utilisateur — même format complet
			// PARTOUT, carte incluse) — appointment_card réutilise
			// désormais format_arabic_full() (voir sa doc, corrige aussi
			// le bug "Tuesday، 22 September 2PM" en anglais) — plus de
			// format compact séparé, la carte affiche exactement le même
			// texte que le récapitulatif ci-dessus.
			'appointment_card' => ! empty( $bk->appointment )
				? TimeZone::format_arabic_full( (string) $bk->appointment, (int) $bk->booking_id, $user_id )
				: '',
		];
	}

	private function fail( string $message, int $code ): void {
		wp_send_json_error( [
			'message' => $message,
			'msg'     => $message,
		], $code );
	}
}
