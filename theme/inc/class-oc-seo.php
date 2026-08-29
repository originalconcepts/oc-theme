<?php
/**
 * OC SEO — the engine.
 *
 * Replaces Yoast with exactly what every client site actually uses: meta
 * title and description, robots, canonical, Open Graph and Twitter — every
 * field auto-built from templates, every field overridable per item. One
 * rule everywhere: automatic beats empty, manual beats everything.
 *
 * Meta keys are plain custom fields (_ocseo_*) so WP All Import maps them
 * directly, on posts and terms alike.
 *
 * @package OC_Theme
 */

declare( strict_types = 1 );

namespace OC\Theme;

defined( 'ABSPATH' ) || exit;

/**
 * The head of every page.
 */
final class Seo {

	const SETTINGS = 'ocseo_settings';

	/**
	 * All the per-item keys, one list to rule metabox, import and migration.
	 *
	 * @var string[]
	 */
	const KEYS = array(
		'_ocseo_title',
		'_ocseo_desc',
		'_ocseo_noindex',
		'_ocseo_nofollow',
		'_ocseo_canonical',
		'_ocseo_og_image',
		'_ocseo_og_title',
		'_ocseo_og_desc',
		'_ocseo_tw_image',
		'_ocseo_tw_title',
		'_ocseo_tw_desc',
	);

	/**
	 * The ALTs already spoken for on this pageload.
	 *
	 * @var array<string,bool>
	 */
	private static $spoken = array();

	/**
	 * Hook in.
	 */
	public function register(): void {
		if ( self::yoast_active() ) {
			// Two title tags help nobody — Yoast keeps the head until removed.
			add_action( 'admin_notices', array( $this, 'yoast_notice' ) );
			return;
		}

		add_filter( 'pre_get_document_title', array( $this, 'title' ), 20 );
		add_action( 'wp_head', array( $this, 'head' ), 1 );
		add_filter( 'wp_robots', array( $this, 'robots' ), 20 );
		add_filter( 'wp_sitemaps_posts_query_args', array( $this, 'sitemap_posts' ) );
		add_filter( 'wp_sitemaps_taxonomies_query_args', array( $this, 'sitemap_terms' ) );
		add_filter( 'wp_sitemaps_enabled', array( $this, 'sitemap_on' ) );
		add_filter( 'wp_sitemaps_taxonomies', array( $this, 'sitemap_taxonomies' ) );
		add_filter( 'wp_sitemaps_post_types', array( $this, 'sitemap_post_types' ) );
		add_action( 'init', array( $this, 'sitemap_route' ) );
		add_filter( 'robots_txt', array( $this, 'robots_txt' ), 20, 2 );
		add_action( 'updated_post_meta', array( $this, 'normalize_meta' ), 10, 4 );
		add_action( 'added_post_meta', array( $this, 'normalize_meta' ), 10, 4 );
	}

	/**
	 * Yoast still running?
	 */
	public static function yoast_active(): bool {
		return defined( 'WPSEO_VERSION' );
	}

	/**
	 * The one warning: two SEO engines, one head.
	 */
	public function yoast_notice(): void {
		echo '<div class="notice notice-warning"><p>' .
			esc_html__( 'Yoast SEO is active, so the theme\'s own SEO engine stepped aside — two engines would print two title tags. Deactivate Yoast (after migrating in Settings ← SEO ← Tools) to switch over.', 'oc-theme' ) .
			'</p></div>';
	}

	/*
	 * ------------------------------------------------------------- settings
	 */

	/**
	 * Everything, with its defaults, one option.
	 *
	 * @return array<string,mixed>
	 */
	public static function settings(): array {
		$saved = get_option( self::SETTINGS, array() );

		return wp_parse_args(
			is_array( $saved ) ? $saved : array(),
			array(
				'sep'            => '-',
				'title_tpl'      => '%%title%% %%sep%% %%sitename%%',
				'desc_tpl'       => '',
				'home_title'     => '',
				'home_desc'      => '',
				'types'          => array(),
				'taxes'          => array(),
				'search_noindex' => 1,
				'date_noindex'   => 1,
				'author_noindex' => 0,
				'paged_noindex'  => 0,
				'og_default'     => '',
				'tw_card'        => 'summary_large_image',
				'fb_app_id'      => '',
				'alt_on'         => 1,
				'alt_mode'       => 'fill',
				'alt_tpl'        => '',
				'alt_tpl_tax'    => '',
				'alt_content'    => 1,
				'alt_upload'     => 0,
			)
		);
	}

	/**
	 * The per-type template row (title/desc/noindex), empty when unset.
	 *
	 * @param string $kind 'types' or 'taxes'.
	 * @param string $name Post type or taxonomy name.
	 * @return array{title:string,desc:string,noindex:int}
	 */
	public static function type_row( string $kind, string $name ): array {
		$settings = self::settings();
		$row      = $settings[ $kind ][ $name ] ?? array();

		return array(
			'title'   => (string) ( $row['title'] ?? '' ),
			'desc'    => (string) ( $row['desc'] ?? '' ),
			'noindex' => (int) ( $row['noindex'] ?? 0 ),
		);
	}

	/*
	 * ---------------------------------------------------- values per object
	 */

	/**
	 * A per-item field: post meta or term meta, trimmed, '' when unset.
	 *
	 * @param \WP_Post|\WP_Term|null $object The item.
	 * @param string                 $key    Meta key.
	 */
	public static function field( $object, string $key ): string {
		if ( $object instanceof \WP_Post ) {
			return trim( (string) get_post_meta( $object->ID, $key, true ) );
		}

		if ( $object instanceof \WP_Term ) {
			return trim( (string) get_term_meta( $object->term_id, $key, true ) );
		}

		return '';
	}

	/**
	 * The queried thing this request is about.
	 *
	 * @return \WP_Post|\WP_Term|null
	 */
	private static function subject() {
		if ( function_exists( 'is_shop' ) && is_shop() ) {
			$page = get_post( (int) wc_get_page_id( 'shop' ) );
			return $page instanceof \WP_Post ? $page : null;
		}

		if ( is_singular() ) {
			$post = get_queried_object();
			return $post instanceof \WP_Post ? $post : null;
		}

		if ( is_category() || is_tag() || is_tax() ) {
			$term = get_queried_object();
			return $term instanceof \WP_Term ? $term : null;
		}

		return null;
	}

	/*
	 * ------------------------------------------------------------ templates
	 */

	/**
	 * Fill a template's %%variables%% for the given item.
	 *
	 * @param string                 $template The template.
	 * @param \WP_Post|\WP_Term|null $object   The item, null for site-level.
	 */
	public static function render( string $template, $object = null ): string {
		$out = (string) preg_replace_callback(
			'/%%([a-z0-9_]+)%%/i',
			static function ( $m ) use ( $object ) {
				return self::variable( strtolower( (string) $m[1] ), $object );
			},
			$template
		);

		// Collapse the holes empty variables leave behind.
		$out = (string) preg_replace( '/\s{2,}/', ' ', $out );
		$out = trim( (string) preg_replace( '/(\s[-|·—]\s)(?=\s*([-|·—]\s|$))/u', ' ', $out ) );

		return trim( $out, " -|·—\t" );
	}

	/**
	 * One variable's value.
	 *
	 * @param string                 $name   Variable, without the %%.
	 * @param \WP_Post|\WP_Term|null $object The item.
	 */
	private static function variable( string $name, $object ): string {
		$settings = self::settings();

		switch ( $name ) {
			case 'sitename':
				return (string) get_bloginfo( 'name' );
			case 'sep':
				return (string) $settings['sep'];
			case 'currentyear':
				return (string) gmdate( 'Y' );
			case 'page':
				$paged = (int) get_query_var( 'paged' );
				/* translators: %d: page number. */
				return $paged > 1 ? sprintf( __( 'Page %d', 'oc-theme' ), $paged ) : '';
			case 'title':
				if ( $object instanceof \WP_Term ) {
					return $object->name;
				}
				return $object instanceof \WP_Post ? (string) get_the_title( $object ) : (string) get_bloginfo( 'name' );
			case 'term_title':
				return $object instanceof \WP_Term ? $object->name : '';
			case 'term_desc':
				return $object instanceof \WP_Term ? wp_strip_all_tags( (string) $object->description ) : '';
			case 'excerpt':
				return $object instanceof \WP_Post ? self::text_of( $object, 155 ) : '';
			case 'parent_title':
				if ( $object instanceof \WP_Post && $object->post_parent > 0 ) {
					return (string) get_the_title( $object->post_parent );
				}
				return '';
			case 'category':
				if ( $object instanceof \WP_Post ) {
					$taxonomy = 'product' === $object->post_type ? 'product_cat' : 'category';
					$terms    = get_the_terms( $object, $taxonomy );
					return is_array( $terms ) && ! empty( $terms ) ? $terms[0]->name : '';
				}
				return '';
			case 'brand':
				$taxonomy = Search::brand_taxonomy();
				if ( '' !== $taxonomy && $object instanceof \WP_Post ) {
					$terms = get_the_terms( $object, $taxonomy );
					return is_array( $terms ) && ! empty( $terms ) ? $terms[0]->name : '';
				}
				return '';
			case 'sku':
				if ( $object instanceof \WP_Post && function_exists( 'wc_get_product' ) ) {
					$product = wc_get_product( $object->ID );
					return $product ? (string) $product->get_sku() : '';
				}
				return '';
			case 'price':
				if ( $object instanceof \WP_Post && function_exists( 'wc_get_product' ) ) {
					$product = wc_get_product( $object->ID );
					return $product ? html_entity_decode( wp_strip_all_tags( (string) wc_price( (float) $product->get_price() ) ) ) : '';
				}
				return '';
		}

		if ( 0 === strpos( $name, 'cf_' ) && $object instanceof \WP_Post ) {
			return trim( (string) get_post_meta( $object->ID, substr( $name, 3 ), true ) );
		}

		if ( 0 === strpos( $name, 'tax_' ) && $object instanceof \WP_Post ) {
			$terms = get_the_terms( $object, substr( $name, 4 ) );
			return is_array( $terms ) ? implode( ', ', wp_list_pluck( $terms, 'name' ) ) : '';
		}

		return '';
	}

	/**
	 * The item's own words: excerpt first, then the content's opening, clean
	 * of tags and shortcodes.
	 *
	 * @param \WP_Post $post  The post.
	 * @param int      $limit Characters.
	 */
	public static function text_of( \WP_Post $post, int $limit ): string {
		$text = trim( (string) $post->post_excerpt );

		if ( '' === $text && 'product' === $post->post_type && function_exists( 'wc_get_product' ) ) {
			$product = wc_get_product( $post->ID );
			$text    = $product ? trim( (string) $product->get_short_description() ) : '';
		}

		if ( '' === $text ) {
			$text = (string) $post->post_content;
		}

		$text = wp_strip_all_tags( strip_shortcodes( $text ) );
		$text = trim( (string) preg_replace( '/\s+/u', ' ', $text ) );

		return mb_strlen( $text ) > $limit ? rtrim( mb_substr( $text, 0, $limit ) ) . '…' : $text;
	}

	/*
	 * --------------------------------------- the resolved values, per field
	 */

	/**
	 * The title that will actually print, manual → type template → site.
	 *
	 * @param \WP_Post|\WP_Term|null $object The item, null for the current request.
	 */
	public static function auto_title( $object = null ): string {
		$object   = $object ?? self::subject();
		$settings = self::settings();

		$manual = self::field( $object, '_ocseo_title' );

		if ( '' !== $manual ) {
			return self::render( $manual, $object );
		}

		$row = array( 'title' => '' );

		if ( $object instanceof \WP_Post ) {
			$row = self::type_row( 'types', $object->post_type );
		} elseif ( $object instanceof \WP_Term ) {
			$row = self::type_row( 'taxes', $object->taxonomy );
		}

		$template = '' !== $row['title'] ? $row['title'] : (string) $settings['title_tpl'];

		return self::render( $template, $object );
	}

	/**
	 * The description that will actually print.
	 *
	 * @param \WP_Post|\WP_Term|null $object The item.
	 */
	public static function auto_desc( $object = null ): string {
		$object = $object ?? self::subject();

		$manual = self::field( $object, '_ocseo_desc' );

		if ( '' !== $manual ) {
			return self::render( $manual, $object );
		}

		$row = array( 'desc' => '' );

		if ( $object instanceof \WP_Post ) {
			$row = self::type_row( 'types', $object->post_type );
		} elseif ( $object instanceof \WP_Term ) {
			$row = self::type_row( 'taxes', $object->taxonomy );
		}

		$settings = self::settings();
		$template = '' !== $row['desc'] ? $row['desc'] : (string) $settings['desc_tpl'];

		if ( '' !== $template ) {
			$out = self::render( $template, $object );

			if ( '' !== $out ) {
				return $out;
			}
		}

		if ( $object instanceof \WP_Post ) {
			return self::text_of( $object, 155 );
		}

		if ( $object instanceof \WP_Term ) {
			$text = wp_strip_all_tags( (string) $object->description );
			return mb_strlen( $text ) > 155 ? rtrim( mb_substr( $text, 0, 155 ) ) . '…' : $text;
		}

		return (string) get_bloginfo( 'description' );
	}

	/**
	 * The share image URL: manual → featured → first in content → site default.
	 *
	 * @param \WP_Post|\WP_Term|null $object The item.
	 */
	public static function auto_image( $object = null ): string {
		$object = $object ?? self::subject();

		$manual = self::image_url( self::field( $object, '_ocseo_og_image' ) );

		if ( '' !== $manual ) {
			return $manual;
		}

		if ( $object instanceof \WP_Post ) {
			$id = (int) get_post_thumbnail_id( $object );

			if ( $id > 0 ) {
				$url = wp_get_attachment_image_url( $id, 'large' );

				if ( is_string( $url ) ) {
					return $url;
				}
			}

			if ( preg_match( '/<img[^>]+src=["\']([^"\']+)/i', (string) $object->post_content, $m ) ) {
				return (string) $m[1];
			}
		}

		if ( $object instanceof \WP_Term ) {
			$id = (int) get_term_meta( $object->term_id, 'thumbnail_id', true );

			if ( $id > 0 ) {
				$url = wp_get_attachment_image_url( $id, 'large' );

				if ( is_string( $url ) ) {
					return $url;
				}
			}
		}

		return self::image_url( (string) self::settings()['og_default'] );
	}

	/**
	 * An image field holds a URL or a media id — either way, a URL out.
	 *
	 * @param string $value URL or attachment id.
	 */
	public static function image_url( string $value ): string {
		$value = trim( $value );

		if ( '' === $value ) {
			return '';
		}

		if ( is_numeric( $value ) ) {
			$url = wp_get_attachment_image_url( (int) $value, 'large' );
			return is_string( $url ) ? $url : '';
		}

		return $value;
	}

	/**
	 * Should this request stay out of the index?
	 */
	public static function is_noindex(): bool {
		$settings = self::settings();
		$object   = self::subject();

		$manual = self::field( $object, '_ocseo_noindex' );

		if ( '' !== $manual ) {
			return '1' === $manual;
		}

		if ( $object instanceof \WP_Post ) {
			return 1 === self::type_row( 'types', $object->post_type )['noindex'];
		}

		if ( $object instanceof \WP_Term ) {
			return 1 === self::type_row( 'taxes', $object->taxonomy )['noindex'];
		}

		if ( is_search() ) {
			return ! empty( $settings['search_noindex'] );
		}

		if ( is_date() ) {
			return ! empty( $settings['date_noindex'] );
		}

		if ( is_author() ) {
			return ! empty( $settings['author_noindex'] );
		}

		return false;
	}

	/*
	 * ------------------------------------------------------------ the head
	 */

	/**
	 * The document title, ours everywhere the engine has a say.
	 *
	 * @param string $title Whatever another filter proposed.
	 */
	public function title( $title ) {
		if ( is_front_page() ) {
			$home = trim( (string) self::settings()['home_title'] );
			return '' !== $home ? self::render( $home ) : $title;
		}

		if ( is_search() || is_404() ) {
			return $title;
		}

		$ours = self::auto_title();

		return '' !== $ours ? $ours : $title;
	}

	/**
	 * Description, canonical, Open Graph, Twitter — one pass, no leftovers.
	 */
	public function head(): void {
		if ( is_404() ) {
			return;
		}

		$object = self::subject();

		$title = is_front_page() && '' !== trim( (string) self::settings()['home_title'] )
			? self::render( (string) self::settings()['home_title'] )
			: self::auto_title( $object );

		// A latest-posts front page has no subject; the template would chain
		// the site name onto itself ("Eden - Eden") — core's title is right.
		if ( null === $object && is_front_page() && '' === trim( (string) self::settings()['home_title'] ) ) {
			$title = wp_get_document_title();
		}
		$desc  = is_front_page() && '' !== trim( (string) self::settings()['home_desc'] )
			? self::render( (string) self::settings()['home_desc'] )
			: self::auto_desc( $object );

		if ( '' !== $desc ) {
			printf( '<meta name="description" content="%s">' . "\n", esc_attr( $desc ) );
		}

		// Canonical: manual wins; otherwise the clean permalink — but a
		// filtered catalogue view already points its own (class-oc-filters).
		$canonical = self::field( $object, '_ocseo_canonical' );

		if ( '' === $canonical ) {
			if ( $object instanceof \WP_Post ) {
				$canonical = (string) get_permalink( $object );
			} elseif ( $object instanceof \WP_Term ) {
				$link      = get_term_link( $object );
				$canonical = is_wp_error( $link ) ? '' : (string) $link;
			} elseif ( is_front_page() ) {
				$canonical = home_url( '/' );
			}

			$paged = (int) get_query_var( 'paged' );

			if ( '' !== $canonical && $paged > 1 ) {
				$canonical = trailingslashit( $canonical ) . 'page/' . $paged . '/';
			}

			if ( ( is_tax() || is_category() || is_tag() ) && ! empty( $_GET ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$canonical = ''; // The filters engine speaks for filtered loads.
			}
		}

		if ( '' !== $canonical ) {
			remove_action( 'wp_head', 'rel_canonical' );
			printf( '<link rel="canonical" href="%s">' . "\n", esc_url( $canonical ) );
		}

		// Open Graph.
		$og_title = self::field( $object, '_ocseo_og_title' );
		$og_title = '' !== $og_title ? self::render( $og_title, $object ) : $title;
		$og_desc  = self::field( $object, '_ocseo_og_desc' );
		$og_desc  = '' !== $og_desc ? self::render( $og_desc, $object ) : $desc;
		$og_image = self::auto_image( $object );

		$type = 'website';

		if ( $object instanceof \WP_Post ) {
			$type = 'product' === $object->post_type ? 'product' : ( 'page' === $object->post_type ? 'website' : 'article' );
		}

		$url = $canonical;

		if ( '' === $url ) {
			$url = home_url( add_query_arg( array(), (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '/' ), PHP_URL_PATH ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		}

		printf( '<meta property="og:type" content="%s">' . "\n", esc_attr( $type ) );
		printf( '<meta property="og:title" content="%s">' . "\n", esc_attr( $og_title ) );

		if ( '' !== $og_desc ) {
			printf( '<meta property="og:description" content="%s">' . "\n", esc_attr( $og_desc ) );
		}

		printf( '<meta property="og:url" content="%s">' . "\n", esc_url( $url ) );
		printf( '<meta property="og:site_name" content="%s">' . "\n", esc_attr( (string) get_bloginfo( 'name' ) ) );
		printf( '<meta property="og:locale" content="%s">' . "\n", esc_attr( (string) get_locale() ) );

		if ( '' !== $og_image ) {
			printf( '<meta property="og:image" content="%s">' . "\n", esc_url( $og_image ) );

			$alt = $object instanceof \WP_Post ? (string) get_the_title( $object ) : ( $object instanceof \WP_Term ? $object->name : '' );

			if ( '' !== $alt ) {
				printf( '<meta property="og:image:alt" content="%s">' . "\n", esc_attr( $alt ) );
			}
		}

		$fb_app = trim( (string) self::settings()['fb_app_id'] );

		if ( '' !== $fb_app ) {
			printf( '<meta property="fb:app_id" content="%s">' . "\n", esc_attr( $fb_app ) );
		}

		// Twitter falls back to OG, per the spec: most sites never touch it.
		$tw_title = self::field( $object, '_ocseo_tw_title' );
		$tw_desc  = self::field( $object, '_ocseo_tw_desc' );
		$tw_image = self::image_url( self::field( $object, '_ocseo_tw_image' ) );

		printf( '<meta name="twitter:card" content="%s">' . "\n", esc_attr( (string) self::settings()['tw_card'] ) );
		printf( '<meta name="twitter:title" content="%s">' . "\n", esc_attr( '' !== $tw_title ? self::render( $tw_title, $object ) : $og_title ) );

		if ( '' !== $og_desc || '' !== $tw_desc ) {
			printf( '<meta name="twitter:description" content="%s">' . "\n", esc_attr( '' !== $tw_desc ? self::render( $tw_desc, $object ) : $og_desc ) );
		}

		if ( '' !== $tw_image || '' !== $og_image ) {
			printf( '<meta name="twitter:image" content="%s">' . "\n", esc_url( '' !== $tw_image ? $tw_image : $og_image ) );
		}
	}

	/**
	 * Robots: the resolved index/follow plus the image preview everyone wants.
	 *
	 * @param array<string,mixed> $robots Current directives.
	 */
	public function robots( $robots ) {
		$noindex = self::is_noindex()
			|| ( (int) get_query_var( 'paged' ) > 1 && ! empty( self::settings()['paged_noindex'] ) );

		if ( $noindex ) {
			// Always the clean pair — core may have piled nofollow on top.
			$robots['noindex'] = true;
			$robots['follow']  = true;
			unset( $robots['index'], $robots['nofollow'] );
		} else {
			$robots['max-image-preview:large'] = true;
		}

		if ( '1' === self::field( self::subject(), '_ocseo_nofollow' ) ) {
			$robots['nofollow'] = true;
			unset( $robots['follow'] );
		}

		return $robots;
	}

	/*
	 * ------------------------------------------------------------- sitemaps
	 */

	/**
	 * The sitemap stands whether or not the site invites search engines.
	 *
	 * WordPress ties the two together: tick "discourage search engines" and
	 * `wp_sitemaps_enabled` goes false, which switches the whole system off —
	 * no providers, no file, a 404. The two are not really related, and on a
	 * site still being built it is useful to see the sitemap that will go
	 * live. Being unindexed is said once, plainly, in robots and in the
	 * robots meta tag; it does not need the sitemap withheld as well.
	 *
	 * @param bool $on Whether core would serve one.
	 */
	public function sitemap_on( $on ): bool {
		unset( $on );

		/**
		 * Filters whether the sitemap is served.
		 *
		 * @param bool $on Whether to serve it.
		 */
		return (bool) apply_filters( 'oc_sitemap_enabled', true );
	}

	/**
	 * Answer on /sitemap.xml, which is where people and robots look.
	 *
	 * Core only claims wp-sitemap.xml. The friendly name is registered here
	 * rather than relied upon: a rule of the same shape was already in the
	 * database from some earlier tool, and a flush would have taken it away
	 * with nobody the wiser.
	 */
	public function sitemap_route(): void {
		add_rewrite_rule( '^sitemap\.xml$', 'index.php?sitemap=index', 'top' );

		if ( ! get_option( 'oc_seo_sitemap_rw' ) ) {
			flush_rewrite_rules( false );
			update_option( 'oc_seo_sitemap_rw', '1', false );
		}
	}

	/**
	 * Point robots.txt at it.
	 *
	 * @param string $output The robots.txt body.
	 * @param bool   $public Whether the site invites indexing.
	 */
	public function robots_txt( $output, $public ): string {
		unset( $public );

		$line = 'Sitemap: ' . home_url( '/sitemap.xml' );

		if ( false !== strpos( (string) $output, $line ) ) {
			return (string) $output;
		}

		return rtrim( (string) $output ) . "\n\n" . $line . "\n";
	}

	/**
	 * Keep product attributes out of the sitemap.
	 *
	 * Colour and size archives are public, so core offers them, but a page
	 * per shade is thin duplicate-ish content and not what the shop wants
	 * crawled. Categories, brands and blog categories stay.
	 *
	 * @param array<string,\WP_Taxonomy> $taxonomies Taxonomies core would list.
	 * @return array<string,\WP_Taxonomy>
	 */
	public function sitemap_taxonomies( $taxonomies ) {
		foreach ( array_keys( (array) $taxonomies ) as $name ) {
			$name = (string) $name;

			if ( 0 === strpos( $name, 'pa_' ) || 'post_format' === $name || 'product_tag' === $name ) {
				unset( $taxonomies[ $name ] );
			}
		}

		/**
		 * Filters the taxonomies the sitemap lists.
		 *
		 * @param array<string,\WP_Taxonomy> $taxonomies Taxonomies.
		 */
		return (array) apply_filters( 'oc_sitemap_taxonomies', $taxonomies );
	}

	/**
	 * And the post types.
	 *
	 * @param array<string,\WP_Post_Type> $types Post types core would list.
	 * @return array<string,\WP_Post_Type>
	 */
	public function sitemap_post_types( $types ) {
		unset( $types['attachment'] );

		/**
		 * Filters the post types the sitemap lists.
		 *
		 * @param array<string,\WP_Post_Type> $types Post types.
		 */
		return (array) apply_filters( 'oc_sitemap_post_types', $types );
	}

	/**
	 * A noindexed post has no business in the core sitemap.
	 *
	 * @param array<string,mixed> $args WP_Query args.
	 */
	public function sitemap_posts( $args ) {
		$args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery
			'relation' => 'OR',
			array(
				'key'     => '_ocseo_noindex',
				'compare' => 'NOT EXISTS',
			),
			array(
				'key'     => '_ocseo_noindex',
				'value'   => '1',
				'compare' => '!=',
			),
		);

		return $args;
	}

	/**
	 * Same courtesy for terms.
	 *
	 * @param array<string,mixed> $args get_terms args.
	 */
	public function sitemap_terms( $args ) {
		$args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery
			'relation' => 'OR',
			array(
				'key'     => '_ocseo_noindex',
				'compare' => 'NOT EXISTS',
			),
			array(
				'key'     => '_ocseo_noindex',
				'value'   => '1',
				'compare' => '!=',
			),
		);

		return $args;
	}

	/*
	 * -------------------------------------------------------------- imports
	 */

	/**
	 * The index flag forgives every spelling an import file might use:
	 * 1/0, yes/no, true/false, index/noindex, כן/לא — normalised on save.
	 *
	 * @param int    $meta_id  Meta row id.
	 * @param int    $post_id  Post id.
	 * @param string $meta_key Key.
	 * @param mixed  $value    Whatever the file said.
	 */
	public function normalize_meta( $meta_id, $post_id, $meta_key, $value ): void {
		if ( ! in_array( $meta_key, array( '_ocseo_noindex', '_ocseo_nofollow' ), true ) ) {
			return;
		}

		$raw = trim( (string) ( is_scalar( $value ) ? $value : '' ) );

		if ( '' === $raw || '0' === $raw || '1' === $raw ) {
			return;
		}

		$yes = array( 'yes', 'true', 'noindex', 'nofollow', 'on', 'כן' );
		$no  = array( 'no', 'false', 'index', 'follow', 'off', 'לא' );

		$flag = null;

		if ( in_array( mb_strtolower( $raw ), $yes, true ) ) {
			$flag = '1';
		} elseif ( in_array( mb_strtolower( $raw ), $no, true ) ) {
			$flag = '0';
		}

		if ( null !== $flag ) {
			remove_action( 'updated_post_meta', array( $this, 'normalize_meta' ) );
			update_post_meta( (int) $post_id, $meta_key, $flag );
			add_action( 'updated_post_meta', array( $this, 'normalize_meta' ), 10, 4 );
		}
	}

	/*
	 * ------------------------------------------------ ALT: the shared brain
	 */

	/**
	 * The ALT an image should carry, unique within this pageload.
	 *
	 * The chain: manual ALT → the post the image belongs to → the current
	 * page's context → the cleaned filename. Duplicates grow a differentiator.
	 *
	 * @param int      $attachment_id The image.
	 * @param int      $parent_id     The post it serves right now (0 = none known).
	 * @param int      $index         Position in a gallery, 1-based (0 = unknown).
	 */
	public static function alt_for( int $attachment_id, int $parent_id = 0, int $index = 0 ): string {
		// The same picture shown twice on one page (thumbnail + zoom) keeps
		// one description — only different pictures need differentiating.
		static $memo = array();

		$memo_key = $attachment_id . ':' . $parent_id;

		if ( $attachment_id > 0 && isset( $memo[ $memo_key ] ) ) {
			return $memo[ $memo_key ];
		}

		$settings = self::settings();

		$manual = trim( (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) );

		if ( '' !== $manual && 'force' !== $settings['alt_mode'] ) {
			self::$spoken[ $manual ] = true;
			return $manual;
		}

		$attachment = get_post( $attachment_id );
		$parent     = $parent_id > 0 ? get_post( $parent_id ) : null;

		if ( ! $parent && $attachment instanceof \WP_Post && $attachment->post_parent > 0 ) {
			$parent = get_post( $attachment->post_parent );
		}

		// A template, when one is filled in, decides the shape.
		$is_tax   = is_category() || is_tag() || is_tax();
		$template = trim( (string) ( $is_tax ? $settings['alt_tpl_tax'] : $settings['alt_tpl'] ) );
		$filename = self::filename_words( $attachment_id );

		if ( '' !== $template ) {
			$base = self::render(
				str_replace(
					array( '%%filename%%', '%%index%%' ),
					array( $filename, $index > 0 ? (string) $index : '' ),
					$template
				),
				$parent instanceof \WP_Post ? $parent : self::subject()
			);
		} else {
			$base = '';

			if ( $parent instanceof \WP_Post ) {
				$base = (string) get_the_title( $parent );
			}

			if ( '' === $base ) {
				$context = self::subject();

				if ( $context instanceof \WP_Term ) {
					$base = $context->name;
				} elseif ( $context instanceof \WP_Post ) {
					$base = (string) get_the_title( $context );
				} elseif ( is_archive() ) {
					$base = wp_strip_all_tags( (string) get_the_archive_title() );
				} else {
					$base = (string) get_bloginfo( 'name' );
				}
			}

			if ( '' === $base ) {
				$base = $filename;
			}
		}

		$base = trim( $base );

		if ( '' === $base ) {
			return '';
		}

		// Uniqueness: category, filename words, then a plain counter.
		$alt = $base;

		if ( isset( self::$spoken[ $alt ] ) && $parent instanceof \WP_Post ) {
			$taxonomy = 'product' === $parent->post_type ? 'product_cat' : 'category';
			$terms    = get_the_terms( $parent, $taxonomy );

			if ( is_array( $terms ) && ! empty( $terms ) ) {
				$alt = $base . ' ' . $settings['sep'] . ' ' . $terms[0]->name;
			}
		}

		if ( isset( self::$spoken[ $alt ] ) && '' !== $filename && false === mb_stripos( $base, $filename ) ) {
			$alt = $base . ' ' . $settings['sep'] . ' ' . $filename;
		}

		$n = max( 2, $index );

		while ( isset( self::$spoken[ $alt ] ) ) {
			/* translators: %d: image position. */
			$alt = $base . ' ' . $settings['sep'] . ' ' . sprintf( __( 'Image %d', 'oc-theme' ), $n );
			$n++;
		}

		self::$spoken[ $alt ] = true;

		if ( $attachment_id > 0 ) {
			$memo[ $memo_key ] = $alt;
		}

		return $alt;
	}

	/**
	 * A filename's meaningful words: no extension, no hyphens, no size
	 * suffixes, no digit runs. entrecote-angus-01.jpg → "entrecote angus".
	 *
	 * @param int $attachment_id The image.
	 */
	public static function filename_words( int $attachment_id ): string {
		$file = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );
		$name = (string) pathinfo( $file, PATHINFO_FILENAME );
		$name = (string) preg_replace( '/-?\d+x\d+$/', '', $name );
		$name = str_replace( array( '-', '_' ), ' ', $name );
		$name = (string) preg_replace( '/\b\d+\b/', '', $name );

		return trim( (string) preg_replace( '/\s+/', ' ', $name ) );
	}
}
