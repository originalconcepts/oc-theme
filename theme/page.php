<?php
/**
 * Single page.
 *
 * @package OC_Theme
 */

get_header();
?>
<main id="main" class="site-main">
	<div class="oc-container">
		<?php do_action( 'oc_before_page_title' ); ?>
		<?php
		while ( have_posts() ) :
			the_post();
			?>
			<article <?php post_class(); ?>>
				<?php if ( ! is_front_page() ) : ?>
					<h1 class="oc-page-title"><?php the_title(); ?></h1>
				<?php endif; ?>
				<div class="oc-content"><?php the_content(); ?></div>
			</article>
			<?php
		endwhile;
		?>
	</div>
</main>
<?php
get_footer();
