<?php
/**
 * Shipping: the theme's own delivery pricing, wired into WooCommerce.
 *
 * Three consumers ask one calculator: the shipping method at the checkout,
 * the delivery line on a product page, the free-shipping bar in the cart.
 * This class registers the method and does the translating — WooCommerce
 * packages and products in, calculator parcels out, and reasons into words.
 *
 * @package OC\Theme
 */

declare( strict_types = 1 );

namespace OC\Theme;

use OC\Theme\Shipping\Quote;
use OC\Theme\Shipping\Rules;

defined( 'ABSPATH' ) || exit;

/**
 * Shipping glue.
 */
final class Shipping {

	/**
	 * Hooks.
	 */
	public function register(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		add_action( 'woocommerce_shipping_init', array( $this, 'load_method' ) );
		add_filter( 'woocommerce_shipping_methods', array( $this, 'add_method' ) );
	}

	/**
	 * The method class exists only once WooCommerce's base class does.
	 */
	public function load_method(): void {
		require_once OC_THEME_DIR . '/inc/shipping/class-oc-shipping-method.php';
	}

	/**
	 * Offer the method to zones.
	 *
	 * @param array<string,string> $methods Methods.
	 * @return array<string,string>
	 */
	public function add_method( array $methods ): array {
		if ( class_exists( '\OC\Theme\Shipping\Method' ) ) {
			$methods[ Shipping\Method::ID ] = Shipping\Method::class;
		}

		return $methods;
	}

	/**
	 * Is the theme pricing delivery on this site?
	 */
	public static function enabled(): bool {
		return Rules::enabled();
	}

	/**
	 * A product on its own, as a parcel to wherever the customer says they
	 * are — would it ship free?
	 *
	 * @param \WC_Product $product Product.
	 * @return array<string,mixed> The quote.
	 */
	public static function product_quote( \WC_Product $product ): array {
		$rules = Rules::get();
		$line  = array(
			'subtotal' => (float) wc_get_price_to_display( $product ),
			'group'    => (string) $product->get_shipping_class(),
			'qty'      => 1,
		);

		return Quote::calculate( array( $line ), self::customer_destination(), $rules );
	}

	/**
	 * The cart as a parcel: what free delivery needs and what it has.
	 *
	 * @return array{goal:float,have:float,free:bool,cost:float}
	 */
	public static function cart_progress(): array {
		$rules = Rules::get();
		$cart  = function_exists( 'WC' ) && WC()->cart ? WC()->cart : null;

		if ( ! $cart ) {
			return array(
				'goal' => 0.0,
				'have' => 0.0,
				'free' => false,
				'cost' => 0.0,
			);
		}

		$quote = Quote::calculate( self::lines_from_package( array( 'contents' => $cart->get_cart() ), $rules ), self::customer_destination(), $rules );

		return array(
			'goal' => (float) $quote['threshold'],
			'have' => (float) $quote['eligible'],
			'free' => (bool) $quote['free'],
			'cost' => (float) $quote['cost'],
		);
	}

	/**
	 * A WooCommerce package's contents as calculator lines. Each line's
	 * subtotal is the displayed one — with tax when prices show with tax —
	 * after coupons, unless the rules say coupons do not count.
	 *
	 * @param array<string,mixed> $package Package (or ['contents' => cart items]).
	 * @param array<string,mixed> $rules   Rules.
	 * @return array<int,array{subtotal:float,group:string,qty:int}>
	 */
	public static function lines_from_package( array $package, array $rules ): array {
		$incl  = function_exists( 'wc_tax_enabled' ) && wc_tax_enabled() && 'incl' === get_option( 'woocommerce_tax_display_cart', 'excl' );
		$lines = array();

		foreach ( (array) ( $package['contents'] ?? array() ) as $item ) {
			$product = $item['data'] ?? null;

			if ( ! $product instanceof \WC_Product || ! $product->needs_shipping() ) {
				continue;
			}

			if ( ! empty( $rules['free_ignore_coupons'] ) ) {
				$sum = (float) ( $item['line_subtotal'] ?? 0 ) + ( $incl ? (float) ( $item['line_subtotal_tax'] ?? 0 ) : 0.0 );
			} else {
				$sum = (float) ( $item['line_total'] ?? 0 ) + ( $incl ? (float) ( $item['line_tax'] ?? 0 ) : 0.0 );
			}

			$lines[] = array(
				'subtotal' => $sum,
				'group'    => (string) $product->get_shipping_class(),
				'qty'      => (int) ( $item['quantity'] ?? 1 ),
			);
		}

		return $lines;
	}

	/**
	 * Where a package is going.
	 *
	 * @param array<string,mixed> $package Package.
	 * @return array{country:string,postcode:string,city:string}
	 */
	public static function destination_from_package( array $package ): array {
		$d = (array) ( $package['destination'] ?? array() );

		return array(
			'country'  => (string) ( $d['country'] ?? '' ),
			'postcode' => (string) ( $d['postcode'] ?? '' ),
			'city'     => (string) ( $d['city'] ?? '' ),
		);
	}

	/**
	 * Where the current customer says they are, if anywhere.
	 *
	 * @return array{country:string,postcode:string,city:string}
	 */
	public static function customer_destination(): array {
		$c = function_exists( 'WC' ) && WC()->customer ? WC()->customer : null;

		return array(
			'country'  => $c ? (string) $c->get_shipping_country() : '',
			'postcode' => $c ? (string) $c->get_shipping_postcode() : '',
			'city'     => $c ? (string) $c->get_shipping_city() : '',
		);
	}

	/**
	 * The paid rate's name.
	 *
	 * @param array<string,mixed> $rules Rules.
	 */
	public static function label( array $rules ): string {
		return '' !== (string) $rules['label'] ? (string) $rules['label'] : __( 'Home delivery', 'oc-theme' );
	}

	/**
	 * The free rate's name.
	 *
	 * @param array<string,mixed> $rules Rules.
	 */
	public static function free_label( array $rules ): string {
		return '' !== (string) $rules['free_label'] ? (string) $rules['free_label'] : __( 'Free shipping', 'oc-theme' );
	}

	/**
	 * A quote's reasons in words, for the checkout and the simulator.
	 *
	 * @param array<string,mixed> $quote Quote.
	 * @param array<string,mixed> $rules Rules.
	 */
	public static function explain( array $quote, array $rules ): string {
		$parts = array();

		foreach ( (array) $quote['reasons'] as $reason ) {
			$a = (array) $reason['args'];

			switch ( (string) $reason['code'] ) {
				case 'region':
					/* translators: 1: region name. 2: its delivery price. */
					$parts[] = sprintf( __( '%1$s: delivery %2$s', 'oc-theme' ), (string) $a['name'], wp_strip_all_tags( wc_price( (float) $a['price'] ) ) );
					break;

				case 'free_over':
					/* translators: %s: the sum from which delivery is free. */
					$parts[] = sprintf( __( 'Free over %s', 'oc-theme' ), wp_strip_all_tags( wc_price( (float) $a['threshold'] ) ) );
					break;

				case 'group':
					/* translators: 1: group name. 2: its delivery price. */
					$parts[] = sprintf( __( '%1$s: delivery %2$s, not included in free delivery', 'oc-theme' ), (string) $a['name'], wp_strip_all_tags( wc_price( (float) $a['price'] ) ) );
					break;
			}
		}

		return implode( ' · ', $parts );
	}
}
