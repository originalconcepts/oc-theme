<?php
/**
 * The event bus: one place where the site says what just happened.
 *
 * Page events queue up during the request and reach the browser as a
 * list the marketing script drains. Events that happen right before a
 * redirect — a sign-up, a login — wait in the session and ride the next
 * page. Money events go to the networks from the server as well, later,
 * off the request, with the same id the browser used, so nothing counts
 * twice.
 *
 * @package OC\Theme
 */

declare( strict_types = 1 );

namespace OC\Theme\Marketing;

defined( 'ABSPATH' ) || exit;

/**
 * Queue and dispatch.
 */
final class Events {

	const LATER = 'oc_mkt_later';
	const HOOK  = 'oc_marketing_dispatch';

	/**
	 * This request's events for the browser.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private static $queue = array();

	/**
	 * A fresh event id, shared by the browser and the server for one event.
	 *
	 * @param string $prefix What kind of event, for a readable id.
	 */
	public static function id( string $prefix = 'oc' ): string {
		return $prefix . '_' . substr( str_replace( '-', '', wp_generate_uuid4() ), 0, 20 );
	}

	/**
	 * Say something happened on this page.
	 *
	 * @param string              $name Our event name.
	 * @param array<string,mixed> $data Event data.
	 * @param string              $id   Event id; made when empty.
	 * @return string The id.
	 */
	public static function queue( string $name, array $data = array(), string $id = '' ): string {
		$id = '' !== $id ? $id : self::id();

		self::$queue[] = array(
			'n'  => $name,
			'd'  => $data,
			'id' => $id,
		);

		return $id;
	}

	/**
	 * Say something happened that the next page should report.
	 *
	 * @param string              $name Our event name.
	 * @param array<string,mixed> $data Event data.
	 * @param string              $id   Event id.
	 */
	public static function later( string $name, array $data = array(), string $id = '' ): void {
		$item = array(
			'n'  => $name,
			'd'  => $data,
			'id' => '' !== $id ? $id : self::id(),
		);

		$uid = get_current_user_id();

		if ( $uid > 0 ) {
			$list   = (array) get_user_meta( $uid, self::LATER, true );
			$list[] = $item;
			update_user_meta( $uid, self::LATER, array_slice( $list, -5 ) );
			return;
		}

		if ( function_exists( 'WC' ) && WC()->session ) {
			$list   = (array) WC()->session->get( self::LATER, array() );
			$list[] = $item;
			WC()->session->set( self::LATER, array_slice( $list, -5 ) );
		}
	}

	/**
	 * Pick up what earlier pages left, once.
	 */
	public static function collect_later(): void {
		$uid = get_current_user_id();

		if ( $uid > 0 ) {
			$list = (array) get_user_meta( $uid, self::LATER, true );

			if ( $list ) {
				delete_user_meta( $uid, self::LATER );
			}
		} else {
			$list = function_exists( 'WC' ) && WC()->session ? (array) WC()->session->get( self::LATER, array() ) : array();

			if ( $list ) {
				WC()->session->set( self::LATER, array() );
			}
		}

		foreach ( $list as $item ) {
			if ( is_array( $item ) && isset( $item['n'] ) ) {
				self::queue( (string) $item['n'], (array) ( $item['d'] ?? array() ), (string) ( $item['id'] ?? '' ) );
			}
		}
	}

	/**
	 * The queue, for the footer.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function drain(): array {
		$q           = self::$queue;
		self::$queue = array();

		return $q;
	}

	/**
	 * Tell the networks from the server, off the request.
	 *
	 * @param string               $name   Our event name.
	 * @param array<string,mixed>  $data   Event data.
	 * @param string               $id     Event id (the browser's, for dedup).
	 * @param array<string,string> $user   Shopper: em, ph, fn, ln, ct, zp, country, external_id.
	 * @param array<string,string> $client ip, ua, fbp, fbc, ttclid, ttp, ga_cid.
	 * @param string               $url    Page.
	 */
	public static function server( string $name, array $data, string $id, array $user = array(), array $client = array(), string $url = '' ): void {
		$job = array(
			'name'   => $name,
			'data'   => $data,
			'id'     => '' !== $id ? $id : self::id(),
			'user'   => $user,
			'client' => $client ? $client : self::client(),
			'url'    => '' !== $url ? $url : self::current_url(),
			'time'   => time(),
		);

		// Money and identity must not be lost: they queue in the scheduler
		// and are retried. Everything else — a product seen, a search — is
		// sent on the spot without waiting for an answer; a lost one costs
		// nothing and a scheduler row per page view would.
		if ( ! in_array( $name, array( 'Purchase', 'CompleteRegistration', 'Subscribe', 'Lead', 'Contact' ), true ) ) {
			Dispatch::send( $job, false );
			return;
		}

		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::HOOK, array( $job ), 'oc-marketing' );
			return;
		}

		wp_schedule_single_event( time(), self::HOOK, array( $job ) );
	}

	/**
	 * The visitor's browser, as the networks want to match it.
	 *
	 * @return array<string,string>
	 */
	public static function client(): array {
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput -- read-only, each value is a bounded string.
		$ip = '';

		if ( class_exists( '\WC_Geolocation' ) ) {
			$ip = (string) \WC_Geolocation::get_ip_address();
		} elseif ( isset( $_SERVER['REMOTE_ADDR'] ) ) {
			$ip = (string) wp_unslash( $_SERVER['REMOTE_ADDR'] );
		}

		$cookie = static function ( string $k ): string {
			return isset( $_COOKIE[ $k ] ) ? substr( sanitize_text_field( wp_unslash( $_COOKIE[ $k ] ) ), 0, 200 ) : '';
		};

		$ga = $cookie( '_ga' );

		if ( preg_match( '/^GA\d\.\d\.(\d+\.\d+)$/', $ga, $m ) ) {
			$ga = $m[1];
		}

		return array(
			'ip'     => $ip,
			'ua'     => isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 400 ) : '',
			'fbp'    => $cookie( '_fbp' ),
			'fbc'    => $cookie( '_fbc' ),
			'ttp'    => $cookie( '_ttp' ),
			'ttclid' => $cookie( 'oc_ttclid' ),
			'ga_cid' => $ga,
		);
		// phpcs:enable
	}

	/**
	 * The page being served.
	 */
	public static function current_url(): string {
		$path = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- a path, escaped by esc_url_raw below.

		return esc_url_raw( home_url( $path ) );
	}
}
