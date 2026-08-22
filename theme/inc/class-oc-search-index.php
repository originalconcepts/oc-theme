<?php
/**
 * The search index: one row per product, one row per word.
 *
 * A listing search has to answer while someone is still typing, which rules
 * out `LIKE '%word%'` — a leading wildcard cannot use an index, so every
 * query becomes a table scan. It also rules out MySQL's own full-text index:
 * its default settings drop two-letter words and it has no idea that "בעגבניות"
 * and "עגבניות" are the same word, and neither is ours to change on shared
 * hosting.
 *
 * So the theme keeps its own words. Every product is broken into tokens when
 * it is saved, each token is stored normalised, and a search becomes an index
 * range — `token LIKE 'עגב%'` — which is the one shape MySQL is fastest at and
 * happens to be exactly what typing produces.
 *
 * @package OC_Theme
 */

declare( strict_types = 1 );

namespace OC\Theme;

defined( 'ABSPATH' ) || exit;

/**
 * Building and holding the words.
 */
final class Search_Index {

	/**
	 * Where a word came from. The number is stored, so these never change.
	 */
	const F_TITLE = 1;
	const F_SKU   = 2;
	const F_CAT   = 3;
	const F_ATTR  = 4;
	const F_TAG   = 5;
	const F_DESC  = 6;
	const F_SYN   = 7;
	const F_BRAND = 8;
	const F_POST  = 9;

	/**
	 * Words that carry no meaning in a product search.
	 *
	 * @var string[]
	 */
	private const STOP = array(
		'של', 'את', 'עם', 'על', 'זה', 'הוא', 'היא', 'גם', 'או', 'אבל', 'כל',
		'and', 'the', 'for', 'with', 'from',
	);

	/**
	 * The products table.
	 */
	public static function table(): string {
		global $wpdb;

		return $wpdb->prefix . 'oc_search';
	}

	/**
	 * The words table.
	 */
	public static function words(): string {
		global $wpdb;

		return $wpdb->prefix . 'oc_search_word';
	}

	/**
	 * The searches people ran.
	 */
	public static function log(): string {
		global $wpdb;

		return $wpdb->prefix . 'oc_search_log';
	}

	/* ------------------------------------------------------------- schema */

	/**
	 * Create or update the tables.
	 */
	public static function install(): void {
		global $wpdb;

		// Not dbDelta: it parses the statement itself and is exacting about
		// whitespace in ways that fail silently. These tables are the theme's
		// own and are created once, so the plain statement is both clearer
		// and certain.
		$charset = $wpdb->get_charset_collate();
		$t       = self::table();
		$w       = self::words();
		$l       = self::log();

		$wpdb->query( // phpcs:ignore WordPress.DB
			"CREATE TABLE IF NOT EXISTS {$t} (
				object_id BIGINT UNSIGNED NOT NULL,
				kind VARCHAR(12) NOT NULL DEFAULT 'product',
				title TEXT NOT NULL,
				title_n VARCHAR(191) NOT NULL DEFAULT '',
				price DECIMAL(16,4) NOT NULL DEFAULT 0,
				in_stock TINYINT(1) NOT NULL DEFAULT 1,
				sales INT UNSIGNED NOT NULL DEFAULT 0,
				views INT UNSIGNED NOT NULL DEFAULT 0,
				boost SMALLINT NOT NULL DEFAULT 0,
				hidden TINYINT(1) NOT NULL DEFAULT 0,
				brand_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				updated INT UNSIGNED NOT NULL DEFAULT 0,
				PRIMARY KEY (object_id),
				KEY kind_stock (kind, hidden, in_stock),
				KEY title_n (title_n(48))
			) {$charset}"
		);

		$wpdb->query( // phpcs:ignore WordPress.DB
			"CREATE TABLE IF NOT EXISTS {$w} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				token VARCHAR(48) NOT NULL,
				object_id BIGINT UNSIGNED NOT NULL,
				kind VARCHAR(12) NOT NULL DEFAULT 'product',
				field TINYINT UNSIGNED NOT NULL DEFAULT 1,
				pos SMALLINT UNSIGNED NOT NULL DEFAULT 0,
				PRIMARY KEY (id),
				KEY token_obj (token(24), object_id),
				KEY obj (object_id)
			) {$charset}"
		);

		$wpdb->query( // phpcs:ignore WordPress.DB
			"CREATE TABLE IF NOT EXISTS {$l} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				day DATE NOT NULL,
				term VARCHAR(120) NOT NULL,
				hits INT UNSIGNED NOT NULL DEFAULT 0,
				searches INT UNSIGNED NOT NULL DEFAULT 0,
				clicks INT UNSIGNED NOT NULL DEFAULT 0,
				PRIMARY KEY (id),
				UNIQUE KEY day_term (day, term),
				KEY term (term)
			) {$charset}"
		);

		update_option( 'oc_search_schema', 3, false );
	}

	/**
	 * Create the tables the first time they are needed.
	 */
	public static function maybe_install(): void {
		if ( 3 !== (int) get_option( 'oc_search_schema' ) ) {
			self::install();
		}
	}

	/* -------------------------------------------------------- normalising */

	/**
	 * One spelling for one word.
	 *
	 * Everything that reaches the index and everything typed into the box
	 * passes through here, so the two always agree. Hebrew needs the work:
	 * a final letter is the same letter, an apostrophe is decoration, and a
	 * word wearing a one-letter prefix is still the same word.
	 *
	 * @param string $text Raw text.
	 */
	public static function normalise( string $text ): string {
		$text = wp_strip_all_tags( $text );
		$text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );

		// Vowel points and cantillation carry no search meaning.
		$text = preg_replace( '/[\x{0591}-\x{05C7}]/u', '', $text );

		// Every flavour of apostrophe and quote reads as the same mark.
		$text = str_replace(
			array( '״', '"', '”', '“', '׳', "'", '’', '‘', '`' ),
			array( '"', '"', '"', '"', "'", "'", "'", "'", "'" ),
			(string) $text
		);

		$text = mb_strtolower( $text, 'UTF-8' );

		// A final letter is the same letter.
		$text = strtr(
			$text,
			array(
				'ם' => 'מ',
				'ן' => 'נ',
				'ץ' => 'צ',
				'ף' => 'פ',
				'ך' => 'כ',
			)
		);

		// Anything that is not a letter or a digit separates words.
		$text = preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $text );

		return trim( (string) preg_replace( '/\s+/u', ' ', (string) $text ) );
	}

	/**
	 * The words in a piece of text.
	 *
	 * A Hebrew word often arrives wearing one of ו ה ב ל מ ש כ. The stem is
	 * stored beside it, so "בעגבניות" and "עגבניות" find each other — and the
	 * same happens to the query, so it works from either side.
	 *
	 * @param string $text  Raw text.
	 * @param int    $limit Stop after this many words. 0 = no limit.
	 * @return string[]
	 */
	public static function tokens( string $text, int $limit = 0 ): array {
		$flat = self::normalise( $text );

		if ( '' === $flat ) {
			return array();
		}

		$out  = array();
		$seen = array();

		foreach ( explode( ' ', $flat ) as $word ) {
			if ( mb_strlen( $word, 'UTF-8' ) < 2 || in_array( $word, self::STOP, true ) ) {
				continue;
			}

			$word = mb_substr( $word, 0, 48, 'UTF-8' );

			foreach ( self::stems( $word ) as $stem ) {
				if ( isset( $seen[ $stem ] ) ) {
					continue;
				}

				$seen[ $stem ] = true;
				$out[]         = $stem;

				if ( $limit && count( $out ) >= $limit ) {
					return $out;
				}
			}
		}

		return $out;
	}

	/**
	 * A word, and the same word without its Hebrew prefix.
	 *
	 * @param string $word Normalised word.
	 * @return string[]
	 */
	public static function stems( string $word ): array {
		$out = array( $word );

		if ( mb_strlen( $word, 'UTF-8' ) >= 4 && preg_match( '/^[והבלמשכ]/u', $word ) ) {
			$out[] = mb_substr( $word, 1, null, 'UTF-8' );
		}

		// "כשה", "ולה" — two stacked prefixes happen often enough to be worth it.
		if ( mb_strlen( $word, 'UTF-8' ) >= 5 && preg_match( '/^[וש][הבלמכ]/u', $word ) ) {
			$out[] = mb_substr( $word, 2, null, 'UTF-8' );
		}

		return $out;
	}

	/* ---------------------------------------------------------- synonyms */

	/**
	 * The shop-wide synonym list, as canonical => variants.
	 *
	 * @return array<string,string[]>
	 */
	public static function global_synonyms(): array {
		$raw = get_option( 'oc_search_synonyms', '' );
		$out = array();

		foreach ( preg_split( '/\R/u', (string) $raw ) ?: array() as $line ) {
			$line = trim( (string) $line );

			if ( '' === $line || 0 === strpos( $line, '#' ) ) {
				continue;
			}

			// "canonical = a, b, c" — or just a comma list, all of it equal.
			$parts = array_map( 'trim', preg_split( '/[=,]/u', $line ) ?: array() );
			$parts = array_values( array_filter( $parts, 'strlen' ) );

			if ( count( $parts ) < 2 ) {
				continue;
			}

			$head = array_shift( $parts );

			$out[ $head ] = $parts;
		}

		return $out;
	}

	/**
	 * Every word a phrase should also answer to.
	 *
	 * @param string $phrase Source phrase.
	 * @return string[]
	 */
	public static function expand( string $phrase ): array {
		$flat = self::normalise( $phrase );

		if ( '' === $flat ) {
			return array();
		}

		$out = array();

		foreach ( self::global_synonyms() as $head => $variants ) {
			$head_flat = self::normalise( (string) $head );

			if ( '' === $head_flat ) {
				continue;
			}

			$all = array_merge( array( $head_flat ), array_map( array( self::class, 'normalise' ), $variants ) );

			if ( ! in_array( $flat, $all, true ) && false === mb_strpos( ' ' . $flat . ' ', ' ' . $head_flat . ' ' ) ) {
				continue;
			}

			$out = array_merge( $out, $all );
		}

		return $out;
	}

	/**
	 * Synonyms typed against a term (a category, a colour, a tag).
	 *
	 * @param int $term_id Term id.
	 * @return string[]
	 */
	public static function term_synonyms( int $term_id ): array {
		$raw = (string) get_term_meta( $term_id, '_oc_syn', true );

		return array_values( array_filter( array_map( 'trim', explode( ',', $raw ) ), 'strlen' ) );
	}

	/**
	 * Synonyms typed against one product.
	 *
	 * @param int $product_id Product id.
	 * @return string[]
	 */
	public static function product_synonyms( int $product_id ): array {
		$raw = (string) get_post_meta( $product_id, '_oc_syn', true );

		return array_values( array_filter( array_map( 'trim', explode( ',', $raw ) ), 'strlen' ) );
	}

	/* ---------------------------------------------------------- indexing */

	/**
	 * Rewrite one product's words.
	 *
	 * @param int $product_id Product id.
	 */
	public static function index_product( int $product_id ): void {
		global $wpdb;

		self::maybe_install();

		$product = wc_get_product( $product_id );

		if ( ! $product instanceof \WC_Product || 'publish' !== get_post_status( $product_id ) ) {
			self::forget( $product_id );

			return;
		}

		if ( $product->get_parent_id() ) {
			self::index_product( $product->get_parent_id() );
			self::forget( $product_id );

			return;
		}

		$s      = Search::settings();
		$bag    = array();
		$syn    = array();
		$brand  = 0;

		self::add( $bag, self::F_TITLE, $product->get_name() );
		$syn = array_merge( $syn, self::expand( $product->get_name() ), self::product_synonyms( $product_id ) );

		if ( ! empty( $s['f_sku'] ) ) {
			self::add( $bag, self::F_SKU, (string) $product->get_sku() );

			// A shopper who knows a variation's code still means this product.
			if ( $product->is_type( 'variable' ) ) {
				foreach ( $product->get_children() as $child_id ) {
					$child = wc_get_product( $child_id );

					if ( $child ) {
						self::add( $bag, self::F_SKU, (string) $child->get_sku() );
					}
				}
			}
		}

		if ( ! empty( $s['f_desc'] ) ) {
			self::add( $bag, self::F_DESC, $product->get_short_description(), 60 );
			self::add( $bag, self::F_DESC, $product->get_description(), 120 );
		}

		foreach ( wp_get_post_terms( $product_id, 'product_cat', array( 'fields' => 'all' ) ) as $term ) {
			self::add( $bag, self::F_CAT, $term->name );
			$syn = array_merge( $syn, self::term_synonyms( (int) $term->term_id ), self::expand( $term->name ) );
		}

		if ( ! empty( $s['f_tag'] ) ) {
			foreach ( wp_get_post_terms( $product_id, 'product_tag', array( 'fields' => 'all' ) ) as $term ) {
				self::add( $bag, self::F_TAG, $term->name );
				$syn = array_merge( $syn, self::term_synonyms( (int) $term->term_id ) );
			}
		}

		$brand_tax = Search::brand_taxonomy();

		if ( $brand_tax ) {
			foreach ( wp_get_post_terms( $product_id, $brand_tax, array( 'fields' => 'all' ) ) as $term ) {
				self::add( $bag, self::F_BRAND, $term->name );
				$syn     = array_merge( $syn, self::term_synonyms( (int) $term->term_id ) );
				$brand   = $brand ?: (int) $term->term_id;
			}
		}

		if ( ! empty( $s['f_attr'] ) ) {
			foreach ( $product->get_attributes() as $attribute ) {
				if ( ! $attribute instanceof \WC_Product_Attribute ) {
					continue;
				}

				if ( $attribute->is_taxonomy() ) {
					foreach ( $attribute->get_terms() ?: array() as $term ) {
						self::add( $bag, self::F_ATTR, $term->name );
						$syn = array_merge( $syn, self::term_synonyms( (int) $term->term_id ), self::expand( $term->name ) );
					}
				} else {
					foreach ( $attribute->get_options() as $option ) {
						self::add( $bag, self::F_ATTR, (string) $option );
						$syn = array_merge( $syn, self::expand( (string) $option ) );
					}
				}
			}
		}

		foreach ( array_unique( $syn ) as $word ) {
			self::add( $bag, self::F_SYN, (string) $word );
		}

		$wpdb->delete( self::words(), array( 'object_id' => $product_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		self::write_words( $product_id, 'product', $bag );

		$wpdb->replace( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table(),
			array(
				'object_id' => $product_id,
				'kind'      => 'product',
				'title'     => $product->get_name(),
				'title_n'   => mb_substr( self::normalise( $product->get_name() ), 0, 191, 'UTF-8' ),
				'price'     => (float) $product->get_price(),
				'in_stock'  => $product->is_in_stock() ? 1 : 0,
				'sales'     => (int) get_post_meta( $product_id, 'total_sales', true ),
				'views'     => (int) get_post_meta( $product_id, '_oc_views', true ),
				'boost'     => (int) get_post_meta( $product_id, '_oc_search_boost', true ),
				'hidden'    => get_post_meta( $product_id, '_oc_search_hide', true ) ? 1 : 0,
				'brand_id'  => $brand,
				'updated'   => time(),
			),
			array( '%d', '%s', '%s', '%s', '%f', '%d', '%d', '%d', '%d', '%d', '%d', '%d' )
		);
	}

	/**
	 * Rewrite one post or page.
	 *
	 * @param int $post_id Post id.
	 */
	public static function index_post( int $post_id ): void {
		global $wpdb;

		self::maybe_install();

		$post = get_post( $post_id );
		$s    = Search::settings();
		$kind = $post ? $post->post_type : '';

		$wanted = array_filter(
			array(
				! empty( $s['f_posts'] ) ? 'post' : '',
				! empty( $s['f_pages'] ) ? 'page' : '',
			)
		);

		if ( ! $post || 'publish' !== $post->post_status || ! in_array( $kind, $wanted, true ) ) {
			self::forget( $post_id );

			return;
		}

		$bag = array();

		self::add( $bag, self::F_TITLE, $post->post_title );
		self::add( $bag, self::F_POST, $post->post_excerpt, 40 );
		self::add( $bag, self::F_POST, $post->post_content, 120 );

		$wpdb->delete( self::words(), array( 'object_id' => $post_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		self::write_words( $post_id, $kind, $bag );

		$wpdb->replace( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table(),
			array(
				'object_id' => $post_id,
				'kind'      => $kind,
				'title'     => $post->post_title,
				'title_n'   => mb_substr( self::normalise( $post->post_title ), 0, 191, 'UTF-8' ),
				'price'     => 0,
				'in_stock'  => 1,
				'sales'     => 0,
				'views'     => 0,
				'boost'     => 0,
				'hidden'    => 0,
				'brand_id'  => 0,
				'updated'   => time(),
			),
			array( '%d', '%s', '%s', '%s', '%f', '%d', '%d', '%d', '%d', '%d', '%d', '%d' )
		);
	}

	/**
	 * Drop everything we hold about one object.
	 *
	 * @param int $object_id Post id.
	 */
	public static function forget( int $object_id ): void {
		global $wpdb;

		$wpdb->delete( self::words(), array( 'object_id' => $object_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( self::table(), array( 'object_id' => $object_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Collect words from one piece of text into the bag.
	 *
	 * @param array<string,array{field:int,pos:int}> $bag   Bag, by token.
	 * @param int                                    $field Which field.
	 * @param string                                 $text  Text.
	 * @param int                                    $limit Word cap.
	 */
	private static function add( array &$bag, int $field, string $text, int $limit = 0 ): void {
		if ( '' === trim( $text ) ) {
			return;
		}

		$pos = 0;

		foreach ( self::tokens( $text, $limit ) as $token ) {
			++$pos;

			// A word already seen in a stronger field keeps that field.
			if ( isset( $bag[ $token ] ) && $bag[ $token ]['field'] <= $field ) {
				continue;
			}

			$bag[ $token ] = array(
				'field' => $field,
				'pos'   => min( 255, $pos ),
			);
		}
	}

	/**
	 * Write a bag of words in one statement.
	 *
	 * @param int                                    $object_id Object.
	 * @param string                                 $kind      product | post | page.
	 * @param array<string,array{field:int,pos:int}> $bag       Words.
	 */
	private static function write_words( int $object_id, string $kind, array $bag ): void {
		global $wpdb;

		if ( ! $bag ) {
			return;
		}

		$rows   = array();
		$values = array();

		foreach ( $bag as $token => $meta ) {
			$rows[]   = '(%s, %d, %s, %d, %d)';
			$values[] = (string) $token;
			$values[] = $object_id;
			$values[] = $kind;
			$values[] = $meta['field'];
			$values[] = $meta['pos'];
		}

		$sql = 'INSERT INTO ' . self::words() . ' (token, object_id, kind, field, pos) VALUES ' . implode( ',', $rows );

		$wpdb->query( $wpdb->prepare( $sql, $values ) ); // phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery
	}

	/* ----------------------------------------------------------- rebuild */

	/**
	 * Everything that belongs in the index, in id order.
	 *
	 * @return int[]
	 */
	public static function all_ids(): array {
		$s     = Search::settings();
		$types = array( 'product' );

		if ( ! empty( $s['f_posts'] ) ) {
			$types[] = 'post';
		}

		if ( ! empty( $s['f_pages'] ) ) {
			$types[] = 'page';
		}

		return get_posts(
			array(
				'post_type'      => $types,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			)
		);
	}

	/**
	 * Index one batch. Returns how many are left.
	 *
	 * @param int $size How many to do now.
	 */
	public static function rebuild_batch( int $size = 40 ): array {
		self::maybe_install();

		$queue = get_option( 'oc_search_queue' );

		if ( ! is_array( $queue ) || ! $queue ) {
			$queue = self::all_ids();

			update_option( 'oc_search_total', count( $queue ), false );
		}

		$batch = array_splice( $queue, 0, max( 1, $size ) );

		foreach ( $batch as $id ) {
			if ( 'product' === get_post_type( $id ) ) {
				self::index_product( (int) $id );
			} else {
				self::index_post( (int) $id );
			}
		}

		update_option( 'oc_search_queue', $queue, false );

		if ( ! $queue ) {
			update_option( 'oc_search_built', time(), false );
		}

		return array(
			'left'  => count( $queue ),
			'total' => (int) get_option( 'oc_search_total', 0 ),
		);
	}

	/**
	 * Start a fresh rebuild.
	 */
	public static function rebuild_start(): void {
		global $wpdb;

		self::install();

		$wpdb->query( 'TRUNCATE TABLE ' . self::words() ); // phpcs:ignore WordPress.DB
		$wpdb->query( 'TRUNCATE TABLE ' . self::table() ); // phpcs:ignore WordPress.DB

		delete_option( 'oc_search_queue' );
		delete_option( 'oc_search_built' );
	}

	/**
	 * How the index is doing.
	 *
	 * @return array{objects:int,words:int,left:int,total:int,built:int}
	 */
	public static function status(): array {
		global $wpdb;

		self::maybe_install();

		$queue = get_option( 'oc_search_queue' );

		return array(
			'objects' => (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table() ), // phpcs:ignore WordPress.DB
			'words'   => (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::words() ), // phpcs:ignore WordPress.DB
			'left'    => is_array( $queue ) ? count( $queue ) : 0,
			'total'   => (int) get_option( 'oc_search_total', 0 ),
			'built'   => (int) get_option( 'oc_search_built', 0 ),
		);
	}
}
