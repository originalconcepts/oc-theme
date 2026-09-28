<?php
/**
 * Store details: the one place the shop describes itself.
 *
 * Phone, email, WhatsApp and the social profiles are the shop's assets, not
 * any single page's settings — so they are entered once here and every
 * surface that shows them (checkout help line, thank-you page, anywhere a
 * {phone} / {email} / {whatsapp} placeholder appears) reads from here. The
 * same goes for the legal facts — the operating company, its number, the
 * address, the hours, the accessibility coordinator — which the generated
 * terms and accessibility pages read live through the [oc_site] shortcode.
 *
 * @package OC_Theme
 */

declare( strict_types = 1 );

namespace OC\Theme;

defined( 'ABSPATH' ) || exit;

/**
 * Central store channels: settings, placeholders and rendering.
 */
final class Contact {

	/**
	 * Social networks, in the order they render.
	 *
	 * @return array<string,string>
	 */
	public static function networks(): array {
		return array(
			'instagram' => 'Instagram',
			'facebook'  => 'Facebook',
			'tiktok'    => 'TikTok',
			'youtube'   => 'YouTube',
		);
	}

	/**
	 * Settings with defaults.
	 *
	 * @return array<string,string>
	 */
	public static function settings(): array {
		$saved = get_option( 'oc_contact' );

		return wp_parse_args(
			is_array( $saved ) ? $saved : array(),
			array(
				'phone'         => '',
				'email'         => '',
				'whatsapp'      => '',   // Number for a direct chat.
				'wa_group'      => '',   // Invite link to the shop's group.
				'instagram'     => '',
				'facebook'      => '',
				'tiktok'        => '',
				'youtube'       => '',
				// The business behind the shop, for the legal pages.
				'company'       => '',   // Legal name; empty = the site name.
				'company_id'    => '',   // ח.פ / ע.מ.
				'address'       => '',   // Empty = the WooCommerce store address.
				'hours'         => '',   // Free text, one line per day.
				// How the terms page is written.
				'terms_kind'    => 'general', // general | food.
				'terms_custom'  => 0,    // Made-to-order products: no cancellation once production began.
				'terms_bulky'   => 0,    // Large items: access, stairs, crane, assembly clauses.
				// Accessibility: the coordinator and what the premises offer.
				'a11y_name'     => '',
				'a11y_phone'    => '',
				'a11y_email'    => '',
				'a11y_whatsapp' => '',
				'a11y_physical' => 0,    // A store or showroom open to the public.
				'a11y_access'   => array(), // Ticked items, when there are no branches.
			)
		);
	}

	/**
	 * One setting, trimmed.
	 *
	 * @param string $key Setting key.
	 */
	public static function get( string $key ): string {
		$s = self::settings();

		return isset( $s[ $key ] ) ? trim( (string) $s[ $key ] ) : '';
	}

	/**
	 * The store phone. Falls back to nothing, never to a guess.
	 */
	public static function phone(): string {
		return self::get( 'phone' );
	}

	/**
	 * The store email. Falls back to the WooCommerce sender address.
	 */
	public static function email(): string {
		$email = self::get( 'email' );

		return $email ? $email : (string) get_option( 'woocommerce_email_from_address', get_option( 'admin_email' ) );
	}

	/**
	 * The WhatsApp number as entered.
	 */
	public static function whatsapp(): string {
		return self::get( 'whatsapp' );
	}

	/**
	 * The legal name of the business. Falls back to the site name.
	 */
	public static function company(): string {
		$name = self::get( 'company' );

		return $name ? $name : (string) get_bloginfo( 'name' );
	}

	/**
	 * The street address. Falls back to the WooCommerce store address.
	 */
	public static function address(): string {
		$own = self::get( 'address' );

		if ( $own ) {
			return $own;
		}

		return trim(
			implode(
				', ',
				array_filter(
					array(
						trim( (string) get_option( 'woocommerce_store_address', '' ) . ' ' . (string) get_option( 'woocommerce_store_address_2', '' ) ),
						(string) get_option( 'woocommerce_store_city', '' ),
					)
				)
			)
		);
	}

	/**
	 * Opening hours as typed, one line per entry.
	 */
	public static function hours(): string {
		return self::get( 'hours' );
	}

	/**
	 * The physical-accessibility checklist, key => label. The same keys the
	 * branches module keeps per branch, so a shop with branches and a shop
	 * with one store fill the same table.
	 *
	 * @return array<string,string>
	 */
	public static function access_items(): array {
		return array(
			'parking'  => __( 'Disabled parking', 'oc-theme' ),
			'path'     => __( 'Accessible path from the parking to the door', 'oc-theme' ),
			'door'     => __( 'Accessible entrance door', 'oc-theme' ),
			'hearing'  => __( 'Hearing assistance device', 'oc-theme' ),
			'checkout' => __( 'Accessible checkout', 'oc-theme' ),
			'toilet'   => __( 'Accessible toilets in the branch or mall', 'oc-theme' ),
			'seat'     => __( 'Accessible seat', 'oc-theme' ),
			'elevator' => __( 'Elevator', 'oc-theme' ),
		);
	}

	/**
	 * The branches the accessibility table lists — every branch when the
	 * module is on and has some, none otherwise.
	 *
	 * @return array<int,array{id:int,name:string}>
	 */
	public static function branches(): array {
		if ( ! class_exists( '\\OC\\Blocks\\Branches' ) || ! \OC\Blocks\Branches::menu_on() ) {
			return array();
		}

		return \OC\Blocks\Branches::all();
	}

	/**
	 * The rows of the physical-accessibility table: one per branch, or the
	 * single store, or none for a shop that only sells online.
	 *
	 * @return array<int,array{name:string,access:array<string,int>}>
	 */
	public static function a11y_rows(): array {
		$rows = array();

		foreach ( self::branches() as $branch ) {
			$access = get_post_meta( $branch['id'], '_oc_br_access', true );
			$rows[] = array(
				'name'   => $branch['name'],
				'access' => is_array( $access ) ? $access : array(),
			);
		}

		if ( $rows ) {
			return $rows;
		}

		$s = self::settings();

		if ( empty( $s['a11y_physical'] ) ) {
			return array();
		}

		return array(
			array(
				'name'   => self::company(),
				'access' => is_array( $s['a11y_access'] ) ? $s['a11y_access'] : array(),
			),
		);
	}

	/**
	 * The accessibility coordinator's channels, each falling back to the
	 * store's own so the statement never names nobody.
	 *
	 * @return array{name:string,phone:string,email:string,whatsapp:string}
	 */
	public static function a11y_contact(): array {
		return array(
			'name'     => self::get( 'a11y_name' ),
			'phone'    => self::get( 'a11y_phone' ) ? self::get( 'a11y_phone' ) : self::phone(),
			'email'    => self::get( 'a11y_email' ) ? self::get( 'a11y_email' ) : self::email(),
			'whatsapp' => self::get( 'a11y_whatsapp' ) ? self::get( 'a11y_whatsapp' ) : self::whatsapp(),
		);
	}

	/**
	 * One fact about the shop, by name, for the pages that quote it.
	 *
	 * @param string $key What to read: name, company, company_id, operator,
	 *                    address, hours, phone, email, whatsapp, domain,
	 *                    a11y_name, a11y_phone, a11y_email, privacy_link.
	 */
	public static function fact( string $key ): string {
		$a11y = self::a11y_contact();

		switch ( $key ) {
			case 'name':
				return (string) get_bloginfo( 'name' );
			case 'company':
				return self::company();
			case 'company_id':
				return self::get( 'company_id' );
			case 'operator':
				// "Company, no. 51-000000-0, 1 Street, City" — only the parts that exist.
				$parts = array( self::company() );
				if ( self::get( 'company_id' ) ) {
					/* translators: %s: company registration number. */
					$parts[] = sprintf( __( 'company no. %s', 'oc-theme' ), self::get( 'company_id' ) );
				}
				if ( self::address() ) {
					$parts[] = self::address();
				}
				return implode( ', ', $parts );
			case 'address':
				return self::address();
			case 'hours':
				return self::hours();
			case 'phone':
				return self::phone();
			case 'email':
				return self::email();
			case 'whatsapp':
				return self::whatsapp();
			case 'domain':
				return (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST );
			case 'a11y_name':
				return $a11y['name'];
			case 'a11y_phone':
				return $a11y['phone'];
			case 'a11y_email':
				return $a11y['email'];
			case 'a11y_whatsapp':
				return $a11y['whatsapp'];
			case 'a11y_coordinator':
				return $a11y['name'] ? $a11y['name'] : __( 'the accessibility coordinator', 'oc-theme' );
			case 'privacy_link':
				$url = (string) get_privacy_policy_url();
				return $url ? '<a href="' . esc_url( $url ) . '">' . esc_html__( 'privacy policy', 'oc-theme' ) . '</a>' : esc_html__( 'privacy policy', 'oc-theme' );
			case 'contact_line':
				return self::line( self::phone(), self::email(), self::whatsapp() );
			case 'a11y_line':
				return self::line( $a11y['phone'], $a11y['email'], $a11y['whatsapp'] );
			case 'hours_line':
				return self::hours() ? '<br />' . esc_html__( 'Opening hours:', 'oc-theme' ) . ' ' . nl2br( esc_html( self::hours() ) ) : '';
		}

		return '';
	}

	/**
	 * "Phone: … · Email: … · WhatsApp: …" — the channels that exist, as
	 * links, for the legal pages. HTML, built from escaped parts.
	 *
	 * @param string $phone    Phone number.
	 * @param string $email    Email address.
	 * @param string $whatsapp WhatsApp number.
	 */
	private static function line( string $phone, string $email, string $whatsapp ): string {
		$parts = array();

		if ( $phone ) {
			$parts[] = esc_html__( 'Phone:', 'oc-theme' ) . ' <a href="tel:' . esc_attr( preg_replace( '/[^\d+]/', '', $phone ) ) . '" dir="ltr">' . esc_html( $phone ) . '</a>';
		}

		if ( $email ) {
			$parts[] = esc_html__( 'Email:', 'oc-theme' ) . ' <a href="mailto:' . esc_attr( $email ) . '" dir="ltr">' . esc_html( $email ) . '</a>';
		}

		if ( $whatsapp ) {
			$parts[] = esc_html__( 'WhatsApp:', 'oc-theme' ) . ' <a href="https://wa.me/' . esc_attr( self::wa_digits( $whatsapp ) ) . '" target="_blank" rel="noopener" dir="ltr">' . esc_html( $whatsapp ) . '</a>';
		}

		return implode( ' · ', $parts );
	}

	/**
	 * [oc_site company] / [oc_site key="hours"] — a fact, escaped, so the
	 * legal pages follow the settings instead of freezing a copy of them.
	 *
	 * @param array<int|string,string>|string $atts Shortcode attributes.
	 */
	public static function shortcode( $atts ): string {
		$atts = is_array( $atts ) ? $atts : array();
		$key  = isset( $atts['key'] ) ? (string) $atts['key'] : (string) ( $atts[0] ?? '' );
		$key  = sanitize_key( $key );

		if ( in_array( $key, array( 'privacy_link', 'contact_line', 'a11y_line', 'hours_line' ), true ) ) {
			return self::fact( $key ); // Built from escaped parts.
		}

		$value = self::fact( $key );

		if ( 'hours' === $key ) {
			return nl2br( esc_html( $value ) );
		}

		if ( in_array( $key, array( 'phone', 'a11y_phone' ), true ) && $value ) {
			return '<a href="tel:' . esc_attr( preg_replace( '/[^\d+]/', '', $value ) ) . '" dir="ltr">' . esc_html( $value ) . '</a>';
		}

		if ( in_array( $key, array( 'email', 'a11y_email' ), true ) && $value ) {
			return '<a href="mailto:' . esc_attr( $value ) . '" dir="ltr">' . esc_html( $value ) . '</a>';
		}

		if ( in_array( $key, array( 'whatsapp', 'a11y_whatsapp' ), true ) && $value ) {
			return '<a href="https://wa.me/' . esc_attr( self::wa_digits( $value ) ) . '" target="_blank" rel="noopener" dir="ltr">' . esc_html( $value ) . '</a>';
		}

		return esc_html( $value );
	}

	/**
	 * A phone number as wa.me wants it: digits only, local zero swapped for
	 * the country code.
	 *
	 * @param string $number Number as typed.
	 */
	public static function wa_digits( string $number ): string {
		$digits = preg_replace( '/\D+/', '', $number );

		if ( ! $digits ) {
			return '';
		}

		if ( '0' === $digits[0] ) {
			$digits = '972' . substr( $digits, 1 );
		}

		return $digits;
	}

	/**
	 * Replace {phone} / {email} / {whatsapp} — and the business facts
	 * {company} {company_id} {address} {hours} — in a snippet.
	 *
	 * @param string $text Text possibly holding placeholders.
	 */
	public static function fill( string $text ): string {
		if ( false === strpos( $text, '{' ) ) {
			return $text;
		}

		return str_replace(
			array( '{phone}', '{email}', '{whatsapp}', '{company}', '{company_id}', '{address}', '{hours}' ),
			array( self::phone(), self::email(), self::whatsapp(), self::company(), self::get( 'company_id' ), self::address(), self::hours() ),
			$text
		);
	}

	/**
	 * An inline icon.
	 *
	 * @param string $name Icon id.
	 */
	public static function icon( string $name ): string {
		$icons = array(
			'phone'     => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M6.5 3.5h3l1.5 4-2 1.4a12 12 0 0 0 6.1 6.1l1.4-2 4 1.5v3a2 2 0 0 1-2.2 2A16.5 16.5 0 0 1 4.5 5.7a2 2 0 0 1 2-2.2Z"/></svg>',
			'email'     => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="2.5"/><path d="m3.8 7 7.1 5.3a2 2 0 0 0 2.2 0L20.2 7"/></svg>',
			'whatsapp'  => '<svg viewBox="0 0 24 24"><path fill="currentColor" d="M12 3a9 9 0 0 0-7.8 13.5L3 21l4.6-1.2A9 9 0 1 0 12 3Zm0 1.8a7.2 7.2 0 1 1-3.7 13.4l-.4-.2-2.5.7.7-2.5-.3-.4A7.2 7.2 0 0 1 12 4.8Zm-2.6 3.5c-.2 0-.5 0-.7.3-.2.3-.9.9-.9 2.1s.9 2.5 1 2.6c.1.2 1.8 2.8 4.4 3.8 2.1.9 2.6.7 3 .7.5 0 1.5-.6 1.7-1.2.2-.6.2-1.1.2-1.2l-.5-.3-1.9-.9c-.2-.1-.4-.1-.6.1l-.8 1c-.1.2-.3.2-.5.1a5.9 5.9 0 0 1-3-2.6c-.1-.2 0-.4.1-.5l.6-.7c.2-.2.2-.4.1-.6l-.8-2c-.2-.4-.4-.5-.6-.5Z"/></svg>',
			'instagram' => '<svg viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="5.4" fill="none" stroke="currentColor" stroke-width="1.8"/><circle cx="12" cy="12" r="4.2" fill="none" stroke="currentColor" stroke-width="1.8"/><circle cx="17.2" cy="6.8" r="1.3" fill="currentColor"/></svg>',
			'facebook'  => '<svg viewBox="0 0 24 24"><path fill="currentColor" d="M13.5 21v-7h2.4l.4-2.9h-2.8V9.2c0-.8.3-1.4 1.5-1.4h1.4V5.2c-.3 0-1.1-.1-2-.1-2 0-3.4 1.2-3.4 3.5v2.5H8.5V14H11v7Z"/></svg>',
			'tiktok'    => '<svg viewBox="0 0 24 24"><path fill="currentColor" d="M16.6 3c.3 1.7 1.4 3 3.4 3.3v2.7c-1.3 0-2.5-.4-3.4-1v6.4c0 3.2-2.2 5.6-5.4 5.6A5.3 5.3 0 0 1 5.8 14.6c0-3 2.4-5.3 5.5-5.1v2.8c-1.5-.3-2.8.7-2.8 2.2 0 1.4 1 2.5 2.5 2.5s2.6-1.1 2.6-2.7V3Z"/></svg>',
			'youtube'   => '<svg viewBox="0 0 24 24"><path fill="currentColor" d="M21.6 7.4a2.5 2.5 0 0 0-1.8-1.8C18.2 5.2 12 5.2 12 5.2s-6.2 0-7.8.4A2.5 2.5 0 0 0 2.4 7.4 26.5 26.5 0 0 0 2 12c0 1.6.1 3.1.4 4.6a2.5 2.5 0 0 0 1.8 1.8c1.6.4 7.8.4 7.8.4s6.2 0 7.8-.4a2.5 2.5 0 0 0 1.8-1.8c.3-1.5.4-3 .4-4.6s-.1-3.1-.4-4.6ZM10.2 15V9l5.2 3Z"/></svg>',
		);

		return $icons[ $name ] ?? '';
	}

	/**
	 * Phone, email and WhatsApp as icon links — whichever are filled in.
	 */
	public static function contact_row_html(): string {
		$rows = array();

		$phone = self::phone();
		if ( $phone ) {
			$rows[] = array( 'phone', 'tel:' . preg_replace( '/[^\d+]/', '', $phone ), $phone );
		}

		$email = self::get( 'email' );
		if ( $email ) {
			$rows[] = array( 'email', 'mailto:' . $email, $email );
		}

		$wa = self::wa_digits( self::whatsapp() );
		if ( $wa ) {
			$rows[] = array( 'whatsapp', 'https://wa.me/' . $wa, self::whatsapp() );
		}

		if ( ! $rows ) {
			return '';
		}

		$html = '<div class="oc-contact-row">';

		foreach ( $rows as $row ) {
			list( $icon, $href, $text ) = $row;

			$html .= '<a class="oc-contact-row__item" href="' . esc_url( $href ) . '"'
				. ( 'whatsapp' === $icon ? ' target="_blank" rel="noopener"' : '' ) . '>'
				. '<span class="oc-contact-row__i" aria-hidden="true">' . self::icon( $icon ) . '</span>'
				. '<span class="oc-contact-row__t" dir="ltr">' . esc_html( $text ) . '</span>'
				. '</a>';
		}

		return $html . '</div>';
	}

	/**
	 * The social profiles that have a URL, keyed by network.
	 *
	 * @return array<string,string>
	 */
	public static function social_links(): array {
		$links = array();

		foreach ( array_keys( self::networks() ) as $net ) {
			$url = self::get( $net );

			if ( $url ) {
				$links[ $net ] = $url;
			}
		}

		return $links;
	}

	/**
	 * Round icon buttons for every filled-in profile.
	 */
	public static function social_row_html(): string {
		$links = self::social_links();

		if ( ! $links ) {
			return '';
		}

		$html = '<div class="oc-soc-row">';

		foreach ( $links as $net => $url ) {
			$html .= '<a class="oc-soc" href="' . esc_url( $url ) . '" target="_blank" rel="noopener" aria-label="' . esc_attr( $net ) . '">'
				. self::icon( $net ) . '</a>';
		}

		return $html . '</div>';
	}

	/**
	 * Hook in.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ), 58 );
		add_action( 'admin_post_oc_contact_save', array( $this, 'save' ) );
		add_shortcode( 'oc_site', array( __CLASS__, 'shortcode' ) );
	}

	/**
	 * Submenu under theme settings.
	 */
	public function menu(): void {
		add_submenu_page(
			Tabs::MENU,
			__( 'Store details', 'oc-theme' ),
			__( 'Store details', 'oc-theme' ),
			'manage_woocommerce',
			'oc-contact',
			array( $this, 'screen' )
		);
	}

	/**
	 * The settings screen.
	 */
	public function screen(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- notices only.
		if ( isset( $_GET['oc_saved'] ) ) {
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Settings saved.', 'oc-theme' ) . '</p></div>';
		}

		if ( isset( $_GET['oc_page'] ) ) {
			$which = sanitize_key( wp_unslash( (string) $_GET['oc_page'] ) );
			$ok    = ! empty( $_GET['ok'] );
			$id    = 'terms' === $which ? Legal\Terms::page_id() : Legal\Accessibility::page_id();
			$url   = $id ? (string) get_permalink( $id ) : '';

			echo '<div class="notice notice-' . ( $ok ? 'success' : 'error' ) . '"><p>';
			if ( $ok && $url ) {
				echo esc_html( 'terms' === $which ? __( 'The terms page is written and set as the checkout terms page. Read it once before publishing.', 'oc-theme' ) : __( 'The accessibility statement is written and linked from the footer. Read it once before publishing.', 'oc-theme' ) );
				echo ' <a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html__( 'View', 'oc-theme' ) . '</a> · <a href="' . esc_url( (string) get_edit_post_link( $id ) ) . '">' . esc_html__( 'Edit', 'oc-theme' ) . '</a>';
			} else {
				esc_html_e( 'The page could not be written.', 'oc-theme' );
			}
			echo '</p></div>';
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$s        = self::settings();
		$branches = self::branches();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Store details', 'oc-theme' ); ?></h1>
			<p><?php esc_html_e( 'The store described once. Every page that shows these details reads from here — the checkout help line, the thank-you page, the emails, the generated terms and accessibility pages, and any text where you write {phone}, {email}, {whatsapp}, {company}, {address} or {hours}. An empty field simply does not appear.', 'oc-theme' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="oc_contact_save" />
				<?php wp_nonce_field( 'oc_contact_save' ); ?>

				<h2><?php esc_html_e( 'The business', 'oc-theme' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="oc-company"><?php esc_html_e( 'Legal name', 'oc-theme' ); ?></label></th>
						<td>
							<input type="text" id="oc-company" name="company" value="<?php echo esc_attr( $s['company'] ); ?>" placeholder="<?php echo esc_attr( (string) get_bloginfo( 'name' ) ); ?>" class="regular-text" />
							<p class="description"><?php esc_html_e( 'The company or business that operates the store, as registered. Empty = the site name.', 'oc-theme' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="oc-company-id"><?php esc_html_e( 'Company / business number', 'oc-theme' ); ?></label></th>
						<td><input type="text" id="oc-company-id" name="company_id" dir="ltr" value="<?php echo esc_attr( $s['company_id'] ); ?>" placeholder="51-0000000" class="regular-text" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="oc-address"><?php esc_html_e( 'Address', 'oc-theme' ); ?></label></th>
						<td>
							<input type="text" id="oc-address" name="address" value="<?php echo esc_attr( $s['address'] ); ?>" placeholder="<?php echo esc_attr( self::address() ); ?>" class="regular-text" />
							<p class="description"><?php esc_html_e( 'Empty = the store address from WooCommerce → Settings → General.', 'oc-theme' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="oc-hours"><?php esc_html_e( 'Opening hours', 'oc-theme' ); ?></label></th>
						<td>
							<textarea id="oc-hours" name="hours" rows="4" class="regular-text" placeholder="<?php esc_attr_e( 'Sunday–Thursday 9:00–19:00&#10;Friday 9:00–14:00', 'oc-theme' ); ?>"><?php echo esc_textarea( $s['hours'] ); ?></textarea>
							<p class="description"><?php esc_html_e( 'One line per entry, exactly as it should read on the site.', 'oc-theme' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Contact', 'oc-theme' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Phone', 'oc-theme' ); ?></th>
						<td><input type="text" name="phone" dir="ltr" value="<?php echo esc_attr( $s['phone'] ); ?>" placeholder="077-0000000" class="regular-text" /></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Email', 'oc-theme' ); ?></th>
						<td>
							<input type="email" name="email" dir="ltr" value="<?php echo esc_attr( $s['email'] ); ?>" placeholder="<?php echo esc_attr( (string) get_option( 'woocommerce_email_from_address', '' ) ); ?>" class="regular-text" />
							<p class="description"><?php esc_html_e( 'Empty = the WooCommerce sender address.', 'oc-theme' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'WhatsApp number', 'oc-theme' ); ?></th>
						<td>
							<input type="text" name="whatsapp" dir="ltr" value="<?php echo esc_attr( $s['whatsapp'] ); ?>" placeholder="050-0000000" class="regular-text" />
							<p class="description"><?php esc_html_e( 'For a direct chat with the store.', 'oc-theme' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'WhatsApp group', 'oc-theme' ); ?></th>
						<td>
							<input type="url" name="wa_group" dir="ltr" value="<?php echo esc_attr( $s['wa_group'] ); ?>" placeholder="https://chat.whatsapp.com/…" class="regular-text" />
							<p class="description"><?php esc_html_e( 'An invite link to the store\'s group, for the join widget.', 'oc-theme' ); ?></p>
						</td>
					</tr>
				</table>

				<?php if ( class_exists( '\\OC\\Blocks\\Branches' ) ) : ?>
					<h2><?php esc_html_e( 'Branches', 'oc-theme' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Branches module', 'oc-theme' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="oc_branches_menu" value="1" <?php checked( \OC\Blocks\Branches::menu_on() ); ?> />
									<?php esc_html_e( 'This store has branches', 'oc-theme' ); ?>
								</label>
								<p class="description">
									<?php esc_html_e( 'Off for most stores, and off to begin with. Turn it on and the Branches screen joins the menu, and a shopper who chooses collection at the checkout is asked which branch to collect from.', 'oc-theme' ); ?>
								</p>
								<p class="description">
									<?php esc_html_e( 'Turning it off hides the screen and the question — it deletes nothing. The branches you have keep their pages, and the branches block goes on working, which makes it just as useful for "where to find our products".', 'oc-theme' ); ?>
								</p>
							</td>
						</tr>
					</table>
				<?php endif; ?>

				<h2><?php esc_html_e( 'Accessibility', 'oc-theme' ); ?></h2>
				<p class="description"><?php esc_html_e( 'The law asks every business site for an accessibility statement naming a coordinator and stating what the premises offer. The coordinator\'s channels fall back to the store\'s own when empty.', 'oc-theme' ); ?></p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="oc-a11y-name"><?php esc_html_e( 'Accessibility coordinator', 'oc-theme' ); ?></label></th>
						<td><input type="text" id="oc-a11y-name" name="a11y_name" value="<?php echo esc_attr( $s['a11y_name'] ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'Full name', 'oc-theme' ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="oc-a11y-phone"><?php esc_html_e( 'Coordinator phone', 'oc-theme' ); ?></label></th>
						<td><input type="text" id="oc-a11y-phone" name="a11y_phone" dir="ltr" value="<?php echo esc_attr( $s['a11y_phone'] ); ?>" class="regular-text" placeholder="<?php echo esc_attr( self::phone() ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="oc-a11y-email"><?php esc_html_e( 'Coordinator email', 'oc-theme' ); ?></label></th>
						<td><input type="email" id="oc-a11y-email" name="a11y_email" dir="ltr" value="<?php echo esc_attr( $s['a11y_email'] ); ?>" class="regular-text" placeholder="<?php echo esc_attr( self::email() ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="oc-a11y-wa"><?php esc_html_e( 'Coordinator WhatsApp', 'oc-theme' ); ?></label></th>
						<td><input type="text" id="oc-a11y-wa" name="a11y_whatsapp" dir="ltr" value="<?php echo esc_attr( $s['a11y_whatsapp'] ); ?>" class="regular-text" placeholder="<?php echo esc_attr( self::whatsapp() ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Premises', 'oc-theme' ); ?></th>
						<td>
							<?php if ( $branches ) : ?>
								<p>
									<?php
									printf(
										/* translators: %d: number of branches. */
										esc_html__( 'The statement lists the branches (%d now) in a table, one row per branch. Tick what each branch offers on its own screen, under Branches → Accessibility.', 'oc-theme' ),
										(int) count( $branches )
									);
									?>
								</p>
							<?php else : ?>
								<label>
									<input type="checkbox" name="a11y_physical" value="1" <?php checked( ! empty( $s['a11y_physical'] ) ); ?> />
									<?php esc_html_e( 'The business has a store or showroom open to the public', 'oc-theme' ); ?>
								</label>
								<p class="description"><?php esc_html_e( 'Off = the statement says the service is online only. On = tick what the premises offer; anything left unticked shows as "no".', 'oc-theme' ); ?></p>
								<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:6px 20px;margin-top:8px">
									<?php foreach ( self::access_items() as $key => $label ) : ?>
										<label style="display:flex;gap:8px;align-items:center"><input type="checkbox" name="a11y_access[<?php echo esc_attr( $key ); ?>]" value="1" <?php checked( ! empty( $s['a11y_access'][ $key ] ) ); ?> /> <?php echo esc_html( $label ); ?></label>
									<?php endforeach; ?>
								</div>
							<?php endif; ?>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Terms of sale', 'oc-theme' ); ?></h2>
				<p class="description"><?php esc_html_e( 'How the generated terms page is written. The cancellation, fee and warranty figures are the ones the Consumer Protection Law sets and are not settings.', 'oc-theme' ); ?></p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Kind of store', 'oc-theme' ); ?></th>
						<td>
							<fieldset>
								<label><input type="radio" name="terms_kind" value="general" <?php checked( 'food' !== $s['terms_kind'] ); ?> /> <?php esc_html_e( 'General — clothing, furniture, home, gifts, electronics', 'oc-theme' ); ?></label><br />
								<label><input type="radio" name="terms_kind" value="food" <?php checked( 'food' === $s['terms_kind'] ); ?> /> <?php esc_html_e( 'Food — adds perishables, weight variance, allergens and cold-chain delivery', 'oc-theme' ); ?></label>
							</fieldset>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Extra clauses', 'oc-theme' ); ?></th>
						<td>
							<fieldset>
								<label><input type="checkbox" name="terms_custom" value="1" <?php checked( ! empty( $s['terms_custom'] ) ); ?> /> <?php esc_html_e( 'Made-to-order products — no cancellation once production has begun', 'oc-theme' ); ?></label><br />
								<label><input type="checkbox" name="terms_bulky" value="1" <?php checked( ! empty( $s['terms_bulky'] ) ); ?> /> <?php esc_html_e( 'Large items — access, stairs, crane, assembly and acceptance on delivery', 'oc-theme' ); ?></label>
							</fieldset>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Social profiles', 'oc-theme' ); ?></h2>
				<table class="form-table" role="presentation">
					<?php foreach ( self::networks() as $field => $label ) : ?>
						<tr>
							<th scope="row"><?php echo esc_html( $label ); ?></th>
							<td><input type="url" name="<?php echo esc_attr( $field ); ?>" dir="ltr" value="<?php echo esc_attr( (string) $s[ $field ] ); ?>" class="regular-text" placeholder="https://" /></td>
						</tr>
					<?php endforeach; ?>
				</table>

				<?php submit_button( __( 'Save settings', 'oc-theme' ) ); ?>
			</form>

			<h2><?php esc_html_e( 'Legal pages', 'oc-theme' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Each button writes a ready-made page from a template built into the theme — no AI, no outside service, nothing leaves the site. The facts on the page (company, address, hours, coordinator, branches) are read live from this screen, so save first, and later edits here show on the page by themselves. Rewriting a page replaces what you edited on it. Read every page once before publishing — a lawyer should confirm anything specific to your business.', 'oc-theme' ); ?></p>
			<?php
			$pages = array(
				'terms' => array(
					'id'    => Legal\Terms::page_id(),
					'write' => __( 'Write the terms page', 'oc-theme' ),
					'again' => __( 'Rewrite the terms page', 'oc-theme' ),
					'what'  => __( 'Terms of sale in the site\'s language: orders and payment, delivery, cancellation and returns per the Consumer Protection Law (14 days, 4 months for eligible customers, 5% or ₪100 fee, the exceptions), warranty, privacy, liability and jurisdiction — written for the kind of store chosen above. Set as the WooCommerce terms page, so the checkout consent and the checkout side panel link to it.', 'oc-theme' ),
				),
				'a11y'  => array(
					'id'    => Legal\Accessibility::page_id(),
					'write' => __( 'Write the accessibility statement', 'oc-theme' ),
					'again' => __( 'Rewrite the accessibility statement', 'oc-theme' ),
					'what'  => __( 'The statement regulation 35 requires: the standard the site follows, the adaptations the theme really provides, how to enlarge text and raise contrast from the browser, the premises table, the coordinator and how to report a problem. Linked from the footer next to the privacy link.', 'oc-theme' ),
				),
			);
			foreach ( $pages as $which => $page ) :
				?>
				<div style="margin:0 0 18px;max-width:760px">
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
						<input type="hidden" name="action" value="oc_legal_<?php echo esc_attr( $which ); ?>" />
						<?php wp_nonce_field( 'oc_legal_' . $which ); ?>
						<button type="submit" class="button button-secondary"><?php echo esc_html( $page['id'] ? $page['again'] : $page['write'] ); ?></button>
					</form>
					<?php if ( $page['id'] ) : ?>
						<a href="<?php echo esc_url( (string) get_permalink( $page['id'] ) ); ?>" target="_blank" rel="noopener" style="margin-inline-start:10px"><?php esc_html_e( 'View', 'oc-theme' ); ?></a>
						· <a href="<?php echo esc_url( (string) get_edit_post_link( $page['id'] ) ); ?>"><?php esc_html_e( 'Edit', 'oc-theme' ); ?></a>
					<?php endif; ?>
					<p class="description"><?php echo esc_html( $page['what'] ); ?></p>
				</div>
			<?php endforeach; ?>
			<p class="description"><?php echo wp_kses_post( sprintf( /* translators: %s: link to the privacy screen. */ __( 'The privacy policy page has its own button under %s.', 'oc-theme' ), '<a href="' . esc_url( admin_url( 'options-general.php?page=oc-privacy' ) ) . '">' . esc_html__( 'Settings → Privacy', 'oc-theme' ) . '</a>' ) ); ?> <code>[oc_site company]</code> <code>[oc_site address]</code> <code>[oc_site hours]</code> <code>[oc_a11y_table]</code> — <?php esc_html_e( 'the same facts, for any page or block.', 'oc-theme' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Persist.
	 */
	public function save(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die();
		}

		check_admin_referer( 'oc_contact_save' );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified above.
		$s = array(
			'phone'         => sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) ),
			'email'         => sanitize_email( wp_unslash( $_POST['email'] ?? '' ) ),
			'whatsapp'      => sanitize_text_field( wp_unslash( $_POST['whatsapp'] ?? '' ) ),
			'wa_group'      => esc_url_raw( wp_unslash( $_POST['wa_group'] ?? '' ) ),
			'company'       => sanitize_text_field( wp_unslash( $_POST['company'] ?? '' ) ),
			'company_id'    => sanitize_text_field( wp_unslash( $_POST['company_id'] ?? '' ) ),
			'address'       => sanitize_text_field( wp_unslash( $_POST['address'] ?? '' ) ),
			'hours'         => sanitize_textarea_field( wp_unslash( $_POST['hours'] ?? '' ) ),
			'terms_kind'    => 'food' === sanitize_key( wp_unslash( $_POST['terms_kind'] ?? '' ) ) ? 'food' : 'general',
			'terms_custom'  => empty( $_POST['terms_custom'] ) ? 0 : 1,
			'terms_bulky'   => empty( $_POST['terms_bulky'] ) ? 0 : 1,
			'a11y_name'     => sanitize_text_field( wp_unslash( $_POST['a11y_name'] ?? '' ) ),
			'a11y_phone'    => sanitize_text_field( wp_unslash( $_POST['a11y_phone'] ?? '' ) ),
			'a11y_email'    => sanitize_email( wp_unslash( $_POST['a11y_email'] ?? '' ) ),
			'a11y_whatsapp' => sanitize_text_field( wp_unslash( $_POST['a11y_whatsapp'] ?? '' ) ),
			'a11y_physical' => empty( $_POST['a11y_physical'] ) ? 0 : 1,
			'a11y_access'   => array(),
		);

		// With branches the premises checklist lives on each branch; the
		// site-level one is not shown, so keep what was saved before.
		$before = self::settings();

		if ( self::branches() ) {
			$s['a11y_physical'] = (int) $before['a11y_physical'];
			$s['a11y_access']   = is_array( $before['a11y_access'] ) ? $before['a11y_access'] : array();
		} else {
			foreach ( array_keys( self::access_items() ) as $key ) {
				$s['a11y_access'][ $key ] = empty( $_POST['a11y_access'][ $key ] ) ? 0 : 1;
			}
		}

		foreach ( array_keys( self::networks() ) as $net ) {
			$s[ $net ] = esc_url_raw( wp_unslash( $_POST[ $net ] ?? '' ) );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		update_option( 'oc_contact', $s );

		// The branches module rides on this screen rather than earning a link
		// of its own: it is one switch, and this is where the shop describes
		// itself. The option belongs to the blocks plugin, which owns the
		// branches; only the switch lives here.
		if ( class_exists( '\\OC\\Blocks\\Branches' ) ) {
			update_option(
				\OC\Blocks\Branches::OPTION,
				array( 'menu' => empty( $_POST['oc_branches_menu'] ) ? 0 : 1 ) // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
			);
		}

		wp_safe_redirect( add_query_arg( 'oc_saved', '1', admin_url( 'admin.php?page=oc-contact' ) ) );
		exit;
	}
}
