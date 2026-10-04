<?php
/**
 * Products to test a shop with.
 *
 * A finished shop has no products in it, and most of what the
 * questionnaire set up cannot be seen until it does: the card, the sale
 * badge, the swatches, the "out of stock" line, the filters, the related
 * row. So this fills it with a dozen, built out of the shop's own
 * answers — its departments, its attributes, its currency — and not out
 * of a generic catalogue, because the point is to test THIS shop.
 *
 * Every one is marked. Nothing here is ever left for a real customer to
 * buy: the same screen that makes them takes them away again, and it
 * refuses to remove one that somebody has actually ordered.
 *
 * @package OC_Theme
 */

declare( strict_types = 1 );

namespace OC\Theme\Onboard;

defined( 'ABSPATH' ) || exit;

/**
 * Makes and unmakes the test catalogue.
 */
final class Demo {

	/**
	 * On the product, on its variations and on its pictures.
	 */
	const MARK = '_oc_demo';

	/**
	 * How many to make.
	 */
	const HOW_MANY = 12;

	/**
	 * Build the test catalogue.
	 *
	 * @return array<string,mixed> What happened.
	 */
	public static function make(): array {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return array( 'error' => __( 'WooCommerce is not here.', 'oc-theme' ) );
		}

		$cats = self::cats();
		$vary = self::vary();
		$made = array();

		for ( $i = 0; $i < self::HOW_MANY; $i++ ) {
			// A catalogue all of one kind tests one thing. The mix is
			// deliberate: something on sale, something sold out, something
			// with no picture at all, and the rest ordinary.
			$kind = ( $vary && $i < 3 ) ? 'variable' : 'simple';
			$sale = in_array( $i, array( 1, 4, 7 ), true );
			$gone = 5 === $i;
			$bare = 9 === $i;

			$id = self::one( $i, $kind, $cats, $vary, $sale, $gone, $bare );

			if ( $id ) {
				$made[] = $id;
			}
		}

		if ( function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients();
		}

		return array(
			'made' => count( $made ),
			'vary' => $vary ? count( $vary ) : 0,
			'cats' => count( $cats ),
		);
	}

	/**
	 * Take them all away again.
	 *
	 * @return array<string,mixed> What happened.
	 */
	public static function remove(): array {
		$ids  = get_posts(
			array(
				'post_type'   => 'product',
				'post_status' => 'any',
				'numberposts' => -1,
				'fields'      => 'ids',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- a one-off admin action on a dozen rows.
				'meta_key'    => self::MARK,
			)
		);
		$gone = 0;
		$kept = 0;

		foreach ( (array) $ids as $id ) {
			// Somebody bought it. However it got there, an ordered product
			// is a record of a sale and is not ours to delete.
			if ( self::was_ordered( (int) $id ) ) {
				++$kept;

				continue;
			}

			$product = function_exists( 'wc_get_product' ) ? wc_get_product( (int) $id ) : null;

			if ( $product && $product->is_type( 'variable' ) ) {
				foreach ( $product->get_children() as $child ) {
					wp_delete_post( (int) $child, true );
				}
			}

			foreach ( self::pictures_of( (int) $id ) as $shot ) {
				wp_delete_attachment( $shot, true );
			}

			wp_delete_post( (int) $id, true );

			++$gone;
		}

		if ( function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients();
		}

		return array(
			'gone' => $gone,
			'kept' => $kept,
		);
	}

	/**
	 * How many are standing right now.
	 */
	public static function count(): int {
		$ids = get_posts(
			array(
				'post_type'   => 'product',
				'post_status' => 'any',
				'numberposts' => -1,
				'fields'      => 'ids',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- a one-off admin screen.
				'meta_key'    => self::MARK,
			)
		);

		return count( (array) $ids );
	}

	/* ---------------------------------------------------------- the making */

	/**
	 * One product.
	 *
	 * @param int                             $n    Which one.
	 * @param string                          $kind simple | variable.
	 * @param array<int,int>                  $cats Category term ids.
	 * @param array<string,array<int,string>> $vary Attribute taxonomy to values.
	 * @param bool                            $sale On offer.
	 * @param bool                            $gone Out of stock.
	 * @param bool                            $bare Deliberately with no picture.
	 */
	private static function one( int $n, string $kind, array $cats, array $vary, bool $sale, bool $gone, bool $bare ): int {
		$name  = self::name( $n );
		$price = self::price( $n );

		$product = 'variable' === $kind ? new \WC_Product_Variable() : new \WC_Product_Simple();

		$product->set_name( $name );
		$product->set_status( 'publish' );
		$product->set_catalog_visibility( 'visible' );
		$product->set_description( self::words( $name ) );
		$product->set_short_description( self::blurb() );
		$product->set_sku( 'OCDEMO-' . str_pad( (string) ( $n + 1 ), 3, '0', STR_PAD_LEFT ) );

		if ( $cats ) {
			$product->set_category_ids( array( $cats[ $n % count( $cats ) ] ) );
		}

		if ( 'simple' === $kind ) {
			$product->set_regular_price( (string) $price );

			if ( $sale ) {
				$product->set_sale_price( (string) round( $price * 0.75 ) );
			}

			$product->set_manage_stock( true );
			$product->set_stock_quantity( $gone ? 0 : ( 2 === $n % 7 ? 2 : 25 ) );
			$product->set_stock_status( $gone ? 'outofstock' : 'instock' );
		}

		if ( 'variable' === $kind && $vary ) {
			$attrs = array();
			$at    = 0;

			foreach ( $vary as $tax => $values ) {
				$terms = self::terms( $tax, $values );

				if ( ! $terms ) {
					continue;
				}

				$a = new \WC_Product_Attribute();

				$a->set_id( (int) wc_attribute_taxonomy_id_by_name( $tax ) );
				$a->set_name( $tax );
				$a->set_options( $terms );
				$a->set_position( $at++ );
				$a->set_visible( true );
				$a->set_variation( true );

				$attrs[] = $a;
			}

			$product->set_attributes( $attrs );
		}

		$id = $product->save();

		if ( ! $id ) {
			return 0;
		}

		update_post_meta( $id, self::MARK, '1' );

		// One of them has no picture on purpose: a shop always ends up with
		// one, and it is worth seeing what the card does about it.
		if ( ! $bare ) {
			$shot = self::picture( $name, $n );

			if ( $shot ) {
				set_post_thumbnail( $id, $shot );
			}
		}

		if ( 'variable' === $kind && $vary ) {
			self::variations( (int) $id, $price, $sale );
		}

		return (int) $id;
	}

	/**
	 * The variations of one variable product.
	 *
	 * @param int  $owner Product id.
	 * @param int  $price The base price.
	 * @param bool $sale  On offer.
	 */
	private static function variations( int $owner, int $price, bool $sale ): void {
		$product = wc_get_product( $owner );

		if ( ! $product ) {
			return;
		}

		$axes = array();

		foreach ( $product->get_attributes() as $tax => $a ) {
			if ( $a->get_variation() ) {
				$axes[ $tax ] = $a->get_options();
			}
		}

		if ( ! $axes ) {
			return;
		}

		// One axis is varied properly and the rest are pinned, so a shop
		// with three attributes does not end up with ninety variations.
		$first = (string) array_key_first( $axes );
		$rest  = array();

		foreach ( $axes as $tax => $ids ) {
			if ( $tax !== $first && $ids ) {
				$term = get_term( (int) $ids[0] );

				$rest[ $tax ] = $term instanceof \WP_Term ? $term->slug : '';
			}
		}

		$at = 0;

		foreach ( (array) $axes[ $first ] as $term_id ) {
			$term = get_term( (int) $term_id );

			if ( ! $term instanceof \WP_Term ) {
				continue;
			}

			$v    = new \WC_Product_Variation();
			$pick = array_merge( $rest, array( $first => $term->slug ) );

			$v->set_parent_id( $owner );
			$v->set_attributes( $pick );
			$v->set_regular_price( (string) ( $price + ( $at * 20 ) ) );

			if ( $sale && 0 === $at ) {
				$v->set_sale_price( (string) round( $price * 0.75 ) );
			}

			$v->set_manage_stock( true );
			$v->set_stock_quantity( 2 === $at ? 0 : 10 );
			$v->set_stock_status( 2 === $at ? 'outofstock' : 'instock' );
			$v->save();

			update_post_meta( $v->get_id(), self::MARK, '1' );

			++$at;

			if ( $at >= 4 ) {
				break;
			}
		}

		\WC_Product_Variable::sync( $owner );
	}

	/* ------------------------------------------------- what it is made from */

	/**
	 * The shop's own departments, deepest first so a product lands in a
	 * real aisle rather than in the top-level heading.
	 *
	 * @return array<int,int>
	 */
	private static function cats(): array {
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
			)
		);

		if ( is_wp_error( $terms ) || ! $terms ) {
			return array();
		}

		$leaves = array();
		$all    = array();

		foreach ( $terms as $t ) {
			if ( 'uncategorized' === $t->slug ) {
				continue;
			}

			$all[] = (int) $t->term_id;

			if ( ! get_term_children( (int) $t->term_id, 'product_cat' ) ) {
				$leaves[] = (int) $t->term_id;
			}
		}

		return $leaves ? $leaves : $all;
	}

	/**
	 * The attributes the shop said its products vary by, with values to
	 * vary them with. The questionnaire asks for the names only, so the
	 * values are ours — recognisable as ours, and easy to replace.
	 *
	 * @return array<string,array<int,string>>
	 */
	private static function vary(): array {
		if ( ! function_exists( 'wc_get_attribute_taxonomies' ) ) {
			return array();
		}

		$known = array(
			'swatch' => array( __( 'Black', 'oc-theme' ), __( 'White', 'oc-theme' ), __( 'Beige', 'oc-theme' ), __( 'Navy', 'oc-theme' ) ),
			'size'   => array( 'S', 'M', 'L', 'XL' ),
			'plain'  => array( __( 'Option one', 'oc-theme' ), __( 'Option two', 'oc-theme' ), __( 'Option three', 'oc-theme' ) ),
		);

		$out = array();

		foreach ( wc_get_attribute_taxonomies() as $a ) {
			$tax = wc_attribute_taxonomy_name( $a->attribute_name );

			if ( ! taxonomy_exists( $tax ) ) {
				continue;
			}

			$label = mb_strtolower( (string) $a->attribute_label );
			$kind  = 'plain';

			if ( 'color' === $a->attribute_type || false !== mb_strpos( $label, (string) mb_strtolower( _x( 'Colour', 'product attribute', 'oc-theme' ) ) ) ) {
				$kind = 'swatch';
			} elseif ( false !== mb_strpos( $label, (string) mb_strtolower( _x( 'Size', 'product attribute', 'oc-theme' ) ) ) ) {
				$kind = 'size';
			}

			$out[ $tax ] = $known[ $kind ];

			if ( count( $out ) >= 2 ) {
				break;
			}
		}

		return $out;
	}

	/**
	 * Terms for one attribute, made if they are not there.
	 *
	 * @param string            $tax    Attribute taxonomy.
	 * @param array<int,string> $values The words.
	 * @return array<int,int>
	 */
	private static function terms( string $tax, array $values ): array {
		$ids = array();

		foreach ( $values as $one ) {
			$term = get_term_by( 'name', $one, $tax );

			if ( ! $term instanceof \WP_Term ) {
				$new = wp_insert_term( $one, $tax );

				if ( is_wp_error( $new ) ) {
					continue;
				}

				$ids[] = (int) $new['term_id'];

				continue;
			}

			$ids[] = (int) $term->term_id;
		}

		return $ids;
	}

	/* ----------------------------------------------------------- the words */

	/**
	 * A name that cannot be mistaken for a real product.
	 *
	 * @param int $n Which one.
	 */
	private static function name( int $n ): string {
		/* translators: %d: the number of the test product. */
		return sprintf( __( 'Test product %d', 'oc-theme' ), $n + 1 );
	}

	/**
	 * A price with some spread to it, so the filter has something to filter.
	 *
	 * @param int $n Which one.
	 */
	private static function price( int $n ): int {
		$steps = array( 39, 89, 129, 199, 249, 349, 499, 649, 799, 899, 1290, 1790 );

		return (int) $steps[ $n % count( $steps ) ];
	}

	/**
	 * The description, which says plainly what it is.
	 *
	 * @param string $name The product's name.
	 */
	private static function words( string $name ): string {
		return sprintf(
			/* translators: %s: the product's name. */
			__( '%s is here to try the shop with, not to sell. It stands in for a real product so the page, the pictures, the filters and the checkout can all be seen working before the real catalogue arrives. Delete it from the questionnaire screen when you are done.', 'oc-theme' ),
			$name
		);
	}

	/**
	 * The one-liner under the title.
	 */
	private static function blurb(): string {
		return __( 'A stand-in product, for testing the shop.', 'oc-theme' );
	}

	/* --------------------------------------------------------- the picture */

	/**
	 * A picture that is obviously not a photograph.
	 *
	 * Drawn here rather than fetched: nothing is downloaded, nothing
	 * belongs to anybody, and a grey card with the product's name on it
	 * says "this is a test" at a glance.
	 *
	 * @param string $name The product's name.
	 * @param int    $n    Which one, for the shade.
	 */
	private static function picture( string $name, int $n ): int {
		$tones = array( '#e8e8ec', '#e2e6ea', '#eae6e2', '#e6eae6', '#ebe8f0', '#efe9e4' );
		$tone  = $tones[ $n % count( $tones ) ];

		$svg = '<svg xmlns="http://www.w3.org/2000/svg" width="1000" height="1000" viewBox="0 0 1000 1000">'
			. '<rect width="1000" height="1000" fill="' . esc_attr( $tone ) . '"/>'
			. '<path d="M300 620l120-150 90 110 70-80 120 160z" fill="#ffffff" opacity=".55"/>'
			. '<circle cx="640" cy="390" r="46" fill="#ffffff" opacity=".55"/>'
			. '<text x="500" y="790" text-anchor="middle" font-family="Arial, Helvetica, sans-serif" font-size="46" fill="#9aa0a6">'
			. esc_html( $name )
			. '</text></svg>';

		$put = wp_upload_bits( 'oc-demo-' . ( $n + 1 ) . '-' . wp_rand( 1000, 9999 ) . '.svg', null, $svg );

		if ( ! empty( $put['error'] ) || empty( $put['file'] ) ) {
			return 0;
		}

		$id = wp_insert_attachment(
			array(
				'post_mime_type' => 'image/svg+xml',
				'post_title'     => $name,
				'post_content'   => '',
				'post_status'    => 'inherit',
			),
			(string) $put['file']
		);

		// wp_insert_attachment() without the error flag answers 0, not an
		// error object.
		if ( ! $id ) {
			return 0;
		}

		// WordPress works out the size of a raster file for itself and
		// cannot for this one. Without it the theme has no shape to reserve
		// and the card jumps as the picture arrives.
		wp_update_attachment_metadata(
			(int) $id,
			array(
				'width'  => 1000,
				'height' => 1000,
				'file'   => _wp_relative_upload_path( (string) $put['file'] ),
				'sizes'  => array(),
			)
		);

		update_post_meta( (int) $id, self::MARK, '1' );
		update_post_meta( (int) $id, '_wp_attachment_image_alt', $name );

		return (int) $id;
	}

	/**
	 * The pictures that belong to one test product.
	 *
	 * @param int $id Product id.
	 * @return array<int,int>
	 */
	private static function pictures_of( int $id ): array {
		$out   = array();
		$thumb = (int) get_post_thumbnail_id( $id );

		if ( $thumb && get_post_meta( $thumb, self::MARK, true ) ) {
			$out[] = $thumb;
		}

		return $out;
	}

	/* ----------------------------------------------------------- the guard */

	/**
	 * Has anybody actually ordered this?
	 *
	 * @param int $id Product id.
	 */
	private static function was_ordered( int $id ): bool {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- no API asks this question, and it runs once per product on an admin action.
		$found = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT meta_id FROM {$wpdb->prefix}woocommerce_order_itemmeta WHERE meta_key IN ( '_product_id', '_variation_id' ) AND meta_value = %d LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the prefix is WordPress's own.
				$id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery

		return (bool) $found;
	}
}
