<?php
/**
 * Statistics: the nightly work.
 *
 * Every hour a single cron tick does what is due: sums yesterday and the
 * seven days before it into the daily table (orders change status, refunds
 * land late), fills older days from the orders alone, drops raw hits past
 * their retention, and hands the digest email its turn.
 *
 * @package OC_Theme
 */

declare( strict_types = 1 );

namespace OC\Theme\Stats;

defined( 'ABSPATH' ) || exit;

/**
 * The tick.
 */
final class Rollup {

	/**
	 * Cron hook.
	 */
	const CRON = 'oc_stats_tick';

	/**
	 * How far back the orders-only backfill goes.
	 */
	const BACKFILL_DAYS = 365;

	/**
	 * Hook in.
	 */
	public function register(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		add_action( 'init', array( $this, 'schedule' ) );
		add_action( self::CRON, array( __CLASS__, 'tick' ) );
	}

	/**
	 * Booked once, hourly.
	 */
	public function schedule(): void {
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'hourly', self::CRON );
		}
	}

	/**
	 * The hourly work.
	 */
	public static function tick(): void {
		Track::install();

		$today = Query::today();

		// Yesterday and the week before it, once a day: statuses move.
		if ( (string) get_option( 'oc_stats_rolled_on', '' ) !== $today ) {
			$yesterday = Query::shift( $today, -1 );

			for ( $i = 7; $i >= 0; $i-- ) {
				$day = Query::shift( $yesterday, -$i );
				Query::store_day( $day, Query::compute( $day . ' 00:00:00', Query::shift( $day, 1 ) . ' 00:00:00' ) );
			}

			update_option( 'oc_stats_rolled_on', $today, false );
			self::sweep();
			delete_transient( 'oc_stats_insights' );
		}

		self::backfill();

		Mail::maybe_send();
	}

	/**
	 * Older days, from the orders alone, a few per tick until a year is in.
	 */
	private static function backfill(): void {
		$cursor = (string) get_option( 'oc_stats_backfill', '' );
		$stop   = Query::shift( Query::today(), -self::BACKFILL_DAYS );

		if ( 'done' === $cursor ) {
			return;
		}

		if ( '' === $cursor ) {
			$cursor = Query::shift( Query::today(), -9 );
		}

		$n = 0;

		while ( $cursor >= $stop && $n < 30 ) {
			if ( null === Query::load_day( $cursor ) ) {
				Query::store_day( $cursor, Query::compute( $cursor . ' 00:00:00', Query::shift( $cursor, 1 ) . ' 00:00:00' ) );
			}

			$cursor = Query::shift( $cursor, -1 );
			++$n;
		}

		update_option( 'oc_stats_backfill', $cursor < $stop ? 'done' : $cursor, false );
	}

	/**
	 * Raw hits past their retention go; the daily totals stay.
	 */
	public static function sweep(): void {
		global $wpdb;

		if ( '2' !== (string) get_option( 'oc_stats_tables', '' ) ) {
			return;
		}

		$t     = Track::events_table();
		$floor = Query::shift( Query::today(), -Track::RETENTION_DAYS );

		$wpdb->query( $wpdb->prepare( "DELETE FROM {$t} WHERE day < %s", $floor ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- our own table.
	}
}
