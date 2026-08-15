<?php
/**
 * Search form.
 *
 * @package OC_Theme
 */

defined( 'ABSPATH' ) || exit;
?>
<form role="search" method="get" class="oc-search" action="<?php echo esc_url( home_url( '/' ) ); ?>">
	<label class="screen-reader-text" for="oc-s"><?php esc_html_e( 'Search', 'oc-theme' ); ?></label>
	<input id="oc-s" type="search" name="s" value="<?php echo get_search_query(); ?>"
		placeholder="<?php esc_attr_e( 'Search…', 'oc-theme' ); ?>" />
	<button type="submit"><?php esc_html_e( 'Search', 'oc-theme' ); ?></button>
</form>
