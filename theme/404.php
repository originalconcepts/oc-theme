<?php
/**
 * Not found.
 *
 * @package OC_Theme
 */

get_header();
?>
<main id="main" class="site-main">
	<div class="oc-container oc-container--narrow oc-404">
		<h1><?php esc_html_e( 'Page not found', 'oc-theme' ); ?></h1>
		<p><?php esc_html_e( 'The page you asked for is not here. Try a search instead.', 'oc-theme' ); ?></p>
		<?php get_search_form(); ?>
	</div>
</main>
<?php
get_footer();
