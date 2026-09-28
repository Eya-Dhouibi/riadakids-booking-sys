<?php
declare( strict_types=1 );
/**
 * RKP_ChildSessionRepository — stockage des sessions enfant en table.
 *
 * ┌──────────────────────────────────────────────────────────────────────────┐
 * │  PHASE 6b-4a (19/08/2026)                                                │
 * │                                                                          │
 * │  Remplace le couple transient + user_meta par une vraie table.           │
 * │                                                                          │
 * │  AVANT                                                                    │
 * │    token   = HMAC déterministe (un seul token possible par enfant)       │
 * │    stockage= transient (volatil) + repli user_meta (1 slot par enfant)   │
 * │    expiration illusoire : le repli user_meta reconstruisait le transient │
 * │    révocation impossible sans rotation de AUTH_KEY                        │
 * │                                                                          │
 * │  APRÈS                                                                    │
 * │    token   = random_bytes(20) → 40 hex (format INCHANGÉ, 25 sites de     │
 * │              validation /^[a-f0-9]{40}$/ intacts)                        │
 * │    stockage= table, SHA-256 du token uniquement                          │
 * │    expiration et révocation réelles, par session                         │
 * │    sessions concurrentes : un enfant peut avoir N sessions actives       │
 * │              (ordinateur, téléphone, tablette, e-mail de quiz…)          │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * ⚠️ Le token BRUT n'est jamais écrit en base. Seul son SHA-256 y figure.
 *    Un dump SQL ne permet donc pas de rejouer une session.
 *
 * @package RK_Platform
 * @since   4.18.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class RKP_ChildSessionRepository {

	/** Durée de vie d'une session : 7 jours (identique à l'ancien TAB_TTL). */
	public const TTL = 604800;

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'rk_child_sessions';   // préfixe dynamique
	}

	/** SHA-256 du token brut — la seule forme qui touche la base. */
	public static function hash( string $token ): string {
		return hash( 'sha256', $token );
	}

	/* ══════════════════════════════════════════════════════════════════
	 * Disponibilité de la table — FAIL CLOSED (P1-1)
	 *
	 * La migration ne tourne que sur `admin_init`. Sur une mise à jour sans
	 * désactivation/réactivation, `register_activation_hook` ne se déclenche
	 * pas : tant qu'aucun administrateur n'ouvre le back-office, la table
	 * n'existe pas.
	 *
	 * Dans cette fenêtre, le comportement doit être DÉTERMINISTE et FERMÉ :
	 * aucune identité enfant accordée, aucun repli sur le parent, aucun repli
	 * sur user_meta ou get_current_user_id(), aucune erreur fatale, et aucun
	 * détail technique exposé au visiteur.
	 *
	 * Le résultat est mémoïsé par requête : un seul SHOW TABLES au maximum.
	 * ══════════════════════════════════════════════════════════════════ */

	/** @var bool|null Mémo par requête. */
	private static ?bool $table_exists = null;

	/** La table est-elle disponible ? */
	public static function is_available(): bool {
		if ( null !== self::$table_exists ) return self::$table_exists;

		global $wpdb;
		$table = self::table();

		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		self::$table_exists = ( $found === $table );

		if ( ! self::$table_exists && function_exists( 'rkp_log' ) ) {
			// Journalisé côté serveur uniquement — rien n'est renvoyé au visiteur.
			rkp_log( '[RK Session] Table ' . $table . ' absente : sessions enfant indisponibles (migration non encore exécutée ?)' );
		}

		return self::$table_exists;
	}

	/** Usage tests uniquement. */
	public static function reset_availability(): void {
		self::$table_exists = null;
	}

	/** Journalise une erreur DB sans jamais l'exposer au visiteur. */
	private static function log_db_error( string $context ): void {
		global $wpdb;
		if ( ! empty( $wpdb->last_error ) && function_exists( 'rkp_log' ) ) {
			rkp_log( '[RK Session] ' . $context . ' : ' . $wpdb->last_error );
		}
	}

	/* ══════════════════════════════════════════════════════════════════
	 * Schéma
	 * ══════════════════════════════════════════════════════════════════ */

	/**
	 * Création idempotente (dbDelta). Appelée par les migrations versionnées
	 * de rkp_run_schema_migrations() — jamais sur le chemin chaud.
	 */
	public static function maybe_create_table(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$collate = $wpdb->get_charset_collate();

		dbDelta( "CREATE TABLE {$table} (
			id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			token_hash    CHAR(64)        NOT NULL,
			child_id      BIGINT UNSIGNED NOT NULL,
			parent_id     BIGINT UNSIGNED NOT NULL DEFAULT 0,
			created_at    DATETIME        NOT NULL,
			expires_at    DATETIME        NOT NULL,
			last_seen_at  DATETIME        DEFAULT NULL,
			revoked_at    DATETIME        DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY token_hash (token_hash),
			KEY child_revoked (child_id, revoked_at),
			KEY expires_at (expires_at)
		) {$collate};" );

		self::$table_exists = null;   // la table vient peut-être d'être créée
	}

	/* ══════════════════════════════════════════════════════════════════
	 * Écriture
	 * ══════════════════════════════════════════════════════════════════ */

	/**
	 * Ouvre une session et retourne le token BRUT (seul moment où il existe).
	 *
	 * N'invalide AUCUNE session existante : les sessions concurrentes sont
	 * autorisées (décision produit 6b-4 §4). Un enfant peut être connecté sur
	 * plusieurs appareils, et un lien de quiz reçu par e-mail reste valide même
	 * si l'enfant ouvre par ailleurs son dashboard.
	 *
	 * @param int $child_wp_uid  wp_user_id de l'enfant.
	 * @param int $parent_wp_uid wp_user_id du parent (0 si inconnu).
	 * @return string 40 hex, ou '' en cas d'échec.
	 */
	public static function open( int $child_wp_uid, int $parent_wp_uid ): string {
		global $wpdb;
		if ( $child_wp_uid <= 0 ) return '';
		if ( ! self::is_available() ) return '';   // fail closed

		try {
			$token = bin2hex( random_bytes( 20 ) );   // 20 octets → 40 hex
		} catch ( \Exception $e ) {
			return '';
		}

		$now = time();
		$ok  = $wpdb->insert(
			self::table(),
			array(
				'token_hash'   => self::hash( $token ),
				'child_id'     => $child_wp_uid,
				'parent_id'    => max( 0, $parent_wp_uid ),
				'created_at'   => gmdate( 'Y-m-d H:i:s', $now ),
				'expires_at'   => gmdate( 'Y-m-d H:i:s', $now + self::TTL ),
				'last_seen_at' => gmdate( 'Y-m-d H:i:s', $now ),
			),
			array( '%s', '%d', '%d', '%s', '%s', '%s' )
		);

		if ( ! $ok ) {
			self::log_db_error( 'open() INSERT échoué' );
			return '';
		}
		return $token;
	}

	/**
	 * Adopte un token EXISTANT dans la table (migration legacy → aléatoire).
	 *
	 * Utilisé une seule fois par token HMAC hérité : le token continue de
	 * fonctionner, mais il est désormais porté par la table, donc révocable
	 * et réellement expirable. Aucun lien déjà distribué n'est cassé.
	 */
	public static function adopt( string $token, int $child_wp_uid, int $parent_wp_uid ): bool {
		global $wpdb;
		if ( $child_wp_uid <= 0 || ! preg_match( '/^[a-f0-9]{40}$/', $token ) ) return false;
		if ( ! self::is_available() ) return false;   // fail closed
		if ( self::find( $token ) ) return true;      // déjà adopté

		$now = time();
		return (bool) $wpdb->insert(
			self::table(),
			array(
				'token_hash'   => self::hash( $token ),
				'child_id'     => $child_wp_uid,
				'parent_id'    => max( 0, $parent_wp_uid ),
				'created_at'   => gmdate( 'Y-m-d H:i:s', $now ),
				'expires_at'   => gmdate( 'Y-m-d H:i:s', $now + self::TTL ),
				'last_seen_at' => gmdate( 'Y-m-d H:i:s', $now ),
			),
			array( '%s', '%d', '%d', '%s', '%s', '%s' )
		);
	}

	/* ══════════════════════════════════════════════════════════════════
	 * Lecture
	 * ══════════════════════════════════════════════════════════════════ */

	/**
	 * Session active correspondant au token, ou null.
	 *
	 * Écarte : token inconnu, révoqué (revoked_at), expiré (expires_at).
	 * Aucun repli d'aucune sorte — un token invalide ne donne jamais accès à
	 * une autre session ni à une autre identité.
	 *
	 * @return object|null
	 */
	public static function find( string $token ): ?object {
		global $wpdb;
		if ( ! preg_match( '/^[a-f0-9]{40}$/', $token ) ) return null;
		if ( ! self::is_available() ) return null;   // fail closed : aucune identité accordée

		$row = $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM ' . self::table() . '
			  WHERE token_hash = %s
			    AND revoked_at IS NULL
			    AND expires_at > %s
			  LIMIT 1',
			self::hash( $token ),
			gmdate( 'Y-m-d H:i:s' )
		) );

		return $row ?: null;
	}

	/** Rafraîchit last_seen_at et prolonge l'expiration (sliding window). */
	public static function touch( string $token ): void {
		global $wpdb;
		if ( ! preg_match( '/^[a-f0-9]{40}$/', $token ) ) return;
		if ( ! self::is_available() ) return;

		$now = time();
		$wpdb->update(
			self::table(),
			array(
				'last_seen_at' => gmdate( 'Y-m-d H:i:s', $now ),
				'expires_at'   => gmdate( 'Y-m-d H:i:s', $now + self::TTL ),
			),
			array( 'token_hash' => self::hash( $token ) ),
			array( '%s', '%s' ),
			array( '%s' )
		);
	}

	/* ══════════════════════════════════════════════════════════════════
	 * Révocation
	 * ══════════════════════════════════════════════════════════════════ */

	/**
	 * Révoque UNE session — celle du token fourni.
	 *
	 * Les autres sessions de l'enfant restent intactes : se déconnecter sur le
	 * téléphone ne doit pas fermer la session de la tablette (6b-4 §4).
	 */
	public static function revoke( string $token ): void {
		global $wpdb;
		if ( ! preg_match( '/^[a-f0-9]{40}$/', $token ) ) return;
		if ( ! self::is_available() ) return;

		$wpdb->update(
			self::table(),
			array( 'revoked_at' => gmdate( 'Y-m-d H:i:s' ) ),
			array( 'token_hash' => self::hash( $token ), 'revoked_at' => null ),
			array( '%s' ),
			array( '%s', '%s' )
		);
	}

	/**
	 * Révoque TOUTES les sessions actives d'un enfant.
	 * Usage : lien compromis, appareil perdu, départ d'un coach.
	 *
	 * @return int Nombre de sessions révoquées.
	 */
	public static function revoke_all_for_child( int $child_wp_uid ): int {
		global $wpdb;
		if ( $child_wp_uid <= 0 || ! self::is_available() ) return 0;

		return (int) $wpdb->query( $wpdb->prepare(
			'UPDATE ' . self::table() . '
			    SET revoked_at = %s
			  WHERE child_id = %d AND revoked_at IS NULL',
			gmdate( 'Y-m-d H:i:s' ),
			$child_wp_uid
		) );
	}

	/**
	 * Révoque TOUTES les sessions actives DÉLÉGUÉES par un parent.
	 *
	 * Sémantique (4.18.14) : une session onglet ouverte depuis l'espace du
	 * parent est une DÉLÉGATION de la session du parent, pas une session
	 * autonome. Quand le parent se déconnecte, la délégation prend fin.
	 *
	 * Les sessions à parent_id = 0 (login enfant direct par PIN, lien de quiz
	 * reçu par e-mail) ne sont PAS concernées : elles n'ont jamais été
	 * déléguées par personne et restent actives.
	 *
	 * @return int Nombre de sessions révoquées.
	 */
	public static function revoke_all_for_parent( int $parent_wp_uid ): int {
		global $wpdb;
		if ( $parent_wp_uid <= 0 || ! self::is_available() ) return 0;

		return (int) $wpdb->query( $wpdb->prepare(
			'UPDATE ' . self::table() . '
			    SET revoked_at = %s
			  WHERE parent_id = %d AND revoked_at IS NULL',
			gmdate( 'Y-m-d H:i:s' ),
			$parent_wp_uid
		) );
	}

	/**
	 * Le token est-il CONNU de la table, quel que soit son état ?
	 *
	 * Sert à distinguer « token jamais vu » (→ chemin legacy éventuel) de
	 * « token connu mais révoqué/expiré » (→ refus immédiat, aucun repli).
	 * Sans cette distinction, un cache transient encore chaud pouvait
	 * ressusciter une session déjà révoquée en base.
	 */
	public static function is_known( string $token ): bool {
		global $wpdb;
		if ( ! preg_match( '/^[a-f0-9]{40}$/', $token ) ) return false;
		if ( ! self::is_available() ) return false;

		return (bool) $wpdb->get_var( $wpdb->prepare(
			'SELECT 1 FROM ' . self::table() . ' WHERE token_hash = %s LIMIT 1',
			self::hash( $token )
		) );
	}

	/**
	 * Décrit un token CONNU, quel que soit son état (actif, révoqué, expiré).
	 *
	 * find() ne rend que les sessions vivantes — parfait pour authentifier,
	 * inutile pour comprendre ce qui vient de mourir. Or c'est exactement la
	 * question posée après une perte de session : la session défunte était-elle
	 * DÉLÉGUÉE par un parent (parent_id > 0) ou AUTONOME (parent_id = 0) ?
	 * De cette réponse dépend la page vers laquelle renvoyer l'utilisateur.
	 *
	 * Ne renvoie aucun secret : ni token, ni hash — seulement l'identité du
	 * délégant et l'état.
	 *
	 * @return array{parent_id:int,child_id:int,revoked:bool,expired:bool}|null
	 */
	public static function describe( string $token ): ?array {
		global $wpdb;
		if ( ! preg_match( '/^[a-f0-9]{40}$/', $token ) ) return null;
		if ( ! self::is_available() ) return null;

		$row = $wpdb->get_row( $wpdb->prepare(
			'SELECT parent_id, child_id, revoked_at, expires_at FROM ' . self::table() . '
			  WHERE token_hash = %s LIMIT 1',
			self::hash( $token )
		) );
		if ( ! $row ) return null;

		return array(
			'parent_id' => (int) $row->parent_id,
			'child_id'  => (int) $row->child_id,
			'revoked'   => null !== $row->revoked_at,
			'expired'   => strtotime( (string) $row->expires_at ) <= time(),
		);
	}

	/** Sessions actives d'un enfant (diagnostic). */
	public static function count_active_for_child( int $child_wp_uid ): int {
		global $wpdb;
		if ( ! self::is_available() ) return 0;
		return (int) $wpdb->get_var( $wpdb->prepare(
			'SELECT COUNT(*) FROM ' . self::table() . '
			  WHERE child_id = %d AND revoked_at IS NULL AND expires_at > %s',
			$child_wp_uid,
			gmdate( 'Y-m-d H:i:s' )
		) );
	}

	/** Purge des sessions expirées/révoquées depuis plus de 30 jours. */
	public static function purge_stale(): int {
		global $wpdb;
		if ( ! self::is_available() ) return 0;
		return (int) $wpdb->query( $wpdb->prepare(
			'DELETE FROM ' . self::table() . '
			  WHERE (expires_at < %s) OR (revoked_at IS NOT NULL AND revoked_at < %s)',
			gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ),
			gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS )
		) );
	}
}
