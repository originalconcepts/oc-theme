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

		// Without the flexslider support flag WooCommerce falls back to the
		// 100px gallery_thumbnail size for every image after the first — which
		// rendered them pixelated and killed zoom on them (QA round 4). Every
		// gallery image is a real single-product image.
		add_filter( 'woocommerce_gallery_image_size', array( $this, 'gallery_image_size' ) );

		// Sale mark on the product page lives inside the price line, so the
		// flex row can stretch it to exactly the sale price's height.
		add_filter( 'woocommerce_get_price_html', array( $this, 'price_badge_html' ), 20, 2 );
		add_filter( 'woocommerce_breadcrumb_defaults', array( $this, 'breadcrumb_defaults' ) );
		add_filter( 'body_class', array( $this, 'body_class' ) );
		add_filter( 'woocommerce_sale_flash', array( $this, 'sale_badge' ), 10, 3 );
		add_action( 'woocommerce_after_shop_loop_item_title', array( $this, 'card_excerpt' ), 8 );

		// One markup path for every card image mode, including 'single'.
		remove_action( 'woocommerce_before_shop_loop_item_title', 'woocommerce_template_loop_product_thumbnail', 10 );
		add_action( 'woocommerce_before_shop_loop_item_title', array( $this, 'card_media' ), 10 );

		// The loop add-to-cart becomes a round icon over the image; the text
		// button goes away. Rendered inside card_media() so it can sit on the
		// image.
		remove_action( 'woocommerce_after_shop_loop_item', 'woocommerce_template_loop_add_to_cart', 10 );

		// Cart drawer shell — WooCommerce's fragments fill it after an
		// ajax add, so it needs no bespoke endpoint.
		add_action( 'wp_footer', array( $this, 'cart_drawer' ) );
		add_action( 'wp_footer', array( $this, 'thumbs_max_attribute' ), 5 );

		// Breadcrumb: the position is read inside the renderer, so one hook per
		// location is enough and the preview always matches.
		remove_action( 'woocommerce_before_main_content', 'woocommerce_breadcrumb', 20 );
		add_action( 'oc_before_page_title', array( $this, 'crumbs_above' ), 10 );
		add_action( 'woocommerce_archive_description', array( $this, 'crumbs_below' ), 5 );
		add_action( 'woocommerce_single_product_summary', array( $this, 'crumbs_below' ), 6 );

		// The header cart counter stays fresh after every ajax add.
		add_filter( 'woocommerce_add_to_cart_fragments', array( $this, 'cart_count_fragment' ) );

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
		// No flexslider, ever: it is jQuery-era machinery whose LTR offset
		// maths fights the RTL stylesheet. All four gallery presets render the
		// flat image list; the thumbnail presets get a native vanilla-JS rail.
		remove_theme_support( 'wc-product-gallery-slider' );

		if ( get_theme_mod( 'oc_gallery_lightbox', true ) ) {
			add_theme_support( 'wc-product-gallery-lightbox' );
		} else {
			remove_theme_support( 'wc-product-gallery-lightbox' );
		}

		// Woo's zoom binds $images.first() only — with the slider gone, images
		// after the first never zoom. The theme ships its own hover zoom that
		// binds every image (see theme.js), so core zoom stays off.
		remove_theme_support( 'wc-product-gallery-zoom' );

		if ( ! get_theme_mod( 'oc_product_related', true ) ) {
			remove_action( 'woocommerce_after_single_product_summary', 'woocommerce_output_related_products', 20 );
		}

		if ( ! get_theme_mod( 'oc_product_upsells', true ) ) {
			remove_action( 'woocommerce_after_single_product_summary', 'woocommerce_upsell_display', 15 );
		}

		if ( get_theme_mod( 'oc_product_sticky_atc', true ) ) {
			add_action( 'woocommerce_after_single_product', array( $this, 'sticky_bar' ) );
		}

		// Category description below the products instead of under the title.
		if ( 'bottom' === get_theme_mod( 'oc_catalog_desc_pos', 'top' ) ) {
			remove_action( 'woocommerce_archive_description', 'woocommerce_taxonomy_archive_description', 10 );
			remove_action( 'woocommerce_archive_description', 'woocommerce_product_archive_description', 10 );
			add_action( 'woocommerce_after_main_content', 'woocommerce_taxonomy_archive_description', 5 );
			add_action( 'woocommerce_after_main_content', 'woocommerce_product_archive_description', 5 );
		}

		// "Beside the gallery": the tabs physically move into the summary
		// column, after add-to-cart, instead of the full-width row below.
		if ( 'side' === get_theme_mod( 'oc_product_tabs_pos', 'below' ) ) {
			remove_action( 'woocommerce_after_single_product_summary', 'woocommerce_output_product_data_tabs', 10 );
			add_action( 'woocommerce_single_product_summary', 'woocommerce_output_product_data_tabs', 35 );
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
	 * Full-quality size for every gallery image, not just the first.
	 *
	 * @return string
	 */
	public function gallery_image_size(): string {
		return 'woocommerce_single';
	}

	/**
	 * Header cart counter, replaced by cart fragments on every ajax add.
	 *
	 * @param array $fragments Fragment map.
	 * @return array
	 */
	public function cart_count_fragment( array $fragments ): array {
		$count = WC()->cart->get_cart_contents_count();

		$fragments['span.oc-cart-count'] = '<span class="oc-cart-count">' . absint( $count ) . '</span>';

		return $fragments;
	}

	/**
	 * Sale mark inside the product-page price line. Follows the same badge
	 * setting as the cards; loop contexts (related, upsells) keep the plain
	 * price.
	 *
	 * @param string      $price   Price html.
	 * @param \WC_Product $product Product.
	 * @return string
	 */
	public function price_badge_html( $price, $product ): string {
		if ( ! is_product() || ! is_main_query() || '' !== wc_get_loop_prop( 'name', '' ) ) {
			return (string) $price;
		}

		$mode = (string) get_theme_mod( 'oc_card_sale_badge', 'percent' );

		if ( 'none' === $mode || ! $product instanceof \WC_Product || ! $product->is_on_sale() ) {
			return (string) $price;
		}

		$text = __( 'Sale!', 'oc-theme' );

		if ( 'percent' === $mode ) {
			$percent = $this->discount_percent( $product );

			if ( 0 === $percent ) {
				return (string) $price;
			}

			$text = sprintf( '‎-%s%%', $percent );
		}

		return $price . ' <span class="oc-price-badge">' . esc_html( $text ) . '</span>';
	}

	/**
	 * Hand the thumbs cap to the native gallery script.
	 */
	public function thumbs_max_attribute(): void {
		if ( ! is_product() ) {
			return;
		}

		printf(
			'<script>document.body.dataset.ocThumbsMax=%d;</script>',
			absint( get_theme_mod( 'oc_gallery_thumbs_max', 5 ) )
		);
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
		$classes[] = 'oc-btn-' . sanitize_html_class( (string) get_theme_mod( 'oc_button_style', 'filled' ) );

		if ( 'center' === get_theme_mod( 'oc_catalog_title_align', 'start' ) ) {
			$classes[] = 'oc-title-center';
		}

		if ( 'center' === get_theme_mod( 'oc_products_heading_align', 'start' ) ) {
			$classes[] = 'oc-headings-center';
		}

		$transparent = (string) get_theme_mod( 'oc_header_transparent', 'none' );
		if ( 'all' === $transparent || ( 'home' === $transparent && is_front_page() ) ) {
			$classes[] = 'oc-htrans';
		}

		if ( is_shop() || is_product_taxonomy() ) {
			$paging = (string) get_theme_mod( 'oc_catalog_paging', 'numbers' );
			if ( 'numbers' !== $paging ) {
				$classes[] = 'oc-paging-' . sanitize_html_class( $paging );
			}

			$classes[] = 'oc-pagshape-' . sanitize_html_class( (string) get_theme_mod( 'oc_paging_shape', 'circle' ) );

			if ( 'full' === get_theme_mod( 'oc_catalog_products_width', 'page' ) ) {
				$classes[] = 'oc-prodfull';
			}
		}

		$classes[] = 'oc-matc-' . sanitize_html_class( (string) get_theme_mod( 'oc_card_atc_mobile', 'none' ) );

		if ( get_theme_mod( 'oc_swatch_check', true ) ) {
			$classes[] = 'oc-swatch-check';
		}

		if ( is_product() ) {
			$classes[] = 'oc-gallery-' . sanitize_html_class( (string) get_theme_mod( 'oc_gallery_preset', 'thumbs-side' ) );
			$classes[] = 'oc-side-' . sanitize_html_class( (string) get_theme_mod( 'oc_product_layout_side', 'gallery-start' ) );
			$classes[] = 'oc-tabs-' . sanitize_html_class( (string) get_theme_mod( 'oc_product_tabs', 'accordion' ) );
			$classes[] = 'oc-tabspos-' . sanitize_html_class( (string) get_theme_mod( 'oc_product_tabs_pos', 'below' ) );
			$classes[] = 'oc-ratio-' . sanitize_html_class( (string) get_theme_mod( 'oc_product_cols_ratio', '50-50' ) );
			$classes[] = 'oc-gm-' . sanitize_html_class( (string) get_theme_mod( 'oc_gallery_mobile', 'dots' ) );

			if ( get_theme_mod( 'oc_gallery_mobile_arrows', false ) ) {
				$classes[] = 'oc-gm-arrows';
			}

			if ( ! get_theme_mod( 'oc_gallery_lightbox', true ) ) {
				$classes[] = 'oc-no-lightbox';
			}

			if ( 'fixed' === get_theme_mod( 'oc_gallery_img_height', 'auto' ) ) {
				$classes[] = 'oc-gimg-fixed';
			}

			if ( get_theme_mod( 'oc_gallery_zoom', true ) ) {
				$classes[] = 'oc-zoom';
			}

			if ( get_theme_mod( 'oc_gallery_desktop_arrows', false ) ) {
				$classes[] = 'oc-gdesk-arrows';
			}
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
				'large',
				false,
				array(
					'loading' => 0 === $i ? 'eager' : 'lazy',
					'sizes'   => '(max-width: 900px) 50vw, 25vw',
				)
			);
			echo '</figure>';
		}

		echo '</div>';

		if ( 'gallery' === $mode ) {
			// Thin bare chevrons, the furniture reference look. Drawn physically
			// (left/right); the stylesheet flips both together for RTL.
			$left  = '<svg viewBox="0 0 100 100" aria-hidden="true"><path d="M 70,0 L 20,50 L 70,100 L 80,90 L 40,50 L 80,10 Z"/></svg>';
			$right = '<svg viewBox="0 0 100 100" aria-hidden="true"><path d="M 30,0 L 80,50 L 30,100 L 20,90 L 60,50 L 20,10 Z"/></svg>';

			printf(
				'<button type="button" class="oc-card-media__nav oc-card-media__nav--prev" aria-label="%s">%s</button>' .
				'<button type="button" class="oc-card-media__nav oc-card-media__nav--next" aria-label="%s">%s</button>',
				esc_attr__( 'Previous image', 'oc-theme' ),
				$left, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG.
				esc_attr__( 'Next image', 'oc-theme' ),
				$right // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG.
			);
		}

		$this->card_atc_icon();

		echo '</div>';
	}

	/**
	 * Round add-to-cart icon over the card image. Simple products add via
	 * WooCommerce's own ajax handler; anything needing options links through
	 * to the product page.
	 */
	private function card_atc_icon(): void {
		global $product;

		if ( 'none' === get_theme_mod( 'oc_card_atc', 'always' ) || ! $product->is_purchasable() || ! $product->is_in_stock() ) {
			return;
		}

		$simple = $product->is_type( 'simple' );
		$class  = 'oc-card-atc' . ( $simple ? ' add_to_cart_button ajax_add_to_cart' : '' );

		printf(
			'<a href="%s" data-quantity="1" data-product_id="%d" class="%s" aria-label="%s" rel="nofollow">%s</a>',
			esc_url( $simple ? '?add-to-cart=' . $product->get_id() : $product->get_permalink() ),
			absint( $product->get_id() ),
			esc_attr( $class ),
			esc_attr( $product->add_to_cart_text() ),
			// Cart icon plus a check for the "added" state, inline so both
			// inherit colour.
			'<svg class="oc-card-atc__cart" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="9" cy="20" r="1.6"/><circle cx="17" cy="20" r="1.6"/><path d="M3 3h2.5l2.2 11.2a1.6 1.6 0 0 0 1.6 1.3h7.6a1.6 1.6 0 0 0 1.6-1.3L20 7H6"/></svg>' .
			'<svg class="oc-card-atc__check" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4.5 12.5l5 5 10-11"/></svg>'
		);
	}

	/**
	 * Cart drawer shell. WooCommerce's cart-fragments script populates
	 * .widget_shopping_cart_content on every ajax add, so the drawer stays in
	 * sync with no custom endpoint.
	 */
	public function cart_drawer(): void {
		?>
		<div class="oc-drawer" data-oc-cart-drawer hidden>
			<div class="oc-drawer__overlay" data-oc-drawer-close tabindex="-1"></div>
			<aside class="oc-drawer__panel" role="dialog" aria-modal="true" aria-label="<?php esc_attr_e( 'Cart', 'oc-theme' ); ?>">
				<header class="oc-drawer__head">
					<h2><?php esc_html_e( 'Cart', 'oc-theme' ); ?></h2>
					<button type="button" class="oc-drawer__close" data-oc-drawer-close aria-label="<?php esc_attr_e( 'Close', 'oc-theme' ); ?>">&times;</button>
				</header>
				<div class="widget_shopping_cart_content"></div>
			</aside>
		</div>
		<?php
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
