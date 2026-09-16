<?php
/**
 * Settings → Spam protection.
 *
 * @package OC_Theme
 */

declare( strict_types = 1 );

namespace OC\Theme;

defined( 'ABSPATH' ) || exit;

/**
 * The screen: Turnstile keys, which forms carry it, hourly limits.
 */
final class Guard_Admin {

	/**
	 * Hook in.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_post_oc_guard_save', array( $this, 'save' ) );
	}

	/**
	 * Under Settings.
	 */
	public function menu(): void {
		add_options_page( __( 'Spam protection', 'oc-theme' ), __( 'Spam protection', 'oc-theme' ), 'manage_options', 'oc-guard', array( $this, 'render' ) );
	}

	/**
	 * The screen.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'oc-theme' ) );
		}

		$s      = Guard::settings();
		$labels = Guard::labels();
		$keys   = '' !== $s['site'] && '' !== $s['secret'];
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Spam protection', 'oc-theme' ); ?></h1>
			<?php if ( isset( $_GET['saved'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- notice only. ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Saved.', 'oc-theme' ); ?></p></div>
			<?php endif; ?>
			<p class="description" style="max-width:720px;">
				<?php esc_html_e( 'Every public form already carries three quiet checks no visitor sees: a trap field robots fill in, a signed timestamp (a form sent under three seconds after it was printed was not filled by a person), and a cap on submissions per hour from one connection. Cloudflare Turnstile adds an invisible challenge on top — no pictures to solve — for the forms you switch it on for.', 'oc-theme' ); ?>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="oc_guard_save">
				<?php wp_nonce_field( 'oc_guard_save' ); ?>

				<h2><?php esc_html_e( 'Cloudflare Turnstile', 'oc-theme' ); ?></h2>
				<p class="description" style="max-width:720px;">
					<?php
					printf(
						/* translators: %s: link to the Cloudflare dashboard. */
						esc_html__( 'Free. Create a widget at %s (type: Managed, or Invisible), add this site\'s domains, and paste the two keys here.', 'oc-theme' ),
						'<a href="https://dash.cloudflare.com/?to=/:account/turnstile" target="_blank" rel="noopener">dash.cloudflare.com → Turnstile</a>'
					);
					?>
					<?php if ( $keys ) : ?>
						<strong><?php esc_html_e( 'Keys are in place.', 'oc-theme' ); ?></strong>
					<?php else : ?>
						<strong><?php esc_html_e( 'No keys yet — Turnstile stays off until both are saved.', 'oc-theme' ); ?></strong>
					<?php endif; ?>
				</p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="oc_guard_site"><?php esc_html_e( 'Site key', 'oc-theme' ); ?></label></th>
						<td><input type="text" id="oc_guard_site" name="site" class="regular-text code" value="<?php echo esc_attr( $s['site'] ); ?>" autocomplete="off"></td>
					</tr>
					<tr>
						<th scope="row"><label for="oc_guard_secret"><?php esc_html_e( 'Secret key', 'oc-theme' ); ?></label></th>
						<td>
							<input type="password" id="oc_guard_secret" name="secret" class="regular-text code" value="" autocomplete="new-password" placeholder="<?php echo esc_attr( '' !== $s['secret'] ? str_repeat( '•', 12 ) : '' ); ?>">
							<p class="description"><?php esc_html_e( 'Leave empty to keep the saved key. Type "clear" to remove it.', 'oc-theme' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Per form', 'oc-theme' ); ?></h2>
				<table class="widefat striped" style="max-width:760px;">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Form', 'oc-theme' ); ?></th>
							<th style="width:140px;"><?php esc_html_e( 'Turnstile', 'oc-theme' ); ?></th>
							<th style="width:220px;"><?php esc_html_e( 'Max per hour, per connection', 'oc-theme' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( Guard::FORMS as $form ) : ?>
							<tr>
								<td><?php echo esc_html( $labels[ $form ] ); ?></td>
								<td><label><input type="checkbox" name="ts[<?php echo esc_attr( $form ); ?>]" value="1" <?php checked( 1, $s['ts'][ $form ] ); ?>> <?php esc_html_e( 'On', 'oc-theme' ); ?></label></td>
								<td><input type="number" name="limits[<?php echo esc_attr( $form ); ?>]" min="0" max="1000" step="1" value="<?php echo esc_attr( (string) $s['limits'][ $form ] ); ?>" style="width:90px;"> <span class="description"><?php esc_html_e( '0 = no cap', 'oc-theme' ); ?></span></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<p class="description" style="max-width:720px;margin-top:8px;">
					<?php esc_html_e( 'Sign-in already limits code sends per phone and per connection on its own; the contact form block keeps its own hourly cap. The trap field and the timestamp are always on.', 'oc-theme' ); ?>
				</p>
				<?php submit_button(); ?>
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

		check_admin_referer( 'oc_guard_save' );

		$was = Guard::settings();
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked above.
		$post = wp_unslash( $_POST );
		// phpcs:enable

		$secret = trim( (string) ( $post['secret'] ?? '' ) );

		$out = array(
			'site'   => sanitize_text_field( (string) ( $post['site'] ?? '' ) ),
			'secret' => '' === $secret ? $was['secret'] : ( 'clear' === $secret ? '' : sanitize_text_field( $secret ) ),
			'ts'     => array(),
			'limits' => array(),
		);

		foreach ( Guard::FORMS as $form ) {
			$out['ts'][ $form ]     = empty( $post['ts'][ $form ] ) ? 0 : 1;
			$out['limits'][ $form ] = max( 0, min( 1000, (int) ( $post['limits'][ $form ] ?? 0 ) ) );
		}

		update_option( Guard::OPTION, $out, false );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'  => 'oc-guard',
					'saved' => '1',
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}
}
