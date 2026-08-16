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
			'primary'   => __( 'Primary menu', 'oc-theme' ),
			'secondary' => __( 'Header side menu', 'oc-theme' ),
			'topbar'    => __( 'Top bar menu', 'oc-theme' ),
			'footer'    => __( 'Footer menu', 'oc-theme' ),
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
 * In "text" style, desktop shows word links (lanvin-style) and the icons
 * stay for mobile; the stylesheet swaps them by viewport.
 */
function oc_header_icons_render(): void {
	if ( 'text' === get_theme_mod( 'oc_header_icons_style', 'icons' ) ) {
		echo '<nav class="oc-htext" aria-label="' . esc_attr__( 'Account and cart', 'oc-theme' ) . '">';

		if ( get_theme_mod( 'oc_header_account', true ) && class_exists( 'WooCommerce' ) ) {
			printf(
				'<a class="oc-htext__link" href="%s">%s</a>',
				esc_url( wc_get_page_permalink( 'myaccount' ) ),
				esc_html__( 'Account', 'oc-theme' )
			);
		}

		if ( get_theme_mod( 'oc_header_search', true ) ) {
			printf(
				'<button type="button" class="oc-htext__link oc-search-toggle" aria-expanded="false" aria-controls="oc-header-search">%s</button>',
				esc_html__( 'Search', 'oc-theme' )
			);
		}

		if ( get_theme_mod( 'oc_header_cart', true ) && class_exists( 'WooCommerce' ) ) {
			printf(
				'<a class="oc-htext__link oc-cart-link" href="%s">%s <span class="oc-cart-count">%d</span></a>',
				esc_url( wc_get_cart_url() ),
				esc_html__( 'Shopping cart', 'oc-theme' ),
				absint( WC()->cart->get_cart_contents_count() )
			);
		}

		echo '</nav>';
	}

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
		$oc_count = WC()->cart->get_cart_contents_count();

		$oc_cart_icons = array(
			'cart'   => '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="9" cy="20" r="1.6"/><circle cx="17" cy="20" r="1.6"/><path d="M3 3h2.5l2.2 11.2a1.6 1.6 0 0 0 1.6 1.3h7.6a1.6 1.6 0 0 0 1.6-1.3L20 7H6"/></svg>',
			'bag'    => '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 8h14l-1.2 12.2a1.8 1.8 0 0 1-1.8 1.6H8a1.8 1.8 0 0 1-1.8-1.6Z"/><path d="M8.5 10V6.5a3.5 3.5 0 0 1 7 0V10"/></svg>',
			'basket' => '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3.5 10h17l-1.6 9a2 2 0 0 1-2 1.6H7.1a2 2 0 0 1-2-1.6Z"/><path d="m8 10 3-6.5M16 10l-3-6.5"/><path d="M9.5 13.5v3.5M14.5 13.5v3.5"/></svg>',
			// The exact icon bonibrand's header uploads (bonicart.svg), inlined
			// with currentColor so the header colour settings reach it.
			'boni'   => '<svg viewBox="0 0 20 20" width="20" height="20" fill="none" stroke="currentColor" aria-hidden="true"><path d="M19 19H1V5H19V19Z" stroke-miterlimit="10" stroke-linecap="round"/><path d="M6 5V4.71746C6 2.66435 7.79085 0.999999 10 0.999999C12.2091 0.999999 14 2.66435 14 4.71746L14 5" stroke-miterlimit="10" stroke-linecap="round" stroke-linejoin="round"/></svg>',
			// The previous theme\'s default header cart (cart-icon.svg) — what
			// amox shows, as the site itself is behind bot protection.
			'amox'   => '<svg viewBox="0 0 20 20" width="20" height="20" fill="currentColor" aria-hidden="true"><path d="M7.90874 19.2748C6.81061 19.2748 5.91718 18.3814 5.91718 17.2832C5.91718 16.1851 6.81061 15.292 7.90874 15.292C9.00686 15.292 9.9003 16.1851 9.9003 17.2832C9.9003 18.3814 9.00686 19.2748 7.90874 19.2748ZM7.90874 16.542C7.49999 16.542 7.16718 16.8745 7.16718 17.2832C7.16718 17.6923 7.49968 18.0248 7.90874 18.0248C8.3178 18.0248 8.6503 17.6923 8.6503 17.2832C8.6503 16.8745 8.3178 16.542 7.90874 16.542Z"/><path d="M14.8319 19.2748C13.7338 19.2748 12.8406 18.3814 12.8406 17.2832C12.8406 16.1851 13.7338 15.292 14.8319 15.292C15.93 15.292 16.8234 16.1851 16.8234 17.2832C16.8234 18.3814 15.9297 19.2748 14.8319 19.2748ZM14.8319 16.542C14.4231 16.542 14.0906 16.8745 14.0906 17.2832C14.0906 17.6923 14.4231 18.0248 14.8319 18.0248C15.241 18.0248 15.5734 17.6923 15.5734 17.2832C15.5734 16.8745 15.2406 16.542 14.8319 16.542Z"/><path d="M16.29 13.7482H6.47156C5.51624 13.7482 4.70031 13.0978 4.48718 12.1666L2.44937 3.26753C2.42249 3.15003 2.34124 3.0494 2.23187 2.99878L0.541557 2.21534C0.150307 2.03409 -0.020006 1.56972 0.161244 1.17815C0.342494 0.786903 0.807182 0.616591 1.19843 0.797841L2.88874 1.58128C3.43343 1.83347 3.83874 2.33347 3.97249 2.91878L6.01031 11.8185C6.05968 12.0347 6.24937 12.186 6.47156 12.186H16.29C16.5112 12.186 16.7006 12.0357 16.7512 11.82L18.3356 5.00347C18.3809 4.80972 18.2984 4.66878 18.2456 4.60222C18.1925 4.53534 18.0737 4.42347 17.875 4.42347H6.31249C5.88093 4.42347 5.53124 4.07378 5.53124 3.64222C5.53124 3.21065 5.88093 2.86097 6.31249 2.86097H17.875C18.4997 2.86097 19.0803 3.14128 19.4691 3.63034C19.8578 4.1194 19.9991 4.74878 19.8578 5.35722L18.2731 12.1741C18.0572 13.1007 17.2419 13.7482 16.29 13.7482Z"/></svg>',
		);

		$oc_style = (string) get_theme_mod( 'oc_header_cart_icon', 'cart' );

		printf(
			'<a class="oc-hicon oc-cart-link" href="%s" aria-label="%s">%s<span class="oc-cart-count">%d</span></a>',
			esc_url( wc_get_cart_url() ),
			esc_attr__( 'Cart', 'oc-theme' ),
			$oc_cart_icons[ $oc_style ] ?? $oc_cart_icons['cart'], // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG.
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
