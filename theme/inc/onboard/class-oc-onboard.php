<?php
/**
 * The onboarding questionnaire: the door, the key and the state.
 *
 * A new shop is born as a clone of the base site. The clone mints one
 * signed link, /start/<token>, and the customer fills the questionnaire
 * on the new site itself — no account, no wp-admin, every answer saved
 * as it is typed. On "I'm done" the answers are written straight into
 * the theme's settings, the store details, WooCommerce and the pages.
 *
 * This class owns the invitation (who, when, which token, what status)
 * and the virtual route. The questions live in Schema, the saving in
 * Draft, the writing in Apply, the page in Front, the screen in Admin.
 *
 * @package OC_Theme
 */

declare( strict_types = 1 );

namespace OC\Theme\Onboard;

defined( 'ABSPATH' ) || exit;

/**
 * Invitation, token and route.
 */
final class Onboard {

	const OPTION   = 'oc_onboard';
	const SETTINGS = 'oc_onboard_settings';
	const TTL_DAYS = 90;
	const QUERY    = 'oc_start';

	/**
	 * Where a copy of every mail goes and where the report lands.
	 */
	const COPY_TO = 'george@originalconcepts.co.il';

	/**
	 * Hook in.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'route' ) );
		add_filter( 'query_vars', array( $this, 'vars' ) );
		add_filter( 'redirect_canonical', array( $this, 'no_canonical' ), 10, 2 );

		( new Rest() )->register();
		( new Front() )->register();
		( new Mail() )->register();
		( new Curtain() )->register();

		if ( is_admin() ) {
			( new Admin() )->register();
		}
	}

	/* ---------------------------------------------------------------- state */

	/**
	 * The invitation record with defaults.
	 *
	 * @return array<string,mixed>
	 */
	public static function state(): array {
		$saved = get_option( self::OPTION );

		return wp_parse_args(
			is_array( $saved ) ? $saved : array(),
			array(
				'status'     => 'none',   // none | draft | submitted | applied | cancelled.
				'token_hash' => '',
				'link'       => '',       // The link itself, for re-sends and reminders.
				'created'    => 0,        // Unix, when the link was minted.
				'expires'    => 0,
				'client'     => array(
					'name'  => '',
					'phone' => '',
					'email' => '',
				),
				'monday'     => array(
					'item'  => '',
					'board' => '',
				),
				'opened'     => 0,        // First time the link was opened.
				'activity'   => 0,        // Last change to the draft.
				'step'       => '',       // Last screen the customer was on.
				'far'        => '',       // Furthest screen they ever opened.
				'reminders'  => array(),  // r1 | r2 | g => unix.
				'submitted'  => 0,
				'applied'    => 0,
				'report'     => array(),  // The apply report, one row per target.
				'existing'   => '',       // The customer's current site, if any.
			)
		);
	}

	/**
	 * Persist the record. Never autoloaded: it carries no front-end value.
	 *
	 * @param array<string,mixed> $state The record.
	 */
	public static function save_state( array $state ): void {
		update_option( self::OPTION, $state, false );
	}

	/**
	 * Change a few keys and save.
	 *
	 * @param array<string,mixed> $patch Keys to change.
	 * @return array<string,mixed> The record after.
	 */
	public static function patch_state( array $patch ): array {
		$state = array_merge( self::state(), $patch );
		self::save_state( $state );

		return $state;
	}

	/**
	 * Settings the team fills once on the base site: keys the questionnaire
	 * uses to talk to Monday and to the writing model. Never theme mods,
	 * which the Customizer would export.
	 *
	 * @return array<string,string>
	 */
	public static function settings(): array {
		$saved = get_option( self::SETTINGS );

		return wp_parse_args(
			is_array( $saved ) ? $saved : array(),
			array(
				'monday_token' => '',
				'monday_board' => '',
				'claude_key'   => '',
			)
		);
	}

	/* ---------------------------------------------------------------- token */

	/**
	 * Mint an invitation. A new token replaces the old one; the answers
	 * already given stay, so a re-issued link picks up where it stopped.
	 *
	 * @param array<string,string> $client name | phone | email.
	 * @param array<string,string> $monday item | board.
	 * @return string The full link.
	 */
	public static function invite( array $client, array $monday = array() ): string {
		$token = bin2hex( random_bytes( 20 ) ); // 40 url-safe characters.
		$state = self::state();
		$fresh = in_array( $state['status'], array( 'none', 'cancelled', 'applied' ), true );

		$state['status']     = 'draft';
		$state['token_hash'] = self::hash( $token );
		$state['link']       = self::url( $token );
		$state['created']    = time();
		$state['expires']    = time() + self::TTL_DAYS * DAY_IN_SECONDS;
		$state['client']     = array(
			'name'  => sanitize_text_field( $client['name'] ?? '' ),
			'phone' => sanitize_text_field( $client['phone'] ?? '' ),
			'email' => sanitize_email( $client['email'] ?? '' ),
		);
		$state['monday']     = array(
			'item'  => sanitize_text_field( $monday['item'] ?? $state['monday']['item'] ),
			'board' => sanitize_text_field( $monday['board'] ?? $state['monday']['board'] ),
		);
		$state['reminders']  = array();
		$state['opened']     = 0;

		if ( $fresh ) {
			$state['activity']  = 0;
			$state['step']      = '';
			$state['far']       = '';
			$state['submitted'] = 0;
			$state['applied']   = 0;
			$state['report']    = array();
			Draft::clear();
		}

		self::save_state( $state );

		return self::url( $token );
	}

	/**
	 * What is still theirs to do, read from the answers they gave.
	 *
	 * The questionnaire lets a shop get to the end without the things it
	 * does not have to hand — the clearing details above all. Saying so at
	 * the end, in their own terms, is the difference between a list of
	 * tasks and a vague sense that something is unfinished.
	 *
	 * @return array<int,string>
	 */
	public static function todo(): array {
		$v    = static function ( string $id ) {
			return Draft::value( $id );
		};
		$out  = array();
		$gw   = (string) $v( 'pay_gw' );
		$name = array(
			'cardcom' => 'Cardcom',
			'payplus' => 'PayPlus',
		);

		if ( 'none' === $gw ) {
			$out[] = __( 'Open a clearing account so the shop can take card payments. We send you the link to PayPlus — leave your details there and they come back to you. Everything else is already built and waiting for it.', 'oc-theme' );
		} elseif ( 'other' === $gw ) {
			$told = trim( (string) $v( 'pay_other' ) );

			$out[] = '' !== $told
				/* translators: %s: the clearing company they named. */
				? sprintf( __( 'Send us the details for %s and we connect it. It is not one of the two we install ourselves, so that part is by hand.', 'oc-theme' ), $told )
				: __( 'Tell us which clearing company you use and send us its details, and we connect it.', 'oc-theme' );
		} elseif ( isset( $name[ $gw ] ) && 'later' === (string) $v( 'pay_when' ) ) {
			$out[] = 'cardcom' === $gw
				? __( 'Fill in the Cardcom details — the terminal number, the API user and its password. The gateway is installed and waiting for them, and switched off until they are in.', 'oc-theme' )
				: __( 'Fill in the PayPlus details — the API key, the secret key and the payment-page id. The gateway is installed and waiting for them, and switched off until they are in.', 'oc-theme' );
		}

		// A shop with nothing on its shelves is not a shop, and this is the
		// one thing nobody else can do for them.
		$out[] = __( 'Gather your products — the names, the prices and a picture of each. That is the one thing we cannot do without you.', 'oc-theme' );

		return $out;
	}

	/**
	 * The link for a token.
	 *
	 * @param string $token The raw token.
	 */
	public static function url( string $token ): string {
		return home_url( '/start/' . rawurlencode( $token ) . '/' );
	}

	/**
	 * A token is stored only as a hash: the option is not the key.
	 *
	 * @param string $token The raw token.
	 */
	private static function hash( string $token ): string {
		return hash_hmac( 'sha256', $token, wp_salt( 'auth' ) );
	}

	/**
	 * Is this the current, unexpired, uncancelled token?
	 *
	 * @param string $token The raw token.
	 */
	public static function token_ok( string $token ): bool {
		$state = self::state();

		if ( '' === $token || '' === $state['token_hash'] ) {
			return false;
		}

		if ( 'cancelled' === $state['status'] || 'none' === $state['status'] ) {
			return false;
		}

		if ( $state['expires'] > 0 && time() > (int) $state['expires'] ) {
			return false;
		}

		return hash_equals( $state['token_hash'], self::hash( $token ) );
	}

	/**
	 * The provisioning secret: a constant in wp-config on the base site,
	 * cloned with it, known to the script that clones. The invite route
	 * answers to it and to nothing else.
	 *
	 * @param string $key What the request carried.
	 */
	public static function provision_ok( string $key ): bool {
		if ( ! defined( 'OC_PROVISION_KEY' ) || '' === (string) OC_PROVISION_KEY ) {
			return false;
		}

		return '' !== $key && hash_equals( (string) OC_PROVISION_KEY, $key );
	}

	/**
	 * The token on this request, wherever it rides: the route, a header,
	 * or a query parameter.
	 *
	 * @param \WP_REST_Request|null $req A REST request, when there is one.
	 */
	public static function request_token( ?\WP_REST_Request $req = null ): string {
		if ( $req ) {
			$t = (string) $req->get_header( 'x-oc-token' );

			if ( '' === $t ) {
				$t = (string) $req->get_param( 't' );
			}

			return sanitize_text_field( $t );
		}

		return sanitize_text_field( (string) get_query_var( self::QUERY ) );
	}

	/* ---------------------------------------------------------------- route */

	/**
	 * /start/<token>/ answers without a page behind it.
	 */
	public function route(): void {
		add_rewrite_rule( '^start/([A-Za-z0-9_-]{20,64})/?$', 'index.php?' . self::QUERY . '=$matches[1]', 'top' );

		if ( '2' !== (string) get_option( 'oc_onboard_rw' ) ) {
			flush_rewrite_rules();
			update_option( 'oc_onboard_rw', '2', false );
		}
	}

	/**
	 * The query var that carries the route.
	 *
	 * @param array<int,string> $vars Query vars.
	 * @return array<int,string>
	 */
	public function vars( array $vars ): array {
		$vars[] = self::QUERY;

		return $vars;
	}

	/**
	 * WordPress must not "fix" the address of a page it does not know.
	 *
	 * @param string|false $redirect Where canonical wants to go.
	 * @param string       $requested The address asked for.
	 * @return string|false
	 */
	public function no_canonical( $redirect, string $requested ) {
		return false !== strpos( $requested, '/start/' ) ? false : $redirect;
	}

	/**
	 * Whether this request is the questionnaire.
	 */
	public static function is_page(): bool {
		return '' !== (string) get_query_var( self::QUERY );
	}

	/* ---------------------------------------------------------------- misc */

	/**
	 * Note that the customer did something now.
	 *
	 * @param string $step The screen, when known.
	 */
	public static function touch( string $step = '' ): void {
		$state             = self::state();
		$state['activity'] = time();

		if ( ! $state['opened'] ) {
			$state['opened'] = time();
		}

		if ( '' !== $step ) {
			$state['step'] = $step;

			// How far they have been is not the same as where they are: a
			// customer who walks back to an early screen has still seen
			// everything up to here, and the menu says so.
			$order = Schema::screen_order();
			$here  = array_search( $step, $order, true );
			$was   = array_search( (string) ( $state['far'] ?? '' ), $order, true );

			if ( false !== $here && ( false === $was || $here > $was ) ) {
				$state['far'] = $step;
			}
		}

		self::save_state( $state );
	}

	/**
	 * Days since the link was minted, whole.
	 */
	public static function age_days(): int {
		$state = self::state();

		return $state['created'] ? (int) floor( ( time() - (int) $state['created'] ) / DAY_IN_SECONDS ) : 0;
	}
}
