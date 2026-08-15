<?php
/**
 * WooCommerce integration.
 *
 * Hooks only — no template overrides (DECISIONS.md #7).
 *
 * Anything that depends on a theme setting is decided in runtime_hooks() on
 * the `wp` action, not at load time. The Customizer preview injects changed
 * values only at wp_loaded, so a get_theme_mod() read while the theme boots
 * sees stale values in the preview — which is exactly the class of bug the
 * first QA round shipped.
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
	 * Static wiring only. Setting-dependent wiring lives in runtime_hooks().
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
		remove_action( 'woocommerce_sidebar', 'woocommerce_get_sidebar', 10 );

		add_filter( 'loop_shop_columns', array( $this, 'columns' ) );
		add_filter( 'loop_shop_per_page', array( $this, 'per_page' ) );
		add_filter( 'woocommerce_product_thumbnails_columns', array( $this, 'gallery_columns' ) );
		add_filter( 'woocommerce_breadcrumb_defaults', array( $this, 'breadcrumb_defaults' ) );
		add_filter( 'body_class', array( $this, 'body_class' ) );
		add_filter( 'woocommerce_sale_flash', array( $this, 'sale_badge' ), 10, 3 );
		add_action( 'woocommerce_after_shop_loop_item_title', array( $this, 'card_excerpt' ), 8 );

		// One markup path for every card image mode, including 'single'.
		remove_action( 'woocommerce_before_shop_loop_item_title', 'woocommerce_template_loop_product_thumbnail', 10 );
		add_action( 'woocommerce_before_shop_loop_item_title', array( $this, 'card_media' ), 10 );

		// Breadcrumb: the position is read inside the renderer, so one hook per
		// location is enough and the preview always matches.
		remove_action( 'woocommerce_before_main_content', 'woocommerce_breadcrumb', 20 );
		add_action( 'oc_before_page_title', array( $this, 'crumbs_above' ), 10 );
		add_action( 'woocommerce_archive_description', array( $this, 'crumbs_below' ), 5 );
		add_action( 'woocommerce_single_product_summary', array( $this, 'crumbs_below' ), 6 );

		// Section headings follow their title settings.
		add_filter( 'woocommerce_product_related_products_heading', array( $this, 'related_heading' ) );
		add_filter( 'woocommerce_product_upsells_products_heading', array( $this, 'upsells_heading' ) );

		add_action( 'wp', array( $this, 'runtime_hooks' ) );
	}

	/**
	 * Setting-dependent wiring, evaluated after Customizer preview values are
	 * live.
	 */
	public function runtime_hooks(): void {
		// Gallery machinery follows the preset: the thumbnail presets ride
		// WooCommerce's slider, grid and mosaic lay images out flat.
		$gallery = (string) get_theme_mod( 'oc_gallery_preset', 'thumbs-side' );
		if ( in_array( $gallery, array( 'thumbs-side', 'thumbs-under' ), true ) ) {
			add_theme_support( 'wc-product-gallery-slider' );
		} else {
			remove_theme_support( 'wc-product-gallery-slider' );
		}

		if ( get_theme_mod( 'oc_gallery_lightbox', true ) ) {
			add_theme_support( 'wc-product-gallery-lightbox' );
		} else {
			remove_theme_support( 'wc-product-gallery-lightbox' );
		}

		if ( get_theme_mod( 'oc_gallery_zoom', true ) ) {
			add_theme_support( 'wc-product-gallery-zoom' );
		} else {
			remove_theme_support( 'wc-product-gallery-zoom' );
		}

		if ( ! get_theme_mod( 'oc_product_related', true ) ) {
			remove_action( 'woocommerce_after_single_product_summary', 'woocommerce_output_related_products', 20 );
		}

		if ( ! get_theme_mod( 'oc_product_upsells', true ) ) {
			remove_action( 'woocommerce_after_single_product_summary', 'woocommerce_upsell_display', 15 );
		}

		if ( get_theme_mod( 'oc_product_sticky_atc', true ) ) {
			add_action( 'woocommerce_after_single_product', array( $this, 'sticky_bar' ) );
		}
	}

	/**
	 * Declare HPOS and cart/checkout-blocks compatibility.
	 */
	public function declare_compatibility(): void {
		if ( ! class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			return;
		}

		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', OC_THEME_DIR . '/functions.php', true );
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', OC_THEME_DIR . '/functions.php', true );
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
	 * Breadcrumb when the position is "above the title".
	 */
	public function crumbs_above(): void {
		if ( 'above' === get_theme_mod( 'oc_breadcrumbs_pos', 'above' ) ) {
			woocommerce_breadcrumb();
		}
	}

	/**
	 * Breadcrumb when the position is "below the title".
	 */
	public function crumbs_below(): void {
		if ( 'below' === get_theme_mod( 'oc_breadcrumbs_pos', 'above' ) ) {
			woocommerce_breadcrumb();
		}
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

		if ( 'center' === get_theme_mod( 'oc_catalog_title_align', 'start' ) ) {
			$classes[] = 'oc-title-center';
		}

		if ( 'center' === get_theme_mod( 'oc_products_heading_align', 'start' ) ) {
			$classes[] = 'oc-headings-center';
		}

		if ( is_product() ) {
			$classes[] = 'oc-gallery-' . sanitize_html_class( (string) get_theme_mod( 'oc_gallery_preset', 'thumbs-side' ) );
			$classes[] = 'oc-side-' . sanitize_html_class( (string) get_theme_mod( 'oc_product_layout_side', 'gallery-start' ) );
			$classes[] = 'oc-tabs-' . sanitize_html_class( (string) get_theme_mod( 'oc_product_tabs', 'accordion' ) );
			$classes[] = 'oc-tabspos-' . sanitize_html_class( (string) get_theme_mod( 'oc_product_tabs_pos', 'below' ) );
			$classes[] = 'oc-wide-' . sanitize_html_class( (string) get_theme_mod( 'oc_gallery_mosaic_wide_pos', 'end' ) );
		}

		return $classes;
	}

	/**
	 * Card media for every image mode. One markup path — so the ratio, radius
	 * and behaviour styles always have the same target.
	 */
	public function card_media(): void {
		global $product;

		if ( ! $product instanceof \WC_Product ) {
			return;
		}

		$mode = (string) get_theme_mod( 'oc_card_image_mode', 'single' );
		$max  = 'gallery' === $mode ? max( 2, (int) get_theme_mod( 'oc_card_gallery_max', 4 ) ) : 2;

		$ids = array_merge(
			array( (int) $product->get_image_id() ),
			array_map( 'intval', $product->get_gallery_image_ids() )
		);
		$ids = array_values( array_unique( array_filter( $ids ) ) );

		if ( 'single' === $mode || count( $ids ) < 2 ) {
			$ids  = array_slice( $ids, 0, 1 );
			$mode = 'single';
		} else {
			$ids = array_slice( $ids, 0, $max );
		}

		if ( empty( $ids ) ) {
			// No image at all — let WooCommerce print its placeholder.
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
	 * Sale badge: none, percent off, or WooCommerce's text. Variable products
	 * report the best discount across their variations.
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
			$percent = $this->discount_percent( $product );

			if ( $percent > 0 ) {
				return sprintf(
					'<span class="onsale oc-sale-percent">‎-%s%%</span>',
					esc_html( (string) $percent )
				);
			}
		}

		return (string) $html;
	}

	/**
	 * Largest discount a product offers.
	 *
	 * @param \WC_Product $product Product.
	 * @return int Percent, 0 when not computable.
	 */
	private function discount_percent( \WC_Product $product ): int {
		$pairs = array();

		if ( $product->is_type( 'variable' ) && $product instanceof \WC_Product_Variable ) {
			$prices = $product->get_variation_prices();
			foreach ( (array) $prices['regular_price'] as $id => $regular ) {
				$pairs[] = array( (float) $regular, (float) ( $prices['sale_price'][ $id ] ?? 0 ) );
			}
		} else {
			$pairs[] = array( (float) $product->get_regular_price(), (float) $product->get_sale_price() );
		}

		$best = 0;
		foreach ( $pairs as $pair ) {
			list( $regular, $sale ) = $pair;
			if ( $regular > 0 && $sale > 0 && $sale < $regular ) {
				$best = max( $best, (int) round( ( 1 - $sale / $regular ) * 100 ) );
			}
		}

		return $best;
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

	/**
	 * "Similar products" heading, overridable per site.
	 *
	 * @param string $heading Default heading.
	 * @return string
	 */
	public function related_heading( $heading ): string {
		$custom = trim( (string) get_theme_mod( 'oc_related_title', '' ) );
		return '' !== $custom ? $custom : (string) $heading;
	}

	/**
	 * "Complementary products" heading, overridable per site.
	 *
	 * @param string $heading Default heading.
	 * @return string
	 */
	public function upsells_heading( $heading ): string {
		$custom = trim( (string) get_theme_mod( 'oc_upsells_title', '' ) );
		return '' !== $custom ? $custom : (string) $heading;
	}

	/**
	 * Sticky add-to-cart bar markup.
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
}
