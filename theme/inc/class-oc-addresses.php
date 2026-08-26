<?php
/**
 * Address book: a per-user list of shipping addresses kept in our own user
 * meta (`_oc_addresses`), never in WooCommerce core — so a Woo/WP update
 * can't drop it. Drives the packed logged-in checkout (address selector +
 * "add address") and the my-account addresses screen.
 *
 * An address holds only the place — city, street, apartment, floor, entry
 * and a label. Name and phone belong to the orderer, not the address.
 *
 * @package OC_Theme
 */

declare( strict_types = 1 );

namespace OC\Theme;

defined( 'ABSPATH' ) || exit;

/**
 * The user's saved addresses.
 */
final class Addresses {

	const META = '_oc_addresses';

	/**
	 * Is the multi-address / packed-checkout experience turned on?
	 */
	public static function enabled(): bool {
		$s = Checkout::settings();

		return ! empty( $s['multi_address'] );
	}

	/**
	 * The built-in chip labels (custom labels pass straight through).
	 *
	 * @return array<string,string>
	 */
	public static function labels(): array {
		return array(
			'home'    => _x( 'Home', 'address label', 'oc-theme' ),
			'work'    => _x( 'Work', 'address label', 'oc-theme' ),
			'parents' => _x( 'Parents', 'address label', 'oc-theme' ),
		);
	}

	/**
	 * Human label for a stored label key.
	 *
	 * @param string $label Stored label.
	 * @return string
	 */
	public static function label_text( string $label ): string {
		$known = self::labels();

		return $known[ $label ] ?? $label;
	}

	/**
	 * Only the truly stored addresses, default first.
	 *
	 * @param int $uid User id.
	 * @return array<int,array<string,mixed>>
	 */
	public static function all( int $uid ): array {
		$list = get_user_meta( $uid, self::META, true );
		$list = is_array( $list ) ? array_values( $list ) : array();

		usort(
			$list,
			static function ( $a, $b ) {
				return (int) ! empty( $b['is_default'] ) <=> (int) ! empty( $a['is_default'] );
			}
		);

		return $list;
	}

	/**
	 * The book to show — the stored list, or a single seed drawn from the
	 * user's WooCommerce billing address when the book is still empty. The
	 * seed lets a returning customer see a packed card on day one; the moment
	 * they save anything it becomes a real, editable entry.
	 *
	 * @param int $uid User id.
	 * @return array<int,array<string,mixed>>
	 */
	public static function book( int $uid ): array {
		$list = self::all( $uid );

		if ( $list ) {
			return $list;
		}

		$seed = self::from_wc_billing( $uid );

		return $seed ? array( $seed ) : array();
	}

	/**
	 * A one-off address built from the user's Woo billing meta.
	 *
	 * @param int $uid User id.
	 * @return array<string,mixed>|null
	 */
	public static function from_wc_billing( int $uid ): ?array {
		$street = (string) get_user_meta( $uid, 'billing_address_1', true );

		if ( '' === trim( $street ) ) {
			return null;
		}

		return array(
			'id'         => 'wc',
			'label'      => 'home',
			'city'       => (string) get_user_meta( $uid, 'billing_city', true ),
			'address_1'  => $street,
			'address_2'  => (string) get_user_meta( $uid, 'billing_address_2', true ),
			'floor'      => '',
			'entry'      => '',
			'is_default' => true,
		);
	}

	/**
	 * One address by id (searches the shown book, seed included).
	 *
	 * @param int    $uid User id.
	 * @param string $id  Address id.
	 * @return array<string,mixed>|null
	 */
	public static function get( int $uid, string $id ): ?array {
		foreach ( self::book( $uid ) as $a ) {
			if ( (string) ( $a['id'] ?? '' ) === $id ) {
				return $a;
			}
		}

		return null;
	}

	/**
	 * The default address (or the first one) from the shown book.
	 *
	 * @param int $uid User id.
	 * @return array<string,mixed>|null
	 */
	public static function default_addr( int $uid ): ?array {
		$book = self::book( $uid );

		foreach ( $book as $a ) {
			if ( ! empty( $a['is_default'] ) ) {
				return $a;
			}
		}

		return $book[0] ?? null;
	}

	/**
	 * Insert or update an address; returns its id. A brand-new entry (or one
	 * carrying the synthetic 'wc' seed id) is given a fresh uuid so the seed
	 * turns into a stored address on first save.
	 *
	 * @param int                  $uid User id.
	 * @param array<string,mixed>  $in  Raw address input.
	 * @return string
	 */
	public static function save( int $uid, array $in ): string {
		$list = self::all( $uid );
		$id   = isset( $in['id'] ) && '' !== (string) $in['id'] && 'wc' !== $in['id']
			? (string) $in['id']
			: wp_generate_uuid4();

		$addr = array(
			'id'         => $id,
			'label'      => sanitize_text_field( (string) ( $in['label'] ?? '' ) ),
			'city'       => sanitize_text_field( (string) ( $in['city'] ?? '' ) ),
			'address_1'  => sanitize_text_field( (string) ( $in['address_1'] ?? '' ) ),
			'address_2'  => sanitize_text_field( (string) ( $in['address_2'] ?? '' ) ),
			'floor'      => sanitize_text_field( (string) ( $in['floor'] ?? '' ) ),
			'entry'      => sanitize_text_field( (string) ( $in['entry'] ?? '' ) ),
			'is_default' => ! empty( $in['is_default'] ),
		);

		$found = false;
		foreach ( $list as &$a ) {
			if ( (string) ( $a['id'] ?? '' ) === $id ) {
				$a     = $addr;
				$found = true;
			}
		}
		unset( $a );

		if ( ! $found ) {
			$list[] = $addr;
		}

		// Exactly one default at all times.
		if ( $addr['is_default'] ) {
			foreach ( $list as &$a ) {
				$a['is_default'] = ( (string) $a['id'] === $id );
			}
			unset( $a );
		} elseif ( ! self::has_default( $list ) && $list ) {
			$list[0]['is_default'] = true;
		}

		update_user_meta( $uid, self::META, array_values( $list ) );

		return $id;
	}

	/**
	 * Remove an address; if the default went, the first survivor takes over.
	 *
	 * @param int    $uid User id.
	 * @param string $id  Address id.
	 */
	public static function delete( int $uid, string $id ): void {
		$list = array_values(
			array_filter(
				self::all( $uid ),
				static function ( $a ) use ( $id ) {
					return (string) ( $a['id'] ?? '' ) !== $id;
				}
			)
		);

		if ( $list && ! self::has_default( $list ) ) {
			$list[0]['is_default'] = true;
		}

		update_user_meta( $uid, self::META, $list );
	}

	/**
	 * Make one address the default.
	 *
	 * @param int    $uid User id.
	 * @param string $id  Address id.
	 */
	public static function set_default( int $uid, string $id ): void {
		$list = self::all( $uid );

		// Nothing stored yet but a seed is showing — persist it first.
		if ( ! $list && 'wc' === $id ) {
			$seed = self::from_wc_billing( $uid );
			if ( $seed ) {
				self::save( $uid, array( $seed ) + array( 'is_default' => true ) );
			}
			return;
		}

		foreach ( $list as &$a ) {
			$a['is_default'] = ( (string) ( $a['id'] ?? '' ) === $id );
		}
		unset( $a );

		update_user_meta( $uid, self::META, array_values( $list ) );
	}

	/**
	 * One-line rendering of an address for cards and packed rows.
	 *
	 * @param array<string,mixed> $a Address.
	 * @return string
	 */
	public static function format( array $a ): string {
		$line = trim( (string) ( $a['address_1'] ?? '' ) );

		if ( ! empty( $a['city'] ) ) {
			$line .= ( '' !== $line ? ', ' : '' ) . $a['city'];
		}

		$extra = array();
		if ( ! empty( $a['address_2'] ) ) {
			/* translators: %s: apartment number. */
			$extra[] = sprintf( __( 'Apt %s', 'oc-theme' ), $a['address_2'] );
		}
		if ( ! empty( $a['floor'] ) ) {
			/* translators: %s: floor. */
			$extra[] = sprintf( __( 'Floor %s', 'oc-theme' ), $a['floor'] );
		}

		if ( $extra ) {
			$line .= ' · ' . implode( ' · ', $extra );
		}

		return $line;
	}

	/**
	 * Does the list already carry a default?
	 *
	 * @param array<int,array<string,mixed>> $list Addresses.
	 * @return bool
	 */
	private static function has_default( array $list ): bool {
		foreach ( $list as $a ) {
			if ( ! empty( $a['is_default'] ) ) {
				return true;
			}
		}

		return false;
	}
}
