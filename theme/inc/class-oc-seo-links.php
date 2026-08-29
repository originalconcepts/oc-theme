<?php
/**
 * OC SEO — automatic internal links.
 *
 * Finds a phrase in the text that names somewhere else on the site and turns
 * it into a link there: "נעלי נשים" in an article becomes a link to the
 * women's-shoes category. Written once, it keeps every new article wired into
 * the shop without anyone remembering to do it by hand.
 *
 * Three things decide whether this helps or hurts, and all three are settings:
 *
 *   Where it runs   The whole site, or only articles, or only category pages,
 *                   or only products. A shop usually wants it in articles,
 *                   pointing at categories.
 *   How many        Left unbounded, a long article becomes a mesh of links and
 *                   reads as spam. The cap is per page and defaults to five.
 *   What it links    Categories, products, articles, brands — each can be
 *                   turned off, because linking every product name in a blog
 *                   post is rarely what anyone means.
 *
 * Nothing here touches what is stored. The links are added as the page is
 * rendered, so unticking the box puts every article back exactly as it was —
 * which is what makes the feature safe to try on a live shop.
 *
 * @package OC_Theme
 */

declare( strict_types = 1 );

namespace OC\Theme;

defined( 'ABSPATH' ) || exit;

/**
 * The linker.
 */
final class Seo_Links {

	/**
	 * Where the phrase list is kept between requests.
	 */
	private const CACHE = 'oc_seo_links_index';

	/**
	 * The most phrases we will ever hold.
	 *
	 * A shop with 20,000 products would otherwise build a dictionary that
	 * costs more to carry than the links are worth.
	 */
	private const CAP = 2000;

	/**
	 * Elements whose text is left alone.
	 *
	 * A link inside a link is invalid; the rest are places where a link is
	 * either meaningless or actively wrong.
	 *
	 * @var string[]
	 */
	private const SKIP = array( 'a', 'script', 'style', 'textarea', 'button', 'code', 'pre', 'select', 'option', 'label' );

	/**
	 * Hook in.
	 */
	public function register(): void {
		add_action( 'save_post', array( __CLASS__, 'forget' ) );
		add_action( 'edited_term', array( __CLASS__, 'forget' ) );
		add_action( 'created_term', array( __CLASS__, 'forget' ) );
		add_action( 'delete_post', array( __CLASS__, 'forget' ) );
		add_action( 'delete_term', array( __CLASS__, 'forget' ) );

		$s = Seo::settings();

		if ( empty( $s['links_on'] ) ) {
			return;
		}

		add_filter( 'the_content', array( $this, 'content' ), 25 );
		add_filter( 'term_description', array( $this, 'term_text' ), 25 );
		add_filter( 'woocommerce_short_description', array( $this, 'content' ), 25 );
	}

	/**
	 * Throw the phrase list away when the site changes shape.
	 */
	public static function forget(): void {
		delete_transient( self::CACHE );
	}

	/*
	 * ------------------------------------------------------------ the entry
	 */

	/**
	 * Link up a piece of content.
	 *
	 * @param string $html The content.
	 */
	public function content( $html ): string {
		$html = (string) $html;

		if ( ! $this->should_run() ) {
			return $html;
		}

		return $this->link( $html );
	}

	/**
	 * The same for a category's description.
	 *
	 * @param string $html The description.
	 */
	public function term_text( $html ): string {
		$html = (string) $html;

		if ( is_admin() || ! $this->in_scope() ) {
			return $html;
		}

		return $this->link( $html );
	}

	/**
	 * Is this a page we should be touching at all?
	 */
	private function should_run(): bool {
		if ( is_admin() || is_feed() || is_embed() ) {
			return false;
		}

		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return false;
		}

		// Only the main content of the page being read, never a shelf of
		// excerpts rendered inside it.
		if ( ! in_the_loop() || ! is_main_query() ) {
			return false;
		}

		return $this->in_scope();
	}

	/**
	 * Does the chosen scope include this page?
	 */
	private function in_scope(): bool {
		$scope = (string) Seo::settings()['links_scope'];

		switch ( $scope ) {
			case 'content':
				return is_singular( array( 'post', 'page' ) );

			case 'category':
				return is_tax( 'product_cat' ) || is_category();

			case 'brand':
				return is_tax( 'product_brand' );

			case 'product':
				return is_singular( 'product' );

			case 'all':
			default:
				return true;
		}
	}

	/*
	 * ------------------------------------------------------- the phrase list
	 */

	/**
	 * Everything worth linking to, longest phrase first.
	 *
	 * Longest first is what makes "נעלי נשים שחורות" win over "נעלי נשים"
	 * when both would match the same words.
	 *
	 * @return array<string,string> Phrase => URL.
	 */
	public static function index(): array {
		$cached = get_transient( self::CACHE );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$s     = Seo::settings();
		$want  = (array) $s['links_targets'];
		$index = array();

		// Hand-written pairs come first and are never overwritten by an
		// automatic one — someone typed them on purpose.
		foreach ( self::manual_pairs() as $phrase => $url ) {
			$index[ $phrase ] = $url;
		}

		if ( in_array( 'product_cat', $want, true ) ) {
			self::add_terms( $index, 'product_cat' );
		}
		if ( in_array( 'product_brand', $want, true ) ) {
			self::add_terms( $index, 'product_brand' );
		}
		if ( in_array( 'category', $want, true ) ) {
			self::add_terms( $index, 'category' );
		}
		if ( in_array( 'product', $want, true ) ) {
			self::add_posts( $index, 'product' );
		}
		if ( in_array( 'post', $want, true ) ) {
			self::add_posts( $index, array( 'post', 'page' ) );
		}

		// Anything the shop asked us never to link.
		foreach ( self::excluded() as $phrase ) {
			unset( $index[ $phrase ] );
		}

		$min = max( 2, (int) $s['links_min'] );

		foreach ( array_keys( $index ) as $phrase ) {
			if ( mb_strlen( (string) $phrase ) < $min ) {
				unset( $index[ $phrase ] );
			}
		}

		uksort(
			$index,
			static function ( $a, $b ) {
				return mb_strlen( (string) $b ) <=> mb_strlen( (string) $a );
			}
		);

		$index = array_slice( $index, 0, self::CAP, true );

		set_transient( self::CACHE, $index, DAY_IN_SECONDS );

		return $index;
	}

	/**
	 * Add a taxonomy's terms to the list.
	 *
	 * @param array<string,string> $index    The list being built.
	 * @param string               $taxonomy Taxonomy name.
	 */
	private static function add_terms( array &$index, string $taxonomy ): void {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return;
		}

		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'number'     => self::CAP,
			)
		);

		if ( is_wp_error( $terms ) ) {
			return;
		}

		foreach ( $terms as $term ) {
			if ( ! $term instanceof \WP_Term ) {
				continue;
			}
			if ( get_term_meta( $term->term_id, '_ocseo_noindex', true ) ) {
				continue;
			}

			$name = trim( $term->name );
			$link = get_term_link( $term );

			if ( '' === $name || is_wp_error( $link ) || isset( $index[ $name ] ) ) {
				continue;
			}

			$index[ $name ] = (string) $link;
		}
	}

	/**
	 * Add a post type's titles to the list.
	 *
	 * @param array<string,string> $index The list being built.
	 * @param string|string[]      $types Post type(s).
	 */
	private static function add_posts( array &$index, $types ): void {
		$posts = get_posts(
			array(
				'post_type'      => $types,
				'post_status'    => 'publish',
				'posts_per_page' => self::CAP,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery
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
				),
			)
		);

		foreach ( $posts as $id ) {
			$title = trim( (string) get_the_title( $id ) );

			if ( '' === $title || isset( $index[ $title ] ) ) {
				continue;
			}

			$index[ $title ] = (string) get_permalink( $id );
		}
	}

	/**
	 * Phrases the shop never wants linked.
	 *
	 * @return string[]
	 */
	private static function excluded(): array {
		$raw = (string) Seo::settings()['links_exclude'];
		$out = array();

		foreach ( preg_split( '/\r\n|\r|\n/', $raw ) as $line ) {
			$line = trim( (string) $line );
			if ( '' !== $line ) {
				$out[] = $line;
			}
		}

		return $out;
	}

	/**
	 * Hand-written phrase => address pairs, one per line, split on a pipe.
	 *
	 * This is the half of the feature that does the real SEO work: the shop
	 * decides that "ריהוט משרדי" should always point at one particular
	 * category, whatever that category happens to be called.
	 *
	 * @return array<string,string>
	 */
	private static function manual_pairs(): array {
		$raw = (string) Seo::settings()['links_manual'];
		$out = array();

		foreach ( preg_split( '/\r\n|\r|\n/', $raw ) as $line ) {
			$line = trim( (string) $line );

			if ( '' === $line || false === strpos( $line, '|' ) ) {
				continue;
			}

			list( $phrase, $url ) = array_map( 'trim', explode( '|', $line, 2 ) );

			if ( '' === $phrase || '' === $url ) {
				continue;
			}

			$out[ $phrase ] = esc_url_raw( $url );
		}

		return $out;
	}

	/*
	 * ------------------------------------------------------------ the linking
	 */

	/**
	 * Put the links in.
	 *
	 * @param string $html The content.
	 */
	private function link( string $html ): string {
		if ( '' === trim( $html ) || ! class_exists( '\DOMDocument' ) ) {
			return $html;
		}

		$index = self::index();

		if ( ! $index ) {
			return $html;
		}

		$s      = Seo::settings();
		$budget = max( 1, (int) $s['links_max'] );
		$self   = $this->current_url();

		// Never link a page to itself, and never repeat a target on one page.
		foreach ( array_keys( $index ) as $phrase ) {
			if ( $index[ $phrase ] === $self ) {
				unset( $index[ $phrase ] );
			}
		}

		$doc  = new \DOMDocument();
		$prev = libxml_use_internal_errors( true );

		// The encoding declaration is what keeps Hebrew intact; without it
		// DOMDocument reads the bytes as Latin-1 and every word comes out
		// mangled.
		$ok = $doc->loadHTML(
			'<?xml encoding="utf-8" ?><div id="oc-al-root">' . $html . '</div>',
			LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
		);

		libxml_clear_errors();
		libxml_use_internal_errors( $prev );

		if ( ! $ok ) {
			return $html;
		}

		$root = $doc->getElementById( 'oc-al-root' );

		if ( ! $root ) {
			return $html;
		}

		$nodes = array();
		$this->collect( $root, $nodes, ! empty( $s['links_headings'] ) );

		$done = array();

		foreach ( $nodes as $node ) {
			if ( $budget <= 0 ) {
				break;
			}

			$this->link_node( $node, $doc, $index, $budget, $done );
		}

		$out = '';

		foreach ( $root->childNodes as $child ) {
			$out .= $doc->saveHTML( $child );
		}

		return $out;
	}

	/**
	 * Gather the text worth looking at.
	 *
	 * @param \DOMNode    $node     Where to start.
	 * @param \DOMText[]  $found    Collected nodes.
	 * @param bool        $headings Whether headings count.
	 */
	private function collect( \DOMNode $node, array &$found, bool $headings ): void {
		foreach ( $node->childNodes as $child ) {
			if ( $child instanceof \DOMText ) {
				if ( '' !== trim( (string) $child->nodeValue ) ) {
					$found[] = $child;
				}
				continue;
			}

			if ( ! $child instanceof \DOMElement ) {
				continue;
			}

			$tag = strtolower( $child->tagName );

			if ( in_array( $tag, self::SKIP, true ) ) {
				continue;
			}

			if ( ! $headings && preg_match( '/^h[1-6]$/', $tag ) ) {
				continue;
			}

			$this->collect( $child, $found, $headings );
		}
	}

	/**
	 * Link the first phrase that appears in one piece of text.
	 *
	 * @param \DOMText             $node   The text.
	 * @param \DOMDocument         $doc    Its document.
	 * @param array<string,string> $index  Remaining phrases.
	 * @param int                  $budget Links still allowed.
	 * @param array<string,bool>   $done   Targets already linked here.
	 */
	private function link_node( \DOMText $node, \DOMDocument $doc, array &$index, int &$budget, array &$done ): void {
		// Left to right through the text, taking the earliest match each time
		// and carrying on through what is left. Working phrase by phrase
		// instead would link only the first phrase that happened to appear
		// anywhere in the paragraph and leave the rest of it bare.
		while ( $budget > 0 ) {
			$text = (string) $node->nodeValue;
			$best = null;

			foreach ( $index as $phrase => $url ) {
				if ( isset( $done[ $url ] ) ) {
					continue;
				}

				// The lookarounds are the Hebrew-safe form of a word boundary:
				// \b is defined against ASCII word characters and would happily
				// match inside a Hebrew word.
				$pattern = '/(?<![\p{L}\p{N}])' . preg_quote( (string) $phrase, '/' ) . '(?![\p{L}\p{N}])/u';

				if ( ! preg_match( $pattern, $text, $m, PREG_OFFSET_CAPTURE ) ) {
					continue;
				}

				$at = (int) $m[0][1];

				// The list is longest-first, so an earlier start wins and a tie
				// goes to the longer phrase already seen.
				if ( null === $best || $at < $best['at'] ) {
					$best = array(
						'at'     => $at,
						'hit'    => (string) $m[0][0],
						'phrase' => (string) $phrase,
						'url'    => (string) $url,
					);
				}
			}

			if ( null === $best ) {
				return;
			}

			$before = substr( $text, 0, $best['at'] );
			$after  = substr( $text, $best['at'] + strlen( $best['hit'] ) );

			$frag = $doc->createDocumentFragment();

			if ( '' !== $before ) {
				$frag->appendChild( $doc->createTextNode( $before ) );
			}

			$link = $doc->createElement( 'a' );
			$link->setAttribute( 'href', $best['url'] );
			$link->setAttribute( 'class', 'oc-autolink' );
			$link->appendChild( $doc->createTextNode( $best['hit'] ) );
			$frag->appendChild( $link );

			$rest = null;

			if ( '' !== $after ) {
				$rest = $doc->createTextNode( $after );
				$frag->appendChild( $rest );
			}

			if ( ! $node->parentNode ) {
				return;
			}

			$node->parentNode->replaceChild( $frag, $node );

			$done[ $best['url'] ] = true;
			unset( $index[ $best['phrase'] ] );
			$budget--;

			if ( null === $rest ) {
				return;
			}

			$node = $rest;
		}
	}

	/**
	 * The address of the page being read, so it is never linked to itself.
	 */
	private function current_url(): string {
		if ( is_singular() ) {
			return (string) get_permalink( get_queried_object_id() );
		}

		if ( is_tax() || is_category() || is_tag() ) {
			$link = get_term_link( get_queried_object_id() );
			return is_wp_error( $link ) ? '' : (string) $link;
		}

		return '';
	}
}
