<?php
/**
 * What the page says happened.
 *
 * Product seen, category browsed, search made, checkout begun, order
 * paid — the server knows these best, so the server queues them. The
 * money event is also sent from the server itself, off the request, with
 * the same id, and a GA4 fallback covers a thank-you page that never
 * rendered.
 *
 * @package OC\Theme
 */

declare( strict_types = 1 );

namespace OC\Theme\Marketing;

defined( 'ABSPATH' ) || exit;

/**
 * Page events and order hooks.
 */
final class Page {

	const META_TY     = '_oc_mkt_ty';
	const META_SRV    = '_oc_mkt_srv';
	const META_CLIENT = '_oc_mkt_client';
	const META_GA4    = '_oc_mkt_ga4_fallback';

	/**
	 * Hooks.
	 */
	public function register(): void {
		add_action( 'wp', array( $this, 'page_events' ), 20 );
		add_action( 'woocommerce_checkout_create_order', array( $this, 'remember_client' ), 10, 1 );
		add_action( 'woocommerce_payment_complete', array( $this, 'order_paid' ) );
		add_action( 'woocommerce_order_status_processing', array( $this, 'order_paid' ) );
		add_action( 'woocommerce_order_status_completed', array( $this, 'order_paid' ) );
		add_action( 'user_register', array( $this, 'registered' ) );
		add_action( 'set_auth_cookie', array( $this, 'logged_in' ), 10, 4 );
		add_action( 'oc_newsletter_subscribed', array( $this, 'subscribed' ), 10, 2 );
		add_action( Events::HOOK, array( Dispatch::class, 'send' ) );
		add_action( 'oc_marketing_ga4_fallback', array( $this, 'ga4_fallback' ) );
		add_action( 'rest_api_init', array( $this, 'rest' ) );
	}

	/**
	 * Register the REST route the browser uses to mirror its own events.
	 */
	public function rest(): void {
		register_rest_route(
			'oc/v1',
			'/mkt',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => array( $this, 'rest_mirror' ),
				'args'                => array(
					'n'  => array( 'required' => true ),
					'id' => array( 'required' => true ),
				),
			)
		);
	}

	/**
	 * A browser-born event (add to cart, payment details) mirrored from the
	 * server with the visitor's own cookies and address — the same id, so
	 * the networks count one. Bounded: known names only, small data.
	 *
	 * @param \WP_REST_Request $req Request.
	 * @return \WP_REST_Response
	 */
	public function rest_mirror( \WP_REST_Request $req ): \WP_REST_Response {
		$name = sanitize_key( (string) $req->get_param( 'n' ) );
		$id   = substr( preg_replace( '/[^a-z0-9_]/i', '', (string) $req->get_param( 'id' ) ), 0, 40 );
		$ok   = array( 'addtocart' => 'AddToCart', 'addpaymentinfo' => 'AddPaymentInfo', 'search' => 'Search', 'subscribe' => 'Subscribe', 'lead' => 'Lead', 'contact' => 'Contact' );

		if ( ! Settings::live() || ! isset( $ok[ $name ] ) || '' === $id ) {
			return new \WP_REST_Response( array( 'ok' => false ), 400 );
		}

		$data  = (array) $req->get_param( 'd' );
		$clean = array();

		foreach ( array( 'currency', 'value', 'search', 'content_name', 'content_category' ) as $k ) {
			if ( isset( $data[ $k ] ) ) {
				$clean[ $k ] = is_numeric( $data[ $k ] ) ? (float) $data[ $k ] : sanitize_text_field( (string) $data[ $k ] );
			}
		}

		foreach ( array_slice( (array) ( $data['items'] ?? array() ), 0, 50 ) as $i ) {
			if ( is_array( $i ) && isset( $i['id'] ) ) {
				$clean['items'][] = array(
					'id'       => sanitize_text_field( (string) $i['id'] ),
					'name'     => sanitize_text_field( (string) ( $i['name'] ?? '' ) ),
					'price'    => (float) ( $i['price'] ?? 0 ),
					'qty'      => max( 1, (int) ( $i['qty'] ?? 1 ) ),
					'category' => sanitize_text_field( (string) ( $i['category'] ?? '' ) ),
				);
			}
		}

		// A guest at the checkout has typed who they are; that is worth more
		// to matching than any cookie. Hashed before it leaves the server.
		$user = self::visitor_user();
		$u    = (array) $req->get_param( 'u' );

		foreach ( array( 'em', 'ph', 'fn', 'ln', 'ct', 'zp' ) as $k ) {
			if ( '' === (string) ( $user[ $k ] ?? '' ) && '' !== (string) ( $u[ $k ] ?? '' ) ) {
				$user[ $k ] = substr( sanitize_text_field( (string) $u[ $k ] ), 0, 120 );
			}
		}

		Events::server( $ok[ $name ], $clean, $id, $user, Events::client(), (string) $req->get_header( 'referer' ) );

		return new \WP_REST_Response( array( 'ok' => true ), 200 );
	}

	/**
	 * The signed-in shopper, for matching server events.
	 *
	 * @return array<string,string>
	 */
	public static function visitor_user(): array {
		$uid = get_current_user_id();

		if ( $uid <= 0 || ! function_exists( 'WC' ) || ! WC()->customer ) {
			return array();
		}

		$c = WC()->customer;

		return array(
			'em'          => (string) $c->get_email(),
			'ph'          => (string) $c->get_billing_phone(),
			'fn'          => (string) $c->get_first_name(),
			'ln'          => (string) $c->get_last_name(),
			'ct'          => (string) $c->get_billing_city(),
			'zp'          => (string) $c->get_billing_postcode(),
			'country'     => (string) ( $c->get_billing_country() ? $c->get_billing_country() : 'IL' ),
			'external_id' => (string) $uid,
		);
	}

	/**
	 * Queue for the browser and mirror from the server, one id for both.
	 *
	 * @param string              $name Event.
	 * @param array<string,mixed> $data Data.
	 * @param string              $id   Id.
	 */
	private static function both( string $name, array $data, string $id = '' ): void {
		$id = Events::queue( $name, $data, $id );
		Events::server( $name, $data, $id, self::visitor_user() );
	}

	/**
	 * The page's own events, queued for the browser and mirrored from the
	 * server.
	 */
	public function page_events(): void {
		if ( is_admin() || ! Settings::live() || ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		Events::collect_later();

		if ( is_product() ) {
			$product = wc_get_product( get_queried_object_id() );

			if ( $product instanceof \WC_Product ) {
				$item = self::item( $product );

				self::both(
					'ViewContent',
					array(
						'currency'         => get_woocommerce_currency(),
						'value'            => $item['price'],
						'items'            => array( $item ),
						'content_name'     => $item['name'],
						'content_category' => $item['category'],
					)
				);
			}

			return;
		}

		if ( is_product_category() ) {
			$term  = get_queried_object();
			$items = array();

			global $wp_query;

			foreach ( array_slice( (array) $wp_query->posts, 0, 12 ) as $post ) {
				$product = wc_get_product( $post );

				if ( $product instanceof \WC_Product ) {
					$items[] = self::item( $product );
				}
			}

			Events::queue(
				'ViewCategory',
				array(
					'currency'         => get_woocommerce_currency(),
					'items'            => $items,
					'content_name'     => $term instanceof \WP_Term ? $term->name : '',
					'content_category' => $term instanceof \WP_Term ? $term->name : '',
				)
			);

			return;
		}

		if ( is_search() && '' !== get_search_query() && ! empty( Settings::get()['events']['search'] ) ) {
			self::both( 'Search', array( 'search' => get_search_query() ) );
			return;
		}

		if ( function_exists( 'is_checkout' ) && is_checkout() && ! is_order_received_page() && WC()->cart && ! WC()->cart->is_empty() ) {
			self::both( 'InitiateCheckout', self::cart_data() );
			return;
		}

		if ( function_exists( 'is_order_received_page' ) && is_order_received_page() ) {
			$order = wc_get_order( absint( get_query_var( 'order-received' ) ) );

			if ( $order instanceof \WC_Order && '' === (string) $order->get_meta( self::META_TY ) ) {
				$order->update_meta_data( self::META_TY, (string) time() );
				$order->save();

				Events::queue( 'Purchase', self::order_data( $order ) + array( 'user' => self::order_user( $order ) ), 'order_' . $order->get_id() );
			}
		}
	}

	/**
	 * The browser that placed the order, kept on it for the server events.
	 *
	 * @param \WC_Order $order Order.
	 */
	public function remember_client( $order ): void {
		if ( $order instanceof \WC_Order ) {
			$order->update_meta_data( self::META_CLIENT, Events::client() );
		}
	}

	/**
	 * Money in: tell Meta and TikTok from here, once, and arrange the GA4
	 * fallback in case the thank-you page never draws.
	 *
	 * @param int $order_id Order.
	 */
	public function order_paid( $order_id ): void {
		if ( ! Settings::live() ) {
			return;
		}

		$order = wc_get_order( $order_id );

		if ( ! $order instanceof \WC_Order || '' !== (string) $order->get_meta( self::META_SRV ) ) {
			return;
		}

		$order->update_meta_data( self::META_SRV, (string) time() );
		$order->save();

		$client = (array) $order->get_meta( self::META_CLIENT );

		Events::server( 'Purchase', self::order_data( $order ), 'order_' . $order->get_id(), self::order_user( $order ), $client ? $client : array(), (string) $order->get_checkout_order_received_url() );

		if ( '' !== Settings::get()['ga4']['secret'] ) {
			wp_schedule_single_event( time() + 20 * MINUTE_IN_SECONDS, 'oc_marketing_ga4_fallback', array( (int) $order->get_id() ) );
		}
	}

	/**
	 * Twenty minutes on: if the browser never reported the purchase to
	 * GA4, the server does, with the browser's own client id.
	 *
	 * @param int $order_id Order.
	 */
	public function ga4_fallback( $order_id ): void {
		$order = wc_get_order( $order_id );

		if ( ! $order instanceof \WC_Order || '' !== (string) $order->get_meta( self::META_TY ) || '' !== (string) $order->get_meta( self::META_GA4 ) ) {
			return;
		}

		$order->update_meta_data( self::META_GA4, (string) time() );
		$order->save();

		Dispatch::send(
			array(
				'name'   => 'Purchase',
				'data'   => self::order_data( $order ),
				'id'     => 'order_' . $order->get_id(),
				'user'   => self::order_user( $order ),
				'client' => (array) $order->get_meta( self::META_CLIENT ),
				'url'    => (string) $order->get_checkout_order_received_url(),
				'time'   => time(),
				'ga4'    => true,
			)
		);
	}

	/**
	 * A new account: the next page reports it, and the server tells Meta.
	 *
	 * @param int $user_id User.
	 */
	public function registered( $user_id ): void {
		if ( ! Settings::live() ) {
			return;
		}

		$user = get_userdata( (int) $user_id );
		$id   = Events::id( 'reg' );

		Events::later( 'CompleteRegistration', array(), $id );

		if ( $user ) {
			Events::server(
				'CompleteRegistration',
				array(),
				$id,
				array(
					'em'          => (string) $user->user_email,
					'fn'          => (string) $user->first_name,
					'ln'          => (string) $user->last_name,
					'external_id' => (string) $user->ID,
				)
			);
		}
	}

	/**
	 * A sign-in: the next page reports it.
	 *
	 * @param string $cookie  Cookie.
	 * @param int    $expire  Expire.
	 * @param int    $expiry  Expiry.
	 * @param int    $user_id User.
	 */
	public function logged_in( $cookie, $expire, $expiry, $user_id ): void {
		if ( ! Settings::live() || ! $user_id ) {
			return;
		}

		// Registration signs in too; that page already says so.
		if ( did_action( 'user_register' ) ) {
			return;
		}

		$list = (array) get_user_meta( (int) $user_id, Events::LATER, true );

		foreach ( $list as $item ) {
			if ( is_array( $item ) && 'Login' === ( $item['n'] ?? '' ) ) {
				return;
			}
		}

		$list[] = array(
			'n'  => 'Login',
			'd'  => array(),
			'id' => Events::id( 'login' ),
		);

		update_user_meta( (int) $user_id, Events::LATER, array_slice( $list, -5 ) );
	}

	/**
	 * A newsletter signup: the server tells Meta and TikTok, with the
	 * same id the page's own script uses.
	 *
	 * @param string $email Email.
	 * @param string $id    Event id.
	 */
	public function subscribed( $email, $id = '' ): void {
		if ( ! Settings::live() ) {
			return;
		}

		Events::server( 'Subscribe', array(), (string) $id, array( 'em' => (string) $email ) );
	}

	/**
	 * A product as an item.
	 *
	 * @param \WC_Product $product Product.
	 * @param int         $qty     Quantity.
	 * @return array{id:string,name:string,price:float,qty:int,category:string}
	 */
	public static function item( \WC_Product $product, int $qty = 1 ): array {
		$parent = $product->get_parent_id() > 0 ? wc_get_product( $product->get_parent_id() ) : $product;
		$cats   = $parent instanceof \WC_Product ? wp_get_post_terms( $parent->get_id(), 'product_cat', array( 'fields' => 'names' ) ) : array();

		return array(
			'id'       => (string) $product->get_id(),
			'name'     => (string) $product->get_name(),
			'price'    => round( (float) wc_get_price_to_display( $product ), 2 ),
			'qty'      => $qty,
			'category' => is_array( $cats ) && $cats ? (string) reset( $cats ) : '',
		);
	}

	/**
	 * The cart as event data.
	 *
	 * @return array<string,mixed>
	 */
	public static function cart_data(): array {
		$items = array();

		foreach ( WC()->cart->get_cart() as $line ) {
			if ( $line['data'] instanceof \WC_Product ) {
				$items[] = self::item( $line['data'], (int) $line['quantity'] );
			}
		}

		return array(
			'currency' => get_woocommerce_currency(),
			'value'    => round( (float) WC()->cart->get_displayed_subtotal(), 2 ),
			'items'    => $items,
		);
	}

	/**
	 * An order as event data.
	 *
	 * @param \WC_Order $order Order.
	 * @return array<string,mixed>
	 */
	public static function order_data( \WC_Order $order ): array {
		$items = array();

		foreach ( $order->get_items() as $line ) {
			if ( ! $line instanceof \WC_Order_Item_Product ) {
				continue;
			}

			$product = $line->get_product();

			if ( $product instanceof \WC_Product ) {
				$item          = self::item( $product, (int) $line->get_quantity() );
				$item['price'] = $line->get_quantity() > 0 ? round( (float) $line->get_total() / $line->get_quantity(), 2 ) : $item['price'];
				$items[]       = $item;
			}
		}

		return array(
			'currency' => $order->get_currency(),
			'value'    => round( (float) $order->get_total(), 2 ),
			'shipping' => round( (float) $order->get_shipping_total(), 2 ),
			'tax'      => round( (float) $order->get_total_tax(), 2 ),
			'items'    => $items,
			'order_id' => (string) $order->get_id(),
		);
	}

	/**
	 * The buyer, for matching.
	 *
	 * @param \WC_Order $order Order.
	 * @return array<string,string>
	 */
	public static function order_user( \WC_Order $order ): array {
		return array(
			'em'          => (string) $order->get_billing_email(),
			'ph'          => (string) $order->get_billing_phone(),
			'fn'          => (string) $order->get_billing_first_name(),
			'ln'          => (string) $order->get_billing_last_name(),
			'ct'          => (string) $order->get_billing_city(),
			'zp'          => (string) $order->get_billing_postcode(),
			'country'     => (string) ( $order->get_billing_country() ? $order->get_billing_country() : 'IL' ),
			'external_id' => $order->get_customer_id() > 0 ? (string) $order->get_customer_id() : '',
		);
	}
}
