<?php
/**
 * What one product says about itself in the feeds.
 *
 * The feed settings speak for the whole shop, and a shop is never quite
 * uniform: one product takes three weeks to arrive, one carries the
 * importer's warranty rather than the maker's, and one has no business
 * being advertised at all. This is where a product overrules the feed.
 *
 * @package OC_Theme
 */

namespace OC\Theme\Feeds;

defined( 'ABSPATH' ) || exit;

/**
 * The product panel.
 */
final class Product {

	/**
	 * Where a product's own answers live.
	 */
	public const META = array(
		'delivery' => '_oc_feed_delivery',
		'warranty' => '_oc_feed_warranty',
		'shipcost' => '_oc_feed_shipcost',
	);

	/**
	 * Hooks.
	 */
	public function register(): void {
		add_action( 'add_meta_boxes', array( $this, 'box' ) );
		add_action( 'woocommerce_process_product_meta', array( $this, 'save' ) );
	}

	/**
	 * The name of the switch that keeps a product out of one network.
	 *
	 * One key per network rather than a list in one key, so that leaving a
	 * product out is a single indexed lookup at build time instead of a
	 * scan of every product in the shop.
	 *
	 * @param string $target Network.
	 */
	public static function hide_key( string $target ): string {
		return '_oc_feed_hide_' . $target;
	}

	/**
	 * Put the panel on the product screen.
	 */
	public function box(): void {
		add_meta_box(
			'oc-feeds',
			__( 'Product feeds', 'oc-theme' ),
			array( $this, 'draw' ),
			'product',
			'side',
			'low'
		);
	}

	/**
	 * Draw it.
	 *
	 * @param \WP_Post $post Product.
	 */
	public function draw( \WP_Post $post ): void {
		$networks = array(
			'meta'   => __( 'Meta', 'oc-theme' ),
			'google' => __( 'Google', 'oc-theme' ),
			'zap'    => __( 'Zap', 'oc-theme' ),
		);

		wp_nonce_field( 'oc_feeds_product', 'oc_feeds_product_nonce' );
		?>
		<p style="margin-top:0"><strong><?php esc_html_e( 'Advertise this product on', 'oc-theme' ); ?></strong></p>
		<?php foreach ( $networks as $target => $label ) : ?>
			<?php $hidden = '1' === (string) get_post_meta( $post->ID, self::hide_key( $target ), true ); ?>
			<p style="margin:.35em 0">
				<label>
					<input type="checkbox" name="oc_feed_show[]" value="<?php echo esc_attr( $target ); ?>" <?php checked( ! $hidden ); ?>>
					<?php echo esc_html( $label ); ?>
				</label>
			</p>
		<?php endforeach; ?>

		<p style="margin:1em 0 .35em"><strong><?php esc_html_e( 'Zap, for this product only', 'oc-theme' ); ?></strong>
			<br><span class="description"><?php esc_html_e( 'Leave empty to use the feed setting.', 'oc-theme' ); ?></span>
		</p>

		<p style="margin:.35em 0">
			<label for="oc-feed-delivery"><?php esc_html_e( 'Delivery time', 'oc-theme' ); ?></label>
			<input type="text" class="widefat" id="oc-feed-delivery" name="oc_feed_delivery"
				value="<?php echo esc_attr( (string) get_post_meta( $post->ID, self::META['delivery'], true ) ); ?>">
		</p>

		<p style="margin:.35em 0">
			<label for="oc-feed-shipcost"><?php esc_html_e( 'Shipping price', 'oc-theme' ); ?></label>
			<input type="text" class="widefat" id="oc-feed-shipcost" name="oc_feed_shipcost" dir="ltr"
				value="<?php echo esc_attr( (string) get_post_meta( $post->ID, self::META['shipcost'], true ) ); ?>">
		</p>

		<p style="margin:.35em 0">
			<label for="oc-feed-warranty"><?php esc_html_e( 'Who gives the warranty', 'oc-theme' ); ?></label>
			<input type="text" class="widefat" id="oc-feed-warranty" name="oc_feed_warranty"
				value="<?php echo esc_attr( (string) get_post_meta( $post->ID, self::META['warranty'], true ) ); ?>">
		</p>
		<?php
	}

	/**
	 * Keep what was said.
	 *
	 * @param int $id Product.
	 */
	public function save( int $id ): void {
		if ( ! isset( $_POST['oc_feeds_product_nonce'] ) ) {
			return;
		}

		$nonce = sanitize_text_field( wp_unslash( (string) $_POST['oc_feeds_product_nonce'] ) );

		if ( ! wp_verify_nonce( $nonce, 'oc_feeds_product' ) || ! current_user_can( 'edit_product', $id ) ) {
			return;
		}

		$shown = isset( $_POST['oc_feed_show'] )
			? array_map( 'sanitize_key', (array) wp_unslash( $_POST['oc_feed_show'] ) )
			: array();

		foreach ( array( 'meta', 'google', 'zap' ) as $target ) {
			$key = self::hide_key( $target );

			// Only the exclusions are stored. A shop of thousands should not
			// carry a row of meta for every product that is simply normal.
			if ( in_array( $target, $shown, true ) ) {
				delete_post_meta( $id, $key );
			} else {
				update_post_meta( $id, $key, '1' );
			}
		}

		foreach ( self::META as $field => $key ) {
			$value = isset( $_POST[ 'oc_feed_' . $field ] )
				? sanitize_text_field( wp_unslash( (string) $_POST[ 'oc_feed_' . $field ] ) )
				: '';

			if ( '' === $value ) {
				delete_post_meta( $id, $key );
			} else {
				update_post_meta( $id, $key, $value );
			}
		}
	}
}
