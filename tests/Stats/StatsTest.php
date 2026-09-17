<?php
/**
 * The statistics' pure parts: channel, device and robot detection.
 */

declare( strict_types = 1 );

namespace OC\Theme\Tests\Stats;

use OC\Theme\Stats\Track;
use PHPUnit\Framework\TestCase;

/**
 * Track statics.
 */
final class StatsTest extends TestCase {

	public function test_channel_from_referrer(): void {
		$site = 'shop.co.il';

		$this->assertSame( 'organic', Track::channel( 'https://www.google.com/', array(), $site ) );
		$this->assertSame( 'organic', Track::channel( 'https://www.google.co.il/search?q=x', array(), $site ) );
		$this->assertSame( 'social', Track::channel( 'https://l.instagram.com/', array(), $site ) );
		$this->assertSame( 'social', Track::channel( 'https://www.facebook.com/', array(), $site ) );
		$this->assertSame( 'social', Track::channel( 'https://t.co/abc', array(), $site ) );
		$this->assertSame( 'referral', Track::channel( 'https://www.ynet.co.il/article', array(), $site ) );
		$this->assertSame( 'direct', Track::channel( '', array(), $site ) );
		$this->assertSame( '', Track::channel( 'https://www.shop.co.il/product/x', array(), $site ), 'an internal move says nothing' );
	}

	public function test_channel_from_parameters_wins(): void {
		$site = 'shop.co.il';

		$this->assertSame( 'paid_search', Track::channel( 'https://www.google.com/', array( 'gclid' => 'abc' ), $site ) );
		$this->assertSame( 'paid_social', Track::channel( '', array( 'fbclid' => 'abc', 'utm_source' => 'facebook' ), $site ) );
		$this->assertSame( 'social', Track::channel( '', array( 'fbclid' => 'abc' ), $site ), 'a bare fbclid is a shared link' );
		$this->assertSame( 'paid_social', Track::channel( '', array( 'utm_source' => 'instagram', 'utm_medium' => 'cpc' ), $site ) );
		$this->assertSame( 'email', Track::channel( '', array( 'utm_medium' => 'email' ), $site ) );
		$this->assertSame( 'email', Track::channel( '', array( 'utm_source' => 'activetrail' ), $site ) );
		$this->assertSame( 'email', Track::channel( 'https://www.google.com/', array( 'utm_medium' => 'sms' ), $site ), 'a tagged link beats the referrer' );
		$this->assertSame( 'referral', Track::channel( '', array( 'utm_source' => 'partner-site' ), $site ) );
	}

	public function test_source_params_keeps_only_source_keys(): void {
		$p = Track::source_params( '?utm_source=fb&utm_medium=cpc&fbclid=1&size=xl&page=2' );

		$this->assertSame( array( 'utm_source' => 'fb', 'utm_medium' => 'cpc', 'fbclid' => '1' ), $p );
		$this->assertSame( array(), Track::source_params( '' ) );
	}

	public function test_device(): void {
		$this->assertSame( 'm', Track::device( 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148 Safari/604.1' ) );
		$this->assertSame( 'm', Track::device( 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 Chrome/120 Mobile Safari/537.36' ) );
		$this->assertSame( 't', Track::device( 'Mozilla/5.0 (iPad; CPU OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Safari/604.1' ) );
		$this->assertSame( 't', Track::device( 'Mozilla/5.0 (Linux; Android 13; SM-X710) AppleWebKit/537.36 Chrome/120 Safari/537.36' ), 'Android without Mobile is a tablet' );
		$this->assertSame( 'd', Track::device( 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 Chrome/120 Safari/537.36' ) );
	}

	public function test_bots(): void {
		$this->assertTrue( Track::is_bot( 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)' ) );
		$this->assertTrue( Track::is_bot( 'facebookexternalhit/1.1' ) );
		$this->assertTrue( Track::is_bot( 'Mozilla/5.0 HeadlessChrome/120' ) );
		$this->assertTrue( Track::is_bot( 'curl/8.4' ) );
		$this->assertTrue( Track::is_bot( '' ) );
		$this->assertFalse( Track::is_bot( 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148 Safari/604.1' ) );
	}

	public function test_host(): void {
		$this->assertSame( 'shop.co.il', Track::host( 'https://www.shop.co.il/a/b?c=d' ) );
		$this->assertSame( 'shop.co.il', Track::host( 'WWW.SHOP.CO.IL' ) );
		$this->assertSame( '', Track::host( '' ) );
	}
}
