<?php
/**
 * Offers: the one card in the cart.
 *
 * One product, not a shelf — the single best thing to put beside what the
 * shopper just chose, with its price, the discount if the offer carries
 * one, a button that adds it in one tap, and a line saying what the
 * addition does to the delivery charge. It rides the cart-fragments
 * channel like the rest of the panel, so it changes with the cart: add the
 * offer and the next one takes its place.
 *
 * @package OC_Theme
 */

declare( strict_types = 1 );

namespace OC\Theme\Offers;

use OC\Theme\Cart;
use OC\Theme\Shipping;

defined( 'ABSPATH' ) || exit;

/**
 * The card and its add button.
 */
final class Card {

	/**
	 * Hook in.
	 */
	public function register(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		add_filter( 'woocommerce_add_to_cart_fragments', array( $this, 'fragments' ) );
		add_action( 'wc_ajax_oc_offer_add', array( $this, 'ajax_add' ) );
		add_action( 'wc_ajax_oc_offer_remove', array( $this, 'ajax_remove' ) );
		add_action( 'woocommerce_review_order_before_order_total', array( $this, 'checkout_row' ) );
	}

	/**
	 * The card is one more fragment.
	 *
	 * @param array<string,string> $fragments Fragment map.
	 * @return array<string,string>
	 */
	public function fragments( $fragments ): array {
		$fragments = (array) $fragments;

		$fragments['[data-oc-cart-offer]'] = self::html();

		return $fragments;
	}

	/**
	 * The card, or its empty stand-in.
	 */
	public static function html(): string {
		$none = '<div data-oc-cart-offer hidden></div>';

		if ( ! Offers::on() || ! function_exists( 'WC' ) || ! WC()->cart || WC()->cart->is_empty() ) {
			return $none;
		}

		$offers = Offers::cart_offers( 1 );

		if ( ! $offers ) {
			return $none;
		}

		$offer   = $offers[0];
		$product = $offer['product'];
		$id      = $product->get_id();
		$link    = get_permalink( $id );
		$was     = (float) wc_get_price_to_display( $product );
		$now     = $was - Rules::off( $was, $offer['pct'], wc_get_price_decimals() );
		$deal    = $offer['pct'] > 0 && $now < $was;

		// What the page needs to say "this was seen". Staff do not count.
		$seen = current_user_can( 'edit_posts' ) ? '' : (string) wp_json_encode(
			array(
				'u' => rest_url( 'oc/v1/offer-seen' ),
				't' => Stats::token(),
				's' => $offer['src'],
				'f' => $offer['for'],
				'o' => $id,
				'p' => 'cart',
			)
		);

		$color = (string) ( Cart::settings()['offer_c'] ?? '' );

		$html  = '<section class="oc-offer" data-oc-cart-offer aria-label="' . esc_attr__( 'An offer for your cart', 'oc-theme' ) . '"';
		$html .= '' !== $seen ? ' data-seen="' . esc_attr( $seen ) . '"' : '';
		$html .= '' !== $color ? ' style="--oc-offer-c:' . esc_attr( $color ) . '"' : '';
		$html .= '>';

		$html .= '<div class="oc-offer__head">';
		$html .= '<h3 class="oc-offer__title">' . esc_html__( 'Goes with what you chose', 'oc-theme' ) . '</h3>';

		if ( $deal ) {
			/* translators: %d: percent off. */
			$html .= '<span class="oc-offer__badge">' . esc_html( sprintf( __( '%d%% off when added now', 'oc-theme' ), $offer['pct'] ) ) . '</span>';
		}

		$html .= '</div>';

		Cart::$in_upsells = true;

		$html .= '<div class="oc-offer__row">';
		$html .= '<a class="oc-offer__media" href="' . esc_url( (string) $link ) . '" tabindex="-1" aria-hidden="true">' . $product->get_image( 'woocommerce_thumbnail' ) . '</a>';
		$html .= '<div class="oc-offer__info">';
		$html .= '<a class="oc-offer__name" href="' . esc_url( (string) $link ) . '">' . esc_html( $product->get_name() ) . '</a>';
		$html .= '<span class="oc-offer__price">';

		if ( $deal ) {
			$html .= '<ins>' . wc_price( $now ) . '</ins> <del>' . wc_price( $was ) . '</del>';
		} else {
			$html .= $product->get_price_html();
		}

		$html .= '</span></div></div>';

		Cart::$in_upsells = false;

		$label = $deal
			/* translators: %s: the price after the offer's discount. */
			? sprintf( __( 'Add to cart for %s', 'oc-theme' ), Cart::plain_price( wc_price( $now ) ) )
			: __( 'Add to cart', 'oc-theme' );

		$html .= '<button type="button" class="oc-offer__add" data-oc-offer-add="' . absint( $id ) . '" data-url="' . esc_url( \WC_AJAX::get_endpoint( 'oc_offer_add' ) ) . '">' . esc_html( $label ) . '</button>';

		$hint = self::hint( $product, $was );

		if ( '' !== $hint ) {
			$html .= '<p class="oc-offer__hint">' . $hint . '</p>';
		}

		$html .= '</section>';

		return $html;
	}

	/**
	 * What adding the product does to the delivery charge, in one line —
	 * or nothing, when there is no free-delivery line to speak of or it
	 * has already been crossed.
	 *
	 * @param \WC_Product $product The product offered.
	 * @param float       $price   Its price as the shop shows it.
	 * @return string Escaped markup.
	 */
	private static function hint( \WC_Product $product, float $price ): string {
		if ( ! $product->needs_shipping() ) {
			return '';
		}

		$gap = Cart::ship_gap();

		if ( $gap['goal'] <= 0 || $gap['have'] >= $gap['goal'] ) {
			return '';
		}

		if ( Shipping::enabled() ) {
			// The theme prices delivery, so ask it what the cart would be
			// worth with this product in it — its own groups and regions
			// counted, not a guess from the price.
			$with = Shipping::cart_progress(
				array(
					array(
						'subtotal' => $price,
						'group'    => (string) $product->get_shipping_class(),
						'qty'      => 1,
					),
				)
			);

			if ( $with['goal'] <= 0 ) {
				return '';
			}

			$after = $with['have'];
			$left  = $with['goal'] - $after;

			// A product that does not count toward free delivery moves
			// nothing; saying so would only be noise.
			if ( $after <= $gap['have'] ) {
				return '';
			}
		} else {
			$after = $gap['have'] + $price;
			$left  = $gap['goal'] - $after;
		}

		if ( $left <= 0 ) {
			return esc_html__( 'Adding it gets you free shipping', 'oc-theme' );
		}

		$mid = $gap['mid'];

		if ( $mid['at'] > 0 && $gap['have'] < $mid['at'] && $after >= $mid['at'] ) {
			return sprintf(
				/* translators: 1: the lower delivery price. 2: the sum still missing for free shipping. */
				esc_html__( 'Adding it drops delivery to %1$s, with %2$s left for free shipping', 'oc-theme' ),
				'<strong>' . esc_html( Cart::plain_price( wc_price( $mid['price'] ) ) ) . '</strong>',
				'<strong>' . esc_html( Cart::plain_price( wc_price( $left ) ) ) . '</strong>'
			);
		}

		/* translators: %s: the sum still missing for free shipping. */
		return sprintf( esc_html__( 'After adding it, only %s left for free shipping', 'oc-theme' ), '<strong>' . esc_html( Cart::plain_price( wc_price( $left ) ) ) . '</strong>' );
	}

	/* ---------------------------------------------------------- checkout */

	/**
	 * The row in the order review that carries the checkout offer.
	 */
	public function checkout_row(): void {
		$html = self::checkout_html();

		if ( '' !== $html ) {
			echo '<tr class="oc-offer-co-row"><td colspan="2">' . $html . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built escaped.
		}
	}

	/**
	 * One tick before paying: the best offer for the cart, or the offer
	 * already taken, ticked, so it can be taken back out.
	 */
	public static function checkout_html(): string {
		if ( ! Offers::on() || ! function_exists( 'WC' ) || ! WC()->cart || WC()->cart->is_empty() ) {
			return '';
		}

		$decimals = wc_get_price_decimals();
		$color    = (string) ( Cart::settings()['offer_c'] ?? '' );
		$style    = '' !== $color ? ' style="--oc-offer-c:' . esc_attr( $color ) . '"' : '';
		$add_url  = esc_url( \WC_AJAX::get_endpoint( 'oc_offer_add' ) );
		$rm_url   = esc_url( \WC_AJAX::get_endpoint( 'oc_offer_remove' ) );

		// An offer already in the cart: shown ticked, so one tap takes it out.
		foreach ( WC()->cart->get_cart() as $key => $item ) {
			$tag = Offers::tag( $item[ Offers::TAG ] ?? null );

			if ( ! $tag || ! $item['data'] instanceof \WC_Product ) {
				continue;
			}

			$html  = '<div class="oc-offer oc-offer--co is-taken"' . $style . '>';
			$html .= '<label class="oc-offer__tick">';
			$html .= '<input type="checkbox" checked data-oc-offer-co data-key="' . esc_attr( (string) $key ) . '" data-url-remove="' . $rm_url . '" />';
			$html .= '<span class="oc-offer__tickbody">';
			/* translators: %s: product name. */
			$html .= '<strong>' . esc_html( sprintf( __( 'Added to your order: %s', 'oc-theme' ), $item['data']->get_name() ) ) . '</strong>';
			$html .= '<span class="oc-offer__hint">' . esc_html__( 'Untick to take it out again.', 'oc-theme' ) . '</span>';
			$html .= '</span></label></div>';

			return $html;
		}

		$offers = Offers::cart_offers( 1, 'checkout' );

		if ( ! $offers ) {
			return '';
		}

		$offer   = $offers[0];
		$product = $offer['product'];
		$was     = (float) wc_get_price_to_display( $product );
		$now     = $was - Rules::off( $was, $offer['pct'], $decimals );
		$deal    = $offer['pct'] > 0 && $now < $was;

		$html  = '<div class="oc-offer oc-offer--co"' . $style . '>';
		$html .= '<span class="oc-offer__cotitle">' . esc_html__( 'A moment before you pay', 'oc-theme' ) . '</span>';
		$html .= '<label class="oc-offer__tick">';
		$html .= '<input type="checkbox" data-oc-offer-co data-add="' . absint( $product->get_id() ) . '" data-url-add="' . $add_url . '" />';
		$html .= '<span class="oc-offer__tickbody">';

		if ( $deal ) {
			/* translators: 1: product name. 2: percent off. */
			$html .= '<strong>' . esc_html( sprintf( __( 'Add %1$s at %2$d%% off', 'oc-theme' ), $product->get_name(), $offer['pct'] ) ) . '</strong>';
			$html .= '<span class="oc-offer__price"><ins>' . wc_price( $now ) . '</ins> <del>' . wc_price( $was ) . '</del></span>';
		} else {
			/* translators: %s: product name. */
			$html .= '<strong>' . esc_html( sprintf( __( 'Add %s', 'oc-theme' ), $product->get_name() ) ) . '</strong>';
			$html .= '<span class="oc-offer__price"><ins>' . wc_price( $was ) . '</ins></span>';
		}

		$hint = self::hint( $product, $was );

		if ( '' !== $hint ) {
			$html .= '<span class="oc-offer__hint">' . $hint . '</span>';
		}

		$html .= '</span>';
		$html .= '<span class="oc-offer__comedia" aria-hidden="true">' . $product->get_image( 'woocommerce_gallery_thumbnail' ) . '</span>';
		$html .= '</label></div>';

		return $html;
	}

	/**
	 * Take an offer line out again, from the checkout's tick.
	 */
	public function ajax_remove(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- same public surface as WooCommerce's own cart ajax.
		$key = isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['key'] ) ) : '';

		if ( '' === $key || ! WC()->cart ) {
			wp_send_json_error();
		}

		$item = WC()->cart->get_cart_item( $key );

		// Only a line that came from an offer leaves this way; the rest of
		// the cart is not this button's to touch.
		if ( ! $item || ! Offers::tag( $item[ Offers::TAG ] ?? null ) ) {
			wp_send_json_error();
		}

		WC()->cart->remove_cart_item( $key );
		WC()->cart->calculate_totals();

		wp_send_json_success();
	}

	/**
	 * Add the offered product, marked as an offer.
	 *
	 * The page only says which product. Whether that product is on offer,
	 * beside what, and at what discount is worked out here from the cart
	 * itself — a price cannot be asked for, only earned.
	 */
	public function ajax_add(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- same public surface as WooCommerce's own add to cart.
		$id    = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
		$place = isset( $_POST['place'] ) && 'checkout' === $_POST['place'] ? 'checkout' : 'cart';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( ! $id || ! Offers::on() || ! WC()->cart ) {
			wp_send_json_error();
		}

		$match = null;

		foreach ( Offers::cart_offers( 12, $place ) as $offer ) {
			if ( $offer['product']->get_id() === $id ) {
				$match = $offer;
				break;
			}
		}

		// The cart moved on since the card was drawn (another tab, a line
		// removed): add nothing, and answer with the cart as it is now.
		if ( ! $match ) {
			\WC_AJAX::get_refreshed_fragments();

			return;
		}

		$added = WC()->cart->add_to_cart(
			$id,
			1,
			0,
			array(),
			array(
				Offers::TAG => array(
					'src' => $match['src'],
					'for' => $match['for'],
					'pct' => $match['pct'],
				),
			)
		);

		if ( ! $added ) {
			$notices = wc_get_notices( 'error' );
			wc_clear_notices();
			wp_send_json_error(
				array(
					'message' => $notices ? html_entity_decode( wp_strip_all_tags( (string) $notices[0]['notice'] ), ENT_QUOTES, 'UTF-8' ) : '',
				)
			);
		}

		wc_clear_notices();

		if ( ! current_user_can( 'edit_posts' ) ) {
			Stats::bump( $match['src'], $place, $match['for'], $id, array( 'added' => 1 ) );
		}

		WC()->cart->calculate_totals();
		\WC_AJAX::get_refreshed_fragments();
	}
}
