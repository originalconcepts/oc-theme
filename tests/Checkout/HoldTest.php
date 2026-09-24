<?php
/**
 * Which of a session's earlier orders give way to the new one.
 */

declare( strict_types = 1 );

namespace OC\Theme\Tests\Checkout;

use OC\Theme\Checkout_Hold;
use PHPUnit\Framework\TestCase;

/**
 * Checkout_Hold::stale().
 */
final class HoldTest extends TestCase {

	/**
	 * A little order book.
	 *
	 * @return callable(int):(array|null)
	 */
	private function book(): callable {
		$orders = array(
			11 => array( 'id' => 11, 'status' => 'pending', 'customer' => 5, 'email' => 'dana@example.com' ),
			12 => array( 'id' => 12, 'status' => 'failed', 'customer' => 0, 'email' => 'Guest@Example.com' ),
			13 => array( 'id' => 13, 'status' => 'processing', 'customer' => 5, 'email' => 'dana@example.com' ),
			14 => array( 'id' => 14, 'status' => 'pending', 'customer' => 9, 'email' => 'other@example.com' ),
			15 => array( 'id' => 15, 'status' => 'pending', 'customer' => 0, 'email' => '' ),
		);

		return static function ( int $id ) use ( $orders ): ?array {
			return $orders[ $id ] ?? null;
		};
	}

	public function test_same_customer_unpaid_order_gives_way(): void {
		$new = array( 'id' => 20, 'status' => 'pending', 'customer' => 5, 'email' => 'dana@example.com' );

		$this->assertSame( array( 11 ), Checkout_Hold::stale( array( 11 ), $new, $this->book() ) );
	}

	public function test_guest_matches_by_email_whatever_the_case(): void {
		$new = array( 'id' => 20, 'status' => 'pending', 'customer' => 0, 'email' => 'guest@example.com' );

		$this->assertSame( array( 12 ), Checkout_Hold::stale( array( 12 ), $new, $this->book() ) );
	}

	public function test_the_new_order_itself_paid_orders_and_strangers_are_left_alone(): void {
		$new = array( 'id' => 11, 'status' => 'pending', 'customer' => 5, 'email' => 'dana@example.com' );

		$this->assertSame( array(), Checkout_Hold::stale( array( 11 ), $new, $this->book() ), 'resumed order' );
		$this->assertSame( array(), Checkout_Hold::stale( array( 13 ), $new, $this->book() ), 'already paid' );
		$this->assertSame( array(), Checkout_Hold::stale( array( 14 ), $new, $this->book() ), 'someone else' );
		$this->assertSame( array(), Checkout_Hold::stale( array( 15 ), $new, $this->book() ), 'no way to tell who' );
		$this->assertSame( array(), Checkout_Hold::stale( array( 99 ), $new, $this->book() ), 'unknown id' );
	}

	public function test_ids_are_deduplicated_and_zeros_skipped(): void {
		$new = array( 'id' => 20, 'status' => 'pending', 'customer' => 5, 'email' => 'dana@example.com' );

		$this->assertSame( array( 11 ), Checkout_Hold::stale( array( 0, 11, '11', 0, 11 ), $new, $this->book() ) );
	}

	public function test_a_guest_never_matches_a_signed_in_order_by_customer_zero(): void {
		$new = array( 'id' => 20, 'status' => 'pending', 'customer' => 0, 'email' => 'nobody@example.com' );

		$this->assertSame( array(), Checkout_Hold::stale( array( 15 ), $new, $this->book() ) );
	}
}
