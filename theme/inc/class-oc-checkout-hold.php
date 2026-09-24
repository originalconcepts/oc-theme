<?php
/**
 * The shopper's own stock hold must never stand in their way.
 *
 * WooCommerce holds stock for an order the moment "place order" creates it,
 * for as long as Inventory → "Hold stock" says (60 minutes by default). A
 * shopper who then comes back from the payment page — to check the cart, to
 * add something — is still holding that stock through their own unpaid
 * order. If the cart changed, WooCommerce creates a second order, counts the
 * first order's hold against it, and refuses: "not enough units in stock".
 * If the cart did not change, WooCommerce would resume the first order — but
 * a payment gateway that redirects off-site sometimes clears the session
 * key that says which order that is, and then even the cart page refuses.
 *
 * Two small moves close both gaps. The session remembers the last order the
 * checkout created here, in a key of ours that no gateway touches; when
 * WooCommerce's own key is gone, ours restores it before the stock check,
 * so the shopper's hold is left out of the count and an unchanged cart
 * resumes the same order. And when the checkout does create a new order,
 * the previous unpaid one of the same shopper is cancelled first — which
 * releases its hold, through WooCommerce's own release on the cancelled
 * status — so the new hold goes through. Nothing is mailed about that
 * cancellation: it is a replacement, not a change of mind.
 *
 * @package OC_Theme
 */

declare( strict_types = 1 );

namespace OC\Theme;

defined( 'ABSPATH' ) || defined( 'OC_TESTS' ) || exit;

/**
 * The previous unpaid order of this session, and what to do with it.
 */
final class Checkout_Hold {

	/**
	 * Session key: the last order the checkout created in this session.
	 */
	const KEY = 'oc_last_checkout_order';

	/**
	 * True while one of our own replacement cancellations runs, so the
	 * "order cancelled" mails stay quiet.
	 *
	 * @var bool
	 */
	private static $quiet = false;

	/**
	 * Hook in.
	 */
	public function register(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		// Before WooCommerce holds stock for the new order (priority 10).
		add_action( 'woocommerce_checkout_order_created', array( $this, 'replace' ), 5 );
		// After everything else has seen the new order.
		add_action( 'woocommerce_checkout_order_created', array( $this, 'remember' ), 20 );

		// Before the cart's own stock check (priority 1 on the same hook).
		add_action( 'woocommerce_cart_loaded_from_session', array( $this, 'restore' ), 20 );
		add_action( 'woocommerce_check_cart_items', array( $this, 'restore' ), 0 );

		add_filter( 'woocommerce_email_enabled_cancelled_order', array( $this, 'hush' ) );
		add_filter( 'woocommerce_email_enabled_customer_cancelled_order', array( $this, 'hush' ) );
	}

	/**
	 * Is the guard switched on?
	 */
	public static function on(): bool {
		return ! empty( Checkout::settings()['hold_guard'] );
	}

	/**
	 * Keep the id of the order the checkout just created.
	 *
	 * @param \WC_Order|mixed $order The new order.
	 */
	public function remember( $order ): void {
		if ( $order instanceof \WC_Order && WC()->session ) {
			WC()->session->set( self::KEY, $order->get_id() );
		}
	}

	/**
	 * WooCommerce's own "which order is this session paying for" key, put
	 * back from ours when a gateway wiped it and the order is still unpaid.
	 */
	public function restore(): void {
		if ( ! self::on() || ! WC()->session ) {
			return;
		}

		if ( absint( WC()->session->get( 'order_awaiting_payment' ) ) ) {
			return;
		}

		$id = absint( WC()->session->get( self::KEY ) );

		if ( ! $id ) {
			return;
		}

		$order = wc_get_order( $id );

		if ( ! $order instanceof \WC_Order || ! $order->has_status( array( 'pending', 'failed' ) ) ) {
			WC()->session->set( self::KEY, 0 );
			return;
		}

		WC()->session->set( 'order_awaiting_payment', $id );
	}

	/**
	 * A new order is being created: the session's previous unpaid order of
	 * the same shopper is cancelled first, so its hold is released before
	 * WooCommerce tries to hold stock for this one.
	 *
	 * @param \WC_Order|mixed $order The new order.
	 */
	public function replace( $order ): void {
		if ( ! self::on() || ! $order instanceof \WC_Order || ! WC()->session ) {
			return;
		}

		/**
		 * Session keys that may name the shopper's previous order. Ours and
		 * WooCommerce's are always read; a gateway that keeps its own key
		 * can add it here (PayPlus writes `page_order_awaiting_payment`).
		 *
		 * @param string[] $keys Session keys.
		 */
		$keys = (array) apply_filters(
			'oc_checkout_previous_order_keys',
			array( 'order_awaiting_payment', self::KEY, 'page_order_awaiting_payment' )
		);

		$ids = array();

		foreach ( $keys as $key ) {
			$ids[] = absint( WC()->session->get( (string) $key ) );
		}

		$new = array(
			'id'       => $order->get_id(),
			'status'   => $order->get_status(),
			'customer' => (int) $order->get_customer_id(),
			'email'    => (string) $order->get_billing_email(),
		);

		$stale = self::stale(
			$ids,
			$new,
			static function ( int $id ): ?array {
				$old = wc_get_order( $id );

				if ( ! $old instanceof \WC_Order ) {
					return null;
				}

				return array(
					'id'       => $old->get_id(),
					'status'   => $old->get_status(),
					'customer' => (int) $old->get_customer_id(),
					'email'    => (string) $old->get_billing_email(),
				);
			}
		);

		foreach ( $stale as $id ) {
			$old = wc_get_order( $id );

			if ( ! $old instanceof \WC_Order ) {
				continue;
			}

			self::$quiet = true;

			$old->update_status(
				'cancelled',
				sprintf(
					/* translators: %d: the order that took this one's place. */
					__( 'Replaced by order #%d: the shopper changed the cart before paying. The stock this order held is released.', 'oc-theme' ),
					$order->get_id()
				)
			);

			self::$quiet = false;

			$order->add_order_note(
				sprintf(
					/* translators: %d: the earlier unpaid order. */
					__( 'Takes the place of unpaid order #%d, cancelled so that its stock hold would not block this one.', 'oc-theme' ),
					$id
				)
			);
		}
	}

	/**
	 * Which of the session's order ids should give way to the new order.
	 * Pure: a function of the ids, the new order's facts and a loader —
	 * so it can be tested without a shop.
	 *
	 * An id gives way when it is another order, still unpaid (pending or
	 * failed), and belongs to the same shopper: the same signed-in
	 * customer, or the same billing e-mail for a guest.
	 *
	 * @param int[]                                        $ids  Candidate order ids, zeros allowed.
	 * @param array{id:int,status:string,customer:int,email:string} $new  The new order's facts.
	 * @param callable(int):(array|null)                    $load Loads the same facts for an id, or null.
	 * @return int[] Ids to cancel, each once, in the order given.
	 */
	public static function stale( array $ids, array $new, callable $load ): array {
		$out = array();

		foreach ( array_unique( array_filter( array_map( 'intval', $ids ) ) ) as $id ) {
			if ( $id === (int) $new['id'] ) {
				continue;
			}

			$old = $load( $id );

			if ( ! is_array( $old ) || ! in_array( (string) $old['status'], array( 'pending', 'failed' ), true ) ) {
				continue;
			}

			$same = ( $old['customer'] > 0 && $old['customer'] === (int) $new['customer'] )
				|| ( '' !== $old['email'] && strtolower( $old['email'] ) === strtolower( (string) $new['email'] ) );

			if ( $same ) {
				$out[] = $id;
			}
		}

		return $out;
	}

	/**
	 * The cancelled-order mails, off while a replacement runs.
	 *
	 * @param bool|mixed $enabled Whether the mail is enabled.
	 * @return bool|mixed
	 */
	public function hush( $enabled ) {
		return self::$quiet ? false : $enabled;
	}
}
