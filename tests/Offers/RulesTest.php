<?php
/**
 * Which rule answers for a product, what may be offered, and what it costs.
 */

declare( strict_types = 1 );

namespace OC\Theme\Tests\Offers;

use OC\Theme\Offers\Rules;
use PHPUnit\Framework\TestCase;

/**
 * Offers\Rules.
 */
final class RulesTest extends TestCase {

	private const BATH    = 10;
	private const SOAP    = 11; // A child of BATH.
	private const HANDLES = 20;
	private const HINGES  = 30;
	private const ZONE    = 500; // A brand.

	/**
	 * A soap dispenser: in Bathroom / Soap, by Zone.
	 *
	 * @return array{id:int,cats:int[],brands:int[]}
	 */
	private function dispenser(): array {
		return array(
			'id'     => 101,
			'cats'   => array( self::SOAP, self::BATH ),
			'brands' => array( self::ZONE ),
		);
	}

	/**
	 * A knob: in Handles, no brand.
	 *
	 * @return array{id:int,cats:int[],brands:int[]}
	 */
	private function knob(): array {
		return array(
			'id'     => 201,
			'cats'   => array( self::HANDLES ),
			'brands' => array(),
		);
	}

	/**
	 * The shop's rules, in its order.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function rules(): array {
		return array(
			'bath'    => Rules::clean(
				array(
					'name'       => 'Bathroom: same brand',
					'on'         => 1,
					'when'       => array( 'cats' => array( self::BATH ) ),
					'offer'      => array( 'cats' => array( self::BATH ) ),
					'same_brand' => 1,
					'pct'        => 10,
				)
			),
			'handles' => Rules::clean(
				array(
					'name'  => 'Handles: what goes with them',
					'on'    => 1,
					'when'  => array( 'cats' => array( self::HANDLES ) ),
					'offer' => array( 'cats' => array( self::HINGES ) ),
				)
			),
			'all'     => Rules::clean(
				array(
					'name'  => 'Everything: a best seller',
					'on'    => 1,
					'offer' => array( 'products' => array( 900 ) ),
				)
			),
		);
	}

	public function test_a_rule_on_a_category_covers_its_children(): void {
		$this->assertSame( 'bath', Rules::pick( $this->rules(), $this->dispenser(), 'cart' ) );
	}

	public function test_the_first_rule_that_fits_wins(): void {
		$this->assertSame( 'handles', Rules::pick( $this->rules(), $this->knob(), 'cart' ) );
	}

	public function test_a_rule_that_names_nothing_covers_every_product(): void {
		$stray = array(
			'id'     => 301,
			'cats'   => array( 77 ),
			'brands' => array(),
		);

		$this->assertSame( 'all', Rules::pick( $this->rules(), $stray, 'cart' ) );
	}

	public function test_a_same_brand_rule_passes_over_a_product_with_no_brand(): void {
		$plain           = $this->dispenser();
		$plain['brands'] = array();

		$this->assertSame( 'all', Rules::pick( $this->rules(), $plain, 'cart' ) );
	}

	public function test_a_rule_switched_off_does_not_answer(): void {
		$rules               = $this->rules();
		$rules['bath']['on'] = 0;

		$this->assertSame( 'all', Rules::pick( $rules, $this->dispenser(), 'cart' ) );
	}

	public function test_a_rule_with_nothing_to_offer_does_not_answer(): void {
		$rules = array(
			'empty' => Rules::clean(
				array(
					'on'   => 1,
					'when' => array( 'cats' => array( self::BATH ) ),
				)
			),
		);

		$this->assertNull( Rules::pick( $rules, $this->dispenser(), 'cart' ) );
	}

	public function test_a_rule_answers_only_in_its_places(): void {
		$this->assertNull( Rules::pick( $this->rules(), $this->dispenser(), 'nowhere' ) );
	}

	public function test_matching_by_product_and_by_brand(): void {
		$by_product = Rules::clean( array( 'when' => array( 'products' => array( 201 ) ) ) );
		$by_brand   = Rules::clean( array( 'when' => array( 'brands' => array( self::ZONE ) ) ) );

		$this->assertTrue( Rules::matches( $by_product, $this->knob() ) );
		$this->assertFalse( Rules::matches( $by_product, $this->dispenser() ) );
		$this->assertTrue( Rules::matches( $by_brand, $this->dispenser() ) );
		$this->assertFalse( Rules::matches( $by_brand, $this->knob() ) );
	}

	public function test_clean_keeps_a_rule_inside_its_limits(): void {
		$rule = Rules::clean(
			array(
				'name'    => "  <b>Bath</b>\n  offers ",
				'on'      => 'yes',
				'when'    => array( 'cats' => array( '10', 10, 0, -4, 'x' ) ),
				'where'   => array( 'cart', 'moon', 'cart' ),
				'pct'     => 250,
				'count'   => 99,
				'order'   => 'sideways',
				'exclude' => '7',
			)
		);

		$this->assertSame( 'Bath offers', $rule['name'] );
		$this->assertSame( 1, $rule['on'] );
		$this->assertSame( array( 10 ), $rule['when']['cats'] );
		$this->assertSame( array( 'cart' ), $rule['where'] );
		$this->assertSame( Rules::MAX_PERCENT, $rule['pct'] );
		$this->assertSame( 12, $rule['count'] );
		$this->assertSame( 'best', $rule['order'] );
		$this->assertSame( array( 7 ), $rule['exclude'] );
	}

	public function test_a_rule_with_no_place_is_shown_in_the_cart(): void {
		$this->assertSame( array( 'cart' ), Rules::clean( array( 'where' => array() ) )['where'] );
	}

	public function test_offerable_drops_the_product_itself_the_cart_and_the_left_out(): void {
		$this->assertSame(
			array( 5, 9 ),
			Rules::offerable( array( 3, 5, 5, 7, 8, 9, 0 ), 3, array( 7 ), array( 8 ) )
		);
	}

	public function test_what_a_percent_takes_off(): void {
		$this->assertSame( 14.3, Rules::off( 143.0, 10 ) );
		$this->assertSame( 0.0, Rules::off( 143.0, 0 ) );
		$this->assertSame( 0.0, Rules::off( 0.0, 10 ) );
		$this->assertSame( 0.0, Rules::off( -5.0, 10 ) );
		$this->assertSame( 90.0, Rules::off( 100.0, 400 ), 'never more than the ceiling' );
		$this->assertSame( 1.0, Rules::off( 9.99, 10, 0 ), 'a currency with no decimals' );
	}

	public function test_only_this_modules_own_sources_pass(): void {
		$this->assertTrue( Rules::is_source( 'manual' ) );
		$this->assertTrue( Rules::is_source( 'auto' ) );
		$this->assertTrue( Rules::is_source( 'r:' . str_repeat( 'a1', 16 ) ) );
		$this->assertFalse( Rules::is_source( 'r:short' ) );
		$this->assertFalse( Rules::is_source( "auto' OR 1=1" ) );
		$this->assertFalse( Rules::is_source( '' ) );
	}

	public function test_tiers_climb_drop_the_single_unit_and_the_repeats(): void {
		$tiers = Rules::clean_tiers(
			array(
				array( 'qty' => 4, 'pct' => 10, 'label' => 'popular' ),
				array( 'qty' => 1, 'pct' => 50 ),
				array( 'qty' => '2', 'pct' => '5', 'label' => 'nope' ),
				array( 'qty' => 4, 'pct' => 12 ),
				array( 'qty' => 8, 'pct' => 150, 'label' => 'best' ),
				'junk',
			)
		);

		$this->assertSame( array( 2, 4, 8 ), array_column( $tiers, 'qty' ) );
		$this->assertSame( array( 5, 12, Rules::MAX_PERCENT ), array_column( $tiers, 'pct' ) );
		$this->assertSame( array( '', '', 'best' ), array_column( $tiers, 'label' ) );
	}

	public function test_the_tier_a_quantity_reaches(): void {
		$tiers = Rules::clean_tiers(
			array(
				array( 'qty' => 2, 'pct' => 5 ),
				array( 'qty' => 4, 'pct' => 10 ),
			)
		);

		$this->assertNull( Rules::tier_for( $tiers, 1 ) );
		$this->assertSame( 5, Rules::tier_for( $tiers, 2 )['pct'] );
		$this->assertSame( 5, Rules::tier_for( $tiers, 3 )['pct'] );
		$this->assertSame( 10, Rules::tier_for( $tiers, 4 )['pct'] );
		$this->assertSame( 10, Rules::tier_for( $tiers, 40 )['pct'] );
		$this->assertSame( 10.8, Rules::unit_at( 12.0, 10 ) );
		$this->assertSame( 12.0, Rules::unit_at( 12.0, 0 ) );
	}

	public function test_the_opening_quantity_must_be_a_tier(): void {
		$tiers = Rules::clean_tiers( array( array( 'qty' => 4, 'pct' => 10 ) ) );

		$this->assertSame( 4, Rules::clean_default( 4, $tiers ) );
		$this->assertSame( 1, Rules::clean_default( 3, $tiers ) );
		$this->assertSame( 1, Rules::clean_default( 4, array() ) );
	}

	public function test_a_tiers_rule_answers_for_tiers_and_never_for_offers(): void {
		$rules = array(
			'legs' => Rules::clean(
				array(
					'on'    => 1,
					'kind'  => 'tiers',
					'when'  => array( 'cats' => array( self::HANDLES ) ),
					'tiers' => array( array( 'qty' => 4, 'pct' => 10 ) ),
				)
			),
			'all'  => Rules::clean(
				array(
					'on'    => 1,
					'offer' => array( 'products' => array( 900 ) ),
				)
			),
		);

		$this->assertSame( 'legs', Rules::pick_tiers( $rules, $this->knob() ) );
		$this->assertNull( Rules::pick_tiers( $rules, $this->dispenser() ) );
		$this->assertSame( 'all', Rules::pick( $rules, $this->knob(), 'cart' ), 'the tiers rule does not offer products' );
	}

	public function test_an_unknown_kind_reads_as_an_offer_rule(): void {
		$this->assertSame( 'offer', Rules::clean( array( 'kind' => 'magic' ) )['kind'] );
	}

	public function test_a_rule_fills_one_product_page_list_at_most(): void {
		$rule = Rules::clean( array( 'where' => array( 'cart', 'xsell', 'similar', 'checkout', 'upsell' ) ) );

		$this->assertSame( array( 'cart', 'xsell', 'checkout' ), $rule['where'], 'the first product-page list named wins' );

		$rule = Rules::clean( array( 'where' => array( 'similar' ) ) );

		$this->assertSame( array( 'similar' ), $rule['where'] );
	}
}
