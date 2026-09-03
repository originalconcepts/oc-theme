<?php
/**
 * Server-side delivery: Meta's Conversions API, TikTok's Events API and
 * GA4's Measurement Protocol.
 *
 * Runs off the request, from the scheduler. Every call and every answer
 * is logged — the last few dozen — so the screen can show the advertiser
 * that the events arrive, and what the network said if they did not.
 *
 * @package OC\Theme
 */

declare( strict_types = 1 );

namespace OC\Theme\Marketing;

defined( 'ABSPATH' ) || exit;

/**
 * Talks to the networks.
 */
final class Dispatch {

	const LOG = 'oc_marketing_log';

	/**
	 * Deliver one job to every network that has server access.
	 *
	 * @param array<string,mixed> $job From Events::server().
	 */
	public static function send( array $job ): void {
		$s      = Settings::get();
		$name   = (string) ( $job['name'] ?? '' );
		$data   = (array) ( $job['data'] ?? array() );
		$id     = (string) ( $job['id'] ?? '' );
		$user   = (array) ( $job['user'] ?? array() );
		$client = (array) ( $job['client'] ?? array() );
		$url    = (string) ( $job['url'] ?? '' );
		$time   = (int) ( $job['time'] ?? time() );

		if ( '' === $name ) {
			return;
		}

		if ( '' !== $s['fb']['pixel'] && '' !== $s['fb']['token'] ) {
			$body = array( 'data' => array( Payload::fb( $name, $data, $id, $user, $client, $url, $time ) ) );

			if ( '' !== $s['fb']['test'] ) {
				$body['test_event_code'] = $s['fb']['test'];
			}

			self::post(
				'meta',
				$name,
				'https://graph.facebook.com/v19.0/' . rawurlencode( $s['fb']['pixel'] ) . '/events?access_token=' . rawurlencode( $s['fb']['token'] ),
				$body,
				array()
			);
		}

		if ( '' !== $s['tiktok']['pixel'] && '' !== $s['tiktok']['token'] ) {
			$event = Payload::tiktok( $name, $data, $id, $user, $client, $url, $time );

			if ( null !== $event ) {
				self::post(
					'tiktok',
					$name,
					'https://business-api.tiktok.com/open_api/v1.3/event/track/',
					array(
						'event_source'    => 'web',
						'event_source_id' => $s['tiktok']['pixel'],
						'data'            => array( $event ),
					),
					array( 'Access-Token' => $s['tiktok']['token'] )
				);
			}
		}

		if ( ! empty( $job['ga4'] ) && '' !== $s['ga4']['id'] && '' !== $s['ga4']['secret'] ) {
			$event = Payload::ga4( $name, $data );
			$cid   = (string) ( $client['ga_cid'] ?? '' );

			if ( null !== $event && '' !== $cid ) {
				self::post(
					'ga4',
					$name,
					'https://www.google-analytics.com/mp/collect?measurement_id=' . rawurlencode( $s['ga4']['id'] ) . '&api_secret=' . rawurlencode( $s['ga4']['secret'] ),
					array(
						'client_id' => $cid,
						'events'    => array( $event ),
					),
					array()
				);
			}
		}
	}

	/**
	 * One HTTP call, logged.
	 *
	 * @param string               $network Network key.
	 * @param string               $event   Our event name.
	 * @param string               $url     Endpoint.
	 * @param array<string,mixed>  $body    JSON body.
	 * @param array<string,string> $headers Extra headers.
	 */
	private static function post( string $network, string $event, string $url, array $body, array $headers ): void {
		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 8,
				'headers' => array_merge( array( 'Content-Type' => 'application/json' ), $headers ),
				'body'    => wp_json_encode( $body ),
			)
		);

		$code = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
		$text = is_wp_error( $response ) ? $response->get_error_message() : substr( (string) wp_remote_retrieve_body( $response ), 0, 300 );

		self::log( $network, $event, $code, $text );
	}

	/**
	 * Remember the last few dozen calls.
	 *
	 * @param string $network Network.
	 * @param string $event   Event.
	 * @param int    $code    HTTP status (0 = no answer).
	 * @param string $text    Answer.
	 */
	private static function log( string $network, string $event, int $code, string $text ): void {
		$log   = (array) get_option( self::LOG, array() );
		$log[] = array(
			't'    => time(),
			'net'  => $network,
			'ev'   => $event,
			'code' => $code,
			'text' => $text,
		);

		update_option( self::LOG, array_slice( $log, -40 ), false );
	}

	/**
	 * The log, newest first.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function recent(): array {
		return array_reverse( (array) get_option( self::LOG, array() ) );
	}
}
