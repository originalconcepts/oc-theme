<?php
/**
 * Cart & mini-cart: the sliding cart panel — item rows with live quantity,
 * a free-shipping progress bar, in-panel upsells and a settings screen.
 *
 * @package OC_Theme
 */

declare( strict_types = 1 );

namespace OC\Theme;

defined( 'ABSPATH' ) || exit;

/**
 * Mini-cart engine + admin.
 */
final class Cart {

	/**
	 * True while upsell cards render — price filters (sale badge, SKU) check
	 * it and stay out of the small prices there.
	 *
	 * @var bool
	 */
	public static $in_upsells = false;

	/**
	 * Hook in.
	 */
	public function register(): void {
		// A shop feature on a site with no shop. The theme's contract is to
		// run as plain WordPress with an admin notice, never to fatal — the
		// demo always has WooCommerce, so only a fresh site can catch this.
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		add_action( 'admin_menu', array( $this, 'menu' ), 56 );
		add_action( 'admin_post_oc_cart_save', array( $this, 'save_settings' ) );

		add_action( 'wp_footer', array( $this, 'drawer' ) );
		add_shortcode( 'oc_cart_page', array( $this, 'cart_page' ) );
		add_filter( 'woocommerce_add_to_cart_fragments', array( $this, 'fragments' ) );

		add_action( 'wp_ajax_oc_cart_qty', array( $this, 'ajax_qty' ) );
		add_action( 'wp_ajax_nopriv_oc_cart_qty', array( $this, 'ajax_qty' ) );
		add_action( 'wp_ajax_oc_cart_vars', array( $this, 'ajax_vars' ) );
		add_action( 'wp_ajax_nopriv_oc_cart_vars', array( $this, 'ajax_vars' ) );
		add_action( 'wp_ajax_oc_card_picker', array( $this, 'ajax_picker' ) );
		add_action( 'wp_ajax_nopriv_oc_card_picker', array( $this, 'ajax_picker' ) );
		add_action( 'wp_ajax_oc_cart_add', array( $this, 'ajax_add' ) );
		add_action( 'wp_ajax_nopriv_oc_cart_add', array( $this, 'ajax_add' ) );
		add_action( 'wp_ajax_oc_cart_coupon', array( $this, 'ajax_coupon' ) );
		add_action( 'wp_ajax_nopriv_oc_cart_coupon', array( $this, 'ajax_coupon' ) );
		add_action( 'wp_ajax_oc_cart_promo_products', array( $this, 'ajax_promo_products' ) );
		add_action( 'wp_ajax_nopriv_oc_cart_promo_products', array( $this, 'ajax_promo_products' ) );
	}

	/**
	 * Settings with defaults.
	 *
	 * @return array<string,mixed>
	 */
	public static function settings(): array {
		$saved = get_option( 'oc_cart' );

		return wp_parse_args(
			is_array( $saved ) ? $saved : array(),
			array(
				'side'         => 'left',    // left | right.
				'width'        => 560,
				'title'        => '',
				'empty_text'   => '',
				'open_on_add'  => 1,
				'count_method' => 'total',   // total | rows.
				'ship_bar'     => 1,
				'ship_goal'    => '',        // empty = auto from shipping zones.
				'ship_text'    => '',
				'ship_done'    => '',
				'up_show'      => 0,
				'up_title'     => '',
				'up_source'    => 'items',   // items | category.
				'up_cat'       => 0,
				'up_max'       => 5,
				'up_style'     => 'side',    // side | list | slider | collapse.
				'up_bg'        => '',
				'coupon'       => 0,
				'btn_total'    => 0,
				'btn_text'     => '',
				'continue'     => 0,
				'cart_link'    => 0,
			)
		);
	}

	/* -------------------------------------------------------------- front */

	/**
	 * The drawer shell. Everything dynamic inside is a cart fragment, so
	 * every add/remove/quantity change refreshes it without a reload.
	 */
	public function drawer(): void {
		if ( is_admin() || is_cart() || is_checkout() ) {
			return;
		}

		$s     = self::settings();
		$title = '' !== (string) $s['title'] ? (string) $s['title'] : __( 'My cart', 'oc-theme' );
		$side  = 'right' === $s['side'] ? 'right' : 'left';
		?>
		<div class="oc-drawer oc-drawer--<?php echo esc_attr( $side ); ?><?php echo 'side' === $s['up_style'] && $s['up_show'] ? ' oc-drawer--upside' : ''; ?>" data-oc-cart-drawer hidden style="--oc-drawer-w:<?php echo absint( $s['width'] ); ?>px">
			<div class="oc-drawer__overlay" data-oc-drawer-close tabindex="-1"></div>
			<aside class="oc-drawer__panel" role="dialog" aria-modal="true" aria-label="<?php echo esc_attr( $title ); ?>">
				<div class="oc-drawer__main">
					<header class="oc-drawer__head">
						<div class="oc-drawer__headcol">
							<h2><?php echo esc_html( $title ); ?> <?php echo $this->head_count_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></h2>
							<?php echo $this->clear_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						</div>
						<button type="button" class="oc-drawer__close" data-oc-drawer-close aria-label="<?php esc_attr_e( 'Close', 'oc-theme' ); ?>">&times;</button>
					</header>
					<?php echo $this->ship_bar_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built escaped. ?>
					<div class="oc-drawer__scroll">
						<div data-oc-mcart><?php echo $this->items_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
						<?php echo $this->promo_msgs_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<?php if ( $s['up_show'] && in_array( (string) $s['up_style'], array( 'list', 'slider' ), true ) ) : ?>
							<?php echo $this->upsells_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<?php endif; ?>
					</div>
					<?php if ( $s['up_show'] && 'collapse' === $s['up_style'] ) : ?>
						<?php echo $this->upsells_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php endif; ?>
					<?php if ( $s['up_show'] && 'side' === $s['up_style'] ) : ?>
						<?php // The side strip has no room on mobile — there the same products fold into a minimizable block above the footer. ?>
						<?php echo $this->upsells_html( 'collapse', true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php endif; ?>
					<?php echo $this->foot_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</div>
				<?php if ( $s['up_show'] && 'side' === $s['up_style'] ) : ?>
					<?php echo $this->upsells_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php endif; ?>
			</aside>
		</div>
		<?php
	}

	/**
	 * The full cart page — the same components as the drawer (rows, promo
	 * messages, shipping bar, discounts, coupon, checkout), laid out as a
	 * page: items on one side, an order summary card on the other. The
	 * drawer skips cart pages, so the shared fragment anchors stay unique
	 * and every ajax behaviour (quantity, remove, coupon) works here too.
	 */
	public function cart_page(): string {
		$s = self::settings();

		ob_start();
		woocommerce_output_all_notices();
		$notices = ob_get_clean();

		$html = '<div class="oc-cartpage">' . $notices;

		if ( WC()->cart->is_empty() ) {
			$empty = (string) $s['empty_text'];

			$html .= '<div class="oc-cartpage__empty">';
			$html .= '<p>' . esc_html( '' !== $empty ? $empty : __( 'Your cart is empty', 'oc-theme' ) ) . '</p>';
			$html .= '<a class="oc-drawer__checkout checkout-button oc-cartpage__shopbtn" href="' . esc_url( wc_get_page_permalink( 'shop' ) ) . '"><span>' . esc_html__( 'Continue shopping', 'oc-theme' ) . '</span></a>';
			$html .= '</div></div>';

			return $html;
		}

		$html .= '<div class="oc-cartpage__grid">';

		$html .= '<div class="oc-cartpage__main">';
		$html .= $this->ship_bar_html();
		$html .= '<div data-oc-mcart>' . $this->items_html() . '</div>';
		$html .= $this->promo_msgs_html();

		if ( $s['up_show'] ) {
			// The side strip belongs to the drawer — the page gets the
			// horizontal slider instead.
			$style = in_array( (string) $s['up_style'], array( 'side', 'collapse' ), true ) ? 'slider' : (string) $s['up_style'];
			$html .= $this->upsells_html( $style );
		}

		$html .= '</div>';

		$html .= '<aside class="oc-cartpage__side">';
		$html .= '<h2 class="oc-cartpage__sidetitle">' . esc_html__( 'Order summary', 'oc-theme' ) . '</h2>';
		$html .= $this->foot_html();
		$html .= '</aside>';

		$html .= '</div></div>';

		return $html;
	}

	/**
	 * All the drawer's dynamic pieces ride the cart-fragments channel.
	 *
	 * @param array<string,string> $fragments Fragment map.
	 * @return array<string,string>
	 */
	public function fragments( array $fragments ): array {
		$s = self::settings();

		$fragments['[data-oc-mcart]']      = '<div data-oc-mcart>' . $this->items_html() . '</div>';
		$fragments['[data-oc-ship-bar]']   = $this->ship_bar_html();
		$fragments['[data-oc-cart-up]']    = $this->upsells_html();
		$fragments['[data-oc-cart-up-m]']  = $this->upsells_html( 'collapse', true );
		$fragments['[data-oc-promo-msgs]'] = $this->promo_msgs_html();
		$fragments['[data-oc-cart-foot]']  = $this->foot_html();

		// The header badge follows the configured counting method.
		$count = 'rows' === $s['count_method']
			? count( WC()->cart->get_cart() )
			: WC()->cart->get_cart_contents_count();

		$fragments['span.oc-cart-count']   = '<span class="oc-cart-count">' . absint( $count ) . '</span>';
		$fragments['[data-oc-head-count]'] = $this->head_count_html();
		$fragments['[data-oc-clear]']      = $this->clear_html();

		return $fragments;
	}

	/**
	 * The quiet clear-all link beneath the title — hidden while empty.
	 */
	private function clear_html(): string {
		return '<button type="button" class="oc-drawer__clearall" data-oc-clear data-oc-cart-clear data-arm="' . esc_attr__( 'Tap again to confirm', 'oc-theme' ) . '"' . ( WC()->cart->is_empty() ? ' hidden' : '' ) . '>' . esc_html__( 'Delete all', 'oc-theme' ) . '</button>';
	}

	/**
	 * The live item count beside the panel title.
	 */
	private function head_count_html(): string {
		$count = 'rows' === self::settings()['count_method']
			? count( WC()->cart->get_cart() )
			: WC()->cart->get_cart_contents_count();

		return '<span class="oc-drawer__count" data-oc-head-count' . ( $count ? '' : ' hidden' ) . '>(' . absint( $count ) . ')</span>';
	}

	/**
	 * The item rows: thumbnail, linked title, variation data, sale-aware
	 * prices, a live quantity stepper and a remove control — newest first.
	 * Out-of-stock items are marked and lose their stepper.
	 */
	private function items_html(): string {
		if ( WC()->cart->is_empty() ) {
			$empty = (string) self::settings()['empty_text'];

			return '<div class="oc-mcart__empty"><p>' . esc_html( '' !== $empty ? $empty : __( 'Your cart is empty', 'oc-theme' ) ) . '</p></div>';
		}

		// Product-specific promotion messages, pinned beneath the row of the
		// last cart line each one refers to.
		$by_key = array();
		foreach ( $this->promo_messages() as $msg ) {
			if ( empty( $msg['keys'] ) ) {
				continue;
			}

			// An applied message shows on EVERY line its discount touched;
			// a nudge stays on one line (the last still-waiting one).
			$slots = $msg['applied'] ? array_map( 'strval', (array) $msg['keys'] ) : array( (string) end( $msg['keys'] ) );
			$name  = (string) ( $msg['name'] ?? $msg['text'] );

			foreach ( array_unique( $slots ) as $slot ) {
				// Showing names only, an applied promotion and its "next set"
				// nudge read identically — the applied row wins per line.
				foreach ( $by_key[ $slot ] ?? array() as $i => $existing ) {
					if ( (string) ( $existing['name'] ?? $existing['text'] ) === $name ) {
						if ( $msg['applied'] && ! $existing['applied'] ) {
							$by_key[ $slot ][ $i ] = $msg;
						}
						continue 2;
					}
				}

				$by_key[ $slot ][] = $msg;
			}
		}

		$html = '<ul class="oc-mcart">';

		foreach ( array_reverse( WC()->cart->get_cart(), true ) as $key => $item ) {
			$product = apply_filters( 'woocommerce_cart_item_product', $item['data'], $item, $key );

			if ( ! $product instanceof \WC_Product || ! $product->exists() || $item['quantity'] <= 0 ) {
				continue;
			}

			$name = apply_filters( 'woocommerce_cart_item_name', $product->get_name(), $item, $key );

			// The raw attachment image, not $product->get_image(): the promo
			// engine wraps that one with its catalogue label, and a product
			// already in the cart needs no label (the upsells keep theirs).
			$image_id = (int) $product->get_image_id();
			$thumb    = $image_id
				? wp_get_attachment_image( $image_id, 'woocommerce_thumbnail' )
				: wc_placeholder_img( 'woocommerce_thumbnail' );
			$thumb    = apply_filters( 'woocommerce_cart_item_thumbnail', $thumb, $item, $key );
			$link     = apply_filters( 'woocommerce_cart_item_permalink', $product->is_visible() ? $product->get_permalink( $item ) : '', $item, $key );
			$in_stock = $product->is_in_stock();
			$qty      = (int) $item['quantity'];

			// Sale items show the crossed-out original next to the sale total.
			$regular = (float) $product->get_regular_price();

			if ( $product->is_on_sale() && $regular > 0 ) {
				$line = '<del>' . wc_price( $regular * $qty ) . '</del> <ins>' . wc_price( (float) $product->get_price() * $qty ) . '</ins>';
			} else {
				$line = WC()->cart->get_product_subtotal( $product, $qty );
			}

			// Promotion King replaces this with its own before/after when its
			// discount lands on the line ("second at 20%" and friends).
			$line = apply_filters( 'woocommerce_cart_item_subtotal', $line, $item, $key );

			$html .= '<li class="oc-mcart__item' . ( $in_stock ? '' : ' oc-mcart__item--oos' ) . '">';

			$html .= '' === $link
				? '<span class="oc-mcart__media">' . $thumb . '</span>'
				: '<a class="oc-mcart__media" href="' . esc_url( $link ) . '">' . $thumb . '</a>';

			$html .= '<div class="oc-mcart__info">';
			$html .= '' === $link
				? '<span class="oc-mcart__name">' . wp_kses_post( $name ) . '</span>'
				: '<a class="oc-mcart__name" href="' . esc_url( $link ) . '">' . wp_kses_post( $name ) . '</a>';
			$html .= $this->item_attributes_html( $item );

			$item_promos = '';
			if ( isset( $by_key[ (string) $key ] ) ) {
				foreach ( $by_key[ (string) $key ] as $msg ) {
					$item_promos .= $this->promo_msg_row( $msg );
				}
			}

			if ( $in_stock ) {
				// One unit: the minus becomes a delicate trash can that asks
				// before removing.
				$trash = '<button type="button" data-oc-qty-trash aria-label="' . esc_attr__( 'Remove', 'oc-theme' ) . '"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 7h16M9 7V5a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2m3 0-1 12a2 2 0 0 1-2 2H9a2 2 0 0 1-2-2L6 7M10 11v6M14 11v6"/></svg></button>';
				$minus = '<button type="button" data-oc-qty-minus aria-label="' . esc_attr__( 'Decrease quantity', 'oc-theme' ) . '">&minus;</button>';

				$html .= '<span class="oc-mcart__qty" data-oc-qty data-key="' . esc_attr( $key ) . '">';
				$html .= 1 === $qty ? $trash : $minus;
				$html .= '<input type="text" inputmode="numeric" value="' . esc_attr( (string) $qty ) . '" aria-label="' . esc_attr__( 'Quantity', 'oc-theme' ) . '" />';
				$html .= '<button type="button" data-oc-qty-plus aria-label="' . esc_attr__( 'Increase quantity', 'oc-theme' ) . '">+</button>';
				$html .= '</span>';
			} else {
				$html .= '<span class="oc-mcart__oos">' . esc_html__( 'Out of stock', 'oc-theme' ) . '</span>';
			}

			$html .= $item_promos;

			$html .= '</div>';

			// Line total at the row's far end, the per-unit price beneath it.
			$html .= '<div class="oc-mcart__prices">';
			$html .= '<span class="oc-mcart__line">' . $line . '</span>';
			if ( $qty > 1 ) {
				$unit_price = apply_filters( 'woocommerce_cart_item_price', WC()->cart->get_product_price( $product ), $item, $key );
				/* translators: %s: single unit price. */
				$html .= '<span class="oc-mcart__each">' . sprintf( esc_html__( '%s per unit', 'oc-theme' ), $unit_price ) . '</span>';
			}
			$html .= '</div>';

			// The removal question covers the row until answered.
			$html .= '<div class="oc-mcart__confirm" data-oc-confirm hidden>';
			/* translators: %s: product name. */
			$html .= '<span class="oc-mcart__confirm-q">' . esc_html( sprintf( __( 'Remove %s?', 'oc-theme' ), wp_strip_all_tags( (string) $name ) ) ) . '</span>';
			$html .= '<span class="oc-mcart__confirm-btns">';
			$html .= '<button type="button" class="oc-mcart__yes" data-oc-confirm-yes data-key="' . esc_attr( $key ) . '">' . esc_html__( 'Yes', 'oc-theme' ) . '</button>';
			$html .= '<button type="button" class="oc-mcart__no" data-oc-confirm-no>' . esc_html__( 'No', 'oc-theme' ) . '</button>';
			$html .= '</span>';
			$html .= '</div>';

			$html .= '</li>';
		}

		$html .= '</ul>';

		return $html;
	}

	/**
	 * The item's variation attributes: name, then the value in a heavier
	 * weight — with the term's swatch dot ahead of it when it has one.
	 *
	 * @param array<string,mixed> $item Cart item.
	 */
	public static function item_attributes_html( array $item ): string {
		if ( empty( $item['variation'] ) || ! is_array( $item['variation'] ) ) {
			return wc_get_formatted_cart_item_data( $item );
		}

		return self::attributes_html( (array) $item['variation'] );
	}

	/**
	 * The same rows from bare taxonomy => slug pairs, so a surface holding an
	 * order item (the thank-you page) shows attributes exactly as the cart
	 * does — swatch dot included.
	 *
	 * @param array<string,mixed> $pairs Taxonomy (or attribute_ key) => term slug.
	 */
	public static function attributes_html( array $pairs ): string {
		$html = '<span class="oc-mcart__attrs">';

		foreach ( $pairs as $raw_tax => $slug ) {
			$slug = (string) $slug;
			if ( '' === $slug ) {
				continue;
			}

			$taxonomy = str_replace( 'attribute_', '', (string) $raw_tax );
			// Hebrew attribute taxonomies arrive percent-encoded — decode
			// before asking for the human label.
			$label  = wc_attribute_label( rawurldecode( $taxonomy ) );
			$value  = rawurldecode( $slug );
			$swatch = '';

			foreach ( array( $taxonomy, rawurldecode( $taxonomy ) ) as $tax_try ) {
				if ( ! taxonomy_exists( $tax_try ) ) {
					continue;
				}
				$term = get_term_by( 'slug', $slug, $tax_try );
				if ( ! $term instanceof \WP_Term ) {
					continue;
				}
				$value = $term->name;

				$image = (string) get_term_meta( $term->term_id, 'oc_swatch_image', true );
				$color = (string) get_term_meta( $term->term_id, 'oc_swatch_color', true );
				if ( '' !== $image ) {
					$swatch = 'background-image:url(' . esc_url( $image ) . ');background-size:cover;';
				} elseif ( '' !== $color ) {
					$swatch = 'background-color:' . sanitize_hex_color( $color ) . ';';
				}
				break;
			}

			$html .= '<span class="oc-mcart__attr">';
			$html .= '<span class="oc-mcart__attr-name">' . esc_html( $label ) . ':</span> ';
			if ( '' !== $swatch ) {
				$html .= '<i class="oc-mcart__attr-swatch" style="' . $swatch . '"></i>';
			}
			$html .= '<strong>' . esc_html( $value ) . '</strong>';
			$html .= '</span>';
		}

		$html .= '</span>';

		return $html;
	}

	/**
	 * Free-shipping progress bar. The goal comes from the shipping zones'
	 * free-shipping minimum unless overridden in the settings.
	 */
	private function ship_bar_html(): string {
		$s = self::settings();

		if ( empty( $s['ship_bar'] ) || WC()->cart->is_empty() ) {
			return '<div data-oc-ship-bar hidden></div>';
		}

		$goal = (float) $s['ship_goal'];

		if ( $goal <= 0 ) {
			$goal = $this->free_shipping_minimum();
		}

		if ( $goal <= 0 ) {
			return '<div data-oc-ship-bar hidden></div>';
		}

		$subtotal = (float) WC()->cart->get_displayed_subtotal();
		$left     = max( 0.0, $goal - $subtotal );
		$percent  = min( 100, (int) round( $subtotal / $goal * 100 ) );

		if ( $left > 0 ) {
			$template = '' !== (string) $s['ship_text'] ? (string) $s['ship_text'] : __( '[sum] left for free shipping', 'oc-theme' );
			// The remaining amount carries the line — bold it.
			$sum  = html_entity_decode( wp_strip_all_tags( wc_price( $left ) ), ENT_QUOTES, 'UTF-8' );
			$text = str_replace( '[sum]', '<strong>' . esc_html( $sum ) . '</strong>', esc_html( $template ) );
		} else {
			$text = esc_html( '' !== (string) $s['ship_done'] ? (string) $s['ship_done'] : __( 'You earned free shipping!', 'oc-theme' ) );
		}

		return '<div class="oc-shipbar' . ( 0.0 === $left ? ' is-done' : '' ) . '" data-oc-ship-bar>'
			. '<span class="oc-shipbar__text">' . $text . '</span>'
			. '<span class="oc-shipbar__track"><i class="oc-shipbar__fill" style="inline-size:' . absint( $percent ) . '%"></i></span>'
			. '</div>';
	}

	/**
	 * The first free-shipping minimum found across the shipping zones.
	 */
	private function free_shipping_minimum(): float {
		foreach ( \WC_Shipping_Zones::get_zones() as $zone ) {
			foreach ( $zone['shipping_methods'] as $method ) {
				if ( 'free_shipping' === $method->id && 'yes' === $method->enabled && (float) $method->min_amount > 0 ) {
					return (float) $method->min_amount;
				}
			}
		}

		return 0.0;
	}

	/**
	 * Panel footer: subtotal, checkout (disabled while an item is out of
	 * stock), optional coupon form and cart-page link.
	 */
	private function foot_html(): string {
		$s = self::settings();

		if ( WC()->cart->is_empty() ) {
			return '<footer class="oc-drawer__foot" data-oc-cart-foot hidden></footer>';
		}

		$oos = false;
		foreach ( WC()->cart->get_cart() as $item ) {
			if ( $item['data'] instanceof \WC_Product && ! $item['data']->is_in_stock() ) {
				$oos = true;
				break;
			}
		}

		// The discounts breakdown: the promotion engine's per-deal savings,
		// then each coupon (with its removal control).
		$rows         = array();
		$promo_saved  = 0.0;
		$coupon_saved = 0.0;

		if ( class_exists( '\\PromoEngine\\Cart' ) && method_exists( '\\PromoEngine\\Cart', 'instance' ) ) {
			$pcart   = \PromoEngine\Cart::instance();
			$summary = $pcart && method_exists( $pcart, 'savings_summary' ) ? $pcart->savings_summary() : null;
			if ( is_array( $summary ) ) {
				foreach ( (array) ( $summary['items'] ?? array() ) as $row ) {
					$promo_saved += (float) $row['saved'];
					$rows[]       = '<div class="oc-drawer__discount"><span>' . esc_html( (string) $row['name'] ) . '</span><strong>&minus;' . wc_price( (float) $row['saved'] ) . '</strong></div>';
				}
			}
		}

		foreach ( WC()->cart->get_applied_coupons() as $code ) {
			$saved         = (float) WC()->cart->get_coupon_discount_amount( $code, false );
			$coupon_saved += $saved;
			$rows[]        = '<div class="oc-drawer__discount"><span>' . esc_html__( 'Coupon', 'oc-theme' ) . ' ' . esc_html( $code ) . ' <button type="button" class="oc-drawer__discount-x" data-oc-coupon-remove data-code="' . esc_attr( $code ) . '" aria-label="' . esc_attr__( 'Remove', 'oc-theme' ) . '">&times;</button></span><strong>&minus;' . wc_price( $saved ) . '</strong></div>';
		}

		// What the customer actually pays before shipping: the cart contents
		// (already carrying line-price promos and coupons) plus fees (how
		// cart-level promos land).
		$payable_amount = (float) WC()->cart->get_cart_contents_total() + (float) WC()->cart->get_cart_contents_tax()
			+ (float) WC()->cart->get_fee_total() + (float) WC()->cart->get_fee_tax();
		$payable_amount = max( 0.0, $payable_amount );
		$subtotal_row   = wc_price( $payable_amount + $promo_saved + $coupon_saved );
		$total          = wc_price( $payable_amount );
		$label          = '' !== (string) $s['btn_text'] ? (string) $s['btn_text'] : __( 'Continue to checkout', 'oc-theme' );

		if ( ! empty( $s['btn_total'] ) ) {
			$label .= ' · ' . html_entity_decode( wp_strip_all_tags( $total ), ENT_QUOTES, 'UTF-8' );
		}

		$html = '<footer class="oc-drawer__foot" data-oc-cart-foot>';

		// With the total on the button, no subtotal lines at all. Otherwise:
		// pre-discount subtotal, the savings line, and what is actually due.
		if ( $rows && empty( $s['btn_total'] ) ) {
			$html .= '<div class="oc-drawer__subtotal oc-drawer__subtotal--pre"><span>' . esc_html__( 'Subtotal', 'oc-theme' ) . '</span><strong>' . $subtotal_row . '</strong></div>';
		}

		// One slim line owns the whole discounts world: "you saved X" with
		// the breakdown folded behind it (always closed by default), and the
		// coupon entry point as a quiet link at the other end.
		if ( $rows || ! empty( $s['coupon'] ) ) {
			$html .= '<div class="oc-drawer__savewrap oc-drawer__coupon-wrap">';
			$html .= '<div class="oc-drawer__saveline">';

			if ( $rows ) {
				$total_saved = $promo_saved + $coupon_saved;
				$html       .= '<button type="button" class="oc-drawer__save-t" data-oc-save-toggle>'
					. '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4.5 12.5l5 5L19.5 7"/></svg>'
					/* translators: %s: amount saved. */
					. sprintf( esc_html__( 'You saved %s', 'oc-theme' ), '<strong>' . wc_price( $total_saved ) . '</strong>' )
					. '<svg class="oc-drawer__save-c" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>'
					. '</button>';
			}

			if ( ! empty( $s['coupon'] ) ) {
				$html .= '<button type="button" class="oc-drawer__coupon-t" data-oc-coupon-toggle>' . esc_html__( 'Have a coupon?', 'oc-theme' ) . '</button>';
			}

			$html .= '</div>';

			if ( $rows ) {
				$html .= '<div class="oc-drawer__save-body"><div class="oc-drawer__discounts">' . implode( '', $rows ) . '</div></div>';
			}

			if ( ! empty( $s['coupon'] ) ) {
				$html .= '<div class="oc-drawer__coupon-body"><form class="oc-drawer__coupon" data-oc-coupon-form>';
				$html .= '<input type="text" name="coupon_code" placeholder="' . esc_attr__( 'Coupon code', 'oc-theme' ) . '" />';
				$html .= '<button type="submit" aria-label="' . esc_attr__( 'Apply', 'oc-theme' ) . '"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4.5 12.5l5 5L19.5 7"/></svg></button>';
				$html .= '<p class="oc-drawer__coupon-msg" data-oc-coupon-msg hidden></p>';
				$html .= '</form></div>';
			}

			$html .= '</div>';
		}

		if ( $rows && empty( $s['btn_total'] ) ) {
			$html .= '<div class="oc-drawer__subtotal"><span>' . esc_html__( 'Total', 'oc-theme' ) . '</span><strong>' . $total . '</strong></div>';
		} elseif ( ! $rows && empty( $s['btn_total'] ) ) {
			$html .= '<div class="oc-drawer__subtotal"><span>' . esc_html__( 'Subtotal', 'oc-theme' ) . '</span><strong>' . $total . '</strong></div>';
		}

		if ( $oos ) {
			$html .= '<p class="oc-drawer__oos-note">' . esc_html__( 'Some items in the cart are out of stock — remove them to check out.', 'oc-theme' ) . '</p>';
		}

		// checkout-button: the class the global CTA hover effects target, so
		// the button follows the design settings like every other CTA.
		$html .= '<a class="oc-drawer__checkout checkout-button' . ( $oos ? ' is-disabled' : '' ) . '" href="' . esc_url( wc_get_checkout_url() ) . '"' . ( $oos ? ' aria-disabled="true" tabindex="-1"' : '' ) . '><span>' . esc_html( $label ) . '</span></a>';

		if ( ! empty( $s['continue'] ) ) {
			$html .= '<button type="button" class="oc-drawer__continue" data-oc-drawer-close>' . esc_html__( 'Continue shopping', 'oc-theme' ) . '</button>';
		}

		if ( ! empty( $s['cart_link'] ) && ! is_cart() ) {
			$html .= '<a class="oc-drawer__cartlink" href="' . esc_url( wc_get_cart_url() ) . '">' . esc_html__( 'View cart', 'oc-theme' ) . '</a>';
		}

		$html .= '</footer>';

		return $html;
	}

	/**
	 * Promotion King's structured messages, when its panel API is around.
	 *
	 * @return array<int,array{text:string,name?:string,keys?:array<int,string>,applied:bool,promo_id?:int,pool?:string,pool_type?:string,cat_url?:string}>
	 */
	private function promo_messages(): array {
		if ( ! class_exists( '\\PromoEngine\\Cart' ) || ! method_exists( '\\PromoEngine\\Cart', 'instance' ) ) {
			return array();
		}

		$cart = \PromoEngine\Cart::instance();

		return $cart && method_exists( $cart, 'panel_messages' ) ? (array) $cart->panel_messages() : array();
	}

	/**
	 * One promotion row — the promotion's NAME with an icon; a group deal
	 * gains a link opening the participating-products popup.
	 *
	 * @param array{text:string,name?:string,promo_id?:int,pool?:string,pool_type?:string,cat_url?:string,applied:bool} $msg Message.
	 */
	private function promo_msg_row( array $msg ): string {
		$icon = $msg['applied']
			? '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4.5 12.5l5 5L19.5 7"/></svg>'
			: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 5L5 19M7.5 9a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3zM16.5 18a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3z"/></svg>';

		$name = '' !== (string) ( $msg['name'] ?? '' ) ? (string) $msg['name'] : (string) $msg['text'];

		$html = '<div class="oc-mcart__promo' . ( $msg['applied'] ? ' oc-mcart__promo--applied' : '' ) . '">' . $icon . '<span>' . esc_html( $name ) . '</span>';

		// The participating-products link matters while the deal is still
		// within reach — once earned, it just adds noise.
		if ( ! $msg['applied'] && 'group' === (string) ( $msg['pool'] ?? '' ) && ! empty( $msg['promo_id'] ) ) {
			if ( 'categories' === (string) ( $msg['pool_type'] ?? '' ) && '' !== (string) ( $msg['cat_url'] ?? '' ) ) {
				// A category deal sends the shopper straight to the category.
				$html .= '<a class="oc-mcart__promo-link" href="' . esc_url( (string) $msg['cat_url'] ) . '">' . esc_html__( 'Participating products', 'oc-theme' ) . '</a>';
			} else {
				$html .= '<button type="button" class="oc-mcart__promo-link" data-oc-promo-list="' . absint( $msg['promo_id'] ) . '" data-name="' . esc_attr( $name ) . '">' . esc_html__( 'Participating products', 'oc-theme' ) . '</button>';
			}
		}

		$html .= '</div>';

		return $html;
	}

	/**
	 * Messages that belong to no single product — a quiet block after the
	 * items; product-specific ones render inside items_html instead.
	 */
	private function promo_msgs_html(): string {
		$rows = '';

		foreach ( $this->promo_messages() as $msg ) {
			if ( empty( $msg['keys'] ) ) {
				$rows .= $this->promo_msg_row( $msg );
			}
		}

		if ( '' === $rows ) {
			return '<div data-oc-promo-msgs hidden></div>';
		}

		return '<div class="oc-drawer__promos" data-oc-promo-msgs>' . $rows . '</div>';
	}

	/* ------------------------------------------------------------ upsells */

	/**
	 * The in-panel upsells block, in the configured style. Products already
	 * in the cart never show — adding one removes it on the next fragment
	 * refresh automatically.
	 *
	 * @param ?string $force_style Style to render instead of the setting.
	 * @param bool    $mobile      Render the mobile variant.
	 */
	private function upsells_html( ?string $force_style = null, bool $mobile = false ): string {
		$s    = self::settings();
		$attr = $mobile ? 'data-oc-cart-up-m' : 'data-oc-cart-up';

		// The mobile twin exists only to stand in for the side strip.
		if ( $mobile && 'side' !== (string) $s['up_style'] ) {
			return '<div ' . $attr . ' hidden></div>';
		}

		if ( empty( $s['up_show'] ) || WC()->cart->is_empty() ) {
			return '<div ' . $attr . ' hidden></div>';
		}

		$products = $this->upsell_products();

		if ( ! $products ) {
			return '<div ' . $attr . ' hidden></div>';
		}

		$style      = null !== $force_style ? $force_style : (string) $s['up_style'];
		$horizontal = in_array( $style, array( 'slider', 'collapse' ), true );
		$title      = '' !== (string) $s['up_title'] ? (string) $s['up_title'] : __( 'You may also like', 'oc-theme' );

		$bg   = (string) $s['up_bg'];
		$html = '<div class="oc-cartup oc-cartup--' . esc_attr( $style ) . ( $mobile ? ' oc-cartup--m' : '' ) . '" ' . $attr . ( '' !== $bg ? ' style="--oc-up-bg:' . esc_attr( $bg ) . '"' : '' ) . '>';

		self::$in_upsells = true;

		if ( 'collapse' === $style ) {
			// The fold handle: a little tab riding above the block's edge —
			// chevron down folds, and folded it flips up to reopen.
			$html .= '<button type="button" class="oc-cartup__tab" data-oc-up-toggle aria-label="' . esc_attr__( 'Minimize', 'oc-theme' ) . '"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg></button>';
		}

		$html .= '<div class="oc-cartup__head">';
		$html .= '<span class="oc-cartup__title">' . esc_html( $title ) . '</span>';
		$html .= '</div>';

		$html .= '<div class="oc-cartup__body">';

		if ( $horizontal ) {
			$html .= '<button type="button" class="oc-cartup__arrow oc-cartup__arrow--prev" data-oc-up-prev aria-label="' . esc_attr__( 'Previous', 'oc-theme' ) . '"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M15 6l-6 6 6 6"/></svg></button>';
			$html .= '<button type="button" class="oc-cartup__arrow oc-cartup__arrow--next" data-oc-up-next aria-label="' . esc_attr__( 'Next', 'oc-theme' ) . '"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg></button>';
		}

		$html .= '<div class="oc-cartup__items' . ( $horizontal ? ' oc-cartup__items--h' : '' ) . '" data-oc-up-track>';

		foreach ( $products as $product ) {
			$link = get_permalink( $product->get_id() );

			// A small flag on the image: the promotion label when one runs,
			// otherwise the sale percent — same colours as the catalogue.
			$flag = (string) apply_filters( 'promeng_product_label', '', $product->get_id() );
			if ( '' === $flag && $product->is_on_sale() ) {
				$regular_f = (float) $product->get_regular_price();
				$price_f   = (float) $product->get_price();
				if ( $regular_f > 0 && $price_f < $regular_f ) {
					$flag = sprintf( '‎-%d%%', (int) round( ( 1 - $price_f / $regular_f ) * 100 ) );
				}
			}

			$html .= '<div class="oc-cartup__item">';
			$html .= '<a class="oc-cartup__media" href="' . esc_url( $link ) . '">' . $product->get_image( 'woocommerce_thumbnail' );
			if ( '' !== $flag ) {
				$html .= '<span class="oc-cartup__flag" style="' . esc_attr( WooCommerce::flag_colors( 'oc_sale_badge_bg', 'oc_sale_badge_tx' ) ) . '">' . esc_html( $flag ) . '</span>';
			}
			$html .= '</a>';
			$html .= '<div class="oc-cartup__info">';
			$html .= '<a class="oc-cartup__name" href="' . esc_url( $link ) . '">' . esc_html( $product->get_name() ) . '</a>';
			$html .= '<span class="oc-cartup__price">' . $product->get_price_html() . '</span>';
			$html .= '</div>';

			$plus_svg = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>';
			$add_text = $horizontal ? '<span>' . esc_html__( 'Add', 'oc-theme' ) . '</span>' : '';
			$plus_svg = $plus_svg . $add_text;

			if ( $product->is_type( 'simple' ) && $product->is_purchasable() && $product->is_in_stock() ) {
				// Woo's own ajax add-to-cart JS picks this up; the fragment
				// refresh then drops the product from this list.
				$html .= '<a class="oc-cartup__add add_to_cart_button ajax_add_to_cart" href="' . esc_url( '?add-to-cart=' . $product->get_id() ) . '" data-product_id="' . absint( $product->get_id() ) . '" data-quantity="1" aria-label="' . esc_attr__( 'Add to cart', 'oc-theme' ) . '" rel="nofollow">' . $plus_svg . '</a>';
			} elseif ( $product->is_type( 'variable' ) && $product->is_in_stock() ) {
				$live = $this->live_variations( $product );

				if ( 1 === count( $live ) ) {
					// A single real option: no question, add it straight away.
					$html .= '<button type="button" class="oc-cartup__add" data-oc-up-single="' . absint( $product->get_id() ) . '" data-variation="' . absint( $live[0]->get_id() ) . '" aria-label="' . esc_attr__( 'Add to cart', 'oc-theme' ) . '">' . $plus_svg . '</button>';
				} else {
					// The plus opens a small picker asking which variation.
					$html .= '<button type="button" class="oc-cartup__add" data-oc-up-var="' . absint( $product->get_id() ) . '" data-name="' . esc_attr( $product->get_name() ) . '" aria-label="' . esc_attr__( 'Add to cart', 'oc-theme' ) . '">' . $plus_svg . '</button>';
				}
			} else {
				$html .= '<a class="oc-cartup__add oc-cartup__add--view" href="' . esc_url( $link ) . '" aria-label="' . esc_attr__( 'View product', 'oc-theme' ) . '"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg></a>';
			}

			$html .= '</div>';
		}

		$html .= '</div></div></div>';

		self::$in_upsells = false;

		return $html;
	}

	/**
	 * Candidate upsell products: the cart items' own upsells, or a category
	 * — minus anything already in the cart, hidden or out of stock.
	 *
	 * @return array<int,\WC_Product>
	 */
	private function upsell_products(): array {
		$s = self::settings();

		$in_cart = array();
		foreach ( WC()->cart->get_cart() as $item ) {
			$in_cart[] = (int) $item['product_id'];
			if ( ! empty( $item['variation_id'] ) ) {
				$in_cart[] = (int) $item['variation_id'];
			}
		}

		$ids = array();

		if ( 'category' === $s['up_source'] && (int) $s['up_cat'] > 0 ) {
			// This query would otherwise run on EVERY page view with a
			// non-empty cart. Its input is settings only, so a short
			// transient serves everyone; ten minutes of staleness in an
			// upsell list is invisible.
			$key = 'oc_cart_up_' . (int) $s['up_cat'] . '_' . ( (int) $s['up_max'] + count( $in_cart ) );
			$ids = get_transient( $key );

			if ( ! is_array( $ids ) ) {
				$ids = wc_get_products(
					array(
						'status'   => 'publish',
						'limit'    => (int) $s['up_max'] + count( $in_cart ),
						'category' => array( get_term_field( 'slug', (int) $s['up_cat'], 'product_cat' ) ),
						'orderby'  => 'date',
						'order'    => 'DESC',
						'return'   => 'ids',
					)
				);
				set_transient( $key, array_map( 'intval', (array) $ids ), 10 * MINUTE_IN_SECONDS );
			}
		} else {
			foreach ( WC()->cart->get_cart() as $item ) {
				$product = $item['data'] instanceof \WC_Product ? $item['data'] : null;
				if ( ! $product ) {
					continue;
				}
				$parent = $product->get_parent_id() ? wc_get_product( $product->get_parent_id() ) : $product;
				if ( $parent ) {
					$ids = array_merge( $ids, array_map( 'intval', $parent->get_upsell_ids() ) );
				}
			}
		}

		$ids      = array_values( array_unique( array_diff( $ids, $in_cart ) ) );
		$products = array();

		foreach ( $ids as $id ) {
			$product = wc_get_product( $id );

			if ( ! $product || ! $product->is_visible() || ! $product->is_in_stock() ) {
				continue;
			}

			$products[] = $product;

			if ( count( $products ) >= (int) $s['up_max'] ) {
				break;
			}
		}

		return $products;
	}

	/* --------------------------------------------------------------- ajax */

	/**
	 * Live quantity change from the panel: set, then answer with the same
	 * fragment payload Woo's own add-to-cart uses.
	 */
	public function ajax_qty(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- same public surface as Woo's own cart ajax.
		$key   = isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['key'] ) ) : '';
		$qty   = isset( $_POST['qty'] ) ? max( 0, (int) $_POST['qty'] ) : 1;
		$clear = ! empty( $_POST['clear'] );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( $clear ) {
			WC()->cart->empty_cart();
			WC()->cart->calculate_totals();
			\WC_AJAX::get_refreshed_fragments();
		}

		if ( '' === $key || ! WC()->cart->get_cart_item( $key ) ) {
			wp_send_json_error();
		}

		if ( 0 === $qty ) {
			WC()->cart->remove_cart_item( $key );
		} else {
			WC()->cart->set_quantity( $key, $qty );
		}

		WC()->cart->calculate_totals();

		\WC_AJAX::get_refreshed_fragments();
	}

	/**
	 * The purchasable, in-stock variations of a variable product.
	 *
	 * @param \WC_Product $product Variable product.
	 * @return array<int,\WC_Product_Variation>
	 */
	private function live_variations( \WC_Product $product ): array {
		$live = array();

		foreach ( $product->get_available_variations( 'objects' ) as $variation ) {
			if ( $variation->is_purchasable() && $variation->is_in_stock() ) {
				$live[] = $variation;
			}
		}

		return $live;
	}

	/**
	 * The variation's swatch style (its first attribute term carrying one) —
	 * the same colour/image the filters show.
	 *
	 * @param \WC_Product_Variation $variation The variation.
	 */
	private function variation_swatch( \WC_Product_Variation $variation ): string {
		foreach ( $variation->get_attributes() as $tax => $slug ) {
			$slug = (string) $slug;
			if ( '' === $slug ) {
				continue;
			}

			foreach ( array( (string) $tax, rawurldecode( (string) $tax ) ) as $taxonomy ) {
				if ( ! taxonomy_exists( $taxonomy ) ) {
					continue;
				}
				$term = get_term_by( 'slug', $slug, $taxonomy );
				if ( ! $term instanceof \WP_Term ) {
					continue;
				}

				$image = (string) get_term_meta( $term->term_id, 'oc_swatch_image', true );
				if ( '' !== $image ) {
					return 'background-image:url(' . esc_url( $image ) . ');background-size:cover;';
				}

				$color = (string) get_term_meta( $term->term_id, 'oc_swatch_color', true );
				if ( '' !== $color ) {
					return 'background-color:' . sanitize_hex_color( $color ) . ';';
				}
			}
		}

		return '';
	}

	/**
	 * Everything the quick-pick panel needs to dress a variable product:
	 * the product itself, its attributes as chip groups, and the variation
	 * map the chips resolve against.
	 */
	public function ajax_picker(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- public read-only.
		$product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$product = wc_get_product( $product_id );

		if ( ! $product || ! $product->is_type( 'variable' ) ) {
			wp_send_json_error();
		}

		$groups = array();

		foreach ( $product->get_variation_attributes() as $attr => $options ) {
			$opts = array();

			foreach ( (array) $options as $slug ) {
				$slug   = (string) $slug;
				$label  = $slug;
				$swatch = '';

				if ( taxonomy_exists( (string) $attr ) ) {
					$term = get_term_by( 'slug', $slug, (string) $attr );

					if ( $term instanceof \WP_Term ) {
						$label = $term->name;
						$image = (string) get_term_meta( $term->term_id, 'oc_swatch_image', true );
						$color = (string) get_term_meta( $term->term_id, 'oc_swatch_color', true );

						if ( '' !== $image ) {
							$swatch = 'background-image:url(' . esc_url( $image ) . ');background-size:cover;';
						} elseif ( '' !== $color ) {
							$swatch = 'background-color:' . sanitize_hex_color( $color ) . ';';
						}
					}
				}

				$opts[] = array(
					'slug'   => $slug,
					'label'  => $label,
					'swatch' => $swatch,
				);
			}

			$groups[] = array(
				'key'     => wc_variation_attribute_name( (string) $attr ),
				'label'   => wc_attribute_label( (string) $attr, $product ),
				'options' => $opts,
			);
		}

		$vars = array();

		foreach ( $product->get_available_variations() as $v ) {
			$vars[] = array(
				'id'    => (int) $v['variation_id'],
				'attrs' => (array) $v['attributes'],
				'price' => (string) $v['price_html'],
				'stock' => ! empty( $v['is_in_stock'] ),
				'img'   => isset( $v['image']['src'] ) ? (string) $v['image']['src'] : '',
			);
		}

		$image = $product->get_image_id() ? (string) wp_get_attachment_image_url( (int) $product->get_image_id(), 'woocommerce_thumbnail' ) : '';

		wp_send_json_success(
			array(
				'name'   => $product->get_name(),
				'url'    => (string) $product->get_permalink(),
				'img'    => $image,
				'price'  => $product->get_price_html(),
				'qty'    => (bool) get_theme_mod( 'oc_atc_qty', true ) && ! $product->is_sold_individually(),
				'groups' => $groups,
				'vars'   => $vars,
			)
		);
	}

	/**
	 * The purchasable variations of a product, for the in-panel picker.
	 */
	public function ajax_vars(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- public read-only.
		$product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$product = wc_get_product( $product_id );

		if ( ! $product || ! $product->is_type( 'variable' ) ) {
			wp_send_json_error();
		}

		$out = array();

		foreach ( $this->live_variations( $product ) as $variation ) {
			$out[] = array(
				'id'     => $variation->get_id(),
				'label'  => WooCommerce::variation_label( $variation ),
				'price'  => html_entity_decode( wp_strip_all_tags( wc_price( (float) $variation->get_price() ) ), ENT_QUOTES, 'UTF-8' ),
				'swatch' => $this->variation_swatch( $variation ),
			);
		}

		wp_send_json_success( array( 'variations' => $out ) );
	}

	/**
	 * Add a chosen variation (or a simple product) from the panel picker,
	 * answering with the usual fragment payload.
	 */
	public function ajax_add(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- same surface as Woo's own add.
		$product_id   = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
		$variation_id = isset( $_POST['variation_id'] ) ? absint( $_POST['variation_id'] ) : 0;
		$quantity     = isset( $_POST['quantity'] ) ? max( 1, (int) $_POST['quantity'] ) : 1;

		// The product form posts its chosen attributes as attribute_* fields.
		$attributes = array();
		foreach ( wp_unslash( $_POST ) as $field => $value ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			if ( 0 === strpos( (string) $field, 'attribute_' ) && is_scalar( $value ) ) {
				$attributes[ sanitize_text_field( $field ) ] = sanitize_text_field( (string) $value );
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( ! $attributes && $variation_id ) {
			$variation = wc_get_product( $variation_id );
			if ( $variation instanceof \WC_Product_Variation ) {
				$attributes = $variation->get_variation_attributes();
			}
		}

		$added = WC()->cart->add_to_cart( $product_id, $quantity, $variation_id, $attributes );

		if ( ! $added ) {
			$notices = wc_get_notices( 'error' );
			wc_clear_notices();
			wp_send_json_error(
				array(
					'message' => $notices ? html_entity_decode( wp_strip_all_tags( (string) $notices[0]['notice'] ), ENT_QUOTES, 'UTF-8' ) : '',
				)
			);
		}

		wc_clear_notices();
		WC()->cart->calculate_totals();
		\WC_AJAX::get_refreshed_fragments();
	}

	/**
	 * Apply or remove a coupon from inside the panel — no page leaves.
	 */
	public function ajax_coupon(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- same surface as Woo's own cart ajax.
		$code   = isset( $_POST['code'] ) ? wc_format_coupon_code( wp_unslash( (string) $_POST['code'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- wc_format_coupon_code() sanitizes.
		$remove = ! empty( $_POST['remove'] );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( '' === $code ) {
			wp_send_json_error( array( 'message' => __( 'Coupon code', 'oc-theme' ) ) );
		}

		if ( $remove ) {
			WC()->cart->remove_coupon( $code );
			WC()->cart->calculate_totals();
			wc_clear_notices();
			\WC_AJAX::get_refreshed_fragments();
		}

		$ok = WC()->cart->apply_coupon( $code );

		if ( ! $ok ) {
			$notices = wc_get_notices( 'error' );
			wc_clear_notices();
			$message = $notices ? html_entity_decode( wp_strip_all_tags( (string) $notices[0]['notice'] ), ENT_QUOTES, 'UTF-8' ) : __( 'This coupon cannot be applied.', 'oc-theme' );
			wp_send_json_error( array( 'message' => $message ) );
		}

		wc_clear_notices();
		WC()->cart->calculate_totals();
		\WC_AJAX::get_refreshed_fragments();
	}

	/**
	 * The products participating in a promotion — rows for the popup.
	 */
	public function ajax_promo_products(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- public read-only.
		$promo_id = isset( $_POST['promo_id'] ) ? absint( $_POST['promo_id'] ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( ! $promo_id || ! class_exists( '\\PromoEngine\\Cart' ) ) {
			wp_send_json_error();
		}

		$pcart = \PromoEngine\Cart::instance();

		if ( ! $pcart || ! method_exists( $pcart, 'promotion_products' ) ) {
			wp_send_json_error();
		}

		$out = array();

		foreach ( (array) $pcart->promotion_products( $promo_id, 12 ) as $id ) {
			$product = wc_get_product( $id );

			if ( ! $product || ! $product->is_visible() || ! $product->is_in_stock() ) {
				continue;
			}

			$out[] = array(
				'name'  => $product->get_name(),
				'url'   => get_permalink( $product->get_id() ),
				'img'   => (string) wp_get_attachment_image_url( (int) $product->get_image_id(), 'woocommerce_thumbnail' ),
				'price' => html_entity_decode( wp_strip_all_tags( $product->get_price_html() ), ENT_QUOTES, 'UTF-8' ),
			);
		}

		wp_send_json_success( array( 'products' => $out ) );
	}

	/* -------------------------------------------------------------- admin */

	/**
	 * Submenu under Theme settings.
	 */
	public function menu(): void {
		add_submenu_page(
			Tabs::MENU,
			__( 'Cart & mini-cart', 'oc-theme' ),
			__( 'Cart & mini-cart', 'oc-theme' ),
			'manage_woocommerce',
			'oc-cart',
			array( $this, 'admin_screen' )
		);
	}

	/**
	 * The settings screen.
	 */
	public function admin_screen(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		if ( isset( $_GET['oc_saved'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- notice only.
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Settings saved.', 'oc-theme' ) . '</p></div>';
		}

		$s = self::settings();

		$cats = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
			)
		);
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Cart & mini-cart', 'oc-theme' ); ?></h1>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="oc_cart_save" />
				<?php wp_nonce_field( 'oc_cart_save' ); ?>

				<p class="description" style="margin-block-start:0;">
					<?php
					printf(
						/* translators: %s: link to the Customizer section. */
						esc_html__( 'Where the panel opens from, its width, the footer buttons and the upsell placement are set in %s.', 'oc-theme' ),
						'<a href="' . esc_url( admin_url( 'customize.php?autofocus[section]=oc_cart_cfg' ) ) . '">' . esc_html__( 'Customize → Cart & mini-cart', 'oc-theme' ) . '</a>'
					);
					?>
				</p>

				<h2><?php esc_html_e( 'Wording', 'oc-theme' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Title', 'oc-theme' ); ?></th>
						<td><input type="text" name="title" value="<?php echo esc_attr( (string) $s['title'] ); ?>" placeholder="<?php esc_attr_e( 'My cart', 'oc-theme' ); ?>" class="regular-text" /></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Empty-cart text', 'oc-theme' ); ?></th>
						<td><input type="text" name="empty_text" value="<?php echo esc_attr( (string) $s['empty_text'] ); ?>" placeholder="<?php esc_attr_e( 'Your cart is empty', 'oc-theme' ); ?>" class="regular-text" /></td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Free-shipping bar', 'oc-theme' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Progress bar', 'oc-theme' ); ?></th>
						<td>
							<p style="margin:0;"><label><?php esc_html_e( 'Threshold override', 'oc-theme' ); ?> <input type="number" name="ship_goal" value="<?php echo esc_attr( (string) $s['ship_goal'] ); ?>" min="0" style="width:110px;" placeholder="<?php esc_attr_e( 'Auto', 'oc-theme' ); ?>" /></label></p>
							<p class="description"><?php esc_html_e( 'Empty = taken automatically from the free-shipping minimum in the shipping zones.', 'oc-theme' ); ?></p>
							<p style="margin:10px 0 0;"><input type="text" name="ship_text" value="<?php echo esc_attr( (string) $s['ship_text'] ); ?>" placeholder="<?php esc_attr_e( '[sum] left for free shipping', 'oc-theme' ); ?>" class="regular-text" /></p>
							<p style="margin:6px 0 0;"><input type="text" name="ship_done" value="<?php echo esc_attr( (string) $s['ship_done'] ); ?>" placeholder="<?php esc_attr_e( 'You earned free shipping!', 'oc-theme' ); ?>" class="regular-text" /></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Upsells in the panel', 'oc-theme' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Upsells', 'oc-theme' ); ?></th>
						<td>
							<label><input type="checkbox" name="up_show" value="1" <?php checked( 1, (int) $s['up_show'] ); ?> /> <?php esc_html_e( 'Show recommended products in the panel', 'oc-theme' ); ?></label>
							<p style="margin:10px 0 0;"><input type="text" name="up_title" value="<?php echo esc_attr( (string) $s['up_title'] ); ?>" placeholder="<?php esc_attr_e( 'You may also like', 'oc-theme' ); ?>" class="regular-text" /></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Source', 'oc-theme' ); ?></th>
						<td>
							<select name="up_source">
								<option value="items" <?php selected( 'items', $s['up_source'] ); ?>><?php esc_html_e( 'The cart items\' own upsells', 'oc-theme' ); ?></option>
								<option value="category" <?php selected( 'category', $s['up_source'] ); ?>><?php esc_html_e( 'A chosen category', 'oc-theme' ); ?></option>
							</select>
							<select name="up_cat">
								<option value="0"><?php esc_html_e( '— Category —', 'oc-theme' ); ?></option>
								<?php foreach ( $cats as $cat ) : ?>
									<option value="<?php echo absint( $cat->term_id ); ?>" <?php selected( (int) $s['up_cat'], (int) $cat->term_id ); ?>><?php echo esc_html( $cat->name ); ?></option>
								<?php endforeach; ?>
							</select>
							<label style="margin-inline-start:12px;"><?php esc_html_e( 'Max products', 'oc-theme' ); ?> <input type="number" name="up_max" value="<?php echo esc_attr( (string) $s['up_max'] ); ?>" min="1" max="12" style="width:60px;" /></label>
							<p class="description"><?php esc_html_e( 'Products already in the cart are never offered; adding one removes it from the list.', 'oc-theme' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Panel footer', 'oc-theme' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Checkout button', 'oc-theme' ); ?></th>
						<td><input type="text" name="btn_text" value="<?php echo esc_attr( (string) $s['btn_text'] ); ?>" placeholder="<?php esc_attr_e( 'Continue to checkout', 'oc-theme' ); ?>" class="regular-text" /></td>
					</tr>
				</table>

				<p style="margin-block-start:18px;"><button class="button button-primary"><?php esc_html_e( 'Save settings', 'oc-theme' ); ?></button></p>
			</form>
		</div>
		<?php
	}

	/**
	 * Persist the screen.
	 */
	public function save_settings(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die();
		}
		check_admin_referer( 'oc_cart_save' );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified above.
		// Only the keys this form still shows are written; the Customizer
		// owns the rest of the array and it must survive untouched.
		update_option(
			'oc_cart',
			array_merge(
				self::settings(),
				array(
					'title'      => sanitize_text_field( wp_unslash( (string) ( $_POST['title'] ?? '' ) ) ),
					'empty_text' => sanitize_text_field( wp_unslash( (string) ( $_POST['empty_text'] ?? '' ) ) ),
					'ship_goal'  => '' === trim( (string) ( $_POST['ship_goal'] ?? '' ) ) ? '' : (string) max( 0, (int) $_POST['ship_goal'] ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- cast to int.
					'ship_text'  => sanitize_text_field( wp_unslash( (string) ( $_POST['ship_text'] ?? '' ) ) ),
					'ship_done'  => sanitize_text_field( wp_unslash( (string) ( $_POST['ship_done'] ?? '' ) ) ),
					'up_show'    => empty( $_POST['up_show'] ) ? 0 : 1,
					'up_title'   => sanitize_text_field( wp_unslash( (string) ( $_POST['up_title'] ?? '' ) ) ),
					'up_source'  => 'category' === ( $_POST['up_source'] ?? '' ) ? 'category' : 'items', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- strict comparison stores a literal.
					'up_cat'     => (int) ( $_POST['up_cat'] ?? 0 ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- cast to int.
					'up_max'     => min( 12, max( 1, (int) ( $_POST['up_max'] ?? 5 ) ) ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- cast to int.
					'btn_text'   => sanitize_text_field( wp_unslash( (string) ( $_POST['btn_text'] ?? '' ) ) ),
				)
			),
			false
		);
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'     => 'oc-cart',
					'oc_saved' => 1,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
