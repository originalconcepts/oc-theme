<?php
/**
 * The site menu.
 *
 * Structure — which links, in what order, which under which — stays on
 * WordPress's own Menus screen, because that is where a shop owner already
 * knows to look and because its drag-and-drop is better than anything worth
 * rebuilding. What this class adds is the two things that screen has no
 * opinion about: how the menu reads, and what opens underneath a link.
 *
 * @package OC_Theme
 */

declare( strict_types = 1 );

namespace OC\Theme;

defined( 'ABSPATH' ) || exit;

/**
 * Front-end presentation for the primary menu.
 */
final class Menu {

	/**
	 * Hooks.
	 */
	public function register(): void {
		add_filter( 'body_class', array( $this, 'body_class' ) );
	}

	/**
	 * Mark the body when a panel is meant to dim the page, so the CSS can
	 * paint the overlay without the JS knowing anything about the setting.
	 *
	 * @param array<int,string> $classes Body classes.
	 * @return array<int,string>
	 */
	public function body_class( array $classes ): array {
		if ( get_theme_mod( 'oc_menu_dim', false ) ) {
			$classes[] = 'oc-menu-dim';
		}

		return $classes;
	}

	/**
	 * Hover treatments. The key becomes a class on the nav.
	 *
	 * @return array<string,string>
	 */
	public static function hovers(): array {
		return array(
			'fill'  => __( 'A line draws itself', 'oc-theme' ),
			'slide' => __( 'A line slides through', 'oc-theme' ),
			'lift'  => __( 'The word lifts', 'oc-theme' ),
			'plain' => __( 'Only the colour changes', 'oc-theme' ),
		);
	}

	/**
	 * Classes for the primary <nav>, carrying the settings the CSS needs to
	 * know about. A class rather than a custom property because these switch
	 * whole rules on and off, not single values.
	 *
	 * @return string
	 */
	public static function nav_class(): string {
		$hover  = (string) get_theme_mod( 'oc_menu_hover', 'fill' );
		$motion = (string) get_theme_mod( 'oc_menu_motion', 'stagger' );

		if ( ! array_key_exists( $hover, self::hovers() ) ) {
			$hover = 'fill';
		}
		if ( ! in_array( $motion, array( 'stagger', 'fade', 'none' ), true ) ) {
			$motion = 'stagger';
		}

		$classes = array(
			'oc-nav',
			'oc-nav--hv-' . $hover,
			'oc-nav--mo-' . $motion,
		);

		if ( get_theme_mod( 'oc_menu_dim', false ) ) {
			$classes[] = 'oc-nav--dim';
		}

		return implode( ' ', array_map( 'sanitize_html_class', $classes ) );
	}

	/**
	 * How deep a plain drop-down may go.
	 *
	 * @return int
	 */
	public static function depth(): int {
		return 3 === (int) get_theme_mod( 'oc_menu_depth', 2 ) ? 3 : 2;
	}
}
