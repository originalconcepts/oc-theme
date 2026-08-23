<?php
/**
 * What opens underneath a menu link.
 *
 * A panel belongs to one top-level item and is a row of blocks. There is no
 * "make this a mega menu" switch: an item with blocks gets a panel, an item
 * with children and no blocks gets the plain drop-down, and an item with
 * neither is a link. One rule, nothing to remember.
 *
 * Every block type declares its own fields once, and that declaration drives
 * three things at once — the editor form, the sanitiser, and the renderer.
 * Adding a type means adding a description of it, not editing three files.
 *
 * @package OC_Theme
 */

declare( strict_types = 1 );

namespace OC\Theme;

defined( 'ABSPATH' ) || exit;

/**
 * Mega panels.
 */
final class Menu_Panel {

	/**
	 * Meta key holding one item's blocks.
	 */
	public const META = '_oc_panel';

	/**
	 * How many blocks one panel may hold.
	 *
	 * Not a technical limit. A panel with twelve blocks is a page, and nobody
	 * finds anything in it — the cap is there so the default is a menu.
	 */
	public const MAX = 6;

	/**
	 * Column widths a block may take, and their share of the row.
	 *
	 * @return array<string,array{label:string,fr:float}>
	 */
	public static function widths(): array {
		return array(
			'auto'   => array(
				'label' => __( 'As wide as it needs', 'oc-theme' ),
				'track' => 'max-content',
			),
			'narrow' => array(
				'label' => __( 'Narrow', 'oc-theme' ),
				'track' => '0.8fr',
			),
			'normal' => array(
				'label' => __( 'Regular', 'oc-theme' ),
				'track' => '1.2fr',
			),
			'wide'   => array(
				'label' => __( 'Wide', 'oc-theme' ),
				'track' => '1.8fr',
			),
			'double' => array(
				'label' => __( 'Double', 'oc-theme' ),
				'track' => '2.6fr',
			),
		);
	}

	/**
	 * Where a block is shown.
	 *
	 * @return array<string,string>
	 */
	public static function devices(): array {
		return array(
			'both'    => __( 'Everywhere', 'oc-theme' ),
			'desktop' => __( 'Desktop only', 'oc-theme' ),
			'mobile'  => __( 'Drawer only', 'oc-theme' ),
		);
	}

	/**
	 * The block types, and the fields each one owns.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function types(): array {
		$types = array(
			'links' => array(
				'label'  => __( 'Links column', 'oc-theme' ),
				'blurb'  => __( 'A heading and a list under it', 'oc-theme' ),
				'icon'   => '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="4" width="12" height="3" rx="1.5"/><rect x="4" y="10" width="16" height="2" rx="1" opacity=".5"/><rect x="4" y="15" width="13" height="2" rx="1" opacity=".5"/><rect x="4" y="20" width="15" height="2" rx="1" opacity=".5"/></svg>',
				'fields' => array(
					'title'     => array(
						'type'  => 'text',
						'label' => __( 'Heading', 'oc-theme' ),
					),
					'title_url' => array(
						'type'  => 'url',
						'label' => __( 'The heading links to', 'oc-theme' ),
						'hint'  => __( 'Leave empty and the heading is plain text.', 'oc-theme' ),
					),
					'rows'      => array(
						'type'  => 'rows',
						'label' => __( 'Links', 'oc-theme' ),
					),
				),
			),
			'menu'  => array(
				'label'  => __( 'Menu', 'oc-theme' ),
				'blurb'  => __( 'Categories, straight from the shop', 'oc-theme' ),
				'icon'   => '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="3" width="13" height="3" rx="1.5"/><rect x="4" y="9" width="16" height="2" rx="1" opacity=".5"/><rect x="4" y="14" width="11" height="3" rx="1.5"/><rect x="4" y="20" width="15" height="2" rx="1" opacity=".5"/></svg>',
				'fields' => array(
					'groups' => array(
						'type'  => 'groups',
						'label' => __( 'What it lists', 'oc-theme' ),
						'hint'  => __( 'Each line is one category. Show its children and it becomes a heading with a list under it; hide them and it stays one row of the menu.', 'oc-theme' ),
					),
				),
			),
			'image' => array(
				'label'  => __( 'Image', 'oc-theme' ),
				'blurb'  => __( 'A picture, with words on it if you like', 'oc-theme' ),
				'icon'   => '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="4" width="18" height="16" rx="2" opacity=".45"/><circle cx="9" cy="10" r="2"/><path d="M4 18l5-5 4 4 3-3 4 4z"/></svg>',
				'fields' => array(
					'img'     => array(
						'type'  => 'image',
						'label' => __( 'Picture', 'oc-theme' ),
					),
					'url'     => array(
						'type'  => 'url',
						'label' => __( 'Leads to', 'oc-theme' ),
					),
					'heading' => array(
						'type'  => 'text',
						'label' => __( 'Heading on the picture', 'oc-theme' ),
					),
					'text'    => array(
						'type'  => 'text',
						'label' => __( 'A line under it', 'oc-theme' ),
					),
					'cta'     => array(
						'type'  => 'text',
						'label' => __( 'Button words', 'oc-theme' ),
					),
					'ratio'   => array(
						'type'    => 'select',
						'label'   => __( 'Shape', 'oc-theme' ),
						'choices' => array(
							'3/4'  => __( 'Upright', 'oc-theme' ),
							'1/1'  => __( 'Square', 'oc-theme' ),
							'4/3'  => __( 'Landscape', 'oc-theme' ),
							'16/9' => __( 'Wide', 'oc-theme' ),
						),
						'def'     => '3/4',
					),
					'pos'     => array(
						'type'    => 'select',
						'label'   => __( 'The words sit', 'oc-theme' ),
						'choices' => array(
							'bottom' => __( 'At the bottom', 'oc-theme' ),
							'centre' => __( 'In the middle', 'oc-theme' ),
							'top'    => __( 'At the top', 'oc-theme' ),
							'under'  => __( 'Under the picture', 'oc-theme' ),
						),
						'def'     => 'bottom',
					),
					'radius'  => array(
						'type'    => 'select',
						'label'   => __( 'Corners', 'oc-theme' ),
						'choices' => array(
							'sharp' => __( 'Sharp', 'oc-theme' ),
							'soft'  => __( 'Softened', 'oc-theme' ),
							'round' => __( 'Round', 'oc-theme' ),
						),
						'def'     => 'soft',
					),
				),
			),
		);

		/**
		 * Block types a panel may hold.
		 *
		 * @param array<string,array<string,mixed>> $types Types.
		 */
		return (array) apply_filters( 'oc_menu_block_types', $types );
	}

	/*
	 * Storage.
	 */

	/**
	 * One item's blocks, cleaned.
	 *
	 * @param int $item_id Menu item id.
	 * @return array<int,array<string,mixed>>
	 */
	public static function blocks( int $item_id ): array {
		$raw = get_post_meta( $item_id, self::META, true );

		return is_array( $raw ) ? self::clean( $raw ) : array();
	}

	/**
	 * Store one item's blocks.
	 *
	 * @param int                            $item_id Menu item id.
	 * @param array<int,array<string,mixed>> $blocks  Blocks.
	 */
	public static function save( int $item_id, array $blocks ): void {
		$blocks = self::clean( $blocks );

		if ( empty( $blocks ) ) {
			delete_post_meta( $item_id, self::META );
		} else {
			update_post_meta( $item_id, self::META, $blocks );
		}

		self::flush();
	}

	/**
	 * Bring a stored or posted structure back to something the renderer can
	 * trust: known types, known widths, known devices, and nothing else.
	 *
	 * @param array<int|string,mixed> $raw Blocks.
	 * @return array<int,array<string,mixed>>
	 */
	public static function clean( array $raw ): array {
		$types   = self::types();
		$widths  = self::widths();
		$devices = self::devices();
		$out     = array();

		foreach ( $raw as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}

			$type = isset( $block['type'] ) ? (string) $block['type'] : '';

			if ( ! isset( $types[ $type ] ) ) {
				continue;
			}

			$clean = array(
				'type' => $type,
				'w'    => isset( $block['w'], $widths[ $block['w'] ] ) ? (string) $block['w'] : 'normal',
				'dev'  => isset( $block['dev'], $devices[ $block['dev'] ] ) ? (string) $block['dev'] : 'both',
				'push' => empty( $block['push'] ) ? 0 : 1,
			);

			foreach ( $types[ $type ]['fields'] as $key => $field ) {
				$clean[ $key ] = self::clean_field( $field, $block[ $key ] ?? null );
			}

			$out[] = $clean;

			if ( count( $out ) >= self::MAX ) {
				break;
			}
		}

		return $out;
	}

	/**
	 * One field, cleaned according to what it says it is.
	 *
	 * @param array<string,mixed> $field Field description.
	 * @param mixed               $value Raw value.
	 * @return mixed
	 */
	private static function clean_field( array $field, $value ) {
		switch ( (string) $field['type'] ) {
			case 'image':
				return absint( $value );

			case 'url':
				return esc_url_raw( trim( (string) $value ) );

			case 'select':
				$choices = (array) ( $field['choices'] ?? array() );
				$def     = (string) ( $field['def'] ?? (string) array_key_first( $choices ) );

				return isset( $choices[ (string) $value ] ) ? (string) $value : $def;

			case 'groups':
				$groups = array();

				foreach ( (array) $value as $group ) {
					if ( ! is_array( $group ) ) {
						continue;
					}

					$term = absint( $group['c'] ?? 0 );

					if ( $term < 1 ) {
						continue;
					}

					$groups[] = array(
						'c'   => $term,
						'sub' => empty( $group['sub'] ) ? 0 : 1,
					);

					if ( count( $groups ) >= 20 ) {
						break;
					}
				}

				return $groups;

			case 'rows':
				$rows = array();

				foreach ( (array) $value as $row ) {
					if ( ! is_array( $row ) ) {
						continue;
					}

					$text = sanitize_text_field( (string) ( $row['t'] ?? '' ) );
					$url  = esc_url_raw( trim( (string) ( $row['u'] ?? '' ) ) );

					// A row with no words is a row nobody can click.
					if ( '' === $text ) {
						continue;
					}

					$rows[] = array(
						't' => $text,
						'u' => $url,
						'b' => sanitize_text_field( (string) ( $row['b'] ?? '' ) ),
					);

					if ( count( $rows ) >= 40 ) {
						break;
					}
				}

				return $rows;

			default:
				return sanitize_text_field( (string) $value );
		}
	}

	/*
	 * Rendering.
	 */

	/**
	 * The rendered panel for one item, from cache when it can be.
	 *
	 * The HTML is printed into every page, so it must cost nothing to have.
	 * Rendering happens once per change, not once per visitor.
	 *
	 * @param int    $item_id Menu item id.
	 * @param string $where   Either 'nav' or 'drawer'.
	 * @return string
	 */
	public static function html( int $item_id, string $where = 'nav' ): string {
		$key    = 'oc_mpanel_' . $item_id . '_' . $where . '_' . self::version();
		$cached = get_transient( $key );

		if ( is_string( $cached ) ) {
			return $cached;
		}

		$html = self::render( self::blocks( $item_id ), $where );

		set_transient( $key, $html, DAY_IN_SECONDS );

		return $html;
	}

	/**
	 * Render a set of blocks. Public so the editor's preview can render what
	 * is on screen rather than what was last saved.
	 *
	 * @param array<int,array<string,mixed>> $blocks Blocks.
	 * @param string                         $where  Either 'nav' or 'drawer'.
	 * @return string
	 */
	public static function render( array $blocks, string $where = 'nav' ): string {
		$widths = self::widths();
		$tracks = array();
		$body   = '';

		foreach ( $blocks as $block ) {
			// A block says where it belongs, and the drawer is not the phone:
			// the desktop hamburger shows it on a wide screen. Deciding here
			// rather than with a breakpoint is the only way to tell the two
			// apart.
			if ( 'drawer' === $where ? 'desktop' === $block['dev'] : 'mobile' === $block['dev'] ) {
				continue;
			}

			$piece = self::block( $block );

			if ( '' === $piece ) {
				continue;
			}

			// A block can ask for the leftover width to be gathered in front
			// of it. That is what puts two columns of links at one edge and
			// two pictures at the other, instead of four things sharing the
			// row equally — which is all a grid will do on its own.
			if ( ! empty( $block['push'] ) && '' !== $body ) {
				$tracks[] = '1fr';
				$body    .= '<div class="oc-mb oc-mb--gap" aria-hidden="true"></div>';
			}

			$tracks[] = (string) $widths[ $block['w'] ]['track'];
			$body    .= $piece;
		}

		if ( '' === $body ) {
			return '';
		}

		if ( 'drawer' === $where ) {
			return '<div class="oc-mega oc-mega--drawer">' . $body . '</div>';
		}

		$class = 'oc-mega oc-mega--' . sanitize_html_class( (string) get_theme_mod( 'oc_mega_width', 'content' ) );

		return '<div class="' . esc_attr( $class ) . '"><div class="oc-mega__row" style="--oc-mega-cols:' . esc_attr( implode( ' ', $tracks ) ) . '">' . $body . '</div></div>';
	}

	/**
	 * One block.
	 *
	 * @param array<string,mixed> $block Block.
	 * @return string
	 */
	private static function block( array $block ): string {
		$type = (string) $block['type'];
		$dev  = (string) $block['dev'];

		unset( $dev );

		$classes = array( 'oc-mb', 'oc-mb--' . $type );

		$inner = '';

		switch ( $type ) {
			case 'links':
				$inner = self::links_block( $block );
				break;
			case 'menu':
				$inner = self::menu_block( $block );
				break;
			case 'image':
				$inner = self::image_block( $block );
				break;
			default:
				/**
				 * Markup for a block type this class does not know.
				 *
				 * @param string              $inner Markup.
				 * @param array<string,mixed> $block Block.
				 */
				$inner = (string) apply_filters( 'oc_menu_block_html', '', $block );
		}

		if ( '' === trim( $inner ) ) {
			return '';
		}

		return '<div class="' . esc_attr( implode( ' ', $classes ) ) . '">' . $inner . '</div>';
	}

	/**
	 * A heading and the links under it.
	 *
	 * @param array<string,mixed> $block Block.
	 * @return string
	 */
	private static function links_block( array $block ): string {
		$title = (string) ( $block['title'] ?? '' );
		$url   = (string) ( $block['title_url'] ?? '' );
		$rows  = (array) ( $block['rows'] ?? array() );
		$out   = '';

		if ( '' !== $title ) {
			$out .= '<h3 class="oc-mb__h">';
			$out .= '' !== $url
				? '<a href="' . esc_url( $url ) . '">' . esc_html( $title ) . '</a>'
				: esc_html( $title );
			$out .= '</h3>';
		}

		if ( ! empty( $rows ) ) {
			$out .= '<ul class="oc-mb__list">';

			foreach ( $rows as $row ) {
				$badge = '' !== (string) $row['b'] ? '<span class="oc-mbadge">' . esc_html( (string) $row['b'] ) . '</span>' : '';

				$out .= '<li>' . ( '' !== (string) $row['u']
					? '<a href="' . esc_url( (string) $row['u'] ) . '">' . esc_html( (string) $row['t'] ) . $badge . '</a>'
					: '<span>' . esc_html( (string) $row['t'] ) . $badge . '</span>' ) . '</li>';
			}

			$out .= '</ul>';
		}

		return $out;
	}

	/**
	 * A column of the shop's own categories.
	 *
	 * A line that shows its children becomes a heading with a list under it;
	 * a line that hides them stays one row of the menu. Consecutive plain
	 * rows gather into one list, because that is what makes them read as a
	 * menu rather than as a pile of separate things.
	 *
	 * @param array<string,mixed> $block Block.
	 * @return string
	 */
	private static function menu_block( array $block ): string {
		$groups = (array) ( $block['groups'] ?? array() );
		$out    = '';
		$run    = '';

		$flush = static function () use ( &$run, &$out ): void {
			if ( '' !== $run ) {
				$out .= '<ul class="oc-mb__list oc-mb__list--menu">' . $run . '</ul>';
				$run  = '';
			}
		};

		foreach ( $groups as $group ) {
			$term = get_term( (int) $group['c'], 'product_cat' );

			if ( ! $term instanceof \WP_Term ) {
				continue;
			}

			$link = (string) get_term_link( $term );
			$name = esc_html( $term->name );

			if ( empty( $group['sub'] ) ) {
				$run .= '<li><a href="' . esc_url( $link ) . '">' . $name . '</a></li>';
				continue;
			}

			$kids = get_terms(
				array(
					'taxonomy'   => 'product_cat',
					'parent'     => (int) $term->term_id,
					'hide_empty' => false,
				)
			);

			// Nothing under it after all — then it is simply a row like the
			// others, rather than a heading with an empty space beneath.
			if ( ! is_array( $kids ) || empty( $kids ) ) {
				$run .= '<li><a href="' . esc_url( $link ) . '">' . $name . '</a></li>';
				continue;
			}

			$flush();

			$out .= '<h4 class="oc-mb__g"><a href="' . esc_url( $link ) . '">' . $name . '</a></h4>';
			$out .= '<ul class="oc-mb__list">';

			foreach ( $kids as $kid ) {
				$out .= '<li><a href="' . esc_url( (string) get_term_link( $kid ) ) . '">' . esc_html( $kid->name ) . '</a></li>';
			}

			$out .= '</ul>';
		}

		$flush();

		return $out;
	}

	/**
	 * A picture, and whatever words belong on it.
	 *
	 * @param array<string,mixed> $block Block.
	 * @return string
	 */
	private static function image_block( array $block ): string {
		$id = (int) ( $block['img'] ?? 0 );

		// The picker only offers images, so this can only happen when the
		// attachment is replaced or deleted later. A block with a heading and
		// a caption floating over nothing looks like a broken page; no block
		// at all looks like a decision.
		if ( $id < 1 || ! wp_attachment_is_image( $id ) ) {
			return '';
		}

		$ratio  = (string) ( $block['ratio'] ?? '3/4' );
		$pos    = (string) ( $block['pos'] ?? 'bottom' );
		$radius = (string) ( $block['radius'] ?? 'soft' );
		$url    = (string) ( $block['url'] ?? '' );

		$img = wp_get_attachment_image(
			$id,
			'medium_large',
			false,
			array(
				'class'   => 'oc-mb__img',
				'loading' => 'lazy',
				'alt'     => '',
			)
		);

		$words = '';

		foreach ( array( 'heading' => 'h4', 'text' => 'p', 'cta' => 'span' ) as $key => $tag ) {
			$value = (string) ( $block[ $key ] ?? '' );

			if ( '' === $value ) {
				continue;
			}

			$words .= '<' . $tag . ' class="oc-mb__' . $key . '">' . esc_html( $value ) . '</' . $tag . '>';
		}

		if ( '' !== $words ) {
			$words = '<div class="oc-mb__words">' . $words . '</div>';
		}

		$inner = '<figure class="oc-mb__fig oc-mb__fig--' . sanitize_html_class( $pos ) . ' oc-mb__fig--r-' . sanitize_html_class( $radius ) . '" style="--oc-mb-ratio:' . esc_attr( $ratio ) . '">' . $img . $words . '</figure>';

		return '' !== $url
			? '<a class="oc-mb__link" href="' . esc_url( $url ) . '">' . $inner . '</a>'
			: $inner;
	}

	/*
	 * Cache.
	 */

	/**
	 * A number that changes whenever any panel does. Keying the transients by
	 * it turns invalidation into one option write, rather than a hunt for
	 * every key that might now be wrong.
	 *
	 * @return int
	 */
	public static function version(): int {
		return (int) get_option( 'oc_menu_panel_ver', 1 );
	}

	/**
	 * Retire every cached panel.
	 */
	public static function flush(): void {
		update_option( 'oc_menu_panel_ver', self::version() + 1, false );
	}
}
