<?php
/**
 * OC Blocks — categories only (Deliz).
 *
 * Deliz's standard page keeps its category menu on one side and its order
 * panel on the other, which leaves the page a narrow middle. This layout
 * keeps the categories and gives the panel's room to the page: the order
 * panel stays in the document (Deliz's own drawer and basket bar need it
 * on a phone) and on a wide screen opens as a drawer from a button.
 *
 * The two layers and the virtual category address (/cat/{slug}/ →
 * mp_cat) are handled exactly as Deliz's page.php does.
 *
 * @package OC_Blocks
 */

defined( 'ABSPATH' ) || exit;

$ocb_shop_view = '' !== (string) get_query_var( 'mp_cat', '' );
$ocb_count     = function_exists( 'WC' ) && WC()->cart ? (int) WC()->cart->get_cart_contents_count() : 0;

get_header();
?>
<main id="site-content" class="site-content static-page ocb-deliz-cats">
	<?php while ( have_posts() ) : ?>
		<?php the_post(); ?>
		<article id="post-<?php the_ID(); ?>" <?php post_class(); ?>>
			<div class="entry-content">
				<div class="main-content">
					<div class="right-column">
						<div class="right-column-inner">
							<div class="menu-block">
								<?php echo do_shortcode( '[ed_menu_sidebar]' ); ?>
							</div>
						</div>
					</div>
					<div class="center-column">
						<div class="center-column-inner">
							<div class="ed-page-products-wrap" data-ed-page-products-wrap="1"<?php echo $ocb_shop_view ? '' : ' hidden'; ?>>
								<?php echo do_shortcode( '[ed_products_box]' ); ?>
							</div>
							<div class="ed-page-static" data-ed-page-static="1"<?php echo $ocb_shop_view ? ' hidden' : ''; ?>>
								<?php the_title( '<h1 class="entry-title">', '</h1>' ); ?>
								<div class="entry-content">
									<?php the_content(); ?>
								</div>
							</div>
						</div>
					</div>
					<div class="left-column">
						<?php echo do_shortcode( '[ed_floating_cart]' ); ?>
					</div>
					<aside class="ocb-dz-cartcol">
						<button type="button" class="ocb-dz-cartbtn" data-ocb-dz-cart aria-expanded="false" aria-controls="ed-float-cart">
							<span><?php esc_html_e( 'My cart', 'oc-blocks' ); ?></span>
							<span class="ocb-dz-cartbtn__n"<?php echo $ocb_count > 0 ? '' : ' hidden'; ?>><?php echo esc_html( (string) $ocb_count ); ?></span>
						</button>
					</aside>
					<div class="ocb-dz-backdrop" data-ocb-dz-cart></div>
				</div>
			</div>
		</article>
	<?php endwhile; ?>
	<?php echo do_shortcode( '[ed_basket_bar]' ); ?>
</main>
<?php
get_footer();
