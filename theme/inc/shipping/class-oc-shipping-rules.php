<?php
/**
 * The shop's shipping rules, as data.
 *
 * One option holds everything the calculator needs: the base price, the
 * free-delivery line, the groups that stand outside it, and the regions
 * that cost differently. The screen that edits it comes later; the shape
 * is versioned so that screen — and any future one — can migrate what an
 * older theme saved.
 *
 * @package OC\Theme
 */

declare( strict_types = 1 );

namespace OC\Theme\Shipping;

if ( ! defined( 'ABSPATH' ) && ! defined( 'OC_TESTS' ) ) {
	exit;
}

/**
 * Loads, shapes and stores the rules.
 */
final class Rules {

	const OPTION  = 'oc_shipping_rules';
	const VERSION = 1;

	/**
	 * The rules read once per request.
	 *
	 * @var array<string,mixed>|null
	 */
	private static $cached = null;

	/**
	 * The rules as the calculator wants them. Seeded from WooCommerce's own
	 * methods the first time, so a shop that already ships keeps shipping
	 * the same way the moment the switch is thrown.
	 *
	 * @return array<string,mixed>
	 */
	public static function get(): array {
		if ( null !== self::$cached ) {
			return self::$cached;
		}

		$raw = get_option( self::OPTION, null );

		if ( ! is_array( $raw ) ) {
			$raw = self::seed_from_woo();
		}

		self::$cached = self::normalize( $raw );

		return self::$cached;
	}

	/**
	 * Is the theme's shipping in charge on this site?
	 */
	public static function enabled(): bool {
		return class_exists( 'WooCommerce' ) && ! empty( self::get()['enabled'] );
	}

	/**
	 * Store rules, normalized.
	 *
	 * @param array<string,mixed> $rules Rules.
	 */
	public static function save( array $rules ): void {
		update_option( self::OPTION, self::normalize( $rules ), false );
		self::$cached = null;
	}

	/**
	 * Every field present and typed, whatever was saved.
	 *
	 * @param array<string,mixed> $raw Raw rules.
	 * @return array<string,mixed>
	 */
	public static function normalize( array $raw ): array {
		$groups = array();

		foreach ( (array) ( $raw['groups'] ?? array() ) as $key => $g ) {
			if ( ! is_array( $g ) ) {
				continue;
			}

			$slug = self::slug( (string) ( $g['slug'] ?? ( is_string( $key ) ? $key : '' ) ) );

			if ( '' === $slug ) {
				continue;
			}

			$groups[ $slug ] = array(
				'slug'    => $slug,
				'name'    => trim( (string) ( $g['name'] ?? $slug ) ),
				'price'   => max( 0.0, (float) ( $g['price'] ?? 0 ) ),
				'in_free' => ! empty( $g['in_free'] ),
			);
		}

		$regions = array();

		foreach ( (array) ( $raw['regions'] ?? array() ) as $r ) {
			if ( ! is_array( $r ) ) {
				continue;
			}

			$name = trim( (string) ( $r['name'] ?? '' ) );

			if ( '' === $name ) {
				continue;
			}

			$regions[] = array(
				'name'      => $name,
				'postcodes' => self::list( $r['postcodes'] ?? array() ),
				'cities'    => self::list( $r['cities'] ?? array() ),
				'price'     => max( 0.0, (float) ( $r['price'] ?? 0 ) ),
				'free'      => 'no' === ( $r['free'] ?? 'inherit' ) ? 'no' : 'inherit',
			);
		}

		return array(
			'version'             => self::VERSION,
			'enabled'             => ! empty( $raw['enabled'] ),
			'base'                => max( 0.0, (float) ( $raw['base'] ?? 0 ) ),
			'free_over'           => max( 0.0, (float) ( $raw['free_over'] ?? 0 ) ),
			'free_ignore_coupons' => ! empty( $raw['free_ignore_coupons'] ),
			'mode'                => 'sum' === ( $raw['mode'] ?? 'max' ) ? 'sum' : 'max',
			'label'               => trim( (string) ( $raw['label'] ?? '' ) ),
			'free_label'          => trim( (string) ( $raw['free_label'] ?? '' ) ),
			'groups'              => $groups,
			'regions'             => $regions,
		);
	}

	/**
	 * What WooCommerce's own zone says today: the flat rate's price and the
	 * free-shipping minimum, read from the first zone that has them. Not
	 * enabled — reading is not deciding.
	 *
	 * @return array<string,mixed>
	 */
	public static function seed_from_woo(): array {
		$seed = array(
			'enabled'   => false,
			'base'      => 0,
			'free_over' => 0,
		);

		if ( ! class_exists( '\WC_Shipping_Zones' ) ) {
			return $seed;
		}

		// A paused method still says what the shop charged — the theme's own
		// switch may have paused it — so its price counts too; an enabled
		// one is preferred by being read first.
		$rows = array();

		foreach ( \WC_Shipping_Zones::get_zones() as $zone ) {
			foreach ( $zone['shipping_methods'] as $method ) {
				$rows[] = $method;
			}
		}

		usort(
			$rows,
			static function ( $a, $b ): int {
				return ( 'yes' === $b->enabled ? 1 : 0 ) - ( 'yes' === $a->enabled ? 1 : 0 );
			}
		);

		foreach ( $rows as $method ) {
			if ( 'flat_rate' === $method->id && 0.0 === (float) $seed['base'] ) {
				$seed['base']  = (float) $method->get_option( 'cost' );
				$seed['label'] = (string) $method->get_title();
			}

			if ( 'free_shipping' === $method->id && 0.0 === (float) $seed['free_over'] ) {
				$seed['free_over']           = (float) $method->get_option( 'min_amount' );
				$seed['free_label']          = (string) $method->get_title();
				$seed['free_ignore_coupons'] = 'yes' === $method->get_option( 'ignore_discounts' );
			}
		}

		return $seed;
	}

	/**
	 * A list from a list or a comma/newline-separated string.
	 *
	 * @param mixed $v Value.
	 * @return string[]
	 */
	private static function list( $v ): array {
		$items = is_array( $v ) ? $v : preg_split( '/[\n,]+/', (string) $v );
		$out   = array();

		foreach ( (array) $items as $item ) {
			$item = trim( (string) $item );

			if ( '' !== $item ) {
				$out[] = $item;
			}
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * A slug the way WooCommerce's shipping classes carry them.
	 *
	 * @param string $s Slug or name.
	 */
	private static function slug( string $s ): string {
		$s = trim( $s );

		return function_exists( 'sanitize_title' ) ? (string) sanitize_title( $s ) : strtolower( preg_replace( '/[^a-z0-9\x{0590}-\x{05FF}-]+/iu', '-', $s ) );
	}
}
