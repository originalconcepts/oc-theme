<?php
/**
 * WooCommerce wrapper.
 *
 * A single entry point for every WooCommerce page. Without it WordPress and
 * WooCommerce each render their own template and the header is output twice.
 *
 * This is not a template override — it carries no copied WooCommerce markup and
 * no @version header. woocommerce_content() hands rendering back to the plugin,
 * which is exactly what DECISIONS.md #7 asks for.
 *
 * @package OC_Theme
 */

defined( 'ABSPATH' ) || exit;

get_header();
woocommerce_content();
get_footer();
