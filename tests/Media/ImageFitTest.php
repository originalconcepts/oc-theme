<?php
/**
 * The edge verdict: plain ground or busy scene.
 */

declare( strict_types = 1 );

namespace OC\Theme\Tests\Media;

use OC\Theme\Image_Fit;
use PHPUnit\Framework\TestCase;

/**
 * Image_Fit::judge().
 */
final class ImageFitTest extends TestCase {

	/**
	 * @param int[] $rgb Colour.
	 * @param int   $n   How many.
	 * @return array<int,int[]>
	 */
	private static function px( array $rgb, int $n, int $alpha = 0 ): array {
		return array_fill( 0, $n, array( $rgb[0], $rgb[1], $rgb[2], $alpha ) );
	}

	public function test_white_studio_shot_is_plain_white(): void {
		$v = Image_Fit::judge( array_merge( self::px( array( 255, 255, 255 ), 190 ), self::px( array( 30, 30, 30 ), 10 ) ) );

		$this->assertTrue( $v['plain'] );
		$this->assertSame( '#ffffff', $v['bg'] );
	}

	public function test_jpeg_noise_on_grey_still_counts_as_plain(): void {
		$samples = array();

		for ( $i = 0; $i < 200; $i++ ) {
			$d         = ( $i % 7 ) - 3;
			$samples[] = array( 240 + $d, 241 + $d, 239 + $d, 0 );
		}

		$v = Image_Fit::judge( $samples );

		$this->assertTrue( $v['plain'] );
		$this->assertSame( '#f0f1ef', $v['bg'] );
	}

	public function test_a_room_is_busy(): void {
		$samples = array();

		for ( $i = 0; $i < 200; $i++ ) {
			$samples[] = array( ( $i * 37 ) % 256, ( $i * 91 ) % 256, ( $i * 53 ) % 256, 0 );
		}

		$v = Image_Fit::judge( $samples );

		$this->assertFalse( $v['plain'] );
		$this->assertSame( '', $v['bg'] );
	}

	public function test_transparent_cutout_is_plain_with_no_colour(): void {
		$v = Image_Fit::judge( self::px( array( 0, 0, 0 ), 100, 127 ) );

		$this->assertTrue( $v['plain'] );
		$this->assertSame( '', $v['bg'] );
	}

	public function test_too_few_samples_is_busy(): void {
		$this->assertFalse( Image_Fit::judge( self::px( array( 255, 255, 255 ), 3 ) )['plain'] );
	}
}
