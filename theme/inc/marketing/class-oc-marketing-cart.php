<?php
/**
 * What changed in the cart, told to the page that changed it.
 *
 * A product added from its own page or a cross-sell is reported by the
 * browser, which knows what it clicked. The side cart's steppers, its
 * "empty the cart", the checkout's steppers and the bought-together
 * button change the cart through our own ajax, and the browser knows
 * only that something moved. The server knows exactly what: it records
 * every line added or taken away during those requests and hands the
 * list back as one more fragment, which marketing.js turns into
 * add_to_cart / remove_from_cart.
 *
 * @package OC\Theme
 */

declare( strict_types = 1 );

namespace OC\Theme\Marketing;

defined( 'ABSPATH' ) || exit;

/**
 * Cart changes as events.
 */
final class Cart {

	/**
	 * The element the change rides in: printed empty in the footer, and
	 * replaced by the fragment of the same id.
	 */
	const ELEMENT = 'oc-mkt-cart';

	/**
	 * The requests whose changes nobody else reports.
	 */
	const ACTIONS = array( 'oc_cart_qty', 'oc_bt_add' );

	/**
	 * Lines added during this request.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private static $added = array();

	/**
	 * Lines taken away during this request.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private static $removed = array();

	/**
	 * Lines whose quantity a stepper already reported this request — an
	 * add of a product already in the cart raises that line's quantity
	 * first, and must not be counted twice.
	 *
	 * @var array<string,bool>
	 */
	private static $stepped = array();

	/**
	 * Hooks.
	 */
	public function register(): void {
		add_action( 'woocommerce_add_to_cart', array( $this, 'added' ), 10, 6 );
		add_action( 'woocommerce_remove_cart_item', array( $this, 'removing' ), 10, 2 );
		add_action( 'woocommerce_after_cart_item_quantity_update', array( $this, 'quantity' ), 10, 4 );
		add_action( 'woocommerce_before_cart_emptied', array( $this, 'emptying' ) );
		add_filter( 'woocommerce_add_to_cart_fragments', array( $this, 'fragment' ), 99 );
		add_action( 'wp_footer', array( $this, 'holder' ), 41 );
	}

	/**
	 * Whether this request is one of ours.
	 */
	private static function watching(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only reads which ajax action runs.
		$action = isset( $_REQUEST['action'] ) ? sanitize_key( (string) wp_unslash( $_REQUEST['action'] ) ) : '';

		return wp_doing_ajax() && in_array( $action, self::ACTIONS, true ) && Settings::live();
	}

	/**
	 * A cart line as event data.
	 *
	 * @param mixed $line Cart line.
	 * @param int   $qty  Quantity changed.
	 * @return array<string,mixed>|null
	 */
	private static function line( $line, int $qty ): ?array {
		if ( ! is_array( $line ) || ! ( $line['data'] ?? null ) instanceof \WC_Product || $qty < 1 ) {
			return null;
		}

		return Page::item( $line['data'], $qty );
	}

	/**
	 * A line added (the bought-together button).
	 *
	 * @param string              $key          Cart line key.
	 * @param int                 $product_id   Product.
	 * @param int                 $quantity     Quantity.
	 * @param int                 $variation_id Variation, or 0.
	 * @param array<string,mixed> $variation    Attributes.
	 * @param array<string,mixed> $data         Line data.
	 */
	public function added( $key, $product_id, $quantity, $variation_id, $variation, $data ): void {
		unset( $variation, $data );

		if ( ! self::watching() || isset( self::$stepped[ (string) $key ] ) ) {
			return;
		}

		$product = wc_get_product( $variation_id ? (int) $variation_id : (int) $product_id );

		if ( $product instanceof \WC_Product && (int) $quantity > 0 ) {
			self::$added[] = Page::item( $product, (int) $quantity );
		}
	}

	/**
	 * A line about to go, while it is still there to read.
	 *
	 * @param string   $key  Cart line key.
	 * @param \WC_Cart $cart Cart.
	 */
	public function removing( $key, $cart ): void {
		if ( ! self::watching() || ! $cart instanceof \WC_Cart ) {
			return;
		}

		$line = $cart->get_cart_item( (string) $key );
		$item = self::line( $line, (int) ( $line['quantity'] ?? 0 ) );

		if ( $item ) {
			self::$removed[] = $item;
		}
	}

	/**
	 * A stepper moved: the difference is what was added or taken away.
	 *
	 * @param string   $key  Cart line key.
	 * @param int      $qty  New quantity.
	 * @param int      $old  Old quantity.
	 * @param \WC_Cart $cart Cart.
	 */
	public function quantity( $key, $qty, $old, $cart ): void {
		if ( ! self::watching() || ! $cart instanceof \WC_Cart ) {
			return;
		}

		self::$stepped[ (string) $key ] = true;

		$diff = (int) $qty - (int) $old;
		$item = self::line( $cart->get_cart_item( (string) $key ), abs( $diff ) );

		if ( ! $item ) {
			return;
		}

		if ( $diff > 0 ) {
			self::$added[] = $item;
		} else {
			self::$removed[] = $item;
		}
	}

	/**
	 * "Empty the cart": every line goes.
	 */
	public function emptying(): void {
		if ( ! self::watching() || ! WC()->cart ) {
			return;
		}

		foreach ( WC()->cart->get_cart() as $line ) {
			$item = self::line( $line, (int) ( $line['quantity'] ?? 0 ) );

			if ( $item ) {
				self::$removed[] = $item;
			}
		}
	}

	/**
	 * The change, as one more fragment. Every script that applies
	 * fragments swaps the footer's empty element for this one, and
	 * marketing.js reads it. The id lets a fragment replayed from the
	 * session cache on a later page be recognised and skipped.
	 *
	 * @param array<string,string> $fragments Fragments.
	 * @return array<string,string>
	 */
	public function fragment( $fragments ): array {
		$fragments = (array) $fragments;

		if ( ! self::$added && ! self::$removed ) {
			return $fragments;
		}

		$change = array(
			'id'       => 'cart_' . wp_generate_password( 10, false ),
			'currency' => get_woocommerce_currency(),
			'added'    => self::$added,
			'removed'  => self::$removed,
		);

		$fragments[ 'script#' . self::ELEMENT ] = '<script type="application/json" id="' . self::ELEMENT . '">' . wp_json_encode( $change, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE ) . '</script>';

		self::$added   = array();
		self::$removed = array();

		return $fragments;
	}

	/**
	 * The empty element the fragment replaces.
	 */
	public function holder(): void {
		if ( is_admin() || ! Settings::live() ) {
			return;
		}

		echo '<script type="application/json" id="' . esc_attr( self::ELEMENT ) . '"></script>' . "\n";
	}
}
