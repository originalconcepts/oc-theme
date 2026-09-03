<?php
/**
 * Throwing the switch: the theme takes over delivery pricing in
 * WooCommerce's zone, and gives it back when asked.
 *
 * On: the zone that covers the shop's country gets an instance of the
 * theme's method, and WooCommerce's own flat rate and free shipping in
 * that zone are paused — not deleted, and remembered, so that off means
 * exactly the shop as it was. Pickup is left alone; it is not delivery.
 *
 * @package OC\Theme
 */

declare( strict_types = 1 );

namespace OC\Theme\Shipping;

defined( 'ABSPATH' ) || exit;

/**
 * Zone surgery, reversible.
 */
final class Engage {

	const PAUSED = 'oc_shipping_paused';

	/**
	 * Take over the zone.
	 *
	 * @return string What was done, for the notice.
	 */
	public static function on(): string {
		if ( ! class_exists( '\WC_Shipping_Zones' ) ) {
			return '';
		}

		$zone = self::zone();

		if ( ! $zone ) {
			$zone = new \WC_Shipping_Zone();
			$zone->set_zone_name( __( 'Israel', 'oc-theme' ) );
			$zone->add_location( 'IL', 'country' );
			$zone->save();
		}

		$ours   = null;
		$paused = array();

		foreach ( $zone->get_shipping_methods( false ) as $method ) {
			if ( Method::ID === $method->id ) {
				$ours = $method;
				continue;
			}

			if ( in_array( $method->id, array( 'flat_rate', 'free_shipping' ), true ) && 'yes' === $method->enabled ) {
				self::set_enabled( (int) $method->instance_id, false );
				$paused[] = (int) $method->instance_id;
			}
		}

		if ( ! $ours ) {
			$zone->add_shipping_method( Method::ID );
		} else {
			self::set_enabled( (int) $ours->instance_id, true );
		}

		update_option( self::PAUSED, $paused, false );
		self::flush();

		return sprintf(
			/* translators: 1: zone name. 2: how many of WooCommerce's own methods were paused. */
			__( 'OC shipping is now pricing delivery in the “%1$s” zone; %2$d of WooCommerce’s own methods were paused.', 'oc-theme' ),
			$zone->get_zone_name(),
			count( $paused )
		);
	}

	/**
	 * Give the zone back.
	 *
	 * @return string What was done, for the notice.
	 */
	public static function off(): string {
		if ( ! class_exists( '\WC_Shipping_Zones' ) ) {
			return '';
		}

		$paused = array_map( 'absint', (array) get_option( self::PAUSED, array() ) );

		foreach ( $paused as $instance_id ) {
			self::set_enabled( $instance_id, true );
		}

		foreach ( \WC_Shipping_Zones::get_zones() as $zone ) {
			foreach ( $zone['shipping_methods'] as $method ) {
				if ( Method::ID === $method->id ) {
					self::set_enabled( (int) $method->instance_id, false );
				}
			}
		}

		delete_option( self::PAUSED );
		self::flush();

		return sprintf(
			/* translators: %d: how many of WooCommerce's own methods were switched back on. */
			__( 'WooCommerce prices delivery again; %d of its methods were switched back on.', 'oc-theme' ),
			count( $paused )
		);
	}

	/**
	 * Where things stand, for the screen.
	 *
	 * @return array{zone:string,ours:bool,others:string[]}
	 */
	public static function status(): array {
		$out = array(
			'zone'   => '',
			'ours'   => false,
			'others' => array(),
		);

		if ( ! class_exists( '\WC_Shipping_Zones' ) ) {
			return $out;
		}

		$zone = self::zone();

		if ( ! $zone ) {
			return $out;
		}

		$out['zone'] = $zone->get_zone_name();

		foreach ( $zone->get_shipping_methods( true ) as $method ) {
			if ( Method::ID === $method->id ) {
				$out['ours'] = true;
			} else {
				$out['others'][] = (string) $method->get_title();
			}
		}

		return $out;
	}

	/**
	 * The zone that covers the shop's country, else the first zone.
	 */
	private static function zone(): ?\WC_Shipping_Zone {
		$country = (string) WC()->countries->get_base_country();
		$first   = null;

		foreach ( \WC_Shipping_Zones::get_zones() as $row ) {
			$zone = new \WC_Shipping_Zone( (int) $row['id'] );

			if ( null === $first ) {
				$first = $zone;
			}

			foreach ( $zone->get_zone_locations() as $loc ) {
				if ( 'country' === $loc->type && $country === $loc->code ) {
					return $zone;
				}
			}
		}

		return $first;
	}

	/**
	 * Pause or resume one method instance — the same write WooCommerce's
	 * own zone screen makes for its toggle.
	 *
	 * @param int  $instance_id Instance.
	 * @param bool $on          Enabled.
	 */
	private static function set_enabled( int $instance_id, bool $on ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- WooCommerce's own zone-methods table; this is the write its zone screen makes.
		$wpdb->update(
			$wpdb->prefix . 'woocommerce_shipping_zone_methods',
			array( 'is_enabled' => $on ? 1 : 0 ),
			array( 'instance_id' => $instance_id )
		);

		do_action( 'woocommerce_shipping_zone_method_status_toggled', $instance_id, Method::ID, 0, $on );
	}

	/**
	 * Rates are cached per session; a rule change must reach the checkout.
	 */
	public static function flush(): void {
		if ( class_exists( '\WC_Cache_Helper' ) ) {
			\WC_Cache_Helper::get_transient_version( 'shipping', true );
		}
	}
}
