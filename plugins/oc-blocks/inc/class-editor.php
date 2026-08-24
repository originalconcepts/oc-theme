<?php
/**
 * The composer screen: a full-screen editor with a live 1:1 preview.
 *
 * Sections stand as cards on one side, the chosen section's settings on the
 * other, and between them the page itself — rendered by the site, at
 * desktop, tablet or phone width. Saving is one button; everything else is
 * a click or a drag.
 *
 * @package OC_Blocks
 */

declare( strict_types = 1 );

namespace OC\Blocks;

defined( 'ABSPATH' ) || exit;

/**
 * Admin side of the composer.
 */
final class Editor {

	/**
	 * Hook in.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'screen' ) );
		add_filter( 'page_row_actions', array( $this, 'row_action' ), 10, 2 );
		add_action( 'add_meta_boxes_page', array( $this, 'meta_box' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );

		add_action( 'wp_ajax_oc_blocks_save', array( $this, 'ajax_save' ) );
		add_action( 'wp_ajax_oc_blocks_draft', array( $this, 'ajax_draft' ) );
		add_action( 'wp_ajax_oc_blocks_search', array( $this, 'ajax_search' ) );
	}

	/**
	 * A hidden admin page carries the editor (linked, not in the menu).
	 */
	public function screen(): void {
		add_submenu_page(
			'',
			__( 'OC Blocks', 'oc-blocks' ),
			__( 'OC Blocks', 'oc-blocks' ),
			'edit_pages',
			'oc-blocks',
			array( $this, 'render_screen' )
		);
	}

	/**
	 * The door from the pages list.
	 *
	 * @param array<string,string> $actions Row actions.
	 * @param \WP_Post             $post    Post.
	 * @return array<string,string>
	 */
	public function row_action( $actions, $post ) {
		if ( 'page' === $post->post_type && current_user_can( 'edit_page', $post->ID ) ) {
			$actions['oc_blocks'] = '<a href="' . esc_url( self::url( (int) $post->ID ) ) . '">' . esc_html__( 'Build with OC Blocks', 'oc-blocks' ) . '</a>';
		}

		return $actions;
	}

	/**
	 * The door from the page's edit screen.
	 */
	public function meta_box(): void {
		add_meta_box(
			'oc-blocks-door',
			__( 'OC Blocks', 'oc-blocks' ),
			static function ( \WP_Post $post ): void {
				$built = Render::is_composed( (int) $post->ID );

				echo '<p style="margin-block-start:0;">'
					. esc_html( $built ? __( 'This page is built with the composer; its sections replace the content below.', 'oc-blocks' ) : __( 'Build this page from ready-made sections, with a live preview.', 'oc-blocks' ) )
					. '</p>';
				echo '<a class="button button-primary" style="width:100%;text-align:center;" href="' . esc_url( self::url( (int) $post->ID ) ) . '">'
					. esc_html( $built ? __( 'Open the composer', 'oc-blocks' ) : __( 'Build with OC Blocks', 'oc-blocks' ) )
					. '</a>';
			},
			'page',
			'side',
			'high'
		);
	}

	/**
	 * The composer's address for one page.
	 *
	 * @param int $page_id Page id.
	 */
	public static function url( int $page_id ): string {
		return admin_url( 'admin.php?page=oc-blocks&post=' . $page_id );
	}

	/**
	 * Assets and data, only on the composer screen.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function assets( $hook ): void {
		if ( 'admin_page_oc-blocks' !== $hook ) {
			return;
		}

		$page_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing.

		wp_enqueue_media();
		wp_enqueue_style( 'oc-blocks-editor', OC_BLOCKS_URI . 'assets/editor.css', array(), (string) filemtime( OC_BLOCKS_DIR . 'assets/editor.css' ) );
		wp_enqueue_script( 'oc-blocks-editor', OC_BLOCKS_URI . 'assets/editor.js', array( 'jquery' ), (string) filemtime( OC_BLOCKS_DIR . 'assets/editor.js' ), true );

		wp_localize_script(
			'oc-blocks-editor',
			'ocBlocks',
			array(
				'page'     => $page_id,
				'title'    => $page_id ? get_the_title( $page_id ) : '',
				'back'     => $page_id ? get_edit_post_link( $page_id, 'raw' ) : admin_url( 'edit.php?post_type=page' ),
				'view'     => $page_id ? get_permalink( $page_id ) : home_url( '/' ),
				'ajax'     => admin_url( 'admin-ajax.php' ),
				'preview'  => home_url( '/' ),
				'nonce'    => wp_create_nonce( 'oc_blocks' ),
				'types'    => self::types_for_js(),
				'shell'    => self::fields_for_js( Registry::shell() ),
				'sections' => $page_id ? Registry::sections( $page_id ) : array(),
				'thumbs'   => $page_id ? self::thumbs( Registry::sections( $page_id ) ) : array(),
				'names'    => $page_id ? self::names( Registry::sections( $page_id ) ) : array(),
				'i18n'     => array(
					'save'     => __( 'Save', 'oc-blocks' ),
					'saved'    => __( 'Saved', 'oc-blocks' ),
					'saving'   => __( 'Saving…', 'oc-blocks' ),
					'failed'   => __( 'Could not save', 'oc-blocks' ),
					'add'      => __( 'Add a section', 'oc-blocks' ),
					'empty'    => __( 'The page is empty. Add your first section:', 'oc-blocks' ),
					'sections' => __( 'Sections', 'oc-blocks' ),
					'content'  => __( 'Content', 'oc-blocks' ),
					'design'   => __( 'Design', 'oc-blocks' ),
					'shellttl' => __( 'Frame', 'oc-blocks' ),
					'remove'   => __( 'Remove', 'oc-blocks' ),
					'dup'      => __( 'Duplicate', 'oc-blocks' ),
					'confirm'  => __( 'Remove this section?', 'oc-blocks' ),
					'pick'     => __( 'Choose', 'oc-blocks' ),
					'change'   => __( 'Change', 'oc-blocks' ),
					'clear'    => __( 'Remove', 'oc-blocks' ),
					'addSlide' => __( 'Add a slide', 'oc-blocks' ),
					'slide'    => __( 'Slide card', 'oc-blocks' ),
					'search'   => __( 'Type to search…', 'oc-blocks' ),
					'view'     => __( 'View page', 'oc-blocks' ),
					'unsaved'  => __( 'There are unsaved changes.', 'oc-blocks' ),
					'media'    => __( 'Choose a picture', 'oc-blocks' ),
					'video'    => __( 'Choose a video', 'oc-blocks' ),
				),
			)
		);
	}

	/**
	 * The registry, trimmed for the browser.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private static function types_for_js(): array {
		$out = array();

		foreach ( Registry::types() as $key => $type ) {
			$out[ $key ] = array(
				'label'  => (string) $type['label'],
				'blurb'  => (string) $type['blurb'],
				'icon'   => (string) $type['icon'],
				'fields' => self::fields_for_js( (array) $type['fields'] ),
			);
		}

		return $out;
	}

	/**
	 * Field declarations, sub-repeaters included.
	 *
	 * @param array<string,array<string,mixed>> $fields Fields.
	 * @return array<string,array<string,mixed>>
	 */
	private static function fields_for_js( array $fields ): array {
		$out = array();

		foreach ( $fields as $key => $field ) {
			$row = array(
				'type'  => (string) $field['type'],
				'label' => (string) ( $field['label'] ?? '' ),
			);

			foreach ( array( 'choices', 'def', 'min', 'max', 'when', 'hint', 'group' ) as $carry ) {
				if ( isset( $field[ $carry ] ) ) {
					$row[ $carry ] = $field[ $carry ];
				}
			}

			if ( isset( $field['sub'] ) ) {
				$row['sub'] = self::fields_for_js( (array) $field['sub'] );
			}

			$out[ $key ] = $row;
		}

		return $out;
	}

	/**
	 * Image ids used by sections, resolved to thumbnails for the editor.
	 *
	 * @param array<int,array<string,mixed>> $sections Sections.
	 * @return array<int,string>
	 */
	private static function thumbs( array $sections ): array {
		$ids = array();

		array_walk_recursive(
			$sections,
			static function ( $value, $key ) use ( &$ids ): void {
				if ( in_array( $key, array( 'img', 'imgm', 'bgimg' ), true ) && absint( $value ) > 0 ) {
					$ids[ absint( $value ) ] = true;
				}
			}
		);

		$out = array();

		foreach ( array_keys( $ids ) as $id ) {
			$url = wp_get_attachment_image_url( $id, 'thumbnail' );

			if ( $url ) {
				$out[ $id ] = (string) $url;
			}
		}

		return $out;
	}

	/**
	 * Names for picked ids (products, posts, categories), for the chips.
	 *
	 * @param array<int,array<string,mixed>> $sections Sections.
	 * @return array<int,string>
	 */
	private static function names( array $sections ): array {
		$out = array();

		foreach ( $sections as $section ) {
			foreach ( array( 'picks' ) as $key ) {
				foreach ( (array) ( $section[ $key ] ?? array() ) as $id ) {
					$title = get_the_title( absint( $id ) );

					if ( '' !== $title ) {
						$out[ absint( $id ) ] = $title;
					}
				}
			}

			if ( isset( $section['cat'] ) && absint( $section['cat'] ) > 0 ) {
				$term = get_term( absint( $section['cat'] ), 'product_cat' );

				if ( $term instanceof \WP_Term ) {
					$out[ 'c' . $term->term_id ] = $term->name;
				}
			}
		}

		// The category tree, whole — pickers show it without asking.
		$cats = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
			)
		);

		$tree = array();

		if ( is_array( $cats ) ) {
			foreach ( $cats as $cat ) {
				$tree[] = array(
					'id'     => (int) $cat->term_id,
					'label'  => $cat->name,
					'parent' => (int) $cat->parent,
				);
			}
		}

		return array(
			'ids'  => $out,
			'cats' => $tree,
		);
	}

	/**
	 * The screen: one root node; the script builds everything.
	 */
	public function render_screen(): void {
		echo '<div id="oc-blocks-root"></div>';
	}

	/*
	 * ------------------------------------------------------------- ajax
	 */

	/**
	 * Keep the sections.
	 */
	public function ajax_save(): void {
		check_ajax_referer( 'oc_blocks', 'nonce' );

		$page_id = isset( $_POST['page'] ) ? absint( $_POST['page'] ) : 0;

		if ( ! $page_id || ! current_user_can( 'edit_page', $page_id ) ) {
			wp_send_json_error( array(), 403 );
		}

		$raw  = isset( $_POST['sections'] ) ? (string) wp_unslash( $_POST['sections'] ) : '[]'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- decoded and cleaned below.
		$list = json_decode( $raw, true );
		$list = is_array( $list ) ? Registry::clean( $list ) : array();

		if ( empty( $list ) ) {
			delete_post_meta( $page_id, Registry::META );
		} else {
			update_post_meta( $page_id, Registry::META, $list );
		}

		update_option( 'oc_blocks_ver', (int) get_option( 'oc_blocks_ver', 0 ) + 1, false );

		wp_send_json_success( array( 'sections' => $list ) );
	}

	/**
	 * Keep a draft for the preview frame.
	 */
	public function ajax_draft(): void {
		check_ajax_referer( 'oc_blocks', 'nonce' );

		if ( ! current_user_can( 'edit_pages' ) ) {
			wp_send_json_error( array(), 403 );
		}

		$raw  = isset( $_POST['sections'] ) ? (string) wp_unslash( $_POST['sections'] ) : '[]'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- decoded and cleaned on render.
		$list = json_decode( $raw, true );
		$key  = substr( md5( (string) get_current_user_id() . wp_salt() ), 0, 12 );

		set_transient( 'oc_compose_draft_' . $key, is_array( $list ) ? $list : array(), HOUR_IN_SECONDS );

		wp_send_json_success( array( 'draft' => $key ) );
	}

	/**
	 * Search products or posts for the pickers.
	 */
	public function ajax_search(): void {
		check_ajax_referer( 'oc_blocks', 'nonce' );

		if ( ! current_user_can( 'edit_pages' ) ) {
			wp_send_json_error( array(), 403 );
		}

		$kind = isset( $_POST['kind'] ) ? sanitize_key( $_POST['kind'] ) : 'product';
		$term = isset( $_POST['q'] ) ? sanitize_text_field( wp_unslash( $_POST['q'] ) ) : '';

		$query = new \WP_Query(
			array(
				'post_type'      => 'posts' === $kind ? 'post' : 'product',
				'post_status'    => 'publish',
				's'              => $term,
				'posts_per_page' => 10,
				'no_found_rows'  => true,
			)
		);

		$hits = array();

		foreach ( $query->posts as $post ) {
			$hits[] = array(
				'id'    => (int) $post->ID,
				'label' => get_the_title( $post ),
			);
		}

		wp_send_json_success( array( 'hits' => $hits ) );
	}
}
