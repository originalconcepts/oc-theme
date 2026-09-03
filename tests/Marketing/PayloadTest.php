<?php
/**
 * One event, three dialects — the translations must hold.
 */

declare( strict_types = 1 );

namespace OC\Tests\Marketing;

use OC\Theme\Marketing\Payload;
use OC\Theme\Marketing\Settings;
use PHPUnit\Framework\TestCase;

final class PayloadTest extends TestCase {

	private function order(): array {
		return array(
			'currency' => 'ILS',
			'value'    => 1234.5,
			'shipping' => 39.0,
			'tax'      => 0.0,
			'order_id' => '5001',
			'items'    => array(
				array( 'id' => '31963', 'name' => 'מתקן לייבוש כלים', 'price' => 195.0, 'qty' => 2, 'category' => 'מטבח' ),
				array( 'id' => '31766', 'name' => 'פח אשפה', 'price' => 844.5, 'qty' => 1, 'category' => 'פחי אשפה' ),
			),
		);
	}

	public function test_email_and_phone_normalize_the_way_the_networks_hash(): void {
		$this->assertSame( 'george@example.com', Payload::norm_email( '  George@Example.com ' ) );
		$this->assertSame( '972501234567', Payload::norm_phone( '050-123-4567' ) );
		$this->assertSame( '972501234567', Payload::norm_phone( '+972 50 123 4567' ) );
		$this->assertSame( '4915112345678', Payload::norm_phone( '0049 151 12345678', 'DE' ) );
		$this->assertSame( '', Payload::norm_phone( '' ) );
		$this->assertSame( hash( 'sha256', 'george@example.com' ), Payload::hash( 'george@example.com' ) );
		$this->assertSame( '', Payload::hash( '' ) );
	}

	public function test_meta_purchase_carries_everything_meta_matches_on(): void {
		$ev = Payload::fb(
			'Purchase',
			$this->order(),
			'order_5001',
			array( 'em' => 'George@Example.com', 'ph' => '0501234567', 'fn' => 'George', 'ct' => 'תל אביב', 'zp' => '61 000', 'country' => 'IL', 'external_id' => '7' ),
			array( 'ip' => '1.2.3.4', 'ua' => 'UA', 'fbp' => 'fb.1.1', 'fbc' => 'fb.1.2' ),
			'https://mg4.mywebsite.co.il/checkout/order-received/5001/',
			1700000000
		);

		$this->assertSame( 'Purchase', $ev['event_name'] );
		$this->assertSame( 'order_5001', $ev['event_id'] );
		$this->assertSame( 'website', $ev['action_source'] );
		$this->assertSame( array( hash( 'sha256', 'george@example.com' ) ), $ev['user_data']['em'] );
		$this->assertSame( array( hash( 'sha256', '972501234567' ) ), $ev['user_data']['ph'] );
		$this->assertSame( array( hash( 'sha256', 'george' ) ), $ev['user_data']['fn'] );
		$this->assertSame( array( hash( 'sha256', '61000' ) ), $ev['user_data']['zp'] );
		$this->assertSame( array( hash( 'sha256', 'il' ) ), $ev['user_data']['country'] );
		$this->assertSame( '1.2.3.4', $ev['user_data']['client_ip_address'] );
		$this->assertSame( 'fb.1.2', $ev['user_data']['fbc'] );
		$this->assertSame( 1234.5, $ev['custom_data']['value'] );
		$this->assertSame( 'ILS', $ev['custom_data']['currency'] );
		$this->assertSame( array( '31963', '31766' ), $ev['custom_data']['content_ids'] );
		$this->assertSame( 'product', $ev['custom_data']['content_type'] );
		$this->assertSame( 3, $ev['custom_data']['num_items'] );
		$this->assertSame( '5001', $ev['custom_data']['order_id'] );
		$this->assertSame( 2, $ev['custom_data']['contents'][0]['quantity'] );
	}

	public function test_meta_skips_empty_matching_fields(): void {
		$ev = Payload::fb( 'ViewContent', array( 'currency' => 'ILS', 'value' => 10 ), 'x', array( 'em' => '', 'ph' => '' ), array(), '/', 1 );

		$this->assertSame( array(), $ev['user_data'] );
		$this->assertArrayNotHasKey( 'content_ids', $ev['custom_data'] );
	}

	public function test_search_travels_as_a_search_string(): void {
		$ev = Payload::fb( 'Search', array( 'search' => 'מיקסר' ), 'x', array(), array(), '/', 1 );

		$this->assertSame( 'Search', $ev['event_name'] );
		$this->assertSame( 'מיקסר', $ev['custom_data']['search_string'] );
	}

	public function test_tiktok_purchase_is_complete_payment_with_hashed_user(): void {
		$ev = Payload::tiktok( 'Purchase', $this->order(), 'order_5001', array( 'em' => 'a@b.co', 'ph' => '0501234567', 'external_id' => '7' ), array( 'ip' => '1.2.3.4', 'ttclid' => 'tt1' ), 'https://x/', 1700000000 );

		$this->assertSame( 'CompletePayment', $ev['event'] );
		$this->assertSame( 'order_5001', $ev['event_id'] );
		$this->assertSame( hash( 'sha256', 'a@b.co' ), $ev['user']['email'] );
		$this->assertSame( hash( 'sha256', '+972501234567' ), $ev['user']['phone'] );
		$this->assertSame( 'tt1', $ev['user']['ttclid'] );
		$this->assertSame( 1234.5, $ev['properties']['value'] );
		$this->assertSame( '31963', $ev['properties']['contents'][0]['content_id'] );
		$this->assertSame( '5001', $ev['properties']['order_id'] );
	}

	public function test_tiktok_has_no_word_for_a_category_view(): void {
		$this->assertNull( Payload::tiktok( 'ViewCategory', array(), 'x', array(), array(), '/', 1 ) );
		$this->assertNull( Payload::tiktok( 'Login', array(), 'x', array(), array(), '/', 1 ) );
	}

	public function test_ga4_purchase_is_the_ecommerce_schema(): void {
		$ev = Payload::ga4( 'Purchase', $this->order() );

		$this->assertSame( 'purchase', $ev['name'] );
		$this->assertSame( '5001', $ev['params']['transaction_id'] );
		$this->assertSame( 1234.5, $ev['params']['value'] );
		$this->assertSame( 39.0, $ev['params']['shipping'] );
		$this->assertSame( 'ILS', $ev['params']['currency'] );
		$this->assertSame( '31963', $ev['params']['items'][0]['item_id'] );
		$this->assertSame( 'מטבח', $ev['params']['items'][0]['item_category'] );
		$this->assertSame( 2, $ev['params']['items'][0]['quantity'] );
	}

	public function test_ga4_category_view_names_the_list(): void {
		$ev = Payload::ga4( 'ViewCategory', array( 'content_category' => 'מטבח', 'items' => array( array( 'id' => '1', 'name' => 'a', 'price' => 1, 'qty' => 1 ) ) ) );

		$this->assertSame( 'view_item_list', $ev['name'] );
		$this->assertSame( 'מטבח', $ev['params']['item_list_name'] );
	}

	public function test_ga4_ignores_what_it_has_no_name_for(): void {
		$this->assertNull( Payload::ga4( 'PageView', array() ) );
	}

	public function test_settings_normalize_types_and_bounds(): void {
		$s = Settings::normalize( array( 'enabled' => '1', 'fb' => array( 'pixel' => ' 123-456 ' ), 'ga4' => array( 'id' => 'g-abc' ), 'consent' => 'bogus', 'events' => array( 'scroll' => '' ) ) );

		$this->assertTrue( $s['enabled'] );
		$this->assertSame( '123456', $s['fb']['pixel'] );
		$this->assertSame( 'G-ABC', $s['ga4']['id'] );
		$this->assertSame( 'auto', $s['consent'] );
		$this->assertFalse( $s['events']['scroll'] );
		$this->assertTrue( $s['events']['video'] );
		$this->assertTrue( Settings::any( $s ) );
		$this->assertFalse( Settings::any( Settings::normalize( array() ) ) );
	}
}
