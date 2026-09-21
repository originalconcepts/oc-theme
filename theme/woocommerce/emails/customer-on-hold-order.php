<?php
/**
 * "We have your order, the payment is not through yet."
 *
 * Drawn by OC\Theme\Emails, like the rest of the family, and with no
 * progress bar: nothing is on its way until the money is.
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
	\OC\Theme\Emails::wording( 'customer_on_hold_order' )
);

echo $oc_email; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- an email built entirely from escaped parts.
