<?php
/**
 * Which of a rule's products fits one product best.
 *
 * A rule names a category; every product in that category used to get
 * the same list in the same order. Here each candidate is scored against
 * the product it stands beside — colour, material, brand, price, the
 * words in the name, what shoppers bought together — and a small,
 * fixed shuffle per pair keeps two neighbours' lists from being twins.
 *
 * The scoring is pure (unit-tested); only rank() touches WordPress.
 *
 * @package OC_Theme
 */

declare( strict_types = 1 );

namespace OC\Theme\Offers;

defined( 'ABSPATH' ) || defined( 'OC_TESTS' ) || exit;

/**
 * Fit: per-product relevance over a rule's pool.
 */
final class Fit {

	/**
	 * Colour families: family => its words, Hebrew and English, lower case.
	 * Gendered and plural forms are found by stripping endings.
	 */
	const COLOURS = array(
		'black'  => 'שחור black',
		'white'  => 'לבן white',
		'grey'   => 'אפור grey gray',
		'beige'  => 'בז בז׳ קרם שמנת שנהב beige cream ivory',
		'brown'  => 'חום אגוז brown walnut',
		'oak'    => 'אלון טבעי oak natural',
		'blue'   => 'כחול נייבי תכלת blue navy',
		'green'  => 'ירוק זית green olive',
		'red'    => 'אדום בורדו red burgundy',
		'pink'   => 'ורוד pink',
		'yellow' => 'צהוב חרדל yellow mustard',
		'orange' => 'כתום orange',
		'purple' => 'סגול לילך purple lilac',
		'teal'   => 'טורקיז turquoise teal',
		'gold'   => 'זהב מוזהב gold',
		'silver' => 'כסף כסוף ניקל כרום silver nickel chrome',
		'bronze' => 'ברונזה נחושת bronze copper',
	);

	/**
	 * Families that go with anything.
	 */
	const NEUTRAL = array( 'black', 'white', 'grey', 'beige', 'brown', 'oak', 'gold', 'silver', 'bronze' );

	/**
	 * Materials: material => its words.
	 */
	const MATERIALS = array(
		'wood'    => 'עץ wood wooden',
		'metal'   => 'מתכת ברזל metal iron',
		'glass'   => 'זכוכית glass',
		'fabric'  => 'בד fabric',
		'velvet'  => 'קטיפה velvet',
		'leather' => 'עור leather',
		'rattan'  => 'רטן rattan',
		'bamboo'  => 'במבוק bamboo',
		'marble'  => 'שיש marble',
		'stone'   => 'אבן stone',
		'ceramic' => 'קרמיקה ceramic',
		'plastic' => 'פלסטיק plastic',
		'rope'    => 'חבל rope',
	);

	/**
	 * Words that say nothing about likeness.
	 */
	const STOP = array( 'עם', 'של', 'את', 'על', 'ללא', 'כולל', 'סט', 'דגם', 'and', 'with', 'the', 'for', 'set' );

	/**
	 * Profiles built in this request.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private static $profiles = array();

	/**
	 * A rule's candidates, the best fit for one product first. Named
	 * products stay ahead of the category's; within each group the fit
	 * decides.
	 *
	 * @param int    $beside The product the offer stands beside.
	 * @param int[]  $ids    Candidates: the named ones first, then the category's.
	 * @param int    $named  How many of $ids were named one by one.
	 * @param string $place  cart | checkout | xsell | upsell | similar.
	 * @return int[]
	 */
	public static function rank( int $beside, array $ids, int $named, string $place ): array {
		$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );

		if ( count( $ids ) < 2 ) {
			return $ids;
		}

		$key = 'oc_offm_' . md5( $beside . '|' . $place . '|' . $named . '|' . implode( ',', $ids ) );
		$hit = get_transient( $key );

		if ( is_array( $hit ) ) {
			return array_map( 'intval', $hit );
		}

		$mine = self::profile( $beside );

		if ( ! $mine ) {
			return $ids;
		}

		$bought = array_flip( Offers::bought_with( $beside ) );
		$n      = count( $ids );
		$scored = array();

		foreach ( $ids as $pos => $id ) {
			$theirs = self::profile( $id );

			if ( ! $theirs ) {
				continue;
			}

			$score = self::score( $mine, $theirs, $place );

			// The rule's own order still counts, a little: best sellers
			// first means the best seller wins a tie.
			$score += 0.6 * ( 1 - $pos / $n );

			if ( isset( $bought[ $id ] ) ) {
				$score += 1.5 * ( 1 - $bought[ $id ] / max( 1, count( $bought ) ) );
			}

			$score += self::jitter( $beside, $id );

			$scored[ $id ] = array( $pos < $named ? 1 : 0, $score );
		}

		uasort(
			$scored,
			static function ( array $a, array $b ): int {
				return $b[0] <=> $a[0] ?: $b[1] <=> $a[1];
			}
		);

		$out = array_map( 'intval', array_keys( $scored ) );

		set_transient( $key, $out, 6 * HOUR_IN_SECONDS );

		return $out;
	}

	/**
	 * How well one product fits beside another: 0 for strangers, around
	 * 6–8 for a near twin.
	 *
	 * @param array<string,mixed> $a     The product the offer stands beside.
	 * @param array<string,mixed> $b     The candidate.
	 * @param string              $place cart | checkout | xsell | upsell | similar.
	 */
	public static function score( array $a, array $b, string $place ): float {
		$score = 0.0;

		// Colour: the same family is the strongest single signal; two
		// neutrals (black table, white chair) still go together.
		$score += self::colour_fit( (array) $a['colours'], (array) $b['colours'] );

		// Material.
		$shared = array_intersect( (array) $a['materials'], (array) $b['materials'] );

		if ( $shared ) {
			$score += 1.0 + 0.3 * ( count( $shared ) - 1 );
		}

		// Any other attribute both state the same way.
		$score += min( 1.5, 0.5 * count( array_intersect( (array) $a['attrs'], (array) $b['attrs'] ) ) );

		// Brand.
		if ( array_intersect( (array) $a['brands'], (array) $b['brands'] ) ) {
			$score += 0.8;
		}

		// Tags.
		$score += 0.6 * self::jaccard( (array) $a['tags'], (array) $b['tags'] );

		// Words in the name, colour and material words aside.
		$score += min( 1.05, 0.35 * count( array_intersect( (array) $a['words'], (array) $b['words'] ) ) );

		// Categories, by depth: a shared leaf says more than a shared hub.
		// For "similar" it is the heart of the matter.
		$score += ( 'similar' === $place ? 1.5 : 0.5 ) * self::weighted_jaccard( (array) $a['cats'], (array) $b['cats'] );

		// Price, by what the place is for.
		$score += self::price_fit( (float) $a['price'], (float) $b['price'], $place );

		return $score;
	}

	/**
	 * Colour fit of two colour lists.
	 *
	 * @param string[] $a Families of one.
	 * @param string[] $b Families of the other.
	 */
	public static function colour_fit( array $a, array $b ): float {
		if ( ! $a || ! $b ) {
			return 0.0;
		}

		if ( array_intersect( $a, $b ) ) {
			return 2.0;
		}

		$an = (bool) array_intersect( $a, self::NEUTRAL );
		$bn = (bool) array_intersect( $b, self::NEUTRAL );

		if ( $an && $bn ) {
			return 1.0;
		}

		return $an || $bn ? 0.5 : 0.0;
	}

	/**
	 * Price fit: similar wants the same price, an upgrade wants a dearer
	 * one, an add-on is happiest cheaper.
	 *
	 * @param float  $mine   The product's price.
	 * @param float  $theirs The candidate's.
	 * @param string $place  cart | checkout | xsell | upsell | similar.
	 */
	public static function price_fit( float $mine, float $theirs, string $place ): float {
		if ( $mine <= 0 || $theirs <= 0 ) {
			return 0.0;
		}

		$ratio = $theirs / $mine;

		if ( 'similar' === $place ) {
			return max( 0.0, 1 - abs( log( $ratio ) ) / log( 3 ) );
		}

		if ( 'upsell' === $place ) {
			// Best around 1.4× the price; nothing for cheaper.
			return $ratio <= 1 ? 0.0 : max( 0.0, 1 - abs( log( $ratio / 1.4 ) ) / log( 2 ) );
		}

		// An add-on: anything up to the product's price fits; beyond it the
		// fit fades, gone at twice the price.
		return $ratio <= 1 ? 0.6 : 0.6 * max( 0.0, 1 - log( $ratio ) / log( 2 ) );
	}

	/**
	 * Colour families named in a run of words.
	 *
	 * @param string[] $tokens Lower-case words.
	 * @return string[]
	 */
	public static function colours( array $tokens ): array {
		return self::families( $tokens, self::lexicon( self::COLOURS ) );
	}

	/**
	 * Materials named in a run of words.
	 *
	 * @param string[] $tokens Lower-case words.
	 * @return string[]
	 */
	public static function materials( array $tokens ): array {
		return self::families( $tokens, self::lexicon( self::MATERIALS ) );
	}

	/**
	 * Words of a name or an attribute value: lower case, punctuation gone,
	 * two letters or more.
	 *
	 * @param string $text The text.
	 * @return string[]
	 */
	public static function tokens( string $text ): array {
		$text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
		$text = mb_strtolower( (string) preg_replace( '/[\x{05F3}\x{05F4}\'"’`.,;:()\[\]\/+\-–—|]+/u', ' ', $text ) );
		$out  = array();

		foreach ( (array) preg_split( '/\s+/u', $text ) as $word ) {
			if ( is_string( $word ) && mb_strlen( $word ) >= 2 ) {
				$out[] = $word;
			}
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * word => family, built once from a family => words table.
	 *
	 * @param array<string,string> $table family => words.
	 * @return array<string,string>
	 */
	private static function lexicon( array $table ): array {
		static $built = array();

		$key = md5( implode( '|', array_keys( $table ) ) );

		if ( ! isset( $built[ $key ] ) ) {
			$built[ $key ] = array();

			foreach ( $table as $family => $words ) {
				foreach ( explode( ' ', $words ) as $word ) {
					$built[ $key ][ $word ] = $family;
				}
			}
		}

		return $built[ $key ];
	}

	/**
	 * Which lexicon families a run of words names, allowing for Hebrew
	 * prefixes (בשחור, השחור) and gender/plural endings (שחורה, שחורים),
	 * and an English plural.
	 *
	 * @param string[]             $tokens  Lower-case words.
	 * @param array<string,string> $lexicon word => family.
	 * @return string[]
	 */
	private static function families( array $tokens, array $lexicon ): array {
		$out = array();

		foreach ( $tokens as $word ) {
			$family = self::family( $word, $lexicon );

			if ( '' !== $family ) {
				$out[ $family ] = true;
			}
		}

		return array_keys( $out );
	}

	/**
	 * The family of one word, or ''.
	 *
	 * @param string               $word    Lower-case word.
	 * @param array<string,string> $lexicon word => family.
	 */
	private static function family( string $word, array $lexicon ): string {
		$tries = array( $word );

		// One Hebrew prefix letter.
		if ( mb_strlen( $word ) >= 4 && preg_match( '/^[בהולכמש]/u', $word ) ) {
			$tries[] = mb_substr( $word, 1 );
		}

		foreach ( $tries as $try ) {
			if ( isset( $lexicon[ $try ] ) ) {
				return $lexicon[ $try ];
			}

			foreach ( array( 'ה', 'ת', 'ים', 'ות', 'י', 'יים', 's', 'es' ) as $suffix ) {
				$len = mb_strlen( $suffix );

				if ( mb_strlen( $try ) > $len + 1 && mb_substr( $try, -$len ) === $suffix ) {
					$stem = mb_substr( $try, 0, -$len );

					if ( isset( $lexicon[ $stem ] ) ) {
						return $lexicon[ $stem ];
					}
				}
			}
		}

		return '';
	}

	/**
	 * A product's profile: what the scoring compares.
	 *
	 * @param int $product_id The product.
	 * @return array<string,mixed>|null
	 */
	private static function profile( int $product_id ): ?array {
		if ( array_key_exists( $product_id, self::$profiles ) ) {
			return self::$profiles[ $product_id ];
		}

		$product = wc_get_product( $product_id );

		if ( ! $product instanceof \WC_Product ) {
			self::$profiles[ $product_id ] = null;

			return null;
		}

		$tokens = self::tokens( $product->get_name() );
		$attrs  = array();
		$avals  = array();

		foreach ( $product->get_attributes() as $attribute ) {
			if ( ! $attribute instanceof \WC_Product_Attribute ) {
				continue;
			}

			$name = mb_strtolower( (string) $attribute->get_name() );

			if ( $attribute->is_taxonomy() ) {
				foreach ( (array) $attribute->get_terms() as $term ) {
					if ( $term instanceof \WP_Term ) {
						$attrs[] = $name . '=' . mb_strtolower( $term->slug );
						$avals[] = $term->name;
					}
				}
			} else {
				foreach ( (array) $attribute->get_options() as $option ) {
					$attrs[] = $name . '=' . mb_strtolower( trim( (string) $option ) );
					$avals[] = (string) $option;
				}
			}
		}

		$all = array_values( array_unique( array_merge( $tokens, self::tokens( implode( ' ', $avals ) ) ) ) );

		$colours   = self::colours( $all );
		$materials = self::materials( $all );

		// The name's plain words: no colours, materials or stop words, no
		// bare numbers — those are sizes, which the price already echoes.
		$words = array();

		foreach ( $tokens as $word ) {
			if ( '' === self::family( $word, self::lexicon( self::COLOURS ) ) && '' === self::family( $word, self::lexicon( self::MATERIALS ) ) && ! in_array( $word, self::STOP, true ) && ! is_numeric( $word ) ) {
				$words[] = $word;
			}
		}

		$cats = array();

		foreach ( (array) get_the_terms( $product_id, 'product_cat' ) as $term ) {
			if ( $term instanceof \WP_Term ) {
				$cats[ (int) $term->term_id ] = 1 + count( (array) get_ancestors( $term->term_id, 'product_cat', 'taxonomy' ) );
			}
		}

		$tax = \OC\Theme\Search::brand_taxonomy();

		self::$profiles[ $product_id ] = array(
			'id'        => $product_id,
			'price'     => (float) $product->get_price(),
			'cats'      => $cats,
			'brands'    => '' === $tax ? array() : array_map( 'intval', wc_get_product_term_ids( $product_id, $tax ) ),
			'tags'      => array_map( 'intval', wc_get_product_term_ids( $product_id, 'product_tag' ) ),
			'attrs'     => $attrs,
			'colours'   => $colours,
			'materials' => $materials,
			'words'     => $words,
		);

		return self::$profiles[ $product_id ];
	}

	/**
	 * A small fixed number per pair, so two neighbours with the same
	 * scores still show different first picks.
	 *
	 * @param int $a One product.
	 * @param int $b The other.
	 */
	public static function jitter( int $a, int $b ): float {
		return ( crc32( $a . ':' . $b ) % 1000 ) / 1000 * 0.25;
	}

	/**
	 * Jaccard similarity of two lists.
	 *
	 * @param array<int|string> $a One list.
	 * @param array<int|string> $b The other.
	 */
	private static function jaccard( array $a, array $b ): float {
		if ( ! $a || ! $b ) {
			return 0.0;
		}

		$union = count( array_unique( array_merge( $a, $b ) ) );

		return $union > 0 ? count( array_intersect( $a, $b ) ) / $union : 0.0;
	}

	/**
	 * Jaccard over weighted sets: shared weight over total weight.
	 *
	 * @param array<int,int> $a term => weight.
	 * @param array<int,int> $b term => weight.
	 */
	private static function weighted_jaccard( array $a, array $b ): float {
		if ( ! $a || ! $b ) {
			return 0.0;
		}

		$shared = 0;
		$total  = 0;

		foreach ( $a + $b as $term => $w ) {
			$total += max( $a[ $term ] ?? 0, $b[ $term ] ?? 0 );

			if ( isset( $a[ $term ], $b[ $term ] ) ) {
				$shared += min( $a[ $term ], $b[ $term ] );
			}
		}

		return $total > 0 ? $shared / $total : 0.0;
	}
}
