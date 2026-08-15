<?php
/**
 * Theme bootstrap.
 *
 * Deliberately thin. The previous theme carried 2,093 lines here and returned
 * early when WooCommerce was inactive, which left a blank site instead of an
 * admin notice.
 *
 * @package OC_Theme
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

define( 'OC_THEME_VERSION', '0.1.0' );
define( 'OC_THEME_DIR', get_template_directory() );
define( 'OC_THEME_URI', get_template_directory_uri() );
define( 'OC_THEME_REPO', 'originalconcepts/oc-theme' );

/**
 * Private login path.
 *
 * Override per site in wp-config.php — give each client site its own, so that
 * learning one does not reveal them all:
 *     define( 'OC_LOGIN_SLUG', 'my-private-door' );
 *
 * Emergency escape, restores wp-login.php without touching files:
 *     define( 'OC_LOGIN_DISABLE', true );
 */
if ( ! defined( 'OC_LOGIN_SLUG' ) ) {
	define( 'OC_LOGIN_SLUG', 'ocadmin' );
}

require_once OC_THEME_DIR . '/inc/class-oc-updater.php';
require_once OC_THEME_DIR . '/inc/class-oc-assets.php';
require_once OC_THEME_DIR . '/inc/class-oc-login.php';

/**
 * Cache-busting version for a theme-relative asset.
 *
 * filemtime(), never time(). The old theme used time() on ~30 files, which
 * gave every asset a new version on every page load and disabled browser and
 * CDN caching site-wide.
 *
 * @param string $relative Path relative to the theme root, with leading slash.
 * @return string
 */
function oc_asset_version( string $relative ): string {
	$path = OC_THEME_DIR . $relative;
	return file_exists( $path ) ? (string) filemtime( $path ) : OC_THEME_VERSION;
}

/**
 * Theme supports and translations.
 *
 * load_theme_textdomain() belongs on after_setup_theme. The old theme called
 * it at file scope, before init, so translations silently never loaded — which
 * is why Hebrew ended up hardcoded throughout the PHP.
 */
function oc_setup(): void {
	load_theme_textdomain( 'oc-theme', OC_THEME_DIR . '/languages' );

	add_theme_support( 'title-tag' );
	add_theme_support( 'post-thumbnails' );
	add_theme_support( 'html5', array( 'search-form', 'gallery', 'caption', 'style', 'script' ) );
	add_theme_support( 'align-wide' );
	add_theme_support( 'responsive-embeds' );
	add_theme_support( 'editor-styles' );

	register_nav_menus(
		array(
			'primary' => __( 'Primary menu', 'oc-theme' ),
			'footer'  => __( 'Footer menu', 'oc-theme' ),
		)
	);

	if ( class_exists( 'WooCommerce' ) ) {
		add_theme_support( 'woocommerce' );
		add_theme_support( 'wc-product-gallery-zoom' );
		add_theme_support( 'wc-product-gallery-lightbox' );
		add_theme_support( 'wc-product-gallery-slider' );
	}
}
add_action( 'after_setup_theme', 'oc_setup' );

/**
 * Tell the shop owner what is missing instead of dying silently.
 */
function oc_dependency_notice(): void {
	if ( class_exists( 'WooCommerce' ) || ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	printf(
		'<div class="notice notice-warning"><p>%s</p></div>',
		esc_html__( 'OC Theme needs WooCommerce. Shop features stay hidden until it is active.', 'oc-theme' )
	);
}
add_action( 'admin_notices', 'oc_dependency_notice' );

( new OC\Theme\Assets() )->register();
( new OC\Theme\Updater( get_template(), OC_THEME_VERSION, OC_THEME_REPO ) )->register();

if ( ! defined( 'OC_LOGIN_DISABLE' ) || ! OC_LOGIN_DISABLE ) {
	$oc_login_slug = trim( sanitize_title( (string) apply_filters( 'oc_login_slug', OC_LOGIN_SLUG ) ), '/' );

	if ( '' !== $oc_login_slug ) {
		( new OC\Theme\Gate( $oc_login_slug ) )->register();
	}
}
