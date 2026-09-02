<?php
/**
 * The sign-in door, dressed.
 *
 * WordPress's own login screen keeps doing the work — the cookie test, the
 * brute-force delay, the auth cookies, the password reset, the interim
 * login, and this theme's own two-step code, which draws itself through the
 * same login_header(). Rebuilding that flow to change how it looks would be
 * trading a hardened path for a pretty one. So nothing here authenticates:
 * it restyles the screen and adds the two doors the shop already owns —
 * a one-time code to the phone, and Google — both of which land on the
 * endpoints Auth already guards.
 *
 * @package OC_Theme
 */

declare( strict_types = 1 );

namespace OC\Theme;

defined( 'ABSPATH' ) || exit;

/**
 * A modern sign-in screen over WordPress's own.
 */
final class Login_Screen {

	/**
	 * Hook in.
	 */
	public function register(): void {
		add_action( 'login_enqueue_scripts', array( $this, 'styles' ) );
		add_filter( 'login_headerurl', array( $this, 'logo_url' ) );
		add_filter( 'login_headertext', array( $this, 'logo_text' ) );
		add_filter( 'login_body_class', array( $this, 'body_class' ) );
		add_action( 'login_form', array( $this, 'alternatives' ) );
		add_action( 'login_footer', array( $this, 'credit' ) );
	}

	/**
	 * The shop's own logo, or ours when the shop has none.
	 *
	 * @return array{url:string,mine:bool} Image URL, and whether it is the
	 *                                     client's own rather than our mark.
	 */
	private static function logo(): array {
		$id = (int) get_theme_mod( 'custom_logo' );

		if ( $id > 0 ) {
			$url = wp_get_attachment_image_url( $id, 'medium' );

			if ( is_string( $url ) && '' !== $url ) {
				return array(
					'url'  => $url,
					'mine' => true,
				);
			}
		}

		return array(
			'url'  => OC_THEME_URI . '/assets/img/oc-credit.svg',
			'mine' => false,
		);
	}

	/**
	 * The logo links home, not to wordpress.org.
	 *
	 * @param string $url Default.
	 */
	public function logo_url( $url ): string {
		unset( $url );

		return home_url( '/' );
	}

	/**
	 * And it is announced by the shop's name.
	 *
	 * @param string $text Default.
	 */
	public function logo_text( $text ): string {
		unset( $text );

		return (string) get_bloginfo( 'name' );
	}

	/**
	 * A hook for the stylesheet, and one for the RTL admin.
	 *
	 * @param array<int,string> $classes Body classes.
	 * @return array<int,string>
	 */
	public function body_class( $classes ): array {
		$classes[] = 'oc-login';

		return (array) $classes;
	}

	/**
	 * The whole look, inline: the login screen loads no theme stylesheet,
	 * and a separate request for two kilobytes of CSS would cost more than
	 * it weighs.
	 */
	public function styles(): void {
		$logo    = self::logo();
		$primary = (string) get_theme_mod( 'oc_color_primary', '' );
		$primary = '' !== $primary ? $primary : '#111827';
		$radius  = absint( get_theme_mod( 'oc_cta_r', 10 ) );

		?>
		<style id="oc-login-css">
		:root {
			--ocl-primary: <?php echo esc_html( $primary ); ?>;
			--ocl-radius: <?php echo absint( $radius ); ?>px;
			--ocl-ink: #10131a;
			--ocl-ink-2: #6b7280;
			--ocl-line: #e5e7eb;
			--ocl-field: #f8fafc;
		}

		body.login {
			background: radial-gradient(1200px 600px at 50% -10%, #eef1f7 0%, #f7f8fa 45%, #f7f8fa 100%);
			color: var(--ocl-ink);
			font-family: "Assistant", system-ui, -apple-system, "Segoe UI", sans-serif;
			min-block-size: 100dvh;
			display: flex;
			flex-direction: column;
			justify-content: center;
			align-items: center;
			padding-block: 32px;
		}

		#login {
			inline-size: min(408px, calc(100vw - 32px));
			padding: 0;
		}

		/* ---------- the mark above the card ---------- */

		/* Recent WordPress paints its own mark on the h1 itself, not on the
		 * link inside it — dressing only the link leaves theirs showing
		 * through underneath. Both carry the shop's. */
		.login h1,
		.login h1.wp-login-logo { margin: 0 0 22px; padding: 0; background: none; }

		.login h1 a,
		.login h1.wp-login-logo a {
			display: block;
			inline-size: 100%;
			block-size: <?php echo $logo['mine'] ? '62px' : '74px'; ?>;
			margin: 0;
			padding: 0;
			background-image: url("<?php echo esc_url( $logo['url'] ); ?>");
			background-size: contain;
			background-position: center;
			background-repeat: no-repeat;
			text-indent: -9999em;
			overflow: hidden;
		}

		/* ---------- the card ---------- */

		#loginform,
		#lostpasswordform,
		#registerform,
		.login form {
			display: flex;
			flex-direction: column;
			gap: 0;
			margin: 0;
			padding: 30px 28px 26px;
			border: 1px solid var(--ocl-line);
			border-radius: 18px;
			background: #fff;
			box-shadow: 0 1px 2px rgb(16 24 40 / 5%), 0 20px 44px -26px rgb(16 24 40 / 26%);
		}

		.login form .forgetmenot { order: 8; margin-block: 4px 0; float: none; }
		.login form .submit { order: 9; margin: 0; }
		.login .oc-alt { order: 10; }

		.login form p { margin-block: 0 14px; }
		.login form label { font-size: 13.5px; font-weight: 600; color: var(--ocl-ink); }

		.login form .input,
		.login input[type="text"],
		.login input[type="password"],
		.login input[type="email"],
		.login input[type="tel"] {
			inline-size: 100%;
			margin: 6px 0 0;
			padding: 11px 13px;
			border: 1px solid var(--ocl-line);
			border-radius: var(--ocl-radius);
			background: var(--ocl-field);
			font-size: 15px;
			line-height: 1.4;
			color: var(--ocl-ink);
			box-shadow: none;
			transition: border-color .14s ease, box-shadow .14s ease, background .14s ease;
		}

		.login form .input:focus,
		.login input[type="text"]:focus,
		.login input[type="password"]:focus,
		.login input[type="email"]:focus,
		.login input[type="tel"]:focus {
			border-color: var(--ocl-primary);
			background: #fff;
			outline: none;
			box-shadow: 0 0 0 3px color-mix(in srgb, var(--ocl-primary) 16%, transparent);
		}

		/* The password field's eye keeps its place inside the new box. */
		.login .wp-pwd { position: relative; }
		.login .wp-pwd .button.wp-hide-pw {
			position: absolute;
			inset-block-start: 50%;
			inset-inline-end: 4px;
			translate: 0 -8%;
			background: none;
			border: 0;
			color: var(--ocl-ink-2);
		}
		.login .wp-pwd input[type="password"],
		.login .wp-pwd input[type="text"] { padding-inline-end: 42px; }

		.login .forgetmenot label { font-weight: 400; font-size: 13.5px; color: var(--ocl-ink-2); }

		.login .button-primary,
		.login #wp-submit {
			inline-size: 100%;
			block-size: auto;
			margin: 0;
			padding: 12px 18px;
			border: 0;
			border-radius: var(--ocl-radius);
			background: var(--ocl-primary);
			color: #fff;
			font-size: 15px;
			font-weight: 700;
			line-height: 1.35;
			text-shadow: none;
			box-shadow: none;
			cursor: pointer;
			transition: filter .14s ease;
		}
		.login .button-primary:hover,
		.login #wp-submit:hover { filter: brightness(1.12); }
		.login .button-primary:focus-visible { outline: 2px solid var(--ocl-primary); outline-offset: 2px; }

		/* ---------- the other doors ---------- */

		.oc-alt { margin-block-start: 18px; }

		.oc-alt__or {
			display: flex;
			align-items: center;
			gap: 12px;
			margin-block-end: 14px;
			color: var(--ocl-ink-2);
			font-size: 12.5px;
		}
		.oc-alt__or::before,
		.oc-alt__or::after {
			content: "";
			flex: 1;
			block-size: 1px;
			background: var(--ocl-line);
		}

		.oc-alt__btn {
			display: flex;
			align-items: center;
			justify-content: center;
			gap: 9px;
			inline-size: 100%;
			margin-block-end: 9px;
			padding: 11px 14px;
			border: 1px solid var(--ocl-line);
			border-radius: var(--ocl-radius);
			background: #fff;
			color: var(--ocl-ink);
			font: inherit;
			font-size: 14.5px;
			font-weight: 600;
			text-decoration: none;
			cursor: pointer;
			transition: border-color .14s ease, background .14s ease;
		}
		.oc-alt__btn:hover { border-color: #c7cdd8; background: #fcfcfd; color: var(--ocl-ink); }
		.oc-alt__btn svg { flex: none; }

		/* ---------- the code panel ---------- */

		.oc-otp { display: none; margin-block-start: 4px; }
		.oc-otp.is-open { display: block; }
		.oc-otp__msg { margin: 0 0 10px; font-size: 13px; color: var(--ocl-ink-2); min-block-size: 18px; }
		.oc-otp__msg.is-bad { color: #b32d2e; }
		.oc-otp input { text-align: start; }
		.oc-otp input[name="oc_otp_code"] { letter-spacing: .35em; font-size: 18px; text-align: center; }
		.oc-otp .oc-alt__btn { background: var(--ocl-primary); border-color: var(--ocl-primary); color: #fff; }
		.oc-otp .oc-alt__btn:hover { background: var(--ocl-primary); filter: brightness(1.12); color: #fff; }
		.oc-otp__back {
			display: inline-block;
			margin-block-start: 2px;
			border: 0;
			background: none;
			padding: 0;
			color: var(--ocl-ink-2);
			font: inherit;
			font-size: 13px;
			cursor: pointer;
			text-decoration: underline;
		}

		/* ---------- the rest of WordPress's furniture ---------- */

		.login #nav,
		.login #backtoblog {
			padding: 0;
			margin-block-start: 14px;
			text-align: center;
			font-size: 13.5px;
		}
		.login #nav a,
		.login #backtoblog a { color: var(--ocl-ink-2); text-decoration: none; }
		.login #nav a:hover,
		.login #backtoblog a:hover { color: var(--ocl-primary); }

		.login .message,
		.login .notice,
		.login #login_error {
			margin-block-end: 16px;
			padding: 12px 14px;
			border: 1px solid var(--ocl-line);
			border-inline-start-width: 3px;
			border-radius: 12px;
			background: #fff;
			box-shadow: none;
			font-size: 13.5px;
		}
		.login #login_error { border-inline-start-color: #b32d2e; }
		.login .message { border-inline-start-color: var(--ocl-primary); }

		.login .privacy-policy-page-link { margin-block-start: 18px; }

		/* Our mark, small, when the shop already showed its own. */
		.oc-login__by {
			display: flex;
			align-items: center;
			justify-content: center;
			gap: 7px;
			margin-block-start: 22px;
			color: var(--ocl-ink-2);
			font-size: 12px;
			text-decoration: none;
		}
		.oc-login__by img { block-size: 26px; inline-size: auto; opacity: .78; }
		.oc-login__by:hover { color: var(--ocl-ink); }
		.oc-login__by:hover img { opacity: 1; }

		@media (max-width: 480px) {
			#loginform,
			.login form { padding: 24px 20px 22px; }
		}
		</style>
		<?php
	}

	/**
	 * The doors beside the password: Google when it is configured, and a
	 * one-time code when the shop can send SMS. Each is only drawn when the
	 * thing behind it actually works, so the screen never offers a way in
	 * that cannot open.
	 */
	public function alternatives(): void {
		if ( ! $this->on_sign_in_form() ) {
			return;
		}

		$s      = Auth::settings();
		$google = ! empty( $s['google_on'] ) && '' !== (string) $s['google_id'];
		$otp    = ! empty( $s['sms_on'] );

		if ( ! $google && ! $otp ) {
			return;
		}

		?>
		<div class="oc-alt">
			<div class="oc-alt__or"><?php esc_html_e( 'or', 'oc-theme' ); ?></div>

			<?php if ( $google ) : ?>
				<a class="oc-alt__btn" href="<?php echo esc_url( admin_url( 'admin-post.php?action=oc_auth_google&to=admin' ) ); ?>">
					<svg width="17" height="17" viewBox="0 0 18 18" aria-hidden="true"><path fill="#4285F4" d="M17.6 9.2c0-.6-.1-1.3-.2-1.9H9v3.5h4.8a4.1 4.1 0 0 1-1.8 2.7v2.2h2.9c1.7-1.6 2.7-3.9 2.7-6.5z"/><path fill="#34A853" d="M9 18c2.4 0 4.5-.8 6-2.2l-2.9-2.3c-.8.6-1.9.9-3.1.9-2.4 0-4.4-1.6-5.1-3.8H.9v2.3A9 9 0 0 0 9 18z"/><path fill="#FBBC05" d="M3.9 10.7a5.4 5.4 0 0 1 0-3.4V5H.9a9 9 0 0 0 0 8l3-2.3z"/><path fill="#EA4335" d="M9 3.6c1.3 0 2.5.5 3.4 1.3l2.6-2.6A9 9 0 0 0 .9 5l3 2.3C4.6 5.2 6.6 3.6 9 3.6z"/></svg>
					<?php esc_html_e( 'Continue with Google', 'oc-theme' ); ?>
				</a>
			<?php endif; ?>

			<?php if ( $otp ) : ?>
				<button type="button" class="oc-alt__btn" id="oc-otp-open">
					<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" aria-hidden="true"><rect x="6" y="2.5" width="12" height="19" rx="2.6"/><path d="M10.6 18.6h2.8"/></svg>
					<?php esc_html_e( 'A code to my phone', 'oc-theme' ); ?>
				</button>

				<div class="oc-otp" id="oc-otp"
					data-ajax="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>"
					data-nonce="<?php echo esc_attr( wp_create_nonce( 'oc_auth' ) ); ?>"
					data-to="<?php echo esc_url( $this->destination() ); ?>">
					<p class="oc-otp__msg" id="oc-otp-msg"><?php esc_html_e( 'The number on your user account — the code arrives by SMS.', 'oc-theme' ); ?></p>

					<p class="oc-otp__step" data-step="phone">
						<label for="oc-otp-phone"><?php esc_html_e( 'Phone', 'oc-theme' ); ?></label>
						<input type="tel" id="oc-otp-phone" name="oc_otp_phone" dir="ltr" autocomplete="tel" inputmode="tel" />
					</p>

					<p class="oc-otp__step" data-step="code" hidden>
						<label for="oc-otp-code"><?php esc_html_e( 'The code', 'oc-theme' ); ?></label>
						<input type="text" id="oc-otp-code" name="oc_otp_code" dir="ltr" inputmode="numeric" autocomplete="one-time-code" maxlength="6" />
					</p>

					<button type="button" class="oc-alt__btn" id="oc-otp-go"><?php esc_html_e( 'Send me a code', 'oc-theme' ); ?></button>
					<button type="button" class="oc-otp__back" id="oc-otp-back" hidden><?php esc_html_e( 'A different number', 'oc-theme' ); ?></button>
				</div>
			<?php endif; ?>
		</div>
		<?php

		if ( $otp ) {
			$this->otp_script();
		}
	}

	/**
	 * Where a successful sign-in should land. WordPress's own redirect_to
	 * when it is there and safe, the dashboard otherwise.
	 */
	private function destination(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only; wp_validate_redirect() decides what is allowed.
		$to = isset( $_REQUEST['redirect_to'] ) ? esc_url_raw( (string) wp_unslash( $_REQUEST['redirect_to'] ) ) : '';

		return '' === $to ? admin_url() : wp_validate_redirect( $to, admin_url() );
	}

	/**
	 * Only the sign-in form itself gets the extra doors — not the password
	 * reset, not the registration form, and not this theme's two-step code
	 * screen, where a second way in would step around the first.
	 */
	private function on_sign_in_form(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only branch on WordPress's own action name.
		$action = isset( $_REQUEST['action'] ) ? sanitize_key( (string) wp_unslash( $_REQUEST['action'] ) ) : 'login';

		return in_array( $action, array( 'login', '' ), true );
	}

	/**
	 * The code panel's behaviour: the same two endpoints the shop's own
	 * sign-in uses, so the rate limits, the attempt counter and the expiry
	 * are the ones already in place.
	 */
	private function otp_script(): void {
		$words = array(
			'sent'     => __( 'Sent — the code is on its way.', 'oc-theme' ),
			'noUser'   => __( 'No user account carries that number.', 'oc-theme' ),
			'wait'     => __( 'One moment…', 'oc-theme' ),
			'network'  => __( 'The connection stumbled — try again.', 'oc-theme' ),
			'needNum'  => __( 'Type the phone number first.', 'oc-theme' ),
			'needCode' => __( 'Type the code you received.', 'oc-theme' ),
			'send'     => __( 'Send me a code', 'oc-theme' ),
			'verify'   => __( 'Sign in', 'oc-theme' ),
		);
		?>
		<script id="oc-login-js">
		( function () {
			var box = document.getElementById( 'oc-otp' ),
				open = document.getElementById( 'oc-otp-open' ),
				go = document.getElementById( 'oc-otp-go' ),
				back = document.getElementById( 'oc-otp-back' ),
				msg = document.getElementById( 'oc-otp-msg' ),
				phone = document.getElementById( 'oc-otp-phone' ),
				code = document.getElementById( 'oc-otp-code' ),
				step = 'phone',
				busy = false;

			if ( ! box || ! open || ! go ) {
				return;
			}

			var T = <?php echo wp_json_encode( $words ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_json_encode() is the escape. ?>;

			function say( text, bad ) {
				msg.textContent = text;
				msg.classList.toggle( 'is-bad', !! bad );
			}

			function show( which ) {
				step = which;

				box.querySelectorAll( '.oc-otp__step' ).forEach( function ( p ) {
					p.hidden = p.dataset.step !== which;
				} );

				go.textContent = 'code' === which ? T.verify : T.send;
				back.hidden = 'code' !== which;
				( 'code' === which ? code : phone ).focus();
			}

			open.addEventListener( 'click', function () {
				box.classList.add( 'is-open' );
				open.hidden = true;
				phone.focus();
			} );

			back.addEventListener( 'click', function () {
				code.value = '';
				say( '' );
				show( 'phone' );
			} );

			function post( action, data ) {
				var body = new FormData();

				body.append( 'action', action );
				body.append( 'nonce', box.dataset.nonce );

				Object.keys( data ).forEach( function ( k ) {
					body.append( k, data[ k ] );
				} );

				return fetch( box.dataset.ajax, {
					method: 'POST',
					credentials: 'same-origin',
					body: body
				} ).then( function ( r ) { return r.json(); } );
			}

			go.addEventListener( 'click', function () {
				if ( busy ) {
					return;
				}

				if ( 'phone' === step ) {
					if ( ! phone.value.trim() ) {
						say( T.needNum, true );
						return;
					}

					busy = true;
					say( T.wait );

					post( 'oc_auth_start', { phone: phone.value.trim() } ).then( function ( r ) {
						busy = false;

						// An unknown number would start the shop's customer
						// registration; this door only opens for accounts
						// that already exist.
						if ( ! r || ! r.success || 'code' !== ( r.data && r.data.step ) ) {
							say( ( r && r.data && r.data.msg ) || T.noUser, true );
							return;
						}

						say( T.sent );
						show( 'code' );
					} ).catch( function () {
						busy = false;
						say( T.network, true );
					} );

					return;
				}

				if ( ! code.value.trim() ) {
					say( T.needCode, true );
					return;
				}

				busy = true;
				say( T.wait );

				post( 'oc_auth_verify', { phone: phone.value.trim(), code: code.value.trim() } ).then( function ( r ) {
					busy = false;

					if ( ! r || ! r.success ) {
						say( ( r && r.data && r.data.msg ) || T.network, true );
						return;
					}

					window.location.href = box.dataset.to;
				} ).catch( function () {
					busy = false;
					say( T.network, true );
				} );
			} );

			box.addEventListener( 'keydown', function ( e ) {
				if ( 'Enter' === e.key ) {
					e.preventDefault();
					go.click();
				}
			} );
		}() );
		</script>
		<?php
	}

	/**
	 * Our mark, small, under a shop that showed its own above the card.
	 * When the shop has no logo ours is already the mark up top, and saying
	 * it twice on one screen is just noise.
	 */
	public function credit(): void {
		if ( ! self::logo()['mine'] ) {
			return;
		}

		$url = trim( (string) get_theme_mod( 'oc_footer_oc_url', 'https://onlinestore.co.il' ) );
		?>
		<a class="oc-login__by" href="<?php echo esc_url( '' === $url ? 'https://onlinestore.co.il' : $url ); ?>" target="_blank" rel="noopener">
			<span><?php esc_html_e( 'by', 'oc-theme' ); ?></span>
			<img src="<?php echo esc_url( OC_THEME_URI . '/assets/img/oc-credit.svg' ); ?>" alt="Original Concepts" width="50" height="26" />
		</a>
		<?php
	}
}
