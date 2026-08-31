<?php
/**
 * Two-factor sign-in for the admin.
 *
 * A password is one secret, and secrets travel: they get reused on some other
 * site that is later breached, or typed into a convincing copy of the login
 * page. Hiding the login page and blocking guesses, which the hardening does,
 * changes none of that — whoever ends up holding the password walks in.
 *
 * So the password buys the first half of the way in and a code sent to the
 * phone on the account buys the second. Taking the shop now needs the password
 * *and* the handset.
 *
 * The flow, in order:
 *
 *   1. Username and password are checked by WordPress exactly as before.
 *   2. If they are right, the sign-in stops there — no cookie is set. A code
 *      is minted, only its hash is kept, and the phone gets a text.
 *   3. The browser carries a single-use token to a second screen. The token
 *      says "the password was right for this account", so the password itself
 *      never makes a second trip.
 *   4. The right code within the time limit sets the cookie and finishes the
 *      login that step 2 held back.
 *
 * Two things keep this from locking anyone out of their own shop, which is the
 * real risk with any second factor:
 *
 *   - If the text cannot be sent — the provider is down, the credits ran out —
 *     the code goes to the account's e-mail instead. The second factor still
 *     stands (you must hold the phone or the mailbox), but a broken SMS
 *     account is no longer a locked door.
 *   - `define( 'OC_2FA_DISABLE', true );` in wp-config.php turns the whole
 *     thing off, for the day something here misbehaves.
 *
 * An account with no phone number on file is let through with a notice asking
 * for one, rather than shut out of the site it administers.
 *
 * @package OC_Theme
 */

declare( strict_types = 1 );

namespace OC\Theme;

defined( 'ABSPATH' ) || exit;

/**
 * The second factor.
 */
final class Two_Factor {

	/**
	 * The login action our second screen answers on.
	 */
	private const ACTION = 'oc2fa';

	/**
	 * How long a half-finished sign-in may sit there.
	 */
	private const WINDOW = 10 * MINUTE_IN_SECONDS;

	/**
	 * Wrong codes allowed before the attempt is torn up.
	 */
	private const TRIES = 5;

	/**
	 * Hook in.
	 */
	public function register(): void {
		if ( defined( 'OC_2FA_DISABLE' ) && constant( 'OC_2FA_DISABLE' ) ) {
			return;
		}

		// After WordPress has had its say on the password.
		add_filter( 'authenticate', array( $this, 'after_password' ), 50, 1 );
		add_action( 'login_form_' . self::ACTION, array( $this, 'screen' ) );
		add_action( 'admin_notices', array( $this, 'no_phone_notice' ) );

		// Somewhere to put the number the codes go to.
		add_action( 'show_user_profile', array( $this, 'profile_field' ) );
		add_action( 'edit_user_profile', array( $this, 'profile_field' ) );
		add_action( 'personal_options_update', array( $this, 'save_profile' ) );
		add_action( 'edit_user_profile_update', array( $this, 'save_profile' ) );
	}

	/**
	 * The mobile number, on the profile screen.
	 *
	 * @param \WP_User $user Whose profile.
	 */
	public function profile_field( $user ): void {
		if ( ! $user instanceof \WP_User ) {
			return;
		}

		$phone = (string) get_user_meta( $user->ID, 'oc_phone', true );
		$local = 0 === strpos( $phone, '972' ) ? '0' . substr( $phone, 3 ) : $phone;
		?>
		<h2><?php esc_html_e( 'Two-step sign-in', 'oc-theme' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th><label for="oc_phone"><?php esc_html_e( 'Mobile for sign-in codes', 'oc-theme' ); ?></label></th>
				<td>
					<input type="tel" name="oc_phone" id="oc_phone" dir="ltr" class="regular-text"
						value="<?php echo esc_attr( $local ); ?>" autocomplete="tel">
					<p class="description">
						<?php
						if ( 'off' === self::mode() ) {
							esc_html_e( 'Two-step sign-in is currently off for everyone. The number is kept ready for when it is switched on.', 'oc-theme' );
						} elseif ( '' === $phone ) {
							esc_html_e( 'Without a number there is nowhere to send a code, so this account still rests on its password alone.', 'oc-theme' );
						} else {
							esc_html_e( 'Codes for this account go here. If the text cannot be sent, the code goes to this account\'s e-mail instead.', 'oc-theme' );
						}
						?>
					</p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Keep it, in the same shape the rest of the sign-in uses.
	 *
	 * @param int $user_id Whose profile.
	 */
	public function save_profile( $user_id ): void {
		$user_id = (int) $user_id;

		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WordPress checks the profile form's own nonce before this fires.
		if ( ! isset( $_POST['oc_phone'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- as above.
		$phone = Auth::normalize_phone( (string) wp_unslash( $_POST['oc_phone'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- normalize_phone() reduces it to digits.

		if ( '' === $phone ) {
			delete_user_meta( $user_id, 'oc_phone' );
			return;
		}

		update_user_meta( $user_id, 'oc_phone', $phone );
	}

	/*
	 * ------------------------------------------------------------- who and if
	 */

	/**
	 * Is the second factor switched on at all, and for whom?
	 */
	public static function mode(): string {
		$mode = (string) ( Auth::settings()['tfa_mode'] ?? 'off' );

		return in_array( $mode, array( 'off', 'admins', 'shop', 'all' ), true ) ? $mode : 'off';
	}

	/**
	 * Does this account have to prove itself twice?
	 *
	 * @param \WP_User $user The account.
	 */
	public static function required_for( \WP_User $user ): bool {
		switch ( self::mode() ) {
			case 'admins':
				return user_can( $user, 'manage_options' );

			case 'shop':
				return user_can( $user, 'manage_options' ) || user_can( $user, 'edit_shop_orders' );

			case 'all':
				return true;

			case 'off':
			default:
				return false;
		}
	}

	/**
	 * The phone this account's codes go to.
	 *
	 * @param int $user_id The account.
	 */
	public static function phone( int $user_id ): string {
		$phone = (string) get_user_meta( $user_id, 'oc_phone', true );

		if ( '' === $phone ) {
			$phone = Auth::normalize_phone( (string) get_user_meta( $user_id, 'billing_phone', true ) );
		}

		return $phone;
	}

	/*
	 * ------------------------------------------------------------ first factor
	 */

	/**
	 * The password was right — now hold the door.
	 *
	 * @param \WP_User|\WP_Error|null $user What WordPress made of the login.
	 * @return \WP_User|\WP_Error|null
	 */
	public function after_password( $user ) {
		if ( ! $user instanceof \WP_User ) {
			return $user;
		}

		if ( ! $this->is_login_form() || ! self::required_for( $user ) ) {
			return $user;
		}

		$phone = self::phone( $user->ID );

		// Nobody is shut out of the site they run because we have no number
		// for them. They are told about it in the admin instead.
		if ( '' === $phone ) {
			return $user;
		}

		$token = wp_generate_password( 32, false );
		$code  = (string) random_int( 100000, 999999 );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- the login form's own POST, already authenticated above.
		$remember = ! empty( $_POST['rememberme'] );
		$to       = isset( $_POST['redirect_to'] ) ? (string) wp_unslash( $_POST['redirect_to'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated by wp_validate_redirect() at use in finish().
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		set_transient(
			self::key( $token ),
			array(
				'uid'      => $user->ID,
				'hash'     => wp_hash( $code . $token ),
				'tries'    => 0,
				'remember' => $remember,
				'to'       => $to,
			),
			self::WINDOW
		);

		$this->deliver( $user, $phone, $code );

		wp_safe_redirect( self::url( $token ) );
		exit;
	}

	/**
	 * Only the sign-in form, never a programmatic or API login.
	 */
	private function is_login_form(): bool {
		if ( defined( 'XMLRPC_REQUEST' ) || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || wp_doing_cron() ) {
			return false;
		}

		if ( 'wp-login.php' !== (string) ( $GLOBALS['pagenow'] ?? '' ) ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- checking the shape of the request, not trusting it.
		return isset( $_POST['log'], $_POST['pwd'] );
	}

	/**
	 * Get the code to the person.
	 *
	 * The text first; the account's e-mail if that fails, so a provider
	 * outage costs a slower login rather than the whole shop.
	 *
	 * @param \WP_User $user  The account.
	 * @param string   $phone Their number.
	 * @param string   $code  The six digits.
	 */
	private function deliver( \WP_User $user, string $phone, string $code ): bool {
		if ( Auth::sms_code( $phone, $code ) ) {
			return true;
		}

		/* translators: %s: the site name. */
		$subject = sprintf( __( 'Your sign-in code for %s', 'oc-theme' ), get_bloginfo( 'name' ) );

		/* translators: %s: the six-digit code. */
		$body = sprintf( __( '%s is your sign-in code. It is good for ten minutes.', 'oc-theme' ), $code );

		return (bool) wp_mail( $user->user_email, $subject, $body );
	}

	/*
	 * ----------------------------------------------------------- second factor
	 */

	/**
	 * The second screen: ask for the code, and check it.
	 */
	public function screen(): void {
		// phpcs:disable WordPress.Security.NonceVerification -- the token below is the proof, and it is single-use.
		$token = isset( $_REQUEST['oc_t'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['oc_t'] ) ) : '';
		$kept  = '' === $token ? false : get_transient( self::key( $token ) );

		if ( ! is_array( $kept ) ) {
			wp_safe_redirect( wp_login_url() );
			exit;
		}

		$error = '';

		if ( 'POST' === sanitize_text_field( wp_unslash( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) && isset( $_POST['oc_code'] ) ) {
			$given = (string) wp_unslash( $_POST['oc_code'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- check() reduces it to digits.

			switch ( self::check( $token, $given ) ) {
				case 'ok':
					$this->finish( $kept );
					break;

				case 'spent':
					wp_safe_redirect( add_query_arg( 'oc_2fa', 'spent', wp_login_url() ) );
					exit;

				case 'gone':
					wp_safe_redirect( wp_login_url() );
					exit;

				default:
					$error = __( 'That code was not right.', 'oc-theme' );
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification

		$this->form( $token, $error, (int) $kept['uid'] );
	}

	/**
	 * Weigh a code against a half-finished sign-in.
	 *
	 * Separate from the screen so the whole of it can be proven without a
	 * browser — this is the piece that, wrong, either lets anyone in or
	 * locks the owner out.
	 *
	 * @param string $token The single-use token.
	 * @param string $given What was typed.
	 *
	 * @return string One of: ok, bad, spent, gone.
	 */
	public static function check( string $token, string $given ): string {
		$kept = '' === $token ? false : get_transient( self::key( $token ) );

		if ( ! is_array( $kept ) ) {
			return 'gone';
		}

		$given = (string) preg_replace( '/\D+/', '', $given );

		if ( '' !== $given && hash_equals( (string) $kept['hash'], wp_hash( $given . $token ) ) ) {
			// Single use: the token dies with the sign-in it authorised.
			delete_transient( self::key( $token ) );
			return 'ok';
		}

		$kept['tries'] = (int) $kept['tries'] + 1;

		if ( $kept['tries'] >= self::TRIES ) {
			delete_transient( self::key( $token ) );
			return 'spent';
		}

		set_transient( self::key( $token ), $kept, self::WINDOW );

		return 'bad';
	}

	/**
	 * Right code: set the cookie the first factor held back.
	 *
	 * @param array<string,mixed> $kept The half-finished sign-in.
	 */
	private function finish( array $kept ): void {
		$user = get_user_by( 'id', (int) $kept['uid'] );

		if ( ! $user instanceof \WP_User ) {
			wp_safe_redirect( wp_login_url() );
			exit;
		}

		wp_set_auth_cookie( $user->ID, ! empty( $kept['remember'] ) );
		wp_set_current_user( $user->ID );

		/** This action is documented in wp-includes/user.php */
		do_action( 'wp_login', $user->user_login, $user );

		$to = (string) $kept['to'];
		$to = '' !== $to ? $to : admin_url();

		wp_safe_redirect( wp_validate_redirect( $to, admin_url() ) );
		exit;
	}

	/**
	 * Draw the code screen, in WordPress's own login furniture.
	 *
	 * @param string $token The single-use token.
	 * @param string $error What went wrong, if anything.
	 * @param int    $uid   Whose sign-in this is.
	 */
	private function form( string $token, string $error, int $uid ): void {
		$phone = self::phone( $uid );
		$tail  = mb_substr( $phone, -4 );

		login_header( __( 'Two-step sign-in', 'oc-theme' ), '', $error ? new \WP_Error( 'oc2fa', $error ) : null );

		?>
		<form name="oc2fa" id="oc2fa" action="<?php echo esc_url( self::url( $token ) ); ?>" method="post">
			<p style="margin-block-end:14px">
				<?php
				printf(
					/* translators: %s: the last four digits of a phone number. */
					esc_html__( 'We sent a code to the phone ending %s.', 'oc-theme' ),
					'<strong dir="ltr">' . esc_html( $tail ) . '</strong>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inline.
				);
				?>
			</p>

			<p>
				<label for="oc_code"><?php esc_html_e( 'Code', 'oc-theme' ); ?></label>
				<input type="text" name="oc_code" id="oc_code" class="input" value="" size="20"
					inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]*" maxlength="6"
					dir="ltr" style="text-align:center;letter-spacing:.4em;font-size:20px" autofocus>
			</p>

			<input type="hidden" name="oc_t" value="<?php echo esc_attr( $token ); ?>">

			<p class="submit">
				<button type="submit" class="button button-primary button-large" style="inline-size:100%">
					<?php esc_html_e( 'Sign in', 'oc-theme' ); ?>
				</button>
			</p>
		</form>

		<p id="nav">
			<a href="<?php echo esc_url( wp_login_url() ); ?>"><?php esc_html_e( 'Start again', 'oc-theme' ); ?></a>
		</p>
		<?php

		login_footer( 'oc_code' );
		exit;
	}

	/*
	 * ------------------------------------------------------------------ odds
	 */

	/**
	 * The second screen's address.
	 *
	 * @param string $token The single-use token.
	 */
	private static function url( string $token ): string {
		return add_query_arg(
			array(
				'action' => self::ACTION,
				'oc_t'   => rawurlencode( $token ),
			),
			wp_login_url()
		);
	}

	/**
	 * Where a half-finished sign-in is kept.
	 *
	 * The token is hashed, so a peek at the options table is not a way in.
	 *
	 * @param string $token The token.
	 */
	private static function key( string $token ): string {
		return 'oc2fa_' . hash( 'sha256', $token );
	}

	/**
	 * Tell an administrator who has no phone on file.
	 *
	 * Without one there is nowhere to send a code, so their account is the
	 * one still resting on a password alone.
	 */
	public function no_phone_notice(): void {
		if ( 'off' === self::mode() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$user = wp_get_current_user();

		if ( ! $user instanceof \WP_User || ! self::required_for( $user ) ) {
			return;
		}

		if ( '' !== self::phone( $user->ID ) ) {
			return;
		}

		echo '<div class="notice notice-warning"><p>' .
			esc_html__( 'Two-step sign-in is on, but there is no phone number on your account — so there is nowhere to send your code and your account is still protected by its password alone. Add a mobile number to your profile.', 'oc-theme' ) .
			' <a href="' . esc_url( admin_url( 'profile.php' ) ) . '">' . esc_html__( 'Edit your profile', 'oc-theme' ) . '</a></p></div>';
	}
}
