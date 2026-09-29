<?php
/**
 * Theme settings → Onboarding: the team's side of the questionnaire.
 *
 * Where the link is minted by hand when Monday did not, copied, re-sent
 * or cancelled; where the answers so far can be read; where the apply
 * report lands, each row pointing at the screen it concerns; and where
 * the keys the questionnaire uses (Monday, the writing model) are kept
 * and, when the site is done, deleted.
 *
 * @package OC_Theme
 */

declare( strict_types = 1 );

namespace OC\Theme\Onboard;

use OC\Theme\Tabs;

defined( 'ABSPATH' ) || exit;

/**
 * The admin screen and the dashboard tile.
 */
final class Admin {

	const PAGE = 'oc-onboard';

	/**
	 * Hook in.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ), 59 );
		add_action( 'admin_post_oc_onboard_invite', array( $this, 'invite' ) );
		add_action( 'admin_post_oc_onboard_resend', array( $this, 'resend' ) );
		add_action( 'admin_post_oc_onboard_cancel', array( $this, 'cancel' ) );
		add_action( 'admin_post_oc_onboard_reapply', array( $this, 'reapply' ) );
		add_action( 'admin_post_oc_onboard_settings', array( $this, 'settings' ) );
		add_action( 'wp_dashboard_setup', array( $this, 'tile' ), 12 );
	}

	/**
	 * Submenu under theme settings.
	 */
	public function menu(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		add_submenu_page(
			Tabs::MENU,
			__( 'Onboarding questionnaire', 'oc-theme' ),
			__( 'Onboarding', 'oc-theme' ),
			'manage_woocommerce',
			self::PAGE,
			array( $this, 'screen' )
		);
	}

	/**
	 * The screen's address.
	 */
	public static function url(): string {
		return admin_url( 'admin.php?page=' . self::PAGE );
	}

	/**
	 * The words for a status.
	 *
	 * @param string $status Status key.
	 */
	public static function status_word( string $status ): string {
		$words = array(
			'none'      => __( 'No link yet', 'oc-theme' ),
			'draft'     => __( 'Waiting for the customer', 'oc-theme' ),
			'submitted' => __( 'Being applied', 'oc-theme' ),
			'applied'   => __( 'Done and applied', 'oc-theme' ),
			'cancelled' => __( 'Cancelled', 'oc-theme' ),
		);

		return $words[ $status ] ?? $status;
	}

	/* ------------------------------------------------------------ screen */

	/**
	 * The screen.
	 */
	public function screen(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$state  = Onboard::state();
		$prog   = Draft::progress();
		$values = Draft::values();
		$fields = Schema::fields();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- notices only.
		if ( isset( $_GET['oc_done'] ) ) {
			$msgs = array(
				'invited'  => __( 'The link is ready.', 'oc-theme' ),
				'sent'     => __( 'The invitation was sent.', 'oc-theme' ),
				'nomail'   => __( 'No customer email on the invitation, so nothing was sent. Copy the link instead.', 'oc-theme' ),
				'cancel'   => __( 'The link is cancelled.', 'oc-theme' ),
				'reapply'  => __( 'The answers were applied again.', 'oc-theme' ),
				'settings' => __( 'Settings saved.', 'oc-theme' ),
				'keygone'  => __( 'The AI key was deleted from this site.', 'oc-theme' ),
				'curtain'  => __( 'Saved.', 'oc-theme' ),
			);
			$key  = sanitize_key( wp_unslash( (string) $_GET['oc_done'] ) );

			if ( isset( $msgs[ $key ] ) ) {
				echo '<div class="notice notice-success"><p>' . esc_html( $msgs[ $key ] ) . '</p></div>';
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		?>
		<div class="wrap oc-onb-admin">
			<h1><?php esc_html_e( 'Onboarding questionnaire', 'oc-theme' ); ?></h1>
			<p><?php esc_html_e( 'The customer fills the questionnaire on this site, at a personal link. When they finish, the answers are written into the settings and the pages, and a report lands here and in your mail.', 'oc-theme' ); ?></p>

			<style>
				.oc-onb-admin .card { max-width: 900px; padding: 18px 22px; margin-top: 16px; }
				.oc-onb-admin .oc-onb-status { display: flex; gap: 24px; flex-wrap: wrap; align-items: center; }
				.oc-onb-admin .oc-onb-status b { font-size: 1.15em; }
				.oc-onb-admin .oc-onb-link { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; margin: 12px 0; }
				.oc-onb-admin .oc-onb-link input { min-width: 420px; direction: ltr; }
				.oc-onb-admin .oc-onb-actions form { display: inline; }
				.oc-onb-admin table.widefat td, .oc-onb-admin table.widefat th { vertical-align: top; }
				.oc-onb-admin .oc-r-applied { color: #1a7f37; } .oc-onb-admin .oc-r-check { color: #b45309; font-weight: 600; } .oc-onb-admin .oc-r-manual { color: #555; } .oc-onb-admin .oc-r-error { color: #b91c1c; font-weight: 600; }
			</style>

			<div class="card">
				<div class="oc-onb-status">
					<div><?php esc_html_e( 'Status', 'oc-theme' ); ?><br><b><?php echo esc_html( self::status_word( (string) $state['status'] ) ); ?></b></div>
					<?php if ( 'none' !== $state['status'] ) : ?>
						<div><?php esc_html_e( 'Customer', 'oc-theme' ); ?><br><b><?php echo esc_html( (string) $state['client']['name'] ); ?></b> <?php echo esc_html( (string) $state['client']['phone'] ); ?> <?php echo esc_html( (string) $state['client']['email'] ); ?></div>
						<div><?php esc_html_e( 'Answered', 'oc-theme' ); ?><br><b><?php echo esc_html( $prog['answered'] . ' / ' . $prog['total'] ); ?></b></div>
						<div><?php esc_html_e( 'Last activity', 'oc-theme' ); ?><br><b><?php echo esc_html( $state['activity'] ? self::ago( (int) $state['activity'] ) : __( 'Not opened yet', 'oc-theme' ) ); ?></b></div>
						<div><?php esc_html_e( 'Link valid until', 'oc-theme' ); ?><br><b><?php echo esc_html( $state['expires'] ? wp_date( 'd/m/Y', (int) $state['expires'] ) : '—' ); ?></b></div>
					<?php endif; ?>
				</div>

				<?php if ( in_array( $state['status'], array( 'draft', 'submitted', 'applied' ), true ) && $state['link'] ) : ?>
					<div class="oc-onb-link">
						<input type="text" readonly value="<?php echo esc_attr( (string) $state['link'] ); ?>" class="regular-text" onclick="this.select()" />
						<button type="button" class="button" onclick="navigator.clipboard.writeText(this.previousElementSibling.value);this.textContent='<?php echo esc_js( __( 'Copied', 'oc-theme' ) ); ?>'"><?php esc_html_e( 'Copy', 'oc-theme' ); ?></button>
						<a class="button" href="<?php echo esc_url( (string) $state['link'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open', 'oc-theme' ); ?></a>
					</div>
					<p class="oc-onb-actions">
						<?php self::action_button( 'oc_onboard_resend', __( 'Send the link by email', 'oc-theme' ) ); ?>
						<?php if ( 'applied' === $state['status'] ) : ?>
							<?php self::action_button( 'oc_onboard_reapply', __( 'Apply the answers again', 'oc-theme' ) ); ?>
						<?php endif; ?>
						<?php self::action_button( 'oc_onboard_cancel', __( 'Cancel the link', 'oc-theme' ), true ); ?>
					</p>
				<?php endif; ?>

				<h2><?php echo esc_html( 'none' === $state['status'] || 'cancelled' === $state['status'] ? __( 'Create the link', 'oc-theme' ) : __( 'Issue a new link', 'oc-theme' ) ); ?></h2>
				<p class="description"><?php esc_html_e( 'Usually Monday does this when the site is cloned. Do it here when it did not, or to replace the link. The answers already given stay.', 'oc-theme' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="oc_onboard_invite" />
					<?php wp_nonce_field( 'oc_onboard_invite' ); ?>
					<table class="form-table" role="presentation">
						<tr><th scope="row"><label for="oc-onb-name"><?php esc_html_e( 'Customer name', 'oc-theme' ); ?></label></th><td><input type="text" id="oc-onb-name" name="name" class="regular-text" value="<?php echo esc_attr( (string) $state['client']['name'] ); ?>" /></td></tr>
						<tr><th scope="row"><label for="oc-onb-phone"><?php esc_html_e( 'Mobile', 'oc-theme' ); ?></label></th><td><input type="text" id="oc-onb-phone" name="phone" class="regular-text" dir="ltr" value="<?php echo esc_attr( (string) $state['client']['phone'] ); ?>" /></td></tr>
						<tr><th scope="row"><label for="oc-onb-email"><?php esc_html_e( 'Email', 'oc-theme' ); ?></label></th><td><input type="email" id="oc-onb-email" name="email" class="regular-text" dir="ltr" value="<?php echo esc_attr( (string) $state['client']['email'] ); ?>" /></td></tr>
						<tr><th scope="row"></th><td><label><input type="checkbox" name="send" value="1" checked /> <?php esc_html_e( 'Send the link by email now', 'oc-theme' ); ?></label></td></tr>
					</table>
					<?php submit_button( __( 'Create the link', 'oc-theme' ), 'primary', 'submit', false ); ?>
				</form>
			</div>

			<?php if ( $state['report'] ) : ?>
				<div class="card">
					<h2><?php esc_html_e( 'Apply report', 'oc-theme' ); ?></h2>
					<p class="description"><?php echo esc_html( sprintf( /* translators: %s: date */ __( 'Applied on %s. Rows marked "check" need a human eye; "left as is" means someone changed that setting by hand after an earlier apply, so it was not touched.', 'oc-theme' ), wp_date( 'd/m/Y H:i', (int) $state['applied'] ) ) ); ?></p>
					<?php self::report_table( (array) $state['report'] ); ?>
				</div>
			<?php endif; ?>

			<?php if ( $values ) : ?>
				<div class="card">
					<h2><?php esc_html_e( 'The answers so far', 'oc-theme' ); ?></h2>
					<table class="widefat striped">
						<tbody>
						<?php foreach ( $fields as $id => $f ) : ?>
							<?php
							if ( ! array_key_exists( $id, $values ) || ! Schema::shown( $id, $values ) ) {
								continue;
							}
							?>
							<tr>
								<th scope="row" style="width:34%"><?php echo esc_html( (string) $f['label'] ); ?></th>
								<td><?php echo wp_kses_post( self::show_value( $f, $values[ $id ] ) ); ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>

			<?php Curtain::card(); ?>

			<div class="card">
				<h2><?php esc_html_e( 'Keys', 'oc-theme' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Filled once on the base site and cloned with it. The AI key is deleted from a customer site when the apply finishes; delete it here too when the site is handed over.', 'oc-theme' ); ?></p>
				<?php $s = Onboard::settings(); ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="oc_onboard_settings" />
					<?php wp_nonce_field( 'oc_onboard_settings' ); ?>
					<table class="form-table" role="presentation">
						<tr><th scope="row"><label for="oc-onb-monday"><?php esc_html_e( 'Monday API token', 'oc-theme' ); ?></label></th><td><input type="password" id="oc-onb-monday" name="monday_token" class="regular-text" dir="ltr" value="<?php echo esc_attr( $s['monday_token'] ); ?>" autocomplete="off" /></td></tr>
						<tr><th scope="row"><label for="oc-onb-board"><?php esc_html_e( 'Monday board id', 'oc-theme' ); ?></label></th><td><input type="text" id="oc-onb-board" name="monday_board" class="regular-text" dir="ltr" value="<?php echo esc_attr( $s['monday_board'] ); ?>" /></td></tr>
						<tr><th scope="row"><label for="oc-onb-claude"><?php esc_html_e( 'Claude API key', 'oc-theme' ); ?></label></th><td><input type="password" id="oc-onb-claude" name="claude_key" class="regular-text" dir="ltr" value="<?php echo esc_attr( $s['claude_key'] ); ?>" autocomplete="off" /> <?php echo $s['claude_key'] ? '<label style="margin-inline-start:12px"><input type="checkbox" name="delete_claude" value="1" /> ' . esc_html__( 'Delete the key', 'oc-theme' ) . '</label>' : ''; ?></td></tr>
					</table>
					<?php submit_button( __( 'Save keys', 'oc-theme' ), 'secondary', 'submit', false ); ?>
				</form>
				<p class="description" style="margin-top:14px"><?php echo esc_html( defined( 'OC_PROVISION_KEY' ) && OC_PROVISION_KEY ? __( 'The provisioning secret is set in wp-config.php.', 'oc-theme' ) : __( 'OC_PROVISION_KEY is not defined in wp-config.php: the invite route is closed until it is.', 'oc-theme' ) ); ?></p>
			</div>
		</div>
		<?php
	}

	/**
	 * A one-button form.
	 *
	 * @param string $action  admin-post action.
	 * @param string $label   Button words.
	 * @param bool   $confirm Ask first.
	 */
	private static function action_button( string $action, string $label, bool $confirm = false ): void {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"<?php echo $confirm ? ' onsubmit="return confirm(\'' . esc_js( __( 'Cancel the link? The customer will not be able to open it. The answers stay.', 'oc-theme' ) ) . '\')"' : ''; ?>>
			<input type="hidden" name="action" value="<?php echo esc_attr( $action ); ?>" />
			<?php wp_nonce_field( $action ); ?>
			<button type="submit" class="button"><?php echo esc_html( $label ); ?></button>
		</form>
		<?php
	}

	/**
	 * The report as a table.
	 *
	 * @param array<int,array<string,string>> $report Rows.
	 */
	public static function report_table( array $report ): void {
		$words = array(
			'applied' => __( 'Applied', 'oc-theme' ),
			'check'   => __( 'Check', 'oc-theme' ),
			'manual'  => __( 'Left as is', 'oc-theme' ),
			'skipped' => __( 'Skipped', 'oc-theme' ),
			'error'   => __( 'Error', 'oc-theme' ),
		);

		usort(
			$report,
			static function ( array $a, array $b ): int {
				$rank = array(
					'error'   => 0,
					'check'   => 1,
					'manual'  => 2,
					'skipped' => 3,
					'applied' => 4,
				);

				return ( $rank[ $a['result'] ] ?? 9 ) <=> ( $rank[ $b['result'] ] ?? 9 );
			}
		);
		?>
		<table class="widefat striped">
			<thead><tr><th><?php esc_html_e( 'Field', 'oc-theme' ); ?></th><th><?php esc_html_e( 'Target', 'oc-theme' ); ?></th><th><?php esc_html_e( 'Result', 'oc-theme' ); ?></th><th><?php esc_html_e( 'Note', 'oc-theme' ); ?></th></tr></thead>
			<tbody>
			<?php foreach ( $report as $row ) : ?>
				<tr>
					<td><?php echo esc_html( (string) $row['label'] ); ?></td>
					<td><code><?php echo esc_html( (string) $row['target'] ); ?></code></td>
					<td class="oc-r-<?php echo esc_attr( (string) $row['result'] ); ?>"><?php echo esc_html( $words[ $row['result'] ] ?? (string) $row['result'] ); ?></td>
					<td><?php echo esc_html( (string) $row['note'] ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * A value, readable.
	 *
	 * @param array<string,mixed> $f     Field.
	 * @param mixed               $value Value.
	 */
	private static function show_value( array $f, $value ): string {
		switch ( (string) $f['type'] ) {
			case 'choice':
				return esc_html( (string) ( $f['options'][ (string) $value ] ?? (string) $value ) );

			case 'checks':
				$out = array();

				foreach ( (array) $value as $k ) {
					$out[] = (string) ( $f['options'][ $k ] ?? $k );
				}

				return esc_html( implode( ', ', $out ) );

			case 'consent':
				return esc_html( $value ? __( 'Confirmed', 'oc-theme' ) : __( 'Not confirmed', 'oc-theme' ) );

			case 'file':
				return is_array( $value ) && ! empty( $value['url'] ) ? '<a href="' . esc_url( (string) $value['url'] ) . '" target="_blank" rel="noopener">' . esc_html( (string) $value['name'] ) . '</a>' : '';

			case 'hours':
				return nl2br( esc_html( Apply::hours_text( (array) $value ) ) );

			case 'repeater':
				$rows = array();

				foreach ( (array) $value as $row ) {
					$rows[] = esc_html( implode( ' · ', array_filter( array_map( static fn( $v ) => is_array( $v ) ? implode( ', ', $v ) : (string) $v, (array) $row ) ) ) );
				}

				return implode( '<br>', $rows );

			case 'textarea':
				return nl2br( esc_html( (string) $value ) );

			default:
				return esc_html( is_scalar( $value ) ? (string) $value : '' );
		}
	}

	/**
	 * "3 hours ago".
	 *
	 * @param int $ts Unix.
	 */
	private static function ago( int $ts ): string {
		return sprintf( /* translators: %s: human time diff */ __( '%s ago', 'oc-theme' ), human_time_diff( $ts, time() ) );
	}

	/* ------------------------------------------------------------ actions */

	/**
	 * Mint by hand.
	 */
	public function invite(): void {
		$this->guard( 'oc_onboard_invite' );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in guard().
		$url = Onboard::invite(
			array(
				'name'  => sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) ),
				'phone' => sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) ),
				'email' => sanitize_email( wp_unslash( $_POST['email'] ?? '' ) ),
			)
		);

		$done = 'invited';

		if ( ! empty( $_POST['send'] ) ) {
			$done = Mail::invitation( $url ) ? 'sent' : 'nomail';
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$this->back( $done );
	}

	/**
	 * Mail the link again.
	 */
	public function resend(): void {
		$this->guard( 'oc_onboard_resend' );

		$link = (string) Onboard::state()['link'];

		$this->back( $link && Mail::invitation( $link ) ? 'sent' : 'nomail' );
	}

	/**
	 * Close the door.
	 */
	public function cancel(): void {
		$this->guard( 'oc_onboard_cancel' );

		Onboard::patch_state( array( 'status' => 'cancelled' ) );

		$this->back( 'cancel' );
	}

	/**
	 * Run the engine again over the answers as they stand.
	 */
	public function reapply(): void {
		$this->guard( 'oc_onboard_reapply' );

		$report = ( new Apply() )->run();

		Onboard::patch_state(
			array(
				'status'  => 'applied',
				'applied' => time(),
				'report'  => $report,
			)
		);

		$this->back( 'reapply' );
	}

	/**
	 * The keys.
	 */
	public function settings(): void {
		$this->guard( 'oc_onboard_settings' );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in guard().
		$s    = array(
			'monday_token' => sanitize_text_field( wp_unslash( $_POST['monday_token'] ?? '' ) ),
			'monday_board' => sanitize_text_field( wp_unslash( $_POST['monday_board'] ?? '' ) ),
			'claude_key'   => sanitize_text_field( wp_unslash( $_POST['claude_key'] ?? '' ) ),
		);
		$gone = ! empty( $_POST['delete_claude'] );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( $gone ) {
			$s['claude_key'] = '';
		}

		update_option( Onboard::SETTINGS, $s, false );

		$this->back( $gone ? 'keygone' : 'settings' );
	}

	/**
	 * Capability and nonce, or die.
	 *
	 * @param string $action Nonce action.
	 */
	private function guard( string $action ): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die();
		}

		check_admin_referer( $action );
	}

	/**
	 * Back to the screen with a word.
	 *
	 * @param string $done Notice key.
	 */
	private function back( string $done ): void {
		wp_safe_redirect( add_query_arg( 'oc_done', $done, self::url() ) );
		exit;
	}

	/* ------------------------------------------------------------ tile */

	/**
	 * A dashboard box while the questionnaire is alive.
	 */
	public function tile(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$state = Onboard::state();

		if ( 'none' === $state['status'] || 'cancelled' === $state['status'] ) {
			return;
		}

		wp_add_dashboard_widget( 'oc_dash_onboard', __( 'Onboarding questionnaire', 'oc-theme' ), array( $this, 'tile_body' ), null, null, 'normal', 'high' );
	}

	/**
	 * The box.
	 */
	public function tile_body(): void {
		$state = Onboard::state();
		$prog  = Draft::progress();
		$sum   = Rest::report_summary( (array) $state['report'] );

		echo '<p><b>' . esc_html( self::status_word( (string) $state['status'] ) ) . '</b> · ' . esc_html( (string) $state['client']['name'] ) . '</p>';
		echo '<p>' . esc_html( sprintf( /* translators: 1: answered, 2: total */ __( 'Answered %1$d of %2$d', 'oc-theme' ), $prog['answered'], $prog['total'] ) ) . '</p>';

		if ( 'applied' === $state['status'] && ( $sum['check'] || $sum['error'] ) ) {
			echo '<p>' . esc_html( sprintf( /* translators: 1: to check, 2: errors */ __( '%1$d rows to check, %2$d errors', 'oc-theme' ), $sum['check'], $sum['error'] ) ) . '</p>';
		}

		echo '<p><a class="button" href="' . esc_url( self::url() ) . '">' . esc_html__( 'Open', 'oc-theme' ) . '</a></p>';
	}
}
