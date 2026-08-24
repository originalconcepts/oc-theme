<?php
/**
 * The blog index: cards in a grid, categories as chips above.
 *
 * @package OC_Theme
 */

use OC\Theme\Blog;

get_header();
?>
<main id="main" class="site-main">
	<div class="oc-container oc-blog" style="<?php echo esc_attr( Blog::grid_style() ); ?>">
		<h1 class="oc-page-title"><?php echo esc_html( get_the_title( (int) get_option( 'page_for_posts' ) ) ); ?></h1>
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
