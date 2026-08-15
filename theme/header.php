<?php
/**
 * Site header.
 *
 * @package OC_Theme
 */

defined( 'ABSPATH' ) || exit;

$oc_preset = sanitize_html_class( (string) get_theme_mod( 'oc_header_preset', 'classic' ) );
$oc_sticky = (bool) get_theme_mod( 'oc_header_sticky', true );
?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<?php wp_head(); ?>
</head>

<body <?php body_class(); ?>>
<?php wp_body_open(); ?>

<a class="oc-skip" href="#main"><?php esc_html_e( 'Skip to content', 'oc-theme' ); ?></a>

<header
	id="main-header"
	class="oc-header oc-header--<?php echo esc_attr( $oc_preset ); ?><?php echo $oc_sticky ? ' is-sticky' : ''; ?>"
>
	<div class="oc-header__inner">

		<div class="oc-header__start">
			<button
				class="oc-burger"
				type="button"
				aria-controls="oc-mobile-menu"
				aria-expanded="false"
				aria-label="<?php esc_attr_e( 'Open menu', 'oc-theme' ); ?>"
			>
				<span aria-hidden="true"></span>
			</button>

			<?php
			if ( has_custom_logo() ) {
				the_custom_logo();
			} else {
				printf(
					'<a class="oc-logo oc-logo--text" href="%s">%s</a>',
					esc_url( home_url( '/' ) ),
					esc_html( get_bloginfo( 'name' ) )
				);
			}
			?>
		</div>

		<?php if ( has_nav_menu( 'primary' ) ) : ?>
			<nav class="oc-nav" aria-label="<?php esc_attr_e( 'Primary', 'oc-theme' ); ?>">
				<?php
				wp_nav_menu(
					array(
						'theme_location' => 'primary',
						'container'      => '',
						'menu_class'     => 'oc-nav__list',
						'depth'          => 3,
					)
				);
				?>
			</nav>
		<?php endif; ?>

		<div class="oc-header__end">
			<?php do_action( 'oc_header_icons' ); ?>
		</div>

	</div>
</header>

<?php if ( has_nav_menu( 'primary' ) ) : ?>
	<div id="oc-mobile-menu" class="oc-mobile-menu" hidden>
		<?php
		wp_nav_menu(
			array(
				'theme_location' => 'primary',
				'container'      => '',
				'menu_class'     => 'oc-mobile-menu__list',
				'depth'          => 3,
			)
		);
		?>
	</div>
<?php endif; ?>
