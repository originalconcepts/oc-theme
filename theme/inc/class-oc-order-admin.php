<?php
/**
 * The screen where a shop puts its products in the order it wants.
 *
 * A grid of the products as the shopper sees them — picture, name, price —
 * dragged into place. Nothing is loaded that is not on screen, the order is
 * written as it is changed rather than in one enormous form, and a page of a
 * long category is saved without touching the pages either side of it.
 *
 * @package OC_Theme
 */

namespace OC\Theme;

defined( 'ABSPATH' ) || exit;

/**
 * The screen, and the small set of routes behind it.
 */
final class Order_Admin {

	/**
	 * The submenu slug.
	 */
	const PAGE = 'oc-order';

	/**
	 * How many products come at a time.
	 */
	const PER = 60;

	/**
	 * Hook in.
	 */
	public function register(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_bar_menu', array( $this, 'toolbar' ), 81 );
		add_action( 'wp_enqueue_scripts', array( $this, 'front_assets' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'rest_api_init', array( $this, 'rest' ) );
	}

	/**
	 * Who may.
	 */
	public static function cap(): string {
		return current_user_can( 'manage_woocommerce' ) ? 'manage_woocommerce' : 'manage_options';
	}

	/**
	 * Under Products.
	 */
	public function menu(): void {
		add_submenu_page(
			'edit.php?post_type=product',
			__( 'Product order', 'oc-theme' ),
			__( 'Product order', 'oc-theme' ),
			self::cap(),
			self::PAGE,
			array( $this, 'screen' )
		);
	}

	/* ------------------------------------------------------------ screen */

	/**
	 * The page itself: a picker, a few buttons, and the grid.
	 */
	public function screen(): void {
		if ( ! current_user_can( self::cap() ) ) {
			wp_die( esc_html__( 'Not allowed.', 'oc-theme' ) );
		}

		Order::install();
		?>
		<div class="wrap ocord">
			<h1><?php esc_html_e( 'Product order', 'oc-theme' ); ?></h1>
			<p class="ocord__sub">
				<?php esc_html_e( 'Drag a product to where it should be. The order is saved as you go, and it holds for this category only — a product can be first here and anywhere else in another. It is used when the shopper has not chosen an order of their own.', 'oc-theme' ); ?>
			</p>

			<div class="ocord__bar">
				<div class="ocord__pick">
					<label class="screen-reader-text" for="ocord-term"><?php esc_html_e( 'Category', 'oc-theme' ); ?></label>
					<input type="text" id="ocord-term" role="combobox" aria-expanded="false" aria-controls="ocord-list" aria-autocomplete="list" autocomplete="off" placeholder="<?php esc_attr_e( 'Type to find a category…', 'oc-theme' ); ?>">
					<ul id="ocord-list" class="ocord__list" role="listbox" hidden></ul>
				</div>

				<label class="ocord__row">
					<span><?php esc_html_e( 'Per row', 'oc-theme' ); ?></span>
					<select id="ocord-row">
						<option value="0"><?php esc_html_e( 'Fit', 'oc-theme' ); ?></option>
						<option value="2">2</option>
						<option value="3">3</option>
						<option value="4">4</option>
						<option value="5">5</option>
						<option value="6">6</option>
					</select>
				</label>

				<span class="ocord__spacer"></span>

				<label class="ocord__auto">
					<span class="screen-reader-text"><?php esc_html_e( 'Put in order by', 'oc-theme' ); ?></span>
					<select id="ocord-auto">
						<option value=""><?php esc_html_e( 'Put in order by…', 'oc-theme' ); ?></option>
						<option value="title"><?php esc_html_e( 'Name, A to Z', 'oc-theme' ); ?></option>
						<option value="price_asc"><?php esc_html_e( 'Price, low to high', 'oc-theme' ); ?></option>
						<option value="price_desc"><?php esc_html_e( 'Price, high to low', 'oc-theme' ); ?></option>
						<option value="new"><?php esc_html_e( 'Newest first', 'oc-theme' ); ?></option>
						<option value="sales"><?php esc_html_e( 'Best selling first', 'oc-theme' ); ?></option>
						<option value="stock"><?php esc_html_e( 'In stock first', 'oc-theme' ); ?></option>
					</select>
				</label>

				<span class="ocord__said" id="ocord-said" role="status" aria-live="polite"></span>
				<button type="button" class="button" id="ocord-clear"><?php esc_html_e( 'Forget this order', 'oc-theme' ); ?></button>
			</div>

			<div class="ocord__note" id="ocord-note" hidden></div>
			<div class="ocord__grid" id="ocord-grid" aria-label="<?php esc_attr_e( 'Products, in the order they are shown', 'oc-theme' ); ?>"></div>

			<p class="ocord__more">
				<button type="button" class="button" id="ocord-more" hidden><?php esc_html_e( 'Show more', 'oc-theme' ); ?></button>
				<span class="ocord__count" id="ocord-count"></span>
			</p>
		</div>
		<?php
	}

	/**
	 * The screen's own script and style.
	 *
	 * @param string $hook Screen hook.
	 */
	public function assets( $hook ): void {
		if ( false === strpos( (string) $hook, self::PAGE ) ) {
			return;
		}

		$dir = get_template_directory_uri() . '/assets';
		$ver = defined( 'OC_THEME_VERSION' ) ? OC_THEME_VERSION : '1';

		wp_enqueue_style( 'oc-order-admin', $dir . '/css/order-admin.css', array(), $ver );
		wp_enqueue_script( 'oc-order-admin', $dir . '/js/order-admin.js', array(), $ver, true );

		wp_add_inline_script(
			'oc-order-admin',
			'window.ocOrder = ' . wp_json_encode(
				array(
					'rest'  => rest_url( 'oc/v1/order' ),
					'nonce' => wp_create_nonce( 'wp_rest' ),
					'terms' => $this->terms(),
					'per'   => self::PER,
					'i18n'  => array(
						'shop'     => __( 'The whole shop', 'oc-theme' ),
						'saved'    => __( 'Saved', 'oc-theme' ),
						'saving'   => __( 'Saving…', 'oc-theme' ),
						'failed'   => __( 'Could not save. Nothing was changed.', 'oc-theme' ),
						'empty'    => __( 'Nothing here yet.', 'oc-theme' ),
						'loading'  => __( 'Loading…', 'oc-theme' ),
						/* translators: 1: how many are shown, 2: how many there are */
						'count'    => __( 'Showing %1$s of %2$s', 'oc-theme' ),
						'sure'     => __( 'Forget the order for this category? The shop goes back to its usual one.', 'oc-theme' ),
						'forgot'   => __( 'Forgotten.', 'oc-theme' ),
						'ownOrder' => __( 'This category has an order of its own.', 'oc-theme' ),
						'noOrder'  => __( 'This category has no order of its own yet — drag a product and it will have one.', 'oc-theme' ),
						'nostock'  => __( 'Out of stock', 'oc-theme' ),
						/* translators: %d: how many products were chosen */
						'picked'   => __( '%d chosen. Drag one of them and they all move, in the order you chose them.', 'oc-theme' ),
						'nocat'    => __( 'No category by that name.', 'oc-theme' ),
					),
				)
			) . ';',
			'before'
		);
	}

	/**
	 * The categories, deepest name first, with how many products each holds.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function terms(): array {
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
			)
		);

		if ( is_wp_error( $terms ) ) {
			return array();
		}

		$nest = array();

		foreach ( $terms as $t ) {
			$nest[ (int) $t->parent ][] = $t;
		}

		$out = array();

		$walk = static function ( int $under, string $trail ) use ( &$walk, &$out, $nest ) {
			foreach ( $nest[ $under ] ?? array() as $t ) {
				$name  = '' === $trail ? $t->name : $trail . ' › ' . $t->name;
				$out[] = array(
					'id'    => (int) $t->term_id,
					'name'  => $name,
					'count' => (int) $t->count,
					'own'   => Order::has( (int) $t->term_id ) ? 1 : 0,
				);

				$walk( (int) $t->term_id, $name );
			}
		};

		$walk( 0, '' );

		return $out;
	}

	/* ------------------------------------------------------------- routes */

	/**
	 * Register them.
	 */
	public function rest(): void {
		$may = static function (): bool {
			return current_user_can( self::cap() );
		};

		register_rest_route(
			'oc/v1',
			'/order',
			array(
				'methods'             => 'GET',
				'permission_callback' => $may,
				'callback'            => array( $this, 'read' ),
			)
		);

		register_rest_route(
			'oc/v1',
			'/order/save',
			array(
				'methods'             => 'POST',
				'permission_callback' => $may,
				'callback'            => array( $this, 'write' ),
			)
		);

		register_rest_route(
			'oc/v1',
			'/order/auto',
			array(
				'methods'             => 'POST',
				'permission_callback' => $may,
				'callback'            => array( $this, 'auto' ),
			)
		);

		register_rest_route(
			'oc/v1',
			'/order/clear',
			array(
				'methods'             => 'POST',
				'permission_callback' => $may,
				'callback'            => array( $this, 'forget' ),
			)
		);
	}

	/**
	 * One window of a category, in the order it is shown.
	 *
	 * @param \WP_REST_Request $req Request.
	 */
	public function read( \WP_REST_Request $req ) {
		$term   = absint( $req->get_param( 'term' ) );
		$offset = max( 0, absint( $req->get_param( 'offset' ) ) );
		$want   = absint( $req->get_param( 'limit' ) );
		$limit  = min( 200, max( 1, $want > 0 ? $want : self::PER ) );
		$ids    = self::ids( $term, $offset, $limit );
		$total  = self::total( $term );

		return rest_ensure_response(
			array(
				'items' => self::cards( $ids, $offset ),
				'total' => $total,
				'own'   => Order::has( $term ),
			)
		);
	}

	/**
	 * Put a window in the order it was dragged into.
	 *
	 * @param \WP_REST_Request $req Request.
	 */
	public function write( \WP_REST_Request $req ) {
		Order::install();

		$term = absint( $req->get_param( 'term' ) );
		$from = max( 0, absint( $req->get_param( 'from' ) ) );
		$ids  = array_map( 'absint', (array) $req->get_param( 'ids' ) );

		if ( count( $ids ) > 400 ) {
			return new \WP_Error( 'oc_order_too_many', __( 'Too many at once.', 'oc-theme' ), array( 'status' => 400 ) );
		}

		// Saving the third page of a category that has never been ordered
		// would put that page in front of the two before it. Give those
		// their natural places first, once.
		if ( $from > 0 && ! Order::has( $term ) ) {
			Order::save( $term, self::ids( $term, 0, $from ), 0 );
		}

		$n = Order::save( $term, $ids, $from );

		return rest_ensure_response( array( 'saved' => $n ) );
	}

	/**
	 * Work the whole order out again from something the shop already knows.
	 *
	 * @param \WP_REST_Request $req Request.
	 */
	public function auto( \WP_REST_Request $req ) {
		Order::install();

		$term = absint( $req->get_param( 'term' ) );
		$by   = sanitize_key( (string) $req->get_param( 'by' ) );

		$ids = self::sorted( $term, $by );

		if ( ! $ids ) {
			return new \WP_Error( 'oc_order_nothing', __( 'Nothing to put in order.', 'oc-theme' ), array( 'status' => 400 ) );
		}

		Order::clear( $term );
		Order::save( $term, $ids, 0 );

		return rest_ensure_response( array( 'saved' => count( $ids ) ) );
	}

	/**
	 * Throw the order away.
	 *
	 * @param \WP_REST_Request $req Request.
	 */
	public function forget( \WP_REST_Request $req ) {
		Order::clear( absint( $req->get_param( 'term' ) ) );

		return rest_ensure_response( array( 'cleared' => true ) );
	}

	/* -------------------------------------------------- from the shop itself */

	/**
	 * Which list the page being looked at belongs to, or null.
	 *
	 * @return array{term:int,from:int}|null
	 */
	private function here(): ?array {
		if ( is_admin() || ! function_exists( 'is_shop' ) ) {
			return null;
		}

		$term = null;

		if ( is_product_category() ) {
			$term = (int) get_queried_object_id();
		} elseif ( is_shop() ) {
			$term = Order::SHOP;
		}

		if ( null === $term ) {
			return null;
		}

		global $wp_query;

		$page = max( 1, (int) $wp_query->get( 'paged' ) );
		$per  = max( 1, (int) $wp_query->get( 'posts_per_page' ) );

		return array(
			'term' => $term,
			'from' => ( $page - 1 ) * $per,
		);
	}

	/**
	 * A way into the order from the catalogue itself, for an administrator
	 * standing on it.
	 *
	 * @param \WP_Admin_Bar $bar The toolbar.
	 */
	public function toolbar( $bar ): void {
		if ( ! current_user_can( self::cap() ) || ! $bar instanceof \WP_Admin_Bar || null === $this->here() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading which way the toolbar entry should point.
		$on = isset( $_GET['oc_sort'] );

		$bar->add_node(
			array(
				'id'    => 'oc-order',
				'title' => $on ? __( 'Leave arranging', 'oc-theme' ) : __( 'Arrange products', 'oc-theme' ),
				'href'  => $on ? remove_query_arg( 'oc_sort' ) : add_query_arg( 'oc_sort', 1 ),
				'meta'  => array(
					'title' => $on
						? __( 'Go back to the shop as a shopper sees it', 'oc-theme' )
						: __( 'Drag the products on this page into the order you want', 'oc-theme' ),
				),
			)
		);
	}

	/**
	 * The catalogue, made draggable — for an administrator who asked, and
	 * for nobody else. A shopper never loads a byte of this.
	 */
	public function front_assets(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a view of your own catalogue, gated on the capability.
		if ( ! isset( $_GET['oc_sort'] ) || ! current_user_can( self::cap() ) ) {
			return;
		}

		$where = $this->here();

		if ( null === $where ) {
			return;
		}

		$dir = get_template_directory_uri() . '/assets';
		$ver = defined( 'OC_THEME_VERSION' ) ? OC_THEME_VERSION : '1';

		wp_enqueue_style( 'oc-order-front', $dir . '/css/order-front.css', array(), $ver );
		wp_enqueue_script( 'oc-order-front', $dir . '/js/order-front.js', array(), $ver, true );

		wp_add_inline_script(
			'oc-order-front',
			'window.ocOrderFront = ' . wp_json_encode(
				array(
					'rest'  => rest_url( 'oc/v1/order' ),
					'nonce' => wp_create_nonce( 'wp_rest' ),
					'term'  => $where['term'],
					'from'  => $where['from'],
					'i18n'  => array(
						'title'  => __( 'Arranging', 'oc-theme' ),
						'help'   => __( 'Drag a product to where it should be. Click one, then another, to move several together. Everything you change is kept as you go — there is nothing to save.', 'oc-theme' ),
						'done'   => __( 'Finish', 'oc-theme' ),
						'saved'  => __( 'Saved', 'oc-theme' ),
						'saving' => __( 'Saving…', 'oc-theme' ),
						'failed' => __( 'Could not save. Nothing was changed.', 'oc-theme' ),
						/* translators: %d: how many were chosen */
						'picked' => __( '%d chosen', 'oc-theme' ),
					),
				)
			) . ';',
			'before'
		);
	}

	/* ------------------------------------------------------------ reading */

	/**
	 * The SQL that both the window and the whole list are built from.
	 *
	 * @param int    $term  Category, or 0 for the whole shop.
	 * @param string $order The ORDER BY, without the words.
	 * @param string $limit The LIMIT, without the word.
	 * @return array{0:string,1:array}
	 */
	private static function sql( int $term, string $order, string $limit ): array {
		global $wpdb;

		$t    = Order::table();
		$join = '';
		$args = array();

		if ( $term > 0 ) {
			$join  .= " INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID";
			$join  .= " INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'product_cat' AND tt.term_id = %d";
			$args[] = $term;
		}

		$join  .= " LEFT JOIN {$t} o ON o.product_id = p.ID AND o.term_id = %d";
		$args[] = $term;

		$where = " WHERE p.post_type = 'product' AND p.post_status = 'publish'";

		return array( $join . $where . ( '' === $order ? '' : ' ORDER BY ' . $order ) . ( '' === $limit ? '' : ' LIMIT ' . $limit ), $args );
	}

	/**
	 * The ids of one window, in the order they are shown.
	 *
	 * @param int $term   Category, or 0.
	 * @param int $offset Where the window starts.
	 * @param int $limit  How many.
	 * @return int[]
	 */
	private static function ids( int $term, int $offset, int $limit ): array {
		global $wpdb;

		list( $tail, $args ) = self::sql( $term, 'o.pos IS NULL ASC, o.pos ASC, p.menu_order ASC, p.post_title ASC', '%d OFFSET %d' );

		$args[] = $limit;
		$args[] = $offset;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders -- own table and core tables; every value is a placeholder, and the clauses are built above from literals only.
		$rows = (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT p.ID FROM {$wpdb->posts} p" . $tail, $args ) );
		// phpcs:enable

		return array_map( 'intval', $rows );
	}

	/**
	 * How many there are altogether.
	 *
	 * @param int $term Category, or 0.
	 */
	private static function total( int $term ): int {
		global $wpdb;

		list( $tail, $args ) = self::sql( $term, '', '' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders -- own table and core tables; every value is a placeholder, and the clauses are built above from literals only.
		$n = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p" . $tail, $args ) );
		// phpcs:enable

		return $n;
	}

	/**
	 * The whole of a category, in an order worked out from what the shop
	 * already knows about each product.
	 *
	 * @param int    $term Category, or 0.
	 * @param string $by   title, price_asc, price_desc, new, sales, stock.
	 * @return int[]
	 */
	private static function sorted( int $term, string $by ): array {
		global $wpdb;

		$order = array(
			'title'      => 'p.post_title ASC',
			'price_asc'  => 'CAST(mp.meta_value AS DECIMAL(16,4)) ASC, p.post_title ASC',
			'price_desc' => 'CAST(mp.meta_value AS DECIMAL(16,4)) DESC, p.post_title ASC',
			'new'        => 'p.post_date DESC',
			'sales'      => 'CAST(ms.meta_value AS UNSIGNED) DESC, p.post_title ASC',
			'stock'      => "CASE WHEN mk.meta_value = 'outofstock' THEN 1 ELSE 0 END ASC, p.menu_order ASC, p.post_title ASC",
		)[ $by ] ?? '';

		if ( '' === $order ) {
			return array();
		}

		list( $tail, $args ) = self::sql( $term, '', '' );

		$meta = '';

		if ( 0 === strpos( $by, 'price' ) ) {
			$meta = " LEFT JOIN {$wpdb->postmeta} mp ON mp.post_id = p.ID AND mp.meta_key = '_price'";
		} elseif ( 'sales' === $by ) {
			$meta = " LEFT JOIN {$wpdb->postmeta} ms ON ms.post_id = p.ID AND ms.meta_key = 'total_sales'";
		} elseif ( 'stock' === $by ) {
			$meta = " LEFT JOIN {$wpdb->postmeta} mk ON mk.post_id = p.ID AND mk.meta_key = '_stock_status'";
		}

		// The meta join has to sit with the others, before the WHERE.
		$tail = preg_replace( '/ WHERE /', $meta . ' WHERE ', $tail, 1 );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders -- own table and core tables; every value is a placeholder, and the ordering is chosen from a fixed list above.
		$rows = (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT p.ID FROM {$wpdb->posts} p" . $tail . ' ORDER BY ' . $order . ' LIMIT 2000', $args ) );
		// phpcs:enable

		return array_map( 'intval', $rows );
	}

	/**
	 * What the grid needs to draw a product, and nothing more.
	 *
	 * @param int[] $ids  Product ids, in order.
	 * @param int   $from The position the first of them holds.
	 * @return array<int,array<string,mixed>>
	 */
	private static function cards( array $ids, int $from ): array {
		if ( ! $ids ) {
			return array();
		}

		_prime_post_caches( $ids, false, true );

		$thumbs = array();

		foreach ( $ids as $id ) {
			$thumb = (int) get_post_thumbnail_id( $id );

			if ( $thumb ) {
				$thumbs[] = $thumb;
			}
		}

		if ( $thumbs ) {
			_prime_post_caches( $thumbs, false, true );
		}

		$out = array();

		foreach ( $ids as $i => $id ) {
			$thumb = (int) get_post_thumbnail_id( $id );
			$price = (string) get_post_meta( $id, '_price', true );

			$out[] = array(
				'id'    => $id,
				'name'  => html_entity_decode( wp_strip_all_tags( (string) get_the_title( $id ) ), ENT_QUOTES, 'UTF-8' ),
				'img'   => $thumb ? (string) wp_get_attachment_image_url( $thumb, 'thumbnail' ) : '',
				'price' => '' === $price ? '' : html_entity_decode( wp_strip_all_tags( (string) wc_price( (float) $price ) ), ENT_QUOTES, 'UTF-8' ),
				'out'   => 'outofstock' === (string) get_post_meta( $id, '_stock_status', true ) ? 1 : 0,
				'pos'   => $from + $i,
				'edit'  => (string) get_edit_post_link( $id, 'raw' ),
			);
		}

		return $out;
	}
}
