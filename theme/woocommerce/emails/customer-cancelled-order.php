<?php
/**
 * "Your order has been cancelled."
 *
 * WooCommerce ships this email switched off. A shop that cancels orders for
 * its own housekeeping should think before switching it on; a shop that
 * cancels because a customer asked should have had it on all along.
 *
 * @package OC_Theme
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\OC\Theme\Emails' ) || ! isset( $order ) || ! $order instanceof WC_Order ) {
	return;
}

$oc_email = \OC\Theme\Emails::body(
	$order,
	$email ?? null,
	0,
	\OC\Theme\Emails::wording( 'customer_cancelled_order' )
);

echo $oc_email; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- an email built entirely from escaped parts.
