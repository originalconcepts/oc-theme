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
	 * @param array $fields Woo's fields.
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
		$fields['oc_heading_pickup'] = array(
			'title'       => __( 'Heading — collection', 'oc-theme' ),
			'type'        => 'text',
			'description' => __( 'Shown instead of the heading above when the order is being collected.', 'oc-theme' ),
			'desc_tip'    => true,
			'default'     => $words['heading_pickup'],
		);

		$fields['oc_intro'] = array(
			'title'       => __( 'Opening words — delivery', 'oc-theme' ),
			'type'        => 'textarea',
			'css'         => 'width:100%;height:110px;',
			'description' => __( 'The paragraph under the heading. Each line is its own paragraph. [from] and [to] become the estimated arrival dates — delete that line to leave the estimate out.', 'oc-theme' ),
			'default'     => $words['intro'],
		);

		$fields['oc_intro_pickup'] = array(
			'title'       => __( 'Opening words — collection', 'oc-theme' ),
			'type'        => 'textarea',
			'css'         => 'width:100%;height:110px;',
			'description' => __( 'The same, for an order being collected rather than delivered.', 'oc-theme' ),
			'default'     => $words['intro_pickup'],
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
		if ( 'customer_completed_order' === $id ) {
			return array(
				'heading'        => __( 'Your order is on its way', 'oc-theme' ),
				'heading_pickup' => __( 'Your order is ready for you', 'oc-theme' ),
				'intro'          => __( "Your order is packed and has left us — it is on its way to you.\nWe will be in touch if anything changes. Thank you for shopping with us.", 'oc-theme' ),
				'intro_pickup'   => __( "Your order is ready and waiting for you.\nCome whenever it suits you — the details are below. Thank you for shopping with us.", 'oc-theme' ),
			);
		}

		return array(
			'heading'        => __( 'Your order has been received', 'oc-theme' ),
			'heading_pickup' => __( 'Your order has been received', 'oc-theme' ),
			'intro'          => __( "Thank you — your order has been received.\nIt is expected to reach you between [from] and [to].\nWe will email you again the moment it leaves us.", 'oc-theme' ),
			'intro_pickup'   => __( "Thank you — your order has been received.\nWe are getting it ready, and we will email you the moment it is waiting for you.", 'oc-theme' ),
		);
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
			// 300px "medium" is already soft; on the retina screen every phone
			// has, it is visibly rough. The browser downsamples a large file
			// beautifully and upsamples a small one badly.
			$src = wp_get_attachment_image_url( $id, 'full' );
			$raw = wp_get_attachment_image_src( $id, 'full' );

			if ( $src ) {
				$wide = $raw && ! empty( $raw[1] ) && ! empty( $raw[2] ) && (int) $raw[1] >= (int) $raw[2];
				$cap  = $wide ? 168 : 62;

				// One dimension only. Give both and the first non-square logo
				// somebody uploads comes out stretched.
				return '<img src="' . esc_url( $src ) . '" alt="' . esc_attr( $name ) . '"'
					. ( $wide ? ' width="' . $cap . '"' : '' )
					. ' style="display:block;border:0;outline:none;text-decoration:none;'
					. ( $wide ? 'width:' . $cap . 'px;max-width:' . $cap . 'px;height:auto;' : 'height:' . $cap . 'px;width:auto;' )
					. '" />';
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
			. '.oc-step-l{font-size:12px !important}'
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
	 */
	public static function close(): string {
		$p    = self::palette();
		$name = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );

		return '</td></tr>'
			. '<tr><td align="center" style="padding:20px 14px 0;font-size:12.5px;line-height:1.8;color:' . esc_attr( $p['soft'] ) . ';">'
			. '<a href="' . esc_url( home_url( '/' ) ) . '" style="color:' . esc_attr( $p['soft'] ) . ';text-decoration:none;font-weight:600;">'
			. esc_html( $name ) . '</a>'
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
			array( $pickup ? __( 'Ready', 'oc-theme' ) : __( 'On its way', 'oc-theme' ), $done > 1 ? $out : '' ),
			array( $pickup ? __( 'Collected', 'oc-theme' ) : __( 'With you', 'oc-theme' ), $due ),
		);

		$tick  = self::icon_url( 'tick' );
		$cells = '';

		foreach ( $stops as $i => $stop ) {
			$on = $i < $done;

			// The circle is a cell with a background rather than a div with a
			// border-radius, because Outlook rounds nothing — it simply comes
			// out square there, which is fine, and round everywhere else.
			$dot = '<table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center"><tr>'
				. '<td align="center" valign="middle" width="30" height="30" style="width:30px;height:30px;line-height:30px;'
				. 'border-radius:15px;background:' . esc_attr( $on ? $p['cta'] : '#ffffff' ) . ';'
				. 'border:2px solid ' . esc_attr( $on ? $p['cta'] : '#d6dae0' ) . ';">'
				. ( $on
					? '<img src="' . esc_url( $tick ) . '" width="13" height="13" alt="" style="display:block;border:0;width:13px;height:13px;margin:0 auto;" />'
					: '&nbsp;' )
				. '</td></tr></table>';

			$cells .= '<td align="center" width="33.33%" valign="top" style="padding:0 3px;">'
				. $dot
				. '<div class="oc-step-l" style="margin:9px 0 0;font-size:13px;font-weight:700;line-height:1.35;color:'
				. esc_attr( $on ? $p['ink'] : '#98a0aa' ) . ';">' . esc_html( $stop[0] ) . '</div>'
				. ( '' !== $stop[1]
					? '<div style="margin:3px 0 0;font-size:11.5px;line-height:1.4;color:#98a0aa;">' . esc_html( $stop[1] ) . '</div>'
					: '' )
				. '</td>';
		}

		// The rule sits behind the circles: two halves, each coloured by
		// whether the stop it leads to has happened.
		$rule = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>'
			. '<td width="17%" style="font-size:0;line-height:0;">&nbsp;</td>'
			. '<td height="3" style="height:3px;line-height:3px;font-size:0;border-radius:2px;background:' . esc_attr( $done > 1 ? $p['cta'] : '#e2e6ea' ) . ';">&nbsp;</td>'
			. '<td width="6" style="font-size:0;line-height:0;">&nbsp;</td>'
			. '<td height="3" style="height:3px;line-height:3px;font-size:0;border-radius:2px;background:#e2e6ea;">&nbsp;</td>'
			. '<td width="17%" style="font-size:0;line-height:0;">&nbsp;</td>'
			. '</tr></table>';

		return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:24px 0 0;'
			. 'background:' . esc_attr( $p['panel'] ) . ';border-radius:12px;">'
			. '<tr><td style="padding:20px 14px 18px;">'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">'
			. '<tr><td style="padding:0 0 -1px;">' . $rule . '</td></tr>'
			. '<tr><td style="padding:0;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top:-16px;"><tr>' . $cells . '</tr></table></td></tr>'
			. '</table>'
			. '</td></tr></table>';
	}

	/**
	 * The button onto the order's own page.
	 *
	 * @param \WC_Order $order Order.
	 */
	public static function button( $order ): string {
		$p = self::palette();

		return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:22px 0 0;"><tr>'
			. '<td align="center" style="border-radius:10px;background:' . esc_attr( $p['cta'] ) . ';">'
			. '<a href="' . esc_url( $order->get_view_order_url() ) . '" style="display:block;padding:16px 24px;font-size:16px;font-weight:700;'
			. 'color:#ffffff;text-decoration:none;border-radius:10px;letter-spacing:.01em;">'
			. esc_html__( 'View your order', 'oc-theme' ) . '</a>'
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
					$at = \OC\Blocks\Branches::details( $id );

					$where .= $line( (string) ( $at['address'] ?? '' ) );
					$where .= $line( (string) ( $at['phone'] ?? '' ), true );
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
			. '<td class="oc-col" width="50%" valign="top" style="padding:0 0 14px;">' . $who . '</td>'
			. '<td class="oc-gap" width="18" style="width:18px;">&nbsp;</td>'
			. '<td class="oc-col" valign="top" style="padding:0 0 14px;">' . $where . '</td>'
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
			// get_items() is typed as the base item; only a line item knows
			// which product it is.
			$product = $item instanceof \WC_Order_Item_Product ? $item->get_product() : null;
			$pic     = '';

			if ( $product ) {
				$id = (int) $product->get_image_id();

				if ( $id ) {
					$src = wp_get_attachment_image_url( $id, 'woocommerce_thumbnail' );

					if ( $src ) {
						$pic = '<img src="' . esc_url( $src ) . '" width="70" height="70" alt=""'
							. ' style="display:block;border:0;width:70px;height:70px;border-radius:10px;background:' . esc_attr( $p['panel'] ) . ';" />';
					}
				}
			}

			if ( '' === $pic ) {
				$pic = '<table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr><td width="70" height="70"'
					. ' style="width:70px;height:70px;border-radius:10px;background:' . esc_attr( $p['panel'] ) . ';">&nbsp;</td></tr></table>';
			}

			$meta = wc_display_item_meta(
				$item,
				array(
					'echo'      => false,
					'separator' => ' · ',
					'before'    => '',
					'after'     => '',
				)
			);

			// Two columns, never three. A price in a column of its own is the
			// first thing to collapse on a phone, and the name is what gets
			// squeezed to make room for it.
			$rows .= '<tr>'
				. '<td width="70" valign="top" style="width:70px;padding:14px 0;">' . $pic . '</td>'
				. '<td valign="top" style="padding:14px 0;padding-inline-start:14px;">'
				. '<div style="font-size:15px;font-weight:600;line-height:1.45;color:' . esc_attr( $p['ink'] ) . ';">'
				. esc_html( $item->get_name() ) . '</div>'
				. ( $meta
					? '<div style="margin:4px 0 0;font-size:13px;line-height:1.55;color:' . esc_attr( $p['soft'] ) . ';">' . wp_kses_post( $meta ) . '</div>'
					: '' )
				. '<div style="margin:6px 0 0;font-size:13.5px;line-height:1.5;color:' . esc_attr( $p['soft'] ) . ';">'
				/* translators: %d: how many of this item. */
				. esc_html( sprintf( __( 'Quantity: %d', 'oc-theme' ), (int) $item->get_quantity() ) )
				. ' &nbsp;·&nbsp; <span style="font-weight:700;color:' . esc_attr( $p['ink'] ) . ';">'
				. wp_kses_post( $order->get_formatted_line_subtotal( $item ) ) . '</span></div>'
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
				. '<td style="padding:' . ( $last ? '13px 0 0' : '6px 0' ) . ';font-size:' . ( $last ? '15.5px' : '14px' ) . ';'
				. 'font-weight:' . ( $last ? '700' : '400' ) . ';color:' . esc_attr( $last ? $p['ink'] : $p['soft'] ) . ';'
				. ( $last ? 'border-top:1px solid ' . esc_attr( $p['line'] ) . ';' : '' ) . '">'
				. wp_kses_post( (string) $total['label'] ) . '</td>'
				. '<td align="left" style="padding:' . ( $last ? '13px 0 0' : '6px 0' ) . ';font-size:' . ( $last ? '18px' : '14px' ) . ';'
				. 'font-weight:' . ( $last ? '700' : '600' ) . ';color:' . esc_attr( $p['ink'] ) . ';white-space:nowrap;'
				. ( $last ? 'border-top:1px solid ' . esc_attr( $p['line'] ) . ';' : '' ) . '">'
				. wp_kses_post( (string) $total['value'] ) . '</td>'
				. '</tr>';
		}

		return '<div style="margin:26px 0 0;padding:22px 0 0;border-top:1px solid ' . esc_attr( $p['line'] ) . ';">'
			. '<div style="margin:0;font-size:16px;font-weight:700;color:' . esc_attr( $p['ink'] ) . ';">'
			. esc_html__( 'Order details', 'oc-theme' ) . '</div>'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">' . $rows . '</table>'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:8px 0 0;padding-top:10px;border-top:1px solid ' . esc_attr( $p['line'] ) . ';">'
			. $totals . '</table></div>';
	}


	/**
	 * "Need a hand?" — the shop's own channels as cards, two to a row, and
	 * nothing that has not been filled in.
	 */
	public static function help(): string {
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
			return '<a href="' . esc_url( $c[3] ) . '" style="display:block;text-decoration:none;background:' . esc_attr( $p['panel'] ) . ';'
				. 'border-radius:12px;padding:15px 16px;">'
				. '<table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>'
				. '<td valign="middle" width="24" style="width:24px;">'
				. '<img src="' . esc_url( self::icon_url( $c[0] ) ) . '" width="22" height="22" alt=""'
				. ' style="display:block;border:0;width:22px;height:22px;" /></td>'
				. '<td valign="middle" style="padding-inline-start:11px;padding-right:11px;">'
				. '<span style="display:block;font-size:14.5px;font-weight:700;line-height:1.3;color:' . esc_attr( $p['ink'] ) . ';">'
				. esc_html( $c[1] ) . '</span>'
				. '<span dir="ltr" style="display:block;margin-top:2px;font-size:13px;line-height:1.4;color:' . esc_attr( $p['soft'] ) . ';unicode-bidi:plaintext;">'
				. esc_html( $c[2] ) . '</span>'
				. '</td></tr></table></a>';
		};

		// Two to a row, stacking on a phone. Any odd one out simply takes the
		// row it is on rather than being stretched across it.
		$rows  = '';
		$pairs = array_chunk( $cards, 2 );

		foreach ( $pairs as $pair ) {
			$rows .= '<tr>';
			$rows .= '<td class="oc-card" width="50%" valign="top" style="padding:0 0 10px;">' . $one( $pair[0] ) . '</td>';
			$rows .= '<td class="oc-gap" width="10" style="width:10px;">&nbsp;</td>';
			$rows .= isset( $pair[1] )
				? '<td class="oc-card" width="50%" valign="top" style="padding:0 0 10px;">' . $one( $pair[1] ) . '</td>'
				: '<td class="oc-card" width="50%">&nbsp;</td>';
			$rows .= '</tr>';
		}

		return '<div style="margin:28px 0 0;padding:22px 0 0;border-top:1px solid ' . esc_attr( $p['line'] ) . ';">'
			. '<div style="margin:0 0 14px;font-size:16px;font-weight:700;color:' . esc_attr( $p['ink'] ) . ';">'
			. esc_html__( 'Need a hand?', 'oc-theme' ) . '</div>'
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
			$cells .= '<td style="padding:0 5px;">'
				. '<a href="' . esc_url( $url ) . '" style="display:block;text-decoration:none;">'
				. '<table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>'
				. '<td align="center" valign="middle" width="40" height="40" style="width:40px;height:40px;line-height:40px;'
				. 'border-radius:20px;background:' . esc_attr( $p['panel'] ) . ';">'
				. '<img src="' . esc_url( self::icon_url( (string) $net ) ) . '" width="19" height="19"'
				. ' alt="' . esc_attr( ucfirst( (string) $net ) ) . '" style="display:block;border:0;width:19px;height:19px;margin:0 auto;" />'
				. '</td></tr></table></a></td>';
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

		$out  = '<div class="oc-pad" style="padding:34px 32px 30px;">';
		$out .= '<h1 class="oc-h1" style="margin:0 0 14px;font-size:26px;line-height:1.3;font-weight:700;color:' . esc_attr( $p['ink'] ) . ';">'
			. esc_html( $head ) . '</h1>';
		$out .= (string) $text;
		$out .= self::steps( $order, $done, $due );
		$out .= self::button( $order );
		$out .= '<div style="margin:26px 0 0;padding:16px 0 0;border-top:1px solid ' . esc_attr( $p['line'] ) . ';font-size:13.5px;font-weight:600;color:' . esc_attr( $p['soft'] ) . ';">'
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
