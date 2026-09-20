<?php
/**
 * "It is on its way" — or, for collection, "it is waiting for you".
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
	2,
	array(
		'heading'        => __( 'Your order is on its way', 'oc-theme' ),
		'heading_pickup' => __( 'Your order is ready for collection', 'oc-theme' ),
		'intro'          => __( "Good news — your order has left us and is on its way to you.\nWe will be in touch if anything changes. Thank you for shopping with us.", 'oc-theme' ),
		'intro_pickup'   => __( "Good news — your order is ready and waiting for you.\nCome whenever it suits you; the details are below. Thank you for shopping with us.", 'oc-theme' ),
	)
);
