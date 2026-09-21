<?php
/**
 * The shop's own transactional emails.
 *
 * WooCommerce's are functional and anonymous: a grey table on a white page
 * with the shop's name in small type. The order page on the site is the
 * opposite, and the email is the part a shopper actually keeps — it sits in
 * their inbox and is opened again the day the delivery is due. So the two
 * customer emails are rendered here instead, with the shop's logo, its
 * colours, its own words, and the same information in the same order as the
 * order page.
 *
 * The words are edited where anybody would look for them: WooCommerce →
 * Settings → Emails → the email in question. Each one gains a heading and an
 * opening paragraph for delivery and another pair for collection, because an
 * order being carried to somebody and an order waiting on a shelf are not the
 * same news.
 *
 * Email is not the web. Everything here is tables and inline styles: no grid,
 * no flexbox, no custom properties, no external stylesheet. Outlook is the
 * reason, and it has not changed its mind in fifteen years.
 *
 * @package OC_Theme
 */

declare( strict_types = 1 );

namespace OC\Theme;

defined( 'ABSPATH' ) || exit;

/**
 * Rendering and settings for the customer emails.
 */
final class Emails {

	/**
	 * The emails this takes over. Admin notices keep WooCommerce's own look —
	 * they go to the shop, which has no need to be sold to.
	 */
	const OURS = array(
		'customer_processing_order',
		'customer_completed_order',
		'customer_on_hold_order',
		'customer_cancelled_order',
		'customer_refunded_order',
		'customer_reset_password',
		'customer_new_account',
	);

	/**
	 * How the written parts of the email being rendered are aligned.
	 *
	 * Set once, at the top of body(), and read by the pieces below it. The
	 * order details are not in it: a table of prices reads along the
	 * language whatever the heading above it does.
	 *
	 * @var string
	 */
	private static $align = '';

	/**
	 * That alignment as a CSS value.
	 */
	public static function align(): string {
		return 'center' === self::$align ? 'center' : ( is_rtl() ? 'right' : 'left' );
	}

	/**
	 * Hook in.
	 */
	public function register(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		foreach ( self::OURS as $id ) {
			add_filter(
				'woocommerce_settings_api_form_fields_' . $id,
				function ( $fields ) use ( $id ) {
					return $this->fields( $fields, $id );
				}
			);
		}
	}

	/* ------------------------------------------------------------ settings */

	/**
	 * The words, added to the email's own settings screen.
	 *
	 * @param array  $fields Woo's fields.
	 * @param string $id     Which email these belong to.
	 * @return array
	 */
	public function fields( $fields, string $id = '' ) {
		$fields = (array) $fields;

		// Woo's own "additional content" is the last thing on the screen and
		// ours belongs with the rest of the wording, so it is re-appended.
		$tail = isset( $fields['additional_content'] ) ? array( 'additional_content' => $fields['additional_content'] ) : array();
		unset( $fields['additional_content'] );

		$words = self::wording( $id );

		// The defaults are the real, written text rather than an empty box.
		// A shop opening this screen should see what its customers are being
		// told and change a word of it — not face three blank fields and have
		// to guess what belongs in them.
		if ( isset( $words['heading_pickup'] ) ) {
			$fields['oc_heading_pickup'] = array(
				'title'       => __( 'Heading — collection', 'oc-theme' ),
				'type'        => 'text',
				'description' => __( 'Shown instead of the heading above when the order is being collected.', 'oc-theme' ),
				'desc_tip'    => true,
				'default'     => $words['heading_pickup'],
			);
		}

		$fields['oc_intro'] = array(
			'title'       => isset( $words['intro_pickup'] )
				? __( 'Opening words — delivery', 'oc-theme' )
				: __( 'Opening words', 'oc-theme' ),
			'type'        => 'textarea',
			'css'         => 'width:100%;height:110px;',
			'description' => isset( $words['intro_pickup'] )
				? __( 'The paragraph under the heading. Each line is its own paragraph. [from] and [to] become the estimated arrival dates — delete that line to leave the estimate out.', 'oc-theme' )
				: __( 'The paragraph under the heading. Each line is its own paragraph. [shop] and [name] become the shop name and the person\'s name.', 'oc-theme' ),
			'default'     => $words['intro'],
		);

		$fields['oc_show_help'] = array(
			'title'       => __( 'Contact block', 'oc-theme' ),
			'type'        => 'checkbox',
			'label'       => __( 'Show "Here for any question" with the shop\'s phone, WhatsApp and email', 'oc-theme' ),
			'description' => __( 'The details themselves come from Theme settings → Store details. Anything left empty there simply does not appear.', 'oc-theme' ),
			'default'     => 'yes',
		);

		if ( isset( $words['intro_pickup'] ) ) {
			$fields['oc_intro_pickup'] = array(
				'title'       => __( 'Opening words — collection', 'oc-theme' ),
				'type'        => 'textarea',
				'css'         => 'width:100%;height:110px;',
				'description' => __( 'The same, for an order being collected rather than delivered.', 'oc-theme' ),
				'default'     => $words['intro_pickup'],
			);
		}

		$fields['oc_align'] = array(
			'title'       => __( 'Text alignment', 'oc-theme' ),
			'type'        => 'select',
			'class'       => 'wc-enhanced-select',
			'description' => __( 'How the heading, the opening words and the closing lines sit. The order details themselves always follow the shop language.', 'oc-theme' ),
			'desc_tip'    => true,
			'default'     => 'center',
			'options'     => array(
				'center' => __( 'Centred', 'oc-theme' ),
				'start'  => __( 'Along the text direction', 'oc-theme' ),
			),
		);

		return array_merge( $fields, $tail );
	}

	/**
	 * The shop's words for one email, before it has changed any of them.
	 *
	 * Kept here rather than in the templates so the settings screen and the
	 * email itself can never drift apart: what the field shows is what goes
	 * out.
	 *
	 * @param string $id Which email.
	 * @return array<string,string>
	 */
	public static function wording( string $id ): array {
		if ( 'customer_on_hold_order' === $id ) {
			return array(
				'heading'        => __( 'Your order is waiting for payment', 'oc-theme' ),
				'heading_pickup' => __( 'Your order is waiting for payment', 'oc-theme' ),
				'intro'          => __( "We have your order, and it is waiting for the payment to come through.\nThe moment it does we will start getting it ready and email you again.\nEverything you ordered is listed below.", 'oc-theme' ),
				'intro_pickup'   => __( "We have your order, and it is waiting for the payment to come through.\nThe moment it does we will get it ready and email you when it is waiting at the branch.\nEverything you ordered is listed below.", 'oc-theme' ),
			);
		}

		if ( 'customer_cancelled_order' === $id ) {
			return array(
				'heading'        => __( 'Your order has been cancelled', 'oc-theme' ),
				'heading_pickup' => __( 'Your order has been cancelled', 'oc-theme' ),
				'intro'          => __( "Your order has been cancelled and nothing further will be charged for it.\nIf it was already paid for, we are taking care of the refund.\nIf this is not what you expected, talk to us — our details are at the foot of this email.", 'oc-theme' ),
				'intro_pickup'   => __( "Your order has been cancelled and nothing further will be charged for it.\nIf it was already paid for, we are taking care of the refund.\nIf this is not what you expected, talk to us — our details are at the foot of this email.", 'oc-theme' ),
			);
		}

		if ( 'customer_refunded_order' === $id ) {
			return array(
				'heading'        => __( 'Your refund is on its way', 'oc-theme' ),
				'heading_pickup' => __( 'Your refund is on its way', 'oc-theme' ),
				'intro'          => __( "We have refunded your order.\nThe money goes back the way it was paid, and it usually takes a few working days to show up.\nThe amount and the order are below.", 'oc-theme' ),
				'intro_pickup'   => __( "We have refunded your order.\nThe money goes back the way it was paid, and it usually takes a few working days to show up.\nThe amount and the order are below.", 'oc-theme' ),
			);
		}

		if ( 'customer_reset_password' === $id ) {
			return array(
				'heading' => __( 'Reset your password', 'oc-theme' ),
				'intro'   => __( "Hi [name], somebody asked to reset the password for your account at [shop].\nIf it was not you, ignore this email — nothing changes until the button below is used.\nThe link is only good for a short while.", 'oc-theme' ),
			);
		}

		if ( 'customer_new_account' === $id ) {
			return array(
				'heading' => __( 'Welcome to [shop]', 'oc-theme' ),
				'intro'   => __( "Hi [name], your account is ready.\nFrom it you can follow your orders and keep your addresses in one place, so the next checkout is a few taps.\nWelcome — we are glad you are here.", 'oc-theme' ),
			);
		}

		if ( 'customer_completed_order' === $id ) {
			return array(
				'heading'        => __( 'Your order is on its way', 'oc-theme' ),
				'heading_pickup' => __( 'Your order is ready for you', 'oc-theme' ),
				'intro'          => __( "It is packed, it has left us, and it is on its way to you.\nIt should reach you between [from] and [to].\nEverything you ordered is listed below, and we are here if anything needs looking at.", 'oc-theme' ),
				'intro_pickup'   => __( "It is packed and waiting for you at the branch.\nCome whenever it suits you — the address and the opening details are below.\nPlease bring the order number with you, and we will have it ready.", 'oc-theme' ),
			);
		}

		return array(
			'heading'        => __( 'Thank you for your order', 'oc-theme' ),
			'heading_pickup' => __( 'Thank you for your order', 'oc-theme' ),
			'intro'          => __( "We have received your order and we are already getting it ready.\nIt is expected to reach you between [from] and [to].\nWe will email you again the moment it leaves us, so you know exactly where it is.", 'oc-theme' ),
			'intro_pickup'   => __( "We have received your order and we are already getting it ready.\nWe will email you the moment it is waiting for you at the branch.\nEverything you ordered is listed below.", 'oc-theme' ),
		);
	}

	/**
	 * One of our settings read straight out of an email's stored options,
	 * for the emails this theme sends without a WC_Email behind them.
	 *
	 * @param string $id       Which email's settings to read.
	 * @param string $key      Setting key.
	 * @param string $fallback Used when the shop has left it empty.
	 */
	public static function saved( string $id, string $key, string $fallback = '' ): string {
		$all = get_option( 'woocommerce_' . $id . '_settings' );
		$val = is_array( $all ) && isset( $all[ $key ] ) ? trim( (string) $all[ $key ] ) : '';

		return '' !== $val ? $val : $fallback;
	}

	/**
	 * One of our settings off an email object, falling back to a default.
	 *
	 * @param mixed  $email    The WC_Email being sent.
	 * @param string $key      Setting key.
	 * @param string $fallback Used when the shop has left it empty.
	 */
	public static function opt( $email, string $key, string $fallback = '' ): string {
		$value = is_object( $email ) && method_exists( $email, 'get_option' ) ? (string) $email->get_option( $key, '' ) : '';
		$value = trim( $value );

		return '' !== $value ? $value : $fallback;
	}

	/* ------------------------------------------------------------- helpers */

	/**
	 * Is this order being collected rather than delivered?
	 *
	 * @param \WC_Order $order Order.
	 */
	public static function is_pickup( $order ): bool {
		foreach ( $order->get_shipping_methods() as $method ) {
			if ( 0 === strpos( (string) $method->get_method_id(), 'local_pickup' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The shop's own colours, as literal values — an email cannot read a
	 * custom property.
	 *
	 * @return array<string,string>
	 */
	public static function palette(): array {
		$primary = trim( (string) get_theme_mod( 'oc_color_primary', '' ) );
		$cta     = trim( (string) get_theme_mod( 'oc_cta_color', '' ) );

		$primary = '' !== $primary ? $primary : '#03104c';

		return array(
			'primary' => $primary,
			'cta'     => '' !== $cta ? $cta : $primary,
			'ink'     => '#1c1c1c',
			'soft'    => '#6b6b6b',
			'line'    => '#e4e6ea',
			'panel'   => '#f7f8f9',
			'page'    => '#f0f1f3',
		);
	}

	/**
	 * The shop's logo as an <img>, or its name when there is none.
	 */
	public static function logo_html(): string {
		$p    = self::palette();
		$name = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		$id   = (int) get_theme_mod( 'custom_logo' );

		if ( $id ) {
			// The FULL file, not a resized one. A logo shown at 170px from a
			// 300px "medium" is already soft, and on the retina screen every
			// phone has it is visibly rough.
			$src = wp_get_attachment_image_url( $id, 'full' );

			if ( $src ) {
				// Bounded on BOTH axes. A width alone is no cap at all for an
				// SVG, which carries no pixel size to argue with — the first
				// version of this let the demo's wordmark run the full width
				// of the email.
				//
				// Whether it may GROW into that box depends on what it is. A
				// vector can be drawn at any size and stay sharp, so it fills
				// the space rather than sitting at whatever the file happens
				// to call its natural size — the demo's is 109px across, lost
				// in a 600px email. A photograph enlarged past its own pixels
				// only looks worse, so that one is bounded and left alone.
				$vector = 'image/svg+xml' === get_post_mime_type( $id );

				return '<img src="' . esc_url( $src ) . '" alt="' . esc_attr( $name ) . '"'
					. ' style="display:block;margin:0 auto;border:0;outline:none;text-decoration:none;'
					. ( $vector ? 'width:180px;' : 'width:auto;' )
					. 'height:auto;max-width:180px;max-height:54px;" />';
			}
		}

		return '<span style="font-size:21px;font-weight:700;letter-spacing:.04em;color:' . esc_attr( $p['primary'] ) . ';">' . esc_html( $name ) . '</span>';
	}

	/**
	 * A file from the email icon set, as an absolute URL.
	 *
	 * Drawn as PNGs on purpose: Gmail throws away inline SVG and SVG files
	 * alike, so a vector icon in an email is an icon half the world does not
	 * see. These are 96px square and shown at 22, which is sharp on any
	 * screen anybody owns.
	 *
	 * @param string $name File name without the extension.
	 */
	public static function icon_url( string $name ): string {
		return get_template_directory_uri() . '/assets/img/email/' . sanitize_file_name( $name ) . '.png';
	}

	/* --------------------------------------------------------------- shell */

	/**
	 * Everything above the message: the page, the card, the logo.
	 */
	public static function open(): string {
		$p   = self::palette();
		$dir = is_rtl() ? 'rtl' : 'ltr';

		// The widths are percentages and the type is sized for a phone from
		// the start, because that is where most of these are read and because
		// a media query is a suggestion — Outlook ignores them and Gmail
		// honours them only sometimes. The query below only makes a good
		// layout roomier; it is never what rescues it.
		$css = '@media only screen and (max-width:620px){'
			. '.oc-pad{padding:26px 18px !important}'
			. '.oc-h1{font-size:23px !important}'
			. '.oc-h1{font-size:24px !important}'
			. '.oc-card{display:block !important;width:100% !important;max-width:100% !important}'
			. '.oc-col{display:block !important;width:100% !important;padding:0 0 18px !important}'
			. '.oc-gap{display:none !important}'
			. '}';

		return '<!DOCTYPE html><html dir="' . $dir . '" lang="' . esc_attr( get_bloginfo( 'language' ) ) . '">'
			. '<head><meta charset="utf-8" /><meta name="viewport" content="width=device-width,initial-scale=1" />'
			. '<meta name="x-apple-disable-message-reformatting" />'
			. '<title>' . esc_html( get_bloginfo( 'name' ) ) . '</title>'
			. '<style>' . $css . '</style></head>'
			. '<body dir="' . $dir . '" style="margin:0;padding:0;width:100%;background:' . esc_attr( $p['page'] ) . ';'
			. '-webkit-text-size-adjust:100%;-ms-text-size-adjust:100%;">'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:' . esc_attr( $p['page'] ) . ';">'
			. '<tr><td align="center" style="padding:26px 12px 32px;">'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:600px;">'
			. '<tr><td align="center" style="padding:0 0 18px;">' . self::logo_html() . '</td></tr>'
			. '<tr><td style="background:#ffffff;border-radius:16px;">';
	}

	/**
	 * Everything below it.
	 *
	 * @param string $why Why this person is being written to, when it is not
	 *                    an order. Empty means it is.
	 */
	public static function close( string $why = '' ): string {
		$p    = self::palette();
		$name = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );

		// The shop's own address, from where WooCommerce already keeps it.
		// A trading address under a commercial email is what tells a reader
		// — and a spam filter — that a real business sent it.
		$at = array();

		if ( function_exists( 'WC' ) && WC() && WC()->countries ) {
			foreach ( array( 'get_base_address', 'get_base_address_2', 'get_base_city', 'get_base_postcode' ) as $get ) {
				$bit = trim( (string) WC()->countries->$get() );

				if ( '' !== $bit ) {
					$at[] = $bit;
				}
			}
		}

		$out = '<div style="font-size:13.5px;font-weight:700;color:' . esc_attr( $p['ink'] ) . ';">'
			. '<a href="' . esc_url( home_url( '/' ) ) . '" style="color:' . esc_attr( $p['ink'] ) . ';text-decoration:none;">'
			. esc_html( $name ) . '</a></div>';

		if ( $at ) {
			$out .= '<div style="margin:4px 0 0;">' . self::plain( implode( ', ', $at ) ) . '</div>';
		}

		if ( '' === trim( $why ) ) {
			/* translators: %s: the shop name. */
			$why = sprintf( __( 'You are receiving this email because an order was placed at %s.', 'oc-theme' ), $name );
		}

		$out .= '<div style="margin:12px 0 0;">' . esc_html( $why ) . '</div>';

		$out .= '<div style="margin:4px 0 0;">'
			/* translators: 1: year, 2: the shop name. */
			. esc_html( sprintf( __( '© %1$s %2$s. All rights reserved.', 'oc-theme' ), wp_date( 'Y' ), $name ) )
			. '</div>';

		return '</td></tr>'
			. '<tr><td align="center" style="padding:22px 18px 0;font-size:12.5px;line-height:1.85;text-align:center;color:' . esc_attr( $p['soft'] ) . ';">'
			. $out
			. '</td></tr></table></td></tr></table></body></html>';
	}

	/* --------------------------------------------------------------- parts */

	/**
	 * Where the order has got to.
	 *
	 * Three stops, and only the first two can ever be ticked. Nothing tells
	 * the shop that a parcel was handed over or that somebody walked in and
	 * collected it, so the last stop is drawn as what it honestly is: the
	 * one still to come, with the date it is expected on.
	 *
	 * Each stop owns a third of the width and centres its own circle inside
	 * it, with the connecting line drawn as two half-rules either side of
	 * that circle within the same cell. The label then sits in the same
	 * third, so it is under the circle's centre by construction. Insetting
	 * the circles by a percentage and the labels by another — which is what
	 * the last version did — leaves the two off by half a circle.
	 *
	 * @param \WC_Order $order Order.
	 * @param int       $done  How many stops are behind us (1 or 2).
	 * @param string    $due   The expected-arrival line, or ''.
	 */
	public static function steps( $order, int $done, string $due = '' ): string {
		$p      = self::palette();
		$pickup = self::is_pickup( $order );
		$made   = $order->get_date_created();

		// The same format the arrival window is written in, so the three
		// dates under the bar are read as one set rather than as two.
		$fmt = (string) apply_filters( 'oc_delivery_date_format', 'j/n' );

		$when = $made ? wp_date( $fmt, $made->getTimestamp() ) : '';
		$paid = $order->get_date_paid();
		$out  = $paid ? wp_date( $fmt, $paid->getTimestamp() ) : '';

		if ( $done > 1 && '' === $out ) {
			$mod = $order->get_date_modified();
			$out = $mod ? wp_date( $fmt, $mod->getTimestamp() ) : '';
		}

		$stops = array(
			array( __( 'Received', 'oc-theme' ), $when ),
			array( $pickup ? __( 'Ready', 'oc-theme' ) : __( 'On its way', 'oc-theme' ), $done > 1 ? $out : '' ),
			array( $pickup ? __( 'Collected', 'oc-theme' ) : __( 'With you', 'oc-theme' ), $due ),
		);

		// One outer box for every circle, filled or empty. A border is drawn
		// OUTSIDE the box in email, so a 38px circle with a 2px border is
		// 42px wide inside a 38px cell — squeezed on one axis only, which is
		// how a circle comes out an oval. The empty one is drawn smaller by
		// exactly its border so both are the same 40px square, and so every
		// cell in the row is the same height and the rules either side of
		// them line up.
		$size  = 40;
		$grey  = '#dfe3e8';
		$cells = '';

		foreach ( $stops as $i => $stop ) {
			$on   = $i < $done;
			$in   = $on ? $size : $size - 4;
			$edge = $on ? '' : 'border:2px solid #cfd4db;';

			// A div with a radius of half its width. A table cell with a
			// radius comes out as a rounded square in more than one client.
			$circle = '<div style="width:' . $in . 'px;height:' . $in . 'px;line-height:' . $in . 'px;'
				. 'border-radius:' . ( $size / 2 ) . 'px;text-align:center;' . $edge
				. ( $on
					? 'background:' . esc_attr( $p['cta'] ) . ';color:#ffffff;font-size:21px;font-weight:700;'
					: 'background:#ffffff;color:#cfd4db;font-size:20px;' )
				. '">' . ( $on ? '&#10003;' : '&nbsp;' ) . '</div>';

			// The half-rule leading INTO this stop is lit once this stop has
			// been reached; the one leading out of it, once the next has.
			$before = $i > 0 ? ( $i < $done ? $p['cta'] : $grey ) : 'transparent';
			$after  = $i < 2 ? ( $i + 1 < $done ? $p['cta'] : $grey ) : 'transparent';

			$rule = static function ( string $colour ) use ( $size ): string {
				return '<td valign="middle" height="' . $size . '" style="height:' . $size . 'px;padding:0;font-size:0;line-height:0;">'
					. '<div style="height:3px;line-height:3px;font-size:0;background:' . esc_attr( $colour ) . ';">&nbsp;</div>'
					. '</td>';
			};

			$cells .= '<td width="33.33%" valign="top" style="padding:0;">'
				. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>'
				. $rule( $before )
				. '<td width="' . $size . '" height="' . $size . '" valign="middle" align="center" style="width:' . $size . 'px;height:' . $size . 'px;padding:0;">' . $circle . '</td>'
				. $rule( $after )
				. '</tr></table>'
				. '<div style="margin:10px 0 0;text-align:center;font-size:14px;font-weight:700;line-height:1.35;color:'
				. esc_attr( $on ? $p['ink'] : '#8f97a1' ) . ';">' . esc_html( $stop[0] ) . '</div>'
				. ( '' !== $stop[1]
					// A range of two dates is two number runs with a dash
					// between them, and in a right-to-left email the dash is
					// neutral: left alone, "22/9–24/9" comes out backwards.
					? '<div dir="ltr" style="margin:3px 0 0;text-align:center;font-size:13px;line-height:1.4;unicode-bidi:plaintext;color:#8f97a1;">' . esc_html( $stop[1] ) . '</div>'
					: '' )
				. '</td>';
		}

		return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:26px 0 0;'
			. 'background:' . esc_attr( $p['panel'] ) . ';border-radius:14px;">'
			. '<tr><td style="padding:24px 14px 20px;">'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>' . $cells . '</tr></table>'
			. '</td></tr></table>';
	}

	/**
	 * The one thing the email is asking to be clicked.
	 *
	 * @param string $url   Where it goes.
	 * @param string $label What it says.
	 */
	public static function cta( string $url, string $label ): string {
		$p = self::palette();

		return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:22px 0 0;"><tr>'
			. '<td align="center" style="border-radius:10px;background:' . esc_attr( $p['cta'] ) . ';">'
			. '<a href="' . esc_url( $url ) . '" style="display:block;padding:17px 24px;font-size:16.5px;font-weight:700;'
			. 'color:#ffffff;text-decoration:none;border-radius:10px;letter-spacing:.01em;">'
			. esc_html( $label ) . '</a>'
			. '</td></tr></table>';
	}

	/**
	 * The button onto the order's own page.
	 *
	 * @param \WC_Order $order Order.
	 */
	public static function button( $order ): string {
		return self::cta( (string) $order->get_view_order_url(), __( 'View your order', 'oc-theme' ) );
	}

	/**
	 * Who ordered, and where it is going — side by side, stacking on a
	 * phone.
	 *
	 * @param \WC_Order $order Order.
	 */
	public static function parties( $order ): string {
		$p      = self::palette();
		$pickup = self::is_pickup( $order );

		$h = static function ( string $text ) use ( $p ): string {
			return '<div style="margin:0 0 8px;font-size:13px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:' . esc_attr( $p['soft'] ) . ';">' . esc_html( $text ) . '</div>';
		};

		$line = static function ( string $text, bool $ltr = false, bool $bold = false ) use ( $p ): string {
			if ( '' === trim( $text ) ) {
				return '';
			}

			$inner = self::plain( $text );

			if ( $ltr ) {
				$inner = '<span dir="ltr" style="unicode-bidi:plaintext;">' . $inner . '</span>';
			}

			return '<div style="font-size:15.5px;line-height:1.7;' . ( $bold ? 'font-weight:600;' : '' ) . 'color:' . esc_attr( $p['ink'] ) . ';">' . $inner . '</div>';
		};

		$who = $h( __( 'Orderer details', 'oc-theme' ) )
			. $line( trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ), false, true )
			. $line( (string) $order->get_billing_phone(), true )
			. $line( (string) $order->get_billing_email(), true );

		if ( $pickup ) {
			$where  = $h( __( 'Collection', 'oc-theme' ) );
			$branch = (string) $order->get_meta( '_oc_branch_name' );
			$find   = '';

			if ( '' !== $branch ) {
				$where .= $line( $branch, false, true );

				$id = (int) $order->get_meta( '_oc_branch' );

				if ( $id && class_exists( '\\OC\\Blocks\\Branches' ) ) {
					$at = \OC\Blocks\Branches::details( $id );

					$where .= $line( (string) ( $at['address'] ?? '' ) );
					$where .= $line( (string) ( $at['phone'] ?? '' ), true );
					$find   = trim( (string) ( $at['address'] ?? '' ) . ' ' . (string) ( $at['city'] ?? '' ) );
				}
			} else {
				$where .= $line( __( 'Collection in person', 'oc-theme' ) );
			}

			$where .= self::map_link( $find );
		} else {
			// The street on its own line, then the flat, floor and entry code
			// named for what they are, then the city. Woo's "address 2" is
			// this theme's flat number, and a bare "3" glued to the street was
			// what the first version printed.
			$extra = array();
			$apt   = trim( (string) $order->get_billing_address_2() );
			$floor = trim( (string) $order->get_meta( '_oc_floor' ) );
			$entry = trim( (string) $order->get_meta( '_oc_entry' ) );

			if ( '' !== $apt ) {
				/* translators: %s: apartment number. */
				$extra[] = sprintf( __( 'Apt %s', 'oc-theme' ), $apt );
			}

			if ( '' !== $floor ) {
				/* translators: %s: floor. */
				$extra[] = sprintf( __( 'Floor %s', 'oc-theme' ), $floor );
			}

			if ( '' !== $entry ) {
				/* translators: %s: entry code. */
				$extra[] = sprintf( __( 'Entry %s', 'oc-theme' ), $entry );
			}

			$street = trim( (string) $order->get_billing_address_1() );
			$city   = trim( (string) $order->get_billing_city() );

			$where = $h( __( 'Delivery address', 'oc-theme' ) )
				. $line( $street, false, true )
				. $line( implode( ' · ', $extra ) )
				. $line( $city )
				. self::map_link( trim( $street . ' ' . $city ) );
		}

		return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:26px 0 0;">'
			. '<tr>'
			. '<td class="oc-col" width="50%" valign="top" style="padding:0 0 14px;">' . $who . '</td>'
			. '<td class="oc-gap" width="20" style="width:20px;">&nbsp;</td>'
			. '<td class="oc-col" valign="top" style="padding:0 0 14px;">' . $where . '</td>'
			. '</tr></table>';
	}

	/**
	 * Text a mail client will not turn into a link of its own.
	 *
	 * Gmail hunts for anything shaped like an address, a phone number or an
	 * email and wraps it in its own blue underlined anchor. Styling the span
	 * around it does nothing, because the colour it sets is on the anchor it
	 * inserted INSIDE that span. A zero-width space breaks the shape it is
	 * matching on while leaving the words identical to read — and it is the
	 * only thing that leaves the text as text rather than as somebody else's
	 * link.
	 *
	 * @param string $text Raw text.
	 */
	public static function plain( string $text ): string {
		$text = trim( $text );

		if ( '' === $text ) {
			return '';
		}

		$out   = '';
		$chars = preg_split( '//u', $text, -1, PREG_SPLIT_NO_EMPTY );

		foreach ( (array) $chars as $i => $char ) {
			$out .= $char;

			// After the first character, and before any run of digits: the
			// two places a "street + number" or a bare number is recognised.
			// An email address is matched on its own shape, so the @ and the
			// dots in the domain are broken as well — without them Gmail
			// still turns the orderer's own address into a blue link.
			$next = $chars[ $i + 1 ] ?? '';

			$break = 0 === $i
				|| ( '' !== $next && preg_match( '/\d/', $next ) && ! preg_match( '/\d/', $char ) )
				|| '@' === $next
				|| '.' === $next;

			if ( $break ) {
				$out .= "\u{200B}";
			}
		}

		return '<span style="color:inherit;text-decoration:none;">' . esc_html( $out ) . '</span>';
	}

	/**
	 * A small, deliberate link to the place on a map.
	 *
	 * The address itself stays text. One short link says what it is and goes
	 * where it says, which is what an address in an email is actually for.
	 *
	 * @param string $where Street and city.
	 */
	public static function map_link( string $where ): string {
		$where = trim( $where );

		if ( '' === $where ) {
			return '';
		}

		$p   = self::palette();
		$url = 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode( $where );

		return '<div style="margin:8px 0 0;">'
			. '<a href="' . esc_url( $url ) . '" style="font-size:13.5px;font-weight:600;color:' . esc_attr( $p['cta'] ) . ';text-decoration:none;">'
			. '&#9679;&nbsp;' . esc_html__( 'Location on the map', 'oc-theme' ) . '</a></div>';
	}

	/**
	 * What was bought, with the pictures — the part a shopper scrolls to.
	 *
	 * Three columns: the picture, the words, the price on the far side —
	 * where a price is looked for. The gutter between picture and words is
	 * the thing that makes a row read as a row rather than a pile.
	 *
	 * @param \WC_Order $order Order.
	 */
	public static function items( $order ): string {
		$p    = self::palette();
		$rows = '';

		foreach ( $order->get_items() as $item ) {
			// get_items() is typed as the base item; only a line item knows
			// which product it is.
			$product = $item instanceof \WC_Order_Item_Product ? $item->get_product() : null;
			$pic     = '';

			if ( $product ) {
				$id = (int) $product->get_image_id();

				if ( $id ) {
					$src = wp_get_attachment_image_url( $id, 'woocommerce_thumbnail' );

					if ( $src ) {
						$pic = '<img src="' . esc_url( $src ) . '" width="72" height="72" alt=""'
							. ' style="display:block;border:0;width:72px;height:72px;border-radius:10px;background:' . esc_attr( $p['panel'] ) . ';" />';
					}
				}
			}

			if ( '' === $pic ) {
				$pic = '<div style="width:72px;height:72px;border-radius:10px;background:' . esc_attr( $p['panel'] ) . ';"></div>';
			}

			// A variation's own name carries its attributes — "BALL TABLE -
			// עץ-חום" — and they are listed again underneath. The parent's
			// title is the product; the attributes are the detail.
			$title = (string) $item->get_name();

			if ( $product && $product->is_type( 'variation' ) ) {
				$parent = wc_get_product( $product->get_parent_id() );

				if ( $parent ) {
					$title = (string) $parent->get_name();
				}
			}

			// Woo's own meta renderer puts the label in a <strong> and the
			// value in a <p>, which is a block and drops to the next line.
			// The pairs are read off directly and set as one line each.
			$bits = array();

			foreach ( $item->get_formatted_meta_data() as $meta ) {
				$label = trim( wp_strip_all_tags( (string) $meta->display_key ) );
				$value = trim( wp_strip_all_tags( (string) $meta->display_value ) );

				if ( '' === $label || '' === $value ) {
					continue;
				}

				$bits[] = '<span style="color:' . esc_attr( $p['soft'] ) . ';">' . esc_html( rtrim( $label, ':' ) ) . ':</span> '
					. '<span style="color:' . esc_attr( $p['ink'] ) . ';">' . esc_html( $value ) . '</span>';
			}

			$rows .= '<tr>'
				. '<td width="72" valign="top" style="width:72px;padding:16px 0;">' . $pic . '</td>'
				. '<td valign="top" style="padding:16px 18px;">'
				. '<div style="font-size:15.5px;font-weight:600;line-height:1.45;color:' . esc_attr( $p['ink'] ) . ';">'
				. esc_html( $title ) . '</div>'
				. ( $bits
					? '<div style="margin:5px 0 0;font-size:13.5px;line-height:1.7;">' . implode( '<br />', $bits ) . '</div>'
					: '' )
				. '<div style="margin:6px 0 0;font-size:13.5px;line-height:1.5;color:' . esc_attr( $p['soft'] ) . ';">'
				/* translators: %d: how many of this item. */
				. esc_html( sprintf( __( 'Quantity: %d', 'oc-theme' ), (int) $item->get_quantity() ) ) . '</div>'
				. '</td>'
				. '<td valign="top" align="left" style="padding:16px 0;white-space:nowrap;font-size:15.5px;font-weight:700;color:' . esc_attr( $p['ink'] ) . ';">'
				. wp_kses_post( $order->get_formatted_line_subtotal( $item ) )
				. '</td></tr>';
		}

		// Woo hands these over in its own order, and on some shops the
		// payment method lands after the grand total — which reads as if the
		// sum were not the end of the sum. The total goes last.
		$rows_in = (array) $order->get_order_item_totals();
		$grand   = isset( $rows_in['order_total'] ) ? array( 'order_total' => $rows_in['order_total'] ) : array();

		unset( $rows_in['order_total'] );

		$totals = '';

		foreach ( array_merge( $rows_in, $grand ) as $key => $total ) {
			$last    = 'order_total' === $key;
			$totals .= '<tr>'
				. '<td style="padding:' . ( $last ? '14px 0 0' : '7px 0' ) . ';font-size:' . ( $last ? '16px' : '14.5px' ) . ';'
				. 'font-weight:' . ( $last ? '700' : '400' ) . ';color:' . esc_attr( $last ? $p['ink'] : $p['soft'] ) . ';'
				. ( $last ? 'border-top:1px solid ' . esc_attr( $p['line'] ) . ';' : '' ) . '">'
				. wp_kses_post( (string) $total['label'] ) . '</td>'
				. '<td align="left" style="padding:' . ( $last ? '14px 0 0' : '7px 0' ) . ';font-size:' . ( $last ? '19px' : '14.5px' ) . ';'
				. 'font-weight:' . ( $last ? '700' : '600' ) . ';color:' . esc_attr( $p['ink'] ) . ';white-space:nowrap;'
				. ( $last ? 'border-top:1px solid ' . esc_attr( $p['line'] ) . ';' : '' ) . '">'
				. wp_kses_post( (string) $total['value'] ) . '</td>'
				. '</tr>';
		}

		return '<div style="margin:28px 0 0;padding:24px 0 0;border-top:1px solid ' . esc_attr( $p['line'] ) . ';">'
			. '<div style="margin:0 0 4px;font-size:17px;font-weight:700;color:' . esc_attr( $p['ink'] ) . ';">'
			. esc_html__( 'Order details', 'oc-theme' ) . '</div>'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">' . $rows . '</table>'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:10px 0 0;padding-top:12px;border-top:1px solid ' . esc_attr( $p['line'] ) . ';">'
			. $totals . '</table></div>';
	}

	/**
	 * "Need a hand?" — the shop's own channels as cards, two to a row, and
	 * nothing that has not been filled in.
	 *
	 * The tint sits on a table CELL. Gmail will not let an anchor be a block
	 * with a background around a table, and the first version came out as a
	 * grey bar floating above the words.
	 *
	 * @param \WC_Email|null $email The email being sent, when there is one.
	 */
	public static function help( $email = null ): string {
		// Off is a real answer: a shop with a busy inbox may not want three
		// invitations to write to it at the foot of every order.
		//
		// The email this theme sends itself — the back-in-stock one — has no
		// settings screen of its own, so it follows the switch on the first
		// order email. One switch, every email, which is what a shop means
		// when it turns the block off.
		$on = null !== $email
			? self::opt( $email, 'oc_show_help', 'yes' )
			: self::saved( 'customer_processing_order', 'oc_show_help', 'yes' );

		if ( 'yes' !== $on ) {
			return '';
		}

		$p     = self::palette();
		$phone = Contact::phone();
		$mail  = Contact::email();
		$wa    = Contact::whatsapp();

		$cards = array();

		if ( '' !== $phone ) {
			$cards[] = array( 'phone', __( 'Call us', 'oc-theme' ), $phone, 'tel:' . preg_replace( '/[^0-9+]/', '', $phone ) );
		}

		if ( '' !== $wa ) {
			$cards[] = array( 'chat', __( 'WhatsApp', 'oc-theme' ), $wa, 'https://wa.me/' . Contact::wa_digits( $wa ) );
		}

		if ( '' !== $mail ) {
			$cards[] = array( 'mail', __( 'Email us', 'oc-theme' ), $mail, 'mailto:' . $mail );
		}

		if ( ! $cards ) {
			return '';
		}

		$one = static function ( array $c ) use ( $p ): string {
			return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">'
				. '<tr><td style="background:' . esc_attr( $p['panel'] ) . ';border-radius:12px;padding:16px 18px;">'
				. '<table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>'
				. '<td valign="middle" width="26" style="width:26px;">'
				. '<a href="' . esc_url( $c[3] ) . '" style="text-decoration:none;">'
				. '<img src="' . esc_url( self::icon_url( $c[0] ) ) . '" width="24" height="24" alt=""'
				. ' style="display:block;border:0;width:24px;height:24px;" /></a></td>'
				. '<td valign="middle" style="padding:0 12px;">'
				. '<a href="' . esc_url( $c[3] ) . '" style="text-decoration:none;color:' . esc_attr( $p['ink'] ) . ';">'
				. '<span style="display:block;font-size:15.5px;font-weight:700;line-height:1.3;color:' . esc_attr( $p['ink'] ) . ';">'
				. esc_html( $c[1] ) . '</span>'
				. '<span style="display:block;margin-top:3px;font-size:14px;line-height:1.4;color:' . esc_attr( $p['soft'] ) . ';">'
				. '<span dir="ltr" style="unicode-bidi:plaintext;color:' . esc_attr( $p['soft'] ) . ';text-decoration:none;">' . esc_html( $c[2] ) . '</span></span>'
				. '</a></td></tr></table>'
				. '</td></tr></table>';
		};

		// Two to a row, stacking on a phone.
		$rows  = '';
		$pairs = array_chunk( $cards, 2 );

		foreach ( $pairs as $pair ) {
			$rows .= '<tr>';
			$rows .= '<td class="oc-card" width="50%" valign="top" style="padding:0 0 12px;">' . $one( $pair[0] ) . '</td>';
			$rows .= '<td class="oc-gap" width="12" style="width:12px;">&nbsp;</td>';
			$rows .= isset( $pair[1] )
				? '<td class="oc-card" width="50%" valign="top" style="padding:0 0 12px;">' . $one( $pair[1] ) . '</td>'
				: '<td class="oc-card" width="50%">&nbsp;</td>';
			$rows .= '</tr>';
		}

		return '<div style="margin:30px 0 0;padding:24px 0 0;border-top:1px solid ' . esc_attr( $p['line'] ) . ';">'
			. '<div style="margin:0 0 14px;font-size:17px;font-weight:700;text-align:' . self::align() . ';color:' . esc_attr( $p['ink'] ) . ';">'
			. esc_html__( 'Here for any question', 'oc-theme' ) . '</div>'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">' . $rows . '</table>'
			. '</div>';
	}

	/**
	 * "Follow us" — a round button per profile the shop has filled in.
	 */
	public static function social(): string {
		$p     = self::palette();
		$links = Contact::social_links();

		if ( ! $links ) {
			return '';
		}

		$cells = '';

		foreach ( $links as $net => $url ) {
			// A profile with nothing behind it is not a profile. esc_url()
			// hands back an empty string for anything that is not a real
			// address, and an icon that goes nowhere is worse than one icon
			// fewer.
			$href = esc_url( (string) $url );

			if ( '' === $href ) {
				continue;
			}

			// The anchor sits INSIDE the cell, around the picture. An
			// <a style="display:block"> wrapped around a table collapses in
			// Gmail — the circles drew, and not one of them was clickable.
			$cells .= '<td style="padding:0 5px;">'
				. '<table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>'
				. '<td align="center" valign="middle" width="40" height="40" style="width:40px;height:40px;line-height:40px;'
				. 'border-radius:20px;background:' . esc_attr( $p['panel'] ) . ';">'
				. '<a href="' . $href . '" style="text-decoration:none;border:0;">'
				. '<img src="' . esc_url( self::icon_url( (string) $net ) ) . '" width="19" height="19"'
				. ' alt="' . esc_attr( ucfirst( (string) $net ) ) . '" style="display:inline-block;border:0;width:19px;height:19px;vertical-align:middle;" />'
				. '</a>'
				. '</td></tr></table></td>';
		}

		if ( '' === $cells ) {
			return '';
		}

		return '<div style="margin:24px 0 0;padding:20px 0 4px;border-top:1px solid ' . esc_attr( $p['line'] ) . ';text-align:center;">'
			. '<div style="margin:0 0 12px;font-size:13.5px;font-weight:600;color:' . esc_attr( $p['soft'] ) . ';">'
			. esc_html__( 'Follow us', 'oc-theme' ) . '</div>'
			. '<table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center"><tr>' . $cells . '</tr></table>'
			. '</div>';
	}

	/* ---------------------------------------------------------------- body */

	/**
	 * A whole customer email.
	 *
	 * @param \WC_Order $order Order.
	 * @param mixed     $email The WC_Email being sent, for its wording.
	 * @param int       $done  Stops behind us: 1 while it is being prepared, 2 once it has left, 0 for no progress bar at all.
	 * @param array     $words Defaults: heading, heading_pickup, intro, intro_pickup.
	 * @param string    $extra Anything that belongs under the order, already marked up.
	 */
	public static function body( $order, $email, int $done, array $words, string $extra = '' ): string {
		$p           = self::palette();
		$pickup      = self::is_pickup( $order );
		self::$align = self::opt( $email, 'oc_align', 'center' );

		$head = $pickup
			? self::opt( $email, 'oc_heading_pickup', (string) ( $words['heading_pickup'] ?? '' ) )
			: '';

		if ( '' === $head ) {
			// Woo's own heading field, but only if the shop actually wrote in
			// it. Asking get_heading() hands back WooCommerce's stock line
			// ("Thanks for shopping with us") even when nobody has touched
			// the box, and ours — the one the settings screen shows as the
			// default — would then never be seen.
			$own = is_object( $email ) && method_exists( $email, 'get_option' ) ? trim( (string) $email->get_option( 'heading' ) ) : '';
			$def = is_object( $email ) && method_exists( $email, 'get_default_heading' ) ? trim( (string) $email->get_default_heading() ) : '';

			$head = ( '' !== $own && $own !== $def )
				? $own
				: (string) ( $words['heading'] ?? '' );

			if ( '' === trim( $head ) ) {
				$head = $own;
			}
		}

		$intro = $pickup
			? self::opt( $email, 'oc_intro_pickup', self::opt( $email, 'oc_intro', (string) ( $words['intro_pickup'] ?? '' ) ) )
			: self::opt( $email, 'oc_intro', (string) ( $words['intro'] ?? '' ) );

		$due  = '';
		$swap = array();

		// Both emails: "when will it come" is the question the second one is
		// opened to answer, and leaving it out there was the whole reason to
		// send it.
		// Only where there is something still to arrive. An order on hold, an
		// order cancelled and an order refunded have no arrival to estimate,
		// and a date on one of those would be a lie in a kind voice.
		if ( ! $pickup && $done > 0 ) {
			$window = WooCommerce::delivery_window();

			if ( $window ) {
				$swap['[from]'] = (string) $window['from'];
				$swap['[to]']   = (string) $window['to'];
				$due            = $window['one_day']
					? (string) $window['from']
					: sprintf( '%s–%s', (string) $window['from'], (string) $window['to'] );
			}
		}

		// A shop that wrote the dates into its own wording and has no window
		// to give must not be left with the placeholders showing.
		if ( ! isset( $swap['[from]'] ) ) {
			$swap['[from]'] = '';
			$swap['[to]']   = '';
		}

		$text = self::paragraphs( $intro, $swap );

		// A line left holding nothing but punctuation once the dates came
		// out empty is worse than no line.
		$text = preg_replace( '/<p[^>]*>[\s\p{P}]*<\/p>/u', '', (string) $text );

		// The shop's own date format, unless it is still WordPress's English
		// default — which on a Hebrew shop writes "ספטמבר 9, 2026", the month
		// and the day the wrong way round.
		$fmt = trim( (string) get_option( 'date_format' ) );

		if ( '' === $fmt || 'F j, Y' === $fmt ) {
			/* translators: PHP date format for the date an order was placed. See https://www.php.net/date */
			$fmt = _x( 'F j, Y', 'the date an order was placed', 'oc-theme' );
		}

		$made = $order->get_date_created();
		$day  = $made ? wc_format_datetime( $made, $fmt ) : '';

		$out  = '<div class="oc-pad" style="padding:34px 32px 30px;">';
		$out .= '<h1 class="oc-h1" style="margin:0 0 8px;font-size:27px;line-height:1.3;font-weight:700;text-align:' . self::align() . ';color:' . esc_attr( $p['ink'] ) . ';">'
			. esc_html( $head ) . '</h1>';
		$out .= (string) $text;

		if ( $done > 0 ) {
			$out .= self::steps( $order, $done, $due );
		}

		$out .= self::button( $order );
		$out .= '<div style="margin:26px 0 0;padding:16px 0 0;border-top:1px solid ' . esc_attr( $p['line'] ) . ';">'
			. '<div style="font-size:14.5px;font-weight:700;color:' . esc_attr( $p['ink'] ) . ';">'
			/* translators: %s: the order number. */
			. esc_html( sprintf( __( 'Order %s', 'oc-theme' ), '#' . $order->get_order_number() ) ) . '</div>'
			. ( '' !== $day
				? '<div style="margin:3px 0 0;font-size:13.5px;line-height:1.5;color:' . esc_attr( $p['soft'] ) . ';">'
					/* translators: %s: the date the order was placed. */
					. esc_html( sprintf( __( 'Placed on %s', 'oc-theme' ), $day ) ) . '</div>'
				: '' )
			. '</div>';
		$out .= self::parties( $order );
		$out .= self::items( $order );
		$out .= $extra;

		$out .= self::help( $email );
		$out .= self::social();
		$out .= '</div>';

		return self::open() . $out . self::close();
	}

	/**
	 * "It is back" — the email a shopper who asked to be told gets.
	 *
	 * The same shell as the order emails on purpose: a shop that sends one
	 * good-looking email and one built in another decade has not branded
	 * anything. There is no progress bar here — there is nothing in
	 * progress — so the picture of the thing itself carries the email.
	 *
	 * @param \WC_Product $product The product, or the variation, that is back.
	 * @param string      $person  The shopper's first name, if we know it.
	 * @param string      $manage  URL of their stock-alert list, if there is one.
	 */
	public static function restock( $product, string $person = '', string $manage = '' ): string {
		$p           = self::palette();
		self::$align = 'center';

		$name = (string) $product->get_name();
		$url  = (string) $product->get_permalink();

		$head = '' !== trim( $person )
			/* translators: %s: the shopper's first name. */
			? sprintf( __( '%s, it is back in stock', 'oc-theme' ), trim( $person ) )
			: __( 'It is back in stock', 'oc-theme' );

		/* translators: %s: product name. */
		$text = sprintf( __( 'You asked us to tell you when %s came back — it is here.', 'oc-theme' ), $name )
			. "\n" . __( 'It can be ordered right now, and it does not usually stay long.', 'oc-theme' );

		// A variation shows its own picture when it has one and the parent's
		// when it does not — which is most of the time.
		$pic = (int) $product->get_image_id();

		if ( ! $pic && $product->get_parent_id() ) {
			$pic = (int) get_post_thumbnail_id( $product->get_parent_id() );
		}

		$src = $pic ? (string) wp_get_attachment_image_url( $pic, 'woocommerce_single' ) : '';

		$card = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:24px 0 0;'
			. 'background:' . esc_attr( $p['panel'] ) . ';border-radius:14px;"><tr><td align="center" style="padding:24px 20px;">';

		if ( '' !== $src ) {
			$card .= '<a href="' . esc_url( $url ) . '" style="text-decoration:none;">'
				. '<img src="' . esc_url( $src ) . '" alt="' . esc_attr( $name ) . '" width="320"'
				. ' style="display:block;margin:0 auto;width:100%;max-width:320px;height:auto;border:0;border-radius:12px;" /></a>';
		}

		$card .= '<div style="margin:16px 0 0;font-size:18px;font-weight:700;line-height:1.4;color:' . esc_attr( $p['ink'] ) . ';">'
			. '<a href="' . esc_url( $url ) . '" style="color:' . esc_attr( $p['ink'] ) . ';text-decoration:none;">'
			. esc_html( $name ) . '</a></div>';

		$price = (string) $product->get_price_html();

		if ( '' !== trim( $price ) ) {
			// The price comes out of WooCommerce as markup, and the currency
			// sign in it is a neutral character: without plaintext bidi it
			// lands on the wrong side of the number in a Hebrew email.
			$card .= '<div style="margin:6px 0 0;font-size:16px;font-weight:700;unicode-bidi:plaintext;color:' . esc_attr( $p['ink'] ) . ';">'
				. wp_kses_post( $price ) . '</div>';
		}

		$card .= '</td></tr></table>';

		$out  = '<div class="oc-pad" style="padding:34px 32px 30px;">';
		$out .= '<h1 class="oc-h1" style="margin:0 0 8px;font-size:27px;line-height:1.3;font-weight:700;text-align:' . self::align() . ';color:' . esc_attr( $p['ink'] ) . ';">'
			. esc_html( $head ) . '</h1>';
		$out .= self::paragraphs( $text );
		$out .= $card;
		$out .= self::cta( $url, __( 'Order it now', 'oc-theme' ) );

		if ( '' !== trim( $manage ) ) {
			$out .= '<div style="margin:14px 0 0;text-align:center;font-size:13px;line-height:1.6;">'
				. '<a href="' . esc_url( $manage ) . '" style="color:' . esc_attr( $p['soft'] ) . ';text-decoration:underline;">'
				. esc_html__( 'Manage your stock alerts', 'oc-theme' ) . '</a></div>';
		}

		$out .= self::help();
		$out .= self::social();
		$out .= '</div>';

		return self::open() . $out . self::close(
			/* translators: %s: the shop name. */
			sprintf( __( 'You are receiving this email because you asked %s to tell you when this came back.', 'oc-theme' ), wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ) )
		);
	}

	/**
	 * How much came back, said plainly.
	 *
	 * WooCommerce sends the same email for a full refund and a part of one,
	 * and the wording cannot tell them apart. The number can: a part refund
	 * says what it was a part of.
	 *
	 * @param \WC_Order $order Order.
	 */
	public static function refunded( $order ): string {
		$p    = self::palette();
		$back = (float) $order->get_total_refunded();

		if ( $back <= 0 ) {
			return '';
		}

		$total = (float) $order->get_total();
		$part  = $back + 0.01 < $total;

		$sum = $part
			/* translators: 1: amount refunded, 2: the order total. */
			? sprintf( __( '%1$s of %2$s', 'oc-theme' ), wc_price( $back, array( 'currency' => $order->get_currency() ) ), wc_price( $total, array( 'currency' => $order->get_currency() ) ) )
			: wc_price( $back, array( 'currency' => $order->get_currency() ) );

		return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:18px 0 0;'
			. 'background:' . esc_attr( $p['panel'] ) . ';border-radius:12px;"><tr><td style="padding:18px 20px;text-align:' . self::align() . ';">'
			. '<div style="font-size:13px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:' . esc_attr( $p['soft'] ) . ';">'
			. esc_html__( 'Refunded', 'oc-theme' ) . '</div>'
			. '<div style="margin:6px 0 0;font-size:20px;font-weight:700;unicode-bidi:plaintext;color:' . esc_attr( $p['ink'] ) . ';">'
			. wp_kses_post( $sum ) . '</div>'
			. '</td></tr></table>';
	}

	/**
	 * An email about the account rather than about an order — the password
	 * reset and the welcome.
	 *
	 * No order, so no items, no totals and no progress: a heading, the words,
	 * one button, and the same contact block and footer as everything else.
	 *
	 * @param mixed  $email The WC_Email being sent.
	 * @param array  $words Defaults: heading, intro.
	 * @param array  $swap  Placeholder => value, for both heading and words.
	 * @param string $url   Where the button goes, or '' for no button.
	 * @param string $label What the button says.
	 * @param string $why   The footer line saying why this arrived.
	 * @param string $panel Anything to show between words and button.
	 */
	public static function account( $email, array $words, array $swap, string $url, string $label, string $why, string $panel = '' ): string {
		$p           = self::palette();
		self::$align = self::opt( $email, 'oc_align', 'center' );

		$own = is_object( $email ) && method_exists( $email, 'get_option' ) ? trim( (string) $email->get_option( 'heading' ) ) : '';
		$def = is_object( $email ) && method_exists( $email, 'get_default_heading' ) ? trim( (string) $email->get_default_heading() ) : '';

		$head = ( '' !== $own && $own !== $def ) ? $own : (string) ( $words['heading'] ?? '' );
		$head = strtr( $head, $swap );

		$intro = self::opt( $email, 'oc_intro', (string) ( $words['intro'] ?? '' ) );

		$out  = '<div class="oc-pad" style="padding:34px 32px 30px;">';
		$out .= '<h1 class="oc-h1" style="margin:0 0 8px;font-size:27px;line-height:1.3;font-weight:700;text-align:' . self::align() . ';color:' . esc_attr( $p['ink'] ) . ';">'
			. esc_html( $head ) . '</h1>';
		$out .= self::paragraphs( $intro, $swap );
		$out .= $panel;

		if ( '' !== trim( $url ) ) {
			$out .= self::cta( $url, $label );
		}

		$out .= self::help( $email );
		$out .= self::social();
		$out .= '</div>';

		return self::open() . $out . self::close( $why );
	}

	/**
	 * Paragraphs from a textarea, escaped.
	 *
	 * @param string $text  The shop's words.
	 * @param array  $swap  Placeholder => value.
	 */
	public static function paragraphs( string $text, array $swap = array() ): string {
		$p   = self::palette();
		$out = '';

		foreach ( preg_split( '/\R+/', trim( $text ) ) as $line ) {
			$line = trim( (string) $line );

			if ( '' === $line ) {
				continue;
			}

			$line = strtr( $line, $swap );

			$out .= '<p style="margin:0 0 6px;font-size:16px;line-height:1.6;text-align:' . self::align() . ';color:' . esc_attr( $p['soft'] ) . ';">'
				. esc_html( $line ) . '</p>';
		}

		return $out;
	}
}
