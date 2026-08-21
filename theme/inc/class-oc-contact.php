<?php
/**
 * Store details: the one place the shop's own channels live.
 *
 * Phone, email, WhatsApp and the social profiles are the shop's assets, not
 * any single page's settings — so they are entered once here and every
 * surface that shows them (checkout help line, thank-you page, anywhere a
 * {phone} / {email} / {whatsapp} placeholder appears) reads from here.
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
				'phone'     => '',
				'email'     => '',
				'whatsapp'  => '',   // Number for a direct chat.
				'wa_group'  => '',   // Invite link to the shop's group.
				'instagram' => '',
				'facebook'  => '',
				'tiktok'    => '',
				'youtube'   => '',
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
	 * Replace {phone} / {email} / {whatsapp} placeholders in a snippet.
	 *
	 * @param string $text Text possibly holding placeholders.
	 */
	public static function fill( string $text ): string {
		return str_replace(
			array( '{phone}', '{email}', '{whatsapp}' ),
			array( self::phone(), self::email(), self::whatsapp() ),
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

		if ( isset( $_GET['oc_saved'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- notice only.
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Settings saved.', 'oc-theme' ) . '</p></div>';
		}

		$s = self::settings();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Store details', 'oc-theme' ); ?></h1>
			<p><?php esc_html_e( 'The store\'s own channels, entered once. Every page that shows them reads from here — the checkout help line, the thank-you page, and any text where you write {phone}, {email} or {whatsapp}. An empty field simply does not appear.', 'oc-theme' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="oc_contact_save" />
				<?php wp_nonce_field( 'oc_contact_save' ); ?>

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
			'phone'    => sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) ),
			'email'    => sanitize_email( wp_unslash( $_POST['email'] ?? '' ) ),
			'whatsapp' => sanitize_text_field( wp_unslash( $_POST['whatsapp'] ?? '' ) ),
			'wa_group' => esc_url_raw( wp_unslash( $_POST['wa_group'] ?? '' ) ),
		);

		foreach ( array_keys( self::networks() ) as $net ) {
			$s[ $net ] = esc_url_raw( wp_unslash( $_POST[ $net ] ?? '' ) );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		update_option( 'oc_contact', $s );

		wp_safe_redirect( add_query_arg( 'oc_saved', '1', admin_url( 'admin.php?page=oc-contact' ) ) );
		exit;
	}
}
