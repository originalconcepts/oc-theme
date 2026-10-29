<?php
/**
 * Which orders have been printed.
 *
 * Every order sent to the print sheet is marked: when, by whom and how many
 * times. The orders list shows a tick in a column of its own and can be
 * narrowed to the orders not printed yet, the order's own screen says so
 * beside its status, and every print leaves a note in the order's history.
 *
 * A mark and not a status on purpose: an order has one status at a time,
 * and the statistics, the customer emails and every connected service read
 * it. A printed order is still waiting to be sent, which is what
 * "processing" already says.
 *
 * The site cannot know whether paper came out of the printer — the browser
 * does not say whether its print dialog was cancelled — so the mark means
 * "sent to print", and it can be taken off again.
 *
 * WooCommerce keeps orders in one of two places depending on whether the
 * shop has moved to its own order tables; both screens are wired here.
 *
 * @package OC_Theme
 */

declare( strict_types = 1 );

namespace OC\Theme;

defined( 'ABSPATH' ) || exit;

/**
 * The printed mark on orders.
 */
final class Order_Printed {

	/**
	 * When the order was last sent to print (unix time).
	 */
	const AT = '_oc_printed';

	/**
	 * Who sent it (user id).
	 */
	const BY = '_oc_printed_by';

	/**
	 * How many times it has been sent.
	 */
	const TIMES = '_oc_printed_n';

	/**
	 * The column, and the query parameter of the filter above the list.
	 */
	const COLUMN = 'oc_printed';

	/**
	 * The bulk action and the single-order action that take the mark off.
	 */
	const UNDO        = 'oc_unprint_orders';
	const UNDO_SINGLE = 'oc_unprint_order';

	/**
	 * Hook in.
	 */
	public function register(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		// The shop's own order tables.
		add_filter( 'manage_woocommerce_page_wc-orders_columns', array( $this, 'columns' ) );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( $this, 'cell' ), 10, 2 );
		add_action( 'woocommerce_order_list_table_restrict_manage_orders', array( $this, 'dropdown' ) );
		add_filter( 'woocommerce_order_list_table_prepare_items_query_args', array( $this, 'narrow_hpos' ) );

		// The older posts table.
		add_filter( 'manage_edit-shop_order_columns', array( $this, 'columns' ) );
		add_action( 'manage_shop_order_posts_custom_column', array( $this, 'cell' ), 10, 2 );
		add_action( 'restrict_manage_posts', array( $this, 'dropdown_legacy' ) );
		add_action( 'pre_get_posts', array( $this, 'narrow_legacy' ) );

		foreach ( array( 'woocommerce_page_wc-orders', 'edit-shop_order' ) as $screen ) {
			add_filter( 'bulk_actions-' . $screen, array( $this, 'bulk_action' ) );
			add_filter( 'handle_bulk_actions-' . $screen, array( $this, 'handle_bulk' ), 10, 3 );
		}

		add_filter( 'woocommerce_order_actions', array( $this, 'order_action' ), 10, 2 );
		add_action( 'woocommerce_order_action_' . self::UNDO_SINGLE, array( $this, 'order_action_run' ) );

		add_action( 'woocommerce_admin_order_data_after_order_details', array( $this, 'order_line' ) );
		add_action( 'admin_notices', array( $this, 'notice' ) );
		add_action( 'admin_head', array( $this, 'style' ) );
	}

	/* ------------------------------------------------------------ the mark */

	/**
	 * Mark an order as sent to print, and say so in its history.
	 *
	 * @param \WC_Order $order The order.
	 */
	public static function mark( \WC_Order $order ): void {
		$times = (int) $order->get_meta( self::TIMES ) + 1;

		$order->update_meta_data( self::AT, time() );
		$order->update_meta_data( self::BY, get_current_user_id() );
		$order->update_meta_data( self::TIMES, $times );

		$note = __( 'Printed.', 'oc-theme' );

		if ( $times > 1 ) {
			/* translators: %d: how many times the order has been printed. */
			$note = sprintf( __( 'Printed again (%d times in all).', 'oc-theme' ), $times );
		}

		$order->add_order_note( $note, 0, true );

		$order->save();
	}

	/**
	 * Take the mark off.
	 *
	 * @param \WC_Order $order The order.
	 */
	public static function unmark( \WC_Order $order ): bool {
		if ( ! self::printed( $order ) ) {
			return false;
		}

		$order->delete_meta_data( self::AT );
		$order->delete_meta_data( self::BY );
		$order->delete_meta_data( self::TIMES );
		$order->add_order_note( __( 'Marked as not printed.', 'oc-theme' ), 0, true );
		$order->save();

		return true;
	}

	/**
	 * Whether the order has been sent to print.
	 *
	 * @param \WC_Order $order The order.
	 */
	public static function printed( \WC_Order $order ): bool {
		return (int) $order->get_meta( self::AT ) > 0;
	}

	/**
	 * "Printed on 29/10/2026 14:32 by George", plus how many times when
	 * more than once.
	 *
	 * @param \WC_Order $order The order.
	 */
	private static function said( \WC_Order $order ): string {
		$at    = (int) $order->get_meta( self::AT );
		$times = (int) $order->get_meta( self::TIMES );
		$user  = get_userdata( (int) $order->get_meta( self::BY ) );
		$when  = (string) wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $at );

		$text = $user instanceof \WP_User
			/* translators: 1: date and time, 2: who printed it. */
			? sprintf( __( 'Printed on %1$s by %2$s', 'oc-theme' ), $when, $user->display_name )
			/* translators: %s: date and time. */
			: sprintf( __( 'Printed on %s', 'oc-theme' ), $when );

		if ( $times > 1 ) {
			/* translators: %d: how many times the order has been printed. */
			$text .= ' · ' . sprintf( _n( '%d time', '%d times', $times, 'oc-theme' ), $times );
		}

		return $text;
	}

	/**
	 * The order behind a list row: the order tables hand over the order,
	 * the posts table its id.
	 *
	 * @param mixed $row Order or post id.
	 */
	private static function order_of( $row ): ?\WC_Order {
		$order = $row instanceof \WC_Order ? $row : wc_get_order( absint( $row ) );

		return $order instanceof \WC_Order ? $order : null;
	}

	/* ------------------------------------------------------------- the list */

	/**
	 * The column, right after the status.
	 *
	 * @param array<string,string> $columns Columns.
	 * @return array<string,string>
	 */
	public function columns( $columns ) {
		$out = array();

		foreach ( (array) $columns as $key => $label ) {
			$out[ $key ] = $label;

			if ( 'order_status' === $key ) {
				$out[ self::COLUMN ] = __( 'Printed', 'oc-theme' );
			}
		}

		if ( ! isset( $out[ self::COLUMN ] ) ) {
			$out[ self::COLUMN ] = __( 'Printed', 'oc-theme' );
		}

		return $out;
	}

	/**
	 * One cell: a tick that says when and by whom, or nothing.
	 *
	 * @param string $column Column key.
	 * @param mixed  $row    Order (order tables) or post id (posts table).
	 */
	public function cell( $column, $row ): void {
		if ( self::COLUMN !== $column ) {
			return;
		}

		$order = self::order_of( $row );

		if ( ! $order || ! self::printed( $order ) ) {
			return;
		}

		$said = self::said( $order );

		printf(
			'<span class="oc-printed dashicons dashicons-yes-alt" title="%1$s"></span><span class="screen-reader-text">%2$s</span>',
			esc_attr( $said ),
			esc_html( $said )
		);
	}

	/**
	 * Which orders are being asked for: 'no', 'yes', or '' for all.
	 */
	private function asked(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a filter on a list the user is already allowed to see.
		$v = sanitize_key( wp_unslash( (string) ( $_GET[ self::COLUMN ] ?? '' ) ) );

		return in_array( $v, array( 'no', 'yes' ), true ) ? $v : '';
	}

	/**
	 * The filter above the list.
	 */
	public function dropdown(): void {
		$now = $this->asked();
		?>
		<select name="<?php echo esc_attr( self::COLUMN ); ?>">
			<option value=""><?php esc_html_e( 'Printed and not printed', 'oc-theme' ); ?></option>
			<option value="no" <?php selected( 'no', $now ); ?>><?php esc_html_e( 'Not printed', 'oc-theme' ); ?></option>
			<option value="yes" <?php selected( 'yes', $now ); ?>><?php esc_html_e( 'Printed', 'oc-theme' ); ?></option>
		</select>
		<?php
	}

	/**
	 * The same filter on the older screen, which offers the hook to every
	 * post type there is.
	 *
	 * @param string $post_type The screen's post type.
	 */
	public function dropdown_legacy( $post_type ): void {
		if ( 'shop_order' === $post_type ) {
			$this->dropdown();
		}
	}

	/**
	 * The meta clause for the asked filter, or null for all orders.
	 *
	 * @return array<string,string>|null
	 */
	private function clause(): ?array {
		$asked = $this->asked();

		if ( '' === $asked ) {
			return null;
		}

		return array(
			'key'     => self::AT,
			'compare' => 'yes' === $asked ? 'EXISTS' : 'NOT EXISTS',
		);
	}

	/**
	 * Narrow the shop's own order tables.
	 *
	 * @param array $args Query arguments.
	 * @return array
	 */
	public function narrow_hpos( $args ) {
		$clause = $this->clause();

		if ( null === $clause ) {
			return $args;
		}

		$args['meta_query']   = (array) ( $args['meta_query'] ?? array() ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- the point of the filter.
		$args['meta_query'][] = $clause;

		return $args;
	}

	/**
	 * Narrow the older posts table.
	 *
	 * @param \WP_Query $query The query about to run.
	 */
	public function narrow_legacy( $query ): void {
		if ( ! is_admin() || ! $query instanceof \WP_Query || ! $query->is_main_query() || 'shop_order' !== (string) $query->get( 'post_type' ) ) {
			return;
		}

		$clause = $this->clause();

		if ( null === $clause ) {
			return;
		}

		$meta   = (array) $query->get( 'meta_query' );
		$meta[] = $clause;

		$query->set( 'meta_query', $meta ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- the point of the filter.
	}

	/* ------------------------------------------------------------- the undo */

	/**
	 * "Mark as not printed" in the bulk-actions menu.
	 *
	 * @param array<string,string> $actions Existing actions.
	 * @return array<string,string>
	 */
	public function bulk_action( $actions ) {
		if ( current_user_can( 'edit_shop_orders' ) ) {
			$actions[ self::UNDO ] = __( 'Mark as not printed', 'oc-theme' );
		}

		return $actions;
	}

	/**
	 * Take the mark off the ticked orders.
	 *
	 * @param string         $redirect Where the list goes next.
	 * @param string         $action   The chosen action.
	 * @param int[]|string[] $ids      The ticked order ids.
	 * @return string
	 */
	public function handle_bulk( $redirect, $action, $ids ) {
		if ( self::UNDO !== $action || ! current_user_can( 'edit_shop_orders' ) ) {
			return $redirect;
		}

		$done = 0;

		foreach ( array_filter( array_map( 'absint', (array) $ids ) ) as $id ) {
			$order = wc_get_order( $id );

			if ( $order instanceof \WC_Order && self::unmark( $order ) ) {
				++$done;
			}
		}

		return add_query_arg( 'oc_unprinted', $done, $redirect );
	}

	/**
	 * "Mark as not printed" on a printed order's own screen.
	 *
	 * @param array<string,string> $actions Existing actions.
	 * @param mixed                $order   The order (newer WooCommerce).
	 * @return array<string,string>
	 */
	public function order_action( $actions, $order = null ) {
		if ( ! $order instanceof \WC_Order ) {
			$order = $GLOBALS['theorder'] ?? null;
		}

		if ( $order instanceof \WC_Order && self::printed( $order ) ) {
			$actions[ self::UNDO_SINGLE ] = __( 'Mark as not printed', 'oc-theme' );
		}

		return $actions;
	}

	/**
	 * Take the mark off this one order.
	 *
	 * @param \WC_Order $order The order.
	 */
	public function order_action_run( $order ): void {
		if ( $order instanceof \WC_Order ) {
			self::unmark( $order );
		}
	}

	/**
	 * How many orders the bulk undo took the mark off.
	 */
	public function notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a count to show, nothing is changed.
		if ( ! isset( $_GET['oc_unprinted'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a count to show, nothing is changed.
		$n = absint( $_GET['oc_unprinted'] );

		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			/* translators: %d: how many orders. */
			esc_html( sprintf( _n( '%d order marked as not printed.', '%d orders marked as not printed.', $n, 'oc-theme' ), $n ) )
		);
	}

	/* -------------------------------------------------------- the order page */

	/**
	 * A line under the status on the order's own screen.
	 *
	 * @param \WC_Order $order The order.
	 */
	public function order_line( $order ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		if ( self::printed( $order ) ) {
			printf(
				'<p class="form-field form-field-wide oc-printed-line is-printed"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span> %s</p>',
				esc_html( self::said( $order ) )
			);
			return;
		}

		printf(
			'<p class="form-field form-field-wide oc-printed-line">%s</p>',
			esc_html__( 'Not printed yet', 'oc-theme' )
		);
	}

	/**
	 * A narrow column and a green tick, on the order screens only.
	 */
	public function style(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || ! in_array( $screen->id, array( 'woocommerce_page_wc-orders', 'edit-shop_order', 'shop_order' ), true ) ) {
			return;
		}

		echo '<style>'
			. '.column-oc_printed{width:4.5em;text-align:center!important}'
			. '.oc-printed{color:#00a32a;cursor:help}'
			. '.oc-printed-line{color:#50575e;margin-top:12px!important}'
			. '.oc-printed-line.is-printed{color:#00a32a;font-weight:600}'
			. '.oc-printed-line .dashicons{vertical-align:text-bottom}'
			. '</style>';
	}
}
