<?php
/**
 * Drawn preset picker for the Customizer.
 *
 * A preset is a layout choice, and a layout choice is far easier to make from a
 * picture than from a dropdown. Each option renders a small inline SVG
 * wireframe built from theme tokens, so it follows the admin colour scheme and
 * needs no image files.
 *
 * This is the one control WordPress does not provide, and the reason we no
 * longer need Kirki (DECISIONS.md #6).
 *
 * @package OC_Theme
 */

declare( strict_types = 1 );

namespace OC\Theme\Customize;

defined( 'ABSPATH' ) || exit;

/**
 * Radio control whose options are drawings.
 */
final class Preset_Control extends \WP_Customize_Control {

	/**
	 * Control type.
	 *
	 * @var string
	 */
	public $type = 'oc-preset';

	/**
	 * Options: value => array{ label:string, hint?:string, svg:string }.
	 *
	 * @var array<string,array<string,string>>
	 */
	public array $presets = array();

	/**
	 * Minimum column width for the picker grid.
	 *
	 * @var string
	 */
	public string $item_width = '168px';

	/**
	 * Enqueue the control's own styles.
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
	 * Render the picker.
	 */
	protected function render_content(): void {
		if ( empty( $this->presets ) ) {
			return;
		}

		$name    = '_customize-oc-preset-' . $this->id;
		$current = (string) $this->value();
		?>
		<# /* markup is printed server-side; this control does not use JS templates */ #>
		<?php if ( $this->label ) : ?>
			<span class="customize-control-title"><?php echo esc_html( $this->label ); ?></span>
		<?php endif; ?>
		<?php if ( $this->description ) : ?>
			<span class="description customize-control-description"><?php echo esc_html( $this->description ); ?></span>
		<?php endif; ?>

		<div class="oc-presets" style="--oc-preset-w:<?php echo esc_attr( $this->item_width ); ?>">
			<?php foreach ( $this->presets as $value => $preset ) : ?>
				<?php
				$value   = (string) $value;
				$input   = $name . '-' . sanitize_key( $value );
				$checked = ( $current === $value );
				?>
				<label class="oc-preset<?php echo $checked ? ' is-selected' : ''; ?>" for="<?php echo esc_attr( $input ); ?>">
					<input
						id="<?php echo esc_attr( $input ); ?>"
						type="radio"
						name="<?php echo esc_attr( $name ); ?>"
						value="<?php echo esc_attr( $value ); ?>"
						<?php checked( $checked ); ?>
						<?php $this->link(); ?>
					/>
					<span class="oc-preset__art" aria-hidden="true">
						<?php echo $this->wireframe( (string) ( $preset['svg'] ?? '' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- sanitised by wireframe(). ?>
					</span>
					<span class="oc-preset__cap">
						<span class="oc-preset__name"><?php echo esc_html( (string) ( $preset['label'] ?? $value ) ); ?></span>
						<?php if ( ! empty( $preset['hint'] ) ) : ?>
							<span class="oc-preset__hint"><?php echo esc_html( (string) $preset['hint'] ); ?></span>
						<?php endif; ?>
					</span>
				</label>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Allow only the SVG shapes we draw wireframes with.
	 *
	 * The markup comes from our own PHP rather than from user input, but it is
	 * still passed through wp_kses so a future editing mistake cannot turn a
	 * preset definition into a script tag.
	 *
	 * @param string $svg Raw SVG markup.
	 * @return string
	 */
	private function wireframe( string $svg ): string {
		$shape = array(
			'class'           => true,
			'x'               => true,
			'y'               => true,
			'width'           => true,
			'height'          => true,
			'rx'              => true,
			'cx'              => true,
			'cy'              => true,
			'r'               => true,
			'd'               => true,
			'fill'            => true,
			'stroke'          => true,
			'opacity'         => true,
			'stroke-linecap'  => true,
			'stroke-linejoin' => true,
		);

		return wp_kses(
			$svg,
			array(
				'svg'    => array(
					'class'       => true,
					'viewbox'     => true,
					'xmlns'       => true,
					'role'        => true,
					'aria-hidden' => true,
				),
				'rect'   => $shape,
				'circle' => $shape,
				'path'   => $shape,
				'g'      => $shape,
			)
		);
	}
}
