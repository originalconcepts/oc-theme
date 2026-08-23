<?php
/**
 * The screen where a panel gets built.
 *
 * Structure stays on WordPress's Menus screen. This screen answers the one
 * question that screen cannot: what opens underneath. It lists the top-level
 * items on one side, the selected item's blocks on the other, and shows the
 * panel itself at its real width underneath — because a setting you have to
 * save and go and look at is a setting nobody uses twice.
 *
 * The editor is built in the browser from a description of the block types
 * that this class hands it. Adding a type in Menu_Panel::types() therefore
 * adds it to this screen too, with no work here.
 *
 * @package OC_Theme
 */

declare( strict_types = 1 );

namespace OC\Theme;

defined( 'ABSPATH' ) || exit;

/**
 * The panel builder.
 */
final class Menu_Admin {

	/**
	 * Screen slug.
	 */
	/**
	 * Hooks.
	 */
	public function register(): void {
		if ( ! is_admin() ) {
			return;
		}

		add_action( 'wp_ajax_oc_menu_panel_save', array( $this, 'ajax_save' ) );
		add_action( 'wp_ajax_oc_menu_panel_preview', array( $this, 'ajax_preview' ) );
		add_action( 'wp_ajax_oc_menu_link_search', array( $this, 'ajax_search' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'wp_nav_menu_item_custom_fields', array( $this, 'item_link' ), 5 );
		add_action( 'admin_footer-nav-menus.php', array( $this, 'modal' ) );
	}

	/**
	 * A way in from the item itself, plus a plain statement of what happens
	 * when someone hovers it. Without that line you have to open every item
	 * to find out which ones have panels.
	 *
	 * @param int $id Menu item id.
	 */
	public function item_link( $id ): void {
		$id   = (int) $id;
		$item = get_post( $id );

		if ( ! $item instanceof \WP_Post || (int) $item->menu_item_parent > 0 ) {
			return;
		}

		if ( ! self::in_primary( $id ) ) {
			return;
		}

		printf(
			'<p class="oc-mi__panel"><span class="oc-mi__state">%1$s</span> <button type="button" class="button button-small oc-mi__edit" data-oc-panel="%2$d" data-oc-name="%3$s" data-oc-blocks="%4$s" data-oc-thumbs="%5$s">%6$s</button></p>',
			esc_html( self::state_line( $id ) ),
			$id,
			esc_attr( wp_strip_all_tags( (string) $item->title ) ),
			esc_attr( (string) wp_json_encode( Menu_Panel::blocks( $id ) ) ),
			esc_attr( (string) wp_json_encode( self::thumbs( Menu_Panel::blocks( $id ) ) ) ),
			esc_html__( 'Edit panel', 'oc-theme' )
		);
	}


	/**
	 * What happens when someone hovers this item, in a few words.
	 *
	 * Said in one place, because the Menus screen shows it and the editor
	 * rewrites it after a save; two spellings of the same fact drift.
	 *
	 * @param int $id Menu item id.
	 * @return string
	 */
	private static function state_line( int $id ): string {
		$shape = Menu_Panel::shape( $id );

		if ( ! Menu_Panel::is_panel( $id ) ) {
			return $shape['columns'] > 0
				? __( 'Opens as a plain drop-down', 'oc-theme' )
				: __( 'Just a link', 'oc-theme' );
		}

		$parts = array();

		if ( $shape['columns'] > 0 ) {
			/* translators: %d: number of columns. */
			$parts[] = sprintf( _n( '%d column from the menu', '%d columns from the menu', $shape['columns'], 'oc-theme' ), $shape['columns'] );
		}

		if ( $shape['extras'] > 0 ) {
			/* translators: %d: number of additions. */
			$parts[] = sprintf( _n( '%d addition', '%d additions', $shape['extras'], 'oc-theme' ), $shape['extras'] );
		}

		return implode( ' · ', $parts );
	}

	/**
	 * Top-level items of the primary menu.
	 *
	 * @return array<int,\WP_Post>
	 */
	private static function items(): array {
		$locations = get_nav_menu_locations();
		$menu      = isset( $locations['primary'] ) ? (int) $locations['primary'] : 0;

		if ( $menu < 1 ) {
			return array();
		}

		$items = wp_get_nav_menu_items( $menu );

		if ( ! is_array( $items ) ) {
			return array();
		}

		return array_values(
			array_filter(
				$items,
				static function ( $item ): bool {
					return 0 === (int) $item->menu_item_parent;
				}
			)
		);
	}

	/**
	 * Whether an item belongs to the primary menu at the top level.
	 *
	 * @param int $id Menu item id.
	 * @return bool
	 */
	private static function in_primary( int $id ): bool {
		foreach ( self::items() as $item ) {
			if ( (int) $item->ID === $id ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Store the blocks a browser sends.
	 */
	public function ajax_save(): void {
		check_ajax_referer( 'oc_menu_panel', 'nonce' );

		if ( ! current_user_can( 'edit_theme_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Not allowed.', 'oc-theme' ) ), 403 );
		}

		$item = isset( $_POST['item'] ) ? absint( $_POST['item'] ) : 0;

		if ( ! self::in_primary( $item ) ) {
			wp_send_json_error( array( 'message' => __( 'Unknown menu item.', 'oc-theme' ) ), 400 );
		}

		$blocks = self::posted_blocks();

		Menu_Panel::save( $item, $blocks );

		$blocks = Menu_Panel::blocks( $item );

		wp_send_json_success(
			array(
				'blocks' => $blocks,
				'thumbs' => self::thumbs( $blocks ),
				'state'  => self::state_line( $item ),
			)
		);
	}

	/**
	 * Render what is on screen, not what was last saved.
	 */
	public function ajax_preview(): void {
		check_ajax_referer( 'oc_menu_panel', 'nonce' );

		if ( ! current_user_can( 'edit_theme_options' ) ) {
			wp_send_json_error( array(), 403 );
		}

		$item = isset( $_POST['item'] ) ? absint( $_POST['item'] ) : 0;

		wp_send_json_success(
			array(
				'html' => Menu_Panel::preview( $item, self::posted_blocks() ),
			)
		);
	}

	/**
	 * The posted blocks, decoded and cleaned.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function posted_blocks(): array {
		$raw = isset( $_POST['blocks'] ) ? (string) wp_unslash( $_POST['blocks'] ) : '[]'; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- decoded and cleaned below; the nonce is checked by the callers.
		$list = json_decode( $raw, true );

		return is_array( $list ) ? Menu_Panel::clean( $list ) : array();
	}


	/**
	 * The shop's categories as a flat list, indented by depth, so a picker
	 * can show the tree without needing to know it is one.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function categories(): array {
		if ( ! taxonomy_exists( 'product_cat' ) ) {
			return array();
		}

		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
			)
		);

		if ( ! is_array( $terms ) ) {
			return array();
		}

		$by_parent = array();

		foreach ( $terms as $term ) {
			$by_parent[ (int) $term->parent ][] = $term;
		}

		$out = array();

		$walk = static function ( int $parent, int $depth ) use ( &$walk, $by_parent, &$out ): void {
			foreach ( $by_parent[ $parent ] ?? array() as $term ) {
				$out[] = array(
					'id'    => (int) $term->term_id,
					'label' => str_repeat( '— ', $depth ) . $term->name,
				);

				$walk( (int) $term->term_id, $depth + 1 );
			}
		};

		$walk( 0, 0 );

		return $out;
	}


	/**
	 * The editor, on the Menus screen.
	 *
	 * It opens over that screen rather than replacing it, because everything
	 * a panel is made of — which links, in what order, under which parent —
	 * is on the screen underneath, and sending someone somewhere else to
	 * arrange pictures around it made two screens out of one job.
	 */
	public function modal(): void {
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return;
		}

		$data = array(
			'types'   => Menu_Panel::types(),
			'widths'  => Menu_Panel::widths(),
			'devices' => Menu_Panel::devices(),
			'max'     => Menu_Panel::MAX,
			'cats'    => self::categories(),
			'nonce'   => wp_create_nonce( 'oc_menu_panel' ),
			'ajax'    => admin_url( 'admin-ajax.php' ),
			'css'     => get_template_directory_uri() . '/assets/css/' . ( file_exists( OC_THEME_DIR . '/assets/css/theme.min.css' ) ? 'theme.min.css' : 'theme.css' ),
			'rtl'     => is_rtl(),
			'i18n'    => array(
				'save'     => __( 'Save', 'oc-theme' ),
				'add'      => __( 'Add a block', 'oc-theme' ),
				'remove'   => __( 'Remove this block', 'oc-theme' ),
				'moveBack' => __( 'Move earlier', 'oc-theme' ),
				'moveOn'   => __( 'Move later', 'oc-theme' ),
				'width'    => __( 'Width', 'oc-theme' ),
				'device'   => __( 'Shown', 'oc-theme' ),
				'push'     => __( 'Leave the spare width in front of it', 'oc-theme' ),
				'choose'   => __( 'Choose', 'oc-theme' ),
				'clear'    => __( 'Remove', 'oc-theme' ),
				'saved'    => __( 'Saved', 'oc-theme' ),
				'saving'   => __( 'Saving…', 'oc-theme' ),
				'failed'   => __( 'Could not save. Try again.', 'oc-theme' ),
				'full'     => __( 'This panel is full.', 'oc-theme' ),
				'empty'    => __( 'The columns come from this link\'s own sub-items, on the screen behind. Add a picture here and they will sit beside it.', 'oc-theme' ),
				'preview'  => __( 'How it will look', 'oc-theme' ),
				'confirm'  => __( 'Remove this block?', 'oc-theme' ),
				'close'    => __( 'Close', 'oc-theme' ),
				'leave'    => __( 'There are unsaved changes. Close anyway?', 'oc-theme' ),
				'notAnAddress' => __( 'This is not an address yet — pick one from the list, or paste a link.', 'oc-theme' ),
			),
		);
		?>
		<div id="oc-mp-modal" class="oc-mp-modal" hidden data-oc-mp="<?php echo esc_attr( (string) wp_json_encode( $data ) ); ?>">
			<div class="oc-mp-modal__scrim" data-oc-mp-close></div>
			<div class="oc-mp-modal__box" role="dialog" aria-modal="true" aria-labelledby="oc-mp-modal-title">
				<div class="oc-mp-modal__head">
					<h2 id="oc-mp-modal-title"></h2>
					<button type="button" class="oc-mp-modal__x" data-oc-mp-close aria-label="<?php esc_attr_e( 'Close', 'oc-theme' ); ?>">&times;</button>
				</div>
				<div id="oc-mp-root"></div>
			</div>
		</div>
		<?php
	}

	/**
	 * Assets for this screen only.
	 *
	 * @param string $hook Current admin page.
	 */
	public function assets( string $hook ): void {
		if ( 'nav-menus.php' !== $hook ) {
			return;
		}

		wp_enqueue_media();

		wp_enqueue_style(
			'oc-menu-panel',
			OC_THEME_URI . '/assets/css/menu-panel.css',
			array(),
			oc_asset_version( '/assets/css/menu-panel.css' )
		);

		wp_enqueue_script(
			'oc-menu-panel',
			OC_THEME_URI . '/assets/js/menu-panel.js',
			array(),
			oc_asset_version( '/assets/js/menu-panel.js' ),
			true
		);
	}

	/**
	 * Thumbnails for the pictures a panel already uses, so the editor can
	 * show them without a request per image.
	 *
	 * @param array<int,array<string,mixed>> $blocks Blocks.
	 * @return array<int,string>
	 */
	private static function thumbs( array $blocks ): array {
		$out = array();

		foreach ( $blocks as $block ) {
			foreach ( $block as $value ) {
				if ( ! is_int( $value ) || $value < 1 ) {
					continue;
				}

				$src = wp_get_attachment_image_url( $value, 'thumbnail' );

				if ( is_string( $src ) ) {
					$out[ $value ] = $src;
				}
			}
		}

		return $out;
	}

	/**
	 * Somewhere on this site, found by name.
	 *
	 * Typing a URL by hand is the part of building a menu that people put off,
	 * and a menu that is out of date because updating it was tedious is worse
	 * than one with a plainer layout.
	 */
	public function ajax_search(): void {
		check_ajax_referer( 'oc_menu_panel', 'nonce' );

		if ( ! current_user_can( 'edit_theme_options' ) ) {
			wp_send_json_error( array(), 403 );
		}

		$term = isset( $_POST['s'] ) ? sanitize_text_field( wp_unslash( $_POST['s'] ) ) : '';

		if ( mb_strlen( $term ) < 2 ) {
			wp_send_json_success( array( 'hits' => array() ) );
		}

		$hits = array();

		$taxonomies = array_filter(
			array( 'product_cat', 'product_tag', 'product_brand', 'pa_brand' ),
			'taxonomy_exists'
		);

		if ( ! empty( $taxonomies ) ) {
			$terms = get_terms(
				array(
					'taxonomy'   => $taxonomies,
					'search'     => $term,
					'number'     => 8,
					'hide_empty' => false,
				)
			);

			foreach ( ( is_array( $terms ) ? $terms : array() ) as $found ) {
				$tax = get_taxonomy( $found->taxonomy );

				$hits[] = array(
					'label' => $found->name,
					'url'   => (string) get_term_link( $found ),
					'kind'  => $tax ? (string) $tax->labels->singular_name : $found->taxonomy,
				);
			}
		}

		$posts = get_posts(
			array(
				'post_type'        => array_filter( array( 'page', 'post', 'product' ), 'post_type_exists' ),
				's'                => $term,
				'numberposts'      => 6,
				'post_status'      => 'publish',
				'suppress_filters' => false,
			)
		);

		foreach ( $posts as $post ) {
			$type = get_post_type_object( $post->post_type );

			$hits[] = array(
				'label' => get_the_title( $post ),
				'url'   => (string) get_permalink( $post ),
				'kind'  => $type ? (string) $type->labels->singular_name : $post->post_type,
			);
		}

		wp_send_json_success( array( 'hits' => array_slice( $hits, 0, 12 ) ) );
	}
}
