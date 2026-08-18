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

		$rows = $this->rows();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Back-in-stock waitlist', 'oc-theme' ); ?></h1>
			<p>
				<?php
				/* translators: %d: number of signups. */
				echo esc_html( sprintf( __( '%d signups are waiting for products to return.', 'oc-theme' ), count( $rows ) ) );
				?>
			</p>

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
