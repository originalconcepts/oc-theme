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

		// Woo 9's "verify your email to link past guest orders" prompt:
		// legitimate for shops migrating guests, noise for ours — customers
		// arrive by phone or social and their orders are already theirs.
		add_action(
			'wp',
			static function (): void {
				global $wp_filter;

				if ( empty( $wp_filter['woocommerce_before_account_orders'] ) ) {
					return;
				}

				foreach ( $wp_filter['woocommerce_before_account_orders']->callbacks as $priority => $callbacks ) {
					foreach ( $callbacks as $cb ) {
						if ( is_array( $cb['function'] ) && is_object( $cb['function'][0] )
							&& false !== strpos( get_class( $cb['function'][0] ), 'CustomerEmailVerification' ) ) {
							remove_action( 'woocommerce_before_account_orders', $cb['function'], $priority );
						}
					}
				}
			},
			1
		);

		// Wording the shop prefers, applied at translation time so no
		// WordPress or WooCommerce update ever tramples it. Exact-string
		// matches only — the map stays surgical.
		$reword = static function ( $translation ) {
			$map = array(
				'מצב'      => 'סטטוס',
				// The address cards' "Edit %s" / "Add %s" — caught at the
				// pattern so the address title never leaks into the link.
				'ערוך %s'  => 'עריכה',
				'להוסיף %s' => 'הוספה',
			);

			return $map[ $translation ] ?? $translation;
		};
		add_filter(
			'gettext',
			static function ( $translation, $text, $domain ) use ( $reword ) {
				return 'woocommerce' === $domain ? $reword( $translation ) : $translation;
			},
			20,
			3
		);
		add_filter(
			'gettext_with_context',
			static function ( $translation, $text, $context, $domain ) use ( $reword ) {
				return 'woocommerce' === $domain ? $reword( $translation ) : $translation;
			},
			20,
			4
		);

		// The orders table's total: the price stands alone, the item count
		// whispers underneath instead of crowding the line.
		add_filter(
			'ngettext',
			static function ( $translation, $single, $plural, $number, $domain ) {
				if ( 'woocommerce' === $domain && '%1$s for %2$s item' === $single ) {
					return 1 === (int) $number
						? '%1$s<span class="oc-ocount">' . __( 'One item', 'oc-theme' ) . '</span>'
						/* translators: %2$s: item count (printf swallows %1$s). */
						: '%1$s<span class="oc-ocount">' . __( '%2$s items', 'oc-theme' ) . '</span>';
				}

				return $translation;
			},
			20,
			5
		);

		// The Downloads tab means nothing to a customer with no downloads,
		// and the Dashboard means nothing to anyone — orders are the lobby.
		add_filter(
			'woocommerce_account_menu_items',
			static function ( array $items ): array {
				unset( $items['dashboard'] );

				if ( isset( $items['downloads'] ) && function_exists( 'wc_get_customer_available_downloads' )
					&& empty( wc_get_customer_available_downloads( get_current_user_id() ) ) ) {
					unset( $items['downloads'] );
				}

				return $items;
			}
		);

		// The address book takes over the "Addresses" screen when the feature
		// is on: our card grid + editor instead of Woo's billing/shipping
		// forms. Edits here and edits at checkout write the same user meta.
		if ( Addresses::enabled() ) {
			add_action( 'template_redirect', array( Addresses::class, 'handle_account' ), 5 );
			add_action(
				'wp',
				static function (): void {
					remove_action( 'woocommerce_account_edit-address_endpoint', 'woocommerce_account_edit_address' );
					add_action( 'woocommerce_account_edit-address_endpoint', array( Addresses::class, 'render_account' ) );
				}
			);
		}

		// Account details: add an editable phone, and keep the billing meta in
		// step with the account so a signed-in shopper meets the same details
		// at checkout (the packed orderer card reads billing_*).
		add_action( 'woocommerce_edit_account_form', array( $this, 'account_phone_field' ) );
		add_action( 'woocommerce_save_account_details', array( $this, 'save_account_extras' ) );

		// The orders list, reimagined: number over date, a product-thumbnail
		// column, a colour-coded status pill, and an optional reorder button.
		add_filter( 'woocommerce_account_orders_columns', array( $this, 'orders_columns' ) );
		add_action( 'woocommerce_my_account_my_orders_column_order-number', array( $this, 'orders_col_number' ) );
		add_action( 'woocommerce_my_account_my_orders_column_order-products', array( $this, 'orders_col_products' ) );
		add_action( 'woocommerce_my_account_my_orders_column_order-total', array( $this, 'orders_col_total' ) );
		add_action( 'woocommerce_my_account_my_orders_column_order-status', array( $this, 'orders_col_status' ) );
		add_action( 'woocommerce_my_account_my_orders_column_order-actions', array( $this, 'orders_col_actions' ) );
		add_action( 'wp_ajax_oc_reorder', array( $this, 'ajax_reorder' ) );

		// The single order view: our own layout — two summary cards (orderer +
		// destination with a location card) over a thank-you-style item list.
		add_action(
			'init',
			static function (): void {
				remove_action( 'woocommerce_view_order', 'woocommerce_order_details_table', 10 );
			},
			20
		);
		add_action( 'woocommerce_view_order', array( $this, 'render_order_view' ), 10 );

		// /my-account/ itself lands on the orders, not on an empty dashboard.
		// Matched by PATH, not by is_wc_endpoint_url() — custom endpoints
		// (stock alerts and friends) are invisible to that check and were
		// being bounced to the orders.
		add_action(
			'template_redirect',
			static function (): void {
				if ( ! function_exists( 'is_account_page' ) || ! is_account_page() || ! is_user_logged_in() ) {
					return;
				}

				$here = trim( (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH ), '/' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				$page = trim( (string) wp_parse_url( (string) wc_get_page_permalink( 'myaccount' ), PHP_URL_PATH ), '/' );

				if ( $here === $page ) {
					wp_safe_redirect( wc_get_account_endpoint_url( 'orders' ) );
					exit;
				}
			}
		);

		// The side navigation dresses like the header's quick menu: one card,
		// the greeting on top, sign-out in red past a rule. The wrapper opens
		// before the nav and closes after it so the card holds both.
		add_action(
			'woocommerce_before_account_navigation',
			static function (): void {
				$user = wp_get_current_user();
				$name = '' !== trim( (string) $user->first_name ) ? trim( (string) $user->first_name ) : (string) $user->display_name;

				echo '<div class="oc-macct-side"><p class="oc-macct-side__hi">' .
					esc_html( oc_greeting( $name ) ) .
					'</p>';
			}
		);
		add_action(
			'woocommerce_after_account_navigation',
			static function (): void {
				echo '</div>';
			}
		);

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
		add_filter( 'woocommerce_single_product_image_thumbnail_html', array( '\\OC\\Theme\\Variations', 'strip_thumb_data' ) );

		// Sale mark on the product page lives inside the price line, so the
		// flex row can stretch it to exactly the sale price's height.
		add_filter( 'woocommerce_get_price_html', array( $this, 'price_badge_html' ), 20, 2 );

		// SKU rides the price line's far end; a per-product checkbox next to
		// the SKU field can hide it.
		add_filter( 'woocommerce_get_price_html', array( $this, 'price_sku_html' ), 30, 2 );
		add_action( 'woocommerce_product_options_sku', array( $this, 'sku_toggle_field' ) );
		add_action( 'woocommerce_admin_process_product_object', array( $this, 'sku_toggle_save' ) );
		add_filter( 'woocommerce_breadcrumb_defaults', array( $this, 'breadcrumb_defaults' ) );
		add_filter( 'body_class', array( $this, 'body_class' ) );
		add_filter( 'posts_clauses', array( $this, 'oos_last' ), 20, 2 );
		add_filter( 'woocommerce_sale_flash', array( $this, 'sale_badge' ), 10, 3 );
		add_action( 'woocommerce_after_shop_loop_item', array( $this, 'card_atc_under' ), 20 );

		// The add-to-cart area: a stock line above the button, icon rows
		// below the form.
		add_action( 'woocommerce_before_add_to_cart_button', array( $this, 'stock_line' ) );
		add_action( 'woocommerce_after_add_to_cart_form', array( $this, 'atc_icons' ) );

		// Sold-out products show a proper block instead of a bare summary:
		// a disabled sold-out button and a back-in-stock signup.
		add_action( 'woocommerce_single_product_summary', array( $this, 'oos_block' ), 30 );
		add_action( 'wp_ajax_oc_notify', array( $this, 'notify_signup' ) );
		add_action( 'wp_ajax_nopriv_oc_notify', array( $this, 'notify_signup' ) );
		add_action( 'wp_ajax_oc_notify_vars', array( $this, 'notify_variations' ) );
		add_action( 'wp_ajax_nopriv_oc_notify_vars', array( $this, 'notify_variations' ) );
		add_action( 'wp_ajax_oc_notify_remove', array( $this, 'notify_remove' ) );
		add_action( 'wp_ajax_nopriv_oc_notify_remove', array( $this, 'notify_remove' ) );

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

		// Title, rating, description and price travel together in one box. The
		// row gives every card the same height for that box, and the box packs
		// its own contents tight — so a card with less to say keeps its price
		// close to its text instead of pushing it down to match a busier
		// neighbour.
		add_action( 'woocommerce_shop_loop_item_title', array( $this, 'card_text_open' ), 1 );
		add_action( 'woocommerce_after_shop_loop_item', array( $this, 'card_text_close' ), 999 );

		// One markup path for every card image mode, including 'single'.
		remove_action( 'woocommerce_before_shop_loop_item_title', 'woocommerce_template_loop_product_thumbnail', 10 );
		add_action( 'woocommerce_before_shop_loop_item_title', array( $this, 'card_media' ), 10 );

		// WooCommerce wraps the whole card in a single link. The card needs
		// anchors of its own inside it — add to cart above all — and an anchor
		// inside an anchor is not valid HTML, so the parser closes the wrapper
		// at the first one. Cards that carry a cart link came out flat, cards
		// without one came out wrapped, and no two products shared a shape.
		// The card links itself instead: the picture carries an overlay link,
		// the title carries its own.
		add_action(
			'init',
			function (): void {
				remove_action( 'woocommerce_before_shop_loop_item', 'woocommerce_template_loop_product_link_open', 10 );
				remove_action( 'woocommerce_after_shop_loop_item', 'woocommerce_template_loop_product_link_close', 5 );
				remove_action( 'woocommerce_shop_loop_item_title', 'woocommerce_template_loop_product_title', 10 );
				add_action( 'woocommerce_shop_loop_item_title', array( $this, 'card_title' ), 10 );
			},
			20
		);

		// The loop add-to-cart becomes a round icon over the image; the text
		// button goes away. Rendered inside card_media() so it can sit on the
		// image.
		remove_action( 'woocommerce_after_shop_loop_item', 'woocommerce_template_loop_add_to_cart', 10 );

		// Cart drawer shell — WooCommerce's fragments fill it after an
		// ajax add, so it needs no bespoke endpoint.
		// The cart drawer itself lives in the Cart class now.
		add_action( 'wp_footer', array( $this, 'thumbs_max_attribute' ), 5 );

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

		// Woo's meta block ("SKU: N/A", comma lists) is replaced wholesale:
		// the SKU moved into the price line and categories/tags render as a
		// quiet chip row.
		remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_meta', 40 );
		add_action( 'woocommerce_single_product_summary', array( $this, 'meta_chips' ), 40 );

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
	 * Sale mark inside the product-page price line. Follows the same badge
	 * setting as the cards; loop contexts (related, upsells) keep the plain
	 * price.
	 *
	 * @param string      $price   Price html.
	 * @param \WC_Product $product Product.
	 * @return string
	 */
	public function price_badge_html( $price, $product ): string {
		if ( Cart::$in_upsells || ! is_product() || ! is_main_query() || '' !== wc_get_loop_prop( 'name', '' ) ) {
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
	 * SKU at the far end of the price line — only when one exists, and only
	 * on the page's own product. Variable products render the span even with
	 * an empty parent SKU (hidden) so theme.js can surface the chosen
	 * variation's SKU; variations resolve the parent SKU as a fallback on
	 * their own, so the swap logic stays trivial.
	 *
	 * @param string      $price   Price html.
	 * @param \WC_Product $product Product.
	 * @return string
	 */
	public function price_sku_html( $price, $product ): string {
		if ( Cart::$in_upsells || is_admin() || ! is_product() || ! is_main_query() || '' !== wc_get_loop_prop( 'name', '' ) ) {
			return (string) $price;
		}

		if ( ! $product instanceof \WC_Product || $product->is_type( 'variation' ) ) {
			return (string) $price;
		}

		if ( (int) $product->get_id() !== (int) get_queried_object_id() ) {
			return (string) $price;
		}

		if ( ! wc_product_sku_enabled() || 'yes' === $product->get_meta( '_oc_sku_hide' ) ) {
			return (string) $price;
		}

		$sku = (string) $product->get_sku();

		if ( '' === $sku && ! $product->is_type( 'variable' ) ) {
			return (string) $price;
		}

		return sprintf(
			'%s<span class="oc-sku"%s>%s <bdi class="oc-sku__v">%s</bdi></span>',
			$price,
			'' === $sku ? ' hidden' : '',
			esc_html__( 'SKU:', 'oc-theme' ),
			esc_html( $sku )
		);
	}

	/**
	 * Per-product "hide the SKU" checkbox, right under the SKU field.
	 */
	public function sku_toggle_field(): void {
		woocommerce_wp_checkbox(
			array(
				'id'          => '_oc_sku_hide',
				'label'       => __( 'Hide SKU', 'oc-theme' ),
				'description' => __( 'Do not show the SKU on the product page.', 'oc-theme' ),
			)
		);
	}

	/**
	 * Persist the hide-SKU flag.
	 *
	 * @param \WC_Product $product Product being saved.
	 */
	public function sku_toggle_save( $product ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Woo verified the product-save nonce already.
		$product->update_meta_data( '_oc_sku_hide', isset( $_POST['_oc_sku_hide'] ) ? 'yes' : 'no' );
	}

	/**
	 * Categories and tags as a quiet chip row under the tabs: outlined pills
	 * for categories, dashed ghosts for tags, capped at five with a "+N"
	 * pill revealing the rest. Replaces Woo's comma-list meta block.
	 */
	public function meta_chips(): void {
		global $product;

		if ( ! $product instanceof \WC_Product ) {
			return;
		}

		$chips = array();

		foreach ( array( 'product_cat', 'product_tag' ) as $tax ) {
			$terms = get_the_terms( $product->get_id(), $tax );

			if ( ! is_array( $terms ) ) {
				continue;
			}

			foreach ( $terms as $term ) {
				if ( 'uncategorized' === $term->slug ) {
					continue;
				}

				$link = get_term_link( $term );

				if ( is_wp_error( $link ) ) {
					continue;
				}

				$chips[] = array(
					'name' => $term->name,
					'url'  => $link,
					'tag'  => 'product_tag' === $tax,
				);
			}
		}

		if ( ! $chips ) {
			return;
		}

		$limit  = 5;
		$hidden = count( $chips ) - $limit;

		echo '<nav class="oc-pmeta" aria-label="' . esc_attr__( 'Product categories and tags', 'oc-theme' ) . '">';

		foreach ( $chips as $i => $chip ) {
			printf(
				'<a class="oc-pmeta__chip%s" href="%s"%s>%s</a>',
				$chip['tag'] ? ' oc-pmeta__chip--tag' : '',
				esc_url( $chip['url'] ),
				$i >= $limit ? ' hidden' : '',
				esc_html( $chip['name'] )
			);
		}

		if ( $hidden > 0 ) {
			printf(
				'<button type="button" class="oc-pmeta__more" aria-label="%s">+%d</button>',
				esc_attr__( 'Show all', 'oc-theme' ),
				(int) $hidden
			);
		}

		echo '</nav>';
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

		if ( 'half' === get_theme_mod( 'oc_dd_width', 'full' ) ) {
			$classes[] = 'oc-dd-half';
		}

		if ( get_theme_mod( 'oc_dd_pair', false ) ) {
			$classes[] = 'oc-dd-pair';
		}

		if ( 'full' === get_theme_mod( 'oc_cta_incomplete', 'faded' ) ) {
			$classes[] = 'oc-cta-full-incomplete';
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

			// A sold-out product page sells the signup, not the cart — the
			// variations form drops its quantity and add-to-cart.
			$oos_product = wc_get_product( get_queried_object_id() );
			if ( $oos_product && ! $oos_product->is_in_stock() ) {
				$classes[] = 'oc-prod-oos';
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
	/**
	 * The daily wave: with the "rotate lead images" setting on, a quarter of
	 * the catalogue — a different, deterministic quarter every day — leads
	 * with one of its gallery shots instead of the featured image. Returning
	 * visitors meet familiar products from a new angle; no cron, no writes,
	 * and every visitor (and cache) sees the same face that day.
	 *
	 * @param array<int,int> $ids        Featured-first image ids.
	 * @param int            $product_id Product id (the shuffle seed).
	 * @return array<int,int>
	 */
	private function fresh_lead( array $ids, int $product_id ): array {
		if ( count( $ids ) < 2 || 'daily' !== (string) get_theme_mod( 'oc_catalog_fresh', 'off' ) ) {
			return $ids;
		}

		$day = gmdate( 'Y-z' );

		// Today's wave: roughly one product in four.
		if ( 0 !== crc32( $product_id . '|' . $day ) % 4 ) {
			return $ids;
		}

		// Which gallery shot leads is just as deterministic.
		$pick = 1 + ( crc32( $day . '#' . $product_id ) % ( count( $ids ) - 1 ) );
		$lead = $ids[ $pick ];
		unset( $ids[ $pick ] );
		array_unshift( $ids, $lead );

		return array_values( $ids );
	}

	/**
	 * With the setting on, sold-out products sink to the end of the catalogue
	 * — one indexed lookup-table join, ahead of whatever ordering is active.
	 *
	 * @param array<string,string> $clauses SQL clauses.
	 * @param \WP_Query            $query   Running query.
	 * @return array<string,string>
	 */
	public function oos_last( array $clauses, \WP_Query $query ): array {
		if ( is_admin() || 'product_query' !== $query->get( 'wc_query' ) || ! get_theme_mod( 'oc_catalog_oos_last', false ) ) {
			return $clauses;
		}

		global $wpdb;

		if ( false === strpos( (string) $clauses['join'], 'oc_stock_lookup' ) ) {
			$clauses['join'] .= " LEFT JOIN {$wpdb->wc_product_meta_lookup} oc_stock_lookup ON {$wpdb->posts}.ID = oc_stock_lookup.product_id ";
		}

		$order              = "( oc_stock_lookup.stock_status = 'outofstock' ) ASC";
		$clauses['orderby'] = '' !== (string) $clauses['orderby'] ? $order . ', ' . $clauses['orderby'] : $order;

		return $clauses;
	}

	/**
	 * Where a catalogue card points.
	 *
	 * WooCommerce runs its own link through `woocommerce_loop_product_link`;
	 * plugins that rewrite catalogue destinations hook there, so the theme's
	 * links go through it too.
	 *
	 * @param \WC_Product $product The product on the card.
	 */
	private static function card_link( \WC_Product $product ): string {
		return (string) apply_filters( 'woocommerce_loop_product_link', get_permalink( $product->get_id() ), $product );
	}

	/**
	 * Opens the card's text box.
	 */
	public function card_text_open(): void {
		echo '<div class="oc-card-text">';
	}

	/**
	 * Closes the card's text box.
	 */
	public function card_text_close(): void {
		echo '</div>';
	}

	/**
	 * The catalogue title, carrying its own link.
	 *
	 * WooCommerce prints the title bare and relies on the wrapper anchor this
	 * theme removes, so the link moves here.
	 */
	public function card_title(): void {
		global $product;

		if ( ! $product instanceof \WC_Product ) {
			return;
		}

		printf(
			'<h2 class="%s"><a class="oc-card-title-link" href="%s">%s</a></h2>',
			esc_attr( (string) apply_filters( 'woocommerce_product_loop_title_classes', 'woocommerce-loop-product__title' ) ),
			esc_url( self::card_link( $product ) ),
			get_the_title() // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- as WooCommerce prints it: the_title filters may return markup.
		);
	}

	/**
	 * The card's media block: image, hover image or video, and badges.
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
		$ids = $this->fresh_lead( $ids, $product->get_id() );

		// A product may nominate its own image for listings (class Catalog).
		$ids = array_values( array_filter( array_map( 'intval', (array) apply_filters( 'oc_card_image_ids', $ids, $product ) ) ) );

		if ( 'single' === $mode || count( $ids ) < 2 ) {
			$ids  = array_slice( $ids, 0, 1 );
			$mode = 'single';
		} else {
			$ids = array_slice( $ids, 0, $max );
		}

		if ( empty( $ids ) ) {
			// No image at all — WooCommerce prints its placeholder, inside the
			// same media box so the card keeps its shape and its link.
			echo '<div class="oc-card-media oc-card-media--single">';
			echo '<div class="oc-card-media__strip">';
			woocommerce_template_loop_product_thumbnail();
			echo '</div>';
			printf(
				'<a class="oc-card-media__link woocommerce-LoopProduct-link" href="%s" aria-hidden="true" tabindex="-1"></a>',
				esc_url( self::card_link( $product ) )
			);
			echo '</div>';
			return;
		}

		// A video marked for the catalogue leads the card, always muted.
		$video      = Video::meta( $product->get_id() );
		$card_video = null !== $video && $video['catalog'] ? $video['url'] : '';

		if ( '' !== $card_video && 'single' === $mode ) {
			// Single-image mode shows the video alone.
			$ids = array();
		}

		// A picture may say where its interesting half is, so the crop keeps it.
		$focus = Catalog::focus( $product->get_id() );

		printf(
			'<div class="oc-card-media oc-card-media--%s"%s>',
			esc_attr( '' !== $card_video && empty( $ids ) ? 'single' : $mode ),
			50 === $focus ? '' : ' style="--oc-card-focus:' . esc_attr( (string) $focus ) . '%"'
		);
		echo '<div class="oc-card-media__strip" aria-label="' . esc_attr__( 'Product images', 'oc-theme' ) . '">';

		// The picture is also a link to the product, and in a gallery the
		// link WRAPS each slide's content rather than lying over it. Two
		// phone bugs taught this shape: an overlay outside the scroller
		// cannot be swiped, and an absolutely-positioned overlay inside it
		// stops answering taps on iOS once the strip has been scrolled —
		// WebKit hit-tests it at the wrong place. An anchor around the
		// image is the one form with nothing to mis-hit.
		$slide_open  = 'gallery' === $mode ? sprintf(
			'<a class="oc-card-media__link woocommerce-LoopProduct-link" href="%s" aria-hidden="true" tabindex="-1">',
			esc_url( self::card_link( $product ) )
		) : '';
		$slide_close = 'gallery' === $mode ? '</a>' : '';

		if ( '' !== $card_video ) {
			// No play badge on the card — the loop speaks for itself. Lazy:
			// the video only loads and plays as its card nears the viewport,
			// so a catalogue full of videos stays light.
			echo '<figure class="oc-card-media__item oc-card-media__item--video is-first">';
			echo $slide_open; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
			echo Video::loop_html( $card_video, 'oc-card-video', true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
			echo $slide_close; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
			echo '</figure>';
		}

		foreach ( $ids as $i => $id ) {
			printf( '<figure class="oc-card-media__item%s">', 0 === $i && '' === $card_video ? ' is-first' : '' );
			echo $slide_open; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
			echo wp_get_attachment_image( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core-generated markup.
				$id,
				'large',
				false,
				array(
					'loading' => 0 === $i ? 'eager' : 'lazy',
					'sizes'   => '(max-width: 900px) 50vw, 25vw',
				)
			);
			echo $slide_close; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
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

		// Outside a gallery there is nothing to swipe, so one sheet over the
		// whole picture is simpler and does the same job. It sits under every
		// control the card paints on top, so the cart chip keeps working.
		if ( 'gallery' !== $mode ) {
			printf(
				'<a class="oc-card-media__link woocommerce-LoopProduct-link" href="%s" aria-hidden="true" tabindex="-1"></a>',
				esc_url( self::card_link( $product ) )
			);
		}

		$this->card_atc_icon();
		$this->card_flags();

		// Sold out: the notify bar takes the bottom edge; demand strips only
		// make sense for products one can actually buy.
		if ( ! $product->is_in_stock() ) {
			$this->card_notify_bar();
		} else {
			$this->card_strip();
		}

		echo '</div>';
	}

	/**
	 * Corner labels on the card: the sale badge first, then stock and "new",
	 * stacked on the configured side. The container always renders so the
	 * colour-sibling swap has a stable home for the badge.
	 */
	private function card_flags(): void {
		global $product;

		echo self::flags_html( $product ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
	}

	/**
	 * The same corner labels as a string, for whoever draws a product
	 * outside the catalogue loop — the menu panel's little cards, say.
	 *
	 * @param \WC_Product $product Product.
	 * @return string
	 */
	public static function flags_html( \WC_Product $product ): string {
		// Each label group picks its own side; both columns render so the
		// colour-sibling swap always has the sale column to dock into.
		$sides = array(
			'left'  => '',
			'right' => '',
		);

		$sale_side = 'right' === get_theme_mod( 'oc_label_sale_pos', 'left' ) ? 'right' : 'left';

		if ( $product->is_on_sale() ) {
			// phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- Woo's own badge string; reuse its translation.
			$sides[ $sale_side ] .= apply_filters( 'woocommerce_sale_flash', '<span class="onsale">' . esc_html__( 'Sale!', 'woocommerce' ) . '</span>', $product->get_id() ? get_post( $product->get_id() ) : null, $product );
		} else {
			// Promotion King promotions live outside WooCommerce sale prices;
			// their label rides the same badge slot, in the sale colours.
			$promo_label = (string) apply_filters( 'promeng_product_label', '', $product->get_id() );
			if ( '' !== $promo_label ) {
				$sides[ $sale_side ] .= sprintf(
					'<span class="onsale" style="%s">%s</span>',
					esc_attr( self::flag_colors( 'oc_label_sale_bg', 'oc_label_sale_tx' ) ),
					esc_html( $promo_label )
				);
			}
		}

		if ( get_theme_mod( 'oc_label_stock', false ) ) {
			$text = '';

			$is_oos = false;

			if ( ! $product->is_in_stock() ) {
				$text   = (string) get_theme_mod( 'oc_label_stock_out', __( 'Out of stock', 'oc-theme' ) );
				$is_oos = true;
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
					'<span class="oc-flag%s" style="%s">%s</span>',
					$is_oos ? ' oc-flag--oos' : '',
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

		$out = '';

		foreach ( $sides as $side => $flags ) {
			$out .= sprintf(
				'<div class="oc-flags oc-flags--%s"%s>%s</div>',
				esc_attr( $side ),
				$side === $sale_side ? ' data-sale' : '',
				$flags
			);
		}

		return $out;
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
			/* translators: %d: how many were bought. */
			$text = str_replace( '%d', (string) $sales, (string) get_theme_mod( 'oc_label_strip_buy_text', __( 'In demand! %d bought recently', 'oc-theme' ) ) );
		} elseif ( $adds >= max( 1, absint( get_theme_mod( 'oc_label_strip_cart_min', 50 ) ) ) ) {
			/* translators: %d: how many were added to a cart. */
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
	 * The under-shape add-to-cart: a full row below the card's words, in the
	 * product page's own button clothes.
	 */
	public function card_atc_under(): void {
		global $product;

		if ( 'under' !== get_theme_mod( 'oc_card_atc_shape', 'circle' ) || ! $product instanceof \WC_Product ) {
			return;
		}

		if ( 'none' === get_theme_mod( 'oc_card_atc', 'always' ) || ! $product->is_purchasable() || ! $product->is_in_stock() ) {
			return;
		}

		$simple = $product->is_type( 'simple' );
		$icon   = 'plus' === get_theme_mod( 'oc_card_atc_icon', 'cart' ) ? 'plus' : 'cart';
		$class  = 'oc-card-atc oc-card-atc--under oc-card-atc--i-' . $icon . ( $simple ? ' add_to_cart_button ajax_add_to_cart' : '' );

		$mark = 'plus' === $icon
			? '<svg class="oc-card-atc__cart" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>'
			: '<svg class="oc-card-atc__cart" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="9" cy="20" r="1.6"/><circle cx="17" cy="20" r="1.6"/><path d="M3 3h2.5l2.2 11.2a1.6 1.6 0 0 0 1.6 1.3h7.6a1.6 1.6 0 0 0 1.6-1.3L20 7H6"/></svg>';

		printf(
			'<a href="%s" data-quantity="1" data-product_id="%d" class="%s" rel="nofollow">%s</a>',
			esc_url( $simple ? '?add-to-cart=' . $product->get_id() : $product->get_permalink() ),
			absint( $product->get_id() ),
			esc_attr( $class ),
			$mark .
			'<svg class="oc-card-atc__check" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4.5 12.5l5 5 10-11"/></svg>' .
			'<span class="oc-card-atc__word">' . esc_html( $product->add_to_cart_text() ) . '</span>'
		);
	}

	/**
	 * Inline colour declarations for a label, from a bg/tx setting pair.
	 *
	 * @param string $bg_key Background setting id.
	 * @param string $tx_key Text-colour setting id.
	 * @return string
	 */
	public static function flag_colors( string $bg_key, string $tx_key ): string {
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

		// The under-shape renders after the words, not over the picture.
		if ( 'under' === get_theme_mod( 'oc_card_atc_shape', 'circle' ) ) {
			return;
		}

		if ( 'none' === get_theme_mod( 'oc_card_atc', 'always' ) || ! $product->is_purchasable() || ! $product->is_in_stock() ) {
			return;
		}

		$simple = $product->is_type( 'simple' );
		$icon   = 'plus' === get_theme_mod( 'oc_card_atc_icon', 'cart' ) ? 'plus' : 'cart';
		$shape  = (string) get_theme_mod( 'oc_card_atc_shape', 'circle' );
		$shape  = in_array( $shape, array( 'circle', 'square', 'wide' ), true ) ? $shape : 'circle';
		$class  = 'oc-card-atc oc-card-atc--' . $shape . ' oc-card-atc--i-' . $icon . ( $simple ? ' add_to_cart_button ajax_add_to_cart' : '' );

		$mark = 'plus' === $icon
			? '<svg class="oc-card-atc__cart" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>'
			: '<svg class="oc-card-atc__cart" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="9" cy="20" r="1.6"/><circle cx="17" cy="20" r="1.6"/><path d="M3 3h2.5l2.2 11.2a1.6 1.6 0 0 0 1.6 1.3h7.6a1.6 1.6 0 0 0 1.6-1.3L20 7H6"/></svg>';

		printf(
			'<a href="%s" data-quantity="1" data-product_id="%d" class="%s" aria-label="%s" rel="nofollow">%s</a>',
			esc_url( $simple ? '?add-to-cart=' . $product->get_id() : $product->get_permalink() ),
			absint( $product->get_id() ),
			esc_attr( $class ),
			esc_attr( $product->add_to_cart_text() ),
			// The icon, a check for the "added" state (inline so both inherit
			// colour), and — on the wide shape — the button's own words.
			$mark .
			'<svg class="oc-card-atc__check" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4.5 12.5l5 5 10-11"/></svg>' .
			( 'wide' === $shape ? '<span class="oc-card-atc__word">' . esc_html( $product->add_to_cart_text() ) . '</span>' : '' )
		);
	}

	/**
	 * The stock line above the add-to-cart button: a note at the start, the
	 * status with its colour dot at the far end.
	 */
	public function stock_line(): void {
		global $product;

		if ( $product instanceof \WC_Product ) {
			echo self::stock_line_html( $product ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
		}
	}

	/**
	 * The stock line as a string, for whoever draws a product outside the
	 * product page — the quick-pick panel, say. Empty when the setting is
	 * off.
	 *
	 * @param \WC_Product $product Product.
	 * @return string
	 */
	public static function stock_line_html( \WC_Product $product ): string {
		if ( ! get_theme_mod( 'oc_stock_indicator', true ) ) {
			return '';
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

		return sprintf(
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

		if ( ! $product instanceof \WC_Product ) {
			return;
		}

		$in_stock = $product->is_in_stock();

		// In-stock variable products carry a hidden copy: it surfaces the
		// moment the shopper picks a variation that happens to be sold out.
		if ( $in_stock && ! $product->is_type( 'variable' ) ) {
			return;
		}
		?>
		<div class="oc-oos<?php echo $in_stock ? ' oc-oos--watch' : ''; ?>"<?php echo $in_stock ? ' hidden' : ''; ?>>
			<button type="button" class="oc-oos__soldout" disabled><?php esc_html_e( 'Out of stock', 'oc-theme' ); ?></button>
			<button type="button" class="oc-oos__notify oc-notify-open" data-product="<?php echo absint( $product->get_id() ); ?>" data-name="<?php echo esc_attr( $product->get_name() ); ?>"<?php echo $product->is_type( 'variable' ) ? ' data-variable="1"' : ''; ?>><?php esc_html_e( 'Notify me when it is back', 'oc-theme' ); ?></button>
		</div>
		<?php
	}

	/**
	 * Sold-out card in the catalogue: a full-width notify bar on the image's
	 * lower edge, opening the same signup popup as the product page.
	 */
	private function card_notify_bar(): void {
		global $product;

		printf(
			'<button type="button" class="oc-notify-bar oc-notify-open" data-product="%d" data-name="%s"%s>%s</button>',
			absint( $product->get_id() ),
			esc_attr( $product->get_name() ),
			$product->is_type( 'variable' ) ? ' data-variable="1"' : '',
			esc_html__( 'Notify me when it is back', 'oc-theme' )
		);
	}

	/**
	 * Human variation label — term names resolved by hand because Hebrew
	 * attribute taxonomies arrive percent-encoded and defeat
	 * wc_get_formatted_variation's own lookup.
	 *
	 * @param \WC_Product_Variation $variation The variation.
	 */
	public static function variation_label( \WC_Product_Variation $variation ): string {
		$parts = array();

		foreach ( $variation->get_attributes() as $tax => $slug ) {
			$slug = (string) $slug;

			if ( '' === $slug ) {
				continue;
			}

			$name = rawurldecode( $slug );

			foreach ( array( (string) $tax, rawurldecode( (string) $tax ) ) as $taxonomy ) {
				if ( taxonomy_exists( $taxonomy ) ) {
					$term = get_term_by( 'slug', $slug, $taxonomy );
					if ( $term instanceof \WP_Term ) {
						$name = $term->name;
						break;
					}
				}
			}

			$parts[] = $name;
		}

		return $parts ? implode( ', ', $parts ) : $variation->get_name();
	}

	/**
	 * The popup's variation picker, fetched only when a variable product's
	 * trigger is clicked — cards stay cheap to render.
	 */
	public function notify_variations(): void {
		$product_id = absint( $_GET['product'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public read-only list.
		$product    = wc_get_product( $product_id );

		if ( ! $product || ! $product->is_type( 'variable' ) ) {
			wp_send_json_error();
		}

		$options = array();

		foreach ( $product->get_children() as $child_id ) {
			$variation = wc_get_product( $child_id );

			if ( ! $variation instanceof \WC_Product_Variation ) {
				continue;
			}

			$options[] = array(
				'id'    => $child_id,
				'label' => self::variation_label( $variation ),
			);
		}

		wp_send_json_success( $options );
	}

	/**
	 * Store a back-in-stock request on the product.
	 */
	public function notify_signup(): void {
		check_ajax_referer( 'oc_notify', 'nonce' );

		$product_id = absint( $_POST['product'] ?? 0 );
		$email      = sanitize_email( wp_unslash( (string) ( $_POST['email'] ?? '' ) ) );
		$phone      = preg_replace( '/[^0-9+\-]/', '', wp_unslash( (string) ( $_POST['phone'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- reduced to phone characters by the preg_replace.
		$channel    = Waitlist::settings()['channel'];

		$valid_email = is_email( $email );
		$valid_phone = strlen( $phone ) >= 9;

		// The admin-chosen channel decides what counts as a valid signup; on
		// 'both' either one is enough. Consent is never optional.
		$valid = ( 'whatsapp' === $channel && $valid_phone )
			|| ( 'email' === $channel && $valid_email )
			|| ( 'both' === $channel && ( $valid_email || $valid_phone ) );

		if ( ! $product_id || 'product' !== get_post_type( $product_id ) || ! $valid || empty( $_POST['consent'] ) ) {
			wp_send_json_error();
		}

		// A variable product's signup is for one specific variation.
		$variation_id = absint( $_POST['variation'] ?? 0 );
		$vname        = '';
		$product      = wc_get_product( $product_id );

		if ( $product && $product->is_type( 'variable' ) ) {
			$variation = $variation_id ? wc_get_product( $variation_id ) : null;

			if ( ! $variation instanceof \WC_Product_Variation || $variation->get_parent_id() !== $product_id ) {
				wp_send_json_error();
			}

			$vname = self::variation_label( $variation );
		} else {
			$variation_id = 0;
		}

		$list = get_post_meta( $product_id, '_oc_notify_list', true );
		$list = is_array( $list ) ? $list : array();
		$key  = ( 'whatsapp' === $channel || ! $valid_email ) ? $phone : $email;

		if ( $variation_id ) {
			$key .= '|' . $variation_id;
		}

		// A logged-in customer brings a name — the restock email greets with it.
		$person = is_user_logged_in() ? wp_get_current_user()->first_name : '';

		$list[ $key ] = array(
			'email'     => $valid_email ? $email : '',
			'phone'     => $valid_phone ? $phone : '',
			'name'      => sanitize_text_field( (string) $person ),
			'variation' => $variation_id,
			'vname'     => $vname,
			'time'      => time(),
			'consent'   => time(),
		);
		update_post_meta( $product_id, '_oc_notify_list', $list );

		// The browser keeps the key — self-service removal presents it back.
		wp_send_json_success( array( 'key' => $key ) );
	}

	/**
	 * Self-service unsubscribe from the popup: the browser presents the exact
	 * signup keys it was handed at signup time.
	 */
	public function notify_remove(): void {
		check_ajax_referer( 'oc_notify', 'nonce' );

		$product_id = absint( $_POST['product'] ?? 0 );
		$keys       = json_decode( wp_unslash( (string) ( $_POST['entries'] ?? '[]' ) ), true ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON payload; decoded, type-checked and compared against stored keys only.

		if ( ! $product_id || ! is_array( $keys ) ) {
			wp_send_json_error();
		}

		$list = get_post_meta( $product_id, '_oc_notify_list', true );

		if ( is_array( $list ) ) {
			foreach ( $keys as $key ) {
				if ( is_string( $key ) ) {
					unset( $list[ sanitize_text_field( wp_unslash( $key ) ) ] );
				}
			}

			if ( empty( $list ) ) {
				delete_post_meta( $product_id, '_oc_notify_list' );
			} else {
				update_post_meta( $product_id, '_oc_notify_list', $list );
			}
		}

		wp_send_json_success();
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

		if ( ! $product instanceof \WC_Product || ! $product->is_purchasable() || ! $product->is_in_stock() ) {
			return;
		}

		$is_variable = $product->is_type( 'variable' );
		$attributes  = $is_variable ? $product->get_variation_attributes() : array();
		$colors      = Variations::sticky_colors( $product );
		?>
		<div class="oc-sticky-atc" data-oc-sticky-atc data-product="<?php echo absint( $product->get_id() ); ?>" data-variable="<?php echo $is_variable ? '1' : '0'; ?>" hidden>
			<div class="oc-sticky-atc__inner">
				<?php echo wp_get_attachment_image( (int) $product->get_image_id(), 'thumbnail', false, array( 'class' => 'oc-sticky-atc__thumb' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core-generated markup. ?>
				<span class="oc-sticky-atc__title"><?php echo esc_html( $product->get_name() ); ?></span>

				<?php if ( $attributes || '' !== $colors['row'] ) : ?>
					<div class="oc-sticky-atc__opts">
						<?php if ( '' !== $colors['row'] ) : ?>
							<div class="oc-sticky-atc__opt oc-sticky-atc__opt--colors">
								<span class="oc-sticky-atc__optlabel"><?php echo esc_html( $colors['label'] ); ?></span>
								<?php echo $colors['row']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts. ?>
							</div>
						<?php endif; ?>

						<?php foreach ( $attributes as $attribute => $options ) : ?>
							<?php
							$options  = array_values( (array) $options );
							$single   = 1 === count( $options );
							$field    = 'attribute_' . sanitize_title( $attribute );
							$swatches = array();
							$labels   = array();

							foreach ( $options as $slug ) {
								$term            = get_term_by( 'slug', $slug, $attribute );
								$labels[ $slug ] = $term instanceof \WP_Term ? $term->name : rawurldecode( (string) $slug );

								if ( $term instanceof \WP_Term ) {
									$image = (string) get_term_meta( $term->term_id, 'oc_swatch_image', true );
									$color = (string) get_term_meta( $term->term_id, 'oc_swatch_color', true );
									if ( '' !== $image ) {
										$swatches[ $slug ] = 'background-image:url(' . esc_url( $image ) . ');background-size:cover;';
									} elseif ( '' !== $color ) {
										$swatches[ $slug ] = 'background-color:' . sanitize_hex_color( $color ) . ';';
									}
								}
							}

							// With a colour-sibling row the product's own single
							// colour is implicit — the row IS the colour UI, so
							// this cell only feeds the add (hidden), exactly
							// like the auto-selected row in the buy form.
							$hidden_cell = $single && '' !== $colors['row'];
							?>
							<label class="oc-sticky-atc__opt<?php echo $single ? ' oc-sticky-atc__opt--single' : ''; ?>"<?php echo $hidden_cell ? ' style="display:none"' : ''; ?>>
								<span class="oc-sticky-atc__optlabel"><?php echo esc_html( wc_attribute_label( rawurldecode( (string) $attribute ) ) ); ?></span>
								<i class="oc-sticky-atc__dot" hidden></i>
								<select data-oc-sticky-attr="<?php echo esc_attr( $field ); ?>" data-swatches="<?php echo esc_attr( (string) wp_json_encode( $swatches ) ); ?>"<?php echo $single ? ' tabindex="-1"' : ''; ?>>
									<?php if ( ! $single ) : ?>
										<option value=""><?php esc_html_e( 'Choose', 'oc-theme' ); ?></option>
									<?php endif; ?>
									<?php foreach ( $options as $slug ) : ?>
										<option value="<?php echo esc_attr( (string) $slug ); ?>"<?php selected( $single ); ?>><?php echo esc_html( $labels[ $slug ] ); ?></option>
									<?php endforeach; ?>
								</select>
							</label>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>

				<button type="button" class="oc-sticky-atc__buy checkout-button" data-oc-sticky-add data-name="<?php echo esc_attr( $product->get_name() ); ?>">
					<span><?php echo esc_html( $product->single_add_to_cart_text() ); ?></span>
					<em data-oc-sticky-price><?php echo wp_kses_post( $product->get_price_html() ); ?></em>
				</button>
			</div>
		</div>
		<?php
	}

	/**
	 * An editable phone field on the account-details form, prefilled from the
	 * billing phone. Rendered as a form-row-last so it pairs with the email.
	 */
	public function account_phone_field(): void {
		$phone = (string) get_user_meta( get_current_user_id(), 'billing_phone', true );
		?>
		<p class="woocommerce-form-row woocommerce-form-row--last form-row form-row-last oc-acct-phone">
			<label for="account_phone"><?php esc_html_e( 'Phone', 'oc-theme' ); ?>&nbsp;<span class="required" aria-hidden="true">*</span></label>
			<input type="tel" class="woocommerce-Input woocommerce-Input--tel input-text" name="account_phone" id="account_phone" autocomplete="tel" inputmode="tel" value="<?php echo esc_attr( $phone ); ?>" required />
		</p>
		<?php
	}

	/**
	 * On account save: store the phone, and mirror the account name/email into
	 * the billing meta so checkout greets the shopper with the same details.
	 *
	 * @param int $user_id User id.
	 */
	public function save_account_extras( $user_id ): void {
		// WooCommerce verifies its own save-account nonce before this fires.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( isset( $_POST['account_phone'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			update_user_meta( $user_id, 'billing_phone', sanitize_text_field( wp_unslash( $_POST['account_phone'] ) ) );
		}

		$user = get_userdata( (int) $user_id );
		if ( $user ) {
			update_user_meta( $user_id, 'billing_email', $user->user_email );
			update_user_meta( $user_id, 'billing_first_name', $user->first_name );
			update_user_meta( $user_id, 'billing_last_name', $user->last_name );
		}
	}

	/* --------------------------------------------------------- orders list */

	/**
	 * Reorder the columns: number (with the date beneath it), a product
	 * thumbnail column in place of the date, total, status, actions.
	 *
	 * @param array<string,string> $cols Columns.
	 * @return array<string,string>
	 */
	public function orders_columns( array $cols ): array {
		$out = array();

		$out['order-number']   = $cols['order-number'] ?? __( 'Order', 'woocommerce' );
		$out['order-products'] = __( 'Products', 'oc-theme' );
		$out['order-total']    = $cols['order-total'] ?? __( 'Total', 'woocommerce' );
		$out['order-status']   = $cols['order-status'] ?? __( 'Status', 'woocommerce' );
		$out['order-actions']  = $cols['order-actions'] ?? '&nbsp;';

		return $out;
	}

	/**
	 * Tone + label for an order status.
	 *
	 * @param string $status Status slug (no wc- prefix).
	 * @return array{tone:string,label:string}
	 */
	private function status_meta( string $status ): array {
		$tone = 'muted';
		switch ( $status ) {
			case 'completed':
				$tone = 'ok';
				break;
			case 'processing':
			case 'on-hold':
				$tone = 'work';
				break;
			case 'pending':
				$tone = 'pend';
				break;
			case 'cancelled':
			case 'refunded':
			case 'failed':
				$tone = 'bad';
				break;
		}

		return array(
			'tone'  => $tone,
			'label' => wc_get_order_status_name( $status ),
		);
	}

	/**
	 * Order number with the date on the line below, as DD/MM/YY.
	 *
	 * @param \WC_Order $order Order.
	 */
	public function orders_col_number( $order ): void {
		$created = $order->get_date_created();
		$date    = $created ? $created->date_i18n( 'd/m/y' ) : '';
		?>
		<a href="<?php echo esc_url( $order->get_view_order_url() ); ?>" class="oc-onum"><?php echo esc_html( '#' . $order->get_order_number() ); ?></a>
		<?php if ( '' !== $date ) : ?>
			<time class="oc-odate" datetime="<?php echo esc_attr( $created->date( 'c' ) ); ?>"><?php echo esc_html( $date ); ?></time>
		<?php endif; ?>
		<?php
	}

	/**
	 * Product thumbnails (rounded squares) with an overflow chip, and the
	 * product count beneath.
	 *
	 * @param \WC_Order $order Order.
	 */
	public function orders_col_products( $order ): void {
		$items = $order->get_items();
		$count = count( $items );
		$max   = 5;
		$i     = 0;
		$view  = $order->get_view_order_url();
		?>
		<a class="oc-oprods" href="<?php echo esc_url( $view ); ?>">
			<span class="oc-oprods__count">
				<?php
				/* translators: %s: number of products. */
				echo esc_html( sprintf( _n( '%s product', '%s products', $count, 'oc-theme' ), number_format_i18n( $count ) ) );
				?>
			</span>
			<span class="oc-oprods__imgs">
				<?php
				foreach ( $items as $item ) {
					if ( $i >= $max ) {
						break;
					}
					$product = $item->get_product();
					$pid     = $product ? $product->get_image_id() : 0;
					$url     = $pid ? wp_get_attachment_image_url( $pid, 'woocommerce_thumbnail' ) : wc_placeholder_img_src( 'woocommerce_thumbnail' );
					?>
					<span class="oc-oprods__img"><img src="<?php echo esc_url( (string) $url ); ?>" alt="" loading="lazy" /></span>
					<?php
					++$i;
				}
				if ( $count > $max ) :
					?>
					<span class="oc-oprods__more">+<?php echo esc_html( (string) ( $count - $max ) ); ?></span>
					<?php
				endif;
				?>
			</span>
		</a>
		<?php
	}

	/**
	 * Just the price — the item count already lives in the products column.
	 *
	 * @param \WC_Order $order Order.
	 */
	public function orders_col_total( $order ): void {
		$count = count( $order->get_items() );
		echo '<span class="oc-ototal">' . wp_kses_post( $order->get_formatted_order_total() ) . '</span>';
		/* translators: %s: number of products. */
		echo ' <span class="oc-ototal-count">' . esc_html( sprintf( _n( '%s product', '%s products', $count, 'oc-theme' ), number_format_i18n( $count ) ) ) . '</span>';
	}

	/**
	 * The status as a soft colour-coded pill.
	 *
	 * @param \WC_Order $order Order.
	 */
	public function orders_col_status( $order ): void {
		echo $this->status_pill( $order->get_status() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
	}

	/**
	 * A colour-coded status pill (icon + label) — shared by the orders list
	 * and the single-order view.
	 *
	 * @param string $status Status slug.
	 * @return string
	 */
	private function status_pill( string $status ): string {
		$meta = $this->status_meta( $status );

		$p     = '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">';
		$icons = array(
			'ok'    => $p . '<path d="M20 6 9 17l-5-5"/></svg>',
			'work'  => $p . '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>',
			'pend'  => $p . '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>',
			'bad'   => $p . '<circle cx="12" cy="12" r="9"/><path d="m15 9-6 6M9 9l6 6"/></svg>',
			'muted' => $p . '<circle cx="12" cy="12" r="9"/></svg>',
		);

		return sprintf(
			'<span class="oc-ostatus oc-ostatus--%1$s">%2$s<span>%3$s</span></span>',
			esc_attr( $meta['tone'] ),
			$icons[ $meta['tone'] ],
			esc_html( $meta['label'] )
		);
	}

	/**
	 * The standard order actions, plus an optional "order again" button.
	 *
	 * @param \WC_Order $order Order.
	 */
	public function orders_col_actions( $order ): void {
		// Wrapped in our own flex row so the cell itself stays a real table
		// cell — a flex <td> drops out of the row and misaligns the border.
		echo '<span class="oc-oactions">';

		$actions = wc_get_account_orders_actions( $order );

		foreach ( $actions as $key => $action ) {
			printf(
				'<a href="%1$s" class="woocommerce-button button %2$s">%3$s</a>',
				esc_url( $action['url'] ),
				esc_attr( sanitize_html_class( $key ) ),
				esc_html( $action['name'] )
			);
		}

		if ( ! empty( Checkout::settings()['reorder'] ) && count( $order->get_items() ) ) {
			printf(
				'<button type="button" class="woocommerce-button button oc-reorder" data-oc-reorder="%1$d" data-nonce="%2$s">%3$s</button>',
				absint( $order->get_id() ),
				esc_attr( wp_create_nonce( 'oc_reorder' ) ),
				esc_html__( 'Order again', 'oc-theme' )
			);
		}

		echo '</span>';
	}

	/**
	 * Add an order's items back to the cart. Modes: 'ask' (report a non-empty
	 * cart so the client can offer a choice), 'add' (keep what's there), or
	 * 'replace' (empty first). Returns the checkout URL to send them on.
	 */
	public function ajax_reorder(): void {
		check_ajax_referer( 'oc_reorder', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'msg' => __( 'Please sign in first.', 'oc-theme' ) ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
		$order = wc_get_order( absint( $_POST['order_id'] ?? 0 ) );

		if ( ! $order || (int) $order->get_customer_id() !== get_current_user_id() ) {
			wp_send_json_error( array( 'msg' => __( 'That order could not be found.', 'oc-theme' ) ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
		$mode = sanitize_text_field( wp_unslash( $_POST['mode'] ?? 'ask' ) );
		$cart = WC()->cart;

		if ( 'ask' === $mode && $cart->get_cart_contents_count() > 0 ) {
			wp_send_json_success( array( 'choice' => true ) );
		}

		if ( 'replace' === $mode ) {
			$cart->empty_cart();
		}

		$added   = 0;
		$skipped = 0;

		foreach ( $order->get_items() as $item ) {
			$product = $item->get_product();
			if ( ! $product || ! $product->is_purchasable() || ! $product->is_in_stock() ) {
				++$skipped;
				continue;
			}

			// Skip a promo freebie (a fully-discounted line, total 0 on a paid
			// subtotal) — the promo engine re-adds it once the paid item is in.
			if ( (float) $item->get_total() <= 0 && (float) $item->get_subtotal() > 0 ) {
				continue;
			}

			$variations = array();
			foreach ( $item->get_meta_data() as $meta ) {
				if ( taxonomy_is_product_attribute( $meta->key ) ) {
					$variations[ $meta->key ] = $meta->value;
				} elseif ( meta_is_product_attribute( $meta->key, $meta->value, $item->get_product_id() ) ) {
					$variations[ $meta->key ] = $meta->value;
				}
			}

			$ok = $cart->add_to_cart( $item->get_product_id(), $item->get_quantity(), $item->get_variation_id(), $variations );
			if ( $ok ) {
				++$added;
			} else {
				++$skipped;
			}
		}

		if ( ! $added ) {
			wp_send_json_error( array( 'msg' => __( 'None of these products are available right now.', 'oc-theme' ) ) );
		}

		wp_send_json_success(
			array(
				'added'    => $added,
				'skipped'  => $skipped,
				'redirect' => wc_get_checkout_url(),
			)
		);
	}

	/* ----------------------------------------------------- single order view */

	/**
	 * Is this order a local-pickup order?
	 *
	 * @param \WC_Order $order Order.
	 */
	private function order_is_pickup( $order ): bool {
		foreach ( $order->get_shipping_methods() as $method ) {
			if ( 0 === strpos( (string) $method->get_method_id(), 'local_pickup' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * A stylised "location" card — a soft map-like square with streets and a
	 * pin. No external service, no API key.
	 *
	 * @param string $label Small caption (e.g. the city).
	 */
	private function location_card( string $label ): string {
		$pin = '<svg class="oc-ov__pin" viewBox="0 0 24 24" width="30" height="30" aria-hidden="true"><path fill="var(--oc-primary,#03104c)" d="M12 2a7 7 0 0 0-7 7c0 4.7 6.2 12.3 6.4 12.6a.8.8 0 0 0 1.2 0C12.8 21.3 19 13.7 19 9a7 7 0 0 0-7-7z"/><circle cx="12" cy="9" r="2.6" fill="#fff"/></svg>';

		return '<div class="oc-ov__map" aria-hidden="true"><span class="oc-ov__map-streets"></span>' . $pin
			. ( '' !== $label ? '<span class="oc-ov__map-cap">' . esc_html( $label ) . '</span>' : '' )
			. '</div>';
	}

	/**
	 * Our whole order-view layout.
	 *
	 * @param int $order_id Order id.
	 */
	public function render_order_view( $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$pickup  = $this->order_is_pickup( $order );
		$created = $order->get_date_created();

		$name  = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		$email = $order->get_billing_email();
		$phone = $order->get_billing_phone();

		// The delivery address (this theme keeps it in billing, no postcode).
		$street = $order->get_billing_address_1();
		$city   = $order->get_billing_city();
		$apt    = $order->get_billing_address_2();
		$floor  = (string) $order->get_meta( '_oc_floor' );
		$entry  = (string) $order->get_meta( '_oc_entry' );

		$extra = array();
		if ( '' !== $apt ) {
			/* translators: %s: apartment. */
			$extra[] = sprintf( __( 'Apt %s', 'oc-theme' ), $apt );
		}
		if ( '' !== $floor ) {
			/* translators: %s: floor. */
			$extra[] = sprintf( __( 'Floor %s', 'oc-theme' ), $floor );
		}
		if ( '' !== $entry ) {
			/* translators: %s: entry code. */
			$extra[] = sprintf( __( 'Entry %s', 'oc-theme' ), $entry );
		}

		// Recipient, when the order was sent to someone else.
		$r_first = (string) $order->get_meta( '_oc_recipient_first' );
		$r_last  = (string) $order->get_meta( '_oc_recipient_last' );
		$r_phone = (string) $order->get_meta( '_oc_recipient_phone' );
		$r_name  = trim( $r_first . ' ' . $r_last );
		?>
		<div class="oc-ov">
			<div class="oc-ov__cards">
				<div class="oc-ov__card">
					<h3 class="oc-ov__card-h"><?php esc_html_e( 'Orderer details', 'oc-theme' ); ?></h3>
					<p class="oc-ov__name"><?php echo esc_html( $name ); ?></p>
					<?php if ( '' !== $email ) : ?>
						<p class="oc-ov__line" dir="ltr"><?php echo esc_html( $email ); ?></p>
					<?php endif; ?>
					<?php if ( '' !== $phone ) : ?>
						<p class="oc-ov__line" dir="ltr"><?php echo esc_html( $phone ); ?></p>
					<?php endif; ?>
				</div>

				<div class="oc-ov__card oc-ov__card--dest">
					<div class="oc-ov__dest-body">
						<?php if ( $pickup ) : ?>
							<h3 class="oc-ov__card-h"><?php esc_html_e( 'Store pickup', 'oc-theme' ); ?></h3>
							<p class="oc-ov__name"><?php echo esc_html( get_bloginfo( 'name' ) ); ?></p>
							<p class="oc-ov__line"><?php esc_html_e( 'Collect your order from the store.', 'oc-theme' ); ?></p>
						<?php else : ?>
							<h3 class="oc-ov__card-h"><?php esc_html_e( 'Delivery address', 'oc-theme' ); ?></h3>
							<p class="oc-ov__name"><?php echo esc_html( trim( $street . ( '' !== $city ? ', ' . $city : '' ) ) ); ?></p>
							<?php if ( $extra ) : ?>
								<p class="oc-ov__line"><?php echo esc_html( implode( ' · ', $extra ) ); ?></p>
							<?php endif; ?>
							<?php if ( '' !== $r_name ) : ?>
								<p class="oc-ov__recip"><?php esc_html_e( 'Recipient:', 'oc-theme' ); ?> <?php echo esc_html( $r_name ); ?><?php echo '' !== $r_phone ? ' · ' . esc_html( $r_phone ) : ''; ?></p>
							<?php endif; ?>
						<?php endif; ?>
					</div>
					<div class="oc-ov__mapwrap">
						<?php
						if ( $pickup ) {
							echo '<div class="oc-ov__map oc-ov__map--pickup" aria-hidden="true"><svg viewBox="0 0 24 24" width="34" height="34" fill="none" stroke="var(--oc-primary,#03104c)" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9.5 12 4l9 5.5"/><path d="M5 10.5V20h14v-9.5"/><path d="M9 20v-5h6v5"/></svg></div>';
						} else {
							echo $this->location_card( $city ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
						}
						?>
					</div>
				</div>
			</div>

			<div class="oc-ty__box oc-ov__products">
				<div class="oc-ov__phead">
					<h2 class="oc-ty__h oc-ov__phead-h"><?php esc_html_e( 'Order items', 'oc-theme' ); ?></h2>
					<div class="oc-ov__phead-meta">
						<?php if ( $created ) : ?>
							<span class="oc-ov__date"><?php echo esc_html( $created->date_i18n( 'd/m/y · H:i' ) ); ?></span>
						<?php endif; ?>
						<?php echo $this->status_pill( $order->get_status() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts. ?>
					</div>
				</div>

				<?php foreach ( $order->get_items() as $item ) : ?>
					<?php
					$product = is_callable( array( $item, 'get_product' ) ) ? $item->get_product() : null;
					$thumb   = $product ? $product->get_image( array( 64, 64 ) ) : '';
					$qty     = (int) $item->get_quantity();
					$meta    = wc_display_item_meta( $item, array( 'echo' => false ) );
					?>
					<div class="oc-ty__item">
						<span class="oc-ty__item-img">
							<?php echo wp_kses_post( $thumb ); ?>
							<span class="oc-ty__item-badge"><?php echo esc_html( (string) $qty ); ?></span>
						</span>
						<span class="oc-ty__item-body">
							<span class="oc-ty__item-name"><?php echo esc_html( $item->get_name() ); ?></span>
							<?php if ( $meta ) : ?>
								<span class="oc-ty__item-meta"><?php echo wp_kses_post( $meta ); ?></span>
							<?php endif; ?>
						</span>
						<span class="oc-ty__item-total"><?php echo wp_kses_post( $order->get_formatted_line_subtotal( $item ) ); ?></span>
					</div>
				<?php endforeach; ?>

				<div class="oc-ty__totals">
					<?php foreach ( $order->get_order_item_totals() as $key => $row ) : ?>
						<div class="oc-ty__trow<?php echo 'order_total' === $key ? ' oc-ty__trow--total' : ''; ?>">
							<span><?php echo esc_html( wp_strip_all_tags( (string) $row['label'] ) ); ?></span>
							<span><?php echo wp_kses_post( $row['value'] ); ?></span>
						</div>
					<?php endforeach; ?>
				</div>
			</div>
		</div>
		<?php
	}
}
