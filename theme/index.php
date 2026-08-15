<?php
/**
 * Fallback template.
 *
 * @package OC_Theme
 */

get_header();
?>
<main id="main" class="site-main">
	<div class="oc-container">
		<?php do_action( 'oc_before_page_title' ); ?>

		<?php if ( have_posts() ) : ?>
			<div class="oc-posts">
				<?php
				while ( have_posts() ) :
					the_post();
					?>
					<article <?php post_class( 'oc-post-card' ); ?>>
						<a href="<?php the_permalink(); ?>">
							<?php the_post_thumbnail( 'medium_large' ); ?>
							<h2><?php the_title(); ?></h2>
						</a>
						<?php the_excerpt(); ?>
					</article>
					<?php
				endwhile;
				?>
			</div>
			<?php the_posts_pagination(); ?>
		<?php else : ?>
			<p><?php esc_html_e( 'Nothing found.', 'oc-theme' ); ?></p>
		<?php endif; ?>
	</div>
</main>
<?php
get_footer();
