<?php
/**
 * A daily word from the site to Make, so Monday's card shows where the
 * customer stands without anybody opening the site.
 *
 * While the questionnaire is alive and Make's webhook is set, the site
 * posts its state once a day — but only when something changed since the
 * last post — and once more the moment the answers are in. It stops for
 * good once the questionnaire is submitted and a card gateway is taking
 * money: nothing left on this card for the site to report.
 *
 * The payload, exactly (Make's side is built to this shape):
 *
 *     {
 *       "event":     "status",
 *       "site":      "https://shop.example.co.il/",   home_url( '/' )
 *       "monday":    { "item": "1234567890" },          the same object
 *                                                      Drive::request() sends
 *       "answered":  37,                               questions answered
 *       "total":     52,                               questions asked
 *       "percent":   71,                               round( 100 × answered / total )
 *       "submitted": false,                            the answers went in
 *       "pay":       "connected" | "none",             a card gateway (PayPlus
 *                                                      or Cardcom) is switched
 *                                                      on in WooCommerce AND
 *                                                      holds its keys
 *       "gateway":   "payplus" | "cardcom" | "paypal" | "other" | ""
 *     }
 *
 * "gateway" names the connected one first; else PayPal when it is switched
 * on; else what the questionnaire chose (payplus, cardcom, other); else any
 * other switched-on gateway that takes money online ("other"); else "".
 * The keys themselves never leave the site — only whether they are there.
 *
 * Posted to the same webhook as the questionnaire_done event
 * (Onboard::settings()['hook']), so Make routes on "event". When: from a
 * daily cron, only if the payload differs from the last one Make accepted
 * and never twice within 20 hours; and once when the answers go in, from a
 * one-off cron event the submit lines up right after Drive::request(), so
 * the customer's page never waits on Make. Ten seconds' patience; a miss
 * is not remembered as sent, so the next day tries again.
 *
 * @package OC_Theme
 */

declare( strict_types = 1 );

namespace OC\Theme\Onboard;

defined( 'ABSPATH' ) || exit;

/**
 * The daily status post.
 */
final class Status {

	const CRON = 'oc_onboard_status';

	/**
	 * The one-off post lined up when the answers go in.
	 */
	const NOW = 'oc_onboard_status_now';

	/**
	 * What Make last accepted: hash, when, for which site, and whether that
	 * was the last word. A record carried over from the site this one was
	 * cloned from belongs to another site and is not read.
	 */
	const LAST = 'oc_onboard_status_sent';

	/**
	 * Two posts from the clock never come closer than this. The cron is
	 * daily; the margin forgives WordPress's cron for running a little early.
	 */
	const GAP = 20 * HOUR_IN_SECONDS;

	/**
	 * The card gateways the questionnaire installs, and the settings each
	 * cannot clear without (the same keys Apply writes into them).
	 */
	const CARDS = array(
		'payplus' => array( 'payplus-payment-gateway', array( 'api_key', 'secret_key', 'payment_page_id' ) ),
		'cardcom' => array( 'cardcom', array( 'terminalnumber', 'username', 'apipass' ) ),
	);

	/**
	 * Hook in.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'schedule' ) );
		add_action( self::CRON, array( __CLASS__, 'daily' ) );
		add_action( self::NOW, array( __CLASS__, 'send' ) );
	}

	/**
	 * A daily tick, the way the reminders keep theirs. Whether there is
	 * anything to say is the tick's own question: asking it here would
	 * read three options on every page of every shop.
	 */
	public function schedule(): void {
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON );
		}
	}

	/**
	 * Is there a questionnaire to report on, somewhere to report it, and
	 * something still open?
	 */
	public static function active(): bool {
		$status = (string) Onboard::state()['status'];
		$last   = self::last();

		return in_array( $status, array( 'draft', 'submitted', 'applied' ), true )
			&& '' !== self::hook()
			&& empty( $last['finished'] );
	}

	/**
	 * The clock's post: only when something changed, and only once a day.
	 */
	public static function daily(): void {
		if ( ! self::active() ) {
			return;
		}

		if ( time() - (int) self::last()['when'] < self::GAP ) {
			return;
		}

		self::send();
	}

	/**
	 * The answers just went in: post now, but not on the customer's time.
	 * A one-off cron event, and a nudge to the cron so it runs at once.
	 */
	public static function soon(): void {
		if ( ! self::active() ) {
			return;
		}

		if ( ! wp_next_scheduled( self::NOW ) ) {
			wp_schedule_single_event( time(), self::NOW );
		}

		spawn_cron();
	}

	/**
	 * Post the state if it differs from the last one Make accepted.
	 *
	 * Runs only from cron, so the ten seconds it may wait never fall on a
	 * page. A miss leaves the record as it was: the next run tries again.
	 */
	public static function send(): void {
		if ( ! self::active() ) {
			return;
		}

		$body = self::payload();
		$json = (string) wp_json_encode( $body );
		$hash = md5( $json );

		if ( self::last()['hash'] === $hash ) {
			return;
		}

		$res = wp_remote_post(
			self::hook(),
			array(
				'timeout' => 10,
				'headers' => array( 'Content-Type' => 'application/json; charset=utf-8' ),
				'body'    => $json,
			)
		);

		$code = is_wp_error( $res ) ? 0 : (int) wp_remote_retrieve_response_code( $res );

		if ( $code < 200 || $code >= 300 ) {
			return;
		}

		update_option(
			self::LAST,
			array(
				'hash'     => $hash,
				'when'     => time(),
				'site'     => home_url( '/' ),
				// Answers in and money taken: this was the last word.
				'finished' => $body['submitted'] && 'connected' === $body['pay'] ? 1 : 0,
			),
			false
		);
	}

	/**
	 * The state, in the shape documented above.
	 *
	 * @return array{event:string,site:string,monday:mixed,answered:int,total:int,percent:int,submitted:bool,pay:string,gateway:string}
	 */
	public static function payload(): array {
		$state = Onboard::state();
		$prog  = Draft::progress();
		$card  = self::connected();

		return array(
			'event'     => 'status',
			'site'      => home_url( '/' ),
			'monday'    => $state['monday'],
			'answered'  => (int) $prog['answered'],
			'total'     => (int) $prog['total'],
			'percent'   => $prog['total'] > 0 ? (int) round( 100 * $prog['answered'] / $prog['total'] ) : 0,
			'submitted' => (int) $state['submitted'] > 0 || in_array( (string) $state['status'], array( 'submitted', 'applied' ), true ),
			'pay'       => '' !== $card ? 'connected' : 'none',
			'gateway'   => '' !== $card ? $card : self::gateway(),
		);
	}

	/**
	 * The card gateway that can take money now: switched on in WooCommerce
	 * and holding every key it needs. Read from the gateway's own saved
	 * settings, so it holds on a cron run as well as on a page.
	 *
	 * @return string payplus | cardcom | ''.
	 */
	public static function connected(): string {
		foreach ( self::CARDS as $name => $card ) {
			list( $id, $keys ) = $card;

			$sets = get_option( 'woocommerce_' . $id . '_settings' );

			if ( ! is_array( $sets ) || 'yes' !== (string) ( $sets['enabled'] ?? '' ) ) {
				continue;
			}

			$full = true;

			foreach ( $keys as $k ) {
				if ( '' === trim( (string) ( $sets[ $k ] ?? '' ) ) ) {
					$full = false;
				}
			}

			if ( $full ) {
				return $name;
			}
		}

		return '';
	}

	/**
	 * Which way of taking money this shop is on, when no card gateway is
	 * connected yet.
	 *
	 * @return string paypal | payplus | cardcom | other | ''.
	 */
	private static function gateway(): string {
		$paypal = get_option( 'woocommerce_ppcp-gateway_settings' );

		if ( is_array( $paypal ) && 'yes' === (string) ( $paypal['enabled'] ?? '' ) ) {
			return 'paypal';
		}

		$kept   = (array) get_option( 'oc_onboard_pay', array() );
		$chosen = (string) ( $kept['gateway'] ?? ( Draft::answered( 'pay_gw' ) ? Draft::value( 'pay_gw' ) : '' ) );

		if ( in_array( $chosen, array( 'payplus', 'cardcom', 'other' ), true ) ) {
			return $chosen;
		}

		if ( function_exists( 'WC' ) && WC()->payment_gateways() ) {
			foreach ( WC()->payment_gateways()->payment_gateways() as $g ) {
				if ( 'yes' === $g->enabled && ! in_array( (string) $g->id, array( 'cod', 'bacs', 'cheque' ), true ) ) {
					return 'other';
				}
			}
		}

		return '';
	}

	/**
	 * Make's webhook, the one the questionnaire_done event goes to.
	 */
	private static function hook(): string {
		return trim( (string) ( Onboard::settings()['hook'] ?? '' ) );
	}

	/**
	 * The last post's record.
	 *
	 * @return array{hash:string,when:int,finished:int}
	 */
	private static function last(): array {
		$saved = get_option( self::LAST );
		$saved = is_array( $saved ) && home_url( '/' ) === (string) ( $saved['site'] ?? '' ) ? $saved : array();

		return array(
			'hash'     => (string) ( $saved['hash'] ?? '' ),
			'when'     => (int) ( $saved['when'] ?? 0 ),
			'finished' => (int) ( $saved['finished'] ?? 0 ),
		);
	}
}
