<?php
/**
 * Segmented button-group control.
 *
 * Replaces bare radio lists for small, mutually exclusive choices — the same
 * pill-row control the approved mockup used. Radios stay in the markup for
 * accessibility; the styling turns them into buttons.
 *
 * @package OC_Theme
 */

declare( strict_types = 1 );

namespace OC\Theme\Customize;

defined( 'ABSPATH' ) || exit;

/**
 * A radio group drawn as connected buttons.
 */
final class Segmented_Control extends \WP_Customize_Control {

	/**
	 * Control type.
	 *
	 * @var string
	 */
	public $type = 'oc-segmented';

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
	 * Render the group.
	 */
	protected function render_content(): void {
		if ( empty( $this->choices ) ) {
			return;
		}

		$name    = '_customize-oc-seg-' . $this->id;
		$current = (string) $this->value();
		?>
		<?php if ( $this->label ) : ?>
			<span class="customize-control-title"><?php echo esc_html( $this->label ); ?></span>
		<?php endif; ?>
		<?php if ( $this->description ) : ?>
			<span class="description customize-control-description"><?php echo esc_html( $this->description ); ?></span>
		<?php endif; ?>

		<div class="oc-seg" role="radiogroup" aria-label="<?php echo esc_attr( $this->label ); ?>">
			<?php foreach ( $this->choices as $value => $label ) : ?>
				<?php $value = (string) $value; ?>
				<label class="oc-seg__opt">
					<input
						type="radio"
						name="<?php echo esc_attr( $name ); ?>"
						value="<?php echo esc_attr( $value ); ?>"
						<?php checked( $current === $value ); ?>
						<?php $this->link(); ?>
					/>
					<span class="oc-seg__btn"><?php echo esc_html( (string) $label ); ?></span>
				</label>
			<?php endforeach; ?>
		</div>
		<?php
	}
}
