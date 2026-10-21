<?php
/**
 * OC Blocks — full width.
 *
 * The theme's header and footer, and between them nothing but the page's
 * sections: no title, no sidebar, no content column. A block theme has no
 * header.php, so its header and footer template parts are printed instead.
 *
 * @package OC_Blocks
 */

defined( 'ABSPATH' ) || exit;

if ( wp_is_block_theme() ) {
	?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
	<?php
	wp_body_open();
	echo '<div class="wp-site-blocks">';
	block_template_part( 'header' );
} else {
	get_header();
}

echo '<main id="ocb-main" class="ocb-canvas">';

while ( have_posts() ) {
	the_post();
	the_content();
}

echo '</main>';

if ( wp_is_block_theme() ) {
	block_template_part( 'footer' );
	echo '</div>';
	wp_footer();
	echo '</body></html>';
} else {
	get_footer();
}
