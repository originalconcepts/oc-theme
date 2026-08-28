<?php
/**
 * Front rendering: sections into HTML, assets only where they are needed.
 *
 * A composed page replaces its own content with its sections. Rendering is
 * plain server HTML — no builder runtime travels to the visitor, and the
 * only JavaScript is the small shared behaviour file (sliders, entrances,
 * the marquee), written once for every block.
 *
 * @package OC_Blocks
 */

declare( strict_types = 1 );

namespace OC\Blocks;

defined( 'ABSPATH' ) || exit;

/**
 * Sections on the page.
 */
final class Render {

	/**
	 * Whether this request printed sections (so the assets know to come).
	 *
	 * @var bool
	 */
	private static $used = false;

	/**
	 * True while rendering the page's first visible section — its hero
	 * image is the likely LCP and deserves fetchpriority over lazy.
	 *
	 * @var bool
	 */
	private static $first_section = false;

	/**
	 * Hook in.
	 */
	public function register(): void {
		add_filter( 'the_content', array( $this, 'compose' ), 9 );
		add_filter( 'body_class', array( $this, 'body_class' ) );
		add_action( 'wp_head', array( $this, 'preload_hero' ), 2 );
		add_action( 'wp_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'integration_assets' ), 15 );

		// Deferred shelves (recently-viewed, catalogue sliders): filled in
		// after load so the page itself stays cacheable.
		add_action( 'wp_ajax_oc_shelf', array( $this, 'ajax_shelf' ) );
		add_action( 'wp_ajax_nopriv_oc_shelf', array( $this, 'ajax_shelf' ) );

		// A category may nominate a composed page as its lobby, shown above
		// the products: "the women's front page" is just another page.
		add_action( 'product_cat_edit_form_fields', array( $this, 'lobby_field' ), 15 );
		add_action( 'edited_product_cat', array( $this, 'save_lobby' ) );
		add_action( 'woocommerce_archive_description', array( $this, 'lobby' ), 5 );

		add_action( 'save_post', array( $this, 'flush' ) );

		// Category edits change what the categories block and product sliders
		// show (e.g. a new card image), so they must turn the cache too.
		add_action( 'edited_product_cat', array( $this, 'flush' ) );
		add_action( 'edited_category', array( $this, 'flush' ) );
	}

	/**
	 * A class on the body of a composed page — the hero is the title there,
	 * so the theme's own heading and breadcrumb step aside (see blocks.css).
	 *
	 * @param array<int,string> $classes Body classes.
	 * @return array<int,string>
	 */
	public function body_class( array $classes ): array {
		// Only a composed PAGE takes over its title/breadcrumb — a post keeps
		// its heading, and a product keeps its whole layout (the sections land
		// inside the description).
		if ( is_page() && self::is_composed( (int) get_queried_object_id() ) ) {
			$classes[] = 'oc-composed';
		}

		return $classes;
	}

	/**
	 * Whether a page was built with the composer.
	 *
	 * @param int $page_id Page id.
	 */
	public static function is_composed( int $page_id ): bool {
		$raw = get_post_meta( $page_id, Registry::META, true );

		return is_array( $raw ) && ! empty( $raw );
	}

	/**
	 * The post types the composer may build. Pages, posts and products —
	 * a product's sections take over its full description (the_content).
	 *
	 * @return array<int,string>
	 */
	public static function composable_types(): array {
		return array_values( (array) apply_filters( 'oc_blocks_post_types', array( 'page', 'post', 'product' ) ) );
	}

	/**
	 * A composed page shows its sections in place of its content.
	 *
	 * @param string $content Content.
	 * @return string
	 */
	public function compose( $content ) {
		if ( ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}

		$page_id = get_the_ID();

		if ( ! $page_id
			|| ! in_array( get_post_type( $page_id ), self::composable_types(), true )
			|| ! self::is_composed( (int) $page_id ) ) {
			return $content;
		}

		return self::page_html( (int) $page_id );
	}

	/**
	 * A page's sections, rendered and cached.
	 *
	 * @param int $page_id Page id.
	 * @return string
	 */
	public static function page_html( int $page_id ): string {
		self::$used = true;

		$key    = 'oc_compose_' . $page_id . '_' . self::version();
		$cached = get_transient( $key );

		if ( is_string( $cached ) ) {
			return $cached;
		}

		$html = self::sections_html( Registry::sections( $page_id ) );

		set_transient( $key, $html, DAY_IN_SECONDS );

		return $html;
	}

	/**
	 * Deploys and edits both start a fresh cache generation.
	 */
	private static function version(): string {
		return (string) get_option( 'oc_blocks_ver', 0 ) . '-' . (string) filemtime( __FILE__ );
	}

	/**
	 * Saving a composed page turns the cache generation.
	 */
	public function flush(): void {
		update_option( 'oc_blocks_ver', (int) get_option( 'oc_blocks_ver', 0 ) + 1, false );
	}

	/**
	 * Sections into HTML.
	 *
	 * @param array<int,array<string,mixed>> $sections Sections.
	 * @return string
	 */
	public static function sections_html( array $sections ): string {
		self::$used = true;

		$out = '';

		foreach ( array_values( $sections ) as $at => $section ) {
			if ( empty( $section['on'] ) ) {
				continue;
			}

			self::$first_section = ( '' === $out );

			$inner = self::block( $section );

			if ( '' === trim( $inner ) ) {
				continue;
			}

			$out .= self::wrap( $section, $inner, $at );
		}

		return '' === $out ? '' : '<div class="oc-compose">' . $out . '</div>';
	}

	/**
	 * The shell around one section: width, background, spacing, entrance.
	 *
	 * @param array<string,mixed> $s     Section.
	 * @param string              $inner Block HTML.
	 * @return string
	 */
	private static function wrap( array $s, string $inner, int $at = -1 ): string {
		$type    = (string) $s['type'];
		$classes = array(
			'ocb',
			'ocb--' . sanitize_html_class( $type ),
			'ocb--w-' . sanitize_html_class( (string) $s['w'] ),
			'ocb--pt-' . sanitize_html_class( (string) $s['pt'] ),
			'ocb--pb-' . sanitize_html_class( (string) $s['pb'] ),
			'ocb--dev-' . sanitize_html_class( (string) $s['dev'] ),
		);

		if ( 'none' !== $s['enter'] ) {
			$classes[] = 'ocb--in-' . sanitize_html_class( (string) $s['enter'] );
		}

		$style = '';

		if ( 'custom' === $s['w'] ) {
			$style .= '--ocb-w:' . absint( $s['wpx'] ) . 'px;';
		}

		$bg = '';

		switch ( (string) $s['bg'] ) {
			case 'color':
				if ( '' !== $s['bg1'] ) {
					$style .= '--ocb-bg:' . $s['bg1'] . ';';
					$classes[] = 'ocb--bg';
				}
				break;

			case 'gradient':
				if ( '' !== $s['bg1'] && '' !== $s['bg2'] ) {
					$style .= '--ocb-bg:linear-gradient(' . absint( $s['bga'] ) . 'deg,' . $s['bg1'] . ',' . $s['bg2'] . ');';
					$classes[] = 'ocb--bg';
				}
				break;

			case 'image':
				$url = $s['bgimg'] > 0 ? (string) wp_get_attachment_image_url( (int) $s['bgimg'], 'full' ) : '';

				if ( '' !== $url ) {
					$bg = '<div class="ocb__bg" style="background-image:url(' . esc_url( $url ) . ')"></div>';
				}
				break;

			case 'video':
				if ( '' !== $s['bgvid'] ) {
					$bg = '<div class="ocb__bg"><video src="' . esc_url( (string) $s['bgvid'] ) . '" autoplay muted loop playsinline preload="metadata"></video></div>';
				}
				break;
		}

		if ( '' !== $bg && $s['overlay'] > 0 ) {
			$bg .= '<div class="ocb__shade" style="opacity:' . ( absint( $s['overlay'] ) / 100 ) . '"></div>';
		}

		return '<section class="' . esc_attr( implode( ' ', $classes ) ) . '"'
			. ( $at < 0 ? '' : ' data-ocb-n="' . $at . '"' )
			. ( '' === $style ? '' : ' style="' . esc_attr( $style ) . '"' ) . '>'
			. $bg
			. '<div class="ocb__in">' . $inner . '</div>'
			. '</section>';
	}

	/**
	 * One block's inner HTML, by type.
	 *
	 * @param array<string,mixed> $s Section.
	 * @return string
	 */
	private static function block( array $s ): string {
		switch ( (string) $s['type'] ) {
			case 'hero':
				return self::hero( $s );
			case 'content':
				return self::content( $s );
			case 'products':
				return self::products( $s );
			case 'categories':
				return self::categories( $s );
			case 'marquee':
				return self::marquee( $s );
			case 'brands':
				return self::brands( $s );
			case 'posts':
				return self::posts( $s );
			case 'look':
				return self::look( $s );
			case 'media':
				return self::media( $s );
			case 'scrolly':
				return self::scrolly( $s );
			case 'story':
				return self::story( $s );
			case 'reviews':
				return self::reviews( $s );
			case 'faq':
				return self::faq( $s );
			case 'logos':
				return self::logos( $s );
			case 'news':
				return self::news( $s );
			case 'countdown':
				return self::countdown( $s );
			case 'branches':
				return self::branches( $s );
			case 'contact':
				return self::contact( $s );
			case 'icons':
				return self::icons( $s );
		}

		/**
		 * Markup for a section type this class does not know.
		 *
		 * @param string              $html Markup.
		 * @param array<string,mixed> $s    Section.
		 */
		return (string) apply_filters( 'oc_blocks_html', '', $s );
	}

	/**
	 * A shared heading over a shelf.
	 *
	 * @param array<string,mixed> $s Section.
	 * @return string
	 */
	private static function heading( array $s ): string {
		$text = trim( (string) ( $s['heading'] ?? '' ) );

		return '' === $text ? '' : '<h2 class="ocb__title">' . esc_html( $text ) . '</h2>';
	}

	/*
	 * ------------------------------------------------------------- blocks
	 */

	/**
	 * Banner / slider. The pictures travel in the strip; the words and the
	 * clock live in an overlay of their own, one set per slide, entering
	 * with a small rise instead of scrolling along.
	 *
	 * @param array<string,mixed> $s Section.
	 */
	private static function hero( array $s ): string {
		$slides = array();
		$sets   = array();

		foreach ( (array) $s['slides'] as $slide ) {
			$media = '';

			if ( '' !== $slide['vid'] ) {
				$media = '<video src="' . esc_url( (string) $slide['vid'] ) . '" autoplay muted loop playsinline preload="metadata"></video>';
			} elseif ( $slide['img'] > 0 ) {
				$meta = wp_get_attachment_image_src( (int) $slide['img'], 'full' );
				$dsk  = is_array( $meta ) ? (string) $meta[0] : '';

				if ( '' === $dsk ) {
					continue;
				}

				// Responsive candidates, so a phone never pays for the
				// desktop original; the first slide of the page's first
				// section is the LCP and asks for priority.
				$set  = (string) wp_get_attachment_image_srcset( (int) $slide['img'], 'full' );
				$dims = ' width="' . absint( $meta[1] ) . '" height="' . absint( $meta[2] ) . '"';
				$lcp  = self::$first_section && 0 === count( $slides );

				$mob     = '';
				$mob_set = '';

				if ( $slide['imgm'] > 0 ) {
					$mob     = (string) wp_get_attachment_image_url( (int) $slide['imgm'], 'large' );
					$mob_set = (string) wp_get_attachment_image_srcset( (int) $slide['imgm'], 'full' );
				}

				$media = '<picture>'
					. ( '' === $mob ? '' : '<source media="(max-width: 782px)" srcset="' . esc_attr( '' !== $mob_set ? $mob_set : esc_url( $mob ) ) . '"' . ( '' === $mob_set ? '' : ' sizes="100vw"' ) . '>' )
					. '<img src="' . esc_url( $dsk ) . '"'
					. ( '' === $set ? '' : ' srcset="' . esc_attr( $set ) . '" sizes="100vw"' )
					. $dims
					. ( $lcp ? ' fetchpriority="high"' : '' )
					. ' decoding="async" alt="' . esc_attr( (string) $slide['heading'] ) . '">'
					. '</picture>';
			} else {
				continue;
			}

			$at = count( $slides );

			// A slide may carry a ticking clock — under the words, or held
			// in the middle of the banner's other half.
			$cd = '';

			if ( '' !== trim( (string) ( $slide['cd'] ?? '' ) ) ) {
				$moment = date_create( trim( (string) $slide['cd'] ), wp_timezone() );

				if ( $moment && $moment->getTimestamp() > time() ) {
					$cells = '';

					foreach ( array(
						'd' => __( 'Days', 'oc-blocks' ),
						'h' => __( 'Hours', 'oc-blocks' ),
						'm' => __( 'Minutes', 'oc-blocks' ),
						's' => __( 'Seconds', 'oc-blocks' ),
					) as $unit => $label ) {
						$cells .= '<span class="ocb-hero__cd-cell"><b data-ocb-cd-u="' . esc_attr( $unit ) . '">&nbsp;</b><i>' . esc_html( $label ) . '</i></span>';
					}

					$cd = '<div class="ocb-hero__cd ocb-hero__cd--' . esc_attr( (string) $s['cdpos'] ) . '" data-ocb-cd="' . esc_attr( (string) $moment->getTimestamp() ) . '" data-ocb-cd-in>' . $cells . '</div>';
				}
			}

			$words = '';

			if ( '' !== $slide['heading'] || '' !== $slide['text'] || '' !== $slide['cta'] || '' !== $cd ) {
				$under = 'under' === (string) $s['cdpos'] ? $cd : '';
				$aside = 'under' === (string) $s['cdpos'] ? '' : $cd;

				$words = '<div class="ocb-hero__set' . ( 0 === $at ? ' is-on' : '' ) . '" data-ocb-set="' . $at . '">'
					. '<div class="ocb-hero__words">'
					. ( '' === $slide['heading'] ? '' : '<h2>' . esc_html( (string) $slide['heading'] ) . '</h2>' )
					. ( '' === $slide['text'] ? '' : '<p>' . esc_html( (string) $slide['text'] ) . '</p>' )
				. ( '' === $slide['cta'] || '' === $slide['url'] ? '' : '<a class="ocb-hero__cta" href="' . esc_url( (string) $slide['url'] ) . '">' . esc_html( (string) $slide['cta'] ) . '</a>' )
					. ( '' === trim( (string) ( $slide['cta2'] ?? '' ) ) || '' === (string) ( $slide['url2'] ?? '' ) ? '' : '<a class="ocb-hero__cta ocb-hero__cta--ghost" href="' . esc_url( (string) $slide['url2'] ) . '">' . esc_html( (string) $slide['cta2'] ) . '</a>' )
					. $under
					. '</div>'
					. $aside
					. '</div>';
			}

			$sets[] = $words;

			// At full strength the picture stands still and the page glides
			// over it: a fixed layer, clipped to its own slide. Not in fade
			// mode — browsers refuse to paint a fixed layer inside a parent
			// whose opacity is animating, and the slide went blank.
			$fixed = 100 === (int) $s['parallax'] && 'fade' !== (string) $s['effect'];

			$open  = '' !== $slide['url'] && '' === $slide['cta']
				? '<a class="ocb-hero__slide' . ( $fixed ? ' ocb-hero__slide--fixedbg' : '' ) . ( 0 === $at ? ' is-on' : '' ) . '" href="' . esc_url( (string) $slide['url'] ) . '">'
				: '<div class="ocb-hero__slide' . ( $fixed ? ' ocb-hero__slide--fixedbg' : '' ) . ( 0 === $at ? ' is-on' : '' ) . '">';
			$close = '' !== $slide['url'] && '' === $slide['cta'] ? '</a>' : '</div>';

			$slides[] = $open
				. '<div class="ocb-hero__media' . ( $fixed ? ' ocb-hero__media--fixed' : '' ) . '"' . ( empty( $s['parallax'] ) || $fixed ? '' : ' data-ocb-parallax="' . absint( $s['parallax'] ) . '"' ) . '>' . $media . '</div>'
				. ( $s['shade'] > 0 ? '<div class="ocb-hero__shade" style="opacity:' . ( absint( $s['shade'] ) / 100 ) . '"></div>' : '' )
				. $close;
		}

		if ( empty( $slides ) ) {
			return '';
		}

		$one = count( $slides ) < 2;

		// The fixed layer is honest only on a single-slide banner: in a
		// moving strip every slide's fixed picture holds the same spot on
		// the screen, and sliding between them reads as a broken crossfade.
		// A slider at full strength gets the strong drift instead.
		if ( ! $one ) {
			$slides = str_replace(
				array( ' ocb-hero__slide--fixedbg', 'ocb-hero__media ocb-hero__media--fixed"' ),
				array( '', 'ocb-hero__media" data-ocb-parallax="100"' ),
				$slides
			);
		}

		// "Natural height" means the first picture's own proportions: the
		// banner takes that ratio and every other slide is cropped to it,
		// so the strip never inherits a stray tall video's height.
		$ratio  = '';
		$ratiom = '';

		foreach ( (array) $s['slides'] as $slide ) {
			if ( absint( $slide['img'] ?? 0 ) > 0 && '' === $ratio ) {
				$src = wp_get_attachment_image_src( absint( $slide['img'] ), 'full' );

				if ( $src && $src[1] > 0 && $src[2] > 0 ) {
					$ratio = $src[1] . ' / ' . $src[2];
				}
			}

			if ( absint( $slide['imgm'] ?? 0 ) > 0 && '' === $ratiom ) {
				$srcm = wp_get_attachment_image_src( absint( $slide['imgm'] ), 'full' );

				if ( $srcm && $srcm[1] > 0 && $srcm[2] > 0 ) {
					$ratiom = $srcm[1] . ' / ' . $srcm[2];
				}
			}
		}

		if ( '' === $ratiom ) {
			$ratiom = $ratio;
		}

		$style = '--ocb-hero-h:' . absint( $s['h'] ) . 'px;--ocb-hero-hm:' . absint( $s['hm'] ) . 'px;'
			. ( '' === $ratio ? '' : '--ocb-hero-ratio:' . $ratio . ';' )
			. ( '' === $ratiom ? '' : '--ocb-hero-ratio-m:' . $ratiom . ';' )
			. ( '' === (string) ( $s['fadebg'] ?? '' ) ? '' : '--ocb-hero-fadebg:' . $s['fadebg'] . ';' )
			. ( '' === (string) ( $s['txtc'] ?? '' ) ? '' : '--ocb-hero-txt:' . $s['txtc'] . ';' )
			. ( '' === (string) ( $s['ctac'] ?? '' ) ? '' : '--ocb-hero-ctabg:' . $s['ctac'] . ';' )
			. ( '' === (string) ( $s['ctat'] ?? '' ) ? '' : '--ocb-hero-ctaink:' . $s['ctat'] . ';' );

		$html = '<div class="ocb-hero ocb-hero--' . esc_attr( (string) $s['effect'] ) . ' ocb-hero--pos-' . esc_attr( (string) $s['pos'] ) . ' ocb-hero--' . esc_attr( (string) $s['tone'] ) . ( $one ? ' ocb-hero--one' : '' ) . ( 0 === absint( $s['h'] ) && '' !== $ratio ? ' ocb-hero--hauto' : '' ) . ( 0 === absint( $s['hm'] ) && '' !== $ratiom ? ' ocb-hero--hmauto' : '' ) . '"'
			. ' style="' . esc_attr( $style ) . '"'
			. ( $one || empty( $s['auto'] ) ? '' : ' data-ocb-auto="' . absint( $s['auto'] ) . '"' ) . '>'
			. '<div class="ocb-hero__strip">' . implode( '', $slides ) . '</div>'
			. '<div class="ocb-hero__sets">' . implode( '', $sets ) . '</div>';

		if ( ! $one && ! empty( $s['arrows'] ) ) {
			$html .= '<button type="button" class="ocb-arr ocb-arr--prev" data-ocb-go="-1" aria-label="prev"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14.5 5l-7 7 7 7"/></svg></button>'
				. '<button type="button" class="ocb-arr ocb-arr--next" data-ocb-go="1" aria-label="next"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9.5 5l7 7-7 7"/></svg></button>';
		}

		if ( ! $one && ! empty( $s['dots'] ) ) {
			$html .= '<div class="ocb-dots" data-ocb-dots></div>';
		}

		return $html . '</div>';
	}


	/**
	 * Words: a heading, a few lines, a button.
	 *
	 * @param array<string,mixed> $s Section.
	 * @return string
	 */
	private static function content( array $s ): string {
		$out = '';

		if ( '' !== trim( (string) ( $s['eyebrow'] ?? '' ) ) ) {
			$out .= '<span class="ocb-ed__eyebrow">' . esc_html( (string) $s['eyebrow'] ) . '</span>';
		}

		if ( '' !== $s['heading'] ) {
			$out .= '<h2>' . esc_html( (string) $s['heading'] ) . '</h2>';
		}

		if ( '' !== $s['text'] ) {
			$out .= '<div class="ocb-words__text">' . wpautop( esc_html( (string) $s['text'] ) ) . '</div>';
		}

		if ( '' !== $s['cta'] && '' !== $s['url'] ) {
			$out .= '<a class="ocb-btn ocb-btn--' . esc_attr( (string) $s['btn'] ) . '" href="' . esc_url( (string) $s['url'] ) . '">' . esc_html( (string) $s['cta'] ) . '</a>';
		}

		return '' === $out ? '' : '<div class="ocb-words ocb-words--' . esc_attr( (string) $s['align'] ) . '">' . $out . '</div>';
	}

	/**
	 * Products: the theme's own cards, on a shelf.
	 *
	 * @param array<string,mixed> $s Section.
	 * @return string
	 */
	private static function products( array $s ): string {
		return self::product_shelf(
			array(
				'heading' => (string) ( $s['heading'] ?? '' ),
				'mode'    => (string) $s['mode'],
				'cat'     => absint( $s['cat'] ?? 0 ),
				'picks'   => (array) ( $s['picks'] ?? array() ),
				'count'   => absint( $s['count'] ),
				'layout'  => (string) $s['layout'],
				'cols'    => absint( $s['cols'] ),
				'gap'     => (string) $s['gap'],
				'mcols'   => (string) ( $s['mcols'] ?? '1' ),
				'all'     => ! empty( $s['all'] ),
				'allurl'  => (string) ( $s['allurl'] ?? '' ),
			)
		);
	}

	/**
	 * A shelf of products — grid or slider — drawn with the theme's own cards.
	 * Shared by the Products block and the category product-slider band.
	 *
	 * Options: heading, mode (cat|manual|sales|new|sale|viewed), cat (term id
	 * or WP_Term), picks (ids), ids (ids, alias of picks), count, layout, cols,
	 * gap, mcols, all (bool), allurl, exclude (id to drop, e.g. the current
	 * product).
	 *
	 * @param array<string,mixed> $o Options.
	 * @return string
	 */
	public static function product_shelf( array $o ): string {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return '';
		}

		$count = max( 1, absint( $o['count'] ?? 8 ) );
		$cols  = max( 2, absint( $o['cols'] ?? 4 ) );
		$mode  = (string) ( $o['mode'] ?? 'new' );
		$cat   = null;

		// A shelf can be deferred to a JS fetch after load: always for the
		// per-visitor "viewed" source (so the page stays cacheable), and on
		// request via $o['defer'] (the catalogue-layout slider defers so it
		// never runs a nested product query mid-loop). The ajax call resolves
		// it for real.
		if ( ( 'viewed' === $mode || ! empty( $o['defer'] ) ) && ! wp_doing_ajax() ) {
			return self::shelf_placeholder( $o, $count, $cols );
		}

		$args = array(
			'status'  => 'publish',
			'limit'   => $count,
			'orderby' => 'date',
			'order'   => 'DESC',
			'return'  => 'ids',
		);

		switch ( $mode ) {
			case 'manual':
				$picks = array_filter( array_map( 'absint', (array) ( $o['picks'] ?? $o['ids'] ?? array() ) ) );

				if ( empty( $picks ) ) {
					return '';
				}

				$args['include'] = $picks;
				$args['orderby'] = 'post__in';
				break;

			case 'sales':
				$args['orderby'] = 'popularity';
				break;

			case 'sale':
				$on_sale = wc_get_product_ids_on_sale();

				if ( empty( $on_sale ) ) {
					return '';
				}

				$args['include'] = array_map( 'absint', $on_sale );
				break;

			case 'viewed':
				$viewed = self::recently_viewed_ids( $count, absint( $o['exclude'] ?? 0 ) );

				if ( empty( $viewed ) ) {
					return '';
				}

				$args['include'] = $viewed;
				$args['orderby'] = 'post__in';
				break;

			case 'cat':
				$cat = $o['cat'] instanceof \WP_Term ? $o['cat'] : get_term( absint( $o['cat'] ?? 0 ), 'product_cat' );

				if ( ! $cat instanceof \WP_Term ) {
					return '';
				}

				$args['category'] = array( $cat->slug );
				break;
		}

		$ids = wc_get_products( $args );

		if ( ! empty( $o['exclude'] ) ) {
			$ids = array_values( array_diff( array_map( 'absint', (array) $ids ), array( absint( $o['exclude'] ) ) ) );
		}

		if ( empty( $ids ) ) {
			return '';
		}

		// The theme's own loop draws the cards, so a products shelf here is
		// pixel-for-pixel the catalogue — labels, galleries, quick pick.
		$shortcode = '[products ids="' . implode( ',', array_map( 'absint', $ids ) ) . '" columns="' . $cols . '" orderby="post__in" limit="' . count( $ids ) . '"]';
		$cards     = do_shortcode( $shortcode );

		$more = '';

		if ( ! empty( $o['all'] ) ) {
			$url = (string) ( $o['allurl'] ?? '' );

			if ( '' === $url && $cat instanceof \WP_Term ) {
				$link = get_term_link( $cat );
				$url  = is_wp_error( $link ) ? '' : (string) $link;
			}

			if ( '' === $url ) {
				$url = (string) wc_get_page_permalink( 'shop' );
			}

			if ( '' !== $url ) {
				$more = '<p class="ocb__more"><a class="ocb-btn ocb-btn--theme" href="' . esc_url( $url ) . '">' . esc_html__( 'All products', 'oc-blocks' ) . '</a></p>';
			}
		}

		$layout = 'grid' === (string) ( $o['layout'] ?? 'slider' ) ? 'grid' : 'slider';
		$gap    = (string) ( $o['gap'] ?? 'normal' );
		$mcols  = '' !== (string) ( $o['mcols'] ?? '' ) ? (string) $o['mcols'] : '1';

		$htext  = trim( (string) ( $o['heading'] ?? '' ) );
		$halign = (string) ( $o['halign'] ?? '' );
		// Explicit choice wins; unset keeps the default centred heading.
		$hcls   = 'center' === $halign ? ' ocb__title--center' : ( 'start' === $halign ? ' ocb__title--start' : '' );
		$head   = '' === $htext ? '' : '<h2 class="ocb__title' . $hcls . '">' . esc_html( $htext ) . '</h2>';

		return $head
			. '<div class="ocb-shelf ocb-shelf--' . esc_attr( $layout ) . ' ocb-shelf--gap-' . esc_attr( $gap ) . ' ocb-shelf--m' . esc_attr( $mcols ) . '" style="--ocb-cols:' . $cols . '"'
			. ( 'slider' === $layout ? ' data-ocb-shelf' : '' ) . '>'
			. $cards
			. ( 'slider' === $layout ? self::shelf_arrows() : '' )
			. '</div>' . $more;
	}

	/**
	 * Recently-viewed product ids, newest first, from the WooCommerce cookie.
	 *
	 * @param int $limit   How many.
	 * @param int $exclude An id to drop (e.g. the current product).
	 * @return int[]
	 */
	private static function recently_viewed_ids( int $limit, int $exclude = 0 ): array {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- ids are absint-ed below.
		$raw = isset( $_COOKIE['woocommerce_recently_viewed'] ) ? (string) $_COOKIE['woocommerce_recently_viewed'] : '';

		if ( '' === $raw ) {
			return array();
		}

		// WooCommerce stores pipe-separated ids, newest last.
		$ids = array_reverse( array_filter( array_map( 'absint', explode( '|', $raw ) ) ) );
		$ids = array_values( array_unique( $ids ) );

		if ( $exclude > 0 ) {
			$ids = array_values( array_diff( $ids, array( $exclude ) ) );
		}

		return array_slice( $ids, 0, max( 1, $limit ) );
	}

	/**
	 * The empty stand-in for a deferred shelf; JS fetches the real thing after
	 * load. Carries the (admin-set) config so the ajax call can rebuild it.
	 *
	 * @param array<string,mixed> $o     Options.
	 * @param int                 $count How many.
	 * @param int                 $cols  Columns.
	 * @return string
	 */
	private static function shelf_placeholder( array $o, int $count, int $cols ): string {
		$cat = $o['cat'] instanceof \WP_Term ? $o['cat']->term_id : absint( $o['cat'] ?? 0 );
		$cfg = array(
			'mode'    => (string) ( $o['mode'] ?? 'viewed' ),
			'cat'     => $cat,
			'ids'     => implode( ',', array_filter( array_map( 'absint', (array) ( $o['ids'] ?? $o['picks'] ?? array() ) ) ) ),
			'heading' => (string) ( $o['heading'] ?? '' ),
			'halign'  => in_array( (string) ( $o['halign'] ?? '' ), array( 'start', 'center' ), true ) ? (string) $o['halign'] : '',
			'count'   => $count,
			'cols'    => $cols,
			'gap'     => (string) ( $o['gap'] ?? 'normal' ),
			'mcols'   => '' !== (string) ( $o['mcols'] ?? '' ) ? (string) $o['mcols'] : '1',
			'layout'  => 'grid' === (string) ( $o['layout'] ?? 'slider' ) ? 'grid' : 'slider',
			'all'     => empty( $o['all'] ) ? 0 : 1,
			'exclude' => absint( $o['exclude'] ?? 0 ),
		);

		return '<div class="oc-shelf-load" data-oc-shelf="' . esc_attr( (string) wp_json_encode( $cfg ) ) . '"></div>';
	}

	/**
	 * Ajax: render a deferred shelf. The config rode in with the request; the
	 * "viewed" source additionally reads the visitor's cookie.
	 */
	public function ajax_shelf(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- public, read-only product listing; no state change.
		$modes = array( 'viewed', 'sales', 'new', 'sale', 'cat', 'manual' );
		$mode  = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : 'viewed';

		$o = array(
			'mode'    => in_array( $mode, $modes, true ) ? $mode : 'viewed',
			'cat'     => isset( $_POST['cat'] ) ? absint( wp_unslash( $_POST['cat'] ) ) : 0,
			'ids'     => isset( $_POST['ids'] ) ? array_filter( array_map( 'absint', explode( ',', (string) wp_unslash( $_POST['ids'] ) ) ) ) : array(),
			'heading' => isset( $_POST['heading'] ) ? sanitize_text_field( wp_unslash( $_POST['heading'] ) ) : '',
			'halign'  => isset( $_POST['halign'] ) && in_array( sanitize_key( wp_unslash( $_POST['halign'] ) ), array( 'start', 'center' ), true ) ? sanitize_key( wp_unslash( $_POST['halign'] ) ) : '',
			'count'   => isset( $_POST['count'] ) ? min( 24, absint( wp_unslash( $_POST['count'] ) ) ) : 8,
			'cols'    => isset( $_POST['cols'] ) ? min( 6, absint( wp_unslash( $_POST['cols'] ) ) ) : 4,
			'gap'     => isset( $_POST['gap'] ) ? sanitize_key( wp_unslash( $_POST['gap'] ) ) : 'normal',
			'mcols'   => isset( $_POST['mcols'] ) ? sanitize_key( wp_unslash( $_POST['mcols'] ) ) : '1',
			'layout'  => isset( $_POST['layout'] ) ? sanitize_key( wp_unslash( $_POST['layout'] ) ) : 'slider',
			'all'     => ! empty( $_POST['all'] ),
			'exclude' => isset( $_POST['exclude'] ) ? absint( wp_unslash( $_POST['exclude'] ) ) : 0,
		);
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		echo self::product_shelf( $o ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
		wp_die();
	}

	/**
	 * The arrows a shelf-slider wears.
	 */
	private static function shelf_arrows(): string {
		return '<button type="button" class="ocb-arr ocb-arr--prev" data-ocb-go="-1" aria-label="prev"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14.5 5l-7 7 7 7"/></svg></button>'
			. '<button type="button" class="ocb-arr ocb-arr--next" data-ocb-go="1" aria-label="next"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9.5 5l7 7-7 7"/></svg></button>';
	}

	/**
	 * Categories: doors to the aisles.
	 *
	 * @param array<string,mixed> $s Section.
	 * @return string
	 */
	private static function categories( array $s ): string {
		$ids = array_map( 'absint', (array) $s['cats'] );

		if ( empty( $ids ) ) {
			return '';
		}

		$items = '';

		foreach ( $ids as $id ) {
			$term = get_term( $id, 'product_cat' );

			if ( ! $term instanceof \WP_Term ) {
				continue;
			}

			// Prefer the theme's shared card image (with its hero/thumbnail
			// fallback chain); fall back to the WooCommerce category image.
			$thumb = class_exists( '\OC\Theme\Category' )
				? \OC\Theme\Category::card_image_id( $id )
				: absint( get_term_meta( $id, 'thumbnail_id', true ) );
			$img   = $thumb > 0 ? wp_get_attachment_image( $thumb, 'large', false, array( 'loading' => 'lazy', 'alt' => $term->name ) ) : '';
			$link  = get_term_link( $term );

			if ( is_wp_error( $link ) ) {
				continue;
			}

			$items .= '<a class="ocb-cat" href="' . esc_url( (string) $link ) . '">'
				. '<span class="ocb-cat__pic">' . $img . '</span>'
				. '<span class="ocb-cat__name">' . esc_html( $term->name ) . '</span>'
				. '</a>';
		}

		if ( '' === $items ) {
			return '';
		}

		$classes = 'ocb-cats ocb-cats--' . esc_attr( (string) $s['shape'] )
			. ' ocb-cats--w-' . esc_attr( (string) $s['words'] )
			. ' ocb-cats--m' . esc_attr( '' !== (string) ( $s['mlay'] ?? '' ) ? (string) $s['mlay'] : '2' )
			. ' ocb-cats--' . esc_attr( (string) $s['layout'] )
			. ' ocb-cats--gap-' . esc_attr( (string) $s['gap'] )
			. ' ocb-cats--hv-' . esc_attr( (string) $s['hover'] )
			. ( 'circle' === $s['shape'] ? '' : ' ocb-cats--c-' . esc_attr( (string) $s['corners'] ) );

		return self::heading( $s )
			. '<div class="' . $classes . '" style="--ocb-cols:' . absint( $s['cols'] ) . '"'
			. ( 'slider' === $s['layout'] ? ' data-ocb-shelf' : '' ) . '>'
			. '<div class="ocb-cats__row">' . $items . '</div>'
			. ( 'slider' === $s['layout'] ? self::shelf_arrows() : '' )
			. '</div>';
	}

	/**
	 * A line of words on the move.
	 *
	 * @param array<string,mixed> $s Section.
	 * @return string
	 */
	private static function marquee( array $s ): string {
		$text = trim( (string) $s['text'] );

		if ( '' === $text ) {
			return '';
		}

		$style = '--ocb-mq-size:' . absint( $s['size'] ) . 'px;--ocb-mq-speed:' . absint( $s['speed'] ) . 's;--ocb-mq-angle:' . (int) $s['angle'] . 'deg;'
			. ( absint( $s['sizem'] ?? 0 ) > 0 ? '--ocb-mq-size-m:' . absint( $s['sizem'] ) . 'px;' : '' )
			. ( '' === $s['color'] ? '' : '--ocb-mq-color:' . $s['color'] . ';' )
			. ( '' === ( $s['bgc'] ?? '' ) ? '' : '--ocb-mq-bg:' . $s['bgc'] . ';' );

		// Four copies seed the loop; the script clones more if a wide screen
		// still shows a gap.
		$piece = '<span>' . esc_html( $text ) . '</span>';

		return '<div class="ocb-mq ocb-mq--' . esc_attr( (string) $s['dir'] ) . ( 0 === (int) $s['angle'] ? '' : ' ocb-mq--tilt' ) . '" style="' . esc_attr( $style ) . '" data-ocb-mq>'
			. '<div class="ocb-mq__track">' . str_repeat( $piece, 4 ) . '</div>'
			. '</div>';
	}

	/**
	 * Brands: the logos, in a quiet row.
	 *
	 * @param array<string,mixed> $s Section.
	 * @return string
	 */
	private static function brands( array $s ): string {
		$taxonomy = '';

		foreach ( array( 'product_brand', 'pwb-brand', 'oc_brand' ) as $tax ) {
			if ( taxonomy_exists( $tax ) ) {
				$taxonomy = $tax;
				break;
			}
		}

		if ( '' === $taxonomy ) {
			return '';
		}

		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => true,
				'orderby'    => 'name',
			)
		);

		if ( ! is_array( $terms ) || empty( $terms ) ) {
			return '';
		}

		$items = '';

		foreach ( $terms as $term ) {
			$thumb = absint( get_term_meta( $term->term_id, 'thumbnail_id', true ) );
			$logo  = $thumb > 0
				? wp_get_attachment_image( $thumb, 'medium', false, array( 'loading' => 'lazy', 'alt' => $term->name ) )
				: '<span class="ocb-brand__name">' . esc_html( $term->name ) . '</span>';
			$link  = get_term_link( $term );

			if ( is_wp_error( $link ) ) {
				continue;
			}

			$items .= '<a class="ocb-brand" href="' . esc_url( (string) $link ) . '">' . $logo . '</a>';
		}

		if ( '' === $items ) {
			return '';
		}

		return self::heading( $s )
			. '<div class="ocb-brands ocb-brands--' . esc_attr( (string) $s['layout'] ) . ' ocb-brands--gap-' . esc_attr( (string) $s['gap'] ) . '" style="--ocb-cols:' . absint( $s['cols'] ) . '"'
			. ( 'slider' === $s['layout'] ? ' data-ocb-shelf' : '' ) . '>'
			. '<div class="ocb-brands__row">' . $items . '</div>'
			. ( 'slider' === $s['layout'] ? self::shelf_arrows() : '' )
			. '</div>';
	}

	/**
	 * From the blog: the theme's own post cards when the theme is ours.
	 *
	 * @param array<string,mixed> $s Section.
	 * @return string
	 */
	private static function posts( array $s ): string {
		$args = array(
			'post_type'      => 'post',
			'post_status'    => 'publish',
			'posts_per_page' => absint( $s['count'] ),
			'no_found_rows'  => true,
		);

		if ( 'manual' === $s['mode'] && ! empty( $s['picks'] ) ) {
			$args['post__in'] = array_map( 'absint', (array) $s['picks'] );
			$args['orderby']  = 'post__in';
		}

		$query = new \WP_Query( $args );

		if ( ! $query->have_posts() ) {
			return '';
		}

		$cards = '';

		foreach ( $query->posts as $post ) {
			$cards .= self::post_card( $post, ! empty( $s['date'] ), ! empty( $s['excerpt'] ) );
		}

		$more = '';

		if ( ! empty( $s['all'] ) ) {
			$blog = absint( get_option( 'page_for_posts' ) );
			$url  = $blog > 0 ? (string) get_permalink( $blog ) : home_url( '/' );
			$text = '' !== trim( (string) $s['alltext'] ) ? (string) $s['alltext'] : __( 'To the blog', 'oc-blocks' );

			$more = '<p class="ocb__more"><a class="ocb-btn ocb-btn--theme" href="' . esc_url( $url ) . '">' . esc_html( $text ) . '</a></p>';
		}

		$slider = 'slider' === (string) ( $s['layout'] ?? 'grid' );

		return self::heading( $s )
			. '<div class="ocb-posts-wrap"' . ( $slider ? ' data-ocb-shelf' : '' ) . '>'
			. '<div class="ocb-posts ocb-posts--c-' . esc_attr( '' !== (string) ( $s['corners'] ?? '' ) ? (string) $s['corners'] : 'soft' ) . ( $slider ? ' ocb-posts--slider' : '' ) . ' ocb-posts--m' . esc_attr( '' !== (string) ( $s['mlay'] ?? '' ) ? (string) $s['mlay'] : '1' ) . '" style="--ocb-cols:' . min( 4, max( 1, absint( $s['count'] ) ) ) . '">' . $cards . '</div>'
			. ( $slider ? self::shelf_arrows() : '' )
			. '</div>'
			. $more;
	}

	/**
	 * One post card — the blog's own when the OC theme is running.
	 *
	 * @param \WP_Post $post    Post.
	 * @param bool     $date    Show the date.
	 * @param bool     $excerpt Show the excerpt.
	 * @return string
	 */
	private static function post_card( \WP_Post $post, bool $date, bool $excerpt ): string {
		$link  = (string) get_permalink( $post );
		$thumb = get_the_post_thumbnail( $post, 'medium_large', array( 'loading' => 'lazy' ) );

		$out = '<article class="ocb-post">';

		if ( '' !== $thumb ) {
			$out .= '<a class="ocb-post__pic" href="' . esc_url( $link ) . '" tabindex="-1" aria-hidden="true">' . $thumb . '</a>';
		}

		if ( $date ) {
			$out .= '<time class="ocb-post__date">' . esc_html( (string) get_the_date( '', $post ) ) . '</time>';
		}

		$out .= '<h3 class="ocb-post__title"><a href="' . esc_url( $link ) . '">' . esc_html( get_the_title( $post ) ) . '</a></h3>';

		if ( $excerpt ) {
			$out .= '<p class="ocb-post__x">' . esc_html( wp_trim_words( (string) get_the_excerpt( $post ), 18 ) ) . '</p>';
		}

		return $out . '</article>';
	}

	/**
	 * Shop the Look: rooms with tagged products. Each room is a picture with
	 * its spots; the visitor walks the products — and the rooms — with one
	 * pair of arrows, or by tapping a spot. Old single-picture sections are
	 * read as one room.
	 *
	 * @param array<string,mixed> $s Section.
	 */
	private static function look( array $s ): string {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return '';
		}

		$scenes = (array) ( $s['scenes'] ?? array() );

		// A section saved before rooms existed carries img + spots directly.
		if ( empty( $scenes ) && ! empty( $s['spots'] ) && ! empty( $s['img'] ) ) {
			$scenes = array(
				array(
					'heading' => '',
					'img'     => $s['img'],
					'imgm'    => $s['imgm'] ?? 0,
					'spots'   => $s['spots'],
				),
			);
		}

		$pics   = '';
		$cards  = '';
		$at     = 0;
		$sc     = 0;

		foreach ( $scenes as $scene ) {
			$dsk = absint( $scene['img'] ?? 0 ) > 0 ? (string) wp_get_attachment_image_url( absint( $scene['img'] ), 'full' ) : '';

			if ( '' === $dsk ) {
				continue;
			}

			$mob   = absint( $scene['imgm'] ?? 0 ) > 0 ? (string) wp_get_attachment_image_url( absint( $scene['imgm'] ), 'large' ) : '';
			$spots = '';
			$any   = false;

			foreach ( (array) ( $scene['spots'] ?? array() ) as $spot ) {
				$product = wc_get_product( absint( $spot['id'] ?? 0 ) );

				if ( ! $product || 'publish' !== $product->get_status() ) {
					continue;
				}

				$any = true;

				$spots .= sprintf(
					'<button type="button" class="ocb-look__spot%s" style="--x:%d%%;--y:%d%%" data-ocb-spot="%d" aria-label="%s"><i></i></button>',
					0 === $at ? ' is-on' : '',
					absint( $spot['x'] ?? 50 ),
					absint( $spot['y'] ?? 50 ),
					$at,
					esc_attr( $product->get_name() )
				);

				// The card is the catalogue's own card — picture, name, price
				// and quick-buy, exactly as on a category page.
				$cards .= '<div class="ocb-look__card' . ( 0 === $at ? ' is-on' : '' ) . '" data-ocb-card="' . $at . '" data-ocb-scene="' . $sc . '">'
					. do_shortcode( '[products ids="' . absint( $product->get_id() ) . '" limit="1" columns="1" orderby="post__in"]' )
					. '</div>';

				++$at;
			}

			if ( ! $any ) {
				continue;
			}

			$pics .= '<div class="ocb-look__scene' . ( 0 === $sc ? ' is-on' : '' ) . '" data-ocb-lscene="' . $sc . '">'
				. '<picture>'
				. ( '' === $mob ? '' : '<source media="(max-width: 782px)" srcset="' . esc_url( $mob ) . '">' )
				. '<img src="' . esc_url( $dsk ) . '" alt="" decoding="async">'
				. '</picture>'
				. $spots
				. '</div>';

			++$sc;
		}

		if ( 0 === $at ) {
			return '';
		}

		$title = trim( (string) ( $s['heading'] ?? '' ) );
		$title = '' !== $title ? $title : __( 'Shop the Look', 'oc-blocks' );

		// Room arrows (desktop, more than one room).
		$snav = $sc > 1
			? '<button type="button" class="ocb-arr ocb-arr--prev ocb-look__snav ocb-look__snav--prev" data-ocb-scene-go="-1" aria-label="' . esc_attr__( 'Previous room', 'oc-blocks' ) . '"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14.5 5l-7 7 7 7"/></svg></button>'
				. '<button type="button" class="ocb-arr ocb-arr--next ocb-look__snav ocb-look__snav--next" data-ocb-scene-go="1" aria-label="' . esc_attr__( 'Next room', 'oc-blocks' ) . '"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9.5 5l7 7-7 7"/></svg></button>'
			: '';

		return '<div class="ocb-look ocb-look--' . esc_attr( (string) $s['side'] ) . ( $sc > 1 ? ' ocb-look--multi' : '' ) . '" data-ocb-look>'
			. '<div class="ocb-look__pic" data-ocb-look-scenes>' . $pics . $snav . '</div>'
			. '<div class="ocb-look__bar">'
			. '<button type="button" class="ocb-look__x" data-ocb-look-close aria-label="' . esc_attr__( 'Close', 'oc-blocks' ) . '"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"/></svg></button>'
			. '<span class="ocb-look__title">' . esc_html( $title ) . '</span>'
			. '</div>'
			. '<div class="ocb-look__side">'
			. '<div class="ocb-look__cards">' . $cards . '</div>'
			. '<div class="ocb-look__nav" hidden>'
			. '<button type="button" class="ocb-arr ocb-arr--prev" data-ocb-go="-1" aria-label="prev"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14.5 5l-7 7 7 7"/></svg></button>'
			. '<span class="ocb-look__count"><b class="ocb-look__cur">1</b> / <b class="ocb-look__tot">1</b></span>'
			. '<button type="button" class="ocb-arr ocb-arr--next" data-ocb-go="1" aria-label="next"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9.5 5l7 7-7 7"/></svg></button>'
			. '</div>'
			. '</div>'
			. '<button type="button" class="ocb-look__open" data-ocb-look-open>' . esc_html__( 'Show the products', 'oc-blocks' ) . '</button>'
			. '</div>';
	}


	/**
	 * Picture & words: an editorial split — media standing on one side, a
	 * short story on the other. Three arrangements, sides swappable.
	 *
	 * @param array<string,mixed> $s Section.
	 */
	private static function media( array $s ): string {
		$img = static function ( int $id, string $class ): string {
			$url = $id > 0 ? (string) wp_get_attachment_image_url( $id, 'full' ) : '';

			return '' === $url
				? ''
				: '<figure class="' . esc_attr( $class ) . '"><img src="' . esc_url( $url ) . '" alt="" loading="lazy" decoding="async"></figure>';
		};

		$preset = (string) $s['preset'];
		$main   = $img( absint( $s['img1'] ), 'ocb-ed__main' );

		// In the single arrangement a video may BE the picture.
		if ( 'single' === $preset && '' !== (string) $s['vid'] ) {
			$main = '<figure class="ocb-ed__main ocb-ed__main--vid"><video src="' . esc_url( (string) $s['vid'] ) . '" autoplay muted loop playsinline preload="metadata"></video></figure>';
		}

		if ( '' === $main ) {
			return '';
		}

		$media = $main;

		if ( 'overlap' === $preset && '' !== (string) $s['vid'] ) {
			$media .= '<div class="ocb-ed__film"><video src="' . esc_url( (string) $s['vid'] ) . '" autoplay muted loop playsinline preload="metadata"></video></div>';
		}

		if ( 'duo' === $preset || 'canvas' === $preset ) {
			$media .= $img( absint( $s['img2'] ), 'ocb-ed__second' );
		}

		$words = '';

		if ( '' !== trim( (string) $s['eyebrow'] ) ) {
			$words .= '<span class="ocb-ed__eyebrow">' . esc_html( (string) $s['eyebrow'] ) . '</span>';
		}

		if ( '' !== trim( (string) $s['heading'] ) ) {
			$words .= '<h2 class="ocb-ed__h">' . esc_html( (string) $s['heading'] ) . '</h2>';
		}

		if ( '' !== trim( (string) $s['text'] ) ) {
			$words .= '<div class="ocb-ed__text">' . wp_kses_post( wpautop( (string) $s['text'] ) ) . '</div>';
		}

		if ( '' !== trim( (string) $s['cta'] ) && '' !== (string) $s['url'] ) {
			$words .= '<p class="ocb-ed__go"><a class="ocb-btn ocb-btn--theme" href="' . esc_url( (string) $s['url'] ) . '">' . esc_html( (string) $s['cta'] ) . '</a></p>';
		}

		return '<div class="ocb-ed ocb-ed--' . esc_attr( $preset ) . ' ocb-ed--c-' . esc_attr( '' !== (string) ( $s['corners'] ?? '' ) ? (string) $s['corners'] : 'soft' ) . ( 'end' === (string) $s['side'] ? ' ocb-ed--flip' : '' ) . '">'
			. '<div class="ocb-ed__media">' . $media . '</div>'
			. '<div class="ocb-ed__words">' . $words . '</div>'
			. '</div>';
	}

	/**
	 * The scrolling story: the picture holds its place while the words
	 * scroll past it in chapters; each chapter brings its own picture, and
	 * when the last one ends the page simply carries on.
	 *
	 * @param array<string,mixed> $s Section.
	 * @return string
	 */
	private static function scrolly( array $s ): string {
		$frames = '';
		$steps  = '';
		$at     = 0;

		foreach ( (array) $s['steps'] as $step ) {
			$media = '';

			if ( '' !== $step['vid'] ) {
				$media = '<video src="' . esc_url( (string) $step['vid'] ) . '" autoplay muted loop playsinline preload="metadata"></video>';
			} elseif ( $step['img'] > 0 ) {
				$url = (string) wp_get_attachment_image_url( (int) $step['img'], 'full' );

				if ( '' !== $url ) {
					$media = '<img src="' . esc_url( $url ) . '" alt="" loading="lazy" decoding="async">';
				}
			}

			if ( '' === $media && '' === trim( (string) $step['heading'] ) && '' === trim( (string) $step['text'] ) ) {
				continue;
			}

			$frames .= '<div class="ocb-sc__frame' . ( 0 === $at ? ' is-on' : '' ) . '" data-ocb-frame="' . $at . '">' . $media . '</div>';

			// On a phone the chapters trade places instead of stacking: the
			// words ride inside the pinned stage and crossfade with it.
			$mtexts = ( $mtexts ?? '' ) . '<div class="ocb-sc__mtext' . ( 0 === $at ? ' is-on' : '' ) . '" data-ocb-mstep="' . $at . '">'
				. ( '' === $step['heading'] ? '' : '<h3>' . esc_html( (string) $step['heading'] ) . '</h3>' )
				. ( '' === $step['text'] ? '' : '<div class="ocb-sc__words">' . wpautop( esc_html( (string) $step['text'] ) ) . '</div>' )
				. '</div>';

			$steps .= '<div class="ocb-sc__step" data-ocb-step="' . $at . '">'
				. ( '' === $step['heading'] ? '' : '<h3>' . esc_html( (string) $step['heading'] ) . '</h3>' )
				. ( '' === $step['text'] ? '' : '<div class="ocb-sc__words">' . wpautop( esc_html( (string) $step['text'] ) ) . '</div>' )
				. '</div>';

			++$at;
		}

		if ( 0 === $at ) {
			return '';
		}

		return '<div class="ocb-sc ocb-sc--' . esc_attr( (string) $s['side'] ) . '" data-ocb-sc>'
			. '<div class="ocb-sc__pin"><div class="ocb-sc__stage">' . $frames . '</div>'
			. '<div class="ocb-sc__mtexts">' . ( $mtexts ?? '' ) . '</div></div>'
			. '<div class="ocb-sc__flow">' . $steps . '</div>'
			. '</div>';
	}

	/**
	 * An OC Story gallery through the plugin's own shortcode, which carries
	 * its own assets. Quiet when the plugin is away.
	 *
	 * @param array<string,mixed> $s Section.
	 * @return string
	 */
	private static function story( array $s ): string {
		if ( ! shortcode_exists( 'oc_story' ) ) {
			return '';
		}

		$atts = 'center' === (string) ( $s['align'] ?? '' ) ? ' align="center"' : '';

		if ( '' !== trim( (string) $s['placement'] ) ) {
			$atts .= ' placement="' . esc_attr( (string) $s['placement'] ) . '"';
		}

		if ( $s['size'] > 0 ) {
			$atts .= ' size="' . absint( $s['size'] ) . '"';
		}

		$atts .= ' labels="' . ( empty( $s['labels'] ) ? 'no' : 'yes' ) . '"';

		if ( $s['max'] > 0 ) {
			$atts .= ' max="' . absint( $s['max'] ) . '"';
		}

		$html = do_shortcode( '[oc_story' . $atts . ']' );

		return '' === trim( $html ) ? '' : self::heading( $s ) . $html;
	}

	/**
	 * OC Reviews through its own shortcode — slider, grid or wall, with the
	 * plugin's own assets and rules. Quiet when the plugin is away.
	 *
	 * @param array<string,mixed> $s Section.
	 * @return string
	 */
	private static function reviews( array $s ): string {
		if ( ! shortcode_exists( 'oc_reviews' ) ) {
			return '';
		}

		$atts = ' layout="' . esc_attr( (string) $s['layout'] ) . '"'
			. ' count="' . absint( $s['count'] ) . '"'
			. ' columns="' . absint( $s['cols'] ) . '"'
			. ' min_rating="' . absint( $s['min_rating'] ) . '"'
			. ' has_media="' . ( empty( $s['media'] ) ? 'no' : 'yes' ) . '"';

		if ( 'featured' === $s['source'] ) {
			$atts .= ' source="featured"';
		} elseif ( 'category' === $s['source'] && $s['cat'] > 0 ) {
			$term = get_term( absint( $s['cat'] ), 'product_cat' );

			if ( $term instanceof \WP_Term ) {
				$atts .= ' source="category" ids="' . absint( $term->term_id ) . '"';
			}
		}

		if ( $s['autoplay'] > 0 ) {
			$atts .= ' autoplay="' . absint( $s['autoplay'] ) . '"';
		}

		$html = do_shortcode( '[oc_reviews' . $atts . ']' );

		if ( '' === trim( $html ) ) {
			return '';
		}

		if ( 'center' === (string) ( $s['align'] ?? '' ) ) {
			$html = '<div class="ocb-alignc">' . $html . '</div>';
		}

		return self::heading( $s ) . $html;
	}

	/**
	 * Questions and answers, folded, with FAQ schema for the search results.
	 *
	 * @param array<string,mixed> $s Section.
	 */
	private static function faq( array $s ): string {
		$rows = array();

		foreach ( (array) $s['items'] as $row ) {
			$q = trim( (string) ( $row['q'] ?? '' ) );
			$a = trim( (string) ( $row['a'] ?? '' ) );

			if ( '' !== $q && '' !== $a ) {
				$rows[] = array(
					'q' => $q,
					'a' => $a,
				);
			}
		}

		if ( empty( $rows ) ) {
			return '';
		}

		$items = '';

		foreach ( $rows as $at => $row ) {
			$open   = 0 === $at && 'first' === (string) $s['open'];
			$items .= '<div class="ocb-faq__item' . ( $open ? ' is-open' : '' ) . '">'
				. '<button type="button" class="ocb-faq__q" aria-expanded="' . ( $open ? 'true' : 'false' ) . '">'
				. '<span>' . esc_html( $row['q'] ) . '</span>'
				. '<span class="ocb-faq__chev" aria-hidden="true"></span>'
				. '</button>'
				. '<div class="ocb-faq__a"><div class="ocb-faq__body">' . wp_kses_post( wpautop( $row['a'] ) ) . '</div></div>'
				. '</div>';
		}

		$schema = '';

		if ( ! empty( $s['schema'] ) ) {
			$entities = array();

			foreach ( $rows as $row ) {
				$entities[] = array(
					'@type'          => 'Question',
					'name'           => $row['q'],
					'acceptedAnswer' => array(
						'@type' => 'Answer',
						'text'  => $row['a'],
					),
				);
			}

			$schema = '<script type="application/ld+json">' . wp_json_encode(
				array(
					'@context'   => 'https://schema.org',
					'@type'      => 'FAQPage',
					'mainEntity' => $entities,
				),
				JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
			) . '</script>';
		}

		return self::heading( $s ) . '<div class="ocb-faq" data-ocb-faq>' . $items . '</div>' . $schema;
	}

	/**
	 * A row of press and partner logos — standing, or drifting like a marquee.
	 *
	 * @param array<string,mixed> $s Section.
	 */
	private static function logos( array $s ): string {
		$items = '';

		foreach ( (array) $s['items'] as $row ) {
			$img = absint( $row['img'] ?? 0 );

			if ( ! $img ) {
				continue;
			}

			$pic = wp_get_attachment_image( $img, 'medium', false, array( 'loading' => 'lazy' ) );

			if ( '' === $pic ) {
				continue;
			}

			$url = (string) ( $row['url'] ?? '' );

			$items .= '' !== $url
				? '<a class="ocb-logos__one" href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . $pic . '</a>'
				: '<span class="ocb-logos__one">' . $pic . '</span>';
		}

		if ( '' === $items ) {
			return '';
		}

		$class = 'ocb-logos' . ( empty( $s['gray'] ) ? '' : ' ocb-logos--gray' );
		$style = '--ocb-logo-h:' . absint( $s['size'] ) . 'px;';

		if ( 'marquee' === (string) $s['move'] ) {
			return self::heading( $s )
				. '<div class="' . esc_attr( $class ) . ' ocb-mq ocb-mq--rtl ocb-logos--mq" style="' . esc_attr( $style . '--ocb-mq-speed:' . absint( $s['speed'] ) . 's;' ) . '" data-ocb-mq>'
				. '<div class="ocb-mq__track">' . $items . $items . '</div>'
				. '</div>';
		}

		return self::heading( $s )
			. '<div class="' . esc_attr( $class ) . '" style="' . esc_attr( $style ) . '">'
			. '<div class="ocb-logos__row">' . $items . '</div>'
			. '</div>';
	}

	/**
	 * The newsletter form. Addresses land under Tools → Newsletter signups.
	 *
	 * No nonce on purpose: for a logged-out visitor a nonce protects nothing,
	 * and this markup gets cached for a day — longer than a nonce lives. The
	 * honeypot and the throttle in Newsletter carry the abuse load instead.
	 *
	 * @param array<string,mixed> $s Section.
	 */
	private static function news( array $s ): string {
		$button      = trim( (string) $s['button'] );
		$placeholder = trim( (string) $s['placeholder'] );
		$text        = trim( (string) $s['text'] );
		$note        = trim( (string) $s['note'] );

		$out = self::heading( $s );

		if ( '' !== $text ) {
			$out .= '<p class="ocb-news__text">' . esc_html( $text ) . '</p>';
		}

		$out .= '<form class="ocb-news" method="post" action="' . esc_url( admin_url( 'admin-ajax.php' ) ) . '" data-ocb-news>'
			. '<input type="hidden" name="action" value="oc_blocks_subscribe">'
			. '<input class="ocb-news__trap" type="text" name="website" tabindex="-1" autocomplete="off" aria-hidden="true">'
			. '<input class="ocb-news__mail" type="email" name="email" required placeholder="' . esc_attr( '' === $placeholder ? __( 'Your email', 'oc-blocks' ) : $placeholder ) . '" aria-label="' . esc_attr__( 'Email', 'oc-blocks' ) . '">'
			. '<button class="ocb-news__go ocb-btn ocb-btn--theme" type="submit">' . esc_html( '' === $button ? __( 'Sign up', 'oc-blocks' ) : $button ) . '</button>'
			. '<p class="ocb-news__thanks" hidden>' . esc_html__( 'Thank you — you are on the list.', 'oc-blocks' ) . '</p>'
			. '</form>';

		if ( '' !== $note ) {
			$out .= '<p class="ocb-news__note">' . esc_html( $note ) . '</p>';
		}

		return '<div class="ocb-news__wrap">' . $out . '</div>';
	}

	/**
	 * Days, hours, minutes and seconds until a moment in the shop's timezone.
	 *
	 * @param array<string,mixed> $s Section.
	 */
	private static function countdown( array $s ): string {
		$raw = trim( (string) $s['until'] );

		if ( '' === $raw ) {
			return '';
		}

		$moment = date_create( $raw, wp_timezone() );

		if ( ! $moment ) {
			return '';
		}

		$stamp = $moment->getTimestamp();
		$done  = trim( (string) $s['done'] );

		// Already over and nothing to say about it: the section stands down.
		if ( $stamp <= time() && '' === $done ) {
			return '';
		}

		$units = array(
			'd' => __( 'Days', 'oc-blocks' ),
			'h' => __( 'Hours', 'oc-blocks' ),
			'm' => __( 'Minutes', 'oc-blocks' ),
			's' => __( 'Seconds', 'oc-blocks' ),
		);

		$cells = '';

		foreach ( $units as $unit => $label ) {
			$cells .= '<span class="ocb-cd__cell"><b class="ocb-cd__n" data-ocb-cd-u="' . esc_attr( $unit ) . '">&nbsp;</b><i>' . esc_html( $label ) . '</i></span>';
		}

		return self::heading( $s )
			. '<div class="ocb-cd ocb-cd--' . esc_attr( (string) $s['size'] ) . '" data-ocb-cd="' . esc_attr( (string) $stamp ) . '" data-ocb-cd-done="' . esc_attr( $done ) . '">'
			. '<div class="ocb-cd__cells">' . $cells . '</div>'
			. '<p class="ocb-cd__done" hidden></p>'
			. '</div>';
	}

	/**
	 * The branches, pulled from the Branches screen the way stories and
	 * reviews are pulled from theirs: cards with a square picture, a search
	 * box, a map that follows the chosen branch, and the branch photos below
	 * — every card linking to the branch's own page.
	 *
	 * @param array<string,mixed> $s Section.
	 */
	private static function branches( array $s ): string {
		if ( ! post_type_exists( Branches::CPT ) ) {
			return '';
		}

		$args = array(
			'post_type'      => Branches::CPT,
			'posts_per_page' => 40,
			'orderby'        => 'title',
			'order'          => 'ASC',
		);

		if ( 'region' === (string) $s['source'] && absint( $s['region'] ) > 0 ) {
			$args['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery -- a handful of branches.
				array(
					'taxonomy' => Branches::TAX,
					'terms'    => absint( $s['region'] ),
				),
			);
		}

		$branches = get_posts( $args );

		if ( empty( $branches ) ) {
			return '';
		}

		$icons = array(
			'pin'   => '<svg viewBox="0 0 24 24" width="18" height="18"><path d="M12 22s-7.5-6.4-7.5-11.5a7.5 7.5 0 1 1 15 0C19.5 15.6 12 22 12 22z" fill="currentColor"/><circle cx="12" cy="10.3" r="2.8" fill="#fff"/></svg>',
			'shop'  => '<svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 9l1.2-5h13.6L20 9M4 9v11h16V9M4 9h16M9.5 20v-6h5v6"/></svg>',
			'tree'  => '<svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 2l5 7h-3l4 6h-4l3 5H7l3-5H6l4-6H7l5-7zM12 20v2.5"/></svg>',
			'phone' => '<svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M5 4h4l2 5-2.5 1.5a12 12 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2z"/></svg>',
		);

		$icon       = $icons[ (string) $s['icon'] ] ?? '';
		$first_addr = '';
		$cards      = '';
		$strip      = '';

		foreach ( $branches as $at => $branch ) {
			$d    = Branches::details( (int) $branch->ID );
			$link = (string) get_permalink( $branch );

			if ( '' === $first_addr && '' !== $d['address'] ) {
				$first_addr = $d['address'];
			}

			$lines = '';

			if ( '' !== $d['address'] ) {
				$lines .= '<p class="ocb-br__addr">' . esc_html( $d['address'] ) . '</p>';
			}

			if ( '' !== $d['phone'] ) {
				$lines .= '<p class="ocb-br__phone"><a href="tel:' . esc_attr( (string) preg_replace( '/[^0-9+]/', '', $d['phone'] ) ) . '">' . esc_html( $d['phone'] ) . '</a></p>';
			}

			$lines .= '<p class="ocb-br__more"><a href="' . esc_url( $link ) . '">' . esc_html__( 'Branch page', 'oc-blocks' ) . ' ←</a></p>';

			$photo = get_the_post_thumbnail( $branch, 'large', array( 'loading' => 'lazy' ) );

			$cards .= '<div class="ocb-br__card' . ( 0 === $at ? ' is-on' : '' ) . '" data-ocb-br-addr="' . esc_attr( $d['address'] ) . '">'
				. '<span class="ocb-br__body">'
				. '<span class="ocb-br__name">' . ( '' === $icon ? '' : '<i class="ocb-br__ico" aria-hidden="true">' . $icon . '</i>' ) . esc_html( (string) $branch->post_title ) . '</span>'
				. $lines
				. '</span>'
				. '</div>';

			if ( ! empty( $s['strip'] ) && '' !== $photo ) {
				$strip .= '<a class="ocb-br__stop" href="' . esc_url( $link ) . '">'
					. get_the_post_thumbnail( $branch, 'large', array( 'loading' => 'lazy' ) )
					. '<span class="ocb-br__stop-name">' . esc_html( (string) $branch->post_title ) . '</span>'
					. '<span class="ocb-br__stop-go">' . esc_html__( 'Branch page', 'oc-blocks' ) . '</span>'
					. '</a>';
			}
		}

		$with_map = ! empty( $s['map'] ) && '' !== $first_addr;
		$map      = '';

		if ( $with_map ) {
			$map = '<div class="ocb-br__map"><iframe loading="lazy" title="' . esc_attr__( 'Map', 'oc-blocks' ) . '" src="' . esc_url( 'https://maps.google.com/maps?q=' . rawurlencode( $first_addr ) . '&hl=' . substr( get_locale(), 0, 2 ) . '&output=embed' ) . '"></iframe></div>';
		}

		$search = empty( $s['search'] ) ? '' : '<p class="ocb-br__search"><input type="search" data-ocb-br-q placeholder="' . esc_attr__( 'Search a branch or a city', 'oc-blocks' ) . '" aria-label="' . esc_attr__( 'Search a branch or a city', 'oc-blocks' ) . '"></p>';

		return self::heading( $s )
			. $search
			. '<div class="ocb-br' . ( $with_map ? ' ocb-br--map' : '' ) . '" data-ocb-br>'
			. '<div class="ocb-br__list"><p class="ocb-br__none" hidden>' . esc_html__( 'We could not find a branch by that name — but every one of ours is right here:', 'oc-blocks' ) . '</p>' . $cards . '</div>' . $map
			. '</div>'
			. ( '' === $strip ? '' : '<div class="ocb-br__strip">' . $strip . '</div>' );
	}

	/**
	 * The contact form. Everything sent lands on the Leads screen, and — when
	 * a webhook is set there — travels on to the shop's CRM as JSON.
	 *
	 * Nonce-free like the newsletter, and for the same reason: the markup is
	 * cached longer than a nonce lives, and a logged-out nonce guards nothing.
	 * The honeypot and the throttle in Leads carry the abuse load.
	 *
	 * @param array<string,mixed> $s Section.
	 */
	private static function contact( array $s ): string {
		$button = trim( (string) $s['button'] );
		$thanks = trim( (string) $s['thanks'] );
		$text   = trim( (string) $s['text'] );

		$out = self::heading( $s );

		if ( '' !== $text ) {
			$out .= '<p class="ocb-lead__text">' . esc_html( $text ) . '</p>';
		}

		$err = '<em class="ocb-lead__err" hidden></em>';

		$fields = '<label class="ocb-lead__f ocb-lead__f--half"><span>' . esc_html__( 'Full name', 'oc-blocks' ) . '</span><input type="text" name="name" required autocomplete="name">' . $err . '</label>';

		if ( ! empty( $s['phone'] ) ) {
			$fields .= '<label class="ocb-lead__f ocb-lead__f--half"><span>' . esc_html__( 'Phone', 'oc-blocks' ) . '</span><input type="tel" name="phone" required autocomplete="tel" inputmode="tel">' . $err . '</label>';
		}

		if ( ! empty( $s['email'] ) ) {
			$fields .= '<label class="ocb-lead__f"><span>' . esc_html__( 'Email', 'oc-blocks' ) . '</span><input type="email" name="email" autocomplete="email">' . $err . '</label>';
		}

		if ( ! empty( $s['msg'] ) ) {
			$fields .= '<label class="ocb-lead__f"><span>' . esc_html__( 'How can we help?', 'oc-blocks' ) . '</span><textarea name="msg" rows="4"></textarea>' . $err . '</label>';
		}

		$consent = '';

		if ( ! empty( $s['consent'] ) ) {
			$wording = trim( (string) $s['consent_text'] );

			if ( '' === $wording ) {
				$wording = __( 'I have read and accept the privacy policy.', 'oc-blocks' );
			}

			$wording = esc_html( $wording );
			$policy  = (string) get_privacy_policy_url();

			// The policy words become the way to the policy itself.
			if ( '' !== $policy ) {
				$needle = esc_html( __( 'privacy policy', 'oc-blocks' ) );

				if ( false !== mb_strpos( $wording, $needle ) ) {
					$wording = str_replace( $needle, '<a href="' . esc_url( $policy ) . '" target="_blank" rel="noopener">' . $needle . '</a>', $wording );
				}
			}

			$consent = '<label class="ocb-lead__consent"><input type="checkbox" name="consent" required><span>' . $wording . '</span><em class="ocb-lead__err" hidden></em></label>';
		}

		$out .= '<form class="ocb-lead" method="post" action="' . esc_url( admin_url( 'admin-ajax.php' ) ) . '" data-ocb-lead novalidate'
			. ' data-err-req="' . esc_attr__( 'Please fill in this field.', 'oc-blocks' ) . '"'
			. ' data-err-phone="' . esc_attr__( 'Please enter a valid phone number (10 digits).', 'oc-blocks' ) . '"'
			. ' data-err-email="' . esc_attr__( 'Please enter a valid email address.', 'oc-blocks' ) . '"'
			. ' data-err-consent="' . esc_attr__( 'Please tick the approval to continue.', 'oc-blocks' ) . '">'
			. '<input type="hidden" name="action" value="oc_blocks_lead">'
			. '<input type="hidden" name="page" value="' . absint( get_the_ID() ) . '">'
			. '<input class="ocb-news__trap" type="text" name="website" tabindex="-1" autocomplete="off" aria-hidden="true">'
			. $fields
			. $consent
			. '<button class="ocb-lead__go ocb-btn ocb-btn--theme" type="submit">' . esc_html( '' === $button ? __( 'Send', 'oc-blocks' ) : $button ) . '</button>'
			. '<p class="ocb-lead__thanks" hidden>' . esc_html( '' === $thanks ? __( 'Thank you — we will be in touch shortly.', 'oc-blocks' ) : $thanks ) . '</p>'
			. '</form>';

		return '<div class="ocb-lead__wrap">' . $out . '</div>';
	}

	/**
	 * The outline icon library — one hand, drawn to match the Bricks-style
	 * promise rows: quiet strokes, no fills.
	 *
	 * @return array<string,string> Icon id => inner SVG.
	 */
	private static function icon_library(): array {
		return array(
			'truck'    => '<path d="M2.5 6.5h11V16h-11zM13.5 9.5h3.6l3.4 3.4V16h-7z"/><circle cx="6.8" cy="17.6" r="1.9"/><circle cx="16.6" cy="17.6" r="1.9"/><path d="M4.8 9.5h4M4.8 12h2.6"/>',
			'returns'  => '<path d="M4 9a8.3 8.3 0 0 1 14.6-2.5L20.5 9M20.5 3.8V9h-5.2M20 15a8.3 8.3 0 0 1-14.6 2.5L3.5 15M3.5 20.2V15h5.2"/>',
			'badge'    => '<path d="M12 2.6l2 1.8 2.7-.4 1 2.5 2.5 1-.4 2.7 1.8 2-1.8 2 .4 2.7-2.5 1-1 2.5-2.7-.4-2 1.8-2-1.8-2.7.4-1-2.5-2.5-1 .4-2.7-1.8-2 1.8-2-.4-2.7 2.5-1 1-2.5 2.7.4z"/><path d="M8.9 12.1l2.1 2.1 4.1-4.4"/>',
			'shield'   => '<path d="M12 3l7 2.6v5.5c0 4.3-2.9 7.7-7 9.4-4.1-1.7-7-5.1-7-9.4V5.6z"/><path d="M8.9 12l2.1 2.1 4.1-4.3"/>',
			'card'     => '<rect x="2.5" y="5.5" width="19" height="13" rx="2"/><path d="M2.5 9.5h19M6 14.5h4.5"/>',
			'support'  => '<path d="M4.5 13.5v-2.3a7.5 7.5 0 0 1 15 0v2.3"/><rect x="3" y="12.8" width="3.8" height="5.6" rx="1.6"/><rect x="17.2" y="12.8" width="3.8" height="5.6" rx="1.6"/><path d="M19 18.4v.7a2.6 2.6 0 0 1-2.6 2.6h-3.6"/>',
			'gift'     => '<rect x="3" y="8" width="18" height="3.8"/><path d="M5 11.8v8.7h14v-8.7M12 8v12.5M12 8s-4.4.3-5.4-1.7C5.8 4.8 7.6 3.1 9.2 3.9 10.9 4.8 12 8 12 8zM12 8s4.4.3 5.4-1.7C18.2 4.8 16.4 3.1 14.8 3.9 13.1 4.8 12 8 12 8z"/>',
			'star'     => '<path d="M12 3.4l2.6 5.4 5.9.8-4.3 4.2 1 5.9-5.2-2.8-5.2 2.8 1-5.9-4.3-4.2 5.9-.8z"/>',
			'heart'    => '<path d="M12 20.4S3.6 15.4 3.6 9.6c0-3 2.4-5.1 4.8-5.1 1.6 0 3 .8 3.6 2 .6-1.2 2-2 3.6-2 2.4 0 4.8 2.1 4.8 5.1 0 5.8-8.4 10.8-8.4 10.8z"/>',
			'leaf'     => '<path d="M5 19C5 9.4 11 4.6 20 4c.5 9-4 15-15 15z"/><path d="M5 19c3-5 7-8.5 11-10.6"/>',
			'clock'    => '<circle cx="12" cy="12" r="8.5"/><path d="M12 7.4V12l3.1 2"/>',
			'box'      => '<path d="M3.5 7.5L12 3l8.5 4.5v9L12 21l-8.5-4.5zM3.5 7.5L12 12l8.5-4.5M12 12v9"/>',
			'phone'    => '<path d="M5 4h4l2 5-2.5 1.5a12 12 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2z"/>',
			'tools'    => '<path d="M14.7 6.3a4.3 4.3 0 0 0-5.8 5.5L3.2 17.5 6.5 20.8l5.7-5.7a4.3 4.3 0 0 0 5.5-5.8L14.9 12l-2.9-2.9z"/>',
			'armchair' => '<path d="M5.5 11V7.5a3 3 0 0 1 3-3h7a3 3 0 0 1 3 3V11"/><path d="M4 11.2a2 2 0 0 1 2 2v1.8h12v-1.8a2 2 0 0 1 4 0v3.3a3 3 0 0 1-3 3H5a3 3 0 0 1-3-3v-3.3a2 2 0 0 1 2-2zM6.5 19.5V21M17.5 19.5V21"/>',
			'ruler'    => '<rect x="2.5" y="9" width="19" height="6" rx="1"/><path d="M6.5 9v2.6M10.2 9v3.6M13.8 9v2.6M17.5 9v3.6"/>',
			'sparkle'  => '<path d="M11 3.5l1.6 4.9 4.9 1.6-4.9 1.6L11 16.5l-1.6-4.9L4.5 10l4.9-1.6zM18.5 15.5l.8 2.2 2.2.8-2.2.8-.8 2.2-.8-2.2-2.2-.8 2.2-.8z"/>',
		);
	}

	/**
	 * Icon columns: a row of quiet promises — an icon, a few words each,
	 * optionally sitting on a soft round circle.
	 *
	 * @param array<string,mixed> $s Section.
	 */
	private static function icons( array $s ): string {
		$library = self::icon_library();
		$items   = '';
		$count   = 0;

		foreach ( (array) $s['items'] as $row ) {
			$head = trim( (string) ( $row['heading'] ?? '' ) );
			$text = trim( (string) ( $row['text'] ?? '' ) );
			$icon = $library[ (string) ( $row['icon'] ?? '' ) ] ?? '';

			if ( '' === $head && '' === $text ) {
				continue;
			}

			$count++;
			$items .= '<div class="ocb-ico__one">'
				. ( '' === $icon ? '' : '<i class="ocb-ico__pic" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">' . $icon . '</svg></i>' )
				. ( '' === $head ? '' : '<h3 class="ocb-ico__h">' . esc_html( $head ) . '</h3>' )
				. ( '' === $text ? '' : '<p class="ocb-ico__t">' . esc_html( $text ) . '</p>' )
				. '</div>';
		}

		if ( 0 === $count ) {
			return '';
		}

		$style = '--ocb-ico-n:' . $count . ';'
			. ( '' === ( $s['bgc'] ?? '' ) ? '' : '--ocb-ico-bg:' . $s['bgc'] . ';' )
			. ( '' === ( $s['ic'] ?? '' ) ? '' : '--ocb-ico-color:' . $s['ic'] . ';' );

		return self::heading( $s )
			. '<div class="ocb-ico ocb-ico--' . esc_attr( (string) $s['size'] ) . ' ocb-ico--m' . esc_attr( '' !== (string) ( $s['mlay'] ?? '' ) ? (string) $s['mlay'] : '1' ) . ( empty( $s['bg'] ) ? '' : ' ocb-ico--bg' ) . '" style="' . esc_attr( $style ) . '">'
			. $items
			. '</div>';
	}

	/*
	 * ------------------------------------------------------------- assets
	 */

	/**
	 * The block styles and behaviours, only where sections render.
	 */
	/**
	 * When the page opens on a hero, its first image is the LCP — tell the
	 * browser before the parser ever reaches the markup.
	 */
	public function preload_hero(): void {
		if ( ! is_singular() ) {
			return;
		}

		$sections = Registry::sections( (int) get_queried_object_id() );

		foreach ( $sections as $section ) {
			if ( empty( $section['on'] ) ) {
				continue;
			}

			if ( 'hero' !== (string) $section['type'] ) {
				return; // The page opens on something else — nothing to rush.
			}

			$slide = ( (array) ( $section['slides'] ?? array() ) )[0] ?? null;

			if ( ! is_array( $slide ) || '' !== (string) ( $slide['vid'] ?? '' ) || absint( $slide['img'] ?? 0 ) < 1 ) {
				return;
			}

			$desktop = absint( $slide['img'] );
			$mobile  = absint( $slide['imgm'] ?? 0 );
			$url     = (string) wp_get_attachment_image_url( $desktop, 'full' );

			if ( '' === $url ) {
				return;
			}

			$set = (string) wp_get_attachment_image_srcset( $desktop, 'full' );

			if ( $mobile > 0 ) {
				$mob_url = (string) wp_get_attachment_image_url( $mobile, 'large' );
				$mob_set = (string) wp_get_attachment_image_srcset( $mobile, 'full' );

				if ( '' !== $mob_url ) {
					echo '<link rel="preload" as="image" media="(max-width: 782px)" href="' . esc_url( $mob_url ) . '"'
						. ( '' === $mob_set ? '' : ' imagesrcset="' . esc_attr( $mob_set ) . '" imagesizes="100vw"' )
						. ' fetchpriority="high">' . "\n";
				}

				echo '<link rel="preload" as="image" media="(min-width: 783px)" href="' . esc_url( $url ) . '"'
					. ( '' === $set ? '' : ' imagesrcset="' . esc_attr( $set ) . '" imagesizes="100vw"' )
					. ' fetchpriority="high">' . "\n";
			} else {
				echo '<link rel="preload" as="image" href="' . esc_url( $url ) . '"'
					. ( '' === $set ? '' : ' imagesrcset="' . esc_attr( $set ) . '" imagesizes="100vw"' )
					. ' fetchpriority="high">' . "\n";
			}

			return;
		}
	}

	public function assets(): void {
		$need = false;

		if ( is_page() && self::is_composed( (int) get_queried_object_id() ) ) {
			$need = true;
		}

		// A branch's own page dresses itself from the same stylesheet.
		if ( ! $need && is_singular( Branches::CPT ) ) {
			$need = true;
		}

		if ( ! $need && function_exists( 'is_product_category' ) && is_product_category() ) {
			$term = get_queried_object();
			$need = $term instanceof \WP_Term && (
				absint( get_term_meta( $term->term_id, 'oc_lobby_page', true ) ) > 0
				|| '1' === (string) get_term_meta( $term->term_id, '_oc_ps_show', true )
			);
		}

		// The theme's catalogue-layout slider blocks also need these assets;
		// the theme answers this filter when one is on the current listing.
		$need = (bool) apply_filters( 'oc_blocks_need_assets', $need );

		if ( ! $need ) {
			return;
		}

		$css = oc_blocks_asset( 'assets/blocks.css' );
		$js  = oc_blocks_asset( 'assets/blocks.js' );
		wp_enqueue_style( 'oc-blocks', OC_BLOCKS_URI . $css, array(), (string) filemtime( OC_BLOCKS_DIR . $css ) );
		wp_enqueue_script( 'oc-blocks', OC_BLOCKS_URI . $js, array(), (string) filemtime( OC_BLOCKS_DIR . $js ), array( 'strategy' => 'defer', 'in_footer' => true ) );
		wp_localize_script( 'oc-blocks', 'OCB', array( 'ajax' => admin_url( 'admin-ajax.php' ) ) );
	}

	/**
	 * Stories and reviews decide their assets by looking for their shortcode
	 * in the post content — but composed sections live in meta, and their
	 * rendered markup often arrives from cache without the shortcode ever
	 * running. So when a section needs them, their assets are requested here,
	 * head-side, before each plugin's own loader makes its decision.
	 */
	public function integration_assets(): void {
		$page = 0;

		if ( is_page() ) {
			$page = (int) get_queried_object_id();
		} elseif ( function_exists( 'is_product_category' ) && is_product_category() ) {
			$term = get_queried_object();

			if ( $term instanceof \WP_Term ) {
				$page = absint( get_term_meta( $term->term_id, 'oc_lobby_page', true ) );
			}
		}

		if ( ! $page || ! self::is_composed( $page ) ) {
			return;
		}

		$types = array();

		foreach ( Registry::sections( $page ) as $s ) {
			if ( ! empty( $s['on'] ) && isset( $s['type'] ) ) {
				$types[ (string) $s['type'] ] = true;
			}
		}

		// OC Story loads nothing unless a surface asked for it by now — the
		// same call its own shortcode detection makes, plus the force filter
		// as a second belt.
		if ( isset( $types['story'] ) && class_exists( '\\OCS\\Display\\Injector' ) ) {
			add_filter( 'ocs_force_assets', '__return_true' );

			if ( class_exists( '\\OCS\\Surfaces\\SurfaceManager' ) ) {
				foreach ( (array) \OCS\Surfaces\SurfaceManager::ids() as $id ) {
					\OCS\Display\Injector::need( (string) $id );
				}
			}
		}

		// OC Reviews registers its bundle on every page; enqueueing the
		// handles is all it takes.
		if ( isset( $types['reviews'] ) && wp_style_is( 'ocr-front', 'registered' ) ) {
			wp_enqueue_style( 'ocr-front' );
			wp_enqueue_script( 'ocr-front' );
		}
	}

	/*
	 * ------------------------------------------------------- category lobby
	 */

	/**
	 * The lobby picker on a category's edit screen.
	 *
	 * @param \WP_Term $term Term.
	 */
	public function lobby_field( $term ): void {
		$current = absint( get_term_meta( $term->term_id, 'oc_lobby_page', true ) );
		$pages   = get_pages( array( 'meta_key' => Registry::META ) ); // phpcs:ignore WordPress.DB.SlowDBQuery -- a handful of composed pages.
		?>
		<tr class="form-field">
			<th scope="row"><label for="oc_lobby_page"><?php esc_html_e( 'Lobby page', 'oc-blocks' ); ?></label></th>
			<td>
				<select name="oc_lobby_page" id="oc_lobby_page">
					<option value="0"><?php esc_html_e( '— none —', 'oc-blocks' ); ?></option>
					<?php foreach ( (array) $pages as $page ) : ?>
						<option value="<?php echo absint( $page->ID ); ?>" <?php selected( $current, $page->ID ); ?>><?php echo esc_html( $page->post_title ); ?></option>
					<?php endforeach; ?>
				</select>
				<p class="description"><?php esc_html_e( 'A page built with OC Blocks, shown at the top of this category.', 'oc-blocks' ); ?></p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Keep the choice.
	 *
	 * @param int $term_id Term id.
	 */
	public function save_lobby( $term_id ): void {
		if ( isset( $_POST['oc_lobby_page'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- rides the term edit screen's own nonce.
			$page = absint( wp_unslash( $_POST['oc_lobby_page'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

			if ( $page > 0 ) {
				update_term_meta( (int) $term_id, 'oc_lobby_page', $page );
			} else {
				delete_term_meta( (int) $term_id, 'oc_lobby_page' );
			}
		}
	}

	/**
	 * The lobby itself, above the category's products.
	 */
	public function lobby(): void {
		if ( ! function_exists( 'is_product_category' ) || ! is_product_category() ) {
			return;
		}

		$term = get_queried_object();

		if ( ! $term instanceof \WP_Term ) {
			return;
		}

		$page = absint( get_term_meta( $term->term_id, 'oc_lobby_page', true ) );

		if ( $page > 0 && self::is_composed( $page ) ) {
			echo self::page_html( $page ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
		}
	}
}
