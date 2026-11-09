<?php
/**
 * Offers: what each one did — how often it was seen, how often it was
 * added, and what it sold.
 *
 * One small table of daily counters, keyed by where the offer came from (a
 * rule, a hand-picked product, the automatic layer), where it was shown,
 * the product it stood beside and the product offered. Counters only: no
 * visitor, no session, nothing that says who.
 *
 * "Seen" is the one number the server cannot know by itself — the cart
 * panel is drawn on every page and opened on few — so the page reports it,
 * with the same kind of day token the statistics use: only a page this
 * site served today or yesterday can add to the count.
 *
 * @package OC_Theme
 */

declare( strict_types = 1 );

namespace OC\Theme\Offers;

defined( 'ABSPATH' ) || exit;

/**
 * Counters.
 */
final class Stats {

	/**
	 * Table name, without the prefix.
	 */
	const TABLE = 'oc_offer_stats';

	/**
	 * Bump when the table's shape changes.
	 */
	const DB_VERSION = '1';

	/**
	 * The counters a row holds.
	 */
	const COUNTERS = array( 'seen', 'added', 'orders', 'revenue' );

	/**
	 * Hook in.
	 */
	public function register(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		add_action( 'admin_init', array( __CLASS__, 'install' ) );
		add_action( 'rest_api_init', array( $this, 'rest' ) );
	}

	/**
	 * The table, with its prefix.
	 */
	public static function table(): string {
		global $wpdb;

		return $wpdb->prefix . self::TABLE;
	}

	/**
	 * Create or update the table.
	 */
	public static function install(): void {
		if ( self::DB_VERSION === (string) get_option( 'oc_offers_db' ) ) {
			return;
		}

		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();

		dbDelta(
			'CREATE TABLE ' . self::table() . " (
			day DATE NOT NULL,
			src VARCHAR(40) NOT NULL DEFAULT '',
			place VARCHAR(12) NOT NULL DEFAULT '',
			for_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			offer_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			seen INT UNSIGNED NOT NULL DEFAULT 0,
			added INT UNSIGNED NOT NULL DEFAULT 0,
			orders INT UNSIGNED NOT NULL DEFAULT 0,
			revenue DECIMAL(12,2) NOT NULL DEFAULT 0,
			PRIMARY KEY  (day,src,place,for_id,offer_id)
			) $charset;"
		);

		update_option( 'oc_offers_db', self::DB_VERSION, false );
	}

	/**
	 * Add to today's counters for one offer.
	 *
	 * @param string              $src      Where the offer came from.
	 * @param string              $place    Where it was shown.
	 * @param int                 $for_id   The product it stood beside.
	 * @param int                 $offer_id The product offered.
	 * @param array<string,float> $add      Counter => amount to add.
	 */
	public static function bump( string $src, string $place, int $for_id, int $offer_id, array $add ): void {
		if ( ! Rules::is_source( $src ) || ! in_array( $place, Rules::PLACES, true ) || $for_id <= 0 || $offer_id <= 0 ) {
			return;
		}

		// The table is made on the first admin page view after the theme
		// arrives. An offer cannot be shown before someone switched offers
		// on in the admin, so by now it is there.
		if ( self::DB_VERSION !== (string) get_option( 'oc_offers_db' ) ) {
			return;
		}

		global $wpdb;

		$seen    = max( 0, (int) ( $add['seen'] ?? 0 ) );
		$added   = max( 0, (int) ( $add['added'] ?? 0 ) );
		$orders  = max( 0, (int) ( $add['orders'] ?? 0 ) );
		$revenue = max( 0.0, (float) ( $add['revenue'] ?? 0 ) );

		$table = self::table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own counters table, name from $wpdb->prefix.
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (day, src, place, for_id, offer_id, seen, added, orders, revenue)
				VALUES (%s, %s, %s, %d, %d, %d, %d, %d, %f)
				ON DUPLICATE KEY UPDATE seen = seen + VALUES(seen), added = added + VALUES(added), orders = orders + VALUES(orders), revenue = revenue + VALUES(revenue)",
				wp_date( 'Y-m-d' ),
				$src,
				$place,
				$for_id,
				$offer_id,
				$seen,
				$added,
				$orders,
				$revenue
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * The last days' totals, per source.
	 *
	 * @param int $days How many days back, today included.
	 * @return array<string,array{seen:int,added:int,orders:int,revenue:float}>
	 */
	public static function totals( int $days = 30 ): array {
		if ( self::DB_VERSION !== (string) get_option( 'oc_offers_db' ) ) {
			return array();
		}

		global $wpdb;

		$since = wp_date( 'Y-m-d', time() - ( max( 1, $days ) - 1 ) * DAY_IN_SECONDS );
		$table = self::table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own counters table, read on one admin screen.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT src, SUM(seen) AS seen, SUM(added) AS added, SUM(orders) AS orders, SUM(revenue) AS revenue
				FROM {$table} WHERE day >= %s GROUP BY src",
				$since
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$out = array();

		foreach ( (array) $rows as $row ) {
			$out[ (string) $row['src'] ] = array(
				'seen'    => (int) $row['seen'],
				'added'   => (int) $row['added'],
				'orders'  => (int) $row['orders'],
				'revenue' => (float) $row['revenue'],
			);
		}

		return $out;
	}

	/**
	 * The last days' totals for a set of products the offers stood beside.
	 *
	 * @param int[] $ids  Product ids.
	 * @param int   $days How many days back, today included.
	 * @return array<int,array{seen:int,added:int,orders:int,revenue:float}>
	 */
	public static function by_products( array $ids, int $days = 30 ): array {
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );

		if ( ! $ids || self::DB_VERSION !== (string) get_option( 'oc_offers_db' ) ) {
			return array();
		}

		global $wpdb;

		$since = wp_date( 'Y-m-d', time() - ( max( 1, $days ) - 1 ) * DAY_IN_SECONDS );
		$table = self::table();
		$marks = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- own counters table; the placeholders are built from the count of ids.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT for_id, SUM(seen) AS seen, SUM(added) AS added, SUM(orders) AS orders, SUM(revenue) AS revenue
				FROM {$table} WHERE day >= %s AND for_id IN ({$marks}) GROUP BY for_id",
				array_merge( array( $since ), $ids )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

		$out = array();

		foreach ( (array) $rows as $row ) {
			$out[ (int) $row['for_id'] ] = array(
				'seen'    => (int) $row['seen'],
				'added'   => (int) $row['added'],
				'orders'  => (int) $row['orders'],
				'revenue' => (float) $row['revenue'],
			);
		}

		return $out;
	}

	/* -------------------------------------------------------------- seen */

	/**
	 * A day token, so only pages this site served can report.
	 *
	 * @param int $shift Days back.
	 */
	public static function token( int $shift = 0 ): string {
		$day = gmdate( 'Y-m-d', time() - $shift * DAY_IN_SECONDS );

		return substr( hash_hmac( 'sha256', 'oc_offers|' . $day, wp_salt( 'nonce' ) ), 0, 20 );
	}

	/**
	 * The route.
	 */
	public function rest(): void {
		register_rest_route(
			'oc/v1',
			'/offer-seen',
			array(
				'methods'             => 'POST',
				'permission_callback' => static function ( \WP_REST_Request $req ): bool {
					$t = (string) $req->get_param( '_t' );

					return '' !== $t && ( hash_equals( self::token(), $t ) || hash_equals( self::token( 1 ), $t ) );
				},
				'callback'            => array( $this, 'rest_seen' ),
			)
		);
	}

	/**
	 * One sighting. Answers 204 whatever happens: the page never waits.
	 *
	 * @param \WP_REST_Request $req Request.
	 * @return \WP_REST_Response
	 */
	public function rest_seen( \WP_REST_Request $req ) {
		$res = new \WP_REST_Response( null, 204 );

		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- only matched against patterns, never stored.

		if ( ! Offers::on() || \OC\Theme\Stats\Track::is_bot( $ua ) ) {
			return $res;
		}

		self::bump(
			(string) $req->get_param( 's' ),
			(string) $req->get_param( 'p' ),
			absint( $req->get_param( 'f' ) ),
			absint( $req->get_param( 'o' ) ),
			array( 'seen' => 1 )
		);

		return $res;
	}
}
