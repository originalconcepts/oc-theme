<?php
/**
 * Offers: the "by product" tab.
 *
 * Every product in one table, and beside each one every kind of offer it
 * carries — what goes with it (the cart offer), what is bought together
 * with it (the bundle), what is like it, and its quantity tiers — each
 * marked with where it came from: picked by hand, a rule, or automatic.
 * A row opens in place to add or remove a product without leaving the
 * screen, and ticked rows take a bulk action.
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
	 * Hook in.
	 */
	public function register(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		add_action( 'admin_post_oc_offers_link', array( $this, 'link' ) );
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
	 * A link that changes one product's lists.
	 *
	 * @param string               $what add | remove | bulk_add | bulk_clear.
	 * @param string               $kind xsell | bt.
	 * @param array<string,scalar> $more Ids.
	 */
	private static function act_url( string $what, string $kind, array $more ): string {
		return wp_nonce_url(
			add_query_arg(
				array_merge(
					array(
						'action' => 'oc_offers_link',
						'what'   => $what,
						'kind'   => $kind,
						'back'   => self::url(),
					),
					$more
				),
				admin_url( 'admin-post.php' )
			),
			'oc_offers_link'
		);
	}

	/**
	 * Who answers for a product in the cart, without fetching the products.
	 *
	 * @param \WC_Product $product The product.
	 * @return array{src:string,label:string}
	 */
	public static function source_of( \WC_Product $product ): array {
		$s = Offers::settings();

		if ( $product->get_cross_sell_ids() ) {
			return array(
				'src'   => 'manual',
				'label' => __( 'Picked by hand', 'oc-theme' ),
			);
		}

		$uid = Rules::pick( $s['rules'], Offers::facts( $product->get_id() ), 'cart' );

		if ( null !== $uid ) {
			return array(
				'src'   => 'rule',
				'label' => (string) $s['rules'][ $uid ]['name'],
			);
		}

		if ( $s['auto'] ) {
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
			<input type="hidden" name="kind" value="xsell" />
			<input type="hidden" name="back" value="<?php echo esc_attr( self::url() ); ?>" />
			<input type="hidden" name="ids" value="" data-oc-of-ids />
			<?php wp_nonce_field( 'oc_offers_link' ); ?>
			<strong><span data-oc-of-count>0</span> <?php esc_html_e( 'products selected', 'oc-theme' ); ?></strong>
			<select class="wc-product-search" name="target" style="width:260px;" data-placeholder="<?php esc_attr_e( 'Product to offer beside them…', 'oc-theme' ); ?>" data-action="woocommerce_json_search_products" data-allow_clear="true"></select>
			<button class="oc-of__btn oc-of__btn--primary" name="what" value="bulk_add"><?php esc_html_e( 'Offer it beside all of them', 'oc-theme' ); ?></button>
			<button class="oc-of__btn" name="what" value="bulk_clear" data-oc-of-confirm><?php esc_html_e( 'Remove their hand-picked offers', 'oc-theme' ); ?></button>
		</form>

		<table class="oc-of__table">
			<thead>
				<tr>
					<th scope="col" style="width:40px;"><input type="checkbox" data-oc-of-pick-all aria-label="<?php esc_attr_e( 'Select all', 'oc-theme' ); ?>" /></th>
					<th scope="col"><?php esc_html_e( 'Product', 'oc-theme' ); ?></th>
					<th scope="col"><?php echo esc_html( _x( 'Goes with it', 'offers column', 'oc-theme' ) ); ?><small><?php esc_html_e( 'cart panel and cart page', 'oc-theme' ); ?></small></th>
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
				echo '<tr><td colspan="8" class="oc-of__muted">' . esc_html__( 'No products match.', 'oc-theme' ) . '</td></tr>';
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

		<p class="oc-of__muted" style="margin-top:12px;"><?php esc_html_e( 'A product picked by hand wins over a rule, and a rule over the automatic offers. Remove the hand-picked products and the product falls back to its rule.', 'oc-theme' ); ?></p>
		<?php
	}

	/**
	 * One product row, and its open panel when asked.
	 *
	 * @param \WC_Product         $product The product.
	 * @param array<string,mixed> $t       Its totals.
	 * @param bool                $open    Whether the panel is open.
	 */
	private static function row( \WC_Product $product, array $t, bool $open ): void {
		$id     = $product->get_id();
		$xsell  = array_map( 'intval', $product->get_cross_sell_ids() );
		$bt     = Bought_Together::ids( $id );
		$source = self::source_of( $product );
		$cats   = wp_get_post_terms( $id, 'product_cat', array( 'fields' => 'names' ) );
		$img    = $product->get_image_id() ? wp_get_attachment_image_url( (int) $product->get_image_id(), 'woocommerce_gallery_thumbnail' ) : '';
		$added  = (int) ( $t['added'] ?? 0 );
		$rev    = (float) ( $t['revenue'] ?? 0 );

		/* translators: %d: number of products. */
		$xsell_count = sprintf( _n( '%d product', '%d products', count( $xsell ), 'oc-theme' ), count( $xsell ) );
		/* translators: %d: number of products. */
		$bt_count = sprintf( _n( '%d product', '%d products', count( $bt ), 'oc-theme' ), count( $bt ) );
		?>
		<tr id="oc-of-row-<?php echo absint( $id ); ?>" class="<?php echo $open ? 'is-open' : ''; ?>">
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
			<td class="oc-of__cell">
				<?php if ( $xsell ) : ?>
					<span class="oc-of__tag oc-of__tag--manual"><?php esc_html_e( 'Picked by hand', 'oc-theme' ); ?></span>
					<?php echo esc_html( $xsell_count ); ?>
				<?php elseif ( 'rule' === $source['src'] ) : ?>
					<span class="oc-of__tag oc-of__tag--rule"><?php esc_html_e( 'Rule', 'oc-theme' ); ?></span><?php echo esc_html( $source['label'] ); ?>
				<?php elseif ( 'auto' === $source['src'] ) : ?>
					<span class="oc-of__tag oc-of__tag--auto"><?php esc_html_e( 'Automatic', 'oc-theme' ); ?></span>
				<?php else : ?>
					<span class="oc-of__none"><?php esc_html_e( 'None', 'oc-theme' ); ?></span>
				<?php endif; ?>
			</td>
			<td class="oc-of__cell">
				<?php if ( $bt ) : ?>
					<span class="oc-of__tag oc-of__tag--manual"><?php esc_html_e( 'Picked by hand', 'oc-theme' ); ?></span>
					<?php echo esc_html( $bt_count ); ?>
				<?php else : ?>
					<span class="oc-of__none"><?php esc_html_e( 'None', 'oc-theme' ); ?></span>
				<?php endif; ?>
			</td>
			<td class="oc-of__cell"><span class="oc-of__tag oc-of__tag--auto"><?php esc_html_e( 'Automatic', 'oc-theme' ); ?></span></td>
			<td class="oc-of__cell"><span class="oc-of__none"><?php esc_html_e( 'Not set', 'oc-theme' ); ?></span></td>
			<td class="oc-of__cell">
				<?php if ( $added ) : ?>
					<strong><?php echo esc_html( number_format_i18n( $added ) ); ?></strong> <?php echo esc_html( _x( 'added', 'offers column', 'oc-theme' ) ); ?><br /><strong class="oc-of__good"><?php echo wp_kses_post( wc_price( $rev ) ); ?></strong>
				<?php else : ?>
					<span class="oc-of__none"><?php esc_html_e( 'Not added yet', 'oc-theme' ); ?></span>
				<?php endif; ?>
			</td>
			<td>
				<a class="oc-of__openbtn" href="<?php echo esc_url( $open ? self::url() . '#oc-of-row-' . $id : self::url( array( 'open' => $id ) ) . '#oc-of-row-' . $id ); ?>" aria-expanded="<?php echo $open ? 'true' : 'false'; ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: product name. */ __( 'Open %s', 'oc-theme' ), $product->get_name() ) ); ?>"><?php echo $open ? '&#9650;' : '&#9660;'; ?></a>
			</td>
		</tr>
		<?php
		if ( $open ) {
			self::detail( $product, $xsell, $bt, $source );
		}
	}

	/**
	 * The open panel: the four kinds of offer, each editable in place.
	 *
	 * @param \WC_Product                    $product The product.
	 * @param int[]                          $xsell   Cross-sell ids.
	 * @param int[]                          $bt      Bought-together ids.
	 * @param array{src:string,label:string} $source  Who answers in the cart.
	 */
	private static function detail( \WC_Product $product, array $xsell, array $bt, array $source ): void {
		$id = $product->get_id();

		$bundle_note = sprintf(
			/* translators: %s: link to the product's edit screen. */
			esc_html__( 'The bundle\'s discount and title are set on %s.', 'oc-theme' ),
			'<a href="' . esc_url( (string) get_edit_post_link( $id ) ) . '">' . esc_html__( 'the product itself', 'oc-theme' ) . '</a>'
		);

		$now = array();

		if ( ! $xsell ) {
			foreach ( array_slice( Offers::for_product( $id, 'cart' ), 0, 3 ) as $offer ) {
				if ( Offers::addable( $offer['id'] ) ) {
					$now[] = get_the_title( $offer['id'] );
				}
			}
		}
		?>
		<tr class="is-open"><td colspan="8" class="oc-of__detail">
			<div class="oc-of__detailgrid">

				<div class="oc-of__box is-lead">
					<div class="oc-of__boxh"><strong><?php esc_html_e( 'Goes with it, in the cart panel and on the cart page', 'oc-theme' ); ?></strong></div>
					<?php if ( $xsell ) : ?>
						<div class="oc-of__chips">
							<?php foreach ( $xsell as $pid ) : ?>
								<?php if ( get_post( $pid ) ) : ?>
									<?php echo self::chip( 'xsell', $id, (int) $pid ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built escaped. ?>
								<?php endif; ?>
							<?php endforeach; ?>
						</div>
					<?php else : ?>
						<span class="oc-of__muted">
							<?php
							if ( 'rule' === $source['src'] ) {
								/* translators: %s: rule name. */
								echo esc_html( sprintf( __( 'Nothing picked by hand. Today the rule "%s" answers', 'oc-theme' ), $source['label'] ) );
							} elseif ( 'auto' === $source['src'] ) {
								esc_html_e( 'Nothing picked by hand. Today the automatic offers answer', 'oc-theme' );
							} else {
								esc_html_e( 'Nothing picked by hand, and no rule or automatic offer answers.', 'oc-theme' );
							}

							if ( $now ) {
								echo ': ' . esc_html( implode( ', ', $now ) );
							}
							?>
						</span>
					<?php endif; ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="oc-of__addrow">
						<input type="hidden" name="action" value="oc_offers_link" />
						<input type="hidden" name="what" value="add" />
						<input type="hidden" name="kind" value="xsell" />
						<input type="hidden" name="product" value="<?php echo absint( $id ); ?>" />
						<input type="hidden" name="back" value="<?php echo esc_attr( self::url( array( 'open' => $id ) ) . '#oc-of-row-' . $id ); ?>" />
						<?php wp_nonce_field( 'oc_offers_link' ); ?>
						<select class="wc-product-search" name="target" data-placeholder="<?php esc_attr_e( 'Add a product to offer…', 'oc-theme' ); ?>" data-action="woocommerce_json_search_products" data-exclude="<?php echo absint( $id ); ?>"></select>
						<button class="oc-of__btn oc-of__btn--primary"><?php esc_html_e( 'Add', 'oc-theme' ); ?></button>
					</form>
				</div>

				<div class="oc-of__box">
					<div class="oc-of__boxh"><strong><?php esc_html_e( 'Bought together, a bundle on the product page', 'oc-theme' ); ?></strong></div>
					<?php if ( $bt ) : ?>
						<div class="oc-of__chips">
							<?php foreach ( $bt as $pid ) : ?>
								<?php if ( get_post( $pid ) ) : ?>
									<?php echo self::chip( 'bt', $id, (int) $pid ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built escaped. ?>
								<?php endif; ?>
							<?php endforeach; ?>
						</div>
					<?php else : ?>
						<span class="oc-of__muted"><?php esc_html_e( 'No bundle on this product.', 'oc-theme' ); ?></span>
					<?php endif; ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="oc-of__addrow">
						<input type="hidden" name="action" value="oc_offers_link" />
						<input type="hidden" name="what" value="add" />
						<input type="hidden" name="kind" value="bt" />
						<input type="hidden" name="product" value="<?php echo absint( $id ); ?>" />
						<input type="hidden" name="back" value="<?php echo esc_attr( self::url( array( 'open' => $id ) ) . '#oc-of-row-' . $id ); ?>" />
						<?php wp_nonce_field( 'oc_offers_link' ); ?>
						<select class="wc-product-search" name="target" data-placeholder="<?php esc_attr_e( 'Add a product to the bundle…', 'oc-theme' ); ?>" data-action="woocommerce_json_search_products" data-exclude="<?php echo absint( $id ); ?>"></select>
						<button class="oc-of__btn"><?php esc_html_e( 'Add', 'oc-theme' ); ?></button>
					</form>
					<span class="oc-of__muted"><?php echo $bundle_note; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built escaped. ?></span>
				</div>

				<div class="oc-of__box">
					<div class="oc-of__boxh"><strong><?php esc_html_e( 'Similar, at the foot of the product page', 'oc-theme' ); ?></strong></div>
					<span class="oc-of__muted"><?php esc_html_e( 'Automatic: products from the same category, the closest first. Set shop-wide in Customize → Product page.', 'oc-theme' ); ?></span>
				</div>

				<div class="oc-of__box">
					<div class="oc-of__boxh"><strong><?php esc_html_e( 'Quantity tiers, on the product page', 'oc-theme' ); ?></strong></div>
					<span class="oc-of__muted"><?php esc_html_e( 'Not set for this product.', 'oc-theme' ); ?></span>
				</div>

			</div>
		</td></tr>
		<?php
	}

	/**
	 * One product in a list, with its remove control.
	 *
	 * @param string $kind xsell | bt.
	 * @param int    $id   The product whose list it is.
	 * @param int    $pid  The listed product.
	 * @return string Escaped markup.
	 */
	private static function chip( string $kind, int $id, int $pid ): string {
		$href = self::act_url(
			'remove',
			$kind,
			array(
				'product' => $id,
				'target'  => $pid,
			)
		);

		/* translators: %s: product name. */
		$label = sprintf( __( 'Remove %s', 'oc-theme' ), get_the_title( $pid ) );

		return '<span class="oc-of__chip oc-of__chip--x">' . esc_html( get_the_title( $pid ) ) . ' <a href="' . esc_url( $href ) . '" aria-label="' . esc_attr( $label ) . '">&times;</a></span>';
	}

	/**
	 * Add to or remove from a product's lists, one or many at a time.
	 */
	public function link(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die();
		}

		check_admin_referer( 'oc_offers_link' );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing -- verified just above; GET for the links, POST for the forms.
		$what    = isset( $_REQUEST['what'] ) ? sanitize_key( (string) wp_unslash( $_REQUEST['what'] ) ) : '';
		$kind    = isset( $_REQUEST['kind'] ) && 'bt' === sanitize_key( (string) wp_unslash( $_REQUEST['kind'] ) ) ? 'bt' : 'xsell';
		$product = isset( $_REQUEST['product'] ) ? absint( $_REQUEST['product'] ) : 0;
		$target  = isset( $_REQUEST['target'] ) ? absint( $_REQUEST['target'] ) : 0;
		$raw_ids = isset( $_REQUEST['ids'] ) ? sanitize_text_field( (string) wp_unslash( $_REQUEST['ids'] ) ) : '';
		$ids     = array_filter( array_map( 'absint', explode( ',', $raw_ids ) ) );
		$back    = isset( $_REQUEST['back'] ) ? esc_url_raw( wp_unslash( $_REQUEST['back'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing

		if ( 'add' === $what && $product && $target && $target !== $product ) {
			self::change( $product, $kind, array( $target ), array() );
		} elseif ( 'remove' === $what && $product && $target ) {
			self::change( $product, $kind, array(), array( $target ) );
		} elseif ( 'bulk_add' === $what && $target ) {
			foreach ( $ids as $id ) {
				if ( $id !== $target ) {
					self::change( $id, $kind, array( $target ), array() );
				}
			}
		} elseif ( 'bulk_clear' === $what ) {
			foreach ( $ids as $id ) {
				self::change( $id, $kind, array(), null );
			}
		}

		wp_safe_redirect( '' !== $back && false !== strpos( $back, 'page=' . Admin::PAGE ) ? $back : self::url() );
		exit;
	}

	/**
	 * Change one product's list.
	 *
	 * @param int        $product_id Product id.
	 * @param string     $kind       xsell | bt.
	 * @param int[]      $add        Ids to add.
	 * @param int[]|null $remove     Ids to remove; null empties the list.
	 */
	private static function change( int $product_id, string $kind, array $add, ?array $remove ): void {
		$product = wc_get_product( $product_id );

		if ( ! $product instanceof \WC_Product ) {
			return;
		}

		$current = 'bt' === $kind ? Bought_Together::ids( $product_id ) : array_map( 'intval', $product->get_cross_sell_ids() );
		$list    = null === $remove ? array() : array_values( array_diff( $current, $remove ) );

		foreach ( $add as $id ) {
			if ( get_post( $id ) && ! in_array( $id, $list, true ) ) {
				$list[] = $id;
			}
		}

		if ( 'bt' === $kind ) {
			update_post_meta( $product_id, '_oc_bt_ids', $list );
		} else {
			$product->set_cross_sell_ids( $list );
			$product->save();
		}
	}
}
