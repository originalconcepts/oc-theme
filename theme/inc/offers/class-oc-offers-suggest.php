<?php
/**
 * Offers: rules worth adding, read off the shop's own orders.
 *
 * Two products that keep landing in the same paid order, and are not yet
 * offered beside each other, are a rule waiting to be written. The shop
 * owner approves or skips each one; nothing is added on its own.
 *
 * @package OC_Theme
 */

declare( strict_types = 1 );

namespace OC\Theme\Offers;

defined( 'ABSPATH' ) || exit;

/**
 * Suggestions from the order history.
 */
final class Suggest {

	/**
	 * Pairs the owner waved away (or accepted), as "a:b" strings.
	 */
	const SKIPPED = 'oc_offers_skipped';

	/**
	 * The pair list, recomputed daily or on request.
	 */
	const CACHE = 'oc_offers_pairs';

	/**
	 * How many paid orders must hold the pair.
	 */
	const MIN_ORDERS = 2;

	/**
	 * The suggestions to show: pairs not skipped and not already offered.
	 *
	 * @param int $limit How many at most.
	 * @return array<int,array{a:int,b:int,n:int}>
	 */
	public static function listing( int $limit = 4 ): array {
		$skipped = self::skipped();
		$out     = array();

		foreach ( self::pairs() as $pair ) {
			if ( in_array( $pair['a'] . ':' . $pair['b'], $skipped, true ) || in_array( $pair['b'] . ':' . $pair['a'], $skipped, true ) ) {
				continue;
			}

			// Each product is offered by hand or by a rule (automatic
			// offers do not count — the point is to make it deliberate).
			$offered = false;

			foreach ( array_slice( Offers::for_product( $pair['a'], 'cart' ), 0, 4 ) as $offer ) {
				if ( $offer['id'] === $pair['b'] && 'auto' !== $offer['src'] ) {
					$offered = true;
					break;
				}
			}

			if ( $offered ) {
				continue;
			}

			if ( ! Offers::addable( $pair['b'] ) || ! get_post( $pair['a'] ) ) {
				continue;
			}

			$out[] = $pair;

			if ( count( $out ) >= $limit ) {
				break;
			}
		}

		return $out;
	}

	/**
	 * Products bought together in paid orders, the pair seen most often
	 * first. "a" is the one sold more on its own — the anchor; "b" the
	 * one to offer beside it.
	 *
	 * @return array<int,array{a:int,b:int,n:int}>
	 */
	public static function pairs(): array {
		$cached = get_transient( self::CACHE );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- WooCommerce's own lookup tables, names from $wpdb->prefix; cached for a day.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT a.product_id AS a, b.product_id AS b, COUNT(DISTINCT a.order_id) AS n
				FROM {$wpdb->prefix}wc_order_product_lookup a
				JOIN {$wpdb->prefix}wc_order_product_lookup b ON b.order_id = a.order_id AND b.product_id > a.product_id
				JOIN {$wpdb->prefix}wc_order_stats s ON s.order_id = a.order_id
				WHERE s.status IN ('wc-processing','wc-completed') AND a.product_id > 0 AND b.product_id > 0
				GROUP BY a.product_id, b.product_id
				HAVING n >= %d
				ORDER BY n DESC, a.product_id DESC
				LIMIT 40",
				self::MIN_ORDERS
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$pairs = array();

		foreach ( (array) $rows as $row ) {
			$a = (int) $row['a'];
			$b = (int) $row['b'];

			// The anchor is the one that sells more by itself.
			$sa = (int) get_post_meta( $a, 'total_sales', true );
			$sb = (int) get_post_meta( $b, 'total_sales', true );

			if ( $sb > $sa ) {
				list( $a, $b ) = array( $b, $a );
			}

			$pairs[] = array(
				'a' => $a,
				'b' => $b,
				'n' => (int) $row['n'],
			);
		}

		set_transient( self::CACHE, $pairs, DAY_IN_SECONDS );

		return $pairs;
	}

	/**
	 * Pairs the owner has dealt with.
	 *
	 * @return string[]
	 */
	public static function skipped(): array {
		$list = get_option( self::SKIPPED );

		return is_array( $list ) ? array_values( array_filter( array_map( 'strval', $list ) ) ) : array();
	}

	/**
	 * Remember a pair as dealt with.
	 *
	 * @param int $a Anchor.
	 * @param int $b Offered.
	 */
	public static function dismiss( int $a, int $b ): void {
		$list   = self::skipped();
		$list[] = $a . ':' . $b;

		update_option( self::SKIPPED, array_values( array_unique( $list ) ), false );
	}

	/**
	 * Forget the pair list, so the next screen view reads the orders again.
	 */
	public static function rescan(): void {
		delete_transient( self::CACHE );
	}
}
