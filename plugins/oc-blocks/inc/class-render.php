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
	 * Hook in.
	 */
	public function register(): void {
		add_filter( 'the_content', array( $this, 'compose' ), 9 );
		add_filter( 'body_class', array( $this, 'body_class' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'assets' ) );

		// A category may nominate a composed page as its lobby, shown above
		// the products: "the women's front page" is just another page.
		add_action( 'product_cat_edit_form_fields', array( $this, 'lobby_field' ), 15 );
		add_action( 'edited_product_cat', array( $this, 'save_lobby' ) );
		add_action( 'woocommerce_archive_description', array( $this, 'lobby' ), 5 );

		add_action( 'save_post_page', array( $this, 'flush' ) );
	}

	/**
	 * A class on the body of a composed page — the hero is the title there,
	 * so the theme's own heading and breadcrumb step aside (see blocks.css).
	 *
	 * @param array<int,string> $classes Body classes.
	 * @return array<int,string>
	 */
	public function body_class( array $classes ): array {
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
	 * A composed page shows its sections in place of its content.
	 *
	 * @param string $content Content.
	 * @return string
	 */
	public function compose( $content ) {
		if ( ! is_page() || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}

		$page_id = get_the_ID();

		if ( ! $page_id || ! self::is_composed( (int) $page_id ) ) {
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

		foreach ( $sections as $section ) {
			if ( empty( $section['on'] ) ) {
				continue;
			}

			$inner = self::block( $section );

			if ( '' === trim( $inner ) ) {
				continue;
			}

			$out .= self::wrap( $section, $inner );
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
	private static function wrap( array $s, string $inner ): string {
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
	 * Banner / slider: one picture is a banner, several are a slider.
	 *
	 * @param array<string,mixed> $s Section.
	 * @return string
	 */
	private static function hero( array $s ): string {
		$slides = array();

		foreach ( (array) $s['slides'] as $slide ) {
			$media = '';

			if ( '' !== $slide['vid'] ) {
				$media = '<video src="' . esc_url( (string) $slide['vid'] ) . '" autoplay muted loop playsinline preload="metadata"></video>';
			} elseif ( $slide['img'] > 0 ) {
				$dsk = (string) wp_get_attachment_image_url( (int) $slide['img'], 'full' );
				$mob = $slide['imgm'] > 0 ? (string) wp_get_attachment_image_url( (int) $slide['imgm'], 'large' ) : '';

				if ( '' === $dsk ) {
					continue;
				}

				$media = '<picture>'
					. ( '' === $mob ? '' : '<source media="(max-width: 782px)" srcset="' . esc_url( $mob ) . '">' )
					. '<img src="' . esc_url( $dsk ) . '" alt="' . esc_attr( (string) $slide['heading'] ) . '">'
					. '</picture>';
			} else {
				continue;
			}

			$words = '';

			if ( '' !== $slide['heading'] || '' !== $slide['text'] || '' !== $slide['cta'] ) {
				$words = '<div class="ocb-hero__words">'
					. ( '' === $slide['heading'] ? '' : '<h2>' . esc_html( (string) $slide['heading'] ) . '</h2>' )
					. ( '' === $slide['text'] ? '' : '<p>' . esc_html( (string) $slide['text'] ) . '</p>' )
					. ( '' === $slide['cta'] || '' === $slide['url'] ? '' : '<a class="ocb-hero__cta" href="' . esc_url( (string) $slide['url'] ) . '">' . esc_html( (string) $slide['cta'] ) . '</a>' )
					. '</div>';
			}

			$open  = '' !== $slide['url'] && '' === $slide['cta']
				? '<a class="ocb-hero__slide" href="' . esc_url( (string) $slide['url'] ) . '">'
				: '<div class="ocb-hero__slide">';
			$close = '' !== $slide['url'] && '' === $slide['cta'] ? '</a>' : '</div>';

			$slides[] = $open
				. '<div class="ocb-hero__media"' . ( empty( $s['parallax'] ) ? '' : ' data-ocb-parallax' ) . '>' . $media . '</div>'
				. ( $s['shade'] > 0 ? '<div class="ocb-hero__shade" style="opacity:' . ( absint( $s['shade'] ) / 100 ) . '"></div>' : '' )
				. $words
				. $close;
		}

		if ( empty( $slides ) ) {
			return '';
		}

		$one   = count( $slides ) < 2;
		$style = '--ocb-hero-h:' . absint( $s['h'] ) . 'px;--ocb-hero-hm:' . absint( $s['hm'] ) . 'px;';

		$html = '<div class="ocb-hero ocb-hero--' . esc_attr( (string) $s['effect'] ) . ' ocb-hero--pos-' . esc_attr( (string) $s['pos'] ) . ' ocb-hero--' . esc_attr( (string) $s['tone'] ) . ( $one ? ' ocb-hero--one' : '' ) . '"'
			. ' style="' . esc_attr( $style ) . '"'
			. ( $one || empty( $s['auto'] ) ? '' : ' data-ocb-auto="' . absint( $s['auto'] ) . '"' ) . '>'
			. '<div class="ocb-hero__strip">' . implode( '', $slides ) . '</div>';

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
		if ( ! class_exists( 'WooCommerce' ) ) {
			return '';
		}

		$args = array(
			'status'  => 'publish',
			'limit'   => absint( $s['count'] ),
			'orderby' => 'date',
			'order'   => 'DESC',
			'return'  => 'ids',
		);

		$cat = null;

		switch ( (string) $s['mode'] ) {
			case 'manual':
				if ( empty( $s['picks'] ) ) {
					return '';
				}

				$args['include'] = array_map( 'absint', (array) $s['picks'] );
				$args['orderby'] = 'post__in';
				break;

			case 'sales':
				$args['orderby'] = 'popularity';
				break;

			case 'cat':
				$cat = get_term( absint( $s['cat'] ), 'product_cat' );

				if ( ! $cat instanceof \WP_Term ) {
					return '';
				}

				$args['category'] = array( $cat->slug );
				break;
		}

		$ids = wc_get_products( $args );

		if ( empty( $ids ) ) {
			return '';
		}

		// The theme's own loop draws the cards, so a products shelf here is
		// pixel-for-pixel the catalogue — labels, galleries, quick pick.
		$shortcode = '[products ids="' . implode( ',', array_map( 'absint', $ids ) ) . '" columns="' . absint( $s['cols'] ) . '" orderby="post__in" limit="' . count( $ids ) . '"]';
		$cards     = do_shortcode( $shortcode );

		$more = '';

		if ( ! empty( $s['all'] ) ) {
			$url = (string) $s['allurl'];

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

		return self::heading( $s )
			. '<div class="ocb-shelf ocb-shelf--' . esc_attr( (string) $s['layout'] ) . ' ocb-shelf--gap-' . esc_attr( (string) $s['gap'] ) . '" style="--ocb-cols:' . absint( $s['cols'] ) . '"'
			. ( 'slider' === $s['layout'] ? ' data-ocb-shelf' : '' ) . '>'
			. $cards
			. ( 'slider' === $s['layout'] ? self::shelf_arrows() : '' )
			. '</div>' . $more;
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

			$thumb = absint( get_term_meta( $id, 'thumbnail_id', true ) );
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
			. ( '' === $s['color'] ? '' : '--ocb-mq-color:' . $s['color'] . ';' );

		// Four copies seed the loop; the script clones more if a wide screen
		// still shows a gap.
		$piece = '<span>' . esc_html( $text ) . '</span>';

		return '<div class="ocb-mq ocb-mq--' . esc_attr( (string) $s['dir'] ) . '" style="' . esc_attr( $style ) . '" data-ocb-mq>'
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

		return self::heading( $s )
			. '<div class="ocb-posts" style="--ocb-cols:' . min( 4, max( 1, absint( $s['count'] ) ) ) . '">' . $cards . '</div>'
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
	 * Shop the look: a picture wearing hot spots, each one a product. The
	 * spots and the cards answer each other; arrows page through the set.
	 *
	 * @param array<string,mixed> $s Section.
	 * @return string
	 */
	private static function look( array $s ): string {
		if ( ! class_exists( 'WooCommerce' ) || $s['img'] <= 0 || empty( $s['spots'] ) ) {
			return '';
		}

		$dsk = (string) wp_get_attachment_image_url( (int) $s['img'], 'full' );

		if ( '' === $dsk ) {
			return '';
		}

		$mob = $s['imgm'] > 0 ? (string) wp_get_attachment_image_url( (int) $s['imgm'], 'large' ) : '';

		$spots = '';
		$cards = '';
		$at    = 0;

		foreach ( (array) $s['spots'] as $spot ) {
			$product = wc_get_product( absint( $spot['id'] ) );

			if ( ! $product || 'publish' !== $product->get_status() ) {
				continue;
			}

			$img = $product->get_image_id() ? (string) wp_get_attachment_image_url( (int) $product->get_image_id(), 'large' ) : '';

			$spots .= sprintf(
				'<button type="button" class="ocb-look__spot%s" style="--x:%d%%;--y:%d%%" data-ocb-spot="%d" aria-label="%s"><i></i></button>',
				0 === $at ? ' is-on' : '',
				absint( $spot['x'] ),
				absint( $spot['y'] ),
				$at,
				esc_attr( $product->get_name() )
			);

			$cards .= '<div class="ocb-look__card' . ( 0 === $at ? ' is-on' : '' ) . '" data-ocb-card="' . $at . '">'
				. ( '' === $img ? '' : '<a class="ocb-look__cimg" href="' . esc_url( (string) $product->get_permalink() ) . '"><img src="' . esc_url( $img ) . '" alt="" loading="lazy" decoding="async"></a>' )
				. '<h3 class="ocb-look__cname"><a href="' . esc_url( (string) $product->get_permalink() ) . '">' . esc_html( $product->get_name() ) . '</a></h3>'
				. '<div class="ocb-look__cprice">' . $product->get_price_html() . '</div>'
				. '<a class="ocb-btn ocb-btn--theme ocb-look__cgo" href="' . esc_url( (string) $product->get_permalink() ) . '">' . esc_html__( 'View product', 'oc-blocks' ) . '</a>'
				. '</div>';

			++$at;
		}

		if ( 0 === $at ) {
			return '';
		}

		return '<div class="ocb-look ocb-look--' . esc_attr( (string) $s['side'] ) . '" data-ocb-look>'
			. '<div class="ocb-look__pic">'
			. '<picture>'
			. ( '' === $mob ? '' : '<source media="(max-width: 782px)" srcset="' . esc_url( $mob ) . '">' )
			. '<img src="' . esc_url( $dsk ) . '" alt="" decoding="async">'
			. '</picture>'
			. $spots
			. '</div>'
			. '<div class="ocb-look__side">'
			. '<div class="ocb-look__cards">' . $cards . '</div>'
			. ( $at > 1
				? '<div class="ocb-look__nav">'
					. '<button type="button" class="ocb-arr ocb-arr--prev" data-ocb-go="-1" aria-label="prev"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14.5 5l-7 7 7 7"/></svg></button>'
					. '<span class="ocb-look__count"><b>1</b> / ' . $at . '</span>'
					. '<button type="button" class="ocb-arr ocb-arr--next" data-ocb-go="1" aria-label="next"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9.5 5l7 7-7 7"/></svg></button>'
					. '</div>'
				: '' )
			. '</div>'
			. '<button type="button" class="ocb-look__open" data-ocb-look-open>' . esc_html__( 'Show the products', 'oc-blocks' ) . '</button>'
			. '</div>';
	}

	/**
	 * A media grid with character: a few curated arrangements instead of a
	 * plain picture drop.
	 *
	 * @param array<string,mixed> $s Section.
	 * @return string
	 */
	private static function media( array $s ): string {
		$cells = '';
		$count = 'duo' === $s['preset'] || 'inset' === $s['preset'] ? 2 : 3;

		for ( $i = 1; $i <= $count; $i++ ) {
			$vid = (string) ( $s[ 'v' . $i ] ?? '' );
			$img = absint( $s[ 'm' . $i ] ?? 0 );

			$inner = '';

			if ( '' !== $vid ) {
				$inner = '<video src="' . esc_url( $vid ) . '" autoplay muted loop playsinline preload="metadata"></video>';
			} elseif ( $img > 0 ) {
				$url = (string) wp_get_attachment_image_url( $img, 'full' );

				if ( '' !== $url ) {
					$inner = '<img src="' . esc_url( $url ) . '" alt="" loading="lazy" decoding="async">';
				}
			}

			if ( '' === $inner ) {
				continue;
			}

			$cells .= '<div class="ocb-mg__cell ocb-mg__cell--' . $i . '">' . $inner . '</div>';
		}

		if ( '' === $cells ) {
			return '';
		}

		return '<div class="ocb-mg ocb-mg--' . esc_attr( (string) $s['preset'] ) . ' ocb-mg--gap-' . esc_attr( (string) $s['gap'] ) . ' ocb-mg--c-' . esc_attr( (string) $s['corners'] ) . '">' . $cells . '</div>';
	}

	/*
	 * ------------------------------------------------------------- assets
	 */

	/**
	 * The block styles and behaviours, only where sections render.
	 */
	public function assets(): void {
		$need = false;

		if ( is_page() && self::is_composed( (int) get_queried_object_id() ) ) {
			$need = true;
		}

		if ( ! $need && function_exists( 'is_product_category' ) && is_product_category() ) {
			$term = get_queried_object();
			$need = $term instanceof \WP_Term && absint( get_term_meta( $term->term_id, 'oc_lobby_page', true ) ) > 0;
		}

		if ( ! $need ) {
			return;
		}

		wp_enqueue_style( 'oc-blocks', OC_BLOCKS_URI . 'assets/blocks.css', array(), (string) filemtime( OC_BLOCKS_DIR . 'assets/blocks.css' ) );
		wp_enqueue_script( 'oc-blocks', OC_BLOCKS_URI . 'assets/blocks.js', array(), (string) filemtime( OC_BLOCKS_DIR . 'assets/blocks.js' ), array( 'strategy' => 'defer', 'in_footer' => true ) );
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
