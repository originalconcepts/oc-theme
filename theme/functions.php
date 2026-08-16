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
require_once OC_THEME_DIR . '/inc/class-oc-woocommerce.php';
require_once OC_THEME_DIR . '/inc/class-oc-customizer.php';

/**
 * Cache-busting version for a theme-relative asset.
 *
 * Uses filemtime(), never time(). The old theme used time() on ~30 files, which
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
 * The textdomain load belongs on after_setup_theme. The old theme called
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
		// Gallery slider/zoom/lightbox supports are setting-dependent and are
		// applied in WooCommerce::runtime_hooks() so the Customizer preview
		// sees the changed values.
	}
}
add_action( 'after_setup_theme', 'oc_setup' );

/**
 * Footer widget columns.
 */
function oc_widgets(): void {
	for ( $i = 1; $i <= 3; $i++ ) {
		register_sidebar(
			array(
				'id'            => 'oc-footer-' . $i,
				/* translators: %d: column number. */
				'name'          => sprintf( __( 'Footer column %d', 'oc-theme' ), $i ),
				'before_widget' => '<div id="%1$s" class="oc-footer__widget %2$s">',
				'after_widget'  => '</div>',
				'before_title'  => '<h2 class="oc-footer__widget-title">',
				'after_title'   => '</h2>',
			)
		);
	}
}
add_action( 'widgets_init', 'oc_widgets' );

/**
 * Header end icons: search, account, cart — each behind its own setting.
 */
function oc_header_icons_render(): void {
	if ( get_theme_mod( 'oc_header_search', true ) ) {
		printf(
			'<button type="button" class="oc-hicon oc-search-toggle" aria-expanded="false" aria-controls="oc-header-search" aria-label="%s">%s</button>',
			esc_attr__( 'Search', 'oc-theme' ),
			'<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.8-3.8"/></svg>'
		);
	}

	if ( class_exists( 'WooCommerce' ) && get_theme_mod( 'oc_header_account', true ) ) {
		printf(
			'<a class="oc-hicon oc-account-link" href="%s" aria-label="%s">%s</a>',
			esc_url( wc_get_page_permalink( 'myaccount' ) ),
			esc_attr__( 'My account', 'oc-theme' ),
			'<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 20c1.8-3.4 4.6-5 8-5s6.2 1.6 8 5"/></svg>'
		);
	}

	if ( class_exists( 'WooCommerce' ) && get_theme_mod( 'oc_header_cart', true ) ) {
		$oc_count = is_object( WC()->cart ) ? WC()->cart->get_cart_contents_count() : 0;

		printf(
			'<a class="oc-hicon oc-cart-link" href="%s" aria-label="%s">%s<span class="oc-cart-count">%d</span></a>',
			esc_url( wc_get_cart_url() ),
			esc_attr__( 'Cart', 'oc-theme' ),
			'<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="9" cy="20" r="1.6"/><circle cx="17" cy="20" r="1.6"/><path d="M3 3h2.5l2.2 11.2a1.6 1.6 0 0 0 1.6 1.3h7.6a1.6 1.6 0 0 0 1.6-1.3L20 7H6"/></svg>',
			absint( $oc_count )
		);
	}
}
add_action( 'oc_header_icons', 'oc_header_icons_render' );

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
( new OC\Theme\WooCommerce() )->register();
( new OC\Theme\Customizer() )->register();
( new OC\Theme\Updater( get_template(), OC_THEME_VERSION, OC_THEME_REPO ) )->register();

if ( ! defined( 'OC_LOGIN_DISABLE' ) || ! OC_LOGIN_DISABLE ) {
	$oc_login_slug = trim( sanitize_title( (string) apply_filters( 'oc_login_slug', OC_LOGIN_SLUG ) ), '/' );

	if ( '' !== $oc_login_slug ) {
		( new OC\Theme\Gate( $oc_login_slug ) )->register();
	}
}
