<?php
/**
 * Front-end assets.
 *
 * The old theme enqueued 29 separate JavaScript files on every page — product,
 * checkout, wishlist and variation scripts loaded on the blog — each with a
 * time() version so nothing could be cached, plus Slick from cdnjs, Swiper
 * from jsDelivr and Flickity locally. Three carousel libraries and jQuery.
 *
 * Here: no jQuery, no carousel library, and per-block assets are registered by
 * block.json so they load only where the block appears.
 *
 * @package OC_Theme
 */

declare( strict_types = 1 );

namespace OC\Theme;

defined( 'ABSPATH' ) || exit;

/**
 * Registers and enqueues theme assets.
 */
final class Assets {

	/**
	 * Hook into WordPress.
	 */
	public function register(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'front_end' ) );
		add_action( 'wp_head', array( $this, 'design_tokens' ), 1 );
	}

	/**
	 * Enqueue the front-end bundle.
	 */
	public function front_end(): void {
		$this->fonts();

		wp_enqueue_style(
			'oc-theme',
			OC_THEME_URI . '/assets/css/theme.css',
			array(),
			oc_asset_version( '/assets/css/theme.css' )
		);

		wp_enqueue_script(
			'oc-theme',
			OC_THEME_URI . '/assets/js/theme.js',
			array(),
			oc_asset_version( '/assets/js/theme.js' ),
			array(
				'strategy'  => 'defer',
				'in_footer' => true,
			)
		);

		wp_localize_script(
			'oc-theme',
			'ocL10n',
			array(
				'addToCart' => __( 'Add to cart', 'oc-theme' ),
			)
		);
	}

	/**
	 * Emit design tokens as custom properties.
	 *
	 * One small block of CSS derived from a handful of settings, replacing the
	 * 7,460-line inline stylesheet the old theme rebuilt on every request from
	 * 613 get_theme_mod() calls.
	 */
	public function design_tokens(): void {
		$display = (string) get_theme_mod( 'oc_font_display', '' );
		$body    = (string) get_theme_mod( 'oc_font_body', '' );

		$tokens = apply_filters(
			'oc_design_tokens',
			array(
				'--oc-font-body'      => '' !== $body ? '"' . $body . '", system-ui, sans-serif' : 'system-ui, sans-serif',
				'--oc-font-display'   => '' !== $display ? '"' . $display . '", system-ui, sans-serif' : 'inherit',
				'--oc-radius'         => get_theme_mod( 'oc_radius', '8px' ),
				'--oc-density'        => get_theme_mod( 'oc_density', '1' ),
				'--oc-content-width'  => absint( get_theme_mod( 'oc_content_width_px', 1280 ) ) . 'px',
				'--oc-card-ratio'     => (string) get_theme_mod( 'oc_card_ratio', '1/1' ),
				'--oc-thumbs-w'       => absint( get_theme_mod( 'oc_gallery_thumb_size', 80 ) ) . 'px',
				'--oc-gimg-h'         => absint( get_theme_mod( 'oc_gallery_img_height_px', 600 ) ) . 'px',
				'--oc-primary-user'   => (string) get_theme_mod( 'oc_color_primary', '' ),
				'--oc-secondary-user' => (string) get_theme_mod( 'oc_color_secondary', '' ),
				'--oc-bg-user'        => (string) get_theme_mod( 'oc_bg_color', '' ),
				'--oc-grid-gap'       => (string) get_theme_mod( 'oc_card_gap', '' ),
			)
		);

		$css = '';
		foreach ( $tokens as $name => $value ) {
			if ( ! is_string( $value ) || '' === $value ) {
				continue;
			}
			$css .= sprintf( '%s:%s;', $this->safe_property( $name ), $this->safe_value( $value ) );
		}

		$css .= $this->context_overrides();

		if ( '' === $css ) {
			return;
		}

		// Values passed through safe_property()/safe_value(), which strip
		// anything that could escape the declaration. esc_html() here would
		// turn font-name quotes into &quot; and void the declarations — the
		// bug that made the font settings appear dead.
		echo "<style id='oc-tokens'>:root{" . $css . "}</style>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Page-scoped overrides: catalogue, product and checkout pages may carry
	 * their own width and background, beating the global values.
	 *
	 * @return string Extra declarations, possibly empty.
	 */
	private function context_overrides(): string {
		$width = 0;
		$bg    = '';

		if ( function_exists( 'is_product' ) && is_product() ) {
			$width = absint( get_theme_mod( 'oc_product_width_px', 0 ) );
			$bg    = (string) get_theme_mod( 'oc_product_bg', '' );
		} elseif ( function_exists( 'is_shop' ) && ( is_shop() || is_product_taxonomy() ) ) {
			$width = absint( get_theme_mod( 'oc_catalog_width_px', 0 ) );
			$bg    = (string) get_theme_mod( 'oc_catalog_bg', '' );
		} elseif ( function_exists( 'is_checkout' ) && ( is_checkout() || is_cart() ) ) {
			$bg = (string) get_theme_mod( 'oc_checkout_bg', '' );
		}

		$out = '';
		if ( $width > 0 ) {
			$out .= '--oc-content-width:' . $width . 'px;';
		}
		if ( '' !== $bg ) {
			$out .= '--oc-bg-user:' . $this->safe_value( $bg ) . ';';
		}

		return $out;
	}

	/**
	 * Load the chosen Google fonts in one request. Hebrew subsets included.
	 */
	private function fonts(): void {
		$families = array_filter(
			array_unique(
				array(
					(string) get_theme_mod( 'oc_font_display', '' ),
					(string) get_theme_mod( 'oc_font_body', '' ),
				)
			)
		);

		if ( empty( $families ) ) {
			return;
		}

		$parts = array();
		foreach ( $families as $family ) {
			$parts[] = 'family=' . rawurlencode( $family ) . ':wght@400;600;700';
		}

		wp_enqueue_style(
			'oc-fonts',
			'https://fonts.googleapis.com/css2?' . implode( '&', $parts ) . '&display=swap',
			array(),
			null // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- the URL is the version.
		);
	}

	/**
	 * Allow only custom-property names.
	 *
	 * @param string $name Property name.
	 * @return string
	 */
	private function safe_property( string $name ): string {
		return (string) preg_replace( '/[^a-z0-9\-]/i', '', $name );
	}

	/**
	 * Strip anything that could break out of a declaration.
	 *
	 * @param string $value Property value.
	 * @return string
	 */
	private function safe_value( string $value ): string {
		return trim( (string) preg_replace( '/[<>{};]/', '', $value ) );
	}
}
