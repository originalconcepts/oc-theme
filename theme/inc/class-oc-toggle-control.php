<?php
/**
 * Switch-style toggle control.
 *
 * A checkbox drawn as an on/off switch, matching the approved mockup. The
 * checkbox stays in the markup for accessibility.
 *
 * @package OC_Theme
 */

declare( strict_types = 1 );

namespace OC\Theme\Customize;

defined( 'ABSPATH' ) || exit;

/**
 * A checkbox drawn as a switch.
 */
final class Toggle_Control extends \WP_Customize_Control {

	/**
	 * Control type.
	 *
	 * @var string
	 */
	public $type = 'oc-toggle';

	/**
	 * Enqueue shared control styles.
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
	 * Render the switch.
	 */
	protected function render_content(): void {
		?>
		<label class="oc-toggle">
			<input type="checkbox" <?php checked( (bool) $this->value() ); ?> <?php $this->link(); ?> />
			<span class="oc-toggle__track" aria-hidden="true"></span>
			<span class="oc-toggle__label">
				<?php echo esc_html( $this->label ); ?>
				<?php if ( $this->description ) : ?>
					<span class="oc-toggle__hint"><?php echo esc_html( $this->description ); ?></span>
				<?php endif; ?>
			</span>
		</label>
		<?php
	}
}
