<?php
/**
 * Taking a page from the site the customer already has.
 *
 * Asking someone to find their terms page, copy it into Word and upload the
 * file is three chances to give up. They already have the page online, so we
 * take it from there: they paste the address — and usually they do not even
 * do that, because we find the four addresses ourselves from their home page.
 *
 * Nothing here trusts what comes back: only http(s), never an address inside
 * our own network, a size cap, and the HTML that survives is what
 * wp_kses_post allows.
 *
 * @package OC_Theme
 */

declare( strict_types = 1 );

namespace OC\Theme\Onboard;

defined( 'ABSPATH' ) || exit;

// The DOM's own properties are camelCase; that is their name, not our style.
// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

/**
 * Fetching and reading a page from somewhere else.
 */
final class Fetch {

	/**
	 * How much of a page we are willing to read.
	 */
	const MAX_BYTES = 2097152;

	/**
	 * What each kind of page tends to be called, in both languages.
	 *
	 * @return array<string,string>
	 */
	public static function patterns(): array {
		return array(
			'terms'   => '(תקנון|תנאי\s*שימוש|תנאים\s*ו|terms|conditions)',
			'privacy' => '(מדיניות\s*הפרטיות|פרטיות|privacy)',
			'a11y'    => '(נגישות|accessib)',
			'about'   => '(אודות|עלינו|קצת\s*עלינו|about)',
		);
	}

	/**
	 * Is this an address we are willing to ask for? Public http(s) only —
	 * never something that would make the server talk to itself or to the
	 * network it sits in.
	 *
	 * @param string $url The address.
	 */
	public static function allowed( string $url ): bool {
		$parts = wp_parse_url( $url );

		if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return false;
		}

		if ( ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
			return false;
		}

		$host = strtolower( (string) $parts['host'] );

		if ( in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true ) ) {
			return false;
		}

		$ip = filter_var( $host, FILTER_VALIDATE_IP ) ? $host : gethostbyname( $host );

		if ( filter_var( $ip, FILTER_VALIDATE_IP ) && ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			return false;
		}

		return true;
	}

	/**
	 * The page's HTML, or an empty string.
	 *
	 * @param string $url The address.
	 */
	public static function get( string $url ): string {
		if ( ! self::allowed( $url ) ) {
			return '';
		}

		$res = wp_safe_remote_get(
			$url,
			array(
				'timeout'    => 15,
				'user-agent' => 'OC Theme onboarding',
				'headers'    => array( 'Accept' => 'text/html' ),
			)
		);

		if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			return '';
		}

		$type = (string) wp_remote_retrieve_header( $res, 'content-type' );

		if ( '' !== $type && false === strpos( $type, 'html' ) ) {
			return '';
		}

		return substr( (string) wp_remote_retrieve_body( $res ), 0, self::MAX_BYTES );
	}

	/**
	 * A document for the HTML, or null.
	 *
	 * @param string $html The page.
	 */
	private static function dom( string $html ): ?\DOMDocument {
		if ( '' === trim( $html ) || ! class_exists( '\\DOMDocument' ) ) {
			return null;
		}

		$doc = new \DOMDocument();

		libxml_use_internal_errors( true );
		$ok = $doc->loadHTML( '<?xml encoding="UTF-8">' . $html );
		libxml_clear_errors();

		return $ok ? $doc : null;
	}

	/**
	 * The four addresses, read off the links of a home page. Every value is
	 * an address or an empty string.
	 *
	 * @param string $home The customer's current site.
	 * @return array<string,string>
	 */
	public static function discover( string $home ): array {
		$found = array_fill_keys( array_keys( self::patterns() ), '' );
		$doc   = self::dom( self::get( $home ) );

		if ( ! $doc ) {
			return $found;
		}

		foreach ( $doc->getElementsByTagName( 'a' ) as $a ) {
			$href = trim( (string) $a->getAttribute( 'href' ) );
			$text = trim( (string) $a->textContent );

			if ( '' === $href || 0 === strpos( $href, '#' ) || 0 === strpos( $href, 'mailto:' ) || 0 === strpos( $href, 'tel:' ) ) {
				continue;
			}

			$full = self::absolute( $href, $home );

			if ( '' === $full ) {
				continue;
			}

			foreach ( self::patterns() as $kind => $pattern ) {
				if ( '' !== $found[ $kind ] ) {
					continue;
				}

				// The words a person reads decide it; the address is the
				// fallback, since a Hebrew page often has an English slug.
				if ( preg_match( '~' . $pattern . '~iu', $text ) || preg_match( '~' . $pattern . '~iu', rawurldecode( $full ) ) ) {
					$found[ $kind ] = $full;
				}
			}
		}

		return $found;
	}

	/**
	 * A relative address made whole.
	 *
	 * @param string $href The address as written.
	 * @param string $base The page it was written on.
	 */
	private static function absolute( string $href, string $base ): string {
		if ( preg_match( '~^https?://~i', $href ) ) {
			return $href;
		}

		$b = wp_parse_url( $base );

		if ( empty( $b['scheme'] ) || empty( $b['host'] ) ) {
			return '';
		}

		$root = $b['scheme'] . '://' . $b['host'];

		if ( 0 === strpos( $href, '//' ) ) {
			return $b['scheme'] . ':' . $href;
		}

		return $root . '/' . ltrim( $href, '/' );
	}

	/**
	 * The words of a page: its own content, without the furniture around it.
	 *
	 * @param string $url The address.
	 * @return array{html:string,title:string}
	 */
	public static function article( string $url ): array {
		$empty = array(
			'html'  => '',
			'title' => '',
		);
		$doc   = self::dom( self::get( $url ) );

		if ( ! $doc ) {
			return $empty;
		}

		$xp = new \DOMXPath( $doc );

		// Everything that is not the page itself.
		foreach ( array( 'script', 'style', 'noscript', 'nav', 'header', 'footer', 'form', 'iframe', 'aside', 'svg', 'button' ) as $tag ) {
			$gone = array();

			foreach ( $doc->getElementsByTagName( $tag ) as $node ) {
				$gone[] = $node;
			}

			foreach ( $gone as $node ) {
				if ( $node->parentNode ) {
					$node->parentNode->removeChild( $node );
				}
			}
		}

		$title = '';
		$heads = $doc->getElementsByTagName( 'h1' );

		if ( $heads->length ) {
			$title = trim( (string) $heads->item( 0 )->textContent );
		}

		$tries = array(
			'//*[contains(concat(" ", normalize-space(@class), " "), " entry-content ")]',
			'//*[contains(concat(" ", normalize-space(@class), " "), " post-content ")]',
			'//article',
			'//main',
			'//*[@id="content"]',
			'//body',
		);

		foreach ( $tries as $path ) {
			$hit = $xp->query( $path );

			if ( ! $hit || ! $hit->length ) {
				continue;
			}

			$html = self::inner( $hit->item( 0 ) );

			// A wrapper that holds almost nothing is the wrong wrapper.
			if ( mb_strlen( wp_strip_all_tags( $html ) ) < 200 ) {
				continue;
			}

			return array(
				'html'  => wp_kses_post( $html ),
				'title' => $title,
			);
		}

		return $empty;
	}

	/**
	 * A node's children as HTML.
	 *
	 * @param \DOMNode $node The node.
	 */
	private static function inner( \DOMNode $node ): string {
		$out = '';

		foreach ( $node->childNodes as $child ) {
			$out .= (string) $node->ownerDocument->saveHTML( $child );
		}

		return $out;
	}
}

// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
