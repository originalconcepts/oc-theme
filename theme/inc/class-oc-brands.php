<?php
/**
 * The brands page: every brand the shop carries, on one address.
 *
 * The taxonomy gives each brand its own archive but no front door to all of
 * them — this is that door, at /brands/, drawn from the Customizer's own
 * settings. No WordPress page to create or forget to keep.
 *
 * @package OC_Theme
 */

declare( strict_types = 1 );

namespace OC\Theme;

defined( 'ABSPATH' ) || exit;

/**
 * All the brands, standing together.
 */
final class Brands {

	/**
	 * The page's address.
	 */
	public static function url(): string {
		return home_url( '/brands/' );
	}

	/**
	 * Hook in.
	 */
	public function register(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		add_action( 'init', array( $this, 'route' ) );
		add_filter( 'query_vars', array( $this, 'vars' ) );
		add_action( 'template_redirect', array( $this, 'render' ) );
		add_filter( 'document_title_parts', array( $this, 'title' ) );
		add_filter( 'body_class', array( $this, 'body_class' ) );
		add_filter( 'woocommerce_get_breadcrumb', array( $this, 'crumbs' ) );
	}

	/**
	 * /brands/ answers, without a page existing behind it.
	 */
	public function route(): void {
		add_rewrite_rule( '^brands/?$', 'index.php?oc_brands=1', 'top' );

		if ( ! get_option( 'oc_brands_rw' ) ) {
			flush_rewrite_rules();
			update_option( 'oc_brands_rw', 1, false );
		}
	}

	/**
	 * The query var that carries the route.
	 *
	 * @param array<int,string> $vars Query vars.
	 * @return array<int,string>
	 */
	public function vars( array $vars ): array {
		$vars[] = 'oc_brands';

		return $vars;
	}

	/**
	 * Whether this request is the brands page.
	 */
	private static function is_page(): bool {
		return '' !== (string) get_query_var( 'oc_brands' );
	}

	/**
	 * Draw the page. The main query found no posts for this address, so the
	 * 404 it concluded is retracted first.
	 */
	public function render(): void {
		if ( ! self::is_page() ) {
			return;
		}

		global $wp_query;

		$wp_query->is_404 = false;
		status_header( 200 );

		get_header();
		echo self::page_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
		get_footer();
		exit;
	}

	/**
	 * The browser-tab title.
	 *
	 * @param array<string,string> $parts Title parts.
	 * @return array<string,string>
	 */
	public function title( array $parts ): array {
		if ( self::is_page() ) {
			$parts['title'] = __( 'Brands', 'oc-theme' );
		}

		return $parts;
	}

	/**
	 * A class on the body, so the page's own background can take it.
	 *
	 * @param array<int,string> $classes Body classes.
	 * @return array<int,string>
	 */
	public function body_class( array $classes ): array {
		if ( self::is_page() ) {
			$classes[] = 'oc-brands-page';
		}

		return $classes;
	}

	/**
	 * On a brand's own archive the trail reads home, brands, the brand —
	 * and "brands" is a door, not a word.
	 *
	 * @param array<int,array<int,string>> $crumbs Breadcrumb as [label, url] pairs.
	 * @return array<int,array<int,string>>
	 */
	public function crumbs( array $crumbs ): array {
		$taxonomy = Search::brand_taxonomy();

		if ( '' === $taxonomy || ! is_tax( $taxonomy ) ) {
			return $crumbs;
		}

		$term = get_queried_object();

		if ( ! $term instanceof \WP_Term ) {
			return $crumbs;
		}

		$home = ! empty( $crumbs[0] ) ? $crumbs[0] : array( __( 'Home', 'oc-theme' ), home_url( '/' ) );

		return array(
			$home,
			array( __( 'Brands', 'oc-theme' ), self::url() ),
			array( $term->name, '' ),
		);
	}

	/*
	 * Rendering.
	 */

	/**
	 * The page itself.
	 */
	public static function page_html(): string {
		$taxonomy = Search::brand_taxonomy();
		$terms    = '' === $taxonomy ? array() : get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => true,
				'orderby'    => 'name',
			)
		);
		$terms    = is_array( $terms ) ? $terms : array();

		$width = absint( get_theme_mod( 'oc_brands_width', 1160 ) );
		$bg    = sanitize_hex_color( (string) get_theme_mod( 'oc_brands_bg', '' ) );
		$cols  = max( 1, absint( get_theme_mod( 'oc_brands_cols', 4 ) ) );
		$colsm = max( 1, absint( get_theme_mod( 'oc_brands_cols_m', 2 ) ) );
		$style = '.oc-brands{--oc-brands-cols:' . $cols . ';--oc-brands-cols-m:' . $colsm . '}';

		if ( $width > 0 ) {
			$style .= '.oc-brands{--oc-brands-w:' . $width . 'px}';
		}

		if ( $bg ) {
			$style .= 'body.oc-brands-page{background:' . $bg . '}';
		}

		$out  = '<style>' . $style . '</style>';
		$out .= '<main id="main" class="site-main"><div class="oc-brands">';
		$out .= '<h1 class="oc-page-title">' . esc_html__( 'Brands', 'oc-theme' ) . '</h1>';

		if ( empty( $terms ) ) {
			$out .= '<p>' . esc_html__( 'No brands yet.', 'oc-theme' ) . '</p>';
		} elseif ( 'logos' === get_theme_mod( 'oc_brands_view', 'letters' ) ) {
			$out .= self::logo_grid( $terms );
		} else {
			$out .= self::letter_groups( $terms );
		}

		return $out . '</div></main>';
	}

	/**
	 * The brands under their first letters, with a letter bar that jumps.
	 *
	 * @param array<int,\WP_Term> $terms Brands.
	 * @return string
	 */
	private static function letter_groups( array $terms ): string {
		$groups = array();

		foreach ( $terms as $term ) {
			$letter = function_exists( 'mb_strtoupper' )
				? mb_strtoupper( mb_substr( $term->name, 0, 1 ) )
				: strtoupper( substr( $term->name, 0, 1 ) );

			$groups[ $letter ][] = $term;
		}

		$bar  = '<nav class="oc-brands__abc" aria-label="' . esc_attr__( 'Brands by letter', 'oc-theme' ) . '">';
		$body = '';
		$at   = 0;

		foreach ( $groups as $letter => $group ) {
			++$at;
			$bar .= '<a href="#oc-brand-g' . $at . '">' . esc_html( (string) $letter ) . '</a>';

			$body .= '<section class="oc-brands__sec" id="oc-brand-g' . $at . '">';
			$body .= '<h2 class="oc-brands__letter">' . esc_html( (string) $letter ) . '</h2>';
			$body .= '<ul class="oc-brands__grid oc-brands__grid--names">';

			foreach ( $group as $term ) {
				$body .= '<li><a href="' . esc_url( (string) get_term_link( $term ) ) . '">' . esc_html( $term->name ) . '</a></li>';
			}

			$body .= '</ul></section>';
		}

		return $bar . '</nav>' . $body;
	}

	/**
	 * Every brand as a card: its picture, its name under it.
	 *
	 * @param array<int,\WP_Term> $terms Brands.
	 * @return string
	 */
	private static function logo_grid( array $terms ): string {
		$out = '<ul class="oc-brands__grid oc-brands__grid--logos">';

		foreach ( $terms as $term ) {
			$thumb = (int) get_term_meta( (int) $term->term_id, 'thumbnail_id', true );
			$logo  = $thumb > 0
				? wp_get_attachment_image(
					$thumb,
					'medium',
					false,
					array(
						'class'   => 'oc-brands__logo',
						'loading' => 'lazy',
						'alt'     => $term->name,
					)
				)
				: '<span class="oc-brands__stand">' . esc_html( $term->name ) . '</span>';

			$out .= '<li><a class="oc-brands__card" href="' . esc_url( (string) get_term_link( $term ) ) . '">';
			$out .= '<span class="oc-brands__face">' . $logo . '</span>';
			$out .= '<span class="oc-brands__name">' . esc_html( $term->name ) . '</span>';
			$out .= '</a></li>';
		}

		return $out . '</ul>';
	}
}
