<?php
/**
 * Orders, narrowed to one branch.
 *
 * A shop with branches is really several shops sharing a catalogue, and the
 * person behind the counter in one of them wants the orders coming to that
 * counter — not everybody's. This is the dropdown above the orders list that
 * does it, and the column that says which branch an order belongs to.
 *
 * WooCommerce keeps orders in one of two places depending on whether the shop
 * has moved to its own order tables, and the two are filtered by different
 * hooks entirely. Both are wired here; only one of them will ever fire.
 *
 * @package OC_Theme
 */

declare( strict_types = 1 );

namespace OC\Theme;

defined( 'ABSPATH' ) || exit;

/**
 * The branch filter on the orders screen.
 */
final class Branch_Orders {

	/**
	 * The query parameter the dropdown submits.
	 */
	const PARAM = 'oc_branch';

	/**
	 * Hook in.
	 */
	public function register(): void {
		if ( ! class_exists( 'WooCommerce' ) || ! class_exists( '\OC\Blocks\Branches' ) ) {
			return;
		}

		// The shop's own order tables.
		add_action( 'woocommerce_order_list_table_restrict_manage_orders', array( $this, 'dropdown' ) );
		add_filter( 'woocommerce_order_list_table_prepare_items_query_args', array( $this, 'narrow_hpos' ) );

		// The older posts table.
		add_action( 'restrict_manage_posts', array( $this, 'dropdown_legacy' ) );
		add_action( 'pre_get_posts', array( $this, 'narrow_legacy' ) );
	}

	/**
	 * Which branch is being asked for, or 0 for all of them.
	 */
	private function asked(): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a filter on a list the user is already allowed to see.
		return absint( $_GET[ self::PARAM ] ?? 0 );
	}

	/**
	 * The dropdown itself. Silent in a shop with no branches — a filter with
	 * nothing to filter by is furniture.
	 */
	public function dropdown(): void {
		// Every branch, not only the ones collection is offered from today:
		// a branch taken off that list keeps the orders it already has, and
		// they still have to be findable.
		$branches = \OC\Blocks\Branches::all();

		if ( ! $branches ) {
			return;
		}

		$now = $this->asked();
		?>
		<select name="<?php echo esc_attr( self::PARAM ); ?>">
			<option value="0"><?php esc_html_e( 'All branches', 'oc-theme' ); ?></option>
			<?php foreach ( $branches as $branch ) : ?>
				<option value="<?php echo absint( $branch['id'] ); ?>" <?php selected( $branch['id'], $now ); ?>>
					<?php echo esc_html( $branch['name'] ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	/**
	 * The same dropdown on the older screen, which offers the hook to every
	 * post type there is.
	 *
	 * @param string $post_type The screen's post type.
	 */
	public function dropdown_legacy( $post_type ): void {
		if ( 'shop_order' !== $post_type ) {
			return;
		}

		$this->dropdown();
	}

	/**
	 * Narrow the shop's own order tables.
	 *
	 * @param array $args Query arguments.
	 * @return array
	 */
	public function narrow_hpos( $args ) {
		$branch = $this->asked();

		if ( ! $branch ) {
			return $args;
		}

		$args['meta_query'] = array_merge( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- the point of the filter.
			(array) ( $args['meta_query'] ?? array() ),
			array(
				array(
					'key'   => '_oc_branch',
					'value' => $branch,
				),
			)
		);

		return $args;
	}

	/**
	 * Narrow the older posts table.
	 *
	 * @param \WP_Query $query The query about to run.
	 */
	public function narrow_legacy( $query ): void {
		if ( ! is_admin() || ! $query instanceof \WP_Query || ! $query->is_main_query() ) {
			return;
		}

		if ( 'shop_order' !== (string) $query->get( 'post_type' ) ) {
			return;
		}

		$branch = $this->asked();

		if ( ! $branch ) {
			return;
		}

		$query->set( 'meta_key', '_oc_branch' ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- the point of the filter.
		$query->set( 'meta_value', $branch ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- the point of the filter.
	}
}
