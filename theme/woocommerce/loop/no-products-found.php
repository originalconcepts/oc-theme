<?php
/**
 * Empty-handed, without the blue box: a plain sentence, an open search
 * field, and the searches other people are making — three roads onward
 * instead of a dead end.
 *
 * @package OC_Theme
 */

defined( 'ABSPATH' ) || exit;

use OC\Theme\Search;

$oc_term = is_search() && class_exists( 'OC\\Theme\\Search' ) ? Search::current_term() : '';
$oc_pop  = class_exists( 'OC\\Theme\\Search' ) ? Search::popular_terms( 14, 6 ) : array();
?>
<div class="oc-nores">
	<p class="oc-nores__word">
		<?php
		if ( '' !== $oc_term ) {
			/* translators: %s: what the shopper searched for. */
			printf( esc_html__( 'No results for "%s" — try a different search:', 'oc-theme' ), esc_html( $oc_term ) );
		} else {
			esc_html_e( 'No products were found matching your selection.', 'woocommerce' ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- Woo's own sentence; reuse its translation.
		}
		?>
	</p>

	<form role="search" method="get" class="oc-search" action="<?php echo esc_url( home_url( '/' ) ); ?>">
		<div class="oc-search__bar">
			<button type="submit" class="oc-search__go" aria-label="<?php esc_attr_e( 'Search', 'oc-theme' ); ?>">
				<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.8-3.8"/></svg>
			</button>
			<label class="screen-reader-text" for="oc-nores-s"><?php esc_html_e( 'Search', 'oc-theme' ); ?></label>
			<input id="oc-nores-s" type="search" name="s" value="" class="oc-search__field" placeholder="<?php esc_attr_e( 'Search…', 'oc-theme' ); ?>" autocomplete="off" />
			<input type="hidden" name="post_type" value="product" />
		</div>
	</form>

	<?php if ( ! empty( $oc_pop ) ) : ?>
		<div class="oc-nores__pop">
			<span><?php esc_html_e( 'Popular searches:', 'oc-theme' ); ?></span>
			<?php foreach ( $oc_pop as $oc_row ) : ?>
				<a href="
				<?php
				echo esc_url(
					add_query_arg(
						array(
							's'         => rawurlencode( (string) $oc_row->term ),
							'post_type' => 'product',
						),
						home_url( '/' )
					)
				);
				?>
							"><?php echo esc_html( (string) $oc_row->term ); ?></a>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>
</div>
