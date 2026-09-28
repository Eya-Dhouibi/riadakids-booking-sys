<?php
namespace RiadaKids\Core;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Conversion des dates SSA → fuseau horaire du client.
 *
 * SSA écrit `wp_ssa_appointments.start_date` (et par recopie
 * `rk_bookings.appointment`) dans UN SEUL fuseau : celui du "business"
 * configuré dans les réglages SSA.
 * La colonne `customer_timezone` contient le fuseau réel du client.
 *
 * @since 3.1.2 Détection automatique du fuseau de stockage + cache mémoire.
 */
final class TimeZone {

	/** Cache mémoire (par requête HTTP) */
	private static ?\DateTimeZone $storage_cache = null;
	private static array          $customer_cache = [];

	/* ─────────────────────────────────────────────────────────────
	 * Fuseau de STOCKAGE (source des valeurs en base)
	 * ───────────────────────────────────────────────────────────── */

	/**
	 * Ordre de résolution :
	 *   1. Constante RK_SSA_STORAGE_TZ (wp-config.php) — surcharge manuelle
	 *   2. Réglages natifs SSA (option ssa_settings / ssa_business_timezone)
	 *   3. Fuseau du site WordPress
	 *
	 * NB : le repli est le fuseau du SITE, pas 'Asia/Riyadh'. Un défaut codé
	 * en dur égal au fuseau du client rend la conversion invisible et donne
	 * l'illusion que le correctif ne s'applique pas.
	 */
	public static function storage_tz(): \DateTimeZone {
		if ( self::$storage_cache instanceof \DateTimeZone ) {
			return self::$storage_cache;
		}

		$tz = '';

		// 1. Surcharge explicite
		if ( defined( 'RK_SSA_STORAGE_TZ' ) && RK_SSA_STORAGE_TZ ) {
			$tz = (string) RK_SSA_STORAGE_TZ;
		}

		// 2. Réglages SSA (mis en cache 1 h côté transient)
		if ( ! $tz ) {
			$cached = get_transient( 'rk_ssa_business_tz' );
			if ( false === $cached ) {
				$cached = self::detect_ssa_timezone() ?: 'none';
				set_transient( 'rk_ssa_business_tz', $cached, HOUR_IN_SECONDS );
			}
			if ( 'none' !== $cached ) $tz = (string) $cached;
		}

		// 3. Repli : fuseau du site
		if ( ! $tz ) $tz = wp_timezone_string();

		$tz = (string) apply_filters( 'rk_ssa_storage_tz', $tz );

		try {
			self::$storage_cache = new \DateTimeZone( $tz );
		} catch ( \Exception $e ) {
			self::$storage_cache = wp_timezone();
		}

		return self::$storage_cache;
	}

	/**
	 * Lit le fuseau du "business" dans les réglages SSA.
	 * Le format de l'option varie selon les versions du plugin : on teste
	 * les emplacements connus, du plus récent au plus ancien.
	 */
	private static function detect_ssa_timezone(): string {
		$settings = get_option( 'ssa_settings' );
		if ( is_string( $settings ) ) {
			$decoded  = json_decode( $settings, true );
			$settings = is_array( $decoded ) ? $decoded : [];
		}

		if ( is_array( $settings ) ) {
			$candidates = [
				$settings['global']['timezone_string'] ?? '',
				$settings['global']['timezone']        ?? '',
				$settings['timezone_string']           ?? '',
				$settings['timezone']                  ?? '',
				$settings['business']['timezone']      ?? '',
			];
			foreach ( $candidates as $c ) {
				if ( is_string( $c ) && self::is_valid_tz( $c ) ) return $c;
			}
		}

		$legacy = (string) get_option( 'ssa_business_timezone', '' );
		if ( self::is_valid_tz( $legacy ) ) return $legacy;

		return '';
	}

	/* ─────────────────────────────────────────────────────────────
	 * Fuseau du CLIENT (cible de la conversion)
	 * ───────────────────────────────────────────────────────────── */

	/**
	 * Ordre : 1) customer_timezone du RDV SSA
	 *         2) user_meta rk_customer_timezone (capté en JS)
	 *         3) fuseau du site
	 */
	public static function customer_tz( int $appt_id = 0, int $user_id = 0 ): \DateTimeZone {
		$key = $appt_id . ':' . $user_id;
		if ( isset( self::$customer_cache[ $key ] ) ) {
			return self::$customer_cache[ $key ];
		}

		global $wpdb;
		$name = '';

		if ( $appt_id > 0 ) {
			$table = $wpdb->prefix . 'ssa_appointments';
			// Évite une erreur SQL si SSA est désactivé
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) {
				$name = (string) $wpdb->get_var( $wpdb->prepare(
					"SELECT customer_timezone FROM {$table} WHERE id = %d",
					$appt_id
				) );
			}
		}

		if ( ! self::is_valid_tz( $name ) && $user_id > 0 ) {
			$name = (string) get_user_meta( $user_id, 'rk_customer_timezone', true );
		}

		$tz = self::is_valid_tz( $name ) ? new \DateTimeZone( $name ) : wp_timezone();

		self::$customer_cache[ $key ] = $tz;
		return $tz;
	}

	/* ─────────────────────────────────────────────────────────────
	 * Formatage
	 * ───────────────────────────────────────────────────────────── */

	/**
	 * Formate une datetime MySQL stockée par SSA dans le fuseau du client.
	 *
	 * @param string $mysql  'Y-m-d H:i:s' tel que stocké en base
	 * @param string $format format PHP/WP
	 */
	/**
	 * AJOUT (demande utilisateur, format exact fourni) — format arabe
	 * complet avec "الساعة" et "صباحاً/مساءً" en toutes lettres (ex.
	 * "السبت، 19 أغسطس الساعة 3:35 مساءً").
	 *
	 * FIX (bug signalé — "Tuesday، 22 September" en anglais au lieu de
	 * l'arabe) — wp_date('l', ...) et wp_date('F', ...) traduisent les
	 * noms de jour/mois selon la LOCALE WORDPRESS DU SITE (get_locale()),
	 * jamais selon le fuseau horaire passé en paramètre. Si le site n'est
	 * pas configuré en langue arabe (Réglages → Général → Langue du
	 * site), ces tokens restent en anglais quel que soit le fuseau —
	 * confirmé par rkConfig.locale (voir Assets.php) qui expose cette
	 * même get_locale() côté JS. Le reste de l'interface de ce plugin est
	 * en arabe indépendamment de cette locale (chaînes codées en dur via
	 * esc_html_e()) — mêmes principe ici : tables de traduction jour/mois
	 * codées en dur, indépendantes de toute configuration serveur.
	 */
	private static function ar_weekday( int $w ): string {
		// $w : 0 (dimanche) → 6 (samedi), format PHP 'w'.
		$days = [ 'الأحد', 'الإثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت' ];
		return $days[ $w ] ?? '';
	}

	private static function ar_month( int $m ): string {
		// $m : 1 (janvier) → 12 (décembre), format PHP 'n'.
		$months = [
			1 => 'يناير', 2 => 'فبراير', 3 => 'مارس', 4 => 'أبريل',
			5 => 'مايو', 6 => 'يونيو', 7 => 'يوليو', 8 => 'أغسطس',
			9 => 'سبتمبر', 10 => 'أكتوبر', 11 => 'نوفمبر', 12 => 'ديسمبر',
		];
		return $months[ $m ] ?? '';
	}

	public static function format_arabic_full( string $mysql, int $appt_id = 0, int $user_id = 0 ): string {
		$mysql = trim( $mysql );
		if ( '' === $mysql || str_starts_with( $mysql, '0000-00-00' ) ) return '—';

		try {
			$dt = new \DateTimeImmutable( $mysql, self::storage_tz() );
		} catch ( \Exception $e ) {
			return '—';
		}

		$ts        = $dt->getTimestamp();
		$tz        = self::customer_tz( $appt_id, $user_id );
		$weekday   = self::ar_weekday( (int) wp_date( 'w', $ts, $tz ) );
		$day       = wp_date( 'j', $ts, $tz );
		$month     = self::ar_month( (int) wp_date( 'n', $ts, $tz ) );
		$time_part = wp_date( 'g:i', $ts, $tz );
		$hour_24   = (int) wp_date( 'G', $ts, $tz );
		$meridiem  = $hour_24 < 12 ? 'صباحاً' : 'مساءً';

		return "{$weekday}، {$day} {$month} الساعة {$time_part} {$meridiem}";
	}

	public static function format( string $mysql, string $format, int $appt_id = 0, int $user_id = 0 ): string {
		$mysql = trim( $mysql );
		if ( '' === $mysql || str_starts_with( $mysql, '0000-00-00' ) ) return '—';

		try {
			$dt = new \DateTimeImmutable( $mysql, self::storage_tz() );
		} catch ( \Exception $e ) {
			return '—';
		}

		return wp_date( $format, $dt->getTimestamp(), self::customer_tz( $appt_id, $user_id ) );
	}

	/** Libellé court du fuseau client (ex. GMT+3) */
	public static function offset_label( int $appt_id = 0, int $user_id = 0 ): string {
		return self::offset_of( self::customer_tz( $appt_id, $user_id ) );
	}

	private static function offset_of( \DateTimeZone $tz ): string {
		$off = $tz->getOffset( new \DateTime( 'now', $tz ) ) / 3600;
		$abs = rtrim( rtrim( number_format( abs( $off ), 1, '.', '' ), '0' ), '.' );
		return sprintf( 'GMT%s%s', $off >= 0 ? '+' : '-', $abs );
	}

	/* ─────────────────────────────────────────────────────────────
	 * Utilitaires / diagnostic
	 * ───────────────────────────────────────────────────────────── */

	private static function is_valid_tz( $name ): bool {
		return is_string( $name ) && $name !== ''
			&& in_array( $name, timezone_identifiers_list(), true );
	}

	/** Nom du fuseau de stockage résolu (ex. "Asia/Riyadh") */
	public static function storage_tz_name(): string {
		return self::storage_tz()->getName();
	}

	/** Vide les caches — à appeler après un changement de réglage SSA */
	public static function flush_cache(): void {
		self::$storage_cache  = null;
		self::$customer_cache = [];
		delete_transient( 'rk_ssa_business_tz' );
	}

	/**
	 * Instantané de l'état des fuseaux, pour diagnostic.
	 *
	 * Usage :
	 *   error_log( print_r( \RiadaKids\Core\TimeZone::debug( 2 ), true ) );
	 */
	public static function debug( int $appt_id = 0, int $user_id = 0 ): array {
		global $wpdb;

		$storage = self::storage_tz();
		$out = [
			'storage_tz'      => $storage->getName(),
			'storage_offset'  => self::offset_of( $storage ),
			'storage_source'  => defined( 'RK_SSA_STORAGE_TZ' ) && RK_SSA_STORAGE_TZ
				? 'constante RK_SSA_STORAGE_TZ'
				: ( self::detect_ssa_timezone() ? 'réglages SSA' : 'fuseau du site (repli)' ),
			'site_tz'         => wp_timezone_string(),
			'php_now'         => ( new \DateTimeImmutable( 'now', wp_timezone() ) )->format( 'Y-m-d H:i:s' ),
			'mysql_now'       => (string) $wpdb->get_var( 'SELECT NOW()' ),
		];

		if ( $appt_id > 0 ) {
			$row = $wpdb->get_row( $wpdb->prepare(
				"SELECT start_date, customer_timezone
				 FROM {$wpdb->prefix}ssa_appointments WHERE id = %d",
				$appt_id
			), ARRAY_A );

			$client = self::customer_tz( $appt_id, $user_id );

			$out['appointment_id']  = $appt_id;
			$out['ssa_start_date']  = $row['start_date'] ?? '(introuvable)';
			$out['ssa_customer_tz'] = $row['customer_timezone'] ?? '(vide)';
			$out['client_tz']       = $client->getName();
			$out['client_offset']   = self::offset_of( $client );
			$out['rendered']        = isset( $row['start_date'] )
				? self::format( $row['start_date'], 'Y-m-d H:i', $appt_id, $user_id )
				: '—';
		}

		return $out;
	}
}