<?php
/**
 * The clearing page, in the language the shopper is reading.
 *
 * A clearing gateway keeps one language setting for the whole shop. On a
 * shop running in one language that is right and nothing here applies. On
 * a shop running in two, it means the card form is the single screen in
 * the whole purchase that changes language under the buyer — they read
 * the shop in English, press to pay, and the bank's page answers in
 * Hebrew.
 *
 * So the setting is followed as the shop set it, and only overridden when
 * there really is more than one language to be in. A shop that chose its
 * own clearing language and has no language plugin is never touched.
 *
 * @package OC_Theme
 */

declare( strict_types = 1 );

namespace OC\Theme;

defined( 'ABSPATH' ) || exit;

/**
 * Follows the shopper into the clearing page.
 */
final class Gateway_Language {

	/**
	 * Which setting each gateway keeps its language in, and which of its
	 * values means what. Only gateways we install ourselves are listed:
	 * guessing at somebody else's field name would break their checkout.
	 */
	private const GATEWAYS = array(
		'cardcom' => array(
			'key'  => 'lang',
			'says' => array(
				'he' => 'he',
				'en' => 'en',
				'ru' => 'ru',
				'ar' => 'ar',
			),
		),
	);

	/**
	 * Hook in, but only where it can do something.
	 */
	public function register(): void {
		if ( ! Language_Switch::available() ) {
			return;
		}

		foreach ( array_keys( self::GATEWAYS ) as $id ) {
			add_filter( 'option_woocommerce_' . $id . '_settings', array( $this, 'speak' ), 10, 2 );
		}
	}

	/**
	 * Hand the gateway the language of this request.
	 *
	 * @param mixed  $value  The stored settings.
	 * @param string $option Option name.
	 * @return mixed
	 */
	public function speak( $value, $option ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}

		$id = (string) preg_replace( '/^woocommerce_|_settings$/', '', (string) $option );

		if ( ! isset( self::GATEWAYS[ $id ] ) ) {
			return $value;
		}

		$spec = self::GATEWAYS[ $id ];
		$now  = strtolower( substr( (string) determine_locale(), 0, 2 ) );

		// A language the gateway has no page for is left alone: its own
		// setting is a better answer than one it cannot honour.
		if ( ! isset( $spec['says'][ $now ] ) ) {
			return $value;
		}

		$value[ $spec['key'] ] = $spec['says'][ $now ];

		return $value;
	}
}
