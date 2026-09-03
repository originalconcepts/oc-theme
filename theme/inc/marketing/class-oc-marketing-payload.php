<?php
/**
 * One event, three dialects.
 *
 * The site speaks one language — "a shopper viewed this product", "an
 * order for this much was paid" — and every network wants it said its
 * own way. This class does the translating, and only that: pure
 * functions of the event and the shopper, with no WordPress in them, so
 * every dialect is tested on the same sentences.
 *
 * @package OC\Theme
 */

declare( strict_types = 1 );

namespace OC\Theme\Marketing;

if ( ! defined( 'ABSPATH' ) && ! defined( 'OC_TESTS' ) ) {
	exit;
}

/**
 * Translates events for Meta, TikTok and GA4.
 */
final class Payload {

	/**
	 * Our names → Meta's. Anything not here goes as a custom event.
	 *
	 * @var array<string,string>
	 */
	const FB = array(
		'PageView'             => 'PageView',
		'ViewContent'          => 'ViewContent',
		'ViewCategory'         => 'ViewCategory',
		'Search'               => 'Search',
		'AddToCart'            => 'AddToCart',
		'InitiateCheckout'     => 'InitiateCheckout',
		'AddPaymentInfo'       => 'AddPaymentInfo',
		'Purchase'             => 'Purchase',
		'Lead'                 => 'Lead',
		'CompleteRegistration' => 'CompleteRegistration',
		'Subscribe'            => 'Subscribe',
		'Contact'              => 'Contact',
	);

	/**
	 * Our names → TikTok's.
	 *
	 * @var array<string,string>
	 */
	const TIKTOK = array(
		'ViewContent'          => 'ViewContent',
		'Search'               => 'Search',
		'AddToCart'            => 'AddToCart',
		'InitiateCheckout'     => 'InitiateCheckout',
		'AddPaymentInfo'       => 'AddPaymentInfo',
		'Purchase'             => 'CompletePayment',
		'CompleteRegistration' => 'CompleteRegistration',
		'Subscribe'            => 'Subscribe',
		'Contact'              => 'Contact',
		'Lead'                 => 'SubmitForm',
	);

	/**
	 * Our names → GA4's.
	 *
	 * @var array<string,string>
	 */
	const GA4 = array(
		'ViewContent'          => 'view_item',
		'ViewCategory'         => 'view_item_list',
		'Search'               => 'search',
		'AddToCart'            => 'add_to_cart',
		'RemoveFromCart'       => 'remove_from_cart',
		'InitiateCheckout'     => 'begin_checkout',
		'AddPaymentInfo'       => 'add_payment_info',
		'Purchase'             => 'purchase',
		'Lead'                 => 'generate_lead',
		'CompleteRegistration' => 'sign_up',
		'Login'                => 'login',
		'Subscribe'            => 'subscribe',
		'Contact'              => 'contact',
	);

	/**
	 * An email the way every network hashes it: lower-case, trimmed.
	 *
	 * @param string $email Email.
	 */
	public static function norm_email( string $email ): string {
		return strtolower( trim( $email ) );
	}

	/**
	 * A phone the way every network hashes it: digits only, with the
	 * country code — an Israeli 05x becomes 9725x.
	 *
	 * @param string $phone   Phone.
	 * @param string $country ISO country, for the leading zero.
	 */
	public static function norm_phone( string $phone, string $country = 'IL' ): string {
		$d = preg_replace( '/\D+/', '', $phone );

		if ( '' === $d ) {
			return '';
		}

		if ( 0 === strpos( $d, '00' ) ) {
			return substr( $d, 2 );
		}

		if ( 'IL' === strtoupper( $country ) && '0' === $d[0] ) {
			return '972' . substr( $d, 1 );
		}

		return $d;
	}

	/**
	 * SHA-256, or '' for nothing.
	 *
	 * @param string $v Value, already normalized.
	 */
	public static function hash( string $v ): string {
		return '' === $v ? '' : hash( 'sha256', $v );
	}

	/**
	 * The shopper as Meta wants them.
	 *
	 * @param array<string,string> $user   em, ph, fn, ln, ct, zp, country, external_id (plain).
	 * @param array<string,string> $client ip, ua, fbp, fbc.
	 * @return array<string,mixed>
	 */
	public static function fb_user( array $user, array $client ): array {
		$out = array();

		foreach ( array( 'em' => 'email', 'ph' => 'phone', 'fn' => 'first', 'ln' => 'last', 'ct' => 'city', 'zp' => 'zip', 'country' => 'country', 'external_id' => 'id' ) as $key => $kind ) {
			$v = (string) ( $user[ $key ] ?? '' );

			if ( '' === $v ) {
				continue;
			}

			switch ( $kind ) {
				case 'email':
					$v = self::norm_email( $v );
					break;
				case 'phone':
					$v = self::norm_phone( $v, (string) ( $user['country'] ?? 'IL' ) );
					break;
				case 'country':
					$v = strtolower( $v );
					break;
				case 'zip':
					$v = strtolower( preg_replace( '/\s+/', '', $v ) );
					break;
				default:
					$v = strtolower( trim( $v ) );
			}

			if ( '' !== $v ) {
				$out[ $key ] = array( self::hash( $v ) );
			}
		}

		foreach ( array( 'ip' => 'client_ip_address', 'ua' => 'client_user_agent', 'fbp' => 'fbp', 'fbc' => 'fbc' ) as $from => $to ) {
			if ( '' !== (string) ( $client[ $from ] ?? '' ) ) {
				$out[ $to ] = (string) $client[ $from ];
			}
		}

		return $out;
	}

	/**
	 * One event for Meta's Conversions API.
	 *
	 * @param string               $name   Our event name.
	 * @param array<string,mixed>  $data   currency, value, items[{id,name,price,qty,category}], order_id, search, content_name, content_category.
	 * @param string               $id     Event id, shared with the browser pixel.
	 * @param array<string,string> $user   Shopper.
	 * @param array<string,string> $client Client.
	 * @param string               $url    Page.
	 * @param int                  $time   Unix time.
	 * @return array<string,mixed>
	 */
	public static function fb( string $name, array $data, string $id, array $user, array $client, string $url, int $time ): array {
		$custom = array();
		$items  = (array) ( $data['items'] ?? array() );

		if ( isset( $data['currency'] ) ) {
			$custom['currency'] = (string) $data['currency'];
		}

		if ( isset( $data['value'] ) ) {
			$custom['value'] = round( (float) $data['value'], 2 );
		}

		if ( $items ) {
			$custom['content_type'] = 'product';
			$custom['content_ids']  = array_values( array_map( static fn( $i ) => (string) $i['id'], $items ) );
			$custom['contents']     = array_values(
				array_map(
					static fn( $i ) => array(
						'id'         => (string) $i['id'],
						'quantity'   => (int) ( $i['qty'] ?? 1 ),
						'item_price' => round( (float) ( $i['price'] ?? 0 ), 2 ),
					),
					$items
				)
			);
			$custom['num_items']    = array_sum( array_map( static fn( $i ) => (int) ( $i['qty'] ?? 1 ), $items ) );
		}

		foreach ( array( 'order_id', 'content_name', 'content_category' ) as $k ) {
			if ( '' !== (string) ( $data[ $k ] ?? '' ) ) {
				$custom[ $k ] = (string) $data[ $k ];
			}
		}

		if ( '' !== (string) ( $data['search'] ?? '' ) ) {
			$custom['search_string'] = (string) $data['search'];
		}

		return array(
			'event_name'       => self::FB[ $name ] ?? $name,
			'event_time'       => $time,
			'event_id'         => $id,
			'event_source_url' => $url,
			'action_source'    => 'website',
			'user_data'        => self::fb_user( $user, $client ),
			'custom_data'      => $custom,
		);
	}

	/**
	 * One event for TikTok's Events API.
	 *
	 * @param string               $name   Our event name.
	 * @param array<string,mixed>  $data   As for fb().
	 * @param string               $id     Event id.
	 * @param array<string,string> $user   Shopper.
	 * @param array<string,string> $client ip, ua, ttclid, ttp.
	 * @param string               $url    Page.
	 * @param int                  $time   Unix time.
	 * @return array<string,mixed>|null Null when TikTok has no such event.
	 */
	public static function tiktok( string $name, array $data, string $id, array $user, array $client, string $url, int $time ): ?array {
		if ( ! isset( self::TIKTOK[ $name ] ) ) {
			return null;
		}

		$u = array();

		if ( '' !== (string) ( $user['em'] ?? '' ) ) {
			$u['email'] = self::hash( self::norm_email( (string) $user['em'] ) );
		}

		if ( '' !== (string) ( $user['ph'] ?? '' ) ) {
			$p = self::norm_phone( (string) $user['ph'], (string) ( $user['country'] ?? 'IL' ) );

			if ( '' !== $p ) {
				$u['phone'] = self::hash( '+' . $p );
			}
		}

		if ( '' !== (string) ( $user['external_id'] ?? '' ) ) {
			$u['external_id'] = self::hash( (string) $user['external_id'] );
		}

		foreach ( array( 'ip' => 'ip', 'ua' => 'user_agent', 'ttclid' => 'ttclid', 'ttp' => 'ttp' ) as $from => $to ) {
			if ( '' !== (string) ( $client[ $from ] ?? '' ) ) {
				$u[ $to ] = (string) $client[ $from ];
			}
		}

		$props = array();
		$items = (array) ( $data['items'] ?? array() );

		if ( isset( $data['currency'] ) ) {
			$props['currency'] = (string) $data['currency'];
		}

		if ( isset( $data['value'] ) ) {
			$props['value'] = round( (float) $data['value'], 2 );
		}

		if ( $items ) {
			$props['content_type'] = 'product';
			$props['contents']     = array_values(
				array_map(
					static fn( $i ) => array(
						'content_id'   => (string) $i['id'],
						'content_name' => (string) ( $i['name'] ?? '' ),
						'quantity'     => (int) ( $i['qty'] ?? 1 ),
						'price'        => round( (float) ( $i['price'] ?? 0 ), 2 ),
					),
					$items
				)
			);
		}

		if ( '' !== (string) ( $data['order_id'] ?? '' ) ) {
			$props['order_id'] = (string) $data['order_id'];
		}

		if ( '' !== (string) ( $data['search'] ?? '' ) ) {
			$props['query'] = (string) $data['search'];
		}

		return array(
			'event'      => self::TIKTOK[ $name ],
			'event_time' => $time,
			'event_id'   => $id,
			'user'       => $u,
			'page'       => array( 'url' => $url ),
			'properties' => $props,
		);
	}

	/**
	 * One event for GA4's Measurement Protocol.
	 *
	 * @param string              $name Our event name.
	 * @param array<string,mixed> $data As for fb().
	 * @return array<string,mixed>|null
	 */
	public static function ga4( string $name, array $data ): ?array {
		if ( ! isset( self::GA4[ $name ] ) ) {
			return null;
		}

		$params = array();
		$items  = (array) ( $data['items'] ?? array() );

		if ( isset( $data['currency'] ) ) {
			$params['currency'] = (string) $data['currency'];
		}

		if ( isset( $data['value'] ) ) {
			$params['value'] = round( (float) $data['value'], 2 );
		}

		if ( '' !== (string) ( $data['order_id'] ?? '' ) ) {
			$params['transaction_id'] = (string) $data['order_id'];
		}

		if ( isset( $data['shipping'] ) ) {
			$params['shipping'] = round( (float) $data['shipping'], 2 );
		}

		if ( isset( $data['tax'] ) ) {
			$params['tax'] = round( (float) $data['tax'], 2 );
		}

		if ( '' !== (string) ( $data['search'] ?? '' ) ) {
			$params['search_term'] = (string) $data['search'];
		}

		if ( '' !== (string) ( $data['content_category'] ?? '' ) && 'ViewCategory' === $name ) {
			$params['item_list_name'] = (string) $data['content_category'];
		}

		if ( $items ) {
			$params['items'] = array_values(
				array_map(
					static fn( $i ) => array_filter(
						array(
							'item_id'       => (string) $i['id'],
							'item_name'     => (string) ( $i['name'] ?? '' ),
							'item_category' => (string) ( $i['category'] ?? '' ),
							'price'         => round( (float) ( $i['price'] ?? 0 ), 2 ),
							'quantity'      => (int) ( $i['qty'] ?? 1 ),
						),
						static fn( $v ) => '' !== $v && null !== $v
					),
					$items
				)
			);
		}

		return array(
			'name'   => self::GA4[ $name ],
			'params' => $params,
		);
	}
}
