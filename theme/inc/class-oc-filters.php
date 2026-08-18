<?php
/**
 * Catalogue filtering: attributes, categories-as-facets, brands, price and
 * stock — instant at any catalogue size.
 *
 * Speed model: full page loads filter the MAIN query through indexed joins
 * (wc_product_meta_lookup for price/stock, term_relationships for terms), so
 * pagination, ordering and SEO stay native. In-page filter changes go through
 * a slim ajax endpoint that renders only the cards and the recounted facets.
 *
 * Crawl model: every filter control is a <button> — no hrefs for bots to
 * harvest; the state URL exists only through history.replaceState. A load
 * that does arrive with filter params answers noindex,follow plus a
 * canonical of the clean category.
 *
 * @package OC_Theme
 */

declare( strict_types = 1 );

namespace OC\Theme;

defined( 'ABSPATH' ) || exit;

/**
 * The filtering engine + its admin screen.
 */
final class Filters {

	/**
	 * Parsed request state, built once.
	 *
	 * @var array<string,mixed>|null
	 */
	private $state = null;

	/**
	 * Hook in.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ), 61 );
		add_action( 'admin_post_oc_filters_save', array( $this, 'save_settings' ) );

		add_action( 'product_cat_edit_form_fields', array( $this, 'category_field' ) );
		add_action( 'edited_product_cat', array( $this, 'save_category_field' ) );

		add_action( 'pre_get_posts', array( $this, 'apply_to_query' ) );
		add_action( 'pre_get_posts', array( $this, 'smart_main_query' ), 11 );
		add_filter( 'posts_clauses', array( $this, 'price_stock_clauses' ), 15, 2 );
		add_filter( 'posts_clauses', array( $this, 'smart_clauses' ), 16, 2 );

		add_action( 'woocommerce_before_shop_loop', array( $this, 'render' ), 5 );
		add_action( 'woocommerce_after_shop_loop', array( $this, 'close_wrap' ), 90 );
		add_filter( 'body_class', array( $this, 'body_class' ) );

		add_filter( 'wp_robots', array( $this, 'robots' ) );
		add_action( 'wp_head', array( $this, 'canonical' ), 4 );

		add_action( 'wp_ajax_oc_filter', array( $this, 'ajax' ) );
		add_action( 'wp_ajax_nopriv_oc_filter', array( $this, 'ajax' ) );
		add_action( 'template_redirect', array( $this, 'rescue_paged_404' ) );
		add_filter( 'woocommerce_catalog_orderby', array( $this, 'orderby_labels' ) );
	}

	/* ---------------------------------------------------------------- setup */

	/**
	 * Settings with defaults. Managed from the admin screen — a shop manager
	 * never needs the customizer for this.
	 *
	 * @return array<string,mixed>
	 */
	public static function settings(): array {
		$saved = get_option( 'oc_filters' );

		return wp_parse_args(
			is_array( $saved ) ? $saved : array(),
			array(
				'enabled'      => 0,
				'layout'       => 'sidebar',   // sidebar | topbar | drawer.
				'topbar_style' => 'drop',      // drop | full.
				'choice'       => 'check',     // check | dot.
				'counts'       => 1,
				'empty'        => 'gray',      // gray | hide.
				'instock'      => 1,
				'brands'       => 0,
				'brands_title' => '',
				'price_mode'   => 'range',     // range | tiers | off.
				'price_ui'     => 'slider',    // slider | inputs.
				'price_tiers'  => '',
				'groups'       => array(),     // ordered rows, see admin.
			)
		);
	}

	/**
	 * The brand taxonomy present on this site, if any.
	 */
	private function brand_tax(): string {
		foreach ( array( 'product_brand', 'pwb-brand' ) as $tax ) {
			if ( taxonomy_exists( $tax ) ) {
				return $tax;
			}
		}

		return '';
	}

	/**
	 * Effective layout for the current archive: per-category term meta first,
	 * the global default otherwise. 'none' disables filtering here.
	 */
	private function layout(): string {
		$settings = self::settings();

		if ( empty( $settings['enabled'] ) ) {
			return 'none';
		}

		$layout = (string) $settings['layout'];

		if ( is_product_taxonomy() ) {
			$term = get_queried_object();
			if ( $term instanceof \WP_Term ) {
				$own = (string) get_term_meta( $term->term_id, 'oc_filter_layout', true );
				if ( '' !== $own ) {
					$layout = $own;
				}
			}
		}

		return in_array( $layout, array( 'sidebar', 'topbar', 'drawer', 'none' ), true ) ? $layout : 'sidebar';
	}

	/* ------------------------------------------------------------- request */

	/**
	 * Filter state from the request, parsed once. Params are short and
	 * numeric where possible: fa_{attribute id}=slug,slug · fc_{group}=ids ·
	 * fb=ids · fmin/fmax · fin=1.
	 *
	 * @return array{attrs:array<int,array<int,string>>,cats:array<int,array<int,int>>,brands:array<int,int>,min:float|null,max:float|null,instock:bool,any:bool}
	 */
	private function state(): array {
		if ( null !== $this->state ) {
			return $this->state;
		}

		$attrs  = array();
		$cats   = array();
		$brands = array();

		foreach ( $_GET as $key => $value ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public read-only filters.
			// No sanitize_text_field here: it strips percent-encoded octets,
			// and Hebrew term slugs ARE percent-encoded. Each param type gets
			// its own strict whitelist instead.
			$value = wp_unslash( (string) $value );

			if ( preg_match( '/^fa_(\d+)$/', (string) $key, $m ) && '' !== $value ) {
				$attrs[ (int) $m[1] ] = array_filter(
					array_map(
						static function ( string $slug ): string {
							return (string) preg_replace( '/[^a-z0-9%_.\-]/i', '', $slug );
						},
						explode( ',', $value )
					)
				);
			} elseif ( preg_match( '/^fc_(\d+)$/', (string) $key, $m ) && '' !== $value ) {
				$cats[ (int) $m[1] ] = array_filter( array_map( 'absint', explode( ',', $value ) ) );
			} elseif ( 'fb' === $key && '' !== $value ) {
				$brands = array_filter( array_map( 'absint', explode( ',', $value ) ) );
			}
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$min     = isset( $_GET['fmin'] ) && '' !== $_GET['fmin'] ? (float) $_GET['fmin'] : null;
		$max     = isset( $_GET['fmax'] ) && '' !== $_GET['fmax'] ? (float) $_GET['fmax'] : null;
		$instock = ! empty( $_GET['fin'] );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$this->state = array(
			'attrs'   => $attrs,
			'cats'    => $cats,
			'brands'  => $brands,
			'min'     => $min,
			'max'     => $max,
			'instock' => $instock,
			'any'     => ! empty( $attrs ) || ! empty( $cats ) || ! empty( $brands ) || null !== $min || null !== $max || $instock,
		);

		return $this->state;
	}

	/**
	 * Set while the ajax endpoint renders cards for a smart sale category —
	 * template code (colour siblings) cannot see is_tax() there.
	 *
	 * @var bool
	 */
	public static $sale_render = false;

	/**
	 * Is the current render a smart "on sale" category?
	 */
	public static function sale_context(): bool {
		if ( self::$sale_render ) {
			return true;
		}

		if ( ! is_tax( 'product_cat' ) ) {
			return false;
		}

		$term = get_queried_object();

		return $term instanceof \WP_Term && 'sale' === (string) get_term_meta( $term->term_id, 'oc_smart', true );
	}

	/**
	 * Is this product "on promotion" — a WooCommerce sale price or a live
	 * Promotion King promotion targeting it (the engine's own rules:
	 * all / chosen products / chosen categories with ancestors, minus
	 * exclusions; channel and customer type respected)?
	 *
	 * @param \WC_Product $product The product.
	 */
	public static function product_promoted( \WC_Product $product ): bool {
		if ( $product->is_on_sale() ) {
			return true;
		}

		if ( ! class_exists( '\PromoEngine\Repository' ) ) {
			return false;
		}

		static $promos = null;

		if ( null === $promos ) {
			$promos    = array();
			$logged_in = is_user_logged_in();

			foreach ( \PromoEngine\Repository::active() as $promotion ) {
				if ( ! $promotion->is_live() ) {
					continue;
				}
				if ( class_exists( '\PromoEngine\App' ) && method_exists( '\PromoEngine\App', 'promotion_runs_here' ) && ! \PromoEngine\App::promotion_runs_here( $promotion ) ) {
					continue;
				}
				if ( method_exists( $promotion, 'customer_type_allowed' ) && ! $promotion->customer_type_allowed( $logged_in ) ) {
					continue;
				}
				if ( 'cart' === (string) $promotion->get( 'applies_to', 'all' ) ) {
					continue;
				}
				$promos[] = $promotion;
			}
		}

		if ( empty( $promos ) ) {
			return false;
		}

		$product_id = (int) $product->get_id();
		$cats       = wc_get_product_term_ids( $product_id, 'product_cat' );
		foreach ( $cats as $cat_id ) {
			$cats = array_merge( $cats, get_ancestors( (int) $cat_id, 'product_cat' ) );
		}
		$cats = array_map( 'intval', array_unique( $cats ) );

		foreach ( $promos as $promotion ) {
			$excluded = array_map( 'intval', (array) $promotion->get( 'excluded_product_ids', array() ) );
			if ( in_array( $product_id, $excluded, true ) ) {
				continue;
			}

			$applies = (string) $promotion->get( 'applies_to', 'all' );

			if ( 'all' === $applies ) {
				return true;
			}
			if ( 'products' === $applies && in_array( $product_id, array_map( 'intval', (array) $promotion->get( 'product_ids', array() ) ), true ) ) {
				return true;
			}
			if ( 'categories' === $applies && array_intersect( array_map( 'intval', (array) $promotion->get( 'category_ids', array() ) ), $cats ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Products targeted by a live Promotion King promotion, as SQL — one
	 * OR'ed condition per promotion, mirroring the engine's own targeting
	 * (all / chosen products / chosen categories incl. descendants, minus
	 * per-promotion exclusions; channel and customer-type respected).
	 * Empty string when the engine is absent or nothing is live.
	 */
	private function engine_sale_sql(): string {
		if ( ! class_exists( '\PromoEngine\Repository' ) ) {
			return '';
		}

		global $wpdb;

		$conds = array();

		foreach ( \PromoEngine\Repository::active() as $promotion ) {
			if ( ! $promotion->is_live() ) {
				continue;
			}
			if ( class_exists( '\PromoEngine\App' ) && method_exists( '\PromoEngine\App', 'promotion_runs_here' ) && ! \PromoEngine\App::promotion_runs_here( $promotion ) ) {
				continue;
			}
			if ( method_exists( $promotion, 'customer_type_allowed' ) && ! $promotion->customer_type_allowed( is_user_logged_in() ) ) {
				continue;
			}

			$applies = (string) $promotion->get( 'applies_to', 'all' );

			if ( 'cart' === $applies ) {
				continue;
			}

			$excluded = array_filter( array_map( 'absint', (array) $promotion->get( 'excluded_product_ids', array() ) ) );
			$not      = $excluded ? " AND {$wpdb->posts}.ID NOT IN ( " . implode( ',', $excluded ) . ' )' : '';

			if ( 'all' === $applies ) {
				$conds[] = '( 1=1' . $not . ' )';
			} elseif ( 'products' === $applies ) {
				$ids = array_filter( array_map( 'absint', (array) $promotion->get( 'product_ids', array() ) ) );
				if ( $ids ) {
					$conds[] = "( {$wpdb->posts}.ID IN ( " . implode( ',', $ids ) . ' )' . $not . ' )';
				}
			} elseif ( 'categories' === $applies ) {
				$cat_ids = array_filter( array_map( 'absint', (array) $promotion->get( 'category_ids', array() ) ) );
				$family  = array();
				foreach ( $cat_ids as $cat_id ) {
					$family = array_merge( $family, array( $cat_id ), array_map( 'intval', get_term_children( $cat_id, 'product_cat' ) ) );
				}
				$family = array_unique( array_filter( $family ) );

				if ( $family ) {
					$tt_ids = get_terms(
						array(
							'taxonomy'   => 'product_cat',
							'include'    => $family,
							'fields'     => 'tt_ids',
							'hide_empty' => false,
						)
					);

					if ( is_array( $tt_ids ) && $tt_ids ) {
						$in      = implode( ',', array_map( 'absint', $tt_ids ) );
						$conds[] = "( EXISTS ( SELECT 1 FROM {$wpdb->term_relationships} oc_pk_tr WHERE oc_pk_tr.object_id = {$wpdb->posts}.ID AND oc_pk_tr.term_taxonomy_id IN ( $in ) )" . $not . ' )';
					}
				}
			}
		}

		return $conds ? '( ' . implode( ' OR ', $conds ) . ' )' : '';
	}

	/**
	 * Does a group limited to $show belong on the $category archive?
	 * An empty list means everywhere (the shop page included); a set list
	 * matches the categories themselves and their descendants.
	 *
	 * @param array<int,int> $show     Chosen category ids.
	 * @param int            $category Current archive category (0 = shop).
	 */
	private function group_applies( array $show, int $category ): bool {
		if ( empty( $show ) ) {
			return true;
		}

		if ( ! $category ) {
			return false;
		}

		if ( in_array( $category, $show, true ) ) {
			return true;
		}

		foreach ( $show as $chosen ) {
			if ( in_array( $category, array_map( 'intval', get_term_children( (int) $chosen, 'product_cat' ) ), true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * A state with nothing active — the base for zeroed-group enumeration.
	 *
	 * @return array<string,mixed>
	 */
	private function empty_state(): array {
		return array(
			'attrs'   => array(),
			'cats'    => array(),
			'brands'  => array(),
			'min'     => null,
			'max'     => null,
			'instock' => false,
			'any'     => false,
		);
	}

	/**
	 * Attribute taxonomy name from its numeric id.
	 */
	private function attr_taxonomy( int $attribute_id ): string {
		foreach ( wc_get_attribute_taxonomies() as $attribute ) {
			if ( (int) $attribute->attribute_id === $attribute_id ) {
				return 'pa_' . $attribute->attribute_name;
			}
		}

		return '';
	}

	/**
	 * tax_query for a given state, optionally skipping one group (facet
	 * recounts exclude their own group so multi-select stays possible).
	 *
	 * @param array<string,mixed> $state Parsed state.
	 * @param string              $skip  Group key to leave out ('a{id}', 'c{i}', 'b').
	 * @return array<int,array<string,mixed>>
	 */
	private function tax_query( array $state, string $skip = '' ): array {
		$tax_query = array();

		foreach ( $state['attrs'] as $attribute_id => $slugs ) {
			if ( 'a' . $attribute_id === $skip ) {
				continue;
			}
			$taxonomy = $this->attr_taxonomy( (int) $attribute_id );
			if ( '' === $taxonomy || empty( $slugs ) ) {
				continue;
			}
			$tax_query[] = array(
				'taxonomy' => $taxonomy,
				'field'    => 'slug',
				'terms'    => $slugs,
			);
		}

		$settings = self::settings();

		foreach ( $state['cats'] as $group_index => $term_ids ) {
			if ( 'c' . $group_index === $skip || empty( $term_ids ) ) {
				continue;
			}
			$tax_query[] = array(
				'taxonomy'         => 'product_cat',
				'field'            => 'term_id',
				'terms'            => $term_ids,
				'include_children' => true,
			);
		}

		if ( 'b' !== $skip && ! empty( $state['brands'] ) && '' !== $this->brand_tax() ) {
			$tax_query[] = array(
				'taxonomy' => $this->brand_tax(),
				'field'    => 'term_id',
				'terms'    => $state['brands'],
			);
		}

		return $tax_query;
	}

	/* ----------------------------------------------------- smart categories */

	/**
	 * A smart category holds no products of its own — membership is a live,
	 * indexed condition: on sale, up to a price, or new. Zero assignment,
	 * zero syncing, always current.
	 *
	 * @param int $term_id Category id.
	 * @return array{mode:string,price:float,cats:array<int,int>}
	 */
	private function smart_meta( int $term_id ): array {
		$mode = (string) get_term_meta( $term_id, 'oc_smart', true );

		return array(
			'mode'  => in_array( $mode, array( 'sale', 'price', 'new' ), true ) ? $mode : '',
			'price' => (float) get_term_meta( $term_id, 'oc_smart_price', true ),
			'cats'  => array_filter( array_map( 'absint', (array) get_term_meta( $term_id, 'oc_smart_cats', true ) ) ),
		);
	}

	/**
	 * The smart category's archive: drop the (empty) term restriction and
	 * flag the query — the clauses below take it from there.
	 *
	 * @param \WP_Query $query Running query.
	 */
	public function smart_main_query( $query ): void {
		if ( is_admin() || ! $query->is_main_query() || 'product_query' !== $query->get( 'wc_query' ) || ! $query->is_tax( 'product_cat' ) ) {
			return;
		}

		$term = get_queried_object();

		if ( ! $term instanceof \WP_Term || '' === $this->smart_meta( (int) $term->term_id )['mode'] ) {
			return;
		}

		$query->set( 'oc_smart_cat', (int) $term->term_id );
		$query->set( 'product_cat', '' );
		$query->query_vars['term']     = '';
		$query->query_vars['taxonomy'] = '';
	}

	/**
	 * The condition itself, on indexed columns only.
	 *
	 * @param array<string,string> $clauses SQL clauses.
	 * @param \WP_Query            $query   Running query.
	 * @return array<string,string>
	 */
	public function smart_clauses( array $clauses, $query ): array {
		$term_id = (int) $query->get( 'oc_smart_cat' );

		if ( ! $term_id ) {
			return $clauses;
		}

		$meta = $this->smart_meta( $term_id );

		if ( '' === $meta['mode'] ) {
			return $clauses;
		}

		global $wpdb;

		if ( in_array( $meta['mode'], array( 'sale', 'price' ), true ) ) {
			if ( false === strpos( (string) $clauses['join'], 'oc_smart_lookup' ) ) {
				$clauses['join'] .= " INNER JOIN {$wpdb->wc_product_meta_lookup} oc_smart_lookup ON {$wpdb->posts}.ID = oc_smart_lookup.product_id ";
			}

			if ( 'sale' === $meta['mode'] ) {
				// WooCommerce sale prices OR a live Promotion King promotion.
				$engine            = $this->engine_sale_sql();
				$clauses['where'] .= '' !== $engine
					? " AND ( oc_smart_lookup.onsale = 1 OR $engine )"
					: ' AND oc_smart_lookup.onsale = 1';
			} elseif ( $meta['price'] > 0 ) {
				$clauses['where'] .= $wpdb->prepare( ' AND oc_smart_lookup.min_price <= %f AND oc_smart_lookup.min_price > 0', $meta['price'] );
			}
		} elseif ( 'new' === $meta['mode'] ) {
			// The same definition the "New" label uses.
			$days              = max( 1, absint( get_theme_mod( 'oc_label_new_days', 30 ) ) );
			$clauses['where'] .= $wpdb->prepare( " AND {$wpdb->posts}.post_date_gmt >= DATE_SUB( UTC_TIMESTAMP(), INTERVAL %d DAY )", $days );
		}

		// Optional source restriction (a gifts category fed from chosen
		// departments only).
		if ( ! empty( $meta['cats'] ) ) {
			$family = array();
			foreach ( $meta['cats'] as $parent_id ) {
				$family = array_merge( $family, array( $parent_id ), array_map( 'intval', get_term_children( $parent_id, 'product_cat' ) ) );
			}
			$family = array_unique( array_filter( $family ) );

			if ( $family ) {
				$tt_ids = get_terms(
					array(
						'taxonomy'   => 'product_cat',
						'include'    => $family,
						'fields'     => 'tt_ids',
						'hide_empty' => false,
					)
				);

				if ( is_array( $tt_ids ) && $tt_ids ) {
					$placeholders      = implode( ',', array_map( 'absint', $tt_ids ) );
					$clauses['where'] .= " AND EXISTS ( SELECT 1 FROM {$wpdb->term_relationships} oc_smart_tr WHERE oc_smart_tr.object_id = {$wpdb->posts}.ID AND oc_smart_tr.term_taxonomy_id IN ( $placeholders ) )";
				}
			}
		}

		return $clauses;
	}

	/* ------------------------------------------------------ main query path */

	/**
	 * Full page loads with filter params: the MAIN archive query narrows
	 * through the same indexed paths the ajax endpoint uses.
	 *
	 * @param \WP_Query $query Running query.
	 */
	public function apply_to_query( $query ): void {
		if ( is_admin() || ! $query->is_main_query() || 'product_query' !== $query->get( 'wc_query' ) ) {
			return;
		}

		if ( 'none' === $this->layout() ) {
			return;
		}

		$state = $this->state();

		if ( ! $state['any'] ) {
			return;
		}

		$tax_query = $this->tax_query( $state );

		if ( $tax_query ) {
			$existing = (array) $query->get( 'tax_query' );
			$query->set( 'tax_query', array_merge( $existing, $tax_query ) );
		}

		// Price and stock ride posts_clauses — flag the query for it.
		$query->set( 'oc_filtering', true );
	}

	/**
	 * Price and stock conditions through wc_product_meta_lookup — one
	 * indexed join instead of slow meta queries.
	 *
	 * @param array<string,string> $clauses SQL clauses.
	 * @param \WP_Query            $query   Running query.
	 * @return array<string,string>
	 */
	public function price_stock_clauses( array $clauses, $query ): array {
		if ( ! $query->get( 'oc_filtering' ) ) {
			return $clauses;
		}

		$state = $this->state();

		if ( null === $state['min'] && null === $state['max'] && ! $state['instock'] ) {
			return $clauses;
		}

		global $wpdb;

		if ( false === strpos( (string) $clauses['join'], 'oc_flt_lookup' ) ) {
			$clauses['join'] .= " INNER JOIN {$wpdb->wc_product_meta_lookup} oc_flt_lookup ON {$wpdb->posts}.ID = oc_flt_lookup.product_id ";
		}

		if ( null !== $state['min'] ) {
			$clauses['where'] .= $wpdb->prepare( ' AND oc_flt_lookup.max_price >= %f', $state['min'] );
		}
		if ( null !== $state['max'] ) {
			$clauses['where'] .= $wpdb->prepare( ' AND oc_flt_lookup.min_price <= %f', $state['max'] );
		}
		if ( $state['instock'] ) {
			$clauses['where'] .= " AND oc_flt_lookup.stock_status IN ( 'instock', 'onbackorder' )";
		}

		return $clauses;
	}

	/**
	 * A filtered result set is smaller than the unfiltered one — a stale
	 * /page/N/ URL carrying filter params can overflow into a 404. Send it
	 * to page one of the same filtered view instead.
	 */
	public function rescue_paged_404(): void {
		if ( ! is_404() || ! $this->state()['any'] ) {
			return;
		}

		$request = (string) ( $_SERVER['REQUEST_URI'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- path-only use, re-escaped below.

		if ( ! preg_match( '#/page/\d+/?#', $request ) ) {
			return;
		}

		$target = preg_replace( '#/page/\d+/?#', '/', $request );

		wp_safe_redirect( esc_url_raw( home_url( $target ) ), 302 );
		exit;
	}

	/* ----------------------------------------------------------------- SEO */

	/**
	 * Filtered views are for people; bots get noindex,follow.
	 *
	 * @param array<string,bool> $robots Directives.
	 * @return array<string,bool>
	 */
	public function robots( array $robots ): array {
		if ( ( is_shop() || is_product_taxonomy() ) && $this->state()['any'] ) {
			$robots['noindex'] = true;
			$robots['follow']  = true;
		}

		return $robots;
	}

	/**
	 * A filtered load points its canonical at the clean category.
	 */
	public function canonical(): void {
		if ( ( is_shop() || is_product_taxonomy() ) && $this->state()['any'] ) {
			$url = is_shop() ? wc_get_page_permalink( 'shop' ) : get_term_link( get_queried_object() );
			if ( is_string( $url ) ) {
				echo '<link rel="canonical" href="' . esc_url( $url ) . '" />' . "\n";
			}
		}
	}

	/* -------------------------------------------------------------- facets */

	/**
	 * The facet groups for the current archive: definition + values with
	 * live counts under the given state. Each group recounts with every
	 * OTHER group applied, so multi-select within a group stays open.
	 *
	 * @param int                 $category Current category id (0 = shop).
	 * @param array<string,mixed> $state    Parsed state.
	 * @return array<int,array<string,mixed>>
	 */
	private function facets( int $category, array $state ): array {
		$settings = self::settings();
		$groups   = array();

		// Stock toggle first — it is not a counted group.
		if ( ! empty( $settings['instock'] ) ) {
			$groups[] = array(
				'key'    => 'in',
				'type'   => 'instock',
				'title'  => __( 'In stock only', 'oc-theme' ),
				'active' => $state['instock'],
			);
		}

		foreach ( (array) $settings['groups'] as $index => $row ) {
			if ( empty( $row['on'] ) ) {
				continue;
			}

			if ( 'attribute' === $row['type'] ) {
				$attribute_id = (int) $row['id'];
				$taxonomy     = $this->attr_taxonomy( $attribute_id );
				if ( '' === $taxonomy ) {
					continue;
				}

				$counts = $this->term_counts( $category, $state, 'a' . $attribute_id, $taxonomy );
				if ( empty( $counts ) ) {
					if ( ! $state['any'] ) {
						continue;
					}

					// Other filters left this group with nothing: its terms
					// still render, greyed to zero, instead of vanishing.
					$all = $this->term_counts( $category, $this->empty_state(), '', $taxonomy );
					if ( empty( $all ) ) {
						continue;
					}
					$counts = array_fill_keys( array_keys( $all ), 0 );
				}

				$display = ( $row['display'] ?? 'auto' );
				$type    = $this->attr_display_type( $taxonomy, (string) $display );
				$values  = array();

				foreach ( $counts as $term_id => $count ) {
					$term = get_term( (int) $term_id );
					if ( ! $term instanceof \WP_Term ) {
						continue;
					}
					$values[] = array(
						'v'      => $term->slug,
						'label'  => $term->name,
						'count'  => (int) $count,
						'active' => in_array( $term->slug, $state['attrs'][ $attribute_id ] ?? array(), true ),
						'style'  => 'swatch' === $type ? $this->term_swatch_style( $term->term_id ) : '',
					);
				}

				usort(
					$values,
					static function ( array $a, array $b ): int {
						return strnatcasecmp( $a['label'], $b['label'] );
					}
				);

				$groups[] = array(
					'key'    => 'fa_' . $attribute_id,
					'type'   => $type,
					'title'  => '' !== (string) ( $row['title'] ?? '' ) ? (string) $row['title'] : wc_attribute_label( $taxonomy ),
					'open'   => ! empty( $row['open'] ),
					'values' => $values,
				);
			} elseif ( 'category' === $row['type'] ) {
				$parents = array_filter( array_map( 'absint', (array) ( $row['cats'] ?? array() ) ) );
				if ( empty( $parents ) ) {
					continue;
				}

				// The group may be limited to chosen categories; empty = everywhere.
				$show = array_filter( array_map( 'absint', (array) ( $row['show'] ?? array() ) ) );
				if ( ! $this->group_applies( $show, $category ) ) {
					continue;
				}

				$counts = $this->category_counts( $category, $state, 'c' . $index, $parents );
				$values = array();

				foreach ( $parents as $parent_id ) {
					$term = get_term( $parent_id, 'product_cat' );
					if ( ! $term instanceof \WP_Term ) {
						continue;
					}
					$count = (int) ( $counts[ $parent_id ] ?? 0 );
					// A department with nothing here does not appear at all —
					// never greyed.
					if ( 0 === $count && ! in_array( $parent_id, $state['cats'][ $index ] ?? array(), true ) ) {
						continue;
					}
					$values[] = array(
						'v'      => (string) $parent_id,
						'label'  => $term->name,
						'count'  => $count,
						'active' => in_array( $parent_id, $state['cats'][ $index ] ?? array(), true ),
						'style'  => '',
					);
				}

				if ( empty( $values ) ) {
					continue;
				}

				$groups[] = array(
					'key'    => 'fc_' . $index,
					'type'   => 'text',
					'title'  => (string) ( $row['title'] ?? '' ),
					'open'   => ! empty( $row['open'] ),
					'values' => $values,
				);
			}
		}

		// Brands.
		if ( ! empty( $settings['brands'] ) && '' !== $this->brand_tax() ) {
			$counts = $this->term_counts( $category, $state, 'b', $this->brand_tax() );
			$values = array();

			foreach ( $counts as $term_id => $count ) {
				$term = get_term( (int) $term_id );
				if ( ! $term instanceof \WP_Term ) {
					continue;
				}
				$values[] = array(
					'v'      => (string) $term->term_id,
					'label'  => $term->name,
					'count'  => (int) $count,
					'active' => in_array( (int) $term->term_id, $state['brands'], true ),
					'style'  => '',
				);
			}

			if ( $values ) {
				usort(
					$values,
					static function ( array $a, array $b ): int {
						return strnatcasecmp( $a['label'], $b['label'] );
					}
				);
				$groups[] = array(
					'key'    => 'fb',
					'type'   => 'text',
					'title'  => '' !== (string) $settings['brands_title'] ? (string) $settings['brands_title'] : __( 'Brand', 'oc-theme' ),
					'open'   => 0,
					'values' => $values,
				);
			}
		}

		// Price.
		if ( 'off' !== $settings['price_mode'] ) {
			$bounds = $this->price_bounds( $category, $state );

			if ( $bounds['max'] > 0 ) {
				$groups[] = array(
					'key'    => 'price',
					'type'   => 'price',
					'title'  => __( 'Price', 'oc-theme' ),
					'open'   => 0,
					'mode'   => (string) $settings['price_mode'],
					'ui'     => (string) $settings['price_ui'],
					'tiers'  => array_filter( array_map( 'floatval', explode( ',', (string) $settings['price_tiers'] ) ) ),
					'bounds' => $bounds,
					'min'    => $state['min'],
					'max'    => $state['max'],
				);
			}
		}

		return $groups;
	}

	/**
	 * Product ids matching the state minus one group, inside the category.
	 * The heart of every recount — one indexed WP_Query, ids only.
	 *
	 * @param int                 $category Current category (0 = shop).
	 * @param array<string,mixed> $state    Parsed state.
	 * @param string              $skip     Group key to exclude.
	 * @param bool                $price    Apply the price part too.
	 * @return array<int,int>
	 */
	private function base_ids( int $category, array $state, string $skip, bool $price = true ): array {
		$args = array(
			'post_type'        => 'product',
			'post_status'      => 'publish',
			'fields'           => 'ids',
			'posts_per_page'   => -1,
			'no_found_rows'    => true,
			'suppress_filters' => false,
			'tax_query'        => $this->tax_query( $state, $skip ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
			'oc_filtering'     => $price,
		);

		if ( $category ) {
			if ( '' !== $this->smart_meta( $category )['mode'] ) {
				$args['oc_smart_cat'] = $category;
			} else {
				$args['tax_query'][] = array(
					'taxonomy'         => 'product_cat',
					'field'            => 'term_id',
					'terms'            => array( $category ),
					'include_children' => true,
				);
			}
		}

		// Visibility: exclude hidden products the way the catalogue does.
		$args['tax_query'][] = array(
			'taxonomy' => 'product_visibility',
			'field'    => 'name',
			'terms'    => array( 'exclude-from-catalog' ),
			'operator' => 'NOT IN',
		);

		if ( ! $price ) {
			// Price excluded (recounting the price group itself): only stock rides.
			$args['oc_filtering'] = $state['instock'];
		}

		$query = new \WP_Query( $args );

		return array_map( 'intval', $query->posts );
	}

	/**
	 * term_id => product count for one taxonomy over the base set.
	 *
	 * @param int                 $category Category id.
	 * @param array<string,mixed> $state    Parsed state.
	 * @param string              $skip     Own group key.
	 * @param string              $taxonomy Counted taxonomy.
	 * @return array<int,int>
	 */
	private function term_counts( int $category, array $state, string $skip, string $taxonomy ): array {
		$ids = $this->base_ids( $category, $state, $skip );

		if ( empty( $ids ) ) {
			return array();
		}

		global $wpdb;

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$wpdb->prepare(
				"SELECT tt.term_id, COUNT(DISTINCT tr.object_id) AS n
				 FROM {$wpdb->term_relationships} tr
				 INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
				 WHERE tt.taxonomy = %s AND tr.object_id IN ( $placeholders )
				 GROUP BY tt.term_id",
				array_merge( array( $taxonomy ), $ids )
			)
		);

		$counts = array();
		foreach ( (array) $rows as $row ) {
			$counts[ (int) $row->term_id ] = (int) $row->n;
		}

		return $counts;
	}

	/**
	 * parent_id => count for a category facet group: each parent counts its
	 * whole subtree.
	 *
	 * @param int                 $category Category id.
	 * @param array<string,mixed> $state    Parsed state.
	 * @param string              $skip     Own group key.
	 * @param array<int,int>      $parents  Parent category ids.
	 * @return array<int,int>
	 */
	private function category_counts( int $category, array $state, string $skip, array $parents ): array {
		$all = $this->term_counts( $category, $state, $skip, 'product_cat' );

		$counts = array();
		foreach ( $parents as $parent_id ) {
			$family = array_merge( array( $parent_id ), get_term_children( $parent_id, 'product_cat' ) );
			$ids    = $this->base_ids( $category, $state, $skip );

			// Cheap union over the already-counted map would overcount products
			// in several children — count precisely with one small query.
			$counts[ $parent_id ] = $this->count_in_terms( $ids, array_map( 'intval', $family ) );
		}

		return $counts;
	}

	/**
	 * Distinct products (of $ids) attached to any of the given category terms.
	 *
	 * @param array<int,int> $ids      Product ids.
	 * @param array<int,int> $term_ids Category term ids.
	 */
	private function count_in_terms( array $ids, array $term_ids ): int {
		if ( empty( $ids ) || empty( $term_ids ) ) {
			return 0;
		}

		global $wpdb;

		$id_ph   = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$term_ph = implode( ',', array_fill( 0, count( $term_ids ), '%d' ) );

		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT tr.object_id)
				 FROM {$wpdb->term_relationships} tr
				 INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
				 WHERE tt.taxonomy = 'product_cat' AND tt.term_id IN ( $term_ph ) AND tr.object_id IN ( $id_ph )",
				array_merge( $term_ids, $ids )
			)
		);
	}

	/**
	 * Price bounds (under every non-price filter) via the lookup table.
	 *
	 * @param int                 $category Category id.
	 * @param array<string,mixed> $state    Parsed state.
	 * @return array{min:float,max:float}
	 */
	private function price_bounds( int $category, array $state ): array {
		$ids = $this->base_ids( $category, $state, 'price', false );

		if ( empty( $ids ) ) {
			return array(
				'min' => 0.0,
				'max' => 0.0,
			);
		}

		global $wpdb;

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$wpdb->prepare(
				"SELECT MIN(min_price) AS lo, MAX(max_price) AS hi FROM {$wpdb->wc_product_meta_lookup} WHERE product_id IN ( $placeholders )",
				$ids
			)
		);

		return array(
			'min' => floor( (float) ( $row->lo ?? 0 ) ),
			'max' => ceil( (float) ( $row->hi ?? 0 ) ),
		);
	}

	/**
	 * How an attribute renders: its own type by default (swatches stay
	 * swatches), or forced to text from the admin screen.
	 */
	private function attr_display_type( string $taxonomy, string $display ): string {
		if ( 'text' === $display ) {
			return 'text';
		}

		foreach ( wc_get_attribute_taxonomies() as $attribute ) {
			if ( 'pa_' . $attribute->attribute_name === rawurldecode( $taxonomy ) ) {
				return in_array( $attribute->attribute_type, array( 'swatch', 'swatch_image' ), true ) ? 'swatch' : 'text';
			}
		}

		return 'text';
	}

	/**
	 * Inline style for a term's filter swatch — its image, else its colour.
	 */
	private function term_swatch_style( int $term_id ): string {
		$image = (string) get_term_meta( $term_id, 'oc_swatch_image', true );

		if ( '' !== $image ) {
			return 'background-image:url(' . esc_url( $image ) . ');background-size:cover;';
		}

		$color = (string) get_term_meta( $term_id, 'oc_swatch_color', true );

		return 'background-color:' . esc_attr( '' !== $color ? $color : '#cccccc' ) . ';';
	}

	/* -------------------------------------------------------------- render */

	/**
	 * Body class for the active layout.
	 *
	 * @param array<int,string> $classes Body classes.
	 * @return array<int,string>
	 */
	public function body_class( array $classes ): array {
		if ( ( is_shop() || is_product_taxonomy() ) && 'none' !== $this->layout() ) {
			$classes[] = 'oc-flt-' . sanitize_html_class( $this->layout() );
		}

		return $classes;
	}

	/**
	 * The filter UI, in the chosen layout, ahead of the product loop.
	 */
	public function render(): void {
		$layout = $this->layout();

		if ( 'none' === $layout || ( ! is_shop() && ! is_product_taxonomy() ) ) {
			return;
		}

		$settings = self::settings();
		$category = 0;
		if ( is_product_taxonomy() ) {
			$term = get_queried_object();
			if ( $term instanceof \WP_Term && 'product_cat' === $term->taxonomy ) {
				$category = (int) $term->term_id;
			}
		}

		$state  = $this->state();
		$facets = $this->facets( $category, $state );

		if ( empty( $facets ) ) {
			return;
		}

		$config = array(
			'category' => $category,
			'layout'   => $layout,
			'topbar'   => (string) $settings['topbar_style'],
			'choice'   => (string) $settings['choice'],
			'counts'   => (int) $settings['counts'],
			'empty'    => (string) $settings['empty'],
			'currency' => html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ),
		);

		echo '<script type="application/json" id="oc-flt-config">' . wp_json_encode( $config ) . '</script>';

		$groups_html = $this->groups_html( $facets, $settings, $layout );
		$chips       = '<div class="oc-flt__chips" data-flt-chips hidden></div>';

		$foot = '<div class="oc-flt__foot oc-flt__foot--m" data-flt-foot' . ( $state['any'] ? '' : ' hidden' ) . '><button type="button" class="oc-flt__apply" data-flt-close-apply>' . esc_html__( 'View results', 'oc-theme' ) . '</button></div>';

		if ( 'sidebar' === $layout ) {
			echo '<div class="oc-flt-wrap">';
			echo '<aside class="oc-flt oc-flt--side" data-flt-panel aria-label="' . esc_attr__( 'Filters', 'oc-theme' ) . '">';
			// No clear-all in the panel head — the chips row above the grid
			// already carries one; two of them read as a bug.
			echo '<div class="oc-flt__head"><span>' . esc_html__( 'Filters', 'oc-theme' ) . '</span><button type="button" class="oc-flt__close oc-flt__close--m" data-flt-close aria-label="' . esc_attr__( 'Close', 'oc-theme' ) . '">&times;</button></div>';
			echo $groups_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo $foot; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '</aside>';
			echo '<div class="oc-flt-main">';
			$this->mobile_trigger( true, true );
			// The chosen values live above the products (vqfit-style), not
			// inside the sidebar; mobile keeps the toolbar's own chips row.
			echo '<div class="oc-flt__chips oc-flt__chips--main" data-flt-chips hidden></div>';
			return;
		}

		echo '<div class="oc-flt-wrap oc-flt-wrap--row">';
		echo '<div class="oc-flt-main">';

		if ( 'topbar' === $layout ) {
			echo '<div class="oc-flt oc-flt--top oc-flt--top-' . esc_attr( (string) $settings['topbar_style'] ) . '" data-flt-panel>';
			echo '<button type="button" class="oc-flt__close oc-flt__close--m" data-flt-close aria-label="' . esc_attr__( 'Close', 'oc-theme' ) . '">&times;</button>';
			echo $groups_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<button type="button" class="oc-flt__clear" data-flt-clear hidden>' . esc_html__( 'Clear all', 'oc-theme' ) . '</button>';
			echo $foot; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '</div>';
			echo $chips; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		} else {
			// Drawer: one toolbar for every viewport — filter, sort, result
			// count at the far end (Lanvin-style) — with the chosen values
			// centred beneath it. The toolbar's own sort sheet becomes a
			// dropdown on desktop.
			$this->mobile_trigger( false, false );
			echo '<div class="oc-flt__chips oc-flt__chips--main oc-flt__chips--center" data-flt-chips hidden></div>';
			echo '<div class="oc-flt__overlay" data-flt-overlay hidden></div>';
			echo '<aside class="oc-flt oc-flt--drawer" data-flt-panel data-flt-drawer hidden aria-label="' . esc_attr__( 'Filters', 'oc-theme' ) . '">';
			echo '<div class="oc-flt__head"><span>' . esc_html__( 'Filters', 'oc-theme' ) . '</span><button type="button" class="oc-flt__close" data-flt-close aria-label="' . esc_attr__( 'Close', 'oc-theme' ) . '">&times;</button></div>';
			echo $groups_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<div class="oc-flt__foot" data-flt-foot' . ( $state['any'] ? '' : ' hidden' ) . '><button type="button" class="oc-flt__apply" data-flt-close-apply>' . esc_html__( 'View results', 'oc-theme' ) . '</button><button type="button" class="oc-flt__clear" data-flt-clear hidden>' . esc_html__( 'Clear all', 'oc-theme' ) . '</button></div>';
			echo '</aside>';
		}

		// Topbar keeps a mobile-only toolbar; the drawer's serves all sizes.
		if ( 'topbar' === $layout ) {
			$this->mobile_trigger( true, false );
		}
	}

	/**
	 * On mobile every layout folds into a drawer: a mobile-only trigger plus
	 * an overlay for the layouts that lack one of their own.
	 *
	 * @param bool $with_overlay Print the click-to-close overlay too.
	 * @param bool $with_chips   Print a mobile-only chips row by the trigger.
	 */
	private function mobile_trigger( bool $with_overlay = true, bool $with_chips = false ): void {
		global $wp_query;

		$found = isset( $wp_query->found_posts ) ? (int) $wp_query->found_posts : 0;

		// The mobile toolbar: filter · sort · live result count.
		echo '<div class="oc-flt__mbar">';
		echo '<button type="button" class="oc-flt__mbtn" data-flt-open>';
		echo '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M4 7h16M7 12h10M10 17h4"/></svg>';
		echo '<span>' . esc_html__( 'Filter', 'oc-theme' ) . '</span><em class="oc-flt__badge" data-flt-badge hidden></em>';
		echo '</button>';
		echo '<button type="button" class="oc-flt__mbtn" data-oc-sort-open>';
		echo '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 5h16l-6.2 7.2V19l-3.6-2v-4.8z"/></svg>';
		echo '<span>' . esc_html__( 'Sort', 'oc-theme' ) . '</span>';
		echo '</button>';
		/* translators: %s: number of products. */
		echo '<span class="oc-flt__rescount" data-flt-rescount>' . esc_html( sprintf( __( '%s results', 'oc-theme' ), number_format_i18n( $found ) ) ) . '</span>';

		// The sort sheet — options arrive from the native select. It lives
		// inside the bar so the desktop dropdown can anchor to it; on mobile
		// its fixed positioning ignores the parent anyway.
		echo '<div class="oc-sortsheet" data-oc-sortsheet hidden>';
		echo '<div class="oc-sortsheet__head"><button type="button" class="oc-sortsheet__close" data-oc-sort-close aria-label="' . esc_attr__( 'Close', 'oc-theme' ) . '">&times;</button><span>' . esc_html__( 'Sort by', 'oc-theme' ) . '</span></div>';
		echo '<div class="oc-sortsheet__list" data-oc-sortlist></div>';
		echo '</div>';
		echo '<div class="oc-flt__overlay oc-sortsheet__overlay" data-oc-sort-overlay hidden></div>';
		echo '</div>';

		if ( $with_chips ) {
			echo '<div class="oc-flt__chips oc-flt__chips--m" data-flt-chips hidden></div>';
		}

		if ( $with_overlay ) {
			echo '<div class="oc-flt__overlay" data-flt-overlay hidden></div>';
		}
	}

	/**
	 * Friendlier ordering labels, everywhere the catalogue sorts.
	 *
	 * @param array<string,string> $options Woo orderby options.
	 * @return array<string,string>
	 */
	public function orderby_labels( array $options ): array {
		$labels = array(
			'menu_order' => __( 'Recommended', 'oc-theme' ),
			'popularity' => __( 'Best sellers', 'oc-theme' ),
			'date'       => __( 'Newest', 'oc-theme' ),
			'price'      => __( 'Price: low to high', 'oc-theme' ),
			'price-desc' => __( 'Price: high to low', 'oc-theme' ),
		);

		foreach ( $labels as $key => $label ) {
			if ( isset( $options[ $key ] ) ) {
				$options[ $key ] = $label;
			}
		}

		return $options;
	}

	/**
	 * Close the wrappers opened by render().
	 */
	public function close_wrap(): void {
		if ( 'none' === $this->layout() || ( ! is_shop() && ! is_product_taxonomy() ) ) {
			return;
		}

		echo '</div></div>';
	}

	/**
	 * The facet groups' markup — shared by every layout; CSS shapes it.
	 *
	 * @param array<int,array<string,mixed>> $facets   Facet groups.
	 * @param array<string,mixed>            $settings Settings.
	 * @param string                         $layout   Active layout — the top
	 *                                                 bar's dropdowns never
	 *                                                 open by themselves.
	 */
	private function groups_html( array $facets, array $settings, string $layout = 'sidebar' ): string {
		$html       = '';
		$allow_open = 'topbar' !== $layout;

		foreach ( $facets as $group ) {
			if ( 'instock' === $group['type'] ) {
				$html .= '<div class="oc-flt__group oc-flt__group--stock" data-flt-group="fin">';
				$html .= '<label class="oc-flt__toggle"><input type="checkbox" data-flt-instock' . ( $group['active'] ? ' checked' : '' ) . ' /><i></i><span>' . esc_html( $group['title'] ) . '</span></label>';
				$html .= '</div>';
				continue;
			}

			if ( 'price' === $group['type'] ) {
				$html .= $this->price_html( $group );
				continue;
			}

			$active_count = count(
				array_filter(
					$group['values'],
					static function ( array $value ): bool {
						return ! empty( $value['active'] );
					}
				)
			);

			$html .= '<div class="oc-flt__group' . ( $allow_open && ! empty( $group['open'] ) ? ' is-open' : '' ) . '" data-flt-group="' . esc_attr( (string) $group['key'] ) . '">';
			$html .= '<button type="button" class="oc-flt__title" data-flt-toggle>';
			$html .= '<span>' . esc_html( (string) $group['title'] ) . '</span>';
			$html .= '<em class="oc-flt__num" data-flt-num' . ( $active_count ? '' : ' hidden' ) . '>(' . absint( $active_count ) . ')</em>';
			$html .= '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>';
			$html .= '</button>';
			$html .= '<div class="oc-flt__body"><div class="oc-flt__values oc-flt__values--' . esc_attr( (string) $group['type'] ) . ' oc-flt__values--' . esc_attr( (string) $settings['choice'] ) . '">';

			foreach ( $group['values'] as $value ) {
				$disabled = 0 === (int) $value['count'] && empty( $value['active'] );
				if ( $disabled && 'hide' === $settings['empty'] ) {
					continue;
				}

				$html .= '<button type="button" class="oc-flt__val' . ( $value['active'] ? ' is-active' : '' ) . ( $disabled ? ' is-off' : '' ) . '"';
				$html .= ' data-flt-val="' . esc_attr( (string) $value['v'] ) . '" data-label="' . esc_attr( (string) $value['label'] ) . '"' . ( $disabled ? ' disabled' : '' ) . '>';

				if ( 'swatch' === $group['type'] && '' !== $value['style'] ) {
					$html .= '<i class="oc-flt__swatch" style="' . $value['style'] . '"></i>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built escaped.
				} else {
					$html .= '<i class="oc-flt__mark"></i>';
				}

				$html .= '<span>' . esc_html( (string) $value['label'] ) . '</span>';

				if ( ! empty( $settings['counts'] ) ) {
					$html .= '<em data-flt-count>' . absint( $value['count'] ) . '</em>';
				}

				$html .= '</button>';
			}

			$html .= '</div></div></div>';
		}

		return $html;
	}

	/**
	 * The price group: range (slider or inputs) or preset tiers.
	 *
	 * @param array<string,mixed> $group Price group data.
	 */
	private function price_html( array $group ): string {
		$symbol = html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' );
		$lo     = (float) $group['bounds']['min'];
		$hi     = (float) $group['bounds']['max'];
		$cur_lo = null !== $group['min'] ? (float) $group['min'] : $lo;
		$cur_hi = null !== $group['max'] ? (float) $group['max'] : $hi;

		$html  = '<div class="oc-flt__group" data-flt-group="price" data-lo="' . esc_attr( (string) $lo ) . '" data-hi="' . esc_attr( (string) $hi ) . '">';
		$html .= '<button type="button" class="oc-flt__title" data-flt-toggle>';
		$html .= '<span>' . esc_html( (string) $group['title'] ) . '</span>';
		$html .= '<em class="oc-flt__num" data-flt-num' . ( null !== $group['min'] || null !== $group['max'] ? '' : ' hidden' ) . '>(1)</em>';
		$html .= '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>';
		$html .= '</button><div class="oc-flt__body">';

		if ( 'tiers' === $group['mode'] && ! empty( $group['tiers'] ) ) {
			$html .= '<div class="oc-flt__values oc-flt__values--text oc-flt__values--tiers">';
			foreach ( $group['tiers'] as $tier ) {
				$active = null !== $group['max'] && (float) $group['max'] === (float) $tier && null === $group['min'];
				$html  .= '<button type="button" class="oc-flt__val' . ( $active ? ' is-active' : '' ) . '" data-flt-tier="' . esc_attr( (string) $tier ) . '">';
				/* translators: %s: price. */
				$html .= '<i class="oc-flt__mark"></i><span>' . esc_html( sprintf( __( 'Up to %s', 'oc-theme' ), $symbol . number_format_i18n( (float) $tier ) ) ) . '</span></button>';
			}
			$html .= '</div>';
		} else {
			$html .= '<div class="oc-flt__price">';
			if ( 'slider' === $group['ui'] ) {
				$html .= '<div class="oc-flt__slider" data-flt-slider>';
				$html .= '<div class="oc-flt__rail"><div class="oc-flt__fill" data-flt-fill></div></div>';
				$html .= '<input type="range" data-flt-rlo min="' . esc_attr( (string) $lo ) . '" max="' . esc_attr( (string) $hi ) . '" value="' . esc_attr( (string) $cur_lo ) . '" step="1" />';
				$html .= '<input type="range" data-flt-rhi min="' . esc_attr( (string) $lo ) . '" max="' . esc_attr( (string) $hi ) . '" value="' . esc_attr( (string) $cur_hi ) . '" step="1" />';
				$html .= '</div>';
				$html .= '<div class="oc-flt__pvals"><span data-flt-plo>' . esc_html( $symbol . number_format_i18n( $cur_lo ) ) . '</span><span data-flt-phi>' . esc_html( $symbol . number_format_i18n( $cur_hi ) ) . '</span></div>';
			} else {
				$html .= '<div class="oc-flt__pinputs">';
				$html .= '<input type="number" inputmode="numeric" data-flt-ilo placeholder="' . esc_attr__( 'From', 'oc-theme' ) . '" value="' . esc_attr( null !== $group['min'] ? (string) $group['min'] : '' ) . '" min="0" />';
				$html .= '<span>—</span>';
				$html .= '<input type="number" inputmode="numeric" data-flt-ihi placeholder="' . esc_attr__( 'To', 'oc-theme' ) . '" value="' . esc_attr( null !== $group['max'] ? (string) $group['max'] : '' ) . '" min="0" />';
				$html .= '<button type="button" class="oc-flt__papply" data-flt-papply>' . esc_html__( 'Apply', 'oc-theme' ) . '</button>';
				$html .= '</div>';
			}
			$html .= '</div>';
		}

		$html .= '</div></div>';

		return $html;
	}

	/* ---------------------------------------------------------------- ajax */

	/**
	 * In-page filtering: cards + recounted facets, nothing else. The query
	 * mirrors the archive's, so what the visitor sees equals a full load of
	 * the same URL.
	 */
	public function ajax(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- public read-only endpoint.
		$category = absint( $_GET['cat'] ?? 0 );
		$paged    = max( 1, absint( $_GET['pg'] ?? 1 ) );
		$orderby  = sanitize_text_field( wp_unslash( (string) ( $_GET['orderby'] ?? '' ) ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$state    = $this->state();
		$settings = self::settings();

		$ordering = WC()->query->get_catalog_ordering_args( $orderby ?: null );

		$args = array(
			'post_type'        => 'product',
			'post_status'      => 'publish',
			'posts_per_page'   => (int) get_theme_mod( 'oc_catalog_per_page', 24 ),
			'paged'            => $paged,
			'orderby'          => $ordering['orderby'],
			'order'            => $ordering['order'],
			'suppress_filters' => false,
			'tax_query'        => $this->tax_query( $state ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
			'oc_filtering'     => true,
		);

		if ( ! empty( $ordering['meta_key'] ) ) {
			$args['meta_key'] = $ordering['meta_key']; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
		}

		if ( $category ) {
			if ( '' !== $this->smart_meta( $category )['mode'] ) {
				$args['oc_smart_cat'] = $category;
			} else {
				$args['tax_query'][] = array(
					'taxonomy'         => 'product_cat',
					'field'            => 'term_id',
					'terms'            => array( $category ),
					'include_children' => true,
				);
			}
		}

		$args['tax_query'][] = array(
			'taxonomy' => 'product_visibility',
			'field'    => 'name',
			'terms'    => array( 'exclude-from-catalog' ),
			'operator' => 'NOT IN',
		);

		if ( $category && 'sale' === $this->smart_meta( $category )['mode'] ) {
			self::$sale_render = true;
		}

		$query = new \WP_Query( $args );

		ob_start();

		global $post;

		while ( $query->have_posts() ) {
			$query->the_post();
			$GLOBALS['product'] = wc_get_product( $post->ID ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- template contract.
			wc_get_template_part( 'content', 'product' );
		}
		wp_reset_postdata();

		$cards = ob_get_clean();

		wp_send_json_success(
			array(
				'html'   => $cards,
				'found'  => (int) $query->found_posts,
				'pages'  => (int) $query->max_num_pages,
				'page'   => $paged,
				'facets' => $this->facets_payload( $category, $state ),
			)
		);
	}

	/**
	 * Slim facet payload for the JS: per group key — value states and counts.
	 *
	 * @param int                 $category Category id.
	 * @param array<string,mixed> $state    Parsed state.
	 * @return array<string,mixed>
	 */
	private function facets_payload( int $category, array $state ): array {
		$payload = array();

		foreach ( $this->facets( $category, $state ) as $group ) {
			if ( 'instock' === $group['type'] ) {
				continue;
			}

			if ( 'price' === $group['type'] ) {
				$payload['price'] = array(
					'lo' => $group['bounds']['min'],
					'hi' => $group['bounds']['max'],
				);
				continue;
			}

			$values = array();
			foreach ( $group['values'] as $value ) {
				$values[ (string) $value['v'] ] = (int) $value['count'];
			}

			$payload[ (string) $group['key'] ] = $values;
		}

		return $payload;
	}

	/* --------------------------------------------------- category term meta */

	/**
	 * Per-category layout picker on the category edit screen.
	 *
	 * @param \WP_Term $term Edited category.
	 */
	public function category_field( $term ): void {
		$current = (string) get_term_meta( $term->term_id, 'oc_filter_layout', true );

		$options = array(
			''        => __( 'Site default', 'oc-theme' ),
			'sidebar' => __( 'Side column', 'oc-theme' ),
			'topbar'  => __( 'Bar above the products', 'oc-theme' ),
			'drawer'  => __( 'Filter button opening a panel', 'oc-theme' ),
			'none'    => __( 'No filters here', 'oc-theme' ),
		);

		$icons = array(
			''        => '<rect x="2" y="2" width="44" height="28" rx="3" fill="none" stroke="currentColor" stroke-dasharray="3 3"/>',
			'sidebar' => '<rect x="34" y="2" width="12" height="28" rx="2"/><rect x="2" y="2" width="28" height="8" rx="2" opacity=".35"/><rect x="2" y="14" width="28" height="16" rx="2" opacity=".35"/>',
			'topbar'  => '<rect x="2" y="2" width="44" height="7" rx="2"/><rect x="2" y="13" width="44" height="17" rx="2" opacity=".35"/>',
			'drawer'  => '<rect x="36" y="2" width="10" height="6" rx="2"/><rect x="2" y="12" width="44" height="18" rx="2" opacity=".35"/>',
			'none'    => '<rect x="2" y="2" width="44" height="28" rx="3" opacity=".35"/><path d="M8 8l32 16M40 8L8 24" stroke="currentColor" stroke-width="2" fill="none"/>',
		);
		?>
		<?php
		$smart    = $this->smart_meta( (int) $term->term_id );
		$top_cats = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'parent'     => 0,
				'hide_empty' => false,
				'exclude'    => array( (int) $term->term_id ),
			)
		);
		?>
		<tr class="form-field">
			<th scope="row"><?php esc_html_e( 'Automatic product assignment', 'oc-theme' ); ?></th>
			<td>
				<select name="oc_smart">
					<option value="" <?php selected( '', $smart['mode'] ); ?>><?php esc_html_e( 'Off — regular category', 'oc-theme' ); ?></option>
					<option value="sale" <?php selected( 'sale', $smart['mode'] ); ?>><?php esc_html_e( 'On-sale products', 'oc-theme' ); ?></option>
					<option value="new" <?php selected( 'new', $smart['mode'] ); ?>><?php esc_html_e( 'New products (per the "New" label setting)', 'oc-theme' ); ?></option>
					<option value="price" <?php selected( 'price', $smart['mode'] ); ?>><?php esc_html_e( 'Products up to a price', 'oc-theme' ); ?></option>
				</select>
				<input type="number" name="oc_smart_price" value="<?php echo esc_attr( $smart['price'] > 0 ? (string) $smart['price'] : '' ); ?>" placeholder="<?php esc_attr_e( 'Up to price', 'oc-theme' ); ?>" min="0" step="1" style="width:110px;" />
				<p class="description"><?php esc_html_e( 'Products are matched live by the condition — nothing to assign or maintain, and they keep their real categories (so a "category" filter group works here).', 'oc-theme' ); ?></p>
				<p style="margin-block-start:10px;">
					<label style="display:block;margin-block-end:4px;"><?php esc_html_e( 'Limit to these source categories (optional)', 'oc-theme' ); ?></label>
					<select name="oc_smart_cats[]" multiple size="5" style="min-width:240px;">
						<?php foreach ( $top_cats as $cat ) : ?>
							<option value="<?php echo absint( $cat->term_id ); ?>" <?php echo in_array( (int) $cat->term_id, $smart['cats'], true ) ? 'selected' : ''; ?>><?php echo esc_html( $cat->name ); ?></option>
						<?php endforeach; ?>
					</select>
				</p>
			</td>
		</tr>
		<tr class="form-field">
			<th scope="row"><?php esc_html_e( 'Filters layout', 'oc-theme' ); ?></th>
			<td>
				<div class="oc-flt-pick" style="display:flex;gap:10px;flex-wrap:wrap;">
					<?php foreach ( $options as $value => $label ) : ?>
						<label style="display:flex;flex-direction:column;align-items:center;gap:6px;padding:10px;border:1.5px solid <?php echo $current === $value ? '#2271b1' : '#ddd'; ?>;border-radius:8px;cursor:pointer;min-width:110px;text-align:center;">
							<svg viewBox="0 0 48 32" width="48" height="32" fill="currentColor" aria-hidden="true"><?php echo $icons[ $value ]; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?></svg>
							<input type="radio" name="oc_filter_layout" value="<?php echo esc_attr( $value ); ?>" <?php checked( $current, $value ); ?> style="margin:0;" />
							<span style="font-size:12px;"><?php echo esc_html( $label ); ?></span>
						</label>
					<?php endforeach; ?>
				</div>
			</td>
		</tr>
		<?php
	}

	/**
	 * Persist the per-category layout.
	 *
	 * @param int $term_id Category id.
	 */
	public function save_category_field( $term_id ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- core term save flow.
		if ( isset( $_POST['oc_filter_layout'] ) ) {
			$value = sanitize_key( wp_unslash( (string) $_POST['oc_filter_layout'] ) );

			if ( '' === $value ) {
				delete_term_meta( (int) $term_id, 'oc_filter_layout' );
			} elseif ( in_array( $value, array( 'sidebar', 'topbar', 'drawer', 'none' ), true ) ) {
				update_term_meta( (int) $term_id, 'oc_filter_layout', $value );
			}
		}

		if ( isset( $_POST['oc_smart'] ) ) {
			$mode = sanitize_key( wp_unslash( (string) $_POST['oc_smart'] ) );

			if ( in_array( $mode, array( 'sale', 'price', 'new' ), true ) ) {
				update_term_meta( (int) $term_id, 'oc_smart', $mode );
			} else {
				delete_term_meta( (int) $term_id, 'oc_smart' );
			}

			$price = (float) ( $_POST['oc_smart_price'] ?? 0 );
			if ( $price > 0 ) {
				update_term_meta( (int) $term_id, 'oc_smart_price', $price );
			} else {
				delete_term_meta( (int) $term_id, 'oc_smart_price' );
			}

			$cats = array_filter( array_map( 'absint', (array) ( $_POST['oc_smart_cats'] ?? array() ) ) );
			if ( $cats ) {
				update_term_meta( (int) $term_id, 'oc_smart_cats', array_values( $cats ) );
			} else {
				delete_term_meta( (int) $term_id, 'oc_smart_cats' );
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/* --------------------------------------------------------------- admin */

	/**
	 * Submenu under WooCommerce — the shop manager's home for filtering.
	 */
	public function menu(): void {
		add_submenu_page(
			Tabs::MENU,
			__( 'Catalogue filters', 'oc-theme' ),
			__( 'Catalogue filters', 'oc-theme' ),
			'manage_woocommerce',
			'oc-filters',
			array( $this, 'admin_screen' )
		);
	}

	/**
	 * The admin screen.
	 */
	public function admin_screen(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		if ( isset( $_GET['oc_saved'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- notice only.
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Settings saved.', 'oc-theme' ) . '</p></div>';
		}

		$settings = self::settings();

		// Existing group rows by identity, for prefilling.
		$attr_rows = array();
		$cat_rows  = array();
		foreach ( (array) $settings['groups'] as $row ) {
			if ( 'attribute' === ( $row['type'] ?? '' ) ) {
				$attr_rows[ (int) $row['id'] ] = $row;
			} elseif ( 'category' === ( $row['type'] ?? '' ) ) {
				$cat_rows[] = $row;
			}
		}

		$top_cats = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'parent'     => 0,
				'hide_empty' => false,
			)
		);
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Catalogue filters', 'oc-theme' ); ?></h1>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="oc_filters_save" />
				<?php wp_nonce_field( 'oc_filters_save' ); ?>

				<h2><?php esc_html_e( 'General', 'oc-theme' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Filtering', 'oc-theme' ); ?></th>
						<td><label><input type="checkbox" name="enabled" value="1" <?php checked( 1, (int) $settings['enabled'] ); ?> /> <?php esc_html_e( 'Enable catalogue filtering', 'oc-theme' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Default layout (desktop)', 'oc-theme' ); ?></th>
						<td>
							<?php
							$layout_options = array(
								'sidebar' => __( 'Side column', 'oc-theme' ),
								'topbar'  => __( 'Bar above the products', 'oc-theme' ),
								'drawer'  => __( 'Filter button opening a panel', 'oc-theme' ),
							);

							$layout_icons = array(
								'sidebar' => '<rect x="34" y="2" width="12" height="28" rx="2"/><rect x="2" y="2" width="28" height="8" rx="2" opacity=".35"/><rect x="2" y="14" width="28" height="16" rx="2" opacity=".35"/>',
								'topbar'  => '<rect x="2" y="2" width="44" height="7" rx="2"/><rect x="2" y="13" width="44" height="17" rx="2" opacity=".35"/>',
								'drawer'  => '<rect x="36" y="2" width="10" height="6" rx="2"/><rect x="2" y="12" width="44" height="18" rx="2" opacity=".35"/>',
							);
							?>
							<div class="oc-flt-pick" id="oc-flt-layout-pick" style="display:flex;gap:10px;flex-wrap:wrap;">
								<?php foreach ( $layout_options as $value => $label ) : ?>
									<label style="display:flex;flex-direction:column;align-items:center;gap:6px;padding:10px;border:1.5px solid <?php echo $settings['layout'] === $value ? '#2271b1' : '#ddd'; ?>;border-radius:8px;cursor:pointer;min-width:110px;text-align:center;">
										<svg viewBox="0 0 48 32" width="48" height="32" fill="currentColor" aria-hidden="true"><?php echo $layout_icons[ $value ]; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?></svg>
										<input type="radio" name="layout" value="<?php echo esc_attr( $value ); ?>" <?php checked( $settings['layout'], $value ); ?> style="margin:0;" />
										<span style="font-size:12px;"><?php echo esc_html( $label ); ?></span>
									</label>
								<?php endforeach; ?>
							</div>
							<p class="description"><?php esc_html_e( 'Each category can pick its own layout on its edit screen. Mobile always uses the panel.', 'oc-theme' ); ?></p>
						</td>
					</tr>
					<tr id="oc-flt-topbar-row" <?php echo 'topbar' === $settings['layout'] ? '' : 'style="display:none;"'; ?>>
						<th scope="row"><label for="oc-flt-topbar"><?php esc_html_e( 'Bar dropdown style', 'oc-theme' ); ?></label></th>
						<td>
							<select name="topbar_style" id="oc-flt-topbar">
								<option value="drop" <?php selected( 'drop', $settings['topbar_style'] ); ?>><?php esc_html_e( 'Opens under the value', 'oc-theme' ); ?></option>
								<option value="full" <?php selected( 'full', $settings['topbar_style'] ); ?>><?php esc_html_e( 'Opens full width', 'oc-theme' ); ?></option>
							</select>
						</td>
					</tr>
					<script>
					( function () {
						var pick = document.getElementById( 'oc-flt-layout-pick' );
						var topbarRow = document.getElementById( 'oc-flt-topbar-row' );

						pick.addEventListener( 'change', function () {
							var chosen = pick.querySelector( 'input[name=layout]:checked' );
							var value = chosen ? chosen.value : '';

							// Layout-specific rows show only for their layout.
							topbarRow.style.display = 'topbar' === value ? '' : 'none';

							pick.querySelectorAll( 'label' ).forEach( function ( card ) {
								var on = card.querySelector( 'input' ).checked;
								card.style.borderColor = on ? '#2271b1' : '#ddd';
							} );
						} );
					} )();
					</script>
					<tr>
						<th scope="row"><label for="oc-flt-choice"><?php esc_html_e( 'Choice style', 'oc-theme' ); ?></label></th>
						<td>
							<select name="choice" id="oc-flt-choice">
								<option value="check" <?php selected( 'check', $settings['choice'] ); ?>><?php esc_html_e( 'Checkboxes', 'oc-theme' ); ?></option>
								<option value="dot" <?php selected( 'dot', $settings['choice'] ); ?>><?php esc_html_e( 'Dot marks', 'oc-theme' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Counts', 'oc-theme' ); ?></th>
						<td><label><input type="checkbox" name="counts" value="1" <?php checked( 1, (int) $settings['counts'] ); ?> /> <?php esc_html_e( 'Show the number of items next to each value', 'oc-theme' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><label for="oc-flt-empty"><?php esc_html_e( 'Values with no results', 'oc-theme' ); ?></label></th>
						<td>
							<select name="empty" id="oc-flt-empty">
								<option value="gray" <?php selected( 'gray', $settings['empty'] ); ?>><?php esc_html_e( 'Grey and unclickable', 'oc-theme' ); ?></option>
								<option value="hide" <?php selected( 'hide', $settings['empty'] ); ?>><?php esc_html_e( 'Hidden entirely', 'oc-theme' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Stock', 'oc-theme' ); ?></th>
						<td><label><input type="checkbox" name="instock" value="1" <?php checked( 1, (int) $settings['instock'] ); ?> /> <?php esc_html_e( 'Show the "In stock only" toggle', 'oc-theme' ); ?></label></td>
					</tr>
					<?php if ( '' !== $this->brand_tax() ) : ?>
						<tr>
							<th scope="row"><?php esc_html_e( 'Brands', 'oc-theme' ); ?></th>
							<td>
								<label><input type="checkbox" name="brands" value="1" <?php checked( 1, (int) $settings['brands'] ); ?> /> <?php esc_html_e( 'Filter by brand', 'oc-theme' ); ?></label>
								<input type="text" name="brands_title" value="<?php echo esc_attr( (string) $settings['brands_title'] ); ?>" placeholder="<?php esc_attr_e( 'Brand', 'oc-theme' ); ?>" style="margin-inline-start:10px;" />
							</td>
						</tr>
					<?php endif; ?>
				</table>

				<h2><?php esc_html_e( 'Price', 'oc-theme' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="oc-flt-pmode"><?php esc_html_e( 'Price filter', 'oc-theme' ); ?></label></th>
						<td>
							<select name="price_mode" id="oc-flt-pmode">
								<option value="range" <?php selected( 'range', $settings['price_mode'] ); ?>><?php esc_html_e( 'From price to price', 'oc-theme' ); ?></option>
								<option value="tiers" <?php selected( 'tiers', $settings['price_mode'] ); ?>><?php esc_html_e( 'Preset "up to" steps', 'oc-theme' ); ?></option>
								<option value="off" <?php selected( 'off', $settings['price_mode'] ); ?>><?php esc_html_e( 'Off', 'oc-theme' ); ?></option>
							</select>
							<select name="price_ui">
								<option value="slider" <?php selected( 'slider', $settings['price_ui'] ); ?>><?php esc_html_e( 'Slider', 'oc-theme' ); ?></option>
								<option value="inputs" <?php selected( 'inputs', $settings['price_ui'] ); ?>><?php esc_html_e( 'Input fields', 'oc-theme' ); ?></option>
							</select>
							<input type="text" name="price_tiers" value="<?php echo esc_attr( (string) $settings['price_tiers'] ); ?>" placeholder="100,300,500,1000" dir="ltr" />
							<p class="description"><?php esc_html_e( 'Steps apply to the "up to" mode — comma-separated prices.', 'oc-theme' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Attributes', 'oc-theme' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Order sets the display order. Swatch attributes show as swatches unless switched to text.', 'oc-theme' ); ?></p>
				<table class="widefat striped" style="max-width:880px;">
					<thead>
						<tr>
							<th style="width:60px;"><?php esc_html_e( 'Show', 'oc-theme' ); ?></th>
							<th style="width:70px;"><?php esc_html_e( 'Order', 'oc-theme' ); ?></th>
							<th><?php esc_html_e( 'Attribute', 'oc-theme' ); ?></th>
							<th><?php esc_html_e( 'Title override', 'oc-theme' ); ?></th>
							<th><?php esc_html_e( 'Display', 'oc-theme' ); ?></th>
							<th><?php esc_html_e( 'Open by default', 'oc-theme' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( wc_get_attribute_taxonomies() as $attribute ) : ?>
							<?php
							$id  = (int) $attribute->attribute_id;
							$row = $attr_rows[ $id ] ?? array();
							?>
							<tr>
								<td><input type="checkbox" name="attr_on[<?php echo absint( $id ); ?>]" value="1" <?php checked( 1, (int) ( $row['on'] ?? 0 ) ); ?> /></td>
								<td><input type="number" name="attr_order[<?php echo absint( $id ); ?>]" value="<?php echo esc_attr( (string) ( $row['order'] ?? 10 ) ); ?>" style="width:60px;" /></td>
								<td><strong><?php echo esc_html( $attribute->attribute_label ); ?></strong> <span style="color:#888;">(<?php echo esc_html( $attribute->attribute_type ); ?>)</span></td>
								<td><input type="text" name="attr_title[<?php echo absint( $id ); ?>]" value="<?php echo esc_attr( (string) ( $row['title'] ?? '' ) ); ?>" /></td>
								<td>
									<select name="attr_display[<?php echo absint( $id ); ?>]">
										<option value="auto" <?php selected( 'auto', $row['display'] ?? 'auto' ); ?>><?php esc_html_e( 'By its type', 'oc-theme' ); ?></option>
										<option value="text" <?php selected( 'text', $row['display'] ?? 'auto' ); ?>><?php esc_html_e( 'Text', 'oc-theme' ); ?></option>
									</select>
								</td>
								<td><input type="checkbox" name="attr_open[<?php echo absint( $id ); ?>]" value="1" <?php checked( 1, (int) ( $row['open'] ?? 0 ) ); ?> /></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<h2><?php esc_html_e( 'Category groups', 'oc-theme' ); ?></h2>
				<p class="description"><?php esc_html_e( 'A facet built from main categories — e.g. a "Gender" group holding the Women and Men categories. Filtering shows the current category\'s products that also belong to the chosen one.', 'oc-theme' ); ?></p>
				<table class="widefat striped" style="max-width:880px;">
					<thead>
						<tr>
							<th style="width:60px;"><?php esc_html_e( 'Show', 'oc-theme' ); ?></th>
							<th style="width:70px;"><?php esc_html_e( 'Order', 'oc-theme' ); ?></th>
							<th><?php esc_html_e( 'Group title', 'oc-theme' ); ?></th>
							<th><?php esc_html_e( 'Main categories', 'oc-theme' ); ?></th>
							<th><?php esc_html_e( 'Only in these categories (empty = everywhere)', 'oc-theme' ); ?></th>
							<th><?php esc_html_e( 'Open by default', 'oc-theme' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php for ( $i = 0; $i < 3; $i++ ) : ?>
							<?php $row = $cat_rows[ $i ] ?? array(); ?>
							<tr>
								<td><input type="checkbox" name="cat_on[<?php echo absint( $i ); ?>]" value="1" <?php checked( 1, (int) ( $row['on'] ?? 0 ) ); ?> /></td>
								<td><input type="number" name="cat_order[<?php echo absint( $i ); ?>]" value="<?php echo esc_attr( (string) ( $row['order'] ?? 20 ) ); ?>" style="width:60px;" /></td>
								<td><input type="text" name="cat_title[<?php echo absint( $i ); ?>]" value="<?php echo esc_attr( (string) ( $row['title'] ?? '' ) ); ?>" placeholder="<?php esc_attr_e( 'e.g. Gender', 'oc-theme' ); ?>" /></td>
								<td>
									<select name="cat_terms[<?php echo absint( $i ); ?>][]" multiple size="4" style="min-width:220px;">
										<?php foreach ( $top_cats as $cat ) : ?>
											<option value="<?php echo absint( $cat->term_id ); ?>" <?php echo in_array( $cat->term_id, array_map( 'intval', (array) ( $row['cats'] ?? array() ) ), true ) ? 'selected' : ''; ?>><?php echo esc_html( $cat->name ); ?></option>
										<?php endforeach; ?>
									</select>
								</td>
								<td>
									<select name="cat_show[<?php echo absint( $i ); ?>][]" multiple size="4" style="min-width:180px;">
										<?php foreach ( $top_cats as $cat ) : ?>
											<option value="<?php echo absint( $cat->term_id ); ?>" <?php echo in_array( $cat->term_id, array_map( 'intval', (array) ( $row['show'] ?? array() ) ), true ) ? 'selected' : ''; ?>><?php echo esc_html( $cat->name ); ?></option>
										<?php endforeach; ?>
									</select>
								</td>
								<td><input type="checkbox" name="cat_open[<?php echo absint( $i ); ?>]" value="1" <?php checked( 1, (int) ( $row['open'] ?? 0 ) ); ?> /></td>
							</tr>
						<?php endfor; ?>
					</tbody>
				</table>

				<p style="margin-block-start:18px;"><button class="button button-primary"><?php esc_html_e( 'Save settings', 'oc-theme' ); ?></button></p>
			</form>
		</div>
		<?php
	}

	/**
	 * Persist the admin screen.
	 */
	public function save_settings(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die();
		}
		check_admin_referer( 'oc_filters_save' );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified above.
		$groups = array();

		foreach ( wc_get_attribute_taxonomies() as $attribute ) {
			$id       = (int) $attribute->attribute_id;
			$groups[] = array(
				'type'    => 'attribute',
				'id'      => $id,
				'on'      => empty( $_POST['attr_on'][ $id ] ) ? 0 : 1,
				'order'   => (int) ( $_POST['attr_order'][ $id ] ?? 10 ),
				'title'   => sanitize_text_field( wp_unslash( (string) ( $_POST['attr_title'][ $id ] ?? '' ) ) ),
				'display' => 'text' === ( $_POST['attr_display'][ $id ] ?? 'auto' ) ? 'text' : 'auto',
				'open'    => empty( $_POST['attr_open'][ $id ] ) ? 0 : 1,
			);
		}

		for ( $i = 0; $i < 3; $i++ ) {
			$title = sanitize_text_field( wp_unslash( (string) ( $_POST['cat_title'][ $i ] ?? '' ) ) );
			$cats  = array_filter( array_map( 'absint', (array) ( $_POST['cat_terms'][ $i ] ?? array() ) ) );

			if ( '' === $title && empty( $cats ) ) {
				continue;
			}

			$groups[] = array(
				'type'  => 'category',
				'on'    => empty( $_POST['cat_on'][ $i ] ) ? 0 : 1,
				'order' => (int) ( $_POST['cat_order'][ $i ] ?? 20 ),
				'title' => $title,
				'cats'  => array_values( $cats ),
				'show'  => array_values( array_filter( array_map( 'absint', (array) ( $_POST['cat_show'][ $i ] ?? array() ) ) ) ),
				'open'  => empty( $_POST['cat_open'][ $i ] ) ? 0 : 1,
			);
		}

		usort(
			$groups,
			static function ( array $a, array $b ): int {
				return ( $a['order'] <=> $b['order'] );
			}
		);

		update_option(
			'oc_filters',
			array(
				'enabled'      => empty( $_POST['enabled'] ) ? 0 : 1,
				'layout'       => in_array( $_POST['layout'] ?? '', array( 'sidebar', 'topbar', 'drawer' ), true ) ? sanitize_key( $_POST['layout'] ) : 'sidebar',
				'topbar_style' => 'full' === ( $_POST['topbar_style'] ?? '' ) ? 'full' : 'drop',
				'choice'       => 'dot' === ( $_POST['choice'] ?? '' ) ? 'dot' : 'check',
				'counts'       => empty( $_POST['counts'] ) ? 0 : 1,
				'empty'        => 'hide' === ( $_POST['empty'] ?? '' ) ? 'hide' : 'gray',
				'instock'      => empty( $_POST['instock'] ) ? 0 : 1,
				'brands'       => empty( $_POST['brands'] ) ? 0 : 1,
				'brands_title' => sanitize_text_field( wp_unslash( (string) ( $_POST['brands_title'] ?? '' ) ) ),
				'price_mode'   => in_array( $_POST['price_mode'] ?? '', array( 'range', 'tiers', 'off' ), true ) ? sanitize_key( $_POST['price_mode'] ) : 'range',
				'price_ui'     => 'inputs' === ( $_POST['price_ui'] ?? '' ) ? 'inputs' : 'slider',
				'price_tiers'  => sanitize_text_field( wp_unslash( (string) ( $_POST['price_tiers'] ?? '' ) ) ),
				'groups'       => $groups,
			),
			false
		);
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		wp_safe_redirect( add_query_arg( array( 'page' => 'oc-filters', 'oc_saved' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}
}
