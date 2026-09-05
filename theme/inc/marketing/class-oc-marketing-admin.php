<?php
/**
 * Settings ← Marketing: the IDs go in, and it works.
 *
 * One card per network, a consent choice, what to track, a test button
 * that fires a server event and shows the network's answer, and the log
 * of the last calls the server made.
 *
 * @package OC\Theme
 */

declare( strict_types = 1 );

namespace OC\Theme;

use OC\Theme\Marketing\Dispatch;
use OC\Theme\Marketing\Events;
use OC\Theme\Marketing\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The marketing screen.
 */
final class Marketing_Admin {

	const NONCE = 'ocmkt';

	/**
	 * Hooks.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_post_ocmkt_save', array( $this, 'handle_save' ) );
		add_action( 'wp_ajax_ocmkt_test', array( $this, 'ajax_test' ) );
	}

	/**
	 * The room's address.
	 */
	public static function url(): string {
		return admin_url( 'options-general.php?page=oc-marketing' );
	}

	/**
	 * Under Settings.
	 */
	public function menu(): void {
		add_options_page( __( 'Marketing', 'oc-theme' ), __( 'Marketing', 'oc-theme' ), 'manage_options', 'oc-marketing', array( $this, 'render' ) );
	}

	/**
	 * The screen.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'oc-theme' ) );
		}

		$s   = Settings::get();
		$msg = isset( $_GET['ocmkt_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['ocmkt_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a notice, nothing acted on.

		$field = static function ( string $name, string $value, string $label, string $hint = '', string $placeholder = '' ): void {
			echo '<label><span>' . esc_html( $label ) . '</span><input type="text" class="ltr" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" placeholder="' . esc_attr( $placeholder ) . '" autocomplete="off" />';

			if ( '' !== $hint ) {
				echo '<small>' . esc_html( $hint ) . '</small>';
			}

			echo '</label>';
		};

		echo '<div class="wrap ocmkt"><h1>' . esc_html__( 'Marketing', 'oc-theme' ) . '</h1>';

		if ( '' !== $msg ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
		}
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="ocmkt_save" />
			<?php wp_nonce_field( self::NONCE ); ?>

			<div class="ocmkt-card ocmkt-card--switch">
				<label class="ocmkt-switch"><input type="checkbox" name="enabled" value="1" <?php checked( $s['enabled'] ); ?> /><strong><?php esc_html_e( 'Tracking is on', 'oc-theme' ); ?></strong></label>
				<p class="description"><?php esc_html_e( 'Enter the IDs below and switch on. Every event the networks expect — product views, categories, searches, add to cart, checkout, payment details, purchases with their value, sign-ups, logins, newsletter — is sent from the browser and, where it matters, from the server too, with one shared id so nothing counts twice.', 'oc-theme' ); ?></p>
				<?php if ( $s['enabled'] && ! Settings::any( $s ) ) : ?>
					<p class="description ocmkt-warn"><?php esc_html_e( 'On, but no network has an ID yet — nothing is sent.', 'oc-theme' ); ?></p>
				<?php endif; ?>
			</div>

			<div class="ocmkt-card">
				<h2><?php esc_html_e( 'Other marketing tags', 'oc-theme' ); ?></h2>
				<label class="ocmkt-check"><input type="checkbox" name="thirdparty[flashy]" value="1" <?php checked( $s['thirdparty']['flashy'] ); ?> /><span><?php esc_html_e( 'Hold the Flashy tag back until the page has drawn', 'oc-theme' ); ?></span></label>
				<p class="description"><?php esc_html_e( 'Flashy fetches its library while the page is still painting, and running it is the heaviest thing on the phone at that moment. With this on, the tag is fetched once the page has finished and the visitor is idle — or the instant they touch anything. Nothing is dropped: the events queue up and are sent as soon as it arrives.', 'oc-theme' ); ?></p>
				<p class="description"><?php esc_html_e( 'The cost is a visitor who opens the page and leaves before the wait is out without touching it — their page view is never sent.', 'oc-theme' ); ?></p>
				<div class="ocmkt-grid">
					<?php $field( 'thirdparty[wait]', (string) $s['thirdparty']['wait'], __( 'Wait before loading it anyway (milliseconds)', 'oc-theme' ), __( 'Counted from the moment the page finishes. Any touch, scroll or key loads it at once and this never runs. Below about 6000 the tag lands back inside the window a speed test measures and the whole thing buys nothing.', 'oc-theme' ), '7000' ); ?>
				</div>
			</div>

			<div class="ocmkt-card">
				<h2><?php esc_html_e( 'Meta (Facebook / Instagram)', 'oc-theme' ); ?></h2>
				<div class="ocmkt-grid">
					<?php $field( 'fb[pixel]', $s['fb']['pixel'], __( 'Pixel ID', 'oc-theme' ), '', '1234567890123456' ); ?>
					<?php $field( 'fb[token]', $s['fb']['token'], __( 'Conversions API access token', 'oc-theme' ), __( 'Events Manager → the pixel → Settings → Conversions API → Generate access token.', 'oc-theme' ) ); ?>
					<?php $field( 'fb[test]', $s['fb']['test'], __( 'Test event code (optional)', 'oc-theme' ), __( 'From Events Manager → Test events. Server events then show up there. Clear it when done.', 'oc-theme' ), 'TEST12345' ); ?>
				</div>
			</div>

			<div class="ocmkt-card">
				<h2><?php esc_html_e( 'Google', 'oc-theme' ); ?></h2>
				<div class="ocmkt-grid">
					<?php $field( 'ga4[id]', $s['ga4']['id'], __( 'GA4 measurement ID', 'oc-theme' ), '', 'G-XXXXXXXXXX' ); ?>
					<?php $field( 'ga4[secret]', $s['ga4']['secret'], __( 'GA4 API secret (optional)', 'oc-theme' ), __( 'Admin → Data streams → the stream → Measurement Protocol API secrets. Lets the server report a purchase the browser never did.', 'oc-theme' ) ); ?>
					<?php $field( 'gads[id]', $s['gads']['id'], __( 'Google Ads conversion ID', 'oc-theme' ), '', 'AW-123456789' ); ?>
					<?php $field( 'gads[label]', $s['gads']['label'], __( 'Purchase conversion label', 'oc-theme' ), __( 'From the conversion action’s tag setup: the part after the slash.', 'oc-theme' ), 'AbCdEfGhIj' ); ?>
					<?php $field( 'gtm[id]', $s['gtm']['id'], __( 'Tag Manager container (optional)', 'oc-theme' ), __( 'Every event is also pushed to the dataLayer as oc_<event>, for any extra tag.', 'oc-theme' ), 'GTM-XXXXXXX' ); ?>
				</div>
			</div>

			<div class="ocmkt-card">
				<h2><?php esc_html_e( 'TikTok', 'oc-theme' ); ?></h2>
				<div class="ocmkt-grid">
					<?php $field( 'tiktok[pixel]', $s['tiktok']['pixel'], __( 'Pixel ID', 'oc-theme' ), '', 'CXXXXXXXXXXXXXXXXX' ); ?>
					<?php $field( 'tiktok[token]', $s['tiktok']['token'], __( 'Events API access token', 'oc-theme' ), __( 'Events Manager → the pixel → Settings → Events API → Generate access token.', 'oc-theme' ) ); ?>
				</div>
			</div>

			<div class="ocmkt-card">
				<h2><?php esc_html_e( 'Consent and events', 'oc-theme' ); ?></h2>
				<div class="ocmkt-grid">
					<label>
						<span><?php esc_html_e( 'Cookie consent', 'oc-theme' ); ?></span>
						<select name="consent">
							<option value="auto" <?php selected( 'auto', $s['consent'] ); ?>><?php esc_html_e( 'Automatic — opt-in in Europe, opt-out elsewhere', 'oc-theme' ); ?></option>
							<option value="optout" <?php selected( 'optout', $s['consent'] ); ?>><?php esc_html_e( 'Banner; tags run unless declined', 'oc-theme' ); ?></option>
							<option value="optin" <?php selected( 'optin', $s['consent'] ); ?>><?php esc_html_e( 'Banner; nothing runs until accepted', 'oc-theme' ); ?></option>
							<option value="off" <?php selected( 'off', $s['consent'] ); ?>><?php esc_html_e( 'No banner, no consent layer', 'oc-theme' ); ?></option>
						</select>
						<small><?php esc_html_e( 'Google Consent Mode v2 is always wired; the choice only sets what a visitor starts with.', 'oc-theme' ); ?></small>
					</label>
					<label class="ocmkt-check"><input type="checkbox" name="events[scroll]" value="1" <?php checked( $s['events']['scroll'] ); ?> /><span><?php esc_html_e( 'Scroll depth (25 / 50 / 75 / 100%)', 'oc-theme' ); ?></span></label>
					<label class="ocmkt-check"><input type="checkbox" name="events[video]" value="1" <?php checked( $s['events']['video'] ); ?> /><span><?php esc_html_e( 'Video start and complete', 'oc-theme' ); ?></span></label>
					<label class="ocmkt-check"><input type="checkbox" name="events[search]" value="1" <?php checked( $s['events']['search'] ); ?> /><span><?php esc_html_e( 'Searches', 'oc-theme' ); ?></span></label>
				</div>
			</div>

			<p class="submit"><button type="submit" class="button button-primary button-hero"><?php esc_html_e( 'Save marketing settings', 'oc-theme' ); ?></button></p>
		</form>

		<div class="ocmkt-card">
			<h2><?php esc_html_e( 'Server events', 'oc-theme' ); ?></h2>
			<p class="description"><?php esc_html_e( 'The last calls the server made to Meta, TikTok and GA4, and what they answered. “Send a test event” fires a PageView through the server with the saved keys.', 'oc-theme' ); ?></p>
			<p><button type="button" class="button" data-ocmkt-test><?php esc_html_e( 'Send a test event', 'oc-theme' ); ?></button> <span data-ocmkt-test-out></span></p>
			<?php $log = Dispatch::recent(); ?>
			<?php if ( $log ) : ?>
				<table class="widefat striped">
					<thead><tr><th><?php esc_html_e( 'When', 'oc-theme' ); ?></th><th><?php esc_html_e( 'Network', 'oc-theme' ); ?></th><th><?php esc_html_e( 'Event', 'oc-theme' ); ?></th><th><?php esc_html_e( 'Status', 'oc-theme' ); ?></th><th><?php esc_html_e( 'Answer', 'oc-theme' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( array_slice( $log, 0, 30 ) as $row ) : ?>
						<tr>
							<td><?php echo esc_html( wp_date( 'j.n H:i', (int) $row['t'] ) ); ?></td>
							<td><?php echo esc_html( (string) $row['net'] ); ?></td>
							<td><?php echo esc_html( (string) $row['ev'] ); ?></td>
							<td><?php echo esc_html( (string) $row['code'] ); ?></td>
							<td class="ltr"><code><?php echo esc_html( (string) $row['text'] ); ?></code></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php else : ?>
				<p><?php esc_html_e( 'Nothing sent yet.', 'oc-theme' ); ?></p>
			<?php endif; ?>
		</div>
		<style>
			.ocmkt .ltr { direction: ltr; text-align: left; }
			.ocmkt-card { background: #fff; border: 1px solid #dcdcde; border-radius: 8px; padding: 18px 20px; margin: 16px 0; max-width: 1000px; }
			.ocmkt-card h2 { margin: 0 0 10px; font-size: 1.1em; }
			.ocmkt-card--switch { border-color: #2271b1; }
			.ocmkt-switch { display: flex; align-items: center; gap: 10px; font-size: 1.05em; }
			.ocmkt-warn { color: #8a4b00; background: #fcf9e8; border-inline-start: 3px solid #dba617; padding: 8px 10px; }
			.ocmkt-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 14px 18px; }
			.ocmkt-grid label > span { display: block; font-weight: 600; margin-bottom: 4px; }
			.ocmkt-grid label input[type="text"], .ocmkt-grid label select { width: 100%; }
			.ocmkt-grid small { display: block; color: #646970; margin-top: 3px; }
			.ocmkt-check { display: flex; align-items: center; gap: 8px; align-self: end; }
			.ocmkt-check span { font-weight: 400 !important; }
			.ocmkt code { background: none; padding: 0; font-size: 12px; }
		</style>
		<script>
		( function () {
			var btn = document.querySelector( '[data-ocmkt-test]' );
			if ( ! btn ) { return; }
			btn.addEventListener( 'click', function () {
				var out = document.querySelector( '[data-ocmkt-test-out]' );
				var body = new FormData();
				body.append( 'action', 'ocmkt_test' );
				body.append( 'nonce', <?php echo wp_json_encode( wp_create_nonce( self::NONCE ) ); ?> );
				out.textContent = '…';
				fetch( <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>, { method: 'POST', credentials: 'same-origin', body: body } )
					.then( function ( r ) { return r.json(); } )
					.then( function ( r ) { out.textContent = r && r.success ? r.data : 'error'; setTimeout( function () { location.reload(); }, 1200 ); } );
			} );
		}() );
		</script>
		</div>
		<?php
	}

	/**
	 * Save.
	 */
	public function handle_save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'oc-theme' ) );
		}

		check_admin_referer( self::NONCE );

		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- check_admin_referer() ran; Settings::normalize() types and bounds every field.
		$raw = array(
			'enabled'    => ! empty( $_POST['enabled'] ),
			'consent'    => sanitize_key( (string) wp_unslash( $_POST['consent'] ?? 'auto' ) ),
			'fb'         => array_map( 'sanitize_text_field', array_map( 'wp_unslash', (array) ( $_POST['fb'] ?? array() ) ) ),
			'ga4'        => array_map( 'sanitize_text_field', array_map( 'wp_unslash', (array) ( $_POST['ga4'] ?? array() ) ) ),
			'gads'       => array_map( 'sanitize_text_field', array_map( 'wp_unslash', (array) ( $_POST['gads'] ?? array() ) ) ),
			'gtm'        => array_map( 'sanitize_text_field', array_map( 'wp_unslash', (array) ( $_POST['gtm'] ?? array() ) ) ),
			'tiktok'     => array_map( 'sanitize_text_field', array_map( 'wp_unslash', (array) ( $_POST['tiktok'] ?? array() ) ) ),
			'thirdparty' => array(
				'flashy' => ! empty( $_POST['thirdparty']['flashy'] ),
				'wait'   => (int) ( $_POST['thirdparty']['wait'] ?? 7000 ),
			),
			'events'     => array(
				'scroll' => ! empty( $_POST['events']['scroll'] ),
				'video'  => ! empty( $_POST['events']['video'] ),
				'search' => ! empty( $_POST['events']['search'] ),
			),
		);
		// phpcs:enable

		Settings::save( $raw );

		wp_safe_redirect( add_query_arg( 'ocmkt_msg', rawurlencode( __( 'Marketing settings saved.', 'oc-theme' ) ), self::url() ) );
		exit;
	}

	/**
	 * A PageView through the server, right now, so the log shows the answer.
	 */
	public function ajax_test(): void {
		if ( ! current_user_can( 'manage_options' ) || ! check_ajax_referer( self::NONCE, 'nonce', false ) ) {
			wp_send_json_error( __( 'Not allowed.', 'oc-theme' ) );
		}

		$user = wp_get_current_user();

		Dispatch::send(
			array(
				'name'   => 'PageView',
				'data'   => array(),
				'id'     => Events::id( 'test' ),
				'user'   => array( 'em' => (string) $user->user_email ),
				'client' => Events::client(),
				'url'    => home_url( '/' ),
				'time'   => time(),
				'ga4'    => false,
			)
		);

		wp_send_json_success( __( 'Sent — see the log.', 'oc-theme' ) );
	}
}
