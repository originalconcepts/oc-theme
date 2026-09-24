<?php
/**
 * Plugin Name:       OC Statistics for WooCommerce
 * Plugin URI:        https://onlinestore.co.il
 * Description:       Sales, visits and what to do about them, inside the WordPress dashboard. Counts visits itself, with no third party and no account to open.
 * Version:           0.3.271
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Requires Plugins:  woocommerce
 * Author:            Original Concepts
 * Author URI:        https://onlinestore.co.il
 * Text Domain:       oc-stats
 * Domain Path:       /languages
 * License:           GPL-2.0-or-later
 *
 * @package OC_Stats
 */

namespace OC\Stats;

defined( 'ABSPATH' ) || exit;

define( 'OC_STATS_VERSION', '0.3.271' );
define( 'OC_STATS_FILE', __FILE__ );
define( 'OC_STATS_DIR', plugin_dir_path( __FILE__ ) );
define( 'OC_STATS_URL', plugin_dir_url( __FILE__ ) );

require_once OC_STATS_DIR . 'inc/class-settings.php';
require_once OC_STATS_DIR . 'inc/class-track.php';
require_once OC_STATS_DIR . 'inc/class-query.php';
require_once OC_STATS_DIR . 'inc/class-insights.php';
require_once OC_STATS_DIR . 'inc/class-rollup.php';
require_once OC_STATS_DIR . 'inc/class-mail.php';
require_once OC_STATS_DIR . 'inc/class-admin.php';
require_once OC_STATS_DIR . 'inc/class-product.php';
require_once OC_STATS_DIR . 'inc/class-heat.php';
require_once OC_STATS_DIR . 'inc/class-heat-admin.php';

/**
 * Everything this plugin needs before it will run: WooCommerce, and no
 * second copy of the same screens. The OC theme carries these statistics
 * built in; where that theme is active the plugin stands aside rather
 * than counting every visit twice.
 *
 * @return string Empty when all is well, otherwise the reason, ready to show.
 */
function blocked(): string {
	if ( ! class_exists( 'WooCommerce' ) ) {
		return __( 'OC Statistics needs WooCommerce. Activate WooCommerce and the statistics appear on their own.', 'oc-stats' );
	}

	if ( class_exists( '\OC\Theme\Stats\Track' ) ) {
		return __( 'The OC theme already carries these statistics, so the plugin is not needed here and has switched itself off.', 'oc-stats' );
	}

	return '';
}

/**
 * Start, once WordPress has loaded the theme and every other plugin — the
 * check above can only be trusted that late.
 */
function start(): void {
	if ( '' !== blocked() ) {
		return;
	}

	( new Track() )->register();
	( new Rollup() )->register();
	( new Admin() )->register();
	( new Product() )->register();
	( new Heat() )->register();
	( new Heat_Admin() )->register();
}
add_action( 'init', __NAMESPACE__ . '\\start', 1 );

/**
 * Say why nothing appeared, on the plugins screen, where someone is looking.
 */
function notice(): void {
	$why = blocked();

	if ( '' === $why || ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

	if ( $screen && in_array( $screen->id, array( 'plugins', 'dashboard' ), true ) ) {
		echo '<div class="notice notice-warning"><p><strong>OC Statistics</strong> — ' . esc_html( $why ) . '</p></div>';
	}
}
add_action( 'admin_notices', __NAMESPACE__ . '\\notice' );

/**
 * Its own Hebrew, from the plugin's own folder.
 */
add_action(
	'init',
	static function (): void {
		load_plugin_textdomain( 'oc-stats', false, dirname( plugin_basename( OC_STATS_FILE ) ) . '/languages' );
	},
	0
);

/**
 * On activation: make the tables and book the hourly tick, so the first
 * visit after switching on is already counted.
 */
register_activation_hook(
	__FILE__,
	static function (): void {
		if ( class_exists( 'WooCommerce' ) && ! class_exists( '\OC\Theme\Stats\Track' ) ) {
			Track::install();
			Heat::install();
			( new Rollup() )->schedule();
		}
	}
);

/**
 * On deactivation the counting stops; the numbers already gathered stay
 * where they are, so switching the plugin on again loses nothing.
 */
register_deactivation_hook(
	__FILE__,
	static function (): void {
		wp_clear_scheduled_hook( Rollup::CRON );
	}
);

/**
 * A way to the statistics from the plugins screen.
 *
 * @param string[] $links Row links.
 * @return string[]
 */
function row_links( $links ) {
	if ( '' === blocked() ) {
		array_unshift(
			$links,
			'<a href="' . esc_url( admin_url( 'admin.php?page=' . Admin::PAGE ) ) . '">' . esc_html__( 'Statistics', 'oc-stats' ) . '</a>',
			'<a href="' . esc_url( admin_url( 'admin.php?page=' . Admin::SETTINGS ) ) . '">' . esc_html__( 'Settings', 'oc-stats' ) . '</a>'
		);
	}

	return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), __NAMESPACE__ . '\\row_links' );

/**
 * HPOS: this plugin reads orders through WooCommerce's own API, so it is
 * at home with the new order tables.
 */
add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', OC_STATS_FILE, true );
		}
	}
);
