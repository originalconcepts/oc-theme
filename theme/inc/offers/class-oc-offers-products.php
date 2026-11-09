<?php
/**
 * Offers: the "by product" tab.
 *
 * Every product in one table, and beside each one every kind of offer it
 * carries — what goes with it, its upgrades, what is bought together with
 * it, what is like it, and its quantity tiers — each marked with where it
 * came from: picked by hand, a rule, or automatic. A row opens in place
 * without a round trip; inside it every list is an open field: type a
 * name, pick the product by its picture, and it is saved at once. Ticked
 * rows take a bulk action.
 *
 * @package OC_Theme
 */

declare( strict_types = 1 );

namespace OC\Theme\Offers;

use OC\Theme\Bought_Together;
use OC\Theme\Search;

defined( 'ABSPATH' ) || exit;

/**
 * The per-product screen and its actions.
 */
final class Products_Admin {

	/**
	 * Rows per page.
	 */
	const PER_PAGE = 20;

	/**
	 * The lists a product carries by hand, with their names.
	 */
	const KINDS = array( 'xsell', 'upsell', 'bt' );

	/**
	 * Hook in.
	 */
	public function register(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		add_action( 'admin_post_oc_offers_link', array( $this, 'link' ) );
		add_action( 'wp_ajax_oc_offers_link', array( $this, 'ajax_link' ) );
	}

	/**
	 * This tab's address, with the current filters kept.
	 *
	 * @param array<string,scalar> $args Extra or overriding arguments.
	 */
	public static function url( array $args = array() ): string {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- filters of a read-only listing.
		$keep = array( 'tab' => 'products' );

		foreach ( array( 's', 'cat', 'brand', 'src', 'paged' ) as $key ) {
			$value = isset( $_GET[ $key ] ) ? sanitize_text_field( (string) wp_unslash( $_GET[ $key ] ) ) : '';

			if ( '' !== $value ) {
				$keep[ $key ] = $value;
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		return Admin::url( array_merge( $keep, $args ) );
	}

	/**
	 * A product's hand-written list of one kind, raw.
	 *
	 * @param \WC_Product $product The product.
	 * @param string      $kind    xsell | upsell | bt.
	 * @return int[]
	 */
	public static function own( \WC_Product $product, string $kind ): array {
		if ( 'bt' === $kind ) {
			return Bought_Together::ids( $product->get_id() );
		}

		$ids = 'upsell' === $kind ? $product->get_upsell_ids( 'edit' ) : $product->get_cross_sell_ids( 'edit' );

		return array_values( array_filter( array_map( 'intval', (array) $ids ) ) );
	}

	/**
	 * Who answers for a product in a place, without fetching the products.
	 *
	 * @param \WC_Product $product The product.
	 * @param string      $place   cart | xsell | upsell | similar.
	 * @return array{src:string,label:string}
	 */
	public static function source_of( \WC_Product $product, string $place = 'cart' ): array {
		$s = Offers::settings();

		$own = 'similar' === $place ? array() : self::own( $product, 'upsell' === $place ? 'upsell' : 'xsell' );

		if ( $own ) {
			return array(
				'src'   => 'manual',
				'label' => __( 'Picked by hand', 'oc-theme' ),
			);
		}

		$uid = Rules::pick( $s['rules'], Offers::facts( $product->get_id() ), $place );

		if ( null !== $uid ) {
			return array(
				'src'   => 'rule',
				'label' => (string) $s['rules'][ $uid ]['name'],
			);
		}

		if ( ( 'cart' === $place && $s['auto'] ) || 'similar' === $place ) {
			return array(
				'src'   => 'auto',
				'label' => __( 'Automatic', 'oc-theme' ),
			);
		}

		return array(
			'src'   => '',
			'label' => '',
		);
	}

	/**
	 * The tab.
	 */
	public static function render(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- filters of a read-only listing.
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['s'] ) ) : '';
		$cat    = isset( $_GET['cat'] ) ? absint( $_GET['cat'] ) : 0;
		$brand  = isset( $_GET['brand'] ) ? absint( $_GET['brand'] ) : 0;
		$src    = isset( $_GET['src'] ) ? sanitize_key( (string) wp_unslash( $_GET['src'] ) ) : '';
		$paged  = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$open   = isset( $_GET['open'] ) ? absint( $_GET['open'] ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$brand_tax = Search::brand_taxonomy();

		$args = array(
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => self::PER_PAGE,
			'paged'          => $paged,
			'fields'         => 'ids',
			'orderby'        => 'title',
			'order'          => 'ASC',
			's'              => $search,
		);

		$tax_query = array();

		if ( $cat ) {
			$tax_query[] = array(
				'taxonomy' => 'product_cat',
				'field'    => 'term_id',
				'terms'    => $cat,
			);
		}

		if ( $brand && '' !== $brand_tax ) {
			$tax_query[] = array(
				'taxonomy' => $brand_tax,
				'field'    => 'term_id',
				'terms'    => $brand,
			);
		}

		if ( $tax_query ) {
			$args['tax_query'] = $tax_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- an admin listing, filtered on purpose.
		}

		if ( 'manual' === $src || 'nomanual' === $src ) {
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- an admin listing, filtered on purpose.
			$args['meta_query'] = 'manual' === $src
				? array(
					array(
						'key'     => '_crosssell_ids',
						'value'   => 'a:0:{}',
						'compare' => '!=',
					),
				)
				: array(
					'relation' => 'OR',
					array(
						'key'     => '_crosssell_ids',
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'   => '_crosssell_ids',
						'value' => 'a:0:{}',
					),
				);
		}

		$query = new \WP_Query( $args );
		$ids   = array_map( 'intval', (array) $query->posts );
		$stats = Stats::by_products( $ids, 30 );
		$total = (int) $query->found_posts;
		$pages = (int) $query->max_num_pages;

		$cats   = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => true,
			)
		);
		$brands = '' === $brand_tax ? array() : get_terms(
			array(
				'taxonomy'   => $brand_tax,
				'hide_empty' => true,
			)
		);

		/* translators: %s: number of products. */
		$count_text = sprintf( _n( '%s product', '%s products', $total, 'oc-theme' ), number_format_i18n( $total ) );

		Admin::head( 'products' );
		?>
		<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="oc-of__filters">
			<input type="hidden" name="page" value="<?php echo esc_attr( Admin::PAGE ); ?>" />
			<input type="hidden" name="tab" value="products" />
			<label class="oc-of__search">
				<span class="screen-reader-text"><?php esc_html_e( 'Search products', 'oc-theme' ); ?></span>
				<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search by name or SKU', 'oc-theme' ); ?>" />
			</label>
			<select name="cat" aria-label="<?php esc_attr_e( 'Category', 'oc-theme' ); ?>">
				<option value=""><?php esc_html_e( 'All categories', 'oc-theme' ); ?></option>
				<?php foreach ( is_array( $cats ) ? $cats : array() as $term ) : ?>
					<option value="<?php echo absint( $term->term_id ); ?>" <?php selected( $cat, (int) $term->term_id ); ?>><?php echo esc_html( $term->name ); ?></option>
				<?php endforeach; ?>
			</select>
			<?php if ( $brands ) : ?>
				<select name="brand" aria-label="<?php esc_attr_e( 'Brand', 'oc-theme' ); ?>">
					<option value=""><?php echo esc_html( _x( 'All brands', 'offers filter', 'oc-theme' ) ); ?></option>
					<?php foreach ( is_array( $brands ) ? $brands : array() as $term ) : ?>
						<option value="<?php echo absint( $term->term_id ); ?>" <?php selected( $brand, (int) $term->term_id ); ?>><?php echo esc_html( $term->name ); ?></option>
					<?php endforeach; ?>
				</select>
			<?php endif; ?>
			<select name="src" aria-label="<?php esc_attr_e( 'Offer source', 'oc-theme' ); ?>">
				<option value=""><?php esc_html_e( 'Offers: all', 'oc-theme' ); ?></option>
				<option value="manual" <?php selected( 'manual', $src ); ?>><?php esc_html_e( 'Picked by hand', 'oc-theme' ); ?></option>
				<option value="nomanual" <?php selected( 'nomanual', $src ); ?>><?php esc_html_e( 'Rule or automatic only', 'oc-theme' ); ?></option>
			</select>
			<button class="oc-of__btn"><?php esc_html_e( 'Filter', 'oc-theme' ); ?></button>
		</form>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="oc-of__bulk" data-oc-of-bulk hidden>
			<input type="hidden" name="action" value="oc_offers_link" />
			<input type="hidden" name="back" value="<?php echo esc_attr( self::url() ); ?>" />
			<input type="hidden" name="ids" value="" data-oc-of-ids />
			<input type="hidden" name="target" value="" data-oc-of-bulk-target />
			<?php wp_nonce_field( 'oc_offers_link' ); ?>
			<strong><span data-oc-of-count>0</span> <?php esc_html_e( 'products selected', 'oc-theme' ); ?></strong>
			<span class="oc-of__pick oc-of__pick--bulk" data-oc-of-pick data-mode="bulk">
				<input type="text" placeholder="<?php esc_attr_e( 'Product to offer beside them…', 'oc-theme' ); ?>" aria-label="<?php esc_attr_e( 'Product to offer beside them', 'oc-theme' ); ?>" autocomplete="off" />
				<div class="oc-of__pickmenu" hidden></div>
			</span>
			<select name="kind" aria-label="<?php esc_attr_e( 'As what', 'oc-theme' ); ?>">
				<option value="xsell"><?php esc_html_e( 'Goes with it', 'oc-theme' ); ?></option>
				<option value="upsell"><?php esc_html_e( 'Upgrade', 'oc-theme' ); ?></option>
				<option value="bt"><?php esc_html_e( 'Bought together', 'oc-theme' ); ?></option>
			</select>
			<button class="oc-of__btn oc-of__btn--primary" name="what" value="bulk_add" data-oc-of-bulk-add disabled><?php esc_html_e( 'Add beside all of them', 'oc-theme' ); ?></button>
			<button class="oc-of__btn" name="what" value="bulk_clear" data-oc-of-confirm><?php esc_html_e( 'Clear that list on all of them', 'oc-theme' ); ?></button>
			<details class="oc-of__bulktiers">
				<summary class="oc-of__btn"><?php esc_html_e( 'Set quantity tiers', 'oc-theme' ); ?></summary>
				<div class="oc-of__bulktiers-body">
					<?php echo Tiers_Admin::table( 'bt', array(), 1, 0.0, false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built escaped. ?>
					<button class="oc-of__btn oc-of__btn--primary" name="what" value="bulk_tiers"><?php esc_html_e( 'Give these tiers to all of them', 'oc-theme' ); ?></button>
				</div>
			</details>
		</form>
		<?php echo Tiers_Admin::style() . Tiers_Admin::script(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static. ?>

		<table class="oc-of__table">
			<thead>
				<tr>
					<th scope="col" style="width:40px;"><input type="checkbox" data-oc-of-pick-all aria-label="<?php esc_attr_e( 'Select all', 'oc-theme' ); ?>" /></th>
					<th scope="col"><?php esc_html_e( 'Product', 'oc-theme' ); ?></th>
					<th scope="col"><?php echo esc_html( _x( 'Goes with it', 'offers column', 'oc-theme' ) ); ?><small><?php esc_html_e( 'cart and product page', 'oc-theme' ); ?></small></th>
					<th scope="col"><?php esc_html_e( 'Upgrades', 'oc-theme' ); ?><small><?php esc_html_e( 'product page', 'oc-theme' ); ?></small></th>
					<th scope="col"><?php esc_html_e( 'Bought together', 'oc-theme' ); ?><small><?php esc_html_e( 'bundle on the product page', 'oc-theme' ); ?></small></th>
					<th scope="col"><?php esc_html_e( 'Similar', 'oc-theme' ); ?><small><?php esc_html_e( 'foot of the product page', 'oc-theme' ); ?></small></th>
					<th scope="col"><?php esc_html_e( 'Quantity tiers', 'oc-theme' ); ?><small><?php esc_html_e( 'product page', 'oc-theme' ); ?></small></th>
					<th scope="col"><?php esc_html_e( 'Performance', 'oc-theme' ); ?><small><?php esc_html_e( '30 days', 'oc-theme' ); ?></small></th>
					<th scope="col" style="width:52px;"><span class="screen-reader-text"><?php esc_html_e( 'Open', 'oc-theme' ); ?></span></th>
				</tr>
			</thead>
			<tbody>
			<?php
			if ( ! $ids ) {
				echo '<tr><td colspan="9" class="oc-of__muted">' . esc_html__( 'No products match.', 'oc-theme' ) . '</td></tr>';
			}

			foreach ( $ids as $id ) {
				$product = wc_get_product( $id );

				if ( ! $product instanceof \WC_Product ) {
					continue;
				}

				self::row( $product, $stats[ $id ] ?? array(), $open === $id );
			}
			?>
			</tbody>
		</table>

		<?php if ( $pages > 1 ) : ?>
			<nav class="oc-of__pages" aria-label="<?php esc_attr_e( 'Pages', 'oc-theme' ); ?>">
				<?php for ( $p = 1; $p <= $pages; $p++ ) : ?>
					<?php if ( $p === $paged ) : ?>
						<span aria-current="page"><?php echo absint( $p ); ?></span>
					<?php elseif ( $p <= 2 || $p > $pages - 2 || abs( $p - $paged ) <= 2 ) : ?>
						<a href="<?php echo esc_url( self::url( array( 'paged' => $p ) ) ); ?>"><?php echo absint( $p ); ?></a>
					<?php elseif ( abs( $p - $paged ) === 3 ) : ?>
						<em>&hellip;</em>
					<?php endif; ?>
				<?php endfor; ?>
				<span class="oc-of__muted" style="background:none;border:0;color:#5a6068;font-weight:400;"><?php echo esc_html( $count_text ); ?></span>
			</nav>
		<?php endif; ?>

		<p class="oc-of__muted" style="margin-top:12px;"><?php esc_html_e( 'A list picked by hand wins over a rule, and a rule over the automatic offers. Empty the list and the product falls back to its rule. Every change here is saved at once.', 'oc-theme' ); ?></p>
		<?php
	}

	/**
	 * One cell: what answers for a place, in a tag and a word.
	 *
	 * @param \WC_Product $product The product.
	 * @param string      $place   cart | upsell | similar.
	 * @param int[]       $own     The hand-written list for the place.
	 * @return string Escaped markup.
	 */
	public static function cell( \WC_Product $product, string $place, array $own ): string {
		$source = self::source_of( $product, $place );

		if ( $own ) {
			/* translators: %d: number of products. */
			return '<span class="oc-of__tag oc-of__tag--manual">' . esc_html__( 'Picked by hand', 'oc-theme' ) . '</span>' . esc_html( sprintf( _n( '%d product', '%d products', count( $own ), 'oc-theme' ), count( $own ) ) );
		}

		if ( 'rule' === $source['src'] ) {
			return '<span class="oc-of__tag oc-of__tag--rule">' . esc_html__( 'Rule', 'oc-theme' ) . '</span>' . esc_html( $source['label'] );
		}

		if ( 'auto' === $source['src'] ) {
			return '<span class="oc-of__tag oc-of__tag--auto">' . esc_html__( 'Automatic', 'oc-theme' ) . '</span>';
		}

		return '<span class="oc-of__none">' . esc_html__( 'None', 'oc-theme' ) . '</span>';
	}

	/**
	 * One product row, with its panel folded beneath it.
	 *
	 * @param \WC_Product         $product The product.
	 * @param array<string,mixed> $t       Its totals.
	 * @param bool                $open    Whether the panel starts open.
	 */
	private static function row( \WC_Product $product, array $t, bool $open ): void {
		$id     = $product->get_id();
		$tiers  = Tiers::for_product( $id );
		$xsell  = self::own( $product, 'xsell' );
		$upsell = self::own( $product, 'upsell' );
		$bt     = self::own( $product, 'bt' );
		$cats   = wp_get_post_terms( $id, 'product_cat', array( 'fields' => 'names' ) );
		$img    = $product->get_image_id() ? wp_get_attachment_image_url( (int) $product->get_image_id(), 'woocommerce_gallery_thumbnail' ) : '';
		$added  = (int) ( $t['added'] ?? 0 );
		$rev    = (float) ( $t['revenue'] ?? 0 );

		/* translators: %d: number of products. */
		$bt_count = sprintf( _n( '%d product', '%d products', count( $bt ), 'oc-theme' ), count( $bt ) );
		?>
		<tr id="oc-of-row-<?php echo absint( $id ); ?>" class="oc-of__prow<?php echo $open ? ' is-open' : ''; ?>" data-oc-of-row="<?php echo absint( $id ); ?>">
			<td><input type="checkbox" value="<?php echo absint( $id ); ?>" data-oc-of-pick-row aria-label="<?php echo esc_attr( sprintf( /* translators: %s: product name. */ __( 'Select %s', 'oc-theme' ), $product->get_name() ) ); ?>" /></td>
			<td>
				<div class="oc-of__prod">
					<?php if ( $img ) : ?>
						<img src="<?php echo esc_url( $img ); ?>" alt="" loading="lazy" />
					<?php else : ?>
						<span class="oc-of__noimg" aria-hidden="true"></span>
					<?php endif; ?>
					<div>
						<strong><a href="<?php echo esc_url( (string) get_edit_post_link( $id ) ); ?>" style="color:inherit;"><?php echo esc_html( $product->get_name() ); ?></a></strong>
						<span><?php echo wp_kses_post( wc_price( (float) wc_get_price_to_display( $product ) ) ); ?><?php echo is_array( $cats ) && $cats ? ' &middot; ' . esc_html( implode( ', ', $cats ) ) : ''; ?></span>
					</div>
				</div>
			</td>
			<td class="oc-of__cell" data-oc-of-cell="xsell"><?php echo self::cell( $product, 'cart', $xsell ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built escaped. ?></td>
			<td class="oc-of__cell" data-oc-of-cell="upsell"><?php echo self::cell( $product, 'upsell', $upsell ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built escaped. ?></td>
			<td class="oc-of__cell" data-oc-of-cell="bt">
				<?php if ( $bt ) : ?>
					<span class="oc-of__tag oc-of__tag--manual"><?php esc_html_e( 'Picked by hand', 'oc-theme' ); ?></span><?php echo esc_html( $bt_count ); ?>
				<?php else : ?>
					<span class="oc-of__none"><?php esc_html_e( 'None', 'oc-theme' ); ?></span>
				<?php endif; ?>
			</td>
			<td class="oc-of__cell"><?php echo self::cell( $product, 'similar', array() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built escaped. ?></td>
			<td class="oc-of__cell">
				<?php if ( $tiers ) : ?>
					<span class="oc-of__tag <?php echo 'manual' === $tiers['src'] ? 'oc-of__tag--manual' : 'oc-of__tag--rule'; ?>"><?php echo 'manual' === $tiers['src'] ? esc_html__( 'Picked by hand', 'oc-theme' ) : esc_html__( 'Rule', 'oc-theme' ); ?></span><?php echo esc_html( implode( ', ', array_map( 'strval', array_column( $tiers['tiers'], 'qty' ) ) ) ); ?>
				<?php else : ?>
					<span class="oc-of__none"><?php esc_html_e( 'Not set', 'oc-theme' ); ?></span>
				<?php endif; ?>
			</td>
			<td class="oc-of__cell">
				<?php if ( $added ) : ?>
					<strong><?php echo esc_html( number_format_i18n( $added ) ); ?></strong> <?php echo esc_html( _x( 'added', 'offers column', 'oc-theme' ) ); ?><br /><strong class="oc-of__good"><?php echo wp_kses_post( wc_price( $rev ) ); ?></strong>
				<?php else : ?>
					<span class="oc-of__none"><?php esc_html_e( 'Not added yet', 'oc-theme' ); ?></span>
				<?php endif; ?>
			</td>
			<td>
				<button type="button" class="oc-of__openbtn" data-oc-of-open="<?php echo absint( $id ); ?>" aria-expanded="<?php echo $open ? 'true' : 'false'; ?>" aria-controls="oc-of-panel-<?php echo absint( $id ); ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: product name. */ __( 'Open %s', 'oc-theme' ), $product->get_name() ) ); ?>"><span aria-hidden="true">&#9660;</span></button>
			</td>
		</tr>
		<tr id="oc-of-panel-<?php echo absint( $id ); ?>" class="oc-of__detailrow" <?php echo $open ? '' : 'hidden'; ?>><td colspan="9" class="oc-of__detail">
			<div class="oc-of__detailgrid">
				<?php
				self::listbox( $product, 'xsell', $xsell, __( 'Goes with it: the offer in the cart, and "goes well with" on the product page', 'oc-theme' ), self::source_of( $product, 'cart' ), true );
				self::listbox( $product, 'upsell', $upsell, __( 'Upgrades: the better version, on the product page', 'oc-theme' ), self::source_of( $product, 'upsell' ), false );
				$none = array(
					'src'   => '',
					'label' => '',
				);
				self::listbox( $product, 'bt', $bt, __( 'Bought together: a bundle on the product page', 'oc-theme' ), $none, false );
				?>

				<div class="oc-of__box">
					<div class="oc-of__boxh"><strong><?php esc_html_e( 'Similar, at the foot of the product page', 'oc-theme' ); ?></strong></div>
					<?php $sim = self::source_of( $product, 'similar' ); ?>
					<span class="oc-of__muted">
						<?php
						if ( 'rule' === $sim['src'] ) {
							/* translators: %s: rule name. */
							echo esc_html( sprintf( __( 'From the rule "%s".', 'oc-theme' ), $sim['label'] ) );
						} else {
							esc_html_e( 'Automatic: products from the same category, the closest first. A rule can choose them instead.', 'oc-theme' );
						}
						?>
					</span>
				</div>

				<div class="oc-of__box">
					<div class="oc-of__boxh"><strong><?php esc_html_e( 'Quantity tiers, on the product page', 'oc-theme' ); ?></strong></div>
					<?php if ( $tiers ) : ?>
						<div class="oc-of__chips">
							<?php foreach ( $tiers['tiers'] as $tier ) : ?>
								<span class="oc-of__chip"><?php echo esc_html( Admin::tier_text( $tier ) ); ?></span>
							<?php endforeach; ?>
						</div>
						<span class="oc-of__muted"><?php echo 'manual' === $tiers['src'] ? esc_html__( 'Set on the product itself.', 'oc-theme' ) : esc_html__( 'From a rule; the product can be given tiers of its own.', 'oc-theme' ); ?></span>
					<?php elseif ( ! Tiers::fits( $product ) ) : ?>
						<span class="oc-of__muted"><?php esc_html_e( 'Tiers need a product with one price: simple, in stock.', 'oc-theme' ); ?></span>
					<?php else : ?>
						<span class="oc-of__muted"><?php esc_html_e( 'Not set for this product.', 'oc-theme' ); ?></span>
					<?php endif; ?>
					<a href="<?php echo esc_url( (string) get_edit_post_link( $id ) ); ?>" class="oc-of__btn"><?php esc_html_e( 'Set tiers on the product', 'oc-theme' ); ?></a>
				</div>
			</div>
		</td></tr>
		<?php
	}

	/**
	 * One list's box: chips, and an open field that saves as you pick.
	 *
	 * @param \WC_Product                    $product The product.
	 * @param string                         $kind    xsell | upsell | bt.
	 * @param int[]                          $ids     The list.
	 * @param string                         $title   Box title.
	 * @param array{src:string,label:string} $source  Who answers when the list is empty.
	 * @param bool                           $lead    Whether this is the lead box.
	 */
	private static function listbox( \WC_Product $product, string $kind, array $ids, string $title, array $source, bool $lead ): void {
		$id = $product->get_id();

		$bundle_note = sprintf(
			/* translators: %s: link to the product's edit screen. */
			esc_html__( 'The bundle\'s discount and title are set on %s.', 'oc-theme' ),
			'<a href="' . esc_url( (string) get_edit_post_link( $id ) ) . '">' . esc_html__( 'the product itself', 'oc-theme' ) . '</a>'
		);
		?>
		<div class="oc-of__box<?php echo $lead ? ' is-lead' : ''; ?>" data-oc-of-list="<?php echo esc_attr( $kind ); ?>" data-product="<?php echo absint( $id ); ?>">
			<div class="oc-of__boxh"><strong><?php echo esc_html( $title ); ?></strong><span class="oc-of__saved" data-oc-of-saved aria-live="polite"></span></div>
			<div class="oc-of__chips" data-oc-of-chips>
				<?php foreach ( $ids as $pid ) : ?>
					<?php echo self::chip( $pid ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built escaped. ?>
				<?php endforeach; ?>
			</div>
			<span class="oc-of__muted" data-oc-of-fallback <?php echo $ids ? 'hidden' : ''; ?>>
				<?php
				if ( 'rule' === $source['src'] ) {
					/* translators: %s: rule name. */
					echo esc_html( sprintf( __( 'Nothing picked by hand. Today the rule "%s" answers.', 'oc-theme' ), $source['label'] ) );
				} elseif ( 'auto' === $source['src'] ) {
					esc_html_e( 'Nothing picked by hand. Today the automatic offers answer.', 'oc-theme' );
				} elseif ( 'bt' === $kind ) {
					esc_html_e( 'No bundle on this product.', 'oc-theme' );
				} else {
					esc_html_e( 'Nothing picked by hand, and no rule answers.', 'oc-theme' );
				}
				?>
			</span>
			<span class="oc-of__pick" data-oc-of-pick data-mode="list">
				<input type="text" placeholder="<?php esc_attr_e( 'Type a product name to add it…', 'oc-theme' ); ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: list name. */ __( 'Add a product: %s', 'oc-theme' ), $title ) ); ?>" autocomplete="off" />
				<div class="oc-of__pickmenu" hidden></div>
			</span>
			<?php if ( 'bt' === $kind ) : ?>
				<span class="oc-of__muted"><?php echo $bundle_note; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built escaped. ?></span>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * One product in a list: picture, name, remove.
	 *
	 * @param int $pid The listed product.
	 * @return string Escaped markup.
	 */
	public static function chip( int $pid ): string {
		$product = wc_get_product( $pid );

		if ( ! $product instanceof \WC_Product ) {
			return '';
		}

		$img = $product->get_image_id() ? (string) wp_get_attachment_image_url( (int) $product->get_image_id(), 'woocommerce_gallery_thumbnail' ) : '';

		/* translators: %s: product name. */
		$label = sprintf( __( 'Remove %s', 'oc-theme' ), $product->get_name() );

		return '<span class="oc-of__chip oc-of__chip--x" data-oc-of-chip="' . absint( $pid ) . '">'
			. ( '' !== $img ? '<img src="' . esc_url( $img ) . '" alt="" />' : '' )
			. esc_html( $product->get_name() )
			. ' <button type="button" data-oc-of-unlink="' . absint( $pid ) . '" aria-label="' . esc_attr( $label ) . '">&times;</button></span>';
	}

	/**
	 * Change one product's list.
	 *
	 * @param int        $product_id Product id.
	 * @param string     $kind       xsell | upsell | bt.
	 * @param int[]      $add        Ids to add.
	 * @param int[]|null $remove     Ids to remove; null empties the list.
	 * @return int[] The list after the change.
	 */
	public static function change( int $product_id, string $kind, array $add, ?array $remove ): array {
		$product = wc_get_product( $product_id );

		if ( ! $product instanceof \WC_Product || ! in_array( $kind, self::KINDS, true ) ) {
			return array();
		}

		$current = self::own( $product, $kind );
		$list    = null === $remove ? array() : array_values( array_diff( $current, $remove ) );

		foreach ( $add as $id ) {
			$id = (int) $id;

			if ( $id !== $product_id && 'product' === get_post_type( $id ) && ! in_array( $id, $list, true ) ) {
				$list[] = $id;
			}
		}

		if ( 'bt' === $kind ) {
			update_post_meta( $product_id, '_oc_bt_ids', $list );
		} elseif ( 'upsell' === $kind ) {
			$product->set_upsell_ids( $list );
			$product->save();
		} else {
			$product->set_cross_sell_ids( $list );
			$product->save();
		}

		return $list;
	}

	/**
	 * The open field's save: add or remove one product, answer with the
	 * chip and the row's cell.
	 */
	public function ajax_link(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error();
		}

		check_ajax_referer( 'oc_offers_link' );

		$what    = isset( $_POST['what'] ) ? sanitize_key( (string) wp_unslash( $_POST['what'] ) ) : '';
		$kind    = isset( $_POST['kind'] ) ? sanitize_key( (string) wp_unslash( $_POST['kind'] ) ) : '';
		$product = isset( $_POST['product'] ) ? absint( $_POST['product'] ) : 0;
		$target  = isset( $_POST['target'] ) ? absint( $_POST['target'] ) : 0;

		if ( ! $product || ! $target || ! in_array( $kind, self::KINDS, true ) || ! in_array( $what, array( 'add', 'remove' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Nothing to change.', 'oc-theme' ) ) );
		}

		$list = 'add' === $what ? self::change( $product, $kind, array( $target ), array() ) : self::change( $product, $kind, array(), array( $target ) );
		$obj  = wc_get_product( $product );

		Offers::forget();

		$cell = '';

		if ( $obj instanceof \WC_Product ) {
			if ( 'bt' === $kind ) {
				$cell = $list
					/* translators: %d: number of products. */
					? '<span class="oc-of__tag oc-of__tag--manual">' . esc_html__( 'Picked by hand', 'oc-theme' ) . '</span>' . esc_html( sprintf( _n( '%d product', '%d products', count( $list ), 'oc-theme' ), count( $list ) ) )
					: '<span class="oc-of__none">' . esc_html__( 'None', 'oc-theme' ) . '</span>';
			} else {
				$cell = self::cell( $obj, 'upsell' === $kind ? 'upsell' : 'cart', $list );
			}
		}

		wp_send_json_success(
			array(
				'chip'  => 'add' === $what && in_array( $target, $list, true ) ? self::chip( $target ) : '',
				'count' => count( $list ),
				'cell'  => $cell,
			)
		);
	}

	/**
	 * The bulk form: add to, clear, or give tiers to many products.
	 */
	public function link(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die();
		}

		check_admin_referer( 'oc_offers_link' );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified just above.
		$what    = isset( $_POST['what'] ) ? sanitize_key( (string) wp_unslash( $_POST['what'] ) ) : '';
		$kind    = isset( $_POST['kind'] ) ? sanitize_key( (string) wp_unslash( $_POST['kind'] ) ) : 'xsell';
		$target  = isset( $_POST['target'] ) ? absint( $_POST['target'] ) : 0;
		$raw_ids = isset( $_POST['ids'] ) ? sanitize_text_field( (string) wp_unslash( $_POST['ids'] ) ) : '';
		$ids     = array_filter( array_map( 'absint', explode( ',', $raw_ids ) ) );
		$back    = isset( $_POST['back'] ) ? esc_url_raw( wp_unslash( $_POST['back'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( ! in_array( $kind, self::KINDS, true ) ) {
			$kind = 'xsell';
		}

		if ( 'bulk_add' === $what && $target ) {
			foreach ( $ids as $id ) {
				self::change( $id, $kind, array( $target ), array() );
			}
		} elseif ( 'bulk_clear' === $what ) {
			foreach ( $ids as $id ) {
				self::change( $id, $kind, array(), null );
			}
		} elseif ( 'bulk_tiers' === $what ) {
			$posted = Tiers_Admin::posted( 'bt' );

			foreach ( $ids as $id ) {
				if ( 'product' === get_post_type( $id ) ) {
					update_post_meta(
						$id,
						Tiers::META,
						array(
							'own'     => 1,
							'tiers'   => $posted['tiers'],
							'default' => $posted['default'],
						)
					);
				}
			}
		}

		wp_safe_redirect( '' !== $back && false !== strpos( $back, 'page=' . Admin::PAGE ) ? $back : self::url() );
		exit;
	}
}
