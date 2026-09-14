<?php
/**
 * What this visitor has agreed to, and what the law where they are asks
 * the site to do before they answer.
 *
 * The answer lives in one cookie the browser writes: a small JSON with a
 * flag per category, the moment it was given, a random id the consent log
 * can be matched against, and the policy version it was given for. The
 * server reads it for what it needs on the way out — a marketing tag it
 * must not print, a snippet it must hold back — and the script does the
 * rest in the browser, where a cached page can still tell visitors apart.
 *
 * @package OC\Theme
 */

declare( strict_types = 1 );

namespace OC\Theme\Privacy;

if ( ! defined( 'ABSPATH' ) && ! defined( 'OC_TESTS' ) ) {
	exit;
}

/**
 * Consent state and regional mode.
 */
final class Consent {

	const COOKIE = 'oc_consent';

	/**
	 * Countries where nothing non-essential runs before a yes: the EEA,
	 * the UK and Switzerland.
	 *
	 * @var string[]
	 */
	const OPT_IN = array( 'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE', 'GR', 'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL', 'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE', 'IS', 'LI', 'NO', 'GB', 'CH' );

	/**
	 * The stored answer, parsed: ['preferences' => bool, 'analytics' =>
	 * bool, 'marketing' => bool, 'id' => string, 't' => string, 'pv' =>
	 * string], or null when the visitor has not answered (or answered an
	 * older policy).
	 *
	 * @param string $raw   The cookie's raw value (already unslashed).
	 * @param string $pv    The policy version the answer must match; ''
	 *                      accepts any.
	 * @return array<string,mixed>|null
	 */
	public static function parse( string $raw, string $pv = '' ): ?array {
		$raw = trim( rawurldecode( $raw ) );

		if ( '' === $raw ) {
			return null;
		}

		// The first banner stored a bare word. Honour it as all-or-nothing
		// until the visitor answers the new one.
		if ( 'granted' === $raw || 'denied' === $raw ) {
			$yes = 'granted' === $raw;

			return array(
				'preferences' => $yes,
				'analytics'   => $yes,
				'marketing'   => $yes,
				'id'          => '',
				't'           => '',
				'pv'          => '',
				'legacy'      => true,
			);
		}

		$d = json_decode( $raw, true );

		if ( ! is_array( $d ) || ! isset( $d['v'] ) ) {
			return null;
		}

		if ( '' !== $pv && (string) ( $d['pv'] ?? '' ) !== $pv ) {
			return null;
		}

		return array(
			'preferences' => ! empty( $d['p'] ),
			'analytics'   => ! empty( $d['a'] ),
			'marketing'   => ! empty( $d['m'] ),
			'id'          => preg_replace( '/[^a-z0-9-]/i', '', (string) ( $d['id'] ?? '' ) ),
			't'           => (string) ( $d['t'] ?? '' ),
			'pv'          => (string) ( $d['pv'] ?? '' ),
			'legacy'      => false,
		);
	}

	/**
	 * This request's answer, or null.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function stored(): ?array {
		if ( ! isset( $_COOKIE[ self::COOKIE ] ) ) {
			return null;
		}

		return self::parse( (string) wp_unslash( $_COOKIE[ self::COOKIE ] ), Settings::policy_version() ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- parse() validates every field.
	}

	/**
	 * May something in this category run for this request?
	 *
	 * With no answer stored the mode decides: opt-out lets it run, opt-in
	 * holds it. Used on the server for what the server prints; the
	 * browser makes the same call for a cached page.
	 *
	 * @param string $cat Category.
	 */
	public static function allows( string $cat ): bool {
		if ( ! Settings::on() ) {
			return true;
		}

		$s = Settings::get();

		if ( empty( $s['cats'][ $cat ]['on'] ) ) {
			// A category the site does not ask about is not gated.
			return true;
		}

		$stored = self::stored();

		if ( null !== $stored ) {
			return ! empty( $stored[ $cat ] );
		}

		return 'optout' === self::mode();
	}

	/**
	 * What an undecided visitor starts with here: 'optin' (nothing until
	 * accepted) or 'optout' (everything until declined).
	 */
	public static function mode(): string {
		$mode = (string) Settings::get()['mode'];

		if ( 'auto' !== $mode ) {
			return $mode;
		}

		return self::mode_for( self::country() );
	}

	/**
	 * The mode a country gets under 'auto'. Unknown is treated as opt-out:
	 * the site's own market is Israel, and the browser holds the tags back
	 * anyway until the region answer arrives.
	 *
	 * @param string $country Two letters, or ''.
	 */
	public static function mode_for( string $country ): string {
		return in_array( strtoupper( $country ), self::OPT_IN, true ) ? 'optin' : 'optout';
	}

	/**
	 * Where the visitor is, by the platform's header or WooCommerce's
	 * lookup; '' when unknown.
	 */
	public static function country(): string {
		if ( ! empty( $_SERVER['HTTP_CF_IPCOUNTRY'] ) ) {
			return strtoupper( substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_IPCOUNTRY'] ) ), 0, 2 ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- two letters, sanitized.
		}

		if ( class_exists( '\WC_Geolocation' ) ) {
			$geo = \WC_Geolocation::geolocate_ip( '', true, false );

			return strtoupper( (string) ( $geo['country'] ?? '' ) );
		}

		return '';
	}
}
