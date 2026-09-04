<?php
/**
 * Product feeds: the store of feeds, and when each one is rebuilt.
 *
 * The lessons behind the design come from feeds that went wrong on live
 * shops rather than from a specification:
 *
 *   - A catalogue kept showing products the shop had deleted, because old
 *     feed files were still sitting at addresses the network remembered.
 *     A feed here has exactly one address, and it always answers with the
 *     file that was written last.
 *   - Generation used to be one long request that a parallel cron run cut
 *     off half way, leaving a feed that was never finished and never
 *     retried. Building here happens in small batches that resume.
 *   - The schedule itself went missing and nothing put it back. It is
 *     re-registered on every load.
 *
 * @package OC_Theme
 */

declare( strict_types = 1 );

namespace OC\Theme\Feeds;

defined( 'ABSPATH' ) || exit;

/**
 * Everything about which feeds exist and when they run.
 */
final class Feeds {

	/**
	 * Where the feeds live.
	 */
	public const OPTION = 'oc_feeds';

	/**
	 * The cron event that builds whatever is due.
	 */
	private const EVENT = 'oc_feeds_tick';

	/**
	 * The event that carries one run forward by a batch.
	 */
	private const STEP = 'oc_feeds_step';

	/**
	 * How many products one batch reads.
	 */
	public const BATCH = 200;

	/**
	 * A run that has not moved for this long is taken to be dead.
	 */
	private const STALL = 900;

	/**
	 * Hook in.
	 */
	public function register(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		add_action( self::EVENT, array( $this, 'tick' ) );
		add_action( self::STEP, array( self::class, 'run_step' ) );
		add_action( 'init', array( $this, 'keep_scheduled' ) );
		add_action( 'init', array( $this, 'serve' ), 5 );
	}

	/**
	 * Put the schedule back if anything lost it.
	 *
	 * A feed that silently stops being rebuilt is worse than no feed: the
	 * shop keeps advertising yesterday's prices and last month's stock.
	 */
	public function keep_scheduled(): void {
		if ( ! wp_next_scheduled( self::EVENT ) ) {
			wp_schedule_event( time() + 60, 'hourly', self::EVENT );
		}
	}

	/**
	 * Carry on with a run that is already going.
	 *
	 * Each batch books the next one ten seconds out rather than waiting for
	 * the hourly tick, so a catalogue of thirty thousand is finished in
	 * minutes — and every batch is still its own short request that nothing
	 * can cut off half way.
	 *
	 * @param string $key Feed key.
	 */
	public static function run_step( string $key ): void {
		Build::step( $key );

		$feed = self::get( $key );

		if ( null !== $feed && 'running' === $feed['state'] ) {
			self::book( $key );
		}
	}

	/**
	 * Book the next batch.
	 *
	 * @param string $key Feed key.
	 */
	public static function book( string $key ): void {
		if ( ! wp_next_scheduled( self::STEP, array( $key ) ) ) {
			wp_schedule_single_event( time() + 10, self::STEP, array( $key ) );
		}
	}

	/**
	 * Every feed, cleaned.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function all(): array {
		$raw = get_option( self::OPTION, array() );
		$out = array();

		foreach ( (array) ( is_array( $raw ) ? $raw : array() ) as $key => $feed ) {
			$key = sanitize_key( (string) $key );

			if ( '' === $key || ! is_array( $feed ) ) {
				continue;
			}

			$out[ $key ] = self::clean( $feed );
		}

		return $out;
	}

	/**
	 * One feed, or null.
	 *
	 * @param string $key Feed key.
	 * @return array<string,mixed>|null
	 */
	public static function get( string $key ): ?array {
		$all = self::all();

		return $all[ sanitize_key( $key ) ] ?? null;
	}

	/**
	 * The settings a feed carries, with the defaults that suit a shop that
	 * has not thought about any of this yet.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		return array(
			'name'      => '',
			'target'    => 'meta',
			'format'    => 'xml',
			'every'     => 'hourly',
			'in_stock'  => 0,
			'variants'  => 1,
			'cats'      => array(),
			'exclude'   => array(),
			'brand'     => '',
			'condition' => 'new',
			'ship'      => 0,
			'utm'       => 1,
			'desc'      => 'short',
			'gcat'      => '',
			'delivery'  => '',
			'made'      => 0,
			'items'     => 0,
			'ms'        => 0,
			'state'     => 'new',
			'cursor'    => 0,
			'started'   => 0,
			'beat'      => 0,
			'error'     => '',
		);
	}

	/**
	 * One feed's settings, cleaned to what they are allowed to be.
	 *
	 * @param array<string,mixed> $raw Feed.
	 * @return array<string,mixed>
	 */
	public static function clean( array $raw ): array {
		$out = self::defaults();

		$out['name']      = sanitize_text_field( (string) ( $raw['name'] ?? '' ) );
		$out['target']    = in_array( (string) ( $raw['target'] ?? '' ), array( 'meta', 'google', 'zap' ), true ) ? (string) $raw['target'] : 'meta';
		$out['format']    = in_array( (string) ( $raw['format'] ?? '' ), array( 'xml', 'csv' ), true ) ? (string) $raw['format'] : 'xml';
		$out['every']     = in_array( (string) ( $raw['every'] ?? '' ), array( 'hourly', 'four', 'daily' ), true ) ? (string) $raw['every'] : 'hourly';
		$out['in_stock']  = empty( $raw['in_stock'] ) ? 0 : 1;
		$out['variants']  = empty( $raw['variants'] ) ? 0 : 1;
		$out['ship']      = empty( $raw['ship'] ) ? 0 : 1;
		$out['utm']       = empty( $raw['utm'] ) ? 0 : 1;
		$out['made']      = absint( $raw['made'] ?? 0 );
		$out['items']     = absint( $raw['items'] ?? 0 );
		$out['ms']        = absint( $raw['ms'] ?? 0 );
		$out['cursor']    = absint( $raw['cursor'] ?? 0 );
		$out['started']   = absint( $raw['started'] ?? 0 );
		$out['beat']      = absint( $raw['beat'] ?? 0 );
		$out['brand']     = sanitize_text_field( (string) ( $raw['brand'] ?? '' ) );
		$out['gcat']      = sanitize_text_field( (string) ( $raw['gcat'] ?? '' ) );
		$out['delivery']  = sanitize_text_field( (string) ( $raw['delivery'] ?? '' ) );
		$out['error']     = sanitize_text_field( (string) ( $raw['error'] ?? '' ) );
		$out['condition'] = in_array( (string) ( $raw['condition'] ?? '' ), array( 'new', 'refurbished', 'used' ), true ) ? (string) $raw['condition'] : 'new';
		$out['desc']      = in_array( (string) ( $raw['desc'] ?? '' ), array( 'short', 'long' ), true ) ? (string) $raw['desc'] : 'short';
		$out['state']     = in_array( (string) ( $raw['state'] ?? '' ), array( 'new', 'running', 'ready', 'failed' ), true ) ? (string) $raw['state'] : 'new';

		foreach ( array( 'cats', 'exclude' ) as $list ) {
			$ids = array();

			foreach ( (array) ( $raw[ $list ] ?? array() ) as $one ) {
				$id = absint( $one );

				if ( $id > 0 ) {
					$ids[] = $id;
				}
			}

			$out[ $list ] = array_values( array_unique( $ids ) );
		}

		return $out;
	}

	/**
	 * Write one feed's settings back.
	 *
	 * @param string              $key  Feed key.
	 * @param array<string,mixed> $feed Settings.
	 */
	public static function put( string $key, array $feed ): void {
		$all         = self::all();
		$all[ $key ] = self::clean( $feed );

		update_option( self::OPTION, $all, false );
	}

	/**
	 * Remove a feed and the file it wrote.
	 *
	 * @param string $key Feed key.
	 */
	public static function drop( string $key ): void {
		$all = self::all();
		$key = sanitize_key( $key );

		if ( ! isset( $all[ $key ] ) ) {
			return;
		}

		$file = self::path( $key, (string) $all[ $key ]['format'] );

		if ( file_exists( $file ) ) {
			wp_delete_file( $file );
		}

		unset( $all[ $key ] );
		update_option( self::OPTION, $all, false );
	}

	/**
	 * A new feed's key: short, and never one that is already taken.
	 */
	public static function fresh_key(): string {
		$all = self::all();

		do {
			$key = 'f' . strtolower( wp_generate_password( 8, false, false ) );
		} while ( isset( $all[ $key ] ) );

		return $key;
	}

	/**
	 * Where a feed's file lives.
	 *
	 * @param string $key    Feed key.
	 * @param string $format 'xml' or 'csv'.
	 */
	public static function path( string $key, string $format = 'xml' ): string {
		$dir = wp_get_upload_dir()['basedir'] . '/oc-feeds';

		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		return $dir . '/' . sanitize_key( $key ) . '.' . ( 'csv' === $format ? 'csv' : 'xml' );
	}

	/**
	 * The one address a feed is ever known by.
	 *
	 * It goes through PHP rather than straight to the file on purpose: a
	 * feed served as a plain file inherits whatever caching the web server
	 * has been told to apply, and a network that is handed a month-old
	 * copy will advertise a month-old shop.
	 *
	 * @param string $key Feed key.
	 */
	public static function url( string $key ): string {
		return add_query_arg( 'oc_feed', sanitize_key( $key ), home_url( '/' ) );
	}

	/**
	 * Hand the file over when someone asks for a feed.
	 */
	public function serve(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a public address, like any feed.
		$key = isset( $_GET['oc_feed'] ) ? sanitize_key( wp_unslash( $_GET['oc_feed'] ) ) : '';

		if ( '' === $key ) {
			return;
		}

		$feed = self::get( $key );

		if ( null === $feed ) {
			status_header( 404 );
			exit;
		}

		$file = self::path( $key, (string) $feed['format'] );

		if ( ! file_exists( $file ) ) {
			status_header( 404 );
			exit;
		}

		nocache_headers();
		header( 'Content-Type: ' . ( 'csv' === $feed['format'] ? 'text/csv' : 'application/xml' ) . '; charset=utf-8' );
		header( 'Content-Length: ' . filesize( $file ) );
		header( 'X-Robots-Tag: noindex' );
		readfile( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- streaming a file this plugin wrote.
		exit;
	}

	/**
	 * How many seconds between rebuilds.
	 *
	 * @param string $every Setting.
	 */
	public static function seconds( string $every ): int {
		if ( 'daily' === $every ) {
			return DAY_IN_SECONDS;
		}

		return 'four' === $every ? 4 * HOUR_IN_SECONDS : HOUR_IN_SECONDS;
	}

	/**
	 * The five-minute tick: carry on with a run in progress, or start the
	 * one that is most overdue. One feed at a time, so a shop with six
	 * feeds never has six builds racing each other.
	 */
	public function tick(): void {
		$all = self::all();
		$now = time();

		foreach ( $all as $key => $feed ) {
			if ( 'running' !== $feed['state'] ) {
				continue;
			}

			// Still going: make sure the next batch is booked, in case the
			// chain was broken by a deploy or a crash mid-run.
			if ( $now - (int) $feed['beat'] < self::STALL ) {
				self::book( $key );

				return;
			}

			// It stopped breathing. Begin again rather than leave a feed
			// that is never finished and never retried.
			Build::start( $key );
			self::book( $key );

			return;
		}

		$due  = '';
		$late = 0;

		foreach ( $all as $key => $feed ) {
			$age = $now - (int) $feed['made'];

			if ( $age < self::seconds( (string) $feed['every'] ) ) {
				continue;
			}

			if ( $age > $late ) {
				$late = $age;
				$due  = $key;
			}
		}

		if ( '' !== $due ) {
			Build::start( $due );
			self::book( $due );
		}
	}
}
