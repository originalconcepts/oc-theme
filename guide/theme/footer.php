<?php
/**
 * @package OC_Guide
 */

defined( 'ABSPATH' ) || exit;
?>
</main>

<footer class="g-foot">
	<div class="g-wrap g-foot__in">
		<div>
			<strong><?php echo esc_html( get_bloginfo( 'name' ) ); ?></strong> —
			<?php esc_html_e( 'המדריך לניהול החנות.', 'oc-guide' ); ?>
		</div>
		<div>
			<?php
			$oc_first = true;

			foreach ( OC\Guide\SECTIONS as $oc_slug => $oc_s ) {
				$oc_cat = get_category_by_slug( $oc_slug );

				if ( ! $oc_cat instanceof WP_Term ) {
					continue;
				}

				echo $oc_first ? '' : ' · ';
				printf( '<a href="%s">%s</a>', esc_url( (string) get_category_link( $oc_cat->term_id ) ), esc_html( $oc_s['name'] ) );
				$oc_first = false;
			}
			?>
		</div>
	</div>
</footer>

<?php wp_footer(); ?>
</body>
</html>
