<?php
/**
 * Dashboard tiles: one small box per thing the shop owner would otherwise
 * have to go looking for — the waitlist, the thank-you ratings, contact
 * clicks, form leads, popular searches, unused pictures and heavy ones.
 * Each shows a number, a line or two under it, and the way to its screen.
 *
 * @package OC_Theme
 */

namespace OC\Theme;

defined( 'ABSPATH' ) || exit;

/**
 * The tiles.
 */
class Dashboard {

	/**
	 * How far back "recent" reaches, in days.
	 */
	const DAYS = 30;

	/**
	 * The heavy-pictures count is kept this long.
	 */
	const HEAVY_CACHE = 'oc_dash_heavy';

	/**
	 * Hook in.
	 */
	public function register(): void {
		add_action( 'wp_dashboard_setup', array( $this, 'widgets' ), 11 ); // After the statistics widget, so Sales today leads the column.
		add_action( 'admin_print_styles-index.php', array( $this, 'styles' ) );
		add_action( 'admin_notices', array( $this, 'notice' ) );
		add_action( 'add_attachment', array( __CLASS__, 'forget_heavy' ) );
		add_action( 'delete_attachment', array( __CLASS__, 'forget_heavy' ) );
		add_filter( 'wp_update_attachment_metadata', array( __CLASS__, 'forget_heavy_meta' ), 20 );
	}

	/**
	 * Register the tiles, for those who may see the screens behind them.
	 */
	public function widgets(): void {
		// Three columns, ahead of WordPress's own boxes: sales and traffic
		// first, then what customers did, then the library. Anyone who has
		// dragged the boxes keeps their own arrangement.
		if ( current_user_can( 'manage_woocommerce' ) ) {
			wp_add_dashboard_widget( 'oc_dash_search', __( 'Popular searches', 'oc-theme' ), array( $this, 'searches' ), null, null, 'normal', 'high' );
			wp_add_dashboard_widget( 'oc_dash_waitlist', __( 'Back-in-stock waitlist', 'oc-theme' ), array( $this, 'waitlist' ), null, null, 'side', 'high' );
		}

		if ( current_user_can( 'manage_options' ) && post_type_exists( 'oc_lead' ) ) {
			wp_add_dashboard_widget( 'oc_dash_leads', __( 'Leads', 'oc-theme' ), array( $this, 'leads' ), null, null, 'side', 'high' );
		}

		if ( current_user_can( 'manage_woocommerce' ) ) {
			wp_add_dashboard_widget( 'oc_dash_contact', __( 'Product contact', 'oc-theme' ), array( $this, 'contact' ), null, null, 'side', 'high' );
			wp_add_dashboard_widget( 'oc_dash_ratings', __( 'Thank-you page ratings', 'oc-theme' ), array( $this, 'ratings' ), null, null, 'column3', 'high' );
		}

		if ( current_user_can( 'manage_options' ) ) {
			wp_add_dashboard_widget( 'oc_dash_media', __( 'Media cleanup', 'oc-theme' ), array( $this, 'media' ), null, null, 'column3', 'high' );
			wp_add_dashboard_widget( 'oc_dash_heavy', __( 'Heavy pictures', 'oc-theme' ), array( $this, 'heavy' ), null, null, 'column3', 'high' );
		}
	}

	/**
	 * One style block for all the tiles.
	 */
	public function styles(): void {
		echo '<style>
			.ocd{display:flex;flex-direction:column;gap:6px}
			.ocd__big{display:flex;align-items:baseline;gap:8px;flex-wrap:wrap}
			.ocd__n{font-size:28px;line-height:1.1;font-weight:600;font-variant-numeric:tabular-nums}
			.ocd__l{color:#646970}
			.ocd__rows{margin:2px 0 0;padding:0;list-style:none;display:grid;gap:3px}
			.ocd__rows li{display:flex;justify-content:space-between;gap:12px;font-size:13px}
			.ocd__rows li b{font-weight:600;font-variant-numeric:tabular-nums;white-space:nowrap}
			.ocd__rows li span a{text-decoration:none}
			.ocd__when{color:#8c8f94;font-size:12px;font-variant-numeric:tabular-nums}
			.ocd__stars{color:#e0a100;letter-spacing:1px;font-size:13px}
			.ocd__stars i{font-style:normal;color:#dcdcde}
			.ocd__chip{font-style:normal;display:inline-block;padding:1px 8px;border-radius:99px;font-size:11.5px;font-weight:600;background:#f0f0f1;color:#50575e}
			.ocd__chip--new{background:#e5f0ff;color:#0a4b94}.ocd__chip--progress{background:#fff4d6;color:#7a4b00}.ocd__chip--waiting{background:#f3e8ff;color:#5b2c8f}.ocd__chip--done{background:#e3f6e8;color:#1e6b34}.ocd__chip--irrelevant{background:#f0f0f1;color:#646970}
			.ocd__rows li span{color:#3c434a;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
			.ocd__note{margin:0;color:#646970;font-size:12px}
			.ocd__wrap{overflow-wrap:anywhere;line-height:1.5}
			.ocd__go{margin-top:4px;font-size:13px;text-decoration:none}
			.ocd__ok{color:#1e7d46}.ocd__warn{color:#b32d2e}
		</style>';
	}

	/**
	 * After "Scan now": say the scan is under way.
	 */
	public function notice(): void {
		if ( isset( $_GET['ocmc_scan'] ) && function_exists( 'get_current_screen' ) && get_current_screen() && 'dashboard' === get_current_screen()->id ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a notice only.
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'The media scan is running in the background; the tile updates when it finishes.', 'oc-theme' ) . '</p></div>';
		}
	}

	/* ------------------------------------------------------------- tiles */

	/**
	 * Who is waiting for what.
	 */
	public function waitlist(): void {
		global $wpdb;

		$metas    = (array) $wpdb->get_results( $wpdb->prepare( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s", '_oc_notify_list' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one indexed read on the dashboard.
		$people   = 0;
		$products = 0;
		$per      = array();

		foreach ( $metas as $m ) {
			$list = maybe_unserialize( $m->meta_value );

			if ( ! is_array( $list ) || ! $list ) {
				continue;
			}

			++$products;
			$per[ (int) $m->post_id ] = count( $list );

			$people += count( $list );
		}

		arsort( $per );
		$rows = array();

		foreach ( array_slice( $per, 0, 6, true ) as $pid => $n ) {
			$title  = get_the_title( (int) $pid );
			$rows[] = array( '<a href="' . esc_url( (string) get_edit_post_link( (int) $pid, 'raw' ) ) . '">' . esc_html( '' === $title ? '#' . $pid : $title ) . '</a>', number_format_i18n( $n ) );
		}

		if ( count( $per ) > 6 ) {
			/* translators: %s: number of further products */
			$rows[] = array( esc_html( sprintf( __( '… and %s more products', 'oc-theme' ), number_format_i18n( count( $per ) - 6 ) ) ), '' );
		}

		$this->tile(
			$people,
			__( 'people waiting', 'oc-theme' ),
			$rows,
			admin_url( 'admin.php?page=oc-waitlist&tab=waiting' ),
			$people ? '' : __( 'Nobody is waiting right now.', 'oc-theme' ),
			true
		);
	}

	/**
	 * The stars left on the thank-you page, and who left the latest ones.
	 */
	public function ratings(): void {
		$agg   = get_option( 'oc_ty_survey' );
		$count = is_array( $agg ) ? (int) ( $agg['count'] ?? 0 ) : 0;
		$sum   = is_array( $agg ) ? (int) ( $agg['sum'] ?? 0 ) : 0;
		$avg   = $count > 0 ? $sum / $count : 0;
		$rows  = array();

		if ( function_exists( 'wc_get_orders' ) ) {
			$orders = wc_get_orders(
				array(
					'limit'      => 6,
					'orderby'    => 'date',
					'order'      => 'DESC',
					'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- an admin tile.
						array(
							'key'     => '_oc_ty_rating',
							'compare' => 'EXISTS',
						),
					),
				)
			);

			foreach ( (array) $orders as $order ) {
				if ( ! $order instanceof \WC_Order ) {
					continue;
				}

				$v = max( 0, min( 5, (int) $order->get_meta( '_oc_ty_rating' ) ) );

				if ( 0 === $v ) {
					continue;
				}

				$name    = trim( (string) $order->get_billing_first_name() . ' ' . (string) $order->get_billing_last_name() );
				$comment = trim( (string) $order->get_meta( '_oc_ty_comment' ) );
				$when    = $order->get_date_created() ? $order->get_date_created()->date_i18n( 'j.n' ) : '';
				$rows[]  = array(
					'<span class="ocd__when">' . esc_html( $when ) . '</span> <a href="' . esc_url( (string) $order->get_edit_order_url() ) . '"' . ( '' !== $comment ? ' title="' . esc_attr( $comment ) . '"' : '' ) . '>' . esc_html( '' === $name ? '#' . $order->get_order_number() : $name ) . '</a>',
					'<span class="ocd__stars" title="' . esc_attr( (string) $v ) . '/5">' . str_repeat( '★', $v ) . '<i>' . str_repeat( '★', 5 - $v ) . '</i></span>',
				);
			}
		}

		$rate = class_exists( __NAMESPACE__ . '\\Thankyou' ) ? Thankyou::response_rate( self::DAYS ) : array( 'rate' => null );
		/* translators: %s: number of ratings */
		$label = sprintf( _n( '%s rating', '%s ratings', $count, 'oc-theme' ), number_format_i18n( $count ) );

		if ( null !== $rate['rate'] ) {
			/* translators: 1: how many ratings in all, 2: a percentage of the orders */
			$label = sprintf( __( '%1$s in all · %2$s%% of the orders this month left one', 'oc-theme' ), $label, number_format_i18n( (float) $rate['rate'], 1 ) );
		}

		$this->tile(
			$count > 0 ? number_format_i18n( $avg, 1 ) . ' ★' : '—',
			$label,
			$rows,
			admin_url( 'admin.php?page=oc-thankyou&tab=ratings' ),
			$count > 0 ? '' : __( 'No ratings yet. Switch the survey on in Thank-you page settings.', 'oc-theme' ),
			true
		);
	}

	/**
	 * WhatsApp and phone taps on product pages: which products, most
	 * pressed first, with the day of the latest tap.
	 */
	public function contact(): void {
		$on   = class_exists( __NAMESPACE__ . '\\Product_Contact' ) && '1' === (string) get_option( 'oc_contact_table', '' );
		$rows = $on ? Product_Contact::stats( wp_date( 'Y-m-d', time() - ( self::DAYS - 1 ) * DAY_IN_SECONDS ), wp_date( 'Y-m-d' ) ) : array();
		$wa   = 0;
		$tel  = 0;

		foreach ( $rows as $r ) {
			$wa  += (int) $r['whatsapp'];
			$tel += (int) $r['phone'];
		}

		usort(
			$rows,
			static function ( array $a, array $b ): int {
				return array( (int) $b['total'], (string) $b['last'] ) <=> array( (int) $a['total'], (string) $a['last'] );
			}
		);

		$list = array();

		foreach ( array_slice( $rows, 0, 6 ) as $r ) {
			$pid    = (int) $r['product_id'];
			$title  = get_the_title( $pid );
			$when   = '' !== (string) $r['last'] ? wp_date( 'j.n', strtotime( $r['last'] . ' UTC' ) ) : '';
			$list[] = array(
				( '' !== $when ? '<span class="ocd__when">' . esc_html( $when ) . '</span> ' : '' ) . '<a href="' . esc_url( (string) get_edit_post_link( $pid, 'raw' ) ) . '">' . esc_html( '' === $title ? '#' . $pid : $title ) . '</a>',
				esc_html( number_format_i18n( (int) $r['total'] ) ),
			);
		}

		$this->tile(
			$wa + $tel,
			/* translators: 1: WhatsApp taps, 2: phone taps */
			sprintf( __( 'taps in the last 30 days · WhatsApp %1$s · phone %2$s', 'oc-theme' ), number_format_i18n( $wa ), number_format_i18n( $tel ) ),
			$list,
			admin_url( 'options-general.php?page=oc-product-contact' ),
			$on ? ( $list ? '' : __( 'No taps in the last 30 days.', 'oc-theme' ) ) : __( 'The contact card is not switched on.', 'oc-theme' ),
			true
		);
	}

	/**
	 * The latest leads and where each one stands.
	 */
	public function leads(): void {
		$labels = class_exists( '\\OC\\Blocks\\Leads' ) ? \OC\Blocks\Leads::statuses() : array();
		$latest = get_posts(
			array(
				'post_type'      => 'oc_lead',
				'post_status'    => 'publish',
				'posts_per_page' => 6,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);
		$rows   = array();

		foreach ( $latest as $lead ) {
			$st     = class_exists( '\\OC\\Blocks\\Leads' ) ? \OC\Blocks\Leads::status( (int) $lead->ID ) : 'new';
			$label  = $labels[ $st ] ?? $st;
			$when   = mysql2date( 'j.n', $lead->post_date );
			$rows[] = array(
				'<span class="ocd__when">' . esc_html( $when ) . '</span> <a href="' . esc_url( (string) get_edit_post_link( (int) $lead->ID, 'raw' ) ) . '">' . esc_html( '' === $lead->post_title ? '#' . $lead->ID : $lead->post_title ) . '</a>',
				'<i class="ocd__chip ocd__chip--' . esc_attr( $st ) . '">' . esc_html( $label ) . '</i>',
			);
		}

		$by = static function ( array $clause ): int {
			$q = new \WP_Query(
				array(
					'post_type'      => 'oc_lead',
					'post_status'    => 'publish',
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'meta_query'     => array( $clause ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- a dashboard tile.
				)
			);

			return (int) $q->found_posts;
		};

		$fresh = $by(
			array(
				'relation' => 'OR',
				array(
					'key'   => '_oc_lead_status',
					'value' => 'new',
				),
				array(
					'key'     => '_oc_lead_status',
					'compare' => 'NOT EXISTS',
				),
			)
		);
		$open  = $by(
			array(
				'key'   => '_oc_lead_status',
				'value' => 'progress',
			)
		);
		$wait  = $by(
			array(
				'key'   => '_oc_lead_status',
				'value' => 'waiting',
			)
		);

		$this->tile(
			$fresh,
			/* translators: 1: leads in progress, 2: leads waiting for the customer */
			sprintf( __( 'new leads to handle · %1$s in progress · %2$s waiting for the customer', 'oc-theme' ), number_format_i18n( $open ), number_format_i18n( $wait ) ),
			$rows,
			admin_url( 'edit.php?post_type=oc_lead&oc_status=new' ),
			$rows ? '' : __( 'No leads yet.', 'oc-theme' ),
			true
		);
	}

	/**
	 * What people looked for, and what they did not find.
	 */
	public function searches(): void {
		if ( ! class_exists( __NAMESPACE__ . '\\Search' ) ) {
			return;
		}

		$rows   = Search::popular_terms( self::DAYS, 5 );
		$misses = Search::popular_terms( self::DAYS, 3, true );
		$total  = 0;
		$list   = array();

		foreach ( $rows as $r ) {
			$total += (int) $r->searches;
			$t      = (string) $r->term;
			$list[] = array( mb_strlen( $t ) > 28 ? mb_substr( $t, 0, 27 ) . '…' : $t, number_format_i18n( (int) $r->searches ) );
		}

		$after = '';

		if ( $misses ) {
			$short = static function ( $m ): string {
				$t = (string) $m->term;
				return mb_strlen( $t ) > 22 ? mb_substr( $t, 0, 21 ) . '…' : $t;
			};
			$after = '<p class="ocd__note ocd__wrap"><i class="ocd__warn">' . esc_html__( 'Searched, found nothing', 'oc-theme' ) . ':</i> ' . esc_html( implode( ' · ', array_map( $short, $misses ) ) ) . '</p>';
		}

		$this->tile(
			$total,
			__( 'searches for the top terms, last 30 days', 'oc-theme' ),
			$list,
			admin_url( 'admin.php?page=oc-search&tab=reports' ),
			$rows ? '' : __( 'No searches recorded in the last 30 days.', 'oc-theme' ),
			false,
			array(),
			$after
		);
	}

	/**
	 * Pictures nothing uses, from the last scan — which runs by itself
	 * once a week in the background.
	 */
	public function media(): void {
		$last    = class_exists( __NAMESPACE__ . '\\Media_Clean' ) ? Media_Clean::last() : null;
		$running = class_exists( __NAMESPACE__ . '\\Media_Clean' ) && Media_Clean::running();
		$rows    = array();

		if ( $last ) {
			$rows = array(
				array( __( 'Used only by drafts', 'oc-theme' ), number_format_i18n( (int) $last['draft'] ) ),
				array( __( 'Used only by trashed items', 'oc-theme' ), number_format_i18n( (int) $last['trash'] ) ),
				array( __( 'In use', 'oc-theme' ), number_format_i18n( (int) $last['used'] ) ),
				/* translators: %s: date and time */
				array( sprintf( __( 'Scanned %s', 'oc-theme' ), wp_date( 'j.n.Y H:i', (int) $last['when'] ) ), '' ),
			);
		}

		$links = array( array( __( 'Open the screen', 'oc-theme' ), admin_url( 'upload.php?page=oc-media-clean' ) ) );

		if ( $running ) {
			$rows[] = array( '<i class="ocd__ok">' . esc_html__( 'A scan is running in the background…', 'oc-theme' ) . '</i>', '' );
		} else {
			$links[] = array( __( 'Scan now', 'oc-theme' ), wp_nonce_url( admin_url( 'admin-post.php?action=ocmc_scan_now' ), 'ocmc_scan_now' ) );
		}

		$this->tile(
			$last ? (int) $last['orphan'] : '—',
			__( 'pictures nothing uses', 'oc-theme' ),
			$rows,
			admin_url( 'upload.php?page=oc-media-clean' ),
			$last || $running ? '' : __( 'The first scan runs by itself within the hour, in the background.', 'oc-theme' ),
			true,
			$links
		);
	}

	/**
	 * Pictures over half a megabyte, over a megabyte, and not yet WebP.
	 */
	public function heavy(): void {
		$c = self::heavy_counts();

		$this->tile(
			$c['over500'],
			__( 'pictures over 500 KB', 'oc-theme' ),
			array(
				array( __( 'Over 1 MB', 'oc-theme' ), number_format_i18n( $c['over1m'] ) ),
				array( __( 'Not yet WebP (JPEG / PNG)', 'oc-theme' ), number_format_i18n( $c['raster'] ) ),
				array( __( 'Pictures in the library', 'oc-theme' ), number_format_i18n( $c['images'] ) ),
			),
			admin_url( 'upload.php?page=oc-media-clean' ),
			'',
			false,
			array(
				array( __( 'Heavy files', 'oc-theme' ), admin_url( 'upload.php?page=oc-media-clean' ) ),
				array( __( 'Convert to WebP', 'oc-theme' ), admin_url( 'upload.php?page=oc-media-webp' ) ),
			)
		);
	}

	/**
	 * The counts behind the heavy tile, from attachment metadata, kept
	 * for half a day and forgotten when the library changes.
	 *
	 * @return array{over500:int,over1m:int,raster:int,images:int}
	 */
	public static function heavy_counts(): array {
		$c = get_transient( self::HEAVY_CACHE );

		if ( is_array( $c ) && isset( $c['over500'] ) ) {
			return $c;
		}

		global $wpdb;

		$c = array(
			'over500' => 0,
			'over1m'  => 0,
			'raster'  => 0,
			'images'  => 0,
		);

		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- one pass over the library, cached.
		$c['raster'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type IN ( 'image/jpeg', 'image/png' )" );
		$rows        = (array) $wpdb->get_results( "SELECT m.meta_value AS meta FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_wp_attachment_metadata' WHERE p.post_type = 'attachment' AND p.post_mime_type LIKE 'image/%'" );
		// phpcs:enable

		foreach ( $rows as $row ) {
			++$c['images'];
			$meta  = is_string( $row->meta ) ? maybe_unserialize( $row->meta ) : array();
			$bytes = is_array( $meta ) ? (int) ( $meta['filesize'] ?? 0 ) : 0;

			if ( $bytes >= 1048576 ) {
				++$c['over1m'];
			}

			if ( $bytes >= 512000 ) {
				++$c['over500'];
			}
		}

		set_transient( self::HEAVY_CACHE, $c, 12 * HOUR_IN_SECONDS );

		return $c;
	}

	/**
	 * The library changed: count again next time.
	 */
	public static function forget_heavy(): void {
		delete_transient( self::HEAVY_CACHE );
	}

	/**
	 * A filter: the metadata goes back untouched.
	 *
	 * @param mixed $data Attachment metadata.
	 * @return mixed
	 */
	public static function forget_heavy_meta( $data ) {
		self::forget_heavy();

		return $data;
	}

	/* ------------------------------------------------------------ markup */

	/**
	 * One tile.
	 *
	 * @param int|string $big   The number.
	 * @param string     $label What it counts.
	 * @param array      $rows  [label, value] pairs.
	 * @param string     $url   The screen.
	 * @param string     $none A sentence instead of rows when there is nothing.
	 * @param bool       $raw   Rows carry markup of their own.
	 * @param array      $links Extra [label, url] links instead of the single one.
	 * @param string     $after Markup after the rows, already escaped.
	 */
	private function tile( $big, string $label, array $rows, string $url, string $none = '', bool $raw = false, array $links = array(), string $after = '' ): void {
		echo '<div class="ocd">';
		echo '<div class="ocd__big"><b class="ocd__n"><bdi dir="ltr">' . esc_html( is_int( $big ) ? number_format_i18n( $big ) : (string) $big ) . '</bdi></b><span class="ocd__l">' . esc_html( $label ) . '</span></div>';

		if ( '' !== $none ) {
			echo '<p class="ocd__note">' . esc_html( $none ) . '</p>';
		} elseif ( $rows ) {
			echo '<ul class="ocd__rows">';

			foreach ( $rows as $r ) {
				echo '<li><span>' . ( $raw ? wp_kses_post( (string) $r[0] ) : esc_html( (string) $r[0] ) ) . '</span><b>' . ( $raw ? wp_kses_post( (string) $r[1] ) : '<bdi dir="ltr">' . esc_html( (string) $r[1] ) . '</bdi>' ) . '</b></li>';
			}

			echo '</ul>';
		}

		if ( '' !== $after ) {
			echo $after; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the caller.
		}

		if ( $links ) {
			$bits = array();

			foreach ( $links as $l ) {
				$bits[] = '<a class="ocd__go" href="' . esc_url( (string) $l[1] ) . '">' . esc_html( (string) $l[0] ) . ' ←</a>';
			}

			echo '<div>' . implode( ' &nbsp;·&nbsp; ', $bits ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
		} else {
			echo '<a class="ocd__go" href="' . esc_url( $url ) . '">' . esc_html__( 'Open the screen', 'oc-theme' ) . ' ←</a>';
		}

		echo '</div>';
	}
}
