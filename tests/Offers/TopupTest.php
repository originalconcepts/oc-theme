<?php
/**
 * The top-up sheet's price bands: how wide "just over the gap" is.
 */

declare( strict_types = 1 );

namespace OC\Tests\Offers;

use OC\Theme\Offers\Topup;
use PHPUnit\Framework\TestCase;

/**
 * Topup::step().
 */
final class TopupTest extends TestCase {

	/**
	 * About a quarter of the gap, rounded up to a round number.
	 *
	 * @return array<string,array{float,float}>
	 */
	public static function steps(): array {
		return array(
			'200 missing' => array( 200.0, 50.0 ),
			'120 missing' => array( 120.0, 30.0 ),
			'85 missing'  => array( 85.0, 30.0 ),
			'37 missing'  => array( 37.0, 10.0 ),
			'1500'        => array( 1500.0, 400.0 ),
			'tiny'        => array( 2.0, 1.0 ),
		);
	}

	/**
	 * @dataProvider steps
	 */
	public function test_step( float $left, float $want ): void {
		self::assertSame( $want, Topup::step( $left ) );
	}

	/**
	 * A price rounded up to half a magnitude.
	 *
	 * @return array<string,array{float,float}>
	 */
	public static function nices(): array {
		return array(
			'1170' => array( 1170.0, 1500.0 ),
			'2400' => array( 2400.0, 2500.0 ),
			'1000' => array( 1000.0, 1000.0 ),
			'90'   => array( 90.0, 90.0 ),
			'4990' => array( 4990.0, 5000.0 ),
			'7'    => array( 6.4, 7.0 ),
		);
	}

	/**
	 * @dataProvider nices
	 */
	public function test_nice( float $price, float $want ): void {
		self::assertSame( $want, Topup::nice( $price ) );
	}

	/**
	 * The tier edges: never the same number twice.
	 */
	public function test_edges_never_collapse(): void {
		self::assertSame( array( 1500.0, 2000.0 ), Topup::edges( array( 1000.0, 1100.0, 1200.0, 1300.0, 1500.0, 1700.0 ) ) );
		self::assertSame( array( 1000.0, 5000.0 ), Topup::edges( array( 50.0, 300.0, 900.0, 2400.0, 4990.0, 9000.0 ) ) );
		self::assertSame( array( 1000.0, 1500.0 ), Topup::edges( array( 1000.0, 1000.0, 1000.0 ) ) );
	}

	public function test_step_is_never_below_one(): void {
		self::assertGreaterThanOrEqual( 1.0, Topup::step( 0.0 ) );
		self::assertGreaterThanOrEqual( 1.0, Topup::step( 0.4 ) );
	}
}
