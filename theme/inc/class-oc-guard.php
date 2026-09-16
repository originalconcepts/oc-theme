<?php
/**
 * Spam guard: the quiet layer under every public form.
 *
 * Three checks no visitor ever sees — a honeypot field, a signed timestamp
 * (a form filled in under three seconds was not filled by a person), and
 * a per-connection hourly limit — plus, per form and off by default,
 * Cloudflare Turnstile: an invisible challenge, no pictures to solve.
 *
 * @package OC_Theme
 */

declare( strict_types = 1 );

namespace OC\Theme;

defined( 'ABSPATH' ) || exit;

/**
 * The guard.
 */
final class Guard {

	/**
	 * Option holding the settings.
	 */
	const OPTION = 'oc_guard';

	/**
	 * The forms the guard knows.
	 */
	const FORMS = array( 'comments', 'notify', 'checkout', 'login', 'newsletter', 'leads' );

	/**
	 * Seconds a person needs at the very least.
	 */
	const MIN_SECONDS = 3;

	/**
	 * Whether a Turnstile container was printed on this page.
	 *
	 * @var bool
	 */
	private static $need = false;

	/**
	 * Hook in.
	 */
	public function register(): void {
		add_action( 'wp_footer', array( $this, 'script' ), 5 );
	}

	/**
	 * Labels for the settings screen.
	 *
	 * @return array<string,string>
	 */
	public static function labels(): array {
		return array(
			'comments'   => __( 'Comments and product reviews', 'oc-theme' ),
			'notify'     => __( 'Back-in-stock signup', 'oc-theme' ),
			'checkout'   => __( 'Checkout', 'oc-theme' ),
			'login'      => __( 'Sign-in (sending the code)', 'oc-theme' ),
			'newsletter' => __( 'Newsletter block', 'oc-theme' ),
			'leads'      => __( 'Contact form block', 'oc-theme' ),
		);
	}

	/**
	 * Defaults: no Turnstile anywhere, sensible hourly limits. Sign-in
	 * and the contact block carry limits of their own, so zero here.
	 *
	 * @return array{site:string,secret:string,ts:array<string,int>,limits:array<string,int>}
	 */
	public static function defaults(): array {
		return array(
			'site'   => '',
			'secret' => '',
			'ts'     => array_fill_keys( self::FORMS, 0 ),
			'limits' => array(
				'comments'   => 10,
				'notify'     => 10,
				'checkout'   => 8,
				'login'      => 0,
				'newsletter' => 10,
				'leads'      => 0,
			),
		);
	}

	/**
	 * Saved settings over the defaults.
	 *
	 * @return array{site:string,secret:string,ts:array<string,int>,limits:array<string,int>}
	 */
	public static function settings(): array {
		$d = self::defaults();
		$o = get_option( self::OPTION, array() );
		$o = is_array( $o ) ? $o : array();

		$out = array(
			'site'   => sanitize_text_field( (string) ( $o['site'] ?? '' ) ),
			'secret' => (string) ( $o['secret'] ?? '' ),
			'ts'     => $d['ts'],
			'limits' => $d['limits'],
		);

		foreach ( self::FORMS as $form ) {
			$out['ts'][ $form ]     = empty( $o['ts'][ $form ] ) ? 0 : 1;
			$out['limits'][ $form ] = isset( $o['limits'][ $form ] ) ? max( 0, min( 1000, (int) $o['limits'][ $form ] ) ) : $d['limits'][ $form ];
		}

		return $out;
	}

	/* ------------------------------------------------------------ pure */

	/**
	 * A timestamp the form carries, signed so it cannot be back-dated.
	 *
	 * @param int    $time The moment the form was printed.
	 * @param string $form Which form.
	 * @param string $salt The site's salt.
	 */
	public static function sign( int $time, string $form, string $salt ): string {
		return $time . '.' . substr( hash_hmac( 'sha256', $time . '|' . $form, $salt ), 0, 24 );
	}

	/**
	 * How many seconds ago a signed timestamp was printed; null when the
	 * signature does not hold.
	 *
	 * @param string $token The signed timestamp.
	 * @param string $form  Which form.
	 * @param string $salt  The site's salt.
	 * @param int    $now   Now.
	 */
	public static function age( string $token, string $form, string $salt, int $now ): ?int {
		$parts = explode( '.', $token, 2 );

		if ( 2 !== count( $parts ) || ! ctype_digit( $parts[0] ) ) {
			return null;
		}

		if ( ! hash_equals( self::sign( (int) $parts[0], $form, $salt ), $token ) ) {
			return null;
		}

		return $now - (int) $parts[0];
	}

	/**
	 * The verdict of the three quiet checks: '' passes, otherwise the
	 * reason ('honey', 'fast', 'stale', 'rate').
	 *
	 * @param string   $honey What the honeypot held.
	 * @param int|null $age   Seconds since the form was printed; null for a
	 *                        missing or forged timestamp.
	 * @param int      $count Submissions from this connection this hour.
	 * @param int      $limit The hourly cap; 0 for none.
	 */
	public static function verdict( string $honey, ?int $age, int $count, int $limit ): string {
		if ( '' !== $honey ) {
			return 'honey';
		}

		if ( null === $age ) {
			return 'stale';
		}

		if ( $age < self::MIN_SECONDS ) {
			return 'fast';
		}

		if ( $limit > 0 && $count >= $limit ) {
			return 'rate';
		}

		return '';
	}

	/* ------------------------------------------------------------ live */

	/**
	 * Whether Turnstile stands in front of a form: switched on for it,
	 * with both keys in place.
	 *
	 * @param string $form Which form.
	 */
	public static function turnstile_on( string $form ): bool {
		$s = self::settings();

		return ! empty( $s['ts'][ $form ] ) && '' !== $s['site'] && '' !== $s['secret'];
	}

	/**
	 * The fields a form prints: the honeypot, the signed timestamp, and
	 * the Turnstile container when it is on for this form.
	 *
	 * @param string $form Which form.
	 */
	public static function fields( string $form ): string {
		$out = '<p class="oc-guard-hp" aria-hidden="true"><label><span>' . esc_html__( 'Leave this empty', 'oc-theme' ) . '</span>'
			. '<input type="text" name="oc_hp" value="" tabindex="-1" autocomplete="off"></label></p>'
			. '<input type="hidden" name="oc_t" value="' . esc_attr( self::sign( time(), $form, self::salt() ) ) . '">';

		if ( self::turnstile_on( $form ) ) {
			self::$need = true;
			$out       .= '<div class="oc-guard-ts" data-action="' . esc_attr( $form ) . '"></div>';
		}

		return $out;
	}

	/**
	 * What the page script needs: the site key, which forms carry
	 * Turnstile, and signed timestamps for the forms the script builds
	 * itself.
	 *
	 * @return array{site:string,ts:array<string,int>,t:array<string,string>}
	 */
	public static function for_script(): array {
		$s  = self::settings();
		$ts = array();

		foreach ( self::FORMS as $form ) {
			if ( self::turnstile_on( $form ) ) {
				$ts[ $form ] = 1;
			}
		}

		return array(
			'site' => '' !== $s['secret'] ? $s['site'] : '',
			'ts'   => $ts,
			't'    => array(
				'notify' => self::sign( time(), 'notify', self::salt() ),
				'login'  => self::sign( time(), 'login', self::salt() ),
			),
		);
	}

	/**
	 * Run the checks on the current request. '' when it passes, otherwise
	 * the sentence to show. A pass counts toward the hourly limit.
	 *
	 * @param string $form Which form.
	 */
	public static function check( string $form ): string {
		$s = self::settings();

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- spam traps, deliberately nonce-free: the forms live in cached pages.
		$honey = isset( $_POST['oc_hp'] ) ? (string) wp_unslash( $_POST['oc_hp'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- only compared against the empty string, never stored.
		$stamp = isset( $_POST['oc_t'] ) ? sanitize_text_field( wp_unslash( $_POST['oc_t'] ) ) : '';
		$token = isset( $_POST['cf-turnstile-response'] ) ? sanitize_text_field( wp_unslash( $_POST['cf-turnstile-response'] ) ) : '';
		// phpcs:enable

		$limit = (int) ( $s['limits'][ $form ] ?? 0 );
		$key   = 'oc_guard_' . $form . '_' . md5( self::ip() );
		$count = $limit > 0 ? (int) get_transient( $key ) : 0;
		$age   = '' === $stamp ? null : self::age( $stamp, $form, self::salt(), time() );
		$why   = self::verdict( $honey, $age, $count, $limit );

		if ( '' === $why && self::turnstile_on( $form ) && ! self::verify( $token ) ) {
			$why = 'bot';
		}

		if ( '' !== $why ) {
			return self::message( $why );
		}

		if ( $limit > 0 ) {
			set_transient( $key, $count + 1, HOUR_IN_SECONDS );
		}

		return '';
	}

	/**
	 * The sentence for a refusal. A trap that sprang is not named.
	 *
	 * @param string $why The verdict.
	 */
	private static function message( string $why ): string {
		switch ( $why ) {
			case 'fast':
				return __( 'That was quick — please take a moment and send again.', 'oc-theme' );
			case 'stale':
				return __( 'The page went stale — refresh it and try again.', 'oc-theme' );
			case 'rate':
				return __( 'Too many attempts from your connection — try again in about an hour.', 'oc-theme' );
			default:
				return __( 'Something went wrong — please try again.', 'oc-theme' );
		}
	}

	/**
	 * Ask Cloudflare whether the token is good. A network failure on our
	 * side lets the visitor through: a shop that cannot reach Cloudflare
	 * must still sell.
	 *
	 * @param string $token The widget's response.
	 */
	private static function verify( string $token ): bool {
		if ( '' === $token ) {
			return false;
		}

		$res = wp_remote_post(
			'https://challenges.cloudflare.com/turnstile/v0/siteverify',
			array(
				'timeout' => 6,
				'body'    => array(
					'secret'   => self::settings()['secret'],
					'response' => $token,
					'remoteip' => self::ip(),
				),
			)
		);

		if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			return true;
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $res ), true );

		return is_array( $body ) && ! empty( $body['success'] );
	}

	/**
	 * The visitor's address, hashed by every caller before it is stored.
	 */
	private static function ip(): string {
		return isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- hashed by the callers, never printed or stored.
	}

	/**
	 * The signing salt.
	 */
	private static function salt(): string {
		return wp_salt( 'nonce' );
	}

	/**
	 * Cloudflare's script, only on pages that carry a widget: one printed
	 * on the page, the sign-in drawer (printed after this hook), or the
	 * back-in-stock card the page script builds on a product page.
	 */
	public function script(): void {
		$wanted = self::$need
			|| self::turnstile_on( 'login' )
			|| ( self::turnstile_on( 'notify' ) && function_exists( 'is_product' ) && is_product() );

		if ( ! $wanted ) {
			return;
		}

		wp_enqueue_script(
			'cf-turnstile',
			'https://challenges.cloudflare.com/turnstile/v0/api.js?onload=ocTurnstileReady&render=explicit',
			array(),
			null, // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- Cloudflare's own, always current.
			array(
				'in_footer' => true,
				'strategy'  => 'async',
			)
		);
	}
}
