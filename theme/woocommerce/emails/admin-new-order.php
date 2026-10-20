<?php
/**
 * "A new order came in" — the shop's own copy.
 *
 * Drawn by OC\Theme\Emails on the same shell as the customer's emails.
 * Subject, heading and recipients stay WooCommerce's: WooCommerce →
 * Settings → Emails → New order.
 *
 * @package OC_Theme
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\OC\Theme\Emails' ) || ! isset( $order ) || ! $order instanceof WC_Order ) {
	return;
}

$oc_email = \OC\Theme\Emails::admin( $order, $email ?? null );

echo $oc_email; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- an email built entirely from escaped parts.
