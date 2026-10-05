<?php
/**
 * Marketing consent → Flashy.
 *
 * The theme asks for marketing consent once, at the checkout, and lets the
 * shopper change their mind on the account page. When the Flashy plugin is
 * installed this module makes that single answer the one Flashy acts on:
 *
 *  - Flashy's own (pre-ticked) checkbox is not printed on the checkout or the
 *    registration form — the theme's box is the only one the shopper sees.
 *  - Flashy's "meta term to automatically subscribe" setting is pointed at the
 *    theme's field (oc_marketing_consent) unless the shop set its own, so the
 *    plugin's order hook subscribes exactly the people who ticked our box, and
 *    a standing "yes" on the account subscribes a returning customer too.
 *  - A change made on the account page is sent to Flashy straight away; the
 *    plugin has nothing for that case.
 *
 * Nothing here runs unless the Flashy plugin is active, and nothing is
 * changed in its stored settings — the overrides live in filters only.
 *
 * @package OC_Theme
 */

declare( strict_types = 1 );

namespace OC\Theme;

defined( 'ABSPATH' ) || exit;

/**
 * The Flashy bridge.
 */
final class Flashy {

	/** The consent field the theme prints at the checkout (POST key and user-meta key alike). */
	public const FIELD = 'oc_marketing_consent';

	/**
	 * Hook in. The filters are cheap and harmless without the plugin; the
	 * API call checks for it.
	 */
	public function register(): void {
		add_filter( 'option_flashy_settings', array( $this, 'quieten_checkbox' ) );
		add_filter( 'option_flashy_subscribe', array( $this, 'subscribe_key' ) );
		add_filter( 'default_option_flashy_subscribe', array( $this, 'subscribe_key' ) );
		add_action( 'oc_marketing_consent', array( $this, 'sync' ), 10, 5 );
	}

	/**
	 * Does the theme ask for consent itself on this site?
	 */
	private static function theme_asks(): bool {
		return class_exists( __NAMESPACE__ . '\\Checkout' ) && ! empty( Checkout::settings()['consent'] );
	}

	/**
	 * On the storefront, tell Flashy not to print its own checkbox — the
	 * theme's box is the one the shopper answers. Flashy's settings screen
	 * (a plain admin request) still shows the shop's stored choice.
	 *
	 * @param mixed $settings Stored flashy_settings.
	 * @return mixed
	 */
	public function quieten_checkbox( $settings ) {
		if ( ! is_array( $settings ) || ( is_admin() && ! wp_doing_ajax() ) || ! self::theme_asks() ) {
			return $settings;
		}
		$settings['add_checkbox'] = 'no';
		return $settings;
	}

	/**
	 * The field Flashy reads the answer from, when the shop has not named one.
	 *
	 * @param mixed $key Stored value.
	 * @return mixed
	 */
	public function subscribe_key( $key ) {
		if ( is_string( $key ) && '' !== $key ) {
			return $key;
		}
		return self::theme_asks() ? self::FIELD : $key;
	}

	/**
	 * Is Flashy installed, connected, and pointed at a list?
	 */
	public static function ready(): bool {
		return function_exists( 'flashy' )
			&& '' !== (string) get_option( 'flashy_key', '' )
			&& '' !== (string) get_option( 'flashy_list_id', '' )
			&& is_object( flashy() )
			&& isset( flashy()->api, flashy()->api->contacts );
	}

	/**
	 * Subscribe or unsubscribe the contact in Flashy, for decisions made on
	 * the account page. The checkout is the plugin's own order hook's job.
	 *
	 * @param int    $user_id User id (0 for a guest — nothing to do here).
	 * @param bool   $granted Yes or no.
	 * @param string $email   Email.
	 * @param string $phone   Phone.
	 * @param string $source  checkout | account.
	 */
	public function sync( $user_id, $granted, $email, $phone, $source ): void {
		if ( 'account' !== $source || '' === (string) $email || ! self::ready() ) {
			return;
		}

		$list    = get_option( 'flashy_list_id' );
		$user    = $user_id ? get_userdata( (int) $user_id ) : null;
		$contact = array_filter(
			array(
				'email'      => (string) $email,
				'phone'      => (string) $phone,
				'first_name' => $user ? (string) $user->first_name : '',
				'last_name'  => $user ? (string) $user->last_name : '',
			)
		);

		try {
			if ( $granted ) {
				flashy()->api->contacts->subscribe( $contact, $list, 'email' );
			} else {
				flashy()->api->contacts->unsubscribe( $contact, $list, 'email' );
			}
		} catch ( \Throwable $e ) {
			// A mailing hiccup must never break saving the account page.
			if ( function_exists( 'flashy_log' ) ) {
				flashy_log( 'oc-theme consent sync failed: ' . $e->getMessage() );
			}
		}
	}
}
