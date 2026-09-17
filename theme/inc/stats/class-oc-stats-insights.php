<?php
/**
 * Statistics: what to do now.
 *
 * Eight plain rules over the numbers. Each one either clears its bar and
 * says a sentence with figures, an estimated worth and a button to the
 * place where it is handled, or stays silent. No model guesses; every
 * number can be explained, and "how this was worked out" travels with it.
 *
 * @package OC_Theme
 */

declare( strict_types = 1 );

namespace OC\Theme\Stats;

defined( 'ABSPATH' ) || exit;

/**
 * The rules.
 */
final class Insights {

	/**
	 * Cache key.
	 */
	const CACHE = 'oc_stats_insights';

	/**
	 * Option holding dismissed keys and until when.
	 */
	const DISMISSED = 'oc_stats_dismissed';

	/**
	 * How many make the screen.
	 */
	const MAX = 5;

	/**
	 * The top insights, cached for six hours.
	 *
	 * @param bool $fresh Skip the cache.
	 * @return array<int,array<string,mixed>>
	 */
	public static function top( bool $fresh = false ): array {
		$all = $fresh ? false : get_transient( self::CACHE );

		if ( ! is_array( $all ) ) {
			$all = self::build();
			set_transient( self::CACHE, $all, 6 * HOUR_IN_SECONDS );
		}

		$dismissed = self::dismissed();
		$out       = array();

		foreach ( $all as $ins ) {
			if ( isset( $dismissed[ $ins['key'] ] ) && $dismissed[ $ins['key'] ] > time() ) {
				continue;
			}

			$out[] = $ins;
		}

		return array_slice( $out, 0, self::MAX );
	}

	/**
	 * Keys the shop said "not relevant" to, with their expiry.
	 *
	 * @return array<string,int>
	 */
	public static function dismissed(): array {
		$d = get_option( self::DISMISSED, array() );

		return is_array( $d ) ? array_map( 'intval', $d ) : array();
	}

	/**
	 * Silence one insight for 30 days.
	 *
	 * @param string $key Its key.
	 */
	public static function dismiss( string $key ): void {
		$d = self::dismissed();

		foreach ( $d as $k => $until ) {
			if ( $until < time() ) {
				unset( $d[ $k ] );
			}
		}

		$d[ $key ] = time() + 30 * DAY_IN_SECONDS;

		update_option( self::DISMISSED, $d, false );
	}

	/**
	 * Every rule, in worth order.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function build(): array {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return array();
		}

		$d30 = Query::range( 'd30' );
		$d90 = Query::range( 'd90' );
		$cur = $d30['cur'];
		$out = array();

		foreach ( array( 'checkout_drop', 'out_of_stock', 'category_down', 'buried', 'no_cart', 'search_misses', 'pairs', 'timing' ) as $rule ) {
			try {
				$out = array_merge( $out, self::$rule( $cur, $d30['prev'], $d90['cur'] ) );
			} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- a rule that breaks must not take the screen down.
				continue;
			}
		}

		usort(
			$out,
			static function ( array $a, array $b ): int {
				$sev = array(
					'crit' => 0,
					'warn' => 1,
					'good' => 2,
					'info' => 3,
				);
				$sa  = $sev[ $a['sev'] ] ?? 3;
				$sb  = $sev[ $b['sev'] ] ?? 3;

				if ( $sa !== $sb ) {
					return $sa <=> $sb;
				}

				return ( (float) $b['value'] ) <=> ( (float) $a['value'] );
			}
		);

		return $out;
	}

	/**
	 * One insight, shaped.
	 *
	 * @param string $key     Stable key (rule + object).
	 * @param string $sev     crit | warn | good | info.
	 * @param string $kind    Short label.
	 * @param string $title   Headline.
	 * @param string $body    Sentence with figures.
	 * @param float  $value   Estimated worth in shop currency, 0 for none.
	 * @param array  $actions Pairs of [label, url].
	 * @param string $why     How it was worked out.
	 * @return array<string,mixed>
	 */
	private static function one( string $key, string $sev, string $kind, string $title, string $body, float $value, array $actions, string $why ): array {
		return array(
			'key'     => $key,
			'sev'     => $sev,
			'kind'    => $kind,
			'title'   => $title,
			'body'    => $body,
			'value'   => $value,
			'actions' => $actions,
			'why'     => $why,
		);
	}

	/**
	 * Money, in the shop's currency, whole.
	 *
	 * @param float $n Amount.
	 */
	private static function money( float $n ): string {
		return wp_strip_all_tags( wc_price( round( $n ), array( 'decimals' => 0 ) ) );
	}

	/**
	 * A product's name, or a placeholder for one that is gone.
	 *
	 * @param int $pid Product id.
	 */
	private static function name( int $pid ): string {
		$p = wc_get_product( $pid );

		return $p ? $p->get_name() : '#' . $pid;
	}

	/**
	 * Rule: completion from the checkout fell in the last two days.
	 *
	 * @param array<string,mixed> $cur  30-day metrics.
	 * @param array<string,mixed> $prev Previous 30 days.
	 * @param array<string,mixed> $d90  90-day metrics.
	 * @return array<int,array<string,mixed>>
	 */
	private static function checkout_drop( array $cur, array $prev, array $d90 ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- every rule shares one signature.
		global $wpdb;

		if ( '2' !== (string) get_option( 'oc_stats_tables', '' ) || (int) $cur['checkout_sessions'] < 40 ) {
			return array();
		}

		$avg   = (float) $cur['purchase_sessions'] / max( 1, (float) $cur['checkout_sessions'] );
		$t     = Track::events_table();
		$since = (string) wp_date( 'Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- our own table.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DATE_FORMAT(t, '%%Y-%%m-%%d %%H') h, type, COUNT(DISTINCT sid) n FROM {$t} WHERE t >= %s AND type IN ('checkout','purchase') AND sid <> '' GROUP BY h, type ORDER BY h",
				$since
			)
		);
		// phpcs:enable

		$win = array();

		foreach ( (array) $rows as $r ) {
			$hour = (int) substr( (string) $r->h, 11, 2 );
			$slot = substr( (string) $r->h, 0, 10 ) . ' ' . sprintf( '%02d', intdiv( $hour, 3 ) * 3 );

			$win[ $slot ]['checkout'] = ( $win[ $slot ]['checkout'] ?? 0 ) + ( 'checkout' === $r->type ? (int) $r->n : 0 );
			$win[ $slot ]['purchase'] = ( $win[ $slot ]['purchase'] ?? 0 ) + ( 'purchase' === $r->type ? (int) $r->n : 0 );
		}

		foreach ( $win as $slot => $w ) {
			$c = (int) $w['checkout'];
			$p = (int) $w['purchase'];

			if ( $c < 10 ) {
				continue;
			}

			$rate = $p / $c;

			if ( $rate < $avg - 0.15 ) {
				$day  = substr( $slot, 0, 10 );
				$from = (int) substr( $slot, 11, 2 );
				$when = sprintf(
					/* translators: 1: date, 2: from hour, 3: to hour. */
					__( 'on %1$s between %2$02d:00 and %3$02d:00', 'oc-theme' ),
					wp_date( 'j.n', strtotime( $day ) ),
					$from,
					$from + 3
				);

				return array(
					self::one(
						'checkout_drop_' . $slot,
						'crit',
						__( 'Checkout drop', 'oc-theme' ),
						sprintf(
							/* translators: %s: when. */
							__( 'Almost nobody completed the checkout %s', 'oc-theme' ),
							$when
						),
						sprintf(
							/* translators: 1: checkouts, 2: completed, 3: usual rate. */
							__( '%1$d reached the checkout, %2$d completed. The usual rate is %3$d%%. A sign of a payment or shipping failure.', 'oc-theme' ),
							$c,
							$p,
							(int) round( $avg * 100 )
						),
						(float) $cur['gross'] / max( 1, (float) $cur['orders'] ) * ( $c * $avg - $p ),
						array(
							array( __( 'Check payment methods', 'oc-theme' ), admin_url( 'admin.php?page=wc-settings&tab=checkout' ) ),
							array( __( 'Failed orders', 'oc-theme' ), admin_url( 'admin.php?page=wc-orders&status=wc-failed' ) ),
						),
						__( 'Rule: in a three-hour window with at least 10 checkouts, completion fell more than 15 points below the 30-day average. Worth: the orders that window would usually have produced, at the average order value.', 'oc-theme' )
					),
				);
			}
		}

		return array();
	}

	/**
	 * Rule: a wanted product with nothing in stock.
	 *
	 * @param array<string,mixed> $cur  30-day metrics.
	 * @param array<string,mixed> $prev Previous 30 days.
	 * @param array<string,mixed> $d90  90-day metrics.
	 * @return array<int,array<string,mixed>>
	 */
	private static function out_of_stock( array $cur, array $prev, array $d90 ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- one signature for every rule.
		$out   = array();
		$views = (array) $cur['product_views'];
		arsort( $views );

		foreach ( array_slice( $views, 0, 60, true ) as $pid => $n ) {
			$pid = (int) $pid;

			if ( (int) $n < 40 ) {
				break;
			}

			$product = wc_get_product( $pid );

			if ( ! $product || $product->is_in_stock() ) {
				continue;
			}

			$list    = get_post_meta( $pid, '_oc_notify_list', true );
			$waiting = is_array( $list ) ? count( $list ) : 0;
			$monthly = (float) ( $d90['product_gross'][ $pid ] ?? 0 ) / 3;

			$out[] = self::one(
				'stock_' . $pid,
				'crit',
				__( 'Out of stock', 'oc-theme' ),
				$waiting > 0
					? sprintf(
						/* translators: 1: how many wait, 2: product. */
						_n( '%1$d person is waiting for %2$s, and the stock is zero', '%1$d people are waiting for %2$s, and the stock is zero', $waiting, 'oc-theme' ),
						$waiting,
						$product->get_name()
					)
					: sprintf(
						/* translators: %s: product. */
						__( '%s is out of stock and people keep looking at it', 'oc-theme' ),
						$product->get_name()
					),
				sprintf(
					/* translators: 1: views, 2: monthly worth. */
					__( 'Viewed %1$d times in the last 30 days. At its usual pace, restocking is worth about %2$s a month.', 'oc-theme' ),
					(int) $n,
					self::money( $monthly )
				),
				$monthly,
				array_values(
					array_filter(
						array(
							array( __( 'Update stock', 'oc-theme' ), (string) get_edit_post_link( $pid, 'raw' ) ),
							$waiting > 0 ? array( __( 'The waiting list', 'oc-theme' ), admin_url( 'admin.php?page=oc-waitlist' ) ) : null,
						)
					)
				),
				__( 'Rule: at least 40 product views in 30 days, no stock, and optionally people on the back-in-stock list. Worth: the product\'s sales over the last 90 days, divided by three.', 'oc-theme' )
			);

			if ( count( $out ) >= 3 ) {
				break;
			}
		}

		return $out;
	}

	/**
	 * Rule: a category whose sales fell while its visits did not.
	 *
	 * @param array<string,mixed> $cur  30-day metrics.
	 * @param array<string,mixed> $prev Previous 30 days.
	 * @param array<string,mixed> $d90  90-day metrics.
	 * @return array<int,array<string,mixed>>
	 */
	private static function category_down( array $cur, array $prev, array $d90 ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- one signature for every rule.
		$out = array();

		foreach ( (array) $prev['cat_gross'] as $tid => $before ) {
			$tid   = (int) $tid;
			$now   = (float) ( $cur['cat_gross'][ $tid ] ?? 0 );
			$views = (float) ( $cur['cat_views'][ $tid ] ?? 0 );
			$vprev = (float) ( $prev['cat_views'][ $tid ] ?? 0 );

			if ( (float) $before < 1000 || $now > (float) $before * 0.75 ) {
				continue;
			}

			$term = get_term( $tid, 'product_cat' );

			if ( ! $term instanceof \WP_Term ) {
				continue;
			}

			$visits_note = $vprev > 0 && $views >= $vprev * 0.9
				? __( 'Visits to the category held; the drop is inside it — stock that ran out, a price that changed, or a lead product that fell off the first row.', 'oc-theme' )
				: __( 'Visits to the category fell too — fewer people reach it. Worth a place on the home page or in the menu.', 'oc-theme' );

			$out[] = self::one(
				'cat_down_' . $tid,
				'warn',
				__( 'Category', 'oc-theme' ),
				sprintf(
					/* translators: 1: category, 2: percent. */
					__( 'Sales in %1$s fell %2$d%% against the previous 30 days', 'oc-theme' ),
					$term->name,
					(int) round( ( 1 - $now / (float) $before ) * 100 )
				),
				sprintf(
					/* translators: 1: before, 2: now. */
					__( '%1$s before, %2$s now. ', 'oc-theme' ),
					self::money( (float) $before ),
					self::money( $now )
				) . $visits_note,
				(float) $before - $now,
				array(
					array( __( 'Open the category', 'oc-theme' ), (string) get_term_link( $term ) ),
					array( __( 'Its products', 'oc-theme' ), admin_url( 'edit.php?post_type=product&product_cat=' . rawurlencode( $term->slug ) ) ),
				),
				__( 'Rule: a category with at least 1,000 in sales in the previous 30 days whose sales fell by 25% or more. The note reads the category\'s own visits to tell traffic from conversion.', 'oc-theme' )
			);

			if ( count( $out ) >= 2 ) {
				break;
			}
		}

		return $out;
	}

	/**
	 * Rule: a product that converts well but sits deep in its category.
	 *
	 * @param array<string,mixed> $cur  30-day metrics.
	 * @param array<string,mixed> $prev Previous 30 days.
	 * @param array<string,mixed> $d90  90-day metrics.
	 * @return array<int,array<string,mixed>>
	 */
	private static function buried( array $cur, array $prev, array $d90 ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- one signature for every rule.
		$views_all = array_sum( (array) $cur['product_views'] );

		if ( $views_all < 500 || (int) $cur['orders'] < 20 ) {
			return array();
		}

		$shop_conv = (float) array_sum( (array) $cur['product_orders'] ) / $views_all;
		$out       = array();

		foreach ( (array) $cur['product_views'] as $pid => $views ) {
			$pid    = (int) $pid;
			$orders = (float) ( $cur['product_orders'][ $pid ] ?? 0 );

			if ( (int) $views < 100 || $orders < 5 || $orders / (float) $views < $shop_conv * 1.4 ) {
				continue;
			}

			$product = wc_get_product( $pid );

			if ( ! $product || ! $product->is_in_stock() ) {
				continue;
			}

			$terms = wp_get_post_terms( $pid, 'product_cat' );
			$term  = is_array( $terms ) && ! empty( $terms ) ? $terms[0] : null;

			if ( ! $term instanceof \WP_Term ) {
				continue;
			}

			$args  = function_exists( 'WC' ) && WC()->query ? WC()->query->get_catalog_ordering_args() : array();
			$first = wc_get_products(
				array(
					'status'   => 'publish',
					'limit'    => 12,
					'category' => array( $term->slug ),
					'orderby'  => (string) ( $args['orderby'] ?? 'menu_order' ),
					'order'    => (string) ( $args['order'] ?? 'ASC' ),
					'return'   => 'ids',
				)
			);

			if ( in_array( $pid, array_map( 'intval', (array) $first ), true ) ) {
				continue;
			}

			$worth = ( (float) ( $cur['product_gross'][ $pid ] ?? 0 ) ) * 0.6;

			$out[] = self::one(
				'buried_' . $pid,
				'warn',
				__( 'Catalogue order', 'oc-theme' ),
				sprintf(
					/* translators: 1: product, 2: category. */
					__( '%1$s converts better than the shop, and sits past the first rows of %2$s', 'oc-theme' ),
					$product->get_name(),
					$term->name
				),
				sprintf(
					/* translators: 1: product rate, 2: shop rate, 3: worth. */
					__( 'Its views turn into orders at %1$.1f%% against %2$.1f%% shop-wide. Moving it up, or making it a large tile, is worth about %3$s a month.', 'oc-theme' ),
					$orders / (float) $views * 100,
					$shop_conv * 100,
					self::money( $worth )
				),
				$worth,
				array(
					array( __( 'Edit the product', 'oc-theme' ), (string) get_edit_post_link( $pid, 'raw' ) ),
					array( __( 'Open the category', 'oc-theme' ), (string) get_term_link( $term ) ),
				),
				__( 'Rule: at least 100 views and 5 orders in 30 days, a view-to-order rate 40% above the shop\'s, and not among the first 12 products of its category in the shop\'s default order. Worth: 60% of its current monthly sales, the usual lift from the first rows.', 'oc-theme' )
			);

			if ( count( $out ) >= 2 ) {
				break;
			}
		}

		return $out;
	}

	/**
	 * Rule: many views, few adds to cart.
	 *
	 * @param array<string,mixed> $cur  30-day metrics.
	 * @param array<string,mixed> $prev Previous 30 days.
	 * @param array<string,mixed> $d90  90-day metrics.
	 * @return array<int,array<string,mixed>>
	 */
	private static function no_cart( array $cur, array $prev, array $d90 ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- one signature for every rule.
		$views_all = array_sum( (array) $cur['product_views'] );
		$atc_all   = array_sum( (array) $cur['product_atc'] );

		if ( $views_all < 500 || $atc_all < 20 ) {
			return array();
		}

		$avg   = $atc_all / $views_all;
		$views = (array) $cur['product_views'];
		arsort( $views );
		$out = array();

		foreach ( array_slice( $views, 0, 40, true ) as $pid => $n ) {
			$pid  = (int) $pid;
			$atc  = (float) ( $cur['product_atc'][ $pid ] ?? 0 );
			$rate = $atc / (float) $n;

			if ( (int) $n < 100 || $rate >= $avg * 0.5 ) {
				continue;
			}

			$product = wc_get_product( $pid );

			if ( ! $product || ! $product->is_in_stock() ) {
				continue;
			}

			$worth = ( $avg - $rate ) * (float) $n * ( (float) $cur['gross'] / max( 1, (float) $cur['orders'] ) ) * 0.3;

			$out[] = self::one(
				'no_cart_' . $pid,
				'warn',
				__( 'Picture or price', 'oc-theme' ),
				sprintf(
					/* translators: %s: product. */
					__( '%s is viewed a lot, but hardly added to the cart', 'oc-theme' ),
					$product->get_name()
				),
				sprintf(
					/* translators: 1: views, 2: rate, 3: shop rate. */
					__( '%1$d views and %2$.1f%% added to cart, against %3$.1f%% shop-wide. Usually the main photo, the price against competitors, or a thin description.', 'oc-theme' ),
					(int) $n,
					$rate * 100,
					$avg * 100
				),
				$worth,
				array(
					array( __( 'Edit the product', 'oc-theme' ), (string) get_edit_post_link( $pid, 'raw' ) ),
					array( __( 'See it as a shopper', 'oc-theme' ), (string) get_permalink( $pid ) ),
				),
				__( 'Rule: at least 100 views in 30 days and an add-to-cart rate under half the shop\'s. Worth: the adds the shop average would have produced, at the average order value, times the usual 30% that go on to buy.', 'oc-theme' )
			);

			if ( count( $out ) >= 2 ) {
				break;
			}
		}

		return $out;
	}

	/**
	 * Rule: searches that found nothing.
	 *
	 * @param array<string,mixed> $cur  30-day metrics.
	 * @param array<string,mixed> $prev Previous 30 days.
	 * @param array<string,mixed> $d90  90-day metrics.
	 * @return array<int,array<string,mixed>>
	 */
	private static function search_misses( array $cur, array $prev, array $d90 ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- one signature for every rule.
		global $wpdb;

		if ( ! class_exists( '\OC\Theme\Search_Index' ) ) {
			return array();
		}

		$t     = \OC\Theme\Search_Index::log();
		$since = Query::shift( Query::today(), -7 );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT term, SUM(searches) n FROM {$t} WHERE day >= %s AND hits = 0 GROUP BY term HAVING n >= 10 ORDER BY n DESC LIMIT 3", $since ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the search module's own table.

		if ( empty( $rows ) ) {
			return array();
		}

		$out = array();

		foreach ( $rows as $r ) {
			$out[] = self::one(
				'search_' . md5( (string) $r->term ),
				'info',
				__( 'Hidden demand', 'oc-theme' ),
				sprintf(
					/* translators: 1: count, 2: term. */
					__( '%1$d searched for "%2$s" this week and found nothing', 'oc-theme' ),
					(int) $r->n,
					(string) $r->term
				),
				__( 'Either the product is missing, or it is called something else in the shop. A synonym fixes the second case in one click.', 'oc-theme' ),
				0,
				array(
					array( __( 'Search reports', 'oc-theme' ), admin_url( 'admin.php?page=oc-search' ) ),
				),
				__( 'Rule: a search term with no results, typed at least 10 times in the last 7 days, from the theme\'s own search log.', 'oc-theme' )
			);
		}

		return $out;
	}

	/**
	 * Rule: two products bought together that are not a bundle yet.
	 *
	 * @param array<string,mixed> $cur  30-day metrics.
	 * @param array<string,mixed> $prev Previous 30 days.
	 * @param array<string,mixed> $d90  90-day metrics.
	 * @return array<int,array<string,mixed>>
	 */
	private static function pairs( array $cur, array $prev, array $d90 ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- one signature for every rule.
		if ( (int) $cur['orders'] < 30 ) {
			return array();
		}

		$tz     = wp_timezone();
		$from   = ( new \DateTimeImmutable( Query::shift( Query::today(), -29 ) . ' 00:00:00', $tz ) )->getTimestamp();
		$orders = wc_get_orders(
			array(
				'limit'        => -1,
				'status'       => Query::SALE_STATUSES,
				'date_created' => '>=' . $from,
				'type'         => 'shop_order',
			)
		);

		$count = array();

		foreach ( (array) $orders as $order ) {
			if ( ! $order instanceof \WC_Order ) {
				continue;
			}

			$ids = array();

			foreach ( $order->get_items( 'line_item' ) as $item ) {
				if ( $item instanceof \WC_Order_Item_Product ) {
					$ids[] = (int) $item->get_product_id();
				}
			}

			$ids = array_values( array_unique( array_filter( $ids ) ) );
			sort( $ids );
			$total = count( $ids );

			for ( $i = 0; $i < $total; $i++ ) {
				for ( $j = $i + 1; $j < $total; $j++ ) {
					$k           = $ids[ $i ] . '|' . $ids[ $j ];
					$count[ $k ] = ( $count[ $k ] ?? 0 ) + 1;
				}
			}
		}

		arsort( $count );
		$out = array();

		foreach ( $count as $k => $n ) {
			if ( $n < 15 ) {
				break;
			}

			list( $a, $b ) = array_map( 'intval', explode( '|', $k ) );
			$bt_a          = array_map( 'intval', (array) get_post_meta( $a, '_oc_bt_ids', true ) );
			$bt_b          = array_map( 'intval', (array) get_post_meta( $b, '_oc_bt_ids', true ) );

			if ( in_array( $b, $bt_a, true ) || in_array( $a, $bt_b, true ) ) {
				continue;
			}

			$worth = ( (float) ( $cur['product_gross'][ $a ] ?? 0 ) + (float) ( $cur['product_gross'][ $b ] ?? 0 ) ) * 0.1;

			$out[] = self::one(
				'pair_' . $k,
				'good',
				__( 'Opportunity', 'oc-theme' ),
				sprintf(
					/* translators: %d: orders. */
					__( 'Two products were bought together in %d orders, and are not a bundle', 'oc-theme' ),
					(int) $n
				),
				sprintf(
					/* translators: 1: product, 2: product, 3: worth. */
					__( '%1$s and %2$s. Offering them as "bought together" with a small discount is worth about %3$s a month.', 'oc-theme' ),
					self::name( $a ),
					self::name( $b ),
					self::money( $worth )
				),
				$worth,
				array(
					array( __( 'Set up the bundle', 'oc-theme' ), (string) get_edit_post_link( $a, 'raw' ) ),
				),
				__( 'Rule: a pair of products in at least 15 paid orders over 30 days, neither listing the other under "bought together". Worth: 10% of the pair\'s sales, the usual lift of a visible bundle.', 'oc-theme' )
			);

			if ( count( $out ) >= 2 ) {
				break;
			}
		}

		return $out;
	}

	/**
	 * Rule: when the shop actually sells.
	 *
	 * @param array<string,mixed> $cur  30-day metrics.
	 * @param array<string,mixed> $prev Previous 30 days.
	 * @param array<string,mixed> $d90  90-day metrics.
	 * @return array<int,array<string,mixed>>
	 */
	private static function timing( array $cur, array $prev, array $d90 ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- one signature for every rule.
		$hours = (array) $d90['hours'];

		if ( array_sum( $hours ) < 100 ) {
			return array();
		}

		$best      = array( 0, 0, 0.0 );
		$days_name = array( __( 'Sunday', 'oc-theme' ), __( 'Monday', 'oc-theme' ), __( 'Tuesday', 'oc-theme' ), __( 'Wednesday', 'oc-theme' ), __( 'Thursday', 'oc-theme' ), __( 'Friday', 'oc-theme' ), __( 'Saturday', 'oc-theme' ) );

		for ( $d = 0; $d < 7; $d++ ) {
			for ( $h = 0; $h <= 21; $h++ ) {
				$sum = 0.0;

				for ( $i = 0; $i < 3; $i++ ) {
					$sum += (float) ( $hours[ $d * 24 + $h + $i ] ?? 0 );
				}

				if ( $sum > $best[2] ) {
					$best = array( $d, $h, $sum );
				}
			}
		}

		$share = $best[2] / max( 1, array_sum( $hours ) ) * 100;

		return array(
			self::one(
				'timing',
				'info',
				__( 'Timing', 'oc-theme' ),
				sprintf(
					/* translators: 1: weekday, 2: from hour, 3: to hour. */
					__( 'Your buyers buy most on %1$s between %2$02d:00 and %3$02d:00', 'oc-theme' ),
					$days_name[ $best[0] ],
					$best[1],
					$best[1] + 3
				),
				sprintf(
					/* translators: %s: percent. */
					__( 'That window alone holds %s%% of the last 90 days\' orders. A campaign or a message that lands an hour before it works harder.', 'oc-theme' ),
					number_format_i18n( $share, 1 )
				),
				0,
				array(),
				__( 'Rule: paid orders over 90 days, by weekday and hour; the strongest three-hour window.', 'oc-theme' )
			),
		);
	}
}
