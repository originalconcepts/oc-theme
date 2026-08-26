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

			<details style="max-width:760px;margin:4px 0 20px">
				<summary style="cursor:pointer;font-weight:600"><?php esc_html_e( 'How to get the keys from Google — step by step', 'oc-theme' ); ?></summary>
				<ol style="margin-top:12px;line-height:1.9">
					<li>
						<?php esc_html_e( 'Sign in at', 'oc-theme' ); ?>
						<a href="https://console.cloud.google.com" target="_blank" rel="noopener">console.cloud.google.com</a>
						<?php esc_html_e( '(preferably with the client\'s own Google account — the sign-in screen shows in their name) and create a New Project, named after the site.', 'oc-theme' ); ?>
					</li>
					<li><?php esc_html_e( 'Search for "Google Auth Platform" at the top and open it. Press "Get started": app name (the shop\'s name — this is what shoppers see), support email, Audience: External, a contact email, agree and Create.', 'oc-theme' ); ?></li>
					<li>
						<?php esc_html_e( 'On the Overview screen press "Create OAuth client": Application type — Web application, any name, and under Authorized redirect URIs add exactly:', 'oc-theme' ); ?>
						<br><code style="direction:ltr;display:inline-block;user-select:all"><?php echo esc_html( admin_url( 'admin-post.php?action=oc_auth_google_cb' ) ); ?></code>
						<br><?php esc_html_e( '"Authorized JavaScript origins" can stay empty. Press Create.', 'oc-theme' ); ?>
					</li>
					<li><?php esc_html_e( 'Copy the Client ID and the Client Secret into the fields above, tick "Show the Google button", and Save.', 'oc-theme' ); ?></li>
					<li><?php esc_html_e( 'One check under Audience (in the right-side menu): if the status says Testing — press "Publish app". In Testing mode only manually-added test users can sign in.', 'oc-theme' ); ?></li>
				</ol>
				<p class="description"><?php esc_html_e( 'No extra scopes and no Google verification are needed — the sign-in only uses the basic name-and-email profile. Every client site gets its own project and its own keys.', 'oc-theme' ); ?></p>
			</details>

			<h2><?php esc_html_e( 'Sign in with Facebook', 'oc-theme' ); ?></h2>
			<table class="form-table">
				<tr>
					<th><?php esc_html_e( 'Facebook sign-in', 'oc-theme' ); ?></th>
					<td><label><input type="checkbox" name="fb_on" value="1" <?php checked( ! empty( $s['fb_on'] ) ); ?>> <?php esc_html_e( 'Show the Facebook button', 'oc-theme' ); ?></label></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'App ID', 'oc-theme' ); ?></th>
					<td><input type="text" name="fb_id" class="large-text ltr" value="<?php echo esc_attr( (string) $s['fb_id'] ); ?>" autocomplete="off"></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'App secret', 'oc-theme' ); ?></th>
					<td><input type="text" name="fb_secret" class="large-text ltr" value="<?php echo esc_attr( (string) $s['fb_secret'] ); ?>" autocomplete="off"></td>
				</tr>
			</table>
			<details style="max-width:760px;margin:4px 0 20px">
				<summary style="cursor:pointer;font-weight:600"><?php esc_html_e( 'How to get the keys from Facebook — step by step', 'oc-theme' ); ?></summary>
				<ol style="margin-top:12px;line-height:1.9">
					<li>
						<?php esc_html_e( 'Sign in at', 'oc-theme' ); ?>
						<a href="https://developers.facebook.com" target="_blank" rel="noopener">developers.facebook.com</a>
						<?php esc_html_e( 'with the client\'s Facebook account, press My Apps, then Create App, and walk the wizard: app name after the shop; use case "Authenticate and request data from users with Facebook Login"; under Business connect the client\'s verified business portfolio if there is one (helpful, not required); the Requirements screen is informational — Next, and Create.', 'oc-theme' ); ?>
					</li>
					<li><?php esc_html_e( 'In the app dashboard open Use cases, press Customize on the login use case, and under Permissions and features make sure email says "Ready for testing". Do not add any other permission — extras like birthday drag the app into a full App Review, and the panel only stores a name and an email anyway.', 'oc-theme' ); ?></li>
					<li>
						<?php esc_html_e( 'Still inside Customize, open Settings in its side menu, and under "Valid OAuth Redirect URIs" add exactly this, then Save Changes:', 'oc-theme' ); ?>
						<br><code style="direction:ltr;display:inline-block;user-select:all"><?php echo esc_html( admin_url( 'admin-post.php?action=oc_auth_fb_cb' ) ); ?></code>
					</li>
					<li><?php esc_html_e( 'Back in the app\'s own side menu: App settings, Basic. Fill the privacy policy URL, a category, the app icon and the site domain. For "User data deletion" choose "Data deletion instructions URL" and point it at the privacy policy page — customers delete themselves under My account, Account details, and the policy should say so.', 'oc-theme' ); ?></li>
					<li><?php esc_html_e( 'The keys sit at the top of that same Basic screen: App ID is visible, App Secret shows after pressing Show (Facebook asks for your password). Paste both into the fields above.', 'oc-theme' ); ?></li>
					<li><?php esc_html_e( 'In Development mode the login works only for the app\'s own admins — handy for a first test. Once it works, flip the toggle at the top to Live so every customer can sign in. The basic email and public_profile permissions need no review.', 'oc-theme' ); ?></li>
				</ol>
				<p class="description"><?php esc_html_e( 'Note: a Facebook account registered by phone may carry no email — the customer still gets an account, anchored to their Facebook identity.', 'oc-theme' ); ?></p>
			</details>

			<h2><?php esc_html_e( 'Sign in with Apple', 'oc-theme' ); ?></h2>
			<table class="form-table">
				<tr>
					<th><?php esc_html_e( 'Apple sign-in', 'oc-theme' ); ?></th>
					<td><label><input type="checkbox" name="apple_on" value="1" <?php checked( ! empty( $s['apple_on'] ) ); ?>> <?php esc_html_e( 'Show the Apple button', 'oc-theme' ); ?></label></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Services ID (client)', 'oc-theme' ); ?></th>
					<td><input type="text" name="apple_client_id" class="regular-text ltr" value="<?php echo esc_attr( (string) $s['apple_client_id'] ); ?>" placeholder="com.example.site" autocomplete="off"></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Team ID', 'oc-theme' ); ?></th>
					<td><input type="text" name="apple_team_id" class="regular-text ltr" value="<?php echo esc_attr( (string) $s['apple_team_id'] ); ?>" autocomplete="off"></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Key ID', 'oc-theme' ); ?></th>
					<td><input type="text" name="apple_key_id" class="regular-text ltr" value="<?php echo esc_attr( (string) $s['apple_key_id'] ); ?>" autocomplete="off"></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Private key (.p8 contents)', 'oc-theme' ); ?></th>
					<td>
						<textarea name="apple_key" rows="5" class="large-text code" style="direction:ltr" autocomplete="off"><?php echo esc_textarea( (string) $s['apple_key'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Open the downloaded .p8 file in a text editor and paste everything, BEGIN and END lines included.', 'oc-theme' ); ?></p>
					</td>
				</tr>
			</table>
			<details style="max-width:760px;margin:4px 0 20px">
				<summary style="cursor:pointer;font-weight:600"><?php esc_html_e( 'How to get the keys from Apple — step by step', 'oc-theme' ); ?></summary>
				<p class="description" style="margin-top:10px"><?php esc_html_e( 'Needed once per site, about ten minutes. Three things are created at Apple: an App ID (the primary identity), a Services ID (the site\'s identity — this is the "client"), and a key (.p8 file). The Team ID is not created — it already exists.', 'oc-theme' ); ?></p>
				<ol style="margin-top:12px;line-height:1.9">
					<li>
						<?php esc_html_e( 'An Apple Developer account is needed (99$ a year, the client\'s):', 'oc-theme' ); ?>
						<a href="https://developer.apple.com/account" target="_blank" rel="noopener">developer.apple.com/account</a>.
						<?php esc_html_e( 'Open Certificates, Identifiers & Profiles. The Team ID shows at the top of every page, next to the company name (10 characters) — copy it into the Team ID field above.', 'oc-theme' ); ?>
					</li>
					<li>
						<strong><?php esc_html_e( 'App ID.', 'oc-theme' ); ?></strong>
						<?php esc_html_e( 'Identifiers, press the plus. Choose App IDs, Continue; on the next screen choose App (not App Clip), Continue. Description: the site name; Bundle ID: Explicit, e.g. com.company.site. The capabilities list is long — use the magnifier to search "Sign in", tick Sign in with Apple and nothing else. Continue, Register.', 'oc-theme' ); ?>
					</li>
					<li>
						<strong><?php esc_html_e( 'Services ID.', 'oc-theme' ); ?></strong>
						<?php esc_html_e( 'The plus again, this time choose Services IDs, Continue. Description: anything; Identifier: the App ID\'s identifier with a suffix, e.g. com.company.site.web — it must differ from the App ID, the two share one namespace. This identifier is the value for the "Services ID (client)" field above. Continue, Register.', 'oc-theme' ); ?>
					</li>
					<li>
						<strong><?php esc_html_e( 'Configuring the Services ID.', 'oc-theme' ); ?></strong>
						<?php esc_html_e( 'Back in the Identifiers list, switch the filter at the top right from App IDs to Services IDs and open the one just created. Tick Sign in with Apple, press Configure: Primary App ID — the App ID from step 2; Domains — the site domain; Return URLs — exactly:', 'oc-theme' ); ?>
						<br><code style="direction:ltr;display:inline-block;user-select:all"><?php echo esc_html( admin_url( 'admin-post.php?action=oc_auth_apple_cb' ) ); ?></code>
						<br><?php esc_html_e( 'Next, Done — and do not leave without pressing Save at the top; this screen forgets silently.', 'oc-theme' ); ?>
					</li>
					<li>
						<strong><?php esc_html_e( 'The key.', 'oc-theme' ); ?></strong>
						<?php esc_html_e( 'Keys in the left menu, the plus. Name it, tick Sign in with Apple, Configure, pick the App ID from step 2, Save, Continue, Register — then Download. The .p8 file downloads exactly once, keep it somewhere safe. The Key ID (10 characters) shows on that screen, and stays readable any time under Keys — only the file itself is one-time.', 'oc-theme' ); ?>
					</li>
					<li>
						<strong><?php esc_html_e( 'Pasting the key.', 'oc-theme' ); ?></strong>
						<?php esc_html_e( 'On a Mac: right-click the downloaded AuthKey_XXXX.p8, Open With, TextEdit. Select all, copy, and paste into the Private key field above — with or without the BEGIN/END lines, both work.', 'oc-theme' ); ?>
					</li>
					<li>
						<strong><?php esc_html_e( 'Hide My Email.', 'oc-theme' ); ?></strong>
						<?php esc_html_e( 'Customers may hide their address and get an Apple relay — it works, but Apple forwards mail only from registered senders. Under Services (left menu), "Sign in with Apple for Email Communication": add the site domain and the shop\'s sending address, and check they show a green SPF. Skip this and order emails to those customers silently vanish.', 'oc-theme' ); ?>
					</li>
					<li><?php esc_html_e( 'Fill the four fields above, tick "Show the Apple button", Save — and try it. Note: Apple sends the customer\'s name only on the very first approval; a customer who removes the site under Settings, Apple ID, Sign-In & Security can approve it afresh.', 'oc-theme' ); ?></li>
				</ol>
			</details>

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
		$s['fb_on']     = empty( $_POST['fb_on'] ) ? 0 : 1;
		$s['apple_on']  = empty( $_POST['apple_on'] ) ? 0 : 1;
		$s['reach']     = 'intl' === ( $_POST['reach'] ?? '' ) ? 'intl' : 'israel';

		foreach ( array( 'api_key', 'sender', 'google_id', 'google_secret', 'fb_id', 'fb_secret', 'apple_client_id', 'apple_team_id', 'apple_key_id' ) as $field ) {
			$s[ $field ] = sanitize_text_field( (string) wp_unslash( $_POST[ $field ] ?? '' ) );
		}

		// The .p8 must keep its line breaks — openssl reads PEM, not prose.
		$s['apple_key'] = trim( (string) wp_unslash( $_POST['apple_key'] ?? '' ) );

		foreach ( array( 'code_expiry' => 180, 'max_attempts' => 5, 'resend_cooldown' => 60, 'phone_hourly' => 3, 'ip_hourly' => 5, 'daily_cap' => 300 ) as $field => $fallback ) {
			$value       = absint( $_POST[ $field ] ?? 0 );
			$s[ $field ] = $value > 0 ? $value : $fallback;
		}

		update_option( Auth::SETTINGS, $s, false );
		wp_safe_redirect( admin_url( 'options-general.php?page=oc-auth&oc_msg=1' ) );
		exit;
	}
}
