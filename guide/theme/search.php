<?php
/**
 * Search results. Each result says which section it came from, because
 * "how do I change a price" is a different job in products than in promotions.
 *
 * @package OC_Guide
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>

<div class="g-wrap g-page">
	<div class="g-page__head">
		<h1>
			<?php
			printf(
				/* translators: %s: the search term. */
				esc_html__( 'תוצאות עבור “%s”', 'oc-guide' ),
				esc_html( get_search_query() )
			);
			?>
		</h1>
		<p>
			<?php
			global $wp_query;
			$oc_found = (int) $wp_query->found_posts;

			printf(
				/* translators: %d: how many guides matched. */
				esc_html( _n( 'מדריך אחד', '%d מדריכים', $oc_found, 'oc-guide' ) ),
				(int) $oc_found
			);
			?>
		</p>
	</div>

	<?php if ( have_posts() ) : ?>
		<div class="g-list">
			<?php
			while ( have_posts() ) :
				the_post();

				$oc_section = OC\Guide\section_of( (int) get_the_ID() );
				?>
				<a class="g-item" href="<?php the_permalink(); ?>">
					<?php if ( null !== $oc_section ) : ?>
						<span class="g-item__tag"><?php echo esc_html( $oc_section['name'] ); ?></span>
					<?php endif; ?>
					<h3><?php the_title(); ?></h3>
					<p><?php echo esc_html( has_excerpt() ? get_the_excerpt() : wp_trim_words( wp_strip_all_tags( get_the_content() ), 24 ) ); ?></p>
				</a>
			<?php endwhile; ?>
		</div>
	<?php else : ?>
		<p class="g-empty">
			<?php esc_html_e( 'לא נמצא מדריך שמתאים לחיפוש. נסו מילה אחת במקום משפט — למשל “מחיר”, “קופון” או “באנר”.', 'oc-guide' ); ?>
		</p>
	<?php endif; ?>
</div>

<?php
get_footer();
