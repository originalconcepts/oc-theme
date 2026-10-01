<?php
/**
 * The wireframes the questionnaire shows instead of describing a layout.
 *
 * A customer cannot answer "half-split hero or full width?" from words. Each
 * of these is a small grey drawing of the same page, where only the thing
 * being asked about changes; everything else stays identical on purpose, so
 * the eye lands on the difference and nothing else.
 *
 * They are drawn, not photographed: a screenshot of our demo would date the
 * moment the demo changes, and would promise a design we are not selling.
 *
 * @package OC_Theme
 */

declare( strict_types = 1 );

namespace OC\Theme\Onboard;

defined( 'ABSPATH' ) || exit;

/**
 * Inline wireframes, keyed by name.
 */
final class Art {

	/**
	 * The paper.
	 */
	const W = 320;

	/**
	 * How tall a drawing is.
	 */
	const H = 240;

	/**
	 * A rectangle.
	 *
	 * @param float  $x    Left.
	 * @param float  $y    Top.
	 * @param float  $w    Width.
	 * @param float  $h    Height.
	 * @param string $tone base | strong | brand.
	 * @param float  $r    Corner radius.
	 */
	private static function box( float $x, float $y, float $w, float $h, string $tone = 'base', float $r = 3 ): string {
		return sprintf(
			'<rect x="%s" y="%s" width="%s" height="%s" rx="%s" class="a-%s"/>',
			$x,
			$y,
			$w,
			$h,
			$r,
			$tone
		);
	}

	/**
	 * A run of text, as lines.
	 *
	 * @param float  $x    Left.
	 * @param float  $y    Top.
	 * @param float  $w    Width of the longest line.
	 * @param int    $n    How many lines.
	 * @param string $tone Tone.
	 */
	private static function lines( float $x, float $y, float $w, int $n = 2, string $tone = 'base' ): string {
		$out = '';

		for ( $i = 0; $i < $n; $i++ ) {
			$out .= self::box( $x, $y + ( $i * 7 ), $i === $n - 1 ? $w * 0.6 : $w, 3.5, $tone, 1.75 );
		}

		return $out;
	}

	/**
	 * A row of same-sized blocks.
	 *
	 * @param float  $x    Left of the row.
	 * @param float  $y    Top.
	 * @param float  $w    Width of the row.
	 * @param float  $h    Height of a block.
	 * @param int    $n    How many.
	 * @param string $tone Tone.
	 */
	private static function row( float $x, float $y, float $w, float $h, int $n, string $tone = 'base' ): string {
		$gap  = 6;
		$each = ( $w - ( $gap * ( $n - 1 ) ) ) / $n;
		$out  = '';

		for ( $i = 0; $i < $n; $i++ ) {
			$out .= self::box( $x + ( $i * ( $each + $gap ) ), $y, $each, $h, $tone );
		}

		return $out;
	}

	/**
	 * The furniture every drawing shares: a header with a logo and a menu,
	 * and a footer band. Drawn faintly — it is never what is being asked.
	 */
	private static function chrome(): string {
		return self::box( 0, 0, self::W, 18, 'faint', 0 )
			. self::box( 12, 6, 34, 6, 'base', 2 )
			. self::row( 180, 7, 128, 4, 4, 'base' )
			. self::box( 0, self::H - 26, self::W, 26, 'faint', 0 )
			. self::row( 12, self::H - 18, 160, 4, 3, 'base' );
	}

	/**
	 * One drawing, wrapped.
	 *
	 * @param string $body The shapes.
	 */
	private static function svg( string $body ): string {
		return '<svg viewBox="0 0 ' . self::W . ' ' . self::H . '" role="img" focusable="false" xmlns="http://www.w3.org/2000/svg">'
			. self::chrome()
			. $body
			. '</svg>';
	}

	/**
	 * Every drawing, keyed by the name a field refers to.
	 *
	 * @return array<string,string>
	 */
	public static function all(): array {
		$art = array();

		/* ---- home page recipes ---- */

		// Classic: one picture, the categories, a shelf of products, a word
		// about the business.
		$art['home_classic'] = self::svg(
			self::box( 0, 18, self::W, 78, 'strong', 0 )
			. self::lines( 110, 44, 100, 2, 'brand' )
			. self::box( 134, 66, 52, 10, 'brand', 5 )
			. self::row( 12, 106, 296, 42, 3 )
			. self::row( 12, 156, 296, 48, 4 )
		);

		// The selling one: an offer strip under the banner and a shelf that
		// starts higher up.
		$art['home_sales'] = self::svg(
			self::box( 0, 18, self::W, 64, 'strong', 0 )
			. self::lines( 110, 36, 100, 2, 'brand' )
			. self::box( 134, 58, 52, 10, 'brand', 5 )
			. self::box( 0, 82, self::W, 14, 'brand', 0 )
			. self::row( 12, 102, 296, 52, 4 )
			. self::row( 12, 160, 296, 44, 3 )
		);

		// The fashion one: a tall picture, then a styled scene with products
		// hanging off it.
		$art['home_look'] = self::svg(
			self::box( 0, 18, self::W, 96, 'strong', 0 )
			. self::lines( 110, 52, 100, 2, 'brand' )
			. self::box( 12, 122, 170, 82, 'strong' )
			. self::box( 60, 150, 10, 10, 'brand', 5 )
			. self::box( 120, 180, 10, 10, 'brand', 5 )
			. self::box( 190, 122, 118, 38 )
			. self::box( 190, 166, 118, 38 )
		);

		/* ---- the category page ---- */

		// A band across the top with the category's name on it.
		$art['cat_hero_full'] = self::svg(
			self::box( 0, 18, self::W, 54, 'strong', 0 )
			. self::lines( 118, 36, 84, 2, 'brand' )
			. self::row( 12, 82, 296, 46, 3 )
			. self::row( 12, 134, 296, 46, 3 )
			. self::row( 12, 186, 296, 20, 3 )
		);

		// Half picture, half words.
		$art['cat_hero_split'] = self::svg(
			self::box( 0, 18, 160, 54, 'strong', 0 )
			. self::box( 160, 18, 160, 54, 'faint', 0 )
			. self::lines( 180, 34, 110, 3, 'brand' )
			. self::row( 12, 82, 296, 46, 3 )
			. self::row( 12, 134, 296, 46, 3 )
			. self::row( 12, 186, 296, 20, 3 )
		);

		// Straight to the goods.
		$art['cat_hero_none'] = self::svg(
			self::lines( 12, 30, 90, 1, 'brand' )
			. self::row( 12, 48, 296, 50, 3 )
			. self::row( 12, 104, 296, 50, 3 )
			. self::row( 12, 160, 296, 46, 3 )
		);

		/* ---- the strip of sub-categories ---- */

		$art['strip_clean'] = self::svg(
			self::lines( 12, 30, 90, 1, 'brand' )
			. self::row( 12, 46, 200, 5, 4 )
			. self::row( 12, 62, 296, 52, 3 )
			. self::row( 12, 120, 296, 52, 3 )
		);

		$art['strip_pill'] = self::svg(
			self::lines( 12, 30, 90, 1, 'brand' )
			. self::row( 12, 44, 230, 14, 4, 'strong' )
			. self::row( 12, 66, 296, 52, 3 )
			. self::row( 12, 124, 296, 52, 3 )
		);

		$art['strip_card'] = self::svg(
			self::lines( 12, 30, 90, 1, 'brand' )
			. self::row( 12, 44, 296, 34, 4, 'strong' )
			. self::row( 12, 84, 296, 54, 3 )
			. self::row( 12, 144, 296, 54, 3 )
		);

		/* ---- how many products in a row ---- */

		$art['grid_2'] = self::svg(
			self::row( 12, 30, 296, 76, 2 )
			. self::row( 12, 112, 296, 76, 2 )
		);

		$art['grid_3'] = self::svg(
			self::row( 12, 30, 296, 54, 3 )
			. self::row( 12, 90, 296, 54, 3 )
			. self::row( 12, 150, 296, 54, 3 )
		);

		$art['grid_4'] = self::svg(
			self::row( 12, 30, 296, 42, 4 )
			. self::row( 12, 78, 296, 42, 4 )
			. self::row( 12, 126, 296, 42, 4 )
			. self::row( 12, 174, 296, 32, 4 )
		);

		/* ---- the product page ---- */

		// The picture beside what you need to know.
		$art['prod_side'] = self::svg(
			self::box( 164, 30, 144, 128, 'strong' )
			. self::lines( 12, 34, 130, 2, 'base' )
			. self::lines( 12, 58, 70, 1, 'brand' )
			. self::lines( 12, 76, 130, 3, 'base' )
			. self::box( 12, 110, 130, 16, 'brand', 8 )
			. self::row( 164, 164, 144, 24, 4 )
			. self::row( 12, 172, 130, 14, 2 )
		);

		// The picture first, the words under it.
		$art['prod_below'] = self::svg(
			self::box( 12, 30, 296, 92, 'strong' )
			. self::lines( 12, 132, 150, 2, 'base' )
			. self::lines( 12, 156, 70, 1, 'brand' )
			. self::box( 12, 176, 130, 16, 'brand', 8 )
			. self::lines( 164, 132, 144, 4, 'base' )
		);

		/* ---- the brand page ---- */

		$art['brand_above'] = self::svg(
			self::box( 130, 32, 60, 30, 'strong' )
			. self::lines( 90, 72, 140, 2, 'base' )
			. self::row( 12, 100, 296, 50, 3 )
			. self::row( 12, 156, 296, 50, 3 )
		);

		$art['brand_beside'] = self::svg(
			self::box( 12, 32, 60, 30, 'strong' )
			. self::lines( 86, 34, 222, 3, 'base' )
			. self::row( 12, 76, 296, 58, 3 )
			. self::row( 12, 140, 296, 58, 3 )
		);

		return $art;
	}
}
