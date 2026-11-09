<?php
/**
 * Offers: the free-shipping top-up sheet.
 *
 * The bar says what is missing; this sheet shows what would cover it. It
 * opens from the bar, carries the bar itself as its title, lets the
 * shopper narrow the grid (one product that covers it / under the gap /
 * seen before), and adds with one tap. Everything in it is fetched when it
 * opens, so it always speaks of the cart as it is.
 *
 * @package OC_Theme
 */

declare( strict_types = 1 );

namespace OC\Theme\Offers;

use OC\Theme\Cart;

defined( 'ABSPATH' ) || exit;

/**
 * The sheet.
 */
final class Topup {

	/**
	 * How many products the grid holds at most.
	 */
	const LIMIT = 12;

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
		return '<div class="oc-topup" data-oc-topup-sheet data-url="' . esc_url( \WC_AJAX::get_endpoint( 'oc_topup' ) ) . '" hidden role="dialog" aria-modal="true" aria-labelledby="oc-topup-title">'
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

		$has_over  = false;
		$has_under = false;
		$has_seen  = false;

		foreach ( $items as $item ) {
			$has_over  = $has_over || $item['price'] >= $left;
			$has_under = $has_under || $item['price'] < $left;
			$has_seen  = $has_seen || 'seen' === $item['tag'];
		}

		$html .= '<div class="oc-topup__chips" role="group" aria-label="' . esc_attr__( 'Narrow down', 'oc-theme' ) . '">';
		$html .= '<button type="button" class="oc-topup__chip is-on" data-oc-topup-chip="all" aria-pressed="true">' . esc_html__( 'All', 'oc-theme' ) . '</button>';

		if ( $has_over ) {
			$html .= '<button type="button" class="oc-topup__chip" data-oc-topup-chip="over" aria-pressed="false">' . esc_html__( 'Covers it in one', 'oc-theme' ) . '</button>';
		}

		if ( $has_under ) {
			/* translators: %s: the sum still missing. */
			$html .= '<button type="button" class="oc-topup__chip" data-oc-topup-chip="under" aria-pressed="false">' . esc_html( sprintf( __( 'Up to %s', 'oc-theme' ), Cart::plain_price( wc_price( $left ) ) ) ) . '</button>';
		}

		if ( $has_seen ) {
			$html .= '<button type="button" class="oc-topup__chip" data-oc-topup-chip="seen" aria-pressed="false">' . esc_html__( 'You viewed', 'oc-theme' ) . '</button>';
		}

		$html .= '</div>';

		$tags = array(
			'match'   => __( 'Goes with your cart', 'oc-theme' ),
			'seen'    => __( 'You viewed it', 'oc-theme' ),
			'popular' => __( 'Sells a lot', 'oc-theme' ),
		);

		Cart::$in_upsells = true;

		$html .= '<div class="oc-topup__grid">';

		foreach ( $items as $item ) {
			$product = $item['product'];
			$id      = $product->get_id();
			$link    = get_permalink( $id );
			$kinds   = ( $item['price'] >= $left ? 'over ' : 'under ' ) . ( 'seen' === $item['tag'] ? 'seen' : '' );

			$html .= '<article class="oc-topup__item" data-oc-topup-item data-kinds="' . esc_attr( trim( $kinds ) ) . '">';
			$html .= '<a class="oc-topup__media" href="' . esc_url( (string) $link ) . '" tabindex="-1" aria-hidden="true">' . $product->get_image( 'woocommerce_thumbnail' ) . '</a>';
			$html .= '<span class="oc-topup__tag oc-topup__tag--' . esc_attr( $item['tag'] ) . '">' . esc_html( $tags[ $item['tag'] ] ) . '</span>';
			$html .= '<a class="oc-topup__name" href="' . esc_url( (string) $link ) . '">' . esc_html( $product->get_name() ) . '</a>';
			$html .= '<span class="oc-topup__price">' . $product->get_price_html() . '</span>';

			if ( $product->is_type( 'simple' ) ) {
				$html .= '<button type="button" class="oc-topup__add" data-oc-topup-add="' . absint( $id ) . '">' . esc_html__( 'Add to cart', 'oc-theme' ) . '</button>';
			} else {
				$html .= '<a class="oc-topup__add oc-topup__add--view" href="' . esc_url( (string) $link ) . '">' . esc_html__( 'Choose options', 'oc-theme' ) . '</a>';
			}

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
	 * What to show: what goes with the cart, what the shopper viewed, and
	 * what sells in the cart's categories — each product once, nothing
	 * already in the cart, nothing unbuyable.
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

		// 1. Goes with what is in the cart.
		foreach ( Offers::cart_offers( 8 ) as $offer ) {
			$take( $offer['product']->get_id(), 'match' );
		}

		// 2. Viewed before, if WooCommerce kept a note of it.
		$viewed = isset( $_COOKIE['woocommerce_recently_viewed'] ) ? wp_parse_id_list( (string) wp_unslash( $_COOKIE['woocommerce_recently_viewed'] ) ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a cookie WooCommerce sets.

		foreach ( array_slice( array_reverse( $viewed ), 0, 6 ) as $id ) {
			$take( (int) $id, 'seen' );
		}

		// 3. What sells in the cart's categories, roughly sized to the gap.
		if ( $cats && count( $out ) < self::LIMIT ) {
			$ids = get_posts(
				array(
					'post_type'              => 'product',
					'post_status'            => 'publish',
					'posts_per_page'         => 24,
					'fields'                 => 'ids',
					'no_found_rows'          => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
					'meta_key'               => 'total_sales', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- ordering only.
					'orderby'                => 'meta_value_num',
					'order'                  => 'DESC',
					'tax_query'              => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- the cart's own categories.
						array(
							'taxonomy' => 'product_cat',
							'field'    => 'term_id',
							'terms'    => array_keys( $cats ),
						),
					),
				)
			);

			foreach ( $ids as $id ) {
				$take( (int) $id, 'popular' );
			}
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
}
