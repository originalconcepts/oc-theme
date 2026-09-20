<?php
/**
 * "We have your order" — the first email a shopper gets.
 *
 * WooCommerce's own template calls the header and footer actions; this one
 * does not, because the whole page is drawn by OC\Theme\Emails. The words
 * come from WooCommerce → Settings → Emails → Processing order.
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
	1,
	\OC\Theme\Emails::wording( 'customer_processing_order' )
);

echo $oc_email; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- an email built entirely from escaped parts.
