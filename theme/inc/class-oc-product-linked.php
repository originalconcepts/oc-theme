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
				add_action( 'woocommerce_before_add_to_cart_button', array( $this, 'render_cross_sells' ), 20 );
				break;

			case 'tabs':
				// The tabs are priority 10; this follows them.
				add_action( 'woocommerce_after_single_product_summary', array( $this, 'render_cross_sells' ), 12 );
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
		$products = self::cross_sells();

		if ( ! $products ) {
			return;
		}

		$style = self::xsell_style();
		$title = trim( (string) get_theme_mod( 'oc_xsell_title', '' ) );
		$title = '' !== $title ? $title : __( 'Goes well with', 'oc-theme' );

		$classes = array( 'oc-xsell', 'oc-xsell--' . self::xsell_place(), 'oc-xsell--' . $style );

		if ( 'center' === (string) get_theme_mod( 'oc_xsell_align', 'start' ) ) {
			$classes[] = 'oc-xsell--center';
		}

		echo '<div class="' . esc_attr( implode( ' ', $classes ) ) . '">';
		echo '<h2 class="oc-xsell__title">' . esc_html( $title ) . '</h2>';

		switch ( $style ) {
			case 'rows':
				$this->rows( $products );
				break;

			case 'wide':
				$this->wide( $products );
				break;

			case 'grid':
			case 'slider':
				$this->cards( $products, $style );
				break;
		}

		echo '</div>';
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

			echo '<li class="oc-xsell__row" data-oc-xs="' . absint( $id ) . '">';

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
			$key = 'oc_xs[' . absint( $p->get_id() ) . '][attr][' . esc_attr( sanitize_title( $name ) ) . ']';

			echo '<select class="oc-xsell__opt" name="' . esc_attr( $key ) . '" data-oc-xs-attr aria-label="' . esc_attr( wc_attribute_label( $name, $p ) ) . '">';
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
		unset( $key, $id, $qty, $variation, $attrs, $data );

		static $busy = false;

		if ( $busy ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verified its own add-to-cart request.
		$asked = isset( $_POST['oc_xs'] ) ? (array) wp_unslash( $_POST['oc_xs'] ) : array();

		if ( ! $asked ) {
			return;
		}

		$busy    = true;
		$allowed = wp_list_pluck( self::cross_sells(), 'id' );

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
				foreach ( (array) ( $row['attr'] ?? array() ) as $name => $value ) {
					$value = sanitize_text_field( (string) $value );

					if ( '' === $value ) {
						continue 2; // An unanswered option means nothing to add.
					}

					$vars[ 'attribute_' . sanitize_title( (string) $name ) ] = $value;
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
}

	/*
	 * ----------------------------------------------------------------- related
	 */

	/**
	 * Which categories decide what counts as related.
	 *
	 * WooCommerce hands us every category the product sits in and finds
	 * neighbours in all of them. On a shop where a bedside table is filed
	 * under "Bedroom", "Side tables" and "NEW", that means the related row
	 * fills with whatever else is new — the loosest of the three wins, and
	 * the row stops being about this product at all.
	 *
	 * On the narrow setting only the most specific categories are kept:
	 *
	 *   - a category that is the parent of another one the product is in is
	 *     dropped, because the child says the same thing more precisely;
	 *   - top-level categories are then dropped too, but only if something
	 *     deeper survived — a product filed *only* at the top level keeps
	 *     what it has rather than losing its related row entirely.
	 *
	 * @param int[] $terms      Category term ids.
	 * @param int   $product_id The product.
	 * @return int[]
	 */
	public function related_terms( $terms, $product_id ) {
		unset( $product_id );

		$terms = array_values( array_unique( array_map( 'intval', (array) $terms ) ) );

		// A shelf marked "leave out of similar products" on its own edit
		// screen steps aside first. This applies whichever scope is chosen:
		// it is a statement about the category, not about the setting.
		$kept = array();
		foreach ( $terms as $id ) {
			if ( '1' !== (string) get_term_meta( $id, '_oc_rel_skip', true ) ) {
				$kept[] = $id;
			}
		}

		// Unless that would leave nothing to go on.
		if ( $kept ) {
			$terms = $kept;
		}

		if ( 'leaf' !== (string) get_theme_mod( 'oc_related_scope', 'all' ) || count( $terms ) < 2 ) {
			return $terms;
		}

		// Drop anything that is an ancestor of another term in the list.
		$ancestors = array();
		foreach ( $terms as $id ) {
			foreach ( (array) get_ancestors( $id, 'product_cat', 'taxonomy' ) as $up ) {
				$ancestors[ (int) $up ] = true;
			}
		}

		$deepest = array();
		foreach ( $terms as $id ) {
			if ( ! isset( $ancestors[ $id ] ) ) {
				$deepest[] = $id;
			}
		}

		if ( ! $deepest ) {
			return $terms;
		}

		// Of those, prefer the ones that actually sit under a parent. A
		// marketing shelf like NEW or SALE lives at the top level and would
		// otherwise drag the whole catalogue in behind it.
		$nested = array();
		foreach ( $deepest as $id ) {
			$term = get_term( $id, 'product_cat' );
			if ( $term instanceof \WP_Term && $term->parent > 0 ) {
				$nested[] = $id;
			}
		}

		return $nested ? $nested : $deepest;
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
		$cols = (int) get_theme_mod( 'oc_related_cols', 4 );
		$cols = max( 2, min( 6, $cols ) );

		$args['columns']        = $cols;
		$args['posts_per_page'] = 'slider' === self::related_layout() ? max( 8, $cols * 3 ) : $cols;

		return $args;
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
