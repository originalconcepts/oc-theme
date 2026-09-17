<?php
/**
 * Sales figures on the product edit screen: how often the product was
 * ordered, how much it brought in, and when it last sold — for the last
 * thirty days and since the beginning. Read from WooCommerce's own
 * order lookup tables, so the box costs one small query per column.
 *
 * @package OC_Theme
 */

namespace OC\Theme\Stats;

defined( 'ABSPATH' ) || exit;

/**
 * The "Sales" box on the product edit screen.
 */
class Product {

	/**
	 * Hook in.
	 */
	public function register(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		add_action( 'add_meta_boxes_product', array( $this, 'box' ) );
	}

	/**
	 * Add the box, for people who may see the statistics.
	 */
	public function box(): void {
		if ( ! current_user_can( Admin::cap() ) ) {
			return;
		}

		add_meta_box( 'oc_stats_product', __( 'Sales', 'oc-theme' ), array( $this, 'render' ), 'product', 'side', 'default' );
	}

	/**
	 * Orders, units and money for one product (all its variations
	 * included), for paid orders and orders in hand; refunds excluded.
	 *
	 * @param int    $product_id Parent product.
	 * @param string $since      Y-m-d, or '' for all time.
	 * @return array{orders:int,qty:int,gross:float,last_id:int,last_at:string}
	 */
	public static function totals( int $product_id, string $since = '' ): array {
		global $wpdb;

		$statuses = array_map( static fn( string $s ): string => 'wc-' . $s, Query::SALE_STATUSES );
		$in       = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
		$where    = "l.product_id = %d AND s.parent_id = 0 AND s.status IN ( $in )";
		$args     = array_merge( array( $product_id ), $statuses );

		if ( '' !== $since ) {
			$where .= ' AND l.date_created >= %s';
			$args[] = $since . ' 00:00:00';
		}

		$sql = "SELECT COUNT( DISTINCT l.order_id ) AS orders, COALESCE( SUM( l.product_qty ), 0 ) AS qty, COALESCE( SUM( l.product_net_revenue + l.tax_amount ), 0 ) AS gross
			FROM {$wpdb->prefix}wc_order_product_lookup l
			INNER JOIN {$wpdb->prefix}wc_order_stats s ON s.order_id = l.order_id
			WHERE $where";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( $sql, $args ), ARRAY_A );

		$out = array(
			'orders'  => (int) ( $row['orders'] ?? 0 ),
			'qty'     => (int) ( $row['qty'] ?? 0 ),
			'gross'   => round( (float) ( $row['gross'] ?? 0 ), 2 ),
			'last_id' => 0,
			'last_at' => '',
		);

		if ( $out['orders'] > 0 ) {
			$sql = "SELECT l.order_id, l.date_created
				FROM {$wpdb->prefix}wc_order_product_lookup l
				INNER JOIN {$wpdb->prefix}wc_order_stats s ON s.order_id = l.order_id
				WHERE $where
				ORDER BY l.date_created DESC
				LIMIT 1";

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$last = $wpdb->get_row( $wpdb->prepare( $sql, $args ), ARRAY_A );

			$out['last_id'] = (int) ( $last['order_id'] ?? 0 );
			$out['last_at'] = (string) ( $last['date_created'] ?? '' );
		}

		return $out;
	}

	/**
	 * The box.
	 *
	 * @param \WP_Post $post The product.
	 */
	public function render( \WP_Post $post ): void {
		$all = self::totals( (int) $post->ID );

		if ( 0 === $all['orders'] ) {
			echo '<p class="ocst-p__none">' . esc_html__( 'This product has not sold yet.', 'oc-theme' ) . '</p>';
			$this->foot();
			return;
		}

		$m30   = self::totals( (int) $post->ID, Query::shift( Query::today(), -29 ) );
		$money = static fn( float $n ): string => wp_strip_all_tags( wc_price( $n, array( 'decimals' => (float) (int) $n === $n ? 0 : 2 ) ) );
		$rows  = array(
			array( __( 'Orders', 'oc-theme' ), number_format_i18n( $m30['orders'] ), number_format_i18n( $all['orders'] ) ),
			array( __( 'Units', 'oc-theme' ), number_format_i18n( $m30['qty'] ), number_format_i18n( $all['qty'] ) ),
			array( __( 'Revenue', 'oc-theme' ), $money( $m30['gross'] ), $money( $all['gross'] ) ),
		);
		?>
		<table class="ocst-p">
			<thead>
				<tr><th></th><th><?php esc_html_e( 'Last 30 days', 'oc-theme' ); ?></th><th><?php esc_html_e( 'All time', 'oc-theme' ); ?></th></tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $r ) : ?>
					<tr><th><?php echo esc_html( $r[0] ); ?></th><td><?php echo esc_html( $r[1] ); ?></td><td><?php echo esc_html( $r[2] ); ?></td></tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p class="ocst-p__last">
			<?php esc_html_e( 'Last ordered', 'oc-theme' ); ?>:
			<?php
			$when = mysql2date( (string) get_option( 'date_format' ), $all['last_at'] );
			$edit = $all['last_id'] > 0 ? get_edit_post_link( $all['last_id'] ) : '';
			if ( $edit ) {
				echo '<a href="' . esc_url( $edit ) . '">' . esc_html( $when ) . ' · #' . esc_html( (string) $all['last_id'] ) . '</a>';
			} else {
				echo esc_html( $when );
			}
			?>
		</p>
		<?php
		$this->foot();
	}

	/**
	 * What the numbers mean, and the way to the full picture.
	 */
	private function foot(): void {
		echo '<p class="ocst-p__note">' . esc_html__( 'Paid orders and orders in hand, including VAT; refunds are left out.', 'oc-theme' )
			. ' <a href="' . esc_url( admin_url( 'admin.php?page=' . Admin::PAGE ) ) . '">' . esc_html__( 'All the statistics', 'oc-theme' ) . '</a></p>';
		echo '<style>
			.ocst-p{width:100%;border-collapse:collapse;margin:4px 0 8px}
			.ocst-p th,.ocst-p td{padding:5px 0;text-align:start;font-variant-numeric:tabular-nums}
			.ocst-p thead th{font-size:11px;color:#646970;font-weight:600;border-bottom:1px solid #dcdcde}
			.ocst-p tbody th{font-weight:400;color:#1d2327}
			.ocst-p tbody td{font-weight:600}
			.ocst-p__last{margin:0 0 6px}
			.ocst-p__none{margin:0 0 6px;color:#646970}
			.ocst-p__note{margin:0;color:#646970;font-size:12px}
		</style>';
	}
}
