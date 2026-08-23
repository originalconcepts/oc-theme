<?php
/**
 * Site search: the engine, the panel and the results page.
 *
 * Two things decide whether a shop search is any good — it has to answer
 * while the shopper is still typing, and it has to answer correctly. The
 * speed comes from {@see Search_Index}, which keeps its own words so a query
 * is an index range rather than a table scan. The accuracy comes from the
 * weights below and from synonyms, which let a product answer to words its
 * own title never carried.
 *
 * @package OC_Theme
 */

declare( strict_types = 1 );

namespace OC\Theme;

defined( 'ABSPATH' ) || exit;

/**
 * Everything the shopper touches.
 */
final class Search {

	/**
	 * Settings, with defaults.
	 *
	 * @return array<string,mixed>
	 */
	public static function settings(): array {
		$saved = get_option( 'oc_search' );

		return wp_parse_args(
			is_array( $saved ) ? $saved : array(),
			array(
				'enabled'   => 1,

				// Where to look.
				'f_sku'     => 1,
				'f_desc'    => 1,
				'f_tag'     => 1,
				'f_attr'    => 1,
				'f_posts'   => 1,
				'f_pages'   => 0,

				// What each field is worth.
				'w_title'   => 10,
				'w_sku'     => 8,
				'w_cat'     => 5,
				'w_attr'    => 4,
				'w_tag'     => 4,
				'w_syn'     => 3,
				'w_desc'    => 1,
				'w_brand'   => 6,

				// 0 = relevance only, 100 = popularity only.
				'pop_mix'   => 30,

				// sink | hide | normal.
				'oos'       => 'sink',

				// Popular searches.
				'pop_days'  => 7,
				'pop_count' => 6,
				'pop_block' => '',

				// Popular products: manual | sales | searches | random.
				'prod_mode' => 'sales',
				'prod_ids'  => '',
				'prod_count' => 8,

				// Pinned results: one per line, "query = id, id".
				'pinned'    => '',

				// Kindnesses that only run when nothing was found.
				'typo'      => 1,
				'kbd'       => 1,
				'learn'     => 1,
			)
		);
	}

	/**
	 * Appearance lives in the Customizer — set once, per site.
	 *
	 * @param string $key Setting key without the prefix.
	 * @param mixed  $def Default.
	 * @return mixed
	 */
	public static function look( string $key, $def ) {
		return get_theme_mod( 'oc_search_' . $key, $def );
	}

	/**
	 * The brand taxonomy on this site, if any.
	 */
	public static function brand_taxonomy(): string {
		foreach ( array( 'product_brand', 'pwb-brand', 'oc_brand' ) as $tax ) {
			if ( taxonomy_exists( $tax ) ) {
				return $tax;
			}
		}

		return '';
	}

	/**
	 * Hook in.
	 */
	public function register(): void {
		add_action( 'wp_ajax_oc_search', array( $this, 'ajax' ) );
		add_action( 'wp_ajax_nopriv_oc_search', array( $this, 'ajax' ) );

		// The panel asks through the front door rather than admin-ajax: the
		// answer is the same and the admin half of WordPress never loads,
		// which is most of the wait.
		add_action( 'parse_request', array( $this, 'endpoint' ), 1 );
		add_action( 'wp_ajax_oc_add_to_cart', array( $this, 'ajax_add' ) );
		add_action( 'wp_ajax_nopriv_oc_add_to_cart', array( $this, 'ajax_add' ) );

		add_action( 'save_post_product', array( $this, 'touch_product' ), 20 );
		add_action( 'woocommerce_update_product', array( $this, 'touch_product' ), 20 );
		add_action( 'save_post_post', array( $this, 'touch_post' ), 20 );
		add_action( 'save_post_page', array( $this, 'touch_post' ), 20 );
		add_action( 'before_delete_post', array( $this, 'drop' ) );
		add_action( 'wp_trash_post', array( $this, 'drop' ) );

		add_action( 'oc_search_rebuild', array( $this, 'cron_rebuild' ) );

		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		add_action( 'pre_get_posts', array( $this, 'results_query' ) );
		add_filter( 'woocommerce_page_title', array( $this, 'results_title' ) );
		add_filter( 'woocommerce_show_page_title', array( $this, 'show_title' ) );
		add_filter( 'oc_page_title', array( $this, 'results_title' ) );
		add_action( 'template_redirect', array( $this, 'log_results_page' ) );
		add_filter( 'woocommerce_catalog_orderby', array( $this, 'orderby_labels' ) );
		add_filter( 'oc_filters_base_ids', array( $this, 'filter_base_ids' ) );
	}

	/* ------------------------------------------------------------ keeping */

	/**
	 * A product changed.
	 *
	 * @param int $product_id Product id.
	 */
	public function touch_product( $product_id ): void {
		if ( wp_is_post_revision( $product_id ) || wp_is_post_autosave( $product_id ) ) {
			return;
		}

		Search_Index::index_product( (int) $product_id );
	}

	/**
	 * A post or page changed.
	 *
	 * @param int $post_id Post id.
	 */
	public function touch_post( $post_id ): void {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		Search_Index::index_post( (int) $post_id );
	}

	/**
	 * Something was deleted.
	 *
	 * @param int $post_id Post id.
	 */
	public function drop( $post_id ): void {
		Search_Index::forget( (int) $post_id );
	}

	/**
	 * The scheduled slice of a rebuild.
	 */
	public function cron_rebuild(): void {
		$state = Search_Index::rebuild_batch( 60 );

		if ( $state['left'] > 0 ) {
			wp_schedule_single_event( time() + 20, 'oc_search_rebuild' );
		}
	}

	/* ------------------------------------------------------------- engine */

	/**
	 * Run a search.
	 *
	 * @param string $query  What was typed.
	 * @param array  $args   kinds, limit, offset, ids_only.
	 * @return array{ids:int[],rows:array,total:int}
	 */
	public static function find( string $query, array $args = array() ): array {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'kinds'  => array( 'product' ),
				'limit'  => 8,
				'offset' => 0,
			)
		);

		// Each entry is one word the shopper typed, with the spellings it may
		// appear as. Every word must be found; any of its spellings will do.
		$groups = array_slice( Search_Index::query_groups( $query ), 0, 6 );

		if ( ! $groups ) {
			return array(
				'ids'   => array(),
				'rows'  => array(),
				'total' => 0,
			);
		}

		$s     = self::settings();
		$table = Search_Index::table();
		$words = Search_Index::words();

		$weights = 'CASE field'
			. $wpdb->prepare( ' WHEN %d THEN %d', Search_Index::F_TITLE, (int) $s['w_title'] )
			. $wpdb->prepare( ' WHEN %d THEN %d', Search_Index::F_SKU, (int) $s['w_sku'] )
			. $wpdb->prepare( ' WHEN %d THEN %d', Search_Index::F_CAT, (int) $s['w_cat'] )
			. $wpdb->prepare( ' WHEN %d THEN %d', Search_Index::F_ATTR, (int) $s['w_attr'] )
			. $wpdb->prepare( ' WHEN %d THEN %d', Search_Index::F_TAG, (int) $s['w_tag'] )
			. $wpdb->prepare( ' WHEN %d THEN %d', Search_Index::F_SYN, (int) $s['w_syn'] )
			. $wpdb->prepare( ' WHEN %d THEN %d', Search_Index::F_BRAND, (int) $s['w_brand'] )
			. $wpdb->prepare( ' WHEN %d THEN %d', Search_Index::F_DESC, (int) $s['w_desc'] )
			. $wpdb->prepare( ' WHEN %d THEN %d', Search_Index::F_POST, (int) $s['w_desc'] )
			. ' ELSE 1 END';

		$kinds_in = implode( ',', array_fill( 0, count( $args['kinds'] ), '%s' ) );

		$parts  = array();
		$union_values = array();

		foreach ( $groups as $i => $spellings ) {
			$likes = implode( ' OR ', array_fill( 0, count( $spellings ), 'token LIKE %s' ) );

			$parts[]        = "SELECT object_id, %d AS grp, MAX(({$weights}) + IF(pos = 1, 3, 0)) AS score"
				. " FROM {$words} WHERE kind IN ({$kinds_in}) AND ({$likes}) GROUP BY object_id";
			$union_values[] = $i;
			$union_values   = array_merge( $union_values, $args['kinds'] );

			foreach ( $spellings as $spelling ) {
				$union_values[] = $wpdb->esc_like( $spelling ) . '%';
			}
		}

		$union = implode( ' UNION ALL ', $parts );

		// The pull between "what matches" and "what sells" is one dial.
		$mix  = max( 0, min( 100, (int) $s['pop_mix'] ) ) / 100;
		$rel  = 1 - ( $mix * 0.7 );
		$pop  = $mix * 6;
		$sink = 'sink' === $s['oos'] ? 'x.in_stock DESC, ' : '';

		$flat = Search_Index::normalise( $query );

		$sql = "SELECT m.object_id, x.kind, x.title, x.price, x.in_stock, x.sales, x.boost,
				SUM(m.score) AS relevance,
				(SUM(m.score) * %f
					+ (LOG(1 + x.sales) + LOG(1 + x.views)) * %f
					+ x.boost
					+ IF(x.title_n = %s, 30, 0)
					+ IF(x.title_n LIKE %s, 12, 0)
				) AS rank_score
			FROM ({$union}) m
			INNER JOIN {$table} x ON x.object_id = m.object_id
			WHERE x.hidden = 0";

		// The order here is the order the placeholders appear in the statement:
		// the ranking sits in the SELECT clause, so its values bind first.
		$values = array( $rel, $pop, $flat, $wpdb->esc_like( $flat ) . '%' );
		$values = array_merge( $values, $union_values );

		if ( 'hide' === $s['oos'] ) {
			$sql .= ' AND x.in_stock = 1';
		}

		$sql .= ' GROUP BY m.object_id HAVING COUNT(DISTINCT m.grp) = %d';
		$values[] = count( $groups );

		$sql .= " ORDER BY {$sink}rank_score DESC, x.sales DESC, m.object_id ASC";

		// One extra row tells us whether there is a next page, at no cost.
		$sql     .= ' LIMIT %d OFFSET %d';
		$values[] = max( 1, (int) $args['limit'] ) + 1;
		$values[] = max( 0, (int) $args['offset'] );

		$prepared = $wpdb->prepare( $sql, $values ); // phpcs:ignore WordPress.DB.PreparedSQL

		$rows = $wpdb->get_results( $prepared ); // phpcs:ignore WordPress.DB

		$more = count( $rows ) > (int) $args['limit'];
		$rows = array_slice( $rows, 0, (int) $args['limit'] );

		return array(
			'ids'   => array_map( 'intval', wp_list_pluck( $rows, 'object_id' ) ),
			'rows'  => $rows,
			'more'  => $more,
			'total' => 0,
		);
	}

	/**
	 * How many results a query has in total.
	 *
	 * @param string $query Query.
	 * @param array  $kinds Kinds.
	 */
	public static function count( string $query, array $kinds = array( 'product' ) ): int {
		$found = self::find(
			$query,
			array(
				'kinds' => $kinds,
				'limit' => 500,
			)
		);

		return count( $found['ids'] );
	}

	/**
	 * Every product id a query matches, in rank order.
	 *
	 * @param string $query Query.
	 * @return int[]
	 */
	public static function product_ids( string $query ): array {
		$key   = 'oc_s_' . md5( $query . '|' . wp_json_encode( self::settings() ) );
		$found = get_transient( $key );

		if ( is_array( $found ) ) {
			return $found;
		}

		$found = self::find(
			$query,
			array(
				'kinds' => array( 'product' ),
				'limit' => 600,
			)
		);

		$ids = $found['ids'];

		$ids = self::apply_pins( $query, $ids );

		set_transient( $key, $ids, 10 * MINUTE_IN_SECONDS );

		return $ids;
	}

	/**
	 * Put the shop's own choices at the front.
	 *
	 * @param string $query Query.
	 * @param int[]  $ids   Ranked ids.
	 * @return int[]
	 */
	public static function apply_pins( string $query, array $ids ): array {
		$pins = self::pins( $query );

		if ( ! $pins ) {
			return $ids;
		}

		return array_values( array_unique( array_merge( $pins, $ids ) ) );
	}

	/**
	 * Products pinned to this query.
	 *
	 * @param string $query Query.
	 * @return int[]
	 */
	public static function pins( string $query ): array {
		$flat = Search_Index::normalise( $query );

		if ( '' === $flat ) {
			return array();
		}

		$out = array();

		foreach ( preg_split( '/\R/u', (string) self::settings()['pinned'] ) ?: array() as $line ) {
			$line = trim( (string) $line );

			if ( '' === $line || false === strpos( $line, '=' ) ) {
				continue;
			}

			list( $head, $tail ) = array_map( 'trim', explode( '=', $line, 2 ) );

			$head = Search_Index::normalise( $head );

			if ( '' === $head ) {
				continue;
			}

			// A pin fires while the word is still being typed, too.
			if ( 0 !== mb_strpos( $flat, $head ) && 0 !== mb_strpos( $head, $flat ) ) {
				continue;
			}

			foreach ( explode( ',', $tail ) as $id ) {
				$id = absint( trim( $id ) );

				if ( $id && 'publish' === get_post_status( $id ) ) {
					$out[] = $id;
				}
			}
		}

		return array_values( array_unique( $out ) );
	}

	/* ---------------------------------------------------------- kindness */

	/**
	 * A second try, only when the first found nothing.
	 *
	 * @param string $query What was typed.
	 * @return string A different query to try, or an empty string.
	 */
	public static function rescue( string $query ): string {
		$s = self::settings();

		if ( ! empty( $s['kbd'] ) ) {
			$flipped = self::flip_layout( $query );

			if ( $flipped !== $query && Search_Index::tokens( $flipped ) ) {
				$try = self::find( $flipped, array( 'limit' => 1 ) );

				if ( $try['ids'] ) {
					return $flipped;
				}
			}
		}

		if ( ! empty( $s['typo'] ) ) {
			$near = self::nearest( $query );

			if ( '' !== $near ) {
				return $near;
			}
		}

		return '';
	}

	/**
	 * The same keystrokes, read on the other keyboard.
	 *
	 * Someone typing Hebrew with the layout still on English produces
	 * "adcbhu,ץ" for "עגבניות". It happens every day here.
	 *
	 * @param string $text Typed text.
	 */
	public static function flip_layout( string $text ): string {
		// Position by position on the same keys: q sits where / does, x where
		// ס does, and so on. The two strings must stay the same length.
		$en = 'qwertyuiopasdfghjkl;zxcvbnm,./';
		$he = '/\'קראטוןםפשדגכעיחלךףזסבהנמצתץ.';

		$from = preg_match( '/[\x{0590}-\x{05FF}]/u', $text ) ? $he : $en;
		$to   = $from === $he ? $en : $he;

		$out = '';

		foreach ( preg_split( '//u', $text, -1, PREG_SPLIT_NO_EMPTY ) ?: array() as $char ) {
			$at  = mb_strpos( $from, mb_strtolower( $char, 'UTF-8' ), 0, 'UTF-8' );
			$out .= false === $at ? $char : mb_substr( $to, $at, 1, 'UTF-8' );
		}

		return $out;
	}

	/**
	 * The closest word we actually hold.
	 *
	 * Only reached when a search found nothing, so the cost never lands on a
	 * search that worked.
	 *
	 * @param string $query What was typed.
	 */
	public static function nearest( string $query ): string {
		global $wpdb;

		$tokens = Search_Index::tokens( $query );

		if ( ! $tokens ) {
			return '';
		}

		$word = (string) end( $tokens );
		$len  = mb_strlen( $word, 'UTF-8' );

		if ( $len < 3 ) {
			return '';
		}

		// Candidates that share the first two letters — a wide enough net for
		// one wrong letter, and still an index range.
		$stub = mb_substr( $word, 0, 2, 'UTF-8' );

		$candidates = $wpdb->get_col( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				'SELECT DISTINCT token FROM ' . Search_Index::words()
					. ' WHERE token LIKE %s AND CHAR_LENGTH(token) BETWEEN %d AND %d LIMIT 200',
				$wpdb->esc_like( $stub ) . '%',
				max( 2, $len - 1 ),
				$len + 1
			)
		);

		$best     = '';
		$distance = 2;

		foreach ( $candidates as $candidate ) {
			$d = levenshtein( $word, (string) $candidate );

			if ( $d > 0 && $d < $distance ) {
				$distance = $d;
				$best     = (string) $candidate;
			}
		}

		if ( '' === $best ) {
			return '';
		}

		array_pop( $tokens );
		$tokens[] = $best;

		return implode( ' ', $tokens );
	}

	/* ------------------------------------------------------------ logging */

	/**
	 * Remember that someone searched — the word and the day, nothing else.
	 *
	 * @param string $term  What was typed.
	 * @param int    $hits  How many results came back.
	 * @param bool   $click Whether this records a click rather than a search.
	 */
	public static function remember( string $term, int $hits, bool $click = false ): void {
		global $wpdb;

		$flat = Search_Index::normalise( $term );

		if ( '' === $flat || mb_strlen( $flat, 'UTF-8' ) < 2 ) {
			return;
		}

		Search_Index::maybe_install();

		$table = Search_Index::log();
		$day   = gmdate( 'Y-m-d' );

		$wpdb->query( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"INSERT INTO {$table} (day, term, hits, searches, clicks) VALUES (%s, %s, %d, %d, %d)
				 ON DUPLICATE KEY UPDATE hits = VALUES(hits), searches = searches + %d, clicks = clicks + %d",
				$day,
				mb_substr( $flat, 0, 120, 'UTF-8' ),
				$hits,
				$click ? 0 : 1,
				$click ? 1 : 0,
				$click ? 0 : 1,
				$click ? 1 : 0
			)
		);
	}

	/**
	 * What people have been searching for.
	 *
	 * @param int  $days  How far back.
	 * @param int  $limit How many.
	 * @param bool $empty Only the ones that found nothing.
	 * @return array<int,object>
	 */
	public static function popular_terms( int $days = 7, int $limit = 8, bool $empty = false ): array {
		global $wpdb;

		Search_Index::maybe_install();

		$table = Search_Index::log();
		$from  = gmdate( 'Y-m-d', time() - max( 1, $days ) * DAY_IN_SECONDS );
		$where = $empty ? ' AND hits = 0' : ' AND hits > 0';

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"SELECT term, SUM(searches) AS searches, SUM(clicks) AS clicks, MAX(hits) AS hits
				 FROM {$table} WHERE day >= %s {$where}
				 GROUP BY term ORDER BY searches DESC LIMIT %d",
				$from,
				max( 1, $limit ) * 3
			)
		);

		$blocked = array_filter( array_map( 'trim', explode( ',', (string) self::settings()['pop_block'] ) ) );
		$blocked = array_map( array( Search_Index::class, 'normalise' ), $blocked );

		$out = array();

		foreach ( $rows as $row ) {
			if ( in_array( $row->term, $blocked, true ) ) {
				continue;
			}

			$out[] = $row;

			if ( count( $out ) >= $limit ) {
				break;
			}
		}

		return $out;
	}

	/* -------------------------------------------------------- the results */

	/**
	 * Products to show before anything has been typed.
	 *
	 * @return int[]
	 */
	public static function popular_products(): array {
		$s     = self::settings();
		$count = max( 1, (int) $s['prod_count'] );
		$mode  = (string) $s['prod_mode'];

		if ( 'manual' === $mode ) {
			$ids = array_filter( array_map( 'absint', preg_split( '/[\s,]+/', (string) $s['prod_ids'] ) ?: array() ) );
			$ids = array_values( array_filter( $ids, static fn( $id ) => 'publish' === get_post_status( $id ) ) );

			if ( $ids ) {
				return array_slice( $ids, 0, $count );
			}
		}

		if ( 'searches' === $mode ) {
			$ids = array();

			foreach ( self::popular_terms( (int) $s['pop_days'], 6 ) as $row ) {
				foreach ( array_slice( self::product_ids( $row->term ), 0, 2 ) as $id ) {
					$ids[] = $id;
				}
			}

			$ids = array_values( array_unique( $ids ) );

			if ( $ids ) {
				return array_slice( $ids, 0, $count );
			}
		}

		$args = array(
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => $count,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		);

		if ( 'sales' === $mode ) {
			$args['meta_key'] = 'total_sales'; // phpcs:ignore WordPress.DB.SlowDBQuery
			$args['orderby']  = 'meta_value_num';
			$args['order']    = 'DESC';
		} else {
			$args['orderby'] = 'rand';
		}

		$ids = get_posts( $args );

		// Nothing has sold yet: better a shelf than an empty panel.
		if ( ! $ids ) {
			$args['orderby'] = 'date';
			unset( $args['meta_key'] );
			$ids = get_posts( $args );
		}

		return array_map( 'intval', $ids );
	}

	/**
	 * Terms that match, for the narrow column.
	 *
	 * @param string $query    Query.
	 * @param string $taxonomy Taxonomy.
	 * @param int    $limit    How many.
	 * @return array<int,\WP_Term>
	 */
	public static function matching_terms( string $query, string $taxonomy, int $limit = 5 ): array {
		if ( ! $taxonomy || ! taxonomy_exists( $taxonomy ) ) {
			return array();
		}

		$flat = Search_Index::normalise( $query );

		if ( '' === $flat ) {
			return array();
		}

		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => true,
				'number'     => 100,
			)
		);

		if ( is_wp_error( $terms ) ) {
			return array();
		}

		$hits = array();

		foreach ( $terms as $term ) {
			$name = Search_Index::normalise( $term->name );
			$syn  = array_map( array( Search_Index::class, 'normalise' ), Search_Index::term_synonyms( (int) $term->term_id ) );

			foreach ( array_merge( array( $name ), $syn ) as $candidate ) {
				if ( '' !== $candidate && false !== mb_strpos( $candidate, $flat ) ) {
					$hits[] = $term;
					continue 2;
				}
			}
		}

		return array_slice( $hits, 0, $limit );
	}

	/* --------------------------------------------------------- the screen */

	/**
	 * Search results are the catalogue, holding a different set of products.
	 *
	 * @param \WP_Query $query Query.
	 */
	public function results_query( $query ): void {
		if ( is_admin() || ! $query->is_main_query() || ! $query->is_search() ) {
			return;
		}

		$term = (string) $query->get( 's' );

		if ( '' === trim( $term ) ) {
			return;
		}

		$post_type = $query->get( 'post_type' );

		if ( 'product' !== $post_type && ! ( is_array( $post_type ) && in_array( 'product', $post_type, true ) ) ) {
			return;
		}

		$ids = self::product_ids( $term );

		$query->set( 'post__in', $ids ?: array( 0 ) );
		$query->set( 'oc_search_term', $term );

		// The word stays on the query — it is the page's title and its
		// breadcrumb — but WordPress's own LIKE clause is dropped, because
		// the ranking already decided which products these are.
		add_filter( 'posts_search', array( $this, 'drop_like' ), 20, 2 );
		$query->set( 'orderby', 'post__in' );
		$query->set( 'ignore_sticky_posts', true );

		// WooCommerce's own ordering would undo the ranking; only an explicit
		// choice from the shopper is allowed to.
		if ( empty( $_GET['orderby'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			add_filter( 'woocommerce_get_catalog_ordering_args', array( $this, 'keep_rank' ), 20 );
		}
	}

	/**
	 * Replace WordPress's own search clause with nothing.
	 *
	 * @param string    $search Search SQL.
	 * @param \WP_Query $query  Query.
	 * @return string
	 */
	public function drop_like( $search, $query ) {
		if ( $query->is_main_query() && $query->is_search() ) {
			return '';
		}

		return $search;
	}

	/**
	 * Keep the ranking as the order.
	 *
	 * @param array $args Ordering args.
	 * @return array
	 */
	public function keep_rank( $args ) {
		$args['orderby']  = 'post__in';
		$args['order']    = '';
		$args['meta_key'] = ''; // phpcs:ignore WordPress.DB.SlowDBQuery

		return $args;
	}

	/**
	 * Offer "best match" in the sorting menu on a results page.
	 *
	 * @param array $options Options.
	 * @return array
	 */
	public function orderby_labels( $options ) {
		if ( is_search() ) {
			$options = array( 'relevance' => __( 'Best match', 'oc-theme' ) ) + (array) $options;
		}

		return $options;
	}

	/**
	 * The heading over the results.
	 *
	 * @param string $title Current title.
	 * @return string
	 */
	public function results_title( $title ) {
		if ( ! is_search() ) {
			return $title;
		}

		$term = self::current_term();

		if ( '' === $term ) {
			return $title;
		}

		/* translators: %s: what the shopper searched for. */
		return sprintf( __( 'Search results for %s', 'oc-theme' ), $term );
	}

	/**
	 * A results page always names what was searched for.
	 *
	 * @param bool $show Current answer.
	 * @return bool
	 */
	public function show_title( $show ) {
		return is_search() ? true : $show;
	}

	/**
	 * What the shopper searched for on this page.
	 */
	public static function current_term(): string {
		global $wp_query;

		$term = $wp_query instanceof \WP_Query ? (string) $wp_query->get( 'oc_search_term' ) : '';

		if ( '' === $term ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$term = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		}

		return trim( $term );
	}

	/**
	 * Record the search behind a results page.
	 */
	public function log_results_page(): void {
		global $wp_query;

		if ( ! is_search() || is_admin() ) {
			return;
		}

		$term = self::current_term();

		if ( '' !== $term ) {
			self::remember( $term, (int) $wp_query->found_posts );
		}
	}

	/**
	 * The filters count against the search results, not the whole shop.
	 *
	 * @param array|null $ids Current base.
	 * @return array|null
	 */
	public function filter_base_ids( $ids ) {
		if ( ! is_search() ) {
			return $ids;
		}

		$term = self::current_term();

		return '' === $term ? $ids : self::product_ids( $term );
	}

	/* --------------------------------------------------------- endpoint */

	/**
	 * Answer a panel request as early as the request is understood.
	 */
	public function endpoint(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a public search.
		if ( ! isset( $_GET['oc_search'] ) ) {
			return;
		}

		// A repeat of the same question inside a minute is answered by the
		// browser itself, which is the only truly free answer there is.
		header( 'Cache-Control: private, max-age=60' );

		$this->ajax();
	}

	/* ------------------------------------------------------------- ajax */

	/**
	 * The panel asks, this answers.
	 */
	public function ajax(): void {
		$term = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
		$log  = ! empty( $_GET['log'] );

		if ( ! empty( $_GET['click'] ) ) {
			self::remember( $term, 1, true );
			wp_send_json_success( array( 'ok' => 1 ) );
		}

		$min = (int) self::look( 'min', 2 );

		if ( mb_strlen( trim( $term ), 'UTF-8' ) < max( 1, $min ) ) {
			wp_send_json_success( array( 'html' => '', 'empty' => 1 ) );
		}

		$html = Search_Panel::results_html( $term, $log );

		wp_send_json_success( $html );
	}

	/**
	 * Straight into the cart from a result.
	 *
	 * Only a simple, purchasable, in-stock product goes this way; anything
	 * that needs a choice made keeps its link to the product page, so nobody
	 * ends up with a size they did not pick.
	 */
	public function ajax_add(): void {
		$product_id = isset( $_POST['product_id'] ) ? absint( wp_unslash( $_POST['product_id'] ) ) : 0;
		$quantity   = isset( $_POST['quantity'] ) ? max( 1, absint( wp_unslash( $_POST['quantity'] ) ) ) : 1;

		$product = $product_id ? wc_get_product( $product_id ) : null;

		if ( ! $product instanceof \WC_Product || ! $product->is_purchasable() || ! $product->is_in_stock() || ! $product->is_type( 'simple' ) ) {
			wp_send_json_error( array( 'message' => __( 'That product needs a choice made first.', 'oc-theme' ) ) );
		}

		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			wp_send_json_error();
		}

		$added = WC()->cart->add_to_cart( $product_id, $quantity );

		if ( ! $added ) {
			wp_send_json_error( array( 'message' => __( 'It could not be added.', 'oc-theme' ) ) );
		}

		wp_send_json_success(
			array(
				'count' => WC()->cart->get_cart_contents_count(),
				'total' => WC()->cart->get_cart_total(),
			)
		);
	}
}
