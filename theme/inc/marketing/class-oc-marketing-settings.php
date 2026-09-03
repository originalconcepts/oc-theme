<?php
/**
 * Marketing settings: which networks, which IDs, what to track.
 *
 * One option, versioned. The screen edits it; the page, the browser
 * script and the server dispatcher read it. Nothing here talks to a
 * network — this is the address book.
 *
 * @package OC\Theme
 */

declare( strict_types = 1 );

namespace OC\Theme\Marketing;

if ( ! defined( 'ABSPATH' ) && ! defined( 'OC_TESTS' ) ) {
	exit;
}

/**
 * Loads, shapes and stores the settings.
 */
final class Settings {

	const OPTION  = 'oc_marketing';
	const VERSION = 1;

	/**
	 * Read once per request.
	 *
	 * @var array<string,mixed>|null
	 */
	private static $cached = null;

	/**
	 * The settings, whole and typed.
	 *
	 * @return array<string,mixed>
	 */
	public static function get(): array {
		if ( null !== self::$cached ) {
			return self::$cached;
		}

		$raw          = get_option( self::OPTION, array() );
		self::$cached = self::normalize( is_array( $raw ) ? $raw : array() );

		return self::$cached;
	}

	/**
	 * Store, normalized.
	 *
	 * @param array<string,mixed> $s Settings.
	 */
	public static function save( array $s ): void {
		update_option( self::OPTION, self::normalize( $s ), false );
		self::$cached = null;
	}

	/**
	 * Is tracking on, with at least one network to talk to?
	 */
	public static function live(): bool {
		$s = self::get();

		return ! empty( $s['enabled'] ) && self::any( $s );
	}

	/**
	 * Any network configured at all?
	 *
	 * @param array<string,mixed> $s Settings.
	 */
	public static function any( array $s ): bool {
		return '' !== $s['fb']['pixel'] || '' !== $s['ga4']['id'] || '' !== $s['gads']['id'] || '' !== $s['gtm']['id'] || '' !== $s['tiktok']['pixel'];
	}

	/**
	 * Every field present and typed.
	 *
	 * @param array<string,mixed> $raw Raw.
	 * @return array<string,mixed>
	 */
	public static function normalize( array $raw ): array {
		$str = static function ( $v ): string {
			return trim( (string) $v );
		};

		$fb      = (array) ( $raw['fb'] ?? array() );
		$ga4     = (array) ( $raw['ga4'] ?? array() );
		$gads    = (array) ( $raw['gads'] ?? array() );
		$gtm     = (array) ( $raw['gtm'] ?? array() );
		$tiktok  = (array) ( $raw['tiktok'] ?? array() );
		$events  = (array) ( $raw['events'] ?? array() );
		$consent = (string) ( $raw['consent'] ?? 'auto' );

		return array(
			'version' => self::VERSION,
			'enabled' => ! empty( $raw['enabled'] ),
			'consent' => in_array( $consent, array( 'off', 'optout', 'optin', 'auto' ), true ) ? $consent : 'auto',
			'fb'      => array(
				'pixel' => preg_replace( '/\D+/', '', $str( $fb['pixel'] ?? '' ) ),
				'token' => $str( $fb['token'] ?? '' ),
				'test'  => $str( $fb['test'] ?? '' ),
			),
			'ga4'     => array(
				'id'     => strtoupper( $str( $ga4['id'] ?? '' ) ),
				'secret' => $str( $ga4['secret'] ?? '' ),
			),
			'gads'    => array(
				'id'    => strtoupper( $str( $gads['id'] ?? '' ) ),
				'label' => $str( $gads['label'] ?? '' ),
			),
			'gtm'     => array(
				'id' => strtoupper( $str( $gtm['id'] ?? '' ) ),
			),
			'tiktok'  => array(
				'pixel' => $str( $tiktok['pixel'] ?? '' ),
				'token' => $str( $tiktok['token'] ?? '' ),
			),
			'events'  => array(
				'scroll' => ! isset( $events['scroll'] ) || ! empty( $events['scroll'] ),
				'video'  => ! isset( $events['video'] ) || ! empty( $events['video'] ),
				'search' => ! isset( $events['search'] ) || ! empty( $events['search'] ),
			),
		);
	}
}
