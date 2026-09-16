<?php
/**
 * The spam guard's pure parts: the signed timestamp and the verdict.
 */

declare( strict_types = 1 );

namespace OC\Theme\Tests\Guard;

use OC\Theme\Guard;
use PHPUnit\Framework\TestCase;

/**
 * Guard statics.
 */
final class GuardTest extends TestCase {

	public function test_signed_timestamp_round_trips(): void {
		$token = Guard::sign( 1_700_000_000, 'notify', 'salt' );

		$this->assertSame( 12, Guard::age( $token, 'notify', 'salt', 1_700_000_012 ) );
	}

	public function test_forged_or_foreign_timestamp_is_rejected(): void {
		$token = Guard::sign( 1_700_000_000, 'notify', 'salt' );

		$this->assertNull( Guard::age( $token, 'checkout', 'salt', 1_700_000_012 ), 'another form' );
		$this->assertNull( Guard::age( $token, 'notify', 'other', 1_700_000_012 ), 'another salt' );
		$this->assertNull( Guard::age( '1699999000.' . substr( $token, 11 ), 'notify', 'salt', 1_700_000_012 ), 'back-dated' );
		$this->assertNull( Guard::age( 'garbage', 'notify', 'salt', 1_700_000_012 ) );
		$this->assertNull( Guard::age( '', 'notify', 'salt', 1_700_000_012 ) );
	}

	public function test_verdicts(): void {
		$this->assertSame( '', Guard::verdict( '', 30, 0, 10 ) );
		$this->assertSame( 'honey', Guard::verdict( 'http://spam', 30, 0, 10 ) );
		$this->assertSame( 'stale', Guard::verdict( '', null, 0, 10 ) );
		$this->assertSame( 'fast', Guard::verdict( '', 2, 0, 10 ) );
		$this->assertSame( '', Guard::verdict( '', 3, 0, 10 ), 'three seconds is enough' );
		$this->assertSame( 'rate', Guard::verdict( '', 30, 10, 10 ) );
		$this->assertSame( '', Guard::verdict( '', 30, 9, 10 ) );
		$this->assertSame( '', Guard::verdict( '', 30, 500, 0 ), 'no cap' );
	}

	public function test_defaults_cover_every_form(): void {
		$d = Guard::defaults();

		foreach ( Guard::FORMS as $form ) {
			$this->assertArrayHasKey( $form, $d['ts'] );
			$this->assertArrayHasKey( $form, $d['limits'] );
		}
	}
}
