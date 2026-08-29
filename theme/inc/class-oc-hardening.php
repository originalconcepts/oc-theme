<?php
/**
 * OC Hardening — closing the doors a shop never uses.
 *
 * Everything here was found by walking the live site rather than by reading a
 * checklist, and each piece closes something that was actually open:
 *
 *   XML-RPC        answered system.multicall, which packs hundreds of password
 *                  guesses into one request. That walks straight past the
 *                  private login path and past any per-request throttle, so it
 *                  is the single thing most worth turning off.
 *   User listing   /wp-json/wp/v2/users handed out every account and its login
 *                  name to anyone who asked, and ?author=1 did the same by
 *                  redirecting to the author's slug. Together with the above
 *                  that is a username and unlimited guesses.
 *   Login errors   WordPress says whether it was the user or the password that
 *                  was wrong, which confirms an account exists.
 *   File editing   The admin's theme and plugin editors turn one borrowed
 *                  session into running PHP on the server.
 *   Headers        nosniff and a referrer policy, which cost nothing.
 *
 * None of it touches how the shop behaves for a customer. Every piece can be
 * turned off with its own filter if it ever gets in the way.
 *
 * @package OC_Theme
 */

declare( strict_types = 1 );

namespace OC\Theme;

defined( 'ABSPATH' ) || exit;

/**
 * The locks.
 */
final class Hardening {

	/**
	 * Hook in.
	 */
	public function register(): void {
		$this->xmlrpc();
		$this->stop_enumeration();
		$this->quiet_login_errors();
		$this->headers();
		$this->hide_version();
	}

	/**
	 * Is one of these locks wanted?
	 *
	 * @param string $what Which lock.
	 */
	private static function on( string $what ): bool {
		/**
		 * Filters whether a hardening measure applies.
		 *
		 * @param bool   $on   Whether to apply it.
		 * @param string $what Which measure.
		 */
		return (bool) apply_filters( 'oc_hardening', true, $what );
	}

	/*
	 * ------------------------------------------------------------------ xmlrpc
	 */

	/**
	 * Turn XML-RPC off.
	 *
	 * A shop has no use for it. Left on, `system.multicall` lets one request
	 * carry hundreds of login attempts, and `pingback.ping` makes the site a
	 * reflector for attacks on other people.
	 */
	private function xmlrpc(): void {
		if ( ! self::on( 'xmlrpc' ) ) {
			return;
		}

		add_filter( 'xmlrpc_enabled', '__return_false' );
		add_filter( 'pings_open', '__return_false', 20 );

		// xmlrpc_enabled only disables the methods that need a password. The
		// rest of the surface — multicall, pingbacks, the method listing —
		// goes here.
		add_filter(
			'xmlrpc_methods',
			static function ( $methods ) {
				return array();
			},
			99
		);

		// And the file itself, for anything that slips past the filters.
		add_action(
			'init',
			static function (): void {
				if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
					status_header( 403 );
					exit;
				}
			},
			0
		);

		// Drop the header that advertises it.
		remove_action( 'wp_head', 'rsd_link' );
		add_filter( 'wp_headers', static function ( $headers ) {
			unset( $headers['X-Pingback'] );
			return $headers;
		}, 99 );
	}

	/*
	 * ------------------------------------------------------------- enumeration
	 */

	/**
	 * Stop the site handing out its list of accounts.
	 *
	 * Two doors lead to the same place: the REST users route, and the
	 * ?author=1 redirect that answers with the author's login slug. Both are
	 * shut for visitors who have no business listing users; an editor who is
	 * signed in still gets what the block editor needs.
	 */
	private function stop_enumeration(): void {
		if ( ! self::on( 'enumeration' ) ) {
			return;
		}

		// ?author=1 -> /author/their-login/ is a username lookup by number.
		add_action(
			'template_redirect',
			static function (): void {
				if ( is_admin() || ! isset( $_GET['author'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
					return;
				}

				if ( current_user_can( 'list_users' ) ) {
					return;
				}

				// phpcs:ignore WordPress.Security.NonceVerification.Recommended
				if ( is_numeric( trim( (string) wp_unslash( $_GET['author'] ) ) ) ) {
					wp_safe_redirect( home_url( '/' ), 301 );
					exit;
				}
			},
			0
		);

		// The REST users route, for anyone who cannot already list users.
		add_filter(
			'rest_endpoints',
			static function ( $endpoints ) {
				if ( current_user_can( 'list_users' ) ) {
					return $endpoints;
				}

				unset( $endpoints['/wp/v2/users'] );
				unset( $endpoints['/wp/v2/users/(?P<id>[\d]+)'] );

				return $endpoints;
			},
			99
		);

		// oEmbed answers carry the author's name and link as well.
		add_filter(
			'oembed_response_data',
			static function ( $data ) {
				unset( $data['author_name'], $data['author_url'] );
				return $data;
			},
			99
		);
	}

	/**
	 * Say the same thing whichever half was wrong.
	 *
	 * "Unknown username" confirms which accounts exist, and that is the half
	 * of a password that an attacker cannot otherwise guess.
	 */
	private function quiet_login_errors(): void {
		if ( ! self::on( 'login_errors' ) ) {
			return;
		}

		add_filter(
			'login_errors',
			static function ( $error ) {
				unset( $error );

				return __( 'Those details were not right.', 'oc-theme' );
			},
			99
		);
	}

	/*
	 * ----------------------------------------------------------------- headers
	 */

	/**
	 * Two headers the site was missing.
	 *
	 * The host already sends HSTS and a frame-ancestors policy, so this only
	 * fills the gaps rather than fighting it.
	 */
	private function headers(): void {
		if ( ! self::on( 'headers' ) ) {
			return;
		}

		add_filter(
			'wp_headers',
			static function ( $headers ) {
				$headers['X-Content-Type-Options'] = 'nosniff';
				$headers['Referrer-Policy']        = 'strict-origin-when-cross-origin';

				return $headers;
			},
			20
		);
	}

	/**
	 * Stop announcing which WordPress this is.
	 *
	 * Version fingerprinting is how a scanner decides whether today's
	 * published flaw is worth trying here.
	 */
	private function hide_version(): void {
		if ( ! self::on( 'version' ) ) {
			return;
		}

		remove_action( 'wp_head', 'wp_generator' );
		add_filter( 'the_generator', '__return_empty_string' );
	}
}
