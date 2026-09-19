<?php
/**
 * Telling the page cache that something it holds is out of date.
 *
 * A shop on managed hosting is usually sitting behind a page cache it did not
 * install and cannot see. WordPress clears it when a post is saved; a change
 * made through a REST route — a product moved in the catalogue, a tile made
 * wider — goes past all of that, and the shopper keeps being handed the page
 * as it was. On this host that is seven days.
 *
 * So we say so, in the ways the caches of the world listen for: the host's own
 * optimiser first when it is there, and the actions the well-known cache
 * plugins fire, which most hosts mirror.
 *
 * @package OC_Theme
 */

namespace OC\Theme;

defined( 'ABSPATH' ) || exit;

/**
 * One small courtesy, called from wherever the catalogue changes.
 */
final class Cache {

	/**
	 * How many pages of an archive are worth forgetting. Someone arranging
	 * a category is almost always on its first pages, and a page nobody
	 * asked for is not in the cache to begin with.
	 */
	const PAGES = 8;

	/**
	 * Forget these addresses.
	 *
	 * @param string[] $urls Absolute URLs.
	 */
	public static function forget( array $urls ): void {
		$urls = array_values( array_unique( array_filter( $urls ) ) );

		if ( ! $urls ) {
			return;
		}

		// Named rather than written out: it belongs to the host, not to us,
		// and it is only there on some of them.
		$host = array( '\Proginter_Optimizer_MU', 'purge_uri' );
		$has  = is_callable( $host );

		foreach ( $urls as $url ) {
			if ( $has ) {
				call_user_func( $host, (string) wp_parse_url( $url, PHP_URL_PATH ) );
			}

			// What the rest of the world listens for. A host that mirrors
			// WP Rocket or W3 Total Cache — most of them do — hears this.
			do_action( 'w3tc_flush_url', $url );
			do_action( 'rocket_clean_files', array( $url ) );

			/**
			 * A page of the shop is no longer what it was.
			 *
			 * @param string $url The address.
			 */
			do_action( 'oc_cache_forget', $url );
		}
	}

	/**
	 * A category archive and the first pages of it.
	 *
	 * @param int $term_id Product category, or 0 for the shop.
	 */
	public static function forget_archive( int $term_id ): void {
		$base = $term_id > 0 ? get_term_link( $term_id, 'product_cat' ) : wc_get_page_permalink( 'shop' );

		if ( is_wp_error( $base ) || ! is_string( $base ) || '' === $base ) {
			return;
		}

		$urls = array( $base );

		for ( $n = 2; $n <= self::PAGES; $n++ ) {
			$urls[] = trailingslashit( $base ) . 'page/' . $n . '/';
		}

		self::forget( $urls );
	}

	/**
	 * Everywhere a product is shown: its own page, the shop, and every
	 * category it belongs to.
	 *
	 * @param int $product_id Product.
	 */
	public static function forget_product( int $product_id ): void {
		$urls = array( (string) get_permalink( $product_id ) );

		self::forget( $urls );
		self::forget_archive( 0 );

		$terms = get_the_terms( $product_id, 'product_cat' );

		foreach ( is_array( $terms ) ? $terms : array() as $term ) {
			self::forget_archive( (int) $term->term_id );
		}
	}
}
