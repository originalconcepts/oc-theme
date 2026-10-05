<?php
/**
 * A real editor for a category's description.
 *
 * WordPress gives a taxonomy a bare textarea, and a shop writing about a
 * department wants what it would want in a page: a bold word, a line
 * break that stays, a link to the size guide. Typing the HTML by hand is
 * not a thing to ask of a shop owner, and the textarea strips most of it
 * on the way out anyway.
 *
 * So the textarea is replaced with the editor WordPress already has, and
 * the description is saved through wp_kses_post rather than through the
 * much narrower filter a term description normally gets.
 *
 * @package OC_Theme
 */

declare( strict_types = 1 );

namespace OC\Theme;

defined( 'ABSPATH' ) || exit;

/**
 * The category description, written the way a page is written.
 */
final class Term_Editor {

	/**
	 * The taxonomies that get it.
	 */
	private const TAXONOMIES = array( 'product_cat', 'product_tag', 'category' );

	/**
	 * Hook in.
	 */
	public function register(): void {
		add_action( 'admin_init', array( $this, 'rows' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
	}

	/**
	 * Put the editor on every taxonomy screen that should have one, and
	 * take the narrow sanitiser off the way back in.
	 */
	public function rows(): void {
		foreach ( self::TAXONOMIES as $tax ) {
			if ( ! taxonomy_exists( $tax ) ) {
				continue;
			}

			// Priority 1: before anything else this theme adds, so the
			// description stays where WordPress put it.
			add_action( $tax . '_edit_form_fields', array( $this, 'field' ), 1 );
		}

		if ( ! current_user_can( 'unfiltered_html' ) ) {
			return;
		}

		// A term description is run through wp_filter_kses, which is far
		// narrower than what a page may contain -- it loses a heading and a
		// list. Only for people already trusted with HTML elsewhere.
		remove_filter( 'pre_term_description', 'wp_filter_kses' );
		add_filter( 'pre_term_description', 'wp_kses_post' );
	}

	/**
	 * Is this one of ours, and is this the right screen?
	 */
	private function mine(): string {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || 'term' !== $screen->base ) {
			return '';
		}

		return in_array( (string) $screen->taxonomy, self::TAXONOMIES, true ) ? (string) $screen->taxonomy : '';
	}

	/**
	 * The editor, in a row of its own.
	 *
	 * @param \WP_Term $term The term being edited.
	 */
	public function field( $term ): void {
		if ( ! $term instanceof \WP_Term || '' === $this->mine() ) {
			return;
		}

		?>
		<tr class="form-field term-description-wrap oc-term-editor">
			<th scope="row"><label for="oc_term_description"><?php esc_html_e( 'Description', 'oc-theme' ); ?></label></th>
			<td>
				<?php
				wp_editor(
					$term->description,
					'oc_term_description',
					array(
						'textarea_name' => 'description',
						'textarea_rows' => 10,
						'media_buttons' => true,
						'tinymce'       => array(
							'toolbar1' => 'formatselect,bold,italic,bullist,numlist,blockquote,alignleft,aligncenter,alignright,link,unlink,undo,redo',
							'toolbar2' => '',
						),
						'quicktags'     => array( 'buttons' => 'strong,em,link,ul,ol,li,close' ),
					)
				);
				?>
				<p class="description"><?php esc_html_e( 'Shown on the category page. Bold, links and lists are kept.', 'oc-theme' ); ?></p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Take WordPress's own plain textarea off the screen.
	 *
	 * It cannot simply be removed in PHP -- core prints it -- so the row is
	 * hidden and its field renamed, leaving one description to submit.
	 */
	public function assets(): void {
		if ( '' === $this->mine() ) {
			return;
		}

		wp_add_inline_script(
			'jquery-core',
			'document.addEventListener( "DOMContentLoaded", function () {
				var ours = document.getElementById( "oc_term_description" );
				var old  = document.getElementById( "description" );

				if ( ! ours || ! old ) { return; }

				var row = old.closest( "tr" );

				if ( row ) { row.style.display = "none"; }

				// Two fields called "description" would post twice, and the
				// empty one would win.
				old.setAttribute( "name", "oc_unused_description" );
				old.removeAttribute( "id" );
			} );'
		);
	}
}
