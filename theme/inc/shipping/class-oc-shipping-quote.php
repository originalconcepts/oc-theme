<?php
/**
 * The one shipping calculator.
 *
 * A pure function of three things — what is in the parcel, where it is
 * going, and the shop's rules — and nothing else: no cart, no session, no
 * database. The checkout's shipping method, the product page's delivery
 * line and the cart's free-shipping bar all ask this same function, so no
 * screen can promise what the checkout will not keep. Being pure, it is
 * tested on a table of parcels with no WordPress in the room.
 *
 * @package OC\Theme
 */

declare( strict_types = 1 );

namespace OC\Theme\Shipping;

if ( ! defined( 'ABSPATH' ) && ! defined( 'OC_TESTS' ) ) {
	exit;
}

/**
 * Quotes a parcel.
 */
final class Quote {

	/**
	 * Price a parcel.
	 *
	 * Lines are what the parcel holds: each a displayed subtotal (after
	 * whatever discounts the rules say count), a shipping-group slug ('' for
	 * none) and a quantity. The destination is a country, postcode and city,
	 * any of which may be empty. The rules are Rules::normalize()'d.
	 *
	 * The answer: the cost, whether it is free, which region and groups
	 * spoke, how much more would earn free delivery, and a list of reasons
	 * as codes with arguments — words are for the caller, who knows the
	 * language.
	 *
	 * @param array $lines Parcel lines: each [ 'subtotal' => float, 'group' => string, 'qty' => int ].
	 * @param array $dest  Destination: [ 'country' => string, 'postcode' => string, 'city' => string ].
	 * @param array $rules Normalized rules.
	 * @return array The quote.
	 *
	 * @phpstan-param array<int,array{subtotal:float,group:string,qty:int}> $lines
	 * @phpstan-param array{country?:string,postcode?:string,city?:string} $dest
	 * @phpstan-param array<string,mixed> $rules
	 * @phpstan-return array{cost:float,free:bool,region:string,groups:string[],eligible:float,threshold:float,missing:float,reasons:array<int,array{code:string,args:array<string,mixed>}>}
	 */
	public static function calculate( array $lines, array $dest, array $rules ): array {
		$region  = self::region_for( $dest, $rules );
		$base    = null !== $region ? (float) $region['price'] : (float) $rules['base'];
		$reasons = array();

		if ( null !== $region ) {
			$reasons[] = array(
				'code' => 'region',
				'args' => array(
					'name'  => (string) $region['name'],
					'price' => (float) $region['price'],
				),
			);
		}

		// Free delivery is earned by the lines that are allowed to earn it.
		$threshold = (float) $rules['free_over'];

		if ( null !== $region && 'no' === $region['free'] ) {
			$threshold = 0.0;
		}

		$eligible = 0.0;
		$present  = array();

		foreach ( $lines as $line ) {
			$slug  = (string) ( $line['group'] ?? '' );
			$group = '' !== $slug && isset( $rules['groups'][ $slug ] ) ? $rules['groups'][ $slug ] : null;

			if ( null === $group || ! empty( $group['in_free'] ) ) {
				$eligible += (float) ( $line['subtotal'] ?? 0 );
			}

			if ( null !== $group && empty( $group['in_free'] ) && ! isset( $present[ $slug ] ) ) {
				$present[ $slug ] = $group;
			}
		}

		$has_eligible = false;

		foreach ( $lines as $line ) {
			$slug = (string) ( $line['group'] ?? '' );

			if ( '' === $slug || ! isset( $rules['groups'][ $slug ] ) || ! empty( $rules['groups'][ $slug ]['in_free'] ) ) {
				$has_eligible = true;
				break;
			}
		}

		$free    = $threshold > 0 && $has_eligible && $eligible >= $threshold;
		$missing = $threshold > 0 && $has_eligible ? max( 0.0, $threshold - $eligible ) : 0.0;

		if ( $free ) {
			$reasons[] = array(
				'code' => 'free_over',
				'args' => array( 'threshold' => $threshold ),
			);
		}

		// The groups that stand outside free delivery are priced on their
		// own terms — once per group, not per unit.
		$prices = array();

		foreach ( $present as $slug => $group ) {
			$prices[]  = (float) $group['price'];
			$reasons[] = array(
				'code' => 'group',
				'args' => array(
					'slug'  => (string) $slug,
					'name'  => (string) $group['name'],
					'price' => (float) $group['price'],
				),
			);
		}

		if ( ! $free ) {
			$prices[] = $base;
		}

		$cost = self::combine( $prices, (string) $rules['mode'] );

		return array(
			'cost'      => round( max( 0.0, $cost ), 2 ),
			'free'      => $free && 0.0 === round( $cost, 2 ),
			'region'    => null !== $region ? (string) $region['name'] : '',
			'groups'    => array_keys( $present ),
			'eligible'  => round( $eligible, 2 ),
			'threshold' => $threshold,
			'missing'   => round( $missing, 2 ),
			'reasons'   => $reasons,
		);
	}

	/**
	 * The first region whose postcodes or cities take the destination.
	 *
	 * A postcode rule is an exact code, a range "88000-88999", or a prefix
	 * "88*". A city rule is a name, compared without case or edge spaces.
	 *
	 * @param array<string,string> $dest  Destination.
	 * @param array<string,mixed>  $rules Rules.
	 * @return array<string,mixed>|null
	 */
	private static function region_for( array $dest, array $rules ): ?array {
		$postcode = preg_replace( '/\s+/', '', (string) ( $dest['postcode'] ?? '' ) );
		$city     = self::fold( (string) ( $dest['city'] ?? '' ) );

		foreach ( (array) $rules['regions'] as $region ) {
			foreach ( (array) $region['postcodes'] as $rule ) {
				if ( '' !== $postcode && self::postcode_matches( $postcode, (string) $rule ) ) {
					return $region;
				}
			}

			foreach ( (array) $region['cities'] as $name ) {
				if ( '' !== $city && self::fold( (string) $name ) === $city ) {
					return $region;
				}
			}
		}

		return null;
	}

	/**
	 * Does a postcode fall under a rule?
	 *
	 * @param string $postcode Postcode, spaces removed.
	 * @param string $rule     Exact, "from-to" or "prefix*".
	 */
	public static function postcode_matches( string $postcode, string $rule ): bool {
		$rule = preg_replace( '/\s+/', '', $rule );

		if ( '' === $rule ) {
			return false;
		}

		if ( '*' === substr( $rule, -1 ) ) {
			$prefix = substr( $rule, 0, -1 );

			return '' !== $prefix && 0 === strpos( $postcode, $prefix );
		}

		if ( preg_match( '/^(\d+)-(\d+)$/', $rule, $m ) && ctype_digit( $postcode ) ) {
			$n = (int) $postcode;

			return $n >= (int) $m[1] && $n <= (int) $m[2];
		}

		return $postcode === $rule;
	}

	/**
	 * Prices stack by the shop's mode: the dearest one wins, or they add up.
	 *
	 * @param float[] $prices Prices.
	 * @param string  $mode   'max' | 'sum'.
	 */
	private static function combine( array $prices, string $mode ): float {
		if ( ! $prices ) {
			return 0.0;
		}

		return 'sum' === $mode ? (float) array_sum( $prices ) : (float) max( $prices );
	}

	/**
	 * A name folded for comparison.
	 *
	 * @param string $s Name.
	 */
	private static function fold( string $s ): string {
		$s = trim( $s );

		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $s ) : strtolower( $s );
	}
}
