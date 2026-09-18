<?php
/**
 * Heat maps: where visitors click on a page, how far down they get, and
 * where they stop.
 *
 * Nothing here runs a query while a page is being built. A page asks one
 * cached option whether it is watched; the browser keeps what it sees in
 * memory and sends it once, when the visitor leaves. Clicks are not kept
 * one row each — they fall into a grid two per cent wide and forty pixels
 * tall, so a million clicks are a few hundred rows.
 *
 * @package OC_Stats
 */

namespace OC\Stats;

defined( 'ABSPATH' ) || exit;

/**
 * The recording side, the storage, and everything the screens read.
 */
class Heat {

	/**
	 * Tables, without the prefix.
	 */
	const PAGES = 'oc_stats_pages';
	const VIEWS = 'oc_stats_pviews';
	const GRID  = 'oc_stats_heat';
	const DEPTH = 'oc_stats_depth';

	/**
	 * The schema version, and the option the tracked set lives in.
	 */
	const SCHEMA = 'oc_stats_heat_tables';
	const SET    = 'oc_stats_heat_set';

	/**
	 * Settings.
	 */
	const OPTION = 'oc_stats_heat';

	/**
	 * How far back a map may be read, in days.
	 */
	const KEEP = 90;

	/**
	 * Days kept one by one; older ones are folded into their week.
	 */
	const DAILY = 30;

	/**
	 * The grid: x in whole per cent steps of this size, y in pixels.
	 */
	const XSTEP = 2;
	const YSTEP = 40;

	/**
	 * The kinds of page that may be watched, and how many of the ones that
	 * are chosen by traffic to take.
	 */
	const KINDS = array( 'home', 'product', 'slow', 'cat', 'cart', 'checkout', 'search', 'e404' );

	/**
	 * Hook in.
	 */
	public function register(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		add_action( 'rest_api_init', array( $this, 'rest' ) );
		add_action( 'admin_init', array( __CLASS__, 'install' ) );
	}

	/* --------------------------------------------------------- settings */

	/**
	 * What is switched on.
	 *
	 * @return array{on:int,kinds:array<string,int>,per:int}
	 */
	public static function settings(): array {
		$s = get_option( self::OPTION );
		$s = is_array( $s ) ? $s : array();

		$kinds = array();

		foreach ( self::KINDS as $k ) {
			$kinds[ $k ] = isset( $s['kinds'][ $k ] ) ? (int) $s['kinds'][ $k ] : 1;
		}

		return array(
			'on'    => isset( $s['on'] ) ? (int) $s['on'] : 1,
			'kinds' => $kinds,
			'per'   => max( 1, min( 20, (int) ( $s['per'] ?? 5 ) ) ),
		);
	}

	/**
	 * Is the whole thing on?
	 */
	public static function on(): bool {
		return 1 === self::settings()['on'] && '1' === (string) get_option( self::SCHEMA, '' );
	}

	/* ------------------------------------------------- the watched pages */

	/**
	 * The set of pages worth watching, worked out once an hour and kept in
	 * one option so a page render costs nothing to check.
	 *
	 * @return array{products:int[],cats:int[],when:string}
	 */
	public static function set(): array {
		$s = get_option( self::SET );

		return is_array( $s ) ? $s + array(
			'products' => array(),
			'cats'     => array(),
			'when'     => '',
		) : array(
			'products' => array(),
			'cats'     => array(),
			'when'     => '',
		);
	}

	/**
	 * Choose the pages: the busiest products, the ones plenty of people
	 * look at and few people buy, and the busiest categories. The second
	 * group is the interesting one — the first only confirms what the shop
	 * owner already knows.
	 */
	public static function refresh(): void {
		if ( ! self::on() ) {
			return;
		}

		$per   = self::settings()['per'];
		$range = Query::range( 'd30' );
		$cur   = $range['cur'];

		$views = (array) $cur['product_views'];
		arsort( $views );

		$top = array_slice( array_keys( $views ), 0, $per );

		// Seen a lot, sold little: the same views, ranked by how few of
		// them turned into an order. Ten views is the floor, or every
		// forgotten product with two views would win.
		$slow = array();

		foreach ( $views as $pid => $n ) {
			if ( (int) $n < 10 || in_array( (int) $pid, array_map( 'intval', $top ), true ) ) {
				continue;
			}

			$orders       = (int) ( $cur['product_orders'][ $pid ] ?? 0 );
			$slow[ $pid ] = $orders / max( 1, (int) $n );
		}

		asort( $slow );

		$cats = (array) $cur['cat_views'];
		arsort( $cats );

		update_option(
			self::SET,
			array(
				'products' => array_values( array_unique( array_map( 'intval', array_merge( $top, array_slice( array_keys( $slow ), 0, $per ) ) ) ) ),
				'cats'     => array_values( array_map( 'intval', array_slice( array_keys( $cats ), 0, $per ) ) ),
				'when'     => gmdate( 'Y-m-d H:i' ),
			),
			false
		);
	}

	/**
	 * Which watched surface this request is, or '' for one that is not
	 * watched. Reads nothing but the cached set.
	 */
	public static function surface(): string {
		if ( ! self::on() || is_admin() ) {
			return '';
		}

		$k   = self::settings()['kinds'];
		$set = self::set();

		if ( is_front_page() && ! empty( $k['home'] ) ) {
			return 'home';
		}

		if ( function_exists( 'is_cart' ) && is_cart() && ! empty( $k['cart'] ) ) {
			return 'cart';
		}

		if ( function_exists( 'is_checkout' ) && is_checkout() && ! is_order_received_page() && ! empty( $k['checkout'] ) ) {
			return 'checkout';
		}

		if ( is_search() && ! empty( $k['search'] ) ) {
			return 'search';
		}

		if ( is_404() && ! empty( $k['e404'] ) ) {
			return 'e404';
		}

		if ( function_exists( 'is_product' ) && is_product() && in_array( (int) get_queried_object_id(), $set['products'], true ) ) {
			return empty( $k['product'] ) ? '' : 'product';
		}

		if ( function_exists( 'is_product_category' ) && is_product_category() && in_array( (int) get_queried_object_id(), $set['cats'], true ) ) {
			return empty( $k['cat'] ) ? '' : 'cat';
		}

		return '';
	}

	/**
	 * What the page hands the browser. An empty array means "do not
	 * record", and the script then does nothing at all.
	 *
	 * @return array<string,mixed>
	 */
	public static function for_script(): array {
		if ( ! self::on() || current_user_can( 'edit_posts' ) ) {
			return array();
		}

		// Where a visit turns into an order: nothing is recorded here, but
		// what the visit kept on its way is handed over and marked as a
		// visit that bought.
		if ( function_exists( 'is_order_received_page' ) && is_order_received_page() ) {
			return array(
				'url'   => rest_url( 'oc/v1/heat' ),
				't'     => Track::token(),
				'flush' => 1,
			);
		}

		$kind = self::surface();

		if ( '' === $kind ) {
			return array();
		}

		return array(
			'url'   => rest_url( 'oc/v1/heat' ),
			't'     => Track::token(),
			'kind'  => $kind,
			'label' => self::label( $kind ),
			'max'   => 60,
		);
	}

	/**
	 * A name a person will recognise in the table.
	 *
	 * @param string $kind Surface.
	 */
	private static function label( string $kind ): string {
		switch ( $kind ) {
			case 'home':
				return __( 'Home page', 'oc-stats' );
			case 'cart':
				return __( 'Cart', 'oc-stats' );
			case 'checkout':
				return __( 'Checkout', 'oc-stats' );
			case 'search':
				return __( 'Search results', 'oc-stats' );
			case 'e404':
				return __( 'Page not found', 'oc-stats' );
			default:
				return wp_strip_all_tags( (string) get_the_title( get_queried_object_id() ) );
		}
	}

	/* ------------------------------------------------------------ intake */

	/**
	 * The route the browser reports to.
	 */
	public function rest(): void {
		register_rest_route(
			'oc/v1',
			'/heat',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( '\OC\Stats\Track', 'permit' ),
				'callback'            => array( $this, 'take' ),
			)
		);
	}

	/**
	 * One page view's worth of marks, sent as the visitor leaves.
	 *
	 * @param \WP_REST_Request $req Request.
	 */
	public function take( \WP_REST_Request $req ) {
		if ( ! self::on() ) {
			return new \WP_REST_Response( null, 204 );
		}

		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- read for its shape only.

		if ( Track::is_bot( $ua ) || ! Track::may_count() ) {
			return new \WP_REST_Response( null, 204 );
		}

		$path = self::path( (string) $req->get_param( 'path' ) );
		$kind = sanitize_key( (string) $req->get_param( 'kind' ) );

		if ( '' === $path || ! in_array( $kind, self::KINDS, true ) ) {
			return new \WP_REST_Response( null, 204 );
		}

		$page = self::page_id( $path, $kind, sanitize_text_field( (string) $req->get_param( 'label' ) ) );

		if ( ! $page ) {
			return new \WP_REST_Response( null, 204 );
		}

		$device = Track::device( $ua );
		$seg    = 'b' === sanitize_key( (string) $req->get_param( 'seg' ) ) ? 'b' : 'a';
		$day    = Query::today();

		self::count_view( $day, $page, $device, $seg );
		self::count_marks( $day, $page, $device, $seg, (array) $req->get_param( 'marks' ) );
		self::count_depth( $day, $page, $device, $seg, (array) $req->get_param( 'depth' ) );

		return new \WP_REST_Response( null, 204 );
	}

	/**
	 * A path, trimmed to what identifies a page.
	 *
	 * @param string $raw As the browser sent it.
	 */
	public static function path( string $raw ): string {
		$p = wp_parse_url( $raw, PHP_URL_PATH );
		$p = is_string( $p ) ? $p : '';
		$p = '/' . trim( $p, '/' );

		return strlen( $p ) > 180 ? substr( $p, 0, 180 ) : $p;
	}

	/**
	 * The row for a path, made the first time the path is seen.
	 *
	 * @param string $path  Path.
	 * @param string $kind  Surface.
	 * @param string $label A readable name.
	 */
	private static function page_id( string $path, string $kind, string $label ): int {
		global $wpdb;

		$t  = $wpdb->prefix . self::PAGES;
		$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t} WHERE path = %s", $path ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table.

		if ( $id > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table.
			$wpdb->update( $t, array( 'seen' => Query::today() ), array( 'id' => $id ), array( '%s' ), array( '%d' ) );

			return $id;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table.
		$wpdb->insert(
			$t,
			array(
				'path'  => $path,
				'kind'  => $kind,
				'label' => '' === $label ? $path : mb_substr( $label, 0, 180 ),
				'seen'  => Query::today(),
			),
			array( '%s', '%s', '%s', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * One more view of a page.
	 *
	 * @param string $day    Y-m-d.
	 * @param int    $page   Page.
	 * @param string $device m, t or d.
	 * @param string $seg    a for everyone, b for a visit that bought.
	 */
	private static function count_view( string $day, int $page, string $device, string $seg ): void {
		global $wpdb;

		$t = $wpdb->prefix . self::VIEWS;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table, values prepared.
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$t} (day, page_id, device, seg, views) VALUES (%s, %d, %s, %s, 1) ON DUPLICATE KEY UPDATE views = views + 1", $day, $page, $device, $seg ) );
	}

	/**
	 * The clicks, folded into the grid.
	 *
	 * @param string $day    Y-m-d.
	 * @param int    $page   Page.
	 * @param string $device m, t or d.
	 * @param string $seg    a or b.
	 * @param array  $marks  [kind, x per cent, y pixels, n].
	 */
	private static function count_marks( string $day, int $page, string $device, string $seg, array $marks ): void {
		if ( ! $marks ) {
			return;
		}

		global $wpdb;

		$t    = $wpdb->prefix . self::GRID;
		$rows = array();
		$args = array();

		foreach ( array_slice( $marks, 0, 120 ) as $m ) {
			if ( ! is_array( $m ) || count( $m ) < 3 ) {
				continue;
			}

			$kind = in_array( (string) $m[0], array( 'c', 'd', 'r' ), true ) ? (string) $m[0] : 'c';
			$xb   = max( 0, min( 100, (int) $m[1] ) );
			$xb   = (int) ( floor( $xb / self::XSTEP ) * self::XSTEP );
			$yb   = max( 0, min( 60000, (int) $m[2] ) );
			$yb   = (int) ( floor( $yb / self::YSTEP ) * self::YSTEP );
			$n    = max( 1, min( 50, (int) ( $m[3] ?? 1 ) ) );

			$rows[] = '(%s, %d, %s, %s, %s, %d, %d, %d)';
			array_push( $args, $day, $page, $device, $seg, $kind, $xb, $yb, $n );
		}

		if ( ! $rows ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders -- every value is prepared above; the placeholders sit inside the row list.
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$t} (day, page_id, device, seg, kind, xb, yb, n) VALUES " . implode( ',', $rows ) . ' ON DUPLICATE KEY UPDATE n = n + VALUES(n)', $args ) );
	}

	/**
	 * How far down the page the visit got, and how long it stayed there.
	 *
	 * @param string $day    Y-m-d.
	 * @param int    $page   Page.
	 * @param string $device m, t or d.
	 * @param string $seg    a or b.
	 * @param array  $depth  Seconds per band, twenty bands of five per cent.
	 */
	private static function count_depth( string $day, int $page, string $device, string $seg, array $depth ): void {
		if ( ! $depth ) {
			return;
		}

		global $wpdb;

		$t    = $wpdb->prefix . self::DEPTH;
		$rows = array();
		$args = array();

		foreach ( array_slice( $depth, 0, 20 ) as $band => $secs ) {
			$secs = max( 0, min( 600, (int) $secs ) );

			$rows[] = '(%s, %d, %s, %s, %d, 1, %d)';
			array_push( $args, $day, $page, $device, $seg, (int) $band, $secs );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders -- every value is prepared above; the placeholders sit inside the row list.
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$t} (day, page_id, device, seg, band, reached, secs) VALUES " . implode( ',', $rows ) . ' ON DUPLICATE KEY UPDATE reached = reached + 1, secs = secs + VALUES(secs)', $args ) );
	}

	/* ------------------------------------------------------------ tables */

	/**
	 * Four small tables, made once.
	 */
	public static function install(): void {
		if ( '1' === (string) get_option( self::SCHEMA, '' ) ) {
			return;
		}

		global $wpdb;

		$collate = $wpdb->get_charset_collate();
		$pages   = $wpdb->prefix . self::PAGES;
		$views   = $wpdb->prefix . self::VIEWS;
		$grid    = $wpdb->prefix . self::GRID;
		$depth   = $wpdb->prefix . self::DEPTH;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta(
			"CREATE TABLE {$pages} (
				id smallint(5) unsigned NOT NULL AUTO_INCREMENT,
				path varchar(190) NOT NULL,
				kind varchar(12) NOT NULL DEFAULT '',
				label varchar(190) NOT NULL DEFAULT '',
				seen date NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY path (path)
			) {$collate};"
		);

		dbDelta(
			"CREATE TABLE {$views} (
				day date NOT NULL,
				page_id smallint(5) unsigned NOT NULL,
				device char(1) NOT NULL DEFAULT 'd',
				seg char(1) NOT NULL DEFAULT 'a',
				views int(10) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY (day, page_id, device, seg)
			) {$collate};"
		);

		dbDelta(
			"CREATE TABLE {$grid} (
				day date NOT NULL,
				page_id smallint(5) unsigned NOT NULL,
				device char(1) NOT NULL DEFAULT 'd',
				seg char(1) NOT NULL DEFAULT 'a',
				kind char(1) NOT NULL DEFAULT 'c',
				xb tinyint(3) unsigned NOT NULL DEFAULT 0,
				yb smallint(5) unsigned NOT NULL DEFAULT 0,
				n int(10) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY (day, page_id, device, seg, kind, xb, yb)
			) {$collate};"
		);

		dbDelta(
			"CREATE TABLE {$depth} (
				day date NOT NULL,
				page_id smallint(5) unsigned NOT NULL,
				device char(1) NOT NULL DEFAULT 'd',
				seg char(1) NOT NULL DEFAULT 'a',
				band tinyint(3) unsigned NOT NULL DEFAULT 0,
				reached int(10) unsigned NOT NULL DEFAULT 0,
				secs int(10) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY (day, page_id, device, seg, band)
			) {$collate};"
		);

		update_option( self::SCHEMA, '1', false );
	}

	/**
	 * Old marks go; the recent ones are folded week by week so ninety days
	 * of history costs about a month of rows.
	 */
	public static function sweep(): void {
		if ( '1' !== (string) get_option( self::SCHEMA, '' ) ) {
			return;
		}

		global $wpdb;

		$gone  = Query::shift( Query::today(), -self::KEEP );
		$fold  = Query::shift( Query::today(), -self::DAILY );
		$grid  = $wpdb->prefix . self::GRID;
		$depth = $wpdb->prefix . self::DEPTH;
		$views = $wpdb->prefix . self::VIEWS;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own tables.
		foreach ( array( $grid, $depth, $views ) as $t ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$t} WHERE day < %s", $gone ) );
		}

		// A week's rows become one row dated to that week's Monday. Done in
		// one statement per table, on the tail only, once a day.
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$grid} (day, page_id, device, seg, kind, xb, yb, n) SELECT DATE_SUB(day, INTERVAL WEEKDAY(day) DAY), page_id, device, seg, kind, xb, yb, SUM(n) FROM {$grid} WHERE day < %s AND DAYOFWEEK(day) <> 2 GROUP BY DATE_SUB(day, INTERVAL WEEKDAY(day) DAY), page_id, device, seg, kind, xb, yb ON DUPLICATE KEY UPDATE n = n + VALUES(n)", $fold ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$grid} WHERE day < %s AND DAYOFWEEK(day) <> 2", $fold ) );

		$wpdb->query( $wpdb->prepare( "INSERT INTO {$depth} (day, page_id, device, seg, band, reached, secs) SELECT DATE_SUB(day, INTERVAL WEEKDAY(day) DAY), page_id, device, seg, band, SUM(reached), SUM(secs) FROM {$depth} WHERE day < %s AND DAYOFWEEK(day) <> 2 GROUP BY DATE_SUB(day, INTERVAL WEEKDAY(day) DAY), page_id, device, seg, band ON DUPLICATE KEY UPDATE reached = reached + VALUES(reached), secs = secs + VALUES(secs)", $fold ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$depth} WHERE day < %s AND DAYOFWEEK(day) <> 2", $fold ) );
		// phpcs:enable
	}

	/* ----------------------------------------------------------- reading */

	/**
	 * The busiest watched pages in a range.
	 *
	 * @param string $from  Y-m-d.
	 * @param string $to    Y-m-d.
	 * @param int    $limit How many.
	 * @return array<int,array<string,mixed>>
	 */
	public static function top_pages( string $from, string $to, int $limit = 40 ): array {
		global $wpdb;

		$v = $wpdb->prefix . self::VIEWS;
		$p = $wpdb->prefix . self::PAGES;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL -- own tables; every value is a placeholder.
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT v.page_id, p.path, p.label, p.kind,
					SUM( v.views ) AS views,
					SUM( CASE WHEN v.device = 'm' THEN v.views ELSE 0 END ) AS m,
					SUM( CASE WHEN v.device = 't' THEN v.views ELSE 0 END ) AS t,
					SUM( CASE WHEN v.device = 'd' THEN v.views ELSE 0 END ) AS d
				FROM {$v} v INNER JOIN {$p} p ON p.id = v.page_id
				WHERE v.day BETWEEN %s AND %s AND v.seg = 'a'
				GROUP BY v.page_id
				ORDER BY views DESC
				LIMIT %d",
				$from,
				$to,
				max( 1, $limit )
			),
			ARRAY_A
		);
		// phpcs:enable

		return array_map(
			static function ( array $r ): array {
				return array(
					'id'    => (int) $r['page_id'],
					'path'  => (string) $r['path'],
					'label' => (string) $r['label'],
					'kind'  => (string) $r['kind'],
					'views' => (int) $r['views'],
					'm'     => (int) $r['m'],
					't'     => (int) $r['t'],
					'd'     => (int) $r['d'],
				);
			},
			$rows
		);
	}

	/**
	 * One page's map: the grid, the bands, and the totals the screen shows.
	 *
	 * @param int    $page   Page.
	 * @param string $device m, t or d.
	 * @param string $from   Y-m-d.
	 * @param string $to     Y-m-d.
	 * @param string $seg    a for everyone, b for visits that bought.
	 * @return array<string,mixed>
	 */
	public static function map( int $page, string $device, string $from, string $to, string $seg = 'a' ): array {
		global $wpdb;

		$g   = $wpdb->prefix . self::GRID;
		$d   = $wpdb->prefix . self::DEPTH;
		$v   = $wpdb->prefix . self::VIEWS;
		$seg = 'b' === $seg ? 'b' : 'a';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own tables.
		$marks = (array) $wpdb->get_results( $wpdb->prepare( "SELECT kind, xb, yb, SUM(n) AS n FROM {$g} WHERE page_id = %d AND device = %s AND seg = %s AND day BETWEEN %s AND %s GROUP BY kind, xb, yb ORDER BY n DESC LIMIT 4000", $page, $device, $seg, $from, $to ), ARRAY_A );
		$bands = (array) $wpdb->get_results( $wpdb->prepare( "SELECT band, SUM(reached) AS reached, SUM(secs) AS secs FROM {$d} WHERE page_id = %d AND device = %s AND seg = %s AND day BETWEEN %s AND %s GROUP BY band ORDER BY band", $page, $device, $seg, $from, $to ), ARRAY_A );
		$views = (int) $wpdb->get_var( $wpdb->prepare( "SELECT SUM(views) FROM {$v} WHERE page_id = %d AND device = %s AND seg = %s AND day BETWEEN %s AND %s", $page, $device, $seg, $from, $to ) );
		// phpcs:enable

		$clicks = 0;

		foreach ( $marks as $m ) {
			if ( 'c' === $m['kind'] ) {
				$clicks += (int) $m['n'];
			}
		}

		return array(
			'views'  => $views,
			'clicks' => $clicks,
			'marks'  => array_map(
				static function ( array $m ): array {
					return array( (string) $m['kind'], (int) $m['xb'], (int) $m['yb'], (int) $m['n'] );
				},
				$marks
			),
			'bands'  => array_map(
				static function ( array $b ): array {
					return array( (int) $b['band'], (int) $b['reached'], (int) $b['secs'] );
				},
				$bands
			),
		);
	}
}
