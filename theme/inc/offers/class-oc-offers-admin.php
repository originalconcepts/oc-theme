<?php
/**
 * Offers: the screen under Theme settings.
 *
 * Two views in one page. The list: the master switches, the shop's rules
 * in the order they are asked (the first that fits a product answers),
 * what each brought in over the last thirty days, and a box that says what
 * a shopper would be offered beside any product. The editor: one rule in
 * three lines — when the shopper has THIS, offer THAT, and the few options
 * most rules never touch folded away below.
 *
 * @package OC_Theme
 */

declare( strict_types = 1 );

namespace OC\Theme\Offers;

use OC\Theme\Search;
use OC\Theme\Tabs;

defined( 'ABSPATH' ) || exit;

/**
 * Settings screen.
 */
final class Admin {

	/**
	 * The screen's slug.
	 */
	const PAGE = 'oc-offers';

	/**
	 * Hook in.
	 */
	public function register(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		add_action( 'admin_menu', array( $this, 'menu' ), 56 );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_post_oc_offers_save', array( $this, 'save_settings' ) );
		add_action( 'admin_post_oc_offers_rule', array( $this, 'save_rule' ) );
		add_action( 'admin_post_oc_offers_act', array( $this, 'act' ) );
		add_action( 'wp_ajax_oc_offers_search', array( $this, 'ajax_search' ) );
	}

	/**
	 * Submenu under Theme settings.
	 */
	public function menu(): void {
		add_submenu_page(
			Tabs::MENU,
			_x( 'Offers', 'settings screen', 'oc-theme' ),
			_x( 'Offers', 'settings screen', 'oc-theme' ),
			'manage_woocommerce',
			self::PAGE,
			array( $this, 'screen' )
		);
	}

	/**
	 * The search fields need WooCommerce's select kit.
	 *
	 * @param string $hook Screen hook.
	 */
	public function assets( $hook ): void {
		if ( false === strpos( (string) $hook, self::PAGE ) ) {
			return;
		}

		wp_enqueue_script( 'wc-enhanced-select' );
		wp_enqueue_style( 'woocommerce_admin_styles' );
	}

	/**
	 * This screen's address, with extra arguments.
	 *
	 * @param array<string,scalar> $args Query arguments.
	 */
	public static function url( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::PAGE ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * A link that does one thing to one rule.
	 *
	 * @param string $what Action: toggle, up, down, delete.
	 * @param string $uid  Rule name.
	 */
	private static function act_url( string $what, string $uid ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'oc_offers_act',
					'what'   => $what,
					'rule'   => $uid,
				),
				admin_url( 'admin-post.php' )
			),
			'oc_offers_act_' . $uid
		);
	}

	/* -------------------------------------------------------------- views */

	/**
	 * The screen: the editor when a rule is named, the list otherwise.
	 */
	public function screen(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- reading which view to draw.
		$rule = isset( $_GET['rule'] ) ? sanitize_key( (string) wp_unslash( $_GET['rule'] ) ) : '';

		if ( isset( $_GET['oc_saved'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'oc-theme' ) . '</p></div>';
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		echo '<div class="wrap oc-offers">';
		echo '<style>
			.oc-offers .oc-of-card{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:16px 20px;margin-block:16px;max-inline-size:1100px}
			.oc-offers .oc-of-card h2{margin-block-start:0}
			.oc-offers .oc-of-line{display:grid;grid-template-columns:170px minmax(0,1fr);gap:8px 16px;align-items:start;margin-block:14px}
			.oc-offers .oc-of-line>label,.oc-offers .oc-of-line>span.oc-of-lab{font-weight:600;padding-block-start:6px}
			.oc-offers .oc-of-line .select2-container{inline-size:100%!important;max-inline-size:640px}
			.oc-offers .oc-of-chip{display:inline-block;background:#f0f0f1;border-radius:999px;padding:1px 10px;margin:2px 0 2px 4px}
			.oc-offers .oc-of-off td{color:#787c82}
			.oc-offers .oc-of-off .oc-of-chip{background:#f6f7f7}
			.oc-offers .oc-of-num{white-space:nowrap}
			.oc-offers details.oc-of-more{margin-block:16px;border-block-start:1px solid #dcdcde;padding-block-start:12px}
			.oc-offers details.oc-of-more>summary{cursor:pointer;font-weight:600}
			.oc-offers details.oc-of-more>summary span{font-weight:400;color:#646970;margin-inline-start:8px}
			.oc-offers .oc-of-foot{display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin-block-start:18px}
			.oc-offers .oc-of-del{color:#b32d2e;margin-inline-start:auto}
			@media (max-width:782px){.oc-offers .oc-of-line{grid-template-columns:1fr}}
		</style>';

		if ( '' !== $rule ) {
			$this->editor( $rule );
		} else {
			$this->overview();
		}

		echo '</div>';
	}

	/**
	 * The list view.
	 */
	private function overview(): void {
		$s      = Offers::settings();
		$totals = Stats::totals( 30 );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- a filter and a read-only lookup.
		$show  = isset( $_GET['show'] ) ? sanitize_key( (string) wp_unslash( $_GET['show'] ) ) : '';
		$check = isset( $_GET['check'] ) ? absint( $_GET['check'] ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$active = 0;

		foreach ( $s['rules'] as $rule ) {
			$active += $rule['on'] ? 1 : 0;
		}

		$all = count( $s['rules'] );
		?>
		<h1 class="wp-heading-inline"><?php echo esc_html( _x( 'Offers', 'settings screen', 'oc-theme' ) ); ?></h1>
		<a href="<?php echo esc_url( self::url( array( 'rule' => 'new' ) ) ); ?>" class="page-title-action"><?php esc_html_e( 'Add a rule', 'oc-theme' ); ?></a>
		<hr class="wp-header-end" />
		<p class="description"><?php esc_html_e( 'What to offer a shopper beside what they already chose. One rule covers a whole category, so nothing has to be set product by product.', 'oc-theme' ); ?></p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="oc-of-card">
			<input type="hidden" name="action" value="oc_offers_save" />
			<?php wp_nonce_field( 'oc_offers_save' ); ?>

			<p><label><input type="checkbox" name="on" value="1" <?php checked( 1, $s['on'] ); ?> /> <strong><?php esc_html_e( 'Show offers in the shop', 'oc-theme' ); ?></strong></label><br />
			<span class="description"><?php esc_html_e( 'One offer in the cart panel and on the cart page, chosen by what is in the cart.', 'oc-theme' ); ?></span></p>

			<p><label><input type="checkbox" name="auto" value="1" <?php checked( 1, $s['auto'] ); ?> /> <strong><?php esc_html_e( 'Automatic offers', 'oc-theme' ); ?></strong></label><br />
			<span class="description"><?php esc_html_e( 'A product with no rule and nothing picked by hand still gets an offer: first what was really bought together with it, then the products most like it.', 'oc-theme' ); ?></span></p>

			<p><label><?php esc_html_e( 'Discount on automatic and hand-picked offers', 'oc-theme' ); ?>
			<input type="number" name="pct" value="<?php echo esc_attr( (string) $s['pct'] ); ?>" min="0" max="<?php echo esc_attr( (string) Rules::MAX_PERCENT ); ?>" style="width:70px;" /> %</label><br />
			<span class="description"><?php esc_html_e( '0 = no discount. A rule sets its own discount.', 'oc-theme' ); ?></span></p>

			<p class="description"><?php esc_html_e( 'Who answers first: a product picked by hand on the product (its "goes with" list), then a rule, then the automatic offers.', 'oc-theme' ); ?></p>

			<p style="margin-block-end:0;"><button class="button button-primary"><?php esc_html_e( 'Save settings', 'oc-theme' ); ?></button></p>
		</form>

		<h2><?php esc_html_e( 'Rules', 'oc-theme' ); ?></h2>
		<ul class="subsubsub" style="float:none;margin-block-end:8px;">
			<li><a href="<?php echo esc_url( self::url() ); ?>" <?php echo '' === $show ? 'class="current" aria-current="page"' : ''; ?>><?php esc_html_e( 'All', 'oc-theme' ); ?> <span class="count">(<?php echo absint( $all ); ?>)</span></a> |</li>
			<li><a href="<?php echo esc_url( self::url( array( 'show' => 'on' ) ) ); ?>" <?php echo 'on' === $show ? 'class="current" aria-current="page"' : ''; ?>><?php echo esc_html( _x( 'Active', 'rules filter', 'oc-theme' ) ); ?> <span class="count">(<?php echo absint( $active ); ?>)</span></a> |</li>
			<li><a href="<?php echo esc_url( self::url( array( 'show' => 'off' ) ) ); ?>" <?php echo 'off' === $show ? 'class="current" aria-current="page"' : ''; ?>><?php echo esc_html( _x( 'Inactive', 'rules filter', 'oc-theme' ) ); ?> <span class="count">(<?php echo absint( $all - $active ); ?>)</span></a></li>
		</ul>

		<table class="wp-list-table widefat striped" style="max-inline-size:1100px;">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Rule', 'oc-theme' ); ?></th>
					<th scope="col" class="oc-of-num"><?php esc_html_e( 'Discount', 'oc-theme' ); ?></th>
					<th scope="col" class="oc-of-num"><?php echo esc_html( _x( 'Seen', 'offers column', 'oc-theme' ) ); ?></th>
					<th scope="col" class="oc-of-num"><?php echo esc_html( _x( 'Added', 'offers column', 'oc-theme' ) ); ?></th>
					<th scope="col" class="oc-of-num"><?php echo esc_html( _x( 'Sold', 'offers column', 'oc-theme' ) ); ?></th>
					<th scope="col"><?php esc_html_e( 'Status', 'oc-theme' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php
			$shown = 0;
			$place = 0;
			$last  = $all - 1;

			foreach ( $s['rules'] as $uid => $rule ) {
				$at = $place++;

				if ( ( 'on' === $show && ! $rule['on'] ) || ( 'off' === $show && $rule['on'] ) ) {
					continue;
				}

				++$shown;

				$t = $totals[ 'r:' . $uid ] ?? array();
				?>
				<tr class="<?php echo $rule['on'] ? '' : 'oc-of-off'; ?>">
					<td>
						<strong><a href="<?php echo esc_url( self::url( array( 'rule' => $uid ) ) ); ?>"><?php echo esc_html( '' !== $rule['name'] ? $rule['name'] : __( '(no name)', 'oc-theme' ) ); ?></a></strong>
						<div><?php echo self::sentence( $rule ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built escaped. ?></div>
						<div class="row-actions" style="position:static;">
							<span><a href="<?php echo esc_url( self::url( array( 'rule' => $uid ) ) ); ?>"><?php esc_html_e( 'Edit', 'oc-theme' ); ?></a> | </span>
							<?php if ( 0 !== $at ) : ?>
								<span><a href="<?php echo esc_url( self::act_url( 'up', $uid ) ); ?>"><?php esc_html_e( 'Move up', 'oc-theme' ); ?></a> | </span>
							<?php endif; ?>
							<?php if ( $at < $last ) : ?>
								<span><a href="<?php echo esc_url( self::act_url( 'down', $uid ) ); ?>"><?php esc_html_e( 'Move down', 'oc-theme' ); ?></a> | </span>
							<?php endif; ?>
							<span class="delete"><a href="<?php echo esc_url( self::act_url( 'delete', $uid ) ); ?>" onclick="return confirm( this.dataset.ask );" data-ask="<?php esc_attr_e( 'Delete this rule?', 'oc-theme' ); ?>"><?php esc_html_e( 'Delete', 'oc-theme' ); ?></a></span>
						</div>
					</td>
					<td class="oc-of-num"><?php echo $rule['pct'] > 0 ? esc_html( $rule['pct'] . '%' ) : '&ndash;'; ?></td>
					<?php self::stat_cells( $t ); ?>
					<td>
						<?php echo $rule['on'] ? esc_html( _x( 'Active', 'rule status', 'oc-theme' ) ) : esc_html( _x( 'Inactive', 'rule status', 'oc-theme' ) ); ?><br />
						<a href="<?php echo esc_url( self::act_url( 'toggle', $uid ) ); ?>"><?php echo $rule['on'] ? esc_html__( 'Switch off', 'oc-theme' ) : esc_html__( 'Switch on', 'oc-theme' ); ?></a>
					</td>
				</tr>
				<?php
			}

			if ( ! $shown ) {
				echo '<tr><td colspan="6">' . esc_html( $all ? __( 'No rules in this view.', 'oc-theme' ) : __( 'No rules yet. Until there is one, the automatic offers answer for every product.', 'oc-theme' ) ) . '</td></tr>';
			}
			?>
			</tbody>
			<tfoot>
				<tr>
					<td><strong><?php esc_html_e( 'Picked by hand on the product', 'oc-theme' ); ?></strong></td>
					<td class="oc-of-num"><?php echo $s['pct'] > 0 ? esc_html( $s['pct'] . '%' ) : '&ndash;'; ?></td>
					<?php self::stat_cells( $totals['manual'] ?? array() ); ?>
					<td></td>
				</tr>
				<tr>
					<td><strong><?php esc_html_e( 'Automatic offers', 'oc-theme' ); ?></strong></td>
					<td class="oc-of-num"><?php echo $s['pct'] > 0 ? esc_html( $s['pct'] . '%' ) : '&ndash;'; ?></td>
					<?php self::stat_cells( $totals['auto'] ?? array() ); ?>
					<td><?php echo $s['auto'] ? esc_html( _x( 'Active', 'rule status', 'oc-theme' ) ) : esc_html( _x( 'Inactive', 'rule status', 'oc-theme' ) ); ?></td>
				</tr>
			</tfoot>
		</table>
		<p class="description"><?php esc_html_e( 'The numbers are for the last 30 days. Rules are asked from the top: the first one that fits a product answers for it.', 'oc-theme' ); ?></p>

		<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="oc-of-card">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>" />
			<h2><?php esc_html_e( 'Check a product', 'oc-theme' ); ?></h2>
			<p class="description"><?php esc_html_e( 'What a shopper with this product in the cart would be offered, and who decided it.', 'oc-theme' ); ?></p>
			<p>
				<select class="wc-product-search" name="check" style="width:360px;" data-placeholder="<?php esc_attr_e( 'Search for a product…', 'oc-theme' ); ?>" data-action="woocommerce_json_search_products" data-allow_clear="true">
					<?php if ( $check && get_post( $check ) ) : ?>
						<option value="<?php echo absint( $check ); ?>" selected="selected"><?php echo esc_html( get_the_title( $check ) ); ?></option>
					<?php endif; ?>
				</select>
				<button class="button"><?php esc_html_e( 'Check', 'oc-theme' ); ?></button>
			</p>
			<?php
			if ( $check ) {
				self::check_result( $check );
			}
			?>
		</form>
		<?php
	}

	/**
	 * Three number cells: seen, added, sold.
	 *
	 * @param array<string,mixed> $t One source's totals.
	 */
	private static function stat_cells( array $t ): void {
		$orders  = (int) ( $t['orders'] ?? 0 );
		$revenue = (float) ( $t['revenue'] ?? 0 );

		echo '<td class="oc-of-num">' . esc_html( number_format_i18n( (int) ( $t['seen'] ?? 0 ) ) ) . '</td>';
		echo '<td class="oc-of-num">' . esc_html( number_format_i18n( (int) ( $t['added'] ?? 0 ) ) ) . '</td>';
		echo '<td class="oc-of-num">' . ( $orders ? wp_kses_post( wc_price( $revenue ) ) . ' <span class="description">(' . esc_html( number_format_i18n( $orders ) ) . ')</span>' : '&ndash;' ) . '</td>';
	}

	/**
	 * A rule in one line of words and chips.
	 *
	 * @param array<string,mixed> $rule A clean rule.
	 * @return string Escaped markup.
	 */
	private static function sentence( array $rule ): string {
		$when  = array_merge(
			self::names( 'c', $rule['when']['cats'] ),
			self::names( 'b', $rule['when']['brands'] ),
			self::names( 'p', $rule['when']['products'] )
		);
		$offer = array_merge(
			self::names( 'c', $rule['offer']['cats'] ),
			self::names( 'p', $rule['offer']['products'] )
		);

		$html = esc_html__( 'When the shopper has', 'oc-theme' ) . ' ';

		if ( $when ) {
			foreach ( $when as $name ) {
				$html .= '<span class="oc-of-chip">' . esc_html( $name ) . '</span>';
			}
		} else {
			$html .= '<span class="oc-of-chip">' . esc_html__( 'any product', 'oc-theme' ) . '</span>';
		}

		$html .= ' ' . esc_html__( 'offer', 'oc-theme' ) . ' ';

		foreach ( $offer as $name ) {
			$html .= '<span class="oc-of-chip">' . esc_html( $name ) . '</span>';
		}

		if ( $rule['same_brand'] ) {
			$html .= '<span class="oc-of-chip">' . esc_html( $offer ? __( 'same brand only', 'oc-theme' ) : __( 'more from the same brand', 'oc-theme' ) ) . '</span>';
		} elseif ( ! $offer ) {
			$html .= '<span class="oc-of-chip">' . esc_html__( 'nothing yet', 'oc-theme' ) . '</span>';
		}

		return $html;
	}

	/**
	 * Names for a list of ids, labelled by kind.
	 *
	 * @param string $kind c = category, b = brand, p = product.
	 * @param int[]  $ids  Ids.
	 * @return array<string,string> Token => label.
	 */
	private static function names( string $kind, array $ids ): array {
		$out = array();

		foreach ( $ids as $id ) {
			$label = self::label( $kind, (int) $id );

			if ( '' !== $label ) {
				$out[ $kind . ':' . (int) $id ] = $label;
			}
		}

		return $out;
	}

	/**
	 * One thing's label: "Category: Bathroom".
	 *
	 * @param string $kind c, b or p.
	 * @param int    $id   Id.
	 */
	private static function label( string $kind, int $id ): string {
		if ( 'p' === $kind ) {
			$title = get_post( $id ) ? get_the_title( $id ) : '';

			/* translators: %s: product name. */
			return '' !== $title ? sprintf( __( 'Product: %s', 'oc-theme' ), $title ) : '';
		}

		$tax = 'b' === $kind ? Search::brand_taxonomy() : 'product_cat';

		if ( '' === $tax ) {
			return '';
		}

		$term = get_term( $id, $tax );

		if ( ! $term instanceof \WP_Term ) {
			return '';
		}

		return 'b' === $kind
			/* translators: %s: brand name. */
			? sprintf( __( 'Brand: %s', 'oc-theme' ), $term->name )
			/* translators: %s: category name. */
			: sprintf( __( 'Category: %s', 'oc-theme' ), $term->name );
	}

	/**
	 * What a shopper would be offered beside one product.
	 *
	 * @param int $product_id Product id.
	 */
	private static function check_result( int $product_id ): void {
		$s      = Offers::settings();
		$offers = Offers::for_product( $product_id, 'cart' );
		$rows   = array();

		foreach ( $offers as $offer ) {
			$product = Offers::addable( $offer['id'] );

			if ( $product ) {
				$rows[] = array( $product, $offer );
			}

			if ( count( $rows ) >= 5 ) {
				break;
			}
		}

		if ( ! $rows ) {
			echo '<p><strong>' . esc_html__( 'Nothing would be offered beside this product.', 'oc-theme' ) . '</strong> ' . esc_html__( 'Only products that can be added with one tap are offered: in stock, visible and with nothing to choose.', 'oc-theme' ) . '</p>';

			return;
		}

		$src = (string) $rows[0][1]['src'];

		if ( 'manual' === $src ) {
			$who = __( 'Picked by hand on the product', 'oc-theme' );
		} elseif ( 'auto' === $src ) {
			$who = __( 'Automatic offers', 'oc-theme' );
		} else {
			$uid  = substr( $src, 2 );
			$name = (string) ( $s['rules'][ $uid ]['name'] ?? '' );

			/* translators: %s: rule name. */
			$who = sprintf( __( 'The rule "%s"', 'oc-theme' ), $name );
		}

		echo '<p>' . esc_html__( 'Decided by:', 'oc-theme' ) . ' <strong>' . esc_html( $who ) . '</strong></p><ol>';

		foreach ( $rows as $i => $row ) {
			list( $product, $offer ) = $row;

			echo '<li>' . esc_html( $product->get_name() ) . ' &middot; ' . wp_kses_post( wc_price( (float) wc_get_price_to_display( $product ) ) );

			if ( $offer['pct'] > 0 ) {
				echo ' &middot; ' . esc_html( $offer['pct'] . '%' );
			}

			if ( 0 === $i ) {
				echo ' <span class="description">' . esc_html__( '(shown first)', 'oc-theme' ) . '</span>';
			}

			echo '</li>';
		}

		echo '</ol>';

		if ( ! $s['on'] ) {
			echo '<p class="description">' . esc_html__( 'Offers are switched off, so shoppers do not see this yet.', 'oc-theme' ) . '</p>';
		}
	}

	/**
	 * The editor for one rule.
	 *
	 * @param string $uid Rule name, or "new".
	 */
	private function editor( string $uid ): void {
		$s    = Offers::settings();
		$new  = ! isset( $s['rules'][ $uid ] );
		$rule = $new ? Rules::clean( array( 'on' => 1 ) ) : $s['rules'][ $uid ];
		$uid  = $new ? 'new' : $uid;

		$nonce   = wp_create_nonce( 'oc_offers_search' );
		$has_tax = '' !== Search::brand_taxonomy();

		$when  = array_merge(
			self::names( 'c', $rule['when']['cats'] ),
			self::names( 'b', $rule['when']['brands'] ),
			self::names( 'p', $rule['when']['products'] )
		);
		$offer = array_merge(
			self::names( 'c', $rule['offer']['cats'] ),
			self::names( 'p', $rule['offer']['products'] )
		);

		$orders = array(
			'best'  => __( 'Best sellers first', 'oc-theme' ),
			'new'   => __( 'Newest first', 'oc-theme' ),
			'cheap' => __( 'Cheapest first', 'oc-theme' ),
			'dear'  => __( 'Most expensive first', 'oc-theme' ),
		);

		$more = array();

		if ( $rule['pct'] > 0 ) {
			/* translators: %d: percent. */
			$more[] = sprintf( __( 'Discount %d%%', 'oc-theme' ), $rule['pct'] );
		}

		$more[] = $orders[ $rule['order'] ];

		if ( $rule['exclude'] ) {
			/* translators: %d: number of products. */
			$more[] = sprintf( _n( '%d product left out', '%d products left out', count( $rule['exclude'] ), 'oc-theme' ), count( $rule['exclude'] ) );
		}
		?>
		<h1><?php echo esc_html( $new ? __( 'New rule', 'oc-theme' ) : __( 'Edit rule', 'oc-theme' ) ); ?></h1>
		<p><a href="<?php echo esc_url( self::url() ); ?>">&larr; <?php esc_html_e( 'All rules', 'oc-theme' ); ?></a></p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="oc-of-card">
			<input type="hidden" name="action" value="oc_offers_rule" />
			<input type="hidden" name="rule" value="<?php echo esc_attr( $uid ); ?>" />
			<?php wp_nonce_field( 'oc_offers_rule' ); ?>

			<div class="oc-of-line">
				<label for="oc-of-name"><?php esc_html_e( 'Rule name', 'oc-theme' ); ?></label>
				<div>
					<input type="text" id="oc-of-name" name="name" value="<?php echo esc_attr( $rule['name'] ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'Bathroom: more from the same brand', 'oc-theme' ); ?>" />
					<label style="margin-inline-start:14px;"><input type="checkbox" name="on" value="1" <?php checked( 1, $rule['on'] ); ?> /> <?php echo esc_html( _x( 'Active', 'rule status', 'oc-theme' ) ); ?></label>
				</div>
			</div>

			<div class="oc-of-line">
				<label for="oc-of-when"><?php esc_html_e( 'When the shopper has', 'oc-theme' ); ?></label>
				<div>
					<select id="oc-of-when" name="when[]" multiple="multiple" class="oc-of-pick" data-kinds="<?php echo $has_tax ? 'cbp' : 'cp'; ?>" data-nonce="<?php echo esc_attr( $nonce ); ?>" data-placeholder="<?php echo esc_attr( $has_tax ? __( 'Type a category, a brand or a product', 'oc-theme' ) : __( 'Type a category or a product', 'oc-theme' ) ); ?>">
						<?php foreach ( $when as $token => $label ) : ?>
							<option value="<?php echo esc_attr( $token ); ?>" selected="selected"><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( 'A category covers everything under it. Left empty, the rule covers every product.', 'oc-theme' ); ?></p>
				</div>
			</div>

			<div class="oc-of-line">
				<label for="oc-of-offer"><?php esc_html_e( 'Offer them', 'oc-theme' ); ?></label>
				<div>
					<select id="oc-of-offer" name="offer[]" multiple="multiple" class="oc-of-pick" data-kinds="cp" data-nonce="<?php echo esc_attr( $nonce ); ?>" data-placeholder="<?php esc_attr_e( 'Type a category or a product', 'oc-theme' ); ?>">
						<?php foreach ( $offer as $token => $label ) : ?>
							<option value="<?php echo esc_attr( $token ); ?>" selected="selected"><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<?php if ( $has_tax ) : ?>
						<p><label><input type="checkbox" name="same_brand" value="1" <?php checked( 1, $rule['same_brand'] ); ?> /> <strong><?php esc_html_e( 'Only from the same brand', 'oc-theme' ); ?></strong> <?php esc_html_e( 'as the product the shopper has', 'oc-theme' ); ?></label></p>
						<p class="description"><?php esc_html_e( 'Ticked with nothing typed above: everything else from that brand.', 'oc-theme' ); ?></p>
					<?php endif; ?>
				</div>
			</div>

			<div class="oc-of-line">
				<span class="oc-of-lab"><?php esc_html_e( 'Where it shows', 'oc-theme' ); ?></span>
				<div style="padding-block-start:6px;"><?php esc_html_e( 'In the cart panel and on the cart page.', 'oc-theme' ); ?></div>
			</div>

			<details class="oc-of-more">
				<summary><?php esc_html_e( 'More options', 'oc-theme' ); ?> <span><?php echo esc_html( implode( ' · ', $more ) ); ?></span></summary>

				<div class="oc-of-line">
					<label for="oc-of-pct"><?php esc_html_e( 'Discount for adding the offer', 'oc-theme' ); ?></label>
					<div>
						<input type="number" id="oc-of-pct" name="pct" value="<?php echo esc_attr( (string) $rule['pct'] ); ?>" min="0" max="<?php echo esc_attr( (string) Rules::MAX_PERCENT ); ?>" style="width:70px;" /> %
						<p class="description"><?php esc_html_e( '0 = no discount. The discount holds while the product it was offered beside stays in the cart.', 'oc-theme' ); ?></p>
					</div>
				</div>

				<div class="oc-of-line">
					<label for="oc-of-order"><?php esc_html_e( 'Which product first', 'oc-theme' ); ?></label>
					<div>
						<select id="oc-of-order" name="order">
							<?php foreach ( $orders as $key => $label ) : ?>
								<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $key, $rule['order'] ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'Products named one by one always come first, in the order they were typed.', 'oc-theme' ); ?></p>
					</div>
				</div>

				<div class="oc-of-line">
					<label for="oc-of-exclude"><?php esc_html_e( 'Never offer', 'oc-theme' ); ?></label>
					<div>
						<select id="oc-of-exclude" name="exclude[]" multiple="multiple" class="wc-product-search" style="width:100%;max-width:640px;" data-placeholder="<?php esc_attr_e( 'Search for a product…', 'oc-theme' ); ?>" data-action="woocommerce_json_search_products">
							<?php foreach ( $rule['exclude'] as $id ) : ?>
								<?php if ( get_post( $id ) ) : ?>
									<option value="<?php echo absint( $id ); ?>" selected="selected"><?php echo esc_html( get_the_title( $id ) ); ?></option>
								<?php endif; ?>
							<?php endforeach; ?>
						</select>
					</div>
				</div>
			</details>

			<div class="oc-of-foot">
				<button class="button button-primary"><?php esc_html_e( 'Save rule', 'oc-theme' ); ?></button>
				<a class="button" href="<?php echo esc_url( self::url() ); ?>"><?php esc_html_e( 'Cancel', 'oc-theme' ); ?></a>
				<?php if ( ! $new ) : ?>
					<a class="oc-of-del" href="<?php echo esc_url( self::act_url( 'delete', $uid ) ); ?>" onclick="return confirm( this.dataset.ask );" data-ask="<?php esc_attr_e( 'Delete this rule?', 'oc-theme' ); ?>"><?php esc_html_e( 'Delete rule', 'oc-theme' ); ?></a>
				<?php endif; ?>
			</div>
		</form>

		<script>
		jQuery( function ( $ ) {
			$( '.oc-of-pick' ).each( function () {
				var $el = $( this );

				$el.selectWoo( {
					minimumInputLength: 2,
					placeholder: $el.data( 'placeholder' ),
					width: '100%',
					ajax: {
						url: window.ajaxurl,
						dataType: 'json',
						delay: 250,
						data: function ( params ) {
							return {
								action: 'oc_offers_search',
								term: params.term,
								kinds: $el.data( 'kinds' ),
								_wpnonce: $el.data( 'nonce' )
							};
						},
						processResults: function ( out ) {
							return { results: out && out.success ? out.data : [] };
						},
						cache: true
					}
				} );
			} );
		} );
		</script>
		<?php
	}

	/* ------------------------------------------------------------ actions */

	/**
	 * Save the master switches.
	 */
	public function save_settings(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die();
		}

		check_admin_referer( 'oc_offers_save' );

		$s = Offers::settings();

		$s['on']   = empty( $_POST['on'] ) ? 0 : 1;
		$s['auto'] = empty( $_POST['auto'] ) ? 0 : 1;
		$s['pct']  = isset( $_POST['pct'] ) ? (int) $_POST['pct'] : 0;

		Offers::save( $s );

		// The counters' table, in case this is the first time offers are
		// switched on and no admin page ran the installer yet.
		Stats::install();

		wp_safe_redirect( self::url( array( 'oc_saved' => 1 ) ) );
		exit;
	}

	/**
	 * Tokens ("c:12", "p:99") from a posted field, split by kind.
	 *
	 * @param string $field Field name in $_POST.
	 * @return array{c:int[],b:int[],p:int[]}
	 */
	private static function tokens( string $field ): array {
		$out = array(
			'c' => array(),
			'b' => array(),
			'p' => array(),
		);

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- the caller verified the nonce; each token is matched against a strict pattern below.
		$raw = isset( $_POST[ $field ] ) ? (array) wp_unslash( $_POST[ $field ] ) : array();

		foreach ( $raw as $token ) {
			if ( is_string( $token ) && 1 === preg_match( '/^([cbp]):(\d{1,12})$/', $token, $m ) ) {
				$out[ $m[1] ][] = (int) $m[2];
			}
		}

		return $out;
	}

	/**
	 * Save one rule.
	 */
	public function save_rule(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die();
		}

		check_admin_referer( 'oc_offers_rule' );

		$s   = Offers::settings();
		$uid = isset( $_POST['rule'] ) ? sanitize_key( (string) wp_unslash( $_POST['rule'] ) ) : 'new';

		if ( ! isset( $s['rules'][ $uid ] ) ) {
			$uid = Tabs::mint();
		}

		$when  = self::tokens( 'when' );
		$offer = self::tokens( 'offer' );

		$s['rules'][ $uid ] = Rules::clean(
			array(
				'name'       => isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['name'] ) ) : '',
				'on'         => empty( $_POST['on'] ) ? 0 : 1,
				'when'       => array(
					'cats'     => $when['c'],
					'brands'   => $when['b'],
					'products' => $when['p'],
				),
				'offer'      => array(
					'cats'     => $offer['c'],
					'products' => $offer['p'],
				),
				'same_brand' => empty( $_POST['same_brand'] ) ? 0 : 1,
				'exclude'    => isset( $_POST['exclude'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['exclude'] ) ) : array(),
				'where'      => array( 'cart' ),
				'pct'        => isset( $_POST['pct'] ) ? (int) $_POST['pct'] : 0,
				'order'      => isset( $_POST['order'] ) ? sanitize_key( (string) wp_unslash( $_POST['order'] ) ) : 'best',
			)
		);

		Offers::save( $s );

		wp_safe_redirect( self::url( array( 'oc_saved' => 1 ) ) );
		exit;
	}

	/**
	 * Switch a rule on or off, move it, or delete it.
	 */
	public function act(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die();
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- the nonce is bound to the rule's name, so the name is read first and verified on the next line.
		$uid  = isset( $_GET['rule'] ) ? sanitize_key( (string) wp_unslash( $_GET['rule'] ) ) : '';
		$what = isset( $_GET['what'] ) ? sanitize_key( (string) wp_unslash( $_GET['what'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		check_admin_referer( 'oc_offers_act_' . $uid );

		$s = Offers::settings();

		if ( isset( $s['rules'][ $uid ] ) ) {
			if ( 'toggle' === $what ) {
				$s['rules'][ $uid ]['on'] = $s['rules'][ $uid ]['on'] ? 0 : 1;
			} elseif ( 'delete' === $what ) {
				unset( $s['rules'][ $uid ] );
			} elseif ( 'up' === $what || 'down' === $what ) {
				$keys = array_keys( $s['rules'] );
				$at   = (int) array_search( $uid, $keys, true );
				$to   = 'up' === $what ? $at - 1 : $at + 1;

				if ( isset( $keys[ $to ] ) ) {
					$keys[ $at ] = $keys[ $to ];
					$keys[ $to ] = $uid;

					$moved = array();

					foreach ( $keys as $key ) {
						$moved[ $key ] = $s['rules'][ $key ];
					}

					$s['rules'] = $moved;
				}
			}

			Offers::save( $s );
		}

		wp_safe_redirect( self::url( array( 'oc_saved' => 1 ) ) );
		exit;
	}

	/**
	 * The typed field's answers: categories, brands and products by name.
	 */
	public function ajax_search(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error();
		}

		check_ajax_referer( 'oc_offers_search' );

		$term  = isset( $_GET['term'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['term'] ) ) : '';
		$kinds = isset( $_GET['kinds'] ) ? sanitize_key( (string) wp_unslash( $_GET['kinds'] ) ) : 'cp';
		$out   = array();

		if ( '' === $term ) {
			wp_send_json_success( $out );
		}

		$taxes = array( 'c' => 'product_cat' );

		if ( false !== strpos( $kinds, 'b' ) && '' !== Search::brand_taxonomy() ) {
			$taxes['b'] = Search::brand_taxonomy();
		}

		foreach ( $taxes as $kind => $tax ) {
			$terms = get_terms(
				array(
					'taxonomy'   => $tax,
					'search'     => $term,
					'hide_empty' => false,
					'number'     => 8,
				)
			);

			foreach ( is_array( $terms ) ? $terms : array() as $found ) {
				if ( $found instanceof \WP_Term ) {
					$out[] = array(
						'id'   => $kind . ':' . $found->term_id,
						'text' => self::label( $kind, (int) $found->term_id ),
					);
				}
			}
		}

		if ( false !== strpos( $kinds, 'p' ) ) {
			$store = \WC_Data_Store::load( 'product' );
			// WC_Data_Store hands the call on to the real store through
			// __call, so method_exists() says no to a method that is there.
			$ids = is_callable( array( $store, 'search_products' ) ) ? (array) $store->search_products( $term, '', false, false, 10 ) : array();

			foreach ( $ids as $id ) {
				$id = (int) $id;

				if ( $id > 0 && 'product' === get_post_type( $id ) ) {
					$out[] = array(
						'id'   => 'p:' . $id,
						'text' => self::label( 'p', $id ),
					);
				}
			}
		}

		wp_send_json_success( $out );
	}
}
