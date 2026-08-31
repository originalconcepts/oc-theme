<?php
/**
 * The way in: say what this is, put the search where the hand lands, then
 * the six doors. Someone who knows what they want searches; someone who does
 * not, browses.
 *
 * @package OC_Guide
 */

defined( 'ABSPATH' ) || exit;

get_header();

$oc_icons = array(
	'content'    => '<path d="M4 5h16M4 10h16M4 15h10"/>',
	'products'   => '<path d="M3 7l9-4 9 4-9 4-9-4Z"/><path d="M3 7v10l9 4 9-4V7"/><path d="M12 11v10"/>',
	'categories' => '<path d="M4 4h7v7H4zM13 4h7v7h-7zM4 13h7v7H4zM13 13h7v7h-7z"/>',
	'promotions' => '<path d="M20 12 12 20 4 12V4h8Z"/><circle cx="8.5" cy="8.5" r="1.4"/>',
	'settings'   => '<circle cx="12" cy="12" r="3.2"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3M4.9 4.9l2.1 2.1M17 17l2.1 2.1M19.1 4.9 17 7M7 17l-2.1 2.1"/>',
	'advanced'   => '<path d="M4 20h16"/><path d="M7 20V9M12 20V4M17 20v-7"/>',
);
?>

<section class="g-hero">
	<div class="g-wrap">
		<h1><?php esc_html_e( 'איך עושים את זה באתר', 'oc-guide' ); ?></h1>
		<p><?php esc_html_e( 'מדריך מלא לניהול החנות — שלב אחר שלב, מהוספת מוצר ועד בניית מבצע. חפשו מה שאתם צריכים, או בחרו נושא.', 'oc-guide' ); ?></p>
		<?php echo OC\Guide\search_html( 'מה תרצו לעשות? למשל: מחיר מבצע' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts. ?>
	</div>
</section>

<div class="g-wrap">
	<div class="g-sections">
		<?php
		foreach ( OC\Guide\SECTIONS as $oc_slug => $oc_s ) :
			$oc_cat = get_category_by_slug( $oc_slug );

			if ( ! $oc_cat instanceof WP_Term ) {
				continue;
			}

			$oc_n = OC\Guide\count_in( $oc_slug );
			?>
			<a class="g-card" href="<?php echo esc_url( (string) get_category_link( $oc_cat->term_id ) ); ?>">
				<span class="g-card__ico" aria-hidden="true">
					<svg viewBox="0 0 24 24" width="21" height="21" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><?php echo $oc_icons[ $oc_slug ]; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?></svg>
				</span>
				<h2><?php echo esc_html( $oc_s['name'] ); ?></h2>
				<p><?php echo esc_html( $oc_s['blurb'] ); ?></p>
				<span class="g-card__n">
					<?php
					printf(
						/* translators: %d: how many guides. */
						esc_html( _n( 'מדריך אחד', '%d מדריכים', $oc_n, 'oc-guide' ) ),
						(int) $oc_n
					);
					?>
				</span>
			</a>
		<?php endforeach; ?>
	</div>
</div>

<?php
get_footer();
