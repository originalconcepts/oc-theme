<?php
/**
 * Weekday picker.
 *
 * Seven letters in a row, each one a day the shop sends orders out. A
 * checkbox each would have been seven controls in the panel for one
 * question; this is one control that answers it.
 *
 * The value travels as a comma-separated list of weekday numbers, Sunday
 * being 0 — the same numbering PHP's date( 'w' ) uses, so nothing has to be
 * translated on the way in or out.
 *
 * @package OC_Theme
 */

declare( strict_types = 1 );

namespace OC\Theme\Customize;

defined( 'ABSPATH' ) || exit;

/**
 * A row of weekday toggles.
 */
final class Days_Control extends \WP_Customize_Control {

	/**
	 * Control type.
	 *
	 * @var string
	 */
	public $type = 'oc-days';

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
	 * Draw the row.
	 */
	protected function render_content(): void {
		$chosen = array_filter( explode( ',', (string) $this->value() ), 'strlen' );
		$chosen = array_map( 'intval', $chosen );

		// Starts on Sunday, the way the week runs here.
		$labels = array(
			0 => _x( 'Su', 'weekday, one or two letters', 'oc-theme' ),
			1 => _x( 'Mo', 'weekday, one or two letters', 'oc-theme' ),
			2 => _x( 'Tu', 'weekday, one or two letters', 'oc-theme' ),
			3 => _x( 'We', 'weekday, one or two letters', 'oc-theme' ),
			4 => _x( 'Th', 'weekday, one or two letters', 'oc-theme' ),
			5 => _x( 'Fr', 'weekday, one or two letters', 'oc-theme' ),
			6 => _x( 'Sa', 'weekday, one or two letters', 'oc-theme' ),
		);

		$full = array(
			0 => __( 'Sunday', 'oc-theme' ),
			1 => __( 'Monday', 'oc-theme' ),
			2 => __( 'Tuesday', 'oc-theme' ),
			3 => __( 'Wednesday', 'oc-theme' ),
			4 => __( 'Thursday', 'oc-theme' ),
			5 => __( 'Friday', 'oc-theme' ),
			6 => __( 'Saturday', 'oc-theme' ),
		);
		?>
		<span class="customize-control-title"><?php echo esc_html( (string) $this->label ); ?></span>

		<?php if ( $this->description ) : ?>
			<span class="description customize-control-description"><?php echo esc_html( (string) $this->description ); ?></span>
		<?php endif; ?>

		<div class="oc-days" data-oc-days>
			<?php foreach ( $labels as $day => $short ) : ?>
				<label class="oc-days__day" title="<?php echo esc_attr( $full[ $day ] ); ?>">
					<input type="checkbox" value="<?php echo (int) $day; ?>" <?php checked( in_array( $day, $chosen, true ) ); ?>>
					<span><?php echo esc_html( $short ); ?></span>
				</label>
			<?php endforeach; ?>
		</div>

		<input type="hidden" <?php $this->link(); ?> value="<?php echo esc_attr( implode( ',', $chosen ) ); ?>">

		<script>
		( function () {
			var box = document.currentScript.previousElementSibling.previousElementSibling;
			var out = document.currentScript.previousElementSibling;

			box.addEventListener( 'change', function () {
				var on = [];

				box.querySelectorAll( 'input:checked' ).forEach( function ( c ) {
					on.push( c.value );
				} );

				out.value = on.join( ',' );
				out.dispatchEvent( new Event( 'change', { bubbles: true } ) );
			} );
		}() );
		</script>
		<?php
	}
}
