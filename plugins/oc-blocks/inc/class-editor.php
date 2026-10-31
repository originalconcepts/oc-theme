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
		add_filter( 'post_row_actions', array( $this, 'row_action' ), 10, 2 );
		add_action( 'add_meta_boxes', array( $this, 'meta_box' ) );
		add_action( 'admin_bar_menu', array( $this, 'admin_bar' ), 81 );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );

		// Pages composed before sections carried identities get them here,
		// a batch per admin request, until none are left.
		add_action( 'admin_init', array( Registry::class, 'sweep' ) );

		add_action( 'wp_ajax_oc_blocks_save', array( $this, 'ajax_save' ) );
		add_action( 'wp_ajax_oc_blocks_draft', array( $this, 'ajax_draft' ) );
		add_action( 'wp_ajax_oc_blocks_search', array( $this, 'ajax_search' ) );
		add_action( 'wp_ajax_oc_blocks_cat_image', array( $this, 'ajax_cat_image' ) );
	}

	/**
	 * A hidden admin page carries the editor (linked, not in the menu).
	 */
	public function screen(): void {
		add_submenu_page(
			'',
			__( 'OC Blocks', 'oc-blocks' ),
			__( 'OC Blocks', 'oc-blocks' ),
			'edit_posts',
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
		if ( in_array( $post->post_type, Render::composable_types(), true ) && current_user_can( 'edit_post', $post->ID ) ) {
			$actions['oc_blocks'] = '<a href="' . esc_url( self::url( (int) $post->ID ) ) . '">' . esc_html__( 'Edit with OC Blocks', 'oc-blocks' ) . '</a>';
		}

		return $actions;
	}

	/**
	 * The door from the black bar: viewing a composable page on the front
	 * end puts the composer one click away, next to WordPress's own Edit.
	 *
	 * @param \WP_Admin_Bar $bar Admin bar.
	 */
	public function admin_bar( $bar ): void {
		if ( is_admin() || ! is_singular( Render::composable_types() ) ) {
			return;
		}

		$post_id = (int) get_queried_object_id();

		if ( $post_id < 1 || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$bar->add_node(
			array(
				'id'    => 'oc-blocks-edit',
				'title' => __( 'Edit with OC Blocks', 'oc-blocks' ),
				'href'  => self::url( $post_id ),
			)
		);
	}

	/**
	 * The door from the edit screen of any composable post type.
	 *
	 * @param string $post_type Current edit screen's post type.
	 */
	public function meta_box( $post_type ): void {
		if ( ! in_array( (string) $post_type, Render::composable_types(), true ) ) {
			return;
		}

		add_meta_box(
			'oc-blocks-door',
			__( 'OC Blocks', 'oc-blocks' ),
			static function ( \WP_Post $post ): void {
				$built = Render::is_composed( (int) $post->ID );

				echo '<p style="margin-block-start:0;">'
					. esc_html( $built ? __( 'This is built with the composer; its sections replace the content below.', 'oc-blocks' ) : __( 'Build this from ready-made sections, with a live preview.', 'oc-blocks' ) )
					. '</p>';
				echo '<a class="button button-primary" style="width:100%;text-align:center;" href="' . esc_url( self::url( (int) $post->ID ) ) . '">'
					. esc_html__( 'Edit with OC Blocks', 'oc-blocks' )
					. '</a>';
			},
			$post_type,
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
		$css = oc_blocks_asset( 'assets/editor.css' );
		$js  = oc_blocks_asset( 'assets/editor.js' );
		wp_enqueue_style( 'oc-blocks-editor', OC_BLOCKS_URI . $css, array(), (string) filemtime( OC_BLOCKS_DIR . $css ) );
		wp_enqueue_script( 'oc-blocks-editor', OC_BLOCKS_URI . $js, array( 'jquery' ), (string) filemtime( OC_BLOCKS_DIR . $js ), true );

		// The composer edits what is stored, never what the front end shows:
		// a plugin rewriting sections on the way out (a translation) must
		// not be what the editor hands back on save.
		$sections = $page_id ? Registry::stored( $page_id ) : array();

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
				'sections' => $sections,
				'thumbs'   => $page_id ? self::thumbs( $sections ) : array(),
				'names'    => $page_id ? self::names( $sections ) : array(),
				'hostNote' => $page_id ? Host::notice( $page_id ) : array(),
				'col'      => $page_id ? Host::col( $page_id ) : array(),
				'i18n'     => array(
					'save'         => __( 'Save', 'oc-blocks' ),
					'saved'        => __( 'Saved', 'oc-blocks' ),
					'saving'       => __( 'Saving…', 'oc-blocks' ),
					'failed'       => __( 'Could not save', 'oc-blocks' ),
					'add'          => __( 'Add a section', 'oc-blocks' ),
					'empty'        => __( 'The page is empty. Add your first section:', 'oc-blocks' ),
					'devD'         => __( 'Desktop', 'oc-blocks' ),
					'devM'         => __( 'Mobile', 'oc-blocks' ),
					'noBrands'     => __( 'This shop has no brands yet.', 'oc-blocks' ),
					'brandsAll'    => __( 'Nothing ticked — every brand with a product is shown.', 'oc-blocks' ),
					/* translators: %d: how many brands are ticked. */
					'brandsPicked' => __( '%d chosen, in the order they were ticked.', 'oc-blocks' ),
					'select'       => __( 'Pick a section from the list to edit it.', 'oc-blocks' ),
					'sections'     => __( 'Sections', 'oc-blocks' ),
					'content'      => __( 'Content', 'oc-blocks' ),
					'design'       => __( 'Design', 'oc-blocks' ),
					'shellttl'     => __( 'Frame', 'oc-blocks' ),
					'remove'       => __( 'Remove', 'oc-blocks' ),
					'dup'          => __( 'Duplicate', 'oc-blocks' ),
					'confirm'      => __( 'Remove this section?', 'oc-blocks' ),
					'pick'         => __( 'Choose', 'oc-blocks' ),
					'change'       => __( 'Change', 'oc-blocks' ),
					'clear'        => __( 'Remove', 'oc-blocks' ),
					'addSlide'     => __( 'Add a slide', 'oc-blocks' ),
					'slide'        => __( 'Slide card', 'oc-blocks' ),
					'search'       => __( 'Type to search…', 'oc-blocks' ),
					'view'         => __( 'View page', 'oc-blocks' ),
					'unsaved'      => __( 'There are unsaved changes.', 'oc-blocks' ),
					'leave'        => __( 'There are unsaved changes — they will be lost. Change the layout anyway?', 'oc-blocks' ),
					'media'        => __( 'Choose a picture', 'oc-blocks' ),
					'noimg'        => __( 'Choose the picture first, then drop spots on it.', 'oc-blocks' ),
					'addspot'      => __( '+ Add a product', 'oc-blocks' ),
					'focus'        => __( 'Hide the side panels', 'oc-blocks' ),
					'spothint'     => __( 'You can also click the picture to place a spot exactly where you want it.', 'oc-blocks' ),
					'video'        => __( 'Choose a video', 'oc-blocks' ),
					'filterCats'   => __( 'Find a category…', 'oc-blocks' ),
					'rename'       => __( 'Its name on this block (optional)', 'oc-blocks' ),
					'catPicAdd'    => __( 'Add a picture to this category', 'oc-blocks' ),
					'catPicChange' => __( 'Change the category picture', 'oc-blocks' ),
					'up'           => __( 'Move up', 'oc-blocks' ),
					'down'         => __( 'Move down', 'oc-blocks' ),
					'linkFind'     => __( 'Type a category, product or page name — or paste an address', 'oc-blocks' ),
					'noHits'       => __( 'Nothing found.', 'oc-blocks' ),
					'productSwap'  => __( 'Type to choose another product…', 'oc-blocks' ),
					'buy'          => __( 'Buy now', 'oc-blocks' ),
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

			foreach ( array( 'choices', 'def', 'min', 'max', 'when', 'hint', 'group', 'dev', 'half', 'row', 'add' ) as $carry ) {
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
				if ( in_array( $key, array( 'img', 'imgm', 'bgimg', 'img1', 'img2' ), true ) && absint( $value ) > 0 ) {
					$ids[ absint( $value ) ] = true;
				}
			}
		);

		$out = array();

		foreach ( array_keys( $ids ) as $id ) {
			$url = wp_get_attachment_image_url( $id, 'large' );

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
	 * @return array{ids: array<int|string, string>, cats: array<int, array{id: int, label: string, parent: int}>, brands: array<int, array{id: int, label: string, count: int}>} Picked-id names keyed by id (categories under 'c' . id), the whole category tree, and every brand.
	 */
	private static function names( array $sections ): array {
		$out = array();

		foreach ( $sections as $section ) {
			foreach ( (array) ( $section['spots'] ?? array() ) as $spot ) {
				$title = get_the_title( absint( $spot['id'] ?? 0 ) );

				if ( '' !== $title ) {
					$out[ absint( $spot['id'] ) ] = $title;
				}
			}

			// Shop-the-look rooms carry their spots a level deeper — without
			// this walk a saved room reopened in the editor showed bare rows.
			foreach ( (array) ( $section['scenes'] ?? array() ) as $scene ) {
				foreach ( (array) ( $scene['spots'] ?? array() ) as $spot ) {
					$title = get_the_title( absint( $spot['id'] ?? 0 ) );

					if ( '' !== $title ) {
						$out[ absint( $spot['id'] ) ] = $title;
					}
				}
			}

			// A slide built on a product names it in its picker.
			foreach ( (array) ( $section['slides'] ?? array() ) as $slide ) {
				$pid = absint( is_array( $slide ) ? ( $slide['product'] ?? 0 ) : 0 );

				if ( $pid > 0 && '' !== get_the_title( $pid ) ) {
					$out[ $pid ] = get_the_title( $pid );
				}
			}

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
				// The picture the tile shows today, so the chosen list can
				// show it and offer to change it.
				$pic    = Render::cat_image_id( (int) $cat->term_id );
				$tree[] = array(
					'id'     => (int) $cat->term_id,
					'label'  => $cat->name,
					'parent' => (int) $cat->parent,
					'img'    => $pic > 0 ? (string) wp_get_attachment_image_url( $pic, 'thumbnail' ) : '',
				);
			}
		}

		// Every brand the shop has, for the picker on the brands block.
		$brands = array();
		$btax   = Render::brand_taxonomy();

		if ( '' !== $btax ) {
			$terms = get_terms(
				array(
					'taxonomy'   => $btax,
					'hide_empty' => false,
					'orderby'    => 'name',
				)
			);

			if ( is_array( $terms ) ) {
				foreach ( $terms as $term ) {
					$brands[] = array(
						'id'    => (int) $term->term_id,
						'label' => $term->name,
						'count' => (int) $term->count,
					);
				}
			}
		}

		return array(
			'ids'    => $out,
			'cats'   => $tree,
			'brands' => $brands,
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

		if ( ! $page_id || ! current_user_can( 'edit_post', $page_id ) ) {
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

		/**
		 * The sections of a composed page were saved. A translation plugin
		 * listens, to translate what is new on the page.
		 *
		 * @param int   $page_id The page.
		 * @param array $list    Its sections, cleaned.
		 */
		do_action( 'oc_blocks_saved', $page_id, $list );

		wp_send_json_success( array( 'sections' => $list ) );
	}

	/**
	 * Keep a draft for the preview frame.
	 */
	public function ajax_draft(): void {
		check_ajax_referer( 'oc_blocks', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
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

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array(), 403 );
		}

		$kind  = isset( $_POST['kind'] ) ? sanitize_key( $_POST['kind'] ) : 'product';
		$term  = isset( $_POST['q'] ) ? sanitize_text_field( wp_unslash( $_POST['q'] ) ) : '';
		$ptype = isset( $_POST['ptype'] ) ? sanitize_key( $_POST['ptype'] ) : 'post';
		$ptype = isset( Registry::post_types()[ $ptype ] ) ? $ptype : 'post';

		if ( 'link' === $kind ) {
			wp_send_json_success( array( 'hits' => self::link_hits( $term ) ) );
		}

		$query = new \WP_Query(
			array(
				'post_type'      => 'posts' === $kind ? $ptype : 'product',
				'post_status'    => 'publish',
				's'              => $term,
				'posts_per_page' => 10,
				'no_found_rows'  => true,
			)
		);

		$hits = array();

		foreach ( $query->posts as $post ) {
			$hit = array(
				'id'    => (int) $post->ID,
				'label' => get_the_title( $post ),
				'img'   => (string) get_the_post_thumbnail_url( $post, 'thumbnail' ),
			);

			// What a slide built on this product copies in: its picture,
			// its short description and its address.
			if ( 'product' === $kind ) {
				$pic = (int) get_post_thumbnail_id( $post );

				$hit['pic']    = $pic;
				$hit['picUrl'] = $pic > 0 ? (string) wp_get_attachment_image_url( $pic, 'large' ) : '';
				$hit['text']   = trim( wp_strip_all_tags( (string) $post->post_excerpt ) );
				$hit['url']    = (string) get_permalink( $post );
			}

			$hits[] = $hit;
		}

		wp_send_json_success( array( 'hits' => $hits ) );
	}

	/**
	 * Places a link may lead to, for the address fields: categories first
	 * (a banner most often opens an aisle), then products, then pages.
	 *
	 * @param string $term What was typed.
	 * @return array<int,array{label:string,url:string,kind:string,img:string}>
	 */
	private static function link_hits( string $term ): array {
		$hits = array();

		if ( '' === $term ) {
			return $hits;
		}

		$cats = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'name__like' => $term,
				'number'     => 6,
			)
		);

		foreach ( is_array( $cats ) ? $cats : array() as $cat ) {
			$url = get_term_link( $cat );
			$pic = Render::cat_image_id( (int) $cat->term_id );

			if ( ! is_wp_error( $url ) ) {
				$hits[] = array(
					'label' => $cat->name,
					'url'   => (string) $url,
					'kind'  => __( 'Category', 'oc-blocks' ),
					'img'   => $pic > 0 ? (string) wp_get_attachment_image_url( $pic, 'thumbnail' ) : '',
				);
			}
		}

		foreach ( array(
			'product' => __( 'Product', 'oc-blocks' ),
			'page'    => __( 'Page', 'oc-blocks' ),
		) as $type => $label ) {
			if ( ! post_type_exists( $type ) ) {
				continue;
			}

			$query = new \WP_Query(
				array(
					'post_type'      => $type,
					'post_status'    => 'publish',
					's'              => $term,
					'posts_per_page' => 'product' === $type ? 8 : 4,
					'no_found_rows'  => true,
				)
			);

			foreach ( $query->posts as $post ) {
				$hits[] = array(
					'label' => get_the_title( $post ),
					'url'   => (string) get_permalink( $post ),
					'kind'  => $label,
					'img'   => (string) get_the_post_thumbnail_url( $post, 'thumbnail' ),
				);
			}
		}

		return $hits;
	}

	/**
	 * A picture chosen for a category in the composer is the category's own:
	 * saved on the category (the OC theme's card image, or the WooCommerce
	 * category image — Deliz's main picture), so its edit screen shows it too
	 * and every place that shows the category shows the same picture.
	 */
	public function ajax_cat_image(): void {
		check_ajax_referer( 'oc_blocks', 'nonce' );

		$term = isset( $_POST['term'] ) ? absint( $_POST['term'] ) : 0;
		$pic  = isset( $_POST['pic'] ) ? absint( $_POST['pic'] ) : 0;

		if ( ! $term || ! get_term( $term, 'product_cat' ) instanceof \WP_Term || ! current_user_can( 'edit_term', $term ) ) {
			wp_send_json_error( array(), 403 );
		}

		if ( $pic > 0 && ! wp_attachment_is_image( $pic ) ) {
			wp_send_json_error( array(), 400 );
		}

		if ( $pic > 0 ) {
			update_term_meta( $term, Render::cat_image_key(), $pic );
		} else {
			delete_term_meta( $term, Render::cat_image_key() );
		}

		// The tiles are cached markup; a new picture is a new generation.
		update_option( 'oc_blocks_ver', (int) get_option( 'oc_blocks_ver', 0 ) + 1, false );

		$now = Render::cat_image_id( $term );

		wp_send_json_success( array( 'img' => $now > 0 ? (string) wp_get_attachment_image_url( $now, 'thumbnail' ) : '' ) );
	}
}
