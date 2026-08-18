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

		// The add-to-cart area: a stock line above the button, icon rows
		// below the form.
		add_action( 'woocommerce_before_add_to_cart_button', array( $this, 'stock_line' ) );
		add_action( 'woocommerce_after_add_to_cart_form', array( $this, 'atc_icons' ) );

		// Sold-out products show a proper block instead of a bare summary:
		// a disabled sold-out button and a back-in-stock signup.
		add_action( 'woocommerce_single_product_summary', array( $this, 'oos_block' ), 30 );
		add_action( 'wp_ajax_oc_notify', array( $this, 'notify_signup' ) );
		add_action( 'wp_ajax_nopriv_oc_notify', array( $this, 'notify_signup' ) );

		// Card labels render inside the media box (card_media) — Woo's own
		// loop sale flash would double them.
		add_action(
			'init',
			static function (): void {
				remove_action( 'woocommerce_before_shop_loop_item_title', 'woocommerce_show_product_loop_sale_flash', 10 );
			},
			20
		);

		// Every add feeds the "added to cart recently" strip counter.
		add_action(
			'woocommerce_add_to_cart',
			static function ( $cart_item_key, $product_id ): void {
				update_post_meta( $product_id, '_oc_atc_count', absint( get_post_meta( $product_id, '_oc_atc_count', true ) ) + 1 );
			},
			10,
			2
		);
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

		// The theme ships ONE lightbox for images and video alike (theme.js) —
		// Woo's PhotoSwipe would be a second, differently-styled gallery on
		// top of it, so core lightbox support stays off; the customizer
		// toggle drives the oc-no-lightbox body class instead.
		remove_theme_support( 'wc-product-gallery-lightbox' );

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

		// The same label settings dress the product-page badge: plain style
		// and the sale colours carry over from the Labels section.
		$plain = 'plain' === get_theme_mod( 'oc_sale_badge_style', 'badge' );

		return sprintf(
			'%s <span class="oc-price-badge%s" style="%s">%s</span>',
			$price,
			$plain ? ' oc-price-badge--plain' : '',
			esc_attr( self::flag_colors( 'oc_sale_badge_bg', 'oc_sale_badge_tx' ) ),
			esc_html( $text )
		);
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

		if ( 'square' === get_theme_mod( 'oc_swatch_shape', 'circle' ) ) {
			$classes[] = 'oc-swatch-square';
		}

		if ( 'square' === get_theme_mod( 'oc_swatch_shape_cat', 'circle' ) ) {
			$classes[] = 'oc-swatch-square-cat';
		}

		$classes[] = 'oc-cta-hover-' . sanitize_html_class( (string) get_theme_mod( 'oc_cta_hover', 'none' ) );

		if ( ! get_theme_mod( 'oc_atc_qty', true ) ) {
			$classes[] = 'oc-no-qty';
		}

		if ( get_theme_mod( 'oc_stock_indicator', true ) ) {
			$classes[] = 'oc-stockline-on';
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

			if ( absint( get_theme_mod( 'oc_gallery_img_height_mobile_px', 0 ) ) > 0 ) {
				$classes[] = 'oc-gimg-m-fixed';
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

		// A video marked for the catalogue leads the card, always muted.
		$video      = Video::meta( $product->get_id() );
		$card_video = null !== $video && $video['catalog'] ? $video['url'] : '';

		if ( '' !== $card_video && 'single' === $mode ) {
			// Single-image mode shows the video alone.
			$ids = array();
		}

		printf( '<div class="oc-card-media oc-card-media--%s">', esc_attr( '' !== $card_video && empty( $ids ) ? 'single' : $mode ) );
		echo '<div class="oc-card-media__strip" aria-label="' . esc_attr__( 'Product images', 'oc-theme' ) . '">';

		if ( '' !== $card_video ) {
			// No play badge on the card — the loop speaks for itself. Lazy:
			// the video only loads and plays as its card nears the viewport,
			// so a catalogue full of videos stays light.
			echo '<figure class="oc-card-media__item oc-card-media__item--video is-first">';
			echo Video::loop_html( $card_video, 'oc-card-video', true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
			echo '</figure>';
		}

		foreach ( $ids as $i => $id ) {
			printf( '<figure class="oc-card-media__item%s">', 0 === $i && '' === $card_video ? ' is-first' : '' );
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
		$this->card_flags();
		$this->card_strip();

		echo '</div>';
	}

	/**
	 * Corner labels on the card: the sale badge first, then stock and "new",
	 * stacked on the configured side. The container always renders so the
	 * colour-sibling swap has a stable home for the badge.
	 */
	private function card_flags(): void {
		global $product;

		// Each label group picks its own side; both columns render so the
		// colour-sibling swap always has the sale column to dock into.
		$sides = array(
			'left'  => '',
			'right' => '',
		);

		$sale_side = 'right' === get_theme_mod( 'oc_label_sale_pos', 'left' ) ? 'right' : 'left';

		if ( $product->is_on_sale() ) {
			$sides[ $sale_side ] .= apply_filters( 'woocommerce_sale_flash', '<span class="onsale">' . esc_html__( 'Sale!', 'woocommerce' ) . '</span>', $product->get_id() ? get_post( $product->get_id() ) : null, $product );
		}

		if ( get_theme_mod( 'oc_label_stock', false ) ) {
			$text = '';

			if ( ! $product->is_in_stock() ) {
				$text = (string) get_theme_mod( 'oc_label_stock_out', __( 'Out of stock', 'oc-theme' ) );
			} elseif ( $product->managing_stock() && null !== $product->get_stock_quantity() ) {
				$qty = (int) $product->get_stock_quantity();
				$low = (int) wc_get_low_stock_amount( $product );

				if ( 1 === $qty ) {
					$text = (string) get_theme_mod( 'oc_label_stock_last', __( 'Last one in stock', 'oc-theme' ) );
				} elseif ( $qty <= $low * 2 ) {
					$text = (string) get_theme_mod( 'oc_label_stock_low', __( 'Last items in stock', 'oc-theme' ) );
				}
			}

			if ( '' !== $text ) {
				$stock_side            = 'right' === get_theme_mod( 'oc_label_stock_pos', 'left' ) ? 'right' : 'left';
				$sides[ $stock_side ] .= sprintf(
					'<span class="oc-flag" style="%s">%s</span>',
					esc_attr( self::flag_colors( 'oc_label_stock_bg', 'oc_label_stock_tx' ) ),
					esc_html( $text )
				);
			}
		}

		if ( get_theme_mod( 'oc_label_new', false ) ) {
			$days  = max( 1, absint( get_theme_mod( 'oc_label_new_days', 30 ) ) );
			$since = get_post_time( 'U', true, $product->get_id() );

			if ( $since && ( time() - (int) $since ) < $days * DAY_IN_SECONDS ) {
				$new_side            = 'right' === get_theme_mod( 'oc_label_new_pos', 'left' ) ? 'right' : 'left';
				$sides[ $new_side ] .= sprintf(
					'<span class="oc-flag" style="%s">%s</span>',
					esc_attr( self::flag_colors( 'oc_label_new_bg', 'oc_label_new_tx' ) ),
					esc_html( (string) get_theme_mod( 'oc_label_new_text', __( 'New', 'oc-theme' ) ) )
				);
			}
		}

		foreach ( $sides as $side => $flags ) {
			printf(
				'<div class="oc-flags oc-flags--%s"%s>%s</div>',
				esc_attr( $side ),
				$side === $sale_side ? ' data-sale' : '',
				$flags // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
			);
		}
	}

	/**
	 * The full-width strip at the card's bottom: high demand / great choice,
	 * fed by real sales and add-to-cart counters.
	 */
	private function card_strip(): void {
		global $product;

		if ( ! get_theme_mod( 'oc_label_strip', false ) ) {
			return;
		}

		$sales = (int) $product->get_total_sales();
		$adds  = absint( get_post_meta( $product->get_id(), '_oc_atc_count', true ) );
		$text  = '';

		if ( $sales >= max( 1, absint( get_theme_mod( 'oc_label_strip_buy_min', 10 ) ) ) ) {
			$text = str_replace( '%d', (string) $sales, (string) get_theme_mod( 'oc_label_strip_buy_text', __( 'In demand! %d bought recently', 'oc-theme' ) ) );
		} elseif ( $adds >= max( 1, absint( get_theme_mod( 'oc_label_strip_cart_min', 50 ) ) ) ) {
			$text = str_replace( '%d', (string) $adds, (string) get_theme_mod( 'oc_label_strip_cart_text', __( 'Great choice! %d added to cart recently', 'oc-theme' ) ) );
		}

		if ( '' === $text ) {
			return;
		}

		// The headline is bold: everything up to the first exclamation mark
		// ("In demand! 11 sold recently") — one text field, no extra knobs.
		$bang = mb_strpos( $text, '!' );
		$html = false !== $bang
			? '<b>' . esc_html( mb_substr( $text, 0, $bang + 1 ) ) . '</b>' . esc_html( mb_substr( $text, $bang + 1 ) )
			: esc_html( $text );

		printf(
			'<div class="oc-strip" style="%s">%s</div>',
			esc_attr( self::flag_colors( 'oc_label_strip_bg', 'oc_label_strip_tx' ) ),
			$html // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
		);
	}

	/**
	 * Inline colour declarations for a label, from a bg/tx setting pair.
	 *
	 * @param string $bg_key Background setting id.
	 * @param string $tx_key Text-colour setting id.
	 * @return string
	 */
	private static function flag_colors( string $bg_key, string $tx_key ): string {
		$bg = (string) get_theme_mod( $bg_key, '' );
		$tx = (string) get_theme_mod( $tx_key, '' );

		return ( '' !== $bg ? '--flag-bg:' . $bg . ';' : '' ) . ( '' !== $tx ? '--flag-tx:' . $tx . ';' : '' );
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
	 * The stock line above the add-to-cart button: a note at the start, the
	 * status with its colour dot at the far end.
	 */
	public function stock_line(): void {
		if ( ! get_theme_mod( 'oc_stock_indicator', true ) ) {
			return;
		}

		global $product;

		if ( ! $product instanceof \WC_Product ) {
			return;
		}

		$note   = '';
		$status = __( 'In stock', 'oc-theme' );
		$tone   = 'green';

		if ( ! $product->is_in_stock() ) {
			$status = __( 'Out of stock', 'oc-theme' );
			$tone   = 'red';
		} elseif ( $product->is_on_backorder( 1 ) ) {
			$availability = $product->get_availability();
			$note         = (string) ( $availability['availability'] ?? '' );
			$status       = __( 'Made to order', 'oc-theme' );
			$tone         = 'orange';
		} elseif ( $product->managing_stock() && null !== $product->get_stock_quantity() ) {
			$qty = (int) $product->get_stock_quantity();
			$low = (int) wc_get_low_stock_amount( $product );

			if ( $qty <= $low ) {
				/* translators: %d: units left in stock. */
				$note   = sprintf( __( 'Only %d left in stock', 'oc-theme' ), $qty );
				$status = __( 'Low stock', 'oc-theme' );
			} elseif ( $qty <= $low * 2 ) {
				$note = __( 'Last items in stock', 'oc-theme' );
			}
		}

		printf(
			'<div class="oc-stockline"><span class="oc-stockline__note">%s</span><span class="oc-stockline__status oc-stockline__status--%s"><i aria-hidden="true"></i>%s</span></div>',
			esc_html( $note ),
			esc_attr( $tone ),
			esc_html( $status )
		);
	}

	/**
	 * Icon rows under the add-to-cart form — shipping, returns, warranty
	 * and friends, picked from the built-in set.
	 */
	public function atc_icons(): void {
		$layout = 'stack' === get_theme_mod( 'oc_atc_icons_layout', 'row' ) ? 'stack' : 'row';
		$items  = '';

		for ( $i = 1; $i <= 4; $i++ ) {
			$icon = (string) get_theme_mod( 'oc_atc_icon_' . $i, '' );
			$text = (string) get_theme_mod( 'oc_atc_icon_text_' . $i, '' );

			if ( '' === $icon || '' === $text ) {
				continue;
			}

			$svg = self::atc_icon_svg( $icon );

			if ( '' === $svg ) {
				continue;
			}

			$items .= sprintf(
				'<span class="oc-atc-icons__item">%s<span>%s</span></span>',
				$svg, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG.
				esc_html( $text )
			);
		}

		if ( '' === $items ) {
			return;
		}

		echo '<div class="oc-atc-icons oc-atc-icons--' . esc_attr( $layout ) . '">' . $items . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
	}

	/**
	 * The built-in icon set for the add-to-cart rows. Thin 1.6 strokes, the
	 * header-icon family.
	 *
	 * @param string $key Icon key.
	 * @return string SVG or ''.
	 */
	private static function atc_icon_svg( string $key ): string {
		$w     = ' width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"';
		$icons = array(
			'truck'    => '<svg' . $w . '><path d="M2.5 7.2A1.2 1.2 0 0 1 3.7 6h9.1A1.2 1.2 0 0 1 14 7.2v7.8H2.5z"/><path d="M14 9.5h3.3a1 1 0 0 1 .77.36l2.2 2.64a1 1 0 0 1 .23.64V15h-2.1"/><path d="M2.5 15h2.3M9.3 15H14"/><circle cx="7" cy="16.6" r="1.7"/><circle cx="16.3" cy="16.6" r="1.7"/></svg>',
			'plane'    => '<svg' . $w . '><path d="M3 19.5h18"/><path d="M3.4 13.2l4.9 1.3L18.6 8.2a1.8 1.8 0 0 1 2.4.8c.4.9 0 1.9-.9 2.3L9 16.2l-5.6-.7z"/><path d="M8.5 9.2l3.2.9"/></svg>',
			'scooter'  => '<svg' . $w . '><circle cx="5.4" cy="16.9" r="2.1"/><circle cx="18.6" cy="16.9" r="2.1"/><path d="M7.5 16.9h6.6l1.7-6.6h2.7"/><path d="M14.1 10.3l-.9-3h-2.6"/><rect x="8.9" y="3.6" width="4.6" height="3.7" rx=".7"/></svg>',
			'box'      => '<svg' . $w . '><path d="M3.5 7.5l8.5-4 8.5 4v9l-8.5 4-8.5-4z"/><path d="M3.5 7.5L12 11.5l8.5-4M12 11.5v9"/></svg>',
			'returns'  => '<svg' . $w . '><path d="M9 5H16a5.5 5.5 0 0 1 0 11H5"/><path d="M8 12.5L4.5 16 8 19.5"/></svg>',
			'warranty' => '<svg' . $w . '><path d="M12 2.8l7.5 3v6c0 5-3.2 8.3-7.5 9.4-4.3-1.1-7.5-4.4-7.5-9.4v-6z"/><path d="M8.5 12l2.4 2.4 4.6-5"/></svg>',
			'question' => '<svg' . $w . '><path d="M3.5 4.5h17v12h-9l-4.5 3.5v-3.5h-3.5z"/><path d="M10 8.7a2.2 2.2 0 1 1 3 2.05c-.7.3-1 .75-1 1.45"/><path d="M12 14.4v.1"/></svg>',
			'gift'     => '<svg' . $w . '><path d="M3.5 8h17v4h-17zM5 12h14v8.5H5z"/><path d="M12 8v12.5M12 8s-1-4-4-4a2 2 0 0 0 0 4M12 8s1-4 4-4a2 2 0 0 1 0 4"/></svg>',
			'secure'   => '<svg' . $w . '><rect x="4.5" y="10" width="15" height="10" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/><path d="M12 14v2.5"/></svg>',
			'discount' => '<svg' . $w . '><path d="M3.5 12l8.5-8.5 8.5.1.1 8.4L12 20.5z"/><circle cx="15.2" cy="8.8" r="1.4"/></svg>',
		);

		return $icons[ $key ] ?? '';
	}

	/**
	 * The sold-out block: a disabled sold-out button where add-to-cart
	 * would be, and a back-in-stock signup that stores the email on the
	 * product.
	 */
	public function oos_block(): void {
		global $product;

		if ( ! $product instanceof \WC_Product || $product->is_in_stock() ) {
			return;
		}
		?>
		<div class="oc-oos" data-product="<?php echo absint( $product->get_id() ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'oc_notify' ) ); ?>">
			<button type="button" class="oc-oos__soldout" disabled><?php esc_html_e( 'Out of stock', 'oc-theme' ); ?></button>
			<button type="button" class="oc-oos__notify"><?php esc_html_e( 'Notify me when it is back', 'oc-theme' ); ?></button>
			<form class="oc-oos__form" hidden>
				<input type="email" required placeholder="<?php esc_attr_e( 'Email address', 'oc-theme' ); ?>" />
				<button type="submit"><?php esc_html_e( 'Sign up', 'oc-theme' ); ?></button>
			</form>
			<p class="oc-oos__done" hidden><?php esc_html_e( 'You are on the list — we will email you the moment it is back.', 'oc-theme' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Store a back-in-stock request on the product.
	 */
	public function notify_signup(): void {
		check_ajax_referer( 'oc_notify', 'nonce' );

		$product_id = absint( $_POST['product'] ?? 0 );
		$email      = sanitize_email( wp_unslash( (string) ( $_POST['email'] ?? '' ) ) );

		if ( ! $product_id || ! is_email( $email ) || 'product' !== get_post_type( $product_id ) ) {
			wp_send_json_error();
		}

		$list = get_post_meta( $product_id, '_oc_notify_list', true );
		$list = is_array( $list ) ? $list : array();

		if ( ! isset( $list[ $email ] ) ) {
			$list[ $email ] = time();
			update_post_meta( $product_id, '_oc_notify_list', $list );
		}

		wp_send_json_success();
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

		// Plain style: bare text at the sale-price size, no pill; colours
		// from the label settings either way.
		$plain = 'plain' === get_theme_mod( 'oc_sale_badge_style', 'badge' );
		$class = 'onsale' . ( $plain ? ' oc-flag--plain' : '' );
		$style = self::flag_colors( 'oc_sale_badge_bg', 'oc_sale_badge_tx' );

		if ( 'percent' === $mode && $product instanceof \WC_Product ) {
			$percent = $this->discount_percent( $product );

			if ( $percent > 0 ) {
				return sprintf(
					'<span class="%s oc-sale-percent" style="%s">‎-%s%%</span>',
					esc_attr( $class ),
					esc_attr( $style ),
					esc_html( (string) $percent )
				);
			}
		}

		if ( '' !== $style || $plain ) {
			return sprintf(
				'<span class="%s" style="%s">%s</span>',
				esc_attr( $class ),
				esc_attr( $style ),
				esc_html( wp_strip_all_tags( (string) $html ) )
			);
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
