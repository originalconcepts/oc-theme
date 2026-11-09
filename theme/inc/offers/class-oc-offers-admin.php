<?php
/**
 * Offers: the screen under Theme settings — the rules.
 *
 * Drawn to the approved sketch rather than to the admin's stock tables:
 * two switches up top, the rules as cards in the order they are asked
 * (the first that fits a product answers), each with what it did in the
 * last thirty days, the editor opening in place as one of the cards, and
 * beside them the rules the shop's own orders suggest. A second tab, "by
 * product", lives in Products_Admin.
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
	 * The screen's own look, plus WooCommerce's select kit for the typed
	 * fields.
	 *
	 * @param string $hook Screen hook.
	 */
	public function assets( $hook ): void {
		if ( false === strpos( (string) $hook, self::PAGE ) ) {
			return;
		}

		$dir = get_template_directory_uri() . '/assets';
		$ver = defined( 'OC_THEME_VERSION' ) ? OC_THEME_VERSION : '1';

		wp_enqueue_script( 'wc-enhanced-select' );
		wp_enqueue_style( 'woocommerce_admin_styles' );
		wp_enqueue_style( 'oc-offers-admin', $dir . '/css/offers-admin.css', array(), $ver );
		wp_enqueue_script( 'oc-offers-admin', $dir . '/js/offers-admin.js', array( 'jquery', 'wc-enhanced-select' ), $ver, true );
		wp_localize_script(
			'oc-offers-admin',
			'ocOffersAdmin',
			array(
				'nonce'  => wp_create_nonce( 'oc_offers_search' ),
				'delete' => __( 'Delete this rule?', 'oc-theme' ),
			)
		);
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
	 * A link that does one thing: to a rule, to a switch, to a suggestion.
	 *
	 * @param string               $what Action name.
	 * @param string               $uid  Rule name, or '' for the shop-wide switches.
	 * @param array<string,scalar> $more Extra arguments.
	 */
	public static function act_url( string $what, string $uid = '', array $more = array() ): string {
		return wp_nonce_url(
			add_query_arg(
				array_merge(
					array(
						'action' => 'oc_offers_act',
						'what'   => $what,
						'rule'   => $uid,
					),
					$more
				),
				admin_url( 'admin-post.php' )
			),
			'oc_offers_act_' . $uid
		);
	}

	/**
	 * A switch drawn as one: a link that flips the thing it names.
	 *
	 * @param bool   $on    Current state.
	 * @param string $href  Where flipping goes.
	 * @param string $label Accessible name.
	 * @return string Escaped markup.
	 */
	public static function switch_html( bool $on, string $href, string $label ): string {
		return '<a class="oc-of__switch" role="switch" aria-checked="' . ( $on ? 'true' : 'false' ) . '" aria-label="' . esc_attr( $label ) . '" href="' . esc_url( $href ) . '"><span class="oc-of__knob"></span></a>';
	}

	/**
	 * The tabs at the top of both screens.
	 *
	 * @param string $current rules | products.
	 * @return string Escaped markup.
	 */
	public static function tabs_html( string $current ): string {
		$html  = '<nav class="oc-of__tabs" aria-label="' . esc_attr__( 'Offer screens', 'oc-theme' ) . '">';
		$html .= '<a href="' . esc_url( self::url() ) . '"' . ( 'rules' === $current ? ' aria-current="page"' : '' ) . '>' . esc_html__( 'Rules', 'oc-theme' ) . '</a>';
		$html .= '<a href="' . esc_url( self::url( array( 'tab' => 'products' ) ) ) . '"' . ( 'products' === $current ? ' aria-current="page"' : '' ) . '>' . esc_html__( 'By product', 'oc-theme' ) . '</a>';
		$html .= '</nav>';

		return $html;
	}

	/**
	 * The page header both tabs share.
	 *
	 * @param string $current rules | products.
	 */
	public static function head( string $current ): void {
		echo '<header class="oc-of__head">';
		echo '<div><h1>' . esc_html( _x( 'Offers', 'settings screen', 'oc-theme' ) ) . '</h1>';
		echo '<p>' . esc_html__( 'What to offer a shopper beside what they already chose, and where. One rule covers a whole category.', 'oc-theme' ) . '</p></div>';
		echo self::tabs_html( $current ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built escaped.
		echo '</header>';
	}

	/* -------------------------------------------------------------- views */

	/**
	 * The screen.
	 */
	public function screen(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- reading which view to draw.
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( (string) wp_unslash( $_GET['tab'] ) ) : '';
		$rule = isset( $_GET['rule'] ) ? sanitize_key( (string) wp_unslash( $_GET['rule'] ) ) : '';
		$done = isset( $_GET['oc_done'] ) ? sanitize_key( (string) wp_unslash( $_GET['oc_done'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		echo '<div class="wrap oc-of" dir="' . ( is_rtl() ? 'rtl' : 'ltr' ) . '">';

		if ( '' !== $done ) {
			$notes = array(
				'saved'    => __( 'Saved.', 'oc-theme' ),
				'rule'     => __( 'The rule was saved.', 'oc-theme' ),
				'deleted'  => __( 'The rule was deleted.', 'oc-theme' ),
				'accepted' => __( 'The rule was added from the suggestion. Open it to adjust the discount or the wording.', 'oc-theme' ),
			);

			if ( isset( $notes[ $done ] ) ) {
				echo '<div class="oc-of__notice" role="status">' . esc_html( $notes[ $done ] ) . '</div>';
			}
		}

		if ( 'products' === $tab ) {
			Products_Admin::render();
		} else {
			$this->rules_view( $rule );
		}

		echo '</div>';
	}

	/**
	 * The rules tab.
	 *
	 * @param string $open Rule being edited, "new", or ''.
	 */
	private function rules_view( string $open ): void {
		$s      = Offers::settings();
		$totals = Stats::totals( 30 );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a read-only lookup.
		$check = isset( $_GET['check'] ) ? absint( $_GET['check'] ) : 0;

		$active = 0;

		foreach ( $s['rules'] as $rule ) {
			$active += $rule['on'] ? 1 : 0;
		}

		$all = count( $s['rules'] );

		self::head( 'rules' );
		?>

		<section class="oc-of__card oc-of__top">
			<div class="oc-of__toprow">
				<?php echo self::switch_html( (bool) $s['on'], self::act_url( 'master' ), __( 'Offers in the shop', 'oc-theme' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<div class="oc-of__topt">
					<strong><?php echo $s['on'] ? esc_html__( 'Offers are live in the shop', 'oc-theme' ) : esc_html__( 'Offers are switched off', 'oc-theme' ); ?></strong>
					<span><?php esc_html_e( 'One offer in the cart panel and on the cart page, chosen by what is in the cart.', 'oc-theme' ); ?></span>
				</div>
			</div>
			<div class="oc-of__toprow">
				<?php echo self::switch_html( (bool) $s['auto'], self::act_url( 'auto' ), __( 'Automatic offers', 'oc-theme' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<div class="oc-of__topt">
					<strong><?php esc_html_e( 'Automatic offers', 'oc-theme' ); ?></strong>
					<span><?php esc_html_e( 'A product with no rule and nothing picked by hand still gets an offer: first what was really bought together with it, then the products most like it.', 'oc-theme' ); ?></span>
				</div>
				<span class="oc-of__pill"><?php esc_html_e( 'Who answers first: picked by hand, then a rule, then automatic', 'oc-theme' ); ?></span>
			</div>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="oc-of__toprow oc-of__pctform">
				<input type="hidden" name="action" value="oc_offers_save" />
				<?php wp_nonce_field( 'oc_offers_save' ); ?>
				<label for="oc-of-gpct"><?php esc_html_e( 'Discount on automatic and hand-picked offers', 'oc-theme' ); ?></label>
				<span class="oc-of__num"><input type="number" id="oc-of-gpct" name="pct" value="<?php echo esc_attr( (string) $s['pct'] ); ?>" min="0" max="<?php echo esc_attr( (string) Rules::MAX_PERCENT ); ?>" /><span>%</span></span>
				<button class="oc-of__btn oc-of__btn--quiet"><?php esc_html_e( 'Save', 'oc-theme' ); ?></button>
				<span class="oc-of__muted"><?php esc_html_e( '0 = no discount. A rule sets its own.', 'oc-theme' ); ?></span>
			</form>
		</section>

		<div class="oc-of__cols">
			<section class="oc-of__rules">
				<div class="oc-of__rulehead">
					<div class="oc-of__rulehead-l">
						<h2><?php esc_html_e( 'Your rules', 'oc-theme' ); ?></h2>
						<div class="oc-of__seg" role="group" aria-label="<?php esc_attr_e( 'Filter the rules', 'oc-theme' ); ?>">
							<button type="button" aria-pressed="true" data-oc-of-filter="all"><?php esc_html_e( 'All', 'oc-theme' ); ?> <b><?php echo absint( $all ); ?></b></button>
							<button type="button" aria-pressed="false" data-oc-of-filter="on"><?php echo esc_html( _x( 'Active', 'rules filter', 'oc-theme' ) ); ?> <b><?php echo absint( $active ); ?></b></button>
							<button type="button" aria-pressed="false" data-oc-of-filter="off"><?php echo esc_html( _x( 'Inactive', 'rules filter', 'oc-theme' ) ); ?> <b><?php echo absint( $all - $active ); ?></b></button>
						</div>
					</div>
					<a class="oc-of__btn oc-of__btn--primary" href="<?php echo esc_url( self::url( array( 'rule' => 'new' ) ) ); ?>#oc-of-editor"><span aria-hidden="true">+</span> <?php esc_html_e( 'New rule', 'oc-theme' ); ?></a>
				</div>

				<?php
				if ( 'new' === $open ) {
					$this->editor( 'new', Rules::clean( array( 'on' => 1 ) ), $totals );
				}

				$place = 0;
				$last  = $all - 1;

				foreach ( $s['rules'] as $uid => $rule ) {
					$at = $place++;

					if ( $uid === $open ) {
						$this->editor( $uid, $rule, $totals );
						continue;
					}

					$this->rule_card( $uid, $rule, $totals[ 'r:' . $uid ] ?? array(), 0 !== $at, $at < $last );
				}

				if ( ! $all && 'new' !== $open ) {
					echo '<div class="oc-of__empty"><strong>' . esc_html__( 'No rules yet.', 'oc-theme' ) . '</strong> ' . esc_html__( 'Until there is one, the automatic offers answer for every product. Start with one of the suggestions beside, or write a rule of your own.', 'oc-theme' ) . '</div>';
				}
				?>

				<div class="oc-of__layers">
					<div class="oc-of__layer">
						<span class="oc-of__tag oc-of__tag--manual"><?php esc_html_e( 'Picked by hand', 'oc-theme' ); ?></span>
						<span class="oc-of__muted"><?php esc_html_e( 'The "goes with" list on each product', 'oc-theme' ); ?></span>
						<?php self::stats_html( $totals['manual'] ?? array() ); ?>
					</div>
					<div class="oc-of__layer<?php echo $s['auto'] ? '' : ' is-off'; ?>">
						<span class="oc-of__tag oc-of__tag--auto"><?php esc_html_e( 'Automatic', 'oc-theme' ); ?></span>
						<span class="oc-of__muted"><?php echo $s['auto'] ? esc_html__( 'Bought together, then most alike', 'oc-theme' ) : esc_html__( 'Switched off', 'oc-theme' ); ?></span>
						<?php self::stats_html( $totals['auto'] ?? array() ); ?>
					</div>
				</div>
				<p class="oc-of__muted"><?php esc_html_e( 'The numbers are for the last 30 days. Rules are asked from the top: the first one that fits a product answers for it.', 'oc-theme' ); ?></p>
			</section>

			<aside class="oc-of__side">
				<?php $this->suggestions(); ?>
			</aside>
		</div>

		<section class="oc-of__card" id="oc-of-check">
			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>" />
				<h2><?php esc_html_e( 'Check a product', 'oc-theme' ); ?></h2>
				<p class="oc-of__muted"><?php esc_html_e( 'What a shopper with this product in the cart would be offered, and who decided it.', 'oc-theme' ); ?></p>
				<div class="oc-of__checkrow">
					<select class="wc-product-search" name="check" style="width:360px;" data-placeholder="<?php esc_attr_e( 'Search for a product…', 'oc-theme' ); ?>" data-action="woocommerce_json_search_products" data-allow_clear="true">
						<?php if ( $check && get_post( $check ) ) : ?>
							<option value="<?php echo absint( $check ); ?>" selected="selected"><?php echo esc_html( get_the_title( $check ) ); ?></option>
						<?php endif; ?>
					</select>
					<button class="oc-of__btn"><?php esc_html_e( 'Check', 'oc-theme' ); ?></button>
				</div>
				<?php
				if ( $check ) {
					self::check_result( $check );
				}
				?>
			</form>
		</section>
		<?php
	}

	/**
	 * Three small numbers: seen, added, sold.
	 *
	 * @param array<string,mixed> $t One source's totals.
	 */
	public static function stats_html( array $t ): void {
		$orders  = (int) ( $t['orders'] ?? 0 );
		$revenue = (float) ( $t['revenue'] ?? 0 );

		echo '<div class="oc-of__stats">';
		echo '<span>' . esc_html( _x( 'Seen', 'offers column', 'oc-theme' ) ) . ' <strong>' . esc_html( number_format_i18n( (int) ( $t['seen'] ?? 0 ) ) ) . '</strong></span>';
		echo '<span>' . esc_html( _x( 'Added', 'offers column', 'oc-theme' ) ) . ' <strong>' . esc_html( number_format_i18n( (int) ( $t['added'] ?? 0 ) ) ) . '</strong></span>';
		echo '<span>' . esc_html__( 'Revenue', 'oc-theme' ) . ' <strong class="oc-of__good">' . ( $orders ? wp_kses_post( wc_price( $revenue ) ) : '&ndash;' ) . '</strong></span>';
		echo '</div>';
	}

	/**
	 * One rule, closed.
	 *
	 * @param string              $uid      Rule name.
	 * @param array<string,mixed> $rule     Clean rule.
	 * @param array<string,mixed> $t        Its totals.
	 * @param bool                $can_up   Not the first.
	 * @param bool                $can_down Not the last.
	 */
	private function rule_card( string $uid, array $rule, array $t, bool $can_up, bool $can_down ): void {
		$edit = self::url( array( 'rule' => $uid ) ) . '#oc-of-editor';

		/* translators: %d: percent. */
		$pct_text = sprintf( __( 'Discount %d%%', 'oc-theme' ), $rule['pct'] );
		?>
		<article class="oc-of__rule<?php echo $rule['on'] ? '' : ' is-off'; ?>" data-oc-of-on="<?php echo $rule['on'] ? '1' : '0'; ?>">
			<div class="oc-of__rulemain">
				<div class="oc-of__rulename">
					<a href="<?php echo esc_url( $edit ); ?>"><?php echo esc_html( '' !== $rule['name'] ? $rule['name'] : __( '(no name)', 'oc-theme' ) ); ?></a>
					<?php if ( ! $rule['on'] ) : ?>
						<span class="oc-of__tag"><?php echo esc_html( _x( 'Inactive', 'rule status', 'oc-theme' ) ); ?></span>
					<?php endif; ?>
				</div>
				<div class="oc-of__sentence"><?php echo self::sentence( $rule ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built escaped. ?></div>
				<div class="oc-of__tags">
					<?php if ( 'tiers' === $rule['kind'] ) : ?>
						<span class="oc-of__tag oc-of__tag--rule"><?php esc_html_e( 'Quantity tiers', 'oc-theme' ); ?></span>
						<span class="oc-of__tag oc-of__tag--line"><?php esc_html_e( 'Product page and cart', 'oc-theme' ); ?></span>
					<?php else : ?>
						<span class="oc-of__tag oc-of__tag--line"><?php esc_html_e( 'Cart panel and cart page', 'oc-theme' ); ?></span>
						<?php if ( $rule['pct'] > 0 ) : ?>
							<span class="oc-of__tag oc-of__tag--good"><?php echo esc_html( $pct_text ); ?></span>
						<?php endif; ?>
					<?php endif; ?>
				</div>
			</div>
			<?php
			if ( 'tiers' !== $rule['kind'] ) {
				self::stats_html( $t );
			}
			?>
			<div class="oc-of__ruleacts">
				<a class="oc-of__btn" href="<?php echo esc_url( $edit ); ?>"><?php esc_html_e( 'Edit', 'oc-theme' ); ?></a>
				<?php echo self::switch_html( (bool) $rule['on'], self::act_url( 'toggle', $uid ), sprintf( /* translators: %s: rule name. */ __( 'Rule active: %s', 'oc-theme' ), $rule['name'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<span class="oc-of__order">
					<?php if ( $can_up ) : ?>
						<a href="<?php echo esc_url( self::act_url( 'up', $uid ) ); ?>" aria-label="<?php esc_attr_e( 'Move up', 'oc-theme' ); ?>" title="<?php esc_attr_e( 'Move up', 'oc-theme' ); ?>">&uarr;</a>
					<?php endif; ?>
					<?php if ( $can_down ) : ?>
						<a href="<?php echo esc_url( self::act_url( 'down', $uid ) ); ?>" aria-label="<?php esc_attr_e( 'Move down', 'oc-theme' ); ?>" title="<?php esc_attr_e( 'Move down', 'oc-theme' ); ?>">&darr;</a>
					<?php endif; ?>
				</span>
			</div>
		</article>
		<?php
	}

	/**
	 * A rule in one line of words and chips.
	 *
	 * @param array<string,mixed> $rule A clean rule.
	 * @return string Escaped markup.
	 */
	public static function sentence( array $rule ): string {
		$when  = array_merge(
			self::names( 'c', $rule['when']['cats'] ),
			self::names( 'b', $rule['when']['brands'] ),
			self::names( 'p', $rule['when']['products'] )
		);
		$offer = array_merge(
			self::names( 'c', $rule['offer']['cats'] ),
			self::names( 'p', $rule['offer']['products'] )
		);

		$html = '<span class="oc-of__muted">' . esc_html( 'tiers' === $rule['kind'] ? __( 'For products in', 'oc-theme' ) : __( 'When the cart holds', 'oc-theme' ) ) . '</span> ';

		if ( $when ) {
			foreach ( $when as $name ) {
				$html .= '<span class="oc-of__chip">' . esc_html( $name ) . '</span>';
			}
		} else {
			$html .= '<span class="oc-of__chip">' . esc_html__( 'any product', 'oc-theme' ) . '</span>';
		}

		if ( 'tiers' === $rule['kind'] ) {
			$html .= ' <span class="oc-of__muted">' . esc_html__( 'tiers', 'oc-theme' ) . '</span> ';

			foreach ( $rule['tiers'] as $tier ) {
				$html .= '<span class="oc-of__chip">' . esc_html( self::tier_text( $tier ) ) . '</span>';
			}

			if ( ! $rule['tiers'] ) {
				$html .= '<span class="oc-of__chip">' . esc_html__( 'nothing yet', 'oc-theme' ) . '</span>';
			}

			return $html;
		}

		$html .= ' <span class="oc-of__muted">' . esc_html__( 'offer', 'oc-theme' ) . '</span> ';

		foreach ( $offer as $name ) {
			$html .= '<span class="oc-of__chip">' . esc_html( $name ) . '</span>';
		}

		if ( $rule['same_brand'] ) {
			$html .= '<span class="oc-of__chip">' . esc_html( $offer ? __( 'same brand only', 'oc-theme' ) : __( 'more from the same brand', 'oc-theme' ) ) . '</span>';
		} elseif ( ! $offer ) {
			$html .= '<span class="oc-of__chip">' . esc_html__( 'nothing yet', 'oc-theme' ) . '</span>';
		}

		return $html;
	}

	/**
	 * A tier in words: "4 units, 10%".
	 *
	 * @param array{qty:int,pct:int,label:string} $tier The tier.
	 */
	public static function tier_text( array $tier ): string {
		/* translators: 1: quantity. 2: percent off. */
		return sprintf( __( '%1$d units, %2$d%%', 'oc-theme' ), $tier['qty'], $tier['pct'] );
	}

	/**
	 * Labels for a list of ids, by kind.
	 *
	 * @param string $kind c = category, b = brand, p = product.
	 * @param int[]  $ids  Ids.
	 * @return array<string,string> Token => label.
	 */
	public static function names( string $kind, array $ids ): array {
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
	public static function label( string $kind, int $id ): string {
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
	 * The editor, as a card among the rules.
	 *
	 * @param string              $uid    Rule name, or "new".
	 * @param array<string,mixed> $rule   Clean rule.
	 * @param array<string,array> $totals All totals.
	 */
	private function editor( string $uid, array $rule, array $totals ): void {
		$new     = 'new' === $uid;
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

		/* translators: %d: percent. */
		$more[] = $rule['pct'] > 0 ? sprintf( __( 'Discount %d%%', 'oc-theme' ), $rule['pct'] ) : __( 'No discount', 'oc-theme' );
		$more[] = $orders[ $rule['order'] ];

		if ( $rule['exclude'] ) {
			/* translators: %d: number of products. */
			$more[] = sprintf( _n( '%d product left out', '%d products left out', count( $rule['exclude'] ), 'oc-theme' ), count( $rule['exclude'] ) );
		}
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="oc-of__rule oc-of__editor" id="oc-of-editor" data-oc-of-on="<?php echo $rule['on'] ? '1' : '0'; ?>">
			<input type="hidden" name="action" value="oc_offers_rule" />
			<input type="hidden" name="rule" value="<?php echo esc_attr( $uid ); ?>" />
			<?php wp_nonce_field( 'oc_offers_rule' ); ?>

			<div class="oc-of__edhead">
				<label class="oc-of__edname">
					<span class="screen-reader-text"><?php esc_html_e( 'Rule name', 'oc-theme' ); ?></span>
					<input type="text" name="name" value="<?php echo esc_attr( $rule['name'] ); ?>" placeholder="<?php esc_attr_e( 'Name the rule, e.g. Bathroom: more from the same brand', 'oc-theme' ); ?>" />
				</label>
				<?php
				if ( ! $new ) {
					self::stats_html( $totals[ 'r:' . $uid ] ?? array() );
				}
				?>
				<label class="oc-of__edon">
					<input type="checkbox" name="on" value="1" <?php checked( 1, $rule['on'] ); ?> />
					<span class="oc-of__fakeswitch" aria-hidden="true"><span class="oc-of__knob"></span></span>
					<span><?php echo esc_html( _x( 'Active', 'rule status', 'oc-theme' ) ); ?></span>
				</label>
			</div>

			<div class="oc-of__edbody">
				<div class="oc-of__line">
					<span class="oc-of__lab"><?php esc_html_e( 'What the rule does', 'oc-theme' ); ?></span>
					<div class="oc-of__kinds" role="radiogroup">
						<label class="oc-of__kind<?php echo 'offer' === $rule['kind'] ? ' is-on' : ''; ?>"><input type="radio" name="kind" value="offer" <?php checked( 'offer', $rule['kind'] ); ?> data-oc-of-kind /> <span><strong><?php esc_html_e( 'Offers products', 'oc-theme' ); ?></strong><small><?php esc_html_e( 'one product beside what is in the cart', 'oc-theme' ); ?></small></span></label>
						<label class="oc-of__kind<?php echo 'tiers' === $rule['kind'] ? ' is-on' : ''; ?>"><input type="radio" name="kind" value="tiers" <?php checked( 'tiers', $rule['kind'] ); ?> data-oc-of-kind /> <span><strong><?php esc_html_e( 'Quantity tiers', 'oc-theme' ); ?></strong><small><?php esc_html_e( 'two for less, four for even less', 'oc-theme' ); ?></small></span></label>
					</div>
				</div>

				<div class="oc-of__line">
					<label for="oc-of-when" data-oc-of-when-label data-offer="<?php esc_attr_e( 'When the cart holds', 'oc-theme' ); ?>" data-tiers="<?php esc_attr_e( 'For products in', 'oc-theme' ); ?>"><?php echo esc_html( 'tiers' === $rule['kind'] ? __( 'For products in', 'oc-theme' ) : __( 'When the cart holds', 'oc-theme' ) ); ?></label>
					<div>
						<select id="oc-of-when" name="when[]" multiple="multiple" class="oc-of-pick" data-kinds="<?php echo $has_tax ? 'cbp' : 'cp'; ?>" data-placeholder="<?php echo esc_attr( $has_tax ? __( 'Type a category, a brand or a product', 'oc-theme' ) : __( 'Type a category or a product', 'oc-theme' ) ); ?>">
							<?php foreach ( $when as $token => $label ) : ?>
								<option value="<?php echo esc_attr( $token ); ?>" selected="selected"><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
						<span class="oc-of__muted"><?php esc_html_e( 'A category covers everything under it. Left empty, the rule covers every product.', 'oc-theme' ); ?></span>
					</div>
				</div>

				<div data-oc-of-only="offer"<?php echo 'offer' === $rule['kind'] ? '' : ' hidden'; ?>>
				<div class="oc-of__line">
					<label for="oc-of-offer"><?php esc_html_e( 'Offer them', 'oc-theme' ); ?></label>
					<div>
						<select id="oc-of-offer" name="offer[]" multiple="multiple" class="oc-of-pick" data-kinds="cp" data-placeholder="<?php esc_attr_e( 'Type a category or a product', 'oc-theme' ); ?>">
							<?php foreach ( $offer as $token => $label ) : ?>
								<option value="<?php echo esc_attr( $token ); ?>" selected="selected"><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
						<?php if ( $has_tax ) : ?>
							<label class="oc-of__check"><input type="checkbox" name="same_brand" value="1" <?php checked( 1, $rule['same_brand'] ); ?> /> <span><strong><?php esc_html_e( 'Only from the same brand', 'oc-theme' ); ?></strong> <?php esc_html_e( 'as the product the shopper has', 'oc-theme' ); ?></span></label>
						<?php endif; ?>
					</div>
				</div>

				<div class="oc-of__line">
					<span class="oc-of__lab"><?php esc_html_e( 'Where it shows', 'oc-theme' ); ?></span>
					<div class="oc-of__places">
						<span class="oc-of__place is-on"><?php esc_html_e( 'Cart panel', 'oc-theme' ); ?></span>
						<span class="oc-of__place is-on"><?php esc_html_e( 'Cart page', 'oc-theme' ); ?></span>
					</div>
				</div>

				</div>

				<div data-oc-of-only="tiers"<?php echo 'tiers' === $rule['kind'] ? '' : ' hidden'; ?>>
				<div class="oc-of__line">
					<span class="oc-of__lab"><?php esc_html_e( 'The tiers', 'oc-theme' ); ?></span>
					<div>
						<?php echo Tiers_Admin::table( 'rt', $rule['tiers'], (int) $rule['default'], 0.0 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built escaped. ?>
						<span class="oc-of__muted"><?php esc_html_e( 'The percent comes off each product\'s own price. A product with tiers of its own keeps them.', 'oc-theme' ); ?></span>
					</div>
				</div>
				</div>

				<details class="oc-of__more" data-oc-of-only="offer"<?php echo 'offer' === $rule['kind'] ? '' : ' hidden'; ?><?php echo $rule['pct'] > 0 || $rule['exclude'] ? ' open' : ''; ?>>
					<summary><?php esc_html_e( 'More options', 'oc-theme' ); ?> <span><?php echo esc_html( implode( ' · ', $more ) ); ?></span></summary>

					<div class="oc-of__line">
						<label for="oc-of-pct"><?php esc_html_e( 'Discount for adding the offer', 'oc-theme' ); ?></label>
						<div>
							<span class="oc-of__num"><input type="number" id="oc-of-pct" name="pct" value="<?php echo esc_attr( (string) $rule['pct'] ); ?>" min="0" max="<?php echo esc_attr( (string) Rules::MAX_PERCENT ); ?>" /><span>%</span></span>
							<span class="oc-of__muted"><?php esc_html_e( '0 = no discount. The discount holds while the product it was offered beside stays in the cart.', 'oc-theme' ); ?></span>
						</div>
					</div>

					<div class="oc-of__line">
						<label for="oc-of-order"><?php esc_html_e( 'Which product first', 'oc-theme' ); ?></label>
						<div>
							<select id="oc-of-order" name="order">
								<?php foreach ( $orders as $key => $label ) : ?>
									<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $key, $rule['order'] ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
							<span class="oc-of__muted"><?php esc_html_e( 'Products named one by one always come first, in the order they were typed.', 'oc-theme' ); ?></span>
						</div>
					</div>

					<div class="oc-of__line">
						<label for="oc-of-exclude"><?php esc_html_e( 'Never offer', 'oc-theme' ); ?></label>
						<div>
							<select id="oc-of-exclude" name="exclude[]" multiple="multiple" class="wc-product-search" data-placeholder="<?php esc_attr_e( 'Search for a product…', 'oc-theme' ); ?>" data-action="woocommerce_json_search_products">
								<?php foreach ( $rule['exclude'] as $id ) : ?>
									<?php if ( get_post( $id ) ) : ?>
										<option value="<?php echo absint( $id ); ?>" selected="selected"><?php echo esc_html( get_the_title( $id ) ); ?></option>
									<?php endif; ?>
								<?php endforeach; ?>
							</select>
						</div>
					</div>
				</details>
			</div>

			<?php echo Tiers_Admin::style() . Tiers_Admin::script(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static. ?>
			<div class="oc-of__edfoot">
				<button class="oc-of__btn oc-of__btn--primary"><?php esc_html_e( 'Save rule', 'oc-theme' ); ?></button>
				<a class="oc-of__btn" href="<?php echo esc_url( self::url() ); ?>"><?php esc_html_e( 'Cancel', 'oc-theme' ); ?></a>
				<a class="oc-of__btn oc-of__btn--quiet" href="#oc-of-check"><?php esc_html_e( 'Check on a product', 'oc-theme' ); ?></a>
				<?php if ( ! $new ) : ?>
					<a class="oc-of__del" href="<?php echo esc_url( self::act_url( 'delete', $uid ) ); ?>" data-oc-of-confirm><?php esc_html_e( 'Delete rule', 'oc-theme' ); ?></a>
				<?php endif; ?>
			</div>
		</form>
		<?php
	}

	/**
	 * The side panel: rules the orders suggest.
	 */
	private function suggestions(): void {
		$list = Suggest::listing( 4 );
		?>
		<div class="oc-of__card oc-of__sugg">
			<div class="oc-of__suggh">
				<svg aria-hidden="true" width="20" height="20" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"><path d="M10 2l1.9 5.1L17 9l-5.1 1.9L10 16l-1.9-5.1L3 9l5.1-1.9z"></path></svg>
				<h2><?php esc_html_e( 'Rules worth adding', 'oc-theme' ); ?></h2>
			</div>
			<p class="oc-of__muted"><?php esc_html_e( 'Read off the shop\'s paid orders. Nothing is added without your approval.', 'oc-theme' ); ?></p>

			<?php if ( ! $list && ! Suggest::tier_listing( 1 ) ) : ?>
				<p class="oc-of__suggempty"><?php esc_html_e( 'Nothing new right now. Suggestions appear once two products keep landing in the same order and are not yet offered beside each other.', 'oc-theme' ); ?></p>
			<?php endif; ?>

			<?php
			foreach ( $list as $pair ) :
				/* translators: 1: product bought first. 2: product to offer beside it. */
				$title = sprintf( __( '%1$s: offer %2$s', 'oc-theme' ), get_the_title( $pair['a'] ), get_the_title( $pair['b'] ) );
				/* translators: %d: number of orders. */
				$why = sprintf( _n( 'Bought together in %d paid order, and not offered beside each other today.', 'Bought together in %d paid orders, and not offered beside each other today.', $pair['n'], 'oc-theme' ), $pair['n'] );
				$ab  = array(
					'a' => $pair['a'],
					'b' => $pair['b'],
				);
				?>
				<div class="oc-of__suggitem">
					<strong><?php echo esc_html( $title ); ?></strong>
					<span><?php echo esc_html( $why ); ?></span>
					<div class="oc-of__suggacts">
						<a class="oc-of__btn oc-of__btn--primary" href="<?php echo esc_url( self::act_url( 'accept', '', $ab ) ); ?>"><?php esc_html_e( 'Approve', 'oc-theme' ); ?></a>
						<a class="oc-of__btn" href="<?php echo esc_url( self::act_url( 'skip', '', $ab ) ); ?>"><?php esc_html_e( 'Skip', 'oc-theme' ); ?></a>
					</div>
				</div>
			<?php endforeach; ?>

			<?php
			foreach ( Suggest::tier_listing( 3 ) as $item ) :
				$qtys = array_column( $item['tiers'], 'qty' );
				/* translators: 1: category name. 2: the quantities, e.g. "4 and 8". */
				$title = sprintf( __( '%1$s: tiers of %2$s', 'oc-theme' ), $item['name'], implode( ', ', array_map( 'strval', $qtys ) ) );
				/* translators: 1: number of orders. 2: category name. */
				$why = sprintf( _n( '%1$d paid order took more than one unit from %2$s; these are the quantities it took.', '%1$d paid orders took more than one unit from %2$s; these are the quantities they took.', $item['n'], 'oc-theme' ), $item['n'], $item['name'] );
				?>
				<div class="oc-of__suggitem">
					<strong><?php echo esc_html( $title ); ?></strong>
					<span><?php echo esc_html( $why ); ?></span>
					<div class="oc-of__suggacts">
						<a class="oc-of__btn oc-of__btn--primary" href="<?php echo esc_url( self::act_url( 'accept_tiers', '', array( 'a' => $item['cat'] ) ) ); ?>"><?php esc_html_e( 'Approve', 'oc-theme' ); ?></a>
						<a class="oc-of__btn" href="<?php echo esc_url( self::act_url( 'skip_tiers', '', array( 'a' => $item['cat'] ) ) ); ?>"><?php esc_html_e( 'Skip', 'oc-theme' ); ?></a>
					</div>
				</div>
			<?php endforeach; ?>

			<a class="oc-of__btn oc-of__btn--dashed" href="<?php echo esc_url( self::act_url( 'rescan' ) ); ?>"><?php esc_html_e( 'Look for more suggestions', 'oc-theme' ); ?></a>
		</div>
		<?php
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

		echo '<div class="oc-of__checkout">';

		if ( ! $rows ) {
			echo '<p><strong>' . esc_html__( 'Nothing would be offered beside this product.', 'oc-theme' ) . '</strong> ' . esc_html__( 'Only products that can be added with one tap are offered: in stock, visible and with nothing to choose.', 'oc-theme' ) . '</p></div>';

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
				echo ' <span class="oc-of__muted">' . esc_html__( '(shown first)', 'oc-theme' ) . '</span>';
			}

			echo '</li>';
		}

		echo '</ol>';

		if ( ! $s['on'] ) {
			echo '<p class="oc-of__muted">' . esc_html__( 'Offers are switched off, so shoppers do not see this yet.', 'oc-theme' ) . '</p>';
		}

		echo '</div>';
	}

	/* ------------------------------------------------------------ actions */

	/**
	 * Save the shop-wide discount.
	 */
	public function save_settings(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die();
		}

		check_admin_referer( 'oc_offers_save' );

		$s        = Offers::settings();
		$s['pct'] = isset( $_POST['pct'] ) ? (int) $_POST['pct'] : 0;

		Offers::save( $s );
		Stats::install();

		wp_safe_redirect( self::url( array( 'oc_done' => 'saved' ) ) );
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
		$tiers = Tiers_Admin::posted( 'rt' );

		$s['rules'][ $uid ] = Rules::clean(
			array(
				'name'       => isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['name'] ) ) : '',
				'on'         => empty( $_POST['on'] ) ? 0 : 1,
				'kind'       => isset( $_POST['kind'] ) ? sanitize_key( (string) wp_unslash( $_POST['kind'] ) ) : 'offer',
				'tiers'      => $tiers['tiers'],
				'default'    => $tiers['default'],
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
		Stats::install();

		wp_safe_redirect( self::url( array( 'oc_done' => 'rule' ) ) );
		exit;
	}

	/**
	 * One click: a switch, a rule's place, a deletion, a suggestion.
	 */
	public function act(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die();
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- the nonce is bound to the rule's name, so the name is read first and verified on the next line.
		$uid  = isset( $_GET['rule'] ) ? sanitize_key( (string) wp_unslash( $_GET['rule'] ) ) : '';
		$what = isset( $_GET['what'] ) ? sanitize_key( (string) wp_unslash( $_GET['what'] ) ) : '';
		$a    = isset( $_GET['a'] ) ? absint( $_GET['a'] ) : 0;
		$b    = isset( $_GET['b'] ) ? absint( $_GET['b'] ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		check_admin_referer( 'oc_offers_act_' . $uid );

		$s    = Offers::settings();
		$done = 'saved';

		if ( 'master' === $what ) {
			$s['on'] = $s['on'] ? 0 : 1;
		} elseif ( 'auto' === $what ) {
			$s['auto'] = $s['auto'] ? 0 : 1;
		} elseif ( 'rescan' === $what ) {
			Suggest::rescan();
		} elseif ( 'skip' === $what && $a && $b ) {
			Suggest::dismiss( $a, $b );
		} elseif ( 'accept_tiers' === $what && $a ) {
			$term = get_term( $a, 'product_cat' );

			if ( $term instanceof \WP_Term ) {
				$sugg = Suggest::tiers_for_category( $a );

				$s['rules'][ Tabs::mint() ] = Rules::clean(
					array(
						/* translators: %s: category name. */
						'name'  => sprintf( __( '%s: quantity tiers', 'oc-theme' ), $term->name ),
						'on'    => 1,
						'kind'  => 'tiers',
						'when'  => array( 'cats' => array( $a ) ),
						'tiers' => $sugg,
					)
				);
				Suggest::dismiss_tiers( $a );
				$done = 'accepted';
			}
		} elseif ( 'skip_tiers' === $what && $a ) {
			Suggest::dismiss_tiers( $a );
		} elseif ( 'accept' === $what && $a && $b && get_post( $a ) && get_post( $b ) ) {
			$s['rules'][ Tabs::mint() ] = Rules::clean(
				array(
					/* translators: 1: product bought first. 2: product to offer beside it. */
					'name'  => sprintf( __( '%1$s: offer %2$s', 'oc-theme' ), get_the_title( $a ), get_the_title( $b ) ),
					'on'    => 1,
					'when'  => array( 'products' => array( $a ) ),
					'offer' => array( 'products' => array( $b ) ),
					'pct'   => $s['pct'],
					'where' => array( 'cart' ),
				)
			);
			Suggest::dismiss( $a, $b );
			$done = 'accepted';
		} elseif ( isset( $s['rules'][ $uid ] ) ) {
			if ( 'toggle' === $what ) {
				$s['rules'][ $uid ]['on'] = $s['rules'][ $uid ]['on'] ? 0 : 1;
			} elseif ( 'delete' === $what ) {
				unset( $s['rules'][ $uid ] );
				$done = 'deleted';
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
		}

		Offers::save( $s );
		Stats::install();

		wp_safe_redirect( self::url( array( 'oc_done' => $done ) ) );
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

		$taxes = array();

		if ( false !== strpos( $kinds, 'c' ) ) {
			$taxes['c'] = 'product_cat';
		}

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
