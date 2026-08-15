<?php
/**
 * Plugin Name: OC Login
 * Plugin URI:  https://github.com/originalconcepts/oc-theme
 * Description: Moves the login screen off wp-login.php to a private path. Cuts automated bot traffic; it is not a substitute for strong passwords or two-factor.
 * Version:     0.1.0
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Author:      Original Concepts
 * License:     GPL-2.0-or-later
 * Text Domain: oc-login
 *
 * @package OC_Login
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

define( 'OC_LOGIN_VERSION', '0.1.0' );

/**
 * The login slug.
 *
 * Override per site in wp-config.php:
 *     define( 'OC_LOGIN_SLUG', 'my-private-door' );
 *
 * Emergency escape — restores wp-login.php without touching files:
 *     define( 'OC_LOGIN_DISABLE', true );
 */
if ( ! defined( 'OC_LOGIN_SLUG' ) ) {
	define( 'OC_LOGIN_SLUG', 'ocadmin' );
}

require_once __DIR__ . '/includes/class-oc-login.php';

add_action(
	'plugins_loaded',
	static function (): void {
		if ( defined( 'OC_LOGIN_DISABLE' ) && OC_LOGIN_DISABLE ) {
			return;
		}

		$slug = (string) apply_filters( 'oc_login_slug', OC_LOGIN_SLUG );
		$slug = trim( sanitize_title( $slug ), '/' );

		if ( '' === $slug ) {
			return;
		}

		( new OC\Login\Gate( $slug ) )->register();
	},
	1
);
