<?php
/**
 * "Reset your password."
 *
 * The one email in the set that has to be trusted on sight, so it looks
 * exactly like the shop's other emails rather than like a plain grey notice
 * from nobody in particular.
 *
 * WooCommerce hands this template $user_display_name, $user_login, $user_id,
 * $reset_key and $blogname.
 *
 * @package OC_Theme
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\OC\Theme\Emails' ) ) {
	return;
}

$oc_login = (string) ( $user_login ?? '' );
$oc_name  = trim( (string) ( $user_display_name ?? '' ) );
$oc_shop  = wp_specialchars_decode( (string) ( $blogname ?? get_bloginfo( 'name' ) ), ENT_QUOTES );

$oc_link = add_query_arg(
	array(
		'key'   => (string) ( $reset_key ?? '' ),
		'id'    => absint( $user_id ?? 0 ),
		'login' => rawurlencode( $oc_login ),
	),
	wc_get_endpoint_url( 'lost-password', '', wc_get_page_permalink( 'myaccount' ) )
);

$oc_email = \OC\Theme\Emails::account(
	$email ?? null,
	\OC\Theme\Emails::wording( 'customer_reset_password' ),
	array(
		'[name]'  => '' !== $oc_name ? $oc_name : $oc_login,
		'[shop]'  => $oc_shop,
		'[login]' => $oc_login,
	),
	(string) $oc_link,
	__( 'Choose a new password', 'oc-theme' ),
	/* translators: %s: the shop name. */
	sprintf( __( 'You are receiving this email because a password reset was asked for on your account at %s.', 'oc-theme' ), $oc_shop ),
	\OC\Theme\Emails::who( $oc_login )
);

echo $oc_email; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- an email built entirely from escaped parts.
