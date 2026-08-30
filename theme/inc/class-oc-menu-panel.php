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
	 * @return array<string,array{label:string,track:string}>
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
			'off'     => __( 'Switched off', 'oc-theme' ),
		);
	}

	/**
	 * The block types, and the fields each one owns.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function types(): array {
		$types = array(
			'products' => array(
				'label'  => __( 'Products', 'oc-theme' ),
				'blurb'  => __( 'A few products, straight from the shop', 'oc-theme' ),
				'icon'   => '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="3" width="8" height="10" rx="1.5" opacity=".5"/><rect x="13" y="3" width="8" height="10" rx="1.5" opacity=".5"/><rect x="3" y="15" width="8" height="2" rx="1"/><rect x="13" y="15" width="8" height="2" rx="1"/><rect x="3" y="19" width="5" height="2" rx="1"/><rect x="13" y="19" width="5" height="2" rx="1"/></svg>',
				'fields' => array(
					'mode'  => array(
						'type'    => 'select',
						'label'   => __( 'Which products', 'oc-theme' ),
						'choices' => array(
							'manual' => __( 'The ones I choose', 'oc-theme' ),
							'sales'  => __( 'Best sellers', 'oc-theme' ),
							'new'    => __( 'Newest', 'oc-theme' ),
							'cat'    => __( 'From a category', 'oc-theme' ),
						),
						'def'     => 'sales',
						'lead'    => true,
					),
					'count' => array(
						'type'  => 'number',
						'label' => __( 'How many', 'oc-theme' ),
						'def'   => 3,
						'min'   => 1,
						'max'   => 6,
						'lead'  => true,
					),
					'picks' => array(
						'type'  => 'products',
						'label' => __( 'The products', 'oc-theme' ),
						'hint'  => __( 'Search by name.', 'oc-theme' ),
						'when'  => array( 'mode' => array( 'manual' ) ),
					),
					'cat'   => array(
						'type'  => 'category',
						'label' => __( 'The category', 'oc-theme' ),
						'when'  => array( 'mode' => array( 'cat' ) ),
					),
				),
			),
			'brands' => array(
				'label'  => __( 'Brands', 'oc-theme' ),
				'blurb'  => __( 'Logos or names, linking to each brand', 'oc-theme' ),
				'icon'   => '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="7" cy="7" r="3.4" opacity=".55"/><circle cx="17" cy="7" r="3.4" opacity=".55"/><circle cx="7" cy="17" r="3.4" opacity=".55"/><circle cx="17" cy="17" r="3.4" opacity=".55"/></svg>',
				'fields' => array(
					'title' => array(
						'type' => 'text',
						'label' => __( 'Heading', 'oc-theme' ),
						'def'   => __( 'By brand', 'oc-theme' ),
						'hint'  => __( 'Leave empty for no heading.', 'oc-theme' ),
					),
					'style' => array(
						'type'    => 'select',
						'label'   => __( 'Shown as', 'oc-theme' ),
						'choices' => array(
							'list' => __( 'A list of names', 'oc-theme' ),
							'logo' => __( 'Logos', 'oc-theme' ),
							'band' => __( 'A band under the whole panel', 'oc-theme' ),
						),
						'def'     => 'list',
					),
					'scope' => array(
						'type'    => 'select',
						'label'   => __( 'Drawn from', 'oc-theme' ),
						'choices' => array(
							'all' => __( 'The whole shop', 'oc-theme' ),
							'cat' => __( 'This item\'s category', 'oc-theme' ),
						),
						'def'     => 'all',
						'hint'    => __( 'On a category item, "this item\'s category" keeps only brands that have products in it.', 'oc-theme' ),
					),
					'terms' => array(
						'type'  => 'terms',
						'label' => __( 'Which brands', 'oc-theme' ),
						'hint'  => __( 'None ticked means all of them.', 'oc-theme' ),
					),
					'count' => array(
						'type'  => 'number',
						'label' => __( 'At most', 'oc-theme' ),
						'def'   => 8,
						'min'   => 1,
						'max'   => 24,
					),
					'link'  => array(
						'type'  => 'url',
						'label' => __( '"All brands" leads to', 'oc-theme' ),
						'hint'  => __( 'Shown when there are more brands than fit. Empty leads to the brands page — or, drawn from a category, to that category.', 'oc-theme' ),
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
							'natural' => __( 'As the picture is', 'oc-theme' ),
							'3/4'     => __( 'Upright', 'oc-theme' ),
							'1/1'     => __( 'Square', 'oc-theme' ),
							'4/3'     => __( 'Landscape', 'oc-theme' ),
							'16/9'    => __( 'Wide', 'oc-theme' ),
						),
						'def'     => 'natural',
					),
					'align'   => array(
						'type'    => 'select',
						'label'   => __( 'The words are', 'oc-theme' ),
						'choices' => array(
							'start'  => __( 'To the side', 'oc-theme' ),
							'centre' => __( 'Centred', 'oc-theme' ),
						),
						'def'     => 'start',
					),
					'focus'   => array(
						'type'  => 'range',
						'label' => __( 'The picture\'s focal point (%)', 'oc-theme' ),
						'def'   => 50,
						'hint'  => __( 'Which part the frame keeps when it has to crop: 0 the top, 50 the middle, 100 the bottom.', 'oc-theme' ),
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

			case 'range':
				return max( 0, min( 100, absint( $value ) ) );

			case 'number':
				$min = (int) ( $field['min'] ?? 0 );
				$max = (int) ( $field['max'] ?? 99 );

				return max( $min, min( $max, (int) ( is_numeric( $value ) ? $value : ( $field['def'] ?? $min ) ) ) );

			case 'category':
				return absint( $value );

			case 'products':
			case 'terms':
				$ids = array();

				foreach ( (array) $value as $one ) {
					$id = absint( is_array( $one ) ? ( $one['id'] ?? 0 ) : $one );

					if ( $id > 0 ) {
						$ids[] = $id;
					}

					if ( count( $ids ) >= 24 ) {
						break;
					}
				}

				return array_values( array_unique( $ids ) );

			case 'select':
				$choices = (array) ( $field['choices'] ?? array() );
				$def     = (string) ( $field['def'] ?? (string) array_key_first( $choices ) );

				return isset( $choices[ (string) $value ] ) ? (string) $value : $def;

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
	 * A panel is its columns plus its extras. The columns are not stored
	 * anywhere — they are the item's own children, read straight from the
	 * menu, so a category renamed or added on the Menus screen is renamed or
	 * added here without anyone remembering to come back.
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

		$html = self::is_panel( $item_id ) ? self::build( $item_id, $where ) : '';

		set_transient( $key, $html, DAY_IN_SECONDS );

		return $html;
	}

	/**
	 * How much a panel would have in it: columns from the menu, extras of
	 * its own.
	 *
	 * @param int $item_id Menu item id.
	 * @return array{columns:int,extras:int}
	 */
	public static function shape( int $item_id ): array {
		return array(
			'columns' => count( self::columns( $item_id, 'nav' ) ),
			'extras'  => count( self::blocks( $item_id ) ),
		);
	}

	/**
	 * Whether this item is worth opening a panel for.
	 *
	 * One list is a drop-down. Anything more than that — a second column, or
	 * a picture — is a panel. Drawing a full-width band to hold a single
	 * short list would be a worse version of the drop-down it replaced.
	 *
	 * @param int $item_id Menu item id.
	 * @return bool
	 */
	public static function is_panel( int $item_id ): bool {
		$shape = self::shape( $item_id );

		return $shape['columns'] > 1 || $shape['extras'] > 0;
	}

	/**
	 * Columns from the menu, extras from the panel, in one row.
	 *
	 * @param int    $item_id Menu item id.
	 * @param string $where   Either 'nav' or 'drawer'.
	 * @param ?array $blocks  Blocks to preview instead of the saved ones.
	 * @return string
	 */
	private static function build( int $item_id, string $where, ?array $blocks = null ): string {
		// The drawer is the hierarchy already: it walks the same children down
		// its own rows. Handing it the columns as well printed every category
		// twice — once as a row you can open, once as a heading you cannot.
		$columns = 'drawer' === $where ? array() : self::columns( $item_id, $where );

		// Spare width only has a side to be gathered on when something is
		// standing at the other one.
		$parts = array_merge(
			$columns,
			self::extras( $item_id, $where, $blocks, ! empty( $columns ) )
		);

		if ( empty( $parts ) ) {
			return '';
		}

		// Bands lie under the row, full width, so they take no track in it.
		// The drawer has no row to lie under — there they stay in the flow.
		$bands = array();

		if ( 'drawer' !== $where ) {
			foreach ( $parts as $at => $part ) {
				if ( ! empty( $part['band'] ) ) {
					$bands[] = $part;
					unset( $parts[ $at ] );
				}
			}
		}

		$tracks = array();
		$body   = '';
		$turn   = 0;

		// Numbered here rather than counted in the stylesheet, because the
		// spacer that gathers loose width is a box and not a piece: counting
		// boxes gave it a turn in the sequence, and whatever came after it
		// waited a beat on something nobody can see.
		foreach ( $parts as $part ) {
			$tracks[] = $part['track'];

			$style = '';

			if ( empty( $part['gap'] ) ) {
				$style = ' style="--i:' . $turn . '"';
				++$turn;
			}

			$body .= '<div class="' . esc_attr( $part['class'] ) . '"' . $style . '>' . $part['inner'] . '</div>';
		}

		if ( 'drawer' === $where ) {
			return '<div class="oc-mega oc-mega--drawer">' . $body . '</div>';
		}

		// How wide the panel opens is not written into it. This markup is
		// cached, and a setting baked into cached markup is a setting that
		// changes nothing until something unrelated happens to expire it —
		// which is exactly how it behaved. The nav carries the width instead,
		// and the nav is rebuilt on every request.
		//
		// Whether there are columns IS written in: a picture measures its
		// height against them, and against nothing when there are none.
		$class = 'oc-mega' . ( empty( $columns ) ? '' : ' oc-mega--cols' );

		$under = '';

		foreach ( $bands as $part ) {
			$under .= '<div class="' . esc_attr( $part['class'] ) . '" style="--i:' . $turn . '">' . $part['inner'] . '</div>';
			++$turn;
		}

		$row = empty( $parts ) ? '' : '<div class="oc-mega__row" style="--oc-mega-cols:' . esc_attr( implode( ' ', $tracks ) ) . '">' . $body . '</div>';

		return '<div class="' . esc_attr( $class ) . '">' . $row . $under . '</div>';
	}

	/**
	 * The panel as it would look with these extras, saved or not. The columns
	 * still come from the menu — they are not the editor's to change.
	 *
	 * @param int                            $item_id Menu item id.
	 * @param array<int,array<string,mixed>> $blocks  Extras.
	 * @return string
	 */
	public static function preview( int $item_id, array $blocks ): string {
		return self::build( $item_id, 'nav', $blocks );
	}

	/**
	 * The menu, by parent, once.
	 *
	 * @return array<int,array<int,\WP_Post>>
	 */
	private static function tree(): array {
		static $tree = null;

		if ( null !== $tree ) {
			return $tree;
		}

		$locations = get_nav_menu_locations();
		$menu      = isset( $locations['primary'] ) ? (int) $locations['primary'] : 0;
		$items     = $menu > 0 ? wp_get_nav_menu_items( $menu ) : array();
		$tree      = array();

		foreach ( (array) $items as $item ) {
			$tree[ (int) $item->menu_item_parent ][] = $item;
		}

		return $tree;
	}

	/**
	 * One item's children, laid out as columns.
	 *
	 * Rank decides weight: a level-two category is bold whether or not it has
	 * anything under it, because it is the same kind of thing either way.
	 *
	 * A category with children takes a column of its own. A category without
	 * joins one shared list — all of them, not merely the ones that happen to
	 * sit next to each other, because a menu written by a person interleaves
	 * them and gathering only neighbours turns ten categories into five
	 * columns, two of them holding a single word. The shared list sits where
	 * the first of its members sits, so dragging that one on the Menus screen
	 * is how you decide whether the list opens the panel or closes it.
	 *
	 * @param int    $item_id Menu item id.
	 * @param string $where   Either 'nav' or 'drawer'.
	 * @return array<int,array{track:string,class:string,inner:string}>
	 */
	private static function columns( int $item_id, string $where ): array {
		$tree     = self::tree();
		$children = $tree[ $item_id ] ?? array();
		$columns  = array();
		$gathered = array();
		$seat     = null;

		foreach ( $children as $child ) {
			$id = (int) $child->ID;

			if ( Menu::hidden( $id, $where ) ) {
				continue;
			}

			$link = '<a href="' . esc_url( (string) $child->url ) . '">' . esc_html( wp_strip_all_tags( (string) $child->title ) ) . '</a>';
			$kids = array();

			foreach ( $tree[ $id ] ?? array() as $grand ) {
				if ( Menu::hidden( (int) $grand->ID, $where ) ) {
					continue;
				}

				$kids[] = '<li><a href="' . esc_url( (string) $grand->url ) . '">' . esc_html( wp_strip_all_tags( (string) $grand->title ) ) . '</a></li>';
			}

			if ( empty( $kids ) ) {
				if ( null === $seat ) {
					$seat = count( $columns );
				}

				$gathered[] = '<li>' . $link . '</li>';
				continue;
			}

			$columns[] = array(
				'track' => 'max-content',
				'class' => 'oc-mb oc-mb--col',
				'inner' => '<h4 class="oc-mb__g">' . $link . '</h4>' . self::lists( $kids ),
			);
		}

		if ( ! empty( $gathered ) ) {
			array_splice(
				$columns,
				(int) $seat,
				0,
				array(
					array(
						'track' => 'max-content',
						'class' => 'oc-mb oc-mb--col',
						'inner' => self::lists( $gathered, 'oc-mb__list--lead' ),
					),
				)
			);
		}

		return $columns;
	}

	/**
	 * Rows as one list — or, past the row limit, as lists standing close
	 * together. The continuation is not a new column, so it does not get the
	 * big inter-column gap; it is the same list taking a breath.
	 *
	 * @param array<int,string> $rows  Row markup.
	 * @param string            $extra Extra class for each list.
	 * @return string
	 */
	private static function lists( array $rows, string $extra = '' ): string {
		$cap   = max( 3, (int) get_theme_mod( 'oc_mega_rows', 8 ) );
		$class = trim( 'oc-mb__list ' . $extra );
		$uls   = array();

		foreach ( array_chunk( $rows, $cap ) as $chunk ) {
			$uls[] = '<ul class="' . esc_attr( $class ) . '">' . implode( '', $chunk ) . '</ul>';
		}

		if ( count( $uls ) === 1 ) {
			return $uls[0];
		}

		return '<div class="oc-mb__cols">' . implode( '', $uls ) . '</div>';
	}

	/**
	 * What the panel adds beyond the menu: pictures, products, the rest.
	 *
	 * The spare width gathers in front of the first of them, which is what
	 * puts the columns at one edge and the pictures at the other instead of
	 * everything sharing the row equally.
	 *
	 * @param int    $item_id Menu item id.
	 * @param string $where   Either 'nav' or 'drawer'.
	 * @param ?array $blocks  Blocks to preview instead of the saved ones.
	 * @param bool   $gap     Whether the spare width gathers before the first extra.
	 * @return array<int,array{track:string,class:string,inner:string,gap?:bool}>
	 */
	private static function extras( int $item_id, string $where, ?array $blocks = null, bool $gap = true ): array {
		$widths = self::widths();
		$side   = array();
		$main   = array();

		foreach ( null === $blocks ? self::blocks( $item_id ) : self::clean( $blocks ) as $block ) {
			if ( 'off' === $block['dev'] ) {
				continue;
			}

			if ( 'drawer' === $where ? 'desktop' === $block['dev'] : 'mobile' === $block['dev'] ) {
				continue;
			}

			$piece = self::block( $block, $item_id, $where );

			if ( null === $piece ) {
				continue;
			}

			$part = array(
				'track' => (string) $widths[ $block['w'] ]['track'],
				'class' => $piece['class'],
				'inner' => $piece['inner'],
				'band'  => ! empty( $piece['band'] ),
			);

			// A list of brands reads like another column, so it sits with
			// the columns at the same rhythm; the visual blocks gather at
			// the far end past the spare width. The drawer is a single
			// file — there, order stays as written.
			if ( 'drawer' !== $where && 'brands' === $block['type'] && 'list' === (string) ( $block['style'] ?? 'list' ) ) {
				$side[] = $part;
			} else {
				$main[] = $part;
			}
		}

		// Spare width only has a side to be gathered on when something is
		// standing at the other one — and a band underneath does not count.
		$loose = array_filter(
			$main,
			static function ( array $part ): bool {
				return empty( $part['band'] );
			}
		);

		if ( $gap && 'drawer' !== $where && ! empty( $loose ) ) {
			$side[] = array(
				'track' => '1fr',
				'class' => 'oc-mb oc-mb--gap',
				'inner' => '',
				'gap'   => true,
			);
		}

		return array_merge( $side, $main );
	}

	/**
	 * One block.
	 *
	 * @param array<string,mixed> $block   Block.
	 * @param int                 $item_id Menu item the block sits under.
	 * @param string              $where   'drawer' or the mega panel.
	 * @return array{class:string,inner:string}|null
	 */
	private static function block( array $block, int $item_id = 0, string $where = '' ): ?array {
		$type = (string) $block['type'];

		$inner = '';

		switch ( $type ) {
			case 'image':
				$inner = self::image_block( $block );
				break;
			case 'products':
				$inner = self::products_block( $block );
				break;
			case 'brands':
				// In the drawer a shelf of brands is out of place: everything
				// else there is a row you press, so a flat run of names reads
				// as text rather than as somewhere to go. It becomes a row of
				// its own, with the arrow and the screen behind it.
				$inner = 'drawer' === $where
					? self::brands_drawer( $block, $item_id )
					: self::brands_block( $block, $item_id );
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
			return null;
		}

		$piece = array(
			'class' => 'oc-mb oc-mb--' . sanitize_html_class( $type ),
			'inner' => $inner,
		);

		// A band is not a column: it leaves the row and lies under it.
		if ( 'brands' === $type && 'band' === (string) ( $block['style'] ?? 'list' ) ) {
			$piece['class'] .= ' oc-mb--band';
			$piece['band']   = true;
		}

		return $piece;
	}

	/**
	 * A few products: picture, name, price, each a door to its page.
	 *
	 * @param array<string,mixed> $block Block.
	 * @return string
	 */
	private static function products_block( array $block ): string {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return '';
		}

		$mode  = (string) ( $block['mode'] ?? 'sales' );
		$count = max( 1, min( 6, (int) ( $block['count'] ?? 3 ) ) );

		$args = array(
			'status'  => 'publish',
			'limit'   => $count,
			'orderby' => 'date',
			'order'   => 'DESC',
		);

		switch ( $mode ) {
			case 'manual':
				$picks = array_slice( (array) ( $block['picks'] ?? array() ), 0, $count );

				if ( empty( $picks ) ) {
					return '';
				}

				$args['include'] = array_map( 'absint', $picks );
				$args['orderby'] = 'post__in';
				break;

			case 'sales':
				$args['orderby'] = 'popularity';
				break;

			case 'cat':
				$term = get_term( absint( $block['cat'] ?? 0 ), 'product_cat' );

				if ( ! $term instanceof \WP_Term ) {
					return '';
				}

				$args['category'] = array( $term->slug );
				break;
		}

		$products = wc_get_products( $args );

		if ( empty( $products ) ) {
			return '';
		}

		// No shape or fit of its own. A product in the menu is the same
		// product the catalogue is showing, so it wears the shape the shop
		// chose for its cards — set that once and the two can never disagree.
		// Blocks saved when this was a per-panel choice keep the value in the
		// database; it is simply not read any more.
		$out = '<div class="oc-mb__prods">';

		// The catalogue size may be server-cropped square; a shape or a
		// whole-picture fit needs the uncropped file.
		$size = '1/1' === $shape && 'cover' === $fit ? 'woocommerce_thumbnail' : 'large';

		foreach ( $products as $product ) {
			$image = $product->get_image( $size, array( 'loading' => 'lazy' ) );

			$out .= '<a class="oc-mb__prod" href="' . esc_url( (string) $product->get_permalink() ) . '">';
			$out .= '<span class="oc-mb__prod-img">' . $image . WooCommerce::flags_html( $product ) . '</span>';
			$out .= '<span class="oc-mb__prod-name">' . esc_html( $product->get_name() ) . '</span>';
			$out .= '<span class="oc-mb__prod-price">' . $product->get_price_html() . '</span>';
			$out .= '</a>';
		}

		return $out . '</div>';
	}

	/**
	 * The brands, as logos or names, each linking to its own shelf. A brand
	 * asked to show a logo it does not have shows its name instead — visible
	 * and correct beats invisible and broken.
	 *
	 * @param array<string,mixed> $block   Block.
	 * @param int                 $item_id Menu item the block sits under.
	 * @return string
	 */
	/**
	 * The same brands, wearing the drawer's own grammar: one row carrying the
	 * name and an arrow, and behind it a screen of brands as ordinary rows,
	 * ruled apart like every other list in there. Each one opens its brand.
	 *
	 * @param array<string,mixed> $block   Block.
	 * @param int                 $item_id Menu item the block sits under.
	 * @return string
	 */
	private static function brands_drawer( array $block, int $item_id = 0 ): string {
		$found = self::brand_terms( $block, $item_id );

		if ( null === $found ) {
			return '';
		}

		$title = trim( (string) ( $block['title'] ?? '' ) );
		$title = '' === $title ? __( 'Brands', 'oc-theme' ) : $title;
		$all   = self::brands_more_url( $block, $found['cat'] );
		$rows  = '';

		foreach ( $found['terms'] as $term ) {
			$rows .= '<li class="oc-drw__i"><div class="oc-drw__row"><a class="oc-drw__a" href="'
				. esc_url( (string) get_term_link( $term ) ) . '">'
				. esc_html( $term->name ) . '</a></div></li>';
		}

		if ( '' === $rows ) {
			return '';
		}

		$head = '<div class="oc-drw__head"><a class="oc-drw__title" href="' . esc_url( $all ) . '">'
			. esc_html( $title ) . '</a>';

		if ( '' !== $all ) {
			$head .= '<a class="oc-drw__all" href="' . esc_url( $all ) . '">' . esc_html__( 'Show all', 'oc-theme' ) . '</a>';
		}

		$head .= '</div>';

		return '<ul class="oc-drw__list oc-drw__list--sub oc-drw__list--brands">'
			. '<li class="oc-drw__i has-more">'
			. '<div class="oc-drw__row">'
			. '<a class="oc-drw__a" href="' . esc_url( $all ) . '">' . esc_html( $title ) . '</a>'
			. '<button type="button" class="oc-drw__more" aria-expanded="false" aria-label="'
			. esc_attr( sprintf( /* translators: %s: menu item name. */ __( 'Open %s', 'oc-theme' ), $title ) )
			. '"><span aria-hidden="true"></span></button>'
			. '</div>'
			. '<div class="oc-drw__sub">' . $head
			. '<div class="oc-drw__subin"><ul class="oc-drw__list oc-drw__list--sub">' . $rows . '</ul></div>'
			. '</div></li></ul>';
	}

	/**
	 * Where "all of them" leads: a set address wins; without one a category
	 * item opens its own category, and anything else opens the brands page.
	 *
	 * @param array<string,mixed> $block Block.
	 * @param \WP_Term|null       $cat   Category behind the menu item.
	 * @return string
	 */
	private static function brands_more_url( array $block, ?\WP_Term $cat ): string {
		$more = (string) ( $block['link'] ?? '' );

		if ( '' === $more && null !== $cat && 'cat' === ( $block['scope'] ?? 'all' ) ) {
			$link = get_term_link( $cat );
			$more = is_wp_error( $link ) ? '' : (string) $link;
		}

		return '' === $more ? Brands::url() : $more;
	}

	/**
	 * Which brands this block is asking for, and how many there are in all.
	 * One place, because the panel and the drawer must agree about the set
	 * they are drawing — the same block showing different brands in the two
	 * presentations of one menu is a bug waiting to be reported.
	 *
	 * @param array<string,mixed> $block   Block.
	 * @param int                 $item_id Menu item the block sits under.
	 * @return array{terms:\WP_Term[],total:int,cat:\WP_Term|null}|null
	 */
	private static function brand_terms( array $block, int $item_id = 0 ): ?array {
		$taxonomy = class_exists( 'OC\\Theme\\Search' ) ? Search::brand_taxonomy() : '';

		if ( '' === $taxonomy ) {
			return null;
		}

		// The category behind the menu item, when there is one. A fashion
		// shop's "women" and "men" both carry brands, and the same block
		// under each should offer each aisle its own — not the whole house.
		$cat = null;

		if ( $item_id > 0 && 'product_cat' === get_post_meta( $item_id, '_menu_item_object', true ) ) {
			$term = get_term( (int) get_post_meta( $item_id, '_menu_item_object_id', true ), 'product_cat' );
			$cat  = $term instanceof \WP_Term ? $term : null;
		}

		$chosen = array_map( 'absint', (array) ( $block['terms'] ?? array() ) );
		$args   = array(
			'taxonomy'   => $taxonomy,
			'hide_empty' => false,
			'include'    => empty( $chosen ) ? array() : $chosen,
			'orderby'    => empty( $chosen ) ? 'name' : 'include',
		);

		if ( 'cat' === ( $block['scope'] ?? 'all' ) && null !== $cat && function_exists( 'wc_get_products' ) ) {
			$in_cat = self::category_brands( $cat, $taxonomy );

			if ( empty( $in_cat ) ) {
				return null;
			}

			$args['include'] = empty( $chosen ) ? $in_cat : array_values( array_intersect( $chosen, $in_cat ) );

			if ( empty( $args['include'] ) ) {
				return null;
			}
		}

		$terms = get_terms( $args );

		if ( ! is_array( $terms ) || empty( $terms ) ) {
			return null;
		}

		$count = max( 1, min( 24, (int) ( $block['count'] ?? 8 ) ) );

		return array(
			'total' => count( $terms ),
			'terms' => array_slice( $terms, 0, $count ),
			'cat'   => $cat,
		);
	}

	private static function brands_block( array $block, int $item_id = 0 ): string {
		$found = self::brand_terms( $block, $item_id );

		if ( null === $found ) {
			return '';
		}

		$cat   = $found['cat'];
		$total = $found['total'];
		$terms = $found['terms'];
		$title = trim( (string) ( $block['title'] ?? '' ) );
		$out   = '' === $title ? '' : '<h4 class="oc-mb__g">' . esc_html( $title ) . '</h4>';

		// 'text' is what this style was called before it grew a list. The
		// band shows the same shelf as the logo grid, only laid full-width.
		if ( ! in_array( (string) ( $block['style'] ?? 'list' ), array( 'logo', 'band' ), true ) ) {
			$rows = array();

			foreach ( $terms as $term ) {
				$rows[] = '<li><a href="' . esc_url( (string) get_term_link( $term ) ) . '">' . esc_html( $term->name ) . '</a></li>';
			}

			$out .= self::lists( $rows );
		} else {
			$out .= '<div class="oc-mb__brands">';

			foreach ( $terms as $term ) {
				$thumb = (int) get_term_meta( (int) $term->term_id, 'thumbnail_id', true );
				$inner = $thumb > 0
					? wp_get_attachment_image( $thumb, 'medium', false, array( 'class' => 'oc-mb__brand-logo', 'loading' => 'lazy', 'alt' => $term->name ) )
					: '<span class="oc-mb__brand-name">' . esc_html( $term->name ) . '</span>';

				$out .= '<a class="oc-mb__brand" href="' . esc_url( (string) get_term_link( $term ) ) . '">' . $inner . '</a>';
			}

			$out .= '</div>';
		}

		// More of them than shown: a door to the rest. A set address wins;
		// without one, a category item opens its own category, and anything
		// else opens the brands page.
		if ( $total > count( $terms ) ) {
			$more = self::brands_more_url( $block, $cat );

			if ( '' !== $more ) {
				$out .= '<a class="oc-mb__all" href="' . esc_url( $more ) . '">' . esc_html__( 'All brands', 'oc-theme' ) . '</a>';
			}
		}

		return $out;
	}

	/**
	 * The brands that have products in a category, as term ids.
	 *
	 * @param \WP_Term $cat      Category.
	 * @param string   $taxonomy Brand taxonomy.
	 * @return array<int,int>
	 */
	private static function category_brands( \WP_Term $cat, string $taxonomy ): array {
		$ids = wc_get_products(
			array(
				'status'   => 'publish',
				'limit'    => 200,
				'return'   => 'ids',
				'category' => array( $cat->slug ),
			)
		);

		if ( empty( $ids ) ) {
			return array();
		}

		$brands = wp_get_object_terms( $ids, $taxonomy, array( 'fields' => 'ids' ) );

		return is_wp_error( $brands ) ? array() : array_map( 'intval', $brands );
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

		$ratio = (string) ( $block['ratio'] ?? 'natural' );

		// "As the picture is" resolves to the picture's own proportions, so
		// the frame never crops what the photographer framed. It is resolved
		// here and cached with the markup — the picture's shape does not
		// change between requests.
		if ( 'natural' === $ratio || ! preg_match( '#^\d+/\d+$#', $ratio ) ) {
			$meta  = wp_get_attachment_metadata( $id );
			$ratio = ! empty( $meta['width'] ) && ! empty( $meta['height'] )
				? (int) $meta['width'] . '/' . (int) $meta['height']
				: '3/4';
		}

		$align  = 'centre' === ( $block['align'] ?? '' ) ? ' oc-mb__fig--al-c' : '';
		$focus  = max( 0, min( 100, absint( $block['focus'] ?? 50 ) ) );
		$pos    = (string) ( $block['pos'] ?? 'bottom' );
		$radius = (string) ( $block['radius'] ?? 'soft' );
		$url    = (string) ( $block['url'] ?? '' );

		// One src, no srcset, on purpose. With a candidate set, phones drew
		// this picture as a white box while every DOM measurement insisted
		// it was fine — the responsive machinery (lazy + WordPress's
		// sizes="auto") sizes candidates by layout width, and inside a
		// closed drawer that width is zero. A single "large" file covers a
		// 480px card at double density, always paints, and a menu picture
		// does not need more.
		$src = wp_get_attachment_image_url( $id, 'large' );

		if ( ! is_string( $src ) || '' === $src ) {
			return '';
		}

		$img = '<img class="oc-mb__img" src="' . esc_url( $src ) . '" alt="" loading="lazy" decoding="async">';

		$words = '';

		foreach ( array(
			'heading' => 'h4',
			'text'    => 'p',
			'cta'     => 'span',
		) as $key => $tag ) {
			$value = (string) ( $block[ $key ] ?? '' );

			if ( '' === $value ) {
				continue;
			}

			$words .= '<' . $tag . ' class="oc-mb__' . $key . '">' . esc_html( $value ) . '</' . $tag . '>';
		}

		if ( '' !== $words ) {
			$words = '<div class="oc-mb__words">' . $words . '</div>';
		}

		$inner = '<figure class="oc-mb__fig oc-mb__fig--' . sanitize_html_class( $pos ) . ' oc-mb__fig--r-' . sanitize_html_class( $radius ) . $align . '" style="--oc-mb-ratio:' . esc_attr( $ratio ) . ( 50 === $focus ? '' : ';--oc-mb-focus:' . $focus . '%' ) . '">' . $img . $words . '</figure>';

		return '' !== $url
			? '<a class="oc-mb__link" href="' . esc_url( $url ) . '">' . $inner . '</a>'
			: $inner;
	}

	/*
	 * Cache.
	 */

	/**
	 * A token that changes whenever any panel does. Keying the transients by
	 * it turns invalidation into one option write, rather than a hunt for
	 * every key that might now be wrong.
	 *
	 * @return string
	 */
	public static function version(): string {
		// The stored number covers content changes. The file's own timestamp
		// covers the other kind: a deploy that changes how a panel is drawn
		// leaves every cached panel drawn the old way, and nothing about
		// saving a menu would ever notice.
		return (int) get_option( 'oc_menu_panel_ver', 1 ) . '.' . (int) filemtime( __FILE__ );
	}

	/**
	 * Retire every cached panel.
	 */
	public static function flush(): void {
		update_option( 'oc_menu_panel_ver', (int) get_option( 'oc_menu_panel_ver', 1 ) + 1, false );
	}
}
