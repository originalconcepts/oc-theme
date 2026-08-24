<?php
/**
 * The conversation under a post.
 *
 * @package OC_Theme
 */

if ( post_password_required() ) {
	return;
}
?>
<section id="comments" class="oc-cmt">
	<?php if ( have_comments() ) : ?>
		<h2 class="oc-cmt__title">
			<?php
			$oc_count = (int) get_comments_number();
			/* translators: %s: number of comments. */
			echo esc_html( sprintf( _n( '%s comment', '%s comments', $oc_count, 'oc-theme' ), number_format_i18n( $oc_count ) ) );
			?>
		</h2>

		<ol class="oc-cmt__list">
			<?php
			wp_list_comments(
				array(
					'style'       => 'ol',
					'short_ping'  => true,
					'avatar_size' => 44,
					'reply_text'  => __( 'Reply', 'oc-theme' ),
				)
			);
			?>
		</ol>

		<?php the_comments_navigation(); ?>
	<?php endif; ?>

	<?php if ( ! comments_open() && get_comments_number() ) : ?>
		<p class="oc-cmt__closed"><?php esc_html_e( 'Comments are closed.', 'oc-theme' ); ?></p>
	<?php endif; ?>

	<?php comment_form(); ?>
</section>
