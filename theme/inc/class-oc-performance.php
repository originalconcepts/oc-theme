<?php
/**
 * Front-end weight control.
 *
 * Everything here removes bytes a visitor pays for on every page without
 * ever needing: emoji polyfills, jQuery Migrate, block-editor CSS on a
 * classic theme, Woo's order-attribution tracker, and a payment gateway's
 * assets on pages that cannot pay.
 *
 * @package OC_Theme
 */

declare( strict_types = 1 );

namespace OC\Theme;

defined( 'ABSPATH' ) || exit;

/**
 * Dequeues and detangles — no visual output of its own.
 */
final class Performance {

	/**
	 * Hook in.
	 */
	public function register(): void {
		// The emoji polyfill: an inline detector on every page plus a lazy
		// twemoji download the moment any later DOM change contains an
		// emoji — which held the load event for seconds here.
		add_action( 'init', array( $this, 'drop_emoji' ) );

		// jQuery Migrate exists for pre-3.0 jQuery code; nothing shipped
		// here needs it.
		add_action( 'wp_default_scripts', array( $this, 'drop_migrate' ) );

		// Classic theme: no theme.json styling, no block-editor front CSS.
		// Global styles need their enqueue actions removed — a dequeue is
		// re-done by core; the rest are swept at print time, which also
		// catches anything enqueued through the block-assets pipeline.
		add_action(
			'init',
			static function (): void {
				remove_action( 'wp_enqueue_scripts', 'wp_enqueue_global_styles' );
				remove_action( 'wp_footer', 'wp_enqueue_global_styles', 1 );
			},
			20
		);
		add_action( 'wp_print_styles', array( $this, 'drop_block_css' ), 999 );

		// Woo's origin tracker (sourcebuster.js + inline config).
		add_filter( 'wc_order_attribution_allow_tracking', '__return_false' );

		// Payment gateways belong to the checkout. PayPlus (and friends)
		// enqueue their alert/UI kits sitewide; strip them anywhere a
		// visitor cannot pay. Print time: after every enqueue path ran.
		add_action( 'wp_print_styles', array( $this, 'gateway_assets_checkout_only' ), 999 );
		add_action( 'wp_print_scripts', array( $this, 'gateway_assets_checkout_only' ), 999 );
	}

	/**
	 * All of core's emoji plumbing.
	 */
	public function drop_emoji(): void {
		remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
		remove_action( 'wp_print_styles', 'print_emoji_styles' );
		remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
		remove_action( 'admin_print_styles', 'print_emoji_styles' );
		remove_filter( 'the_content_feed', 'wp_staticize_emoji' );
		remove_filter( 'comment_text_rss', 'wp_staticize_emoji' );
		remove_filter( 'wp_mail', 'wp_staticize_emoji_for_email' );
		add_filter( 'emoji_svg_url', '__return_false' );
	}

	/**
	 * Core jQuery without the Migrate shim.
	 *
	 * @param \WP_Scripts $scripts Core registry.
	 */
	public function drop_migrate( $scripts ): void {
		if ( ! isset( $scripts->registered['jquery'] ) ) {
			return;
		}

		$scripts->registered['jquery']->deps = array_diff(
			$scripts->registered['jquery']->deps,
			array( 'jquery-migrate' )
		);
	}

	/**
	 * Block-editor styling a classic theme never reads.
	 */
	public function drop_block_css(): void {
		foreach ( array( 'global-styles', 'classic-theme-styles', 'wc-blocks-style', 'wc-blocks-style-rtl', 'wc-blocks-vendors-style' ) as $handle ) {
			wp_dequeue_style( $handle );
			wp_deregister_style( $handle );
		}
	}

	/**
	 * Any style or script whose handle or URL smells like a payment
	 * gateway's UI kit is dropped outside the checkout and cart.
	 */
	public function gateway_assets_checkout_only(): void {
		// No WooCommerce, no gateways to sweep — and no is_checkout() to ask.
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		if ( is_checkout() || is_cart() || is_admin() ) {
			return;
		}

		$needles = array( 'payplus', 'alertify' );

		// Woo's order-attribution pair rides along here: same rule (only
		// meaningful where an order can start), same print-time sweep.
		foreach ( array( 'sourcebuster-js', 'wc-order-attribution' ) as $handle ) {
			wp_dequeue_script( $handle );
		}

		foreach ( array( wp_styles(), wp_scripts() ) as $registry ) {
			foreach ( $registry->queue as $handle ) {
				$src = isset( $registry->registered[ $handle ] ) ? (string) $registry->registered[ $handle ]->src : '';

				foreach ( $needles as $needle ) {
					if ( false !== stripos( $handle, $needle ) || false !== stripos( $src, $needle ) ) {
						$registry->dequeue( $handle );
						break;
					}
				}
			}
		}
	}
}
