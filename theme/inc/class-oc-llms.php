<?php
/**
 * /llms.txt — the shop, described for AI agents.
 *
 * A Markdown file at the site root (llmstxt.org): one heading, a short
 * blockquote, and sections of links with a line each. Shopping agents and
 * AI browsers read it to learn what the shop sells, where the catalogue
 * is, how to add to the cart and where the policies live. It is never
 * fetched by a visitor's browser, so it costs the page nothing.
 *
 * Built from what the site already knows — the store details, the
 * categories, the brands, the WooCommerce pages, the public Store API,
 * the sitemap and the product feeds — plus two fields on the SEO screen
 * ("AI agents"): a description and free notes. Off with one checkbox.
 *
 * @package OC\Theme
 */

namespace OC\Theme;

/**
 * The llms.txt file.
 */
final class Llms {

	/**
	 * Hooks.
	 */
	public function register(): void {
		// Before anything routes, but after WooCommerce has registered its
		// post type and taxonomies (init 5): /llms.txt answers whether or
		// not pretty permalinks are on, and no rewrite rule has to be
		// flushed.
		add_action( 'wp_loaded', array( $this, 'serve' ), 0 );
		add_filter( 'robots_txt', array( $this, 'robots_txt' ), 20 );
	}

	/**
	 * Is the file on?
	 *
	 * @return bool
	 */
	public static function on(): bool {
		$s = Seo::settings();

		return ! empty( $s['llms_on'] ) && class_exists( 'WooCommerce' );
	}

	/**
	 * Answer /llms.txt.
	 */
	public function serve(): void {
		$path = (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- compared against a literal.
		$home = rtrim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );

		if ( $home . '/llms.txt' !== $path ) {
			return;
		}

		if ( ! self::on() ) {
			status_header( 404 );
			nocache_headers();
			header( 'Content-Type: text/plain; charset=utf-8' );
			echo "Not found.\n";
			exit;
		}

		header( 'Content-Type: text/markdown; charset=utf-8' );
		header( 'Cache-Control: public, max-age=3600' );
		header( 'X-Robots-Tag: noindex' );

		echo self::body(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markdown text, built from escaped parts below.
		exit;
	}

	/**
	 * Mention the file in robots.txt — a courtesy for crawlers that look
	 * there first.
	 *
	 * @param string $output The robots.txt body.
	 */
	public function robots_txt( $output ): string {
		if ( ! self::on() ) {
			return (string) $output;
		}

		$line = '# AI agents: ' . home_url( '/llms.txt' );

		return false === strpos( (string) $output, $line ) ? rtrim( (string) $output ) . "\n" . $line . "\n" : (string) $output;
	}

	/**
	 * The Markdown. Kept for an hour: categories and brands are counted
	 * from the database and a shop with thousands of products should not
	 * do that for every agent that passes by.
	 */
	public static function body(): string {
		$s   = Seo::settings();
		$key = 'oc_llms_' . md5( OC_THEME_VERSION . '|' . (string) $s['llms_about'] . '|' . (string) $s['llms_notes'] . '|' . get_locale() );
		$out = get_transient( $key );

		if ( is_string( $out ) && '' !== $out ) {
			return $out;
		}

		$out = self::build( $s );
		set_transient( $key, $out, HOUR_IN_SECONDS );

		return $out;
	}

	/**
	 * Forget the kept copy (after settings or catalogue changes).
	 */
	public static function forget(): void {
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_oc\\_llms\\_%' OR option_name LIKE '\\_transient\\_timeout\\_oc\\_llms\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- clearing our own transients by prefix.
	}

	/**
	 * One Markdown link line.
	 *
	 * @param string $title Link text.
	 * @param string $url   Address.
	 * @param string $note  What is there (optional).
	 */
	private static function line( string $title, string $url, string $note = '' ): string {
		$title = str_replace( array( '[', ']', "\n" ), array( '(', ')', ' ' ), wp_strip_all_tags( $title ) );
		$note  = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $note ) ) );

		return '- [' . $title . '](' . esc_url_raw( $url ) . ')' . ( '' === $note ? '' : ': ' . $note ) . "\n";
	}

	/**
	 * Compose the file.
	 *
	 * @param array<string,mixed> $s SEO settings.
	 */
	private static function build( array $s ): string {
		$name  = (string) get_bloginfo( 'name' );
		$about = trim( (string) $s['llms_about'] );

		if ( '' === $about ) {
			$about = trim( (string) $s['home_desc'] );
		}

		if ( '' === $about ) {
			$about = trim( (string) get_bloginfo( 'description' ) );
		}

		$md = '# ' . wp_strip_all_tags( $name ) . "\n\n";

		if ( '' !== $about ) {
			$md .= '> ' . preg_replace( '/\s+/', ' ', wp_strip_all_tags( $about ) ) . "\n\n";
		}

		// The facts an agent needs before it shops: who sells, where,
		// how to reach them, in what currency and language.
		$facts = array();

		if ( class_exists( '\\OC\\Theme\\Contact' ) ) {
			$company = Contact::company();
			$address = Contact::address();
			$phone   = Contact::phone();
			$email   = Contact::email();
			$hours   = Contact::hours();

			if ( '' !== $company && $company !== $name ) {
				/* translators: %s: company name */
				$facts[] = sprintf( __( 'Operated by %s.', 'oc-theme' ), $company );
			}
			if ( '' !== $address ) {
				/* translators: %s: address */
				$facts[] = sprintf( __( 'Address: %s.', 'oc-theme' ), $address );
			}
			if ( '' !== $phone ) {
				/* translators: %s: phone */
				$facts[] = sprintf( __( 'Phone: %s.', 'oc-theme' ), $phone );
			}
			if ( '' !== $email ) {
				/* translators: %s: email */
				$facts[] = sprintf( __( 'Email: %s.', 'oc-theme' ), $email );
			}
			if ( '' !== $hours ) {
				/* translators: %s: opening hours */
				$facts[] = sprintf( __( 'Hours: %s.', 'oc-theme' ), $hours );
			}
		}

		if ( function_exists( 'get_woocommerce_currency' ) ) {
			/* translators: %s: currency code */
			$facts[] = sprintf( __( 'Prices are in %s and include VAT.', 'oc-theme' ), get_woocommerce_currency() );
		}

		$lang = str_replace( '_', '-', get_locale() );
		/* translators: %s: language code */
		$facts[] = sprintf( __( 'The site is in %s.', 'oc-theme' ), $lang );

		$gateways = array();

		if ( function_exists( 'WC' ) && WC()->payment_gateways() ) {
			foreach ( WC()->payment_gateways()->get_available_payment_gateways() as $gw ) {
				$title = trim( wp_strip_all_tags( (string) $gw->get_title() ) );

				if ( '' !== $title ) {
					$gateways[] = $title;
				}
			}
		}

		if ( $gateways ) {
			/* translators: %s: list of payment methods */
			$facts[] = sprintf( __( 'Payment: %s.', 'oc-theme' ), implode( ', ', array_unique( $gateways ) ) );
		}

		// Never empty: the language line is always there. One paragraph —
		// opening hours may be typed over several lines.
		$md .= preg_replace( '/\s+/', ' ', implode( ' ', array_map( 'wp_strip_all_tags', $facts ) ) ) . "\n\n";

		// Shop: the catalogue and its top-level categories.
		$md .= '## ' . __( 'Shop', 'oc-theme' ) . "\n\n";

		$shop_id = function_exists( 'wc_get_page_id' ) ? (int) wc_get_page_id( 'shop' ) : 0;
		$count   = (array) wp_count_posts( 'product' );
		$total   = (int) ( $count['publish'] ?? 0 );

		if ( $shop_id > 0 ) {
			/* translators: %d: number of products */
			$md .= self::line( __( 'All products', 'oc-theme' ), (string) get_permalink( $shop_id ), sprintf( _n( '%d product', '%d products', $total, 'oc-theme' ), $total ) );
		}

		$cats = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'parent'     => 0,
				'hide_empty' => true,
				'orderby'    => 'count',
				'order'      => 'DESC',
				'number'     => 40,
			)
		);

		foreach ( is_array( $cats ) ? $cats : array() as $cat ) {
			if ( 'uncategorized' === $cat->slug ) {
				continue;
			}

			$note = trim( wp_strip_all_tags( (string) $cat->description ) );

			if ( '' === $note ) {
				/* translators: %d: number of products */
				$note = sprintf( _n( '%d product', '%d products', (int) $cat->count, 'oc-theme' ), (int) $cat->count );
			} elseif ( mb_strlen( $note ) > 160 ) {
				$note = mb_substr( $note, 0, 157 ) . '…';
			}

			$md .= self::line( (string) $cat->name, (string) get_term_link( $cat ), $note );
		}

		$md .= "\n";

		// Brands, where the shop has them.
		$brand_tax = class_exists( '\\OC\\Theme\\Search' ) && method_exists( '\\OC\\Theme\\Search', 'brand_taxonomy' ) ? (string) Search::brand_taxonomy() : '';

		if ( '' !== $brand_tax && taxonomy_exists( $brand_tax ) ) {
			$brands = get_terms(
				array(
					'taxonomy'   => $brand_tax,
					'hide_empty' => true,
					'orderby'    => 'count',
					'order'      => 'DESC',
					'number'     => 60,
				)
			);

			if ( is_array( $brands ) && $brands ) {
				$md .= '## ' . __( 'Brands', 'oc-theme' ) . "\n\n";

				foreach ( $brands as $brand ) {
					/* translators: %d: number of products */
					$md .= self::line( (string) $brand->name, (string) get_term_link( $brand ), sprintf( _n( '%d product', '%d products', (int) $brand->count, 'oc-theme' ), (int) $brand->count ) );
				}

				$md .= "\n";
			}
		}

		// Buying: the pages that act, and how to act without them.
		$md .= '## ' . __( 'Buying', 'oc-theme' ) . "\n\n";

		foreach ( array(
			'cart'      => __( 'Cart', 'oc-theme' ),
			'checkout'  => __( 'Checkout', 'oc-theme' ),
			'myaccount' => __( 'My account', 'oc-theme' ),
		) as $page => $label ) {
			$id = function_exists( 'wc_get_page_id' ) ? (int) wc_get_page_id( $page ) : 0;

			if ( $id > 0 ) {
				$md .= self::line( $label, (string) get_permalink( $id ) );
			}
		}

		$md .= "\n" . __( 'To add a product to the cart, open its page with `?add-to-cart=ID` (the product id from the Store API below), or use the Store API cart endpoints.', 'oc-theme' ) . "\n\n";

		// Policies.
		$policies = array();

		if ( class_exists( '\\OC\\Theme\\Legal\\Terms' ) ) {
			$id = (int) Legal\Terms::page_id();
			if ( $id > 0 ) {
				$policies[] = array( __( 'Terms, shipping and returns', 'oc-theme' ), (string) get_permalink( $id ) );
			}
		}

		$privacy = (int) get_option( 'oc_privacy_page', 0 );

		if ( $privacy < 1 ) {
			$privacy = (int) get_option( 'wp_page_for_privacy_policy', 0 );
		}

		if ( $privacy > 0 && 'publish' === get_post_status( $privacy ) ) {
			$policies[] = array( __( 'Privacy policy', 'oc-theme' ), (string) get_permalink( $privacy ) );
		}

		if ( class_exists( '\\OC\\Theme\\Legal\\Accessibility' ) ) {
			$id = (int) Legal\Accessibility::page_id();
			if ( $id > 0 ) {
				$policies[] = array( __( 'Accessibility statement', 'oc-theme' ), (string) get_permalink( $id ) );
			}
		}

		if ( $policies ) {
			$md .= '## ' . __( 'Policies', 'oc-theme' ) . "\n\n";

			foreach ( $policies as $p ) {
				$md .= self::line( $p[0], $p[1] );
			}

			$md .= "\n";
		}

		// Data: machine-readable doors to the catalogue.
		$md .= '## ' . __( 'Product data', 'oc-theme' ) . "\n\n";
		$md .= self::line( __( 'Products (JSON, read-only Store API)', 'oc-theme' ), rest_url( 'wc/store/v1/products' ), __( 'Public; supports search, category, per_page and page parameters. Each product carries its id, prices, stock and permalink.', 'oc-theme' ) );
		$md .= self::line( __( 'Categories (JSON)', 'oc-theme' ), rest_url( 'wc/store/v1/products/categories' ) );

		if ( class_exists( '\\OC\\Theme\\Feeds\\Feeds' ) ) {
			foreach ( Feeds\Feeds::all() as $fkey => $feed ) {
				$target = (string) ( $feed['target'] ?? '' );

				// Every feed configured is live; the OpenAI one goes out by SFTP and has no address.
				if ( ! in_array( $target, array( 'google', 'meta', 'tiktok', 'zap' ), true ) ) {
					continue;
				}

				/* translators: %s: network name */
				$md .= self::line( sprintf( __( 'Product feed for %s', 'oc-theme' ), ucfirst( $target ) ), Feeds\Feeds::url( (string) $fkey ), 'csv' === ( $feed['format'] ?? '' ) ? 'CSV' : 'XML' );
			}
		}

		$md .= self::line( __( 'Sitemap', 'oc-theme' ), home_url( '/sitemap.xml' ) );
		$md .= "\n";

		// Optional: the blog, if there is one.
		$blog = (int) get_option( 'page_for_posts', 0 );

		if ( $blog > 0 && 'publish' === get_post_status( $blog ) ) {
			$md .= '## ' . __( 'Optional', 'oc-theme' ) . "\n\n";
			$md .= self::line( (string) get_the_title( $blog ), (string) get_permalink( $blog ), __( 'Articles and guides.', 'oc-theme' ) );
			$md .= "\n";
		}

		// Notes the shop owner wrote for agents.
		$notes = trim( (string) $s['llms_notes'] );

		if ( '' !== $notes ) {
			$md .= '## ' . __( 'Notes for agents', 'oc-theme' ) . "\n\n" . wp_strip_all_tags( $notes ) . "\n";
		}

		return rtrim( $md ) . "\n";
	}
}
