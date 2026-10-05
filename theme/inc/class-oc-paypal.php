<?php
/**
 * How PayPal presents itself in this theme.
 *
 * The WooCommerce PayPal Payments plugin is built to be loud: a gold button
 * on every product page, an English name on a Hebrew checkout, and a line of
 * explanation under it that says what the row above already said. A shop
 * running this theme wants PayPal as one payment method among several, named
 * the way the others are named, so the three are evened out here — in the
 * theme, so every site gets the same checkout without anyone re-finding the
 * settings. Each of them yields to a filter for a shop that disagrees.
 *
 * @package OC_Theme
 */

declare( strict_types = 1 );

namespace OC\Theme;

defined( 'ABSPATH' ) || exit;

/**
 * PayPal, made to match the rest of the checkout.
 */
final class Paypal {

	const GATEWAY = 'ppcp-gateway';

	/**
	 * The plugin's own mark, so the row carries PayPal's real logo rather
	 * than a drawing of it.
	 */
	const LOGO = '/woocommerce-paypal-payments/modules/ppcp-wc-gateway/assets/images/paypal.svg';

	/**
	 * Hook in.
	 */
	public function register(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		// Only on the storefront: the plugin's own settings screen keeps
		// showing what is really stored, so nobody is told one thing there
		// and shown another here.
		if ( ! is_admin() ) {
			add_filter( 'option_woocommerce-ppcp-data-styling', array( $this, 'no_product_buttons' ) );
			add_filter( 'option_woocommerce-ppcp-settings', array( $this, 'no_product_buttons_legacy' ) );
		}

		add_filter( 'woocommerce_gateway_title', array( $this, 'title' ), 10, 2 );
		add_filter( 'woocommerce_gateway_description', array( $this, 'description' ), 10, 2 );
		add_filter( 'woocommerce_gateway_icon', array( $this, 'icon' ), 10, 2 );

		// The gateway reads its description straight from its own settings
		// rather than from the property, and offers this filter for it.
		add_filter( 'woocommerce_paypal_payments_gateway_description', array( $this, 'no_description' ) );

		// Belt for the property, for anything that does read it.
		add_filter( 'woocommerce_available_payment_gateways', array( $this, 'quieten' ) );

		// Plugins load before themes, so a filter on the stored settings is
		// already too late — the buttons are switched off where they are drawn.
		add_action( 'wp_enqueue_scripts', array( $this, 'strip_buttons' ), 99 );

		// With no language set, PayPal's SDK guesses from the browser and a
		// Hebrew checkout gets an English button. The site's own language it is.
		add_filter( 'woocommerce_paypal_payments_smart_buttons_locale', array( $this, 'locale' ) );
	}

	/**
	 * The SDK's locale, when the plugin's own setting leaves it open.
	 *
	 * @param string $locale What the plugin would send ('' = let PayPal guess).
	 */
	public function locale( $locale ) {
		if ( '' !== (string) $locale || ! self::on( 'locale' ) ) {
			return $locale;
		}

		return str_replace( '-', '_', (string) get_locale() );
	}

	/**
	 * Nothing under the row, through the plugin's own filter.
	 *
	 * @param string $description Gateway description.
	 */
	public function no_description( $description ) {
		return self::on( 'description' ) ? '' : $description;
	}

	/**
	 * Empty the description on the gateway object, for the rendering that
	 * never passes through woocommerce_gateway_description.
	 *
	 * @param array<string,\WC_Payment_Gateway> $gateways Available gateways.
	 * @return array<string,\WC_Payment_Gateway>
	 */
	public function quieten( $gateways ) {
		if ( ! is_array( $gateways ) || empty( $gateways[ self::GATEWAY ] ) ) {
			return $gateways;
		}

		if ( self::on( 'description' ) ) {
			$gateways[ self::GATEWAY ]->description = '';
		}

		if ( self::on( 'title' ) ) {
			$gateways[ self::GATEWAY ]->title = __( 'Pay with PayPal', 'oc-theme' );
		}

		return $gateways;
	}

	/**
	 * The plugin's buttons are drawn by its own script; with the script gone
	 * the containers stay empty and the stylesheet folds them away.
	 *
	 * On the product page that removes the second buy button. At the
	 * checkout it means PayPal is paid for with the shop's own "Place order"
	 * button like every other method: the gateway, finding no PayPal order
	 * in the session, creates one and sends the shopper to PayPal's page —
	 * the plugin's own fallback for exactly this (process_payment →
	 * PayPalOrderMissingException → redirect). One button, one look.
	 */
	public function strip_buttons(): void {
		$product  = function_exists( 'is_product' ) && is_product() && self::on( 'hide_product_button' );
		$checkout = function_exists( 'is_checkout' ) && is_checkout() && self::on( 'native_button' );

		if ( $product || $checkout ) {
			wp_dequeue_script( 'ppcp-smart-button' );
		}
	}

	/**
	 * Should the theme be deciding this at all?
	 *
	 * @param string $what Which of the three.
	 */
	private static function on( string $what ): bool {
		return (bool) apply_filters( 'oc_paypal_' . $what, true );
	}

	/**
	 * No PayPal button on the product page (PayPal Payments 4 and up).
	 *
	 * A second buy button beside "Add to cart" sends the shopper out of the
	 * shop before they have seen the cart, and it carries its own styling
	 * into a page that was designed without it.
	 *
	 * @param mixed $value Stored styling, one entry per button location.
	 * @return mixed
	 */
	public function no_product_buttons( $value ) {
		if ( ! is_array( $value ) || ! isset( $value['product'] ) || ! self::on( 'hide_product_button' ) ) {
			return $value;
		}

		$value['product']['enabled'] = false;

		return $value;
	}

	/**
	 * The same, for the versions that kept a list of locations instead.
	 *
	 * @param mixed $value Stored settings.
	 * @return mixed
	 */
	public function no_product_buttons_legacy( $value ) {
		if ( ! is_array( $value ) || empty( $value['smart_button_locations'] ) || ! is_array( $value['smart_button_locations'] ) || ! self::on( 'hide_product_button' ) ) {
			return $value;
		}

		$value['smart_button_locations'] = array_values( array_diff( $value['smart_button_locations'], array( 'product' ) ) );

		return $value;
	}

	/**
	 * "Pay with PayPal", in the language the rest of the rows speak.
	 *
	 * @param string $title Gateway title.
	 * @param string $id    Gateway id.
	 */
	public function title( $title, $id = '' ) {
		if ( self::GATEWAY !== $id || ! self::on( 'title' ) ) {
			return $title;
		}

		return __( 'Pay with PayPal', 'oc-theme' );
	}

	/**
	 * Nothing under the row: "Pay via PayPal" only repeats the name.
	 *
	 * @param string $description Gateway description.
	 * @param string $id          Gateway id.
	 */
	public function description( $description, $id = '' ) {
		if ( self::GATEWAY !== $id || ! self::on( 'description' ) ) {
			return $description;
		}

		return '';
	}

	/**
	 * The PayPal mark, where every other method shows its own.
	 *
	 * @param string $icon Icon HTML.
	 * @param string $id   Gateway id.
	 */
	public function icon( $icon, $id = '' ) {
		if ( self::GATEWAY !== $id || ! self::on( 'icon' ) ) {
			return $icon;
		}

		if ( '' !== trim( (string) $icon ) || ! file_exists( WP_PLUGIN_DIR . self::LOGO ) ) {
			return $icon;
		}

		return '<img src="' . esc_url( plugins_url( ltrim( self::LOGO, '/' ) ) ) . '" alt="PayPal" />';
	}
}
