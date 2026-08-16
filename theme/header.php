<?php
/**
 * Site header.
 *
 * @package OC_Theme
 */

defined( 'ABSPATH' ) || exit;

$oc_preset  = sanitize_html_class( (string) get_theme_mod( 'oc_header_preset', 'classic' ) );
$oc_mobile  = sanitize_html_class( (string) get_theme_mod( 'oc_header_mobile', 'plain' ) );
$oc_sticky  = (bool) get_theme_mod( 'oc_header_sticky', true );
$oc_hborder = (bool) get_theme_mod( 'oc_header_border', true );

$oc_topbar_msgs = array_values(
	array_filter(
		array(
			trim( (string) get_theme_mod( 'oc_topbar_msg1', '' ) ),
			trim( (string) get_theme_mod( 'oc_topbar_msg2', '' ) ),
			trim( (string) get_theme_mod( 'oc_topbar_msg3', '' ) ),
		)
	)
);
$oc_topbar_on   = (bool) get_theme_mod( 'oc_topbar', false ) && ( ! empty( $oc_topbar_msgs ) || has_nav_menu( 'topbar' ) );
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

<?php if ( $oc_topbar_on ) : ?>
	<div class="oc-topbar oc-topbar--<?php echo esc_attr( sanitize_html_class( (string) get_theme_mod( 'oc_topbar_effect', 'fade' ) ) ); ?>">
		<div class="oc-topbar__inner">

			<?php if ( has_nav_menu( 'topbar' ) ) : ?>
				<nav class="oc-topbar__menu" aria-label="<?php esc_attr_e( 'Top bar', 'oc-theme' ); ?>">
					<?php
					wp_nav_menu(
						array(
							'theme_location' => 'topbar',
							'container'      => '',
							'menu_class'     => 'oc-topbar__list',
							'depth'          => 1,
						)
					);
					?>
				</nav>
			<?php endif; ?>

			<?php if ( ! empty( $oc_topbar_msgs ) ) : ?>
				<div class="oc-topbar__rotator">
					<?php if ( count( $oc_topbar_msgs ) > 1 ) : ?>
						<button type="button" class="oc-topbar__nav oc-topbar__nav--prev" aria-label="<?php esc_attr_e( 'Previous message', 'oc-theme' ); ?>"><svg viewBox="0 0 100 100" aria-hidden="true"><path d="M 70,0 L 20,50 L 70,100 L 80,90 L 40,50 L 80,10 Z"/></svg></button>
					<?php endif; ?>
					<div class="oc-topbar__msgs">
						<?php foreach ( $oc_topbar_msgs as $oc_i => $oc_msg ) : ?>
							<span class="oc-topbar__msg<?php echo 0 === $oc_i ? ' is-current' : ''; ?>"><?php echo esc_html( $oc_msg ); ?></span>
						<?php endforeach; ?>
					</div>
					<?php if ( count( $oc_topbar_msgs ) > 1 ) : ?>
						<button type="button" class="oc-topbar__nav oc-topbar__nav--next" aria-label="<?php esc_attr_e( 'Next message', 'oc-theme' ); ?>"><svg viewBox="0 0 100 100" aria-hidden="true"><path d="M 30,0 L 80,50 L 30,100 L 20,90 L 60,50 L 20,10 Z"/></svg></button>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<span class="oc-topbar__spacer" aria-hidden="true"></span>

		</div>
	</div>
<?php endif; ?>

<header
	id="main-header"
	class="oc-header oc-header--<?php echo esc_attr( $oc_preset ); ?> oc-header--m-<?php echo esc_attr( $oc_mobile ); ?><?php echo $oc_sticky ? ' is-sticky' : ''; ?><?php echo $oc_hborder ? '' : ' oc-header--noline'; ?><?php echo 'text' === get_theme_mod( 'oc_header_icons_style', 'icons' ) ? ' oc-icons-text' : ''; ?>"
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
			<?php if ( has_nav_menu( 'secondary' ) ) : ?>
				<nav class="oc-nav2" aria-label="<?php esc_attr_e( 'Secondary', 'oc-theme' ); ?>">
					<?php
					wp_nav_menu(
						array(
							'theme_location' => 'secondary',
							'container'      => '',
							'menu_class'     => 'oc-nav2__list',
							'depth'          => 1,
						)
					);
					?>
				</nav>
			<?php endif; ?>
			<?php do_action( 'oc_header_icons' ); ?>
		</div>

	</div>
</header>

<?php if ( get_theme_mod( 'oc_header_search', true ) ) : ?>
	<div id="oc-header-search" class="oc-header-search" hidden>
		<?php get_search_form(); ?>
	</div>
<?php endif; ?>

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
