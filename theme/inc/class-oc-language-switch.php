<?php
/**
 * Where the language switcher goes.
 *
 * The translation plugin draws the switcher; the theme knows its header,
 * its menu drawer and its footer, so the theme says where it sits — once
 * for a desk, once for a phone, from the Customizer's header section. A
 * phone's header bar is crowded, and the switcher pushed the icons aside:
 * the drawer's top bar, beside the close, is where it fits there.
 *
 * @package OC_Theme
 */

declare( strict_types = 1 );

namespace OC\Theme;

defined( 'ABSPATH' ) || exit;

/**
 * Places the plugin's switcher by the header settings.
 */
final class Language_Switch {

	/**
	 * The plugin's class that draws the switcher.
	 */
	private const PLUGIN = '\\OC\\Lang\\Switcher';

	/**
	 * Where it may go on a desk: beside the header icons, at the end of the
	 * main menu, in the footer, floating, or nowhere but the shortcode.
	 */
	public const DESK = array( 'icons', 'menu', 'footer', 'floating', 'none' );

	/**
	 * Where it may go on a phone: in the menu drawer beside the close, in
	 * the header bar, in the footer, floating, or nowhere but the shortcode.
	 */
	public const MOBILE = array( 'drawer', 'bar', 'footer', 'floating', 'none' );

	/**
	 * Whether the plugin is here to draw one.
	 */
	public static function available(): bool {
		return class_exists( self::PLUGIN );
	}

	/**
	 * Hook in: tell the plugin the theme places it, then print it where
	 * the settings say.
	 */
	public function register(): void {
		if ( ! self::available() ) {
			return;
		}

		add_filter( 'oclang_switcher_placement', array( __CLASS__, 'placement' ) );
		add_action( 'oc_header_icons', array( $this, 'header' ), 5 );
		add_action( 'oc_drawer_top', array( $this, 'drawer' ) );
		add_action( 'oc_footer_legal', array( $this, 'footer' ) );
		add_action( 'wp_footer', array( $this, 'floating' ) );
		add_filter( 'wp_nav_menu_items', array( $this, 'menu_item' ), 20, 2 );
	}

	/**
	 * The plugin's own placement setting steps aside: the theme places it.
	 *
	 * @param string|mixed $placement What the plugin's settings say.
	 * @return string
	 */
	public static function placement( $placement ): string {
		unset( $placement );

		return 'theme';
	}

	/**
	 * The desk setting.
	 */
	public static function desk(): string {
		$value = (string) get_theme_mod( 'oc_lang_desk', 'icons' );

		return in_array( $value, self::DESK, true ) ? $value : 'icons';
	}

	/**
	 * The phone setting.
	 */
	public static function mobile(): string {
		$value = (string) get_theme_mod( 'oc_lang_mob', 'drawer' );

		return in_array( $value, self::MOBILE, true ) ? $value : 'drawer';
	}

	/**
	 * The switcher as the plugin draws it, or nothing when it has fewer
	 * than two languages to offer.
	 *
	 * @param array<string,string> $args style, layout — the plugin's render() arguments.
	 */
	private static function draw( array $args ): string {
		$render = array( self::PLUGIN, 'render' );

		return is_callable( $render ) ? (string) call_user_func( $render, $args ) : '';
	}

	/**
	 * The switcher for one spot, or nothing when neither setting names it.
	 * A spot both settings name gets one copy; a spot one names gets a copy
	 * the stylesheet shows on that kind of screen only.
	 *
	 * @param string               $spot  header, drawer, footer or floating.
	 * @param array<string,string> $args  What the plugin's render() takes.
	 * @param string               $extra More classes for the wrapper.
	 */
	private static function html( string $spot, array $args = array(), string $extra = '' ): string {
		$desk   = self::desk();
		$mobile = self::mobile();
		$on_d   = 'header' === $spot ? 'icons' === $desk : $spot === $desk;
		$on_m   = 'header' === $spot ? 'bar' === $mobile : $spot === $mobile;

		if ( ! $on_d && ! $on_m ) {
			return '';
		}

		$inner = self::draw( $args );

		if ( '' === $inner ) {
			return '';
		}

		$class = 'oc-lang oc-lang--' . $spot;

		if ( $on_d && ! $on_m ) {
			$class .= ' oc-lang--desk';
		} elseif ( $on_m && ! $on_d ) {
			$class .= ' oc-lang--mob';
		}

		if ( '' !== $extra ) {
			$class .= ' ' . $extra;
		}

		return '<div class="' . esc_attr( $class ) . '">' . $inner . '</div>';
	}

	/**
	 * Beside the header icons.
	 */
	public function header(): void {
		echo self::html( 'header' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
	}

	/**
	 * In the drawer's top bar, beside the close: the languages in a row.
	 */
	public function drawer(): void {
		echo self::html( 'drawer', array( 'layout' => 'inline' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
	}

	/**
	 * In the footer's legal line.
	 */
	public function footer(): void {
		echo self::html( 'footer' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
	}

	/**
	 * Floating in a corner, in the plugin's own bubble.
	 */
	public function floating(): void {
		echo self::html( 'floating', array(), 'oclang-switcher-float' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
	}

	/**
	 * As the last item of the main menu — the desk's menu; the drawer
	 * draws its own list and has its own spot.
	 *
	 * @param string|mixed $items Menu items markup.
	 * @param object|mixed $args  Menu arguments.
	 * @return string|mixed
	 */
	public function menu_item( $items, $args ) {
		$location = is_object( $args ) && isset( $args->theme_location ) ? (string) $args->theme_location : '';

		if ( 'primary' !== $location || 'menu' !== self::desk() || ! is_string( $items ) ) {
			return $items;
		}

		$inner = self::draw( array( 'layout' => 'dropdown' ) );

		return '' === $inner ? $items : $items . '<li class="menu-item oclang-menu-item oc-lang oc-lang--menu oc-lang--desk">' . $inner . '</li>';
	}
}
