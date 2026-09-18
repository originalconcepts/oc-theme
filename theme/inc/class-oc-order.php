<?php
/**
 * The order products are shown in, set by hand, per category.
 *
 * WooCommerce has one number per product — menu_order — and it is the same
 * everywhere, so a product put first in one category is first in all of them.
 * A shop wants to say "in Rugs, show these four first", and that is what this
 * keeps: one row per product per category, in its own small table.
 *
 * It costs the front end almost nothing. A category that has never been
 * ordered by hand carries no mark, and the catalogue query is not touched at
 * all. One that has been ordered adds a single LEFT JOIN on a two-column
 * primary key — and only while the shopper is looking at the shop's own
 * ordering. The moment they choose "price, low to high", their choice wins and
 * the join goes away.
 *
 * @package OC_Theme
 */

namespace OC\Theme;

defined( 'ABSPATH' ) || exit;

/**
 * Stored order, and the one place it is applied.
 */
final class Order {

	/**
	 * The table, without the prefix.
	 */
	const TABLE = 'oc_order';

	/**
	 * Bumped when the table changes.
	 */
	const SCHEMA = 'oc_order_tables';

	/**
	 * Its version.
	 */
	const SCHEMA_V = '1';

	/**
	 * The mark a category carries when it has an order of its own. Kept in
	 * term meta so the front end can ask without a query: term meta is
	 * already in the cache by the time the archive renders.
	 */
	const MARK = '_oc_order';

	/**
	 * The shop's own order — everything, in one list — is filed under this
	 * instead of a category id.
	 */
	const SHOP = 0;

	/**
	 * The mark for the shop-wide order, which has no term to carry it.
	 */
	const SHOP_MARK = 'oc_order_shop';

	/**
	 * Hook in.
	 */
	public function register(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		add_action( 'pre_get_posts', array( $this, 'watch' ), 20 );
		add_action( 'before_delete_post', array( $this, 'forget_product' ) );
		add_action( 'delete_product_cat', array( $this, 'forget_term' ) );
	}

	/* ------------------------------------------------------------- store */

	/**
	 * The table name.
	 */
	public static function table(): string {
		global $wpdb;

		return $wpdb->prefix . self::TABLE;
	}

	/**
	 * Create the table, once.
	 */
	public static function install(): void {
		if ( self::SCHEMA_V === (string) get_option( self::SCHEMA, '' ) ) {
			return;
		}

		global $wpdb;

		$t       = self::table();
		$collate = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta(
			"CREATE TABLE {$t} (
				term_id bigint(20) unsigned NOT NULL DEFAULT 0,
				product_id bigint(20) unsigned NOT NULL,
				pos int(10) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY (term_id, product_id),
				KEY term_pos (term_id, pos)
			) {$collate};"
		);

		update_option( self::SCHEMA, self::SCHEMA_V, false );
	}

	/**
	 * Whether a category — or the shop — has an order of its own. Free to
	 * ask: term meta is already loaded with the term.
	 *
	 * @param int $term_id Category, or SHOP.
	 */
	public static function has( int $term_id ): bool {
		if ( self::SHOP === $term_id ) {
			return '1' === (string) get_option( self::SHOP_MARK, '' );
		}

		return '1' === (string) get_term_meta( $term_id, self::MARK, true );
	}

	/**
	 * Say so, or stop saying so.
	 *
	 * @param int  $term_id Category, or SHOP.
	 * @param bool $on      Whether there is an order.
	 */
	private static function mark( int $term_id, bool $on ): void {
		if ( self::SHOP === $term_id ) {
			if ( $on ) {
				update_option( self::SHOP_MARK, '1', true );
			} else {
				delete_option( self::SHOP_MARK );
			}

			return;
		}

		if ( $on ) {
			update_term_meta( $term_id, self::MARK, '1' );
		} else {
			delete_term_meta( $term_id, self::MARK );
		}
	}

	/**
	 * Write a run of positions. The ids are a window of the list, and the
	 * first of them sits at $from — so a page of a long category can be
	 * saved without touching the pages either side of it.
	 *
	 * @param int   $term_id Category, or SHOP.
	 * @param int[] $ids     Product ids, in the order they should appear.
	 * @param int   $from    The position the first id takes.
	 */
	public static function save( int $term_id, array $ids, int $from = 0 ): int {
		$ids = array_values( array_unique( array_map( 'absint', $ids ) ) );
		$ids = array_values( array_filter( $ids ) );

		if ( ! $ids ) {
			return 0;
		}

		global $wpdb;

		$t    = self::table();
		$rows = array();
		$args = array();

		foreach ( $ids as $i => $id ) {
			$rows[] = '(%d, %d, %d)';
			array_push( $args, $term_id, $id, $from + $i );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders -- every value is prepared above; the placeholders sit inside the row list.
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$t} (term_id, product_id, pos) VALUES " . implode( ',', $rows ) . ' ON DUPLICATE KEY UPDATE pos = VALUES(pos)', $args ) );

		self::mark( $term_id, true );

		return count( $ids );
	}

	/**
	 * Throw a category's order away and go back to the shop's own.
	 *
	 * @param int $term_id Category, or SHOP.
	 */
	public static function clear( int $term_id ): void {
		global $wpdb;

		$t = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$t} WHERE term_id = %d", $term_id ) );

		self::mark( $term_id, false );
	}

	/**
	 * A product that is gone takes its places with it.
	 *
	 * @param int $post_id Post.
	 */
	public function forget_product( $post_id ): void {
		if ( 'product' !== get_post_type( (int) $post_id ) ) {
			return;
		}

		global $wpdb;

		$t = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$t} WHERE product_id = %d", (int) $post_id ) );
	}

	/**
	 * So does a category.
	 *
	 * @param int $term_id Term.
	 */
	public function forget_term( $term_id ): void {
		self::clear( (int) $term_id );
	}

	/* ------------------------------------------------------- the front end */

	/**
	 * Which list the page being looked at is ordered by, or 0 for none.
	 * Reads the query and the term cache, and nothing else.
	 *
	 * @param \WP_Query $q The query.
	 * @return int|null Term id, SHOP, or null for "leave it alone".
	 */
	private function which( $q ) {
		if ( is_admin() || ! $q instanceof \WP_Query || ! $q->is_main_query() ) {
			return null;
		}

		// A shopper who has chosen an order of their own has the last word.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading the shopper's own choice of ordering.
		if ( ! empty( $_GET['orderby'] ) ) {
			return null;
		}

		if ( function_exists( 'is_product_category' ) && $q->is_tax( 'product_cat' ) ) {
			$term = $q->get_queried_object();

			if ( $term instanceof \WP_Term && self::has( (int) $term->term_id ) ) {
				return (int) $term->term_id;
			}

			return null;
		}

		if ( function_exists( 'is_shop' ) && $q->is_post_type_archive( 'product' ) && self::has( self::SHOP ) ) {
			return self::SHOP;
		}

		return null;
	}

	/**
	 * Put the shop's own order on the catalogue, when there is one.
	 *
	 * @param \WP_Query $q The query.
	 */
	public function watch( $q ): void {
		$list = $this->which( $q );

		if ( null === $list ) {
			return;
		}

		$this->list = $list;

		add_filter( 'posts_clauses', array( $this, 'clauses' ), 20, 2 );
	}

	/**
	 * Which list this request is being ordered by.
	 *
	 * @var int
	 */
	private $list = 0;

	/**
	 * The join and the ordering. One LEFT JOIN on a two-column primary key,
	 * and products without a place of their own fall in behind the ones
	 * that have one, in the order they would have been in anyway.
	 *
	 * @param array     $clauses Clauses.
	 * @param \WP_Query $q       Query.
	 * @return array
	 */
	public function clauses( $clauses, $q ) {
		if ( ! $q instanceof \WP_Query || ! $q->is_main_query() ) {
			return $clauses;
		}

		remove_filter( 'posts_clauses', array( $this, 'clauses' ), 20 );

		global $wpdb;

		$t   = self::table();
		$was = trim( (string) ( $clauses['orderby'] ?? '' ) );

		$clauses['join']   .= $wpdb->prepare( " LEFT JOIN {$t} AS ocord ON ocord.product_id = {$wpdb->posts}.ID AND ocord.term_id = %d", $this->list ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table.
		$clauses['orderby'] = 'ocord.pos IS NULL ASC, ocord.pos ASC' . ( '' === $was ? '' : ', ' . $was );

		return $clauses;
	}

	/* ---------------------------------------------------------- reading */

	/**
	 * The places a category already holds, as product id => position.
	 *
	 * @param int $term_id Category, or SHOP.
	 * @return array<int,int>
	 */
	public static function places( int $term_id ): array {
		global $wpdb;

		$t = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT product_id, pos FROM {$t} WHERE term_id = %d", $term_id ), ARRAY_A );

		$out = array();

		foreach ( $rows as $r ) {
			$out[ (int) $r['product_id'] ] = (int) $r['pos'];
		}

		return $out;
	}

	/**
	 * How many products a list holds a place for.
	 *
	 * @param int $term_id Category, or SHOP.
	 */
	public static function count( int $term_id ): int {
		global $wpdb;

		$t = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE term_id = %d", $term_id ) );
	}
}
