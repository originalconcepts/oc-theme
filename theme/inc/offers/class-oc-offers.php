<?php
/**
 * Offers: what to put beside what the shopper already chose.
 *
 * Three layers answer, and the higher one wins:
 *
 *   1. By hand   — the product's own "goes with" list (WooCommerce's
 *                  cross-sells), set on the product.
 *   2. A rule    — "when the shopper has something from THIS category,
 *                  brand or product, offer from THAT one", written once for
 *                  a whole category. See Rules.
 *   3. By itself — what was really bought together with the product, and
 *                  after that the products most like it.
 *
 * So a shop with nothing set up still makes an offer, a shop with ten rules
 * covers its catalogue, and a product that needs a particular partner gets
 * exactly that one.
 *
 * An offer that carries a discount is kept honest the way the bundle
 * discount is: the line added from the offer is marked, and the discount is
 * a negative fee worked out from what is in the cart right now. Take the
 * product it stood beside out of the cart and the discount goes with it;
 * nothing has to be undone.
 *
 * @package OC_Theme
 */

declare( strict_types = 1 );

namespace OC\Theme\Offers;

use OC\Theme\Product_Linked;
use OC\Theme\Search;

defined( 'ABSPATH' ) || exit;

/**
 * Settings, the three layers, the discount and the order's memory of it.
 */
final class Offers {

	/**
	 * The option everything lives in.
	 */
	const OPTION = 'oc_offers';

	/**
	 * Cart-item data key marking a line added from an offer.
	 */
	const TAG = 'oc_offer';

	/**
	 * Order line meta carrying the same mark.
	 */
	const META = '_oc_offer';

	/**
	 * Order meta: this order's offer lines were already counted.
	 */
	const COUNTED = '_oc_offer_counted';

	/**
	 * How many orders must hold two products together before "bought
	 * together" is believed. One order is a coincidence.
	 */
	const CO_MIN = 2;

	/**
	 * Settings, read once per request.
	 *
	 * @var array<string,mixed>|null
	 */
	private static $settings = null;

	/**
	 * The cart's offers, worked out once per cart state.
	 *
	 * @var array<string,array<int,array{product:\WC_Product,for:int,src:string,pct:int}>>
	 */
	private static $memo = array();

	/**
	 * Hook in.
	 */
	public function register(): void {
		// A shop feature on a site with no shop: stay out, never fatal.
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		add_action( 'woocommerce_cart_calculate_fees', array( $this, 'apply_discount' ), 20 );

		// The product page's own lists: a product that names nothing takes
		// what its rule says. Read with the 'edit' context anywhere the raw
		// list is wanted, or these filters answer back.
		add_filter( 'woocommerce_product_get_cross_sell_ids', array( $this, 'page_cross_sells' ), 10, 2 );
		add_filter( 'woocommerce_product_get_upsell_ids', array( $this, 'page_upsells' ), 10, 2 );
		add_filter( 'woocommerce_related_products', array( $this, 'page_similar' ), 30, 3 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'order_item' ), 10, 3 );
		add_filter( 'woocommerce_hidden_order_itemmeta', array( $this, 'hide_meta' ) );
		add_action( 'woocommerce_order_status_processing', array( $this, 'count_order' ) );
		add_action( 'woocommerce_order_status_completed', array( $this, 'count_order' ) );
	}

	/* ---------------------------------------------------------- settings */

	/**
	 * Settings with defaults. The module ships switched off; once on, the
	 * automatic layer is on with it.
	 *
	 * @return array{on:int,auto:int,pct:int,ver:int,rules:array<string,array<string,mixed>>}
	 */
	public static function settings(): array {
		if ( null !== self::$settings ) {
			return self::$settings;
		}

		$saved = get_option( self::OPTION );
		$saved = is_array( $saved ) ? $saved : array();
		$rules = array();

		foreach ( (array) ( $saved['rules'] ?? array() ) as $uid => $rule ) {
			if ( is_array( $rule ) && 1 === preg_match( '/^[0-9a-f]{32}$/', (string) $uid ) ) {
				$rules[ (string) $uid ] = Rules::clean( $rule );
			}
		}

		self::$settings = array(
			'on'    => empty( $saved['on'] ) ? 0 : 1,
			'auto'  => isset( $saved['auto'] ) && ! $saved['auto'] ? 0 : 1,
			'pct'   => min( Rules::MAX_PERCENT, max( 0, (int) ( $saved['pct'] ?? 0 ) ) ),
			'ver'   => max( 1, (int) ( $saved['ver'] ?? 1 ) ),
			'rules' => $rules,
		);

		return self::$settings;
	}

	/**
	 * Whether offers are switched on for this shop.
	 */
	public static function on(): bool {
		return class_exists( 'WooCommerce' ) && 1 === self::settings()['on'];
	}

	/**
	 * Store the settings. Every save moves the version on, which is what
	 * the cached product lists are keyed by — a changed rule is read afresh
	 * at once instead of half an hour later.
	 *
	 * @param array<string,mixed> $settings Settings, in the shape settings() gives.
	 */
	public static function save( array $settings ): void {
		$rules = array();

		foreach ( (array) ( $settings['rules'] ?? array() ) as $uid => $rule ) {
			if ( is_array( $rule ) && 1 === preg_match( '/^[0-9a-f]{32}$/', (string) $uid ) ) {
				$rules[ (string) $uid ] = Rules::clean( $rule );
			}
		}

		update_option(
			self::OPTION,
			array(
				'on'    => empty( $settings['on'] ) ? 0 : 1,
				'auto'  => empty( $settings['auto'] ) ? 0 : 1,
				'pct'   => min( Rules::MAX_PERCENT, max( 0, (int) ( $settings['pct'] ?? 0 ) ) ),
				'ver'   => self::settings()['ver'] + 1,
				'rules' => $rules,
			)
		);

		self::$settings = null;
		self::$memo     = array();
	}

	/**
	 * Forget what was worked out in this request — after a product's lists
	 * changed under it.
	 */
	public static function forget(): void {
		self::$memo = array();
		Tiers::forget();
	}

	/* ------------------------------------------------------------ layers */

	/**
	 * A product's facts, the way the rules read them: its categories with
	 * every ancestor, and its brands.
	 *
	 * @param int $product_id Product id.
	 * @return array{id:int,cats:int[],brands:int[]}
	 */
	public static function facts( int $product_id ): array {
		$cats = array();

		foreach ( wc_get_product_term_ids( $product_id, 'product_cat' ) as $term_id ) {
			$cats[] = (int) $term_id;

			foreach ( get_ancestors( (int) $term_id, 'product_cat', 'taxonomy' ) as $parent ) {
				$cats[] = (int) $parent;
			}
		}

		$tax    = Search::brand_taxonomy();
		$brands = '' === $tax ? array() : array_map( 'intval', wc_get_product_term_ids( $product_id, $tax ) );

		return array(
			'id'     => $product_id,
			'cats'   => array_values( array_unique( $cats ) ),
			'brands' => array_values( array_unique( $brands ) ),
		);
	}

	/**
	 * What to offer beside one product, best first, with where each offer
	 * came from and the discount it carries. The first layer that has
	 * anything left to offer answers alone.
	 *
	 * @param int    $product_id The product the offer stands beside.
	 * @param string $place      One of Rules::PLACES.
	 * @param int[]  $in_cart    Product and variation ids already in the cart.
	 * @return array<int,array{id:int,src:string,pct:int}>
	 */
	public static function for_product( int $product_id, string $place, array $in_cart = array() ): array {
		$s       = self::settings();
		$product = wc_get_product( $product_id );

		if ( ! $product instanceof \WC_Product ) {
			return array();
		}

		// 1. By hand: the product's own list for the place. Similar products
		// have no hand-written list; the page works them out.
		if ( 'upsell' === $place ) {
			$own = $product->get_upsell_ids( 'edit' );
		} elseif ( 'similar' === $place ) {
			$own = array();
		} else {
			$own = $product->get_cross_sell_ids( 'edit' );
		}

		$ids = Rules::offerable( array_map( 'intval', (array) $own ), $product_id, $in_cart );

		if ( $ids ) {
			return self::tagged( $ids, 'manual', in_array( $place, array( 'cart', 'checkout' ), true ) ? $s['pct'] : 0 );
		}

		// 2. A rule.
		$facts = self::facts( $product_id );
		$uid   = Rules::pick( $s['rules'], $facts, $place );

		if ( null !== $uid ) {
			$rule  = $s['rules'][ $uid ];
			$named = 0;
			$pool  = self::rule_products( $rule, $facts, $named );
			$ids   = Rules::offerable( $pool, $product_id, $in_cart, $rule['exclude'] );
			$ids   = Fit::rank( $product_id, $ids, count( array_intersect( $ids, array_slice( $pool, 0, $named ) ) ), $place );

			if ( $ids ) {
				return self::tagged( $ids, 'r:' . $uid, (int) $rule['pct'] );
			}
		}

		// 3. By itself — in the cart only. The product page's similar row
		// is WooCommerce's own, and its other rows stay empty rather than
		// guessed.
		if ( $s['auto'] && in_array( $place, array( 'cart', 'checkout' ), true ) ) {
			$ids = array_merge( self::bought_with( $product_id ), Product_Linked::rank_similar( $product_id, 8 ) );
			$ids = Rules::offerable( array_map( 'intval', $ids ), $product_id, $in_cart );

			if ( $ids ) {
				return self::tagged( $ids, 'auto', $s['pct'] );
			}
		}

		return array();
	}

	/**
	 * Ids with their source and discount.
	 *
	 * @param int[]  $ids Product ids.
	 * @param string $src Source label.
	 * @param int    $pct Percent off.
	 * @return array<int,array{id:int,src:string,pct:int}>
	 */
	private static function tagged( array $ids, string $src, int $pct ): array {
		$out = array();

		foreach ( $ids as $id ) {
			$out[] = array(
				'id'  => (int) $id,
				'src' => $src,
				'pct' => $pct,
			);
		}

		return $out;
	}

	/**
	 * The products a rule offers beside a product: the ones it names, in
	 * the shop's order, then the ones from its categories — kept to the
	 * product's own brand when the rule asks for that.
	 *
	 * @param array<string,mixed>                   $rule  A clean rule.
	 * @param array{id:int,cats:int[],brands:int[]} $facts The product's facts.
	 * @param int                                   $named Out: how many of the ids were named one by one.
	 * @return int[]
	 */
	public static function rule_products( array $rule, array $facts, int &$named = 0 ): array {
		$tax    = Search::brand_taxonomy();
		$brands = ! empty( $rule['same_brand'] ) && '' !== $tax ? $facts['brands'] : array();

		// "Same brand" on a shop with no brands, or beside a product with
		// none, has nothing to offer — never the whole category instead.
		if ( ! empty( $rule['same_brand'] ) && ! $brands ) {
			return array();
		}

		$named_ids = array();

		foreach ( $rule['offer']['products'] as $id ) {
			if ( ! $brands || has_term( $brands, $tax, $id ) ) {
				$named_ids[] = (int) $id;
			}
		}

		$named = count( $named_ids );

		if ( ! $rule['offer']['cats'] && ! $brands ) {
			return $named_ids;
		}

		$key  = 'oc_offq_' . md5( (string) wp_json_encode( array( self::settings()['ver'], $rule['offer']['cats'], $brands, $rule['order'] ) ) );
		$more = get_transient( $key );

		if ( ! is_array( $more ) ) {
			$more = self::query( $rule['offer']['cats'], $brands, (string) $rule['order'] );

			set_transient( $key, $more, 30 * MINUTE_IN_SECONDS );
		}

		return array_merge( $named_ids, array_map( 'intval', $more ) );
	}

	/**
	 * Visible, in-stock products from the given categories and brands.
	 *
	 * @param int[]  $cats   Category ids; children are included.
	 * @param int[]  $brands Brand ids, or none.
	 * @param string $order  One of Rules::ORDERS.
	 * @return int[]
	 */
	private static function query( array $cats, array $brands, string $order ): array {
		$tax_query = array( 'relation' => 'AND' );

		if ( $cats ) {
			$tax_query[] = array(
				'taxonomy' => 'product_cat',
				'field'    => 'term_id',
				'terms'    => $cats,
			);
		}

		if ( $brands ) {
			$tax_query[] = array(
				'taxonomy' => Search::brand_taxonomy(),
				'field'    => 'term_id',
				'terms'    => $brands,
			);
		}

		$hidden = wc_get_product_visibility_term_ids();
		$hidden = array_filter( array( (int) ( $hidden['exclude-from-catalog'] ?? 0 ), (int) ( $hidden['outofstock'] ?? 0 ) ) );

		if ( $hidden ) {
			$tax_query[] = array(
				'taxonomy' => 'product_visibility',
				'field'    => 'term_taxonomy_id',
				'terms'    => array_values( $hidden ),
				'operator' => 'NOT IN',
			);
		}

		$args = array(
			'post_type'              => 'product',
			'post_status'            => 'publish',
			'posts_per_page'         => 30,
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'ignore_sticky_posts'    => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'tax_query'              => $tax_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- the rule IS a category; the answer is cached for half an hour.
		);

		if ( 'new' === $order ) {
			$args['orderby'] = 'date';
			$args['order']   = 'DESC';
		} else {
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- ordering only, cached.
			$args['meta_key'] = 'best' === $order ? 'total_sales' : '_price';
			$args['orderby']  = array(
				'meta_value_num' => 'cheap' === $order ? 'ASC' : 'DESC',
				'ID'             => 'DESC',
			);
		}

		return array_map( 'intval', get_posts( $args ) );
	}

	/**
	 * What shoppers really bought in the same order as a product, the
	 * pairing seen most often first. Read from WooCommerce's own order
	 * lookup tables, paid orders only, once a day per product.
	 *
	 * @param int $product_id Product id.
	 * @return int[]
	 */
	public static function bought_with( int $product_id ): array {
		$key = 'oc_offco_' . $product_id;
		$ids = get_transient( $key );

		if ( is_array( $ids ) ) {
			return array_map( 'intval', $ids );
		}

		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- WooCommerce's own lookup tables, names from $wpdb->prefix; cached for a day just below.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT b.product_id
				FROM {$wpdb->prefix}wc_order_product_lookup a
				JOIN {$wpdb->prefix}wc_order_product_lookup b ON b.order_id = a.order_id AND b.product_id <> a.product_id
				JOIN {$wpdb->prefix}wc_order_stats s ON s.order_id = a.order_id
				WHERE a.product_id = %d AND s.status IN ('wc-processing','wc-completed')
				GROUP BY b.product_id
				HAVING COUNT(DISTINCT b.order_id) >= %d
				ORDER BY COUNT(DISTINCT b.order_id) DESC, b.product_id DESC
				LIMIT 12",
				$product_id,
				self::CO_MIN
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$ids = array_map( 'intval', (array) $ids );

		set_transient( $key, $ids, DAY_IN_SECONDS );

		return $ids;
	}

	/* ------------------------------------------------------ product page */

	/**
	 * Whether a product-page list may be answered by a rule right now:
	 * offers on, a shop page (not the admin, not a REST or cron run), and
	 * not already inside this very lookup.
	 */
	private static function page_answers(): bool {
		if ( ! self::on() || is_admin() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return false;
		}

		return true;
	}

	/**
	 * A rule's products for a place on the product page, as ids.
	 *
	 * @param int    $product_id The product.
	 * @param string $place      xsell | upsell | similar.
	 * @return int[]
	 */
	private static function page_list( int $product_id, string $place ): array {
		static $inside = false;

		if ( $inside ) {
			return array();
		}

		$inside = true;

		$s     = self::settings();
		$facts = self::facts( $product_id );
		$uid   = Rules::pick( $s['rules'], $facts, $place );
		$ids   = array();

		if ( null !== $uid ) {
			$rule  = $s['rules'][ $uid ];
			$named = 0;
			$pool  = self::rule_products( $rule, $facts, $named );
			$ids   = Rules::offerable( $pool, $product_id, array(), $rule['exclude'] );
			$ids   = Fit::rank( $product_id, $ids, count( array_intersect( $ids, array_slice( $pool, 0, $named ) ) ), $place );
			$ids   = array_slice( $ids, 0, (int) $rule['count'] );
		}

		$inside = false;

		return $ids;
	}

	/**
	 * Cross-sells: the product's own list, or its rule's.
	 *
	 * @param mixed       $ids     What the product holds.
	 * @param \WC_Product $product The product.
	 * @return mixed
	 */
	public function page_cross_sells( $ids, $product ) {
		if ( ! empty( $ids ) || ! $product instanceof \WC_Product || ! self::page_answers() ) {
			return $ids;
		}

		$found = self::page_list( $product->get_id(), 'xsell' );

		return $found ? $found : $ids;
	}

	/**
	 * Upsells: the product's own list, or its rule's.
	 *
	 * @param mixed       $ids     What the product holds.
	 * @param \WC_Product $product The product.
	 * @return mixed
	 */
	public function page_upsells( $ids, $product ) {
		if ( ! empty( $ids ) || ! $product instanceof \WC_Product || ! self::page_answers() ) {
			return $ids;
		}

		$found = self::page_list( $product->get_id(), 'upsell' );

		return $found ? $found : $ids;
	}

	/**
	 * Similar products: a rule's pick replaces WooCommerce's when one
	 * covers the product.
	 *
	 * @param mixed               $related    WooCommerce's (or the theme's) pick.
	 * @param int                 $product_id The product.
	 * @param array<string,mixed> $args       limit, excluded_ids.
	 * @return mixed
	 */
	public function page_similar( $related, $product_id, $args ) {
		if ( ! self::page_answers() ) {
			return $related;
		}

		$found = self::page_list( (int) $product_id, 'similar' );

		if ( ! $found ) {
			return $related;
		}

		$limit = max( 1, (int) ( ( (array) $args )['limit'] ?? 4 ) );

		return array_slice( $found, 0, $limit );
	}

	/* -------------------------------------------------------------- cart */

	/**
	 * A product that can be added with one tap: there, visible, in stock,
	 * priced, and with nothing to choose first.
	 *
	 * @param int $id Product id.
	 */
	public static function addable( int $id ): ?\WC_Product {
		$product = wc_get_product( $id );

		if ( ! $product instanceof \WC_Product || ! $product->is_type( 'simple' ) ) {
			return null;
		}

		if ( ! $product->is_visible() || ! $product->is_in_stock() || ! $product->is_purchasable() || (float) $product->get_price() <= 0 ) {
			return null;
		}

		return $product;
	}

	/**
	 * The offers for the cart as it stands: the newest line speaks first,
	 * and lines that were themselves added from an offer speak last.
	 *
	 * @param int    $limit How many at most.
	 * @param string $place cart | checkout.
	 * @return array<int,array{product:\WC_Product,for:int,src:string,pct:int}>
	 */
	public static function cart_offers( int $limit = 1, string $place = 'cart' ): array {
		$cart = function_exists( 'WC' ) && WC()->cart ? WC()->cart : null;

		if ( ! $cart || ! self::on() || $cart->is_empty() ) {
			return array();
		}

		$contents = $cart->get_cart();
		$memo     = md5( implode( ',', array_keys( $contents ) ) ) . ':' . $limit . ':' . $place;

		if ( isset( self::$memo[ $memo ] ) ) {
			return self::$memo[ $memo ];
		}

		$in_cart  = array();
		$plain    = array();
		$offered  = array();
		$partners = array();

		foreach ( $contents as $item ) {
			$id        = (int) $item['product_id'];
			$in_cart[] = $id;

			if ( ! empty( $item['variation_id'] ) ) {
				$in_cart[] = (int) $item['variation_id'];
			}

			$tag = self::tag( $item[ self::TAG ] ?? null );

			if ( ! $tag ) {
				$plain[] = $id;
			} else {
				$offered[]  = $id;
				$partners[] = $tag['for'];
			}
		}

		// A line added from an offer never offers back the product it was
		// offered beside. Take that product out and it would otherwise come
		// straight back as an offer of its own — and adding it would bring
		// the first discount back too, two discounts for one pair.
		$skip = array_merge( $in_cart, $partners );

		$out  = array();
		$seen = array();

		foreach ( array_unique( array_merge( array_reverse( $plain ), array_reverse( $offered ) ) ) as $for ) {
			// Looking a product up costs a query or two; a long list of
			// candidates is cut off as soon as enough of them can be added.
			foreach ( self::for_product( (int) $for, $place, $skip ) as $offer ) {
				if ( isset( $seen[ $offer['id'] ] ) ) {
					continue;
				}

				$seen[ $offer['id'] ] = true;
				$product              = self::addable( $offer['id'] );

				if ( ! $product ) {
					continue;
				}

				$out[] = array(
					'product' => $product,
					'for'     => (int) $for,
					'src'     => $offer['src'],
					'pct'     => $offer['pct'],
				);

				if ( count( $out ) >= $limit ) {
					break 2;
				}
			}
		}

		self::$memo[ $memo ] = $out;

		return $out;
	}

	/**
	 * The mark a cart line carries, if it is a sound one.
	 *
	 * @param mixed $tag What the cart line holds under TAG.
	 * @return array{src:string,for:int,pct:int}|null
	 */
	public static function tag( $tag ): ?array {
		if ( ! is_array( $tag ) || ! isset( $tag['src'], $tag['for'] ) || ! Rules::is_source( (string) $tag['src'] ) ) {
			return null;
		}

		return array(
			'src' => (string) $tag['src'],
			'for' => (int) $tag['for'],
			'pct' => min( Rules::MAX_PERCENT, max( 0, (int) ( $tag['pct'] ?? 0 ) ) ),
		);
	}

	/**
	 * Take the offer discount off, while the offer still stands.
	 *
	 * It stands as long as the product it was offered beside is still in
	 * the cart. The sum follows the line, so a changed quantity changes it
	 * and a removed line ends it.
	 *
	 * @param \WC_Cart $cart The cart.
	 */
	public function apply_discount( $cart ): void {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}

		if ( ! $cart instanceof \WC_Cart || ! self::on() ) {
			return;
		}

		$present = array();

		foreach ( $cart->get_cart() as $item ) {
			$present[ (int) $item['product_id'] ] = true;
		}

		foreach ( $cart->get_cart() as $item ) {
			$tag = self::tag( $item[ self::TAG ] ?? null );

			if ( ! $tag || $tag['pct'] <= 0 || $tag['for'] === (int) $item['product_id'] || empty( $present[ $tag['for'] ] ) ) {
				continue;
			}

			$off = Rules::off( (float) $item['line_subtotal'] + (float) $item['line_subtotal_tax'], $tag['pct'], wc_get_price_decimals() );

			if ( $off <= 0 ) {
				continue;
			}

			$name = $item['data'] instanceof \WC_Product ? $item['data']->get_name() : '';

			$cart->add_fee(
				'' !== $name
					/* translators: %s: the product that was added from the offer. */
					? sprintf( __( 'Offer discount — %s', 'oc-theme' ), $name )
					: __( 'Offer discount', 'oc-theme' ),
				-1 * $off,
				false
			);
		}
	}

	/* ------------------------------------------------------------ orders */

	/**
	 * Carry the mark from the cart line onto the order line, so a sale can
	 * be traced back to the offer that made it.
	 *
	 * @param \WC_Order_Item_Product $item   Order line.
	 * @param string                 $key    Cart item key.
	 * @param array<string,mixed>    $values Cart item.
	 */
	public function order_item( $item, $key, $values ): void {
		$tag = self::tag( is_array( $values ) ? ( $values[ self::TAG ] ?? null ) : null );

		if ( $tag && $item instanceof \WC_Order_Item_Product ) {
			$item->add_meta_data( self::META, $tag, true );
		}
	}

	/**
	 * Keep the mark out of the order screen's item details.
	 *
	 * @param string[] $keys Hidden meta keys.
	 * @return string[]
	 */
	public function hide_meta( $keys ): array {
		$keys   = (array) $keys;
		$keys[] = self::META;

		return $keys;
	}

	/**
	 * A paid order: count its offer lines, once.
	 *
	 * @param int $order_id Order id.
	 */
	public function count_order( $order_id ): void {
		$order = wc_get_order( $order_id );

		if ( ! $order instanceof \WC_Order || $order->get_meta( self::COUNTED ) ) {
			return;
		}

		$present = array();

		foreach ( $order->get_items() as $item ) {
			if ( $item instanceof \WC_Order_Item_Product ) {
				$present[ (int) $item->get_product_id() ] = true;
			}
		}

		$found = false;

		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}

			$tag = self::tag( $item->get_meta( self::META ) );

			if ( ! $tag ) {
				continue;
			}

			$paid = (float) $item->get_total() + (float) $item->get_total_tax();

			// The discount was a fee on the order, not a lower line price;
			// what the offer really brought in is the line less that fee.
			if ( $tag['pct'] > 0 && ! empty( $present[ $tag['for'] ] ) ) {
				$paid -= Rules::off( (float) $item->get_subtotal() + (float) $item->get_subtotal_tax(), $tag['pct'], wc_get_price_decimals() );
			}

			Stats::bump(
				$tag['src'],
				'cart',
				$tag['for'],
				(int) $item->get_product_id(),
				array(
					'orders'  => 1,
					'revenue' => max( 0.0, $paid ),
				)
			);

			$found = true;
		}

		if ( $found ) {
			$order->update_meta_data( self::COUNTED, '1' );
			$order->save();
		}
	}
}
