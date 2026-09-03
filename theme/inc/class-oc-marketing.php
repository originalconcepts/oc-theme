<?php
/**
 * Marketing: the glue between the page, the browser script and the
 * networks.
 *
 * Enqueues the one small script — deferred, after everything — hands it
 * the IDs, the page's context and the shopper's matching data, and prints
 * the server's event queue and the consent banner in the footer.
 *
 * @package OC\Theme
 */

declare( strict_types = 1 );

namespace OC\Theme;

use OC\Theme\Marketing\Consent;
use OC\Theme\Marketing\Events;
use OC\Theme\Marketing\Page;
use OC\Theme\Marketing\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Marketing glue.
 */
final class Marketing {

	/**
	 * Hooks.
	 */
	public function register(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		( new Page() )->register();

		add_action( 'wp_enqueue_scripts', array( $this, 'assets' ), 30 );
		add_action( 'wp_footer', array( $this, 'footer' ), 40 );
	}

	/**
	 * The script and its config.
	 */
	public function assets(): void {
		if ( is_admin() || ! Settings::live() ) {
			return;
		}

		$s = Settings::get();

		wp_enqueue_script( 'oc-marketing', OC_THEME_URI . oc_asset_min( '/assets/js/marketing.js' ), array(), oc_asset_version( '/assets/js/marketing.js' ), array( 'in_footer' => true, 'strategy' => 'defer' ) );

		wp_localize_script(
			'oc-marketing',
			'ocMkt',
			array(
				'fb'            => $s['fb']['pixel'],
				'ga4'           => $s['ga4']['id'],
				'gads'          => $s['gads']['id'],
				'gadsLabel'     => $s['gads']['label'],
				'gtm'           => $s['gtm']['id'],
				'tiktok'        => $s['tiktok']['pixel'],
				'consentMode'   => Consent::mode(),
				'consentStored' => Consent::stored(),
				'events'        => $s['events'],
				'currency'      => get_woocommerce_currency(),
				'rest'          => esc_url_raw( rest_url() ),
				'pageId'        => Events::id( 'pv' ),
				'page'          => self::page_context(),
				'user'          => self::user_match(),
			)
		);
	}

	/**
	 * What kind of page this is, and the product or cart on it.
	 *
	 * @return array<string,mixed>
	 */
	private static function page_context(): array {
		if ( is_product() ) {
			$product = wc_get_product( get_queried_object_id() );

			return array(
				'type' => 'product',
				'item' => $product instanceof \WC_Product ? Page::item( $product ) : null,
			);
		}

		if ( function_exists( 'is_checkout' ) && is_checkout() && ! is_order_received_page() ) {
			return array(
				'type' => 'checkout',
				'cart' => WC()->cart && ! WC()->cart->is_empty() ? Page::cart_data() : array(),
			);
		}

		if ( function_exists( 'is_order_received_page' ) && is_order_received_page() ) {
			return array( 'type' => 'thankyou' );
		}

		if ( is_product_category() ) {
			return array( 'type' => 'category' );
		}

		return array( 'type' => is_search() ? 'search' : 'other' );
	}

	/**
	 * The signed-in shopper, for the networks' advanced matching. Plain
	 * values: the pixels hash them themselves before anything leaves
	 * the browser.
	 *
	 * @return array<string,string>
	 */
	private static function user_match(): array {
		$uid = get_current_user_id();

		if ( $uid <= 0 || ! function_exists( 'WC' ) || ! WC()->customer ) {
			return array();
		}

		$c = WC()->customer;

		return array_filter(
			array(
				'em'          => strtolower( trim( (string) $c->get_email() ) ),
				'ph'          => Marketing\Payload::norm_phone( (string) $c->get_billing_phone(), (string) ( $c->get_billing_country() ? $c->get_billing_country() : 'IL' ) ),
				'fn'          => strtolower( trim( (string) $c->get_first_name() ) ),
				'ln'          => strtolower( trim( (string) $c->get_last_name() ) ),
				'ct'          => strtolower( trim( (string) $c->get_billing_city() ) ),
				'zp'          => strtolower( preg_replace( '/\s+/', '', (string) $c->get_billing_postcode() ) ),
				'country'     => strtolower( (string) $c->get_billing_country() ),
				'external_id' => (string) $uid,
			)
		);
	}

	/**
	 * The queue and the banner.
	 */
	public function footer(): void {
		if ( is_admin() || ! Settings::live() ) {
			return;
		}

		$queue = Events::drain();

		if ( $queue ) {
			echo '<script>window.ocq=(window.ocq||[]).concat(' . wp_json_encode( $queue ) . ');</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_json_encode() is the escape.
		}

		Consent::banner();
	}
}
