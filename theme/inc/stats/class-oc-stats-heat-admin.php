<?php
/**
 * The heat map screens: a table of the pages people actually look at, and
 * a viewer that draws the marks over the page itself.
 *
 * The viewer is a front-end overlay because a map only means something on
 * top of the layout it was recorded against. The page is shown in a frame
 * at the width of the device whose marks are being drawn — a click made on
 * a phone sits somewhere else entirely in a desktop layout.
 *
 * @package OC_Theme
 */

namespace OC\Theme\Stats;

defined( 'ABSPATH' ) || exit;

/**
 * Screens, route and the way in from the toolbar.
 */
class Heat_Admin {

	/**
	 * The submenu slug.
	 */
	const PAGE = 'oc-stats-heat';

	/**
	 * The widths a map is drawn at.
	 */
	const WIDTHS = array(
		'm' => 390,
		't' => 768,
		'd' => 1280,
	);

	/**
	 * Hook in.
	 */
	public function register(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		add_action( 'admin_menu', array( $this, 'menu' ), 12 );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_assets' ) );
		add_action( 'rest_api_init', array( $this, 'rest' ) );
		add_action( 'admin_bar_menu', array( $this, 'toolbar' ), 80 );
		add_action( 'wp_enqueue_scripts', array( $this, 'viewer_assets' ) );
		add_action( 'admin_post_oc_heat_save', array( $this, 'save' ) );
	}

	/**
	 * Under Statistics.
	 */
	public function menu(): void {
		add_submenu_page( Admin::PAGE, __( 'Heat maps', 'oc-theme' ), __( 'Heat maps', 'oc-theme' ), Admin::cap(), self::PAGE, array( $this, 'screen' ) );
	}

	/* ------------------------------------------------------------- table */

	/**
	 * The screen: which pages people look at, and the way into each map.
	 */
	public function screen(): void {
		if ( ! current_user_can( Admin::cap() ) ) {
			wp_die( esc_html__( 'Not allowed.', 'oc-theme' ) );
		}

		Heat::install();

		$ranges = array(
			'today'     => __( 'Today', 'oc-theme' ),
			'yesterday' => __( 'Yesterday', 'oc-theme' ),
			'd7'        => __( '7 days', 'oc-theme' ),
			'd30'       => __( '30 days', 'oc-theme' ),
			'd90'       => __( '90 days', 'oc-theme' ),
		);

		$now = isset( $_GET['range'] ) ? sanitize_key( wp_unslash( $_GET['range'] ) ) : 'd7'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a range picker.
		$now = isset( $ranges[ $now ] ) ? $now : 'd7';
		$r   = Query::range( $now );
		$set = Heat::set();
		$s   = Heat::settings();
		$top = Heat::top_pages( (string) $r['from'], (string) $r['to'] );
		$all = 0;

		foreach ( $top as $row ) {
			$all += $row['views'];
		}
		?>
		<div class="wrap ocst">
			<h1><?php esc_html_e( 'Heat maps', 'oc-theme' ); ?></h1>
			<p class="ocst__sub">
				<?php esc_html_e( 'Where people look, and where they click. Only the pages below are recorded, and the visitor pays nothing for it: the marks are counted in the browser and sent once, as the page closes.', 'oc-theme' ); ?>
			</p>

			<div class="ocst__pickers" style="margin-block-end:14px;">
				<?php foreach ( $ranges as $key => $label ) : ?>
					<a class="ocst__pill" aria-pressed="<?php echo $key === $now ? 'true' : 'false'; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE . '&range=' . $key ) ); ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</div>

			<?php if ( ! $top ) : ?>
				<div class="ocst__card">
					<p><?php esc_html_e( 'Nothing recorded in this period yet. The pages below are the ones being watched; a map appears once people have visited them.', 'oc-theme' ); ?></p>
					<p class="ocst__note"><?php echo esc_html( sprintf( /* translators: %s: a date and time */ __( 'The list of watched pages was last worked out at %s.', 'oc-theme' ), '' === $set['when'] ? '—' : $set['when'] ) ); ?></p>
				</div>
			<?php else : ?>
				<div class="ocst__card">
					<table class="ocst__tbl">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Page', 'oc-theme' ); ?></th>
								<th class="n"><?php esc_html_e( 'Views', 'oc-theme' ); ?></th>
								<th class="n"><?php esc_html_e( 'Share', 'oc-theme' ); ?></th>
								<th class="n"><?php esc_html_e( 'Mobile', 'oc-theme' ); ?></th>
								<th class="n"><?php esc_html_e( 'Tablet', 'oc-theme' ); ?></th>
								<th class="n"><?php esc_html_e( 'Desktop', 'oc-theme' ); ?></th>
								<th></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $top as $row ) : ?>
								<?php
								$best = 'd';

								if ( $row['m'] >= $row['t'] && $row['m'] >= $row['d'] ) {
									$best = 'm';
								} elseif ( $row['t'] >= $row['d'] ) {
									$best = 't';
								}

								$view = add_query_arg(
									array(
										'oc_heat' => 1,
										'hp'      => $row['id'],
										'hr'      => $now,
										'hd'      => $best,
									),
									home_url( $row['path'] )
								);
								?>
								<tr>
									<td>
										<strong><?php echo esc_html( '' === $row['label'] ? $row['path'] : $row['label'] ); ?></strong><br>
										<a href="<?php echo esc_url( home_url( $row['path'] ) ); ?>" class="ocst__note" target="_blank" rel="noopener"><?php echo esc_html( $row['path'] ); ?></a>
									</td>
									<td class="n"><bdi dir="ltr"><?php echo esc_html( number_format_i18n( $row['views'] ) ); ?></bdi></td>
									<td class="n"><bdi dir="ltr"><?php echo esc_html( $all > 0 ? round( $row['views'] / $all * 100 ) . '%' : '—' ); ?></bdi></td>
									<td class="n"><bdi dir="ltr"><?php echo esc_html( number_format_i18n( $row['m'] ) ); ?></bdi></td>
									<td class="n"><bdi dir="ltr"><?php echo esc_html( number_format_i18n( $row['t'] ) ); ?></bdi></td>
									<td class="n"><bdi dir="ltr"><?php echo esc_html( number_format_i18n( $row['d'] ) ); ?></bdi></td>
									<td class="n"><a class="button button-primary" href="<?php echo esc_url( $view ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View the heat map', 'oc-theme' ); ?></a></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>

			<div class="ocst__card" style="margin-block-start:16px;">
				<h2><?php esc_html_e( 'What is recorded', 'oc-theme' ); ?></h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="oc_heat_save">
					<?php wp_nonce_field( 'oc_heat_save' ); ?>
					<p>
						<label><input type="checkbox" name="on" value="1" <?php checked( 1, $s['on'] ); ?>> <strong><?php esc_html_e( 'Record heat maps', 'oc-theme' ); ?></strong></label>
					</p>
					<p class="ocst__note"><?php esc_html_e( 'Switching this off stops the recording everywhere at once. What was gathered stays.', 'oc-theme' ); ?></p>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Pages', 'oc-theme' ); ?></th>
							<td>
								<?php
								$names = array(
									'home'     => __( 'Home page', 'oc-theme' ),
									'product'  => __( 'The most visited products', 'oc-theme' ),
									'slow'     => __( 'Products many look at and few buy', 'oc-theme' ),
									'cat'      => __( 'The most visited categories', 'oc-theme' ),
									'cart'     => __( 'Cart', 'oc-theme' ),
									'checkout' => __( 'Checkout', 'oc-theme' ),
									'search'   => __( 'Search results', 'oc-theme' ),
									'e404'     => __( 'Page not found', 'oc-theme' ),
								);

								foreach ( $names as $key => $label ) {
									echo '<label style="display:block;margin-block-end:4px"><input type="checkbox" name="kinds[]" value="' . esc_attr( $key ) . '" ' . checked( 1, $s['kinds'][ $key ] ?? 0, false ) . '> ' . esc_html( $label ) . '</label>';
								}
								?>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="oc-heat-per"><?php esc_html_e( 'How many of each', 'oc-theme' ); ?></label></th>
							<td>
								<input type="number" id="oc-heat-per" name="per" min="1" max="20" value="<?php echo esc_attr( (string) $s['per'] ); ?>" class="small-text">
								<p class="description"><?php esc_html_e( 'Products and categories are chosen by traffic and worked out again every hour.', 'oc-theme' ); ?></p>
							</td>
						</tr>
					</table>
					<p><button class="button button-primary"><?php esc_html_e( 'Save', 'oc-theme' ); ?></button></p>
				</form>
			</div>
		</div>
		<?php
	}

	/**
	 * Save the settings.
	 */
	public function save(): void {
		if ( ! current_user_can( Admin::cap() ) || ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['_wpnonce'] ) ), 'oc_heat_save' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'oc-theme' ) );
		}

		$picked = isset( $_POST['kinds'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['kinds'] ) ) : array();
		$kinds  = array();

		foreach ( Heat::KINDS as $k ) {
			$kinds[ $k ] = in_array( $k, $picked, true ) ? 1 : 0;
		}

		update_option(
			Heat::OPTION,
			array(
				'on'    => isset( $_POST['on'] ) ? 1 : 0,
				'kinds' => $kinds,
				'per'   => isset( $_POST['per'] ) ? max( 1, min( 20, absint( wp_unslash( $_POST['per'] ) ) ) ) : 5,
			),
			false
		);

		Heat::refresh();
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE . '&saved=1' ) );
		exit;
	}

	/**
	 * The statistics stylesheet, for the table on this screen.
	 *
	 * @param string $hook Screen hook.
	 */
	public function admin_assets( $hook ): void {
		if ( false === strpos( (string) $hook, self::PAGE ) ) {
			return;
		}

		wp_enqueue_style( 'oc-stats-admin', get_template_directory_uri() . '/assets/css/stats-admin.css', array(), defined( 'OC_THEME_VERSION' ) ? OC_THEME_VERSION : '1' );
	}

	/* -------------------------------------------------------- the viewer */

	/**
	 * A way in from the toolbar, on any page of the shop.
	 *
	 * @param \WP_Admin_Bar $bar The toolbar.
	 */
	public function toolbar( $bar ): void {
		if ( is_admin() || ! current_user_can( Admin::cap() ) || ! Heat::on() || ! $bar instanceof \WP_Admin_Bar ) {
			return;
		}

		$bar->add_node(
			array(
				'id'    => 'oc-heat',
				'title' => __( 'Heat map', 'oc-theme' ),
				'href'  => add_query_arg( 'oc_heat', 1 ),
				'meta'  => array( 'title' => __( 'See where people click on this page', 'oc-theme' ) ),
			)
		);
	}

	/**
	 * Is the viewer being asked for?
	 */
	private function wanted(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a view of your own site's numbers, gated on the capability.
		return isset( $_GET['oc_heat'] ) && ! isset( $_GET['oc_heat_frame'] ) && current_user_can( Admin::cap() ) && Heat::on();
	}

	/**
	 * The viewer's own script and style, for an administrator who asked.
	 */
	public function viewer_assets(): void {
		if ( ! $this->wanted() ) {
			return;
		}

		$dir = get_template_directory_uri() . '/assets';
		$ver = defined( 'OC_THEME_VERSION' ) ? OC_THEME_VERSION : '1';

		wp_enqueue_style( 'oc-heat', $dir . '/css/heat.css', array(), $ver );
		wp_enqueue_script( 'oc-heat', $dir . '/js/heat.js', array(), $ver, true );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- which map to open.
		$page = isset( $_GET['hp'] ) ? absint( wp_unslash( $_GET['hp'] ) ) : 0;
		$rng  = isset( $_GET['hr'] ) ? sanitize_key( wp_unslash( $_GET['hr'] ) ) : 'd7';
		$dev  = isset( $_GET['hd'] ) ? sanitize_key( wp_unslash( $_GET['hd'] ) ) : 'm';
		// phpcs:enable

		wp_add_inline_script(
			'oc-heat',
			'window.ocHeatView = ' . wp_json_encode(
				array(
					'rest'   => rest_url( 'oc/v1/heat/map' ),
					'nonce'  => wp_create_nonce( 'wp_rest' ),
					'path'   => Heat::path( (string) wp_parse_url( home_url( add_query_arg( array() ) ), PHP_URL_PATH ) ),
					'page'   => $page,
					'range'  => $rng,
					'device' => isset( self::WIDTHS[ $dev ] ) ? $dev : 'm',
					'widths' => self::WIDTHS,
					'i18n'   => array(
						'title'    => __( 'Heat map', 'oc-theme' ),
						'clicks'   => __( 'Clicks', 'oc-theme' ),
						'dead'     => __( 'Dead clicks', 'oc-theme' ),
						'rage'     => __( 'Rage clicks', 'oc-theme' ),
						'attn'     => __( 'Attention', 'oc-theme' ),
						'everyone' => __( 'Everyone', 'oc-theme' ),
						'buyers'   => __( 'Visits that bought', 'oc-theme' ),
						'mobile'   => __( 'Mobile', 'oc-theme' ),
						'tablet'   => __( 'Tablet', 'oc-theme' ),
						'desktop'  => __( 'Desktop', 'oc-theme' ),
						'today'    => __( 'Today', 'oc-theme' ),
						'd7'       => __( '7 days', 'oc-theme' ),
						'd30'      => __( '30 days', 'oc-theme' ),
						'd90'      => __( '90 days', 'oc-theme' ),
						'views'    => __( 'views', 'oc-theme' ),
						'ofClicks' => __( 'of the clicks on this page', 'oc-theme' ),
						'reach'    => __( 'Half the visitors never go below this line', 'oc-theme' ),
						'none'     => __( 'Nothing recorded for this page, device and period yet.', 'oc-theme' ),
						'close'    => __( 'Close', 'oc-theme' ),
						'spots'    => __( 'The places people press', 'oc-theme' ),
						'deadNote' => __( 'A click on something that does nothing. Where people expected a link and did not find one.', 'oc-theme' ),
					),
				)
			) . ';',
			'before'
		);
	}

	/**
	 * The map itself, for the screen that draws it.
	 */
	public function rest(): void {
		register_rest_route(
			'oc/v1',
			'/heat/map',
			array(
				'methods'             => 'GET',
				'permission_callback' => static function (): bool {
					return current_user_can( Admin::cap() );
				},
				'callback'            => array( $this, 'rest_map' ),
			)
		);
	}

	/**
	 * One page's marks.
	 *
	 * @param \WP_REST_Request $req Request.
	 */
	public function rest_map( \WP_REST_Request $req ) {
		$page = absint( $req->get_param( 'page' ) );

		if ( ! $page ) {
			$page = self::page_of( Heat::path( (string) $req->get_param( 'path' ) ) );
		}

		if ( ! $page ) {
			return rest_ensure_response( array( 'views' => 0, 'clicks' => 0, 'marks' => array(), 'bands' => array() ) );
		}

		$r   = Query::range( sanitize_key( (string) $req->get_param( 'range' ) ) );
		$dev = sanitize_key( (string) $req->get_param( 'device' ) );
		$dev = isset( self::WIDTHS[ $dev ] ) ? $dev : 'm';
		$seg = 'b' === sanitize_key( (string) $req->get_param( 'seg' ) ) ? 'b' : 'a';

		return rest_ensure_response( Heat::map( $page, $dev, (string) $r['from'], (string) $r['to'], $seg ) );
	}

	/**
	 * The page row for a path, if there is one.
	 *
	 * @param string $path Path.
	 */
	private static function page_of( string $path ): int {
		global $wpdb;

		$t = $wpdb->prefix . Heat::PAGES;

		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t} WHERE path = %s", $path ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table.
	}
}
