<?php
/**
 * OC Guide — the documentation site for the OC theme.
 *
 * A child of oc-theme so the brand carries over, but with its own
 * templates: a shop's header has a basket in it, and a guide does not.
 *
 * @package OC_Guide
 */

declare( strict_types = 1 );

namespace OC\Guide;

defined( 'ABSPATH' ) || exit;

const SECTIONS = array(
	'content'    => array( 'name' => 'תוכן', 'blurb' => 'דפים, פוסטים ובניית עמודים עם עורך העמוד.' ),
	'products'   => array( 'name' => 'מוצרים', 'blurb' => 'יצירת מוצר, מחירים, צבעים, תמונות ווידאו.' ),
	'categories' => array( 'name' => 'קטגוריות', 'blurb' => 'קטגוריות ותתי קטגוריות, באנרים, סליידרים וסדר מוצרים.' ),
	'promotions' => array( 'name' => 'מבצעים', 'blurb' => 'קופונים ומבצעים: 1+1, כמותי, אחוזי הנחה ועוד.' ),
	'settings'   => array( 'name' => 'הגדרות', 'blurb' => 'הפניות 301, SEO, פרטי האתר וכתובות.' ),
	'advanced'   => array( 'name' => 'מתקדם', 'blurb' => 'הדר, מבנה קטלוג ומוצר, עיצוב גלובלי — למי שמקים אתר.' ),
);

/**
 * The section a guide belongs to, by its category slug.
 *
 * @param int $post_id Post.
 * @return array{slug:string,name:string}|null
 */
function section_of( int $post_id ): ?array {
	foreach ( (array) get_the_category( $post_id ) as $cat ) {
		if ( isset( SECTIONS[ $cat->slug ] ) ) {
			return array( 'slug' => $cat->slug, 'name' => SECTIONS[ $cat->slug ]['name'] );
		}
	}

	return null;
}

/**
 * Pull the headings out of a guide so the aside can list them. Done on the
 * rendered HTML rather than the raw content, because that is what the reader
 * actually sees — and it gives every heading a stable id to jump to.
 *
 * @param string $html Rendered content.
 * @return array{html:string,toc:array<int,array{id:string,text:string}>}
 */
function with_toc( string $html ): array {
	$toc = array();

	$html = (string) preg_replace_callback(
		'#<h2([^>]*)>(.*?)</h2>#is',
		static function ( array $m ) use ( &$toc ): string {
			$text = trim( wp_strip_all_tags( $m[2] ) );

			if ( '' === $text ) {
				return $m[0];
			}

			$id = 'g-' . ( count( $toc ) + 1 );
			$toc[] = array( 'id' => $id, 'text' => $text );

			return '<h2 id="' . esc_attr( $id ) . '"' . $m[1] . '>' . $m[2] . '</h2>';
		},
		$html
	);

	return array( 'html' => $html, 'toc' => $toc );
}

/**
 * Guides related to this one: whatever it links to by slug, and failing that
 * its neighbours in the same section.
 *
 * @param int $post_id Post.
 * @return \WP_Post[]
 */
function related( int $post_id ): array {
	$slugs = (array) get_post_meta( $post_id, '_g_related', true );
	$out   = array();

	foreach ( $slugs as $slug ) {
		$p = get_page_by_path( (string) $slug, OBJECT, 'post' );

		if ( $p instanceof \WP_Post && $p->ID !== $post_id ) {
			$out[] = $p;
		}
	}

	if ( count( $out ) >= 3 ) {
		return array_slice( $out, 0, 5 );
	}

	$section = section_of( $post_id );

	if ( null === $section ) {
		return $out;
	}

	$more = get_posts(
		array(
			'category_name'  => $section['slug'],
			'post__not_in'   => array_merge( array( $post_id ), wp_list_pluck( $out, 'ID' ) ),
			'posts_per_page' => 5 - count( $out ),
			'orderby'        => 'menu_order title',
			'order'          => 'ASC',
		)
	);

	return array_merge( $out, $more );
}

/**
 * How many guides a section holds.
 *
 * @param string $slug Category slug.
 * @return int
 */
function count_in( string $slug ): int {
	$cat = get_category_by_slug( $slug );

	return $cat instanceof \WP_Term ? (int) $cat->count : 0;
}

/**
 * The masthead's section links.
 */
function nav_html(): string {
	$current = '';

	if ( is_category() ) {
		$term    = get_queried_object();
		$current = $term instanceof \WP_Term ? $term->slug : '';
	} elseif ( is_single() ) {
		$section = section_of( (int) get_the_ID() );
		$current = null === $section ? '' : $section['slug'];
	}

	$out = '';

	foreach ( SECTIONS as $slug => $s ) {
		$link = get_category_link( (int) ( get_category_by_slug( $slug )->term_id ?? 0 ) );

		$out .= sprintf(
			'<a href="%s"%s>%s</a>',
			esc_url( (string) $link ),
			$slug === $current ? ' class="is-on"' : '',
			esc_html( $s['name'] )
		);
	}

	return $out;
}

/**
 * The search field, in the one shape the whole site uses.
 *
 * @param string $placeholder Placeholder.
 */
function search_html( string $placeholder = 'חיפוש במדריך…' ): string {
	return sprintf(
		'<form class="g-search" role="search" method="get" action="%s">
			<input type="search" name="s" value="%s" placeholder="%s" autocomplete="off" />
			<button class="g-search__go" type="submit" aria-label="חיפוש"><svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.6-3.6"/></svg></button>
		</form>',
		esc_url( home_url( '/' ) ),
		esc_attr( get_search_query() ),
		esc_attr( $placeholder )
	);
}

/**
 * The guide is written in Hebrew, so it reads right to left — whatever the
 * WordPress locale of the install happens to be. Without this the page comes
 * out `lang="en-US"` with no direction, and every numbered step, breadcrumb
 * and search icon lands on the wrong side.
 *
 * The admin is left alone: an English admin turned RTL is worse than either.
 */
add_filter(
	'locale',
	static function ( $locale ) {
		return is_admin() ? $locale : 'he_IL';
	}
);

add_action(
	'after_setup_theme',
	static function (): void {
		if ( ! is_admin() && isset( $GLOBALS['wp_locale'] ) ) {
			$GLOBALS['wp_locale']->text_direction = 'rtl';
		}
	},
	0
);

/**
 * The child's own stylesheet, after the parent's.
 */
add_action(
	'wp_enqueue_scripts',
	static function (): void {
		$css = get_stylesheet_directory() . '/style.css';

		wp_enqueue_style(
			'oc-guide',
			get_stylesheet_uri(),
			array(),
			file_exists( $css ) ? (string) filemtime( $css ) : '1.0.0'
		);
	},
	20
);

/**
 * A body class the guide styles can hang off without fighting the parent.
 */
add_filter(
	'body_class',
	static function ( array $classes ): array {
		$classes[] = 'oc-guide';

		return $classes;
	}
);

/**
 * Search looks at guides only, and shows them all rather than paging a
 * documentation site into fragments.
 */
add_action(
	'pre_get_posts',
	static function ( \WP_Query $q ): void {
		if ( is_admin() || ! $q->is_main_query() ) {
			return;
		}

		if ( $q->is_search() ) {
			$q->set( 'post_type', 'post' );
			$q->set( 'posts_per_page', 40 );
		}

		if ( $q->is_category() ) {
			$q->set( 'posts_per_page', 60 );
			$q->set( 'orderby', 'menu_order title' );
			$q->set( 'order', 'ASC' );
		}
	}
);
