<?php
/**
 * A visual divider heading inside a customizer section.
 *
 * Loaded only from within customize_register, when WP_Customize_Control
 * exists.
 *
 * @package OC_Theme
 */

declare( strict_types = 1 );

namespace OC\Theme;

defined( 'ABSPATH' ) || exit;

/**
 * Renders a styled group heading between controls.
 */
final class Heading_Control extends \WP_Customize_Control {

	/**
	 * Control type.
	 *
	 * @var string
	 */
	public $type = 'oc_heading';

	/**
	 * Print the heading.
	 */
	public function render_content(): void {
		printf(
			'<h3 style="margin:20px 0 0;padding:10px 12px;background:#f0f0f1;border-inline-start:4px solid #3858e9;font-size:12px;text-transform:uppercase;letter-spacing:.05em;">%s</h3>',
			esc_html( (string) $this->label )
		);
	}
}
