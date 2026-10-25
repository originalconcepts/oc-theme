<?php
/**
 * The redirects' pure parts: the lookup and the chain walk.
 */

declare( strict_types = 1 );

namespace OC\Theme\Tests\Redirects;

use OC\Theme\Redirects;
use PHPUnit\Framework\TestCase;

/**
 * Redirects::pick() and Redirects::follow().
 */
final class ChainTest extends TestCase {

	/**
	 * A rulebook from source => target pairs; a trailing star makes a net.
	 *
	 * @param array<string,string|array{0:string,1:int}> $pairs Source => target, or [target, type].
	 * @return array{exact:array<string,array{t:string,y:int,i:int}>,wild:array<int,array{s:string,t:string,y:int,i:int}>}
	 */
	private static function book( array $pairs ): array {
		$book = array(
			'exact' => array(),
			'wild'  => array(),
		);
		$id   = 0;

		foreach ( $pairs as $source => $target ) {
			$rule = array(
				't' => is_array( $target ) ? $target[0] : $target,
				'y' => is_array( $target ) ? $target[1] : 301,
				'i' => ++$id,
			);

			if ( '*' === substr( $source, -1 ) ) {
				$book['wild'][] = array( 's' => rtrim( $source, '*' ) ) + $rule;
			} else {
				$book['exact'][ $source ] = $rule;
			}
		}

		return $book;
	}

	/**
	 * Walk a chain with a plain normaliser and a set of living paths.
	 *
	 * @param array    $book   Rulebook.
	 * @param string   $target First target.
	 * @param string[] $living Paths that answer with a page of their own.
	 */
	private static function walk( array $book, string $target, array $living = array() ): string {
		return Redirects::follow(
			$book,
			$target,
			static function ( string $url ): string {
				return '' === rtrim( $url, '/' ) ? '/' : rtrim( strtolower( $url ), '/' );
			},
			static function ( string $path ) use ( $living ): bool {
				return in_array( $path, $living, true );
			}
		);
	}

	public function test_a_net_never_swallows_a_chosen_target(): void {
		$book = self::book(
			array(
				'/sale'               => '/product-category/deals',
				'/product-category/*' => '/shop',
			)
		);

		$this->assertSame( '/product-category/deals', self::walk( $book, '/product-category/deals' ) );
	}

	public function test_exact_rules_still_shorten_a_chain(): void {
		$book = self::book(
			array(
				'/old-a' => '/old-b',
				'/old-b' => '/new',
			)
		);

		$this->assertSame( '/new', self::walk( $book, '/old-b' ) );
	}

	public function test_a_chain_stops_on_an_address_that_lives_again(): void {
		$book = self::book( array( '/product/back' => '/product-category/old-home' ) );

		$this->assertSame( '/product/back', self::walk( $book, '/product/back', array( '/product/back' ) ) );
	}

	public function test_a_circle_and_a_gone_rule_end_the_walk(): void {
		$circle = self::book(
			array(
				'/a' => '/b',
				'/b' => '/a',
			)
		);

		$this->assertContains( self::walk( $circle, '/b' ), array( '/a', '/b' ) );

		$gone = self::book( array( '/gone' => array( '/anything', 410 ) ) );

		$this->assertSame( '/gone', self::walk( $gone, '/gone' ) );
	}

	public function test_pick_answers_with_a_net_only_when_asked(): void {
		$book = self::book(
			array(
				'/product/old' => '/product/new',
				'/product/*'   => '/shop',
			)
		);

		$this->assertSame( '/product/new', Redirects::pick( $book, '/product/old', false )['t'] );
		$this->assertSame( '/product/new', Redirects::pick( $book, '/product/old?colour=red', false )['t'], 'the bare path still answers' );
		$this->assertSame( '/shop', Redirects::pick( $book, '/product/unknown' )['t'] );
		$this->assertNull( Redirects::pick( $book, '/product/unknown', false ) );
		$this->assertNull( Redirects::pick( $book, '/productive' ), 'a net covers its folder, not a longer word' );
	}
}
