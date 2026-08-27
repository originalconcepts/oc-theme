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

		// Core blog categories get the shared card image only.
		add_action( 'category_edit_form_fields', array( $this, 'category_fields' ), 20 );
		add_action( 'edited_category', array( $this, 'category_save' ) );

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

	/**
	 * Read a term's sub-category-strip settings.
	 *
	 * @param int $term_id Term id.
	 * @return array<string,mixed>
	 */
	private static function subs( int $term_id ): array {
		$get = static function ( string $key, string $def = '' ) use ( $term_id ): string {
			$v = get_term_meta( $term_id, $key, true );

			return '' !== (string) $v ? (string) $v : $def;
		};

		return array(
			'show'    => '1' === $get( '_oc_sub_show' ),
			'style'   => $get( '_oc_sub_style', 'clean' ),      // clean | pill | card.
			'pill'    => $get( '_oc_sub_pill', 'round' ),        // round | rect.
			'shape'   => $get( '_oc_sub_shape', 'square' ),      // square | portrait | circle.
			'corners' => $get( '_oc_sub_corners', 'soft' ),      // sharp | soft.
			'place'   => $get( '_oc_sub_place', 'out' ),         // out | in.
			'align'   => $get( '_oc_sub_align', 'start' ),       // start | center.
		);
	}

	/**
	 * The queried category's direct children.
	 *
	 * @param int $term_id Parent id.
	 * @return \WP_Term[]
	 */
	private static function children( int $term_id ): array {
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'parent'     => $term_id,
				'hide_empty' => false,
				'orderby'    => 'menu_order',
			)
		);

		return is_array( $terms ) ? array_filter( $terms, static fn( $t ) => $t instanceof \WP_Term ) : array();
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

		$h       = self::hero( $term->term_id );
		$sub     = self::subs( $term->term_id );
		$hero_on = self::has_hero( $h );

		if ( $hero_on ) {
			// The hero carries the H1 and the description, so hide Woo's own.
			add_filter( 'woocommerce_show_page_title', '__return_false' );
			remove_action( 'woocommerce_archive_description', 'woocommerce_taxonomy_archive_description', 10 );
			remove_action( 'woocommerce_archive_description', 'woocommerce_product_archive_description', 10 );

			// Full-bleed: render before the <main> wrapper opens (open_wrapper
			// is priority 10 on the same hook).
			add_action(
				'woocommerce_before_main_content',
				function () use ( $term, $h, $sub ): void {
					echo self::render( $term, $h, $sub ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
				},
				5
			);
		}

		// Sub-categories can sit inside an over-image hero; otherwise they go
		// below the description in the normal content flow.
		$place_in = $hero_on && 'full' === $h['layout'] && 'over' === $h['text'] && 'in' === $sub['place'];

		if ( $sub['show'] && ! $place_in ) {
			$align = 'center' === $sub['align'] ? 'center' : 'start';

			add_action(
				'woocommerce_archive_description',
				function () use ( $term, $sub, $align ): void {
					echo self::subcats_html( $term, $sub, 'out', $align ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
				},
				15
			);
		}
	}

	/**
	 * Build the hero markup.
	 *
	 * @param \WP_Term            $term Category.
	 * @param array<string,mixed> $h    Hero settings.
	 * @param array<string,mixed> $sub  Sub-category settings.
	 * @return string
	 */
	private static function render( \WP_Term $term, array $h, array $sub = array() ): string {
		$title = '<h1 class="oc-chero__title">' . esc_html( $term->name ) . '</h1>';
		$desc  = trim( (string) term_description( $term->term_id, 'product_cat' ) );
		$desc  = '' !== $desc ? '<div class="oc-chero__desc">' . wp_kses_post( $desc ) . '</div>' : '';

		// Sub-categories over the image: they follow the content's alignment.
		$subs = '';

		if ( ! empty( $sub['show'] ) && 'full' === $h['layout'] && 'over' === $h['text'] && 'in' === $sub['place'] ) {
			$align = in_array( $h['pos'], array( 'cc', 'bc' ), true ) ? 'center' : 'start';
			$subs  = self::subcats_html( $term, $sub, 'in', $align );
		}

		$words = '<div class="oc-chero__words"><div class="oc-chero__wordsin">' . $title . $desc . $subs . '</div></div>';

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

	/**
	 * The strip of sub-categories under a category.
	 *
	 * @param \WP_Term            $term    Parent category.
	 * @param array<string,mixed> $sub     Sub-category settings.
	 * @param string              $context 'in' (over the hero image) | 'out'.
	 * @param string              $align   'start' | 'center'.
	 * @return string
	 */
	private static function subcats_html( \WP_Term $term, array $sub, string $context, string $align ): string {
		$children = self::children( $term->term_id );

		if ( empty( $children ) ) {
			return '';
		}

		$style = in_array( $sub['style'], array( 'clean', 'pill', 'card' ), true ) ? $sub['style'] : 'clean';
		$items = '';

		foreach ( $children as $child ) {
			$link = get_term_link( $child );

			if ( is_wp_error( $link ) ) {
				continue;
			}

			$link = esc_url( (string) $link );
			$name = esc_html( $child->name );

			if ( 'card' === $style ) {
				$id  = self::card_image_id( $child->term_id );
				$img = $id > 0
					? wp_get_attachment_image( $id, 'medium', false, array( 'loading' => 'lazy', 'alt' => $child->name ) )
					: '';

				$items .= '<a class="oc-subcats__card" href="' . $link . '">'
					. '<span class="oc-subcats__pic">' . $img . '</span>'
					. '<span class="oc-subcats__name">' . $name . '</span>'
					. '</a>';
			} elseif ( 'pill' === $style ) {
				$items .= '<a class="oc-subcats__pill" href="' . $link . '">' . $name . '</a>';
			} else {
				$items .= '<a class="oc-subcats__link" href="' . $link . '">' . $name . '</a>';
			}
		}

		if ( '' === $items ) {
			return '';
		}

		$classes = 'oc-subcats oc-subcats--' . $style
			. ' oc-subcats--align-' . ( 'center' === $align ? 'center' : 'start' )
			. ' oc-subcats--' . ( 'in' === $context ? 'in' : 'out' );

		if ( 'pill' === $style ) {
			$classes .= ' oc-subcats--pill-' . ( 'rect' === $sub['pill'] ? 'rect' : 'round' );
		}

		if ( 'card' === $style ) {
			$shape    = in_array( $sub['shape'], array( 'square', 'portrait', 'circle' ), true ) ? $sub['shape'] : 'square';
			$classes .= ' oc-subcats--shape-' . $shape . ' oc-subcats--corners-' . ( 'sharp' === $sub['corners'] ? 'sharp' : 'soft' );
		}

		return '<nav class="' . esc_attr( $classes ) . '" aria-label="' . esc_attr__( 'Sub-categories', 'oc-theme' ) . '">' . $items . '</nav>';
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

		if ( $screen && in_array( $screen->taxonomy, array( 'product_cat', 'category' ), true ) ) {
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
	 * A checkbox row.
	 *
	 * @param string $name    Field name.
	 * @param bool   $checked Current state.
	 * @param string $label   Label.
	 * @param string $text    Text beside the checkbox.
	 * @param string $show_if data-attribute gate.
	 */
	private function toggle_field( string $name, bool $checked, string $label, string $text, string $show_if = '' ): void {
		$gate = '' !== $show_if ? ' data-oc-when="' . esc_attr( $show_if ) . '"' : '';
		?>
		<tr class="form-field"<?php echo $gate; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- literal above. ?>>
			<th scope="row"><label for="<?php echo esc_attr( $name ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td>
				<label>
					<input type="checkbox" name="<?php echo esc_attr( $name ); ?>" id="<?php echo esc_attr( $name ); ?>" value="1" <?php checked( $checked ); ?> data-oc-field>
					<?php echo esc_html( $text ); ?>
				</label>
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

		$h   = self::hero( $term->term_id );
		$sub = self::subs( $term->term_id );
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

		<tr class="form-field">
			<th scope="row" colspan="2" style="padding-block-end:0">
				<h2 style="margin:22px 0 0;font-size:1.15em"><?php esc_html_e( 'Card image', 'oc-theme' ); ?></h2>
				<p class="description" style="font-weight:400"><?php esc_html_e( 'One image for this category, used by the categories block, the sub-category strip and the blog. If empty, the hero image is used.', 'oc-theme' ); ?></p>
			</th>
		</tr>
		<?php
		$card = absint( get_term_meta( $term->term_id, '_oc_card_img', true ) );
		$this->image_field( '_oc_card_img', $card, __( 'Image', 'oc-theme' ) );
		?>

		<tr class="form-field">
			<th scope="row" colspan="2" style="padding-block-end:0">
				<h2 style="margin:22px 0 0;font-size:1.15em"><?php esc_html_e( 'Sub-categories', 'oc-theme' ); ?></h2>
				<p class="description" style="font-weight:400"><?php esc_html_e( 'Show this category’s sub-categories under the description (or over the hero image).', 'oc-theme' ); ?></p>
			</th>
		</tr>
		<?php
		$this->toggle_field( '_oc_sub_show', $sub['show'], __( 'Show sub-categories', 'oc-theme' ), __( 'Display a strip of the child categories.', 'oc-theme' ) );

		$this->select_field(
			'_oc_sub_style',
			$sub['style'],
			__( 'Display', 'oc-theme' ),
			array(
				'clean' => __( 'Clean — underlined links', 'oc-theme' ),
				'pill'  => __( 'Pills', 'oc-theme' ),
				'card'  => __( 'Image cards', 'oc-theme' ),
			),
			'',
			'_oc_sub_show:1'
		);

		$this->select_field(
			'_oc_sub_pill',
			$sub['pill'],
			__( 'Pill shape', 'oc-theme' ),
			array(
				'round' => __( 'Rounded (ellipse)', 'oc-theme' ),
				'rect'  => __( 'Rectangle', 'oc-theme' ),
			),
			'',
			'_oc_sub_show:1,_oc_sub_style:pill'
		);

		$this->select_field(
			'_oc_sub_shape',
			$sub['shape'],
			__( 'Image shape', 'oc-theme' ),
			array(
				'square'   => __( 'Square', 'oc-theme' ),
				'portrait' => __( 'Portrait', 'oc-theme' ),
				'circle'   => __( 'Circle', 'oc-theme' ),
			),
			'',
			'_oc_sub_show:1,_oc_sub_style:card'
		);

		$this->select_field(
			'_oc_sub_corners',
			$sub['corners'],
			__( 'Corners', 'oc-theme' ),
			array(
				'soft'  => __( 'Soft', 'oc-theme' ),
				'sharp' => __( 'Sharp', 'oc-theme' ),
			),
			'',
			'_oc_sub_show:1,_oc_sub_style:card'
		);

		$this->select_field(
			'_oc_sub_place',
			$sub['place'],
			__( 'Placement', 'oc-theme' ),
			array(
				'out' => __( 'Below the description', 'oc-theme' ),
				'in'  => __( 'Over the hero image (full-width, text-over only)', 'oc-theme' ),
			),
			__( 'Over the image, they follow the text’s alignment.', 'oc-theme' ),
			'_oc_sub_show:1'
		);

		$this->select_field(
			'_oc_sub_align',
			$sub['align'],
			__( 'Alignment', 'oc-theme' ),
			array(
				'start'  => __( 'Reading side', 'oc-theme' ),
				'center' => __( 'Centre', 'oc-theme' ),
			),
			'',
			'_oc_sub_show:1,_oc_sub_place:out'
		);

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

			// Conditional rows. data-oc-when="field:a|b,field2:c" — every
			// comma-separated clause must match (AND); values within a clause
			// are alternatives (OR).
			function fval( name ) {
				var el = document.querySelector( '[name="' + name + '"]' );
				if ( ! el ) { return ''; }
				if ( 'checkbox' === el.type ) { return el.checked ? '1' : ''; }
				return el.value || '';
			}
			function sync() {
				$( '[data-oc-when]' ).each( function () {
					var ok = true;
					$( this ).data( 'oc-when' ).toString().split( ',' ).forEach( function ( cond ) {
						var p = cond.split( ':' ), field = p[ 0 ], vals = ( p[ 1 ] || '' ).split( '|' );
						if ( vals.indexOf( fval( field ) ) === -1 ) { ok = false; }
					} );
					$( this ).toggle( ok );
				} );
			}
			$( document ).on( 'change', '[data-oc-field]', sync );
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

		$this->save_int( $term_id, '_oc_card_img' );

		$this->save_bool( $term_id, '_oc_sub_show' );
		$this->save_enum( $term_id, '_oc_sub_style', array( 'clean', 'pill', 'card' ) );
		$this->save_enum( $term_id, '_oc_sub_pill', array( 'round', 'rect' ) );
		$this->save_enum( $term_id, '_oc_sub_shape', array( 'square', 'portrait', 'circle' ) );
		$this->save_enum( $term_id, '_oc_sub_corners', array( 'sharp', 'soft' ) );
		$this->save_enum( $term_id, '_oc_sub_place', array( 'out', 'in' ) );
		$this->save_enum( $term_id, '_oc_sub_align', array( 'start', 'center' ) );

		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/**
	 * The card-image row on a core (blog) category edit screen.
	 *
	 * @param \WP_Term $term Category.
	 */
	public function category_fields( $term ): void {
		if ( ! $term instanceof \WP_Term ) {
			return;
		}

		$card = absint( get_term_meta( $term->term_id, '_oc_card_img', true ) );
		?>
		<tr class="form-field">
			<th scope="row" colspan="2" style="padding-block-end:0">
				<h2 style="margin:22px 0 0;font-size:1.15em"><?php esc_html_e( 'Card image', 'oc-theme' ); ?></h2>
				<p class="description" style="font-weight:400"><?php esc_html_e( 'Shown for this category on the blog and in category strips.', 'oc-theme' ); ?></p>
			</th>
		</tr>
		<?php
		$this->image_field( '_oc_card_img', $card, __( 'Image', 'oc-theme' ) );
		$this->admin_script();
	}

	/**
	 * Save a core-category card image.
	 *
	 * @param int $term_id Term id.
	 */
	public function category_save( $term_id ): void {
		if ( ! current_user_can( 'manage_categories' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- core verifies the term-edit nonce.
		$this->save_int( $term_id, '_oc_card_img' );
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
	 * Save a checkbox as '1', or delete when unchecked.
	 *
	 * @param int    $term_id Term id.
	 * @param string $key     Meta key / POST key.
	 */
	private function save_bool( int $term_id, string $key ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( isset( $_POST[ $key ] ) && '1' === (string) wp_unslash( $_POST[ $key ] ) ) {
			update_term_meta( $term_id, $key, '1' );
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
