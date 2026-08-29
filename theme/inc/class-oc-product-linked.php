<?php
/**
 * The linked-product blocks on a product page.
 *
 * WooCommerce gives a product three lists of neighbours and shows them in two
 * places: related products and up-sells go under the summary, cross-sells wait
 * in the cart. This class takes charge of all three so a shop can decide what
 * appears, where, and in what shape.
 *
 * Two jobs live here:
 *
 *   1. Related products — which ones count as related, and whether they read
 *      as a grid or a slider.
 *   2. Cross-sells — the products that go *with* this one, brought onto the
 *      product page in one of three positions, each with its own manners.
 *
 * @package OC_Theme
 */

declare( strict_types = 1 );

namespace OC\Theme;

defined( 'ABSPATH' ) || exit;

/**
 * Related products and cross-sells.
 */
final class Product_Linked {

	/**
	 * Hook in.
	 */
	public function register(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		add_filter( 'woocommerce_get_related_product_cat_terms', array( $this, 'related_terms' ), 10, 2 );
		add_filter( 'woocommerce_output_related_products_args', array( $this, 'related_args' ) );
	}

	/*
	 * ----------------------------------------------------------------- related
	 */

	/**
	 * Which categories decide what counts as related.
	 *
	 * WooCommerce hands us every category the product sits in and finds
	 * neighbours in all of them. On a shop where a bedside table is filed
	 * under "Bedroom", "Side tables" and "NEW", that means the related row
	 * fills with whatever else is new — the loosest of the three wins, and
	 * the row stops being about this product at all.
	 *
	 * On the narrow setting only the most specific categories are kept:
	 *
	 *   - a category that is the parent of another one the product is in is
	 *     dropped, because the child says the same thing more precisely;
	 *   - top-level categories are then dropped too, but only if something
	 *     deeper survived — a product filed *only* at the top level keeps
	 *     what it has rather than losing its related row entirely.
	 *
	 * @param int[] $terms      Category term ids.
	 * @param int   $product_id The product.
	 * @return int[]
	 */
	public function related_terms( $terms, $product_id ) {
		unset( $product_id );

		$terms = array_values( array_unique( array_map( 'intval', (array) $terms ) ) );

		if ( 'leaf' !== (string) get_theme_mod( 'oc_related_scope', 'all' ) || count( $terms ) < 2 ) {
			return $terms;
		}

		// Drop anything that is an ancestor of another term in the list.
		$ancestors = array();
		foreach ( $terms as $id ) {
			foreach ( (array) get_ancestors( $id, 'product_cat', 'taxonomy' ) as $up ) {
				$ancestors[ (int) $up ] = true;
			}
		}

		$deepest = array();
		foreach ( $terms as $id ) {
			if ( ! isset( $ancestors[ $id ] ) ) {
				$deepest[] = $id;
			}
		}

		if ( ! $deepest ) {
			return $terms;
		}

		// Of those, prefer the ones that actually sit under a parent. A
		// marketing shelf like NEW or SALE lives at the top level and would
		// otherwise drag the whole catalogue in behind it.
		$nested = array();
		foreach ( $deepest as $id ) {
			$term = get_term( $id, 'product_cat' );
			if ( $term instanceof \WP_Term && $term->parent > 0 ) {
				$nested[] = $id;
			}
		}

		return $nested ? $nested : $deepest;
	}

	/**
	 * How many related products to fetch, and in how many columns.
	 *
	 * A slider wants more than a grid — a row that scrolls and runs out
	 * after four looks broken.
	 *
	 * @param array<string,mixed> $args Loop args.
	 * @return array<string,mixed>
	 */
	public function related_args( $args ) {
		$cols = (int) get_theme_mod( 'oc_related_cols', 4 );
		$cols = max( 2, min( 6, $cols ) );

		$args['columns']        = $cols;
		$args['posts_per_page'] = 'slider' === self::related_layout() ? max( 8, $cols * 3 ) : $cols;

		return $args;
	}

	/**
	 * Grid or slider for the related row.
	 */
	public static function related_layout(): string {
		$layout = (string) get_theme_mod( 'oc_related_layout', 'grid' );

		return 'slider' === $layout ? 'slider' : 'grid';
	}

	/**
	 * Where the related row's cards sit when they do not fill the width.
	 */
	public static function related_align(): string {
		$align = (string) get_theme_mod( 'oc_related_align', 'start' );

		return 'center' === $align ? 'center' : 'start';
	}
}
