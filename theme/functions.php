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

define( 'OC_THEME_VERSION', '0.3.61' );
define( 'OC_THEME_DIR', get_template_directory() );
define( 'OC_THEME_URI', get_template_directory_uri() );
define( 'OC_THEME_REPO', 'originalconcepts/oc-theme' );

/**
 * No editing theme or plugin PHP from inside the admin.
 *
 * The built-in editors turn one borrowed admin session into running code on
 * the server, and this theme is deployed from git, so nobody needs them.
 * Override in wp-config.php if a site ever genuinely does.
 */
if ( ! defined( 'DISALLOW_FILE_EDIT' ) ) {
	define( 'DISALLOW_FILE_EDIT', true );
}

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

/**
 * A warm, time-of-day greeting for the account areas — no comma, a light
 * emoji. Uses the site's timezone (a single-timezone Israeli store), so the
 * server hour matches the shopper's.
 *
 * @param string $name First name (may be empty).
 * @return string
 */
function oc_greeting( string $name ): string {
	$hour = (int) current_time( 'G' );

	if ( $hour >= 5 && $hour < 12 ) {
		$text  = __( 'Good morning', 'oc-theme' );
		$emoji = '☀️';
	} elseif ( $hour >= 12 && $hour < 17 ) {
		$text  = __( 'Good afternoon', 'oc-theme' );
		$emoji = '🌤️';
	} elseif ( $hour >= 17 && $hour < 22 ) {
		$text  = __( 'Good evening', 'oc-theme' );
		$emoji = '🌆';
	} else {
		$text  = __( 'Good night', 'oc-theme' );
		$emoji = '🌙';
	}

	$name = trim( $name );

	return trim( '' !== $name ? $text . ' ' . $name : $text ) . ' ' . $emoji;
}

require_once OC_THEME_DIR . '/inc/class-oc-updater.php';
require_once OC_THEME_DIR . '/inc/class-oc-assets.php';
require_once OC_THEME_DIR . '/inc/class-oc-login.php';
require_once OC_THEME_DIR . '/inc/class-oc-login-screen.php';
require_once OC_THEME_DIR . '/inc/class-oc-woocommerce.php';
require_once OC_THEME_DIR . '/inc/shipping/class-oc-shipping-quote.php';
require_once OC_THEME_DIR . '/inc/shipping/class-oc-shipping-rules.php';
require_once OC_THEME_DIR . '/inc/class-oc-shipping.php';
require_once OC_THEME_DIR . '/inc/shipping/class-oc-shipping-engage.php';
require_once OC_THEME_DIR . '/inc/shipping/class-oc-shipping-admin.php';
require_once OC_THEME_DIR . '/inc/marketing/class-oc-marketing-settings.php';
require_once OC_THEME_DIR . '/inc/marketing/class-oc-marketing-payload.php';
require_once OC_THEME_DIR . '/inc/marketing/class-oc-marketing-events.php';
require_once OC_THEME_DIR . '/inc/marketing/class-oc-marketing-dispatch.php';
require_once OC_THEME_DIR . '/inc/marketing/class-oc-marketing-consent.php';
require_once OC_THEME_DIR . '/inc/marketing/class-oc-marketing-page.php';
require_once OC_THEME_DIR . '/inc/marketing/class-oc-marketing-admin.php';
require_once OC_THEME_DIR . '/inc/class-oc-marketing.php';
require_once OC_THEME_DIR . '/inc/feeds/class-oc-feeds.php';
require_once OC_THEME_DIR . '/inc/feeds/class-oc-feeds-build.php';
require_once OC_THEME_DIR . '/inc/feeds/class-oc-feeds-admin.php';
require_once OC_THEME_DIR . '/inc/class-oc-product-linked.php';
require_once OC_THEME_DIR . '/inc/class-oc-bought-together.php';
require_once OC_THEME_DIR . '/inc/class-oc-customizer.php';
require_once OC_THEME_DIR . '/inc/class-oc-variations.php';
require_once OC_THEME_DIR . '/inc/class-oc-video.php';
require_once OC_THEME_DIR . '/inc/class-oc-waitlist.php';
require_once OC_THEME_DIR . '/inc/class-oc-filters.php';
require_once OC_THEME_DIR . '/inc/class-oc-tabs.php';
require_once OC_THEME_DIR . '/inc/class-oc-catalog.php';
require_once OC_THEME_DIR . '/inc/class-oc-category.php';
require_once OC_THEME_DIR . '/inc/class-oc-order-print.php';
require_once OC_THEME_DIR . '/inc/class-oc-hardening.php';
require_once OC_THEME_DIR . '/inc/class-oc-media-clean.php';
require_once OC_THEME_DIR . '/inc/class-oc-media-clean-admin.php';
require_once OC_THEME_DIR . '/inc/class-oc-webp-admin.php';
require_once OC_THEME_DIR . '/inc/class-oc-menu.php';
require_once OC_THEME_DIR . '/inc/class-oc-menu-panel.php';
require_once OC_THEME_DIR . '/inc/class-oc-menu-admin.php';
require_once OC_THEME_DIR . '/inc/class-oc-blocks.php';
require_once OC_THEME_DIR . '/inc/class-oc-cart.php';
require_once OC_THEME_DIR . '/inc/class-oc-addresses.php';
require_once OC_THEME_DIR . '/inc/class-oc-checkout.php';
require_once OC_THEME_DIR . '/inc/class-oc-announce.php';
require_once OC_THEME_DIR . '/inc/class-oc-contact.php';
require_once OC_THEME_DIR . '/inc/class-oc-thankyou.php';
require_once OC_THEME_DIR . '/inc/class-oc-performance.php';
require_once OC_THEME_DIR . '/inc/class-oc-search-index.php';
require_once OC_THEME_DIR . '/inc/class-oc-search.php';
require_once OC_THEME_DIR . '/inc/class-oc-brands.php';
require_once OC_THEME_DIR . '/inc/class-oc-blog.php';
require_once OC_THEME_DIR . '/inc/class-oc-search-panel.php';
require_once OC_THEME_DIR . '/inc/class-oc-search-admin.php';
require_once OC_THEME_DIR . '/inc/class-oc-redirects.php';
require_once OC_THEME_DIR . '/inc/class-oc-redirects-admin.php';
require_once OC_THEME_DIR . '/inc/class-oc-seo.php';
require_once OC_THEME_DIR . '/inc/class-oc-seo-alt.php';
require_once OC_THEME_DIR . '/inc/class-oc-seo-links.php';
require_once OC_THEME_DIR . '/inc/class-oc-seo-admin.php';
require_once OC_THEME_DIR . '/inc/class-oc-auth.php';
require_once OC_THEME_DIR . '/inc/class-oc-auth-admin.php';
require_once OC_THEME_DIR . '/inc/class-oc-2fa.php';

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
 * Prefer the minified build of an asset — but only when it is at least as
 * new as its source. A stale .min (someone edited the source and skipped
 * scripts/minify.py) must never ship: slower is acceptable, broken is not.
 *
 * @param string $relative Source path relative to the theme root.
 * @return string The path to enqueue.
 */
function oc_asset_min( string $relative ): string {
	$min = preg_replace( '/\.(js|css)$/', '.min.$1', $relative );

	$src_path = OC_THEME_DIR . $relative;
	$min_path = OC_THEME_DIR . $min;

	if ( file_exists( $min_path ) && file_exists( $src_path ) && filemtime( $min_path ) >= filemtime( $src_path ) ) {
		return $min;
	}

	return $relative;
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
			'primary'      => __( 'Primary menu', 'oc-theme' ),
			'secondary'    => __( 'Header side menu', 'oc-theme' ),
			'topbar'       => __( 'Top bar menu', 'oc-theme' ),
			'footer'       => __( 'Footer menu', 'oc-theme' ),
			'footer-col-1' => __( 'Footer column 1', 'oc-theme' ),
			'footer-col-2' => __( 'Footer column 2', 'oc-theme' ),
			'footer-col-3' => __( 'Footer column 3', 'oc-theme' ),
			'footer-col-4' => __( 'Footer column 4', 'oc-theme' ),
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
				'<a class="oc-htext__link" href="%s"%s>%s</a>',
				esc_url( wc_get_page_permalink( 'myaccount' ) ),
				apply_filters( 'oc_header_account_attrs', '' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- attribute string built by the theme.
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
			'<form role="search" method="get" class="oc-hsearch" action="%s"><input type="search" name="s" placeholder="%s" autocomplete="off" data-oc-search-field />%s<button type="submit" aria-label="%s">%s</button><span class="oc-hsearch__sep" aria-hidden="true" hidden data-oc-search-typed></span><button type="button" class="oc-hsearch__close" hidden data-oc-search-typed data-oc-search-reset aria-label="%s">%s</button></form>',
			esc_url( home_url( '/' ) ),
			esc_attr__( 'Search…', 'oc-theme' ),
			class_exists( 'WooCommerce' ) ? '<input type="hidden" name="post_type" value="product" />' : '',
			esc_attr__( 'Search', 'oc-theme' ),
			'<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.8-3.8"/></svg>',
			esc_attr__( 'Clear and close', 'oc-theme' ),
			'<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>'
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
			'<a class="oc-hicon oc-account-link" href="%s" aria-label="%s"%s>%s</a>',
			esc_url( wc_get_page_permalink( 'myaccount' ) ),
			esc_attr__( 'My account', 'oc-theme' ),
			apply_filters( 'oc_header_account_attrs', '' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- attribute string built by the theme.
			'<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 20c1.8-3.4 4.6-5 8-5s6.2 1.6 8 5"/></svg>'
		);
	}

	if ( class_exists( 'WooCommerce' ) && get_theme_mod( 'oc_header_cart', true ) ) {
		// The cart is missing on any request that renders the theme before
		// WooCommerce has booted; the header must not die over a counter.
		$oc_cart  = function_exists( 'WC' ) && WC()->cart ? WC()->cart : null;
		$oc_count = $oc_cart ? $oc_cart->get_cart_contents_count() : 0;

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
( new OC\Theme\Shipping() )->register();
( new OC\Theme\Shipping_Admin() )->register();
( new OC\Theme\Marketing() )->register();
( new OC\Theme\Feeds\Feeds() )->register();
( new OC\Theme\Feeds\Admin() )->register();
( new OC\Theme\Marketing_Admin() )->register();
( new OC\Theme\Customizer() )->register();
( new OC\Theme\Variations() )->register();
( new OC\Theme\Video() )->register();
( new OC\Theme\Waitlist() )->register();
( new OC\Theme\Filters() )->register();
( new OC\Theme\Tabs() )->register();
( new OC\Theme\Catalog() )->register();
( new OC\Theme\Category() )->register();
( new OC\Theme\Order_Print() )->register();
( new OC\Theme\Media_Clean() )->register();
( new OC\Theme\Hardening() )->register();
( new OC\Theme\Menu() )->register();
( new OC\Theme\Menu_Admin() )->register();
( new OC\Theme\Blocks() )->register();
( new OC\Theme\Cart() )->register();
( new OC\Theme\Checkout() )->register();
( new OC\Theme\Announce() )->register();
( new OC\Theme\Contact() )->register();
( new OC\Theme\Thankyou() )->register();
( new OC\Theme\Performance() )->register();
( new OC\Theme\Search() )->register();
( new OC\Theme\Search_Admin() )->register();
( new OC\Theme\Brands() )->register();
( new OC\Theme\Blog() )->register();
( new OC\Theme\Redirects() )->register();
( new OC\Theme\Redirects_Admin() )->register();
( new OC\Theme\Seo() )->register();
( new OC\Theme\Seo_Links() )->register();
( new OC\Theme\Seo_Alt() )->register();
( new OC\Theme\Seo_Admin() )->register();
( new OC\Theme\Auth() )->register();
( new OC\Theme\Auth_Admin() )->register();
( new OC\Theme\Two_Factor() )->register();
( new OC\Theme\Login_Screen() )->register();
( new OC\Theme\Product_Linked() )->register();
( new OC\Theme\Bought_Together() )->register();
( new OC\Theme\Updater( get_template(), OC_THEME_VERSION, OC_THEME_REPO ) )->register();

// The blocks plugin ships in the same release and had no updater at all, so
// it was the one piece that always had to be installed by hand. The theme
// registers it: the two travel together, and a site whose theme is inactive
// has nothing for the plugin to update into anyway.
// Registered straight away, not on plugins_loaded: plugins load before
// themes, so that hook has already fired by the time a theme's functions.php
// runs and the callback would never be called. The constants it needs are
// defined for the same reason -- the plugin is already in memory.
if ( defined( 'OC_BLOCKS_VERSION' ) && defined( 'OC_BLOCKS_DIR' ) ) {
	$oc_blocks_file = plugin_basename( OC_BLOCKS_DIR . 'oc-blocks.php' );

	( new OC\Theme\Updater( dirname( $oc_blocks_file ), OC_BLOCKS_VERSION, OC_THEME_REPO, 'plugin', $oc_blocks_file ) )->register();

	unset( $oc_blocks_file );
}

if ( ! defined( 'OC_LOGIN_DISABLE' ) || ! OC_LOGIN_DISABLE ) {
	$oc_login_slug = trim( sanitize_title( (string) apply_filters( 'oc_login_slug', OC_LOGIN_SLUG ) ), '/' );

	if ( '' !== $oc_login_slug ) {
		( new OC\Theme\Gate( $oc_login_slug ) )->register();
	}
}
