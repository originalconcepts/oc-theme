<?php
/**
 * Statistics: the settings.
 *
 * @package OC_Stats
 */

declare( strict_types = 1 );

namespace OC\Stats;

defined( 'ABSPATH' ) || exit;

/**
 * One option, read everywhere.
 */
final class Settings {

	/**
	 * Option name.
	 */
	const OPTION = 'oc_stats';

	/**
	 * Defaults.
	 *
	 * @return array{mail_daily:int,mail_weekly:int,mail_to:string,mail_hour:int,exclude_ips:string}
	 */
	public static function defaults(): array {
		return array(
			'mail_daily'  => 0,
			'mail_weekly' => 0,
			'mail_to'     => '',
			'mail_hour'   => 8,
			'exclude_ips' => '',
		);
	}

	/**
	 * Saved settings over the defaults.
	 *
	 * @return array{mail_daily:int,mail_weekly:int,mail_to:string,mail_hour:int,exclude_ips:string}
	 */
	public static function get(): array {
		$d = self::defaults();
		$o = get_option( self::OPTION, array() );
		$o = is_array( $o ) ? $o : array();

		return array(
			'mail_daily'  => empty( $o['mail_daily'] ) ? 0 : 1,
			'mail_weekly' => empty( $o['mail_weekly'] ) ? 0 : 1,
			'mail_to'     => sanitize_text_field( (string) ( $o['mail_to'] ?? '' ) ),
			'mail_hour'   => isset( $o['mail_hour'] ) ? max( 0, min( 23, (int) $o['mail_hour'] ) ) : $d['mail_hour'],
			'exclude_ips' => sanitize_text_field( (string) ( $o['exclude_ips'] ?? '' ) ),
		);
	}

	/**
	 * The recipients, cleaned: only real addresses.
	 *
	 * @return array<int,string>
	 */
	public static function recipients(): array {
		$out = array();

		foreach ( preg_split( '/[\s,;]+/', self::get()['mail_to'] ) as $addr ) {
			$addr = sanitize_email( (string) $addr );

			if ( '' !== $addr && is_email( $addr ) ) {
				$out[] = $addr;
			}
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * Addresses that never count as visits.
	 *
	 * @return array<int,string>
	 */
	public static function excluded_ips(): array {
		$out = array();

		foreach ( preg_split( '/[\s,;]+/', self::get()['exclude_ips'] ) as $ip ) {
			$ip = trim( (string) $ip );

			if ( '' !== $ip && filter_var( $ip, FILTER_VALIDATE_IP ) ) {
				$out[] = $ip;
			}
		}

		return $out;
	}
}
