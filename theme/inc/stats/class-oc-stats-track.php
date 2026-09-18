<?php
/**
 * Statistics: measuring visits.
 *
 * WordPress has no idea how many people came. This is the theme's own
 * first-party measurement: the page script sends one small hit per page
 * view, product view, add to cart and checkout start; the server writes a
 * row. Orders come from WooCommerce itself, but the session that placed
 * them, the channel it came from and the device are written onto the order
 * here, so "orders by source" rests on real orders.
 *
 * No names, no emails, no full addresses: a random session id that rotates
 * every 30 minutes of quiet, a channel word and a device letter.
 *
 * @package OC_Theme
 */

declare( strict_types = 1 );

namespace OC\Theme\Stats;

defined( 'ABSPATH' ) || exit;

/**
 * The beacon, the tables and the order attribution.
 */
final class Track {

	/**
	 * Tables, without prefix.
	 */
	const EVENTS = 'oc_stats_events';
	const DAILY  = 'oc_stats_daily';

	/**
	 * Cookies.
	 */
	const SID = 'oc_sid';
	const SRC = 'oc_src';

	/**
	 * Order meta.
	 */
	const META_SID = '_oc_stat_sid';
	const META_CH  = '_oc_stat_ch';
	const META_DEV = '_oc_stat_dev';
	const META_HIT = '_oc_stat_hit';

	/**
	 * Hit types the beacon may send.
	 */
	const TYPES = array( 'view', 'product', 'cat', 'brand', 'atc', 'checkout' );

	/**
	 * Channels, in display order.
	 */
	const CHANNELS = array( 'organic', 'paid_search', 'social', 'paid_social', 'email', 'referral', 'direct' );

	/**
	 * Days the raw hits are kept; the daily summary keeps the totals forever.
	 */
	const RETENTION_DAYS = 60;

	/**
	 * Hook in.
	 */
	public function register(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		add_action( 'rest_api_init', array( $this, 'rest' ) );
		add_action( 'admin_init', array( __CLASS__, 'install' ) );

		// The session, channel and device travel onto the order at creation.
		add_action( 'woocommerce_checkout_order_created', array( $this, 'attribute' ) );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'attribute' ) );

		// A sale is a hit too, once, when the order is paid or in hand.
		add_action( 'woocommerce_order_status_processing', array( $this, 'purchase' ) );
		add_action( 'woocommerce_order_status_completed', array( $this, 'purchase' ) );
	}

	/* ------------------------------------------------------------ pure */

	/**
	 * The host of a URL, lower-case, without "www.".
	 *
	 * @param string $url A URL or a bare host.
	 */
	public static function host( string $url ): string {
		$url  = trim( $url );
		$host = false !== strpos( $url, '://' ) ? (string) wp_parse_url( $url, PHP_URL_HOST ) : $url;
		$host = strtolower( (string) $host );

		return preg_replace( '/^www\./', '', $host ) ?? $host;
	}

	/**
	 * Where a visit came from: one of CHANNELS, or '' for an internal
	 * move (a referrer on this same site) that says nothing new.
	 *
	 * @param string               $referrer The referring URL, '' for none.
	 * @param array<string,string> $query    The landing page's query parameters.
	 * @param string               $site     This site's host.
	 */
	public static function channel( string $referrer, array $query, string $site ): string {
		$q = array();

		foreach ( $query as $k => $v ) {
			$q[ strtolower( (string) $k ) ] = strtolower( trim( (string) $v ) );
		}

		$medium = $q['utm_medium'] ?? '';
		$source = $q['utm_source'] ?? '';
		$paid   = (bool) preg_match( '/^(cpc|ppc|paid|paidsocial|paid_social|paid-social|display|cpm|retargeting)$/', $medium );

		if ( isset( $q['gclid'] ) || isset( $q['gbraid'] ) || isset( $q['wbraid'] ) || isset( $q['msclkid'] ) ) {
			return 'paid_search';
		}

		if ( isset( $q['fbclid'] ) || isset( $q['ttclid'] ) || isset( $q['igshid'] ) ) {
			return $paid || '' !== $source ? 'paid_social' : 'social';
		}

		if ( preg_match( '/^(email|e-mail|newsletter|sms|whatsapp|push)$/', $medium ) || preg_match( '/(activetrail|flashy|mailchimp|klaviyo|inforu|smoove|responder|sendgrid)/', $source ) ) {
			return 'email';
		}

		$social_src = (bool) preg_match( '/(facebook|instagram|meta|tiktok|pinterest|linkedin|twitter|youtube|snapchat)/', $source );

		if ( $paid ) {
			return $social_src ? 'paid_social' : 'paid_search';
		}

		if ( $social_src ) {
			return 'social';
		}

		if ( '' !== $source ) {
			return 'referral';
		}

		$ref = self::host( $referrer );

		if ( '' === $ref ) {
			return 'direct';
		}

		if ( $ref === self::host( $site ) ) {
			return '';
		}

		if ( preg_match( '/(^|\.)(google|bing|yahoo|duckduckgo|yandex|baidu|ecosia|ask)\./', $ref . '.' ) ) {
			return 'organic';
		}

		if ( preg_match( '/(^|\.)(facebook|instagram|tiktok|pinterest|linkedin|twitter|x|youtube|whatsapp|t|l\.instagram|lm\.facebook|snapchat|threads)\.(com|co|net|me)$/', $ref ) || 't.co' === $ref ) {
			return 'social';
		}

		return 'referral';
	}

	/**
	 * The device, as one letter: m (phone), t (tablet), d (desktop).
	 *
	 * @param string $ua The browser's user agent.
	 */
	public static function device( string $ua ): string {
		if ( preg_match( '/iPad|Tablet|PlayBook|Silk|Kindle/i', $ua ) || ( false !== stripos( $ua, 'Android' ) && false === stripos( $ua, 'Mobile' ) ) ) {
			return 't';
		}

		if ( preg_match( '/Mobi|iPhone|iPod|Android|Windows Phone|webOS|BlackBerry/i', $ua ) ) {
			return 'm';
		}

		return 'd';
	}

	/**
	 * Whether a user agent is a robot, a crawler or a link preview.
	 *
	 * @param string $ua The browser's user agent.
	 */
	public static function is_bot( string $ua ): bool {
		if ( '' === trim( $ua ) ) {
			return true;
		}

		return (bool) preg_match( '/bot|crawl|spider|slurp|headless|lighthouse|pagespeed|pingdom|gtmetrix|uptime|monitor|facebookexternalhit|whatsapp|telegrambot|preview|python|curl|wget|okhttp|java\/|libwww|httpclient|scrapy|phantom|selenium|puppeteer|playwright|dataprovider|semrush|ahrefs|mj12|dotbot|petalbot|bytespider|gptbot|claudebot|ccbot|applebot|yandex/i', $ua );
	}

	/**
	 * Only the query parameters that say where a visit came from.
	 *
	 * @param string $query The landing page's query string, with or without "?".
	 * @return array<string,string>
	 */
	public static function source_params( string $query ): array {
		$out  = array();
		$keep = array( 'utm_source', 'utm_medium', 'utm_campaign', 'gclid', 'gbraid', 'wbraid', 'msclkid', 'fbclid', 'ttclid', 'igshid' );

		parse_str( ltrim( $query, '?' ), $parsed );

		foreach ( $keep as $k ) {
			if ( isset( $parsed[ $k ] ) && is_scalar( $parsed[ $k ] ) ) {
				$out[ $k ] = substr( (string) $parsed[ $k ], 0, 100 );
			}
		}

		return $out;
	}

	/* ------------------------------------------------------------ tables */

	/**
	 * The events table, with prefix.
	 */
	public static function events_table(): string {
		global $wpdb;

		return $wpdb->prefix . self::EVENTS;
	}

	/**
	 * The daily summary table, with prefix.
	 */
	public static function daily_table(): string {
		global $wpdb;

		return $wpdb->prefix . self::DAILY;
	}

	/**
	 * The first day visits were counted on this site. Recorded when the
	 * tables are made; for a site that had them before this was recorded,
	 * the earliest day in the events table.
	 */
	public static function since(): string {
		$day = (string) get_option( 'oc_stats_since', '' );

		if ( '' !== $day ) {
			return $day;
		}

		global $wpdb;

		$day = '2' === (string) get_option( 'oc_stats_tables', '' ) ? (string) $wpdb->get_var( 'SELECT MIN(day) FROM ' . self::events_table() ) : ''; // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- own table, once.
		$day = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $day ) ? $day : (string) wp_date( 'Y-m-d' );
		update_option( 'oc_stats_since', $day, false );

		return $day;
	}

	/**
	 * Creates both tables once.
	 */
	public static function install(): void {
		if ( '2' === (string) get_option( 'oc_stats_tables', '' ) ) {
			return;
		}

		add_option( 'oc_stats_since', (string) wp_date( 'Y-m-d' ), '', false );

		global $wpdb;

		$collate = $wpdb->get_charset_collate();
		$events  = self::events_table();
		$daily   = self::daily_table();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta(
			"CREATE TABLE {$events} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				day date NOT NULL,
				t datetime NOT NULL,
				sid char(24) NOT NULL,
				type varchar(10) NOT NULL,
				obj bigint(20) unsigned NOT NULL DEFAULT 0,
				device char(1) NOT NULL DEFAULT 'd',
				channel varchar(12) NOT NULL DEFAULT 'direct',
				val decimal(12,2) NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				KEY day_type (day, type),
				KEY sid (sid),
				KEY t (t)
			) {$collate};"
		);

		dbDelta(
			"CREATE TABLE {$daily} (
				day date NOT NULL,
				metric varchar(24) NOT NULL,
				k varchar(64) NOT NULL DEFAULT '',
				v decimal(14,2) NOT NULL DEFAULT 0,
				PRIMARY KEY  (day, metric, k),
				KEY metric_day (metric, day)
			) {$collate};"
		);

		update_option( 'oc_stats_tables', '2', false );
	}

	/* ------------------------------------------------------------ beacon */

	/**
	 * The route.
	 */
	public function rest(): void {
		register_rest_route(
			'oc/v1',
			'/hit',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( __CLASS__, 'permit' ),
				'callback'            => array( $this, 'rest_hit' ),
			)
		);
	}

	/**
	 * A day token, so only pages this site served can report a hit.
	 *
	 * @param int $shift Days back.
	 */
	public static function token( int $shift = 0 ): string {
		$day = gmdate( 'Y-m-d', time() - $shift * DAY_IN_SECONDS );

		return substr( hash_hmac( 'sha256', 'oc_stats|' . $day, wp_salt( 'nonce' ) ), 0, 20 );
	}

	/**
	 * Today's or yesterday's token.
	 *
	 * @param \WP_REST_Request $req Request.
	 */
	public static function permit( \WP_REST_Request $req ): bool {
		$t = (string) $req->get_param( '_t' );

		return '' !== $t && ( hash_equals( self::token(), $t ) || hash_equals( self::token( 1 ), $t ) );
	}

	/**
	 * The visitor's address, hashed by the caller, for a per-network ceiling.
	 */
	public static function net(): string {
		return class_exists( '\OC\Theme\Privacy\Log' ) ? \OC\Theme\Privacy\Log::net() : (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- hashed by the caller.
	}

	/**
	 * One hit. Answers 204 whatever happens: the page never waits for it.
	 *
	 * @param \WP_REST_Request $req Request.
	 * @return \WP_REST_Response
	 */
	public function rest_hit( \WP_REST_Request $req ) {
		$res = new \WP_REST_Response( null, 204 );

		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- only matched against patterns, never stored.
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		if ( self::is_bot( $ua ) || in_array( $ip, Settings::excluded_ips(), true ) ) {
			return $res;
		}

		if ( class_exists( '\OC\Theme\Privacy\Consent' ) && ! \OC\Theme\Privacy\Consent::allows( 'analytics' ) ) {
			return $res;
		}

		$sid  = strtolower( (string) $req->get_param( 'sid' ) );
		$type = (string) $req->get_param( 't' );
		$obj  = absint( $req->get_param( 'o' ) );

		if ( ! preg_match( '/^[a-f0-9]{24}$/', $sid ) || ! in_array( $type, self::TYPES, true ) ) {
			return $res;
		}

		// A ceiling per network: a page sends a handful of hits, not hundreds.
		$key   = 'oc_stats_rl_' . md5( self::net() );
		$count = (int) get_transient( $key );

		if ( $count > 240 ) {
			return $res;
		}

		set_transient( $key, $count + 1, MINUTE_IN_SECONDS );

		self::install();

		$first   = ! empty( $req->get_param( 'f' ) );
		$channel = '';

		if ( $first ) {
			$channel = self::channel(
				(string) $req->get_param( 'r' ),
				self::source_params( (string) $req->get_param( 'q' ) ),
				(string) wp_parse_url( home_url(), PHP_URL_HOST )
			);
		}

		// Last non-direct click wins for 30 days: a session that opened from
		// an ad keeps the ad's channel even when the buyer comes back
		// directly a week later.
		$stored = isset( $_COOKIE[ self::SRC ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::SRC ] ) ) : '';
		$stored = in_array( $stored, self::CHANNELS, true ) ? $stored : '';

		if ( '' !== $channel && 'direct' !== $channel ) {
			$stored = $channel;
			setcookie( self::SRC, $channel, time() + 30 * DAY_IN_SECONDS, '/', '', is_ssl(), true );
		}

		$channel = '' !== $stored ? $stored : 'direct';

		global $wpdb;

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table.
			self::events_table(),
			array(
				'day'     => wp_date( 'Y-m-d' ),
				't'       => wp_date( 'Y-m-d H:i:s' ),
				'sid'     => $sid,
				'type'    => $type,
				'obj'     => $obj,
				'device'  => self::device( $ua ),
				'channel' => $channel,
				'val'     => 0,
			),
			array( '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%f' )
		);

		return $res;
	}

	/* ------------------------------------------------------------ orders */

	/**
	 * The session, channel and device written onto a fresh order.
	 *
	 * @param mixed $order The order (an id on some hooks).
	 */
	public function attribute( $order ): void {
		$order = $order instanceof \WC_Order ? $order : wc_get_order( $order );

		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$sid = isset( $_COOKIE[ self::SID ] ) ? strtolower( sanitize_text_field( wp_unslash( $_COOKIE[ self::SID ] ) ) ) : '';
		$src = isset( $_COOKIE[ self::SRC ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::SRC ] ) ) : '';
		$ua  = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- reduced to one letter.

		$order->update_meta_data( self::META_SID, preg_match( '/^[a-f0-9]{24}$/', $sid ) ? $sid : '' );
		$order->update_meta_data( self::META_CH, in_array( $src, self::CHANNELS, true ) ? $src : 'direct' );
		$order->update_meta_data( self::META_DEV, self::device( $ua ) );
		$order->save();
	}

	/**
	 * The sale as a hit, once per order.
	 *
	 * @param int $order_id Order id.
	 */
	public function purchase( $order_id ): void {
		$order = wc_get_order( $order_id );

		if ( ! $order instanceof \WC_Order || '' !== (string) $order->get_meta( self::META_HIT ) ) {
			return;
		}

		$order->update_meta_data( self::META_HIT, (string) time() );
		$order->save();

		self::install();

		global $wpdb;

		$channel = (string) $order->get_meta( self::META_CH );
		$device  = (string) $order->get_meta( self::META_DEV );

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table.
			self::events_table(),
			array(
				'day'     => wp_date( 'Y-m-d' ),
				't'       => wp_date( 'Y-m-d H:i:s' ),
				'sid'     => (string) $order->get_meta( self::META_SID ),
				'type'    => 'purchase',
				'obj'     => (int) $order->get_id(),
				'device'  => in_array( $device, array( 'm', 't', 'd' ), true ) ? $device : 'd',
				'channel' => in_array( $channel, self::CHANNELS, true ) ? $channel : 'direct',
				'val'     => (float) $order->get_total(),
			),
			array( '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%f' )
		);
	}

	/**
	 * What the page script needs, per page.
	 *
	 * @return array<string,mixed>
	 */
	public static function for_script(): array {
		$product = function_exists( 'is_product' ) && is_product() ? (int) get_queried_object_id() : 0;
		$cat     = function_exists( 'is_product_category' ) && is_product_category() ? (int) get_queried_object_id() : 0;
		$co      = function_exists( 'is_checkout' ) && is_checkout() && ! is_order_received_page() ? 1 : 0;

		$tax   = Query::brand_taxonomy();
		$brand = '' !== $tax && function_exists( 'is_tax' ) && is_tax( $tax ) ? (int) get_queried_object_id() : 0;

		return array(
			'url'   => rest_url( 'oc/v1/hit' ),
			't'     => self::token(),
			'p'     => $product,
			'c'     => $cat,
			'b'     => $brand,
			'co'    => $co,
			'staff' => current_user_can( 'edit_posts' ) ? 1 : 0,
		);
	}
}
