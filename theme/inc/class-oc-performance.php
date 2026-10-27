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

		// jQuery is never deferred: core downgrades the strategy when a
		// REGISTERED dependent cannot defer, but a script enqueued mid-page
		// (Woo's variation template enqueues wp-util while the content
		// renders) arrives after the header already printed jQuery deferred,
		// executes first, and dies with "jQuery is not defined". It goes to
		// the footer instead — a plain blocking script at the end of the
		// page, which the first paint does not wait for, and every
		// dependent follows it there, mid-page enqueues included, so the
		// order holds.
		add_action( 'wp_enqueue_scripts', array( $this, 'jquery_last' ), PHP_INT_MAX );
		add_action( 'wp_print_scripts', array( $this, 'jquery_last' ), 0 );

		// Woo's origin tracker (sourcebuster.js + inline config).
		add_filter( 'wc_order_attribution_allow_tracking', '__return_false' );

		// Payment gateways belong to the checkout. PayPlus (and friends)
		// enqueue their alert/UI kits sitewide; strip them anywhere a
		// visitor cannot pay. Print time: after every enqueue path ran.
		add_action( 'wp_print_styles', array( $this, 'gateway_assets_checkout_only' ), 999 );
		add_action( 'wp_print_scripts', array( $this, 'gateway_assets_checkout_only' ), 999 );

		// Styles for things that float in a corner and are never part of
		// the first screen's layout load without holding the first paint.
		add_filter( 'style_loader_tag', array( $this, 'late_styles' ), 10, 2 );

		// Third-party kits that only matter once the visitor moves wait for
		// that move (theme.js hands them over): PayPal's product-page
		// button pulls its SDK, a fraud pixel and a handful of cookies from
		// three paypal.com hosts the moment the page opens.
		add_filter( 'script_loader_tag', array( $this, 'late_scripts' ), 10, 3 );
	}

	/**
	 * Script handles that wait for the visitor's first move on a product
	 * page. The script tag is printed with its address in data-oc-late and
	 * an inert type; theme.js turns them back into real scripts, in order,
	 * on the first pointer, touch, scroll or key — or after eight seconds,
	 * for a visitor who only reads.
	 *
	 * @return string[]
	 */
	public static function late_script_handles(): array {
		if ( ! function_exists( 'is_product' ) || ! is_product() ) {
			return array();
		}

		/**
		 * Script handles that load on the visitor's first move.
		 *
		 * @param string[] $handles Handles.
		 */
		return (array) apply_filters( 'oc_late_script_handles', array( 'wc-ppcp-sdk-v6-boot', 'ppcp-fraudnet', 'ppcp-smart-button' ) );
	}

	/**
	 * Plugin scripts that may run after the page is parsed.
	 *
	 * @return string[]
	 */
	public static function defer_script_handles(): array {
		/**
		 * Script handles given the defer attribute.
		 *
		 * @param string[] $handles Handles.
		 */
		return (array) apply_filters( 'oc_defer_script_handles', array( 'ocai' ) );
	}

	/**
	 * Rewrite a late script's tag so the browser keeps it but does not run it.
	 *
	 * @param string $tag    The script tag.
	 * @param string $handle Script handle.
	 * @param string $src    Script address.
	 */
	public function late_scripts( $tag, $handle, $src ): string {
		$tag = (string) $tag;

		if ( is_admin() || '' === (string) $src ) {
			return $tag;
		}

		// A plugin script printed plain at the end of the page still stops
		// the parser while it downloads and runs — HiiAI's 35 KB cost the
		// first paint half a second on a phone line. Deferred, it runs
		// once the page is parsed, where its chat button lives anyway.
		if ( in_array( (string) $handle, self::defer_script_handles(), true ) && ! preg_match( '/\s(?:defer|async)\b/i', $tag ) ) {
			return (string) preg_replace( '/<script\b/i', '<script defer', $tag, 1 );
		}

		if ( ! in_array( (string) $handle, self::late_script_handles(), true ) ) {
			return $tag;
		}

		// Only the tag that carries the address; WordPress prints the
		// inline "before/after/extra" pieces as their own tags.
		return (string) preg_replace_callback(
			'/<script\b([^>]*)\ssrc=([\'"])([^\'"]+)\2([^>]*)><\/script>/i',
			static function ( array $m ): string {
				$attrs = $m[1] . $m[4];
				$attrs = (string) preg_replace( '/\s(?:type|defer|async)(?:=[\'"][^\'"]*[\'"])?/i', '', $attrs );
				return '<script type="text/oc-late"' . $attrs . ' data-oc-late="' . esc_attr( $m[3] ) . '"></script>';
			},
			$tag
		);
	}

	/**
	 * Handles whose stylesheet may arrive after the first paint: the HiiAI
	 * chat button (OC AI Shop Assistant) sits fixed in a corner and its
	 * markup is printed at the very end of the page, so nothing on the
	 * first screen waits for, or moves with, its 10 KB.
	 *
	 * @return string[]
	 */
	public static function late_handles(): array {
		/**
		 * Stylesheet handles loaded without blocking the first paint.
		 *
		 * @param string[] $handles Handles.
		 */
		return (array) apply_filters( 'oc_late_style_handles', array( 'ocai' ) );
	}

	/**
	 * The print-media swap: the browser fetches a print stylesheet without
	 * blocking, and the onload turns it on for the screen.
	 *
	 * @param string $tag    The link tag.
	 * @param string $handle Style handle.
	 */
	public function late_styles( $tag, $handle ): string {
		$tag = (string) $tag;

		if ( is_admin() || ! in_array( (string) $handle, self::late_handles(), true ) || false === strpos( $tag, "media='all'" ) ) {
			return $tag;
		}

		return str_replace( "media='all'", "media='print' onload=\"this.media='all'\"", $tag ) . '<noscript>' . $tag . '</noscript>';
	}

	/**
	 * JQuery (core, and Migrate if a plugin re-adds it) prints at the end of
	 * the page. Core moves every script that depends on it after it.
	 */
	public function jquery_last(): void {
		if ( is_admin() ) {
			return;
		}

		/**
		 * Whether jQuery prints at the end of the page instead of the head.
		 *
		 * @param bool $late Default true.
		 */
		if ( ! apply_filters( 'oc_jquery_in_footer', true ) ) {
			return;
		}

		// Core places a dependency no later than its earliest dependent, so
		// jQuery only moves if everything that needs it moves with it: the
		// whole registry is walked, and whatever leads back to jQuery goes
		// to the footer. Run again right before the head prints, for a
		// script enqueued after the enqueue hook.
		$scripts = wp_scripts();
		$memo    = array();
		$needs   = static function ( string $handle ) use ( $scripts, &$memo, &$needs ): bool {
			if ( isset( $memo[ $handle ] ) ) {
				return $memo[ $handle ];
			}
			$memo[ $handle ] = false; // Guards a cycle.
			if ( in_array( $handle, array( 'jquery', 'jquery-core', 'jquery-migrate' ), true ) ) {
				$memo[ $handle ] = true;
				return true;
			}
			$dep = $scripts->registered[ $handle ] ?? null;
			foreach ( (array) ( $dep ? $dep->deps : array() ) as $d ) {
				if ( $needs( (string) $d ) ) {
					$memo[ $handle ] = true;
					return true;
				}
			}
			return false;
		};

		foreach ( array_keys( $scripts->registered ) as $handle ) {
			if ( $needs( (string) $handle ) ) {
				$scripts->add_data( (string) $handle, 'group', 1 );
			}
		}
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
