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
	}

	/**
	 * Emit design tokens as custom properties.
	 *
	 * One small block of CSS derived from a handful of settings, replacing the
	 * 7,460-line inline stylesheet the old theme rebuilt on every request from
	 * 613 get_theme_mod() calls.
	 */
	public function design_tokens(): void {
		$tokens = apply_filters(
			'oc_design_tokens',
			array(
				'--oc-font-body'      => get_theme_mod( 'oc_font_body', 'system-ui, sans-serif' ),
				'--oc-font-display'   => get_theme_mod( 'oc_font_display', 'system-ui, sans-serif' ),
				'--oc-radius'         => get_theme_mod( 'oc_radius', '8px' ),
				'--oc-density'        => get_theme_mod( 'oc_density', '1' ),
				'--oc-content-width'  => get_theme_mod( 'oc_content_width', '1280px' ),
			)
		);

		$css = '';
		foreach ( $tokens as $name => $value ) {
			if ( ! is_string( $value ) || '' === $value ) {
				continue;
			}
			$css .= sprintf( '%s:%s;', $this->safe_property( $name ), $this->safe_value( $value ) );
		}

		if ( '' === $css ) {
			return;
		}

		printf(
			"<style id='oc-tokens'>:root{%s}</style>\n",
			esc_html( $css )
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
