<?php
/**
 * Plugin Name: OC Blocks
 * Plugin URI:  https://github.com/originalconcepts/oc-theme
 * Description: Content blocks for the OC theme — hero, products, categories, banners and the rest of the page-building set.
 * Version:     0.1.0
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Author:      Original Concepts
 * License:     GPL-2.0-or-later
 * Text Domain: oc-blocks
 *
 * Blocks live in a plugin, not the theme, so a client can switch or rebuild a
 * theme without losing every page they have built.
 *
 * @package OC_Blocks
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

define( 'OC_BLOCKS_VERSION', '0.1.0' );
define( 'OC_BLOCKS_DIR', plugin_dir_path( __FILE__ ) );

/**
 * Register every built block.
 *
 * wp_register_block_types_from_metadata_collection() reads build/blocks-manifest.php,
 * so adding a block means adding a folder — never editing a registration list.
 */
function oc_blocks_register(): void {
	$build = OC_BLOCKS_DIR . 'build';

	if ( ! is_dir( $build ) ) {
		return;
	}

	if ( function_exists( 'wp_register_block_types_from_metadata_collection' ) ) {
		wp_register_block_types_from_metadata_collection( $build, $build . '/blocks-manifest.php' );
		return;
	}

	foreach ( (array) glob( $build . '/*', GLOB_ONLYDIR ) as $dir ) {
		if ( file_exists( $dir . '/block.json' ) ) {
			register_block_type( $dir );
		}
	}
}
add_action( 'init', 'oc_blocks_register' );

/**
 * Keep our blocks in one clearly named category.
 *
 * @param array $categories Block categories.
 * @return array
 */
function oc_blocks_category( array $categories ): array {
	array_unshift(
		$categories,
		array(
			'slug'  => 'oc',
			'title' => __( 'OC', 'oc-blocks' ),
		)
	);

	return $categories;
}
add_filter( 'block_categories_all', 'oc_blocks_category' );
