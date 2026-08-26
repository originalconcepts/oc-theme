<?php
/**
 * OC Auth — the login panel's engine.
 *
 * One drawer, three steps. A phone number in: a recognised customer gets a
 * six-digit SMS code and is in; an unrecognised one fills their details a
 * single time (no code — the shop owner's call) and is in. Google arrives
 * as a native OIDC flow, no plugin. Every send is rate-limited per phone,
 * per IP and per day, because the SMS line is a credit card.
 *
 * Secrets live in the settings option (never in theme mods — those travel
 * between sites when a new shop is provisioned).
 *
 * @package OC_Theme
 */

declare( strict_types = 1 );

namespace OC\Theme;

defined( 'ABSPATH' ) || exit;

/**
 * Phone-first login.
 */
final class Auth {

	const SETTINGS = 'oc_auth_settings';

	/**
	 * Hook in.
	 */
	public function register(): void {
		// The right to delete your account does not depend on which sign-in
		// methods are switched on — it always exists.
		add_action( 'woocommerce_after_edit_account_form', array( $this, 'delete_block' ) );
		add_action( 'admin_post_oc_delete_account', array( $this, 'delete_account' ) );

		// Signed-in comforts, provider-independent too: the account icon
		// grows a green dot and a quick menu, and an account whose display
		// name is still its internal login heals itself to the first name.
		add_action( 'wp_footer', array( $this, 'account_menu' ) );
		add_action( 'init', array( $this, 'heal_display_name' ) );

		$on = self::settings();

		if ( empty( $on['sms_on'] ) && empty( $on['google_on'] ) && empty( $on['fb_on'] ) && empty( $on['apple_on'] ) && empty( $on['email_on'] ) ) {
			return; // Nothing enabled — the account icon behaves as always.
		}

		add_action( 'wp_footer', array( $this, 'panel' ) );
		add_filter( 'oc_header_account_attrs', array( $this, 'icon_attrs' ) );

		foreach ( array( 'oc_auth_start', 'oc_auth_verify', 'oc_auth_register', 'oc_auth_email_code', 'oc_auth_email_login' ) as $action ) {
			add_action( 'wp_ajax_nopriv_' . $action, array( $this, substr( $action, 3 ) ) );
			add_action( 'wp_ajax_' . $action, array( $this, substr( $action, 3 ) ) );
		}

		add_action( 'admin_post_nopriv_oc_auth_google', array( $this, 'google_start' ) );
		add_action( 'admin_post_oc_auth_google', array( $this, 'google_start' ) );
		add_action( 'admin_post_nopriv_oc_auth_google_cb', array( $this, 'google_back' ) );
		add_action( 'admin_post_oc_auth_google_cb', array( $this, 'google_back' ) );
		add_action( 'admin_post_nopriv_oc_auth_fb', array( $this, 'fb_start' ) );
		add_action( 'admin_post_oc_auth_fb', array( $this, 'fb_start' ) );
		add_action( 'admin_post_nopriv_oc_auth_fb_cb', array( $this, 'fb_back' ) );
		add_action( 'admin_post_oc_auth_fb_cb', array( $this, 'fb_back' ) );
		add_action( 'admin_post_nopriv_oc_auth_apple', array( $this, 'apple_start' ) );
		add_action( 'admin_post_oc_auth_apple', array( $this, 'apple_start' ) );
		add_action( 'admin_post_nopriv_oc_auth_apple_cb', array( $this, 'apple_back' ) );
		add_action( 'admin_post_oc_auth_apple_cb', array( $this, 'apple_back' ) );
	}

	/**
	 * Everything, with defaults, one option.
	 *
	 * @return array<string,mixed>
	 */
	public static function settings(): array {
		$saved = get_option( self::SETTINGS, array() );

		return wp_parse_args(
			is_array( $saved ) ? $saved : array(),
			array(
				'sms_on'          => 0,
				'api_key'         => '',
				'sender'          => '',
				'reach'           => 'israel',
				'code_expiry'     => 180,
				'max_attempts'    => 5,
				'resend_cooldown' => 60,
				'phone_hourly'    => 3,
				'ip_hourly'       => 5,
				'daily_cap'       => 300,
				'email_on'        => 0,
				'google_on'       => 0,
				'google_id'       => '',
				'google_secret'   => '',
				'fb_on'           => 0,
				'fb_id'           => '',
				'fb_secret'       => '',
				'apple_on'        => 0,
				'apple_client_id' => '',
				'apple_team_id'   => '',
				'apple_key_id'    => '',
				'apple_key'       => '',
			)
		);
	}

	/*
	 * --------------------------------------------------------------- phone
	 */

	/**
	 * A phone as the visitor typed it into the digits we store: local
	 * Israeli numbers become 972…, international ones keep their code.
	 * Empty string when the number does not pass the configured reach.
	 *
	 * @param string $raw Whatever was typed.
	 */
	public static function normalize_phone( string $raw ): string {
		$digits = (string) preg_replace( '/\D+/', '', $raw );

		if ( 0 === strpos( $digits, '00' ) ) {
			$digits = substr( $digits, 2 );
		}

		$israeli = '';

		if ( 0 === strpos( $digits, '972' ) ) {
			$israeli = '0' . substr( $digits, 3 );
		} elseif ( 0 === strpos( $digits, '0' ) ) {
			$israeli = $digits;
		}

		if ( preg_match( '/^05\d{8}$/', $israeli ) ) {
			return '972' . substr( $israeli, 1 );
		}

		if ( 'intl' === self::settings()['reach'] && strlen( $digits ) >= 9 && strlen( $digits ) <= 15 && 0 !== strpos( $digits, '0' ) ) {
			return $digits;
		}

		return '';
	}

	/**
	 * The phone as people read it: 05X-XXXXXXX for Israel, +digits abroad.
	 *
	 * @param string $phone Normalised digits.
	 */
	public static function pretty_phone( string $phone ): string {
		if ( 0 === strpos( $phone, '972' ) ) {
			$local = '0' . substr( $phone, 3 );
			return substr( $local, 0, 3 ) . '-' . substr( $local, 3 );
		}

		return '+' . $phone;
	}

	/**
	 * The user this phone belongs to, if any.
	 *
	 * @param string $phone Normalised digits.
	 */
	public static function user_by_phone( string $phone ) {
		// Every spelling the number may be stored under, ours and Woo's.
		$variants = array_unique(
			array(
				$phone,
				'+' . $phone,
				0 === strpos( $phone, '972' ) ? '0' . substr( $phone, 3 ) : $phone,
			)
		);

		foreach ( array( 'oc_phone', 'billing_phone' ) as $key ) {
			$found = get_users(
				array(
					'meta_key'     => $key, // phpcs:ignore WordPress.DB.SlowDBQuery
					'meta_value'   => $variants, // phpcs:ignore WordPress.DB.SlowDBQuery
					'meta_compare' => 'IN',
					'number'       => 1,
					'fields'       => 'all',
				)
			);

			if ( ! empty( $found ) ) {
				return $found[0];
			}
		}

		return null;
	}

	/*
	 * --------------------------------------------------------- rate limits
	 */

	/**
	 * May we send this phone a code right now? '' when yes, a human reason
	 * when no. Counts one send when allowed.
	 *
	 * @param string $phone Normalised digits.
	 */
	private function may_send( string $phone ): string {
		$s  = self::settings();
		$ip = self::ip();

		if ( get_transient( 'ocau_cool_' . $phone ) ) {
			return __( 'A code was just sent — give it a moment before asking again.', 'oc-theme' );
		}

		$per_phone = (int) get_transient( 'ocau_ph_' . $phone );
		$per_ip    = (int) get_transient( 'ocau_ip_' . md5( $ip ) );

		if ( $per_phone >= (int) $s['phone_hourly'] || $per_ip >= (int) $s['ip_hourly'] ) {
			return __( 'Too many attempts — try again in about an hour.', 'oc-theme' );
		}

		// The daily budget: past the cap the tap closes and the owner hears.
		$day = get_option( 'ocau_day', array() );

		if ( ! is_array( $day ) || ( $day['d'] ?? '' ) !== gmdate( 'Y-m-d' ) ) {
			$day = array(
				'd' => gmdate( 'Y-m-d' ),
				'n' => 0,
			);
		}

		if ( $day['n'] >= (int) $s['daily_cap'] ) {
			if ( empty( $day['told'] ) ) {
				$day['told'] = 1;
				update_option( 'ocau_day', $day, false );
				wp_mail(
					(string) get_option( 'admin_email' ),
					__( 'SMS login: the daily cap was reached', 'oc-theme' ),
					sprintf(
						/* translators: %d: the cap. */
						__( 'The login SMS budget (%d a day) ran out — sending is paused until midnight. If this is real traffic, raise the cap under Settings, Login.', 'oc-theme' ),
						(int) $s['daily_cap']
					)
				);
			}

			return __( 'SMS login is resting — please try again later or sign in another way.', 'oc-theme' );
		}

		++$day['n'];
		update_option( 'ocau_day', $day, false );
		set_transient( 'ocau_cool_' . $phone, 1, (int) $s['resend_cooldown'] );
		set_transient( 'ocau_ph_' . $phone, $per_phone + 1, HOUR_IN_SECONDS );
		set_transient( 'ocau_ip_' . md5( $ip ), $per_ip + 1, HOUR_IN_SECONDS );

		return '';
	}

	/**
	 * The visitor's address, best effort.
	 */
	private static function ip(): string {
		return isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	}

	/*
	 * ---------------------------------------------------------- the code
	 */

	/**
	 * Mint a code for this phone and remember only its hash.
	 *
	 * @param string $phone Normalised digits.
	 * @return string The code, for sending.
	 */
	private function mint_code( string $phone ): string {
		$code = (string) random_int( 100000, 999999 );

		set_transient(
			'ocau_code_' . $phone,
			array(
				'h' => wp_hash( $code . $phone ),
				'a' => 0,
			),
			max( 60, (int) self::settings()['code_expiry'] )
		);

		return $code;
	}

	/**
	 * Send the code by SMS through ActiveTrail. True on 200.
	 *
	 * @param string $phone Normalised digits.
	 * @param string $code  The six digits.
	 */
	private function send_sms( string $phone, string $code ): bool {
		$s = self::settings();

		if ( '' === trim( (string) $s['api_key'] ) ) {
			return false;
		}

		// A sender name may carry at most 11 characters — the carrier's
		// rule, enforced by ActiveTrail with a 400. Never let a long shop
		// name silently kill every code.
		$from = trim( (string) $s['sender'] );
		$from = mb_substr( '' !== $from ? $from : (string) get_bloginfo( 'name' ), 0, 11 );

		$response = wp_remote_post(
			'https://webapi.mymarketing.co.il/api/smscampaign/OperationalMessage',
			array(
				'timeout' => 15,
				'headers' => array(
					'Authorization' => (string) $s['api_key'],
					'Content-Type'  => 'application/json',
				),
				'body'    => (string) wp_json_encode(
					array(
						'details'    => array(
							'unsubscribe_text' => '',
							'can_unsubscribe'  => false,
							'name'             => 'login',
							'from_name'        => $from,
							/* translators: %s: the code. */
							'content'          => sprintf( __( '%s is your sign-in code', 'oc-theme' ), $code ),
						),
						'scheduling' => array( 'send_now' => true ),
						'mobiles'    => array( array( 'phone_number' => 0 === strpos( $phone, '972' ) ? '0' . substr( $phone, 3 ) : $phone ) ),
					)
				),
			)
		);

		return ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response );
	}

	/*
	 * ------------------------------------------------------ ajax endpoints
	 */

	/**
	 * The guard every endpoint passes first.
	 */
	private function guard(): void {
		if ( ! check_ajax_referer( 'oc_auth', 'nonce', false ) ) {
			wp_send_json_error( array( 'msg' => __( 'The page went stale — refresh and try again.', 'oc-theme' ) ) );
		}
	}

	/**
	 * Step one: a phone arrives. Recognised → code sent. New → the
	 * registration step (the owner chose: no code for new customers).
	 */
	public function auth_start(): void {
		$this->guard();

		$phone = self::normalize_phone( (string) wp_unslash( $_POST['phone'] ?? '' ) );

		if ( '' === $phone ) {
			wp_send_json_error( array(
				'msg' => 'israel' === self::settings()['reach']
					? __( 'That does not look like an Israeli mobile number.', 'oc-theme' )
					: __( 'That does not look like a valid phone number.', 'oc-theme' ),
			) );
		}

		$user = self::user_by_phone( $phone );

		if ( ! $user ) {
			wp_send_json_success( array(
				'step'   => 'register',
				'phone'  => $phone,
				'pretty' => self::pretty_phone( $phone ),
			) );
		}

		if ( empty( self::settings()['sms_on'] ) ) {
			wp_send_json_error( array( 'msg' => __( 'Phone sign-in is not available right now.', 'oc-theme' ) ) );
		}

		$why = $this->may_send( $phone );

		if ( '' !== $why ) {
			wp_send_json_error( array( 'msg' => $why ) );
		}

		if ( ! $this->send_sms( $phone, $this->mint_code( $phone ) ) ) {
			wp_send_json_error( array( 'msg' => __( 'The message did not go out — try again in a moment.', 'oc-theme' ) ) );
		}

		wp_send_json_success( array(
			'step'   => 'code',
			'phone'  => $phone,
			'pretty' => self::pretty_phone( $phone ),
			'email'  => '' !== (string) $user->user_email,
			'wait'   => (int) self::settings()['resend_cooldown'],
		) );
	}

	/**
	 * The same code, to the customer's email instead — recognised users
	 * only, and only when one is on file.
	 */
	public function auth_email_code(): void {
		$this->guard();

		$phone = self::normalize_phone( (string) wp_unslash( $_POST['phone'] ?? '' ) );
		$user  = '' === $phone ? null : self::user_by_phone( $phone );

		if ( ! $user || '' === (string) $user->user_email ) {
			wp_send_json_error( array( 'msg' => __( 'Email is not available for this number.', 'oc-theme' ) ) );
		}

		$why = $this->may_send( $phone );

		if ( '' !== $why ) {
			wp_send_json_error( array( 'msg' => $why ) );
		}

		$code = $this->mint_code( $phone );

		wp_mail(
			(string) $user->user_email,
			/* translators: %s: site name. */
			sprintf( __( 'Your sign-in code for %s', 'oc-theme' ), (string) get_bloginfo( 'name' ) ),
			/* translators: %s: the code. */
			sprintf( __( '%s is your sign-in code', 'oc-theme' ), $code )
		);

		wp_send_json_success( array( 'wait' => (int) self::settings()['resend_cooldown'] ) );
	}

	/**
	 * Plain email + password — for whoever prefers the classic door.
	 * Slowed hard against guessing, and closed to administrators: their
	 * door is the private login path, and this endpoint must not become a
	 * password oracle for it.
	 */
	public function auth_email_login(): void {
		$this->guard();

		if ( empty( self::settings()['email_on'] ) ) {
			wp_send_json_error( array( 'msg' => __( 'Email sign-in is not available right now.', 'oc-theme' ) ) );
		}

		$email = sanitize_email( (string) wp_unslash( $_POST['email'] ?? '' ) );
		$pass  = (string) wp_unslash( $_POST['password'] ?? '' );

		if ( ! is_email( $email ) || '' === $pass ) {
			wp_send_json_error( array( 'msg' => __( 'An email and a password are both needed.', 'oc-theme' ) ) );
		}

		$bucket = 'ocau_pw_' . md5( self::ip() . '|' . $email );
		$fails  = (int) get_transient( $bucket );

		if ( $fails >= 5 ) {
			wp_send_json_error( array( 'msg' => __( 'Too many attempts — try again in a few minutes.', 'oc-theme' ) ) );
		}

		$user = wp_authenticate( $email, $pass );

		if ( is_wp_error( $user ) || user_can( $user, 'manage_options' ) || user_can( $user, 'manage_woocommerce' ) ) {
			set_transient( $bucket, $fails + 1, 15 * MINUTE_IN_SECONDS );
			wp_send_json_error( array( 'msg' => __( 'The email or the password is wrong.', 'oc-theme' ) ) );
		}

		delete_transient( $bucket );
		wp_set_auth_cookie( $user->ID, true );
		wp_send_json_success();
	}

	/**
	 * Step two: six digits back. Right → in. Wrong → counted; too many
	 * wrongs kill the code.
	 */
	public function auth_verify(): void {
		$this->guard();

		$phone = self::normalize_phone( (string) wp_unslash( $_POST['phone'] ?? '' ) );
		$code  = (string) preg_replace( '/\D+/', '', (string) wp_unslash( $_POST['code'] ?? '' ) );
		$kept  = '' === $phone ? false : get_transient( 'ocau_code_' . $phone );

		if ( ! is_array( $kept ) ) {
			wp_send_json_error( array( 'msg' => __( 'The code expired — ask for a new one.', 'oc-theme' ) ) );
		}

		if ( ! hash_equals( (string) $kept['h'], wp_hash( $code . $phone ) ) ) {
			++$kept['a'];

			if ( $kept['a'] >= (int) self::settings()['max_attempts'] ) {
				delete_transient( 'ocau_code_' . $phone );
				wp_send_json_error( array( 'msg' => __( 'Too many tries — ask for a new code.', 'oc-theme' ) ) );
			}

			set_transient( 'ocau_code_' . $phone, $kept, max( 60, (int) self::settings()['code_expiry'] ) );
			wp_send_json_error( array( 'msg' => __( 'That is not the code — look again.', 'oc-theme' ) ) );
		}

		delete_transient( 'ocau_code_' . $phone );

		$user = self::user_by_phone( $phone );

		if ( ! $user ) {
			wp_send_json_error( array( 'msg' => __( 'Something moved — start again.', 'oc-theme' ) ) );
		}

		update_user_meta( $user->ID, 'oc_phone', $phone );
		wp_set_auth_cookie( $user->ID, true );
		wp_send_json_success();
	}

	/**
	 * The registration step: a one-time form for a number we do not know.
	 * Honeypot and a time trap keep the bots bored.
	 */
	public function auth_register(): void {
		$this->guard();

		// The honeypot field must come back empty, and the form must have
		// been open for longer than a script bothers to wait.
		if ( '' !== trim( (string) wp_unslash( $_POST['website'] ?? '' ) ) || ( time() - absint( $_POST['ts'] ?? 0 ) ) < 3 ) {
			wp_send_json_error( array( 'msg' => __( 'Something went wrong — refresh and try again.', 'oc-theme' ) ) );
		}

		$ip     = 'ocau_reg_' . md5( self::ip() );
		$per_ip = (int) get_transient( $ip );

		if ( $per_ip >= (int) self::settings()['ip_hourly'] ) {
			wp_send_json_error( array( 'msg' => __( 'Too many attempts — try again in about an hour.', 'oc-theme' ) ) );
		}

		set_transient( $ip, $per_ip + 1, HOUR_IN_SECONDS );

		$phone = self::normalize_phone( (string) wp_unslash( $_POST['phone'] ?? '' ) );
		$first = sanitize_text_field( (string) wp_unslash( $_POST['first'] ?? '' ) );
		$last  = sanitize_text_field( (string) wp_unslash( $_POST['last'] ?? '' ) );
		$email = sanitize_email( (string) wp_unslash( $_POST['email'] ?? '' ) );

		if ( '' === $phone || '' === $first || ! is_email( $email ) ) {
			wp_send_json_error( array( 'msg' => __( 'A name, a valid email and a phone are all needed.', 'oc-theme' ) ) );
		}

		if ( empty( $_POST['consent'] ) ) {
			wp_send_json_error( array( 'msg' => __( 'The privacy policy needs your approval.', 'oc-theme' ) ) );
		}

		if ( self::user_by_phone( $phone ) ) {
			wp_send_json_error( array( 'msg' => __( 'This number is already registered — sign in with it instead.', 'oc-theme' ) ) );
		}

		if ( email_exists( $email ) ) {
			wp_send_json_error( array( 'msg' => __( 'This email is already registered — try signing in with Google, or use another address.', 'oc-theme' ) ) );
		}

		$login = 'u' . $phone;

		if ( username_exists( $login ) ) {
			$login .= wp_rand( 10, 99 );
		}

		$user_id = wp_insert_user(
			array(
				'user_login'   => $login,
				'user_pass'    => wp_generate_password( 24 ),
				'user_email'   => $email,
				'first_name'   => $first,
				'last_name'    => $last,
				'display_name' => trim( $first . ' ' . $last ),
				'role'         => class_exists( 'WooCommerce' ) ? 'customer' : 'subscriber',
			)
		);

		if ( is_wp_error( $user_id ) ) {
			wp_send_json_error( array( 'msg' => __( 'The account did not open — try again.', 'oc-theme' ) ) );
		}

		update_user_meta( $user_id, 'oc_phone', $phone );
		update_user_meta( $user_id, 'billing_phone', 0 === strpos( $phone, '972' ) ? '0' . substr( $phone, 3 ) : $phone );
		update_user_meta( $user_id, 'billing_first_name', $first );
		update_user_meta( $user_id, 'billing_last_name', $last );
		update_user_meta( $user_id, 'billing_email', $email );
		update_user_meta( $user_id, 'oc_privacy_consent', (string) gmdate( 'c' ) );

		wp_set_auth_cookie( (int) $user_id, true );
		wp_send_json_success();
	}

	/*
	 * ------------------------------------------------------------- google
	 */

	/**
	 * Off to Google, state pinned in a cookie.
	 */
	public function google_start(): void {
		$s = self::settings();

		if ( empty( $s['google_on'] ) || '' === (string) $s['google_id'] ) {
			wp_safe_redirect( home_url( '/' ) );
			exit;
		}

		$state = wp_generate_password( 24, false );
		setcookie( 'oc_auth_state', $state, time() + 600, '/', '', is_ssl(), true );
		setcookie( 'oc_auth_back', esc_url_raw( (string) wp_get_referer() ), time() + 600, '/', '', is_ssl(), true );

		wp_redirect( add_query_arg( // phpcs:ignore WordPress.Security.SafeRedirect
			array(
				'client_id'     => rawurlencode( (string) $s['google_id'] ),
				'redirect_uri'  => rawurlencode( admin_url( 'admin-post.php?action=oc_auth_google_cb' ) ),
				'response_type' => 'code',
				'scope'         => rawurlencode( 'openid email profile' ),
				'state'         => $state,
				'prompt'        => 'select_account',
			),
			'https://accounts.google.com/o/oauth2/v2/auth'
		) );
		exit;
	}

	/**
	 * Back from Google: code for tokens, token for identity, identity for
	 * a session. An email already registered joins its own account.
	 */
	public function google_back(): void {
		$s     = self::settings();
		$state = (string) ( $_COOKIE['oc_auth_state'] ?? '' );
		$back  = (string) ( $_COOKIE['oc_auth_back'] ?? home_url( '/' ) );

		setcookie( 'oc_auth_state', '', time() - 100, '/', '', is_ssl(), true );

		if ( '' === $state || $state !== (string) ( $_GET['state'] ?? '' ) || empty( $_GET['code'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			wp_safe_redirect( $back );
			exit;
		}

		$tokens = wp_remote_post(
			'https://oauth2.googleapis.com/token',
			array(
				'timeout' => 15,
				'body'    => array(
					'code'          => sanitize_text_field( (string) wp_unslash( $_GET['code'] ) ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended
					'client_id'     => (string) $s['google_id'],
					'client_secret' => (string) $s['google_secret'],
					'redirect_uri'  => admin_url( 'admin-post.php?action=oc_auth_google_cb' ),
					'grant_type'    => 'authorization_code',
				),
			)
		);

		$body     = json_decode( (string) wp_remote_retrieve_body( $tokens ), true );
		$id_token = is_array( $body ) ? (string) ( $body['id_token'] ?? '' ) : '';

		if ( '' === $id_token ) {
			wp_safe_redirect( $back );
			exit;
		}

		// Google itself vouches for the token — one server-side question.
		$info    = wp_remote_get( 'https://oauth2.googleapis.com/tokeninfo?id_token=' . rawurlencode( $id_token ), array( 'timeout' => 15 ) );
		$claims  = json_decode( (string) wp_remote_retrieve_body( $info ), true );
		$claims  = is_array( $claims ) ? $claims : array();
		$email   = sanitize_email( (string) ( $claims['email'] ?? '' ) );
		$sub     = (string) ( $claims['sub'] ?? '' );
		$aud_ok  = ( $claims['aud'] ?? '' ) === (string) $s['google_id'];
		$mail_ok = 'true' === (string) ( $claims['email_verified'] ?? '' ) || true === ( $claims['email_verified'] ?? false );

		if ( ! $aud_ok || ! $mail_ok || '' === $sub || ! is_email( $email ) ) {
			wp_safe_redirect( $back );
			exit;
		}

		$found = get_users(
			array(
				'meta_key'   => 'oc_google_sub', // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value' => $sub, // phpcs:ignore WordPress.DB.SlowDBQuery
				'number'     => 1,
			)
		);
		$user  = ! empty( $found ) ? $found[0] : get_user_by( 'email', $email );

		if ( ! $user ) {
			$user_id = wp_insert_user(
				array(
					'user_login' => 'g' . substr( md5( $sub ), 0, 12 ),
					'user_pass'  => wp_generate_password( 24 ),
					'user_email' => $email,
					'first_name' => sanitize_text_field( (string) ( $claims['given_name'] ?? '' ) ),
					'last_name'  => sanitize_text_field( (string) ( $claims['family_name'] ?? '' ) ),
					'role'       => class_exists( 'WooCommerce' ) ? 'customer' : 'subscriber',
				)
			);

			if ( is_wp_error( $user_id ) ) {
				wp_safe_redirect( $back );
				exit;
			}

			$user = get_user_by( 'id', (int) $user_id );
		}

		update_user_meta( $user->ID, 'oc_google_sub', $sub );
		wp_set_auth_cookie( $user->ID, true );
		wp_safe_redirect( '' !== $back ? $back : home_url( '/' ) );
		exit;
	}

	/*
	 * ----------------------------------------------------- facebook, apple
	 */

	/**
	 * A signed-in social identity becomes a session: link by the provider's
	 * own id first, then by a verified email, and open an account otherwise.
	 *
	 * @param string $meta_key Provider id meta key.
	 * @param string $provider_id The provider's stable id.
	 * @param string $email    Email, may be ''.
	 * @param string $first    First name.
	 * @param string $last     Last name.
	 * @param string $prefix   Login prefix for a fresh account.
	 * @param string $back     Where to land.
	 */
	private function finish_social( string $meta_key, string $provider_id, string $email, string $first, string $last, string $prefix, string $back ): void {
		$found = get_users(
			array(
				'meta_key'   => $meta_key, // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value' => $provider_id, // phpcs:ignore WordPress.DB.SlowDBQuery
				'number'     => 1,
			)
		);
		$user  = ! empty( $found ) ? $found[0] : ( '' !== $email ? get_user_by( 'email', $email ) : false );

		if ( ! $user ) {
			$user_id = wp_insert_user(
				array(
					'user_login'   => $prefix . substr( md5( $meta_key . $provider_id ), 0, 12 ),
					'user_pass'    => wp_generate_password( 24 ),
					'user_email'   => $email,
					'first_name'   => $first,
					'last_name'    => $last,
					// Without this the greeting says the internal login hash.
					'display_name' => '' !== trim( $first . ' ' . $last ) ? trim( $first . ' ' . $last ) : __( 'Customer', 'oc-theme' ),
					'role'         => class_exists( 'WooCommerce' ) ? 'customer' : 'subscriber',
				)
			);

			if ( is_wp_error( $user_id ) ) {
				wp_safe_redirect( $back );
				exit;
			}

			$user = get_user_by( 'id', (int) $user_id );
		}

		update_user_meta( $user->ID, $meta_key, $provider_id );
		wp_set_auth_cookie( $user->ID, true );
		wp_safe_redirect( '' !== $back ? $back : home_url( '/' ) );
		exit;
	}

	/**
	 * Mint the state + return cookies for an outgoing social hop.
	 *
	 * @param bool $cross_post True when the answer arrives as a cross-site
	 *                         POST (Apple) — the cookie must say SameSite=None
	 *                         or the browser will keep it to itself.
	 * @return string The state.
	 */
	private function state_out( bool $cross_post = false ): string {
		$state = wp_generate_password( 24, false );
		$opts  = array(
			'expires'  => time() + 600,
			'path'     => '/',
			'secure'   => is_ssl(),
			'httponly' => true,
			'samesite' => $cross_post ? 'None' : 'Lax',
		);

		setcookie( 'oc_auth_state', $state, $opts );
		setcookie( 'oc_auth_back', esc_url_raw( (string) wp_get_referer() ), $opts );

		return $state;
	}

	/**
	 * Off to Facebook.
	 */
	public function fb_start(): void {
		$s = self::settings();

		if ( empty( $s['fb_on'] ) || '' === (string) $s['fb_id'] ) {
			wp_safe_redirect( home_url( '/' ) );
			exit;
		}

		wp_redirect( add_query_arg( // phpcs:ignore WordPress.Security.SafeRedirect
			array(
				'client_id'     => rawurlencode( (string) $s['fb_id'] ),
				'redirect_uri'  => rawurlencode( admin_url( 'admin-post.php?action=oc_auth_fb_cb' ) ),
				'response_type' => 'code',
				'scope'         => rawurlencode( 'email,public_profile' ),
				'state'         => $this->state_out(),
			),
			'https://www.facebook.com/v19.0/dialog/oauth'
		) );
		exit;
	}

	/**
	 * Back from Facebook: code for a token, token for a profile.
	 */
	public function fb_back(): void {
		$s     = self::settings();
		$state = (string) ( $_COOKIE['oc_auth_state'] ?? '' );
		$back  = (string) ( $_COOKIE['oc_auth_back'] ?? home_url( '/' ) );

		setcookie( 'oc_auth_state', '', time() - 100, '/', '', is_ssl(), true );

		if ( '' === $state || $state !== (string) ( $_GET['state'] ?? '' ) || empty( $_GET['code'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			wp_safe_redirect( $back );
			exit;
		}

		$token_res = wp_remote_get(
			add_query_arg(
				array(
					'client_id'     => rawurlencode( (string) $s['fb_id'] ),
					'client_secret' => rawurlencode( (string) $s['fb_secret'] ),
					'redirect_uri'  => rawurlencode( admin_url( 'admin-post.php?action=oc_auth_fb_cb' ) ),
					'code'          => rawurlencode( sanitize_text_field( (string) wp_unslash( $_GET['code'] ) ) ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				),
				'https://graph.facebook.com/v19.0/oauth/access_token'
			),
			array( 'timeout' => 15 )
		);

		$token = json_decode( (string) wp_remote_retrieve_body( $token_res ), true );
		$token = is_array( $token ) ? (string) ( $token['access_token'] ?? '' ) : '';

		if ( '' === $token ) {
			wp_safe_redirect( $back );
			exit;
		}

		$me_res = wp_remote_get(
			'https://graph.facebook.com/v19.0/me?fields=id,first_name,last_name,email&access_token=' . rawurlencode( $token ),
			array( 'timeout' => 15 )
		);

		$me = json_decode( (string) wp_remote_retrieve_body( $me_res ), true );
		$me = is_array( $me ) ? $me : array();
		$id = (string) ( $me['id'] ?? '' );

		if ( '' === $id ) {
			wp_safe_redirect( $back );
			exit;
		}

		// A Facebook account may carry no email at all (phone signups, or
		// permission declined) — the account still opens, anchored to the id.
		$this->finish_social(
			'oc_fb_id',
			$id,
			sanitize_email( (string) ( $me['email'] ?? '' ) ),
			sanitize_text_field( (string) ( $me['first_name'] ?? '' ) ),
			sanitize_text_field( (string) ( $me['last_name'] ?? '' ) ),
			'f',
			$back
		);
	}

	/**
	 * Off to Apple. Asking for name/email obliges response_mode=form_post,
	 * so the answer arrives as a cross-site POST — the state cookie says
	 * SameSite=None for exactly that reason.
	 */
	public function apple_start(): void {
		$s = self::settings();

		if ( empty( $s['apple_on'] ) || '' === (string) $s['apple_client_id'] ) {
			wp_safe_redirect( home_url( '/' ) );
			exit;
		}

		wp_redirect( add_query_arg( // phpcs:ignore WordPress.Security.SafeRedirect
			array(
				'client_id'     => rawurlencode( (string) $s['apple_client_id'] ),
				'redirect_uri'  => rawurlencode( admin_url( 'admin-post.php?action=oc_auth_apple_cb' ) ),
				'response_type' => 'code',
				'response_mode' => 'form_post',
				'scope'         => rawurlencode( 'name email' ),
				'state'         => $this->state_out( true ),
			),
			'https://appleid.apple.com/auth/authorize'
		) );
		exit;
	}

	/**
	 * Back from Apple: a POSTed code, exchanged with the signed JWT that
	 * stands in for a client secret; the id_token straight off Apple's own
	 * token endpoint carries the identity.
	 */
	public function apple_back(): void {
		$s     = self::settings();
		$state = (string) ( $_COOKIE['oc_auth_state'] ?? '' );
		$back  = (string) ( $_COOKIE['oc_auth_back'] ?? home_url( '/' ) );

		setcookie( 'oc_auth_state', '', time() - 100, '/', '', is_ssl(), true );

		if ( '' === $state || $state !== (string) ( $_POST['state'] ?? '' ) || empty( $_POST['code'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			self::apple_note( 'state: cookie=' . ( '' === $state ? 'missing' : 'present' ) . ' match=' . ( $state === (string) ( $_POST['state'] ?? '' ) ? 'yes' : 'no' ) . ' code=' . ( empty( $_POST['code'] ) ? 'missing' : 'present' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			wp_safe_redirect( $back );
			exit;
		}

		$secret = $this->apple_secret();

		if ( '' === $secret ) {
			self::apple_note( 'secret: could not sign (key unreadable?)' );
			wp_safe_redirect( $back );
			exit;
		}

		$token_res = wp_remote_post(
			'https://appleid.apple.com/auth/token',
			array(
				'timeout' => 15,
				'body'    => array(
					'client_id'     => (string) $s['apple_client_id'],
					'client_secret' => $secret,
					'code'          => sanitize_text_field( (string) wp_unslash( $_POST['code'] ) ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
					'grant_type'    => 'authorization_code',
					'redirect_uri'  => admin_url( 'admin-post.php?action=oc_auth_apple_cb' ),
				),
			)
		);

		$body     = json_decode( (string) wp_remote_retrieve_body( $token_res ), true );
		$id_token = is_array( $body ) ? (string) ( $body['id_token'] ?? '' ) : '';
		$parts    = explode( '.', $id_token );

		if ( 3 !== count( $parts ) ) {
			self::apple_note( 'token: ' . ( is_wp_error( $token_res ) ? $token_res->get_error_message() : wp_remote_retrieve_response_code( $token_res ) . ' ' . substr( (string) wp_remote_retrieve_body( $token_res ), 0, 300 ) ) );
			wp_safe_redirect( $back );
			exit;
		}

		// The token came to us directly from Apple over TLS in exchange for
		// our signed secret — parsing suffices, no JWKS trip needed.
		$claims = json_decode( (string) base64_decode( strtr( $parts[1], '-_', '+/' ) . str_repeat( '=', ( 4 - strlen( $parts[1] ) % 4 ) % 4 ) ), true );
		$claims = is_array( $claims ) ? $claims : array();
		$sub    = (string) ( $claims['sub'] ?? '' );

		if ( '' === $sub || ( $claims['aud'] ?? '' ) !== (string) $s['apple_client_id'] || 'https://appleid.apple.com' !== (string) ( $claims['iss'] ?? '' ) ) {
			self::apple_note( 'claims: sub=' . ( '' === $sub ? 'missing' : 'ok' ) . ' aud=' . (string) ( $claims['aud'] ?? '(none)' ) . ' iss=' . (string) ( $claims['iss'] ?? '(none)' ) );
			wp_safe_redirect( $back );
			exit;
		}

		self::apple_note( '' );

		// The name travels only on the very first authorisation, as a
		// POSTed JSON blob beside the code.
		$first = '';
		$last  = '';

		if ( ! empty( $_POST['user'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$blob = json_decode( (string) wp_unslash( $_POST['user'] ), true ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

			if ( is_array( $blob ) ) {
				$first = sanitize_text_field( (string) ( $blob['name']['firstName'] ?? '' ) );
				$last  = sanitize_text_field( (string) ( $blob['name']['lastName'] ?? '' ) );
			}
		}

		$this->finish_social(
			'oc_apple_sub',
			$sub,
			sanitize_email( (string) ( $claims['email'] ?? '' ) ),
			$first,
			$last,
			'a',
			$back
		);
	}

	/**
	 * A one-line breadcrumb about the last Apple attempt, for the doctor.
	 * Empty means the last attempt went through.
	 *
	 * @param string $why What went wrong.
	 */
	private static function apple_note( string $why ): void {
		update_option( 'ocau_apple_note', array(
			't' => (string) gmdate( 'c' ),
			'w' => $why,
		), false );
	}

	/**
	 * Apple's client secret is not a string off a dashboard — it is a JWT
	 * we sign ourselves with the downloaded .p8 key, ES256.
	 */
	private function apple_secret(): string {
		$s = self::settings();

		// People paste the .p8 in every shape — with the BEGIN/END lines,
		// without them, single-line, CRLF. Rebuild the canonical PEM from
		// the base64 body and every shape reads.
		$b64 = (string) preg_replace( '/-----[^-]+-----|\s+/', '', (string) $s['apple_key'] );

		if ( '' === $b64 ) {
			return '';
		}

		$key = openssl_pkey_get_private(
			"-----BEGIN PRIVATE KEY-----\n" . chunk_split( $b64, 64, "\n" ) . '-----END PRIVATE KEY-----'
		);

		if ( false === $key ) {
			return '';
		}

		$header  = self::b64url( (string) wp_json_encode( array(
			'alg' => 'ES256',
			'kid' => (string) $s['apple_key_id'],
		) ) );
		$payload = self::b64url( (string) wp_json_encode( array(
			'iss' => (string) $s['apple_team_id'],
			'iat' => time(),
			'exp' => time() + HOUR_IN_SECONDS,
			'aud' => 'https://appleid.apple.com',
			'sub' => (string) $s['apple_client_id'],
		) ) );

		$der = '';

		if ( ! openssl_sign( $header . '.' . $payload, $der, $key, OPENSSL_ALGO_SHA256 ) ) {
			return '';
		}

		return $header . '.' . $payload . '.' . self::b64url( self::der_to_raw( $der ) );
	}

	/**
	 * Base64url, the JWT dialect.
	 *
	 * @param string $bin Raw bytes.
	 */
	private static function b64url( string $bin ): string {
		return rtrim( strtr( base64_encode( $bin ), '+/', '-_' ), '=' );
	}

	/**
	 * openssl hands ES256 signatures back as DER; a JWT wants the two bare
	 * 32-byte integers side by side.
	 *
	 * @param string $der DER-encoded ECDSA signature.
	 */
	private static function der_to_raw( string $der ): string {
		$offset = 2;

		if ( ord( $der[1] ) > 0x80 ) {
			$offset += ord( $der[1] ) - 0x80;
		}

		$out = '';

		for ( $i = 0; $i < 2; $i++ ) {
			$offset++; // 0x02, the INTEGER tag.
			$len    = ord( $der[ $offset ] );
			$offset++;
			$int    = substr( $der, $offset, $len );
			$offset += $len;
			$int    = ltrim( $int, "\x00" );
			$out   .= str_pad( $int, 32, "\x00", STR_PAD_LEFT );
		}

		return $out;
	}

	/*
	 * ----------------------------------------------------- account deletion
	 */

	/**
	 * The way out, at the bottom of the account-details page: a red-lined
	 * block, a warning dialog, and a goodbye. Meta's data-deletion
	 * requirement points here.
	 */
	public function delete_block(): void {
		if ( ! is_user_logged_in() ) {
			return;
		}

		// A shop manager does not get to vaporise themselves by accident.
		if ( current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<section class="oc-delacc">
			<h3><?php esc_html_e( 'Delete account', 'oc-theme' ); ?></h3>
			<p><?php esc_html_e( 'Deleting the account permanently erases your details from the site. Orders and invoices are kept as the law requires, detached from you.', 'oc-theme' ); ?></p>
			<button type="button" class="oc-delacc__open"><?php esc_html_e( 'Delete my account', 'oc-theme' ); ?></button>

			<div class="oc-delacc__dim" hidden>
				<div class="oc-delacc__box" role="alertdialog" aria-modal="true" aria-label="<?php esc_attr_e( 'Delete account', 'oc-theme' ); ?>">
					<h4><?php esc_html_e( 'Are you sure?', 'oc-theme' ); ?></h4>
					<p><?php esc_html_e( 'The account and all your personal details will be deleted for good — there is no way back. Orders and invoices are kept as the law requires.', 'oc-theme' ); ?></p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'oc_delete_account' ); ?>
						<input type="hidden" name="action" value="oc_delete_account">
						<div class="oc-delacc__row">
							<button type="button" class="oc-delacc__cancel" data-delacc-close><?php esc_html_e( 'Keep my account', 'oc-theme' ); ?></button>
							<button type="submit" class="oc-delacc__yes"><?php esc_html_e( 'Delete for good', 'oc-theme' ); ?></button>
						</div>
					</form>
				</div>
			</div>
		</section>
		<?php
	}

	/**
	 * The deletion itself: orders stay for the books but forget whose they
	 * were; the user row and every personal detail go; the session ends on
	 * the front page.
	 */
	public function delete_account(): void {
		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( home_url( '/' ) );
			exit;
		}

		check_admin_referer( 'oc_delete_account' );

		$user_id = get_current_user_id();

		if ( current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' ) ) {
			wp_safe_redirect( home_url( '/' ) );
			exit;
		}

		// Orders keep their books-required details, detached from the user.
		if ( function_exists( 'wc_get_orders' ) ) {
			$orders = wc_get_orders(
				array(
					'customer_id' => $user_id,
					'limit'       => -1,
				)
			);

			foreach ( $orders as $order ) {
				$order->set_customer_id( 0 );
				$order->add_order_note( __( 'The customer account was deleted at the customer\'s request; the order is kept as the law requires.', 'oc-theme' ) );
				$order->save();
			}
		}

		wp_logout();

		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $user_id );

		wp_safe_redirect( home_url( '/' ) );
		exit;
	}

	/*
	 * -------------------------------------------------------------- panel
	 */

	/**
	 * Attributes the header account icon carries so a click opens the
	 * drawer instead of leaving for the account page.
	 *
	 * @param string $attrs Current extra attributes.
	 */
	public function icon_attrs( $attrs ) {
		if ( ! is_user_logged_in() ) {
			$attrs .= ' data-oc-auth="1"';
		} else {
			$attrs .= ' data-oc-accmenu="1"';
		}

		return $attrs;
	}

	/**
	 * A greeting whose name is an internal login is no greeting. Accounts
	 * created before display names were set heal themselves on sight.
	 */
	public function heal_display_name(): void {
		if ( ! is_user_logged_in() ) {
			return;
		}

		$user = wp_get_current_user();

		if ( $user->display_name === $user->user_login && '' !== trim( (string) $user->first_name ) ) {
			wp_update_user(
				array(
					'ID'           => $user->ID,
					'display_name' => trim( $user->first_name . ' ' . $user->last_name ),
				)
			);
		}
	}

	/**
	 * The signed-in account icon opens a small menu: every account section
	 * one click away, the dashboard skipped — there is nothing there.
	 */
	public function account_menu(): void {
		if ( ! is_user_logged_in() || ! function_exists( 'wc_get_account_menu_items' ) ) {
			return;
		}

		$items = wc_get_account_menu_items();
		unset( $items['dashboard'] );

		if ( empty( $items ) ) {
			return;
		}

		$user = wp_get_current_user();
		$name = '' !== trim( (string) $user->first_name ) ? trim( (string) $user->first_name ) : (string) $user->display_name;
		?>
		<nav class="oc-accmenu" hidden aria-label="<?php esc_attr_e( 'My account', 'oc-theme' ); ?>">
			<p class="oc-accmenu__hi"><?php echo esc_html( sprintf( /* translators: %s: first name. */ __( 'Hello, %s', 'oc-theme' ), $name ) ); ?></p>
			<?php foreach ( $items as $endpoint => $label ) : ?>
				<?php
				$url = wc_get_account_endpoint_url( $endpoint );

				if ( 'customer-logout' === $endpoint ) {
					$url = wp_nonce_url( $url, 'customer-logout' );
				}
				?>
				<a class="oc-accmenu__item<?php echo 'customer-logout' === $endpoint ? ' oc-accmenu__item--out' : ''; ?>" href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $label ); ?></a>
			<?php endforeach; ?>
		</nav>
		<?php
	}

	/**
	 * The drawer itself, printed once per page for signed-out visitors.
	 */
	public function panel(): void {
		if ( is_user_logged_in() ) {
			return;
		}

		$s     = self::settings();
		$side  = get_theme_mod( 'oc_login_side', 'right' );
		$width = absint( get_theme_mod( 'oc_login_width', 480 ) );
		$title = trim( (string) get_theme_mod( 'oc_login_title', '' ) );
		$title = '' !== $title ? $title : __( 'Phone number and off we go :)', 'oc-theme' );
		$club = trim( (string) get_theme_mod( 'oc_login_club_text', '' ) );

		if ( '' === $club ) {
			$club = __( 'Join the club and earn 5% of your order back in points, to spend on your next purchases.', 'oc-theme' );
		}
		$align = get_theme_mod( 'oc_login_align', 'center' );
		$shape = get_theme_mod( 'oc_login_btn_shape', 'inherit' );

		$privacy_id  = (int) get_option( 'wp_page_for_privacy_policy' );
		$privacy_url = $privacy_id > 0 ? (string) get_permalink( $privacy_id ) : '';
		/* translators: the linked words inside the consent sentence. */
		$needle  = __( 'privacy policy', 'oc-theme' );
		$consent = __( 'I have read and accept the privacy policy', 'oc-theme' );

		if ( '' !== $privacy_url ) {
			$consent = str_replace( $needle, '<a href="' . esc_url( $privacy_url ) . '" target="_blank" rel="noopener">' . esc_html( $needle ) . '</a>', esc_html( $consent ) );
		} else {
			$consent = esc_html( $consent );
		}
		?>
		<div class="oc-auth oc-auth--<?php echo esc_attr( 'left' === $side ? 'left' : 'right' ); ?> oc-auth--a-<?php echo esc_attr( 'center' === $align ? 'c' : 's' ); ?><?php echo 'inherit' === $shape ? '' : ' oc-auth--b-' . esc_attr( $shape ); ?>"
			hidden
			data-nonce="<?php echo esc_attr( wp_create_nonce( 'oc_auth' ) ); ?>"
			style="--oc-auth-w:<?php echo esc_attr( (string) max( 320, $width ) ); ?>px">
			<div class="oc-auth__dim" data-auth-close></div>
			<aside class="oc-auth__panel" role="dialog" aria-modal="true" aria-label="<?php esc_attr_e( 'Sign in', 'oc-theme' ); ?>">
				<header class="oc-auth__head">
					<h2 class="oc-auth__name"><?php esc_html_e( 'Sign in', 'oc-theme' ); ?></h2>
					<button type="button" class="oc-auth__close" data-auth-close aria-label="<?php esc_attr_e( 'Close', 'oc-theme' ); ?>">&times;</button>
				</header>

				<div class="oc-auth__step" data-step="phone">
					<h3 class="oc-auth__title"><?php echo esc_html( $title ); ?></h3>
					<?php if ( ! empty( $s['sms_on'] ) ) : ?>
						<form class="oc-auth__form" data-auth-form="start" novalidate>
							<input class="oc-auth__tel" type="tel" name="phone" inputmode="tel" autocomplete="tel" placeholder="<?php esc_attr_e( 'Enter phone number', 'oc-theme' ); ?>" required>
							<p class="oc-auth__err" hidden></p>
							<button type="submit" class="oc-auth__cta"><?php esc_html_e( 'Send code', 'oc-theme' ); ?></button>
						</form>
					<?php endif; ?>
					<?php if ( ! empty( $s['google_on'] ) || ! empty( $s['fb_on'] ) || ! empty( $s['apple_on'] ) || ! empty( $s['email_on'] ) ) : ?>
						<div class="oc-auth__social">
							<?php if ( ! empty( $s['sms_on'] ) ) : ?>
								<p class="oc-auth__also"><?php esc_html_e( 'You can also sign in with…', 'oc-theme' ); ?></p>
							<?php endif; ?>
							<?php if ( ! empty( $s['google_on'] ) ) : ?>
							<a class="oc-auth__provider" href="<?php echo esc_url( admin_url( 'admin-post.php?action=oc_auth_google' ) ); ?>" rel="nofollow">
								<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path fill="#4285F4" d="M23.5 12.3c0-.9-.1-1.5-.2-2.2H12v4.4h6.5c-.1 1.1-.8 2.7-2.4 3.8l3.7 2.8c2.2-2 3.7-5 3.7-8.8z"/><path fill="#34A853" d="M12 24c3.2 0 5.9-1 7.9-2.9l-3.7-2.8c-1 .7-2.4 1.2-4.2 1.2-3.2 0-5.9-2.1-6.9-5L1.3 17.4C3.3 21.4 7.3 24 12 24z"/><path fill="#FBBC05" d="M5.1 14.5c-.2-.7-.4-1.4-.4-2.2s.1-1.5.4-2.2L1.3 7.2C.5 8.7 0 10.3 0 12.3s.5 3.6 1.3 5.1l3.8-2.9z"/><path fill="#EA4335" d="M12 4.8c2.3 0 3.8 1 4.7 1.8l3.4-3.3C18 1.3 15.2 0 12 0 7.3 0 3.3 2.6 1.3 6.6l3.8 2.9c1-2.9 3.7-4.7 6.9-4.7z"/></svg>
								<?php esc_html_e( 'Sign in with Google', 'oc-theme' ); ?>
							</a>
							<?php endif; ?>
							<?php if ( ! empty( $s['fb_on'] ) ) : ?>
							<a class="oc-auth__provider" href="<?php echo esc_url( admin_url( 'admin-post.php?action=oc_auth_fb' ) ); ?>" rel="nofollow">
								<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path fill="#1877F2" d="M24 12a12 12 0 1 0-13.9 11.9v-8.4H7.1V12h3.1V9.4c0-3 1.8-4.7 4.5-4.7 1.3 0 2.7.2 2.7.2v3h-1.5c-1.5 0-2 .9-2 1.9V12h3.4l-.5 3.5h-2.9v8.4A12 12 0 0 0 24 12z"/></svg>
								<?php esc_html_e( 'Sign in with Facebook', 'oc-theme' ); ?>
							</a>
							<?php endif; ?>
							<?php if ( ! empty( $s['apple_on'] ) ) : ?>
							<a class="oc-auth__provider" href="<?php echo esc_url( admin_url( 'admin-post.php?action=oc_auth_apple' ) ); ?>" rel="nofollow">
								<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path fill="currentColor" d="M16.7 12.9c0-2.4 2-3.6 2.1-3.6-1.1-1.7-2.9-1.9-3.5-1.9-1.5-.2-2.9.9-3.7.9-.8 0-1.9-.9-3.2-.9-1.6 0-3.1 1-4 2.4-1.7 2.9-.4 7.3 1.2 9.7.8 1.2 1.8 2.5 3 2.4 1.2 0 1.7-.8 3.1-.8 1.5 0 1.9.8 3.2.8 1.3 0 2.1-1.2 2.9-2.4.9-1.4 1.3-2.7 1.3-2.8-.1 0-2.4-1-2.4-3.8zM14.4 5.6c.6-.8 1.1-1.9 1-3-1 0-2.1.6-2.8 1.5-.6.7-1.2 1.9-1 3 1 .1 2.1-.6 2.8-1.5z"/></svg>
								<?php esc_html_e( 'Sign in with Apple', 'oc-theme' ); ?>
							</a>
							<?php endif; ?>
							<?php if ( ! empty( $s['email_on'] ) ) : ?>
							<button type="button" class="oc-auth__provider" data-auth-goto="email">
								<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/></svg>
								<?php esc_html_e( 'Sign in with email and password', 'oc-theme' ); ?>
							</button>
							<?php endif; ?>
						</div>
					<?php endif; ?>
				</div>

				<?php if ( ! empty( $s['email_on'] ) ) : ?>
				<div class="oc-auth__step" data-step="email" hidden>
					<h3 class="oc-auth__title"><?php esc_html_e( 'Sign in with email and password', 'oc-theme' ); ?></h3>
					<form class="oc-auth__form" data-auth-form="login" novalidate>
						<input type="email" name="email" autocomplete="email" placeholder="<?php esc_attr_e( 'Email', 'oc-theme' ); ?>" required>
						<input type="password" name="password" autocomplete="current-password" placeholder="<?php esc_attr_e( 'Password', 'oc-theme' ); ?>" required>
						<p class="oc-auth__err" hidden></p>
						<button type="submit" class="oc-auth__cta"><?php esc_html_e( 'Sign in', 'oc-theme' ); ?></button>
						<p class="oc-auth__resend">
							<a class="oc-auth__link" href="<?php echo esc_url( function_exists( 'wc_lostpassword_url' ) ? wc_lostpassword_url() : wp_lostpassword_url() ); ?>"><?php esc_html_e( 'Forgot the password?', 'oc-theme' ); ?></a>
							<button type="button" class="oc-auth__link" data-auth-change><?php esc_html_e( 'Back', 'oc-theme' ); ?></button>
						</p>
					</form>
				</div>
				<?php endif; ?>

				<div class="oc-auth__step" data-step="code" hidden>
					<h3 class="oc-auth__title"><?php esc_html_e( 'Enter the code from the SMS', 'oc-theme' ); ?></h3>
					<p class="oc-auth__sub">
						<?php esc_html_e( 'Sent to', 'oc-theme' ); ?> <span data-auth-pretty></span>
						<button type="button" class="oc-auth__link" data-auth-change><?php esc_html_e( 'Change number', 'oc-theme' ); ?></button>
					</p>
					<form class="oc-auth__form" data-auth-form="verify" novalidate>
						<div class="oc-auth__boxes" dir="ltr">
							<?php for ( $i = 0; $i < 6; $i++ ) : ?>
								<input type="text" inputmode="numeric" maxlength="1" autocomplete="<?php echo 0 === $i ? 'one-time-code' : 'off'; ?>" aria-label="<?php echo esc_attr( (string) ( $i + 1 ) ); ?>">
							<?php endfor; ?>
						</div>
						<p class="oc-auth__err" hidden></p>
						<p class="oc-auth__resend">
							<button type="button" class="oc-auth__link" data-auth-resend hidden><?php esc_html_e( 'Send it again', 'oc-theme' ); ?></button>
							<span data-auth-timer></span>
							<button type="button" class="oc-auth__link" data-auth-email hidden><?php esc_html_e( 'Send the code by email', 'oc-theme' ); ?></button>
						</p>
						<p class="oc-auth__hint"><?php esc_html_e( 'On Android devices, worth a glance at the spam folder.', 'oc-theme' ); ?></p>
					</form>
				</div>

				<div class="oc-auth__step" data-step="register" hidden>
					<h3 class="oc-auth__title"><?php esc_html_e( 'Opening an account', 'oc-theme' ); ?></h3>
					<p class="oc-auth__sub"><?php esc_html_e( 'We do not know this number yet — fill in your details once, and next time the phone alone gets you in.', 'oc-theme' ); ?></p>
					<?php if ( '' !== $club ) : ?>
						<p class="oc-auth__club"><?php echo esc_html( $club ); ?></p>
					<?php endif; ?>
					<form class="oc-auth__form" data-auth-form="register" novalidate>
						<div class="oc-auth__phone-row">
							<input class="oc-auth__tel" type="tel" name="phone_show" readonly>
							<button type="button" class="oc-auth__link" data-auth-change><?php esc_html_e( 'Change', 'oc-theme' ); ?></button>
						</div>
						<div class="oc-auth__pair">
							<input type="text" name="first" autocomplete="given-name" placeholder="<?php esc_attr_e( 'First name', 'oc-theme' ); ?>" required>
							<input type="text" name="last" autocomplete="family-name" placeholder="<?php esc_attr_e( 'Last name', 'oc-theme' ); ?>">
						</div>
						<input type="email" name="email" autocomplete="email" placeholder="<?php esc_attr_e( 'Email', 'oc-theme' ); ?>" required>
						<input type="text" name="website" class="oc-auth__hp" tabindex="-1" autocomplete="off" aria-hidden="true">
						<label class="oc-auth__consent">
							<input type="checkbox" name="consent" required>
							<span><?php echo $consent; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?></span>
						</label>
						<p class="oc-auth__err" hidden></p>
						<button type="submit" class="oc-auth__cta"><?php esc_html_e( 'Create account', 'oc-theme' ); ?></button>
					</form>
				</div>
				<footer class="oc-auth__signup" data-auth-signup>
					<h4><?php esc_html_e( 'Not registered yet?', 'oc-theme' ); ?></h4>
					<p><?php echo esc_html( $club ); ?></p>
					<button type="button" class="oc-auth__signup-btn" data-auth-goto="register"><?php esc_html_e( 'Sign up', 'oc-theme' ); ?></button>
				</footer>
			</aside>
		</div>
		<?php
	}
}
