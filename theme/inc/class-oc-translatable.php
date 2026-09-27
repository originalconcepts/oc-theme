<?php
/**
 * The theme's own typed texts, declared for OC Lang: tab titles, the cart
 * drawer's words, the labels, the login drawer, the email openings. The
 * plugin translates the settings it is told about; this is where the
 * theme tells it, with labels for the Translations table.
 *
 * Both filters are the plugin's. On a site without it nothing here runs.
 *
 * @package OC_Theme
 */

declare( strict_types = 1 );

namespace OC\Theme;

defined( 'ABSPATH' ) || exit;

/**
 * What of the theme's settings holds words shoppers read.
 */
final class Translatable {

	/**
	 * WooCommerce's customer emails, whose settings carry the theme's
	 * opening words and their collection variants.
	 */
	private const EMAILS = array( 'customer_on_hold_order', 'customer_processing_order', 'customer_completed_order', 'customer_refunded_order', 'customer_invoice', 'customer_note', 'customer_reset_password', 'customer_new_account' );

	/**
	 * Hook in.
	 */
	public function register(): void {
		add_filter( 'oclang_options', array( $this, 'options' ) );
		add_filter( 'oclang_option_labels', array( $this, 'labels' ) );
	}

	/**
	 * The option theme mods live in: one per stylesheet.
	 */
	private static function mods_option(): string {
		return 'theme_mods_' . get_option( 'stylesheet' );
	}

	/**
	 * The theme mods that hold words, with their labels.
	 *
	 * @return array<string,string>
	 */
	private static function mods(): array {
		return array(
			'oc_related_title'         => __( 'Related products — heading', 'oc-theme' ),
			'oc_upsells_title'         => __( 'Complementary products — heading', 'oc-theme' ),
			'oc_xsell_title'           => __( 'Cross-sells — heading', 'oc-theme' ),
			'oc_label_new_text'        => __( '"New" label', 'oc-theme' ),
			'oc_label_stock_last'      => __( 'Last-one label', 'oc-theme' ),
			'oc_label_stock_low'       => __( 'Last-items label', 'oc-theme' ),
			'oc_label_stock_out'       => __( 'Out-of-stock label', 'oc-theme' ),
			'oc_label_strip_buy_text'  => __( '"In demand" strip', 'oc-theme' ),
			'oc_label_strip_cart_text' => __( '"Great choice" strip', 'oc-theme' ),
			'oc_login_title'           => __( 'Login — title', 'oc-theme' ),
			'oc_login_reg_title'       => __( 'Login — registration line', 'oc-theme' ),
			'oc_login_reg_perks'       => __( 'Login — registration perks', 'oc-theme' ),
			'oc_login_club_text'       => __( 'Login — club pitch', 'oc-theme' ),
			'oc_blog_disclaimer'       => __( 'Blog disclaimer', 'oc-theme' ),
			'oc_contact_title_text'    => __( 'Product contact — title', 'oc-theme' ),
			'oc_contact_name'          => __( 'Product contact — name', 'oc-theme' ),
			'oc_contact_role'          => __( 'Product contact — role', 'oc-theme' ),
			'oc_contact_msg'           => __( 'Product contact — message', 'oc-theme' ),
			'oc_contact_btn'           => __( 'Product contact — button', 'oc-theme' ),
		);
	}

	/**
	 * The cart drawer's words, with their labels.
	 *
	 * @return array<string,string>
	 */
	private static function cart(): array {
		return array(
			'title'      => __( 'Cart — title', 'oc-theme' ),
			'empty_text' => __( 'Cart — empty text', 'oc-theme' ),
			'ship_text'  => __( 'Cart — free-shipping progress', 'oc-theme' ),
			'ship_done'  => __( 'Cart — free-shipping reached', 'oc-theme' ),
			'up_title'   => __( 'Cart — upsells title', 'oc-theme' ),
			'btn_text'   => __( 'Cart — checkout button', 'oc-theme' ),
		);
	}

	/**
	 * The product tabs' words: the two built-in titles and every custom tab
	 * the shop added, by its place in the list.
	 *
	 * @return array<string,string>
	 */
	private static function tabs(): array {
		$out   = array(
			'short_title' => __( 'Short description tab — title', 'oc-theme' ),
			'desc_title'  => __( 'Description tab — title', 'oc-theme' ),
		);
		$saved = get_option( 'oc_tabs' );
		$rows  = is_array( $saved ) && isset( $saved['custom'] ) && is_array( $saved['custom'] ) ? $saved['custom'] : array();

		foreach ( array_keys( $rows ) as $i ) {
			/* translators: %d: the tab's number */
			$out[ 'custom/' . $i . '/title' ] = sprintf( __( 'Custom tab %d — title', 'oc-theme' ), (int) $i + 1 );
			/* translators: %d: the tab's number */
			$out[ 'custom/' . $i . '/content' ] = sprintf( __( 'Custom tab %d — content', 'oc-theme' ), (int) $i + 1 );
		}

		return $out;
	}

	/**
	 * The opening words the theme adds to each customer email's settings.
	 *
	 * @return array<string,string>
	 */
	private static function emails(): array {
		return array(
			'oc_intro'          => __( 'Opening words', 'oc-theme' ),
			'oc_heading_pickup' => __( 'Heading — collection', 'oc-theme' ),
			'oc_intro_pickup'   => __( 'Opening words — collection', 'oc-theme' ),
		);
	}

	/**
	 * The theme's settings, added to the plugin's manifest.
	 *
	 * @param array<string,string[]>|mixed $out Option name => the keys that hold text.
	 * @return array<string,string[]>
	 */
	public function options( $out ): array {
		$out = is_array( $out ) ? $out : array();

		$out[ self::mods_option() ] = array_keys( self::mods() );
		$out['oc_tabs']             = array_keys( self::tabs() );
		$out['oc_cart']             = array_keys( self::cart() );

		foreach ( self::EMAILS as $id ) {
			$name         = 'woocommerce_' . $id . '_settings';
			$out[ $name ] = array_values( array_unique( array_merge( (array) ( $out[ $name ] ?? array() ), array_keys( self::emails() ) ) ) );
		}

		return $out;
	}

	/**
	 * Labels for the Translations table.
	 *
	 * @param array<string,array<string,string>>|mixed $labels Option name => ( '' => the option's label, key => the field's ).
	 * @return array<string,array<string,string>>
	 */
	public function labels( $labels ): array {
		$labels = is_array( $labels ) ? $labels : array();

		$labels[ self::mods_option() ] = array( '' => __( 'Theme settings', 'oc-theme' ) ) + self::mods();
		$labels['oc_tabs']             = array( '' => __( 'Product tabs', 'oc-theme' ) ) + self::tabs();
		$labels['oc_cart']             = array( '' => __( 'Cart drawer', 'oc-theme' ) ) + self::cart();

		foreach ( self::EMAILS as $id ) {
			$name            = 'woocommerce_' . $id . '_settings';
			$labels[ $name ] = (array) ( $labels[ $name ] ?? array() ) + self::emails();
		}

		return $labels;
	}
}
