<?php
/**
 * OC Redirects — the engine, the data, and the automatic rules.
 *
 * Redirect management inside the theme: a dedicated table, a matcher that
 * runs before the 404 template ever loads, automatic redirects whenever
 * content is deleted or renamed, a 404 journal, and the auto-mapper that
 * marries an old site's address book to the structure already built here.
 *
 * @package OC_Theme
 */

declare( strict_types = 1 );

namespace OC\Theme;

defined( 'ABSPATH' ) || exit;

/**
 * Redirects: engine and data.
 */
final class Redirects {

	/**
	 * Schema version, bumped when the tables change.
	 */
	const DB_VERSION = '1';

	/**
	 * Cache option holding every active rule.
	 */
	const CACHE = 'ocrd_cache';

	/**
	 * Settings option.
	 */
	const SETTINGS = 'ocrd_settings';

	/**
	 * Replacement dictionary option (old prefix => new prefix).
	 */
	const DICTIONARY = 'ocrd_dictionary';

	/**
	 * Hook in.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'maybe_install' ), 1 );
		add_action( 'template_redirect', array( $this, 'route' ), 0 );

		// Automatic rules: content leaving or changing address.
		add_action( 'wp_trash_post', array( $this, 'on_trash' ) );
		add_action( 'untrash_post', array( $this, 'on_untrash' ) );
		add_action( 'post_updated', array( $this, 'on_slug_change' ), 10, 3 );
		add_action( 'pre_delete_term', array( $this, 'on_term_delete' ), 10, 2 );
	}

	/*
	 * --------------------------------------------------------------- schema
	 */

	/**
	 * The redirects table name.
	 */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'ocrd_redirects';
	}

	/**
	 * The 404 journal table name.
	 */
	public static function log_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'ocrd_log404';
	}

	/**
	 * Create or upgrade the tables once per schema version.
	 */
	public function maybe_install(): void {
		if ( self::DB_VERSION === get_option( 'ocrd_db_version' ) ) {
			return;
		}

		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();

		dbDelta(
			'CREATE TABLE ' . self::table() . " (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				source VARCHAR(500) NOT NULL,
				source_key VARCHAR(191) NOT NULL,
				target VARCHAR(500) NOT NULL,
				type SMALLINT NOT NULL DEFAULT 301,
				origin VARCHAR(20) NOT NULL DEFAULT 'manual',
				batch_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				match_rule VARCHAR(40) NOT NULL DEFAULT '',
				confidence VARCHAR(20) NOT NULL DEFAULT '',
				object_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				object_type VARCHAR(40) NOT NULL DEFAULT '',
				hits BIGINT UNSIGNED NOT NULL DEFAULT 0,
				last_hit DATETIME NULL DEFAULT NULL,
				active TINYINT NOT NULL DEFAULT 1,
				note TEXT NULL,
				created_by VARCHAR(60) NOT NULL DEFAULT '',
				created_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY source_key (source_key),
				KEY batch_id (batch_id),
				KEY object_lookup (object_type, object_id)
			) $charset"
		);

		dbDelta(
			'CREATE TABLE ' . self::log_table() . " (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				path VARCHAR(500) NOT NULL,
				path_key VARCHAR(191) NOT NULL,
				referer VARCHAR(500) NOT NULL DEFAULT '',
				hits BIGINT UNSIGNED NOT NULL DEFAULT 1,
				last_hit DATETIME NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY path_key (path_key)
			) $charset"
		);

		update_option( 'ocrd_db_version', self::DB_VERSION );
	}

	/*
	 * ---------------------------------------------------------- normalising
	 */

	/**
	 * A URL of any shape down to the comparable path.
	 *
	 * Absolute or relative in, normalised path out: no scheme, no host, no
	 * trailing slash, no letter case, no UTM noise. The empty string means
	 * the front page.
	 *
	 * @param string $url Raw address.
	 */
	public static function normalize( string $url ): string {
		$url = trim( $url );

		if ( '' === $url ) {
			return '';
		}

		$parts = wp_parse_url( $url );
		$path  = isset( $parts['path'] ) ? (string) $parts['path'] : '/';
		$path  = '/' . ltrim( $path, '/' );
		$path  = untrailingslashit( $path );
		$path  = strtolower( rawurldecode( $path ) );

		$query = array();

		if ( ! empty( $parts['query'] ) ) {
			parse_str( (string) $parts['query'], $query );

			foreach ( array_keys( $query ) as $key ) {
				if ( 0 === strpos( (string) $key, 'utm_' ) || in_array( $key, array( 'fbclid', 'gclid' ), true ) ) {
					unset( $query[ $key ] );
				}
			}
		}

		if ( ! empty( $query ) ) {
			ksort( $query );
			$path .= '?' . http_build_query( $query );
		}

		return '' === $path ? '/' : $path;
	}

	/**
	 * The indexable key for a path: capped for the unique index.
	 *
	 * @param string $path Normalised path.
	 */
	public static function source_key( string $path ): string {
		return strlen( $path ) > 191 ? md5( $path ) : $path;
	}

	/*
	 * -------------------------------------------------------------- routing
	 */

	/**
	 * The whole live rulebook, one option read.
	 *
	 * @return array{exact:array<string,array{t:string,y:int,i:int}>,wild:array<int,array{s:string,t:string,y:int,i:int}>}
	 */
	public static function rules(): array {
		$cache = get_option( self::CACHE, null );

		if ( is_array( $cache ) && isset( $cache['exact'], $cache['wild'] ) ) {
			return $cache;
		}

		return self::rebuild_cache();
	}

	/**
	 * Rebuild the rulebook from the table. Called after every write.
	 *
	 * @return array{exact:array,wild:array}
	 */
	public static function rebuild_cache(): array {
		global $wpdb;

		$rows  = $wpdb->get_results( 'SELECT id, source, target, type FROM ' . self::table() . ' WHERE active = 1', ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$cache = array(
			'exact' => array(),
			'wild'  => array(),
		);

		foreach ( (array) $rows as $row ) {
			$source = (string) $row['source'];

			if ( '*' === substr( $source, -1 ) ) {
				$cache['wild'][] = array(
					's' => rtrim( $source, '*' ),
					't' => (string) $row['target'],
					'y' => (int) $row['type'],
					'i' => (int) $row['id'],
				);
			} else {
				$cache['exact'][ $source ] = array(
					't' => (string) $row['target'],
					'y' => (int) $row['type'],
					'i' => (int) $row['id'],
				);
			}
		}

		// The longest wildcard prefix wins, so sort once here.
		usort(
			$cache['wild'],
			static function ( $a, $b ) {
				return strlen( $b['s'] ) <=> strlen( $a['s'] );
			}
		);

		update_option( self::CACHE, $cache, true );

		return $cache;
	}

	/**
	 * Find the rule for a path: exact first, then the longest wildcard.
	 *
	 * @param string $path Normalised path.
	 * @return array{t:string,y:int,i:int}|null
	 */
	public static function match( string $path ) {
		$rules = self::rules();

		if ( isset( $rules['exact'][ $path ] ) ) {
			return $rules['exact'][ $path ];
		}

		// Also try without the query string — a rule for the bare path
		// still catches the address arriving with parameters.
		$bare = strtok( $path, '?' );

		if ( $bare !== $path && isset( $rules['exact'][ $bare ] ) ) {
			return $rules['exact'][ $bare ];
		}

		foreach ( $rules['wild'] as $rule ) {
			if ( 0 === strpos( $bare . '/', rtrim( $rule['s'], '/' ) . '/' ) ) {
				return $rule;
			}
		}

		return null;
	}

	/**
	 * A redirect wins over a 404, never over a living page.
	 */
	public function route(): void {
		if ( is_admin() || ! is_404() ) {
			return;
		}

		$request = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$path    = self::normalize( $request );
		$rule    = self::match( $path );

		if ( null === $rule ) {
			$this->log_404( $path, isset( $_SERVER['HTTP_REFERER'] ) ? (string) wp_unslash( $_SERVER['HTTP_REFERER'] ) : '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			return;
		}

		$this->count_hit( $rule['i'] );

		if ( 410 === $rule['y'] ) {
			status_header( 410 );
			nocache_headers();
			exit;
		}

		$target = self::to_url( self::resolve_chain( (string) $rule['t'] ) );

		// The visitor's own parameters travel along (UTM already stripped
		// from the comparison, but the original request keeps them all).
		$query = (string) wp_parse_url( $request, PHP_URL_QUERY );

		if ( '' !== $query && '' !== $target && false === strpos( $target, '?' ) ) {
			$target .= '?' . $query;
		}

		if ( '' === $target || self::normalize( $target ) === strtok( $path, '?' ) ) {
			return; // A rule pointing at itself is a rule ignored.
		}

		wp_safe_redirect( $target, 302 === $rule['y'] ? 302 : 301 );
		exit;
	}

	/**
	 * A chain of rules shortens to its final stop.
	 *
	 * @param string $target First target.
	 */
	public static function resolve_chain( string $target ): string {
		$seen = array();

		for ( $hop = 0; $hop < 5; $hop++ ) {
			$path = self::normalize( $target );

			if ( isset( $seen[ $path ] ) ) {
				break; // A circle: stop where we stand.
			}

			$seen[ $path ] = true;
			$next          = self::match( $path );

			if ( null === $next || 410 === $next['y'] ) {
				break;
			}

			$target = (string) $next['t'];
		}

		return $target;
	}

	/**
	 * A stored target into a redirectable URL.
	 *
	 * @param string $target Path or absolute URL.
	 */
	public static function to_url( string $target ): string {
		$target = trim( $target );

		if ( '' === $target ) {
			return '';
		}

		if ( preg_match( '#^https?://#i', $target ) ) {
			return $target;
		}

		if ( '/' === $target ) {
			return home_url( '/' );
		}

		$path = '/' . ltrim( $target, '/' );

		// Land straight on the canonical form — no second hop for the slash.
		if ( false === strpos( $path, '?' ) && false === strpos( basename( $path ), '.' ) ) {
			$path = user_trailingslashit( $path );
		}

		return home_url( $path );
	}

	/**
	 * One more visitor sent onward.
	 *
	 * @param int $id Rule id.
	 */
	private function count_hit( int $id ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . ' SET hits = hits + 1, last_hit = %s WHERE id = %d', current_time( 'mysql' ), $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * A 404 nobody answered, written down.
	 *
	 * @param string $path    Normalised path.
	 * @param string $referer Where the visitor came from.
	 */
	private function log_404( string $path, string $referer ): void {
		$settings = self::settings();

		if ( empty( $settings['log404'] ) ) {
			return;
		}

		// Static files and obvious probe noise stay out of the journal.
		if ( preg_match( '#\.(png|jpe?g|gif|webp|svg|ico|css|js|map|txt|xml|woff2?|ttf|php)(\?|$)#i', $path ) ) {
			return;
		}

		global $wpdb;

		$key = self::source_key( strtok( $path, '?' ) );

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'INSERT INTO ' . self::log_table() . ' (path, path_key, referer, hits, last_hit) VALUES (%s, %s, %s, 1, %s)
				ON DUPLICATE KEY UPDATE hits = hits + 1, last_hit = VALUES(last_hit), referer = VALUES(referer)',
				strtok( $path, '?' ),
				$key,
				substr( $referer, 0, 500 ),
				current_time( 'mysql' )
			)
		);

		// The journal never balloons: entries older than 30 days leave,
		// checked at most once a day.
		if ( false === get_transient( 'ocrd_log_swept' ) ) {
			set_transient( 'ocrd_log_swept', 1, DAY_IN_SECONDS );
			$wpdb->query( 'DELETE FROM ' . self::log_table() . ' WHERE last_hit < DATE_SUB(NOW(), INTERVAL 30 DAY)' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
	}

	/*
	 * -------------------------------------------------------------- writing
	 */

	/**
	 * Settings with their defaults.
	 *
	 * @return array<string,mixed>
	 */
	public static function settings(): array {
		return wp_parse_args(
			(array) get_option( self::SETTINGS, array() ),
			array(
				'log404'        => 1,
				'auto_product'  => 1,
				'auto_term'     => 1,
				'auto_post'     => 1,
				'auto_page'     => 1,
				'auto_slug'     => 1,
				'fixed_product' => '',
				'fixed_term'    => '',
				'fixed_post'    => '',
				'fixed_page'    => '',
			)
		);
	}

	/**
	 * Create or update one rule.
	 *
	 * @param array<string,mixed> $args source, target, type, origin, note, batch_id,
	 *                                  match_rule, confidence, object_id, object_type.
	 * @param bool                $overwrite Replace an existing source's target.
	 * @return int|\WP_Error Rule id.
	 */
	public static function save( array $args, bool $overwrite = true ) {
		global $wpdb;

		$source = self::normalize( (string) ( $args['source'] ?? '' ) );
		$target = trim( (string) ( $args['target'] ?? '' ) );

		if ( '' === $source || '/' === $source && '' === $target ) {
			return new \WP_Error( 'ocrd_empty', __( 'Both addresses are needed.', 'oc-theme' ) );
		}

		if ( '' === $target ) {
			return new \WP_Error( 'ocrd_empty', __( 'Both addresses are needed.', 'oc-theme' ) );
		}

		// Wildcards keep their star; everything else is a plain path.
		$wild = '*' === substr( rtrim( (string) $args['source'] ), -1 );

		if ( $wild ) {
			// Normalise keeps a literal star, so shed it before re-adding.
			$source = untrailingslashit( rtrim( $source, '*/' ) ) . '/*';
		}

		$target_path = 0 === strpos( $target, 'http' ) ? $target : self::normalize( $target );

		if ( ! $wild && $target_path === $source ) {
			return new \WP_Error( 'ocrd_loop', __( 'A rule pointing at itself would spin forever.', 'oc-theme' ) );
		}

		$key      = self::source_key( $source );
		$existing = $wpdb->get_row( $wpdb->prepare( 'SELECT id, origin FROM ' . self::table() . ' WHERE source_key = %s', $key ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		$user = wp_get_current_user();
		$data = array(
			'source'      => $source,
			'source_key'  => $key,
			'target'      => 0 === strpos( $target, 'http' ) ? $target : ( '' === $target_path ? '/' : $target_path ),
			'type'        => in_array( (int) ( $args['type'] ?? 301 ), array( 301, 302, 410 ), true ) ? (int) $args['type'] : 301,
			'origin'      => in_array( (string) ( $args['origin'] ?? 'manual' ), array( 'manual', 'import', 'auto' ), true ) ? (string) $args['origin'] : 'manual',
			'batch_id'    => absint( $args['batch_id'] ?? 0 ),
			'match_rule'  => sanitize_key( (string) ( $args['match_rule'] ?? '' ) ),
			'confidence'  => sanitize_key( (string) ( $args['confidence'] ?? '' ) ),
			'object_id'   => absint( $args['object_id'] ?? 0 ),
			'object_type' => sanitize_key( (string) ( $args['object_type'] ?? '' ) ),
			'active'      => isset( $args['active'] ) ? (int) ! empty( $args['active'] ) : 1,
			'note'        => sanitize_text_field( (string) ( $args['note'] ?? '' ) ),
			'created_by'  => $user instanceof \WP_User && $user->exists() ? $user->user_login : 'system',
			'created_at'  => current_time( 'mysql' ),
		);

		if ( $existing ) {
			if ( ! $overwrite ) {
				return (int) $existing['id'];
			}

			// A row somebody shaped by hand is never trampled by a robot.
			if ( 'auto' === $data['origin'] && 'auto' !== $existing['origin'] ) {
				return (int) $existing['id'];
			}

			unset( $data['created_at'], $data['created_by'] );
			$wpdb->update( self::table(), $data, array( 'id' => (int) $existing['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::rebuild_cache();

			return (int) $existing['id'];
		}

		$wpdb->insert( self::table(), $data ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		self::rebuild_cache();

		return (int) $wpdb->insert_id;
	}

	/**
	 * Remove rules by ids.
	 *
	 * @param int[] $ids Rule ids.
	 */
	public static function delete( array $ids ): void {
		global $wpdb;

		$ids = array_filter( array_map( 'absint', $ids ) );

		if ( empty( $ids ) ) {
			return;
		}

		$wpdb->query( 'DELETE FROM ' . self::table() . ' WHERE id IN (' . implode( ',', $ids ) . ')' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		self::rebuild_cache();
	}

	/*
	 * ---------------------------------------------- automatic on deletion
	 */

	/**
	 * Where a departed thing's visitors should land.
	 *
	 * Always one step up; when there is no up, the front page.
	 *
	 * @param \WP_Post $post The leaving post.
	 */
	private function fallback_for( \WP_Post $post ): string {
		$settings = self::settings();

		if ( 'product' === $post->post_type ) {
			if ( '' !== (string) $settings['fixed_product'] ) {
				return (string) $settings['fixed_product'];
			}

			$terms = get_the_terms( $post, 'product_cat' );

			if ( is_array( $terms ) && ! empty( $terms ) ) {
				$link = get_term_link( $terms[0] );

				if ( ! is_wp_error( $link ) ) {
					return (string) $link;
				}
			}

			$shop = function_exists( 'wc_get_page_permalink' ) ? (string) wc_get_page_permalink( 'shop' ) : '';

			return '' !== $shop ? $shop : home_url( '/' );
		}

		if ( 'post' === $post->post_type ) {
			if ( '' !== (string) $settings['fixed_post'] ) {
				return (string) $settings['fixed_post'];
			}

			$cats = get_the_category( $post->ID );

			if ( ! empty( $cats ) ) {
				$link = get_term_link( $cats[0] );

				if ( ! is_wp_error( $link ) ) {
					return (string) $link;
				}
			}

			$blog = absint( get_option( 'page_for_posts' ) );

			return $blog > 0 ? (string) get_permalink( $blog ) : home_url( '/' );
		}

		if ( 'page' === $post->post_type ) {
			if ( '' !== (string) $settings['fixed_page'] ) {
				return (string) $settings['fixed_page'];
			}

			if ( $post->post_parent > 0 ) {
				$parent = (string) get_permalink( $post->post_parent );

				if ( '' !== $parent ) {
					return $parent;
				}
			}

			return home_url( '/' );
		}

		$archive = (string) get_post_type_archive_link( $post->post_type );

		return '' !== $archive ? $archive : home_url( '/' );
	}

	/**
	 * Content going to the trash leaves a forwarding address at once.
	 *
	 * @param int $post_id Post id.
	 */
	public function on_trash( $post_id ): void {
		$post = get_post( $post_id );

		if ( ! $post instanceof \WP_Post || 'publish' !== $post->post_status ) {
			return;
		}

		$settings = self::settings();
		$flag     = 'product' === $post->post_type ? 'auto_product' : ( 'post' === $post->post_type ? 'auto_post' : 'auto_page' );

		if ( empty( $settings[ $flag ] ) ) {
			return;
		}

		$source = (string) get_permalink( $post );

		if ( '' === $source ) {
			return;
		}

		self::save(
			array(
				'source'      => $source,
				'target'      => $this->fallback_for( $post ),
				'origin'      => 'auto',
				'object_id'   => (int) $post->ID,
				'object_type' => $post->post_type,
				'note'        => __( 'Created when the content was trashed.', 'oc-theme' ),
			),
			false
		);
	}

	/**
	 * Content back from the trash takes its automatic redirect with it.
	 *
	 * @param int $post_id Post id.
	 */
	public function on_untrash( $post_id ): void {
		global $wpdb;

		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . " WHERE origin = 'auto' AND object_id = %d", absint( $post_id ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		self::rebuild_cache();
	}

	/**
	 * A renamed address forwards from the old name to the new.
	 *
	 * @param int      $post_id Post id.
	 * @param \WP_Post $after   Post after the update.
	 * @param \WP_Post $before  Post before the update.
	 */
	public function on_slug_change( $post_id, $after, $before ): void {
		$settings = self::settings();

		if ( empty( $settings['auto_slug'] )
			|| ! $after instanceof \WP_Post || ! $before instanceof \WP_Post
			|| 'publish' !== $before->post_status || 'publish' !== $after->post_status
			|| $before->post_name === $after->post_name
			|| '' === $before->post_name
		) {
			return;
		}

		// The old permalink, rebuilt from the old slug on today's structure.
		$new = (string) get_permalink( $after );
		$old = str_replace( '/' . $after->post_name . '/', '/' . $before->post_name . '/', trailingslashit( $new ) );

		if ( self::normalize( $old ) === self::normalize( $new ) ) {
			return;
		}

		self::save(
			array(
				'source'      => $old,
				'target'      => $new,
				'origin'      => 'auto',
				'object_id'   => (int) $post_id,
				'object_type' => $after->post_type,
				'note'        => __( 'Created when the address changed.', 'oc-theme' ),
			),
			false
		);
	}

	/**
	 * A deleted term sends its visitors one step up.
	 *
	 * @param int    $term_id  Term id.
	 * @param string $taxonomy Taxonomy.
	 */
	public function on_term_delete( $term_id, $taxonomy ): void {
		$brand = Search::brand_taxonomy();

		if ( ! in_array( $taxonomy, array( 'product_cat', 'category', $brand ), true ) || '' === $taxonomy ) {
			return;
		}

		$settings = self::settings();

		if ( empty( $settings['auto_term'] ) ) {
			return;
		}

		$term = get_term( $term_id, $taxonomy );

		if ( ! $term instanceof \WP_Term ) {
			return;
		}

		$source = get_term_link( $term );

		if ( is_wp_error( $source ) ) {
			return;
		}

		$target = '';

		if ( '' !== (string) $settings['fixed_term'] ) {
			$target = (string) $settings['fixed_term'];
		} elseif ( $term->parent > 0 ) {
			$parent = get_term_link( (int) $term->parent, $taxonomy );
			$target = is_wp_error( $parent ) ? '' : (string) $parent;
		}

		if ( '' === $target && '' !== $brand && $taxonomy === $brand ) {
			$target = Brands::url(); // A gone brand joins the rest at /brands/.
		}

		if ( '' === $target ) {
			if ( 'product_cat' === $taxonomy && function_exists( 'wc_get_page_permalink' ) ) {
				$target = (string) wc_get_page_permalink( 'shop' );
			}

			if ( '' === $target ) {
				$target = home_url( '/' );
			}
		}

		self::save(
			array(
				'source'      => (string) $source,
				'target'      => $target,
				'origin'      => 'auto',
				'object_id'   => (int) $term_id,
				'object_type' => $taxonomy,
				'note'        => __( 'Created when the category was deleted.', 'oc-theme' ),
			),
			false
		);
	}

	/*
	 * ------------------------------------------------------- the auto-mapper
	 */

	/**
	 * The new site's whole address book, indexed for the mapper's ladder.
	 *
	 * @return array{sku:array<string,string>,slug:array<string,string>,name:array<string,string>,norm:array<string,string>,paths:array<string,string>}
	 */
	public static function site_index(): array {
		$index = array(
			'sku'   => array(),
			'slug'  => array(),
			'name'  => array(),
			'norm'  => array(),
			'paths' => array(),
		);

		$taxonomies = array_filter( array( 'product_cat', 'category', Search::brand_taxonomy() ) );

		foreach ( $taxonomies as $taxonomy ) {
			$terms = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'hide_empty' => false,
				)
			);

			if ( ! is_array( $terms ) ) {
				continue;
			}

			foreach ( $terms as $term ) {
				$link = get_term_link( $term );

				if ( is_wp_error( $link ) ) {
					continue;
				}

				$path = self::normalize( (string) $link );

				$index['slug'][ strtolower( rawurldecode( $term->slug ) ) ] = $path;
				$index['name'][ mb_strtolower( $term->name ) ]              = $path;
				$index['norm'][ self::loose( $term->name ) ]                = $path;
				$index['norm'][ self::loose( $term->slug ) ]                = $path;
				$index['paths'][ $path ]                                    = $path;
			}
		}

		$posts = get_posts(
			array(
				'post_type'      => array( 'product', 'page', 'post' ),
				'post_status'    => 'publish',
				'posts_per_page' => 3000,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);

		foreach ( $posts as $post_id ) {
			$path = self::normalize( (string) get_permalink( $post_id ) );
			$slug = strtolower( rawurldecode( (string) get_post_field( 'post_name', $post_id ) ) );
			$name = (string) get_the_title( $post_id );

			$index['slug'][ $slug ]                  = $path;
			$index['name'][ mb_strtolower( $name ) ] = $path;
			$index['norm'][ self::loose( $name ) ]   = $path;
			$index['norm'][ self::loose( $slug ) ]   = $path;
			$index['paths'][ $path ]                 = $path;

			if ( 'product' === get_post_type( $post_id ) ) {
				$sku = (string) get_post_meta( $post_id, '_sku', true );

				if ( '' !== $sku ) {
					$index['sku'][ strtolower( $sku ) ] = $path;
				}
			}
		}

		return $index;
	}

	/**
	 * A loose form of a name for forgiving comparison: no niqqud, no
	 * definite article, no joining vav, no hyphens, singular-ish, and a
	 * rough Hebrew-Latin bridge.
	 *
	 * @param string $text Name or slug.
	 */
	public static function loose( string $text ): string {
		$text = mb_strtolower( trim( rawurldecode( $text ) ) );
		$text = (string) preg_replace( '/[\x{0591}-\x{05C7}]/u', '', $text );
		$text = str_replace( array( '-', '_', '"', "'", '״', '׳' ), ' ', $text );

		$words = array();

		foreach ( preg_split( '/\s+/u', $text ) ?: array() as $word ) {
			if ( '' === $word ) {
				continue;
			}

			// A leading ו or ה is glue, not meaning.
			$word = (string) preg_replace( '/^[וה](?=[\x{0590}-\x{05FF}]{2,})/u', '', $word );

			// A rough singular: ות / ים off the tail.
			$word = (string) preg_replace( '/(ות|ים)$/u', '', $word );
			$word = (string) preg_replace( '/(s|es)$/', '', $word );

			$words[] = $word;
		}

		sort( $words );

		return implode( ' ', $words );
	}

	/**
	 * Propose a target for one old address, walking the ladder.
	 *
	 * @param string               $old_path Normalised old path.
	 * @param array<string,mixed>  $row      Extra columns from the file: name, sku, type.
	 * @param array                $index    The site index.
	 * @param array<string,string> $mapped  Old→target choices made so far (parents first).
	 * @return array{target:string,rule:string,confidence:string}
	 */
	public static function propose( string $old_path, array $row, array $index, array $mapped ): array {
		$dictionary = (array) get_option( self::DICTIONARY, array() );

		// 1. Same SKU: the safest road for products.
		$sku = strtolower( trim( (string) ( $row['sku'] ?? '' ) ) );

		if ( '' !== $sku && isset( $index['sku'][ $sku ] ) ) {
			return array(
				'target'     => $index['sku'][ $sku ],
				'rule'       => 'sku',
				'confidence' => 'certain',
			);
		}

		$slug = strtolower( rawurldecode( (string) basename( strtok( $old_path, '?' ) ) ) );

		// 2. Same slug, even if the parents moved.
		if ( '' !== $slug && isset( $index['slug'][ $slug ] ) ) {
			return array(
				'target'     => $index['slug'][ $slug ],
				'rule'       => 'slug',
				'confidence' => 'certain',
			);
		}

		// 3. Same name.
		$name = mb_strtolower( trim( (string) ( $row['name'] ?? '' ) ) );

		if ( '' !== $name && isset( $index['name'][ $name ] ) ) {
			return array(
				'target'     => $index['name'][ $name ],
				'rule'       => 'name',
				'confidence' => 'certain',
			);
		}

		// 4. The loose form: plural, articles, transliteration noise.
		foreach ( array( $slug, $name ) as $candidate ) {
			if ( '' === $candidate ) {
				continue;
			}

			$loose = self::loose( $candidate );

			if ( '' !== $loose && isset( $index['norm'][ $loose ] ) ) {
				return array(
					'target'     => $index['norm'][ $loose ],
					'rule'       => 'normalized',
					'confidence' => 'high',
				);
			}
		}

		// 5. The replacement dictionary: a rule applies to the address and
		// to everything beneath it.
		foreach ( $dictionary as $from => $to ) {
			$from = '/' . trim( (string) $from, '/' );
			$to   = '/' . trim( (string) $to, '/' );

			if ( $old_path === $from || 0 === strpos( $old_path . '/', $from . '/' ) ) {
				$candidate = $to . substr( $old_path, strlen( $from ) );

				if ( isset( $index['paths'][ self::normalize( $candidate ) ] ) ) {
					return array(
						'target'     => self::normalize( $candidate ),
						'rule'       => 'dictionary',
						'confidence' => 'high',
					);
				}

				if ( isset( $index['paths'][ $to ] ) ) {
					return array(
						'target'     => $to,
						'rule'       => 'dictionary',
						'confidence' => 'high',
					);
				}
			}
		}

		// 6. Word overlap, above a threshold only.
		$best      = '';
		$bestScore = 0.0;
		$words     = array_filter( explode( ' ', self::loose( '' !== $name ? $name : str_replace( '/', ' ', $old_path ) ) ) );

		if ( count( $words ) > 0 ) {
			foreach ( $index['norm'] as $key => $path ) {
				$theirs = array_filter( explode( ' ', (string) $key ) );

				if ( empty( $theirs ) ) {
					continue;
				}

				$common = count( array_intersect( $words, $theirs ) );
				$score  = $common / max( count( $words ), count( $theirs ) );

				if ( $score > $bestScore ) {
					$bestScore = $score;
					$best      = $path;
				}
			}
		}

		if ( $bestScore >= 0.6 && '' !== $best ) {
			return array(
				'target'     => $best,
				'rule'       => 'similar',
				'confidence' => 'medium',
			);
		}

		// 7. Climb: the parent's destiny is the child's fallback.
		$parent = strtok( $old_path, '?' );

		while ( false !== strrpos( $parent, '/' ) && '' !== $parent && '/' !== $parent ) {
			$parent = substr( $parent, 0, (int) strrpos( $parent, '/' ) );

			if ( '' === $parent ) {
				break;
			}

			if ( isset( $mapped[ $parent ] ) && '' !== $mapped[ $parent ] ) {
				return array(
					'target'     => $mapped[ $parent ],
					'rule'       => 'parent',
					'confidence' => 'fallback',
				);
			}

			if ( isset( $index['paths'][ $parent ] ) ) {
				return array(
					'target'     => $parent,
					'rule'       => 'parent',
					'confidence' => 'fallback',
				);
			}
		}

		$shop = function_exists( 'wc_get_page_permalink' ) ? self::normalize( (string) wc_get_page_permalink( 'shop' ) ) : '';

		return array(
			'target'     => '' !== $shop && isset( $index['paths'][ $shop ] ) ? $shop : '/',
			'rule'       => 'none',
			'confidence' => 'fallback',
		);
	}
}
