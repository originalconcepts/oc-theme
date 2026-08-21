<?php
/**
 * Contact details: the one place a phone number and an email live.
 *
 * Everything that shows a phone or an email — the checkout help line, the
 * thank-you page, any editor content — pulls from here via the {phone} and
 * {email} placeholders, so changing the number once changes it everywhere.
 *
 * @package OC_Theme
 */

declare( strict_types = 1 );

namespace OC\Theme;

defined( 'ABSPATH' ) || exit;

/**
 * Central contact settings + placeholder filling.
 */
final class Contact {

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
				'phone' => '',
				'email' => '',
			)
		);
	}

	/**
	 * The store phone. Falls back to nothing, never to a guess.
	 */
	public static function phone(): string {
		return (string) self::settings()['phone'];
	}

	/**
	 * The store email. Falls back to the WooCommerce sender address.
	 */
	public static function email(): string {
		$email = (string) self::settings()['email'];

		return $email ? $email : (string) get_option( 'woocommerce_email_from_address', get_option( 'admin_email' ) );
	}

	/**
	 * Replace {phone} / {email} placeholders in a text or HTML snippet.
	 *
	 * @param string $text Text possibly holding placeholders.
	 */
	public static function fill( string $text ): string {
		return str_replace(
			array( '{phone}', '{email}' ),
			array( self::phone(), self::email() ),
			$text
		);
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
			__( 'Contact details', 'oc-theme' ),
			__( 'Contact details', 'oc-theme' ),
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
			<h1><?php esc_html_e( 'Contact details', 'oc-theme' ); ?></h1>
			<p><?php esc_html_e( 'The main phone and email of the store. Anywhere a phone or email shows — the checkout help line, the thank-you page, editable texts — you can write {phone} or {email} and the value set here appears.', 'oc-theme' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="oc_contact_save" />
				<?php wp_nonce_field( 'oc_contact_save' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Phone', 'oc-theme' ); ?></th>
						<td><input type="text" name="phone" dir="ltr" value="<?php echo esc_attr( $s['phone'] ); ?>" placeholder="077-4510511" class="regular-text" /></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Email', 'oc-theme' ); ?></th>
						<td>
							<input type="email" name="email" dir="ltr" value="<?php echo esc_attr( $s['email'] ); ?>" placeholder="<?php echo esc_attr( (string) get_option( 'woocommerce_email_from_address', '' ) ); ?>" class="regular-text" />
							<p class="description"><?php esc_html_e( 'Empty = the WooCommerce sender address.', 'oc-theme' ); ?></p>
						</td>
					</tr>
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

		update_option(
			'oc_contact',
			array(
				'phone' => sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) ),
				'email' => sanitize_email( wp_unslash( $_POST['email'] ?? '' ) ),
			)
		);

		wp_safe_redirect( add_query_arg( 'oc_saved', '1', admin_url( 'admin.php?page=oc-contact' ) ) );
		exit;
	}
}
