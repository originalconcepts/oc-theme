<?php
/**
 * A brand's own page: its banner, its logo beside or above its name, its
 * description under the title or under the products.
 *
 * The look is decided once, in Customize → Catalogue page → Brand page;
 * each brand brings its own pictures on its edit screen — the logo it
 * already had (WooCommerce's brand picture) and a banner for desktop and,
 * if it wants one, for phones.
 *
 * @package OC_Theme
 */

declare( strict_types = 1 );

namespace OC\Theme;

defined( 'ABSPATH' ) || exit;

/**
 * Brand archive pages.
 */
final class Brand_Page {

	/**
	 * Hook in.
	 */
	public function register(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		// The taxonomy is known only once every plugin has registered its own.
		add_action( 'init', array( $this, 'admin_hooks' ), 40 );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_assets' ) );
		// After WooCommerce::runtime_hooks (priority 10).
		add_action( 'wp', array( $this, 'setup' ), 11 );
	}

	/**
	 * The brand taxonomy in use, or '' when the shop has none.
	 */
	public static function taxonomy(): string {
		return class_exists( '\OC\Theme\Search' ) ? Search::brand_taxonomy() : '';
	}

	/**
	 * Is this a brand's page?
	 */
	public static function is_brand(): bool {
		$tax = self::taxonomy();

		return '' !== $tax && is_tax( $tax );
	}

	/**
	 * Where the description goes on a brand page: the brand's own choice,
	 * else the Customize default.
	 *
	 * @param int $term_id Brand.
	 */
	public static function desc_pos( int $term_id ): string {
		$own = (string) get_term_meta( $term_id, '_oc_desc_pos', true );

		if ( in_array( $own, array( 'top', 'bottom' ), true ) ) {
			return $own;
		}

		return 'bottom' === get_theme_mod( 'oc_brand_desc_pos', 'top' ) ? 'bottom' : 'top';
	}

	/**
	 * Text alignment on the brand page: its own, or the catalogue's.
	 */
	public static function align(): string {
		$own = (string) get_theme_mod( 'oc_brand_align', 'inherit' );

		if ( in_array( $own, array( 'start', 'center' ), true ) ) {
			return $own;
		}

		return 'center' === get_theme_mod( 'oc_catalog_title_align', 'start' ) ? 'center' : 'start';
	}

	/* ---------------------------------------------------------- front */

	/**
	 * Wire the page.
	 */
	public function setup(): void {
		if ( ! self::is_brand() ) {
			return;
		}

		$term = get_queried_object();

		if ( ! $term instanceof \WP_Term ) {
			return;
		}

		$pos    = self::desc_pos( $term->term_id );
		$align  = self::align();
		$banner = (int) get_term_meta( $term->term_id, '_oc_brand_banner', true );

		// The header below carries the title and, when it sits at the top,
		// the description — so WooCommerce's own step aside. The catalogue's
		// "description under the products" rule is for categories; a brand
		// page decides for itself.
		add_filter( 'woocommerce_show_page_title', '__return_false' );
		remove_action( 'woocommerce_archive_description', 'woocommerce_taxonomy_archive_description', 10 );
		remove_action( 'woocommerce_archive_description', 'woocommerce_product_archive_description', 10 );
		remove_action( 'woocommerce_after_main_content', 'woocommerce_taxonomy_archive_description', 5 );
		remove_action( 'woocommerce_after_main_content', 'woocommerce_product_archive_description', 5 );

		add_filter(
			'body_class',
			static function ( array $classes ) use ( $align ): array {
				$classes = array_values( array_diff( $classes, array( 'oc-title-center' ) ) );

				if ( 'center' === $align ) {
					$classes[] = 'oc-title-center';
				}

				$classes[] = 'oc-brand-page';

				return $classes;
			}
		);

		if ( $banner > 0 ) {
			// Full-bleed, before the constrained <main> opens (priority 10).
			add_action(
				'woocommerce_before_main_content',
				function () use ( $term ): void {
					echo self::banner( $term ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
				},
				5
			);
		}

		add_action(
			'woocommerce_archive_description',
			function () use ( $term, $pos, $align ): void {
				echo self::header( $term, 'top' === $pos, $align ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
			},
			8
		);

		if ( 'bottom' === $pos ) {
			add_action(
				'woocommerce_after_main_content',
				function () use ( $term ): void {
					echo self::description( $term, 'oc-archive-desc oc-archive-desc--bottom' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
				},
				5
			);
		}
	}

	/**
	 * The banner: the brand's desktop picture, its phone picture where it
	 * has one, whole or cut to the height Customize set.
	 *
	 * @param \WP_Term $term Brand.
	 */
	private static function banner( \WP_Term $term ): string {
		$img  = (int) get_term_meta( $term->term_id, '_oc_brand_banner', true );
		$imgm = (int) get_term_meta( $term->term_id, '_oc_brand_banner_m', true );
		$crop = 'crop' === get_theme_mod( 'oc_brand_banner_fit', 'whole' );

		$picture = wp_get_attachment_image(
			$img,
			'full',
			false,
			array(
				'class'         => 'oc-bbanner__img',
				'alt'           => $term->name,
				'loading'       => 'eager',
				'fetchpriority' => 'high',
				'decoding'      => 'async',
			)
		);

		if ( '' === $picture ) {
			return '';
		}

		if ( $imgm > 0 && $imgm !== $img ) {
			$src_m = wp_get_attachment_image_url( $imgm, 'full' );

			if ( $src_m ) {
				$picture = '<picture><source media="(max-width: 700px)" srcset="' . esc_url( $src_m ) . '">' . $picture . '</picture>';
			}
		}

		$style = '';

		if ( $crop ) {
			$style = ' style="--bb-h:' . max( 100, (int) get_theme_mod( 'oc_brand_banner_h', 360 ) ) . 'px;--bb-hm:' . max( 80, (int) get_theme_mod( 'oc_brand_banner_hm', 220 ) ) . 'px"';
		}

		return '<div class="oc-bbanner' . ( $crop ? ' oc-bbanner--crop' : '' ) . '"' . $style . '>' . $picture . '</div>';
	}

	/**
	 * Logo and name, with the description when it belongs at the top.
	 *
	 * @param \WP_Term $term      Brand.
	 * @param bool     $with_desc Description under the title.
	 * @param string   $align     start|center.
	 */
	private static function header( \WP_Term $term, bool $with_desc, string $align ): string {
		$where = (string) get_theme_mod( 'oc_brand_logo_pos', 'above' );
		$logo  = '';

		if ( 'hidden' !== $where ) {
			$thumb = (int) get_term_meta( $term->term_id, 'thumbnail_id', true );

			if ( $thumb > 0 ) {
				$logo = wp_get_attachment_image(
					$thumb,
					'medium',
					false,
					array(
						'class'   => 'oc-bhead__logo-img',
						'alt'     => $term->name,
						'loading' => 'eager',
					)
				);
			}
		}

		$classes = array( 'oc-bhead', 'oc-bhead--' . ( '' !== $logo && 'beside' === $where ? 'beside' : 'above' ), 'oc-bhead--' . $align );

		if ( '' === $logo ) {
			$classes[] = 'oc-bhead--nologo';
		}

		$out = '<header class="' . esc_attr( implode( ' ', $classes ) ) . '" style="--bh-logo:' . max( 24, (int) get_theme_mod( 'oc_brand_logo_h', 72 ) ) . 'px">';

		if ( '' !== $logo ) {
			$out .= '<div class="oc-bhead__logo">' . $logo . '</div>';
		}

		$out .= '<div class="oc-bhead__words"><h1 class="oc-bhead__title page-title">' . esc_html( $term->name ) . '</h1>';

		if ( $with_desc ) {
			$out .= self::description( $term, 'oc-archive-desc' );
		}

		$out .= '</div></header>';

		return $out;
	}

	/**
	 * The description, or '' when the brand has none.
	 *
	 * @param \WP_Term $term  Brand.
	 * @param string   $class Classes on the box.
	 */
	private static function description( \WP_Term $term, string $class ): string {
		$desc = trim( (string) term_description( $term->term_id ) );

		if ( '' === $desc ) {
			return '';
		}

		return '<div class="' . esc_attr( $class ) . '">' . wp_kses_post( $desc ) . '</div>';
	}

	/* ---------------------------------------------------------- admin */

	/**
	 * The brand's edit screen: description placement right under the
	 * description, the banner pictures further down.
	 */
	public function admin_hooks(): void {
		$tax = self::taxonomy();

		if ( '' === $tax ) {
			return;
		}

		add_action( $tax . '_edit_form_fields', array( $this, 'pos_field' ), 5 );
		add_action( $tax . '_edit_form_fields', array( $this, 'banner_fields' ), 20 );
		add_action( 'edited_' . $tax, array( $this, 'save' ) );
	}

	/**
	 * Load wp.media on the brand edit screen.
	 *
	 * @param string $hook Current admin page.
	 */
	public function admin_assets( string $hook ): void {
		if ( 'term.php' !== $hook ) {
			return;
		}

		$screen = get_current_screen();

		if ( $screen && '' !== self::taxonomy() && $screen->taxonomy === self::taxonomy() ) {
			wp_enqueue_media();
		}
	}

	/**
	 * Description under the title or under the products — this brand's own
	 * choice, or Customize's.
	 *
	 * @param \WP_Term $term The brand.
	 */
	public function pos_field( $term ): void {
		if ( ! $term instanceof \WP_Term ) {
			return;
		}

		$current = (string) get_term_meta( $term->term_id, '_oc_desc_pos', true );
		$default = 'bottom' === get_theme_mod( 'oc_brand_desc_pos', 'top' ) ? __( 'Under the products', 'oc-theme' ) : __( 'Under the title', 'oc-theme' );

		self::pos_row( $current, $default );
	}

	/**
	 * The description-placement row, shared with the category screen.
	 *
	 * @param string $current The term's own value, '' = as in Customize.
	 * @param string $default What Customize says, for the first option.
	 */
	public static function pos_row( string $current, string $default ): void {
		?>
		<tr class="form-field">
			<th scope="row"><label for="_oc_desc_pos"><?php esc_html_e( 'Description position', 'oc-theme' ); ?></label></th>
			<td>
				<select name="_oc_desc_pos" id="_oc_desc_pos">
					<option value="" <?php selected( '', $current ); ?>>
						<?php
						/* translators: %s: the value Customize holds. */
						echo esc_html( sprintf( __( 'As in Customize (%s)', 'oc-theme' ), $default ) );
						?>
					</option>
					<option value="top" <?php selected( 'top', $current ); ?>><?php esc_html_e( 'Under the title', 'oc-theme' ); ?></option>
					<option value="bottom" <?php selected( 'bottom', $current ); ?>><?php esc_html_e( 'Under the products', 'oc-theme' ); ?></option>
				</select>
				<p class="description"><?php esc_html_e( 'Text meant for search engines usually goes under the products, where it does not push them down.', 'oc-theme' ); ?></p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Banner pictures.
	 *
	 * @param \WP_Term $term The brand.
	 */
	public function banner_fields( $term ): void {
		if ( ! $term instanceof \WP_Term ) {
			return;
		}

		$fit = 'crop' === get_theme_mod( 'oc_brand_banner_fit', 'whole' )
			/* translators: 1: desktop height, 2: phone height. */
			? sprintf( __( 'Cut to %1$dpx on desktop and %2$dpx on phones, as set in Customize → Brand page.', 'oc-theme' ), (int) get_theme_mod( 'oc_brand_banner_h', 360 ), (int) get_theme_mod( 'oc_brand_banner_hm', 220 ) )
			: __( 'Shown whole, at its own height, as set in Customize → Brand page.', 'oc-theme' );
		?>
		<tr class="form-field oc-cat-sec">
			<th scope="row" colspan="2" style="padding-block-end:0"><h2 style="margin:0"><?php esc_html_e( 'Brand page — banner', 'oc-theme' ); ?></h2>
				<p class="description" style="font-weight:400"><?php echo esc_html( $fit ); ?></p></th>
		</tr>
		<?php
		self::image_row( '_oc_brand_banner', (int) get_term_meta( $term->term_id, '_oc_brand_banner', true ), __( 'Banner — desktop', 'oc-theme' ), __( 'Above everything on the brand page, edge to edge.', 'oc-theme' ) );
		self::image_row( '_oc_brand_banner_m', (int) get_term_meta( $term->term_id, '_oc_brand_banner_m', true ), __( 'Banner — phone', 'oc-theme' ), __( 'Optional. Without it, phones show the desktop picture.', 'oc-theme' ) );
		self::admin_script();
	}

	/**
	 * An image-picker row.
	 *
	 * @param string $name  Field name.
	 * @param int    $id    Current attachment.
	 * @param string $label Label.
	 * @param string $hint  Hint.
	 */
	private static function image_row( string $name, int $id, string $label, string $hint ): void {
		$preview = $id > 0 ? wp_get_attachment_image( $id, 'medium', false, array( 'style' => 'display:block;max-inline-size:260px;height:auto;border-radius:6px' ) ) : '';
		?>
		<tr class="form-field" data-oc-imgfield>
			<th scope="row"><label><?php echo esc_html( $label ); ?></label></th>
			<td>
				<div class="oc-cat-img__view" style="margin-block-end:8px"><?php echo $preview; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_get_attachment_image() is safe. ?></div>
				<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( (string) ( $id > 0 ? $id : '' ) ); ?>" data-oc-img-input>
				<button type="button" class="button" data-oc-img-pick><?php esc_html_e( 'Choose image', 'oc-theme' ); ?></button>
				<button type="button" class="button-link" data-oc-img-clear style="margin-inline-start:8px;<?php echo $id > 0 ? '' : 'display:none'; ?>"><?php esc_html_e( 'Remove', 'oc-theme' ); ?></button>
				<p class="description"><?php echo esc_html( $hint ); ?></p>
			</td>
		</tr>
		<?php
	}

	/**
	 * The picker, once per screen.
	 */
	private static function admin_script(): void {
		static $done = false;

		if ( $done ) {
			return;
		}

		$done = true;
		?>
		<style>.oc-cat-sec th { border-block-start: 1px solid #dcdcde; padding-block-start: 14px; }</style>
		<script>
		( function ( $ ) {
			$( document ).on( 'click', '[data-oc-img-pick]', function ( e ) {
				e.preventDefault();
				var $row = $( this ).closest( '[data-oc-imgfield]' );
				var frame = wp.media( { title: <?php echo wp_json_encode( __( 'Choose image', 'oc-theme' ) ); ?>, multiple: false, library: { type: 'image' } } );
				frame.on( 'select', function () {
					var a = frame.state().get( 'selection' ).first().toJSON();
					var u = ( a.sizes && a.sizes.medium ) ? a.sizes.medium.url : a.url;
					$row.find( '[data-oc-img-input]' ).val( a.id );
					$row.find( '.oc-cat-img__view' ).html( '<img src="' + u + '" style="display:block;max-inline-size:260px;height:auto;border-radius:6px">' );
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
		}( jQuery ) );
		</script>
		<?php
	}

	/**
	 * Persist the brand's fields.
	 *
	 * @param int $term_id Brand.
	 */
	public function save( $term_id ): void {
		if ( ! current_user_can( 'manage_product_terms' ) && ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		// Core verifies the term-edit nonce before this fires.
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$term_id = (int) $term_id;
		$pos     = isset( $_POST['_oc_desc_pos'] ) ? sanitize_key( wp_unslash( $_POST['_oc_desc_pos'] ) ) : '';

		if ( in_array( $pos, array( 'top', 'bottom' ), true ) ) {
			update_term_meta( $term_id, '_oc_desc_pos', $pos );
		} elseif ( isset( $_POST['_oc_desc_pos'] ) ) {
			delete_term_meta( $term_id, '_oc_desc_pos' );
		}

		foreach ( array( '_oc_brand_banner', '_oc_brand_banner_m' ) as $key ) {
			if ( ! isset( $_POST[ $key ] ) ) {
				continue;
			}

			$id = absint( wp_unslash( $_POST[ $key ] ) );

			if ( $id > 0 ) {
				update_term_meta( $term_id, $key, (string) $id );
			} else {
				delete_term_meta( $term_id, $key );
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}
}
