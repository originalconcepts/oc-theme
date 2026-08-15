<?php
/**
 * WooCommerce integration.
 *
 * Hooks only — no template overrides. The old theme carried 37 of them
 * declaring @version between 1.6.4 and 9.3.0 against a WooCommerce 11 install,
 * which is what made every WooCommerce update a risk to checkout on every site
 * at once (DECISIONS.md #7).
 *
 * @package OC_Theme
 */

declare( strict_types = 1 );

namespace OC\Theme;

defined( 'ABSPATH' ) || exit;

/**
 * Shop behaviour, expressed through WooCommerce's own hooks.
 */
final class WooCommerce {

	/**
	 * Hook in, but only when WooCommerce is present.
	 */
	public function register(): void {
		add_action( 'before_woocommerce_init', array( $this, 'declare_compatibility' ) );

		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		// Our own wrappers instead of the default sidebar-shaped ones.
		remove_action( 'woocommerce_before_main_content', 'woocommerce_output_content_wrapper', 10 );
		remove_action( 'woocommerce_after_main_content', 'woocommerce_output_content_wrapper_end', 10 );
		add_action( 'woocommerce_before_main_content', array( $this, 'open_wrapper' ), 10 );
		add_action( 'woocommerce_after_main_content', array( $this, 'close_wrapper' ), 10 );

		// The default sidebar is not part of this theme's layout.
		remove_action( 'woocommerce_sidebar', 'woocommerce_get_sidebar', 10 );

		add_filter( 'loop_shop_columns', array( $this, 'columns' ) );
		add_filter( 'loop_shop_per_page', array( $this, 'per_page' ) );
		add_filter( 'woocommerce_product_thumbnails_columns', array( $this, 'gallery_columns' ) );

		// Breadcrumb position is a setting, so we place it ourselves.
		remove_action( 'woocommerce_before_main_content', 'woocommerce_breadcrumb', 20 );
		add_action( 'oc_before_page_title', 'woocommerce_breadcrumb', 10 );

		add_filter( 'woocommerce_breadcrumb_defaults', array( $this, 'breadcrumb_defaults' ) );
		add_filter( 'body_class', array( $this, 'body_class' ) );

		add_filter( 'woocommerce_sale_flash', array( $this, 'sale_badge' ), 10, 3 );
		add_action( 'woocommerce_after_shop_loop_item_title', array( $this, 'card_excerpt' ), 8 );
	}

	/**
	 * Declare support for High-Performance Order Storage and the cart/checkout
	 * blocks. Without this WooCommerce shows an incompatibility warning on
	 * every site.
	 */
	public function declare_compatibility(): void {
		if ( ! class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			return;
		}

		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
			'custom_order_tables',
			OC_THEME_DIR . '/functions.php',
			true
		);
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
			'cart_checkout_blocks',
			OC_THEME_DIR . '/functions.php',
			true
		);
	}

	/**
	 * Open the shop content wrapper.
	 */
	public function open_wrapper(): void {
		echo '<main id="main" class="site-main shop-main">';
		do_action( 'oc_before_page_title' );
	}

	/**
	 * Close the shop content wrapper.
	 */
	public function close_wrapper(): void {
		echo '</main>';
	}

	/**
	 * Products per row.
	 *
	 * @return int
	 */
	public function columns(): int {
		return max( 1, (int) get_theme_mod( 'oc_catalog_cols', 4 ) );
	}

	/**
	 * Products per page. -1 shows everything.
	 *
	 * @return int
	 */
	public function per_page(): int {
		$value = (int) get_theme_mod( 'oc_catalog_per_page', 24 );
		return 0 === $value ? 24 : $value;
	}

	/**
	 * Gallery thumbnail columns.
	 *
	 * @return int
	 */
	public function gallery_columns(): int {
		return max( 1, (int) get_theme_mod( 'oc_gallery_thumbs_max', 5 ) );
	}

	/**
	 * Breadcrumb separator and home label.
	 *
	 * @param array $defaults Breadcrumb defaults.
	 * @return array
	 */
	public function breadcrumb_defaults( array $defaults ): array {
		$defaults['delimiter']   = '<span class="sep" aria-hidden="true">/</span>';
		$defaults['wrap_before'] = '<nav class="oc-breadcrumb" aria-label="' . esc_attr__( 'Breadcrumb', 'oc-theme' ) . '">';
		$defaults['wrap_after']  = '</nav>';
		$defaults['home']        = __( 'Home', 'oc-theme' );

		return $defaults;
	}

	/**
	 * Expose layout choices to CSS.
	 *
	 * @param array $classes Body classes.
	 * @return array
	 */
	public function body_class( array $classes ): array {
		$classes[] = 'oc-cols-' . $this->columns();
		$classes[] = 'oc-cols-m-' . max( 1, (int) get_theme_mod( 'oc_catalog_cols_mobile', 2 ) );
		$classes[] = 'oc-card-' . sanitize_html_class( (string) get_theme_mod( 'oc_card_preset', 'classic' ) );
		$classes[] = 'oc-atc-' . sanitize_html_class( (string) get_theme_mod( 'oc_card_atc', 'always' ) );
		$classes[] = 'oc-crumbs-' . sanitize_html_class( (string) get_theme_mod( 'oc_breadcrumbs_pos', 'above' ) );

		if ( 'center' === get_theme_mod( 'oc_catalog_title_align', 'start' ) ) {
			$classes[] = 'oc-title-center';
		}

		return $classes;
	}

	/**
	 * Sale badge: none, percent off, or WooCommerce's text.
	 *
	 * @param string      $html    Badge markup.
	 * @param \WP_Post    $post    Product post.
	 * @param \WC_Product $product Product.
	 * @return string
	 */
	public function sale_badge( $html, $post, $product ): string {
		$mode = (string) get_theme_mod( 'oc_card_sale_badge', 'percent' );

		if ( 'none' === $mode ) {
			return '';
		}

		if ( 'percent' === $mode && $product instanceof \WC_Product ) {
			$regular = (float) $product->get_regular_price();
			$sale    = (float) $product->get_sale_price();

			if ( $regular > 0 && $sale > 0 && $sale < $regular ) {
				$percent = round( ( 1 - $sale / $regular ) * 100 );

				return sprintf(
					'<span class="onsale oc-sale-percent">‎-%s%%</span>',
					esc_html( (string) $percent )
				);
			}
		}

		return (string) $html;
	}

	/**
	 * Optional short description on the card.
	 */
	public function card_excerpt(): void {
		if ( ! get_theme_mod( 'oc_card_excerpt', false ) ) {
			return;
		}

		global $product;

		if ( ! $product instanceof \WC_Product ) {
			return;
		}

		$text = wp_strip_all_tags( (string) $product->get_short_description() );

		if ( '' === $text ) {
			return;
		}

		printf( '<p class="oc-card-excerpt">%s</p>', esc_html( wp_trim_words( $text, 14 ) ) );
	}
}
