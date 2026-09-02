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
		$height  = absint( get_theme_mod( 'oc_cta_height', 48 ) );
		$radius  = (string) get_theme_mod( 'oc_cta_radius', '8px' );

		?>
		<style id="oc-login-css">
		:root {
			/* The same two tokens every button in the shop's own sign-in
			 * drawer measures itself by, so the two screens are one design
			 * rather than two that look alike. */
			--ocl-h: <?php echo absint( $height ); ?>px;
			--ocl-r: <?php echo esc_html( $radius ); ?>;
			--ocl-primary: <?php echo esc_html( $primary ); ?>;
			--ocl-ink: #10131a;
			--ocl-ink-2: #6b7280;
			--ocl-line: #e3e6ec;
		}

		/* Centred while the card fits, and scrolled from the top once it
		 * does not — centring a column taller than the screen pushes its
		 * head off the top edge, which is where the logo was going on a
		 * phone with every provider switched on. */
		body.login {
			background: radial-gradient(1100px 560px at 50% -8%, #eef1f7 0%, #f7f8fa 46%, #f7f8fa 100%);
			color: var(--ocl-ink);
			font-family: "Assistant", system-ui, -apple-system, "Segoe UI", sans-serif;
			min-block-size: 100dvh;
			display: flex;
			flex-direction: column;
			justify-content: safe center;
			align-items: center;
			padding-block: 32px;
		}

		#login { inline-size: min(400px, calc(100vw - 32px)); padding: 0; }

		/* ---------- the mark ---------- */

		.login h1,
		.login h1.wp-login-logo { margin: 0 0 24px; padding: 0; background: none; }

		.login h1 a,
		.login h1.wp-login-logo a {
			display: block;
			inline-size: 100%;
			block-size: <?php echo $logo['mine'] ? '43px' : '52px'; ?>;
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

		.login form {
			display: flex;
			flex-direction: column;
			gap: 12px;
			margin: 0;
			padding: 26px 24px;
			border: 1px solid var(--ocl-line);
			border-radius: 18px;
			background: #fff;
			box-shadow: 0 1px 2px rgb(16 24 40 / 5%), 0 18px 40px -26px rgb(16 24 40 / 24%);
		}

		.login form p { margin: 0; }

		.login form label {
			display: block;
			margin-block-end: 6px;
			font-size: 13.5px;
			font-weight: 600;
			color: var(--ocl-ink);
		}

		/* Every field and every button is one height and one corner — the
		 * rule the drawer keeps, and the reason its column reads as a set. */
		.login input[type="text"],
		.login input[type="password"],
		.login input[type="email"],
		.login input[type="tel"],
		.login form .input {
			box-sizing: border-box;
			inline-size: 100%;
			block-size: var(--ocl-h);
			margin: 0;
			padding-inline: 14px;
			padding-block: 0;
			border: 1px solid var(--ocl-line);
			border-radius: var(--ocl-r);
			background: #fff;
			font: inherit;
			font-size: 15px;
			line-height: normal;
			color: var(--ocl-ink);
			box-shadow: none;
			transition: border-color .14s ease, box-shadow .14s ease;
		}

		.login input:focus,
		.login form .input:focus {
			border-color: var(--ocl-primary);
			outline: none;
			box-shadow: 0 0 0 3px color-mix(in srgb, var(--ocl-primary) 16%, transparent);
		}

		/* The eye rides inside its own field: the wrapper is the frame, the
		 * input inside it loses its own. Hanging the button off a field that
		 * still carried a border is what put it beside the box. */
		.login .wp-pwd { position: relative; display: block; }
		.login .wp-pwd input[type="password"],
		.login .wp-pwd input[type="text"] { padding-inline-end: calc(var(--ocl-h) - 6px); }
		.login .wp-pwd .button.wp-hide-pw {
			position: absolute;
			inset-block-start: 0;
			inset-inline-end: 0;
			display: flex;
			align-items: center;
			justify-content: center;
			inline-size: var(--ocl-h);
			block-size: var(--ocl-h);
			min-inline-size: 0;
			padding: 0;
			border: 0;
			background: none;
			box-shadow: none;
			color: var(--ocl-ink-2);
		}
		.login .wp-pwd .button.wp-hide-pw:hover { color: var(--ocl-ink); background: none; }
		.login .wp-pwd .button.wp-hide-pw .dashicons { inline-size: 20px; block-size: 20px; font-size: 20px; }

		/* ---------- buttons: one shape, two weights ---------- */

		.login .button-primary,
		.login #wp-submit,
		.oc-l__cta {
			box-sizing: border-box;
			display: flex;
			align-items: center;
			justify-content: center;
			gap: 10px;
			inline-size: 100%;
			block-size: var(--ocl-h);
			margin: 0;
			padding-inline: 22px;
			border: 1px solid var(--ocl-primary);
			border-radius: var(--ocl-r);
			background: var(--ocl-primary);
			color: #fff;
			font: inherit;
			font-size: 15px;
			font-weight: 600;
			line-height: normal;
			text-shadow: none;
			text-decoration: none;
			box-shadow: none;
			cursor: pointer;
			transition: filter .12s ease, opacity .15s ease;
		}
		.login .button-primary:hover,
		.login #wp-submit:hover,
		.oc-l__cta:hover { filter: brightness(1.08); color: #fff; }
		.oc-l__cta.is-busy { opacity: .6; pointer-events: none; }

		.oc-l__provider {
			box-sizing: border-box;
			display: flex;
			align-items: center;
			justify-content: center;
			gap: 10px;
			inline-size: 100%;
			block-size: var(--ocl-h);
			padding-inline: 22px;
			border: 1px solid var(--ocl-line);
			border-radius: var(--ocl-r);
			background: none;
			color: var(--ocl-ink);
			font: inherit;
			font-size: 15px;
			font-weight: 500;
			line-height: normal;
			text-decoration: none;
			cursor: pointer;
			transition: border-color .12s ease, background .12s ease;
		}
		.oc-l__provider:hover { border-color: #c6ccd8; background: #fbfbfd; color: var(--ocl-ink); }
		.oc-l__provider svg { flex: none; }

		/* ---------- the parts, in order ---------- */

		.oc-l__stack { display: flex; flex-direction: column; gap: 10px; }

		.oc-l__or {
			display: flex;
			align-items: center;
			gap: 14px;
			margin: 2px 0;
			color: var(--ocl-ink-2);
			font-size: 13px;
		}
		.oc-l__or::before,
		.oc-l__or::after { content: ""; flex: 1; block-size: 1px; background: var(--ocl-line); }

		.oc-l__msg { margin: 0; font-size: 13px; line-height: 1.5; color: var(--ocl-ink-2); }
		.oc-l__msg.is-bad { color: #b32d2e; }

		.oc-l__back {
			align-self: center;
			border: 0;
			background: none;
			padding: 0;
			color: var(--ocl-ink-2);
			font: inherit;
			font-size: 13px;
			text-decoration: underline;
			text-underline-offset: 3px;
			cursor: pointer;
		}
		.oc-l__back:hover { color: var(--ocl-ink); }

		.oc-l__code { text-align: center; letter-spacing: .4em; font-size: 18px; }

		/* Password rows, gathered by script so they can travel and hide as
		 * one; without script they simply stay where WordPress put them. */
		.oc-l__pwd { display: flex; flex-direction: column; gap: 12px; }
		.oc-l__pwd[hidden],
		.oc-l__step[hidden],
		.oc-l__provider[hidden],
		.oc-l__back[hidden] { display: none; }

		/* The checkbox, its words and WordPress's help dot are three
		 * siblings in one paragraph — the row is what has to line them up,
		 * not the label, which holds only the words. */
		.login .forgetmenot {
			display: flex;
			align-items: center;
			gap: 8px;
			margin: 0;
			float: none;
			line-height: 1.4;
		}
		.login .forgetmenot input[type="checkbox"] { margin: 0; flex: none; }
		.login .forgetmenot label {
			display: inline;
			margin: 0;
			font-weight: 400;
			font-size: 13.5px;
			color: var(--ocl-ink-2);
			cursor: pointer;
		}
		.login .forgetmenot .wp-tooltip { display: inline-flex; align-items: center; }
		.login .forgetmenot .wp-tooltip__toggle {
			display: inline-flex;
			align-items: center;
			padding: 0;
			border: 0;
			background: none;
			color: var(--ocl-ink-2);
			cursor: pointer;
		}

		/* ---------- WordPress's own furniture ---------- */

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
		.login .language-switcher { margin-block-start: 20px; }

		.oc-login__by {
			display: flex;
			align-items: center;
			justify-content: center;
			gap: 7px;
			margin-block-start: 20px;
			color: var(--ocl-ink-2);
			font-size: 12px;
			text-decoration: none;
		}
		.oc-login__by img { block-size: 24px; inline-size: auto; opacity: .75; }
		.oc-login__by:hover { color: var(--ocl-ink); }
		.oc-login__by:hover img { opacity: 1; }

		@media (max-width: 480px) {
			.login form { padding: 22px 18px; }
		}
		</style>
		<?php
	}

	/**
	 * The doors, in the order a shopper's own sign-in offers them: the
	 * phone first, because a code beats a remembered password, then the
	 * providers, and the username and password last — present, one click
	 * away, never gone.
	 *
	 * Each is drawn only when the thing behind it is configured, so the
	 * screen never offers a way in that cannot open.
	 */
	public function alternatives(): void {
		if ( ! $this->on_sign_in_form() ) {
			return;
		}

		$s      = Auth::settings();
		$otp    = ! empty( $s['sms_on'] );
		$google = ! empty( $s['google_on'] ) && '' !== (string) $s['google_id'];
		$fb     = ! empty( $s['fb_on'] ) && '' !== (string) $s['fb_id'];
		$apple  = ! empty( $s['apple_on'] ) && '' !== (string) $s['apple_client_id'];

		if ( ! $otp && ! $google && ! $fb && ! $apple ) {
			return;
		}

		?>
		<div class="oc-l__stack" id="oc-l"
			data-ajax="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>"
			data-nonce="<?php echo esc_attr( wp_create_nonce( 'oc_auth' ) ); ?>"
			data-to="<?php echo esc_url( $this->destination() ); ?>"
			data-otp="<?php echo $otp ? '1' : '0'; ?>">

			<?php if ( $otp ) : ?>
				<p class="oc-l__msg" id="oc-l-msg"><?php esc_html_e( 'The number on your user account — the code arrives by SMS.', 'oc-theme' ); ?></p>

				<p class="oc-l__step" data-step="phone">
					<label for="oc-l-phone"><?php esc_html_e( 'Phone', 'oc-theme' ); ?></label>
					<input type="tel" id="oc-l-phone" dir="ltr" inputmode="tel" autocomplete="tel" />
				</p>

				<p class="oc-l__step" data-step="code" hidden>
					<label for="oc-l-code"><?php esc_html_e( 'The code', 'oc-theme' ); ?></label>
					<input type="text" id="oc-l-code" class="oc-l__code" dir="ltr" inputmode="numeric" autocomplete="one-time-code" maxlength="6" />
				</p>

				<button type="button" class="oc-l__cta" id="oc-l-go"><?php esc_html_e( 'Send me a code', 'oc-theme' ); ?></button>
				<button type="button" class="oc-l__back" id="oc-l-back" hidden><?php esc_html_e( 'A different number', 'oc-theme' ); ?></button>
			<?php endif; ?>

			<div class="oc-l__or" aria-hidden="true"><span><?php esc_html_e( 'or', 'oc-theme' ); ?></span></div>

			<?php if ( $google ) : ?>
				<a class="oc-l__provider" href="<?php echo esc_url( admin_url( 'admin-post.php?action=oc_auth_google&to=admin' ) ); ?>" rel="nofollow">
					<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path fill="#4285F4" d="M23.5 12.3c0-.9-.1-1.5-.2-2.2H12v4.4h6.5c-.1 1.1-.8 2.7-2.4 3.8l3.7 2.8c2.2-2 3.7-5 3.7-8.8z"/><path fill="#34A853" d="M12 24c3.2 0 5.9-1 7.9-2.9l-3.7-2.8c-1 .7-2.4 1.2-4.2 1.2-3.2 0-5.9-2.1-6.9-5L1.3 17.4C3.3 21.4 7.3 24 12 24z"/><path fill="#FBBC05" d="M5.1 14.5c-.2-.7-.4-1.4-.4-2.2s.1-1.5.4-2.2L1.3 7.2C.5 8.7 0 10.3 0 12.3s.5 3.6 1.3 5.1l3.8-2.9z"/><path fill="#EA4335" d="M12 4.8c2.3 0 3.8 1 4.7 1.8l3.4-3.3C18 1.3 15.2 0 12 0 7.3 0 3.3 2.6 1.3 6.6l3.8 2.9c1-2.9 3.7-4.7 6.9-4.7z"/></svg>
					<?php esc_html_e( 'Sign in with Google', 'oc-theme' ); ?>
				</a>
			<?php endif; ?>

			<?php if ( $fb ) : ?>
				<a class="oc-l__provider" href="<?php echo esc_url( admin_url( 'admin-post.php?action=oc_auth_fb&to=admin' ) ); ?>" rel="nofollow">
					<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path fill="#1877F2" d="M24 12a12 12 0 1 0-13.9 11.9v-8.4H7.1V12h3.1V9.4c0-3 1.8-4.7 4.5-4.7 1.3 0 2.7.2 2.7.2v3h-1.5c-1.5 0-2 .9-2 1.9V12h3.4l-.5 3.5h-2.9v8.4A12 12 0 0 0 24 12z"/></svg>
					<?php esc_html_e( 'Sign in with Facebook', 'oc-theme' ); ?>
				</a>
			<?php endif; ?>

			<?php if ( $apple ) : ?>
				<a class="oc-l__provider" href="<?php echo esc_url( admin_url( 'admin-post.php?action=oc_auth_apple&to=admin' ) ); ?>" rel="nofollow">
					<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path fill="currentColor" d="M16.7 12.9c0-2.4 2-3.6 2.1-3.6-1.1-1.7-2.9-1.9-3.5-1.9-1.5-.2-2.9.9-3.7.9-.8 0-1.9-.9-3.2-.9-1.6 0-3.1 1-4 2.4-1.7 2.9-.4 7.3 1.2 9.7.8 1.2 1.8 2.5 3 2.4 1.2 0 1.7-.8 3.1-.8 1.5 0 1.9.8 3.2.8 1.3 0 2.1-1.2 2.9-2.4.9-1.4 1.3-2.7 1.3-2.8-.1 0-2.4-1-2.4-3.8zM14.4 5.6c.6-.8 1.1-1.9 1-3-1 0-2.1.6-2.8 1.5-.6.7-1.2 1.9-1 3 1 .1 2.1-.6 2.8-1.5z"/></svg>
					<?php esc_html_e( 'Sign in with Apple', 'oc-theme' ); ?>
				</a>
			<?php endif; ?>

			<?php if ( $otp ) : ?>
				<button type="button" class="oc-l__provider" id="oc-l-pwd-open">
					<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><rect x="3.5" y="10.5" width="17" height="10.5" rx="2.4"/><path d="M7.6 10.5V7.4a4.4 4.4 0 0 1 8.8 0v3.1"/></svg>
					<?php esc_html_e( 'Sign in with username and password', 'oc-theme' ); ?>
				</button>
			<?php endif; ?>
		</div>
		<?php

		$this->script( $otp );
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
	 * Two jobs. It gathers WordPress's own rows into one block so they can
	 * move below the phone and fold away behind a button — without script
	 * they simply stay where WordPress put them, which is a working form,
	 * so the screen degrades into the plain one rather than into nothing.
	 * And it runs the code flow against the two endpoints the shop's own
	 * sign-in uses, so the rate limits, the attempt counter and the expiry
	 * are the ones already in place.
	 *
	 * @param bool $otp Whether the phone step is on the screen.
	 */
	private function script( bool $otp ): void {
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
			var box = document.getElementById( 'oc-l' ),
				form = box && box.closest( 'form' );

			if ( ! box || ! form ) {
				return;
			}

			var T = <?php echo wp_json_encode( $words ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_json_encode() is the escape. ?>;

			/* ---- WordPress's own rows, gathered and moved ---- */

			var pwd = document.createElement( 'div' );

			pwd.className = 'oc-l__pwd';

			var opener = document.getElementById( 'oc-l-pwd-open' );

			// Remember-me and the submit button are printed after this
			// block, so at the moment the script runs they do not exist
			// yet. Gathering waits for the closing tag; a snapshot of the
			// list is taken first, because appending to the new block
			// removes a row from the live form.children the loop walks.
			function gather() {
				var rows = Array.prototype.slice.call( form.children );

				form.appendChild( pwd );

				rows.forEach( function ( row ) {
					if ( row !== box && row !== pwd ) {
						pwd.appendChild( row );
					}
				} );

				if ( opener ) {
					pwd.hidden = true;
				}
			}

			if ( 'loading' === document.readyState ) {
				document.addEventListener( 'DOMContentLoaded', gather );
			} else {
				gather();
			}

			if ( opener ) {
				opener.addEventListener( 'click', function () {
					pwd.hidden = false;
					opener.hidden = true;

					var first = pwd.querySelector( 'input:not([type="hidden"])' );

					if ( first ) {
						first.focus();
					}
				} );
			}

			<?php if ( $otp ) : ?>
			/* ---- the code flow ---- */

			var go = document.getElementById( 'oc-l-go' ),
				back = document.getElementById( 'oc-l-back' ),
				msg = document.getElementById( 'oc-l-msg' ),
				phone = document.getElementById( 'oc-l-phone' ),
				code = document.getElementById( 'oc-l-code' ),
				step = 'phone',
				busy = false;

			function say( text, bad ) {
				msg.textContent = text;
				msg.classList.toggle( 'is-bad', !! bad );
			}

			function show( which ) {
				step = which;

				box.querySelectorAll( '.oc-l__step' ).forEach( function ( el ) {
					el.hidden = el.dataset.step !== which;
				} );

				go.textContent = 'code' === which ? T.verify : T.send;
				back.hidden = 'code' !== which;
				( 'code' === which ? code : phone ).focus();
			}

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

			function done() {
				busy = false;
				go.classList.remove( 'is-busy' );
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
					go.classList.add( 'is-busy' );
					say( T.wait );

					post( 'oc_auth_start', { phone: phone.value.trim() } ).then( function ( r ) {
						done();

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
						done();
						say( T.network, true );
					} );

					return;
				}

				if ( ! code.value.trim() ) {
					say( T.needCode, true );
					return;
				}

				busy = true;
				go.classList.add( 'is-busy' );
				say( T.wait );

				post( 'oc_auth_verify', { phone: phone.value.trim(), code: code.value.trim() } ).then( function ( r ) {
					done();

					if ( ! r || ! r.success ) {
						say( ( r && r.data && r.data.msg ) || T.network, true );
						return;
					}

					window.location.href = box.dataset.to;
				} ).catch( function () {
					done();
					say( T.network, true );
				} );
			} );

			back.addEventListener( 'click', function () {
				code.value = '';
				say( '' );
				show( 'phone' );
			} );

			// Enter inside the phone or code field means "go", not "submit
			// the password form that is folded away below".
			box.addEventListener( 'keydown', function ( e ) {
				if ( 'Enter' === e.key && ( e.target === phone || e.target === code ) ) {
					e.preventDefault();
					go.click();
				}
			} );
			<?php endif; ?>
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
