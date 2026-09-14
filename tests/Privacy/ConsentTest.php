<?php
/**
 * The privacy layer's pure parts: settings shape and the consent cookie.
 */

declare( strict_types = 1 );

namespace OC\Theme\Tests\Privacy;

use OC\Theme\Privacy\Consent;
use OC\Theme\Privacy\Settings;
use PHPUnit\Framework\TestCase;

/**
 * Consent parsing and settings normalisation.
 */
final class ConsentTest extends TestCase {

	public function test_empty_settings_are_on_and_regional(): void {
		$s = Settings::normalize( array() );

		$this->assertTrue( $s['enabled'] );
		$this->assertSame( 'auto', $s['mode'] );
		$this->assertSame( 'card', $s['layout'] );
		$this->assertTrue( $s['cats']['marketing']['on'] );
		$this->assertTrue( $s['log'] );
		$this->assertSame( 400, $s['log_days'] );
		$this->assertSame( array(), $s['scripts'] );
	}

	public function test_bad_values_fall_back(): void {
		$s = Settings::normalize(
			array(
				'mode'     => 'whatever',
				'layout'   => 'popup',
				'reopen'   => 'x',
				'log_days' => 1,
				'scripts'  => array(
					array( 'name' => 'A', 'cat' => 'nonsense', 'where' => 'sky', 'code' => '<script>1</script>' ),
					array( 'name' => 'empty', 'cat' => 'analytics', 'code' => '   ' ),
				),
			)
		);

		$this->assertSame( 'auto', $s['mode'] );
		$this->assertSame( 'card', $s['layout'] );
		$this->assertSame( 'badge', $s['reopen'] );
		$this->assertSame( 30, $s['log_days'] );
		$this->assertCount( 1, $s['scripts'] );
		$this->assertSame( 'marketing', $s['scripts'][0]['cat'] );
		$this->assertSame( 'footer', $s['scripts'][0]['where'] );
	}

	public function test_explicit_off_stays_off(): void {
		$s = Settings::normalize( array( 'enabled' => 0, 'cats' => array( 'analytics' => array( 'on' => 0 ) ) ) );

		$this->assertFalse( $s['enabled'] );
		$this->assertFalse( $s['cats']['analytics']['on'] );
		$this->assertTrue( $s['cats']['marketing']['on'] );
	}

	public function test_cookie_parses_and_matches_policy(): void {
		$raw = rawurlencode( '{"v":1,"p":1,"a":0,"m":1,"id":"abc-123","t":"2026-09-15T00:00:00Z","pv":"2:deadbeef"}' );
		$c   = Consent::parse( $raw, '2:deadbeef' );

		$this->assertNotNull( $c );
		$this->assertTrue( $c['preferences'] );
		$this->assertFalse( $c['analytics'] );
		$this->assertTrue( $c['marketing'] );
		$this->assertSame( 'abc-123', $c['id'] );
		$this->assertFalse( $c['legacy'] );
	}

	public function test_cookie_for_an_older_policy_is_no_answer(): void {
		$raw = '{"v":1,"p":1,"a":1,"m":1,"id":"x","t":"","pv":"1:aaaaaaaa"}';

		$this->assertNull( Consent::parse( $raw, '2:bbbbbbbb' ) );
		$this->assertNotNull( Consent::parse( $raw, '' ) );
	}

	public function test_legacy_words_still_count(): void {
		$yes = Consent::parse( 'granted', '3:x' );
		$no  = Consent::parse( 'denied', '3:x' );

		$this->assertTrue( $yes['marketing'] );
		$this->assertTrue( $yes['legacy'] );
		$this->assertFalse( $no['analytics'] );
	}

	public function test_garbage_is_no_answer(): void {
		$this->assertNull( Consent::parse( '', '' ) );
		$this->assertNull( Consent::parse( 'not json', '' ) );
		$this->assertNull( Consent::parse( '{"a":1}', '' ) );
	}

	public function test_regions(): void {
		$this->assertSame( 'optin', Consent::mode_for( 'DE' ) );
		$this->assertSame( 'optin', Consent::mode_for( 'gb' ) );
		$this->assertSame( 'optout', Consent::mode_for( 'IL' ) );
		$this->assertSame( 'optout', Consent::mode_for( '' ) );
	}
}
