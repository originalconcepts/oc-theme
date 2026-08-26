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
		$on = self::settings();

		if ( empty( $on['sms_on'] ) && empty( $on['google_on'] ) ) {
			return; // Nothing enabled — the account icon behaves as always.
		}

		add_action( 'wp_footer', array( $this, 'panel' ) );
		add_filter( 'oc_header_account_attrs', array( $this, 'icon_attrs' ) );

		foreach ( array( 'oc_auth_start', 'oc_auth_verify', 'oc_auth_register', 'oc_auth_email_code' ) as $action ) {
			add_action( 'wp_ajax_nopriv_' . $action, array( $this, substr( $action, 3 ) ) );
			add_action( 'wp_ajax_' . $action, array( $this, substr( $action, 3 ) ) );
		}

		add_action( 'admin_post_nopriv_oc_auth_google', array( $this, 'google_start' ) );
		add_action( 'admin_post_oc_auth_google', array( $this, 'google_start' ) );
		add_action( 'admin_post_nopriv_oc_auth_google_cb', array( $this, 'google_back' ) );
		add_action( 'admin_post_oc_auth_google_cb', array( $this, 'google_back' ) );
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
				'google_on'       => 0,
				'google_id'       => '',
				'google_secret'   => '',
				'fb_on'           => 0,
				'fb_id'           => '',
				'fb_secret'       => '',
				'apple_on'        => 0,
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
				'user_login' => $login,
				'user_pass'  => wp_generate_password( 24 ),
				'user_email' => $email,
				'first_name' => $first,
				'last_name'  => $last,
				'role'       => class_exists( 'WooCommerce' ) ? 'customer' : 'subscriber',
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
		}

		return $attrs;
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
		$club  = trim( (string) get_theme_mod( 'oc_login_club_text', '' ) );
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
					<?php if ( ! empty( $s['google_on'] ) ) : ?>
						<div class="oc-auth__social">
							<?php if ( ! empty( $s['sms_on'] ) ) : ?>
								<p class="oc-auth__also"><?php esc_html_e( 'You can also sign in with…', 'oc-theme' ); ?></p>
							<?php endif; ?>
							<a class="oc-auth__provider" href="<?php echo esc_url( admin_url( 'admin-post.php?action=oc_auth_google' ) ); ?>" rel="nofollow">
								<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path fill="#4285F4" d="M23.5 12.3c0-.9-.1-1.5-.2-2.2H12v4.4h6.5c-.1 1.1-.8 2.7-2.4 3.8l3.7 2.8c2.2-2 3.7-5 3.7-8.8z"/><path fill="#34A853" d="M12 24c3.2 0 5.9-1 7.9-2.9l-3.7-2.8c-1 .7-2.4 1.2-4.2 1.2-3.2 0-5.9-2.1-6.9-5L1.3 17.4C3.3 21.4 7.3 24 12 24z"/><path fill="#FBBC05" d="M5.1 14.5c-.2-.7-.4-1.4-.4-2.2s.1-1.5.4-2.2L1.3 7.2C.5 8.7 0 10.3 0 12.3s.5 3.6 1.3 5.1l3.8-2.9z"/><path fill="#EA4335" d="M12 4.8c2.3 0 3.8 1 4.7 1.8l3.4-3.3C18 1.3 15.2 0 12 0 7.3 0 3.3 2.6 1.3 6.6l3.8 2.9c1-2.9 3.7-4.7 6.9-4.7z"/></svg>
								<?php esc_html_e( 'Sign in with Google', 'oc-theme' ); ?>
							</a>
						</div>
					<?php endif; ?>
				</div>

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
						<button type="submit" class="oc-auth__cta"><?php esc_html_e( 'Sign in', 'oc-theme' ); ?></button>
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
			</aside>
		</div>
		<?php
	}
}
