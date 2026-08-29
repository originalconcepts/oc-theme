<?php
/**
 * Printing orders from the orders list.
 *
 * The shop ticks the orders it wants, chooses Print, and gets one clean A4
 * sheet per order — the same shape as the customer's own order page, minus
 * the logo and everything else that costs ink without helping the person
 * packing the box.
 *
 * @package OC_Theme
 */

declare( strict_types=1 );

namespace OC\Theme;

/**
 * The order print sheet.
 */
class Order_Print {

	/**
	 * The hidden screen that draws the sheets.
	 */
	private const PAGE = 'oc-order-print';

	/**
	 * Hook in.
	 */
	public function register(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		// The orders list lives in one of two places depending on whether the
		// shop keeps orders in their own tables.
		foreach ( array( 'woocommerce_page_wc-orders', 'edit-shop_order' ) as $screen ) {
			add_filter( 'bulk_actions-' . $screen, array( $this, 'bulk_action' ) );
			add_filter( 'handle_bulk_actions-' . $screen, array( $this, 'handle_bulk' ), 10, 3 );
		}

		add_action( 'admin_menu', array( $this, 'screen' ) );

		// The sheet is a document of its own, so it has to be written before
		// WordPress starts drawing the admin around it. Left to the ordinary
		// page callback the whole admin menu comes along, and prints as a
		// column down the side of every sheet.
		add_action( 'admin_init', array( $this, 'maybe_render' ), 1 );

		// A single order can be printed from its own edit screen too.
		add_action( 'woocommerce_order_actions', array( $this, 'order_action' ) );
		add_action( 'woocommerce_order_action_oc_print_order', array( $this, 'order_action_run' ) );
	}

	/**
	 * The entry in the bulk-actions menu.
	 *
	 * @param array<string,string> $actions Existing actions.
	 * @return array<string,string>
	 */
	public function bulk_action( $actions ) {
		if ( current_user_can( 'edit_shop_orders' ) ) {
			$actions['oc_print_orders'] = __( 'Print', 'oc-theme' );
		}

		return $actions;
	}

	/**
	 * Turn the ticked rows into a trip to the print sheet.
	 *
	 * @param string    $redirect Where the list would have gone.
	 * @param string    $action   The chosen action.
	 * @param int[]|string[] $ids The ticked order ids.
	 * @return string
	 */
	public function handle_bulk( $redirect, $action, $ids ) {
		if ( 'oc_print_orders' !== $action || ! current_user_can( 'edit_shop_orders' ) ) {
			return $redirect;
		}

		$ids = array_filter( array_map( 'absint', (array) $ids ) );

		if ( ! $ids ) {
			return $redirect;
		}

		return self::url( $ids );
	}

	/**
	 * The print screen's address.
	 *
	 * @param int[] $ids Order ids.
	 */
	public static function url( array $ids ): string {
		return add_query_arg(
			array(
				'page' => self::PAGE,
				'ids'  => implode( ',', array_map( 'absint', $ids ) ),
				'_pn'  => wp_create_nonce( 'oc_print_orders' ),
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * A print entry on a single order's own screen.
	 *
	 * @param array<string,string> $actions Existing actions.
	 * @return array<string,string>
	 */
	public function order_action( $actions ) {
		$actions['oc_print_order'] = __( 'Print this order', 'oc-theme' );

		return $actions;
	}

	/**
	 * Off to the sheet for this one order.
	 *
	 * @param \WC_Order $order The order.
	 */
	public function order_action_run( $order ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		wp_safe_redirect( self::url( array( $order->get_id() ) ) );
		exit;
	}

	/**
	 * Register the screen without giving it a menu entry of its own.
	 */
	public function screen(): void {
		add_submenu_page(
			'',
			__( 'Print orders', 'oc-theme' ),
			__( 'Print orders', 'oc-theme' ),
			'edit_shop_orders',
			self::PAGE,
			array( $this, 'render' )
		);
	}

	/**
	 * Catch our own screen early and answer it in full.
	 *
	 * admin_init runs before a single byte of the admin page is sent, so
	 * exiting here is what keeps the sheet a bare document.
	 */
	public function maybe_render(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- render() checks the nonce.
		$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';

		if ( self::PAGE !== $page ) {
			return;
		}

		$this->render();
		exit;
	}

	/**
	 * Draw the sheets: one order per page, and the print dialog on arrival.
	 */
	public function render(): void {
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_die( esc_html__( 'You cannot print orders.', 'oc-theme' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified right below.
		$nonce = isset( $_GET['_pn'] ) ? sanitize_text_field( wp_unslash( $_GET['_pn'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, 'oc_print_orders' ) ) {
			wp_die( esc_html__( 'That print link has expired — pick the orders again.', 'oc-theme' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified above.
		$raw = isset( $_GET['ids'] ) ? (string) wp_unslash( $_GET['ids'] ) : '';
		$ids = array_filter( array_map( 'absint', explode( ',', $raw ) ) );

		$orders = array();

		foreach ( $ids as $id ) {
			$order = wc_get_order( $id );

			if ( $order instanceof \WC_Order ) {
				$orders[] = $order;
			}
		}

		$this->document( $orders );
		exit;
	}

	/**
	 * The whole print document, standing on its own.
	 *
	 * @param \WC_Order[] $orders Orders to draw.
	 */
	private function document( array $orders ): void {
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );

		?><!doctype html>
		<html <?php language_attributes(); ?>>
		<head>
			<meta charset="<?php bloginfo( 'charset' ); ?>">
			<meta name="viewport" content="width=device-width, initial-scale=1">
			<title><?php esc_html_e( 'Print orders', 'oc-theme' ); ?></title>
			<style><?php echo self::css(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static stylesheet. ?></style>
		</head>
		<body>
			<div class="bar no-print">
				<button type="button" onclick="window.print()"><?php esc_html_e( 'Print', 'oc-theme' ); ?></button>
				<span><?php
					printf(
						/* translators: %d: how many orders. */
						esc_html( _n( '%d order', '%d orders', count( $orders ), 'oc-theme' ) ),
						count( $orders )
					);
				?></span>
			</div>

			<?php if ( ! $orders ) : ?>
				<p class="empty"><?php esc_html_e( 'Nothing to print — no order was found.', 'oc-theme' ); ?></p>
			<?php endif; ?>

			<?php foreach ( $orders as $order ) : ?>
				<?php $this->sheet( $order ); ?>
			<?php endforeach; ?>

			<script>window.addEventListener( 'load', function () { window.print(); } );</script>
		</body>
		</html>
		<?php
	}

	/**
	 * One order, one page.
	 *
	 * @param \WC_Order $order The order.
	 */
	private function sheet( \WC_Order $order ): void {
		$created = $order->get_date_created();
		$name    = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		$email   = (string) $order->get_billing_email();
		$phone   = (string) $order->get_billing_phone();

		$pickup = 'local_pickup' === $this->shipping_method( $order );

		$street = (string) $order->get_billing_address_1();
		$city   = (string) $order->get_billing_city();
		$apt    = (string) $order->get_billing_address_2();
		$floor  = (string) $order->get_meta( '_oc_floor' );
		$entry  = (string) $order->get_meta( '_oc_entry' );

		$extra = array();

		if ( '' !== $apt ) {
			/* translators: %s: apartment. */
			$extra[] = sprintf( __( 'Apt %s', 'oc-theme' ), $apt );
		}
		if ( '' !== $floor ) {
			/* translators: %s: floor. */
			$extra[] = sprintf( __( 'Floor %s', 'oc-theme' ), $floor );
		}
		if ( '' !== $entry ) {
			/* translators: %s: entry code. */
			$extra[] = sprintf( __( 'Entry %s', 'oc-theme' ), $entry );
		}

		$r_name  = trim( (string) $order->get_meta( '_oc_recipient_first' ) . ' ' . (string) $order->get_meta( '_oc_recipient_last' ) );
		$r_phone = (string) $order->get_meta( '_oc_recipient_phone' );
		$note    = (string) $order->get_customer_note();
		?>
		<section class="sheet">
			<header class="head">
				<h1><?php
					/* translators: %s: order number. */
					printf( esc_html__( 'Order %s', 'oc-theme' ), esc_html( $order->get_order_number() ) );
				?></h1>
				<div class="head-meta">
					<?php if ( $created ) : ?>
						<span><?php echo esc_html( $created->date_i18n( 'd/m/Y · H:i' ) ); ?></span>
					<?php endif; ?>
					<span class="status"><?php echo esc_html( wc_get_order_status_name( $order->get_status() ) ); ?></span>
				</div>
			</header>

			<div class="cards">
				<div class="card">
					<h2><?php esc_html_e( 'Orderer details', 'oc-theme' ); ?></h2>
					<p class="strong"><?php echo esc_html( $name ); ?></p>
					<?php if ( '' !== $phone ) : ?>
						<p dir="ltr"><?php echo esc_html( $phone ); ?></p>
					<?php endif; ?>
					<?php if ( '' !== $email ) : ?>
						<p dir="ltr"><?php echo esc_html( $email ); ?></p>
					<?php endif; ?>
				</div>

				<div class="card">
					<?php if ( $pickup ) : ?>
						<h2><?php esc_html_e( 'Store pickup', 'oc-theme' ); ?></h2>
						<p class="strong"><?php echo esc_html( get_bloginfo( 'name' ) ); ?></p>
					<?php else : ?>
						<h2><?php esc_html_e( 'Delivery address', 'oc-theme' ); ?></h2>
						<p class="strong"><?php echo esc_html( trim( $street . ( '' !== $city ? ', ' . $city : '' ) ) ); ?></p>
						<?php if ( $extra ) : ?>
							<p><?php echo esc_html( implode( ' · ', $extra ) ); ?></p>
						<?php endif; ?>
						<?php if ( '' !== $r_name ) : ?>
							<p><?php esc_html_e( 'Recipient:', 'oc-theme' ); ?> <?php echo esc_html( $r_name ); ?><?php echo '' !== $r_phone ? ' · ' . esc_html( $r_phone ) : ''; ?></p>
						<?php endif; ?>
					<?php endif; ?>
				</div>
			</div>

			<?php if ( '' !== $note ) : ?>
				<p class="note"><span><?php esc_html_e( 'Note from the customer:', 'oc-theme' ); ?></span> <?php echo esc_html( $note ); ?></p>
			<?php endif; ?>

			<table class="items">
				<thead>
					<tr>
						<th class="qty"><?php esc_html_e( 'Qty', 'oc-theme' ); ?></th>
						<th><?php esc_html_e( 'Item', 'oc-theme' ); ?></th>
						<th class="sum"><?php esc_html_e( 'Total', 'oc-theme' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $order->get_items() as $item ) : ?>
						<?php
						$product = is_callable( array( $item, 'get_product' ) ) ? $item->get_product() : null;
						$sku     = $product ? (string) $product->get_sku() : '';
						$meta    = wc_display_item_meta( $item, array( 'echo' => false ) );
						?>
						<tr>
							<td class="qty"><?php echo esc_html( (string) (int) $item->get_quantity() ); ?></td>
							<td>
								<span class="iname"><?php echo esc_html( $item->get_name() ); ?></span>
								<?php if ( '' !== $sku ) : ?>
									<span class="sku"><?php esc_html_e( 'SKU:', 'oc-theme' ); ?> <?php echo esc_html( $sku ); ?></span>
								<?php endif; ?>
								<?php if ( $meta ) : ?>
									<span class="imeta"><?php echo wp_kses_post( $meta ); ?></span>
								<?php endif; ?>
							</td>
							<td class="sum"><?php echo wp_kses_post( $order->get_formatted_line_subtotal( $item ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<table class="totals">
				<?php foreach ( $order->get_order_item_totals() as $key => $row ) : ?>
					<tr class="<?php echo 'order_total' === $key ? 'grand' : ''; ?>">
						<th><?php echo esc_html( wp_strip_all_tags( (string) $row['label'] ) ); ?></th>
						<td><?php echo wp_kses_post( $row['value'] ); ?></td>
					</tr>
				<?php endforeach; ?>
			</table>
		</section>
		<?php
	}

	/**
	 * The order's shipping method id, for telling a pickup from a delivery.
	 *
	 * @param \WC_Order $order The order.
	 */
	private function shipping_method( \WC_Order $order ): string {
		foreach ( $order->get_shipping_methods() as $method ) {
			return (string) $method->get_method_id();
		}

		return '';
	}

	/**
	 * The sheet's own stylesheet — nothing of the shop's own design reaches
	 * this page, so it says everything it needs itself.
	 */
	private static function css(): string {
		return '
		* { box-sizing: border-box; }
		body {
			margin: 0;
			background: #f1f1f1;
			color: #111;
			font-family: -apple-system, "Segoe UI", Roboto, Arial, sans-serif;
			font-size: 13px;
			line-height: 1.5;
		}
		.bar {
			position: sticky;
			inset-block-start: 0;
			display: flex;
			align-items: center;
			gap: 14px;
			padding: 12px 18px;
			background: #fff;
			border-block-end: 1px solid #ddd;
		}
		.bar button {
			padding: 8px 22px;
			border: 0;
			border-radius: 6px;
			background: #111;
			color: #fff;
			font: inherit;
			font-weight: 600;
			cursor: pointer;
		}
		.bar span { color: #666; }
		.empty { padding: 30px; text-align: center; color: #666; }

		.sheet {
			inline-size: 210mm;
			min-block-size: 297mm;
			margin: 18px auto;
			padding: 16mm 14mm;
			background: #fff;
			box-shadow: 0 1px 6px rgb(0 0 0 / 12%);
		}

		.head {
			display: flex;
			align-items: baseline;
			justify-content: space-between;
			gap: 16px;
			padding-block-end: 10px;
			border-block-end: 2px solid #111;
		}
		.head h1 { margin: 0; font-size: 20px; }
		.head-meta { display: flex; align-items: center; gap: 10px; color: #555; }
		.status {
			padding: 3px 10px;
			border: 1px solid #111;
			border-radius: 999px;
			font-weight: 600;
			color: #111;
		}

		.cards { display: flex; gap: 12px; margin-block-start: 14px; }
		.card {
			flex: 1;
			padding: 10px 12px;
			border: 1px solid #ddd;
			border-radius: 6px;
		}
		.card h2 {
			margin: 0 0 6px;
			font-size: 11px;
			font-weight: 700;
			letter-spacing: .06em;
			text-transform: uppercase;
			color: #777;
		}
		.card p { margin: 0 0 2px; }
		.card .strong { font-weight: 600; }

		.note {
			margin: 12px 0 0;
			padding: 8px 12px;
			border: 1px dashed #bbb;
			border-radius: 6px;
		}
		.note span { font-weight: 700; }

		.items { inline-size: 100%; border-collapse: collapse; margin-block-start: 16px; }
		.items th, .items td { padding: 8px 6px; border-block-end: 1px solid #e3e3e3; text-align: start; vertical-align: top; }
		.items thead th {
			border-block-end: 1px solid #111;
			font-size: 11px;
			letter-spacing: .06em;
			text-transform: uppercase;
			color: #777;
		}
		.items .qty { inline-size: 46px; text-align: center; font-weight: 700; }
		.items .sum { inline-size: 92px; text-align: end; white-space: nowrap; }
		.iname { display: block; font-weight: 600; }
		.sku, .imeta { display: block; font-size: 11px; color: #666; }
		.imeta p { margin: 0; }

		.totals { inline-size: 58%; margin: 12px 0 0 auto; border-collapse: collapse; }
		.totals th, .totals td { padding: 5px 6px; text-align: start; font-weight: 400; }
		.totals td { text-align: end; white-space: nowrap; }
		.totals .grand th, .totals .grand td {
			border-block-start: 2px solid #111;
			font-size: 15px;
			font-weight: 700;
		}

		@media print {
			body { background: #fff; }
			.no-print { display: none !important; }
			.sheet {
				inline-size: auto;
				min-block-size: 0;
				margin: 0;
				padding: 0;
				box-shadow: none;
				break-after: page;
			}
			.sheet:last-child { break-after: auto; }
		}

		@page { size: A4; margin: 14mm; }
		';
	}
}
