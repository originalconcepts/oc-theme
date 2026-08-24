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
		);
	}

	/**
	 * The block types, and the fields each one owns.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function types(): array {
		$types = array(
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

		return '<div class="' . esc_attr( $class ) . '"><div class="oc-mega__row" style="--oc-mega-cols:' . esc_attr( implode( ' ', $tracks ) ) . '">' . $body . '</div></div>';
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
		$out    = array();
		$first  = $gap;

		foreach ( null === $blocks ? self::blocks( $item_id ) : self::clean( $blocks ) as $block ) {
			if ( 'drawer' === $where ? 'desktop' === $block['dev'] : 'mobile' === $block['dev'] ) {
				continue;
			}

			$piece = self::block( $block );

			if ( null === $piece ) {
				continue;
			}

			if ( $first && 'drawer' !== $where ) {
				$out[] = array(
					'track' => '1fr',
					'class' => 'oc-mb oc-mb--gap',
					'inner' => '',
					'gap'   => true,
				);
				$first = false;
			}

			$out[] = array(
				'track' => (string) $widths[ $block['w'] ]['track'],
				'class' => $piece['class'],
				'inner' => $piece['inner'],
			);
		}

		return $out;
	}

	/**
	 * One block.
	 *
	 * @param array<string,mixed> $block Block.
	 * @return array{class:string,inner:string}|null
	 */
	private static function block( array $block ): ?array {
		$type = (string) $block['type'];

		$inner = '';

		switch ( $type ) {
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
			return null;
		}

		return array(
			'class' => 'oc-mb oc-mb--' . sanitize_html_class( $type ),
			'inner' => $inner,
		);
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

		$img = wp_get_attachment_image(
			$id,
			'medium_large',
			false,
			array(
				'class'   => 'oc-mb__img',
				'loading' => 'lazy',
				'alt'     => '',
				'sizes'   => '(max-width: 900px) 92vw, 480px',
			)
		);

		// WordPress prefixes a lazy image's sizes with "auto", telling the
		// browser to pick a candidate by the image's LAYOUT width — which is
		// zero inside a closed drawer. The host's device optimiser then
		// honours that zero and hands back a bitmap the element never
		// paints: a white box where a sofa should be, on phones only, while
		// every measurement of the DOM insists nothing is wrong. The
		// explicit sizes above are the truth; the keyword goes.
		$img = str_replace( 'sizes="auto, ', 'sizes="', $img );

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
