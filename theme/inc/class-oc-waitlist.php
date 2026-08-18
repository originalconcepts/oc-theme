<?php
/**
 * Back-in-stock waitlist admin screen: every signup the notify popup
 * collected, across all products — with CSV export and row removal.
 *
 * @package OC_Theme
 */

declare( strict_types = 1 );

namespace OC\Theme;

defined( 'ABSPATH' ) || exit;

/**
 * The "waiting for stock" screen under WooCommerce.
 */
final class Waitlist {

	/**
	 * Hook in.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ), 60 );
		add_action( 'admin_post_oc_waitlist_export', array( $this, 'export' ) );
		add_action( 'admin_post_oc_waitlist_settings', array( $this, 'save_settings' ) );
		add_action( 'admin_post_oc_waitlist_test', array( $this, 'test_send' ) );
		add_action( 'woocommerce_product_set_stock_status', array( $this, 'restocked' ), 10, 3 );
	}

	/**
	 * Sending settings with defaults. The channel also drives which fields the
	 * front-end popup shows and which one is required.
	 *
	 * @return array{channel:string,auto:int,twilio_sid:string,twilio_token:string,twilio_from:string,twilio_template:string}
	 */
	public static function settings(): array {
		$saved = get_option( 'oc_waitlist_settings' );

		return wp_parse_args(
			is_array( $saved ) ? $saved : array(),
			array(
				'channel'         => 'both',
				'auto'            => 1,
				'twilio_sid'      => '',
				'twilio_token'    => '',
				'twilio_from'     => '',
				'twilio_template' => '',
			)
		);
	}

	/**
	 * Persist the settings panel.
	 */
	public function save_settings(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die();
		}
		check_admin_referer( 'oc_waitlist_settings' );

		$channel = sanitize_key( $_POST['channel'] ?? 'both' );

		update_option(
			'oc_waitlist_settings',
			array(
				'channel'         => in_array( $channel, array( 'whatsapp', 'email', 'both' ), true ) ? $channel : 'both',
				'auto'            => empty( $_POST['auto'] ) ? 0 : 1,
				'twilio_sid'      => sanitize_text_field( wp_unslash( (string) ( $_POST['twilio_sid'] ?? '' ) ) ),
				'twilio_token'    => sanitize_text_field( wp_unslash( (string) ( $_POST['twilio_token'] ?? '' ) ) ),
				'twilio_from'     => sanitize_text_field( wp_unslash( (string) ( $_POST['twilio_from'] ?? '' ) ) ),
				'twilio_template' => sanitize_text_field( wp_unslash( (string) ( $_POST['twilio_template'] ?? '' ) ) ),
			),
			false
		);

		wp_safe_redirect( add_query_arg( array( 'page' => 'oc-waitlist', 'oc_saved' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * A product came back: tell everyone on its list and clear the entries
	 * that were reached.
	 *
	 * @param int         $product_id Product id.
	 * @param string      $status     New stock status.
	 * @param \WC_Product $product    The product.
	 */
	public function restocked( int $product_id, string $status, $product ): void {
		$settings = self::settings();

		if ( 'instock' !== $status || empty( $settings['auto'] ) ) {
			return;
		}

		$list = get_post_meta( $product_id, '_oc_notify_list', true );

		if ( ! is_array( $list ) || empty( $list ) ) {
			return;
		}

		$name = $product ? $product->get_name() : get_the_title( $product_id );
		$url  = (string) get_permalink( $product_id );

		foreach ( $list as $key => $entry ) {
			if ( ! is_array( $entry ) ) {
				$entry = array( 'email' => (string) $key, 'phone' => '' );
			}

			$sent = false;

			if ( 'email' !== $settings['channel'] && ! empty( $entry['phone'] ) ) {
				$sent = $this->send_whatsapp( (string) $entry['phone'], $name, $url ) || $sent;
			}
			if ( 'whatsapp' !== $settings['channel'] && ! empty( $entry['email'] ) ) {
				$sent = $this->send_email( (string) $entry['email'], $name, $url ) || $sent;
			}

			if ( $sent ) {
				unset( $list[ $key ] );
			}
		}

		if ( empty( $list ) ) {
			delete_post_meta( $product_id, '_oc_notify_list' );
		} else {
			update_post_meta( $product_id, '_oc_notify_list', $list );
		}
	}

	/**
	 * Local numbers to E.164: 05x → +9725x, 972… → +972…
	 *
	 * @param string $phone Raw signup number.
	 */
	private function normalize_phone( string $phone ): string {
		$digits = preg_replace( '/[^0-9]/', '', $phone );

		if ( str_starts_with( $digits, '0' ) ) {
			return '+972' . substr( $digits, 1 );
		}

		return '+' . $digits;
	}

	/**
	 * One WhatsApp template message through Twilio — the integration proven on
	 * pinukitchen.co.il. The template gets {{1}} product name, {{2}} link.
	 *
	 * @param string $phone Subscriber number, any local format.
	 * @param string $name  Product name.
	 * @param string $url   Product URL.
	 */
	private function send_whatsapp( string $phone, string $name, string $url ): bool {
		$settings = self::settings();

		if ( '' === $settings['twilio_sid'] || '' === $settings['twilio_token'] || '' === $settings['twilio_from'] || '' === $settings['twilio_template'] ) {
			return false;
		}

		// Safety valve well inside Twilio's own limits.
		$rate_key = 'oc_waitlist_rate_' . gmdate( 'Y-m-d-H' );
		$count    = (int) get_transient( $rate_key );
		if ( $count >= 300 ) {
			return false;
		}
		set_transient( $rate_key, $count + 1, HOUR_IN_SECONDS );

		$response = wp_remote_post(
			'https://api.twilio.com/2010-04-01/Accounts/' . rawurlencode( $settings['twilio_sid'] ) . '/Messages.json',
			array(
				'headers' => array(
					'Authorization' => 'Basic ' . base64_encode( $settings['twilio_sid'] . ':' . $settings['twilio_token'] ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP basic auth.
				),
				'body'    => array(
					'To'               => 'whatsapp:' . $this->normalize_phone( $phone ),
					'From'             => 'whatsapp:' . $settings['twilio_from'],
					'ContentSid'       => $settings['twilio_template'],
					'ContentVariables' => wp_json_encode(
						array(
							'1' => $name,
							'2' => $url,
						)
					),
				),
				'timeout' => 20,
			)
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ) );

		return 201 === wp_remote_retrieve_response_code( $response ) && isset( $body->sid );
	}

	/**
	 * The email counterpart, wrapped in WooCommerce's branded mail template.
	 *
	 * @param string $email Subscriber address.
	 * @param string $name  Product name.
	 * @param string $url   Product URL.
	 */
	private function send_email( string $email, string $name, string $url ): bool {
		/* translators: %s: product name. */
		$subject = sprintf( __( 'Good news — %s is back in stock!', 'oc-theme' ), $name );
		$body    = '<p>' . esc_html__( 'The product you asked to follow is available again. Quantities may be limited — first come, first served.', 'oc-theme' ) . '</p>' .
			'<p><a href="' . esc_url( $url ) . '" style="display:inline-block;padding:12px 28px;background:#111;color:#fff;text-decoration:none;border-radius:8px;">' . esc_html__( 'To the product', 'oc-theme' ) . '</a></p>';

		$mailer = WC()->mailer();

		return (bool) $mailer->send( $email, $subject, $mailer->wrap_message( $subject, $body ) );
	}

	/**
	 * Admin test button: one template message to the given number.
	 */
	public function test_send(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die();
		}
		check_admin_referer( 'oc_waitlist_test' );

		$phone = sanitize_text_field( wp_unslash( (string) ( $_POST['test_phone'] ?? '' ) ) );
		$ok    = '' !== $phone && $this->send_whatsapp( $phone, __( 'Test product', 'oc-theme' ), home_url( '/' ) );

		wp_safe_redirect( add_query_arg( array( 'page' => 'oc-waitlist', 'oc_test' => $ok ? 1 : 0 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Submenu under WooCommerce.
	 */
	public function menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'Back-in-stock waitlist', 'oc-theme' ),
			__( 'Back-in-stock waitlist', 'oc-theme' ),
			'manage_woocommerce',
			'oc-waitlist',
			array( $this, 'render' )
		);
	}

	/**
	 * Every signup, flattened and newest first.
	 *
	 * @return array<int,array{product:int,key:string,email:string,phone:string,time:int}>
	 */
	private function rows(): array {
		global $wpdb;

		// One meta key across all products — a single indexed query, admin
		// only. No API equivalent fetches meta across posts in one go.
		$metas = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s",
				'_oc_notify_list'
			)
		);

		$rows = array();

		foreach ( $metas as $meta ) {
			$list = maybe_unserialize( $meta->meta_value );

			if ( ! is_array( $list ) ) {
				continue;
			}

			foreach ( $list as $key => $entry ) {
				// Early signups stored email => timestamp.
				if ( is_int( $entry ) ) {
					$entry = array(
						'email' => (string) $key,
						'phone' => '',
						'time'  => $entry,
					);
				}

				$rows[] = array(
					'product' => (int) $meta->post_id,
					'key'     => (string) $key,
					'email'   => (string) ( $entry['email'] ?? '' ),
					'phone'   => (string) ( $entry['phone'] ?? '' ),
					'time'    => (int) ( $entry['time'] ?? 0 ),
				);
			}
		}

		usort(
			$rows,
			static function ( array $a, array $b ): int {
				return $b['time'] <=> $a['time'];
			}
		);

		return $rows;
	}

	/**
	 * The screen: removals first, then the table.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		if ( isset( $_GET['oc_remove'], $_GET['oc_product'], $_GET['_wpnonce'] ) &&
			wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'oc_waitlist_remove' ) ) {
			$product_id = absint( $_GET['oc_product'] );
			$remove_key = sanitize_text_field( wp_unslash( $_GET['oc_remove'] ) );
			$list       = get_post_meta( $product_id, '_oc_notify_list', true );

			if ( is_array( $list ) && isset( $list[ $remove_key ] ) ) {
				unset( $list[ $remove_key ] );
				if ( empty( $list ) ) {
					delete_post_meta( $product_id, '_oc_notify_list' );
				} else {
					update_post_meta( $product_id, '_oc_notify_list', $list );
				}
				echo '<div class="notice notice-success"><p>' . esc_html__( 'Removed.', 'oc-theme' ) . '</p></div>';
			}
		}

		if ( isset( $_GET['oc_saved'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- notice only.
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Settings saved.', 'oc-theme' ) . '</p></div>';
		}
		if ( isset( $_GET['oc_test'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- notice only.
			echo absint( $_GET['oc_test'] )
				? '<div class="notice notice-success"><p>' . esc_html__( 'Test message sent — check WhatsApp.', 'oc-theme' ) . '</p></div>'
				: '<div class="notice notice-error"><p>' . esc_html__( 'Sending failed — check the Twilio details below.', 'oc-theme' ) . '</p></div>';
		}

		$rows     = $this->rows();
		$settings = self::settings();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Back-in-stock waitlist', 'oc-theme' ); ?></h1>
			<p>
				<?php
				/* translators: %d: number of signups. */
				echo esc_html( sprintf( __( '%d signups are waiting for products to return.', 'oc-theme' ), count( $rows ) ) );
				?>
			</p>

			<div class="card" style="max-width:640px;margin-block-end:20px;padding:4px 20px 16px;">
				<h2><?php esc_html_e( 'Sending settings', 'oc-theme' ); ?></h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="oc_waitlist_settings" />
					<?php wp_nonce_field( 'oc_waitlist_settings' ); ?>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="oc-wl-channel"><?php esc_html_e( 'Notification channel', 'oc-theme' ); ?></label></th>
							<td>
								<select name="channel" id="oc-wl-channel">
									<option value="whatsapp" <?php selected( 'whatsapp', $settings['channel'] ); ?>><?php esc_html_e( 'WhatsApp', 'oc-theme' ); ?></option>
									<option value="email" <?php selected( 'email', $settings['channel'] ); ?>><?php esc_html_e( 'Email', 'oc-theme' ); ?></option>
									<option value="both" <?php selected( 'both', $settings['channel'] ); ?>><?php esc_html_e( 'WhatsApp and email', 'oc-theme' ); ?></option>
								</select>
								<p class="description"><?php esc_html_e( 'Also controls which fields the signup popup shows on the site.', 'oc-theme' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Automatic sending', 'oc-theme' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="auto" value="1" <?php checked( 1, (int) $settings['auto'] ); ?> />
									<?php esc_html_e( 'Send automatically the moment a product returns to stock', 'oc-theme' ); ?>
								</label>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="oc-wl-sid"><?php esc_html_e( 'Twilio Account SID', 'oc-theme' ); ?></label></th>
							<td><input type="text" class="regular-text" id="oc-wl-sid" name="twilio_sid" value="<?php echo esc_attr( $settings['twilio_sid'] ); ?>" autocomplete="off" dir="ltr" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="oc-wl-token"><?php esc_html_e( 'Twilio Auth Token', 'oc-theme' ); ?></label></th>
							<td><input type="password" class="regular-text" id="oc-wl-token" name="twilio_token" value="<?php echo esc_attr( $settings['twilio_token'] ); ?>" autocomplete="new-password" dir="ltr" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="oc-wl-from"><?php esc_html_e( 'WhatsApp sender number', 'oc-theme' ); ?></label></th>
							<td>
								<input type="text" class="regular-text" id="oc-wl-from" name="twilio_from" value="<?php echo esc_attr( $settings['twilio_from'] ); ?>" placeholder="+9725XXXXXXXX" dir="ltr" />
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="oc-wl-template"><?php esc_html_e( 'Message template SID', 'oc-theme' ); ?></label></th>
							<td>
								<input type="text" class="regular-text" id="oc-wl-template" name="twilio_template" value="<?php echo esc_attr( $settings['twilio_template'] ); ?>" autocomplete="off" dir="ltr" />
								<p class="description"><?php esc_html_e( 'An approved Twilio content template with {{1}} for the product name and {{2}} for the product link.', 'oc-theme' ); ?></p>
							</td>
						</tr>
					</table>
					<p><button class="button button-primary"><?php esc_html_e( 'Save settings', 'oc-theme' ); ?></button></p>
				</form>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:flex;gap:8px;align-items:center;border-block-start:1px solid #eee;padding-block-start:12px;">
					<input type="hidden" name="action" value="oc_waitlist_test" />
					<?php wp_nonce_field( 'oc_waitlist_test' ); ?>
					<input type="tel" name="test_phone" placeholder="05XXXXXXXX" dir="ltr" />
					<button class="button"><?php esc_html_e( 'Send a test WhatsApp message', 'oc-theme' ); ?></button>
				</form>
			</div>

			<?php if ( $rows ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-block-end:12px;">
					<input type="hidden" name="action" value="oc_waitlist_export" />
					<?php wp_nonce_field( 'oc_waitlist_export' ); ?>
					<button class="button button-primary"><?php esc_html_e( 'Export CSV', 'oc-theme' ); ?></button>
				</form>

				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Product', 'oc-theme' ); ?></th>
							<th><?php esc_html_e( 'Phone', 'oc-theme' ); ?></th>
							<th><?php esc_html_e( 'Email', 'oc-theme' ); ?></th>
							<th><?php esc_html_e( 'Signed up', 'oc-theme' ); ?></th>
							<th><?php esc_html_e( 'Stock status', 'oc-theme' ); ?></th>
							<th></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $rows as $row ) : ?>
							<?php
							$product = wc_get_product( $row['product'] );
							$remove  = wp_nonce_url(
								add_query_arg(
									array(
										'page'       => 'oc-waitlist',
										'oc_product' => $row['product'],
										'oc_remove'  => rawurlencode( $row['key'] ),
									),
									admin_url( 'admin.php' )
								),
								'oc_waitlist_remove'
							);
							?>
							<tr>
								<td>
									<?php if ( $product ) : ?>
										<a href="<?php echo esc_url( get_edit_post_link( $row['product'] ) ); ?>"><strong><?php echo esc_html( $product->get_name() ); ?></strong></a>
									<?php else : ?>
										#<?php echo absint( $row['product'] ); ?>
									<?php endif; ?>
								</td>
								<td dir="ltr"><?php echo esc_html( $row['phone'] ); ?></td>
								<td><?php echo esc_html( $row['email'] ); ?></td>
								<td><?php echo esc_html( $row['time'] ? wp_date( 'j.n.Y H:i', $row['time'] ) : '—' ); ?></td>
								<td>
									<?php if ( $product && $product->is_in_stock() ) : ?>
										<span style="color:#2e9e5b;font-weight:600;"><?php esc_html_e( 'Back in stock', 'oc-theme' ); ?></span>
									<?php else : ?>
										<span style="color:#c4342c;"><?php esc_html_e( 'Still out', 'oc-theme' ); ?></span>
									<?php endif; ?>
								</td>
								<td><a href="<?php echo esc_url( $remove ); ?>" style="color:#c4342c;"><?php esc_html_e( 'Remove', 'oc-theme' ); ?></a></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php else : ?>
				<p><?php esc_html_e( 'No signups yet — they collect from the notify popup on sold-out products.', 'oc-theme' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * CSV export, Excel-friendly (BOM), newest first.
	 */
	public function export(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die();
		}
		check_admin_referer( 'oc_waitlist_export' );

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=waitlist-' . gmdate( 'Y-m-d' ) . '.csv' );

		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streamed CSV download.
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- BOM keeps Hebrew intact in Excel.
		fputcsv( $out, array( __( 'Product', 'oc-theme' ), __( 'Phone', 'oc-theme' ), __( 'Email', 'oc-theme' ), __( 'Signed up', 'oc-theme' ) ) );

		foreach ( $this->rows() as $row ) {
			$product = wc_get_product( $row['product'] );
			fputcsv(
				$out,
				array(
					$product ? $product->get_name() : ( '#' . $row['product'] ),
					$row['phone'],
					$row['email'],
					$row['time'] ? wp_date( 'Y-m-d H:i', $row['time'] ) : '',
				)
			);
		}

		exit;
	}
}
