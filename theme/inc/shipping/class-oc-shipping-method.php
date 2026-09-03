<?php
/**
 * The theme's shipping method, as WooCommerce sees it.
 *
 * A first-class method: it sits in a zone like any other, WooCommerce asks
 * it for a rate, caches it in the session, taxes it and hands it to the
 * checkout — classic or blocks — and to every plugin that reads rates. The
 * price comes from the one calculator; this class only translates between
 * WooCommerce's package and the calculator's parcel.
 *
 * @package OC\Theme
 */

declare( strict_types = 1 );

namespace OC\Theme\Shipping;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\WC_Shipping_Method' ) ) {
	return;
}

/**
 * OC shipping.
 */
final class Method extends \WC_Shipping_Method {

	const ID = 'oc_shipping';

	/**
	 * Set up.
	 *
	 * @param int $instance_id Instance.
	 */
	public function __construct( $instance_id = 0 ) {
		$this->id                 = self::ID;
		$this->instance_id        = absint( $instance_id );
		$this->method_title       = __( 'OC shipping', 'oc-theme' );
		$this->method_description = __( 'Delivery priced by the theme’s shipping rules: a base price, free delivery over a sum, groups priced on their own terms, and regions that cost differently.', 'oc-theme' );
		$this->supports           = array( 'shipping-zones', 'instance-settings', 'instance-settings-modal' );

		$this->instance_form_fields = array(
			'title' => array(
				'title'       => __( 'Name at the checkout', 'oc-theme' ),
				'type'        => 'text',
				'description' => __( 'Leave empty to use the name from the shipping rules.', 'oc-theme' ),
				'default'     => '',
				'desc_tip'    => true,
			),
		);

		$this->init_instance_settings();
		$this->title   = (string) $this->get_option( 'title', '' );
		$this->enabled = 'yes';

		add_action(
			'woocommerce_update_options_shipping_' . $this->id,
			function (): void {
				$this->process_admin_options();
			}
		);
	}

	/**
	 * Price this package.
	 *
	 * @param array<string,mixed> $package Package.
	 */
	public function calculate_shipping( $package = array() ): void {
		// Someone put this method in a zone; a shopper is at the checkout.
		// Whether or not the switch is on, they get a price — from the
		// rules as saved, or from what WooCommerce's own methods said.
		$rules = Rules::get();

		$quote = Quote::calculate( \OC\Theme\Shipping::lines_from_package( (array) $package, $rules ), \OC\Theme\Shipping::destination_from_package( (array) $package ), $rules );
		$label = $quote['free'] ? \OC\Theme\Shipping::free_label( $rules ) : ( '' !== $this->title ? $this->title : \OC\Theme\Shipping::label( $rules ) );

		$this->add_rate(
			array(
				'id'        => $this->get_rate_id(),
				'label'     => $label,
				'cost'      => $quote['cost'],
				'package'   => $package,
				'meta_data' => array(
					'oc_reason' => \OC\Theme\Shipping::explain( $quote ),
				),
			)
		);
	}
}
