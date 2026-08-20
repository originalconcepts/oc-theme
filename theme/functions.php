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
require_once OC_THEME_DIR . '/inc/class-oc-variations.php';
require_once OC_THEME_DIR . '/inc/class-oc-video.php';
require_once OC_THEME_DIR . '/inc/class-oc-waitlist.php';
require_once OC_THEME_DIR . '/inc/class-oc-filters.php';
require_once OC_THEME_DIR . '/inc/class-oc-tabs.php';
require_once OC_THEME_DIR . '/inc/class-oc-cart.php';
require_once OC_THEME_DIR . '/inc/class-oc-checkout.php';

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
	add_theme_support(
		'custom-logo',
		array(
			'height'      => 250,
			'width'       => 600,
			'flex-height' => true,
			'flex-width'  => true,
		)
	);
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

	if ( get_theme_mod( 'oc_header_search', true ) && 'field' === get_theme_mod( 'oc_header_search_style', 'icon' ) ) {
		printf(
			'<form role="search" method="get" class="oc-hsearch" action="%s"><input type="search" name="s" placeholder="%s" />%s<button type="submit" aria-label="%s">%s</button></form>',
			esc_url( home_url( '/' ) ),
			esc_attr__( 'Search…', 'oc-theme' ),
			class_exists( 'WooCommerce' ) ? '<input type="hidden" name="post_type" value="product" />' : '',
			esc_attr__( 'Search', 'oc-theme' ),
			'<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.8-3.8"/></svg>'
		);
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

		printf(
			'<a class="oc-hicon oc-cart-link" href="%s" aria-label="%s">%s<span class="oc-cart-count">%d</span></a>',
			esc_url( wc_get_cart_url() ),
			esc_attr__( 'Cart', 'oc-theme' ),
			oc_cart_icon_svg(), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG.
			absint( $oc_count )
		);
	}
}

/**
 * The site's configured cart icon — one source for the header, the checkout
 * brand row, and anywhere else the cart shows its face.
 */
function oc_cart_icon_svg(): string {
	$oc_cart_icons = array(
			'cart'   => '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="9" cy="20" r="1.6"/><circle cx="17" cy="20" r="1.6"/><path d="M3 3h2.5l2.2 11.2a1.6 1.6 0 0 0 1.6 1.3h7.6a1.6 1.6 0 0 0 1.6-1.3L20 7H6"/></svg>',
			'bag'    => '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 8h14l-1.2 12.2a1.8 1.8 0 0 1-1.8 1.6H8a1.8 1.8 0 0 1-1.8-1.6Z"/><path d="M8.5 10V6.5a3.5 3.5 0 0 1 7 0V10"/></svg>',
			'basket' => '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3.5 10h17l-1.6 9a2 2 0 0 1-2 1.6H7.1a2 2 0 0 1-2-1.6Z"/><path d="m8 10 3-6.5M16 10l-3-6.5"/><path d="M9.5 13.5v3.5M14.5 13.5v3.5"/></svg>',
			// bonibrand's square bag (bonicart.svg), redrawn at the set's stroke
			// weight so all five icons match.
			'boni'   => '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20.5 20.5h-17V7h17Z"/><path d="M8.6 7v-.4a3.4 3.4 0 0 1 6.8 0V7"/></svg>',
			// amox's dome-handle bag (from the live header, via the site archive),
			// redrawn at the set's stroke weight.
			'amox'   => '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4.4 8.2h15.2l-1.4 10.9a2 2 0 0 1-2 1.7H7.8a2 2 0 0 1-2-1.7Z"/><path d="M7.5 8.2a4.5 4.5 0 0 1 9 0"/></svg>',
		);

	$oc_style = (string) get_theme_mod( 'oc_header_cart_icon', 'cart' );

	return $oc_cart_icons[ $oc_style ] ?? $oc_cart_icons['cart'];
}
add_action( 'oc_header_icons', 'oc_header_icons_render' );

/**
 * SVG media: preview in the media modal (core shows a blank file icon) and
 * uploads for administrators.
 *
 * @param array    $response   Attachment data for the JS media views.
 * @param \WP_Post $attachment Attachment post.
 * @return array
 */
function oc_svg_media_preview( array $response, $attachment ): array {
	if ( 'image/svg+xml' === ( $response['mime'] ?? '' ) && empty( $response['sizes'] ) ) {
		$url = (string) wp_get_attachment_url( $attachment->ID );

		$response['image'] = array(
			'src'    => $url,
			'width'  => 300,
			'height' => 150,
		);
		$response['thumb'] = $response['image'];
		$response['sizes'] = array(
			'full'      => array(
				'url'         => $url,
				'width'       => 300,
				'height'      => 150,
				'orientation' => 'landscape',
			),
			'thumbnail' => array(
				'url'         => $url,
				'width'       => 150,
				'height'      => 150,
				'orientation' => 'portrait',
			),
		);
	}

	return $response;
}
add_filter( 'wp_prepare_attachment_for_js', 'oc_svg_media_preview', 10, 2 );

/**
 * Let administrators upload SVG logos.
 *
 * @param array $mimes Allowed mime types.
 * @return array
 */
function oc_svg_mimes( array $mimes ): array {
	if ( current_user_can( 'manage_options' ) ) {
		$mimes['svg'] = 'image/svg+xml';
	}

	return $mimes;
}
add_filter( 'upload_mimes', 'oc_svg_mimes' );

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
( new OC\Theme\Variations() )->register();
( new OC\Theme\Video() )->register();
( new OC\Theme\Waitlist() )->register();
( new OC\Theme\Filters() )->register();
( new OC\Theme\Tabs() )->register();
( new OC\Theme\Cart() )->register();
( new OC\Theme\Checkout() )->register();
( new OC\Theme\Updater( get_template(), OC_THEME_VERSION, OC_THEME_REPO ) )->register();

if ( ! defined( 'OC_LOGIN_DISABLE' ) || ! OC_LOGIN_DISABLE ) {
	$oc_login_slug = trim( sanitize_title( (string) apply_filters( 'oc_login_slug', OC_LOGIN_SLUG ) ), '/' );

	if ( '' !== $oc_login_slug ) {
		( new OC\Theme\Gate( $oc_login_slug ) )->register();
	}
}
