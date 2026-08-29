<?php
/**
 * The linked-product blocks on a product page.
 *
 * WooCommerce gives a product three lists of neighbours and shows them in two
 * places: related products and up-sells go under the summary, cross-sells wait
 * in the cart. This class takes charge of all three so a shop can decide what
 * appears, where, and in what shape.
 *
 * Two jobs live here:
 *
 *   1. Related products — which ones count as related, and whether they read
 *      as a grid or a slider.
 *   2. Cross-sells — the products that go *with* this one, brought onto the
 *      product page in one of three positions, each with its own manners.
 *
 * @package OC_Theme
 */

declare( strict_types = 1 );

namespace OC\Theme;

defined( 'ABSPATH' ) || exit;

/**
 * Related products and cross-sells.
 */
final class Product_Linked {

	/**
	 * Hook in.
	 */
	public function register(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		add_filter( 'woocommerce_get_related_product_cat_terms', array( $this, 'related_terms' ), 10, 2 );
		add_filter( 'woocommerce_output_related_products_args', array( $this, 'related_args' ) );
		add_filter( 'woocommerce_breadcrumb_main_term', array( $this, 'breadcrumb_term' ), 10, 2 );

		// WooCommerce keeps each product's similar row in a transient. Without
		// this, changing the setting appears to do nothing until the caches
		// happen to expire, and the setting reads as broken.
		add_action( 'customize_save_after', array( $this, 'forget_related' ) );

		add_action( 'wp', array( $this, 'place_cross_sells' ) );
		add_action( 'woocommerce_add_to_cart', array( $this, 'add_the_ticked' ), 20, 6 );
	}

	/*
	 * ------------------------------------------------------------ cross-sells
	 */

	/**
	 * Is the cross-sell block wanted, and where does it go?
	 */
	public static function xsell_place(): string {
		if ( ! get_theme_mod( 'oc_xsell_on', false ) ) {
			return '';
		}

		$place = (string) get_theme_mod( 'oc_xsell_place', 'summary' );

		return in_array( $place, array( 'cart', 'tabs', 'summary' ), true ) ? $place : 'summary';
	}

	/**
	 * The shape it takes, which depends on where it sits.
	 *
	 * Each position offers the two styles that make sense there: beside the
	 * add-to-cart button the products are things you tick, so they are rows
	 * or selectable cards; further down the page they are things you buy on
	 * their own, so each carries its own button.
	 */
	public static function xsell_style(): string {
		switch ( self::xsell_place() ) {
			case 'cart':
				return 'grid' === (string) get_theme_mod( 'oc_xsell_style_cart', 'rows' ) ? 'grid' : 'rows';

			case 'tabs':
				return 'grid' === (string) get_theme_mod( 'oc_xsell_style_tabs', 'wide' ) ? 'grid' : 'wide';

			case 'summary':
				return 'slider' === (string) get_theme_mod( 'oc_xsell_style_sum', 'grid' ) ? 'slider' : 'grid';
		}

		return '';
	}

	/**
	 * Hang the block wherever it was asked for.
	 *
	 * Beside the add-to-cart button it must sit *inside* the product's own
	 * form, or the ticks would not travel with the submission.
	 */
	public function place_cross_sells(): void {
		if ( ! is_product() || '' === self::xsell_place() ) {
			return;
		}

		switch ( self::xsell_place() ) {
			case 'cart':
				// Above the whole add-to-cart area — above the colour
				// swatches too, which this theme draws before the form
				// opens. That puts the block outside the form, so the ticks
				// cannot travel with the submission on their own; theme.js
				// copies them in as the form is sent. See oc-xsell in the
				// script for the other half of this.
				add_action( 'woocommerce_before_add_to_cart_form', array( $this, 'render_cross_sells' ), 5 );
				break;

			case 'tabs':
				// Follow the tabs wherever the shop put them. Beside the
				// gallery they sit inside the summary column at 35, so this
				// goes at 36 and stays in that column; below, they are the
				// full-width row and this follows it there.
				if ( 'side' === (string) get_theme_mod( 'oc_product_tabs_pos', 'below' ) ) {
					add_action( 'woocommerce_single_product_summary', array( $this, 'render_cross_sells' ), 36 );
				} else {
					add_action( 'woocommerce_after_single_product_summary', array( $this, 'render_cross_sells' ), 12 );
				}
				break;

			case 'summary':
				// Ahead of up-sells (15) and related (20).
				add_action( 'woocommerce_after_single_product_summary', array( $this, 'render_cross_sells' ), 14 );
				break;
		}
	}

	/**
	 * The products this one goes with, minus anything unbuyable.
	 *
	 * @return \WC_Product[]
	 */
	public static function cross_sells( ?\WC_Product $product = null ): array {
		$product = $product ?: wc_get_product( get_the_ID() );

		if ( ! $product instanceof \WC_Product ) {
			return array();
		}

		$out = array();

		foreach ( (array) $product->get_cross_sell_ids() as $id ) {
			$item = wc_get_product( (int) $id );

			if ( ! $item instanceof \WC_Product || ! $item->is_visible() || ! $item->is_purchasable() || ! $item->is_in_stock() ) {
				continue;
			}

			$out[] = $item;
		}

		return $out;
	}

	/**
	 * Draw it.
	 */
	public function render_cross_sells(): void {
		static $drawn = false;

		if ( $drawn ) {
			return;
		}

		$products = self::cross_sells();

		if ( ! $products ) {
			return;
		}

		$drawn = true;

		$style = self::xsell_style();
		$title = trim( (string) get_theme_mod( 'oc_xsell_title', '' ) );
		$title = '' !== $title ? $title : __( 'Goes well with', 'oc-theme' );

		$classes = array( 'oc-xsell', 'oc-xsell--' . self::xsell_place(), 'oc-xsell--' . $style );

		if ( 'center' === (string) get_theme_mod( 'oc_xsell_align', 'start' ) ) {
			$classes[] = 'oc-xsell--center';
		}

		$band = self::band( 'oc_xsell_bg' );

		if ( '' !== $band['class'] ) {
			$classes[] = 'oc-linked--band';
		}

		// A card is not the product page: the theme's SKU-in-the-price-line
		// belongs to the page, not to these. Same flag the cart's cards use.
		Cart::$in_upsells = true;

		// Beside the button the block also feeds the running total printed on
		// it, so the page's own price and the shop's money format travel with
		// the markup.
		$data = '';

		if ( 'cart' === self::xsell_place() ) {
			$self  = wc_get_product( get_the_ID() );
			$data .= ' data-oc-xs-total="1"';
			$data .= ' data-main="' . esc_attr( (string) ( $self instanceof \WC_Product ? wc_get_price_to_display( $self ) : 0 ) ) . '"';
			$data .= ' data-money="' . esc_attr( (string) wp_json_encode( self::money() ) ) . '"';
		}

		echo '<div class="' . esc_attr( implode( ' ', $classes ) ) . '"' . $band['style'] . $data . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
		echo '<h2 class="oc-xsell__title">' . esc_html( $title ) . '</h2>';

		switch ( $style ) {
			case 'rows':
				$this->rows( $products );
				break;

			case 'wide':
				$this->wide( $products );
				break;

			case 'grid':
				// Beside the button a grid still has to be tickable, so it
				// gets tiles rather than the catalogue's cards.
				if ( 'cart' === self::xsell_place() ) {
					$this->tiles( $products );
					break;
				}

				$this->cards( $products, $style );
				break;

			case 'slider':
				$this->cards( $products, $style );
				break;
		}

		echo '</div>';

		Cart::$in_upsells = false;
	}

	/*
	 * ------------------------------------------------------------ the shapes
	 */

	/**
	 * One product per row, each with a tick, a quantity and its options.
	 *
	 * These ride along with the main product: what is ticked here is added
	 * when the add-to-cart button is pressed, which is why the whole thing
	 * lives inside the product's own form.
	 *
	 * @param \WC_Product[] $products The cross-sells.
	 */
	private function rows( array $products ): void {
		echo '<ul class="oc-xsell__rows">';

		foreach ( $products as $p ) {
			$id = $p->get_id();

			echo '<li class="oc-xsell__row" data-oc-xs="' . absint( $id ) . '" data-price="' . esc_attr( (string) wc_get_price_to_display( $p ) ) . '">';

			echo '<label class="oc-xsell__tick">';
			echo '<input type="checkbox" name="oc_xs[' . absint( $id ) . '][on]" value="1" data-oc-xs-on>';
			echo '<span class="oc-xsell__box" aria-hidden="true"></span>';
			echo '<span class="screen-reader-text">' . esc_html( $p->get_name() ) . '</span>';
			echo '</label>';

			echo '<button type="button" class="oc-xsell__media" data-oc-xs-open="' . absint( $id ) . '" aria-label="' . esc_attr( $p->get_name() ) . '">';
			echo $p->get_image( 'woocommerce_gallery_thumbnail' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WooCommerce markup.
			echo '</button>';

			echo '<div class="oc-xsell__info">';
			echo '<button type="button" class="oc-xsell__name" data-oc-xs-open="' . absint( $id ) . '">' . esc_html( $p->get_name() ) . '</button>';
			echo '<span class="oc-xsell__price">' . wp_kses_post( $p->get_price_html() ) . '</span>';
			$this->options( $p );
			echo '</div>';

			echo '<div class="oc-xsell__qty" data-oc-xs-qty hidden>';
			echo '<button type="button" class="oc-xsell__step" data-oc-xs-step="-1" aria-label="' . esc_attr__( 'Less', 'oc-theme' ) . '">&minus;</button>';
			echo '<input type="number" class="oc-xsell__n" name="oc_xs[' . absint( $id ) . '][qty]" value="1" min="1" step="1" inputmode="numeric" aria-label="' . esc_attr__( 'Quantity', 'oc-theme' ) . '">';
			echo '<button type="button" class="oc-xsell__step" data-oc-xs-step="1" aria-label="' . esc_attr__( 'More', 'oc-theme' ) . '">+</button>';
			echo '</div>';

			echo '</li>';
		}

		echo '</ul>';
	}

	/**
	 * The attribute dropdowns for a variable cross-sell.
	 *
	 * Only rendered in the row style, where there is room to answer them;
	 * everywhere else a variable product opens the quick-pick panel instead.
	 *
	 * @param \WC_Product $p The product.
	 */
	private function options( \WC_Product $p ): void {
		if ( ! $p->is_type( 'variable' ) ) {
			return;
		}

		$attributes = $p->get_variation_attributes();

		if ( ! $attributes ) {
			return;
		}

		echo '<div class="oc-xsell__opts">';

		foreach ( $attributes as $name => $values ) {
			// The full key the variation is stored under, carried whole so the
			// server never has to rebuild it. A Hebrew attribute such as
			// pa_צבע lives as attribute_pa_%d7%a6%d7%91%d7%a2, and only that
			// spelling finds the variation again.
			$key = 'oc_xs[' . absint( $p->get_id() ) . '][attr][' . esc_attr( wc_variation_attribute_name( $name ) ) . ']';

			// The data attribute carries the same key the name does, because
			// the block sits outside the form and theme.js rebuilds these
			// fields inside it as the form is sent.
			echo '<select class="oc-xsell__opt" name="' . esc_attr( $key ) . '" data-oc-xs-attr="' . esc_attr( wc_variation_attribute_name( $name ) ) . '" aria-label="' . esc_attr( wc_attribute_label( $name, $p ) ) . '">';
			echo '<option value="">' . esc_html( sprintf( /* translators: %s: attribute name, e.g. Colour. */ __( 'Choose %s', 'oc-theme' ), wc_attribute_label( $name, $p ) ) ) . '</option>';

			foreach ( $values as $value ) {
				$label = $value;

				if ( taxonomy_exists( $name ) ) {
					$term  = get_term_by( 'slug', $value, $name );
					$label = ( $term && ! is_wp_error( $term ) ) ? $term->name : $value;
				}

				echo '<option value="' . esc_attr( $value ) . '">' . esc_html( $label ) . '</option>';
			}

			echo '</select>';
		}

		echo '</div>';
	}

	/**
	 * Square tiles you can tick, in a row that scrolls.
	 *
	 * The grid beside the add-to-cart button is the same promise as the
	 * rows — pick some, they come along — so a tile is a label wrapping a
	 * real checkbox rather than a card with a link. A product with options
	 * cannot be answered in a tile this size, so that one opens the
	 * quick-pick panel instead of pretending to be tickable.
	 *
	 * @param \WC_Product[] $products The cross-sells.
	 */
	private function tiles( array $products ): void {
		echo '<div class="oc-xsell__tiles" data-oc-slider>';

		foreach ( $products as $p ) {
			$id       = $p->get_id();
			$variable = ! $p->is_type( 'simple' );

			if ( $variable ) {
				echo '<button type="button" class="oc-xsell__tile oc-xsell__tile--opts" data-oc-xs-open="' . absint( $id ) . '">';
				echo '<span class="oc-xsell__tilepic">' . $p->get_image( 'woocommerce_thumbnail' ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WooCommerce markup.
				echo '<span class="oc-xsell__tilename">' . esc_html( $p->get_name() ) . '</span>';
				echo '<span class="oc-xsell__tileprice">' . wp_kses_post( $p->get_price_html() ) . '</span>';
				echo '<span class="oc-xsell__tilehint">' . esc_html__( 'Choose options', 'oc-theme' ) . '</span>';
				echo '</button>';
				continue;
			}

			echo '<label class="oc-xsell__tile" data-oc-xs="' . absint( $id ) . '" data-price="' . esc_attr( (string) wc_get_price_to_display( $p ) ) . '">';
			echo '<input type="checkbox" name="oc_xs[' . absint( $id ) . '][on]" value="1" data-oc-xs-on>';
			echo '<input type="hidden" name="oc_xs[' . absint( $id ) . '][qty]" value="1">';
			echo '<span class="oc-xsell__tilepic">' . $p->get_image( 'woocommerce_thumbnail' ) . '<span class="oc-xsell__box" aria-hidden="true"></span></span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WooCommerce markup.
			echo '<span class="oc-xsell__tilename">' . esc_html( $p->get_name() ) . '</span>';
			echo '<span class="oc-xsell__tileprice">' . wp_kses_post( $p->get_price_html() ) . '</span>';
			echo '</label>';
		}

		echo '</div>';
	}

	/**
	 * A product across the width, several of them a slider.
	 *
	 * Down the page each product stands on its own, so each carries a full
	 * width button of its own rather than a tick.
	 *
	 * @param \WC_Product[] $products The cross-sells.
	 */
	private function wide( array $products ): void {
		$many = count( $products ) > 1;

		echo '<div class="oc-xsell__wides' . ( $many ? ' oc-xsell__wides--slide' : '' ) . '"' . ( $many ? ' data-oc-slider' : '' ) . '>';

		foreach ( $products as $p ) {
			echo '<div class="oc-xsell__wide">';

			echo '<button type="button" class="oc-xsell__media" data-oc-xs-open="' . absint( $p->get_id() ) . '" aria-label="' . esc_attr( $p->get_name() ) . '">';
			echo $p->get_image( 'woocommerce_thumbnail' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WooCommerce markup.
			echo '</button>';

			echo '<div class="oc-xsell__info">';
			echo '<button type="button" class="oc-xsell__name" data-oc-xs-open="' . absint( $p->get_id() ) . '">' . esc_html( $p->get_name() ) . '</button>';
			echo '<span class="oc-xsell__price">' . wp_kses_post( $p->get_price_html() ) . '</span>';
			echo '</div>';

			echo $this->own_button( $p ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.

			echo '</div>';
		}

		echo '</div>';
	}

	/**
	 * A product's own add-to-cart, for the styles that stand alone.
	 *
	 * Anything with options opens the quick-pick panel rather than guessing
	 * which one was meant.
	 *
	 * @param \WC_Product $p The product.
	 */
	private function own_button( \WC_Product $p ): string {
		$label = __( 'Add to cart', 'oc-theme' );

		if ( $p->is_type( 'simple' ) ) {
			return '<a href="' . esc_url( '?add-to-cart=' . $p->get_id() ) . '" rel="nofollow"' .
				' class="oc-xsell__add button add_to_cart_button ajax_add_to_cart"' .
				' data-product_id="' . absint( $p->get_id() ) . '" data-quantity="1">' . esc_html( $label ) . '</a>';
		}

		return '<button type="button" class="oc-xsell__add button" data-oc-xs-open="' . absint( $p->get_id() ) . '">' .
			esc_html__( 'Choose options', 'oc-theme' ) . '</button>';
	}

	/**
	 * The catalogue's own cards, in a grid or a slider.
	 *
	 * Using the shop's card template means these carry whatever the theme
	 * already decided a card shows — the add button included, if the setting
	 * asks for one.
	 *
	 * @param \WC_Product[] $products The cross-sells.
	 * @param string        $style    grid or slider.
	 */
	private function cards( array $products, string $style ): void {
		$cols = max( 2, min( 6, (int) get_theme_mod( 'oc_xsell_cols', 4 ) ) );

		echo '<div class="oc-linked oc-linked--' . esc_attr( 'slider' === $style ? 'slider' : 'grid' ) . '" style="--oc-linked-cols:' . (int) $cols . '">';

		$open = woocommerce_product_loop_start( false );
		$open = str_replace( 'class="products', 'class="products columns-' . (int) $cols . ' ', $open );

		if ( 'slider' === $style ) {
			$open = str_replace( '<ul ', '<ul data-oc-slider ', $open );
		}

		echo $open; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WooCommerce markup.

		$original = $GLOBALS['post'];

		foreach ( $products as $p ) {
			$GLOBALS['post'] = get_post( $p->get_id() ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

			setup_postdata( $GLOBALS['post'] );
			wc_get_template_part( 'content', 'product' );
		}

		$GLOBALS['post'] = $original; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		wp_reset_postdata();

		woocommerce_product_loop_end();

		echo '</div>';
	}

	/*
	 * ------------------------------------------------------- riding along
	 */

	/**
	 * Add whatever was ticked beside the add-to-cart button.
	 *
	 * Fires after the main product went in. Adding the extras sets this
	 * same hook off again, so a flag keeps it from chasing its own tail.
	 *
	 * @param string $key       Cart item key.
	 * @param int    $id        Product added.
	 * @param int    $qty       How many.
	 * @param int    $variation Variation id.
	 * @param array  $attrs     Chosen attributes.
	 * @param array  $data      Extra cart item data.
	 */
	public function add_the_ticked( $key, $id, $qty, $variation, $attrs, $data ): void {
		unset( $key, $qty, $variation, $attrs, $data );

		static $busy = false;

		if ( $busy ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verified its own add-to-cart request.
		$asked = isset( $_POST['oc_xs'] ) ? (array) wp_unslash( $_POST['oc_xs'] ) : array();

		if ( ! $asked ) {
			return;
		}

		$busy = true;

		// The product being added comes from the hook, never from the loop:
		// this theme adds to cart over ajax, where there is no post in hand
		// and get_the_ID() is empty. That was the whole bug — the list of
		// what this page may offer came back empty, so every tick was
		// refused as something the page never offered.
		$parent  = wc_get_product( (int) $id );
		$allowed = $parent instanceof \WC_Product ? wp_list_pluck( self::cross_sells( $parent ), 'id' ) : array();

		if ( ! $allowed ) {
			$busy = false;
			return;
		}

		foreach ( $asked as $pid => $row ) {
			$pid = absint( $pid );

			// Only the products this page actually offered.
			if ( ! $pid || ! in_array( $pid, $allowed, true ) || ! is_array( $row ) || empty( $row['on'] ) ) {
				continue;
			}

			$product = wc_get_product( $pid );

			if ( ! $product instanceof \WC_Product ) {
				continue;
			}

			$want = max( 1, (int) ( $row['qty'] ?? 1 ) );
			$vid  = 0;
			$vars = array();

			if ( $product->is_type( 'variable' ) ) {
				$sent = (array) ( $row['attr'] ?? array() );

				// Keys come from the product, not the request, and the value
				// gets sanitize_title() rather than sanitize_text_field() —
				// the latter eats percent-encoded octets, which is the whole
				// of a Hebrew attribute's key and slug.
				foreach ( array_keys( $product->get_variation_attributes() ) as $name ) {
					$key   = wc_variation_attribute_name( (string) $name );
					$value = isset( $sent[ $key ] ) ? sanitize_title( wp_unslash( (string) $sent[ $key ] ) ) : '';

					if ( '' === $value ) {
						continue 2; // An unanswered option means nothing to add.
					}

					$vars[ $key ] = $value;
				}

				$data_store = \WC_Data_Store::load( 'product' );
				$vid        = (int) $data_store->find_matching_product_variation( $product, $vars );

				if ( ! $vid ) {
					continue;
				}
			}

			WC()->cart->add_to_cart( $pid, $want, $vid, $vars );
		}

		$busy = false;
	}

	/*
	 * ----------------------------------------------------------------- related
	 */

	/**
	 * The one category a product most belongs to.
	 *
	 * A product is filed in several places at once — its real category, that
	 * category's parent, and shelves like NEW or SALE that are not really
	 * categories at all. Everything that has to name *the* category needs the
	 * same answer, so it is worked out once here.
	 *
	 * The narrowest one wins: deepest in the tree first, and where two sit at
	 * the same depth, the one holding fewer products. That is what "more
	 * specific" means in practice — a shelf holding most of the catalogue
	 * tells you almost nothing, while the category with four things in it
	 * tells you what the product is. Ties break on the id so the answer never
	 * moves on its own.
	 *
	 * For "Sofas and armchairs / Sofas" it gives Sofas: deeper, and the path
	 * the shopper walked.
	 *
	 * @param int $product_id The product.
	 * @return \WP_Term|null
	 */
	public static function primary_term( int $product_id ): ?\WP_Term {
		$terms = wp_get_post_terms( $product_id, 'product_cat' );

		if ( is_wp_error( $terms ) || ! $terms ) {
			return null;
		}

		$best  = null;
		$score = null;

		foreach ( $terms as $term ) {
			if ( ! $term instanceof \WP_Term ) {
				continue;
			}

			$here = array(
				-1 * count( (array) get_ancestors( $term->term_id, 'product_cat', 'taxonomy' ) ), // deeper first
				(int) $term->count,                                                               // then narrower
				(int) $term->term_id,                                                             // then steady
			);

			if ( null === $score || $here < $score ) {
				$score = $here;
				$best  = $term;
			}
		}

		return $best;
	}

	/**
	 * Which categories decide what counts as related.
	 *
	 * WooCommerce hands us every category the product sits in and finds
	 * neighbours in all of them, so a table filed under "Living room tables"
	 * and NEW fills its similar row with whatever else happens to be new —
	 * the loosest of them wins and the row stops being about the product.
	 *
	 * On the narrow setting the row is drawn from the one category above,
	 * which is also the one the breadcrumb shows. What the shopper reads at
	 * the top of the page and what they get at the bottom then agree, and
	 * nothing has to be configured per category for that to be true.
	 *
	 * @param int[] $terms      Category term ids.
	 * @param int   $product_id The product.
	 * @return int[]
	 */
	public function related_terms( $terms, $product_id ) {
		$terms = array_values( array_unique( array_map( 'intval', (array) $terms ) ) );

		if ( 'leaf' !== (string) get_theme_mod( 'oc_related_scope', 'leaf' ) || count( $terms ) < 2 ) {
			return $terms;
		}

		$primary = self::primary_term( (int) $product_id );

		return $primary ? array( $primary->term_id ) : $terms;
	}

	/**
	 * The breadcrumb names the same category.
	 *
	 * WooCommerce picks whichever term sorts first by parent, which on this
	 * catalogue meant a product's path read "Home / NEW / Ball table" — the
	 * shelf, not the category. One rule now answers both, so the path and
	 * the similar row cannot disagree.
	 *
	 * @param \WP_Term   $main  The term WooCommerce chose.
	 * @param \WP_Term[] $terms All of the product's terms.
	 * @return \WP_Term
	 */
	public function breadcrumb_term( $main, $terms ) {
		unset( $terms );

		$post_id = get_queried_object_id();

		if ( ! $post_id ) {
			return $main;
		}

		$primary = self::primary_term( (int) $post_id );

		return $primary ?: $main;
	}

	/**
	 * Throw away the cached similar rows.
	 *
	 * Bumping WooCommerce's product cache version does it wholesale and works
	 * whether the transients live in the database or in Redis, which deleting
	 * option rows by hand would not.
	 */
	public function forget_related(): void {
		global $wpdb;

		// Bumping WooCommerce's product cache version does not reach these:
		// wc_get_related_products() keeps a plain wc_related_<id> transient
		// that carries no version in its name. They have to be deleted by
		// name, which is why the option table is asked for the list and
		// delete_transient() then does the removing — that way an object
		// cache holding them is cleared too, rather than only the rows.
		$names = (array) $wpdb->get_col(
			"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_wc\_related\_%'"
		);

		foreach ( $names as $option ) {
			delete_transient( substr( (string) $option, strlen( '_transient_' ) ) );
		}

		if ( class_exists( 'WC_Cache_Helper' ) ) {
			\WC_Cache_Helper::get_transient_version( 'product', true );
		}
	}

	/**
	 * How many related products to fetch, and in how many columns.
	 *
	 * A slider wants more than a grid — a row that scrolls and runs out
	 * after four looks broken.
	 *
	 * @param array<string,mixed> $args Loop args.
	 * @return array<string,mixed>
	 */
	public function related_args( $args ) {
		$cols = max( 2, min( 6, (int) get_theme_mod( 'oc_related_cols', 4 ) ) );

		// How many to show is its own question. It used to fall out of the
		// column count -- a grid showed exactly one row -- which meant asking
		// for four across quietly asked for four products.
		$count = max( 2, min( 24, (int) get_theme_mod( 'oc_related_count', 8 ) ) );

		$args['columns']        = $cols;
		$args['posts_per_page'] = $count;

		return $args;
	}

	/**
	 * How this shop writes an amount, so a running total can match it.
	 *
	 * @return array<string,mixed>
	 */
	public static function money(): array {
		return array(
			'symbol'   => html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ),
			'decimals' => wc_get_price_decimals(),
			'dot'      => wc_get_price_decimal_separator(),
			'thousand' => wc_get_price_thousand_separator(),
			'format'   => get_option( 'woocommerce_currency_pos', 'left' ),
			// This shop writes 1,000 rather than 1,000.00, and a running
			// total that ignored that would not look like its own prices.
			'trim'     => 'yes' === get_option( 'woocommerce_price_trim_zeros', 'no' ),
		);
	}

	/**
	 * The band a linked area sits on, if the shop asked for one.
	 *
	 * Returns the class and the inline custom property together, so a caller
	 * only has to drop them in. With no colour set nothing is added and the
	 * area sits on the page exactly as before.
	 *
	 * @param string $mod The theme mod holding the colour.
	 * @return array{class:string,style:string}
	 */
	public static function band( string $mod ): array {
		$colour = trim( (string) get_theme_mod( $mod, '' ) );

		if ( '' === $colour || ! preg_match( '/^#[0-9a-f]{3,8}$/i', $colour ) ) {
			return array(
				'class' => '',
				'style' => '',
			);
		}

		return array(
			'class' => ' oc-linked--band',
			'style' => ' style="--oc-band:' . esc_attr( $colour ) . '"',
		);
	}

	/**
	 * Grid or slider for the related row.
	 */
	public static function related_layout(): string {
		$layout = (string) get_theme_mod( 'oc_related_layout', 'grid' );

		return 'slider' === $layout ? 'slider' : 'grid';
	}

	/**
	 * Where the related row's cards sit when they do not fill the width.
	 */
	public static function related_align(): string {
		$align = (string) get_theme_mod( 'oc_related_align', 'start' );

		return 'center' === $align ? 'center' : 'start';
	}
}
