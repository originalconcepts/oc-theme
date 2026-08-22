<?php
/**
 * Catalogue tiles: how one product presents itself in a listing.
 *
 * A grid where every card is the same size reads as a spreadsheet. Three
 * settings live on the product — the size of its tile, whether that size
 * also applies on a phone, and an image chosen for the catalogue rather
 * than the product page — and the archive renders them with no extra
 * queries: the meta is already primed with the loop.
 *
 * @package OC_Theme
 */

declare( strict_types = 1 );

namespace OC\Theme;

defined( 'ABSPATH' ) || exit;

/**
 * Per-product catalogue presentation.
 */
final class Catalog {

	/**
	 * Tile sizes, keyed by the value stored on the product.
	 *
	 * @return array<string,string>
	 */
	public static function sizes(): array {
		return array(
			''     => __( 'Normal — one cell', 'oc-theme' ),
			'wide' => __( 'Wide — 2 columns', 'oc-theme' ),
			'big'  => __( 'Large — 2×2', 'oc-theme' ),
		);
	}

	/**
	 * A product's tile settings.
	 *
	 * @param int $product_id Product id.
	 * @return array{size:string,flat_m:bool,image:int}
	 */
	public static function tile( int $product_id ): array {
		$size = (string) get_post_meta( $product_id, '_oc_tile_size', true );

		return array(
			'size'   => array_key_exists( $size, self::sizes() ) ? $size : '',
			'flat_m' => (bool) get_post_meta( $product_id, '_oc_tile_flat_m', true ),
			'image'  => (int) get_post_meta( $product_id, '_oc_tile_image', true ),
		);
	}

	/**
	 * Hook in.
	 */
	public function register(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		add_filter( 'woocommerce_product_data_tabs', array( $this, 'tab' ) );
		add_action( 'woocommerce_product_data_panels', array( $this, 'panel' ) );
		add_action( 'woocommerce_process_product_meta', array( $this, 'save' ) );

		add_filter( 'the_posts', array( $this, 'prime_images' ), 10, 2 );
		add_filter( 'woocommerce_post_class', array( $this, 'tile_class' ), 10, 2 );
		add_filter( 'oc_card_image_ids', array( $this, 'catalogue_image' ), 10, 2 );

		// Managing a few thousand products means never opening them one by one.
		add_filter( 'manage_product_posts_columns', array( $this, 'column' ) );
		add_action( 'manage_product_posts_custom_column', array( $this, 'column_body' ), 10, 2 );
		add_action( 'quick_edit_custom_box', array( $this, 'quick_edit' ), 10, 2 );
		add_action( 'bulk_edit_custom_box', array( $this, 'quick_edit' ), 10, 2 );
		add_action( 'admin_footer-edit.php', array( $this, 'list_script' ) );
		add_action( 'save_post_product', array( $this, 'quick_save' ) );
		add_action( 'woocommerce_product_bulk_edit_save', array( $this, 'bulk_save' ) );
	}

	/* ------------------------------------------------------------ product */

	/**
	 * The product-data tab.
	 *
	 * @param array $tabs Tabs.
	 */
	public function tab( array $tabs ): array {
		$tabs['oc_catalog'] = array(
			'label'    => __( 'Catalogue display', 'oc-theme' ),
			'target'   => 'oc_catalog_panel',
			'class'    => array(),
			'priority' => 64,
		);

		return $tabs;
	}

	/**
	 * The panel behind it.
	 */
	public function panel(): void {
		global $post;

		$tile = self::tile( (int) $post->ID );

		wp_enqueue_media();
		?>
		<div id="oc_catalog_panel" class="panel woocommerce_options_panel">
			<div style="padding:12px;">
				<p style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin:0 0 4px;">
					<label style="float:none;inline-size:auto;margin:0;"><?php esc_html_e( 'Tile size', 'oc-theme' ); ?></label>
					<select name="oc_tile_size" style="inline-size:220px;">
						<?php foreach ( self::sizes() as $value => $label ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $tile['size'], $value ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<label style="float:none;inline-size:auto;margin:0 12px 0 0;">
						<input type="checkbox" name="oc_tile_flat_m" value="1" <?php checked( true, $tile['flat_m'] ); ?> />
						<?php esc_html_e( 'Normal size on a phone', 'oc-theme' ); ?>
					</label>
				</p>
				<p class="description" style="margin:0 0 16px;"><?php esc_html_e( 'An enlarged tile fills the width on a phone unless you tick the box.', 'oc-theme' ); ?></p>

				<p style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin:0 0 4px;">
					<label style="float:none;inline-size:auto;margin:0;"><?php esc_html_e( 'Catalogue image', 'oc-theme' ); ?></label>
					<input type="hidden" name="oc_tile_image" id="oc_tile_image" value="<?php echo esc_attr( (string) ( $tile['image'] ?: '' ) ); ?>" />
					<button type="button" class="button" id="oc_tile_pick"><?php esc_html_e( 'Choose image', 'oc-theme' ); ?></button>
					<button type="button" class="button-link-delete" id="oc_tile_clear" style="<?php echo $tile['image'] ? '' : 'display:none;'; ?>"><?php esc_html_e( 'Remove', 'oc-theme' ); ?></button>
				</p>
				<div id="oc_tile_prev" style="margin:6px 0 0;">
					<?php
					if ( $tile['image'] ) {
						echo wp_get_attachment_image( $tile['image'], 'thumbnail', false, array( 'style' => 'border-radius:6px;' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core markup.
					}
					?>
				</div>
				<p class="description" style="margin-block-start:6px;"><?php esc_html_e( 'Shown in listings instead of the product image. Empty = the product image.', 'oc-theme' ); ?></p>
			</div>
		</div>
		<script>
		( function () {
			var pick = document.getElementById( 'oc_tile_pick' ),
				clear = document.getElementById( 'oc_tile_clear' ),
				field = document.getElementById( 'oc_tile_image' ),
				prev = document.getElementById( 'oc_tile_prev' ),
				frame;

			pick.addEventListener( 'click', function () {
				if ( ! frame ) {
					frame = wp.media( { title: pick.textContent, library: { type: 'image' }, multiple: false } );
					frame.on( 'select', function () {
						var img = frame.state().get( 'selection' ).first().toJSON();
						field.value = img.id;
						prev.innerHTML = '<img src="' + ( img.sizes && img.sizes.thumbnail ? img.sizes.thumbnail.url : img.url ) + '" style="border-radius:6px;" />';
						clear.style.display = '';
					} );
				}
				frame.open();
			} );

			clear.addEventListener( 'click', function () {
				field.value = '';
				prev.innerHTML = '';
				clear.style.display = 'none';
			} );
		}() );
		</script>
		<?php
	}

	/**
	 * Persist the panel.
	 *
	 * @param int $product_id Product id.
	 */
	public function save( $product_id ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce verifies before this fires.
		$size = sanitize_key( wp_unslash( $_POST['oc_tile_size'] ?? '' ) );

		update_post_meta( $product_id, '_oc_tile_size', array_key_exists( $size, self::sizes() ) ? $size : '' );
		update_post_meta( $product_id, '_oc_tile_flat_m', empty( $_POST['oc_tile_flat_m'] ) ? '' : 1 );
		update_post_meta( $product_id, '_oc_tile_image', absint( $_POST['oc_tile_image'] ?? 0 ) );
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/* ------------------------------------------------------------ archive */

	/**
	 * One query for every catalogue image on the page.
	 *
	 * Without this, a listing where each product carries its own catalogue
	 * image asks the database for each attachment separately — the classic
	 * N+1, and the fastest way to undo the work of the performance passes.
	 *
	 * @param array     $posts Posts.
	 * @param \WP_Query $query Query.
	 */
	public function prime_images( $posts, $query ) {
		if ( is_admin() || empty( $posts ) || ! is_object( $posts[0] ) || 'product' !== get_post_type( $posts[0] ) ) {
			return $posts;
		}

		$ids = wp_list_pluck( $posts, 'ID' );
		update_meta_cache( 'post', $ids );

		$images = array();

		foreach ( $ids as $id ) {
			$image = (int) get_post_meta( $id, '_oc_tile_image', true );

			if ( $image ) {
				$images[] = $image;
			}
		}

		if ( $images ) {
			_prime_post_caches( array_unique( $images ), false, true );
		}

		return $posts;
	}

	/**
	 * Carry the tile size onto the list item.
	 *
	 * @param array       $classes Classes.
	 * @param \WC_Product $product Product.
	 */
	public function tile_class( $classes, $product ) {
		if ( is_admin() || ! $product instanceof \WC_Product ) {
			return $classes;
		}

		$tile = self::tile( $product->get_id() );

		if ( '' === $tile['size'] ) {
			return $classes;
		}

		$classes[] = 'oc-tile--' . $tile['size'];

		if ( $tile['flat_m'] ) {
			$classes[] = 'oc-tile--m-plain';
		}

		return $classes;
	}

	/**
	 * Lead the card with the catalogue image when one is set.
	 *
	 * @param array       $ids     Attachment ids.
	 * @param \WC_Product $product Product.
	 */
	public function catalogue_image( $ids, $product ) {
		if ( ! $product instanceof \WC_Product ) {
			return $ids;
		}

		$image = (int) get_post_meta( $product->get_id(), '_oc_tile_image', true );

		if ( ! $image ) {
			return $ids;
		}

		// It leads; the rest of the strip (the hover images) stays as it is.
		return array_values( array_unique( array_merge( array( $image ), (array) $ids ) ) );
	}

	/* --------------------------------------------------------------- list */

	/**
	 * A column so the size is visible without opening anything.
	 *
	 * @param array $columns Columns.
	 */
	public function column( array $columns ): array {
		$out = array();

		foreach ( $columns as $key => $label ) {
			$out[ $key ] = $label;

			if ( 'name' === $key ) {
				$out['oc_tile'] = __( 'Display', 'oc-theme' );
			}
		}

		return isset( $out['oc_tile'] ) ? $out : $out + array( 'oc_tile' => __( 'Display', 'oc-theme' ) );
	}

	/**
	 * The cell, which also carries the value for Quick Edit to read.
	 *
	 * @param string $column     Column key.
	 * @param int    $product_id Product id.
	 */
	public function column_body( $column, $product_id ): void {
		if ( 'oc_tile' !== $column ) {
			return;
		}

		$tile  = self::tile( (int) $product_id );
		$sizes = self::sizes();

		echo '<span class="oc-tile-cell" data-size="' . esc_attr( $tile['size'] ) . '" data-flat="' . esc_attr( $tile['flat_m'] ? '1' : '' ) . '">';
		echo '' === $tile['size']
			? '<span style="color:#a7aaad;">—</span>'
			: esc_html( $sizes[ $tile['size'] ] );
		echo '</span>';
	}

	/**
	 * The same two fields inside Quick Edit and Bulk Edit.
	 *
	 * @param string $column    Column key.
	 * @param string $post_type Post type.
	 */
	public function quick_edit( $column, $post_type ): void {
		if ( 'oc_tile' !== $column || 'product' !== $post_type ) {
			return;
		}

		$bulk = 'bulk_edit_custom_box' === current_action();
		?>
		<fieldset class="inline-edit-col-right">
			<div class="inline-edit-col">
				<label style="display:block;margin-block-end:6px;">
					<span class="title" style="inline-size:auto;"><?php esc_html_e( 'Tile size', 'oc-theme' ); ?></span>
					<select name="oc_tile_size">
						<?php if ( $bulk ) : ?>
							<option value="-1"><?php esc_html_e( '— No change —', 'oc-theme' ); ?></option>
						<?php endif; ?>
						<?php foreach ( self::sizes() as $value => $label ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label>
					<input type="checkbox" name="oc_tile_flat_m" value="1" />
					<span class="checkbox-title"><?php esc_html_e( 'Normal size on a phone', 'oc-theme' ); ?></span>
				</label>
			</div>
		</fieldset>
		<?php
	}

	/**
	 * Fill Quick Edit from the row it was opened on.
	 */
	public function list_script(): void {
		$screen = get_current_screen();

		if ( ! $screen || 'edit-product' !== $screen->id ) {
			return;
		}
		?>
		<script>
		( function () {
			if ( ! window.inlineEditPost ) {
				return;
			}

			var open = inlineEditPost.edit;

			inlineEditPost.edit = function ( id ) {
				open.apply( this, arguments );

				var post = typeof id === 'object' ? this.getId( id ) : id;
				if ( ! post ) {
					return;
				}

				var row = document.getElementById( 'post-' + post ),
					form = document.getElementById( 'edit-' + post ),
					cell = row && row.querySelector( '.oc-tile-cell' );

				if ( ! form || ! cell ) {
					return;
				}

				var size = form.querySelector( '[name="oc_tile_size"]' ),
					flat = form.querySelector( '[name="oc_tile_flat_m"]' );

				if ( size ) {
					size.value = cell.dataset.size || '';
				}
				if ( flat ) {
					flat.checked = '1' === cell.dataset.flat;
				}
			};
		}() );
		</script>
		<?php
	}

	/**
	 * Quick Edit save.
	 *
	 * @param int $product_id Product id.
	 */
	public function quick_save( $product_id ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- core verifies the inline-edit nonce first.
		if ( ! isset( $_POST['_inline_edit'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['_inline_edit'] ) ), 'inlineeditnonce' ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $product_id ) ) {
			return;
		}

		$size = sanitize_key( wp_unslash( $_POST['oc_tile_size'] ?? '' ) );

		update_post_meta( $product_id, '_oc_tile_size', array_key_exists( $size, self::sizes() ) ? $size : '' );
		update_post_meta( $product_id, '_oc_tile_flat_m', empty( $_POST['oc_tile_flat_m'] ) ? '' : 1 );
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/**
	 * Bulk Edit save — "no change" leaves the product alone.
	 *
	 * @param \WC_Product $product Product.
	 */
	public function bulk_save( $product ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce verifies before this fires.
		$size = isset( $_POST['oc_tile_size'] ) ? sanitize_key( wp_unslash( $_POST['oc_tile_size'] ) ) : '-1';

		if ( '-1' === $size ) {
			return;
		}

		update_post_meta( $product->get_id(), '_oc_tile_size', array_key_exists( $size, self::sizes() ) ? $size : '' );
		update_post_meta( $product->get_id(), '_oc_tile_flat_m', empty( $_POST['oc_tile_flat_m'] ) ? '' : 1 );
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}
}
