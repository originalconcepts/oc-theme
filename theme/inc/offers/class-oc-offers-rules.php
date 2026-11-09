<?php
/**
 * Offers: the rules, as plain data and plain functions.
 *
 * A rule says "when the shopper has a product like THIS, offer one of
 * THOSE". Everything here is a function of its arguments — no database, no
 * WordPress — so the decisions a shop's money rides on can be tested with
 * nothing but PHP: which rule answers for a product, what may be offered
 * beside it, and what the discount comes to.
 *
 * @package OC_Theme
 */

declare( strict_types = 1 );

namespace OC\Theme\Offers;

defined( 'ABSPATH' ) || defined( 'OC_TESTS' ) || exit;

/**
 * Rule shape, matching and arithmetic.
 */
final class Rules {

	/**
	 * Where an offer can be shown. Only the places the theme really draws
	 * are listed; a rule cannot be saved for a place that does not exist.
	 */
	const PLACES = array( 'cart', 'checkout', 'xsell', 'upsell', 'similar' );

	/**
	 * How a rule's products are put in order.
	 */
	const ORDERS = array( 'best', 'new', 'cheap', 'dear' );

	/**
	 * The most a rule may take off, in percent. Past this it is a typing
	 * mistake rather than an offer.
	 */
	const MAX_PERCENT = 90;

	/**
	 * What a rule does: offer products, or price quantities in steps.
	 */
	const KINDS = array( 'offer', 'tiers' );

	/**
	 * The little labels a quantity tier may carry.
	 */
	const TIER_LABELS = array( '', 'popular', 'best' );

	/**
	 * How many tiers a product may have. More than that is a price list,
	 * not an offer.
	 */
	const MAX_TIERS = 6;

	/**
	 * A rule in its one true shape, whatever came in.
	 *
	 * @param array<string,mixed> $raw Rule as posted or as stored.
	 * @return array{name:string,on:int,kind:string,when:array{cats:int[],brands:int[],products:int[]},offer:array{cats:int[],products:int[]},same_brand:int,exclude:int[],where:string[],pct:int,count:int,order:string,tiers:array<int,array{qty:int,pct:int,label:string}>,default:int}
	 */
	public static function clean( array $raw ): array {
		$when  = isset( $raw['when'] ) && is_array( $raw['when'] ) ? $raw['when'] : array();
		$offer = isset( $raw['offer'] ) && is_array( $raw['offer'] ) ? $raw['offer'] : array();

		$name = isset( $raw['name'] ) && is_scalar( $raw['name'] ) ? (string) $raw['name'] : '';
		$name = trim( (string) preg_replace( '/\s+/u', ' ', strip_tags( $name ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- must run without WordPress.

		if ( function_exists( 'mb_substr' ) ) {
			$name = mb_substr( $name, 0, 120 );
		}

		$where = array();

		foreach ( (array) ( $raw['where'] ?? array() ) as $place ) {
			if ( is_string( $place ) && in_array( $place, self::PLACES, true ) && ! in_array( $place, $where, true ) ) {
				$where[] = $place;
			}
		}

		// A rule shown nowhere is a rule switched off by accident; the cart
		// is the place every rule starts in.
		if ( ! $where ) {
			$where = array( 'cart' );
		}

		$order = isset( $raw['order'] ) && is_string( $raw['order'] ) && in_array( $raw['order'], self::ORDERS, true ) ? $raw['order'] : 'best';
		$kind  = isset( $raw['kind'] ) && is_string( $raw['kind'] ) && in_array( $raw['kind'], self::KINDS, true ) ? $raw['kind'] : 'offer';
		$tiers = self::clean_tiers( isset( $raw['tiers'] ) && is_array( $raw['tiers'] ) ? $raw['tiers'] : array() );

		return array(
			'name'       => $name,
			'on'         => empty( $raw['on'] ) ? 0 : 1,
			'kind'       => $kind,
			'when'       => array(
				'cats'     => self::ids( $when['cats'] ?? array() ),
				'brands'   => self::ids( $when['brands'] ?? array() ),
				'products' => self::ids( $when['products'] ?? array() ),
			),
			'offer'      => array(
				'cats'     => self::ids( $offer['cats'] ?? array() ),
				'products' => self::ids( $offer['products'] ?? array() ),
			),
			'same_brand' => empty( $raw['same_brand'] ) ? 0 : 1,
			'exclude'    => self::ids( $raw['exclude'] ?? array() ),
			'where'      => $where,
			'pct'        => min( self::MAX_PERCENT, max( 0, (int) ( $raw['pct'] ?? 0 ) ) ),
			'count'      => min( 12, max( 1, (int) ( $raw['count'] ?? 4 ) ) ),
			'order'      => $order,
			'tiers'      => $tiers,
			'default'    => self::clean_default( (int) ( $raw['default'] ?? 0 ), $tiers ),
		);
	}

	/**
	 * Quantity tiers in their one true shape: whole quantities of two or
	 * more, each once, climbing, with a percent off and an optional label.
	 * The single unit is never a tier — it is the price itself.
	 *
	 * @param array<int,mixed> $raw Tiers as posted or as stored.
	 * @return array<int,array{qty:int,pct:int,label:string}>
	 */
	public static function clean_tiers( array $raw ): array {
		$by_qty = array();

		foreach ( $raw as $tier ) {
			if ( ! is_array( $tier ) ) {
				continue;
			}

			$qty = (int) ( $tier['qty'] ?? 0 );
			$pct = min( self::MAX_PERCENT, max( 0, (int) ( $tier['pct'] ?? 0 ) ) );

			if ( $qty < 2 || $qty > 9999 ) {
				continue;
			}

			$label = isset( $tier['label'] ) && is_string( $tier['label'] ) && in_array( $tier['label'], self::TIER_LABELS, true ) ? $tier['label'] : '';

			$by_qty[ $qty ] = array(
				'qty'   => $qty,
				'pct'   => $pct,
				'label' => $label,
			);
		}

		ksort( $by_qty );

		return array_slice( array_values( $by_qty ), 0, self::MAX_TIERS );
	}

	/**
	 * The quantity picked when the page opens: one of the tiers, or 1.
	 *
	 * @param int                                            $asked What was asked for.
	 * @param array<int,array{qty:int,pct:int,label:string}> $tiers Clean tiers.
	 */
	public static function clean_default( int $asked, array $tiers ): int {
		foreach ( $tiers as $tier ) {
			if ( $tier['qty'] === $asked ) {
				return $asked;
			}
		}

		return 1;
	}

	/**
	 * The tier a quantity has reached: the highest one at or below it, or
	 * null below the first.
	 *
	 * @param array<int,array{qty:int,pct:int,label:string}> $tiers Clean tiers, climbing.
	 * @param int                                            $qty   Units in the line.
	 * @return array{qty:int,pct:int,label:string}|null
	 */
	public static function tier_for( array $tiers, int $qty ): ?array {
		$hit = null;

		foreach ( $tiers as $tier ) {
			if ( $tier['qty'] <= $qty ) {
				$hit = $tier;
			}
		}

		return $hit;
	}

	/**
	 * The unit price at a tier.
	 *
	 * @param float $price    The unit price.
	 * @param int   $percent  The tier's percent.
	 * @param int   $decimals Decimals of the shop's currency.
	 */
	public static function unit_at( float $price, int $percent, int $decimals = 2 ): float {
		return round( $price - self::off( $price, $percent, $decimals ), $decimals );
	}

	/**
	 * The tiers rule that answers for a product: the first one, in the
	 * shop's order, that is switched on, has tiers and covers the product.
	 *
	 * @param array<string,array<string,mixed>>     $rules   Clean rules, keyed by their names.
	 * @param array{id:int,cats:int[],brands:int[]} $product The product's facts.
	 */
	public static function pick_tiers( array $rules, array $product ): ?string {
		foreach ( $rules as $uid => $rule ) {
			if ( empty( $rule['on'] ) || 'tiers' !== ( $rule['kind'] ?? 'offer' ) || empty( $rule['tiers'] ) ) {
				continue;
			}

			if ( self::matches( $rule, $product ) ) {
				return (string) $uid;
			}
		}

		return null;
	}

	/**
	 * A list of positive whole numbers, each once, in the order given.
	 *
	 * @param mixed $values Anything.
	 * @return int[]
	 */
	public static function ids( $values ): array {
		$out = array();

		foreach ( (array) $values as $id ) {
			$id = is_scalar( $id ) ? (int) $id : 0;

			if ( $id > 0 && ! in_array( $id, $out, true ) ) {
				$out[] = $id;
			}
		}

		return $out;
	}

	/**
	 * Whether a rule has anything to offer at all. A rule that names no
	 * category and no product, and does not ask for the same brand, would
	 * offer the whole shop — which is what the automatic layer is for.
	 *
	 * @param array<string,mixed> $rule A clean rule.
	 */
	public static function offers_something( array $rule ): bool {
		return ! empty( $rule['offer']['cats'] ) || ! empty( $rule['offer']['products'] ) || ! empty( $rule['same_brand'] );
	}

	/**
	 * Whether a rule speaks for a product.
	 *
	 * The product is given as facts: its id, its categories WITH their
	 * ancestors (a rule on "Bathroom" covers "Bathroom / Soap dishes") and
	 * its brands. A rule that names nothing covers every product; one that
	 * names several things covers a product that fits any of them.
	 *
	 * @param array<string,mixed>                   $rule    A clean rule.
	 * @param array{id:int,cats:int[],brands:int[]} $product The product's facts.
	 */
	public static function matches( array $rule, array $product ): bool {
		$when = $rule['when'];

		if ( ! $when['cats'] && ! $when['brands'] && ! $when['products'] ) {
			return true;
		}

		if ( in_array( (int) $product['id'], $when['products'], true ) ) {
			return true;
		}

		if ( array_intersect( $when['cats'], $product['cats'] ) ) {
			return true;
		}

		return (bool) array_intersect( $when['brands'], $product['brands'] );
	}

	/**
	 * The rule that answers for a product in a place: the first one, in the
	 * shop's own order, that is switched on, shows there, has something to
	 * offer and covers the product. Null when none does.
	 *
	 * @param array<string,array<string,mixed>>     $rules   Clean rules, keyed by their names.
	 * @param array{id:int,cats:int[],brands:int[]} $product The product's facts.
	 * @param string                                $place   One of PLACES.
	 */
	public static function pick( array $rules, array $product, string $place ): ?string {
		foreach ( $rules as $uid => $rule ) {
			if ( empty( $rule['on'] ) || 'offer' !== ( $rule['kind'] ?? 'offer' ) || ! in_array( $place, $rule['where'], true ) ) {
				continue;
			}

			if ( ! self::offers_something( $rule ) ) {
				continue;
			}

			// A rule that asks for the same brand has nothing to say about
			// a product with no brand.
			if ( ! empty( $rule['same_brand'] ) && ! $product['brands'] ) {
				continue;
			}

			if ( self::matches( $rule, $product ) ) {
				return (string) $uid;
			}
		}

		return null;
	}

	/**
	 * What may really be offered out of a list of candidates: never the
	 * product itself, never something already in the cart, never a product
	 * the rule leaves out — each once, in the order they came.
	 *
	 * @param int[] $candidates Product ids, best first.
	 * @param int   $beside     The product the offer stands beside.
	 * @param int[] $in_cart    Product and variation ids in the cart.
	 * @param int[] $exclude    Products the rule leaves out.
	 * @return int[]
	 */
	public static function offerable( array $candidates, int $beside, array $in_cart, array $exclude = array() ): array {
		$out = array();

		foreach ( self::ids( $candidates ) as $id ) {
			if ( $id === $beside || in_array( $id, $in_cart, true ) || in_array( $id, $exclude, true ) ) {
				continue;
			}

			$out[] = $id;
		}

		return $out;
	}

	/**
	 * What a percentage takes off an amount, to the given decimals.
	 *
	 * @param float $amount   What the line comes to.
	 * @param int   $percent  The rule's percent.
	 * @param int   $decimals Decimals of the shop's currency.
	 */
	public static function off( float $amount, int $percent, int $decimals = 2 ): float {
		$percent = min( self::MAX_PERCENT, max( 0, $percent ) );

		if ( $amount <= 0 || 0 === $percent ) {
			return 0.0;
		}

		return round( $amount * $percent / 100, $decimals );
	}

	/**
	 * Whether a source label is one this module writes: a hand-picked
	 * offer, the automatic layer, or a rule by its name.
	 *
	 * @param string $src Source label.
	 */
	public static function is_source( string $src ): bool {
		return 'manual' === $src || 'auto' === $src || 1 === preg_match( '/^r:[0-9a-f]{32}$/', $src );
	}
}
