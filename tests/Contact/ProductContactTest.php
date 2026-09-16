<?php
/**
 * The contact card's pure parts: hours, the message, the two links.
 */

declare( strict_types = 1 );

namespace OC\Theme\Tests\Contact;

use OC\Theme\Product_Contact;
use PHPUnit\Framework\TestCase;

/**
 * Product_Contact statics.
 */
final class ProductContactTest extends TestCase {

	public function test_hours_within_a_day(): void {
		$days = array( 0, 1, 2, 3, 4 );

		$this->assertTrue( Product_Contact::open_now( '09:00', '18:00', '09:00', $days, 1 ) );
		$this->assertTrue( Product_Contact::open_now( '09:00', '18:00', '17:59', $days, 4 ) );
		$this->assertFalse( Product_Contact::open_now( '09:00', '18:00', '18:00', $days, 1 ) );
		$this->assertFalse( Product_Contact::open_now( '09:00', '18:00', '08:59', $days, 1 ) );
		$this->assertFalse( Product_Contact::open_now( '09:00', '18:00', '12:00', $days, 5 ), 'Friday is not a working day' );
		$this->assertFalse( Product_Contact::open_now( '10:00', '10:00', '10:00', $days, 1 ), 'an empty window is never open' );
	}

	public function test_hours_over_midnight(): void {
		$days = array( 0, 1, 2, 3, 4 );

		$this->assertTrue( Product_Contact::open_now( '20:00', '02:00', '23:30', $days, 4 ), 'Thursday night, late half' );
		$this->assertTrue( Product_Contact::open_now( '20:00', '02:00', '01:00', $days, 5 ), 'early Friday belongs to Thursday' );
		$this->assertFalse( Product_Contact::open_now( '20:00', '02:00', '01:00', $days, 0 ), 'early Sunday belongs to Saturday, a day off' );
		$this->assertFalse( Product_Contact::open_now( '20:00', '02:00', '12:00', $days, 1 ) );
	}

	public function test_message_tokens_both_spellings(): void {
		$out = Product_Contact::message( "Hi about [product]\nLink: [link]", 'Bin 30L', 'https://x.test/p/1' );
		$this->assertSame( "Hi about Bin 30L\nLink: https://x.test/p/1", $out );

		$he = Product_Contact::message( 'היי, מעוניין בעזרה על [שם המוצר] [קישור המוצר] [קישור]', 'פח', 'https://x.test/p' );
		$this->assertSame( 'היי, מעוניין בעזרה על פח https://x.test/p https://x.test/p', $he );
	}

	public function test_numbers_and_links(): void {
		$this->assertSame( '972545722557', Product_Contact::digits( '054-572-2557' ) );
		$this->assertSame( '972545722557', Product_Contact::digits( '+972 54-572-2557' ) );
		$this->assertSame( '972545722557', Product_Contact::digits( '00972545722557' ) );
		$this->assertSame( '447955572941', Product_Contact::digits( '0795 557 2941', '44' ) );
		$this->assertSame( '', Product_Contact::digits( 'abc' ) );

		$this->assertSame( 'https://wa.me/972545722557?text=Hi%20there', Product_Contact::wa_link( '054-572-2557', 'Hi there' ) );
		$this->assertSame( 'https://wa.me/972545722557', Product_Contact::wa_link( '054-572-2557', '' ) );
		$this->assertSame( 'tel:+972545722557', Product_Contact::tel_link( '054-572-2557' ) );
		$this->assertSame( '', Product_Contact::wa_link( '', 'x' ) );
	}

	public function test_clock_validation(): void {
		$this->assertSame( '09:30', Product_Contact::clock( '09:30', '00:00' ) );
		$this->assertSame( '00:00', Product_Contact::clock( '9:30', '00:00' ) );
		$this->assertSame( '00:00', Product_Contact::clock( '25:00', '00:00' ) );
	}
}
