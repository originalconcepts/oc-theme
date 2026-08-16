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
	 * Hook in.
	 */
	public function register(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		// "Buttons" and "Swatch" join Woo's own attribute-type dropdown.
		add_filter( 'product_attributes_type_selector', array( $this, 'attribute_types' ) );

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

		add_action( 'woocommerce_single_product_summary', array( $this, 'product_colors' ), 12 );
		add_action( 'woocommerce_after_shop_loop_item_title', array( $this, 'loop_colors' ), 7 );
	}

	/**
	 * Extra attribute display types.
	 *
	 * @param array $types Type map.
	 * @return array
	 */
	public function attribute_types( array $types ): array {
		$types['button'] = __( 'Buttons', 'oc-theme' );
		$types['swatch'] = __( 'Swatch (colour / image)', 'oc-theme' );

		return $types;
	}

	/**
	 * Display type for an attribute taxonomy, e.g. pa_color.
	 *
	 * @param string $taxonomy Attribute taxonomy name.
	 * @return string select|button|swatch
	 */
	private function attr_type( string $taxonomy ): string {
		foreach ( wc_get_attribute_taxonomies() as $attribute ) {
			if ( 'pa_' . $attribute->attribute_name === $taxonomy ) {
				return in_array( $attribute->attribute_type, array( 'button', 'swatch' ), true )
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
			if ( 'swatch' !== $attribute->attribute_type ) {
				continue;
			}

			$taxonomy = 'pa_' . $attribute->attribute_name;
			add_action( $taxonomy . '_add_form_fields', array( $this, 'term_fields_add' ) );
			add_action( $taxonomy . '_edit_form_fields', array( $this, 'term_fields_edit' ) );
		}
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
			<label for="oc_swatch_image"><?php esc_html_e( 'Swatch image URL (overrides the colour)', 'oc-theme' ); ?></label>
			<input type="url" id="oc_swatch_image" name="oc_swatch_image" value="" placeholder="https://" />
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
			<th scope="row"><label for="oc_swatch_image"><?php esc_html_e( 'Swatch image URL (overrides the colour)', 'oc-theme' ); ?></label></th>
			<td><input type="url" id="oc_swatch_image" name="oc_swatch_image" value="<?php echo esc_url( $image ); ?>" placeholder="https://" /></td>
		</tr>
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

		$items = '';

		foreach ( $options as $slug ) {
			$term = get_term_by( 'slug', $slug, $taxonomy );

			if ( ! $term instanceof \WP_Term ) {
				continue;
			}

			$is_sel = $selected === $slug ? ' is-selected' : '';
			$label  = esc_html( $term->name );

			if ( 'swatch' === $type ) {
				$image = (string) get_term_meta( $term->term_id, 'oc_swatch_image', true );
				$color = (string) get_term_meta( $term->term_id, 'oc_swatch_color', true );
				$style = '' !== $image
					? 'background-image:url(' . esc_url( $image ) . ');background-size:cover;'
					: 'background-color:' . esc_attr( '' !== $color ? $color : '#ddd' ) . ';';

				$items .= sprintf(
					'<button type="button" class="oc-var__swatch%s" data-value="%s" style="%s" title="%s" aria-label="%s"></button>',
					$is_sel,
					esc_attr( $slug ),
					$style,
					esc_attr( $term->name ),
					esc_attr( $term->name )
				);
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
			<?php echo wc_help_tip( esc_html__( 'The same product in other colours. Links sync both ways: connect black and grey here and each of them links back automatically.', 'oc-theme' ) ); ?>
		</p>
		<?php
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
			$kept = array_values( array_diff(
				array_filter( array_map( 'absint', (array) get_post_meta( $removed, '_oc_color_links', true ) ) ),
				array( (int) $post_id )
			) );
			update_post_meta( $removed, '_oc_color_links', $kept );
		}
	}

	/**
	 * Colour sibling thumbs on the product page — a click navigates.
	 */
	public function product_colors(): void {
		global $product;

		if ( ! $product instanceof \WC_Product ) {
			return;
		}

		$row = $this->colors_row( $product->get_id(), 'oc-colors--product' );

		if ( '' !== $row ) {
			echo '<div class="oc-colors-wrap"><span class="oc-colors-label">' . esc_html__( 'Colours', 'oc-theme' ) . '</span>' . $row . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
		}
	}

	/**
	 * Colour sibling thumbs under the card.
	 */
	public function loop_colors(): void {
		global $product;

		if ( ! $product instanceof \WC_Product ) {
			return;
		}

		echo $this->colors_row( $product->get_id(), 'oc-colors--loop' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
	}

	/**
	 * A row of colour-sibling thumbnails, the current product marked.
	 *
	 * @param int    $product_id Current product.
	 * @param string $class      Context class.
	 * @return string Empty when the product has no siblings.
	 */
	private function colors_row( int $product_id, string $class ): string {
		$links = array_filter( array_map( 'absint', (array) get_post_meta( $product_id, '_oc_color_links', true ) ) );

		if ( empty( $links ) ) {
			return '';
		}

		$ids   = array_merge( array( $product_id ), $links );
		$items = '';

		foreach ( $ids as $id ) {
			$sibling = wc_get_product( $id );

			if ( ! $sibling || 'publish' !== $sibling->get_status() ) {
				continue;
			}

			$thumb = wp_get_attachment_image_url( (int) $sibling->get_image_id(), 'thumbnail' );

			if ( ! $thumb ) {
				continue;
			}

			$current = $id === $product_id;

			$items .= sprintf(
				'<a class="oc-colors__item%s" href="%s" title="%s" aria-label="%s"%s><img src="%s" alt="" loading="lazy" /></a>',
				$current ? ' is-current' : '',
				esc_url( $current ? '#' : get_permalink( $id ) ),
				esc_attr( $sibling->get_name() ),
				esc_attr( $sibling->get_name() ),
				$current ? ' aria-current="true"' : '',
				esc_url( $thumb )
			);
		}

		if ( '' === $items ) {
			return '';
		}

		return '<div class="oc-colors ' . esc_attr( $class ) . '">' . $items . '</div>';
	}
}
