<?php
/**
 * Site footer.
 *
 * Two presets: "columns" (brand · link columns · newsletter · social · bottom
 * bar) and "minimal" (the old menu + credit line). Link columns are menus
 * assigned to the Footer column 1–4 locations; headings, newsletter and social
 * come from the Customizer (Appearance → Customize → Footer).
 *
 * @package OC_Theme
 */

defined( 'ABSPATH' ) || exit;

$oc_preset = sanitize_html_class( (string) get_theme_mod( 'oc_footer_preset', 'columns' ) );
$oc_layout = sanitize_html_class( (string) get_theme_mod( 'oc_footer_layout', 'inline' ) );
$oc_dark   = (bool) get_theme_mod( 'oc_footer_dark', false );
$oc_mobile = sanitize_html_class( (string) get_theme_mod( 'oc_footer_mobile', 'accordion' ) );
$oc_credit = trim( (string) get_theme_mod( 'oc_footer_credit', '' ) );

/**
 * Bottom bar: legal menu + credit + optional country + our credit.
 */
$oc_bottom = static function () use ( $oc_credit ) {
	?>
	<div class="oc-footer__bottom">
		<div class="oc-footer__bottom-start">
			<p class="oc-footer__credit">
				<?php
				if ( '' !== $oc_credit ) {
					echo esc_html( $oc_credit );
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

			<?php if ( has_nav_menu( 'footer' ) ) : ?>
				<nav class="oc-footer__legal" aria-label="<?php esc_attr_e( 'Footer', 'oc-theme' ); ?>">
					<?php
					wp_nav_menu(
						array(
							'theme_location' => 'footer',
							'container'      => '',
							'menu_class'     => 'oc-footer__legal-list',
							'depth'          => 1,
						)
					);
					?>
				</nav>
			<?php endif; ?>
		</div>

		<div class="oc-footer__bottom-end">
			<?php if ( class_exists( 'WooCommerce' ) && get_theme_mod( 'oc_footer_country', false ) ) : ?>
				<span class="oc-footer__country">
					<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a15 15 0 0 1 0 18A15 15 0 0 1 12 3z"/></svg>
					<?php
					$oc_country = WC()->countries ? WC()->countries->get_base_country() : '';
					$oc_cname   = $oc_country && WC()->countries ? WC()->countries->countries[ $oc_country ] ?? $oc_country : $oc_country;
					echo esc_html( trim( (string) $oc_cname . ' (' . get_woocommerce_currency_symbol() . ')' ) );
					?>
				</span>
			<?php endif; ?>

			<?php
			$oc_oc_url = trim( (string) get_theme_mod( 'oc_footer_oc_url', 'https://onlinestore.co.il' ) );
			if ( '' === $oc_oc_url ) {
				$oc_oc_url = 'https://onlinestore.co.il';
			}
			?>
			<a class="oc-footer__oc" href="<?php echo esc_url( $oc_oc_url ); ?>" target="_blank" rel="noopener">
				<span><?php esc_html_e( 'E-commerce site by', 'oc-theme' ); ?></span>
				<img src="<?php echo esc_url( OC_THEME_URI . '/assets/img/oc-credit.svg' ); ?>" alt="Original Concepts" width="88" height="46" loading="lazy" />
			</a>
		</div>
	</div>
	<?php
};
?>
<footer class="oc-footer oc-footer--<?php echo esc_attr( $oc_preset ); ?> oc-footer--bar-<?php echo esc_attr( $oc_layout ); ?> oc-footer--m-<?php echo esc_attr( $oc_mobile ); ?><?php echo $oc_dark ? ' oc-footer--dark' : ''; ?>">

<?php if ( 'minimal' === $oc_preset ) : ?>

	<div class="oc-footer__inner">
		<?php $oc_bottom(); ?>
	</div>

<?php else : ?>

	<?php
	// ---- Columns preset --------------------------------------------------

	// Which link columns actually have a menu assigned?
	$oc_cols = array();
	for ( $oc_i = 1; $oc_i <= 4; $oc_i++ ) {
		if ( has_nav_menu( 'footer-col-' . $oc_i ) ) {
			$oc_cols[ $oc_i ] = trim( (string) get_theme_mod( 'oc_footer_col' . $oc_i . '_h', '' ) );
		}
	}

	$oc_show_logo = (bool) get_theme_mod( 'oc_footer_logo', true );
	$oc_tagline   = trim( (string) get_theme_mod( 'oc_footer_tagline', '' ) );
	$oc_has_brand = $oc_show_logo || '' !== $oc_tagline;

	$oc_news_on = (bool) get_theme_mod( 'oc_footer_news', true );

	// Social platforms with inline icons.
	$oc_social_icons = array(
		'facebook'  => '<svg viewBox="0 0 24 24" width="19" height="19" fill="currentColor" aria-hidden="true"><path d="M22 12a10 10 0 1 0-11.6 9.9v-7H7.9V12h2.5V9.8c0-2.5 1.5-3.9 3.8-3.9 1.1 0 2.2.2 2.2.2v2.5h-1.3c-1.2 0-1.6.8-1.6 1.6V12h2.8l-.4 2.9h-2.4v7A10 10 0 0 0 22 12z"/></svg>',
		'instagram' => '<svg viewBox="0 0 24 24" width="19" height="19" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="5"/><circle cx="12" cy="12" r="4"/><circle cx="17.3" cy="6.7" r="1" fill="currentColor" stroke="none"/></svg>',
		'x'         => '<svg viewBox="0 0 24 24" width="17" height="17" fill="currentColor" aria-hidden="true"><path d="M18.9 2H22l-7.2 8.3L23 22h-6.6l-5.2-6.8L5.3 22H2.2l7.7-8.8L1.5 2h6.8l4.7 6.2L18.9 2zm-1.2 18h1.8L7.4 3.8H5.5L17.7 20z"/></svg>',
		'tiktok'    => '<svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor" aria-hidden="true"><path d="M16.5 3c.3 2.1 1.5 3.6 3.5 3.9v2.4c-1.3.1-2.5-.3-3.6-1v5.7c0 3.4-2.6 5.9-6 5.6-3.1-.3-5.2-3.3-4.5-6.4.5-2.3 2.5-3.9 4.9-3.8v2.5c-.4-.1-.9-.1-1.3 0-1.2.3-2 1.5-1.6 2.8.3 1.2 1.6 1.9 2.8 1.5 1-.3 1.5-1.1 1.5-2.1V3h3.9z"/></svg>',
		'youtube'   => '<svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor" aria-hidden="true"><path d="M23 12s0-3.2-.4-4.7a2.5 2.5 0 0 0-1.7-1.8C19.3 5 12 5 12 5s-7.3 0-8.9.5A2.5 2.5 0 0 0 1.4 7.3C1 8.8 1 12 1 12s0 3.2.4 4.7a2.5 2.5 0 0 0 1.7 1.8C4.7 19 12 19 12 19s7.3 0 8.9-.5a2.5 2.5 0 0 0 1.7-1.8C23 15.2 23 12 23 12zM9.8 15.1V8.9l5.3 3.1-5.3 3.1z"/></svg>',
		'pinterest' => '<svg viewBox="0 0 24 24" width="19" height="19" fill="currentColor" aria-hidden="true"><path d="M12 2a10 10 0 0 0-3.6 19.3c-.1-.8-.2-2 0-2.9l1.2-5s-.3-.6-.3-1.5c0-1.4.8-2.4 1.8-2.4.9 0 1.3.6 1.3 1.4 0 .9-.5 2.1-.8 3.3-.2.9.5 1.7 1.4 1.7 1.7 0 2.9-2.2 2.9-4.7 0-1.9-1.3-3.4-3.7-3.4a4.2 4.2 0 0 0-4.4 4.2c0 .8.3 1.4.6 1.8.2.2.2.3.1.5l-.2.9c-.1.3-.3.4-.6.2-1.1-.5-1.7-1.9-1.7-3.1C6 8.3 8 6 12.2 6c3.4 0 5.7 2.4 5.7 5.1 0 3.5-1.9 6-4.8 6-1 0-1.9-.5-2.2-1.1l-.6 2.3c-.2.8-.7 1.7-1 2.3A10 10 0 1 0 12 2z"/></svg>',
		'linkedin'  => '<svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor" aria-hidden="true"><path d="M4.98 3.5A2.5 2.5 0 1 1 5 8.5a2.5 2.5 0 0 1 0-5zM3 9h4v12H3zM9 9h3.8v1.7h.05c.53-1 1.8-2 3.7-2 4 0 4.7 2.6 4.7 6V21h-4v-5.3c0-1.3 0-3-1.8-3s-2.1 1.4-2.1 2.9V21H9z"/></svg>',
		'whatsapp'  => '<svg viewBox="0 0 24 24" width="19" height="19" fill="currentColor" aria-hidden="true"><path d="M12 2a10 10 0 0 0-8.5 15.2L2 22l4.9-1.3A10 10 0 1 0 12 2zm0 18a8 8 0 0 1-4.1-1.1l-.3-.2-2.9.8.8-2.8-.2-.3A8 8 0 1 1 12 20zm4.4-6c-.2-.1-1.4-.7-1.6-.8-.2-.1-.4-.1-.5.1l-.7.9c-.1.2-.3.2-.5.1a6.5 6.5 0 0 1-3.2-2.8c-.2-.4.2-.4.6-1.2.1-.1 0-.3 0-.4l-.8-1.8c-.2-.5-.4-.4-.5-.4h-.5c-.2 0-.4.1-.6.3-.8.8-.9 2 .1 3.4a9 9 0 0 0 3.5 3.1c1.3.6 1.8.6 2.5.5.4-.1 1.4-.6 1.6-1.1.2-.6.2-1 .1-1.1z"/></svg>',
	);
	$oc_socials = array();
	foreach ( $oc_social_icons as $oc_key => $oc_svg ) {
		$oc_url = trim( (string) get_theme_mod( 'oc_social_' . $oc_key, '' ) );
		if ( '' !== $oc_url ) {
			$oc_socials[ $oc_key ] = array( $oc_url, $oc_svg );
		}
	}
	?>

	<div class="oc-footer__top">
		<div class="oc-footer__grid">

			<?php if ( $oc_has_brand ) : ?>
				<div class="oc-footer__brand">
					<?php if ( $oc_show_logo ) : ?>
						<?php if ( has_custom_logo() ) : ?>
							<?php the_custom_logo(); ?>
						<?php else : ?>
							<a class="oc-footer__brand-name" href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php echo esc_html( get_bloginfo( 'name' ) ); ?></a>
						<?php endif; ?>
					<?php endif; ?>
					<?php if ( '' !== $oc_tagline ) : ?>
						<p class="oc-footer__tagline"><?php echo esc_html( $oc_tagline ); ?></p>
					<?php endif; ?>
					<?php if ( $oc_socials ) : ?>
						<div class="oc-footer__social">
							<?php foreach ( $oc_socials as $oc_key => $oc_s ) : ?>
								<a class="oc-footer__social-link" href="<?php echo esc_url( $oc_s[0] ); ?>" target="_blank" rel="noopener" aria-label="<?php echo esc_attr( ucfirst( $oc_key ) ); ?>"><?php echo $oc_s[1]; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static inline SVG. ?></a>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<?php foreach ( $oc_cols as $oc_i => $oc_heading ) : ?>
				<div class="oc-footer__col">
					<?php if ( '' !== $oc_heading ) : ?>
						<h2 class="oc-footer__col-h"><?php echo esc_html( $oc_heading ); ?></h2>
					<?php endif; ?>
					<?php
					wp_nav_menu(
						array(
							'theme_location' => 'footer-col-' . $oc_i,
							'container'      => '',
							'menu_class'     => 'oc-footer__col-list',
							'depth'          => 1,
						)
					);
					?>
				</div>
			<?php endforeach; ?>

			<?php if ( $oc_news_on ) : ?>
				<div class="oc-footer__news">
					<?php $oc_nh = trim( (string) get_theme_mod( 'oc_footer_news_h', __( 'Newsletter', 'oc-theme' ) ) ); ?>
					<?php $oc_nt = trim( (string) get_theme_mod( 'oc_footer_news_t', '' ) ); ?>
					<h2 class="oc-footer__col-h"><?php echo esc_html( '' !== $oc_nh ? $oc_nh : __( 'Newsletter', 'oc-theme' ) ); ?></h2>
					<?php if ( '' !== $oc_nt ) : ?>
						<p class="oc-footer__news-text"><?php echo esc_html( $oc_nt ); ?></p>
					<?php endif; ?>
					<form class="oc-footer__news-form" method="post" action="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" data-oc-subscribe>
						<input type="hidden" name="action" value="oc_blocks_subscribe">
						<input class="oc-footer__news-trap" type="text" name="website" tabindex="-1" autocomplete="off" aria-hidden="true">
						<input class="oc-footer__news-mail" type="email" name="email" required placeholder="<?php esc_attr_e( 'Your email', 'oc-theme' ); ?>" aria-label="<?php esc_attr_e( 'Email', 'oc-theme' ); ?>">
						<button class="oc-footer__news-go" type="submit"><?php esc_html_e( 'Subscribe', 'oc-theme' ); ?></button>
						<p class="oc-footer__news-thanks" role="status" hidden><?php esc_html_e( 'Thank you — you are on the list.', 'oc-theme' ); ?></p>
					</form>
				</div>
			<?php endif; ?>

		</div>
	</div>

	<div class="oc-footer__inner">
		<?php $oc_bottom(); ?>
	</div>

<?php endif; ?>

</footer>

<?php wp_footer(); ?>
</body>
</html>
