<?php
/**
 * The guide's masthead. A shop header carries a basket; this one carries
 * the six sections and the search, because that is what a reader needs.
 *
 * @package OC_Guide
 */

defined( 'ABSPATH' ) || exit;
?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>

<header class="g-head">
	<div class="g-wrap g-head__in">
		<a class="g-brand" href="<?php echo esc_url( home_url( '/' ) ); ?>">
			<span class="g-brand__mark" aria-hidden="true">OC</span>
			<span><?php echo esc_html( get_bloginfo( 'name' ) ); ?></span>
		</a>

		<nav class="g-nav" aria-label="<?php esc_attr_e( 'נושאי המדריך', 'oc-guide' ); ?>">
			<?php echo OC\Guide\nav_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts. ?>
		</nav>

		<?php
		if ( ! is_front_page() ) {
			echo OC\Guide\search_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
		}
		?>
	</div>
</header>

<main id="main">
