<?php
/**
 * The login gate.
 *
 * Serves the login screen from a private path and makes wp-login.php answer
 * 404. Three things must keep working regardless, and each is handled below:
 * admin-ajax.php (the front end uses it), the REST API, and the flows that
 * legitimately post to wp-login.php — logout, password reset, protected-post
 * passwords and the interim login iframe.
 *
 * Lives in the theme, so it is only in force while the theme is active.
 * Switching themes restores wp-login.php, which fails safe rather than locking
 * anyone out.
 *
 * @package OC_Theme
 */

declare( strict_types = 1 );

namespace OC\Theme;

defined( 'ABSPATH' ) || exit;

/**
 * Rewrites login URLs and blocks the default one.
 */
final class Gate {

	/**
	 * Private login slug.
	 *
	 * @var string
	 */
	private string $slug;

	/**
	 * Whether this request is for the private login path.
	 *
	 * @var bool
	 */
	private bool $is_login_request = false;

	/**
	 * Store the private slug.
	 *
	 * @param string $slug Private login slug.
	 */
	public function __construct( string $slug ) {
		$this->slug = $slug;
	}

	/**
	 * Hook everything up.
	 *
	 * Ordering matters and is the reason this is split in two. Marking happens
	 * at `after_setup_theme` — a theme's functions.php is loaded after
	 * `plugins_loaded` has already fired, so that hook would never run here —
	 * and it only *marks* the request and neutralises the URI so WordPress does
	 * not route /ocadmin as a page and 404 it. The login screen itself loads at
	 * `wp_loaded`, once WordPress is fully booted; requiring wp-login.php any
	 * earlier makes it error. Both still run long before the request is routed.
	 */
	public function register(): void {
		add_action( 'after_setup_theme', array( $this, 'mark_request' ), 1 );
		add_action( 'wp_loaded', array( $this, 'handle' ) );

		// Anything WordPress prints, mails or redirects to must use the new path.
		add_filter( 'site_url', array( $this, 'filter_url' ), 10, 2 );
		add_filter( 'network_site_url', array( $this, 'filter_url' ), 10, 2 );
		add_filter( 'wp_redirect', array( $this, 'filter_redirect' ), 10, 1 );
		add_filter( 'lostpassword_url', array( $this, 'swap' ), 10, 1 );
		add_filter( 'login_url', array( $this, 'swap' ), 10, 1 );
		add_filter( 'logout_url', array( $this, 'swap' ), 10, 1 );
		add_filter( 'register_url', array( $this, 'swap' ), 10, 1 );
	}

	/**
	 * Classify the request before WordPress routes it.
	 */
	public function mark_request(): void {
		global $pagenow;

		$path = $this->request_path();

		if ( $path === $this->slug ) {
			$this->is_login_request = true;

			// Stop WordPress resolving /ocadmin as a page and 404ing it before
			// we get a chance to serve the login screen.
			$_SERVER['REQUEST_URI'] = '/' . str_repeat( '-/', 8 );
			$pagenow                = 'wp-login.php'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- required to serve the login screen from another path.

			return;
		}

		if ( 'wp-login.php' === $path && ! $this->is_permitted_login_action() ) {
			$this->not_found();
		}
	}

	/**
	 * Serve the login screen, or keep logged-out visitors out of the admin.
	 *
	 * Runs at `wp_loaded`, which is late enough for wp-login.php to behave.
	 */
	public function handle(): void {
		if ( $this->is_login_request ) {
			require_once ABSPATH . 'wp-login.php';
			exit;
		}

		// A logged-out visitor asking for /wp-admin gets a 404 rather than a
		// redirect that would point straight at the private path.
		if ( is_admin() && ! is_user_logged_in() && ! wp_doing_ajax() && ! wp_doing_cron() ) {
			if ( ! ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
				$this->not_found();
			}
		}
	}

	/**
	 * Flows that must still reach wp-login.php directly.
	 *
	 * Logout links carry a nonce; password resets arrive from an emailed link;
	 * protected posts post their password here; the interim login is the modal
	 * WordPress shows when a session expires mid-edit.
	 *
	 * @return bool
	 */
	private function is_permitted_login_action(): bool {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- reading a routing flag, not acting on it.
		$action  = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
		$interim = isset( $_REQUEST['interim-login'] );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$allowed = array( 'logout', 'postpass', 'rp', 'resetpass', 'lostpassword' );

		/**
		 * Filter the actions allowed to reach wp-login.php directly.
		 *
		 * @param string[] $allowed Action names.
		 */
		$allowed = (array) apply_filters( 'oc_login_permitted_actions', $allowed );

		return $interim || in_array( $action, $allowed, true );
	}

	/**
	 * Send a genuine 404 rather than a redirect, so a scanner learns nothing.
	 */
	private function not_found(): void {
		status_header( 404 );
		nocache_headers();

		global $wp_query;
		if ( $wp_query instanceof \WP_Query ) {
			$wp_query->set_404();
		}

		// The gate can fire as early as `after_setup_theme`, long before
		// WooCommerce has booted. The theme's 404 draws the header, which asks
		// WooCommerce for the cart — on an early request that call lands on a
		// cart that does not exist yet and the request dies with a fatal. A
		// scanner gets the bare page instead; a visitor, who always arrives
		// after WooCommerce is up, still gets the designed one.
		$ready    = ! class_exists( 'WooCommerce' ) || did_action( 'woocommerce_init' );
		$template = $ready ? get_404_template() : '';

		if ( $template && file_exists( $template ) ) {
			require $template;
		} else {
			echo '<!doctype html><meta charset="utf-8"><title>404</title><h1>404</h1>';
		}

		exit;
	}

	/**
	 * Swap wp-login.php for the private slug inside a URL.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public function swap( $url ): string {
		if ( ! is_string( $url ) || '' === $url ) {
			return (string) $url;
		}

		return str_replace( 'wp-login.php', $this->slug, $url );
	}

	/**
	 * Rewrite login URLs produced by site_url()/network_site_url().
	 *
	 * @param string $url  Full URL.
	 * @param string $path Requested path.
	 * @return string
	 */
	public function filter_url( $url, $path ): string {
		$url = (string) $url;

		if ( is_string( $path ) && str_contains( $path, 'wp-login.php' ) ) {
			return $this->swap( $url );
		}

		return $url;
	}

	/**
	 * Rewrite redirects that would send a visitor to wp-login.php.
	 *
	 * @param string $location Redirect target.
	 * @return string
	 */
	public function filter_redirect( $location ): string {
		return $this->swap( (string) $location );
	}

	/**
	 * First path segment of the current request, without query string.
	 *
	 * @return string
	 */
	private function request_path(): string {
		$uri = isset( $_SERVER['REQUEST_URI'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) )
			: '';

		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		$home = (string) wp_parse_url( home_url(), PHP_URL_PATH );

		// Support installs in a subdirectory.
		if ( '' !== $home && '/' !== $home && str_starts_with( $path, $home ) ) {
			$path = substr( $path, strlen( $home ) );
		}

		return trim( $path, '/' );
	}
}
