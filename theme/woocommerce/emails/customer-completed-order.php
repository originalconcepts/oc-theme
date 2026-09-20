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

$oc_email = \OC\Theme\Emails::body(
	$order,
	$email ?? null,
	2,
	\OC\Theme\Emails::wording( 'customer_completed_order' )
);

echo $oc_email; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- an email built entirely from escaped parts.
