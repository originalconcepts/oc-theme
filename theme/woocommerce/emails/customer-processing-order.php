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

echo \OC\Theme\Emails::body( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
	$order,
	$email ?? null,
	1,
	array(
		'heading'        => __( 'Your order has been received', 'oc-theme' ),
		'heading_pickup' => __( 'Your order has been received', 'oc-theme' ),
		'intro'          => __( "Thank you — your order has been received.\nIt is expected to reach you between [from] and [to].\nWe will email you again the moment it leaves us.", 'oc-theme' ),
		'intro_pickup'   => __( "Thank you — your order has been received.\nWe are getting it ready, and we will email you the moment it is waiting for you.", 'oc-theme' ),
	)
);
