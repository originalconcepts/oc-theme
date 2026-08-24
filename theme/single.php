<?php
/**
 * One post, read quietly: date, title, picture, words — then the doors out
 * (share, category, tags) and the conversation under it.
 *
 * @package OC_Theme
 */

use OC\Theme\Blog;

get_header();
?>
<main id="main" class="site-main">
	<div class="oc-container oc-container--narrow oc-bsingle">
		<?php
		while ( have_posts() ) :
			the_post();
			?>
			<article <?php post_class(); ?>>
				<time class="oc-bsingle__date" datetime="<?php echo esc_attr( (string) get_the_date( 'c' ) ); ?>"><?php echo esc_html( (string) get_the_date() ); ?></time>
				<h1 class="oc-page-title"><?php the_title(); ?></h1>
				<?php the_post_thumbnail( 'large', array( 'class' => 'oc-bsingle__hero' ) ); ?>
				<div class="oc-content"><?php the_content(); ?></div>

				<footer class="oc-bsingle__foot">
					<?php
					echo Blog::share( get_post() );   // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
					echo Blog::belongs( get_post() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
					?>
				</footer>
			</article>
			<?php
			if ( comments_open() || get_comments_number() ) {
				comments_template();
			}
		endwhile;
		?>
	</div>
</main>
<?php
get_footer();
