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

		// Breadcrumb position is a setting, so we place it ourselves. WooCommerce
		// ships its own breadcrumb trail — no SEO plugin needed for this.
		remove_action( 'woocommerce_before_main_content', 'woocommerce_breadcrumb', 20 );

		$crumbs = (string) get_theme_mod( 'oc_breadcrumbs_pos', 'above' );
		if ( 'above' === $crumbs ) {
			add_action( 'oc_before_page_title', 'woocommerce_breadcrumb', 10 );
		} elseif ( 'below' === $crumbs ) {
			// Inside the products header, right under the page title.
			add_action( 'woocommerce_archive_description', 'woocommerce_breadcrumb', 5 );
		}

		add_filter( 'woocommerce_breadcrumb_defaults', array( $this, 'breadcrumb_defaults' ) );
		add_filter( 'body_class', array( $this, 'body_class' ) );

		add_filter( 'woocommerce_sale_flash', array( $this, 'sale_badge' ), 10, 3 );
		add_action( 'woocommerce_after_shop_loop_item_title', array( $this, 'card_excerpt' ), 8 );

		// Card image behaviour (single / hover swap / scrollable gallery).
		if ( 'single' !== get_theme_mod( 'oc_card_image_mode', 'single' ) ) {
			remove_action( 'woocommerce_before_shop_loop_item_title', 'woocommerce_template_loop_product_thumbnail', 10 );
			add_action( 'woocommerce_before_shop_loop_item_title', array( $this, 'card_media' ), 10 );
		}

		if ( ! get_theme_mod( 'oc_product_related', true ) ) {
			remove_action( 'woocommerce_after_single_product_summary', 'woocommerce_output_related_products', 20 );
		}

		if ( get_theme_mod( 'oc_product_sticky_atc', true ) ) {
			add_action( 'woocommerce_after_single_product', array( $this, 'sticky_bar' ) );
		}
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

		if ( is_product() ) {
			$classes[] = 'oc-gallery-' . sanitize_html_class( (string) get_theme_mod( 'oc_gallery_preset', 'thumbs-side' ) );
			$classes[] = 'oc-side-' . sanitize_html_class( (string) get_theme_mod( 'oc_product_layout_side', 'gallery-start' ) );
			$classes[] = 'oc-tabs-' . sanitize_html_class( (string) get_theme_mod( 'oc_product_tabs', 'accordion' ) );
			$classes[] = 'oc-wide-' . sanitize_html_class( (string) get_theme_mod( 'oc_gallery_mosaic_wide_pos', 'end' ) );
		}

		return $classes;
	}

	/**
	 * Card media: featured image plus gallery images, as a hover pair or a
	 * scroll-snap strip. Replaces the old plugin's Slick-based card slider
	 * with no JavaScript library at all.
	 */
	public function card_media(): void {
		global $product;

		if ( ! $product instanceof \WC_Product ) {
			return;
		}

		$mode = (string) get_theme_mod( 'oc_card_image_mode', 'single' );
		$max  = 'hover' === $mode ? 2 : max( 2, (int) get_theme_mod( 'oc_card_gallery_max', 4 ) );

		$ids = array_merge(
			array( (int) $product->get_image_id() ),
			array_map( 'intval', $product->get_gallery_image_ids() )
		);
		$ids = array_values( array_unique( array_filter( $ids ) ) );
		$ids = array_slice( $ids, 0, $max );

		if ( count( $ids ) < 2 ) {
			// Nothing to swap or scroll — fall back to the standard image.
			woocommerce_template_loop_product_thumbnail();
			return;
		}

		printf( '<div class="oc-card-media oc-card-media--%s">', esc_attr( $mode ) );
		echo '<div class="oc-card-media__strip" aria-label="' . esc_attr__( 'Product images', 'oc-theme' ) . '">';

		foreach ( $ids as $i => $id ) {
			printf( '<figure class="oc-card-media__item%s">', 0 === $i ? ' is-first' : '' );
			echo wp_get_attachment_image( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core-generated markup.
				$id,
				'woocommerce_thumbnail',
				false,
				array( 'loading' => 0 === $i ? 'eager' : 'lazy' )
			);
			echo '</figure>';
		}

		echo '</div>';

		if ( 'gallery' === $mode ) {
			echo '<span class="oc-card-media__dots" aria-hidden="true">';
			foreach ( $ids as $i => $id ) {
				printf( '<i%s></i>', 0 === $i ? ' class="is-on"' : '' );
			}
			echo '</span>';
		}

		echo '</div>';
	}

	/**
	 * Sticky add-to-cart bar markup. Shown by JS once the buy form scrolls
	 * out of view; the button proxies a click to the real form.
	 */
	public function sticky_bar(): void {
		global $product;

		if ( ! $product instanceof \WC_Product ) {
			return;
		}

		?>
		<div class="oc-sticky-atc" data-oc-sticky-atc hidden>
			<div class="oc-sticky-atc__inner">
				<?php echo wp_get_attachment_image( (int) $product->get_image_id(), 'thumbnail', false, array( 'class' => 'oc-sticky-atc__thumb' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core-generated markup. ?>
				<span class="oc-sticky-atc__title"><?php echo esc_html( $product->get_name() ); ?></span>
				<span class="oc-sticky-atc__price"><?php echo wp_kses_post( $product->get_price_html() ); ?></span>
				<button type="button" class="button oc-sticky-atc__btn">
					<?php echo esc_html( $product->single_add_to_cart_text() ); ?>
				</button>
			</div>
		</div>
		<?php
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
