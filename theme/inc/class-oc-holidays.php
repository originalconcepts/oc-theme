<?php
/**
 * The days the couriers do not drive.
 *
 * The delivery window walks forward over the weekdays a shop sends orders
 * out. That is enough for a normal week and wrong for a holiday: on the
 * second day of Rosh Hashanah nothing moves, whatever the weekday says, and
 * a page promising delivery that day is telling the shopper something
 * untrue.
 *
 * Holidays are not a list to be typed in and re-typed every autumn. The
 * Jewish ones are arithmetic — PHP's own calendar extension converts a
 * Hebrew date to a Gregorian one for any year — so they are computed, not
 * remembered, and a shop set up today still knows about Yom Kippur in 2043
 * with nobody touching it.
 *
 * Everything else is a list: a shop outside Israel picks no pack and types
 * its own days, or a country pack hooks `oc_shipping_closed_dates` and adds
 * its own arithmetic the same way this one does.
 *
 * @package OC_Theme
 */

declare( strict_types = 1 );

namespace OC\Theme;

defined( 'ABSPATH' ) || exit;

/**
 * Closed days for the delivery estimate.
 */
final class Holidays {

	/**
	 * PHP's Jewish calendar numbers all thirteen months whether the year
	 * has them or not, so a month is the same number every year.
	 */
	private const TISHRI = 1;
	private const NISAN  = 8;
	private const IYAR   = 9;
	private const SIVAN  = 10;

	/**
	 * Worked-out years, so a page of products asks once.
	 *
	 * @var array<int, array<string,string>>
	 */
	private static $cache = array();

	/**
	 * Which pack of public holidays the shop keeps.
	 *
	 * The default follows the shop rather than the person setting it up: a
	 * store selling from Israel gets the Israeli days without being asked,
	 * and everyone else starts with none.
	 */
	public static function pack(): string {
		$pack = (string) get_theme_mod( 'oc_ship_holidays', self::default_pack() );

		return in_array( $pack, array( 'il', 'none' ), true ) ? $pack : self::default_pack();
	}

	/**
	 * The pack a shop gets before anyone chooses one.
	 */
	public static function default_pack(): string {
		$country = function_exists( 'wc_get_base_location' ) ? (string) ( wc_get_base_location()['country'] ?? '' ) : '';

		if ( '' !== $country ) {
			return 'IL' === $country ? 'il' : 'none';
		}

		return 0 === strpos( get_locale(), 'he' ) ? 'il' : 'none';
	}

	/**
	 * Is this a day the shop cannot send or deliver on?
	 *
	 * @param \DateTimeImmutable $day The day.
	 */
	public static function closed( \DateTimeImmutable $day ): bool {
		return isset( self::days( (int) $day->format( 'Y' ) )[ $day->format( 'Y-m-d' ) ] );
	}

	/**
	 * Every closed day in a calendar year, 'Y-m-d' => name.
	 *
	 * @param int $year Gregorian year.
	 * @return array<string,string>
	 */
	public static function days( int $year ): array {
		if ( isset( self::$cache[ $year ] ) ) {
			return self::$cache[ $year ];
		}

		$days = 'il' === self::pack() ? self::israel( $year ) : array();

		if ( get_theme_mod( 'oc_ship_holiday_eves', false ) ) {
			$days = self::with_eves( $days );
		}

		foreach ( self::typed( $year ) as $date => $name ) {
			$days[ $date ] = $name;
		}

		/**
		 * The closed days of one year, 'Y-m-d' => name.
		 *
		 * A country pack adds its own here — computed like this one, or a
		 * plain list — without touching the theme.
		 *
		 * @param array<string,string> $days Closed days.
		 * @param int                  $year Gregorian year.
		 */
		$days = (array) apply_filters( 'oc_shipping_closed_dates', $days, $year );

		ksort( $days );

		self::$cache[ $year ] = $days;

		return $days;
	}

	/**
	 * The next few closed days from today, for the settings screen — proof
	 * that the setting is doing something, in the shop's own date format.
	 *
	 * @param int $how_many How many to name.
	 * @return array<int,string>
	 */
	public static function upcoming( int $how_many = 4 ): array {
		$today = ( new \DateTimeImmutable( 'today', wp_timezone() ) )->format( 'Y-m-d' );
		$out   = array();

		foreach ( array( 0, 1 ) as $ahead ) {
			$year = (int) gmdate( 'Y', strtotime( $today ) ) + $ahead;

			foreach ( self::days( $year ) as $date => $name ) {
				if ( $date >= $today && count( $out ) < $how_many ) {
					$out[] = wp_date( 'j/n', (int) strtotime( $date . ' 12:00' ) ) . ' ' . $name;
				}
			}
		}

		return $out;
	}

	/**
	 * The dates typed into the settings by hand.
	 *
	 * One per line. A full date — 2026-12-25 — closes that day once; a day
	 * and month — 12-25 — closes it every year. Anything after the date on
	 * the line is its name, so the list reads back to whoever wrote it.
	 *
	 * @param int $year Gregorian year.
	 * @return array<string,string>
	 */
	private static function typed( int $year ): array {
		$raw = trim( (string) get_theme_mod( 'oc_ship_holidays_extra', '' ) );

		if ( '' === $raw ) {
			return array();
		}

		$out = array();

		foreach ( preg_split( '/[\r\n,]+/', $raw ) as $line ) {
			$line = trim( (string) $line );

			if ( '' === $line || ! preg_match( '/^(\d{4}-)?(\d{1,2})-(\d{1,2})\s*(.*)$/u', $line, $m ) ) {
				continue;
			}

			$on = '' !== $m[1] ? (int) rtrim( $m[1], '-' ) : $year;

			if ( $on !== $year ) {
				continue;
			}

			$date = sprintf( '%04d-%02d-%02d', $year, (int) $m[2], (int) $m[3] );

			if ( checkdate( (int) $m[2], (int) $m[3], $year ) ) {
				$out[ $date ] = '' !== trim( $m[4] ) ? trim( $m[4] ) : __( 'Closed', 'oc-theme' );
			}
		}

		return $out;
	}

	/**
	 * The day before each holiday, for a shop that stops a day earlier.
	 *
	 * Only the eve of a day that is not itself already closed gets added —
	 * the middle of Rosh Hashanah is not an eve of anything.
	 *
	 * @param array<string,string> $days Closed days.
	 * @return array<string,string>
	 */
	private static function with_eves( array $days ): array {
		$out = $days;

		foreach ( array_keys( $days ) as $date ) {
			$eve = gmdate( 'Y-m-d', (int) strtotime( $date . ' 12:00 -1 day' ) );

			if ( ! isset( $days[ $eve ] ) ) {
				/* translators: %s: the holiday this is the eve of. */
				$out[ $eve ] = sprintf( __( 'Eve of %s', 'oc-theme' ), $days[ $date ] );
			}
		}

		return $out;
	}

	/**
	 * Israel's public holidays falling in one Gregorian year.
	 *
	 * The days on which the country does not work, which is what a courier
	 * follows: the two days of Rosh Hashanah, Yom Kippur, the first day of
	 * Sukkot and Simchat Torah, the first and seventh of Pesach, Shavuot,
	 * and Independence Day. Chol HaMoed is left out on purpose — the vans
	 * run — and so are the fasts and Purim, which a shop that closes for
	 * them can type into the list itself.
	 *
	 * A Gregorian year holds the tail of one Hebrew year and the head of
	 * the next, so both are asked and only the days that land inside the
	 * year are kept.
	 *
	 * @param int $year Gregorian year.
	 * @return array<string,string>
	 */
	private static function israel( int $year ): array {
		if ( ! function_exists( 'jewishtojd' ) ) {
			return array();
		}

		$out = array();

		foreach ( array( $year + 3760, $year + 3761 ) as $hebrew ) {
			$feasts = array(
				array( self::TISHRI, 1, __( 'Rosh Hashanah', 'oc-theme' ) ),
				array( self::TISHRI, 2, __( 'Rosh Hashanah', 'oc-theme' ) ),
				array( self::TISHRI, 10, __( 'Yom Kippur', 'oc-theme' ) ),
				array( self::TISHRI, 15, __( 'Sukkot', 'oc-theme' ) ),
				array( self::TISHRI, 22, __( 'Simchat Torah', 'oc-theme' ) ),
				array( self::NISAN, 15, __( 'Passover', 'oc-theme' ) ),
				array( self::NISAN, 21, __( 'Last day of Passover', 'oc-theme' ) ),
				array( self::SIVAN, 6, __( 'Shavuot', 'oc-theme' ) ),
			);

			foreach ( $feasts as $feast ) {
				$date = self::hebrew_date( (int) $feast[0], (int) $feast[1], $hebrew );

				if ( '' !== $date && (int) substr( $date, 0, 4 ) === $year ) {
					$out[ $date ] = (string) $feast[2];
				}
			}

			$day = self::independence( $hebrew );

			if ( '' !== $day && (int) substr( $day, 0, 4 ) === $year ) {
				$out[ $day ] = __( 'Independence Day', 'oc-theme' );
			}
		}

		return $out;
	}

	/**
	 * Independence Day, which moves so that it never touches Shabbat.
	 *
	 * The 5th of Iyar as written, except: falling on a Friday or a Saturday
	 * it is kept earlier, to the Thursday; falling on a Monday it is put off
	 * to the Tuesday — so that the memorial day before it never runs into
	 * the Sabbath either.
	 *
	 * @param int $hebrew Hebrew year.
	 */
	private static function independence( int $hebrew ): string {
		$date = self::hebrew_date( self::IYAR, 5, $hebrew );

		if ( '' === $date ) {
			return '';
		}

		$day = new \DateTimeImmutable( $date . ' 12:00', new \DateTimeZone( 'UTC' ) );

		switch ( (int) $day->format( 'w' ) ) {
			case 5: // Friday: back one, to Thursday.
				$day = $day->modify( '-1 day' );
				break;
			case 6: // Saturday: back two, to Thursday.
				$day = $day->modify( '-2 days' );
				break;
			case 1: // Monday: forward one, to Tuesday.
				$day = $day->modify( '+1 day' );
				break;
		}

		return $day->format( 'Y-m-d' );
	}

	/**
	 * One Hebrew date as 'Y-m-d', or '' when the calendar cannot say.
	 *
	 * @param int $month  Month, PHP's numbering.
	 * @param int $day    Day of the month.
	 * @param int $hebrew Hebrew year.
	 */
	private static function hebrew_date( int $month, int $day, int $hebrew ): string {
		$jd = jewishtojd( $month, $day, $hebrew );

		if ( ! $jd ) {
			return '';
		}

		// jdtogregorian answers "9/12/2026" — month, day, year.
		$parts = explode( '/', (string) jdtogregorian( $jd ) );

		if ( 3 !== count( $parts ) ) {
			return '';
		}

		return sprintf( '%04d-%02d-%02d', (int) $parts[2], (int) $parts[0], (int) $parts[1] );
	}
}
