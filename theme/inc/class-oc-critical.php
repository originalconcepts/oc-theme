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
	const MAX_BYTES = 200000;

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
	}

	/**
	 * Open the buffer on a plain front-end page view.
	 */
	public function start(): void {
		if ( is_admin() || is_feed() || is_robots() || is_trackback() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}

		if ( 'GET' !== ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) || is_customize_preview() ) {
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
		$tokens = self::tokens( substr( $html, $body_at ) );

		if ( count( $tokens ) < 10 ) {
			return '';
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
				$part = self::extract( (string) file_get_contents( $l['path'] ), $tokens );

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
				$late = '<style id="oc-critical">' . $css . '</style>' . "\n" . $late;
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
	 * The link tag, loading late: fetched at once as a preload, applied on
	 * arrival; a plain copy for a browser without scripts.
	 *
	 * @param string $tag   The original link tag.
	 * @param string $media Its media.
	 */
	private static function late( string $tag, string $media ): string {
		$pre = (string) preg_replace( '#\brel=([\'"])stylesheet\1#i', 'rel="preload" as="style"', $tag );
		$pre = (string) preg_replace( '#\bmedia=([\'"])[^\'"]*\1#i', '', $pre );
		$pre = (string) preg_replace( '#\bid=([\'"])([^\'"]+)\1#', 'id="$2-late"', $pre );
		$pre = (string) preg_replace( '#\s*/?>$#', ' onload="this.onload=null;this.rel=\'stylesheet\';this.media=' . esc_attr( wp_json_encode( $media ) ) . '">', $pre );

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

		// A name with a number in it (post-34114, menu-item-77) is one no
		// rule targets — and one that would make every product its own
		// cache entry.
		if ( preg_match_all( '#\bclass=([\'"])([^\'"]*)\1#', $markup, $m ) ) {
			foreach ( $m[2] as $list ) {
				foreach ( preg_split( '/\s+/', $list ) as $c ) {
					if ( '' !== $c && ! preg_match( '/\d{2,}/', $c ) ) {
						$set[ '.' . $c ] = true;
					}
				}
			}
		}

		if ( preg_match_all( '#\bid=([\'"])([^\'"\s]+)\1#', $markup, $m ) ) {
			foreach ( $m[2] as $id ) {
				if ( ! preg_match( '/\d{2,}/', $id ) ) {
					$set[ '#' . $id ] = true;
				}
			}
		}

		foreach ( self::STATES as $c ) {
			$set[ '.' . $c ] = true;
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
				$i++;
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
						$depth++;
					} elseif ( '}' === $d ) {
						$depth--;
					}
					$j++;
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

			$i++;
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
				$depth++;
			} elseif ( ')' === $c ) {
				$depth--;
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
