<?php
/**
 * One section: every guide in it, in the order they should be read.
 *
 * @package OC_Guide
 */

defined( 'ABSPATH' ) || exit;

get_header();

$oc_term  = get_queried_object();
$oc_slug  = $oc_term instanceof WP_Term ? $oc_term->slug : '';
$oc_blurb = OC\Guide\SECTIONS[ $oc_slug ]['blurb'] ?? '';
?>

<div class="g-wrap g-page">
	<div class="g-page__head">
		<p class="g-crumbs"><a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'מדריך', 'oc-guide' ); ?></a><span>›</span><?php echo esc_html( single_cat_title( '', false ) ); ?></p>
		<h1><?php echo esc_html( single_cat_title( '', false ) ); ?></h1>
		<?php if ( '' !== $oc_blurb ) : ?>
			<p><?php echo esc_html( $oc_blurb ); ?></p>
		<?php endif; ?>
	</div>

	<?php if ( have_posts() ) : ?>
		<div class="g-list">
			<?php
			while ( have_posts() ) :
				the_post();
				?>
				<a class="g-item" href="<?php the_permalink(); ?>">
					<h3><?php the_title(); ?></h3>
					<?php if ( has_excerpt() ) : ?>
						<p><?php echo esc_html( get_the_excerpt() ); ?></p>
					<?php endif; ?>
				</a>
			<?php endwhile; ?>
		</div>
	<?php else : ?>
		<p class="g-empty"><?php esc_html_e( 'עוד אין מדריכים בנושא הזה.', 'oc-guide' ); ?></p>
	<?php endif; ?>
</div>

<?php
get_footer();
