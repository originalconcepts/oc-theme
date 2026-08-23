<?php
/**
 * The search panel: what the shopper sees before, during and after typing.
 *
 * The markup is built here rather than in the browser for two reasons. The
 * panel then opens with its contents already present — no request, no flash —
 * and a result looks exactly like the rest of the shop, because it is built
 * from the same pieces.
 *
 * @package OC_Theme
 */

declare( strict_types = 1 );

namespace OC\Theme;

defined( 'ABSPATH' ) || exit;

/**
 * Rendering the panel.
 */
final class Search_Panel {

	/**
	 * The magnifying glass, once.
	 */
	private static function glass(): string {
		return '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.8-3.8"/></svg>';
	}

	/**
	 * The whole panel, ready to open.
	 */
	public static function panel_html(): string {
		$mode  = 'min' === Search::look( 'panel', 'full' ) ? 'min' : 'full';
		$min   = max( 1, (int) Search::look( 'min', 2 ) );
		$more  = esc_url( self::results_url( '' ) );

		ob_start();
		?>
		<div id="oc-header-search" class="oc-searchbox" data-oc-search data-mode="<?php echo esc_attr( $mode ); ?>" data-min="<?php echo esc_attr( (string) $min ); ?>" data-action="<?php echo esc_url( home_url( '/' ) ); ?>" data-cart="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" hidden>
			<div class="oc-search__inner oc-searchbox__stage">
				<form class="oc-search__bar" role="search" method="get" action="<?php echo esc_url( home_url( '/' ) ); ?>">
					<button type="button" class="oc-search__wipe" hidden data-oc-search-clear aria-label="<?php esc_attr_e( 'Clear the text', 'oc-theme' ); ?>">
						<svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>
					</button>
					<input
						type="search"
						name="s"
						class="oc-search__field"
						autocomplete="off"
						autocapitalize="off"
						spellcheck="false"
						role="combobox"
						aria-expanded="false"
						aria-autocomplete="list"
						aria-controls="oc-search-out"
						placeholder="<?php esc_attr_e( 'Search products', 'oc-theme' ); ?>"
						data-oc-search-field />
					<input type="hidden" name="post_type" value="product" />
					<button type="submit" class="oc-search__go" aria-label="<?php esc_attr_e( 'Show all results', 'oc-theme' ); ?>"><?php echo self::glass(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
					<span class="oc-search__sep" aria-hidden="true"></span>
					<button type="button" class="oc-search__close" data-oc-search-close aria-label="<?php esc_attr_e( 'Close search', 'oc-theme' ); ?>">
						<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>
					</button>
				</form>

				<div class="oc-search__body" id="oc-search-out" role="listbox" aria-label="<?php esc_attr_e( 'Search results', 'oc-theme' ); ?>">
					<?php if ( 'full' === $mode ) : ?>
						<div class="oc-search__idle" data-oc-search-idle><?php echo self::idle_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
					<?php endif; ?>
					<div class="oc-search__out" data-oc-search-out hidden></div>
				</div>

				<p class="oc-search__live screen-reader-text" aria-live="polite" data-oc-search-live></p>
			</div>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Where a full results page lives.
	 *
	 * @param string $term Query.
	 */
	public static function results_url( string $term ): string {
		return add_query_arg(
			array(
				's'         => rawurlencode( $term ),
				'post_type' => 'product',
			),
			home_url( '/' )
		);
	}

	/* ---------------------------------------------------------- before typing */

	/**
	 * Popular searches, history and popular products.
	 */
	public static function idle_html(): string {
		$s     = Search::settings();
		$terms = Search::popular_terms( (int) $s['pop_days'], (int) $s['pop_count'] );
		$prods = Search::popular_products();

		ob_start();
		?>
		<div class="oc-search__cols">
			<div class="oc-search__side">
				<?php if ( $terms ) : ?>
					<h3 class="oc-search__h"><?php esc_html_e( 'Popular searches', 'oc-theme' ); ?></h3>
					<div class="oc-search__pills">
						<?php foreach ( $terms as $row ) : ?>
							<button type="button" class="oc-search__pill" data-oc-search-term="<?php echo esc_attr( $row->term ); ?>"><?php echo esc_html( $row->term ); ?></button>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>

				<?php if ( Search::look( 'history', true ) ) : ?>
					<div class="oc-search__hist" data-oc-search-history hidden>
						<h3 class="oc-search__h">
							<?php esc_html_e( 'Your searches', 'oc-theme' ); ?>
							<button type="button" class="oc-search__clearall" data-oc-search-history-clear><?php esc_html_e( 'Clear all', 'oc-theme' ); ?></button>
						</h3>
						<ul class="oc-search__histlist" data-oc-search-history-list></ul>
					</div>
				<?php endif; ?>
			</div>

			<div class="oc-search__main">
				<?php if ( $prods ) : ?>
					<h3 class="oc-search__h"><?php esc_html_e( 'Popular products', 'oc-theme' ); ?></h3>
					<?php echo self::products_html( $prods, '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php endif; ?>
			</div>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/* ----------------------------------------------------------- after typing */

	/**
	 * Everything the panel shows for a query.
	 *
	 * @param string $term What was typed.
	 * @param bool   $log  Whether to record the search.
	 * @return array<string,mixed>
	 */
	public static function results_html( string $term, bool $log = false, int $show = 0 ): array {
		$s     = Search::settings();
		$limit = $show > 0 ? $show : max( 1, (int) Search::look( 'limit', 6 ) );

		$ids = Search::product_ids( $term );

		$suggested = '';

		if ( ! $ids ) {
			$suggested = Search::rescue( $term );

			if ( '' !== $suggested ) {
				$ids = Search::product_ids( $suggested );
			}
		}

		if ( $log ) {
			// When a rescue worked, what the shopper meant is worth more than
			// what their fingers produced — that is what other shoppers should
			// be offered later. A search that found nothing keeps its raw
			// spelling, because that is the whole value of the empty report.
			$meant = ( '' !== $suggested && $ids ) ? $suggested : $term;

			Search::remember( $meant, count( $ids ) );
		}

		$total = count( $ids );
		$shown = array_slice( $ids, 0, $limit );

		$posts = array();

		if ( ! empty( $s['f_posts'] ) || ! empty( $s['f_pages'] ) ) {
			$kinds = array();

			if ( ! empty( $s['f_posts'] ) ) {
				$kinds[] = 'post';
			}

			if ( ! empty( $s['f_pages'] ) ) {
				$kinds[] = 'page';
			}

			if ( Search::look( 'show_post', true ) ) {
				$found = Search::find(
					'' !== $suggested ? $suggested : $term,
					array(
						'kinds' => $kinds,
						'limit' => 4,
					)
				);

				$posts = $found['ids'];
			}
		}

		ob_start();

		if ( ! $total && ! $posts ) {
			echo '<div class="oc-search__none">';
			printf(
				'<p class="oc-search__none-t">%s</p>',
				esc_html( sprintf( /* translators: %s: what was typed. */ __( 'Nothing found for “%s”', 'oc-theme' ), $term ) )
			);
			echo '<p class="oc-search__none-d">' . esc_html__( 'Try a shorter word, or one of these:', 'oc-theme' ) . '</p>';
			echo self::products_html( array_slice( Search::popular_products(), 0, 4 ), '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '</div>';

			return array(
				'html'  => (string) ob_get_clean(),
				'total' => 0,
				'term'  => $term,
			);
		}

		$side = self::side_html( '' !== $suggested ? $suggested : $term, $ids );

		?>
		<div class="oc-search__cols<?php echo $side ? '' : ' is-single'; ?>">
			<?php if ( $side ) : ?>
				<div class="oc-search__side"><?php echo $side; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
			<?php endif; ?>

			<div class="oc-search__main">
				<?php if ( '' !== $suggested ) : ?>
					<p class="oc-search__did">
						<?php
						printf(
							/* translators: %s: the word we think was meant. */
							esc_html__( 'Did you mean %s?', 'oc-theme' ),
							'<button type="button" class="oc-search__did-b" data-oc-search-term="' . esc_attr( $suggested ) . '">' . esc_html( $suggested ) . '</button>'
						);
						?>
					</p>
				<?php endif; ?>

				<?php if ( $shown ) : ?>
					<h3 class="oc-search__h"><?php esc_html_e( 'Products', 'oc-theme' ); ?></h3>
					<?php echo self::products_html( $shown, '' !== $suggested ? $suggested : $term ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

					<?php if ( $total > count( $shown ) ) : ?>
						<?php if ( 'min' === Search::look( 'panel', 'full' ) ) : ?>
							<button type="button" class="oc-search__more" data-oc-search-more="<?php echo esc_attr( (string) ( count( $shown ) + max( 1, (int) Search::look( 'limit', 6 ) ) ) ); ?>">
								<?php esc_html_e( 'Show more', 'oc-theme' ); ?>
							</button>
						<?php endif; ?>

						<a class="oc-search__all" href="<?php echo esc_url( self::results_url( '' !== $suggested ? $suggested : $term ) ); ?>">
							<?php
							printf(
								/* translators: %d: how many results there are. */
								esc_html__( 'All %d results', 'oc-theme' ),
								(int) $total
							);
							?>
						</a>
					<?php endif; ?>
				<?php endif; ?>

				<?php if ( $posts ) : ?>
					<h3 class="oc-search__h oc-search__h--posts"><?php esc_html_e( 'From the site', 'oc-theme' ); ?></h3>
					<ul class="oc-search__posts">
						<?php foreach ( $posts as $post_id ) : ?>
							<li>
								<a href="<?php echo esc_url( (string) get_permalink( $post_id ) ); ?>" data-oc-search-hit>
									<span class="oc-search__post-t"><?php echo esc_html( (string) get_the_title( $post_id ) ); ?></span>
									<span class="oc-search__post-k"><?php echo esc_html( 'page' === get_post_type( $post_id ) ? __( 'Page', 'oc-theme' ) : __( 'Article', 'oc-theme' ) ); ?></span>
								</a>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</div>
		</div>
		<?php

		return array(
			'html'  => (string) ob_get_clean(),
			'total' => $total,
			'term'  => '' !== $suggested ? $suggested : $term,
		);
	}

	/**
	 * Categories, brands and tags that match — the narrow column.
	 *
	 * Capped on purpose: this column must never grow taller than the products
	 * beside it, or the panel turns into a long ribbon of links.
	 *
	 * @param string $term Query.
	 */
	private static function side_html( string $term, array $ids ): string {
		$blocks = array();

		if ( Search::look( 'show_cat', true ) ) {
			$blocks[] = array( __( 'Categories', 'oc-theme' ), Search::result_facets( $ids, 'product_cat', 4 ), 'product_cat' );
		}

		if ( Search::look( 'show_brand', true ) ) {
			$brand = Search::brand_taxonomy();

			if ( $brand ) {
				$blocks[] = array( __( 'Brands', 'oc-theme' ), Search::result_facets( $ids, $brand, 4 ), $brand );
			}
		}

		if ( Search::look( 'show_tag', false ) ) {
			$blocks[] = array( __( 'Tags', 'oc-theme' ), Search::result_facets( $ids, 'product_tag', 4 ), 'product_tag' );
		}

		$out   = '';
		$lines = 0;

		foreach ( $blocks as $block ) {
			list( $label, $facets, $taxonomy ) = $block;

			if ( ! $facets ) {
				continue;
			}

			$rows = '';

			foreach ( $facets as $facet ) {
				// Nine lines is about the height of the product column beside
				// it; this is a shortcut, not a directory.
				if ( $lines >= 9 ) {
					break;
				}

				++$lines;

				$logo = '';

				if ( $taxonomy !== 'product_cat' && $taxonomy !== 'product_tag' && 'logo' === Search::look( 'brand_style', 'text' ) ) {
					$thumb = (int) get_term_meta( (int) $facet->term_id, 'thumbnail_id', true );

					if ( $thumb ) {
						$logo = wp_get_attachment_image( $thumb, 'thumbnail', false, array( 'class' => 'oc-search__brandimg', 'alt' => '' ) );
					}
				}

				$rows .= sprintf(
					'<li><a href="%s" data-oc-search-hit>%s<span>%s</span><span class="oc-search__n">%d</span></a></li>',
					esc_url( Search::narrowed_url( $term, $taxonomy, (string) $facet->slug ) ),
					$logo, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core markup.
					self::mark( (string) $facet->name, $term ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
					(int) $facet->n
				);
			}

			if ( '' === $rows ) {
				continue;
			}

			$out .= '<h3 class="oc-search__h">' . esc_html( $label ) . '</h3><ul class="oc-search__terms">' . $rows . '</ul>';
		}

		return $out;
	}

	/* ------------------------------------------------------------- products */

	/**
	 * A set of products, in whichever shape the site asked for.
	 *
	 * @param int[]  $ids  Product ids.
	 * @param string $term What was typed, for highlighting.
	 */
	public static function products_html( array $ids, string $term ): string {
		if ( ! $ids ) {
			return '';
		}

		// One trip for the meta of everything about to be drawn.
		_prime_post_caches( $ids, false, true );
		update_meta_cache( 'post', $ids );

		$card = 'card' === Search::look( 'layout', 'list' ) && 'min' !== Search::look( 'panel', 'full' );

		$out = '<div class="oc-search__prods' . ( $card ? ' is-cards' : ' is-list' ) . '">';

		foreach ( $ids as $id ) {
			$product = wc_get_product( $id );

			if ( ! $product instanceof \WC_Product ) {
				continue;
			}

			$out .= $card ? self::card( $product, $term ) : self::row( $product, $term );
		}

		return $out . '</div>';
	}

	/**
	 * Bold the part of a title the shopper has typed.
	 *
	 * @param string $title Product title.
	 * @param string $term  Query.
	 */
	public static function mark( string $title, string $term ): string {
		$tokens = Search_Index::tokens( $term );

		if ( ! $tokens ) {
			return esc_html( $title );
		}

		$safe  = esc_html( $title );
		$flat  = Search_Index::normalise( $title );
		$words = explode( ' ', $flat );

		// Marking is done on the visible string, matched loosely: a token that
		// starts a word in the title gets that word emboldened.
		foreach ( explode( ' ', $safe ) as $piece ) {
			$piece_flat = Search_Index::normalise( $piece );

			foreach ( $tokens as $token ) {
				if ( '' !== $piece_flat && 0 === mb_strpos( $piece_flat, $token ) ) {
					$safe = str_replace( $piece, '<mark>' . $piece . '</mark>', $safe );
					break;
				}
			}
		}

		unset( $words );

		return $safe;
	}

	/**
	 * The picture for a result.
	 *
	 * @param \WC_Product $product Product.
	 */
	private static function thumb( \WC_Product $product ): string {
		$id = (int) $product->get_image_id();

		$alt = array(
			'alt'     => '',
			'loading' => 'lazy',
			'class'   => 'oc-search__img',
		);

		if ( $id ) {
			return (string) wp_get_attachment_image( $id, 'woocommerce_thumbnail', false, $alt );
		}

		return '<span class="oc-search__img oc-search__img--none" aria-hidden="true"></span>';
	}

	/**
	 * The add button, which behaves differently per kind of product.
	 *
	 * @param \WC_Product $product Product.
	 */
	private static function add_button( \WC_Product $product ): string {
		if ( ! Search::look( 'quickadd', true ) ) {
			return '';
		}

		if ( ! $product->is_in_stock() ) {
			return sprintf(
				'<a class="oc-search__add is-oos" href="%s">%s</a>',
				esc_url( (string) $product->get_permalink() ),
				esc_html__( 'Notify me', 'oc-theme' )
			);
		}

		if ( $product->is_type( 'simple' ) && $product->is_purchasable() ) {
			return sprintf(
				'<button type="button" class="oc-search__add" data-oc-search-add="%d" aria-label="%s">%s</button>',
				(int) $product->get_id(),
				esc_attr(
					sprintf(
						/* translators: %s: product name. */
						__( 'Add %s to the cart', 'oc-theme' ),
						$product->get_name()
					)
				),
				esc_html__( 'Add', 'oc-theme' )
			);
		}

		return sprintf(
			'<a class="oc-search__add is-opts" href="%s">%s</a>',
			esc_url( (string) $product->get_permalink() ),
			esc_html__( 'Choose', 'oc-theme' )
		);
	}

	/**
	 * A result as a row: picture beside the words.
	 *
	 * @param \WC_Product $product Product.
	 * @param string      $term    Query.
	 */
	private static function row( \WC_Product $product, string $term ): string {
		return sprintf(
			'<div class="oc-search__prod"><a class="oc-search__prod-l" href="%s" data-oc-search-hit>%s<span class="oc-search__prod-b"><span class="oc-search__prod-t">%s</span><span class="oc-search__prod-p">%s</span></span></a>%s</div>',
			esc_url( (string) $product->get_permalink() ),
			self::thumb( $product ),
			self::mark( $product->get_name(), $term ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
			wp_kses_post( (string) $product->get_price_html() ),
			self::add_button( $product )
		);
	}

	/**
	 * A result as a card: picture above the words, like the catalogue.
	 *
	 * @param \WC_Product $product Product.
	 * @param string      $term    Query.
	 */
	private static function card( \WC_Product $product, string $term ): string {
		return sprintf(
			'<div class="oc-search__prod oc-search__prod--card"><a class="oc-search__prod-l" href="%s" data-oc-search-hit>%s<span class="oc-search__prod-b"><span class="oc-search__prod-t">%s</span><span class="oc-search__prod-p">%s</span></span></a>%s</div>',
			esc_url( (string) $product->get_permalink() ),
			self::thumb( $product ),
			self::mark( $product->get_name(), $term ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
			wp_kses_post( (string) $product->get_price_html() ),
			self::add_button( $product )
		);
	}
}
