<?php
/**
 * Category page — per-category hero, card image and sub-category display.
 *
 * A product category's edit screen gains an "עמוד קטגוריה" group: a hero
 * (full-width or half-split, desktop + mobile image, height, text over or
 * below the image), a shared card image (used by the categories block, the
 * sub-category strip and the blog), and a sub-category display under the
 * description. All of it is term meta — nothing here is global.
 *
 * @package OC\Theme
 */

declare( strict_types=1 );

namespace OC\Theme;

/**
 * Category page settings and rendering.
 */
class Category {

	/**
	 * Words positions on a hero image, shared with the OC-Blocks hero.
	 *
	 * @return array<string,string>
	 */
	private static function positions(): array {
		return array(
			'cc' => __( 'Centre', 'oc-theme' ),
			'cs' => __( 'Centre, reading side', 'oc-theme' ),
			'bc' => __( 'Bottom centre', 'oc-theme' ),
			'bs' => __( 'Bottom, reading side', 'oc-theme' ),
			'ts' => __( 'Top, reading side', 'oc-theme' ),
		);
	}

	/**
	 * Wire the admin fields and the front-end hero.
	 */
	public function register(): void {
		add_action( 'product_cat_edit_form_fields', array( $this, 'fields' ), 20 );
		add_action( 'edited_product_cat', array( $this, 'save' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_assets' ) );

		add_action( 'wp', array( $this, 'setup' ) );
	}

	/* ------------------------------------------------------------------ *
	 *  Reading helpers
	 * ------------------------------------------------------------------ */

	/**
	 * The card image id for a term, with the fallback chain George asked for:
	 * the term's own card image, then its hero desktop image, then its hero
	 * mobile image, then the WooCommerce category image as a last resort.
	 *
	 * @param int $term_id Term id.
	 * @return int Attachment id, or 0.
	 */
	public static function card_image_id( int $term_id ): int {
		foreach ( array( '_oc_card_img', '_oc_hero_img', '_oc_hero_img_m', 'thumbnail_id' ) as $key ) {
			$id = absint( get_term_meta( $term_id, $key, true ) );

			if ( $id > 0 ) {
				return $id;
			}
		}

		return 0;
	}

	/**
	 * Read a term's hero settings into a tidy shape.
	 *
	 * @param int $term_id Term id.
	 * @return array<string,mixed>
	 */
	private static function hero( int $term_id ): array {
		$get = static function ( string $key, string $def = '' ) use ( $term_id ): string {
			$v = get_term_meta( $term_id, $key, true );

			return '' !== (string) $v ? (string) $v : $def;
		};

		return array(
			'layout' => $get( '_oc_hero_layout' ),                 // '' | full | split.
			'img'    => absint( $get( '_oc_hero_img' ) ),
			'imgm'   => absint( $get( '_oc_hero_img_m' ) ),
			'h'      => absint( $get( '_oc_hero_h' ) ),
			'hm'     => absint( $get( '_oc_hero_hm' ) ),
			'text'   => $get( '_oc_hero_text', 'over' ),           // over | below.
			'pos'    => $get( '_oc_hero_pos', 'bs' ),
			'tone'   => $get( '_oc_hero_tone', 'light' ),          // light | dark.
			'shade'  => (int) $get( '_oc_hero_shade', '0' ),
			'side'   => $get( '_oc_hero_side', 'start' ),          // image reading-side | opposite.
			'cbg'    => $get( '_oc_hero_cbg' ),
		);
	}

	/**
	 * Is there a hero worth rendering for this term?
	 *
	 * @param array<string,mixed> $h Hero settings.
	 */
	private static function has_hero( array $h ): bool {
		return in_array( $h['layout'], array( 'full', 'split' ), true ) && $h['img'] > 0;
	}

	/* ------------------------------------------------------------------ *
	 *  Front-end
	 * ------------------------------------------------------------------ */

	/**
	 * On a product-category archive with a hero set, suppress the default
	 * title + description (they move into the hero) and render the hero at
	 * full page width, above the constrained shop content.
	 */
	public function setup(): void {
		if ( ! function_exists( 'is_product_category' ) || ! is_product_category() ) {
			return;
		}

		$term = get_queried_object();

		if ( ! $term instanceof \WP_Term ) {
			return;
		}

		$h = self::hero( $term->term_id );

		if ( ! self::has_hero( $h ) ) {
			return;
		}

		// The hero carries the H1 and the description, so hide Woo's own.
		add_filter( 'woocommerce_show_page_title', '__return_false' );
		remove_action( 'woocommerce_archive_description', 'woocommerce_taxonomy_archive_description', 10 );
		remove_action( 'woocommerce_archive_description', 'woocommerce_product_archive_description', 10 );

		// Full-bleed: render before the <main> wrapper opens (open_wrapper is
		// priority 10 on the same hook).
		add_action(
			'woocommerce_before_main_content',
			function () use ( $term, $h ): void {
				echo self::render( $term, $h ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
			},
			5
		);
	}

	/**
	 * Build the hero markup.
	 *
	 * @param \WP_Term            $term Category.
	 * @param array<string,mixed> $h    Hero settings.
	 * @return string
	 */
	private static function render( \WP_Term $term, array $h ): string {
		$title = '<h1 class="oc-chero__title oc-page-title">' . esc_html( $term->name ) . '</h1>';
		$desc  = trim( (string) term_description( $term->term_id, 'product_cat' ) );
		$desc  = '' !== $desc ? '<div class="oc-chero__desc">' . wp_kses_post( $desc ) . '</div>' : '';
		$words = '<div class="oc-chero__words"><div class="oc-chero__wordsin">' . $title . $desc . '</div></div>';

		$style = '';

		if ( $h['h'] > 0 ) {
			$style .= '--ch-h:' . $h['h'] . 'px;';
		}

		if ( $h['hm'] > 0 ) {
			$style .= '--ch-hm:' . $h['hm'] . 'px;';
		}

		if ( 'split' === $h['layout'] && '' !== $h['cbg'] ) {
			$style .= '--ch-cbg:' . $h['cbg'] . ';';
		}

		$style = '' !== $style ? ' style="' . esc_attr( $style ) . '"' : '';

		if ( 'split' === $h['layout'] ) {
			$classes = 'oc-chero oc-chero--split oc-chero--img-' . esc_attr( $h['side'] );

			return '<section class="' . $classes . '"' . $style . '>'
				. '<div class="oc-chero__media">' . self::picture( $h, $term->name ) . '</div>'
				. '<div class="oc-chero__panel">' . $words . '</div>'
				. '</section>';
		}

		// Full-width.
		$over    = 'over' === $h['text'];
		$classes = 'oc-chero oc-chero--full oc-chero--text-' . ( $over ? 'over' : 'below' )
			. ( $over ? ' oc-chero--pos-' . esc_attr( $h['pos'] ) . ' oc-chero--' . esc_attr( $h['tone'] ) : '' );

		$shade = ( $over && $h['shade'] > 0 )
			? '<span class="oc-chero__shade" style="opacity:' . esc_attr( (string) ( $h['shade'] / 100 ) ) . '"></span>'
			: '';

		return '<section class="' . $classes . '"' . $style . '>'
			. '<div class="oc-chero__media">' . self::picture( $h, $term->name ) . $shade . '</div>'
			. $words
			. '</section>';
	}

	/**
	 * Desktop image with an optional mobile art-direction source.
	 *
	 * @param array<string,mixed> $h   Hero settings.
	 * @param string              $alt Alt text.
	 * @return string
	 */
	private static function picture( array $h, string $alt ): string {
		$img = wp_get_attachment_image(
			$h['img'],
			'full',
			false,
			array(
				'class'         => 'oc-chero__img',
				'alt'           => $alt,
				'fetchpriority' => 'high',
				'decoding'      => 'async',
			)
		);

		if ( '' === $img ) {
			return '';
		}

		if ( $h['imgm'] > 0 ) {
			$murl = (string) wp_get_attachment_image_url( $h['imgm'], 'full' );

			if ( '' !== $murl ) {
				return '<picture><source media="(max-width:700px)" srcset="' . esc_url( $murl ) . '">' . $img . '</picture>';
			}
		}

		return $img;
	}

	/* ------------------------------------------------------------------ *
	 *  Admin — the category edit screen
	 * ------------------------------------------------------------------ */

	/**
	 * wp.media on the product-category edit screen.
	 *
	 * @param string $hook Current admin page.
	 */
	public function admin_assets( string $hook ): void {
		if ( 'term.php' !== $hook && 'edit-tags.php' !== $hook ) {
			return;
		}

		$screen = get_current_screen();

		if ( $screen && 'product_cat' === $screen->taxonomy ) {
			wp_enqueue_media();
		}
	}

	/**
	 * An image-picker row (button + hidden id + preview).
	 *
	 * @param string $name    Field / input name.
	 * @param int    $id      Current attachment id.
	 * @param string $label   Row label.
	 * @param string $hint    Description under the control.
	 * @param string $show_if data-attribute gate "field:value|value".
	 */
	private function image_field( string $name, int $id, string $label, string $hint = '', string $show_if = '' ): void {
		$preview = $id > 0
			? wp_get_attachment_image( $id, 'thumbnail', false, array( 'style' => 'display:block;max-inline-size:120px;height:auto;border-radius:6px' ) )
			: '';
		$gate = '' !== $show_if ? ' data-oc-when="' . esc_attr( $show_if ) . '"' : '';
		?>
		<tr class="form-field oc-cat-imgfield" data-oc-imgfield<?php echo $gate; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- literal above. ?>>
			<th scope="row"><label><?php echo esc_html( $label ); ?></label></th>
			<td>
				<div class="oc-cat-img__view" style="margin-block-end:8px"><?php echo $preview; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_get_attachment_image() is safe. ?></div>
				<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( (string) ( $id > 0 ? $id : '' ) ); ?>" data-oc-img-input>
				<button type="button" class="button" data-oc-img-pick><?php esc_html_e( 'Choose image', 'oc-theme' ); ?></button>
				<button type="button" class="button-link oc-cat-img__clear" data-oc-img-clear style="margin-inline-start:8px;<?php echo $id > 0 ? '' : 'display:none'; ?>"><?php esc_html_e( 'Remove', 'oc-theme' ); ?></button>
				<?php if ( '' !== $hint ) : ?>
					<p class="description"><?php echo esc_html( $hint ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * A select row.
	 *
	 * @param string                $name    Field name.
	 * @param string                $current Current value.
	 * @param string                $label   Label.
	 * @param array<string,string>  $choices Options.
	 * @param string                $hint    Hint.
	 * @param string                $show_if data-attribute gate "field:value|value".
	 */
	private function select_field( string $name, string $current, string $label, array $choices, string $hint = '', string $show_if = '' ): void {
		$gate = '' !== $show_if ? ' data-oc-when="' . esc_attr( $show_if ) . '"' : '';
		?>
		<tr class="form-field"<?php echo $gate; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- literal above. ?>>
			<th scope="row"><label for="<?php echo esc_attr( $name ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td>
				<select name="<?php echo esc_attr( $name ); ?>" id="<?php echo esc_attr( $name ); ?>" data-oc-field>
					<?php foreach ( $choices as $value => $text ) : ?>
						<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $current, $value ); ?>><?php echo esc_html( $text ); ?></option>
					<?php endforeach; ?>
				</select>
				<?php if ( '' !== $hint ) : ?>
					<p class="description"><?php echo esc_html( $hint ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * The category-page fields on the edit screen.
	 *
	 * @param \WP_Term $term Category.
	 */
	public function fields( $term ): void {
		if ( ! $term instanceof \WP_Term ) {
			return;
		}

		$h = self::hero( $term->term_id );
		?>
		<tr class="form-field">
			<th scope="row" colspan="2" style="padding-block-end:0">
				<h2 style="margin:22px 0 0;font-size:1.15em"><?php esc_html_e( 'Category page — hero', 'oc-theme' ); ?></h2>
				<p class="description" style="font-weight:400"><?php esc_html_e( 'A banner at the top of this category. The category name and description move onto it. Leave the layout on “None” to keep the plain title.', 'oc-theme' ); ?></p>
			</th>
		</tr>
		<?php
		$this->select_field(
			'_oc_hero_layout',
			$h['layout'],
			__( 'Layout', 'oc-theme' ),
			array(
				''      => __( 'None — plain title', 'oc-theme' ),
				'full'  => __( 'Full-width image', 'oc-theme' ),
				'split' => __( 'Half image · half content', 'oc-theme' ),
			)
		);

		$this->image_field( '_oc_hero_img', $h['img'], __( 'Hero image — desktop', 'oc-theme' ), __( 'Shown on the category page.', 'oc-theme' ), '_oc_hero_layout:full|split' );
		$this->image_field( '_oc_hero_img_m', $h['imgm'], __( 'Hero image — mobile (optional)', 'oc-theme' ), __( 'Used on phones. If empty, the desktop image is used.', 'oc-theme' ), '_oc_hero_layout:full|split' );

		// Heights.
		?>
		<tr class="form-field" data-oc-when="_oc_hero_layout:full|split">
			<th scope="row"><label><?php esc_html_e( 'Height', 'oc-theme' ); ?></label></th>
			<td>
				<label style="display:inline-block;min-inline-size:90px"><?php esc_html_e( 'Desktop', 'oc-theme' ); ?></label>
				<input type="number" min="0" max="1200" name="_oc_hero_h" value="<?php echo esc_attr( (string) ( $h['h'] > 0 ? $h['h'] : '' ) ); ?>" placeholder="<?php echo esc_attr( 'split' === $h['layout'] ? '440' : '420' ); ?>" style="inline-size:90px"> px<br>
				<label style="display:inline-block;min-inline-size:90px;margin-block-start:6px"><?php esc_html_e( 'Mobile', 'oc-theme' ); ?></label>
				<input type="number" min="0" max="1200" name="_oc_hero_hm" value="<?php echo esc_attr( (string) ( $h['hm'] > 0 ? $h['hm'] : '' ) ); ?>" placeholder="360" style="inline-size:90px"> px
				<p class="description"><?php esc_html_e( 'Leave empty for the automatic height.', 'oc-theme' ); ?></p>
			</td>
		</tr>
		<?php
		// Full-width options.
		$this->select_field(
			'_oc_hero_text',
			$h['text'],
			__( 'Text', 'oc-theme' ),
			array(
				'over'  => __( 'Over the image', 'oc-theme' ),
				'below' => __( 'Below the image', 'oc-theme' ),
			),
			'',
			'_oc_hero_layout:full'
		);

		$this->select_field(
			'_oc_hero_pos',
			$h['pos'],
			__( 'Text position', 'oc-theme' ),
			self::positions(),
			'',
			'_oc_hero_layout:full'
		);

		$this->select_field(
			'_oc_hero_tone',
			$h['tone'],
			__( 'Text colour', 'oc-theme' ),
			array(
				'light' => __( 'Light (for a dark image)', 'oc-theme' ),
				'dark'  => __( 'Dark (for a light image)', 'oc-theme' ),
			),
			'',
			'_oc_hero_layout:full'
		);
		?>
		<tr class="form-field" data-oc-when="_oc_hero_layout:full">
			<th scope="row"><label for="_oc_hero_shade"><?php esc_html_e( 'Darken image', 'oc-theme' ); ?></label></th>
			<td>
				<input type="number" min="0" max="90" step="5" name="_oc_hero_shade" id="_oc_hero_shade" value="<?php echo esc_attr( (string) $h['shade'] ); ?>" style="inline-size:80px"> %
				<p class="description"><?php esc_html_e( 'A dark veil over the image so light text stays readable. 0 = off.', 'oc-theme' ); ?></p>
			</td>
		</tr>
		<?php
		// Split options.
		$this->select_field(
			'_oc_hero_side',
			$h['side'],
			__( 'Image side', 'oc-theme' ),
			array(
				'start' => __( 'Reading side (right in Hebrew)', 'oc-theme' ),
				'end'   => __( 'Opposite side', 'oc-theme' ),
			),
			'',
			'_oc_hero_layout:split'
		);
		?>
		<tr class="form-field" data-oc-when="_oc_hero_layout:split">
			<th scope="row"><label for="_oc_hero_cbg"><?php esc_html_e( 'Content background', 'oc-theme' ); ?></label></th>
			<td>
				<input type="text" name="_oc_hero_cbg" id="_oc_hero_cbg" value="<?php echo esc_attr( $h['cbg'] ); ?>" placeholder="#f4f1ec" class="ltr" style="inline-size:140px">
				<p class="description"><?php esc_html_e( 'Background colour behind the content half. Leave empty for the page background.', 'oc-theme' ); ?></p>
			</td>
		</tr>
		<?php
		$this->admin_script();
	}

	/**
	 * The image picker + conditional-field JS for the edit screen.
	 */
	private function admin_script(): void {
		?>
		<script>
		( function ( $ ) {
			// Image pickers.
			$( document ).on( 'click', '[data-oc-img-pick]', function ( e ) {
				e.preventDefault();
				var $row = $( this ).closest( '[data-oc-imgfield]' );
				var frame = wp.media( { title: <?php echo wp_json_encode( __( 'Choose image', 'oc-theme' ) ); ?>, multiple: false, library: { type: 'image' } } );
				frame.on( 'select', function () {
					var a = frame.state().get( 'selection' ).first().toJSON();
					var u = ( a.sizes && a.sizes.thumbnail ) ? a.sizes.thumbnail.url : a.url;
					$row.find( '[data-oc-img-input]' ).val( a.id );
					$row.find( '.oc-cat-img__view' ).html( '<img src="' + u + '" style="display:block;max-inline-size:120px;height:auto;border-radius:6px">' );
					$row.find( '[data-oc-img-clear]' ).show();
				} );
				frame.open();
			} );
			$( document ).on( 'click', '[data-oc-img-clear]', function ( e ) {
				e.preventDefault();
				var $row = $( this ).closest( '[data-oc-imgfield]' );
				$row.find( '[data-oc-img-input]' ).val( '' );
				$row.find( '.oc-cat-img__view' ).empty();
				$( this ).hide();
			} );

			// Conditional rows: show only when _oc_hero_layout matches.
			function sync() {
				var layout = $( 'select[name="_oc_hero_layout"]' ).val() || '';
				$( '[data-oc-when]' ).each( function () {
					var spec = $( this ).data( 'oc-when' ).toString().split( ':' );
					var field = spec[ 0 ], vals = ( spec[ 1 ] || '' ).split( '|' );
					var on = ( field === '_oc_hero_layout' ) && vals.indexOf( layout ) !== -1;
					$( this ).toggle( on );
				} );
			}
			$( document ).on( 'change', 'select[name="_oc_hero_layout"]', sync );
			sync();
		} )( jQuery );
		</script>
		<?php
	}

	/* ------------------------------------------------------------------ *
	 *  Save
	 * ------------------------------------------------------------------ */

	/**
	 * Save the category-page fields.
	 *
	 * @param int $term_id Term id.
	 */
	public function save( $term_id ): void {
		if ( ! current_user_can( 'manage_product_terms' ) && ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		// Core verifies the term-edit nonce before this fires.
		// phpcs:disable WordPress.Security.NonceVerification.Missing

		$this->save_enum( $term_id, '_oc_hero_layout', array( 'full', 'split' ) );
		$this->save_int( $term_id, '_oc_hero_img' );
		$this->save_int( $term_id, '_oc_hero_img_m' );
		$this->save_int( $term_id, '_oc_hero_h' );
		$this->save_int( $term_id, '_oc_hero_hm' );
		$this->save_enum( $term_id, '_oc_hero_text', array( 'over', 'below' ) );
		$this->save_enum( $term_id, '_oc_hero_pos', array_keys( self::positions() ) );
		$this->save_enum( $term_id, '_oc_hero_tone', array( 'light', 'dark' ) );
		$this->save_int( $term_id, '_oc_hero_shade', 0, 90 );
		$this->save_enum( $term_id, '_oc_hero_side', array( 'start', 'end' ) );
		$this->save_colour( $term_id, '_oc_hero_cbg' );

		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/**
	 * Save a whitelisted string, or delete when empty / off-list.
	 *
	 * @param int      $term_id Term id.
	 * @param string   $key     Meta key / POST key.
	 * @param string[] $allowed Allowed values.
	 */
	private function save_enum( int $term_id, string $key, array $allowed ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised below.
		$value = isset( $_POST[ $key ] ) ? sanitize_key( wp_unslash( $_POST[ $key ] ) ) : '';

		if ( in_array( $value, $allowed, true ) ) {
			update_term_meta( $term_id, $key, $value );
		} else {
			delete_term_meta( $term_id, $key );
		}
	}

	/**
	 * Save a non-negative integer, or delete when empty / zero.
	 *
	 * @param int $term_id Term id.
	 * @param string $key  Meta key / POST key.
	 * @param int    $min  Minimum kept value.
	 * @param int    $max  Maximum kept value.
	 */
	private function save_int( int $term_id, string $key, int $min = 1, int $max = 100000 ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$raw = isset( $_POST[ $key ] ) ? absint( wp_unslash( $_POST[ $key ] ) ) : 0;

		if ( $raw >= $min ) {
			update_term_meta( $term_id, $key, (string) min( $max, $raw ) );
		} else {
			delete_term_meta( $term_id, $key );
		}
	}

	/**
	 * Save a CSS colour string, or delete when empty.
	 *
	 * @param int    $term_id Term id.
	 * @param string $key     Meta key / POST key.
	 */
	private function save_colour( int $term_id, string $key ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised below.
		$raw = isset( $_POST[ $key ] ) ? trim( (string) wp_unslash( $_POST[ $key ] ) ) : '';
		$ok  = preg_match( '/^#([0-9a-f]{3}|[0-9a-f]{6})$|^(rgb|rgba|hsl|hsla)\([0-9.,%\s\/]+\)$/i', $raw );

		if ( $ok ) {
			update_term_meta( $term_id, $key, $raw );
		} else {
			delete_term_meta( $term_id, $key );
		}
	}
}
