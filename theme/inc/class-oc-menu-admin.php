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
	private const PAGE = 'oc-menu-panels';

	/**
	 * Hooks.
	 */
	public function register(): void {
		if ( ! is_admin() ) {
			return;
		}

		add_action( 'admin_menu', array( $this, 'page' ) );
		add_action( 'wp_ajax_oc_menu_panel_save', array( $this, 'ajax_save' ) );
		add_action( 'wp_ajax_oc_menu_panel_preview', array( $this, 'ajax_preview' ) );
		add_action( 'wp_ajax_oc_menu_link_search', array( $this, 'ajax_search' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'wp_nav_menu_item_custom_fields', array( $this, 'item_link' ), 5 );
	}

	/**
	 * The screen.
	 */
	public function page(): void {
		add_theme_page(
			__( 'Menu panels', 'oc-theme' ),
			__( 'Menu panels', 'oc-theme' ),
			'edit_theme_options',
			self::PAGE,
			array( $this, 'screen' )
		);
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

		$count = count( Menu_Panel::blocks( $id ) );
		$kids  = self::has_children( $id );

		if ( $count > 0 ) {
			/* translators: %d: number of blocks. */
			$state = sprintf( _n( '%d block', '%d blocks', $count, 'oc-theme' ), $count );
		} elseif ( $kids ) {
			$state = __( 'Opens as a plain drop-down', 'oc-theme' );
		} else {
			$state = __( 'Just a link', 'oc-theme' );
		}

		printf(
			'<p class="oc-mi__panel"><span class="oc-mi__state">%s</span> <a class="button button-small" href="%s">%s</a></p>',
			esc_html( $state ),
			esc_url( add_query_arg( array( 'page' => self::PAGE, 'item' => $id ), admin_url( 'themes.php' ) ) ),
			esc_html__( 'Edit panel', 'oc-theme' )
		);
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
	 * Whether an item has children in the primary menu.
	 *
	 * @param int $id Menu item id.
	 * @return bool
	 */
	private static function has_children( int $id ): bool {
		$locations = get_nav_menu_locations();
		$menu      = isset( $locations['primary'] ) ? (int) $locations['primary'] : 0;
		$items     = $menu > 0 ? wp_get_nav_menu_items( $menu ) : array();

		foreach ( (array) $items as $item ) {
			if ( (int) $item->menu_item_parent === $id ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Render the screen.
	 */
	public function screen(): void {
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return;
		}

		$items = self::items();

		if ( empty( $items ) ) {
			printf(
				'<div class="wrap"><h1>%s</h1><div class="notice notice-warning"><p>%s</p></div></div>',
				esc_html__( 'Menu panels', 'oc-theme' ),
				sprintf(
					/* translators: %s: link to the Menus screen. */
					esc_html__( 'No menu is assigned to the primary location yet. Set one on the %s screen and come back.', 'oc-theme' ),
					'<a href="' . esc_url( admin_url( 'nav-menus.php' ) ) . '">' . esc_html__( 'Menus', 'oc-theme' ) . '</a>'
				)
			);
			return;
		}

		$current = isset( $_GET['item'] ) ? absint( $_GET['item'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( ! self::in_primary( $current ) ) {
			$current = (int) $items[0]->ID;
		}

		wp_enqueue_media();

		$data = array(
			'types'   => Menu_Panel::types(),
			'widths'  => Menu_Panel::widths(),
			'devices' => Menu_Panel::devices(),
			'max'     => Menu_Panel::MAX,
			'blocks'  => Menu_Panel::blocks( $current ),
			'thumbs'  => self::thumbs( Menu_Panel::blocks( $current ) ),
			'item'    => $current,
			'nonce'   => wp_create_nonce( 'oc_menu_panel' ),
			'ajax'    => admin_url( 'admin-ajax.php' ),
			'css'     => get_template_directory_uri() . '/assets/css/' . ( file_exists( OC_THEME_DIR . '/assets/css/theme.min.css' ) ? 'theme.min.css' : 'theme.css' ),
			'rtl'     => is_rtl(),
			'i18n'    => array(
				'save'     => __( 'Save', 'oc-theme' ),
				'add'      => __( 'Add a block', 'oc-theme' ),
				'remove'   => __( 'Remove this block', 'oc-theme' ),
				'move'     => __( 'Move', 'oc-theme' ),
				'width'    => __( 'Width', 'oc-theme' ),
				'device'   => __( 'Shown', 'oc-theme' ),
				'addLink'  => __( 'Add a link', 'oc-theme' ),
				'linkText' => __( 'Words', 'oc-theme' ),
				'linkUrl'  => __( 'Address', 'oc-theme' ),
				'notAnAddress' => __( 'This is not an address yet — pick one from the list, or paste a link.', 'oc-theme' ),
				'linkTag'  => __( 'Badge', 'oc-theme' ),
				'choose'   => __( 'Choose', 'oc-theme' ),
				'clear'    => __( 'Remove', 'oc-theme' ),
				'saved'    => __( 'Saved', 'oc-theme' ),
				'saving'   => __( 'Saving…', 'oc-theme' ),
				'failed'   => __( 'Could not save. Try again.', 'oc-theme' ),
				'full'     => __( 'This panel is full.', 'oc-theme' ),
				'empty'    => __( 'No blocks yet. This link opens as a plain drop-down if it has children, and as a plain link if it does not.', 'oc-theme' ),
				'untitled' => __( 'Untitled', 'oc-theme' ),
				'preview'  => __( 'How it will look', 'oc-theme' ),
				'confirm'  => __( 'Remove this block?', 'oc-theme' ),
			),
		);
		?>
		<div class="wrap oc-mp">
			<h1><?php esc_html_e( 'Menu panels', 'oc-theme' ); ?></h1>
			<p class="oc-mp__lead"><?php esc_html_e( 'Pick a link on the right, then build what opens underneath it. Order and hierarchy stay on the Menus screen.', 'oc-theme' ); ?></p>

			<div class="oc-mp__grid">
				<div class="oc-mp__side">
					<h2 class="oc-mp__sh"><?php esc_html_e( 'Links in the menu', 'oc-theme' ); ?></h2>
					<?php
					foreach ( $items as $item ) {
						$id    = (int) $item->ID;
						$count = count( Menu_Panel::blocks( $id ) );

						if ( $count > 0 ) {
							/* translators: %d: number of blocks. */
							$state = sprintf( _n( '%d block', '%d blocks', $count, 'oc-theme' ), $count );
						} elseif ( self::has_children( $id ) ) {
							$state = __( 'Plain drop-down', 'oc-theme' );
						} else {
							$state = __( 'Link only', 'oc-theme' );
						}

						printf(
							'<a class="oc-mp__item%s" href="%s"><span>%s</span><small>%s</small></a>',
							$id === $current ? ' is-on' : '',
							esc_url( add_query_arg( array( 'page' => self::PAGE, 'item' => $id ), admin_url( 'themes.php' ) ) ),
							esc_html( $item->title ),
							esc_html( $state )
						);
					}
					?>
				</div>

				<div class="oc-mp__main">
					<div id="oc-mp-root" data-oc-mp="<?php echo esc_attr( (string) wp_json_encode( $data ) ); ?>"></div>
				</div>
			</div>
		</div>
		<?php
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

		wp_send_json_success(
			array(
				'count' => count( Menu_Panel::blocks( $item ) ),
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

		wp_send_json_success(
			array(
				'html' => Menu_Panel::render( self::posted_blocks() ),
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
	 * Assets for this screen only.
	 *
	 * @param string $hook Current admin page.
	 */
	public function assets( string $hook ): void {
		if ( 'appearance_page_' . self::PAGE !== $hook ) {
			return;
		}

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
