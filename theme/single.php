<?php
/**
 * One post, read quietly: a kicker of category and date, a large title, the
 * picture, the words — then the doors out (share, category, tags) and the
 * conversation. A hairline across the top of the site fills as you read.
 *
 * @package OC_Theme
 */

use OC\Theme\Blog;

get_header();
?>
<div class="oc-progress" aria-hidden="true"><i></i></div>
<main id="main" class="site-main">
	<div class="oc-container oc-container--narrow oc-bsingle">
		<?php
		while ( have_posts() ) :
			the_post();
			$oc_cats = get_the_category();
			?>
			<article <?php post_class(); ?>>
				<header class="oc-bsingle__head">
					<p class="oc-bsingle__kick">
						<?php if ( ! empty( $oc_cats ) ) : ?>
							<a href="<?php echo esc_url( (string) get_category_link( $oc_cats[0] ) ); ?>"><?php echo esc_html( $oc_cats[0]->name ); ?></a>
							<span aria-hidden="true">·</span>
						<?php endif; ?>
						<time datetime="<?php echo esc_attr( (string) get_the_date( 'c' ) ); ?>"><?php echo esc_html( (string) get_the_date() ); ?></time>
					</p>
					<h1 class="oc-bsingle__title"><?php the_title(); ?></h1>
				</header>
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
