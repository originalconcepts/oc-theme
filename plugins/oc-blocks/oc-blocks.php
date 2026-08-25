<?php
/**
 * Plugin Name: OC Blocks
 * Plugin URI:  https://github.com/originalconcepts/oc-theme
 * Description: Page composer for the OC theme — hero sliders, products, categories, banners and the rest, edited in a full-screen composer with a live 1:1 preview.
 * Version:     0.2.3
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

define( 'OC_BLOCKS_VERSION', '0.2.3' );
define( 'OC_BLOCKS_DIR', plugin_dir_path( __FILE__ ) );
define( 'OC_BLOCKS_URI', plugin_dir_url( __FILE__ ) );

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
