<?php
/**
 * Leads: where the contact block's submissions live, and how they travel on.
 *
 * @package OC_Blocks
 */

declare( strict_types = 1 );

namespace OC\Blocks;

defined( 'ABSPATH' ) || exit;

/**
 * A private post type with an admin screen — search, columns, CSV — plus an
 * optional webhook that hands every new lead to the shop's CRM as JSON.
 */
final class Leads {

	/**
	 * The post type.
	 */
	const CPT = 'oc_lead';

	/**
	 * Option holding the webhook URL.
	 */
	const HOOK = 'oc_blocks_lead_hook';

	/**
	 * Hook in.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'cpt' ) );
		add_action( 'wp_ajax_oc_blocks_lead', array( $this, 'take' ) );
		add_action( 'wp_ajax_nopriv_oc_blocks_lead', array( $this, 'take' ) );

		add_filter( 'manage_' . self::CPT . '_posts_columns', array( $this, 'columns' ) );
		add_action( 'manage_' . self::CPT . '_posts_custom_column', array( $this, 'column' ), 10, 2 );
		add_action( 'add_meta_boxes_' . self::CPT, array( $this, 'boxes' ) );
		add_action( 'admin_menu', array( $this, 'settings_page' ) );
		add_action( 'admin_post_oc_blocks_leads_csv', array( $this, 'csv' ) );
	}

	/**
	 * The type itself: not public, fully visible in the admin.
	 */
	public function cpt(): void {
		register_post_type(
			self::CPT,
			array(
				'labels'          => array(
					'name'          => __( 'Leads', 'oc-blocks' ),
					'singular_name' => __( 'Lead', 'oc-blocks' ),
					'search_items'  => __( 'Search leads', 'oc-blocks' ),
					'not_found'     => __( 'No leads yet — the contact block fills this screen.', 'oc-blocks' ),
				),
				'public'          => false,
				'show_ui'         => true,
				'menu_position'   => 26,
				'menu_icon'       => 'dashicons-id-alt',
				'supports'        => array( 'title' ),
				'capability_type' => 'post',
				'capabilities'    => array( 'create_posts' => 'do_not_allow' ),
				'map_meta_cap'    => true,
			)
		);
	}

	/**
	 * Take one lead.
	 *
	 * Nonce-free by design — the form ships in cached HTML that outlives any
	 * nonce, and a logged-out nonce is the same for everyone. A honeypot and
	 * a short per-sender throttle stand guard instead.
	 */
	public function take(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- see above.
		$trap  = isset( $_POST['website'] ) ? trim( (string) wp_unslash( $_POST['website'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- honeypot; only ever compared to the empty string.
		$name  = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$phone = isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '';
		$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$msg   = isset( $_POST['msg'] ) ? sanitize_textarea_field( wp_unslash( $_POST['msg'] ) ) : '';
		$page  = isset( $_POST['page'] ) ? absint( wp_unslash( $_POST['page'] ) ) : 0;
		// phpcs:enable

		if ( '' !== $trap ) {
			wp_send_json_success(); // A bot told "thank you" moves on.
		}

		if ( '' === $name || mb_strlen( $name ) < 2 ) {
			wp_send_json_error( array( 'msg' => __( 'Please write your name.', 'oc-blocks' ) ) );
		}

		// The throttle below is keyed on who the sender says they are, which
		// a script defeats by making up a new name every time. This one is
		// keyed on where they are sending from, which they cannot change as
		// cheaply. A real contact form sees a handful an hour; anything past
		// that is told "thank you" and quietly dropped.
		$ip    = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- hashed below, never printed or stored.
		$burst = 'oc_blocks_lead_ip_' . md5( $ip );
		$sent  = (int) get_transient( $burst );

		/**
		 * Filters how many leads one sender may leave in an hour.
		 *
		 * @param int $max The cap.
		 */
		$max = (int) apply_filters( 'oc_blocks_lead_hourly_cap', 20 );

		if ( $sent >= $max ) {
			wp_send_json_success();
		}

		set_transient( $burst, $sent + 1, HOUR_IN_SECONDS );

		$gate = 'oc_blocks_lead_' . md5( strtolower( $name . '|' . $phone . '|' . $email ) );

		if ( false !== get_transient( $gate ) ) {
			wp_send_json_success(); // The same person twice in a minute: once was enough.
		}

		set_transient( $gate, 1, MINUTE_IN_SECONDS );

		$lead_id = wp_insert_post(
			array(
				'post_type'   => self::CPT,
				'post_status' => 'publish',
				'post_title'  => $name,
				'meta_input'  => array(
					'_oc_lead_phone' => $phone,
					'_oc_lead_email' => $email,
					'_oc_lead_msg'   => mb_substr( $msg, 0, 3000 ),
					'_oc_lead_page'  => $page,
				),
			)
		);

		if ( $lead_id > 0 ) {
			$this->forward( (int) $lead_id, $name, $phone, $email, $msg, $page );
		}

		wp_send_json_success();
	}

	/**
	 * Hand a fresh lead to the CRM, when a webhook is set.
	 *
	 * Non-blocking on purpose: the visitor is not kept waiting for someone
	 * else's server. A developer wanting more rides `oc_blocks_lead_payload`.
	 *
	 * @param int    $lead_id Lead post id.
	 * @param string $name    Name.
	 * @param string $phone   Phone.
	 * @param string $email   Email.
	 * @param string $msg     Message.
	 * @param int    $page    Page the form stood on.
	 */
	private function forward( int $lead_id, string $name, string $phone, string $email, string $msg, int $page ): void {
		$hook = (string) get_option( self::HOOK, '' );

		if ( '' === $hook ) {
			return;
		}

		$payload = array(
			'id'      => $lead_id,
			'name'    => $name,
			'phone'   => $phone,
			'email'   => $email,
			'message' => $msg,
			'page'    => $page > 0 ? (string) get_the_title( $page ) : '',
			'site'    => home_url( '/' ),
			'time'    => gmdate( 'c' ),
		);

		/**
		 * The JSON a new lead sends to the webhook.
		 *
		 * @param array<string,mixed> $payload Payload.
		 * @param int                 $lead_id Lead id.
		 */
		$payload = (array) apply_filters( 'oc_blocks_lead_payload', $payload, $lead_id );

		wp_remote_post(
			$hook,
			array(
				'blocking' => false,
				'timeout'  => 3,
				'headers'  => array( 'Content-Type' => 'application/json; charset=utf-8' ),
				'body'     => wp_json_encode( $payload ),
			)
		);
	}

	/**
	 * The list's columns.
	 *
	 * @param array<string,string> $columns Columns.
	 * @return array<string,string>
	 */
	public function columns( array $columns ): array {
		return array(
			'cb'       => $columns['cb'] ?? '',
			'title'    => __( 'Name', 'oc-blocks' ),
			'oc_phone' => __( 'Phone', 'oc-blocks' ),
			'oc_email' => __( 'Email', 'oc-blocks' ),
			'oc_msg'   => __( 'Message', 'oc-blocks' ),
			'oc_page'  => __( 'From the page', 'oc-blocks' ),
			'date'     => __( 'Arrived', 'oc-blocks' ),
		);
	}

	/**
	 * One cell.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Lead id.
	 */
	public function column( string $column, int $post_id ): void {
		switch ( $column ) {
			case 'oc_phone':
				$phone = (string) get_post_meta( $post_id, '_oc_lead_phone', true );
				echo '' === $phone ? '—' : '<a href="tel:' . esc_attr( (string) preg_replace( '/[^0-9+]/', '', $phone ) ) . '">' . esc_html( $phone ) . '</a>';
				break;

			case 'oc_email':
				$email = (string) get_post_meta( $post_id, '_oc_lead_email', true );
				echo '' === $email ? '—' : '<a href="mailto:' . esc_attr( $email ) . '">' . esc_html( $email ) . '</a>';
				break;

			case 'oc_msg':
				echo esc_html( wp_html_excerpt( (string) get_post_meta( $post_id, '_oc_lead_msg', true ), 70, '…' ) );
				break;

			case 'oc_page':
				$page = absint( get_post_meta( $post_id, '_oc_lead_page', true ) );
				echo $page > 0 ? esc_html( (string) get_the_title( $page ) ) : '—';
				break;
		}
	}

	/**
	 * The lead's own screen: everything it carries, read-only.
	 *
	 * @param \WP_Post $post Lead.
	 */
	public function boxes( \WP_Post $post ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- hook signature.
		add_meta_box(
			'oc-lead-details',
			__( 'The lead', 'oc-blocks' ),
			static function ( \WP_Post $lead ): void {
				$rows = array(
					__( 'Phone', 'oc-blocks' )         => (string) get_post_meta( $lead->ID, '_oc_lead_phone', true ),
					__( 'Email', 'oc-blocks' )         => (string) get_post_meta( $lead->ID, '_oc_lead_email', true ),
					__( 'Message', 'oc-blocks' )       => (string) get_post_meta( $lead->ID, '_oc_lead_msg', true ),
					__( 'From the page', 'oc-blocks' ) => (string) get_the_title( absint( get_post_meta( $lead->ID, '_oc_lead_page', true ) ) ),
				);

				echo '<table class="widefat striped">';

				foreach ( $rows as $label => $value ) {
					echo '<tr><th style="width:130px;text-align:start">' . esc_html( $label ) . '</th><td>' . nl2br( esc_html( '' === $value ? '—' : $value ) ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped before nl2br.
				}

				echo '</table>';
			},
			self::CPT,
			'normal',
			'high'
		);
	}

	/**
	 * Settings + export, under the Leads menu.
	 */
	public function settings_page(): void {
		add_submenu_page(
			'edit.php?post_type=' . self::CPT,
			__( 'Leads settings', 'oc-blocks' ),
			__( 'Settings & export', 'oc-blocks' ),
			'manage_options',
			'oc-blocks-leads-settings',
			array( $this, 'settings_html' )
		);
	}

	/**
	 * The settings screen: one webhook field and the way out.
	 */
	public function settings_html(): void {
		if ( isset( $_POST['oc_lead_hook'] ) && check_admin_referer( 'oc_blocks_leads_settings' ) && current_user_can( 'manage_options' ) ) {
			$url = esc_url_raw( trim( (string) wp_unslash( $_POST['oc_lead_hook'] ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- esc_url_raw() sanitizes.
			update_option( self::HOOK, $url, false );
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Saved.', 'oc-blocks' ) . '</p></div>';
		}

		$hook   = (string) get_option( self::HOOK, '' );
		$export = wp_nonce_url( admin_url( 'admin-post.php?action=oc_blocks_leads_csv' ), 'oc_blocks_leads_csv' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Leads settings', 'oc-blocks' ); ?></h1>
			<form method="post">
				<?php wp_nonce_field( 'oc_blocks_leads_settings' ); ?>
				<table class="form-table">
					<tr>
						<th scope="row"><label for="oc_lead_hook"><?php esc_html_e( 'CRM webhook', 'oc-blocks' ); ?></label></th>
						<td>
							<input type="url" class="regular-text ltr" id="oc_lead_hook" name="oc_lead_hook" value="<?php echo esc_attr( $hook ); ?>" placeholder="https://">
							<p class="description"><?php esc_html_e( 'Every new lead is POSTed there as JSON — name, phone, email, message, page. Works with any CRM that accepts webhooks, and with Make / Zapier in between.', 'oc-blocks' ); ?></p>
						</td>
					</tr>
				</table>
				<p class="submit">
					<button class="button button-primary"><?php esc_html_e( 'Save', 'oc-blocks' ); ?></button>
					<a class="button" href="<?php echo esc_url( $export ); ?>"><?php esc_html_e( 'Download all leads as CSV', 'oc-blocks' ); ?></a>
				</p>
			</form>
		</div>
		<?php
	}

	/**
	 * Every lead, as CSV.
	 */
	public function csv(): void {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'oc_blocks_leads_csv' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'oc-blocks' ) );
		}

		$leads = get_posts(
			array(
				'post_type'      => self::CPT,
				'posts_per_page' => -1,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=leads-' . gmdate( 'Y-m-d' ) . '.csv' );

		// The BOM, so Hebrew-locale Excel reads the file as UTF-8.
		echo "\xEF\xBB\xBF";
		echo "name,phone,email,message,page,date\n";

		$cell = static function ( string $value ): string {
			return '"' . str_replace( '"', '""', $value ) . '"';
		};

		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV fields, quoted and doubled.
		foreach ( $leads as $lead ) {
			echo $cell( (string) $lead->post_title ) . ','
				. $cell( (string) get_post_meta( $lead->ID, '_oc_lead_phone', true ) ) . ','
				. $cell( (string) get_post_meta( $lead->ID, '_oc_lead_email', true ) ) . ','
				. $cell( (string) get_post_meta( $lead->ID, '_oc_lead_msg', true ) ) . ','
				. $cell( (string) get_the_title( absint( get_post_meta( $lead->ID, '_oc_lead_page', true ) ) ) ) . ','
				. $cell( (string) get_the_date( 'Y-m-d H:i', $lead ) ) . "\n";
		}
		// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped

		exit;
	}
}
