<?php
/**
 * Variation display types and linked colour products.
 *
 * The previous theme proved the model — display type per attribute, swatch
 * colour/image per term — and also proved what to avoid: its 1,600-line
 * ajax gallery-swap. Here a colour click NAVIGATES to the sibling product
 * (own URL, own schema, own analytics), and the variation UI drives Woo's
 * own hidden selects, so stock, prices and images keep working untouched.
 *
 * @package OC_Theme
 */

declare( strict_types = 1 );

namespace OC\Theme;

defined( 'ABSPATH' ) || exit;

/**
 * Attribute display types, term swatches, linked colour products.
 */
final class Variations {

	/**
	 * Where the attribute guard records that it fired.
	 */
	private const GUARD_LOG = 'oc_save_guard';

	/**
	 * Registered instance, for the sticky bar's colour-sibling row.
	 *
	 * @var Variations|null
	 */
	private static $me = null;

	/**
	 * Colour-sibling row for the sticky add-to-cart bar, with the same label
	 * resolution as the summary row. Empty 'row' when the product has no
	 * linked colour products.
	 *
	 * @param \WC_Product $product The product shown.
	 * @return array{row: string, label: string}
	 */
	public static function sticky_colors( \WC_Product $product ): array {
		$out = array(
			'row'   => '',
			'label' => __( 'Colours', 'oc-theme' ),
		);

		if ( ! self::$me instanceof self ) {
			return $out;
		}

		$out['row'] = self::$me->colors_row( $product->get_id(), 'oc-colors--sticky' );

		if ( '' === $out['row'] ) {
			return $out;
		}

		foreach ( array_keys( $product->get_attributes() ) as $attr_tax ) {
			$attr_tax = rawurldecode( (string) $attr_tax );

			if ( ! in_array( self::$me->attr_type( $attr_tax ), array( 'swatch', 'swatch_image' ), true ) ) {
				continue;
			}

			$out['label'] = wc_attribute_label( $attr_tax );
			break;
		}

		return $out;
	}

	/**
	 * Colour-sibling row for the quick-pick panel — the same circles as the
	 * page, each carrying its product id so a click can re-dress the panel.
	 *
	 * @param \WC_Product $product The product shown.
	 * @return array{row: string, label: string}
	 */
	public static function panel_colors( \WC_Product $product ): array {
		$out = self::sticky_colors( $product );

		if ( self::$me instanceof self && '' !== $out['row'] ) {
			$out['row'] = self::$me->colors_row( $product->get_id(), 'oc-colors--vp', true );
		}

		return $out;
	}

	/**
	 * The display type of an attribute, for whoever draws options outside
	 * the product form — the quick-pick panel, say.
	 *
	 * @param string $taxonomy Attribute taxonomy.
	 */
	public static function display_type( string $taxonomy ): string {
		return self::$me instanceof self ? self::$me->attr_type( rawurldecode( $taxonomy ) ) : 'select';
	}

	/**
	 * A term's swatch style through the product page's own chain, so the
	 * same colour never draws two different circles.
	 *
	 * @param \WC_Product $product  Product.
	 * @param string      $taxonomy Attribute taxonomy.
	 * @param \WP_Term    $term     Term.
	 */
	public static function swatch_css( \WC_Product $product, string $taxonomy, \WP_Term $term ): string {
		if ( ! self::$me instanceof self ) {
			return '';
		}

		$type = self::$me->attr_type( rawurldecode( $taxonomy ) );

		if ( ! in_array( $type, array( 'swatch', 'swatch_image' ), true ) ) {
			return '';
		}

		return self::$me->swatch_style( $product, $taxonomy, $term, $type );
	}

	/**
	 * Hook in.
	 */
	public function register(): void {
		self::$me = $this;

		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		// "Buttons" and "Swatch" join Woo's own attribute-type dropdown.
		add_filter( 'product_attributes_type_selector', array( $this, 'attribute_types' ) );

		// WooCommerce renders the term picker on the product's attribute row
		// ONLY for the built-in 'select' type; custom types must draw their
		// own via this hook. Without it the row has no values control, every
		// save posts the attribute empty, and Woo strips it — which is
		// exactly the vanishing-attributes bug this restores.
		add_action( 'woocommerce_product_option_terms', array( $this, 'option_terms' ), 10, 3 );

		// Swatch attributes get colour/image fields on their term screens.
		add_action( 'admin_init', array( $this, 'term_field_hooks' ) );
		add_action( 'created_term', array( $this, 'save_term_swatch' ), 10, 3 );
		add_action( 'edited_term', array( $this, 'save_term_swatch' ), 10, 3 );

		// The rendered select stays as Woo's source of truth; buttons and
		// swatches are appended beside it and drive it.
		add_filter( 'woocommerce_dropdown_variation_attribute_options_html', array( $this, 'variation_ui' ), 20, 2 );

		// Linked colour products: one field, synced in both directions.
		add_action( 'woocommerce_product_options_related', array( $this, 'links_field' ) );
		add_action( 'woocommerce_process_product_meta', array( $this, 'save_links' ) );

		// A save whose form lost the attribute fields would silently strip a
		// variable product of its attributes (and orphan every variation).
		// Restore them from the database instead, and log the event.
		add_action( 'woocommerce_admin_process_product_object', array( $this, 'guard_attributes' ), 5 );

		// Per-colour galleries on regular variable products: pick a colour,
		// attach its images; a swatch click swaps the gallery immediately.
		add_filter( 'woocommerce_product_data_tabs', array( $this, 'galleries_tab' ) );
		add_action( 'woocommerce_product_data_panels', array( $this, 'galleries_panel' ) );
		add_action( 'woocommerce_process_product_meta', array( $this, 'save_galleries' ) );
		add_action( 'woocommerce_before_add_to_cart_form', array( $this, 'galleries_json' ) );

		// Colour siblings sit in the variations area, like every attribute row.
		add_action( 'woocommerce_before_add_to_cart_form', array( $this, 'product_colors' ) );

		// Both positions hook in; the choice is read at render time, so the
		// customizer preview reflects it immediately — a hook picked when the
		// theme loads would only see the saved value.
		//
		// "Above" renders INSIDE the text box (right after it opens, before
		// the title) rather than as the card's own grid row: a shared subgrid
		// track made every card in the row reserve swatch height when one
		// neighbour had swatches. Inside the box each card packs itself —
		// the same decision the reviews row got.
		add_action( 'woocommerce_shop_loop_item_title', array( $this, 'loop_colors_above' ), 2 );
		add_action( 'woocommerce_after_shop_loop_item_title', array( $this, 'loop_colors_below' ), 30 );

		// The card's default colour travels: every card link carries the
		// first colour's attribute, so the product page opens with the same
		// swatch selected the visitor was just looking at.
		add_filter( 'woocommerce_loop_product_link', array( $this, 'carry_default_color' ), 10, 2 );

		// And a product entered with nothing chosen still opens on its first
		// variation — the same default the catalogue card shows.
		add_filter( 'woocommerce_product_get_default_attributes', array( $this, 'first_variation_default' ), 10, 2 );
	}

	/**
	 * The catalogue card's link, carrying the default colour.
	 *
	 * @param string $link    The permalink.
	 * @param mixed  $product The product.
	 * @return string
	 */
	public function carry_default_color( $link, $product ) {
		if ( ! $product instanceof \WC_Product ) {
			return $link;
		}

		$d = $this->default_term( $product );

		return null === $d ? $link : add_query_arg( 'attribute_' . rawurlencode( sanitize_title( $d['tax'] ) ), $d['slug'], (string) $link );
	}

	/**
	 * A variable product with no chosen defaults opens on its first
	 * variation, matching the swatch the catalogue pre-marks. A default the
	 * shop owner set per product always wins.
	 *
	 * @param array $defaults Saved defaults.
	 * @param mixed $product  The product.
	 * @return array
	 */
	public function first_variation_default( $defaults, $product ) {
		if ( ! empty( $defaults ) || is_admin() || ! $product instanceof \WC_Product_Variable ) {
			return $defaults;
		}

		// This filter fires on every property read — remember the answer.
		static $cache = array();

		$id = $product->get_id();

		if ( ! isset( $cache[ $id ] ) ) {
			$cache[ $id ] = array();

			foreach ( $product->get_variation_attributes() as $name => $options ) {
				if ( ! empty( $options ) ) {
					$cache[ $id ][ sanitize_title( (string) $name ) ] = (string) reset( $options );
				}
			}
		}

		return $cache[ $id ];
	}

	/**
	 * The card's default colour: the first term the swatch row would draw.
	 * Null for simple products, colour-sibling products, and products whose
	 * colours are not a swatch attribute.
	 *
	 * @param \WC_Product $product The product.
	 * @return array{tax:string,slug:string}|null
	 */
	private function default_term( \WC_Product $product ): ?array {
		static $cache = array();

		$id = $product->get_id();

		if ( array_key_exists( $id, $cache ) ) {
			return $cache[ $id ];
		}

		$cache[ $id ] = null;

		if ( ! $product->is_type( 'variable' ) ) {
			return null;
		}

		// Colour-sibling products carry their colour in the product itself.
		if ( ! empty( array_filter( array_map( 'absint', (array) get_post_meta( $id, '_oc_color_links', true ) ) ) ) ) {
			return null;
		}

		foreach ( array_keys( $product->get_attributes() ) as $attr_tax ) {
			$attr_tax = rawurldecode( (string) $attr_tax );
			$type     = $this->attr_type( $attr_tax );

			if ( ! in_array( $type, array( 'swatch', 'swatch_image' ), true ) ) {
				continue;
			}

			$terms = wc_get_product_terms( $id, $attr_tax, array( 'fields' => 'all' ) );

			if ( count( $terms ) < 2 ) {
				return null;
			}

			foreach ( $terms as $term ) {
				if ( '' !== $this->swatch_style( $product, $attr_tax, $term, $type ) ) {
					$cache[ $id ] = array(
						'tax'  => $attr_tax,
						'slug' => $term->slug,
					);

					return $cache[ $id ];
				}
			}

			return null;
		}

		return null;
	}

	/**
	 * The catalogue cap: at most N swatches, the rest folded into a +N chip
	 * that leads to the product. The current colour never disappears — when
	 * it falls past the cap it takes the last visible slot.
	 *
	 * @param array<int,string> $anchors    The built swatch anchors.
	 * @param int               $at_current Index of the current item, -1 when none.
	 * @param string            $permalink  Where +N leads.
	 * @return array<int,string>
	 */
	private static function capped( array $anchors, int $at_current, string $permalink ): array {
		$max = absint( get_theme_mod( 'oc_swatch_loop_max', 0 ) );

		if ( $max < 1 || count( $anchors ) <= $max ) {
			return $anchors;
		}

		$extra = count( $anchors ) - $max;

		if ( $at_current >= $max ) {
			$anchors[ $max - 1 ] = $anchors[ $at_current ];
		}

		$anchors   = array_slice( $anchors, 0, $max );
		$anchors[] = '<a class="oc-colors__more" href="' . esc_url( $permalink ) . '">+' . (int) $extra . '</a>';

		return $anchors;
	}

	/**
	 * Card swatches above the title, when so configured.
	 */
	public function loop_colors_above(): void {
		if ( 'above' === get_theme_mod( 'oc_colors_loop_pos', 'below' ) ) {
			$this->loop_colors();
		}
	}

	/**
	 * Card swatches below the content, when so configured.
	 */
	public function loop_colors_below(): void {
		if ( 'above' !== get_theme_mod( 'oc_colors_loop_pos', 'below' ) ) {
			$this->loop_colors();
		}
	}

	/**
	 * Extra attribute display types.
	 *
	 * @param array $types Type map.
	 * @return array
	 */
	public function attribute_types( array $types ): array {
		$types['button']       = __( 'Buttons', 'oc-theme' );
		$types['swatch']       = __( 'Swatch — colour', 'oc-theme' );
		$types['swatch_image'] = __( 'Swatch — image', 'oc-theme' );

		return $types;
	}

	/**
	 * Display type for an attribute taxonomy, e.g. pa_color.
	 *
	 * The type doubles as the default look: every term stores both a colour
	 * and an image, and the attribute type decides which one leads. Stored in
	 * Woo's own attribute_type column — no extra table, no extra query.
	 *
	 * @param string $taxonomy Attribute taxonomy name.
	 * @return string select|button|swatch|swatch_image
	 */
	private function attr_type( string $taxonomy ): string {
		// Product-attribute keys arrive percent-encoded for Hebrew taxonomies.
		$taxonomy = rawurldecode( $taxonomy );

		foreach ( wc_get_attribute_taxonomies() as $attribute ) {
			if ( 'pa_' . $attribute->attribute_name === $taxonomy ) {
				return in_array( $attribute->attribute_type, array( 'button', 'swatch', 'swatch_image' ), true )
					? $attribute->attribute_type
					: 'select';
			}
		}

		return 'select';
	}

	/**
	 * Attach the swatch fields to every swatch-type attribute taxonomy.
	 */
	public function term_field_hooks(): void {
		foreach ( wc_get_attribute_taxonomies() as $attribute ) {
			if ( ! in_array( $attribute->attribute_type, array( 'swatch', 'swatch_image' ), true ) ) {
				continue;
			}

			$taxonomy = 'pa_' . $attribute->attribute_name;
			add_action( $taxonomy . '_add_form_fields', array( $this, 'term_fields_add' ) );
			add_action( $taxonomy . '_edit_form_fields', array( $this, 'term_fields_edit' ) );

			// A swatch preview column in the terms table — see every value at
			// a glance without opening it.
			add_filter( 'manage_edit-' . $taxonomy . '_columns', array( $this, 'term_column' ) );
			add_filter( 'manage_' . $taxonomy . '_custom_column', array( $this, 'term_column_value' ), 10, 3 );
		}
	}

	/**
	 * Swatch column header, right after the checkbox.
	 *
	 * @param array $columns Terms table columns.
	 * @return array
	 */
	public function term_column( array $columns ): array {
		$out = array();

		foreach ( $columns as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'cb' === $key ) {
				$out['oc_swatch'] = __( 'Swatch', 'oc-theme' );
			}
		}

		return $out;
	}

	/**
	 * Swatch column value: the term's circle, colour or image.
	 *
	 * @param string $output  Column output.
	 * @param string $column  Column key.
	 * @param int    $term_id Term id.
	 * @return string
	 */
	public function term_column_value( $output, $column, $term_id ): string {
		if ( 'oc_swatch' !== $column ) {
			return (string) $output;
		}

		$image = (string) get_term_meta( $term_id, 'oc_swatch_image', true );
		$color = (string) get_term_meta( $term_id, 'oc_swatch_color', true );
		$style = 'display:inline-block;inline-size:24px;block-size:24px;border-radius:50%;border:1px solid #ccd0d4;vertical-align:middle;';

		if ( '' !== $image ) {
			$style .= 'background-image:url(' . esc_url( $image ) . ');background-size:cover;';
		} else {
			$style .= 'background-color:' . ( '' !== $color ? $color : '#f0f0f1' ) . ';';
		}

		return sprintf( '<span style="%s"></span>', esc_attr( $style ) );
	}

	/**
	 * Swatch fields on the "add term" form.
	 */
	public function term_fields_add(): void {
		?>
		<div class="form-field">
			<label for="oc_swatch_color"><?php esc_html_e( 'Swatch colour', 'oc-theme' ); ?></label>
			<input type="color" id="oc_swatch_color" name="oc_swatch_color" value="#cccccc" />
		</div>
		<div class="form-field">
			<label for="oc_swatch_image"><?php esc_html_e( 'Swatch image', 'oc-theme' ); ?></label>
			<?php $this->image_picker( '' ); ?>
			<p><?php esc_html_e( 'The attribute type decides which one leads; the other is the fallback.', 'oc-theme' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Swatch fields on the "edit term" form.
	 *
	 * @param \WP_Term $term Term being edited.
	 */
	public function term_fields_edit( \WP_Term $term ): void {
		$color = (string) get_term_meta( $term->term_id, 'oc_swatch_color', true );
		$image = (string) get_term_meta( $term->term_id, 'oc_swatch_image', true );
		?>
		<tr class="form-field">
			<th scope="row"><label for="oc_swatch_color"><?php esc_html_e( 'Swatch colour', 'oc-theme' ); ?></label></th>
			<td><input type="color" id="oc_swatch_color" name="oc_swatch_color" value="<?php echo esc_attr( '' !== $color ? $color : '#cccccc' ); ?>" /></td>
		</tr>
		<tr class="form-field">
			<th scope="row"><label for="oc_swatch_image"><?php esc_html_e( 'Swatch image', 'oc-theme' ); ?></label></th>
			<td>
				<?php $this->image_picker( $image ); ?>
				<p class="description"><?php esc_html_e( 'The attribute type decides which one leads; the other is the fallback.', 'oc-theme' ); ?></p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Media-library picker for the swatch image: preview, choose, remove.
	 * The value stays a plain URL, so nothing changes for existing terms.
	 *
	 * @param string $image Current image URL.
	 */
	private function image_picker( string $image ): void {
		wp_enqueue_media();
		?>
		<span class="oc-swatch-picker">
			<img class="oc-swatch-picker__preview" src="<?php echo esc_url( $image ); ?>" alt="" style="inline-size:32px;block-size:32px;border-radius:50%;object-fit:cover;vertical-align:middle;border:1px solid #ccd0d4;<?php echo '' === $image ? 'display:none;' : ''; ?>" />
			<input type="hidden" id="oc_swatch_image" name="oc_swatch_image" value="<?php echo esc_url( $image ); ?>" />
			<button type="button" class="button oc-swatch-picker__choose"><?php esc_html_e( 'Choose image', 'oc-theme' ); ?></button>
			<button type="button" class="button oc-swatch-picker__remove" <?php echo '' === $image ? 'style="display:none;"' : ''; ?>><?php esc_html_e( 'Remove', 'oc-theme' ); ?></button>
		</span>
		<?php
		$this->picker_script();
	}

	/**
	 * One shared script for every picker on the screen.
	 */
	private function picker_script(): void {
		static $printed = false;

		if ( $printed ) {
			return;
		}
		$printed = true;
		?>
		<script>
		document.addEventListener( 'click', function ( event ) {
			var wrap = event.target.closest( '.oc-swatch-picker' );
			if ( ! wrap || ! window.wp || ! wp.media ) {
				return;
			}
			var input   = wrap.querySelector( 'input' );
			var preview = wrap.querySelector( '.oc-swatch-picker__preview' );
			var remove  = wrap.querySelector( '.oc-swatch-picker__remove' );
			if ( event.target.closest( '.oc-swatch-picker__remove' ) ) {
				input.value = '';
				preview.style.display = 'none';
				remove.style.display = 'none';
				return;
			}
			if ( ! event.target.closest( '.oc-swatch-picker__choose' ) ) {
				return;
			}
			var frame = wp.media( { title: wrap.dataset.title || '', multiple: false, library: { type: 'image' } } );
			frame.on( 'select', function () {
				var att = frame.state().get( 'selection' ).first().toJSON();
				var url = att.sizes && att.sizes.thumbnail ? att.sizes.thumbnail.url : att.url;
				input.value = url;
				preview.src = url;
				preview.style.display = '';
				remove.style.display = '';
			} );
			frame.open();
		} );
		</script>
		<?php
	}

	/**
	 * Persist the swatch term meta.
	 *
	 * @param int    $term_id  Term id.
	 * @param int    $tt_id    Term taxonomy id.
	 * @param string $taxonomy Taxonomy name.
	 */
	public function save_term_swatch( $term_id, $tt_id, $taxonomy ): void {
		unset( $tt_id );

		if ( 0 !== strpos( (string) $taxonomy, 'pa_' ) ) {
			return;
		}

		// Woo's own term screens carry their nonces; these fields ride along.
		if ( isset( $_POST['oc_swatch_color'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			update_term_meta( $term_id, 'oc_swatch_color', sanitize_hex_color( wp_unslash( $_POST['oc_swatch_color'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		}

		if ( isset( $_POST['oc_swatch_image'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			update_term_meta( $term_id, 'oc_swatch_image', esc_url_raw( wp_unslash( $_POST['oc_swatch_image'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		}
	}

	/**
	 * Buttons / swatches rendered beside the hidden select.
	 *
	 * @param string $html Select html.
	 * @param array  $args Dropdown args.
	 * @return string
	 */
	public function variation_ui( $html, $args ): string {
		$taxonomy = (string) $args['attribute'];

		if ( ! taxonomy_exists( $taxonomy ) ) {
			return (string) $html;
		}

		$type = $this->attr_type( $taxonomy );

		if ( 'select' === $type ) {
			return (string) $html;
		}

		$options  = (array) $args['options'];
		$selected = (string) $args['selected'];

		if ( empty( $options ) ) {
			return (string) $html;
		}

		// A product whose colours live as linked sibling products must show one
		// colour UI only: when its own colour attribute holds a single value,
		// auto-select it and hide the row — the "Colours" thumbs take over.
		if ( 1 === count( $options ) && 'select' !== $type && $args['product'] instanceof \WC_Product
			&& ! empty( array_filter( array_map( 'absint', (array) get_post_meta( $args['product']->get_id(), '_oc_color_links', true ) ) ) ) ) {
			return $html . sprintf(
				'<div class="oc-var oc-var--auto" data-for="%s" data-auto="%s"></div>',
				esc_attr( sanitize_title( $taxonomy ) ),
				esc_attr( (string) reset( $options ) )
			);
		}

		$items = '';

		foreach ( $options as $slug ) {
			$term = get_term_by( 'slug', $slug, $taxonomy );

			if ( ! $term instanceof \WP_Term ) {
				continue;
			}

			$is_sel = $selected === $slug ? ' is-selected' : '';
			$label  = esc_html( $term->name );

			if ( in_array( $type, array( 'swatch', 'swatch_image' ), true ) ) {
				$style = $this->swatch_style( $args['product'], $taxonomy, $term, $type );

				if ( '' === $style ) {
					// Nothing to draw — the term's initial stands in, so an
					// unfilled value is still clickable, never a grey mystery.
					$items .= sprintf(
						'<button type="button" class="oc-var__swatch oc-var__swatch--txt%s" data-value="%s" title="%s" aria-label="%s">%s</button>',
						$is_sel,
						esc_attr( $slug ),
						esc_attr( $term->name ),
						esc_attr( $term->name ),
						esc_html( mb_substr( $term->name, 0, 1 ) )
					);
				} else {
					$items .= sprintf(
						'<button type="button" class="oc-var__swatch%s" data-value="%s" style="%s" title="%s" aria-label="%s"></button>',
						$is_sel,
						esc_attr( $slug ),
						$style,
						esc_attr( $term->name ),
						esc_attr( $term->name )
					);
				}
			} else {
				$items .= sprintf(
					'<button type="button" class="oc-var__btn%s" data-value="%s">%s</button>',
					$is_sel,
					esc_attr( $slug ),
					$label
				);
			}
		}

		if ( '' === $items ) {
			return (string) $html;
		}

		return $html . sprintf(
			'<div class="oc-var oc-var--%s" data-for="%s">%s</div>',
			esc_attr( $type ),
			esc_attr( sanitize_title( $taxonomy ) ),
			$items
		);
	}

	/**
	 * Inline style for a swatch button, resolved in confidence order:
	 * per-product override, then the term's leading medium (by attribute
	 * type), the other medium, and finally the variation's own image.
	 *
	 * @param mixed    $product  Product, when the dropdown args carried one.
	 * @param string   $taxonomy Attribute taxonomy.
	 * @param \WP_Term $term     Term.
	 * @param string   $type     swatch|swatch_image.
	 * @return string Empty when nothing is available.
	 */
	private function swatch_style( $product, string $taxonomy, \WP_Term $term, string $type ): string {
		$image = '';
		$color = (string) get_term_meta( $term->term_id, 'oc_swatch_color', true );

		if ( $product instanceof \WC_Product ) {
			$galleries = $this->galleries_meta( $product->get_id() );

			// A per-product swatch image is an explicit choice for this very
			// product — it wins over everything, whatever the attribute type.
			$override = (string) ( $galleries[ $term->slug ]['swatch'] ?? '' );
			if ( '' !== $override ) {
				return 'background-image:url(' . esc_url( $override ) . ');background-size:cover;';
			}

			// A per-product shade replaces the value's store-wide colour.
			$shade = (string) ( $galleries[ $term->slug ]['color'] ?? '' );
			if ( '' !== $shade ) {
				$color = $shade;
			}
		}

		$image = (string) get_term_meta( $term->term_id, 'oc_swatch_image', true );

		$use_image = 'swatch_image' === $type ? '' !== $image : '' !== $image && '' === $color;

		if ( ! $use_image && '' === $color && '' === $image && $product instanceof \WC_Product ) {
			$image     = $this->variation_image( $product, $taxonomy, $term->slug );
			$use_image = '' !== $image;
		}

		if ( $use_image || ( '' === $color && '' !== $image ) ) {
			return 'background-image:url(' . esc_url( $image ) . ');background-size:cover;';
		}

		if ( '' !== $color ) {
			return 'background-color:' . esc_attr( $color ) . ';';
		}

		return '';
	}

	/**
	 * The image of a variation carrying this attribute value — saves the
	 * admin a duplicate upload when the variation photos already exist.
	 * One pass over the children per product, memoised for the request.
	 *
	 * @param \WC_Product $product  Variable product.
	 * @param string      $taxonomy Attribute taxonomy.
	 * @param string      $slug     Term slug.
	 * @param string      $size     Image size to resolve.
	 * @return string Image URL or ''.
	 */
	private function variation_image( \WC_Product $product, string $taxonomy, string $slug, string $size = 'thumbnail' ): string {
		static $maps = array();

		$pid = $product->get_id();

		if ( ! isset( $maps[ $pid ] ) ) {
			$maps[ $pid ] = array();

			if ( $product->is_type( 'variable' ) ) {
				foreach ( $product->get_children() as $child_id ) {
					$variation = wc_get_product( $child_id );

					if ( ! $variation || ! $variation->get_image_id() ) {
						continue;
					}

					foreach ( $variation->get_attributes() as $attr_tax => $attr_slug ) {
						// Keys arrive percent-encoded for Hebrew taxonomies;
						// the callers ask in decoded form — speak one tongue.
						$key = rawurldecode( (string) $attr_tax ) . ':' . $attr_slug;
						if ( '' !== (string) $attr_slug && ! isset( $maps[ $pid ][ $key ] ) ) {
							$maps[ $pid ][ $key ] = (int) $variation->get_image_id();
						}
					}
				}
			}
		}

		$image_id = (int) ( $maps[ $pid ][ $taxonomy . ':' . $slug ] ?? 0 );

		return $image_id > 0 ? (string) wp_get_attachment_image_url( $image_id, $size ) : '';
	}

	/**
	 * Per-product colour galleries meta, normalised.
	 *
	 * @param int $product_id Product id.
	 * @return array slug => { imgs: int[], swatch: string }
	 */
	private function galleries_meta( int $product_id ): array {
		$raw = get_post_meta( $product_id, '_oc_color_galleries', true );

		if ( ! is_array( $raw ) ) {
			return array();
		}

		$out = array();

		foreach ( $raw as $slug => $entry ) {
			$imgs   = array_filter( array_map( 'absint', (array) ( $entry['imgs'] ?? array() ) ) );
			$swatch = (string) ( $entry['swatch'] ?? '' );
			$color  = (string) ( $entry['color'] ?? '' );

			if ( ! empty( $imgs ) || '' !== $swatch || '' !== $color ) {
				$out[ (string) $slug ] = array(
					'imgs'   => array_values( $imgs ),
					'swatch' => $swatch,
					'color'  => $color,
				);
			}
		}

		return $out;
	}

	/**
	 * Linked colour products field, in the Linked Products panel.
	 */
	public function links_field(): void {
		global $post;

		$links = array_filter( array_map( 'absint', (array) get_post_meta( $post->ID, '_oc_color_links', true ) ) );
		?>
		<p class="form-field">
			<label for="oc_color_links"><?php esc_html_e( 'Colour siblings', 'oc-theme' ); ?></label>
			<select class="wc-product-search" multiple="multiple" style="width: 50%;" id="oc_color_links" name="oc_color_links[]" data-placeholder="<?php esc_attr_e( 'Search for a product…', 'oc-theme' ); ?>" data-action="woocommerce_json_search_products" data-exclude="<?php echo absint( $post->ID ); ?>">
				<?php foreach ( $links as $link_id ) : ?>
					<?php $link_product = wc_get_product( $link_id ); ?>
					<?php if ( $link_product ) : ?>
						<option value="<?php echo absint( $link_id ); ?>" selected="selected"><?php echo esc_html( wp_strip_all_tags( $link_product->get_formatted_name() ) ); ?></option>
					<?php endif; ?>
				<?php endforeach; ?>
			</select>
			<?php echo wc_help_tip( esc_html__( 'The same product in other colours. Links sync both ways: connect black and grey here and each of them links back automatically.', 'oc-theme' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wc_help_tip() returns escaped markup. ?>
		</p>
		<?php
	}

	/**
	 * The term picker for our attribute types on the product's attribute
	 * row — the same multiselect WooCommerce draws for its 'select' type,
	 * so search, "select all" and term creation keep working.
	 *
	 * @param object                     $attribute_taxonomy Attribute taxonomy row.
	 * @param int                        $i                  Row index.
	 * @param \WC_Product_Attribute|null $attribute   Attribute being rendered.
	 */
	public function option_terms( $attribute_taxonomy, $i, $attribute = null ): void {
		if ( ! in_array( $attribute_taxonomy->attribute_type, array( 'button', 'swatch', 'swatch_image' ), true ) ) {
			return;
		}

		$taxonomy = wc_attribute_taxonomy_name( (string) $attribute_taxonomy->attribute_name );
		?>
		<select multiple="multiple" data-minimum_input_length="0" data-limit="50" data-return_id="id"
			data-placeholder="<?php esc_attr_e( 'Select values', 'oc-theme' ); ?>"
			data-orderby="<?php echo esc_attr( $attribute_taxonomy->attribute_orderby ?? 'name' ); ?>"
			class="multiselect attribute_values wc-taxonomy-term-search"
			name="attribute_values[<?php echo esc_attr( (string) $i ); ?>][]"
			data-taxonomy="<?php echo esc_attr( $taxonomy ); ?>">
			<?php
			$selected_terms = $attribute instanceof \WC_Product_Attribute ? $attribute->get_terms() : array();

			if ( $selected_terms ) {
				foreach ( $selected_terms as $selected_term ) {
					echo '<option value="' . esc_attr( (string) $selected_term->term_id ) . '" selected="selected">'
						. esc_html( apply_filters( 'woocommerce_product_attribute_term_name', $selected_term->name, $selected_term ) )
						. '</option>';
				}
			}
			?>
		</select>
		<button class="button plus select_all_attributes"><?php esc_html_e( 'Select all', 'oc-theme' ); ?></button>
		<button class="button minus select_no_attributes"><?php esc_html_e( 'Select none', 'oc-theme' ); ?></button>
		<button class="button fr plus add_new_attribute"><?php esc_html_e( 'Create value', 'oc-theme' ); ?></button>
		<?php
	}

	/**
	 * Keep a variable product's attributes when a save posts none.
	 *
	 * WooCommerce reads the attribute fields from the submitted form and
	 * treats their absence as "remove everything" — so a form that lost
	 * those fields (a broken edit screen, a script error, a truncated
	 * request) silently wipes the attributes and orphans every variation.
	 * When that shape appears, restore the stored attributes and note that it
	 * happened, so a recurrence is visible without a trace of every save.
	 *
	 * @param \WC_Product $product Product being saved.
	 */
	public function guard_attributes( $product ): void {
		if ( ! $product instanceof \WC_Product || ! $product->is_type( 'variable' ) ) {
			return;
		}

		if ( ! empty( $product->get_attributes() ) ) {
			return;
		}

		// The save is about to leave a variable product with zero attributes —
		// whatever emptied them (unposted fields, values Woo discarded), that
		// is never what an edit intends. The database still holds the
		// pre-save state here; put it back.
		$stored = wc_get_product( $product->get_id() );

		if ( $stored && ! empty( $stored->get_attributes() ) ) {
			$product->set_attributes( $stored->get_attributes() );
			$this->save_log( sprintf( 'GUARD restored %d attributes on product=%d', count( $stored->get_attributes() ), $product->get_id() ) );
		}
	}

	/**
	 * Note that the guard had to step in.
	 *
	 * This used to append a line per save to wp-content/uploads, a file the
	 * whole internet could read. It held no secrets, but a public trace of
	 * how the admin saves products is a map drawn for the wrong reader, and
	 * the per-save detail was scaffolding for a bug the guard now handles.
	 * What is worth keeping is how often it fires, which lives in an option.
	 *
	 * @param string $line What happened.
	 */
	private function save_log( string $line ): void {
		$seen = (array) get_option( self::GUARD_LOG, array() );

		update_option(
			self::GUARD_LOG,
			array(
				'count' => (int) ( $seen['count'] ?? 0 ) + 1,
				'last'  => gmdate( 'c' ) . ' ' . $line,
			),
			false
		);
	}

	/**
	 * "Colour galleries" tab in the Product Data box.
	 *
	 * @param array $tabs Product data tabs.
	 * @return array
	 */
	public function galleries_tab( array $tabs ): array {
		$tabs['oc_color_galleries'] = array(
			'label'    => __( 'Colour galleries', 'oc-theme' ),
			'target'   => 'oc_color_galleries_panel',
			'class'    => array( 'show_if_variable' ),
			'priority' => 65,
		);

		return $tabs;
	}

	/**
	 * The panel: one block per colour value the product uses — a gallery to
	 * show when that colour is picked, and an optional swatch override.
	 */
	public function galleries_panel(): void {
		global $post;

		$product = wc_get_product( $post->ID );
		$terms   = array();

		if ( $product instanceof \WC_Product ) {
			foreach ( array_keys( $product->get_attributes() ) as $attr_tax ) {
				// Keys arrive percent-encoded for Hebrew taxonomies.
				$attr_tax = rawurldecode( (string) $attr_tax );

				if ( ! in_array( $this->attr_type( $attr_tax ), array( 'swatch', 'swatch_image' ), true ) ) {
					continue;
				}

				$product_terms = wc_get_product_terms( $post->ID, $attr_tax, array( 'fields' => 'all' ) );
				foreach ( $product_terms as $term ) {
					$terms[] = $term;
				}
			}
		}

		$saved = $this->galleries_meta( $post->ID );

		wp_enqueue_media();
		echo '<div id="oc_color_galleries_panel" class="panel woocommerce_options_panel">';

		if ( empty( $terms ) ) {
			echo '<p class="form-field">' . esc_html__( 'Give the product a swatch-type attribute (for example: colour) and its values appear here.', 'oc-theme' ) . '</p>';
		}

		foreach ( $terms as $term ) {
			$entry  = $saved[ $term->slug ] ?? array(
				'imgs'   => array(),
				'swatch' => '',
				'color'  => '',
			);
			$swatch = (string) $entry['swatch'];
			$color  = (string) ( $entry['color'] ?? '' );

			$term_color = (string) get_term_meta( $term->term_id, 'oc_swatch_color', true );
			$term_color = '' !== $term_color ? $term_color : '#cccccc';

			echo '<div class="oc-cgal" data-slug="' . esc_attr( $term->slug ) . '" style="border-block-end:1px solid #eee;padding:12px;">';
			echo '<strong style="display:block;margin-block-end:8px;">' . esc_html( $term->name ) . '</strong>';

			// Gallery ids + sortable previews: drag to reorder, × removes one,
			// and the add button sits on its own line under the images.
			echo '<input type="hidden" name="oc_cgal[' . esc_attr( $term->slug ) . '][imgs]" value="' . esc_attr( implode( ',', $entry['imgs'] ) ) . '" class="oc-cgal__ids" />';
			echo '<span class="oc-cgal__thumbs" style="display:flex;flex-wrap:wrap;gap:6px;' . ( empty( $entry['imgs'] ) ? '' : 'margin-block-end:8px;' ) . '">';
			foreach ( $entry['imgs'] as $img_id ) {
				$url = wp_get_attachment_image_url( $img_id, 'thumbnail' );
				if ( $url ) {
					echo $this->gallery_chip( $img_id, $url ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
				}
			}
			echo '</span>';
			echo '<span style="display:block;margin-block-start:10px;"><button type="button" class="button oc-cgal__pick">' . esc_html__( 'Add images', 'oc-theme' ) . '</button></span>';

			// Optional colour-shade override for this product only: the value
			// keeps its store-wide colour; this product may fine-tune it, and
			// reset returns to the value's own colour.
			echo '<span style="display:flex;align-items:center;gap:8px;margin-block-start:8px;">';
			echo '<label style="float:none;inline-size:auto;margin:0;display:inline-block;">' . esc_html__( 'Colour shade (this product only)', 'oc-theme' ) . '</label>';
			echo '<input type="hidden" name="oc_cgal[' . esc_attr( $term->slug ) . '][color]" value="' . esc_attr( $color ) . '" class="oc-cgal__colval" />';
			echo '<input type="color" class="oc-cgal__color" value="' . esc_attr( '' !== $color ? $color : $term_color ) . '" data-def="' . esc_attr( $term_color ) . '" />';
			echo '<button type="button" class="button oc-cgal__colreset"' . ( '' === $color ? ' style="display:none;"' : '' ) . '>' . esc_html__( 'Reset', 'oc-theme' ) . '</button>';
			echo '</span>';

			// Optional swatch override for this product only — the chosen image
			// becomes a chip with the same red x as the gallery ones, and the
			// choose button hides while an override is set.
			echo '<span style="display:flex;align-items:center;gap:8px;margin-block-start:8px;">';
			echo '<label style="float:none;inline-size:auto;margin:0;display:inline-block;">' . esc_html__( 'Swatch image (this product only)', 'oc-theme' ) . '</label>';
			echo '<input type="hidden" name="oc_cgal[' . esc_attr( $term->slug ) . '][swatch]" value="' . esc_url( $swatch ) . '" class="oc-cgal__sw" />';
			echo '<span class="oc-cgal__swchip" style="position:relative;display:' . ( '' === $swatch ? 'none' : 'inline-block' ) . ';">';
			echo '<img class="oc-cgal__swprev" src="' . esc_url( $swatch ) . '" alt="" style="inline-size:28px;block-size:28px;border-radius:50%;object-fit:cover;border:1px solid #ccd0d4;display:block;" />';
			echo '<button type="button" class="oc-cgal__swx" aria-label="' . esc_attr__( 'Remove', 'oc-theme' ) . '" style="position:absolute;inset-block-start:-6px;inset-inline-end:-6px;inline-size:18px;block-size:18px;border-radius:50%;border:none;background:#d63638;color:#fff;font-size:12px;line-height:1;cursor:pointer;padding:0;">&times;</button>';
			echo '</span>';
			echo '<button type="button" class="button oc-cgal__swpick"' . ( '' === $swatch ? '' : ' style="display:none;"' ) . '>' . esc_html__( 'Choose image', 'oc-theme' ) . '</button>';
			echo '</span>';

			echo '</div>';
		}

		$this->galleries_panel_script();
		echo '</div>';
	}

	/**
	 * One sortable preview chip: drag to reorder, × removes just this image.
	 *
	 * @param int    $img_id Attachment id.
	 * @param string $url    Thumbnail URL.
	 * @return string
	 */
	private function gallery_chip( int $img_id, string $url ): string {
		return sprintf(
			'<span class="oc-cgal__chip" draggable="true" data-id="%d" style="position:relative;display:inline-block;cursor:grab;">' .
			'<img src="%s" alt="" style="inline-size:48px;block-size:48px;object-fit:cover;border-radius:4px;display:block;border:1px solid #ccd0d4;" />' .
			'<button type="button" class="oc-cgal__x" aria-label="%s" style="position:absolute;inset-block-start:-6px;inset-inline-end:-6px;inline-size:18px;block-size:18px;border-radius:50%%;border:none;background:#d63638;color:#fff;font-size:12px;line-height:1;cursor:pointer;padding:0;">&times;</button>' .
			'</span>',
			absint( $img_id ),
			esc_url( $url ),
			esc_attr__( 'Remove', 'oc-theme' )
		);
	}

	/**
	 * One shared script for the galleries panel: pickers, per-image removal
	 * and drag-to-reorder, all synced into the hidden ids field.
	 */
	private function galleries_panel_script(): void {
		?>
		<script>
		( function () {
			function chipHtml( id, url ) {
				return '<span class="oc-cgal__chip" draggable="true" data-id="' + id + '" style="position:relative;display:inline-block;cursor:grab;">' +
					'<img src="' + url + '" alt="" style="inline-size:48px;block-size:48px;object-fit:cover;border-radius:4px;display:block;border:1px solid #ccd0d4;" />' +
					'<button type="button" class="oc-cgal__x" aria-label="x" style="position:absolute;inset-block-start:-6px;inset-inline-end:-6px;inline-size:18px;block-size:18px;border-radius:50%;border:none;background:#d63638;color:#fff;font-size:12px;line-height:1;cursor:pointer;padding:0;">&times;</button>' +
					'</span>';
			}

			function syncIds( row ) {
				var ids = [];
				row.querySelectorAll( '.oc-cgal__chip' ).forEach( function ( chip ) {
					ids.push( chip.dataset.id );
				} );
				row.querySelector( '.oc-cgal__ids' ).value = ids.join( ',' );
			}

			var dragChip = null;

			document.addEventListener( 'dragstart', function ( event ) {
				var chip = event.target.closest ? event.target.closest( '.oc-cgal__chip' ) : null;
				if ( chip ) {
					dragChip = chip;
					event.dataTransfer.effectAllowed = 'move';
				}
			} );

			document.addEventListener( 'dragover', function ( event ) {
				if ( ! dragChip ) {
					return;
				}
				var over = event.target.closest ? event.target.closest( '.oc-cgal__chip' ) : null;
				if ( ! over || over === dragChip || over.parentElement !== dragChip.parentElement ) {
					return;
				}
				event.preventDefault();
				var rect = over.getBoundingClientRect();
				var rtl = 'rtl' === getComputedStyle( over ).direction;
				var firstHalf = ( event.clientX - rect.left ) / rect.width < 0.5;
				var before = rtl ? ! firstHalf : firstHalf;
				over.parentElement.insertBefore( dragChip, before ? over : over.nextSibling );
			} );

			document.addEventListener( 'dragend', function () {
				if ( dragChip ) {
					syncIds( dragChip.closest( '.oc-cgal' ) );
					dragChip = null;
				}
			} );

			// A picked shade lands in the hidden field; reset returns to the
			// value's own colour.
			document.addEventListener( 'input', function ( event ) {
				var picker = event.target.closest ? event.target.closest( '.oc-cgal__color' ) : null;
				if ( ! picker ) {
					return;
				}
				var row = picker.closest( '.oc-cgal' );
				row.querySelector( '.oc-cgal__colval' ).value = picker.value;
				row.querySelector( '.oc-cgal__colreset' ).style.display = '';
			} );

			document.addEventListener( 'click', function ( event ) {
				var row = event.target.closest( '.oc-cgal' );
				if ( ! row ) {
					return;
				}

				if ( event.target.closest( '.oc-cgal__x' ) ) {
					event.target.closest( '.oc-cgal__chip' ).remove();
					syncIds( row );
					return;
				}

				if ( event.target.closest( '.oc-cgal__colreset' ) ) {
					var shade = row.querySelector( '.oc-cgal__color' );
					shade.value = shade.dataset.def;
					row.querySelector( '.oc-cgal__colval' ).value = '';
					event.target.closest( '.oc-cgal__colreset' ).style.display = 'none';
					return;
				}

				if ( event.target.closest( '.oc-cgal__swx' ) ) {
					row.querySelector( '.oc-cgal__sw' ).value = '';
					row.querySelector( '.oc-cgal__swchip' ).style.display = 'none';
					row.querySelector( '.oc-cgal__swpick' ).style.display = '';
					return;
				}

				if ( ! window.wp || ! wp.media ) {
					return;
				}

				if ( event.target.closest( '.oc-cgal__pick' ) ) {
					var frame = wp.media( { multiple: 'add', library: { type: 'image' } } );
					frame.on( 'select', function () {
						var box = row.querySelector( '.oc-cgal__thumbs' );
						var have = [];
						box.querySelectorAll( '.oc-cgal__chip' ).forEach( function ( chip ) {
							have.push( chip.dataset.id );
						} );
						frame.state().get( 'selection' ).forEach( function ( att ) {
							var a = att.toJSON();
							if ( have.indexOf( String( a.id ) ) > -1 ) {
								return;
							}
							var u = a.sizes && a.sizes.thumbnail ? a.sizes.thumbnail.url : a.url;
							box.insertAdjacentHTML( 'beforeend', chipHtml( a.id, u ) );
						} );
						syncIds( row );
					} );
					frame.open();
					return;
				}

				if ( event.target.closest( '.oc-cgal__swpick' ) ) {
					var swFrame = wp.media( { multiple: false, library: { type: 'image' } } );
					swFrame.on( 'select', function () {
						var a = swFrame.state().get( 'selection' ).first().toJSON();
						var u = a.sizes && a.sizes.thumbnail ? a.sizes.thumbnail.url : a.url;
						row.querySelector( '.oc-cgal__sw' ).value = u;
						row.querySelector( '.oc-cgal__swprev' ).src = u;
						row.querySelector( '.oc-cgal__swchip' ).style.display = 'inline-block';
						row.querySelector( '.oc-cgal__swpick' ).style.display = 'none';
					} );
					swFrame.open();
					return;
				}
			} );
		}() );
		</script>
		<?php
	}

	/**
	 * Persist the colour galleries.
	 *
	 * @param int $post_id Product id.
	 */
	public function save_galleries( $post_id ): void {
		// Woo verified its own nonce before this hook fires.
		if ( ! isset( $_POST['oc_cgal'] ) || ! is_array( $_POST['oc_cgal'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}

		$clean = array();

		foreach ( wp_unslash( (array) $_POST['oc_cgal'] ) as $slug => $entry ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$imgs   = array_filter( array_map( 'absint', explode( ',', (string) ( $entry['imgs'] ?? '' ) ) ) );
			$swatch = esc_url_raw( (string) ( $entry['swatch'] ?? '' ) );
			$color  = (string) sanitize_hex_color( (string) ( $entry['color'] ?? '' ) );

			if ( ! empty( $imgs ) || '' !== $swatch || '' !== $color ) {
				$clean[ sanitize_title( (string) $slug ) ] = array(
					'imgs'   => array_values( $imgs ),
					'swatch' => $swatch,
					'color'  => $color,
				);
			}
		}

		if ( empty( $clean ) ) {
			delete_post_meta( $post_id, '_oc_color_galleries' );
		} else {
			update_post_meta( $post_id, '_oc_color_galleries', $clean );
		}
	}

	/**
	 * Drop the data-thumb / data-thumb-srcset attributes Woo prints for its
	 * flexslider thumbnails. The slider is removed here and nothing reads
	 * them — but data-thumb-srcset repeats the whole srcset string per
	 * slide, which alone was a third of the colour-gallery payload.
	 *
	 * @param string $html Gallery image html.
	 * @return string
	 */
	public static function strip_thumb_data( string $html ): string {
		return (string) preg_replace( '/\sdata-thumb(?:-alt|-srcset)?="[^"]*"/', '', $html );
	}

	/**
	 * The colour → gallery map for the front end: ready-made slide markup per
	 * colour, so a swatch click swaps the gallery with zero requests.
	 */
	public function galleries_json(): void {
		global $product;

		if ( ! $product instanceof \WC_Product || ! function_exists( 'wc_get_gallery_image_html' ) ) {
			return;
		}

		$galleries = $this->galleries_meta( $product->get_id() );
		$map       = array();

		foreach ( $galleries as $slug => $entry ) {
			if ( empty( $entry['imgs'] ) ) {
				continue;
			}

			$slides = array();
			foreach ( $entry['imgs'] as $i => $img_id ) {
				$slides[] = self::strip_thumb_data( wc_get_gallery_image_html( $img_id, 0 === $i ) );
			}

			$map[ $slug ] = $slides;
		}

		if ( empty( $map ) ) {
			return;
		}

		printf(
			'<script type="application/json" id="oc-color-galleries">%s</script>',
			wp_json_encode( $map ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON in a non-executed script tag.
		);
	}

	/**
	 * Save the linked group and keep every member in sync.
	 *
	 * @param int $post_id Product id.
	 */
	public function save_links( $post_id ): void {
		// Woo verified its own nonce before this hook fires.
		$new = isset( $_POST['oc_color_links'] ) ? array_filter( array_map( 'absint', (array) wp_unslash( $_POST['oc_color_links'] ) ) ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$old = array_filter( array_map( 'absint', (array) get_post_meta( $post_id, '_oc_color_links', true ) ) );

		$group = array_values( array_unique( array_merge( array( (int) $post_id ), $new ) ) );

		foreach ( $group as $member ) {
			$others = array_values( array_diff( $group, array( $member ) ) );
			update_post_meta( $member, '_oc_color_links', $others );
		}

		// A sibling removed here forgets this product too.
		foreach ( array_diff( $old, $new ) as $removed ) {
			$kept = array_values(
				array_diff(
					array_filter( array_map( 'absint', (array) get_post_meta( $removed, '_oc_color_links', true ) ) ),
					array( (int) $post_id )
				)
			);
			update_post_meta( $removed, '_oc_color_links', $kept );
		}
	}

	/**
	 * Colour sibling thumbs on the product page — a click navigates. Renders
	 * in the variations area, in the same "Label: value" format as every
	 * attribute row: the label comes from the product's swatch attribute and
	 * the value is this product's own colour.
	 */
	public function product_colors(): void {
		global $product;

		if ( ! $product instanceof \WC_Product ) {
			return;
		}

		$row = $this->colors_row( $product->get_id(), 'oc-colors--product' );

		if ( '' === $row ) {
			return;
		}

		$label = __( 'Colours', 'oc-theme' );
		$value = '';

		foreach ( array_keys( $product->get_attributes() ) as $attr_tax ) {
			// Keys arrive percent-encoded for Hebrew taxonomies.
			$attr_tax = rawurldecode( (string) $attr_tax );

			if ( ! in_array( $this->attr_type( $attr_tax ), array( 'swatch', 'swatch_image' ), true ) ) {
				continue;
			}

			$terms = wc_get_product_terms( $product->get_id(), $attr_tax, array( 'fields' => 'names' ) );

			if ( 1 === count( $terms ) ) {
				$label = wc_attribute_label( $attr_tax );
				$value = (string) $terms[0];
			}
			break;
		}

		printf(
			'<div class="oc-colors-wrap"><span class="oc-colors-label">%s%s</span>%s</div>',
			esc_html( $label ),
			'' !== $value ? '<span class="oc-choice">' . esc_html( $value ) . '</span>' : '',
			$row // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
		);
	}

	/**
	 * Colour swatches under the card. Linked siblings when the product has
	 * them; otherwise the colour variation terms of a regular variable
	 * product — the exact same behaviour: a click swaps the card's gallery
	 * in place, and the card links carry the colour so the product page
	 * opens with it selected.
	 */
	public function loop_colors(): void {
		global $product;

		if ( ! $product instanceof \WC_Product ) {
			return;
		}

		$row = $this->colors_row( $product->get_id(), 'oc-colors--loop', true );

		if ( '' === $row ) {
			$row = $this->loop_term_colors( $product );
		}

		echo $row; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
	}

	/**
	 * Term swatches for a variable product's colour attribute on the card.
	 *
	 * @param \WC_Product $product Product.
	 * @return string Empty when there is no swatch attribute with 2+ values.
	 */
	private function loop_term_colors( \WC_Product $product ): string {
		if ( ! $product->is_type( 'variable' ) ) {
			return '';
		}

		foreach ( array_keys( $product->get_attributes() ) as $attr_tax ) {
			// Keys arrive percent-encoded for Hebrew taxonomies.
			$attr_tax = rawurldecode( (string) $attr_tax );
			$type     = $this->attr_type( $attr_tax );

			if ( ! in_array( $type, array( 'swatch', 'swatch_image' ), true ) ) {
				continue;
			}

			$terms = wc_get_product_terms( $product->get_id(), $attr_tax, array( 'fields' => 'all' ) );

			if ( count( $terms ) < 2 ) {
				return '';
			}

			$galleries = $this->galleries_meta( $product->get_id() );
			// Same rule as the card itself: inside a sideways row there is one
			// picture, so the swatch must not hand the script four to rebuild.
			$max       = ( 'gallery' === get_theme_mod( 'oc_card_image_mode', 'single' ) && ! WooCommerce::in_slider() ) ? max( 2, (int) get_theme_mod( 'oc_card_gallery_max', 4 ) ) : 1;
			$permalink = get_permalink( $product->get_id() );
			$list      = array();

			// Woo reads the preselection from the sanitize_title form of the
			// attribute name — for a Hebrew attribute that means the
			// percent-encoded key, not the readable one.
			// rawurlencode keeps the sanitize_title percents literal in the
			// URL, so PHP's own decode hands Woo exactly the key it expects.
			$qkey = 'attribute_' . rawurlencode( sanitize_title( $attr_tax ) );

			foreach ( $terms as $term ) {
				$style = $this->swatch_style( $product, $attr_tax, $term, $type );

				if ( '' === $style ) {
					continue;
				}

				// The colour's own gallery drives the card; a variation image
				// stands in when no gallery was attached.
				$imgs = array();
				foreach ( array_slice( $galleries[ $term->slug ]['imgs'] ?? array(), 0, $max ) as $img_id ) {
					$img_url = wp_get_attachment_image_url( $img_id, 'large' );
					if ( $img_url ) {
						$imgs[] = $img_url;
					}
				}

				if ( empty( $imgs ) ) {
					$var_img = $this->variation_image( $product, $attr_tax, $term->slug, 'large' );
					if ( '' !== $var_img ) {
						$imgs[] = $var_img;
					}
				}

				// The first colour is the card's default, pre-marked — the
				// same one the card links carry into the product page.
				$list[] = sprintf(
					'<a class="oc-colors__item oc-colors__item--term%s" href="%s" style="%s" title="%s" aria-label="%s"%s data-url="%s" data-pid="%d" data-imgs="%s" data-slug="%s"></a>',
					empty( $list ) ? ' is-current' : '',
					esc_url( add_query_arg( $qkey, $term->slug, $permalink ) ),
					$style, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
					esc_attr( $term->name ),
					esc_attr( $term->name ),
					empty( $list ) ? ' aria-current="true"' : '',
					esc_url( add_query_arg( $qkey, $term->slug, $permalink ) ),
					absint( $product->get_id() ),
					esc_attr( (string) wp_json_encode( $imgs ) ),
					esc_attr( $term->slug )
				);
			}

			if ( empty( $list ) ) {
				return '';
			}

			return '<div class="oc-colors oc-colors--loop">' . implode( '', self::capped( $list, 0, $permalink ) ) . '</div>';
		}

		return '';
	}

	/**
	 * A row of colour-sibling thumbnails, the current product marked.
	 *
	 * Siblings render in id order on every member's page — a stable order, so
	 * the row never reshuffles as the visitor moves between colours.
	 *
	 * @param int    $product_id Current product.
	 * @param string $css_class  Context class.
	 * @param bool   $with_data  Attach card-swap data (catalogue cards).
	 * @return string Empty when the product has no siblings.
	 */
	private function colors_row( int $product_id, string $css_class, bool $with_data = false ): string {
		$links = array_filter( array_map( 'absint', (array) get_post_meta( $product_id, '_oc_color_links', true ) ) );

		if ( empty( $links ) ) {
			return '';
		}

		$ids = array_unique( array_merge( array( $product_id ), $links ) );
		sort( $ids );

		// In a smart "on sale" category, a colour that is NOT on promotion
		// must not be offered — a shopper switching to it would land on a
		// full-price product inside the sale section.
		$sale_context = $with_data && Filters::sale_context();

		$list          = array();
		$at_current    = -1;
		$sale_siblings = 0;

		foreach ( $ids as $id ) {
			$sibling = wc_get_product( $id );

			if ( ! $sibling || 'publish' !== $sibling->get_status() ) {
				continue;
			}

			if ( $sale_context && ! Filters::product_promoted( $sibling ) ) {
				continue;
			}

			if ( $sale_context && $id !== $product_id ) {
				++$sale_siblings;
			}

			// The sibling's own colour value decides the circle, through the
			// same chain as every swatch — attribute type first, product
			// thumbnail only as the last fallback. So a colour-led attribute
			// shows colours here too, not product photos.
			$style = $this->sibling_style( $sibling );
			$thumb = '' !== $style ? '' : wp_get_attachment_image_url( (int) $sibling->get_image_id(), 'thumbnail' );

			if ( '' === $style && ! $thumb ) {
				continue;
			}

			$current = $id === $product_id;
			$data    = '';

			if ( $with_data ) {
				$badge = $sibling->is_on_sale()
					// phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- Woo's own badge string; reuse its translation.
					? (string) apply_filters( 'woocommerce_sale_flash', '<span class="onsale">' . esc_html__( 'Sale!', 'woocommerce' ) . '</span>', get_post( $id ), $sibling )
					: '';

				$data = sprintf(
					' data-url="%s" data-pid="%d" data-name="%s" data-price="%s" data-badge="%s" data-imgs="%s" data-oos="%s" data-var="%s"',
					esc_url( get_permalink( $id ) ),
					absint( $id ),
					esc_attr( $sibling->get_name() ),
					esc_attr( $sibling->get_price_html() ),
					esc_attr( $badge ),
					esc_attr( (string) wp_json_encode( $this->card_image_urls( $sibling ) ) ),
					$sibling->is_in_stock() ? '0' : '1',
					$sibling->is_type( 'variable' ) ? '1' : '0'
				);
			}

			if ( $current ) {
				$at_current = count( $list );
			}

			$list[] = sprintf(
				'<a class="oc-colors__item%s" href="%s" title="%s" aria-label="%s"%s%s%s>%s</a>',
				$current ? ' is-current' : '',
				esc_url( $current ? '#' : get_permalink( $id ) ),
				esc_attr( $sibling->get_name() ),
				esc_attr( $sibling->get_name() ),
				$current ? ' aria-current="true"' : '',
				$data, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
				'' !== $style ? ' style="' . $style . '"' : '', // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
				'' !== $style ? '' : '<img src="' . esc_url( (string) $thumb ) . '" alt="" loading="lazy" />'
			);
		}

		if ( empty( $list ) ) {
			return '';
		}

		// Only its own colour survived the sale filter: no row at all.
		if ( $sale_context && 0 === $sale_siblings ) {
			return '';
		}

		// The catalogue row is capped; anywhere else shows everything.
		if ( false !== strpos( $css_class, 'loop' ) ) {
			$list = self::capped( $list, $at_current, get_permalink( $product_id ) );
		}

		return '<div class="oc-colors ' . esc_attr( $css_class ) . '">' . implode( '', $list ) . '</div>';
	}

	/**
	 * Swatch style for a colour-sibling product, resolved from its own solo
	 * colour value through the standard chain. Empty when the product has no
	 * swatch attribute or nothing to draw — the caller falls back to the
	 * product thumbnail.
	 *
	 * @param \WC_Product $sibling Sibling product.
	 * @return string
	 */
	private function sibling_style( \WC_Product $sibling ): string {
		foreach ( array_keys( $sibling->get_attributes() ) as $attr_tax ) {
			// Keys arrive percent-encoded for Hebrew taxonomies.
			$attr_tax = rawurldecode( (string) $attr_tax );
			$type     = $this->attr_type( $attr_tax );

			if ( ! in_array( $type, array( 'swatch', 'swatch_image' ), true ) ) {
				continue;
			}

			$terms = wc_get_product_terms( $sibling->get_id(), $attr_tax, array( 'fields' => 'all' ) );

			if ( 1 === count( $terms ) ) {
				return $this->swatch_style( $sibling, $attr_tax, $terms[0], $type );
			}
			break;
		}

		return '';
	}

	/**
	 * The image URLs a catalogue card would show for a product, mirroring the
	 * card-media rules (mode and gallery cap).
	 *
	 * @param \WC_Product $product Product.
	 * @return string[]
	 */
	private function card_image_urls( \WC_Product $product ): array {
		$mode = (string) get_theme_mod( 'oc_card_image_mode', 'single' );
		$max  = ( 'gallery' === $mode && ! WooCommerce::in_slider() ) ? max( 2, (int) get_theme_mod( 'oc_card_gallery_max', 4 ) ) : 1;

		$ids = array_merge(
			array( (int) $product->get_image_id() ),
			array_map( 'intval', $product->get_gallery_image_ids() )
		);
		$ids = array_slice( array_values( array_unique( array_filter( $ids ) ) ), 0, $max );

		$urls = array();
		foreach ( $ids as $id ) {
			$url = wp_get_attachment_image_url( $id, 'large' );
			if ( $url ) {
				$urls[] = $url;
			}
		}

		return $urls;
	}
}
