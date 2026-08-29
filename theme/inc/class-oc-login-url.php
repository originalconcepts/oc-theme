<?php
/**
 * OC Login address — the way in is /ocadmin, and wp-login.php is not home.
 *
 * Nearly every attack on a WordPress shop begins by posting guesses at
 * wp-login.php. Moving the door does not make a weak password strong, but it
 * takes the site off the list of things worth trying, and the bots that hammer
 * that one file all day get a redirect to the shop front instead.
 *
 * What happens where:
 *
 *   /ocadmin        serves the real login page
 *   /wp-login.php   goes to the shop front, except for the errands that must
 *                   keep working: signing out, and the link in a password
 *                   reset e-mail
 *   /wp-admin/...   goes to the shop front for anyone not signed in, instead
 *                   of the usual bounce to a login form that announces where
 *                   the login form is
 *
 * Signed-in staff carry on using /wp-admin exactly as before — only the door
 * moved, not the building.
 *
 * The slug can be changed with the `oc_login_slug` filter, or by putting
 * `define( 'OC_LOGIN_SLUG', 'something' );` in wp-config.php, which is also
 * the way back in if the slug is ever forgotten.
 *
 * @package OC_Theme
 */

declare( strict_types = 1 );

namespace OC\Theme;

defined( 'ABSPATH' ) || exit;

/**
 * The moved door.
 */
final class Login_Url {

	/**
	 * Where the login page answers, unless told otherwise.
	 */
	private const DEFAULT_SLUG = 'ocadmin';

	/**
	 * Errands still allowed at the old address.
	 *
	 * Signing out and the reset-password link both arrive as wp-login.php
	 * with an action, and neither of them accepts a password guess, so there
	 * is nothing gained by turning them away — and a great deal lost, since
	 * every reset e-mail already sent points there.
	 *
	 * @var string[]
	 */
	private const ERRANDS = array( 'logout', 'rp', 'resetpass', 'postpass', 'confirmaction' );

	/**
	 * Files under wp-admin that must answer for everyone.
	 *
	 * admin-ajax.php carries the shop's own front-end requests, so shutting
	 * it to signed-out visitors would break the cart.
	 *
	 * @var string[]
	 */
	private const OPEN = array( 'admin-ajax.php', 'admin-post.php' );

	/**
	 * Hook in.
	 */
	public function register(): void {
		// The way back in. Putting `define( 'OC_LOGIN_DISABLE', true );` in
		// wp-config.php hands wp-login.php back, for the day the slug is
		// forgotten or something here misbehaves.
		if ( defined( 'OC_LOGIN_DISABLE' ) && constant( 'OC_LOGIN_DISABLE' ) ) {
			return;
		}

		// Early enough to beat admin.php's own bounce to the login form.
		add_action( 'init', array( $this, 'route' ), 0 );

		add_filter( 'site_url', array( $this, 'filter_site_url' ), 10, 2 );
		add_filter( 'network_site_url', array( $this, 'filter_site_url' ), 10, 2 );
		add_filter( 'wp_redirect', array( $this, 'filter_plain' ), 10, 1 );
		add_filter( 'login_url', array( $this, 'filter_plain' ), 10, 1 );
		add_filter( 'logout_url', array( $this, 'filter_plain' ), 10, 1 );
		add_filter( 'lostpassword_url', array( $this, 'filter_plain' ), 10, 1 );
		add_filter( 'register_url', array( $this, 'filter_plain' ), 10, 1 );
	}

	/**
	 * The chosen slug.
	 */
	public static function slug(): string {
		$slug = defined( 'OC_LOGIN_SLUG' ) ? (string) constant( 'OC_LOGIN_SLUG' ) : self::DEFAULT_SLUG;

		/**
		 * Filters the address the login page answers on.
		 *
		 * @param string $slug The slug, with no slashes.
		 */
		$slug = (string) apply_filters( 'oc_login_slug', $slug );

		return trim( sanitize_title( $slug ) );
	}

	/**
	 * The full address of the login page.
	 */
	public static function url( string $query = '' ): string {
		$url = home_url( '/' . self::slug() . '/' );

		return $query ? $url . '?' . ltrim( $query, '?' ) : $url;
	}

	/**
	 * The path being asked for, relative to the site root.
	 */
	private function path(): string {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$uri = (string) wp_parse_url( $uri, PHP_URL_PATH );

		$home = (string) wp_parse_url( home_url(), PHP_URL_PATH );
		if ( $home && 0 === strpos( $uri, $home ) ) {
			$uri = substr( $uri, strlen( $home ) );
		}

		return trim( $uri, '/' );
	}

	/**
	 * Decide what this request is, and send it where it belongs.
	 */
	public function route(): void {
		$path = $this->path();
		$slug = self::slug();

		// The new door: serve the real login page from here.
		if ( $path === $slug ) {
			$this->serve_login();
			return;
		}

		// The old door.
		if ( 'wp-login.php' === $path ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading an action name to route on, nothing acted on.
			$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';

			if ( ! in_array( $action, self::ERRANDS, true ) ) {
				wp_safe_redirect( home_url( '/' ), 302 );
				exit;
			}

			return;
		}

		// The admin, for someone who is not signed in.
		if ( 0 === strpos( $path, 'wp-admin' ) && ! is_user_logged_in() ) {
			if ( in_array( wp_basename( $path ), self::OPEN, true ) ) {
				return;
			}

			wp_safe_redirect( home_url( '/' ), 302 );
			exit;
		}
	}

	/**
	 * Hand the request to WordPress's own login page.
	 */
	private function serve_login(): void {
		global $pagenow, $error, $interim_login, $action, $user_login;

		// Already signed in and just visiting the door: go on through.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading an action name only.
		$asked = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';

		if ( is_user_logged_in() && '' === $asked ) {
			wp_safe_redirect( admin_url(), 302 );
			exit;
		}

		// wp-login.php reads $pagenow and expects to be the page in hand.
		$pagenow = 'wp-login.php'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		// Silence the "you are already logged in" caching of a 404 and make
		// sure nothing has begun writing a page yet.
		if ( ! headers_sent() ) {
			nocache_headers();
			header( 'Content-Type: text/html; charset=' . get_bloginfo( 'charset' ) );
		}

		unset( $error, $interim_login, $action, $user_login );

		require_once ABSPATH . 'wp-login.php';
		exit;
	}

	/**
	 * Point generated URLs at the new door.
	 *
	 * @param string $url    The URL WordPress built.
	 * @param string $scheme The scheme it asked for.
	 */
	public function filter_site_url( $url, $scheme = null ) {
		unset( $scheme );

		return $this->swap( (string) $url );
	}

	/**
	 * The same, for filters that pass the URL alone.
	 *
	 * @param string $url The URL.
	 */
	public function filter_plain( $url ) {
		return $this->swap( (string) $url );
	}

	/**
	 * Rewrite wp-login.php to the chosen slug, keeping the query intact.
	 *
	 * Signing out is left pointing at wp-login.php: it is allowed there, and
	 * its nonce is built against that address.
	 *
	 * @param string $url The URL.
	 */
	private function swap( string $url ): string {
		if ( false === strpos( $url, 'wp-login.php' ) ) {
			return $url;
		}

		if ( false !== strpos( $url, 'action=logout' ) ) {
			return $url;
		}

		return str_replace( 'wp-login.php', self::slug(), $url );
	}
}
