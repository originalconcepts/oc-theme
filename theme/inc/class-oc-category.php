<?php
/**
 * Category page — per-category hero, card image and sub-category display.
 *
 * A product category's edit screen gains an "עמוד קטגוריה" group: a hero
 * (full-width or half-split, desktop + mobile image, height, text over or
 * below the image), a shared card image (used by the categories block, the
 * sub-category strip and the blog), and a sub-category display under the
 * description. All of it is term meta — nothing here is global.
 *
 * @package OC\Theme
 */

declare( strict_types=1 );

namespace OC\Theme;

/**
 * Category page settings and rendering.
 */
class Category {

	/**
	 * Words positions on a hero image, shared with the OC-Blocks hero.
	 *
	 * @return array<string,string>
	 */
	public static function positions(): array {
		return array(
			'cc' => __( 'Centre', 'oc-theme' ),
			'cs' => __( 'Centre, reading side', 'oc-theme' ),
			'bc' => __( 'Bottom centre', 'oc-theme' ),
			'bs' => __( 'Bottom, reading side', 'oc-theme' ),
			'ts' => __( 'Top, reading side', 'oc-theme' ),
		);
	}

	/**
	 * Wire the admin fields and the front-end hero.
	 */
	public function register(): void {
		add_action( 'product_cat_edit_form_fields', array( $this, 'fields' ), 20 );
		add_action( 'edited_product_cat', array( $this, 'save' ) );

		// Core blog categories get the shared card image only.
		add_action( 'category_edit_form_fields', array( $this, 'category_fields' ), 20 );
		add_action( 'edited_category', array( $this, 'category_save' ) );

		add_action( 'admin_enqueue_scripts', array( $this, 'admin_assets' ) );

		add_action( 'wp', array( $this, 'setup' ) );

		// Old saves pinned every category to the dropdowns' first values.
		add_action( 'init', array( $this, 'migrate' ), 30 );

		// Track product views for the "recently viewed" slider source.
		add_action( 'template_redirect', array( $this, 'track_view' ), 20 );
	}

	/**
	 * One-time tidy for the shop-wide hero defaults.
	 *
	 * The old category screen had no "default" choice, so every save wrote
	 * the dropdowns' first values — text over the image, bottom on the
	 * reading side, light, no shade, image on the reading side — whether
	 * anyone chose them or not. Left in place they would pin every category
	 * against the defaults in Customize. They are exactly those defaults, so
	 * removing them changes nothing on a page until the shop changes the
	 * defaults. A layout, a height or a colour was always a real choice and
	 * is kept as the category's own.
	 */
	public function migrate(): void {
		if ( get_option( 'oc_chero_v2' ) ) {
			return;
		}

		$stale = array(
			'_oc_hero_text'  => 'over',
			'_oc_hero_pos'   => 'bs',
			'_oc_hero_tone'  => 'light',
			'_oc_hero_shade' => '0',
			'_oc_hero_side'  => 'start',
		);

		foreach ( $stale as $key => $value ) {
			delete_metadata( 'term', 0, $key, $value, true );
		}

		update_option( 'oc_chero_v2', 1 );
	}

	/**
	 * Remember a viewed product in the WooCommerce recently-viewed cookie.
	 */
	public function track_view(): void {
		if ( ! function_exists( 'is_product' ) || ! is_product() || ! function_exists( 'wc_setcookie' ) ) {
			return;
		}

		$id = (int) get_queried_object_id();

		if ( $id <= 0 || headers_sent() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- ids are absint-ed.
		$raw      = isset( $_COOKIE['woocommerce_recently_viewed'] ) ? (string) $_COOKIE['woocommerce_recently_viewed'] : '';
		$viewed   = array_filter( array_map( 'absint', explode( '|', $raw ) ) );
		$viewed   = array_values( array_diff( $viewed, array( $id ) ) );
		$viewed[] = $id;
		$viewed   = array_slice( $viewed, -15 );

		wc_setcookie( 'woocommerce_recently_viewed', implode( '|', $viewed ) );
	}

	/* ---------------------------------------------------------- reading helpers */

	/**
	 * The card image id for a term, with the fallback chain George asked for:
	 * the term's own card image, then its hero desktop image, then its hero
	 * mobile image, then the WooCommerce category image as a last resort.
	 *
	 * @param int $term_id Term id.
	 * @return int Attachment id, or 0.
	 */
	public static function card_image_id( int $term_id ): int {
		foreach ( array( '_oc_card_img', '_oc_hero_img', '_oc_hero_img_m', 'thumbnail_id' ) as $key ) {
			$id = absint( get_term_meta( $term_id, $key, true ) );

			if ( $id > 0 ) {
				return $id;
			}
		}

		return 0;
	}

	/**
	 * Where the card image's interesting half is, 0 (top) to 100 (bottom).
	 * Read wherever that picture is cut to a shape — the categories block,
	 * the sub-category strip — so a hat is not lost to a square.
	 *
	 * @param int $term_id Term id.
	 */
	public static function card_focus( int $term_id ): int {
		$v = get_term_meta( $term_id, '_oc_card_focus', true );

		return '' === (string) $v ? 50 : max( 0, min( 100, (int) $v ) );
	}

	/**
	 * Read a term's hero settings into a tidy shape.
	 *
	 * @param int $term_id Term id.
	 * @return array<string,mixed>
	 */
	private static function hero( int $term_id ): array {
		$get = static function ( string $key ) use ( $term_id ): string {
			return (string) get_term_meta( $term_id, $key, true );
		};

		$pct = static function ( string $v, int $def ): int {
			return '' === $v ? $def : max( 0, min( 100, (int) $v ) );
		};

		$d = self::hero_defaults();

		// Whatever the category leaves empty is the shop-wide choice made in
		// Customize. The pictures are always the category's own.
		$layout = '' !== $get( '_oc_hero_layout' ) ? $get( '_oc_hero_layout' ) : $d['layout'];

		return array(
			'layout' => 'none' === $layout ? '' : $layout,
			'img'    => absint( $get( '_oc_hero_img' ) ),
			'imgm'   => absint( $get( '_oc_hero_img_m' ) ),
			'h'      => absint( $get( '_oc_hero_h' ) ) > 0 ? absint( $get( '_oc_hero_h' ) ) : $d['h'],
			'hm'     => absint( $get( '_oc_hero_hm' ) ) > 0 ? absint( $get( '_oc_hero_hm' ) ) : $d['hm'],
			'text'   => '' !== $get( '_oc_hero_text' ) ? $get( '_oc_hero_text' ) : $d['text'],
			'pos'    => '' !== $get( '_oc_hero_pos' ) ? $get( '_oc_hero_pos' ) : $d['pos'],
			'tone'   => '' !== $get( '_oc_hero_tone' ) ? $get( '_oc_hero_tone' ) : $d['tone'],
			'shade'  => '' !== $get( '_oc_hero_shade' ) ? min( 90, absint( $get( '_oc_hero_shade' ) ) ) : $d['shade'],
			'side'   => '' !== $get( '_oc_hero_side' ) ? $get( '_oc_hero_side' ) : $d['side'],
			'cbg'    => '' !== $get( '_oc_hero_cbg' ) ? $get( '_oc_hero_cbg' ) : $d['cbg'],
			'fx'     => $pct( $get( '_oc_hero_fx' ), 50 ),
			'fy'     => $pct( $get( '_oc_hero_fy' ), 50 ),
			'fxm'    => $pct( $get( '_oc_hero_fxm' ), -1 ),
			'fym'    => $pct( $get( '_oc_hero_fym' ), -1 ),
		);
	}

	/**
	 * The hero every category takes unless it chooses otherwise — set once
	 * in Customize › Catalogue.
	 *
	 * @return array{layout:string,h:int,hm:int,text:string,pos:string,tone:string,shade:int,side:string,cbg:string}
	 */
	public static function hero_defaults(): array {
		$pick = static function ( string $key, array $allowed, string $def ): string {
			$v = (string) get_theme_mod( $key, $def );

			return in_array( $v, $allowed, true ) ? $v : $def;
		};

		return array(
			'layout' => $pick( 'oc_chero_layout', array( 'none', 'full', 'split' ), 'none' ),
			'h'      => absint( get_theme_mod( 'oc_chero_h', 0 ) ),
			'hm'     => absint( get_theme_mod( 'oc_chero_hm', 0 ) ),
			'text'   => $pick( 'oc_chero_text', array( 'over', 'below' ), 'over' ),
			'pos'    => $pick( 'oc_chero_pos', array_keys( self::positions() ), 'bs' ),
			'tone'   => $pick( 'oc_chero_tone', array( 'light', 'dark' ), 'light' ),
			'shade'  => min( 90, absint( get_theme_mod( 'oc_chero_shade', 0 ) ) ),
			'side'   => $pick( 'oc_chero_side', array( 'start', 'end' ), 'start' ),
			'cbg'    => (string) sanitize_hex_color( (string) get_theme_mod( 'oc_chero_cbg', '' ) ),
		);
	}

	/**
	 * Is there a hero worth rendering for this term?
	 *
	 * @param array<string,mixed> $h Hero settings.
	 */
	private static function has_hero( array $h ): bool {
		return in_array( $h['layout'], array( 'full', 'split' ), true ) && $h['img'] > 0;
	}

	/**
	 * Read a term's sub-category-strip settings.
	 *
	 * @param int $term_id Term id.
	 * @return array<string,mixed>
	 */
	private static function subs( int $term_id ): array {
		$get = static function ( string $key, string $def = '' ) use ( $term_id ): string {
			$v = get_term_meta( $term_id, $key, true );

			return '' !== (string) $v ? (string) $v : $def;
		};

		return array(
			'show'     => '1' === $get( '_oc_sub_show' ),
			'style'    => $get( '_oc_sub_style', 'clean' ),      // clean | pill | card.
			'pill'     => $get( '_oc_sub_pill', 'round' ),        // round | rect.
			'shape'    => $get( '_oc_sub_shape', 'square' ),      // square | portrait | circle.
			'corners'  => $get( '_oc_sub_corners', 'soft' ),      // sharp | soft.
			'slider'   => '1' === $get( '_oc_sub_slider' ),
			'slider_m' => $get( '_oc_sub_slider_m', 'same' ),    // same | yes | no — the phone's own answer.
			'place'    => $get( '_oc_sub_place', 'out' ),         // out | in.
			'place_m'  => $get( '_oc_sub_place_m', 'out' ),       // same | out | in — the phone's own answer.
			'align'    => $get( '_oc_sub_align', 'start' ),       // start | center.
		);
	}

	/**
	 * The queried category's direct children.
	 *
	 * @param int $term_id Parent id.
	 * @return \WP_Term[]
	 */
	private static function children( int $term_id ): array {
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'parent'     => $term_id,
				'hide_empty' => false,
				'orderby'    => 'menu_order',
			)
		);

		return is_array( $terms ) ? array_filter( $terms, static fn( $t ) => $t instanceof \WP_Term ) : array();
	}

	/* ---------------------------------------------------------- front-end */

	/**
	 * On a product-category archive with a hero set, suppress the default
	 * title + description (they move into the hero) and render the hero at
	 * full page width, above the constrained shop content.
	 */
	public function setup(): void {
		if ( ! function_exists( 'is_product_category' ) || ! is_product_category() ) {
			return;
		}

		$term = get_queried_object();

		if ( ! $term instanceof \WP_Term ) {
			return;
		}

		$h       = self::hero( $term->term_id );
		$sub     = self::subs( $term->term_id );
		$hero_on = self::has_hero( $h );

		if ( $hero_on ) {
			// It is the page's largest paint, and the parser does not reach
			// it until the stylesheets are done. Measured on a category of a
			// live shop: unannounced, the hero finished at 19s, because a
			// screenful of product thumbnails had the connection first.
			add_action(
				'wp_head',
				function () use ( $h ): void {
					self::rush( $h );
				},
				2
			);

			// The hero carries the H1 and the description, so hide Woo's own.
			add_filter( 'woocommerce_show_page_title', '__return_false' );
			remove_action( 'woocommerce_archive_description', 'woocommerce_taxonomy_archive_description', 10 );
			remove_action( 'woocommerce_archive_description', 'woocommerce_product_archive_description', 10 );

			// Full-bleed: render before the <main> wrapper opens (open_wrapper
			// is priority 10 on the same hook).
			add_action(
				'woocommerce_before_main_content',
				function () use ( $term, $h, $sub ): void {
					echo self::render( $term, $h, $sub ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
				},
				5
			);
		}

		// Sub-categories either ride the hero with its text, under the
		// description, or stand below the hero above the products. Each
		// screen answers for itself: a phone's hero has no room for a
		// stack of pills, so it usually wants them below.
		$places = self::sub_places( $hero_on, $sub );

		if ( $sub['show'] && in_array( 'out', $places, true ) ) {
			$align = 'center' === $sub['align'] ? 'center' : 'start';
			$only  = self::only_for( 'out', $places );

			add_action(
				'woocommerce_archive_description',
				function () use ( $term, $sub, $align, $only ): void {
					echo self::subcats_html( $term, $sub, 'out', $align, $only ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
				},
				15
			);
		}
	}

	/**
	 * Where the strip goes on each screen: [ desktop, mobile ], each
	 * 'in' (with the hero's text) or 'out' (below the hero). Without a
	 * hero there is nothing to be in.
	 *
	 * @param bool                $hero_on Hero drawn.
	 * @param array<string,mixed> $sub     Sub-category settings.
	 * @return string[]
	 */
	private static function sub_places( bool $hero_on, array $sub ): array {
		if ( ! $hero_on ) {
			return array( 'out', 'out' );
		}

		$d = 'in' === $sub['place'] ? 'in' : 'out';
		$m = 'same' === $sub['place_m'] ? $d : ( 'in' === $sub['place_m'] ? 'in' : 'out' );

		return array( $d, $m );
	}

	/**
	 * When the two screens disagree, each copy of the strip is marked for
	 * the screen it serves; when they agree, one copy serves both.
	 *
	 * @param string   $where  'in' | 'out'.
	 * @param string[] $places From sub_places().
	 */
	private static function only_for( string $where, array $places ): string {
		if ( $places[0] === $places[1] ) {
			return '';
		}

		return $places[0] === $where ? 'd' : 'm';
	}

	/**
	 * Build the hero markup.
	 *
	 * @param \WP_Term            $term Category.
	 * @param array<string,mixed> $h    Hero settings.
	 * @param array<string,mixed> $sub  Sub-category settings.
	 * @return string
	 */
	private static function render( \WP_Term $term, array $h, array $sub = array() ): string {
		$title = '<h1 class="oc-chero__title">' . esc_html( $term->name ) . '</h1>';
		$desc  = trim( (string) term_description( $term->term_id ) );
		$desc  = '' !== $desc ? '<div class="oc-chero__desc">' . wp_kses_post( $desc ) . '</div>' : '';

		// Sub-categories with the text: they follow its alignment.
		$subs = '';

		if ( ! empty( $sub['show'] ) ) {
			$places = self::sub_places( true, $sub );

			if ( in_array( 'in', $places, true ) ) {
				$over  = 'full' === $h['layout'] && 'over' === $h['text'];
				$align = $over ? ( in_array( $h['pos'], array( 'cc', 'bc' ), true ) ? 'center' : 'start' ) : ( 'center' === $sub['align'] ? 'center' : 'start' );
				$subs  = self::subcats_html( $term, $sub, 'in', $align, self::only_for( 'in', $places ) );
			}
		}

		$words = '<div class="oc-chero__words"><div class="oc-chero__wordsin">' . $title . $desc . $subs . '</div></div>';

		$style = '';

		if ( $h['h'] > 0 ) {
			$style .= '--ch-h:' . $h['h'] . 'px;';
		}

		if ( $h['hm'] > 0 ) {
			$style .= '--ch-hm:' . $h['hm'] . 'px;';
		}

		if ( 'split' === $h['layout'] && '' !== $h['cbg'] ) {
			$style .= '--ch-cbg:' . $h['cbg'] . ';';
		}

		// Where the picture sits inside a box that cuts it. Mobile follows
		// desktop unless it was given a place of its own.
		if ( 50 !== $h['fx'] || 50 !== $h['fy'] ) {
			$style .= '--ch-fx:' . $h['fx'] . '%;--ch-fy:' . $h['fy'] . '%;';
		}

		if ( $h['fxm'] >= 0 ) {
			$style .= '--ch-fxm:' . $h['fxm'] . '%;';
		}

		if ( $h['fym'] >= 0 ) {
			$style .= '--ch-fym:' . $h['fym'] . '%;';
		}

		$style = '' !== $style ? ' style="' . esc_attr( $style ) . '"' : '';

		if ( 'split' === $h['layout'] ) {
			$classes = 'oc-chero oc-chero--split oc-chero--img-' . esc_attr( $h['side'] );

			return '<section class="' . $classes . '"' . $style . '>'
				. '<div class="oc-chero__media">' . self::picture( $h, $term->name ) . '</div>'
				. '<div class="oc-chero__panel">' . $words . '</div>'
				. '</section>';
		}

		// Full-width.
		$over    = 'over' === $h['text'];
		$classes = 'oc-chero oc-chero--full oc-chero--text-' . ( $over ? 'over' : 'below' )
			. ( $over ? ' oc-chero--pos-' . esc_attr( $h['pos'] ) . ' oc-chero--' . esc_attr( $h['tone'] ) : '' );

		$shade = ( $over && $h['shade'] > 0 )
			? '<span class="oc-chero__shade" style="opacity:' . esc_attr( (string) ( $h['shade'] / 100 ) ) . '"></span>'
			: '';

		return '<section class="' . $classes . '"' . $style . '>'
			. '<div class="oc-chero__media">' . self::picture( $h, $term->name ) . $shade . '</div>'
			. $words
			. '</section>';
	}

	/**
	 * Tell the browser about the hero before the parser reaches it.
	 *
	 * @param array<string,mixed> $h Hero settings.
	 */
	private static function rush( array $h ): void {
		$wide = (string) wp_get_attachment_image_url( $h['img'], 'full' );

		if ( '' === $wide ) {
			return;
		}

		$phone = $h['imgm'] > 0 ? (string) wp_get_attachment_image_url( $h['imgm'], 'full' ) : '';

		// With two pictures each has to name the screen it is for, or a
		// phone fetches both and the announcement costs more than it saves.
		if ( '' !== $phone ) {
			self::rush_one( $phone, (int) $h['imgm'], '(max-width: 700px)' );
			self::rush_one( $wide, (int) $h['img'], '(min-width: 701px)' );

			return;
		}

		self::rush_one( $wide, (int) $h['img'], '' );
	}

	/**
	 * One preload line.
	 *
	 * @param string $url   Picture.
	 * @param int    $id    Attachment.
	 * @param string $media Which screen it is for, or ''.
	 */
	private static function rush_one( string $url, int $id, string $media ): void {
		$set = (string) wp_get_attachment_image_srcset( $id, 'full' );

		printf(
			'<link rel="preload" as="image" href="%s"%s%s fetchpriority="high">' . "\n",
			esc_url( $url ),
			'' === $media ? '' : ' media="' . esc_attr( $media ) . '"',
			'' === $set ? '' : ' imagesrcset="' . esc_attr( $set ) . '" imagesizes="100vw"'
		);
	}

	/**
	 * Desktop image with an optional mobile art-direction source.
	 *
	 * @param array<string,mixed> $h   Hero settings.
	 * @param string              $alt Alt text.
	 * @return string
	 */
	private static function picture( array $h, string $alt ): string {
		$img = wp_get_attachment_image(
			$h['img'],
			'full',
			false,
			array(
				'class'         => 'oc-chero__img',
				'alt'           => $alt,
				'fetchpriority' => 'high',
				'decoding'      => 'async',
			)
		);

		if ( '' === $img ) {
			return '';
		}

		if ( $h['imgm'] > 0 ) {
			$murl = (string) wp_get_attachment_image_url( $h['imgm'], 'full' );

			if ( '' !== $murl ) {
				// The phone picture needs its own set of widths as much as
				// the wide one does. Handed a bare src, a phone downloads
				// the original — a banner exported at 2000px and several
				// megabytes — for a screen 400px across.
				$mset = (string) wp_get_attachment_image_srcset( $h['imgm'], 'full' );
				$srcs = '' === $mset ? esc_url( $murl ) : esc_attr( $mset );

				return '<picture><source media="(max-width:700px)" srcset="' . $srcs . '" sizes="100vw">' . $img . '</picture>';
			}
		}

		return $img;
	}

	/**
	 * The strip of sub-categories under a category.
	 *
	 * @param \WP_Term            $term    Parent category.
	 * @param array<string,mixed> $sub     Sub-category settings.
	 * @param string              $context 'in' (with the hero's text) | 'out'.
	 * @param string              $align   'start' | 'center'.
	 * @param string              $only    '' (both screens) | 'd' | 'm'.
	 * @return string
	 */
	private static function subcats_html( \WP_Term $term, array $sub, string $context, string $align, string $only = '' ): string {
		$children = self::children( $term->term_id );

		if ( empty( $children ) ) {
			return '';
		}

		$style = in_array( $sub['style'], array( 'clean', 'pill', 'card' ), true ) ? $sub['style'] : 'clean';
		$items = '';

		foreach ( $children as $child ) {
			$link = get_term_link( $child );

			if ( is_wp_error( $link ) ) {
				continue;
			}

			$link = esc_url( (string) $link );
			$name = esc_html( $child->name );

			if ( 'card' === $style ) {
				$id  = self::card_image_id( $child->term_id );
				$img = $id > 0
					? wp_get_attachment_image(
						$id,
						'medium',
						false,
						array(
							'loading' => 'lazy',
							'alt'     => $child->name,
						)
					)
					: '';

				$focus = self::card_focus( $child->term_id );

				$items .= '<a class="oc-subcats__card" href="' . $link . '">'
					. '<span class="oc-subcats__pic"' . ( 50 !== $focus ? ' style="--oc-card-focus:' . esc_attr( (string) $focus ) . '%"' : '' ) . '>' . $img . '</span>'
					. '<span class="oc-subcats__name">' . $name . '</span>'
					. '</a>';
			} elseif ( 'pill' === $style ) {
				$items .= '<a class="oc-subcats__pill" href="' . $link . '">' . $name . '</a>';
			} else {
				$items .= '<a class="oc-subcats__link" href="' . $link . '">' . $name . '</a>';
			}
		}

		if ( '' === $items ) {
			return '';
		}

		$classes = 'oc-subcats oc-subcats--' . $style
			. ' oc-subcats--align-' . ( 'center' === $align ? 'center' : 'start' )
			. ' oc-subcats--' . ( 'in' === $context ? 'in' : 'out' )
			. ( '' !== $only ? ' oc-subcats--dev-' . $only : '' );

		if ( 'pill' === $style ) {
			$classes .= ' oc-subcats--pill-' . ( 'rect' === $sub['pill'] ? 'rect' : 'round' );
		}

		$attrs = '';

		if ( 'card' === $style ) {
			$shape    = in_array( $sub['shape'], array( 'square', 'portrait', 'circle' ), true ) ? $sub['shape'] : 'square';
			$classes .= ' oc-subcats--shape-' . $shape . ' oc-subcats--corners-' . ( 'sharp' === $sub['corners'] ? 'sharp' : 'soft' );
		}

		// A sideways strip instead of wrapped rows — any style, and each
		// screen decides for itself. The desktop strip also takes the
		// mouse drag; a phone swipes on its own.
		$slide_d = ! empty( $sub['slider'] );
		$slide_m = 'same' === ( $sub['slider_m'] ?? 'same' ) ? $slide_d : 'yes' === $sub['slider_m'];

		if ( $slide_d ) {
			$classes .= ' oc-subcats--slider oc-subcats--slider-d';
			$attrs    = ' data-oc-slider';
		}

		if ( $slide_m ) {
			$classes .= ' oc-subcats--slider-m';
		}

		return '<nav class="' . esc_attr( $classes ) . '"' . $attrs . ' aria-label="' . esc_attr__( 'Sub-categories', 'oc-theme' ) . '">' . $items . '</nav>';
	}

	/* ---------------------------------------------------------- admin — the category edit screen */

	/**
	 * Load wp.media on the product-category edit screen.
	 *
	 * @param string $hook Current admin page.
	 */
	public function admin_assets( string $hook ): void {
		if ( 'term.php' !== $hook && 'edit-tags.php' !== $hook ) {
			return;
		}

		$screen = get_current_screen();

		if ( $screen && in_array( $screen->taxonomy, array( 'product_cat', 'category' ), true ) ) {
			wp_enqueue_media();
		}
	}

	/**
	 * An image-picker row (button + hidden id + preview).
	 *
	 * @param string $name    Field / input name.
	 * @param int    $id      Current attachment id.
	 * @param string $label   Row label.
	 * @param string $hint    Description under the control.
	 * @param string $show_if data-attribute gate "field:value|value".
	 */
	private function image_field( string $name, int $id, string $label, string $hint = '', string $show_if = '' ): void {
		$preview = $id > 0
			? wp_get_attachment_image( $id, 'thumbnail', false, array( 'style' => 'display:block;max-inline-size:120px;height:auto;border-radius:6px' ) )
			: '';
		$gate    = '' !== $show_if ? ' data-oc-when="' . esc_attr( $show_if ) . '"' : '';
		?>
		<tr class="form-field oc-cat-imgfield" data-oc-imgfield<?php echo $gate; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- literal above. ?>>
			<th scope="row"><label><?php echo esc_html( $label ); ?></label></th>
			<td>
				<div class="oc-cat-img__view" style="margin-block-end:8px"><?php echo $preview; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_get_attachment_image() is safe. ?></div>
				<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( (string) ( $id > 0 ? $id : '' ) ); ?>" data-url="<?php echo esc_url( $id > 0 ? (string) wp_get_attachment_image_url( $id, 'large' ) : '' ); ?>" data-oc-img-input>
				<button type="button" class="button" data-oc-img-pick><?php esc_html_e( 'Choose image', 'oc-theme' ); ?></button>
				<button type="button" class="button-link oc-cat-img__clear" data-oc-img-clear style="margin-inline-start:8px;<?php echo $id > 0 ? '' : 'display:none'; ?>"><?php esc_html_e( 'Remove', 'oc-theme' ); ?></button>
				<?php if ( '' !== $hint ) : ?>
					<p class="description"><?php echo esc_html( $hint ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * A select row.
	 *
	 * @param string               $name    Field name.
	 * @param string               $current Current value.
	 * @param string               $label   Label.
	 * @param array<string,string> $choices Options.
	 * @param string               $hint    Hint.
	 * @param string               $show_if data-attribute gate "field:value|value".
	 */
	private function select_field( string $name, string $current, string $label, array $choices, string $hint = '', string $show_if = '' ): void {
		$gate = '' !== $show_if ? ' data-oc-when="' . esc_attr( $show_if ) . '"' : '';
		?>
		<tr class="form-field"<?php echo $gate; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- literal above. ?>>
			<th scope="row"><label for="<?php echo esc_attr( $name ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td>
				<select name="<?php echo esc_attr( $name ); ?>" id="<?php echo esc_attr( $name ); ?>" data-oc-field>
					<?php foreach ( $choices as $value => $text ) : ?>
						<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $current, $value ); ?>><?php echo esc_html( $text ); ?></option>
					<?php endforeach; ?>
				</select>
				<?php if ( '' !== $hint ) : ?>
					<p class="description"><?php echo esc_html( $hint ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * A small illustrative SVG for the visual pickers.
	 *
	 * @param string $key Icon key.
	 * @return string
	 */
	private static function icon( string $key ): string {
		$icons = array(
			// Hero layouts.
			'l-none'  => '<svg viewBox="0 0 108 64" aria-hidden="true"><rect x="20" y="22" width="68" height="7" rx="3.5" fill="#b9c0c7"/><rect x="30" y="35" width="48" height="4" rx="2" fill="#dbe0e5"/><rect x="34" y="44" width="40" height="4" rx="2" fill="#dbe0e5"/></svg>',
			'l-full'  => '<svg viewBox="0 0 108 64" aria-hidden="true"><rect x="6" y="6" width="96" height="52" rx="5" fill="#b9c0c7"/><rect x="16" y="38" width="44" height="7" rx="3.5" fill="#fff"/><rect x="16" y="48" width="30" height="4" rx="2" fill="#eaeef1"/></svg>',
			'l-split' => '<svg viewBox="0 0 108 64" aria-hidden="true"><rect x="6" y="6" width="46" height="52" rx="5" fill="#eaeef1"/><rect x="56" y="6" width="46" height="52" rx="5" fill="#b9c0c7"/><rect x="14" y="26" width="30" height="7" rx="3.5" fill="#b9c0c7"/><rect x="14" y="37" width="22" height="4" rx="2" fill="#cfd6dc"/></svg>',
			// Sub-category displays.
			's-clean' => '<svg viewBox="0 0 108 64" aria-hidden="true"><rect x="22" y="20" width="30" height="5" rx="2.5" fill="#b9c0c7"/><rect x="22" y="27" width="30" height="2" rx="1" fill="#cfd6dc"/><rect x="60" y="20" width="26" height="5" rx="2.5" fill="#b9c0c7"/><rect x="60" y="27" width="26" height="2" rx="1" fill="#cfd6dc"/><rect x="22" y="38" width="34" height="5" rx="2.5" fill="#b9c0c7"/><rect x="22" y="45" width="34" height="2" rx="1" fill="#cfd6dc"/></svg>',
			's-pill'  => '<svg viewBox="0 0 108 64" aria-hidden="true"><rect x="14" y="26" width="36" height="15" rx="7.5" fill="none" stroke="#b9c0c7" stroke-width="2.5"/><rect x="58" y="26" width="30" height="15" rx="7.5" fill="none" stroke="#b9c0c7" stroke-width="2.5"/></svg>',
			's-card'  => '<svg viewBox="0 0 108 64" aria-hidden="true"><rect x="12" y="12" width="24" height="26" rx="3" fill="#b9c0c7"/><rect x="16" y="42" width="16" height="4" rx="2" fill="#cfd6dc"/><rect x="42" y="12" width="24" height="26" rx="3" fill="#b9c0c7"/><rect x="46" y="42" width="16" height="4" rx="2" fill="#cfd6dc"/><rect x="72" y="12" width="24" height="26" rx="3" fill="#b9c0c7"/><rect x="76" y="42" width="16" height="4" rx="2" fill="#cfd6dc"/></svg>',
		);

		return $icons[ $key ] ?? '';
	}

	/**
	 * A visual radio picker — each option is an illustrated card.
	 *
	 * @param string                             $name    Field name.
	 * @param string                             $current Current value.
	 * @param string                             $label   Row label.
	 * @param array<string,array<string,string>> $options value => [label, svg].
	 * @param string                             $hint    Hint.
	 * @param string                             $show_if data-attribute gate.
	 */
	private function visual_field( string $name, string $current, string $label, array $options, string $hint = '', string $show_if = '' ): void {
		$gate = '' !== $show_if ? ' data-oc-when="' . esc_attr( $show_if ) . '"' : '';
		?>
		<tr class="form-field"<?php echo $gate; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- literal above. ?>>
			<th scope="row"><label><?php echo esc_html( $label ); ?></label></th>
			<td>
				<div class="oc-vpick">
					<?php foreach ( $options as $value => $opt ) : ?>
						<label class="oc-vpick__opt<?php echo $current === (string) $value ? ' is-sel' : ''; ?>">
							<input type="radio" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( (string) $value ); ?>" <?php checked( $current, (string) $value ); ?> data-oc-field data-oc-vpick>
							<span class="oc-vpick__art"><?php echo $opt['svg']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static illustrative SVG. ?></span>
							<span class="oc-vpick__lbl"><?php echo esc_html( $opt['label'] ); ?></span>
						</label>
					<?php endforeach; ?>
				</div>
				<?php if ( '' !== $hint ) : ?>
					<p class="description"><?php echo esc_html( $hint ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * A checkbox row.
	 *
	 * @param string $name    Field name.
	 * @param bool   $checked Current state.
	 * @param string $label   Label.
	 * @param string $text    Text beside the checkbox.
	 * @param string $show_if data-attribute gate.
	 */
	private function toggle_field( string $name, bool $checked, string $label, string $text, string $show_if = '' ): void {
		$gate = '' !== $show_if ? ' data-oc-when="' . esc_attr( $show_if ) . '"' : '';
		?>
		<tr class="form-field"<?php echo $gate; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- literal above. ?>>
			<th scope="row"><label for="<?php echo esc_attr( $name ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td>
				<label>
					<input type="checkbox" name="<?php echo esc_attr( $name ); ?>" id="<?php echo esc_attr( $name ); ?>" value="1" <?php checked( $checked ); ?> data-oc-field>
					<?php echo esc_html( $text ); ?>
				</label>
			</td>
		</tr>
		<?php
	}

	/**
	 * The category-page fields on the edit screen.
	 *
	 * @param \WP_Term $term Category.
	 */
	public function fields( $term ): void {
		if ( ! $term instanceof \WP_Term ) {
			return;
		}

		$h   = self::hero( $term->term_id );
		$sub = self::subs( $term->term_id );
		$g   = self::hero_defaults();

		$raw = static function ( string $key ) use ( $term ): string {
			return (string) get_term_meta( $term->term_id, $key, true );
		};

		// A row that belongs to a layout shows for that layout — and for
		// "Default" while the default is that layout.
		$gate = static function ( array $layouts ) use ( $g ): string {
			return '_oc_hero_layout:' . implode( '|', $layouts ) . ( in_array( $g['layout'], $layouts, true ) ? '|' : '' );
		};

		$first = static function ( array $choices, string $current ): array {
			/* translators: %s: the choice Customize holds for every category. */
			return array( '' => sprintf( __( 'Default — %s', 'oc-theme' ), (string) ( $choices[ $current ] ?? '' ) ) ) + $choices;
		};

		$layouts = array(
			'none'  => __( 'None — plain title', 'oc-theme' ),
			'full'  => __( 'Full-width image', 'oc-theme' ),
			'split' => __( 'Half image · half content', 'oc-theme' ),
		);
		$texts   = array(
			'over'  => __( 'Over the image', 'oc-theme' ),
			'below' => __( 'Below the image', 'oc-theme' ),
		);
		$tones   = array(
			'light' => __( 'Light (for a dark image)', 'oc-theme' ),
			'dark'  => __( 'Dark (for a light image)', 'oc-theme' ),
		);
		$sides   = array(
			'start' => __( 'Reading side (right in Hebrew)', 'oc-theme' ),
			'end'   => __( 'Opposite side', 'oc-theme' ),
		);
		$auto    = __( 'automatic', 'oc-theme' );
		?>
		<tr class="form-field oc-cat-sec">
			<th scope="row" colspan="2" style="padding-block-end:0">
				<h2 style="margin:22px 0 0;font-size:1.15em"><?php esc_html_e( 'Category page — hero', 'oc-theme' ); ?></h2>
				<p class="description" style="font-weight:400"><?php esc_html_e( 'A banner at the top of this category. The category name and description move onto it. “Default” follows Customize › Catalogue; pick anything else to give this category its own.', 'oc-theme' ); ?></p>
			</th>
		</tr>
		<?php
		$this->visual_field(
			'_oc_hero_layout',
			$raw( '_oc_hero_layout' ),
			__( 'Layout', 'oc-theme' ),
			array(
				''      => array(
					/* translators: %s: the layout Customize holds for every category. */
					'label' => sprintf( __( 'Default — %s', 'oc-theme' ), $layouts[ $g['layout'] ] ),
					'svg'   => self::icon( 'l-' . $g['layout'] ),
				),
				'none'  => array(
					'label' => $layouts['none'],
					'svg'   => self::icon( 'l-none' ),
				),
				'full'  => array(
					'label' => $layouts['full'],
					'svg'   => self::icon( 'l-full' ),
				),
				'split' => array(
					'label' => $layouts['split'],
					'svg'   => self::icon( 'l-split' ),
				),
			)
		);

		$this->image_field( '_oc_hero_img', $h['img'], __( 'Hero image — desktop', 'oc-theme' ), __( 'Shown on the category page.', 'oc-theme' ), $gate( array( 'full', 'split' ) ) );
		$this->image_field( '_oc_hero_img_m', $h['imgm'], __( 'Hero image — mobile (optional)', 'oc-theme' ), __( 'Used on phones. If empty, the desktop image is used.', 'oc-theme' ), $gate( array( 'full', 'split' ) ) );

		$this->hero_focus_field( $term->term_id, $g, $gate( array( 'full', 'split' ) ) );

		// Heights.
		?>
		<tr class="form-field" data-oc-when="<?php echo esc_attr( $gate( array( 'full', 'split' ) ) ); ?>">
			<th scope="row"><label><?php esc_html_e( 'Height', 'oc-theme' ); ?></label></th>
			<td>
				<label style="display:inline-block;min-inline-size:90px"><?php esc_html_e( 'Desktop', 'oc-theme' ); ?></label>
				<input type="number" min="0" max="1200" name="_oc_hero_h" value="<?php echo esc_attr( $raw( '_oc_hero_h' ) ); ?>" placeholder="<?php echo esc_attr( $g['h'] > 0 ? (string) $g['h'] : $auto ); ?>" style="inline-size:110px"> px<br>
				<label style="display:inline-block;min-inline-size:90px;margin-block-start:6px"><?php esc_html_e( 'Mobile', 'oc-theme' ); ?></label>
				<input type="number" min="0" max="1200" name="_oc_hero_hm" value="<?php echo esc_attr( $raw( '_oc_hero_hm' ) ); ?>" placeholder="<?php echo esc_attr( $g['hm'] > 0 ? (string) $g['hm'] : $auto ); ?>" style="inline-size:110px"> px
				<p class="description">
					<?php
					/* translators: %s: the default heights, desktop / mobile. */
					echo esc_html( sprintf( __( 'Empty follows the default (%s).', 'oc-theme' ), ( $g['h'] > 0 ? $g['h'] . 'px' : $auto ) . ' / ' . ( $g['hm'] > 0 ? $g['hm'] . 'px' : $auto ) ) );
					?>
				</p>
			</td>
		</tr>
		<?php
		// Full-width options.
		$this->select_field( '_oc_hero_text', $raw( '_oc_hero_text' ), __( 'Text', 'oc-theme' ), $first( $texts, $g['text'] ), '', $gate( array( 'full' ) ) );
		$this->select_field( '_oc_hero_pos', $raw( '_oc_hero_pos' ), __( 'Text position', 'oc-theme' ), $first( self::positions(), $g['pos'] ), '', $gate( array( 'full' ) ) );
		$this->select_field( '_oc_hero_tone', $raw( '_oc_hero_tone' ), __( 'Text colour', 'oc-theme' ), $first( $tones, $g['tone'] ), '', $gate( array( 'full' ) ) );
		?>
		<tr class="form-field" data-oc-when="<?php echo esc_attr( $gate( array( 'full' ) ) ); ?>">
			<th scope="row"><label for="_oc_hero_shade"><?php esc_html_e( 'Darken image', 'oc-theme' ); ?></label></th>
			<td>
				<input type="number" min="0" max="90" step="5" name="_oc_hero_shade" id="_oc_hero_shade" value="<?php echo esc_attr( $raw( '_oc_hero_shade' ) ); ?>" placeholder="<?php echo esc_attr( (string) $g['shade'] ); ?>" style="inline-size:80px"> %
				<p class="description">
					<?php esc_html_e( 'A dark veil over the image so light text stays readable. 0 = off.', 'oc-theme' ); ?>
					<?php
					/* translators: %s: the default shade. */
					echo esc_html( sprintf( __( 'Empty follows the default (%s).', 'oc-theme' ), $g['shade'] . '%' ) );
					?>
				</p>
			</td>
		</tr>
		<?php
		// Split options.
		$this->select_field( '_oc_hero_side', $raw( '_oc_hero_side' ), __( 'Image side', 'oc-theme' ), $first( $sides, $g['side'] ), '', $gate( array( 'split' ) ) );
		?>
		<tr class="form-field" data-oc-when="<?php echo esc_attr( $gate( array( 'split' ) ) ); ?>">
			<th scope="row"><label for="_oc_hero_cbg"><?php esc_html_e( 'Content background', 'oc-theme' ); ?></label></th>
			<td>
				<input type="text" name="_oc_hero_cbg" id="_oc_hero_cbg" value="<?php echo esc_attr( $raw( '_oc_hero_cbg' ) ); ?>" placeholder="<?php echo esc_attr( '' !== $g['cbg'] ? $g['cbg'] : '#f4f1ec' ); ?>" class="ltr" style="inline-size:140px">
				<p class="description"><?php esc_html_e( 'Background colour behind the content half. Leave empty for the page background.', 'oc-theme' ); ?></p>
			</td>
		</tr>

		<tr class="form-field oc-cat-sec">
			<th scope="row" colspan="2" style="padding-block-end:0">
				<h2 style="margin:22px 0 0;font-size:1.15em"><?php esc_html_e( 'Card image', 'oc-theme' ); ?></h2>
				<p class="description" style="font-weight:400"><?php esc_html_e( 'One image for this category, used by the categories block, the sub-category strip and the blog. If empty, the hero image is used.', 'oc-theme' ); ?></p>
			</th>
		</tr>
		<?php
		$card = absint( get_term_meta( $term->term_id, '_oc_card_img', true ) );
		$this->image_field( '_oc_card_img', $card, __( 'Image', 'oc-theme' ) );
		$this->focus_field( $term->term_id );
		?>

		<tr class="form-field oc-cat-sec">
			<th scope="row" colspan="2" style="padding-block-end:0">
				<h2 style="margin:22px 0 0;font-size:1.15em"><?php esc_html_e( 'Sub-categories', 'oc-theme' ); ?></h2>
				<p class="description" style="font-weight:400"><?php esc_html_e( 'Show this category’s sub-categories under the description (or over the hero image).', 'oc-theme' ); ?></p>
			</th>
		</tr>
		<?php
		$this->toggle_field( '_oc_sub_show', $sub['show'], __( 'Show sub-categories', 'oc-theme' ), __( 'Display a strip of the child categories.', 'oc-theme' ) );

		$this->visual_field(
			'_oc_sub_style',
			$sub['style'],
			__( 'Display', 'oc-theme' ),
			array(
				'clean' => array(
					'label' => __( 'Clean — underlined links', 'oc-theme' ),
					'svg'   => self::icon( 's-clean' ),
				),
				'pill'  => array(
					'label' => __( 'Pills', 'oc-theme' ),
					'svg'   => self::icon( 's-pill' ),
				),
				'card'  => array(
					'label' => __( 'Image cards', 'oc-theme' ),
					'svg'   => self::icon( 's-card' ),
				),
			),
			'',
			'_oc_sub_show:1'
		);

		$this->select_field(
			'_oc_sub_pill',
			$sub['pill'],
			__( 'Pill shape', 'oc-theme' ),
			array(
				'round' => __( 'Rounded (ellipse)', 'oc-theme' ),
				'rect'  => __( 'Rectangle', 'oc-theme' ),
			),
			'',
			'_oc_sub_show:1,_oc_sub_style:pill'
		);

		$this->select_field(
			'_oc_sub_shape',
			$sub['shape'],
			__( 'Image shape', 'oc-theme' ),
			array(
				'square'   => __( 'Square', 'oc-theme' ),
				'portrait' => __( 'Portrait', 'oc-theme' ),
				'circle'   => __( 'Circle', 'oc-theme' ),
			),
			'',
			'_oc_sub_show:1,_oc_sub_style:card'
		);

		$this->select_field(
			'_oc_sub_corners',
			$sub['corners'],
			__( 'Corners', 'oc-theme' ),
			array(
				'soft'  => __( 'Soft', 'oc-theme' ),
				'sharp' => __( 'Sharp', 'oc-theme' ),
			),
			'',
			'_oc_sub_show:1,_oc_sub_style:card'
		);

		$this->toggle_field(
			'_oc_sub_slider',
			$sub['slider'],
			__( 'Slider (desktop)', 'oc-theme' ),
			__( 'One row that scrolls sideways instead of wrapping — links, pills or cards alike. Drag on desktop, swipe on touch.', 'oc-theme' ),
			'_oc_sub_show:1'
		);

		$this->select_field(
			'_oc_sub_slider_m',
			$sub['slider_m'],
			__( 'Slider (mobile)', 'oc-theme' ),
			array(
				'same' => __( 'Same as desktop', 'oc-theme' ),
				'yes'  => __( 'Slider', 'oc-theme' ),
				'no'   => __( 'Wrapped rows', 'oc-theme' ),
			),
			__( 'A finger-swipe strip that runs to the screen edge — below the hero or with its text.', 'oc-theme' ),
			'_oc_sub_show:1'
		);

		$this->select_field(
			'_oc_sub_place',
			$sub['place'],
			__( 'Placement (desktop)', 'oc-theme' ),
			array(
				'out' => __( 'Below the hero, above the products', 'oc-theme' ),
				'in'  => __( 'With the text, under the description', 'oc-theme' ),
			),
			__( 'Only with a hero; without one the strip sits above the products. With the text, they follow its alignment.', 'oc-theme' ),
			'_oc_sub_show:1'
		);

		$this->select_field(
			'_oc_sub_place_m',
			$sub['place_m'],
			__( 'Placement (mobile)', 'oc-theme' ),
			array(
				'out'  => __( 'Below the hero, above the products', 'oc-theme' ),
				'in'   => __( 'With the text, under the description', 'oc-theme' ),
				'same' => __( 'Same as desktop', 'oc-theme' ),
			),
			__( 'A phone’s hero has little room; below it is usually the better place.', 'oc-theme' ),
			'_oc_sub_show:1'
		);

		$this->select_field(
			'_oc_sub_align',
			$sub['align'],
			__( 'Alignment', 'oc-theme' ),
			array(
				'start'  => __( 'Reading side', 'oc-theme' ),
				'center' => __( 'Centre', 'oc-theme' ),
			),
			'',
			'_oc_sub_show:1,_oc_sub_place:out'
		);

		$this->admin_script();
	}

	/**
	 * The image picker + conditional-field JS for the edit screen.
	 */
	private function admin_script(): void {
		?>
		<style>
		.oc-cat-sec th { border-block-start: 1px solid #dcdcde; padding-block-start: 4px; }
		.oc-cat-sec h2 { color: #1d2327; }
		.oc-vpick { display: flex; flex-wrap: wrap; gap: 12px; }
		.oc-vpick__opt {
			display: flex; flex-direction: column; align-items: center; gap: 7px;
			inline-size: 128px; padding: 12px 10px 10px; cursor: pointer;
			border: 2px solid #dcdcde; border-radius: 10px; background: #fff;
			text-align: center; transition: border-color .12s, box-shadow .12s;
		}
		.oc-vpick__opt:hover { border-color: #a7aaad; }
		.oc-vpick__opt input { position: absolute; opacity: 0; pointer-events: none; }
		.oc-vpick__opt.is-sel,
		.oc-vpick__opt:has(input:checked) { border-color: #2271b1; box-shadow: 0 0 0 1px #2271b1; }
		.oc-vpick__art { inline-size: 100%; }
		.oc-vpick__art svg { display: block; inline-size: 100%; block-size: 62px; }
		.oc-vpick__lbl { font-size: 12px; font-weight: 600; line-height: 1.3; }
		.oc-hfocus { display: flex; flex-wrap: wrap; gap: 20px; align-items: flex-start; }
		.oc-hfocus__none { display: none; margin: 0; color: #646970; }
		.oc-hfocus.is-empty .oc-hfocus__none { display: block; }
		.oc-hfocus.is-empty .oc-hfocus__dev { display: none; }
		.oc-hfocus__dev { display: flex; flex-direction: column; gap: 6px; }
		.oc-hfocus__dev[data-dev="d"] { inline-size: min(440px, 100%); }
		.oc-hfocus__dev[data-dev="m"] { inline-size: 190px; }
		.oc-hfocus__cap { display: flex; flex-wrap: wrap; align-items: baseline; gap: 6px; font-weight: 600; }
		.oc-hfocus__cap .oc-hfocus__same { font-weight: 400; color: #646970; }
		.oc-hfocus__own, .oc-hfocus__follow, .oc-hfocus__same { display: none; }
		.oc-hfocus__dev.is-mirror .oc-hfocus__own, .oc-hfocus__dev.is-mirror .oc-hfocus__same { display: inline; }
		.oc-hfocus__dev[data-dev="m"]:not(.is-mirror) .oc-hfocus__follow { display: inline; }
		.oc-hfocus__frame { position: relative; overflow: hidden; border-radius: 6px; background: #f0f0f1; cursor: grab; touch-action: none; box-shadow: inset 0 0 0 1px rgba(0,0,0,.1); }
		.oc-hfocus__frame:active { cursor: grabbing; }
		.oc-hfocus__frame img { position: absolute; inset: 0; inline-size: 100%; block-size: 100%; object-fit: cover; pointer-events: none; user-select: none; }
		.oc-hfocus__dev.is-mirror .oc-hfocus__frame { opacity: .8; }
		.oc-hfocus__row { display: flex; align-items: center; gap: 8px; font-size: 12px; }
		.oc-hfocus__row span { min-inline-size: 72px; }
		.oc-hfocus__row input[type=range] { flex: 1; min-inline-size: 0; }
		.oc-hfocus__row output { min-inline-size: 36px; text-align: end; font-variant-numeric: tabular-nums; }
		.oc-hfocus__dev.no-x [data-row="x"], .oc-hfocus__dev.no-y [data-row="y"] { opacity: .4; }
		</style>
		<script>
		( function ( $ ) {
			// Image pickers.
			$( document ).on( 'click', '[data-oc-img-pick]', function ( e ) {
				e.preventDefault();
				var $row = $( this ).closest( '[data-oc-imgfield]' );
				var frame = wp.media( { title: <?php echo wp_json_encode( __( 'Choose image', 'oc-theme' ) ); ?>, multiple: false, library: { type: 'image' } } );
				frame.on( 'select', function () {
					var a = frame.state().get( 'selection' ).first().toJSON();
					var u = ( a.sizes && a.sizes.thumbnail ) ? a.sizes.thumbnail.url : a.url;
					$row.find( '[data-oc-img-input]' ).val( a.id ).attr( 'data-url', ( a.sizes && a.sizes.large ) ? a.sizes.large.url : a.url );
					$row.find( '.oc-cat-img__view' ).html( '<img src="' + u + '" style="display:block;max-inline-size:120px;height:auto;border-radius:6px">' );
					$row.find( '[data-oc-img-clear]' ).show();
					$( document ).trigger( 'oc:img' );
				} );
				frame.open();
			} );
			$( document ).on( 'click', '[data-oc-img-clear]', function ( e ) {
				e.preventDefault();
				var $row = $( this ).closest( '[data-oc-imgfield]' );
				$row.find( '[data-oc-img-input]' ).val( '' ).attr( 'data-url', '' );
				$row.find( '.oc-cat-img__view' ).empty();
				$( this ).hide();
				$( document ).trigger( 'oc:img' );
			} );

			// Conditional rows. data-oc-when="field:a|b,field2:c" — every
			// comma-separated clause must match (AND); values within a clause
			// are alternatives (OR).
			function fval( name ) {
				var els = document.getElementsByName( name );
				if ( ! els.length ) { return ''; }
				if ( 'radio' === els[ 0 ].type ) {
					var picked = document.querySelector( '[name="' + name + '"]:checked' );
					return picked ? picked.value : '';
				}
				if ( 'checkbox' === els[ 0 ].type ) { return els[ 0 ].checked ? '1' : ''; }
				return els[ 0 ].value || '';
			}
			function sync() {
				$( '[data-oc-when]' ).each( function () {
					var ok = true;
					$( this ).data( 'oc-when' ).toString().split( ',' ).forEach( function ( cond ) {
						var p = cond.split( ':' ), field = p[ 0 ], vals = ( p[ 1 ] || '' ).split( '|' );
						if ( vals.indexOf( fval( field ) ) === -1 ) { ok = false; }
					} );
					$( this ).toggle( ok );
				} );
			}
			// Visual-picker selected state (fallback for browsers without :has).
			function vsel() {
				$( '.oc-vpick__opt' ).each( function () {
					var r = $( this ).find( 'input[type=radio]' )[ 0 ];
					$( this ).toggleClass( 'is-sel', !! ( r && r.checked ) );
				} );
			}
			$( document ).on( 'change', '[data-oc-field]', sync );
			$( document ).on( 'change', '[data-oc-vpick]', vsel );
			// The position slider: the number and the preview follow the thumb.
			$( document ).on( 'input change', '[data-oc-focus]', function () {
				$( '#_oc_card_focus_out' ).text( this.value + '%' );
				$( '.oc-focus-prev img' ).css( 'object-position', '50% ' + this.value + '%' );
			} );
			$( document ).on( 'click', '[data-oc-focus-reset]', function ( e ) {
				e.preventDefault();
				$( '[data-oc-focus]' ).val( 50 ).trigger( 'change' );
			} );
			// Hero picture position. Each frame takes the banner's real shape on
			// its screen — the layout, the text placement and the heights,
			// the category's own or the defaults — and cuts the picture the
			// way the page will. Dragging moves the picture; so do the sliders.
			var hf = document.querySelector( '[data-oc-hfocus]' );
			if ( hf ) {
				var G = { layout: hf.dataset.glayout, text: hf.dataset.gtext, h: parseInt( hf.dataset.gh, 10 ) || 0, hm: parseInt( hf.dataset.ghm, 10 ) || 0 };
				var own = hf.querySelector( '[name="_oc_hero_fm"]' );
				var eff = function ( name, def ) { var v = fval( name ); return '' === v ? def : v; };
				var num = function ( name ) { var el = document.querySelector( '[name="' + name + '"]' ); return el ? ( parseInt( el.value, 10 ) || 0 ) : 0; };
				var url = function ( name ) { var el = document.querySelector( '[name="' + name + '"]' ); return el ? ( el.getAttribute( 'data-url' ) || '' ) : ''; };
				var parts = function ( dev ) {
					var box = hf.querySelector( '[data-dev="' + dev + '"]' );
					return { box: box, x: box.querySelector( '[data-axis="x"]' ), y: box.querySelector( '[data-axis="y"]' ), frame: box.querySelector( '[data-frame]' ), img: box.querySelector( '[data-frame] img' ) };
				};
				var shape = function ( dev, img ) {
					var layout = eff( '_oc_hero_layout', G.layout );
					var text = eff( '_oc_hero_text', G.text );
					var natural = img && img.naturalWidth ? img.naturalWidth / img.naturalHeight : 16 / 9;
					var h = 'd' === dev ? ( num( '_oc_hero_h' ) || G.h ) : ( num( '_oc_hero_hm' ) || G.hm );
					var wide = 'd' === dev ? 1440 : 390;
					if ( 'split' === layout ) { return 'd' === dev ? 720 / ( h || 440 ) : wide / ( h || 300 ); }
					if ( 'below' === text && ! h ) { return natural; }
					return wide / ( h || ( 'd' === dev ? 420 : 340 ) );
				};
				var draw = function () {
					var dUrl = url( '_oc_hero_img' );
					var mUrl = url( '_oc_hero_img_m' ) || dUrl;
					hf.classList.toggle( 'is-empty', '' === dUrl );
					[ 'd', 'm' ].forEach( function ( dev ) {
						var p = parts( dev );
						var src = 'd' === dev ? dUrl : mUrl;
						if ( src && p.img.getAttribute( 'src' ) !== src ) { p.img.src = src; }
						var mirror = 'm' === dev && '1' !== own.value;
						if ( mirror ) { var d = parts( 'd' ); p.x.value = d.x.value; p.y.value = d.y.value; }
						p.x.disabled = p.y.disabled = mirror;
						p.box.classList.toggle( 'is-mirror', mirror );
						p.frame.style.aspectRatio = String( shape( dev, p.img ) );
						p.img.style.objectPosition = p.x.value + '% ' + p.y.value + '%';
						p.box.querySelector( '[data-out="x"]' ).textContent = p.x.value + '%';
						p.box.querySelector( '[data-out="y"]' ).textContent = p.y.value + '%';
						// How far the picture overhangs the frame on each axis:
						// an axis with nothing hidden has nothing to move.
						var fw = p.frame.clientWidth, fh = p.frame.clientHeight, nw = p.img.naturalWidth, nh = p.img.naturalHeight, sx = 0, sy = 0;
						if ( fw && fh && nw && nh ) { var k = Math.max( fw / nw, fh / nh ); sx = nw * k - fw; sy = nh * k - fh; }
						p.frame.setAttribute( 'data-sx', sx );
						p.frame.setAttribute( 'data-sy', sy );
						p.box.classList.toggle( 'no-x', sx < 1 );
						p.box.classList.toggle( 'no-y', sy < 1 );
					} );
				};
				hf.addEventListener( 'pointerdown', function ( e ) {
					var frame = e.target.closest( '[data-frame]' );
					if ( ! frame ) { return; }
					var dev = frame.closest( '[data-dev]' ).dataset.dev;
					// Dragging the phone frame is asking for a place of its own.
					if ( 'm' === dev && '1' !== own.value ) { own.value = '1'; draw(); }
					var p = parts( dev ), sx = parseFloat( frame.getAttribute( 'data-sx' ) ) || 0, sy = parseFloat( frame.getAttribute( 'data-sy' ) ) || 0;
					var x0 = e.clientX, y0 = e.clientY, vx = parseFloat( p.x.value ), vy = parseFloat( p.y.value );
					e.preventDefault();
					frame.setPointerCapture( e.pointerId );
					var move = function ( ev ) {
						// The picture follows the hand: dragging it right shows
						// more of its left side, which is a smaller percentage.
						if ( sx >= 1 ) { p.x.value = Math.round( Math.max( 0, Math.min( 100, vx - ( ev.clientX - x0 ) / sx * 100 ) ) ); }
						if ( sy >= 1 ) { p.y.value = Math.round( Math.max( 0, Math.min( 100, vy - ( ev.clientY - y0 ) / sy * 100 ) ) ); }
						draw();
					};
					var up = function () {
						frame.removeEventListener( 'pointermove', move );
						frame.removeEventListener( 'pointerup', up );
						frame.removeEventListener( 'pointercancel', up );
					};
					frame.addEventListener( 'pointermove', move );
					frame.addEventListener( 'pointerup', up );
					frame.addEventListener( 'pointercancel', up );
				} );
				hf.addEventListener( 'input', function ( e ) { if ( e.target.hasAttribute( 'data-axis' ) ) { draw(); } } );
				hf.addEventListener( 'click', function ( e ) {
					var b = e.target.closest( '[data-hf]' );
					if ( ! b ) { return; }
					e.preventDefault();
					var act = b.getAttribute( 'data-hf' ), dev = b.closest( '[data-dev]' ).dataset.dev, p = parts( dev );
					if ( 'own' === act ) { own.value = '1'; }
					if ( 'follow' === act ) { own.value = ''; }
					if ( 'centre' === act ) { if ( 'm' === dev ) { own.value = '1'; } p.x.value = 50; p.y.value = 50; }
					draw();
				} );
				hf.querySelectorAll( '[data-frame] img' ).forEach( function ( i ) { i.addEventListener( 'load', draw ); } );
				$( document ).on( 'change input', '[name="_oc_hero_layout"], [name="_oc_hero_text"], [name="_oc_hero_h"], [name="_oc_hero_hm"]', draw );
				$( document ).on( 'oc:img', draw );
				window.addEventListener( 'resize', draw );
				draw();
			}
			sync();
			vsel();
		} )( jQuery );
		</script>
		<?php
	}

	/* ---------------------------------------------------------- save */

	/**
	 * Save the category-page fields.
	 *
	 * @param int $term_id Term id.
	 */
	public function save( $term_id ): void {
		if ( ! current_user_can( 'manage_product_terms' ) && ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		// Core verifies the term-edit nonce before this fires.
		// phpcs:disable WordPress.Security.NonceVerification.Missing

		$this->save_enum( $term_id, '_oc_hero_layout', array( 'none', 'full', 'split' ) );
		$this->save_int( $term_id, '_oc_hero_img' );
		$this->save_int( $term_id, '_oc_hero_img_m' );
		$this->save_int( $term_id, '_oc_hero_h' );
		$this->save_int( $term_id, '_oc_hero_hm' );
		$this->save_enum( $term_id, '_oc_hero_text', array( 'over', 'below' ) );
		$this->save_enum( $term_id, '_oc_hero_pos', array_keys( self::positions() ) );
		$this->save_enum( $term_id, '_oc_hero_tone', array( 'light', 'dark' ) );
		$this->save_optional_int( $term_id, '_oc_hero_shade', 90 );
		$this->save_enum( $term_id, '_oc_hero_side', array( 'start', 'end' ) );
		$this->save_colour( $term_id, '_oc_hero_cbg' );
		$this->save_hero_focus( $term_id );

		$this->save_int( $term_id, '_oc_card_img' );

		$this->save_bool( $term_id, '_oc_sub_show' );
		$this->save_enum( $term_id, '_oc_sub_style', array( 'clean', 'pill', 'card' ) );
		$this->save_enum( $term_id, '_oc_sub_pill', array( 'round', 'rect' ) );
		$this->save_enum( $term_id, '_oc_sub_shape', array( 'square', 'portrait', 'circle' ) );
		$this->save_enum( $term_id, '_oc_sub_corners', array( 'sharp', 'soft' ) );
		$this->save_bool( $term_id, '_oc_sub_slider' );
		$this->save_enum( $term_id, '_oc_sub_slider_m', array( 'same', 'yes', 'no' ) );
		$this->save_enum( $term_id, '_oc_sub_place', array( 'out', 'in' ) );
		$this->save_enum( $term_id, '_oc_sub_place_m', array( 'out', 'in', 'same' ) );
		$this->save_enum( $term_id, '_oc_sub_align', array( 'start', 'center' ) );

		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/**
	 * The card-image row on a core (blog) category edit screen.
	 *
	 * @param \WP_Term $term Category.
	 */
	public function category_fields( $term ): void {
		if ( ! $term instanceof \WP_Term ) {
			return;
		}

		$card = absint( get_term_meta( $term->term_id, '_oc_card_img', true ) );
		?>
		<tr class="form-field oc-cat-sec">
			<th scope="row" colspan="2" style="padding-block-end:0">
				<h2 style="margin:22px 0 0;font-size:1.15em"><?php esc_html_e( 'Card image', 'oc-theme' ); ?></h2>
				<p class="description" style="font-weight:400"><?php esc_html_e( 'Shown for this category on the blog and in category strips.', 'oc-theme' ); ?></p>
			</th>
		</tr>
		<?php
		$this->image_field( '_oc_card_img', $card, __( 'Image', 'oc-theme' ) );
		$this->focus_field( $term->term_id );
		$this->admin_script();
	}

	/**
	 * Save a core-category card image.
	 *
	 * @param int $term_id Term id.
	 */
	public function category_save( $term_id ): void {
		if ( ! current_user_can( 'manage_categories' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- core verifies the term-edit nonce.
		$this->save_int( $term_id, '_oc_card_img' );
		$this->save_focus( $term_id );
	}

	/**
	 * Save the card image's position, or delete it when it is the centre.
	 *
	 * @param int $term_id Term id.
	 */
	private function save_focus( int $term_id ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- core verifies the term-edit nonce.
		$raw = isset( $_POST['_oc_card_focus'] ) ? sanitize_text_field( wp_unslash( $_POST['_oc_card_focus'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		if ( '' === $raw || 50 === (int) $raw ) {
			delete_term_meta( $term_id, '_oc_card_focus' );
		} else {
			update_term_meta( $term_id, '_oc_card_focus', max( 0, min( 100, (int) $raw ) ) );
		}
	}

	/**
	 * The card image's position slider, with a live 3:4 preview — the same
	 * control a product has for its tile.
	 *
	 * @param int $term_id Term id.
	 */
	private function focus_field( int $term_id ): void {
		$focus = self::card_focus( $term_id );
		$img   = self::card_image_id( $term_id );
		$src   = $img > 0 ? (string) wp_get_attachment_image_url( $img, 'large' ) : '';
		?>
		<tr class="form-field">
			<th scope="row"><label for="_oc_card_focus"><?php esc_html_e( 'Picture position', 'oc-theme' ); ?></label></th>
			<td>
				<div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
					<input type="range" id="_oc_card_focus" name="_oc_card_focus" min="0" max="100" step="1" value="<?php echo esc_attr( (string) $focus ); ?>" style="inline-size:220px;" data-oc-focus />
					<output id="_oc_card_focus_out" style="min-inline-size:44px;"><?php echo esc_html( (string) $focus ); ?>%</output>
					<button type="button" class="button-link" data-oc-focus-reset><?php esc_html_e( 'Centre', 'oc-theme' ); ?></button>
				</div>
				<p class="description"><?php esc_html_e( 'When the picture is taller than the tile it fills, this decides which part stays: 0 keeps the top (a head, a hat), 100 keeps the bottom.', 'oc-theme' ); ?></p>
				<div class="oc-focus-prev" style="inline-size:120px;aspect-ratio:3/4;overflow:hidden;border-radius:6px;margin-block-start:8px;background:#f0f0f1;<?php echo '' === $src ? 'display:none;' : ''; ?>">
					<img src="<?php echo esc_url( $src ); ?>" alt="" style="inline-size:100%;block-size:100%;object-fit:cover;object-position:50% <?php echo esc_attr( (string) $focus ); ?>%;" />
				</div>
			</td>
		</tr>
		<?php
	}

	/**
	 * The hero picture's position: a frame cut to the banner's real shape
	 * on each screen, the picture moving inside it as it is dragged or the
	 * sliders move — so what the page will keep is what the screen shows.
	 *
	 * @param int                 $term_id Term id.
	 * @param array<string,mixed> $g       Shop-wide hero defaults.
	 * @param string              $gate    data-oc-when rule for the row.
	 */
	private function hero_focus_field( int $term_id, array $g, string $gate ): void {
		$get = static function ( string $key ) use ( $term_id ): string {
			return (string) get_term_meta( $term_id, $key, true );
		};

		$pct = static function ( string $v, int $def ): int {
			return '' === $v ? $def : max( 0, min( 100, (int) $v ) );
		};

		$fx  = $pct( $get( '_oc_hero_fx' ), 50 );
		$fy  = $pct( $get( '_oc_hero_fy' ), 50 );
		$own = '' !== $get( '_oc_hero_fxm' ) || '' !== $get( '_oc_hero_fym' );

		$screens = array(
			'd' => array( __( 'Desktop', 'oc-theme' ), '_oc_hero_fx', '_oc_hero_fy', $fx, $fy ),
			'm' => array( __( 'Mobile', 'oc-theme' ), '_oc_hero_fxm', '_oc_hero_fym', $pct( $get( '_oc_hero_fxm' ), $fx ), $pct( $get( '_oc_hero_fym' ), $fy ) ),
		);
		?>
		<tr class="form-field" data-oc-when="<?php echo esc_attr( $gate ); ?>">
			<th scope="row"><label><?php esc_html_e( 'Picture position', 'oc-theme' ); ?></label></th>
			<td>
				<div class="oc-hfocus" data-oc-hfocus data-glayout="<?php echo esc_attr( (string) $g['layout'] ); ?>" data-gtext="<?php echo esc_attr( (string) $g['text'] ); ?>" data-gh="<?php echo esc_attr( (string) $g['h'] ); ?>" data-ghm="<?php echo esc_attr( (string) $g['hm'] ); ?>">
					<input type="hidden" name="_oc_hero_fm" value="<?php echo esc_attr( $own ? '1' : '' ); ?>">
					<p class="oc-hfocus__none"><?php esc_html_e( 'Choose a hero image first.', 'oc-theme' ); ?></p>
					<?php foreach ( $screens as $key => $screen ) : ?>
						<div class="oc-hfocus__dev" data-dev="<?php echo esc_attr( $key ); ?>">
							<div class="oc-hfocus__cap">
								<?php echo esc_html( $screen[0] ); ?>
								<?php if ( 'm' === $key ) : ?>
									<span class="oc-hfocus__same"><?php esc_html_e( 'Same as desktop', 'oc-theme' ); ?></span>
									<button type="button" class="button-link oc-hfocus__own" data-hf="own"><?php esc_html_e( 'Give mobile its own', 'oc-theme' ); ?></button>
									<button type="button" class="button-link oc-hfocus__follow" data-hf="follow"><?php esc_html_e( 'Follow desktop', 'oc-theme' ); ?></button>
								<?php endif; ?>
							</div>
							<div class="oc-hfocus__frame" data-frame><img alt="" draggable="false"></div>
							<div class="oc-hfocus__row" data-row="x">
								<span><?php esc_html_e( 'Side to side', 'oc-theme' ); ?></span>
								<input type="range" dir="ltr" min="0" max="100" step="1" name="<?php echo esc_attr( $screen[1] ); ?>" value="<?php echo esc_attr( (string) $screen[3] ); ?>" data-axis="x">
								<output data-out="x"></output>
							</div>
							<div class="oc-hfocus__row" data-row="y">
								<span><?php esc_html_e( 'Up and down', 'oc-theme' ); ?></span>
								<input type="range" dir="ltr" min="0" max="100" step="1" name="<?php echo esc_attr( $screen[2] ); ?>" value="<?php echo esc_attr( (string) $screen[4] ); ?>" data-axis="y">
								<output data-out="y"></output>
							</div>
							<button type="button" class="button-link" data-hf="centre"><?php esc_html_e( 'Centre', 'oc-theme' ); ?></button>
						</div>
					<?php endforeach; ?>
				</div>
				<p class="description"><?php esc_html_e( 'Drag the picture, or use the sliders, to choose what stays in view. Each frame is cut to the banner’s real shape on that screen.', 'oc-theme' ); ?></p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Save a number the category may leave to the default: empty deletes,
	 * anything else — zero included — is kept.
	 *
	 * @param int    $term_id Term id.
	 * @param string $key     Meta key / POST key.
	 * @param int    $max     Largest kept value.
	 */
	private function save_optional_int( int $term_id, string $key, int $max ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- cast below.
		$raw = isset( $_POST[ $key ] ) ? trim( (string) wp_unslash( $_POST[ $key ] ) ) : '';

		if ( '' === $raw || ! is_numeric( $raw ) ) {
			delete_term_meta( $term_id, $key );
		} else {
			update_term_meta( $term_id, $key, (string) max( 0, min( $max, (int) $raw ) ) );
		}
	}

	/**
	 * Save the hero picture's position: desktop always, mobile only when it
	 * was given a place of its own.
	 *
	 * @param int $term_id Term id.
	 */
	private function save_hero_focus( int $term_id ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- core verified the term-edit nonce; values are cast.
		$read = static function ( string $key ): ?int {
			if ( ! isset( $_POST[ $key ] ) || ! is_numeric( wp_unslash( $_POST[ $key ] ) ) ) {
				return null;
			}

			return max( 0, min( 100, (int) wp_unslash( $_POST[ $key ] ) ) );
		};

		$own = isset( $_POST['_oc_hero_fm'] ) && '1' === (string) wp_unslash( $_POST['_oc_hero_fm'] );
		// phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		foreach ( array( '_oc_hero_fx', '_oc_hero_fy' ) as $key ) {
			$v = $read( $key );

			if ( null === $v || 50 === $v ) {
				delete_term_meta( $term_id, $key );
			} else {
				update_term_meta( $term_id, $key, (string) $v );
			}
		}

		foreach ( array( '_oc_hero_fxm', '_oc_hero_fym' ) as $key ) {
			$v = $own ? $read( $key ) : null;

			if ( null === $v ) {
				delete_term_meta( $term_id, $key );
			} else {
				update_term_meta( $term_id, $key, (string) $v );
			}
		}
	}

	/**
	 * Save one of a fixed set of values, or delete when not one of them.
	 *
	 * @param int      $term_id Term id.
	 * @param string   $key     Meta key / POST key.
	 * @param string[] $allowed Accepted values.
	 */
	private function save_enum( int $term_id, string $key, array $allowed ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised below.
		$value = isset( $_POST[ $key ] ) ? sanitize_key( wp_unslash( $_POST[ $key ] ) ) : '';

		if ( in_array( $value, $allowed, true ) ) {
			update_term_meta( $term_id, $key, $value );
		} else {
			delete_term_meta( $term_id, $key );
		}
	}

	/**
	 * Save a non-negative integer, or delete when empty / zero.
	 *
	 * @param int    $term_id Term id.
	 * @param string $key  Meta key / POST key.
	 * @param int    $min  Minimum kept value.
	 * @param int    $max  Maximum kept value.
	 */
	private function save_int( int $term_id, string $key, int $min = 1, int $max = 100000 ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$raw = isset( $_POST[ $key ] ) ? absint( wp_unslash( $_POST[ $key ] ) ) : 0;

		if ( $raw >= $min ) {
			update_term_meta( $term_id, $key, (string) min( $max, $raw ) );
		} else {
			delete_term_meta( $term_id, $key );
		}
	}

	/**
	 * Save a checkbox as '1', or delete when unchecked.
	 *
	 * @param int    $term_id Term id.
	 * @param string $key     Meta key / POST key.
	 */
	private function save_bool( int $term_id, string $key ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- strict comparison against a literal.
		if ( isset( $_POST[ $key ] ) && '1' === (string) wp_unslash( $_POST[ $key ] ) ) {
			update_term_meta( $term_id, $key, '1' );
		} else {
			delete_term_meta( $term_id, $key );
		}
	}

	/**
	 * Save a CSS colour string, or delete when empty.
	 *
	 * @param int    $term_id Term id.
	 * @param string $key     Meta key / POST key.
	 */
	private function save_colour( int $term_id, string $key ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised below.
		$raw = isset( $_POST[ $key ] ) ? trim( (string) wp_unslash( $_POST[ $key ] ) ) : '';
		$ok  = preg_match( '/^#([0-9a-f]{3}|[0-9a-f]{6})$|^(rgb|rgba|hsl|hsla)\([0-9.,%\s\/]+\)$/i', $raw );

		if ( $ok ) {
			update_term_meta( $term_id, $key, $raw );
		} else {
			delete_term_meta( $term_id, $key );
		}
	}
}
