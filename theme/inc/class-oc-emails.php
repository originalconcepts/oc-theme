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
	const OURS = array( 'customer_processing_order', 'customer_completed_order' );

	/**
	 * Hook in.
	 */
	public function register(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		foreach ( self::OURS as $id ) {
			add_filter( 'woocommerce_settings_api_form_fields_' . $id, array( $this, 'fields' ) );
		}
	}

	/* ------------------------------------------------------------ settings */

	/**
	 * The words, added to the email's own settings screen.
	 *
	 * @param array $fields Woo's fields.
	 * @return array
	 */
	public function fields( $fields ) {
		$fields = (array) $fields;

		// Woo's own "additional content" is the last thing on the screen and
		// ours belongs with the rest of the wording, so it is re-appended.
		$tail = isset( $fields['additional_content'] ) ? array( 'additional_content' => $fields['additional_content'] ) : array();
		unset( $fields['additional_content'] );

		$fields['oc_heading_pickup'] = array(
			'title'       => __( 'Heading — collection', 'oc-theme' ),
			'type'        => 'text',
			'desc_tip'    => __( 'Used instead of the heading above when the order is being collected rather than delivered.', 'oc-theme' ),
			'placeholder' => '',
			'default'     => '',
		);

		$fields['oc_intro'] = array(
			'title'       => __( 'Opening words — delivery', 'oc-theme' ),
			'type'        => 'textarea',
			'css'         => 'width:100%;height:90px;',
			'desc_tip'    => __( 'The paragraph under the heading. One line per paragraph.', 'oc-theme' ),
			'default'     => '',
			'placeholder' => '',
		);

		$fields['oc_intro_pickup'] = array(
			'title'       => __( 'Opening words — collection', 'oc-theme' ),
			'type'        => 'textarea',
			'css'         => 'width:100%;height:90px;',
			'desc_tip'    => __( 'The same, for an order being collected. Left empty, the delivery wording is used.', 'oc-theme' ),
			'default'     => '',
			'placeholder' => '',
		);

		$fields = array_merge( $fields, $tail );

		return $fields;
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
			$src = wp_get_attachment_image_url( $id, 'medium' );
			$meta = wp_get_attachment_image_src( $id, 'medium' );

			if ( $src ) {
				// A height only, and a width the client can work out for
				// itself: a logo scaled by a fixed width goes wrong the first
				// time somebody uploads a square one.
				$h = 46;
				$w = ( $meta && ! empty( $meta[1] ) && ! empty( $meta[2] ) ) ? (int) round( $h * ( (int) $meta[1] / max( 1, (int) $meta[2] ) ) ) : 0;

				return '<img src="' . esc_url( $src ) . '" alt="' . esc_attr( $name ) . '"'
					. ( $w ? ' width="' . $w . '"' : '' ) . ' height="' . $h . '"'
					. ' style="display:block;border:0;outline:none;text-decoration:none;height:' . $h . 'px;width:auto;max-width:220px;" />';
			}
		}

		return '<span style="font-size:20px;font-weight:700;letter-spacing:.02em;color:' . esc_attr( $p['primary'] ) . ';">' . esc_html( $name ) . '</span>';
	}

	/* --------------------------------------------------------------- shell */

	/**
	 * Everything above the message: the page, the card, the logo.
	 */
	public static function open(): string {
		$p   = self::palette();
		$dir = is_rtl() ? 'rtl' : 'ltr';

		return '<!DOCTYPE html><html dir="' . $dir . '" lang="' . esc_attr( get_bloginfo( 'language' ) ) . '">'
			. '<head><meta charset="utf-8" /><meta name="viewport" content="width=device-width,initial-scale=1" />'
			. '<meta name="x-apple-disable-message-reformatting" /><title>' . esc_html( get_bloginfo( 'name' ) ) . '</title></head>'
			. '<body dir="' . $dir . '" style="margin:0;padding:0;background:' . esc_attr( $p['page'] ) . ';">'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:' . esc_attr( $p['page'] ) . ';">'
			. '<tr><td align="center" style="padding:28px 14px;">'
			. '<table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:600px;max-width:100%;">'
			. '<tr><td align="center" style="padding:0 0 20px;">' . self::logo_html() . '</td></tr>'
			. '<tr><td style="background:#ffffff;border-radius:14px;overflow:hidden;">';
	}

	/**
	 * Everything below it.
	 */
	public static function close(): string {
		$p = self::palette();

		return '</td></tr>'
			. '<tr><td align="center" style="padding:18px 10px 0;font-size:12px;line-height:1.7;color:' . esc_attr( $p['soft'] ) . ';">'
			. esc_html( wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ) )
			. '</td></tr></table></td></tr></table></body></html>';
	}

	/* --------------------------------------------------------------- parts */

	/**
	 * Where the order has got to.
	 *
	 * Three stops, and only the first two can ever be ticked. Nothing tells
	 * the shop that a parcel was handed over or that somebody walked in and
	 * collected it — the carrier does not say so and marking it by hand is
	 * work the shop did not ask for. So the last stop is drawn as what it
	 * honestly is: the one still to come, with the date it is expected on.
	 * Pretending to know is worse than an open circle.
	 *
	 * @param \WC_Order $order Order.
	 * @param int       $done  How many stops are behind us (1 or 2).
	 * @param string    $due   The expected-arrival line, or ''.
	 */
	public static function steps( $order, int $done, string $due = '' ): string {
		$p      = self::palette();
		$pickup = self::is_pickup( $order );
		$made   = $order->get_date_created();

		$when = $made ? wp_date( 'j.n', $made->getTimestamp() ) : '';
		$paid = $order->get_date_paid();
		$out  = $paid ? wp_date( 'j.n', $paid->getTimestamp() ) : '';

		if ( $done > 1 && '' === $out ) {
			$mod = $order->get_date_modified();
			$out = $mod ? wp_date( 'j.n', $mod->getTimestamp() ) : '';
		}

		$stops = array(
			array( __( 'Received', 'oc-theme' ), $when ),
			array( $pickup ? __( 'Ready for collection', 'oc-theme' ) : __( 'On its way', 'oc-theme' ), $done > 1 ? $out : '' ),
			array( $pickup ? __( 'Collected', 'oc-theme' ) : __( 'With you', 'oc-theme' ), $done > 2 ? '' : $due ),
		);

		$cells = '';

		foreach ( $stops as $i => $stop ) {
			$on   = $i < $done;
			$fill = $on ? $p['cta'] : '#ffffff';
			$edge = $on ? $p['cta'] : '#cdd2d8';
			$mark = $on
				? '<span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:#ffffff;"></span>'
				: '';

			$cells .= '<td align="center" width="33%" style="padding:0 2px;">'
				. '<table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center"><tr>'
				. '<td align="center" valign="middle" width="22" height="22" style="width:22px;height:22px;line-height:22px;'
				. 'border:2px solid ' . esc_attr( $edge ) . ';border-radius:50%;background:' . esc_attr( $fill ) . ';">'
				. $mark . '</td></tr></table>'
				. '<div style="margin:7px 0 0;font-size:12.5px;font-weight:600;color:' . esc_attr( $on ? $p['ink'] : $p['soft'] ) . ';">'
				. esc_html( $stop[0] ) . '</div>'
				. ( '' !== $stop[1] ? '<div style="margin:2px 0 0;font-size:11.5px;color:' . esc_attr( $p['soft'] ) . ';">' . esc_html( $stop[1] ) . '</div>' : '' )
				. '</td>';
		}

		// The rule behind the circles, drawn as two halves so the colour can
		// change where the progress does.
		$bar = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 -11px;"><tr>'
			. '<td width="17%"></td>'
			. '<td height="2" style="height:2px;line-height:2px;font-size:0;background:' . esc_attr( $done > 1 ? $p['cta'] : '#cdd2d8' ) . ';">&nbsp;</td>'
			. '<td height="2" style="height:2px;line-height:2px;font-size:0;background:#cdd2d8;">&nbsp;</td>'
			. '<td width="17%"></td>'
			. '</tr></table>';

		return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:22px 0 4px;">'
			. '<tr><td>' . $bar . '</td></tr>'
			. '<tr><td><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>' . $cells . '</tr></table></td></tr>'
			. '</table>';
	}

	/**
	 * The button onto the order's own page.
	 *
	 * @param \WC_Order $order Order.
	 */
	public static function button( $order ): string {
		$p = self::palette();

		return '<table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="margin:22px auto 4px;"><tr>'
			. '<td align="center" style="border-radius:8px;background:' . esc_attr( $p['cta'] ) . ';">'
			. '<a href="' . esc_url( $order->get_view_order_url() ) . '" style="display:inline-block;padding:13px 34px;font-size:15px;font-weight:700;'
			. 'color:#ffffff;text-decoration:none;border-radius:8px;">' . esc_html__( 'View your order', 'oc-theme' ) . '</a>'
			. '</td></tr></table>';
	}

	/**
	 * Who ordered, and where it is going — side by side, and stacked on a
	 * phone because the cells are given percentage widths on one row.
	 *
	 * @param \WC_Order $order Order.
	 */
	public static function parties( $order ): string {
		$p      = self::palette();
		$pickup = self::is_pickup( $order );

		$h = static function ( string $text ) use ( $p ): string {
			return '<div style="margin:0 0 8px;font-size:11px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:' . esc_attr( $p['soft'] ) . ';">' . esc_html( $text ) . '</div>';
		};

		$line = static function ( string $text, bool $ltr = false ) use ( $p ): string {
			if ( '' === trim( $text ) ) {
				return '';
			}

			return '<div' . ( $ltr ? ' dir="ltr"' : '' ) . ' style="font-size:14px;line-height:1.65;color:' . esc_attr( $p['ink'] ) . ';'
				. ( $ltr ? 'unicode-bidi:plaintext;' : '' ) . '">' . esc_html( $text ) . '</div>';
		};

		$who = $h( __( 'Orderer details', 'oc-theme' ) )
			. '<div style="font-size:14.5px;font-weight:600;line-height:1.65;color:' . esc_attr( $p['ink'] ) . ';">'
			. esc_html( trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ) ) . '</div>'
			. $line( (string) $order->get_billing_phone(), true )
			. $line( (string) $order->get_billing_email(), true );

		if ( $pickup ) {
			$where  = $h( __( 'Collection', 'oc-theme' ) );
			$branch = (string) $order->get_meta( '_oc_branch_name' );

			if ( '' !== $branch ) {
				$where .= '<div style="font-size:14.5px;font-weight:600;line-height:1.65;color:' . esc_attr( $p['ink'] ) . ';">' . esc_html( $branch ) . '</div>';

				$id = (int) $order->get_meta( '_oc_branch' );

				if ( $id && class_exists( '\\OC\\Blocks\\Branches' ) ) {
					$d = \OC\Blocks\Branches::details( $id );
					$where .= $line( (string) ( $d['address'] ?? '' ) );
					$where .= $line( (string) ( $d['phone'] ?? '' ), true );
				}
			} else {
				$where .= $line( __( 'Collection in person', 'oc-theme' ) );
			}
		} else {
			$bits = array_filter(
				array(
					trim( (string) $order->get_billing_address_1() . ' ' . (string) $order->get_billing_address_2() ),
					(string) $order->get_billing_city(),
				),
				static function ( $v ) {
					return '' !== trim( (string) $v );
				}
			);

			$where = $h( __( 'Delivery address', 'oc-theme' ) );

			foreach ( $bits as $bit ) {
				$where .= $line( (string) $bit );
			}
		}

		return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:24px 0 0;">'
			. '<tr>'
			. '<td width="50%" valign="top" style="padding:0 0 14px;">' . $who . '</td>'
			. '<td width="50%" valign="top" style="padding:0 0 14px;">' . $where . '</td>'
			. '</tr></table>';
	}

	/**
	 * What was bought, with the pictures — the part a shopper scrolls to.
	 *
	 * @param \WC_Order $order Order.
	 */
	public static function items( $order ): string {
		$p    = self::palette();
		$rows = '';

		foreach ( $order->get_items() as $item ) {
			$product = $item->get_product();
			$pic     = '';

			if ( $product ) {
				$id = (int) $product->get_image_id();

				if ( $id ) {
					$src = wp_get_attachment_image_url( $id, 'woocommerce_thumbnail' );

					if ( $src ) {
						$pic = '<img src="' . esc_url( $src ) . '" width="64" height="64" alt=""'
							. ' style="display:block;border:0;width:64px;height:64px;object-fit:cover;border-radius:8px;background:' . esc_attr( $p['panel'] ) . ';" />';
					}
				}
			}

			if ( '' === $pic ) {
				$pic = '<div style="width:64px;height:64px;border-radius:8px;background:' . esc_attr( $p['panel'] ) . ';"></div>';
			}

			$meta = wc_display_item_meta( $item, array( 'echo' => false, 'separator' => ' · ', 'before' => '', 'after' => '' ) );

			$rows .= '<tr>'
				. '<td width="64" valign="top" style="padding:12px 0;">' . $pic . '</td>'
				. '<td valign="top" style="padding:12px 12px;">'
				. '<div style="font-size:14px;font-weight:600;line-height:1.5;color:' . esc_attr( $p['ink'] ) . ';">' . esc_html( $item->get_name() ) . '</div>'
				. ( $meta ? '<div style="margin:3px 0 0;font-size:12.5px;line-height:1.6;color:' . esc_attr( $p['soft'] ) . ';">' . wp_kses_post( $meta ) . '</div>' : '' )
				. '<div style="margin:3px 0 0;font-size:12.5px;color:' . esc_attr( $p['soft'] ) . ';">'
				/* translators: %d: how many of this item. */
				. esc_html( sprintf( __( 'Quantity: %d', 'oc-theme' ), (int) $item->get_quantity() ) ) . '</div>'
				. '</td>'
				. '<td valign="top" align="left" style="padding:12px 0;white-space:nowrap;font-size:14px;font-weight:600;color:' . esc_attr( $p['ink'] ) . ';">'
				. wp_kses_post( $order->get_formatted_line_subtotal( $item ) )
				. '</td></tr>';
		}

		$totals = '';

		foreach ( $order->get_order_item_totals() as $key => $total ) {
			$last    = 'order_total' === $key;
			$totals .= '<tr>'
				. '<td style="padding:' . ( $last ? '12px 0 0' : '5px 0' ) . ';font-size:' . ( $last ? '15px' : '13.5px' ) . ';'
				. 'font-weight:' . ( $last ? '700' : '400' ) . ';color:' . esc_attr( $last ? $p['ink'] : $p['soft'] ) . ';'
				. ( $last ? 'border-top:1px solid ' . esc_attr( $p['line'] ) . ';' : '' ) . '">'
				. wp_kses_post( (string) $total['label'] ) . '</td>'
				. '<td align="left" style="padding:' . ( $last ? '12px 0 0' : '5px 0' ) . ';font-size:' . ( $last ? '17px' : '13.5px' ) . ';'
				. 'font-weight:' . ( $last ? '700' : '600' ) . ';color:' . esc_attr( $last ? $p['ink'] : $p['ink'] ) . ';white-space:nowrap;'
				. ( $last ? 'border-top:1px solid ' . esc_attr( $p['line'] ) . ';' : '' ) . '">'
				. wp_kses_post( (string) $total['value'] ) . '</td>'
				. '</tr>';
		}

		return '<div style="margin:26px 0 0;padding:2px 0 0;border-top:1px solid ' . esc_attr( $p['line'] ) . ';">'
			. '<div style="margin:16px 0 0;font-size:11px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:' . esc_attr( $p['soft'] ) . ';">'
			. esc_html__( 'Order details', 'oc-theme' ) . '</div>'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">' . $rows . '</table>'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:6px 0 0;padding-top:8px;border-top:1px solid ' . esc_attr( $p['line'] ) . ';">'
			. $totals . '</table></div>';
	}

	/**
	 * "A question?" — the shop's own channels, and nothing that is not set.
	 */
	public static function help(): string {
		$p     = self::palette();
		$phone = Contact::phone();
		$mail  = Contact::email();
		$wa    = Contact::whatsapp();

		$links = array();

		if ( '' !== $phone ) {
			$links[] = array( __( 'Call', 'oc-theme' ), 'tel:' . preg_replace( '/[^0-9+]/', '', $phone ), $phone );
		}

		if ( '' !== $wa ) {
			$links[] = array( __( 'WhatsApp', 'oc-theme' ), 'https://wa.me/' . Contact::wa_digits( $wa ), $wa );
		}

		if ( '' !== $mail ) {
			$links[] = array( __( 'Email', 'oc-theme' ), 'mailto:' . $mail, $mail );
		}

		if ( ! $links ) {
			return '';
		}

		$cells = '';

		foreach ( $links as $one ) {
			$cells .= '<td align="center" style="padding:4px 6px;">'
				. '<a href="' . esc_url( $one[1] ) . '" style="display:block;padding:10px 14px;border:1px solid ' . esc_attr( $p['line'] ) . ';'
				. 'border-radius:8px;text-decoration:none;background:#ffffff;">'
				. '<span style="display:block;font-size:12px;color:' . esc_attr( $p['soft'] ) . ';">' . esc_html( $one[0] ) . '</span>'
				. '<span dir="ltr" style="display:block;margin-top:2px;font-size:13.5px;font-weight:600;color:' . esc_attr( $p['ink'] ) . ';unicode-bidi:plaintext;">'
				. esc_html( $one[2] ) . '</span></a></td>';
		}

		return '<div style="margin:26px 0 0;padding:18px 0 0;border-top:1px solid ' . esc_attr( $p['line'] ) . ';">'
			. '<div style="text-align:center;font-size:15px;font-weight:700;color:' . esc_attr( $p['ink'] ) . ';">'
			. esc_html__( 'Any questions?', 'oc-theme' ) . '</div>'
			. '<div style="margin:4px 0 12px;text-align:center;font-size:13px;color:' . esc_attr( $p['soft'] ) . ';">'
			. esc_html__( 'We are here — whichever way suits you.', 'oc-theme' ) . '</div>'
			. '<table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center"><tr>' . $cells . '</tr></table>'
			. '</div>';
	}

	/**
	 * "Follow us" — only the profiles the shop has actually filled in.
	 */
	public static function social(): string {
		$p     = self::palette();
		$links = Contact::social_links();

		if ( ! $links ) {
			return '';
		}

		$cells = '';

		foreach ( $links as $net => $url ) {
			$cells .= '<td style="padding:0 7px;">'
				. '<a href="' . esc_url( $url ) . '" style="font-size:13px;font-weight:600;color:' . esc_attr( $p['primary'] ) . ';text-decoration:none;">'
				. esc_html( ucfirst( (string) $net ) ) . '</a></td>';
		}

		return '<div style="margin:22px 0 0;padding:16px 0 2px;border-top:1px solid ' . esc_attr( $p['line'] ) . ';text-align:center;">'
			. '<div style="margin:0 0 8px;font-size:13px;color:' . esc_attr( $p['soft'] ) . ';">' . esc_html__( 'Follow us', 'oc-theme' ) . '</div>'
			. '<table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center"><tr>' . $cells . '</tr></table>'
			. '</div>';
	}

	/* ---------------------------------------------------------------- body */

	/**
	 * A whole customer email.
	 *
	 * @param \WC_Order $order Order.
	 * @param mixed     $email The WC_Email being sent, for its wording.
	 * @param int       $done  Stops behind us: 1 while it is being prepared, 2 once it has left.
	 * @param array     $words Defaults: heading, heading_pickup, intro, intro_pickup.
	 */
	public static function body( $order, $email, int $done, array $words ): string {
		$p      = self::palette();
		$pickup = self::is_pickup( $order );

		$head = $pickup
			? self::opt( $email, 'oc_heading_pickup', (string) ( $words['heading_pickup'] ?? '' ) )
			: '';

		if ( '' === $head ) {
			$head = is_object( $email ) && method_exists( $email, 'get_heading' ) ? (string) $email->get_heading() : '';
			$head = '' !== trim( $head ) ? $head : (string) ( $words['heading'] ?? '' );
		}

		$intro = $pickup
			? self::opt( $email, 'oc_intro_pickup', self::opt( $email, 'oc_intro', (string) ( $words['intro_pickup'] ?? '' ) ) )
			: self::opt( $email, 'oc_intro', (string) ( $words['intro'] ?? '' ) );

		// The window is only a promise worth making while the order is still
		// being prepared, and only where something is being carried.
		$due  = '';
		$swap = array();

		if ( 1 === $done && ! $pickup ) {
			$window = WooCommerce::delivery_window();

			if ( $window ) {
				$swap['[from]'] = (string) $window['from'];
				$swap['[to]']   = (string) $window['to'];
				$due            = $window['one_day']
					? (string) $window['from']
					: sprintf( '%s – %s', (string) $window['from'], (string) $window['to'] );
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
		$text = preg_replace( '/<p[^>]*>[\s\p{P}]*<\/p>/u', '', $text );

		$out  = '<div style="padding:30px 28px 26px;">';
		$out .= '<h1 style="margin:0 0 12px;font-size:23px;line-height:1.35;font-weight:700;color:' . esc_attr( $p['ink'] ) . ';">'
			. esc_html( $head ) . '</h1>';
		$out .= (string) $text;
		$out .= self::steps( $order, $done, $due );
		$out .= self::button( $order );
		$out .= '<div style="margin:22px 0 0;padding:14px 0 0;border-top:1px solid ' . esc_attr( $p['line'] ) . ';font-size:13px;color:' . esc_attr( $p['soft'] ) . ';">'
			/* translators: %s: the order number. */
			. esc_html( sprintf( __( 'Order %s', 'oc-theme' ), '#' . $order->get_order_number() ) ) . '</div>';
		$out .= self::parties( $order );
		$out .= self::items( $order );
		$out .= self::help();
		$out .= self::social();
		$out .= '</div>';

		return self::open() . $out . self::close();
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

			$out .= '<p style="margin:0 0 10px;font-size:15px;line-height:1.7;color:' . esc_attr( $p['soft'] ) . ';">'
				. esc_html( $line ) . '</p>';
		}

		return $out;
	}
}
