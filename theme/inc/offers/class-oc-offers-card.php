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
use OC\Theme\WooCommerce;

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
		// Above the payment methods, outside the order review: the review
		// folds on the desktop, and a tick nobody sees is no offer.
		add_action( 'woocommerce_review_order_before_payment', array( $this, 'checkout_slot' ), 5 );
		add_filter( 'woocommerce_update_order_review_fragments', array( $this, 'checkout_fragment' ) );
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
		$html .= self::promo_html( $product );
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

		// A product with options opens the quick pick, which adds it at the
		// offer's price once the option is chosen.
		$act   = $product->is_type( 'variable' ) ? 'data-oc-offer-pick' : 'data-oc-offer-add';
		$html .= '<button type="button" class="oc-offer__add" ' . $act . '="' . absint( $id ) . '" data-url="' . esc_url( \WC_AJAX::get_endpoint( 'oc_offer_add' ) ) . '">' . esc_html( $label ) . '</button>';
		$html .= self::hint_html( $product, $was, 'p' );
		$html .= '</section>';

		return $html;
	}

	/**
	 * The delivery line under an offer: a loud badge when adding earns
	 * free shipping, a quiet note when it only brings it closer, nothing
	 * when there is nothing to say.
	 *
	 * @param \WC_Product $product The product offered.
	 * @param float       $price   Its price as the shop shows it.
	 * @param string      $tag     The element to wrap it in (p | span).
	 * @return string Escaped markup, or ''.
	 */
	public static function hint_html( \WC_Product $product, float $price, string $tag = 'span' ): string {
		$hint = self::hint( $product, $price );

		if ( '' === $hint['text'] ) {
			return '';
		}

		$tag = 'p' === $tag ? 'p' : 'span';

		if ( $hint['free'] ) {
			return '<' . $tag . ' class="oc-offer__hint oc-offer__hint--free"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 7h11v9H3zM14 10h4l3 3v3h-7z"/><circle cx="7" cy="18" r="2"/><circle cx="17" cy="18" r="2"/></svg><span>' . $hint['text'] . '</span></' . $tag . '>';
		}

		return '<' . $tag . ' class="oc-offer__hint">' . $hint['text'] . '</' . $tag . '>';
	}

	/**
	 * What adding the product does to the delivery charge, in one line —
	 * or nothing, when there is no free-delivery line to speak of or it
	 * has already been crossed.
	 *
	 * @param \WC_Product $product The product offered.
	 * @param float       $price   Its price as the shop shows it.
	 * @return array{text:string,free:bool} Escaped markup, and whether it earns free shipping.
	 */
	private static function hint( \WC_Product $product, float $price ): array {
		$none = array(
			'text' => '',
			'free' => false,
		);

		if ( ! $product->needs_shipping() ) {
			return $none;
		}

		$gap = Cart::ship_gap();

		if ( $gap['goal'] <= 0 || $gap['have'] >= $gap['goal'] ) {
			return $none;
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
				return $none;
			}

			$after = $with['have'];
			$left  = $with['goal'] - $after;

			// A product that does not count toward free delivery moves
			// nothing; saying so would only be noise.
			if ( $after <= $gap['have'] ) {
				return $none;
			}
		} else {
			$after = $gap['have'] + $price;
			$left  = $gap['goal'] - $after;
		}

		if ( $left <= 0 ) {
			return array(
				'text' => esc_html__( 'Adding it gets you free shipping', 'oc-theme' ),
				'free' => true,
			);
		}

		$mid = $gap['mid'];

		if ( $mid['at'] > 0 && $gap['have'] < $mid['at'] && $after >= $mid['at'] ) {
			return array(
				'text' => sprintf(
					/* translators: 1: the lower delivery price. 2: the sum still missing for free shipping. */
					esc_html__( 'Adding it drops delivery to %1$s, with %2$s left for free shipping', 'oc-theme' ),
					'<strong>' . esc_html( Cart::plain_price( wc_price( $mid['price'] ) ) ) . '</strong>',
					'<strong>' . esc_html( Cart::plain_price( wc_price( $left ) ) ) . '</strong>'
				),
				'free' => false,
			);
		}

		return array(
			/* translators: %s: the sum still missing for free shipping. */
			'text' => sprintf( esc_html__( 'After adding it, only %s left for free shipping', 'oc-theme' ), '<strong>' . esc_html( Cart::plain_price( wc_price( $left ) ) ) . '</strong>' ),
			'free' => false,
		);
	}

	/* ---------------------------------------------------------- checkout */

	/**
	 * The checkout offer, above the payment methods.
	 */
	public function checkout_slot(): void {
		echo self::checkout_slot_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built escaped.
	}

	/**
	 * The slot is refreshed with every order-review update, so a tick (or
	 * an untick) shows its result without a reload.
	 *
	 * @param array<string,string> $fragments Fragment map.
	 * @return array<string,string>
	 */
	public function checkout_fragment( array $fragments ): array {
		$fragments['[data-oc-offer-co-slot]'] = self::checkout_slot_html();

		return $fragments;
	}

	/**
	 * The slot, empty or holding the offer; printed even when empty so a
	 * later update has somewhere to land.
	 */
	private static function checkout_slot_html(): string {
		return '<div class="oc-offer-co-slot" data-oc-offer-co-slot>' . self::checkout_html() . '</div>';
	}

	/**
	 * One tick before paying: the best offer for the cart, or the offer
	 * already taken, ticked, so it can be taken back out. With the strip
	 * setting, several — each a picture, a tick, a name and a price.
	 */
	public static function checkout_html(): string {
		if ( ! Offers::on() || ! function_exists( 'WC' ) || ! WC()->cart || WC()->cart->is_empty() ) {
			return '';
		}

		$max = Offers::settings()['co_max'];

		return $max > 1 ? self::checkout_strip( $max ) : self::checkout_one();
	}

	/**
	 * The card's opening tag and its colour.
	 *
	 * @param string $extra More classes.
	 */
	private static function co_open( string $extra = '' ): string {
		$color = (string) ( Cart::settings()['offer_c'] ?? '' );
		$style = '' !== $color ? ' style="--oc-offer-c:' . esc_attr( $color ) . '"' : '';

		return '<div class="oc-offer oc-offer--co' . ( '' !== $extra ? ' ' . $extra : '' ) . '"' . $style . ' data-url-view="' . esc_url( \WC_AJAX::get_endpoint( 'oc_offer_add' ) ) . '">';
	}

	/**
	 * The lines in the cart that came from an offer, by cart key.
	 *
	 * @return array<string,\WC_Product>
	 */
	private static function taken(): array {
		$out = array();

		foreach ( WC()->cart->get_cart() as $key => $item ) {
			if ( Offers::tag( $item[ Offers::TAG ] ?? null ) && $item['data'] instanceof \WC_Product ) {
				$out[ (string) $key ] = $item['data'];
			}
		}

		return $out;
	}

	/**
	 * The picture, as a button that opens the quick pick — the product's
	 * details and its options, with "add" at the foot.
	 *
	 * @param \WC_Product $product The product.
	 * @param string      $wrap    The button's class.
	 */
	private static function co_picture( \WC_Product $product, string $wrap ): string {
		/* translators: %s: product name. */
		$label = sprintf( __( 'Details of %s', 'oc-theme' ), $product->get_name() );

		return '<button type="button" class="' . esc_attr( $wrap ) . '" data-oc-offer-view="' . absint( $product->get_id() ) . '" aria-label="' . esc_attr( $label ) . '">' . $product->get_image( 'woocommerce_thumbnail' ) . '</button>';
	}

	/**
	 * One product, with its details: the single card.
	 */
	private static function checkout_one(): string {
		$decimals = wc_get_price_decimals();
		$add_url  = esc_url( \WC_AJAX::get_endpoint( 'oc_offer_add' ) );
		$rm_url   = esc_url( \WC_AJAX::get_endpoint( 'oc_offer_remove' ) );

		// An offer already in the cart: shown ticked, so one tap takes it out.
		foreach ( self::taken() as $key => $product ) {
			$html  = self::co_open( 'is-taken' );
			$html .= '<label class="oc-offer__tick">';
			$html .= '<input type="checkbox" checked data-oc-offer-co data-key="' . esc_attr( $key ) . '" data-url-remove="' . $rm_url . '" />';
			$html .= '<span class="oc-offer__tickbody">';
			/* translators: %s: product name. */
			$html .= '<strong>' . esc_html( sprintf( __( 'Added to your order: %s', 'oc-theme' ), $product->get_name() ) ) . '</strong>';
			$html .= '<span class="oc-offer__hint">' . esc_html__( 'Untick to take it out again.', 'oc-theme' ) . '</span>';
			$html .= '</span></label>';
			$html .= self::co_picture( $product, 'oc-offer__comedia' );
			$html .= '</div>';

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
		$pick    = $product->is_type( 'variable' ) ? ' data-pick="1"' : '';

		$html  = self::co_open();
		$html .= '<span class="oc-offer__cotitle">' . esc_html__( 'A moment before you pay', 'oc-theme' ) . '</span>';
		$html .= '<label class="oc-offer__tick">';
		$html .= '<input type="checkbox" data-oc-offer-co data-add="' . absint( $product->get_id() ) . '" data-url-add="' . $add_url . '"' . $pick . ' />';
		$html .= '<span class="oc-offer__tickbody">';
		$html .= self::promo_html( $product );

		if ( $deal ) {
			/* translators: 1: product name. 2: percent off. */
			$html .= '<strong>' . esc_html( sprintf( __( 'Add %1$s at %2$d%% off', 'oc-theme' ), $product->get_name(), $offer['pct'] ) ) . '</strong>';
			$price = '<span class="oc-offer__price"><ins>' . wc_price( $now ) . '</ins> <del>' . wc_price( $was ) . '</del></span>';
		} else {
			/* translators: %s: product name. */
			$html .= '<strong>' . esc_html( sprintf( __( 'Add %s', 'oc-theme' ), $product->get_name() ) ) . '</strong>';
			$price = '<span class="oc-offer__price"><ins>' . wc_price( $was ) . '</ins></span>';
		}

		$html .= $price;
		$html .= self::hint_html( $product, $was );
		$html .= '</span></label>';
		$html .= self::co_picture( $product, 'oc-offer__comedia' );
		$html .= '</div>';

		return $html;
	}

	/**
	 * The promotion a product runs — "the second at 20% off" — as a small
	 * line above its name, in the catalogue's sale colours. The engine
	 * drapes the same words over the picture on catalogue pages; the
	 * cards hide that and say it here, where it reads.
	 *
	 * @param \WC_Product $product The product.
	 */
	public static function promo_html( \WC_Product $product ): string {
		$label = (string) apply_filters( 'promeng_product_label', '', $product->get_id() );

		if ( '' === $label ) {
			return '';
		}

		return '<span class="oc-offer__promo" style="' . esc_attr( WooCommerce::flag_colors( 'oc_sale_badge_bg', 'oc_sale_badge_tx' ) ) . '">' . esc_html( $label ) . '</span>';
	}

	/**
	 * Several products in a strip: the ones already taken first, ticked,
	 * then the best offers for the cart up to the limit.
	 *
	 * @param int $max How many at most.
	 */
	private static function checkout_strip( int $max ): string {
		$decimals = wc_get_price_decimals();
		$add_url  = esc_url( \WC_AJAX::get_endpoint( 'oc_offer_add' ) );
		$rm_url   = esc_url( \WC_AJAX::get_endpoint( 'oc_offer_remove' ) );
		$cards    = array();
		$taken    = self::taken();

		foreach ( $taken as $key => $product ) {
			$card  = '<article class="oc-offer__cocard is-taken" role="listitem">';
			$card .= '<span class="oc-offer__cofig">' . self::co_picture( $product, 'oc-offer__coimg' );
			$card .= '<label class="oc-offer__cobox"><input type="checkbox" checked data-oc-offer-co data-key="' . esc_attr( $key ) . '" data-url-remove="' . $rm_url . '" /><span class="oc-offer__cocheck" aria-hidden="true"></span><span class="screen-reader-text">' . esc_html__( 'Untick to take it out again.', 'oc-theme' ) . '</span></label></span>';
			$card .= '<span class="oc-offer__coname">' . esc_html( $product->get_name() ) . '</span>';
			$card .= '<span class="oc-offer__costate">' . esc_html__( 'Added to your order', 'oc-theme' ) . '</span>';
			$card .= '</article>';

			$cards[] = $card;
		}

		$room = $max - count( $cards );

		if ( $room > 0 ) {
			foreach ( Offers::cart_offers( $room, 'checkout' ) as $offer ) {
				$product = $offer['product'];
				$was     = (float) wc_get_price_to_display( $product );
				$now     = $was - Rules::off( $was, $offer['pct'], $decimals );
				$deal    = $offer['pct'] > 0 && $now < $was;
				$pick    = $product->is_type( 'variable' ) ? ' data-pick="1"' : '';
				/* translators: %s: product name. */
				$say = sprintf( __( 'Add %s', 'oc-theme' ), $product->get_name() );

				$card  = '<article class="oc-offer__cocard" role="listitem">';
				$card .= '<span class="oc-offer__cofig">' . self::co_picture( $product, 'oc-offer__coimg' );
				$card .= '<label class="oc-offer__cobox"><input type="checkbox" data-oc-offer-co data-add="' . absint( $product->get_id() ) . '" data-url-add="' . $add_url . '"' . $pick . ' /><span class="oc-offer__cocheck" aria-hidden="true"></span><span class="screen-reader-text">' . esc_html( $say ) . '</span></label>';

				if ( $deal ) {
					/* translators: %d: percent off. */
					$card .= '<span class="oc-offer__codeal">' . esc_html( sprintf( __( '%d%% off', 'oc-theme' ), $offer['pct'] ) ) . '</span>';
				}

				$card .= '</span>';
				$card .= self::promo_html( $product );
				$card .= '<span class="oc-offer__coname">' . esc_html( $product->get_name() ) . '</span>';
				$card .= $deal
					? '<span class="oc-offer__price"><ins>' . wc_price( $now ) . '</ins> <del>' . wc_price( $was ) . '</del></span>'
					: '<span class="oc-offer__price"><ins>' . wc_price( $was ) . '</ins></span>';

				$hint = self::hint( $product, $was );

				if ( $hint['free'] ) {
					$card .= self::hint_html( $product, $was );
				}

				$card .= '</article>';

				$cards[] = $card;
			}
		}

		if ( ! $cards ) {
			return '';
		}

		$html  = self::co_open( 'oc-offer--cos' );
		$html .= '<span class="oc-offer__cotitle">' . esc_html__( 'A moment before you pay', 'oc-theme' ) . '</span>';
		$html .= '<div class="oc-offer__strip" role="list">' . implode( '', $cards ) . '</div>';
		$html .= '</div>';

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
		$id        = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
		$variation = isset( $_POST['variation_id'] ) ? absint( $_POST['variation_id'] ) : 0;
		$place     = isset( $_POST['place'] ) && 'checkout' === $_POST['place'] ? 'checkout' : 'cart';

		// The quick pick posts the chosen options as attribute_* fields.
		$attributes = array();

		foreach ( wp_unslash( $_POST ) as $field => $value ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- each field is sanitized below.
			if ( 0 === strpos( (string) $field, 'attribute_' ) && is_scalar( $value ) ) {
				$attributes[ sanitize_text_field( (string) $field ) ] = sanitize_text_field( (string) $value );
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( ! $attributes && $variation ) {
			$chosen = wc_get_product( $variation );

			if ( $chosen instanceof \WC_Product_Variation ) {
				$attributes = $chosen->get_variation_attributes();
			}
		}

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
			$variation,
			$attributes,
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
