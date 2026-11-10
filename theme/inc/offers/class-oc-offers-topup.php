<?php
/**
 * Offers: the free-shipping top-up sheet.
 *
 * The bar says what is missing; this sheet shows what would cover it. It
 * opens from the bar, carries the bar itself as its title, lets the
 * shopper narrow the grid by price band around the gap (under it, just
 * over it, well over it), and adds with one tap — or through the quick
 * pick when there are options to choose. Everything in it is fetched when
 * it opens, so it always speaks of the cart as it is.
 *
 * @package OC_Theme
 */

declare( strict_types = 1 );

namespace OC\Theme\Offers;

use OC\Theme\Cart;
use OC\Theme\Product_Linked;

defined( 'ABSPATH' ) || defined( 'OC_TESTS' ) || exit;

/**
 * The sheet.
 */
final class Topup {

	/**
	 * How many products the grid holds at most.
	 */
	const LIMIT = 16;

	/**
	 * Hook in.
	 */
	public function register(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		add_action( 'wc_ajax_oc_topup', array( $this, 'ajax' ) );
	}

	/**
	 * Whether the bar may offer the sheet: offers on, a gap to close.
	 */
	public static function available(): bool {
		if ( ! Offers::on() ) {
			return false;
		}

		$gap = Cart::ship_gap();

		return $gap['goal'] > 0 && $gap['have'] < $gap['goal'];
	}

	/**
	 * The link the bar carries.
	 */
	public static function link_html(): string {
		return '<a href="#" class="oc-shipbar__go" data-oc-topup role="button">' . esc_html__( 'Top it up', 'oc-theme' ) . '</a>';
	}

	/**
	 * The empty sheet, printed once with the panel; the page fills it.
	 */
	public static function shell(): string {
		$auto = empty( Cart::settings()['topup_auto'] ) ? '0' : '1';

		return '<div class="oc-topup" data-oc-topup-sheet data-auto="' . $auto . '" data-url="' . esc_url( \WC_AJAX::get_endpoint( 'oc_topup' ) ) . '" hidden role="dialog" aria-modal="true" aria-labelledby="oc-topup-title">'
			. '<div class="oc-topup__card">'
			. '<div class="oc-topup__head"><h3 class="oc-topup__title" id="oc-topup-title">' . esc_html__( 'Top up for free shipping', 'oc-theme' ) . '</h3>'
			. '<button type="button" class="oc-topup__close" data-oc-topup-close aria-label="' . esc_attr__( 'Close', 'oc-theme' ) . '">&times;</button></div>'
			. '<div class="oc-topup__body" data-oc-topup-body><span class="oc-topup__loading">…</span></div>'
			. '</div></div>';
	}

	/**
	 * The sheet's contents for the cart as it is.
	 */
	public function ajax(): void {
		if ( ! Offers::on() || ! WC()->cart ) {
			wp_send_json_error();
		}

		wp_send_json_success( array( 'html' => self::html() ) );
	}

	/**
	 * Bar, chips, grid, footer.
	 */
	public static function html(): string {
		$gap  = Cart::ship_gap();
		$left = max( 0.0, $gap['goal'] - $gap['have'] );

		if ( $gap['goal'] <= 0 ) {
			return '<p class="oc-topup__empty">' . esc_html__( 'There is no free-shipping line to reach.', 'oc-theme' ) . '</p>';
		}

		$html = Cart::ship_bar_markup();

		if ( $left <= 0 ) {
			return $html . '<p class="oc-topup__empty">' . esc_html__( 'You already earned free shipping.', 'oc-theme' ) . '</p>';
		}

		$items = self::pool( $left );

		if ( ! $items ) {
			return $html . '<p class="oc-topup__empty">' . esc_html__( 'Nothing to suggest right now.', 'oc-theme' ) . '</p>';
		}

		// Price bands around the gap, the way a marketplace filters: under
		// what is missing, just over it (covers it in one), well over it.
		// Only the bands with something in them get a chip.
		$step  = self::step( $left );
		$bands = array(
			'under' => array(
				0.0,
				$left,
				/* translators: %s: a sum. */
				sprintf( __( 'Up to %s', 'oc-theme' ), Cart::plain_price( wc_price( $left ) ) ),
			),
			'near'  => array(
				$left,
				$left + $step,
				/* translators: 1: a sum. 2: a higher sum. */
				sprintf( _x( '%1$s to %2$s', 'price band', 'oc-theme' ), Cart::plain_price( wc_price( $left ) ), Cart::plain_price( wc_price( $left + $step ) ) ),
			),
			'over'  => array(
				$left + $step,
				INF,
				/* translators: %s: a sum. */
				sprintf( __( 'Over %s', 'oc-theme' ), Cart::plain_price( wc_price( $left + $step ) ) ),
			),
		);
		$count = array_fill_keys( array_keys( $bands ), 0 );

		foreach ( $items as $i => $item ) {
			$items[ $i ]['band'] = self::band( $item['price'], $bands );
			++$count[ $items[ $i ]['band'] ];
		}

		$html .= '<div class="oc-topup__chips" role="group" aria-label="' . esc_attr__( 'Narrow down', 'oc-theme' ) . '">';
		$html .= '<button type="button" class="oc-topup__chip is-on" data-oc-topup-chip="all" aria-pressed="true">' . esc_html__( 'All', 'oc-theme' ) . '</button>';

		foreach ( $bands as $key => $band ) {
			if ( $count[ $key ] > 0 ) {
				$html .= '<button type="button" class="oc-topup__chip" data-oc-topup-chip="' . esc_attr( $key ) . '" aria-pressed="false">' . esc_html( $band[2] ) . '</button>';
			}
		}

		$html .= '</div>';

		$tags = array(
			'match'   => __( 'Goes with your cart', 'oc-theme' ),
			'seen'    => __( 'You viewed it', 'oc-theme' ),
			'alike'   => __( 'Like what you chose', 'oc-theme' ),
			'popular' => __( 'Sells a lot', 'oc-theme' ),
		);

		Cart::$in_upsells = true;

		$html .= '<div class="oc-topup__grid">';

		foreach ( $items as $item ) {
			$product = $item['product'];
			$id      = $product->get_id();
			$link    = get_permalink( $id );

			$html .= '<article class="oc-topup__item" data-oc-topup-item data-kinds="' . esc_attr( $item['band'] ) . '">';
			$html .= '<a class="oc-topup__media" href="' . esc_url( (string) $link ) . '" tabindex="-1" aria-hidden="true">' . $product->get_image( 'woocommerce_thumbnail' ) . '</a>';
			$html .= '<span class="oc-topup__tag oc-topup__tag--' . esc_attr( $item['tag'] ) . '">' . esc_html( $tags[ $item['tag'] ] ) . '</span>';
			$html .= '<a class="oc-topup__name" href="' . esc_url( (string) $link ) . '">' . esc_html( $product->get_name() ) . '</a>';
			$html .= '<span class="oc-topup__price">' . $product->get_price_html() . '</span>';

			// The product page's own button word on both: a simple product
			// is added where it stands, one with options opens the quick
			// pick — the same panel the catalogue's cards open.
			$act   = $product->is_type( 'simple' ) ? 'data-oc-topup-add' : 'data-oc-topup-pick';
			$html .= '<button type="button" class="oc-topup__add" ' . $act . '="' . absint( $id ) . '">' . esc_html( $product->single_add_to_cart_text() ) . '</button>';

			$html .= '</article>';
		}

		$html .= '</div>';

		Cart::$in_upsells = false;

		$html .= '<div class="oc-topup__foot">';
		$html .= '<span class="oc-topup__sum"><strong>' . wp_kses_post( wc_price( (float) WC()->cart->get_displayed_subtotal() ) ) . '</strong><small>' . esc_html__( 'in your cart', 'oc-theme' ) . '</small></span>';
		$html .= '<a class="oc-topup__checkout checkout-button" href="' . esc_url( wc_get_checkout_url() ) . '">' . esc_html__( 'Continue to checkout', 'oc-theme' ) . '</a>';
		$html .= '</div>';

		return $html;
	}

	/**
	 * How wide the "just over" band is: about a quarter of the gap,
	 * rounded up to a round number — 200 missing gives 200–250, 120 gives
	 * 120–150, 37 gives 37–47.
	 *
	 * @param float $left The sum still missing.
	 */
	public static function step( float $left ): float {
		$raw = max( 1.0, $left / 4 );
		$mag = 10 ** floor( log10( $raw ) );

		return (float) ( ceil( $raw / $mag ) * $mag );
	}

	/**
	 * Which band a price falls in.
	 *
	 * @param float                            $price The price.
	 * @param array<string,array{0:float,1:float,2:string}> $bands The bands: key => [from, to, label].
	 */
	private static function band( float $price, array $bands ): string {
		foreach ( $bands as $key => $band ) {
			if ( $price >= $band[0] && $price < $band[1] ) {
				return $key;
			}
		}

		return (string) array_key_last( $bands );
	}

	/**
	 * What to show: what goes with the cart (the cart's and the checkout's
	 * offers — hand-picked, rules, automatic), what the shopper viewed,
	 * what is like what they chose, and what sells in the cart's
	 * categories — each product once, nothing already in the cart, nothing
	 * unbuyable.
	 *
	 * @param float $left The sum still missing.
	 * @return array<int,array{product:\WC_Product,price:float,tag:string}>
	 */
	private static function pool( float $left ): array {
		$in_cart = array();
		$cats    = array();

		foreach ( WC()->cart->get_cart() as $item ) {
			$in_cart[] = (int) $item['product_id'];

			foreach ( wc_get_product_term_ids( (int) $item['product_id'], 'product_cat' ) as $term_id ) {
				$cats[ (int) $term_id ] = true;
			}
		}

		$seen = array();
		$out  = array();

		$take = static function ( int $id, string $tag ) use ( &$seen, &$out, $in_cart ): void {
			if ( isset( $seen[ $id ] ) || in_array( $id, $in_cart, true ) || count( $out ) >= self::LIMIT ) {
				return;
			}

			$seen[ $id ] = true;
			$product     = wc_get_product( $id );

			if ( ! $product instanceof \WC_Product || ! $product->is_visible() || ! $product->is_in_stock() || ! $product->is_purchasable() ) {
				return;
			}

			$price = (float) wc_get_price_to_display( $product );

			if ( $price <= 0 ) {
				return;
			}

			$out[] = array(
				'product' => $product,
				'price'   => $price,
				'tag'     => $tag,
			);
		};

		// Each shelf gets its share, so no one source floods the grid: what
		// goes with the cart, what the shopper viewed, what is like what
		// they chose, what sells there — then the shelves fill up.
		$share = (int) ceil( self::LIMIT / 4 );

		// 1. Goes with what is in the cart: the cart's offers and the ones
		// the shop set for the checkout, each product once.
		$matches = array();

		foreach ( array_merge( Offers::cart_offers( 8 ), Offers::cart_offers( 8, 'checkout' ) ) as $offer ) {
			$matches[ $offer['product']->get_id() ] = true;
		}

		$matches = array_keys( $matches );

		foreach ( array_slice( $matches, 0, $share ) as $id ) {
			$take( $id, 'match' );
		}

		// 2. Viewed before: the ids the browser noted, newest first.
		$viewed = self::viewed();

		foreach ( array_slice( $viewed, 0, $share ) as $id ) {
			$take( $id, 'seen' );
		}

		// 3. Like what is in the cart: the newest line's nearest products
		// first, then the other lines', taken in turns.
		$alike = self::alike();

		foreach ( array_slice( $alike, 0, $share ) as $id ) {
			$take( $id, 'alike' );
		}

		// 4. Sells in the cart's categories and covers the gap in one,
		// then what fits under it, the closest first.
		foreach ( self::selling( $cats, $left, 'over', 12 ) as $id ) {
			if ( count( $out ) >= 4 * $share ) {
				break;
			}

			$take( $id, 'popular' );
		}

		foreach ( self::selling( $cats, $left, 'under', 12 ) as $id ) {
			if ( count( $out ) >= self::LIMIT ) {
				break;
			}

			$take( $id, 'popular' );
		}

		// 5. Whatever room is left: the rest of each shelf.
		foreach ( array_slice( $matches, $share ) as $id ) {
			$take( $id, 'match' );
		}

		foreach ( array_slice( $viewed, $share ) as $id ) {
			$take( $id, 'seen' );
		}

		foreach ( array_slice( $alike, $share ) as $id ) {
			$take( $id, 'alike' );
		}

		foreach ( self::selling( $cats, $left, 'any', 24 ) as $id ) {
			$take( $id, 'popular' );
		}

		// The ones that cover the gap in one go first, cheapest of them
		// first; then the rest, dearest first — the closest to the gap
		// either way.
		usort(
			$out,
			static function ( array $a, array $b ) use ( $left ): int {
				$ao = $a['price'] >= $left;
				$bo = $b['price'] >= $left;

				if ( $ao !== $bo ) {
					return $ao ? -1 : 1;
				}

				return $ao ? $a['price'] <=> $b['price'] : $b['price'] <=> $a['price'];
			}
		);

		return $out;
	}

	/**
	 * Products like the ones in the cart: each line's nearest few, the
	 * newest line first, taken in turns so one line does not crowd out
	 * the rest.
	 *
	 * @return int[]
	 */
	private static function alike(): array {
		$lists = array();

		foreach ( array_reverse( WC()->cart->get_cart() ) as $item ) {
			$lists[] = Product_Linked::rank_similar( (int) $item['product_id'], 6 );

			if ( count( $lists ) >= 4 ) {
				break;
			}
		}

		$out = array();

		for ( $i = 0; $i < 6; $i++ ) {
			foreach ( $lists as $list ) {
				if ( isset( $list[ $i ] ) ) {
					$out[] = (int) $list[ $i ];
				}
			}
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * The products the shopper viewed, newest first, from the note the
	 * browser keeps (WooCommerce's recently-viewed cookie, written by the
	 * page so the response stays cacheable). The ids are "|"-separated.
	 *
	 * @return int[]
	 */
	private static function viewed(): array {
		if ( ! isset( $_COOKIE['woocommerce_recently_viewed'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a cookie, read only.
			return array();
		}

		$raw = (string) wp_unslash( $_COOKIE['woocommerce_recently_viewed'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- digits only are kept below.
		$ids = array();

		foreach ( (array) preg_split( '/[|,\s]+/', $raw ) as $piece ) {
			if ( ctype_digit( $piece ) && (int) $piece > 0 ) {
				$ids[] = (int) $piece;
			}
		}

		return array_values( array_unique( array_reverse( $ids ) ) );
	}

	/**
	 * What sells in the given categories, priced against the gap: "over"
	 * covers it in one (best sellers first), "under" fits beneath it (the
	 * closest first), "any" is the plain best-seller list.
	 *
	 * @param array<int,bool> $cats  Category ids as keys.
	 * @param float           $left  The sum still missing.
	 * @param string          $how   over | under | any.
	 * @param int             $limit How many at most.
	 * @return int[]
	 */
	private static function selling( array $cats, float $left, string $how, int $limit ): array {
		$args = array(
			'post_type'              => 'product',
			'post_status'            => 'publish',
			'posts_per_page'         => $limit,
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		);

		if ( $cats ) {
			$args['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- the cart's own categories, a dozen ids.
				array(
					'taxonomy' => 'product_cat',
					'field'    => 'term_id',
					'terms'    => array_keys( $cats ),
				),
			);
		}

		if ( 'under' === $how ) {
			$args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- a price band, a dozen rows.
				array(
					'key'     => '_price',
					'value'   => array( 0.01, $left ),
					'compare' => 'BETWEEN',
					'type'    => 'DECIMAL(12,2)',
				),
			);
			$args['orderby']    = array( 'meta_value_num' => 'DESC' );
			$args['meta_key']   = '_price'; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- ordering only.
		} else {
			if ( 'over' === $how ) {
				$args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- a price floor.
					array(
						'key'     => '_price',
						'value'   => $left,
						'compare' => '>=',
						'type'    => 'DECIMAL(12,2)',
					),
				);
			}

			$args['meta_key'] = 'total_sales'; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- ordering only.
			$args['orderby']  = 'meta_value_num';
			$args['order']    = 'DESC';
		}

		return array_map( 'intval', (array) get_posts( $args ) );
	}
}
