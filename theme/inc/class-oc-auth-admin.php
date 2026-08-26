<?php
/**
 * OC Auth — the settings screen, under Settings like its siblings.
 *
 * Infrastructure and secrets only: the SMS provider and its key, reach
 * (Israel or worldwide), the safety rails, and the social providers.
 * Everything that is design — side, width, title, alignment — lives in
 * the Customizer, where it belongs and where no secret ever travels.
 *
 * @package OC_Theme
 */

declare( strict_types = 1 );

namespace OC\Theme;

defined( 'ABSPATH' ) || exit;

/**
 * The login settings.
 */
final class Auth_Admin {

	/**
	 * Hook in.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_post_oc_auth_settings', array( $this, 'save' ) );
	}

	/**
	 * Under Settings, next to SEO and the redirects.
	 */
	public function menu(): void {
		add_options_page(
			__( 'Login', 'oc-theme' ),
			__( 'Login', 'oc-theme' ),
			'manage_options',
			'oc-auth',
			array( $this, 'render' )
		);
	}

	/**
	 * The screen.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'oc-theme' ) );
		}

		$s = Auth::settings();

		echo '<div class="wrap"><h1>' . esc_html__( 'Login', 'oc-theme' ) . '</h1>';

		if ( isset( $_GET['oc_msg'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Saved.', 'oc-theme' ) . '</p></div>';
		}

		echo '<p>' . esc_html__( 'Design — side, width, title, alignment — lives in the Customizer under "Login panel". This screen holds the machinery and the keys.', 'oc-theme' ) . '</p>';
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'oc_auth_settings' ); ?>
			<input type="hidden" name="action" value="oc_auth_settings">

			<h2><?php esc_html_e( 'SMS sign-in', 'oc-theme' ); ?></h2>
			<table class="form-table">
				<tr>
					<th><?php esc_html_e( 'Phone sign-in', 'oc-theme' ); ?></th>
					<td><label><input type="checkbox" name="sms_on" value="1" <?php checked( ! empty( $s['sms_on'] ) ); ?>> <?php esc_html_e( 'A recognised number gets a code; a new one opens an account once', 'oc-theme' ); ?></label></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'ActiveTrail API key', 'oc-theme' ); ?></th>
					<td><input type="text" name="api_key" class="large-text ltr" value="<?php echo esc_attr( (string) $s['api_key'] ); ?>" autocomplete="off"></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Sender name', 'oc-theme' ); ?></th>
					<td>
						<input type="text" name="sender" class="regular-text" maxlength="11" value="<?php echo esc_attr( (string) $s['sender'] ); ?>" placeholder="<?php echo esc_attr( (string) get_bloginfo( 'name' ) ); ?>">
						<p class="description"><?php esc_html_e( 'Up to 11 characters — the carrier\'s rule; anything longer is refused.', 'oc-theme' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Reach', 'oc-theme' ); ?></th>
					<td>
						<label><input type="radio" name="reach" value="israel" <?php checked( 'israel', $s['reach'] ); ?>> <?php esc_html_e( 'Israeli mobile numbers only (recommended — blocks SMS-pumping fraud)', 'oc-theme' ); ?></label><br>
						<label><input type="radio" name="reach" value="intl" <?php checked( 'intl', $s['reach'] ); ?>> <?php esc_html_e( 'International numbers too', 'oc-theme' ); ?></label>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Safety rails', 'oc-theme' ); ?></h2>
			<table class="form-table">
				<tr>
					<th><?php esc_html_e( 'Code lifetime (seconds)', 'oc-theme' ); ?></th>
					<td><input type="number" name="code_expiry" class="small-text" min="60" max="900" value="<?php echo esc_attr( (string) (int) $s['code_expiry'] ); ?>"></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Wrong tries before the code dies', 'oc-theme' ); ?></th>
					<td><input type="number" name="max_attempts" class="small-text" min="3" max="10" value="<?php echo esc_attr( (string) (int) $s['max_attempts'] ); ?>"></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Seconds between sends to one number', 'oc-theme' ); ?></th>
					<td><input type="number" name="resend_cooldown" class="small-text" min="30" max="300" value="<?php echo esc_attr( (string) (int) $s['resend_cooldown'] ); ?>"></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Sends per hour — per number / per address', 'oc-theme' ); ?></th>
					<td>
						<input type="number" name="phone_hourly" class="small-text" min="1" max="20" value="<?php echo esc_attr( (string) (int) $s['phone_hourly'] ); ?>">
						/
						<input type="number" name="ip_hourly" class="small-text" min="1" max="50" value="<?php echo esc_attr( (string) (int) $s['ip_hourly'] ); ?>">
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Daily SMS budget', 'oc-theme' ); ?></th>
					<td>
						<input type="number" name="daily_cap" class="small-text" min="20" max="10000" value="<?php echo esc_attr( (string) (int) $s['daily_cap'] ); ?>">
						<p class="description"><?php esc_html_e( 'Past this, sending pauses until midnight and you get an email.', 'oc-theme' ); ?></p>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Sign in with Google', 'oc-theme' ); ?></h2>
			<table class="form-table">
				<tr>
					<th><?php esc_html_e( 'Google sign-in', 'oc-theme' ); ?></th>
					<td><label><input type="checkbox" name="google_on" value="1" <?php checked( ! empty( $s['google_on'] ) ); ?>> <?php esc_html_e( 'Show the Google button', 'oc-theme' ); ?></label></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Client ID', 'oc-theme' ); ?></th>
					<td><input type="text" name="google_id" class="large-text ltr" value="<?php echo esc_attr( (string) $s['google_id'] ); ?>" autocomplete="off"></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Client secret', 'oc-theme' ); ?></th>
					<td>
						<input type="text" name="google_secret" class="large-text ltr" value="<?php echo esc_attr( (string) $s['google_secret'] ); ?>" autocomplete="off">
						<p class="description">
							<?php esc_html_e( 'Google Cloud Console → Credentials → OAuth client (Web). Authorised redirect URI:', 'oc-theme' ); ?>
							<code style="direction:ltr;display:inline-block"><?php echo esc_html( admin_url( 'admin-post.php?action=oc_auth_google_cb' ) ); ?></code>
						</p>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Facebook and Apple', 'oc-theme' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Phase two — the plumbing is ready, the switches arrive once a site actually needs them (Facebook wants an app review; Apple wants a paid developer account).', 'oc-theme' ); ?></p>

			<p><button class="button button-primary"><?php esc_html_e( 'Save', 'oc-theme' ); ?></button></p>
		</form>
		</div>
		<?php
	}

	/**
	 * Save.
	 */
	public function save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'oc-theme' ) );
		}

		check_admin_referer( 'oc_auth_settings' );

		$s = Auth::settings();

		$s['sms_on']    = empty( $_POST['sms_on'] ) ? 0 : 1;
		$s['google_on'] = empty( $_POST['google_on'] ) ? 0 : 1;
		$s['reach']     = 'intl' === ( $_POST['reach'] ?? '' ) ? 'intl' : 'israel';

		foreach ( array( 'api_key', 'sender', 'google_id', 'google_secret' ) as $field ) {
			$s[ $field ] = sanitize_text_field( (string) wp_unslash( $_POST[ $field ] ?? '' ) );
		}

		foreach ( array( 'code_expiry' => 180, 'max_attempts' => 5, 'resend_cooldown' => 60, 'phone_hourly' => 3, 'ip_hourly' => 5, 'daily_cap' => 300 ) as $field => $fallback ) {
			$value       = absint( $_POST[ $field ] ?? 0 );
			$s[ $field ] = $value > 0 ? $value : $fallback;
		}

		update_option( Auth::SETTINGS, $s, false );
		wp_safe_redirect( admin_url( 'options-general.php?page=oc-auth&oc_msg=1' ) );
		exit;
	}
}
