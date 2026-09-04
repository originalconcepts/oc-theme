<?php
/**
 * Building a feed: one batch of products at a time, into a file that is
 * only put in place once it is complete.
 *
 * @package OC_Theme
 */

declare( strict_types = 1 );

namespace OC\Theme\Feeds;

defined( 'ABSPATH' ) || exit;

/**
 * The writer for all three networks.
 */
final class Build {

	/**
	 * Begin a run: work out what to include, and open a part file.
	 *
	 * The feed already in place is left exactly where it is until this run
	 * finishes. A half-written feed is worse than an hour-old one.
	 *
	 * @param string $key Feed key.
	 */
	public static function start( string $key ): void {
		$feed = Feeds::get( $key );

		if ( null === $feed ) {
			return;
		}

		$ids = self::ids( $feed );

		set_transient( 'oc_feed_ids_' . $key, $ids, DAY_IN_SECONDS );

		$part = Feeds::path( $key, (string) $feed['format'] ) . '.part';
		$open = self::head( $feed );

		file_put_contents( $part, $open ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- writing this plugin's own feed file.

		$feed['state']   = 'running';
		$feed['cursor']  = 0;
		$feed['started'] = time();
		$feed['beat']    = time();
		$feed['items']   = 0;
		$feed['error']   = '';

		Feeds::put( $key, $feed );
	}

	/**
	 * One batch. Called again and again until the run is done.
	 *
	 * @param string $key Feed key.
	 */
	public static function step( string $key ): void {
		$feed = Feeds::get( $key );

		if ( null === $feed || 'running' !== $feed['state'] ) {
			return;
		}

		$ids = get_transient( 'oc_feed_ids_' . $key );

		if ( ! is_array( $ids ) ) {
			// The list expired mid-run; begin again rather than write a
			// feed that is missing whatever the shop has since added.
			self::start( $key );

			return;
		}

		$began = microtime( true );
		$part  = Feeds::path( $key, (string) $feed['format'] ) . '.part';
		$at    = (int) $feed['cursor'];
		$stop  = min( $at + Feeds::BATCH, count( $ids ) );
		$rows  = '';
		$made  = 0;

		for ( ; $at < $stop; $at++ ) {
			$product = wc_get_product( (int) $ids[ $at ] );

			if ( ! $product instanceof \WC_Product ) {
				continue;
			}

			foreach ( self::items_of( $product, $feed ) as $item ) {
				$rows .= self::row( $item, $feed );
				++$made;
			}
		}

		if ( '' !== $rows ) {
			file_put_contents( $part, $rows, FILE_APPEND ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- appending to this plugin's own feed file.
		}

		$feed['cursor'] = $at;
		$feed['items']  = (int) $feed['items'] + $made;
		$feed['beat']   = time();
		$feed['ms']     = (int) $feed['ms'] + (int) round( ( microtime( true ) - $began ) * 1000 );

		if ( $at >= count( $ids ) ) {
			file_put_contents( $part, self::foot( $feed ), FILE_APPEND ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- closing this plugin's own feed file.

			$final = Feeds::path( $key, (string) $feed['format'] );

			// The swap is the last thing that happens, so the address never
			// answers with a feed that is only half written.
			rename( $part, $final ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- moving this plugin's own file into place.

			$feed['state'] = 'ready';
			$feed['made']  = time();

			delete_transient( 'oc_feed_ids_' . $key );
		}

		Feeds::put( $key, $feed );
	}

	/*
	 * ----------------------------------------------- what each network wants
	 */

	/**
	 * The opening of the file.
	 *
	 * @param array<string,mixed> $feed Feed.
	 */
	private static function head( array $feed ): string {
		$shop = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		$when = gmdate( 'D, d M Y H:i:s' ) . ' GMT';

		if ( 'csv' === $feed['format'] ) {
			return "\xEF\xBB\xBF" . implode( ',', self::columns( $feed ) ) . "\n";
		}

		if ( 'zap' === $feed['target'] ) {
			return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
				. '<STORE URL="' . esc_attr( home_url( '/' ) ) . '" DATE="' . esc_attr( wp_date( 'd/m/Y' ) ) . '" TIME="' . esc_attr( wp_date( 'H:i:s' ) ) . '">' . "\n"
				. '<PRODUCTS>' . "\n";
		}

		// Meta reads the same RSS Google does, and understands the g:
		// namespace, so one writer serves both.
		return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
			. '<rss version="2.0" xmlns:g="http://base.google.com/ns/1.0">' . "\n"
			. '<channel>' . "\n"
			. '<title>' . self::x( $shop ) . '</title>' . "\n"
			. '<link>' . self::x( home_url( '/' ) ) . '</link>' . "\n"
			. '<description>' . self::x( sprintf( /* translators: %s: shop name. */ __( 'Product catalogue for %s', 'oc-theme' ), $shop ) ) . '</description>' . "\n"
			. '<lastBuildDate>' . self::x( $when ) . '</lastBuildDate>' . "\n";
	}

	/**
	 * The close of the file.
	 *
	 * @param array<string,mixed> $feed Feed.
	 */
	private static function foot( array $feed ): string {
		if ( 'csv' === $feed['format'] ) {
			return '';
		}

		if ( 'zap' === $feed['target'] ) {
			return '</PRODUCTS>' . "\n" . '</STORE>' . "\n";
		}

		return '</channel>' . "\n" . '</rss>' . "\n";
	}

	/**
	 * The columns a CSV carries, in order.
	 *
	 * @param array<string,mixed> $feed Feed.
	 * @return array<int,string>
	 */
	private static function columns( array $feed ): array {
		if ( 'google' === $feed['target'] ) {
			return array(
				'id', 'title', 'description', 'link', 'image_link', 'additional_image_link', 'availability',
				'price', 'sale_price', 'sale_price_effective_date', 'brand', 'gtin', 'mpn', 'identifier_exists',
				'condition', 'item_group_id', 'color', 'size', 'google_product_category', 'product_type', 'shipping_weight',
			);
		}

		return array(
			'id', 'title', 'description', 'link', 'image_link', 'additional_image_link', 'availability',
			'price', 'sale_price', 'sale_price_effective_date', 'brand', 'gtin', 'mpn', 'condition',
			'item_group_id', 'color', 'size', 'google_product_category', 'product_type', 'quantity_to_sell_on_facebook',
		);
	}

	/**
	 * One item, in the shape its network reads.
	 *
	 * @param array<string,mixed> $it   Item fields.
	 * @param array<string,mixed> $feed Feed.
	 */
	private static function row( array $it, array $feed ): string {
		if ( 'zap' === $feed['target'] ) {
			return self::zap_row( $it, $feed );
		}

		$google = 'google' === $feed['target'];
		$money  = static function ( float $n ): string {
			return number_format( $n, 2, '.', '' ) . ' ' . get_woocommerce_currency();
		};

		$values = array(
			'id'                        => $it['sku'] !== '' ? $it['sku'] . '-' . $it['id'] : $it['id'],
			'title'                     => $it['title'],
			'description'               => $it['desc'],
			'link'                      => $it['link'],
			'image_link'                => $it['image'],
			'additional_image_link'     => implode( ',', $it['gallery'] ),
			// The one value that decides whether a shop advertises things it
			// cannot sell. Google spells it with an underscore, Meta with a
			// space, and a feed that uses the wrong one is simply rejected.
			'availability'              => $google
				? ( $it['stock'] ? 'in_stock' : 'out_of_stock' )
				: ( $it['stock'] ? 'in stock' : 'out of stock' ),
			'price'                     => $money( (float) $it['price'] ),
			'sale_price'                => $it['sale'] > 0 ? $money( (float) $it['sale'] ) : '',
			'sale_price_effective_date' => self::window( (string) $it['sale_from'], (string) $it['sale_to'], (float) $it['sale'] ),
			'brand'                     => $it['brand'],
			'gtin'                      => $it['gtin'],
			'mpn'                       => $it['mpn'],
			'condition'                 => (string) $feed['condition'],
			'item_group_id'             => $it['id'] === $it['group'] ? '' : $it['group'],
			'color'                     => $it['colour'],
			'size'                      => $it['size'],
			'google_product_category'   => (string) $feed['gcat'],
			'product_type'              => $it['cats'][0] ?? '',
		);

		if ( $google ) {
			// Without this, a shop with no barcodes has every line rejected
			// for a missing identifier. Saying so plainly is the fix.
			$values['identifier_exists'] = ( '' === $it['gtin'] && '' === $it['brand'] ) ? 'no' : 'yes';
			$values['shipping_weight']   = '' === $it['weight'] ? '' : $it['weight'] . ' ' . get_option( 'woocommerce_weight_unit', 'kg' );
		} else {
			$values['quantity_to_sell_on_facebook'] = $it['qty'] > 0 ? (string) $it['qty'] : ( $it['stock'] ? '10' : '0' );
		}

		if ( 'csv' === $feed['format'] ) {
			$cells = array();

			foreach ( self::columns( $feed ) as $column ) {
				$cells[] = '"' . str_replace( '"', '""', (string) ( $values[ $column ] ?? '' ) ) . '"';
			}

			return implode( ',', $cells ) . "\n";
		}

		$out = '<item>' . "\n";

		foreach ( $values as $name => $value ) {
			$value = (string) $value;

			if ( '' === $value ) {
				continue;
			}

			$out .= "\t" . '<g:' . $name . '>' . self::x( $value ) . '</g:' . $name . '>' . "\n";
		}

		return $out . '</item>' . "\n";
	}

	/**
	 * One item, in Zap's own shape.
	 *
	 * @param array<string,mixed> $it   Item fields.
	 * @param array<string,mixed> $feed Feed.
	 */
	private static function zap_row( array $it, array $feed ): string {
		$price = (float) ( $it['sale'] > 0 ? $it['sale'] : $it['price'] );

		$fields = array(
			'PRODUCT_URL'    => $it['link'],
			'PRODUCT_NAME'   => $it['title'],
			'DETAILS'        => $it['desc'],
			'CATALOG_NUMBER' => '' !== $it['sku'] ? $it['sku'] : $it['id'],
			'PRODUCTCODE'    => $it['id'],
			'CURRENCY'       => get_woocommerce_currency(),
			'PRICE'          => number_format( $price, 2, '.', '' ),
			'IMAGE'          => $it['image'],
			'MODEL'          => $it['size'] !== '' ? $it['size'] : $it['colour'],
			'DELIVERY_TIME'  => (string) $feed['delivery'],
			'MANUFACTURER'   => $it['brand'],
			'WARRANTY'       => '1',
			'TAX'            => '1',
			'SHIPMENT_COST'  => '' === $it['ship'] ? '0' : $it['ship'],
		);

		$out = "\t" . '<PRODUCT>' . "\n";

		foreach ( $fields as $name => $value ) {
			$out .= "\t\t" . '<' . $name . '><![CDATA[' . str_replace( ']]>', ']]&gt;', (string) $value ) . ']]></' . $name . '>' . "\n";
		}

		return $out . "\t" . '</PRODUCT>' . "\n";
	}

	/**
	 * A sale's window, in the format both networks read.
	 *
	 * Without an end date a network keeps showing the sale price long after
	 * the shop has stopped honouring it, which is the complaint that gets a
	 * catalogue suspended.
	 *
	 * @param string $from  Start.
	 * @param string $to    End.
	 * @param float  $sale  Sale price.
	 */
	private static function window( string $from, string $to, float $sale ): string {
		if ( $sale <= 0 || '' === $to ) {
			return '';
		}

		$start = '' === $from ? time() : strtotime( $from );
		$end   = strtotime( $to );

		if ( ! $start || ! $end ) {
			return '';
		}

		return gmdate( 'Y-m-d\TH:iP', (int) $start ) . '/' . gmdate( 'Y-m-d\TH:iP', (int) $end );
	}

	/**
	 * Text that is safe inside an XML element.
	 *
	 * @param string $text Text.
	 */
	private static function x( string $text ): string {
		return htmlspecialchars( $text, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * Which products belong in this feed.
	 *
	 * Only what is published and visible: a feed is an advertisement, and
	 * a draft or a hidden product has no business being advertised. The
	 * query is by ID alone so that a catalogue of thirty thousand does not
	 * have to fit in memory at once.
	 *
	 * @param array<string,mixed> $feed Feed.
	 * @return array<int,int>
	 */
	private static function ids( array $feed ): array {
		$args = array(
			'status'     => 'publish',
			'limit'      => -1,
			'return'     => 'ids',
			'orderby'    => 'ID',
			'order'      => 'ASC',
			'visibility' => 'visible',
		);

		if ( ! empty( $feed['in_stock'] ) ) {
			$args['stock_status'] = 'instock';
		}

		if ( ! empty( $feed['cats'] ) ) {
			$slugs = array();

			foreach ( (array) $feed['cats'] as $id ) {
				$term = get_term( (int) $id, 'product_cat' );

				if ( $term instanceof \WP_Term ) {
					$slugs[] = $term->slug;
				}
			}

			if ( ! empty( $slugs ) ) {
				$args['category'] = $slugs;
			}
		}

		$ids = wc_get_products( $args );
		$out = array();
		$no  = array_map( 'absint', (array) $feed['exclude'] );

		foreach ( (array) $ids as $id ) {
			$id = absint( $id );

			if ( $id > 0 && ! in_array( $id, $no, true ) ) {
				$out[] = $id;
			}
		}

		return $out;
	}

	/**
	 * One product, flattened into the items a network wants.
	 *
	 * A variable product is sent as its variations, each one its own item
	 * tied to the parent by item_group_id — that is how a network knows
	 * that a red shirt and a blue shirt are the same shirt, and it is what
	 * lets a shopper land on the size that was actually advertised.
	 *
	 * @param \WC_Product         $product Product.
	 * @param array<string,mixed> $feed    Feed.
	 * @return array<int,array<string,mixed>>
	 */
	private static function items_of( \WC_Product $product, array $feed ): array {
		if ( ! $product->is_type( 'variable' ) || empty( $feed['variants'] ) ) {
			return array( self::fields( $product, $product, $feed ) );
		}

		$out = array();

		foreach ( $product->get_children() as $child ) {
			$variation = wc_get_product( $child );

			if ( ! $variation instanceof \WC_Product ) {
				continue;
			}

			if ( ! empty( $feed['in_stock'] ) && ! $variation->is_in_stock() ) {
				continue;
			}

			$out[] = self::fields( $variation, $product, $feed );
		}

		// A variable product whose variations are all gone still deserves a
		// line rather than silently vanishing from the catalogue.
		return empty( $out ) ? array( self::fields( $product, $product, $feed ) ) : $out;
	}

	/**
	 * Everything one item can say about itself, before a network decides
	 * what it calls each of those things.
	 *
	 * @param \WC_Product         $item   The thing being sold (a variation, or the product).
	 * @param \WC_Product         $parent Its product.
	 * @param array<string,mixed> $feed   Feed.
	 * @return array<string,mixed>
	 */
	private static function fields( \WC_Product $item, \WC_Product $parent, array $feed ): array {
		$link = (string) $item->get_permalink();

		if ( ! empty( $feed['utm'] ) ) {
			$link = add_query_arg(
				array(
					'utm_source'   => (string) $feed['target'],
					'utm_medium'   => 'cpc',
					'utm_campaign' => 'catalog',
				),
				$link
			);
		}

		$image = wp_get_attachment_url( (int) ( $item->get_image_id() ?: $parent->get_image_id() ) );
		$more  = array();

		foreach ( array_slice( $parent->get_gallery_image_ids(), 0, 10 ) as $gid ) {
			$url = wp_get_attachment_url( (int) $gid );

			if ( is_string( $url ) && '' !== $url ) {
				$more[] = $url;
			}
		}

		$text = 'long' === $feed['desc']
			? ( $parent->get_description() ?: $parent->get_short_description() )
			: ( $parent->get_short_description() ?: $parent->get_description() );

		$text = trim( (string) wp_strip_all_tags( strip_shortcodes( (string) $text ) ) );
		$text = (string) preg_replace( '/\s+/u', ' ', $text );

		if ( '' === $text ) {
			$text = (string) $parent->get_name();
		}

		$regular = (float) $item->get_regular_price();
		$now     = (float) $item->get_price();
		$sale    = $now > 0 && $regular > $now ? $now : 0.0;

		return array(
			'id'        => (string) $item->get_id(),
			'group'     => (string) $parent->get_id(),
			'sku'       => (string) $item->get_sku(),
			'title'     => self::cut( (string) ( $item->get_id() === $parent->get_id() ? $parent->get_name() : $item->get_name() ), 150 ),
			'desc'      => self::cut( $text, 4900 ),
			'link'      => $link,
			'image'     => is_string( $image ) ? $image : '',
			'gallery'   => $more,
			'price'     => $regular > 0 ? $regular : $now,
			'sale'      => $sale,
			'sale_from' => (string) $item->get_date_on_sale_from(),
			'sale_to'   => (string) $item->get_date_on_sale_to(),
			'stock'     => $item->is_in_stock(),
			'qty'       => $item->managing_stock() ? (int) $item->get_stock_quantity() : 0,
			'brand'     => self::brand( $parent, $feed ),
			'gtin'      => self::gtin( $item, $parent ),
			'mpn'       => (string) $item->get_sku(),
			'cats'      => self::cats( $parent ),
			'colour'    => self::attribute( $item, $parent, array( 'color', 'colour', 'צבע' ) ),
			'size'      => self::attribute( $item, $parent, array( 'size', 'מידה' ) ),
			'weight'    => (string) $item->get_weight(),
			'ship'      => empty( $feed['ship'] ) ? '' : self::ship( $item ),
		);
	}

	/**
	 * A product's brand, from whichever plugin the shop happens to use.
	 *
	 * @param \WC_Product         $product Product.
	 * @param array<string,mixed> $feed    Feed.
	 */
	private static function brand( \WC_Product $product, array $feed ): string {
		foreach ( array( 'product_brand', 'pwb-brand', 'yith_product_brand' ) as $taxonomy ) {
			if ( ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}

			$terms = wp_get_post_terms( $product->get_id(), $taxonomy, array( 'fields' => 'names' ) );

			if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
				return (string) $terms[0];
			}
		}

		return (string) $feed['brand'];
	}

	/**
	 * The barcode, where the shop keeps one.
	 *
	 * @param \WC_Product $item   Item.
	 * @param \WC_Product $parent Its product.
	 */
	private static function gtin( \WC_Product $item, \WC_Product $parent ): string {
		foreach ( array( $item, $parent ) as $one ) {
			if ( method_exists( $one, 'get_global_unique_id' ) ) {
				$id = trim( (string) $one->get_global_unique_id() );

				if ( '' !== $id ) {
					return $id;
				}
			}

			foreach ( array( '_gtin', '_ean', '_barcode', '_wpm_gtin_code', 'hwp_product_gtin' ) as $meta ) {
				$id = trim( (string) $one->get_meta( $meta ) );

				if ( '' !== $id ) {
					return $id;
				}
			}
		}

		return '';
	}

	/**
	 * The product's categories, deepest path first.
	 *
	 * @param \WC_Product $product Product.
	 * @return array<int,string>
	 */
	private static function cats( \WC_Product $product ): array {
		$terms = wp_get_post_terms( $product->get_id(), 'product_cat' );

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return array();
		}

		$out = array();

		foreach ( $terms as $term ) {
			$path = array( $term->name );
			$up   = (int) $term->parent;

			while ( $up > 0 ) {
				$mum = get_term( $up, 'product_cat' );

				if ( ! $mum instanceof \WP_Term ) {
					break;
				}

				array_unshift( $path, $mum->name );
				$up = (int) $mum->parent;
			}

			$out[] = implode( ' > ', $path );
		}

		return $out;
	}

	/**
	 * One attribute by any of the names a shop might have given it.
	 *
	 * @param \WC_Product    $item   Item.
	 * @param \WC_Product    $parent Its product.
	 * @param array<int,string> $names Candidate names.
	 */
	private static function attribute( \WC_Product $item, \WC_Product $parent, array $names ): string {
		foreach ( array( $item, $parent ) as $one ) {
			foreach ( $names as $name ) {
				foreach ( array( 'pa_' . $name, $name ) as $slug ) {
					$value = $one->get_attribute( $slug );

					if ( is_string( $value ) && '' !== trim( $value ) ) {
						return trim( explode( ',', $value )[0] );
					}
				}
			}
		}

		return '';
	}

	/**
	 * What this item costs to send, when the shop can say.
	 *
	 * @param \WC_Product $item Item.
	 */
	private static function ship( \WC_Product $item ): string {
		if ( ! class_exists( '\OC\Theme\Shipping' ) || ! method_exists( '\OC\Theme\Shipping', 'product_quote' ) ) {
			return '';
		}

		$quote = \OC\Theme\Shipping::product_quote( $item );

		return isset( $quote['cost'] ) ? (string) (float) $quote['cost'] : '';
	}

	/**
	 * Trim to a length a network will accept, on a word where it can.
	 *
	 * @param string $text Text.
	 * @param int    $max  Longest allowed.
	 */
	private static function cut( string $text, int $max ): string {
		$text = trim( $text );

		if ( mb_strlen( $text ) <= $max ) {
			return $text;
		}

		return rtrim( mb_substr( $text, 0, $max - 1 ) ) . '…';
	}
}
