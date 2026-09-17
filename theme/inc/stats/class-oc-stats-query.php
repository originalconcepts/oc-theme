<?php
/**
 * Statistics: the numbers for a period.
 *
 * Two sources, one shape. Visits, views, adds to cart and checkouts come
 * from the events table; orders, money, customers and products come from
 * WooCommerce itself. Whole days are read from the daily summary the
 * rollup keeps, today is computed live and cached for five minutes.
 *
 * @package OC_Theme
 */

declare( strict_types = 1 );

namespace OC\Theme\Stats;

defined( 'ABSPATH' ) || exit;

/**
 * Compute and read.
 */
final class Query {

	/**
	 * Statuses that count as a sale (George's call: paid and in hand).
	 */
	const SALE_STATUSES = array( 'processing', 'completed' );

	/**
	 * Every status the breakdown looks at.
	 */
	const ALL_STATUSES = array( 'processing', 'completed', 'on-hold', 'pending', 'cancelled', 'failed', 'refunded' );

	/**
	 * Scalar metrics; everything else is keyed.
	 */
	const SCALARS = array(
		'sessions',
		'views',
		'product_sessions',
		'atc_sessions',
		'checkout_sessions',
		'purchase_sessions',
		'orders',
		'gross',
		'items',
		'shipping',
		'tax',
		'refunds',
		'cancelled_n',
		'cancelled_sum',
		'failed_n',
		'failed_sum',
		'pending_n',
		'pending_sum',
		'onhold_n',
		'onhold_sum',
		'new_customers',
		'returning_customers',
	);

	/**
	 * Keyed metrics.
	 */
	const KEYED = array(
		'sessions_dev',
		'sessions_ch',
		'product_views',
		'product_atc',
		'cat_views',
		'orders_ch',
		'gross_ch',
		'orders_dev',
		'gross_dev',
		'product_orders',
		'product_gross',
		'product_qty',
		'cat_gross',
		'hours',
	);

	/**
	 * An empty metrics set.
	 *
	 * @return array<string,mixed>
	 */
	public static function blank(): array {
		$m = array();

		foreach ( self::SCALARS as $k ) {
			$m[ $k ] = 0;
		}

		foreach ( self::KEYED as $k ) {
			$m[ $k ] = array();
		}

		return $m;
	}

	/**
	 * Adds one metrics set into another.
	 *
	 * @param array<string,mixed> $into Accumulator.
	 * @param array<string,mixed> $add  Addend.
	 * @return array<string,mixed>
	 */
	public static function merge( array $into, array $add ): array {
		foreach ( self::SCALARS as $k ) {
			$into[ $k ] = ( $into[ $k ] ?? 0 ) + ( $add[ $k ] ?? 0 );
		}

		foreach ( self::KEYED as $k ) {
			foreach ( (array) ( $add[ $k ] ?? array() ) as $key => $v ) {
				$into[ $k ][ $key ] = ( $into[ $k ][ $key ] ?? 0 ) + $v;
			}
		}

		return $into;
	}

	/**
	 * Percent change, rounded; null when there is nothing to compare to.
	 *
	 * @param float $now    Current.
	 * @param float $before Previous.
	 */
	public static function change( float $now, float $before ): ?float {
		if ( $before <= 0 ) {
			return null;
		}

		return round( ( $now - $before ) / $before * 100, 1 );
	}

	/**
	 * Today's date in the shop's time zone.
	 */
	public static function today(): string {
		return (string) wp_date( 'Y-m-d' );
	}

	/**
	 * A day shifted by N days, in the shop's time zone.
	 *
	 * @param string $day  Y-m-d.
	 * @param int    $days Positive or negative.
	 */
	public static function shift( string $day, int $days ): string {
		$dt = new \DateTimeImmutable( $day . ' 12:00:00', wp_timezone() );

		return $dt->modify( ( $days >= 0 ? '+' : '' ) . $days . ' days' )->format( 'Y-m-d' );
	}

	/**
	 * Every day from one to another, inclusive.
	 *
	 * @param string $from Y-m-d.
	 * @param string $to   Y-m-d.
	 * @return array<int,string>
	 */
	public static function days( string $from, string $to ): array {
		$out = array();
		$d   = $from;
		$n   = 0;

		while ( $d <= $to && $n < 800 ) {
			$out[] = $d;
			$d     = self::shift( $d, 1 );
			++$n;
		}

		return $out;
	}

	/* ------------------------------------------------------------ compute */

	/**
	 * The full metrics set for a window, live from both sources.
	 *
	 * @param string $from Shop-time 'Y-m-d H:i:s', inclusive.
	 * @param string $to   Shop-time 'Y-m-d H:i:s', exclusive.
	 * @return array<string,mixed>
	 */
	public static function compute( string $from, string $to ): array {
		return self::merge( self::compute_events( $from, $to ), self::compute_orders( $from, $to ) );
	}

	/**
	 * Visits and the funnel, from the events table.
	 *
	 * @param string $from Shop-time from, inclusive.
	 * @param string $to   Shop-time to, exclusive.
	 * @return array<string,mixed>
	 */
	private static function compute_events( string $from, string $to ): array {
		global $wpdb;

		$m = self::blank();

		if ( '2' !== (string) get_option( 'oc_stats_tables', '' ) ) {
			return $m;
		}

		$t = Track::events_table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- our own table; the window is prepared.
		$m['sessions'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT sid) FROM {$t} WHERE t >= %s AND t < %s AND type <> 'purchase'", $from, $to ) );
		$m['views']    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE t >= %s AND t < %s AND type IN ('view','product','cat')", $from, $to ) );

		$per_type = array(
			'product'  => 'product_sessions',
			'atc'      => 'atc_sessions',
			'checkout' => 'checkout_sessions',
			'purchase' => 'purchase_sessions',
		);

		foreach ( $per_type as $type => $key ) {
			$m[ $key ] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT sid) FROM {$t} WHERE t >= %s AND t < %s AND type = %s AND sid <> ''", $from, $to, $type ) );
		}

		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT device, COUNT(DISTINCT sid) n FROM {$t} WHERE t >= %s AND t < %s AND type <> 'purchase' GROUP BY device", $from, $to ) ) as $r ) {
			$m['sessions_dev'][ (string) $r->device ] = (int) $r->n;
		}

		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT channel, COUNT(DISTINCT sid) n FROM {$t} WHERE t >= %s AND t < %s AND type <> 'purchase' GROUP BY channel", $from, $to ) ) as $r ) {
			$m['sessions_ch'][ (string) $r->channel ] = (int) $r->n;
		}

		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT obj, COUNT(*) n FROM {$t} WHERE t >= %s AND t < %s AND type = 'product' AND obj > 0 GROUP BY obj", $from, $to ) ) as $r ) {
			$m['product_views'][ (int) $r->obj ] = (int) $r->n;
		}

		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT obj, COUNT(*) n FROM {$t} WHERE t >= %s AND t < %s AND type = 'atc' AND obj > 0 GROUP BY obj", $from, $to ) ) as $r ) {
			$m['product_atc'][ (int) $r->obj ] = (int) $r->n;
		}

		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT obj, COUNT(*) n FROM {$t} WHERE t >= %s AND t < %s AND type = 'cat' AND obj > 0 GROUP BY obj", $from, $to ) ) as $r ) {
			$m['cat_views'][ (int) $r->obj ] = (int) $r->n;
		}
		// phpcs:enable

		return $m;
	}

	/**
	 * Orders, money, customers and products, from WooCommerce.
	 *
	 * @param string $from Shop-time from, inclusive.
	 * @param string $to   Shop-time to, exclusive.
	 * @return array<string,mixed>
	 */
	private static function compute_orders( string $from, string $to ): array {
		$m = self::blank();

		if ( ! function_exists( 'wc_get_orders' ) ) {
			return $m;
		}

		$tz      = wp_timezone();
		$from_ts = ( new \DateTimeImmutable( $from, $tz ) )->getTimestamp();
		$to_ts   = ( new \DateTimeImmutable( $to, $tz ) )->getTimestamp() - 1;

		$orders = wc_get_orders(
			array(
				'limit'        => -1,
				'status'       => self::ALL_STATUSES,
				'date_created' => $from_ts . '...' . $to_ts,
				'type'         => 'shop_order',
			)
		);

		foreach ( (array) $orders as $order ) {
			if ( ! $order instanceof \WC_Order ) {
				continue;
			}

			$status = $order->get_status();
			$total  = (float) $order->get_total();

			switch ( $status ) {
				case 'cancelled':
					++$m['cancelled_n'];
					$m['cancelled_sum'] += $total;
					continue 2;
				case 'failed':
					++$m['failed_n'];
					$m['failed_sum'] += $total;
					continue 2;
				case 'pending':
					++$m['pending_n'];
					$m['pending_sum'] += $total;
					continue 2;
				case 'on-hold':
					++$m['onhold_n'];
					$m['onhold_sum'] += $total;
					continue 2;
				case 'refunded':
					$m['refunds'] += (float) $order->get_total_refunded();
					continue 2;
			}

			if ( ! in_array( $status, self::SALE_STATUSES, true ) ) {
				continue;
			}

			++$m['orders'];
			$m['gross']    += $total;
			$m['shipping'] += (float) $order->get_shipping_total();
			$m['tax']      += (float) $order->get_total_tax();
			$m['refunds']  += (float) $order->get_total_refunded();

			$ch  = (string) $order->get_meta( Track::META_CH );
			$ch  = in_array( $ch, Track::CHANNELS, true ) ? $ch : 'direct';
			$dev = (string) $order->get_meta( Track::META_DEV );
			$dev = in_array( $dev, array( 'm', 't', 'd' ), true ) ? $dev : 'd';

			$m['orders_ch'][ $ch ]   = ( $m['orders_ch'][ $ch ] ?? 0 ) + 1;
			$m['gross_ch'][ $ch ]    = ( $m['gross_ch'][ $ch ] ?? 0 ) + $total;
			$m['orders_dev'][ $dev ] = ( $m['orders_dev'][ $dev ] ?? 0 ) + 1;
			$m['gross_dev'][ $dev ]  = ( $m['gross_dev'][ $dev ] ?? 0 ) + $total;

			$created = $order->get_date_created();

			if ( $created ) {
				$local = $created->setTimezone( $tz );
				$slot  = (int) $local->format( 'w' ) * 24 + (int) $local->format( 'G' );

				$m['hours'][ $slot ] = ( $m['hours'][ $slot ] ?? 0 ) + 1;
			}

			if ( self::is_returning( $order ) ) {
				++$m['returning_customers'];
			} else {
				++$m['new_customers'];
			}

			foreach ( $order->get_items( 'line_item' ) as $item ) {
				if ( ! $item instanceof \WC_Order_Item_Product ) {
					continue;
				}

				$pid = (int) $item->get_product_id();
				$qty = (int) $item->get_quantity();
				$sum = (float) $item->get_total() + (float) $item->get_total_tax();

				$m['items'] += $qty;

				$m['product_orders'][ $pid ] = ( $m['product_orders'][ $pid ] ?? 0 ) + 1;
				$m['product_qty'][ $pid ]    = ( $m['product_qty'][ $pid ] ?? 0 ) + $qty;
				$m['product_gross'][ $pid ]  = ( $m['product_gross'][ $pid ] ?? 0 ) + $sum;

				foreach ( (array) wp_get_post_terms( $pid, 'product_cat', array( 'fields' => 'ids' ) ) as $tid ) {
					$m['cat_gross'][ (int) $tid ] = ( $m['cat_gross'][ (int) $tid ] ?? 0 ) + $sum;
				}
			}
		}

		return $m;
	}

	/**
	 * Whether the person behind an order had bought before it.
	 *
	 * @param \WC_Order $order The order.
	 */
	private static function is_returning( \WC_Order $order ): bool {
		static $seen = array();

		$who = $order->get_customer_id() > 0 ? 'u' . $order->get_customer_id() : 'e' . strtolower( (string) $order->get_billing_email() );

		if ( 'e' === $who ) {
			return false;
		}

		$created = $order->get_date_created();
		$before  = $created ? $created->getTimestamp() : time();

		if ( isset( $seen[ $who ] ) && $seen[ $who ] < $before ) {
			return true;
		}

		$args = array(
			'limit'        => 1,
			'status'       => self::SALE_STATUSES,
			'date_created' => '<' . $before,
			'return'       => 'ids',
			'exclude'      => array( $order->get_id() ),
			'type'         => 'shop_order',
		);

		if ( $order->get_customer_id() > 0 ) {
			$args['customer_id'] = $order->get_customer_id();
		} else {
			$args['billing_email'] = $order->get_billing_email();
		}

		$earlier = wc_get_orders( $args );
		$is      = ! empty( $earlier );

		if ( ! isset( $seen[ $who ] ) || $before < $seen[ $who ] ) {
			$seen[ $who ] = $before;
		}

		return $is;
	}

	/* ------------------------------------------------------------ daily */

	/**
	 * Writes a day's metrics into the summary table.
	 *
	 * @param string              $day Y-m-d.
	 * @param array<string,mixed> $m   Metrics.
	 */
	public static function store_day( string $day, array $m ): void {
		global $wpdb;

		$t = Track::daily_table();

		$wpdb->delete( $t, array( 'day' => $day ), array( '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table.

		$rows = array();

		foreach ( self::SCALARS as $k ) {
			$rows[] = $wpdb->prepare( '(%s,%s,%s,%f)', $day, $k, '', (float) ( $m[ $k ] ?? 0 ) );
		}

		foreach ( self::KEYED as $k ) {
			foreach ( (array) ( $m[ $k ] ?? array() ) as $key => $v ) {
				$rows[] = $wpdb->prepare( '(%s,%s,%s,%f)', $day, $k, (string) $key, (float) $v );
			}
		}

		foreach ( array_chunk( $rows, 400 ) as $chunk ) {
			$wpdb->query( "INSERT INTO {$t} (day, metric, k, v) VALUES " . implode( ',', $chunk ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- every row was prepared above; the table is our own.
		}
	}

	/**
	 * Reads a day's metrics from the summary table; null when it is not there.
	 *
	 * @param string $day Y-m-d.
	 * @return array<string,mixed>|null
	 */
	public static function load_day( string $day ): ?array {
		global $wpdb;

		if ( '2' !== (string) get_option( 'oc_stats_tables', '' ) ) {
			return null;
		}

		$t    = Track::daily_table();
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT metric, k, v FROM {$t} WHERE day = %s", $day ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- our own table.

		if ( empty( $rows ) ) {
			return null;
		}

		$m = self::blank();

		foreach ( $rows as $r ) {
			$metric = (string) $r->metric;

			if ( in_array( $metric, self::SCALARS, true ) ) {
				$m[ $metric ] = (float) $r->v;
			} elseif ( in_array( $metric, self::KEYED, true ) ) {
				$m[ $metric ][ (string) $r->k ] = (float) $r->v;
			}
		}

		return $m;
	}

	/**
	 * A whole day's metrics: the summary when it exists, else computed and,
	 * for a day that is over, stored.
	 *
	 * @param string $day Y-m-d.
	 * @return array<string,mixed>
	 */
	public static function day( string $day ): array {
		$today = self::today();

		if ( $day > $today ) {
			return self::blank();
		}

		if ( $day === $today ) {
			$key = 'oc_stats_today_' . $day;
			$m   = get_transient( $key );

			if ( ! is_array( $m ) ) {
				$m = self::compute( $day . ' 00:00:00', self::shift( $day, 1 ) . ' 00:00:00' );
				set_transient( $key, $m, 5 * MINUTE_IN_SECONDS );
			}

			return $m;
		}

		$m = self::load_day( $day );

		if ( null !== $m ) {
			return $m;
		}

		$m = self::compute( $day . ' 00:00:00', self::shift( $day, 1 ) . ' 00:00:00' );
		self::store_day( $day, $m );

		return $m;
	}

	/**
	 * Metrics summed over whole days.
	 *
	 * @param string $from Y-m-d.
	 * @param string $to   Y-m-d.
	 * @return array<string,mixed>
	 */
	public static function span( string $from, string $to ): array {
		$m = self::blank();

		foreach ( self::days( $from, $to ) as $d ) {
			$m = self::merge( $m, self::day( $d ) );
		}

		return $m;
	}

	/**
	 * The dashboard's answer for a range: current, previous, and a series.
	 *
	 * @param string $range today | d7 | d30 | d90 | custom.
	 * @param string $from  Y-m-d for custom.
	 * @param string $to    Y-m-d for custom.
	 * @return array<string,mixed>
	 */
	public static function range( string $range, string $from = '', string $to = '' ): array {
		$today = self::today();

		if ( 'today' === $range ) {
			$now       = (string) wp_date( 'H:i:s' );
			$yesterday = self::shift( $today, -1 );
			$cur       = self::compute( $today . ' 00:00:00', self::shift( $today, 1 ) . ' 00:00:00' );
			$prev      = self::compute( $yesterday . ' 00:00:00', $yesterday . ' ' . $now );

			return array(
				'range'       => 'today',
				'from'        => $today,
				'to'          => $today,
				'cur'         => $cur,
				'prev'        => $prev,
				'series'      => self::hourly_series( $today ),
				'prev_series' => self::hourly_series( $yesterday ),
				'granularity' => 'hour',
			);
		}

		$len = array(
			'd7'  => 7,
			'd30' => 30,
			'd90' => 90,
		);

		if ( isset( $len[ $range ] ) ) {
			$to   = $today;
			$from = self::shift( $today, -( $len[ $range ] - 1 ) );
		} elseif ( 'yesterday' === $range ) {
			$to   = self::shift( $today, -1 );
			$from = $to;
		} else {
			$range = 'custom';
			$from  = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) ? $from : self::shift( $today, -29 );
			$to    = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to ) ? min( $to, $today ) : $today;

			if ( $from > $to ) {
				$tmp  = $from;
				$from = $to;
				$to   = $tmp;
			}
		}

		$days      = self::days( $from, $to );
		$n         = count( $days );
		$prev_to   = self::shift( $from, -1 );
		$prev_from = self::shift( $prev_to, -( $n - 1 ) );

		$series      = array();
		$prev_series = array();
		$cur         = self::blank();
		$prev        = self::blank();

		foreach ( $days as $d ) {
			$m        = self::day( $d );
			$cur      = self::merge( $cur, $m );
			$series[] = array( $d, round( (float) $m['gross'], 2 ), (int) $m['orders'], (int) $m['sessions'] );
		}

		foreach ( self::days( $prev_from, $prev_to ) as $d ) {
			$m             = self::day( $d );
			$prev          = self::merge( $prev, $m );
			$prev_series[] = array( $d, round( (float) $m['gross'], 2 ), (int) $m['orders'], (int) $m['sessions'] );
		}

		return array(
			'range'       => $range,
			'from'        => $from,
			'to'          => $to,
			'prev_from'   => $prev_from,
			'prev_to'     => $prev_to,
			'cur'         => $cur,
			'prev'        => $prev,
			'series'      => $series,
			'prev_series' => $prev_series,
			'granularity' => 'day',
		);
	}

	/**
	 * A day's sales by hour, for the "today" chart.
	 *
	 * @param string $day Y-m-d.
	 * @return array<int,array{0:string,1:float,2:int,3:int}>
	 */
	private static function hourly_series( string $day ): array {
		global $wpdb;

		$out    = array();
		$tz     = wp_timezone();
		$orders = function_exists( 'wc_get_orders' ) ? wc_get_orders(
			array(
				'limit'        => -1,
				'status'       => self::SALE_STATUSES,
				'date_created' => ( new \DateTimeImmutable( $day . ' 00:00:00', $tz ) )->getTimestamp() . '...' . ( ( new \DateTimeImmutable( $day . ' 23:59:59', $tz ) )->getTimestamp() ),
				'type'         => 'shop_order',
			)
		) : array();

		$by_hour = array_fill( 0, 24, array( 0.0, 0, 0 ) );

		foreach ( (array) $orders as $order ) {
			if ( ! $order instanceof \WC_Order || ! $order->get_date_created() ) {
				continue;
			}

			$h = (int) $order->get_date_created()->setTimezone( $tz )->format( 'G' );

			$by_hour[ $h ][0] += (float) $order->get_total();
			++$by_hour[ $h ][1];
		}

		if ( '2' === (string) get_option( 'oc_stats_tables', '' ) ) {
			$t = Track::events_table();

			foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT HOUR(t) h, COUNT(DISTINCT sid) n FROM {$t} WHERE day = %s AND type <> 'purchase' GROUP BY HOUR(t)", $day ) ) as $r ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- our own table.
				$by_hour[ (int) $r->h ][2] = (int) $r->n;
			}
		}

		$limit = $day === self::today() ? (int) wp_date( 'G' ) : 23;

		for ( $h = 0; $h <= $limit; $h++ ) {
			$out[] = array( sprintf( '%02d:00', $h ), round( $by_hour[ $h ][0], 2 ), $by_hour[ $h ][1], $by_hour[ $h ][2] );
		}

		return $out;
	}
}
