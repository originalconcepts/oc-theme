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
	 * Categories whose paid orders keep taking more than one unit, with the
	 * quantities they take — a tiers rule waiting to be written. Only
	 * categories no tiers rule covers yet, and not the ones waved away.
	 *
	 * @param int $limit How many at most.
	 * @return array<int,array{cat:int,name:string,n:int,tiers:array<int,array{qty:int,pct:int,label:string}>}>
	 */
	public static function tier_listing( int $limit = 3 ): array {
		$skipped = self::skipped();
		$rules   = Offers::settings()['rules'];
		$out     = array();

		foreach ( self::quantity_patterns() as $cat => $row ) {
			if ( in_array( 't:' . $cat, $skipped, true ) ) {
				continue;
			}

			$facts = array(
				'id'     => 0,
				'cats'   => array_merge( array( (int) $cat ), array_map( 'intval', get_ancestors( (int) $cat, 'product_cat', 'taxonomy' ) ) ),
				'brands' => array(),
			);

			if ( null !== Rules::pick_tiers( $rules, $facts ) ) {
				continue;
			}

			$term = get_term( (int) $cat, 'product_cat' );

			if ( ! $term instanceof \WP_Term ) {
				continue;
			}

			$out[] = array(
				'cat'   => (int) $cat,
				'name'  => $term->name,
				'n'     => $row['n'],
				'tiers' => self::tiers_from( $row['qtys'] ),
			);

			if ( count( $out ) >= $limit ) {
				break;
			}
		}

		return $out;
	}

	/**
	 * The tiers to propose for a category, from its quantity pattern.
	 *
	 * @param int $cat Category id.
	 * @return array<int,array{qty:int,pct:int,label:string}>
	 */
	public static function tiers_for_category( int $cat ): array {
		$patterns = self::quantity_patterns();

		return isset( $patterns[ $cat ] ) ? self::tiers_from( $patterns[ $cat ]['qtys'] ) : array();
	}

	/**
	 * Two quantities into a modest ladder: 5% at the first, 10% at the
	 * second, the first marked the most popular. The owner adjusts.
	 *
	 * @param int[] $qtys The quantities most often bought, most common first.
	 * @return array<int,array{qty:int,pct:int,label:string}>
	 */
	private static function tiers_from( array $qtys ): array {
		$qtys = array_values( array_unique( array_filter( array_map( 'intval', $qtys ), static fn( $q ) => $q >= 2 ) ) );
		sort( $qtys );

		$rows = array();

		foreach ( array_slice( $qtys, 0, 2 ) as $i => $qty ) {
			$rows[] = array(
				'qty'   => $qty,
				'pct'   => 0 === $i ? 5 : 10,
				'label' => 0 === $i ? 'popular' : 'best',
			);
		}

		return Rules::clean_tiers( $rows );
	}

	/**
	 * Per leaf category: how many paid order lines took two units or more,
	 * and the two quantities taken most often. Once a day.
	 *
	 * @return array<int,array{n:int,qtys:int[]}>
	 */
	private static function quantity_patterns(): array {
		$cached = get_transient( self::CACHE . '_q' );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- WooCommerce's own lookup tables and core taxonomy tables; cached for a day.
		$rows = $wpdb->get_results(
			"SELECT tt.term_id AS cat, l.product_qty AS qty, COUNT(*) AS n
			FROM {$wpdb->prefix}wc_order_product_lookup l
			JOIN {$wpdb->prefix}wc_order_stats s ON s.order_id = l.order_id
			JOIN {$wpdb->term_relationships} tr ON tr.object_id = l.product_id
			JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'product_cat'
			WHERE s.status IN ('wc-processing','wc-completed') AND l.product_qty >= 2
			GROUP BY tt.term_id, l.product_qty
			ORDER BY n DESC
			LIMIT 400",
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$by_cat = array();
		$shop   = (int) wp_count_posts( 'product' )->publish;

		foreach ( (array) $rows as $row ) {
			$cat = (int) $row['cat'];

			// Only the deepest categories: a hub like "Hardware" says
			// nothing about how its screws are bought. Nor does a label
			// like "New" or "Sale" that holds a third of the shop.
			if ( get_term_children( $cat, 'product_cat' ) ) {
				continue;
			}

			$term = get_term( $cat, 'product_cat' );

			if ( ! $term instanceof \WP_Term || ( $shop > 0 && $term->count > $shop * 0.3 ) ) {
				continue;
			}

			if ( ! isset( $by_cat[ $cat ] ) ) {
				$by_cat[ $cat ] = array(
					'n'    => 0,
					'qtys' => array(),
				);
			}

			$by_cat[ $cat ]['n'] += (int) $row['n'];

			if ( count( $by_cat[ $cat ]['qtys'] ) < 2 ) {
				$by_cat[ $cat ]['qtys'][] = (int) $row['qty'];
			}
		}

		// Three lines at least, or it is one shopper's habit.
		$by_cat = array_filter( $by_cat, static fn( $row ) => $row['n'] >= 3 );

		uasort( $by_cat, static fn( $a, $b ) => $b['n'] <=> $a['n'] );

		set_transient( self::CACHE . '_q', $by_cat, DAY_IN_SECONDS );

		return $by_cat;
	}

	/**
	 * Remember a category's tiers suggestion as dealt with.
	 *
	 * @param int $cat Category id.
	 */
	public static function dismiss_tiers( int $cat ): void {
		$list   = self::skipped();
		$list[] = 't:' . $cat;

		update_option( self::SKIPPED, array_values( array_unique( $list ) ), false );
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
		delete_transient( self::CACHE . '_q' );
	}
}
