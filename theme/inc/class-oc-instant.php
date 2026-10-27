<?php
/**
 * Instant navigation: the next page is fetched — in Chromium, rendered —
 * while the finger is still on its way to the link, and pages change
 * places with a short cross-fade instead of a white flash.
 *
 * Three browser features, each harmless where it is not understood:
 *
 * - Speculation rules (Chromium 121+). WordPress prints them itself since
 *   6.8, in its most careful mode (prefetch, conservative); the theme
 *   turns the dial to prerender on "moderate" eagerness — a hover of
 *   200ms, or the first touch — and lists the pages that must never be
 *   rendered ahead: cart, checkout, account, anything that acts.
 * - Cross-document view transitions (Chromium 126+, Safari 18.2+): the old
 *   page stays on screen until the new one has painted, then fades.
 * - The scripts that count a visit (statistics, heat map, pixels, recently
 *   viewed) run only when the page is actually shown — theme.js's
 *   ocWhenShown — so a prerender nobody opened is not a visit. Server
 *   events skip speculative requests too (Marketing\Events::speculative).
 *
 * @package OC\Theme
 */

namespace OC\Theme;

/**
 * Instant navigation.
 */
final class Instant {

	/**
	 * Hooks.
	 */
	public function register(): void {
		add_filter( 'wp_speculation_rules_configuration', array( $this, 'configuration' ) );
		add_filter( 'wp_speculation_rules_href_exclude_paths', array( $this, 'exclude' ) );
		add_action( 'wp_head', array( $this, 'head' ), 3 );
		add_action( 'wp_footer', array( $this, 'rules_fallback' ), 90 );
	}

	/**
	 * Is the feature on for this request?
	 *
	 * @return bool
	 */
	public static function on(): bool {
		if ( is_admin() || is_customize_preview() ) {
			return false;
		}

		/**
		 * Lets a site switch instant navigation off in code.
		 *
		 * @param bool $on Whether the feature is on.
		 */
		return (bool) apply_filters( 'oc_instant_nav', (bool) get_theme_mod( 'oc_instant_nav', true ) );
	}

	/**
	 * Core's speculation rules: render the next page ahead of the click.
	 *
	 * @param mixed $config Core's configuration (mode, eagerness), or null.
	 * @return mixed
	 */
	public function configuration( $config ) {
		if ( ! self::on() ) {
			return $config;
		}

		return array(
			'mode'      => 'prerender',
			'eagerness' => 'moderate',
		);
	}

	/**
	 * Paths a browser must never fetch ahead of a click — the ones that act
	 * on the shopper's behalf or are theirs alone. WooCommerce adds its own
	 * when it knows the filter; these are the theme's and the shop's pages
	 * by their real (translated) slugs.
	 *
	 * @param mixed $paths Core's list.
	 * @return mixed
	 */
	public function exclude( $paths ) {
		if ( ! is_array( $paths ) ) {
			$paths = array();
		}

		return array_values( array_unique( array_merge( $paths, self::excluded_paths() ) ) );
	}

	/**
	 * The exclusion list (URL patterns, as the speculation rules read them).
	 *
	 * @return string[]
	 */
	public static function excluded_paths(): array {
		$paths = array(
			'/wp-admin/*',
			'/wp-login.php*',
			'/wp-json/*',
			'/ocadmin*',
			'/oc-feed/*',
			'/feed/*',
			'/*\\?*add-to-cart=*',
			'/*\\?*remove_item=*',
			'/*\\?*undo_item=*',
			'/*\\?*wc-ajax=*',
			'/*\\?*s=*',
			'/*\\?*oc_pay=*',
			'/*\\?*key=*',
			'/*\\?*_wpnonce=*',
		);

		if ( function_exists( 'wc_get_page_id' ) ) {
			foreach ( array( 'cart', 'checkout', 'myaccount' ) as $page ) {
				$id = (int) wc_get_page_id( $page );

				if ( $id > 0 ) {
					$path = (string) wp_parse_url( (string) get_permalink( $id ), PHP_URL_PATH );

					if ( '' !== $path && '/' !== $path ) {
						$paths[] = rtrim( $path, '/' ) . '/*';
						$paths[] = rtrim( $path, '/' );
					}
				}
			}
		}

		return $paths;
	}

	/**
	 * The cross-fade between documents, and the header that stays put.
	 */
	public function head(): void {
		if ( ! self::on() ) {
			return;
		}

		echo '<style id="oc-instant">@view-transition{navigation:auto}::view-transition-old(root),::view-transition-new(root){animation-duration:.16s}@media (prefers-reduced-motion:reduce){@view-transition{navigation:none}}</style>' . "\n";
	}

	/**
	 * Before WordPress 6.8 there is no core printer: the theme prints the
	 * same rules itself.
	 */
	public function rules_fallback(): void {
		if ( ! self::on() || function_exists( 'wp_print_speculation_rules' ) ) {
			return;
		}

		$where = array(
			'and' => array(
				array( 'href_matches' => '/*' ),
				array( 'not' => array( 'href_matches' => self::excluded_paths() ) ),
				array( 'not' => array( 'selector_matches' => '[rel~="nofollow"], [download], [target="_blank"], .no-prefetch, .no-prerender' ) ),
			),
		);

		$rules = array(
			'prerender' => array(
				array(
					'source'    => 'document',
					'where'     => $where,
					'eagerness' => 'moderate',
				),
			),
			'prefetch'  => array(
				array(
					'source'    => 'document',
					'where'     => $where,
					'eagerness' => 'moderate',
				),
			),
		);

		echo '<script type="speculationrules">' . wp_json_encode( $rules ) . '</script>' . "\n";
	}
}
