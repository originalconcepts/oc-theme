<?php
/**
 * Related products.
 *
 * Overridden so the row can be a grid or a slider, and so its cards can be
 * centred. WooCommerce's own copy hardcodes a grid; everything else here —
 * the heading filter, the card template, resetting the post — matches it.
 *
 * @package OC_Theme
 */

defined( 'ABSPATH' ) || exit;

if ( empty( $related_products ) ) {
	return;
}

$oc_layout = \OC\Theme\Product_Linked::related_layout();
$oc_align  = \OC\Theme\Product_Linked::related_align();

$oc_classes = array( 'related', 'products', 'oc-linked', 'oc-linked--' . $oc_layout );

if ( 'center' === $oc_align ) {
	$oc_classes[] = 'oc-linked--center';
}

$oc_heading = apply_filters( 'woocommerce_product_related_products_heading', __( 'Related products', 'woocommerce' ) );

// The slider sizes its cards from the same column count the grid uses, so
// switching between the two does not change how wide a card is.
$oc_cols = max( 2, min( 6, (int) get_theme_mod( 'oc_related_cols', 4 ) ) );
?>
<section class="<?php echo esc_attr( implode( ' ', $oc_classes ) ); ?>" style="--oc-linked-cols:<?php echo (int) $oc_cols; ?>">
	<?php if ( $oc_heading ) : ?>
		<h2 class="oc-linked__title"><?php echo esc_html( $oc_heading ); ?></h2>
	<?php endif; ?>

	<?php
	// The loop's opening tag, with the drag-to-scroll hook added when the row
	// is a slider. woocommerce_product_loop_start( false ) returns it rather
	// than printing it, which is the only way to reach that attribute.
	$oc_open = woocommerce_product_loop_start( false );

	if ( 'slider' === $oc_layout ) {
		$oc_open = str_replace( '<ul class="products', '<ul data-oc-slider class="products', $oc_open );
	}

	echo $oc_open; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WooCommerce's own markup.

	foreach ( $related_products as $related_product ) {
		$post_object = get_post( $related_product->get_id() );

		setup_postdata( $GLOBALS['post'] = $post_object ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited, Squiz.PHP.DisallowMultipleAssignments.Found

		wc_get_template_part( 'content', 'product' );
	}

	woocommerce_product_loop_end();
	?>
</section>
<?php
wp_reset_postdata();
