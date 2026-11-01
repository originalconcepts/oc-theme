<?php
/**
 * Constant definitions for static analysis only.
 *
 * PHPStan does not execute functions.php, so the constants defined there are
 * unknown to it. This file mirrors them; it is never loaded by WordPress.
 *
 * @package OC_Theme
 */

define( 'OC_THEME_VERSION', '0.0.0' );
define( 'OC_THEME_DIR', __DIR__ );
define( 'OC_THEME_URI', 'https://example.invalid' );
define( 'OC_THEME_REPO', 'originalconcepts/oc-theme' );
define( 'OC_LOGIN_SLUG', 'ocadmin' );
define( 'OC_BLOCKS_VERSION', '0.0.0' );
define( 'OC_BLOCKS_DIR', __DIR__ );
define( 'OC_BLOCKS_URI', 'https://example.invalid' );
define( 'OC_STATS_VERSION', '0.0.0' );
define( 'OC_STATS_FILE', __FILE__ );
define( 'OC_STATS_DIR', __DIR__ );
define( 'OC_STATS_URL', 'https://example.invalid/' );

// WordPress defines these in wp_cookie_constants(); the stubs do not.
define( 'COOKIEPATH', '/' );
define( 'COOKIE_DOMAIN', '' );

/*
 * Optional third-party plugins the theme talks to when they are present.
 * Every call in the theme sits behind a function_exists() guard; these
 * signatures only let the analyser follow the calls.
 */
if ( ! function_exists( 'flashy' ) ) {
	/**
	 * The Flashy plugin's singleton (wp-flashy-marketing-automation).
	 *
	 * @return mixed
	 */
	function flashy() {
		return null;
	}
}
if ( ! function_exists( 'flashy_log' ) ) {
	/**
	 * Flashy's own log.
	 *
	 * @param string $message Line to log.
	 */
	function flashy_log( $message ): void {
		unset( $message );
	}
}

/*
 * The Deliz theme, which OC Blocks also runs under. Its recipe cards are
 * borrowed when a posts section asks for the theme's own design; every call
 * sits behind a function_exists() guard.
 */
if ( ! function_exists( 'deliz_recipes_per_row' ) ) {
	/**
	 * Recipe cards per row, as the theme's settings say.
	 */
	function deliz_recipes_per_row(): string {
		return '3';
	}
}
if ( ! function_exists( 'deliz_recipes_card_style' ) ) {
	/**
	 * The recipe card style the theme's settings choose.
	 */
	function deliz_recipes_card_style(): string {
		return 'classic';
	}
}
if ( ! function_exists( 'deliz_asset_min' ) ) {
	/**
	 * The minified twin of a theme asset, when there is one.
	 *
	 * @param string $rel Path inside the theme.
	 */
	function deliz_asset_min( string $rel ): string {
		return $rel;
	}
}
