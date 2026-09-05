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
	 * @param string $key   Feed key.
	 * @param bool   $force Take the lock from whoever holds it.
	 */
	public static function start( string $key, bool $force = false ): void {
		$feed = Feeds::get( $key );

		if ( null === $feed ) {
			return;
		}

		// Asked for by hand, a rebuild takes the lock away from whatever is
		// holding it. That is the way out of a run that wedged: the button
		// on the screen always works.
		if ( $force ) {
			self::unlock( $key );
		}

		// Wait for a batch in flight to finish before pulling the ground out
		// from under it.
		if ( ! self::lock( $key ) ) {
			return;
		}

		$ids = self::ids( $feed );
		$run = (string) time() . wp_generate_password( 4, false, false );

		set_transient( 'oc_feed_ids_' . $key, $ids, DAY_IN_SECONDS );
		self::sweep_parts( $key );

		$feed['run']     = $run;
		$feed['state']   = 'running';
		$feed['skipped'] = 0;
		$feed['cursor']  = 0;
		$feed['started'] = time();
		$feed['beat']    = time();
		$feed['items']   = 0;
		$feed['error']   = '';

		Feeds::put( $key, $feed );
		self::unlock( $key );
	}

	/**
	 * Where a run's batches are collected before they become a feed.
	 *
	 * @param string $key Feed key.
	 * @param string $run The run.
	 */
	private static function parts_dir( string $key, string $run ): string {
		$dir = wp_get_upload_dir()['basedir'] . '/oc-feeds/parts-' . sanitize_key( $key ) . '-' . sanitize_key( $run );

		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		return $dir;
	}

	/**
	 * Clear away every run of this feed, finished or abandoned.
	 *
	 * Each run keeps its batches in a folder of its own, so a worker still
	 * finishing the previous run writes into a folder nobody will read.
	 * Sharing one folder meant a batch that landed a moment after a rebuild
	 * began was assembled into it, and the same products appeared twice.
	 *
	 * @param string $key Feed key.
	 */
	private static function sweep_parts( string $key ): void {
		$base = wp_get_upload_dir()['basedir'] . '/oc-feeds/parts-' . sanitize_key( $key ) . '-*';

		foreach ( (array) glob( $base, GLOB_ONLYDIR ) as $dir ) {
			self::wipe( (string) $dir );
		}
	}

	/**
	 * Remove one run's folder.
	 *
	 * @param string $dir Directory.
	 */
	private static function wipe( string $dir ): void {
		foreach ( (array) glob( $dir . '/*.txt' ) as $file ) {
			wp_delete_file( (string) $file );
		}

		if ( is_dir( $dir ) ) {
			rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- a folder this class made for one run of one feed.
		}
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

		// The screen drives batches while it is open and the schedule drives
		// them in the background, and two workers on one feed ruin it either
		// way: they repeat products, or one of them decides the run is over
		// and clears away batches the other has not written yet.
		if ( ! self::lock( $key ) ) {
			return;
		}

		$ids = get_transient( 'oc_feed_ids_' . $key );

		if ( ! is_array( $ids ) ) {
			// The list expired mid-run; begin again rather than write a
			// feed that is missing whatever the shop has since added.
			self::unlock( $key );
			self::start( $key );

			return;
		}

		$run     = (string) ( $feed['run'] ?? '' );
		$began   = microtime( true );
		$at      = (int) $feed['cursor'];
		$stop    = min( $at + Feeds::BATCH, count( $ids ) );
		$from    = $at;
		$rows    = '';
		$made    = 0;
		$skipped = 0;

		for ( ; $at < $stop; $at++ ) {
			$product = wc_get_product( (int) $ids[ $at ] );

			if ( ! $product instanceof \WC_Product ) {
				continue;
			}

			foreach ( self::items_of( $product, $feed ) as $item ) {
				// A line with no picture or no price is rejected wherever it
				// is sent, and a feed full of rejections is what gets a whole
				// catalogue held for review. Leave them out and say so.
				if ( '' === (string) $item['image'] || (float) $item['price'] <= 0 ) {
					++$skipped;

					continue;
				}

				$rows .= self::row( $item, $feed );
				++$made;
			}
		}

		file_put_contents( self::parts_dir( $key, $run ) . '/' . str_pad( (string) $from, 9, '0', STR_PAD_LEFT ) . '.txt', $rows ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- writing this plugin's own batch file.

		// A rebuild may have begun while this batch was working. Its numbers
		// belong to a run nobody is waiting for any more.
		$now = Feeds::get( $key );

		if ( null === $now || (string) ( $now['run'] ?? '' ) !== $run ) {
			self::unlock( $key );

			return;
		}

		$feed = $now;

		$feed['cursor']  = $at;
		$feed['items']   = (int) $feed['items'] + $made;
		$feed['skipped'] = (int) ( $feed['skipped'] ?? 0 ) + $skipped;
		$feed['beat']    = time();
		$feed['ms']      = (int) $feed['ms'] + (int) round( ( microtime( true ) - $began ) * 1000 );

		if ( $at >= count( $ids ) ) {
			self::assemble( $key, $feed, $run );

			$feed['state'] = 'ready';
			$feed['made']  = time();
			$feed['items'] = self::count_items( $key, $feed );

			delete_transient( 'oc_feed_ids_' . $key );
		}

		Feeds::put( $key, $feed );
		self::unlock( $key );
	}

	/**
	 * Put the batches together, in order, and swap the result into place.
	 *
	 * The address keeps answering with the previous feed right up to the
	 * rename, so nobody is ever handed a half-written catalogue.
	 *
	 * @param string              $key  Feed key.
	 * @param array<string,mixed> $feed Feed.
	 * @param string              $run  The run whose batches to use.
	 */
	private static function assemble( string $key, array $feed, string $run ): void {
		$final = Feeds::path( $key, (string) $feed['format'] );
		$part  = $final . '.part';
		$files = (array) glob( self::parts_dir( $key, $run ) . '/*.txt' );

		// A run that found nothing replaces a working feed with an empty one,
		// and a network reading an empty catalogue takes the whole shop down
		// from its listings. A shop really can empty — but when it does, the
		// feed that was there yesterday is the safer thing to keep until
		// somebody has looked.
		if ( 0 === (int) $feed['items'] && (int) $feed['made'] > 0 && file_exists( $final ) ) {
			self::sweep_parts( $key );

			return;
		}

		sort( $files, SORT_STRING );

		file_put_contents( $part, self::head( $feed ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- this plugin's own feed file.

		foreach ( $files as $file ) {
			$rows = (string) file_get_contents( (string) $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- a local batch file this class wrote a moment ago, not a URL.

			if ( '' !== $rows ) {
				file_put_contents( $part, $rows, FILE_APPEND ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- this plugin's own feed file.
			}
		}

		file_put_contents( $part, self::foot( $feed ), FILE_APPEND ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- this plugin's own feed file.
		rename( $part, $final ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- moving this plugin's own file into place.

		self::sweep_parts( $key );
	}

	/**
	 * Throw away everything a feed left on disk.
	 *
	 * @param string $key Feed key.
	 */
	public static function forget( string $key ): void {
		self::sweep_parts( $key );
		self::unlock( $key );
		delete_transient( 'oc_feed_ids_' . $key );
	}

	/**
	 * How many lines the finished feed really holds.
	 *
	 * Counted from the file rather than tallied while building, so the
	 * number on the screen is the number a network will read.
	 *
	 * @param string              $key  Feed key.
	 * @param array<string,mixed> $feed Feed.
	 */
	private static function count_items( string $key, array $feed ): int {
		$file = Feeds::path( $key, (string) $feed['format'] );

		if ( ! file_exists( $file ) ) {
			return 0;
		}

		$mark   = 'csv' === $feed['format'] ? "\n" : ( 'zap' === $feed['target'] ? '<PRODUCT>' : '<item>' );
		$count  = 0;
		$handle = fopen( $file, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- reading this plugin's own feed file in a stream.

		if ( false === $handle ) {
			return 0;
		}

		while ( ! feof( $handle ) ) {
			$count += substr_count( (string) fread( $handle, 1048576 ), $mark ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- reading this plugin's own feed file in a stream.
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closing this plugin's own file handle.

		return 'csv' === $feed['format'] ? max( 0, $count - 1 ) : $count;
	}

	/**
	 * Take the build lock, or say that somebody else holds it.
	 *
	 * The options table's unique key on option_name is the only thing in
	 * WordPress that can settle this: INSERT IGNORE either creates the row
	 * or changes nothing, and the row count says which happened. The usual
	 * candidates do not work — add_option() ends in ON DUPLICATE KEY UPDATE
	 * and so succeeds for the second caller too, and a get-then-set on a
	 * transient leaves a window both callers walk through.
	 *
	 * @param string $key Feed key.
	 */
	private static function lock( string $key ): bool {
		global $wpdb;

		$name = 'oc_feed_lock_' . $key;

		$held = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a lock; the whole point is to miss the cache.

		if ( null !== $held ) {
			if ( time() - (int) $held < 5 * MINUTE_IN_SECONDS ) {
				return false;
			}

			// Whoever held it is long gone.
			$wpdb->delete( $wpdb->options, array( 'option_name' => $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- as above.
			wp_cache_delete( $name, 'options' );
		}

		$made = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- as above.
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
				$name,
				(string) time()
			)
		);

		wp_cache_delete( $name, 'options' );

		return 1 === (int) $made;
	}

	/**
	 * Let the next worker in.
	 *
	 * @param string $key Feed key.
	 */
	private static function unlock( string $key ): void {
		global $wpdb;

		$name = 'oc_feed_lock_' . $key;

		$wpdb->delete( $wpdb->options, array( 'option_name' => $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- see lock().
		wp_cache_delete( $name, 'options' );
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
				'id',
				'title',
				'description',
				'link',
				'image_link',
				'additional_image_link',
				'availability',
				'price',
				'sale_price',
				'sale_price_effective_date',
				'brand',
				'gtin',
				'mpn',
				'identifier_exists',
				'condition',
				'item_group_id',
				'color',
				'size',
				'google_product_category',
				'product_type',
				'shipping_weight',
				'custom_label_0',
				'custom_label_1',
				'custom_label_2',
				'custom_label_3',
			);
		}

		return array(
			'id',
			'title',
			'description',
			'link',
			'image_link',
			'additional_image_link',
			'availability',
			'price',
			'sale_price',
			'sale_price_effective_date',
			'brand',
			'gtin',
			'mpn',
			'condition',
			'item_group_id',
			'color',
			'size',
			'google_product_category',
			'product_type',
			'quantity_to_sell_on_facebook',
			'short_description',
			'custom_label_0',
			'custom_label_1',
			'custom_label_2',
			'custom_label_3',
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
			'additional_image_link'     => $it['gallery'],
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
			// The labels are what a campaign is split by later: the brand,
			// the aisle, and whether a thing is on offer. Filling them now
			// costs nothing and saves rebuilding the feed to get them.
			'custom_label_0'            => $it['sale'] > 0 ? 'sale' : 'regular',
			'custom_label_1'            => $it['stock'] ? 'in-stock' : 'out-of-stock',
			'custom_label_2'            => $it['brand'],
			'custom_label_3'            => $it['cats'][0] ?? '',
		);

		if ( $google ) {
			// Without this, a shop with no barcodes has every line rejected
			// for a missing identifier. Saying so plainly is the fix.
			$values['identifier_exists'] = ( '' === $it['gtin'] && '' === $it['brand'] ) ? 'no' : 'yes';
			$values['shipping_weight']   = '' === $it['weight'] ? '' : $it['weight'] . ' ' . get_option( 'woocommerce_weight_unit', 'kg' );
		} else {
			$values['quantity_to_sell_on_facebook'] = $it['qty'] > 0 ? (string) $it['qty'] : ( $it['stock'] ? '10' : '0' );
			$values['short_description']            = self::cut( (string) $it['brief'], 999 );
		}

		if ( 'csv' === $feed['format'] ) {
			$cells = array();

			foreach ( self::columns( $feed ) as $column ) {
				$cell    = $values[ $column ] ?? '';
				$cell    = is_array( $cell ) ? implode( ',', $cell ) : (string) $cell;
				$cells[] = '"' . str_replace( '"', '""', $cell ) . '"';
			}

			return implode( ',', $cells ) . "\n";
		}

		$out = '<item>' . "\n";

		foreach ( $values as $name => $value ) {
			// More than one picture means more than one element. Joining
			// them with commas is accepted by Meta and quietly ignored by
			// Google, which reads only the first.
			foreach ( is_array( $value ) ? $value : array( $value ) as $one ) {
				$one = (string) $one;

				if ( '' === $one ) {
					continue;
				}

				$out .= "\t" . '<g:' . $name . '>' . self::x( $one ) . '</g:' . $name . '>' . "\n";
			}
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

		// The visibility filter joins the product_visibility taxonomy, and a
		// product carrying more than one of those terms comes back once per
		// term. Left alone that is a feed with the same id in it twice,
		// which is the one fault a network rejects a whole catalogue for.
		return array_values( array_unique( $out ) );
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
	 * @param \WC_Product         $owner  Its product.
	 * @param array<string,mixed> $feed   Feed.
	 * @return array<string,mixed>
	 */
	private static function fields( \WC_Product $item, \WC_Product $owner, array $feed ): array {
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

		$picture = (int) $item->get_image_id();
		$picture = $picture > 0 ? $picture : (int) $owner->get_image_id();
		$image   = wp_get_attachment_url( $picture );

		$more = array();

		foreach ( array_slice( $owner->get_gallery_image_ids(), 0, 10 ) as $gid ) {
			$url = wp_get_attachment_url( (int) $gid );

			if ( is_string( $url ) && '' !== $url ) {
				$more[] = $url;
			}
		}

		$long  = trim( (string) $owner->get_description() );
		$brief = trim( (string) $owner->get_short_description() );

		if ( 'long' === $feed['desc'] ) {
			$text = '' !== $long ? $long : $brief;
		} else {
			$text = '' !== $brief ? $brief : $long;
		}

		$text = trim( (string) wp_strip_all_tags( strip_shortcodes( (string) $text ) ) );
		$text = (string) preg_replace( '/\s+/u', ' ', $text );

		if ( '' === $text ) {
			$text = (string) $owner->get_name();
		}

		/*
		 * The number a shopper sees, not the number in the database. Two
		 * things move between them, and getting either wrong is a price
		 * mismatch — the fault that has a network reject an item outright.
		 * One is tax: a shop that stores prices without VAT and shows them
		 * with it would otherwise feed a price nobody is charged. The other
		 * is a discount plugin — some change the price itself, which
		 * get_price() already reflects, and some only change what the page
		 * prints, which has to be asked for.
		 */
		$regular = (float) wc_get_price_to_display( $item, array( 'price' => $item->get_regular_price() ) );
		$now     = (float) wc_get_price_to_display( $item );

		if ( $regular <= 0 ) {
			$regular = $now;
		}

		$now  = self::discounted( $item, $now );
		$sale = $now > 0 && $regular > $now ? $now : 0.0;

		return array(
			'id'        => (string) $item->get_id(),
			'group'     => (string) $owner->get_id(),
			'sku'       => (string) $item->get_sku(),
			'title'     => self::cut( (string) ( $item->get_id() === $owner->get_id() ? $owner->get_name() : $item->get_name() ), 150 ),
			'desc'      => self::cut( $text, 4900 ),
			'brief'     => '' !== $brief ? $brief : $text,
			'link'      => $link,
			'image'     => is_string( $image ) ? $image : '',
			'gallery'   => $more,
			'price'     => $regular > 0 ? $regular : $now,
			'sale'      => $sale,
			'sale_from' => (string) $item->get_date_on_sale_from(),
			'sale_to'   => (string) $item->get_date_on_sale_to(),
			'stock'     => $item->is_in_stock(),
			'qty'       => $item->managing_stock() ? (int) $item->get_stock_quantity() : 0,
			'brand'     => self::brand( $owner, $feed ),
			'gtin'      => self::gtin( $item, $owner ),
			'mpn'       => (string) $item->get_sku(),
			'cats'      => self::cats( $owner ),
			'colour'    => self::attribute( $item, $owner, array( 'color', 'colour', 'צבע' ) ),
			'size'      => self::attribute( $item, $owner, array( 'size', 'מידה' ) ),
			'weight'    => (string) $item->get_weight(),
			'ship'      => empty( $feed['ship'] ) ? '' : self::ship( $item ),
		);
	}

	/**
	 * The price after any discount the shop is showing but has not written
	 * into the product.
	 *
	 * Plugins split into two kinds. One kind filters the price itself, and
	 * `get_price()` has already told us. The other only rewrites the price
	 * on the page and keeps the discount in the cart — for those the number
	 * has to be asked for, and asking is always better than working it out
	 * again here, because the rules are theirs and they change.
	 *
	 * @param \WC_Product $item Item.
	 * @param float       $now  The price as WooCommerce reports it.
	 */
	private static function discounted( \WC_Product $item, float $now ): float {
		// The OC promotion engine: an automatic catalogue discount lives in
		// its display layer, and it answers for one product at a time.
		if ( function_exists( 'PromoEngine' ) || class_exists( '\PromoEngine\Plugin' ) ) {
			$engine = \PromoEngine\Plugin::instance();

			if ( isset( $engine->catalog ) && method_exists( $engine->catalog, 'catalog_price' ) ) {
				$asked = $engine->catalog->catalog_price( $item );

				if ( is_numeric( $asked ) && (float) $asked > 0 && (float) $asked < $now ) {
					$now = (float) $asked;
				}
			}
		}

		// Woo Discount Rules keeps its discount in the cart as well, and
		// offers this filter as the way to ask what a product really costs.
		if ( class_exists( '\Wdr\App\Controllers\ManageDiscount' ) ) {
			$asked = apply_filters( 'advanced_woo_discount_rules_get_product_discount_price', $now, $item, 1 );

			if ( is_numeric( $asked ) && (float) $asked > 0 && (float) $asked < $now ) {
				$now = (float) $asked;
			}
		}

		/**
		 * The price one item goes into the feed with.
		 *
		 * The way to teach the feed about any other discount plugin: return
		 * the number the shop actually shows.
		 *
		 * @param float       $now  Price so far.
		 * @param \WC_Product $item The item.
		 */
		return (float) apply_filters( 'oc_feed_price', $now, $item );
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
	 * @param \WC_Product $owner  Its product.
	 */
	private static function gtin( \WC_Product $item, \WC_Product $owner ): string {
		foreach ( array( $item, $owner ) as $one ) {
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
	 * @param \WC_Product       $item  Item.
	 * @param \WC_Product       $owner Its product.
	 * @param array<int,string> $names Candidate names.
	 */
	private static function attribute( \WC_Product $item, \WC_Product $owner, array $names ): string {
		foreach ( array( $item, $owner ) as $one ) {
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
