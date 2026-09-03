<?php
/**
 * The newsletter block's back half: collecting addresses, and handing them over.
 *
 * @package OC_Blocks
 */

declare( strict_types = 1 );

namespace OC\Blocks;

defined( 'ABSPATH' ) || exit;

/**
 * Signups land in one option; the shop reads them under Tools and takes them
 * away as CSV — into whatever mailing tool it actually sends with.
 */
final class Newsletter {

	/**
	 * Where the addresses live: email => unix time of signup.
	 */
	const OPTION = 'oc_blocks_subscribers';

	/**
	 * A soft ceiling. A shop with more subscribers than this has long since
	 * moved the list into a real mailing tool.
	 */
	const CAP = 20000;

	/**
	 * Hook in.
	 */
	public function register(): void {
		add_action( 'wp_ajax_oc_blocks_subscribe', array( $this, 'subscribe' ) );
		add_action( 'wp_ajax_nopriv_oc_blocks_subscribe', array( $this, 'subscribe' ) );
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_post_oc_blocks_subs_csv', array( $this, 'csv' ) );
	}

	/**
	 * Take one address.
	 *
	 * Deliberately nonce-free: the form ships inside page HTML that is cached
	 * for longer than a nonce lives, and for a logged-out visitor a nonce is
	 * the same for everyone anyway. A honeypot field and a per-address
	 * throttle carry the abuse load instead.
	 */
	public function subscribe(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- see above.
		$trap  = isset( $_POST['website'] ) ? trim( (string) wp_unslash( $_POST['website'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- honeypot; only ever compared to the empty string.
		$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		// phpcs:enable

		// A human never sees the trap field. A bot that filled it hears
		// "thank you" and moves on, instead of trying harder.
		if ( '' !== $trap ) {
			wp_send_json_success();
		}

		if ( ! is_email( $email ) ) {
			wp_send_json_error( array( 'msg' => __( 'That does not look like an email address.', 'oc-blocks' ) ) );
		}

		$gate = 'oc_blocks_sub_' . md5( strtolower( $email ) );

		if ( false === get_transient( $gate ) ) {
			set_transient( $gate, 1, MINUTE_IN_SECONDS );

			$all = (array) get_option( self::OPTION, array() );

			if ( ! isset( $all[ $email ] ) && count( $all ) < self::CAP ) {
				$all[ $email ] = time();
				update_option( self::OPTION, $all, false );
			}
		}

		// The marketing layer hears about it — with an id the page's own
		// script shares, so the browser and the server count one signup.
		$event_id = 'sub_' . substr( str_replace( '-', '', wp_generate_uuid4() ), 0, 20 );
		do_action( 'oc_newsletter_subscribed', $email, $event_id );

		// Repeat signups and full lists all hear the same thing: an address
		// owner learns nothing about whether it was already known.
		wp_send_json_success( array( 'event_id' => $event_id ) );
	}

	/**
	 * The page under Tools.
	 */
	public function menu(): void {
		add_submenu_page(
			'tools.php',
			__( 'Newsletter signups', 'oc-blocks' ),
			__( 'Newsletter signups', 'oc-blocks' ),
			'manage_options',
			'oc-blocks-subs',
			array( $this, 'page' )
		);
	}

	/**
	 * The list, newest first, and the way out.
	 */
	public function page(): void {
		$all = (array) get_option( self::OPTION, array() );
		arsort( $all );

		$export = wp_nonce_url( admin_url( 'admin-post.php?action=oc_blocks_subs_csv' ), 'oc_blocks_subs_csv' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Newsletter signups', 'oc-blocks' ); ?></h1>
			<p>
				<?php
				/* translators: %s: how many addresses. */
				echo esc_html( sprintf( __( 'On the list: %s', 'oc-blocks' ), number_format_i18n( count( $all ) ) ) );
				?>
				<?php if ( ! empty( $all ) ) : ?>
					<a class="button" href="<?php echo esc_url( $export ); ?>"><?php esc_html_e( 'Download as CSV', 'oc-blocks' ); ?></a>
				<?php endif; ?>
			</p>
			<?php if ( ! empty( $all ) ) : ?>
				<table class="widefat striped" style="max-width:560px">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Email', 'oc-blocks' ); ?></th>
							<th><?php esc_html_e( 'Signed up', 'oc-blocks' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( array_slice( $all, 0, 500, true ) as $email => $when ) : ?>
							<tr>
								<td><?php echo esc_html( (string) $email ); ?></td>
								<td><?php echo esc_html( wp_date( (string) get_option( 'date_format' ), (int) $when ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<?php if ( count( $all ) > 500 ) : ?>
					<p class="description"><?php esc_html_e( 'Showing the newest 500 — the CSV holds everyone.', 'oc-blocks' ); ?></p>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Everyone, as CSV.
	 */
	public function csv(): void {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'oc_blocks_subs_csv' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'oc-blocks' ) );
		}

		$all = (array) get_option( self::OPTION, array() );
		arsort( $all );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=newsletter-' . gmdate( 'Y-m-d' ) . '.csv' );

		// The BOM, so Hebrew-locale Excel reads the file as UTF-8.
		echo "\xEF\xBB\xBF";
		echo "email,signed_up\n";

		foreach ( $all as $email => $when ) {
			echo '"' . str_replace( '"', '""', (string) $email ) . '",' . esc_html( gmdate( 'Y-m-d H:i', (int) $when ) ) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV field, quoted and doubled above.
		}

		exit;
	}
}
