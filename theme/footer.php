<?php
/**
 * Site footer.
 *
 * @package OC_Theme
 */

defined( 'ABSPATH' ) || exit;
?>
<footer class="oc-footer">
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
			printf(
				/* translators: %1$s: year, %2$s: site name. */
				esc_html__( '© %1$s %2$s', 'oc-theme' ),
				esc_html( gmdate( 'Y' ) ),
				esc_html( get_bloginfo( 'name' ) )
			);
			?>
		</p>

	</div>
</footer>

<?php wp_footer(); ?>
</body>
</html>
