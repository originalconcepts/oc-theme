<?php
/**
 * From–to pair.
 *
 * Two whole numbers that belong to one question — "delivery takes 3 to 7
 * days" — on one line, each bound to its own setting. Two stacked number
 * controls read as two questions; this reads as the one it is.
 *
 * @package OC_Theme
 */

declare( strict_types = 1 );

namespace OC\Theme\Customize;

defined( 'ABSPATH' ) || exit;

/**
 * "From … to …" on one line.
 */
final class Range_Control extends \WP_Customize_Control {

	/**
	 * Control type.
	 *
	 * @var string
	 */
	public $type = 'oc-range';

	/**
	 * Shared control styling.
	 */
	public function enqueue(): void {
		wp_enqueue_style(
			'oc-customize-controls',
			OC_THEME_URI . '/assets/css/customize-controls.css',
			array(),
			oc_asset_version( '/assets/css/customize-controls.css' )
		);
	}

	/**
	 * Draw the pair.
	 */
	protected function render_content(): void {
		$min = (int) ( $this->input_attrs['min'] ?? 1 );
		$max = (int) ( $this->input_attrs['max'] ?? 30 );
		?>
		<span class="customize-control-title"><?php echo esc_html( (string) $this->label ); ?></span>

		<div class="oc-range">
			<label class="oc-range__field">
				<span><?php echo esc_html_x( 'from', 'a range of days: from X to Y', 'oc-theme' ); ?></span>
				<input type="number" min="<?php echo (int) $min; ?>" max="<?php echo (int) $max; ?>" step="1" <?php $this->link( 'from' ); ?> value="<?php echo esc_attr( (string) $this->value( 'from' ) ); ?>">
			</label>
			<label class="oc-range__field">
				<span><?php echo esc_html_x( 'to', 'a range of days: from X to Y', 'oc-theme' ); ?></span>
				<input type="number" min="<?php echo (int) $min; ?>" max="<?php echo (int) $max; ?>" step="1" <?php $this->link( 'to' ); ?> value="<?php echo esc_attr( (string) $this->value( 'to' ) ); ?>">
			</label>
		</div>

		<?php if ( $this->description ) : ?>
			<span class="description customize-control-description"><?php echo esc_html( (string) $this->description ); ?></span>
		<?php endif; ?>
		<?php
	}
}
