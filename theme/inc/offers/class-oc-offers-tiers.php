<?php
/**
 * Offers: quantity tiers — "four for less than four times the price".
 *
 * A product carries its own tiers, or takes the tiers of the first rule
 * that covers it. On the product page the tiers are cards beside the
 * quantity box: pick one and the quantity follows. In the cart the unit
 * price follows the quantity, whatever put it there — type 4 yourself and
 * you pay the 4-tier price — so the page never promises what the cart
 * would not keep.
 *
 * @package OC_Theme
 */

declare( strict_types = 1 );

namespace OC\Theme\Offers;

use OC\Theme\Cart;

defined( 'ABSPATH' ) || exit;

/**
 * Tiers: resolution, the product-page cards and the cart price.
 */
final class Tiers {

	/**
	 * Product meta: ['own' => 0|1, 'tiers' => [...], 'default' => qty].
	 */
	const META = '_oc_tiers';

	/**
	 * Resolved tiers, once per product per request.
	 *
	 * @var array<int,array|null>
	 */
	private static $memo = array();

	/**
	 * Hook in.
	 */
	public function register(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		add_action( 'woocommerce_before_add_to_cart_button', array( $this, 'render' ), 5 );
		add_action( 'woocommerce_before_calculate_totals', array( $this, 'price_lines' ), 20 );
	}

	/**
	 * What a product's own meta says, clean.
	 *
	 * @param int $product_id Product id.
	 * @return array{own:int,tiers:array<int,array{qty:int,pct:int,label:string}>,default:int}
	 */
	public static function own( int $product_id ): array {
		$meta  = get_post_meta( $product_id, self::META, true );
		$meta  = is_array( $meta ) ? $meta : array();
		$tiers = Rules::clean_tiers( isset( $meta['tiers'] ) && is_array( $meta['tiers'] ) ? $meta['tiers'] : array() );

		return array(
			'own'     => empty( $meta['own'] ) ? 0 : 1,
			'tiers'   => $tiers,
			'default' => Rules::clean_default( (int) ( $meta['default'] ?? 0 ), $tiers ),
		);
	}

	/**
	 * The tiers that apply to a product, and where they came from. Null
	 * when none do, or offers are off.
	 *
	 * @param int $product_id Product id.
	 * @return array{tiers:array<int,array{qty:int,pct:int,label:string}>,default:int,src:string}|null
	 */
	public static function for_product( int $product_id ): ?array {
		if ( array_key_exists( $product_id, self::$memo ) ) {
			return self::$memo[ $product_id ];
		}

		$out = null;

		if ( Offers::on() ) {
			$own = self::own( $product_id );

			if ( $own['own'] && $own['tiers'] ) {
				$out = array(
					'tiers'   => $own['tiers'],
					'default' => $own['default'],
					'src'     => 'manual',
				);
			} elseif ( ! $own['own'] ) {
				$s   = Offers::settings();
				$uid = Rules::pick_tiers( $s['rules'], Offers::facts( $product_id ) );

				if ( null !== $uid ) {
					$out = array(
						'tiers'   => $s['rules'][ $uid ]['tiers'],
						'default' => (int) $s['rules'][ $uid ]['default'],
						'src'     => 'r:' . $uid,
					);
				}
			}
		}

		self::$memo[ $product_id ] = $out;

		return $out;
	}

	/**
	 * Forget what was resolved — after a save in the same request.
	 */
	public static function forget(): void {
		self::$memo = array();
	}

	/**
	 * Whether a product can carry tiers at all: one price, buyable.
	 *
	 * @param \WC_Product $product The product.
	 */
	public static function fits( \WC_Product $product ): bool {
		return $product->is_type( 'simple' ) && $product->is_purchasable() && $product->is_in_stock() && (float) $product->get_price() > 0;
	}

	/* -------------------------------------------------------------- front */

	/**
	 * The tier cards, inside the product form above its button.
	 */
	public function render(): void {
		global $product;

		if ( ! $product instanceof \WC_Product || ! self::fits( $product ) ) {
			return;
		}

		$set = self::for_product( $product->get_id() );

		if ( ! $set ) {
			return;
		}

		$price    = (float) wc_get_price_to_display( $product );
		$decimals = wc_get_price_decimals();
		$default  = $set['default'];
		$labels   = array(
			'popular' => __( 'Most popular', 'oc-theme' ),
			'best'    => __( 'Best value', 'oc-theme' ),
		);

		$rows   = array();
		$rows[] = array(
			'qty'   => 1,
			'unit'  => $price,
			'total' => $price,
			'save'  => 0.0,
			'label' => '',
		);

		foreach ( $set['tiers'] as $tier ) {
			$unit   = Rules::unit_at( $price, $tier['pct'], $decimals );
			$rows[] = array(
				'qty'   => $tier['qty'],
				'unit'  => $unit,
				'total' => round( $unit * $tier['qty'], $decimals ),
				'save'  => round( ( $price - $unit ) * $tier['qty'], $decimals ),
				'label' => $tier['label'],
			);
		}
		?>
		<fieldset class="oc-tiers" data-oc-tiers data-default="<?php echo absint( $default ); ?>">
			<legend class="oc-tiers__title"><?php esc_html_e( 'How many? More units, lower price', 'oc-theme' ); ?></legend>
			<?php foreach ( $rows as $row ) : ?>
				<label class="oc-tiers__opt<?php echo $row['qty'] === $default ? ' is-on' : ''; ?>">
					<input type="radio" name="oc_tier" value="<?php echo absint( $row['qty'] ); ?>" <?php checked( $row['qty'], $default ); ?> />
					<span class="oc-tiers__l">
						<span class="oc-tiers__q">
							<strong><?php echo 1 === $row['qty'] ? esc_html__( 'One unit', 'oc-theme' ) : esc_html( sprintf( /* translators: %d: quantity. */ _n( '%d unit', '%d units', $row['qty'], 'oc-theme' ), $row['qty'] ) ); ?></strong>
							<?php if ( '' !== $row['label'] && isset( $labels[ $row['label'] ] ) ) : ?>
								<em class="oc-tiers__badge oc-tiers__badge--<?php echo esc_attr( $row['label'] ); ?>"><?php echo esc_html( $labels[ $row['label'] ] ); ?></em>
							<?php endif; ?>
						</span>
						<small><?php echo esc_html( sprintf( /* translators: %s: price per unit. */ __( '%s per unit', 'oc-theme' ), Cart::plain_price( wc_price( $row['unit'] ) ) ) ); ?></small>
					</span>
					<span class="oc-tiers__r">
						<strong><?php echo wp_kses_post( wc_price( $row['total'] ) ); ?></strong>
						<?php if ( $row['save'] > 0 ) : ?>
							<small><?php echo esc_html( sprintf( /* translators: %s: amount saved. */ __( 'You save %s', 'oc-theme' ), Cart::plain_price( wc_price( $row['save'] ) ) ) ); ?></small>
						<?php endif; ?>
					</span>
				</label>
			<?php endforeach; ?>
			<p class="oc-tiers__note"><?php esc_html_e( 'Any other quantity works too; the cart prices it by the tier it reaches.', 'oc-theme' ); ?></p>
		</fieldset>
		<?php
	}

	/* --------------------------------------------------------------- cart */

	/**
	 * Price every line by the tier its quantity reaches. Read from a fresh
	 * copy of the product each time, so a second pass never discounts a
	 * price that was already discounted.
	 *
	 * @param \WC_Cart $cart The cart.
	 */
	public function price_lines( $cart ): void {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}

		if ( ! $cart instanceof \WC_Cart || ! Offers::on() ) {
			return;
		}

		foreach ( $cart->get_cart() as $item ) {
			$product = $item['data'] ?? null;

			if ( ! $product instanceof \WC_Product || ! $product->is_type( 'simple' ) ) {
				continue;
			}

			$set = self::for_product( (int) $item['product_id'] );

			if ( ! $set ) {
				continue;
			}

			$tier = Rules::tier_for( $set['tiers'], (int) $item['quantity'] );

			if ( ! $tier || $tier['pct'] <= 0 ) {
				continue;
			}

			$fresh = wc_get_product( (int) $item['product_id'] );

			if ( ! $fresh instanceof \WC_Product ) {
				continue;
			}

			$base = (float) $fresh->get_price();

			if ( $base <= 0 ) {
				continue;
			}

			$product->set_price( (string) Rules::unit_at( $base, $tier['pct'], wc_get_price_decimals() ) );
		}
	}
}
