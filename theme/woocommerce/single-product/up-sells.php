<?php
/**
 * Up-sells — "you may also like".
 *
 * Overridden only so the area can carry a background of its own, the way the
 * other linked areas do. The layout is WooCommerce's grid, untouched.
 *
 * @package OC_Theme
 */

defined( 'ABSPATH' ) || exit;

if ( empty( $upsells ) ) {
	return;
}

$oc_band = \OC\Theme\Product_Linked::band( 'oc_upsells_bg' );

$oc_classes = array( 'up-sells', 'upsells', 'products', 'oc-linked', 'oc-linked--grid' );

if ( '' !== $oc_band['class'] ) {
	$oc_classes[] = 'oc-linked--band';
}

$oc_heading = apply_filters( 'woocommerce_product_upsells_products_heading', __( 'You may also like&hellip;', 'woocommerce' ) ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- WooCommerce's own string; its translations apply.
?>
<section class="<?php echo esc_attr( implode( ' ', $oc_classes ) ); ?>"<?php echo $oc_band['style']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in band(). ?>>
	<?php if ( $oc_heading ) : ?>
		<h2 class="oc-linked__title"><?php echo esc_html( $oc_heading ); ?></h2>
	<?php endif; ?>

	<?php
	woocommerce_product_loop_start();

	foreach ( $upsells as $upsell ) {
		$post_object = get_post( $upsell->get_id() );

		setup_postdata( $GLOBALS['post'] = $post_object ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited, Squiz.PHP.DisallowMultipleAssignments.Found

		wc_get_template_part( 'content', 'product' );
	}

	woocommerce_product_loop_end();
	?>
</section>
<?php
wp_reset_postdata();
