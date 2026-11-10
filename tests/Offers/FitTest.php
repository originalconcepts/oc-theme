<?php
/**
 * Which of a rule's products fits one product best.
 */

declare( strict_types = 1 );

namespace OC\Theme\Tests\Offers;

use OC\Theme\Offers\Fit;
use PHPUnit\Framework\TestCase;

/**
 * Offers\Fit — the pure scoring.
 */
final class FitTest extends TestCase {

	/**
	 * A profile with the given overrides.
	 *
	 * @param array<string,mixed> $over Fields to set.
	 * @return array<string,mixed>
	 */
	private function profile( array $over ): array {
		return array_merge(
			array(
				'id'        => 1,
				'price'     => 100.0,
				'cats'      => array( 10 => 2 ),
				'brands'    => array(),
				'tags'      => array(),
				'attrs'     => array(),
				'colours'   => array(),
				'materials' => array(),
				'words'     => array(),
			),
			$over
		);
	}

	public function test_tokens_drop_punctuation_and_short_words(): void {
		$this->assertSame( array( 'שולחן', 'סלון', 'oslo', '120' ), Fit::tokens( 'שולחן סלון OSLO, 120 ס״מ' ), 'the quote mark splits ס״מ into single letters, which drop' );
	}

	public function test_colours_read_hebrew_forms_and_english(): void {
		$this->assertSame( array( 'black' ), Fit::colours( array( 'שולחן', 'שחור' ) ) );
		$this->assertSame( array( 'black' ), Fit::colours( array( 'כיסא', 'שחורה' ) ), 'feminine' );
		$this->assertSame( array( 'black' ), Fit::colours( array( 'כיסאות', 'שחורים' ) ), 'plural' );
		$this->assertSame( array( 'black' ), Fit::colours( array( 'כיסא', 'בשחור' ) ), 'prefix' );
		$this->assertSame( array( 'white', 'oak' ), Fit::colours( array( 'white', 'oak' ) ) );
		$this->assertSame( array( 'beige' ), Fit::colours( array( 'קרם' ) ) );
		$this->assertSame( array(), Fit::colours( array( 'שולחן', 'עגול' ) ) );
	}

	public function test_materials(): void {
		$this->assertSame( array( 'wood', 'metal' ), Fit::materials( array( 'שולחן', 'עץ', 'ורגלי', 'מתכת' ) ) );
		$this->assertSame( array( 'glass' ), Fit::materials( array( 'זכוכית' ) ) );
	}

	public function test_colour_fit(): void {
		$this->assertSame( 2.0, Fit::colour_fit( array( 'black' ), array( 'black' ) ), 'same family' );
		$this->assertSame( 1.0, Fit::colour_fit( array( 'black' ), array( 'white' ) ), 'two neutrals' );
		$this->assertSame( 0.5, Fit::colour_fit( array( 'black' ), array( 'red' ) ), 'a neutral beside a colour' );
		$this->assertSame( 0.0, Fit::colour_fit( array( 'blue' ), array( 'red' ) ), 'two colours' );
		$this->assertSame( 0.0, Fit::colour_fit( array(), array( 'red' ) ), 'nothing known' );
	}

	public function test_price_fit_by_place(): void {
		$this->assertEqualsWithDelta( 1.0, Fit::price_fit( 100, 100, 'similar' ), 0.001 );
		$this->assertEqualsWithDelta( 0.0, Fit::price_fit( 100, 300, 'similar' ), 0.001 );
		$this->assertSame( 0.0, Fit::price_fit( 100, 80, 'upsell' ), 'an upgrade is never cheaper' );
		$this->assertEqualsWithDelta( 1.0, Fit::price_fit( 100, 140, 'upsell' ), 0.001 );
		$this->assertSame( 0.6, Fit::price_fit( 100, 40, 'xsell' ), 'an add-on under the price fits' );
		$this->assertEqualsWithDelta( 0.0, Fit::price_fit( 100, 200, 'cart' ), 0.001, 'twice the price does not' );
		$this->assertSame( 0.0, Fit::price_fit( 0, 50, 'cart' ) );
	}

	public function test_a_black_table_prefers_the_black_chair_then_the_white_one(): void {
		$table = $this->profile( array( 'colours' => array( 'black' ), 'materials' => array( 'wood' ), 'words' => array( 'שולחן' ) ) );
		$black = $this->profile( array( 'id' => 2, 'colours' => array( 'black' ), 'price' => 60.0 ) );
		$white = $this->profile( array( 'id' => 3, 'colours' => array( 'white' ), 'price' => 60.0 ) );
		$red   = $this->profile( array( 'id' => 4, 'colours' => array( 'red' ), 'price' => 60.0 ) );
		$none  = $this->profile( array( 'id' => 5, 'price' => 60.0 ) );

		$sb = Fit::score( $table, $black, 'xsell' );
		$sw = Fit::score( $table, $white, 'xsell' );
		$sr = Fit::score( $table, $red, 'xsell' );
		$sn = Fit::score( $table, $none, 'xsell' );

		$this->assertGreaterThan( $sw, $sb );
		$this->assertGreaterThan( $sr, $sw );
		$this->assertGreaterThan( $sn, $sr );
	}

	public function test_material_brand_and_words_add_up(): void {
		$a = $this->profile( array( 'materials' => array( 'wood' ), 'brands' => array( 7 ), 'words' => array( 'oslo', 'סלון' ) ) );
		$b = $this->profile( array( 'id' => 2, 'materials' => array( 'wood' ), 'brands' => array( 7 ), 'words' => array( 'oslo' ) ) );
		$c = $this->profile( array( 'id' => 3 ) );

		// wood 1.0 + brand .8 + one word .35 + the shared category .5 + price .6 = 3.25.
		$this->assertEqualsWithDelta( 3.25, Fit::score( $a, $b, 'xsell' ), 0.001 );
		$this->assertEqualsWithDelta( 1.1, Fit::score( $a, $c, 'xsell' ), 0.001 );
	}

	public function test_similar_leans_on_the_category_and_the_price(): void {
		$a = $this->profile( array( 'cats' => array( 10 => 2, 11 => 3 ) ) );
		$b = $this->profile( array( 'id' => 2, 'cats' => array( 10 => 2, 11 => 3 ) ) );

		$this->assertEqualsWithDelta( 2.5, Fit::score( $a, $b, 'similar' ), 0.001 );
	}

	public function test_jitter_is_small_fixed_and_different_per_pair(): void {
		$this->assertSame( Fit::jitter( 5, 9 ), Fit::jitter( 5, 9 ) );
		$this->assertLessThan( 0.25, Fit::jitter( 5, 9 ) );
		$this->assertGreaterThanOrEqual( 0.0, Fit::jitter( 5, 9 ) );
		$this->assertNotSame( Fit::jitter( 5, 9 ), Fit::jitter( 6, 9 ) );
	}
}
