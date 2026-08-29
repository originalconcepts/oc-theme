<?php
/**
 * Bought together — a bundle offered on the product page.
 *
 * A camera is rarely the whole purchase: it wants a second battery, a bag,
 * a tripod. This puts that group on the page as one thing, priced as one
 * thing, and adds it in one press — with a discount for taking the lot, if
 * the shop wants to give one.
 *
 * The bundle is set per product, because only a person knows that this
 * tripod belongs with that camera. Everything else follows from the list:
 *
 *   - Each item can be unticked, and the total answers immediately.
 *   - The button says what it will actually do — "Add all to cart" while
 *     several are ticked, plainly "Add to cart" once one is left, and it
 *     goes quiet when nothing is.
 *   - The discount is applied in the cart rather than baked into a price,
 *     so it survives a change of mind: take one line out of the cart and
 *     the discount recalculates itself, and drop below two and it simply
 *     stops applying. Nothing has to be cleaned up afterwards.
 *
 * A note on where the discount lives. Rewriting item prices would make the
 * cart lie about what each thing costs, and a coupon would collide with the
 * shop's own. A negative fee says what happened, in words, on its own line.
 *
 * @package OC_Theme
 */

declare( strict_types = 1 );

namespace OC\Theme;

defined( 'ABSPATH' ) || exit;

/**
 * The bundle.
 */
final class Bought_Together {

	/**
	 * Where the bundle's members live.
	 */
	private const IDS = '_oc_bt_ids';

	/**
	 * How much comes off, and in what units.
	 */
	private const AMOUNT = '_oc_bt_amount';

	/**
	 * percent or fixed.
	 */
	private const KIND = '_oc_bt_kind';

	/**
	 * This bundle's own heading, when the shop wants one.
	 */
	private const TITLE = '_oc_bt_title';

	/**
	 * The cart-item key that remembers which bundle a line came from.
	 */
	private const TAG = 'oc_bt';

	/**
	 * Hook in.
	 */
	public function register(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		// The shop's side.
		add_action( 'woocommerce_product_options_related', array( $this, 'fields' ) );
		add_action( 'woocommerce_admin_process_product_object', array( $this, 'save' ) );

		// The shopper's side.
		add_action( 'wp', array( $this, 'place' ) );
		add_action( 'wp_ajax_oc_bt_add', array( $this, 'ajax_add' ) );
		add_action( 'wp_ajax_nopriv_oc_bt_add', array( $this, 'ajax_add' ) );

		// The discount.
		add_action( 'woocommerce_cart_calculate_fees', array( $this, 'apply_discount' ) );
	}

	/*
	 * ------------------------------------------------------------- the shop
	 */

	/**
	 * The bundle fields, under Linked Products where the neighbours are.
	 */
	public function fields(): void {
		global $post;

		$product = wc_get_product( $post->ID ?? 0 );

		if ( ! $product instanceof \WC_Product ) {
			return;
		}

		$ids = self::ids( $product->get_id() );

		echo '<div class="options_group">';
		echo '<p class="form-field"><label for="oc_bt_ids">' . esc_html__( 'Bought together', 'oc-theme' ) . '</label>';
		echo '<select class="wc-product-search" multiple="multiple" style="width:50%" id="oc_bt_ids" name="oc_bt_ids[]" data-placeholder="' . esc_attr__( 'Search for a product…', 'oc-theme' ) . '" data-action="woocommerce_json_search_products_and_variations" data-exclude="' . absint( $product->get_id() ) . '">';

		foreach ( $ids as $id ) {
			$item = wc_get_product( $id );

			if ( $item ) {
				echo '<option value="' . absint( $id ) . '" selected="selected">' . esc_html( wp_strip_all_tags( $item->get_formatted_name() ) ) . '</option>';
			}
		}

		echo '</select> ' . wc_help_tip( __( 'Offered on this product\'s page as one bundle, with this product. The shopper can untick any of them.', 'oc-theme' ) ) . '</p>';
		echo '</div>';

		echo '<div class="options_group">';

		woocommerce_wp_text_input(
			array(
				'id'          => 'oc_bt_title',
				'value'       => (string) get_post_meta( $product->get_id(), self::TITLE, true ),
				'label'       => __( 'Bundle heading', 'oc-theme' ),
				'desc_tip'    => true,
				'description' => __( 'Left empty, the heading from the Customizer is used.', 'oc-theme' ),
			)
		);

		woocommerce_wp_select(
			array(
				'id'      => 'oc_bt_kind',
				'value'   => self::kind( $product->get_id() ),
				'label'   => __( 'Bundle discount', 'oc-theme' ),
				'options' => array(
					'none'    => __( 'No discount', 'oc-theme' ),
					'percent' => __( 'Percentage off', 'oc-theme' ),
					'fixed'   => __( 'Amount off', 'oc-theme' ),
				),
			)
		);

		woocommerce_wp_text_input(
			array(
				'id'                => 'oc_bt_amount',
				'value'             => (string) get_post_meta( $product->get_id(), self::AMOUNT, true ),
				'label'             => __( 'How much', 'oc-theme' ),
				'type'              => 'number',
				'custom_attributes' => array(
					'step' => '0.01',
					'min'  => '0',
				),
				'desc_tip'          => true,
				'description'       => __( 'Comes off in the cart once two or more of the bundle are in it, and recalculates itself if one is taken out.', 'oc-theme' ),
			)
		);

		echo '</div>';
	}

	/**
	 * Keep what was chosen.
	 *
	 * @param \WC_Product $product The product being saved.
	 */
	public function save( $product ): void {
		if ( ! $product instanceof \WC_Product ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce checks the product form's nonce before this fires.
		$ids = isset( $_POST['oc_bt_ids'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['oc_bt_ids'] ) ) : array();
		$ids = array_values( array_filter( array_unique( $ids ) ) );

		// A bundle cannot contain the thing it hangs off.
		$ids = array_values( array_diff( $ids, array( $product->get_id() ) ) );

		$kind = isset( $_POST['oc_bt_kind'] ) ? sanitize_key( (string) wp_unslash( $_POST['oc_bt_kind'] ) ) : 'none';
		$kind = in_array( $kind, array( 'none', 'percent', 'fixed' ), true ) ? $kind : 'none';

		$amount = isset( $_POST['oc_bt_amount'] ) ? wc_format_decimal( wp_unslash( $_POST['oc_bt_amount'] ) ) : '';
		$title  = isset( $_POST['oc_bt_title'] ) ? sanitize_text_field( wp_unslash( $_POST['oc_bt_title'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		// A percentage over 100 would pay the shopper to shop.
		if ( 'percent' === $kind ) {
			$amount = (string) min( 100, max( 0, (float) $amount ) );
		}

		$product->update_meta_data( self::IDS, $ids );
		$product->update_meta_data( self::KIND, $kind );
		$product->update_meta_data( self::AMOUNT, $amount );
		$product->update_meta_data( self::TITLE, $title );
	}

	/*
	 * ------------------------------------------------------------ the reading
	 */

	/**
	 * The bundle's members, as stored.
	 *
	 * @param int $product_id The product.
	 * @return int[]
	 */
	public static function ids( int $product_id ): array {
		$ids = get_post_meta( $product_id, self::IDS, true );

		return array_values( array_filter( array_map( 'absint', (array) $ids ) ) );
	}

	/**
	 * What kind of discount, if any.
	 *
	 * @param int $product_id The product.
	 */
	public static function kind( int $product_id ): string {
		$kind = (string) get_post_meta( $product_id, self::KIND, true );

		return in_array( $kind, array( 'none', 'percent', 'fixed' ), true ) ? $kind : 'none';
	}

	/**
	 * How much comes off.
	 *
	 * @param int $product_id The product.
	 */
	public static function amount( int $product_id ): float {
		return (float) get_post_meta( $product_id, self::AMOUNT, true );
	}

	/**
	 * The buyable members of a product's bundle, the product first.
	 *
	 * @param \WC_Product $product The product whose page this is.
	 * @return \WC_Product[]
	 */
	public static function members( \WC_Product $product ): array {
		$out = array();

		foreach ( self::ids( $product->get_id() ) as $id ) {
			$item = wc_get_product( $id );

			if ( ! $item instanceof \WC_Product ) {
				continue;
			}

			// A variable product joins the bundle carrying its own options,
			// answered in the card. Anything more exotic — grouped, external —
			// cannot be bought in one press and is left out rather than
			// offered and then refused.
			if ( ! $item->is_type( 'simple' ) && ! $item->is_type( 'variable' ) ) {
				continue;
			}

			if ( ! $item->is_visible() || ! $item->is_purchasable() || ! $item->is_in_stock() ) {
				continue;
			}

			$out[] = $item;
		}

		return $out;
	}

	/*
	 * ---------------------------------------------------------- the shopper
	 */

	/**
	 * Put the bundle under the gallery and details.
	 */
	public function place(): void {
		if ( ! is_product() || ! get_theme_mod( 'oc_bt_on', true ) ) {
			return;
		}

		// Ahead of the tabs, where the eye still is.
		add_action( 'woocommerce_after_single_product_summary', array( $this, 'render' ), 9 );
	}

	/**
	 * Draw it.
	 */
	public function render(): void {
		$product = wc_get_product( get_the_ID() );

		if ( ! $product instanceof \WC_Product || ! $product->is_purchasable() ) {
			return;
		}

		if ( ! $product->is_type( 'simple' ) && ! $product->is_type( 'variable' ) ) {
			return;
		}

		$members = self::members( $product );

		if ( ! $members ) {
			return;
		}

		$all   = array_merge( array( $product ), $members );
		$title = trim( (string) get_post_meta( $product->get_id(), self::TITLE, true ) );

		if ( '' === $title ) {
			$title = trim( (string) get_theme_mod( 'oc_bt_title', '' ) );
		}

		if ( '' === $title ) {
			$title = __( 'Often bought together', 'oc-theme' );
		}

		$kind   = self::kind( $product->get_id() );
		$amount = self::amount( $product->get_id() );

		?>
		<div class="oc-bt" data-oc-bt="<?php echo absint( $product->get_id() ); ?>"
			data-kind="<?php echo esc_attr( $kind ); ?>"
			data-amount="<?php echo esc_attr( (string) $amount ); ?>"
			data-money="<?php echo esc_attr( (string) wp_json_encode( self::money() ) ); ?>">

			<h2 class="oc-bt__title"><?php echo esc_html( $title ); ?></h2>

			<div class="oc-bt__body">
				<ul class="oc-bt__items">
					<?php
					foreach ( $all as $i => $item ) :
						$self = 0 === $i;
						?>
						<li class="oc-bt__item is-on<?php echo $item->is_type( 'variable' ) ? ' oc-bt__item--opts' : ''; ?>"
							data-oc-bt-item="<?php echo absint( $item->get_id() ); ?>"
							data-self="<?php echo $self ? '1' : '0'; ?>"
							<?php if ( $item->is_type( 'variable' ) ) : ?>
								data-variations="<?php echo esc_attr( (string) wp_json_encode( self::variation_map( $item ) ) ); ?>"
							<?php endif; ?>
							data-price="<?php echo esc_attr( (string) wc_get_price_to_display( $item ) ); ?>">
							<label class="oc-bt__tick">
								<input type="checkbox" checked <?php disabled( $self ); ?> data-oc-bt-on>
								<span class="oc-bt__box" aria-hidden="true"></span>
								<span class="screen-reader-text"><?php echo esc_html( $item->get_name() ); ?></span>
							</label>

							<a class="oc-bt__media" href="<?php echo esc_url( get_permalink( $item->get_id() ) ); ?>">
								<?php echo $item->get_image( 'woocommerce_thumbnail' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WooCommerce markup. ?>
							</a>

							<div class="oc-bt__info">
								<a class="oc-bt__name" href="<?php echo esc_url( get_permalink( $item->get_id() ) ); ?>"><?php echo esc_html( $item->get_name() ); ?></a>
								<span class="oc-bt__price"><?php echo wp_kses_post( $item->get_price_html() ); ?></span>
								<?php if ( $self ) : ?>
									<span class="oc-bt__this"><?php esc_html_e( 'This product', 'oc-theme' ); ?></span>
								<?php endif; ?>
								<?php
								// The page's own product is answered by the form
								// above; a companion answers here.
								if ( ! $self ) {
									self::options( $item );
								}
								?>
							</div>
						</li>
					<?php endforeach; ?>
				</ul>

				<div class="oc-bt__sum">
					<div class="oc-bt__totals">
						<span class="oc-bt__was" data-oc-bt-was hidden></span>
						<span class="oc-bt__now" data-oc-bt-now></span>
						<span class="oc-bt__saved" data-oc-bt-saved hidden></span>
					</div>

					<button type="button" class="oc-bt__add button alt" data-oc-bt-add>
						<?php esc_html_e( 'Add all to cart', 'oc-theme' ); ?>
					</button>

					<p class="oc-bt__note" data-oc-bt-note role="status" aria-live="polite"></p>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * The option pickers for a variable member.
	 *
	 * @param \WC_Product $item The product.
	 */
	private static function options( \WC_Product $item ): void {
		if ( ! $item->is_type( 'variable' ) ) {
			return;
		}

		$attributes = $item->get_variation_attributes();

		if ( ! $attributes ) {
			return;
		}

		echo '<span class="oc-bt__opts">';

		foreach ( $attributes as $name => $values ) {
			// wc_variation_attribute_name() builds exactly the key the variation
			// is stored under. It matters here: a Hebrew attribute such as
			// pa_צבע is held as attribute_pa_%d7%a6%d7%91%d7%a2, and only that
			// spelling finds the variation again.
			echo '<select class="oc-bt__opt" data-oc-bt-attr="' . esc_attr( wc_variation_attribute_name( $name ) ) . '" aria-label="' . esc_attr( wc_attribute_label( $name, $item ) ) . '">';
			echo '<option value="">' . esc_html( sprintf( /* translators: %s: attribute name, e.g. Colour. */ __( 'Choose %s', 'oc-theme' ), wc_attribute_label( $name, $item ) ) ) . '</option>';

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

		echo '</span>';
	}

	/**
	 * Every buyable variation of a product, with what it costs.
	 *
	 * Sent to the browser so the running total can follow a change of colour
	 * without asking the server, and so the right variation id travels back
	 * when the bundle is added.
	 *
	 * @param \WC_Product $item The variable product.
	 * @return array<int,array<string,mixed>>
	 */
	private static function variation_map( \WC_Product $item ): array {
		if ( ! $item->is_type( 'variable' ) ) {
			return array();
		}

		$out = array();

		foreach ( $item->get_available_variations() as $variation ) {
			$id = (int) ( $variation['variation_id'] ?? 0 );

			if ( ! $id || empty( $variation['is_purchasable'] ) || empty( $variation['is_in_stock'] ) ) {
				continue;
			}

			$object = wc_get_product( $id );

			$out[] = array(
				'id'    => $id,
				// Left exactly as WooCommerce spells them, which is how they
				// are stored and how they must come back.
				'attrs' => (array) ( $variation['attributes'] ?? array() ),
				'price' => $object ? (float) wc_get_price_to_display( $object ) : 0.0,
			);
		}

		return $out;
	}

	/**
	 * How this shop writes an amount, so the running total can match.
	 *
	 * @return array<string,mixed>
	 */
	private static function money(): array {
		return array(
			'symbol'   => html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ),
			'decimals' => wc_get_price_decimals(),
			'dot'      => wc_get_price_decimal_separator(),
			'thousand' => wc_get_price_thousand_separator(),
			'format'   => get_option( 'woocommerce_currency_pos', 'left' ),
		);
	}

	/*
	 * ------------------------------------------------------------- the adding
	 */

	/**
	 * Add everything that was left ticked.
	 */
	public function ajax_add(): void {
		check_ajax_referer( 'oc_bt', 'nonce' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
		$main = isset( $_POST['main'] ) ? absint( wp_unslash( $_POST['main'] ) ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
		$want = isset( $_POST['ids'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['ids'] ) ) : array();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
		$picked = isset( $_POST['variations'] ) ? (array) wp_unslash( $_POST['variations'] ) : array();

		$product = wc_get_product( $main );

		if ( ! $product instanceof \WC_Product || ! $want ) {
			wp_send_json_error( array( 'msg' => __( 'Nothing to add.', 'oc-theme' ) ) );
		}

		// Only this bundle's own members, and only what it really offers —
		// the list arrives from a browser and cannot be taken at its word.
		$allowed = array( $product->get_id() );

		foreach ( self::members( $product ) as $item ) {
			$allowed[] = $item->get_id();
		}

		$added   = 0;
		$pending = 0;

		foreach ( $want as $id ) {
			if ( ! in_array( $id, $allowed, true ) ) {
				continue;
			}

			$item = wc_get_product( $id );

			if ( ! $item instanceof \WC_Product ) {
				continue;
			}

			$variation = 0;
			$attrs     = array();

			if ( $item->is_type( 'variable' ) ) {
				$chosen = isset( $picked[ $id ] ) ? (array) $picked[ $id ] : array();

				foreach ( $chosen as $key => $value ) {
					// Kept as sent: these are already in the spelling the
					// variation is stored under.
					$key   = sanitize_text_field( (string) $key );
					$value = sanitize_text_field( (string) $value );

					if ( '' === $value || 0 !== strpos( $key, 'attribute_' ) ) {
						continue;
					}

					$attrs[ $key ] = $value;
				}

				// A variation nobody answered cannot be guessed at.
				if ( count( $attrs ) < count( $item->get_variation_attributes() ) ) {
					++$pending;
					continue;
				}

				$store     = \WC_Data_Store::load( 'product' );
				$variation = (int) $store->find_matching_product_variation( $item, $attrs );

				if ( ! $variation ) {
					++$pending;
					continue;
				}
			}

			if ( WC()->cart->add_to_cart( $id, 1, $variation, $attrs, array( self::TAG => $main ) ) ) {
				++$added;
			}
		}

		if ( ! $added && $pending ) {
			wp_send_json_error( array( 'msg' => __( 'Please choose the options first.', 'oc-theme' ) ) );
		}

		if ( ! $added ) {
			wp_send_json_error( array( 'msg' => __( 'Those could not be added.', 'oc-theme' ) ) );
		}

		wp_send_json_success(
			array(
				'added'     => $added,
				/* translators: %d: how many products were added. */
				'msg'       => sprintf( _n( '%d product added', '%d products added', $added, 'oc-theme' ), $added ),
				'fragments' => apply_filters( 'woocommerce_add_to_cart_fragments', array() ),
			)
		);
	}

	/*
	 * ----------------------------------------------------------- the discount
	 */

	/**
	 * Take the bundle discount off, if the bundle is still there.
	 *
	 * Applied to what is actually in the cart rather than to what was once
	 * offered: two or more lines from the same bundle earn it, and the sum
	 * follows those lines. Remove one and it recalculates; remove enough and
	 * it quietly stops. Nothing needs undoing.
	 *
	 * @param \WC_Cart $cart The cart.
	 */
	public function apply_discount( $cart ): void {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}

		if ( ! $cart instanceof \WC_Cart ) {
			return;
		}

		$bundles = array();

		foreach ( $cart->get_cart() as $item ) {
			$from = (int) ( $item[ self::TAG ] ?? 0 );

			if ( ! $from ) {
				continue;
			}

			if ( ! isset( $bundles[ $from ] ) ) {
				$bundles[ $from ] = array(
					'lines' => 0,
					'sum'   => 0.0,
				);
			}

			++$bundles[ $from ]['lines'];
			$bundles[ $from ]['sum'] += (float) $item['line_subtotal'] + (float) $item['line_subtotal_tax'];
		}

		foreach ( $bundles as $main => $bundle ) {
			// One line on its own is not a bundle any more.
			if ( $bundle['lines'] < 2 ) {
				continue;
			}

			$kind   = self::kind( (int) $main );
			$amount = self::amount( (int) $main );

			if ( 'none' === $kind || $amount <= 0 ) {
				continue;
			}

			$off = 'percent' === $kind ? $bundle['sum'] * ( $amount / 100 ) : $amount;
			$off = min( $off, $bundle['sum'] );

			if ( $off <= 0 ) {
				continue;
			}

			$product = wc_get_product( (int) $main );
			$name    = $product instanceof \WC_Product ? $product->get_name() : '';

			$cart->add_fee(
				$name
					/* translators: %s: the product the bundle hangs off. */
					? sprintf( __( 'Bundle discount — %s', 'oc-theme' ), $name )
					: __( 'Bundle discount', 'oc-theme' ),
				-1 * round( $off, wc_get_price_decimals() ),
				false
			);
		}
	}
}
