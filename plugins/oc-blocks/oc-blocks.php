<?php
/**
 * Plugin Name: OC Blocks
 * Plugin URI:  https://github.com/originalconcepts/oc-theme
 * Description: Page composer for the OC theme — hero sliders, products, categories, banners and the rest, edited in a full-screen composer with a live 1:1 preview.
 * Version:     0.3.64
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Author:      Original Concepts
 * License:     GPL-2.0-or-later
 * Text Domain: oc-blocks
 *
 * Pages live in a plugin, not the theme, so a client can switch or rebuild a
 * theme without losing every page they have built.
 *
 * @package OC_Blocks
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

define( 'OC_BLOCKS_VERSION', '0.3.64' );
define( 'OC_BLOCKS_DIR', plugin_dir_path( __FILE__ ) );
define( 'OC_BLOCKS_URI', plugin_dir_url( __FILE__ ) );

/**
 * Prefer the minified build of an asset — only when it is at least as new
 * as its source. A stale .min (edited source, skipped scripts/minify.py)
 * must never ship: slower is acceptable, broken is not.
 *
 * @param string $rel Path relative to the plugin root, e.g. 'assets/blocks.css'.
 * @return string The path to enqueue.
 */
function oc_blocks_asset( string $rel ): string {
	$min = (string) preg_replace( '/\.(js|css)$/', '.min.$1', $rel );

	if ( file_exists( OC_BLOCKS_DIR . $min ) && file_exists( OC_BLOCKS_DIR . $rel )
		&& filemtime( OC_BLOCKS_DIR . $min ) >= filemtime( OC_BLOCKS_DIR . $rel ) ) {
		return $min;
	}

	return $rel;
}

require_once OC_BLOCKS_DIR . 'inc/class-registry.php';
require_once OC_BLOCKS_DIR . 'inc/class-render.php';
require_once OC_BLOCKS_DIR . 'inc/class-editor.php';
require_once OC_BLOCKS_DIR . 'inc/class-preview.php';
require_once OC_BLOCKS_DIR . 'inc/class-newsletter.php';
require_once OC_BLOCKS_DIR . 'inc/class-leads.php';
require_once OC_BLOCKS_DIR . 'inc/class-branches.php';

add_action(
	'init',
	static function (): void {
		load_plugin_textdomain( 'oc-blocks', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	}
);

( new OC\Blocks\Render() )->register();
( new OC\Blocks\Editor() )->register();
( new OC\Blocks\Preview() )->register();
( new OC\Blocks\Newsletter() )->register();
( new OC\Blocks\Leads() )->register();
( new OC\Blocks\Branches() )->register();
