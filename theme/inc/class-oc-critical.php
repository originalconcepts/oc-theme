<?php
/**
 * Critical CSS: the first screen's rules inside the page.
 *
 * A browser draws nothing until every stylesheet in the head has arrived —
 * on a slow phone that is the better part of two seconds of white, while
 * the banner picture already sits downloaded. So the page carries, inline,
 * the rules the first screen needs, and the stylesheets themselves load
 * without blocking and take over when they arrive.
 *
 * Which rules: the page's own output is read back (output buffer), its
 * markup is scanned for class names and ids, and every rule whose
 * selector asks for nothing that is not there is kept — the whole page's
 * names, not a guess at the first screen, since a two-column layout puts
 * what the eye sees first late in the markup. Rules for states a script
 * sets at once (is-current, is-ready, ...) and for the pieces a script
 * builds (the pause buttons) are kept too. The result is cached per
 * distinct set of names, so the parsing runs once per kind of page.
 *
 * Safety: anything odd — no head, a stylesheet that cannot be read, an
 * extract that came out empty or outsized — leaves the page exactly as
 * it was, with its blocking stylesheets. `?oc_critical=0` does the same
 * on demand, and the `oc_critical_css` filter switches it off for a site.
 *
 * @package OC_Theme
 */

declare( strict_types = 1 );

namespace OC\Theme;

defined( 'ABSPATH' ) || exit;

/**
 * The first screen's CSS, inlined.
 */
final class Critical {

	/**
	 * Stylesheets left alone: WordPress's own admin chrome, and one that
	 * already loads late on its own.
	 */
	const SKIP = array( 'admin-bar', 'dashicons', 'ocai' );

	/**
	 * An extract bigger than this is not worth inlining on every page.
	 */
	const MAX_BYTES = 260000;

	/**
	 * Classes a script adds the moment the page runs, before the full
	 * stylesheet can be counted on: their rules ride along.
	 */
	const STATES = array( 'ocpp', 'ocpp--corner', 'ocpp--inline', 'ocpp--top', 'ocpp__g', 'ocpp__p', 'is-current', 'is-ready', 'is-on', 'is-active', 'is-open', 'is-in', 'is-done', 'is-settled', 'is-scrolled', 'is-visible', 'is-live', 'is-revealed', 'is-out', 'is-past', 'is-fading', 'is-paused', 'is-playing', 'is-loaded', 'is-empty', 'is-afloat', 'is-stuck', 'is-solid', 'oc-ready', 'oc-fonts', 'oc-scrolled', 'oc-signed', 'oc-col-stick', 'oc-acc-init', 'oc-clamped', 'ocb--still', 'ocb--in', 'in-scene', 'has-nav', 'loading', 'loaded' );

	/**
	 * Hook in.
	 */
	public function register(): void {
		add_action( 'template_redirect', array( $this, 'start' ), 1 );
		add_action( 'rest_api_init', array( $this, 'rest' ) );
	}

	/**
	 * The route the page reports back on: the class names its scripts
	 * ended up building, so the next page of the kind inlines their rules.
	 */
	public function rest(): void {
		register_rest_route(
			'oc/v1',
			'/critical-learn',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( __CLASS__, 'permit' ),
				'callback'            => array( $this, 'learn' ),
				'args'                => array(
					'type'  => array(
						'type'     => 'string',
						'required' => true,
					),
					'names' => array(
						'type'     => 'array',
						'required' => true,
					),
					'_t'    => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * A day token, so only a page this site served can teach it: the page
	 * prints it in the oc-critical meta and the report carries it back.
	 *
	 * @param int $shift Days back.
	 */
	public static function token( int $shift = 0 ): string {
		$day = gmdate( 'Y-m-d', time() - $shift * DAY_IN_SECONDS );

		return substr( hash_hmac( 'sha256', 'oc_critical|' . $day, wp_salt( 'nonce' ) ), 0, 20 );
	}

	/**
	 * Today's or yesterday's token.
	 *
	 * @param \WP_REST_Request $req Request.
	 */
	public static function permit( \WP_REST_Request $req ): bool {
		$t = (string) $req->get_param( '_t' );

		return '' !== $t && ( hash_equals( self::token(), $t ) || hash_equals( self::token( 1 ), $t ) );
	}

	/**
	 * Remember, per kind of page, the names the scripts added. Only names
	 * a stylesheet of ours actually styles are kept, so a stray request
	 * can at most ask for rules that exist anyway.
	 *
	 * @param \WP_REST_Request $req The request.
	 */
	public function learn( \WP_REST_Request $req ): \WP_REST_Response {
		$type  = sanitize_key( (string) $req->get_param( 'type' ) );
		$names = (array) $req->get_param( 'names' );

		if ( '' === $type || ! $names || count( $names ) > 1500 ) {
			return new \WP_REST_Response( array( 'added' => 0 ), 200 );
		}

		$known = self::styled_names();
		$all   = get_option( 'oc_critical_learned', array() );
		$all   = is_array( $all ) ? $all : array();
		$have  = array_fill_keys( (array) ( $all[ $type ] ?? array() ), true );
		$added = 0;

		foreach ( $names as $n ) {
			$n = (string) $n;

			if ( strlen( $n ) > 80 || ! preg_match( '/^[A-Za-z_][\w-]*$/', $n ) || preg_match( '/\d{3,}/', $n ) ) {
				continue;
			}

			if ( isset( $have[ $n ] ) || ! isset( $known[ $n ] ) ) {
				continue;
			}

			$have[ $n ] = true;
			++$added;
		}

		if ( $added && count( $have ) <= 3000 ) {
			$all[ $type ] = array_keys( $have );
			update_option( 'oc_critical_learned', $all, false );
		}

		return new \WP_REST_Response( array( 'added' => $added ), 200 );
	}

	/**
	 * Every class name any of the theme's stylesheets mentions, as keys.
	 * Cached against the files' dates.
	 *
	 * @return array<string,true>
	 */
	private static function styled_names(): array {
		$files = array_merge(
			(array) glob( OC_THEME_DIR . '/assets/css/theme*.min.css' ),
			(array) glob( WP_PLUGIN_DIR . '/oc-blocks/assets/blocks*.min.css' )
		);
		$stamp = '';

		foreach ( $files as $f ) {
			$stamp .= $f . ':' . (int) filemtime( $f ) . ';';
		}

		$key = 'oc_crit_names_' . md5( $stamp );
		$set = get_transient( $key );

		if ( is_array( $set ) ) {
			return $set;
		}

		$set = array();

		foreach ( $files as $f ) {
			if ( preg_match_all( '/\.([A-Za-z_][\w-]*)/', (string) file_get_contents( $f ), $m ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a file of ours on disk.
				foreach ( $m[1] as $n ) {
					$set[ $n ] = true;
				}
			}
		}

		set_transient( $key, $set, 7 * DAY_IN_SECONDS );

		return $set;
	}

	/**
	 * What kind of page this is, for the learned names.
	 */
	public static function type(): string {
		if ( is_front_page() ) {
			return 'front';
		}
		if ( class_exists( 'WooCommerce' ) ) {
			if ( is_product() ) {
				return 'product';
			}
			if ( is_checkout() ) {
				return 'checkout';
			}
			if ( is_cart() ) {
				return 'cart';
			}
			if ( is_account_page() ) {
				return 'account';
			}
			if ( is_shop() || is_product_taxonomy() ) {
				return 'catalogue';
			}
		}
		if ( is_singular( 'post' ) ) {
			return 'post';
		}
		if ( is_home() || is_archive() || is_search() ) {
			return 'blog';
		}
		if ( is_page() ) {
			return 'page';
		}

		return 'other';
	}

	/**
	 * Open the buffer on a plain front-end page view.
	 */
	public function start(): void {
		if ( is_admin() || is_feed() || is_robots() || is_trackback() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}

		if ( 'GET' !== sanitize_text_field( wp_unslash( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) ) || is_customize_preview() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a read-only switch, no state changes.
		if ( isset( $_GET['oc_critical'] ) && '0' === $_GET['oc_critical'] ) {
			return;
		}

		/**
		 * Whether the first screen's CSS is inlined on this site.
		 *
		 * @param bool $on Default true.
		 */
		if ( ! apply_filters( 'oc_critical_css', true ) ) {
			return;
		}

		ob_start( array( $this, 'filter' ) );
	}

	/**
	 * Rewrite the finished page: inline the extract, let the sheets load late.
	 *
	 * @param string $html The whole page.
	 */
	public function filter( string $html ): string {
		try {
			$out = $this->rewrite( $html );
		} catch ( \Throwable $e ) {
			$out = '';
		}

		return '' !== $out ? $out : $html;
	}

	/**
	 * The rewrite proper; '' when the page is left alone.
	 *
	 * @param string $html The whole page.
	 */
	private function rewrite( string $html ): string {
		$head_end = strpos( $html, '</head>' );
		$body_at  = strpos( $html, '<body' );

		if ( false === $head_end || false === $body_at || $body_at < $head_end ) {
			return '';
		}

		$head = substr( $html, 0, $head_end );

		// The stylesheets the extract is cut from, in the order they stand.
		if ( ! preg_match_all( '#<link\b[^>]*\brel=([\'"])stylesheet\1[^>]*>#i', $head, $m, PREG_OFFSET_CAPTURE ) ) {
			return '';
		}

		$links = array();

		foreach ( $m[0] as $hit ) {
			$tag = $hit[0];

			if ( ! preg_match( '#\bid=([\'"])([^\'"]+)-css\1#', $tag, $id ) || ! self::wanted( $id[2] ) ) {
				continue;
			}

			if ( ! preg_match( '#\bhref=([\'"])([^\'"]+)\1#', $tag, $href ) ) {
				continue;
			}

			$path = self::local_path( html_entity_decode( $href[2], ENT_QUOTES ) );

			if ( '' === $path ) {
				continue;
			}

			$media = preg_match( '#\bmedia=([\'"])([^\'"]+)\1#', $tag, $md ) ? $md[2] : 'all';

			$links[] = array(
				'tag'   => $tag,
				'at'    => $hit[1],
				'path'  => $path,
				'media' => $media,
			);
		}

		if ( ! $links ) {
			return '';
		}

		// The whole page's names, not a guess at the first screen: a two
		// column layout (the checkout, a filtered catalogue) puts what the
		// eye sees first late in the markup, and a byte limit cut it off.
		$type   = self::type();
		$tokens = self::tokens( substr( $html, $body_at ) );

		if ( count( $tokens ) < 10 ) {
			return '';
		}

		// What earlier pages of this kind reported their scripts built.
		$learned = get_option( 'oc_critical_learned', array() );

		foreach ( (array) ( is_array( $learned ) ? ( $learned[ $type ] ?? array() ) : array() ) as $c ) {
			$tokens[ '.' . (string) $c ] = true;
		}

		$stamp = OC_THEME_VERSION;

		foreach ( $links as $l ) {
			$stamp .= '|' . $l['path'] . ':' . (int) filemtime( $l['path'] ) . ':' . $l['media'];
		}

		$names = array_keys( $tokens );
		sort( $names );
		$key = 'oc_crit_' . md5( $stamp . '|' . implode( ' ', $names ) );
		$css = get_transient( $key );

		if ( ! is_string( $css ) ) {
			$css = '';

			foreach ( $links as $l ) {
				$part = self::extract( (string) file_get_contents( $l['path'] ), $tokens ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a stylesheet of this site on disk.

				if ( '' !== $part ) {
					$css .= 'all' === $l['media'] ? $part : '@media ' . $l['media'] . '{' . $part . '}';
				}
			}

			$css = '' === $css ? '-' : $css; // '-' remembers "nothing to inline".
			set_transient( $key, $css, 7 * DAY_IN_SECONDS );
		}

		if ( '-' === $css || strlen( $css ) > self::MAX_BYTES ) {
			return '';
		}

		// Rewrite from the end so the offsets stay true.
		$out = $html;

		for ( $i = count( $links ) - 1; $i >= 0; $i-- ) {
			$l    = $links[ $i ];
			$late = self::late( $l['tag'], $l['media'] );

			if ( 0 === $i ) {
				$late = '<meta name="oc-critical" content="' . esc_attr( $type . ':' . substr( $key, -12 ) . ':' . self::token() ) . '">' . "\n"
					. '<style id="oc-critical">' . $css . '</style>' . "\n" . $late;
			}

			$out = substr_replace( $out, $late, $l['at'], strlen( $l['tag'] ) );
		}

		return $out;
	}

	/**
	 * Is this stylesheet one to cut and defer? Every sheet served from this
	 * site is, bar the few in SKIP; a sheet from elsewhere (a font service)
	 * cannot be read and stays as it is.
	 *
	 * @param string $handle The link id without "-css".
	 */
	private static function wanted( string $handle ): bool {
		foreach ( self::SKIP as $h ) {
			if ( 0 === strpos( $handle, $h ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * The file on disk behind a same-site stylesheet address, or ''.
	 *
	 * @param string $href The link's href.
	 */
	private static function local_path( string $href ): string {
		$href = (string) preg_replace( '/[?#].*$/', '', $href );
		$base = (string) preg_replace( '#^https?:#', '', content_url() );
		$here = (string) preg_replace( '#^https?:#', '', $href );

		if ( '' === $base || 0 !== strpos( $here, $base ) ) {
			return '';
		}

		$path = WP_CONTENT_DIR . substr( $here, strlen( $base ) );

		return is_readable( $path ) && preg_match( '/\.css$/', $path ) ? $path : '';
	}

	/**
	 * The link tag, loading late: asked for as a print stylesheet, which a
	 * browser fetches at its lowest priority and never waits for, and
	 * switched to the screen on arrival. Not a preload — that fetches at
	 * high priority and took the bandwidth the banner picture needed, and
	 * the largest paint came later, not sooner. A plain copy for a browser
	 * without scripts.
	 *
	 * @param string $tag   The original link tag.
	 * @param string $media Its media.
	 */
	private static function late( string $tag, string $media ): string {
		$pre = (string) preg_replace( '#\bmedia=([\'"])[^\'"]*\1#i', '', $tag );
		$pre = (string) preg_replace( '#\bid=([\'"])([^\'"]+)\1#', 'id="$2-late"', $pre );
		$pre = (string) preg_replace( '#\s*/?>$#', ' media="print" fetchpriority="low" onload="this.onload=null;this.media=' . esc_attr( wp_json_encode( $media ) ) . '">', $pre );

		return $pre . '<noscript>' . $tag . '</noscript>';
	}

	/**
	 * Every class name and id in a stretch of markup, plus the states a
	 * script sets at once; keys for a cheap lookup.
	 *
	 * @param string $markup HTML.
	 * @return array<string,true>
	 */
	private static function tokens( string $markup ): array {
		$set = array();

		// A name with a long number in it (post-34114, wp-image-9031) is
		// one no rule targets — and one that would make every product its
		// own cache entry. Two digits (oc-ratio-60-40) are a real name.
		if ( preg_match_all( '#\bclass=([\'"])([^\'"]*)\1#', $markup, $m ) ) {
			foreach ( $m[2] as $list ) {
				foreach ( preg_split( '/\s+/', $list ) as $c ) {
					if ( '' !== $c && ! preg_match( '/\d{3,}/', $c ) ) {
						$set[ '.' . $c ] = true;
					}
				}
			}
		}

		if ( preg_match_all( '#\bid=([\'"])([^\'"\s]+)\1#', $markup, $m ) ) {
			foreach ( $m[2] as $id ) {
				if ( ! preg_match( '/\d{3,}/', $id ) ) {
					$set[ '#' . $id ] = true;
				}
			}
		}

		foreach ( self::STATES as $c ) {
			$set[ '.' . $c ] = true;
		}

		// And every name the scripts build or switch on (scripts/minify.py
		// writes the list): a slider's dots, the folded checkout summary,
		// an open drawer — markup the page does not carry yet.
		$built = OC_THEME_DIR . '/inc/critical-js-classes.php';
		$list  = file_exists( $built ) ? include $built : array();

		foreach ( (array) $list as $c ) {
			$set[ '.' . (string) $c ] = true;
		}

		return $set;
	}

	/* ------------------------------------------------------------ the cut */

	/**
	 * The rules of a stylesheet whose selectors are satisfied by the tokens.
	 *
	 * @param string             $css    The stylesheet.
	 * @param array<string,true> $tokens Class names and ids present.
	 */
	public static function extract( string $css, array $tokens ): string {
		$css = (string) preg_replace( '#/\*.*?\*/#s', '', $css );

		return self::emit( self::parse( $css ), $tokens );
	}

	/**
	 * Top-level nodes of a stylesheet: array{0:string,1:string,2:mixed}
	 * — ("rule", prelude, body), ("at", prelude, children) for a nesting
	 * at-rule, ("raw", text, null) for the rest.
	 *
	 * @param string $text CSS.
	 * @return array<int,array{0:string,1:string,2:mixed}>
	 */
	private static function parse( string $text ): array {
		$nodes = array();
		$n     = strlen( $text );
		$i     = 0;
		$start = 0;

		while ( $i < $n ) {
			$c = $text[ $i ];

			if ( '"' === $c || "'" === $c ) {
				$j = $i + 1;
				while ( $j < $n && $text[ $j ] !== $c ) {
					$j += '\\' === $text[ $j ] ? 2 : 1;
				}
				$i = $j + 1;
				continue;
			}

			if ( ';' === $c ) {
				$stmt = trim( substr( $text, $start, $i + 1 - $start ) );
				if ( '' !== $stmt ) {
					$nodes[] = array( 'raw', $stmt, null );
				}
				$start = $i + 1;
				++$i;
				continue;
			}

			if ( '{' === $c ) {
				$prelude = trim( substr( $text, $start, $i - $start ) );
				$depth   = 1;
				$j       = $i + 1;

				while ( $j < $n && $depth ) {
					$d = $text[ $j ];
					if ( '"' === $d || "'" === $d ) {
						$k = $j + 1;
						while ( $k < $n && $text[ $k ] !== $d ) {
							$k += '\\' === $text[ $k ] ? 2 : 1;
						}
						$j = $k + 1;
						continue;
					}
					if ( '{' === $d ) {
						++$depth;
					} elseif ( '}' === $d ) {
						--$depth;
					}
					++$j;
				}

				$body = substr( $text, $i + 1, max( 0, $j - 1 - ( $i + 1 ) ) );

				if ( '' !== $prelude && '@' === $prelude[0] ) {
					$name = strtolower( strtok( $prelude, " (\t\n" ) );
					if ( in_array( $name, array( '@media', '@supports', '@container', '@layer', '@document' ), true ) ) {
						$nodes[] = array( 'at', $prelude, self::parse( $body ) );
					} else {
						$nodes[] = array( 'raw', $prelude . '{' . $body . '}', null );
					}
				} else {
					$nodes[] = array( 'rule', $prelude, $body );
				}

				$start = $j;
				$i     = $j;
				continue;
			}

			++$i;
		}

		return $nodes;
	}

	/**
	 * The text of the nodes the tokens satisfy.
	 *
	 * @param array<int,array{0:string,1:string,2:mixed}> $nodes  Parsed CSS.
	 * @param array<string,true>                          $tokens Present names.
	 */
	private static function emit( array $nodes, array $tokens ): string {
		$out = '';

		foreach ( $nodes as $node ) {
			list( $kind, $a, $b ) = $node;

			if ( 'raw' === $kind ) {
				// @font-face, @keyframes, @charset, @import: small, and the
				// first screen may lean on any of them.
				$out .= $a;
			} elseif ( 'rule' === $kind ) {
				$keep = self::selectors_kept( $a, $tokens );
				if ( '' !== $keep ) {
					$out .= $keep . '{' . $b . '}';
				}
			} else {
				$inner = self::emit( $b, $tokens );
				if ( '' !== $inner ) {
					$out .= $a . '{' . $inner . '}';
				}
			}
		}

		return $out;
	}

	/**
	 * The selectors of a list that ask for nothing missing, joined; '' when
	 * none does. A class or id inside parentheses (:is, :not, :has) is not
	 * a requirement.
	 *
	 * @param string             $prelude The selector list.
	 * @param array<string,true> $tokens  Present names.
	 */
	private static function selectors_kept( string $prelude, array $tokens ): string {
		$kept = array();

		foreach ( self::split( $prelude ) as $sel ) {
			$bare = $sel;
			while ( false !== strpos( $bare, '(' ) ) {
				$bare = (string) preg_replace( '/\([^()]*\)/', '', $bare, -1, $n );
				if ( ! $n ) {
					break;
				}
			}

			$ok = true;

			if ( preg_match_all( '/[.#][A-Za-z_-][\w-]*/', $bare, $m ) ) {
				foreach ( $m[0] as $need ) {
					if ( ! isset( $tokens[ $need ] ) ) {
						$ok = false;
						break;
					}
				}
			}

			if ( $ok ) {
				$kept[] = $sel;
			}
		}

		return implode( ',', $kept );
	}

	/**
	 * A selector list split on the commas outside parentheses.
	 *
	 * @param string $prelude The list.
	 * @return string[]
	 */
	private static function split( string $prelude ): array {
		$out   = array();
		$depth = 0;
		$cur   = '';
		$n     = strlen( $prelude );

		for ( $i = 0; $i < $n; $i++ ) {
			$c = $prelude[ $i ];
			if ( '(' === $c ) {
				++$depth;
			} elseif ( ')' === $c ) {
				--$depth;
			}
			if ( ',' === $c && 0 === $depth ) {
				$out[] = trim( $cur );
				$cur   = '';
			} else {
				$cur .= $c;
			}
		}

		$out[] = trim( $cur );

		return array_values( array_filter( $out, 'strlen' ) );
	}
}
