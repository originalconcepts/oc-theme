<?php
/**
 * Statistics: the screen.
 *
 * A top-level "Statistics" menu, a dashboard widget, a JSON feed the
 * screen's script reads, CSV export, the settings, and the test button
 * for the digest.
 *
 * @package OC_Theme
 */

declare( strict_types = 1 );

namespace OC\Theme\Stats;

defined( 'ABSPATH' ) || exit;

/**
 * Screens and feeds.
 */
final class Admin {

	/**
	 * Page slugs.
	 */
	const PAGE     = 'oc-stats';
	const SETTINGS = 'oc-stats-settings';

	/**
	 * Hook in.
	 */
	public function register(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'rest_api_init', array( $this, 'rest' ) );
		add_action( 'admin_post_oc_stats_csv', array( $this, 'csv' ) );
		add_action( 'admin_post_oc_stats_save', array( $this, 'save' ) );
		add_action( 'admin_post_oc_stats_test_mail', array( $this, 'test_mail' ) );
		add_action( 'wp_dashboard_setup', array( $this, 'widget' ) );
	}

	/**
	 * Who may look.
	 */
	public static function cap(): string {
		return 'manage_woocommerce';
	}

	/**
	 * The menu: Statistics, right under the dashboard.
	 */
	public function menu(): void {
		add_menu_page( __( 'Statistics', 'oc-theme' ), __( 'Statistics', 'oc-theme' ), self::cap(), self::PAGE, array( $this, 'render' ), 'dashicons-chart-area', 3 );
		add_submenu_page( self::PAGE, __( 'Overview', 'oc-theme' ), __( 'Overview', 'oc-theme' ), self::cap(), self::PAGE, array( $this, 'render' ) );
		add_submenu_page( self::PAGE, __( 'Reports & settings', 'oc-theme' ), __( 'Reports & settings', 'oc-theme' ), self::cap(), self::SETTINGS, array( $this, 'render_settings' ) );
	}

	/**
	 * The screen's own script and style, on its pages only.
	 *
	 * @param string $hook Screen hook.
	 */
	public function assets( $hook ): void {
		if ( false === strpos( (string) $hook, self::PAGE ) ) {
			return;
		}

		$dir = get_template_directory_uri() . '/assets';
		$ver = defined( 'OC_THEME_VERSION' ) ? OC_THEME_VERSION : '1';

		wp_enqueue_style( 'oc-stats-admin', $dir . '/css/stats-admin.css', array(), $ver );
		wp_enqueue_script( 'oc-stats-admin', $dir . '/js/stats-admin.js', array(), $ver, true );
		wp_localize_script(
			'oc-stats-admin',
			'ocStats',
			array(
				'rest'     => rest_url( 'oc/v1/stats' ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'currency' => get_woocommerce_currency_symbol(),
				'csv'      => wp_nonce_url( admin_url( 'admin-post.php?action=oc_stats_csv' ), 'oc_stats_csv' ),
				'i18n'     => array(
					'today'       => __( 'Today', 'oc-theme' ),
					'yesterday'   => __( 'Yesterday', 'oc-theme' ),
					'd7'          => __( '7 days', 'oc-theme' ),
					'd30'         => __( '30 days', 'oc-theme' ),
					'd90'         => __( '90 days', 'oc-theme' ),
					'custom'      => __( 'Between dates', 'oc-theme' ),
					'vsYesterday' => __( 'against yesterday, up to the same hour', 'oc-theme' ),
					'vsPrev'      => __( 'against the previous period', 'oc-theme' ),
					'sales'       => __( 'Sales', 'oc-theme' ),
					'orders'      => __( 'Orders', 'oc-theme' ),
					'visits'      => __( 'Visits', 'oc-theme' ),
					'conv'        => __( 'Conversion', 'oc-theme' ),
					'aov'         => __( 'Average order', 'oc-theme' ),
					'returning'   => __( 'Returning customers', 'oc-theme' ),
					'newCust'     => __( 'New customers', 'oc-theme' ),
					'cancelled'   => __( 'Cancelled', 'oc-theme' ),
					'failed'      => __( 'Failed', 'oc-theme' ),
					'refunds'     => __( 'Refunds', 'oc-theme' ),
					'pending'     => __( 'Awaiting payment', 'oc-theme' ),
					'onhold'      => __( 'On hold', 'oc-theme' ),
					'salesByDay'  => __( 'Sales by day', 'oc-theme' ),
					'salesByHour' => __( 'Sales by hour', 'oc-theme' ),
					'cur'         => __( 'This period', 'oc-theme' ),
					'prev'        => __( 'Previous period', 'oc-theme' ),
					'funnel'      => __( 'Where shoppers drop off', 'oc-theme' ),
					'fVisits'     => __( 'Visits', 'oc-theme' ),
					'fProduct'    => __( 'Viewed a product', 'oc-theme' ),
					'fCart'       => __( 'Added to cart', 'oc-theme' ),
					'fCheckout'   => __( 'Reached the checkout', 'oc-theme' ),
					'fOrder'      => __( 'Completed an order', 'oc-theme' ),
					'drop'        => __( 'drop', 'oc-theme' ),
					/* translators: %s: the step, e.g. "Visits → Viewed a product". */
					'biggestDrop' => __( 'The biggest drop is %s. That is the step to work on first.', 'oc-theme' ),
					'channels'    => __( 'Where orders came from', 'oc-theme' ),
					'ordersSales' => __( 'orders · sales', 'oc-theme' ),
					'devices'     => __( 'Devices', 'oc-theme' ),
					'ordersConv'  => __( 'orders · conversion', 'oc-theme' ),
					'products'    => __( 'Products driving revenue', 'oc-theme' ),
					'byGross'     => __( 'by sales', 'oc-theme' ),
					'product'     => __( 'Product', 'oc-theme' ),
					'views'       => __( 'Views', 'oc-theme' ),
					'toCart'      => __( 'To cart', 'oc-theme' ),
					'insights'    => __( 'What to do now', 'oc-theme' ),
					'insightsSub' => __( 'The five insights that matter, refreshed every morning', 'oc-theme' ),
					'noInsights'  => __( 'Nothing needs your attention right now. The rules run again every morning.', 'oc-theme' ),
					'how'         => __( 'How this was worked out', 'oc-theme' ),
					'dismiss'     => __( 'Not relevant', 'oc-theme' ),
					'breakdown'   => __( 'What did not become sales', 'oc-theme' ),
					'customers'   => __( 'Customers', 'oc-theme' ),
					'loading'     => __( 'Loading…', 'oc-theme' ),
					'noVisits'    => __( 'Visits are counted from the day the statistics were switched on; earlier days show orders only.', 'oc-theme' ),
					'm'           => __( 'Mobile', 'oc-theme' ),
					'd'           => __( 'Desktop', 'oc-theme' ),
					't'           => __( 'Tablet', 'oc-theme' ),
					'ch'          => array(
						'organic'     => __( 'Organic search', 'oc-theme' ),
						'paid_search' => __( 'Google Ads', 'oc-theme' ),
						'social'      => __( 'Social', 'oc-theme' ),
						'paid_social' => __( 'Paid social', 'oc-theme' ),
						'email'       => __( 'Email & SMS', 'oc-theme' ),
						'referral'    => __( 'Referrals', 'oc-theme' ),
						'direct'      => __( 'Direct', 'oc-theme' ),
					),
					'updated'     => __( 'Updated just now', 'oc-theme' ),
					'exportCsv'   => __( 'Export CSV', 'oc-theme' ),
					'from'        => __( 'From', 'oc-theme' ),
					'to'          => __( 'To', 'oc-theme' ),
					'show'        => __( 'Show', 'oc-theme' ),
					'grossNote'   => __( 'What customers paid, including shipping and VAT, for paid orders and orders in hand.', 'oc-theme' ),
				),
			)
		);
	}

	/**
	 * The overview shell; the script fills it.
	 */
	public function render(): void {
		if ( ! current_user_can( self::cap() ) ) {
			wp_die( esc_html__( 'Not allowed.', 'oc-theme' ) );
		}

		Track::install();
		?>
		<div class="wrap ocst" id="oc-stats" data-range="d30">
			<div class="ocst__head">
				<div>
					<h1><?php esc_html_e( 'Statistics', 'oc-theme' ); ?></h1>
					<p class="ocst__sub" data-sub><?php esc_html_e( 'Loading…', 'oc-theme' ); ?></p>
				</div>
				<div class="ocst__pickers" data-pickers></div>
			</div>
			<div class="ocst__custom" data-custom hidden>
				<label><?php esc_html_e( 'From', 'oc-theme' ); ?> <input type="date" data-from></label>
				<label><?php esc_html_e( 'To', 'oc-theme' ); ?> <input type="date" data-to></label>
				<button type="button" class="button" data-go><?php esc_html_e( 'Show', 'oc-theme' ); ?></button>
			</div>
			<div class="ocst__body" data-body><p class="ocst__loading"><?php esc_html_e( 'Loading…', 'oc-theme' ); ?></p></div>
		</div>
		<?php
	}

	/**
	 * Reports and settings.
	 */
	public function render_settings(): void {
		if ( ! current_user_can( self::cap() ) ) {
			wp_die( esc_html__( 'Not allowed.', 'oc-theme' ) );
		}

		$s        = Settings::get();
		$backfill = (string) get_option( 'oc_stats_backfill', '' );
		?>
		<div class="wrap ocst">
			<h1><?php esc_html_e( 'Reports & settings', 'oc-theme' ); ?></h1>
			<?php if ( isset( $_GET['saved'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- notice only. ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Saved.', 'oc-theme' ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['mailed'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- notice only. ?>
				<div class="notice notice-<?php echo '1' === (string) $_GET['mailed'] ? 'success' : 'error'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput -- compared to a literal. ?> is-dismissible"><p><?php echo '1' === (string) $_GET['mailed'] ? esc_html__( 'The test report was sent.', 'oc-theme' ) : esc_html__( 'Nothing was sent — add at least one recipient and save first.', 'oc-theme' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput -- compared to a literal. ?></p></div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width:760px;">
				<input type="hidden" name="action" value="oc_stats_save">
				<?php wp_nonce_field( 'oc_stats_save' ); ?>

				<h2><?php esc_html_e( 'Report by email', 'oc-theme' ); ?></h2>
				<p class="description"><?php esc_html_e( 'The daily report goes out in the morning about the whole of yesterday, with the orders themselves; the weekly report goes out on Sunday morning about the week that ended. Both compare to the period before.', 'oc-theme' ); ?></p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Send', 'oc-theme' ); ?></th>
						<td>
							<label><input type="checkbox" name="mail_daily" value="1" <?php checked( 1, $s['mail_daily'] ); ?>> <?php esc_html_e( 'A daily report, every morning', 'oc-theme' ); ?></label><br>
							<label><input type="checkbox" name="mail_weekly" value="1" <?php checked( 1, $s['mail_weekly'] ); ?>> <?php esc_html_e( 'A weekly report, Sunday morning', 'oc-theme' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="oc_stats_to"><?php esc_html_e( 'Recipients', 'oc-theme' ); ?></label></th>
						<td><input type="text" id="oc_stats_to" name="mail_to" class="regular-text" value="<?php echo esc_attr( $s['mail_to'] ); ?>" placeholder="owner@shop.co.il, manager@shop.co.il"><p class="description"><?php esc_html_e( 'One or more addresses, separated by commas.', 'oc-theme' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><label for="oc_stats_hour"><?php esc_html_e( 'Send at', 'oc-theme' ); ?></label></th>
						<td>
							<select id="oc_stats_hour" name="mail_hour">
								<?php for ( $h = 5; $h <= 12; $h++ ) : ?>
									<option value="<?php echo (int) $h; ?>" <?php selected( $h, $s['mail_hour'] ); ?>><?php echo esc_html( sprintf( '%02d:00', $h ) ); ?></option>
								<?php endfor; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Shop time. The report is sent within the hour.', 'oc-theme' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Measurement', 'oc-theme' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="oc_stats_ips"><?php esc_html_e( 'Addresses that never count', 'oc-theme' ); ?></label></th>
						<td><input type="text" id="oc_stats_ips" name="exclude_ips" class="regular-text code" value="<?php echo esc_attr( $s['exclude_ips'] ); ?>" placeholder="84.229.10.20, 2a00:…"><p class="description"><?php esc_html_e( 'Your office, for instance. Signed-in staff are never counted anyway, and neither are robots.', 'oc-theme' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'History', 'oc-theme' ); ?></th>
						<td><p class="description">
							<?php
							if ( 'done' === $backfill ) {
								esc_html_e( 'A year of orders is summarised. Visits are counted from the day the statistics were switched on.', 'oc-theme' );
							} elseif ( '' === $backfill ) {
								esc_html_e( 'Older orders are being summarised in the background, a month at a time.', 'oc-theme' );
							} else {
								printf( /* translators: %s: date. */ esc_html__( 'Older orders are being summarised in the background; done back to %s so far.', 'oc-theme' ), esc_html( wp_date( 'j.n.Y', strtotime( $backfill ) ) ) );
							}
							?>
						</p></td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:-8px;">
				<input type="hidden" name="action" value="oc_stats_test_mail">
				<?php wp_nonce_field( 'oc_stats_test_mail' ); ?>
				<button class="button" type="submit"><?php esc_html_e( 'Send yesterday\'s report now, as a test', 'oc-theme' ); ?></button>
			</form>
		</div>
		<?php
	}

	/**
	 * Save.
	 */
	public function save(): void {
		if ( ! current_user_can( self::cap() ) ) {
			wp_die( esc_html__( 'Not allowed.', 'oc-theme' ) );
		}

		check_admin_referer( 'oc_stats_save' );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked above.
		$post = wp_unslash( $_POST );
		// phpcs:enable

		update_option(
			Settings::OPTION,
			array(
				'mail_daily'  => empty( $post['mail_daily'] ) ? 0 : 1,
				'mail_weekly' => empty( $post['mail_weekly'] ) ? 0 : 1,
				'mail_to'     => sanitize_text_field( (string) ( $post['mail_to'] ?? '' ) ),
				'mail_hour'   => max( 0, min( 23, (int) ( $post['mail_hour'] ?? 8 ) ) ),
				'exclude_ips' => sanitize_text_field( (string) ( $post['exclude_ips'] ?? '' ) ),
			),
			false
		);

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'  => self::SETTINGS,
					'saved' => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * The test button.
	 */
	public function test_mail(): void {
		if ( ! current_user_can( self::cap() ) ) {
			wp_die( esc_html__( 'Not allowed.', 'oc-theme' ) );
		}

		check_admin_referer( 'oc_stats_test_mail' );

		$ok = Mail::send_test();

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'   => self::SETTINGS,
					'mailed' => $ok ? '1' : '0',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/* ------------------------------------------------------------ feed */

	/**
	 * Routes.
	 */
	public function rest(): void {
		register_rest_route(
			'oc/v1',
			'/stats',
			array(
				'methods'             => 'GET',
				'permission_callback' => static function (): bool {
					return current_user_can( self::cap() );
				},
				'callback'            => array( $this, 'rest_stats' ),
			)
		);

		register_rest_route(
			'oc/v1',
			'/stats/dismiss',
			array(
				'methods'             => 'POST',
				'permission_callback' => static function (): bool {
					return current_user_can( self::cap() );
				},
				'callback'            => static function ( \WP_REST_Request $req ) {
					Insights::dismiss( sanitize_key( (string) $req->get_param( 'key' ) ) );

					return new \WP_REST_Response( array( 'ok' => true ) );
				},
			)
		);
	}

	/**
	 * Everything the screen draws, for a range.
	 *
	 * @param \WP_REST_Request $req Request.
	 * @return \WP_REST_Response
	 */
	public function rest_stats( \WP_REST_Request $req ) {
		$range = sanitize_key( (string) $req->get_param( 'range' ) );
		$r     = Query::range( $range, sanitize_text_field( (string) $req->get_param( 'from' ) ), sanitize_text_field( (string) $req->get_param( 'to' ) ) );

		return new \WP_REST_Response( self::shape( $r ) );
	}

	/**
	 * Numbers into what the screen shows.
	 *
	 * @param array<string,mixed> $r From Query::range().
	 * @return array<string,mixed>
	 */
	public static function shape( array $r ): array {
		$cur  = $r['cur'];
		$prev = $r['prev'];

		$rate = static function ( array $m, string $a, string $b ): float {
			return (float) $m[ $b ] > 0 ? (float) $m[ $a ] / (float) $m[ $b ] : 0.0;
		};

		$conv  = $rate( $cur, 'orders', 'sessions' ) * 100;
		$pconv = $rate( $prev, 'orders', 'sessions' ) * 100;
		$aov   = $rate( $cur, 'gross', 'orders' );
		$paov  = $rate( $prev, 'gross', 'orders' );
		$ret   = (float) $cur['new_customers'] + (float) $cur['returning_customers'];
		$pret  = (float) $prev['new_customers'] + (float) $prev['returning_customers'];
		$rets  = $ret > 0 ? (float) $cur['returning_customers'] / $ret * 100 : 0;
		$prets = $pret > 0 ? (float) $prev['returning_customers'] / $pret * 100 : 0;

		$kpis = array(
			array( 'sales', round( (float) $cur['gross'], 2 ), 'money', Query::change( (float) $cur['gross'], (float) $prev['gross'] ) ),
			array( 'orders', (int) $cur['orders'], 'int', Query::change( (float) $cur['orders'], (float) $prev['orders'] ) ),
			array( 'visits', (int) $cur['sessions'], 'int', Query::change( (float) $cur['sessions'], (float) $prev['sessions'] ) ),
			array( 'conv', round( $conv, 2 ), 'pct', $pconv > 0 ? round( $conv - $pconv, 2 ) : null ),
			array( 'aov', round( $aov, 2 ), 'money', Query::change( $aov, $paov ) ),
			array( 'returning', round( $rets, 1 ), 'pct', $prets > 0 ? round( $rets - $prets, 1 ) : null ),
		);

		$funnel = array(
			array( 'fVisits', (int) $cur['sessions'] ),
			array( 'fProduct', (int) $cur['product_sessions'] ),
			array( 'fCart', (int) $cur['atc_sessions'] ),
			array( 'fCheckout', (int) $cur['checkout_sessions'] ),
			array( 'fOrder', (int) $cur['orders'] ),
		);

		$channels = array();

		foreach ( Track::CHANNELS as $ch ) {
			$n = (int) ( $cur['orders_ch'][ $ch ] ?? 0 );
			$s = (int) ( $cur['sessions_ch'][ $ch ] ?? 0 );

			if ( $n > 0 || $s > 0 ) {
				$channels[] = array( $ch, $n, round( (float) ( $cur['gross_ch'][ $ch ] ?? 0 ), 2 ), $s );
			}
		}

		usort(
			$channels,
			static function ( array $a, array $b ): int {
				return $b[2] <=> $a[2];
			}
		);

		$devices = array();
		$dev_all = max( 1, array_sum( (array) $cur['sessions_dev'] ) );

		foreach ( array( 'm', 'd', 't' ) as $dv ) {
			$s = (int) ( $cur['sessions_dev'][ $dv ] ?? 0 );
			$n = (int) ( $cur['orders_dev'][ $dv ] ?? 0 );

			$devices[] = array( $dv, $n, $s > 0 ? round( $n / $s * 100, 2 ) : null, round( $s / $dev_all * 100 ) );
		}

		$gross = (array) $cur['product_gross'];
		arsort( $gross );
		$products = array();

		foreach ( array_slice( $gross, 0, 8, true ) as $pid => $sum ) {
			$p          = wc_get_product( (int) $pid );
			$products[] = array(
				$p ? $p->get_name() : '#' . $pid,
				$p ? (string) get_edit_post_link( (int) $pid, 'raw' ) : '',
				(int) ( $cur['product_views'][ $pid ] ?? 0 ),
				(int) ( $cur['product_atc'][ $pid ] ?? 0 ),
				(int) ( $cur['product_orders'][ $pid ] ?? 0 ),
				round( (float) $sum, 2 ),
				$p ? (string) $p->get_image( 'thumbnail', array( 'class' => 'ocst__thumb' ) ) : '',
			);
		}

		$breakdown = array(
			array( 'cancelled', (int) $cur['cancelled_n'], round( (float) $cur['cancelled_sum'], 2 ) ),
			array( 'failed', (int) $cur['failed_n'], round( (float) $cur['failed_sum'], 2 ) ),
			array( 'refunds', 0, round( (float) $cur['refunds'], 2 ) ),
			array( 'pending', (int) $cur['pending_n'], round( (float) $cur['pending_sum'], 2 ) ),
			array( 'onhold', (int) $cur['onhold_n'], round( (float) $cur['onhold_sum'], 2 ) ),
		);

		return array(
			'range'       => $r['range'],
			'from'        => $r['from'],
			'to'          => $r['to'],
			'prev_from'   => $r['prev_from'] ?? '',
			'prev_to'     => $r['prev_to'] ?? '',
			'granularity' => $r['granularity'],
			'series'      => $r['series'],
			'prev_series' => $r['prev_series'],
			'kpis'        => $kpis,
			'funnel'      => $funnel,
			'channels'    => $channels,
			'devices'     => $devices,
			'products'    => $products,
			'breakdown'   => $breakdown,
			'customers'   => array( (int) $cur['new_customers'], (int) $cur['returning_customers'] ),
			'insights'    => Insights::top(),
			'tracking'    => '2' === (string) get_option( 'oc_stats_tables', '' ),
		);
	}

	/* ------------------------------------------------------------ csv */

	/**
	 * A day-by-day CSV of the range.
	 */
	public function csv(): void {
		if ( ! current_user_can( self::cap() ) ) {
			wp_die( esc_html__( 'Not allowed.', 'oc-theme' ) );
		}

		check_admin_referer( 'oc_stats_csv' );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- checked above.
		$range = sanitize_key( (string) ( $_GET['range'] ?? 'd30' ) );
		$from  = sanitize_text_field( wp_unslash( (string) ( $_GET['from'] ?? '' ) ) );
		$to    = sanitize_text_field( wp_unslash( (string) ( $_GET['to'] ?? '' ) ) );
		// phpcs:enable

		$r = Query::range( 'today' === $range ? 'd7' : $range, $from, $to );

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="stats-' . $r['from'] . '-' . $r['to'] . '.csv"' );

		$out = fopen( 'php://output', 'w' );

		if ( false === $out ) {
			exit;
		}

		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- a download stream.
		fputcsv( $out, array( __( 'Day', 'oc-theme' ), __( 'Sales', 'oc-theme' ), __( 'Orders', 'oc-theme' ), __( 'Visits', 'oc-theme' ), __( 'Conversion', 'oc-theme' ), __( 'Cancelled', 'oc-theme' ), __( 'Failed', 'oc-theme' ), __( 'Refunds', 'oc-theme' ) ) );

		foreach ( Query::days( $r['from'], $r['to'] ) as $day ) {
			$m = Query::day( $day );
			fputcsv(
				$out,
				array(
					$day,
					round( (float) $m['gross'], 2 ),
					(int) $m['orders'],
					(int) $m['sessions'],
					(int) $m['sessions'] > 0 ? round( (float) $m['orders'] / (float) $m['sessions'] * 100, 2 ) : 0,
					(int) $m['cancelled_n'],
					(int) $m['failed_n'],
					round( (float) $m['refunds'], 2 ),
				)
			);
		}

		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- a download stream.
		exit;
	}

	/* ------------------------------------------------------------ widget */

	/**
	 * The dashboard widget.
	 */
	public function widget(): void {
		if ( ! current_user_can( self::cap() ) ) {
			return;
		}

		wp_add_dashboard_widget( 'oc_stats_widget', __( 'Sales today', 'oc-theme' ), array( $this, 'render_widget' ) );
	}

	/**
	 * Today against yesterday to the same hour, in four numbers.
	 */
	public function render_widget(): void {
		$r    = Query::range( 'today' );
		$cur  = $r['cur'];
		$prev = $r['prev'];
		$tile = static function ( string $label, string $value, ?float $delta ): void {
			$cls = null === $delta ? 'flat' : ( $delta > 0 ? 'up' : ( $delta < 0 ? 'down' : 'flat' ) );
			echo '<div class="ocst-w__tile"><span>' . esc_html( $label ) . '</span><b>' . esc_html( $value ) . '</b><i class="ocst-w__d ocst-w__d--' . esc_attr( $cls ) . '">' . ( null === $delta ? '—' : esc_html( ( $delta > 0 ? '+' : '' ) . number_format_i18n( $delta, 0 ) . '%' ) ) . '</i></div>';
		};
		?>
		<div class="ocst-w">
			<?php
			$tile( __( 'Sales', 'oc-theme' ), wp_strip_all_tags( wc_price( (float) $cur['gross'], array( 'decimals' => 0 ) ) ), Query::change( (float) $cur['gross'], (float) $prev['gross'] ) );
			$tile( __( 'Orders', 'oc-theme' ), number_format_i18n( (int) $cur['orders'] ), Query::change( (float) $cur['orders'], (float) $prev['orders'] ) );
			$tile( __( 'Visits', 'oc-theme' ), number_format_i18n( (int) $cur['sessions'] ), Query::change( (float) $cur['sessions'], (float) $prev['sessions'] ) );
			$tile( __( 'Cancelled + failed', 'oc-theme' ), number_format_i18n( (int) $cur['cancelled_n'] + (int) $cur['failed_n'] ), null );
			?>
		</div>
		<p class="ocst-w__note"><?php esc_html_e( 'Against yesterday, up to the same hour.', 'oc-theme' ); ?> <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ); ?>"><?php esc_html_e( 'All the statistics', 'oc-theme' ); ?></a></p>
		<style>
			.ocst-w{display:grid;grid-template-columns:repeat(2,1fr);gap:8px}
			.ocst-w__tile{background:#f6f7f7;border-radius:8px;padding:10px 12px;display:flex;flex-direction:column;gap:2px}
			.ocst-w__tile span{font-size:12px;color:#646970}
			.ocst-w__tile b{font-size:20px;line-height:1.1;font-variant-numeric:tabular-nums}
			.ocst-w__d{font-style:normal;font-size:12px;font-weight:600}
			.ocst-w__d--up{color:#1e7d46}.ocst-w__d--down{color:#b32d2e}.ocst-w__d--flat{color:#8c8f94}
			.ocst-w__note{margin:10px 0 0;color:#646970;font-size:12px}
		</style>
		<?php
	}
}
