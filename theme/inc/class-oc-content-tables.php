<?php
/**
 * Tables inside content nobody here wrote.
 *
 * A supplier's spec table arrives with a pixel width from the page it was
 * copied from, a height on every row, an <hr> in every cell and no borders
 * at all. It cannot be fixed per product — the next import brings another
 * — so each such table is wrapped in a scroll box the stylesheet knows how
 * to dress: full column width, quiet lines, a tinted heading row, and a
 * sideways scroll on a phone where the columns do not fit.
 *
 * WooCommerce's own tables (variations, attributes, cart) and the theme's
 * are laid out by hand elsewhere and are left alone.
 *
 * @package OC_Theme
 */

declare( strict_types = 1 );

namespace OC\Theme;

if ( ! defined( 'ABSPATH' ) && ! defined( 'OC_TESTS' ) ) {
	exit;
}

/**
 * Wraps bare content tables.
 */
final class Content_Tables {

	/**
	 * Hook the content filters late, after wpautop and shortcodes.
	 */
	public function register(): void {
		add_filter( 'the_content', array( __CLASS__, 'wrap' ), 30 );
		add_filter( 'woocommerce_short_description', array( __CLASS__, 'wrap' ), 30 );
		add_filter( 'term_description', array( __CLASS__, 'wrap' ), 30 );
	}

	/**
	 * Wrap every bare <table> in <div class="oc-tbl">.
	 *
	 * A table already inside the wrapper, or one carrying a WooCommerce,
	 * theme or block class, is skipped.
	 *
	 * @param mixed $html The content.
	 * @return mixed
	 */
	public static function wrap( $html ) {
		if ( ! is_string( $html ) || false === stripos( $html, '<table' ) ) {
			return $html;
		}

		// Whole elements, so the closing tag travels with the opening one.
		// A table nested in a table is not a case worth the parser it needs.
		return (string) preg_replace_callback(
			'#(<div class="oc-tbl">\s*)?<table\b([^>]*)>.*?</table>#is',
			static function ( array $m ): string {
				if ( '' !== $m[1] ) {
					return $m[0];
				}

				if ( preg_match( '#class=["\'][^"\']*(?:woocommerce|variations|shop_table|\boc-|\bocb-)#i', $m[2] ) ) {
					return $m[0];
				}

				return '<div class="oc-tbl' . ( self::bold_first_row( $m[0] ) ? ' oc-tbl--h1' : '' ) . '">' . $m[0] . '</div>';
			},
			$html
		);
	}

	/**
	 * A table with no <thead> whose first row is set entirely in bold was
	 * given a heading row by hand; the stylesheet then tints it as one.
	 *
	 * @param string $table The whole table.
	 */
	private static function bold_first_row( string $table ): bool {
		if ( false !== stripos( $table, '<thead' ) || false !== stripos( $table, '<th' ) ) {
			return false;
		}

		if ( ! preg_match( '#<tr\b[^>]*>(.*?)</tr>#is', $table, $row ) ) {
			return false;
		}

		if ( ! preg_match_all( '#<td\b[^>]*>(.*?)</td>#is', $row[1], $cells ) ) {
			return false;
		}

		foreach ( $cells[1] as $cell ) {
			if ( '' === trim( (string) preg_replace( '#<[^>]*>#', '', $cell ) ) ) {
				continue;
			}

			if ( ! preg_match( '#<(strong|b)\b#i', $cell ) ) {
				return false;
			}
		}

		return true;
	}
}
