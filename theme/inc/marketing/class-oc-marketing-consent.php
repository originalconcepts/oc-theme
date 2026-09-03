<?php
/**
 * Consent: Google's Consent Mode v2, and a small banner where the law
 * wants one.
 *
 * In the EEA, the UK and Switzerland nothing is stored until the visitor
 * says yes. Elsewhere — Israel included — the banner informs and lets the
 * visitor decline; the tags run meanwhile. The choice lives in a cookie
 * for a year, and the marketing script reads it before it loads anything.
 *
 * @package OC\Theme
 */

declare( strict_types = 1 );

namespace OC\Theme\Marketing;

defined( 'ABSPATH' ) || exit;

/**
 * Consent defaults and the banner.
 */
final class Consent {

	const COOKIE = 'oc_consent';

	/**
	 * Countries where nothing runs before a yes.
	 *
	 * @var string[]
	 */
	const OPT_IN = array( 'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE', 'GR', 'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL', 'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE', 'IS', 'LI', 'NO', 'GB', 'CH' );

	/**
	 * What this visitor starts with: 'off' (no consent layer), 'optin'
	 * (denied until accepted) or 'optout' (granted unless declined).
	 */
	public static function mode(): string {
		$mode = (string) Settings::get()['consent'];

		if ( 'auto' !== $mode ) {
			return $mode;
		}

		return in_array( self::country(), self::OPT_IN, true ) ? 'optin' : 'optout';
	}

	/**
	 * The visitor's stored answer: 'granted', 'denied' or ''.
	 */
	public static function stored(): string {
		$v = isset( $_COOKIE[ self::COOKIE ] ) ? sanitize_key( wp_unslash( $_COOKIE[ self::COOKIE ] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitize_key() bounds it.

		return in_array( $v, array( 'granted', 'denied' ), true ) ? $v : '';
	}

	/**
	 * Where the visitor is, by the platform's header or WooCommerce's
	 * lookup; '' when unknown (treated as opt-out).
	 */
	private static function country(): string {
		if ( ! empty( $_SERVER['HTTP_CF_IPCOUNTRY'] ) ) {
			return strtoupper( substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_IPCOUNTRY'] ) ), 0, 2 ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- two letters, sanitized.
		}

		if ( class_exists( '\WC_Geolocation' ) ) {
			$geo = \WC_Geolocation::geolocate_ip( '', true, false );

			return strtoupper( (string) ( $geo['country'] ?? '' ) );
		}

		return '';
	}

	/**
	 * The banner, printed once when a choice is still open.
	 */
	public static function banner(): void {
		if ( 'off' === self::mode() || '' !== self::stored() ) {
			return;
		}

		$policy = function_exists( 'get_privacy_policy_url' ) ? (string) get_privacy_policy_url() : '';
		?>
		<div class="oc-consent" data-oc-consent hidden>
			<p class="oc-consent__text">
				<?php esc_html_e( 'We use cookies to run the shop, measure visits and show relevant offers.', 'oc-theme' ); ?>
				<?php if ( '' !== $policy ) : ?>
					<a href="<?php echo esc_url( $policy ); ?>"><?php esc_html_e( 'Privacy policy', 'oc-theme' ); ?></a>
				<?php endif; ?>
			</p>
			<div class="oc-consent__acts">
				<button type="button" class="oc-consent__no" data-oc-consent-deny><?php esc_html_e( 'Only what is needed', 'oc-theme' ); ?></button>
				<button type="button" class="oc-consent__yes" data-oc-consent-grant><?php esc_html_e( 'Accept', 'oc-theme' ); ?></button>
			</div>
		</div>
		<?php
	}
}
