<?php
/**
 * A guide. The steps take the width that reads well; the aside carries the
 * stages, so a long guide can be entered in the middle.
 *
 * @package OC_Guide
 */

defined( 'ABSPATH' ) || exit;

get_header();

while ( have_posts() ) :
	the_post();

	$oc_section = OC\Guide\section_of( (int) get_the_ID() );
	$oc_built   = OC\Guide\with_toc( (string) apply_filters( 'the_content', get_the_content() ) );
	$oc_related = OC\Guide\related( (int) get_the_ID() );
	?>

	<div class="g-wrap g-guide">
		<article class="g-body">
			<p class="g-crumbs">
				<a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'מדריך', 'oc-guide' ); ?></a>
				<?php if ( null !== $oc_section ) : ?>
					<span>›</span>
					<a href="<?php echo esc_url( (string) get_category_link( (int) ( get_category_by_slug( $oc_section['slug'] )->term_id ?? 0 ) ) ); ?>"><?php echo esc_html( $oc_section['name'] ); ?></a>
				<?php endif; ?>
			</p>

			<h1><?php the_title(); ?></h1>

			<?php if ( has_excerpt() ) : ?>
				<p class="g-lede"><?php echo esc_html( get_the_excerpt() ); ?></p>
			<?php endif; ?>

			<?php echo $oc_built['html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- post content, already filtered. ?>
		</article>

		<aside class="g-aside">
			<?php if ( count( $oc_built['toc'] ) > 1 ) : ?>
				<div class="g-aside__box g-toc">
					<h4><?php esc_html_e( 'בעמוד הזה', 'oc-guide' ); ?></h4>
					<ol>
						<?php foreach ( $oc_built['toc'] as $oc_h ) : ?>
							<li><a href="#<?php echo esc_attr( $oc_h['id'] ); ?>"><?php echo esc_html( $oc_h['text'] ); ?></a></li>
						<?php endforeach; ?>
					</ol>
				</div>
			<?php endif; ?>

			<?php if ( $oc_related ) : ?>
				<div class="g-aside__box">
					<h4><?php esc_html_e( 'מדריכים קשורים', 'oc-guide' ); ?></h4>
					<ul>
						<?php foreach ( $oc_related as $oc_p ) : ?>
							<li><a href="<?php echo esc_url( (string) get_permalink( $oc_p ) ); ?>"><?php echo esc_html( get_the_title( $oc_p ) ); ?></a></li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>
		</aside>
	</div>

	<?php
endwhile;

get_footer();
