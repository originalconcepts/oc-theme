<?php
/**
 * Post archives — a category, a tag, a month — wearing the blog's own grid.
 * Shop archives never reach this file; WooCommerce keeps its own templates.
 *
 * @package OC_Theme
 */

use OC\Theme\Blog;

get_header();
?>
<main id="main" class="site-main">
	<div class="oc-container oc-blog" style="<?php echo esc_attr( Blog::grid_style() ); ?>">
		<?php
		// A category's shared card image, as a banner over the archive.
		if ( is_category() ) {
			$oc_cat = get_queried_object();

			if ( $oc_cat instanceof WP_Term ) {
				$oc_cat_img = class_exists( '\OC\Theme\Category' )
					? OC\Theme\Category::card_image_id( $oc_cat->term_id )
					: absint( get_term_meta( $oc_cat->term_id, '_oc_card_img', true ) );

				if ( $oc_cat_img > 0 ) {
					echo '<figure class="oc-blog__cat-img">' . wp_get_attachment_image( $oc_cat_img, 'large', false, array( 'alt' => esc_attr( single_term_title( '', false ) ) ) ) . '</figure>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_get_attachment_image() is safe.
				}
			}
		}
		?>
		<h1 class="oc-page-title"><?php echo esc_html( single_term_title( '', false ) ? single_term_title( '', false ) : get_the_archive_title() ); ?></h1>
		<?php echo Blog::filter_bar(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts. ?>

		<?php if ( have_posts() ) : ?>
			<div class="oc-blog__grid">
				<?php
				while ( have_posts() ) :
					the_post();
					echo Blog::card( get_post() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
				endwhile;
				?>
			</div>
			<?php the_posts_pagination( array( 'mid_size' => 1 ) ); ?>
		<?php else : ?>
			<p><?php esc_html_e( 'Nothing found.', 'oc-theme' ); ?></p>
		<?php endif; ?>
	</div>
</main>
<?php
get_footer();
