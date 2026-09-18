<?php
/**
 * Statistics: the digest email.
 *
 * Daily, in the morning, about yesterday: a whole day that compares
 * cleanly to the day before and to the same weekday last week. Weekly on
 * Sunday morning about the week that ended. What sold, what was cancelled
 * or failed, the orders themselves, the best sellers, and the top insight.
 *
 * @package OC_Stats
 */

declare( strict_types = 1 );

namespace OC\Stats;

defined( 'ABSPATH' ) || exit;

/**
 * Build and send.
 */
final class Mail {

	/**
	 * Sends what is due this hour, once.
	 */
	public static function maybe_send(): void {
		$s    = Settings::get();
		$to   = Settings::recipients();
		$hour = (int) wp_date( 'G' );

		if ( empty( $to ) || $hour < $s['mail_hour'] ) {
			return;
		}

		$today = Query::today();

		if ( $s['mail_daily'] && (string) get_option( 'oc_stats_mail_daily', '' ) !== $today ) {
			update_option( 'oc_stats_mail_daily', $today, false );
			$yesterday = Query::shift( $today, -1 );
			self::send( $yesterday, $yesterday, 'daily', $to );
		}

		if ( $s['mail_weekly'] && '0' === (string) wp_date( 'w' ) && (string) get_option( 'oc_stats_mail_weekly', '' ) !== $today ) {
			update_option( 'oc_stats_mail_weekly', $today, false );
			$to_day = Query::shift( $today, -1 );
			self::send( Query::shift( $to_day, -6 ), $to_day, 'weekly', $to );
		}
	}

	/**
	 * A test: yesterday's report, now, to the saved recipients.
	 */
	public static function send_test(): bool {
		$to = Settings::recipients();

		if ( empty( $to ) ) {
			return false;
		}

		$yesterday = Query::shift( Query::today(), -1 );

		return self::send( $yesterday, $yesterday, 'daily', $to );
	}

	/**
	 * Build and send one report.
	 *
	 * @param string            $from Y-m-d.
	 * @param string            $to   Y-m-d.
	 * @param string            $kind daily | weekly.
	 * @param array<int,string> $rcpt Recipients.
	 */
	public static function send( string $from, string $to, string $kind, array $rcpt ): bool {
		$built   = self::build( $from, $to, $kind );
		$headers = array( 'Content-Type: text/html; charset=UTF-8' );

		return wp_mail( $rcpt, $built['subject'], $built['html'], $headers );
	}

	/**
	 * The subject and the HTML.
	 *
	 * @param string $from Y-m-d.
	 * @param string $to   Y-m-d.
	 * @param string $kind daily | weekly.
	 * @return array{subject:string,html:string}
	 */
	public static function build( string $from, string $to, string $kind ): array {
		$r    = Query::range( 'custom', $from, $to );
		$cur  = $r['cur'];
		$prev = $r['prev'];
		$shop = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		$rtl  = is_rtl();

		// Same weekday last week, for the daily report's second comparison.
		$week_ago = 'daily' === $kind ? Query::day( Query::shift( $from, -7 ) ) : null;

		$when = 'daily' === $kind
			? wp_date( 'l, j.n.Y', strtotime( $from ) )
			: sprintf( '%s – %s', wp_date( 'j.n', strtotime( $from ) ), wp_date( 'j.n.Y', strtotime( $to ) ) );

		$subject = 'daily' === $kind
			? sprintf(
				/* translators: 1: shop, 2: sales, 3: orders. */
				__( '%1$s yesterday: %2$s in %3$d orders', 'oc-stats' ),
				$shop,
				self::money( (float) $cur['gross'] ),
				(int) $cur['orders']
			)
			: sprintf(
				/* translators: 1: shop, 2: sales, 3: orders. */
				__( '%1$s this week: %2$s in %3$d orders', 'oc-stats' ),
				$shop,
				self::money( (float) $cur['gross'] ),
				(int) $cur['orders']
			);

		$conv  = (int) $cur['sessions'] >= (int) $cur['orders'] && (int) $cur['sessions'] > 0 ? (float) $cur['orders'] / (float) $cur['sessions'] * 100 : null;
		$pconv = (int) $prev['sessions'] >= (int) $prev['orders'] && (int) $prev['sessions'] > 0 ? (float) $prev['orders'] / (float) $prev['sessions'] * 100 : null;
		$aov   = (int) $cur['orders'] > 0 ? (float) $cur['gross'] / (float) $cur['orders'] : 0;
		$paov  = (int) $prev['orders'] > 0 ? (float) $prev['gross'] / (float) $prev['orders'] : 0;

		$tiles = array(
			array( __( 'Sales', 'oc-stats' ), self::money( (float) $cur['gross'] ), Query::change( (float) $cur['gross'], (float) $prev['gross'] ) ),
			array( __( 'Orders', 'oc-stats' ), number_format_i18n( (int) $cur['orders'] ), Query::change( (float) $cur['orders'], (float) $prev['orders'] ) ),
			array( __( 'Visits', 'oc-stats' ), number_format_i18n( (int) $cur['sessions'] ), Query::change( (float) $cur['sessions'], (float) $prev['sessions'] ) ),
			array( __( 'Conversion', 'oc-stats' ), null === $conv ? '—' : number_format_i18n( $conv, 2 ) . '%', null !== $conv && null !== $pconv && $pconv > 0 ? round( $conv - $pconv, 2 ) : null ),
			array( __( 'Average order', 'oc-stats' ), self::money( $aov ), Query::change( $aov, $paov ) ),
			array( __( 'Cancelled and failed', 'oc-stats' ), number_format_i18n( (int) $cur['cancelled_n'] + (int) $cur['failed_n'] ), null ),
		);

		$compare = 'daily' === $kind ? __( 'against the day before', 'oc-stats' ) : __( 'against the previous week', 'oc-stats' );

		$o  = '';
		$o .= '<div style="font-family:Arial,Helvetica,sans-serif;background:#f3f4f6;padding:24px 12px;direction:' . ( $rtl ? 'rtl' : 'ltr' ) . ';">';
		$o .= '<table role="presentation" cellpadding="0" cellspacing="0" style="max-width:640px;margin:0 auto;background:#fff;border-radius:12px;overflow:hidden;border:1px solid #e5e7eb;">';
		$o .= '<tr><td style="padding:22px 24px 6px;">';
		$o .= '<div style="font-size:12px;color:#6b7280;">' . esc_html( $shop ) . ' · ' . esc_html( 'daily' === $kind ? __( 'Daily report', 'oc-stats' ) : __( 'Weekly report', 'oc-stats' ) ) . '</div>';
		$o .= '<div style="font-size:22px;font-weight:700;color:#111827;margin-top:4px;">' . esc_html( $when ) . '</div>';
		$o .= '<div style="font-size:13px;color:#6b7280;margin-top:2px;">' . esc_html( sprintf( /* translators: %s: comparison. */ __( 'Changes are %s.', 'oc-stats' ), $compare ) ) . '</div>';
		$o .= '</td></tr>';

		// Tiles, three per row.
		$o .= '<tr><td style="padding:10px 16px 4px;"><table role="presentation" cellpadding="0" cellspacing="0" width="100%">';

		foreach ( array_chunk( $tiles, 3 ) as $row ) {
			$o .= '<tr>';

			foreach ( $row as $t ) {
				$o .= '<td width="33%" style="padding:6px;"><div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:10px;padding:12px 12px 10px;">';
				$o .= '<div style="font-size:12px;color:#6b7280;">' . esc_html( $t[0] ) . '</div>';
				$o .= '<div style="font-size:20px;font-weight:700;color:#111827;margin-top:2px;direction:ltr;text-align:' . ( $rtl ? 'right' : 'left' ) . ';">' . esc_html( $t[1] ) . '</div>';
				$o .= '<div style="font-size:12px;margin-top:3px;">' . self::delta( $t[2] ) . '</div>';
				$o .= '</div></td>';
			}

			$o .= '</tr>';
		}

		$o .= '</table></td></tr>';

		if ( null !== $week_ago ) {
			$o .= '<tr><td style="padding:2px 24px 10px;font-size:13px;color:#4b5563;">' . esc_html(
				sprintf(
					/* translators: 1: weekday, 2: sales, 3: orders. */
					__( 'Same weekday last week (%1$s): %2$s in %3$d orders.', 'oc-stats' ),
					wp_date( 'j.n', strtotime( Query::shift( $from, -7 ) ) ),
					self::money( (float) $week_ago['gross'] ),
					(int) $week_ago['orders']
				)
			) . '</td></tr>';
		}

		// Weekly: a day-by-day line.
		if ( 'weekly' === $kind ) {
			$o .= '<tr><td style="padding:6px 24px 4px;">' . self::h( __( 'Day by day', 'oc-stats' ) ) . '<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="font-size:13px;">';

			foreach ( $r['series'] as $s ) {
				$o .= '<tr><td style="padding:5px 0;border-bottom:1px solid #f3f4f6;color:#374151;">' . esc_html( wp_date( 'D j.n', strtotime( $s[0] ) ) ) . '</td>';
				$o .= '<td style="padding:5px 0;border-bottom:1px solid #f3f4f6;text-align:' . ( $rtl ? 'left' : 'right' ) . ';direction:ltr;color:#111827;font-weight:700;">' . esc_html( self::money( (float) $s[1] ) ) . '</td>';
				$o .= '<td style="padding:5px 0 5px 12px;border-bottom:1px solid #f3f4f6;text-align:' . ( $rtl ? 'left' : 'right' ) . ';color:#6b7280;">' . esc_html( sprintf( /* translators: %d: orders. */ _n( '%d order', '%d orders', (int) $s[2], 'oc-stats' ), (int) $s[2] ) ) . '</td></tr>';
			}

			$o .= '</table></td></tr>';
		}

		// Orders, daily only, up to 40.
		if ( 'daily' === $kind ) {
			$orders = self::orders_of( $from, $to );

			if ( ! empty( $orders ) ) {
				$o .= '<tr><td style="padding:6px 24px 4px;">' . self::h( __( 'The orders', 'oc-stats' ) ) . '<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="font-size:13px;">';

				foreach ( array_slice( $orders, 0, 40 ) as $ord ) {
					$o .= '<tr><td style="padding:5px 0;border-bottom:1px solid #f3f4f6;"><a href="' . esc_url( $ord['url'] ) . '" style="color:#111827;text-decoration:none;font-weight:700;">#' . esc_html( $ord['number'] ) . '</a> <span style="color:#6b7280;">' . esc_html( $ord['name'] ) . '</span></td>';
					$o .= '<td style="padding:5px 0;border-bottom:1px solid #f3f4f6;color:#6b7280;">' . esc_html( $ord['status'] ) . '</td>';
					$o .= '<td style="padding:5px 0;border-bottom:1px solid #f3f4f6;text-align:' . ( $rtl ? 'left' : 'right' ) . ';direction:ltr;font-weight:700;color:' . ( $ord['bad'] ? '#b91c1c' : '#111827' ) . ';">' . esc_html( self::money( $ord['total'] ) ) . '</td></tr>';
				}

				$o .= '</table></td></tr>';
			}
		}

		// Best sellers.
		$gross = (array) $cur['product_gross'];
		arsort( $gross );
		$top = array_slice( $gross, 0, 5, true );

		if ( ! empty( $top ) ) {
			$o .= '<tr><td style="padding:6px 24px 4px;">' . self::h( __( 'Best sellers', 'oc-stats' ) ) . '<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="font-size:13px;">';

			foreach ( $top as $pid => $sum ) {
				$p  = wc_get_product( (int) $pid );
				$o .= '<tr><td style="padding:5px 0;border-bottom:1px solid #f3f4f6;color:#374151;">' . esc_html( $p ? $p->get_name() : '#' . $pid ) . '</td>';
				$o .= '<td style="padding:5px 0;border-bottom:1px solid #f3f4f6;color:#6b7280;">' . esc_html( sprintf( /* translators: %d: units. */ _n( '%d unit', '%d units', (int) ( $cur['product_qty'][ $pid ] ?? 0 ), 'oc-stats' ), (int) ( $cur['product_qty'][ $pid ] ?? 0 ) ) ) . '</td>';
				$o .= '<td style="padding:5px 0;border-bottom:1px solid #f3f4f6;text-align:' . ( $rtl ? 'left' : 'right' ) . ';direction:ltr;font-weight:700;color:#111827;">' . esc_html( self::money( (float) $sum ) ) . '</td></tr>';
			}

			$o .= '</table></td></tr>';
		}

		// Cancelled, failed, refunds.
		$bits = array();

		if ( (int) $cur['cancelled_n'] > 0 ) {
			$bits[] = sprintf( /* translators: 1: count, 2: sum. */ __( '%1$d cancelled (%2$s)', 'oc-stats' ), (int) $cur['cancelled_n'], self::money( (float) $cur['cancelled_sum'] ) );
		}

		if ( (int) $cur['failed_n'] > 0 ) {
			$bits[] = sprintf( /* translators: 1: count, 2: sum. */ __( '%1$d failed (%2$s)', 'oc-stats' ), (int) $cur['failed_n'], self::money( (float) $cur['failed_sum'] ) );
		}

		if ( (float) $cur['refunds'] > 0 ) {
			$bits[] = sprintf( /* translators: %s: sum. */ __( 'refunds %s', 'oc-stats' ), self::money( (float) $cur['refunds'] ) );
		}

		if ( (int) $cur['pending_n'] > 0 ) {
			$bits[] = sprintf( /* translators: 1: count, 2: sum. */ __( '%1$d awaiting payment (%2$s)', 'oc-stats' ), (int) $cur['pending_n'], self::money( (float) $cur['pending_sum'] ) );
		}

		if ( ! empty( $bits ) ) {
			$o .= '<tr><td style="padding:6px 24px 4px;font-size:13px;color:#4b5563;">' . self::h( __( 'Did not become sales', 'oc-stats' ) ) . esc_html( implode( ' · ', $bits ) ) . '</td></tr>';
		}

		// Top insight.
		$ins = Insights::top();

		if ( ! empty( $ins ) ) {
			$o .= '<tr><td style="padding:10px 24px 4px;">' . self::h( __( 'Worth doing now', 'oc-stats' ) );

			foreach ( array_slice( $ins, 0, 3 ) as $i ) {
				$color = 'crit' === $i['sev'] ? '#b91c1c' : ( 'warn' === $i['sev'] ? '#b45309' : '#0e7c8a' );
				$o    .= '<div style="border:1px solid #e5e7eb;border-' . ( $rtl ? 'right' : 'left' ) . ':3px solid ' . $color . ';border-radius:8px;padding:10px 12px;margin-bottom:8px;">';
				$o    .= '<div style="font-size:11px;font-weight:700;color:' . $color . ';">' . esc_html( $i['kind'] ) . '</div>';
				$o    .= '<div style="font-size:14px;font-weight:700;color:#111827;margin-top:2px;">' . esc_html( $i['title'] ) . '</div>';
				$o    .= '<div style="font-size:13px;color:#4b5563;margin-top:2px;">' . esc_html( $i['body'] ) . '</div>';

				if ( ! empty( $i['actions'][0][1] ) ) {
					$o .= '<div style="margin-top:6px;"><a href="' . esc_url( $i['actions'][0][1] ) . '" style="font-size:12px;color:#0e7c8a;font-weight:700;">' . esc_html( $i['actions'][0][0] ) . ' ←</a></div>';
				}

				$o .= '</div>';
			}

			$o .= '</td></tr>';
		}

		$o .= '<tr><td style="padding:14px 24px 22px;text-align:center;"><a href="' . esc_url( admin_url( 'admin.php?page=oc-stats' ) ) . '" style="display:inline-block;background:#111827;color:#fff;text-decoration:none;font-size:14px;font-weight:700;padding:11px 20px;border-radius:999px;">' . esc_html__( 'Open the statistics', 'oc-stats' ) . '</a>';
		$o .= '<div style="font-size:11px;color:#9ca3af;margin-top:12px;">' . esc_html__( 'Sales count paid orders and orders in hand, at what the customer paid. Visits are measured by the shop itself; people who declined statistics cookies are not counted.', 'oc-stats' ) . '</div></td></tr>';
		$o .= '</table></div>';

		return array(
			'subject' => $subject,
			'html'    => $o,
		);
	}

	/**
	 * A section heading.
	 *
	 * @param string $text Heading.
	 */
	private static function h( string $text ): string {
		return '<div style="font-size:13px;font-weight:700;color:#111827;margin:10px 0 6px;">' . esc_html( $text ) . '</div>';
	}

	/**
	 * A change, coloured.
	 *
	 * @param float|null $pct Percent, or points; null for nothing to compare.
	 */
	private static function delta( ?float $pct ): string {
		if ( null === $pct ) {
			return '<span style="color:#9ca3af;">—</span>';
		}

		$color = $pct > 0 ? '#15803d' : ( $pct < 0 ? '#b91c1c' : '#6b7280' );
		$sign  = $pct > 0 ? '+' : '';

		return '<span style="color:' . $color . ';direction:ltr;unicode-bidi:isolate;">' . esc_html( $sign . number_format_i18n( $pct, 1 ) . '%' ) . '</span>';
	}

	/**
	 * Money, whole.
	 *
	 * @param float $n Amount.
	 */
	private static function money( float $n ): string {
		return wp_strip_all_tags( html_entity_decode( wc_price( round( $n, 2 ), array( 'decimals' => (float) (int) $n === $n ? 0 : 2 ) ), ENT_QUOTES, 'UTF-8' ) );
	}

	/**
	 * The period's orders, for the daily list.
	 *
	 * @param string $from Y-m-d.
	 * @param string $to   Y-m-d.
	 * @return array<int,array{number:string,name:string,status:string,total:float,url:string,bad:bool}>
	 */
	private static function orders_of( string $from, string $to ): array {
		$tz     = wp_timezone();
		$a      = ( new \DateTimeImmutable( $from . ' 00:00:00', $tz ) )->getTimestamp();
		$b      = ( new \DateTimeImmutable( $to . ' 23:59:59', $tz ) )->getTimestamp();
		$orders = wc_get_orders(
			array(
				'limit'        => 60,
				'status'       => array( 'processing', 'completed', 'on-hold', 'pending', 'cancelled', 'failed' ),
				'date_created' => $a . '...' . $b,
				'orderby'      => 'date',
				'order'        => 'DESC',
				'type'         => 'shop_order',
			)
		);

		$out = array();

		foreach ( (array) $orders as $order ) {
			if ( ! $order instanceof \WC_Order ) {
				continue;
			}

			$status = $order->get_status();
			$out[]  = array(
				'number' => (string) $order->get_order_number(),
				'name'   => trim( (string) $order->get_billing_first_name() . ' ' . mb_substr( (string) $order->get_billing_last_name(), 0, 1 ) . '.' ),
				'status' => wc_get_order_status_name( $status ),
				'total'  => (float) $order->get_total(),
				'url'    => (string) $order->get_edit_order_url(),
				'bad'    => in_array( $status, array( 'cancelled', 'failed' ), true ),
			);
		}

		return $out;
	}
}
