<?php
/**
 * Site footer.
 *
 * @package OC_Theme
 */

defined( 'ABSPATH' ) || exit;
?>
<?php
$oc_footer_layout = sanitize_html_class( (string) get_theme_mod( 'oc_footer_layout', 'inline' ) );
$oc_footer_credit = trim( (string) get_theme_mod( 'oc_footer_credit', '' ) );

$oc_has_widgets = false;
for ( $oc_i = 1; $oc_i <= 3; $oc_i++ ) {
	if ( is_active_sidebar( 'oc-footer-' . $oc_i ) ) {
		$oc_has_widgets = true;
		break;
	}
}
?>
<footer class="oc-footer oc-footer--<?php echo esc_attr( $oc_footer_layout ); ?>">

	<?php if ( $oc_has_widgets ) : ?>
		<div class="oc-footer__widgets">
			<?php for ( $oc_i = 1; $oc_i <= 3; $oc_i++ ) : ?>
				<?php if ( is_active_sidebar( 'oc-footer-' . $oc_i ) ) : ?>
					<div class="oc-footer__col">
						<?php dynamic_sidebar( 'oc-footer-' . $oc_i ); ?>
					</div>
				<?php endif; ?>
			<?php endfor; ?>
		</div>
	<?php endif; ?>

	<div class="oc-footer__inner">

		<?php if ( has_nav_menu( 'footer' ) ) : ?>
			<nav class="oc-footer__nav" aria-label="<?php esc_attr_e( 'Footer', 'oc-theme' ); ?>">
				<?php
				wp_nav_menu(
					array(
						'theme_location' => 'footer',
						'container'      => '',
						'menu_class'     => 'oc-footer__list',
						'depth'          => 1,
					)
				);
				?>
			</nav>
		<?php endif; ?>

		<p class="oc-footer__credit">
			<?php
			if ( '' !== $oc_footer_credit ) {
				echo esc_html( $oc_footer_credit );
			} else {
				printf(
					/* translators: %1$s: year, %2$s: site name. */
					esc_html__( '© %1$s %2$s', 'oc-theme' ),
					esc_html( gmdate( 'Y' ) ),
					esc_html( get_bloginfo( 'name' ) )
				);
			}
			?>
		</p>

	</div>
</footer>

<?php wp_footer(); ?>
</body>
</html>
