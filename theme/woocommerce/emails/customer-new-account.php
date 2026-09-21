<?php
/**
 * "Welcome — your account is ready."
 *
 * WooCommerce hands this template $user_display_name, $user_login, $blogname,
 * $password_generated and, when the password was generated for them,
 * $set_password_url. Where there is a password to set, that is the button;
 * where there is not, the account area is.
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
$oc_set   = ! empty( $password_generated ) ? (string) ( $set_password_url ?? '' ) : '';

$oc_email = \OC\Theme\Emails::account(
	$email ?? null,
	\OC\Theme\Emails::wording( 'customer_new_account' ),
	array(
		'[name]'  => '' !== $oc_name ? $oc_name : $oc_login,
		'[shop]'  => $oc_shop,
		'[login]' => $oc_login,
	),
	'' !== $oc_set ? $oc_set : (string) wc_get_page_permalink( 'myaccount' ),
	'' !== $oc_set ? __( 'Set your password', 'oc-theme' ) : __( 'Go to your account', 'oc-theme' ),
	/* translators: %s: the shop name. */
	sprintf( __( 'You are receiving this email because an account was opened at %s.', 'oc-theme' ), $oc_shop )
);

echo $oc_email; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- an email built entirely from escaped parts.
