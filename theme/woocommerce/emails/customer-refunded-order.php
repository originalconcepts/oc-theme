<?php
/**
 * "Your refund is on its way."
 *
 * The same email carries a full refund and a part of one, so the amount says
 * which it is rather than the wording.
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
	\OC\Theme\Emails::wording( 'customer_refunded_order' ),
	\OC\Theme\Emails::refunded( $order )
);

echo $oc_email; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- an email built entirely from escaped parts.
