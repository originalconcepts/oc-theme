<?php
/**
 * The shipping calculator, on a table of parcels.
 *
 * Every row is a cart a real shopper could build on mg4 — and the price
 * the shop has promised for it. A change that moves any of these prices
 * does not ship.
 */

declare( strict_types = 1 );

namespace OC\Tests\Shipping;

use OC\Theme\Shipping\Quote;
use OC\Theme\Shipping\Rules;
use PHPUnit\Framework\TestCase;

final class QuoteTest extends TestCase {

	/**
	 * mg4's rules: 39 nationwide, free over 400, electrical priced at 100
	 * and outside free delivery, Eilat 69 with no free delivery.
	 *
	 * @return array<string,mixed>
	 */
	private function mg4(): array {
		return Rules::normalize(
			array(
				'enabled'   => true,
				'base'      => 39,
				'free_over' => 400,
				'groups'    => array(
					array(
						'slug'    => 'electric',
						'name'    => 'מוצרי חשמל למטבח',
						'price'   => 100,
						'in_free' => false,
					),
				),
				'regions'   => array(
					array(
						'name'      => 'אילת',
						'postcodes' => '88000-88999',
						'cities'    => 'אילת',
						'price'     => 69,
						'free'      => 'no',
					),
				),
			)
		);
	}

	/**
	 * @param float  $subtotal Line subtotal.
	 * @param string $group    Group slug.
	 * @return array{subtotal:float,group:string,qty:int}
	 */
	private function line( float $subtotal, string $group = '' ): array {
		return array(
			'subtotal' => $subtotal,
			'group'    => $group,
			'qty'      => 1,
		);
	}

	/**
	 * The table.
	 *
	 * @return array<string,array{0:array,1:array,2:float,3:bool}>
	 */
	public static function parcels(): array {
		$il    = array( 'country' => 'IL', 'postcode' => '4000000', 'city' => 'תל אביב' );
		$eilat = array( 'country' => 'IL', 'postcode' => '88000', 'city' => '' );

		return array(
			'one cheap item'                    => array( array( array( 'subtotal' => 195.0, 'group' => '', 'qty' => 1 ) ), $il, 39.0, false ),
			'two items over the line'           => array( array( array( 'subtotal' => 195.0, 'group' => '', 'qty' => 1 ), array( 'subtotal' => 250.0, 'group' => '', 'qty' => 1 ) ), $il, 0.0, true ),
			'exactly on the line'               => array( array( array( 'subtotal' => 400.0, 'group' => '', 'qty' => 1 ) ), $il, 0.0, true ),
			'one electrical item, cheap'        => array( array( array( 'subtotal' => 150.0, 'group' => 'electric', 'qty' => 1 ) ), $il, 100.0, false ),
			'one electrical item, dear'         => array( array( array( 'subtotal' => 900.0, 'group' => 'electric', 'qty' => 1 ) ), $il, 100.0, false ),
			'electrical plus regular, under'    => array( array( array( 'subtotal' => 150.0, 'group' => 'electric', 'qty' => 1 ), array( 'subtotal' => 100.0, 'group' => '', 'qty' => 1 ) ), $il, 100.0, false ),
			'electrical plus regular over line' => array( array( array( 'subtotal' => 150.0, 'group' => 'electric', 'qty' => 1 ), array( 'subtotal' => 450.0, 'group' => '', 'qty' => 1 ) ), $il, 100.0, false ),
			'electrical does not lift regular'  => array( array( array( 'subtotal' => 900.0, 'group' => 'electric', 'qty' => 1 ), array( 'subtotal' => 100.0, 'group' => '', 'qty' => 1 ) ), $il, 100.0, false ),
			'eilat cheap'                       => array( array( array( 'subtotal' => 195.0, 'group' => '', 'qty' => 1 ) ), $eilat, 69.0, false ),
			'eilat over the line, still paid'   => array( array( array( 'subtotal' => 900.0, 'group' => '', 'qty' => 1 ) ), $eilat, 69.0, false ),
			'eilat with electrical'             => array( array( array( 'subtotal' => 900.0, 'group' => 'electric', 'qty' => 1 ) ), $eilat, 100.0, false ),
			'eilat by city name'                => array( array( array( 'subtotal' => 100.0, 'group' => '', 'qty' => 1 ) ), array( 'country' => 'IL', 'postcode' => '', 'city' => ' אילת ' ), 69.0, false ),
			'unknown group counts as regular'   => array( array( array( 'subtotal' => 450.0, 'group' => 'furniture', 'qty' => 1 ) ), $il, 0.0, true ),
			'empty parcel'                      => array( array(), $il, 39.0, false ),
		);
	}

	/**
	 * @dataProvider parcels
	 *
	 * @param array $lines Lines.
	 * @param array $dest  Destination.
	 * @param float $cost  Expected cost.
	 * @param bool  $free  Expected free flag.
	 */
	public function test_parcel( array $lines, array $dest, float $cost, bool $free ): void {
		$quote = Quote::calculate( $lines, $dest, $this->mg4() );

		$this->assertSame( $cost, $quote['cost'] );
		$this->assertSame( $free, $quote['free'] );
	}

	public function test_missing_counts_only_eligible_lines(): void {
		$quote = Quote::calculate( array( $this->line( 900.0, 'electric' ), $this->line( 100.0 ) ), array(), $this->mg4() );

		$this->assertSame( 100.0, $quote['eligible'] );
		$this->assertSame( 300.0, $quote['missing'] );
	}

	public function test_missing_is_zero_where_free_delivery_never_applies(): void {
		$quote = Quote::calculate( array( $this->line( 100.0 ) ), array( 'postcode' => '88100' ), $this->mg4() );

		$this->assertSame( 0.0, $quote['threshold'] );
		$this->assertSame( 0.0, $quote['missing'] );
		$this->assertSame( 'אילת', $quote['region'] );
	}

	public function test_sum_mode_adds_the_group_to_the_base(): void {
		$rules         = $this->mg4();
		$rules['mode'] = 'sum';

		$quote = Quote::calculate( array( $this->line( 150.0, 'electric' ), $this->line( 100.0 ) ), array(), $rules );

		$this->assertSame( 139.0, $quote['cost'] );
	}

	public function test_a_group_is_charged_once_however_many_units(): void {
		$quote = Quote::calculate( array( $this->line( 150.0, 'electric' ), $this->line( 150.0, 'electric' ), $this->line( 500.0 ) ), array(), $this->mg4() );

		$this->assertSame( 100.0, $quote['cost'] );
		$this->assertSame( array( 'electric' ), $quote['groups'] );
	}

	public function test_no_free_line_means_never_free(): void {
		$rules              = $this->mg4();
		$rules['free_over'] = 0.0;

		$quote = Quote::calculate( array( $this->line( 5000.0 ) ), array(), $rules );

		$this->assertSame( 39.0, $quote['cost'] );
		$this->assertFalse( $quote['free'] );
	}

	public function test_reasons_name_what_happened(): void {
		$quote = Quote::calculate( array( $this->line( 900.0, 'electric' ) ), array( 'postcode' => '88000' ), $this->mg4() );
		$codes = array_column( $quote['reasons'], 'code' );

		$this->assertSame( array( 'region', 'group' ), $codes );
	}

	public function test_postcode_rules(): void {
		$this->assertTrue( Quote::postcode_matches( '88123', '88000-88999' ) );
		$this->assertFalse( Quote::postcode_matches( '89000', '88000-88999' ) );
		$this->assertTrue( Quote::postcode_matches( '8812345', '88*' ) );
		$this->assertTrue( Quote::postcode_matches( '4000000', '4000000' ) );
		$this->assertFalse( Quote::postcode_matches( '4000000', '' ) );
	}

	public function test_normalize_fills_and_types_everything(): void {
		$rules = Rules::normalize( array( 'base' => '39', 'groups' => array( 'electric' => array( 'price' => '100' ) ) ) );

		$this->assertFalse( $rules['enabled'] );
		$this->assertSame( 39.0, $rules['base'] );
		$this->assertSame( 'max', $rules['mode'] );
		$this->assertSame( 'electric', $rules['groups']['electric']['slug'] );
		$this->assertSame( 100.0, $rules['groups']['electric']['price'] );
		$this->assertFalse( $rules['groups']['electric']['in_free'] );
		$this->assertSame( array(), $rules['regions'] );
	}
}
