<?php
/**
 * The consent log: proof, kept small.
 *
 * A regulator asks one question — can you show that this visitor agreed,
 * when, and to what? — so every answer the banner records is written
 * here: the random id the browser also keeps in its cookie, the moment,
 * the three flags, the mode the visitor was shown, the policy version,
 * and a coarse fingerprint (the network, not the address; a hash of the
 * browser, not the browser). Nothing here names a person.
 *
 * The browser reports through a REST route that a cached page can still
 * reach: a page carries no nonce that would survive four hours in a cache,
 * so the route is guarded by a day-scoped token derived from the site's
 * salts, plus a per-network rate limit.
 *
 * @package OC\Theme
 */

declare( strict_types = 1 );

namespace OC\Theme\Privacy;

defined( 'ABSPATH' ) || exit;

/**
 * Consent records.
 */
final class Log {

	const TABLE = 'oc_consent';
	const CRON  = 'oc_privacy_sweep';

	/**
	 * Hooks.
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'rest' ) );
		add_action( 'init', array( $this, 'schedule' ) );
		add_action( self::CRON, array( __CLASS__, 'sweep' ) );
	}

	/**
	 * The table, with its prefix.
	 */
	public static function table(): string {
		global $wpdb;

		return $wpdb->prefix . self::TABLE;
	}

	/**
	 * Creates the table the first time it is needed. Cheap to call: one
	 * option read after the first run.
	 */
	public static function install(): void {
		if ( '1' === (string) get_option( 'oc_consent_table', '' ) ) {
			return;
		}

		global $wpdb;

		$table   = self::table();
		$collate = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				consent_id varchar(40) NOT NULL DEFAULT '',
				given_at datetime NOT NULL,
				prefs tinyint(1) NOT NULL DEFAULT 0,
				analytics tinyint(1) NOT NULL DEFAULT 0,
				marketing tinyint(1) NOT NULL DEFAULT 0,
				mode varchar(10) NOT NULL DEFAULT '',
				region varchar(2) NOT NULL DEFAULT '',
				policy varchar(20) NOT NULL DEFAULT '',
				net varchar(45) NOT NULL DEFAULT '',
				agent varchar(16) NOT NULL DEFAULT '',
				lang varchar(10) NOT NULL DEFAULT '',
				PRIMARY KEY  (id),
				KEY consent_id (consent_id),
				KEY given_at (given_at)
			) {$collate};"
		);

		update_option( 'oc_consent_table', '1', false );
	}

	/**
	 * A token the page can carry through any cache: the same for a whole
	 * day, derived from the salts, never stored. Yesterday's is accepted
	 * too, for a page cached just before midnight.
	 *
	 * @param int $shift Days back.
	 */
	public static function token( int $shift = 0 ): string {
		$day = gmdate( 'Y-m-d', time() - $shift * DAY_IN_SECONDS );

		return substr( hash_hmac( 'sha256', 'oc_consent|' . $day, wp_salt( 'nonce' ) ), 0, 20 );
	}

	/**
	 * Is this a token we handed out today or yesterday?
	 *
	 * @param string $t Token from the request.
	 */
	public static function token_ok( string $t ): bool {
		return '' !== $t && ( hash_equals( self::token(), $t ) || hash_equals( self::token( 1 ), $t ) );
	}

	/**
	 * The routes.
	 */
	public function rest(): void {
		register_rest_route(
			'oc/v1',
			'/consent',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( __CLASS__, 'permit' ),
				'callback'            => array( $this, 'rest_record' ),
			)
		);

		register_rest_route(
			'oc/v1',
			'/consent/region',
			array(
				'methods'             => 'GET',
				'permission_callback' => array( __CLASS__, 'permit' ),
				'callback'            => array( $this, 'rest_region' ),
			)
		);
	}

	/**
	 * The page's day token, in the body or the query, plus a rate limit
	 * per network so nobody fills the table from one machine.
	 *
	 * @param \WP_REST_Request $req Request.
	 */
	public static function permit( \WP_REST_Request $req ): bool {
		if ( ! Settings::on() ) {
			return false;
		}

		if ( ! self::token_ok( (string) $req->get_param( '_t' ) ) ) {
			return false;
		}

		// Per network, not per address: an office or a campus shares one,
		// so the ceiling is generous — it only stops a script hammering
		// the table, never a busy hour.
		$key = 'oc_consent_rl_' . md5( self::net() );
		$n   = (int) get_transient( $key );

		if ( $n >= 600 ) {
			return false;
		}

		set_transient( $key, $n + 1, HOUR_IN_SECONDS );

		return true;
	}

	/**
	 * Where the visitor is, for a page that came out of a cache and
	 * cannot know. Never cached itself.
	 *
	 * @param \WP_REST_Request $req Request.
	 */
	public function rest_region( \WP_REST_Request $req ): \WP_REST_Response { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- the signature is WordPress's.
		$country = Consent::country();

		$res = new \WP_REST_Response(
			array(
				'country' => $country,
				'mode'    => Consent::mode_for( $country ),
			)
		);

		$res->header( 'Cache-Control', 'no-store, private' );

		return $res;
	}

	/**
	 * One answer, recorded.
	 *
	 * @param \WP_REST_Request $req Request.
	 */
	public function rest_record( \WP_REST_Request $req ): \WP_REST_Response {
		if ( empty( Settings::get()['log'] ) ) {
			return new \WP_REST_Response(
				array(
					'ok'     => true,
					'logged' => false,
				)
			);
		}

		$id = preg_replace( '/[^a-z0-9-]/i', '', (string) $req->get_param( 'id' ) );

		if ( '' === $id || strlen( $id ) > 40 ) {
			return new \WP_REST_Response( array( 'ok' => false ), 400 );
		}

		self::install();

		global $wpdb;

		$mode = (string) $req->get_param( 'mode' );
		$ua   = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- hashed, never stored.

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table.
			self::table(),
			array(
				'consent_id' => $id,
				'given_at'   => gmdate( 'Y-m-d H:i:s' ),
				'prefs'      => (int) ! empty( $req->get_param( 'p' ) ),
				'analytics'  => (int) ! empty( $req->get_param( 'a' ) ),
				'marketing'  => (int) ! empty( $req->get_param( 'm' ) ),
				'mode'       => in_array( $mode, array( 'optin', 'optout' ), true ) ? $mode : '',
				'region'     => substr( preg_replace( '/[^A-Z]/', '', strtoupper( (string) $req->get_param( 'region' ) ) ), 0, 2 ),
				'policy'     => substr( preg_replace( '/[^a-z0-9:]/', '', (string) $req->get_param( 'pv' ) ), 0, 20 ),
				'net'        => self::net(),
				'agent'      => substr( md5( $ua ), 0, 16 ),
				'lang'       => substr( sanitize_key( (string) $req->get_param( 'lang' ) ), 0, 10 ),
			),
			array( '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		$res = new \WP_REST_Response(
			array(
				'ok'     => true,
				'logged' => true,
			)
		);
		$res->header( 'Cache-Control', 'no-store, private' );

		return $res;
	}

	/**
	 * The visitor's network, not their address: the last octet of an IPv4
	 * dropped, an IPv6 cut to its /48. Enough to tell two answers apart,
	 * not enough to find a person.
	 */
	public static function net(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			return preg_replace( '/\.\d+$/', '.0', $ip );
		}

		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			$parts = explode( ':', $ip );

			return implode( ':', array_slice( $parts, 0, 3 ) ) . '::';
		}

		return '';
	}

	/**
	 * The last rows, newest first.
	 *
	 * @param int $limit How many.
	 * @param int $offset From where.
	 * @return array<int,array<string,mixed>>
	 */
	public static function recent( int $limit = 50, int $offset = 0 ): array {
		if ( '1' !== (string) get_option( 'oc_consent_table', '' ) ) {
			return array();
		}

		global $wpdb;

		$table = self::table();

		return (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table; the name is ours, the numbers are prepared.
			$wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d", $limit, $offset ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from the prefix.
			ARRAY_A
		);
	}

	/**
	 * How many rows there are.
	 */
	public static function count(): int {
		if ( '1' !== (string) get_option( 'oc_consent_table', '' ) ) {
			return 0;
		}

		global $wpdb;

		$table = self::table();

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- our own table, no input.
	}

	/**
	 * Everything, for a CSV.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function all(): array {
		if ( '1' !== (string) get_option( 'oc_consent_table', '' ) ) {
			return array();
		}

		global $wpdb;

		$table = self::table();

		return (array) $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id ASC", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- our own table, no input.
	}

	/**
	 * Empties the log.
	 */
	public static function purge(): void {
		if ( '1' !== (string) get_option( 'oc_consent_table', '' ) ) {
			return;
		}

		global $wpdb;

		$table = self::table();

		$wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- our own table, no input.
	}

	/**
	 * A daily sweep, booked once.
	 */
	public function schedule(): void {
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON );
		}
	}

	/**
	 * Drops rows older than the retention the settings name.
	 */
	public static function sweep(): void {
		if ( '1' !== (string) get_option( 'oc_consent_table', '' ) ) {
			return;
		}

		global $wpdb;

		$table = self::table();
		$days  = (int) Settings::get()['log_days'];

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table.
			$wpdb->prepare( "DELETE FROM {$table} WHERE given_at < %s", gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from the prefix.
		);
	}
}
